<?php
/**
 * Plugin Name:          Featured Creators
 * Plugin URI:           https://noxpress.tech
 * Description:          Μία σελίδα για κάθε δημιουργό του καταλόγου (συγγραφέα, εικονογράφο, μουσικό, σκηνοθέτη…) που συγκεντρώνει αυτόματα τα προϊόντα του ανά ρόλο από τα χαρακτηριστικά του WooCommerce. Σύνδεσμοι από τα «Επιπλέον πληροφορίες», πλαίσιο δημιουργού στο προϊόν, ευρετήριο με φίλτρο ρόλου και schema Person / ProfilePage.
 * Version:              1.2.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.1
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/featured-creators
 * Text Domain:          featured-creators
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - featured-creators.php → bootstrap only (constants, requires, init wiring)
 *  - CRT_Lang      → EN dictionary + gettext filter (admin only: rs_lang;
 *                    visitor texts are store settings, Bible §14.17)
 *  - CRT_Settings  → the crt_settings option, strict validation, roles,
 *                    visitor texts (el/en, filter crt_text)
 *  - CRT_CPT       → the nox_creator post type and its meta (photo = the
 *                    featured image, biography = the content); the meta the
 *                    store writes is in REST (edit context) since 1.1.0
 *  - CRT_Match     → creator ↔ attribute terms: same slug in every role
 *                    taxonomy, extra terms, exclusions, conflicts; writes
 *                    the term meta crt_creator and the creator stats on
 *                    save (Bible §14.10); purges the products' caches
 *  - CRT_Front     → creator page (classic template / block template),
 *                    links in "Additional information", creator box and
 *                    creator tab on the product, index [nox_creators],
 *                    blocks, assets
 *  - CRT_Schema    → Person + ProfilePage, Book / MusicAlbum / Movie /
 *                    Audiobook roles on products (Rank Math graph or our
 *                    own JSON-LD)
 *  - CRT_Metabox   → creator edit screen: details, links, press lists,
 *                    term matching (AJAX term search)
 *  - CRT_Import    → draft creators from the role terms (batches)
 *  - CRT_Admin_UI  → menu, list columns, settings page with tabs, coverage
 *                    check, backup, footer
 *
 * Front-end rules (Bible §14): test mode after activation (only shop
 * managers see creator pages, links, boxes and the index); CRT_DISABLE in
 * wp-config.php stops every front-end hook; no theme or plugin file is
 * changed; no cookie, no external request, no database write on a visitor
 * request. Only published creators without a password are public.
 *
 * Cooperation (Bible §6): fires crt_creator_changed( $creator_id,
 * $product_ids ) when what visitors see about a creator changes. Public
 * API: crt_get_creators( $product_id ). Reads Product Formats only
 * through pfm_get_work().
 *
 * Deviations from the Bible (with reason):
 *  - §12 (nothing left after Delete): the creators themselves (posts with
 *    biographies and lists) are content the store wrote, like the product
 *    texts of Smart Formatter. They stay after uninstall unless the
 *    setting "Delete the creators on uninstall" is on. Settings, term meta
 *    and transients are always deleted.
 */

defined( 'ABSPATH' ) || exit;

define( 'CRT_VERSION', '1.2.0' );
define( 'CRT_FILE', __FILE__ );
define( 'CRT_PATH', plugin_dir_path( __FILE__ ) );
define( 'CRT_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once CRT_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: no order data is read or written.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CRT_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', CRT_FILE, true );
		}
	}
);

require_once CRT_PATH . 'includes/class-lang.php';

final class Featured_Creators {

	public static function init(): void {

		CRT_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'featured-creators', false, dirname( plugin_basename( CRT_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once CRT_PATH . 'includes/class-settings.php';
		require_once CRT_PATH . 'includes/class-cpt.php';
		require_once CRT_PATH . 'includes/class-match.php';
		require_once CRT_PATH . 'includes/class-front.php';
		require_once CRT_PATH . 'includes/class-schema.php';

		CRT_CPT::init();
		CRT_Match::init();

		// Emergency switch (Bible §14): no front-end hook at all. The blocks
		// stay registered (empty output) so the editor keeps them valid.
		if ( self::killed() ) {
			add_action( 'init', array( 'CRT_Front', 'register_blocks' ) );
		} else {
			CRT_Front::init();
			CRT_Schema::init();
		}

		if ( is_admin() ) {
			require_once CRT_PATH . 'includes/class-metabox.php';
			require_once CRT_PATH . 'includes/class-import.php';
			require_once CRT_PATH . 'includes/class-admin-ui.php';
			CRT_Metabox::init();
			CRT_Import::init();
			CRT_Admin_UI::init();
		}
	}

	/** True when CRT_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'CRT_DISABLE' ) && CRT_DISABLE;
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Featured Creators χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'featured-creators' )
			. '</p></div>';
	}

	/** Activation: the post type's URLs need fresh rewrite rules. */
	public static function activate(): void {
		update_option( 'crt_flush', 1, false );
	}
}

register_activation_hook( __FILE__, array( 'Featured_Creators', 'activate' ) );
add_action( 'plugins_loaded', array( 'Featured_Creators', 'init' ), 20 );

if ( ! function_exists( 'crt_get_creators' ) ) {
	/**
	 * Public API (Bible §6): the published creators of a product.
	 *
	 * @param int $product_id Product id (a variation's parent is used).
	 * @return array[] Each { id, name, url, roles: string[] taxonomy names },
	 *                 in the order of the roles in the settings.
	 */
	function crt_get_creators( $product_id ) {
		return class_exists( 'CRT_Match' ) ? CRT_Match::product_creators( (int) $product_id ) : array();
	}
}

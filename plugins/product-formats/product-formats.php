<?php
/**
 * Plugin Name:          Product Formats
 * Plugin URI:           https://noxpress.tech
 * Description:          Ομαδοποιεί τα προϊόντα που είναι το ίδιο έργο σε άλλη μορφή (έντυπο, ebook, ηχητικό βιβλίο, ταινία, μουσική ή δικές σου μορφές) και δείχνει στη σελίδα κάθε προϊόντος το μπλοκ «Διαθέσιμες μορφές» με ετικέτα, τιμή και σύνδεσμο. Προτείνει ομάδες από τίτλους, slugs και upsells, και καθαρίζει με ασφάλεια τα upsells που έκαναν αυτή τη δουλειά.
 * Version:              1.0.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.1
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/product-formats
 * Text Domain:          product-formats
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - product-formats.php → bootstrap only (constants, requires, init wiring)
 *  - PFM_Lang        → EN dictionary + gettext filter (admin: rs_lang,
 *                      front end: the site language)
 *  - PFM_Settings    → options (settings, format registry, rejected
 *                      suggestions), strict validation
 *  - PFM_Works       → hidden taxonomy pfm_work (one term = one work),
 *                      product format meta, the precomputed member map,
 *                      save / trash / delete hooks, cache purge, public API
 *  - PFM_Suggest     → work suggestions from slugs, titles and upsells
 *  - PFM_Front       → "Available formats" block, shortcode, list line,
 *                      upsell hiding, front-end CSS
 *  - PFM_Upsells     → upsell cleanup: preview, batches, snapshots, restore
 *  - PFM_Product_Tab → "Formats" tab in the product data panel
 *  - PFM_Admin_UI    → menu, pages, PRG routes, AJAX, backup, footer
 *
 * Front-end rules (Bible §14): nothing changes until enabled; test mode
 * shows the block to shop managers only; PFM_DISABLE in wp-config.php
 * stops every front-end hook; no database writes on visitor requests (the
 * member map is built in save hooks and in the admin); theme files are
 * never changed.
 *
 * Cooperation (Bible §6): the upsell cleanup changes product data, so it
 * fires `noxpress_products_changed( $ids )`. Public API for other plugins:
 * pfm_get_work( $product_id ) and the filter `pfm_formats`.
 *
 * Deviations from the Bible: none.
 */

defined( 'ABSPATH' ) || exit;

define( 'PFM_VERSION', '1.0.0' );
define( 'PFM_FILE', __FILE__ );
define( 'PFM_PATH', plugin_dir_path( __FILE__ ) );
define( 'PFM_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once PFM_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce HPOS (custom order tables) compatibility: Product Formats never touches orders.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PFM_FILE, true );
		}
	}
);

require_once PFM_PATH . 'includes/class-lang.php';

final class Product_Formats {

	public static function init(): void {

		PFM_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'product-formats', false, dirname( plugin_basename( PFM_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once PFM_PATH . 'includes/class-settings.php';
		require_once PFM_PATH . 'includes/class-works.php';
		require_once PFM_PATH . 'includes/class-front.php';

		// The taxonomy and the save hooks keep the member map right even with
		// PFM_DISABLE: they never print anything.
		PFM_Works::init();

		// Emergency switch (Bible §14): no front-end hook at all.
		if ( ! self::killed() ) {
			PFM_Front::init();
		}

		if ( is_admin() ) {
			require_once PFM_PATH . 'includes/class-suggest.php';
			require_once PFM_PATH . 'includes/class-upsells.php';
			require_once PFM_PATH . 'includes/class-product-tab.php';
			require_once PFM_PATH . 'includes/class-admin-ui.php';
			PFM_Product_Tab::init();
			PFM_Admin_UI::init();
		}
	}

	/** True when PFM_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'PFM_DISABLE' ) && PFM_DISABLE;
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Product Formats χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'product-formats' )
			. '</p></div>';
	}
}

add_action( 'plugins_loaded', array( 'Product_Formats', 'init' ), 20 );

if ( ! function_exists( 'pfm_get_work' ) ) {
	/**
	 * Public API (Bible §6): the work a product belongs to, or null.
	 *
	 * @param int $product_id Product ID.
	 * @return array|null { id, name, members: [ { id, format, variant, status, visible } ] }
	 */
	function pfm_get_work( $product_id ) {
		return class_exists( 'PFM_Works' ) ? PFM_Works::public_work( (int) $product_id ) : null;
	}
}

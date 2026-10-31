<?php
/**
 * Plugin Name:          Variation Picker
 * Plugin URI:           https://noxpress.tech
 * Description:          Swatches για τις παραλλαγές του WooCommerce: κουμπιά, χρώματα (και δίχρωμα), εικόνες, εικόνα της παραλλαγής, λίστα ή dropdown ανά χαρακτηριστικό. Δείχνει ποιοι συνδυασμοί λείπουν, «Χρώμα: Μαύρο», τιμή και απόθεμα στο tooltip, οδηγό μεγεθών, swatches στις κάρτες του καταστήματος και εισαγωγή από άλλα swatches plugins.
 * Version:              1.1.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.1
 * WC tested up to:      11.2
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/variation-picker
 * Text Domain:          variation-picker
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - variation-picker.php → bootstrap only (constants, requires, init wiring)
 *  - VPK_Lang        → EN dictionary + gettext filter (admin only: rs_lang;
 *                      visitor texts are store settings, Bible §14.17)
 *  - VPK_Settings    → the vpk_settings / vpk_guides options, strict
 *                      validation, visitor texts (el/en, filter vpk_text)
 *  - VPK_Swatch      → type of an attribute, look of a value (term meta,
 *                      product overrides), public API body
 *  - VPK_Map         → availability map per variable product (_vpk_map),
 *                      built on save / stock change, Action Scheduler jobs
 *  - VPK_Front       → product page swatches, assets, cart colour dot
 *  - VPK_Loop        → swatches on shop cards
 *  - VPK_Guide       → size guide button and dialog
 *  - VPK_Compat      → other swatches plugins: detection and import
 *  - VPK_Admin_Terms → fields on WooCommerce's attribute, term and product
 *                      screens
 *  - VPK_Admin_UI    → menu, settings page (general, attributes, shop,
 *                      guides, texts, tools), backup, footer
 *
 * Front-end rules (Bible §14): no change after activation (mode "off" until
 * set to "test" or "live"); test mode shows the swatches to shop managers
 * only; VPK_DISABLE in wp-config.php stops every front-end hook; WooCommerce's
 * <select> stays in the page and the swatches only drive it, so without
 * JavaScript (or after an error) the visitor gets the normal dropdown; no
 * theme or plugin file is changed; no cookie, no external request; every
 * callback is wrapped and falls back to WooCommerce's output.
 *
 * Cooperation (Bible §6): fires nothing (it changes no product data).
 * Filter for others: vpk_text. Public API: vpk_get_term_swatch().
 *
 * Deviations from the Bible (with reason):
 *  - §14.4 (never in AJAX): product swatches also render in admin-ajax /
 *    wc-ajax requests, for quick-view popups that load the product form
 *    over AJAX; the cart colour dot also runs in WooCommerce's cart
 *    fragments (mini-cart), the §14.4 cart exception.
 *  - §14.5 (no write on a visitor request): when an order changes the stock
 *    of a variation, the product's availability map is not rebuilt in that
 *    request; one Action Scheduler job is queued (group variation-picker)
 *    by WooCommerce's own stock hook, inside the order WooCommerce is
 *    already writing.
 *  - §12 (clean uninstall): colours, images and short labels typed on
 *    attribute values (term meta vpk_*) and per-product overrides (_vpk)
 *    stay after uninstall unless "Delete … when the plugin is deleted" is
 *    ticked in Tools (Bible §12, content the user writes). Settings, guides,
 *    maps and jobs always go.
 */

defined( 'ABSPATH' ) || exit;

define( 'VPK_VERSION', '1.1.0' );
define( 'VPK_FILE', __FILE__ );
define( 'VPK_PATH', plugin_dir_path( __FILE__ ) );
define( 'VPK_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once VPK_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: no order data (HPOS-safe); the Cart / Checkout
// blocks are left alone (the cart dot is for the classic cart and mini-cart).
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', VPK_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', VPK_FILE, true );
		}
	}
);

// First activation: build the availability maps in the background once
// WooCommerce's Action Scheduler is loaded (next admin request).
register_activation_hook(
	__FILE__,
	static function () {
		update_option( 'vpk_build_pending', 1, false );
	}
);

require_once VPK_PATH . 'includes/class-lang.php';

final class Variation_Picker {

	public static function init(): void {

		VPK_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'variation-picker', false, dirname( plugin_basename( VPK_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once VPK_PATH . 'includes/class-settings.php';
		require_once VPK_PATH . 'includes/class-compat.php';
		require_once VPK_PATH . 'includes/class-swatch.php';
		require_once VPK_PATH . 'includes/class-map.php';
		require_once VPK_PATH . 'includes/class-guide.php';
		require_once VPK_PATH . 'includes/class-front.php';
		require_once VPK_PATH . 'includes/class-loop.php';

		// Maps are store data and keep up to date even with the swatches off.
		VPK_Map::init();

		// Emergency switch (Bible §14): no front-end hook at all.
		if ( ! self::killed() ) {
			VPK_Front::init();
			VPK_Loop::init();
		}

		if ( is_admin() ) {
			require_once VPK_PATH . 'includes/class-admin-terms.php';
			require_once VPK_PATH . 'includes/class-admin-ui.php';
			VPK_Admin_Terms::init();
			VPK_Admin_UI::init();
			add_action( 'admin_init', array( __CLASS__, 'first_build' ) );
		}
	}

	/** True when VPK_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'VPK_DISABLE' ) && VPK_DISABLE;
	}

	/** After activation: queue the background map build once. */
	public static function first_build(): void {
		if ( ! get_option( 'vpk_build_pending' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( VPK_Map::enqueue_all() ) {
			delete_option( 'vpk_build_pending' );
		}
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Variation Picker χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'variation-picker' )
			. '</p></div>';
	}
}

/**
 * The look of an attribute value (public API, Bible §6), e.g. for colour
 * dots in filters.
 *
 * @param int|WP_Term $term Attribute term or term id (taxonomy pa_…).
 * @return array|null { type, color, color2, image (URL), label }, or null when nothing is set.
 */
function vpk_get_term_swatch( $term ): ?array {
	if ( ! class_exists( 'VPK_Swatch' ) ) {
		return null;
	}
	return VPK_Swatch::api_term( $term );
}

add_action( 'plugins_loaded', array( 'Variation_Picker', 'init' ), 20 );

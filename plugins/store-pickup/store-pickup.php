<?php
/**
 * Plugin Name:          Store Pickup
 * Plugin URI:           https://noxpress.tech
 * Description:          Παραλαβή από το κατάστημα ως μέθοδος αποστολής του WooCommerce, χωρίς μεταφορικά (ή με δικό σου κόστος), με επιλογή καταστήματος στο ταμείο. Για κάθε κατάστημα γράφεις όνομα, διεύθυνση, ώρες και διευκρινίσεις· το κατάστημα που διάλεξε ο πελάτης φαίνεται στην παραγγελία, στα emails και στον λογαριασμό του.
 * Version:              1.1.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.1
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/store-pickup
 * Text Domain:          store-pickup
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - store-pickup.php → bootstrap only (constants, requires, init wiring)
 *  - PKP_Lang      → EN dictionary + gettext filter (admin only: rs_lang;
 *                    visitor texts are store settings, Bible §14.17)
 *  - PKP_Settings  → the pkp_settings option, strict validation, the store
 *                    list, visitor texts (el/en, filter pkp_text)
 *  - PKP_Method    → the WooCommerce shipping method nox_store_pickup
 *                    (shipping zones, per-zone name / cost / stores)
 *  - PKP_Checkout  → store choice under the method in the classic
 *                    checkout, validation, the store on the order's
 *                    shipping line, order totals rows (thank-you page,
 *                    My account, emails), shipping address hiding,
 *                    no-pickup rule by shipping class, admin email line
 *  - PKP_Shortcode → [pkp_stores], the active stores as a list
 *  - PKP_Admin_UI  → menu, settings page (stores, texts, tools), backup,
 *                    footer
 *
 * Front-end rules (Bible §14): off after activation (the method stays
 * hidden until the mode is "test" or "live", even in a zone); test mode
 * shows it only to shop managers; PKP_DISABLE in wp-config.php
 * unregisters the method and stops every front-end hook; no theme or
 * plugin file is changed; no cookie, no external request; the only write
 * on a visitor request is the order WooCommerce itself creates.
 *
 * Cooperation (Bible §6): fires nothing (it changes no product data).
 * Filters for others: pkp_text, pkp_pickup_allowed. Public API:
 * pkp_get_order_store(). Shortcode: [pkp_stores].
 *
 * Deviations from the Bible (with reason):
 *  - §14.4 (never in AJAX or REST): the store choice is rendered in
 *    WooCommerce's checkout AJAX (update_order_review, the §14.4
 *    exception), and WooCommerce asks the method for its rates in Store
 *    API requests too (Cart / Checkout blocks). There the method is
 *    offered only when one store is possible (the blocks have no place
 *    for the choice), and that store goes on the order.
 *  - §12 (clean uninstall): the chosen store stays on the orders' shipping
 *    lines after uninstall — it is part of the store's order record, like
 *    Easy Withdrawal's requests (Bible §12, "store records").
 */

defined( 'ABSPATH' ) || exit;

define( 'PKP_VERSION', '1.1.0' );
define( 'PKP_FILE', __FILE__ );
define( 'PKP_PATH', plugin_dir_path( __FILE__ ) );
define( 'PKP_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once PKP_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: orders only through CRUD (HPOS); in the
// Checkout block the method is offered only with a single possible store.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PKP_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PKP_FILE, true );
		}
	}
);

require_once PKP_PATH . 'includes/class-lang.php';

final class Store_Pickup {

	public static function init(): void {

		PKP_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'store-pickup', false, dirname( plugin_basename( PKP_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once PKP_PATH . 'includes/class-settings.php';
		require_once PKP_PATH . 'includes/class-method.php';
		require_once PKP_PATH . 'includes/class-checkout.php';
		require_once PKP_PATH . 'includes/class-shortcode.php';

		// Registered even with PKP_DISABLE (it then prints nothing), so the
		// tag never shows as raw text on a page.
		PKP_Shortcode::init();

		// Emergency switch (Bible §14): the method is not even registered,
		// so WooCommerce skips its zone entries and checkout falls back to
		// the other methods.
		if ( ! self::killed() ) {
			add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'register_method' ) );
			PKP_Checkout::init();
		}

		if ( is_admin() ) {
			require_once PKP_PATH . 'includes/class-admin-ui.php';
			PKP_Admin_UI::init();
		}
	}

	/** True when PKP_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'PKP_DISABLE' ) && PKP_DISABLE;
	}

	/** woocommerce_shipping_methods: our method class. */
	public static function register_method( $methods ) {
		$methods = is_array( $methods ) ? $methods : array();
		$methods[ PKP_Method::ID ] = 'PKP_Method';
		return $methods;
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Store Pickup χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'store-pickup' )
			. '</p></div>';
	}
}

/**
 * The pickup store of an order (public API, Bible §6).
 *
 * @param int|WC_Order $order Order or order ID.
 * @return array|null { id, name, address, details, map } as saved at checkout, or null.
 */
function pkp_get_order_store( $order ): ?array {
	if ( ! class_exists( 'PKP_Checkout' ) ) {
		return null;
	}
	return PKP_Checkout::order_store( $order );
}

add_action( 'plugins_loaded', array( 'Store_Pickup', 'init' ), 20 );

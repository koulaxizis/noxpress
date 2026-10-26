<?php
/**
 * Plugin Name:          Pre-Orders
 * Plugin URI:           https://noxpress.tech
 * Description:          Προπαραγγελίες για προϊόντα του WooCommerce που δεν έχουν κυκλοφορήσει ακόμα: ημερομηνία κυκλοφορίας (ή «Σύντομα διαθέσιμο»), σήμα και κουμπί «Προπαραγγελία», η ημερομηνία στο καλάθι, στην παραγγελία και στα emails. Την ημέρα της κυκλοφορίας το προϊόν γίνεται αυτόματα κανονικό και οι πελάτες μπορούν να ενημερωθούν.
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
 * Update URI:           https://noxpress.tech/updates/pre-orders
 * Text Domain:          pre-orders
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - pre-orders.php     → bootstrap only (constants, requires, init wiring)
 *  - POR_Lang           → EN dictionary + gettext filter (admin: rs_lang;
 *                         emails: with_lang(); visitor texts are store
 *                         settings, Bible §14.17)
 *  - POR_Settings       → the por_settings option, strict validation, mode
 *                         (with the rival plugin rule), visitor texts
 *                         (el/en, filter por_text), dates
 *  - POR_Product        → pre-order data of products and variations, the
 *                         derived catalog data, the release day job
 *  - POR_Cart           → cart line note, mixed carts, the snapshot on the
 *                         order lines
 *  - POR_Orders         → orders list column and filter, background jobs
 *                         for released / new date
 *  - POR_Emails         → the two customer emails (WC_Email, off by default)
 *  - POR_Front          → badge, button, availability, structured data,
 *                         Shipping Notes date, [nox_preorders] and the
 *                         noxpress/preorders block
 *  - POR_Admin_Product  → product editor fields, product list column,
 *                         filter, quick and bulk edit
 *  - POR_Admin_UI       → menu, plugin page (upcoming, settings, texts,
 *                         tools), CSV, backup, footer
 *
 * Front-end rules (Bible §14): off after activation; test mode shows the
 * treatment only to shop managers; POR_DISABLE in wp-config.php stops
 * every front-end hook (pre-order products then sell as normal products);
 * no theme or plugin file is changed; no cookie, no external request; the
 * only writes on a visitor request are the order lines WooCommerce itself
 * creates.
 *
 * Cooperation (Bible §6): fires por_released( $product_id, $date ) on the
 * release day. Filters for others: por_is_preorder, por_text. Public API:
 * por_get_release(). Fills Shipping Notes' shn_available_from with the
 * release date. Shortcode [nox_preorders], block noxpress/preorders.
 *
 * Deviations from the Bible (with reason):
 *  - §14.4 (never in AJAX or REST): the cart note, the mixed-cart rule and
 *    the order line snapshot also run in Store API requests (Cart /
 *    Checkout blocks), because WooCommerce builds the block cart and the
 *    block order through the same hooks; nothing else runs there.
 *  - §14.10 (no writes outside admin saves): the release day job (Action
 *    Scheduler) saves the product with CRUD, so page caches and feeds see
 *    the change.
 *  - §12 (clean uninstall): the pre-order rows on order lines and the
 *    order flags stay after uninstall, as part of the store's order record
 *    (Bible §12, "store records").
 */

defined( 'ABSPATH' ) || exit;

define( 'POR_VERSION', '1.1.0' );
define( 'POR_FILE', __FILE__ );
define( 'POR_PATH', plugin_dir_path( __FILE__ ) );
define( 'POR_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once POR_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: orders only through CRUD (HPOS); the Cart and
// Checkout blocks read the cart note and get the same order line rows.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', POR_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', POR_FILE, true );
		}
	}
);

require_once POR_PATH . 'includes/class-lang.php';

final class Pre_Orders {

	public static function init(): void {

		POR_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'pre-orders', false, dirname( plugin_basename( POR_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once POR_PATH . 'includes/class-settings.php';
		require_once POR_PATH . 'includes/class-product.php';
		require_once POR_PATH . 'includes/class-orders.php';
		require_once POR_PATH . 'includes/class-emails.php';
		require_once POR_PATH . 'includes/class-cart.php';
		require_once POR_PATH . 'includes/class-front.php';

		// Product data, the release day and the order jobs keep running even
		// with POR_DISABLE: they only keep the store's own data right.
		POR_Product::init();
		POR_Orders::init();
		POR_Emails::init();

		// The shortcode and the block are registered even with POR_DISABLE
		// (they then print nothing), so the tag never shows as raw text.
		POR_Front::init();
		if ( ! self::killed() ) {
			POR_Cart::init();
		}

		if ( is_admin() ) {
			require_once POR_PATH . 'includes/class-admin-product.php';
			require_once POR_PATH . 'includes/class-admin-ui.php';
			POR_Admin_Product::init();
			POR_Admin_UI::init();
		}
	}

	/** True when POR_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'POR_DISABLE' ) && POR_DISABLE;
	}

	/** Activation: rebuild the derived catalog data in the background. */
	public static function activate(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'por_resync', array( 0 ), 'pre-orders' );
		}
	}

	/**
	 * Deactivation: no release job runs while the plugin is off (they are
	 * scheduled again by the resync on activation). Order jobs already
	 * queued stay; Action Scheduler skips them while nobody listens.
	 */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'por_release' );
			as_unschedule_all_actions( 'por_resync' );
		}
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Pre-Orders χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'pre-orders' )
			. '</p></div>';
	}
}

/**
 * The pre-order of a product (public API, Bible §6).
 *
 * @param int|WC_Product $product Product, variation or ID.
 * @return array|null { active: true, date: 'Y-m-d'|'' (empty = no date yet),
 *                    text: string, variations?: [ variation_id => 'Y-m-d'|'' ] }
 *                    or null when the product is not on pre-order.
 */
function por_get_release( $product ): ?array {
	if ( ! class_exists( 'POR_Product' ) ) {
		return null;
	}
	return POR_Product::api( $product );
}

register_activation_hook( __FILE__, array( 'Pre_Orders', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Pre_Orders', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'Pre_Orders', 'init' ), 20 );

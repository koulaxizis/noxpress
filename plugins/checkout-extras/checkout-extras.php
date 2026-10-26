<?php
/**
 * Plugin Name:          Checkout Extras
 * Plugin URI:           https://noxpress.tech
 * Description:          Τρία μικρά πράγματα γύρω από το ταμείο, σε ένα plugin: «Είναι δώρο» με αφιέρωση και προαιρετική χρέωση συσκευασίας, μήνυμα και προτεινόμενα προϊόντα στη σελίδα ευχαριστίας, και κουπόνι ευχαριστίας για την επόμενη παραγγελία, μόνο σε πληρωμένες παραγγελίες, με μέτρηση χρήσης και καθαρισμό των ληγμένων.
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
 * Update URI:           https://noxpress.tech/updates/checkout-extras
 * Text Domain:          checkout-extras
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - checkout-extras.php → bootstrap only (constants, requires, init wiring)
 *  - CKE_Lang      → EN dictionary + gettext filter (admin only: rs_lang;
 *                    visitor texts are store settings, Bible §14.17)
 *  - CKE_Settings  → the cke_settings option, strict validation, visitor
 *                    texts (el/en, filter cke_text), placeholders
 *  - CKE_Gift      → "This is a gift" at checkout (classic + block), the
 *                    gift fee, the gift on the order (totals rows, meta
 *                    box, orders list column and filter, admin email line)
 *  - CKE_Thankyou  → message, coupon box and products on the thank-you page
 *  - CKE_Coupon    → the thank-you coupon: issue on status change, rules,
 *                    email block, redemption tracking, stats, daily cleanup,
 *                    reminders (Action Scheduler)
 *  - CKE_Emails    → the WooCommerce emails "Thank-you coupon" and
 *                    "Coupon reminder" (class-email-classes.php, templates/)
 *  - CKE_Admin_UI  → menu, settings page (gift, thank-you page, coupon,
 *                    stats, tools), backup, footer
 *
 * Front-end rules (Bible §14): every part is off after activation; test
 * mode shows a part only to shop managers (and issues coupons only for
 * their orders); CKE_DISABLE in wp-config.php stops every front-end hook
 * and the coupon issue; no theme or plugin file is changed; no cookie, no
 * session, no external request.
 *
 * Cooperation (Bible §6): fires cke_coupon_created; filters cke_text,
 * cke_coupon_args. Public API: cke_get_gift().
 *
 * Deviations from the Bible (with reason):
 *  - §14.4 (never in AJAX or REST): the gift option and its fee live in
 *    WooCommerce's checkout AJAX (update_order_review, the §14.4 exception),
 *    and the block checkout gets two additional checkout fields plus a
 *    read-only cart extension value (the §14.4 Store API exception). In the
 *    Checkout block a fee cannot follow a field without session writes, so
 *    with a gift fee the option is offered in the classic checkout only.
 *  - §14.5 (no database write on a visitor request): the gift is written on
 *    the order WooCommerce creates, and the coupon is created on an order
 *    status change (also when a payment callback or the checkout request
 *    itself changes it), never on a page view.
 *  - §12 (clean uninstall): the gift on the orders, the coupon codes on the
 *    orders and the coupons themselves stay after uninstall (store records:
 *    customers hold the codes). The coupons are deleted only when "Delete
 *    the coupons … when the plugin is deleted" is ticked in Tools.
 */

defined( 'ABSPATH' ) || exit;

define( 'CKE_VERSION', '1.1.0' );
define( 'CKE_FILE', __FILE__ );
define( 'CKE_PATH', plugin_dir_path( __FILE__ ) );
define( 'CKE_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once CKE_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: orders only through CRUD (HPOS); the Checkout
// block gets the gift option through additional checkout fields.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CKE_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', CKE_FILE, true );
		}
	}
);

require_once CKE_PATH . 'includes/class-lang.php';

final class Checkout_Extras {

	public static function init(): void {

		CKE_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'checkout-extras', false, dirname( plugin_basename( CKE_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once CKE_PATH . 'includes/class-settings.php';
		require_once CKE_PATH . 'includes/class-gift.php';
		require_once CKE_PATH . 'includes/class-coupon.php';
		require_once CKE_PATH . 'includes/class-thankyou.php';
		require_once CKE_PATH . 'includes/class-emails.php';

		// Orders that already carry a gift or a coupon keep showing them
		// (admin screens, emails, the background jobs), whatever the mode.
		CKE_Gift::init_display();
		CKE_Coupon::init();
		CKE_Emails::init();

		// Emergency switch (Bible §14): no checkout or thank-you hooks at all.
		if ( ! self::killed() ) {
			CKE_Gift::init_front();
			CKE_Thankyou::init();
		}

		if ( is_admin() ) {
			require_once CKE_PATH . 'includes/class-admin-ui.php';
			CKE_Admin_UI::init();
		}
	}

	/** True when CKE_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'CKE_DISABLE' ) && CKE_DISABLE;
	}

	/** True in REST requests (Store API: Cart / Checkout blocks). */
	public static function is_rest(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		return function_exists( 'WC' ) && method_exists( WC(), 'is_store_api_request' ) && WC()->is_store_api_request();
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Checkout Extras χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'checkout-extras' )
			. '</p></div>';
	}
}

/**
 * The gift of an order (public API, Bible §6).
 *
 * @param int|WC_Order $order Order or order ID.
 * @return array|null { gift: true, message: string, fee: float }, or null when the order is not a gift.
 */
function cke_get_gift( $order ): ?array {
	if ( ! class_exists( 'CKE_Gift' ) ) {
		return null;
	}
	return CKE_Gift::order_gift( $order );
}

add_action( 'plugins_loaded', array( 'Checkout_Extras', 'init' ), 20 );

// Deactivation: the background jobs stop (they come back on the next admin page view).
register_deactivation_hook(
	__FILE__,
	static function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'cke_cleanup' );
		}
	}
);

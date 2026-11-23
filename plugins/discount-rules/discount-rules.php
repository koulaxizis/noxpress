<?php
/**
 * Plugin Name:          Discount Rules
 * Plugin URI:           https://noxpress.tech
 * Description:          Κανόνες εκπτώσεων για το WooCommerce με ημερομηνίες έναρξης και λήξης: προσφορά σε προϊόντα και κατηγορίες, κλιμακωτή έκπτωση ποσότητας, «πάρε Χ πλήρωσε Υ» και έκπτωση στο σύνολο του καλαθιού. Ένας κανόνας ανά γραμμή, ανώτατο όριο ανά παραγγελία, χαμηλότερη τιμή 30 ημερών και ιστορικό αλλαγών.
 * Version:              1.2.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.1
 * WC tested up to:      11.3
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://noxpress.tech/support/
 * Update URI:           https://noxpress.tech/updates/discount-rules
 * Text Domain:          discount-rules
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter. Tier 1 plugin (money): every
 * release runs a full security scan first.
 *
 * Dispatch map:
 *  - discount-rules.php → bootstrap only (constants, requires, init wiring,
 *                         public API)
 *  - DSR_Lang           → EN dictionary + gettext filter (admin: rs_lang;
 *                         visitor texts are store settings, Bible §14.17)
 *  - DSR_Settings       → the dsr_settings option, strict validation, mode,
 *                         visitor texts (el/en, filter dsr_text)
 *  - DSR_Rules          → the rules (option dsr_rules): validation, status,
 *                         the change history (dsr_log), export / import
 *  - DSR_Engine         → the calculation, the same for the shop pages, the
 *                         cart, the test cart and the abilities: which rule
 *                         a line gets, the order cap, the category limits,
 *                         the cart rule, the "add one more" hints
 *  - DSR_Cart           → the cart: line prices, the automatic cart
 *                         discount (a virtual coupon), customer coupons,
 *                         the hints
 *  - DSR_Order          → order lines: discount written like a coupon
 *                         (subtotal = price before the rule), order meta,
 *                         recalculation in the admin
 *  - DSR_Front          → what visitors see: sale price, tier table, labels,
 *                         Theme Patcher badge, lowest 30-day price
 *  - DSR_History        → the price history for the lowest 30-day price
 *                         (table {prefix}dsr_prices, daily job)
 *  - DSR_Abilities      → read-only abilities (rules, preview)
 *  - DSR_Admin_UI       → menu, plugin page (rules, rule form, test cart,
 *                         history, settings, texts, tools), footer
 *
 * Front-end rules (Bible §14): off after activation; test mode gives the
 * discounts only to shop managers; DSR_DISABLE in wp-config.php stops every
 * discount (the cart goes back to the normal prices at the next
 * calculation); no theme or plugin file is changed; no cookie, no external
 * request; nothing is written on a visitor request except the order lines
 * WooCommerce itself creates.
 *
 * Money rules (approved design 2026-11-16):
 *  - Every calculation runs on the server from the stored prices; nothing
 *    sent by the browser is read.
 *  - One rule per line, the one that gives the customer the most; a
 *    WooCommerce sale price that is lower wins over a rule.
 *  - Never on bundle lines (Product Bundles), never for trade accounts
 *    (Trade Accounts), other publishers' titles excluded by default.
 *  - The discount of an order never goes over the cap (default 50 % of
 *    the subtotal), and no line goes below 0.
 *  - Customer coupons do not apply to lines that already have a rule (a
 *    100 % coupon, free copies, still does, as in Product Bundles).
 *  - On any error the cart keeps the normal prices, never a zero price.
 *
 * Cooperation (Bible §6): public API dsr_get_price(), dsr_get_item_rule();
 * action dsr_rules_changed( int[] $rule_ids ); filters dsr_eligible,
 * dsr_line_discount, dsr_text. Reads tra_is_trade() (Trade Accounts),
 * bkf_get_source() (Book Feed), tfi_get_source() (Trade Feed Import),
 * por_get_release() (Pre-Orders) when they exist; gives Theme Patcher's
 * badge its percentage through tp_sale_badge_text.
 *
 * Deviations from the Bible (with reason):
 *  - §14.4 (never in AJAX or REST): the cart calculation and the cart
 *    discount also run in wc-ajax and Store API requests (Cart / Checkout
 *    blocks), because WooCommerce builds the cart and the block order
 *    through the same hooks; the sale price display also runs in Store
 *    API product lists, as Trade Accounts does (§14.33). No other REST.
 *  - §14.5 (no writes on visitor requests): none, except the order lines
 *    and order meta written while WooCommerce creates the order.
 *  - §12 (clean uninstall): the discount rows on order lines and the order
 *    meta stay after uninstall, as part of the store's order record.
 */

defined( 'ABSPATH' ) || exit;

define( 'DSR_VERSION', '1.2.0' );
define( 'DSR_FILE', __FILE__ );
define( 'DSR_PATH', plugin_dir_path( __FILE__ ) );
define( 'DSR_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once DSR_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: orders only through CRUD (HPOS); the Cart and
// Checkout blocks get the same prices and the same order lines.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DSR_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DSR_FILE, true );
		}
	}
);

require_once DSR_PATH . 'includes/class-lang.php';

final class Discount_Rules {

	public static function init(): void {

		DSR_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'discount-rules', false, dirname( plugin_basename( DSR_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once DSR_PATH . 'includes/class-settings.php';
		require_once DSR_PATH . 'includes/class-rules.php';
		require_once DSR_PATH . 'includes/class-engine.php';
		require_once DSR_PATH . 'includes/class-history.php';
		require_once DSR_PATH . 'includes/class-cart.php';
		require_once DSR_PATH . 'includes/class-order.php';
		require_once DSR_PATH . 'includes/class-front.php';
		require_once DSR_PATH . 'includes/class-abilities.php';

		DSR_Rules::init();
		DSR_History::init();
		DSR_Abilities::init();

		// The cart and the order hooks run even with DSR_DISABLE: with it
		// (or with the mode off) they only put the normal prices back on a
		// cart that still holds discounted lines, and keep the discounts of
		// existing orders when the admin recalculates them.
		DSR_Cart::init();
		DSR_Order::init();
		if ( ! self::killed() ) {
			DSR_Front::init();
		}

		if ( is_admin() ) {
			require_once DSR_PATH . 'includes/class-admin-ui.php';
			DSR_Admin_UI::init();
		}
	}

	/** True when DSR_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'DSR_DISABLE' ) && DSR_DISABLE;
	}

	/** Activation: the price history table and its daily job. */
	public static function activate(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		require_once DSR_PATH . 'includes/class-history.php';
		DSR_History::install();
	}

	/** Deactivation: no scheduled job runs while the plugin is off. */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 'dsr_price_day', 'dsr_price_batch', 'dsr_rule_edge' ) as $hook ) {
				as_unschedule_all_actions( $hook );
			}
		}
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Discount Rules χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'discount-rules' )
			. '</p></div>';
	}
}

/**
 * The price a product sells at right now because of a «Προσφορά» rule
 * (public API, Bible §6), for feeds and structured data.
 *
 * @param int|WC_Product $product Product or variation (or ID).
 * @return array|null { price: float (as stored, with or without tax like the
 *                    store's prices), regular: float, rule: int, name: string,
 *                    to: 'Y-m-d H:i'|'' (local time; empty = no end) }, or
 *                    null when no rule lowers its price for guests now.
 */
function dsr_get_price( $product ): ?array {
	if ( ! class_exists( 'DSR_Engine' ) ) {
		return null;
	}
	$p = $product instanceof WC_Product ? $product : wc_get_product( (int) $product );
	if ( ! $p instanceof WC_Product ) {
		return null;
	}
	return DSR_Engine::public_price( $p );
}

/**
 * The rule an order line got (public API, Bible §6).
 *
 * @param WC_Order_Item_Product $item Order line.
 * @return array|null { rule: int, name: string, type: string, before: float
 *                    (unit price before the rule, as stored), after: float,
 *                    discount: float (whole line) }, or null.
 */
function dsr_get_item_rule( $item ): ?array {
	if ( ! class_exists( 'DSR_Order' ) || ! $item instanceof WC_Order_Item_Product ) {
		return null;
	}
	return DSR_Order::item_rule( $item );
}

register_activation_hook( __FILE__, array( 'Discount_Rules', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Discount_Rules', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'Discount_Rules', 'init' ), 20 );

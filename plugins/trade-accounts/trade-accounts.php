<?php
/**
 * Plugin Name:          Trade Accounts
 * Plugin URI:           https://noxpress.tech
 * Description:          Λογαριασμοί χονδρικής για βιβλιοπωλεία και μεταπωλητές. Modules που ενεργοποιείς ένα-ένα: Τιμές χονδρικής (ρόλος «Χονδρική» με προκαθορισμένη έκπτωση μόνο στα προϊόντα που ορίζεις, στο κατάστημα, στο καλάθι και στην παραγγελία, με υποχρεωτικό τιμολόγιο αν θέλεις) και Trade Feed (API με όνομα χρήστη και Application Password ανά λογαριασμό, JSON ή XML, με φίλτρα, όριο κλήσεων και ημερολόγιο).
 * Version:              1.4.0
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
 * Update URI:           https://noxpress.tech/updates/trade-accounts
 * Text Domain:          trade-accounts
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * A plugin with modules (Bible §20): wholesale accounts are one thing seen
 * from two sides, the shop (wholesale prices for the account) and the
 * account's own e-shop (the catalogue it pulls). Both read the same
 * accounts, the same discount and the same list of eligible products, so
 * they live in one plugin; each module is off until switched on, and a
 * module that is off loads no code at all.
 *
 * Dispatch map:
 *  - trade-accounts.php → bootstrap only (constants, requires, init wiring)
 *  - TRA_Lang      → EN dictionary + gettext filter (admin: rs_lang)
 *  - TRA_Settings  → the tra_settings option, strict validation, modules,
 *                    visitor texts (EL/EN), test / live / off mode
 *  - TRA_Accounts  → the «Χονδρική» role, who is a trade account, the
 *                    account's discount and API switch, Application
 *                    Passwords of the Trade Feed
 *  - TRA_Scope     → which products are eligible (categories AND brands,
 *                    physical only, never Book Feed titles, per-product
 *                    exclusion), the trade price of a product
 *  - TRA_Pricing   → module "pricing": wholesale prices in the shop, cart,
 *                    Store API and order; order / line marks; invoice
 *  - TRA_Feed      → module "feed": REST tra/v1 (products, one product,
 *                    categories) in JSON or XML, rate limit, call log
 *  - TRA_Guide     → the trade guide page in a trade account's My Account
 *  - TRA_Abilities → status and accounts in the WordPress Abilities API
 *  - TRA_Admin_UI  → menu, modules, settings, accounts, API, tools
 *
 * Deviations from the Bible (with reason):
 *  - §1.1 (only public APIs of other plugins): pre-orders of PRENA are read
 *    from its meta (_simple_preorder, _wpro_date), as Home Sections does
 *    (Bible §14.30), because PRENA has no PHP API; Pre-Orders is read with
 *    por_get_release(). The feed's product query leaves out Book Feed
 *    titles by their meta _bkf_source, the marker behind bkf_get_source()
 *    (a query cannot call a function; every product is checked with
 *    bkf_get_source() again before it is listed). The invoice rule reads the receipt-type field of
 *    the invoicing plugin from the checkout POST (field name and value in
 *    the settings, default «Timologia for WooCommerce» by OBS).
 *  - §14.4 (no REST): the tra/v1 routes are the purpose of the Trade Feed
 *    module; they answer only to authenticated trade accounts (and to
 *    managers, for testing).
 *  - §20 (amounts in cents): the feed gives prices as decimal strings with
 *    the shop's decimals ("13.00"), the form e-shops import as is.
 *  - §14.5 (no database write on a visitor request): the feed writes the
 *    account's rate-limit counter (transient), its last call (user meta)
 *    and one line of the call log (option, no IP). These are API calls of
 *    an authenticated account, not page views.
 *  - §11 (PRG messages only): a new Application Password is shown once,
 *    after the redirect, from a 60-second transient of the admin who made
 *    it; the transient is deleted when the page reads it.
 *  - §12 (nothing left after uninstall): trade accounts are WordPress users
 *    and stay, as customers; the order and line marks stay with the orders
 *    (the shop's record). Settings, the log, the role, the plugin's user
 *    meta and its Application Passwords go.
 *
 * Front end (Bible §14): off until a module is switched on; test mode by
 * default (wholesale prices only for managers, as a preview); TRA_DISABLE
 * in wp-config.php stops the prices and closes the feed (403).
 *
 * Cooperation (Bible §6): tra_is_trade( $user_id ), tra_trade_price(
 * $product, $user_id ), tra_is_eligible( $product ); filters tra_eligible,
 * tra_discount, tra_feed_item and tra_text.
 */

defined( 'ABSPATH' ) || exit;

define( 'TRA_VERSION', '1.4.0' );
define( 'TRA_FILE', __FILE__ );
define( 'TRA_PATH', plugin_dir_path( __FILE__ ) );
define( 'TRA_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once TRA_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce HPOS (custom order tables) and checkout blocks: orders are
// written through the CRUD API only.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TRA_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', TRA_FILE, true );
		}
	}
);

require_once TRA_PATH . 'includes/class-lang.php';

register_activation_hook( TRA_FILE, array( 'Trade_Accounts', 'activate' ) );

final class Trade_Accounts {

	public static function init(): void {
		TRA_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'trade-accounts', false, dirname( plugin_basename( TRA_FILE ) ) . '/languages' );
			}
		);

		if ( ! self::woo() ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_no_wc' ) );
			return;
		}

		require_once TRA_PATH . 'includes/class-settings.php';
		require_once TRA_PATH . 'includes/class-accounts.php';
		require_once TRA_PATH . 'includes/class-scope.php';
		require_once TRA_PATH . 'includes/class-guide.php';
		TRA_Accounts::init();
		TRA_Scope::init();
		TRA_Guide::init();

		if ( TRA_Settings::module( 'pricing' ) ) {
			require_once TRA_PATH . 'includes/class-pricing.php';
			TRA_Pricing::init();
		}

		if ( TRA_Settings::module( 'feed' ) ) {
			require_once TRA_PATH . 'includes/class-feed.php';
			TRA_Feed::init();
		}

		require_once TRA_PATH . 'includes/class-abilities.php';
		TRA_Abilities::init();

		if ( is_admin() ) {
			require_once TRA_PATH . 'includes/class-admin-ui.php';
			TRA_Admin_UI::init();
		}
	}

	/** Activation: the «Χονδρική» role exists from the first moment. */
	public static function activate(): void {
		require_once TRA_PATH . 'includes/class-accounts.php';
		TRA_Accounts::add_role();
	}

	public static function notice_no_wc(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Το Trade Accounts χρειάζεται ενεργό WooCommerce.', 'trade-accounts' ) . '</p></div>';
	}

	/** True when TRA_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'TRA_DISABLE' ) && TRA_DISABLE;
	}

	/** True when WooCommerce is active. */
	public static function woo(): bool {
		return class_exists( 'WooCommerce' );
	}

	/** Capability for the settings (Bible §11). */
	public static function cap(): string {
		return 'manage_woocommerce';
	}
}

/**
 * Public API (Bible §6). Safe to call before the plugin loads its classes:
 * each one answers "no" until WooCommerce and the plugin are ready.
 */

/** Whether a user is a trade account (the «Χονδρική» role). 0 = current user. */
function tra_is_trade( int $user_id = 0 ): bool {
	return class_exists( 'TRA_Accounts' ) && TRA_Accounts::is_trade( $user_id );
}

/** Whether a product is eligible for wholesale prices and the feed. */
function tra_is_eligible( $product ): bool {
	return class_exists( 'TRA_Scope' ) && TRA_Scope::eligible( $product );
}

/**
 * The wholesale price of a product for a trade account (0 = current user),
 * as a float with the shop's decimals, or null when the product is not
 * eligible or the user is not a trade account.
 */
function tra_trade_price( $product, int $user_id = 0 ): ?float {
	if ( ! class_exists( 'TRA_Scope' ) || ! class_exists( 'TRA_Accounts' ) ) {
		return null;
	}
	$product = is_numeric( $product ) ? wc_get_product( (int) $product ) : $product;
	if ( ! $product instanceof WC_Product || ! TRA_Accounts::is_trade( $user_id ) || ! TRA_Scope::eligible( $product ) ) {
		return null;
	}
	return TRA_Scope::trade_price( $product, TRA_Accounts::discount( $user_id ) );
}

// Priority 20 like the other suite plugins.
add_action( 'plugins_loaded', array( 'Trade_Accounts', 'init' ), 20 );

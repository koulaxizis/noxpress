<?php
/**
 * Plugin Name:          Wish Shelf
 * Plugin URI:           https://noxpress.tech
 * Description:          Λίστα επιθυμιών για το WooCommerce: καρδούλα στις κάρτες και στη σελίδα προϊόντος, λίστα για επισκέπτες χωρίς cookies, παραλλαγές, «η τιμή έπεσε», καλάθι για όλα με ένα κλικ, κοινοποίηση με σύνδεσμο, καρτέλα στον λογαριασμό, αναφορά για το τι θέλουν οι πελάτες και εισαγωγή από το WishSuite.
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
 * Update URI:           https://noxpress.tech/updates/wish-shelf
 * Text Domain:          wish-shelf
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - wish-shelf.php → bootstrap only (constants, requires, init wiring)
 *  - WSH_Lang       → EN dictionary + gettext filter (admin only: rs_lang;
 *                     visitor texts are store settings, Bible §14.17)
 *  - WSH_Settings   → the wsh_settings option, strict validation, visitor
 *                     texts (el/en, filter wsh_text)
 *  - WSH_Store      → the {prefix}wsh_items table, share tokens, counters
 *  - WSH_Front      → heart button, counter, assets, request guard
 *  - WSH_List       → the list (page, account tab, shared link), its form
 *                     (remove, add to cart), "bought from a list"
 *  - WSH_Ajax       → wc-ajax endpoints wsh_sync and wsh_render
 *  - WSH_Account    → My Account tab, shortcodes, blocks, privacy tools
 *  - WSH_Alerts     → email alerts (price drop, back in stock): opt-in,
 *                     daily Action Scheduler job, WSH_Email_Alert (WC_Email)
 *  - WSH_Import     → other wishlist plugins: detection, import from
 *                     WishSuite, YITH and TI
 *  - WSH_Report     → ♥ column in Products, report, CSV
 *  - WSH_Admin_UI   → menu, settings page (general, list, texts, report,
 *                     tools), backup, footer
 *
 * Front-end rules (Bible §14): no change after activation (mode "off" until
 * set to "test" or "live"); test mode shows the wishlist to shop managers
 * only; WSH_DISABLE in wp-config.php stops every front-end hook; while
 * another wishlist plugin is active, "live" acts as "test" (§14.23); the
 * button HTML is the same for every visitor, so page caches keep working;
 * no theme or plugin file is changed; no external request; every callback
 * is wrapped and falls back to the page without the wishlist.
 *
 * Visitor lists (Bible §14.28): a guest's list lives only in the browser
 * (localStorage, no cookie, nothing on the server); a signed-in customer's
 * list is stored in the account and the browser list is merged into it
 * after sign-in.
 *
 * Cooperation (Bible §6): fires wsh_item_added / wsh_item_removed. Filters
 * for others: wsh_text, wsh_max_items. Public API: wsh_get_items(),
 * wsh_product_count().
 *
 * Deviations from the Bible (with reason):
 *  - §14.4 (never in AJAX): the two wc-ajax endpoints of WSH_Ajax are the
 *    plugin's purpose (the heart of a signed-in customer, a guest's list
 *    page). They check the same mode and guard as the front end.
 *  - §14.5 (no write on a visitor request): a signed-in customer's own
 *    clicks write to their own list (nonce, whitelist of operations and
 *    ids, item limit, rate limit). "Add to cart" from a list stores a mark
 *    in WooCommerce's own session, copied to the order line, so the report
 *    can count what was bought from a list.
 *  - §14.5, alerts: the daily job of WSH_Alerts writes the alert state of
 *    the items (alert_price, was_out) from cron, never from a visitor.
 *  - §12 (clean uninstall): customers' lists and share links stay after
 *    uninstall unless "Delete the lists …" is ticked in Tools (content the
 *    customers made). Settings, counters and marks always go.
 */

defined( 'ABSPATH' ) || exit;

define( 'WSH_VERSION', '1.1.0' );
define( 'WSH_FILE', __FILE__ );
define( 'WSH_PATH', plugin_dir_path( __FILE__ ) );
define( 'WSH_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once WSH_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: order line meta goes through the WC CRUD
// (HPOS-safe); the Cart / Checkout blocks are not touched.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WSH_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WSH_FILE, true );
		}
	}
);

// Activation: the table, the counters of lists kept from an earlier
// install (uninstall removes the counters, not the lists), and the account
// endpoint's rewrite rules.
register_activation_hook(
	__FILE__,
	static function () {
		require_once WSH_PATH . 'includes/class-store.php';
		WSH_Store::install();
		WSH_Store::recount_all();
		update_option( 'wsh_flush', 1, true );
	}
);

require_once WSH_PATH . 'includes/class-lang.php';

final class Wish_Shelf {

	public static function init(): void {

		WSH_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'wish-shelf', false, dirname( plugin_basename( WSH_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once WSH_PATH . 'includes/class-settings.php';
		require_once WSH_PATH . 'includes/class-store.php';
		require_once WSH_PATH . 'includes/class-import.php';
		require_once WSH_PATH . 'includes/class-front.php';
		require_once WSH_PATH . 'includes/class-list.php';
		require_once WSH_PATH . 'includes/class-ajax.php';
		require_once WSH_PATH . 'includes/class-account.php';
		require_once WSH_PATH . 'includes/class-alerts.php';

		// Shortcodes and blocks always exist (they print nothing while off),
		// so a page never shows a raw [nox_wishlist].
		WSH_Account::shortcodes();
		WSH_Account::privacy();
		// The email class, the daily job and the unsubscribe link; the job
		// itself checks mode, WSH_DISABLE and the email switch.
		WSH_Alerts::init();

		// Emergency switch (Bible §14): no front-end hook at all.
		if ( ! self::killed() ) {
			WSH_Front::init();
			WSH_List::init();
			WSH_Account::init();
			WSH_Ajax::init();
		}

		// Table changes of an update arrive without activation: before any query.
		add_action( 'init', array( __CLASS__, 'upgrade' ), 1 );

		if ( is_admin() ) {
			require_once WSH_PATH . 'includes/class-report.php';
			require_once WSH_PATH . 'includes/class-admin-ui.php';
			WSH_Report::init();
			WSH_Admin_UI::init();
		}
	}

	/** True when WSH_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'WSH_DISABLE' ) && WSH_DISABLE;
	}

	/**
	 * Creates / upgrades the tables when the schema version differs (updates
	 * without activation). One request does it; the lock keeps others out.
	 */
	public static function upgrade(): void {
		if ( WSH_Store::ready() || get_transient( 'wsh_upgrading' ) ) {
			return;
		}
		set_transient( 'wsh_upgrading', 1, MINUTE_IN_SECONDS );
		WSH_Store::install();
		delete_transient( 'wsh_upgrading' );
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Wish Shelf χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'wish-shelf' )
			. '</p></div>';
	}
}

/**
 * A signed-in customer's wishlist (public API, Bible §6).
 *
 * @param int $user_id User id (0 = the current user).
 * @return array[] Each { product_id, variation_id, added (GMT 'Y-m-d H:i:s'), list_id (0 = main list; 1.1.0) }, newest first.
 */
function wsh_get_items( int $user_id = 0 ): array {
	if ( ! class_exists( 'WSH_Store' ) ) {
		return array();
	}
	$user_id = $user_id ? $user_id : get_current_user_id();
	$out     = array();
	foreach ( WSH_Store::items( (int) $user_id ) as $it ) {
		$out[] = array(
			'product_id'   => (int) $it['p'],
			'variation_id' => (int) $it['v'],
			'added'        => (string) $it['added'],
			'list_id'      => (int) $it['list'],
		);
	}
	return $out;
}

/**
 * How many signed-in customers have a product in their wishlist (public API).
 *
 * @param int $product_id Product id (parent).
 */
function wsh_product_count( int $product_id ): int {
	return $product_id > 0 ? (int) get_post_meta( $product_id, '_wsh_count', true ) : 0;
}

add_action( 'plugins_loaded', array( 'Wish_Shelf', 'init' ), 20 );

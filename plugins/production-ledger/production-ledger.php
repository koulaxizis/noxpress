<?php
/**
 * Plugin Name:          Production Ledger
 * Plugin URI:           https://noxpress.tech
 * Description:          Κόστη και καθαρό αποτέλεσμα ανά προϊόν: καταχωρίσεις κόστους παραγωγής ανά κατηγορία (εκτύπωση, επιμέλεια, σχέδιο, ήχος, μεταφορικά, προώθηση…), μεταφορικά, αναλώσιμα και προμήθειες πληρωμών μοιρασμένα στις παραγγελίες του μήνα, αμοιβές δημιουργών από το Revenue Splitter, γενικά έξοδα σε χωριστό βιβλίο, νεκρό σημείο, εξαγωγή CSV και ροή εγγραφών για το orOS Budget. Όλα τα ποσά καθαρά, με τον ΦΠΑ σε δική του στήλη.
 * Version:              1.1.1
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
 * Update URI:           https://noxpress.tech/updates/production-ledger
 * Text Domain:          production-ledger
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * What it answers: what did each product earn after everything it cost,
 * and what is left after the general expenses of the business. The unit
 * is each WooCommerce product on its own; every amount is net (VAT in its
 * own column).
 *
 * Dispatch map:
 *  - production-ledger.php → bootstrap only (constants, requires, init
 *                       wiring), public pl_get_report()
 *  - PL_Lang          → EN dictionary + gettext filter (rs_lang, Bible §9)
 *  - PL_Settings      → the pl_settings option (start date, publisher
 *                       names in Revenue Splitter, carriers, payment
 *                       groups, modules), sealed Printful key
 *  - PL_Entries       → table {prefix}pl_entries: categories, validation,
 *                       add / update / soft delete / restore, queries
 *  - PL_Report        → the computed report per product and month (orders,
 *                       refunds, retail / trade, pools spread over the
 *                       month's orders, creator payouts, break-even)
 *  - PL_Export        → CSV per Bible §5 (entries, summary)
 *  - PL_Abilities     → production-ledger/report, entries, add-entry,
 *                       update-entry, delete-entry, export-csv
 *  - PL_Rest          → module "oros": GET wc/v3/pl/records for orOS
 *                       Budget (read only, pulled while orOS is open)
 *  - PL_Printful      → module "printful": Printful's cost per order
 *                       (read-only API key) becomes «Εκτύπωση» entries
 *  - PL_Admin_UI      → menu «Κόστη & Απόδοση», tabs, forms, footer
 *
 * Deviations from the Bible (with reason):
 *  - §12 (nothing left after uninstall): the entries are the shop's own
 *    bookkeeping (supplier invoices typed in by hand). The table stays
 *    unless «Delete the entries when the plugin is deleted» is ticked in
 *    the settings (as Featured Creators / Wish Shelf user content).
 *  - entry_date is a DATE column, not a timestamp: an invoice date is a
 *    calendar day with no time zone. Order ranges are still local-day
 *    timestamps (§1.3).
 *  - Revenue counts on the PAID date (date_paid), not the creation date
 *    (§1.3 still: local-day timestamps): it must match the bank day by day
 *    (Christos, 2026-11-07). Historical entries (before the start date,
 *    is_hist = 1) are kept for statistics only and left out of every
 *    total, the orOS feed and the monthly CSV.
 *  - §20 «under wc/v3»: the records route lives at wc/v3/pl/records because
 *    orOS knows the shop only from its /wp-json/wc/v3 root (as RS 1.11.0).
 *
 * Front end: none. Nothing runs on a visitor request. The Printful
 * module only queues a look-up when an order is completed (Action
 * Scheduler); the remote call never runs in the request that completed it.
 *
 * Cooperation (Bible §6): reads Revenue Splitter only through
 * RS_Reports::run() and RS_Beneficiaries::collect_names() (creator
 * payouts per product); listens to rs_invalidate_cache. Reads the trade
 * flag _tra_trade that Trade Accounts writes on its orders (§6). Fires
 * pl_entries_changed( int[] $ids ) after every change of entries.
 */

defined( 'ABSPATH' ) || exit;

define( 'PL_VERSION', '1.1.1' );
define( 'PL_FILE', __FILE__ );
define( 'PL_PATH', plugin_dir_path( __FILE__ ) );
define( 'PL_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once PL_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce HPOS: orders are read through wc_get_orders / CRUD only.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PL_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PL_FILE, true );
		}
	}
);

require_once PL_PATH . 'includes/class-lang.php';

final class Production_Ledger {

	const CAP   = 'manage_woocommerce';
	const GROUP = 'production-ledger';

	public static function init(): void {
		PL_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'production-ledger', false, dirname( plugin_basename( PL_FILE ) ) . '/languages' );
			}
		);

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_no_wc' ) );
			return;
		}

		require_once PL_PATH . 'includes/class-settings.php';
		require_once PL_PATH . 'includes/class-entries.php';
		require_once PL_PATH . 'includes/class-report.php';
		require_once PL_PATH . 'includes/class-abilities.php';

		PL_Report::init();
		PL_Abilities::init();

		// Queued Printful look-ups run even when the module was switched
		// off after they were queued: the handler then drops them (§20).
		add_action( 'pl_printful_fetch', array( __CLASS__, 'run_printful' ), 10, 2 );

		if ( PL_Settings::module( 'printful' ) && ! self::killed() ) {
			require_once PL_PATH . 'includes/class-printful.php';
			PL_Printful::init();
		}
		if ( PL_Settings::module( 'oros' ) ) {
			require_once PL_PATH . 'includes/class-rest.php';
			PL_Rest::init();
		}

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'upkeep' ) );
			require_once PL_PATH . 'includes/class-export.php';
			require_once PL_PATH . 'includes/class-admin-ui.php';
			PL_Admin_UI::init();
		}
	}

	/** Action Scheduler callback of a queued Printful look-up. */
	public static function run_printful( $order_id = 0, $try = 1 ): void {
		require_once PL_PATH . 'includes/class-printful.php';
		PL_Printful::fetch( (int) $order_id, (int) $try );
	}

	/** True when PL_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'PL_DISABLE' ) && PL_DISABLE;
	}

	/** Table up to date (admin requests of managers only). */
	public static function upkeep(): void {
		if ( current_user_can( self::CAP ) ) {
			PL_Entries::install();
		}
	}

	public static function activate(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			require_once PL_PATH . 'includes/class-settings.php';
			require_once PL_PATH . 'includes/class-entries.php';
			PL_Entries::install();
		}
	}

	public static function notice_no_wc(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Το Production Ledger χρειάζεται το WooCommerce: ενεργοποίησέ το για να δεις κόστη και απόδοση ανά προϊόν.', 'production-ledger' ) . '</p></div>';
	}
}

/**
 * The computed report for other plugins (Bible §6), the same numbers as
 * the admin page and the production-ledger/report ability.
 *
 * @param string $from       'Y-m-d' (local, inclusive).
 * @param string $to         'Y-m-d' (local, inclusive).
 * @param int    $product_id 0 = every product.
 * @return array|null Null without WooCommerce or on bad dates.
 */
function pl_get_report( string $from, string $to, int $product_id = 0 ): ?array {
	if ( ! class_exists( 'PL_Report' ) ) {
		return null;
	}
	try {
		$r = PL_Report::get( $from, $to, $product_id );
		return is_wp_error( $r ) ? null : $r;
	} catch ( \Throwable $e ) {
		return null;
	}
}

register_activation_hook( __FILE__, array( 'Production_Ledger', 'activate' ) );

add_action( 'plugins_loaded', array( 'Production_Ledger', 'init' ), 20 );

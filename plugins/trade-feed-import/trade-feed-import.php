<?php
/**
 * Plugin Name:          Trade Feed Import
 * Plugin URI:           https://noxpress.tech
 * Description:          Για βιβλιοπωλεία: οι τίτλοι ενός εκδότη που έχει Trade Accounts περνούν στο δικό σου WooCommerce με το όνομα χρήστη και τον κωδικό του Trade Feed. Εξώφυλλο, ISBN, συντελεστές, κατηγορία και τιμή λιανικής, καθημερινός συγχρονισμός τιμής, διαθεσιμότητας και προπαραγγελιών, κλείδωμα όσων διορθώνεις με το χέρι.
 * Version:              1.0.0
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
 * Update URI:           https://noxpress.tech/updates/trade-feed-import
 * Text Domain:          trade-feed-import
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * The other side of Trade Accounts' Trade Feed (tra/v1): a bookshop that
 * has a trade account with a publisher installs this plugin, enters the
 * publisher's address, its username and the Application Password the
 * publisher sent, and the publisher's titles become its own products.
 *
 * Dispatch map:
 *  - trade-feed-import.php → bootstrap only (constants, requires, init)
 *  - TFI_Lang     → EN dictionary + gettext filter (admin only: rs_lang)
 *  - TFI_Crypt    → sealed password (AES-256-GCM with the site's keys)
 *  - TFI_Settings → tfi_settings (connection, import, sync), the sealed
 *                   password in tfi_pass, the cached source categories
 *  - TFI_Client   → the Trade Feed over HTTPS (HTTP Basic), 429 handling,
 *                   errors without credentials
 *  - TFI_Record   → one feed item, validated and normalised
 *  - TFI_Importer → record → WooCommerce product (create, update with
 *                   field locks, link, unlink, gone, cover image)
 *  - TFI_Sync     → the daily job (Action Scheduler): every page of the
 *                   feed, then the products the feed no longer lists
 *  - TFI_Log      → history (tfi_log, last 300 entries)
 *  - TFI_Admin_UI → menu, page (connection, titles, settings, history,
 *                   tools), PRG routes, backup, product meta box
 *
 * Front end: nothing. The plugin adds no hook, asset or output on the
 * front end; what visitors see are ordinary WooCommerce products.
 *
 * Data (Bible §12): products, their images and their terms are store data
 * and stay after uninstall, unless "Delete the imported products too" is
 * ticked (off by default; only products marked with _tfi_source). The
 * plugin's own product meta is removed.
 *
 * Cooperation (Bible §6): fires noxpress_products_changed( $ids ) after
 * each import / sync step that changed products. Public API:
 * tfi_get_source( $product_id ).
 *
 * Deviations from the Bible (with reason):
 *  - §1.1 (only public APIs of other plugins): Pre-Orders has a public
 *    reader (por_get_release()) but no public writer; the release date of
 *    a pre-order title is written with POR_Product::set(), the method its
 *    own product editor uses, only when that method exists. Without it the
 *    title is put on backorder and the date is kept in _tfi_release.
 */

defined( 'ABSPATH' ) || exit;

define( 'TFI_VERSION', '1.0.0' );
define( 'TFI_FILE', __FILE__ );
define( 'TFI_PATH', plugin_dir_path( __FILE__ ) );
define( 'TFI_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once TFI_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: no order data is read or written.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TFI_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', TFI_FILE, true );
		}
	}
);

require_once TFI_PATH . 'includes/class-lang.php';

final class Trade_Feed_Import {

	public static function init(): void {

		TFI_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'trade-feed-import', false, dirname( plugin_basename( TFI_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once TFI_PATH . 'includes/class-crypt.php';
		require_once TFI_PATH . 'includes/class-settings.php';
		require_once TFI_PATH . 'includes/class-client.php';
		require_once TFI_PATH . 'includes/class-record.php';
		require_once TFI_PATH . 'includes/class-log.php';
		require_once TFI_PATH . 'includes/class-importer.php';
		require_once TFI_PATH . 'includes/class-sync.php';

		TFI_Sync::init();

		if ( is_admin() ) {
			require_once TFI_PATH . 'includes/class-admin-ui.php';
			TFI_Admin_UI::init();
		}
	}

	/** True when TFI_DISABLE is defined and truthy in wp-config.php: no call, no sync. */
	public static function killed(): bool {
		return defined( 'TFI_DISABLE' ) && TFI_DISABLE;
	}

	/** Capability for the page (Bible §11). */
	public static function cap(): string {
		return 'manage_woocommerce';
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Trade Feed Import χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'trade-feed-import' )
			. '</p></div>';
	}

	/** Deactivation: stop the scheduled job (it is scheduled again on the next admin visit). */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'trade-feed-import' );
		}
		wp_clear_scheduled_hook( 'tfi_step' );
		wp_clear_scheduled_hook( 'tfi_safety' );
		wp_clear_scheduled_hook( 'tfi_daily' );
	}
}

/**
 * Where a product came from (public API, Bible §6).
 *
 * @param int $product_id Product id.
 * @return array|null { source: string (the publisher's host), id: int (the
 *                    product id at the publisher), isbn: string, synced:
 *                    int (Unix time, 0 = never), linked: bool (true = an
 *                    existing product linked by ISBN: only price,
 *                    availability and pre-order follow the feed), gone:
 *                    bool }, or null.
 */
function tfi_get_source( int $product_id ): ?array {
	$src = (string) get_post_meta( $product_id, '_tfi_source', true );
	if ( '' === $src ) {
		return null;
	}
	return array(
		'source' => $src,
		'id'     => (int) get_post_meta( $product_id, '_tfi_id', true ),
		'isbn'   => (string) get_post_meta( $product_id, '_tfi_isbn', true ),
		'synced' => (int) get_post_meta( $product_id, '_tfi_synced', true ),
		'linked' => '1' === (string) get_post_meta( $product_id, '_tfi_linked', true ),
		'gone'   => '1' === (string) get_post_meta( $product_id, '_tfi_gone', true ),
	);
}

register_deactivation_hook( __FILE__, array( 'Trade_Feed_Import', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'Trade_Feed_Import', 'init' ), 20 );

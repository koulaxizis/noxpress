<?php
/**
 * Plugin Name:          Book Feed
 * Plugin URI:           https://noxpress.tech
 * Description:          Τίτλοι άλλων εκδοτικών από κατάλογο βιβλίων (Bookpoint / ΟΣΔΕΛ) ως προϊόντα WooCommerce: αναζήτηση και εισαγωγή, κανόνες ανά εκδότη, καθημερινός συγχρονισμός τιμής και διαθεσιμότητας, κλείδωμα όσων διορθώνεις με το χέρι.
 * Version:              1.5.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.1
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/book-feed
 * Text Domain:          book-feed
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - book-feed.php   → bootstrap only (constants, requires, init wiring)
 *  - BKF_Lang        → EN dictionary + gettext filter (admin only: rs_lang)
 *  - BKF_Crypt       → sealed secret (AES-256-GCM with the site's keys)
 *  - BKF_Settings    → bkf_settings (mapping, sync, guard), bkf_rules,
 *                      the source password, values seen in the feed
 *  - BKF_Client      → the Bookpoint web service (Solr): query building,
 *                      https first, redacted errors
 *  - BKF_Record      → one feed document, validated and normalised
 *  - BKF_Pub_Price   → the price on the publisher's own site (labelled
 *                      text, JSON-LD, Store API, Open Graph, microdata),
 *                      plausibility
 *  - BKF_Importer    → record → WooCommerce product (create, update with
 *                      field locks, link, unlink, cover images)
 *  - BKF_Sync        → the background job (Action Scheduler): linked
 *                      products, then the rules; one at a time, steps
 *                      of at most 10 s, a safety action, stall detection
 *  - BKF_Log         → history (bkf_log, last 500 entries), CSV export
 *  - BKF_Guard       → fixed book price: discount warning on save
 *  - BKF_Admin_UI    → menu, page (rules, history, settings, tools),
 *                      status box, PRG routes, CSV, backup, notices
 *  - BKF_Admin_Ajax  → search tab, AJAX import / link, publisher
 *                      autocomplete, product meta box
 *  - BKF_CLI         → wp book-feed status | search | sync
 *
 * Front end: nothing. The plugin adds no hook, asset or output on the
 * front end; what visitors see are ordinary WooCommerce products.
 *
 * Data (Bible §12): products, their images and their categories / terms
 * are store data and stay after uninstall, unless "Delete the imported
 * products too" is ticked (off by default; only products marked with
 * _bkf_source, in batches). Attachments always stay.
 *
 * Cooperation (Bible §6): fires noxpress_products_changed( $ids ) after
 * each import / sync step that changed products. Public API:
 * bkf_get_source( $product_id ). Shipping times: Shipping Notes reads the
 * preparation days of the product category the titles go into, so no
 * code is needed here.
 *
 * Deviations from the Bible (with reason): none.
 */

defined( 'ABSPATH' ) || exit;

define( 'BKF_VERSION', '1.5.0' );
define( 'BKF_FILE', __FILE__ );
define( 'BKF_PATH', plugin_dir_path( __FILE__ ) );
define( 'BKF_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once BKF_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: no order data is read or written.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BKF_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BKF_FILE, true );
		}
	}
);

require_once BKF_PATH . 'includes/class-lang.php';

final class Book_Feed {

	public static function init(): void {

		BKF_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'book-feed', false, dirname( plugin_basename( BKF_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once BKF_PATH . 'includes/class-crypt.php';
		require_once BKF_PATH . 'includes/class-settings.php';
		require_once BKF_PATH . 'includes/class-client.php';
		require_once BKF_PATH . 'includes/class-record.php';
		require_once BKF_PATH . 'includes/class-log.php';
		require_once BKF_PATH . 'includes/class-pub-price.php';
		require_once BKF_PATH . 'includes/class-importer.php';
		require_once BKF_PATH . 'includes/class-sync.php';

		BKF_Sync::init();
		add_action( 'added_term_meta', array( 'BKF_Importer', 'brand_logo' ), 10, 4 );
		add_action( 'updated_term_meta', array( 'BKF_Importer', 'brand_logo' ), 10, 4 );

		if ( is_admin() ) {
			require_once BKF_PATH . 'includes/class-guard.php';
			require_once BKF_PATH . 'includes/class-admin-ui.php';
			BKF_Guard::init();
			BKF_Admin_UI::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once BKF_PATH . 'includes/class-cli.php';
			WP_CLI::add_command( 'book-feed', 'BKF_CLI' );
		}
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Book Feed χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'book-feed' )
			. '</p></div>';
	}

	/** Deactivation: stop the scheduled job (it is scheduled again on the next save or run). */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'book-feed' );
		}
		wp_clear_scheduled_hook( 'bkf_step' );
		wp_clear_scheduled_hook( 'bkf_safety' );
		wp_clear_scheduled_hook( 'bkf_daily' );
	}
}

/**
 * Where a product came from (public API, Bible §6).
 *
 * @param int $product_id Product id.
 * @return array|null { source: 'bookpoint', id: string, isbn: string,
 *                    synced: int (Unix time, 0 = never), linked: bool
 *                    (true = an existing product linked by ISBN, whose
 *                    fields the feed does not change) }, or null.
 */
function bkf_get_source( int $product_id ): ?array {
	$src = (string) get_post_meta( $product_id, '_bkf_source', true );
	if ( '' === $src ) {
		return null;
	}
	return array(
		'source' => $src,
		'id'     => (string) get_post_meta( $product_id, '_bkf_source_id', true ),
		'isbn'   => (string) get_post_meta( $product_id, '_bkf_isbn', true ),
		'synced' => (int) get_post_meta( $product_id, '_bkf_synced', true ),
		'linked' => '1' === (string) get_post_meta( $product_id, '_bkf_linked', true ),
	);
}

register_deactivation_hook( __FILE__, array( 'Book_Feed', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'Book_Feed', 'init' ), 20 );

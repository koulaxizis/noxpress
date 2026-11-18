<?php
/**
 * Plugin Name:          Product Feed
 * Plugin URI:           https://noxpress.tech
 * Description:          Feeds προϊόντων για Google Merchant Center, Skroutz, BestPrice και κατάλογο Meta (Facebook/Instagram), και γενικό CSV: στατικό αρχείο με σταθερό URL, ανανέωση στο παρασκήνιο, ISBN/GTIN, εκδότης, προπαραγγελίες και έλεγχος πριν δημοσιευτεί.
 * Version:              1.0.3
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.1
 * WC tested up to:      11.3
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/product-feed
 * Text Domain:          product-feed
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * What it does: writes one static file per feed (XML or CSV) in
 * uploads/product-feed/<random key>/, for price comparison and shopping
 * services. The file is rebuilt in the background in batches and replaced
 * in one step (rename), so a reader never sees half a feed; the web server
 * serves it without loading WordPress.
 *
 * Dispatch map:
 *  - product-feed.php → bootstrap only (constants, requires, init wiring),
 *                       public pfd_get_feeds()
 *  - PFD_Lang         → EN dictionary + gettext filter (rs_lang, Bible §9)
 *  - PFD_Settings     → options pfd_settings (folder key, uninstall) and
 *                       pfd_feeds (the feeds), strict validation, status
 *  - PFD_Item         → one product → normalised rows (prices with VAT,
 *                       GTIN, brand, availability, images, category path)
 *  - PFD_Channels     → registry of the writers in includes/channels/:
 *                       PFD_Ch_Google, PFD_Ch_Skroutz, PFD_Ch_BestPrice,
 *                       PFD_Ch_Meta, PFD_Ch_Csv
 *  - PFD_Builder      → Action Scheduler batches, tick, change debounce,
 *                       atomic replace, legacy path copy, build report
 *  - PFD_Ctx_Import   → one-off import of CTX Feed (WebAppick) settings
 *  - PFD_Admin_UI     → menu «Product Feed», tabs, forms, footer
 *
 * Deviations from the Bible (with reason):
 *  - §14 «no DB writes from visitor requests»: a stock change during a
 *    visitor's checkout may queue ONE debounced rebuild (at most one per
 *    10 minutes, guarded by a transient). Without it a sold-out product
 *    would stay «in stock» in the feeds for up to 6 hours.
 *  - §12: the generated feed files are deleted on uninstall only when
 *    «Delete the feed files» is ticked (on by default). A copy written to
 *    a legacy path (e.g. the old CTX Feed file) is never deleted: the
 *    shopping service may still read it.
 *  - The CTX Feed import reads the options of a third-party plugin
 *    (wf_feed_*), once, on the owner's click, as a migration. Nothing of
 *    CTX Feed is changed.
 *
 * Front end: none. Nothing runs on a visitor request except the change
 * debounce above. The feeds are static files.
 *
 * Cooperation (Bible §6): reads
 * hic_hidden_terms( 'shop' ) (Hide Catalog), por_get_release() (Pre-Orders),
 * crt_get_creators() (Featured Creators, CSV author column),
 * bkf_get_source() (Book Feed imports). GTIN from WooCommerce's own
 * global unique id (as Search Signals and Trade Accounts). Listens to
 * noxpress_products_changed. Fires pfd_feed_built( string $id, array $status ).
 */

defined( 'ABSPATH' ) || exit;

define( 'PFD_VERSION', '1.0.3' );
define( 'PFD_FILE', __FILE__ );
define( 'PFD_PATH', plugin_dir_path( __FILE__ ) );
define( 'PFD_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once PFD_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce HPOS: no order is read or written.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PFD_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PFD_FILE, true );
		}
	}
);

require_once PFD_PATH . 'includes/class-lang.php';

final class Product_Feed {

	const CAP   = 'manage_woocommerce';
	const GROUP = 'product-feed';

	public static function init(): void {
		PFD_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'product-feed', false, dirname( plugin_basename( PFD_FILE ) ) . '/languages' );
			}
		);

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_no_wc' ) );
			return;
		}

		self::load();
		PFD_Builder::init();

		if ( is_admin() ) {
			require_once PFD_PATH . 'includes/class-ctx-import.php';
			require_once PFD_PATH . 'includes/class-admin-ui.php';
			PFD_Admin_UI::init();
		}
	}

	/** Every class the builder needs (also from uninstall-free contexts). */
	public static function load(): void {
		require_once PFD_PATH . 'includes/class-settings.php';
		require_once PFD_PATH . 'includes/class-item.php';
		require_once PFD_PATH . 'includes/class-channels.php';
		require_once PFD_PATH . 'includes/class-builder.php';
	}

	/** True when PFD_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'PFD_DISABLE' ) && PFD_DISABLE;
	}

	public static function activate(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			self::load();
			PFD_Builder::schedule_tick();
		}
	}

	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( array( 'pfd_tick', 'pfd_batch', 'pfd_changed' ) as $hook ) {
				as_unschedule_all_actions( $hook, array(), self::GROUP );
			}
		}
	}

	public static function notice_no_wc(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Το Product Feed χρειάζεται το WooCommerce: ενεργοποίησέ το για να φτιάξεις feeds προϊόντων.', 'product-feed' ) . '</p></div>';
	}
}

/**
 * The feeds of this site for other plugins (Bible §6): id => [ name,
 * channel, url, active, finished (unix), count ]. Empty without WooCommerce.
 */
function pfd_get_feeds(): array {
	if ( ! class_exists( 'PFD_Settings' ) ) {
		return array();
	}
	try {
		$out = array();
		foreach ( PFD_Settings::feeds() as $id => $f ) {
			$st         = PFD_Settings::status( $id );
			$out[ $id ] = array(
				'name'     => $f['name'],
				'channel'  => $f['channel'],
				'url'      => PFD_Settings::feed_url( $f ),
				'active'   => (bool) $f['active'],
				'finished' => (int) $st['finished'],
				'count'    => (int) $st['count'],
			);
		}
		return $out;
	} catch ( \Throwable $e ) {
		return array();
	}
}

register_activation_hook( __FILE__, array( 'Product_Feed', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Product_Feed', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Product_Feed', 'init' ), 20 );

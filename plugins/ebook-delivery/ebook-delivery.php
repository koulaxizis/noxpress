<?php
/**
 * Plugin Name:          Ebook Delivery
 * Plugin URI:           https://noxpress.tech
 * Description:          Υδατογράφημα του αγοραστή σε κάθε PDF και EPUB τη στιγμή της λήψης (social DRM), με κρυφό κωδικό που δείχνει ποια παραγγελία μοιράστηκε ένα αρχείο, καθαρά ονόματα αρχείων και σελίδα «Η βιβλιοθήκη μου» στον λογαριασμό. Πάνω στις λήψεις του WooCommerce: τα όρια, η λήξη και το ιστορικό μένουν δικά του.
 * Version:              1.0.1
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
 * Update URI:           https://noxpress.tech/updates/ebook-delivery
 * Text Domain:          ebook-delivery
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - ebook-delivery.php → bootstrap only (constants, requires, init wiring)
 *  - EBD_Lang       → EN dictionary + gettext filter (admin: rs_lang)
 *  - EBD_Settings   → the ebd_settings option, strict validation, visitor
 *                     texts (EL / EN, Bible §14.17)
 *  - EBD_Code       → the fingerprint code of a download (HMAC with the
 *                     plugin's own secret) and its lookup
 *  - EBD_Store      → the private cache folder (Bible §12), file names,
 *                     disk checks, daily cleanup, the issue log
 *  - EBD_Pdf_*      → PDF reader (copy of Book Preview's), EBD_Pdf_Stamp
 *                     (incremental update), EBD_Font (embedded Noto Sans)
 *  - EBD_Epub       → EPUB stamping (Ex libris page, line, metadata)
 *  - EBD_Delivery   → WooCommerce download hooks: which file is served,
 *                     the clean file name, the download method
 *  - EBD_Library    → My Account endpoint «Η βιβλιοθήκη μου», the link in
 *                     the order emails
 *  - EBD_Limits     → per-product switch, defaults for new downloadable
 *                     products, bulk apply with backup and restore
 *  - EBD_Admin_UI   → menu, settings, library texts, limits, «Ποιος το
 *                     μοιράστηκε;», status, tools, footer
 *  - EBD_Abilities  → ebook-delivery/status, ebook-delivery/lookup-code
 *
 * How a download works:
 *  1. The buyer presses a download link (My Account, the library or the
 *     order email). WooCommerce checks the link, the order key and the
 *     e-mail as always.
 *  2. Filter woocommerce_download_product_filepath (it carries the order
 *     and the permission): for a local PDF / EPUB of a product that is not
 *     switched off, the stamped copy for this permission is made once (or
 *     taken from the cache) and its path is returned. Any failure returns
 *     the original path and writes a line to the issue log: a buyer never
 *     loses a book because of the watermark.
 *  3. WooCommerce then checks the limits and the expiry, counts and logs
 *     the download (Revenue Splitter reads that log) and serves the file.
 *     For our files the method is always «Force downloads» (filter
 *     woocommerce_file_download_method): X-Accel-Redirect and redirects
 *     would need the private folder to be public.
 *
 * Front-end rules (Bible §14): mode "off" after activation (downloads are
 * served as before, no library); test mode stamps and shows the library
 * only for shop managers (manage_woocommerce); EBD_DISABLE in wp-config.php
 * stops every hook; no theme or plugin file is changed; every callback is
 * wrapped in try/catch(\Throwable).
 *
 * Deviations from the Bible (with reason):
 *  - §14.5 (no DB write on a visitor request): a download writes the
 *    stamped copy to the private folder (a file, not the database). A file
 *    that cannot be stamped adds one line to the issue log (option
 *    ebd_issues), at most once a day per file and reason, so the shop
 *    manager learns about it. WooCommerce's own download counting is a
 *    write that happens anyway.
 *  - §14.4 (never on AJAX / REST): downloads run on WooCommerce's init
 *    handler of the download link, which is the purpose of the plugin.
 *
 * Cooperation (Bible §6): fires ebd_stamped( $path, $context ) after a
 * copy is made. Filters ebd_should_stamp, ebd_line_text, ebd_download_name,
 * ebd_library_items and ebd_text. The download log stays WooCommerce's, so
 * Revenue Splitter's «Οι τίτλοι σου» counts keep working.
 */

defined( 'ABSPATH' ) || exit;

define( 'EBD_VERSION', '1.0.1' );
define( 'EBD_FILE', __FILE__ );
define( 'EBD_PATH', plugin_dir_path( __FILE__ ) );
define( 'EBD_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once EBD_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: orders are read through CRUD only.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', EBD_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', EBD_FILE, true );
		}
	}
);

require_once EBD_PATH . 'includes/class-lang.php';

final class Ebook_Delivery {

	public static function init(): void {

		EBD_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'ebook-delivery', false, dirname( plugin_basename( EBD_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once EBD_PATH . 'includes/class-settings.php';
		require_once EBD_PATH . 'includes/class-code.php';
		require_once EBD_PATH . 'includes/class-store.php';
		require_once EBD_PATH . 'includes/class-limits.php';

		EBD_Store::init();
		EBD_Limits::init();

		if ( ! self::killed() ) {
			require_once EBD_PATH . 'includes/class-delivery.php';
			require_once EBD_PATH . 'includes/class-library.php';
			EBD_Delivery::init();
			EBD_Library::init();
		}

		if ( is_admin() ) {
			require_once EBD_PATH . 'includes/class-admin-ui.php';
			EBD_Admin_UI::init();
		}

		require_once EBD_PATH . 'includes/class-abilities.php';
		EBD_Abilities::init();
	}

	/** Loads the PDF / EPUB engine (only when a file is stamped or read). */
	public static function load_engine(): void {
		require_once EBD_PATH . 'includes/class-pdf.php';
		require_once EBD_PATH . 'includes/class-font.php';
		require_once EBD_PATH . 'includes/class-pdf-stamp.php';
		require_once EBD_PATH . 'includes/class-epub.php';
	}

	/** True when EBD_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'EBD_DISABLE' ) && EBD_DISABLE;
	}

	/** Capability of the settings, the tools and the abilities. */
	public static function cap(): string {
		return 'manage_woocommerce';
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Ebook Delivery χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'ebook-delivery' )
			. '</p></div>';
	}
}

add_action( 'plugins_loaded', array( 'Ebook_Delivery', 'init' ), 20 );

/*
 * Activation: the secret of the fingerprint codes, the issue log and a
 * rewrite flush on the next request (the library endpoint).
 */
register_activation_hook(
	EBD_FILE,
	static function () {
		if ( ! get_option( 'ebd_secret' ) ) {
			add_option( 'ebd_secret', bin2hex( random_bytes( 32 ) ), '', false );
		}
		add_option( 'ebd_issues', array(), '', false );
		update_option( 'ebd_flush', 1, false );
	}
);

register_deactivation_hook(
	EBD_FILE,
	static function () {
		wp_clear_scheduled_hook( 'ebd_cleanup' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'ebd_cleanup' );
		}
		// Rebuilt on the next request without our endpoint.
		delete_option( 'rewrite_rules' );
	}
);

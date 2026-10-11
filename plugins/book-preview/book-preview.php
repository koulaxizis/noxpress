<?php
/**
 * Plugin Name:          Book Preview
 * Plugin URI:           https://noxpress.tech
 * Description:          Δείγμα βιβλίου στη σελίδα κάθε προϊόντος: ορίζεις ένα PDF και τις σελίδες του δείγματος, το plugin φτιάχνει στον server ξεχωριστό PDF μόνο με αυτές και το δείχνει σε viewer μέσα στη σελίδα. Το πλήρες PDF δεν φτάνει ποτέ στον browser.
 * Version:              1.0.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.1
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/book-preview
 * Text Domain:          book-preview
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - book-preview.php → bootstrap only (constants, requires, init wiring)
 *  - BPV_Lang        → EN dictionary + gettext filter (admin: rs_lang)
 *  - BPV_Settings    → options, visitor texts (§14.17), strict validation
 *  - BPV_Pdf_*       → pure PHP reader / writer that copies chosen pages
 *                      into a new PDF (includes/class-pdf.php)
 *  - BPV_Pages       → page list parser ("1-12, 20, 35-40")
 *  - BPV_Source      → the PDFs a product can use (downloadable files of
 *                      the product and its variations, media library) and
 *                      their local paths
 *  - BPV_Engine      → generation: own engine, then qpdf / Ghostscript
 *                      when they exist on the server
 *  - BPV_Samples     → sample files, product meta, staleness, background
 *                      regeneration (Action Scheduler)
 *  - BPV_Metabox     → "Book sample" box on the product screen + AJAX
 *  - BPV_Front       → button, viewer, shortcode [book_preview], block
 *  - BPV_Admin_UI    → menu, pages (samples, bulk, settings), PRG, AJAX,
 *                      backup, footer
 *
 * Front-end rules (Bible §14): nothing shows until enabled; test mode shows
 * the button to shop managers only; BPV_DISABLE in wp-config.php stops
 * every front-end hook; visitor requests only read (the sample files are
 * made in the admin or in background jobs); no cookies, no storage, no
 * external requests: PDF.js is bundled and loads on the first click.
 *
 * Data (Bible §12): the sample files in uploads/noxpress-previews/ are
 * derived data and go with the plugin on uninstall; the source PDFs are
 * never touched.
 *
 * Deviations from the Bible: the PDF reader and writer are instances, not
 * static classes (several files may be open in one request). Third-party
 * code: PDF.js (Apache-2.0) in assets/vendor/pdfjs, see LICENSES.md.
 */

defined( 'ABSPATH' ) || exit;

define( 'BPV_VERSION', '1.0.0' );
define( 'BPV_FILE', __FILE__ );
define( 'BPV_PATH', plugin_dir_path( __FILE__ ) );
define( 'BPV_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once BPV_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce HPOS (custom order tables) compatibility: Book Preview never touches orders.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BPV_FILE, true );
		}
	}
);

require_once BPV_PATH . 'includes/class-lang.php';

final class Book_Preview {

	public static function init(): void {

		BPV_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'book-preview', false, dirname( plugin_basename( BPV_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once BPV_PATH . 'includes/class-settings.php';
		require_once BPV_PATH . 'includes/class-pages.php';
		require_once BPV_PATH . 'includes/class-source.php';
		require_once BPV_PATH . 'includes/class-samples.php';
		require_once BPV_PATH . 'includes/class-front.php';

		// Background regeneration and the save hooks never print anything,
		// so they stay on with BPV_DISABLE.
		BPV_Samples::init();

		// Emergency switch (Bible §14): no front-end hook at all.
		if ( ! self::killed() ) {
			BPV_Front::init();
		}

		if ( is_admin() ) {
			require_once BPV_PATH . 'includes/class-metabox.php';
			require_once BPV_PATH . 'includes/class-admin-ui.php';
			BPV_Metabox::init();
			BPV_Admin_UI::init();
		}
	}

	/** True when BPV_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'BPV_DISABLE' ) && BPV_DISABLE;
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Book Preview χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'book-preview' )
			. '</p></div>';
	}
}

add_action( 'plugins_loaded', array( 'Book_Preview', 'init' ), 20 );

<?php
/**
 * Plugin Name:          Theme Patcher
 * Plugin URI:           https://noxpress.tech
 * Description:          Διορθώνει κλασικά θέματα WooCommerce χωρίς child theme και χωρίς αλλαγή στα αρχεία τους: τιμή, κουμπί καλαθιού, ένδειξη έκπτωσης και hooks στις κάρτες προϊόντων, πίνακας κειμένων, εικόνες και πλακίδια κατηγοριών, προστασία των ρυθμίσεων του θέματος, ονόματα προσβασιμότητας, ετικέτες τιμής («Δωρεάν», προϊόντα χωρίς τιμή), κείμενο ένδειξης έκπτωσης για όλο το site («Έκπτωση 20%»), καρτέλα «Φροντίδα και ασφάλεια» στη σελίδα προϊόντος. Ανιχνευτής θέματος, σάρωση σελίδας και λειτουργία δοκιμής.
 * Version:              1.5.2
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.1
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/theme-patcher
 * Text Domain:          theme-patcher
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - theme-patcher.php  → bootstrap only (constants, requires, init wiring)
 *  - TP_Lang         → EN dictionary + gettext filter (reads rs_lang only)
 *  - TP_Settings     → schema, defaults, strict validation, per-theme storage
 *  - TP_Areas        → built-in areas registry, file areas, request context
 *  - TP_Detector     → theme scan (token based), suggestions, fingerprints
 *  - TP_Render       → slot rendering (sale badge / rating / price /
 *                      button / card hooks), area CSS
 *  - TP_Runtime      → front-end gating, loop contexts, markers, buffers,
 *                      card image size
 *  - TP_Replace      → template swaps (archive / related / up-sells /
 *                      cross-sells / content-product)
 *  - TP_Page         → page HTML rules (text table, element removal,
 *                      same-tab links, image size / alt, aria names), CSS
 *  - TP_Theme        → theme settings guard, setting overrides, local files
 *  - TP_Categories   → category image fallback, category lists
 *  - TP_Prices       → price labels ("Free" for price 0, per-category
 *                      labels for products without a price)
 *  - TP_Sale          → sale badge text for the whole store ("Sale
 *                      {percent}", "up to", fallback, product page text)
 *  - TP_Care         → "Care & Safety" product tab (care / safety texts
 *                      per product type, manufacturer, EU responsible
 *                      person), product meta, resolution
 *  - TP_Care_Admin   → its admin tab, product side box, preview
 *  - TP_Care_CLI     → `wp theme-patcher care resolve` (read only)
 *  - TP_Checks       → admin checks (block filter widgets)
 *  - TP_Admin_UI     → menu, pages, PRG routes, backup, footer
 *
 * Front-end rules (Bible §14): nothing changes until configured; test
 * mode shows changes to shop managers only; TP_DISABLE in wp-config.php
 * stops every front-end hook; no database writes on visitor requests (the
 * settings guard even blocks the theme's own); theme files are never
 * modified.
 *
 * Deviation (Bible §14.4): the price labels also run on AJAX requests,
 * because WooCommerce refreshes the cart, the mini cart and the checkout
 * over AJAX; the sale badge text too, for quick views and product lists
 * loaded over AJAX. They never run on admin screens, REST (the Store API
 * included), feeds, cron, CLI or XML-RPC, and they work on block themes
 * too (WooCommerce's price and sale badge blocks render on the server).
 *
 * Deviation (Bible §14.17): the profile texts of the Care & Safety tab are
 * HTML (wp_kses_post), not plain text, because they are content (care
 * instructions, warnings, lists). Headings stay plain text.
 *
 * Data kept after uninstall (Bible §12): the per-product choices of the
 * Care & Safety tab (_tp_care_* post meta) stay unless "delete on
 * uninstall" is ticked in the settings; the tp_care option always goes.
 *
 * Cooperation (Bible §6): Theme Patcher changes presentation only, so it
 * fires no suite hook and listens to none.
 */

defined( 'ABSPATH' ) || exit;

define( 'TP_VERSION', '1.5.2' );
define( 'TP_FILE', __FILE__ );
define( 'TP_PATH', plugin_dir_path( __FILE__ ) );
define( 'TP_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once TP_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce HPOS (custom order tables) compatibility: Theme Patcher never touches orders.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TP_FILE, true );
		}
	}
);

require_once TP_PATH . 'includes/class-lang.php';

final class Theme_Patcher {

	public static function init(): void {

		TP_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'theme-patcher', false, dirname( plugin_basename( TP_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once TP_PATH . 'includes/class-settings.php';
		require_once TP_PATH . 'includes/class-areas.php';
		require_once TP_PATH . 'includes/class-detector.php';
		require_once TP_PATH . 'includes/class-render.php';
		require_once TP_PATH . 'includes/class-runtime.php';
		require_once TP_PATH . 'includes/class-replace.php';
		require_once TP_PATH . 'includes/class-page.php';
		require_once TP_PATH . 'includes/class-theme.php';
		require_once TP_PATH . 'includes/class-categories.php';
		require_once TP_PATH . 'includes/class-prices.php';
		require_once TP_PATH . 'includes/class-sale.php';
		require_once TP_PATH . 'includes/class-care.php';

		TP_Detector::init();

		// Emergency switch (Bible §14): no front-end hook at all.
		if ( ! self::killed() ) {
			TP_Theme::init();
			TP_Categories::init();
			TP_Runtime::init();
			TP_Replace::init();
			TP_Page::init();
			TP_Prices::init();
			TP_Sale::init();
		}
		// Product meta registration always; front-end hooks only without TP_DISABLE.
		TP_Care::init();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once TP_PATH . 'includes/class-care-cli.php';
			\WP_CLI::add_command( 'theme-patcher care', 'TP_Care_CLI' );
		}

		if ( is_admin() ) {
			require_once TP_PATH . 'includes/class-checks.php';
			TP_Checks::init();
			require_once TP_PATH . 'includes/class-admin-ui.php';
			TP_Admin_UI::init();
			require_once TP_PATH . 'includes/class-care-admin.php';
			TP_Care_Admin::init();
		}
	}

	/** True when TP_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'TP_DISABLE' ) && TP_DISABLE;
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Theme Patcher χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'theme-patcher' )
			. '</p></div>';
	}
}

add_action( 'plugins_loaded', array( 'Theme_Patcher', 'init' ), 20 );

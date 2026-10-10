<?php
/**
 * Plugin Name:          Shop Filters
 * Plugin URI:           https://noxpress.tech
 * Description:          Λιτά φίλτρα προϊόντων για κλασικά θέματα WooCommerce: κατηγορίες με πραγματική ιεραρχία, χαρακτηριστικά με μετρητές, τιμή με slider. Ομαδοποίηση τιμών χαρακτηριστικών (π.χ. 88 ηλικίες σε 6 εύρη) χωρίς αλλαγή στα προϊόντα. Καθαροί σύνδεσμοι που δουλεύουν χωρίς JavaScript, εμφάνιση που αντέχει τους κανόνες του θέματος, χωρίς εξωτερικές βιβλιοθήκες.
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
 * Update URI:           https://noxpress.tech/updates/shop-filters
 * Text Domain:          shop-filters
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - shop-filters.php  → bootstrap only (constants, requires, init wiring)
 *  - SHF_Lang       → EN dictionary + gettext filter (admin: rs_lang,
 *                     front end: the site language)
 *  - SHF_Settings   → options (settings, filter sets, value groups),
 *                     strict validation
 *  - SHF_Groups     → value groups: range parser, keyword rules,
 *                     suggestions, term ↔ group resolution
 *  - SHF_Request    → front-end gating, URL parameters (whitelist),
 *                     current context, filter URLs
 *  - SHF_Index      → product ↔ term maps (WooCommerce attributes lookup
 *                     table, term_relationships fallback), result sets,
 *                     counts, price bounds (read only, object cache)
 *  - SHF_Query      → applies the active filters to the main product query
 *  - SHF_Render     → filter set HTML, widget, shortcode, assets
 *  - SHF_Seo        → noindex / canonical on filtered pages
 *  - SHF_Admin_UI   → menu, pages, PRG routes, backup, footer
 *
 * Front-end rules (Bible §14): nothing changes until enabled and a filter
 * set is shown; test mode shows the filters to shop managers only;
 * SHF_DISABLE in wp-config.php stops every front-end hook; no database
 * writes on visitor requests; product data is never changed.
 *
 * Cooperation (Bible §6): Shop Filters changes no data, so it fires no
 * suite hook. It answers the public filter `noxpress_filters_present`
 * (true when the filters are active), which Theme Patcher can read.
 *
 * Deviations from the Bible (with reason):
 *  - §14.10 (queries in admin / save hooks, page views only read): the
 *    counts depend on the visitor's selection, so they are computed on the
 *    page view. The queries only read (WooCommerce's lookup tables), their
 *    maps are kept in the object cache, and nothing is written.
 *  - §14.9 (CSS once in the <head>): true when the widget is placed in a
 *    classic theme. A shortcode in the content of a classic theme enqueues
 *    while the page renders, so WordPress prints the CSS in the footer.
 */

defined( 'ABSPATH' ) || exit;

define( 'SHF_VERSION', '1.0.0' );
define( 'SHF_FILE', __FILE__ );
define( 'SHF_PATH', plugin_dir_path( __FILE__ ) );
define( 'SHF_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once SHF_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce HPOS (custom order tables) compatibility: Shop Filters never touches orders.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SHF_FILE, true );
		}
	}
);

require_once SHF_PATH . 'includes/class-lang.php';

final class Shop_Filters {

	public static function init(): void {

		SHF_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'shop-filters', false, dirname( plugin_basename( SHF_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once SHF_PATH . 'includes/class-settings.php';
		require_once SHF_PATH . 'includes/class-groups.php';
		require_once SHF_PATH . 'includes/class-request.php';
		require_once SHF_PATH . 'includes/class-index.php';
		require_once SHF_PATH . 'includes/class-query.php';
		require_once SHF_PATH . 'includes/class-render.php';
		require_once SHF_PATH . 'includes/class-seo.php';

		// The widget is registered even with SHF_DISABLE, so it stays in its
		// sidebar; it then prints nothing.
		SHF_Render::init_widget();

		// Emergency switch (Bible §14): no front-end hook at all.
		if ( ! self::killed() ) {
			SHF_Query::init();
			SHF_Render::init();
			SHF_Seo::init();
		}

		if ( is_admin() ) {
			require_once SHF_PATH . 'includes/class-admin-ui.php';
			SHF_Admin_UI::init();
		}
	}

	/** True when SHF_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'SHF_DISABLE' ) && SHF_DISABLE;
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Shop Filters χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'shop-filters' )
			. '</p></div>';
	}
}

add_action( 'plugins_loaded', array( 'Shop_Filters', 'init' ), 20 );

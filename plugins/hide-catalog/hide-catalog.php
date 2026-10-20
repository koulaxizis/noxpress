<?php
/**
 * Plugin Name:          Hide Catalog
 * Plugin URI:           https://noxpress.tech
 * Description:          Απόκρυψη κατηγοριών προϊόντων και των προϊόντων τους από το shop, τις άλλες λίστες, την αναζήτηση, τα σχετικά προϊόντα, τα shortcodes και τα blocks, με κανόνα ανά κατηγορία. Η σελίδα της κατηγορίας μένει ανοιχτή ή κλείνει (noindex, 404, ανακατεύθυνση). Τα προϊόντα δεν αλλάζουν.
 * Version:              1.0.1
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.1
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/hide-catalog
 * Text Domain:          hide-catalog
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - hide-catalog.php → bootstrap only (constants, requires, init wiring)
 *  - HIC_Lang      → EN dictionary + gettext filter (admin only: rs_lang)
 *  - HIC_Settings  → the hic_settings option, strict validation, the rules
 *  - HIC_Rules     → who sees the rules (mode), term sets, tax_query
 *                    clauses per context, visible ids, category pages,
 *                    admin preview
 *  - HIC_Front     → the hooks: WooCommerce's catalog tax_query, the
 *                    WordPress search, related / upsells / cross-sells,
 *                    shortcodes, blocks, Store API product lists, category
 *                    lists, product categories, breadcrumbs, category
 *                    pages (noindex / 404 / redirect), sitemaps
 *  - HIC_Admin_UI  → menu, settings page (rules, tools), import from
 *                    "Hide Categories Or Products On Shop Page", backup,
 *                    page cache clearing, footer
 *
 * Front-end rules (Bible §14): nothing changes after activation (mode
 * "off" and no rules); test mode applies the rules to shop managers only;
 * HIC_DISABLE in wp-config.php stops every front-end hook; no theme or
 * plugin file is changed; no product data is written (the rules apply at
 * query time, so deactivation restores the catalog at once); no write on
 * a visitor request.
 *
 * Cooperation (Bible §6): fires nothing (it changes no data). Public API:
 * hic_hidden_terms( $context ), filter hic_hidden_terms. Shop Filters
 * needs no change: its counts read WooCommerce's catalog tax_query, which
 * carries our clauses.
 *
 * Deviations from the Bible (with reason):
 *  - §14.4 (never in REST): the Store API product list (All Products
 *    block) and the cart's cross-sells in Store API requests are filtered
 *    too — read-only, products only — otherwise those blocks would show
 *    what the rest of the shop hides.
 */

defined( 'ABSPATH' ) || exit;

define( 'HIC_VERSION', '1.0.1' );
define( 'HIC_FILE', __FILE__ );
define( 'HIC_PATH', plugin_dir_path( __FILE__ ) );
define( 'HIC_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once HIC_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: no order data is read or written; product
// blocks of the Cart / Checkout pages are not changed.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', HIC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', HIC_FILE, true );
		}
	}
);

require_once HIC_PATH . 'includes/class-lang.php';

final class Hide_Catalog {

	public static function init(): void {

		HIC_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'hide-catalog', false, dirname( plugin_basename( HIC_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once HIC_PATH . 'includes/class-settings.php';
		require_once HIC_PATH . 'includes/class-rules.php';

		// Emergency switch (Bible §14): no front-end hook at all.
		if ( ! self::killed() ) {
			require_once HIC_PATH . 'includes/class-front.php';
			HIC_Front::init();
		}

		if ( is_admin() ) {
			require_once HIC_PATH . 'includes/class-admin-ui.php';
			HIC_Admin_UI::init();
		}
	}

	/** True when HIC_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'HIC_DISABLE' ) && HIC_DISABLE;
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Hide Catalog χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'hide-catalog' )
			. '</p></div>';
	}
}

/**
 * Hidden product categories for the current request (public API, Bible §6).
 *
 * @param string $context 'shop' | 'listings' | 'search' | 'related' | 'blocks'
 *                        (where products are hidden) or 'terms' (the
 *                        category itself is hidden from category lists).
 * @return int[] Category ids, subcategories included where the rule says
 *               so; empty when the rules do not apply to this request.
 */
function hic_hidden_terms( string $context = 'shop' ): array {
	$ids = array();
	if ( class_exists( 'HIC_Rules' ) && in_array( $context, array( 'shop', 'listings', 'search', 'related', 'blocks', 'terms' ), true ) ) {
		try {
			$ids = HIC_Rules::hidden_terms( $context );
		} catch ( \Throwable $e ) {
			$ids = array();
		}
	}
	return array_values( array_map( 'intval', (array) apply_filters( 'hic_hidden_terms', $ids, $context ) ) );
}

add_action( 'plugins_loaded', array( 'Hide_Catalog', 'init' ), 20 );

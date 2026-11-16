<?php
/**
 * Plugin Name:          Live Search
 * Plugin URI:           https://noxpress.tech
 * Description:          Αναζήτηση προϊόντων με προτάσεις καθώς γράφεις: τίτλος, ISBN / GTIN / SKU, συγγραφείς και άλλα χαρακτηριστικά, χωρίς τόνους, με greeklish και με ανοχή σε μικρά λάθη. Οι προτάσεις έρχονται από ένα στατικό αρχείο, χωρίς αίτημα στον server σε κάθε πλήκτρο· η σελίδα αποτελεσμάτων βρίσκει τα ίδια.
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
 * Update URI:           https://noxpress.tech/updates/live-search
 * Text Domain:          live-search
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter. Design approved by Chris
 * 2026-11-16 (notes/live-search/01-sxediasmos.md, Q1–Q10).
 *
 * Dispatch map:
 *  - live-search.php → bootstrap only (constants, requires, init wiring),
 *                      public API lvs_normalize()
 *  - LVS_Lang     → EN dictionary + gettext filter (admin: rs_lang; the
 *                   visitor's language: site locale or the setting)
 *  - LVS_Settings → the lvs_settings option, strict validation, visitor
 *                   texts, synonyms
 *  - LVS_Text     → normalisation (lower case, no accents, ς→σ), the
 *                   greeklish / phonetic key, ISBN-10 ↔ ISBN-13, typo
 *                   distance. Mirrored byte for byte in assets/live-search.js
 *  - LVS_Index    → the {prefix}lvs_index table, one row per product, built
 *                   only in admin, cron and save hooks (Bible §14.5); the
 *                   queue, the hourly catch-up and the nightly full pass
 *  - LVS_File     → the public index file uploads/live-search/index-<hash>.json
 *                   (the previous file is kept 13 h for cached pages)
 *  - LVS_Search   → the PHP scorer (results page, REST, abilities), the
 *                   same rules as the browser
 *  - LVS_Front    → assets and config, [lvs_search], the results page
 *                   (WooCommerce's own search with our matching), ISBN →
 *                   product, results-page statistics
 *  - LVS_Rest     → lvs/v1/suggest (server mode) and lvs/v1/log (a picked
 *                   suggestion, for statistics), both public (Bible §22)
 *  - LVS_Stats    → search statistics in a private file: phrase, result
 *                   count, time; no IP, user agent or user (Bible §14.5)
 *  - LVS_Abilities→ live-search/test, /stats, /status for Site Bridge
 *  - LVS_Admin_UI → menu «Αναζήτηση», settings, index, statistics, import
 *                   from FiboSearch, JSON backup, footer
 *
 * Front-end rules (Bible §14): nothing changes after activation (mode
 * "off"); test mode shows the suggestions and our results page to shop
 * managers only; LVS_DISABLE in wp-config.php stops every front-end hook
 * and the REST routes; no theme or plugin file is changed; the search
 * forms of the theme are enhanced in the browser, not replaced, so they
 * keep the theme's look (§14.13).
 *
 * Cooperation (Bible §6): reads hic_hidden_terms( 'search' ) (Hide
 * Catalog), pfm_get_work() (Product Formats), por_get_release()
 * (Pre-Orders), tra_is_trade() (Trade Accounts), the nox_creator post type
 * (Featured Creators); listens to noxpress_products_changed and
 * crt_creator_changed; purges the page cache with
 * Noxpress_Core::purge_page_cache() only when visitor texts change.
 * Public API: lvs_normalize( $text, $phonetic = false ).
 *
 * Deviations from the Bible (with reason):
 *  - §14.4 (never in REST): lvs/v1/suggest and lvs/v1/log are the purpose
 *    of the plugin in server mode and for statistics; both are read-only
 *    on products, and /log writes only to the private statistics file.
 *  - §14.5 (no write on a visitor request): when WooCommerce itself
 *    changes a product during a visitor request (stock after an order),
 *    the product id is added to our queue option at shutdown, once per
 *    request. The visitor request is already a write request of
 *    WooCommerce; without it the suggestions would keep a stale stock.
 *    Statistics go to a private file, never to the database (as Redirects).
 *  - §6 «only public APIs»: format labels of Product Formats are read with
 *    PFM_Settings::label() when that method exists (pfm_get_work() gives
 *    only the key); filter lvs_format_label overrides it.
 *  - Hide Catalog: a product is left out when any of its categories is in
 *    hic_hidden_terms( 'search' ); a rule with «only when all categories
 *    are hidden» is applied as «any» in the suggestions (the results page
 *    follows Hide Catalog exactly, through WooCommerce's query).
 */

defined( 'ABSPATH' ) || exit;

define( 'LVS_VERSION', '1.0.1' );
define( 'LVS_FILE', __FILE__ );
define( 'LVS_PATH', plugin_dir_path( __FILE__ ) );
define( 'LVS_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once LVS_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: no order data is read or written.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', LVS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', LVS_FILE, true );
		}
	}
);

require_once LVS_PATH . 'includes/class-lang.php';
require_once LVS_PATH . 'includes/class-text.php';

final class Live_Search {

	const CAP = 'manage_woocommerce';

	public static function init(): void {

		LVS_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'live-search', false, dirname( plugin_basename( LVS_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once LVS_PATH . 'includes/class-settings.php';
		require_once LVS_PATH . 'includes/class-index.php';
		require_once LVS_PATH . 'includes/class-file.php';
		require_once LVS_PATH . 'includes/class-search.php';
		require_once LVS_PATH . 'includes/class-stats.php';
		require_once LVS_PATH . 'includes/class-abilities.php';

		LVS_Index::init();
		LVS_Abilities::init();

		// Emergency switch (Bible §14): no front-end hook and no route.
		if ( ! self::killed() ) {
			require_once LVS_PATH . 'includes/class-front.php';
			require_once LVS_PATH . 'includes/class-rest.php';
			LVS_Front::init();
			LVS_Rest::init();
		}

		if ( is_admin() ) {
			require_once LVS_PATH . 'includes/class-admin-ui.php';
			LVS_Admin_UI::init();
		}
	}

	/** True when LVS_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'LVS_DISABLE' ) && LVS_DISABLE;
	}

	/** Activation: the table, the cron events, the private statistics folder. */
	public static function activate(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		require_once LVS_PATH . 'includes/class-settings.php';
		require_once LVS_PATH . 'includes/class-index.php';
		require_once LVS_PATH . 'includes/class-file.php';
		require_once LVS_PATH . 'includes/class-stats.php';
		LVS_Index::install();
		LVS_Index::schedule();
		LVS_Stats::ensure_dir();
		// The first index right away, in the background (not only tonight).
		LVS_Index::start_full();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'lvs_hourly' );
		wp_clear_scheduled_hook( 'lvs_daily' );
		wp_clear_scheduled_hook( 'lvs_flush' );
		wp_clear_scheduled_hook( 'lvs_full' );
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Live Search χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'live-search' )
			. '</p></div>';
	}
}

/**
 * Normalised text, as the suite's search compares it (public API, Bible §6):
 * lower case, no accents or diaeresis, final sigma as σ, every other
 * character a single space. With $phonetic, the greeklish / phonetic key
 * on top (η, ι, υ, ει, οι → i; ο, ω → o; h, w, y as greeklish writers use
 * them), so «η ειρήνη των κυμάτων» and «h eirini twn kumatwn» meet.
 */
function lvs_normalize( string $text, bool $phonetic = false ): string {
	$n = LVS_Text::norm( $text );
	return $phonetic ? LVS_Text::phon( $n ) : $n;
}

register_activation_hook( __FILE__, array( 'Live_Search', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Live_Search', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'Live_Search', 'init' ), 20 );

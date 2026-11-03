<?php
/**
 * Plugin Name:          Search Signals
 * Plugin URI:           https://noxpress.tech
 * Description:          Τίτλοι και περιγραφές από templates, robots, canonical, ένα γράφημα JSON-LD (Organization, WebSite, WebPage, Product, BreadcrumbList), Open Graph και Twitter, XML sitemaps και llms.txt χωρίς προσωπικά δεδομένα. Εισάγει ρυθμίσεις και meta από το Rank Math, συγκρίνει την έξοδο πριν τη μετάβαση και μένει παθητικό όσο τρέχει άλλο SEO plugin.
 * Version:              1.0.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/search-signals
 * Text Domain:          search-signals
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - search-signals.php → bootstrap only (constants, requires, module
 *                         loading), public noxpress_seo_is_active()
 *  - SSG_Lang      → EN dictionary + gettext filter (rs_lang, Bible §9)
 *  - SSG_Settings  → the ssg_settings option, strict validation, modules
 *  - SSG_Detect    → other SEO plugins (passive while one is active)
 *  - SSG_Context   → what a request (or a URL) is: built from a WP_Query
 *  - SSG_Vars      → %title% %sep% … templates
 *  - SSG_Meta      → title, description, robots, canonical (pure)
 *  - SSG_Schema    → the JSON-LD graph (pure)
 *  - SSG_Social    → Open Graph + Twitter (pure)
 *  - SSG_Head      → module wiring on the front end (wp_head, wp_robots,
 *                    document title), only for the modules that are on
 *  - SSG_Sitemaps  → /sitemap_index.xml, /{type}-sitemap{N}.xml, robots.txt
 *  - SSG_Llms      → /llms.txt
 *  - SSG_Editor    → meta box on posts, fields on terms
 *  - SSG_Import    → Rank Math settings and meta: dry run, import, undo
 *  - SSG_Compare   → live page (other SEO plugin) vs our output, sitemaps
 *  - SSG_Switch    → «Switch over» / «Switch back» in one request
 *  - SSG_Admin_UI  → menu, tabs, forms, footer
 *  - SSG_Abilities → search-signals/status, get-meta, set-meta, json-ld,
 *                    sitemap-status, compare
 *
 * Modules (Bible §20, all off after activation; a module that is off is
 * never required): meta, schema, social, sitemaps, llms. Social needs
 * meta (it reuses the title and the description).
 *
 * Front-end rules (Bible §14):
 *  - §14.1 / §14.2: test mode first: the tags, sitemaps and llms.txt are
 *    there only for users with manage_options (on pages that are never
 *    cached). «Live» = every visitor.
 *  - Passive: while Rank Math, Yoast, SEOPress, AIOSEO or The SEO
 *    Framework is active, nothing is printed or served, in any mode
 *    (never two SEO layers). Import and Compare still work.
 *  - §14.3: SSG_DISABLE in wp-config.php stops every front-end hook (the
 *    core sitemaps of WordPress stay off, so wp-sitemap.xml never shows
 *    up by surprise).
 *  - §14.4: never admin, AJAX, REST, feeds, embeds, cron, CLI or XML-RPC.
 *    §14.5: no write on a visitor request (sitemaps and llms.txt are
 *    cached in the object cache only, keyed by the last_changed values
 *    of WordPress, so a save refreshes them without a hook of ours).
 *    §14.6: every callback in try/catch(\Throwable).
 *  - No external request, no cookie. The only HTTP request is Compare,
 *    after a click: a GET to this site's own home_url (loopback).
 *
 * Cooperation (Bible §6, suite contract §14.38): filters
 * noxpress_seo_graph, noxpress_seo_title, noxpress_seo_description,
 * noxpress_seo_robots, noxpress_seo_canonical, noxpress_seo_sitemap_entry,
 * noxpress_seo_llms_sections; action noxpress_seo_active; function
 * noxpress_seo_is_active(). Robots go through wp_robots, the Product node
 * is built by WooCommerce (woocommerce_structured_data_product / _offer),
 * sitemap queries pass the core wp_sitemaps_* filters: plugins that
 * already use those (Hide Catalog, Pre-Orders, Featured Creators) work
 * without a change.
 */

defined( 'ABSPATH' ) || exit;

define( 'SSG_VERSION', '1.0.0' );
define( 'SSG_FILE', __FILE__ );
define( 'SSG_PATH', plugin_dir_path( __FILE__ ) );
define( 'SSG_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once SSG_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: no order or cart data is read or written.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SSG_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SSG_FILE, true );
		}
	}
);

require_once SSG_PATH . 'includes/class-lang.php';
require_once SSG_PATH . 'includes/class-settings.php';
require_once SSG_PATH . 'includes/class-detect.php';
require_once SSG_PATH . 'includes/class-abilities.php';

final class Search_Signals {

	const CAP = 'manage_options';

	/** @var bool Set after an error: the plugin stops for the rest of the request (§14.6). */
	private static $broken = false;

	/** @var bool|null Per-request cache of serving(). */
	private static $serving = null;

	public static function init(): void {
		SSG_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'search-signals', false, dirname( plugin_basename( SSG_FILE ) ) . '/languages' );
			}
		);

		// The core sitemaps stay off whenever our sitemaps module is on,
		// also when the plugin is passive or killed: another SEO plugin
		// turns them off by itself, and a sudden wp-sitemap.xml is never
		// what the owner wants.
		if ( SSG_Settings::module( 'sitemaps' ) ) {
			add_filter( 'wp_sitemaps_enabled', '__return_false', 20 );
		}

		// Pure parts, needed by the front end, the editor preview, Compare
		// and the abilities.
		if ( SSG_Settings::any_module() || is_admin() || self::is_rest() ) {
			self::load_core();
		}

		if ( ! self::killed() && ! is_admin() ) {
			if ( SSG_Settings::module( 'meta' ) || SSG_Settings::module( 'schema' ) || SSG_Settings::module( 'social' ) ) {
				require_once SSG_PATH . 'includes/class-head.php';
				SSG_Head::init();
			}
			if ( SSG_Settings::module( 'sitemaps' ) ) {
				require_once SSG_PATH . 'includes/class-sitemaps.php';
				SSG_Sitemaps::init();
			}
			if ( SSG_Settings::module( 'llms' ) ) {
				require_once SSG_PATH . 'includes/class-llms.php';
				SSG_Llms::init();
			}
		}

		if ( SSG_Settings::module( 'meta' ) ) {
			require_once SSG_PATH . 'includes/class-editor.php';
			SSG_Editor::init();
		}

		SSG_Abilities::init();

		if ( is_admin() ) {
			require_once SSG_PATH . 'includes/class-import.php';
			require_once SSG_PATH . 'includes/class-compare.php';
			require_once SSG_PATH . 'includes/class-switch.php';
			require_once SSG_PATH . 'includes/class-admin-ui.php';
			SSG_Admin_UI::init();
		}
	}

	/** The pure classes (no hook of their own). */
	public static function load_core(): void {
		require_once SSG_PATH . 'includes/class-context.php';
		require_once SSG_PATH . 'includes/class-vars.php';
		require_once SSG_PATH . 'includes/class-meta.php';
		require_once SSG_PATH . 'includes/class-schema.php';
		require_once SSG_PATH . 'includes/class-social.php';
	}

	/** The admin-only tools, for the abilities (REST requests). */
	public static function load_tools(): void {
		self::load_core();
		require_once SSG_PATH . 'includes/class-compare.php';
		if ( ! class_exists( 'SSG_Sitemaps', false ) ) {
			require_once SSG_PATH . 'includes/class-sitemaps.php';
		}
	}

	/** True when SSG_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'SSG_DISABLE' ) && SSG_DISABLE;
	}

	private static function is_rest(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || false !== strpos( $uri, '/' . rest_get_url_prefix() . '/' ) || false !== strpos( $uri, 'rest_route=' );
	}

	/**
	 * Whether this front-end request gets our output: not killed, no other
	 * SEO plugin, live for everyone or test for managers. Call it only
	 * after set_current_user (template_redirect, wp_head, parse_request).
	 */
	public static function serving(): bool {
		if ( null !== self::$serving ) {
			return self::$serving;
		}
		$on = false;
		try {
			if ( ! self::$broken && ! self::killed() && '' === SSG_Detect::other() && self::front_request() ) {
				$mode = SSG_Settings::get()['mode'];
				if ( 'live' === $mode ) {
					$on = true;
				} elseif ( 'test' === $mode && is_user_logged_in() && current_user_can( self::CAP ) ) {
					$on = true;
					Noxpress_Core::no_page_cache();
				}
			}
		} catch ( \Throwable $e ) {
			$on = false;
		}
		if ( did_action( 'set_current_user' ) ) {
			self::$serving = $on;
		}
		return $on;
	}

	/** A normal page view: not admin, AJAX, REST, feed, embed, cron, CLI or XML-RPC (§14.4). */
	public static function front_request(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || self::is_rest() ) {
			return false;
		}
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}
		return true;
	}

	/** Stops the plugin for the rest of the request after an error (§14.6). */
	public static function fail( \Throwable $e ): void {
		self::$broken  = true;
		self::$serving = false;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Search Signals: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	public static function broken(): bool {
		return self::$broken;
	}

	/** Tests only: forget the per-request cache. */
	public static function reset(): void {
		self::$serving = null;
	}
}

/**
 * Whether Search Signals prints the SEO tags of this request (Bible §6).
 * For plugins that would otherwise print their own canonical or JSON-LD.
 */
function noxpress_seo_is_active(): bool {
	try {
		return class_exists( 'Search_Signals', false ) && SSG_Settings::any_module() && Search_Signals::serving();
	} catch ( \Throwable $e ) {
		return false;
	}
}

add_action( 'plugins_loaded', array( 'Search_Signals', 'init' ), 20 );

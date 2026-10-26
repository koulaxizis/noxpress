<?php
/**
 * Plugin Name:          Fast Pages
 * Plugin URI:           https://noxpress.tech
 * Description:          Page cache για επισκέπτες με έξυπνο καθάρισμα και preload από το sitemap, minify CSS / JS, lazy load εικόνων, iframes και backgrounds, YouTube με κλικ, διαστάσεις εικόνων και preload στο hover. Ο Claude το χειρίζεται μέσω Site Bridge. Χωρίς λογαριασμό και χωρίς εξωτερική υπηρεσία.
 * Version:              1.0.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/fast-pages
 * Text Domain:          fast-pages
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - fast-pages.php    → bootstrap only (constants, requires, init wiring)
 *  - FPG_Lang          → EN dictionary + gettext filter (rs_lang, Bible §9)
 *  - FPG_Settings      → the fpg_settings option, strict validation
 *  - FPG_Key           → request / URL → cache file, plain PHP (shared with
 *                        the drop-in)
 *  - includes/serve.php → the drop-in's answer before WordPress loads
 *  - FPG_Files         → the cache folder: write, delete, count
 *  - FPG_Setup         → advanced-cache.php, WP_CACHE in wp-config.php,
 *                        the drop-in config, other page caches
 *  - FPG_Cache         → stores the page at the end of a visitor's view
 *                        (wrapper template, includes/template.php)
 *  - FPG_Optimize      → minify, lazy load, dimensions, YouTube, on the
 *                        stored HTML only; FPG_Vendor loads the minifier
 *  - FPG_Purge         → what to delete when content changes; the suite
 *                        hook noxpress_purge_page_cache
 *  - FPG_Preload       → background preload (sitemap), hourly upkeep
 *  - FPG_Front         → hover preload (speculative loading), admin bar
 *  - FPG_Tools         → import from WP Rocket, database cleanup
 *  - FPG_Abilities     → fast-pages/* abilities (Site Bridge, WP 6.9+)
 *  - FPG_Admin_UI      → menu, settings page, checks, footer
 *
 * Deviations from the Bible (with reason):
 *  - §1.2 (WooCommerce required): a WordPress plugin; a page cache helps
 *    every site. The WooCommerce rules (cart, checkout, account, stock)
 *    load only when WooCommerce is there. Capability manage_options.
 *  - §14.1 / §14.8 (nothing changes after activation, no file outside the
 *    plugin): the page cache needs wp-content/advanced-cache.php and
 *    WP_CACHE in wp-config.php. Both are written only with the "Turn on"
 *    button (after a confirmation), never over another plugin's drop-in,
 *    and removed on deactivation (§14.34). Optimizations are off until set.
 *  - §12 (files under uploads/noxpress-<name>/): the cache lives in
 *    wp-content/cache/fast-pages/, where the drop-in finds it before
 *    WordPress loads (no wp_upload_dir() yet). Names are md5 hashes,
 *    every delete goes through the patterns of FPG_Files.
 *  - §14.5 (no writes from a visitor's request): the purpose is to write
 *    cache files at the end of a visitor's page view, and to delete them
 *    when the visitor's order changes stock. Never a database write.
 *  - §11 (state changes by POST): the admin bar purge links are nonce
 *    links to admin-post.php (capability checked), like WordPress's own
 *    row actions, so they work from any page.
 *
 * Front end (Bible §14): FPG_DISABLE in wp-config.php stops the drop-in,
 * the writing of pages, the optimizations and the hover setting; the site
 * then works as without the plugin. Test mode (on by default): cached
 * pages answer only ?fpg-test=1.
 */

defined( 'ABSPATH' ) || exit;

define( 'FPG_VERSION', '1.0.0' );
define( 'FPG_FILE', __FILE__ );
define( 'FPG_PATH', plugin_dir_path( __FILE__ ) );
define( 'FPG_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once FPG_PATH . 'includes/noxpress-core/loader.php';

require_once FPG_PATH . 'includes/class-lang.php';
require_once FPG_PATH . 'includes/class-key.php';
require_once FPG_PATH . 'includes/class-settings.php';
require_once FPG_PATH . 'includes/class-files.php';
require_once FPG_PATH . 'includes/class-setup.php';
require_once FPG_PATH . 'includes/class-vendor.php';
require_once FPG_PATH . 'includes/class-optimize.php';
require_once FPG_PATH . 'includes/class-cache.php';
require_once FPG_PATH . 'includes/class-purge.php';
require_once FPG_PATH . 'includes/class-preload.php';
require_once FPG_PATH . 'includes/class-front.php';
require_once FPG_PATH . 'includes/class-tools.php';
require_once FPG_PATH . 'includes/class-abilities.php';

final class Fast_Pages {

	const CAP = 'manage_options';

	public static function init(): void {
		FPG_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'fast-pages', false, dirname( plugin_basename( FPG_FILE ) ) . '/languages' );
			}
		);

		// Purges and the preload run even with FPG_DISABLE: they only delete
		// our files and request pages, so the cache never goes stale.
		FPG_Purge::init();
		FPG_Preload::init();
		FPG_Abilities::init();
		if ( ! self::killed() ) {
			FPG_Cache::init();
			FPG_Front::init();
		}

		// The drop-in config follows the settings it is built from.
		foreach ( array( 'home', 'siteurl', 'blog_charset', 'page_on_front', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id', 'permalink_structure', 'active_plugins' ) as $opt ) {
			add_action( 'update_option_' . $opt, array( __CLASS__, 'refresh_config' ) );
		}
		add_action( 'admin_init', array( 'FPG_Setup', 'maybe_refresh' ) );

		if ( is_admin() ) {
			require_once FPG_PATH . 'includes/class-admin-ui.php';
			FPG_Admin_UI::init();
		}
	}

	/** True when FPG_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'FPG_DISABLE' ) && FPG_DISABLE;
	}

	public static function refresh_config(): void {
		if ( FPG_Settings::get()['enabled'] || is_file( FPG_Files::dir() . '/config.php' ) ) {
			FPG_Setup::write_config();
		}
	}

	public static function activate(): void {
		// Nothing changes until "Turn on" (Bible §14.1).
	}

	/** Removes the drop-in, our WP_CACHE line and the cached pages. */
	public static function deactivate(): void {
		FPG_Setup::disable();
		wp_clear_scheduled_hook( FPG_Preload::UPKEEP );
		if ( is_file( FPG_Files::dir() . '/config.php' ) ) {
			@unlink( FPG_Files::dir() . '/config.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}
}

register_activation_hook( __FILE__, array( 'Fast_Pages', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Fast_Pages', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Fast_Pages', 'init' ), 20 );

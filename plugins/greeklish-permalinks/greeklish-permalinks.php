<?php
/**
 * Plugin Name:          Greeklish Permalinks
 * Plugin URI:           https://noxpress.tech
 * Description:          Ελληνικά σε λατινικά στα νέα slugs (άρθρα, σελίδες, προϊόντα, κατηγορίες, ετικέτες, χρήστες) και, αν το θέλεις, στα ονόματα των αρχείων που ανεβαίνουν. Απλός ή φωνητικός τρόπος, μονοτονικό και πολυτονικό, δικοί σου κανόνες, ζωντανή δοκιμή, 301 για ελληνικά links, και μαζική μετατροπή των υπαρχόντων με δοκιμή, ανακατευθύνσεις και αναίρεση. Δουλεύει με ή χωρίς WooCommerce.
 * Version:              1.2.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/greeklish-permalinks
 * Text Domain:          greeklish-permalinks
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - greeklish-permalinks.php → bootstrap only (constants, requires, init
 *                    wiring) and the public glp_convert()
 *  - GLP_Lang      → EN dictionary + gettext filter (rs_lang, Bible §9)
 *  - GLP_Converter → Greek → Latin (simple / phonetic, monotonic,
 *                    polytonic, combining marks, rules, stop words)
 *  - GLP_Settings  → the glp_settings option, strict validation, first run
 *  - GLP_Hooks     → new slugs (sanitize_title, 'save' context), new file
 *                    names (sanitize_file_name), WooCommerce attribute guard
 *  - GLP_Redirects → 301 on a 404: bulk redirect table, Greek addresses
 *  - GLP_Bulk      → on-demand conversion of existing slugs and files,
 *                    dry run, undo log
 *  - GLP_Admin_UI  → menu, settings, live test, bulk page, tools, footer
 *
 * Deviations from the Bible (with reason):
 *  - §1.2 (WooCommerce required): a WordPress plugin, it works with or
 *    without WooCommerce (no "Requires Plugins"); with WooCommerce it also
 *    handles products, product terms, attributes and downloadable files
 *    (Bible 1.20 writes the exception).
 *  - §8 (capability manage_woocommerce): manage_options, a setting of the
 *    whole site.
 *  - §14.1 (no effect after activation): converting new slugs is the
 *    plugin's purpose and starts on activation; existing slugs and files
 *    never change without the bulk page. File names are off by default.
 *  - §1.1 (no reading of other plugins' options): the first activation
 *    reads agp_diphthongs of AutoConvert Greeklish Permalinks once, to keep
 *    the same slug style; it never writes it.
 *
 * Front end (Bible §14): only GLP_Redirects, on requests WordPress already
 * answers with a 404; read-only; GLP_DISABLE in wp-config.php stops it and
 * the automatic conversions.
 *
 * Cooperation (Bible §6): fires noxpress_products_changed after the bulk
 * page changes products. Function and filter glp_convert for others.
 */

defined( 'ABSPATH' ) || exit;

define( 'GLP_VERSION', '1.2.0' );
define( 'GLP_FILE', __FILE__ );
define( 'GLP_PATH', plugin_dir_path( __FILE__ ) );
define( 'GLP_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once GLP_PATH . 'includes/noxpress-core/loader.php';

require_once GLP_PATH . 'includes/class-lang.php';
require_once GLP_PATH . 'includes/class-converter.php';
require_once GLP_PATH . 'includes/class-settings.php';

/**
 * Greek → Latin with the site's settings.
 *
 * @param string $text    Any text.
 * @param string $context 'slug' (rules, stop words, lower case: run it
 *                        through sanitize_title() for a real slug), 'file'
 *                        (a file name; extension kept) or 'plain'.
 */
function glp_convert( string $text, string $context = 'plain' ): string {
	if ( 'file' === $context ) {
		$out = GLP_Converter::file_name( $text, GLP_Settings::file_opt() );
	} elseif ( 'slug' === $context ) {
		$out = GLP_Converter::convert( $text, GLP_Settings::slug_opt() );
	} else {
		$opt         = GLP_Settings::slug_opt();
		$opt['stop'] = false;
		$opt['lower'] = false;
		$out         = GLP_Converter::convert( $text, $opt );
	}
	/**
	 * Filters a conversion.
	 *
	 * @param string $out     Latin text.
	 * @param string $text    Original text.
	 * @param string $context 'slug' | 'file' | 'plain'.
	 */
	return (string) apply_filters( 'glp_convert', $out, $text, $context );
}

final class Greeklish_Permalinks {

	public static function init(): void {
		GLP_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'greeklish-permalinks', false, dirname( plugin_basename( GLP_FILE ) ) . '/languages' );
			}
		);

		require_once GLP_PATH . 'includes/class-redirects.php';

		if ( ! self::killed() ) {
			require_once GLP_PATH . 'includes/class-hooks.php';
			GLP_Hooks::init();
			GLP_Redirects::init();
		}

		if ( is_admin() ) {
			require_once GLP_PATH . 'includes/class-bulk.php';
			require_once GLP_PATH . 'includes/class-admin-ui.php';
			GLP_Admin_UI::init();
		}
	}

	/** True when GLP_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'GLP_DISABLE' ) && GLP_DISABLE;
	}

	public static function activate(): void {
		GLP_Settings::first_run();
	}
}

register_activation_hook( __FILE__, array( 'Greeklish_Permalinks', 'activate' ) );

// Priority 20 like the other suite plugins; slugs built earlier in the
// request (rare) are not converted.
add_action( 'plugins_loaded', array( 'Greeklish_Permalinks', 'init' ), 20 );

<?php
/**
 * Plugin Name:          Publishing Quote
 * Plugin URI:           https://noxpress.tech
 * Description:          Κοστολόγηση έκδοσης για υποψήφιους συγγραφείς: ο επισκέπτης διαλέγει μέγεθος, επιμέλεια, εξώφυλλο, εικονογράφηση, εκτύπωση και έξτρα παροχές, και βλέπει μόνο το ποσό ανά υπηρεσία και ένα σύνολο. Οι συντελεστές μένουν στον server. Με το «Ενδιαφέρομαι» αφήνει τα στοιχεία του και το αίτημα έρχεται σε σένα. Δουλεύει με ή χωρίς WooCommerce.
 * Version:              1.0.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/publishing-quote
 * Text Domain:          publishing-quote
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * The calculation runs on the server only (Bible §14.26): the page holds
 * the option labels and nothing else, the browser sends the choices and
 * gets back the rounded amount of each service and the total. Rates, the
 * formula, the tiers and the hidden typesetting fee never leave the server.
 *
 * Dispatch map:
 *  - publishing-quote.php → bootstrap only (constants, requires, init wiring)
 *  - PBQ_Lang      → EN dictionary + gettext filter (admin: rs_lang)
 *  - PBQ_Settings  → the pbq_settings option, strict validation, templates,
 *                    visitor texts (EL/EN), money format, test / live / off
 *  - PBQ_Calc      → the quote: input whitelist, formula, typesetting share,
 *                    minimums, VAT, rounding, public view / breakdown
 *  - PBQ_Requests  → post type pbq_request, the «I'm interested» submission
 *                    (limits, duplicates), e-mails, states, retention,
 *                    privacy exporter / eraser, public API (functions.php)
 *  - PBQ_Front     → shortcode, block, widget, REST routes, no-JS fallback
 *  - PBQ_Admin_UI  → menu, settings tabs, test bench, requests, tools, footer
 *
 * Deviations from the Bible (with reason):
 *  - §1.2 (WooCommerce required): a calculator for any WordPress site (no
 *    "Requires Plugins"); with WooCommerce it only takes the currency and
 *    the number format from the shop (Bible 1.20 exception).
 *  - §8 (capability manage_woocommerce): manage_woocommerce with
 *    WooCommerce, manage_options without it.
 *  - §14.4 (no REST): the quote and the request routes are the purpose of
 *    the plugin (pbq/v1/quote, pbq/v1/request), as Book Preview's viewer.
 *  - §14.5 (no database write on a visitor request): only the explicit
 *    «Send» of the contact form writes (the request, and the per-IP limit
 *    as a hashed transient), as Easy Withdrawal (§14.15). A quote writes
 *    nothing.
 *  - §12 (nothing left after uninstall): the requests are the business's
 *    record (§12, «data that is the shop's record»): they stay unless
 *    «Delete requests on uninstall» is ticked. Settings, transients and the
 *    cleanup event go.
 *
 * Front end (Bible §14): test mode by default (only managers see the
 * calculator); PBQ_DISABLE in wp-config.php stops the calculator, the REST
 * routes and the contact form.
 *
 * Cooperation (Bible §6): action pbq_request_created( $id, $request ) and
 * pbq_request_state( $id, $state, $old ); functions pbq_get_request(),
 * pbq_get_requests(), pbq_set_request_state(); filter pbq_text.
 */

defined( 'ABSPATH' ) || exit;

define( 'PBQ_VERSION', '1.0.0' );
define( 'PBQ_FILE', __FILE__ );
define( 'PBQ_PATH', plugin_dir_path( __FILE__ ) );
define( 'PBQ_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once PBQ_PATH . 'includes/noxpress-core/loader.php';

require_once PBQ_PATH . 'includes/class-lang.php';
require_once PBQ_PATH . 'includes/class-settings.php';
require_once PBQ_PATH . 'includes/class-calc.php';
require_once PBQ_PATH . 'includes/class-requests.php';
require_once PBQ_PATH . 'includes/functions.php';

final class Publishing_Quote {

	public static function init(): void {
		PBQ_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'publishing-quote', false, dirname( plugin_basename( PBQ_FILE ) ) . '/languages' );
			}
		);

		PBQ_Requests::init();

		require_once PBQ_PATH . 'includes/class-front.php';
		PBQ_Front::init();

		if ( is_admin() ) {
			require_once PBQ_PATH . 'includes/class-admin-ui.php';
			PBQ_Admin_UI::init();
		}
	}

	/** True when PBQ_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'PBQ_DISABLE' ) && PBQ_DISABLE;
	}

	/** True when WooCommerce is active. */
	public static function woo(): bool {
		return class_exists( 'WooCommerce' );
	}

	/** Capability for the settings: shop managers with WooCommerce, admins without it. */
	public static function cap(): string {
		return self::woo() ? 'manage_woocommerce' : 'manage_options';
	}
}

register_activation_hook( __FILE__, array( 'PBQ_Requests', 'schedule' ) );
register_deactivation_hook( __FILE__, array( 'PBQ_Requests', 'unschedule' ) );

// Priority 20 like the other suite plugins.
add_action( 'plugins_loaded', array( 'Publishing_Quote', 'init' ), 20 );

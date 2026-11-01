<?php
/**
 * Plugin Name:          Redirects
 * Plugin URI:           https://noxpress.tech
 * Description:          Ανακατευθύνσεις 301 / 302 / 307 και 410 για παλιές διευθύνσεις, μόνο εκεί που το site απαντά 404, και καταγραφή των 404 σε ιδιωτικό αρχείο (όχι στη βάση), χωρίς IP και χωρίς τα bots. Προτείνει στόχο για κάθε σπασμένο link, εισάγει κανόνες από Rank Math, Redirection, Yoast Premium, Simple 301 Redirects και CSV, και συνεργάζεται με το Greeklish Permalinks.
 * Version:              1.0.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/redirects
 * Text Domain:          redirects
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - redirects.php   → bootstrap only (constants, requires, init wiring),
 *                      public rdr_add_redirect()
 *  - RDR_Lang        → EN dictionary + gettext filter (rs_lang, Bible §9)
 *  - RDR_Settings    → the rdr_settings option, strict validation
 *  - RDR_Path        → path normalisation shared by rules, log and admin
 *  - RDR_Rules       → table {prefix}rdr_rules, validation (loops, chains,
 *                      protected paths, regex), lookup, target URLs
 *  - RDR_Log         → private folder, 404 / hit log files, limits,
 *                      retention, HTTP self-test, report
 *  - RDR_Front       → template_redirect: rules on 404 (priority 9), the
 *                      log of what stayed 404 (last priority)
 *  - RDR_Suggest     → target suggestions for a 404 path (admin only)
 *  - RDR_Import      → Rank Math, Redirection, Yoast Premium, Simple 301
 *                      Redirects and CSV (scan → preview → apply), CSV and
 *                      JSON export, JSON backup
 *  - RDR_Watch       → trashed posts and changed term addresses become
 *                      suggestions on the admin page (never rules by
 *                      themselves)
 *  - RDR_Admin_UI    → menu, tabs (rules, 404, import / export, settings),
 *                      footer
 *  - RDR_Abilities   → redirects/list, top-404, test-url, add
 *
 * Front-end rules (Bible §14):
 *  - §14.1 / §14.2: the first activation is test mode: rules answer only
 *    users with manage_options until «Live» is chosen. The 404 log runs
 *    from the start (it changes nothing a visitor sees).
 *  - Rules run only on requests WordPress already answers with 404, so a
 *    page that exists is never hidden by a rule and a normal page view
 *    makes no query for redirects.
 *  - §14.3: RDR_DISABLE in wp-config.php stops every front-end hook.
 *  - §14.4: GET / HEAD only, never admin, AJAX, REST, feeds, embeds, cron,
 *    CLI or XML-RPC. §14.6: every callback in try/catch(\Throwable).
 *
 * Deviation from the Bible (with reason):
 *  - §14.5 (no write on a visitor request): a 404 or a redirect appends
 *    one line to a private log file (never the database): that is the
 *    purpose of the log. Limits: 60 lines a minute, 5 MB a month, 256 MB
 *    free disk; no IP, no user agent, no query string; known scanner paths
 *    are not written at all. Hits per rule are counted from the same file,
 *    so no database row changes on a visitor request.
 *
 * Cooperation (Bible §6): rdr_add_redirect() for other plugins, filter
 * rdr_redirect_target. Reads Greeklish Permalinks (glp_convert(),
 * GLP_Redirects::map()) for suggestions, when it is there. Fires
 * noxpress_purge_page_cache for the source of a rule made from a trashed
 * post. Reads (never changes) the tables of the plugins it imports from.
 */

defined( 'ABSPATH' ) || exit;

define( 'RDR_VERSION', '1.0.0' );
define( 'RDR_FILE', __FILE__ );
define( 'RDR_PATH', plugin_dir_path( __FILE__ ) );
define( 'RDR_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once RDR_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: no order or cart data is read or written.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', RDR_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', RDR_FILE, true );
		}
	}
);

require_once RDR_PATH . 'includes/class-lang.php';
require_once RDR_PATH . 'includes/class-settings.php';
require_once RDR_PATH . 'includes/class-path.php';
require_once RDR_PATH . 'includes/class-rules.php';
require_once RDR_PATH . 'includes/class-log.php';
require_once RDR_PATH . 'includes/class-watch.php';
require_once RDR_PATH . 'includes/class-abilities.php';

final class Redirects {

	const CAP  = 'manage_options';
	const CRON = 'rdr_daily';

	public static function init(): void {
		RDR_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'redirects', false, dirname( plugin_basename( RDR_FILE ) ) . '/languages' );
			}
		);

		// Emergency switch (Bible §14.3): no front-end hook at all.
		if ( ! self::killed() && ! is_admin() ) {
			require_once RDR_PATH . 'includes/class-front.php';
			RDR_Front::init();
		}

		RDR_Watch::init();
		RDR_Abilities::init();
		add_action( self::CRON, array( 'RDR_Log', 'cleanup' ) );

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'upkeep' ) );
			require_once RDR_PATH . 'includes/class-suggest.php';
			require_once RDR_PATH . 'includes/class-import.php';
			require_once RDR_PATH . 'includes/class-admin-ui.php';
			RDR_Admin_UI::init();
		}
	}

	/** True when RDR_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'RDR_DISABLE' ) && RDR_DISABLE;
	}

	/** Table, log folder and daily clean-up exist (admin requests only). */
	public static function upkeep(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		RDR_Rules::install();
		RDR_Log::ensure_dir();
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function activate(): void {
		RDR_Rules::install();
		RDR_Log::ensure_dir();
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON );
	}
}

/**
 * Adds a redirect, for other plugins (Bible §6). The same checks as the
 * admin page: a loop, a protected path or an invalid target is refused.
 * A new rule from here is live at once (not staged): the caller knows the
 * old address is gone.
 *
 * @param string $from   Old path or URL of this site.
 * @param string $to     New path, URL, or '' with $code 410.
 * @param int    $code   301 | 302 | 307 | 410.
 * @param string $source Who adds it (shown in the note), e.g. 'greeklish-permalinks'.
 * @return int Rule id, or 0 when it was refused or already exists.
 */
function rdr_add_redirect( string $from, string $to, int $code = 301, string $source = '' ): int {
	try {
		$r = RDR_Rules::save(
			array(
				'src'    => $from,
				'match'  => 'exact',
				'target' => $to,
				'code'   => $code,
				'staged' => false,
				'note'   => '' !== $source ? sanitize_key( $source ) : 'api',
			)
		);
		return is_wp_error( $r ) ? 0 : (int) $r['id'];
	} catch ( \Throwable $e ) {
		return 0;
	}
}

register_activation_hook( __FILE__, array( 'Redirects', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Redirects', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Redirects', 'init' ), 20 );

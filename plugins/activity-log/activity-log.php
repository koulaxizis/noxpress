<?php
/**
 * Plugin Name:          Activity Log
 * Plugin URI:           https://noxpress.tech
 * Description:          Ποιος άλλαξε τι και πότε: προϊόντα, τιμές, απόθεμα, παραγγελίες, κουπόνια, σελίδες, χρήστες και συνδέσεις, plugins, θέματα και ρυθμίσεις, με την πηγή κάθε αλλαγής (admin, REST, WP-CLI, cron, Site Bridge). Χωρίς μυστικά και χωρίς στοιχεία πελατών, IP με μάσκα, αυτόματη διαγραφή μετά από 90 μέρες και email για τις κρίσιμες αλλαγές.
 * Version:              1.0.1
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/activity-log
 * Text Domain:          activity-log
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - activity-log.php → bootstrap only (constants, requires, init wiring),
 *                       public acl_log()
 *  - ACL_Lang         → EN dictionary + gettext filter (rs_lang, Bible §9)
 *  - ACL_Settings     → the acl_settings option, strict validation
 *  - ACL_Context      → who (actor), from where (context), client IP and
 *                       its mask
 *  - ACL_Redact       → secrets and personal data out of every value
 *  - ACL_Store        → table {prefix}acl_events, the per-request buffer
 *                       (one INSERT at shutdown), queries, daily purge
 *  - ACL_Watch        → WordPress hooks: content, users, plugins, themes,
 *                       core, settings, media
 *  - ACL_Watch_WC     → WooCommerce hooks: products, orders, coupons
 *                       (only when WooCommerce is active)
 *  - ACL_Watch_Suite  → actions of other Noxpress plugins and Login Guard
 *  - ACL_Notify       → e-mail for critical events, one summary at most
 *                       every 15 minutes
 *  - ACL_Privacy      → WordPress personal data exporter and eraser,
 *                       privacy policy text
 *  - ACL_Abilities    → activity-log/list (read only, Abilities API)
 *  - ACL_Admin_UI     → menu, history table, CSV, settings, the «History»
 *                       box on products and orders, dashboard widget
 *
 * What it never stores: passwords, keys, tokens or any value whose name
 * looks secret; names, e-mails, phones or addresses of customers (orders
 * and customers are numbers); the text typed into a failed login.
 *
 * Writes happen only when something changes. A visitor request writes a
 * row only for an event of the site (e.g. an order changed status), never
 * for a page view. ACL_DISABLE in wp-config.php stops every hook.
 *
 * Cooperation (Bible §6): acl_log() for other plugins, filters
 * acl_watched_options, acl_skip_event, acl_categories. Listens to the
 * actions of Noxpress plugins (crt_creator_changed, tra_consignment_*,
 * noxpress_withdrawal_*, pl_entries_changed, por_released, …), to Login
 * Guard (lgd_*) and to Site Bridge (sbr_call_start / sbr_call_end, so a
 * change made by Claude through Site Bridge says so).
 */

defined( 'ABSPATH' ) || exit;

define( 'ACL_VERSION', '1.0.1' );
define( 'ACL_FILE', __FILE__ );
define( 'ACL_PATH', plugin_dir_path( __FILE__ ) );
define( 'ACL_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once ACL_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: orders are read through the CRUD API only.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ACL_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', ACL_FILE, true );
		}
	}
);

require_once ACL_PATH . 'includes/class-lang.php';
require_once ACL_PATH . 'includes/class-settings.php';
require_once ACL_PATH . 'includes/class-context.php';
require_once ACL_PATH . 'includes/class-redact.php';
require_once ACL_PATH . 'includes/class-store.php';
require_once ACL_PATH . 'includes/class-events.php';
require_once ACL_PATH . 'includes/class-watch.php';
require_once ACL_PATH . 'includes/class-watch-wc.php';
require_once ACL_PATH . 'includes/class-watch-suite.php';
require_once ACL_PATH . 'includes/class-notify.php';
require_once ACL_PATH . 'includes/class-privacy.php';
require_once ACL_PATH . 'includes/class-abilities.php';

final class Activity_Log {

	const CAP  = 'manage_options';
	const CRON = 'acl_daily';

	/** Daily cron: retention (days, then the row limit). */
	public static function daily(): void {
		ACL_Store::purge();
	}

	public static function init(): void {
		ACL_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'activity-log', false, dirname( plugin_basename( ACL_FILE ) ) . '/languages' );
			}
		);

		add_action( self::CRON, array( __CLASS__, 'daily' ) );
		ACL_Notify::init();
		ACL_Privacy::init();
		ACL_Abilities::init();

		// Emergency switch: nothing is recorded, the page still opens.
		if ( ! self::killed() ) {
			ACL_Context::init();
			ACL_Store::init();
			ACL_Watch::init();
			ACL_Watch_WC::init();
			ACL_Watch_Suite::init();
		}

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'upkeep' ) );
			require_once ACL_PATH . 'includes/class-admin-ui.php';
			ACL_Admin_UI::init();
		}
	}

	/** True when ACL_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'ACL_DISABLE' ) && ACL_DISABLE;
	}

	/** Table and daily purge exist (admin requests only). */
	public static function upkeep(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		ACL_Store::install();
		self::schedule();
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function activate(): void {
		ACL_Store::install();
		self::schedule();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON );
		wp_clear_scheduled_hook( ACL_Notify::CRON );
	}
}

/**
 * Records an event, for other plugins (Bible §6). Safe to call before or
 * without the plugin: check function_exists( 'acl_log' ) first.
 *
 * @param string $action Short machine name, e.g. 'mail.failed' or 'rs.payout'.
 * @param array  $args   object_type (string), object_id (int), label
 *                       (string, shown in the list), details (array of
 *                       field => array( old, new ) or field => text),
 *                       severity ('info' | 'notice' | 'warning' |
 *                       'critical'), category (default 'suite').
 */
function acl_log( string $action, array $args = array() ): void {
	try {
		if ( Activity_Log::killed() ) {
			return;
		}
		$cat = isset( $args['category'] ) ? sanitize_key( (string) $args['category'] ) : 'suite';
		ACL_Store::add(
			array(
				'category'    => '' !== $cat ? $cat : 'suite',
				'action'      => $action,
				'object_type' => isset( $args['object_type'] ) ? (string) $args['object_type'] : '',
				'object_id'   => isset( $args['object_id'] ) ? (int) $args['object_id'] : 0,
				'label'       => isset( $args['label'] ) ? (string) $args['label'] : '',
				'details'     => isset( $args['details'] ) && is_array( $args['details'] ) ? $args['details'] : array(),
				'severity'    => isset( $args['severity'] ) ? (string) $args['severity'] : 'info',
			)
		);
	} catch ( \Throwable $e ) {
		return;
	}
}

register_activation_hook( __FILE__, array( 'Activity_Log', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Activity_Log', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Activity_Log', 'init' ), 20 );

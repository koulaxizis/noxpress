<?php
/**
 * Plugin Name:          Live Operator
 * Plugin URI:           https://noxpress.tech
 * Description:          Το live chat του PurioChat Pro στο κινητό: διακόπτης online / offline που μένει στον server (με αυτόματο offline), ειδοποιήσεις push για κάθε νέο αίτημα και μήνυμα, και εφαρμογή για ανάληψη και απαντήσεις, που μπαίνει στην αρχική οθόνη ή ανοίγει από την εφαρμογή Android. Χωρίς Firebase και χωρίς λογαριασμό σε τρίτους.
 * Version:              1.1.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/live-operator
 * Text Domain:          live-operator
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - live-operator.php → bootstrap only (constants, requires, init wiring)
 *  - LOP_Lang      → EN dictionary + gettext filter (rs_lang, Bible §9)
 *  - LOP_Settings  → the lop_settings option, strict validation
 *  - LOP_Purio     → everything read from PurioChat Pro's Live Chat
 *  - LOP_Presence  → the server-side online / offline switch, automatic
 *                    offline (lop_state, WP-Cron lop_auto_offline)
 *  - LOP_Crypt     → sealed private key (Bible §12)
 *  - LOP_Web_Push  → Web Push protocol: RFC 8291 encryption, RFC 8292 VAPID
 *  - LOP_Push      → keys, subscriptions (user meta lop_push), the
 *                    notifications on PurioChat's Live Chat events
 *  - LOP_REST      → live-operator/v1 for the app page
 *  - LOP_App       → /live-operator/ (app, service worker, manifest) and
 *                    /.well-known/assetlinks.json for the Android app
 *  - LOP_Admin_UI  → menu, settings, status checks, test, footer
 *
 * Deviations from the Bible (with reason):
 *  - §1.2 (WooCommerce required): a WordPress plugin; it needs PurioChat
 *    Pro, not WooCommerce (no "Requires Plugins": PurioChat is not on
 *    WordPress.org; a notice says when it is missing).
 *  - §8 (capability manage_woocommerce): manage_options, the capability
 *    PurioChat's Live Chat routes ask for.
 *  - §1.1 (only public APIs of other plugins): PurioChat is a third-party
 *    plugin without a presence API. LOP_Purio reads its availability and
 *    waiting-time options and answers its presence transient through the
 *    WordPress transient filters, never writing any of its data.
 *  - §14.4 (no own front-end output, no own REST): /live-operator/ and the
 *    live-operator/v1 routes are the plugin's purpose, for logged-in
 *    administrators only; the service worker and the Android app's
 *    verified link need the site's origin.
 *  - §21 (one mobile app for the suite, the Site Bridge app): the live chat
 *    needs a reply within seconds and per-message notifications; the Site
 *    Bridge app goes through the Hub with a fingerprint for every write and
 *    checks hourly (WorkManager). Its companion Android app
 *    (apps/live-operator-android) is a Trusted Web Activity of
 *    /live-operator/: notifications by Web Push, without Firebase.
 *
 * Front end (Bible §14): nothing for visitors. LOP_DISABLE in wp-config.php
 * stops the switch filters, the notifications, the app and the REST routes;
 * PurioChat then works exactly as without the plugin.
 */

defined( 'ABSPATH' ) || exit;

define( 'LOP_VERSION', '1.1.0' );
define( 'LOP_FILE', __FILE__ );
define( 'LOP_PATH', plugin_dir_path( __FILE__ ) );
define( 'LOP_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once LOP_PATH . 'includes/noxpress-core/loader.php';

require_once LOP_PATH . 'includes/class-lang.php';
require_once LOP_PATH . 'includes/class-settings.php';
require_once LOP_PATH . 'includes/class-purio.php';
require_once LOP_PATH . 'includes/class-crypt.php';
require_once LOP_PATH . 'includes/class-web-push.php';
require_once LOP_PATH . 'includes/class-push.php';
require_once LOP_PATH . 'includes/class-presence.php';
require_once LOP_PATH . 'includes/class-rest.php';
require_once LOP_PATH . 'includes/class-app.php';

final class Live_Operator {

	public static function init(): void {
		LOP_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'live-operator', false, dirname( plugin_basename( LOP_FILE ) ) . '/languages' );
			}
		);

		if ( ! self::killed() ) {
			LOP_Presence::init();
			LOP_Push::init();
			LOP_REST::init();
		}
		// The routes stay registered with LOP_DISABLE, to answer 404.
		LOP_App::init();

		if ( is_admin() ) {
			require_once LOP_PATH . 'includes/class-admin-ui.php';
			LOP_Admin_UI::init();
		}
	}

	/** True when LOP_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'LOP_DISABLE' ) && LOP_DISABLE;
	}

	public static function activate(): void {
		LOP_App::rules();
		flush_rewrite_rules( false );
		update_option( 'lop_rules', LOP_VERSION, true );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( LOP_Presence::CRON );
		delete_option( 'lop_rules' );
		// Our rules are still registered in this request: let WordPress
		// rebuild them on the next one, without the plugin. PurioChat's own
		// presence works again at once: nothing of it was written.
		delete_option( 'rewrite_rules' );
	}
}

register_activation_hook( __FILE__, array( 'Live_Operator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Live_Operator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Live_Operator', 'init' ), 20 );

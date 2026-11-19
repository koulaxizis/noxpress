<?php
/**
 * Plugin Name:          Login Guard
 * Plugin URI:           https://noxpress.tech
 * Description:          Επαλήθευση σε δύο βήματα με εφαρμογή (TOTP) και κωδικούς ανάκτησης, όριο αποτυχημένων συνδέσεων ανά IP και ανά λογαριασμό με κλείδωμα που κλιμακώνεται και ξεκλείδωμα με email, κλείσιμο των συνδέσεων μέσω XML-RPC, προστασία από απαρίθμηση χρηστών και email όταν ένας διαχειριστής συνδέεται από νέα συσκευή. Σωστή IP πίσω από Cloudflare, λειτουργία δοκιμής και πολλοί τρόποι ανάκτησης.
 * Version:              1.1.3
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://noxpress.tech/support/
 * Update URI:           https://noxpress.tech/updates/login-guard
 * Text Domain:          login-guard
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - login-guard.php → bootstrap only (constants, requires, init wiring)
 *  - LGD_Lang        → EN dictionary + gettext filter (rs_lang, Bible §9)
 *  - LGD_Settings    → the lgd_settings option, strict validation, visitor
 *                      texts (EL / EN, Bible §14.17)
 *  - LGD_Store       → counters and lockouts (transients, hashed keys), the
 *                      active-lockout index, the event log table
 *                      {prefix}lgd_log, failure statistics, IP helpers
 *  - LGD_Guard       → the login gate (authenticate, Application
 *                      Passwords), failure counting, lost-password limits
 *                      and neutral answers
 *  - LGD_Unlock      → wp-login.php?action=lgd_unlock: unlock link by
 *                      e-mail for a locked account
 *  - LGD_Hardening   → XML-RPC and user-enumeration protections
 *  - LGD_Notify      → new-device e-mail for administrators, attack digest
 *  - LGD_Crypt       → AES-256-GCM sealing of 2FA secrets (salts, user id)
 *  - LGD_TOTP        → RFC 6238 codes, base32, otpauth:// URI
 *  - LGD_TwoFA       → second step at login (wp-login.php?action=lgd_2fa),
 *                      recovery codes, remembered browsers, strict guard,
 *                      required 2FA per role with grace, e-mails
 *  - LGD_Profile     → «Two-step verification» in the user profile
 *  - LGD_Admin_UI    → menu, tabs (overview, settings, log, tools), footer
 *  - LGD_Abilities   → login-guard/status (read only)
 *  - LGD_CLI         → wp login-guard status | unlock | mode
 *
 * WordPress-only plugin (Bible §1.2): it works with or without WooCommerce
 * (no Requires Plugins, no WC headers, capability manage_options). The
 * WooCommerce login and lost-password forms are covered when it is there,
 * because they go through the same WordPress functions.
 *
 * Front-end rules (Bible §14):
 *  - §14.1: after activation the mode is «off»: nothing is counted or
 *    blocked. «Test» counts and logs what would be locked without
 *    blocking anyone (and sends the new-device e-mail); «Live» applies
 *    everything.
 *  - §14.3: LGD_DISABLE in wp-config.php stops every hook of the plugin
 *    outside the admin page (the recovery door of the readme).
 *  - §14.6: every front-end callback is wrapped in try/catch(\Throwable)
 *    and returns what it was given on an error.
 *
 * Deviations from the Bible (with reason):
 *  - §14.2 (test mode = changes visible to managers): a manager is logged
 *    in and never meets a login form, so the test mode counts and logs
 *    without blocking anyone instead.
 *  - §14.4 (never on REST / XML-RPC / AJAX): the gate must run where
 *    passwords are checked, and that includes XML-RPC and Application
 *    Passwords on REST. Only login checks run there.
 *  - §14.5 (no DB write on a visitor request): a failed login updates its
 *    counters (transients) and one hourly statistics option; a lockout,
 *    a limit reached or an unlock request also writes one log row. That
 *    is the purpose of the plugin. A visitor who does not submit a login,
 *    lost-password or unlock form writes nothing.
 *
 * Cooperation (Bible §6): actions lgd_locked( $kind, $ident_hash, $until ),
 * lgd_unlocked( $kind, $ident_hash, $by ), lgd_2fa_passed( $user_id ),
 * lgd_2fa_failed( $user_id ) and lgd_session_blocked( $user_id ), filters
 * lgd_trusted_ip, lgd_text and lgd_requires_2fa( $required, $user ) (can
 * only add). LGD_DISABLE_2FA in wp-config.php switches off 2FA only. Reads no other plugin's data. The visitor address comes from
 * Noxpress_Safe::client_ip() (Core 1.4.7).
 */

defined( 'ABSPATH' ) || exit;

define( 'LGD_VERSION', '1.1.3' );
define( 'LGD_FILE', __FILE__ );
define( 'LGD_PATH', plugin_dir_path( __FILE__ ) );
define( 'LGD_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once LGD_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: no order or cart data is read or written.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', LGD_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', LGD_FILE, true );
		}
	}
);

require_once LGD_PATH . 'includes/class-lang.php';
require_once LGD_PATH . 'includes/class-settings.php';
require_once LGD_PATH . 'includes/class-store.php';
require_once LGD_PATH . 'includes/class-guard.php';
require_once LGD_PATH . 'includes/class-unlock.php';
require_once LGD_PATH . 'includes/class-hardening.php';
require_once LGD_PATH . 'includes/class-notify.php';
require_once LGD_PATH . 'includes/class-abilities.php';
require_once LGD_PATH . 'includes/class-crypt.php';
require_once LGD_PATH . 'includes/class-totp.php';
require_once LGD_PATH . 'includes/class-twofa.php';
require_once LGD_PATH . 'includes/class-profile.php';

final class Login_Guard {

	const CAP  = 'manage_options';
	const CRON = 'lgd_daily';

	public static function init(): void {
		LGD_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'login-guard', false, dirname( plugin_basename( LGD_FILE ) ) . '/languages' );
			}
		);

		add_action( self::CRON, array( 'LGD_Store', 'cleanup' ) );
		LGD_Abilities::init();
		add_action( 'admin_init', array( __CLASS__, 'privacy' ) );

		// Emergency switch (Bible §14.3): no login hook at all.
		if ( ! self::killed() && 'off' !== LGD_Settings::mode() ) {
			LGD_Guard::init();
			LGD_Unlock::init();
			LGD_Notify::init();
			if ( LGD_TwoFA::active() ) {
				LGD_TwoFA::init();
			}
			if ( LGD_Settings::live() ) {
				LGD_Hardening::init();
			}
		}

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'upkeep' ) );
			require_once LGD_PATH . 'includes/class-admin-ui.php';
			LGD_Admin_UI::init();
			if ( ! self::killed() ) {
				LGD_Profile::init();
			}
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once LGD_PATH . 'includes/class-cli.php';
			\WP_CLI::add_command( 'login-guard', 'LGD_CLI' );
		}
	}

	/** True when LGD_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'LGD_DISABLE' ) && LGD_DISABLE;
	}

	/** Table and daily clean-up exist (admin requests only). */
	public static function upkeep(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		LGD_Store::install();
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/** Suggested text for the privacy policy (Settings → Privacy). */
	public static function privacy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'Login Guard',
			wpautop(
				esc_html__( 'Για να προστατεύσουμε τους λογαριασμούς από επιθέσεις, όταν μια σύνδεση αποτυγχάνει μετράμε τις προσπάθειες ανά διεύθυνση IP και ανά λογαριασμό για λίγα λεπτά, μόνο ως κρυπτογραφικό hash. Όταν μια διεύθυνση ή ένας λογαριασμός κλειδώνεται, κρατάμε για 30 ημέρες την ώρα, τη διεύθυνση IP χωρίς το τελευταίο της μέρος (π.χ. 203.0.113.x) και, αν υπάρχει, τον αριθμό του λογαριασμού. Για τους διαχειριστές κρατάμε 30 ημέρες ένα hash της συσκευής (τμήμα της IP, browser και λειτουργικό), ώστε να τους ειδοποιούμε με email όταν συνδέονται από νέα συσκευή. Τίποτα από αυτά δεν στέλνεται σε τρίτους. Νομική βάση: έννομο συμφέρον για την ασφάλεια του site.', 'login-guard' )
			) . wpautop(
				esc_html__( 'Για όσους ενεργοποιούν την επαλήθευση σε δύο βήματα κρατάμε το μυστικό κλειδί της εφαρμογής κρυπτογραφημένο και τους κωδικούς ανάκτησης μόνο ως hash, μέχρι να την απενεργοποιήσουν. Αν επιλέξουν «να μη ζητηθεί ξανά σε αυτόν τον browser», ο browser κρατά ένα cookie με υπογραφή για όσες ημέρες ορίζει ο διαχειριστής.', 'login-guard' )
			)
		);
	}

	public static function activate(): void {
		LGD_Store::install();
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON );
	}
}

register_activation_hook( __FILE__, array( 'Login_Guard', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Login_Guard', 'deactivate' ) );

// Early, so the authenticate and REST filters are in place before any
// login of this request (XML-RPC and REST authenticate on init or later).
add_action( 'plugins_loaded', array( 'Login_Guard', 'init' ), 20 );

<?php
/**
 * Plugin Name:          Mail Relay
 * Plugin URI:           https://noxpress.tech
 * Description:          Όλα τα email του site μέσω SMTP (με έτοιμη ρύθμιση για Proton Mail) ή μέσω Brevo, Mailgun, SendGrid, Postmark και Amazon SES, με εφεδρική σύνδεση, αρχείο αποστολών που δείχνει ποιο plugin έστειλε κάθε email, δοκιμαστικό email, έλεγχο SPF / DKIM / DMARC και πάγωμα της αποστολής σε αντίγραφα του site. Οι κωδικοί μένουν σφραγισμένοι ή στο wp-config.php.
 * Version:              1.0.2
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://noxpress.tech/support/
 * Update URI:           https://noxpress.tech/updates/mail-relay
 * Text Domain:          mail-relay
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - mail-relay.php   → bootstrap only (constants, requires, init wiring)
 *  - MRL_Lang         → EN dictionary + gettext filter (rs_lang, Bible §9)
 *  - MRL_Crypt        → sealed secrets (Bible §12), bound to the connection
 *  - MRL_Settings     → the mrl_settings option, presets, strict validation
 *  - MRL_Mailer       → pre_wp_mail / phpmailer_init: primary, backup,
 *                       staging guard, From, sensitive mails, source
 *  - MRL_PHPMailer    → WP's PHPMailer plus the "mrlapi" mailer that hands
 *                       the built message to an API adapter
 *  - MRL_Api          → Brevo, Mailgun, SendGrid, Postmark, Amazon SES
 *                       (fixed hosts in the code, never a URL from settings)
 *  - MRL_Log          → table {prefix}mrl_log, retention, sealed bodies,
 *                       privacy exporter / eraser, CSV
 *  - MRL_Alerts       → admin banner, hourly summary email
 *  - MRL_Dns          → SPF / DKIM / DMARC check of the From domain
 *  - MRL_Import       → non-secret settings from FluentSMTP
 *  - MRL_Admin_UI     → menu, tabs (log, connection, tools, settings)
 *  - MRL_Abilities    → mail-relay/status, mail-relay/log (read only)
 *
 * Security (Tier 1, Bible §11, §12, rule 35):
 *  - Capability manage_options everywhere (settings, secrets, log).
 *  - Secrets: wp-config.php constant first, else sealed AES-256-GCM with
 *    the salts; refused without real salts; never shown, exported, logged
 *    or printed by PHPMailer's debug (SMTPDebug stays 0); cleared when
 *    the host, user or provider changes.
 *  - SMTP with a password only over SSL / STARTTLS, certificate always
 *    verified. SMTP host must resolve to public IPs (MRL_ALLOW_LOCAL_SMTP
 *    allows a local relay without a password).
 *  - Mail bodies are kept only when the setting is on, for 7 days at most,
 *    sealed, and never for sensitive mails (password reset, login links,
 *    tokens).
 *
 * Emergency switch: MRL_DISABLE in wp-config.php stops all sending hooks
 * (WordPress sends as it would without the plugin).
 */

defined( 'ABSPATH' ) || exit;

define( 'MRL_VERSION', '1.0.2' );
define( 'MRL_FILE', __FILE__ );
define( 'MRL_PATH', plugin_dir_path( __FILE__ ) );
define( 'MRL_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once MRL_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: no order or cart data is read or written.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MRL_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MRL_FILE, true );
		}
	}
);

require_once MRL_PATH . 'includes/class-lang.php';
require_once MRL_PATH . 'includes/class-crypt.php';
require_once MRL_PATH . 'includes/class-settings.php';
require_once MRL_PATH . 'includes/class-log.php';
require_once MRL_PATH . 'includes/class-api.php';
require_once MRL_PATH . 'includes/class-mailer.php';
require_once MRL_PATH . 'includes/class-alerts.php';
require_once MRL_PATH . 'includes/class-abilities.php';

final class Mail_Relay {

	/**
	 * Meta capability: manage_options on a single site, manage_network_options
	 * on multisite. Whoever controls the mail transport of a site receives
	 * every mail sent there, password resets of network users included, so
	 * on a network only a network administrator may hold it.
	 */
	const CAP  = 'mrl_manage';
	const CRON = 'mrl_daily';

	public static function init(): void {
		MRL_Lang::init();
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_cap' ), 10, 2 );

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'mail-relay', false, dirname( plugin_basename( MRL_FILE ) ) . '/languages' );
			}
		);

		if ( ! self::killed() ) {
			MRL_Mailer::init();
		}
		MRL_Log::init();
		MRL_Alerts::init();
		MRL_Abilities::init();
		add_action( self::CRON, array( 'MRL_Log', 'cleanup' ) );

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'upkeep' ) );
			require_once MRL_PATH . 'includes/class-dns.php';
			require_once MRL_PATH . 'includes/class-import.php';
			require_once MRL_PATH . 'includes/class-admin-ui.php';
			MRL_Admin_UI::init();
		}
	}

	public static function map_cap( $caps, $cap ) {
		if ( self::CAP === $cap ) {
			return array( is_multisite() ? 'manage_network_options' : 'manage_options' );
		}
		return $caps;
	}

	/** True when MRL_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'MRL_DISABLE' ) && MRL_DISABLE;
	}

	/** Table and daily clean-up exist (admin requests only). */
	public static function upkeep(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		MRL_Log::install();
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function activate(): void {
		MRL_Log::install();
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Deactivation turns sending off (mode «off»), so a later activation
	 * never sends from settings nobody has looked at again.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON );
		$s = MRL_Settings::get();
		if ( 'on' === $s['mode'] ) {
			$s['mode'] = 'off';
			MRL_Settings::save( $s );
		}
	}
}

register_activation_hook( __FILE__, array( 'Mail_Relay', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Mail_Relay', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Mail_Relay', 'init' ), 20 );

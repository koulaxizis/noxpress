<?php
/**
 * Plugin Name:          Magic Login
 * Plugin URI:           https://noxpress.tech
 * Description:          Σύνδεση πελατών χωρίς κωδικό: κουμπί στη φόρμα σύνδεσης του «Ο λογαριασμός μου» και του ταμείου που στέλνει με email σύνδεσμο μίας χρήσης, με λήξη, όριο αιτημάτων και σελίδα επιβεβαίωσης. Ο κωδικός μένει ως εναλλακτική· οι διαχειριστές συνδέονται μόνο με κωδικό.
 * Version:              1.0.2
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
 * Update URI:           https://noxpress.tech/updates/magic-login
 * Text Domain:          magic-login
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - magic-login.php → bootstrap only (constants, requires, init wiring)
 *  - MGL_Lang      → EN dictionary + gettext filter (admin: rs_lang;
 *                    customer e-mail: forced to the visitor's language)
 *  - MGL_Settings  → the mgl_settings option, strict validation, visitor
 *                    texts (EL / EN)
 *  - MGL_Tokens    → one-time tokens (user meta, HMAC only), rate limits
 *                    (transients), the event log (mgl_log), who may use a
 *                    link (never staff)
 *  - MGL_Front     → the button in WooCommerce's login forms (My Account,
 *                    classic checkout), the request, the confirmation box
 *                    the link opens, the login on its POST
 *  - MGL_Emails    → registers MGL_Email_Link (WooCommerce → Settings →
 *                    Emails) and sends it in the visitor's language
 *  - MGL_Admin_UI  → menu, settings, log, tools (backup, developers),
 *                    privacy text, footer
 *  - MGL_Abilities → magic-login/status in the Abilities API (read only)
 *
 * How a login works:
 *  1. The customer writes an e-mail (or username) in WooCommerce's login
 *     form and presses «Στείλε μου σύνδεσμο σύνδεσης». The same neutral
 *     message shows whether an account exists or not.
 *  2. If the account may use links (a customer or a trade account: no
 *     edit_posts, manage_woocommerce or manage_options), a 256-bit token is
 *     made, only its HMAC is kept in user meta with its expiry (default 15
 *     minutes), and the link goes out as a WooCommerce e-mail (FluentSMTP
 *     or whatever sends wp_mail). A new request replaces the old token.
 *  3. The link opens the My Account page with a «Σύνδεση» button and logs
 *     in nothing by itself: mail scanners (Outlook Safe Links, Gmail)
 *     open links before the customer does, and a GET that logged in would
 *     burn the token. The button POSTs the token; it is deleted before the
 *     login (one use), and the customer goes back where they were (e.g.
 *     the checkout with the cart).
 *
 * Front-end rules (Bible §14): nothing changes after activation (mode
 * "off"); MGL_DISABLE in wp-config.php stops every front-end hook; no
 * theme or plugin file is changed; every callback is wrapped in
 * try/catch(\Throwable).
 *
 * Cooperation (Bible §6): fires mgl_logged_in( $user_id ) after a login
 * by link and WordPress's own wp_login. Filters mgl_user_allowed and
 * mgl_text. Reads no other plugin's data.
 *
 * Deviations from the Bible (with reason):
 *  - §14.2 (test mode = changes visible to managers): a manager is logged
 *    in and never sees a login form, and staff may not use links. Test
 *    mode shows the button only on a login form opened with ?mgl-test in
 *    the URL, and sends links only to the accounts listed in the settings
 *    (a test customer); every other request gets the neutral message.
 *  - §14.5 (no DB write on a visitor request): asking for a link writes
 *    the token (user meta), two rate-limit counters (transients) and a log
 *    line; using it deletes the token and logs. That is the purpose of the
 *    plugin. Nothing is written for a visitor who does not press our
 *    buttons, and nothing personal goes to the log (user id, time, event).
 *  - §14.4 (never on AJAX / REST): nothing here runs there; the two POSTs
 *    are ordinary form posts handled on wp_loaded, before WooCommerce's
 *    own login handler.
 */

defined( 'ABSPATH' ) || exit;

define( 'MGL_VERSION', '1.0.2' );
define( 'MGL_FILE', __FILE__ );
define( 'MGL_PATH', plugin_dir_path( __FILE__ ) );
define( 'MGL_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once MGL_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: no order data is read or written. The login
// forms are the classic ones; the Checkout block has no login form.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MGL_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MGL_FILE, true );
		}
	}
);

require_once MGL_PATH . 'includes/class-lang.php';

final class Magic_Login {

	public static function init(): void {

		MGL_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'magic-login', false, dirname( plugin_basename( MGL_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once MGL_PATH . 'includes/class-settings.php';
		require_once MGL_PATH . 'includes/class-tokens.php';
		require_once MGL_PATH . 'includes/class-emails.php';

		MGL_Tokens::init();
		MGL_Emails::init();

		// Emergency switch (Bible §14): no front-end hook at all.
		if ( ! self::killed() && ! is_admin() ) {
			require_once MGL_PATH . 'includes/class-front.php';
			MGL_Front::init();
		}

		if ( is_admin() ) {
			require_once MGL_PATH . 'includes/class-admin-ui.php';
			MGL_Admin_UI::init();
		}

		require_once MGL_PATH . 'includes/class-abilities.php';
		MGL_Abilities::init();
	}

	/** True when MGL_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'MGL_DISABLE' ) && MGL_DISABLE;
	}

	/** Capability of the settings, the log and the abilities. */
	public static function cap(): string {
		return 'manage_woocommerce';
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Magic Login χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'magic-login' )
			. '</p></div>';
	}
}

add_action( 'plugins_loaded', array( 'Magic_Login', 'init' ), 20 );

/*
 * The log option exists from activation on. Created later by the first
 * event, a persistent object cache (Redis) can lose that first write to the
 * shared "notoptions" cache of a parallel request.
 */
register_activation_hook(
	MGL_FILE,
	static function () {
		add_option( 'mgl_log', array(), '', false );
	}
);

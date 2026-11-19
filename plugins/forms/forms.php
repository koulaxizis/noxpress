<?php
/**
 * Plugin Name:          Forms
 * Plugin URI:           https://noxpress.tech
 * Description:          Φόρμες επικοινωνίας με block και shortcode, ειδοποίηση με email και προστασία από spam χωρίς υπηρεσίες τρίτων (honeypot, υπογεγραμμένος χρόνος, αόρατο proof of work, κανόνες περιεχομένου, όριο ανά επισκέπτη). Κανένα μήνυμα δεν χάνεται όταν αποτύχει το email. Εισαγωγή από Contact Form 7 με το ίδιο shortcode, ερώτηση για προϊόν, εγγραφή στο orOS Newsletter. Δουλεύει με ή χωρίς WooCommerce.
 * Version:              1.0.2
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://noxpress.tech/support/
 * Update URI:           https://noxpress.tech/updates/forms
 * Text Domain:          forms
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter. Design:
 * notes/forms/01-sxediasmos.md (approved 2026-11-16).
 *
 * Dispatch map:
 *  - forms.php       → bootstrap only (constants, requires, init wiring)
 *  - FRM_Lang        → EN dictionary + gettext filter (admin: rs_lang)
 *  - FRM_Settings    → the frm_settings option (mode, language, retention,
 *                      spam defaults, visitor texts EL/EN)
 *  - FRM_Forms       → post type frm_form, the form schema (fields, e-mail,
 *                      texts, spam, storage), validation, templates
 *  - FRM_Fields      → field types: markup and server-side validation
 *  - FRM_Spam        → tokens, proof of work, content rules, limits, counter
 *  - FRM_Entries     → post type frm_entry, retention, privacy tools, CSV
 *  - FRM_Mailer      → the notification and the optional auto-reply
 *  - FRM_Submit      → one submission end to end (both REST and no-JS)
 *  - FRM_Front       → block, shortcodes (and the Contact Form 7 one), REST
 *                      routes, no-JS posts and the confirmation step
 *  - FRM_CF7         → import from Contact Form 7
 *  - FRM_Oros        → sign-ups to orOS Newsletter through orOS Connect
 *  - FRM_Abilities   → read-only abilities (Site Bridge, WP 6.9+)
 *  - FRM_Admin_UI    → menu, forms, builder, messages, settings, tools
 *  - FRM_Widget      → dashboard widget (Bible §6)
 *
 * Deviations from the Bible (with reason):
 *  - §1.2 (WooCommerce required): contact forms are for any WordPress site
 *    (no "Requires Plugins"); with WooCommerce, the product enquiry field
 *    and manage_woocommerce (Bible 1.20 exception, as Publishing Quote).
 *  - §8 (capability manage_woocommerce): manage_woocommerce with
 *    WooCommerce, manage_options without it (Chris 2026-11-16, Q14).
 *  - §14.4 (no REST): the token and the submit routes are the purpose of
 *    the plugin (frm/v1/token, frm/v1/submit).
 *  - §14.5 (no database write on a visitor request): only the explicit
 *    «Send» writes (the message when it is stored, the one-use mark of the
 *    token, the per-IP limits as hashed transients), as Easy Withdrawal
 *    (§14.15); and a blocked attempt adds one to the daily spam counter,
 *    at most 20 times an hour per IP (Chris 2026-11-16, Q15).
 *  - §14.13 (no checkbox inputs): choices and consent are real inputs,
 *    hidden visually under a CSS box, so screen readers announce them.
 *  - §12 (nothing left after uninstall): stored messages are the owner's
 *    record: they stay unless «Delete the messages on uninstall» is on.
 *
 * Front end (Bible §14): test mode by default (only managers see the
 * forms); FRM_DISABLE in wp-config.php stops the forms, the REST routes
 * and the Contact Form 7 shortcode.
 *
 * Cooperation (Bible §6): actions frm_submission( $form_id, $data,
 * $entry_id ) and frm_entry_created( $entry_id, $form_id, $data ); filters
 * frm_spam_check, frm_mail_args, frm_text; functions frm_get_form(),
 * frm_get_entries(), frm_get_entry().
 */

defined( 'ABSPATH' ) || exit;

define( 'FRM_VERSION', '1.0.2' );
define( 'FRM_FILE', __FILE__ );
define( 'FRM_PATH', plugin_dir_path( __FILE__ ) );
define( 'FRM_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once FRM_PATH . 'includes/noxpress-core/loader.php';

require_once FRM_PATH . 'includes/class-lang.php';
require_once FRM_PATH . 'includes/class-settings.php';
require_once FRM_PATH . 'includes/class-fields.php';
require_once FRM_PATH . 'includes/class-forms.php';
require_once FRM_PATH . 'includes/class-spam.php';
require_once FRM_PATH . 'includes/class-entries.php';
require_once FRM_PATH . 'includes/class-mailer.php';
require_once FRM_PATH . 'includes/class-oros.php';
require_once FRM_PATH . 'includes/class-submit.php';
require_once FRM_PATH . 'includes/functions.php';

final class Noxpress_Forms {

	public static function init(): void {
		FRM_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'forms', false, dirname( plugin_basename( FRM_FILE ) ) . '/languages' );
			}
		);

		FRM_Forms::init();
		FRM_Entries::init();
		FRM_Oros::init();

		require_once FRM_PATH . 'includes/class-front.php';
		FRM_Front::init();

		require_once FRM_PATH . 'includes/class-abilities.php';
		FRM_Abilities::init();

		if ( is_admin() ) {
			require_once FRM_PATH . 'includes/class-cf7.php';
			require_once FRM_PATH . 'includes/class-admin-ui.php';
			require_once FRM_PATH . 'includes/class-widget.php';
			FRM_Admin_UI::init();
			FRM_Widget::init();
		}
	}

	/** True when FRM_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'FRM_DISABLE' ) && FRM_DISABLE;
	}

	/** True when WooCommerce is active. */
	public static function woo(): bool {
		return class_exists( 'WooCommerce' );
	}

	/** Capability for the plugin: shop managers with WooCommerce, admins without it. */
	public static function cap(): string {
		return self::woo() ? 'manage_woocommerce' : 'manage_options';
	}
}

register_activation_hook( __FILE__, array( 'FRM_Entries', 'schedule' ) );
register_deactivation_hook( __FILE__, array( 'FRM_Entries', 'unschedule' ) );

// Priority 20 like the other suite plugins.
add_action( 'plugins_loaded', array( 'Noxpress_Forms', 'init' ), 20 );

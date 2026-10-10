<?php
/**
 * Plugin Name:          Easy Withdrawal
 * Plugin URI:           https://noxpress.tech
 * Description:          Λειτουργία υπαναχώρησης για WooCommerce (Οδηγία ΕΕ 2023/2673): φόρμα για πελάτες και επισκέπτες, επιλογή προϊόντων και ποσοτήτων, επιβεβαίωση σε δύο βήματα, απόδειξη με email, εξαιρέσεις (εξατομικευμένα, ψηφιακά με συναίνεση), καταγραφή στην παραγγελία και λίστα αιτημάτων στο admin.
 * Version:              1.0.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.1
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/easy-withdrawal
 * Text Domain:          easy-withdrawal
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - easy-withdrawal.php → bootstrap only (constants, requires, init wiring)
 *  - EWD_Lang       → EN dictionary + gettext filter (admin: rs_lang,
 *                     front end: the page language, emails: the language
 *                     of the request)
 *  - EWD_Settings   → the ewd_settings option, strict validation
 *  - EWD_Rules      → withdrawal window, exclusions, quantities still
 *                     available per order item
 *  - EWD_Requests   → requests stored in order meta (CRUD, HPOS and
 *                     legacy), status changes, order notes, suite hooks
 *  - EWD_Form       → [nox_withdrawal] shortcode: find order → items →
 *                     review → confirm → receipt; signed tokens, rate limit
 *  - EWD_Account    → My Account buttons, links in order emails and on the
 *                     thank-you page, optional footer link
 *  - EWD_Checkout   → consent checkbox for digital items (classic and block
 *                     checkout), "personalised" flag on products
 *  - EWD_Emails     → WC_Email classes: receipt, admin notice, status change
 *  - EWD_Admin_UI   → menu, requests list and detail, CSV, settings, order
 *                     metabox, refund pre-fill, footer
 *
 * Front-end rules (Bible §14): test mode by default (only shop managers
 * see the form, buttons and links); EWD_DISABLE in wp-config.php stops
 * every front-end hook; no theme or plugin file is changed.
 *
 * Cooperation (Bible §6): fires noxpress_withdrawal_submitted and
 * noxpress_withdrawal_status_changed. It does not fire rs_invalidate_cache:
 * revenue changes only with the refund, which WooCommerce reports itself.
 * No custom order statuses, so the reports of RS and SP stay correct.
 *
 * Deviations from the Bible (with reason):
 *  - §14.5 (no database write on a visitor request): the confirmed
 *    withdrawal statement must be recorded, so the final step writes the
 *    request to the order meta. The lookup step writes a rate-limit
 *    transient (as Revenue Splitter's portal does). Nothing else is
 *    written for visitors: the steps in between travel in a signed token.
 *  - §12 (nothing left after uninstall): the requests stay in the order
 *    meta. They are the store's record of the consumer's statement (the
 *    readme says so). Settings, transients and product flags are deleted.
 */

defined( 'ABSPATH' ) || exit;

define( 'EWD_VERSION', '1.0.0' );
define( 'EWD_FILE', __FILE__ );
define( 'EWD_PATH', plugin_dir_path( __FILE__ ) );
define( 'EWD_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once EWD_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce HPOS (custom order tables) compatibility: orders are read and
// written through the CRUD API only.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', EWD_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', EWD_FILE, true );
		}
	}
);

require_once EWD_PATH . 'includes/class-lang.php';

final class Easy_Withdrawal {

	public static function init(): void {

		EWD_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'easy-withdrawal', false, dirname( plugin_basename( EWD_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once EWD_PATH . 'includes/class-settings.php';
		require_once EWD_PATH . 'includes/class-rules.php';
		require_once EWD_PATH . 'includes/class-requests.php';
		require_once EWD_PATH . 'includes/class-emails.php';
		require_once EWD_PATH . 'includes/class-checkout.php';

		// Emails, the product flag and the checkout consent work in every
		// context: they record data, they do not change what visitors see
		// of the theme.
		EWD_Emails::init();
		EWD_Checkout::init();

		// Emergency switch (Bible §14): no front-end hook at all.
		if ( ! self::killed() ) {
			require_once EWD_PATH . 'includes/class-form.php';
			require_once EWD_PATH . 'includes/class-account.php';
			EWD_Form::init();
			EWD_Account::init();
		}

		if ( is_admin() ) {
			require_once EWD_PATH . 'includes/class-admin-ui.php';
			EWD_Admin_UI::init();
		}
	}

	/** True when EWD_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'EWD_DISABLE' ) && EWD_DISABLE;
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Easy Withdrawal χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'easy-withdrawal' )
			. '</p></div>';
	}
}

add_action( 'plugins_loaded', array( 'Easy_Withdrawal', 'init' ), 20 );

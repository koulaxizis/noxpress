<?php
/**
 * Plugin Name:          orOS Connect
 * Plugin URI:           https://noxpress.tech
 * Description:          Σύνδεση του site με το orOS (useoros.online). Modules που ενεργοποιείς ένα-ένα· το πρώτο είναι το Newsletter: φόρμα εγγραφής (μπλοκ, shortcode, widget) και ατσέκαρτο κουτάκι στο ταμείο, που στέλνουν τους νέους συνδρομητές στη λίστα σου στο orOS Newsletter· και οι Προσφορές: τα αιτήματα «Ενδιαφέρομαι» του Publishing Quote γίνονται πρόχειρες προσφορές στο orOS. Δουλεύει με ή χωρίς WooCommerce.
 * Version:              1.2.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/oros-connect
 * Text Domain:          oros-connect
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * A plugin with modules (Bible §20): everything orOS-related lives here,
 * each module is off until switched on, and a module that is off loads no
 * code at all. orOS is a static system in the browser: nothing here can
 * write into it. The site only hands data to orOS's own channels (the
 * relay's public sign-up form), or keeps it for orOS to pull while it is
 * open (the offers endpoint).
 *
 * Dispatch map:
 *  - oros-connect.php → bootstrap only (constants, requires, init wiring)
 *  - ORC_Lang       → EN dictionary + gettext filter (admin: rs_lang)
 *  - ORC_Settings   → the orc_settings option, strict validation, modules,
 *                     visitor texts (EL/EN), test / live / off mode
 *  - ORC_Relay      → the orOS sign-up link (parse, check), the server-side
 *                     sign-up POST, the send log
 *  - ORC_Newsletter → module "newsletter": form (shortcode, block, widget),
 *                     checkout consent (classic and block checkout), the
 *                     send queue (Action Scheduler), order screen line
 *  - ORC_Offers     → module "offers": REST orc/v1/offers that orOS pulls
 *                     (Publishing Quote requests → draft offers), with the
 *                     shop's WooCommerce REST keys or an Application Password
 *  - ORC_Admin_UI   → menu, modules, Newsletter and Offers settings, tools
 *
 * Deviations from the Bible (with reason):
 *  - §1.2 (WooCommerce required): the Newsletter form works on any
 *    WordPress site (no "Requires Plugins"); the checkout consent loads only
 *    with WooCommerce (Bible 1.20 exception).
 *  - §8 (capability manage_woocommerce): manage_woocommerce with
 *    WooCommerce, manage_options without it.
 *  - §14.5 (no database write on a visitor request): the checkout consent
 *    is part of the order WooCommerce is already saving (order meta, CRUD);
 *    the send is queued when the order is paid. The sign-up form itself
 *    posts straight to the orOS relay and writes nothing here.
 *  - §12 (nothing left after uninstall): the consent stays in the order
 *    meta: it is the shop's record of the customer's consent (as Easy
 *    Withdrawal's statements). Settings, the log and queued sends go.
 *  - §14.4 (no REST): the offers routes are the purpose of the Offers
 *    module; they answer only to authenticated managers.
 *
 * Front end (Bible §14): off until a module is switched on; test mode by
 * default (only managers see the form and the checkbox); ORC_DISABLE in
 * wp-config.php stops every front-end hook and every send.
 *
 * Cooperation (Bible §6): fires orc_newsletter_sent( $order_id, $result )
 * after each checkout sign-up attempt; filter orc_text for visitor texts.
 */

defined( 'ABSPATH' ) || exit;

define( 'ORC_VERSION', '1.2.0' );
define( 'ORC_FILE', __FILE__ );
define( 'ORC_PATH', plugin_dir_path( __FILE__ ) );
define( 'ORC_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once ORC_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce HPOS (custom order tables) and checkout blocks: orders are
// read and written through the CRUD API only.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ORC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', ORC_FILE, true );
		}
	}
);

require_once ORC_PATH . 'includes/class-lang.php';
require_once ORC_PATH . 'includes/class-settings.php';
require_once ORC_PATH . 'includes/class-relay.php';

final class Oros_Connect {

	public static function init(): void {
		ORC_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'oros-connect', false, dirname( plugin_basename( ORC_FILE ) ) . '/languages' );
			}
		);

		// Queued checkout sign-ups run even if the module was switched off
		// after they were queued: the handler then drops them (logged).
		add_action( 'orc_nl_send', array( __CLASS__, 'run_send' ), 10, 2 );

		if ( ORC_Settings::module( 'newsletter' ) ) {
			require_once ORC_PATH . 'includes/class-newsletter.php';
			ORC_Newsletter::init();
		}

		if ( ORC_Settings::module( 'offers' ) ) {
			require_once ORC_PATH . 'includes/class-offers.php';
			ORC_Offers::init();
		}

		if ( is_admin() ) {
			require_once ORC_PATH . 'includes/class-admin-ui.php';
			ORC_Admin_UI::init();
		}
	}

	/** Action Scheduler callback of a queued checkout sign-up. */
	public static function run_send( $order_id = 0, $try = 1 ): void {
		require_once ORC_PATH . 'includes/class-newsletter.php';
		ORC_Newsletter::send( (int) $order_id, (int) $try );
	}

	/** True when ORC_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'ORC_DISABLE' ) && ORC_DISABLE;
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

// Priority 20 like the other suite plugins.
add_action( 'plugins_loaded', array( 'Oros_Connect', 'init' ), 20 );

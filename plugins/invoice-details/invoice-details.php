<?php
/**
 * Plugin Name:          Invoice Details
 * Plugin URI:           https://noxpress.tech
 * Description:          Απόδειξη ή τιμολόγιο στο ταμείο (κλασικό και block): επωνυμία, ΑΦΜ, ΔΟΥ και δραστηριότητα, έλεγχος του ελληνικού ΑΦΜ με το ψηφίο ελέγχου και των ΑΦΜ της ΕΕ στο VIES, τα στοιχεία στην παραγγελία, στα emails και στον λογαριασμό του πελάτη, και λίστα τιμολογίων με CSV. Δεν κόβει παραστατικά: τα δίνει στον πάροχο τιμολόγησης.
 * Version:              1.1.1
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
 * Update URI:           https://noxpress.tech/updates/invoice-details
 * Text Domain:          invoice-details
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - invoice-details.php → bootstrap only (constants, requires, init wiring)
 *  - IVD_Lang      → EN dictionary + gettext filter (admin only: rs_lang;
 *                    visitor texts are store settings, Bible §14.17)
 *  - IVD_Settings  → the ivd_settings option, strict validation, visitor
 *                    texts (el/en, filter ivd_text)
 *  - IVD_Vat       → number clean-up, the Greek check digit, EU prefixes,
 *                    the VIES client (fixed host, 8 s timeout)
 *  - IVD_Data      → the invoice details of an order: our meta, the old
 *                    Timologia / OBS meta (read), compatible meta (write),
 *                    the customer's saved details
 *  - IVD_Checkout  → the receipt / invoice choice and the fields in the
 *                    classic checkout and the Checkout block, validation,
 *                    saving, the VIES check at order time
 *  - IVD_Order     → what an order shows: totals rows (thank-you page,
 *                    My account, emails), the admin meta box (edit + VIES
 *                    check), the orders list column and filter, My account
 *                    › Addresses
 *  - IVD_Admin_UI  → menu, «Τιμολόγια» list with CSV, settings, footer
 *  - IVD_Abilities → read-only ability invoice-details/get-order (WP 6.9+)
 *
 * Front-end rules (Bible §14): test mode after activation (only shop
 * managers see the choice); IVD_DISABLE in wp-config.php stops every
 * checkout hook (orders keep showing their details); no theme or plugin
 * file is changed; no cookie, no session.
 *
 * Cooperation (Bible §6): public API ivd_get(), ivd_is_invoice(); filters
 * ivd_require_invoice (another plugin asks for an invoice, e.g. Trade
 * Accounts), ivd_text, ivd_vies_response (tests).
 *
 * Deviations from the Bible (with reason):
 *  - §14.4 (never in AJAX or REST): the fields live in WooCommerce's
 *    checkout (update_order_review keeps them through the posted form) and
 *    in the Checkout block as additional checkout fields (the §14.4
 *    Store API exception).
 *  - No external request (§14): one request to the European Commission's
 *    VIES (fixed host ec.europa.eu, no key, no personal data except the
 *    VAT number) when an order with an EU VAT number of another country is
 *    placed, or when a shop manager presses «Έλεγχος VIES». Greek numbers
 *    are checked locally (check digit). No public route, no nopriv AJAX.
 *  - §12 (clean uninstall): the invoice details on the orders stay (store
 *    records) and so do the customers' saved details (their billing data),
 *    unless "Delete the customers' saved details" is ticked.
 */

defined( 'ABSPATH' ) || exit;

define( 'IVD_VERSION', '1.1.1' );
define( 'IVD_FILE', __FILE__ );
define( 'IVD_PATH', plugin_dir_path( __FILE__ ) );
define( 'IVD_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once IVD_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: orders only through CRUD (HPOS); the Checkout
// block gets the fields through additional checkout fields.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', IVD_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', IVD_FILE, true );
		}
	}
);

require_once IVD_PATH . 'includes/class-lang.php';

final class Invoice_Details {

	public static function init(): void {

		IVD_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'invoice-details', false, dirname( plugin_basename( IVD_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once IVD_PATH . 'includes/class-settings.php';
		require_once IVD_PATH . 'includes/class-vat.php';
		require_once IVD_PATH . 'includes/class-data.php';
		require_once IVD_PATH . 'includes/class-checkout.php';
		require_once IVD_PATH . 'includes/class-order.php';
		require_once IVD_PATH . 'includes/class-abilities.php';
		require_once IVD_PATH . 'includes/class-privacy.php';
		require_once IVD_PATH . 'includes/class-aade.php';

		// Orders that already carry invoice details keep showing them.
		IVD_Order::init();
		IVD_Abilities::init();
		IVD_Privacy::init();

		// Emergency switch (Bible §14): no checkout hooks at all.
		if ( ! self::killed() ) {
			IVD_Checkout::init();
			IVD_Aade::init();
		}

		if ( is_admin() ) {
			require_once IVD_PATH . 'includes/class-admin-ui.php';
			IVD_Admin_UI::init();
		}
	}

	/** True when IVD_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'IVD_DISABLE' ) && IVD_DISABLE;
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Invoice Details χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'invoice-details' )
			. '</p></div>';
	}
}

/**
 * The invoice details of an order (public API, Bible §6).
 *
 * @param int|WC_Order $order Order or order ID.
 * @return array|null { type: 'invoice', company, vat, country, tax_office,
 *                    activity, hq, check: array, source: 'ivd'|'timologia'|'obs' },
 *                    or null when the order has no invoice.
 */
function ivd_get( $order ): ?array {
	if ( ! class_exists( 'IVD_Data' ) ) {
		return null;
	}
	return IVD_Data::get( $order );
}

/**
 * True when the customer asked for an invoice (public API, Bible §6).
 *
 * @param int|WC_Order $order Order or order ID.
 */
function ivd_is_invoice( $order ): bool {
	return null !== ivd_get( $order );
}

/**
 * True when the current visitor sees the receipt / invoice choice at
 * checkout (mode «On for everyone», or test mode for a shop manager, and not
 * IVD_DISABLE). Other plugins hand their invoice rule to Invoice Details only
 * then (filter ivd_require_invoice), and keep their own otherwise.
 *
 * @since 1.0.1
 */
function ivd_offered(): bool {
	return class_exists( 'IVD_Checkout' ) && IVD_Checkout::is_offered();
}

add_action( 'plugins_loaded', array( 'Invoice_Details', 'init' ), 20 );

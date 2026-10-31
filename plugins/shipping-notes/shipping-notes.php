<?php
/**
 * Plugin Name:          Shipping Notes
 * Plugin URI:           https://noxpress.tech
 * Description:          Μηνύματα αποστολής για τον πελάτη, χωρίς plugins τρίτων: «Απομένουν Χ € για δωρεάν αποστολή» με μπάρα προόδου σε καλάθι, mini-cart και ταμείο (όριο από τις ζώνες αποστολής του WooCommerce, κλασικό και block καλάθι), και «Παράδοση σε Χ–Ψ εργάσιμες» στη σελίδα προϊόντος, ανά μέθοδο αποστολής, κατηγορία και προϊόν, με cut-off, εργάσιμες και αργίες. Για καλάθια σε δύο δέματα (π.χ. Printful): ονόματα δεμάτων, μήνυμα, ελληνικές ετικέτες και τα δέματα στην παραγγελία.
 * Version:              1.2.0
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
 * Update URI:           https://noxpress.tech/updates/shipping-notes
 * Text Domain:          shipping-notes
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - shipping-notes.php → bootstrap only (constants, requires, init wiring)
 *  - SHN_Lang      → EN dictionary + gettext filter (admin only: rs_lang;
 *                    visitor texts are store settings, Bible §14.17)
 *  - SHN_Settings  → the shn_settings option, strict validation, visitor
 *                    texts (el/en, filter shn_text)
 *  - SHN_Threshold → free-shipping threshold from the shipping zones and
 *                    the amount WooCommerce counts (taxes, coupons)
 *  - SHN_Calendar  → dispatch / delivery days, cut-off, holidays, Greek
 *                    public holidays (Orthodox Easter)
 *  - SHN_Delivery  → delivery estimate of a product (preparation +
 *                    transit per shipping method), its HTML
 *  - SHN_Front     → places, cart fragments, Store API cart data, Cart /
 *                    Checkout block slot, shortcodes, blocks, assets
 *  - SHN_Parcels   → carts in several shipping packages (e.g. Printful):
 *                    parcel names, notice, partner rate labels, the
 *                    parcels in the order, product-page hint (1.1.0)
 *  - SHN_Fields    → preparation days and release date on products and
 *                    product categories
 *  - SHN_Admin_UI  → menu, settings page with tabs, preview, backup, footer
 *
 * Front-end rules (Bible §14): both parts are off after activation; test
 * mode shows them only to shop managers; SHN_DISABLE in wp-config.php
 * stops every front-end hook; no theme or plugin file is changed; no
 * cookie, no external request, no database write on a visitor request
 * (the parcels part adds a meta to the shipping lines of the order that
 * WooCommerce is creating at checkout).
 *
 * Cooperation (Bible §6): fires nothing. Filters for others: shn_text,
 * shn_threshold, shn_available_from (pre-order plugins), shn_partner_package,
 * shn_partner_rate, shn_partner_product. Reads the $package['printful']
 * flag the Printful plugin sets on its package (no option of another plugin).
 *
 * Deviations from the Bible (with reason):
 *  - §14.4 (never in AJAX or REST): the free-shipping parts also run on
 *    WooCommerce's cart AJAX (fragments, update_order_review) — the §14.4
 *    exception — and add read-only data to the Store API cart response,
 *    so the Cart and Checkout blocks refresh the bar without an endpoint
 *    of our own (Bible 1.10 writes this into §14.4).
 */

defined( 'ABSPATH' ) || exit;

define( 'SHN_VERSION', '1.2.0' );
define( 'SHN_FILE', __FILE__ );
define( 'SHN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SHN_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once SHN_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: orders through CRUD only (parcel name on the
// shipping lines, the parcels in order views and emails); the Cart
// and Checkout blocks are supported through the Store API cart data.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SHN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SHN_FILE, true );
		}
	}
);

require_once SHN_PATH . 'includes/class-lang.php';

final class Shipping_Notes {

	public static function init(): void {

		SHN_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'shipping-notes', false, dirname( plugin_basename( SHN_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once SHN_PATH . 'includes/class-settings.php';
		require_once SHN_PATH . 'includes/class-calendar.php';
		require_once SHN_PATH . 'includes/class-threshold.php';
		require_once SHN_PATH . 'includes/class-delivery.php';
		require_once SHN_PATH . 'includes/class-front.php';
		require_once SHN_PATH . 'includes/class-parcels.php';

		// Emergency switch (Bible §14): no front-end hook at all. The blocks
		// stay registered (empty output) so the editor keeps them valid.
		if ( self::killed() ) {
			add_action( 'init', array( 'SHN_Front', 'register_blocks' ) );
		} else {
			SHN_Front::init();
			SHN_Parcels::init();
		}

		if ( is_admin() ) {
			require_once SHN_PATH . 'includes/class-fields.php';
			require_once SHN_PATH . 'includes/class-admin-ui.php';
			SHN_Fields::init();
			SHN_Admin_UI::init();
		}
	}

	/** True when SHN_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'SHN_DISABLE' ) && SHN_DISABLE;
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Shipping Notes χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'shipping-notes' )
			. '</p></div>';
	}
}

add_action( 'plugins_loaded', array( 'Shipping_Notes', 'init' ), 20 );

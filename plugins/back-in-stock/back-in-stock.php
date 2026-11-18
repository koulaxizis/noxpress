<?php
/**
 * Plugin Name:          Back in Stock
 * Plugin URI:           https://noxpress.tech
 * Description:          Φόρμα «Ειδοποίησέ με όταν ξαναέρθει» σε εξαντλημένα προϊόντα και παραλλαγές: επιβεβαίωση με email για τους επισκέπτες, ένα email όταν το προϊόν ξαναμπεί σε απόθεμα, αποστολή σε παρτίδες στο παρασκήνιο και ζήτηση ανά προϊόν. Χωρίς newsletter και χωρίς εξωτερική υπηρεσία.
 * Version:              1.0.2
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.1
 * WC tested up to:      11.3
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/back-in-stock
 * Text Domain:          back-in-stock
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - back-in-stock.php → bootstrap only (constants, requires, init wiring)
 *  - BIS_Lang      → EN dictionary + gettext filter (admin: rs_lang;
 *                    customer e-mails: the language of the request)
 *  - BIS_Settings  → the bis_settings option, strict validation, visitor
 *                    texts (EL / EN)
 *  - BIS_Store     → the {prefix}bis_requests table, tokens (HMAC only),
 *                    rate limits, demand counts, bis_covers()
 *  - BIS_Front     → the form on the product page, its POST (PRG), the
 *                    confirm / unsubscribe pages opened from the e-mails
 *  - BIS_Sender    → stock hooks, Action Scheduler jobs, batches, the daily
 *                    clean-up
 *  - BIS_Emails    → registers the two WC_Email classes (off by default)
 *  - BIS_Privacy   → WordPress personal data exporter and eraser
 *  - BIS_Admin_UI  → menu, requests per product, settings, tools, the
 *                    «Ζήτηση εξαντλημένων» dashboard widget
 *  - BIS_Abilities → back-in-stock/demand in the Abilities API (read only)
 *
 * How a request works:
 *  1. An out-of-stock product (stock status "outofstock", never a
 *     backorder or a pre-order) shows the form: e-mail and a consent box.
 *  2. A logged-in customer's request is active at once. A guest gets a
 *     «confirm» e-mail first (double opt-in, 48 hours), so nobody can sign
 *     up someone else's address.
 *  3. When the product (or the variation) goes back in stock, a job runs
 *     after a delay (default 10 minutes, so a 0 → 5 → 0 slip sends
 *     nothing), checks the stock again and sends one e-mail per request in
 *     batches (default 50, by id, each batch schedules the next). A request
 *     is marked sent only once its e-mail has left; then its address is
 *     erased and only a hash stays for 30 days (counts, Wish Shelf).
 *
 * Front-end rules (Bible §14): test mode on activation (only shop managers
 * see the form); BIS_DISABLE in wp-config.php stops every front-end hook;
 * the same HTML for every guest (page caches work); every callback is
 * wrapped in try/catch(\Throwable). Rule 20: no newsletter box, no
 * mailing-list service, one transactional e-mail per request.
 *
 * Cooperation (Bible §6): bis_covers( $email, $product_id, $variation_id )
 * for Wish Shelf (one e-mail, not two); reads por_get_release() (Pre-Orders),
 * hic_hidden_terms() (Hide Catalog), pfm_get_work() (Product Formats) and
 * the _bkf_gone meta (Book Feed). Filters bis_text, bis_show_form; action
 * bis_sent( $request_id, $product_id, $variation_id ).
 *
 * Deviations from the Bible (with reason):
 *  - §14.5 (no DB write on a visitor request): a request writes one row
 *    and a rate-limit counter. That is the purpose of the plugin; nothing
 *    is written for a visitor who does not press our button.
 */

defined( 'ABSPATH' ) || exit;

define( 'BIS_VERSION', '1.0.2' );
define( 'BIS_DB_VERSION', '1' );
define( 'BIS_FILE', __FILE__ );
define( 'BIS_PATH', plugin_dir_path( __FILE__ ) );
define( 'BIS_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16).
require_once BIS_PATH . 'includes/noxpress-core/loader.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BIS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BIS_FILE, true );
		}
	}
);

require_once BIS_PATH . 'includes/class-lang.php';

final class Back_In_Stock {

	public static function init(): void {

		BIS_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'back-in-stock', false, dirname( plugin_basename( BIS_FILE ) ) . '/languages' );
			}
		);

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once BIS_PATH . 'includes/class-settings.php';
		require_once BIS_PATH . 'includes/class-store.php';
		require_once BIS_PATH . 'includes/class-emails.php';
		require_once BIS_PATH . 'includes/class-sender.php';
		require_once BIS_PATH . 'includes/class-privacy.php';

		BIS_Store::maybe_upgrade();
		BIS_Emails::init();
		BIS_Sender::init();
		BIS_Privacy::init();

		if ( ! self::killed() ) {
			require_once BIS_PATH . 'includes/class-front.php';
			BIS_Front::init();
		}

		if ( is_admin() ) {
			require_once BIS_PATH . 'includes/class-admin-ui.php';
			BIS_Admin_UI::init();
		}

		require_once BIS_PATH . 'includes/class-abilities.php';
		BIS_Abilities::init();
	}

	/** True when BIS_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'BIS_DISABLE' ) && BIS_DISABLE;
	}

	/** Capability of the settings, the requests and the abilities. */
	public static function cap(): string {
		return 'manage_woocommerce';
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Back in Stock χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'back-in-stock' )
			. '</p></div>';
	}
}

add_action( 'plugins_loaded', array( 'Back_In_Stock', 'init' ), 20 );

if ( ! function_exists( 'bis_covers' ) ) {
	/**
	 * Whether Back in Stock sends (or has sent in the last 2 days) the
	 * back-in-stock e-mail for this address and product. Wish Shelf asks
	 * before its own stock line, so the customer gets one e-mail, not two.
	 *
	 * @param string $email        E-mail address.
	 * @param int    $product_id   Product id (the parent of a variation).
	 * @param int    $variation_id Variation id, 0 for a simple product.
	 */
	function bis_covers( $email, $product_id, $variation_id = 0 ): bool {
		if ( ! class_exists( 'BIS_Store' ) ) {
			return false;
		}
		return BIS_Store::covers( (string) $email, (int) $product_id, (int) $variation_id );
	}
}

register_activation_hook(
	BIS_FILE,
	static function () {
		require_once BIS_PATH . 'includes/class-store.php';
		BIS_Store::install();
	}
);

register_deactivation_hook(
	BIS_FILE,
	static function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'bis_daily', array(), 'back-in-stock' );
		}
	}
);

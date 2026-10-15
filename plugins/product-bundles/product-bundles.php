<?php
/**
 * Plugin Name:          Product Bundles
 * Plugin URI:           https://noxpress.tech
 * Description:          Πακέτα προϊόντων για το WooCommerce: νέος τύπος προϊόντος «Πακέτο» με προϊόντα (και επιλογή μεγέθους από τον πελάτη), έκπτωση % ή τελική τιμή, τιμή και διαθεσιμότητα από τα προϊόντα. Στην παραγγελία μπαίνει κάθε προϊόν με το δικό του απόθεμα, ΦΠΑ και ποσοστά, και η έκπτωση φαίνεται ως έκπτωση στα Revenue Splitter, Store Pulse και WooCommerce.
 * Version:              1.0.1
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.8
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/product-bundles
 * Text Domain:          product-bundles
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - product-bundles.php → bootstrap only (constants, requires, init wiring,
 *                          product class, activation / deactivation)
 *  - PBN_Lang            → EN dictionary + gettext filter (admin only:
 *                          rs_lang; visitor texts are store settings)
 *  - PBN_Settings        → the pbn_settings option, strict validation,
 *                          mode, visitor texts (el/en, filter pbn_text)
 *  - PBN_Product         → the product type nox_bundle (WC_Product)
 *  - PBN_Bundle          → contents, prices, availability, derived data
 *                          (price, stock status, weight), reverse index,
 *                          Action Scheduler jobs
 *  - PBN_Cart            → a bundle in the cart is its products (group,
 *                          prices, locked quantities, coupons)
 *  - PBN_Order           → order lines (_pbn, Δ2 discount), admin
 *                          recalculation, the bundle's sales, "Bundles"
 *                          box on the order screen
 *  - PBN_Front           → the bundle's product page
 *  - PBN_Admin_Product   → "Bundle" on the product screen
 *  - PBN_Convert         → an existing product becomes a bundle (backup, undo)
 *  - PBN_Admin_UI        → menu, bundles list, settings, backup, footer
 *
 * The approved model (design 2026-10-14, model B): a bundle never becomes
 * an order line. In the cart and in the order every product is its own
 * line (own stock, VAT, Revenue Splitter beneficiaries, refunds) with the
 * meta "Bundle: …"; the bundle discount is the difference between the line
 * subtotal (separate price) and total (bundle share), like a coupon.
 *
 * Front-end rules (Bible §14): bundles are not for sale after activation
 * (mode "off" until set to "test" or "live"); test mode lets only shop
 * managers buy them; PBN_DISABLE in wp-config.php stops the sale of bundles
 * and the product page form; every callback is wrapped.
 *
 * Cooperation (Bible §6): fires noxpress_products_changed after bundles
 * are rebuilt. Filter for others: pbn_text. Public API: pbn_get_item_bundle().
 *
 * Deviations from the Bible (with reason):
 *  - §14.3 / §14.4: the cart and order hooks run whatever the mode, with
 *    PBN_DISABLE too and in the Store API, because they keep a cart or an
 *    order that already holds bundle lines correct (prices, groups, the
 *    order discount). They change nothing for carts without bundles.
 *  - §14.5 (no write on a visitor request): when an order changes the stock
 *    of a product inside bundles, the bundles are not rebuilt in that
 *    request; one Action Scheduler job per bundle is queued (group
 *    product-bundles) by WooCommerce's own stock hook. The bundle's own
 *    total_sales grows with WooCommerce's sales count of the order.
 *  - §12 (clean uninstall): the contents and discount of bundles (_pbn_*
 *    product meta) and conversion backups stay after uninstall unless
 *    "Delete … when the plugin is deleted" is ticked (content the user
 *    writes). Order line meta (_pbn and "Bundle: …") is order history and
 *    always stays.
 */

defined( 'ABSPATH' ) || exit;

define( 'PBN_VERSION', '1.0.1' );
define( 'PBN_FILE', __FILE__ );
define( 'PBN_PATH', plugin_dir_path( __FILE__ ) );
define( 'PBN_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once PBN_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: order data only through CRUD (HPOS-safe); the
// Cart / Checkout blocks work through the Store API hooks.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PBN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PBN_FILE, true );
		}
	}
);

// Activation: rebuild every bundle (deactivation marked them out of stock).
register_activation_hook(
	__FILE__,
	static function () {
		update_option( 'pbn_build_pending', 1, false );
	}
);

// Deactivation: WooCommerce would load bundles as simple products and sell
// them without their contents; they become "Out of stock" (derived data,
// approved question 12) and the background jobs stop.
register_deactivation_hook(
	__FILE__,
	static function () {
		if ( class_exists( 'PBN_Bundle' ) ) {
			PBN_Bundle::freeze_all();
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'pbn_sync', null, 'product-bundles' );
			as_unschedule_all_actions( 'pbn_sync_all', null, 'product-bundles' );
		}
	}
);

require_once PBN_PATH . 'includes/class-lang.php';

final class Product_Bundles {

	public static function init(): void {

		PBN_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'product-bundles', false, dirname( plugin_basename( PBN_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Product' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once PBN_PATH . 'includes/class-settings.php';
		require_once PBN_PATH . 'includes/class-product.php';
		require_once PBN_PATH . 'includes/class-bundle.php';
		require_once PBN_PATH . 'includes/class-cart.php';
		require_once PBN_PATH . 'includes/class-order.php';
		require_once PBN_PATH . 'includes/class-front.php';

		// The product class, before anything loads a product.
		add_filter( 'woocommerce_product_class', array( __CLASS__, 'product_class' ), 20, 2 );
		add_filter( 'woocommerce_data_stores', array( __CLASS__, 'data_store' ), 20, 1 );

		// Store data: always on, so bundles, carts and orders stay correct.
		PBN_Bundle::init();
		PBN_Cart::init();
		PBN_Order::init();

		// Emergency switch (Bible §14): no product page form.
		if ( ! self::killed() ) {
			PBN_Front::init();
		}

		if ( is_admin() ) {
			require_once PBN_PATH . 'includes/class-admin-product.php';
			require_once PBN_PATH . 'includes/class-convert.php';
			require_once PBN_PATH . 'includes/class-admin-ui.php';
			PBN_Admin_Product::init();
			PBN_Admin_UI::init();
		} else {
			// The type list is read outside the admin too (REST, product search).
			add_filter( 'product_type_selector', array( __CLASS__, 'type_name' ), 20 );
		}
		add_action( 'admin_init', array( __CLASS__, 'first_build' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'first_build_cron' ) );
	}

	/** True when PBN_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'PBN_DISABLE' ) && PBN_DISABLE;
	}

	/**
	 * nox_bundle → PBN_Product.
	 *
	 * @param mixed $classname    Class name.
	 * @param mixed $product_type Product type.
	 */
	public static function product_class( $classname, $product_type = '' ) {
		return PBN_Product::TYPE === $product_type ? 'PBN_Product' : $classname;
	}

	/**
	 * Bundles use WooCommerce's product data store.
	 *
	 * @param mixed $stores Data stores.
	 */
	public static function data_store( $stores ) {
		if ( is_array( $stores ) && ! isset( $stores[ 'product-' . PBN_Product::TYPE ] ) ) {
			$stores[ 'product-' . PBN_Product::TYPE ] = 'WC_Product_Data_Store_CPT';
		}
		return $stores;
	}

	/** @param mixed $types Product types. */
	public static function type_name( $types ) {
		$types                       = is_array( $types ) ? $types : array();
		$types[ PBN_Product::TYPE ] = __( 'Πακέτο', 'product-bundles' );
		return $types;
	}

	/** After activation: rebuild the index and every bundle once. */
	public static function first_build(): void {
		if ( ! get_option( 'pbn_build_pending' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		delete_option( 'pbn_build_pending' );
		PBN_Bundle::rebuild_index();
		$ids = PBN_Bundle::bundle_ids();
		if ( count( $ids ) <= 200 ) {
			PBN_Bundle::sync_many( $ids );
		} else {
			PBN_Bundle::enqueue_all();
		}
	}

	/** Activation from WP-CLI: no admin request follows, queue the rebuild. */
	public static function first_build_cron(): void {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) && get_option( 'pbn_build_pending' ) ) {
			delete_option( 'pbn_build_pending' );
			PBN_Bundle::rebuild_index();
			PBN_Bundle::enqueue_all();
		}
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Product Bundles χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'product-bundles' )
			. '</p></div>';
	}
}

/**
 * The bundle an order line came from (public API, Bible §6), e.g. for a
 * "via bundle" column in reports.
 *
 * @param WC_Order_Item_Product $item Order line.
 * @return array|null { bundle_id, name, group, bundles, per, separate, share }, or null.
 */
function pbn_get_item_bundle( $item ): ?array {
	if ( ! class_exists( 'PBN_Order' ) || ! $item instanceof WC_Order_Item_Product ) {
		return null;
	}
	return PBN_Order::item_bundle( $item );
}

add_action( 'plugins_loaded', array( 'Product_Bundles', 'init' ), 20 );

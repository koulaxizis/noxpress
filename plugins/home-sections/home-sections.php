<?php
/**
 * Plugin Name:          Home Sections
 * Plugin URI:           https://noxpress.tech
 * Description:          Έξυπνα shortcodes [products] για την αρχική και κάθε σελίδα: marker classes που εξαιρούν προϊόντα, κατηγορίες ή προπαραγγελίες και κρατούν μόνο προϊόντα με μια λέξη στον τίτλο, έτοιμα ή ως δικά σου presets, και το [hs_video] με το νεότερο book trailer ή παρουσίαση από τις καρτέλες βίντεο των προϊόντων. Τα προϊόντα δεν αλλάζουν.
 * Version:              1.1.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.1
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/home-sections
 * Text Domain:          home-sections
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - home-sections.php → bootstrap only (constants, requires, init wiring)
 *  - HMS_Lang      → EN dictionary + gettext filter (admin only: rs_lang)
 *  - HMS_Settings  → the hms_settings option (alias prefix, presets),
 *                    strict validation
 *  - HMS_Markers   → reads the marker classes of a [products] shortcode
 *                    (built-in hs-…, the alias prefix, presets) and turns
 *                    them into query args; the title condition in
 *                    posts_where
 *  - HMS_Video     → the [hs_video] shortcode: the newest product video
 *                    (trailer / presentation) from the products' video
 *                    tabs, poster markup only, cache transient, the
 *                    click-to-load script (assets/video.js)
 *  - HMS_Admin_UI  → menu, settings page (markers, alias, presets,
 *                    shortcodes, video texts, shortcode test), footer
 *
 * Front-end rules (Bible §14): nothing changes after activation — a
 * marker class acts only where a [products] shortcode carries it, and a
 * shortcode without one gives byte-identical output; HMS_DISABLE in
 * wp-config.php stops every hook; no theme or plugin file is changed; no
 * product data is written (the markers apply at query time, so
 * deactivation restores the sections at once); no write on a visitor
 * request.
 *
 * Deviations from the Bible (with reason):
 *  - §14.2 (test mode): none. A marker is an explicit choice written in
 *    one shortcode, so a section changes only when its author adds the
 *    class; the settings page has a shortcode test instead.
 *  - §14.4 (never in REST / admin): the markers apply wherever WooCommerce
 *    renders a [products] shortcode (an editor preview too), so a preview
 *    shows what visitors will see.
 *  - §1.1 (no direct reads of another plugin's data): the pre-order
 *    marker excludes products by the meta keys of known pre-order
 *    plugins (PRENA _simple_preorder, Pre-Orders _por_on), read in the
 *    product query only. A query cannot call their PHP APIs. Filter
 *    hms_preorder_meta adds or removes keys. The same applies to the
 *    video tabs of [hs_video] (meta wb_custom_tabs of Custom Product Tabs
 *    for WooCommerce, read with get_post_meta; filter hms_video_source).
 *  - §14.5 (no write on a visitor request): [hs_video] keeps its pick
 *    (product ID + video ID per attribute set) in ONE transient,
 *    hms_video_cache (12 h), written when a visitor request finds it
 *    missing or stale and cleared whenever a product or its tabs change.
 *    Nothing else is written. Without it every view would scan 50
 *    products.
 *  - §14.10 (queries only in save hooks): that scan runs at render time
 *    on a cache miss, because the pick depends on the shortcode's own
 *    attributes (category, exclude, pre-orders).
 */

defined( 'ABSPATH' ) || exit;

define( 'HMS_VERSION', '1.1.0' );
define( 'HMS_FILE', __FILE__ );
define( 'HMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'HMS_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once HMS_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: no order data is read or written; the Cart /
// Checkout blocks are not changed.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', HMS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', HMS_FILE, true );
		}
	}
);

require_once HMS_PATH . 'includes/class-lang.php';

final class Home_Sections {

	public static function init(): void {

		HMS_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'home-sections', false, dirname( plugin_basename( HMS_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once HMS_PATH . 'includes/class-settings.php';
		require_once HMS_PATH . 'includes/class-markers.php';
		require_once HMS_PATH . 'includes/class-video.php';

		// [hs_video] is registered even with HMS_DISABLE, so it never shows
		// as text; it then prints nothing (Bible §14).
		HMS_Video::init();

		// Emergency switch (Bible §14): no front-end hook at all.
		if ( ! self::killed() ) {
			HMS_Markers::init();
		}

		if ( is_admin() ) {
			require_once HMS_PATH . 'includes/class-admin-ui.php';
			HMS_Admin_UI::init();
		}
	}

	/** True when HMS_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'HMS_DISABLE' ) && HMS_DISABLE;
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Home Sections χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'home-sections' )
			. '</p></div>';
	}
}

add_action( 'plugins_loaded', array( 'Home_Sections', 'init' ), 20 );

<?php
/**
 * Plugin Name:          Product Tabs
 * Plugin URI:           https://noxpress.tech
 * Description:          Επιπλέον καρτέλες στη σελίδα προϊόντος: ανά προϊόν και κοινές ανά κατηγορία, ετικέτα ή είδος, βίντεο που φορτώνουν μόνο με κλικ, μία σειρά για όλες τις καρτέλες και εισαγωγή από το Custom Product Tabs for WooCommerce χωρίς να αλλάξουν τα δεδομένα του.
 * Version:              1.0.3
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
 * Update URI:           https://noxpress.tech/updates/product-tabs
 * Text Domain:          product-tabs
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - product-tabs.php → bootstrap only (constants, requires, init wiring,
 *                       public API)
 *  - PTB_Lang         → EN dictionary + gettext filter (admin: rs_lang)
 *  - PTB_Settings     → ptb_settings (mode, texts, order of the known
 *                       tabs) and ptb_shared (shared tabs and their
 *                       rules), strict validation
 *  - PTB_Tabs         → the per-product data (meta _ptb_tabs), video
 *                       addresses, which tabs a product shows (its own,
 *                       the shared ones, those shared by the other
 *                       formats of its work in Product Formats)
 *  - PTB_Front        → woocommerce_product_tabs, tab content, the
 *                       click-to-load player, [nox_video] and the bridge
 *                       for [wb_cpt_youtube_embed_shortcode]
 *  - PTB_Metabox      → "Product tabs" box on the product screen
 *  - PTB_Import       → read-only import from "Custom Product Tabs for
 *                       WooCommerce" (meta wb_custom_tabs), with undo
 *  - PTB_Admin_UI     → menu, settings page (settings, shared tabs,
 *                       import and backup, developers), PRG, footer
 *  - PTB_Abilities    → product-tabs/list, product-tabs/import-preview
 *                       (WordPress 6.9+, read only)
 *
 * Front-end rules (Bible §14): nothing changes after activation (mode
 * "off"); test mode shows our tabs and the new order to shop managers
 * only; PTB_DISABLE in wp-config.php stops every front-end hook; product
 * pages only; no write on a visitor request; every callback in
 * try/catch — on an error the original tabs stay; CSS and script only
 * on pages that show a video of ours. Videos load only on a click, from
 * youtube-nocookie.com or player.vimeo.com (dnt=1); the poster image of a
 * YouTube video comes from i.ytimg.com unless that setting is off.
 *
 * Data (Bible §12): tabs are shop content, so the product meta and the
 * shared tabs stay after uninstall unless "delete on uninstall" is
 * ticked. The meta of "Custom Product Tabs for WooCommerce" is only read.
 *
 * Cooperation (Bible §6): action noxpress_products_changed after the
 * import; ptb_get_video( $product_id, $role ) and ptb_get_tabs(
 * $product_id ) for other plugins (Home Sections reads the videos);
 * filters ptb_tabs (the tabs of a product before they reach WooCommerce)
 * and ptb_tab_content. Known tabs of the suite (Book Preview, Featured
 * Creators, Theme Patcher Care & Safety) take part in the order setting by
 * their keys.
 *
 * Deviations from the Bible (with reason):
 *  - §12 (uninstall deletes the plugin's data): tab content is shop
 *    content, kept unless the user ticks deletion, as the Bible asks for
 *    user content.
 *  - The bridge shortcode [wb_cpt_youtube_embed_shortcode] carries a
 *    third-party name, registered only while that plugin is inactive, so
 *    old content never shows as raw text after the switch.
 */

defined( 'ABSPATH' ) || exit;

define( 'PTB_VERSION', '1.0.3' );
define( 'PTB_FILE', __FILE__ );
define( 'PTB_PATH', plugin_dir_path( __FILE__ ) );
define( 'PTB_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once PTB_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: no order data is read or written.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PTB_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PTB_FILE, true );
		}
	}
);

require_once PTB_PATH . 'includes/class-lang.php';

final class Product_Tabs {

	const CAP = 'manage_woocommerce';

	public static function init(): void {

		PTB_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'product-tabs', false, dirname( plugin_basename( PTB_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once PTB_PATH . 'includes/class-settings.php';
		require_once PTB_PATH . 'includes/class-tabs.php';
		require_once PTB_PATH . 'includes/class-import.php';
		require_once PTB_PATH . 'includes/class-abilities.php';
		PTB_Abilities::init();

		// Emergency switch (Bible §14): no front-end hook at all.
		if ( ! self::killed() ) {
			require_once PTB_PATH . 'includes/class-front.php';
			PTB_Front::init();
		}

		if ( is_admin() ) {
			require_once PTB_PATH . 'includes/class-metabox.php';
			require_once PTB_PATH . 'includes/class-admin-ui.php';
			PTB_Metabox::init();
			PTB_Admin_UI::init();
		}
	}

	/** True when PTB_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'PTB_DISABLE' ) && PTB_DISABLE;
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Product Tabs χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'product-tabs' )
			. '</p></div>';
	}
}

/**
 * The YouTube ID of a product's video for a role (public API, Bible §6).
 *
 * Reads the product's own tabs first, then those shared by the other
 * formats of its work (Product Formats). Works whatever the mode is: the
 * caller decides what to show.
 *
 * @param int    $product_id Product id.
 * @param string $role       'trailer' | 'presentation'.
 * @return string An 11-character YouTube ID, or ''.
 */
function ptb_get_video( int $product_id, string $role = 'trailer' ): string {
	if ( ! class_exists( 'PTB_Tabs' ) ) {
		return '';
	}
	try {
		return PTB_Tabs::video_for_role( $product_id, $role );
	} catch ( \Throwable $e ) {
		return '';
	}
}

/**
 * The product's own tabs as stored (public API, Bible §6): id, title, type
 * ('text' | 'video'), content, video ('yt:ID' | 'vm:ID' | ''), role,
 * priority, on, share.
 *
 * @param int $product_id Product id.
 * @return array[]
 */
function ptb_get_tabs( int $product_id ): array {
	if ( ! class_exists( 'PTB_Tabs' ) ) {
		return array();
	}
	try {
		return PTB_Tabs::get( $product_id )['tabs'];
	} catch ( \Throwable $e ) {
		return array();
	}
}

add_action( 'plugins_loaded', array( 'Product_Tabs', 'init' ), 20 );

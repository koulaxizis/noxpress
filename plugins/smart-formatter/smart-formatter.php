<?php
/**
 * Plugin Name:          Smart Formatter
 * Plugin URI:           https://noxpress.tech
 * Description:          Μαζική μορφοποίηση κειμένων προϊόντων WooCommerce (bold, italic, παρενθέσεις, αριθμοί, εισαγωγικά, κενά) με preview, dry run, snapshots/undo και profiles.
 * Version:              1.2.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.1
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/smart-formatter
 * Text Domain:          smart-formatter
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map (ποιος κάνει τι):
 *  - smart-formatter.php  → ΜΟΝΟ bootstrap/constants/requires/init wiring
 *  - SF_Lang             → EN dict + gettext filter (διαβάζει μόνο το rs_lang)
 *  - SF_Rules            → registry κανόνων (id, label, callback, descriptor)
 *  - SF_Engine           → tokenizer (HTML/tag protection) + rule pipeline
 *  - SF_Targets          → επίλυση στόχων (products/categories/tags × fields)
 *  - SF_Snapshots        → undo (snapshot πριν κάθε run, restore, ιστορικό)
 *  - SF_Admin_UI         → menu, dashboard, settings/profiles, AJAX endpoints
 *
 * Language: Greek msgids ως πηγή, EN μέσω δικού μας dict (gettext filter,
 * domain 'smart-formatter'). Το rs_lang (user meta) ΔΙΑΒΑΖΕΤΑΙ μόνο —
 * δεν γράφεται ποτέ από εδώ (Bible §9: one choice, whole ecosystem).
 *
 * Cooperation: μετά από apply/restore που άλλαξε προϊόντα →
 * do_action( 'noxpress_products_changed', int[] $product_ids ).
 */

defined( 'ABSPATH' ) || exit;

define( 'SF_VERSION', '1.2.0' );
define( 'SF_FILE', __FILE__ );
define( 'SF_PATH', plugin_dir_path( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once SF_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce HPOS (custom order tables) compatibility: το SF δεν αγγίζει παραγγελίες.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SF_FILE, true );
		}
	}
);

require_once SF_PATH . 'includes/class-lang.php';

final class Smart_Formatter {

	public static function init(): void {

		SF_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'smart-formatter', false, dirname( plugin_basename( SF_FILE ) ) . '/languages' );
			}
		);

		// Χωρίς WooCommerce: μόνο admin notice — κανένα WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once SF_PATH . 'includes/class-rules.php';
		require_once SF_PATH . 'includes/class-engine.php';
		require_once SF_PATH . 'includes/class-targets.php';
		require_once SF_PATH . 'includes/class-snapshots.php';
		require_once SF_PATH . 'includes/class-admin-ui.php';

		SF_Admin_UI::init();
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Smart Formatter χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'smart-formatter' )
			. '</p></div>';
	}
}

add_action( 'plugins_loaded', array( 'Smart_Formatter', 'init' ), 20 );

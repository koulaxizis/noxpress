<?php
/**
 * Plugin Name:          Loop Fixer
 * Plugin URI:           https://noxpress.tech
 * Description:          Επαναφέρει τιμή, κουμπί καλαθιού και βαθμολογία στις κάρτες προϊόντων WooCommerce σε θέματα που ζωγραφίζουν δικές τους κάρτες — χωρίς child theme και χωρίς αλλαγή σε αρχεία του θέματος. Έγχυση ή αντικατάσταση ανά περιοχή, ανιχνευτής θέματος, σάρωση σελίδας και λειτουργία δοκιμής.
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
 * Text Domain:          loop-fixer
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - loop-fixer.php  → bootstrap only (constants, requires, init wiring)
 *  - LF_Lang         → EN dictionary + gettext filter (reads rs_lang only)
 *  - LF_Settings     → schema, defaults, strict validation, per-theme storage
 *  - LF_Areas        → built-in areas registry, file areas, request context
 *  - LF_Detector     → theme scan (token based), suggestions, fingerprints
 *  - LF_Render       → slot rendering (price / button / rating), area CSS
 *  - LF_Runtime      → front-end gating, loop contexts, markers, buffers
 *  - LF_Replace      → template swaps (archive / related / up-sells /
 *                      cross-sells / content-product) and archive text fixes
 *  - LF_Admin_UI     → menu, pages, PRG routes, backup, footer
 *
 * Front-end rules (Bible §14): nothing changes until configured; test
 * mode shows changes to shop managers only; LF_DISABLE in wp-config.php
 * stops every front-end hook; no database writes on visitor requests;
 * theme files are never modified.
 *
 * Cooperation (Bible §6): Loop Fixer changes presentation only, so it
 * fires no suite hook and listens to none.
 */

defined( 'ABSPATH' ) || exit;

define( 'LF_VERSION', '1.0.0' );
define( 'LF_FILE', __FILE__ );
define( 'LF_PATH', plugin_dir_path( __FILE__ ) );
define( 'LF_URL', plugin_dir_url( __FILE__ ) );

// WooCommerce HPOS (custom order tables) compatibility: Loop Fixer never touches orders.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', LF_FILE, true );
		}
	}
);

require_once LF_PATH . 'includes/class-lang.php';

final class Loop_Fixer {

	public static function init(): void {

		LF_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'loop-fixer', false, dirname( plugin_basename( LF_FILE ) ) . '/languages' );
			}
		);

		// Without WooCommerce: admin notice only — no WC-dependent code.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo_notice' ) );
			return;
		}

		require_once LF_PATH . 'includes/class-settings.php';
		require_once LF_PATH . 'includes/class-areas.php';
		require_once LF_PATH . 'includes/class-detector.php';
		require_once LF_PATH . 'includes/class-render.php';
		require_once LF_PATH . 'includes/class-runtime.php';
		require_once LF_PATH . 'includes/class-replace.php';

		LF_Detector::init();

		// Emergency switch (Bible §14): no front-end hook at all.
		if ( ! self::killed() ) {
			LF_Runtime::init();
			LF_Replace::init();
		}

		if ( is_admin() ) {
			require_once LF_PATH . 'includes/class-admin-ui.php';
			LF_Admin_UI::init();
		}
	}

	/** True when LF_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'LF_DISABLE' ) && LF_DISABLE;
	}

	public static function missing_woo_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Το Loop Fixer χρειάζεται το WooCommerce ενεργό — το plugin παραμένει ανενεργό μέχρι να ενεργοποιηθεί.', 'loop-fixer' )
			. '</p></div>';
	}
}

add_action( 'plugins_loaded', array( 'Loop_Fixer', 'init' ), 20 );

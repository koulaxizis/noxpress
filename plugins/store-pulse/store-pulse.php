<?php
/**
 * Plugin Name:       Store Pulse
 * Plugin URI:        https://noxpress.tech
 * Description:       Εικόνα του καταστήματος με μια ματιά: εκκρεμείς & εξυπηρετημένες παραγγελίες, επιστροφές, ακυρώσεις, χαμηλό/εξαντλημένο στοκ, και κέρδος εκδότη / οφειλές δικαιούχων μέσω του Revenue Splitter (χωρίς μεταφορικά).
 * Version:           1.2.5
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Christos Koulaxizis
 * License:           MIT
 * Text Domain:       store-pulse
 * Domain Path:       /languages
 *
 * Noxpress ecosystem — sister plugin του Revenue Splitter:
 *  - Χωρίς Revenue Splitter: όλες οι κάρτες λειτουργούν, εκτός από
 *    τα χρηματικά μεγέθη (κέρδος εκδότη / οφειλές) που εμφανίζουν
 *    ενημερωτικό μήνυμα με link.
 *  - Με Revenue Splitter: διαβάζει ΜΟΝΟ τα public APIs του
 *    (RS_Reports::run, RS_Beneficiaries::collect_names,
 *    RS_Ledger::sum) — μηδενικός διπλός υπολογισμός, κοινό
 *    invalidation μέσω rs_cache_version.
 */

defined( 'ABSPATH' ) || exit;

define( 'SP_VERSION', '1.2.5' );
define( 'SP_FILE', __FILE__ );
define( 'SP_PATH', plugin_dir_path( __FILE__ ) );
define( 'SP_URL', plugin_dir_url( __FILE__ ) );

/**
 * HPOS compatibility (custom order tables) — δηλώνεται πριν το
 * WooCommerce bootstrapping. Χωρίς αυτό, το Woo σε HPOS setup
 * εμφανίζει το plugin ως «incompatible» στο Site Health.
 */
add_action(
	'before_woocommerce_init',
	function (): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', 'sp_bootstrap' );

/**
 * Bootstrap. Το Revenue Splitter ΔΕΝ είναι προαπαιτούμενο — αν δεν
 * υπάρχει, φορτώνουμε κανονικά και τα money cards δείχνουν placeholder.
 */
function sp_bootstrap(): void {

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'sp_missing_woo_notice' );
		return;
	}

	add_action(
		'init',
		function () {
			load_plugin_textdomain(
				'store-pulse',
				false,
				dirname( plugin_basename( SP_FILE ) ) . '/languages'
			);
		}
	);

	require_once SP_PATH . 'includes/class-sp-lang.php';
	require_once SP_PATH . 'includes/class-sp-data.php';
	require_once SP_PATH . 'includes/class-sp-admin.php';
	require_once SP_PATH . 'includes/class-sp-dashboard.php';

	SP_Lang::init();
	SP_Admin::init();
	SP_Dashboard::init();
}

/** Είναι ενεργό το Revenue Splitter (με όλα τα APIs που χρειαζόμαστε); */
function sp_rs_active(): bool {
	return class_exists( 'RS_Reports' )
		&& class_exists( 'RS_Beneficiaries' )
		&& class_exists( 'RS_Ledger' );
}

function sp_missing_woo_notice(): void {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Το Store Pulse απαιτεί WooCommerce για να λειτουργήσει.', 'store-pulse' );
	echo '</p></div>';
}

register_activation_hook(
	__FILE__,
	function (): void {
		// Defaults μόνο με add_option — σε reinstall/upgrade δεν
		// αλλοιώνονται αποθηκευμένες επιλογές του χρήστη.
		add_option( 'sp_low_stock_threshold', '5' );
		add_option( 'sp_default_period_orders', '7d' );
		add_option( 'sp_default_period_money', 'month' );
		add_option( 'sp_default_period_refunds', 'month' );
		add_option( 'sp_default_period_cancelled', 'month' );
		add_option( 'sp_publisher', '' );
		add_option(
			'sp_quick_cards',
			array( 'pending', 'old_pending', 'completed', 'low', 'out', 'publisher', 'others' )
		);
		add_option( 'sp_cache_version', '0' );
	}
);
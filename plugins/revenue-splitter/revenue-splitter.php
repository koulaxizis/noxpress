<?php
/**
 * Plugin Name:       Revenue Splitter
 * Plugin URI:        https://noxpress.tech
 * Description:       Πωλήσεις/έσοδα WooCommerce με αυτόματη αφαίρεση ΦΠΑ ανά προϊόν, καταμερισμός σε δικαιούχους, ledger εκτός πωλήσεων & πληρωμών, υποχρεωτική αιτιολογία δωρεάν αντιτύπων και Author Portal με προσωπικά κλειδιά.
 * Version:           1.5.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Christos Koulaxizis
 * License:           MIT
 * Donate URI:        https://ko-fi.com/koulaxizis
 * Text Domain:       revenue-splitter
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'RS_VERSION', '1.5.0' );
define( 'RS_FILE', __FILE__ );
define( 'RS_PATH', plugin_dir_path( __FILE__ ) );
define( 'RS_URL', plugin_dir_url( __FILE__ ) );

// WooCommerce HPOS (custom order tables) compatibility declaration.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', RS_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', 'rs_bootstrap' );

function rs_bootstrap(): void {

	add_action(
		'init',
		function () {
			load_plugin_textdomain(
				'revenue-splitter',
				false,
				dirname( plugin_basename( RS_FILE ) ) . '/languages'
			);
		}
	);

	require_once RS_PATH . 'includes/class-lang.php';
	RS_Lang::init();

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'rs_missing_woo_notice' );
		return;
	}

	require_once RS_PATH . 'includes/class-vat.php';
	require_once RS_PATH . 'includes/class-beneficiaries.php';
	require_once RS_PATH . 'includes/class-reports.php';
	require_once RS_PATH . 'includes/class-ledger.php';
	require_once RS_PATH . 'includes/class-checkout.php';
	require_once RS_PATH . 'includes/class-admin-ui.php';
	require_once RS_PATH . 'includes/class-portal.php';

	RS_VAT::init();
	RS_Beneficiaries::init();
	RS_Reports::init();
	RS_Ledger::init();
	RS_Checkout::init();
	RS_Admin_UI::init();
	RS_Portal::init();

	// WP-CLI commands: wp rs report / ledger-add / ledger-list /
	// ledger-delete / balance / backup.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once RS_PATH . 'includes/class-cli.php';
		RS_CLI::init();
	}
}

// Migration / upgrade routine (runs on every admin init, applies defaults safely).
add_action( 'admin_init', 'rs_maybe_upgrade' );

function rs_maybe_upgrade(): void {
	$installed = (string) get_option( 'rs_version', '0' );
	if ( RS_VERSION === $installed ) {
		return; // Already on latest version.
	}

	// Future migrations go here. In 1.4.0 we just record the version.
	update_option( 'rs_version', RS_VERSION );
}

function rs_missing_woo_notice(): void {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Το Revenue Splitter απαιτεί WooCommerce για να λειτουργήσει.', 'revenue-splitter' );
	echo '</p></div>';
}

register_activation_hook(
	__FILE__,
	function (): void {
		if ( false === get_option( 'rs_default_vat_rate', false ) ) {
			add_option( 'rs_default_vat_rate', '24' );
		}
	}
);
<?php
/**
 * Plugin Name:          Revenue Splitter
 * Plugin URI:           https://noxpress.tech
 * Description:          Πωλήσεις/έσοδα WooCommerce με αυτόματη αφαίρεση ΦΠΑ ανά προϊόν, καταμερισμός σε δικαιούχους, ledger εκτός πωλήσεων & πληρωμών, υποχρεωτική αιτιολογία δωρεάν αντιτύπων, μηνιαία email αναφοράς και Author Portal με προσωπικά κλειδιά.
 * Version:              1.8.2
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.1
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/revenue-splitter
 * Text Domain:          revenue-splitter
 * Domain Path:          /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'RS_VERSION', '1.8.2' );
define( 'RS_FILE', __FILE__ );
define( 'RS_PATH', plugin_dir_path( __FILE__ ) );
define( 'RS_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once RS_PATH . 'includes/noxpress-core/loader.php';

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
	require_once RS_PATH . 'includes/class-emails.php';
	require_once RS_PATH . 'includes/class-portal.php';

	RS_VAT::init();
	RS_Beneficiaries::init();
	RS_Reports::init();
	RS_Ledger::init();
	RS_Checkout::init();
	RS_Admin_UI::init();
	RS_Emails::init();
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

/**
 * Upgrade routine: συγκρίνει την αποθηκευμένη έκδοση με το RS_VERSION
 * και εκτελεί ΜΟΝΟ τα βήματα που λείπουν (version_compare), ώστε ένα
 * store που αναβαθμίζει από οποιαδήποτε παλαιότερη έκδοση να περνά από
 * όλα τα ενδιάμεσα migrations με τη σωστή σειρά.
 *
 * Ιστορικό: μέχρι το 1.4.0 υπήρχε μόνο η καταγραφή της έκδοσης
 * (option rs_version) — κανένα data migration δεν απαιτήθηκε ως το 1.7.0.
 */
function rs_maybe_upgrade(): void {
	$installed = (string) get_option( 'rs_version', '0' );
	if ( RS_VERSION === $installed ) {
		return; // Already on latest version.
	}

	// Μελλοντικά migrations: if ( version_compare( $installed, 'X.Y.Z', '<' ) ) { … }

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

// v1.7.0: στην απενεργοποίηση αφαιρείται το cron της μηνιαίας αναφοράς
// (RS_Emails::CRON_HOOK — literal, γιατί χωρίς WooCommerce η κλάση δεν
// φορτώνεται). Στην επανενεργοποίηση το RS_Emails::init() το ξαναβάζει.
register_deactivation_hook(
	__FILE__,
	function (): void {
		wp_clear_scheduled_hook( 'rs_email_monthly_check' );
	}
);

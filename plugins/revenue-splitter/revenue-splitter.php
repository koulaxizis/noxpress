<?php
/**
 * Plugin Name:       Revenue Splitter
 * Plugin URI:        https://koulaxizis.gr
 * Description:       Προβολή πωλήσεων/εσόδων WooCommerce, αυτόματη αφαίρεση ΦΠΑ ανά προϊόν και καταμερισμός ποσοστών σε δικαιούχους.
 * Version:           1.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Christos Koulaxizis
 * License:           GPL-2.0-or-later
 * Text Domain:       revenue-splitter
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'RS_VERSION', '1.1.1' );
define( 'RS_FILE', __FILE__ );
define( 'RS_PATH', plugin_dir_path( __FILE__ ) );
define( 'RS_URL', plugin_dir_url( __FILE__ ) );

/*
 * Custom updater — το Action στο repo noxpress γράφει αυτόματα το
 * updates/revenue-splitter.json σε κάθε Release, και το Pages το σερβίρει.
 * Κενό ('') = τα updates απενεργοποιούνται.
 */
define( 'RS_UPDATE_URL', 'https://koulaxizis.github.io/noxpress/updates/revenue-splitter.json' );

add_action( 'plugins_loaded', 'rs_bootstrap' );

function rs_bootstrap(): void {

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'rs_missing_woo_notice' );
		return;
	}

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
	require_once RS_PATH . 'includes/class-vat.php';
	require_once RS_PATH . 'includes/class-beneficiaries.php';
	require_once RS_PATH . 'includes/class-reports.php';
	require_once RS_PATH . 'includes/class-admin-ui.php';
	require_once RS_PATH . 'includes/class-updater.php';

	RS_Lang::init();
	RS_VAT::init();
	RS_Beneficiaries::init();
	RS_Reports::init();
	RS_Admin_UI::init();
	RS_Updater::init();
}

function rs_missing_woo_notice(): void {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Το Revenue Splitter απαιτεί WooCommerce για να λειτουργήσει.', 'revenue-splitter' );
	echo '</p></div>';
}

register_activation_hook(
	__FILE__,
	function (): void {
		add_option( 'rs_default_vat_rate', '24' );
	}
);

register_uninstall_hook(
	__FILE__,
	array( 'RS_Uninstaller', 'run' )
);

final class RS_Uninstaller {

	public static function run(): void {
		delete_option( 'rs_default_vat_rate' );
		delete_option( 'rs_beneficiaries' );

		// Destructive uninstall: καθαρίζει και τα product meta
		// (override δικαιούχων + ΦΠΑ ανά προϊόν) — clean DB απόσταση.
		delete_post_meta_by_key( '_rs_split' );
		delete_post_meta_by_key( '_rs_vat_rate' );

		// Καθαρισμός user-level δεδομένων (γλώσσα, φίλτρα, περίοδος).
		$users = get_users( array( 'fields' => array( 'ID' ) ) );
		foreach ( $users as $u ) {
			delete_user_meta( $u->ID, 'rs_lang' );
			delete_user_meta( $u->ID, 'rs_last_period' );
			delete_user_meta( $u->ID, 'rs_last_filters' );
		}
	}
}
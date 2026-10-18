<?php
/**
 * Store Pickup — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Option pkp_settings (stores, texts).
 *  - Transients pkp_aui_msg_{uid} (PRG notices).
 *  - The method's entries in the shipping zones (table
 *    woocommerce_shipping_zone_methods, method_id nox_store_pickup) and
 *    their settings (options woocommerce_nox_store_pickup_{instance}_settings).
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Kept on purpose (Bible §12, store records): the pickup store saved on
 * the orders' shipping lines (_pkp_store and the readable copies). It
 * belongs to the order and leaves with it. The rs_lang user meta belongs
 * to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function pkp_uninstall_site(): void {
	global $wpdb;

	delete_option( 'pkp_settings' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_pkp_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_pkp_aui_msg_' ) . '%',
			$wpdb->esc_like( 'woocommerce_nox_store_pickup_' ) . '%' . $wpdb->esc_like( '_settings' )
		)
	);

	$pkp_table = $wpdb->prefix . 'woocommerce_shipping_zone_methods';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pkp_table ) ) === $pkp_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->delete( $pkp_table, array( 'method_id' => 'nox_store_pickup' ) );
	}

	// Cached shipping rates (WooCommerce's transient version for "shipping").
	delete_transient( 'shipping-transient-version' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $pkp_site_id ) {
		switch_to_blog( (int) $pkp_site_id );
		pkp_uninstall_site();
		restore_current_blog();
	}
} else {
	pkp_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$pkp_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'data-migrator' ) as $pkp_slug ) {
	if ( 'store-pickup' !== $pkp_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $pkp_slug . '/' . $pkp_slug . '.php' ) ) {
		$pkp_suite_left = true;
		break;
	}
}

if ( ! $pkp_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$pkp_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $pkp_sites as $pkp_site_id ) {
		if ( $pkp_site_id ) {
			switch_to_blog( (int) $pkp_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $pkp_site_id ) {
			restore_current_blog();
		}
	}
}

<?php
/**
 * Hide Catalog — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Option hic_settings (mode, rules).
 *  - Transients hic_aui_msg_{uid} (PRG notices).
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Nothing else exists: the plugin never writes to products, terms or
 * orders (the rules apply at query time). The rs_lang user meta belongs
 * to Revenue Splitter and is not touched. The option of "Hide Categories
 * Or Products On Shop Page" (hcps_data), read once by the import, is not
 * ours and is not touched either.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function hic_uninstall_site(): void {
	global $wpdb;

	delete_option( 'hic_settings' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_hic_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_hic_aui_msg_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $hic_site_id ) {
		switch_to_blog( (int) $hic_site_id );
		hic_uninstall_site();
		restore_current_blog();
	}
} else {
	hic_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$hic_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote' ) as $hic_slug ) {
	if ( 'hide-catalog' !== $hic_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $hic_slug . '/' . $hic_slug . '.php' ) ) {
		$hic_suite_left = true;
		break;
	}
}

if ( ! $hic_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$hic_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $hic_sites as $hic_site_id ) {
		if ( $hic_site_id ) {
			switch_to_blog( (int) $hic_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $hic_site_id ) {
			restore_current_blog();
		}
	}
}

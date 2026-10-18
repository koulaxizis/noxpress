<?php
/**
 * Shipping Notes — uninstall.php (v1.1.0)
 *
 * Deleted:
 *  - Option shn_settings.
 *  - Transients shn_aui_msg_{uid} (PRG notices).
 *  - Product meta _shn_prep_min, _shn_prep_max, _shn_available_from.
 *  - Product category meta shn_prep_min, shn_prep_max.
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Kept: the parcel name on shipping lines of orders (meta shn_parcel,
 * 1.1.0): it is part of the order record (Bible §12). The rs_lang user
 * meta belongs to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function shn_uninstall_site(): void {
	global $wpdb;

	delete_option( 'shn_settings' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_shn_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_shn_aui_msg_' ) . '%'
		)
	);

	foreach ( array( '_shn_prep_min', '_shn_prep_max', '_shn_available_from' ) as $shn_key ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery -- one-off uninstall cleanup.
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $shn_key ) );
	}
	foreach ( array( 'shn_prep_min', 'shn_prep_max' ) as $shn_key ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery -- one-off uninstall cleanup.
		$wpdb->delete( $wpdb->termmeta, array( 'meta_key' => $shn_key ) );
	}
	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( 'post_meta' );
		wp_cache_flush_group( 'term_meta' );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $shn_site_id ) {
		switch_to_blog( (int) $shn_site_id );
		shn_uninstall_site();
		restore_current_blog();
	}
} else {
	shn_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$shn_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles' ) as $shn_slug ) {
	if ( 'shipping-notes' !== $shn_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $shn_slug . '/' . $shn_slug . '.php' ) ) {
		$shn_suite_left = true;
		break;
	}
}

if ( ! $shn_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$shn_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $shn_sites as $shn_site_id ) {
		if ( $shn_site_id ) {
			switch_to_blog( (int) $shn_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $shn_site_id ) {
			restore_current_blog();
		}
	}
}

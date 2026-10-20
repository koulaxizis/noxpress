<?php
/**
 * Home Sections — uninstall.php (v1.1.0)
 *
 * Deleted:
 *  - Option hms_settings (alias prefix, presets, video texts).
 *  - Transients hms_aui_msg_{uid} (PRG notices) and hms_video_cache (the
 *    [hs_video] picks).
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Nothing else exists: the plugin never writes to products, terms or
 * orders (the markers apply at query time; [hs_video] only reads the
 * products' tab meta). The rs_lang user meta belongs
 * to Revenue Splitter and is not touched. Shortcodes that carry marker
 * classes stay in the pages and simply list products without them.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function hms_uninstall_site(): void {
	global $wpdb;

	delete_option( 'hms_settings' );
	delete_transient( 'hms_video_cache' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_hms_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_hms_aui_msg_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $hms_site_id ) {
		switch_to_blog( (int) $hms_site_id );
		hms_uninstall_site();
		restore_current_blog();
	}
} else {
	hms_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$hms_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed' ) as $hms_slug ) {
	if ( 'home-sections' !== $hms_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $hms_slug . '/' . $hms_slug . '.php' ) ) {
		$hms_suite_left = true;
		break;
	}
}

if ( ! $hms_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$hms_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $hms_sites as $hms_site_id ) {
		if ( $hms_site_id ) {
			switch_to_blog( (int) $hms_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $hms_site_id ) {
			restore_current_blog();
		}
	}
}

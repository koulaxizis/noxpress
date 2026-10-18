<?php
/**
 * Publishing Quote — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Options pbq_settings, widget_pbq_calculator (the widget instances).
 *  - Transients pbq_aui_msg_{uid} (PRG notices), pbq_rl_* (rate limit),
 *    pbq_dup_* (double-send guard).
 *  - The daily cleanup event pbq_cleanup.
 *  - The pbq_request posts and their meta, ONLY when «Delete the requests
 *    on uninstall» is on.
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Kept by default (Bible §12): the requests, which are the owner's record
 * of what each visitor asked and saw. They still expire with the retention
 * set in the settings once the plugin is back. The rs_lang user meta
 * belongs to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function pbq_uninstall_site(): void {
	global $wpdb;

	$settings = get_option( 'pbq_settings', array() );
	$wipe     = is_array( $settings ) && ! empty( $settings['wipe'] );

	if ( $wipe ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'pbq_request' ) );
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	delete_option( 'pbq_settings' );
	delete_option( 'widget_pbq_calculator' );

	foreach ( array( 'pbq_aui_msg_', 'pbq_rl_', 'pbq_dup_' ) as $prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
			)
		);
	}

	wp_unschedule_hook( 'pbq_cleanup' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $pbq_site_id ) {
		switch_to_blog( (int) $pbq_site_id );
		pbq_uninstall_site();
		restore_current_blog();
	}
} else {
	pbq_uninstall_site();
}
// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$pbq_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras' ) as $pbq_slug ) {
	if ( 'publishing-quote' !== $pbq_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $pbq_slug . '/' . $pbq_slug . '.php' ) ) {
		$pbq_suite_left = true;
		break;
	}
}

if ( ! $pbq_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$pbq_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $pbq_sites as $pbq_site_id ) {
		if ( $pbq_site_id ) {
			switch_to_blog( (int) $pbq_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $pbq_site_id ) {
			restore_current_blog();
		}
	}
}

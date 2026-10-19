<?php
/**
 * Variation Picker — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Options vpk_settings, vpk_guides, vpk_build_pending.
 *  - Transients vpk_aui_msg_{uid} (PRG notices).
 *  - Post meta _vpk_map (availability maps, derived data).
 *  - Action Scheduler jobs vpk_build_map / vpk_build_all (group
 *    variation-picker).
 *  - Only when "Delete … when the plugin is deleted" is ticked in Tools
 *    (delete_data): term meta vpk_color, vpk_color2, vpk_image, vpk_label
 *    and post meta _vpk (per-product overrides).
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Kept on purpose (Bible §12, content the user writes): without that tick
 * the colours, images and labels typed on attribute values and the
 * per-product overrides stay, so a new install finds them. Images in the
 * media library always stay. The rs_lang user meta belongs to Revenue
 * Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function vpk_uninstall_site(): void {
	global $wpdb;

	$vpk_settings = get_option( 'vpk_settings', array() );
	$vpk_all      = is_array( $vpk_settings ) && ! empty( $vpk_settings['delete_data'] );

	delete_option( 'vpk_settings' );
	delete_option( 'vpk_guides' );
	delete_option( 'vpk_build_pending' );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_vpk_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_vpk_aui_msg_' ) . '%'
		)
	);
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_vpk_map' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery

	if ( $vpk_all ) {
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_vpk' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( array( 'vpk_color', 'vpk_color2', 'vpk_image', 'vpk_label' ) as $vpk_key ) {
			$wpdb->delete( $wpdb->termmeta, array( 'meta_key' => $vpk_key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
	}
	// phpcs:enable

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'vpk_build_map', null, 'variation-picker' );
		as_unschedule_all_actions( 'vpk_build_all', null, 'variation-picker' );
	} else {
		$vpk_actions = $wpdb->prefix . 'actionscheduler_actions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $vpk_actions ) ) === $vpk_actions ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
			$wpdb->query( "DELETE FROM {$vpk_actions} WHERE hook IN ('vpk_build_map','vpk_build_all') AND status = 'pending'" );
		}
	}

	// Rows were deleted with SQL: drop the cached meta (WordPress < 6.1: whole cache).
	if ( function_exists( 'wp_cache_flush_group' ) && wp_cache_supports( 'flush_group' ) ) {
		wp_cache_flush_group( 'post_meta' );
		wp_cache_flush_group( 'term_meta' );
	} else {
		wp_cache_flush();
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $vpk_site_id ) {
		switch_to_blog( (int) $vpk_site_id );
		vpk_uninstall_site();
		restore_current_blog();
	}
} else {
	vpk_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$vpk_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed' ) as $vpk_slug ) {
	if ( 'variation-picker' !== $vpk_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $vpk_slug . '/' . $vpk_slug . '.php' ) ) {
		$vpk_suite_left = true;
		break;
	}
}

if ( ! $vpk_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$vpk_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $vpk_sites as $vpk_site_id ) {
		if ( $vpk_site_id ) {
			switch_to_blog( (int) $vpk_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $vpk_site_id ) {
			restore_current_blog();
		}
	}
}

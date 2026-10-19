<?php
/**
 * Featured Creators — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Options crt_settings, crt_conflicts, crt_flush.
 *  - Transients crt_aui_msg_{uid} (PRG notices).
 *  - Term meta crt_creator (owner of a role term; recomputed when the
 *    plugin is installed again).
 *  - Only with "Delete the creators" on (setting delete_posts): every
 *    nox_creator post with its meta (biography, links, lists, matching).
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Kept (Bible §12 deviation, see the bootstrap docblock): the creators by
 * default, because they are content written by hand. Photos always stay
 * in the media library. Products and their attributes are never touched.
 * The rs_lang user meta belongs to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function crt_uninstall_site(): void {
	global $wpdb;

	$crt_settings = get_option( 'crt_settings', array() );
	$crt_posts    = is_array( $crt_settings ) && ! empty( $crt_settings['delete_posts'] );

	if ( $crt_posts ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$crt_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'nox_creator' ) );
		foreach ( (array) $crt_ids as $crt_id ) {
			wp_delete_post( (int) $crt_id, true ); // Meta and revisions go with it; attachments stay.
		}
	}

	foreach ( array( 'crt_settings', 'crt_conflicts', 'crt_flush' ) as $crt_opt ) {
		delete_option( $crt_opt );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_crt_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_crt_aui_msg_' ) . '%'
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery -- one-off uninstall cleanup.
	$wpdb->delete( $wpdb->termmeta, array( 'meta_key' => 'crt_creator' ) );

	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( 'term_meta' );
		wp_cache_flush_group( 'featured-creators' );
	}
	flush_rewrite_rules( false );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $crt_site_id ) {
		switch_to_blog( (int) $crt_site_id );
		crt_uninstall_site();
		restore_current_blog();
	}
} else {
	crt_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$crt_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed' ) as $crt_slug ) {
	if ( 'featured-creators' !== $crt_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $crt_slug . '/' . $crt_slug . '.php' ) ) {
		$crt_suite_left = true;
		break;
	}
}

if ( ! $crt_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$crt_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $crt_sites as $crt_site_id ) {
		if ( $crt_site_id ) {
			switch_to_blog( (int) $crt_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $crt_site_id ) {
			restore_current_blog();
		}
	}
}

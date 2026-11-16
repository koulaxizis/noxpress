<?php
/**
 * Product Feed — uninstall.php (v1.0.0)
 *
 * Always deleted:
 *  - Options pfd_settings, pfd_feeds, pfd_status_{id}.
 *  - Transients pfd_aui_msg_{uid}, pfd_dirty.
 *  - Queued actions pfd_tick, pfd_batch, pfd_changed (Action Scheduler
 *    group product-feed).
 * Deleted when «Delete the feed files» (pfd_settings.drop, on by default)
 * is ticked:
 *  - The folder uploads/product-feed/ with every feed file of the plugin
 *    (only regular files of ours: *.xml, *.csv, index.php, .*.tmp-*).
 * Never deleted: a copy written to a legacy path (e.g. the old CTX Feed
 * file), because a shopping service may still read it (Bible §12,
 * deviation in the bootstrap docblock).
 * Multisite: every site ONCE (the main site included in the loop).
 *
 * Nothing of another plugin is touched. The rs_lang user meta belongs to
 * Revenue Splitter.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function pfd_uninstall_site(): void {
	global $wpdb;

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		foreach ( array( 'pfd_tick', 'pfd_batch', 'pfd_changed' ) as $pfd_hook ) {
			as_unschedule_all_actions( $pfd_hook, array(), 'product-feed' );
		}
	}

	foreach ( array( 'pfd_aui_msg_', 'pfd_dirty' ) as $pfd_prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $pfd_prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $pfd_prefix ) . '%'
			)
		);
	}

	$pfd_settings = get_option( 'pfd_settings', array() );
	$pfd_drop     = ! is_array( $pfd_settings ) || ! isset( $pfd_settings['drop'] ) || ! empty( $pfd_settings['drop'] );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'pfd_status_' ) . '%' ) );
	delete_option( 'pfd_settings' );
	delete_option( 'pfd_feeds' );

	if ( ! $pfd_drop ) {
		return;
	}
	$pfd_up   = wp_upload_dir( null, false );
	$pfd_root = untrailingslashit( (string) $pfd_up['basedir'] ) . '/product-feed';
	if ( ! is_dir( $pfd_root ) || is_link( $pfd_root ) ) {
		return;
	}
	foreach ( (array) scandir( $pfd_root ) as $pfd_key ) {
		$pfd_dir = $pfd_root . '/' . $pfd_key;
		if ( '.' === $pfd_key || '..' === $pfd_key ) {
			continue;
		}
		if ( is_file( $pfd_dir ) && 'index.php' === $pfd_key ) {
			wp_delete_file( $pfd_dir );
			continue;
		}
		if ( ! is_dir( $pfd_dir ) || is_link( $pfd_dir ) || ! preg_match( '/^[a-z0-9]{16,40}$/', (string) $pfd_key ) ) {
			continue;
		}
		foreach ( (array) scandir( $pfd_dir ) as $pfd_name ) {
			$pfd_file = $pfd_dir . '/' . $pfd_name;
			if ( is_file( $pfd_file ) && ! is_link( $pfd_file ) && preg_match( '/^(index\.php|[a-z0-9][a-z0-9\-]*\.(xml|csv)|\.[a-z0-9\-]+\.tmp-[a-z0-9]{8})$/', (string) $pfd_name ) ) {
				wp_delete_file( $pfd_file );
			}
		}
		@rmdir( $pfd_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- our own folder, only when empty.
	}
	@rmdir( $pfd_root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- only when empty.
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $pfd_site_id ) {
		switch_to_blog( (int) $pfd_site_id );
		pfd_uninstall_site();
		restore_current_blog();
	}
} else {
	pfd_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$pfd_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login', 'trade-feed-import', 'lean-images', 'redirects', 'search-signals', 'production-ledger', 'product-tabs', 'product-feed', 'invoice-details' ) as $pfd_slug ) {
	if ( 'product-feed' !== $pfd_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $pfd_slug . '/' . $pfd_slug . '.php' ) ) {
		$pfd_suite_left = true;
		break;
	}
}

if ( ! $pfd_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_option( 'noxpress_trust' ); // Core 1.3.0: newest signed list serial.
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$pfd_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $pfd_sites as $pfd_site_id ) {
		if ( $pfd_site_id ) {
			switch_to_blog( (int) $pfd_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $pfd_site_id ) {
			restore_current_blog();
		}
	}
}

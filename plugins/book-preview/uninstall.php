<?php
/**
 * Book Preview — uninstall.php (v1.0.0)
 *
 * Full cleanup (nothing left after deactivate + delete):
 *  - Sample files: the folder uploads/noxpress-previews/ (only our own
 *    file names, the index.php guard and then the folder itself).
 *  - Product meta: _bpv_source, _bpv_pages, _bpv_hidden, _bpv_sample,
 *    _bpv_error.
 *  - Options: bpv_settings, bpv_bulk (a bulk run in progress).
 *  - Transients: bpv_aui_msg_{uid} (PRG notices).
 *  - Background jobs: bpv_generate (Action Scheduler and WP-Cron).
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * The source PDFs (downloadable files, media library) are never touched.
 * The rs_lang user meta belongs to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function bpv_uninstall_site(): void {
	global $wpdb;

	delete_option( 'bpv_settings' );
	delete_option( 'bpv_bulk' );

	// Transients: LIKE with escaped '_' (otherwise a wildcard).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_bpv_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_bpv_aui_msg_' ) . '%'
		)
	);

	foreach ( array( '_bpv_source', '_bpv_pages', '_bpv_hidden', '_bpv_sample', '_bpv_error' ) as $bpv_key ) {
		delete_post_meta_by_key( $bpv_key );
	}

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'bpv_generate', array(), 'book-preview' );
		as_unschedule_all_actions( 'bpv_generate' );
	}
	$bpv_cron = _get_cron_array();
	foreach ( is_array( $bpv_cron ) ? $bpv_cron : array() as $bpv_time => $bpv_hooks ) {
		if ( isset( $bpv_hooks['bpv_generate'] ) ) {
			foreach ( $bpv_hooks['bpv_generate'] as $bpv_event ) {
				wp_unschedule_event( (int) $bpv_time, 'bpv_generate', $bpv_event['args'] );
			}
		}
	}

	// Sample files.
	$bpv_up  = wp_get_upload_dir();
	$bpv_dir = rtrim( $bpv_up['basedir'], '/\\' ) . '/noxpress-previews';
	if ( is_dir( $bpv_dir ) ) {
		foreach ( (array) scandir( $bpv_dir ) as $bpv_file ) {
			$bpv_file = (string) $bpv_file;
			$bpv_ours = preg_match( '/^\d+-[0-9a-f]{32}\.pdf(\.tmp|\.qpdf\.tmp|\.gs\.tmp)?$/', $bpv_file ) || 'index.php' === $bpv_file;
			if ( $bpv_ours && is_file( $bpv_dir . '/' . $bpv_file ) ) {
				wp_delete_file( $bpv_dir . '/' . $bpv_file );
			}
		}
		// Only when empty: anything else someone put there stays.
		@rmdir( $bpv_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $bpv_site_id ) {
		switch_to_blog( (int) $bpv_site_id );
		bpv_uninstall_site();
		restore_current_blog();
	}
} else {
	bpv_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$bpv_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'book-preview', 'shipping-notes', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast' ) as $bpv_slug ) {
	if ( 'book-preview' !== $bpv_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $bpv_slug . '/' . $bpv_slug . '.php' ) ) {
		$bpv_suite_left = true;
		break;
	}
}

if ( ! $bpv_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$bpv_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $bpv_sites as $bpv_site_id ) {
		if ( $bpv_site_id ) {
			switch_to_blog( (int) $bpv_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $bpv_site_id ) {
			restore_current_blog();
		}
	}
}

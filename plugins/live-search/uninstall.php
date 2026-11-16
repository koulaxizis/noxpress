<?php
/**
 * Live Search — uninstall.php (v1.0.0)
 *
 * Deleted (everything here is derived from the catalogue or is our own
 * log; no store content):
 *  - Table {prefix}lvs_index (the search index).
 *  - Options lvs_settings, lvs_db, lvs_queue, lvs_state, lvs_ver,
 *    lvs_file, lvs_stats.
 *  - Transients lvs_aui_msg_{uid}.
 *  - Cron events lvs_flush, lvs_hourly, lvs_daily, lvs_full.
 *  - The public folder uploads/live-search/: only index-<12 hex>.json
 *    (and .json.tmp) and index.php, then the folder if it is empty.
 *  - The statistics folder uploads/noxpress-live-search-<12 hex>/: only
 *    search-YYYY-MM.log, rate.txt, probe-*.txt, index.php, .htaccess,
 *    web.config, then the folder if it is empty.
 * Multisite: every site ONCE (the main site included in the loop).
 *
 * Nothing of another plugin is touched (the FiboSearch settings are only
 * ever read). The rs_lang user meta belongs to Revenue Splitter.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete only files whose names match, then the folder if it is empty.
 */
function lvs_uninstall_dir( string $dir, array $patterns, array $names ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $lvs_f ) {
		if ( ! is_string( $lvs_f ) ) {
			continue;
		}
		$lvs_ok = in_array( $lvs_f, $names, true );
		foreach ( $patterns as $lvs_re ) {
			if ( preg_match( $lvs_re, $lvs_f ) ) {
				$lvs_ok = true;
			}
		}
		if ( $lvs_ok ) {
			@unlink( $dir . '/' . $lvs_f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- fails while anything else is inside.
}

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function lvs_uninstall_site(): void {
	global $wpdb;

	foreach ( array( 'lvs_flush', 'lvs_hourly', 'lvs_daily', 'lvs_full' ) as $lvs_hook ) {
		wp_clear_scheduled_hook( $lvs_hook );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_lvs_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_lvs_aui_msg_' ) . '%'
		)
	);

	$lvs_up   = wp_upload_dir( null, false );
	$lvs_base = empty( $lvs_up['basedir'] ) ? '' : rtrim( (string) $lvs_up['basedir'], '/\\' );
	if ( '' !== $lvs_base ) {
		lvs_uninstall_dir( $lvs_base . '/live-search', array( '/^index-[a-f0-9]{12}\.json(\.tmp)?$/' ), array( 'index.php' ) );
		$lvs_stats = get_option( 'lvs_stats', array() );
		if ( is_array( $lvs_stats ) && ! empty( $lvs_stats['dir'] ) && preg_match( '/^noxpress-live-search-[a-f0-9]{12}$/', (string) $lvs_stats['dir'] ) ) {
			lvs_uninstall_dir(
				$lvs_base . '/' . $lvs_stats['dir'],
				array( '/^search-\d{4}-\d{2}\.log$/', '/^probe-[a-f0-9]{16}\.txt$/' ),
				array( 'rate.txt', 'index.php', '.htaccess', 'web.config' )
			);
		}
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- our own table.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lvs_index" );

	foreach ( array( 'lvs_settings', 'lvs_db', 'lvs_queue', 'lvs_state', 'lvs_ver', 'lvs_file', 'lvs_stats' ) as $lvs_opt ) {
		delete_option( $lvs_opt );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $lvs_site_id ) {
		switch_to_blog( (int) $lvs_site_id );
		lvs_uninstall_site();
		restore_current_blog();
	}
} else {
	lvs_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$lvs_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login', 'trade-feed-import', 'lean-images', 'redirects', 'search-signals', 'production-ledger', 'product-tabs', 'product-feed', 'invoice-details', 'activity-log', 'mail-relay', 'login-guard', 'live-search' ) as $lvs_slug ) {
	if ( 'live-search' !== $lvs_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $lvs_slug . '/' . $lvs_slug . '.php' ) ) {
		$lvs_suite_left = true;
		break;
	}
}

if ( ! $lvs_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_option( 'noxpress_trust' ); // Core 1.3.0: newest signed list serial.
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$lvs_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $lvs_sites as $lvs_site_id ) {
		if ( $lvs_site_id ) {
			switch_to_blog( (int) $lvs_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $lvs_site_id ) {
			restore_current_blog();
		}
	}
}

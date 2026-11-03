<?php
/**
 * Redirects — uninstall.php (v1.0.0)
 *
 * Deleted, unless «Keep the rules and the log» (rdr_settings.keep) is on:
 *  - Table {prefix}rdr_rules (the rules).
 *  - Options rdr_settings, rdr_db, rdr_patterns, rdr_state, rdr_pending,
 *    rdr_ignored.
 *  - The log folder uploads/noxpress-redirects-<12 hex>/: only files that
 *    match our patterns (404-YYYY-MM.log, rate.txt, probe-*.txt,
 *    index.php, .htaccess, web.config), then the folder if it is empty.
 * Always deleted: transients rdr_aui_msg_{uid}, rdr_prev_{uid},
 * rdr_hits_{days}, and the daily cron rdr_daily.
 * Multisite: every site ONCE (the main site included in the loop).
 *
 * Nothing of another plugin is touched (the tables of Rank Math,
 * Redirection, Yoast and the glp_map of Greeklish Permalinks are only
 * ever read). The rs_lang user meta belongs to Revenue Splitter.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function rdr_uninstall_site(): void {
	global $wpdb;

	wp_clear_scheduled_hook( 'rdr_daily' );
	foreach ( array( 'rdr_aui_msg_', 'rdr_prev_', 'rdr_hits_' ) as $rdr_prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $rdr_prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $rdr_prefix ) . '%'
			)
		);
	}

	$rdr_settings = get_option( 'rdr_settings', array() );
	if ( is_array( $rdr_settings ) && ! empty( $rdr_settings['keep'] ) ) {
		return;
	}

	// Log folder: only our files, then the folder if it is empty.
	$rdr_state = get_option( 'rdr_state', array() );
	if ( is_array( $rdr_state ) && ! empty( $rdr_state['dir'] ) && preg_match( '/^noxpress-redirects-[a-f0-9]{12}$/', (string) $rdr_state['dir'] ) ) {
		$rdr_up  = wp_upload_dir( null, false );
		$rdr_dir = rtrim( (string) $rdr_up['basedir'], '/\\' ) . '/' . $rdr_state['dir'];
		if ( is_dir( $rdr_dir ) ) {
			foreach ( (array) scandir( $rdr_dir ) as $rdr_f ) {
				if ( is_string( $rdr_f ) && ( preg_match( '/^404-\d{4}-\d{2}\.log$/', $rdr_f ) || preg_match( '/^probe-[a-f0-9]{16}\.txt$/', $rdr_f ) || in_array( $rdr_f, array( 'rate.txt', 'index.php', '.htaccess', 'web.config' ), true ) ) ) {
					@unlink( $rdr_dir . '/' . $rdr_f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			}
			@rmdir( $rdr_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- fails while anything else is inside.
		}
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- our own table.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rdr_rules" );

	foreach ( array( 'rdr_settings', 'rdr_db', 'rdr_patterns', 'rdr_state', 'rdr_pending', 'rdr_ignored' ) as $rdr_opt ) {
		delete_option( $rdr_opt );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $rdr_site_id ) {
		switch_to_blog( (int) $rdr_site_id );
		rdr_uninstall_site();
		restore_current_blog();
	}
} else {
	rdr_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$rdr_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login', 'trade-feed-import', 'lean-images', 'redirects', 'search-signals' ) as $rdr_slug ) {
	if ( 'redirects' !== $rdr_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $rdr_slug . '/' . $rdr_slug . '.php' ) ) {
		$rdr_suite_left = true;
		break;
	}
}

if ( ! $rdr_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$rdr_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $rdr_sites as $rdr_site_id ) {
		if ( $rdr_site_id ) {
			switch_to_blog( (int) $rdr_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $rdr_site_id ) {
			restore_current_blog();
		}
	}
}

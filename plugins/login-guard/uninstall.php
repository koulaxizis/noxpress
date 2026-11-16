<?php
/**
 * Login Guard — uninstall.php (v1.0.0)
 *
 * Always deleted (they only live for minutes or hours, or belong to one
 * person): every transient that starts with lgd_ (failure counters,
 * lockouts, escalation and request counters, admin notices, retry and
 * log-throttle flags), the user meta _lgd_unlock (unlock tokens) and
 * _lgd_devices (known devices), and the daily cron lgd_daily.
 * Deleted unless «Keep settings and log» (lgd_settings.keep) is on:
 *  - Table {prefix}lgd_log (the log).
 *  - Options lgd_settings, lgd_locks, lgd_stats, lgd_state, lgd_db.
 * Multisite: every site ONCE (the main site included in the loop); the
 * user meta is network-wide and is deleted once.
 *
 * The rs_lang user meta belongs to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function lgd_uninstall_site(): void {
	global $wpdb;

	wp_clear_scheduled_hook( 'lgd_daily' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_lgd_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_lgd_' ) . '%'
		)
	);

	$lgd_settings = get_option( 'lgd_settings', array() );
	if ( is_array( $lgd_settings ) && ! empty( $lgd_settings['keep'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- our own table.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}lgd_log" );

	foreach ( array( 'lgd_settings', 'lgd_locks', 'lgd_stats', 'lgd_state', 'lgd_db' ) as $lgd_opt ) {
		delete_option( $lgd_opt );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $lgd_site_id ) {
		switch_to_blog( (int) $lgd_site_id );
		lgd_uninstall_site();
		restore_current_blog();
	}
} else {
	lgd_uninstall_site();
}

delete_metadata( 'user', 0, '_lgd_unlock', '', true );
delete_metadata( 'user', 0, '_lgd_devices', '', true );

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$lgd_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login', 'trade-feed-import', 'lean-images', 'redirects', 'search-signals', 'production-ledger', 'product-tabs', 'product-feed', 'invoice-details', 'activity-log', 'mail-relay', 'login-guard', 'forms', 'live-search' ) as $lgd_slug ) {
	if ( 'login-guard' !== $lgd_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $lgd_slug . '/' . $lgd_slug . '.php' ) ) {
		$lgd_suite_left = true;
		break;
	}
}

if ( ! $lgd_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_option( 'noxpress_trust' ); // Core 1.3.0: newest signed list serial.
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$lgd_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $lgd_sites as $lgd_site_id ) {
		if ( $lgd_site_id ) {
			switch_to_blog( (int) $lgd_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $lgd_site_id ) {
			restore_current_blog();
		}
	}
}

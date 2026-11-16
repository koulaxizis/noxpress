<?php
/**
 * Activity Log — uninstall.php (v1.0.0)
 *
 * Deleted (the history is data of this plugin, Bible §12):
 *  - Table {prefix}acl_events.
 *  - Options acl_settings, acl_db, acl_notify_q, acl_notify_last.
 *  - Transients acl_aui_msg_{uid}, acl_fail_{YmdH}, acl_db_lock.
 *  - Cron events acl_daily and acl_notify.
 * Multisite: every site ONCE (the main site included in the loop).
 *
 * Nothing of another plugin is touched. The rs_lang user meta belongs to
 * Revenue Splitter.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function acl_uninstall_site(): void {
	global $wpdb;

	wp_clear_scheduled_hook( 'acl_daily' );
	wp_clear_scheduled_hook( 'acl_notify' );
	foreach ( array( 'acl_aui_msg_', 'acl_fail_', 'acl_db_lock' ) as $acl_prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $acl_prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $acl_prefix ) . '%'
			)
		);
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- our own table.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}acl_events" );

	foreach ( array( 'acl_settings', 'acl_db', 'acl_notify_q', 'acl_notify_last' ) as $acl_opt ) {
		delete_option( $acl_opt );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $acl_site_id ) {
		switch_to_blog( (int) $acl_site_id );
		acl_uninstall_site();
		restore_current_blog();
	}
} else {
	acl_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$acl_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login', 'trade-feed-import', 'lean-images', 'redirects', 'search-signals', 'production-ledger', 'product-tabs', 'product-feed', 'invoice-details', 'activity-log', 'mail-relay', 'login-guard', 'forms', 'live-search', 'back-in-stock', 'events', 'gift-cards', 'ebook-delivery' ) as $acl_slug ) {
	if ( 'activity-log' !== $acl_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $acl_slug . '/' . $acl_slug . '.php' ) ) {
		$acl_suite_left = true;
		break;
	}
}

if ( ! $acl_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_option( 'noxpress_trust' ); // Core 1.3.0: newest signed list serial.
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$acl_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $acl_sites as $acl_site_id ) {
		if ( $acl_site_id ) {
			switch_to_blog( (int) $acl_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $acl_site_id ) {
			restore_current_blog();
		}
	}
}

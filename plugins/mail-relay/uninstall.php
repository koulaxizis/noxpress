<?php
/**
 * Mail Relay — uninstall.php (v1.0.0)
 *
 * Always deleted: the sealed secrets mrl_secret_primary / mrl_secret_backup,
 * the constant pins mrl_const_pin
 * (even with «keep»: a secret never outlives the plugin), the transients
 * mrl_aui_msg_{uid}, mrl_test_{uid}, mrl_dns_*, mrl_alert_sent, the user
 * meta mrl_seen and the daily cron mrl_daily.
 * Deleted unless «Keep the settings and the log» (mrl_settings.keep) is on:
 * the table {prefix}mrl_log and the options mrl_settings, mrl_state,
 * mrl_alert, mrl_db.
 * Multisite: every site ONCE (the main site included in the loop).
 *
 * Nothing of another plugin is touched (FluentSMTP's settings are only
 * ever read). The rs_lang user meta belongs to Revenue Splitter.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function mrl_uninstall_site(): void {
	global $wpdb;

	wp_clear_scheduled_hook( 'mrl_daily' );
	delete_option( 'mrl_secret_primary' );
	delete_option( 'mrl_secret_backup' );
	delete_option( 'mrl_const_pin' );
	delete_transient( 'mrl_alert_sent' );
	foreach ( array( 'mrl_aui_msg_', 'mrl_test_', 'mrl_dns_' ) as $mrl_prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $mrl_prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $mrl_prefix ) . '%'
			)
		);
	}

	$mrl_settings = get_option( 'mrl_settings', array() );
	if ( is_array( $mrl_settings ) && ! empty( $mrl_settings['keep'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- our own table.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mrl_log" );

	foreach ( array( 'mrl_settings', 'mrl_state', 'mrl_alert', 'mrl_db' ) as $mrl_opt ) {
		delete_option( $mrl_opt );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $mrl_site_id ) {
		switch_to_blog( (int) $mrl_site_id );
		mrl_uninstall_site();
		restore_current_blog();
	}
} else {
	mrl_uninstall_site();
}
delete_metadata( 'user', 0, 'mrl_seen', '', true );

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$mrl_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login', 'trade-feed-import', 'lean-images', 'redirects', 'search-signals', 'production-ledger', 'product-tabs', 'product-feed', 'invoice-details', 'activity-log', 'mail-relay' ) as $mrl_slug ) {
	if ( 'mail-relay' !== $mrl_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $mrl_slug . '/' . $mrl_slug . '.php' ) ) {
		$mrl_suite_left = true;
		break;
	}
}

if ( ! $mrl_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_option( 'noxpress_trust' ); // Core 1.3.0: newest signed list serial.
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$mrl_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $mrl_sites as $mrl_site_id ) {
		if ( $mrl_site_id ) {
			switch_to_blog( (int) $mrl_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $mrl_site_id ) {
			restore_current_blog();
		}
	}
}

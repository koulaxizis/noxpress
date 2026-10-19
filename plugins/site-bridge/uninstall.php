<?php
/**
 * Site Bridge — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Every sbr_* option: sbr_settings, sbr_window_until, sbr_keys (the
 *    Ed25519 key pair), sbr_key_reset, sbr_hub_trust, sbr_pairing,
 *    sbr_hub_seen, sbr_sites (the Hub's paired sites), sbr_dcr_clients,
 *    sbr_db_version.
 *  - Every sbr_* transient (OAuth requests and codes, nonces, rate
 *    counters, CIMD cache, PRG notices).
 *  - The tables {prefix}sbr_log (audit log) and {prefix}sbr_grants
 *    (connections): every token stops working at once.
 *  - The daily cron sbr_purge.
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Nothing is kept: a connection or pairing must never outlive the plugin.
 * The rs_lang user meta belongs to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function sbr_uninstall_site(): void {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'sbr_' ) . '%',
			$wpdb->esc_like( '_transient_sbr_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_sbr_' ) . '%'
		)
	);
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sbr_log" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sbr_grants" );
	// phpcs:enable

	wp_clear_scheduled_hook( 'sbr_purge' );
	wp_cache_flush();
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $sbr_site_id ) {
		switch_to_blog( (int) $sbr_site_id );
		sbr_uninstall_site();
		restore_current_blog();
	}
} else {
	sbr_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$sbr_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed' ) as $sbr_slug ) {
	if ( 'site-bridge' !== $sbr_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $sbr_slug . '/' . $sbr_slug . '.php' ) ) {
		$sbr_suite_left = true;
		break;
	}
}

if ( ! $sbr_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$sbr_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $sbr_sites as $sbr_site_id ) {
		if ( $sbr_site_id ) {
			switch_to_blog( (int) $sbr_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $sbr_site_id ) {
			restore_current_blog();
		}
	}
}

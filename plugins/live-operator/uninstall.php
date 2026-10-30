<?php
/**
 * Live Operator — uninstall.php (v1.3.0)
 *
 * Deleted:
 *  - Options lop_settings, lop_state (the switch), lop_vapid (push keys),
 *    lop_rules (rewrite rules version), lop_schedule and lop_sched_state
 *    (the weekly schedule, 1.3.0).
 *  - User meta lop_push (the devices that receive notifications).
 *  - Transients lop_aui_msg_{uid} (PRG notices), the lop_auto_offline and
 *    lop_schedule_tick cron events.
 *  - Multisite: every site ONCE (the main site included in the loop).
 *  - The rewrite rules are rebuilt without /live-operator/ on the next
 *    request.
 *
 * Kept: PurioChat Pro's data and options (never written by this plugin).
 * The rs_lang user meta belongs to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function lop_uninstall_site(): void {
	global $wpdb;

	wp_clear_scheduled_hook( 'lop_auto_offline' );
	wp_clear_scheduled_hook( 'lop_schedule_tick' );

	delete_option( 'lop_settings' );
	delete_option( 'lop_state' );
	delete_option( 'lop_vapid' );
	delete_option( 'lop_rules' );
	delete_option( 'lop_schedule' );
	delete_option( 'lop_sched_state' );
	delete_option( 'rewrite_rules' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_lop_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_lop_aui_msg_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $lop_site_id ) {
		switch_to_blog( (int) $lop_site_id );
		lop_uninstall_site();
		restore_current_blog();
	}
} else {
	lop_uninstall_site();
}

// The devices live in user meta (shared by all sites of a network).
delete_metadata( 'user', 0, 'lop_push', '', true );

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$lop_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login' ) as $lop_slug ) {
	if ( 'live-operator' !== $lop_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $lop_slug . '/' . $lop_slug . '.php' ) ) {
		$lop_suite_left = true;
		break;
	}
}

if ( ! $lop_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$lop_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $lop_sites as $lop_site_id ) {
		if ( $lop_site_id ) {
			switch_to_blog( (int) $lop_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $lop_site_id ) {
			restore_current_blog();
		}
	}
}

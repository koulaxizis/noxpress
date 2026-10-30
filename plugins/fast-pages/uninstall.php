<?php
/**
 * Fast Pages — uninstall.php (v1.0.0)
 *
 * WordPress runs this only after deactivation, which already stopped the
 * page cache: drop-in, WP_CACHE line and cached pages are gone. This file
 * repeats those steps in case deactivation could not finish (a read-only
 * wp-config.php that became writable, a folder that was locked):
 *  - wp-content/advanced-cache.php, only when it is ours (the marker).
 *  - The WP_CACHE line, only when Fast Pages added or changed it.
 *  - wp-content/cache/fast-pages/: pages, minified files, config.php,
 *    index.php, then the empty folders. Every delete goes through the
 *    patterns of FPG_Files; nothing else in wp-content/cache is touched.
 *
 * Deleted per site (multisite: every site ONCE, the main site included):
 *  - Options fpg_settings, fpg_state, fpg_preload, fpg_preload_urgent.
 *  - Transients fpg_aui_msg_{uid} (PRG notices).
 *  - The fpg_preload_tick and fpg_upkeep events (Action Scheduler and
 *    WP-Cron).
 *
 * Kept: WP Rocket's settings (only read, never written). The rs_lang user
 * meta belongs to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The file classes are plain and need no hooks: the same patterns as the
// plugin, so uninstall deletes exactly what the plugin would.
$fpg_dir = plugin_dir_path( __FILE__ ) . 'includes/';
foreach ( array( 'class-key.php', 'class-settings.php', 'class-files.php', 'class-setup.php' ) as $fpg_file ) {
	require_once $fpg_dir . $fpg_file;
}

/** Clean one site. */
function fpg_uninstall_site(): void {
	global $wpdb;

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'fpg_preload_tick' );
		as_unschedule_all_actions( 'fpg_upkeep' );
	}
	wp_clear_scheduled_hook( 'fpg_preload_tick' );
	wp_clear_scheduled_hook( 'fpg_upkeep' );

	delete_option( 'fpg_settings' );
	delete_option( 'fpg_state' );
	delete_option( 'fpg_preload' );
	delete_option( 'fpg_preload_urgent' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_fpg_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_fpg_aui_msg_' ) . '%'
		)
	);
}

// Files first: remove_wp_cache() reads fpg_state (what we added).
FPG_Setup::remove_dropin();
FPG_Setup::remove_wp_cache();
FPG_Files::remove_all();

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $fpg_site_id ) {
		switch_to_blog( (int) $fpg_site_id );
		fpg_uninstall_site();
		restore_current_blog();
	}
} else {
	fpg_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$fpg_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login' ) as $fpg_slug ) {
	if ( 'fast-pages' !== $fpg_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $fpg_slug . '/' . $fpg_slug . '.php' ) ) {
		$fpg_suite_left = true;
		break;
	}
}
if ( ! $fpg_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$fpg_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $fpg_sites as $fpg_site_id ) {
		if ( $fpg_site_id ) {
			switch_to_blog( (int) $fpg_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $fpg_site_id ) {
			restore_current_blog();
		}
	}
}

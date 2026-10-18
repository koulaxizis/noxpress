<?php
/**
 * Data Migrator — uninstall.php (v1.2.0)
 *
 * Deleted:
 *  - Every nm_* option (nm_settings, nm_crypt, nm_schedules, nm_state, nm_post_restore) and every
 *    nm_* transient (PRG notices, installer keys, restore tokens shown
 *    once, the core checksum result).
 *  - Temporary, snapshot and undo tables of unfinished restores
 *    (nmt<6 hex>_…, nms<6 hex>_…, nmx<6 hex>_…). Deleting the plugin
 *    finalizes any open restore.
 *  - In the storage folder: jobs, work files, logs, unfinished uploads,
 *    and the backups (parts and metadata, by strict file name patterns),
 *    unless "Keep backups" is ticked in DM Settings. The folder itself
 *    goes only when nothing else is left in it, so a custom folder that
 *    holds other files is never emptied.
 *  - Stage folders wp-content/nm-stage-<hex>/ (restores across disks).
 *  - The schedule events nm_sched_tick / nm_sched_step, in Action
 *    Scheduler (group data-migrator) and in WP-Cron.
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * The rs_lang user meta belongs to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** Delete a file or a folder tree, never outside $guard. */
function nm_uninstall_rm( string $path, string $guard ): void {
	$path  = rtrim( str_replace( '\\', '/', $path ), '/' );
	$guard = rtrim( str_replace( '\\', '/', $guard ), '/' ) . '/';
	if ( '/' === $guard || 0 !== strpos( $path . '/', $guard ) || $path . '/' === $guard ) {
		return;
	}
	if ( is_link( $path ) || is_file( $path ) ) {
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		return;
	}
	if ( ! is_dir( $path ) ) {
		return;
	}
	foreach ( (array) @scandir( $path ) as $item ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( '.' !== $item && '..' !== $item ) {
			nm_uninstall_rm( $path . '/' . $item, $guard );
		}
	}
	@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

/** Our files in the storage folder. */
function nm_uninstall_storage( string $dir, bool $keep ): void {
	$dir = rtrim( str_replace( '\\', '/', $dir ), '/' );
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return;
	}
	foreach ( array( 'jobs', 'work', 'logs', 'incoming' ) as $sub ) {
		nm_uninstall_rm( $dir . '/' . $sub, $dir );
	}
	if ( ! $keep && is_dir( $dir . '/backups' ) ) {
		foreach ( (array) @scandir( $dir . '/backups' ) as $f ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$f = (string) $f;
			if ( preg_match( '/^[a-z0-9.-]{1,80}-\d{8}-\d{6}-[a-f0-9]{32}(-part\d{1,3})?\.zip$/', $f ) || preg_match( '/^[a-f0-9]{32}\.php(\.lock)?$/', $f ) || in_array( $f, array( 'index.php', '.htaccess', 'web.config' ), true ) ) {
				@unlink( $dir . '/backups/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		@rmdir( $dir . '/backups' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
	if ( is_dir( $dir . '/backups' ) ) {
		return; // Kept backups keep their protected folder.
	}
	// Our protection files, only when they are ours.
	$ours = array( 'index.php' => 'Silence is golden', '.htaccess' => 'Data Migrator: private folder', 'web.config' => 'deny users="*"' );
	foreach ( $ours as $name => $mark ) {
		$f = $dir . '/' . $name;
		if ( is_file( $f ) && filesize( $f ) < 1024 && false !== strpos( (string) file_get_contents( $f ), $mark ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- only when empty.
}

/** Clean one site. Names are hardcoded: the plugin classes are not loaded here. */
function nm_uninstall_site(): void {
	global $wpdb;

	$set = get_option( 'nm_settings', array() );
	$set = is_array( $set ) ? $set : array();
	if ( ! empty( $set['storage'] ) && is_string( $set['storage'] ) ) {
		nm_uninstall_storage( $set['storage'], ! empty( $set['keep'] ) );
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'nm_' ) . '%',
			$wpdb->esc_like( '_transient_nm_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_nm_' ) . '%'
		)
	);
	// phpcs:enable
	wp_cache_flush();
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $nm_site_id ) {
		switch_to_blog( (int) $nm_site_id );
		nm_uninstall_site();
		restore_current_blog();
	}
} else {
	nm_uninstall_site();
}

// Scheduled backup events (Action Scheduler and WP-Cron, Bible §12).
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'data-migrator' );
}
if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $nm_site_id ) {
		switch_to_blog( (int) $nm_site_id );
		wp_clear_scheduled_hook( 'nm_sched_tick' );
		wp_clear_scheduled_hook( 'nm_sched_step' );
		restore_current_blog();
	}
} else {
	wp_clear_scheduled_hook( 'nm_sched_tick' );
	wp_clear_scheduled_hook( 'nm_sched_step' );
}

// Tables of unfinished restores (one database for all sites).
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
$nm_tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', 'nm%' ) );
foreach ( $nm_tables as $nm_table ) {
	if ( preg_match( '/^nm[tsx][0-9a-f]{6}_[A-Za-z0-9_$]+$/', (string) $nm_table ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- name matched above.
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $nm_table . '`' );
	}
}

// Stage folders of restores across disks.
foreach ( (array) glob( rtrim( WP_CONTENT_DIR, '/\\' ) . '/nm-stage-*', GLOB_ONLYDIR ) as $nm_stage ) {
	if ( preg_match( '/^nm-stage-[a-f0-9]{32}$/', basename( (string) $nm_stage ) ) ) {
		nm_uninstall_rm( (string) $nm_stage, WP_CONTENT_DIR );
	}
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$nm_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'data-migrator' ) as $nm_slug ) {
	if ( 'data-migrator' !== $nm_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $nm_slug . '/' . $nm_slug . '.php' ) ) {
		$nm_suite_left = true;
		break;
	}
}

if ( ! $nm_suite_left ) {
	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$nm_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $nm_sites as $nm_site_id ) {
		if ( $nm_site_id ) {
			switch_to_blog( (int) $nm_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $nm_site_id ) {
			restore_current_blog();
		}
	}
}

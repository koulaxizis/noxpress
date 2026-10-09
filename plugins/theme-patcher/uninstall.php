<?php
/**
 * Theme Patcher — uninstall.php (v1.0.0)
 *
 * Full cleanup (nothing left after deactivate + delete):
 *  - Options: tp_settings (all themes), tp_cat_images (category image
 *    fallback map).
 *  - Transients: tp_aui_msg_{uid} (PRG notices), tp_probe_{uid} (page
 *    probe reports), tp_scan_{hash} (theme detector cache), tp_fp_check
 *    (fingerprint check throttle).
 *  - Theme Patcher never writes to theme files, theme settings, products,
 *    categories or the filesystem: no other cleanup is needed.
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * The rs_lang user meta belongs to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site (Theme Patcher options + transients only).
 * Names are hardcoded: the plugin classes are not loaded here.
 */
function tp_uninstall_site(): void {
	global $wpdb;

	delete_option( 'tp_settings' );
	delete_option( 'tp_cat_images' );

	foreach ( array( 'tp_aui_msg_', 'tp_probe_', 'tp_scan_', 'tp_fp_check' ) as $tp_prefix ) {
		// LIKE with escaped '_' (otherwise a wildcard).
		$tp_like_val = $wpdb->esc_like( '_transient_' . $tp_prefix ) . '%';
		$tp_like_to  = $wpdb->esc_like( '_transient_timeout_' . $tp_prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$tp_like_val,
				$tp_like_to
			)
		);
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $tp_site_id ) {
		switch_to_blog( (int) $tp_site_id );
		tp_uninstall_site();
		restore_current_blog();
	}
} else {
	tp_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$tp_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher' ) as $tp_slug ) {
	if ( 'theme-patcher' !== $tp_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $tp_slug . '/' . $tp_slug . '.php' ) ) {
		$tp_suite_left = true;
		break;
	}
}

if ( ! $tp_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );

	$tp_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $tp_sites as $tp_site_id ) {
		if ( $tp_site_id ) {
			switch_to_blog( (int) $tp_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $tp_site_id ) {
			restore_current_blog();
		}
	}
}

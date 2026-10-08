<?php
/**
 * Loop Fixer — uninstall.php (v1.0.0)
 *
 * Full cleanup (nothing left after deactivate + delete):
 *  - Option: lf_settings (all themes).
 *  - Transients: lf_aui_msg_{uid} (PRG notices), lf_probe_{uid} (page
 *    probe reports), lf_scan_{hash} (theme detector cache), lf_fp_check
 *    (fingerprint check throttle).
 *  - Loop Fixer never writes to theme files, products or the filesystem:
 *    no other cleanup is needed.
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * The rs_lang user meta belongs to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site (Loop Fixer options + transients only).
 * Names are hardcoded: the plugin classes are not loaded here.
 */
function lf_uninstall_site(): void {
	global $wpdb;

	delete_option( 'lf_settings' );

	foreach ( array( 'lf_aui_msg_', 'lf_probe_', 'lf_scan_', 'lf_fp_check' ) as $lf_prefix ) {
		// LIKE with escaped '_' (otherwise a wildcard).
		$lf_like_val = $wpdb->esc_like( '_transient_' . $lf_prefix ) . '%';
		$lf_like_to  = $wpdb->esc_like( '_transient_timeout_' . $lf_prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$lf_like_val,
				$lf_like_to
			)
		);
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $lf_site_id ) {
		switch_to_blog( (int) $lf_site_id );
		lf_uninstall_site();
		restore_current_blog();
	}
} else {
	lf_uninstall_site();
}

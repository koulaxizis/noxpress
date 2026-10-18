<?php
/**
 * Greeklish Permalinks — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Options glp_settings, glp_log (undo log of the bulk page).
 *  - Option glp_map (redirect table of the bulk page), unless the setting
 *    "keep the redirect table" (keep_map) is on.
 *  - Transients glp_aui_msg_{uid} (PRG notices).
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Kept (production data, Bible §12): slugs and file names the plugin
 * converted stay converted; the _wp_old_slug entries it added are
 * WordPress's own redirects and stay. AutoConvert Greeklish Permalinks'
 * options are never touched. The rs_lang user meta belongs to Revenue
 * Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function glp_uninstall_site(): void {
	global $wpdb;

	$glp_settings = get_option( 'glp_settings', array() );
	$glp_keep_map = is_array( $glp_settings ) && ! empty( $glp_settings['keep_map'] );

	delete_option( 'glp_settings' );
	delete_option( 'glp_log' );
	if ( ! $glp_keep_map ) {
		delete_option( 'glp_map' );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_glp_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_glp_aui_msg_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $glp_site_id ) {
		switch_to_blog( (int) $glp_site_id );
		glp_uninstall_site();
		restore_current_blog();
	}
} else {
	glp_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$glp_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'data-migrator' ) as $glp_slug ) {
	if ( 'greeklish-permalinks' !== $glp_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $glp_slug . '/' . $glp_slug . '.php' ) ) {
		$glp_suite_left = true;
		break;
	}
}

if ( ! $glp_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$glp_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $glp_sites as $glp_site_id ) {
		if ( $glp_site_id ) {
			switch_to_blog( (int) $glp_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $glp_site_id ) {
			restore_current_blog();
		}
	}
}

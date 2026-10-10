<?php
/**
 * Shop Filters — uninstall.php (v1.0.0)
 *
 * Full cleanup (nothing left after deactivate + delete):
 *  - Options: shf_settings, shf_sets, shf_groups_{attribute} (one per
 *    attribute with value groups), widget_shf_filters (widget instances).
 *  - The widget instances are also removed from the sidebars.
 *  - Transients: shf_aui_msg_{uid} (PRG notices).
 *  - Shop Filters never writes to products, terms or the filesystem, and
 *    keeps no table of its own: no other cleanup is needed.
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * The rs_lang user meta belongs to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site (Shop Filters options + transients only).
 * Names are hardcoded: the plugin classes are not loaded here.
 */
function shf_uninstall_site(): void {
	global $wpdb;

	delete_option( 'shf_settings' );
	delete_option( 'shf_sets' );
	delete_option( 'widget_shf_filters' );

	// shf_groups_{attribute}: LIKE with escaped '_' (otherwise a wildcard).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'shf_groups_' ) . '%'
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_shf_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_shf_aui_msg_' ) . '%'
		)
	);

	// Widget instances in the sidebars (core option, our ids only).
	$shf_sidebars = get_option( 'sidebars_widgets' );
	if ( is_array( $shf_sidebars ) ) {
		$shf_changed = false;
		foreach ( $shf_sidebars as $shf_area => $shf_widgets ) {
			if ( ! is_array( $shf_widgets ) ) {
				continue;
			}
			$shf_kept = array_values(
				array_filter(
					$shf_widgets,
					static function ( $id ) {
						return ! preg_match( '/^shf_filters-\d+$/', (string) $id );
					}
				)
			);
			if ( count( $shf_kept ) !== count( $shf_widgets ) ) {
				$shf_sidebars[ $shf_area ] = $shf_kept;
				$shf_changed               = true;
			}
		}
		if ( $shf_changed ) {
			update_option( 'sidebars_widgets', $shf_sidebars );
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $shf_site_id ) {
		switch_to_blog( (int) $shf_site_id );
		shf_uninstall_site();
		restore_current_blog();
	}
} else {
	shf_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$shf_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters' ) as $shf_slug ) {
	if ( 'shop-filters' !== $shf_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $shf_slug . '/' . $shf_slug . '.php' ) ) {
		$shf_suite_left = true;
		break;
	}
}

if ( ! $shf_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );

	$shf_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $shf_sites as $shf_site_id ) {
		if ( $shf_site_id ) {
			switch_to_blog( (int) $shf_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $shf_site_id ) {
			restore_current_blog();
		}
	}
}

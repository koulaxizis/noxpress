<?php
/**
 * Product Formats — uninstall.php (v1.0.0)
 *
 * Full cleanup (nothing left after deactivate + delete):
 *  - Options: pfm_settings, pfm_formats, pfm_rejected,
 *    pfm_upsell_snapshots (the cleanup snapshots).
 *  - Transients: pfm_aui_msg_{uid} (PRG notices), pfm_scan_{uid} (the
 *    last scan of the suggestions).
 *  - Works: the terms of the hidden pfm_work taxonomy with their term
 *    meta and product relationships (direct queries, because the taxonomy
 *    is not registered while uninstalling).
 *  - Product meta: _pfm_format, _pfm_variant.
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Upsells removed by the permanent cleanup are NOT restored: restoring is
 * a separate, visible action (Upsells tab) and stays the user's choice
 * before deleting the plugin. The products themselves are never touched.
 *
 * The rs_lang user meta belongs to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function pfm_uninstall_site(): void {
	global $wpdb;

	delete_option( 'pfm_settings' );
	delete_option( 'pfm_formats' );
	delete_option( 'pfm_rejected' );
	delete_option( 'pfm_upsell_snapshots' );

	// Transients: LIKE with escaped '_' (otherwise a wildcard).
	foreach ( array( 'pfm_aui_msg_', 'pfm_scan_' ) as $pfm_prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $pfm_prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $pfm_prefix ) . '%'
			)
		);
	}

	// Works (taxonomy pfm_work).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$pfm_rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT term_taxonomy_id, term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'pfm_work' ),
		ARRAY_A
	);
	foreach ( is_array( $pfm_rows ) ? $pfm_rows : array() as $pfm_row ) {
		$pfm_tt   = (int) $pfm_row['term_taxonomy_id'];
		$pfm_term = (int) $pfm_row['term_id'];
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->delete( $wpdb->term_relationships, array( 'term_taxonomy_id' => $pfm_tt ), array( '%d' ) );
		$wpdb->delete( $wpdb->term_taxonomy, array( 'term_taxonomy_id' => $pfm_tt ), array( '%d' ) );
		// The term row and its meta go only when no other taxonomy uses the term (shared terms before WP 4.4).
		$pfm_shared = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d", $pfm_term ) );
		if ( 0 === $pfm_shared ) {
			$wpdb->delete( $wpdb->termmeta, array( 'term_id' => $pfm_term ), array( '%d' ) );
			$wpdb->delete( $wpdb->terms, array( 'term_id' => $pfm_term ), array( '%d' ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $pfm_term, 'terms' );
		wp_cache_delete( $pfm_term, 'term_meta' );
	}
	delete_option( 'pfm_work_children' );
	if ( function_exists( 'wp_cache_set_last_changed' ) ) {
		wp_cache_set_last_changed( 'terms' );
	}

	delete_post_meta_by_key( '_pfm_format' );
	delete_post_meta_by_key( '_pfm_variant' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $pfm_site_id ) {
		switch_to_blog( (int) $pfm_site_id );
		pfm_uninstall_site();
		restore_current_blog();
	}
} else {
	pfm_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$pfm_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats' ) as $pfm_slug ) {
	if ( 'product-formats' !== $pfm_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $pfm_slug . '/' . $pfm_slug . '.php' ) ) {
		$pfm_suite_left = true;
		break;
	}
}

if ( ! $pfm_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );

	$pfm_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $pfm_sites as $pfm_site_id ) {
		if ( $pfm_site_id ) {
			switch_to_blog( (int) $pfm_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $pfm_site_id ) {
			restore_current_blog();
		}
	}
}

<?php
/**
 * Trade Feed Import — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Options tfi_settings, tfi_pass (the sealed password), tfi_cats,
 *    tfi_log, tfi_job, tfi_last, tfi_catalog, tfi_catalog_at, tfi_wait.
 *  - Transients tfi_aui_msg_{uid} (PRG notices), tfi_probe (connection
 *    check), tfi_lock.
 *  - Scheduled actions of the group trade-feed-import (Action Scheduler)
 *    and the WP-Cron hooks tfi_step, tfi_safety, tfi_daily.
 *  - Every _tfi_* post meta (products and cover attachments).
 *  - Only when "delete the products it brought" is ticked (off by
 *    default): the products the plugin created (_tfi_source, not linked),
 *    in batches. Products that were linked (they existed before) always
 *    stay. Images (attachments) and categories / terms always stay.
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * The rs_lang user meta belongs to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function tfi_uninstall_site(): void {
	global $wpdb;

	$settings = get_option( 'tfi_settings', array() );
	$delete   = is_array( $settings ) && ! empty( $settings['purge'] );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'trade-feed-import' );
	}
	foreach ( array( 'tfi_step', 'tfi_safety', 'tfi_daily' ) as $tfi_hook ) {
		wp_clear_scheduled_hook( $tfi_hook );
	}

	if ( $delete ) {
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		}
		$done = array();
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
			$ids = $wpdb->get_col(
				"SELECT DISTINCT s.post_id FROM {$wpdb->postmeta} s
				 INNER JOIN {$wpdb->posts} p ON p.ID = s.post_id AND p.post_type = 'product'
				 LEFT JOIN {$wpdb->postmeta} l ON l.post_id = s.post_id AND l.meta_key = '_tfi_linked'
				 WHERE s.meta_key = '_tfi_source' AND ( l.meta_value IS NULL OR l.meta_value <> '1' )
				 ORDER BY s.post_id ASC LIMIT 100"
			);
			$ids = array_diff( array_map( 'intval', (array) $ids ), $done );
			foreach ( $ids as $id ) {
				$done[] = $id;
				$p      = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
				if ( $p ) {
					$p->delete( true );
				} else {
					wp_delete_post( $id, true );
				}
			}
		} while ( $ids );
	}

	foreach ( array( 'tfi_settings', 'tfi_pass', 'tfi_cats', 'tfi_log', 'tfi_job', 'tfi_last', 'tfi_catalog', 'tfi_catalog_at', 'tfi_wait' ) as $tfi_opt ) {
		delete_option( $tfi_opt );
	}
	delete_transient( 'tfi_probe' );
	delete_transient( 'tfi_lock' );

	foreach ( array( '_tfi_source', '_tfi_id', '_tfi_isbn', '_tfi_synced', '_tfi_hash', '_tfi_locked', '_tfi_attr_keys', '_tfi_linked', '_tfi_gone', '_tfi_gone_status', '_tfi_trade', '_tfi_cost', '_tfi_release', '_tfi_por', '_tfi_img_src' ) as $tfi_key ) {
		delete_post_meta_by_key( $tfi_key );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_tfi_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_tfi_aui_msg_' ) . '%'
		)
	);

	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $tfi_site_id ) {
		switch_to_blog( (int) $tfi_site_id );
		tfi_uninstall_site();
		restore_current_blog();
	}
} else {
	tfi_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$tfi_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login', 'trade-feed-import' ) as $tfi_slug ) {
	if ( 'trade-feed-import' !== $tfi_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $tfi_slug . '/' . $tfi_slug . '.php' ) ) {
		$tfi_suite_left = true;
		break;
	}
}

if ( ! $tfi_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$tfi_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $tfi_sites as $tfi_site_id ) {
		if ( $tfi_site_id ) {
			switch_to_blog( (int) $tfi_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $tfi_site_id ) {
			restore_current_blog();
		}
	}
}

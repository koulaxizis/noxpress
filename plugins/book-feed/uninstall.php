<?php
/**
 * Book Feed — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Options bkf_settings, bkf_rules, bkf_seen, bkf_pass (the sealed
 *    password), bkf_log, bkf_job, bkf_lock, bkf_last, bkf_bulk, bkf_bulk_last.
 *  - Transients bkf_aui_msg_{uid} (PRG notices), bkf_guard_{uid} (price
 *    warning), bkf_scheme (http / https memory).
 *  - Scheduled actions of the group book-feed (Action Scheduler) and the
 *    WP-Cron hooks bkf_step, bkf_safety, bkf_daily, bkf_bulk_step.
 *  - Every _bkf_* post meta (products and cover attachments), and the
 *    term meta bkf_publisher / bkf_logo of publisher subcategories.
 *  - Only when "Also delete the imported products" is ticked (off by
 *    default): the products the plugin created (_bkf_source, not linked),
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
function bkf_uninstall_site(): void {
	global $wpdb;

	$settings = get_option( 'bkf_settings', array() );
	$delete   = is_array( $settings ) && ! empty( $settings['delete_products'] );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'book-feed' );
	}
	foreach ( array( 'bkf_step', 'bkf_safety', 'bkf_daily', 'bkf_bulk_step' ) as $bkf_hook ) {
		wp_clear_scheduled_hook( $bkf_hook );
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
				 LEFT JOIN {$wpdb->postmeta} l ON l.post_id = s.post_id AND l.meta_key = '_bkf_linked'
				 WHERE s.meta_key = '_bkf_source' AND ( l.meta_value IS NULL OR l.meta_value <> '1' )
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

	foreach ( array( 'bkf_settings', 'bkf_rules', 'bkf_seen', 'bkf_pass', 'bkf_log', 'bkf_job', 'bkf_lock', 'bkf_last', 'bkf_bulk', 'bkf_bulk_last' ) as $bkf_opt ) {
		delete_option( $bkf_opt );
	}
	delete_transient( 'bkf_scheme' );

	foreach ( array( '_bkf_source', '_bkf_source_id', '_bkf_isbn', '_bkf_synced', '_bkf_updated', '_bkf_hash', '_bkf_attr_keys', '_bkf_rel_isbn', '_bkf_rule', '_bkf_linked', '_bkf_gone', '_bkf_gone_status', '_bkf_cover_src', '_bkf_back_src', '_bkf_feed_price', '_bkf_ptype', '_bkf_fixed_until', '_bkf_locked', '_bkf_img_fail', '_bkf_pub_price', '_bkf_pub_at', '_bkf_pub_note', '_bkf_pub_url', '_bkf_pub_cover_src', '_bkf_pub_cover_no', '_bkf_pub_cat', '_bkf_pub_status' ) as $bkf_key ) {
		delete_post_meta_by_key( $bkf_key );
	}
	// The publisher subcategories stay; only our notes on them go (images stay).
	foreach ( array( 'bkf_publisher', 'bkf_logo' ) as $bkf_key ) {
		delete_metadata( 'term', 0, $bkf_key, '', true );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_bkf_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_bkf_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_bkf_guard_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_bkf_guard_' ) . '%',
			$wpdb->esc_like( '_transient_bkf_pp_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_bkf_pp_' ) . '%'
		)
	);

	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $bkf_site_id ) {
		switch_to_blog( (int) $bkf_site_id );
		bkf_uninstall_site();
		restore_current_blog();
	}
} else {
	bkf_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$bkf_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed' ) as $bkf_slug ) {
	if ( 'book-feed' !== $bkf_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $bkf_slug . '/' . $bkf_slug . '.php' ) ) {
		$bkf_suite_left = true;
		break;
	}
}

if ( ! $bkf_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$bkf_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $bkf_sites as $bkf_site_id ) {
		if ( $bkf_site_id ) {
			switch_to_blog( (int) $bkf_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $bkf_site_id ) {
			restore_current_blog();
		}
	}
}

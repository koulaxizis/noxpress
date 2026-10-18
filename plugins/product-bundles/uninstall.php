<?php
/**
 * Product Bundles — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Options pbn_settings, pbn_index (reverse index, derived),
 *    pbn_build_pending.
 *  - Transients pbn_aui_msg_{uid} (PRG notices), pbn_dropped_{uid}.
 *  - Post meta _pbn_value_max, _pbn_price_max, _pbn_avail (derived data).
 *  - Action Scheduler jobs pbn_sync / pbn_sync_all (group product-bundles).
 *  - Only when "Delete … when the plugin is deleted" is ticked in Tools
 *    (delete_data): post meta _pbn_items, _pbn_discount_type, _pbn_discount,
 *    _pbn_max (the contents and discount of bundles) and _pbn_backup
 *    (conversion backups).
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Kept on purpose (Bible §12):
 *  - The bundles themselves (products, with their pages, images and
 *    reviews). Deactivation already marked them "Out of stock", so
 *    WooCommerce never sells one without its contents.
 *  - Order line meta (_pbn and the visible "Bundle: …"): order history.
 *  - Without the tick, the contents and discount of bundles, so a new
 *    install finds them. The rs_lang user meta belongs to Revenue Splitter
 *    and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function pbn_uninstall_site(): void {
	global $wpdb;

	$pbn_settings = get_option( 'pbn_settings', array() );
	$pbn_all      = is_array( $pbn_settings ) && ! empty( $pbn_settings['delete_data'] );

	delete_option( 'pbn_settings' );
	delete_option( 'pbn_index' );
	delete_option( 'pbn_build_pending' );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	foreach ( array( 'pbn_aui_msg_', 'pbn_dropped_' ) as $pbn_prefix ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $pbn_prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $pbn_prefix ) . '%'
			)
		);
	}
	foreach ( array( '_pbn_value_max', '_pbn_price_max', '_pbn_avail' ) as $pbn_key ) {
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $pbn_key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	if ( $pbn_all ) {
		foreach ( array( '_pbn_items', '_pbn_discount_type', '_pbn_discount', '_pbn_max', '_pbn_backup' ) as $pbn_key ) {
			$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $pbn_key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
	}
	// phpcs:enable

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'pbn_sync', null, 'product-bundles' );
		as_unschedule_all_actions( 'pbn_sync_all', null, 'product-bundles' );
	} else {
		$pbn_actions = $wpdb->prefix . 'actionscheduler_actions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pbn_actions ) ) === $pbn_actions ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
			$wpdb->query( "DELETE FROM {$pbn_actions} WHERE hook IN ('pbn_sync','pbn_sync_all') AND status = 'pending'" );
		}
	}

	// Rows were deleted with SQL: drop the cached meta (WordPress < 6.1: whole cache).
	if ( function_exists( 'wp_cache_flush_group' ) && wp_cache_supports( 'flush_group' ) ) {
		wp_cache_flush_group( 'post_meta' );
	} else {
		wp_cache_flush();
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $pbn_site_id ) {
		switch_to_blog( (int) $pbn_site_id );
		pbn_uninstall_site();
		restore_current_blog();
	}
} else {
	pbn_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$pbn_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras' ) as $pbn_slug ) {
	if ( 'product-bundles' !== $pbn_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $pbn_slug . '/' . $pbn_slug . '.php' ) ) {
		$pbn_suite_left = true;
		break;
	}
}

if ( ! $pbn_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$pbn_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $pbn_sites as $pbn_site_id ) {
		if ( $pbn_site_id ) {
			switch_to_blog( (int) $pbn_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $pbn_site_id ) {
			restore_current_blog();
		}
	}
}

<?php
/**
 * Wish Shelf — uninstall.php (v1.1.0)
 *
 * Deleted:
 *  - Options wsh_settings, wsh_db (schema version; a new install runs
 *    dbDelta again, which keeps a kept table), wsh_flush.
 *  - Transients wsh_aui_msg_{uid} (PRG notices), wsh_rl_{uid} (rate limit)
 *    wsh_upgrading (schema upgrade lock) and wsh_alerts_chk (hourly check
 *    of the alerts job).
 *  - Email alerts: the settings of the email (option
 *    woocommerce_wsh_alert_settings), the customers' opt-in (user meta
 *    wsh_alerts) and the queued jobs (Action Scheduler hook wsh_alerts_run).
 *  - User option wsh_csv (remembered CSV format).
 *  - Product meta _wsh_count, _wsh_bought (derived counters).
 *  - Order line meta _wsh_from and the order flag _wsh_counted (posts and
 *    HPOS tables).
 *  - Only when "Delete the customers' lists …" is ticked in Tools
 *    (delete_data): the tables {prefix}wsh_items and {prefix}wsh_lists
 *    (named lists) and the share keys (user meta wsh_share).
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Kept on purpose (Bible §12, content the customers made): without that
 * tick the lists and share links stay, so a new install finds them. A
 * guest's list lives in their browser and is not the store's to delete.
 * The rs_lang user meta belongs to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function wsh_uninstall_site(): void {
	global $wpdb;

	$wsh_settings = get_option( 'wsh_settings', array() );
	$wsh_all      = is_array( $wsh_settings ) && ! empty( $wsh_settings['delete_data'] );

	delete_option( 'wsh_settings' );
	delete_option( 'wsh_db' );
	delete_option( 'wsh_flush' );
	delete_option( 'woocommerce_wsh_alert_settings' );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'wsh_alerts_run' );
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery -- one-off uninstall cleanup.
	foreach ( array( 'wsh_aui_msg_', 'wsh_rl_', 'wsh_upgrading', 'wsh_alerts_chk' ) as $wsh_t ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $wsh_t ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $wsh_t ) . '%'
			)
		);
	}
	delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . 'wsh_csv', '', true );
	delete_metadata( 'user', 0, 'wsh_alerts', '', true );
	foreach ( array( '_wsh_count', '_wsh_bought', '_wsh_counted' ) as $wsh_key ) {
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $wsh_key ) );
	}
	$wsh_hpos = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wsh_hpos ) ) ) === $wsh_hpos ) {
		$wpdb->delete( $wsh_hpos, array( 'meta_key' => '_wsh_counted' ) );
	}
	$wsh_itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wsh_itemmeta ) ) ) === $wsh_itemmeta ) {
		$wpdb->delete( $wsh_itemmeta, array( 'meta_key' => '_wsh_from' ) );
	}

	if ( $wsh_all ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wsh_items" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wsh_lists" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		delete_metadata( 'user', 0, 'wsh_share', '', true );
	}
	// phpcs:enable

	// Rows were deleted with SQL: drop the cached meta (WordPress < 6.1: whole cache).
	if ( function_exists( 'wp_cache_flush_group' ) && wp_cache_supports( 'flush_group' ) ) {
		wp_cache_flush_group( 'post_meta' );
	} else {
		wp_cache_flush();
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $wsh_site_id ) {
		switch_to_blog( (int) $wsh_site_id );
		wsh_uninstall_site();
		restore_current_blog();
	}
} else {
	wsh_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$wsh_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'book-feed' ) as $wsh_slug ) {
	if ( 'wish-shelf' !== $wsh_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $wsh_slug . '/' . $wsh_slug . '.php' ) ) {
		$wsh_suite_left = true;
		break;
	}
}

if ( ! $wsh_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$wsh_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $wsh_sites as $wsh_site_id ) {
		if ( $wsh_site_id ) {
			switch_to_blog( (int) $wsh_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $wsh_site_id ) {
			restore_current_blog();
		}
	}
}

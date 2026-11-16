<?php
/**
 * Back in Stock — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Table {prefix}bis_requests (every request and sent hash).
 *  - Options bis_settings, bis_db_version, woocommerce_bis_confirm_settings
 *    and woocommerce_bis_restock_settings (the e-mails' own settings).
 *  - Transients bis_aui_msg_{uid} (PRG notices), bis_ri_* (rate limits),
 *    bis_daily_ok.
 *  - Scheduled actions bis_send and bis_daily (group back-in-stock).
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * No product, order or customer is touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function bis_uninstall_site(): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- own table.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bis_requests" );

	delete_option( 'bis_settings' );
	delete_option( 'bis_db_version' );
	delete_option( 'woocommerce_bis_confirm_settings' );
	delete_option( 'woocommerce_bis_restock_settings' );
	delete_transient( 'bis_daily_ok' );

	foreach ( array( 'bis_aui_msg_', 'bis_ri_' ) as $bis_prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $bis_prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $bis_prefix ) . '%'
			)
		);
	}

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'bis_send', array(), 'back-in-stock' );
		as_unschedule_all_actions( 'bis_daily', array(), 'back-in-stock' );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $bis_site_id ) {
		switch_to_blog( (int) $bis_site_id );
		bis_uninstall_site();
		restore_current_blog();
	}
} else {
	bis_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$bis_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login', 'trade-feed-import', 'lean-images', 'redirects', 'search-signals', 'production-ledger', 'product-tabs', 'product-feed', 'invoice-details', 'back-in-stock', 'events' ) as $bis_slug ) {
	if ( 'back-in-stock' !== $bis_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $bis_slug . '/' . $bis_slug . '.php' ) ) {
		$bis_suite_left = true;
		break;
	}
}

if ( ! $bis_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_option( 'noxpress_trust' ); // Core 1.3.0: newest signed list serial.
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$bis_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $bis_sites as $bis_site_id ) {
		if ( $bis_site_id ) {
			switch_to_blog( (int) $bis_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $bis_site_id ) {
			restore_current_blog();
		}
	}
}

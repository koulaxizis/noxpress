<?php
/**
 * Maintenance Mode — uninstall.php (v1.0.0)
 *
 * Deleted: options mmd_settings (switch, texts, colours, access),
 * mmd_links (preview links), mmd_purged (cache state), the transients
 * mmd_aui_msg_{uid} and the cron event mmd_edge. The preview cookie lives
 * only in visitors' browsers and expires with its link (links are gone,
 * so it is worth nothing). Multisite: every site once.
 *
 * Nothing of another plugin is touched (SeedProd and WP Maintenance
 * options are only ever read). The rs_lang user meta belongs to Revenue
 * Splitter (and to the whole suite).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** Clean one site. Names are hardcoded: the plugin classes are not loaded here. */
function mmd_uninstall_site(): void {
	global $wpdb;

	wp_clear_scheduled_hook( 'mmd_edge' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_mmd_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_mmd_aui_msg_' ) . '%'
		)
	);
	foreach ( array( 'mmd_settings', 'mmd_links', 'mmd_purged' ) as $mmd_opt ) {
		delete_option( $mmd_opt );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $mmd_site_id ) {
		switch_to_blog( (int) $mmd_site_id );
		mmd_uninstall_site();
		restore_current_blog();
	}
} else {
	mmd_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$mmd_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login', 'trade-feed-import', 'lean-images', 'redirects', 'search-signals', 'production-ledger', 'product-tabs', 'product-feed', 'invoice-details', 'activity-log', 'mail-relay', 'login-guard', 'forms', 'live-search', 'back-in-stock', 'events', 'gift-cards', 'ebook-delivery', 'maintenance-mode', 'discount-rules', 'visitor-language' ) as $mmd_slug ) {
	if ( 'maintenance-mode' !== $mmd_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $mmd_slug . '/' . $mmd_slug . '.php' ) ) {
		$mmd_suite_left = true;
		break;
	}
}

if ( ! $mmd_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_option( 'noxpress_trust' ); // Core 1.3.0: newest signed list serial.
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.
	delete_metadata( 'user', 0, 'noxpress_menu', '', true ); // Core 1.5.0: menu favourites, recent pages, full menu.

	$mmd_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $mmd_sites as $mmd_site_id ) {
		if ( $mmd_site_id ) {
			switch_to_blog( (int) $mmd_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $mmd_site_id ) {
			restore_current_blog();
		}
	}
}

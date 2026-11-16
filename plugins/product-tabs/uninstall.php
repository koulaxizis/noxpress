<?php
/**
 * Product Tabs — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Option ptb_settings (mode, texts, order).
 *  - Transients ptb_aui_msg_{uid} (PRG notices).
 *  - Only when "delete on uninstall" was ticked: the shared tabs (option
 *    ptb_shared) and every product's tabs (post meta _ptb_tabs). Tabs are
 *    shop content, so they stay otherwise (Bible §12).
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * The meta of "Custom Product Tabs for WooCommerce" (wb_custom_tabs),
 * read by the import, is not ours and is never touched. The rs_lang user
 * meta belongs to Revenue Splitter and is not touched either.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function ptb_uninstall_site(): void {
	global $wpdb;

	$settings = get_option( 'ptb_settings', array() );
	$wipe     = is_array( $settings ) && ! empty( $settings['wipe'] );

	delete_option( 'ptb_settings' );

	if ( $wipe ) {
		delete_option( 'ptb_shared' );
		delete_metadata( 'post', 0, '_ptb_tabs', '', true );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_ptb_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ptb_aui_msg_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $ptb_site_id ) {
		switch_to_blog( (int) $ptb_site_id );
		ptb_uninstall_site();
		restore_current_blog();
	}
} else {
	ptb_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$ptb_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login', 'trade-feed-import', 'lean-images', 'redirects', 'search-signals', 'production-ledger', 'product-tabs', 'product-feed', 'live-search' ) as $ptb_slug ) {
	if ( 'product-tabs' !== $ptb_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $ptb_slug . '/' . $ptb_slug . '.php' ) ) {
		$ptb_suite_left = true;
		break;
	}
}

if ( ! $ptb_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_option( 'noxpress_trust' ); // Core 1.3.0: newest signed list serial.
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$ptb_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $ptb_sites as $ptb_site_id ) {
		if ( $ptb_site_id ) {
			switch_to_blog( (int) $ptb_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $ptb_site_id ) {
			restore_current_blog();
		}
	}
}

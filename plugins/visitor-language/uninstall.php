<?php
/**
 * Visitor Language — uninstall.php (v1.0.3)
 *
 * Deleted: the option vlg_settings, the transients vlg_aui_msg_{uid} and
 * the English texts of every post (post meta _vlg_title, _vlg_excerpt,
 * _vlg_content) and the language mark of orders (order meta _vlg_lang, in
 * postmeta and, with HPOS, in wc_orders_meta). Multisite: every site once. Nothing else is touched: the
 * rs_lang user meta belongs to Revenue Splitter (and to the whole suite).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** Clean one site. Names are hardcoded: the plugin classes are not loaded here. */
function vlg_uninstall_site(): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_vlg_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_vlg_aui_msg_' ) . '%'
		)
	);
	delete_option( 'vlg_settings' );
	foreach ( array( '_vlg_title', '_vlg_excerpt', '_vlg_content', '_vlg_lang' ) as $vlg_key ) {
		delete_post_meta_by_key( $vlg_key );
	}
	$vlg_orders_meta = $wpdb->prefix . 'wc_orders_meta';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup; the table name is ours to build.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $vlg_orders_meta ) ) ) === $vlg_orders_meta ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$vlg_orders_meta} WHERE meta_key = %s", '_vlg_lang' ) );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $vlg_site_id ) {
		switch_to_blog( (int) $vlg_site_id );
		vlg_uninstall_site();
		restore_current_blog();
	}
} else {
	vlg_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$vlg_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login', 'trade-feed-import', 'lean-images', 'redirects', 'search-signals', 'production-ledger', 'product-tabs', 'product-feed', 'invoice-details', 'activity-log', 'mail-relay', 'login-guard', 'forms', 'live-search', 'back-in-stock', 'events', 'gift-cards', 'ebook-delivery', 'maintenance-mode', 'discount-rules', 'visitor-language' ) as $vlg_slug ) {
	if ( 'visitor-language' !== $vlg_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $vlg_slug . '/' . $vlg_slug . '.php' ) ) {
		$vlg_suite_left = true;
		break;
	}
}

if ( ! $vlg_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_option( 'noxpress_trust' ); // Core 1.3.0: newest signed list serial.
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.
	delete_metadata( 'user', 0, 'noxpress_menu', '', true ); // Core 1.5.0: menu favourites, recent pages, full menu.

	$vlg_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $vlg_sites as $vlg_site_id ) {
		if ( $vlg_site_id ) {
			switch_to_blog( (int) $vlg_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $vlg_site_id ) {
			restore_current_blog();
		}
	}
}

<?php
/**
 * Production Ledger — uninstall.php (v1.0.0)
 *
 * Always deleted (settings and caches, never the books):
 *  - Options pl_settings, pl_pf_key (the sealed Printful key), pl_pf_log,
 *    pl_cache_ver, pl_oros_pulled.
 *  - Transients pl_aui_msg_{uid}, pl_undo_{uid}, pl_rep_{hash}.
 *  - Queued Printful look-ups (hook pl_printful_fetch, Action Scheduler
 *    group production-ledger, or WP-Cron).
 * Deleted ONLY when «Delete all entries when the plugin is deleted»
 * (pl_settings.drop) is ticked (Bible §12: the entries are the shop's
 * books and stay by default):
 *  - Table {prefix}pl_entries, option pl_db, order meta _pl_pf.
 * Multisite: every site ONCE (the main site included in the loop).
 *
 * Nothing of another plugin is touched: orders, products and Revenue
 * Splitter data are only ever read. The rs_lang user meta belongs to
 * Revenue Splitter.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function pl_uninstall_site(): void {
	global $wpdb;

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'pl_printful_fetch', array(), 'production-ledger' );
	}
	wp_unschedule_hook( 'pl_printful_fetch' );

	foreach ( array( 'pl_aui_msg_', 'pl_undo_', 'pl_rep_' ) as $pl_prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $pl_prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $pl_prefix ) . '%'
			)
		);
	}

	$pl_settings = get_option( 'pl_settings', array() );
	$pl_drop     = is_array( $pl_settings ) && ! empty( $pl_settings['drop'] );

	foreach ( array( 'pl_settings', 'pl_pf_key', 'pl_pf_log', 'pl_cache_ver', 'pl_oros_pulled' ) as $pl_opt ) {
		delete_option( $pl_opt );
	}

	if ( ! $pl_drop ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- our own table.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pl_entries" );
	delete_option( 'pl_db' );

	// Our order meta, in the posts table and (HPOS) in wc_orders_meta.
	delete_metadata( 'post', 0, '_pl_pf', '', true );
	$pl_hpos = $wpdb->prefix . 'wc_orders_meta';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	if ( $pl_hpos === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $pl_hpos ) ) ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the prefix.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$pl_hpos} WHERE meta_key = %s", '_pl_pf' ) );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $pl_site_id ) {
		switch_to_blog( (int) $pl_site_id );
		pl_uninstall_site();
		restore_current_blog();
	}
} else {
	pl_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$pl_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login', 'trade-feed-import', 'lean-images', 'redirects', 'search-signals', 'production-ledger' ) as $pl_slug ) {
	if ( 'production-ledger' !== $pl_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $pl_slug . '/' . $pl_slug . '.php' ) ) {
		$pl_suite_left = true;
		break;
	}
}

if ( ! $pl_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$pl_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $pl_sites as $pl_site_id ) {
		if ( $pl_site_id ) {
			switch_to_blog( (int) $pl_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $pl_site_id ) {
			restore_current_blog();
		}
	}
}

<?php
/**
 * Discount Rules — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Options dsr_settings (settings, texts), dsr_rules (the rules),
 *    dsr_next_id, dsr_log (change history) and dsr_db (table version).
 *  - Transients dsr_aui_msg_{uid} (PRG notices) and dsr_form_{uid} (an
 *    invalid rule form kept for the next page).
 *  - The price history table {prefix}dsr_prices (Omnibus).
 *  - The Action Scheduler jobs (dsr_price_day, dsr_price_batch,
 *    dsr_rule_edge), when Action Scheduler is loaded.
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Kept on purpose (Bible §12, store records): the rule rows on the order
 * lines (_dsr, the readable «Offer: …» row) and on the order's discount
 * line (_dsr_rule), and the order flag _dsr_applied. They belong to the
 * orders and leave with them. The rs_lang user meta belongs to Revenue
 * Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function dsr_uninstall_site(): void {
	global $wpdb;

	foreach ( array( 'dsr_settings', 'dsr_rules', 'dsr_next_id', 'dsr_log', 'dsr_db' ) as $dsr_opt ) {
		delete_option( $dsr_opt );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_dsr_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_dsr_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_dsr_form_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_dsr_form_' ) . '%'
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- own table, uninstall.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'dsr_prices' );

	// Background jobs. Action Scheduler is loaded when WooCommerce is active;
	// without it the rows stay in its tables and never run (nobody listens).
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		foreach ( array( 'dsr_price_day', 'dsr_price_batch', 'dsr_rule_edge' ) as $dsr_hook ) {
			as_unschedule_all_actions( $dsr_hook );
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $dsr_site_id ) {
		switch_to_blog( (int) $dsr_site_id );
		dsr_uninstall_site();
		restore_current_blog();
	}
} else {
	dsr_uninstall_site();
}
// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$dsr_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login', 'trade-feed-import', 'lean-images', 'redirects', 'search-signals', 'production-ledger', 'product-tabs', 'product-feed', 'invoice-details', 'activity-log', 'mail-relay', 'login-guard', 'forms', 'live-search', 'back-in-stock', 'events', 'gift-cards', 'ebook-delivery', 'maintenance-mode', 'discount-rules', 'visitor-language' ) as $dsr_slug ) {
	if ( 'discount-rules' !== $dsr_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $dsr_slug . '/' . $dsr_slug . '.php' ) ) {
		$dsr_suite_left = true;
		break;
	}
}

if ( ! $dsr_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_option( 'noxpress_trust' ); // Core 1.3.0: newest signed list serial.
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.
	delete_metadata( 'user', 0, 'noxpress_menu', '', true ); // Core 1.5.0: menu favourites, recent pages, full menu.

	$dsr_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $dsr_sites as $dsr_site_id ) {
		if ( $dsr_site_id ) {
			switch_to_blog( (int) $dsr_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $dsr_site_id ) {
			restore_current_blog();
		}
	}
}

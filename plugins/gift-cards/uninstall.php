<?php
/**
 * Gift Cards — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Option gfc_settings (settings, texts) and gfc_flush_rewrite.
 *  - Table gfc_limits (attempt counters) and the transients gfc_aui_msg_*,
 *    gfc_alert_*, gfc_site_closed, gfc_db_lock.
 *  - The background jobs (Action Scheduler group gift-cards: deliveries
 *    and the daily job).
 *  - Only when "Delete all gift cards …" is ticked in Tools AND the word
 *    ΔΙΑΓΡΑΦΗ was typed (Bible §12): the tables gfc_cards and gfc_ledger,
 *    the HMAC key gfc_hmac_key and gfc_db_version.
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Kept on purpose (Bible §12, balances the store owes): by default the
 * cards, their ledger and the HMAC key, so every code works again if the
 * plugin is installed again. Always kept: the order meta (_gfc_cards, _gfc_reduced,
 * _gfc_has, the gift-card line meta) and the gift-card products, which
 * are part of the store's records. The rs_lang user meta belongs to
 * Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function gfc_uninstall_site(): void {
	global $wpdb;

	$gfc_settings = get_option( 'gfc_settings', array() );
	$gfc_delete   = is_array( $gfc_settings ) && isset( $gfc_settings['delete_data'] ) && 'ΔΙΑΓΡΑΦΗ' === $gfc_settings['delete_data'];

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		// By hook only and by group only (Bible §12): an args array would
		// match only the jobs without args.
		as_unschedule_all_actions( 'gfc_daily' );
		as_unschedule_all_actions( 'gfc_deliver' );
		as_unschedule_all_actions( '', array(), 'gift-cards' );
	}
	wp_clear_scheduled_hook( 'gfc_deliver' );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-off uninstall cleanup of own tables.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gfc_limits" );
	if ( $gfc_delete ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gfc_ledger" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gfc_cards" );
		delete_option( 'gfc_hmac_key' );
		delete_option( 'gfc_db_version' );
	} else {
		// gfc_limits is gone: the next activation runs dbDelta again.
		delete_option( 'gfc_db_version' );
	}

	delete_option( 'gfc_settings' );
	delete_option( 'gfc_flush_rewrite' );

	foreach ( array( 'gfc_aui_msg_', 'gfc_alert_', 'gfc_site_closed', 'gfc_db_lock' ) as $gfc_t ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $gfc_t ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $gfc_t ) . '%'
			)
		);
	}
	// phpcs:enable
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $gfc_site_id ) {
		switch_to_blog( (int) $gfc_site_id );
		gfc_uninstall_site();
		restore_current_blog();
	}
} else {
	gfc_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$gfc_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login', 'trade-feed-import', 'lean-images', 'redirects', 'search-signals', 'production-ledger', 'gift-cards' ) as $gfc_slug ) {
	if ( 'gift-cards' !== $gfc_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $gfc_slug . '/' . $gfc_slug . '.php' ) ) {
		$gfc_suite_left = true;
		break;
	}
}

if ( ! $gfc_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_option( 'noxpress_trust' ); // Core 1.3.0: newest signed list serial.
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$gfc_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $gfc_sites as $gfc_site_id ) {
		if ( $gfc_site_id ) {
			switch_to_blog( (int) $gfc_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $gfc_site_id ) {
			restore_current_blog();
		}
	}
}

<?php
/**
 * Events — uninstall.php (v1.0.0)
 *
 * Always deleted:
 *  - Options evt_settings and evt_flush.
 *  - Transients evt_aui_msg_{uid} (PRG notices).
 *  - Scheduled jobs evt_after_end (Action Scheduler group events, WP-Cron).
 *
 * Only when the setting «Delete the events on uninstall» is on (Bible §12
 * deviation, see events.php): every nox_event post (any status, with its
 * meta and revisions) and every nox_venue term (with its meta). Otherwise
 * the events and venues stay: they are content the site wrote.
 *
 * Multisite: every site ONCE (the main site included in the loop). No
 * product, creator or other post is touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function evt_uninstall_site(): void {
	global $wpdb;

	$evt_settings = get_option( 'evt_settings', array() );
	$evt_all      = is_array( $evt_settings ) && ! empty( $evt_settings['delete_all'] );

	if ( $evt_all ) {
		// The type and the taxonomy are not registered during uninstall.
		if ( ! post_type_exists( 'nox_event' ) ) {
			register_post_type( 'nox_event', array( 'public' => false ) );
		}
		if ( ! taxonomy_exists( 'nox_venue' ) ) {
			register_taxonomy( 'nox_venue', 'nox_event', array( 'public' => false ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup, every status.
		$evt_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'nox_event' ) );
		foreach ( (array) $evt_ids as $evt_id ) {
			wp_delete_post( (int) $evt_id, true );
		}
		$evt_terms = get_terms(
			array(
				'taxonomy'   => 'nox_venue',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		if ( is_array( $evt_terms ) ) {
			foreach ( $evt_terms as $evt_term ) {
				wp_delete_term( (int) $evt_term, 'nox_venue' );
			}
		}
	}

	delete_option( 'evt_settings' );
	delete_option( 'evt_flush' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_evt_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_evt_aui_msg_' ) . '%'
		)
	);

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'evt_after_end' ); // By hook: every args set.
	}
	if ( function_exists( 'wp_unschedule_hook' ) ) {
		wp_unschedule_hook( 'evt_after_end' );
	}
	flush_rewrite_rules( false );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $evt_site_id ) {
		switch_to_blog( (int) $evt_site_id );
		evt_uninstall_site();
		restore_current_blog();
	}
} else {
	evt_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$evt_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login', 'trade-feed-import', 'lean-images', 'redirects', 'search-signals', 'production-ledger', 'product-tabs', 'product-feed', 'invoice-details', 'activity-log', 'mail-relay', 'login-guard', 'back-in-stock', 'events' ) as $evt_slug ) {
	if ( 'events' !== $evt_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $evt_slug . '/' . $evt_slug . '.php' ) ) {
		$evt_suite_left = true;
		break;
	}
}

if ( ! $evt_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_option( 'noxpress_trust' ); // Core 1.3.0: newest signed list serial.
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$evt_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $evt_sites as $evt_site_id ) {
		if ( $evt_site_id ) {
			switch_to_blog( (int) $evt_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $evt_site_id ) {
			restore_current_blog();
		}
	}
}

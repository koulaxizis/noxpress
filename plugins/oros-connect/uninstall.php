<?php
/**
 * orOS Connect — uninstall.php (v1.1.0)
 *
 * Deleted:
 *  - Options orc_settings, orc_nl_log (send log), orc_of_seen (last pull
 *    of the Offers module).
 *  - Transients orc_aui_msg_{uid} (PRG notices).
 *  - Queued checkout sign-ups (Action Scheduler group oros-connect, and
 *    the WP-Cron fallback hook orc_nl_send).
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Kept (Bible §12, the shop's record): the newsletter consent in the order
 * meta (_orc_nl_consent, _orc_nl_text, _orc_nl_lang and the send state).
 * It goes with the order. People already sent to orOS stay in the orOS
 * list. The rs_lang user meta belongs to Revenue Splitter and is not
 * touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function orc_uninstall_site(): void {
	global $wpdb;

	delete_option( 'orc_settings' );
	delete_option( 'orc_nl_log' );
	delete_option( 'orc_of_seen' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_orc_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_orc_aui_msg_' ) . '%'
		)
	);

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		// Hook only (and group only): with an args array Action Scheduler
		// matches only actions without args, and every send has args.
		as_unschedule_all_actions( 'orc_nl_send' );
		as_unschedule_all_actions( '', array(), 'oros-connect' );
	}
	wp_unschedule_hook( 'orc_nl_send' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $orc_site_id ) {
		switch_to_blog( (int) $orc_site_id );
		orc_uninstall_site();
		restore_current_blog();
	}
} else {
	orc_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$orc_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote' ) as $orc_slug ) {
	if ( 'oros-connect' !== $orc_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $orc_slug . '/' . $orc_slug . '.php' ) ) {
		$orc_suite_left = true;
		break;
	}
}

if ( ! $orc_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$orc_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $orc_sites as $orc_site_id ) {
		if ( $orc_site_id ) {
			switch_to_blog( (int) $orc_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $orc_site_id ) {
			restore_current_blog();
		}
	}
}

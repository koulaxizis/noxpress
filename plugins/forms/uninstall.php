<?php
/**
 * Forms — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Options frm_settings, frm_spam_stats (daily counter), frm_cf7_map
 *    (the Contact Form 7 shortcode map).
 *  - The forms (frm_form posts and their meta): settings of the plugin,
 *    useless without it.
 *  - Transients frm_aui_msg_{uid} (PRG notices), frm_rl_ / frm_rla_
 *    (limits), frm_dup_ (double-send guard), frm_used_ (spent tokens),
 *    frm_bl_ (counter cap), frm_ar_ (auto-reply guard), frm_oros_ (orOS
 *    sign-ups waiting to be sent).
 *  - The daily cleanup event frm_cleanup and the orOS sign-up jobs
 *    frm_oros_send (Action Scheduler and WP-Cron).
 *  - The stored messages (frm_entry posts and their meta) ONLY when
 *    «Delete the messages on uninstall» is on.
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Kept by default (Bible §12): the stored messages, which are the owner's
 * record of what visitors wrote (they are stored only when the e-mail
 * failed, or when a form stores all). They still expire with the retention
 * set in the settings once the plugin is back. The rs_lang user meta
 * belongs to Revenue Splitter and is not touched. Contact Form 7 and its
 * forms were never changed.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function frm_uninstall_site(): void {
	global $wpdb;

	$settings = get_option( 'frm_settings', array() );
	$wipe     = is_array( $settings ) && ! empty( $settings['wipe'] );

	$types = $wipe ? array( 'frm_form', 'frm_entry' ) : array( 'frm_form' );
	foreach ( $types as $type ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $type ) );
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	delete_option( 'frm_settings' );
	delete_option( 'frm_spam_stats' );
	delete_option( 'frm_cf7_map' );

	foreach ( array( 'frm_aui_msg_', 'frm_rl_', 'frm_rla_', 'frm_dup_', 'frm_used_', 'frm_bl_', 'frm_ar_', 'frm_oros_' ) as $prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
			)
		);
	}

	wp_unschedule_hook( 'frm_cleanup' );
	wp_unschedule_hook( 'frm_oros_send' );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'frm_oros_send' );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $frm_site_id ) {
		switch_to_blog( (int) $frm_site_id );
		frm_uninstall_site();
		restore_current_blog();
	}
} else {
	frm_uninstall_site();
}
// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$frm_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login', 'trade-feed-import', 'lean-images', 'redirects', 'search-signals', 'production-ledger', 'product-tabs', 'product-feed', 'forms', 'invoice-details' ) as $frm_slug ) {
	if ( 'forms' !== $frm_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $frm_slug . '/' . $frm_slug . '.php' ) ) {
		$frm_suite_left = true;
		break;
	}
}

if ( ! $frm_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_option( 'noxpress_trust' ); // Core 1.3.0: newest signed list serial.
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$frm_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $frm_sites as $frm_site_id ) {
		if ( $frm_site_id ) {
			switch_to_blog( (int) $frm_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $frm_site_id ) {
			restore_current_blog();
		}
	}
}

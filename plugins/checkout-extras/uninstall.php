<?php
/**
 * Checkout Extras — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Option cke_settings (settings, texts).
 *  - Transients cke_aui_msg_{uid} (PRG notices).
 *  - The background jobs (Action Scheduler group checkout-extras: the daily
 *    cleanup and the coupon reminders).
 *  - Only when "Delete the thank-you coupons … when the plugin is deleted"
 *    is ticked in Tools: the coupons this plugin created (meta
 *    _cke_generated), active ones too. No other coupon is touched.
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Kept on purpose (Bible §12, store records): the gift, its message and fee
 * line on the orders (_cke_gift, _cke_gift_message, _cke_gift_fee), the
 * coupon code each order received or used (_cke_coupon_id,
 * _cke_coupon_code, _cke_redeemed, _cke_used_in) and, by default, the
 * coupons themselves: customers hold the codes. The rs_lang user meta
 * belongs to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function cke_uninstall_site(): void {
	global $wpdb;

	$cke_settings = get_option( 'cke_settings', array() );
	$cke_delete   = is_array( $cke_settings ) && ! empty( $cke_settings['c_delete_on_uninstall'] );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		// By hook only and by group only (Bible §12): an args array would
		// match only the jobs without args.
		as_unschedule_all_actions( 'cke_cleanup' );
		as_unschedule_all_actions( 'cke_coupon_reminder' );
		as_unschedule_all_actions( '', array(), 'checkout-extras' );
	}

	if ( $cke_delete ) {
		// In batches, so a store with many coupons does not run out of memory.
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
			$cke_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
					WHERE p.post_type = 'shop_coupon' AND m.meta_key = %s LIMIT 200",
					'_cke_generated'
				)
			);
			foreach ( $cke_ids as $cke_id ) {
				wp_delete_post( (int) $cke_id, true );
			}
		} while ( count( $cke_ids ) === 200 );
	}

	delete_option( 'cke_settings' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_cke_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_cke_aui_msg_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $cke_site_id ) {
		switch_to_blog( (int) $cke_site_id );
		cke_uninstall_site();
		restore_current_blog();
	}
} else {
	cke_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$cke_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast' ) as $cke_slug ) {
	if ( 'checkout-extras' !== $cke_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $cke_slug . '/' . $cke_slug . '.php' ) ) {
		$cke_suite_left = true;
		break;
	}
}

if ( ! $cke_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$cke_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $cke_sites as $cke_site_id ) {
		if ( $cke_site_id ) {
			switch_to_blog( (int) $cke_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $cke_site_id ) {
			restore_current_blog();
		}
	}
}

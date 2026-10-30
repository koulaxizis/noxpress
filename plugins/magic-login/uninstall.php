<?php
/**
 * Magic Login — uninstall.php (v1.0.1)
 *
 * Deleted:
 *  - Options mgl_settings (settings), mgl_log (event log) and
 *    woocommerce_mgl_link_settings (the e-mail's own WooCommerce settings).
 *  - User meta _mgl_token (pending login links) of every user.
 *  - Transients mgl_aui_msg_{uid} (PRG notices), mgl_ra_* and mgl_ri_*
 *    (rate limits).
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Nothing else exists: no user, password, session or order is touched;
 * customers keep logging in with their passwords. The rs_lang user meta
 * belongs to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function mgl_uninstall_site(): void {
	global $wpdb;

	delete_option( 'mgl_settings' );
	delete_option( 'mgl_log' );
	delete_option( 'woocommerce_mgl_link_settings' );

	foreach ( array( 'mgl_aui_msg_', 'mgl_ra_', 'mgl_ri_' ) as $mgl_prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $mgl_prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $mgl_prefix ) . '%'
			)
		);
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $mgl_site_id ) {
		switch_to_blog( (int) $mgl_site_id );
		mgl_uninstall_site();
		restore_current_blog();
	}
} else {
	mgl_uninstall_site();
}

// Users are shared by the network: their meta is cleaned once.
delete_metadata( 'user', 0, '_mgl_token', '', true );

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$mgl_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login' ) as $mgl_slug ) {
	if ( 'magic-login' !== $mgl_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $mgl_slug . '/' . $mgl_slug . '.php' ) ) {
		$mgl_suite_left = true;
		break;
	}
}

if ( ! $mgl_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$mgl_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $mgl_sites as $mgl_site_id ) {
		if ( $mgl_site_id ) {
			switch_to_blog( (int) $mgl_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $mgl_site_id ) {
			restore_current_blog();
		}
	}
}

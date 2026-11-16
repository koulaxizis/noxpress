<?php
/**
 * Invoice Details — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Option ivd_settings (settings, texts).
 *  - Transients ivd_aui_msg_{uid} (PRG notices).
 *  - Only when "Delete the customers' saved details …" is ticked: the
 *    customers' saved invoice details (user meta
 *    _wc_other/invoice-details/*, the keys the Checkout block uses).
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Kept on purpose (Bible §12, store records): the invoice details on the
 * orders (_ivd_type, _ivd_vat, _ivd_country, _ivd_tax_office,
 * _ivd_activity, _ivd_hq, _ivd_check, the block fields
 * _wc_other/invoice-details/* and the compatible Timologia / OBS meta),
 * and by default the customers' saved details (their billing data). The
 * rs_lang user meta belongs to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function ivd_uninstall_site(): void {
	global $wpdb;

	$ivd_settings = get_option( 'ivd_settings', array() );
	$ivd_delete   = is_array( $ivd_settings ) && ! empty( $ivd_settings['delete_customers'] );

	delete_option( 'ivd_settings' );
	delete_option( 'ivd_aade_last' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_ivd_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ivd_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_ivd_aade_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ivd_aade_' ) . '%',
			$wpdb->esc_like( 'ivd_aade_rl_' ) . '%'
		)
	);

	if ( $ivd_delete ) {
		foreach ( array( 'invoice', 'company', 'vat', 'tax-office', 'activity', 'hq' ) as $ivd_field ) {
			delete_metadata( 'user', 0, '_wc_other/invoice-details/' . $ivd_field, '', true );
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $ivd_site_id ) {
		switch_to_blog( (int) $ivd_site_id );
		ivd_uninstall_site();
		restore_current_blog();
	}
} else {
	ivd_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$ivd_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login', 'trade-feed-import', 'lean-images', 'redirects', 'search-signals', 'production-ledger', 'product-tabs', 'product-feed', 'invoice-details' ) as $ivd_slug ) {
	if ( 'invoice-details' !== $ivd_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $ivd_slug . '/' . $ivd_slug . '.php' ) ) {
		$ivd_suite_left = true;
		break;
	}
}

if ( ! $ivd_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_option( 'noxpress_trust' ); // Core 1.3.0: newest signed list serial.
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$ivd_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $ivd_sites as $ivd_site_id ) {
		if ( $ivd_site_id ) {
			switch_to_blog( (int) $ivd_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $ivd_site_id ) {
			restore_current_blog();
		}
	}
}

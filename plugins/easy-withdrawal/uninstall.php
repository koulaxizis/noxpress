<?php
/**
 * Easy Withdrawal — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Option ewd_settings.
 *  - The emails' settings (woocommerce_ewd_receipt_settings,
 *    woocommerce_ewd_admin_settings, woocommerce_ewd_status_settings).
 *  - Transients: ewd_aui_msg_{uid} (PRG notices), ewd_rl_* (rate limit).
 *  - Product meta _ewd_personalized (the "personalised" flag).
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Kept on purpose (Bible §12 deviation, see the bootstrap docblock):
 *  - The withdrawal requests in the order meta (_ewd_requests, _ewd_state,
 *    _ewd_last) and the digital-content consent (_ewd_digital_consent,
 *    _ewd_digital_consent_text, and the block checkout field
 *    _wc_other/easy-withdrawal/digital-consent). They are the store's
 *    record of what customers declared and agreed to. They are removed
 *    with their orders.
 *
 * The rs_lang user meta belongs to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function ewd_uninstall_site(): void {
	global $wpdb;

	delete_option( 'ewd_settings' );
	foreach ( array( 'ewd_receipt', 'ewd_admin', 'ewd_status' ) as $ewd_email ) {
		delete_option( 'woocommerce_' . $ewd_email . '_settings' );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_ewd_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ewd_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_ewd_rl_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ewd_rl_' ) . '%'
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_ewd_personalized' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $ewd_site_id ) {
		switch_to_blog( (int) $ewd_site_id );
		ewd_uninstall_site();
		restore_current_blog();
	}
} else {
	ewd_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$ewd_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal' ) as $ewd_slug ) {
	if ( 'easy-withdrawal' !== $ewd_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $ewd_slug . '/' . $ewd_slug . '.php' ) ) {
		$ewd_suite_left = true;
		break;
	}
}

if ( ! $ewd_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );

	$ewd_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $ewd_sites as $ewd_site_id ) {
		if ( $ewd_site_id ) {
			switch_to_blog( (int) $ewd_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $ewd_site_id ) {
			restore_current_blog();
		}
	}
}

<?php
/**
 * Trade Accounts — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Options tra_settings, tra_log (Trade Feed call log).
 *  - Transients tra_aui_msg_{uid} (PRG notices), tra_pw_{uid} (a new feed
 *    password waiting to be shown), tra_rl_{uid} (rate-limit windows).
 *  - The Application Passwords the plugin made (its app_id only; the
 *    users' other Application Passwords stay).
 *  - User meta tra_discount, tra_api, tra_api_seen.
 *  - Product meta _tra_exclude («Εκτός χονδρικής»).
 *  - The «Χονδρική» role: its users become customers first.
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Kept (Bible §12, the shop's record): the trade accounts themselves (they
 * are WordPress users, now customers), and the order marks _tra_trade and
 * the line marks _tra_discount with their readable line. They go with the
 * orders. The rs_lang user meta belongs to Revenue Splitter.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function tra_uninstall_site(): void {
	global $wpdb;

	delete_option( 'tra_settings' );
	delete_option( 'tra_log' );

	foreach ( array( 'tra_aui_msg_', 'tra_pw_', 'tra_rl_' ) as $tra_prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $tra_prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $tra_prefix ) . '%'
			)
		);
	}

	delete_post_meta_by_key( '_tra_exclude' );

	$role = get_role( 'tra_wholesale' );
	if ( $role ) {
		$fallback = get_role( 'customer' ) ? 'customer' : get_option( 'default_role', 'subscriber' );
		foreach ( get_users( array( 'role' => 'tra_wholesale', 'fields' => 'ID', 'number' => -1 ) ) as $tra_uid ) {
			$user = get_userdata( (int) $tra_uid );
			if ( $user instanceof WP_User ) {
				$user->remove_role( 'tra_wholesale' );
				if ( empty( $user->roles ) ) {
					$user->set_role( $fallback );
				}
			}
		}
		remove_role( 'tra_wholesale' );
	}
}

/**
 * Network-wide (user meta and Application Passwords live in the shared
 * users tables): the plugin's passwords, then its user meta.
 */
function tra_uninstall_users(): void {
	if ( class_exists( 'WP_Application_Passwords' ) ) {
		foreach ( get_users( array( 'meta_key' => '_application_passwords', 'fields' => 'ID', 'number' => -1, 'blog_id' => 0 ) ) as $tra_uid ) { // phpcs:ignore WordPress.DB.SlowDBQuery -- one-off uninstall cleanup.
			foreach ( (array) WP_Application_Passwords::get_user_application_passwords( (int) $tra_uid ) as $tra_pw ) {
				if ( isset( $tra_pw['app_id'], $tra_pw['uuid'] ) && '5d3c8f0e-6a1b-4c2d-9e7f-3b4a5c6d7e8f' === $tra_pw['app_id'] ) {
					WP_Application_Passwords::delete_application_password( (int) $tra_uid, (string) $tra_pw['uuid'] );
				}
			}
		}
	}
	foreach ( array( 'tra_discount', 'tra_api', 'tra_api_seen' ) as $tra_key ) {
		delete_metadata( 'user', 0, $tra_key, '', true );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $tra_site_id ) {
		switch_to_blog( (int) $tra_site_id );
		tra_uninstall_site();
		restore_current_blog();
	}
} else {
	tra_uninstall_site();
}
tra_uninstall_users();

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$tra_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login' ) as $tra_slug ) {
	if ( 'trade-accounts' !== $tra_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $tra_slug . '/' . $tra_slug . '.php' ) ) {
		$tra_suite_left = true;
		break;
	}
}

if ( ! $tra_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$tra_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $tra_sites as $tra_site_id ) {
		if ( $tra_site_id ) {
			switch_to_blog( (int) $tra_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $tra_site_id ) {
			restore_current_blog();
		}
	}
}

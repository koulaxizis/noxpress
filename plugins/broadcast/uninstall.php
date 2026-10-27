<?php
/**
 * Broadcast — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - The webhook at Viber (best effort: set_webhook with an empty URL, when
 *    a token can still be read), so Viber stops calling a route that is gone.
 *  - Table {prefix}brc_items (queue and history) and its schema option brc_db.
 *  - Options brc_settings, brc_viber (connection), brc_secret_viber,
 *    brc_secret_mailerlite (sealed secrets), brc_status, brc_yt_seen,
 *    brc_lock.
 *  - Transients brc_aui_msg_{uid} (PRG notices), the user option brc_csv
 *    (last CSV format).
 *  - Background work (Action Scheduler group broadcast and hooks brc_tick,
 *    brc_run, brc_product_check; the same WP-Cron hooks).
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Kept: what was posted stays in the Viber channel; products, campaigns and
 * videos belong to their own systems. The rs_lang user meta belongs to
 * Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** The token, unsealed as BRC_Crypt does (the plugin classes are not loaded here). */
function brc_uninstall_token(): string {
	if ( defined( 'BRC_VIBER_TOKEN' ) && is_string( BRC_VIBER_TOKEN ) && '' !== BRC_VIBER_TOKEN ) {
		return BRC_VIBER_TOKEN;
	}
	$sealed = get_option( 'brc_secret_viber', '' );
	if ( ! is_string( $sealed ) || 0 !== strpos( $sealed, 'g1.' ) || ! function_exists( 'openssl_decrypt' ) ) {
		return '';
	}
	$raw = base64_decode( substr( $sealed, 3 ), true );
	if ( false === $raw || strlen( $raw ) < 28 ) {
		return '';
	}
	$m = '';
	foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT' ) as $c ) {
		$m .= defined( $c ) ? (string) constant( $c ) : '';
	}
	$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', hash( 'sha256', 'broadcast-seal|' . $m, true ), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
	return false === $plain ? '' : $plain;
}

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function brc_uninstall_site(): void {
	global $wpdb;

	$acc   = get_option( 'brc_viber', array() );
	$token = brc_uninstall_token();
	if ( is_array( $acc ) && ! empty( $acc['from'] ) && '' !== $token ) {
		wp_safe_remote_post(
			'https://chatapi.viber.com/pa/set_webhook',
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'       => 'application/json',
					'X-Viber-Auth-Token' => $token,
				),
				'body'        => wp_json_encode(
					array(
						'url'        => '',
						'auth_token' => $token,
					)
				),
			)
		);
	}

	foreach ( array( 'brc_settings', 'brc_viber', 'brc_secret_viber', 'brc_secret_mailerlite', 'brc_status', 'brc_yt_seen', 'brc_lock', 'brc_db' ) as $opt ) {
		delete_option( $opt );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-off uninstall cleanup of our own table.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}brc_items" );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_brc_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_brc_aui_msg_' ) . '%'
		)
	);
	delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . 'brc_csv', '', true );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		// Hook only (and group only): with an args array Action Scheduler
		// matches only actions without args (Bible §12).
		as_unschedule_all_actions( 'brc_tick' );
		as_unschedule_all_actions( 'brc_run' );
		as_unschedule_all_actions( 'brc_product_check' );
		as_unschedule_all_actions( '', array(), 'broadcast' );
	}
	wp_unschedule_hook( 'brc_tick' );
	wp_unschedule_hook( 'brc_run' );
	wp_unschedule_hook( 'brc_product_check' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $brc_site_id ) {
		switch_to_blog( (int) $brc_site_id );
		brc_uninstall_site();
		restore_current_blog();
	}
} else {
	brc_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$brc_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast' ) as $brc_slug ) {
	if ( 'broadcast' !== $brc_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $brc_slug . '/' . $brc_slug . '.php' ) ) {
		$brc_suite_left = true;
		break;
	}
}

if ( ! $brc_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$brc_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $brc_sites as $brc_site_id ) {
		if ( $brc_site_id ) {
			switch_to_blog( (int) $brc_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $brc_site_id ) {
			restore_current_blog();
		}
	}
}

<?php
/**
 * Ebook Delivery — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Options ebd_settings, ebd_limits, ebd_limits_backup, ebd_issues,
 *    ebd_secret, ebd_dir, ebd_flush, ebd_web_test.
 *  - Transients ebd_aui_msg_{uid} (PRG notices), ebd_preview_{uid} and
 *    ebd_leak_{uid} (results shown once).
 *  - Product meta _ebd_off («No watermark» box).
 *  - The private folder uploads/noxpress-ebook-delivery-<12 hex>/: only
 *    files with the plugin's own names (<id>-<32 hex>.pdf|epub, their
 *    .tmp) and its index.php / .htaccess / web.config; the folder is
 *    removed only if it is empty then.
 *  - The daily cleanup (Action Scheduler group ebook-delivery, WP-Cron).
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Never touched: WooCommerce's download permissions, limits, expiry and
 * download log, products' own files, orders. Customers keep downloading
 * the original files. The rs_lang user meta belongs to Revenue Splitter.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function ebd_uninstall_site(): void {
	global $wpdb;

	// The private folder first (its name needs ebd_dir).
	$ebd_key = (string) get_option( 'ebd_dir', '' );
	$ebd_up  = wp_upload_dir( null, false );
	if ( preg_match( '/^[a-f0-9]{12}$/', $ebd_key ) && empty( $ebd_up['error'] ) && ! empty( $ebd_up['basedir'] ) ) {
		$ebd_dir = untrailingslashit( (string) $ebd_up['basedir'] ) . '/noxpress-ebook-delivery-' . $ebd_key;
		if ( is_dir( $ebd_dir ) && ! is_link( $ebd_dir ) ) {
			$ebd_files = scandir( $ebd_dir );
			foreach ( is_array( $ebd_files ) ? $ebd_files : array() as $ebd_name ) {
				$ebd_ours = preg_match( '/^\d{1,20}-[a-f0-9]{32}\.(pdf|epub)(\.[a-f0-9]{12}\.tmp)?$/', $ebd_name )
					|| in_array( $ebd_name, array( 'index.php', '.htaccess', 'web.config' ), true );
				$ebd_path = $ebd_dir . '/' . $ebd_name;
				if ( $ebd_ours && is_file( $ebd_path ) && ! is_link( $ebd_path ) ) {
					wp_delete_file( $ebd_path );
				}
			}
			$ebd_left = scandir( $ebd_dir );
			if ( is_array( $ebd_left ) && 2 === count( $ebd_left ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- empty own folder.
				rmdir( $ebd_dir );
			}
		}
	}

	foreach ( array( 'ebd_settings', 'ebd_limits', 'ebd_limits_backup', 'ebd_issues', 'ebd_secret', 'ebd_dir', 'ebd_flush', 'ebd_web_test' ) as $ebd_opt ) {
		delete_option( $ebd_opt );
	}

	foreach ( array( 'ebd_aui_msg_', 'ebd_preview_', 'ebd_leak_' ) as $ebd_prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $ebd_prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $ebd_prefix ) . '%'
			)
		);
	}

	delete_post_meta_by_key( '_ebd_off' );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'ebd_cleanup' ); // Hook only (Bible §12).
	}
	wp_clear_scheduled_hook( 'ebd_cleanup' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $ebd_site_id ) {
		switch_to_blog( (int) $ebd_site_id );
		ebd_uninstall_site();
		restore_current_blog();
	}
} else {
	ebd_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$ebd_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login', 'trade-feed-import', 'lean-images', 'redirects', 'search-signals', 'production-ledger', 'product-tabs', 'product-feed', 'invoice-details', 'ebook-delivery' ) as $ebd_slug ) {
	if ( 'ebook-delivery' !== $ebd_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $ebd_slug . '/' . $ebd_slug . '.php' ) ) {
		$ebd_suite_left = true;
		break;
	}
}

if ( ! $ebd_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_option( 'noxpress_trust' ); // Core 1.3.0: newest signed list serial.
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$ebd_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $ebd_sites as $ebd_site_id ) {
		if ( $ebd_site_id ) {
			switch_to_blog( (int) $ebd_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $ebd_site_id ) {
			restore_current_blog();
		}
	}
}

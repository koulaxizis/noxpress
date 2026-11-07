<?php
/**
 * Search Signals — uninstall.php (v1.0.0)
 *
 * Always deleted:
 *  - Options ssg_settings, ssg_import_log, ssg_switch, ssg_compare_urls.
 *  - Transients ssg_aui_msg_{uid}, ssg_scan_{uid}, ssg_cmp_{uid},
 *    ssg_cmp_sm_{uid}, ssg_static_chk_{uid}.
 * Deleted unless «Keep the titles, descriptions and robots of the pages»
 * (ssg_settings.keep) is on:
 *  - Post meta and term meta _ssg_title, _ssg_desc, _ssg_robots,
 *    _ssg_canonical, _ssg_og_image, _ssg_primary_{product_cat,
 *    product_brand, category}.
 * Kept on purpose:
 *  - The backup folder uploads/noxpress-search-signals-<12 hex>/ when it
 *    still holds a moved llms.txt / llms-full.txt (*.bak): those are the
 *    owner's files, not ours. Its options ssg_static and ssg_state stay
 *    with it, so a new install can still restore them. An empty folder
 *    (only our guard files) is removed, with both options.
 * Multisite: every site ONCE (the main site included in the loop).
 *
 * Nothing of another plugin is touched: Rank Math's options and its
 * rank_math_* meta are only ever read, by the import. The rs_lang user
 * meta belongs to Revenue Splitter.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function ssg_uninstall_site(): void {
	global $wpdb;

	foreach ( array( 'ssg_aui_msg_', 'ssg_scan_', 'ssg_cmp_', 'ssg_static_chk_' ) as $ssg_prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $ssg_prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $ssg_prefix ) . '%'
			)
		);
	}

	// Backup folder of the static llms files: removed only when empty.
	$ssg_state = get_option( 'ssg_state', array() );
	$ssg_left  = false;
	if ( is_array( $ssg_state ) && ! empty( $ssg_state['dir'] ) && preg_match( '/^noxpress-search-signals-[a-f0-9]{12}$/', (string) $ssg_state['dir'] ) ) {
		$ssg_up  = wp_upload_dir( null, false );
		$ssg_dir = rtrim( (string) $ssg_up['basedir'], '/\\' ) . '/' . $ssg_state['dir'];
		if ( is_dir( $ssg_dir ) ) {
			foreach ( (array) scandir( $ssg_dir ) as $ssg_f ) {
				if ( is_string( $ssg_f ) && '.' !== $ssg_f && '..' !== $ssg_f && ! in_array( $ssg_f, array( 'index.php', '.htaccess', 'web.config' ), true ) ) {
					$ssg_left = true;
				}
			}
			if ( ! $ssg_left ) {
				foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $ssg_f ) {
					@unlink( $ssg_dir . '/' . $ssg_f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				}
				@rmdir( $ssg_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
		}
	}
	if ( ! $ssg_left ) {
		delete_option( 'ssg_static' );
		delete_option( 'ssg_state' );
	}

	$ssg_settings = get_option( 'ssg_settings', array() );
	if ( ! is_array( $ssg_settings ) || empty( $ssg_settings['keep'] ) ) {
		$ssg_keys = array( '_ssg_title', '_ssg_desc', '_ssg_robots', '_ssg_canonical', '_ssg_og_image', '_ssg_primary_product_cat', '_ssg_primary_product_brand', '_ssg_primary_category' );
		foreach ( $ssg_keys as $ssg_key ) {
			delete_metadata( 'post', 0, $ssg_key, '', true );
			delete_metadata( 'term', 0, $ssg_key, '', true );
		}
	}

	foreach ( array( 'ssg_settings', 'ssg_import_log', 'ssg_switch', 'ssg_compare_urls' ) as $ssg_opt ) {
		delete_option( $ssg_opt );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $ssg_site_id ) {
		switch_to_blog( (int) $ssg_site_id );
		ssg_uninstall_site();
		restore_current_blog();
	}
} else {
	ssg_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$ssg_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login', 'trade-feed-import', 'lean-images', 'redirects', 'search-signals', 'production-ledger' ) as $ssg_slug ) {
	if ( 'search-signals' !== $ssg_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $ssg_slug . '/' . $ssg_slug . '.php' ) ) {
		$ssg_suite_left = true;
		break;
	}
}

if ( ! $ssg_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$ssg_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $ssg_sites as $ssg_site_id ) {
		if ( $ssg_site_id ) {
			switch_to_blog( (int) $ssg_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $ssg_site_id ) {
			restore_current_blog();
		}
	}
}

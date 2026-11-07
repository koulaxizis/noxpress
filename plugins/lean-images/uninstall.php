<?php
/**
 * Lean Images — uninstall.php (v1.0.0)
 *
 * The sidecars are derived files (Bible §12), so they go: every
 * "<source>.webp" / "<source>.avif" that a _lim record names (made by us,
 * or adopted from Imagify and looked after since), in the folder the
 * record names, and only when the path is "<a .jpg/.jpeg/.jpe/.png inside
 * uploads>.webp|.avif". Source images are never touched; sidecars no
 * record names (e.g. Imagify's own, not adopted) stay.
 *
 * Deleted per site (multisite: every site ONCE, the main site included):
 *  - Post meta _lim, _lim_state, _lim_tries.
 *  - Options lim_settings, lim_stats, lim_last, lim_lock, lim_purged.
 *  - Transients lim_aui_msg_{uid} (PRG notices).
 *  - The lim_tick event (Action Scheduler and WP-Cron).
 *
 * Kept: Imagify's settings (only read, never written). The rs_lang user
 * meta belongs to Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** Deletes the sidecars of one site's records (uses only core APIs). */
function lim_uninstall_files(): void {
	global $wpdb;

	$up   = wp_get_upload_dir();
	$base = isset( $up['basedir'] ) ? untrailingslashit( wp_normalize_path( (string) $up['basedir'] ) ) : '';
	if ( '' === $base ) {
		return;
	}
	$after = 0;
	do {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_lim' AND meta_id > %d ORDER BY meta_id ASC LIMIT 200", $after ) );
		foreach ( (array) $rows as $row ) {
			$after = (int) $row->meta_id;
			$rec   = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $rec ) || empty( $rec['f'] ) || ! is_array( $rec['f'] ) ) {
				continue;
			}
			$dir = isset( $rec['dir'] ) && is_string( $rec['dir'] ) ? trim( wp_normalize_path( $rec['dir'] ), '/' ) : '';
			if ( false !== strpos( $dir, '..' ) ) {
				continue;
			}
			foreach ( $rec['f'] as $name => $f ) {
				$name = wp_basename( (string) $name );
				if ( ! is_array( $f ) || ! preg_match( '/\.(jpe?g|jpe|png)$/i', $name ) ) {
					continue;
				}
				$src = $base . '/' . ( '' === $dir ? '' : $dir . '/' ) . $name;
				foreach ( array( 'webp', 'avif' ) as $fmt ) {
					if ( ! empty( $f[ $fmt ] ) && is_file( $src . '.' . $fmt ) ) {
						@unlink( $src . '.' . $fmt ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
					}
				}
			}
		}
	} while ( ! empty( $rows ) );
}

/** Clean one site. */
function lim_uninstall_site(): void {
	global $wpdb;

	lim_uninstall_files();

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'lim_tick', array(), 'lean-images' );
	}
	wp_clear_scheduled_hook( 'lim_tick' );

	foreach ( array( 'lim_settings', 'lim_stats', 'lim_last', 'lim_lock', 'lim_purged' ) as $lim_opt ) {
		delete_option( $lim_opt );
	}
	foreach ( array( '_lim', '_lim_state', '_lim_tries' ) as $lim_key ) {
		delete_metadata( 'post', 0, $lim_key, '', true );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_lim_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_lim_aui_msg_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $lim_site_id ) {
		switch_to_blog( (int) $lim_site_id );
		lim_uninstall_site();
		restore_current_blog();
	}
} else {
	lim_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$lim_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections', 'book-feed', 'live-operator', 'trade-accounts', 'fast-pages', 'broadcast', 'magic-login', 'trade-feed-import', 'lean-images', 'redirects', 'search-signals', 'production-ledger' ) as $lim_slug ) {
	if ( 'lean-images' !== $lim_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $lim_slug . '/' . $lim_slug . '.php' ) ) {
		$lim_suite_left = true;
		break;
	}
}
if ( ! $lim_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$lim_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $lim_sites as $lim_site_id ) {
		if ( $lim_site_id ) {
			switch_to_blog( (int) $lim_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $lim_site_id ) {
			restore_current_blog();
		}
	}
}

<?php
/**
 * Pre-Orders — uninstall.php (v1.0.0)
 *
 * Deleted:
 *  - Options por_settings (settings, texts) and por_horizon (derived).
 *  - Transients por_aui_msg_{uid} (PRG notices) and por_product_err_{uid}
 *    (product editor date errors).
 *  - The pre-order data of products and variations (_por_on, _por_date,
 *    _por_text) and the derived _por_sort: the products sell as normal
 *    products afterwards.
 *  - The Action Scheduler jobs of the group pre-orders (release day, order
 *    jobs, resync), when Action Scheduler is loaded.
 *  - The settings of the two emails (woocommerce_por_released_settings,
 *    woocommerce_por_new_date_settings).
 *  - The CSV format choice of each user (user option por_csv_format).
 *  - Multisite: every site ONCE (the main site included in the loop).
 *
 * Kept on purpose (Bible §12, store records): the pre-order rows on the
 * order lines (the readable «Pre-order: …» row, _por_release, _por_key,
 * _por_released) and the order flags (_por_has, _por_items). They belong
 * to the orders and leave with them. The rs_lang user meta belongs to
 * Revenue Splitter and is not touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site. Names are hardcoded: the plugin classes are not loaded here.
 */
function por_uninstall_site(): void {
	global $wpdb;

	delete_option( 'por_settings' );
	delete_option( 'por_horizon' );
	delete_option( 'woocommerce_por_released_settings' );
	delete_option( 'woocommerce_por_new_date_settings' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_por_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_por_aui_msg_' ) . '%',
			$wpdb->esc_like( '_transient_por_product_err_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_por_product_err_' ) . '%'
		)
	);

	// Product and variation data (product types live in wp_posts / wp_postmeta).
	foreach ( array( '_por_on', '_por_date', '_por_text', '_por_sort' ) as $por_key ) {
		delete_post_meta_by_key( $por_key );
	}

	// Background jobs. Action Scheduler is loaded when WooCommerce is active;
	// without it the rows stay in its tables and never run (nobody listens).
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		foreach ( array( 'por_release', 'por_orders_job', 'por_resync' ) as $por_hook ) {
			as_unschedule_all_actions( $por_hook );
		}
	}

	// Per-user option of this site (CSV format).
	delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . 'por_csv_format', '', true );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $por_site_id ) {
		switch_to_blog( (int) $por_site_id );
		por_uninstall_site();
		restore_current_blog();
	}
} else {
	por_uninstall_site();
}

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$por_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal', 'shipping-notes', 'book-preview', 'featured-creators', 'store-pickup', 'hide-catalog', 'variation-picker', 'greeklish-permalinks', 'oros-connect', 'product-bundles', 'site-bridge', 'publishing-quote', 'pre-orders', 'wish-shelf', 'checkout-extras', 'data-migrator', 'home-sections' ) as $por_slug ) {
	if ( 'pre-orders' !== $por_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $por_slug . '/' . $por_slug . '.php' ) ) {
		$por_suite_left = true;
		break;
	}
}

if ( ! $por_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );
	delete_metadata( 'user', 0, 'noxpress_hidden', '', true ); // Core 1.1.0: hidden other-channel notices.

	$por_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $por_sites as $por_site_id ) {
		if ( $por_site_id ) {
			switch_to_blog( (int) $por_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $por_site_id ) {
			restore_current_blog();
		}
	}
}

<?php
/**
 * Clean uninstall για το Store Pulse.
 *
 * Διαγράφει από τη βάση:
 *  - Options: sp_low_stock_threshold, sp_default_period_*,
 *    sp_publisher, sp_quick_cards, sp_cache_version.
 *  - Transients: sp_<md5> (cached pulse datasets) + τα timeout τους.
 *
 * ΔΕΝ αγγίζει ΤΙΠΟΤΑ του Revenue Splitter (rs_* options/meta,
 * user meta rs_lang) — αν και τα δύο plugins είναι εγκατεστημένα,
 * το uninstall του ενός δεν ξεσκίζει δεδομένα του άλλου.
 *
 * Το script τρέχει ΕΞΩ από plugins_loaded — κανένα plugin class
 * ή function δεν καλείται εδώ. Μόνο WordPress core APIs.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Σημ.: χωρίς έλεγχο current_user_can — το WP_UNINSTALL_PLUGIN το
// ορίζει μόνο το core, και στο `wp plugin uninstall` (WP-CLI) δεν
// υπάρχει τρέχων χρήστης, οπότε ο έλεγχος θα ακύρωνε το cleanup.

// ---------------------------------------------------------------------
// Options (multisite-aware: μόνο στο site του uninstall).
// ---------------------------------------------------------------------

$sp_uninstall_options = array(
	'sp_low_stock_threshold',
	'sp_default_period_orders',
	'sp_default_period_money',
	'sp_default_period_refunds',
	'sp_default_period_cancelled',
	'sp_publisher',
	'sp_quick_cards',
	'sp_cache_version',
);

foreach ( $sp_uninstall_options as $sp_opt ) {
	delete_option( $sp_opt );
}

// ---------------------------------------------------------------------
// Transients του SP_Data::cached(): ΑΚΡΙΒΩΣ 'sp_' + md5 (32 hex).
// Το LIKE περνά από $wpdb->esc_like (το '_' είναι wildcard στη SQL)
// και κάθε όνομα επιβεβαιώνεται με regex πριν σβηστεί — ώστε να μην
// αγγίξουμε ποτέ transients άλλων plugins που τυχαίνει να αρχίζουν
// από «sp_». Το delete_transient() σβήνει και το _transient_timeout_
// ζεύγος (και το object cache, αν υπάρχει).
// ---------------------------------------------------------------------

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- εφάπαξ cleanup.
$sp_names = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_sp_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_sp_' ) . '%'
	)
);

if ( is_array( $sp_names ) ) {
	foreach ( $sp_names as $sp_name ) {
		if ( preg_match( '/^_transient_(?:timeout_)?(sp_[0-9a-f]{32})$/', (string) $sp_name, $sp_m ) ) {
			delete_transient( $sp_m[1] );
		}
	}
}

// ---------------------------------------------------------------------
// Done. Το Store Pulse δεν γράφει user meta δικό του (η γλώσσα
// οθόνης διαβάζεται από το κοινό rs_lang του Revenue Splitter —
// δεν το σβήνουμε εδώ), δεν γράφει post/order meta, δεν έχει
// custom tables. Καθαρό uninstall.
// ---------------------------------------------------------------------

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$sp_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats', 'easy-withdrawal' ) as $sp_slug ) {
	if ( 'store-pulse' !== $sp_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $sp_slug . '/' . $sp_slug . '.php' ) ) {
		$sp_suite_left = true;
		break;
	}
}

if ( ! $sp_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );

	$sp_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $sp_sites as $sp_site_id ) {
		if ( $sp_site_id ) {
			switch_to_blog( (int) $sp_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $sp_site_id ) {
			restore_current_blog();
		}
	}
}

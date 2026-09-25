<?php
/**
 * Clean uninstall για το Store Pulse.
 *
 * Διαγράφει από τη βάση:
 *  - Options: sp_low_stock_threshold, sp_default_period_*,
 *    sp_publisher, sp_quick_cards, sp_cache_version.
 *  - Transients: sp_* (cached pulse datasets).
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

if ( ! current_user_can( 'activate_plugins' ) ) {
	return;
}

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
// Transients με πρόθεμα sp_ (cached datasets). Same approach με το RS:
// SELECT των option_names → στοχευμένο delete_option() ανά όνομα,
// ώστε να invalidated και το object cache χωρίς blanket flush.
//
// ΠΡΟΣΟΧΗ: τα masks είναι «sp_» — ΠΟΤΕ «rs_». Το Revenue Splitter
// κρατά τα δικά του δεδομένα (rs_tok_, rs_report_ κ.λπ.).
// ---------------------------------------------------------------------

global $wpdb;

$sp_transient_masks = array(
	'_transient_sp_%',
	'_transient_timeout_sp_%',
);

foreach ( $sp_transient_masks as $sp_mask ) {

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- εφάπαξ cleanup.
	$sp_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$sp_mask
		)
	);

	if ( ! is_array( $sp_names ) ) {
		continue;
	}

	foreach ( $sp_names as $sp_name ) {
		delete_option( $sp_name );
	}
}

// ---------------------------------------------------------------------
// Done. Το Store Pulse δεν γράφει user meta δικό του (η γλώσσα
// οθόνης διαβάζεται από το κοινό rs_lang του Revenue Splitter —
// δεν το σβήνουμε εδώ), δεν γράφει post/order meta, δεν έχει
// custom tables. Καθαρό uninstall.
// ---------------------------------------------------------------------
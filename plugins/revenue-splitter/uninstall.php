<?php
/**
 * Clean uninstall για το Revenue Splitter.
 *
 * Διαγράφει ΟΛΟΚΛΗΡΩΣ κάθε ίχνος του plugin από τη βάση:
 *  - Όλα τα options: rs_default_vat_rate, rs_beneficiaries,
 *    rs_portal_keys, rs_ledger, rs_reason_coupons, rs_cache_version.
 *  - User meta: rs_lang (ανά χρήστη).
 *  - Transients: rs_tok_* (portal sessions), rs_rl_* (rate limit),
 *    rs_report_* (cached reports), rs_ledger_msg_* (PRG notices),
 *    rs_aui_msg_* (backup/import/settings notices), rs_split_error_*
 *    (validation notices metabox), rs_newkey_* (one-shot plaintext
 *    κλειδιά portal — v1.3.1).
 *  - Order meta: _rs_free_reason (αιτιολογία δωρεάν αντιτύπου).
 *  - Post meta προϊόντων: _rs_split, _rs_beneficiaries, _rs_vat_rate.
 *
 * ΣΗΜΑΝΤΙΚΟ: το script αυτό τρέχει ΕΞΩ από plugins_loaded —
 * καμία κλήση σε functions του plugin (classes, constants).
 * Μόνο WordPress core APIs + $wpdb με full guards.
 *
 * Πολιτική: καμία ερώτηση «σίγουρα;» — αν ο admin κάνει Delete,
 * εννοεί DELETE. Τα παραγωγικά δεδομένα που πρέπει να ΣΩΘΟΥΝ
 * είναι δουλειά του admin (το κουμπί «Εξαγωγή state (JSON)»
 * στις Ρυθμίσεις).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// ------------------------------------------------------------------------
// Guard: αποκλειστικά από το WP core uninstaller (ΔΕΝ από avatar
// request, cron, ή external include).
// ------------------------------------------------------------------------

if ( ! current_user_can( 'activate_plugins' ) ) {
	return;
}

// ------------------------------------------------------------------------
// Options (multisite-aware: σβήνουμε ΜΟΝΟ στο site που γίνεται το
// uninstall — δεν αγγίζουμε άλλα sites του network).
// ------------------------------------------------------------------------

$rs_uninstall_options = array(
	'rs_default_vat_rate',
	'rs_beneficiaries',
	'rs_portal_keys',
	'rs_ledger',
	'rs_reason_coupons',
	'rs_cache_version',
);

foreach ( $rs_uninstall_options as $rs_opt ) {
	delete_option( $rs_opt );
}

// ------------------------------------------------------------------------
// User meta (rs_lang) — για ΟΛΟΥΣ τους χρήστες του site.
// ------------------------------------------------------------------------

delete_metadata( 'user', 0, 'rs_lang', '', true );

// ------------------------------------------------------------------------
// Transients (πρόθεμα του plugin).
//
// Approach: SELECT των option_name που ταιριάζουν, μετά delete_option()
// ανά όνομα — έτσι το WordPress core invalidates ΚΑΙ το object cache
// (Redis/Memcached) στοχευμένα, χωρίς blanket wp_cache_flush() που
// καθάριζε το cache ΟΛΟΥ του site (και άλλων sites σε multisite).
//
// Αν υπάρχει persistent object cache, τα transients ίσως ΔΕΝ είναι
// καθόλου στη βάση — αυτά όμως έχουν ίδιο TTL (≤ 24h) και λήγουν
// μόνα τους, οπότε κανένα ρίσκο εγκατάλειψης δεδομένων.
// ------------------------------------------------------------------------

global $wpdb;

$rs_transient_masks = array(
	'_transient_rs_tok_%',              // Portal session tokens.
	'_transient_rs_rl_%',               // Rate limit counters.
	'_transient_rs_report_%',           // Cached reports.
	'_transient_rs_ledger_msg_%',       // PRG notices (ledger).
	'_transient_rs_aui_msg_%',          // PRG notices (backup/import/settings).
	'_transient_rs_split_error_%',      // Validation notices (metabox).
	'_transient_rs_newkey_%',           // v1.3.1: one-shot plaintext portal keys.
	'_transient_timeout_rs_tok_%',
	'_transient_timeout_rs_rl_%',
	'_transient_timeout_rs_report_%',
	'_transient_timeout_rs_ledger_msg_%',
	'_transient_timeout_rs_aui_msg_%',
	'_transient_timeout_rs_split_error_%',
	'_transient_timeout_rs_newkey_%',
);

foreach ( $rs_transient_masks as $rs_mask ) {

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- μαζικό cleanup, εφάπαξ.
	$rs_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$rs_mask
		)
	);

	if ( ! is_array( $rs_names ) ) {
		continue;
	}

	foreach ( $rs_names as $rs_name ) {
		// delete_option() σβήνει το row + σβήνει το object cache entry.
		delete_option( $rs_name );
	}
}

// ------------------------------------------------------------------------
// Object cache ΔΕΝ ξανα-σβήνεται blanket: τα στοχευμένα delete_option()
// παραπάνω φροντίζουν για τις περιπτώσεις που τα transients είναι στη
// βάση· ό,τι μένει μόνο σε persistent cache λήγει με το TTL του.
// ------------------------------------------------------------------------

// ------------------------------------------------------------------------
// Post meta προϊόντων (_rs_split, _rs_beneficiaries, _rs_vat_rate) —
// permadelete: συμπεριλαμβανομένων και trash/auto-draft post states.
// (_rs_beneficiaries = legacy meta από παλιές εκδόσεις — backward compat.)
// ------------------------------------------------------------------------

delete_post_meta_by_key( '_rs_split' );
delete_post_meta_by_key( '_rs_beneficiaries' );
delete_post_meta_by_key( '_rs_vat_rate' );

// ------------------------------------------------------------------------
// Order meta (_rs_free_reason — αιτιολογία δωρεάν αντιτύπου).
//
// HPOS-aware: ελέγχουμε και τα δύο datastores — κλασικό postmeta
// και το dedicated order meta table του HPOS (wc_orders_meta).
// ------------------------------------------------------------------------

// Κλασικό postmeta (legacy datastore ή reverted HPOS).
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
		'_rs_free_reason'
	)
);

// HPOS meta table (WooCommerce 8.2+, custom order tables).
$rs_hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';

// Καθαρό existence check — δεν κάνουμε abort σε παλιά installs.
$rs_table_exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare( 'SHOW TABLES LIKE %s', $rs_hpos_meta_table )
);

if ( $rs_table_exists === $rs_hpos_meta_table ) {
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"DELETE FROM {$rs_hpos_meta_table} WHERE meta_key = %s",
			'_rs_free_reason'
		)
	);
}

// ------------------------------------------------------------------------
// Done. Καμία σιωπηλή αποτυχία — αν κάτι πήγε στραβά, θα το δεις στα
// υπολειπόμενα δεδομένα (option inspector / DB browser).
// ------------------------------------------------------------------------
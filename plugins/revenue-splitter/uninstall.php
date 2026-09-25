<?php
/**
 * Clean uninstall για το Revenue Splitter.
 *
 * Διαγράφει ΟΛΟΚΛΗΡΩΣ κάθε ίχνος του plugin από τη βάση:
 *  - Όλα τα options: rs_default_vat_rate, rs_beneficiaries,
 *    rs_portal_keys, rs_ledger, rs_reason_coupons, rs_sales_since, rs_cache_version.
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

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! current_user_can( 'activate_plugins' ) ) {
	return;
}

/**
 * v1.3.4-b: Sweep leftover plugin folders (revenue-splitter*) +
 * εγγραφή log στο /wp-content/uploads/ για επαλήθευση χωρίς FTP.
 *
 * Safeguards: ΜΟΝΟ dirs με prefix "revenue-splitter*", πάντα εκτός
 * του εαυτού μας, symlink candidates SKIP, realpath containment
 * μέσα στο WP_PLUGIN_DIR. Το log επιβιώνει στο uploads.
 */

if ( ! function_exists( 'rs_uninstall_rrmdir' ) ) {
	/**
	 * Recursive directory deletion. Συγκεντρώνει γεγονότα + πλήθος αρχείων.
	 *
	 * @param string $dir   Realpath του directory.
	 * @param array  $log   Collector (by reference).
	 * @param int    $files Counter αρχείων (by reference).
	 * @return void
	 */
	function rs_uninstall_rrmdir( string $dir, array &$log, int &$files ): void {
		$entries = scandir( $dir );
		if ( false === $entries ) {
			$log[] = '[FAIL] scandir: ' . $dir;
			return;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_link( $path ) ) {
				@unlink( $path ); // symlink-κόμβος: unlink, ποτέ recursion.
				$files++;
				continue;
			}
			if ( is_dir( $path ) ) {
				rs_uninstall_rrmdir( $path, $log, $files );
			} else {
				@unlink( $path );
				$files++;
			}
		}
		$log[] = ( @rmdir( $dir ) ? '[DELETED DIR] ' : '[FAILED DIR] ' ) . $dir;
	}
}

$log          = array();
$files        = 0;
$sweeps       = 0;
$plugins_dir = rtrim( (string) realpath( WP_PLUGIN_DIR ), '/' );
if ( '' === $plugins_dir ) {
	$plugins_dir = '/nonexistent-guard';
}
$targets      = glob( trailingslashit( WP_PLUGIN_DIR ) . 'revenue-splitter*', GLOB_ONLYDIR );
$self_dir     = basename( __DIR__ );

foreach ( (array) $targets as $target ) {
	$target = (string) $target;
	$slug   = basename( $target );

	// 1. Ποτέ ο εαυτός μας.
	if ( $slug === $self_dir ) {
		continue;
	}
	// 2. Symlink candidates: skip με trace στο log.
	if ( is_link( $target ) ) {
		$log[] = '[SKIPPED SYMLINK] ' . $target;
		continue;
	}
	// 3. Realpath containment — ποτέ εκτός WP_PLUGIN_DIR.
	$real = (string) realpath( $target );
	if ( '' === $real || 0 !== strpos( $real . '/', $plugins_dir . '/' ) ) {
		$log[] = '[SKIPPED ESCAPE] ' . $target;
		continue;
	}

	$log[] = '[SWEEP START] ' . $real;
	rs_uninstall_rrmdir( $real, $log, $files );
	$log[] = '[SWEEP DONE] ' . $real;
	$sweeps++;
}

// ---- v1.3.4-c: Log σε ΜΥΣΤΙΚΟ random filename + email στον admin ----
//
// Privacy-first: κανένα predictable, δημόσια προσβάσιμο log URL.
// Ροή: γράφουμε σε random-named file στο uploads (fallback), στέλνουμε
// το περιεχόμενο με wp_mail() στον admin_email του site, και αν το mail
// φύγει ΣΒΗΝΟΥΜΕ το file — μηδέν ίχνη στο disk. Αν το mail αποτύχει,
// το file παραμένει και το debug.log παίρνει το random path.
$upload_dir = wp_upload_dir();
$logfile    = '';
$lines      = array_merge(
	array(
		'=== Revenue Splitter — uninstall sweep ===',
		'Date: ' . gmdate( 'c' ),
		'',
	),
	( empty( $log ) ? array( '[INFO] Δεν βρέθηκαν leftover φάκελοι revenue-splitter*.' ) : $log ),
	array(
		'',
		'Leftover folders swept: ' . $sweeps,
		'Total files removed: ' . $files,
		'',
	)
);
$body = implode( "\n", $lines );

if ( ! empty( $upload_dir['basedir'] ) ) {
	$random  = wp_generate_password( 24, false, false ); // alphanumeric, 24 chars.
	$logfile = trailingslashit( $upload_dir['basedir'] ) . 'rs-uninstall-' . $random . '.log';
	@file_put_contents( $logfile, $body, LOCK_EX );
}

$mail_sent = false;
if ( function_exists( 'wp_mail' ) ) {
	$mail_sent = wp_mail(
		(string) get_option( 'admin_email' ),
		'Revenue Splitter — uninstall sweep report',
		$body
	);
}

if ( $mail_sent ) {
	if ( '' !== $logfile && @unlink( $logfile ) ) {
		$logfile = ''; // Σβήστηκε — κανένα ίχνος στο disk.
	}
	if ( function_exists( 'error_log' ) ) {
		error_log( 'Revenue Splitter uninstall sweep: ' . $sweeps . ' folder(s), ' . $files . ' file(s). Report emailed to admin (file removed).' );
	}
} elseif ( function_exists( 'error_log' ) ) {
	error_log( 'Revenue Splitter uninstall sweep: ' . $sweeps . ' folder(s), ' . $files . ' file(s). Mail FAILED — log kept at: ' . $logfile );
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
	'rs_sales_since',
	'rs_beneficiary_colors', // v1.3.8 (#1): χρώματα δικαιούχων.
	'rs_channels',           // v1.3.8 (#7): λίστα καναλιών πώλησης.
	'rs_default_channel',    // v1.3.8 (#7 στάδιο 3): default κανάλι.
	'rs_version',            // v1.4.0: recorded version από το rs_maybe_upgrade().
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
	'_transient_rs_block_checkout_warned_%', // v1.4.0: blocks-checkout warning suppression flags.
	'_transient_timeout_rs_tok_%',
	'_transient_timeout_rs_rl_%',
	'_transient_timeout_rs_report_%',
	'_transient_timeout_rs_ledger_msg_%',
	'_transient_timeout_rs_aui_msg_%',
	'_transient_timeout_rs_split_error_%',
	'_transient_timeout_rs_newkey_%',
	'_transient_timeout_rs_block_checkout_warned_%',
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
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)",
		'_rs_free_reason',
		'_rs_channel'
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
			"DELETE FROM {$rs_hpos_meta_table} WHERE meta_key IN (%s, %s)",
			'_rs_free_reason',
			'_rs_channel'
		)
	);
}

// ------------------------------------------------------------------------
// Order ITEM meta (_rs_reg_unit — v1.3.7: stamped regular unit price
// των line items από το RS_Checkout::stamp_regular_prices). Ζει στον
// wp_woocommerce_order_itemmeta — ΚΟΙΝΟΣ πίνακας και για classic και
// για HPOS setups (τα line items δεν μετακομίζουν στο HPOS), άρα ΕΝΑ
// DELETE τα καλύπτει όλα. Existence check — ποτέ abort.
// ------------------------------------------------------------------------

$rs_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rs_itemmeta_table ) ) === $rs_itemmeta_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"DELETE FROM {$rs_itemmeta_table} WHERE meta_key = %s",
			'_rs_reg_unit'
		)
	);
}

// ------------------------------------------------------------------------
// Done. Καμία σιωπηλή αποτυχία — αν κάτι πήγε στραβά, θα το δεις στα
// υπολειπόμενα δεδομένα (option inspector / DB browser).
// ------------------------------------------------------------------------
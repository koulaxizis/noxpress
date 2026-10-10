<?php
/**
 * Clean uninstall για το Revenue Splitter (v1.7.0).
 *
 * Διαγράφει ΜΟΝΟ τα δεδομένα αυτού του plugin από τη βάση:
 *  - Όλα τα options του plugin (λίστα παρακάτω — επαληθευμένη με grep
 *    σε κάθε get/update/add_option του κώδικα).
 *  - User meta: rs_lang — ΜΟΝΟ αν δεν είναι εγκατεστημένα το Store Pulse,
 *    το Smart Formatter, το Theme Patcher ή το Shop Filters (το διαβάζουν
 *    ως κοινή επιλογή γλώσσας Noxpress).
 *  - Noxpress Core (noxpress_channel, noxpress_checked, noxpress_manifest,
 *    noxpress_hub_msg_*) — ΜΟΝΟ αν δεν μένει άλλο plugin της σουίτας.
 *  - Transients: rs_tok_* (portal sessions), rs_rl_* (rate limit),
 *    rs_report_* (cached reports), rs_ledger_msg_* / rs_aui_msg_* (PRG
 *    notices), rs_split_error_* / rs_split_warn_* (metabox notices),
 *    rs_newkey_* (one-shot κλειδιά portal), rs_block_checkout_warned_*.
 *  - WP-Cron: rs_email_monthly_check.
 *  - Order meta: _rs_free_reason, _rs_channel (classic + HPOS).
 *  - Order item meta: _rs_reg_unit.
 *  - Post meta προϊόντων: _rs_split, _rs_beneficiaries, _rs_vat_rate.
 *
 * v1.7.0: ΑΦΑΙΡΕΘΗΚΕ το παλιό «sweep» που διέγραφε αναδρομικά ΑΛΛΟΥΣ
 * φακέλους plugins/revenue-splitter* (και το log/email του). Ένα
 * uninstall δεν αγγίζει ποτέ αρχεία άλλων plugins — τα αρχεία του ίδιου
 * του plugin τα σβήνει το WordPress.
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
	'rs_beneficiary_emails', // Emails δικαιούχων.
	'rs_email_reports',      // Opt-in μηνιαίας αναφοράς.
	'rs_last_report_run',    // Dedup του cron μηνιαίας αναφοράς.
);

foreach ( $rs_uninstall_options as $rs_opt ) {
	delete_option( $rs_opt );
}

// ------------------------------------------------------------------------
// WP-Cron της μηνιαίας αναφοράς.
// ------------------------------------------------------------------------

wp_clear_scheduled_hook( 'rs_email_monthly_check' );

// ------------------------------------------------------------------------
// User meta (rs_lang) — για ΟΛΟΥΣ τους χρήστες του site.
//
// Το rs_lang είναι η ΚΟΙΝΗ επιλογή γλώσσας του οικοσυστήματος Noxpress:
// το Store Pulse, το Smart Formatter, το Theme Patcher, το Shop Filters και το Product Formats τη διαβάζουν.
// Σβήνεται ΜΟΝΟ αν κανένα δεν είναι εγκατεστημένο (έλεγχος κύριου αρχείου).
// ------------------------------------------------------------------------

$rs_lang_readers = array(
	'store-pulse/store-pulse.php',
	'smart-formatter/smart-formatter.php',
	'theme-patcher/theme-patcher.php',
	'shop-filters/shop-filters.php',
	'product-formats/product-formats.php',
);

$rs_lang_in_use = false;
foreach ( $rs_lang_readers as $rs_reader ) {
	if ( file_exists( trailingslashit( WP_PLUGIN_DIR ) . $rs_reader ) ) {
		$rs_lang_in_use = true;
		break;
	}
}

if ( ! $rs_lang_in_use ) {
	delete_metadata( 'user', 0, 'rs_lang', '', true );
}

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

$rs_transient_prefixes = array(
	'rs_tok_',                   // Portal session tokens.
	'rs_rl_',                    // Rate limit counters (login/reset/ανά δικαιούχο).
	'rs_report_',                // Cached reports.
	'rs_ledger_msg_',            // PRG notices (ledger).
	'rs_aui_msg_',               // PRG notices (backup/import/settings).
	'rs_split_error_',           // Validation notices (metabox).
	'rs_split_warn_',            // v1.7.0: email warnings (metabox).
	'rs_newkey_',                // One-shot plaintext portal keys.
	'rs_block_checkout_warned_', // Blocks-checkout warning suppression flags.
);

foreach ( $rs_transient_prefixes as $rs_prefix ) {

	foreach ( array( '_transient_', '_transient_timeout_' ) as $rs_kind ) {

		// LIKE escaped: τα '_' του prefix είναι literal, όχι wildcards.
		$rs_mask = $wpdb->esc_like( $rs_kind . $rs_prefix ) . '%';

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

// ---------------------------------------------------------------------
// Noxpress Core (Bible §16): the update channel, the cached version list
// and the hub notices are shared by the suite. They are deleted only
// when no other Noxpress plugin is installed (main file check).
// ---------------------------------------------------------------------

$rs_suite_left = false;
foreach ( array( 'revenue-splitter', 'store-pulse', 'smart-formatter', 'theme-patcher', 'shop-filters', 'product-formats' ) as $rs_slug ) {
	if ( 'revenue-splitter' !== $rs_slug && file_exists( trailingslashit( WP_PLUGIN_DIR ) . $rs_slug . '/' . $rs_slug . '.php' ) ) {
		$rs_suite_left = true;
		break;
	}
}

if ( ! $rs_suite_left ) {
	global $wpdb;

	delete_site_option( 'noxpress_channel' );
	delete_site_option( 'noxpress_checked' );
	delete_site_transient( 'noxpress_manifest' );

	$rs_sites = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 );
	foreach ( $rs_sites as $rs_site_id ) {
		if ( $rs_site_id ) {
			switch_to_blog( (int) $rs_site_id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_noxpress_hub_msg_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_noxpress_hub_msg_' ) . '%'
			)
		);
		if ( $rs_site_id ) {
			restore_current_blog();
		}
	}
}

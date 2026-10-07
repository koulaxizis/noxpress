<?php
/**
 * Clean uninstall για το Noxpress Migrator.
 *
 * Διαγράφει ΟΛΟΚΛΗΡΩΣ κάθε ίχνος του plugin:
 *  - Options: nm_max_volume_mb, nm_checkpoint, nm_last_manifest +
 *    ΟΛΟΙΣΤΙΚΉ sweep κάθε option που ξεκινάει από 'nm_' (future-proof).
 *  - User meta: nm_lang (ανά χρήστη).
 *  - Transients: _transient_nm_* / _transient_timeout_nm_*.
 *  - Uploads: ΣΤΑΘΕΡΟΙ φάκελοι staging 'noxpress-migrator-tmp*' και
 *    τυχόν προσωρινά logs 'noxpress-migrator-*.log' — ΠΟΤΕ εκτός
 *    uploads, ΠΟΤΕ χωρίς το prefix.
 *  - Plugin φάκελοι: leftover 'noxpress-migrator*' στο WP_PLUGIN_DIR
 *    (εκτός από τον εαυτό μας) — ίδιο sweep pattern με το Revenue
 *    Splitter 1.3.4-b, sympathy σto design του οικοσυστήματος.
 *
 * ΣΗΜΑΝΤΙΚΟ: το script τρέχει ΕΞΩ από plugins_loaded — καμία κλήση σε
 * functions του plugin (classes, constants). Μόνο WP core APIs +
 * $wpdb με full guards.
 *
 * ΠΟΛΙΤΙΚΗ ΑΣΦΑΛΕΙΑΣ: τα εκEXPORTα migration archives (ZIP) που έχει
 * ήδη κατεβάσει ο admin ΔΕΝ είναι δουλειά του uninstall — φυσικά
 * βρίσκονται στον client, εκτός server. Ό,τι μείνει στο staging
 * (ημιτελή ZIP) είναι SCRAP: σβήνεται χωρίς ερώτηση. Αν ο admin
 * κάνει Delete, εννοεί DELETE.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! current_user_can( 'activate_plugins' ) ) {
	return;
}

/* =====================================================================
 * Helper: recursive rmdir με collector (ίδιο pattern με RS uninstall).
 * =================================================================== */

if ( ! function_exists( 'nm_uninstall_rrmdir' ) ) {
	/**
	 * Recursive directory deletion. Συγκεντρώνει γεγονότα + πλήθος αρχείων.
	 *
	 * @param string $dir   Realpath του directory.
	 * @param array  $log   Collector (by reference).
	 * @param int    $files Counter αρχείων (by reference).
	 * @return void
	 */
	function nm_uninstall_rrmdir( string $dir, array &$log, int &$files ): void {
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
				@unlink( $path ); // symlink κόμβος: unlink, ποτέ recursion.
				$files++;
				continue;
			}
			if ( is_dir( $path ) ) {
				nm_uninstall_rrmdir( $path, $log, $files );
			} else {
				@unlink( $path );
				$files++;
			}
		}
		$log[] = ( @rmdir( $dir ) ? '[DELETED DIR] ' : '[FAILED DIR] ' ) . $dir;
	}
}

/**
 * Τοπικό (safe) realpath wrapper: η 'string' return + '/nonexistent-guard'
 * => κανένα path traversal — περνάει πάντα από realpath containment.
 */
if ( ! function_exists( 'nm_uninstall_realdir' ) ) {
	function nm_uninstall_realdir( string $dir ): string {
		$real = (string) realpath( $dir );
		return ( '' !== $real && is_dir( $real ) ) ? rtrim( $real, '/' ) : '';
	}
}

$log   = array();
$files = 0;
$swept = 0;

/* =====================================================================
 * 1. Sweep leftover plugin folders (noxpress-migrator*) στο WP_PLUGIN_DIR.
 * =================================================================== */

$plugins_dir = nm_uninstall_realdir( (string) WP_PLUGIN_DIR );
$targets     = glob( trailingslashit( (string) WP_PLUGIN_DIR ) . 'noxpress-migrator*', GLOB_ONLYDIR );
$self_dir    = basename( __DIR__ );

foreach ( (array) $targets as $target ) {
	$target = (string) $target;
	$slug   = basename( $target );

	// 1. Ποτέ ο εαυτός μας.
	if ( $slug === $self_dir ) {
		continue;
	}
	// 2. Symlink: skip με trace.
	if ( is_link( $target ) ) {
		$log[] = '[SKIPPED SYMLINK] ' . $target;
		continue;
	}
	// 3. Realpath containment — ποτέ εκτός WP_PLUGIN_DIR.
	$real = nm_uninstall_realdir( $target );
	if ( '' === $real || '' === $plugins_dir
		|| 0 !== strpos( $real . '/', $plugins_dir . '/' ) ) {
		$log[] = '[SKIPPED ESCAPE] ' . $target;
		continue;
	}

	$log[] = '[SWEEP START] ' . $real;
	nm_uninstall_rrmdir( $real, $log, $files );
	$log[] = '[SWEEP DONE] ' . $real;
	$swept++;
}

/* =====================================================================
 * 2. Sweep staging + logs στο uploads (prefix NM_UPLOAD_PREFIX, εδώ
 *    hardcoded γιατί δεν ζούμε στο scope του plugin).
 *
 *    - noxpress-migrator-tmp*   → staging φάκελοι ημιτελών exports.
 *    - noxpress-migrator-*.log  → uninstall/operation logs.
 *    ΠΟΤΕ δεν σβήνουμε τίποτα χωρίς το prefix — το ίδιο το uploads
 *    μένει άθικτο.
 * =================================================================== */

$upload_dir = wp_upload_dir();
$uploads    = nm_uninstall_realdir( (string) ( is_array( $upload_dir ) ? ( $upload_dir['basedir'] ?? '' ) : '' ) );

if ( '' !== $uploads ) {

	$up_targets = array_merge(
		(array) glob( $uploads . '/noxpress-migrator-tmp*', GLOB_ONLYDIR ),
		(array) glob( $uploads . '/noxpress-migrator-*.log' )
	);

	foreach ( $up_targets as $target ) {
		$target = (string) $target;

		if ( is_link( $target ) ) {
			$log[] = '[SKIPPED SYMLINK] ' . $target;
			continue;
		}

		$real = is_dir( $target ) ? nm_uninstall_realdir( $target ) : (string) realpath( $target );

		// Realpath containment μέσα στο uploads.
		if ( '' === $real || 0 !== strpos( $real . '/', $uploads . '/' ) ) {
			$log[] = '[SKIPPED ESCAPE] ' . $target;
			continue;
		}

		if ( is_dir( $real ) ) {
			$log[] = '[SWEEP START] ' . $real;
			nm_uninstall_rrmdir( $real, $log, $files );
			$log[] = '[SWEEP DONE] ' . $real;
			$swept++;
		} else {
			if ( @unlink( $real ) ) {
				$log[] = '[DELETED FILE] ' . $real;
				$files++;
			} else {
				$log[] = '[FAILED FILE] ' . $real;
			}
		}
	}
} else {
	$log[] = '[INFO] Το wp_upload_dir() δεν ήταν διαθέσιμο —跳 sweeps του uploads.';
}

/* =====================================================================
 * 3. Log σε ΜΥΣΤΙΚΟ random filename + email στον admin — μηδέν ίχνη.
 *    (Ίδιο privacy-first pattern με RS: αν το mail φύγει, το file
 *    ΣΒΗΝΕΤΑΙ. Αν αποτύχει, μένει και γράφεται στο debug.log.)
 * =================================================================== */

$nm_logfile = '';
$nm_lines   = array_merge(
	array(
		'=== Noxpress Migrator — uninstall sweep ===',
		'Date: ' . gmdate( 'c' ),
		'',
	),
	( empty( $log ) ? array( '[INFO] Δεν βρέθηκαν leftover φάκελοι/αρχεία noxpress-migrator*.' ) : $log ),
	array(
		'',
		'Leftover folders/files swept: ' . $swept,
		'Total files removed: ' . $files,
		'',
	)
);
$nm_body = implode( "\n", $nm_lines );

if ( '' !== $uploads ) {
	$random     = wp_generate_password( 24, false, false ); // alphanumeric, 24 chars.
	$nm_logfile = $uploads . '/noxpress-migrator-' . $random . '.log';
	@file_put_contents( $nm_logfile, $nm_body, LOCK_EX );
}

$nm_mail_sent = false;
if ( function_exists( 'wp_mail' ) ) {
	$nm_mail_sent = wp_mail(
		(string) get_option( 'admin_email' ),
		'Noxpress Migrator — uninstall sweep report',
		$nm_body
	);
}

if ( $nm_mail_sent ) {
	if ( '' !== $nm_logfile && @unlink( $nm_logfile ) ) {
		$nm_logfile = ''; // Σβήστηκε — μηδέν ίχνη στο disk.
	}
	if ( function_exists( 'error_log' ) ) {
		error_log( 'Noxpress Migrator uninstall sweep: ' . $swept . ' folder(s)/item(s), ' . $files . ' file(s). Report emailed to admin (file removed).' );
	}
} elseif ( function_exists( 'error_log' ) ) {
	error_log( 'Noxpress Migrator uninstall sweep: ' . $swept . ' folder(s)/item(s), ' . $files . ' file(s). Mail FAILED — log kept at: ' . $nm_logfile );
}

/* =====================================================================
 * 4. Options — targeted list + wildcard sweep 'nm\_%'.
 *
 *    Why wildcard: το plugin προσθέτει options δυναμικά (checkpoints,
 *    manifests, μελλοντικές ρυθμίσεις waves 2+) — ένα targeted list
 *    μόνο του θα άφηνε ορφανά keys. Το escape '_' κρατά το PREFIX
 *    αυστηρό: 'nm_foo' ταιριάζει, 'nmfoo' ΔΕΝ ταιριάζει.
 * =================================================================== */

global $wpdb;

$nm_known_options = array(
	'nm_max_volume_mb',
	'nm_checkpoint',
	'nm_last_manifest',
);

foreach ( $nm_known_options as $nm_opt ) {
	delete_option( $nm_opt );
}

// Wildcard sweep — ταυτοποιούμε από τη βάση, μετά targeted delete.
$nm_like_rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- εφάπαξ uninstall cleanup.
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		'nm\_%'
	)
);

if ( is_array( $nm_like_rows ) ) {
	foreach ( $nm_like_rows as $nm_opt_name ) {
		delete_option( $nm_opt_name ); // Σβήνει row + το αντίστοιχο object cache entry.
	}
}

/* =====================================================================
 * 5. User meta (nm_lang) — για ΟΛΟΥΣ τους χρήστες του site.
 * =================================================================== */

delete_metadata( 'user', 0, 'nm_lang', '', true );

/* =====================================================================
 * 6. Transients (_transient_nm_* — targeted SELECT + delete_option
 *    ανά όνομα, ώστε το WordPress να invalidate ΚΑΙ το persistent
 *    object cache (Redis/Memcached) χωρίς blanket wp_cache_flush()
 *    που καθάριζε ΟΛΟ το site — και άλλα sites σε multisite.
 * =================================================================== */

$nm_transient_masks = array(
	'_transient_nm_%',
	'_transient_timeout_nm_%',
);

foreach ( $nm_transient_masks as $nm_mask ) {

	$nm_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- εφάπαξ uninstall cleanup.
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$nm_mask
		)
	);

	if ( ! is_array( $nm_names ) ) {
		continue;
	}

	foreach ( $nm_names as $nm_name ) {
		delete_option( $nm_name );
	}
}

/* =====================================================================
 * Done. Ούτε το object cache flush-άρθηκε blanket, ούτε αγγίχτηκε
 * Ο,ΤΙΔΗΠΟΤΕ εκτός του namespace 'nm_'/'noxpress-migrator-'. Αν κάτι
 * πήγε στραβά, το uninstall report email/logs το αποκαλύπτουν.
 * =================================================================== */
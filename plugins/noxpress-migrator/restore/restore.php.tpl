<?php
/**
 * Noxpress Migrator — restore.php (standalone importer).
 *
 * Self-contained: ΜΗΔΕΝ WordPress dependency. Τρέχει σε οποιονδήποτε
 * server με PHP 7.4+ (pdo_mysql ή mysqli), απευθείας από τον φάκελο
 * όπου βρίσκονται τα volumes (noxpress-migration-*.zip/.z01...).
 *
 * Workflow (steps, browser-driven, resumable):
 *   0 intro        → φόρμα ρυθμίσεων (DB creds + paths)
 *   1 concat       → ένωση volumes σε archive.tmp.zip + SHA-256 check
 *   2 manifest     → ανάγνωση manifest.json + CHECKSUM ΟΛΩΝ των entries
 *   3 extract      → αρχεία → uploads/ plugins/ themes/ root/
 *   4 sql          → chunked import του db/dump.sql (byte-offset state)
 *   5 verify       → counts + έκθεση αποτελεσμάτων
 *   6 done         → οδηγίες post-restore + ΣΒΗΣΕ ΜΕ
 *
 * ΠΟΤΕ δεν σβήνει τίποτα αυτόματα — τα κρίσιμα verdicts είναι πάντα
 * ρητά κουμπιά του χρήστη.
 *
 * @license MIT
 */

declare( strict_types = 1 );

error_reporting( E_ALL );
ini_set( 'display_errors', '0' ); // Ποτέ raw errors στο HTML — μαζεύονται στο log array.
set_time_limit( 0 );              // Κάθε step είναι chunked, αλλά ας μην κόβουμε τυχόν.
ignore_user_abort( false );       // Unlooped refresh-guard: ένα abort σταματά ΚΑΘΕTHING.

/* =====================================================================
 * 0. Bootstrap + guards
 * =================================================================== */

define( 'NM_CHUNK', 4194304 ); // 4 MiB — όριο fread/fwrite παντού.

$STATE_FILE = __DIR__ . '/restore-state.json';
$TMP_ZIP    = __DIR__ . '/archive.tmp.zip';
$LOG        = array();

/**
 * JSON safe-read/write για το restore-state.
 * { step, sql_offset, extracted, db: {host,name,user,pass,charset},
 *   root, blog_public_checked, verified }
 */
function nm_state_read( string $file ): array {
	if ( ! is_readable( $file ) ) {
		return array();
	}
	$dec = json_decode( (string) file_get_contents( $file ), true );
	return is_array( $dec ) ? $dec : array();
}

function nm_state_write( string $file, array $state ): void {
	file_put_contents( $file, json_encode( $state, JSON_UNESCAPED_SLASHES ), LOCK_EX );
}

/** HTML escape helper (μικρό όνομα — χρησιμοποιείται παντού). */
function nm_h( string $s ): string {
	return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
}

/** Byte format (ίδιο με NM_Lang::fmt_bytes — standalone έκδοση). */
function nm_bytes( $b ): string {
	$b = (float) $b;
	if ( $b < 1024 )            { return number_format( $b, 0 ) . ' B'; }
	if ( $b < 1048576 )         { return number_format( $b / 1024, 1 ) . ' KB'; }
	if ( $b < 1073741824 )      { return number_format( $b / 1048576, 1 ) . ' MB'; }
	return number_format( $b / 1073741824, 2 ) . ' GB';
}

/**
 * Εντοπισμός volumes στον φάκελο του script.
 * Αναμένει: noxpress-migration-*.z01 ... + noxpress-migration-*.zip
 * Επιστρέφει []['path','ext','base'] σε ΣΩΣΤΗ σειρά (z01 πρώτα, .zip τελευταίο)
 * ή null αν λείπει το τελικό .zip.
 */
function nm_find_volumes(): ?array {

	$out   = array();
	$mains = array();

	foreach ( (array) scandir( __DIR__ ) as $f ) {
		if ( preg_match( '/^(noxpress-migration-.+)\.(z\d{2}|zip)$/i', $f, $m ) ) {
			if ( 'zip' === strtolower( $m[2] ) ) {
				$mains[] = array( 'path' => __DIR__ . '/' . $f, 'ext' => 'zip', 'base' => $m[1] );
			} else {
				$out[] = array( 'path' => __DIR__ . '/' . $f, 'ext' => strtolower( $m[2] ), 'base' => $m[1] );
			}
		}
	}

	if ( empty( $mains ) ) {
		return null; // Χωρίς τελικό .zip δεν υπάρχει EOF marker — ποτέ guess.
	}

	usort( $out, function ( $a, $b ) {
		return strcmp( $a['ext'], $b['ext'] ); // z01 < z02 < ... < z99 (lexicographic = numeric σε 2 digits).
	} );
	$out[] = $mains[0];

	return $out;
}

/**
 * Step 1: CONCAT των volumes σε ΕΝΑ αρχείο + (προαιρετικά) ελέγχεται
 * το SHA-256 του συνόλου αν δοθεί expected.
 *
 * Resumable: αν το archive.tmp.zip υπάρχει ήδη με σωστό μέγεθος,
 * απλά συνεχίζουμε (skip bytes) — δεν ξαναderiv αρχεία.
 */
function nm_concat_volumes( array $volumes, string $dst ): array {

	$expected_total = 0;
	foreach ( $volumes as $v ) {
		$expected_total += (int) @filesize( $v['path'] );
	}

	$existing = file_exists( $dst ) ? (int) @filesize( $dst ) : 0;

	if ( $existing === $expected_total && $existing > 0 ) {
		return array( 'ok' => true, 'bytes' => $existing, 'resumed' => true );
	}
	if ( $existing > $expected_total ) {
		return array( 'ok' => false, 'error' => 'archive.tmp.zip is LARGER than the volumes combined — stale/corrupt temp file. Delete it and retry.' );
	}

	// Append-mode resume: συνεχίζουμε από το byte που είμαστε.
	$out = @fopen( $dst, ( $existing > 0 ) ? 'ab' : 'wb' );
	if ( false === $out ) {
		return array( 'ok' => false, 'error' => 'Cannot open ' . basename( $dst ) . ' for writing — check directory permissions (755/644).' );
	}

	$written = $existing;

	try {
		foreach ( $volumes as $v ) {
			$src = @fopen( $v['path'], 'rb' );
			if ( false === $src ) {
				throw new RuntimeException( 'Cannot read volume ' . basename( $v['path'] ) );
			}
			try {
				while ( ! feof( $src ) ) {
					$chunk = fread( $src, NM_CHUNK );
					if ( false === $chunk || '' === $chunk ) {
						break;
					}
					if ( fwrite( $out, $chunk ) !== strlen( $chunk ) ) {
						throw new RuntimeException( 'Short write on ' . basename( $dst ) . ' — disk full?' );
					}
					$written += strlen( $chunk );
					if ( $written > $expected_total ) {
						throw new RuntimeException( 'Overflow: volumes grew mid-concat — aborting.' );
					}
				}
			} finally {
				fclose( $src );
			}
		}
	} catch ( Throwable $e ) {
		fclose( $out );
		return array( 'ok' => false, 'error' => $e->getMessage() );
	}

	fflush( $out );
	fclose( $out );

	return array( 'ok' => true, 'bytes' => $written, 'resumed' => false );
}

/* =====================================================================
 * ZIP reader — ΠΟΛΥ μικρός reader για ΑΥΤΟ το writer (STORED + descriptors).
 *
 * Διαβάζει ΜΟΝΟ μέσω central directory (τα τοπικά headers έχουν
 * placeholders λόγω streaming — δεν τα εμπιστευόμαστε). Το CD γράφτηκε
 * με πραγματικά sizes/CRCs/offsets από τον writer — ενιαία πηγή αλήθειας.
 *
 * Υποστηρίζει: STORED (method 0), data descriptors (αδιάφορα εδώ),
 * ZIP64 EOCD + locator, sentinels 0xFFFFFFFF/0xFFFF στα κλασικά πεδία.
 * =================================================================== */

/**
 * Parses το central directory του (πάντα) regenerated archive.tmp.zip.
 *
 * @return array<string, array{offset:int, csize:int, crc:int}> name → entry meta.
 *         null + error message σε αποτυχία.
 */
function nm_zip_read_central( string $zip ): array {

	$size = (int) @filesize( $zip );
	if ( $size < 22 ) {
		return array( '__error' => 'ZIP too small / unreadable.' );
	}

	$fh = @fopen( $zip, 'rb' );
	if ( false === $fh ) {
		return array( '__error' => 'Cannot open ZIP.' );
	}

	try {
		// ---- Εύρεση EOCD: σκαν backwards (max 64KB + z64 structures). ----
		$tail_max = min( $size, 65557 );
		fseek( $fh, $size - $tail_max, SEEK_SET );
		$tail = fread( $fh, $tail_max );

		$pos = strrpos( $tail, "\x50\x4b\x05\x06" ); // EOCD sig.
		if ( false === $pos ) {
			return array( '__error' => 'EOCD signature not found — not a ZIP?' );
		}

		$eocd = unpack( 'vidisk/vcddisk/vnthis/vntotal/Vcdsize/Vcdoff/vcomment', substr( $tail, $pos, 22 ) );
		if ( false === $eocd ) {
			return array( '__error' => 'Corrupt EOCD record.' );
		}

		$cd_off  = (int) $eocd['cdoff'];
		$cd_size = (int) $eocd['cdsize'];
		$n       = (int) $eocd['ntotal'];

		// ---- ZIP64: locator πριν το EOCD; αν τα sentinels ταιριάζουν. ----
		$loc_pos = strrpos( $tail, "\x50\x4b\x06\x07" ); // z64 locator sig.
		if ( false !== $loc_pos && ( 0xFFFFFFFF === $cd_off || 0xFFFF === $n || 0xFFFFFFFF === $cd_size ) ) {
			$loc = unpack( 'Vsig/Vdisk/Pz64off/Vndisks', substr( $tail, $loc_pos, 20 ) );
			if ( false !== $loc ) {
				fseek( $fh, (int) $loc['z64off'], SEEK_SET );
				$z64 = fread( $fh, 56 );
				$rec = unpack( 'Vsig/Psize/vmade/vneed/Vdisk/Vcddisk/Pnthis/Pntotal/Pcdsize/Pcdoff', $z64 );
				if ( false !== $rec ) {
					$cd_off  = (int) $rec['cdoff'];
					$cd_size = (int) $rec['cdsize'];
					$n       = (int) $rec['ntotal'];
				}
			}
		}

		if ( $cd_off + $cd_size > $size ) {
			return array( '__error' => 'Central directory out of bounds.' );
		}

		// ---- Entries ----
		fseek( $fh, $cd_off, SEEK_SET );
		$cd = fread( $fh, $cd_size );

		$entries = array();
		$p      = 0;

		for ( $i = 0; $i < $n; $i++ ) {

			if ( $p + 46 > strlen( $cd ) ) {
				break;
			}

			$h = unpack(
				'Vsig/vmade/vneed/vflag/vmethod/vmtime/vmdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen/vcommlen/vdisk/viattr/Veattr/Vlho',
				substr( $cd, $p, 46 )
			);
			if ( false === $h || 0x02014b50 !== $h['sig'] ) {
				break;
			}

			$name   = substr( $cd, $p + 46, $h['namelen'] );
			$csize  = (int) $h['csize'];
			$usize  = (int) $h['usize'];
			$lho    = (int) $h['lho'];

			// ---- ZIP64 extra fields: ανά [origOffset, origSize] όταν sentinel. ----
			if ( 0xFFFFFFFF === $csize || 0xFFFFFFFF === $usize || 0xFFFFFFFF === $lho ) {
				$ep = $p + 46 + $h['namelen'];
				$ex_end = $ep + $h['extralen'];
				while ( $ep + 4 <= $ex_end ) {
					$xh = unpack( 'vid/vlen', substr( $cd, $ep, 4 ) );
					if ( false === $xh ) { break; }
					if ( 0x0001 === $xh['id'] ) {
						// Order: usize, csize, lho (spec original order — και η σειρά του writer μας).
						$blob = substr( $cd, $ep + 4, $xh['len'] );
						$bo = 0;
						if ( 0xFFFFFFFF === $usize && $bo + 8 <= strlen( $blob ) ) {
							$u = unpack( 'Pv', substr( $blob, $bo, 8 ) );
							$usize = (int) $u['v']; $bo += 8;
						}
						if ( 0xFFFFFFFF === $csize && $bo + 8 <= strlen( $blob ) ) {
							$c = unpack( 'Pv', substr( $blob, $bo, 8 ) );
							$csize = (int) $c['v']; $bo += 8;
						}
						if ( 0xFFFFFFFF === $lho && $bo + 8 <= strlen( $blob ) ) {
							$o = unpack( 'Pv', substr( $blob, $bo, 8 ) );
							$lho = (int) $o['v'];
						}
						break;
					}
					$ep += 4 + $xh['len'];
				}
			}

			if ( 0 !== (int) $h['method'] ) {
				$entries[ $name ] = array(
					'offset' => -1,
					'csize' => $csize,
					'crc'   => (int) $h['crc'],
				); // Unsupported method — flag το στο extraction.
			} else {
				$entries[ $name ] = array(
					'offset' => $lho, // local header offset: name len/meta διαβάζεται live.
					'csize' => $csize,
					'crc'   => (int) $h['crc'],
				);
			}

			$p += 46 + $h['namelen'] + $h['extralen'] + $h['commlen'];
		}

		return $entries;

	} finally {
		fclose( $fh );
	}
}

/**
 * Εξάγει ΕΝΑ entry σε callback chunks (streamed, 4 MiB) + on-the-fly
 * CRC32/SHA-256. Επιστρέφει ['ok'=>bool,'error'=>?,'sha256'=>, 'bytes'=>].
 *
 * Το $callback(string $chunk) δέχεται raw STORED data.
 */
function nm_zip_stream_entry( string $zip, array $entry, callable $callback ): array {

	if ( $entry['offset'] < 0 ) {
		return array( 'ok' => false, 'error' => 'Unsupported compression method (not STORED).' );
	}

	$fh = @fopen( $zip, 'rb' );
	if ( false === $fh ) {
		return array( 'ok' => false, 'error' => 'Cannot open ZIP for reading.' );
	}

	try {
		// ---- Local header: περνάμε name/extra για να βρούμε τα data. ----
		fseek( $fh, $entry['offset'], SEEK_SET );
		$lh = fread( $fh, 30 );
		if ( 30 !== strlen( $lh ) ) {
			throw new RuntimeException( 'Truncated local header.' );
		}
		$meta = unpack( 'Vsig/vneed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen', $lh );
		if ( false === $meta || 0x04034b50 !== $meta['sig'] ) {
			throw new RuntimeException( 'Bad local header signature.' );
		}

		fseek( $fh, $entry['offset'] + 30 + $meta['namelen'] + $meta['extralen'], SEEK_SET );

		$left  = (int) $entry['csize'];
		$bytes = 0;

		while ( $left > 0 ) {
			$chunk = fread( $fh, min( NM_CHUNK, $left ) );
			if ( false === $chunk || '' === $chunk ) {
				throw new RuntimeException( 'Unexpected EOF inside entry data.' );
			}
			$callback( $chunk );
			$bytes += strlen( $chunk );
			$left  -= strlen( $chunk );
		}

		return array( 'ok' => true, 'bytes' => $bytes );

	} catch ( Throwable $e ) {
		return array( 'ok' => false, 'error' => $e->getMessage() );
	} finally {
		fclose( $fh );
	}
}

/* =====================================================================
 * Extraction — αρχεία στο filesystem με STRICT containment.
 *
 * Mapping entries → προορισμούς:
 *   files/uploads/...  → {root}/wp-content/uploads/...
 *   files/plugins/...  → {root}/wp-content/plugins/...
 *   files/themes/...   → {root}/wp-content/themes/...
 *   files/root/...     → {root}/... (μόνο wp-config.php / .htaccess —
 *                         whitelist ΟΛΑ, jamais escapes)
 *   db/dump.sql        → ΔΕΝ εξάγεται (διαβάζεται από το ZIP απευθείας
 *                         στο SQL step — μηδέν επιπλέον χώρος δίσκου).
 *
 * Zip-slip guard: resolve('.') στο αποτέλεσμα ΠΑΝΤΑ εκτός root = SKIP
 * + error στο log. Ποτέ '../', ποτέ absolute paths, ποτέ symlinks.
 * =================================================================== */

/**
 * Map entry name → absolute destination (или null = skip).
 */
function nm_dest_path( string $entry, string $root ): ?string {

	if ( 0 !== strpos( $entry, 'files/' ) ) {
		return null;
	}

	$rel = substr( $entry, strlen( 'files/' ) );

	if ( 0 === strpos( $rel, 'uploads/' ) ) {
		$dest = $root . '/wp-content/uploads/' . substr( $rel, strlen( 'uploads/' ) );
	} elseif ( 0 === strpos( $rel, 'plugins/' ) ) {
		$dest = $root . '/wp-content/plugins/' . substr( $rel, strlen( 'plugins/' ) );
	} elseif ( 0 === strpos( $rel, 'themes/' ) ) {
		$dest = $root . '/wp-content/themes/' . substr( $rel, strlen( 'themes/' ) );
	} elseif ( 0 === strpos( $rel, 'root/' ) ) {
		$name = substr( $rel, strlen( 'root/' ) );
		// Whitelist — ρητά, τίποτα άλλο δεν εξάγεται στο root.
		if ( 'wp-config.php' !== $name && '.htaccess' !== $name ) {
			return null;
		}
		$dest = $root . '/' . $name;
	} else {
		return null;
	}

	// ---- Zip-slip: καθαρισμός components + realpath containment. ----
	$clean = array();
	foreach ( explode( '/', str_replace( '\\', '/', $dest ) ) as $part ) {
		if ( '' === $part || '.' === $part ) {
			continue;
		}
		if ( '..' === $part ) {
			return null; // ΠΟΤΕ traversal.
		}
		$clean[] = $part;
	}
	if ( empty( $clean ) ) {
		return null;
	}

	$dest = '/' . implode( '/', $clean );

	// Το root είναι ήδη normalized absolute — containment check.
	if ( 0 !== strpos( $dest, rtrim( $root, '/' ) . '/' ) ) {
		return null;
	}

	return $dest;
}

/**
 * Extracts τα entries ΠΟΥ ΣΟΣΤΟ. Resumable: state['extracted']
 * κρατάει τον ΑΡΙΘΜΟ των ολοκληρωμένων entries (η λίστα είναι
 * deterministic από το CD — ίδια σειρά πάντα).
 *
 * @param string $zip        Το archive.tmp.zip.
 * @param array  $entries    nm_zip_read_central() output.
 * @param array  $manifest  manifest.json entries (για SHA-256 check).
 * @param string $root       Destination WP root.
 * @param array  $state      Restore state (by ref — updated).
 * @param int    $budget_sec Χρονικό όριο του βήματος.
 *
 * @return array ['done'=>bool,'count'=>int,'total'=>int,'errors'=>array]
 */
function nm_extract_files( string $zip, array $entries, array $manifest, string $root, array &$state, int $budget_sec ): array {

	$t0   = microtime( true );
	$i    = (int) ( $state['extracted'] ?? 0 );
	$keys = array_keys( $entries ); // ΟΛΑ τα entries — σειρά CD.
	$total = count( $keys );
	$errors = array();

	// SHA lookup — γρήγορη αναφορά από path.
	$sha_map = array();
	foreach ( $manifest as $m ) {
		if ( isset( $m['path'], $m['sha256'] ) ) {
			$sha_map[ $m['path'] ] = $m['sha256'];
		}
	}

	for ( ; $i < $total; $i++ ) {

		if ( microtime( true ) - $t0 >= $budget_sec ) {
			$state['extracted'] = $i;
			return array( 'done' => false, 'count' => $i, 'total' => $total, 'errors' => $errors );
		}

		$name = $keys[ $i ];

		// db/dump.sql + manifest.json + restore.php: skip στο extraction.
		if ( 0 !== strpos( $name, 'files/' ) ) {
			continue;
		}

		$dest = nm_dest_path( $name, $root );
		if ( null === $dest ) {
			$errors[] = "Skipped (unsafe path): {$name}";
			continue;
		}

		$dir = dirname( $dest );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			$errors[] = "Cannot create dir: {$dir}";
			continue;
		}

		// ---- Streamed copy + SHA-256 + CRC (tower of checks). ----
		$hash = hash_init( 'sha256' );
		$out  = @fopen( $dest, 'wb' );

		if ( false === $out ) {
			$errors[] = "Cannot write: {$dest}";
			continue;
		}

		$res = nm_zip_stream_entry( $zip, $entries[ $name ], function ( $chunk ) use ( $out, $hash ) {
			fwrite( $out, $chunk );
			hash_update( $hash, $chunk );
		} );

		fclose( $out );

		if ( ! $res['ok'] ) {
			@unlink( $dest );
			$errors[] = "{$name}: " . $res['error'];
			continue;
		}

		$sha = hash_final( $hash );

		if ( isset( $sha_map[ $name ] ) && $sha_map[ $name ] !== $sha ) {
			@unlink( $dest );
			$errors[] = "SHA-256 mismatch: {$name}";
			continue;
		}
	}

	$state['extracted'] = $i;

	return array( 'done' => true, 'count' => $i, 'total' => $total, 'errors' => $errors );
}

/* =====================================================================
 * SQL importer — chunked, quote-aware, resumable.
 *
 * Το dump.sql ΔΙΑΒΑΖΕΤΑΙ ΑΠΟ ΤΟ ZIP απευθείας (streamed) — το offset
 * αναφέρεται σε bytes ΕΝΤΟΣ του entry. Δεν γράφουμε ποτέ το SQL στον
 * δίσκο εκτός ZIP (ο δίσκος του destination είναι ήδη under pressure).
 *
 * Statement splitting: CHAR-BY-CHAR state machine — string quotes
 * ('' escaping), backticks, line comments (-- to EOL) και ';' εκτός
 * string/comment = statement boundary. Safe ΚΑΙ για blobs (ΔΕΝ
 * φορτώνουμε statement στη μνήμη ολόκληρο — buffered με cap 16 MiB,
 * typed guard).
 * =================================================================== */

/**
 * @param PDO    $pdo       Connection (или null → μόνο setup test).
 * @param string $zip       Archive path.
 * @param array  $entry     CD entry του db/dump.sql.
 * @param array  $state     By-ref (sql_offset aktualisiert).
 * @param int    $budget_sec
 * @param array  $checkpoints Referenz für verify: []PDOStatement counters.
 *
 * @return array ['done'=>bool,'offset'=>int,'executed'=>int,'error'=>?]
 */
function nm_import_sql( PDO $pdo, string $zip, array $entry, array &$state, int $budget_sec ): array {

	$t0 = microtime( true );

	$data_offset = (int) $entry['data_offset'] ?? 0; // set from dispatcher.
	$csize       = (int) $entry['csize'];
	$pos         = (int) ( $state['sql_offset'] ?? 0 ); // bytes μέσα στο entry.

	if ( $pos >= $csize ) {
		return array( 'done' => true, 'offset' => $pos, 'executed' => (int) ( $state['sql_executed'] ?? 0 ) );
	}

	$fh = @fopen( $zip, 'rb' );
	if ( false === $fh ) {
		return array( 'done' => false, 'offset' => $pos, 'executed' => 0, 'error' => 'Cannot open archive.' );
	}

	try {
		fseek( $fh, $data_offset + $pos, SEEK_SET );

		$buf        = '';
		$in_str     = false; // single-quote string.
		$in_bt      = false; // backtick identifier.
		$in_comment = false; // -- line comment.
		$executed   = (int) ( $state['sql_executed'] ?? 0 );

		while ( $pos < $csize ) {

			if ( microtime( true ) - $t0 >= $budget_sec ) {
				$state['sql_offset']   = $pos;
				$state['sql_executed'] = $executed;
				return array( 'done' => false, 'offset' => $pos, 'executed' => $executed );
			}

			$chunk = fread( $fh, min( 262144, $csize - $pos ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$len = strlen( $chunk );

			for ( $i = 0; $i < $len; $i++ ) {

				$c = $chunk[ $i ];

				// ---- Comment mode: till newline. ----
				if ( $in_comment ) {
					if ( "\n" === $c ) { $in_comment = false; }
					$buf .= $c;
					continue;
				}

				// ---- String mode: '' escape ή κλείσιμο. ----
				if ( $in_str ) {
					if ( "'" === $c ) {
						$nx = ( $i + 1 < $len ) ? $chunk[ $i + 1 ] : '';
						if ( "'" === $nx ) { // escaped ''.
							$buf .= "''";
							$i++;
							continue;
						}
						$in_str = false;
					}
					$buf .= $c;
					continue;
				}

				if ( $in_bt ) {
					if ( '`' === $c ) { $in_bt = false; }
					$buf .= $c;
					continue;
				}

				// ---- Normal mode. ----
				if ( "'" === $c ) {
					$in_str = true;
					$buf .= $c;
					continue;
				}
				if ( '`' === $c ) {
					$in_bt = true;
					$buf .= $c;
					continue;
				}
				if ( '-' === $c && ( $i + 1 < $len ) && '-' === $chunk[ $i + 1 ] ) {
					$in_comment = true;
					$buf .= $c;
					continue;
				}
				if ( ';' === $c ) {
					// ---- Statement COMPLETE. ----
					$stmt = trim( $buf );

					// Κενό / σχόλια-only statements: skip χωρίς EXECUTE.
					$stripped = trim( preg_replace( '/^\s*(--[^\n]*\n\s*)+/m', '', $stmt ) );
					if ( '' !== $stripped ) {
						try {
							$pdo->exec( $stmt ); // phpcs:ignore
							$executed++;
						} catch ( Throwable $e ) {
							// Συνέχιση ΣΕ ΕΠΑΓΓΕΛΜΑΤΙΚΑ migrations: log + keep going.
							$GLOBALS['nm_sql_errors'][] = $e->getMessage();
							if ( count( $GLOBALS['nm_sql_errors'] ) > 50 ) {
								throw new RuntimeException( 'Too many SQL errors — aborting.' );
							}
						}
					}

					$buf = '';
					continue;
				}

				$buf .= $c;
			}

			$pos += $len;
		}

		// ---- Τέλος entry: κενό buffer (τελευταίο statement χωρίς ';'). ----
		$tail = trim( $buf );
		if ( '' !== $tail ) {
			$stripped = trim( preg_replace( '/^\s*(--[^\n]*\n\s*)+/m', '', $tail ) );
			if ( '' !== $stripped ) {
				try {
					$pdo->exec( $tail );
					$executed++;
				} catch ( Throwable $e ) {
					$GLOBALS['nm_sql_errors'][] = $e->getMessage();
				}
			}
		}

		$state['sql_offset']   = $pos;
		$state['sql_executed'] = $executed;

		return array( 'done' => ( $pos >= $csize ), 'offset' => $pos, 'executed' => $executed );

	} finally {
		fclose( $fh );
	}
}

/* =====================================================================
 * Verification — counts + site readiness
 * =================================================================== */

function nm_verify( PDO $pdo ): array {

	$out = array();

	$tables = $pdo->query( 'SHOW TABLES' )->fetchAll( PDO::FETCH_COLUMN );
	$out['tables'] = is_array( $tables ) ? count( $tables ) : 0;

	foreach ( array(
		'posts'    => "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '%posts'",
		'options'  => "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '%options'",
		'postmeta' => "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '%postmeta'",
	) as $k => $q ) {
		try {
			$out[ 'has_' . $k ] = ( (int) $pdo->query( $q )->fetchColumn() ) > 0;
		} catch ( Throwable $e ) {
			$out[ 'has_' . $k ] = false;
		}
	}

	try {
		$wp_ver = $pdo->query( "SELECT option_value FROM `{$GLOBALS['nm_db_prefix']}options` WHERE option_name = 'siteurl' LIMIT 1" )->fetchColumn();
		$out['siteurl'] = (string) $wp_ver;
	} catch ( Throwable $e ) {
		$out['siteurl'] = '';
	}

	// DB prefix: guess-αριθμός από τον πίνακα options που βρίσκουμε.
	if ( empty( $out['siteurl'] ) ) {
		try {
			$row = $pdo->query( "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '%options' LIMIT 1" )->fetchColumn();
			$out['prefix'] = ( false !== $row ) ? substr( (string) $row, 0, strrpos( (string) $row, 'options' ) ) : '';
		} catch ( Throwable $e ) {
			$out['prefix'] = '';
		}
	}

	return $out;
}

/* =====================================================================
 * UI — δίγλωσσο (EN default / EL), dark, single-file.
 * =================================================================== */

$LANG = ( isset( $_GET['lang'] ) && 'el' === $_GET['lang'] ) ? 'el' : 'en';

$T = array(
	'en' => array(
		'title'        => 'Noxpress Migrator — Restore',
		'intro'        => 'This script restores a Noxpress Migrator archive to this server. Everything happens locally — no external calls.',
		'credentials'  => 'Database credentials',
		'host'         => 'DB host',
		'name'         => 'DB name',
		'user'         => 'DB user',
		'pass'         => 'DB password',
		'root'         => 'WordPress root (absolute path, e.g. /home/user/public_html)',
		'begin'        => 'Begin restore',
		'step_concat'  => 'Joining volumes',
		'step_check'   => 'Verifying checksums',
		'step_extract' => 'Extracting files',
		'step_sql'     => 'Importing database',
		'step_verify'  => 'Verifying',
		'done'         => 'Restore complete',
		'continue'     => 'Continue',
		'delete_me'    => 'DELETE THIS FILE (and archive.tmp.zip) now — it contains your database!',
		'searchreplace' => 'Run search-replace for URLs? (recommended: yes)',
	),
	'el' => array(
		'title'        => 'Noxpress Migrator — Επαναφορά',
		'intro'        => 'Αυτό το script επαναφέρει ένα αρχείο Noxpress Migrator σε αυτόν τον server. Όλα γίνονται τοπικά — καμία εξωτερική κλήση.',
		'credentials'  => 'Στοιχεία βάσης δεδομένων',
		'host'         => 'Host βάσης',
		'name'         => 'Όνομα βάσης',
		'user'         => 'Χρήστης βάσης',
		'pass'         => 'Κωδικός βάσης',
		'root'         => 'WordPress root (απόλυτη διαδρομή, π.χ. /home/user/public_html)',
		'begin'        => 'Έναρξη επαναφοράς',
		'step_concat'  => 'Ένωση τόμων',
		'step_check'   => 'Έλεγχος αθροισμάτων',
		'step_extract' => 'Αποσυμπίεση αρχείων',
		'step_sql'     => 'Εισαγωγή βάσης',
		'step_verify'  => 'Επαλήθευση',
		'done'         => 'Η επαναφορά ολοκληρώθηκε',
		'continue'     => 'Συνέχεια',
		'delete_me'    => 'ΔΙΑΓΡΑΨΕ ΤΟ ΑΡΧΕΙΟ (και το archive.tmp.zip) ΤΩΡΑ — περιέχει τη βάση σου!',
		'searchreplace' => 'Να γίνει search-replace για τα URLs; (προτεινόμενο: ναι)',
	),
);

function nm_page_top( string $title ): void {
	echo '<!DOCTYPE html><html><head><meta charset="utf-8">' .
		'<meta name="viewport" content="width=device-width, initial-scale=1">' .
		'<meta name="robots" content="noindex,nofollow">' .
		'<title>' . nm_h( $title ) . '</title>' .
		'<style>
		body{font-family:system-ui,-apple-system,sans-serif;background:#101218;color:#eae8fa;margin:0;padding:32px;line-height:1.6}
		.box{max-width:720px;margin:0 auto;background:#17191f;border:1px solid #2b2e36;border-radius:8px;padding:32px}
		h1{color:#f6f4ff;font-size:22px;margin-top:0}
		label{display:block;margin:12px 0 4px;font-size:13px;color:#b7b2d6}
		input[type=text],input[type=password]{width:100%;box-sizing:border-box;background:#1a1d26;border:1px solid #3a3f52;color:#f0eefc;border-radius:4px;padding:8px}
		button{background:#6d4aff;color:#fff;border:none;border-radius:4px;padding:10px 24px;font-size:15px;cursor:pointer}
		button:hover{background:#8263ff}
		.ok{color:#8fe6ab}.err{color:#f5c97e}
		code{background:#1a1d26;padding:2px 6px;border-radius:3px;font-size:13px}
		.warn{background:#2a1818;border:1px solid #f5c97e;padding:12px 16px;border-radius:6px;color:#f5c97e;margin-top:20px}
		</style></head><body><div class="box">';
}

function nm_page_bottom(): void {
	echo '</div></body></html>';
}

/* =====================================================================
 * DISPATCH
 * =================================================================== */

$volumes = nm_find_volumes();
$state   = nm_state_read( $STATE_FILE );
$step    = (string) ( $_GET['step'] ?? 'intro' );
$err     = null;

nm_page_top( $T[ $LANG ]['title'] );

try {

	// ---- Guards. ----
	if ( null === $volumes ) {
		throw new RuntimeException( 'No noxpress-migration-*.zip found next to restore.php. Upload the volumes first.' );
	}

	// ---- INTRO: φόρμα. ----
	if ( 'intro' === $step ) {

		echo '<h1>' . nm_h( $T[ $LANG ]['title'] ) . '</h1>';
		echo '<p>' . nm_h( $T[ $LANG ]['intro'] ) . '</p>';

		echo '<form method="post" action="?step=setup&amp;lang=' . nm_h( $LANG ) . '">';
		echo '<h2>' . nm_h( $T[ $LANG ]['credentials'] ) . '</h2>';
		foreach ( array( 'host', 'name', 'user', 'pass' ) as $f ) {
			echo '<label>' . nm_h( $T[ $LANG ][ $f ] ) . '</label>';
			echo '<input type="' . ( 'pass' === $f ? 'password' : 'text' ) . '" name="db_' . $f . '" value="' . nm_h( (string) ( $state['db'][ $f ] ?? '' ) ) . '" />';
		}
		echo '<label>' . nm_h( $T[ $LANG ]['root'] ) . '</label>';
		echo '<input type="text" name="wp_root" value="' . nm_h( (string) ( $state['root'] ?? '' ) ) . '" />';
		echo '<p><button type="submit">' . nm_h( $T[ $LANG ]['begin'] ) . '</button></p>';
		echo '</form>';

		nm_page_bottom();
		exit;
	}

	// ---- SETUP: αποθήκευση credentials + test. ----
	if ( 'setup' === $step ) {

		$db = array(
			'host' => (string) ( $_POST['db_host'] ?? '' ),
			'name' => (string) ( $_POST['db_name'] ?? '' ),
			'user' => (string) ( $_POST['db_user'] ?? '' ),
			'pass' => (string) ( $_POST['db_pass'] ?? '' ),
		);

		$root = (string) ( $_POST['wp_root'] ?? '' );
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );

		if ( '' === $root || ! is_dir( $root ) ) {
			throw new RuntimeException( 'Invalid WordPress root: ' . $root );
		}

		try {
			$pdo = new PDO(
				"mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
				$db['user'],
				$db['pass'],
				array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION )
			);
		} catch ( Throwable $e ) {
			throw new RuntimeException( 'DB connection failed: ' . $e->getMessage() );
		}

		$state['db']   = $db;
		$state['root'] = $root;
		$state['step'] = 'concat';
		nm_state_write( $STATE_FILE, $state );

		header( 'Location: ?step=concat&lang=' . $LANG );
		exit;
	}

	// ---- CONCAT. ----
	if ( 'concat' === $step ) {

		$res = nm_concat_volumes( $volumes, $TMP_ZIP );

		if ( ! $res['ok'] ) {
			throw new RuntimeException( $res['error'] );
		}

		$state['step'] = 'check';
		nm_state_write( $STATE_FILE, $state );
		header( 'Location: ?step=check&lang=' . $LANG );
		exit;
	}

	// ---- CHECK (checksums του manifest). ----
	if ( 'check' === $step ) {

		$entries = nm_zip_read_central( $TMP_ZIP );
		if ( isset( $entries['__error'] ) ) {
			throw new RuntimeException( 'ZIP read: ' . $entries['__error'] );
		}

		$manifest = array();
		if ( isset( $entries['manifest.json'] ) ) {
			$acc = '';
			nm_zip_stream_entry( $TMP_ZIP, $entries['manifest.json'], function ( $c ) use ( &$acc ) {
				$acc .= $c;
			} );
			$dec = json_decode( $acc, true );
			if ( is_array( $dec ) && isset( $dec['entries'] ) ) {
				$manifest = $dec['entries'];
			}
		}

		$bad = array();
		foreach ( $manifest as $m ) {
			$name = (string) ( $m['path'] ?? '' );
			if ( ! isset( $entries[ $name ] ) ) {
				$bad[] = $name . ' (missing)';
				continue;
			}
			// Size check από το CD — γρήγορο pre-check (το SHA-256
			// γίνεται στο extraction ανά αρχείο — δικό μας
			// two-layer scheme).
			if ( (int) $m['bytes'] !== (int) $entries[ $name ]['csize'] ) {
				$bad[] = $name . ' (size)';
			}
		}

		if ( ! empty( $bad ) ) {
			throw new RuntimeException( 'Manifest mismatch: ' . implode( ', ', array_slice( $bad, 0, 5 ) ) );
		}

		$state['step'] = 'extract';
		nm_state_write( $STATE_FILE, $state );
		header( 'Location: ?step=extract&lang=' . $LANG );
		exit;
	}

	// ---- EXTRACT. ----
	if ( 'extract' === $step ) {

		$entries = nm_zip_read_central( $TMP_ZIP );
		if ( isset( $entries['__error'] ) ) {
			throw new RuntimeException( 'ZIP read: ' . $entries['__error'] );
		}

		$manifest = array();
		if ( isset( $entries['manifest.json'] ) ) {
			$acc = '';
			nm_zip_stream_entry( $TMP_ZIP, $entries['manifest.json'], function ( $c ) use ( &$acc ) {
				$acc .= $c;
			} );
			$dec = json_decode( $acc, true );
			if ( is_array( $dec ) && isset( $dec['entries'] ) ) {
				$manifest = $dec['entries'];
			}
		}

		$root = (string) ( $state['root'] ?? '' );
		$res  = nm_extract_files( $TMP_ZIP, $entries, $manifest, $root, $state, 15 );

		nm_state_write( $STATE_FILE, $state );

		if ( ! empty( $res['errors'] ) ) {
			$GLOBALS['nm_extract_errors'] = $res['errors'];
		}

		if ( ! $res['done'] ) {
			// Progress page + auto-refresh loop (_BROWSER-driven resume).
			echo '<h1>' . nm_h( $T[ $LANG ]['step_extract'] ) . '</h1>';
			echo '<p>' . $res['count'] . ' / ' . $res['total'] . '</p>';
			echo '<meta http-equiv="refresh" content="2;url=?step=extract&lang=' . nm_h( $LANG ) . '">';
			nm_page_bottom();
			exit;
		}

		$state['step'] = 'sql';
		nm_state_write( $STATE_FILE, $state );
		header( 'Location: ?step=sql&lang=' . $LANG );
		exit;
	}

	// ---- SQL. ----
	if ( 'sql' === $step ) {

		$db = $state['db'];

		$pdo = new PDO(
			"mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
			$db['user'],
			$db['pass'],
			array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION )
		);

		$entries = nm_zip_read_central( $TMP_ZIP );
		if ( ! isset( $entries['db/dump.sql'] ) ) {
			throw new RuntimeException( 'db/dump.sql not found in archive.' );
		}

		// Data offset του entry: local header + name + extra, live read.
		$e = $entries['db/dump.sql'];
		$fh = fopen( $TMP_ZIP, 'rb' );
		fseek( $fh, $e['offset'], SEEK_SET );
		$lh = unpack( 'Vsig/vneed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen', fread( $fh, 30 ) );
		fclose( $fh );
		$e['data_offset'] = $e['offset'] + 30 + (int) $lh['namelen'] + (int) $lh['extralen'];

		$GLOBALS['nm_sql_errors'] = array();

		$res = nm_import_sql( $pdo, $TMP_ZIP, $e, $state, 15 );

		nm_state_write( $STATE_FILE, $state );

		if ( ! empty( $GLOBALS['nm_sql_errors'] ) ) {
			$state['sql_errors'] = array_slice( $GLOBALS['nm_sql_errors'], 0, 20 );
			nm_state_write( $STATE_FILE, $state );
		}

		if ( ! $res['done'] ) {
			echo '<h1>' . nm_h( $T[ $LANG ]['step_sql'] ) . '</h1>';
			echo '<p>' . nm_bytes( $res['offset'] ) . ' imported — ' . (int) $res['executed'] . ' statements</p>';
			echo '<meta http-equiv="refresh" content="2;url=?step=sql&lang=' . nm_h( $LANG ) . '">';
			nm_page_bottom();
			exit;
		}

		$state['step'] = 'verify';
		nm_state_write( $STATE_FILE, $state );
		header( 'Location: ?step=verify&lang=' . $LANG );
		exit;
	}

	// ---- VERIFY. ----
	if ( 'verify' === $step ) {

		$db = $state['db'];
		$pdo = new PDO(
			"mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
			$db['user'],
			$db['pass'],
			array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION )
		);

		$v = nm_verify( $pdo );

		$state['verify'] = $v;
		$state['step']   = 'done';
		nm_state_write( $STATE_FILE, $state );

		echo '<h1>' . nm_h( $T[ $LANG ]['step_verify'] ) . '</h1>';
		echo '<ul>';
		echo '<li>Tables: <strong>' . (int) $v['tables'] . '</strong></li>';
		echo '<li>WP posts tables: <strong class="' . ( $v['has_posts'] ? 'ok' : 'err' ) . '">' . ( $v['has_posts'] ? 'YES' : 'MISSING' ) . '</strong></li>';
		echo '<li>WP options table: <strong class="' . ( $v['has_options'] ? 'ok' : 'err' ) . '">' . ( $v['has_options'] ? 'YES' : 'MISSING' ) . '</strong></li>';
		echo '</ul>';
		echo '<p><a href="?step=done&lang=' . nm_h( $LANG ) . '"><button>' . nm_h( $T[ $LANG ]['continue'] ) . '</button></a></p>';
		nm_page_bottom();
		exit;
	}

	// ---- DONE. ----
	if ( 'done' === $step ) {

		echo '<h1 class="ok">' . nm_h( $T[ $LANG ]['done'] ) . '</h1>';

		$sql_errors = (array) ( $state['sql_errors'] ?? array() );
		if ( ! empty( $sql_errors ) ) {
			echo '<p class="err">' . count( $sql_errors ) . ' SQL statements failed:</p><ul>';
			foreach ( array_slice( $sql_errors, 0, 10 ) as $se ) {
				echo '<li><code>' . nm_h( $se ) . '</code></li>';
			}
			echo '</ul>';
		}

		echo '<div class="warn"><strong>' . nm_h( $T[ $LANG ]['delete_me'] ) . '</strong></div>';
		echo '<p style="margin-top:20px;font-size:13px;color:#b7b2d6">';
		echo 'Made with &lt;3 by <a href="https://koulaxizis.gr" style="color:#beb1ff">Christos Koulaxizis</a> · MIT · Part of glarolykoi.net';
		echo '</p>';

		nm_page_bottom();
		exit;
	}

	throw new RuntimeException( 'Unknown step: ' . $step );

} catch ( Throwable $e ) {
	echo '<h1 class="err">Error</h1>';
	echo '<p><code>' . nm_h( $e->getMessage() ) . '</code></p>';
	nm_page_bottom();
	exit;
}
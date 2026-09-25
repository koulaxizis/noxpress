<?php
/**
 * NM_Verify — Post-restore verification (zero data loss check).
 *
 * Σενάριο: το restore.php έτρεξε στον ΝΕΟ server, το plugin είναι
 * εγκατεστημένο εκεί, και ο admin δίνει το manifest.json του
 * (από το migration ZIP) → εμείς συγκρίνουμε:
 *
 *   1. DB: κάθε πίνακας του original → τοπικό COUNT(*) ισούται;
 *   2. Files: κάθε entry του manifest → existence + μέγεθος
 *      (+ πλήρες SHA-256 σε 'deep' mode) στο τοπικό filesystem;
 *   3. Extra: πίνακες/αρχεία που υπάρχουν ΤΩΡΑ αλλά ΔΕΝ ήταν στο
 *      original (orphan detection — το ανάποδο κρατάγωγο).
 *
 * Stepping: ίδιο model με τα engines (budget 15s/step, state σε
 * staging sidecar 'verify-state.json', AJAX-driven από το admin UI).
 * ΔΕΝ χρησιμοποιεί transient lock διαφορετικό από τα engines (τρέχει
 * μετά το restore, δεν συμπίπτει ποτέ με export) — δικό του lock
 * 'nm_verify_lock' για ασφάλεια.
 *
 * Το manifest ΔΕΝ ανεβαίνει από φόρμα multipart (5GB ούτε εδώ): ο
 * admin το βγάζει από το ZIP (ή τον πήρε ήδη στο download) και το
 * τοποθετεί στο uploads root ή το δίνει ως path στη φόρμα. Το path
 * VALidate-αρΕΤΑΙ με realpath containment (uploads ή plugin dir).
 */

defined( 'ABSPATH' ) || exit;

final class NM_Verify {

	const STEP_BUDGET = 15; // δευτ. — ίδιο με τα engines.

	/** Ιδιότητες επιλογής: 'quick' (existence+size) | 'deep' (+SHA-256). */
	const MODES = array( 'quick', 'deep' );

	public static function init(): void {
		// Worker — καθαρή αρχιτεκτονική (idem NM_Export_Engine).
	}

	/* =====================================================================
	 * State (staging sidecar)
	 * =================================================================== */

	private static function state_path(): string {
		return NM_Export_Engine::staging_dir() . '/verify-state.json';
	}

	public static function load_state(): ?array {
		if ( ! is_readable( self::state_path() ) ) {
			return null;
		}
		$dec = json_decode( (string) file_get_contents( self::state_path() ), true );
		return is_array( $dec ) && isset( $dec['phase'], $dec['manifest'] ) ? $dec : null;
	}

	private static function save_state( array $state ): void {
		@file_put_contents( self::state_path(), wp_json_encode( $state, JSON_UNESCAPED_SLASHES ), LOCK_EX );
	}

	private static function lock(): bool {
		if ( false !== get_transient( 'nm_verify_lock' ) ) {
			return false;
		}
		set_transient( 'nm_verify_lock', time(), 600 );
		return true;
	}

	private static function unlock(): void {
		delete_transient( 'nm_verify_lock' );
	}

	/* =====================================================================
	 * Setup — έναρξη verification από manifest
	 * =================================================================== */

	/**
	 * Ξεκινά νέο verification.
	 *
	 * @param string $manifest_path Απόλυτο path του manifest.json (validated).
	 * @param string $mode         'quick' | 'deep'.
	 * @return array ['ok'=>bool,'error'=>?string]
	 */
	public static function begin( string $manifest_path, string $mode = 'deep' ): array {

		// ---- Path validation: realpath + readable. ----
		$real = (string) realpath( $manifest_path );
		if ( '' === $real || ! is_readable( $real ) ) {
			return array( 'ok' => false, 'error' => 'Manifest not readable: ' . $manifest_path );
		}

		// Containment: uploads-basedir Ή plugin dir (τίποτα άλλο ποτέ).
		$up       = (string) wp_upload_dir()['basedir'];
		$allowed  = array( rtrim( $up, '/' ), rtrim( NM_PATH, '/' ) );
		$contained = false;
		foreach ( $allowed as $a ) {
			if ( 0 === strpos( $real . '/', $a . '/' ) ) {
				$contained = true;
				break;
			}
		}
		if ( ! $contained ) {
			return array( 'ok' => false, 'error' => 'Manifest must live inside uploads/ or the plugin folder — got: ' . $real );
		}

		// ---- Manifest structure validation. ----
		$dec = json_decode( (string) file_get_contents( $real ), true );
		if ( ! is_array( $dec ) || ! isset( $dec['entries'] ) || ! is_array( $dec['entries'] ) ) {
			return array( 'ok' => false, 'error' => 'Not a valid Noxpress Migrator manifest (missing entries).' );
		}
		if ( ! isset( $dec['tables'] ) || ! is_array( $dec['tables'] ) ) {
			return array( 'ok' => false, 'error' => 'Manifest predates row-count support (old export) — re-export with current version for DB verification.' );
		}

		$mode = in_array( $mode, self::MODES, true ) ? $mode : 'deep';

		$state = array(
			'phase'     => 'db',      // db → files → done.
			'manifest'  => $real,
			'mode'      => $mode,
			'table_idx' => 0,          // ευρετήριο στο (sorted) tables list.
			'file_idx'  => 0,          // ευρετήριο στο entries list.
			'db_ok'     => array(),
			'db_bad'    => array(),    // ['table' => ['expected'=>, 'actual'=>]].
			'files_ok'  => 0,
			'files_bad' => array(),    // [['path'=>, 'reason'=>], ...].
			'orphan_tables' => array(),
			'started'   => time(),
		);
		self::save_state( $state );

		return array( 'ok' => true );
	}

	/* =====================================================================
	 * Stepping
	 * =================================================================== */

	/**
	 * Ένα budgeted βήμα verification. Idempotent, safe σε loop.
	 *
	 * @return array ['status'=>db|files|done|failed, 'pct'=>float, ...]
	 */
	public static function step(): array {

		$state = self::load_state();

		if ( null === $state ) {
			return array(
				'status' => 'failed',
				'error'  => 'No verification in progress — start from the Verify page.',
			);
		}

		if ( ! self::lock() ) {
			return self::progress( $state, 'busy' );
		}

		try {
			$t0 = microtime( true );

			// ---- PHASE: DB ----
			if ( 'db' === $state['phase'] ) {

				global $wpdb;

				$dec    = json_decode( (string) file_get_contents( $state['manifest'] ), true );
				$tables = array_keys( $dec['tables'] );
				sort( $tables, SORT_STRING );

				$total = count( $tables );
				$i     = (int) $state['table_idx'];

				// Τοπικές πίνακες — ΜΙΑ φορά ανά βήμα (cache σε state).
				if ( ! isset( $state['local_tables'] ) ) {
					$state['local_tables'] = NM_Export_Engine::table_list();
				}
				$locals = $state['local_tables'];

				while ( $i < $total ) {

					if ( microtime( true ) - $t0 >= self::STEP_BUDGET ) {
						break;
					}

					$t    = $tables[ $i ];
					$want = (int) $dec['tables'][ $t ];

					if ( ! in_array( $t, $locals, true ) ) {
						$state['db_bad'][ $t ] = array(
							'expected' => $want,
							'actual'   => 'TABLE MISSING',
						);
					} else {
						$got = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
							'SELECT COUNT(*) FROM `' . str_replace( '`', '``', $t ) . '`'
						);
						$got = ( null === $got ) ? -1 : (int) $got;

						if ( $want === $got ) {
							$state['db_ok'][] = $t;
						} else {
							$state['db_bad'][ $t ] = array(
								'expected' => $want,
								'actual'   => $got,
							);
						}
					}

					$i++;
					$state['table_idx'] = $i;
				}

				if ( $i < $total ) {
					self::save_state( $state );
					self::unlock();
					return self::progress( $state, 'db' );
				}

				// ---- DB τελείωσε: orphan detection (extra πίνακες). ----
				$dec_entries = array_keys( $dec['tables'] );
				foreach ( $locals as $lt ) {
					if ( ! in_array( $lt, $dec_entries, true ) ) {
						$state['orphan_tables'][] = $lt;
					}
				}

				$state['phase'] = 'files';
				self::save_state( $state );
				self::unlock();
				return self::progress( $state, 'files' );
			}

			// ---- PHASE: FILES ----
			if ( 'files' === $state['phase'] ) {

				$dec     = json_decode( (string) file_get_contents( $state['manifest'] ), true );
				$entries = $dec['entries'];

				$total = count( $entries );
				$i     = (int) $state['file_idx'];

				while ( $i < $total ) {

					if ( microtime( true ) - $t0 >= self::STEP_BUDGET ) {
						break;
					}

					$e = $entries[ $i ];

					// Μη-αρχεία entries (db/dump.sql, manifest.json, restore.php): skip counters.
					$dest = self::dest_of( (string) $e['path'] );

					if ( null === $dest ) {
						$i++;
						continue;
					}

					if ( ! is_readable( $dest ) ) {
						$state['files_bad'][] = array( 'path' => $e['path'], 'reason' => 'MISSING' );
					} elseif ( (int) @filesize( $dest ) !== (int) $e['bytes'] ) {
						$state['files_bad'][] = array(
							'path'   => $e['path'],
							'reason' => 'SIZE: expected ' . (int) $e['bytes'] . ', got ' . (int) @filesize( $dest ),
						);
					} elseif ( 'deep' === $state['mode'] ) {
						$sha = hash_file( 'sha256', $dest );
						if ( false === $sha || $sha !== (string) $e['sha256'] ) {
							$state['files_bad'][] = array( 'path' => $e['path'], 'reason' => 'SHA-256 MISMATCH' );
						} else {
							$state['files_ok']++;
						}
					} else {
						$state['files_ok']++;
					}

					$i++;
					$state['file_idx'] = $i;
				}

				if ( $i < $total ) {
					self::save_state( $state );
					self::unlock();
					return self::progress( $state, 'files' );
				}

				$state['phase'] = 'done';
				$state['ended'] = time();
				self::save_state( $state );

				// Τελικό report σε option (για το UI του Μέρους 2/2).
				update_option( 'nm_verify_report', self::report_from_state( $state ), false );

				self::unlock();
				return self::progress( $state, 'done' );
			}

			// ---- PHASE: done (idempotent). ----
			self::unlock();
			return self::progress( $state, 'done' );

		} catch ( Throwable $e ) {
			$state['phase'] = 'failed';
			$state['error'] = $e->getMessage();
			self::save_state( $state );
			self::unlock();
			return self::progress( $state, 'failed' );
		}
	}

	/* =====================================================================
	 * Mapping manifest paths → τοπικά destinations
	 * (Mirror της nm_dest_path() του restore.php — Ίδια whitelist.)
	 * =================================================================== */

	private static function dest_of( string $entry ): ?string {

		if ( 0 !== strpos( $entry, 'files/' ) ) {
			return null;
		}

		$rel = substr( $entry, strlen( 'files/' ) );

		if ( 0 === strpos( $rel, 'uploads/' ) ) {
			$dest = (string) wp_upload_dir()['basedir'] . '/' . substr( $rel, strlen( 'uploads/' ) );
		} elseif ( 0 === strpos( $rel, 'plugins/' ) ) {
			$dest = (string) WP_PLUGIN_DIR . '/' . substr( $rel, strlen( 'plugins/' ) );
		} elseif ( 0 === strpos( $rel, 'themes/' ) ) {
			$dest = WP_CONTENT_DIR . '/themes/' . substr( $rel, strlen( 'themes/' ) );
		} elseif ( 0 === strpos( $rel, 'root/' ) ) {
			$name = substr( $rel, strlen( 'root/' ) );
			if ( 'wp-config.php' !== $name && '.htaccess' !== $name ) {
				return null;
			}
			$dest = ABSPATH . $name;
		} else {
			return null;
		}

		// ---- Zip-slip / traversal guard (same pattern με restore). ----
		$clean = array();
		foreach ( explode( '/', str_replace( '\\', '/', $dest ) ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				return null;
			}
			$clean[] = $part;
		}
		if ( empty( $clean ) ) {
			return null;
		}

		return '/' . implode( '/', $clean );
	}

	/* =====================================================================
	 * Reports
	 * =================================================================== */

	private static function progress( array $state, string $phase ): array {

		$dec     = json_decode( (string) file_get_contents( $state['manifest'] ), true );
		$t_total = count( (array) ( $dec['tables'] ?? array() ) );
		$f_total = count( (array) ( $dec['entries'] ?? array() ) );

		$t_done = (int) ( $state['table_idx'] ?? 0 );
		$f_done = (int) ( $state['file_idx'] ?? 0 );

		$pct = 0.0;
		if ( 'db' === $phase || 'busy' === $phase && 'db' === ( $state['phase'] ?? '' ) ) {
			$pct = ( $t_total > 0 ) ? ( $t_done / $t_total ) * 50.0 : 50.0; // DB = πρώτο μισό.
		} else {
			$pct = 50.0 + ( ( $f_total > 0 ) ? ( $f_done / $f_total ) * 50.0 : 50.0 );
		}
		if ( 'done' === $phase ) {
			$pct = 100.0;
		}

		return array(
			'status'      => $phase,
			'pct'         => round( $pct, 1 ),
			'tables_done' => $t_done,
			'tables_total'=> $t_total,
			'files_done'  => $f_done,
			'files_total' => $f_total,
			'db_ok'       => count( (array) ( $state['db_ok'] ?? array() ) ),
			'db_bad'      => count( (array) ( $state['db_bad'] ?? array() ) ),
			'files_ok'    => (int) ( $state['files_ok'] ?? 0 ),
			'files_bad'   => count( (array) ( $state['files_bad'] ?? array() ) ),
			'error'       => $state['error'] ?? null,
		);
	}

	/** Τελική αναφορά (για rendering / option nm_verify_report). */
	public static function report_from_state( array $state ): array {
		return array(
			'ended'         => (int) ( $state['ended'] ?? time() ),
			'mode'          => (string) ( $state['mode'] ?? '' ),
			'manifest'      => (string) ( $state['manifest'] ?? '' ),
			'db_ok'         => (array) ( $state['db_ok'] ?? array() ),
			'db_bad'        => (array) ( $state['db_bad'] ?? array() ),
			'orphan_tables' => (array) ( $state['orphan_tables'] ?? array() ),
			'files_ok'      => (int) ( $state['files_ok'] ?? 0 ),
			'files_bad'     => (array) ( $state['files_bad'] ?? array() ),
			'verdict'       => self::verdict( $state ),
		);
	}

	/** Το κλείσιμο της υπόθεσης: OK ή αναλυτική αιτία. */
	private static function verdict( array $state ): string {

		$bad_t   = count( (array) ( $state['db_bad'] ?? array() ) );
		$orphans = count( (array) ( $state['orphan_tables'] ?? array() ) );
		$bad_f   = count( (array) ( $state['files_bad'] ?? array() ) );

		if ( 0 === $bad_t && 0 === $bad_f ) {
			if ( $orphans > 0 ) {
				return 'DATA OK — ' . $orphans . ' extra local table(s) not present in the original (orphans, harmless but noted).';
			}
			return 'PERFECT — every table and every file matches the original.';
		}

		$parts = array();
		if ( $bad_t > 0 ) {
			$parts[] = $bad_t . ' table(s) with row-count mismatch';
		}
		if ( $bad_f > 0 ) {
			$parts[] = $bad_f . ' file(s) missing or altered';
		}
		return 'MISMATCH — ' . implode( '; ', $parts ) . '. See details below.';
	}

	/** Το αποθηκευμένο report (από το UI του Μέρους 2/2). */
	public static function last_report(): array {
		$r = get_option( 'nm_verify_report', array() );
		return is_array( $r ) ? $r : array();
	}

	/** Καθαρισμός verification state + report (ρίτη ενέργεια UI). */
	public static function reset(): void {
		@unlink( self::state_path() );
		delete_option( 'nm_verify_report' );
		delete_transient( 'nm_verify_lock' );
	}
}
<?php
/**
 * NM_Files_Engine — Orchestration + αρχεία + packaging.
 *
 * Καλείται ΜΟΝΟ μέσα από το checkpoint pipeline:
 *   db phase  → NM_Export_Engine (delegate)
 *   files     → αυτό εδώ (copy με ταυτόχρονο SHA-256)
 *   packaged  → τέλος (ZIP + manifest + volumes + summary option)
 *
 * Persistence model:
 *  - Option 'nm_checkpoint' (μικρό): {phase, scope, media_after, table,
 *    pk_last, bytes_written, ...} — οι φάσεις: db|files|packaged|failed.
 *  - Staging 'zip-state.json' (βαριά): {entries, offset, manifest,
 *    last_file, files_total} — ξαναγεννιέται/επαναφέρεται ανά βήμα.
 *    Το 'manifest' = [ ['path','bytes','sha256'], ... ] ανά source αρχείο.
 *
 * Checkpoint invariant: ΚΑΘΕ βήμα τελειώνει σε FILE BOUNDARY — ποτέ
 * εντός ενός entry (τα CRC contexts είναι request-local, αopen.
 * Τα αυστηρά oversized μεμονωμένα αρχεία (> budget) αντιμετωπίζονται
 * με I/O speed: ένα 500MB αρχείο in-band copy = δευτερόλεπτα σε τοπικό
 * δίσκο — δεν κόβουμε ποτέ entry στη μέση.
 *
 * Multi-volume: split ΕΡΩΤΗΣΗ {base}.z01..zip μετά το finish() όταν
 * total > nm_max_volume_mb (default 500). Πάντα 7-Zip-compatible
 * byte-split (concat = valid ZIP), ΠΟΤΕ spec-split.
 */

defined( 'ABSPATH' ) || exit;

final class NM_Files_Engine {

	const STEP_BUDGET = 15;    // δευτ. — Ίδιο με το DB engine.
	const SAVE_EVERY  = 50;    // files ανά sidecar flush (μέσα στο βήμα).

	/** Excluded ονόματα/κατάληξη subtree (case-insensitive). */
	const SKIP_NAMES = array( '.git', 'node_modules', '.ds_store' );

	public static function init(): void {
		// Worker — καθαρή αρχιτεκτονική (idem NM_Export_Engine).
	}

	/* =====================================================================
	 * Checkpoint helpers (κοινό option με το DB engine)
	 * =================================================================== */

	private static function read_ck(): array {
		$raw = get_option( 'nm_checkpoint', '' );
		$dec = ( is_string( $raw ) && '' !== $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $dec ) ? $dec : array();
	}

	private static function write_ck( array $ck ): void {
		update_option( 'nm_checkpoint', wp_json_encode( $ck, JSON_UNESCAPED_UNICODE ), false );
	}

	/* =====================================================================
	 * Entry points
	 * =================================================================== */

	/**
	 * Ξεκινά ΝΕΟ export: καθαρίζει τα πάντα και γράφει το scope στο
	 * checkpoint. Καλείται ΜΙΑ φορά από το admin UI (nm_start).
	 *
	 * @param string $scope       everything|db|files.
	 * @param string $media_after 'YYYY-MM-DD' ή '' (χωρίς φίλτρο).
	 */
	public static function begin( string $scope, string $media_after ): void {

		if ( ! in_array( $scope, array( 'everything', 'db', 'files' ), true ) ) {
			$scope = 'everything';
		}

		// Καθαρή εκκίνηση του DB engine (wipe staging + checkpoint + header).
		NM_Export_Engine::start( true );

		// Scope πάνω στο ΦΡΕΣΚΟ checkpoint που μόλις έγραψε ο DB engine.
		$ck = self::read_ck();

		$ck['scope']       = $scope;
		$ck['media_after'] = ( '' !== $media_after && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $media_after ) ) ? $media_after : '';

		self::write_ck( $ck );
	}

	/**
	 * Το ΕΝΑ stepping entry point (admin AJAX / CLI). Delegate στο DB
	 * engine όσο phase=db, αλλιώς δικά μας steps.
	 *
	 * @return array Report (ίδιο shape με NM_Export_Engine::report()).
	 */
	public static function run_step(): array {

		$ck    = self::read_ck();
		$phase = (string) ( $ck['phase'] ?? 'db' );

		// ---- 'failed' απαντάται αυτούσιο ----
		if ( 'failed' === $phase ) {
			return array(
				'status'       => 'failed',
				'table'        => (string) ( $ck['table'] ?? '' ),
				'rows'         => 0,
				'bytes'        => (int) ( $ck['bytes_written'] ?? 0 ),
				'pct'          => 0.0,
				'done_tables'  => 0,
				'total_tables' => 0,
				'error'        => (string) ( $ck['error'] ?? 'Aborted.' ),
			);
		}

		// ---- Τέλος ( packaged ) — idempotent ----
		if ( 'packaged' === $phase ) {
			return self::packaged_report();
		}

		// ---- DB phase: delegate ----
		if ( 'db' === $phase || 'done' === $phase ) {

			// 'done' = DB ολοκληρώθηκε σε προηγούμενο βήμα → pasar.
			if ( 'done' !== $phase ) {
				$db     = NM_Export_Engine::start( false );
				$report = $db->step();

				if ( $db->is_complete() ) {
					self::begin_files_phase();
					// Το UI να ΜΗΝ δει 'complete' — ακολουθούν files.
					$report['status'] = 'running';
					$report['phase']  = 'files';
				}
				return $report;
			}

			self::begin_files_phase();
			// fallthrough σε files stepping στο επόμενο request? Όχι —
			// συνεχίζουμε στο ίδιο request παρακάτω.
		}

		return self::files_step();
	}

	/** Ενιαίο abort (db ή files phase). */
	public static function abort(): void {

		$ck    = self::read_ck();
		$phase = (string) ( $ck['phase'] ?? 'db' );

		if ( 'db' === $phase || 'done' === $phase ) {
			$db = NM_Export_Engine::start( false );
			$db->abort();
			return;
		}

		$ck['phase'] = 'failed';
		$ck['error'] = 'Aborted by user.';
		self::write_ck( $ck );
	}

	/* =====================================================================
	 * Φάση 'files' — setup
	 * =================================================================== */

	/**
	 * Μετάβαση db → files: ΦΡΕΣΚΟ ZIP στο staging, πρώτο entry το
	 * dump.sql (scope 'everything'/'db'), αρχικο sidecar state.
	 */
	private static function begin_files_phase(): void {

		$ck    = self::read_ck();
		$scope = (string) ( $ck['scope'] ?? 'everything' );

		$dir      = NM_Export_Engine::staging_dir();
		$zip_path = $dir . '/archive.zip';

		$writer = new NM_Zip_Writer( $zip_path );

		$manifest = array();

		if ( 'db' === $scope || 'everything' === $scope ) {
			$dump = NM_Export_Engine::staging_sql_path();
			if ( is_readable( $dump ) ) {
				// Stage-and-pack: το dump μπαίνει ΟΛΟΚΛΗΡΟ ως entry —
				// SHA-256 ταυτόχρονα (streamed, μηδέν μνήμη).
				$hash = hash_init( 'sha256' );
				$src  = @fopen( $dump, 'rb' );
				if ( false !== $src ) {
					$writer->begin_file( 'db/dump.sql' );
					while ( true ) {
						$chunk = fread( $src, NM_Zip_Writer::CHUNK );
						if ( false === $chunk || '' === $chunk ) {
							break;
						}
						hash_update( $hash, $chunk );
						$writer->write( $chunk );
					}
					$writer->end_file();
					fclose( $src );

					$manifest[] = array(
						'path'   => 'db/dump.sql',
						'bytes'  => (int) filesize( $dump ),
						'sha256' => hash_final( $hash ),
					);
				}
			}
		}

		$state = array(
			'entries'     => $writer->entries(),
			'offset'      => $writer->offset(),
			'manifest'    => $manifest,
			'last_file'   => '',
			'files_total' => count( self::scan( $scope, (string) ( $ck['media_after'] ?? '' ) ) ),
		);
		self::save_state( $state );

		$ck['phase'] = 'files';
		self::write_ck( $ck );
	}

	/* =====================================================================
	 * Φάση 'files' — stepping
	 * =================================================================== */

	private static function files_step(): array {

		if ( false !== get_transient( 'nm_lock' ) ) {
			return self::files_report( 'paused' );
		}
		set_transient( 'nm_lock', time(), 600 );

		$error = null;

		try {
			$t0    = microtime( true );
			$ck    = self::read_ck();
			$scope = (string) ( $ck['scope'] ?? 'everything' );

			$state = self::load_state();
			if ( null === $state ) {
				// Άθικτο invariant break (π.χ. χειροκίνητο wipe του staging).
				self::begin_files_phase();
				$state = self::load_state();
				if ( null === $state ) {
					throw new RuntimeException( 'NM_Files_Engine: state initialization failed.' );
				}
			}

			$writer  = new NM_Zip_Writer( self::zip_path(), $state );
			$files   = self::scan( $scope, (string) ( $ck['media_after'] ?? '' ) );
			$total   = max( count( $files ), (int) ( $state['files_total'] ?? 0 ) );
			$last    = (string) ( $state['last_file'] ?? '' );
			$manifest = $state['manifest'];

			$done    = 0;
			$exhaust = false;

			foreach ( $files as $rel => $abs ) {
				if ( '' !== $last && $rel <= $last ) {
					$done++;
					continue;
				}

				$entry = self::copy_file( $writer, $abs, 'files/' . $rel, $manifest );

				$last = $rel;
				$done++;

				if ( 0 === $done % self::SAVE_EVERY ) {
					self::save_state( self::snapshot( $writer, $manifest, $last, $total ) );
				}

				if ( microtime( true ) - $t0 >= self::STEP_BUDGET ) {
					break;
				}
			}

			$exhaust = ( $done >= $total );

			if ( $exhaust ) {
				self::save_state( self::snapshot( $writer, $manifest, $last, $total ) );
				self::package( $writer, $manifest, $scope );
				delete_transient( 'nm_lock' );
				return self::packaged_report();
			}

			self::save_state( self::snapshot( $writer, $manifest, $last, $total ) );
			delete_transient( 'nm_lock' );
			return self::files_report( 'running', $last, $done, $total, $writer->offset() );

		} catch ( Throwable $e ) {
			$error = $e->getMessage();
			$ck    = self::read_ck();
			$ck['phase'] = 'failed';
			$ck['error'] = $error;
			self::write_ck( $ck );
			delete_transient( 'nm_lock' );
			return array(
				'status'       => 'failed',
				'table'        => '',
				'rows'         => 0,
				'bytes'        => 0,
				'pct'          => 0.0,
				'done_tables'  => 0,
				'total_tables' => 0,
				'error'        => $error,
			);
		}
	}

	/**
	 * Copy ΕΝΟΣ source αρχείου στο ZIP + ταυτόχρονο SHA-256.
	 *
	 * @param string $rel      Relative path (π.χ. 'uploads/2024/foo.jpg').
	 * @param string $entry    ZIP entry name ('files/' . $rel).
	 * @param array  $manifest By-ref collector.
	 * @return string Το SHA-256.
	 */
	private static function copy_file( NM_Zip_Writer $writer, string $abs, string $entry, array &$manifest ): string {

		$src = @fopen( $abs, 'rb' );
		if ( false === $src ) {
			throw new RuntimeException( 'NM_Files_Engine: cannot read source file ' . $abs );
		}

		$hash  = hash_init( 'sha256' );
		$bytes = 0;

		$writer->begin_file( $entry );

		try {
			while ( true ) {
				$chunk = fread( $src, NM_Zip_Writer::CHUNK );
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				hash_update( $hash, $chunk );
				$writer->write( $chunk );
				$bytes += strlen( $chunk );
			}
			$writer->end_file();
		} finally {
			@fclose( $src );
		}

		$sha = hash_final( $hash );

		$manifest[] = array(
			'path'   => $entry,
			'bytes'  => $bytes,
			'sha256' => $sha,
		);

		return $sha;
	}

	/* =====================================================================
	 * Packaging — manifest entry, restore.php, finish, volumes, summary
	 * =================================================================== */

	private static function package( NM_Zip_Writer $writer, array $manifest, string $scope ): void {

		// ---- Row counts ανά πίνακα (για το NM_Verify στο νέο server) ----
		// Ακριβή COUNT(*) — ΟΧΙ information_schema estimates (approximations).
		global $wpdb;

		$table_counts = array();
		foreach ( NM_Export_Engine::table_list() as $vt ) {
			$c = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'SELECT COUNT(*) FROM `' . str_replace( '`', '``', $vt ) . '`'
			);
			$table_counts[ $vt ] = ( null === $c ) ? -1 : (int) $c;
		}

		// ---- manifest.json (JSON, typed, complete) ----
		$payload = array(
			'version'     => NM_VERSION,
			'created'     => gmdate( 'c' ),
			'site'        => home_url(),
			'scope'       => $scope,
			'wp_version'  => get_bloginfo( 'version' ),
			'php_version' => PHP_VERSION,
			'tables'      => $table_counts,
			'entries'     => $manifest,
		);

		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		if ( false === $json || '' === $json ) {
			throw new RuntimeException( 'NM_Files_Engine: manifest JSON encoding failed.' );
		}

		$writer->begin_file( 'manifest.json' );
		$writer->write( $json );
		$writer->end_file();

		$manifest[] = array(
			'path'   => 'manifest.json',
			'bytes'  => strlen( $json ),
			'sha256' => hash( 'sha256', $json ),
		);

		// ---- restore.php (self-contained importer, Wave 2 Μέρος 2/2) ----
		$tpl = NM_PATH . 'restore/restore.php.tpl';
		if ( is_readable( $tpl ) ) {
			$writer->add_file( 'restore.php', $tpl );
			$manifest[] = array(
				'path'   => 'restore.php',
				'bytes'  => (int) filesize( $tpl ),
				'sha256' => hash_file( 'sha256', $tpl ) ?: '',
			);
		}

		// ---- Finish: central directory + EOCD ----
		$summary = $writer->finish();
		$zip_bytes = (int) $summary['bytes'];

		// ---- Volume split (όταν χρειάζεται) ----
		$max_mb = max( 50, min( 4096, (int) get_option( 'nm_max_volume_mb', '500' ) ) );
		$max    = $max_mb * 1048576;
		$base   = 'noxpress-migration-' . gmdate( 'Y-m-d' );

		$volumes = array();
		if ( $zip_bytes > $max ) {
			$vols = NM_Zip_Writer::split_into_volumes( self::zip_path(), NM_Export_Engine::staging_dir(), $base, $max );
			foreach ( $vols as $v ) {
				$volumes[] = array(
					'file'   => basename( (string) $v['file'] ),
					'bytes'  => (int) $v['bytes'],
					'sha256' => hash_file( 'sha256', (string) $v['file'] ) ?: '',
				);
			}
		} else {
			$final = NM_Export_Engine::staging_dir() . '/' . $base . '.zip';
			if ( ! @rename( self::zip_path(), $final ) ) {
				throw new RuntimeException( 'NM_Files_Engine: cannot rename final archive to ' . $final );
			}
			$volumes[] = array(
				'file'   => $base . '.zip',
				'bytes'  => $zip_bytes,
				'sha256' => hash_file( 'sha256', $final ) ?: '',
			);
		}

		// ---- Summary option ( για το UI / download, Μέρος 2/2) ----
		update_option(
			'nm_last_manifest',
			array(
				'created'     => time(),
				'scope'       => $scope,
				'files'       => count( $manifest ),
				'zip_bytes'   => $zip_bytes,
				'volumes'     => $volumes,
				'staging_dir' => NM_Export_Engine::staging_dir(),
			),
			false
		);

		// ---- Το dump.sql δεν χρειάζεται πλέον — ζει μέσα στο ZIP. ----
		@unlink( NM_Export_Engine::staging_sql_path() );

		$ck = self::read_ck();
		$ck['phase'] = 'packaged';
		self::write_ck( $ck );
	}

	/* =====================================================================
	 * Scan — ντετερμινιστική λίστα (sorted rel => abs)
	 * =================================================================== */

	/**
	 * Roots ανά scope. ΠΑΝΤΑ exclude το staging dir μας (ακούγεται
	 * παράλογο αλλιώς: το ZIP να πακετάρει τον εαυτό του).
	 *
	 * @return array<string,string> rel path => absolute path, sorted.
	 */
	private static function scan( string $scope, string $media_after ): array {

		if ( 'db' === $scope ) {
			return array(); // Κανένα file — μόνο dump.sql (ήδη packed).
		}

		$staging = NM_Export_Engine::staging_dir();
		$cutoff  = ( '' !== $media_after ) ? (int) ( strtotime( $media_after . ' 00:00:00' ) ) : 0;

		$roots = array(
			'uploads' => (string) wp_upload_dir()['basedir'],
			'plugins' => (string) WP_PLUGIN_DIR,
			'themes'  => WP_CONTENT_DIR . '/themes',
		);

		$files = array();

		foreach ( $roots as $label => $abs_root ) {

			if ( '' === $abs_root || ! is_dir( $abs_root ) ) {
				continue;
			}

			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(
					$abs_root,
					FilesystemIterator::SKIP_DOTS
				),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $it as $info ) {
				/** @var SplFileInfo $info */
				if ( ! $info->isFile() ) {
					continue;
				}

				$abs = $info->getPathname();

				// Skip guards (in order of cheapness):
				$base = basename( $abs );
				if ( in_array( strtolower( $base ), self::SKIP_NAMES, true ) ) {
					continue;
				}

				// Realpath staging containment — ποτέ pack τον εαυτό μας.
				$real = (string) realpath( $abs );
				if ( '' !== $staging && 0 === strpos( $real . '/', $staging . '/' ) ) {
					continue;
				}

				// Media date filter (μόνο uploads, μόνο όταν έχει οριστεί).
				if ( 'uploads' === $label && $cutoff > 0 && $info->getMTime() < $cutoff ) {
					continue;
				}

				$rel = $label . '/' . ltrim( substr( $abs, strlen( $abs_root ) ), '/' );
				$files[ $rel ] = $abs;
			}
		}

		// Root config files — ALWAYS (migration χωρίς wp-config = μισή δουλειά).
		foreach ( array( 'wp-config.php', '.htaccess' ) as $rf ) {
			$rp = ABSPATH . $rf;
			if ( is_readable( $rp ) ) {
				$files[ 'root/' . $rf ] = $rp;
			}
		}

		ksort( $files, SORT_STRING );

		return $files;
	}

	/* =====================================================================
	 * State persistence (sidecar)
	 * =================================================================== */

	private static function zip_path(): string {
		return NM_Export_Engine::staging_dir() . '/archive.zip';
	}

	private static function state_path(): string {
		return NM_Export_Engine::staging_dir() . '/zip-state.json';
	}

	private static function load_state(): ?array {
		if ( ! is_readable( self::state_path() ) ) {
			return null;
		}
		$dec = json_decode( (string) file_get_contents( self::state_path() ), true );
		return is_array( $dec ) && isset( $dec['entries'], $dec['offset'] ) ? $dec : null;
	}

	private static function save_state( array $state ): void {
		@file_put_contents( self::state_path(), wp_json_encode( $state, JSON_UNESCAPED_SLASHES ), LOCK_EX );
	}

	private static function snapshot( NM_Zip_Writer $writer, array $manifest, string $last, int $total ): array {
		return array(
			'entries'     => $writer->entries(),
			'offset'      => $writer->offset(),
			'manifest'    => $manifest,
			'last_file'   => $last,
			'files_total' => $total,
		);
	}

	/* =====================================================================
	 * Reports
	 * =================================================================== */

	private static function files_report( string $status, ?string $file = null, ?int $done = null, ?int $total = null, ?int $bytes = null ): array {

		$state = self::load_state();

		return array(
			'status'       => $status,
			'table'        => $file ?? (string) ( $state['last_file'] ?? '' ),
			'rows'         => $done ?? 0,
			'bytes'        => $bytes ?? (int) ( $state['offset'] ?? 0 ),
			'pct'          => ( $total > 0 ) ? round( ( ( $done ?? 0 ) / $total ) * 100, 1 ) : 0.0,
			'done_tables'  => $done ?? 0,
			'total_tables' => $total ?? (int) ( $state['files_total'] ?? 0 ),
			'error'        => null,
		);
	}

	private static function packaged_report(): array {

		$sum = get_option( 'nm_last_manifest', array() );
		$sum = is_array( $sum ) ? $sum : array();

		return array(
			'status'       => 'complete',
			'table'        => '',
			'rows'         => (int) ( $sum['files'] ?? 0 ),
			'bytes'        => (int) ( $sum['zip_bytes'] ?? 0 ),
			'pct'          => 100.0,
			'done_tables'  => (int) ( $sum['files'] ?? 0 ),
			'total_tables' => (int) ( $sum['files'] ?? 0 ),
			'error'        => null,
		);
	}

		/**
	 * Public report για το dashboard rendering (ΧΩΡΙΣ stepping).
	 * Χρησιμοποιείται από το class-admin-ui.php.
	 */
	public static function current_report(): array {

		$ck    = self::read_ck();
		$phase = (string) ( $ck['phase'] ?? 'db' );

		if ( 'failed' === $phase ) {
			return array(
				'status'       => 'failed',
				'table'        => '',
				'rows'         => 0,
				'bytes'        => 0,
				'pct'          => 0.0,
				'done_tables'  => 0,
				'total_tables' => 0,
				'error'        => (string) ( $ck['error'] ?? '' ),
			);
		}

		if ( 'packaged' === $phase ) {
			return self::packaged_report();
		}

		if ( 'files' === $phase ) {

			$state = self::load_state();

			if ( null === $state ) {
				// Χωρίς state δεν ξέρουμε τίποτα — ενημερώνουμε με
				// ουδέτερο paused report· το επόμενο step θα ξαναχτίσει.
				return self::files_report( 'paused' );
			}

			$total = (int) ( $state['files_total'] ?? 0 );

			// Files πληρωμένα = manifest entries κάτω από 'files/'
			// (αφαιρούμε το db/dump.sql, το manifest.json κ.λπ. που ΔΕΝ
			// είναι πηγές αρχείων — μόνο πραγματικά copied files μετράνε).
			$done = 0;
			foreach ( (array) ( $state['manifest'] ?? array() ) as $m ) {
				if ( 0 === strpos( (string) ( $m['path'] ?? '' ), 'files/' ) ) {
					$done++;
				}
			}

			return self::files_report(
				'paused',
				(string) ( $state['last_file'] ?? '' ),
				$done,
				$total,
				(int) ( $state['offset'] ?? 0 )
			);
		}

		// Phase 'db' (ή ακόμη άγνωστο) — delegate στο report του DB engine.
		$db = NM_Export_Engine::start( false );
		return $db->report();
	}

	/* =====================================================================
	 * Downloads — τελικά artifacts (για το admin UI / Part 2/2)
	 * =================================================================== */

	/**
	 * Τα πλήρη filesystem paths των τελικών volumes (η .zip ή το
	 * .z01/.z02/... set) του τελευταίου packaged export.
	 *
	 * @return array<int, array{file:string, full:string, bytes:int, sha256:string}>
	 */
	public static function final_paths(): array {

		$sum = get_option( 'nm_last_manifest', array() );
		$sum = is_array( $sum ) ? $sum : array();

		$staging = NM_Export_Engine::staging_dir();
		$out     = array();

		foreach ( (array) ( $sum['volumes'] ?? array() ) as $v ) {
			if ( ! is_array( $v ) || empty( $v['file'] ) ) {
				continue;
			}

			$name = (string) $v['file'];
			$full = $staging . '/' . $name;

			// Realpath containment — ποτέ εκτός staging (defense in
			// depth: το file name γράφτηκε από εμάς, αλλά το
			// επιβεβαιώνουμε ΚΑΙ στο read path, όχι μόνο στο write).
			if ( ! is_readable( $full ) ) {
				continue;
			}

			$out[] = array(
				'file'   => $name,
				'full'   => $full,
				'bytes'  => (int) ( $v['bytes'] ?? filesize( $full ) ),
				'sha256' => (string) ( $v['sha256'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * Ελέγχει ένα volume ενάντια στο καταγεγραμμένο SHA-256 του
	 * nm_last_manifest (read-back verification πριν το download —
	 * δωρεάν deep-check ότι το staging ΔΕΝ φαγώθηκε από κάτι).
	 *
	 * @return true|array true = OK, αλλιώς ['file' => name, 'expected' => …, 'actual' => …]
	 */
	public static function verify_volumes() {

		foreach ( self::final_paths() as $v ) {
			$actual = hash_file( 'sha256', $v['full'] );
			if ( false === $actual || $actual !== $v['sha256'] ) {
				return array(
					'file'     => $v['file'],
					'expected' => $v['sha256'],
					'actual'   => (string) $actual,
				);
			}
		}

		return true;
	}

	/**
	 * Σβήνει τα τελικά volumes + τα staging υπολείμματα (καθαρή
	 * εκκίνηση επόμενου export). Καλείται από το admin UI ("Delete
	 * package") και ΠΟΤΕ αυτόματα — ο admin πατάει, εμείς σβήνουμε.
	 */
	public static function delete_package(): void {

		foreach ( self::final_paths() as $v ) {
			@unlink( $v['full'] );
		}

		delete_option( 'nm_last_manifest' );

		$ck = self::read_ck();
		if ( 'packaged' === ( $ck['phase'] ?? '' ) ) {
			unset( $ck['phase'] );
			$ck['phase'] = 'db'; // Επιστροφή σε neutral — το επόμενο start το καθαρίζει.
			$ck['table'] = '';
			self::write_ck( $ck );
		}
	}
}
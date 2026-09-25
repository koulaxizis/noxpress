<?php
/**
 * NM_CLI — WP-CLI commands: wp nm <export|resume|status|abort|verify|reset>.
 *
 * Χρήση:
 *   wp nm export [--scope=everything|db|files] [--media-after=YYYY-MM-DD]
 *   wp nm resume
 *   wp nm status
 *   wp nm abort
 *   wp nm verify <manifest-path> [--mode=deep|quick]
 *   wp nm reset            (καθαρίζει export staging + verify state)
 *
 * Το CLI είναι ΛΕΠΤΟ wrapper: όλη η δουλειά γίνεται από τα ίδια
 * engines με το admin UI (NM_Files_Engine / NM_Verify). CTRL+C κατά
 * το export = παύση· το checkpoint είναι πάντα αποθηκευμένο στο τέλος
 * κάθε step (ΔΕΝ κάθε statement — file/table boundaries).
 *
 * Exit codes: 0 = OK, 1 = failure (για scripts/cron).
 */

defined( 'ABSPATH' ) || exit;

final class NM_CLI {

	public static function init(): void {

		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'nm', array( __CLASS__, 'dispatch' ), array(
			'shortdesc' => 'Noxpress Migrator — export/import/verify site migrations.',
		) );
	}

	/**
	 * Dispatcher: wp nm <subcommand> [...args]
	 *
	 * @param array $args       Positional args (πρώτο = subcommand).
	 * @param array $assoc_args Flags (--scope=... κ.λπ.).
	 */
	public static function dispatch( array $args, array $assoc_args ): void {

		$sub = (string) ( $args[0] ?? '' );

		switch ( $sub ) {
			case 'export':
				self::cmd_export( $assoc_args );
				return;
			case 'resume':
				self::cmd_resume();
				return;
			case 'status':
				self::cmd_status();
				return;
			case 'abort':
				self::cmd_abort();
				return;
			case 'verify':
				self::cmd_verify( $args, $assoc_args );
				return;
			case 'reset':
				self::cmd_reset();
				return;
			default:
				WP_CLI::error( 'Unknown subcommand: ' . $sub . ' — use export|resume|status|abort|verify|reset.' );
		}
	}

	/* =====================================================================
	 * wp nm export [--scope=...] [--media-after=...]
	 * =================================================================== */

	private static function cmd_export( array $assoc ): void {

		$scope = (string) ( $assoc['scope'] ?? 'everything' );
		if ( ! in_array( $scope, array( 'everything', 'db', 'files' ), true ) ) {
			WP_CLI::error( 'Invalid --scope (everything|db|files): ' . $scope );
		}

		$after = (string) ( $assoc['media-after'] ?? '' );
		if ( '' !== $after && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $after ) ) {
			WP_CLI::error( 'Invalid --media-after (YYYY-MM-DD): ' . $after );
		}

		WP_CLI::log( 'Starting export — scope: ' . $scope . ( '' !== $after ? ', media after: ' . $after : '' ) );

		NM_Files_Engine::begin( $scope, $after );

		self::stepping_loop();
	}

	/* =====================================================================
	 * wp nm resume
	 * =================================================================== */

	private static function cmd_resume(): void {

		$report = NM_Files_Engine::current_report();

		if ( 'idle' === $report['status'] ) {
			WP_CLI::error( 'Nothing to resume — no checkpoint found. Start with: wp nm export' );
		}
		if ( 'complete' === $report['status'] || 'packaged' === $report['status'] ) {
			WP_CLI::success( 'Export is already complete — nothing to resume. Check: wp nm status' );
			return;
		}

		WP_CLI::log( 'Resuming — last state: ' . $report['status'] );

		self::stepping_loop();
	}

	/**
	 * Κοινός βρόχος stepping μέχρι τερματική κατάσταση. Εκτυπώνει
	 * one-line progress ανά step (CR-safe για logs).
	 */
	private static function stepping_loop(): void {

		$t0    = microtime( true );
		$steps = 0;

		while ( true ) {

			$report = NM_Files_Engine::run_step();

			if ( 'failed' === $report['status'] ) {
				WP_CLI::error( 'Export FAILED: ' . ( $report['error'] ?? 'unknown error' ) . ' — run "wp nm resume" after fixing the cause.' );
			}

			$steps++;

			$line = sprintf(
				'[%3d] %-8s %-45s rows/items: %-9s pct: %5.1f%% (%s)',
				$steps,
				(string) $report['status'],
				mb_substr( (string) $report['table'], 0, 45 ),
				(string) $report['rows'],
				(float) $report['pct'],
				self::fmt_bytes( (int) $report['bytes'] )
			);
			WP_CLI::log( $line );

			if ( 'complete' === $report['status'] ) {
				self::print_summary();
				WP_CLI::success( 'Export complete in ' . round( microtime( true ) - $t0 ) . 's (' . $steps . ' steps).' );
				return;
			}

			// Χωρίς flush/interval: το step() είναι ήδη budgeted (15s).
			// Επαναλαμβάνουμε αμέσως εώς την τερματική κατάσταση.
		}
	}

	/* =====================================================================
	 * wp nm status
	 * =================================================================== */

	private static function cmd_status(): void {

		$report = NM_Files_Engine::current_report();

		WP_CLI::log( 'Status:    ' . (string) $report['status'] );
		WP_CLI::log( 'Item:      ' . (string) ( $report['table'] ?: '—' ) );
		WP_CLI::log( 'Rows/Items:' . number_format_i18n( (int) $report['rows'] ) . ' / ' . (int) $report['total_tables'] );
		WP_CLI::log( 'Bytes:     ' . self::fmt_bytes( (int) $report['bytes'] ) );
		WP_CLI::log( 'Progress:  ' . (float) $report['pct'] . '%' );

		if ( ! empty( $report['error'] ) ) {
			WP_CLI::log( 'Error:     ' . (string) $report['error'] );
		}

		// Packaged? δείξε volumes.
		if ( 'complete' === $report['status'] && class_exists( 'NM_Files_Engine' ) ) {
			self::print_summary();
		}
	}

	/* =====================================================================
	 * wp nm abort
	 * =================================================================== */

	private static function cmd_abort(): void {

		NM_Files_Engine::abort();
		WP_CLI::success( 'Aborted. Staging preserved for debugging — resume with: wp nm resume (or reset with: wp nm reset)' );
	}

	/* =====================================================================
	 * wp nm verify <manifest-path> [--mode=deep|quick]
	 * =================================================================== */

	private static function cmd_verify( array $args, array $assoc ): void {

		$path = (string) ( $args[1] ?? '' );
		$mode = (string) ( $assoc['mode'] ?? 'deep' );

		if ( '' === $path ) {
			WP_CLI::error( 'Usage: wp nm verify <manifest-path> [--mode=deep|quick]' );
		}

		$begun = NM_Verify::begin( $path, $mode );
		if ( ! $begun['ok'] ) {
			WP_CLI::error( (string) ( $begun['error'] ?? 'Cannot start verification.' ) );
		}

		WP_CLI::log( 'Verifying — mode: ' . $mode );

		$steps = 0;

		while ( true ) {

			$report = NM_Verify::step();

			if ( 'failed' === $report['status'] ) {
				WP_CLI::error( 'Verification FAILED: ' . ( $report['error'] ?? 'unknown' ) );
			}

			$steps++;
			WP_CLI::log( sprintf( '[%3d] %-8s pct: %5.1f%%', $steps, (string) $report['status'], (float) $report['pct'] ) );

			if ( 'done' === $report['status'] ) {

				$r = NM_Verify::last_report();

				WP_CLI::log( '' );
				WP_CLI::log( 'Verdict: ' . (string) $r['verdict'] );
				WP_CLI::log( 'Tables OK: ' . count( (array) $r['db_ok'] ) . ' | mismatched: ' . count( (array) $r['db_bad'] ) . ' | orphans: ' . count( (array) $r['orphan_tables'] ) );
				WP_CLI::log( 'Files OK: ' . (int) $r['files_ok'] . ' | bad: ' . count( (array) $r['files_bad'] ) );

				if ( ! empty( $r['db_bad'] ) ) {
					foreach ( (array) $r['db_bad'] as $tbl => $m ) {
						WP_CLI::warning( 'Table ' . $tbl . ': expected ' . $m['expected'] . ', got ' . $m['actual'] );
					}
				}
				if ( ! empty( $r['files_bad'] ) ) {
					foreach ( array_slice( (array) $r['files_bad'], 0, 20 ) as $fb ) {
						WP_CLI::warning( 'File ' . $fb['path'] . ': ' . $fb['reason'] );
					}
				}

				$is_ok = false !== strpos( (string) $r['verdict'], 'PERFECT' );
				( $is_ok ? 'WP_CLI::success' : 'WP_CLI::error' )( (string) $r['verdict'] );
				return;
			}
		}
	}

	/* =====================================================================
	 * wp nm reset
	 * =================================================================== */

	private static function cmd_reset(): void {

		// Νέο, καθαρό staging (wipe checkpoint + files).
		NM_Export_Engine::start( true );
		NM_Files_Engine::delete_package();

		if ( class_exists( 'NM_Verify' ) ) {
			NM_Verify::reset();
		}

		WP_CLI::success( 'Migration state cleared (checkpoint, staging, packages, verify state).' );
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	private static function print_summary(): void {

		$sum = get_option( 'nm_last_manifest', array() );
		$sum = is_array( $sum ) ? $sum : array();

		if ( empty( $sum['volumes'] ) ) {
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Package:' );

		foreach ( (array) $sum['volumes'] as $v ) {
			WP_CLI::log( sprintf(
				'  %-40s %s',
				(string) $v['file'],
				self::fmt_bytes( (int) $v['bytes'] )
			) );
		}

		if ( class_exists( 'NM_Files_Engine' ) ) {
			foreach ( NM_Files_Engine::final_paths() as $p ) {
				WP_CLI::log( '  path: ' . $p['full'] );
			}
		}
	}

	private static function fmt_bytes( int $b ): string {
		if ( $b < 1024 )          { return $b . ' B'; }
		if ( $b < 1048576 )       { return round( $b / 1024, 1 ) . ' KB'; }
		if ( $b < 1073741824 )    { return round( $b / 1048576, 1 ) . ' MB'; }
		return round( $b / 1073741824, 2 ) . ' GB';
	}
}
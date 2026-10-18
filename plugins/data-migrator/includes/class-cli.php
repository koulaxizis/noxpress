<?php
/**
 * NM_CLI — wp data-migrator … (Bible §13).
 *
 * The same jobs as the admin pages, stepped in one process with a long
 * budget. Output is English (WP-CLI convention); messages from the
 * engine follow the site's language.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Backup, verify and restore the whole site.
 */
final class NM_CLI {

	/**
	 * Create a backup.
	 *
	 * ## OPTIONS
	 *
	 * [--scope=<scope>]
	 * : full, db or files.
	 * ---
	 * default: full
	 * options:
	 *   - full
	 *   - db
	 *   - files
	 * ---
	 *
	 * [--part-mb=<mb>]
	 * : Split into parts of this size (0 = one file). Default: the setting.
	 *
	 * [--note=<text>]
	 * : A note shown in the list.
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-migrator backup
	 *     wp data-migrator backup --scope=db --note="before update"
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function backup( $args, $assoc ): void {
		$job = NM_Backup::start(
			array(
				'scope'   => (string) ( $assoc['scope'] ?? 'full' ),
				'part_mb' => isset( $assoc['part-mb'] ) ? (int) $assoc['part-mb'] : (int) NM_Settings::get()['part_mb'],
				'note'    => sanitize_text_field( (string) ( $assoc['note'] ?? '' ) ),
			)
		);
		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}
		$p = $this->drive( (string) $job['id'], array( 'NM_Backup', 'step' ) );
		if ( 'done' !== $p['status'] ) {
			WP_CLI::error( (string) $p['text'] );
		}
		$m = NM_Storage::meta( (string) $p['id'] );
		WP_CLI::success( sprintf( 'Backup %s: %d part(s), %s.', (string) $p['id'], count( (array) ( $m['parts'] ?? array() ) ), NM_Util::size( (float) ( $m['size'] ?? 0 ) ) ) );
		foreach ( (array) ( $m['parts'] ?? array() ) as $part ) {
			WP_CLI::log( NM_Storage::part_path( (string) $part['file'] ) );
		}
	}

	/**
	 * List backups.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * @subcommand list
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function list_( $args, $assoc ): void {
		$rows = array();
		foreach ( NM_Storage::all() as $m ) {
			$rows[] = array(
				'id'      => $m['id'],
				'created' => gmdate( 'Y-m-d H:i', (int) $m['created'] ) . ' UTC',
				'status'  => $m['status'] ?? '',
				'db'      => empty( $m['db'] ) ? 'no' : 'yes',
				'groups'  => implode( ',', (array) ( $m['groups'] ?? array() ) ),
				'parts'   => count( (array) ( $m['parts'] ?? array() ) ),
				'size'    => NM_Util::size( (float) ( $m['size'] ?? 0 ) ),
				'home'    => $m['home'] ?? '',
				'note'    => $m['note'] ?? '',
			);
		}
		$format = (string) ( $assoc['format'] ?? 'table' );
		if ( 'ids' === $format ) {
			WP_CLI::log( implode( ' ', wp_list_pluck( $rows, 'id' ) ) );
			return;
		}
		WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'created', 'status', 'db', 'groups', 'parts', 'size', 'home', 'note' ) );
	}

	/**
	 * Add a backup made on another site (all its parts) to this site's
	 * backups. The files are copied; the originals stay.
	 *
	 * ## OPTIONS
	 *
	 * <file>...
	 * : The backup's .zip file, or all its parts.
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-migrator import ~/example-com-20261018-103845-*.zip
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function import( $args, $assoc ): void {
		if ( count( $args ) > 99 ) {
			WP_CLI::error( 'Too many files.' );
		}
		$dir = NM_Storage::sub( 'incoming' );
		if ( '' === $dir ) {
			WP_CLI::error( 'No writable storage folder.' );
		}
		$upload = NM_Util::hex( 16 );
		$d      = $dir . '/' . $upload;
		NM_Util::mkdirp( $d );
		foreach ( array_values( $args ) as $i => $file ) {
			$file = (string) $file;
			if ( ! is_file( $file ) || ! preg_match( '/\.zip$/i', $file ) ) {
				NM_Restore_Prep::discard( $upload );
				WP_CLI::error( 'Not a .zip file: ' . $file );
			}
			if ( ! copy( $file, $d . '/' . sprintf( '%02d', $i ) . '.zip' ) ) {
				NM_Restore_Prep::discard( $upload );
				WP_CLI::error( 'Could not copy ' . $file );
			}
		}
		$id = NM_Restore_Prep::upload_finish( $upload );
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id->get_error_message() );
		}
		WP_CLI::success( 'Imported as backup ' . $id . '. Next: wp data-migrator restore ' . $id . ' --dry-run' );
	}

	/**
	 * Check a backup (CRC of every entry, SQL readable) and show the
	 * restore plan for this site. Changes nothing.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Backup id.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function verify( $args, $assoc ): void {
		$an = $this->analyze( (string) $args[0] );
		$this->print_checks( NM_Restore_Prep::checks( $an['result'] ) );
		WP_CLI::success( 'The backup is readable.' );
	}

	/**
	 * Restore a backup on this site, optionally at another address.
	 *
	 * The database tables are swapped at once; replaced files and tables
	 * are kept until `wp data-migrator finalize` (or undone with
	 * `wp data-migrator undo`).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Backup id (see `wp data-migrator list`).
	 *
	 * [--new-url=<url>]
	 * : The site's address after the restore. Default: this site's.
	 *   (WP-CLI's own --url picks the site to run on, not this.)
	 *
	 * [--local]
	 * : Also replace the local development addresses the analysis finds
	 *   (e.g. http://localhost/site) with the new address.
	 *
	 * [--bare]
	 * : Also replace the old bare domain (e.g. in emails like info@…).
	 *
	 * [--db-only]
	 * : Restore the database only.
	 *
	 * [--files-only]
	 * : Restore the files only.
	 *
	 * [--blog-public=<0|1>]
	 * : Search engine visibility after the restore. Default: this site's
	 *   when the address changes, the backup's otherwise.
	 *
	 * [--salts]
	 * : New security keys (everyone signs in again).
	 *
	 * [--dry-run]
	 * : Analyse and show the plan only.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function restore( $args, $assoc ): void {
		$an = $this->analyze( (string) $args[0] );
		$o  = NM_Restore_Prep::defaults( $an['result'] );
		if ( isset( $assoc['new-url'] ) ) {
			$url = esc_url_raw( (string) $assoc['new-url'] );
			if ( ! preg_match( '#^https?://[^/\s]+#i', $url ) ) {
				WP_CLI::error( 'Invalid --new-url.' );
			}
			$o['home'] = $url;
		}
		if ( ! empty( $assoc['local'] ) ) {
			$o['local'] = array_map( 'strval', array_keys( (array) ( $an['result']['local'] ?? array() ) ) );
		}
		$o['bare'] = ! empty( $assoc['bare'] );
		if ( ! empty( $assoc['db-only'] ) && ! empty( $assoc['files-only'] ) ) {
			WP_CLI::error( 'Use --db-only or --files-only, not both.' );
		}
		if ( ! empty( $assoc['db-only'] ) ) {
			$o['groups']     = array();
			$o['root_files'] = array();
		}
		if ( ! empty( $assoc['files-only'] ) ) {
			$o['db'] = false;
		}
		if ( isset( $assoc['blog-public'] ) ) {
			$o['blog_public'] = (int) (bool) $assoc['blog-public'];
		}
		$o['salts'] = ! empty( $assoc['salts'] );

		$checks = NM_Restore_Prep::checks( $an['result'], $o );
		$this->print_checks( $checks );
		foreach ( (array) ( $an['result']['local'] ?? array() ) as $u => $n ) {
			WP_CLI::log( sprintf( 'Local address %s (%d): %s', (string) $u, (int) $n, in_array( (string) $u, (array) $o['local'], true ) ? 'replaced' : 'kept (use --local to replace)' ) );
		}
		WP_CLI::log( sprintf( 'Address: %s → %s', (string) $an['result']['manifest']['site']['home'], untrailingslashit( (string) $o['home'] ) ) );
		WP_CLI::log( 'Database: ' . ( $o['db'] ? 'yes' : 'no' ) . ' · files: ' . ( $o['groups'] ? implode( ', ', $o['groups'] ) : 'none' ) );
		if ( ! empty( $assoc['dry-run'] ) ) {
			WP_CLI::success( 'Dry run: nothing changed.' );
			return;
		}
		WP_CLI::confirm( 'Replace this site with the backup?', $assoc );

		$r = NM_Restore_Prep::start( (string) $an['id'], $o );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		$out = $this->run_restore( (string) $r[0]['id'] );
		if ( 'done' === $out ) {
			WP_CLI::success( 'Restored. Check the site, then run `wp data-migrator finalize` (or `wp data-migrator undo`).' );
			return;
		}
		$job = NM_Storage::job( (string) $r[0]['id'] );
		$why = (string) ( $job['error'] ?? '' );
		if ( 'rolledback' === $out ) {
			WP_CLI::error( 'The restore failed and was undone: ' . $why );
		}
		WP_CLI::error( 'The restore failed before the site changed: ' . $why . ' Run `wp data-migrator finalize` to clean up.' );
	}

	/**
	 * Undo the last restore (files and tables back as they were).
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function undo( $args, $assoc ): void {
		$job = $this->last_restore();
		WP_CLI::confirm( 'Put the site back as it was before the restore?', $assoc );
		$r = NM_Restore_Prep::undo( (string) $job['id'] );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		$out = $this->run_restore( (string) $job['id'] );
		if ( 'rolledback' !== $out ) {
			WP_CLI::error( 'Undo stopped: ' . (string) ( NM_Storage::job( (string) $job['id'] )['error'] ?? $out ) );
		}
		WP_CLI::success( 'Undone. Run `wp data-migrator finalize` to delete the undo data.' );
	}

	/**
	 * Delete the undo data of the last restore (snapshots, replaced files).
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function finalize( $args, $assoc ): void {
		$job = $this->last_restore();
		if ( ! NM_Restore_Prep::finalize( (string) $job['id'] ) ) {
			WP_CLI::error( 'Nothing to finalize.' );
		}
		WP_CLI::success( 'Finalized.' );
	}

	/**
	 * Delete a backup.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Backup id.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function delete( $args, $assoc ): void {
		$id = (string) $args[0];
		if ( ! preg_match( NM_Storage::ID_RE, $id ) || ! NM_Storage::meta( $id ) ) {
			WP_CLI::error( 'Backup not found.' );
		}
		WP_CLI::confirm( 'Delete backup ' . $id . '?', $assoc );
		NM_Storage::delete( $id );
		WP_CLI::success( 'Deleted.' );
	}

	/**
	 * Check the storage folder and the WordPress core files.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function check( $args, $assoc ): void {
		$dir = NM_Storage::dir();
		if ( '' === $dir ) {
			WP_CLI::error( 'No writable storage folder.' );
		}
		WP_CLI::log( 'Storage: ' . $dir . ( NM_Storage::in_webroot( $dir ) ? ' (inside the web root, protected)' : ' (outside the web root)' ) );
		WP_CLI::log( 'Web test: ' . NM_Storage::web_test() );
		$c = NM_Integrity::summary( NM_Integrity::check( true ) );
		WP_CLI::log( 'Core files: ' . (string) $c['status'] );
		foreach ( array( 'changed', 'missing', 'extra' ) as $k ) {
			foreach ( (array) $c[ $k ] as $f ) {
				WP_CLI::log( '  ' . $k . ': ' . (string) $f );
			}
		}
	}

	/**
	 * Scheduled backups: list them, or run one now in this process.
	 *
	 * The schedules themselves are set on the Backups page.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list or run.
	 * ---
	 * options:
	 *   - list
	 *   - run
	 * ---
	 *
	 * [<id>]
	 * : The schedule id (run).
	 *
	 * [--format=<format>]
	 * : Output format (list).
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-migrator schedule list
	 *     wp data-migrator schedule run 3fa9c2d1
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Options.
	 */
	public function schedule( $args, $assoc ): void {
		$all = NM_Schedule::all();
		if ( 'run' === ( $args[0] ?? '' ) ) {
			$sc = $all[ (string) ( $args[1] ?? '' ) ] ?? null;
			if ( ! $sc ) {
				WP_CLI::error( 'Schedule not found. See: wp data-migrator schedule list' );
			}
			$r = NM_Schedule::start( $sc, true );
			if ( is_wp_error( $r ) ) {
				WP_CLI::error( $r->get_error_message() );
			}
			$job = NM_Schedule::job();
			$p   = $this->drive( (string) $job['id'], array( 'NM_Backup', 'step' ) );
			NM_Schedule::step(); // Retention, last run, email.
			if ( 'done' !== $p['status'] ) {
				WP_CLI::error( (string) $p['text'] );
			}
			WP_CLI::success( sprintf( 'Backup %s.', (string) $p['id'] ) );
			return;
		}
		$rows = array();
		foreach ( $all as $sc ) {
			$rows[] = array(
				'id'    => $sc['id'],
				'on'    => $sc['on'] ? 'yes' : 'no',
				'scope' => $sc['scope'],
				'when'  => $sc['freq'] . ' ' . $sc['time'] . ( 'weekly' === $sc['freq'] ? ' day ' . $sc['dow'] : ( 'monthly' === $sc['freq'] ? ' day ' . $sc['dom'] : '' ) ),
				'keep'  => $sc['keep'],
				'next'  => $sc['on'] && $sc['next'] ? wp_date( 'Y-m-d H:i', (int) $sc['next'] ) : '',
				'last'  => empty( $sc['last']['time'] ) ? '' : $sc['last']['status'] . ' ' . wp_date( 'Y-m-d H:i', (int) $sc['last']['time'] ),
			);
		}
		WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, array( 'id', 'on', 'scope', 'when', 'keep', 'next', 'last' ) );
	}

	/* ---------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------- */

	/** Step a job until it stops; prints progress. */
	private function drive( string $job_id, callable $step ): array {
		$last = '';
		do {
			$p = call_user_func( $step, $job_id );
			$t = (string) ( $p['text'] ?? '' );
			if ( $t !== $last ) {
				WP_CLI::log( sprintf( '%3d%% %s', (int) ( $p['pct'] ?? 0 ), $t ) );
				$last = $t;
			}
			if ( 'busy' === $p['status'] ) {
				sleep( 2 );
			}
		} while ( in_array( $p['status'], array( 'running', 'busy' ), true ) );
		return $p;
	}

	/** Run the analysis of a backup. @return array The finished job. */
	private function analyze( string $id ): array {
		if ( ! preg_match( NM_Storage::ID_RE, $id ) ) {
			WP_CLI::error( 'Invalid backup id.' );
		}
		$job = NM_Restore_Prep::analyze_start( $id );
		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}
		$p = $this->drive( (string) $job['id'], array( 'NM_Restore_Prep', 'step' ) );
		if ( 'done' !== $p['status'] ) {
			WP_CLI::error( (string) $p['text'] );
		}
		return (array) NM_Storage::job( (string) $job['id'] );
	}

	private function print_checks( array $checks ): void {
		$fail = false;
		foreach ( $checks as $c ) {
			WP_CLI::log( str_pad( strtoupper( (string) $c[0] ), 5 ) . ' ' . (string) $c[1] );
			$fail = $fail || 'fail' === $c[0];
		}
		if ( $fail ) {
			WP_CLI::error( 'The restore cannot run on this server.' );
		}
	}

	/** Run a restore or undo job in this process. */
	private function run_restore( string $id ): string {
		$path = NM_Storage::job_path( $id );
		$lock = NM_Util::lock( $path . '.lock' );
		if ( ! $lock ) {
			WP_CLI::error( 'Another process is working on this restore.' );
		}
		$db = NM_Backup::db();
		if ( ! $db ) {
			WP_CLI::error( 'No database connection.' );
		}
		NM_Util::prepare_request();
		NM_Util::$log_file = NM_Storage::log_file( $id );
		$save              = static function ( $j ) use ( $path ) {
			NM_Util::write_state( $path, $j );
		};
		$step = '';
		do {
			$job = NM_Util::read_state( $path );
			$r   = new NM_Restorer( $job, $db, $save );
			$out = $r->run( microtime( true ) + NM_Util::budget() );
			$now = 'rolling-back' === $r->job['status'] ? 'rollback' : (string) $r->job['step'];
			if ( $now !== $step ) {
				WP_CLI::log( '… ' . $now );
				$step = $now;
			}
		} while ( 'more' === $out );
		$db->close();
		NM_Util::unlock( $lock );
		return $out;
	}

	private function last_restore(): array {
		$st  = get_option( 'nm_state', array() );
		$id  = is_array( $st ) ? (string) ( $st['last'] ?? '' ) : '';
		$job = '' === $id ? null : NM_Storage::job( $id );
		if ( ! $job || 'restore' !== $job['type'] ) {
			WP_CLI::error( 'No restore found.' );
		}
		return $job;
	}
}

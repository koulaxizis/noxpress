<?php
/**
 * NM_Remote — backups in Dropbox (1.3.0): upload, download back to the
 * server, list, retention, delete.
 *
 * Where a backup goes (dest, per manual backup and per schedule):
 *  - local   only in the storage folder (as before 1.3.0, the default);
 *  - both    the storage folder and Dropbox;
 *  - remote  Dropbox only: written here first, uploaded, verified, and
 *            only then the local parts are deleted. A failed upload keeps
 *            them and says so (e-mail for scheduled runs).
 *
 * Upload: the last phase of the backup job ("upload"), or a job of its
 * own (u-<id>) for "Upload again". Each part goes up in an upload session
 * of chunk_mb pieces (4, 8 or 16 MB), as many as fit in the step's time
 * budget; the session id and offset live in the job state, so a lost
 * request continues where Dropbox says it stopped (incorrect_offset).
 * The Dropbox content hash (sha256 of every 4 MB block) is worked out on
 * the way and compared with the one Dropbox reports. Files are added,
 * never overwritten. Errors 429/5xx/network wait (Retry-After, at most
 * five seconds per try) and retry; eight failures in a row end the upload
 * without touching the local backup.
 *
 * Layout in the app folder: /<host>/<stem>[-partN].zip and /<host>/<id>.json
 * (the metadata, no secrets), so another server can list them.
 *
 * Download (job d-<id>): the parts come back to the storage folder in
 * pieces, are checked against their sha256 and only then join the
 * backups; the restore itself is unchanged.
 *
 * Retention: only scheduled backups, only after a successful upload of
 * the newest one, only files whose names pass the strict patterns.
 */

defined( 'ABSPATH' ) || exit;

final class NM_Remote {

	const FAILS_MAX = 8;
	const WAIT_MAX  = 5;
	const BLOCK     = 4194304;
	const DESTS     = array( 'local', 'both', 'remote' );

	/* ---------------------------------------------------------------
	 * Metadata helpers
	 * ------------------------------------------------------------- */

	/** The local parts are there (true for every backup before 1.3.0). */
	public static function has_local( array $m ): bool {
		return ! isset( $m['local'] ) || false !== $m['local'];
	}

	/** The backup is complete in Dropbox. */
	public static function in_dropbox( array $m ): bool {
		return 'done' === ( $m['remote']['state'] ?? '' );
	}

	/** Host folder of a backup in Dropbox, from its file names. */
	public static function host( array $m ): string {
		$f = (string) ( $m['parts'][0]['file'] ?? '' );
		return preg_match( '/^([a-z0-9.-]{1,80})-\d{8}-\d{6}-[a-f0-9]{32}(-part\d{1,3})?\.zip$/', $f, $x ) ? $x[1] : '';
	}

	private static function set_remote( string $id, array $changes ): void {
		$m = NM_Storage::meta( $id );
		if ( ! $m ) {
			return;
		}
		$m['remote'] = array_merge( (array) ( $m['remote'] ?? array() ), $changes );
		NM_Storage::save_meta( $m );
	}

	/* ---------------------------------------------------------------
	 * Upload (backup phase "upload", or a push job)
	 * ------------------------------------------------------------- */

	/**
	 * Work on the upload until the deadline.
	 *
	 * @return string 'more' | 'next' (finished, well or not: the job goes on)
	 */
	public static function upload_run( array &$job, float $deadline ): string {
		$dest = (string) ( $job['dest'] ?? 'local' );
		if ( 'local' === $dest ) {
			return 'next';
		}
		$id = (string) $job['backup'];
		$m  = NM_Storage::meta( $id );
		if ( ! $m || 'done' !== ( $m['status'] ?? '' ) || ! self::has_local( $m ) ) {
			return 'next';
		}
		$host = self::host( $m );
		$ss   = $job['ss'] + array(
			'stage'    => 'check',
			'i'        => 0,
			'sid'      => '',
			'off'      => 0,
			'fails'    => 0,
			'restarts' => 0,
			'sent'     => 0,
			'total'    => (int) ( $m['size'] ?? 0 ),
		);
		$job['ss'] = $ss;
		if ( '' === $host || ! NM_Dropbox::connected() ) {
			return self::up_fail( $job, '' === $host ? NM_Util::t( 'Μη έγκυρο όνομα αρχείου.' ) : NM_Util::t( 'Δεν υπάρχει σύνδεση με το Dropbox.' ) );
		}
		$chunk = (int) NM_Dropbox::get()['chunk_mb'] * 1048576;
		$parts = (array) $m['parts'];

		while ( microtime( true ) < $deadline ) {
			if ( 'check' === $ss['stage'] ) {
				$r = NM_Dropbox::rpc( 'users/get_space_usage', null );
				if ( is_wp_error( $r ) ) {
					if ( self::again( $job, $ss, $r ) ) {
						continue;
					}
					return self::up_fail( $job, $r->get_error_message() );
				}
				$alloc = (float) ( $r['allocation']['allocated'] ?? 0 );
				$used  = (float) ( $r['used'] ?? 0 );
				if ( $alloc > 0 && $alloc - $used < (float) $ss['total'] ) {
					return self::up_fail( $job, NM_Util::tf( 'Δεν χωράει στο Dropbox: ελεύθερα %1$s, χρειάζονται %2$s.', NM_Util::size( max( 0.0, $alloc - $used ) ), NM_Util::size( (float) $ss['total'] ) ) );
				}
				self::set_remote(
					$id,
					array(
						'state' => 'uploading',
						'error' => '',
						'files' => array(),
						'host'  => $host,
					)
				);
				NM_Util::log( 'dropbox: upload of ' . count( $parts ) . ' part(s) to /' . $host . '/' );
				$ss['stage'] = 'parts';
				continue;
			}

			if ( 'parts' === $ss['stage'] ) {
				if ( $ss['i'] >= count( $parts ) ) {
					$ss['stage'] = 'json';
					continue;
				}
				$r = self::part_step( $job, $ss, $m, $host, $chunk );
				if ( 'fail' === $r ) {
					return self::up_fail( $job, (string) $job['up_error'] );
				}
				$job['ss'] = $ss;
				NM_Storage::save_job( $job );
				continue;
			}

			if ( 'json' === $ss['stage'] ) {
				$r = NM_Dropbox::upload(
					'files/upload',
					array(
						'path'       => NM_Dropbox::path( $host, $id . '.json' ),
						'mode'       => 'add',
						'autorename' => false,
						'mute'       => true,
					),
					self::public_meta( $m )
				);
				if ( is_wp_error( $r ) && ! self::conflict( $r ) ) {
					if ( self::again( $job, $ss, $r ) ) {
						continue;
					}
					return self::up_fail( $job, $r->get_error_message() );
				}
				$ss['stage'] = 'done';
				continue;
			}

			// Done: every part is there and verified.
			self::set_remote(
				$id,
				array(
					'state' => 'done',
					'time'  => time(),
					'error' => '',
				)
			);
			NM_Dropbox::save(
				array(
					'last_ok' => time(),
					'broken'  => '',
				)
			);
			NM_Util::log( 'dropbox: upload done, ' . NM_Util::size( (float) $ss['sent'] ) );
			if ( 'remote' === $dest ) {
				NM_Storage::drop_local( $id );
				NM_Util::log( 'dropbox: local parts deleted (Dropbox only)' );
			}
			$job['ss'] = $ss;
			return 'next';
		}
		$job['ss'] = $ss;
		return 'more';
	}

	/**
	 * One request for the current part: start, append or finish.
	 *
	 * @return string 'ok' | 'fail' (message in $job['up_error'])
	 */
	private static function part_step( array &$job, array &$ss, array $m, string $host, int $chunk ): string {
		$id   = (string) $m['id'];
		$part = (array) $m['parts'][ $ss['i'] ];
		$file = (string) $part['file'];
		$src  = NM_Storage::part_path( $file );
		$dst  = NM_Dropbox::path( $host, $file );
		$hb   = NM_Storage::sub( 'work' ) . '/up-' . $id . '-' . (int) $ss['i'] . '.bin';
		$size = '' === $src ? -1 : (int) @filesize( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $size <= 0 || '' === $dst ) {
			$job['up_error'] = NM_Util::tf( 'Λείπει το κομμάτι %s.', $file );
			return 'fail';
		}
		if ( ! self::sync_hashes( $src, $hb, (int) $ss['off'], $size ) ) {
			$job['up_error'] = NM_Util::t( 'Δεν γράφεται το αρχείο στον δίσκο.' );
			return 'fail';
		}

		// Finish: every byte is in the session.
		if ( '' !== $ss['sid'] && (int) $ss['off'] >= $size ) {
			$r = NM_Dropbox::upload(
				'files/upload_session/finish',
				array(
					'cursor' => array(
						'session_id' => (string) $ss['sid'],
						'offset'     => $size,
					),
					'commit' => array(
						'path'       => $dst,
						'mode'       => 'add',
						'autorename' => false,
						'mute'       => true,
					),
				),
				''
			);
			$ours = hash( 'sha256', (string) file_get_contents( $hb ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( is_wp_error( $r ) && self::conflict( $r ) ) {
				// Already there: ours from a request that did not get its answer, or not.
				$r = NM_Dropbox::rpc( 'files/get_metadata', array( 'path' => $dst ) );
			}
			if ( is_wp_error( $r ) ) {
				$off = self::correct_offset( $r );
				if ( null !== $off ) {
					$ss['off'] = min( $off, $size );
					return 'ok';
				}
				if ( self::session_gone( $r ) ) {
					return self::restart_part( $job, $ss, $hb );
				}
				return self::again( $job, $ss, $r ) ? 'ok' : self::stop( $job, $r );
			}
			if ( (int) ( $r['size'] ?? -1 ) !== $size || ! hash_equals( $ours, (string) ( $r['content_hash'] ?? '' ) ) ) {
				$job['up_error'] = NM_Util::tf( 'Το κομμάτι %s δεν έφτασε σωστά στο Dropbox (διαφορετικό hash).', $file );
				return 'fail';
			}
			$mm = NM_Storage::meta( $id );
			if ( $mm ) {
				$files          = (array) ( $mm['remote']['files'] ?? array() );
				$files[ $file ] = array(
					'size' => $size,
					'hash' => $ours,
				);
				self::set_remote( $id, array( 'files' => $files ) );
			}
			@unlink( $hb ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			NM_Util::log( 'dropbox: ' . $file . ' verified' );
			++$ss['i'];
			$ss['sid']   = '';
			$ss['off']   = 0;
			$ss['fails'] = 0;
			return 'ok';
		}

		// Start or append one piece.
		$fh = @fopen( $src, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			$job['up_error'] = NM_Util::tf( 'Λείπει το κομμάτι %s.', $file );
			return 'fail';
		}
		$off = '' === $ss['sid'] ? 0 : (int) $ss['off'];
		fseek( $fh, $off );
		$bytes = (string) fread( $fh, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( '' === $ss['sid'] ) {
			$r = NM_Dropbox::upload( 'files/upload_session/start', array( 'close' => false ), $bytes );
		} else {
			$r = NM_Dropbox::upload(
				'files/upload_session/append_v2',
				array(
					'cursor' => array(
						'session_id' => (string) $ss['sid'],
						'offset'     => $off,
					),
					'close'  => false,
				),
				$bytes
			);
		}
		if ( is_wp_error( $r ) ) {
			$c = self::correct_offset( $r );
			if ( null !== $c && '' !== $ss['sid'] ) {
				$ss['off'] = min( $c, $size ); // Hashes catch up on the next call.
				return 'ok';
			}
			if ( '' !== $ss['sid'] && self::session_gone( $r ) ) {
				return self::restart_part( $job, $ss, $hb );
			}
			return self::again( $job, $ss, $r ) ? 'ok' : self::stop( $job, $r );
		}
		if ( '' === $ss['sid'] ) {
			$sid = (string) ( $r['session_id'] ?? '' );
			if ( ! preg_match( '/^[A-Za-z0-9:_-]{4,200}$/', $sid ) ) {
				$job['up_error'] = NM_Util::t( 'Το Dropbox δεν άνοιξε συνεδρία αποστολής.' );
				return 'fail';
			}
			$ss['sid'] = $sid;
		}
		self::append_hashes( $hb, $off, $bytes );
		$ss['off']    = $off + strlen( $bytes );
		$ss['sent']   = (int) $ss['sent'] + strlen( $bytes );
		$ss['fails']  = 0;
		return 'ok';
	}

	/** Block hashes of the bytes before $off are in $hb, nothing more. */
	private static function sync_hashes( string $src, string $hb, int $off, int $size ): bool {
		$want = ( $off >= $size ? (int) ceil( $size / self::BLOCK ) : intdiv( $off, self::BLOCK ) ) * 32;
		$have = is_file( $hb ) ? (int) filesize( $hb ) : 0;
		clearstatcache( true, $hb );
		if ( $have === $want ) {
			return true;
		}
		$fh = @fopen( $hb, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return false;
		}
		$have = intdiv( min( $have, $want ), 32 ) * 32;
		ftruncate( $fh, $have );
		fseek( $fh, $have );
		$in = @fopen( $src, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $in ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}
		for ( $b = intdiv( $have, 32 ); $b * 32 < $want; $b++ ) {
			fseek( $in, $b * self::BLOCK );
			fwrite( $fh, hash( 'sha256', (string) fread( $in, self::BLOCK ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fread
		}
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		clearstatcache( true, $hb );
		return true;
	}

	/** Add the hashes of a piece that starts at $off (a multiple of 4 MB). */
	private static function append_hashes( string $hb, int $off, string $bytes ): void {
		$fh = @fopen( $hb, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return;
		}
		$at = intdiv( $off, self::BLOCK ) * 32;
		ftruncate( $fh, $at );
		fseek( $fh, $at );
		fwrite( $fh, NM_Dropbox::block_hashes( $bytes ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		clearstatcache( true, $hb );
	}

	/** Dropbox's correct_offset from an incorrect_offset error, or null. */
	private static function correct_offset( WP_Error $e ): ?int {
		if ( 'api' !== $e->get_error_code() ) {
			return null;
		}
		$d = (array) $e->get_error_data();
		foreach ( array( $d, (array) ( $d['lookup_failed'] ?? array() ) ) as $x ) {
			if ( 'incorrect_offset' === ( $x['.tag'] ?? '' ) && isset( $x['correct_offset'] ) ) {
				return max( 0, (int) $x['correct_offset'] );
			}
		}
		return null;
	}

	/** The upload session expired or closed: the part starts again. */
	private static function session_gone( WP_Error $e ): bool {
		if ( 'api' !== $e->get_error_code() ) {
			return false;
		}
		return (bool) preg_match( '#^(lookup_failed/)?(not_found|closed|not_closed|too_large|concurrent_session_invalid)#', $e->get_error_message() );
	}

	private static function restart_part( array &$job, array &$ss, string $hb ): string {
		if ( (int) $ss['restarts'] >= 3 ) {
			$job['up_error'] = NM_Util::t( 'Η συνεδρία αποστολής του Dropbox έληξε πολλές φορές.' );
			return 'fail';
		}
		++$ss['restarts'];
		$ss['sid'] = '';
		$ss['off'] = 0;
		@unlink( $hb ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		NM_Util::log( 'dropbox: session gone, part starts again' );
		return 'ok';
	}

	private static function conflict( WP_Error $e ): bool {
		return 'api' === $e->get_error_code() && (bool) preg_match( '#path/conflict|conflict/file#', $e->get_error_message() );
	}

	/**
	 * A failure worth another try: wait (at most WAIT_MAX seconds) and say yes,
	 * until FAILS_MAX in a row.
	 */
	private static function again( array &$job, array &$ss, WP_Error $e ): bool {
		if ( 'retry' !== $e->get_error_code() ) {
			return false;
		}
		$ss['fails'] = (int) $ss['fails'] + 1;
		$job['ss']   = $ss;
		if ( $ss['fails'] >= self::FAILS_MAX ) {
			return false;
		}
		NM_Util::log( 'dropbox: ' . $e->get_error_message() . ' (try ' . $ss['fails'] . ')' );
		sleep( (int) min( self::WAIT_MAX, max( 1, (int) $e->get_error_data() ) ) );
		return true;
	}

	private static function stop( array &$job, WP_Error $e ): string {
		$job['up_error'] = $e->get_error_message();
		return 'fail';
	}

	/** The upload ended badly: the local backup stays, the job goes on. */
	private static function up_fail( array &$job, string $msg ): string {
		$msg = NM_Dropbox::redact( $msg );
		self::set_remote(
			(string) $job['backup'],
			array(
				'state' => 'failed',
				'error' => $msg,
				'time'  => time(),
			)
		);
		$job['remote_error'] = $msg;
		$job['warn'][]       = NM_Util::tf( 'Η αποστολή στο Dropbox απέτυχε (το αντίγραφο έμεινε σε αυτόν τον server): %s', $msg );
		NM_Util::log( 'dropbox: upload failed: ' . $msg, true );
		// Leftover hash files of this backup.
		foreach ( (array) glob( NM_Storage::sub( 'work' ) . '/up-' . $job['backup'] . '-*.bin' ) as $f ) {
			@unlink( (string) $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		return 'next';
	}

	/** The metadata file in Dropbox: what another server needs, nothing local. */
	public static function public_meta( array $m ): string {
		unset( $m['remote'], $m['local'], $m['job'], $m['imported'] );
		$m['format'] = 1;
		return (string) wp_json_encode( $m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	public static function upload_progress( array $job ): array {
		$ss  = (array) $job['ss'];
		$tot = max( 1, (int) ( $ss['total'] ?? 1 ) );
		return array(
			(int) min( 99, 100 * (int) ( $ss['sent'] ?? 0 ) / $tot ),
			NM_Util::t( 'Αποστολή στο Dropbox' ) . ' · ' . NM_Util::size( (float) ( $ss['sent'] ?? 0 ) ) . ' / ' . NM_Util::size( (float) $tot ),
		);
	}

	/* ---------------------------------------------------------------
	 * Jobs of their own: push (upload again) and fetch (download)
	 * ------------------------------------------------------------- */

	/** @return array|WP_Error */
	/**
	 * May this backup go to Dropbox as it is? With "encrypt the copies in
	 * Dropbox" on, only an encrypted backup: never a silent plain upload.
	 */
	public static function push_allowed( array $m ): bool {
		return ! empty( $m['enc'] ) || empty( NM_Dropbox::get()['encrypt'] );
	}

	public static function push_start( string $id ) {
		$m = NM_Storage::meta( $id );
		if ( ! $m || 'done' !== ( $m['status'] ?? '' ) || ! self::has_local( $m ) ) {
			return new WP_Error( 'gone', NM_Util::t( 'Το αντίγραφο δεν βρέθηκε.' ) );
		}
		if ( ! self::push_allowed( $m ) ) {
			return new WP_Error( 'plain', NM_Util::t( 'Το αντίγραφο δεν είναι κρυπτογραφημένο και τα αντίγραφα στο Dropbox κρυπτογραφούνται. Φτιάξε νέο αντίγραφο με προορισμό το Dropbox (κρυπτογραφείται μόνο του) ή κλείσε την κρυπτογράφηση του Dropbox στις ρυθμίσεις.' ) );
		}
		if ( ! NM_Dropbox::connected() ) {
			return new WP_Error( 'dropbox', NM_Util::t( 'Δεν υπάρχει σύνδεση με το Dropbox.' ) );
		}
		if ( self::in_dropbox( $m ) ) {
			return new WP_Error( 'there', NM_Util::t( 'Το αντίγραφο είναι ήδη στο Dropbox.' ) );
		}
		if ( NM_Backup::running() ) {
			return new WP_Error( 'busy', NM_Util::t( 'Τρέχει ήδη μια εργασία του Data Migrator. Περίμενε να τελειώσει ή ακύρωσέ την.' ) );
		}
		// A half upload of an earlier try: Dropbox keeps nothing of it (no
		// finished file), and "add" refuses a file that is already complete.
		$job = array(
			'id'      => 'u-' . NM_Util::hex( 16 ),
			'type'    => 'push',
			'backup'  => $id,
			'created' => time(),
			'user'    => get_current_user_id(),
			'status'  => 'running',
			'phase'   => 'upload',
			'dest'    => 'both',
			'ss'      => array(),
			'warn'    => array(),
		);
		NM_Storage::save_job( $job );
		NM_Util::$log_file = NM_Storage::log_file( $job['id'] );
		NM_Util::log( 'dropbox: upload again of ' . $id );
		return $job;
	}

	/** @return array|WP_Error */
	public static function fetch_start( string $id ) {
		$m = NM_Storage::meta( $id );
		if ( ! $m || ! self::in_dropbox( $m ) ) {
			return new WP_Error( 'gone', NM_Util::t( 'Το αντίγραφο δεν βρέθηκε.' ) );
		}
		if ( self::has_local( $m ) ) {
			return new WP_Error( 'here', NM_Util::t( 'Το αντίγραφο είναι ήδη σε αυτόν τον server.' ) );
		}
		if ( ! NM_Dropbox::connected() ) {
			return new WP_Error( 'dropbox', NM_Util::t( 'Δεν υπάρχει σύνδεση με το Dropbox.' ) );
		}
		if ( NM_Backup::running() ) {
			return new WP_Error( 'busy', NM_Util::t( 'Τρέχει ήδη μια εργασία του Data Migrator. Περίμενε να τελειώσει ή ακύρωσέ την.' ) );
		}
		$need = (float) ( $m['size'] ?? 0 ) + (float) NM_Backup::RESERVE;
		$free = NM_Util::free_space( NM_Storage::dir() );
		if ( $free >= 0 && $free < $need ) {
			return new WP_Error( 'disk', NM_Util::tf( 'Ο ελεύθερος χώρος (%1$s) δεν φτάνει για τη λήψη (περίπου %2$s μαζί με ένα περιθώριο για το site).', NM_Util::size( $free ), NM_Util::size( $need ) ) );
		}
		$job = array(
			'id'      => 'd-' . NM_Util::hex( 16 ),
			'type'    => 'fetch',
			'backup'  => $id,
			'created' => time(),
			'user'    => get_current_user_id(),
			'status'  => 'running',
			'phase'   => 'fetch',
			'ss'      => array(
				'i'     => 0,
				'off'   => 0,
				'fails' => 0,
				'got'   => 0,
				'total' => (int) ( $m['size'] ?? 0 ),
			),
			'warn'    => array(),
		);
		NM_Storage::save_job( $job );
		NM_Util::$log_file = NM_Storage::log_file( $job['id'] );
		NM_Util::log( 'dropbox: download of ' . $id );
		return $job;
	}

	/** One request's worth of a push or fetch job. */
	public static function step( string $job_id ): array {
		$path = NM_Storage::job_path( $job_id );
		$job  = '' === $path ? null : NM_Util::read_state( $path );
		if ( ! $job || ! in_array( $job['type'] ?? '', array( 'push', 'fetch' ), true ) ) {
			return array(
				'status' => 'failed',
				'text'   => NM_Util::t( 'Η εργασία δεν βρέθηκε.' ),
			);
		}
		if ( 'running' !== $job['status'] ) {
			return self::progress( $job );
		}
		$lock = NM_Util::lock( $path . '.lock' );
		if ( ! $lock ) {
			$p           = self::progress( $job );
			$p['status'] = 'busy';
			return $p;
		}
		NM_Util::prepare_request();
		NM_Util::$log_file = NM_Storage::log_file( $job_id );
		$deadline          = microtime( true ) + NM_Util::budget();
		try {
			if ( 'push' === $job['type'] ) {
				if ( 'next' === self::upload_run( $job, $deadline ) ) {
					$job['status'] = empty( $job['remote_error'] ) ? 'done' : 'failed';
					$job['error']  = (string) ( $job['remote_error'] ?? '' );
				}
			} elseif ( 'next' === self::fetch_run( $job, $deadline ) ) {
				$job['status'] = empty( $job['error'] ) ? 'done' : 'failed';
			}
		} catch ( Throwable $e ) {
			$job['status'] = 'failed';
			$job['error']  = NM_Dropbox::redact( $e->getMessage() );
		}
		NM_Storage::save_job( $job );
		NM_Util::unlock( $lock );
		return self::progress( $job );
	}

	public static function progress( array $job ): array {
		$ss = (array) $job['ss'];
		if ( 'push' === $job['type'] ) {
			list( $pct, $text ) = self::upload_progress( $job );
		} else {
			$tot  = max( 1, (int) ( $ss['total'] ?? 1 ) );
			$pct  = (int) min( 99, 100 * (int) ( $ss['got'] ?? 0 ) / $tot );
			$text = NM_Util::t( 'Λήψη από το Dropbox' ) . ' · ' . NM_Util::size( (float) ( $ss['got'] ?? 0 ) ) . ' / ' . NM_Util::size( (float) $tot );
		}
		if ( 'done' === $job['status'] ) {
			$pct  = 100;
			$text = NM_Util::t( 'Έτοιμο' );
		}
		return array(
			'status' => $job['status'],
			'phase'  => (string) ( $job['phase'] ?? '' ),
			'pct'    => $pct,
			'text'   => 'failed' === $job['status'] ? NM_Util::tf( 'Απέτυχε: %s', (string) ( $job['error'] ?? '' ) ) : $text,
			'id'     => $job['backup'],
		);
	}

	public static function cancel( string $job_id ): bool {
		$job = NM_Storage::job( $job_id );
		if ( ! $job || ! in_array( $job['type'] ?? '', array( 'push', 'fetch' ), true ) || 'running' !== $job['status'] ) {
			return false;
		}
		$job['status'] = 'cancelled';
		$job['error']  = NM_Util::t( 'Ακυρώθηκε.' );
		NM_Storage::save_job( $job );
		if ( 'fetch' === $job['type'] ) {
			foreach ( (array) glob( NM_Storage::sub( 'work' ) . '/dl-' . $job['backup'] . '-*.zip' ) as $f ) {
				@unlink( (string) $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		} else {
			self::set_remote(
				(string) $job['backup'],
				array(
					'state' => 'failed',
					'error' => NM_Util::t( 'Ακυρώθηκε.' ),
				)
			);
		}
		return true;
	}

	/** @return string 'more' | 'next' ($job['error'] set on failure) */
	private static function fetch_run( array &$job, float $deadline ): string {
		$id = (string) $job['backup'];
		$m  = NM_Storage::meta( $id );
		if ( ! $m || ! self::in_dropbox( $m ) ) {
			$job['error'] = NM_Util::t( 'Το αντίγραφο δεν βρέθηκε.' );
			return 'next';
		}
		$host  = self::host( $m );
		$parts = (array) $m['parts'];
		$ss    = (array) $job['ss'];
		$chunk = (int) NM_Dropbox::get()['chunk_mb'] * 1048576;
		while ( microtime( true ) < $deadline ) {
			if ( $ss['i'] >= count( $parts ) ) {
				$m          = (array) NM_Storage::meta( $id );
				$m['local'] = true;
				NM_Storage::save_meta( $m );
				NM_Util::log( 'dropbox: download done' );
				$job['ss'] = $ss;
				return 'next';
			}
			$part = (array) $parts[ $ss['i'] ];
			$file = (string) $part['file'];
			$size = (int) ( $part['size'] ?? 0 );
			$src  = NM_Dropbox::path( $host, $file );
			$fin  = NM_Storage::part_path( $file );
			$tmp  = NM_Storage::sub( 'work' ) . '/dl-' . $id . '-' . (int) $ss['i'] . '.zip';
			if ( '' === $src || '' === $fin || $size <= 0 ) {
				$job['error'] = NM_Util::t( 'Μη έγκυρο όνομα αρχείου.' );
				return 'next';
			}
			$free = NM_Util::free_space( NM_Storage::dir() );
			if ( $free >= 0 && $free < (float) NM_Backup::RESERVE + $chunk ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				$job['error'] = NM_Util::tf( 'Σταμάτησε: ο ελεύθερος χώρος στον δίσκο έπεσε κάτω από %s.', NM_Util::size( (float) NM_Backup::RESERVE ) );
				return 'next';
			}
			if ( $ss['off'] >= $size ) {
				clearstatcache( true, $tmp );
				$sha = (string) ( $part['sha256'] ?? '' );
				if ( (int) @filesize( $tmp ) !== $size || ( '' !== $sha && ! hash_equals( $sha, (string) hash_file( 'sha256', $tmp ) ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
					$job['error'] = NM_Util::tf( 'Το κομμάτι %s δεν ταιριάζει με το αντίγραφο (διαφορετικό sha256).', $file );
					return 'next';
				}
				if ( ! NM_Util::move( $tmp, $fin ) ) {
					$job['error'] = NM_Util::t( 'Δεν γράφεται το αρχείο στον δίσκο.' );
					return 'next';
				}
				NM_Util::log( 'dropbox: ' . $file . ' downloaded and checked' );
				++$ss['i'];
				$ss['off'] = 0;
				continue;
			}
			$to = (int) min( $size, $ss['off'] + $chunk ) - 1;
			$r  = NM_Dropbox::download( $src, (int) $ss['off'], $to );
			if ( is_wp_error( $r ) ) {
				if ( self::again( $job, $ss, $r ) ) {
					continue;
				}
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				$job['error'] = NM_Dropbox::redact( $r->get_error_message() );
				return 'next';
			}
			$fh = @fopen( $tmp, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $fh ) {
				$job['error'] = NM_Util::t( 'Δεν γράφεται το αρχείο στον δίσκο.' );
				return 'next';
			}
			ftruncate( $fh, (int) $ss['off'] );
			fseek( $fh, (int) $ss['off'] );
			$ok = strlen( $r ) === (int) fwrite( $fh, $r ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			if ( ! $ok ) {
				$job['error'] = NM_Util::t( 'Δεν γράφεται το αρχείο στον δίσκο.' );
				return 'next';
			}
			$ss['off']  += strlen( $r );
			$ss['got']   = (int) $ss['got'] + strlen( $r );
			$ss['fails'] = 0;
			$job['ss']   = $ss;
			NM_Storage::save_job( $job );
		}
		$job['ss'] = $ss;
		return 'more';
	}

	/* ---------------------------------------------------------------
	 * Delete, link, list
	 * ------------------------------------------------------------- */

	/**
	 * Delete a backup's files in Dropbox (only names that pass the patterns).
	 * Without a local copy the metadata goes too.
	 */
	public static function delete( string $id ): bool {
		$m = NM_Storage::meta( $id );
		if ( ! $m || empty( $m['remote'] ) ) {
			return false;
		}
		$host  = self::host( $m );
		$names = array();
		foreach ( (array) $m['parts'] as $p ) {
			$names[] = (string) $p['file'];
		}
		$names[] = $id . '.json';
		foreach ( $names as $n ) {
			$path = NM_Dropbox::path( $host, $n );
			if ( '' === $path || false === strpos( $n, $id ) ) {
				continue;
			}
			$r = NM_Dropbox::rpc( 'files/delete_v2', array( 'path' => $path ) );
			if ( is_wp_error( $r ) && ! ( 'api' === $r->get_error_code() && false !== strpos( $r->get_error_message(), 'not_found' ) ) ) {
				NM_Util::log( 'dropbox: delete ' . $n . ': ' . $r->get_error_message(), true );
				return false;
			}
		}
		NM_Util::log( 'dropbox: backup ' . $id . ' deleted' );
		if ( ! self::has_local( $m ) ) {
			NM_Storage::delete( $id );
			return true;
		}
		unset( $m['remote'] );
		NM_Storage::save_meta( $m );
		return true;
	}

	/** A Dropbox link (four hours) for the browser, or ''. */
	public static function link( string $id, int $n ): string {
		$m = NM_Storage::meta( $id );
		if ( ! $m || ! self::in_dropbox( $m ) || ! isset( $m['parts'][ $n ] ) ) {
			return '';
		}
		$path = NM_Dropbox::path( self::host( $m ), (string) $m['parts'][ $n ]['file'] );
		$r    = '' === $path ? null : NM_Dropbox::rpc( 'files/get_temporary_link', array( 'path' => $path ) );
		$url  = is_array( $r ) ? (string) ( $r['link'] ?? '' ) : '';
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$ok   = 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && (bool) preg_match( '/(^|\.)(dropboxusercontent\.com|dropbox\.com)$/', $host );
		return $ok || (bool) apply_filters( 'nm_dropbox_test_link', false, $url ) ? $url : '';
	}

	/**
	 * Read the backups in Dropbox (this site's and other sites') and add
	 * the ones this server does not know to the list, as "Dropbox only".
	 * Everything read is checked as untrusted: names by pattern, numbers
	 * as numbers, only parts that really are in the folder.
	 *
	 * @return int|WP_Error How many were added.
	 */
	public static function sync() {
		$r = NM_Dropbox::rpc(
			'files/list_folder',
			array(
				'path'      => '',
				'recursive' => true,
				'limit'     => 2000,
			)
		);
		$files = array();
		for ( $page = 0; $page < 10; $page++ ) {
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			foreach ( (array) ( $r['entries'] ?? array() ) as $e ) {
				if ( 'file' !== ( $e['.tag'] ?? '' ) ) {
					continue;
				}
				$p = (string) ( $e['path_lower'] ?? '' );
				if ( preg_match( '#^/([a-z0-9.-]{1,80})/([^/]+)$#', $p, $x ) ) {
					$files[ $x[1] ][ $x[2] ] = array(
						'size' => (int) ( $e['size'] ?? 0 ),
						'hash' => (string) ( $e['content_hash'] ?? '' ),
					);
				}
			}
			if ( empty( $r['has_more'] ) || empty( $r['cursor'] ) ) {
				break;
			}
			$r = NM_Dropbox::rpc( 'files/list_folder/continue', array( 'cursor' => (string) $r['cursor'] ) );
		}
		$added = 0;
		foreach ( $files as $host => $names ) {
			foreach ( $names as $name => $info ) {
				if ( $added >= 100 || ! preg_match( '/^([a-f0-9]{32})\.json$/', $name, $x ) || $info['size'] > 262144 ) {
					continue;
				}
				$id  = $x[1];
				$old = NM_Storage::meta( $id );
				if ( $old ) {
					if ( empty( $old['remote'] ) ) {
						// Known here, and it is in Dropbox: note it.
						$m = self::import( $id, $host, $names, (string) json_encode( $old ) );
						if ( $m ) {
							$old['remote'] = $m['remote'];
							NM_Storage::save_meta( $old );
						}
					}
					continue;
				}
				$body = NM_Dropbox::get_small( (string) NM_Dropbox::path( $host, $name ) );
				if ( is_wp_error( $body ) ) {
					continue;
				}
				$m = self::import( $id, $host, $names, (string) $body );
				if ( $m ) {
					NM_Storage::save_meta( $m );
					++$added;
				}
			}
		}
		NM_Util::log( 'dropbox: list read, ' . $added . ' new' );
		return $added;
	}

	/** A metadata record from a JSON in Dropbox, or null when anything is off. */
	private static function import( string $id, string $host, array $names, string $json ): ?array {
		$j = json_decode( $json, true );
		if ( ! is_array( $j ) || ( $j['id'] ?? '' ) !== $id || ! is_array( $j['parts'] ?? null ) || ! $j['parts'] || count( $j['parts'] ) > 999 ) {
			return null;
		}
		$parts = array();
		$rfile = array();
		foreach ( $j['parts'] as $p ) {
			$f   = is_array( $p ) ? (string) ( $p['file'] ?? '' ) : '';
			$sz  = is_array( $p ) ? (int) ( $p['size'] ?? 0 ) : 0;
			$sha = is_array( $p ) ? strtolower( (string) ( $p['sha256'] ?? '' ) ) : '';
			if ( ! preg_match( NM_Storage::FILE_RE, $f ) || false === strpos( $f, $id ) || 0 !== strpos( $f, $host . '-' ) || $sz <= 0 || ! preg_match( '/^[a-f0-9]{64}$/', $sha ) ) {
				return null;
			}
			if ( ! isset( $names[ $f ] ) || $names[ $f ]['size'] !== $sz ) {
				return null; // Not (all) in Dropbox.
			}
			$parts[]     = array(
				'file'   => $f,
				'size'   => $sz,
				'sha256' => $sha,
			);
			$rfile[ $f ] = array(
				'size' => $sz,
				'hash' => preg_match( '/^[a-f0-9]{64}$/', $names[ $f ]['hash'] ) ? $names[ $f ]['hash'] : '',
			);
		}
		$scopes = array( 'full', 'db', 'files', 'custom' );
		return array(
			'id'       => $id,
			'created'  => max( 0, (int) ( $j['created'] ?? 0 ) ),
			'status'   => 'done',
			'scope'    => in_array( $j['scope'] ?? '', $scopes, true ) ? $j['scope'] : 'full',
			'note'     => substr( sanitize_text_field( (string) ( $j['note'] ?? '' ) ), 0, 120 ),
			'sched'    => '',
			'enc'      => ! empty( $j['enc'] ),
			'home'     => esc_url_raw( (string) ( $j['home'] ?? '' ) ),
			'parts'    => $parts,
			'size'     => array_sum( array_column( $parts, 'size' ) ),
			'db'       => ! empty( $j['db'] ),
			'groups'   => array_values( array_intersect( NM_Backup::GROUPS, (array) ( $j['groups'] ?? array() ) ) ),
			'files'    => max( 0, (int) ( $j['files'] ?? 0 ) ),
			'tables'   => max( 0, (int) ( $j['tables'] ?? 0 ) ),
			'wp'       => substr( preg_replace( '/[^0-9a-z.-]/i', '', (string) ( $j['wp'] ?? '' ) ), 0, 20 ),
			'flags'    => array(),
			'warn'     => array(),
			'local'    => false,
			'imported' => true,
			'remote'   => array(
				'state' => 'done',
				'host'  => $host,
				'files' => $rfile,
				'time'  => time(),
				'error' => '',
			),
		);
	}

	/* ---------------------------------------------------------------
	 * Retention (scheduled backups only)
	 * ------------------------------------------------------------- */

	/**
	 * Keep the newest rkeep backups of the schedule in Dropbox, then the
	 * size limit of all scheduled backups there. The newest of each
	 * schedule always stays.
	 */
	public static function retention( array $s ): void {
		$mine  = array();
		$sched = array();
		foreach ( NM_Storage::all() as $m ) { // Newest first.
			if ( empty( $m['sched'] ) || ! self::in_dropbox( $m ) ) {
				continue;
			}
			$sched[] = $m;
			if ( $m['sched'] === $s['id'] ) {
				$mine[] = $m;
			}
		}
		$keep = (int) ( $s['rkeep'] ?? 0 ) > 0 ? (int) $s['rkeep'] : (int) $s['keep'];
		$gone = array();
		foreach ( array_slice( $mine, max( 1, $keep ) ) as $m ) {
			if ( self::delete( (string) $m['id'] ) ) {
				$gone[ $m['id'] ] = true;
			}
		}
		$quota = (float) NM_Dropbox::get()['quota'] * 1073741824;
		if ( $quota <= 0 ) {
			return;
		}
		$newest = array();
		$total  = 0.0;
		foreach ( $sched as $m ) {
			if ( isset( $gone[ $m['id'] ] ) ) {
				continue;
			}
			if ( ! isset( $newest[ $m['sched'] ] ) ) {
				$newest[ $m['sched'] ] = $m['id'];
			}
			$total += (float) ( $m['size'] ?? 0 );
		}
		foreach ( array_reverse( $sched ) as $m ) { // Oldest first.
			if ( $total <= $quota ) {
				break;
			}
			if ( isset( $gone[ $m['id'] ] ) || in_array( $m['id'], $newest, true ) ) {
				continue;
			}
			if ( self::delete( (string) $m['id'] ) ) {
				$total -= (float) ( $m['size'] ?? 0 );
			}
		}
	}
}

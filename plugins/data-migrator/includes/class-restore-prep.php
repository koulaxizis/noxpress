<?php
/**
 * NM_Restore_Prep — everything before the runner takes over:
 *
 *  - Upload: chunked upload of one or more parts into incoming/, then
 *    registration as a backup (parts matched to the manifest by sha256
 *    and renamed to our pattern).
 *  - Analysis (dry run, read only): a job a-<hex> that checks every
 *    part's sha256, reads the manifest, unpacks the SQL chunks to a work
 *    folder and parses them without writing: how many old URLs and
 *    paths would change, local (localhost) URLs, the largest statement,
 *    the tables in the dump. Plus a list of the archive's files per group.
 *  - Plan: pre-flight checks, the replacement pairs, the options.
 *  - Start: a restore job r-<hex> with a one-time token (recovery code)
 *    for runner.php. Only the token's sha256 is stored.
 */

defined( 'ABSPATH' ) || exit;

final class NM_Restore_Prep {

	const UPLOAD_RE = '/^[a-f0-9]{32}$/';

	/* ---------------------------------------------------------------
	 * Upload
	 * ------------------------------------------------------------- */

	/**
	 * Append one chunk. Returns the bytes now stored for that file.
	 *
	 * @return int|WP_Error
	 */
	public static function upload_chunk( string $upload, int $index, int $offset, int $total, string $tmp ) {
		if ( ! preg_match( self::UPLOAD_RE, $upload ) || $index < 0 || $index > 99 || $total <= 0 ) {
			return new WP_Error( 'bad', NM_Util::t( 'Μη έγκυρο αίτημα.' ) );
		}
		$dir = NM_Storage::sub( 'incoming' );
		if ( '' === $dir ) {
			return new WP_Error( 'storage', NM_Util::t( 'Δεν βρέθηκε φάκελος με δικαίωμα εγγραφής για τα αντίγραφα. Όρισε έναν στις Ρυθμίσεις.' ) );
		}
		$d = $dir . '/' . $upload;
		if ( ! NM_Util::mkdirp( $d ) ) {
			return new WP_Error( 'storage', NM_Util::t( 'Δεν δημιουργήθηκε ο φάκελος εργασίας.' ) );
		}
		$file = $d . '/' . sprintf( '%02d', $index ) . '.zip';
		clearstatcache( true, $file );
		$have = is_file( $file ) ? (int) filesize( $file ) : 0;
		if ( $offset !== $have ) {
			return $have; // The browser resumes from here.
		}
		if ( 0 === $offset ) {
			$free = NM_Util::free_space( $dir );
			if ( $free >= 0 && $free < $total * 2.2 ) {
				return new WP_Error( 'space', NM_Util::tf( 'Δεν υπάρχει αρκετός χώρος στον δίσκο (ελεύθερα %1$s, χρειάζονται περίπου %2$s).', NM_Util::size( $free ), NM_Util::size( $total * 2.2 ) ) );
			}
		}
		$in  = @fopen( $tmp, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = @fopen( $file, 'ab' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $in || ! $out ) {
			return new WP_Error( 'write', NM_Util::t( 'Δεν γράφτηκε το κομμάτι στον δίσκο.' ) );
		}
		stream_copy_to_stream( $in, $out );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		clearstatcache( true, $file );
		$now = (int) filesize( $file );
		if ( $now > $total ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return new WP_Error( 'size', NM_Util::t( 'Το αρχείο ξεπέρασε το μέγεθος που δηλώθηκε.' ) );
		}
		return $now;
	}

	/**
	 * All parts are up: find the manifest, match parts, register.
	 *
	 * @return string|WP_Error Backup id.
	 */
	public static function upload_finish( string $upload ) {
		if ( ! preg_match( self::UPLOAD_RE, $upload ) ) {
			return new WP_Error( 'bad', NM_Util::t( 'Μη έγκυρο αίτημα.' ) );
		}
		$d     = NM_Storage::sub( 'incoming' ) . '/' . $upload;
		$files = glob( $d . '/*.zip' );
		if ( ! $files ) {
			return new WP_Error( 'none', NM_Util::t( 'Δεν ανέβηκε κανένα αρχείο.' ) );
		}
		NM_Util::prepare_request();
		$manifest = null;
		$last     = '';
		$enc      = false;
		foreach ( $files as $f ) {
			$m = self::read_manifest( (string) $f );
			if ( ! is_array( $m ) ) {
				// Encrypted: the plain header is enough to register it.
				$m   = self::read_head( (string) $f );
				$enc = is_array( $m );
			}
			if ( is_array( $m ) ) {
				$manifest = $m;
				$last     = (string) $f;
				break;
			}
		}
		if ( ! $manifest ) {
			self::discard( $upload );
			return new WP_Error( 'manifest', NM_Util::t( 'Δεν βρέθηκε το τελευταίο κομμάτι (με το manifest) ενός αντιγράφου του Data Migrator. Ανέβασε όλα τα κομμάτια μαζί.' ) );
		}
		$id = (string) ( $manifest['id'] ?? '' );
		if ( ! preg_match( NM_Storage::ID_RE, $id ) ) {
			self::discard( $upload );
			return new WP_Error( 'manifest', NM_Util::t( 'Το manifest του αρχείου δεν είναι έγκυρο.' ) );
		}
		if ( NM_Storage::meta( $id ) ) {
			self::discard( $upload );
			return $id; // Already here.
		}
		// Earlier parts by sha256.
		$need  = (array) ( $manifest['parts'] ?? array() );
		$parts = array();
		$left  = array_diff( array_map( 'strval', $files ), array( $last ) );
		foreach ( $need as $i => $p ) {
			$found = '';
			foreach ( $left as $k => $f ) {
				if ( (int) filesize( $f ) === (int) $p['size'] && hash_file( 'sha256', $f ) === (string) $p['sha256'] ) {
					$found = $f;
					unset( $left[ $k ] );
					break;
				}
			}
			if ( '' === $found ) {
				self::discard( $upload );
				return new WP_Error( 'parts', NM_Util::tf( 'Λείπει το κομμάτι %1$d από %2$d (%3$s).', $i + 1, count( $need ) + 1, (string) $p['file'] ) );
			}
			$parts[] = $found;
		}
		$parts[] = $last;
		$home    = (string) ( $enc ? $manifest['home'] : $manifest['site']['home'] );
		$host    = substr( preg_replace( '/[^a-z0-9.-]/', '-', strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) ) ), 0, 60 );
		$stamp   = gmdate( 'Ymd-His', (int) strtotime( (string) $manifest['created'] ) );
		$stem    = ( '' === trim( $host, '.-' ) ? 'site' : trim( $host, '.-' ) ) . '-' . $stamp . '-' . $id;
		$meta    = array();
		foreach ( $parts as $i => $f ) {
			$name = $stem . ( $i > 0 ? '-part' . ( $i + 1 ) : '' ) . '.zip';
			$dest = NM_Storage::part_path( $name );
			if ( '' === $dest || ! NM_Util::move( $f, $dest ) ) {
				self::discard( $upload );
				return new WP_Error( 'move', NM_Util::t( 'Δεν μεταφέρθηκε το αρχείο στον φάκελο των αντιγράφων.' ) );
			}
			$meta[] = array(
				'file'   => $name,
				'size'   => (int) filesize( $dest ),
				'sha256' => $i < count( $need ) ? (string) $need[ $i ]['sha256'] : (string) hash_file( 'sha256', $dest ),
			);
		}
		self::discard( $upload );
		$saved = array(
			'id'       => $id,
			'created'  => (int) strtotime( (string) $manifest['created'] ),
			'status'   => 'done',
			'scope'    => (string) ( $manifest['scope'] ?? 'full' ),
			'note'     => (string) ( $manifest['note'] ?? '' ),
			'home'     => $home,
			'enc'      => $enc,
			'parts'    => $meta,
			'size'     => array_sum( array_column( $meta, 'size' ) ),
			'db'       => ! empty( $manifest['db'] ),
			'groups'   => (array) ( $manifest['groups'] ?? array() ),
			'files'    => (int) ( $manifest['files']['count'] ?? 0 ),
			'tables'   => count( (array) ( $manifest['tables'] ?? array() ) ),
			'wp'       => (string) ( $manifest['versions']['wp'] ?? '' ),
			'flags'    => (array) ( $manifest['flags'] ?? array() ),
			'uploaded' => time(),
		);
		if ( $enc ) {
			// Filled in from the manifest by the first analysis.
			unset( $saved['wp'] );
		}
		NM_Storage::save_meta( $saved );
		NM_Util::log( 'uploaded backup ' . $id . ' registered (' . count( $meta ) . ' part(s))' );
		return $id;
	}

	public static function discard( string $upload ): void {
		if ( preg_match( self::UPLOAD_RE, $upload ) ) {
			$base = NM_Storage::sub( 'incoming' );
			NM_Util::rrmdir( $base . '/' . $upload, $base );
		}
	}

	/** Why the last read_manifest() returned null: '', nopass, password, … */
	public static $error = '';

	/** nm/manifest.json of a part, or null (see $error). */
	public static function read_manifest( string $file, string $pw = '' ) {
		self::$error = '';
		$zr          = new NM_Zip_Reader( $file );
		if ( ! $zr->open() ) {
			self::$error = 'open';
			return null;
		}
		$zr->set_password( $pw );
		$e = $zr->find( 'nm/manifest.json' );
		$m = $e ? $zr->read( $e ) : false;
		if ( false === $m ) {
			self::$error = $e ? $zr->error : 'none';
		}
		$zr->close();
		$j = is_string( $m ) ? json_decode( $m, true ) : null;
		return ( is_array( $j ) && 'data-migrator' === ( $j['plugin'] ?? '' ) && isset( $j['site']['home'] ) ) ? $j : null;
	}

	/** nm/encrypted.json (the plain header of an encrypted backup), or null. */
	public static function read_head( string $file ) {
		$zr = new NM_Zip_Reader( $file );
		if ( ! $zr->open() ) {
			return null;
		}
		$e = $zr->find( 'nm/encrypted.json' );
		$m = $e && $e['aes'] < 0 ? $zr->read( $e ) : false;
		$zr->close();
		$j = is_string( $m ) ? json_decode( $m, true ) : null;
		return ( is_array( $j ) && 'data-migrator' === ( $j['plugin'] ?? '' ) && isset( $j['home'], $j['id'], $j['created'] ) && is_array( $j['parts'] ?? null ) ) ? $j : null;
	}

	/** The password of a job (sealed in its state), '' when none. */
	public static function job_password( array $job ): string {
		if ( empty( $job['pw'] ) ) {
			return '';
		}
		$pw = NM_Crypt::unseal( (string) $job['pw'] );
		return null === $pw ? '' : $pw;
	}

	/** Text for a reader error about the password. */
	public static function pw_text( string $code ): string {
		if ( 'nopass' === $code ) {
			return NM_Util::t( 'Το αντίγραφο είναι κρυπτογραφημένο. Γράψε τον κωδικό του.' );
		}
		if ( 'password' === $code ) {
			return NM_Util::t( 'Λάθος κωδικός για αυτό το αντίγραφο.' );
		}
		if ( 'openssl' === $code ) {
			return NM_Util::t( 'Το αντίγραφο είναι κρυπτογραφημένο και η PHP του server δεν έχει την επέκταση OpenSSL.' );
		}
		return '';
	}

	/** Absolute part paths of a backup in order, or array(). */
	public static function parts( array $meta ): array {
		$out = array();
		foreach ( (array) $meta['parts'] as $p ) {
			$path = NM_Storage::part_path( (string) $p['file'] );
			if ( '' === $path || ! is_file( $path ) ) {
				return array();
			}
			$out[] = $path;
		}
		return $out;
	}

	/* ---------------------------------------------------------------
	 * Analysis job
	 * ------------------------------------------------------------- */

	/**
	 * $pw: the password of an encrypted backup; '' = the one in Settings.
	 *
	 * @return array|WP_Error
	 */
	public static function analyze_start( string $id, string $pw = '' ) {
		$meta = NM_Storage::meta( $id );
		if ( ! $meta || 'done' !== ( $meta['status'] ?? '' ) ) {
			return new WP_Error( 'none', NM_Util::t( 'Το αντίγραφο δεν βρέθηκε ή δεν ολοκληρώθηκε.' ) );
		}
		if ( ! self::parts( $meta ) ) {
			return new WP_Error( 'parts', NM_Util::t( 'Λείπει κάποιο κομμάτι του αντιγράφου από τον φάκελο.' ) );
		}
		if ( NM_Backup::running() ) {
			return new WP_Error( 'busy', NM_Util::t( 'Τρέχει ήδη μια εργασία του Data Migrator. Περίμενε να τελειώσει ή ακύρωσέ την.' ) );
		}
		$sealed = '';
		if ( ! empty( $meta['enc'] ) ) {
			if ( ! NM_Crypt::available() ) {
				return new WP_Error( 'openssl', self::pw_text( 'openssl' ) );
			}
			if ( '' === $pw ) {
				$pw = (string) NM_Settings::password();
			}
			$parts = self::parts( $meta );
			if ( '' === $pw || ! self::read_manifest( (string) end( $parts ), $pw ) ) {
				$why = '' === $pw ? 'nopass' : self::$error;
				return new WP_Error( 'password', '' !== self::pw_text( $why ) ? self::pw_text( $why ) : NM_Util::t( 'Το manifest του αρχείου δεν είναι έγκυρο.' ) );
			}
			$sealed = NM_Crypt::seal( $pw );
		}
		$hex  = NM_Util::hex( 16 );
		$work = NM_Storage::sub( 'work' ) . '/a' . $hex;
		NM_Util::mkdirp( $work . '/db' );
		$job = array(
			'id'      => 'a-' . $hex,
			'type'    => 'analyze',
			'backup'  => $id,
			'created' => time(),
			'user'    => get_current_user_id(),
			'status'  => 'running',
			'phase'   => 'verify',
			'ss'      => array(),
			'work'    => $work,
			'result'  => array(),
			'pw'      => $sealed,
		);
		NM_Storage::save_job( $job );
		return $job;
	}

	public static function step( string $job_id ): array {
		$path = NM_Storage::job_path( $job_id );
		$job  = '' === $path ? null : NM_Util::read_state( $path );
		if ( ! $job || 'analyze' !== ( $job['type'] ?? '' ) ) {
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
			while ( 'running' === $job['status'] && microtime( true ) < $deadline ) {
				$r = self::analyze_phase( $job, $deadline );
				if ( 'error' === $r ) {
					$job['status'] = 'failed';
					NM_Util::rrmdir( (string) $job['work'], NM_Storage::sub( 'work' ) );
				} elseif ( 'next' === $r ) {
					$order        = array( 'verify', 'extract', 'scan', 'done' );
					$job['phase'] = $order[ array_search( $job['phase'], $order, true ) + 1 ];
					$job['ss']    = array();
					if ( 'done' === $job['phase'] ) {
						$job['status'] = 'done';
						NM_Util::rrmdir( (string) $job['work'], NM_Storage::sub( 'work' ) );
					}
				}
				NM_Storage::save_job( $job );
			}
		} catch ( Throwable $e ) {
			$job['status'] = 'failed';
			$job['error']  = $e->getMessage();
			NM_Storage::save_job( $job );
		}
		NM_Util::unlock( $lock );
		return self::progress( $job );
	}

	public static function progress( array $job ): array {
		$labels = array(
			'verify'  => NM_Util::t( 'Έλεγχος αρχείων' ),
			'extract' => NM_Util::t( 'Ανάγνωση βάσης' ),
			'scan'    => NM_Util::t( 'Ανάλυση βάσης' ),
			'done'    => NM_Util::t( 'Έτοιμο' ),
		);
		$pct    = array(
			'verify'  => 5,
			'extract' => 30,
			'scan'    => 60,
			'done'    => 100,
		);
		return array(
			'status' => $job['status'],
			'phase'  => $job['phase'],
			'pct'    => $pct[ $job['phase'] ] ?? 0,
			'text'   => 'failed' === $job['status'] ? NM_Util::tf( 'Η ανάλυση απέτυχε: %s', (string) ( $job['error'] ?? '' ) ) : ( $labels[ $job['phase'] ] ?? '' ),
			'id'     => $job['backup'],
		);
	}

	private static function analyze_phase( array &$job, float $deadline ): string {
		$meta  = NM_Storage::meta( (string) $job['backup'] );
		$parts = $meta ? self::parts( $meta ) : array();
		if ( ! $parts ) {
			$job['error'] = NM_Util::t( 'Λείπει κάποιο κομμάτι του αντιγράφου από τον φάκελο.' );
			return 'error';
		}
		$ss = $job['ss'];

		if ( 'verify' === $job['phase'] ) {
			$i = (int) ( $ss['i'] ?? 0 );
			if ( $i < count( $parts ) ) {
				$want = (string) ( $meta['parts'][ $i ]['sha256'] ?? '' );
				if ( '' !== $want && hash_file( 'sha256', $parts[ $i ] ) !== $want ) {
					$job['error'] = NM_Util::tf( 'Το κομμάτι %s είναι κατεστραμμένο (άλλο checksum).', basename( $parts[ $i ] ) );
					return 'error';
				}
				$job['ss'] = array( 'i' => $i + 1 );
				return 'more';
			}
			$manifest = self::read_manifest( end( $parts ), self::job_password( $job ) );
			if ( ! $manifest ) {
				$job['error'] = '' !== self::pw_text( self::$error ) ? self::pw_text( self::$error ) : NM_Util::t( 'Το manifest του αρχείου δεν είναι έγκυρο.' );
				return 'error';
			}
			if ( ! empty( $meta['enc'] ) && ! isset( $meta['wp'] ) ) {
				// An uploaded encrypted copy: the rest of its details.
				$meta['note']   = (string) ( $manifest['note'] ?? '' );
				$meta['files']  = (int) ( $manifest['files']['count'] ?? 0 );
				$meta['tables'] = count( (array) ( $manifest['tables'] ?? array() ) );
				$meta['wp']     = (string) ( $manifest['versions']['wp'] ?? '' );
				$meta['flags']  = (array) ( $manifest['flags'] ?? array() );
				NM_Storage::save_meta( $meta );
			}
			// Contents per group, root files and drop-ins.
			$groups = array();
			$root   = array();
			$drop   = array();
			$usize  = 0;
			foreach ( $parts as $p ) {
				$zr = new NM_Zip_Reader( $p );
				if ( ! $zr->open() ) {
					$job['error'] = NM_Util::tf( 'Το αρχείο %s δεν διαβάζεται ως ZIP.', basename( $p ) );
					return 'error';
				}
				$pos = 0;
				$e   = $zr->entry_at( $pos, $next );
				while ( $e ) {
					$usize += $e['usize'];
					if ( preg_match( '#^files/([a-z-]+)/(.+)$#', $e['name'], $m ) ) {
						$g = $m[1];
						if ( ! isset( $groups[ $g ] ) ) {
							$groups[ $g ] = array(
								'files' => 0,
								'bytes' => 0,
							);
						}
						++$groups[ $g ]['files'];
						$groups[ $g ]['bytes'] += $e['usize'];
						if ( 'root' === $g ) {
							$top          = false === strpos( $m[2], '/' ) ? $m[2] : strtok( $m[2], '/' ) . '/';
							$root[ $top ] = ( $root[ $top ] ?? 0 ) + $e['usize'];
						} elseif ( 'dropins' === $g ) {
							$drop[] = $m[2];
						}
					}
					$pos = (int) $next;
					$e   = $zr->entry_at( $pos, $next );
				}
				$zr->close();
			}
			$job['result'] = array(
				'manifest' => $manifest,
				'groups'   => $groups,
				'root'     => $root,
				'dropins'  => $drop,
				'usize'    => $usize,
			);
			return 'next';
		}

		if ( 'extract' === $job['phase'] ) {
			if ( empty( $job['result']['manifest']['db'] ) ) {
				return 'next';
			}
			$ss += array(
				'part' => 0,
				'pos'  => 0,
			);
			while ( $ss['part'] < count( $parts ) ) {
				$zr = new NM_Zip_Reader( $parts[ $ss['part'] ] );
				if ( ! $zr->open() ) {
					return 'error';
				}
				$zr->set_password( self::job_password( $job ) );
				$e = $zr->entry_at( (int) $ss['pos'], $next );
				while ( $e ) {
					if ( preg_match( '#^db/\d{5}\.sql$#', $e['name'] ) ) {
						$done = 0;
						$r    = $zr->extract( $e, $job['work'] . '/db/' . substr( $e['name'], 3 ), $done, $deadline + 60 );
						if ( 'done' !== $r ) {
							$zr->close();
							$job['error'] = '' !== self::pw_text( $r ) ? self::pw_text( $r ) : NM_Util::tf( 'Το %1$s δεν βγήκε σωστά από το αρχείο (%2$s).', $e['name'], $r );
							return 'error';
						}
					}
					$ss['pos'] = (int) $next;
					if ( microtime( true ) >= $deadline ) {
						$zr->close();
						$job['ss'] = $ss;
						return 'more';
					}
					$e = $zr->entry_at( (int) $ss['pos'], $next );
				}
				$zr->close();
				++$ss['part'];
				$ss['pos'] = 0;
			}
			return 'next';
		}

		if ( 'scan' === $job['phase'] ) {
			if ( empty( $job['result']['manifest']['db'] ) ) {
				return 'next';
			}
			$m = $job['result']['manifest'];
			if ( ! $ss ) {
				$chunks = glob( $job['work'] . '/db/*.sql' );
				sort( $chunks );
				$ss = array(
					'chunks' => $chunks ? $chunks : array(),
					'src'    => (string) $m['site']['prefix'],
					'dst'    => (string) $m['site']['prefix'],
					'scan'   => true,
				);
			}
			// Count the old site's URLs and paths (the new values do not matter here).
			$im        = new NM_Importer( null, new NM_Replacer( self::old_pairs( $m ) ), $ss );
			$r         = $im->run( $deadline );
			$job['ss'] = $im->st;
			if ( 'done' === $r ) {
				// The old address itself is a pair already, also when it is a localhost one.
				$old   = preg_replace( '#^https?:#i', '', untrailingslashit( (string) $m['site']['home'] ) );
				// Per origin: one folder in every URL → the site lived there
				// (http://localhost/shop); several → it lived at the root.
				// This site's own address needs no suggestion either.
				$here = preg_replace( '#^https?:#i', '', untrailingslashit( home_url() ) );
				$by   = array();
				foreach ( (array) $im->st['local'] as $u => $n ) {
					$bare = preg_replace( '#^https?:#i', '', (string) $u );
					foreach ( array( $old, $here ) as $skip ) {
						if ( $bare === $skip || 0 === strpos( $bare, $skip . '/' ) ) {
							continue 2;
						}
					}
					$origin                     = (string) preg_replace( '#^(https?://[^/]+).*$#i', '$1', (string) $u );
					$by[ $origin ][ (string) $u ] = (int) $n;
				}
				$local = array();
				foreach ( $by as $origin => $urls ) {
					$folders = array_diff( array_keys( $urls ), array( $origin ) );
					$key     = ( 1 === count( $folders ) && ! isset( $urls[ $origin ] ) ) ? reset( $folders ) : $origin;
					$local[ $key ] = array_sum( $urls );
				}
				arsort( $local );
				$job['result']['repl']    = (int) $im->st['repl'];
				$job['result']['local']   = array_slice( $local, 0, 10, true );
				$job['result']['maxstmt'] = (int) $im->st['maxstmt'];
				$job['result']['tables']  = array_keys( (array) $im->st['temp'] );
				$job['result']['rows']    = array_sum( array_map( 'intval', $im->st['rows'] ) );
				return 'next';
			}
			return 'error' === $r ? 'error' : 'more';
		}
		return 'next';
	}

	/** Pairs from the old site's URLs and paths to a placeholder (for counting). */
	private static function old_pairs( array $m ): array {
		$x     = 'https://nm-new.invalid';
		$pairs = NM_Replacer::url_pairs( (string) $m['site']['home'], $x ) + NM_Replacer::url_pairs( (string) $m['site']['siteurl'], $x );
		$pairs += NM_Replacer::path_pairs( (string) $m['site']['abspath'], '/nm-new-path' );
		return $pairs;
	}

	/* ---------------------------------------------------------------
	 * Plan
	 * ------------------------------------------------------------- */

	/** True when the backup comes from this same site (URL and path). */
	public static function same_site( array $m ): bool {
		return untrailingslashit( (string) $m['site']['home'] ) === untrailingslashit( home_url() )
			&& NM_Util::norm( (string) $m['site']['abspath'] ) === NM_Util::norm( ABSPATH );
	}

	/**
	 * Pre-flight checks: list of [level ok|info|warn|fail, text].
	 */
	public static function checks( array $res, array $opts = array() ): array {
		global $wp_version, $wpdb;
		$m   = $res['manifest'];
		$out = array();
		$v   = $m['versions'];

		// PHP.
		if ( version_compare( PHP_VERSION, (string) $v['php'], '<' ) && version_compare( substr( PHP_VERSION, 0, 3 ), substr( (string) $v['php'], 0, 3 ), '<' ) ) {
			$out[] = array( 'warn', NM_Util::tf( 'Η PHP εδώ (%1$s) είναι παλαιότερη από της πηγής (%2$s). Κάποιο plugin μπορεί να μη δουλεύει.', PHP_VERSION, (string) $v['php'] ) );
		} else {
			$out[] = array( 'ok', NM_Util::tf( 'PHP %1$s (πηγή %2$s).', PHP_VERSION, (string) $v['php'] ) );
		}
		// WordPress core.
		$core = in_array( 'core', (array) ( $opts['groups'] ?? $m['groups'] ), true ) && isset( $res['groups']['core'] );
		if ( ! $core && version_compare( $wp_version, (string) $v['wp'], '<' ) ) {
			$out[] = array( 'warn', NM_Util::tf( 'Το WordPress εδώ (%1$s) είναι παλαιότερο από της πηγής (%2$s) και τα αρχεία του core δεν επαναφέρονται. Ενημέρωσε πρώτα το WordPress.', $wp_version, (string) $v['wp'] ) );
		} else {
			$out[] = array( 'ok', NM_Util::tf( 'WordPress %1$s (πηγή %2$s).', $core ? (string) $v['wp'] : $wp_version, (string) $v['wp'] ) );
		}
		// Database server.
		$db = NM_Backup::db();
		if ( ! $db ) {
			$out[] = array( 'fail', NM_Util::t( 'Δεν έγινε σύνδεση με τη βάση δεδομένων.' ) );
		} else {
			$packet = $db->max_packet();
			$need   = (int) ( $res['maxstmt'] ?? $m['maxstmt'] ?? 0 ) + 1024;
			if ( ! empty( $m['db'] ) && $packet > 0 && $packet < $need ) {
				$out[] = array( 'fail', NM_Util::tf( 'Το max_allowed_packet της MySQL (%1$s) είναι μικρότερο από τη μεγαλύτερη εντολή του αντιγράφου (%2$s). Ζήτησε από τον host να το αυξήσει.', NM_Util::size( $packet ), NM_Util::size( $need ) ) );
			}
			$here = $db->server_version();
			$out[] = array( 'ok', NM_Util::tf( 'Βάση %1$s (πηγή %2$s).', $here, (string) $v['mysql'] ) );
			if ( false !== stripos( $here, 'mariadb' ) && false === stripos( (string) $v['mysql'], 'mariadb' ) && version_compare( (string) $v['mysql'], '8.0', '>=' ) ) {
				$out[] = array( 'info', NM_Util::t( 'Η πηγή είναι MySQL 8 και εδώ MariaDB: οι collations utf8mb4_0900_* γίνονται utf8mb4_unicode_520_ci.' ) );
			}
			$db->close();
		}
		// Disk.
		$free = NM_Util::free_space( NM_Storage::dir() );
		$need = (float) ( $res['usize'] ?? 0 ) * 1.1;
		if ( $free >= 0 && $free < $need ) {
			$out[] = array( 'fail', NM_Util::tf( 'Δεν υπάρχει αρκετός χώρος στον δίσκο (ελεύθερα %1$s, χρειάζονται περίπου %2$s).', NM_Util::size( $free ), NM_Util::size( $need ) ) );
		} elseif ( $free >= 0 ) {
			$out[] = array( 'ok', NM_Util::tf( 'Χώρος στον δίσκο: %1$s ελεύθερα, χρειάζονται περίπου %2$s.', NM_Util::size( $free ), NM_Util::size( $need ) ) );
		}
		// Prefix.
		if ( (string) $m['site']['prefix'] !== $wpdb->prefix ) {
			$out[] = array( 'info', NM_Util::tf( 'Το πρόθεμα πινάκων αλλάζει από %1$s σε %2$s (το κρατά το wp-config.php εδώ).', (string) $m['site']['prefix'], $wpdb->prefix ) );
		}
		// What is not restored.
		if ( ! empty( $m['views'] ) ) {
			$out[] = array( 'warn', NM_Util::tf( 'Views στην πηγή που δεν επαναφέρονται: %s', implode( ', ', (array) $m['views'] ) ) );
		}
		if ( ! empty( $m['routines']['triggers'] ) || ! empty( $m['routines']['procedures'] ) ) {
			$out[] = array( 'warn', NM_Util::tf( 'Η πηγή είχε %1$d triggers και %2$d procedures/functions, που δεν επαναφέρονται.', (int) $m['routines']['triggers'], (int) $m['routines']['procedures'] ) );
		}
		if ( isset( $m['site']['blog_public'] ) && 0 === (int) $m['site']['blog_public'] ) {
			$out[] = array( 'warn', NM_Util::t( 'Στην πηγή οι μηχανές αναζήτησης ήταν αποκλεισμένες (blog_public = 0). Διάλεξε παρακάτω τι θα ισχύει εδώ.' ) );
		}
		if ( 'changed' === ( $m['integrity']['status'] ?? '' ) ) {
			$out[] = array( 'warn', NM_Util::t( 'Στην πηγή, ο έλεγχος του core βρήκε αλλαγμένα ή άγνωστα αρχεία. Δες τις λεπτομέρειες στο αντίγραφο πριν την επαναφορά.' ) );
		}
		if ( ! empty( $res['local'] ) ) {
			$out[] = array( 'warn', NM_Util::t( 'Η βάση έχει διευθύνσεις τοπικής ανάπτυξης (localhost). Δες τις προτάσεις παρακάτω.' ) );
		}
		if ( is_multisite() ) {
			$out[] = array( 'fail', NM_Util::t( 'Το Data Migrator δεν υποστηρίζει multisite.' ) );
		}
		return $out;
	}

	/** Default options for the plan form. */
	public static function defaults( array $res ): array {
		$m    = $res['manifest'];
		$same = self::same_site( $m );
		$root = array();
		$flag = (array) ( $m['flags'] ?? array() );
		foreach ( array_keys( (array) $res['root'] ) as $name ) {
			$base = rtrim( (string) $name, '/' );
			if ( isset( $flag[ $base ] ) ) {
				continue; // Unknown or host files: only when ticked.
			}
			if ( '.htaccess' === $base && ! $same ) {
				continue;
			}
			$root[] = (string) $name;
		}
		$groups = array_keys( (array) $res['groups'] );
		if ( ! $same ) {
			$groups = array_values( array_diff( $groups, array( 'dropins' ) ) );
		}
		return array(
			'db'          => ! empty( $m['db'] ),
			'groups'      => $groups,
			'root_files'  => $root,
			'home'        => home_url(),
			'blog_public' => $same ? (int) ( $m['site']['blog_public'] ?? 1 ) : (int) get_option( 'blog_public', 1 ),
			'salts'       => false,
			'bare'        => false,
			'local'       => array(),
			'extra'       => '',
		);
	}

	/** All replacement pairs for the chosen options. */
	public static function pairs( array $m, array $o ): array {
		$home    = untrailingslashit( (string) $o['home'] );
		$siteurl = untrailingslashit( site_url() );
		if ( untrailingslashit( home_url() ) !== $home ) {
			// The user typed another address: WordPress lives at the same place below it.
			$siteurl = $home . substr( $siteurl, strlen( untrailingslashit( home_url() ) ) );
		}
		$pairs = NM_Replacer::url_pairs( (string) $m['site']['home'], $home );
		if ( untrailingslashit( (string) $m['site']['siteurl'] ) !== untrailingslashit( (string) $m['site']['home'] ) ) {
			$pairs = NM_Replacer::url_pairs( (string) $m['site']['siteurl'], $siteurl ) + $pairs;
		}
		$dirs = NM_Backup::dirs();
		$src  = (array) $m['site']['dirs'];
		$pairs += NM_Replacer::path_pairs( (string) $m['site']['abspath'], $dirs['core'] );
		foreach ( array( 'content', 'uploads' ) as $g ) {
			if ( isset( $src[ $g ] ) && ! NM_Util::inside( (string) $src[ $g ], (string) $m['site']['abspath'] ) ) {
				$pairs += NM_Replacer::path_pairs( (string) $src[ $g ], $dirs[ $g ] );
			}
		}
		foreach ( (array) $o['local'] as $u ) {
			$pairs += NM_Replacer::url_pairs( (string) $u, $home );
		}
		if ( ! empty( $o['bare'] ) ) {
			$old = NM_Util::bare_host( (string) $m['site']['home'] );
			$new = NM_Util::bare_host( $home );
			if ( '' !== $old && '' !== $new && $old !== $new ) {
				$pairs[ $old ] = $new;
			}
		}
		foreach ( preg_split( '/\R/', (string) $o['extra'] ) as $line ) {
			$p = explode( '=>', (string) $line, 2 );
			if ( 2 === count( $p ) ) {
				$f = trim( $p[0] );
				$t = trim( $p[1] );
				if ( strlen( $f ) >= 4 && $f !== $t ) {
					$pairs[ $f ] = $t;
				}
			}
		}
		return $pairs;
	}

	/* ---------------------------------------------------------------
	 * Start the restore
	 * ------------------------------------------------------------- */

	/**
	 * @return array|WP_Error [job, token]
	 */
	public static function start( string $analysis_id, array $o ) {
		global $wpdb;
		$an = NM_Storage::job( $analysis_id );
		if ( ! $an || 'analyze' !== $an['type'] || 'done' !== $an['status'] ) {
			return new WP_Error( 'analysis', NM_Util::t( 'Κάνε πρώτα την ανάλυση του αντιγράφου.' ) );
		}
		$meta  = NM_Storage::meta( (string) $an['backup'] );
		$parts = $meta ? self::parts( $meta ) : array();
		if ( ! $parts ) {
			return new WP_Error( 'parts', NM_Util::t( 'Λείπει κάποιο κομμάτι του αντιγράφου από τον φάκελο.' ) );
		}
		if ( NM_Backup::running() ) {
			return new WP_Error( 'busy', NM_Util::t( 'Τρέχει ήδη μια εργασία του Data Migrator. Περίμενε να τελειώσει ή ακύρωσέ την.' ) );
		}
		foreach ( self::checks( $an['result'], $o ) as $c ) {
			if ( 'fail' === $c[0] ) {
				return new WP_Error( 'check', $c[1] );
			}
		}
		$m    = $an['result']['manifest'];
		$hex  = NM_Util::hex( 16 );
		$tag  = substr( NM_Util::hex( 3 ), 0, 6 );
		$work = NM_Storage::sub( 'work' ) . '/r' . $hex;
		if ( ! NM_Util::mkdirp( $work . '/db' ) ) {
			return new WP_Error( 'work', NM_Util::t( 'Δεν δημιουργήθηκε ο φάκελος εργασίας.' ) );
		}
		NM_Storage::protect( $work );

		// Stage next to the files it replaces, so moves are renames.
		$guard  = NM_Storage::dir();
		$sguard = $guard;
		$stage  = $work . '/stage';
		$rb     = $work . '/rollback';
		$sroot  = '';
		$st1    = @stat( $work ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$st2    = @stat( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $st1 || ! $st2 || $st1['dev'] !== $st2['dev'] ) {
			$sroot  = NM_Util::norm( WP_CONTENT_DIR ) . '/nm-stage-' . $hex;
			$sguard = NM_Util::norm( WP_CONTENT_DIR );
			if ( NM_Util::mkdirp( $sroot ) ) {
				NM_Storage::protect( $sroot );
				$stage = $sroot . '/stage';
				$rb    = $sroot . '/rollback';
			} else {
				$sroot  = '';
				$sguard = $guard;
			}
		}
		NM_Util::mkdirp( $stage );
		NM_Util::mkdirp( $rb );

		// This restore is the last one, also in the restored database.
		$st         = get_option( 'nm_state', array() );
		$st         = is_array( $st ) ? $st : array();
		$st['last'] = 'r-' . $hex;
		update_option( 'nm_state', $st, false );

		// Our settings stay this site's.
		$keep = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'nm_' ) . '%' ), ARRAY_A );
		foreach ( (array) $rows as $r ) {
			if ( 'nm_post_restore' !== $r['option_name'] ) {
				$keep[ $r['option_name'] ] = $r['option_value'];
			}
		}

		$groups = array_values( array_intersect( array_keys( (array) $an['result']['groups'] ), (array) $o['groups'] ) );
		$tables = null;
		if ( isset( $o['tables'] ) && is_array( $o['tables'] ) ) {
			$tables = array_values( array_intersect( (array) $an['result']['tables'], $o['tables'] ) );
		}
		$token = NM_Util::hex( 16 );
		$cfg   = is_file( ABSPATH . 'wp-config.php' ) ? ABSPATH . 'wp-config.php' : ( is_file( dirname( ABSPATH ) . '/wp-config.php' ) ? dirname( ABSPATH ) . '/wp-config.php' : '' );
		$home  = untrailingslashit( (string) $o['home'] );
		$job   = array(
			'id'         => 'r-' . $hex,
			'type'       => 'restore',
			'backup'     => $an['backup'],
			'created'    => time(),
			'user'       => get_current_user_id(),
			'lang'       => NM_Lang::lang(),
			'token_hash' => hash( 'sha256', $token ),
			'status'     => 'running',
			'step'       => 'extract',
			'ss'         => array(),
			'mode'       => '',
			'tag'        => $tag,
			'archive'    => array( 'parts' => $parts ),
			'pw'         => (string) ( $an['pw'] ?? '' ),
			'manifest'   => array(
				'prefix'  => (string) $m['site']['prefix'],
				'fks'     => (array) ( $m['fks'] ?? array() ),
				'home'    => (string) $m['site']['home'],
				'admins'  => (array) ( $m['admins'] ?? array() ),
				'created' => (string) $m['created'],
			),
			'target'     => array(
				'dirs'         => NM_Backup::dirs(),
				'prefix'       => $wpdb->prefix,
				'home'         => $home,
				'siteurl'      => untrailingslashit( home_url() ) !== $home ? $home . substr( untrailingslashit( site_url() ), strlen( untrailingslashit( home_url() ) ) ) : untrailingslashit( site_url() ),
				'own'          => dirname( plugin_basename( NM_FILE ) ),
				'basename'     => plugin_basename( NM_FILE ),
				'keep_options' => $keep,
				'config'       => $cfg,
			),
			'options'    => array(
				'db'          => ! empty( $o['db'] ) && ! empty( $m['db'] ),
				'groups'      => $groups,
				'root_files'  => array_values( array_map( 'strval', (array) $o['root_files'] ) ),
				'tables'      => $tables,
				'blog_public' => isset( $o['blog_public'] ) ? (int) (bool) $o['blog_public'] : null,
				'salts'       => ! empty( $o['salts'] ),
				'pairs'       => self::pairs( $m, $o ),
			),
			'work'       => array(
				'root'       => $work,
				'guard'      => $guard,
				'sguard'     => $sguard,
				'stage_root' => $sroot,
				'stage'      => $stage,
				'rollback'   => $rb,
				'db'         => $work . '/db',
				'list'       => $work . '/staged.txt',
				'journal'    => $work . '/journal.txt',
			),
			'report'     => array(),
		);
		if ( ! NM_Storage::save_job( $job ) ) {
			return new WP_Error( 'job', NM_Util::t( 'Δεν γράφτηκε η εργασία στον φάκελο αποθήκευσης.' ) );
		}
		NM_Util::$log_file = NM_Storage::log_file( $job['id'] );
		NM_Util::log( 'restore ' . $job['id'] . ' of backup ' . $an['backup'] . ' prepared: db ' . ( $job['options']['db'] ? 'yes' : 'no' ) . ', groups ' . implode( ',', $groups ) . ', ' . count( $job['options']['pairs'] ) . ' pairs' );
		return array( $job, $token );
	}

	/** An undo job for a finished restore (new token). @return array|WP_Error */
	public static function undo( string $restore_id ) {
		$job = NM_Storage::job( $restore_id );
		if ( ! $job || 'restore' !== $job['type'] || ! in_array( $job['status'], array( 'done', 'failed' ), true ) ) {
			return new WP_Error( 'none', NM_Util::t( 'Δεν υπάρχει επαναφορά για αναίρεση.' ) );
		}
		$token             = NM_Util::hex( 16 );
		$job['token_hash'] = hash( 'sha256', $token );
		$job['mode']       = 'rollback';
		$job['rb']         = array();
		$job['status']     = 'rolling-back';
		$job['lang']       = NM_Lang::lang();
		NM_Storage::save_job( $job );
		return array( $job, $token );
	}

	/** Drop snapshots and undo data of a finished restore. */
	public static function finalize( string $restore_id ): bool {
		$job = NM_Storage::job( $restore_id );
		if ( ! $job || 'restore' !== $job['type'] || ! in_array( $job['status'], array( 'done', 'failed', 'rolledback' ), true ) ) {
			return false;
		}
		$db = NM_Backup::db();
		if ( ! $db ) {
			return false;
		}
		$r = new NM_Restorer(
			$job,
			$db,
			static function ( $j ) {
				NM_Storage::save_job( $j );
			}
		);
		$r->finalize();
		$db->close();
		return true;
	}
}

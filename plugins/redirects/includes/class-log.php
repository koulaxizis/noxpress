<?php
/**
 * RDR_Log — the 404 log and the hits of the rules, in private files.
 *
 * Folder uploads/noxpress-redirects-<12 hex>/ (Bible §12 «Ιδιωτικά
 * αρχεία»): empty index.php, deny .htaccess, deny web.config, and an HTTP
 * self-test that warns when a file of the folder can be downloaded (nginx
 * ignores .htaccess). The folder is made on activation or on an admin
 * request, never on a visitor request.
 *
 * Files 404-YYYY-MM.log, one JSON line per event:
 *  t  unix time
 *  k  'n' a 404 nobody redirected | 'r' a redirect by a rule | 'g' a 410
 *  p  normalised path (no query string: emails and tokens live there)
 *  h  referrer host only, '=' for this site (an internal broken link), ''
 *  b  1 when the user agent looks like a crawler (the agent is not kept)
 *  i  rule id (r, g), c answered code (r)
 * No IP address, no user agent, no cookie, no user id.
 *
 * Limits on every write: logging on, the folder exists, at least 256 MB
 * free, the month's file under max_mb, at most per_min lines a minute
 * (a counter file with flock, not the database). Retention: the daily
 * cron deletes files older than «months». rate.txt and the probe files
 * are the only other files; every read, write and delete goes through the
 * name patterns first.
 */

defined( 'ABSPATH' ) || exit;

final class RDR_Log {

	const STATE    = 'rdr_state';
	const FILE_RE  = '/^404-(\d{4})-(\d{2})\.log$/';
	const DIR_RE   = '/^noxpress-redirects-[a-f0-9]{12}$/';
	const MIN_FREE = 268435456; // 256 MB.

	/* =====================================================================
	 * Folder
	 * =================================================================== */

	public static function state(): array {
		$s = get_option( self::STATE, array() );
		return is_array( $s ) ? $s : array();
	}

	/** Full path of the folder, or '' when it does not exist yet. */
	public static function dir(): string {
		$s = self::state();
		if ( empty( $s['dir'] ) || ! preg_match( self::DIR_RE, (string) $s['dir'] ) ) {
			return '';
		}
		$up  = wp_upload_dir( null, false );
		$dir = rtrim( (string) $up['basedir'], '/\\' ) . '/' . $s['dir'];
		return is_dir( $dir ) ? $dir : '';
	}

	/** Creates and protects the folder (activation, admin). */
	public static function ensure_dir(): string {
		$dir = self::dir();
		if ( '' !== $dir ) {
			self::protect( $dir );
			return $dir;
		}
		$up = wp_upload_dir( null, false );
		if ( ! empty( $up['error'] ) || empty( $up['basedir'] ) ) {
			return '';
		}
		$s    = self::state();
		$name = ! empty( $s['dir'] ) && preg_match( self::DIR_RE, (string) $s['dir'] ) ? (string) $s['dir'] : 'noxpress-redirects-' . bin2hex( random_bytes( 6 ) );
		$dir  = rtrim( (string) $up['basedir'], '/\\' ) . '/' . $name;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		self::protect( $dir );
		$s['dir'] = $name;
		update_option( self::STATE, $s, false );
		self::web_test();
		return $dir;
	}

	/** index.php, .htaccess and web.config (Bible §12, private files). */
	private static function protect( string $dir ): void {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "# Redirects: private folder.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $body ) {
			if ( ! is_file( $dir . '/' . $name ) ) {
				@file_put_contents( $dir . '/' . $name, $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/**
	 * HTTP self-test: can a file of the folder be downloaded?
	 * 'safe' | 'exposed' | 'unknown' (the site did not answer). Saved.
	 */
	public static function web_test(): string {
		$dir = self::dir();
		$res = 'unknown';
		if ( '' !== $dir ) {
			$up    = wp_upload_dir( null, false );
			$name  = 'probe-' . bin2hex( random_bytes( 8 ) ) . '.txt';
			$token = bin2hex( random_bytes( 8 ) );
			@file_put_contents( $dir . '/' . $name, $token ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$r = wp_remote_get(
				trailingslashit( (string) $up['baseurl'] ) . basename( $dir ) . '/' . $name,
				array(
					'timeout'     => 8,
					'redirection' => 0,
					'sslverify'   => false,
				)
			);
			@unlink( $dir . '/' . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			if ( ! is_wp_error( $r ) ) {
				$code = (int) wp_remote_retrieve_response_code( $r );
				$res  = ( 200 === $code && false !== strpos( (string) wp_remote_retrieve_body( $r ), $token ) ) ? 'exposed' : 'safe';
			}
		}
		$s             = self::state();
		$s['web']      = $res;
		$s['web_time'] = time();
		update_option( self::STATE, $s, false );
		return $res;
	}

	/* =====================================================================
	 * Write (visitor requests)
	 * =================================================================== */

	/** Path of the log file of a month. */
	private static function file_for( int $ts ): string {
		$dir = self::dir();
		return '' === $dir ? '' : $dir . '/404-' . gmdate( 'Y-m', $ts ) . '.log';
	}

	/**
	 * Appends one event. Never throws, never writes the database.
	 *
	 * @param array $e k, p, h, b, i, c (t is added).
	 */
	public static function write( array $e ): void {
		try {
			$s = RDR_Settings::get();
			if ( ! $s['log'] ) {
				return;
			}
			$dir = self::dir();
			if ( '' === $dir ) {
				return;
			}
			$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled by the host.
			if ( is_numeric( $free ) && (float) $free < self::MIN_FREE ) {
				return;
			}
			$now  = time();
			$file = self::file_for( $now );
			clearstatcache( true, $file );
			if ( is_file( $file ) && filesize( $file ) >= $s['max_mb'] * 1048576 ) {
				return;
			}
			if ( ! self::rate_ok( $dir, (int) $s['per_min'], $now ) ) {
				return;
			}
			$line = wp_json_encode( array( 't' => $now ) + $e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $line ) ) {
				return;
			}
			@file_put_contents( $file, $line . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** Per-minute counter in rate.txt ("YmdHi count"), under flock. */
	private static function rate_ok( string $dir, int $max, int $now ): bool {
		$fh = @fopen( $dir . '/rate.txt', 'c+' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return false;
		}
		$ok = false;
		if ( flock( $fh, LOCK_EX ) ) {
			$raw   = (string) stream_get_contents( $fh );
			$min   = gmdate( 'YmdHi', $now );
			$parts = explode( ' ', trim( $raw ) );
			$count = ( 2 === count( $parts ) && $parts[0] === $min ) ? (int) $parts[1] : 0;
			if ( $count < $max ) {
				$ok = true;
				++$count;
				ftruncate( $fh, 0 );
				rewind( $fh );
				fwrite( $fh, $min . ' ' . $count ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			}
			flock( $fh, LOCK_UN );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $ok;
	}

	/* =====================================================================
	 * Read (admin)
	 * =================================================================== */

	/** Log files: name => array( size, mtime ), newest first. */
	public static function files(): array {
		$dir = self::dir();
		$out = array();
		if ( '' === $dir ) {
			return $out;
		}
		foreach ( (array) scandir( $dir ) as $f ) {
			if ( is_string( $f ) && preg_match( self::FILE_RE, $f ) ) {
				$out[ $f ] = array( (int) filesize( $dir . '/' . $f ), (int) filemtime( $dir . '/' . $f ) );
			}
		}
		krsort( $out );
		return $out;
	}

	/**
	 * Events since a time, oldest first, through a callback (files of up to
	 * 50 MB each are read line by line).
	 *
	 * @param int      $since Unix time.
	 * @param callable $fn    Receives each event array.
	 */
	public static function each( int $since, callable $fn ): void {
		$dir = self::dir();
		if ( '' === $dir ) {
			return;
		}
		$from = gmdate( 'Y-m', $since );
		$names = array_keys( self::files() );
		sort( $names );
		foreach ( $names as $f ) {
			$m = array();
			preg_match( self::FILE_RE, $f, $m );
			if ( $m[1] . '-' . $m[2] < $from ) {
				continue;
			}
			$fh = @fopen( $dir . '/' . $f, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $fh ) {
				continue;
			}
			while ( false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$e = json_decode( $line, true );
				if ( is_array( $e ) && isset( $e['t'], $e['k'] ) && (int) $e['t'] >= $since ) {
					$fn( $e );
				}
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * The 404 report: one row per path that stayed 404, most hits first.
	 *
	 * @param int      $days    Period.
	 * @param string[] $ignored Paths hidden from the report.
	 * @return array{rows: array[], total: int, paths: int, bots: int, self: int}
	 */
	public static function report( int $days, array $ignored = array() ): array {
		$since = time() - $days * DAY_IN_SECONDS;
		$agg   = array();
		$tot   = 0;
		$bots  = 0;
		$self  = 0;
		$skip  = array_flip( $ignored );
		self::each(
			$since,
			static function ( array $e ) use ( &$agg, &$tot, &$bots, &$self, $skip ) {
				if ( 'n' !== $e['k'] || ! isset( $e['p'] ) ) {
					return;
				}
				$p = (string) $e['p'];
				if ( isset( $skip[ $p ] ) ) {
					return;
				}
				++$tot;
				if ( ! isset( $agg[ $p ] ) ) {
					$agg[ $p ] = array(
						'path'  => $p,
						'hits'  => 0,
						'first' => (int) $e['t'],
						'last'  => 0,
						'bots'  => 0,
						'self'  => 0,
						'hosts' => array(),
					);
				}
				$a          = &$agg[ $p ];
				$a['hits'] += 1;
				$a['last']  = max( $a['last'], (int) $e['t'] );
				if ( ! empty( $e['b'] ) ) {
					++$a['bots'];
					++$bots;
				}
				$h = isset( $e['h'] ) ? (string) $e['h'] : '';
				if ( '=' === $h ) {
					++$a['self'];
					++$self;
				} elseif ( '' !== $h ) {
					$a['hosts'][ $h ] = ( isset( $a['hosts'][ $h ] ) ? $a['hosts'][ $h ] : 0 ) + 1;
				}
				unset( $a );
			}
		);
		uasort(
			$agg,
			static function ( $x, $y ) {
				return $y['hits'] <=> $x['hits'] ?: $y['last'] <=> $x['last'];
			}
		);
		// Paths that a rule answers now (made after these visits) are done.
		$done = RDR_Rules::covered( array_keys( $agg ) );
		$tot  = 0;
		$bots = 0;
		$self = 0;
		foreach ( $agg as $p => &$a ) {
			if ( isset( $done[ $p ] ) ) {
				unset( $agg[ $p ] );
				continue;
			}
			$tot  += $a['hits'];
			$bots += $a['bots'];
			$self += $a['self'];
			arsort( $a['hosts'] );
			$a['hosts'] = array_slice( $a['hosts'], 0, 3, true );
		}
		unset( $a );
		return array(
			'rows'  => array_values( $agg ),
			'total' => $tot,
			'paths' => count( $agg ),
			'bots'  => $bots,
			'self'  => $self,
		);
	}

	/** Hits per rule id in the last $days (cached 5 minutes). */
	public static function hits( int $days = 30 ): array {
		$key = 'rdr_hits_' . $days;
		$c   = get_transient( $key );
		if ( is_array( $c ) ) {
			return $c;
		}
		$out = array();
		self::each(
			time() - $days * DAY_IN_SECONDS,
			static function ( array $e ) use ( &$out ) {
				if ( ( 'r' === $e['k'] || 'g' === $e['k'] ) && ! empty( $e['i'] ) ) {
					$i         = (int) $e['i'];
					$out[ $i ] = ( isset( $out[ $i ] ) ? $out[ $i ] : 0 ) + 1;
				}
			}
		);
		set_transient( $key, $out, 5 * MINUTE_IN_SECONDS );
		return $out;
	}

	/* =====================================================================
	 * Retention
	 * =================================================================== */

	/** Daily cron: deletes log files older than the kept months. */
	public static function cleanup(): int {
		$dir = self::dir();
		if ( '' === $dir ) {
			return 0;
		}
		$keep = (int) RDR_Settings::get()['months'];
		$edge = gmdate( 'Y-m', strtotime( gmdate( 'Y-m-01' ) . ' -' . ( $keep - 1 ) . ' months' ) );
		$n    = 0;
		foreach ( array_keys( self::files() ) as $f ) {
			$m = array();
			if ( preg_match( self::FILE_RE, $f, $m ) && $m[1] . '-' . $m[2] < $edge && @unlink( $dir . '/' . $f ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				++$n;
			}
		}
		// Probe files left by an interrupted self-test.
		foreach ( (array) scandir( $dir ) as $f ) {
			if ( is_string( $f ) && preg_match( '/^probe-[a-f0-9]{16}\.txt$/', $f ) && filemtime( $dir . '/' . $f ) < time() - HOUR_IN_SECONDS ) {
				@unlink( $dir . '/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		return $n;
	}

	/** Deletes every log file (button «Clear log»). */
	public static function clear(): int {
		$dir = self::dir();
		$n   = 0;
		foreach ( array_keys( self::files() ) as $f ) {
			if ( @unlink( $dir . '/' . $f ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				++$n;
			}
		}
		delete_transient( 'rdr_hits_30' );
		delete_transient( 'rdr_hits_7' );
		delete_transient( 'rdr_hits_90' );
		return $n;
	}
}

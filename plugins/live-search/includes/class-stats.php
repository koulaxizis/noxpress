<?php
/**
 * LVS_Stats — what visitors search, in private files (Bible §12 «Ιδιωτικά
 * αρχεία», the pattern of Redirects' 404 log). Never in the database.
 *
 * Folder uploads/noxpress-live-search-<12 hex>/: empty index.php, deny
 * .htaccess and web.config, and an HTTP self-test that warns when a file
 * can be downloaded (nginx ignores .htaccess). Made on activation or on an
 * admin request, never on a visitor request.
 *
 * Files search-YYYY-MM.log, one JSON line per search:
 *   t  unix time
 *   q  the phrase, trimmed, lower case, at most 80 characters
 *   n  how many products it found
 *   k  'r' the results page (Enter) | 's' a picked suggestion
 * No IP address, no user agent, no cookie, no user id. A phrase with «@»
 * (an email) is not written. Bots, shop managers and test mode are not
 * counted. Limits on every write: statistics on, plugin live, the folder
 * exists, 256 MB free, at most 5 MB a month, at most 120 lines a minute
 * (a counter file with flock). The daily cron deletes lines older than 90
 * days (whole files whose month ended before that).
 */

defined( 'ABSPATH' ) || exit;

final class LVS_Stats {

	const STATE    = 'lvs_stats';
	const FILE_RE  = '/^search-(\d{4})-(\d{2})\.log$/';
	const DIR_RE   = '/^noxpress-live-search-[a-f0-9]{12}$/';
	const MIN_FREE = 268435456;
	const MAX_FILE = 5242880;
	const PER_MIN  = 120;
	const DAYS     = 90;

	public static function state(): array {
		$s = get_option( self::STATE, array() );
		return is_array( $s ) ? $s : array();
	}

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
		$name = ! empty( $s['dir'] ) && preg_match( self::DIR_RE, (string) $s['dir'] ) ? (string) $s['dir'] : 'noxpress-live-search-' . bin2hex( random_bytes( 6 ) );
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

	private static function protect( string $dir ): void {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "# Live Search: private folder.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $body ) {
			if ( ! is_file( $dir . '/' . $name ) ) {
				@file_put_contents( $dir . '/' . $name, $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/** 'safe' | 'exposed' | 'unknown'. Saved. */
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

	/** Statistics are written for this request. */
	public static function counting(): bool {
		$s = LVS_Settings::get();
		if ( ! $s['stats'] || 'live' !== $s['mode'] ) {
			return false;
		}
		if ( is_user_logged_in() && current_user_can( Live_Search::CAP ) ) {
			return false;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $ua || preg_match( '/bot|crawl|spider|slurp|preview|fetch|monitor|curl|wget|python|headless|lighthouse/', $ua ) ) {
			return false;
		}
		return true;
	}

	/** Appends one search. Never throws, never writes the database. */
	public static function write( string $q, int $n, string $kind ): void {
		try {
			if ( ! self::counting() ) {
				return;
			}
			$q = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $q ) ) );
			$q = function_exists( 'mb_strtolower' ) ? mb_strtolower( $q, 'UTF-8' ) : strtolower( $q );
			$q = function_exists( 'mb_substr' ) ? mb_substr( $q, 0, 80, 'UTF-8' ) : substr( $q, 0, 80 );
			if ( '' === $q || false !== strpos( $q, '@' ) ) {
				return;
			}
			$dir = self::dir();
			if ( '' === $dir ) {
				return;
			}
			$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $free && $free < self::MIN_FREE ) {
				return;
			}
			$now  = time();
			$file = $dir . '/search-' . gmdate( 'Y-m', $now ) . '.log';
			if ( is_file( $file ) && (int) @filesize( $file ) > self::MAX_FILE ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return;
			}
			if ( ! self::rate_ok( $dir, $now ) ) {
				return;
			}
			$line = wp_json_encode(
				array(
					't' => $now,
					'q' => $q,
					'n' => max( 0, min( 100000, $n ) ),
					'k' => 's' === $kind ? 's' : 'r',
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
			if ( is_string( $line ) ) {
				@file_put_contents( $file, $line . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	private static function rate_ok( string $dir, int $now ): bool {
		$fh = @fopen( $dir . '/rate.txt', 'c+' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return false;
		}
		$ok = false;
		if ( flock( $fh, LOCK_EX ) ) {
			$raw = (string) stream_get_contents( $fh );
			list( $min, $cnt ) = array_map( 'intval', array_pad( explode( ' ', trim( $raw ) ), 2, 0 ) );
			$cur = (int) floor( $now / 60 );
			if ( $min !== $cur ) {
				$min = $cur;
				$cnt = 0;
			}
			if ( $cnt < self::PER_MIN ) {
				$cnt++;
				$ok = true;
			}
			ftruncate( $fh, 0 );
			rewind( $fh );
			fwrite( $fh, $min . ' ' . $cnt ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			flock( $fh, LOCK_UN );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $ok;
	}

	/** Log files, oldest first. */
	public static function files(): array {
		$dir = self::dir();
		if ( '' === $dir ) {
			return array();
		}
		$out = array();
		foreach ( (array) scandir( $dir ) as $f ) {
			if ( preg_match( self::FILE_RE, (string) $f ) ) {
				$out[] = $dir . '/' . $f;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Report of the last N days: phrase => { count, zero, last, found }.
	 *
	 * @return array{rows: array, total: int, zero: int}
	 */
	public static function report( int $days ): array {
		$since = time() - $days * DAY_IN_SECONDS;
		$agg   = array();
		$tot   = 0;
		$zero  = 0;
		foreach ( self::files() as $f ) {
			if ( preg_match( self::FILE_RE, basename( $f ), $m ) ) {
				$end = gmmktime( 0, 0, 0, (int) $m[2] + 1, 1, (int) $m[1] );
				if ( $end < $since ) {
					continue;
				}
			}
			$fh = @fopen( $f, 'r' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $fh ) {
				continue;
			}
			while ( false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$e = json_decode( $line, true );
				if ( ! is_array( $e ) || ! isset( $e['t'], $e['q'] ) || (int) $e['t'] < $since ) {
					continue;
				}
				$q = (string) $e['q'];
				if ( ! isset( $agg[ $q ] ) ) {
					$agg[ $q ] = array(
						'q'     => $q,
						'count' => 0,
						'zero'  => 0,
						'found' => 0,
						'last'  => 0,
					);
				}
				$agg[ $q ]['count']++;
				$n = (int) ( $e['n'] ?? 0 );
				if ( 0 === $n ) {
					$agg[ $q ]['zero']++;
					$zero++;
				}
				$agg[ $q ]['found'] = $n;
				$agg[ $q ]['last']  = max( $agg[ $q ]['last'], (int) $e['t'] );
				$tot++;
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		$rows = array_values( $agg );
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['count'] - $a['count'] ?: $b['last'] - $a['last'];
			}
		);
		return array(
			'rows'  => $rows,
			'total' => $tot,
			'zero'  => $zero,
		);
	}

	/** Deletes files whose month ended more than 90 days ago. */
	public static function cleanup(): int {
		$n     = 0;
		$limit = time() - self::DAYS * DAY_IN_SECONDS;
		foreach ( self::files() as $f ) {
			if ( preg_match( self::FILE_RE, basename( $f ), $m ) ) {
				$end = gmmktime( 0, 0, 0, (int) $m[2] + 1, 1, (int) $m[1] );
				if ( $end < $limit && @unlink( $f ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
					$n++;
				}
			}
		}
		return $n;
	}

	/** Deletes every log file (button «Clear»). */
	public static function clear(): int {
		$n = 0;
		foreach ( self::files() as $f ) {
			if ( preg_match( self::FILE_RE, basename( $f ) ) && @unlink( $f ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				$n++;
			}
		}
		return $n;
	}
}

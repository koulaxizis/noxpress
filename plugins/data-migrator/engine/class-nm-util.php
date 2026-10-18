<?php
/**
 * NM_Util — small helpers shared by the plugin, the runner and the
 * installer: state files, random names, paths, logging, translation.
 *
 * The engine/ folder never calls WordPress: the same classes run inside
 * WordPress, in runner.php (SHORTINIT) and in the standalone installer.
 */

defined( 'ABSPATH' ) || defined( 'NM_STANDALONE' ) || exit;

final class NM_Util {

	/** Prefix of every log line (Bible: logs with a fixed prefix). */
	const LOG_PREFIX = '[Data Migrator]';

	/** First line of every state file: the file never runs as PHP. */
	const GUARD = "<?php exit; ?>\n";

	/** 'el' | 'en' outside WordPress (runner, installer); null = use __(). */
	public static $lang = null;

	/** Current log file (absolute path) or ''. */
	public static $log_file = '';

	/* ---------------------------------------------------------------
	 * Translation
	 * ------------------------------------------------------------- */

	/** Greek msgid → current language. */
	public static function t( string $msgid ): string {
		if ( null === self::$lang && function_exists( '__' ) ) {
			return __( $msgid, 'data-migrator' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- msgids pass through here from the engine.
		}
		if ( 'en' === self::$lang && class_exists( 'NM_Lang' ) ) {
			return NM_Lang::en( $msgid );
		}
		return $msgid;
	}

	/** sprintf over t(). */
	public static function tf( string $msgid, ...$args ): string {
		return vsprintf( self::t( $msgid ), $args );
	}

	/* ---------------------------------------------------------------
	 * Random values
	 * ------------------------------------------------------------- */

	public static function hex( int $bytes = 16 ): string {
		return bin2hex( random_bytes( $bytes ) );
	}

	/* ---------------------------------------------------------------
	 * State files: "<?php exit; ?>\n" + JSON, written atomically.
	 * ------------------------------------------------------------- */

	public static function write_state( string $file, array $data ): bool {
		$json = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $json ) {
			return false;
		}
		return self::write_atomic( $file, self::GUARD . $json );
	}

	/** @return array|null */
	public static function read_state( string $file ) {
		if ( ! is_file( $file ) ) {
			return null;
		}
		$raw = (string) @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( 0 === strpos( $raw, self::GUARD ) ) {
			$raw = substr( $raw, strlen( self::GUARD ) );
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/** Write a file through a temporary name and rename(). */
	public static function write_atomic( string $file, string $bytes ): bool {
		$tmp = $file . '.' . self::hex( 4 ) . '.tmp';
		if ( false === @file_put_contents( $tmp, $bytes, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return false;
		}
		if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return false;
		}
		return true;
	}

	/* ---------------------------------------------------------------
	 * Locks
	 * ------------------------------------------------------------- */

	/** @return resource|false An exclusive, non-blocking lock on $file. */
	public static function lock( string $file ) {
		$fh = @fopen( $file, 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return false;
		}
		if ( ! flock( $fh, LOCK_EX | LOCK_NB ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}
		return $fh;
	}

	/** @param resource|false $fh */
	public static function unlock( $fh ): void {
		if ( $fh ) {
			flock( $fh, LOCK_UN );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/* ---------------------------------------------------------------
	 * Paths
	 * ------------------------------------------------------------- */

	/** Forward slashes, no trailing slash (except the root "/"). */
	public static function norm( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		$path = preg_replace( '#/+#', '/', $path );
		if ( strlen( $path ) > 1 ) {
			$path = rtrim( $path, '/' );
		}
		return $path;
	}

	/** True when $path is $base or lies inside it (both normalized). */
	public static function inside( string $path, string $base ): bool {
		$path = self::norm( $path );
		$base = self::norm( $base );
		return $path === $base || 0 === strpos( $path, rtrim( $base, '/' ) . '/' );
	}

	/**
	 * A relative path from an archive is safe to join to a folder: no
	 * "..", no absolute path, no drive letter, no NUL, no empty parts.
	 */
	public static function safe_rel( string $rel ): bool {
		if ( '' === $rel || false !== strpos( $rel, "\0" ) || false !== strpos( $rel, '\\' ) ) {
			return false;
		}
		if ( '/' === $rel[0] || preg_match( '#^[A-Za-z]:#', $rel ) ) {
			return false;
		}
		foreach ( explode( '/', $rel ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return false;
			}
		}
		return true;
	}

	/** mkdir -p with 0755; true when the folder exists afterwards. */
	public static function mkdirp( string $dir ): bool {
		if ( is_dir( $dir ) ) {
			return true;
		}
		@mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		return is_dir( $dir );
	}

	/**
	 * Delete a folder tree. Refuses anything outside $guard (an absolute
	 * folder that must contain $dir), and never follows symlinks.
	 */
	public static function rrmdir( string $dir, string $guard ): bool {
		$dir = self::norm( $dir );
		if ( '' === $guard || $dir === self::norm( $guard ) || ! self::inside( $dir, $guard ) ) {
			return false;
		}
		if ( is_link( $dir ) || is_file( $dir ) ) {
			return @unlink( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		if ( ! is_dir( $dir ) ) {
			return true;
		}
		$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		foreach ( (array) $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			self::rrmdir( $dir . '/' . $item, $guard );
		}
		return @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/** Move a file; falls back to copy + unlink across filesystems. */
	public static function move( string $from, string $to ): bool {
		if ( @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			return true;
		}
		if ( is_file( $from ) && @copy( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return true;
		}
		return false;
	}

	/* ---------------------------------------------------------------
	 * Time budget
	 * ------------------------------------------------------------- */

	/** Seconds one request may work: 20, or half of max_execution_time. */
	public static function budget(): float {
		// NM_BUDGET in wp-config.php: seconds per request, for hosts whose
		// proxy cuts requests sooner than PHP's own limit says.
		if ( defined( 'NM_BUDGET' ) && is_numeric( NM_BUDGET ) ) {
			return (float) max( 1, min( 60, (float) NM_BUDGET ) );
		}
		if ( 'cli' === PHP_SAPI ) {
			return 3600.0;
		}
		$max = (int) ini_get( 'max_execution_time' );
		$b   = 20.0;
		if ( $max > 0 ) {
			$b = min( $b, max( 3.0, $max / 2 ) );
		}
		return $b;
	}

	/** Raise the time limit where the host allows it. */
	public static function prepare_request(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/* ---------------------------------------------------------------
	 * Logging
	 * ------------------------------------------------------------- */

	public static function log( string $msg, bool $error = false ): void {
		$line = self::LOG_PREFIX . ' ' . gmdate( 'Y-m-d H:i:s' ) . ' ' . ( $error ? 'ERROR ' : '' ) . $msg;
		if ( '' !== self::$log_file ) {
			@file_put_contents( self::$log_file, $line . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( $error ) {
			error_log( self::LOG_PREFIX . ' ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/* ---------------------------------------------------------------
	 * Formatting
	 * ------------------------------------------------------------- */

	public static function size( float $bytes ): string {
		$u = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i = 0;
		while ( $bytes >= 1024 && $i < 4 ) {
			$bytes /= 1024;
			++$i;
		}
		return ( $i ? number_format( $bytes, 1, '.', '' ) : (string) (int) $bytes ) . ' ' . $u[ $i ];
	}

	/** php.ini size ("128M") in bytes; -1 = unlimited. */
	public static function ini_bytes( string $v ): int {
		$v = trim( $v );
		if ( '' === $v || '-1' === $v ) {
			return -1;
		}
		$n    = (int) $v;
		$unit = strtolower( substr( $v, -1 ) );
		if ( 'g' === $unit ) {
			$n *= 1024 * 1024 * 1024;
		} elseif ( 'm' === $unit ) {
			$n *= 1024 * 1024;
		} elseif ( 'k' === $unit ) {
			$n *= 1024;
		}
		return $n;
	}

	/** Free disk space in bytes, or -1 when the host hides it. */
	public static function free_space( string $dir ): float {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return -1;
		}
		$f = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false === $f ? -1 : (float) $f;
	}

	/** JSON for files and responses (unicode and slashes as they are). */
	public static function json( $data ): string {
		$out = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		return false === $out ? '{}' : $out;
	}

	/** Host of a URL, lower case, without "www.". */
	public static function bare_host( string $url ): string {
		$h = strtolower( (string) parse_url( $url, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return 0 === strpos( $h, 'www.' ) ? substr( $h, 4 ) : $h;
	}
}

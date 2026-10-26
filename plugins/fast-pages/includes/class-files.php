<?php
/**
 * FPG_Files — the cache folder: writing, deleting, counting.
 *
 * Folder: wp-content/cache/fast-pages/ (Bible §12 deviation: the drop-in
 * finds it before WordPress loads, so it cannot live under uploads; this
 * is where WordPress caches usually live). Inside:
 *  config.php           the drop-in's config (FPG_Setup::write_config)
 *  index.php            empty, no file listing
 *  <host>/<2 hex>/<32 hex>[-m].html   cached pages (FPG_Key)
 *  min/<32 hex>.css|.js minified copies; <32 hex>.fail = could not minify
 *
 * Every delete goes through the patterns first, so nothing outside them is
 * ever touched. Writes go to a .tmp file and are renamed at the end.
 */

defined( 'ABSPATH' ) || exit;

final class FPG_Files {

	const HOST_DIR = '/^[a-z0-9._-]+$/';
	const SUB_DIR  = '/^[0-9a-f]{2}$/';
	const MIN_FILE = '/^[0-9a-f]{32}\.(css|js|fail)$/';

	public static function dir(): string {
		return WP_CONTENT_DIR . '/cache/fast-pages';
	}

	public static function min_dir(): string {
		return self::dir() . '/min';
	}

	public static function min_url(): string {
		return content_url( 'cache/fast-pages/min' );
	}

	/** Creates the folder with an empty index.php. */
	public static function ensure(): bool {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- best effort.
		}
		return is_dir( $dir ) && wp_is_writable( $dir );
	}

	/** The folder can be written (or created), without creating it. */
	public static function writable(): bool {
		foreach ( array( self::dir(), dirname( self::dir() ), WP_CONTENT_DIR ) as $d ) {
			if ( is_dir( $d ) ) {
				return wp_is_writable( $d );
			}
		}
		return false;
	}

	/** Writes a file through a .tmp file and a rename. */
	public static function write( string $file, string $content ): bool {
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$tmp = $file . '.' . wp_generate_password( 8, false ) . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions -- the drop-in reads these files without WordPress.
		if ( false === @file_put_contents( $tmp, $content, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- reported by the caller.
			return false;
		}
		if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return false;
		}
		// The drop-in and its config are PHP: OPcache must not keep the old
		// copy (with validate_timestamps off it would keep it for good).
		if ( '.php' === substr( $file, -4 ) && function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $file, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return true;
	}

	/** Deletes one cached page file (only inside the cache, by pattern). */
	public static function delete_page( string $file ): bool {
		if ( ! preg_match( FPG_Key::PATTERN, basename( $file ) ) ) {
			return false;
		}
		$sub  = dirname( $file );
		$host = dirname( $sub );
		if ( ! preg_match( self::SUB_DIR, basename( $sub ) ) || ! preg_match( self::HOST_DIR, basename( $host ) ) || dirname( $host ) !== self::dir() ) {
			return false;
		}
		return is_file( $file ) && @unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	}

	/** Host folders inside the cache. */
	private static function hosts(): array {
		$out = array();
		foreach ( (array) @scandir( self::dir() ) as $name ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a missing folder is empty.
			$name = (string) $name;
			if ( '' !== $name && '.' !== $name[0] && 'min' !== $name && preg_match( self::HOST_DIR, $name ) && is_dir( self::dir() . '/' . $name ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Walks every cached page: $fn( string $file, int $mtime, int $size ).
	 * Returns array( pages, bytes ).
	 */
	private static function walk( callable $fn = null ): array {
		$pages = 0;
		$bytes = 0;
		foreach ( self::hosts() as $host ) {
			$hdir = self::dir() . '/' . $host;
			foreach ( (array) @scandir( $hdir ) as $sub ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$sub = (string) $sub;
				if ( ! preg_match( self::SUB_DIR, $sub ) ) {
					continue;
				}
				foreach ( (array) @scandir( $hdir . '/' . $sub ) as $f ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					$f = (string) $f;
					if ( ! preg_match( FPG_Key::PATTERN, $f ) ) {
						continue;
					}
					$path = $hdir . '/' . $sub . '/' . $f;
					$size = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					$time = (int) @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					++$pages;
					$bytes += $size;
					if ( $fn ) {
						$fn( $path, $time, $size );
					}
				}
			}
		}
		return array( $pages, $bytes );
	}

	/** Number of cached pages and their size. */
	public static function stats(): array {
		list( $pages, $bytes ) = self::walk();
		$min                   = 0;
		foreach ( (array) @scandir( self::min_dir() ) as $f ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( preg_match( self::MIN_FILE, (string) $f ) && '.fail' !== substr( (string) $f, -5 ) ) {
				++$min;
			}
		}
		return array(
			'pages' => $pages,
			'bytes' => $bytes,
			'min'   => $min,
		);
	}

	/** Deletes every cached page (and, with $min, the minified files). */
	public static function purge_all( bool $min = true ): int {
		$n = 0;
		self::walk(
			static function ( $path ) use ( &$n ) {
				if ( @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
					++$n;
				}
			}
		);
		foreach ( self::hosts() as $host ) {
			$hdir = self::dir() . '/' . $host;
			foreach ( (array) @scandir( $hdir ) as $sub ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( preg_match( self::SUB_DIR, (string) $sub ) ) {
					self::remove_tmp( $hdir . '/' . $sub );
					@rmdir( $hdir . '/' . $sub ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- only when empty.
				}
			}
			@rmdir( $hdir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
		if ( $min ) {
			self::purge_min();
		}
		return $n;
	}

	/** Deletes the minified copies (and the "could not minify" marks). */
	public static function purge_min(): void {
		foreach ( (array) @scandir( self::min_dir() ) as $f ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( preg_match( self::MIN_FILE, (string) $f ) ) {
				@unlink( self::min_dir() . '/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			}
		}
		self::remove_tmp( self::min_dir() );
	}

	/** Deletes pages older than $seconds. Returns how many. */
	public static function purge_older( int $seconds ): int {
		if ( $seconds <= 0 ) {
			return 0;
		}
		$limit = time() - $seconds;
		$n     = 0;
		self::walk(
			static function ( $path, $time ) use ( $limit, &$n ) {
				if ( $time < $limit && @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
					++$n;
				}
			}
		);
		return $n;
	}

	/** Leftover .tmp files older than a minute (a write that died). */
	private static function remove_tmp( string $dir ): void {
		foreach ( (array) @scandir( $dir ) as $f ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$f = (string) $f;
			if ( preg_match( '/^[0-9a-f]{32}(-m)?\.(html|css|js)\.[A-Za-z0-9]{8}\.tmp$/', $f ) && (int) @filemtime( $dir . '/' . $f ) < time() - 60 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				@unlink( $dir . '/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			}
		}
	}

	/**
	 * Removes everything of ours (uninstall, deactivation with "delete the
	 * cache"): pages, minified files, config and index.php, then the
	 * folders if they are empty.
	 */
	public static function remove_all(): void {
		self::purge_all( true );
		@rmdir( self::min_dir() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		foreach ( array( 'config.php', 'index.php' ) as $f ) {
			if ( is_file( self::dir() . '/' . $f ) ) {
				@unlink( self::dir() . '/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			}
		}
		@rmdir( self::dir() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- only when empty.
	}
}

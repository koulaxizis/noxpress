<?php
/**
 * TP_Theme — fixes for what a theme does behind the page.
 *
 *  - Guard: page views cannot write the theme's settings (theme_mods).
 *    Some themes call set_theme_mod() inside templates — on every view
 *    they write to the database and overwrite what was chosen in the
 *    Customizer (e.g. a fixed banner image or slider speed). With the
 *    guard on, such a write on a front-end request is cancelled through
 *    `pre_update_option_theme_mods_{stylesheet}` (the old value is kept,
 *    so update_option() writes nothing). The Customizer, admin, AJAX and
 *    REST requests are never blocked.
 *  - Overrides: chosen theme settings get a fixed value on the front end
 *    through the `theme_mod_{name}` filter (e.g. a slider delay the theme
 *    resets in its template). The stored value is not changed.
 *  - Local files: themes that read their own files over HTTP
 *    (file_get_contents( get_template_directory_uri() . '/x.svg' )) make
 *    the site call itself on every view. Only on such source lines, the
 *    directory URI is replaced by the directory path, so the file is
 *    read from disk. The caller line is found with a short backtrace and
 *    checked once per file:line.
 *
 * Nothing here runs in the Customizer preview.
 */

defined( 'ABSPATH' ) || exit;

final class TP_Theme {

	/** Writes blocked this request: setting name => count. */
	private static $blocked = array();

	/** Local reads this request. */
	private static $local = 0;

	/** "file:line" => bool (line reads a theme file over HTTP). */
	private static $lines = array();

	public static function init(): void {
		// Priority 1: before templates run, after the query and the user are known.
		add_action( 'wp', array( __CLASS__, 'boot' ), 1 );
	}

	public static function boot(): void {
		try {
			if ( ! TP_Runtime::request_ok() || is_customize_preview() || ! TP_Runtime::applies() ) {
				return;
			}
			$t = TP_Settings::theme();

			if ( $t['guard'] ) {
				$opt = 'theme_mods_' . get_option( 'stylesheet' );
				add_filter( 'pre_update_option_' . $opt, array( __CLASS__, 'guard' ), 999, 2 );
			}

			foreach ( $t['mods'] as $name => $value ) {
				add_filter(
					'theme_mod_' . $name,
					static function () use ( $value ) {
						return $value;
					},
					999
				);
			}

			if ( $t['local_files'] ) {
				add_filter( 'template_directory_uri', array( __CLASS__, 'local_uri' ), 999, 2 );
				add_filter( 'stylesheet_directory_uri', array( __CLASS__, 'local_uri' ), 999, 2 );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Keep the stored theme settings (update_option() then writes nothing).
	 *
	 * @param mixed $value     New value.
	 * @param mixed $old_value Stored value.
	 */
	public static function guard( $value, $old_value ) {
		try {
			if ( is_array( $value ) ) {
				$old = is_array( $old_value ) ? $old_value : array();
				foreach ( $value as $name => $v ) {
					if ( ! array_key_exists( $name, $old ) || $old[ $name ] !== $v ) {
						$name                   = (string) $name;
						self::$blocked[ $name ] = isset( self::$blocked[ $name ] ) ? self::$blocked[ $name ] + 1 : 1;
					}
				}
			}
		} catch ( \Throwable $e ) {
			return $old_value;
		}
		return $old_value;
	}

	/**
	 * Directory path instead of URI, only for a theme line that reads the
	 * theme directory with file_get_contents().
	 *
	 * @param string $uri      Directory URI.
	 * @param string $template Theme directory name.
	 */
	public static function local_uri( $uri, $template = '' ) {
		if ( ! is_string( $uri ) ) {
			return $uri;
		}
		try {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- short, no args.
			$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 8 );
			foreach ( $trace as $frame ) {
				$fn = isset( $frame['function'] ) ? $frame['function'] : '';
				if ( 'get_template_directory_uri' !== $fn && 'get_stylesheet_directory_uri' !== $fn ) {
					continue;
				}
				if ( empty( $frame['file'] ) || empty( $frame['line'] ) || ! self::reads_file( $frame['file'], (int) $frame['line'] ) ) {
					return $uri;
				}
				++self::$local;
				return 'get_template_directory_uri' === $fn ? get_template_directory() : get_stylesheet_directory();
			}
		} catch ( \Throwable $e ) {
			return $uri;
		}
		return $uri;
	}

	/** True when line $line of theme file $file is file_get_contents( get_*_directory_uri() … ). */
	private static function reads_file( string $file, int $line ): bool {
		$key = $file . ':' . $line;
		if ( isset( self::$lines[ $key ] ) ) {
			return self::$lines[ $key ];
		}
		$hit = false;
		if ( '' !== TP_Areas::theme_relative( $file ) && is_readable( $file ) ) {
			$src = file( $file, FILE_IGNORE_NEW_LINES );
			if ( is_array( $src ) && isset( $src[ $line - 1 ] ) ) {
				$hit = 1 === preg_match( '/file_get_contents\s*\(\s*get_(template|stylesheet)_directory_uri\s*\(/i', $src[ $line - 1 ] );
			}
		}
		self::$lines[ $key ] = $hit;
		return $hit;
	}

	/** Counters for the page probe. */
	public static function stats(): array {
		return array(
			'blocked' => self::$blocked,
			'local'   => self::$local,
		);
	}

	/**
	 * Theme files that write theme settings or read theme files over HTTP
	 * (admin scan): theme-relative path => [ 'writes' => names, 'http' => n ].
	 */
	public static function scan(): array {
		$out = array();
		foreach ( TP_Detector::theme_php_files() as $rel => $abs ) {
			$src = (string) file_get_contents( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
			$writes = array();
			if ( preg_match_all( '/\bset_theme_mod\s*\(\s*[\'"]([A-Za-z0-9_\-]+)[\'"]/', $src, $m ) ) {
				$writes = array_values( array_unique( $m[1] ) );
			}
			$http = (int) preg_match_all( '/file_get_contents\s*\(\s*get_(template|stylesheet)_directory_uri\s*\(/i', $src );
			if ( $writes || $http ) {
				$out[ $rel ] = array(
					'writes' => $writes,
					'http'   => $http,
				);
			}
		}
		return $out;
	}
}

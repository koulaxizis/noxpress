<?php
/**
 * SSG_Static — static llms.txt / llms-full.txt files in the web root.
 *
 * The web server sends a file that exists before WordPress runs, so a
 * static llms.txt hides the one of the llms module (and may hold personal
 * data, like the admin e-mail an old generator printed). On a manager's
 * click only (never automatically):
 *  - move(): the two files, and only those names, from the WordPress root
 *    (ABSPATH) and the site root (get_home_path(), when it differs) to a
 *    private backup folder in uploads, noxpress-search-signals-<12 hex>/
 *    (index.php, .htaccess, web.config: Bible §12), as
 *    <name>.<YYYYmmdd-His>.bak;
 *  - restore(): one backup back to where it was, only if no file is there;
 *  - check(): GET /llms.txt and /llms-full.txt of this site (loopback)
 *    and say who answers.
 * The list of backups is the option ssg_static.
 */

defined( 'ABSPATH' ) || exit;

final class SSG_Static {

	const NAMES = array( 'llms.txt', 'llms-full.txt' );
	const OPT   = 'ssg_static';
	const STATE = 'ssg_state';

	/** Folders where the web server could find the files. */
	public static function roots(): array {
		$out = array( wp_normalize_path( untrailingslashit( ABSPATH ) ) );
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$home = wp_normalize_path( untrailingslashit( (string) get_home_path() ) );
		if ( '' !== $home && ! in_array( $home, $out, true ) ) {
			$out[] = $home;
		}
		return $out;
	}

	/** The static files that exist: [ name, root, size, time, email ]. */
	public static function found(): array {
		$out = array();
		foreach ( self::roots() as $root ) {
			foreach ( self::NAMES as $name ) {
				$path = $root . '/' . $name;
				if ( is_file( $path ) && ! is_link( $path ) ) {
					$head  = (string) @file_get_contents( $path, false, null, 0, 65536 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
					$out[] = array(
						'name'  => $name,
						'root'  => $root,
						'size'  => (int) filesize( $path ),
						'time'  => (int) filemtime( $path ),
						'email' => (bool) preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $head ),
					);
				}
			}
		}
		return $out;
	}

	/** Backups that can be restored. */
	public static function backups(): array {
		$list = get_option( self::OPT, array() );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/** The private backup folder (made on first use); '' if uploads is not writable. */
	public static function dir(): string {
		$st = get_option( self::STATE, array() );
		$st = is_array( $st ) ? $st : array();
		if ( empty( $st['dir'] ) || ! preg_match( '/^noxpress-search-signals-[a-f0-9]{12}$/', (string) $st['dir'] ) ) {
			$st['dir'] = 'noxpress-search-signals-' . substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
			update_option( self::STATE, $st, false );
		}
		$up  = wp_upload_dir( null, false );
		$dir = rtrim( wp_normalize_path( (string) $up['basedir'] ), '/' ) . '/' . $st['dir'];
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		$guards = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $guards as $f => $body ) {
			if ( ! file_exists( $dir . '/' . $f ) ) {
				@file_put_contents( $dir . '/' . $f, $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- guard file.
			}
		}
		return $dir;
	}

	/**
	 * Moves every static file found to the backup folder.
	 *
	 * @return array [ moved => string[], failed => string[] ]
	 */
	public static function move(): array {
		$res = array(
			'moved'  => array(),
			'failed' => array(),
		);
		$dir = self::dir();
		$all = self::backups();
		foreach ( self::found() as $f ) {
			$src = $f['root'] . '/' . $f['name'];
			if ( '' === $dir ) {
				$res['failed'][] = $src;
				continue;
			}
			$file = $f['name'] . '.' . gmdate( 'Ymd-His' ) . '.bak';
			$i    = 1;
			while ( file_exists( $dir . '/' . $file ) ) {
				$file = $f['name'] . '.' . gmdate( 'Ymd-His' ) . '-' . ( ++$i ) . '.bak';
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- move inside this site.
			if ( @rename( $src, $dir . '/' . $file ) ) {
				$all[]          = array(
					'name' => $f['name'],
					'root' => $f['root'],
					'file' => $file,
					'time' => time(),
					'size' => $f['size'],
				);
				$res['moved'][] = $src;
			} else {
				$res['failed'][] = $src;
			}
		}
		update_option( self::OPT, array_slice( $all, -20 ), false );
		return $res;
	}

	/** Puts one backup back. Returns '' on success or the reason. */
	public static function restore( int $index ): string {
		$all = self::backups();
		if ( ! isset( $all[ $index ] ) ) {
			return 'missing';
		}
		$b    = $all[ $index ];
		$dir  = self::dir();
		$name = (string) $b['name'];
		$file = (string) $b['file'];
		$root = wp_normalize_path( (string) $b['root'] );
		if ( '' === $dir || ! in_array( $name, self::NAMES, true ) || ! in_array( $root, self::roots(), true ) || ! preg_match( '/^llms(-full)?\.txt\.[0-9]{8}-[0-9]{6}(-[0-9]+)?\.bak$/', $file ) ) {
			return 'invalid';
		}
		if ( file_exists( $root . '/' . $name ) ) {
			return 'exists';
		}
		if ( ! is_file( $dir . '/' . $file ) ) {
			return 'missing';
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- move inside this site.
		if ( ! @rename( $dir . '/' . $file, $root . '/' . $name ) ) {
			return 'failed';
		}
		unset( $all[ $index ] );
		update_option( self::OPT, array_values( $all ), false );
		return '';
	}

	/**
	 * Who answers /llms.txt and /llms-full.txt now (loopback, no cookies).
	 *
	 * @return array name => [ code, who: ours | other | none | error ]
	 */
	public static function check(): array {
		$out = array();
		foreach ( self::NAMES as $name ) {
			$r = wp_safe_remote_get(
				add_query_arg( 'ssg', (string) time(), home_url( '/' . $name ) ),
				array(
					'timeout'     => 10,
					'redirection' => 0,
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);
			if ( is_wp_error( $r ) ) {
				$out[ $name ] = array( 0, 'error' );
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			$ours = 'llms' === strtolower( (string) wp_remote_retrieve_header( $r, 'x-search-signals' ) );
			$out[ $name ] = array( $code, $ours ? 'ours' : ( 200 === $code ? 'other' : 'none' ) );
		}
		return $out;
	}
}

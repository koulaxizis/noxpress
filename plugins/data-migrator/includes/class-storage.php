<?php
/**
 * NM_Storage — the private folder for backups, jobs, work files, logs.
 *
 * Where (chosen once, kept in nm_settings['storage']):
 *  1. A custom path from DM Settings, if it is writable.
 *  2. Next to the web root (outside DOCUMENT_ROOT), when WordPress lives
 *     inside DOCUMENT_ROOT and the parent folder is writable.
 *  3. wp-content/uploads/noxpress-data-migrator-<12 hex>/.
 *
 * Every folder gets an empty index.php, a deny .htaccess and a deny
 * web.config. The .htaccess is a deliberate exception to Bible §12: if
 * the host refuses the directive the folder answers 500, which for a
 * folder of backups is as good as a deny. A self-test fetches a probe
 * file over HTTP and warns when the folder can be downloaded (nginx
 * ignores .htaccess).
 *
 * Backup files: <host>-<YYYYMMDD-HHMMSS>-<id>[-partN].zip, <id> = 32 hex;
 * metadata in backups/<id>.php (guarded JSON). Every read, download or
 * delete passes the patterns below first.
 */

defined( 'ABSPATH' ) || exit;

final class NM_Storage {

	const ID_RE   = '/^[a-f0-9]{32}$/';
	const FILE_RE = '/^[a-z0-9.-]{1,80}-\d{8}-\d{6}-[a-f0-9]{32}(-part\d{1,3})?\.zip$/';
	const JOB_RE  = '/^[a-z]-[a-f0-9]{32}$/';

	/** Per-request cache. */
	private static $dir = null;

	/** The storage folder (absolute, no trailing slash), created if needed; '' on failure. */
	public static function dir(): string {
		if ( null !== self::$dir ) {
			return self::$dir;
		}
		$set = NM_Settings::get();
		$cur = (string) $set['storage'];
		if ( '' !== $cur && is_dir( $cur ) && wp_is_writable( $cur ) ) {
			self::protect( $cur );
			self::$dir = NM_Util::norm( $cur );
			return self::$dir;
		}
		foreach ( self::candidates() as $cand ) {
			if ( NM_Util::mkdirp( $cand ) && wp_is_writable( $cand ) ) {
				self::protect( $cand );
				$set['storage'] = NM_Util::norm( $cand );
				NM_Settings::save( $set );
				self::$dir = $set['storage'];
				NM_Util::log( 'storage folder: ' . self::$dir );
				return self::$dir;
			}
		}
		self::$dir = '';
		return '';
	}

	public static function reset_cache(): void {
		self::$dir = null;
	}

	/** Places to try, best first. */
	private static function candidates(): array {
		$name = 'noxpress-data-migrator-' . NM_Util::hex( 6 );
		$out  = array();
		$doc  = isset( $_SERVER['DOCUMENT_ROOT'] ) ? NM_Util::norm( (string) wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a path compared, never printed.
		$abs  = NM_Util::norm( ABSPATH );
		if ( '' !== $doc && '/' !== $doc && NM_Util::inside( $abs, $doc ) ) {
			$parent = dirname( $doc );
			if ( '' !== $parent && '/' !== $parent && self::allowed( $parent ) && @is_writable( $parent ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
				$out[] = $parent . '/' . $name;
			}
		}
		$up = wp_upload_dir( null, false );
		if ( empty( $up['error'] ) && ! empty( $up['basedir'] ) ) {
			$out[] = NM_Util::norm( $up['basedir'] ) . '/' . $name;
		}
		return $out;
	}

	/** open_basedir allows $path. */
	private static function allowed( string $path ): bool {
		$ob = (string) ini_get( 'open_basedir' );
		if ( '' === $ob ) {
			return true;
		}
		foreach ( explode( PATH_SEPARATOR, $ob ) as $b ) {
			if ( '' !== $b && NM_Util::inside( $path, $b ) ) {
				return true;
			}
		}
		return false;
	}

	/** True when the folder is under the web root (needs the HTTP test). */
	public static function in_webroot( string $dir ): bool {
		$doc = isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a path compared, never printed.
		if ( '' !== $doc && NM_Util::inside( $dir, $doc ) ) {
			return true;
		}
		return NM_Util::inside( $dir, ABSPATH ) || NM_Util::inside( $dir, WP_CONTENT_DIR );
	}

	/** index.php, .htaccess and web.config in $dir. */
	public static function protect( string $dir ): void {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "# Data Migrator: private folder.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $body ) {
			if ( ! is_file( $dir . '/' . $name ) ) {
				@file_put_contents( $dir . '/' . $name, $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/** A sub folder (backups, jobs, work, logs, incoming), created and protected. */
	public static function sub( string $name ): string {
		$base = self::dir();
		if ( '' === $base || ! in_array( $name, array( 'backups', 'jobs', 'work', 'logs', 'incoming' ), true ) ) {
			return '';
		}
		$d = $base . '/' . $name;
		if ( NM_Util::mkdirp( $d ) ) {
			self::protect( $d );
			return $d;
		}
		return '';
	}

	/**
	 * HTTP self-test: can a file in the folder be downloaded?
	 * Returns 'safe', 'exposed', 'outside' (not under the web root) or
	 * 'unknown' (the site did not answer). Saved in nm_state.
	 */
	public static function web_test(): string {
		$dir = self::dir();
		if ( '' === $dir ) {
			return 'unknown';
		}
		if ( ! self::in_webroot( $dir ) ) {
			$res = 'outside';
		} else {
			$url = self::url_of( $dir );
			$res = 'unknown';
			if ( '' !== $url ) {
				$name  = 'probe-' . NM_Util::hex( 8 ) . '.txt';
				$token = NM_Util::hex( 8 );
				@file_put_contents( $dir . '/' . $name, $token ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$r = wp_remote_get(
					$url . '/' . $name,
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
		}
		$st             = get_option( 'nm_state', array() );
		$st             = is_array( $st ) ? $st : array();
		$st['web']      = $res;
		$st['web_time'] = time();
		update_option( 'nm_state', $st, false );
		return $res;
	}

	/** URL of a folder under the site (uploads, content or ABSPATH), or ''. */
	private static function url_of( string $dir ): string {
		$up = wp_upload_dir( null, false );
		$map = array(
			NM_Util::norm( (string) $up['basedir'] ) => (string) $up['baseurl'],
			NM_Util::norm( WP_CONTENT_DIR )          => content_url(),
			NM_Util::norm( ABSPATH )                 => site_url(),
		);
		foreach ( $map as $base => $url ) {
			if ( '' !== $base && NM_Util::inside( $dir, $base ) ) {
				return rtrim( $url, '/' ) . substr( NM_Util::norm( $dir ), strlen( $base ) );
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------
	 * Backups
	 * ------------------------------------------------------------- */

	public static function meta_path( string $id ): string {
		$d = self::sub( 'backups' );
		return ( '' === $d || ! preg_match( self::ID_RE, $id ) ) ? '' : $d . '/' . $id . '.php';
	}

	/** @return array|null */
	public static function meta( string $id ) {
		$p = self::meta_path( $id );
		return '' === $p ? null : NM_Util::read_state( $p );
	}

	public static function save_meta( array $meta ): bool {
		$p = self::meta_path( (string) $meta['id'] );
		return '' !== $p && NM_Util::write_state( $p, $meta );
	}

	/** Absolute path of a backup part, '' when the name fails the pattern. */
	public static function part_path( string $file ): string {
		$d = self::sub( 'backups' );
		return ( '' === $d || ! preg_match( self::FILE_RE, $file ) ) ? '' : $d . '/' . $file;
	}

	/** Every backup, newest first. */
	public static function all(): array {
		$d = self::sub( 'backups' );
		if ( '' === $d ) {
			return array();
		}
		$out = array();
		foreach ( (array) glob( $d . '/*.php' ) as $f ) {
			$id = basename( (string) $f, '.php' );
			if ( ! preg_match( self::ID_RE, $id ) ) {
				continue;
			}
			$m = NM_Util::read_state( (string) $f );
			if ( is_array( $m ) && isset( $m['id'] ) && $m['id'] === $id ) {
				$out[] = $m;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return (int) $b['created'] - (int) $a['created'];
			}
		);
		return $out;
	}

	/** Delete a backup: its parts (pattern checked) and metadata. */
	public static function delete( string $id ): bool {
		$m = self::meta( $id );
		if ( ! $m ) {
			return false;
		}
		foreach ( (array) ( $m['parts'] ?? array() ) as $p ) {
			$path = self::part_path( (string) ( $p['file'] ?? '' ) );
			if ( '' !== $path && false !== strpos( $path, $id ) && is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		// A backup that never finished may have a part not yet in the list.
		foreach ( (array) glob( self::sub( 'backups' ) . '/*-' . $id . '*.zip' ) as $f ) {
			if ( preg_match( self::FILE_RE, basename( (string) $f ) ) ) {
				@unlink( (string) $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		NM_Util::rrmdir( self::sub( 'work' ) . '/' . $id, self::sub( 'work' ) );
		@unlink( self::meta_path( $id ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		return true;
	}

	/* ---------------------------------------------------------------
	 * Jobs
	 * ------------------------------------------------------------- */

	public static function job_path( string $job ): string {
		$d = self::sub( 'jobs' );
		return ( '' === $d || ! preg_match( self::JOB_RE, $job ) ) ? '' : $d . '/' . $job . '.php';
	}

	/** @return array|null */
	public static function job( string $job ) {
		$p = self::job_path( $job );
		return '' === $p ? null : NM_Util::read_state( $p );
	}

	public static function save_job( array $job ): bool {
		$p = self::job_path( (string) $job['id'] );
		return '' !== $p && NM_Util::write_state( $p, $job );
	}

	public static function log_file( string $job ): string {
		$d = self::sub( 'logs' );
		return ( '' === $d || ! preg_match( self::JOB_RE, $job ) ) ? '' : $d . '/' . $job . '.log';
	}

	/** Bytes used by the storage folder (backups only). */
	public static function used(): float {
		$t = 0.0;
		foreach ( (array) glob( self::sub( 'backups' ) . '/*.zip' ) as $f ) {
			$t += (float) @filesize( (string) $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return $t;
	}
}

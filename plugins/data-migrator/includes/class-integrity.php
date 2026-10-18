<?php
/**
 * NM_Integrity — WordPress core files against the official checksums.
 *
 * One request to api.wordpress.org/core/checksums/1.0/ (the only
 * external call of the plugin, approved), cached for an hour. Reports
 * changed and missing core files, and unknown files inside wp-admin/ and
 * wp-includes/ (a common hiding place for malware). wp-content is not
 * checked: plugins and themes are not part of core.
 */

defined( 'ABSPATH' ) || exit;

final class NM_Integrity {

	const CACHE = 'nm_integrity';

	/**
	 * @return array status (ok|changed|unknown), changed[], missing[], extra[], version, checked
	 */
	public static function check( bool $fresh = false ): array {
		global $wp_version;
		if ( ! $fresh ) {
			$c = get_transient( self::CACHE );
			if ( is_array( $c ) && ( $c['version'] ?? '' ) === $wp_version ) {
				return $c;
			}
		}
		$out  = array(
			'status'  => 'unknown',
			'changed' => array(),
			'missing' => array(),
			'extra'   => array(),
			'version' => $wp_version,
			'checked' => time(),
		);
		$sums = self::checksums( $wp_version, get_locale() );
		if ( ! $sums && 'en_US' !== get_locale() ) {
			$sums = self::checksums( $wp_version, 'en_US' );
		}
		if ( ! $sums ) {
			set_transient( self::CACHE, $out, 10 * MINUTE_IN_SECONDS );
			return $out;
		}
		$abs = trailingslashit( ABSPATH );
		foreach ( $sums as $file => $md5 ) {
			if ( 0 === strpos( $file, 'wp-content/' ) ) {
				continue;
			}
			if ( ! is_file( $abs . $file ) ) {
				$out['missing'][] = $file;
			} elseif ( md5_file( $abs . $file ) !== $md5 ) {
				$out['changed'][] = $file;
			}
		}
		// Files in wp-admin / wp-includes that core does not ship.
		foreach ( array( 'wp-admin', 'wp-includes' ) as $d ) {
			if ( ! is_dir( $abs . $d ) ) {
				continue;
			}
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $abs . $d, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				$rel = $d . '/' . str_replace( '\\', '/', substr( $f->getPathname(), strlen( $abs . $d ) + 1 ) );
				if ( ! isset( $sums[ $rel ] ) && count( $out['extra'] ) < 200 ) {
					$out['extra'][] = $rel;
				}
			}
		}
		$out['status'] = ( $out['changed'] || $out['missing'] || $out['extra'] ) ? 'changed' : 'ok';
		set_transient( self::CACHE, $out, HOUR_IN_SECONDS );
		return $out;
	}

	private static function checksums( string $version, string $locale ): array {
		$url = add_query_arg(
			array(
				'version' => $version,
				'locale'  => $locale,
			),
			'https://api.wordpress.org/core/checksums/1.0/'
		);
		$r   = wp_safe_remote_get( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
			return array();
		}
		$j = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		return isset( $j['checksums'] ) && is_array( $j['checksums'] ) ? $j['checksums'] : array();
	}

	/** Short form for the manifest. */
	public static function summary( array $c ): array {
		return array(
			'status'  => $c['status'],
			'changed' => array_slice( $c['changed'], 0, 50 ),
			'missing' => array_slice( $c['missing'], 0, 50 ),
			'extra'   => array_slice( $c['extra'], 0, 50 ),
			'checked' => $c['checked'],
		);
	}
}

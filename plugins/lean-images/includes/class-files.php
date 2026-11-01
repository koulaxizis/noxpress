<?php
/**
 * LIM_Files — the files of an attachment, their sidecars and our records.
 *
 * A sidecar is "<source file>.<format>" next to its source, e.g.
 * uploads/2026/10/cover-300x300.png.webp (the name Imagify uses too, so
 * its files are recognised). Sources are the files of the attachment's
 * metadata: the full file, the original before "-scaled" and every size.
 * Only JPEG and PNG are converted (GIF may be animated, WebP / AVIF are
 * already light).
 *
 * Records, per attachment:
 *  _lim        array( 'sig' => md5 of the metadata's files,
 *                     'dir' => folder relative to uploads,
 *                     'f'   => array( basename => array(
 *                                's'    => source bytes,
 *                                'webp' => sidecar bytes, 0 = not kept,
 *                                'avif' => sidecar bytes, 0 = not kept,
 *                                'imp'  => array( fmt => true ) adopted ) ),
 *                     'err' => last error, 't' => time )
 *  _lim_state  done | retry | failed (missing = waiting)
 *  _lim_tries  failed attempts so far
 *
 * Only what a record names is ever deleted, and only through sidecar(),
 * which refuses any path that is not "<file in uploads>.webp|.avif".
 */

defined( 'ABSPATH' ) || exit;

final class LIM_Files {

	const META  = '_lim';
	const STATE = '_lim_state';
	const TRIES = '_lim_tries';

	/** Formats we make. */
	const FORMATS = array( 'webp', 'avif' );

	/** Source mime types we convert. */
	const MIMES = array( 'image/jpeg', 'image/png' );

	/** Per-request cache of the uploads folder. */
	private static $up = null;

	/** Per-request is_file() answers for the front end. */
	private static $seen = array();

	/** array( basedir, baseurl, path of baseurl, host of baseurl ). */
	public static function uploads(): array {
		if ( null === self::$up ) {
			$u        = wp_get_upload_dir();
			$base     = isset( $u['basedir'] ) ? untrailingslashit( wp_normalize_path( (string) $u['basedir'] ) ) : '';
			$url      = isset( $u['baseurl'] ) ? untrailingslashit( (string) $u['baseurl'] ) : '';
			$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
			$host     = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			self::$up = array( $base, $url, untrailingslashit( $path ), $host );
		}
		return self::$up;
	}

	/** True for a file name we convert (by extension). */
	public static function convertible( string $file ): bool {
		return (bool) preg_match( '/\.(jpe?g|jpe|png)$/i', $file );
	}

	/**
	 * Absolute paths of the attachment's source files that exist, keyed by
	 * basename, from its metadata. Empty for anything but JPEG / PNG.
	 */
	public static function sources( int $id, $meta = null ): array {
		$meta = is_array( $meta ) ? $meta : wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) || empty( $meta['file'] ) || ! is_string( $meta['file'] ) ) {
			return array();
		}
		list( $base ) = self::uploads();
		if ( '' === $base ) {
			return array();
		}
		$rel  = ltrim( wp_normalize_path( $meta['file'] ), '/' );
		$dir  = dirname( $rel );
		$dir  = '.' === $dir ? '' : $dir;
		$list = array( wp_basename( $rel ) );
		if ( ! empty( $meta['original_image'] ) && is_string( $meta['original_image'] ) ) {
			$list[] = wp_basename( $meta['original_image'] );
		}
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
					$list[] = wp_basename( $size['file'] );
				}
			}
		}
		$out = array();
		foreach ( array_unique( $list ) as $name ) {
			if ( ! self::convertible( $name ) || false !== strpos( $name, '..' ) ) {
				continue;
			}
			$path = $base . '/' . ( '' === $dir ? '' : $dir . '/' ) . $name;
			if ( self::inside( $path ) && is_file( $path ) ) {
				$out[ $name ] = $path;
			}
		}
		return $out;
	}

	/** Folder of the attachment relative to uploads ('' = the root). */
	public static function rel_dir( int $id, $meta = null ): string {
		$meta = is_array( $meta ) ? $meta : wp_get_attachment_metadata( $id );
		if ( ! is_array( $meta ) || empty( $meta['file'] ) || ! is_string( $meta['file'] ) ) {
			return '';
		}
		$dir = dirname( ltrim( wp_normalize_path( $meta['file'] ), '/' ) );
		return '.' === $dir ? '' : $dir;
	}

	/** md5 of the files the metadata names (to see a regenerate or rename). */
	public static function signature( $meta ): string {
		if ( ! is_array( $meta ) ) {
			return '';
		}
		$parts = array( isset( $meta['file'] ) ? (string) $meta['file'] : '', isset( $meta['original_image'] ) ? (string) $meta['original_image'] : '' );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $name => $size ) {
				if ( is_array( $size ) ) {
					$parts[] = $name . ':' . ( isset( $size['file'] ) ? (string) $size['file'] : '' );
				}
			}
		}
		sort( $parts );
		return md5( implode( '|', $parts ) );
	}

	/** True when $path is a file path inside the uploads folder. */
	public static function inside( string $path ): bool {
		list( $base ) = self::uploads();
		$path         = wp_normalize_path( $path );
		return '' !== $base && 0 === strpos( $path, $base . '/' ) && false === strpos( $path, '/../' ) && false === strpos( $path, "\0" );
	}

	/**
	 * The sidecar of a source, or '' when the pair is not allowed: the
	 * source must be a JPEG / PNG inside uploads, the format webp | avif.
	 */
	public static function sidecar( string $src, string $fmt ): string {
		if ( ! in_array( $fmt, self::FORMATS, true ) || ! self::convertible( $src ) || ! self::inside( $src ) ) {
			return '';
		}
		return wp_normalize_path( $src ) . '.' . $fmt;
	}

	/** Deletes one sidecar (through sidecar(), never anything else). */
	public static function unlink_sidecar( string $src, string $fmt ): bool {
		$side = self::sidecar( $src, $fmt );
		if ( '' === $side || ! is_file( $side ) ) {
			return false;
		}
		return @unlink( $side ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	}

	public static function record( int $id ): array {
		$r = get_post_meta( $id, self::META, true );
		return is_array( $r ) ? $r : array();
	}

	public static function save_record( int $id, array $rec ): void {
		update_post_meta( $id, self::META, wp_slash( $rec ) );
	}

	/**
	 * Deletes every sidecar the record names (made or adopted), then the
	 * record and the state. Returns the number of files deleted. The
	 * folder comes from the record, so it works after a rename too.
	 */
	public static function forget( int $id ): int {
		$rec = self::record( $id );
		$n   = 0;
		if ( ! empty( $rec['f'] ) && is_array( $rec['f'] ) ) {
			list( $base ) = self::uploads();
			$dir          = isset( $rec['dir'] ) && is_string( $rec['dir'] ) ? trim( wp_normalize_path( $rec['dir'] ), '/' ) : '';
			foreach ( $rec['f'] as $name => $f ) {
				$name = wp_basename( (string) $name );
				$src  = $base . '/' . ( '' === $dir ? '' : $dir . '/' ) . $name;
				foreach ( self::FORMATS as $fmt ) {
					if ( is_array( $f ) && ! empty( $f[ $fmt ] ) && self::unlink_sidecar( $src, $fmt ) ) {
						++$n;
					}
				}
			}
			LIM_Queue::stats_remove( $rec );
		}
		delete_post_meta( $id, self::META );
		delete_post_meta( $id, self::STATE );
		delete_post_meta( $id, self::TRIES );
		return $n;
	}

	/* =====================================================================
	 * Front end
	 * =================================================================== */

	/**
	 * Local path of an uploads URL, or '' (other host, query string, not a
	 * JPEG / PNG, outside uploads). Scheme-relative and root-relative URLs
	 * count.
	 */
	public static function url_to_path( string $url ): string {
		list( $base, , $bpath, $bhost ) = self::uploads();
		if ( '' === $base || '' === $url || false !== strpos( $url, '?' ) || false !== strpos( $url, '#' ) ) {
			return '';
		}
		$u = wp_parse_url( html_entity_decode( $url, ENT_QUOTES ) );
		if ( ! is_array( $u ) || empty( $u['path'] ) ) {
			return '';
		}
		if ( ! empty( $u['host'] ) && strtolower( $u['host'] ) !== $bhost ) {
			return '';
		}
		if ( empty( $u['host'] ) && 0 !== strpos( $url, '/' ) ) {
			return '';
		}
		$path = (string) $u['path'];
		if ( 0 !== strpos( $path, $bpath . '/' ) ) {
			return '';
		}
		$rel = rawurldecode( substr( $path, strlen( $bpath ) + 1 ) );
		if ( '' === $rel || false !== strpos( $rel, '..' ) || false !== strpos( $rel, "\0" ) || ! self::convertible( $rel ) ) {
			return '';
		}
		return $base . '/' . $rel;
	}

	/** True when the sidecar of an uploads URL is on disk (cached). */
	public static function has( string $url, string $fmt ): bool {
		$key = $fmt . '|' . $url;
		if ( ! isset( self::$seen[ $key ] ) ) {
			$path               = self::url_to_path( $url );
			$side               = '' === $path ? '' : self::sidecar( $path, $fmt );
			self::$seen[ $key ] = '' !== $side && is_file( $side );
		}
		return self::$seen[ $key ];
	}
}

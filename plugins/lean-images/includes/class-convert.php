<?php
/**
 * LIM_Convert — one attachment's JPEG / PNG files → WebP / AVIF sidecars.
 *
 * Through WordPress's own image editor (wp_get_image_editor: Imagick or
 * GD, whichever the host has), so no exec(), no binaries and no library
 * of ours. Each file is loaded and saved once per format, at the quality
 * of the settings (filter wp_editor_set_quality only while we save: core
 * resets the quality when the output type changes). Imagick keeps the
 * colour profile and drops EXIF (core's image_strip_meta).
 *
 * A sidecar is written as "<sidecar>.lim-tmp.<fmt>" and renamed, so a
 * visitor never gets half a file. It is kept only when it is at least
 * min_saving percent smaller than its source; otherwise it is deleted and
 * recorded as 0 (never served). A sidecar that is already there and not
 * older than its source (e.g. Imagify's) is adopted, not made again.
 */

defined( 'ABSPATH' ) || exit;

final class LIM_Convert {

	/** Quality for the save in progress (filter callback). */
	private static $quality = 0;

	/** Mime type of a format. */
	public static function mime( string $fmt ): string {
		return 'avif' === $fmt ? 'image/avif' : 'image/webp';
	}

	/** True when the host's image editor can write the format. */
	public static function supports( string $fmt ): bool {
		static $cache = array();
		if ( ! isset( $cache[ $fmt ] ) ) {
			$cache[ $fmt ] = in_array( $fmt, LIM_Files::FORMATS, true ) && wp_image_editor_supports( array( 'mime_type' => self::mime( $fmt ) ) );
		}
		return $cache[ $fmt ];
	}

	/** Name of the editor class WordPress would use ('' = none). */
	public static function editor(): string {
		$e = _wp_image_editor_choose( array( 'mime_type' => 'image/png' ) );
		return is_string( $e ) ? $e : '';
	}

	/** wp_editor_set_quality while we save. */
	public static function quality( $quality, $mime = '' ) {
		return self::$quality > 0 && in_array( $mime, array( 'image/webp', 'image/avif' ), true ) ? self::$quality : $quality;
	}

	/**
	 * Converts one attachment and writes its record and state.
	 *
	 * @return array( 'state' => done|retry|failed|none, 'made' => files
	 *               written, 'kept' => kept, 'src' => source bytes of kept
	 *               files, 'out' => their bytes, 'err' => message )
	 */
	public static function attachment( int $id ): array {
		$res  = array(
			'state' => 'none',
			'made'  => 0,
			'kept'  => 0,
			'src'   => 0,
			'out'   => 0,
			'err'   => '',
		);
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ! in_array( $post->post_mime_type, LIM_Files::MIMES, true ) ) {
			return $res;
		}
		$meta    = wp_get_attachment_metadata( $id );
		$sources = LIM_Files::sources( $id, $meta );
		$formats = array_values( array_filter( LIM_Settings::formats(), array( __CLASS__, 'supports' ) ) );
		$s       = LIM_Settings::get();

		$old = LIM_Files::record( $id );
		if ( $old ) {
			LIM_Queue::stats_remove( $old );
		}
		$rec = array(
			'sig' => LIM_Files::signature( $meta ),
			'dir' => LIM_Files::rel_dir( $id, $meta ),
			'f'   => array(),
			'err' => '',
			't'   => time(),
		);

		$errors = array();
		foreach ( $sources as $name => $src ) {
			$bytes = (int) @filesize( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$f     = isset( $old['f'][ $name ] ) && is_array( $old['f'][ $name ] ) ? $old['f'][ $name ] : array();
			$f['s'] = $bytes;
			foreach ( $formats as $fmt ) {
				$one = self::file( $src, $fmt, $bytes, $s );
				if ( '' !== $one['err'] ) {
					$errors[] = $name . ' (' . $fmt . '): ' . $one['err'];
					continue;
				}
				if ( $one['adopted'] && empty( $f[ $fmt ] ) ) {
					// Not ours before: someone else's file we now look after.
					$f['imp'][ $fmt ] = true;
				}
				$f[ $fmt ] = $one['bytes'];
				if ( $one['made'] ) {
					++$res['made'];
				}
				if ( $one['bytes'] > 0 ) {
					++$res['kept'];
					$res['src'] += $bytes;
					$res['out'] += $one['bytes'];
				}
			}
			$rec['f'][ $name ] = $f;
		}

		if ( $errors ) {
			$rec['err'] = implode( '; ', array_slice( $errors, 0, 3 ) );
			$tries      = (int) get_post_meta( $id, LIM_Files::TRIES, true ) + 1;
			update_post_meta( $id, LIM_Files::TRIES, $tries );
			$res['state'] = $tries >= 3 ? 'failed' : 'retry';
			$res['err']   = $rec['err'];
		} else {
			delete_post_meta( $id, LIM_Files::TRIES );
			$res['state'] = 'done';
		}
		LIM_Files::save_record( $id, $rec );
		update_post_meta( $id, LIM_Files::STATE, $res['state'] );
		LIM_Queue::stats_add( $rec );
		return $res;
	}

	/**
	 * One source → one sidecar.
	 *
	 * @return array( 'bytes' => kept size (0 = not kept), 'made' => bool,
	 *               'adopted' => bool, 'err' => '' )
	 */
	private static function file( string $src, string $fmt, int $bytes, array $s ): array {
		$out  = array(
			'bytes'   => 0,
			'made'    => false,
			'adopted' => false,
			'err'     => '',
		);
		$side = LIM_Files::sidecar( $src, $fmt );
		if ( '' === $side || $bytes <= 0 ) {
			$out['err'] = 'not allowed';
			return $out;
		}
		$limit = (int) floor( $bytes * ( 100 - (int) $s['min_saving'] ) / 100 );

		// Already there and not older than its source: adopt (Imagify's, or
		// ours after a lost record). Kept only when it is smaller.
		if ( is_file( $side ) && (int) @filemtime( $side ) >= (int) @filemtime( $src ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$size = (int) @filesize( $side ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $size > 0 && $size <= $limit ) {
				$out['bytes']   = $size;
				$out['adopted'] = true;
			}
			return $out;
		}

		$made = self::encode( $src, $fmt, (int) $s[ 'q_' . $fmt ] );
		if ( '' !== $made['err'] ) {
			$out['err'] = $made['err'];
			return $out;
		}
		$out['made'] = true;
		$size        = (int) @filesize( $made['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $size <= 0 || $size > $limit ) {
			@unlink( $made['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			if ( is_file( $side ) ) {
				// An older sidecar of a changed source: never serve it.
				LIM_Files::unlink_sidecar( $src, $fmt );
			}
			return $out;
		}
		if ( ! @rename( $made['path'], $side ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			@unlink( $made['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			$out['err'] = 'rename failed';
			return $out;
		}
		$out['bytes'] = $size;
		return $out;
	}

	/**
	 * Encodes $src as $fmt into a temporary file next to it.
	 *
	 * @return array( 'path' => temporary file, 'err' => '' )
	 */
	public static function encode( string $src, string $fmt, int $quality, string $dir = '' ): array {
		$out = array(
			'path' => '',
			'err'  => '',
		);
		if ( ! self::supports( $fmt ) ) {
			$out['err'] = 'the server cannot write ' . $fmt;
			return $out;
		}
		$side = LIM_Files::sidecar( $src, $fmt );
		if ( '' === $side ) {
			$out['err'] = 'not allowed';
			return $out;
		}
		$tmp = ( '' === $dir ? $side : trailingslashit( $dir ) . wp_basename( $side ) ) . '.lim-tmp.' . $fmt;
		try {
			$editor = wp_get_image_editor( $src );
			if ( is_wp_error( $editor ) ) {
				$out['err'] = $editor->get_error_message();
				return $out;
			}
			self::$quality = $quality;
			add_filter( 'wp_editor_set_quality', array( __CLASS__, 'quality' ), PHP_INT_MAX, 2 );
			$editor->set_quality( $quality );
			$saved = $editor->save( $tmp, self::mime( $fmt ) );
		} catch ( \Throwable $e ) {
			$saved = new WP_Error( 'lim_encode', $e->getMessage() );
		} finally {
			remove_filter( 'wp_editor_set_quality', array( __CLASS__, 'quality' ), PHP_INT_MAX );
			self::$quality = 0;
		}
		if ( is_wp_error( $saved ) ) {
			$out['err'] = $saved->get_error_message();
			return $out;
		}
		$path = is_array( $saved ) && ! empty( $saved['path'] ) ? (string) $saved['path'] : '';
		$mime = is_array( $saved ) && ! empty( $saved['mime-type'] ) ? (string) $saved['mime-type'] : '';
		if ( '' === $path || ! is_file( $path ) ) {
			$out['err'] = 'nothing was written';
			return $out;
		}
		if ( self::mime( $fmt ) !== $mime ) {
			// Another plugin mapped the output to a different type.
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			$out['err'] = 'the editor wrote ' . $mime;
			return $out;
		}
		$out['path'] = $path;
		return $out;
	}

	/**
	 * Sample test: converts the files of up to $max waiting attachments
	 * into a temporary folder, measures, deletes everything. Writes no
	 * record and keeps no file.
	 *
	 * @return array( 'items', 'files', 'src', 'out' (per format), 'secs',
	 *               'errors', 'stopped' )
	 */
	public static function sample( int $max = 20, int $budget = 20 ): array {
		$res = array(
			'items'   => 0,
			'files'   => 0,
			'src'     => 0,
			'out'     => array(),
			'secs'    => 0.0,
			'errors'  => 0,
			'stopped' => false,
		);
		$formats = array_values( array_filter( LIM_Settings::formats(), array( __CLASS__, 'supports' ) ) );
		if ( ! $formats ) {
			return $res;
		}
		$ids = LIM_Queue::pending( $max );
		if ( ! $ids ) {
			$ids = LIM_Queue::any( $max );
		}
		$dir = trailingslashit( get_temp_dir() ) . 'lim-sample-' . wp_generate_password( 8, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return $res;
		}
		$s     = LIM_Settings::get();
		$start = microtime( true );
		foreach ( $ids as $id ) {
			if ( microtime( true ) - $start >= $budget ) {
				$res['stopped'] = true;
				break;
			}
			++$res['items'];
			foreach ( LIM_Files::sources( (int) $id ) as $src ) {
				$bytes = (int) @filesize( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				++$res['files'];
				$res['src'] += $bytes;
				foreach ( $formats as $fmt ) {
					$made = self::encode( $src, $fmt, (int) $s[ 'q_' . $fmt ], $dir );
					if ( '' !== $made['err'] ) {
						++$res['errors'];
						continue;
					}
					$size = (int) @filesize( $made['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					@unlink( $made['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
					$keep = $size > 0 && $size <= floor( $bytes * ( 100 - (int) $s['min_saving'] ) / 100 );
					// What would be added on disk: kept sidecars only.
					$res['out'][ $fmt ] = ( isset( $res['out'][ $fmt ] ) ? $res['out'][ $fmt ] : 0 ) + ( $keep ? $size : 0 );
				}
			}
		}
		$res['secs'] = round( microtime( true ) - $start, 1 );
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return $res;
	}
}

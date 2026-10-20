<?php
/**
 * BKF_Square — square covers (1.9.0).
 *
 * With the "square" setting on, every cover the plugin downloads becomes
 * a square PNG with a transparent background: the whole cover, never
 * cropped, centred on a canvas whose side is its longer side. Store
 * pages then show book covers in the same square frame as the store's
 * own titles.
 *
 * Two ways in:
 *  - file(): a downloaded file, before it goes into the media library
 *    (BKF_Importer::sideload()).
 *  - attachment(): an attachment already in the library, in place: same
 *    id (so field hashes and product links stay), a new PNG file next to
 *    the old one, new sizes, the old files deleted.
 *
 * Covers already in the library are done in the background (hook
 * bkf_square_step, steps of at most BUDGET seconds), started when the
 * setting is turned on or from Tools. Only attachments the plugin
 * downloaded (_bkf_cover_src) that are the image of an imported product
 * (_bkf_source); covers uploaded by hand are never touched.
 *
 * Attachment meta: _bkf_square = 1 (done or already square) | fail.
 * State of the background job in bkf_square (autoload off):
 *  { began, done, skipped, errors, steps }; the last summary in
 *  bkf_square_last.
 *
 * GD with alpha is used when present, else Imagick.
 */

defined( 'ABSPATH' ) || exit;

final class BKF_Square {

	const OPTION = 'bkf_square';
	const LAST   = 'bkf_square_last';
	const HOOK   = 'bkf_square_step';
	const BUDGET = 10;
	const BATCH  = 20;
	const MAX_PX = 40000000; // 40 MP: larger images are left as they are

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'step' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure' ) );
		add_action( 'update_option_' . BKF_Settings::OPTION, array( __CLASS__, 'on_settings' ), 10, 2 );
		add_action( 'add_option_' . BKF_Settings::OPTION, array( __CLASS__, 'on_settings_added' ), 10, 2 );
	}

	/** True when this server can draw a transparent canvas. */
	public static function available(): bool {
		return ( function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagepng' ) && function_exists( 'imagesavealpha' ) )
			|| ( class_exists( 'Imagick' ) );
	}

	/* ---------------------------------------------------------------------
	 * One image
	 * ------------------------------------------------------------------- */

	/**
	 * Makes a square transparent PNG from an image file.
	 *
	 * @param string $src  the image file.
	 * @param string $dest where to write the PNG ('' = a new temporary file).
	 * @return string the PNG's path; '' when the image is already square;
	 *                WP_Error when it cannot be done.
	 */
	public static function file( string $src, string $dest = '' ) {
		$size = @getimagesize( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
			return new WP_Error( 'bkf_square', __( 'Η εικόνα δεν διαβάζεται.', 'book-feed' ) );
		}
		$w = (int) $size[0];
		$h = (int) $size[1];
		if ( $w === $h ) {
			return '';
		}
		$side = max( $w, $h );
		if ( $side * $side > self::MAX_PX ) {
			return new WP_Error( 'bkf_square', __( 'Η εικόνα είναι πολύ μεγάλη για τετράγωνο καμβά.', 'book-feed' ) );
		}
		if ( '' === $dest ) {
			$dest = wp_tempnam( 'bkf-square.png' );
		}
		$ok = self::gd( $src, (string) $size['mime'], $w, $h, $side, $dest );
		if ( true !== $ok ) {
			$ok = self::imagick( $src, $w, $h, $side, $dest );
		}
		if ( true !== $ok ) {
			wp_delete_file( $dest );
			return new WP_Error( 'bkf_square', __( 'Ο server δεν μπορεί να φτιάξει τετράγωνη εικόνα (λείπουν GD και Imagick).', 'book-feed' ) );
		}
		return $dest;
	}

	private static function gd( string $src, string $mime, int $w, int $h, int $side, string $dest ): bool {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagesavealpha' ) ) {
			return false;
		}
		$open = array(
			'image/jpeg' => 'imagecreatefromjpeg',
			'image/png'  => 'imagecreatefrompng',
			'image/gif'  => 'imagecreatefromgif',
			'image/webp' => 'imagecreatefromwebp',
		);
		if ( ! isset( $open[ $mime ] ) || ! function_exists( $open[ $mime ] ) ) {
			return false;
		}
		$img = @call_user_func( $open[ $mime ], $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $img ) {
			return false;
		}
		$canvas = imagecreatetruecolor( $side, $side );
		if ( ! $canvas ) {
			imagedestroy( $img );
			return false;
		}
		imagealphablending( $canvas, false );
		imagesavealpha( $canvas, true );
		imagefill( $canvas, 0, 0, imagecolorallocatealpha( $canvas, 0, 0, 0, 127 ) );
		imagealphablending( $canvas, true );
		imagecopy( $canvas, $img, intdiv( $side - $w, 2 ), intdiv( $side - $h, 2 ), 0, 0, $w, $h );
		$ok = imagepng( $canvas, $dest, 9 );
		imagedestroy( $img );
		imagedestroy( $canvas );
		return $ok && is_readable( $dest ) && filesize( $dest ) > 0;
	}

	private static function imagick( string $src, int $w, int $h, int $side, string $dest ): bool {
		if ( ! class_exists( 'Imagick' ) ) {
			return false;
		}
		try {
			$im = new Imagick( $src );
			$im->setIteratorIndex( 0 ); // first frame of a GIF
			$im->setImageBackgroundColor( new ImagickPixel( 'transparent' ) );
			$im->setImageAlphaChannel( Imagick::ALPHACHANNEL_SET );
			$im->extentImage( $side, $side, -intdiv( $side - $w, 2 ), -intdiv( $side - $h, 2 ) );
			$im->setImageFormat( 'png' );
			$ok = $im->writeImage( $dest );
			$im->clear();
			return $ok && is_readable( $dest ) && filesize( $dest ) > 0;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Squares an attachment in place (same id): 'done', 'square' (it
	 * already was), or WP_Error (and _bkf_square = fail).
	 */
	public static function attachment( int $att ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$orig = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $att ) : get_attached_file( $att );
		if ( ! $orig || ! is_readable( $orig ) ) {
			update_post_meta( $att, '_bkf_square', 'fail' );
			return new WP_Error( 'bkf_square', __( 'Το αρχείο της εικόνας δεν βρέθηκε.', 'book-feed' ) );
		}
		$dir  = dirname( $orig );
		$name = wp_unique_filename( $dir, pathinfo( $orig, PATHINFO_FILENAME ) . '.png' );
		$dest = $dir . '/' . $name;
		$png  = self::file( $orig, $dest );
		if ( is_wp_error( $png ) ) {
			update_post_meta( $att, '_bkf_square', 'fail' );
			return $png;
		}
		if ( '' === $png ) {
			update_post_meta( $att, '_bkf_square', '1' );
			return 'square';
		}

		// The old files go only after the new ones are in place.
		$old_meta   = wp_get_attachment_metadata( $att );
		$old_backup = get_post_meta( $att, '_wp_attachment_backup_sizes', true );
		$old_file   = get_attached_file( $att );

		update_attached_file( $att, $png );
		wp_update_post(
			array(
				'ID'             => $att,
				'post_mime_type' => 'image/png',
			)
		);
		$meta = wp_generate_attachment_metadata( $att, $png );
		if ( ! is_array( $meta ) || empty( $meta['width'] ) ) {
			// Put the old file back; the new one goes.
			update_attached_file( $att, $old_file );
			wp_update_post(
				array(
					'ID'             => $att,
					'post_mime_type' => (string) wp_check_filetype( (string) $old_file )['type'],
				)
			);
			wp_delete_file( $png );
			update_post_meta( $att, '_bkf_square', 'fail' );
			return new WP_Error( 'bkf_square', __( 'Τα μεγέθη της νέας εικόνας δεν δημιουργήθηκαν.', 'book-feed' ) );
		}
		wp_update_attachment_metadata( $att, $meta );
		if ( is_array( $old_meta ) && $old_file && function_exists( 'wp_delete_attachment_files' ) ) {
			wp_delete_attachment_files( $att, $old_meta, is_array( $old_backup ) ? $old_backup : array(), $old_file );
		}
		update_post_meta( $att, '_bkf_square', '1' );
		clean_post_cache( $att );
		return 'done';
	}

	/* ---------------------------------------------------------------------
	 * Covers already in the library (background)
	 * ------------------------------------------------------------------- */

	public static function get(): ?array {
		$b = get_option( self::OPTION, null );
		return is_array( $b ) && isset( $b['began'] ) ? $b : null;
	}

	public static function last(): ?array {
		$l = get_option( self::LAST, null );
		return is_array( $l ) && isset( $l['t'] ) ? $l : null;
	}

	/** Starts the background job (false while one runs). */
	public static function start(): bool {
		if ( self::get() ) {
			return false;
		}
		update_option(
			self::OPTION,
			array(
				'began'   => time(),
				'done'    => 0,
				'skipped' => 0,
				'errors'  => 0,
				'steps'   => 0,
			),
			false
		);
		BKF_Sync::schedule( self::HOOK, time() );
		return true;
	}

	/** Wakes a job left without a scheduled step (admin only). */
	public static function ensure(): void {
		$b = self::get();
		if ( $b && ! BKF_Sync::next( self::HOOK ) && time() - (int) ( $b['at'] ?? $b['began'] ) > 120 ) {
			BKF_Sync::schedule( self::HOOK, time() );
		}
	}

	/** Turning "square" on also squares the covers already there. */
	public static function on_settings( $old, $new ): void {
		$was = is_array( $old ) && ! empty( $old['square'] );
		$now = is_array( $new ) && ! empty( $new['square'] );
		if ( $now && ! $was ) {
			self::start();
		}
	}

	public static function on_settings_added( $name, $value ): void {
		self::on_settings( array(), $value );
	}

	/** Covers to do: ours, the image of an imported product, not yet square. */
	public static function todo( int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one read over indexed meta keys.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT t.meta_value FROM {$wpdb->postmeta} t
				 JOIN {$wpdb->postmeta} s ON s.post_id = t.post_id AND s.meta_key = '_bkf_source'
				 JOIN {$wpdb->postmeta} c ON c.post_id = t.meta_value AND c.meta_key = '_bkf_cover_src'
				 LEFT JOIN {$wpdb->postmeta} q ON q.post_id = t.meta_value AND q.meta_key = '_bkf_square'
				 WHERE t.meta_key = '_thumbnail_id' AND q.meta_id IS NULL
				 ORDER BY t.meta_value + 0 ASC LIMIT %d",
				$limit
			)
		);
		return array_map( 'intval', $ids );
	}

	/** How many covers are still to do. */
	public static function left(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT t.meta_value) FROM {$wpdb->postmeta} t
			 JOIN {$wpdb->postmeta} s ON s.post_id = t.post_id AND s.meta_key = '_bkf_source'
			 JOIN {$wpdb->postmeta} c ON c.post_id = t.meta_value AND c.meta_key = '_bkf_cover_src'
			 LEFT JOIN {$wpdb->postmeta} q ON q.post_id = t.meta_value AND q.meta_key = '_bkf_square'
			 WHERE t.meta_key = '_thumbnail_id' AND q.meta_id IS NULL"
		);
	}

	/** One step (Action Scheduler / WP-Cron). */
	public static function step(): void {
		$b = self::get();
		if ( ! $b ) {
			return;
		}
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 120 );
		}
		$t0 = microtime( true );
		++$b['steps'];
		$more = true;
		if ( ! self::available() ) {
			$b['error'] = __( 'Ο server δεν μπορεί να φτιάξει τετράγωνη εικόνα (λείπουν GD και Imagick).', 'book-feed' );
			$more       = false;
		}
		while ( $more && microtime( true ) - $t0 < self::BUDGET ) {
			$ids = self::todo( self::BATCH );
			if ( ! $ids ) {
				$more = false;
				break;
			}
			foreach ( $ids as $att ) {
				if ( microtime( true ) - $t0 >= self::BUDGET ) {
					break;
				}
				$res = self::attachment( $att );
				if ( is_wp_error( $res ) ) {
					++$b['errors'];
					BKF_Log::add( 'error', (int) wp_get_post_parent_id( $att ), '', get_the_title( $att ), array( 'image' ), $res->get_error_message() );
				} elseif ( 'done' === $res ) {
					++$b['done'];
				} else {
					++$b['skipped'];
				}
			}
		}
		BKF_Log::flush();
		if ( ! $more || $b['steps'] > 500 ) {
			self::finish( $b );
			return;
		}
		$b['at'] = time();
		update_option( self::OPTION, $b, false );
		BKF_Sync::schedule( self::HOOK, time() );
	}

	private static function finish( array $b ): void {
		$last = array(
			't'       => time(),
			'done'    => (int) $b['done'],
			'skipped' => (int) $b['skipped'],
			'errors'  => (int) $b['errors'],
			'error'   => (string) ( $b['error'] ?? '' ),
		);
		update_option( self::LAST, $last, false );
		delete_option( self::OPTION );
		BKF_Sync::unschedule( self::HOOK );
		BKF_Log::add( 'bulk', 0, '', '', array( 'image' ), self::text( $last ) . ( '' !== $last['error'] ? ' ' . $last['error'] : '' ) );
		BKF_Log::flush();
	}

	/** "Square covers: 96 made square, 2 already square, 0 errors." */
	public static function text( array $b ): string {
		/* translators: 1: covers made square, 2: already square, 3: errors */
		return sprintf( __( 'Τετράγωνα εξώφυλλα: %1$d έγιναν τετράγωνα, %2$d ήταν ήδη, σφάλματα %3$d.', 'book-feed' ), (int) $b['done'], (int) $b['skipped'], (int) $b['errors'] );
	}
}

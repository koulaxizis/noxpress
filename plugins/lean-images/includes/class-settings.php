<?php
/**
 * LIM_Settings — the lim_settings option, strict validation (Bible §11).
 *
 *  running     the background batches convert (off after activation)
 *  test        test mode: <picture> only for administrators
 *  webp        make and serve WebP
 *  avif        make and serve AVIF (off: several times the CPU of WebP)
 *  q_webp      WebP quality 50–100 (82)
 *  q_avif      AVIF quality 30–100 (60)
 *  min_saving  keep a sidecar only when it is at least this many percent
 *              smaller than its source (10)
 *  batch       attachments per batch (10)
 *  budget      seconds per batch (20)
 *  night       batches only between 01:00 and 07:00, site time
 *  full_page   also rewrite <img> that WordPress functions did not print
 *              (whole-page pass, off)
 */

defined( 'ABSPATH' ) || exit;

final class LIM_Settings {

	const OPTION = 'lim_settings';

	/** Slug of the settings page. */
	const PAGE = 'lim-settings';

	const BATCHES = array( 1, 2, 5, 10, 20, 30 );
	const BUDGETS = array( 5, 10, 15, 20, 30 );

	/** Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'running'    => false,
			'test'       => true,
			'webp'       => true,
			'avif'       => false,
			'q_webp'     => 82,
			'q_avif'     => 60,
			'min_saving' => 10,
			'batch'      => 10,
			'budget'     => 20,
			'night'      => false,
			'full_page'  => false,
		);
	}

	/** Boolean keys. */
	public static function bools(): array {
		return array( 'running', 'test', 'webp', 'avif', 'night', 'full_page' );
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPTION, array() );
			self::$cache = self::sanitize( is_array( $raw ) ? $raw : array(), self::defaults() );
		}
		return self::$cache;
	}

	/** Merges $in into the current settings (invalid values keep the old one). */
	public static function update( array $in ): array {
		$new = self::sanitize( $in, self::get() );
		update_option( self::OPTION, $new, false );
		self::$cache = $new;
		return $new;
	}

	public static function reset_cache(): void {
		self::$cache = null;
	}

	/** Validates each known key of $in against $base; unknown keys drop. */
	public static function sanitize( array $in, array $base ): array {
		$out = $base;
		foreach ( self::bools() as $k ) {
			if ( array_key_exists( $k, $in ) ) {
				$out[ $k ] = (bool) filter_var( $in[ $k ], FILTER_VALIDATE_BOOLEAN );
			}
		}
		$ints = array(
			'q_webp'     => array( 50, 100 ),
			'q_avif'     => array( 30, 100 ),
			'min_saving' => array( 0, 50 ),
		);
		foreach ( $ints as $k => $range ) {
			if ( isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) && is_numeric( $in[ $k ] ) ) {
				$v = (int) $in[ $k ];
				if ( $v >= $range[0] && $v <= $range[1] ) {
					$out[ $k ] = $v;
				}
			}
		}
		if ( isset( $in['batch'] ) && is_scalar( $in['batch'] ) && in_array( (int) $in['batch'], self::BATCHES, true ) ) {
			$out['batch'] = (int) $in['batch'];
		}
		if ( isset( $in['budget'] ) && is_scalar( $in['budget'] ) && in_array( (int) $in['budget'], self::BUDGETS, true ) ) {
			$out['budget'] = (int) $in['budget'];
		}
		return $out;
	}

	/** The formats to make: 'avif' first, then 'webp' (only those on). */
	public static function formats(): array {
		$s   = self::get();
		$out = array();
		if ( $s['avif'] ) {
			$out[] = 'avif';
		}
		if ( $s['webp'] ) {
			$out[] = 'webp';
		}
		return $out;
	}
}

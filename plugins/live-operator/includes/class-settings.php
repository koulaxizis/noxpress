<?php
/**
 * LOP_Settings — the lop_settings option, strict validation (Bible §11).
 *
 *  enabled      the app's switch decides the Live Chat availability
 *               (off after activation: nothing changes until it is set,
 *               Bible §14.1)
 *  hours        automatic offline after this many hours online
 *  inbox        the PurioChat Live Chat page, while open, also counts as
 *               online (off: only the switch decides)
 *  preview      the visitor's message text in the notifications
 *  app_name     name of the installed app (web manifest)
 *  package      Android package of the companion app (assetlinks.json)
 *  fingerprints SHA-256 signing certificate fingerprints of that app
 */

defined( 'ABSPATH' ) || exit;

final class LOP_Settings {

	const OPTION = 'lop_settings';

	/** Allowed values of «automatic offline after». */
	const HOURS = array( 1, 2, 4, 8, 12, 24 );

	const PACKAGE = 'tech.noxpress.liveoperator';

	/** Slug of the settings page (used outside the admin too). */
	const PAGE = 'lop-settings';

	/** Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'enabled'      => false,
			'hours'        => 8,
			'inbox'        => false,
			'preview'      => true,
			'app_name'     => '',
			'package'      => self::PACKAGE,
			'fingerprints' => array(),
		);
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPTION, array() );
			self::$cache = self::sanitize( is_array( $raw ) ? $raw : array() );
		}
		return self::$cache;
	}

	public static function save( array $settings ): void {
		$clean = self::sanitize( $settings );
		update_option( self::OPTION, $clean, true );
		self::$cache = $clean;
	}

	public static function sanitize( array $raw ): array {
		$d   = self::defaults();
		$out = array();

		foreach ( array( 'enabled', 'inbox', 'preview' ) as $k ) {
			$out[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}

		$hours        = isset( $raw['hours'] ) ? (int) $raw['hours'] : $d['hours'];
		$out['hours'] = in_array( $hours, self::HOURS, true ) ? $hours : $d['hours'];

		$name            = isset( $raw['app_name'] ) && is_scalar( $raw['app_name'] ) ? sanitize_text_field( (string) $raw['app_name'] ) : '';
		$out['app_name'] = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 40 ) : substr( $name, 0, 40 );

		$pkg            = isset( $raw['package'] ) && is_scalar( $raw['package'] ) ? trim( (string) $raw['package'] ) : '';
		$out['package'] = preg_match( '/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/', $pkg ) && strlen( $pkg ) <= 120 ? $pkg : $d['package'];

		$out['fingerprints'] = self::fingerprints( isset( $raw['fingerprints'] ) ? $raw['fingerprints'] : array() );

		return $out;
	}

	/**
	 * Valid SHA-256 fingerprints (AA:BB:… upper case, 32 bytes), from a list
	 * or from text with one per line. Anything else is dropped.
	 *
	 * @param mixed $value Array or text.
	 */
	public static function fingerprints( $value ): array {
		$lines = is_array( $value ) ? $value : preg_split( '/[\r\n,]+/', is_scalar( $value ) ? (string) $value : '' );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			if ( ! is_scalar( $line ) ) {
				continue;
			}
			$hex = strtoupper( preg_replace( '/[^0-9A-Fa-f]/', '', (string) $line ) );
			if ( 64 === strlen( $hex ) ) {
				$out[] = implode( ':', str_split( $hex, 2 ) );
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 5 );
	}

	/** Lines of the fingerprints field that are not a valid fingerprint. */
	public static function count_invalid_fingerprints( string $text ): int {
		$bad = 0;
		foreach ( preg_split( '/[\r\n]+/', $text ) as $line ) {
			if ( '' !== trim( $line ) && array() === self::fingerprints( array( $line ) ) ) {
				++$bad;
			}
		}
		return $bad;
	}

	/** The app's name: the setting, or «<site name> Live». */
	public static function app_name(): string {
		$name = self::get()['app_name'];
		if ( '' !== $name ) {
			return $name;
		}
		$site = wp_strip_all_tags( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		return '' !== $site ? $site . ' Live' : 'Live Operator';
	}
}

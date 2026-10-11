<?php
/**
 * BPV_Settings — options, defaults and strict validation (Bible §11, §12, §14.17).
 *
 * One option, bpv_settings (autoload, read on product pages):
 *  - enabled, test_mode                 front end on / off (Bible §14.1–2)
 *  - position                           after_excerpt | after_cart | manual
 *  - lang                               auto | el | en (visitor texts)
 *  - button_el / button_en              the button label
 *  - end_card                           "Continue reading" card after the last page
 *  - end_title_el / end_title_en        its title
 *  - end_button_el / end_button_en      its buy button
 *  - first_pages, skip_pages            defaults for an empty page field and the bulk run
 *  - warn_mb                            size above which the admin is warned
 *  - external                           allow qpdf / Ghostscript when present
 *  - auto_regen                         rebuild stale samples in the background
 *
 * Visitor texts are shop settings, not msgids (§14.17): an empty English
 * text shows the Greek one, an empty Greek text the built-in default.
 */

defined( 'ABSPATH' ) || exit;

final class BPV_Settings {

	const OPT       = 'bpv_settings';
	const MAX_TEXT  = 80;
	const POSITIONS = array( 'after_excerpt', 'after_cart', 'manual' );
	const LANGS     = array( 'auto', 'el', 'en' );

	/** Built-in visitor texts: key => [ el, en ]. */
	const TEXTS = array(
		'button'     => array( 'Διαβάστε ένα δείγμα', 'Read a sample' ),
		'end_title'  => array( 'Συνεχίστε την ανάγνωση', 'Keep reading' ),
		'end_button' => array( 'Αγορά', 'Buy now' ),
	);

	/** @var array|null Per-request cache. */
	private static $settings = null;

	public static function defaults(): array {
		return array(
			'enabled'       => false,
			'test_mode'     => true,
			'position'      => 'after_excerpt',
			'lang'          => 'auto',
			'button_el'     => '',
			'button_en'     => '',
			'end_card'      => true,
			'end_title_el'  => '',
			'end_title_en'  => '',
			'end_button_el' => '',
			'end_button_en' => '',
			'first_pages'   => 10,
			'skip_pages'    => 0,
			'warn_mb'       => 10,
			'external'      => true,
			'auto_regen'    => true,
		);
	}

	public static function get(): array {
		if ( null === self::$settings ) {
			$raw            = get_option( self::OPT, array() );
			self::$settings = self::sanitize( is_array( $raw ) ? $raw : array() );
		}
		return self::$settings;
	}

	public static function sanitize( array $in ): array {
		$d   = self::defaults();
		$out = array();
		foreach ( array( 'enabled', 'test_mode', 'end_card', 'external', 'auto_regen' ) as $k ) {
			$out[ $k ] = array_key_exists( $k, $in ) ? (bool) $in[ $k ] : $d[ $k ];
		}
		$pos             = isset( $in['position'] ) && is_scalar( $in['position'] ) ? (string) $in['position'] : '';
		$out['position'] = in_array( $pos, self::POSITIONS, true ) ? $pos : $d['position'];
		$lang            = isset( $in['lang'] ) && is_scalar( $in['lang'] ) ? (string) $in['lang'] : '';
		$out['lang']     = in_array( $lang, self::LANGS, true ) ? $lang : $d['lang'];
		foreach ( array_keys( self::TEXTS ) as $t ) {
			foreach ( array( 'el', 'en' ) as $l ) {
				$k         = $t . '_' . $l;
				$out[ $k ] = isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? self::clip( sanitize_text_field( (string) $in[ $k ] ), self::MAX_TEXT ) : '';
			}
		}
		$out['first_pages'] = self::int_in( $in['first_pages'] ?? null, 1, 200, $d['first_pages'] );
		$out['skip_pages']  = self::int_in( $in['skip_pages'] ?? null, 0, 100, $d['skip_pages'] );
		$out['warn_mb']     = self::int_in( $in['warn_mb'] ?? null, 1, 500, $d['warn_mb'] );
		return $out;
	}

	private static function int_in( $v, int $min, int $max, int $default ): int {
		if ( ! is_scalar( $v ) || ! preg_match( '/^\d+$/', trim( (string) $v ) ) ) {
			return $default;
		}
		return max( $min, min( $max, (int) $v ) );
	}

	public static function save( array $in ): void {
		$clean = self::sanitize( $in );
		update_option( self::OPT, $clean, true );
		self::$settings = $clean;
	}

	public static function clip( string $s, int $max ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	/** Visitor language: the setting, or the site locale (§14.17). */
	public static function front_lang(): string {
		$l = self::get()['lang'];
		if ( 'el' === $l || 'en' === $l ) {
			return $l;
		}
		return 0 === strpos( strtolower( (string) get_locale() ), 'el' ) ? 'el' : 'en';
	}

	/**
	 * A visitor text in the visitor language. Filter `bpv_text` lets code
	 * change it (e.g. WPML): ( string $text, string $key, string $lang ).
	 */
	public static function text( string $key ): string {
		if ( ! isset( self::TEXTS[ $key ] ) ) {
			return '';
		}
		$s    = self::get();
		$lang = self::front_lang();
		$el   = '' !== $s[ $key . '_el' ] ? $s[ $key . '_el' ] : self::TEXTS[ $key ][0];
		if ( 'en' === $lang ) {
			$out = '' !== $s[ $key . '_en' ] ? $s[ $key . '_en' ] : ( '' !== $s[ $key . '_el' ] ? $s[ $key . '_el' ] : self::TEXTS[ $key ][1] );
		} else {
			$out = $el;
		}
		return (string) apply_filters( 'bpv_text', $out, $key, $lang );
	}
}

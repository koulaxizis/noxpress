<?php
/**
 * PFM_Settings — options, defaults and strict validation (Bible §11, §12).
 *
 * Options (all prefixed pfm_):
 *  - pfm_settings (autoload): enabled, test_mode, block position, block
 *    title (EL / EN), stock note, list line, upsell hiding.
 *  - pfm_formats  (autoload): the format registry, in display order. Each
 *    format has a key, EL / EN labels (built-in formats may leave them
 *    empty to use the translated default), slug suffixes and product
 *    categories that point to it (used only for suggestions), on / off.
 *  - pfm_rejected (autoload = no): signatures of rejected suggestions.
 *
 * Every write goes through the sanitize_* methods below, the forms and the
 * JSON import alike.
 */

defined( 'ABSPATH' ) || exit;

final class PFM_Settings {

	const OPT_SETTINGS = 'pfm_settings';
	const OPT_FORMATS  = 'pfm_formats';
	const OPT_REJECTED = 'pfm_rejected';

	const MAX_FORMATS  = 20;
	const MAX_SUFFIXES = 10;
	const MAX_REJECTED = 2000;
	const MAX_LABEL    = 60;
	const MAX_TITLE    = 80;

	const POSITIONS = array( 'after_price', 'after_cart', 'before_tabs', 'manual' );

	/** Per-request caches. */
	private static $settings = null;
	private static $formats  = null;

	/* =====================================================================
	 * General settings
	 * =================================================================== */

	public static function defaults(): array {
		return array(
			'enabled'      => false,
			'test_mode'    => true,
			'position'     => 'after_price',
			'title_el'     => '',
			'title_en'     => '',
			'show_stock'   => true,
			'loop_line'    => false,
			'hide_upsells' => false,
		);
	}

	public static function get(): array {
		if ( null === self::$settings ) {
			$raw            = get_option( self::OPT_SETTINGS, array() );
			self::$settings = self::sanitize_settings( is_array( $raw ) ? $raw : array() );
		}
		return self::$settings;
	}

	public static function sanitize_settings( array $in ): array {
		$d   = self::defaults();
		$out = array();
		foreach ( array( 'enabled', 'test_mode', 'show_stock', 'loop_line', 'hide_upsells' ) as $k ) {
			$out[ $k ] = array_key_exists( $k, $in ) ? (bool) $in[ $k ] : $d[ $k ];
		}
		$pos             = isset( $in['position'] ) ? (string) $in['position'] : $d['position'];
		$out['position'] = in_array( $pos, self::POSITIONS, true ) ? $pos : $d['position'];
		foreach ( array( 'title_el', 'title_en' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? self::clip( sanitize_text_field( (string) $in[ $k ] ), self::MAX_TITLE ) : '';
		}
		return $out;
	}

	public static function save( array $in ): void {
		$clean = self::sanitize_settings( $in );
		update_option( self::OPT_SETTINGS, $clean, true );
		self::$settings = $clean;
	}

	/* =====================================================================
	 * Format registry
	 * =================================================================== */

	/** Built-in formats: key => default msgid (translated on render). */
	public static function builtin(): array {
		return array(
			'print'     => 'Έντυπο',
			'ebook'     => 'Ηλεκτρονικό βιβλίο',
			'audiobook' => 'Ηχητικό βιβλίο',
			'film'      => 'Ταινία',
			'music'     => 'Μουσική',
		);
	}

	/** Default slug suffixes of the built-in formats. */
	private static function builtin_suffixes(): array {
		return array(
			'print'     => array(),
			'ebook'     => array( 'ebook', 'e-book' ),
			'audiobook' => array( 'audiobook', 'audio-book' ),
			'film'      => array( 'film', 'movie' ),
			'music'     => array( 'music', 'songs', 'album' ),
		);
	}

	public static function default_formats(): array {
		$out = array();
		foreach ( self::builtin_suffixes() as $key => $suffixes ) {
			$out[] = array(
				'key'      => $key,
				'builtin'  => true,
				'label_el' => '',
				'label_en' => '',
				'suffixes' => $suffixes,
				'cats'     => array(),
				'enabled'  => true,
			);
		}
		return $out;
	}

	/** All formats (enabled or not), in display order. */
	public static function formats(): array {
		if ( null === self::$formats ) {
			$raw           = get_option( self::OPT_FORMATS, null );
			self::$formats = is_array( $raw ) ? self::sanitize_formats( $raw ) : self::default_formats();
		}
		return self::$formats;
	}

	/** Enabled formats, keyed by format key. */
	public static function enabled_formats(): array {
		$out = array();
		foreach ( self::formats() as $f ) {
			if ( $f['enabled'] ) {
				$out[ $f['key'] ] = $f;
			}
		}
		return $out;
	}

	/** One format by key (enabled or not), or null. */
	public static function format( string $key ): ?array {
		foreach ( self::formats() as $f ) {
			if ( $f['key'] === $key ) {
				return $f;
			}
		}
		return null;
	}

	/** Position of a format key in the registry (unknown keys last). */
	public static function format_rank( string $key ): int {
		foreach ( self::formats() as $i => $f ) {
			if ( $f['key'] === $key ) {
				return $i;
			}
		}
		return 999;
	}

	/**
	 * The label of a format in the current language (admin: the user's
	 * choice, front end: the site language). Unknown keys return ''.
	 */
	public static function label( string $key ): string {
		$f = self::format( $key );
		if ( null === $f ) {
			return '';
		}
		$en = 'en' === PFM_Lang::lang();
		if ( $en && '' !== $f['label_en'] ) {
			return $f['label_en'];
		}
		if ( '' !== $f['label_el'] && ( ! $en || ! $f['builtin'] ) ) {
			return $f['label_el'];
		}
		$builtin = self::builtin();
		if ( isset( $builtin[ $key ] ) ) {
			return __( $builtin[ $key ], 'product-formats' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- msgid from builtin().
		}
		return $key;
	}

	/**
	 * Strict validation of the registry. Built-in keys always exist (they
	 * can be disabled, not removed); custom keys are [a-z0-9_-], unique.
	 */
	public static function sanitize_formats( array $in ): array {
		$builtin = self::builtin();
		$seen    = array();
		$out     = array();
		foreach ( $in as $row ) {
			if ( ! is_array( $row ) || count( $out ) >= self::MAX_FORMATS ) {
				continue;
			}
			$key = isset( $row['key'] ) && is_scalar( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
			if ( '' === $key || strlen( $key ) > 20 || isset( $seen[ $key ] ) ) {
				continue;
			}
			$is_builtin = isset( $builtin[ $key ] );
			$label_el   = isset( $row['label_el'] ) && is_scalar( $row['label_el'] ) ? self::clip( sanitize_text_field( (string) $row['label_el'] ), self::MAX_LABEL ) : '';
			$label_en   = isset( $row['label_en'] ) && is_scalar( $row['label_en'] ) ? self::clip( sanitize_text_field( (string) $row['label_en'] ), self::MAX_LABEL ) : '';
			if ( ! $is_builtin && '' === $label_el ) {
				continue; // A custom format needs at least a Greek label.
			}
			$out[]        = array(
				'key'      => $key,
				'builtin'  => $is_builtin,
				'label_el' => $label_el,
				'label_en' => $label_en,
				'suffixes' => self::sanitize_suffixes( $row['suffixes'] ?? array() ),
				'cats'     => self::sanitize_cats( $row['cats'] ?? array() ),
				'enabled'  => ! empty( $row['enabled'] ),
			);
			$seen[ $key ] = true;
		}
		// Built-in formats that are missing come back at the end, enabled.
		foreach ( self::default_formats() as $def ) {
			if ( ! isset( $seen[ $def['key'] ] ) ) {
				$out[] = $def;
			}
		}
		return $out;
	}

	/** @param mixed $in Array of suffixes or a comma separated string. */
	public static function sanitize_suffixes( $in ): array {
		if ( is_string( $in ) ) {
			$in = explode( ',', $in );
		}
		$out = array();
		foreach ( is_array( $in ) ? $in : array() as $s ) {
			if ( ! is_scalar( $s ) ) {
				continue;
			}
			$s = trim( sanitize_title( (string) $s ), '-' );
			if ( '' !== $s && strlen( $s ) <= 30 && ! in_array( $s, $out, true ) ) {
				$out[] = $s;
			}
			if ( count( $out ) >= self::MAX_SUFFIXES ) {
				break;
			}
		}
		return $out;
	}

	/** Product category ids that exist. */
	public static function sanitize_cats( $in ): array {
		$out = array();
		foreach ( is_array( $in ) ? $in : array() as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
				$t = get_term( $id, 'product_cat' );
				if ( $t instanceof WP_Term ) {
					$out[] = $id;
				}
			}
		}
		return $out;
	}

	public static function save_formats( array $in ): void {
		$clean = self::sanitize_formats( $in );
		update_option( self::OPT_FORMATS, $clean, true );
		self::$formats = $clean;
	}

	/* =====================================================================
	 * Rejected suggestions
	 * =================================================================== */

	public static function rejected(): array {
		$raw = get_option( self::OPT_REJECTED, array() );
		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_string' ) ) : array();
	}

	public static function reject( string $signature ): void {
		if ( ! preg_match( '/^[0-9a-f]{12}$/', $signature ) ) {
			return;
		}
		$list = self::rejected();
		if ( ! in_array( $signature, $list, true ) ) {
			$list[] = $signature;
		}
		$list = array_slice( $list, -self::MAX_REJECTED );
		update_option( self::OPT_REJECTED, $list, false );
	}

	public static function clear_rejected(): void {
		delete_option( self::OPT_REJECTED );
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	public static function clip( string $s, int $max ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}
}

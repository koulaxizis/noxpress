<?php
/**
 * HMS_Settings — the hms_settings option (autoload: read when a [products]
 * shortcode renders), strict validation with whitelists (Bible §11).
 *
 *  - alias    a second prefix for the built-in markers (e.g. "glk" makes
 *             glk-exclude-{ID} work like hs-exclude-{ID}); '' = none
 *  - presets  list of named markers, max MAX_PRESETS:
 *      name       the class written in the shortcode (e.g. glk-releases)
 *      cats       product_cat term ids whose products are left out
 *      preorders  leave pre-orders out
 *      title      keep only products whose title contains this text
 *      ids        product ids left out
 */

defined( 'ABSPATH' ) || exit;

final class HMS_Settings {

	const OPT         = 'hms_settings';
	const MAX_PRESETS = 20;
	const MAX_IDS     = 100;
	const MAX_TITLE   = 100;

	/** @var array|null Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'alias'   => '',
			'presets' => array(),
		);
	}

	/** An empty preset: changes nothing until a field is set. */
	public static function preset_defaults(): array {
		return array(
			'name'      => '',
			'cats'      => array(),
			'preorders' => false,
			'title'     => '',
			'ids'       => array(),
		);
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPT, array() );
			self::$cache = self::sanitize( is_array( $raw ) ? $raw : array() );
		}
		return self::$cache;
	}

	public static function save( array $s ): void {
		$s           = self::sanitize( $s, true );
		self::$cache = $s;
		update_option( self::OPT, $s, true );
	}

	/** Drops the per-request cache (tests). */
	public static function flush(): void {
		self::$cache = null;
	}

	/** A valid alias prefix, or ''. */
	public static function clean_alias( $raw ): string {
		$a = is_scalar( $raw ) ? strtolower( trim( (string) $raw ) ) : '';
		$a = rtrim( $a, '-' );
		if ( 'hs' === $a || ! preg_match( '/^[a-z][a-z0-9]{0,19}$/', $a ) ) {
			return '';
		}
		return $a;
	}

	/** True when $name is a valid preset name (format only). */
	public static function valid_name( string $name ): bool {
		return strlen( $name ) >= 3 && strlen( $name ) <= 60 && (bool) preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name );
	}

	/**
	 * Whitelist validation: unknown keys are dropped, bad values fall back
	 * to the defaults. Presets with a bad name, a name a built-in marker
	 * already uses, a duplicate name or no condition are dropped. With
	 * $check (on save), categories that do not exist are dropped; reads
	 * skip that query.
	 */
	public static function sanitize( array $raw, bool $check = false ): array {
		$s          = self::defaults();
		$s['alias'] = self::clean_alias( $raw['alias'] ?? '' );

		$seen = array();
		if ( isset( $raw['presets'] ) && is_array( $raw['presets'] ) ) {
			foreach ( $raw['presets'] as $row ) {
				$p = is_array( $row ) ? self::preset( $row, $s['alias'], $check ) : null;
				if ( null === $p || isset( $seen[ $p['name'] ] ) ) {
					continue;
				}
				$seen[ $p['name'] ] = true;
				$s['presets'][]     = $p;
				if ( count( $s['presets'] ) >= self::MAX_PRESETS ) {
					break;
				}
			}
		}
		return $s;
	}

	/** One preset, or null when it is invalid or does nothing. */
	public static function preset( array $row, string $alias, bool $check = false ): ?array {
		$name = isset( $row['name'] ) && is_scalar( $row['name'] ) ? strtolower( trim( (string) $row['name'] ) ) : '';
		if ( ! self::valid_name( $name ) || HMS_Markers::is_builtin( $name, $alias ) ) {
			return null;
		}
		$p         = self::preset_defaults();
		$p['name'] = $name;

		$p['cats'] = self::int_list( $row['cats'] ?? array(), self::MAX_IDS );
		if ( $check && $p['cats'] && taxonomy_exists( 'product_cat' ) ) {
			$p['cats'] = array_values(
				array_filter(
					$p['cats'],
					static function ( $id ) {
						return (bool) term_exists( $id, 'product_cat' );
					}
				)
			);
		}
		$p['preorders'] = ! empty( $row['preorders'] );

		$title = isset( $row['title'] ) && is_scalar( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '';
		if ( function_exists( 'mb_substr' ) ) {
			$title = mb_substr( $title, 0, self::MAX_TITLE );
		} else {
			$title = substr( $title, 0, self::MAX_TITLE );
		}
		$p['title'] = trim( $title );

		$p['ids'] = self::int_list( $row['ids'] ?? array(), self::MAX_IDS );

		return self::preset_does_something( $p ) ? $p : null;
	}

	/**
	 * Positive, unique ints from an array or a comma / space separated
	 * string (the ids field).
	 */
	public static function int_list( $raw, int $max ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		}
		$out = array();
		foreach ( (array) $raw as $v ) {
			if ( ! is_scalar( $v ) || ! preg_match( '/^\d{1,19}$/', trim( (string) $v ) ) ) {
				continue;
			}
			$i = (int) $v;
			if ( $i > 0 ) {
				$out[ $i ] = $i;
			}
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return array_values( $out );
	}

	/** True when the preset changes the query at all. */
	public static function preset_does_something( array $p ): bool {
		return ! empty( $p['cats'] ) || ! empty( $p['preorders'] ) || '' !== $p['title'] || ! empty( $p['ids'] );
	}
}

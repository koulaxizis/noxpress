<?php
/**
 * GLP_Settings — the glp_settings option and its strict validation.
 *
 * Keys:
 *  - slugs       bool   convert new slugs (posts, terms, users…)
 *  - mode        string 'simple' | 'phonetic'
 *  - stop        bool   drop articles, «και» and short prepositions from new slugs
 *  - files       bool   convert the names of new uploads (off by default)
 *  - files_lower bool   lower case file names
 *  - redirect    bool   301 for Greek links that would be a 404
 *  - rules       array  list of array( greek, latin ) (whole words)
 *  - keep_map    bool   keep the redirect table (glp_map) on uninstall
 *
 * Autoloaded: the slug filter reads it whenever WordPress builds a slug.
 */

defined( 'ABSPATH' ) || exit;

final class GLP_Settings {

	const OPT       = 'glp_settings';
	const MAX_RULES = 300;

	/** Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'slugs'       => true,
			'mode'        => 'phonetic',
			'stop'        => false,
			'files'       => false,
			'files_lower' => true,
			'redirect'    => true,
			'rules'       => array(),
			'keep_map'    => false,
		);
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPT, array() );
			self::$cache = self::sanitize( is_array( $raw ) ? $raw : array() );
		}
		return self::$cache;
	}

	public static function save( array $raw ): void {
		$clean = self::sanitize( $raw );
		update_option( self::OPT, $clean, true );
		self::$cache = $clean;
	}

	/** Strict validation (forms, import, stored value). */
	public static function sanitize( array $raw ): array {
		$d = self::defaults();
		$s = array();
		foreach ( array( 'slugs', 'stop', 'files', 'files_lower', 'redirect', 'keep_map' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}
		$s['mode']  = isset( $raw['mode'] ) && in_array( $raw['mode'], array( 'simple', 'phonetic' ), true ) ? $raw['mode'] : $d['mode'];
		$s['rules'] = self::sanitize_rules( isset( $raw['rules'] ) ? $raw['rules'] : array() );
		return $s;
	}

	/**
	 * Rules from a stored list or from the textarea («ελληνικά = latin» per
	 * line; "=", "→" or "->"). The left side must be one Greek word, the right
	 * side Latin letters, digits and '-'. Later lines win; invalid lines are
	 * dropped (count_invalid_rules() reports them to the form).
	 *
	 * @param mixed $raw array of array( greek, latin ) or string.
	 */
	public static function sanitize_rules( $raw ): array {
		$pairs = array();
		if ( is_string( $raw ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
				$pair = self::parse_rule_line( $line );
				if ( null !== $pair ) {
					$pairs[] = $pair;
				}
			}
		} elseif ( is_array( $raw ) ) {
			foreach ( $raw as $p ) {
				if ( is_array( $p ) && isset( $p[0], $p[1] ) && is_string( $p[0] ) && is_string( $p[1] ) ) {
					$pair = self::parse_rule_line( $p[0] . ' = ' . $p[1] );
					if ( null !== $pair ) {
						$pairs[] = $pair;
					}
				}
			}
		}
		$out = array();
		foreach ( $pairs as $p ) {
			$out[ GLP_Converter::key( $p[0] ) ] = $p;
		}
		return array_slice( array_values( $out ), 0, self::MAX_RULES );
	}

	/** array( greek, latin ) or null for an empty / invalid line. */
	public static function parse_rule_line( string $line ): ?array {
		$line = trim( $line );
		if ( '' === $line ) {
			return null;
		}
		$parts = preg_split( '/\s*(?:=|→|->)\s*/u', $line, 2 );
		if ( ! is_array( $parts ) || 2 !== count( $parts ) ) {
			return null;
		}
		$greek = trim( sanitize_text_field( $parts[0] ) );
		$latin = trim( (string) $parts[1] );
		if ( '' === GLP_Converter::key( $greek ) || ! preg_match( '/^[A-Za-z0-9-]{1,80}$/', $latin ) ) {
			return null;
		}
		return array( $greek, $latin );
	}

	/** Non-empty textarea lines that sanitize_rules() drops. */
	public static function count_invalid_rules( string $raw ): int {
		$n = 0;
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			if ( '' !== trim( $line ) && null === self::parse_rule_line( $line ) ) {
				$n++;
			}
		}
		return $n;
	}

	/** Rules as the textarea shows them. */
	public static function rules_text( array $rules ): string {
		$lines = array();
		foreach ( $rules as $p ) {
			$lines[] = $p[0] . ' = ' . $p[1];
		}
		return implode( "\n", $lines );
	}

	/** Rules as the converter wants them: key => latin. */
	public static function rule_map( array $rules ): array {
		$map = array();
		foreach ( $rules as $p ) {
			$map[ GLP_Converter::key( $p[0] ) ] = $p[1];
		}
		return $map;
	}

	/** Converter options for slugs, from a settings array. */
	public static function slug_opt( ?array $s = null ): array {
		$s = null === $s ? self::get() : $s;
		return array(
			'mode'  => $s['mode'],
			'rules' => self::rule_map( $s['rules'] ),
			'stop'  => $s['stop'],
			'lower' => true,
		);
	}

	/** Converter options for file names, from a settings array. */
	public static function file_opt( ?array $s = null ): array {
		$s = null === $s ? self::get() : $s;
		return array(
			'mode'  => $s['mode'],
			'rules' => self::rule_map( $s['rules'] ),
			'lower' => $s['files_lower'],
		);
	}

	/**
	 * First activation: when AutoConvert Greeklish Permalinks left its
	 * settings, take the same style (agp_diphthongs), so new slugs look like
	 * the old ones. Read once, never written.
	 */
	public static function first_run(): void {
		if ( false !== get_option( self::OPT, false ) ) {
			return;
		}
		$s   = self::defaults();
		$agp = get_option( 'agp_diphthongs', false );
		if ( false !== $agp ) {
			$s['mode'] = 'enabled' === $agp ? 'phonetic' : 'simple';
		}
		self::save( $s );
	}
}

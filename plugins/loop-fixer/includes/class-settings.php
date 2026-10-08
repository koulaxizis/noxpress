<?php
/**
 * LF_Settings — single option `lf_settings` (JSON, autoloaded: it is read
 * on every front-end request and stays small).
 *
 * Shape:
 *  {
 *    "enabled":   bool,                 master switch (default false)
 *    "test_mode": bool,                 changes visible to shop managers only (default true)
 *    "themes": {
 *      "<stylesheet>": {
 *        "areas":        { "<area id>": { area config } },
 *        "text_fixes":   [ { "find": str, "replace": "wc_title"|"custom", "custom": str } ],
 *        "fingerprints": { "<theme-relative path>": "<sha1>" },
 *        "suspended":    [ "<area id>", … ]
 *      }
 *    }
 *  }
 *
 * Settings are stored per theme (stylesheet): with another theme active,
 * no area applies (Bible §14 — safe theme switching).
 *
 * Every value is validated against a whitelist on save AND on import
 * (one implementation, Bible §11).
 */

defined( 'ABSPATH' ) || exit;

final class LF_Settings {

	const OPT = 'lf_settings';

	const MODES   = array( 'off', 'inject', 'replace' );
	const PARTS   = array( 'rating', 'price', 'button' );
	const ANCHORS = array( 'title', 'thumbnail' );
	const TAGS    = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'p', 'div', 'span' );
	const ALIGNS  = array( '', 'left', 'center', 'right' );
	const STYLE_COLORS = array( 'price', 'price_hover', 'btn_bg', 'btn_text' );

	const MAX_FILE_AREAS = 20;
	const MAX_TEXT_FIXES = 10;
	const MAX_FIND_LEN   = 200;
	const MAX_CUSTOM_LEN = 200;
	const MAX_CSS_LEN    = 5120;

	/** Per-request cache of the decoded option. */
	private static $cache = null;

	/* =====================================================================
	 * Defaults
	 * =================================================================== */

	public static function defaults(): array {
		return array(
			'enabled'   => false,
			'test_mode' => true,
			'themes'    => array(),
		);
	}

	public static function theme_defaults(): array {
		return array(
			'areas'        => array(),
			'text_fixes'   => array(),
			'fingerprints' => array(),
			'suspended'    => array(),
		);
	}

	public static function area_defaults(): array {
		return array(
			'mode'   => 'off',
			'parts'  => array( 'price', 'button' ),
			'anchor' => 'title',
			'tag'    => 'h3',
			'card'   => '',
			'style'  => array(
				'price'       => '',
				'price_hover' => '',
				'btn_bg'      => '',
				'btn_text'    => '',
				'align'       => '',
				'gap'         => '',
			),
			'css'    => '',
		);
	}

	/* =====================================================================
	 * Read
	 * =================================================================== */

	public static function get(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$raw     = get_option( self::OPT, '' );
		$decoded = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		// Stored data was validated on write; sanitize again anyway (cheap, defensive).
		self::$cache = is_array( $decoded ) ? self::sanitize_all( $decoded ) : self::defaults();
		return self::$cache;
	}

	/** Active theme key (child theme stylesheet when a child is active). */
	public static function theme_key(): string {
		return (string) get_stylesheet();
	}

	/** Settings block of the active theme (defaults when none). */
	public static function theme( ?string $key = null ): array {
		$all = self::get();
		$key = null === $key ? self::theme_key() : $key;
		return isset( $all['themes'][ $key ] ) ? $all['themes'][ $key ] : self::theme_defaults();
	}

	/** Config of one area of the active theme (defaults when unset). */
	public static function area( string $id ): array {
		$t = self::theme();
		return isset( $t['areas'][ $id ] ) ? $t['areas'][ $id ] : self::area_defaults();
	}

	public static function is_suspended( string $id ): bool {
		$t = self::theme();
		return in_array( $id, $t['suspended'], true );
	}

	/** Mode of an area, 'off' when suspended. */
	public static function mode( string $id ): string {
		if ( self::is_suspended( $id ) ) {
			return 'off';
		}
		$a = self::area( $id );
		return $a['mode'];
	}

	/** True when the active theme has at least one area that is not off. */
	public static function has_active_areas(): bool {
		$t = self::theme();
		foreach ( $t['areas'] as $id => $a ) {
			if ( 'off' !== $a['mode'] && ! in_array( (string) $id, $t['suspended'], true ) ) {
				return true;
			}
		}
		return false;
	}

	/* =====================================================================
	 * Write
	 * =================================================================== */

	public static function save( array $data ): bool {
		$clean = self::sanitize_all( $data );
		self::$cache = $clean;
		return false !== update_option( self::OPT, wp_json_encode( $clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), true );
	}

	/** Replace the settings block of one theme (others untouched). */
	public static function save_theme( array $theme, ?string $key = null ): bool {
		$all = self::get();
		$key = null === $key ? self::theme_key() : $key;
		$all['themes'][ $key ] = $theme;
		return self::save( $all );
	}

	/** Drop the per-request cache (after external option changes). */
	public static function flush(): void {
		self::$cache = null;
	}

	/* =====================================================================
	 * Validation (one implementation — admin POST, import, read)
	 * =================================================================== */

	public static function sanitize_all( array $raw ): array {
		$out = self::defaults();

		$out['enabled']   = ! empty( $raw['enabled'] );
		$out['test_mode'] = array_key_exists( 'test_mode', $raw ) ? ! empty( $raw['test_mode'] ) : true;

		if ( isset( $raw['themes'] ) && is_array( $raw['themes'] ) ) {
			foreach ( $raw['themes'] as $key => $theme ) {
				$key = self::valid_theme_key( (string) $key );
				if ( '' === $key || ! is_array( $theme ) ) {
					continue;
				}
				$out['themes'][ $key ] = self::sanitize_theme( $theme );
			}
		}
		return $out;
	}

	public static function valid_theme_key( string $key ): string {
		return 1 === preg_match( '/^[A-Za-z0-9._-]{1,100}$/', $key ) ? $key : '';
	}

	public static function sanitize_theme( array $raw ): array {
		$out = self::theme_defaults();

		if ( isset( $raw['areas'] ) && is_array( $raw['areas'] ) ) {
			$files = 0;
			foreach ( $raw['areas'] as $id => $area ) {
				$id = self::valid_area_id( (string) $id );
				if ( '' === $id || ! is_array( $area ) ) {
					continue;
				}
				$is_file = LF_Areas::is_file_area( $id );
				if ( $is_file && ++$files > self::MAX_FILE_AREAS ) {
					continue;
				}
				$out['areas'][ $id ] = self::sanitize_area( $area, $id );
			}
		}

		if ( isset( $raw['text_fixes'] ) && is_array( $raw['text_fixes'] ) ) {
			foreach ( $raw['text_fixes'] as $fix ) {
				if ( count( $out['text_fixes'] ) >= self::MAX_TEXT_FIXES ) {
					break;
				}
				$fix = is_array( $fix ) ? self::sanitize_text_fix( $fix ) : null;
				if ( null !== $fix ) {
					$out['text_fixes'][] = $fix;
				}
			}
		}

		if ( isset( $raw['fingerprints'] ) && is_array( $raw['fingerprints'] ) ) {
			foreach ( $raw['fingerprints'] as $path => $hash ) {
				$path = self::valid_rel_path( (string) $path );
				if ( '' !== $path && is_string( $hash ) && 1 === preg_match( '/^[a-f0-9]{40}$/', $hash ) ) {
					$out['fingerprints'][ $path ] = $hash;
				}
			}
		}

		if ( isset( $raw['suspended'] ) && is_array( $raw['suspended'] ) ) {
			foreach ( $raw['suspended'] as $id ) {
				$id = self::valid_area_id( (string) $id );
				if ( '' !== $id && ! in_array( $id, $out['suspended'], true ) ) {
					$out['suspended'][] = $id;
				}
			}
		}

		return $out;
	}

	/** Area ids: built-in ids or "file:<theme-relative .php path>". */
	public static function valid_area_id( string $id ): string {
		if ( isset( LF_Areas::builtin()[ $id ] ) ) {
			return $id;
		}
		if ( 0 === strpos( $id, LF_Areas::FILE_PREFIX ) ) {
			$path = self::valid_rel_path( substr( $id, strlen( LF_Areas::FILE_PREFIX ) ) );
			return '' === $path ? '' : LF_Areas::FILE_PREFIX . $path;
		}
		return '';
	}

	/**
	 * Theme-relative PHP path: letters, digits, "_", "-", ".", "/"; no
	 * leading slash, no "..", must end in ".php". Existence is checked by
	 * the admin UI when an area is added (the setting itself may outlive a
	 * theme file, e.g. after an update — the detector reports it).
	 */
	public static function valid_rel_path( string $path ): string {
		$path = str_replace( '\\', '/', trim( $path ) );
		if ( '' === $path || strlen( $path ) > 200 ) {
			return '';
		}
		if ( 1 !== preg_match( '#^[A-Za-z0-9_\-][A-Za-z0-9_\-./]*\.php$#', $path ) ) {
			return '';
		}
		if ( false !== strpos( $path, '..' ) || false !== strpos( $path, '//' ) ) {
			return '';
		}
		return $path;
	}

	public static function sanitize_area( array $raw, string $id ): array {
		$out   = self::area_defaults();
		$modes = LF_Areas::modes_for( $id );

		$mode        = isset( $raw['mode'] ) ? (string) $raw['mode'] : 'off';
		$out['mode'] = in_array( $mode, $modes, true ) ? $mode : 'off';

		if ( isset( $raw['parts'] ) && is_array( $raw['parts'] ) ) {
			$parts = array();
			foreach ( self::PARTS as $p ) { // Keep canonical order.
				if ( in_array( $p, $raw['parts'], true ) ) {
					$parts[] = $p;
				}
			}
			$out['parts'] = $parts;
		}

		$anchor        = isset( $raw['anchor'] ) ? (string) $raw['anchor'] : 'title';
		$out['anchor'] = in_array( $anchor, self::ANCHORS, true ) ? $anchor : 'title';

		$tag        = isset( $raw['tag'] ) ? strtolower( (string) $raw['tag'] ) : 'h3';
		$out['tag'] = in_array( $tag, self::TAGS, true ) ? $tag : 'h3';

		$out['card'] = self::valid_selector( isset( $raw['card'] ) ? (string) $raw['card'] : '' );

		$style = isset( $raw['style'] ) && is_array( $raw['style'] ) ? $raw['style'] : array();
		foreach ( self::STYLE_COLORS as $k ) {
			$c                   = isset( $style[ $k ] ) ? sanitize_hex_color( (string) $style[ $k ] ) : '';
			$out['style'][ $k ] = is_string( $c ) ? $c : '';
		}
		$align                 = isset( $style['align'] ) ? (string) $style['align'] : '';
		$out['style']['align'] = in_array( $align, self::ALIGNS, true ) ? $align : '';
		$gap                   = isset( $style['gap'] ) ? trim( (string) $style['gap'] ) : '';
		$out['style']['gap']   = ( '' !== $gap && 1 === preg_match( '/^\d{1,3}$/', $gap ) ) ? $gap : '';

		$css        = isset( $raw['css'] ) ? (string) $raw['css'] : '';
		$out['css'] = self::valid_css( $css );

		return $out;
	}

	/**
	 * Plain CSS selector used only to scope the hover rule. No braces,
	 * semicolons, angle brackets, quotes, backslashes or at-rules.
	 */
	public static function valid_selector( string $sel ): string {
		$sel = trim( $sel );
		if ( '' === $sel || strlen( $sel ) > 120 ) {
			return '';
		}
		return 1 === preg_match( '/^[A-Za-z0-9_\-.#\s,:()\[\]=*>+~]+$/', $sel ) && false === strpos( $sel, '@' ) ? $sel : '';
	}

	/** Custom CSS: tags stripped, no "</" sequences, length cap. */
	public static function valid_css( string $css ): string {
		$css = wp_strip_all_tags( $css );
		$css = str_replace( array( '</', '<' ), '', $css );
		$css = trim( $css );
		if ( strlen( $css ) > self::MAX_CSS_LEN ) {
			$css = substr( $css, 0, self::MAX_CSS_LEN );
		}
		return $css;
	}

	public static function sanitize_text_fix( array $raw ): ?array {
		$find = isset( $raw['find'] ) ? trim( (string) $raw['find'] ) : '';
		if ( '' === $find || mb_strlen( $find ) > self::MAX_FIND_LEN ) {
			return null;
		}
		$replace = isset( $raw['replace'] ) && 'custom' === $raw['replace'] ? 'custom' : 'wc_title';
		$custom  = isset( $raw['custom'] ) ? sanitize_text_field( (string) $raw['custom'] ) : '';
		if ( mb_strlen( $custom ) > self::MAX_CUSTOM_LEN ) {
			$custom = mb_substr( $custom, 0, self::MAX_CUSTOM_LEN );
		}
		return array(
			'find'    => $find,
			'replace' => $replace,
			'custom'  => $custom,
		);
	}
}

<?php
/**
 * TP_Settings — single option `tp_settings` (JSON, autoloaded: it is read
 * on every front-end request and stays small).
 *
 * Shape:
 *  {
 *    "enabled":   bool,                 master switch (default false)
 *    "test_mode": bool,                 changes visible to shop managers only (default true)
 *    "themes": {
 *      "<stylesheet>": {
 *        "areas":  { "<area id>": { area config } },
 *        "texts":  [ { "find": str, "replace": "wc_title"|"custom", "custom": html, "scope": "all"|"shop"|"home"|"product" } ],
 *        "page":   { "same_tab": bool, "img_alt": bool, "aria": bool, "cat_img_size": size,
 *                    "remove": [ "tag.class" | ".class" | "#id", … ], "css": str },
 *        "guard":  bool,                theme settings cannot be written by page views
 *        "local_files": bool,           theme reads its own files from disk, not over HTTP
 *        "mods":   { "<theme setting>": value },   values forced on the front end
 *        "cats":   { "fallback": bool, "source": "recent"|"popular",
 *                    "lists": [ { "file": path, "include": [ term ids ], "hide_empty": bool } ] },
 *        "fingerprints": { "<theme-relative path>": "<sha1>" },
 *        "suspended":    [ "<area id>", … ]
 *      }
 *    }
 *  }
 *
 * Settings are stored per theme (stylesheet): with another theme active,
 * nothing applies (Bible §14 — safe theme switching).
 *
 * Every value is validated against a whitelist on save AND on import
 * (one implementation, Bible §11).
 */

defined( 'ABSPATH' ) || exit;

final class TP_Settings {

	const OPT = 'tp_settings';

	const MODES   = array( 'off', 'inject', 'replace' );
	const PARTS   = array( 'sale_badge', 'rating', 'price', 'button' );
	const ANCHORS = array( 'title', 'thumbnail' );
	const TAGS    = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'p', 'div', 'span' );
	const ALIGNS  = array( '', 'left', 'center', 'right' );
	const STYLE_COLORS = array( 'price', 'price_hover', 'btn_bg', 'btn_text', 'badge_bg', 'badge_text' );
	const SCOPES  = array( 'all', 'shop', 'home', 'product' );
	const SOURCES = array( 'recent', 'popular' );

	const MAX_FILE_AREAS = 20;
	const MAX_TEXTS      = 40;
	const MAX_FIND_LEN   = 300;
	const MAX_CUSTOM_LEN = 500;
	const MAX_CSS_LEN    = 5120;
	const MAX_CAT_LISTS  = 5;
	const MAX_CAT_IDS    = 200;
	const MAX_REMOVE     = 10;
	const MAX_MODS       = 30;
	const MAX_MOD_LEN    = 500;

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
			'texts'        => array(),
			'page'         => self::page_defaults(),
			'guard'        => false,
			'local_files'  => false,
			'mods'         => array(),
			'cats'         => array(
				'fallback' => false,
				'source'   => 'recent',
				'lists'    => array(),
			),
			'fingerprints' => array(),
			'suspended'    => array(),
		);
	}

	public static function page_defaults(): array {
		return array(
			'same_tab'     => false,
			'img_alt'      => false,
			'aria'         => false,
			'cat_img_size' => '',
			'remove'       => array(),
			'css'          => '',
		);
	}

	public static function area_defaults(): array {
		return array(
			'mode'       => 'off',
			'parts'      => array( 'price', 'button' ),
			'hooks'      => false,
			'anchor'     => 'title',
			'tag'        => 'h3',
			'card'       => '',
			'badge_hide' => '',
			'thumb'      => '',
			'title'      => array(
				'sel'   => '',
				'lines' => '',
				'size'  => '',
			),
			'style'      => array(
				'price'       => '',
				'price_hover' => '',
				'btn_bg'      => '',
				'btn_text'    => '',
				'badge_bg'    => '',
				'badge_text'  => '',
				'align'       => '',
				'gap'         => '',
			),
			'css'        => '',
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

	/** True when page-level features (texts / HTML rules / CSS) are configured. */
	public static function has_page_features(): bool {
		$t = self::theme();
		$p = $t['page'];
		return ! empty( $t['texts'] ) || $p['same_tab'] || $p['img_alt'] || $p['aria'] || '' !== $p['cat_img_size'] || ! empty( $p['remove'] ) || '' !== $p['css'];
	}

	/** True when anything at all is configured for the active theme. */
	public static function has_anything(): bool {
		$t = self::theme();
		return self::has_active_areas() || self::has_page_features() || $t['guard'] || $t['local_files'] || ! empty( $t['mods'] ) || $t['cats']['fallback'] || ! empty( $t['cats']['lists'] );
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
				$is_file = TP_Areas::is_file_area( $id );
				if ( $is_file && ++$files > self::MAX_FILE_AREAS ) {
					continue;
				}
				$out['areas'][ $id ] = self::sanitize_area( $area, $id );
			}
		}

		$texts = isset( $raw['texts'] ) && is_array( $raw['texts'] ) ? $raw['texts'] : array();
		foreach ( $texts as $fix ) {
			if ( count( $out['texts'] ) >= self::MAX_TEXTS ) {
				break;
			}
			$fix = is_array( $fix ) ? self::sanitize_text( $fix ) : null;
			if ( null !== $fix ) {
				$out['texts'][] = $fix;
			}
		}

		if ( isset( $raw['page'] ) && is_array( $raw['page'] ) ) {
			$out['page'] = self::sanitize_page( $raw['page'] );
		}

		$out['guard']       = ! empty( $raw['guard'] );
		$out['local_files'] = ! empty( $raw['local_files'] );
		$out['mods']        = self::sanitize_mods( isset( $raw['mods'] ) && is_array( $raw['mods'] ) ? $raw['mods'] : array() );

		if ( isset( $raw['cats'] ) && is_array( $raw['cats'] ) ) {
			$out['cats'] = self::sanitize_cats( $raw['cats'] );
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
		if ( isset( TP_Areas::builtin()[ $id ] ) ) {
			return $id;
		}
		if ( 0 === strpos( $id, TP_Areas::FILE_PREFIX ) ) {
			$path = self::valid_rel_path( substr( $id, strlen( TP_Areas::FILE_PREFIX ) ) );
			return '' === $path ? '' : TP_Areas::FILE_PREFIX . $path;
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

	/** Image size name (existence is checked at run time). */
	public static function valid_size( string $size ): string {
		$size = trim( $size );
		return 1 === preg_match( '/^[A-Za-z0-9_-]{1,60}$/', $size ) ? $size : '';
	}

	/** Registered image size (core sizes included). */
	public static function size_exists( string $size ): bool {
		return '' !== $size && in_array( $size, get_intermediate_image_sizes(), true );
	}

	public static function sanitize_area( array $raw, string $id ): array {
		$out   = self::area_defaults();
		$modes = TP_Areas::modes_for( $id );

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

		$out['hooks'] = ! empty( $raw['hooks'] );

		$anchor        = isset( $raw['anchor'] ) ? (string) $raw['anchor'] : 'title';
		$out['anchor'] = in_array( $anchor, self::ANCHORS, true ) ? $anchor : 'title';

		$tag        = isset( $raw['tag'] ) ? strtolower( (string) $raw['tag'] ) : 'h3';
		$out['tag'] = in_array( $tag, self::TAGS, true ) ? $tag : 'h3';

		$out['card']       = self::valid_selector( isset( $raw['card'] ) ? (string) $raw['card'] : '' );
		$out['badge_hide'] = self::valid_selector( isset( $raw['badge_hide'] ) ? (string) $raw['badge_hide'] : '' );
		$out['thumb']      = self::valid_size( isset( $raw['thumb'] ) ? (string) $raw['thumb'] : '' );

		$title               = isset( $raw['title'] ) && is_array( $raw['title'] ) ? $raw['title'] : array();
		$out['title']['sel'] = self::valid_selector( isset( $title['sel'] ) ? (string) $title['sel'] : '' );
		$lines               = isset( $title['lines'] ) ? trim( (string) $title['lines'] ) : '';
		$out['title']['lines'] = ( 1 === preg_match( '/^[1-5]$/', $lines ) ) ? $lines : '';
		$size                = isset( $title['size'] ) ? trim( (string) $title['size'] ) : '';
		$out['title']['size'] = ( 1 === preg_match( '/^\d{1,2}$/', $size ) && (int) $size >= 8 ) ? $size : '';

		$style = isset( $raw['style'] ) && is_array( $raw['style'] ) ? $raw['style'] : array();
		foreach ( self::STYLE_COLORS as $k ) {
			$c                  = isset( $style[ $k ] ) ? sanitize_hex_color( (string) $style[ $k ] ) : '';
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

	public static function sanitize_page( array $raw ): array {
		$out                 = self::page_defaults();
		$out['same_tab']     = ! empty( $raw['same_tab'] );
		$out['img_alt']      = ! empty( $raw['img_alt'] );
		$out['aria']         = ! empty( $raw['aria'] );
		$out['cat_img_size'] = self::valid_size( isset( $raw['cat_img_size'] ) ? (string) $raw['cat_img_size'] : '' );
		$out['css']          = self::valid_css( isset( $raw['css'] ) ? (string) $raw['css'] : '' );
		foreach ( ( isset( $raw['remove'] ) && is_array( $raw['remove'] ) ) ? $raw['remove'] : array() as $sel ) {
			$sel = self::valid_simple_selector( (string) $sel );
			if ( '' !== $sel && ! in_array( $sel, $out['remove'], true ) && count( $out['remove'] ) < self::MAX_REMOVE ) {
				$out['remove'][] = $sel;
			}
		}
		return $out;
	}

	/**
	 * "tag.class", ".class", "tag#id" or "#id", optionally followed by
	 * ":empty" (removed only when it holds no text and no image / icon) —
	 * elements removed from the page HTML.
	 */
	public static function valid_simple_selector( string $sel ): string {
		$sel = trim( $sel );
		return 1 === preg_match( '/^([a-z][a-z0-9]{0,9})?[.#][A-Za-z_][A-Za-z0-9_-]{0,60}(:empty)?$/', $sel ) ? $sel : '';
	}

	/**
	 * Theme setting overrides: name => value. Values are plain text
	 * (numbers become integers) — the theme escapes them where it prints.
	 */
	public static function sanitize_mods( array $raw ): array {
		$out = array();
		foreach ( $raw as $name => $value ) {
			$name = (string) $name;
			if ( 1 !== preg_match( '/^[A-Za-z0-9_\-]{1,100}$/', $name ) || count( $out ) >= self::MAX_MODS || ! is_scalar( $value ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $value );
			if ( mb_strlen( $value ) > self::MAX_MOD_LEN ) {
				continue;
			}
			$out[ $name ] = 1 === preg_match( '/^-?\d{1,9}$/', $value ) ? (int) $value : $value;
		}
		return $out;
	}

	public static function sanitize_cats( array $raw ): array {
		$out = array(
			'fallback' => ! empty( $raw['fallback'] ),
			'source'   => isset( $raw['source'] ) && in_array( $raw['source'], self::SOURCES, true ) ? $raw['source'] : 'recent',
			'lists'    => array(),
		);
		$lists = isset( $raw['lists'] ) && is_array( $raw['lists'] ) ? $raw['lists'] : array();
		foreach ( $lists as $l ) {
			if ( ! is_array( $l ) || count( $out['lists'] ) >= self::MAX_CAT_LISTS ) {
				continue;
			}
			$file = self::valid_rel_path( isset( $l['file'] ) ? (string) $l['file'] : '' );
			if ( '' === $file ) {
				continue;
			}
			$ids = array();
			foreach ( ( isset( $l['include'] ) && is_array( $l['include'] ) ) ? $l['include'] : array() as $tid ) {
				$tid = (int) $tid;
				if ( $tid > 0 && ! in_array( $tid, $ids, true ) && count( $ids ) < self::MAX_CAT_IDS ) {
					$ids[] = $tid;
				}
			}
			$out['lists'][] = array(
				'file'       => $file,
				'include'    => $ids,
				'hide_empty' => ! empty( $l['hide_empty'] ),
			);
		}
		return $out;
	}

	/**
	 * Plain CSS selector (card, title, theme badge). No braces,
	 * semicolons, angle brackets, quotes, backslashes or at-rules.
	 */
	public static function valid_selector( string $sel ): string {
		$sel = trim( $sel );
		if ( '' === $sel || strlen( $sel ) > 120 ) {
			return '';
		}
		return 1 === preg_match( '/^[A-Za-z0-9_\-.#\s,:()\[\]=*>+~]+$/', $sel ) && false === strpos( $sel, '@' ) ? $sel : '';
	}

	/** Custom CSS: tags stripped, no "<" at all, length cap. */
	public static function valid_css( string $css ): string {
		$css = wp_strip_all_tags( $css );
		$css = str_replace( '<', '', $css );
		$css = trim( $css );
		if ( strlen( $css ) > self::MAX_CSS_LEN ) {
			$css = substr( $css, 0, self::MAX_CSS_LEN );
		}
		return $css;
	}

	/**
	 * Text table row. "find" is kept raw (it is only searched for); the
	 * custom replacement may hold simple HTML (links, emphasis) and goes
	 * through wp_kses_post(). An empty custom text removes the found text.
	 */
	public static function sanitize_text( array $raw ): ?array {
		$find = isset( $raw['find'] ) ? trim( (string) $raw['find'] ) : '';
		if ( '' === $find || mb_strlen( $find ) > self::MAX_FIND_LEN ) {
			return null;
		}
		$replace = isset( $raw['replace'] ) && 'wc_title' === $raw['replace'] ? 'wc_title' : 'custom';
		$custom  = isset( $raw['custom'] ) ? trim( wp_kses_post( (string) $raw['custom'] ) ) : '';
		if ( mb_strlen( $custom ) > self::MAX_CUSTOM_LEN ) {
			$custom = '';
		}
		$scope = isset( $raw['scope'] ) && in_array( $raw['scope'], self::SCOPES, true ) ? $raw['scope'] : 'all';
		return array(
			'find'    => $find,
			'replace' => $replace,
			'custom'  => $custom,
			'scope'   => $scope,
		);
	}
}

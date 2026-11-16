<?php
/**
 * PTB_Settings — the two options, defaults and strict validation (Bible
 * §11, §12, §14.17).
 *
 * ptb_settings (autoload: read on product pages):
 *  - mode        'off' (default) | 'test' (shop managers only) | 'live'
 *  - lang        'auto' | 'el' | 'en' (visitor texts)
 *  - thumbs      poster image of YouTube videos from i.ytimg.com (on)
 *  - bridge      [wb_cpt_youtube_embed_shortcode] while "Custom Product
 *                Tabs for WooCommerce" is inactive (on)
 *  - hide_wb     while both run, hide that plugin's tabs on products
 *                already imported (on)
 *  - purge       clear page caches on save (on)
 *  - wipe        delete the tabs on uninstall (off)
 *  - texts       visitor texts {key: {el, en}}: trailer, presentation
 *                (default titles of video tabs), play ("Play: %s")
 *  - order       known tabs of the page {key: {priority, hide, el, en}}:
 *                priority 0 = keep, el / en = new title ('' = keep)
 *
 * ptb_shared (autoload: read on product pages, small):
 *  - tabs  up to MAX_SHARED shared tabs:
 *      id, on, type ('text' | 'video'), title_el, title_en, content_el,
 *      content_en, video ('yt:ID' | 'vm:ID'), priority,
 *      all (every product), cats[], tags[] (term ids), kinds[]
 *      (simple, variable, grouped, external, downloadable, virtual),
 *      exclude[] (product ids)
 *
 * Content is HTML: kept as written for users with unfiltered_html, else
 * through wp_kses_post (Bible §11 security policy).
 */

defined( 'ABSPATH' ) || exit;

final class PTB_Settings {

	const OPT        = 'ptb_settings';
	const OPT_SHARED = 'ptb_shared';
	const MAX_SHARED = 30;
	const MAX_TITLE  = 80;
	const MAX_HTML   = 65535;
	const MAX_IDS    = 200;

	const KINDS = array( 'simple', 'variable', 'grouped', 'external', 'downloadable', 'virtual' );

	/** Built-in visitor texts: key => [ el, en ]. */
	const TEXTS = array(
		'trailer'      => array( 'Δείτε το βίντεο', 'Watch the video' ),
		'presentation' => array( 'Παρουσίαση βιβλίου', 'Book presentation' ),
		'play'         => array( 'Αναπαραγωγή: %s', 'Play: %s' ),
	);

	/** Tabs of WooCommerce and of the suite that the order setting knows. */
	const KNOWN = array( 'description', 'additional_information', 'reviews', 'tp_care', 'crt_creators', 'bpv_preview' );

	/** @var array|null Per-request caches. */
	private static $cache  = null;
	private static $shared = null;

	public static function defaults(): array {
		$texts = array();
		foreach ( self::TEXTS as $k => $v ) {
			$texts[ $k ] = array(
				'el' => '',
				'en' => '',
			);
		}
		$order = array();
		foreach ( self::KNOWN as $k ) {
			$order[ $k ] = self::order_defaults();
		}
		return array(
			'mode'    => 'off',
			'lang'    => 'auto',
			'thumbs'  => true,
			'bridge'  => true,
			'hide_wb' => true,
			'purge'   => true,
			'wipe'    => false,
			'texts'   => $texts,
			'order'   => $order,
		);
	}

	public static function order_defaults(): array {
		return array(
			'priority' => 0,
			'hide'     => false,
			'el'       => '',
			'en'       => '',
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
		self::$cache = self::sanitize( $s );
		update_option( self::OPT, self::$cache, true );
	}

	public static function flush(): void {
		self::$cache  = null;
		self::$shared = null;
	}

	public static function sanitize( array $raw ): array {
		$d = self::defaults();
		$s = array();

		$s['mode'] = isset( $raw['mode'] ) && in_array( $raw['mode'], array( 'off', 'test', 'live' ), true ) ? $raw['mode'] : $d['mode'];
		$s['lang'] = isset( $raw['lang'] ) && in_array( $raw['lang'], array( 'auto', 'el', 'en' ), true ) ? $raw['lang'] : $d['lang'];
		foreach ( array( 'thumbs', 'bridge', 'hide_wb', 'purge', 'wipe' ) as $k ) {
			$s[ $k ] = array_key_exists( $k, $raw ) ? (bool) $raw[ $k ] : $d[ $k ];
		}

		$s['texts'] = $d['texts'];
		foreach ( array_keys( self::TEXTS ) as $k ) {
			foreach ( array( 'el', 'en' ) as $l ) {
				$v = isset( $raw['texts'][ $k ][ $l ] ) && is_scalar( $raw['texts'][ $k ][ $l ] ) ? (string) $raw['texts'][ $k ][ $l ] : '';
				// The play label must keep its single %s.
				if ( 'play' === $k && '' !== $v && 1 !== substr_count( $v, '%s' ) ) {
					$v = '';
				}
				$s['texts'][ $k ][ $l ] = self::text_field( $v );
			}
		}

		$s['order'] = $d['order'];
		foreach ( self::KNOWN as $k ) {
			$o = isset( $raw['order'][ $k ] ) && is_array( $raw['order'][ $k ] ) ? $raw['order'][ $k ] : array();
			$s['order'][ $k ] = array(
				'priority' => self::int_in( $o['priority'] ?? 0, 0, 200, 0 ),
				'hide'     => ! empty( $o['hide'] ),
				'el'       => self::text_field( isset( $o['el'] ) && is_scalar( $o['el'] ) ? (string) $o['el'] : '' ),
				'en'       => self::text_field( isset( $o['en'] ) && is_scalar( $o['en'] ) ? (string) $o['en'] : '' ),
			);
		}
		return $s;
	}

	/* =====================================================================
	 * Shared tabs
	 * =================================================================== */

	public static function shared(): array {
		if ( null === self::$shared ) {
			$raw          = get_option( self::OPT_SHARED, array() );
			$raw          = is_array( $raw ) && isset( $raw['tabs'] ) && is_array( $raw['tabs'] ) ? $raw['tabs'] : array();
			self::$shared = array();
			foreach ( $raw as $row ) {
				// Stored values were checked on save: no kses pass on reads.
				$t = is_array( $row ) ? self::shared_tab( $row, false ) : null;
				if ( null !== $t ) {
					self::$shared[] = $t;
				}
			}
		}
		return self::$shared;
	}

	/**
	 * Saves the shared tabs. $raw_html: the HTML may stay as written (the
	 * current user has unfiltered_html), else wp_kses_post.
	 *
	 * @return int Tabs kept.
	 */
	public static function save_shared( array $rows, bool $raw_html ): int {
		$out  = array();
		$seen = array();
		foreach ( $rows as $row ) {
			$t = is_array( $row ) ? self::shared_tab( $row, ! $raw_html ) : null;
			if ( null === $t || isset( $seen[ $t['id'] ] ) ) {
				continue;
			}
			$seen[ $t['id'] ] = true;
			$out[]            = $t;
			if ( count( $out ) >= self::MAX_SHARED ) {
				break;
			}
		}
		update_option( self::OPT_SHARED, array( 'tabs' => $out ), true );
		self::$shared = $out;
		return count( $out );
	}

	public static function shared_defaults(): array {
		return array(
			'id'         => '',
			'on'         => true,
			'type'       => 'text',
			'title_el'   => '',
			'title_en'   => '',
			'content_el' => '',
			'content_en' => '',
			'video'      => '',
			'priority'   => 40,
			'all'        => false,
			'cats'       => array(),
			'tags'       => array(),
			'kinds'      => array(),
			'exclude'    => array(),
		);
	}

	/** One shared tab, or null when it has no title or nothing to show. */
	public static function shared_tab( array $row, bool $kses ): ?array {
		$t = self::shared_defaults();

		$t['id']       = isset( $row['id'] ) && is_string( $row['id'] ) && preg_match( '/^[a-f0-9]{8}$/', $row['id'] ) ? $row['id'] : self::new_id();
		$t['on']       = ! array_key_exists( 'on', $row ) || (bool) $row['on'];
		$t['type']     = isset( $row['type'] ) && 'video' === $row['type'] ? 'video' : 'text';
		$t['title_el'] = self::text_field( isset( $row['title_el'] ) && is_scalar( $row['title_el'] ) ? (string) $row['title_el'] : '' );
		$t['title_en'] = self::text_field( isset( $row['title_en'] ) && is_scalar( $row['title_en'] ) ? (string) $row['title_en'] : '' );
		foreach ( array( 'content_el', 'content_en' ) as $k ) {
			$t[ $k ] = self::html( isset( $row[ $k ] ) && is_scalar( $row[ $k ] ) ? (string) $row[ $k ] : '', $kses );
		}
		$t['video']    = PTB_Tabs::parse_video( isset( $row['video'] ) && is_scalar( $row['video'] ) ? (string) $row['video'] : '' );
		$t['priority'] = self::int_in( $row['priority'] ?? 40, 1, 200, 40 );
		$t['all']      = ! empty( $row['all'] );
		$t['cats']     = self::ids( $row['cats'] ?? array() );
		$t['tags']     = self::ids( $row['tags'] ?? array() );
		$t['exclude']  = self::ids( $row['exclude'] ?? array() );
		$kinds         = isset( $row['kinds'] ) && is_array( $row['kinds'] ) ? $row['kinds'] : array();
		$t['kinds']    = array_values( array_intersect( self::KINDS, array_map( 'strval', $kinds ) ) );

		if ( '' === $t['title_el'] ) {
			return null;
		}
		if ( 'video' === $t['type'] ? '' === $t['video'] : '' === trim( $t['content_el'] ) ) {
			return null;
		}
		return $t;
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	public static function new_id(): string {
		return bin2hex( random_bytes( 4 ) );
	}

	public static function clip( string $s, int $max ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	public static function text_field( string $s ): string {
		return self::clip( sanitize_text_field( $s ), self::MAX_TITLE );
	}

	/** HTML content: wp_kses_post when $kses, always cut at MAX_HTML bytes. */
	public static function html( string $s, bool $kses ): string {
		$s = $kses ? wp_kses_post( $s ) : $s;
		if ( strlen( $s ) > self::MAX_HTML ) {
			$s = function_exists( 'mb_strcut' ) ? mb_strcut( $s, 0, self::MAX_HTML ) : substr( $s, 0, self::MAX_HTML );
		}
		return $s;
	}

	public static function int_in( $v, int $min, int $max, int $default ): int {
		if ( is_int( $v ) ) {
			return max( $min, min( $max, $v ) );
		}
		if ( ! is_scalar( $v ) || ! preg_match( '/^\d+$/', trim( (string) $v ) ) ) {
			return $default;
		}
		return max( $min, min( $max, (int) $v ) );
	}

	/**
	 * A list of positive ids from an array or a "1, 2 3" string.
	 *
	 * @param mixed $v Raw value.
	 * @return int[]
	 */
	public static function ids( $v ): array {
		if ( is_string( $v ) ) {
			$v = preg_split( '/[\s,]+/', $v, -1, PREG_SPLIT_NO_EMPTY );
		}
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $x ) {
			if ( is_scalar( $x ) && preg_match( '/^\d+$/', trim( (string) $x ) ) && (int) $x > 0 ) {
				$out[ (int) $x ] = (int) $x;
			}
			if ( count( $out ) >= self::MAX_IDS ) {
				break;
			}
		}
		return array_values( $out );
	}

	/* =====================================================================
	 * Visitor language (Bible §14.17)
	 * =================================================================== */

	public static function front_lang(): string {
		$l = self::get()['lang'];
		if ( 'el' === $l || 'en' === $l ) {
			return $l;
		}
		return PTB_Lang::from_locale( (string) get_locale() );
	}

	/** A visitor text: the setting, else the built-in one; empty EN shows EL. */
	public static function text( string $key ): string {
		if ( ! isset( self::TEXTS[ $key ] ) ) {
			return '';
		}
		$t    = self::get()['texts'][ $key ];
		$lang = self::front_lang();
		if ( 'en' === $lang ) {
			$out = '' !== $t['en'] ? $t['en'] : ( '' !== $t['el'] ? $t['el'] : self::TEXTS[ $key ][1] );
		} else {
			$out = '' !== $t['el'] ? $t['el'] : self::TEXTS[ $key ][0];
		}
		$out = apply_filters( 'ptb_text', $out, $key, $lang );
		return is_string( $out ) ? $out : '';
	}

	/** One of a pair by the visitor language: empty EN falls back to EL. */
	public static function pick( string $el, string $en ): string {
		return 'en' === self::front_lang() && '' !== $en ? $en : $el;
	}
}

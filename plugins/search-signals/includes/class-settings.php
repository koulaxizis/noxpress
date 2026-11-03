<?php
/**
 * SSG_Settings — the ssg_settings option and its strict validation
 * (Bible §11 whitelists, §20 one option for every module; unknown keys
 * dropped, bad values → defaults).
 *
 * Keys:
 *  - mode        'off' | 'test' (default) | 'live'
 *  - mods        module => bool (meta, schema, social, sitemaps, llms), all off
 *  - sep         separator for %sep% (default «|»)
 *  - tpl         key => { t: title template, d: description template,
 *                n: noindex }. Keys: home (latest posts on the front page),
 *                pt_{post type}, tax_{taxonomy}, arch_{post type} (post
 *                type archive), author, date, search, 404. A key that is
 *                not saved uses defaults_for().
 *  - noindex_empty  bool  noindex on terms without posts (default on)
 *  - paged_noindex  bool  noindex on page 2+ of archives (default off)
 *  - org         { type Organization|Person, name, url, logo (attachment
 *                ID), phones [ { number, type, lang } ], same_as [url] }
 *  - image       default share image (attachment ID)
 *  - twitter     @handle of the site, '' = none
 *  - search_product  bool  SearchAction searches products (WooCommerce)
 *  - wc_generator    bool  remove the WooCommerce generator tag (default on)
 *  - sm_per      URLs per sitemap file (10–2000, default 200)
 *  - sm_images   bool  <image:image> in post type sitemaps (default on)
 *  - sm_types    post types in the sitemap; null = every public type but
 *                attachments
 *  - sm_taxes    taxonomies in the sitemap; null = category, product_cat
 *  - sm_authors  bool  author sitemap (default off)
 *  - llms_types  post types in llms.txt; null = page, post, product
 *  - llms_taxes  taxonomies in llms.txt; null = product_cat
 *  - llms_limit  newest entries per post type (0 = all, default 300)
 *  - llms_intro  text under the title of llms.txt ('' = the tagline)
 *  - keep        bool  keep the _ssg_* meta on uninstall (default off)
 *
 * Autoloaded: read on every page view.
 */

defined( 'ABSPATH' ) || exit;

final class SSG_Settings {

	const OPT     = 'ssg_settings';
	const MODULES = array( 'meta', 'schema', 'social', 'sitemaps', 'llms' );
	const MAX_TPL = 300;

	/** @var array|null Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'mode'           => 'test',
			'mods'           => array_fill_keys( self::MODULES, false ),
			'sep'            => '|',
			'tpl'            => array(),
			'noindex_empty'  => true,
			'paged_noindex'  => false,
			'org'            => array(
				'type'    => 'Organization',
				'name'    => '',
				'url'     => '',
				'logo'    => 0,
				'phones'  => array(),
				'same_as' => array(),
			),
			'image'          => 0,
			'twitter'        => '',
			'search_product' => true,
			'wc_generator'   => true,
			'sm_per'         => 200,
			'sm_images'      => true,
			'sm_types'       => null,
			'sm_taxes'       => null,
			'sm_authors'     => false,
			'llms_types'     => null,
			'llms_taxes'     => null,
			'llms_limit'     => 300,
			'llms_intro'     => '',
			'keep'           => false,
		);
	}

	/** Default templates and robots of one key. */
	public static function defaults_for( string $key ): array {
		if ( 'home' === $key ) {
			return array( 't' => '%sitename% %page% %sep% %sitedesc%', 'd' => '%sitedesc%', 'n' => false );
		}
		if ( 'author' === $key ) {
			return array( 't' => '%name% %sep% %sitename% %page%', 'd' => '', 'n' => true );
		}
		if ( 'date' === $key ) {
			return array( 't' => '%date% %page% %sep% %sitename%', 'd' => '', 'n' => true );
		}
		if ( 'search' === $key ) {
			return array( 't' => '%search_query% %page% %sep% %sitename%', 'd' => '', 'n' => true );
		}
		if ( '404' === $key ) {
			return array( 't' => '404 %sep% %sitename%', 'd' => '', 'n' => true );
		}
		if ( 0 === strpos( $key, 'tax_' ) ) {
			$tax = substr( $key, 4 );
			return array(
				't' => '%term% %page% %sep% %sitename%',
				'd' => '%term_description%',
				'n' => in_array( $tax, array( 'post_tag', 'post_format', 'product_tag' ), true ),
			);
		}
		if ( 0 === strpos( $key, 'arch_' ) ) {
			return array( 't' => '%pt_plural% %page% %sep% %sitename%', 'd' => '', 'n' => false );
		}
		// pt_{post type}
		$pt = 0 === strpos( $key, 'pt_' ) ? substr( $key, 3 ) : '';
		return array(
			't' => '%title% %sep% %sitename%',
			'd' => '%excerpt%',
			'n' => 'attachment' === $pt,
		);
	}

	/** Templates and robots of one key: saved value or default. */
	public static function tpl( string $key ): array {
		$s = self::get();
		$d = self::defaults_for( $key );
		if ( isset( $s['tpl'][ $key ] ) ) {
			return array_merge( $d, $s['tpl'][ $key ] );
		}
		return $d;
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
		do_action( 'ssg_settings_saved', $clean );
	}

	/** Forget the per-request cache (after a write by another request path). */
	public static function flush(): void {
		self::$cache = null;
	}

	public static function sanitize( array $raw ): array {
		$d = self::defaults();
		$s = array();

		$s['mode'] = isset( $raw['mode'] ) && in_array( $raw['mode'], array( 'off', 'test', 'live' ), true ) ? $raw['mode'] : $d['mode'];

		$s['mods'] = array();
		$mods      = isset( $raw['mods'] ) && is_array( $raw['mods'] ) ? $raw['mods'] : array();
		foreach ( self::MODULES as $m ) {
			$s['mods'][ $m ] = ! empty( $mods[ $m ] );
		}
		// Social reuses the title and description of the meta module.
		if ( $s['mods']['social'] ) {
			$s['mods']['meta'] = true;
		}

		$sep      = isset( $raw['sep'] ) && is_string( $raw['sep'] ) ? trim( wp_strip_all_tags( $raw['sep'] ) ) : $d['sep'];
		$s['sep'] = '' !== $sep && mb_strlen( $sep ) <= 5 ? $sep : $d['sep'];

		$s['tpl'] = array();
		if ( isset( $raw['tpl'] ) && is_array( $raw['tpl'] ) ) {
			foreach ( $raw['tpl'] as $key => $row ) {
				$key = (string) $key;
				if ( ! self::valid_key( $key ) || ! is_array( $row ) || count( $s['tpl'] ) >= 100 ) {
					continue;
				}
				$s['tpl'][ $key ] = array(
					't' => self::text( isset( $row['t'] ) ? $row['t'] : '', self::MAX_TPL ),
					'd' => self::text( isset( $row['d'] ) ? $row['d'] : '', self::MAX_TPL ),
					'n' => ! empty( $row['n'] ),
				);
				if ( '' === $s['tpl'][ $key ]['t'] ) {
					$s['tpl'][ $key ]['t'] = self::defaults_for( $key )['t'];
				}
			}
		}

		foreach ( array( 'noindex_empty', 'paged_noindex', 'search_product', 'wc_generator', 'sm_images', 'sm_authors', 'keep' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}

		$o        = isset( $raw['org'] ) && is_array( $raw['org'] ) ? $raw['org'] : array();
		$s['org'] = array(
			'type'    => isset( $o['type'] ) && 'Person' === $o['type'] ? 'Person' : 'Organization',
			'name'    => self::text( isset( $o['name'] ) ? $o['name'] : '', 200 ),
			'url'     => isset( $o['url'] ) && is_string( $o['url'] ) ? self::url( $o['url'] ) : '',
			'logo'    => isset( $o['logo'] ) ? absint( $o['logo'] ) : 0,
			'phones'  => array(),
			'same_as' => array(),
		);
		if ( isset( $o['phones'] ) && is_array( $o['phones'] ) ) {
			foreach ( $o['phones'] as $p ) {
				if ( ! is_array( $p ) || count( $s['org']['phones'] ) >= 10 ) {
					continue;
				}
				$num = isset( $p['number'] ) && is_string( $p['number'] ) ? (string) preg_replace( '/[^0-9+()\-\s.]/', '', $p['number'] ) : '';
				$num = trim( $num );
				if ( '' === $num || strlen( $num ) > 40 ) {
					continue;
				}
				$s['org']['phones'][] = array(
					'number' => $num,
					'type'   => self::text( isset( $p['type'] ) ? $p['type'] : '', 60 ),
					'lang'   => self::text( isset( $p['lang'] ) ? $p['lang'] : '', 60 ),
				);
			}
		}
		$same = isset( $o['same_as'] ) ? $o['same_as'] : array();
		if ( is_string( $same ) ) {
			$same = preg_split( '/[\r\n]+/', $same );
		}
		foreach ( (array) $same as $u ) {
			$u = is_string( $u ) ? self::url( $u ) : '';
			if ( '' !== $u && ! in_array( $u, $s['org']['same_as'], true ) && count( $s['org']['same_as'] ) < 20 ) {
				$s['org']['same_as'][] = $u;
			}
		}

		$s['image']   = isset( $raw['image'] ) ? absint( $raw['image'] ) : 0;
		$tw           = isset( $raw['twitter'] ) && is_string( $raw['twitter'] ) ? ltrim( trim( $raw['twitter'] ), '@' ) : '';
		$s['twitter'] = preg_match( '/^[A-Za-z0-9_]{1,15}$/', $tw ) ? '@' . $tw : '';

		$s['sm_per']     = self::range( $raw, 'sm_per', 10, 2000, $d['sm_per'] );
		$s['llms_limit'] = self::range( $raw, 'llms_limit', 0, 5000, $d['llms_limit'] );
		$s['llms_intro'] = isset( $raw['llms_intro'] ) && is_string( $raw['llms_intro'] ) ? mb_substr( sanitize_textarea_field( $raw['llms_intro'] ), 0, 1000 ) : '';

		foreach ( array( 'sm_types', 'sm_taxes', 'llms_types', 'llms_taxes' ) as $k ) {
			$s[ $k ] = null;
			if ( isset( $raw[ $k ] ) && is_array( $raw[ $k ] ) ) {
				$list = array();
				foreach ( $raw[ $k ] as $v ) {
					$v = is_string( $v ) ? sanitize_key( $v ) : '';
					if ( '' !== $v && ! in_array( $v, $list, true ) && count( $list ) < 50 ) {
						$list[] = $v;
					}
				}
				$s[ $k ] = $list;
			}
		}
		return $s;
	}

	public static function valid_key( string $key ): bool {
		return (bool) preg_match( '/^(home|author|date|search|404|(pt|tax|arch)_[a-z0-9_\-]{1,32})$/', $key );
	}

	/** Plain text with a length limit. */
	private static function text( $v, int $max ): string {
		if ( ! is_string( $v ) ) {
			return '';
		}
		return mb_substr( self::clean( $v ), 0, $max );
	}

	/**
	 * sanitize_text_field() for text with %variables%: it strips anything
	 * that looks like a percent-encoded byte, so %date% would lose «%da»
	 * and %category% «%ca». The percent signs are kept aside meanwhile.
	 */
	public static function clean( string $v ): string {
		$mark = '__ssgpct__';
		return trim( str_replace( $mark, '%', sanitize_text_field( str_replace( '%', $mark, $v ) ) ) );
	}

	/** An http(s) URL or ''. */
	private static function url( string $u ): string {
		$u = esc_url_raw( trim( $u ), array( 'http', 'https' ) );
		return strlen( $u ) <= 500 ? $u : '';
	}

	/** An integer setting kept inside its range. */
	private static function range( array $raw, string $key, int $min, int $max, int $def ): int {
		$v = isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ? (int) $raw[ $key ] : $def;
		return max( $min, min( $max, $v ) );
	}

	public static function module( string $m ): bool {
		$s = self::get();
		return 'off' !== $s['mode'] && ! empty( $s['mods'][ $m ] );
	}

	public static function any_module(): bool {
		foreach ( self::MODULES as $m ) {
			if ( self::module( $m ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Lists resolved at run time (post types exist only after init)
	 * ------------------------------------------------------------------- */

	/** Public post types we handle (no attachments in sitemaps / llms). */
	public static function public_types( bool $with_attachment = false ): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $pt ) {
			if ( ! $with_attachment && 'attachment' === $pt ) {
				continue;
			}
			$out[] = (string) $pt;
		}
		return $out;
	}

	public static function public_taxes(): array {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tx ) {
			if ( in_array( $tx, array( 'post_format', 'product_shipping_class' ), true ) ) {
				continue;
			}
			$out[] = (string) $tx;
		}
		return $out;
	}

	public static function sitemap_types(): array {
		$s = self::get();
		$l = null === $s['sm_types'] ? self::public_types() : $s['sm_types'];
		return array_values( array_intersect( $l, self::public_types() ) );
	}

	public static function sitemap_taxes(): array {
		$s = self::get();
		$l = null === $s['sm_taxes'] ? array( 'category', 'product_cat' ) : $s['sm_taxes'];
		return array_values( array_intersect( $l, self::public_taxes() ) );
	}

	public static function llms_types(): array {
		$s = self::get();
		$l = null === $s['llms_types'] ? array( 'page', 'post', 'product' ) : $s['llms_types'];
		return array_values( array_intersect( $l, self::public_types() ) );
	}

	public static function llms_taxes(): array {
		$s = self::get();
		$l = null === $s['llms_taxes'] ? array( 'product_cat' ) : $s['llms_taxes'];
		return array_values( array_intersect( $l, self::public_taxes() ) );
	}

	/** The name of the organization (falls back to the site title). */
	public static function org_name(): string {
		$n = self::get()['org']['name'];
		return '' !== $n ? $n : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}
}

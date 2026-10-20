<?php
/**
 * CRT_Settings — the crt_settings option (autoload: read on every product
 * and creator view), strict validation with whitelists (Bible §11).
 *
 *  - mode            'off' | 'test' (default: shop managers only) | 'live'
 *  - lang            visitor text language: 'auto' (site locale) | 'el' | 'en'
 *  - roles           ordered list of { tax, h_el, h_en, l_el, l_en, p_el, p_en,
 *                    schema, box, tab, section }:
 *                    tax = a global attribute taxonomy (pa_*); h_* = section
 *                    heading on the creator page; l_* = role name (box,
 *                    index, header, product tab); p_* = role name in the
 *                    plural (product tab with several creators); '' = the
 *                    attribute's label; schema = schema.org property
 *                    (self::PROPS); box = shown in the creator box on the
 *                    product; tab = shown in the creator tab on the
 *                    product; section = its products get a section on the
 *                    creator page (off: the role only matches and shows in
 *                    the roles line, e.g. page layout with 60 products)
 *  - base            URL base of the creator pages (/base/slug/)
 *  - top             space above the creator page (CSS length)
 *  - columns         product columns on the creator page (1–6)
 *  - bundle_cats     product_cat term ids whose products are bundles
 *  - bundle_types    product types that are bundles (grouped, woosb, …)
 *  - formats         one card per work when Product Formats is active
 *  - links           links in the "Additional information" table
 *  - box, box_pos    creator box on the product: 'description' (end of the
 *                    Description tab) | 'after' (after the tabs) | 'none'
 *                    (shortcode / block only); box_max cards
 *  - tab, tab_priority product tab with the creators of the "tab" roles
 *                    (photo, short biography, links, latest press items),
 *                    at this WooCommerce tab priority (1–200)
 *  - index_page      page with the index (breadcrumbs, schema, back link)
 *  - schema          Person + ProfilePage on creator pages
 *  - schema_products roles on products (needs schema_types)
 *  - schema_types    product_cat term id => Book | Audiobook | MusicAlbum | Movie
 *  - delete_posts    delete the creators on uninstall (off)
 *  - t_*             visitor texts { el, en } (Bible §14.17)
 */

defined( 'ABSPATH' ) || exit;

final class CRT_Settings {

	const OPT = 'crt_settings';

	/** Visitor text keys => max length. */
	const TEXTS = array(
		't_bundles'    => 80,
		't_interviews' => 80,
		't_videos'     => 80,
		't_reviews'    => 80,
		't_more'       => 80,
		't_all'        => 40,
		't_formats'    => 80,
		't_box'        => 80,
		't_tab_m'      => 80,
		't_tab_f'      => 80,
		't_tab_n'      => 80,
		't_tab_many'   => 80,
		't_tab_mixed'  => 80,
	);

	/** schema.org properties a role may map to ('' = none). */
	const PROPS = array( 'author', 'illustrator', 'editor', 'translator', 'readBy', 'director', 'actor', 'byArtist', 'producer', 'contributor', '' );

	/** schema.org work types for products. */
	const TYPES = array( 'Book', 'Audiobook', 'MusicAlbum', 'Movie' );

	/** @var array|null Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'mode'            => 'test',
			'lang'            => 'auto',
			'roles'           => array(),
			'base'            => 'creators',
			'top'             => '2.5em',
			'columns'         => 4,
			'bundle_cats'     => array(),
			'bundle_types'    => array(),
			'formats'         => true,
			'links'           => true,
			'box'             => true,
			'box_pos'         => 'description',
			'box_max'         => 3,
			'tab'             => false,
			'tab_priority'    => 35,
			'index_page'      => 0,
			'schema'          => true,
			'schema_products' => true,
			'schema_types'    => array(),
			'delete_posts'    => false,
			't_bundles'       => array(
				'el' => 'Πακέτα',
				'en' => 'Bundles',
			),
			't_interviews'    => array(
				'el' => 'Συνεντεύξεις',
				'en' => 'Interviews',
			),
			't_videos'        => array(
				'el' => 'Βίντεο',
				'en' => 'Videos',
			),
			't_reviews'       => array(
				'el' => 'Κριτικές και αναφορές',
				'en' => 'Reviews and mentions',
			),
			't_more'          => array(
				'el' => 'Όλα τα έργα',
				'en' => 'All works',
			),
			't_all'           => array(
				'el' => 'Όλοι',
				'en' => 'All',
			),
			't_formats'       => array(
				'el' => 'Σε {n} μορφές',
				'en' => 'In {n} formats',
			),
			't_box'           => array(
				'el' => 'Οι δημιουργοί',
				'en' => 'The creators',
			),
			// Product tab title. {role} = the role name, {roles} = the
			// plural; the first letter of the result is capitalised.
			't_tab_m'         => array(
				'el' => 'Ο {role}',
				'en' => 'About the {role}',
			),
			't_tab_f'         => array(
				'el' => 'Η {role}',
				'en' => 'About the {role}',
			),
			't_tab_n'         => array(
				'el' => '{role}',
				'en' => 'About the {role}',
			),
			't_tab_many'      => array(
				'el' => 'Οι {roles}',
				'en' => 'About the {roles}',
			),
			't_tab_mixed'     => array(
				'el' => 'Οι δημιουργοί',
				'en' => 'About the creators',
			),
		);
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPT, array() );
			self::$cache = self::sanitize( is_array( $raw ) ? $raw : array() );
		}
		return self::$cache;
	}

	/** Validates and stores; returns the stored settings. */
	public static function save( array $raw ): array {
		$old = self::get();
		$s   = self::sanitize( $raw );
		update_option( self::OPT, $s, true );
		self::$cache = $s;
		if ( $old['base'] !== $s['base'] ) {
			update_option( 'crt_flush', 1, false );
		}
		return $s;
	}

	/** Drops the per-request cache (tests, imports). */
	public static function reset(): void {
		self::$cache = null;
	}

	/**
	 * Strict validation: unknown keys are dropped, every value is checked
	 * against a whitelist or a range; anything invalid falls back to the
	 * default (or the current value where the form says so).
	 */
	public static function sanitize( array $raw ): array {
		$d = self::defaults();
		$s = $d;

		$s['mode'] = isset( $raw['mode'] ) && in_array( $raw['mode'], array( 'off', 'test', 'live' ), true ) ? $raw['mode'] : $d['mode'];
		$s['lang'] = isset( $raw['lang'] ) && in_array( $raw['lang'], array( 'auto', 'el', 'en' ), true ) ? $raw['lang'] : $d['lang'];

		$s['roles'] = self::sanitize_roles( isset( $raw['roles'] ) && is_array( $raw['roles'] ) ? $raw['roles'] : array() );

		$base = isset( $raw['base'] ) && is_string( $raw['base'] ) ? sanitize_title( $raw['base'] ) : '';
		// Never a base that collides with WordPress's own query vars.
		if ( '' === $base || in_array( $base, array( 'page', 'feed', 'embed', 'attachment', 'product', 'shop', 'wp-admin', 'wp-json' ), true ) ) {
			$base = $d['base'];
		}
		$s['base'] = substr( $base, 0, 60 );

		$top      = isset( $raw['top'] ) && is_string( $raw['top'] ) ? strtolower( trim( $raw['top'] ) ) : '';
		$s['top'] = preg_match( '/^(0|\d{1,3}(\.\d{1,2})?(px|em|rem))$/', $top ) ? $top : $d['top'];

		$cols         = isset( $raw['columns'] ) ? (int) $raw['columns'] : 0;
		$s['columns'] = $cols >= 1 && $cols <= 6 ? $cols : $d['columns'];

		$s['bundle_cats'] = array();
		if ( isset( $raw['bundle_cats'] ) && is_array( $raw['bundle_cats'] ) ) {
			foreach ( $raw['bundle_cats'] as $id ) {
				$id = (int) $id;
				if ( $id > 0 && term_exists( $id, 'product_cat' ) ) {
					$s['bundle_cats'][] = $id;
				}
			}
			$s['bundle_cats'] = array_values( array_unique( $s['bundle_cats'] ) );
		}

		$s['bundle_types'] = array();
		if ( isset( $raw['bundle_types'] ) && is_array( $raw['bundle_types'] ) ) {
			$known = self::product_types();
			foreach ( $raw['bundle_types'] as $t ) {
				if ( is_string( $t ) && isset( $known[ $t ] ) && 'simple' !== $t && 'variable' !== $t ) {
					$s['bundle_types'][] = $t;
				}
			}
			$s['bundle_types'] = array_values( array_unique( $s['bundle_types'] ) );
		}

		foreach ( array( 'formats', 'links', 'box', 'tab', 'schema', 'schema_products', 'delete_posts' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}

		$s['box_pos'] = isset( $raw['box_pos'] ) && in_array( $raw['box_pos'], array( 'description', 'after', 'none' ), true ) ? $raw['box_pos'] : $d['box_pos'];
		$max          = isset( $raw['box_max'] ) ? (int) $raw['box_max'] : 0;
		$s['box_max'] = $max >= 1 && $max <= 12 ? $max : $d['box_max'];

		$prio              = isset( $raw['tab_priority'] ) ? (int) $raw['tab_priority'] : 0;
		$s['tab_priority'] = $prio >= 1 && $prio <= 200 ? $prio : $d['tab_priority'];

		$page            = isset( $raw['index_page'] ) ? (int) $raw['index_page'] : 0;
		$s['index_page'] = $page > 0 && 'page' === get_post_type( $page ) ? $page : 0;

		$s['schema_types'] = array();
		if ( isset( $raw['schema_types'] ) && is_array( $raw['schema_types'] ) ) {
			foreach ( $raw['schema_types'] as $id => $type ) {
				$id = (int) $id;
				if ( $id > 0 && in_array( $type, self::TYPES, true ) && term_exists( $id, 'product_cat' ) ) {
					$s['schema_types'][ $id ] = $type;
				}
			}
		}

		foreach ( self::TEXTS as $key => $len ) {
			$s[ $key ] = self::sanitize_pair( isset( $raw[ $key ] ) ? $raw[ $key ] : null, $d[ $key ], $len );
		}

		return $s;
	}

	/**
	 * A { el, en } visitor text. An empty Greek value restores the default;
	 * an empty English value is kept (the Greek one shows instead).
	 *
	 * @param mixed $v
	 */
	private static function sanitize_pair( $v, array $default, int $len ): array {
		if ( ! is_array( $v ) ) {
			return $default;
		}
		$el = isset( $v['el'] ) && is_string( $v['el'] ) ? self::clip( sanitize_text_field( $v['el'] ), $len ) : '';
		$en = isset( $v['en'] ) && is_string( $v['en'] ) ? self::clip( sanitize_text_field( $v['en'] ), $len ) : '';
		return array(
			'el' => '' === $el ? $default['el'] : $el,
			'en' => $en,
		);
	}

	/** A string cut to $len characters (multibyte safe). */
	public static function clip( string $v, int $len ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $len ) : substr( $v, 0, $len );
	}

	/** Roles: only existing attribute taxonomies, once each, in the given order. */
	private static function sanitize_roles( array $raw ): array {
		$taxes = self::attribute_taxonomies();
		$out   = array();
		$seen  = array();
		foreach ( $raw as $r ) {
			if ( ! is_array( $r ) || ! isset( $r['tax'] ) || ! is_string( $r['tax'] ) ) {
				continue;
			}
			$tax = $r['tax'];
			if ( ! isset( $taxes[ $tax ] ) || isset( $seen[ $tax ] ) ) {
				continue;
			}
			$seen[ $tax ] = true;
			$row          = array( 'tax' => $tax );
			foreach ( array( 'h_el', 'h_en', 'l_el', 'l_en', 'p_el', 'p_en' ) as $k ) {
				$row[ $k ] = isset( $r[ $k ] ) && is_string( $r[ $k ] ) ? self::clip( sanitize_text_field( $r[ $k ] ), 80 ) : '';
			}
			$row['schema']  = isset( $r['schema'] ) && in_array( $r['schema'], self::PROPS, true ) ? $r['schema'] : self::guess_prop( $taxes[ $tax ] );
			$row['box']     = ! empty( $r['box'] );
			$row['tab']     = ! empty( $r['tab'] );
			// Settings saved before 1.1.0 have no 'section': every role had one.
			$row['section'] = ! isset( $r['section'] ) || ! empty( $r['section'] );
			$out[]          = $row;
		}
		return $out;
	}

	/** A new role row with defaults guessed from the attribute's label. */
	public static function new_role( string $tax, bool $box ): array {
		$taxes = self::attribute_taxonomies();
		return array(
			'tax'     => $tax,
			'h_el'    => '',
			'h_en'    => '',
			'l_el'    => '',
			'l_en'    => '',
			'p_el'    => '',
			'p_en'    => '',
			'schema'  => self::guess_prop( isset( $taxes[ $tax ] ) ? $taxes[ $tax ] : '' ),
			'box'     => $box,
			'tab'     => false,
			'section' => true,
		);
	}

	/** schema.org property guessed from an attribute label (Greek or English). */
	public static function guess_prop( string $label ): string {
		$l = self::fold( $label );
		$map = array(
			'author'      => array( 'συγγραφ', 'author', 'writer' ),
			'illustrator' => array( 'εικονογραφ', 'illustrat' ),
			'editor'      => array( 'επιμελ', 'editor' ),
			'translator'  => array( 'μεταφρασ', 'μεταφραστ', 'translat' ),
			'readBy'      => array( 'αφηγησ', 'αφηγητ', 'narrat', 'read by' ),
			'director'    => array( 'σκηνοθεσ', 'σκηνοθετ', 'director' ),
			'actor'       => array( 'ηθοποι', 'actor', 'cast' ),
			'byArtist'    => array( 'παρουσιασ', 'ερμηνε', 'artist', 'perform' ),
			'producer'    => array( 'παραγωγ', 'producer' ),
		);
		foreach ( $map as $prop => $needles ) {
			foreach ( $needles as $n ) {
				if ( false !== strpos( $l, $n ) ) {
					return $prop;
				}
			}
		}
		return 'contributor';
	}

	/**
	 * Lower case without accents, Greek included (tonos, dialytika, final
	 * sigma), for matching and for sorting names.
	 */
	public static function fold( string $v ): string {
		$v = function_exists( 'mb_strtolower' ) ? mb_strtolower( $v, 'UTF-8' ) : strtolower( $v );
		$v = strtr(
			$v,
			array(
				'ά' => 'α',
				'έ' => 'ε',
				'ή' => 'η',
				'ί' => 'ι',
				'ό' => 'ο',
				'ύ' => 'υ',
				'ώ' => 'ω',
				'ϊ' => 'ι',
				'ϋ' => 'υ',
				'ΐ' => 'ι',
				'ΰ' => 'υ',
				'ς' => 'σ',
			)
		);
		return remove_accents( $v );
	}

	/** Global attribute taxonomies: pa_name => label. */
	public static function attribute_taxonomies(): array {
		$out = array();
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return $out;
		}
		foreach ( wc_get_attribute_taxonomies() as $a ) {
			$tax         = wc_attribute_taxonomy_name( $a->attribute_name );
			$out[ $tax ] = (string) $a->attribute_label;
		}
		return $out;
	}

	/** Product types known to WooCommerce: slug => label. */
	public static function product_types(): array {
		return function_exists( 'wc_get_product_types' ) ? (array) wc_get_product_types() : array();
	}

	/** Role taxonomies, in order. */
	public static function role_taxes(): array {
		$out = array();
		foreach ( self::get()['roles'] as $r ) {
			if ( taxonomy_exists( $r['tax'] ) ) {
				$out[] = $r['tax'];
			}
		}
		return $out;
	}

	/** Role taxonomies shown in the product tab, in order. */
	public static function tab_taxes(): array {
		$out = array();
		foreach ( self::get()['roles'] as $r ) {
			if ( $r['tab'] && taxonomy_exists( $r['tax'] ) ) {
				$out[] = $r['tax'];
			}
		}
		return $out;
	}

	/** Whether a role gets its own section of products on the creator page. */
	public static function has_section( string $tax ): bool {
		$r = self::role( $tax );
		return null !== $r && $r['section'];
	}

	/** The role row of a taxonomy, or null. */
	public static function role( string $tax ): ?array {
		foreach ( self::get()['roles'] as $r ) {
			if ( $r['tax'] === $tax ) {
				return $r;
			}
		}
		return null;
	}

	/** Visitor language: 'el' | 'en'. */
	public static function lang(): string {
		$l = self::get()['lang'];
		if ( 'el' === $l || 'en' === $l ) {
			return $l;
		}
		return CRT_Lang::from_locale( (string) get_locale() );
	}

	/**
	 * A role's visitor text: 'h' (section heading), 'l' (role name) or 'p'
	 * (role name in the plural). An empty value falls back to the other
	 * language, then ('h' only) to the role name, then to the label of the
	 * attribute.
	 */
	public static function role_text( string $tax, string $which ): string {
		$r    = self::role( $tax );
		$lang = self::lang();
		$text = '';
		if ( null !== $r ) {
			$k    = ( 'h' === $which || 'p' === $which ? $which . '_' : 'l_' );
			$text = ( 'en' === $lang && '' !== $r[ $k . 'en' ] ) ? $r[ $k . 'en' ] : $r[ $k . 'el' ];
			if ( '' === $text && 'h' === $which ) {
				$text = ( 'en' === $lang && '' !== $r['l_en'] ) ? $r['l_en'] : $r['l_el'];
			}
		}
		if ( '' === $text ) {
			$taxes = self::attribute_taxonomies();
			$text  = isset( $taxes[ $tax ] ) ? $taxes[ $tax ] : $tax;
		}
		/** This filter is documented in self::text(). */
		return (string) apply_filters( 'crt_text', $text, $which . ':' . $tax, $lang );
	}

	/** A visitor text in the visitor's language (Bible §14.17). */
	public static function text( string $key ): string {
		$s    = self::get();
		$lang = self::lang();
		$pair = isset( $s[ $key ] ) && is_array( $s[ $key ] ) ? $s[ $key ] : array(
			'el' => '',
			'en' => '',
		);
		$text = ( 'en' === $lang && '' !== $pair['en'] ) ? $pair['en'] : $pair['el'];
		/**
		 * Filters a visitor text of Featured Creators (e.g. for WPML).
		 *
		 * @param string $text Text with its {placeholders}.
		 * @param string $key  Text key (t_more, t_bundles, …), or
		 *                     'h:pa_x' / 'l:pa_x' / 'p:pa_x' for a role
		 *                     heading / name / name in the plural.
		 * @param string $lang 'el' | 'en'.
		 */
		return (string) apply_filters( 'crt_text', $text, $key, $lang );
	}
}

<?php
/**
 * LVS_Settings — the lvs_settings option (autoload: read on every page
 * that shows a search form), strict validation with whitelists (§11).
 *
 *  - mode        'off' (default) | 'test' (shop managers only) | 'live'
 *  - forms       'all' (every search form of the site searches products,
 *                default) | 'product' (only the theme's product search)
 *  - codes       ISBN / GTIN / SKU are searched (default on)
 *  - people      attribute taxonomies of people (authors, translators…):
 *                searched with a high weight and shown under the title
 *  - attrs       other attribute taxonomies that are searched (e.g. year)
 *  - cats, tags, brands  product categories, tags, brands are searched
 *  - excerpt     the short description is searched on the results page
 *  - limit       suggestions shown (3–20, default 7)
 *  - min_chars   letters before suggestions start (1–5, default 3)
 *  - image, price, byline  what a suggestion shows
 *  - oos         out of stock: 'last' (default) | 'normal' | 'hide'
 *  - isbn_direct an exact ISBN of one product opens that product
 *  - group       one suggestion per work of Product Formats
 *  - creators    a «Creators» section (Featured Creators pages)
 *  - engine      'auto' (file up to «threshold» products, else server) |
 *                'file' | 'server'
 *  - threshold   products above which auto uses the server (500–50000)
 *  - synonyms    lines «word = word, phrase»; both ways
 *  - stats       statistics in a private file (default on)
 *  - lang        visitor texts: 'auto' (site language) | 'el' | 'en'
 *  - texts       placeholder, none, all, each in el and en
 */

defined( 'ABSPATH' ) || exit;

final class LVS_Settings {

	const OPT = 'lvs_settings';

	const TEXT_KEYS = array( 'placeholder', 'none', 'all' );

	/** @var array|null Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'mode'        => 'off',
			'forms'       => 'all',
			'codes'       => true,
			'people'      => array(),
			'attrs'       => array(),
			'cats'        => true,
			'tags'        => true,
			'brands'      => true,
			'excerpt'     => true,
			'limit'       => 7,
			'min_chars'   => 3,
			'image'       => true,
			'price'       => true,
			'byline'      => true,
			'oos'         => 'last',
			'isbn_direct' => true,
			'group'       => true,
			'creators'    => true,
			'engine'      => 'auto',
			'threshold'   => 5000,
			'synonyms'    => '',
			'stats'       => true,
			'lang'        => 'auto',
			'texts'       => self::text_defaults(),
		);
	}

	/** Visitor texts, as the store writes them (§14.17). */
	public static function text_defaults(): array {
		return array(
			'placeholder' => array(
				'el' => 'Αναζήτηση βιβλίων, συγγραφέων, ISBN…',
				'en' => 'Search books, authors, ISBN…',
			),
			'none'        => array(
				'el' => 'Δεν βρέθηκε τίποτα. Δοκίμασε άλλη λέξη.',
				'en' => 'Nothing found. Try another word.',
			),
			'all'         => array(
				'el' => 'Όλα τα αποτελέσματα',
				'en' => 'All results',
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

	public static function save( array $raw ): void {
		$clean = self::sanitize( $raw );
		update_option( self::OPT, $clean, true );
		self::$cache = $clean;
	}

	/** Strict validation: anything unknown falls back to the default. */
	public static function sanitize( array $raw ): array {
		$d   = self::defaults();
		$out = array();

		$out['mode']   = self::pick( $raw, 'mode', array( 'off', 'test', 'live' ), $d['mode'] );
		$out['forms']  = self::pick( $raw, 'forms', array( 'all', 'product' ), $d['forms'] );
		$out['oos']    = self::pick( $raw, 'oos', array( 'last', 'normal', 'hide' ), $d['oos'] );
		$out['engine'] = self::pick( $raw, 'engine', array( 'auto', 'file', 'server' ), $d['engine'] );
		$out['lang']   = self::pick( $raw, 'lang', array( 'auto', 'el', 'en' ), $d['lang'] );

		foreach ( array( 'codes', 'cats', 'tags', 'brands', 'excerpt', 'image', 'price', 'byline', 'isbn_direct', 'group', 'creators', 'stats' ) as $k ) {
			$out[ $k ] = array_key_exists( $k, $raw ) ? (bool) $raw[ $k ] : $d[ $k ];
		}

		$out['limit']     = self::range( $raw, 'limit', 3, 20, $d['limit'] );
		$out['min_chars'] = self::range( $raw, 'min_chars', 1, 5, $d['min_chars'] );
		$out['threshold'] = self::range( $raw, 'threshold', 500, 50000, $d['threshold'] );

		$out['people'] = self::taxes( $raw['people'] ?? array() );
		$out['attrs']  = array_values( array_diff( self::taxes( $raw['attrs'] ?? array() ), $out['people'] ) );

		$syn             = isset( $raw['synonyms'] ) && is_string( $raw['synonyms'] ) ? $raw['synonyms'] : '';
		$out['synonyms'] = self::clean_synonyms( $syn );

		$texts = isset( $raw['texts'] ) && is_array( $raw['texts'] ) ? $raw['texts'] : array();
		foreach ( self::TEXT_KEYS as $k ) {
			foreach ( array( 'el', 'en' ) as $l ) {
				$v = isset( $texts[ $k ][ $l ] ) && is_string( $texts[ $k ][ $l ] ) ? $texts[ $k ][ $l ] : $d['texts'][ $k ][ $l ];
				$v = trim( sanitize_text_field( $v ) );
				if ( function_exists( 'mb_substr' ) ) {
					$v = mb_substr( $v, 0, 120 );
				}
				$out['texts'][ $k ][ $l ] = $v;
			}
		}
		return $out;
	}

	private static function pick( array $raw, string $key, array $allowed, string $def ): string {
		return isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && in_array( $raw[ $key ], $allowed, true ) ? $raw[ $key ] : $def;
	}

	private static function range( array $raw, string $key, int $min, int $max, int $def ): int {
		if ( ! isset( $raw[ $key ] ) || ! is_numeric( $raw[ $key ] ) ) {
			return $def;
		}
		return max( $min, min( $max, (int) $raw[ $key ] ) );
	}

	/** Attribute taxonomies only (pa_…), max 20. */
	private static function taxes( $list ): array {
		$out = array();
		foreach ( (array) $list as $t ) {
			$t = is_string( $t ) ? sanitize_key( $t ) : '';
			if ( '' !== $t && 0 === strpos( $t, 'pa_' ) && strlen( $t ) <= 32 && ! in_array( $t, $out, true ) ) {
				$out[] = $t;
			}
		}
		return array_slice( $out, 0, 20 );
	}

	/** At most 200 lines of 300 characters, each «a = b, c». */
	public static function clean_synonyms( string $raw ): string {
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( sanitize_text_field( (string) $line ) );
			if ( '' === $line || false === strpos( $line, '=' ) ) {
				continue;
			}
			if ( function_exists( 'mb_substr' ) ) {
				$line = mb_substr( $line, 0, 300 );
			}
			$out[] = $line;
			if ( count( $out ) >= 200 ) {
				break;
			}
		}
		return implode( "\n", $out );
	}

	/**
	 * Synonym groups as normalised word => alternatives. Every word or
	 * phrase of a line stands for every other one.
	 *
	 * @return array<string, string[]>
	 */
	public static function synonym_map(): array {
		$map = array();
		foreach ( explode( "\n", self::get()['synonyms'] ) as $line ) {
			$parts = preg_split( '/[=,]/', $line );
			$group = array();
			foreach ( (array) $parts as $p ) {
				$n = LVS_Text::norm( (string) $p );
				if ( '' !== $n && ! in_array( $n, $group, true ) ) {
					$group[] = $n;
				}
			}
			if ( count( $group ) < 2 ) {
				continue;
			}
			foreach ( $group as $g ) {
				// Only one-word keys: the query is split into words.
				if ( false !== strpos( $g, ' ' ) ) {
					continue;
				}
				$others      = array_values( array_diff( $group, array( $g ) ) );
				$map[ $g ]   = array_values( array_unique( array_merge( $map[ $g ] ?? array(), $others ) ) );
			}
		}
		return $map;
	}

	/** Mode as it applies to this request: live, or test for shop managers. */
	public static function on_for_request(): bool {
		$m = self::get()['mode'];
		if ( 'live' === $m ) {
			return true;
		}
		return 'test' === $m && did_action( 'set_current_user' ) && current_user_can( Live_Search::CAP );
	}

	/** Visitor language: 'el' | 'en'. */
	public static function visitor_lang(): string {
		$l = self::get()['lang'];
		if ( 'el' === $l || 'en' === $l ) {
			return $l;
		}
		return LVS_Lang::from_locale( (string) get_locale() );
	}

	/** A visitor text in the visitor's language; empty EN falls back to EL. */
	public static function text( string $key ): string {
		$t    = self::get()['texts'];
		$lang = self::visitor_lang();
		$v    = isset( $t[ $key ][ $lang ] ) ? (string) $t[ $key ][ $lang ] : '';
		if ( '' === $v && isset( $t[ $key ]['el'] ) ) {
			$v = (string) $t[ $key ]['el'];
		}
		return $v;
	}

	/** File or server, as it applies now. */
	public static function engine(): string {
		$s = self::get();
		if ( 'auto' !== $s['engine'] ) {
			return $s['engine'];
		}
		$n = LVS_Index::count_visible();
		return $n > $s['threshold'] ? 'server' : 'file';
	}
}

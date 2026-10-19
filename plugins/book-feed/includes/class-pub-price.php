<?php
/**
 * BKF_Pub_Price — the price on the publisher's own site.
 *
 * The catalogue's prices are often old. For a publisher with a page
 * address in the settings (bkf_settings.pub_prices: pub_id => template
 * with {isbn}, {isbn13}, {title}), the product page is read and its price
 * takes the place of the catalogue's, automatically (Chris, 2026-10-19).
 * A product can name its own page instead (_bkf_pub_url, in the Book Feed
 * box): sites whose addresses do not carry the ISBN (1.3.0).
 *
 * Where the price comes from, in order (first one found wins):
 *  0. A WooCommerce Store API answer (/wp-json/wc/store/v1/products?
 *     search=…): the product whose SKU is this ISBN, or the only one;
 *     its regular price.
 *  1. JSON-LD (schema.org Product → offers): a ListPrice /
 *     StrikethroughPrice in priceSpecification (the regular price while
 *     the shop runs a sale), else offers.price / lowPrice.
 *  2. <meta property="product:price:amount"> (Open Graph).
 *  3. itemprop="price" content="…" (microdata).
 *  4. The page's text: a number right after one of the words in the
 *     settings (pub_labels, "Τιμή εκδότη"). Read before 1–3 when found,
 *     since those often carry the shop's own (discounted) price.
 * When the page names an ISBN / GTIN of its own and it is not this
 * title's, nothing is taken (a search page that landed on another book).
 *
 * Kept safe:
 *  - wp_safe_remote_get (no local / private addresses), http(s) only,
 *    8 s, 3 redirects, at most 2 MB read.
 *  - A price is used only when it is plausible: above 0, and between half
 *    and double the catalogue's price when the catalogue has one.
 *    Anything else keeps the catalogue price and goes to the history once.
 *  - One request per title per day: the result (price or "none") is kept
 *    in a transient per ISBN, so imports and the sync share it.
 *
 * The same page also gives the book's cover (1.4.0): og:image or the
 * JSON-LD Product image, an absolute http(s) address. The importer takes
 * it only when it is larger than the catalogue's (cover()).
 *
 * Everything read from the page is hostile: only numbers and one image
 * address come out.
 */

defined( 'ABSPATH' ) || exit;

final class BKF_Pub_Price {

	const TTL      = 72000; // 20 h: one look per daily sync
	const MAX_BODY = 2097152;
	const TIMEOUT  = 8;

	/** The product's own page, else the template for the record's publisher, or ''. */
	public static function template( array $r ): string {
		if ( isset( $r['pub_url'] ) && is_string( $r['pub_url'] ) && BKF_Settings::page_url_ok( $r['pub_url'] ) ) {
			return $r['pub_url'];
		}
		$s = BKF_Settings::get();
		return ( '' !== $r['pub_id'] && isset( $s['pub_prices'][ $r['pub_id'] ] ) ) ? $s['pub_prices'][ $r['pub_id'] ]['url'] : '';
	}

	/** The page address for a record, or ''. */
	public static function url( string $tpl, array $r ): string {
		if ( '' === $tpl || '' === $r['isbn_digits'] ) {
			return '';
		}
		$url = str_replace(
			array( '{isbn}', '{isbn13}', '{title}' ),
			array( rawurlencode( '' !== $r['isbn'] ? $r['isbn'] : $r['isbn_digits'] ), rawurlencode( $r['isbn_digits'] ), rawurlencode( $r['title'] ) ),
			$tpl
		);
		return preg_match( '#^https?://#i', $url ) ? esc_url_raw( $url, array( 'http', 'https' ) ) : '';
	}

	/**
	 * The publisher's price for a record (with VAT, as the page shows it),
	 * or null when there is none to use. $why gets the reason on null.
	 */
	public static function price( array $r, ?string &$why = null, bool $fresh = false ): ?float {
		$why = '';
		$got = self::page( $r, $fresh );
		if ( null === $got ) {
			return null;
		}
		$p   = isset( $got['p'] ) && is_numeric( $got['p'] ) ? (float) $got['p'] : null;
		$why = isset( $got['why'] ) && is_string( $got['why'] ) ? $got['why'] : '';
		if ( null === $p ) {
			return null;
		}
		if ( ! self::plausible( $p, $r['price'] ) ) {
			/* translators: 1: publisher price, 2: catalogue price */
			$why = sprintf( __( 'Η τιμή του εκδότη (%1$s) απέχει πολύ από του καταλόγου (%2$s)· κρατήθηκε του καταλόγου.', 'book-feed' ), BKF_Pub_Price::money( (float) $p ), null === $r['price'] ? '—' : BKF_Pub_Price::money( (float) $r['price'] ) );
			return null;
		}
		return $p;
	}

	/** The cover address on the publisher's page for a record, or ''. */
	public static function cover( array $r ): string {
		$got = self::page( $r );
		return null !== $got && isset( $got['img'] ) && is_string( $got['img'] ) ? $got['img'] : '';
	}

	/**
	 * What the publisher's page gave for a record (price, reason, image),
	 * read once a day and kept in a transient; null without a page.
	 */
	private static function page( array $r, bool $fresh = false ): ?array {
		$url = self::url( self::template( $r ), $r );
		if ( '' === $url ) {
			return null;
		}
		$key = 'bkf_pp_' . md5( $r['isbn_digits'] . '|' . $url );
		$got = $fresh ? false : get_transient( $key );
		// Entries from before 1.4.0 have no image: read the page again.
		if ( ! is_array( $got ) || ! array_key_exists( 'img', $got ) ) {
			$why = '';
			$img = '';
			$p   = self::fetch( $url, $r, $why, $img );
			$got = array(
				'p'   => $p,
				'why' => $why,
				'img' => $img,
			);
			set_transient( $key, $got, self::TTL );
		}
		return $got;
	}

	/** A price for messages: "12,50" in the store's format. */
	public static function money( float $p ): string {
		return wc_format_localized_price( wc_format_decimal( $p, wc_get_price_decimals() ) );
	}

	/** Above 0 and within half / double of the catalogue's price (when known). */
	public static function plausible( float $p, ?float $feed ): bool {
		if ( $p <= 0 || $p > 100000 ) {
			return false;
		}
		if ( null === $feed || $feed <= 0 ) {
			return true;
		}
		return $p >= $feed / 2 && $p <= $feed * 2;
	}

	/** Reads the page; the price or null (reason in $why, cover address in $img). */
	public static function fetch( string $url, array $r, ?string &$why = null, ?string &$img = null ): ?float {
		$img = '';
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 3,
				'limit_response_size' => self::MAX_BODY,
				'user-agent'          => 'Book Feed/' . BKF_VERSION . '; ' . home_url( '/' ),
				'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			$why = $res->get_error_message();
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status */
			$why = sprintf( __( 'Η σελίδα του εκδότη απάντησε %d.', 'book-feed' ), $code );
			return null;
		}
		$body = (string) wp_remote_retrieve_body( $res );
		$p    = self::parse( $body, $r['isbn_digits'] );
		$img  = self::image( $body, $r['isbn_digits'] );
		if ( null === $p ) {
			$why = __( 'Δεν βρέθηκε τιμή για αυτό το ISBN στη σελίδα του εκδότη.', 'book-feed' );
		}
		return $p;
	}

	/**
	 * The price on a product page for this ISBN, or null. $labels: the
	 * words before a price in the text (null = the settings').
	 */
	public static function parse( string $html, string $isbn_digits, ?array $labels = null ): ?float {
		// 0. Store API (JSON list of products).
		$t = ltrim( $html );
		if ( '' !== $t && '[' === $t[0] ) {
			$list = json_decode( $t, true );
			return is_array( $list ) ? self::store_api( $list, $isbn_digits ) : null;
		}
		// 4 first: a labelled price is the publisher's list price.
		if ( self::other_book( $html, $isbn_digits ) ) {
			return null;
		}
		$p = self::labelled( self::text( $html ), null === $labels ? BKF_Settings::get()['pub_labels'] : $labels );
		if ( null !== $p ) {
			return $p;
		}
		// 1. JSON-LD.
		if ( preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m ) ) {
			foreach ( $m[1] as $json ) {
				$data = json_decode( trim( $json ), true );
				if ( ! is_array( $data ) ) {
					$data = json_decode( html_entity_decode( trim( $json ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
				}
				if ( ! is_array( $data ) ) {
					continue;
				}
				foreach ( self::products( $data ) as $prod ) {
					$ids = self::ids( $prod );
					if ( $ids && ! in_array( $isbn_digits, $ids, true ) ) {
						continue; // another book
					}
					$p = self::offer_price( $prod['offers'] ?? null );
					if ( null !== $p ) {
						return $p;
					}
				}
			}
		}
		// 2. Open Graph product price.
		if ( preg_match( '#<meta[^>]+property=["\'](?:product|og):price:amount["\'][^>]+content=["\']([\d.,]+)["\']#i', $html, $m ) || preg_match( '#<meta[^>]+content=["\']([\d.,]+)["\'][^>]+property=["\'](?:product|og):price:amount["\']#i', $html, $m ) ) {
			return self::num( $m[1] );
		}
		// 3. Microdata.
		if ( preg_match( '#itemprop=["\']price["\'][^>]*content=["\']([\d.,]+)["\']#i', $html, $m ) || preg_match( '#content=["\']([\d.,]+)["\'][^>]*itemprop=["\']price["\']#i', $html, $m ) ) {
			return self::num( $m[1] );
		}
		return null;
	}

	/**
	 * The cover on a product page for this ISBN: the JSON-LD Product
	 * image, else og:image; an absolute http(s) address or ''.
	 */
	public static function image( string $html, string $isbn_digits ): string {
		$t = ltrim( $html );
		if ( '' === $t || '[' === $t[0] || self::other_book( $html, $isbn_digits ) ) {
			return '';
		}
		if ( preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m ) ) {
			foreach ( $m[1] as $json ) {
				$data = json_decode( trim( $json ), true );
				if ( ! is_array( $data ) ) {
					continue;
				}
				foreach ( self::products( $data ) as $prod ) {
					$ids = self::ids( $prod );
					if ( $ids && ! in_array( $isbn_digits, $ids, true ) ) {
						continue;
					}
					$img = $prod['image'] ?? null;
					if ( is_array( $img ) ) {
						$img = isset( $img[0] ) ? $img[0] : $img;
						$img = is_array( $img ) ? ( $img['contentUrl'] ?? ( $img['url'] ?? null ) ) : $img;
					}
					$u = is_string( $img ) ? self::abs_url( $img ) : '';
					if ( '' !== $u ) {
						return $u;
					}
				}
			}
		}
		foreach ( array( 'og:image:secure_url', 'og:image' ) as $prop ) {
			$q = preg_quote( $prop, '#' );
			if ( preg_match( '#<meta[^>]+property=["\']' . $q . '["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $mm ) || preg_match( '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']' . $q . '["\']#i', $html, $mm ) ) {
				$u = self::abs_url( $mm[1] );
				if ( '' !== $u ) {
					return $u;
				}
			}
		}
		return '';
	}

	/** An absolute http(s) address (or "//host/…"), else ''. */
	private static function abs_url( string $u ): string {
		$u = trim( html_entity_decode( $u, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( 0 === strpos( $u, '//' ) ) {
			$u = 'https:' . $u;
		}
		if ( strlen( $u ) > 1000 || ! preg_match( '#^https?://[^/\s"\'<>]+/\S*$#i', $u ) ) {
			return '';
		}
		return esc_url_raw( $u, array( 'http', 'https' ) );
	}

	/** The visible text of a page, one space between words. */
	private static function text( string $html ): string {
		$t = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/[\s\x{00a0}]+/u', ' ', $t ) );
	}

	/** True when the page (scripts included) names ISBNs and none is this title's. */
	private static function other_book( string $html, string $isbn_digits ): bool {
		if ( ! preg_match_all( '/97[89][\d\-\s]{10,16}/', preg_replace( '/<[^>]*>/', ' ', $html ), $mm ) ) {
			return false;
		}
		$seen = array_filter( array_unique( array_map( array( 'BKF_Record', 'isbn_digits' ), array_map( 'trim', $mm[0] ) ) ) );
		return $seen && ! in_array( $isbn_digits, $seen, true );
	}

	/**
	 * The first number after a label ("Τιμή εκδότη: 24,40 €", "Τιμή εκδότη
	 * (με ΦΠΑ) 24,40"), at most 20 other characters between, or null.
	 */
	private static function labelled( string $text, array $labels ): ?float {
		foreach ( $labels as $label ) {
			if ( ! is_string( $label ) || '' === trim( $label ) ) {
				continue;
			}
			$re = '/' . preg_quote( trim( $label ), '/' ) . '[^\d]{0,20}?(\d{1,6}(?:[.,]\d{3})*(?:[.,]\d{1,2})?)(?!\d)/iu';
			if ( preg_match( $re, $text, $m ) ) {
				$p = self::num( $m[1] );
				if ( null !== $p ) {
					return $p;
				}
			}
		}
		return null;
	}

	/** The regular price in a Store API product list. */
	private static function store_api( array $list, string $isbn_digits ): ?float {
		$hits = array();
		foreach ( $list as $prod ) {
			if ( ! is_array( $prod ) || ! isset( $prod['prices'] ) || ! is_array( $prod['prices'] ) ) {
				continue;
			}
			$sku = BKF_Record::isbn_digits( (string) ( $prod['sku'] ?? '' ) );
			if ( $sku === $isbn_digits ) {
				$hits = array( $prod );
				break;
			}
			if ( '' === $sku ) {
				$hits[] = $prod;
			}
		}
		if ( 1 !== count( $hits ) ) {
			return null;
		}
		$pr    = $hits[0]['prices'];
		$minor = isset( $pr['currency_minor_unit'] ) ? max( 0, min( 4, (int) $pr['currency_minor_unit'] ) ) : 2;
		foreach ( array( 'regular_price', 'price' ) as $k ) {
			if ( isset( $pr[ $k ] ) && is_scalar( $pr[ $k ] ) && preg_match( '/^\d{1,9}$/', (string) $pr[ $k ] ) && (int) $pr[ $k ] > 0 ) {
				return (int) $pr[ $k ] / ( 10 ** $minor );
			}
		}
		return null;
	}

	/** Every schema.org Product in a JSON-LD document (top level, @graph, lists). */
	private static function products( array $d, int $depth = 0 ): array {
		if ( $depth > 4 ) {
			return array();
		}
		$out  = array();
		$type = $d['@type'] ?? '';
		$type = is_array( $type ) ? $type : array( $type );
		if ( array_intersect( array( 'Product', 'Book', 'ProductModel', 'IndividualProduct' ), $type ) ) {
			$out[] = $d;
		}
		foreach ( array( '@graph', 'mainEntity', 'itemListElement', 'item', 'workExample' ) as $k ) {
			if ( isset( $d[ $k ] ) && is_array( $d[ $k ] ) ) {
				$list = isset( $d[ $k ][0] ) || array() === $d[ $k ] ? $d[ $k ] : array( $d[ $k ] );
				foreach ( $list as $x ) {
					if ( is_array( $x ) ) {
						$out = array_merge( $out, self::products( $x, $depth + 1 ) );
					}
				}
			}
		}
		if ( isset( $d[0] ) ) {
			foreach ( $d as $x ) {
				if ( is_array( $x ) ) {
					$out = array_merge( $out, self::products( $x, $depth + 1 ) );
				}
			}
		}
		return $out;
	}

	/** ISBN digits a Product names (gtin13, gtin, isbn, sku that is an ISBN). */
	private static function ids( array $prod ): array {
		$out = array();
		foreach ( array( 'gtin13', 'gtin', 'isbn', 'sku', 'productID' ) as $k ) {
			foreach ( (array) ( $prod[ $k ] ?? array() ) as $v ) {
				if ( is_scalar( $v ) ) {
					$d = BKF_Record::isbn_digits( preg_replace( '/^isbn:/i', '', (string) $v ) );
					if ( '' !== $d ) {
						$out[] = $d;
					}
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** The regular price in an offers value (one offer, a list, or an AggregateOffer). */
	private static function offer_price( $offers ): ?float {
		if ( ! is_array( $offers ) ) {
			return null;
		}
		$list = isset( $offers[0] ) ? $offers : array( $offers );
		foreach ( $list as $o ) {
			if ( ! is_array( $o ) ) {
				continue;
			}
			// A list price beats the selling price (sales on the publisher's shop).
			$specs = $o['priceSpecification'] ?? array();
			$specs = isset( $specs[0] ) ? $specs : array( $specs );
			foreach ( $specs as $sp ) {
				if ( is_array( $sp ) && isset( $sp['price'], $sp['priceType'] ) && is_string( $sp['priceType'] ) && preg_match( '/(ListPrice|StrikethroughPrice|SRP|MSRP)$/i', $sp['priceType'] ) ) {
					$p = self::num( $sp['price'] );
					if ( null !== $p ) {
						return $p;
					}
				}
			}
			foreach ( array( 'price', 'lowPrice' ) as $k ) {
				if ( isset( $o[ $k ] ) ) {
					$p = self::num( $o[ $k ] );
					if ( null !== $p ) {
						return $p;
					}
				}
			}
			foreach ( $specs as $sp ) {
				if ( is_array( $sp ) && isset( $sp['price'] ) ) {
					$p = self::num( $sp['price'] );
					if ( null !== $p ) {
						return $p;
					}
				}
			}
		}
		return null;
	}

	/** "12,50" / "12.50" / 12.5 / "1.234,50" → 12.5; null when not a price. */
	private static function num( $v ): ?float {
		if ( is_int( $v ) || is_float( $v ) ) {
			return $v > 0 ? (float) $v : null;
		}
		if ( ! is_string( $v ) ) {
			return null;
		}
		$v = trim( $v );
		if ( ! preg_match( '/^\d{1,6}(?:[.,]\d{3})*(?:[.,]\d{1,2})?$/', $v ) ) {
			return null;
		}
		if ( preg_match( '/[.,]\d{1,2}$/', $v ) ) {
			$dec = substr( $v, -3, 1 ) === '.' || substr( $v, -3, 1 ) === ',' ? substr( $v, -2 ) : substr( $v, -1 );
			$int = preg_replace( '/[.,]/', '', substr( $v, 0, - ( strlen( $dec ) + 1 ) ) );
			$f   = (float) ( $int . '.' . $dec );
		} else {
			$f = (float) preg_replace( '/[.,]/', '', $v );
		}
		return $f > 0 ? $f : null;
	}
}

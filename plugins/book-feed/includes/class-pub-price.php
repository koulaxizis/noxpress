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
 * The book's own page (1.9.0): when the template is a search page that
 * does not name this ISBN, the first link on the same site whose text is
 * the book's title is read too (one more request), and taken when it
 * names the ISBN. The book's page address (that link, a Store API
 * product's permalink, or the template page itself when it names the
 * ISBN) comes out as 'link'; the importer keeps it in _bkf_pub_url when
 * the product has none, so later looks go straight to it. A search page
 * that names the ISBN itself is not the book's page: its title link is
 * followed too, and a search page kept by 1.9.0 is replaced (1.9.1).
 *
 * Everything read from the page is hostile: only numbers, one image
 * address and one page address come out.
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
		return self::pub_template( $r );
	}

	/** The template for the record's publisher (settings), or ''. */
	private static function pub_template( array $r ): string {
		$s = BKF_Settings::get();
		return ( '' !== $r['pub_id'] && isset( $s['pub_prices'][ $r['pub_id'] ] ) ) ? $s['pub_prices'][ $r['pub_id'] ]['url'] : '';
	}

	/**
	 * True when $u is the publisher's template page for this record (the
	 * search page), not a book's own page. 1.9.0 kept such pages in
	 * _bkf_pub_url when the search page itself named the ISBN (1.9.1).
	 */
	public static function is_template_page( array $r, string $u ): bool {
		$t = self::url( self::pub_template( $r ), $r );
		return '' !== $u && '' !== $t && untrailingslashit( $u ) === untrailingslashit( $t );
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

	/** The book's own page on the publisher's site, when one was found, or ''. */
	public static function link( array $r ): string {
		$got = self::page( $r );
		return null !== $got && isset( $got['link'] ) && is_string( $got['link'] ) ? $got['link'] : '';
	}

	/**
	 * What the publisher's page gave for a record (price, reason, image, link),
	 * read once a day and kept in a transient; null without a page.
	 */
	private static function page( array $r, bool $fresh = false ): ?array {
		$url = self::url( self::template( $r ), $r );
		if ( '' === $url ) {
			return null;
		}
		$key = 'bkf_pp_' . md5( $r['isbn_digits'] . '|' . $url );
		$got = $fresh ? false : get_transient( $key );
		// Entries from before 1.9.1 may hold the search page as "link": read the page again.
		if ( ! is_array( $got ) || ! array_key_exists( 'img', $got ) || ! array_key_exists( 'read', $got ) || ! array_key_exists( 'link', $got ) || ( $got['v'] ?? 0 ) < 2 ) {
			$why  = '';
			$img  = '';
			$read = false;
			$link = '';
			// A product's own page is the book's page: no link to follow
			// (unless it is only the publisher's search page, 1.9.1).
			$own  = isset( $r['pub_url'] ) && is_string( $r['pub_url'] ) && '' !== $r['pub_url'] && $url === $r['pub_url'] && ! self::is_template_page( $r, $r['pub_url'] );
			$p    = self::fetch( $url, $r, $why, $img, $read, $link, ! $own );
			$got  = array(
				'p'    => $p,
				'why'  => $why,
				'img'  => $img,
				'read' => $read,
				'link' => $link,
				'v'    => 2,
			);
			set_transient( $key, $got, self::TTL );
		}
		return $got;
	}

	/**
	 * True when the publisher's page answered but has no price for this
	 * book: the page's own answer, not a passing error (1.4.2).
	 */
	public static function no_price( array $r ): bool {
		$got = self::page( $r );
		return null !== $got && ! empty( $got['read'] ) && ! isset( $got['p'] );
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

	/**
	 * Reads the page; the price or null (reason in $why, cover address in
	 * $img, $read true when the page answered, the book's page in $link).
	 * $follow: also try the book's link on a search page (1.9.0).
	 */
	public static function fetch( string $url, array $r, ?string &$why = null, ?string &$img = null, ?bool &$read = null, ?string &$link = null, bool $follow = true ): ?float {
		$img  = '';
		$read = false;
		$link = '';
		$res  = wp_safe_remote_get(
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
		$read = true;
		$body = (string) wp_remote_retrieve_body( $res );
		$t    = ltrim( $body );
		if ( '' !== $t && '[' === $t[0] ) {
			// Store API: the matching product's price, image and permalink.
			$list = json_decode( $t, true );
			$hit  = null;
			$p    = is_array( $list ) ? self::store_api( $list, $r['isbn_digits'], $hit ) : null;
			if ( is_array( $hit ) ) {
				$link = isset( $hit['permalink'] ) && is_string( $hit['permalink'] ) ? self::abs_url( $hit['permalink'] ) : '';
				$src  = $hit['images'][0]['src'] ?? '';
				$img  = is_string( $src ) ? self::abs_url( $src ) : '';
			}
		} elseif ( self::names_isbn( $body, $r['isbn_digits'] ) ) {
			$p    = self::parse( $body, $r['isbn_digits'], null, (string) $r['title'] );
			$img  = self::image( $body, $r['isbn_digits'] );
			$link = self::canonical( $body, $url );
			// A search page that lists this ISBN (Διόπτρα): the book's own
			// page still has the full-size cover and its address (1.9.1).
			$to = $follow ? self::title_link( $body, $url, (string) $r['title'] ) : '';
			if ( '' !== $to && $to !== $url && $to !== $link ) {
				$w2 = '';
				$i2 = '';
				$r2 = false;
				$l2 = '';
				$p2 = self::fetch( $to, $r, $w2, $i2, $r2, $l2, false );
				if ( '' !== $l2 ) {
					// The title's page names this ISBN: it is the book's page.
					$link = $l2;
					$p    = null !== $p2 ? $p2 : $p;
					$img  = '' !== $i2 ? $i2 : $img;
				}
			}
		} else {
			$p   = self::parse( $body, $r['isbn_digits'], null, (string) $r['title'] );
			$img = self::image( $body, $r['isbn_digits'] );
			$to  = $follow ? self::title_link( $body, $url, (string) $r['title'] ) : '';
			if ( '' !== $to && $to !== $url ) {
				$w2 = '';
				$i2 = '';
				$r2 = false;
				$l2 = '';
				$p2 = self::fetch( $to, $r, $w2, $i2, $r2, $l2, false );
				if ( '' !== $l2 || null !== $p2 ) {
					// The title's page names this ISBN, or gives a price and
					// names no other ISBN: it is the book's page.
					$link = '' !== $l2 ? $l2 : $to;
					$p    = null !== $p2 ? $p2 : $p;
					$img  = '' !== $i2 ? $i2 : $img;
				}
			}
		}
		if ( null === $p ) {
			$why = __( 'Δεν βρέθηκε τιμή για αυτό το ISBN στη σελίδα του εκδότη.', 'book-feed' );
		}
		return $p;
	}

	/**
	 * The price on a product page for this ISBN, or null. $labels: the
	 * words before a price in the text (null = the settings'); $title: the
	 * book's title, to pick its own labelled price among others (1.4.1).
	 */
	public static function parse( string $html, string $isbn_digits, ?array $labels = null, string $title = '' ): ?float {
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
		$why = '';
		$p   = self::labelled( self::text( $html ), null === $labels ? BKF_Settings::get()['pub_labels'] : $labels, $title, $why );
		if ( null !== $p ) {
			return $p;
		}
		if ( 'ambiguous' === $why ) {
			// Labelled prices of several books (lists of related titles):
			// the structured price is as likely to be another book's.
			return null;
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
	 * image, else og:image; an absolute http(s) address or ''. When the
	 * page links the same picture as a file of its own (a gallery link
	 * around a resized image), that file is taken instead (1.4.1).
	 */
	public static function image( string $html, string $isbn_digits ): string {
		$u = self::found_image( $html, $isbn_digits );
		return '' !== $u ? self::full_size( $html, $u ) : '';
	}

	/** The image the page names for the book (JSON-LD, else og:image), or ''. */
	private static function found_image( string $html, string $isbn_digits ): string {
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

	/**
	 * A link on the page to the same picture as $u without the resize
	 * marks ("/w640/", "-600x600"), e.g. <a href="/files/products/x.jpg">
	 * around <img src="/mediastream/w600/files/products/x.jpg.jpg">.
	 */
	private static function full_size( string $html, string $u ): string {
		$path = (string) wp_parse_url( $u, PHP_URL_PATH );
		$stem = preg_replace( '/(\.(?:jpe?g|png|gif|webp))+$/i', '', basename( $path ) );
		$stem = preg_replace( '/-\d{2,5}x\d{2,5}$/', '', (string) $stem );
		if ( strlen( (string) $stem ) < 4 ) {
			return $u;
		}
		$re = '#<a\s[^>]*href=["\']([^"\']*/' . preg_quote( $stem, '#' ) . '\.(?:jpe?g|png|webp))["\']#i';
		if ( ! preg_match( $re, $html, $m ) ) {
			return $u;
		}
		$link = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( '/' === substr( $link, 0, 1 ) && '/' !== substr( $link, 1, 1 ) ) {
			$host = wp_parse_url( $u, PHP_URL_SCHEME ) . '://' . wp_parse_url( $u, PHP_URL_HOST );
			$link = $host . $link;
		}
		$full = self::abs_url( $link );
		return '' !== $full ? $full : $u;
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

	/** True when the page (scripts included) names this ISBN. */
	private static function names_isbn( string $html, string $isbn_digits ): bool {
		if ( '' === $isbn_digits || ! preg_match_all( '/97[89][\d\-\s]{10,16}/', preg_replace( '/<[^>]*>/', ' ', $html ), $mm ) ) {
			return false;
		}
		return in_array( $isbn_digits, array_map( array( 'BKF_Record', 'isbn_digits' ), array_map( 'trim', $mm[0] ) ), true );
	}

	/** The page's canonical address on the same site, else the address read. */
	private static function canonical( string $html, string $url ): string {
		if ( preg_match( '#<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']#i', $html, $m ) || preg_match( '#<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']canonical["\']#i', $html, $m ) ) {
			$u = self::abs_url( $m[1] );
			if ( '' !== $u && self::same_site( $u, $url ) ) {
				return $u;
			}
		}
		return self::abs_url( $url );
	}

	/** True when two addresses are on the same host (www. aside). */
	private static function same_site( string $a, string $b ): bool {
		$ha = preg_replace( '/^www\./i', '', strtolower( (string) wp_parse_url( $a, PHP_URL_HOST ) ) );
		$hb = preg_replace( '/^www\./i', '', strtolower( (string) wp_parse_url( $b, PHP_URL_HOST ) ) );
		return '' !== $ha && $ha === $hb;
	}

	/**
	 * On a search page: the first link on the same site whose text is the
	 * book's title (accents and case aside; a subtitle after it is fine),
	 * or ''.
	 */
	private static function title_link( string $html, string $url, string $title ): string {
		$want = trim( self::fold( $title ) );
		if ( '' === $want || ! preg_match_all( '#<a\s[^>]*href=["\']([^"\'\#]+)["\'][^>]*>(.*?)</a>#is', $html, $m, PREG_SET_ORDER ) ) {
			return '';
		}
		$host = wp_parse_url( $url, PHP_URL_SCHEME ) . '://' . wp_parse_url( $url, PHP_URL_HOST );
		foreach ( $m as $a ) {
			$text = trim( self::fold( self::text( $a[2] ) ) );
			if ( '' === $text || ( $text !== $want && 0 !== strpos( $text, $want . ' ' ) && 0 !== strpos( $text, $want . ':' ) ) ) {
				continue;
			}
			$href = html_entity_decode( $a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( '/' === substr( $href, 0, 1 ) && '/' !== substr( $href, 1, 1 ) ) {
				$href = $host . $href;
			}
			$u = self::abs_url( $href );
			if ( '' !== $u && self::same_site( $u, $url ) ) {
				return $u;
			}
		}
		return '';
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
	 * The number after a label ("Τιμή εκδότη: 24,40 €", "Τιμή εκδότη
	 * (με ΦΠΑ) 24,40"), at most 20 other characters between, or null.
	 *
	 * Pages often list other books with the same label (the author's
	 * other titles, "readers also bought"), and the book's own price may
	 * be only in such a list (1.4.1, Dioptra). So, in order:
	 *  1. the label within 150 characters after this book's title, with
 *     no price, year or ISBN between (small numbers such as "ΣΕΙΡΑ 3"
 *     are fine, 1.4.2); those mean another part of the page;
	 *  2. else the label's number when every labelled number on the page
	 *     is the same;
	 *  3. else nothing, and $why = 'ambiguous'.
	 */
	private static function labelled( string $text, array $labels, string $title = '', ?string &$why = null ): ?float {
		$why  = '';
		$flat = self::fold( $text );
		$t    = trim( self::fold( $title ) );
		foreach ( $labels as $label ) {
			if ( ! is_string( $label ) || '' === trim( $label ) ) {
				continue;
			}
			$l   = preg_quote( self::fold( trim( $label ) ), '/' );
			$num = '[^\d]{0,20}?(\d{1,6}(?:[.,]\d{3})*(?:[.,]\d{1,2})?)(?!\d)';
			if ( mb_strlen( $t ) >= 3 && preg_match( '/' . preg_quote( $t, '/' ) . '(?:(?!' . $l . ')(?!\d{4})(?!\d+[.,]\d).){0,150}?' . $l . $num . '/isu', $flat, $m ) ) {
				$p = self::num( $m[1] );
				if ( null !== $p ) {
					return $p;
				}
			}
			if ( preg_match_all( '/' . $l . $num . '/iu', $flat, $mm ) ) {
				$seen = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'num' ), $mm[1] ) ) ) );
				if ( 1 === count( $seen ) ) {
					return $seen[0];
				}
				if ( count( $seen ) > 1 ) {
					$why = 'ambiguous';
				}
			}
		}
		return null;
	}

	/** Lower case without accents, for matching titles and labels. */
	private static function fold( string $s ): string {
		if ( class_exists( 'Normalizer' ) ) {
			$n = Normalizer::normalize( $s, Normalizer::FORM_D );
			if ( is_string( $n ) ) {
				$s = (string) preg_replace( '/\p{Mn}+/u', '', $n );
			}
		}
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		return str_replace( 'ς', 'σ', $s );
	}

	/** The regular price in a Store API product list ($hit: the product). */
	private static function store_api( array $list, string $isbn_digits, ?array &$hit = null ): ?float {
		$hit  = null;
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
		$hit   = $hits[0];
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

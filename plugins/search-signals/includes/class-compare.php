<?php
/**
 * SSG_Compare — the switch-over check. For a list of URLs: what the site
 * prints now (another SEO plugin, read with a GET to this site's own
 * home_url, no cookies) next to what Search Signals would print (from
 * SSG_Context::from_url(), no page rendered). Also the sitemaps: URLs in
 * one and not in the other.
 *
 * Fields: title, description, robots, canonical (and how many), og:type,
 * og:title, og:description, og:image, twitter:card, JSON-LD (how many
 * scripts, which @types; Product: price, availability, brand, GTIN).
 * Status per field: same | text (both there, different) | missing (we
 * would print nothing where the site prints something) | extra (we add
 * something the site does not print).
 *
 * Only after a click (admin) or through the compare ability. The only
 * HTTP requests of the plugin are these loopback GETs.
 */

defined( 'ABSPATH' ) || exit;

final class SSG_Compare {

	const URLS   = 'ssg_compare_urls';
	const MAX    = 40;
	const FIELDS = array( 'title', 'description', 'robots', 'canonical', 'og:type', 'og:title', 'og:description', 'og:image', 'twitter:card', 'schema', 'product' );

	/* =====================================================================
	 * Samples
	 * =================================================================== */

	/** The saved list, or the automatic samples. */
	public static function urls(): array {
		$saved = get_option( self::URLS, array() );
		return is_array( $saved ) && $saved ? array_slice( array_values( $saved ), 0, self::MAX ) : self::samples();
	}

	public static function save_urls( string $text ): array {
		$out = array();
		foreach ( preg_split( '/[\r\n]+/', $text ) as $l ) {
			$l = trim( $l );
			if ( '' === $l ) {
				continue;
			}
			if ( '/' === $l[0] ) {
				$l = home_url( $l );
			}
			$l = esc_url_raw( $l, array( 'http', 'https' ) );
			if ( '' !== $l && null !== self::same_host( $l ) && ! in_array( $l, $out, true ) && count( $out ) < self::MAX ) {
				$out[] = $l;
			}
		}
		if ( $out ) {
			update_option( self::URLS, $out, false );
		} else {
			delete_option( self::URLS );
		}
		return $out;
	}

	private static function same_host( string $url ): ?string {
		$h = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $h ) && strtolower( $h ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ? $url : null;
	}

	/**
	 * About 20 sample URLs of different kinds: home, shop, pages, products
	 * of different categories, a variable product, an out-of-stock one, a
	 * category and its child, page 2 of a big category, a brand, a post,
	 * the search, a 404.
	 */
	public static function samples(): array {
		$u   = array();
		$u[] = home_url( '/' );
		if ( function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'shop' ) > 0 ) {
			$u[] = (string) get_permalink( wc_get_page_id( 'shop' ) );
		}
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 3,
				'orderby'        => 'menu_order date',
				'order'          => 'ASC',
				'post__not_in'   => SSG_Meta::skipped_ids( 'page' ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
				'fields'         => 'ids',
			)
		);
		foreach ( $pages as $id ) {
			$u[] = (string) get_permalink( (int) $id );
		}
		if ( post_type_exists( 'product' ) ) {
			$cats = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => true,
					'parent'     => 0,
					'number'     => 4,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);
			foreach ( is_array( $cats ) ? $cats : array() as $cat ) {
				$p = get_posts(
					array(
						'post_type'      => 'product',
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'tax_query'      => array( array( 'taxonomy' => 'product_cat', 'terms' => (int) $cat->term_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					)
				);
				if ( $p ) {
					$u[] = (string) get_permalink( (int) $p[0] );
				}
			}
			if ( $cats && ! is_wp_error( $cats ) ) {
				$first = $cats[0];
				$u[]   = (string) get_term_link( $first );
				if ( (int) $first->count > (int) get_option( 'posts_per_page', 10 ) ) {
					$u[] = SSG_Meta::paged_url( (string) get_term_link( $first ), 2 );
				}
				$child = get_terms(
					array(
						'taxonomy'   => 'product_cat',
						'hide_empty' => true,
						'parent'     => (int) $first->term_id,
						'number'     => 1,
					)
				);
				if ( is_array( $child ) && $child ) {
					$u[] = (string) get_term_link( $child[0] );
				}
			}
			foreach ( array( 'variable' => array( 'type' => 'variable' ), 'outofstock' => array( 'stock_status' => 'outofstock' ), 'onbackorder' => array( 'stock_status' => 'onbackorder' ) ) as $args ) {
				if ( function_exists( 'wc_get_products' ) ) {
					$p = wc_get_products(
						array_merge(
							array(
								'status' => 'publish',
								'limit'  => 1,
								'return' => 'ids',
							),
							$args
						)
					);
					if ( $p ) {
						$u[] = (string) get_permalink( (int) $p[0] );
					}
				}
			}
			if ( taxonomy_exists( 'product_brand' ) ) {
				$b = get_terms(
					array(
						'taxonomy'   => 'product_brand',
						'hide_empty' => true,
						'number'     => 1,
						'orderby'    => 'count',
						'order'      => 'DESC',
					)
				);
				if ( is_array( $b ) && $b ) {
					$u[] = (string) get_term_link( $b[0] );
				}
			}
		}
		$post = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		if ( $post ) {
			$u[] = (string) get_permalink( (int) $post[0] );
		}
		$u[] = add_query_arg( 's', 'test', home_url( '/' ) );
		$u[] = home_url( '/ssg-compare-404-check/' );
		return array_slice( array_values( array_unique( array_filter( $u ) ) ), 0, self::MAX );
	}

	/* =====================================================================
	 * The two sides
	 * =================================================================== */

	/** What the live page prints now (loopback, logged out). */
	public static function live( string $url ): array {
		$r = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $r ) ) {
			return array( 'error' => $r->get_error_message() );
		}
		$html = (string) wp_remote_retrieve_body( $r );
		$out  = self::parse( $html );
		$out['status'] = (int) wp_remote_retrieve_response_code( $r );
		$xr            = (string) wp_remote_retrieve_header( $r, 'x-robots-tag' );
		if ( '' !== $xr && '' === $out['robots'] ) {
			$out['robots'] = self::norm_robots( $xr );
		}
		return $out;
	}

	/** Head tags of an HTML page. */
	public static function parse( string $html ): array {
		$end  = stripos( $html, '</head>' );
		$head = false !== $end ? substr( $html, 0, $end ) : substr( $html, 0, 300000 );
		$out  = array_fill_keys( self::FIELDS, '' );
		$out['canonicals'] = 0;
		$out['ld']         = 0;

		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $head, $m ) ) {
			$out['title'] = SSG_Vars::plain( $m[1] );
		}
		if ( preg_match_all( '#<meta\s[^>]*>#i', $head, $ms ) ) {
			foreach ( $ms[0] as $tag ) {
				$a   = self::attrs( $tag );
				$key = isset( $a['property'] ) ? strtolower( $a['property'] ) : ( isset( $a['name'] ) ? strtolower( $a['name'] ) : '' );
				$val = isset( $a['content'] ) ? SSG_Vars::plain( $a['content'] ) : '';
				if ( 'description' === $key && '' === $out['description'] ) {
					$out['description'] = $val;
				} elseif ( 'robots' === $key ) {
					$out['robots'] = self::norm_robots( $val );
				} elseif ( in_array( $key, array( 'og:type', 'og:title', 'og:description', 'og:image', 'twitter:card' ), true ) && '' === $out[ $key ] ) {
					$out[ $key ] = $val;
				}
			}
		}
		if ( preg_match_all( '#<link\s[^>]*>#i', $head, $ls ) ) {
			foreach ( $ls[0] as $tag ) {
				$a = self::attrs( $tag );
				if ( isset( $a['rel'] ) && 'canonical' === strtolower( $a['rel'] ) ) {
					++$out['canonicals'];
					if ( '' === $out['canonical'] ) {
						$out['canonical'] = isset( $a['href'] ) ? html_entity_decode( $a['href'], ENT_QUOTES ) : '';
					}
				}
			}
		}
		$nodes = array();
		if ( preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $ss ) ) {
			foreach ( $ss[1] as $json ) {
				$d = json_decode( html_entity_decode( trim( $json ), ENT_NOQUOTES ), true );
				if ( ! is_array( $d ) ) {
					continue;
				}
				++$out['ld'];
				$nodes = array_merge( $nodes, self::flatten( $d ) );
			}
		}
		$out['schema']  = self::types( $nodes );
		$out['product'] = self::product_summary( $nodes );
		return $out;
	}

	/** What Search Signals would print for a URL. */
	public static function ours( string $url ): array {
		$out = array_fill_keys( self::FIELDS, '' );
		$c   = SSG_Context::from_url( $url );
		if ( null === $c ) {
			return array( 'error' => 'other host' );
		}
		$out['kind']   = $c->kind;
		$out['title']  = SSG_Meta::title( $c );
		$out['description'] = SSG_Meta::description( $c );
		$r             = SSG_Meta::robots( $c );
		$out['robots'] = ( $r['noindex'] ? 'noindex' : 'index' ) . ', ' . ( $r['nofollow'] ? 'nofollow' : 'follow' );
		$can           = SSG_Meta::canonical( $c );
		$out['canonical']  = $r['noindex'] ? '' : $can;
		$out['canonicals'] = '' !== $out['canonical'] ? 1 : 0;
		foreach ( SSG_Social::tags( $c ) as $t ) {
			if ( is_array( $t ) && isset( $t[1], $t[2] ) && array_key_exists( (string) $t[1], $out ) && '' === $out[ $t[1] ] ) {
				$out[ $t[1] ] = SSG_Vars::plain( (string) $t[2] );
			}
		}
		$nodes          = SSG_Schema::build( $c );
		$out['ld']      = $nodes ? 1 : 0;
		$out['schema']  = self::types( $nodes );
		$out['product'] = self::product_summary( $nodes );
		return $out;
	}

	/* =====================================================================
	 * Comparison
	 * =================================================================== */

	/** One URL: [ url, live, ours, fields => [ field => status ] ]. */
	public static function one( string $url ): array {
		$live = self::live( $url );
		$ours = self::ours( $url );
		$f    = array();
		if ( ! isset( $live['error'] ) && ! isset( $ours['error'] ) ) {
			foreach ( self::FIELDS as $k ) {
				$f[ $k ] = self::status( $k, (string) $live[ $k ], (string) $ours[ $k ] );
			}
			$f['canonicals'] = (int) $live['canonicals'] > 1 ? 'text' : 'same';
			$f['ld']         = (int) $live['ld'] > 1 ? 'text' : 'same';
		}
		return array(
			'url'    => $url,
			'live'   => $live,
			'ours'   => $ours,
			'fields' => $f,
		);
	}

	/** Several URLs, with a summary. */
	public static function run( array $urls ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$rows = array();
		$sum  = array(
			'same'    => 0,
			'text'    => 0,
			'missing' => 0,
			'extra'   => 0,
		);
		foreach ( array_slice( $urls, 0, self::MAX ) as $u ) {
			$row    = self::one( (string) $u );
			$rows[] = $row;
			foreach ( $row['fields'] as $st ) {
				if ( isset( $sum[ $st ] ) ) {
					++$sum[ $st ];
				}
			}
		}
		return array(
			'time'    => time(),
			'other'   => SSG_Detect::other(),
			'rows'    => $rows,
			'summary' => $sum,
		);
	}

	private static function status( string $k, string $live, string $ours ): string {
		$a = self::norm( $k, $live );
		$b = self::norm( $k, $ours );
		if ( $a === $b ) {
			return 'same';
		}
		if ( '' === $b ) {
			return 'missing';
		}
		if ( '' === $a ) {
			return 'extra';
		}
		return 'text';
	}

	private static function norm( string $k, string $v ): string {
		$v = trim( (string) preg_replace( '/\s+/u', ' ', $v ) );
		if ( in_array( $k, array( 'canonical', 'og:image' ), true ) ) {
			return untrailingslashit( preg_replace( '#^https?://#', '', $v ) );
		}
		return 'robots' === $k ? strtolower( $v ) : $v;
	}

	/** «index, follow» from any robots content. */
	public static function norm_robots( string $v ): string {
		$v = strtolower( $v );
		if ( '' === trim( $v ) ) {
			return '';
		}
		$idx = false !== strpos( $v, 'noindex' ) || false !== strpos( $v, 'none' ) ? 'noindex' : 'index';
		$fol = false !== strpos( $v, 'nofollow' ) || false !== strpos( $v, 'none' ) ? 'nofollow' : 'follow';
		return $idx . ', ' . $fol;
	}

	private static function attrs( string $tag ): array {
		$out = array();
		if ( preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("([^"]*)"|\'([^\']*)\')/', $tag, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $a ) {
				$out[ strtolower( $a[1] ) ] = isset( $a[4] ) && '' !== $a[4] ? $a[4] : $a[3];
			}
		}
		return $out;
	}

	/** Every node of a JSON-LD document (top level, @graph, arrays). */
	private static function flatten( array $d ): array {
		if ( isset( $d['@graph'] ) && is_array( $d['@graph'] ) ) {
			return array_values( array_filter( $d['@graph'], 'is_array' ) );
		}
		if ( isset( $d['@type'] ) ) {
			return array( $d );
		}
		$out = array();
		foreach ( $d as $n ) {
			if ( is_array( $n ) ) {
				$out = array_merge( $out, self::flatten( $n ) );
			}
		}
		return $out;
	}

	/** Sorted, comma separated @types of the top-level nodes. */
	private static function types( array $nodes ): string {
		$t = array();
		foreach ( $nodes as $n ) {
			if ( is_array( $n ) && isset( $n['@type'] ) ) {
				foreach ( (array) $n['@type'] as $x ) {
					$t[] = (string) $x;
				}
			}
		}
		$t = array_unique( $t );
		sort( $t );
		return implode( ', ', $t );
	}

	/** «price currency · availability · brand · gtin» of the Product node. */
	private static function product_summary( array $nodes ): string {
		foreach ( $nodes as $n ) {
			if ( ! is_array( $n ) || ! isset( $n['@type'] ) || ! in_array( 'Product', (array) $n['@type'], true ) ) {
				continue;
			}
			$o = isset( $n['offers'] ) && is_array( $n['offers'] ) ? $n['offers'] : array();
			if ( isset( $o[0] ) && is_array( $o[0] ) ) {
				$o = $o[0];
			}
			$price = isset( $o['price'] ) ? (string) $o['price'] : ( isset( $o['lowPrice'] ) ? (string) $o['lowPrice'] : '' );
			// WooCommerce 9+ puts the price in priceSpecification.
			if ( '' === $price && isset( $o['priceSpecification'] ) && is_array( $o['priceSpecification'] ) ) {
				$ps    = isset( $o['priceSpecification'][0] ) ? $o['priceSpecification'][0] : $o['priceSpecification'];
				$price = is_array( $ps ) && isset( $ps['price'] ) ? (string) $ps['price'] : '';
			}
			if ( '' !== $price ) {
				$price = rtrim( rtrim( number_format( (float) $price, 2, '.', '' ), '0' ), '.' );
			}
			$avail = isset( $o['availability'] ) ? (string) preg_replace( '#^https?://schema\.org/#', '', (string) $o['availability'] ) : '';
			$brand = '';
			if ( isset( $n['brand'] ) ) {
				$brand = is_array( $n['brand'] ) ? ( isset( $n['brand']['name'] ) ? (string) $n['brand']['name'] : '' ) : (string) $n['brand'];
			}
			$gtin = '';
			foreach ( array( 'gtin', 'gtin13', 'gtin12', 'gtin8', 'gtin14', 'isbn' ) as $g ) {
				if ( ! empty( $n[ $g ] ) ) {
					$gtin = (string) $n[ $g ];
					break;
				}
			}
			return implode( ' · ', array( $price, $avail, $brand, $gtin ) );
		}
		return '';
	}

	/* =====================================================================
	 * Sitemaps
	 * =================================================================== */

	/** URLs of the live /sitemap_index.xml (whoever serves it) and of ours. */
	public static function sitemaps(): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$live  = array();
		$files = array();
		$index = self::fetch( home_url( '/sitemap_index.xml' ) );
		if ( preg_match_all( '#<sitemap>\s*<loc>([^<]+)</loc>#i', $index, $m ) ) {
			foreach ( array_slice( $m[1], 0, 100 ) as $f ) {
				$f    = html_entity_decode( trim( $f ), ENT_QUOTES );
				$body = self::fetch( $f );
				$n    = 0;
				if ( preg_match_all( '#<url>\s*<loc>([^<]+)</loc>#i', $body, $u ) ) {
					foreach ( $u[1] as $loc ) {
						$live[] = untrailingslashit( html_entity_decode( trim( $loc ), ENT_QUOTES ) );
						++$n;
					}
				}
				$files[ (string) wp_parse_url( $f, PHP_URL_PATH ) ] = $n;
			}
		}
		$ours      = array();
		$our_files = array();
		if ( ! class_exists( 'SSG_Sitemaps', false ) ) {
			require_once SSG_PATH . 'includes/class-sitemaps.php';
		}
		foreach ( SSG_Sitemaps::sources() as $name => $d ) {
			for ( $i = 1; $i <= $d['pages']; $i++ ) {
				$n = 0;
				foreach ( SSG_Sitemaps::entries( (string) $name, $i ) as $e ) {
					$ours[] = untrailingslashit( (string) $e['loc'] );
					++$n;
				}
				$our_files[ (string) wp_parse_url( SSG_Sitemaps::file_url( (string) $name, $i, (int) $d['pages'] ), PHP_URL_PATH ) ] = $n;
			}
		}
		$live = array_values( array_unique( $live ) );
		$ours = array_values( array_unique( $ours ) );
		return array(
			'time'       => time(),
			'live_files' => $files,
			'our_files'  => $our_files,
			'live'       => count( $live ),
			'ours'       => count( $ours ),
			'only_live'  => array_slice( array_values( array_diff( $live, $ours ) ), 0, 200 ),
			'only_ours'  => array_slice( array_values( array_diff( $ours, $live ) ), 0, 200 ),
		);
	}

	private static function fetch( string $url ): string {
		if ( null === self::same_host( $url ) ) {
			return '';
		}
		$r = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 2,
			)
		);
		return is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_body( $r );
	}
}

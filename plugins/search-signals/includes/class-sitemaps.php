<?php
/**
 * SSG_Sitemaps — XML sitemaps at the addresses Rank Math uses, so Search
 * Console needs no change:
 *  - /sitemap_index.xml
 *  - /{post type}-sitemap.xml, or /{post type}-sitemap{N}.xml when there
 *    is more than one file (product-sitemap1.xml …), sm_per URLs a file
 *  - /{taxonomy}-sitemap.xml (category, product_cat …), paged the same way
 *  - /author-sitemap.xml (only with sm_authors)
 *  - /sitemap.xml and /wp-sitemap.xml → 301 to /sitemap_index.xml
 *  - /ssg-sitemap.xsl, the stylesheet that makes them readable in a browser
 *
 * Served on parse_request (priority 1) by matching the path, so there are
 * no rewrite rules to flush: they work from the first request after the
 * switch. An empty sitemap is left out of the index (and answers 404).
 *
 * Left out: noindex objects (own robots or the template of their type),
 * passwords, drafts, hidden products (catalog visibility «hidden»), cart /
 * checkout / account pages, and whatever the core filters
 * wp_sitemaps_post_types, wp_sitemaps_taxonomies,
 * wp_sitemaps_posts_query_args, wp_sitemaps_taxonomies_query_args and our
 * noxpress_seo_sitemap_entry remove.
 *
 * Nothing is written on a visitor request (§14.5): the XML is kept in the
 * object cache (group ssg_sitemap) under the «last changed» of posts and
 * terms (§14.14), so it is rebuilt only after a change.
 */

defined( 'ABSPATH' ) || exit;

final class SSG_Sitemaps {

	const GROUP = 'ssg_sitemap';
	const XSL   = 'ssg-sitemap.xsl';

	public static function init(): void {
		add_action( 'parse_request', array( __CLASS__, 'serve' ), 1 );
		add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 20, 2 );
	}

	/* =====================================================================
	 * Routing
	 * =================================================================== */

	/** The requested path relative to the home URL, without query. */
	private static function request_path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $base && '/' !== $base && 0 === strpos( $path, $base ) ) {
			$path = substr( $path, strlen( rtrim( $base, '/' ) ) );
		}
		return ltrim( $path, '/' );
	}

	/**
	 * What a path asks for: [ 'index' ] | [ 'file', name, page ] |
	 * [ 'xsl' ] | [ 'redirect' ] | [].
	 */
	public static function route( string $path ): array {
		$path = strtolower( $path );
		if ( 'sitemap_index.xml' === $path ) {
			return array( 'index' );
		}
		if ( 'sitemap.xml' === $path || 'wp-sitemap.xml' === $path ) {
			return array( 'redirect' );
		}
		if ( self::XSL === $path ) {
			return array( 'xsl' );
		}
		if ( preg_match( '/^([a-z0-9_\-]{1,40})-sitemap([0-9]{0,5})\.xml$/', $path, $m ) ) {
			return array( 'file', $m[1], '' === $m[2] ? 1 : max( 1, (int) $m[2] ) );
		}
		return array();
	}

	/** @param WP $wp */
	public static function serve( $wp ): void {
		try {
			$r = self::route( self::request_path() );
			if ( ! $r || ! Search_Signals::serving() ) {
				return;
			}
			if ( 'redirect' === $r[0] ) {
				wp_safe_redirect( home_url( '/sitemap_index.xml' ), 301, 'Search Signals' );
				exit;
			}
			if ( 'xsl' === $r[0] ) {
				self::send_headers( 'text/xsl' );
				echo self::xsl(); // phpcs:ignore WordPress.Security.EscapeOutput -- static XSL.
				exit;
			}
			$xml = 'index' === $r[0] ? self::index_xml() : self::file_xml( (string) $r[1], (int) $r[2] );
			if ( '' === $xml ) {
				return; // Not ours (or empty): WordPress answers, usually 404.
			}
			self::send_headers( 'text/xml' );
			echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while built.
			exit;
		} catch ( \Throwable $e ) {
			Search_Signals::fail( $e );
		}
	}

	private static function send_headers( string $type ): void {
		if ( headers_sent() ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: ' . $type . '; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow' );
		header( 'X-Search-Signals: sitemap' );
		nocache_headers();
	}

	/**
	 * @param string $out
	 * @param bool   $public
	 */
	public static function robots_txt( $out, $public = true ) {
		try {
			if ( ! $public || ! Search_Signals::serving() ) {
				return $out;
			}
			$line = 'Sitemap: ' . home_url( '/sitemap_index.xml' );
			if ( false === strpos( (string) $out, $line ) ) {
				$out = rtrim( (string) $out ) . "\n\n" . $line . "\n";
			}
			return $out;
		} catch ( \Throwable $e ) {
			return $out;
		}
	}

	/* =====================================================================
	 * Sources
	 * =================================================================== */

	/**
	 * The sitemaps that exist: name => [ 'kind' => post|term|author,
	 * 'object' => post type / taxonomy, 'count' => URLs, 'pages' => files,
	 * 'lastmod' => ISO 8601 or '' ].
	 */
	public static function sources(): array {
		$key = 'sources_' . self::version();
		$hit = wp_cache_get( $key, self::GROUP );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$per = SSG_Settings::get()['sm_per'];
		$out = array();
		foreach ( self::post_types() as $pt ) {
			$n = self::count_posts( $pt );
			if ( $n > 0 ) {
				$out[ $pt ] = array(
					'kind'    => 'post',
					'object'  => $pt,
					'count'   => $n,
					'pages'   => (int) ceil( $n / $per ),
					'lastmod' => self::last_modified( $pt ),
				);
			}
		}
		foreach ( self::taxonomies() as $tx ) {
			if ( isset( $out[ $tx ] ) ) {
				continue;
			}
			$n = self::count_terms( $tx );
			if ( $n > 0 ) {
				$out[ $tx ] = array(
					'kind'    => 'term',
					'object'  => $tx,
					'count'   => $n,
					'pages'   => (int) ceil( $n / $per ),
					'lastmod' => self::last_modified_terms( $tx ),
				);
			}
		}
		if ( self::authors_on() && ! isset( $out['author'] ) ) {
			$ids = self::author_ids();
			if ( $ids ) {
				$out['author'] = array(
					'kind'    => 'author',
					'object'  => 'author',
					'count'   => count( $ids ),
					'pages'   => (int) ceil( count( $ids ) / $per ),
					'lastmod' => '',
				);
			}
		}
		wp_cache_set( $key, $out, self::GROUP, HOUR_IN_SECONDS );
		return $out;
	}

	/** Post types in the sitemap, after the core filter and noindex. */
	public static function post_types(): array {
		$objs = array();
		foreach ( SSG_Settings::sitemap_types() as $pt ) {
			$o = get_post_type_object( $pt );
			if ( $o && ! SSG_Settings::tpl( 'pt_' . $pt )['n'] ) {
				$objs[ $pt ] = $o;
			}
		}
		$objs = apply_filters( 'wp_sitemaps_post_types', $objs );
		return is_array( $objs ) ? array_values( array_map( 'strval', array_keys( $objs ) ) ) : array();
	}

	public static function taxonomies(): array {
		$objs = array();
		foreach ( SSG_Settings::sitemap_taxes() as $tx ) {
			$o = get_taxonomy( $tx );
			if ( $o && ! SSG_Settings::tpl( 'tax_' . $tx )['n'] ) {
				$objs[ $tx ] = $o;
			}
		}
		$objs = apply_filters( 'wp_sitemaps_taxonomies', $objs );
		return is_array( $objs ) ? array_values( array_map( 'strval', array_keys( $objs ) ) ) : array();
	}

	private static function authors_on(): bool {
		return SSG_Settings::get()['sm_authors'] && ! SSG_Settings::tpl( 'author' )['n'];
	}

	/** Query args of a post type sitemap page. */
	private static function post_args( string $pt, int $page, int $per ): array {
		$args = array(
			'post_type'              => $pt,
			'post_status'            => 'publish',
			'has_password'           => false,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'posts_per_page'         => $per,
			'paged'                  => max( 1, $page ),
			'fields'                 => 'ids',
			'ignore_sticky_posts'    => true,
			'suppress_filters'       => false,
			'update_post_term_cache' => false,
			'meta_query'             => SSG_Meta::indexable_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- sitemap, cached.
		);
		$skip = SSG_Meta::skipped_ids( $pt );
		if ( $skip ) {
			$args['post__not_in'] = $skip; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- small list.
		}
		$args = apply_filters( 'wp_sitemaps_posts_query_args', $args, $pt );
		return is_array( $args ) ? $args : array();
	}

	private static function count_posts( string $pt ): int {
		$args                   = self::post_args( $pt, 1, 1 );
		$args['no_found_rows']  = false;
		$args['posts_per_page'] = 1;
		$q                      = new WP_Query( $args );
		return (int) $q->found_posts;
	}

	/** Newest post_modified_gmt of a post type, ISO 8601. */
	private static function last_modified( string $pt ): string {
		$q = new WP_Query(
			array(
				'post_type'      => $pt,
				'post_status'    => 'publish',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		if ( empty( $q->posts ) ) {
			return '';
		}
		return (string) get_post_modified_time( 'c', true, (int) $q->posts[0] );
	}

	private static function term_args( string $tx, int $page, int $per ): array {
		$args = array(
			'taxonomy'               => $tx,
			'fields'                 => 'ids',
			'orderby'                => 'term_order',
			'number'                 => $per,
			'offset'                 => ( max( 1, $page ) - 1 ) * $per,
			'hide_empty'             => true,
			'hierarchical'           => false,
			'update_term_meta_cache' => false,
			'meta_query'             => SSG_Meta::indexable_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- sitemap, cached.
		);
		$args = apply_filters( 'wp_sitemaps_taxonomies_query_args', $args, $tx );
		return is_array( $args ) ? $args : array();
	}

	private static function count_terms( string $tx ): int {
		$args = self::term_args( $tx, 1, 0 );
		unset( $args['number'], $args['offset'] );
		$args['fields'] = 'count';
		$n              = get_terms( $args );
		return is_numeric( $n ) ? (int) $n : 0;
	}

	/** Newest post modified among the posts of a taxonomy (one query). */
	private static function last_modified_terms( string $tx ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one aggregate, cached in sources().
		$v = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(p.post_modified_gmt) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tt.taxonomy = %s AND p.post_status = 'publish'",
				$tx
			)
		);
		return is_string( $v ) && '' !== $v && '0000-00-00 00:00:00' !== $v ? gmdate( 'c', (int) strtotime( $v . ' UTC' ) ) : '';
	}

	private static function author_ids(): array {
		$types = self::post_types();
		if ( ! $types ) {
			return array();
		}
		$ids = get_users(
			array(
				'has_published_posts' => $types,
				'fields'              => 'ID',
				'orderby'             => 'ID',
				'number'              => 2000,
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/* =====================================================================
	 * Entries
	 * =================================================================== */

	/**
	 * Entries of one file: [ [ loc, lastmod, images[] ], … ].
	 */
	public static function entries( string $name, int $page ): array {
		$src = self::sources();
		if ( ! isset( $src[ $name ] ) || $page < 1 || $page > $src[ $name ]['pages'] ) {
			return array();
		}
		$s   = SSG_Settings::get();
		$per = $s['sm_per'];
		$out = array();
		$def = $src[ $name ];

		if ( 'post' === $def['kind'] ) {
			$q   = new WP_Query( self::post_args( $def['object'], $page, $per ) );
			$ids = array_map( 'intval', (array) $q->posts );
			if ( $ids ) {
				_prime_post_caches( $ids, false, true );
			}
			$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( ! $post instanceof WP_Post ) {
					continue;
				}
				$loc = $id === $front ? home_url( '/' ) : (string) get_permalink( $post );
				// A page whose own canonical is another address is not listed
				// (the other address is), as with Rank Math.
				$canon = get_post_meta( $id, SSG_Meta::CANON, true );
				if ( is_string( $canon ) && '' !== $canon && untrailingslashit( $canon ) !== untrailingslashit( $loc ) ) {
					continue;
				}
				$e   = array(
					'loc'     => $loc,
					'lastmod' => (string) get_post_modified_time( 'c', true, $post ),
					'images'  => $s['sm_images'] ? self::images( $post ) : array(),
				);
				$e = self::filter_entry( $e, 'post', $post );
				if ( $e ) {
					$out[] = $e;
				}
			}
		} elseif ( 'term' === $def['kind'] ) {
			$ids = get_terms( self::term_args( $def['object'], $page, $per ) );
			foreach ( is_array( $ids ) ? $ids : array() as $tid ) {
				$t = get_term( (int) $tid, $def['object'] );
				if ( ! $t instanceof WP_Term ) {
					continue;
				}
				$link = get_term_link( $t );
				if ( ! is_string( $link ) ) {
					continue;
				}
				$e = array(
					'loc'     => $link,
					'lastmod' => '',
					'images'  => array(),
				);
				$thumb = (int) get_term_meta( $t->term_id, 'thumbnail_id', true );
				if ( $thumb && $s['sm_images'] ) {
					$u = wp_get_attachment_url( $thumb );
					if ( $u ) {
						$e['images'][] = $u;
					}
				}
				$e = self::filter_entry( $e, 'term', $t );
				if ( $e ) {
					$out[] = $e;
				}
			}
		} else {
			$ids = array_slice( self::author_ids(), ( $page - 1 ) * $per, $per );
			foreach ( $ids as $uid ) {
				$u = get_userdata( $uid );
				if ( ! $u ) {
					continue;
				}
				$e = self::filter_entry(
					array(
						'loc'     => get_author_posts_url( $uid ),
						'lastmod' => '',
						'images'  => array(),
					),
					'author',
					$u
				);
				if ( $e ) {
					$out[] = $e;
				}
			}
		}
		return $out;
	}

	/**
	 * @param array  $e
	 * @param string $type post | term | author
	 * @param object $obj
	 * @return array Empty = left out.
	 */
	private static function filter_entry( array $e, string $type, $obj ): array {
		/**
		 * One URL of a sitemap. Return false to leave it out.
		 *
		 * @param array  $e    [ 'loc' => url, 'lastmod' => ISO 8601, 'images' => url[] ]
		 * @param string $type post | term | author
		 * @param object $obj  WP_Post | WP_Term | WP_User
		 */
		$e = apply_filters( 'noxpress_seo_sitemap_entry', $e, $type, $obj );
		if ( ! is_array( $e ) || empty( $e['loc'] ) ) {
			return array();
		}
		return $e;
	}

	/** Featured image and the WooCommerce gallery of a post. */
	private static function images( WP_Post $post ): array {
		$ids   = array();
		$thumb = (int) get_post_thumbnail_id( $post );
		if ( $thumb ) {
			$ids[] = $thumb;
		}
		if ( 'product' === $post->post_type ) {
			$gallery = (string) get_post_meta( $post->ID, '_product_image_gallery', true );
			foreach ( wp_parse_id_list( $gallery ) as $gid ) {
				$ids[] = (int) $gid;
			}
		}
		$out = array();
		foreach ( array_slice( array_unique( $ids ), 0, 20 ) as $id ) {
			$u = wp_get_attachment_url( $id );
			if ( $u ) {
				$out[] = $u;
			}
		}
		return $out;
	}

	/* =====================================================================
	 * XML
	 * =================================================================== */

	/** URL of one file of a sitemap. */
	public static function file_url( string $name, int $page, int $pages ): string {
		return home_url( '/' . $name . '-sitemap' . ( $pages > 1 ? (string) $page : '' ) . '.xml' );
	}

	private static function head(): string {
		$xsl = (string) preg_replace( '#^https?:#', '', home_url( '/' . self::XSL ) );
		return '<?xml version="1.0" encoding="UTF-8"?>' . '<?xml-stylesheet type="text/xsl" href="' . esc_attr( $xsl ) . '"?>' . "\n";
	}

	public static function index_xml(): string {
		$key = 'index_' . self::version();
		$hit = wp_cache_get( $key, self::GROUP );
		if ( is_string( $hit ) ) {
			return $hit;
		}
		$src = self::sources();
		if ( ! $src ) {
			return '';
		}
		$xml = self::head() . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( $src as $name => $d ) {
			for ( $i = 1; $i <= $d['pages']; $i++ ) {
				$xml .= "\t<sitemap>\n\t\t<loc>" . esc_url( self::file_url( (string) $name, $i, (int) $d['pages'] ) ) . "</loc>\n";
				if ( '' !== $d['lastmod'] ) {
					$xml .= "\t\t<lastmod>" . esc_html( $d['lastmod'] ) . "</lastmod>\n";
				}
				$xml .= "\t</sitemap>\n";
			}
		}
		$xml .= "</sitemapindex>\n<!-- Search Signals -->";
		wp_cache_set( $key, $xml, self::GROUP, HOUR_IN_SECONDS );
		return $xml;
	}

	public static function file_xml( string $name, int $page ): string {
		$src = self::sources();
		if ( ! isset( $src[ $name ] ) ) {
			return '';
		}
		// One file only: page-sitemap.xml; several: product-sitemap1.xml …
		// page-sitemap1.xml of a single-file sitemap is accepted too.
		$key = 'file_' . md5( $name . '|' . $page ) . '_' . self::version();
		$hit = wp_cache_get( $key, self::GROUP );
		if ( is_string( $hit ) ) {
			return $hit;
		}
		$entries = self::entries( $name, $page );
		if ( ! $entries ) {
			return '';
		}
		$xml = self::head() . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
		foreach ( $entries as $e ) {
			$xml .= "\t<url>\n\t\t<loc>" . esc_url( (string) $e['loc'] ) . "</loc>\n";
			if ( ! empty( $e['lastmod'] ) ) {
				$xml .= "\t\t<lastmod>" . esc_html( (string) $e['lastmod'] ) . "</lastmod>\n";
			}
			foreach ( isset( $e['images'] ) ? (array) $e['images'] : array() as $img ) {
				$xml .= "\t\t<image:image>\n\t\t\t<image:loc>" . esc_url( (string) $img ) . "</image:loc>\n\t\t</image:image>\n";
			}
			$xml .= "\t</url>\n";
		}
		$xml .= "</urlset>\n<!-- Search Signals -->";
		wp_cache_set( $key, $xml, self::GROUP, HOUR_IN_SECONDS );
		return $xml;
	}

	/** Cache version: changes with posts, terms and our settings. */
	private static function version(): string {
		return md5(
			wp_cache_get_last_changed( 'posts' ) . '|' . wp_cache_get_last_changed( 'terms' ) . '|' . SSG_VERSION . '|' . wp_json_encode( SSG_Settings::get() ) . '|' . home_url()
		);
	}

	/** Status for the admin page and the abilities. */
	public static function status(): array {
		$src   = self::sources();
		$files = array();
		$total = 0;
		foreach ( $src as $name => $d ) {
			for ( $i = 1; $i <= $d['pages']; $i++ ) {
				$files[] = array(
					'url'  => self::file_url( (string) $name, $i, (int) $d['pages'] ),
					'type' => $d['kind'] . ':' . $d['object'],
					'urls' => min( SSG_Settings::get()['sm_per'], $d['count'] - ( $i - 1 ) * SSG_Settings::get()['sm_per'] ),
				);
			}
			$total += (int) $d['count'];
		}
		return array(
			'index'          => home_url( '/sitemap_index.xml' ),
			'files'          => $files,
			'urls'           => $total,
			'per_file'       => SSG_Settings::get()['sm_per'],
			'images'         => SSG_Settings::get()['sm_images'],
			'core_sitemaps'  => (bool) apply_filters( 'wp_sitemaps_enabled', (bool) get_option( 'blog_public' ) ),
			'robots_txt'     => file_exists( ABSPATH . 'robots.txt' ) ? 'static file in the web root (our Sitemap line is not added)' : 'virtual',
			'search_engines' => '1' === (string) get_option( 'blog_public' ),
		);
	}

	private static function xsl(): string {
		return '<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
<xsl:output method="html" encoding="UTF-8" indent="yes"/>
<xsl:template match="/">
<html><head><meta charset="UTF-8"/><meta name="robots" content="noindex"/><title>XML Sitemap</title>
<style>body{font:14px/1.5 system-ui,sans-serif;margin:24px;color:#1d2327}table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:6px 10px;border-bottom:1px solid #e2e4e7}th{background:#f6f7f7}a{color:#2271b1}p{color:#50575e}</style></head>
<body><h1>XML Sitemap</h1><p>Search Signals · <xsl:value-of select="count(s:sitemapindex/s:sitemap) + count(s:urlset/s:url)"/> URLs</p>
<xsl:if test="s:sitemapindex"><table><tr><th>Sitemap</th><th>Last modified</th></tr>
<xsl:for-each select="s:sitemapindex/s:sitemap"><tr><td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td><td><xsl:value-of select="s:lastmod"/></td></tr></xsl:for-each></table></xsl:if>
<xsl:if test="s:urlset"><table><tr><th>URL</th><th>Images</th><th>Last modified</th></tr>
<xsl:for-each select="s:urlset/s:url"><tr><td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td><td><xsl:value-of select="count(image:image)"/></td><td><xsl:value-of select="s:lastmod"/></td></tr></xsl:for-each></table></xsl:if>
</body></html>
</xsl:template>
</xsl:stylesheet>';
	}
}

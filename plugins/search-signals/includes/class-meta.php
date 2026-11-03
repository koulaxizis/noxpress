<?php
/**
 * SSG_Meta — title, description, robots and canonical of a context (pure:
 * no output, no hook of its own). Per-object values come from the _ssg_*
 * meta (posts) and term meta (terms); everything else from the templates
 * of SSG_Settings.
 *
 * Meta keys (hidden, registered by SSG_Editor):
 *  _ssg_title, _ssg_desc       templates too (%variables% allowed)
 *  _ssg_robots                 array with 'noindex' and / or 'nofollow'
 *  _ssg_canonical              absolute URL
 *  _ssg_og_image               attachment ID of the share image
 *  _ssg_primary_{taxonomy}     term ID (product_cat, product_brand, category)
 */

defined( 'ABSPATH' ) || exit;

final class SSG_Meta {

	const TITLE   = '_ssg_title';
	const DESC    = '_ssg_desc';
	const ROBOTS  = '_ssg_robots';
	const CANON   = '_ssg_canonical';
	const IMAGE   = '_ssg_og_image';
	const PRIMARY = '_ssg_primary_';

	/** Taxonomies with a primary term. */
	const PRIMARY_TAXES = array( 'product_cat', 'product_brand', 'category' );

	/** The meta value of the object behind a context ('' when none). */
	public static function own( SSG_Context $c, string $key ) {
		if ( $c->has_post() ) {
			return get_post_meta( $c->post_id, $key, true );
		}
		if ( 'term' === $c->kind && $c->term_id ) {
			return get_term_meta( $c->term_id, $key, true );
		}
		return '';
	}

	public static function title( SSG_Context $c ): string {
		$own   = self::own( $c, self::TITLE );
		$title = '';
		if ( is_string( $own ) && '' !== trim( $own ) ) {
			$title = SSG_Vars::render( $own, $c );
		}
		if ( '' === $title ) {
			$key   = $c->tpl_key();
			$title = '' !== $key ? SSG_Vars::render( SSG_Settings::tpl( $key )['t'], $c ) : '';
		}
		if ( '' === $title ) {
			$title = SSG_Vars::render( '%title% %term% %sep% %sitename%', $c );
		}
		/**
		 * The <title> (and og:title) of a page, plain text.
		 *
		 * @param string      $title
		 * @param SSG_Context $c
		 */
		return trim( (string) apply_filters( 'noxpress_seo_title', $title, $c ) );
	}

	public static function description( SSG_Context $c ): string {
		$own  = self::own( $c, self::DESC );
		$desc = '';
		if ( is_string( $own ) && '' !== trim( $own ) ) {
			$desc = SSG_Vars::render( $own, $c );
		}
		if ( '' === $desc ) {
			$key  = $c->tpl_key();
			$desc = '' !== $key ? SSG_Vars::render( SSG_Settings::tpl( $key )['d'], $c ) : '';
		}
		/**
		 * The meta description of a page, plain text ('' = no tag).
		 *
		 * @param string      $desc
		 * @param SSG_Context $c
		 */
		return trim( (string) apply_filters( 'noxpress_seo_description', $desc, $c ) );
	}

	/**
	 * Robots of a context: [ 'noindex' => bool, 'nofollow' => bool ].
	 */
	public static function robots( SSG_Context $c ): array {
		$s   = SSG_Settings::get();
		$key = $c->tpl_key();
		$r   = array(
			'noindex'  => '' !== $key ? (bool) SSG_Settings::tpl( $key )['n'] : false,
			'nofollow' => false,
		);
		if ( '404' === $c->kind || 'search' === $c->kind ) {
			$r['noindex'] = $r['noindex'] || '404' === $c->kind;
		}
		if ( 'term' === $c->kind && $s['noindex_empty'] ) {
			$t = get_term( $c->term_id, $c->taxonomy );
			if ( $t instanceof WP_Term && 0 === (int) $t->count ) {
				$r['noindex'] = true;
			}
		}
		if ( $c->page > 1 && $s['paged_noindex'] && ! $c->has_post() ) {
			$r['noindex'] = true;
		}
		$own = self::own( $c, self::ROBOTS );
		if ( is_array( $own ) && $own ) {
			// An object with its own robots: exactly those.
			$r['noindex']  = in_array( 'noindex', $own, true );
			$r['nofollow'] = in_array( 'nofollow', $own, true );
		}
		if ( $c->has_post() && 'publish' !== get_post_status( $c->post_id ) ) {
			$r['noindex'] = true;
		}
		/**
		 * Robots of a page. Shop Filters, Hide Catalog… add noindex here
		 * (they also keep their wp_robots filter, which runs after ours).
		 *
		 * @param array       $r [ 'noindex' => bool, 'nofollow' => bool ]
		 * @param SSG_Context $c
		 */
		$r = apply_filters( 'noxpress_seo_robots', $r, $c );
		return array(
			'noindex'  => is_array( $r ) && ! empty( $r['noindex'] ),
			'nofollow' => is_array( $r ) && ! empty( $r['nofollow'] ),
		);
	}

	/** Canonical URL of a context ('' = none: search, 404, other). */
	public static function canonical( SSG_Context $c ): string {
		$own = self::own( $c, self::CANON );
		$url = is_string( $own ) && '' !== $own ? esc_url_raw( $own ) : self::base_url( $c );
		if ( '' !== $url && $c->page > 1 && ! ( is_string( $own ) && '' !== $own ) ) {
			$url = 'singular' === $c->kind || 'front' === $c->kind && $c->max_pages <= 1 ? self::post_page_url( $url, $c->page ) : self::paged_url( $url, $c->page );
		}
		/**
		 * Canonical URL of a page ('' = no tag). Shop Filters points its
		 * filtered listings at the unfiltered one here.
		 *
		 * @param string      $url
		 * @param SSG_Context $c
		 */
		return (string) apply_filters( 'noxpress_seo_canonical', $url, $c );
	}

	/** The address of the object, without paging ('' when there is none). */
	public static function base_url( SSG_Context $c ): string {
		switch ( $c->kind ) {
			case 'front':
			case 'home':
				return home_url( '/' );
			case 'blog':
			case 'shop':
			case 'singular':
				$u = get_permalink( $c->post_id );
				return is_string( $u ) ? $u : '';
			case 'term':
				$u = get_term_link( $c->term_id, $c->taxonomy );
				return is_string( $u ) ? $u : '';
			case 'pt_archive':
				$u = get_post_type_archive_link( $c->post_type );
				return is_string( $u ) ? $u : '';
			case 'author':
				return $c->author_id ? get_author_posts_url( $c->author_id ) : '';
			case 'date':
				$p = explode( '-', $c->date );
				if ( 3 === count( $p ) ) {
					return get_day_link( (int) $p[0], (int) $p[1], (int) $p[2] );
				}
				if ( 2 === count( $p ) ) {
					return get_month_link( (int) $p[0], (int) $p[1] );
				}
				return '' !== $c->date ? get_year_link( (int) $p[0] ) : '';
		}
		return '';
	}

	/** Page N of an archive URL. */
	public static function paged_url( string $base, int $page ): string {
		global $wp_rewrite;
		if ( $page < 2 ) {
			return $base;
		}
		if ( $wp_rewrite instanceof WP_Rewrite && $wp_rewrite->using_permalinks() && false === strpos( $base, '?' ) ) {
			return user_trailingslashit( trailingslashit( $base ) . $wp_rewrite->pagination_base . '/' . $page, 'paged' );
		}
		return add_query_arg( 'paged', $page, $base );
	}

	/** Page N of a post split with <!--nextpage-->. */
	public static function post_page_url( string $base, int $page ): string {
		global $wp_rewrite;
		if ( $page < 2 ) {
			return $base;
		}
		if ( $wp_rewrite instanceof WP_Rewrite && $wp_rewrite->using_permalinks() && false === strpos( $base, '?' ) ) {
			return user_trailingslashit( trailingslashit( $base ) . $page, 'single_paged' );
		}
		return add_query_arg( 'page', $page, $base );
	}

	/** [ prev, next ] URLs of an archive page ('' when none). */
	public static function prev_next( SSG_Context $c ): array {
		if ( $c->has_post() && 'blog' !== $c->kind && 'shop' !== $c->kind ) {
			return array( '', '' );
		}
		$base = self::base_url( $c );
		if ( '' === $base || $c->max_pages < 2 ) {
			return array( '', '' );
		}
		$prev = $c->page > 1 ? self::paged_url( $base, $c->page - 1 ) : '';
		$next = $c->page < $c->max_pages ? self::paged_url( $base, $c->page + 1 ) : '';
		return array( $prev, $next );
	}

	/** IDs that never go in a sitemap or llms.txt: hidden products, cart / checkout / account. */
	public static function skipped_ids( string $pt ): array {
		$out = array();
		if ( 'page' === $pt && function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $p ) {
				$id = (int) wc_get_page_id( $p );
				if ( $id > 0 ) {
					$out[] = $id;
				}
			}
		}
		if ( 'product' === $pt && taxonomy_exists( 'product_visibility' ) ) {
			$cat    = get_term_by( 'slug', 'exclude-from-catalog', 'product_visibility' );
			$search = get_term_by( 'slug', 'exclude-from-search', 'product_visibility' );
			if ( $cat && $search ) {
				$a   = get_objects_in_term( (int) $cat->term_id, 'product_visibility' );
				$b   = get_objects_in_term( (int) $search->term_id, 'product_visibility' );
				$out = array_merge( $out, array_map( 'intval', array_intersect( (array) $a, (array) $b ) ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** meta_query that leaves out objects with their own noindex. */
	public static function indexable_query(): array {
		return array(
			'relation' => 'OR',
			array(
				'key'     => self::ROBOTS,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::ROBOTS,
				'value'   => '"noindex"',
				'compare' => 'NOT LIKE',
			),
		);
	}

	/**
	 * Primary term of a post in a taxonomy: the one chosen in the editor
	 * (or imported from Rank Math), else the first term of the post.
	 */
	public static function primary_term( int $post_id, string $tax ): ?WP_Term {
		if ( ! taxonomy_exists( $tax ) ) {
			return null;
		}
		$terms = get_the_terms( $post_id, $tax );
		if ( ! is_array( $terms ) || ! $terms ) {
			return null;
		}
		$want = (int) get_post_meta( $post_id, self::PRIMARY . $tax, true );
		if ( $want ) {
			foreach ( $terms as $t ) {
				if ( (int) $t->term_id === $want ) {
					return $t;
				}
			}
		}
		// The deepest term: «Βιβλία › Ποίηση» says more than «Βιβλία».
		$best = null;
		$deep = -1;
		foreach ( $terms as $t ) {
			$d = count( get_ancestors( (int) $t->term_id, $tax, 'taxonomy' ) );
			if ( $d > $deep ) {
				$best = $t;
				$deep = $d;
			}
		}
		return $best;
	}
}

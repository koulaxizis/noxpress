<?php
/**
 * SSG_Context — what a request is, built from a WP_Query, so that the same
 * pure code serves the visitor (main query), Compare and the abilities (a
 * URL turned into query vars with the rewrite rules of WordPress, without
 * loading the page).
 *
 * kind:
 *  - front      static front page (a page: post_id)
 *  - home       front page that lists the latest posts
 *  - blog       the posts page of a static front page (post_id)
 *  - shop       the WooCommerce shop (post_id = shop page)
 *  - singular   a post, page, product… (post_id, post_type)
 *  - term       category, tag, product_cat… (term_id, taxonomy)
 *  - pt_archive post type archive (post_type)
 *  - author     author archive (author_id)
 *  - date       date archive
 *  - search     search results (search)
 *  - 404
 *  - other      anything else (feeds are never handled)
 */

defined( 'ABSPATH' ) || exit;

final class SSG_Context {

	/** @var string */
	public $kind = 'other';
	/** @var int */
	public $post_id = 0;
	/** @var string */
	public $post_type = '';
	/** @var int */
	public $term_id = 0;
	/** @var string */
	public $taxonomy = '';
	/** @var int */
	public $author_id = 0;
	/** @var string */
	public $search = '';
	/** @var int Page of the archive (or of a paginated post), from 1. */
	public $page = 1;
	/** @var int */
	public $max_pages = 1;
	/** @var string Y, Y-m or Y-m-d of a date archive. */
	public $date = '';
	/** @var bool Built from the main query of this request. */
	public $main = false;
	/** @var string The URL a context from_url() was asked for. */
	public $asked = '';

	/** @var SSG_Context|null */
	private static $current = null;

	/** The context of the main query (cached for the request). */
	public static function current(): self {
		if ( null === self::$current ) {
			global $wp_query;
			self::$current       = $wp_query instanceof WP_Query ? self::from_query( $wp_query ) : new self();
			self::$current->main = true;
		}
		return self::$current;
	}

	public static function from_query( WP_Query $q ): self {
		$c = new self();

		$c->page      = max( 1, (int) $q->get( 'paged' ), (int) $q->get( 'page' ) );
		$c->max_pages = max( 1, (int) $q->max_num_pages );

		$front_id = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		$blog_id  = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_for_posts' ) : 0;
		$shop_id  = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;

		if ( $q->is_404() ) {
			$c->kind = '404';
		} elseif ( $q->is_search() ) {
			$c->kind   = 'search';
			$c->search = (string) $q->get( 's' );
		} elseif ( $shop_id > 0 && $q->is_post_type_archive( 'product' ) ) {
			$c->kind      = 'shop';
			$c->post_id   = $shop_id;
			$c->post_type = 'page';
		} elseif ( $front_id > 0 && $q->is_page( $front_id ) ) {
			$c->kind      = 'front';
			$c->post_id   = $front_id;
			$c->post_type = 'page';
		} elseif ( $q->is_home() ) {
			if ( $blog_id > 0 ) {
				$c->kind      = 'blog';
				$c->post_id   = $blog_id;
				$c->post_type = 'page';
			} else {
				$c->kind = 'home';
			}
		} elseif ( $q->is_singular() ) {
			$obj = $q->get_queried_object();
			if ( $obj instanceof WP_Post ) {
				$c->kind      = 'singular';
				$c->post_id   = (int) $obj->ID;
				$c->post_type = (string) $obj->post_type;
			} else {
				$c->kind = '404';
			}
		} elseif ( $q->is_category() || $q->is_tag() || $q->is_tax() ) {
			$obj = $q->get_queried_object();
			if ( $obj instanceof WP_Term ) {
				$c->kind     = 'term';
				$c->term_id  = (int) $obj->term_id;
				$c->taxonomy = (string) $obj->taxonomy;
			}
		} elseif ( $q->is_post_type_archive() ) {
			$pt           = $q->get( 'post_type' );
			$c->kind      = 'pt_archive';
			$c->post_type = is_array( $pt ) ? (string) reset( $pt ) : (string) $pt;
		} elseif ( $q->is_author() ) {
			$obj          = $q->get_queried_object();
			$c->kind      = 'author';
			$c->author_id = $obj instanceof WP_User ? (int) $obj->ID : (int) $q->get( 'author' );
		} elseif ( $q->is_date() ) {
			$c->kind = 'date';
			$y       = (int) $q->get( 'year' );
			$m       = (int) $q->get( 'monthnum' );
			$d       = (int) $q->get( 'day' );
			$c->date = $y ? ( $m ? ( $d ? sprintf( '%04d-%02d-%02d', $y, $m, $d ) : sprintf( '%04d-%02d', $y, $m ) ) : sprintf( '%04d', $y ) ) : '';
		}
		return $c;
	}

	/** A post (post, page, product…) as a visitor would open it. */
	public static function from_post( int $id ): self {
		$c    = new self();
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post ) {
			$c->kind = '404';
			return $c;
		}
		$c->post_id   = (int) $post->ID;
		$c->post_type = (string) $post->post_type;
		$front_id     = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		$blog_id      = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_for_posts' ) : 0;
		$shop_id      = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
		if ( $c->post_id === $front_id ) {
			$c->kind = 'front';
		} elseif ( $c->post_id === $blog_id ) {
			$c->kind = 'blog';
		} elseif ( $c->post_id === $shop_id ) {
			$c->kind = 'shop';
		} else {
			$c->kind = 'singular';
		}
		return $c;
	}

	public static function from_term( int $term_id, string $taxonomy = '' ): self {
		$c    = new self();
		$term = '' !== $taxonomy ? get_term( $term_id, $taxonomy ) : get_term( $term_id );
		if ( ! $term instanceof WP_Term ) {
			$c->kind = '404';
			return $c;
		}
		$c->kind     = 'term';
		$c->term_id  = (int) $term->term_id;
		$c->taxonomy = (string) $term->taxonomy;
		return $c;
	}

	/**
	 * A URL of this site, parsed with the rewrite rules of WordPress and
	 * queried like a visitor would (no page is rendered). Null for a URL
	 * of another host.
	 */
	public static function from_url( string $url ): ?self {
		$url  = trim( $url );
		$home = wp_parse_url( home_url( '/' ) );
		if ( '' !== $url && '/' === $url[0] ) {
			$url = home_url( $url );
		}
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) || ! is_array( $home ) || strtolower( (string) $p['host'] ) !== strtolower( (string) $home['host'] ) ) {
			return null;
		}
		$path  = isset( $p['path'] ) ? (string) $p['path'] : '/';
		$query = isset( $p['query'] ) ? (string) $p['query'] : '';

		// WP::parse_request() reads the request from the server globals.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification
		$keep_uri  = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
		$keep_path = isset( $_SERVER['PATH_INFO'] ) ? $_SERVER['PATH_INFO'] : null;
		$keep_get  = $_GET;
		$keep_wp   = isset( $GLOBALS['wp'] ) ? $GLOBALS['wp'] : null;

		$_SERVER['REQUEST_URI'] = $path . ( '' !== $query ? '?' . $query : '' );
		unset( $_SERVER['PATH_INFO'] );
		$args = array();
		if ( '' !== $query ) {
			wp_parse_str( $query, $args );
		}
		$_GET = $args;

		$c = new self();
		try {
			$wp = new WP();
			// Post types and taxonomies add their query vars to the global
			// WP object only (register_post_type → $wp->add_query_var()).
			if ( $keep_wp instanceof WP ) {
				$wp->public_query_vars  = $keep_wp->public_query_vars;
				$wp->private_query_vars = $keep_wp->private_query_vars;
			}
			$wp->parse_request();
			$q = new WP_Query();
			$q->query( $wp->query_vars );
			if ( ( $q->is_singular() && ! $q->post ) || ( ! empty( $wp->query_vars['error'] ) && '404' === (string) $wp->query_vars['error'] ) ) {
				$q->set_404();
			} elseif ( ( $q->is_category() || $q->is_tag() || $q->is_tax() ) && ! $q->get_queried_object() ) {
				$q->set_404();
			}
			$c = self::from_query( $q );
		} catch ( \Throwable $e ) {
			$c->kind = 'other';
		} finally {
			if ( null === $keep_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $keep_uri;
			}
			if ( null !== $keep_path ) {
				$_SERVER['PATH_INFO'] = $keep_path;
			}
			$_GET = $keep_get;
			if ( null !== $keep_wp ) {
				$GLOBALS['wp'] = $keep_wp;
			}
		}
		// phpcs:enable
		$c->asked = $url;
		return $c;
	}

	/** Settings key of the templates that apply (SSG_Settings::tpl()). */
	public function tpl_key(): string {
		switch ( $this->kind ) {
			case 'front':
			case 'blog':
			case 'shop':
				return 'pt_page';
			case 'singular':
				return 'pt_' . $this->post_type;
			case 'term':
				return 'tax_' . $this->taxonomy;
			case 'pt_archive':
				return 'arch_' . $this->post_type;
			case 'home':
			case 'author':
			case 'date':
			case 'search':
			case '404':
				return $this->kind;
		}
		return '';
	}

	/** A post object is behind the page (front, blog, shop, singular). */
	public function has_post(): bool {
		return $this->post_id > 0 && in_array( $this->kind, array( 'front', 'blog', 'shop', 'singular' ), true );
	}

	/** For tests: forget the main context. */
	public static function reset(): void {
		self::$current = null;
	}
}

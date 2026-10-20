<?php
/**
 * HIC_Front — applies the rules where visitors see the catalog.
 *
 * Products (HIC_Rules::clauses, per context):
 *  - shop / listings / product search: woocommerce_product_query_tax_query.
 *    WooCommerce runs it for the main query of the shop and the product
 *    taxonomy pages, and for every catalog count on the same page (price
 *    filter and layered nav widgets, Shop Filters' SHF_Index), so results
 *    and counts agree. On the page of a hidden category its own rule does
 *    not apply.
 *  - search: the WordPress search (?s= without post_type=product) through
 *    pre_get_posts; product search goes through WooCommerce's query above.
 *  - related, upsells, cross-sells: woocommerce_related_products,
 *    woocommerce_product_get_upsell_ids, woocommerce_cart_crosssell_ids.
 *  - shortcodes and blocks: woocommerce_shortcode_products_query (rules
 *    of a category the shortcode asks for by name do not apply),
 *    query_loop_block_query_vars (Product Collection), parse_query for the
 *    older product grid blocks (BlocksWpQuery: the clause is in the query
 *    vars, so their cache key changes with it) and the Store API product
 *    list (All Products block).
 *
 * The category itself (rules with "terms"):
 *  - get_terms_args: excluded from category lists (widgets, blocks, the
 *    shop's category display, Shop Filters' tree, core sitemaps), never
 *    when a query names terms (include, slug, name, term_taxonomy_id:
 *    get_term_by and the main query's term lookup) or reads a product's
 *    terms (object_ids: the object term cache stays complete);
 *  - get_the_terms: not in a product's categories (product meta, cards);
 *  - woocommerce_get_breadcrumb: not in a product's breadcrumb.
 *  On a hidden category's own page, that category and its subcategories
 *  stay in the lists.
 *
 * Category pages (rules with "archive"): noindex (wp_robots, Yoast,
 * Rank Math), 404 or a 302 redirect to the shop, in template_redirect.
 * Closed pages leave the core and Rank Math sitemaps.
 *
 * Bible §14: nothing until a rule is set and the mode is test or live;
 * never in admin, AJAX, cron, CLI, XML-RPC, feeds or embeds; REST only for
 * the Store API (read-only product lists and cart cross-sells, deviation
 * in the bootstrap docblock); every callback in try/catch; no write on a
 * visitor request.
 */

defined( 'ABSPATH' ) || exit;

final class HIC_Front {

	/** @var bool A Store API request (set around its callbacks). */
	private static $store_api = false;

	/** @var bool A Store API product list request. */
	private static $store_products = false;

	/** @var array|null Hidden-term ids for the lists of this request. */
	private static $hidden = null;

	public static function init(): void {
		add_filter( 'woocommerce_product_query_tax_query', array( __CLASS__, 'wc_tax_query' ), 20 );
		add_action( 'pre_get_posts', array( __CLASS__, 'pre_get_posts' ), 20 );
		add_action( 'parse_query', array( __CLASS__, 'parse_query' ), 20 );
		add_filter( 'woocommerce_related_products', array( __CLASS__, 'related' ), 20 );
		add_filter( 'woocommerce_product_get_upsell_ids', array( __CLASS__, 'related' ), 20 );
		add_filter( 'woocommerce_cart_crosssell_ids', array( __CLASS__, 'related' ), 20 );
		add_filter( 'woocommerce_shortcode_products_query', array( __CLASS__, 'shortcode' ), 20, 2 );
		add_filter( 'query_loop_block_query_vars', array( __CLASS__, 'block_query' ), 20 );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'rest_before' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'rest_after' ), 10 );

		add_filter( 'get_terms_args', array( __CLASS__, 'terms_args' ), 20, 2 );
		add_filter( 'get_the_terms', array( __CLASS__, 'the_terms' ), 20, 3 );
		add_filter( 'woocommerce_get_breadcrumb', array( __CLASS__, 'breadcrumb' ), 20 );

		add_action( 'template_redirect', array( __CLASS__, 'template_redirect' ), 5 );
		add_filter( 'wp_robots', array( __CLASS__, 'wp_robots' ), 20 );
		add_filter( 'wpseo_robots', array( __CLASS__, 'yoast_robots' ), 20 );
		add_filter( 'rank_math/frontend/robots', array( __CLASS__, 'rank_math_robots' ), 20 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( __CLASS__, 'core_sitemap' ), 20, 2 );
		add_filter( 'rank_math/sitemap/entry', array( __CLASS__, 'rank_math_sitemap' ), 20, 3 );
	}

	/* =====================================================================
	 * Request type
	 * =================================================================== */

	/** A visitor page request (not admin, AJAX, REST, cron, CLI, XML-RPC, feed or embed). */
	private static function page_request(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}
		if ( did_action( 'parse_query' ) && ( is_feed() || is_embed() ) ) {
			return false;
		}
		return true;
	}

	/** A page request, or a Store API request where the Store API is allowed. */
	private static function allowed( bool $store_api_too ): bool {
		if ( $store_api_too && self::$store_api ) {
			return true;
		}
		return self::page_request();
	}

	/**
	 * The catalog context of the current page and the categories whose
	 * rules do not apply there (the queried category, matched against each
	 * rule's set).
	 *
	 * @return array{0:string,1:int[]} context ('' = none), exempt ids
	 */
	private static function page_context(): array {
		if ( ! function_exists( 'is_shop' ) ) {
			return array( '', array() );
		}
		if ( is_search() ) {
			return array( 'search', array() );
		}
		if ( is_product_taxonomy() ) {
			$exempt = array();
			if ( is_product_category() ) {
				HIC_Rules::enter();
				try {
					$obj = get_queried_object();
				} finally {
					HIC_Rules::leave();
				}
				if ( $obj instanceof WP_Term ) {
					$exempt[] = (int) $obj->term_id;
				}
			}
			return array( 'listings', $exempt );
		}
		if ( is_shop() ) {
			return array( 'shop', array() );
		}
		return array( '', array() );
	}

	/* =====================================================================
	 * Products
	 * =================================================================== */

	/** woocommerce_product_query_tax_query: shop, listings, product search. */
	public static function wc_tax_query( $tax_query ) {
		try {
			if ( HIC_Rules::internal() || ! self::page_request() || ! HIC_Rules::active() ) {
				return $tax_query;
			}
			list( $context, $exempt ) = self::page_context();
			if ( '' === $context ) {
				return $tax_query;
			}
			return HIC_Rules::append( $tax_query, $context, $exempt );
		} catch ( \Throwable $e ) {
			HIC_Rules::fail();
			return $tax_query;
		}
	}

	/**
	 * pre_get_posts: the WordPress search (main query, not a product
	 * archive) and the Store API product list.
	 *
	 * @param WP_Query $q Query.
	 */
	public static function pre_get_posts( $q ): void {
		try {
			if ( ! $q instanceof WP_Query || $q->get( 'hic_internal' ) || HIC_Rules::internal() ) {
				return;
			}
			if ( self::$store_products && ! $q->is_main_query() && self::is_product_query( $q ) ) {
				if ( HIC_Rules::active() ) {
					$q->set( 'tax_query', HIC_Rules::append( $q->get( 'tax_query' ), 'blocks' ) );
				}
				return;
			}
			if ( ! $q->is_main_query() || ! $q->is_search() || $q->is_post_type_archive( 'product' ) || ! self::page_request() ) {
				return;
			}
			$type = $q->get( 'post_type' );
			if ( '' !== $type && 'any' !== $type && ! in_array( 'product', (array) $type, true ) ) {
				return;
			}
			if ( HIC_Rules::active() ) {
				$q->set( 'tax_query', HIC_Rules::append( $q->get( 'tax_query' ), 'search' ) );
			}
		} catch ( \Throwable $e ) {
			HIC_Rules::fail();
		}
	}

	private static function is_product_query( WP_Query $q ): bool {
		$type = $q->get( 'post_type' );
		return 'product' === $type || ( is_array( $type ) && in_array( 'product', $type, true ) );
	}

	/**
	 * parse_query: WooCommerce's older product grid blocks (Newest, On
	 * sale, Top rated, By category …). Their query is cached under a hash
	 * of the query vars, which now include our clause.
	 *
	 * @param WP_Query $q Query.
	 */
	public static function parse_query( $q ): void {
		try {
			if ( ! $q instanceof \Automattic\WooCommerce\Blocks\Utils\BlocksWpQuery || HIC_Rules::internal() || ! self::allowed( true ) || ! HIC_Rules::active() ) {
				return;
			}
			$tax = isset( $q->query_vars['tax_query'] ) ? $q->query_vars['tax_query'] : array();
			if ( HIC_Rules::has_ours( $tax ) ) {
				return;
			}
			$new = HIC_Rules::append( $tax, 'blocks' );
			if ( $new !== $tax ) {
				$q->query_vars['tax_query'] = $new; // phpcs:ignore WordPress.DB.SlowDBQuery -- the point of the plugin.
				$q->parse_tax_query( $q->query_vars );
			}
		} catch ( \Throwable $e ) {
			HIC_Rules::fail();
		}
	}

	/** Related products, upsells, cross-sells: hidden ones are left out. */
	public static function related( $ids ) {
		try {
			if ( ! is_array( $ids ) || ! $ids || HIC_Rules::internal() || ! self::allowed( true ) || ! HIC_Rules::active() ) {
				return $ids;
			}
			return HIC_Rules::visible_ids( $ids, 'related' );
		} catch ( \Throwable $e ) {
			HIC_Rules::fail();
			return $ids;
		}
	}

	/**
	 * [products] and the other product shortcodes. Rules of a category the
	 * shortcode asks for (category="…") do not apply to it.
	 */
	public static function shortcode( $args, $atts = array() ) {
		try {
			if ( ! is_array( $args ) || ! self::page_request() || ! HIC_Rules::active() ) {
				return $args;
			}
			$exempt = array();
			if ( is_array( $atts ) && ! empty( $atts['category'] ) && is_string( $atts['category'] ) ) {
				foreach ( array_map( 'trim', explode( ',', $atts['category'] ) ) as $c ) {
					$t = is_numeric( $c ) ? get_term( (int) $c, 'product_cat' ) : get_term_by( 'slug', sanitize_title( $c ), 'product_cat' );
					if ( $t instanceof WP_Term ) {
						$exempt[] = (int) $t->term_id;
					}
				}
			}
			$args['tax_query'] = HIC_Rules::append( isset( $args['tax_query'] ) ? $args['tax_query'] : array(), 'blocks', $exempt ); // phpcs:ignore WordPress.DB.SlowDBQuery -- the point of the plugin.
			return $args;
		} catch ( \Throwable $e ) {
			HIC_Rules::fail();
			return $args;
		}
	}

	/** Product Collection and other Query Loop blocks of products. */
	public static function block_query( $query ) {
		try {
			if ( ! is_array( $query ) || ! self::page_request() || ! HIC_Rules::active() ) {
				return $query;
			}
			$type = isset( $query['post_type'] ) ? $query['post_type'] : '';
			if ( 'product' !== $type && ! ( is_array( $type ) && in_array( 'product', $type, true ) ) ) {
				return $query;
			}
			$query['tax_query'] = HIC_Rules::append( isset( $query['tax_query'] ) ? $query['tax_query'] : array(), 'blocks' ); // phpcs:ignore WordPress.DB.SlowDBQuery -- the point of the plugin.
			return $query;
		} catch ( \Throwable $e ) {
			HIC_Rules::fail();
			return $query;
		}
	}

	/**
	 * Store API requests: product lists (All Products block) and the cart's
	 * cross-sells. Read-only, nothing else in REST.
	 *
	 * @param mixed           $response Response so far.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 */
	public static function rest_before( $response, $handler, $request ) {
		try {
			if ( $request instanceof WP_REST_Request ) {
				$route                = (string) $request->get_route();
				self::$store_api      = (bool) preg_match( '#^/wc/store(/v\d+)?/#', $route );
				self::$store_products = self::$store_api && 'GET' === $request->get_method() && (bool) preg_match( '#^/wc/store(/v\d+)?/products/?$#', $route );
			}
		} catch ( \Throwable $e ) {
			HIC_Rules::fail();
		}
		return $response;
	}

	public static function rest_after( $response ) {
		self::$store_api      = false;
		self::$store_products = false;
		return $response;
	}

	/* =====================================================================
	 * The category itself
	 * =================================================================== */

	/** Hidden category ids for lists on this request (minus the current category's family). */
	private static function hidden_in_lists(): array {
		if ( null !== self::$hidden && did_action( 'wp' ) ) {
			return self::$hidden;
		}
		$ids = HIC_Rules::hidden_terms( 'terms' );
		if ( $ids && did_action( 'parse_query' ) && function_exists( 'is_product_category' ) && is_product_category() ) {
			// get_queried_object() looks the term up through get_terms: keep
			// our filter out of that lookup.
			HIC_Rules::enter();
			try {
				$obj = get_queried_object();
				if ( $obj instanceof WP_Term && in_array( (int) $obj->term_id, $ids, true ) ) {
					$keep = get_term_children( (int) $obj->term_id, 'product_cat' );
					$keep = is_array( $keep ) ? array_map( 'intval', $keep ) : array();
					$ids  = array_values( array_diff( $ids, array_merge( array( (int) $obj->term_id ), $keep ) ) );
				}
			} finally {
				HIC_Rules::leave();
			}
		}
		// Cache only once the main query is known (the exemption depends on it).
		if ( did_action( 'wp' ) ) {
			self::$hidden = $ids;
		}
		return $ids;
	}

	/** get_terms_args: hidden categories leave category lists. */
	public static function terms_args( $args, $taxonomies ) {
		try {
			if ( ! is_array( $args ) || HIC_Rules::internal() || ! in_array( 'product_cat', (array) $taxonomies, true ) ) {
				return $args;
			}
			// A lookup of named terms (get_term_by, the main query's term,
			// a product's terms) is not a list: never change it.
			foreach ( array( 'include', 'object_ids', 'slug', 'name', 'term_taxonomy_id' ) as $k ) {
				if ( ! empty( $args[ $k ] ) ) {
					return $args;
				}
			}
			// Nor the full read behind core's stored hierarchy
			// (_get_term_hierarchy, option product_cat_children): without
			// the hidden categories, their subcategories would vanish from
			// get_term_children() everywhere until the next category save.
			if ( ( isset( $args['get'] ) && 'all' === $args['get'] ) || ( isset( $args['fields'] ) && 'id=>parent' === $args['fields'] ) ) {
				return $args;
			}
			if ( ! self::page_request() || ! HIC_Rules::active() ) {
				return $args;
			}
			$hide = self::hidden_in_lists();
			if ( ! $hide ) {
				return $args;
			}
			$args['exclude'] = array_values( array_unique( array_merge( wp_parse_id_list( isset( $args['exclude'] ) ? $args['exclude'] : array() ), $hide ) ) );
			return $args;
		} catch ( \Throwable $e ) {
			HIC_Rules::fail();
			return $args;
		}
	}

	/** get_the_terms: hidden categories leave a product's categories. */
	public static function the_terms( $terms, $post_id, $taxonomy ) {
		try {
			if ( 'product_cat' !== $taxonomy || ! is_array( $terms ) || ! $terms || HIC_Rules::internal() || ! self::page_request() || ! HIC_Rules::active() ) {
				return $terms;
			}
			$hide = self::hidden_in_lists();
			if ( ! $hide ) {
				return $terms;
			}
			$hide = array_fill_keys( $hide, true );
			$out  = array();
			foreach ( $terms as $t ) {
				if ( ! ( $t instanceof WP_Term && isset( $hide[ (int) $t->term_id ] ) ) ) {
					$out[] = $t;
				}
			}
			return $out ? $out : false;
		} catch ( \Throwable $e ) {
			HIC_Rules::fail();
			return $terms;
		}
	}

	/** woocommerce_get_breadcrumb: hidden categories leave a product's breadcrumb. */
	public static function breadcrumb( $crumbs ) {
		try {
			if ( ! is_array( $crumbs ) || ! function_exists( 'is_product' ) || ! is_product() || ! self::page_request() || ! HIC_Rules::active() ) {
				return $crumbs;
			}
			$hide = self::hidden_in_lists();
			if ( ! $hide ) {
				return $crumbs;
			}
			$links = array();
			foreach ( $hide as $id ) {
				$l = get_term_link( (int) $id, 'product_cat' );
				if ( is_string( $l ) ) {
					$links[ untrailingslashit( $l ) ] = true;
				}
			}
			$out = array();
			foreach ( $crumbs as $c ) {
				if ( is_array( $c ) && isset( $c[1] ) && is_string( $c[1] ) && isset( $links[ untrailingslashit( $c[1] ) ] ) ) {
					continue;
				}
				$out[] = $c;
			}
			return $out;
		} catch ( \Throwable $e ) {
			HIC_Rules::fail();
			return $crumbs;
		}
	}

	/* =====================================================================
	 * Category pages
	 * =================================================================== */

	/** 'open' | 'noindex' | '404' | 'redirect' for the current page. */
	private static function current_archive(): string {
		if ( ! function_exists( 'is_product_category' ) || ! is_product_category() ) {
			return 'open';
		}
		$obj = get_queried_object();
		return $obj instanceof WP_Term ? HIC_Rules::archive_for( (int) $obj->term_id ) : 'open';
	}

	public static function template_redirect(): void {
		try {
			if ( ! self::page_request() || ! HIC_Rules::active() ) {
				return;
			}
			$mode = self::current_archive();
			if ( '404' === $mode ) {
				global $wp_query;
				$wp_query->set_404();
				status_header( 404 );
				nocache_headers();
			} elseif ( 'redirect' === $mode ) {
				$shop = wc_get_page_permalink( 'shop' );
				if ( $shop ) {
					wp_safe_redirect( $shop, 302, 'Hide Catalog' );
					exit;
				}
			}
		} catch ( \Throwable $e ) {
			HIC_Rules::fail();
		}
	}

	private static function noindex(): bool {
		try {
			return self::page_request() && HIC_Rules::active() && 'noindex' === self::current_archive();
		} catch ( \Throwable $e ) {
			HIC_Rules::fail();
			return false;
		}
	}

	public static function wp_robots( $robots ) {
		if ( ! is_array( $robots ) || ! self::noindex() ) {
			return $robots;
		}
		unset( $robots['index'], $robots['nofollow'] );
		$robots['noindex'] = true;
		$robots['follow']  = true;
		return $robots;
	}

	public static function yoast_robots( $robots ) {
		return self::noindex() ? 'noindex, follow' : $robots;
	}

	public static function rank_math_robots( $robots ) {
		if ( ! self::noindex() ) {
			return $robots;
		}
		$robots           = is_array( $robots ) ? $robots : array();
		$robots['index']  = 'noindex';
		$robots['follow'] = 'follow';
		return $robots;
	}

	/** Core sitemaps: closed category pages are left out. */
	public static function core_sitemap( $args, $taxonomy ) {
		try {
			if ( 'product_cat' !== $taxonomy || ! is_array( $args ) || ! HIC_Rules::active() ) {
				return $args;
			}
			$closed = HIC_Rules::closed_archives();
			if ( $closed ) {
				$args['exclude'] = array_values( array_unique( array_merge( wp_parse_id_list( isset( $args['exclude'] ) ? $args['exclude'] : array() ), $closed ) ) );
			}
			return $args;
		} catch ( \Throwable $e ) {
			HIC_Rules::fail();
			return $args;
		}
	}

	/** Rank Math sitemaps: closed category pages are left out. */
	public static function rank_math_sitemap( $url, $type = '', $object = null ) {
		try {
			if ( 'term' !== $type || ! $object instanceof WP_Term || 'product_cat' !== $object->taxonomy || ! HIC_Rules::active() ) {
				return $url;
			}
			return in_array( (int) $object->term_id, HIC_Rules::closed_archives(), true ) ? array() : $url;
		} catch ( \Throwable $e ) {
			HIC_Rules::fail();
			return $url;
		}
	}
}

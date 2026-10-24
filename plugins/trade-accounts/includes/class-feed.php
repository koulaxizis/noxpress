<?php
/**
 * TRA_Feed — module «Trade Feed»: the catalogue a trade account pulls into
 * its own e-shop.
 *
 * REST (namespace tra/v1), JSON by default, XML with output=xml:
 *  - GET /account             the account: discount, rate limit, currency
 *  - GET /products            eligible products, with filters (see args())
 *  - GET /products/<isbn>     one product by ISBN / ISSN / GTIN / SKU
 *  - GET /categories          the eligible categories (id, name, slug, parent)
 *
 * Authentication: HTTP Basic with the account's username (or e-mail) and
 * its Application Password (TRA_Accounts::new_password()), over HTTPS.
 * WordPress authenticates it; this module only lets in trade accounts with
 * the API switched on, and managers (to test). TRA_DISABLE: 403.
 *
 * Only eligible products (TRA_Scope): published, visible in the catalogue,
 * simple, in the chosen categories and brands, physical, no Book Feed
 * title, not excluded by hand. Prices: the regular retail price, the sale
 * price when there is one, and the trade price of the calling account (the
 * same function as the shop). Every answer: no cache (private, no-store).
 *
 * Rate limit: tra_settings rate calls per hour and account (transient
 * tra_rl_{uid}); above it 429 with Retry-After. Each call writes the
 * account's last call (user meta tra_api_seen) and one line of the call log
 * (option tra_log, last 200, no IP, no credentials).
 */

defined( 'ABSPATH' ) || exit;

final class TRA_Feed {

	const NS      = 'tra/v1';
	const LOG     = 'tra_log';
	const LOG_MAX = 200;
	const PER     = 100;

	/** @var int Seconds to wait after a 429, for the Retry-After header. */
	private static $retry = 0;

	/** @var array|null Rate state of this request: limit, remaining, reset. */
	private static $rate = null;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'after' ), 10, 3 );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_xml' ), 10, 4 );
	}

	/* ---------------------------------------------------------------------
	 * Routes
	 * ------------------------------------------------------------------- */

	public static function routes(): void {
		$output = array(
			'type'    => 'string',
			'enum'    => array( 'json', 'xml' ),
			'default' => 'json',
		);
		register_rest_route(
			self::NS,
			'/account',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'account' ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
				'args'                => array( 'output' => $output ),
			)
		);
		register_rest_route(
			self::NS,
			'/products',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'index' ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
				'args'                => self::args() + array( 'output' => $output ),
			)
		);
		register_rest_route(
			self::NS,
			'/products/(?P<code>[0-9Xx\-]{4,20})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'one' ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
				'args'                => array(
					'fields' => array(
						'type'    => 'string',
						'default' => '',
					),
					'output' => $output,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/categories',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'categories' ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
				'args'                => array( 'output' => $output ),
			)
		);
	}

	/** Filters of /products. */
	public static function args(): array {
		return array(
			'page'           => array(
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			),
			'per_page'       => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => self::PER,
				'default' => 50,
			),
			'category'       => array(
				'type'        => 'string',
				'default'     => '',
				'description' => 'Category ids or slugs, comma-separated (subcategories included).',
			),
			'isbn'           => array(
				'type'        => 'string',
				'default'     => '',
				'description' => 'ISBN / ISSN / GTIN / SKU, comma-separated (up to 100), hyphens ignored.',
			),
			'author'         => array(
				'type'        => 'string',
				'default'     => '',
				'description' => 'A contributor\'s name or slug.',
			),
			'attribute'      => array(
				'type'        => 'string',
				'default'     => '',
				'description' => 'An attribute taxonomy (pa_…), with attribute_term.',
			),
			'attribute_term' => array(
				'type'    => 'string',
				'default' => '',
			),
			'availability'   => array(
				'type'    => 'string',
				'enum'    => array( '', 'instock', 'outofstock', 'onbackorder', 'preorder' ),
				'default' => '',
			),
			'modified_since' => array(
				'type'        => 'string',
				'default'     => '',
				'description' => 'ISO 8601 date or date-time (UTC unless an offset is given): only products changed since.',
			),
			'search'         => array(
				'type'    => 'string',
				'default' => '',
			),
			'orderby'        => array(
				'type'    => 'string',
				'enum'    => array( 'modified', 'date', 'title', 'id' ),
				'default' => 'modified',
			),
			'order'          => array(
				'type'    => 'string',
				'enum'    => array( 'asc', 'desc' ),
				'default' => 'desc',
			),
			'fields'         => array(
				'type'        => 'string',
				'default'     => '',
				'description' => 'Top-level fields to return, comma-separated (default all).',
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Access
	 * ------------------------------------------------------------------- */

	/** Trade accounts with the API on, and managers; then the rate limit. */
	public static function allowed() {
		if ( Trade_Accounts::killed() ) {
			return new WP_Error( 'tra_off', 'The Trade Feed is switched off on this site.', array( 'status' => 403 ) );
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'tra_auth', 'Authentication required: HTTP Basic with your username and the Application Password the shop gave you, over HTTPS.', array( 'status' => 401 ) );
		}
		$uid     = get_current_user_id();
		$manager = current_user_can( Trade_Accounts::cap() );
		if ( ! $manager && ! TRA_Accounts::is_trade( $uid ) ) {
			return new WP_Error( 'tra_not_trade', 'This user is not a trade account.', array( 'status' => 403 ) );
		}
		if ( ! $manager && ! TRA_Accounts::api_on( $uid ) ) {
			return new WP_Error( 'tra_api_off', 'The Trade Feed is not enabled for this account. Ask the shop.', array( 'status' => 403 ) );
		}
		return self::rate( $uid );
	}

	/** Counts the call in the account's hourly window. */
	private static function rate( int $uid ) {
		$limit = (int) TRA_Settings::get()['rate'];
		$key   = 'tra_rl_' . $uid;
		$now   = time();
		$w     = get_transient( $key );
		if ( ! is_array( $w ) || ! isset( $w['s'], $w['n'] ) || $now - (int) $w['s'] >= HOUR_IN_SECONDS ) {
			$w = array(
				's' => $now,
				'n' => 0,
			);
		}
		$reset = (int) $w['s'] + HOUR_IN_SECONDS;
		if ( (int) $w['n'] >= $limit ) {
			self::$retry = max( 1, $reset - $now );
			self::$rate  = array( $limit, 0, $reset );
			return new WP_Error( 'tra_rate', sprintf( 'Rate limit reached (%d calls per hour). Try again in %d seconds.', $limit, self::$retry ), array( 'status' => 429 ) );
		}
		++$w['n'];
		set_transient( $key, $w, HOUR_IN_SECONDS );
		self::$rate = array( $limit, max( 0, $limit - (int) $w['n'] ), $reset );
		return true;
	}

	/** The discount of the caller: the account's, or the plugin's for a manager. */
	private static function discount(): float {
		$uid = get_current_user_id();
		return TRA_Accounts::is_trade( $uid ) ? TRA_Accounts::discount( $uid ) : (float) TRA_Settings::get()['discount'];
	}

	/* ---------------------------------------------------------------------
	 * Callbacks
	 * ------------------------------------------------------------------- */

	public static function account( WP_REST_Request $req ): WP_REST_Response {
		$user = wp_get_current_user();
		return new WP_REST_Response(
			array(
				'name'               => (string) $user->display_name,
				'trade_account'      => TRA_Accounts::is_trade( (int) $user->ID ),
				'discount_percent'   => self::discount(),
				'currency'           => get_woocommerce_currency(),
				'prices_include_tax' => 'yes' === get_option( 'woocommerce_prices_include_tax' ),
				'rate_limit'         => array(
					'per_hour'  => self::$rate ? self::$rate[0] : (int) TRA_Settings::get()['rate'],
					'remaining' => self::$rate ? self::$rate[1] : null,
					'reset'     => self::$rate ? gmdate( 'c', self::$rate[2] ) : null,
				),
				'products'           => rest_url( self::NS . '/products' ),
				'categories'         => rest_url( self::NS . '/categories' ),
			),
			200
		);
	}

	public static function index( WP_REST_Request $req ) {
		$args = TRA_Scope::query_args();
		$tax  = $args['tax_query'];
		$meta = $args['meta_query'];

		$cats = self::cats_param( (string) $req->get_param( 'category' ) );
		if ( null === $cats ) {
			return new WP_Error( 'tra_bad_category', 'Unknown or not eligible category.', array( 'status' => 400 ) );
		}
		if ( $cats ) {
			$tax[] = array(
				'taxonomy'         => 'product_cat',
				'field'            => 'term_id',
				'terms'            => $cats,
				'include_children' => true,
			);
		}

		$codes = trim( (string) $req->get_param( 'isbn' ) );
		if ( '' !== $codes ) {
			$ids = self::ids_by_code( preg_split( '/\s*,\s*/', $codes ) );
			$args['post__in'] = $ids ? $ids : array( 0 );
		}

		$author = trim( (string) $req->get_param( 'author' ) );
		if ( '' !== $author ) {
			$t = self::author_terms( $author );
			if ( null === $t ) {
				return new WP_Error( 'tra_no_author_filter', 'The author filter is not set up on this site.', array( 'status' => 400 ) );
			}
			$or = array( 'relation' => 'OR' );
			foreach ( $t as $taxonomy => $term_ids ) {
				$or[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_ids,
				);
			}
			$tax[] = count( $or ) > 1 ? $or : array(
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => array( 0 ),
			);
		}

		$attr = sanitize_key( (string) $req->get_param( 'attribute' ) );
		$term = sanitize_title( (string) $req->get_param( 'attribute_term' ) );
		if ( '' !== $attr ) {
			if ( 0 !== strpos( $attr, 'pa_' ) || ! taxonomy_exists( $attr ) || '' === $term ) {
				return new WP_Error( 'tra_bad_attribute', 'attribute must be an attribute taxonomy (pa_…) and needs attribute_term.', array( 'status' => 400 ) );
			}
			$tax[] = array(
				'taxonomy' => $attr,
				'field'    => 'slug',
				'terms'    => array( $term ),
			);
		}

		$av = (string) $req->get_param( 'availability' );
		if ( 'preorder' === $av ) {
			$meta[] = array(
				'relation' => 'OR',
				array(
					'key'   => '_simple_preorder',
					'value' => 'yes',
				),
				array(
					'key'   => '_por_on',
					'value' => 'yes',
				),
			);
		} elseif ( '' !== $av ) {
			$meta[] = array(
				'key'   => '_stock_status',
				'value' => $av,
			);
		}

		$since = trim( (string) $req->get_param( 'modified_since' ) );
		if ( '' !== $since ) {
			$ts = strtotime( $since );
			if ( false === $ts ) {
				return new WP_Error( 'tra_bad_date', 'modified_since must be an ISO 8601 date or date-time.', array( 'status' => 400 ) );
			}
			$args['date_query'] = array(
				array(
					'column'    => 'post_modified_gmt',
					'after'     => gmdate( 'Y-m-d H:i:s', $ts ),
					'inclusive' => true,
				),
			);
		}

		$search = trim( sanitize_text_field( (string) $req->get_param( 's' ) ?: (string) $req->get_param( 'search' ) ) );
		if ( '' !== $search ) {
			$args['s'] = mb_substr( $search, 0, 100 );
		}

		$orderby = array(
			'modified' => 'modified',
			'date'     => 'date',
			'title'    => 'title',
			'id'       => 'ID',
		);
		$ob                     = (string) $req->get_param( 'orderby' );
		$args['orderby']        = array(
			isset( $orderby[ $ob ] ) ? $orderby[ $ob ] : 'modified' => 'asc' === $req->get_param( 'order' ) ? 'ASC' : 'DESC',
			'ID'                                                   => 'ASC',
		);
		$args['tax_query']      = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery
		$args['meta_query']     = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery
		$args['posts_per_page'] = (int) $req->get_param( 'per_page' );
		$args['paged']          = (int) $req->get_param( 'page' );
		$args['fields']         = 'ids';

		$q        = new WP_Query( $args );
		$fields   = self::fields_param( (string) $req->get_param( 'fields' ) );
		$discount = self::discount();
		$items    = array();
		foreach ( $q->posts as $id ) {
			$p = wc_get_product( (int) $id );
			if ( $p && TRA_Scope::eligible( $p ) ) {
				$items[] = self::pick( self::item( $p, $discount ), $fields );
			}
		}

		$res = new WP_REST_Response(
			array(
				'page'        => (int) $args['paged'],
				'per_page'    => (int) $args['posts_per_page'],
				'total'       => (int) $q->found_posts,
				'total_pages' => (int) $q->max_num_pages,
				'items'       => $items,
			),
			200
		);
		$res->header( 'X-WP-Total', (string) (int) $q->found_posts );
		$res->header( 'X-WP-TotalPages', (string) (int) $q->max_num_pages );
		return $res;
	}

	public static function one( WP_REST_Request $req ) {
		$ids = self::ids_by_code( array( (string) $req['code'] ) );
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( $p && 'publish' === $p->get_status() && 'simple' === $p->get_type() && $p->is_visible() && TRA_Scope::eligible( $p ) ) {
				return new WP_REST_Response( self::pick( self::item( $p, self::discount() ), self::fields_param( (string) $req->get_param( 'fields' ) ) ), 200 );
			}
		}
		return new WP_Error( 'tra_not_found', 'No eligible product with this code.', array( 'status' => 404 ) );
	}

	public static function categories( WP_REST_Request $req ): WP_REST_Response {
		$ids = TRA_Scope::cat_ids();
		$out = array();
		if ( $ids ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'include'    => $ids,
					'hide_empty' => false,
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $t ) {
				$out[] = array(
					'id'     => (int) $t->term_id,
					'name'   => html_entity_decode( (string) $t->name, ENT_QUOTES, 'UTF-8' ),
					'slug'   => (string) $t->slug,
					'parent' => in_array( (int) $t->parent, $ids, true ) ? (int) $t->parent : 0,
				);
			}
		}
		return new WP_REST_Response( array( 'items' => $out ), 200 );
	}

	/* ---------------------------------------------------------------------
	 * Parameters
	 * ------------------------------------------------------------------- */

	/** Category ids from ids / slugs; null when one is unknown or not eligible. */
	private static function cats_param( string $raw ): ?array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array();
		}
		$allowed = TRA_Scope::cat_ids();
		$out     = array();
		foreach ( array_slice( preg_split( '/\s*,\s*/', $raw ), 0, 20 ) as $c ) {
			$t = is_numeric( $c ) ? get_term( (int) $c, 'product_cat' ) : get_term_by( 'slug', sanitize_title( $c ), 'product_cat' );
			if ( ! $t instanceof WP_Term || ! in_array( (int) $t->term_id, $allowed, true ) ) {
				return null;
			}
			$out[] = (int) $t->term_id;
		}
		return $out;
	}

	/** Normalised code: digits and X only, upper case. */
	public static function norm( string $code ): string {
		return strtoupper( (string) preg_replace( '/[^0-9Xx]/', '', $code ) );
	}

	/**
	 * Product ids whose GTIN (global unique id) or SKU equals one of the
	 * codes, hyphens and spaces ignored.
	 */
	private static function ids_by_code( array $codes ): array {
		global $wpdb;
		$codes = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'norm' ), array_slice( $codes, 0, 100 ) ) ) ) );
		if ( ! $codes ) {
			return array();
		}
		$in = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_global_unique_id','_sku') AND UPPER(REPLACE(REPLACE(meta_value,'-',''),' ','')) IN ($in) LIMIT 200", $codes ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Terms of the contributor taxonomies matching a name or slug:
	 * taxonomy => term ids. Null when no contributor taxonomy is set.
	 */
	private static function author_terms( string $name ): ?array {
		$taxes = array_filter( TRA_Settings::get()['contrib'], 'taxonomy_exists' );
		if ( ! $taxes ) {
			return null;
		}
		$out = array();
		foreach ( $taxes as $tax ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
					'name__like' => mb_substr( $name, 0, 80 ),
					'fields'     => 'ids',
					'number'     => 50,
				)
			);
			$slug  = get_term_by( 'slug', sanitize_title( $name ), $tax );
			$ids   = is_array( $terms ) ? array_map( 'intval', $terms ) : array();
			if ( $slug instanceof WP_Term ) {
				$ids[] = (int) $slug->term_id;
			}
			if ( $ids ) {
				$out[ $tax ] = array_values( array_unique( $ids ) );
			}
		}
		return $out;
	}

	private static function fields_param( string $raw ): array {
		$raw = trim( $raw );
		return '' === $raw ? array() : array_slice( array_filter( array_map( 'sanitize_key', preg_split( '/\s*,\s*/', $raw ) ) ), 0, 40 );
	}

	private static function pick( array $item, array $fields ): array {
		if ( ! $fields ) {
			return $item;
		}
		$fields[] = 'id';
		return array_intersect_key( $item, array_flip( $fields ) );
	}

	/* ---------------------------------------------------------------------
	 * One product
	 * ------------------------------------------------------------------- */

	public static function item( WC_Product $p, float $discount ): array {
		$id     = (int) $p->get_id();
		$dec    = wc_get_price_decimals();
		$codes  = self::codes( $p );
		$sale   = $p->is_on_sale( 'edit' ) ? (float) $p->get_sale_price( 'edit' ) : null;
		$trade  = TRA_Scope::trade_price( $p, $discount );
		$dims   = array(
			'length' => (string) $p->get_length( 'edit' ),
			'width'  => (string) $p->get_width( 'edit' ),
			'height' => (string) $p->get_height( 'edit' ),
			'unit'   => (string) get_option( 'woocommerce_dimension_unit', 'cm' ),
		);
		$item = array(
			'id'                     => $id,
			'isbn'                   => '' !== $codes['isbn'] ? $codes['isbn'] : ( '' !== $codes['issn'] ? $codes['issn'] : ( '' !== $codes['gtin'] ? $codes['gtin'] : $codes['sku'] ) ),
			'identifiers'            => $codes,
			'title'                  => html_entity_decode( wp_strip_all_tags( $p->get_name( 'edit' ) ), ENT_QUOTES, 'UTF-8' ),
			'url'                    => (string) get_permalink( $id ),
			'publisher'              => self::term_names( $id, 'product_brand' ),
			'categories'             => self::cats( $id ),
			'contributors'           => self::contributors( $p ),
			'attributes'             => self::attributes( $p ),
			'description_html'       => self::clean_html( (string) $p->get_description( 'edit' ) ),
			'description_text'       => self::clean_text( (string) $p->get_description( 'edit' ) ),
			'short_description_html' => self::clean_html( (string) $p->get_short_description( 'edit' ) ),
			'short_description_text' => self::clean_text( (string) $p->get_short_description( 'edit' ) ),
			'dimensions'             => $dims,
			'weight'                 => array(
				'value' => (string) $p->get_weight( 'edit' ),
				'unit'  => (string) get_option( 'woocommerce_weight_unit', 'kg' ),
			),
			'prices'                 => array(
				'currency'         => get_woocommerce_currency(),
				'retail'           => wc_format_decimal( (float) $p->get_regular_price( 'edit' ), $dec ),
				'sale'             => null === $sale ? null : wc_format_decimal( $sale, $dec ),
				'trade'            => null === $trade ? null : wc_format_decimal( $trade, $dec ),
				'discount_percent' => $discount,
				'vat_rate'         => self::vat( $p ),
				'include_tax'      => 'yes' === get_option( 'woocommerce_prices_include_tax' ),
			),
			'availability'           => self::availability( $p ),
			'images'                 => self::images( $p ),
			'date_created'           => $p->get_date_created() ? $p->get_date_created()->date( 'c' ) : null,
			'date_modified'          => $p->get_date_modified() ? $p->get_date_modified()->date( 'c' ) : null,
		);
		/**
		 * Filters one product of the Trade Feed.
		 *
		 * @param array      $item    The product as the feed gives it.
		 * @param WC_Product $p       Product.
		 * @param int        $user_id The calling account.
		 */
		$item = apply_filters( 'tra_feed_item', $item, $p, get_current_user_id() );
		return is_array( $item ) ? $item : array( 'id' => $id );
	}

	/** gtin, isbn, issn, sku: GTIN field, attributes named ISBN / ISSN, SKU. */
	private static function codes( WC_Product $p ): array {
		$out = array(
			'gtin' => method_exists( $p, 'get_global_unique_id' ) ? (string) $p->get_global_unique_id( 'edit' ) : '',
			'isbn' => '',
			'issn' => '',
			'sku'  => (string) $p->get_sku( 'edit' ),
		);
		foreach ( $p->get_attributes() as $a ) {
			if ( ! $a instanceof WC_Product_Attribute ) {
				continue;
			}
			$name = $a->is_taxonomy() ? (string) wc_attribute_label( $a->get_name() ) : (string) $a->get_name();
			$key  = strtolower( trim( $name ) );
			if ( 'isbn' === $key || 'issn' === $key ) {
				$vals = $a->is_taxonomy() ? wc_get_product_terms( $p->get_id(), $a->get_name(), array( 'fields' => 'names' ) ) : $a->get_options();
				if ( $vals ) {
					$out[ $key ] = trim( (string) reset( $vals ) );
				}
			}
		}
		if ( '' === $out['isbn'] && '' !== $out['gtin'] && preg_match( '/^97[89]/', self::norm( $out['gtin'] ) ) ) {
			$out['isbn'] = $out['gtin'];
		}
		return $out;
	}

	private static function term_names( int $id, string $tax ): array {
		if ( ! taxonomy_exists( $tax ) ) {
			return array();
		}
		$terms = get_the_terms( $id, $tax );
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$out[] = html_entity_decode( (string) $t->name, ENT_QUOTES, 'UTF-8' );
		}
		return $out;
	}

	private static function cats( int $id ): array {
		$terms = get_the_terms( $id, 'product_cat' );
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$out[] = array(
				'id'   => (int) $t->term_id,
				'name' => html_entity_decode( (string) $t->name, ENT_QUOTES, 'UTF-8' ),
				'slug' => (string) $t->slug,
			);
		}
		return $out;
	}

	private static function contributors( WC_Product $p ): array {
		$out = array();
		foreach ( TRA_Settings::get()['contrib'] as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$names = wc_get_product_terms( $p->get_id(), $tax, array( 'fields' => 'names' ) );
			if ( $names ) {
				$out[] = array(
					'role'     => html_entity_decode( (string) wc_attribute_label( $tax ), ENT_QUOTES, 'UTF-8' ),
					'role_key' => substr( $tax, 3 ),
					'names'    => array_map(
						static function ( $n ) {
							return html_entity_decode( (string) $n, ENT_QUOTES, 'UTF-8' );
						},
						array_values( $names )
					),
				);
			}
		}
		return $out;
	}

	/** Visible attributes (taxonomy and local) with their values. */
	private static function attributes( WC_Product $p ): array {
		$out = array();
		foreach ( $p->get_attributes() as $a ) {
			if ( ! $a instanceof WC_Product_Attribute || ! $a->get_visible() ) {
				continue;
			}
			if ( $a->is_taxonomy() ) {
				$vals = wc_get_product_terms( $p->get_id(), $a->get_name(), array( 'fields' => 'names' ) );
				$name = (string) wc_attribute_label( $a->get_name() );
				$slug = (string) $a->get_name();
			} else {
				$vals = $a->get_options();
				$name = (string) $a->get_name();
				$slug = sanitize_title( $name );
			}
			$out[] = array(
				'name'   => html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ),
				'slug'   => $slug,
				'values' => array_map(
					static function ( $v ) {
						return html_entity_decode( trim( (string) $v ), ENT_QUOTES, 'UTF-8' );
					},
					array_values( (array) $vals )
				),
			);
		}
		return $out;
	}

	/** VAT rate (%) of the product's tax class at the shop's base address. */
	private static function vat( WC_Product $p ): ?float {
		if ( ! wc_tax_enabled() || 'taxable' !== $p->get_tax_status( 'edit' ) ) {
			return null;
		}
		$rates = WC_Tax::get_base_tax_rates( $p->get_tax_class( 'edit' ) );
		foreach ( (array) $rates as $r ) {
			if ( isset( $r['rate'] ) ) {
				return (float) $r['rate'];
			}
		}
		return null;
	}

	private static function availability( WC_Product $p ): array {
		$date = null;
		$pre  = false;
		if ( function_exists( 'por_get_release' ) ) {
			$r = por_get_release( $p );
			if ( is_array( $r ) && ! empty( $r['active'] ) ) {
				$pre  = true;
				$date = ! empty( $r['date'] ) ? (string) $r['date'] : null;
			}
		}
		if ( ! $pre && 'yes' === $p->get_meta( '_simple_preorder' ) ) {
			// PRENA (no PHP API): the release time as a Unix timestamp.
			$pre = true;
			$ts  = (int) $p->get_meta( '_wpro_date' );
			$date = $ts > 0 ? wp_date( 'Y-m-d', $ts ) : null;
		}
		$managed = $p->managing_stock();
		return array(
			'status'       => $pre ? 'preorder' : (string) $p->get_stock_status( 'edit' ),
			'quantity'     => $managed ? (int) $p->get_stock_quantity( 'edit' ) : null,
			'preorder'     => $pre,
			'release_date' => $date,
		);
	}

	private static function images( WC_Product $p ): array {
		$ids = array_filter( array_merge( array( (int) $p->get_image_id( 'edit' ) ), array_map( 'intval', (array) $p->get_gallery_image_ids( 'edit' ) ) ) );
		$out = array();
		foreach ( array_unique( $ids ) as $aid ) {
			$src = wp_get_attachment_image_src( $aid, 'full' );
			if ( ! $src ) {
				continue;
			}
			$out[] = array(
				'src'    => (string) $src[0],
				'width'  => (int) $src[1],
				'height' => (int) $src[2],
				'alt'    => (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ),
			);
		}
		return $out;
	}

	/** Description HTML without shortcodes, scripts, buttons or layout wrappers. */
	public static function clean_html( string $html ): string {
		$html = strip_shortcodes( $html );
		$html = (string) preg_replace( '#<(script|style|button|iframe|form|svg)\b[^>]*>.*?</\1>#is', '', $html );
		$html = wp_kses(
			$html,
			array(
				'p'          => array(),
				'br'         => array(),
				'strong'     => array(),
				'b'          => array(),
				'em'         => array(),
				'i'          => array(),
				'ul'         => array(),
				'ol'         => array(),
				'li'         => array(),
				'h2'         => array(),
				'h3'         => array(),
				'h4'         => array(),
				'blockquote' => array(),
			)
		);
		$html = (string) preg_replace( '/<p>\s*<\/p>/u', '', $html );
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $html ) );
	}

	public static function clean_text( string $html ): string {
		$text = self::clean_html( $html );
		$text = (string) preg_replace( '#<(br|/p|/li|/h[2-4]|/blockquote)\s*/?>#i', "\n", $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( "/[ \t]+/u", ' ', $text );
		$text = (string) preg_replace( "/ *\n */u", "\n", $text );
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}

	/* ---------------------------------------------------------------------
	 * After dispatch: headers, log; XML output
	 * ------------------------------------------------------------------- */

	private static function ours( $request ): bool {
		return $request instanceof WP_REST_Request && 0 === strpos( (string) $request->get_route(), '/' . self::NS . '/' );
	}

	public static function after( $response, $server, $request ) {
		if ( ! self::ours( $request ) || ! $response instanceof WP_REST_Response ) {
			return $response;
		}
		foreach ( wp_get_nocache_headers() as $k => $v ) {
			$response->header( $k, $v );
		}
		$response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
		if ( self::$rate ) {
			$response->header( 'X-RateLimit-Limit', (string) self::$rate[0] );
			$response->header( 'X-RateLimit-Remaining', (string) self::$rate[1] );
			$response->header( 'X-RateLimit-Reset', (string) self::$rate[2] );
		}
		if ( 429 === $response->get_status() && self::$retry > 0 ) {
			$response->header( 'Retry-After', (string) self::$retry );
		}
		try {
			self::log( $request, $response );
		} catch ( \Throwable $e ) {
			unset( $e ); // The log never breaks an answer.
		}
		return $response;
	}

	private static function log( WP_REST_Request $request, WP_REST_Response $response ): void {
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return; // Failed logins are WordPress's business (no user, nothing to tie the line to).
		}
		$data  = $response->get_data();
		$n     = is_array( $data ) && isset( $data['items'] ) && is_array( $data['items'] ) ? count( $data['items'] ) : ( 200 === $response->get_status() ? 1 : 0 );
		$query = $request->get_query_params();
		unset( $query['_wpnonce'] );
		$route = substr( (string) $request->get_route(), strlen( '/' . self::NS ) ) . ( $query ? '?' . http_build_query( $query ) : '' );
		$line  = array(
			't' => time(),
			'u' => $uid,
			'r' => mb_substr( $route, 0, 200 ),
			's' => (int) $response->get_status(),
			'n' => $n,
		);
		update_user_meta( $uid, 'tra_api_seen', $line );
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift( $log, $line );
		update_option( self::LOG, array_slice( $log, 0, self::LOG_MAX ), false );
	}

	public static function log_lines(): array {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	public static function serve_xml( $served, $result, $request, $server ) {
		if ( $served || ! self::ours( $request ) || 'xml' !== $request->get_param( 'output' ) || ! $result instanceof WP_HTTP_Response ) {
			return $served;
		}
		$data = $result->get_data();
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/xml; charset=UTF-8', true );
		}
		$root = 200 === $result->get_status() ? 'response' : 'error';
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . self::xml( $root, $data ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in xml().
		return true;
	}

	/** Element names of list items. */
	private static function singular( string $key ): string {
		$map = array(
			'items'        => 'item',
			'categories'   => 'category',
			'contributors' => 'contributor',
			'names'        => 'name',
			'attributes'   => 'attribute',
			'values'       => 'value',
			'images'       => 'image',
			'publisher'    => 'name',
		);
		return isset( $map[ $key ] ) ? $map[ $key ] : 'value';
	}

	private static function xml( string $name, $value ): string {
		$name = preg_match( '/^[A-Za-z_][A-Za-z0-9_\-]*$/', $name ) ? $name : 'value';
		if ( null === $value ) {
			return '<' . $name . '/>';
		}
		if ( is_bool( $value ) ) {
			return '<' . $name . '>' . ( $value ? 'true' : 'false' ) . '</' . $name . '>';
		}
		if ( is_scalar( $value ) ) {
			return '<' . $name . '>' . htmlspecialchars( (string) $value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</' . $name . '>';
		}
		$value = (array) $value;
		$list  = array_keys( $value ) === range( 0, count( $value ) - 1 );
		$inner = '';
		foreach ( $value as $k => $v ) {
			$inner .= self::xml( $list ? self::singular( $name ) : (string) $k, $v );
		}
		return '<' . $name . '>' . $inner . '</' . $name . '>';
	}
}

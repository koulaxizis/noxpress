<?php
/**
 * SHF_Index — product sets and counts (read only).
 *
 * Source of attribute data:
 *  - WooCommerce's wc_product_attributes_lookup table when it exists and is
 *    not being regenerated. It is kept up to date on every product change
 *    whether or not WooCommerce itself uses it for filtering, and it knows
 *    the stock of each variation: a variation value ("Pink") counts only
 *    when that variation is in stock.
 *  - Fallback: term_relationships (parent products, no per-variation stock).
 *
 * Maps (product → terms, product → categories, product → price) are read
 * with one query each and kept in the object cache, keyed by the posts /
 * terms "last changed" stamps, which WordPress bumps on every change. No
 * option or transient is written: without a persistent object cache the
 * maps live for the current request only (Bible §14.5).
 *
 * Counting: options of one filter combine with OR, filters with AND. The
 * count of an option is the number of products left if it were added: the
 * filter's own selection is left out, every other filter applies.
 */

defined( 'ABSPATH' ) || exit;

final class SHF_Index {

	const TTL = 300;

	/** Main query vars before our changes (set by SHF_Query). */
	private static $snapshot = null;

	/** Per-request caches. */
	private static $maps    = array();
	private static $context = null;
	private static $shop    = null;
	private static $source  = null;

	/* =====================================================================
	 * Source
	 * =================================================================== */

	/** 'lookup' (WooCommerce lookup table) or 'terms' (fallback). */
	public static function source(): string {
		if ( null !== self::$source ) {
			return self::$source;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wc_product_attributes_lookup';
		$key   = 'source:' . wp_cache_get_last_changed( 'posts' );
		$found = false;
		$src   = wp_cache_get( $key, 'shf', false, $found );
		if ( ! $found ) {
			$exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$regen  = 'yes' === get_option( 'woocommerce_attribute_lookup_regeneration_in_progress' );
			$src    = ( $exists && ! $regen ) ? 'lookup' : 'terms';
			$src    = (string) apply_filters( 'shf_index_source', $src );
			wp_cache_set( $key, $src, 'shf', self::TTL );
		}
		self::$source = 'lookup' === $src ? 'lookup' : 'terms';
		return self::$source;
	}

	/* =====================================================================
	 * Maps
	 * =================================================================== */

	private static function stamp(): string {
		return wp_cache_get_last_changed( 'posts' ) . ':' . wp_cache_get_last_changed( 'terms' );
	}

	/**
	 * Product (parent) id → term ids of one attribute.
	 *
	 * @return array<int, int[]>
	 */
	public static function attr_map( string $attr ): array {
		$tax = wc_attribute_taxonomy_name( $attr );
		$src = self::source();
		$key = 'map:' . $src . ':' . $tax . ':' . self::stamp();
		if ( isset( self::$maps[ $key ] ) ) {
			return self::$maps[ $key ];
		}
		$found = false;
		$map   = wp_cache_get( $key, 'shf', false, $found );
		if ( ! $found || ! is_array( $map ) ) {
			global $wpdb;
			if ( 'lookup' === $src ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT product_or_parent_id AS p, term_id AS t FROM {$wpdb->prefix}wc_product_attributes_lookup WHERE taxonomy = %s AND ( is_variation_attribute = 0 OR in_stock = 1 )",
						$tax
					),
					ARRAY_N
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT tr.object_id AS p, tt.term_id AS t FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s",
						$tax
					),
					ARRAY_N
				);
			}
			$map = self::pairs( (array) $rows );
			wp_cache_set( $key, $map, 'shf', self::TTL );
		}
		self::$maps[ $key ] = $map;
		return $map;
	}

	/**
	 * Product id → category ids (direct terms only).
	 *
	 * @return array<int, int[]>
	 */
	public static function cat_map(): array {
		$key = 'cats:' . self::stamp();
		if ( isset( self::$maps[ $key ] ) ) {
			return self::$maps[ $key ];
		}
		$found = false;
		$map   = wp_cache_get( $key, 'shf', false, $found );
		if ( ! $found || ! is_array( $map ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				"SELECT tr.object_id AS p, tt.term_id AS t FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'product_cat'",
				ARRAY_N
			);
			$map  = self::pairs( (array) $rows );
			wp_cache_set( $key, $map, 'shf', self::TTL );
		}
		self::$maps[ $key ] = $map;
		return $map;
	}

	/**
	 * Product id → [min price, max price] (wc_product_meta_lookup).
	 *
	 * @return array<int, array{0:float,1:float}>
	 */
	public static function price_map(): array {
		$key = 'price:' . wp_cache_get_last_changed( 'posts' );
		if ( isset( self::$maps[ $key ] ) ) {
			return self::$maps[ $key ];
		}
		$found = false;
		$map   = wp_cache_get( $key, 'shf', false, $found );
		if ( ! $found || ! is_array( $map ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				"SELECT l.product_id, l.min_price, l.max_price FROM {$wpdb->wc_product_meta_lookup} l INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id WHERE p.post_type = 'product'",
				ARRAY_N
			);
			$map  = array();
			foreach ( (array) $rows as $r ) {
				if ( null !== $r[1] && null !== $r[2] ) {
					$map[ (int) $r[0] ] = array( (float) $r[1], (float) $r[2] );
				}
			}
			wp_cache_set( $key, $map, 'shf', self::TTL );
		}
		self::$maps[ $key ] = $map;
		return $map;
	}

	/**
	 * Rows [product, term] → product → unique term ids. The term lists are
	 * keyed by term id (value = term id): one pass, no re-indexing, which
	 * matters with tens of thousands of rows.
	 */
	private static function pairs( array $rows ): array {
		$map = array();
		foreach ( $rows as $r ) {
			$t = (int) $r[1];
			if ( $t > 0 ) {
				$map[ (int) $r[0] ][ $t ] = $t;
			}
		}
		unset( $map[0] );
		return $map;
	}

	/* =====================================================================
	 * Product sets (arrays keyed by product id)
	 * =================================================================== */

	/** Main query vars before our changes (WooCommerce's own filters included). */
	public static function set_snapshot( array $vars ): void {
		self::$snapshot = $vars;
	}

	public static function has_snapshot(): bool {
		return null !== self::$snapshot;
	}

	/**
	 * Products of the current listing without our filters and without the
	 * price range: category / tag / search, visibility and stock rules, and
	 * any other plugin's changes to the main query.
	 *
	 * @return array<int, true>
	 */
	public static function context_ids(): array {
		if ( null !== self::$context ) {
			return self::$context;
		}
		if ( null === self::$snapshot ) {
			self::$context = self::shop_ids();
			return self::$context;
		}
		$vars = self::$snapshot;
		unset( $vars['paged'], $vars['offset'], $vars['post__in'] );
		$vars = array_merge(
			$vars,
			array(
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'nopaging'               => true,
				'no_found_rows'          => true,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'shf_aux'                => 1,
			)
		);
		self::$context = self::ids_query( $vars );
		return self::$context;
	}

	/**
	 * Every product visible in the catalog (used by the category tree, which
	 * links to other categories).
	 *
	 * @return array<int, true>
	 */
	public static function shop_ids(): array {
		if ( null !== self::$shop ) {
			return self::$shop;
		}
		$vars       = array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => -1,
			'nopaging'               => true,
			'no_found_rows'          => true,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'tax_query'              => WC()->query->get_tax_query( array(), false ), // phpcs:ignore WordPress.DB.SlowDBQuery -- visibility rules, as WooCommerce.
			'meta_query'             => WC()->query->get_meta_query( array(), false ), // phpcs:ignore WordPress.DB.SlowDBQuery
			'shf_aux'                => 1,
		);
		self::$shop = self::ids_query( $vars );
		return self::$shop;
	}

	/** Runs an ids-only query (object cached by key of its vars). */
	private static function ids_query( array $vars ): array {
		$key   = 'ids:' . md5( (string) wp_json_encode( $vars ) ) . ':' . self::stamp();
		$found = false;
		$ids   = wp_cache_get( $key, 'shf', false, $found );
		if ( ! $found || ! is_array( $ids ) ) {
			$q   = new WP_Query( $vars );
			$ids = array_map( 'intval', (array) $q->posts );
			wp_cache_set( $key, $ids, 'shf', self::TTL );
		}
		return array_fill_keys( $ids, true );
	}

	/**
	 * Products matching the selected tokens of one attribute (OR).
	 *
	 * @return array<int, true>
	 */
	public static function attr_ids( string $attr, array $tokens ): array {
		$options = SHF_Groups::options( $attr );
		$want    = array();
		foreach ( $tokens as $tok ) {
			foreach ( $options[ $tok ]['terms'] ?? array() as $t ) {
				$want[ $t ] = true;
			}
		}
		$out = array();
		foreach ( self::attr_map( $attr ) as $p => $terms ) {
			foreach ( $terms as $t ) {
				if ( isset( $want[ $t ] ) ) {
					$out[ $p ] = true;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Products in the price range, with WooCommerce's own rule (a range of
	 * variation prices that touches the selected range) and its tax
	 * adjustment for prices shown with tax.
	 *
	 * @return array<int, true>
	 */
	public static function price_ids( float $min, float $max ): array {
		if ( wc_tax_enabled() && 'incl' === get_option( 'woocommerce_tax_display_shop' ) && ! wc_prices_include_tax() ) {
			$rates = WC_Tax::get_rates( apply_filters( 'woocommerce_price_filter_widget_tax_class', '' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WooCommerce's own filter.
			if ( $rates ) {
				$min -= WC_Tax::get_tax_total( WC_Tax::calc_inclusive_tax( $min, $rates ) );
				$max -= WC_Tax::get_tax_total( WC_Tax::calc_inclusive_tax( $max, $rates ) );
			}
		}
		$out = array();
		foreach ( self::price_map() as $p => $r ) {
			if ( ! ( $max < $r[0] || $min > $r[1] ) ) {
				$out[ $p ] = true;
			}
		}
		return $out;
	}

	/** Intersection of id sets (smallest first). */
	public static function intersect( array $sets ): array {
		if ( ! $sets ) {
			return array();
		}
		usort(
			$sets,
			static function ( $a, $b ) {
				return count( $a ) <=> count( $b );
			}
		);
		$out = array_shift( $sets );
		foreach ( $sets as $s ) {
			$out = array_intersect_key( $out, $s );
		}
		return $out;
	}

	/**
	 * Sets of the active filters: attribute → ids, plus 'price' → ids.
	 *
	 * @return array<string, array<int, true>>
	 */
	public static function active_sets(): array {
		$out = array();
		foreach ( SHF_Request::selected() as $attr => $tokens ) {
			$out[ 'attr:' . $attr ] = self::attr_ids( $attr, $tokens );
		}
		$p = SHF_Request::price();
		if ( null !== $p ) {
			$out['price'] = self::price_ids( $p[0], $p[1] );
		}
		return $out;
	}

	/**
	 * A base set with every active filter applied except one.
	 *
	 * @param array  $base   Ids (keys).
	 * @param string $except 'attr:{slug}', 'price' or '' (none left out).
	 * @return array<int, true>
	 */
	public static function filtered( array $base, string $except ): array {
		$sets = array( $base );
		foreach ( self::active_sets() as $key => $ids ) {
			if ( $key !== $except ) {
				$sets[] = $ids;
			}
		}
		return self::intersect( $sets );
	}

	/* =====================================================================
	 * Counts
	 * =================================================================== */

	/**
	 * Count per option of an attribute filter (products of the current
	 * listing with every other filter applied).
	 *
	 * @return array<string, int> token → count
	 */
	public static function attr_counts( string $attr ): array {
		$options = SHF_Groups::options( $attr );
		$by_term = array();
		foreach ( $options as $tok => $o ) {
			foreach ( $o['terms'] as $t ) {
				$by_term[ $t ][] = $tok;
			}
		}
		$counts = array_fill_keys( array_keys( $options ), 0 );
		$ids    = self::filtered( self::context_ids(), 'attr:' . $attr );
		$map    = self::attr_map( $attr );
		foreach ( $ids as $p => $_ ) {
			if ( empty( $map[ $p ] ) ) {
				continue;
			}
			$hit = array();
			foreach ( $map[ $p ] as $t ) {
				foreach ( $by_term[ $t ] ?? array() as $tok ) {
					$hit[ $tok ] = true;
				}
			}
			foreach ( $hit as $tok => $_x ) {
				++$counts[ $tok ];
			}
		}
		return $counts;
	}

	/**
	 * Count per product category, children included (catalog-wide, with
	 * every active filter applied).
	 *
	 * @param array<int,int> $parents term id → parent id.
	 * @return array<int, int>
	 */
	public static function cat_counts( array $parents ): array {
		$ids    = self::filtered( self::shop_ids(), '' );
		$map    = self::cat_map();
		$counts = array();
		foreach ( $ids as $p => $_ ) {
			if ( empty( $map[ $p ] ) ) {
				continue;
			}
			$seen = array();
			foreach ( $map[ $p ] as $t ) {
				$guard = 0;
				while ( $t > 0 && ! isset( $seen[ $t ] ) && $guard++ < 20 ) {
					$seen[ $t ] = true;
					$t          = $parents[ $t ] ?? 0;
				}
			}
			foreach ( $seen as $t => $_x ) {
				$counts[ $t ] = ( $counts[ $t ] ?? 0 ) + 1;
			}
		}
		return $counts;
	}

	/**
	 * Lowest and highest price of the current listing with every other
	 * filter applied.
	 *
	 * @return array{0:float,1:float}|null
	 */
	public static function price_bounds(): ?array {
		$ids = self::filtered( self::context_ids(), 'price' );
		$map = self::price_map();
		$lo  = null;
		$hi  = null;
		foreach ( $ids as $p => $_ ) {
			if ( isset( $map[ $p ] ) ) {
				$lo = null === $lo ? $map[ $p ][0] : min( $lo, $map[ $p ][0] );
				$hi = null === $hi ? $map[ $p ][1] : max( $hi, $map[ $p ][1] );
			}
		}
		return null === $lo ? null : array( (float) floor( $lo ), (float) ceil( $hi ) );
	}

	/** Flush the per-request caches (tests). */
	public static function reset(): void {
		self::$snapshot = null;
		self::$maps     = array();
		self::$context  = null;
		self::$shop     = null;
		self::$source   = null;
	}
}

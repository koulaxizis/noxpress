<?php
/**
 * HIC_Rules — which categories are hidden where, and the tax_query clauses
 * that hide their products.
 *
 * Nothing is written anywhere: every rule is applied at query time, so
 * turning the plugin off restores the catalog at once.
 *
 * A rule covers its category and, with "children", every subcategory (the
 * term set). Per context (shop, listings, search, related, blocks):
 *  - rules hiding a product when it has ANY hidden category give one
 *    clause: product_cat NOT IN the union of their sets;
 *  - rules hiding a product only when ALL its categories are hidden give
 *    a nested clause: NOT IN their sets OR IN every other category.
 * A rule never applies on the page of its own category (or subcategory):
 * the category archive stays reachable unless its "archive" setting closes
 * it (HIC_Front).
 *
 * Every clause carries the key 'hic' => 1 (ignored by WP_Tax_Query), so a
 * query that already has ours is not changed twice.
 */

defined( 'ABSPATH' ) || exit;

final class HIC_Rules {

	/** @var bool After an error the plugin stays out of the request (Bible §14.6). */
	private static $broken = false;

	/** @var int > 0 while we run our own term / product queries. */
	private static $internal = 0;

	/** @var array<int, int[]> Term sets by category id (per request). */
	private static $sets = array();

	/** @var int[]|null Every product category id (per request). */
	private static $all = null;

	/** @var array<string, int[]> visible_ids() results (per request). */
	private static $visible = array();

	/** @var bool|null */
	private static $active = null;

	/* =====================================================================
	 * State
	 * =================================================================== */

	/**
	 * True when the rules apply to this request: mode live, or mode test
	 * for a shop manager. Never after an error, never with HIC_DISABLE.
	 */
	public static function active(): bool {
		if ( self::$broken || Hide_Catalog::killed() ) {
			return false;
		}
		if ( null !== self::$active ) {
			return self::$active;
		}
		$s = HIC_Settings::get();
		if ( 'live' === $s['mode'] ) {
			$on = true;
		} elseif ( 'test' === $s['mode'] ) {
			// The user is not known yet: stay out, and decide again later.
			if ( ! did_action( 'set_current_user' ) ) {
				return false;
			}
			$on = current_user_can( 'manage_woocommerce' );
		} else {
			$on = false;
		}
		if ( $on ) {
			$on = false;
			foreach ( $s['rules'] as $r ) {
				if ( HIC_Settings::rule_does_something( $r ) ) {
					$on = true;
					break;
				}
			}
		}
		self::$active = $on;
		return $on;
	}

	/** An error: stop for the rest of the request. */
	public static function fail(): void {
		self::$broken = true;
	}

	/** Drops the per-request caches (tests, after saving). */
	public static function flush(): void {
		self::$sets    = array();
		self::$all     = null;
		self::$visible = array();
		self::$active  = null;
	}

	public static function internal(): bool {
		return self::$internal > 0;
	}

	/**
	 * Our own term and product lookups run between enter() and leave(), so
	 * our filters stay out of them (no recursion through get_terms).
	 */
	public static function enter(): void {
		++self::$internal;
	}

	public static function leave(): void {
		self::$internal = max( 0, self::$internal - 1 );
	}

	/* =====================================================================
	 * Term sets
	 * =================================================================== */

	/**
	 * The category of a rule and, with "children", its subcategories.
	 *
	 * @return int[]
	 */
	public static function set( array $rule ): array {
		$cat = (int) $rule['cat'];
		$key = $cat * 2 + ( empty( $rule['children'] ) ? 0 : 1 );
		if ( isset( self::$sets[ $key ] ) ) {
			return self::$sets[ $key ];
		}
		$ids = array( $cat );
		if ( ! empty( $rule['children'] ) ) {
			self::enter();
			$kids = get_term_children( $cat, 'product_cat' );
			self::leave();
			if ( is_array( $kids ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $kids ) );
			}
		}
		self::$sets[ $key ] = array_values( array_unique( $ids ) );
		return self::$sets[ $key ];
	}

	/** @return int[] Every product category id. */
	public static function all_cats(): array {
		if ( null === self::$all ) {
			++self::$internal;
			$ids = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);
			--self::$internal;
			self::$all = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		}
		return self::$all;
	}

	/**
	 * Rules that hide something in a context ('shop', 'listings', 'search',
	 * 'related', 'blocks', or 'terms' for the category itself), without the
	 * rules whose set contains one of the exempt terms.
	 *
	 * @param int[] $exempt Category ids whose rules do not apply here.
	 * @return array[]
	 */
	public static function rules_for( string $context, array $exempt = array() ): array {
		$out = array();
		foreach ( HIC_Settings::get()['rules'] as $r ) {
			if ( empty( $r[ $context ] ) ) {
				continue;
			}
			if ( $exempt && array_intersect( $exempt, self::set( $r ) ) ) {
				continue;
			}
			$out[] = $r;
		}
		return $out;
	}

	/**
	 * Hidden categories in a context (for the public API hic_hidden_terms()).
	 *
	 * @return int[]
	 */
	public static function hidden_terms( string $context ): array {
		if ( ! self::active() ) {
			return array();
		}
		$ids = array();
		foreach ( self::rules_for( $context ) as $r ) {
			$ids = array_merge( $ids, self::set( $r ) );
		}
		return array_values( array_unique( $ids ) );
	}

	/* =====================================================================
	 * Product clauses
	 * =================================================================== */

	/**
	 * tax_query clauses that hide the products of a context.
	 *
	 * @param int[] $exempt Category ids whose rules do not apply here.
	 * @return array[] Clauses to append to a tax_query (empty = nothing to hide).
	 */
	public static function clauses( string $context, array $exempt = array() ): array {
		if ( ! self::active() ) {
			return array();
		}
		$any = array();
		$all = array();
		foreach ( self::rules_for( $context, $exempt ) as $r ) {
			if ( empty( $r['all_only'] ) ) {
				$any = array_merge( $any, self::set( $r ) );
			} else {
				$all = array_merge( $all, self::set( $r ) );
			}
		}
		$out = array();
		if ( $any ) {
			$out[] = self::not_in( array_values( array_unique( $any ) ) );
		}
		if ( $all ) {
			$all     = array_values( array_diff( array_unique( $all ), $any ) );
			$visible = array_values( array_diff( self::all_cats(), $all, $any ) );
			if ( $all && $visible ) {
				$out[] = array(
					'relation' => 'OR',
					'hic'      => 1,
					self::not_in( $all ),
					array(
						'taxonomy'         => 'product_cat',
						'field'            => 'term_id',
						'terms'            => $visible,
						'operator'         => 'IN',
						'include_children' => false,
					),
				);
			} elseif ( $all ) {
				$out[] = self::not_in( $all );
			}
		}
		return $out;
	}

	private static function not_in( array $ids ): array {
		return array(
			'taxonomy'         => 'product_cat',
			'field'            => 'term_id',
			'terms'            => $ids,
			'operator'         => 'NOT IN',
			'include_children' => false,
			'hic'              => 1,
		);
	}

	/** True when a tax_query already has our clauses. */
	public static function has_ours( $tax_query ): bool {
		if ( ! is_array( $tax_query ) ) {
			return false;
		}
		foreach ( $tax_query as $c ) {
			if ( is_array( $c ) && ! empty( $c['hic'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Appends the clauses of a context to a tax_query (once).
	 *
	 * @param mixed $tax_query Existing tax_query.
	 * @param int[] $exempt    Category ids whose rules do not apply here.
	 * @return mixed The tax_query (unchanged when there is nothing to hide).
	 */
	public static function append( $tax_query, string $context, array $exempt = array() ) {
		$add = self::clauses( $context, $exempt );
		if ( ! $add || self::has_ours( $tax_query ) ) {
			return $tax_query;
		}
		$tax_query = is_array( $tax_query ) ? $tax_query : array();
		foreach ( $add as $c ) {
			$tax_query[] = $c;
		}
		return $tax_query;
	}

	/**
	 * The ids of a list that may be shown in a context, in their order.
	 *
	 * @param int[] $ids Product ids.
	 * @return int[]
	 */
	public static function visible_ids( array $ids, string $context ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return $ids;
		}
		$clauses = self::clauses( $context );
		if ( ! $clauses ) {
			return $ids;
		}
		$key = $context . ':' . implode( ',', $ids );
		if ( ! isset( self::$visible[ $key ] ) ) {
			++self::$internal;
			$q = new WP_Query(
				array(
					'post_type'              => array( 'product', 'product_variation' ),
					'post_status'            => 'any',
					'post__in'               => $ids,
					'fields'                 => 'ids',
					'posts_per_page'         => -1,
					'nopaging'               => true,
					'no_found_rows'          => true,
					'orderby'                => 'post__in',
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'tax_query'              => $clauses, // phpcs:ignore WordPress.DB.SlowDBQuery -- the point of the plugin.
					'hic_internal'           => 1,
				)
			);
			--self::$internal;
			$keep = array_fill_keys( array_map( 'intval', (array) $q->posts ), true );
			$out  = array();
			foreach ( $ids as $id ) {
				if ( isset( $keep[ $id ] ) ) {
					$out[] = $id;
				}
			}
			self::$visible[ $key ] = $out;
		}
		return self::$visible[ $key ];
	}

	/* =====================================================================
	 * Category pages
	 * =================================================================== */

	/**
	 * What happens on a category page: 'open' | 'noindex' | '404' | 'redirect'.
	 * When several rules cover the category, the strictest wins.
	 */
	public static function archive_for( int $term_id ): string {
		if ( ! self::active() || $term_id <= 0 ) {
			return 'open';
		}
		$rank = array(
			'open'     => 0,
			'noindex'  => 1,
			'redirect' => 2,
			'404'      => 3,
		);
		$out  = 'open';
		foreach ( HIC_Settings::get()['rules'] as $r ) {
			if ( 'open' !== $r['archive'] && in_array( $term_id, self::set( $r ), true ) && $rank[ $r['archive'] ] > $rank[ $out ] ) {
				$out = $r['archive'];
			}
		}
		return $out;
	}

	/**
	 * Category ids whose pages are not open (for sitemaps).
	 *
	 * @return int[]
	 */
	public static function closed_archives(): array {
		if ( ! self::active() ) {
			return array();
		}
		$ids = array();
		foreach ( HIC_Settings::get()['rules'] as $r ) {
			if ( 'open' !== $r['archive'] ) {
				$ids = array_merge( $ids, self::set( $r ) );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/* =====================================================================
	 * Admin preview (runs on the settings page only)
	 * =================================================================== */

	/**
	 * Published products of a rule's set, and how many of them are also in
	 * a category outside the set.
	 *
	 * @return array{0:int,1:int}
	 */
	public static function preview( array $rule ): array {
		$set   = self::set( $rule );
		$count = static function ( array $tax ): int {
			$q = new WP_Query(
				array(
					'post_type'              => 'product',
					'post_status'            => 'publish',
					'fields'                 => 'ids',
					'posts_per_page'         => 1,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'tax_query'              => $tax, // phpcs:ignore WordPress.DB.SlowDBQuery -- admin preview.
					'hic_internal'           => 1,
				)
			);
			return (int) $q->found_posts;
		};
		++self::$internal;
		$in    = array(
			'taxonomy'         => 'product_cat',
			'field'            => 'term_id',
			'terms'            => $set,
			'include_children' => false,
		);
		$total = $count( array( $in ) );
		$other = array_values( array_diff( self::all_cats(), $set ) );
		$mixed = $other ? $count(
			array(
				'relation' => 'AND',
				$in,
				array(
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => $other,
					'include_children' => false,
				),
			)
		) : 0;
		--self::$internal;
		return array( $total, $mixed );
	}
}

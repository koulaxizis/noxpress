<?php
/**
 * HMS_Markers — marker classes in the class="…" attribute of [products].
 *
 * Built-in markers, prefix "hs" and the alias prefix from the settings:
 *   {p}-exclude-{ID}         leave product ID out (repeatable)
 *   {p}-exclude-cat-{slug}   leave a product category out (repeatable;
 *                            its subcategories too, as tax_query does)
 *   {p}-no-preorders         leave pre-orders out (filter hms_preorder_meta)
 *   {p}-title-{word}         keep products whose title contains the word,
 *                            case-insensitive under the usual collations
 * Presets: a class equal to a preset name applies its conditions.
 *
 * Every marker only adds conditions, so classes combine freely. The
 * shortcode caches its results keyed on the final query args, so these
 * conditions are part of the cache key and each section keeps its own
 * cache. A class that is not a marker stays a plain CSS class.
 */

defined( 'ABSPATH' ) || exit;

final class HMS_Markers {

	const PREFIX = 'hs';

	/** Custom query var read in posts_where. */
	const TITLE_VAR = 'hms_title_like';

	/** True after an error: the rest of the request runs without us (Bible §14.6). */
	private static $off = false;

	public static function init(): void {
		add_filter( 'woocommerce_shortcode_products_query', array( __CLASS__, 'filter_query' ), 10, 3 );
		add_filter( 'posts_where', array( __CLASS__, 'title_where' ), 10, 2 );
	}

	/**
	 * Pre-order meta keys and the value that marks a pre-order.
	 *
	 * @return array<string,string> meta key => value.
	 */
	public static function preorder_meta(): array {
		$meta = apply_filters(
			'hms_preorder_meta',
			array(
				'_simple_preorder' => 'yes', // PRENA (Pre-Orders for WooCommerce, simple).
				'_por_on'          => 'yes', // Pre-Orders (Noxpress).
			)
		);
		$out = array();
		foreach ( (array) $meta as $k => $v ) {
			if ( is_string( $k ) && '' !== $k && is_scalar( $v ) ) {
				$out[ $k ] = (string) $v;
			}
		}
		return $out;
	}

	/** The prefixes of the built-in markers: hs, then the alias. */
	public static function prefixes( string $alias ): array {
		return '' === $alias ? array( self::PREFIX ) : array( self::PREFIX, $alias );
	}

	/**
	 * Reads one built-in marker.
	 *
	 * @return array|null array( kind, value ) — kind 'id' | 'cat' |
	 *                    'preorders' | 'title' — or null when it is not one.
	 */
	public static function builtin( string $class, string $alias ): ?array {
		foreach ( self::prefixes( $alias ) as $p ) {
			if ( 0 !== strpos( $class, $p . '-' ) ) {
				continue;
			}
			$rest = substr( $class, strlen( $p ) + 1 );
			if ( 'no-preorders' === $rest ) {
				return array( 'preorders', true );
			}
			if ( preg_match( '/^exclude-cat-(.+)$/', $rest, $m ) ) {
				$slug = sanitize_title( $m[1] );
				return '' !== $slug ? array( 'cat', $slug ) : null;
			}
			if ( preg_match( '/^exclude-(\d{1,19})$/', $rest, $m ) ) {
				$id = (int) $m[1];
				return $id > 0 ? array( 'id', $id ) : null;
			}
			if ( preg_match( '/^title-(.+)$/u', $rest, $m ) ) {
				$word = function_exists( 'mb_substr' ) ? mb_substr( $m[1], 0, HMS_Settings::MAX_TITLE ) : substr( $m[1], 0, HMS_Settings::MAX_TITLE );
				return array( 'title', $word );
			}
		}
		return null;
	}

	/** True when a preset may not take this name (reserved hs-…, or a built-in marker of the alias). */
	public static function is_builtin( string $name, string $alias ): bool {
		return 0 === strpos( $name, self::PREFIX . '-' ) || null !== self::builtin( $name, $alias );
	}

	/**
	 * Reads a class attribute.
	 *
	 * @return array{ids:int[],cat_slugs:string[],cat_ids:int[],preorders:bool,titles:string[],found:array}
	 *         found: one row per class, array( class, kind, value ), where
	 *         kind is a built-in kind, 'preset' (value: the preset) or
	 *         'none' (a plain CSS class).
	 */
	public static function parse( string $class_attr ): array {
		$spec = array(
			'ids'       => array(),
			'cat_slugs' => array(),
			'cat_ids'   => array(),
			'preorders' => false,
			'titles'    => array(),
			'found'     => array(),
		);
		$classes = preg_split( '/\s+/', $class_attr, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $classes ) {
			return $spec;
		}

		$s       = HMS_Settings::get();
		$presets = array();
		foreach ( $s['presets'] as $p ) {
			$presets[ $p['name'] ] = $p;
		}

		foreach ( $classes as $class ) {
			$b = self::builtin( $class, $s['alias'] );
			if ( null !== $b ) {
				list( $kind, $value ) = $b;
				if ( 'id' === $kind ) {
					$spec['ids'][] = $value;
				} elseif ( 'cat' === $kind ) {
					$spec['cat_slugs'][] = $value;
				} elseif ( 'preorders' === $kind ) {
					$spec['preorders'] = true;
				} else {
					$spec['titles'][] = $value;
				}
				$spec['found'][] = array( $class, $kind, $value );
				continue;
			}
			if ( isset( $presets[ $class ] ) ) {
				$p                 = $presets[ $class ];
				$spec['ids']       = array_merge( $spec['ids'], $p['ids'] );
				$spec['cat_ids']   = array_merge( $spec['cat_ids'], $p['cats'] );
				$spec['preorders'] = $spec['preorders'] || $p['preorders'];
				if ( '' !== $p['title'] ) {
					$spec['titles'][] = $p['title'];
				}
				$spec['found'][] = array( $class, 'preset', $p );
				continue;
			}
			$spec['found'][] = array( $class, 'none', null );
		}

		$spec['ids']       = array_values( array_unique( $spec['ids'] ) );
		$spec['cat_slugs'] = array_values( array_unique( $spec['cat_slugs'] ) );
		$spec['cat_ids']   = array_values( array_unique( $spec['cat_ids'] ) );
		$spec['titles']    = array_values( array_unique( $spec['titles'] ) );
		return $spec;
	}

	/** Adds the conditions of a parsed class attribute to WP_Query args. */
	public static function apply( array $query_args, array $spec ): array {
		if ( $spec['cat_slugs'] ) {
			$query_args['tax_query'] = self::add_clause(
				$query_args['tax_query'] ?? array(),
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'slug',
					'terms'    => $spec['cat_slugs'],
					'operator' => 'NOT IN',
				)
			);
		}
		if ( $spec['cat_ids'] ) {
			$query_args['tax_query'] = self::add_clause(
				$query_args['tax_query'] ?? array(),
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $spec['cat_ids'],
					'operator' => 'NOT IN',
				)
			);
		}

		if ( $spec['preorders'] ) {
			foreach ( self::preorder_meta() as $key => $value ) {
				$query_args['meta_query'] = self::add_clause(
					$query_args['meta_query'] ?? array(),
					array(
						'relation' => 'OR',
						array(
							'key'     => $key,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => $key,
							'value'   => $value,
							'compare' => '!=',
						),
					)
				);
			}
		}

		if ( $spec['ids'] ) {
			$query_args['post__not_in'] = array_values( array_unique( array_merge( array_map( 'intval', (array) ( $query_args['post__not_in'] ?? array() ) ), $spec['ids'] ) ) );
		}

		if ( $spec['titles'] ) {
			$query_args[ self::TITLE_VAR ] = array_values( array_unique( array_merge( (array) ( $query_args[ self::TITLE_VAR ] ?? array() ), $spec['titles'] ) ) );
		}

		return $query_args;
	}

	/**
	 * Adds a clause so that it is ANDed with what is there: a list whose
	 * top-level relation is OR is wrapped first.
	 */
	private static function add_clause( $list, array $clause ): array {
		$list = (array) $list;
		if ( isset( $list['relation'] ) && 'OR' === strtoupper( (string) $list['relation'] ) ) {
			$list = array( $list );
		}
		$list[] = $clause;
		return $list;
	}

	/** woocommerce_shortcode_products_query. */
	public static function filter_query( $query_args, $attributes, $type = '' ) {
		if ( self::$off || ! is_array( $query_args ) ) {
			return $query_args;
		}
		$class = is_array( $attributes ) && isset( $attributes['class'] ) && is_scalar( $attributes['class'] ) ? (string) $attributes['class'] : '';
		if ( '' === trim( $class ) ) {
			return $query_args;
		}
		try {
			return self::apply( $query_args, self::parse( $class ) );
		} catch ( \Throwable $e ) {
			self::$off = true;
			return $query_args;
		}
	}

	/** posts_where: the title condition of the hms_title_like query var. */
	public static function title_where( $where, $query ) {
		if ( self::$off || ! $query instanceof WP_Query ) {
			return $where;
		}
		$needles = $query->get( self::TITLE_VAR );
		if ( empty( $needles ) ) {
			return $where;
		}
		try {
			global $wpdb;
			foreach ( (array) $needles as $needle ) {
				if ( is_string( $needle ) && '' !== $needle ) {
					$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_title LIKE %s", '%' . $wpdb->esc_like( $needle ) . '%' );
				}
			}
		} catch ( \Throwable $e ) {
			self::$off = true;
		}
		return $where;
	}
}

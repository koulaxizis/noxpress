<?php
/**
 * PBN_Bundle — contents, prices, availability and derived data of bundles.
 *
 * Contents (_pbn_items, JSON): up to 30 rows
 *   { "p": product id, "q": quantity per bundle (1–99), "m": "fixed" | "choice", "v": [ variation ids ] }
 *  - fixed  : a simple product or one variation (p is that id)
 *  - choice : a variable product (p is the parent); the customer picks one
 *             of the allowed variations (v; empty = all of them)
 *
 * Price (quote()):
 *  - value V = Σ current price × quantity of the products inside (what
 *    they cost separately, sale prices included)
 *  - bundle price P = V − discount % ('percent'), or the price typed
 *    ('price'; when a choice costs more than the cheapest one, the
 *    difference is added)
 *  - the share of every line = P × line / V, in cents, the remainder cent
 *    by cent to the largest fractions (as RS_Reports::reconcile_splits),
 *    so the shares always add up to P exactly; a free product stays 0.
 *
 * Derived data (Bible §14.10, §14.23): the bundle's _regular_price (V),
 * _sale_price / _price (P), _stock_status and _pbn_* are written when the
 * bundle is saved (woocommerce_before_product_object_save) and rebuilt
 * when a product inside changes (price, stock, status): at shutdown in
 * admin, REST, cron and CLI requests, through one Action Scheduler job
 * (group product-bundles) when the change comes from a visitor request
 * (an order). After every rebuild noxpress_products_changed fires.
 *
 * Reverse index (option pbn_index, no autoload, derived): product id =>
 * bundle ids, so a stock change finds its bundles without a query.
 */

defined( 'ABSPATH' ) || exit;

final class PBN_Bundle {

	const META_ITEMS = '_pbn_items';
	const META_DTYPE = '_pbn_discount_type';
	const META_DISC  = '_pbn_discount';
	const META_MAX   = '_pbn_max';
	const META_VMAX  = '_pbn_value_max';
	const META_PMAX  = '_pbn_price_max';
	const META_AVAIL = '_pbn_avail';

	const OPT_INDEX = 'pbn_index';
	const GROUP     = 'product-bundles';
	const HOOK      = 'pbn_sync';
	const HOOK_ALL  = 'pbn_sync_all';
	const BATCH     = 50;
	const MAX_ROWS  = 30;
	const MAX_QTY   = 99;

	/** @var int[] Bundles to rebuild at shutdown (id => id). */
	private static $queue = array();

	/** @var WC_Product[]|false[] Per-request product cache. */
	private static $products = array();

	/** @var bool True while the deactivation hook marks bundles out of stock. */
	private static $frozen = false;

	/** @var bool True while a bundle is being saved by sync(). */
	private static $syncing = false;

	public static function init(): void {
		add_action( 'woocommerce_before_product_object_save', array( __CLASS__, 'before_save' ), 20, 1 );
		add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'after_save' ), 20, 1 );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_child' ), 20, 1 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_child' ), 20, 1 );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'on_child_status' ), 20, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'on_child_status' ), 20, 3 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_status' ), 20, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ), 20, 1 );
		add_action( self::HOOK, array( __CLASS__, 'run_one' ), 10, 1 );
		add_action( self::HOOK_ALL, array( __CLASS__, 'run_batch' ), 10, 1 );
	}

	/* =====================================================================
	 * Contents
	 * =================================================================== */

	/**
	 * The stored rows of a bundle, normalized (invalid rows dropped).
	 *
	 * @param WC_Product $bundle Bundle.
	 * @return array[]
	 */
	public static function rows( WC_Product $bundle ): array {
		$raw  = $bundle->get_meta( self::META_ITEMS, true, 'edit' );
		$list = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : array() );
		return self::normalize_rows( is_array( $list ) ? $list : array() );
	}

	/**
	 * Shape-only validation of rows (ids and numbers, no lookups).
	 *
	 * @param array $list Raw rows.
	 * @return array[]
	 */
	public static function normalize_rows( array $list ): array {
		$out = array();
		foreach ( $list as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['p'] ) || ! is_numeric( $row['p'] ) ) {
				continue;
			}
			$p = (int) $row['p'];
			$q = isset( $row['q'] ) && is_numeric( $row['q'] ) ? (int) $row['q'] : 1;
			$m = isset( $row['m'] ) && 'choice' === $row['m'] ? 'choice' : 'fixed';
			if ( $p <= 0 || $q < 1 || $q > self::MAX_QTY ) {
				continue;
			}
			$v = array();
			if ( 'choice' === $m && isset( $row['v'] ) && is_array( $row['v'] ) ) {
				foreach ( $row['v'] as $id ) {
					if ( is_numeric( $id ) && (int) $id > 0 ) {
						$v[] = (int) $id;
					}
				}
				$v = array_slice( array_values( array_unique( $v ) ), 0, 200 );
			}
			$out[] = array(
				'p' => $p,
				'q' => $q,
				'm' => $m,
				'v' => $v,
			);
			if ( count( $out ) >= self::MAX_ROWS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Full validation of rows typed on the product screen: products that
	 * exist and can go in a bundle; a variable product becomes a choice, a
	 * variation or a simple product a fixed row.
	 *
	 * @param array $list Raw rows.
	 * @param int   $self Bundle id (a bundle cannot contain itself).
	 * @return array { rows: array[], dropped: int }
	 */
	public static function validate_rows( array $list, int $self ): array {
		$rows    = array();
		$dropped = 0;
		foreach ( self::normalize_rows( $list ) as $row ) {
			$p = wc_get_product( $row['p'] );
			if ( ! $p instanceof WC_Product || $row['p'] === $self || ! self::allowed_type( $p ) ) {
				++$dropped;
				continue;
			}
			if ( $p->is_type( 'variable' ) ) {
				$row['m'] = 'choice';
				$kids     = array_map( 'intval', $p->get_children() );
				$row['v'] = array_values( array_intersect( $row['v'], $kids ) );
				if ( count( $row['v'] ) === count( $kids ) ) {
					$row['v'] = array(); // All of them = empty (new variations join too).
				}
			} else {
				$row['m'] = 'fixed';
				$row['v'] = array();
				if ( $p->is_type( 'variation' ) && ! self::fully_specified( $p ) ) {
					++$dropped;
					continue;
				}
			}
			$rows[] = $row;
		}
		return array(
			'rows'    => $rows,
			'dropped' => $dropped,
		);
	}

	/**
	 * Product types that may go in a bundle: simple, variable (a choice),
	 * variation. Not bundles, grouped or external products.
	 *
	 * @param WC_Product $p Product.
	 */
	public static function allowed_type( WC_Product $p ): bool {
		return $p->is_type( array( 'simple', 'variable', 'variation' ) );
	}

	/**
	 * A variation with a value on every attribute (no "Any …"), so the
	 * cart line says exactly what was bought.
	 *
	 * @param WC_Product $v Variation.
	 */
	public static function fully_specified( WC_Product $v ): bool {
		if ( ! $v instanceof WC_Product_Variation ) {
			return false;
		}
		foreach ( $v->get_variation_attributes( false ) as $value ) {
			if ( '' === (string) $value ) {
				return false;
			}
		}
		return true;
	}

	/** @param WC_Product $bundle Bundle. */
	public static function has_choices( WC_Product $bundle ): bool {
		foreach ( self::rows( $bundle ) as $row ) {
			if ( 'choice' === $row['m'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A product by id, cached for the request (sync() flushes the cache).
	 *
	 * @param int $id Product id.
	 */
	public static function product( int $id ): ?WC_Product {
		if ( ! array_key_exists( $id, self::$products ) ) {
			$p                     = $id > 0 ? wc_get_product( $id ) : false;
			self::$products[ $id ] = $p instanceof WC_Product ? $p : false;
		}
		return self::$products[ $id ] ? self::$products[ $id ] : null;
	}

	public static function flush_products(): void {
		self::$products = array();
	}

	/**
	 * The variations a customer may pick in a choice row (published,
	 * priced, every attribute set), in menu order.
	 *
	 * @param array $row Row.
	 * @return WC_Product_Variation[] id => variation
	 */
	public static function choices( array $row ): array {
		$out    = array();
		$parent = self::product( (int) $row['p'] );
		if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
			return $out;
		}
		$ids = ! empty( $row['v'] ) ? array_values( array_intersect( array_map( 'intval', $parent->get_children() ), $row['v'] ) ) : array_map( 'intval', $parent->get_children() );
		foreach ( $ids as $id ) {
			$v = self::product( $id );
			if ( ! $v instanceof WC_Product_Variation || 'publish' !== $v->get_status() || '' === $v->get_price() || ! self::fully_specified( $v ) ) {
				continue;
			}
			$out[ $id ] = $v;
		}
		return $out;
	}

	/**
	 * The product a row resolves to: the fixed product, the customer's
	 * choice (when allowed), or null.
	 *
	 * @param array    $row    Row.
	 * @param int|null $choice Variation id for a choice row.
	 */
	public static function resolve( array $row, ?int $choice ): ?WC_Product {
		if ( 'fixed' === $row['m'] ) {
			$p = self::product( (int) $row['p'] );
			return ( $p && self::allowed_type( $p ) && ! $p->is_type( 'variable' ) ) ? $p : null;
		}
		if ( null === $choice ) {
			return null;
		}
		$choices = self::choices( $row );
		return isset( $choices[ $choice ] ) ? $choices[ $choice ] : null;
	}

	/* =====================================================================
	 * Prices
	 * =================================================================== */

	/**
	 * Price of a bundle and the share of every line.
	 *
	 * @param WC_Product $bundle  Bundle.
	 * @param array      $choices row index => variation id (choice rows).
	 * @param string     $pick    Without a choice: 'min' | 'max' (cheapest / dearest variation).
	 * @return array { ok, rows[ { row, product, qty, base, line, share, unit } ], value, price, display_value, display_price }
	 */
	public static function quote( WC_Product $bundle, array $choices = array(), string $pick = 'min' ): array {
		$rows = self::rows( $bundle );
		$out  = array(
			'ok'            => ! empty( $rows ),
			'rows'          => array(),
			'value'         => 0.0,
			'price'         => 0.0,
			'display_value' => 0.0,
			'display_price' => 0.0,
		);
		$dp   = wc_get_price_decimals();

		foreach ( $rows as $i => $row ) {
			$p = null;
			if ( 'fixed' === $row['m'] ) {
				$p = self::resolve( $row, null );
			} elseif ( isset( $choices[ $i ] ) ) {
				$p = self::resolve( $row, (int) $choices[ $i ] );
			} else {
				foreach ( self::choices( $row ) as $v ) {
					if ( null === $p
						|| ( 'min' === $pick && (float) $v->get_price() < (float) $p->get_price() )
						|| ( 'max' === $pick && (float) $v->get_price() > (float) $p->get_price() ) ) {
						$p = $v;
					}
				}
			}
			if ( ! $p || '' === $p->get_price() ) {
				$out['ok']     = false;
				$out['rows'][] = array(
					'row'     => $i,
					'product' => $p,
					'qty'     => $row['q'],
					'base'    => 0.0,
					'line'    => 0.0,
					'share'   => 0.0,
					'unit'    => 0.0,
				);
				continue;
			}
			$base          = (float) $p->get_price();
			$line          = $base * $row['q'];
			$out['value'] += $line;
			$out['rows'][] = array(
				'row'     => $i,
				'product' => $p,
				'qty'     => $row['q'],
				'base'    => $base,
				'line'    => $line,
				'share'   => 0.0,
				'unit'    => 0.0,
			);
		}

		$out['value'] = round( $out['value'], $dp );
		if ( ! $out['ok'] ) {
			return $out;
		}

		$out['price'] = self::target_price( $bundle, $out['value'], empty( $choices ) && 'min' === $pick );
		$shares       = self::allocate( $out['price'], array_column( $out['rows'], 'line' ) );
		foreach ( $out['rows'] as $k => $r ) {
			$out['rows'][ $k ]['share'] = $shares[ $k ];
			$out['rows'][ $k ]['unit']  = $r['qty'] > 0 ? $shares[ $k ] / $r['qty'] : 0.0;
			$out['display_value']      += (float) wc_get_price_to_display(
				$r['product'],
				array(
					'price' => $r['base'],
					'qty'   => $r['qty'],
				)
			);
			$out['display_price']      += (float) wc_get_price_to_display(
				$r['product'],
				array(
					'price' => $out['rows'][ $k ]['unit'],
					'qty'   => $r['qty'],
				)
			);
		}
		return $out;
	}

	/**
	 * Bundle price for a value of the contents.
	 *
	 * @param WC_Product $bundle   Bundle.
	 * @param float      $value    Value of the contents (V).
	 * @param bool       $is_min   True when $value is already the cheapest value.
	 */
	public static function target_price( WC_Product $bundle, float $value, bool $is_min ): float {
		$dp   = wc_get_price_decimals();
		$type = (string) $bundle->get_meta( self::META_DTYPE, true, 'edit' );
		$disc = $bundle->get_meta( self::META_DISC, true, 'edit' );
		$disc = is_numeric( $disc ) ? (float) $disc : 0.0;

		if ( 'price' === $type ) {
			$min   = $is_min ? $value : self::quote( $bundle, array(), 'min' )['value'];
			$price = max( 0.0, $disc ) + max( 0.0, $value - $min );
			return round( min( $value, $price ), $dp );
		}
		$disc = min( 99.0, max( 0.0, $disc ) );
		return round( $value * ( 100.0 - $disc ) / 100.0, $dp );
	}

	/**
	 * Splits $total over lines in proportion to their value, in cents; the
	 * remainder goes cent by cent to the largest fractions (ties: the
	 * larger line), so the parts add up to $total exactly.
	 *
	 * @param float   $total Amount to split.
	 * @param float[] $lines Line values.
	 * @return float[] Same keys as $lines.
	 */
	public static function allocate( float $total, array $lines ): array {
		$dp    = wc_get_price_decimals();
		$f     = pow( 10, $dp );
		$sum   = array_sum( $lines );
		$out   = array_fill_keys( array_keys( $lines ), 0.0 );
		$cents = (int) round( $total * $f );
		if ( $sum <= 0 || $cents <= 0 ) {
			return $out;
		}
		$floor = array();
		$frac  = array();
		foreach ( $lines as $k => $line ) {
			$raw         = $cents * $line / $sum;
			$floor[ $k ] = (int) floor( $raw + 1e-9 );
			$frac[ $k ]  = $raw - $floor[ $k ];
		}
		$left  = $cents - array_sum( $floor );
		$order = array_keys( $lines );
		usort(
			$order,
			static function ( $a, $b ) use ( $frac, $lines ) {
				if ( abs( $frac[ $a ] - $frac[ $b ] ) > 1e-9 ) {
					return $frac[ $b ] <=> $frac[ $a ];
				}
				return $lines[ $b ] <=> $lines[ $a ];
			}
		);
		foreach ( $order as $k ) {
			if ( $left <= 0 ) {
				break;
			}
			if ( $lines[ $k ] > 0 ) {
				++$floor[ $k ];
				--$left;
			}
		}
		foreach ( $floor as $k => $c ) {
			$out[ $k ] = $c / $f;
		}
		return $out;
	}

	/**
	 * True when a choice changes the price ("From …").
	 *
	 * @param WC_Product $bundle Bundle.
	 */
	public static function price_varies( WC_Product $bundle ): bool {
		$max = $bundle->get_meta( self::META_PMAX, true, 'edit' );
		return is_numeric( $max ) && (float) $max > (float) $bundle->get_price( 'edit' ) + 0.004;
	}

	/* =====================================================================
	 * Availability
	 * =================================================================== */

	/**
	 * Stock status of a bundle and how many can be sold (-1 = no limit).
	 *
	 * @param WC_Product $bundle Bundle.
	 * @return array { status: instock|outofstock|onbackorder, count: int }
	 */
	public static function availability( WC_Product $bundle ): array {
		$rows   = self::rows( $bundle );
		$count  = -1;
		$status = 'instock';
		if ( empty( $rows ) ) {
			return array(
				'status' => 'outofstock',
				'count'  => 0,
			);
		}
		foreach ( $rows as $row ) {
			$cands = 'fixed' === $row['m'] ? array_filter( array( self::resolve( $row, null ) ) ) : self::choices( $row );
			$best  = null; // null = none available, -1 = no limit.
			$back  = true; // Only on backorder?
			foreach ( $cands as $p ) {
				$n = self::units_available( $p, $row['q'] );
				if ( 0 === $n ) {
					continue;
				}
				if ( 'onbackorder' !== $p->get_stock_status() ) {
					$back = false;
				}
				if ( -1 === $n || -1 === $best ) {
					$best = -1;
				} else {
					$best = null === $best ? $n : max( $best, $n );
				}
			}
			if ( null === $best ) {
				return array(
					'status' => 'outofstock',
					'count'  => 0,
				);
			}
			if ( $back ) {
				$status = 'onbackorder';
			}
			if ( -1 !== $best ) {
				$count = -1 === $count ? $best : min( $count, $best );
			}
		}

		$max = (int) $bundle->get_meta( self::META_MAX, true, 'edit' );
		if ( $max > 0 ) {
			$left  = max( 0, $max - (int) $bundle->get_total_sales( 'edit' ) );
			$count = -1 === $count ? $left : min( $count, $left );
		}
		if ( 0 === $count ) {
			$status = 'outofstock';
		}
		return array(
			'status' => $status,
			'count'  => $count,
		);
	}

	/**
	 * Bundles a product allows: 0 = none, -1 = no limit (stock not tracked
	 * or backorders allowed), else floor(stock / quantity per bundle).
	 *
	 * @param WC_Product $p   Product inside.
	 * @param int        $qty Quantity per bundle.
	 */
	public static function units_available( WC_Product $p, int $qty ): int {
		if ( 'publish' !== $p->get_status() || '' === $p->get_price() || ! $p->is_in_stock() ) {
			return 0;
		}
		if ( $p->is_type( 'variation' ) ) {
			$parent = self::product( (int) $p->get_parent_id() );
			if ( ! $parent || 'publish' !== $parent->get_status() ) {
				return 0;
			}
		}
		if ( ! $p->managing_stock() || $p->backorders_allowed() ) {
			return -1;
		}
		return max( 0, (int) floor( (float) $p->get_stock_quantity() / max( 1, $qty ) ) );
	}

	/* =====================================================================
	 * Derived data
	 * =================================================================== */

	/**
	 * Before a bundle is saved: price and stock status from the contents.
	 *
	 * @param mixed $product Product being saved.
	 */
	public static function before_save( $product ): void {
		if ( ! $product instanceof PBN_Product || self::$frozen ) {
			return;
		}
		try {
			self::flush_products();
			$min = self::quote( $product, array(), 'min' );
			$max = self::quote( $product, array(), 'max' );
			$product->set_manage_stock( false );
			$product->set_date_on_sale_from( '' );
			$product->set_date_on_sale_to( '' );
			if ( ! $min['ok'] ) {
				$product->set_regular_price( '' );
				$product->set_sale_price( '' );
				$product->set_price( '' );
				$product->delete_meta_data( self::META_VMAX );
				$product->delete_meta_data( self::META_PMAX );
			} else {
				$dp = wc_get_price_decimals();
				$product->set_regular_price( wc_format_decimal( $min['value'], $dp ) );
				$product->set_sale_price( $min['price'] < $min['value'] - 0.004 ? wc_format_decimal( $min['price'], $dp ) : '' );
				$product->set_price( wc_format_decimal( $min['price'], $dp ) );
				if ( $max['ok'] && $max['price'] > $min['price'] + 0.004 ) {
					$product->update_meta_data( self::META_VMAX, wc_format_decimal( $max['value'], $dp ) );
					$product->update_meta_data( self::META_PMAX, wc_format_decimal( $max['price'], $dp ) );
				} else {
					$product->delete_meta_data( self::META_VMAX );
					$product->delete_meta_data( self::META_PMAX );
				}
			}
			// Weight = the sum of the contents (approved question 16); shipping
			// still weighs every product line on its own.
			$weight = 0.0;
			foreach ( $min['rows'] as $r ) {
				if ( $r['product'] instanceof WC_Product && $r['product']->has_weight() ) {
					$weight += (float) $r['product']->get_weight() * $r['qty'];
				}
			}
			$product->set_weight( $weight > 0 ? wc_format_decimal( $weight ) : '' );

			$a = self::availability( $product );
			$product->set_stock_status( $a['status'] );
			$product->update_meta_data( self::META_AVAIL, (string) $a['count'] );
		} catch ( \Throwable $e ) {
			// Derived data: the next save rebuilds it. Never block a save.
			return;
		}
	}

	/**
	 * After any product save: a bundle updates the index, a product inside
	 * queues its bundles.
	 *
	 * @param mixed $product Saved product.
	 */
	public static function after_save( $product ): void {
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		if ( $product instanceof PBN_Product ) {
			self::index_bundle( (int) $product->get_id(), 'trash' === $product->get_status() ? array() : self::rows( $product ) );
			return;
		}
		unset( self::$products[ (int) $product->get_id() ] );
		self::queue_for( (int) $product->get_id(), (int) $product->get_parent_id() );
	}

	/** @param mixed $product Product whose stock changed. */
	public static function on_child( $product ): void {
		if ( $product instanceof WC_Product && ! $product instanceof PBN_Product ) {
			unset( self::$products[ (int) $product->get_id() ] );
			self::queue_for( (int) $product->get_id(), (int) $product->get_parent_id() );
		}
	}

	/**
	 * @param mixed $id      Product id.
	 * @param mixed $status  New stock status.
	 * @param mixed $product Product.
	 */
	public static function on_child_status( $id, $status = '', $product = null ): void {
		if ( $product instanceof PBN_Product ) {
			return;
		}
		$parent = $product instanceof WC_Product ? (int) $product->get_parent_id() : (int) wp_get_post_parent_id( (int) $id );
		unset( self::$products[ (int) $id ] );
		self::queue_for( (int) $id, $parent );
	}

	/**
	 * A product (or bundle) is published, unpublished, trashed or restored.
	 *
	 * @param mixed $new  New status.
	 * @param mixed $old  Old status.
	 * @param mixed $post WP_Post.
	 */
	public static function on_status( $new, $old, $post ): void {
		if ( $new === $old || ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return;
		}
		$id = (int) $post->ID;
		unset( self::$products[ $id ] );
		if ( 'product' === $post->post_type && has_term( PBN_Product::TYPE, 'product_type', $id ) ) {
			if ( 'trash' === $new ) {
				self::index_bundle( $id, array() );
			} elseif ( 'trash' === $old ) {
				self::queue( $id );
			}
			return;
		}
		self::queue_for( $id, (int) $post->post_parent );
	}

	/** @param mixed $post_id Post being deleted for good. */
	public static function on_delete( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			return;
		}
		$id = (int) $post->ID;
		if ( 'product' === $post->post_type && has_term( PBN_Product::TYPE, 'product_type', $id ) ) {
			self::index_bundle( $id, array() );
			return;
		}
		self::queue_for( $id, (int) $post->post_parent );
	}

	/**
	 * Queues the bundles that contain a product (or its parent).
	 *
	 * @param int $id     Product id.
	 * @param int $parent Parent id (variations).
	 */
	private static function queue_for( int $id, int $parent ): void {
		$index = self::index();
		foreach ( array( $id, $parent ) as $k ) {
			if ( $k > 0 && isset( $index[ $k ] ) ) {
				foreach ( $index[ $k ] as $b ) {
					self::queue( (int) $b );
				}
			}
		}
	}

	public static function queue( int $id ): void {
		if ( $id <= 0 ) {
			return;
		}
		if ( empty( self::$queue ) ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_queue' ), 5 );
		}
		self::$queue[ $id ] = $id;
	}

	/** True when this request may rebuild directly (no visitor waits for it). */
	private static function direct(): bool {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return true;
		}
		return current_user_can( 'edit_products' );
	}

	public static function flush_queue(): void {
		$ids         = self::$queue;
		self::$queue = array();
		if ( empty( $ids ) ) {
			return;
		}
		if ( self::direct() ) {
			self::sync_many( array_values( $ids ) );
			return;
		}
		foreach ( $ids as $id ) {
			self::enqueue( (int) $id );
		}
	}

	/** One Action Scheduler job per bundle (not twice). */
	public static function enqueue( int $id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		$args = array( $id );
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return;
		}
		as_enqueue_async_action( self::HOOK, $args, self::GROUP );
	}

	/** Rebuilds every bundle in the background, in batches. */
	public static function enqueue_all(): bool {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_ALL, null, self::GROUP );
		}
		as_enqueue_async_action( self::HOOK_ALL, array( 0 ), self::GROUP );
		return true;
	}

	/** @param mixed $id Bundle id (Action Scheduler). */
	public static function run_one( $id ): void {
		self::sync_many( array( (int) $id ) );
	}

	/** @param mixed $after Last id of the previous batch (Action Scheduler). */
	public static function run_batch( $after ): void {
		$ids = self::bundle_ids( (int) $after, self::BATCH );
		self::sync_many( $ids );
		if ( count( $ids ) === self::BATCH && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_ALL, array( (int) end( $ids ) ), self::GROUP );
		}
	}

	/**
	 * Rebuilds bundles now and tells the suite (noxpress_products_changed).
	 *
	 * @param int[] $ids Bundle ids.
	 */
	public static function sync_many( array $ids ): void {
		$done = array();
		foreach ( $ids as $id ) {
			if ( self::sync( (int) $id ) ) {
				$done[] = (int) $id;
			}
		}
		if ( $done ) {
			/** Bible §6: product data changed (prices, stock status of bundles). */
			do_action( 'noxpress_products_changed', $done );
		}
	}

	/**
	 * Rebuilds one bundle: before_save() does the work.
	 *
	 * @param int $id Bundle id.
	 */
	public static function sync( int $id ): bool {
		if ( self::$syncing ) {
			return false;
		}
		try {
			self::flush_products();
			$b = wc_get_product( $id );
			if ( ! $b instanceof PBN_Product || 'trash' === $b->get_status() ) {
				return false;
			}
			self::$syncing = true;
			$b->save();
			self::$syncing = false;
			return true;
		} catch ( \Throwable $e ) {
			self::$syncing = false;
			return false;
		}
	}

	/**
	 * Bundle ids after $after (any status but trash), ascending.
	 *
	 * @param int $after Id to start after.
	 * @param int $limit Max ids (0 = all).
	 * @return int[]
	 */
	public static function bundle_ids( int $after = 0, int $limit = 0 ): array {
		global $wpdb;
		$term = get_term_by( 'slug', PBN_Product::TYPE, 'product_type' );
		if ( ! $term ) {
			return array();
		}
		$sql = "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			WHERE tr.term_taxonomy_id = %d AND p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft') AND p.ID > %d ORDER BY p.ID ASC";
		$args = array( (int) $term->term_taxonomy_id, $after );
		if ( $limit > 0 ) {
			$sql   .= ' LIMIT %d';
			$args[] = $limit;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- prepared with placeholders above.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
	}

	/* =====================================================================
	 * Reverse index
	 * =================================================================== */

	/** product id => bundle ids. */
	public static function index(): array {
		$raw = get_option( self::OPT_INDEX, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Puts one bundle in the index with its rows (empty = removes it).
	 *
	 * @param int   $bundle Bundle id.
	 * @param array $rows   Rows.
	 */
	public static function index_bundle( int $bundle, array $rows ): void {
		$index = self::index();
		foreach ( $index as $pid => $list ) {
			$list = array_values( array_diff( array_map( 'intval', (array) $list ), array( $bundle ) ) );
			if ( $list ) {
				$index[ $pid ] = $list;
			} else {
				unset( $index[ $pid ] );
			}
		}
		foreach ( $rows as $row ) {
			$pid = (int) $row['p'];
			if ( ! isset( $index[ $pid ] ) ) {
				$index[ $pid ] = array();
			}
			if ( ! in_array( $bundle, $index[ $pid ], true ) ) {
				$index[ $pid ][] = $bundle;
			}
		}
		update_option( self::OPT_INDEX, $index, false );
	}

	/** Rebuilds the whole index from the bundles. */
	public static function rebuild_index(): void {
		$index = array();
		foreach ( self::bundle_ids() as $id ) {
			$b = wc_get_product( $id );
			if ( ! $b instanceof PBN_Product ) {
				continue;
			}
			foreach ( self::rows( $b ) as $row ) {
				$index[ (int) $row['p'] ][] = $id;
			}
		}
		foreach ( $index as $pid => $list ) {
			$index[ $pid ] = array_values( array_unique( $list ) );
		}
		update_option( self::OPT_INDEX, $index, false );
	}

	/**
	 * Bundles that contain a product (directly or as a variation of it).
	 *
	 * @param int $product_id Product id.
	 * @return int[]
	 */
	public static function bundles_of( int $product_id ): array {
		$index = self::index();
		$out   = isset( $index[ $product_id ] ) ? (array) $index[ $product_id ] : array();
		$p     = self::product( $product_id );
		if ( $p && $p->get_parent_id() && isset( $index[ $p->get_parent_id() ] ) ) {
			$out = array_merge( $out, (array) $index[ $p->get_parent_id() ] );
		}
		return array_values( array_unique( array_map( 'intval', $out ) ) );
	}

	/* =====================================================================
	 * Deactivation / activation (approved design, question 12)
	 * =================================================================== */

	/**
	 * Deactivation: every bundle becomes "Out of stock", so WooCommerce
	 * (which then loads bundles as simple products) never sells one
	 * without its contents. Stock status is derived data: activation
	 * rebuilds it.
	 */
	public static function freeze_all(): void {
		self::$frozen = true;
		foreach ( self::bundle_ids() as $id ) {
			try {
				$b = wc_get_product( $id );
				if ( $b instanceof WC_Product ) {
					$b->set_stock_status( 'outofstock' );
					$b->save();
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}
		self::$frozen = false;
	}
}

<?php
/**
 * DSR_Engine — the calculation. One code path for the shop pages, the
 * cart, the test cart of the admin and the abilities, so what a visitor
 * sees and what the cart charges never disagree.
 *
 * Input: lines { key => { product: WC_Product, qty: int, price: float } },
 * where price is the unit price before any rule (as stored: with or
 * without tax like the store's prices, WooCommerce's own sale included),
 * and a context { ts, logged_in, subtotal } (subtotal: the whole cart at
 * those prices, bundle lines included).
 *
 * Steps (approved design, 2026-11-16):
 *  1. The line rules that are active now for this visitor (Α sale,
 *     Β tiers, Γ buy X pay Y), each with the lines in its scope.
 *  2. Best rule wins, one rule per line: the rule that gives the biggest
 *     discount on the lines still free takes them; the rest are worked out
 *     again without those lines, until no rule gives anything. A rule's
 *     result is compared with the line's own price, so a lower WooCommerce
 *     sale price wins over a rule.
 *  3. Category limits (dsr_settings cat_max): a line of a limited category
 *     never pays less than its regular price minus the limit.
 *  4. Order cap: all rule discounts together stay within cap % of the
 *     subtotal (they are scaled down together when they would not).
 *  5. The cart rule (Δ), the best one that applies to the cart after the
 *     line discounts, within what is left of the cap; lines of limited
 *     categories and bundle lines take no part in it.
 *  6. Hints: a line one unit away from the next tier or from a free item.
 *
 * Every amount is rounded to the store's decimals only at the end; no line
 * ever goes below 0.
 */

defined( 'ABSPATH' ) || exit;

final class DSR_Engine {

	/** @var array Per-request caches. */
	private static $cats      = array();
	private static $eligible  = array();
	private static $sale      = array();
	private static $rules_for = array();

	/** Drops the per-request caches (tests, after a rule changes). */
	public static function flush(): void {
		self::$cats      = array();
		self::$eligible  = array();
		self::$sale      = array();
		self::$rules_for = array();
	}

	/* =====================================================================
	 * Who and when
	 * =================================================================== */

	/** True when the current user is a trade account (Trade Accounts): never any rule. */
	public static function is_trade(): bool {
		return function_exists( 'tra_is_trade' ) && tra_is_trade();
	}

	/** True when the current user gets discounts now (mode, test mode, kill switch, trade). */
	public static function on_for_user(): bool {
		return DSR_Settings::visible() && ! self::is_trade();
	}

	/** The context of the current request. */
	public static function context( float $subtotal = 0.0 ): array {
		return array(
			'ts'        => time(),
			'logged_in' => is_user_logged_in(),
			'subtotal'  => $subtotal,
		);
	}

	/**
	 * The rules of some types that are active at the context's time for its visitor.
	 *
	 * @param string[] $types Rule types.
	 * @param array    $ctx   Context.
	 * @return array[] Rules, by id.
	 */
	public static function active( array $types, array $ctx ): array {
		$key = implode( ',', $types ) . '|' . (int) floor( (int) $ctx['ts'] / 60 ) . '|' . ( $ctx['logged_in'] ? 1 : 0 );
		if ( isset( self::$rules_for[ $key ] ) ) {
			return self::$rules_for[ $key ];
		}
		$out = array();
		foreach ( DSR_Rules::all() as $id => $r ) {
			if ( ! in_array( $r['type'], $types, true ) || 'active' !== DSR_Rules::status( $r, (int) $ctx['ts'] ) ) {
				continue;
			}
			if ( ( 'guests' === $r['who'] && $ctx['logged_in'] ) || ( 'customers' === $r['who'] && ! $ctx['logged_in'] ) ) {
				continue;
			}
			$out[ $id ] = $r;
		}
		self::$rules_for[ $key ] = $out;
		return $out;
	}

	/* =====================================================================
	 * Scope
	 * =================================================================== */

	/** The product whose terms count: the parent of a variation. */
	public static function main_id( WC_Product $p ): int {
		return $p->is_type( 'variation' ) ? (int) $p->get_parent_id() : (int) $p->get_id();
	}

	/**
	 * The categories of a product with all their parents (a rule or a limit
	 * on «Βιβλία» covers «Ποίηση» under it).
	 *
	 * @param int $main Product id (not a variation).
	 * @return int[]
	 */
	public static function cat_ids( int $main ): array {
		if ( ! isset( self::$cats[ $main ] ) ) {
			$ids = array();
			foreach ( wc_get_product_term_ids( $main, 'product_cat' ) as $t ) {
				$ids[ (int) $t ] = (int) $t;
				foreach ( get_ancestors( (int) $t, 'product_cat', 'taxonomy' ) as $a ) {
					$ids[ (int) $a ] = (int) $a;
				}
			}
			self::$cats[ $main ] = array_values( $ids );
		}
		return self::$cats[ $main ];
	}

	/**
	 * True when a product may get any rule at all: not another publisher's
	 * title, not a pre-order (when so set), not excluded by code.
	 *
	 * @param WC_Product $p Product or variation.
	 */
	public static function eligible( WC_Product $p ): bool {
		$id = (int) $p->get_id();
		if ( isset( self::$eligible[ $id ] ) ) {
			return self::$eligible[ $id ];
		}
		$main = self::main_id( $p );
		$s    = DSR_Settings::get();
		$ok   = true;
		// Gift cards are stored value, never a sale (Gift Cards, Bible §14.43).
		if ( function_exists( 'gfc_is_gift_card_product' ) && ( gfc_is_gift_card_product( $p ) || ( $main !== $id && gfc_is_gift_card_product( $main ) ) ) ) {
			$ok = false;
		}
		if ( $ok && 'yes' === $s['ex_others'] ) {
			if ( function_exists( 'bkf_get_source' ) && null !== bkf_get_source( $main ) ) {
				$ok = false;
			}
			if ( $ok && function_exists( 'tfi_get_source' ) && null !== tfi_get_source( $main ) ) {
				$ok = false;
			}
		}
		if ( $ok && 'yes' === $s['ex_preorder'] && function_exists( 'por_get_release' ) && null !== por_get_release( $p ) ) {
			$ok = false;
		}
		/**
		 * Filters whether a product may get discount rules at all (e.g. false
		 * for gift cards).
		 *
		 * @param bool       $ok      Eligible.
		 * @param WC_Product $product Product or variation.
		 */
		$ok                     = (bool) apply_filters( 'dsr_eligible', $ok, $p );
		self::$eligible[ $id ] = $ok;
		return $ok;
	}

	/**
	 * True when a rule covers a product.
	 *
	 * @param array      $r Rule.
	 * @param WC_Product $p Product or variation.
	 */
	public static function in_scope( array $r, WC_Product $p ): bool {
		if ( ! self::eligible( $p ) ) {
			return false;
		}
		$main = self::main_id( $p );
		$ids  = array_unique( array( (int) $p->get_id(), $main ) );
		if ( array_intersect( $ids, $r['ex_products'] ) ) {
			return false;
		}
		$cats = self::cat_ids( $main );
		if ( ! empty( $r['ex_cats'] ) && array_intersect( $cats, $r['ex_cats'] ) ) {
			return false;
		}
		if ( 'yes' === $r['all'] ) {
			return true;
		}
		if ( array_intersect( $ids, $r['products'] ) ) {
			return true;
		}
		if ( ! empty( $r['cats'] ) && array_intersect( $cats, $r['cats'] ) ) {
			return true;
		}
		if ( ! empty( $r['tags'] ) && array_intersect( array_map( 'intval', wc_get_product_term_ids( $main, 'product_tag' ) ), $r['tags'] ) ) {
			return true;
		}
		if ( ! empty( $r['brands'] ) && taxonomy_exists( 'product_brand' ) && array_intersect( array_map( 'intval', wc_get_product_term_ids( $main, 'product_brand' ) ), $r['brands'] ) ) {
			return true;
		}
		return false;
	}

	/** The lowest category limit of a product in percent, or null (no limit). */
	public static function cat_limit( WC_Product $p ): ?float {
		$max = DSR_Settings::get()['cat_max'];
		if ( empty( $max ) ) {
			return null;
		}
		$lim = null;
		foreach ( self::cat_ids( self::main_id( $p ) ) as $t ) {
			if ( isset( $max[ $t ] ) ) {
				$lim = null === $lim ? (float) $max[ $t ] : min( $lim, (float) $max[ $t ] );
			}
		}
		return $lim;
	}

	/** The regular unit price (as stored), or the given price when there is none. */
	public static function regular( WC_Product $p, float $price ): float {
		$reg = (float) $p->get_regular_price( 'edit' );
		return $reg > 0 ? max( $reg, $price ) : $price;
	}

	/* =====================================================================
	 * The calculation
	 * =================================================================== */

	/**
	 * Unit price after a per-unit discount.
	 *
	 * @param float  $reg    Regular unit price.
	 * @param string $kind   percent | fixed.
	 * @param float  $amount Amount.
	 */
	private static function off( float $reg, string $kind, float $amount ): float {
		if ( 'percent' === $kind ) {
			return max( 0.0, $reg * ( 1 - min( 100.0, $amount ) / 100 ) );
		}
		return max( 0.0, $reg - $amount );
	}

	/**
	 * What one line rule gives on the lines it may take: key => new line total.
	 * Lines it does not lower are left out.
	 *
	 * @param array $r     Rule (sale, tiers, xy).
	 * @param array $lines key => { product, qty, price, reg }.
	 * @return array key => float
	 */
	private static function evaluate( array $r, array $lines ): array {
		$mine = array();
		foreach ( $lines as $k => $l ) {
			if ( self::in_scope( $r, $l['product'] ) ) {
				$mine[ $k ] = $l;
			}
		}
		if ( empty( $mine ) ) {
			return array();
		}
		$out = array();

		if ( 'sale' === $r['type'] ) {
			foreach ( $mine as $k => $l ) {
				$out[ $k ] = self::off( $l['reg'], $r['kind'], $r['amount'] ) * $l['qty'];
			}
		} elseif ( 'tiers' === $r['type'] ) {
			foreach ( self::groups( $r, $mine ) as $keys ) {
				$n = 0;
				foreach ( $keys as $k ) {
					$n += $mine[ $k ]['qty'];
				}
				$tier = null;
				foreach ( $r['tiers'] as $t ) {
					if ( $n >= $t['min'] ) {
						$tier = $t;
					}
				}
				if ( null === $tier ) {
					continue;
				}
				foreach ( $keys as $k ) {
					$out[ $k ] = self::off( $mine[ $k ]['reg'], $tier['kind'], $tier['amount'] ) * $mine[ $k ]['qty'];
				}
			}
		} elseif ( 'xy' === $r['type'] ) {
			foreach ( self::groups( $r, $mine ) as $keys ) {
				$n = 0;
				foreach ( $keys as $k ) {
					$n += $mine[ $k ]['qty'];
				}
				$free = intdiv( $n, $r['buy'] ) * ( $r['buy'] - $r['pay'] );
				if ( $free <= 0 ) {
					continue;
				}
				// The cheapest units are free (at the price the customer pays).
				usort(
					$keys,
					static function ( $a, $b ) use ( $mine ) {
						$c = $mine[ $a ]['price'] <=> $mine[ $b ]['price'];
						return 0 !== $c ? $c : strcmp( (string) $a, (string) $b );
					}
				);
				foreach ( $keys as $k ) {
					if ( $free <= 0 ) {
						break;
					}
					$take      = min( $free, $mine[ $k ]['qty'] );
					$free     -= $take;
					$out[ $k ] = $mine[ $k ]['price'] * ( $mine[ $k ]['qty'] - $take );
				}
			}
		}

		// Only what actually lowers the line (a lower sale price wins).
		foreach ( $out as $k => $total ) {
			if ( $total >= $lines[ $k ]['price'] * $lines[ $k ]['qty'] - 0.000001 ) {
				unset( $out[ $k ] );
			}
		}
		return $out;
	}

	/**
	 * The lines a tiers / xy rule counts together: all of them ('set') or
	 * one group per product, variations together ('product').
	 *
	 * @param array $r    Rule.
	 * @param array $mine key => line.
	 * @return array[] lists of keys
	 */
	private static function groups( array $r, array $mine ): array {
		if ( 'set' === $r['count'] ) {
			return array( array_keys( $mine ) );
		}
		$g = array();
		foreach ( $mine as $k => $l ) {
			$g[ self::main_id( $l['product'] ) ][] = $k;
		}
		return array_values( $g );
	}

	/**
	 * The whole calculation for a cart (or a test cart).
	 *
	 * @param array $lines key => { product: WC_Product, qty: int, price: float }.
	 * @param array $ctx   { ts, logged_in, subtotal }.
	 * @return array {
	 *   lines:  key => { rule, name, price (before), unit (after), discount },
	 *   cart:   null | { rule, name, amount, keys: string[] },
	 *   hints:  [ { rule, name, n } ],
	 *   capped: bool, total: float (all discounts)
	 * }
	 */
	public static function compute( array $lines, array $ctx ): array {
		$dp  = wc_get_price_decimals();
		$res = array(
			'lines'  => array(),
			'cart'   => null,
			'hints'  => array(),
			'capped' => false,
			'total'  => 0.0,
		);

		$work = array();
		foreach ( $lines as $k => $l ) {
			if ( ! isset( $l['product'] ) || ! $l['product'] instanceof WC_Product ) {
				continue;
			}
			$qty   = (int) $l['qty'];
			$price = (float) $l['price'];
			if ( $qty <= 0 || $price <= 0 ) {
				continue;
			}
			$work[ (string) $k ] = array(
				'product' => $l['product'],
				'qty'     => $qty,
				'price'   => $price,
				'reg'     => self::regular( $l['product'], $price ),
			);
		}
		$subtotal = (float) $ctx['subtotal'];
		$rules    = self::active( array( 'sale', 'tiers', 'xy' ), $ctx );

		// 1–2. Best rule wins, one rule per line.
		$free      = $work;
		$remaining = array();
		foreach ( $rules as $id => $r ) {
			if ( $r['min'] > 0 && $subtotal + 0.000001 < $r['min'] ) {
				continue;
			}
			$remaining[ $id ] = $r;
		}
		$taken = array();
		while ( ! empty( $remaining ) && ! empty( $free ) ) {
			$best_id = 0;
			$best    = array();
			$gain    = 0.0;
			foreach ( $remaining as $id => $r ) {
				$totals = self::evaluate( $r, $free );
				$g      = 0.0;
				foreach ( $totals as $k => $t ) {
					$g += $free[ $k ]['price'] * $free[ $k ]['qty'] - $t;
				}
				if ( $g > $gain + 0.000001 || ( abs( $g - $gain ) <= 0.000001 && $g > 0 && $id < $best_id ) ) {
					$gain    = $g;
					$best_id = $id;
					$best    = $totals;
				}
			}
			if ( $best_id <= 0 ) {
				break;
			}
			foreach ( $best as $k => $t ) {
				$taken[ $k ] = array(
					'rule'  => $best_id,
					'total' => $t,
				);
				unset( $free[ $k ] );
			}
			unset( $remaining[ $best_id ] );
		}

		// 3. Category limits, then the per-line filter.
		$disc = array();
		foreach ( $taken as $k => $t ) {
			$l     = $work[ $k ];
			$total = max( 0.0, $t['total'] );
			$lim   = self::cat_limit( $l['product'] );
			if ( null !== $lim ) {
				$total = max( $total, min( $l['price'], $l['reg'] * ( 1 - $lim / 100 ) ) * $l['qty'] );
			}
			$d = $l['price'] * $l['qty'] - $total;
			/**
			 * Filters the discount of one cart line (whole line, as stored), the
			 * last word on it. 0 = no discount on this line.
			 *
			 * @param float      $discount Discount.
			 * @param int        $rule_id  Rule.
			 * @param WC_Product $product  Product.
			 * @param int        $qty      Quantity.
			 * @param float      $price    Unit price before the rule.
			 */
			$d = (float) apply_filters( 'dsr_line_discount', $d, $t['rule'], $l['product'], $l['qty'], $l['price'] );
			$d = min( max( 0.0, $d ), $l['price'] * $l['qty'] );
			if ( $d > 0.000001 ) {
				$disc[ $k ] = $d;
			} else {
				unset( $taken[ $k ] );
			}
		}

		// 4. Order cap.
		$cap   = (int) DSR_Settings::get()['cap'] / 100 * max( 0.0, $subtotal );
		$lsum  = array_sum( $disc );
		if ( $lsum > $cap + 0.000001 ) {
			$f = $lsum > 0 ? $cap / $lsum : 0.0;
			foreach ( $disc as $k => $d ) {
				$disc[ $k ] = $d * $f;
			}
			$lsum          = array_sum( $disc );
			$res['capped'] = true;
		}

		foreach ( $disc as $k => $d ) {
			$l    = $work[ $k ];
			$d    = round( $d, $dp + 2 );
			$unit = max( 0.0, ( $l['price'] * $l['qty'] - $d ) / $l['qty'] );
			$r    = DSR_Rules::get( $taken[ $k ]['rule'] );
			$res['lines'][ $k ] = array(
				'rule'     => $taken[ $k ]['rule'],
				'type'     => $r ? $r['type'] : '',
				'name'     => $r ? DSR_Rules::label( $r ) : '',
				'price'    => $l['price'],
				'unit'     => $unit,
				'discount' => $d,
			);
		}

		// 5. The cart rule.
		$room = max( 0.0, $cap - $lsum );
		if ( $room > 0.000001 ) {
			$after = $subtotal - $lsum;
			$best  = null;
			foreach ( self::active( array( 'cart' ), $ctx ) as $id => $r ) {
				if ( $r['min'] > 0 && $after + 0.000001 < $r['min'] ) {
					continue;
				}
				$base = 0.0;
				$keys = array();
				foreach ( $work as $k => $l ) {
					if ( null !== self::cat_limit( $l['product'] ) || ! self::in_scope( $r, $l['product'] ) ) {
						continue;
					}
					$base  += isset( $res['lines'][ $k ] ) ? $res['lines'][ $k ]['unit'] * $l['qty'] : $l['price'] * $l['qty'];
					$keys[] = $k;
				}
				if ( $base <= 0 ) {
					continue;
				}
				$amount = 'percent' === $r['kind'] ? $base * min( 100.0, $r['amount'] ) / 100 : min( $r['amount'], $base );
				$amount = min( $amount, $room );
				if ( $amount > 0.000001 && ( null === $best || $amount > $best['amount'] + 0.000001 ) ) {
					$best = array(
						'rule'   => $id,
						'name'   => '' !== DSR_Rules::label( $r ) ? DSR_Rules::label( $r ) : $r['name'],
						'amount' => round( $amount, $dp ),
						'keys'   => $keys,
					);
				}
			}
			if ( null !== $best && $best['amount'] > 0 ) {
				$res['cart'] = $best;
				if ( $best['amount'] >= $room - 0.000001 && $room < $cap ) {
					$res['capped'] = true;
				}
			}
		}

		$res['total'] = round( $lsum + ( $res['cart'] ? $res['cart']['amount'] : 0.0 ), $dp );

		// 6. Hints: one unit away from the next tier or a free item.
		foreach ( $rules as $id => $r ) {
			if ( ! in_array( $r['type'], array( 'tiers', 'xy' ), true ) || ( $r['min'] > 0 && $subtotal + 0.000001 < $r['min'] ) ) {
				continue;
			}
			$mine = array();
			foreach ( $work as $k => $l ) {
				if ( self::in_scope( $r, $l['product'] ) ) {
					$mine[ $k ] = $l;
				}
			}
			foreach ( self::groups( $r, $mine ) as $keys ) {
				$n = 0;
				foreach ( $keys as $k ) {
					$n += $mine[ $k ]['qty'];
				}
				if ( $n <= 0 ) {
					continue;
				}
				$need = 0;
				if ( 'xy' === $r['type'] ) {
					$need = $r['buy'] - ( $n % $r['buy'] );
				} else {
					foreach ( $r['tiers'] as $t ) {
						if ( $t['min'] > $n ) {
							$need = $t['min'] - $n;
							break;
						}
					}
				}
				if ( 1 !== $need ) {
					continue;
				}
				// Only when one more unit would make this rule worth more on these
				// lines than what they get now (another rule may already be better).
				$now = 0.0;
				$hyp = array();
				foreach ( $keys as $k ) {
					$hyp[ $k ] = $mine[ $k ];
					if ( isset( $disc[ $k ] ) ) {
						$now += $disc[ $k ];
					}
				}
				$first = reset( $keys );
				++$hyp[ $first ]['qty'];
				$gain = 0.0;
				foreach ( self::evaluate( $r, $hyp ) as $k => $t ) {
					$gain += $hyp[ $k ]['price'] * $hyp[ $k ]['qty'] - $t;
				}
				if ( $gain > $now + 0.005 ) {
					$res['hints'][ $id ] = array(
						'rule' => $id,
						'name' => '' !== DSR_Rules::label( $r ) ? DSR_Rules::label( $r ) : $r['name'],
						'n'    => 1,
					);
				}
			}
		}
		$res['hints'] = array_values( $res['hints'] );

		return $res;
	}

	/* =====================================================================
	 * One product (shop pages, feeds)
	 * =================================================================== */

	/**
	 * The «Προσφορά» (sale) rule price of one product for a visitor, with no
	 * cart: the best active sale rule without a cart minimum, after the
	 * category limit, only when it is lower than the product's own price.
	 *
	 * @param WC_Product $p   Simple product or variation.
	 * @param array|null $ctx Context (null = the current visitor now).
	 * @return array|null { price, regular, before, rule, name, to, percent }
	 */
	public static function sale_for( WC_Product $p, ?array $ctx = null ): ?array {
		$ctx = null === $ctx ? self::context() : $ctx;
		$key = $p->get_id() . '|' . ( $ctx['logged_in'] ? 1 : 0 ) . '|' . (int) floor( (int) $ctx['ts'] / 60 );
		if ( array_key_exists( $key, self::$sale ) ) {
			return self::$sale[ $key ];
		}
		self::$sale[ $key ] = null;
		if ( $p->is_type( 'variable' ) || $p->is_type( 'grouped' ) || $p->is_type( 'external' ) || $p->is_type( 'nox_bundle' ) ) {
			return null;
		}
		$price = (float) $p->get_price( 'edit' );
		if ( $price <= 0 ) {
			return null;
		}
		$reg  = self::regular( $p, $price );
		$best = null;
		foreach ( self::active( array( 'sale' ), $ctx ) as $id => $r ) {
			if ( $r['min'] > 0 || ! self::in_scope( $r, $p ) ) {
				continue;
			}
			$new = self::off( $reg, $r['kind'], $r['amount'] );
			if ( null === $best || $new < $best['price'] - 0.000001 ) {
				$best = array(
					'price' => $new,
					'rule'  => $r,
				);
			}
		}
		if ( null === $best ) {
			return null;
		}
		$new = $best['price'];
		$lim = self::cat_limit( $p );
		if ( null !== $lim ) {
			$new = max( $new, $reg * ( 1 - $lim / 100 ) );
		}
		$new = round( $new, wc_get_price_decimals() );
		if ( $new >= $price - 0.000001 ) {
			return null;
		}
		$r                  = $best['rule'];
		self::$sale[ $key ] = array(
			'price'   => $new,
			'regular' => $reg,
			'before'  => $price,
			'rule'    => (int) $r['id'],
			'name'    => DSR_Rules::label( $r ),
			'to'      => $r['to'],
			'percent' => $reg > 0 ? round( 100 * ( $reg - $new ) / $reg ) : 0,
			'start'   => '' !== $r['from'] ? DSR_Rules::ts( $r['from'] ) : (int) $r['since'],
		);
		return self::$sale[ $key ];
	}

	/** dsr_get_price(): the sale rule price for guests now (feeds, schema). */
	public static function public_price( WC_Product $p ): ?array {
		if ( Discount_Rules::killed() || 'live' !== DSR_Settings::get()['mode'] ) {
			return null;
		}
		$ctx = array(
			'ts'        => time(),
			'logged_in' => false,
			'subtotal'  => 0.0,
		);
		$s   = self::sale_for( $p, $ctx );
		if ( null === $s ) {
			return null;
		}
		return array(
			'price'   => $s['price'],
			'regular' => $s['regular'],
			'rule'    => $s['rule'],
			'name'    => $s['name'],
			'to'      => $s['to'],
		);
	}

	/**
	 * The tiers and buy-X-pay-Y rules that cover a product now, for the
	 * product page (table, label).
	 *
	 * @param WC_Product $p Product (variable parents count by their terms).
	 * @return array[] rules
	 */
	public static function qty_rules_for( WC_Product $p ): array {
		$out = array();
		foreach ( self::active( array( 'tiers', 'xy' ), self::context() ) as $id => $r ) {
			if ( self::in_scope( $r, $p ) ) {
				$out[ $id ] = $r;
			}
		}
		return $out;
	}

	/**
	 * Unit price after a tier, for the product page table.
	 *
	 * @param float $reg  Regular price.
	 * @param array $tier Tier.
	 */
	public static function tier_price( float $reg, array $tier ): float {
		return self::off( $reg, $tier['kind'], $tier['amount'] );
	}
}

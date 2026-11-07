<?php
/**
 * PL_Report — the computed report: per product, then the general ledger.
 *
 * Per product (all amounts net of VAT, in the shop currency):
 *  - retail / trade      line totals excl. tax of paid orders (processing,
 *                        completed) minus refunds; trade = orders with the
 *                        Trade Accounts flag _tra_trade (Bible §6)
 *  - offsite             «Έσοδα εκτός site» entries
 *  - creators_rs         creator payouts from Revenue Splitter
 *                        (RS_Reports::run splits, minus the publisher's
 *                        own names in the settings)
 *  - costs[category]     direct entries of the product
 *  - shipping_charged    what customers paid for shipping (excl. tax),
 *                        spread over an order's products by their share
 *  - shipping_cost       the carrier's pooled invoice for the month, an
 *                        equal share per parcel (order) of that carrier
 *  - consumables         the month's consumables, an equal share per
 *                        shipped order
 *  - payment_fees        a provider's fees for the month, by order value
 *                        over the orders paid through it
 *  - net                 revenue + shipping charged − everything above
 *  - break_even          units still to sell to reach 0 since the start
 *                        date, at the average net per unit after creator
 *                        payouts (null when already ≥ 0 or nothing sold)
 *
 * Orders are read with wc_get_orders, by date_created in local-day
 * timestamps (Bible §1.3), the same window Revenue Splitter uses, so the
 * creator payouts match its own report. Refunds are netted out per line
 * (_refunded_item_id); an amount-only refund is spread over the lines in
 * proportion, as RS_Reports::collect_refunds() does.
 *
 * A pool that finds no order in its month is not lost: it is reported as
 * «unallocated» and counted with the general expenses.
 *
 * The result is cached for 5 minutes (transient, key with a version that
 * entries, settings, orders and RS bump).
 */

defined( 'ABSPATH' ) || exit;

final class PL_Report {

	const TTL      = 300;
	const STATUSES = array( 'wc-completed', 'wc-processing' );
	const VER      = 'pl_cache_ver';

	public static function init(): void {
		add_action( 'rs_invalidate_cache', array( __CLASS__, 'bump' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'bump' ) );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'bump' ) );
		add_action( 'woocommerce_refund_deleted', array( __CLASS__, 'bump' ) );
	}

	/** Invalidates every cached report. */
	public static function bump(): void {
		update_option( self::VER, (string) microtime( true ), false );
	}

	/**
	 * The report for a period (cached).
	 *
	 * @return array|WP_Error
	 */
	public static function get( string $from, string $to, int $product_id = 0 ) {
		if ( ! PL_Settings::valid_date( $from ) || ! PL_Settings::valid_date( $to ) || $from > $to ) {
			return new WP_Error( 'pl_period', 'from and to must be YYYY-MM-DD with from ≤ to.' );
		}
		$start = PL_Settings::start();
		$key   = 'pl_rep_' . md5( $from . '|' . $to . '|' . $start . '|' . get_option( self::VER, '0' ) );
		$rep   = get_transient( $key );
		if ( ! is_array( $rep ) ) {
			$rep = self::compute( max( $from, $start ), $to, true );
			unset( $rep['by_id'] );
			$rep['period']['asked'] = array( $from, $to );
			set_transient( $key, $rep, self::TTL );
		}
		return $product_id > 0 ? self::only( $rep, $product_id ) : $rep;
	}

	/** The report kept to one product (totals stay the shop's). */
	private static function only( array $rep, int $pid ): array {
		$rep['products'] = array_values(
			array_filter(
				$rep['products'],
				static function ( $p ) use ( $pid ) {
					return (int) $p['product_id'] === $pid;
				}
			)
		);
		$rep['filtered'] = $pid;
		return $rep;
	}

	/** Months (Y-m) between two dates, inclusive. */
	public static function months( string $from, string $to ): array {
		$out = array();
		$m   = substr( $from, 0, 7 );
		$end = substr( $to, 0, 7 );
		while ( $m <= $end && count( $out ) < 240 ) {
			$out[] = $m;
			$m     = gmdate( 'Y-m', strtotime( $m . '-01 +1 month' ) );
		}
		return $out;
	}

	private static function empty_row( int $pid ): array {
		$costs = array();
		foreach ( PL_Entries::categories() as $slug => $c ) {
			if ( 'cost' === $c[1] && 'general' !== $slug ) {
				$costs[ $slug ] = 0.0;
			}
		}
		return array(
			'product_id'       => $pid,
			'title'            => '',
			'units'            => 0,
			'units_retail'     => 0,
			'units_trade'      => 0,
			'retail'           => 0.0,
			'trade'            => 0.0,
			'offsite'          => 0.0,
			'revenue'          => 0.0,
			'vat_out'          => 0.0,
			'creators_rs'      => 0.0,
			'costs'            => $costs,
			'vat_in'           => 0.0,
			'shipping_charged' => 0.0,
			'shipping_cost'    => 0.0,
			'consumables'      => 0.0,
			'payment_fees'     => 0.0,
			'costs_total'      => 0.0,
			'net'              => 0.0,
			'avg_unit'         => null,
			'break_even'       => null,
		);
	}

	/**
	 * The full computation (no cache).
	 *
	 * @param bool $with_be Also compute the break-even (cumulative since the start date).
	 */
	public static function compute( string $from, string $to, bool $with_be ): array {
		$s       = PL_Settings::get();
		$rows    = array();
		$general = array();
		$unalloc = array();
		$orders_n = 0;

		$row = static function ( int $pid ) use ( &$rows ) {
			if ( ! isset( $rows[ $pid ] ) ) {
				$rows[ $pid ] = self::empty_row( $pid );
			}
		};

		if ( $from <= $to ) {
			foreach ( self::months( $from, $to ) as $m ) {
				$m_from = max( $from, $m . '-01' );
				$m_to   = min( $to, gmdate( 'Y-m-t', strtotime( $m . '-01' ) ) );
				$month  = self::orders_of( $m_from, $m_to, $s );
				$orders_n += count( $month );

				// Order revenue, shipping charged.
				foreach ( $month as $o ) {
					foreach ( $o['lines'] as $pid => $l ) {
						$row( $pid );
						$rows[ $pid ][ $o['trade'] ? 'trade' : 'retail' ] += $l['net'];
						$rows[ $pid ][ $o['trade'] ? 'units_trade' : 'units_retail' ] += $l['qty'];
						$rows[ $pid ]['vat_out']          += $l['vat'];
						$rows[ $pid ]['shipping_charged'] += $o['ship_net'] * $l['w'];
					}
				}

				// Entries of the month.
				foreach ( PL_Entries::in_range( $m_from, $m_to ) as $e ) {
					$cat = $e['category'];
					if ( $e['product_id'] > 0 ) {
						$pid = $e['product_id'];
						$row( $pid );
						if ( 'offsite' === $cat ) {
							$rows[ $pid ]['offsite'] += $e['net_amount'];
						} else {
							$rows[ $pid ]['costs'][ $cat ] += $e['net_amount'];
							$rows[ $pid ]['vat_in']        += $e['vat_amount'];
						}
						continue;
					}
					if ( '' === $e['pool'] || ( 'consumables' === $cat && ! $s['spread_consumables'] ) ) {
						$general[ $cat ] = ( isset( $general[ $cat ] ) ? $general[ $cat ] : 0.0 ) + $e['net_amount'];
						continue;
					}
					// Pooled: share over the month's matching orders.
					$share = self::pool_shares( $cat, $e['pool'], $month );
					if ( ! $share ) {
						$k             = $cat . ':' . $e['pool'];
						$unalloc[ $k ] = ( isset( $unalloc[ $k ] ) ? $unalloc[ $k ] : 0.0 ) + $e['net_amount'];
						continue;
					}
					$field = 'shipping' === $cat ? 'shipping_cost' : $cat;
					foreach ( $share as $oid => $part ) {
						foreach ( $month[ $oid ]['lines'] as $pid => $l ) {
							$row( $pid );
							$rows[ $pid ][ $field ] += $e['net_amount'] * $part * $l['w'];
							$rows[ $pid ]['vat_in'] += $e['vat_amount'] * $part * $l['w'];
						}
					}
				}
			}
		}

		// Creator payouts (Revenue Splitter, public API only).
		$rs = self::creator_payouts( $from, $to, $s['house'] );
		foreach ( $rs['per_product'] as $pid => $amount ) {
			$row( (int) $pid );
			$rows[ $pid ]['creators_rs'] = $amount;
		}

		// Totals per product.
		$tot = array(
			'retail'           => 0.0,
			'trade'            => 0.0,
			'offsite'          => 0.0,
			'revenue'          => 0.0,
			'vat_out'          => 0.0,
			'vat_in'           => 0.0,
			'creators_rs'      => 0.0,
			'shipping_charged' => 0.0,
			'shipping_cost'    => 0.0,
			'consumables'      => 0.0,
			'payment_fees'     => 0.0,
			'costs_total'      => 0.0,
			'net'              => 0.0,
			'units'            => 0,
		);
		foreach ( $rows as $pid => &$r ) {
			$title      = get_the_title( $pid );
			$r['title'] = '' !== (string) $title ? html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' ) : '#' . $pid;
			$r['units'] = $r['units_retail'] + $r['units_trade'];
			foreach ( array( 'retail', 'trade', 'offsite', 'vat_out', 'vat_in', 'shipping_charged', 'shipping_cost', 'consumables', 'payment_fees', 'creators_rs' ) as $k ) {
				$r[ $k ] = round( $r[ $k ], 2 );
			}
			foreach ( $r['costs'] as $k => $v ) {
				$r['costs'][ $k ] = round( $v, 2 );
			}
			$r['revenue']     = round( $r['retail'] + $r['trade'] + $r['offsite'], 2 );
			$r['costs_total'] = round( array_sum( $r['costs'] ) + $r['creators_rs'] + $r['shipping_cost'] + $r['consumables'] + $r['payment_fees'], 2 );
			$r['net']         = round( $r['revenue'] + $r['shipping_charged'] - $r['costs_total'], 2 );
			$r['avg_unit']    = $r['units'] > 0 ? round( ( $r['retail'] + $r['trade'] - $r['creators_rs'] ) / $r['units'], 2 ) : null;
			foreach ( $tot as $k => $v ) {
				$tot[ $k ] += 'units' === $k ? $r['units'] : $r[ $k ];
			}
		}
		unset( $r );
		foreach ( $tot as $k => $v ) {
			$tot[ $k ] = 'units' === $k ? (int) $v : round( $v, 2 );
		}

		// Break-even since the start date (cumulative to the period's end).
		if ( $with_be ) {
			$cum = $from === PL_Settings::start() ? $rows : self::compute( PL_Settings::start(), $to, false )['by_id'];
			foreach ( $rows as $pid => &$r ) {
				$c = isset( $cum[ $pid ] ) ? $cum[ $pid ] : $r;
				if ( $c['net'] < 0 && null !== $c['avg_unit'] && $c['avg_unit'] > 0 ) {
					$r['break_even'] = (int) ceil( -$c['net'] / $c['avg_unit'] );
				}
				$r['net_since_start'] = $c['net'];
			}
			unset( $r );
		}

		$gen_total = round( array_sum( $general ) + array_sum( $unalloc ), 2 );
		foreach ( $general as $k => $v ) {
			$general[ $k ] = round( $v, 2 );
		}
		foreach ( $unalloc as $k => $v ) {
			$unalloc[ $k ] = round( $v, 2 );
		}

		$list = array_values( $rows );
		usort(
			$list,
			static function ( $a, $b ) {
				return $b['net'] <=> $a['net'];
			}
		);

		return array(
			'period'      => array(
				'from'  => $from,
				'to'    => $to,
				'start' => PL_Settings::start(),
			),
			'currency'    => PL_Entries::currency(),
			'orders'      => $orders_n,
			'products'    => $list,
			'by_id'       => $rows,
			'totals'      => $tot,
			'general'     => array(
				'by_category' => $general,
				'unallocated' => $unalloc,
				'total'       => $gen_total,
			),
			'grand_total' => round( $tot['net'] - $gen_total, 2 ),
			'rs'          => array(
				'available' => $rs['available'],
				'house'     => $s['house'],
			),
		);
	}

	/**
	 * Paid orders of a period: per order its product lines (net, VAT, units,
	 * weight in the order), trade flag, shipping charged, carrier, payment
	 * group, value.
	 *
	 * @return array order_id => array
	 */
	public static function orders_of( string $from, string $to, array $s ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		try {
			$tz    = wp_timezone();
			$range = ( new DateTimeImmutable( $from . ' 00:00:00', $tz ) )->getTimestamp() . '...' . ( new DateTimeImmutable( $to . ' 23:59:59', $tz ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'limit'        => -1,
				'status'       => self::STATUSES,
				'type'         => 'shop_order',
				'return'       => 'objects',
				'date_created' => $range,
			)
		);
		$out = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$o = self::order_parts( $order, $s );
			if ( $o['lines'] ) {
				$out[ (int) $order->get_id() ] = $o;
			}
		}
		return $out;
	}

	/** One order's parts (also used by the orOS feed). */
	public static function order_parts( WC_Order $order, array $s ): array {
		$ref   = self::refunds_by_item( $order );
		$lines = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$qty = (int) $item->get_quantity();
			if ( $qty <= 0 ) {
				continue;
			}
			$pid = self::product_of( $item );
			if ( $pid <= 0 ) {
				continue;
			}
			$net   = (float) $item->get_total();
			$tax   = (float) $item->get_total_tax();
			$gross = $net + $tax;
			$ratio = $gross > 0 && isset( $ref[ $item_id ] ) ? min( 1.0, $ref[ $item_id ] / $gross ) : 0.0;
			$rqty  = abs( (int) $order->get_qty_refunded_for_item( $item_id ) );
			if ( ! isset( $lines[ $pid ] ) ) {
				$lines[ $pid ] = array(
					'net' => 0.0,
					'vat' => 0.0,
					'qty' => 0,
				);
			}
			$lines[ $pid ]['net'] += $net * ( 1 - $ratio );
			$lines[ $pid ]['vat'] += $tax * ( 1 - $ratio );
			$lines[ $pid ]['qty'] += max( 0, $qty - $rqty );
		}

		// Weight of each product in the order (by net; equal when all are 0).
		$sum = 0.0;
		foreach ( $lines as $l ) {
			$sum += max( 0.0, $l['net'] );
		}
		$n = count( $lines );
		foreach ( $lines as $pid => $l ) {
			$lines[ $pid ]['w'] = $sum > 0 ? max( 0.0, $l['net'] ) / $sum : ( $n ? 1 / $n : 0 );
		}

		$ship_net = max( 0.0, (float) $order->get_shipping_total() - (float) $order->get_total_shipping_refunded() );

		$carrier = '';
		$pickup  = false;
		foreach ( $order->get_items( 'shipping' ) as $ship ) {
			if ( ! $ship instanceof WC_Order_Item_Shipping ) {
				continue;
			}
			$mid  = strtolower( (string) $ship->get_method_id() );
			$name = strtolower( (string) $ship->get_name() );
			if ( 'local_pickup' === $mid || 'pickup_location' === $mid || 'nox_store_pickup' === $mid ) {
				$pickup = true;
				continue;
			}
			foreach ( $s['carriers'] as $key => $g ) {
				foreach ( $g['match'] as $w ) {
					if ( false !== strpos( $mid, $w ) || false !== strpos( $name, $w ) ) {
						$carrier = (string) $key;
						break 3;
					}
				}
			}
		}

		$pm  = (string) $order->get_payment_method();
		$pay = '';
		foreach ( $s['pay'] as $key => $g ) {
			if ( in_array( strtolower( $pm ), $g['methods'], true ) ) {
				$pay = (string) $key;
				break;
			}
		}

		return array(
			'id'       => (int) $order->get_id(),
			'lines'    => $lines,
			'trade'    => '' !== (string) $order->get_meta( '_tra_trade' ),
			'ship_net' => $ship_net,
			'carrier'  => $carrier,
			'shipped'  => '' !== $carrier || ( ! $pickup && count( $order->get_items( 'shipping' ) ) > 0 ),
			'pay'      => $pay,
			'method'   => $pm,
			'value'    => max( 0.0, (float) $order->get_total() - (float) $order->get_total_refunded() ),
		);
	}

	/**
	 * Share of a pooled entry per order: order_id => fraction (sums to 1),
	 * or [] when no order of the month matches.
	 */
	public static function pool_shares( string $cat, string $pool, array $month ): array {
		$pick = array();
		foreach ( $month as $oid => $o ) {
			if ( 'shipping' === $cat && $o['carrier'] === $pool ) {
				$pick[ $oid ] = 1.0;
			} elseif ( 'consumables' === $cat && $o['shipped'] ) {
				$pick[ $oid ] = 1.0;
			} elseif ( 'payment_fees' === $cat && $o['pay'] === $pool ) {
				$pick[ $oid ] = $o['value'];
			}
		}
		$sum = array_sum( $pick );
		if ( $sum <= 0 ) {
			// Payment fees over orders worth 0: equal shares instead.
			if ( $pick ) {
				$sum  = count( $pick );
				$pick = array_fill_keys( array_keys( $pick ), 1.0 );
			} else {
				return array();
			}
		}
		foreach ( $pick as $oid => $v ) {
			$pick[ $oid ] = $v / $sum;
		}
		return $pick;
	}

	/** The product (parent of a variation) of an order line, or 0. */
	public static function product_of( WC_Order_Item_Product $item ): int {
		$p = $item->get_product();
		if ( $p instanceof WC_Product_Variation ) {
			return (int) $p->get_parent_id();
		}
		if ( $p instanceof WC_Product ) {
			return (int) $p->get_id();
		}
		return (int) $item->get_product_id();
	}

	/**
	 * Refunded gross per original line item id: itemised refunds by
	 * _refunded_item_id; an amount-only remainder spread over the lines in
	 * proportion (the same rule as RS_Reports::collect_refunds()).
	 */
	public static function refunds_by_item( WC_Order $order ): array {
		$ref        = array();
		$unassigned = 0.0;
		$itemized_t = 0.0;
		foreach ( $order->get_refunds() as $refund ) {
			$itemized = 0.0;
			foreach ( $refund->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $ri ) {
				$amount    = abs( (float) $ri->get_total() + (float) $ri->get_total_tax() );
				$itemized += $amount;
				if ( ! $ri instanceof WC_Order_Item_Product ) {
					continue;
				}
				$orig = (int) $ri->get_meta( '_refunded_item_id' );
				if ( $orig > 0 && $amount > 0 ) {
					$ref[ $orig ] = ( isset( $ref[ $orig ] ) ? $ref[ $orig ] : 0.0 ) + $amount;
				}
			}
			$itemized_t += $itemized;
			$rest        = abs( (float) $refund->get_amount() ) - $itemized;
			if ( $rest > 0.005 ) {
				$unassigned += $rest;
			}
		}
		if ( $unassigned > 0.005 ) {
			$remaining = (float) $order->get_total() - $itemized_t;
			if ( $remaining > 0.005 ) {
				$ratio = min( 1.0, $unassigned / $remaining );
				foreach ( $order->get_items() as $item_id => $item ) {
					if ( ! $item instanceof WC_Order_Item_Product ) {
						continue;
					}
					$gross          = (float) $item->get_total() + (float) $item->get_total_tax();
					$already        = isset( $ref[ $item_id ] ) ? $ref[ $item_id ] : 0.0;
					$left           = max( 0.0, $gross - $already );
					$ref[ $item_id ] = $already + $left * $ratio;
				}
			}
		}
		return $ref;
	}

	/**
	 * Creator payouts per product from Revenue Splitter's own report:
	 * every split whose name is not one of the publisher's names.
	 *
	 * @return array{available: bool, per_product: float[]}
	 */
	public static function creator_payouts( string $from, string $to, array $house ): array {
		$out = array(
			'available'   => false,
			'per_product' => array(),
		);
		if ( $from > $to || ! class_exists( 'RS_Reports' ) || ! is_callable( array( 'RS_Reports', 'run' ) ) ) {
			return $out;
		}
		try {
			$rep = RS_Reports::run(
				array(
					'date_start' => $from,
					'date_end'   => $to,
				)
			);
		} catch ( \Throwable $e ) {
			return $out;
		}
		$out['available'] = true;
		$own              = array_fill_keys( array_map( 'mb_strtolower', $house ), true );
		foreach ( isset( $rep['products'] ) && is_array( $rep['products'] ) ? $rep['products'] : array() as $p ) {
			$pid = isset( $p['product_id'] ) ? (int) $p['product_id'] : 0;
			if ( $pid <= 0 || empty( $p['splits'] ) || ! is_array( $p['splits'] ) ) {
				continue;
			}
			$sum = 0.0;
			foreach ( $p['splits'] as $sp ) {
				$name = isset( $sp['name'] ) ? mb_strtolower( (string) $sp['name'] ) : '';
				if ( '' !== $name && ! isset( $own[ $name ] ) ) {
					$sum += (float) ( isset( $sp['amount'] ) ? $sp['amount'] : 0 );
				}
			}
			$out['per_product'][ $pid ] = round( $sum, 2 );
		}
		return $out;
	}
}

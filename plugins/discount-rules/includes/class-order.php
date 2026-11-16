<?php
/**
 * DSR_Order — lines with a rule in orders (Bible §14.25: a plugin's
 * discount is written like a coupon).
 *
 * Every line that had a rule in the cart gets:
 *  - hidden meta _dsr: { r: rule, t: type, name, p: unit price before the
 *    rule, u: after (as stored), pre_u / pre_t: total and tax per unit at
 *    the discounted price, sub_u: subtotal per unit after the move }
 *  - visible meta «Προσφορά: 3 για 2» (readable without the plugin)
 *  - subtotal = the price before the rule, total = the discounted price,
 *    so WooCommerce (admin, emails, Analytics), Revenue Splitter and Store
 *    Pulse show the rule as a discount; the classic checkout raises the
 *    order discount by the same amount (the block checkout calls
 *    calculate_totals(), which does it from the lines).
 *
 * The cart rule is the coupon line dsr-<id> with hidden meta _dsr_rule.
 * Order meta _dsr_applied: [ { r, name, type, amount } ] (amount: the
 * discount without tax), the order's record of the rules it got.
 *
 * WooCommerce rewrites line totals from the subtotal when coupons are
 * recalculated on an existing order (WC_Abstract_Order::recalculate_coupons()
 * sets total = subtotal): before_totals() puts the discounted price back.
 * Lines a person edited by hand stay as they are.
 *
 * These hooks run whatever the mode and with DSR_DISABLE too: they only
 * keep existing orders right.
 */

defined( 'ABSPATH' ) || exit;

final class DSR_Order {

	const META     = '_dsr';
	const META_CPN = '_dsr_rule';
	const APPLIED  = '_dsr_applied';

	/** @var bool[] Orders whose coupons are being recalculated (id => true). */
	private static $recalc = array();

	public static function init(): void {
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'line_item' ), 30, 4 );
		add_action( 'woocommerce_checkout_create_order_coupon_item', array( __CLASS__, 'coupon_item' ), 30, 4 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'checkout_order' ), 30, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'store_api_order' ), 30, 1 );
		add_filter( 'woocommerce_order_recalculate_coupons_coupon_object', array( __CLASS__, 'recalc_flag' ), 30, 4 );
		add_action( 'woocommerce_order_before_calculate_totals', array( __CLASS__, 'before_totals' ), 30, 2 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_meta' ), 30, 1 );
	}

	/* =====================================================================
	 * Checkout
	 * =================================================================== */

	/**
	 * @param mixed $item   Order item.
	 * @param mixed $key    Cart key.
	 * @param mixed $values Cart item.
	 * @param mixed $order  Order.
	 */
	public static function line_item( $item, $key, $values, $order ): void {
		if ( ! $item instanceof WC_Order_Item_Product || ! is_array( $values ) || ! isset( $values[ DSR_Cart::KEY ]['r'], $values[ DSR_Cart::KEY ]['p'], $values[ DSR_Cart::KEY ]['u'] ) ) {
			return;
		}
		try {
			$m = $values[ DSR_Cart::KEY ];
			$p = isset( $values['data'] ) && $values['data'] instanceof WC_Product ? $values['data'] : $item->get_product();
			self::mark( $item, $m, $p instanceof WC_Product ? $p : null );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Writes the rule meta on a line and moves its subtotal to the price
	 * before the rule. The line total (the discounted price) is not touched.
	 *
	 * @param WC_Order_Item_Product $item Line.
	 * @param array                 $m    { r, t, n, p, u }.
	 * @param WC_Product|null       $p    Product.
	 */
	private static function mark( WC_Order_Item_Product $item, array $m, ?WC_Product $p ): void {
		$base  = (float) $m['p'];
		$unit  = (float) $m['u'];
		$qty   = max( 1, (float) $item->get_quantity() );
		$sub   = (float) $item->get_subtotal();
		$taxes = $item->get_taxes();
		$taxes = is_array( $taxes ) ? $taxes : array();
		$stax  = isset( $taxes['subtotal'] ) && is_array( $taxes['subtotal'] ) ? $taxes['subtotal'] : array();
		$pre_t = (float) $item->get_subtotal_tax();

		$new_sub  = $sub;
		$new_stax = $stax;
		if ( $base > $unit + 0.000001 ) {
			if ( $unit > 0 ) {
				$r       = $base / $unit;
				$new_sub = $sub * $r;
				foreach ( $new_stax as $rate => $amount ) {
					$new_stax[ $rate ] = (float) $amount * $r;
				}
			} elseif ( $p ) {
				// Free line (e.g. the free item of «3 για 2»): the price before from the product's own tax.
				$new_sub  = (float) wc_get_price_excluding_tax(
					$p,
					array(
						'qty'   => $qty,
						'price' => $base,
					)
				);
				$new_stax = array();
				if ( wc_tax_enabled() && $p->is_taxable() ) {
					$new_stax = WC_Tax::calc_tax( $new_sub, WC_Tax::get_rates( $item->get_tax_class() ), false );
				}
			}
		}

		if ( abs( $new_sub - $sub ) > 0.0000001 ) {
			$item->set_subtotal( wc_format_decimal( $new_sub ) );
			$taxes['subtotal'] = $new_stax;
			if ( ! isset( $taxes['total'] ) ) {
				$taxes['total'] = array();
			}
			$item->set_taxes( $taxes );
			$item->set_subtotal_tax( wc_format_decimal( array_sum( array_map( 'floatval', $new_stax ) ) ) );
		}

		$name = wp_strip_all_tags( (string) ( $m['n'] ?? '' ) );
		$item->update_meta_data(
			self::META,
			array(
				'r'     => (int) $m['r'],
				't'     => sanitize_key( (string) ( $m['t'] ?? '' ) ),
				'name'  => $name,
				'p'     => $base,
				'u'     => $unit,
				'pre_u' => $sub / $qty,
				'pre_t' => $pre_t / $qty,
				'sub_u' => $new_sub / $qty,
			)
		);
		if ( '' !== $name ) {
			$item->update_meta_data( DSR_Settings::text( 't_line' ), $name );
		}
	}

	/**
	 * The coupon line of the cart rule: which rule it was.
	 *
	 * @param mixed $item   Coupon item.
	 * @param mixed $code   Code.
	 * @param mixed $coupon Coupon.
	 * @param mixed $order  Order.
	 */
	public static function coupon_item( $item, $code, $coupon = null, $order = null ): void {
		$id = DSR_Cart::code_rule( $code );
		if ( $id <= 0 || ! $item instanceof WC_Order_Item_Coupon ) {
			return;
		}
		$rule = DSR_Rules::get( $id );
		$item->update_meta_data(
			self::META_CPN,
			array(
				'r'    => $id,
				'name' => $rule ? wp_strip_all_tags( DSR_Rules::label( $rule ) ) : '',
			)
		);
	}

	/**
	 * Classic checkout: the order discount grows by the rule discounts, so
	 * subtotal − discount = total; then the order's record of the rules.
	 *
	 * @param mixed $order Order.
	 * @param mixed $data  Posted data.
	 */
	public static function checkout_order( $order, $data = array() ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		try {
			$d  = 0.0;
			$dt = 0.0;
			foreach ( $order->get_items() as $item ) {
				$m = self::meta( $item );
				if ( null === $m ) {
					continue;
				}
				$qty = (float) $item->get_quantity();
				$d  += (float) $item->get_subtotal() - (float) $m['pre_u'] * $qty;
				$dt += (float) $item->get_subtotal_tax() - (float) $m['pre_t'] * $qty;
			}
			if ( abs( $d ) > 0.0000001 || abs( $dt ) > 0.0000001 ) {
				$order->set_discount_total( wc_format_decimal( (float) $order->get_discount_total( 'edit' ) + $d, wc_get_price_decimals() ) );
				$order->set_discount_tax( wc_format_decimal( (float) $order->get_discount_tax( 'edit' ) + $dt, wc_get_price_decimals() ) );
			}
			self::record( $order );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Block checkout: the order's record of the rules (the discount comes
	 * from calculate_totals()).
	 *
	 * @param mixed $order Order.
	 */
	public static function store_api_order( $order ): void {
		if ( $order instanceof WC_Order ) {
			try {
				self::record( $order );
			} catch ( \Throwable $e ) {
				return;
			}
		}
	}

	/** Order meta _dsr_applied from the lines and the coupon line. */
	private static function record( WC_Order $order ): void {
		$by = array();
		foreach ( $order->get_items() as $item ) {
			$m = self::meta( $item );
			if ( null === $m ) {
				continue;
			}
			$r = (int) $m['r'];
			if ( ! isset( $by[ $r ] ) ) {
				$by[ $r ] = array(
					'r'      => $r,
					'name'   => (string) $m['name'],
					'type'   => (string) $m['t'],
					'amount' => 0.0,
				);
			}
			$by[ $r ]['amount'] += (float) $item->get_quantity() * ( (float) $m['sub_u'] - (float) $m['pre_u'] );
		}
		foreach ( $order->get_items( 'coupon' ) as $c ) {
			if ( ! $c instanceof WC_Order_Item_Coupon ) {
				continue;
			}
			$id = DSR_Cart::code_rule( $c->get_code() );
			if ( $id <= 0 ) {
				continue;
			}
			$meta = $c->get_meta( self::META_CPN, true );
			if ( ! isset( $by[ $id ] ) ) {
				$by[ $id ] = array(
					'r'      => $id,
					'name'   => is_array( $meta ) && isset( $meta['name'] ) ? (string) $meta['name'] : '',
					'type'   => 'cart',
					'amount' => 0.0,
				);
			}
			$by[ $id ]['amount'] += (float) $c->get_discount();
		}
		if ( empty( $by ) ) {
			$order->delete_meta_data( self::APPLIED );
			return;
		}
		$dp = wc_get_price_decimals();
		foreach ( $by as $r => $row ) {
			$by[ $r ]['amount'] = round( $row['amount'], $dp );
		}
		$order->update_meta_data( self::APPLIED, array_values( $by ) );
	}

	/* =====================================================================
	 * Recalculation in the admin
	 * =================================================================== */

	/**
	 * @param mixed $coupon      Coupon.
	 * @param mixed $code        Code.
	 * @param mixed $coupon_item Coupon item.
	 * @param mixed $order       Order.
	 */
	public static function recalc_flag( $coupon, $code = '', $coupon_item = null, $order = null ) {
		if ( $order instanceof WC_Abstract_Order ) {
			self::$recalc[ (int) $order->get_id() ] = true;
		}
		return $coupon;
	}

	/**
	 * Puts the discounted price back on lines whose total was reset to the
	 * subtotal. Lines a person edited by hand stay as they are.
	 *
	 * @param mixed $and_taxes Calculate taxes.
	 * @param mixed $order     Order.
	 */
	public static function before_totals( $and_taxes, $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$oid  = (int) $order->get_id();
		$flag = ! empty( self::$recalc[ $oid ] );
		unset( self::$recalc[ $oid ] );
		try {
			foreach ( $order->get_items() as $item ) {
				$m = self::meta( $item );
				if ( null === $m || (float) $m['sub_u'] <= 0 || (float) $m['pre_u'] >= (float) $m['sub_u'] - 0.0000001 ) {
					continue;
				}
				$sub   = (float) $item->get_subtotal();
				$total = (float) $item->get_total();
				if ( ! $flag && abs( $total - $sub ) > 0.005 ) {
					continue;
				}
				$f     = (float) $m['pre_u'] / (float) $m['sub_u'];
				$taxes = $item->get_taxes();
				if ( is_array( $taxes ) && isset( $taxes['total'] ) && is_array( $taxes['total'] ) ) {
					foreach ( $taxes['total'] as $rate => $amount ) {
						$taxes['total'][ $rate ] = (float) $amount * $f;
					}
					$item->set_taxes( $taxes );
				}
				$item->set_total( wc_format_decimal( $total * $f ) );
				$item->set_total_tax( wc_format_decimal( (float) $item->get_total_tax() * $f ) );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/* =====================================================================
	 * Reading
	 * =================================================================== */

	/**
	 * The rule meta of an order line, or null.
	 *
	 * @param mixed $item Order item.
	 */
	public static function meta( $item ): ?array {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return null;
		}
		$m = $item->get_meta( self::META, true );
		if ( ! is_array( $m ) || ! isset( $m['r'], $m['pre_u'], $m['sub_u'] ) ) {
			return null;
		}
		return array(
			'r'     => (int) $m['r'],
			't'     => isset( $m['t'] ) ? (string) $m['t'] : '',
			'name'  => isset( $m['name'] ) ? (string) $m['name'] : '',
			'p'     => isset( $m['p'] ) ? (float) $m['p'] : 0.0,
			'u'     => isset( $m['u'] ) ? (float) $m['u'] : 0.0,
			'pre_u' => (float) $m['pre_u'],
			'pre_t' => isset( $m['pre_t'] ) ? (float) $m['pre_t'] : 0.0,
			'sub_u' => (float) $m['sub_u'],
		);
	}

	/** dsr_get_item_rule(). */
	public static function item_rule( WC_Order_Item_Product $item ): ?array {
		$m = self::meta( $item );
		if ( null === $m ) {
			return null;
		}
		return array(
			'rule'     => $m['r'],
			'name'     => $m['name'],
			'type'     => $m['t'],
			'before'   => $m['p'],
			'after'    => $m['u'],
			'discount' => round( (float) $item->get_quantity() * ( $m['sub_u'] - $m['pre_u'] ), wc_get_price_decimals() ),
		);
	}

	/**
	 * @param mixed $keys Hidden meta keys.
	 */
	public static function hidden_meta( $keys ) {
		$keys   = is_array( $keys ) ? $keys : array();
		$keys[] = self::META;
		$keys[] = self::META_CPN;
		return $keys;
	}
}

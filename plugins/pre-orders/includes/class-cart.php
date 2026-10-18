<?php
/**
 * POR_Cart — cart, checkout and the order lines.
 *
 *  - Cart line note «Pre-order: Releases on …» (woocommerce_get_item_data:
 *    cart, mini-cart, checkout, and the Cart / Checkout blocks, which read
 *    the same data through the Store API).
 *  - Mixed cart (pre-orders together with available products), setting
 *    «mixed»: allow (a note in the classic cart and checkout), warn (a
 *    notice that suggests two orders) or block (add to cart refused, and
 *    the cart check stops checkout; both work in the Store API too,
 *    which turns WooCommerce notices into errors).
 *  - Snapshot on the order line at checkout (classic and block checkout
 *    both create lines through woocommerce_checkout_create_order_line_item)
 *    and on lines a shop manager adds to an order by hand.
 *
 * The treatment follows the mode: off = nothing, test = shop managers only.
 */

defined( 'ABSPATH' ) || exit;

final class POR_Cart {

	public static function init(): void {
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 20, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add' ), 20, 5 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart' ), 20 );
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'mixed_notice' ), 20 );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'mixed_notice' ), 5 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'snapshot' ), 20, 4 );
		add_action( 'woocommerce_ajax_add_order_item_meta', array( __CLASS__, 'admin_item' ), 20, 3 );
	}

	/** The active pre-order of a cart line, or null. */
	private static function of_line( array $values ): ?array {
		$p = isset( $values['data'] ) && $values['data'] instanceof WC_Product ? $values['data'] : null;
		return $p ? POR_Product::active( $p ) : null;
	}

	/**
	 * Counts the lines of the cart: [ pre-orders, available ].
	 *
	 * @return int[]
	 */
	private static function counts(): array {
		$pre   = 0;
		$avail = 0;
		$cart  = function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart() : array();
		foreach ( $cart as $values ) {
			if ( null !== self::of_line( (array) $values ) ) {
				++$pre;
			} else {
				++$avail;
			}
		}
		return array( $pre, $avail );
	}

	/** woocommerce_get_item_data: the release line under a cart item. */
	public static function item_data( $data, $values ) {
		try {
			if ( ! POR_Settings::visible() || ! is_array( $values ) ) {
				return $data;
			}
			$a = self::of_line( $values );
			if ( null === $a ) {
				return $data;
			}
			$data   = is_array( $data ) ? $data : array();
			$data[] = array(
				'key'     => POR_Settings::text( 't_line' ),
				'value'   => POR_Product::availability_text( $a ),
				'display' => '',
			);
		} catch ( \Throwable $e ) {
			return $data;
		}
		return $data;
	}

	/**
	 * woocommerce_add_to_cart_validation: «block» keeps pre-orders and
	 * available products in separate carts.
	 */
	public static function validate_add( $passed, $product_id, $quantity = 1, $variation_id = 0, $variations = array() ) {
		try {
			if ( ! $passed || 'block' !== POR_Settings::get()['mixed'] || ! POR_Settings::visible() ) {
				return $passed;
			}
			$p = wc_get_product( $variation_id ? (int) $variation_id : (int) $product_id );
			if ( ! $p instanceof WC_Product ) {
				return $passed;
			}
			$is_pre          = null !== POR_Product::active( $p );
			list( $pre, $av ) = self::counts();
			if ( ( $is_pre && $av > 0 ) || ( ! $is_pre && $pre > 0 ) ) {
				wc_add_notice( POR_Settings::text( 't_mixed_block' ), 'error' );
				return false;
			}
		} catch ( \Throwable $e ) {
			return $passed;
		}
		return $passed;
	}

	/** woocommerce_check_cart_items: «block» stops checkout with a mixed cart. */
	public static function check_cart(): void {
		try {
			if ( 'block' !== POR_Settings::get()['mixed'] || ! POR_Settings::visible() ) {
				return;
			}
			list( $pre, $av ) = self::counts();
			if ( $pre > 0 && $av > 0 ) {
				wc_add_notice( POR_Settings::text( 't_mixed_block' ), 'error' );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** Classic cart and checkout: the «allow» / «warn» note. */
	public static function mixed_notice(): void {
		try {
			$mode = POR_Settings::get()['mixed'];
			if ( 'block' === $mode || ! POR_Settings::visible() ) {
				return;
			}
			list( $pre, $av ) = self::counts();
			if ( 0 === $pre ) {
				return;
			}
			if ( 'warn' === $mode && $av > 0 ) {
				wc_print_notice( esc_html( POR_Settings::text( 't_mixed_warn' ) ), 'notice' );
			} elseif ( 'allow' === $mode && $av > 0 ) {
				wc_print_notice( esc_html( POR_Settings::text( 't_mixed_allow' ) ), 'notice' );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * woocommerce_checkout_create_order_line_item: the snapshot on the line
	 * and the order's flags (the order is saved right after by WooCommerce).
	 */
	public static function snapshot( $item, $cart_item_key, $values, $order ): void {
		try {
			if ( ! $item instanceof WC_Order_Item_Product || ! $order instanceof WC_Order || ! POR_Settings::visible() ) {
				return;
			}
			$a = self::of_line( (array) $values );
			if ( null === $a ) {
				return;
			}
			self::write_line( $item, $order, $a, isset( $values['data'] ) && $values['data'] instanceof WC_Product ? (int) $values['data']->get_id() : 0 );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * woocommerce_ajax_add_order_item_meta: a pre-order product added to an
	 * order by hand gets the same snapshot.
	 */
	public static function admin_item( $item_id, $item, $order ): void {
		try {
			if ( ! $item instanceof WC_Order_Item_Product || ! $order instanceof WC_Order || 'off' === POR_Settings::mode() ) {
				return;
			}
			$p = $item->get_product();
			$a = $p instanceof WC_Product ? POR_Product::active( $p ) : null;
			if ( null === $a ) {
				return;
			}
			self::write_line( $item, $order, $a, (int) $p->get_id() );
			$item->save();
			$order->save();
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** Readable row + hidden data on the line, flags on the order. */
	private static function write_line( WC_Order_Item_Product $item, WC_Order $order, array $a, int $pid ): void {
		$key = POR_Settings::text( 't_line' );
		$key = '' !== $key ? $key : 'Pre-order';
		$item->add_meta_data( $key, POR_Product::availability_text( $a ), true );
		$item->add_meta_data( POR_Orders::I_KEY, $key, true );
		$item->add_meta_data( POR_Orders::I_RELEASE, '' !== $a['date'] ? $a['date'] : 'tba', true );

		$order->update_meta_data( POR_Orders::M_HAS, 'yes' );
		if ( $pid > 0 ) {
			$list = trim( (string) $order->get_meta( POR_Orders::M_ITEMS, true ), ',' );
			$ids  = '' !== $list ? array_map( 'intval', explode( ',', $list ) ) : array();
			if ( ! in_array( $pid, $ids, true ) ) {
				$ids[] = $pid;
			}
			$order->update_meta_data( POR_Orders::M_ITEMS, ',' . implode( ',', $ids ) . ',' );
		}
	}
}

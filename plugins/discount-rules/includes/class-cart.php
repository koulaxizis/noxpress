<?php
/**
 * DSR_Cart — the rules in the cart (classic cart, mini-cart, wc-ajax and
 * the Store API of the Cart / Checkout blocks).
 *
 * Every cart calculation (woocommerce_before_calculate_totals, priority 30,
 * after Product Bundles at 20):
 *  1. Every line goes back to the price it had before this plugin touched
 *     it in this request; the "dsr" cart item data of the last calculation
 *     is dropped. So switching the plugin off, the mode off, DSR_DISABLE or
 *     a rule that ended gives the normal prices at the next calculation.
 *  2. When the visitor gets discounts in this request (mode, test mode,
 *     not a trade account, a front-end, wc-ajax or Store API request),
 *     DSR_Engine::compute() works out the cart from the stored prices:
 *     bundle lines (cart item "pbn") never take part.
 *  3. Each line with a rule gets its price lowered (set_price) and the
 *     cart item data "dsr" { r: rule, n: label, p: price before, u: price
 *     after } for the order line (DSR_Order).
 *  4. The cart rule (Δ) is a virtual coupon dsr-<rule id> that only this
 *     class adds to and removes from the applied coupons. Its amount is
 *     the one the engine worked out for this cart, so typing the code by
 *     hand gives nothing more: the coupon exists only while the cart
 *     qualifies, and only in the requests where the engine runs.
 *
 * Customer coupons: they never apply to a line that has a rule (approved
 * design, question 6); a 100 % coupon (free copies) still does, as in
 * Product Bundles. Trade Accounts already refuses coupons in trade carts.
 *
 * Hints: «Πρόσθεσε ακόμη 1 για την προσφορά…» above the classic cart
 * (printed, never stored in the session).
 *
 * Every callback is wrapped in try/catch: on any error the line keeps the
 * normal price.
 */

defined( 'ABSPATH' ) || exit;

final class DSR_Cart {

	const KEY    = 'dsr';
	const PREFIX = 'dsr-';

	/** @var bool True inside a Store API request (/wc/store/…). */
	private static $store_api = false;

	/** @var array spl_object_id( product ) => price before this plugin (this request). */
	private static $orig = array();

	/** @var array|null The last calculation of this request. */
	private static $last = null;

	/** @var array|null A calculation made before the first cart calculation of the request (coupon checks). */
	private static $peek = null;

	public static function init(): void {
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'rest_in' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'rest_out' ), 10, 3 );

		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'prices' ), 30, 1 );

		// The cart rule as a virtual coupon.
		add_filter( 'woocommerce_get_shop_coupon_data', array( __CLASS__, 'coupon_data' ), 20, 3 );
		add_filter( 'woocommerce_cart_totals_coupon_label', array( __CLASS__, 'coupon_label' ), 20, 2 );
		add_filter( 'woocommerce_cart_totals_coupon_html', array( __CLASS__, 'coupon_html' ), 20, 3 );
		add_action( 'woocommerce_applied_coupon', array( __CLASS__, 'typed_code' ), 20, 1 );

		// Customer coupons skip lines with a rule.
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( __CLASS__, 'coupon_valid' ), 30, 4 );
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( __CLASS__, 'coupon_items' ), 30, 2 );

		// Display in the classic cart.
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'item_price' ), 30, 3 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 30, 2 );
		add_action( 'woocommerce_before_cart_table', array( __CLASS__, 'hints' ), 20 );
	}

	public static function rest_in( $response, $handler, $request ) {
		if ( $request instanceof WP_REST_Request && 0 === strpos( (string) $request->get_route(), '/wc/store/' ) ) {
			self::$store_api = true;
		}
		return $response;
	}

	public static function rest_out( $response, $handler, $request ) {
		self::$store_api = false;
		return $response;
	}

	/**
	 * True when this request may give discounts: front-end pages, wc-ajax
	 * and the Store API; never admin screens, other REST, cron or CLI.
	 */
	public static function request_ok(): bool {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && ! self::$store_api ) {
			return false;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}
		return true;
	}

	/** True in a Store API request. */
	public static function in_store_api(): bool {
		return self::$store_api;
	}

	/** The virtual coupon code of a cart rule. */
	public static function code( int $rule_id ): string {
		return self::PREFIX . $rule_id;
	}

	/** The rule id of one of our codes, or 0. */
	public static function code_rule( $code ): int {
		$code = wc_format_coupon_code( (string) $code );
		return preg_match( '/^dsr-(\d{1,9})$/', $code, $m ) ? (int) $m[1] : 0;
	}

	/* =====================================================================
	 * The calculation
	 * =================================================================== */

	/**
	 * @param mixed $cart Cart.
	 */
	public static function prices( $cart ): void {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}
		self::$last = null;
		self::$peek = null;
		try {
			// 1. Back to the prices before this plugin.
			$lines    = array();
			$subtotal = 0.0;
			foreach ( $cart->cart_contents as $k => $item ) {
				if ( ! isset( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
					continue;
				}
				$oid = spl_object_id( $item['data'] );
				if ( isset( self::$orig[ $oid ] ) ) {
					$cart->cart_contents[ $k ]['data']->set_price( self::$orig[ $oid ] );
				}
				unset( $cart->cart_contents[ $k ][ self::KEY ] );
				$price     = (float) $item['data']->get_price();
				$qty       = (int) $item['quantity'];
				$subtotal += $price * $qty;
				if ( isset( $item['pbn'] ) ) {
					continue; // Bundle lines: never a rule.
				}
				$lines[ (string) $k ] = array(
					'product' => $item['data'],
					'qty'     => $qty,
					'price'   => $price,
				);
			}

			$on = self::request_ok() && DSR_Engine::on_for_user();
			if ( ! $on || empty( $lines ) ) {
				self::sync_coupon( $cart, null );
				return;
			}

			// 2–3. The engine and the line prices.
			$res = DSR_Engine::compute( $lines, DSR_Engine::context( $subtotal ) );
			foreach ( $res['lines'] as $k => $l ) {
				if ( ! isset( $cart->cart_contents[ $k ]['data'] ) ) {
					continue;
				}
				$p = $cart->cart_contents[ $k ]['data'];
				$o = spl_object_id( $p );
				if ( ! isset( self::$orig[ $o ] ) ) {
					self::$orig[ $o ] = $p->get_price( 'edit' );
				}
				$p->set_price( $l['unit'] );
				$cart->cart_contents[ $k ][ self::KEY ] = array(
					'r' => (int) $l['rule'],
					't' => (string) $l['type'],
					'n' => (string) $l['name'],
					'p' => (float) $l['price'],
					'u' => (float) $l['unit'],
				);
			}
			self::$last = $res;

			// 4. The cart rule.
			self::sync_coupon( $cart, $res['cart'] );
		} catch ( \Throwable $e ) {
			// Never a half-discounted cart: everything back to the normal prices.
			foreach ( $cart->cart_contents as $k => $item ) {
				if ( isset( $item['data'] ) && $item['data'] instanceof WC_Product ) {
					$oid = spl_object_id( $item['data'] );
					if ( isset( self::$orig[ $oid ] ) ) {
						$cart->cart_contents[ $k ]['data']->set_price( self::$orig[ $oid ] );
					}
				}
				unset( $cart->cart_contents[ $k ][ self::KEY ] );
			}
			self::$last = null;
			self::sync_coupon( $cart, null );
		}
	}

	/**
	 * Our virtual coupon in the applied coupons: only the current cart
	 * rule, none when there is none. Customer coupons are untouched.
	 *
	 * @param WC_Cart    $cart Cart.
	 * @param array|null $rule The engine's cart result.
	 */
	private static function sync_coupon( WC_Cart $cart, ?array $rule ): void {
		$want    = null === $rule ? '' : self::code( (int) $rule['rule'] );
		$applied = $cart->get_applied_coupons();
		$next    = array();
		$has     = false;
		foreach ( $applied as $code ) {
			if ( self::code_rule( $code ) > 0 ) {
				if ( '' !== $want && wc_format_coupon_code( $code ) === $want && ! $has ) {
					$next[] = $want;
					$has    = true;
				}
				continue;
			}
			$next[] = $code;
		}
		if ( '' !== $want && ! $has ) {
			$next[] = $want;
		}
		if ( $next !== $applied ) {
			$cart->set_applied_coupons( $next );
		}
	}

	/** The last calculation of this request (for the front-end and the order). */
	public static function last(): ?array {
		return self::$last;
	}

	/**
	 * The calculation for the coupon checks: the last one, or, before the
	 * first cart calculation of the request (WooCommerce checks the applied
	 * coupons when it loads the cart), one made now from the same lines
	 * without changing them.
	 */
	private static function current(): ?array {
		if ( null !== self::$last ) {
			return self::$last;
		}
		if ( null !== self::$peek ) {
			return self::$peek;
		}
		self::$peek = array();
		try {
			if ( ! self::request_ok() || ! DSR_Engine::on_for_user() || ! function_exists( 'WC' ) || ! WC()->cart ) {
				return self::$peek;
			}
			$lines    = array();
			$subtotal = 0.0;
			foreach ( WC()->cart->get_cart() as $k => $item ) {
				if ( ! isset( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
					continue;
				}
				$price     = (float) $item['data']->get_price();
				$subtotal += $price * (int) $item['quantity'];
				if ( ! isset( $item['pbn'] ) ) {
					$lines[ (string) $k ] = array(
						'product' => $item['data'],
						'qty'     => (int) $item['quantity'],
						'price'   => $price,
					);
				}
			}
			self::$peek = DSR_Engine::compute( $lines, DSR_Engine::context( $subtotal ) );
		} catch ( \Throwable $e ) {
			self::$peek = array();
		}
		return self::$peek;
	}

	/* =====================================================================
	 * The virtual coupon
	 * =================================================================== */

	/**
	 * Data of our code: a virtual fixed-cart coupon with the amount the
	 * engine worked out for this cart. Nothing outside the requests where
	 * the engine runs (an order recalculated in the admin keeps its stored
	 * coupon line).
	 *
	 * @param mixed $data   False or data.
	 * @param mixed $code   Code.
	 * @param mixed $coupon Coupon.
	 */
	public static function coupon_data( $data, $code, $coupon = null ) {
		$id = self::code_rule( $code );
		if ( $id <= 0 ) {
			return $data;
		}
		if ( ! self::request_ok() ) {
			return $data;
		}
		$res = self::current();
		if ( empty( $res ) || ! isset( $res['cart']['rule'] ) || (int) $res['cart']['rule'] !== $id ) {
			return $data;
		}
		return array(
			'code'                 => self::code( $id ),
			'discount_type'        => 'fixed_cart',
			'amount'               => (float) $res['cart']['amount'],
			'individual_use'       => false,
			'usage_limit'          => 0,
			'usage_limit_per_user' => 0,
			'limit_usage_to_x_items' => null,
			'free_shipping'        => false,
			'exclude_sale_items'   => false,
			'minimum_amount'       => '',
			'maximum_amount'       => '',
			'virtual'              => true,
		);
	}

	/**
	 * A code typed by the customer: taken out again; the engine puts it
	 * back when the cart qualifies, with its own amount.
	 *
	 * @param mixed $code Code.
	 */
	public static function typed_code( $code ): void {
		if ( self::code_rule( $code ) <= 0 || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		try {
			$applied = WC()->cart->get_applied_coupons();
			WC()->cart->set_applied_coupons( array_values( array_diff( $applied, array( wc_format_coupon_code( (string) $code ) ) ) ) );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * @param mixed $label  Label.
	 * @param mixed $coupon Coupon.
	 */
	public static function coupon_label( $label, $coupon = null ) {
		if ( $coupon instanceof WC_Coupon && self::code_rule( $coupon->get_code() ) > 0 ) {
			$res = self::current();
			if ( ! empty( $res ) && isset( $res['cart']['name'] ) ) {
				return esc_html( DSR_Settings::text( 't_cart' ) . ': ' . (string) $res['cart']['name'] );
			}
		}
		return $label;
	}

	/**
	 * Our discount has no «[Remove]» link in the classic cart.
	 *
	 * @param mixed $html        HTML.
	 * @param mixed $coupon      Coupon.
	 * @param mixed $amount_html Discount amount HTML.
	 */
	public static function coupon_html( $html, $coupon = null, $amount_html = '' ) {
		if ( $coupon instanceof WC_Coupon && self::code_rule( $coupon->get_code() ) > 0 && is_string( $amount_html ) && '' !== $amount_html ) {
			return $amount_html;
		}
		return $html;
	}

	/** A 100 % coupon (free copies) applies to lines with a rule too. */
	private static function full_coupon( $coupon ): bool {
		return $coupon instanceof WC_Coupon && $coupon->is_type( 'percent' ) && (float) $coupon->get_amount() >= 100.0;
	}

	/**
	 * Our coupon applies only to the lines the engine counted; customer
	 * coupons never to lines with a rule.
	 *
	 * @param mixed $valid   Valid.
	 * @param mixed $product Product.
	 * @param mixed $coupon  Coupon.
	 * @param mixed $values  Cart item / order item.
	 */
	public static function coupon_valid( $valid, $product, $coupon = null, $values = null ) {
		if ( ! $coupon instanceof WC_Coupon ) {
			return $valid;
		}
		if ( self::code_rule( $coupon->get_code() ) > 0 ) {
			if ( ! is_array( $values ) || ! isset( $values['key'] ) ) {
				return $valid;
			}
			$res  = self::current();
			$keys = ! empty( $res ) && isset( $res['cart']['keys'] ) ? $res['cart']['keys'] : array();
			return $valid && in_array( (string) $values['key'], $keys, true );
		}
		if ( ! $valid || self::full_coupon( $coupon ) ) {
			return $valid;
		}
		return ( is_array( $values ) && isset( $values[ self::KEY ] ) ) ? false : $valid;
	}

	/**
	 * Cart-wide coupons (fixed cart, WooCommerce 8.8+): the same rule.
	 *
	 * @param mixed $items  Items.
	 * @param mixed $coupon Coupon.
	 */
	public static function coupon_items( $items, $coupon = null ) {
		if ( ! is_array( $items ) || ! $coupon instanceof WC_Coupon ) {
			return $items;
		}
		$ours = self::code_rule( $coupon->get_code() ) > 0;
		if ( ! $ours && self::full_coupon( $coupon ) ) {
			return $items;
		}
		$res  = $ours ? self::current() : null;
		$keys = ! empty( $res ) && isset( $res['cart']['keys'] ) ? $res['cart']['keys'] : array();
		return array_values(
			array_filter(
				$items,
				static function ( $it ) use ( $ours, $keys ) {
					if ( ! is_object( $it ) || ! isset( $it->object ) || ! is_array( $it->object ) ) {
						return true;
					}
					if ( $ours ) {
						return isset( $it->key ) && in_array( (string) $it->key, $keys, true );
					}
					return ! isset( $it->object[ self::KEY ] );
				}
			)
		);
	}

	/* =====================================================================
	 * Display (classic cart, mini-cart)
	 * =================================================================== */

	/**
	 * The unit price of a line with a rule: the price before, struck, and the new one.
	 *
	 * @param mixed $html Price HTML.
	 * @param mixed $item Cart item.
	 * @param mixed $key  Cart key.
	 */
	public static function item_price( $html, $item = null, $key = '' ) {
		if ( ! is_array( $item ) || ! isset( $item[ self::KEY ]['p'], $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
			return $html;
		}
		try {
			$p      = $item['data'];
			$before = wc_get_price_to_display( $p, array( 'price' => (float) $item[ self::KEY ]['p'] ) );
			$after  = wc_get_price_to_display( $p, array( 'price' => (float) $item[ self::KEY ]['u'] ) );
			return wc_format_sale_price( $before, $after );
		} catch ( \Throwable $e ) {
			return $html;
		}
	}

	/**
	 * «Προσφορά: 3 για 2» under the line (classic cart, mini-cart, blocks).
	 *
	 * @param mixed $data Item data.
	 * @param mixed $item Cart item.
	 */
	public static function item_data( $data, $item = null ) {
		$data = is_array( $data ) ? $data : array();
		if ( is_array( $item ) && isset( $item[ self::KEY ]['n'] ) && '' !== (string) $item[ self::KEY ]['n'] ) {
			$data[] = array(
				'key'   => DSR_Settings::text( 't_line' ),
				'value' => wp_strip_all_tags( (string) $item[ self::KEY ]['n'] ),
			);
		}
		return $data;
	}

	/** «Πρόσθεσε ακόμη 1…» above the classic cart table (printed, never stored). */
	public static function hints(): void {
		if ( 'yes' !== DSR_Settings::get()['hint'] || null === self::$last || empty( self::$last['hints'] ) ) {
			return;
		}
		foreach ( self::$last['hints'] as $h ) {
			$text = DSR_Settings::fill(
				't_hint',
				array(
					'n'    => (int) $h['n'],
					'rule' => (string) $h['name'],
				)
			);
			if ( '' !== $text ) {
				echo '<div class="woocommerce-info dsr-hint" role="status">' . esc_html( $text ) . '</div>';
			}
		}
	}
}

<?php
/**
 * TRA_Pricing — module «Τιμές χονδρικής».
 *
 * Who sees wholesale prices: live → trade accounts; test → managers
 * (manage_woocommerce), as a preview of what an account sees; off or
 * TRA_DISABLE → nobody. Only on eligible products (TRA_Scope).
 *
 * How: the product's price and sale price are filtered for that user
 * ('view' context), so the shop, the cart, the mini-cart, the Store API
 * (Cart / Checkout blocks) and the order all use the same trade price, and
 * the regular price shows struck through. The order lines are ordinary
 * lines at the trade price: VAT, stock, Revenue Splitter (same shares of
 * the lower price) and refunds work as always. Nothing is stored on the
 * products.
 *
 * Where it never runs: admin screens (an order recalculated there keeps
 * its stored line prices), REST other than the Store API (so the WooCommerce
 * REST API, the Trade Feed and remote tools see the real prices), cron and
 * CLI. Bible §14.4: the cart and checkout run in AJAX too.
 *
 * Marks (stay with the order after uninstall, the shop's record):
 *  - order meta _tra_trade   discount % of the trade order
 *  - line meta  _tra_discount discount % of the line, and a readable line
 *               «Τιμή χονδρικής: −40%»
 *
 * Invoice (setting, classic checkout): a trade order needs the invoicing
 * plugin's receipt type set to "invoice"; the checkout preselects it.
 *
 * Coupons (settings, on by default):
 *  - no_coupons: a cart with a line at a trade price takes no coupon
 *    (woocommerce_coupon_is_valid, so the classic cart, the blocks and the
 *    re-check before payment all refuse it with the same message), and
 *    the coupon field is hidden for such a cart (woocommerce_coupons_enabled:
 *    the cart form, the checkout's «Have a coupon?» notice and the blocks);
 *  - no_reward: a trade order (order meta _tra_trade) gets no thank-you
 *    coupon. Thanko Thank You Page Customizer (VillaTheme) is reached
 *    through its own parameter filters: on the thank-you page of a trade
 *    order its coupon type reads "none" and its coupon block is left out,
 *    so no coupon is made, shown or e-mailed. Checkout Extras through
 *    cke_coupon_args (false = no coupon).
 *
 * Pages with wholesale prices are never cached (DONOTCACHEPAGE).
 */

defined( 'ABSPATH' ) || exit;

final class TRA_Pricing {

	/** @var bool|null Per-request cache of applies(). */
	private static $applies = null;

	/** @var bool True inside a Store API request (/wc/store/…). */
	private static $store_api = false;

	/** @var bool Guard: no price filter while a price is computed. */
	private static $busy = false;

	public static function init(): void {
		if ( Trade_Accounts::killed() || 'off' === TRA_Settings::get()['mode'] ) {
			return;
		}

		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'rest_in' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'rest_out' ), 10, 3 );
		add_action( 'set_current_user', array( __CLASS__, 'reset' ) );

		foreach ( array( 'woocommerce_product_get_price', 'woocommerce_product_variation_get_price', 'woocommerce_product_get_sale_price', 'woocommerce_product_variation_get_sale_price' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'price' ), 20, 2 );
		}
		add_filter( 'woocommerce_product_is_on_sale', array( __CLASS__, 'on_sale' ), 20, 2 );
		add_filter( 'woocommerce_variation_prices_price', array( __CLASS__, 'variation_price' ), 20, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( __CLASS__, 'variation_price' ), 20, 3 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'prices_hash' ), 20, 3 );
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 20, 2 );

		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'mark_line' ), 20, 4 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'check_invoice' ), 20, 2 );
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'invoice_default' ), 20, 2 );

		add_action( 'template_redirect', array( __CLASS__, 'no_page_cache' ), 1 );

		$s = TRA_Settings::get();
		if ( $s['no_coupons'] ) {
			add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'coupon_valid' ), 20, 3 );
			add_filter( 'woocommerce_coupons_enabled', array( __CLASS__, 'coupons_enabled' ), 20 );
		}
		if ( $s['no_reward'] ) {
			add_filter( 'woo_thank_you_page_paramscoupon_type', array( __CLASS__, 'thanko_type' ), 20 );
			add_filter( 'woo_thank_you_page_paramsblocks', array( __CLASS__, 'thanko_blocks' ), 20 );
			add_filter( 'cke_coupon_args', array( __CLASS__, 'cke_coupon' ), 20, 2 );
		}
	}

	public static function reset(): void {
		self::$applies = null;
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

	/** Whether the current request shows wholesale prices to the current user. */
	public static function applies(): bool {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && ! self::$store_api ) {
			return false;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		if ( null !== self::$applies && did_action( 'set_current_user' ) ) {
			return self::$applies;
		}
		$mode = TRA_Settings::get()['mode'];
		$on   = false;
		if ( 'live' === $mode ) {
			$on = TRA_Accounts::is_trade();
		} elseif ( 'test' === $mode ) {
			$on = current_user_can( Trade_Accounts::cap() );
		}
		if ( did_action( 'set_current_user' ) ) {
			self::$applies = $on;
		}
		return $on;
	}

	/** The trade price of the product for the current user, or null. */
	private static function trade( WC_Product $product ): ?float {
		if ( self::$busy || ! self::applies() || ! TRA_Scope::eligible( $product ) ) {
			return null;
		}
		self::$busy = true;
		try {
			$price = TRA_Scope::trade_price( $product, TRA_Accounts::discount() );
		} catch ( \Throwable $e ) {
			$price = null;
		}
		self::$busy = false;
		return $price;
	}

	public static function price( $price, $product ) {
		if ( ! $product instanceof WC_Product || '' === (string) $price && ! $product->get_regular_price( 'edit' ) ) {
			return $price;
		}
		$t = self::trade( $product );
		return null === $t ? $price : wc_format_decimal( $t, wc_get_price_decimals() );
	}

	public static function on_sale( $on, $product ) {
		if ( ! $product instanceof WC_Product || $product->is_type( 'variable' ) ) {
			return $on;
		}
		$t = self::trade( $product );
		if ( null === $t ) {
			return $on;
		}
		return $t < (float) $product->get_regular_price( 'edit' );
	}

	public static function variation_price( $price, $variation, $product ) {
		if ( ! $variation instanceof WC_Product ) {
			return $price;
		}
		$t = self::trade( $variation );
		return null === $t ? $price : wc_format_decimal( $t, wc_get_price_decimals() );
	}

	/** Variation price caches are per hash: trade users get their own. */
	public static function prices_hash( $hash, $product, $for_display ) {
		if ( self::applies() ) {
			$hash   = (array) $hash;
			$hash[] = 'tra-' . TRA_Accounts::discount() . '-' . TRA_Settings::get()['base'] . '-' . md5( wp_json_encode( TRA_Settings::get()['cats'] ) . wp_json_encode( TRA_Settings::get()['brands'] ) );
		}
		return $hash;
	}

	public static function price_html( $html, $product ) {
		if ( ! TRA_Settings::get()['label'] || ! $product instanceof WC_Product || '' === (string) $html ) {
			return $html;
		}
		try {
			$check = $product;
			if ( $product->is_type( 'variable' ) ) {
				$kids  = $product->get_visible_children();
				$check = $kids ? wc_get_product( (int) $kids[0] ) : null;
			}
			if ( ! $check instanceof WC_Product || null === self::trade( $check ) ) {
				return $html;
			}
			return $html . ' <small class="tra-trade-label">' . esc_html( TRA_Settings::text( 'label' ) ) . '</small>';
		} catch ( \Throwable $e ) {
			return $html;
		}
	}

	/** Classic checkout and Store API both fire this for every line. */
	public static function mark_line( $item, $cart_item_key, $values, $order ): void {
		try {
			if ( ! $item instanceof WC_Order_Item_Product || ! isset( $values['data'] ) || ! $values['data'] instanceof WC_Product ) {
				return;
			}
			if ( null === self::trade( $values['data'] ) ) {
				return;
			}
			$d = TRA_Accounts::discount();
			$item->add_meta_data( '_tra_discount', (string) $d, true );
			$item->add_meta_data( TRA_Settings::text( 'label' ), '−' . wc_format_localized_decimal( (string) $d ) . '%', true );
			if ( $order instanceof WC_Order ) {
				$order->update_meta_data( '_tra_trade', (string) $d );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** Whether the cart holds a line at a trade price. */
	private static function trade_cart(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $line ) {
			if ( isset( $line['data'] ) && $line['data'] instanceof WC_Product && null !== self::trade( $line['data'] ) ) {
				return true;
			}
		}
		return false;
	}

	public static function check_invoice( $data, $errors ): void {
		$s = TRA_Settings::get();
		if ( ! $s['invoice'] || ! $errors instanceof WP_Error || ! self::applies() || ! self::trade_cart() ) {
			return;
		}
		// WooCommerce verified the checkout nonce before this hook.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$v = isset( $_POST[ $s['inv_field'] ] ) && is_scalar( $_POST[ $s['inv_field'] ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $s['inv_field'] ] ) ) : '';
		if ( $v !== $s['inv_value'] ) {
			$errors->add( 'tra_invoice', esc_html( TRA_Settings::text( 'invoice' ) ) );
		}
	}

	public static function invoice_default( $value, $input ) {
		$s = TRA_Settings::get();
		if ( null === $value && $s['invoice'] && $input === $s['inv_field'] && self::applies() ) {
			return $s['inv_value'];
		}
		return $value;
	}

	/* ---------------------------------------------------------------------
	 * Coupons
	 * ------------------------------------------------------------------- */

	/**
	 * No coupon in a cart with trade lines. WooCommerce turns the exception
	 * into the cart notice and drops a coupon applied earlier.
	 *
	 * @throws Exception When the cart holds a line at a trade price.
	 */
	public static function coupon_valid( $valid, $coupon, $discounts ) {
		if ( ! $valid || ! self::applies() || ! self::trade_cart() ) {
			return $valid;
		}
		throw new Exception( esc_html( TRA_Settings::text( 'coupon' ) ), 100 );
	}

	/** No coupon field for a cart with trade lines: a coupon would be refused anyway. */
	public static function coupons_enabled( $enabled ) {
		if ( ! $enabled || ! self::applies() || ! self::trade_cart() ) {
			return $enabled;
		}
		return false;
	}

	/** Whether an order is a trade order (it has a line at a trade price). */
	private static function trade_order( $order ): bool {
		$order = is_numeric( $order ) ? wc_get_order( (int) $order ) : $order;
		return $order instanceof WC_Order && '' !== (string) $order->get_meta( '_tra_trade' );
	}

	/** The order of the thank-you page being shown, if it is a trade order. */
	private static function thankyou_trade(): bool {
		global $wp;
		$id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
		return $id > 0 && self::trade_order( $id );
	}

	/** Thanko: no coupon type for a trade order, so none is made or e-mailed. */
	public static function thanko_type( $value ) {
		return self::thankyou_trade() ? array( 'none' ) : $value;
	}

	/** Thanko: its coupon block left out of the layout for a trade order. */
	public static function thanko_blocks( $value ) {
		if ( ! is_string( $value ) || false === strpos( $value, 'coupon' ) || ! self::thankyou_trade() ) {
			return $value;
		}
		$blocks = json_decode( $value, true );
		if ( ! is_array( $blocks ) ) {
			return $value;
		}
		$json = wp_json_encode( self::without_coupon( $blocks ) );
		return false === $json ? $value : $json;
	}

	private static function without_coupon( array $a ): array {
		$list = array_keys( $a ) === range( 0, count( $a ) - 1 );
		$out  = array();
		foreach ( $a as $k => $v ) {
			if ( 'coupon' === $v ) {
				continue;
			}
			$out[ $k ] = is_array( $v ) ? self::without_coupon( $v ) : $v;
		}
		return $list ? array_values( $out ) : $out;
	}

	/** Checkout Extras: no thank-you coupon for a trade order. */
	public static function cke_coupon( $args, $order ) {
		return self::trade_order( $order ) ? false : $args;
	}

	public static function no_page_cache(): void {
		if ( self::applies() && ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}
}

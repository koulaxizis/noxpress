<?php
/**
 * SHN_Rules — shipping rules for made-to-order (partner) products (1.5.0).
 *
 * A store that sells its own goods with free shipping over an amount, and
 * print-on-demand goods (e.g. Printful) that the partner sends itself, needs
 * two rules WooCommerce cannot express alone:
 *
 *  1. Partner products always pay shipping: one flat fee per order (a
 *     higher one when the cart holds a "large" product, e.g. a backpack),
 *     whatever the quantity. The fee replaces the partner's live rates on
 *     the partner's package (woocommerce_package_rates, priority 20000,
 *     after the Printful plugin added its rates). The partner rate keeps its
 *     id and method id (e.g. printful_shipping_STANDARD), so the partner's
 *     order import still reads the method it expects; only its cost, taxes
 *     and label change. Only the cheapest partner rate is kept.
 *     When the cart was not split (the partner's API did not answer) and
 *     one package holds partner and own products, the fee is added to every
 *     rate of that package, so partner products never ship free.
 *
 *  2. Only some products count toward free shipping: never the partner's
 *     products nor virtual / downloadable ones, and, when categories are chosen, only products of those
 *     categories (and their sub-categories). Carrier methods with a "free
 *     over X" of their own count the whole cart (BOX NOW Delivery compares
 *     the cart subtotal); when the amount that counts is below X, the
 *     carrier rate gets its cost back. WooCommerce's Free shipping rates
 *     that need an amount are removed when the amount that counts is below
 *     it (a free-shipping coupon keeps them, as WooCommerce does). The bar
 *     and the product-page line of the free-shipping part use the same
 *     amount (SHN_Threshold::counted_total()).
 *
 * Signals (Bible §1.1): partner products = products of the chosen shipping
 * classes, plus every product of a package SHN_Parcels::is_partner_package()
 * recognises (the $package['printful'] flag). Filters: shn_rules_partner_product,
 * shn_rules_counts, shn_rules_fee.
 *
 * Rules (Bible §14): off until enabled, test mode for shop managers,
 * SHN_DISABLE (not initialised); runs while WooCommerce calculates the cart
 * (page views, cart AJAX, Store API cart); no write; every callback in
 * try/catch with the original rates as the fallback. WooCommerce caches
 * package rates per package content and cart subtotal; saving the settings
 * bumps the shipping cache version so new rules apply at once.
 */

defined( 'ABSPATH' ) || exit;

final class SHN_Rules {

	/** @var bool Stops the part for the rest of the request after an error. */
	private static $broken = false;

	/** @var array<int,int[]>|null Chosen category ids with their descendants. */
	private static $cats = null;

	public static function init(): void {
		if ( 'off' === SHN_Settings::get()['sr_mode'] ) {
			return;
		}
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'package_rates' ), 20000, 2 );
	}

	/** The rules apply to the current request (mode, test mode, context). */
	public static function on(): bool {
		if ( self::$broken || Shipping_Notes::killed() ) {
			return false;
		}
		$mode = SHN_Settings::get()['sr_mode'];
		if ( 'off' === $mode || ! self::context_ok() ) {
			return false;
		}
		if ( 'test' === $mode ) {
			return did_action( 'set_current_user' ) && current_user_can( 'manage_woocommerce' );
		}
		return true;
	}

	/**
	 * Where WooCommerce calculates a visitor's cart: page views, its cart and
	 * checkout AJAX (wc-ajax), and the Store API of the Cart / Checkout
	 * blocks. The rules decide what an order costs, so unlike the messages
	 * they also run in the Store API (place order included).
	 */
	private static function context_ok(): bool {
		if ( wp_doing_cron() || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			if ( function_exists( 'WC' ) && method_exists( WC(), 'is_store_api_request' ) ) {
				return (bool) WC()->is_store_api_request();
			}
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
			return false !== strpos( $uri, '/wc/store/' );
		}
		if ( wp_doing_ajax() ) {
			return true;
		}
		return ! is_admin();
	}

	private static function fail( \Throwable $e ): void {
		self::$broken = true;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only.
			error_log( 'Shipping Notes (rules): ' . $e->getMessage() );
		}
	}

	/* =====================================================================
	 * Products
	 * =================================================================== */

	/** True when a product is the partner's (made to order). */
	public static function is_partner_product( WC_Product $product ): bool {
		$classes = SHN_Settings::get()['sr_classes'];
		$partner = $classes && in_array( (string) $product->get_shipping_class(), $classes, true );
		/**
		 * Filters whether a product is a partner (made-to-order) product.
		 *
		 * @param bool       $partner Default: its shipping class is one of the chosen ones.
		 * @param WC_Product $product Product (a variation reads its parent's class when it has none).
		 */
		return (bool) apply_filters( 'shn_rules_partner_product', $partner, $product );
	}

	/** True when a product uses the large-product fee. */
	public static function is_large_product( WC_Product $product ): bool {
		$classes = SHN_Settings::get()['sr_large_classes'];
		return $classes && in_array( (string) $product->get_shipping_class(), $classes, true );
	}

	/** True when a product counts toward free shipping (physical, not the partner's, in a chosen category). */
	public static function counts( WC_Product $product ): bool {
		// Virtual and downloadable products (ebooks, digital albums) ship nothing.
		$counts = $product->needs_shipping() && ! self::is_partner_product( $product );
		if ( $counts && SHN_Settings::get()['sr_cats'] ) {
			$id     = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
			$counts = $id > 0 && (bool) array_intersect( wc_get_product_term_ids( $id, 'product_cat' ), self::cat_ids() );
		}
		/**
		 * Filters whether a product counts toward free shipping.
		 *
		 * @param bool       $counts  Default: needs shipping, not a partner product and, when categories are chosen, in one of them.
		 * @param WC_Product $product Product.
		 */
		return (bool) apply_filters( 'shn_rules_counts', $counts, $product );
	}

	/** The chosen categories and all their sub-categories. */
	private static function cat_ids(): array {
		if ( null === self::$cats ) {
			$ids = array();
			foreach ( SHN_Settings::get()['sr_cats'] as $id ) {
				$ids[] = (int) $id;
				$kids  = get_term_children( (int) $id, 'product_cat' );
				if ( is_array( $kids ) ) {
					$ids = array_merge( $ids, array_map( 'intval', $kids ) );
				}
			}
			self::$cats = array_values( array_unique( $ids ) );
		}
		return self::$cats;
	}

	/* =====================================================================
	 * Amounts
	 * =================================================================== */

	/**
	 * The amount that counts toward free shipping, as
	 * WC_Shipping_Free_Shipping::is_available() would compare it, but only
	 * over the products that count: their displayed line subtotals, minus
	 * their coupon discounts unless $ignore_discounts is 'yes'.
	 */
	public static function counted_total( WC_Cart $cart, string $ignore_discounts = 'no' ): float {
		$incl  = $cart->display_prices_including_tax();
		$total = 0.0;
		foreach ( $cart->get_cart() as $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product instanceof WC_Product || ! self::counts( $product ) ) {
				continue;
			}
			if ( 'yes' === $ignore_discounts ) {
				$total += (float) ( $item['line_subtotal'] ?? 0 ) + ( $incl ? (float) ( $item['line_subtotal_tax'] ?? 0 ) : 0.0 );
			} else {
				$total += (float) ( $item['line_total'] ?? 0 ) + ( $incl ? (float) ( $item['line_tax'] ?? 0 ) : 0.0 );
			}
		}
		return round( $total, wc_get_price_decimals() );
	}

	/** True when the cart holds a product that needs shipping and is not the partner's. */
	public static function has_own_shipping( WC_Cart $cart ): bool {
		foreach ( $cart->get_cart() as $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( $product instanceof WC_Product && $product->needs_shipping() && ! self::is_partner_product( $product ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The fee of a set of partner products, in the store currency, as the
	 * customer pays it: the large fee when one of them is large, else the
	 * standard fee. 0 = keep the partner's own rates.
	 *
	 * @param WC_Product[] $products Partner products of the package.
	 */
	public static function fee( array $products ): float {
		$s     = SHN_Settings::get();
		$large = false;
		foreach ( $products as $product ) {
			if ( $product instanceof WC_Product && self::is_large_product( $product ) ) {
				$large = true;
				break;
			}
		}
		$fee = $large && $s['sr_fee_large'] > 0 ? (float) $s['sr_fee_large'] : (float) $s['sr_fee'];
		/**
		 * Filters the partner shipping fee of a package.
		 *
		 * @param float        $fee      Fee as the customer pays it (0 = the partner's rates).
		 * @param WC_Product[] $products Partner products of the package.
		 * @param bool         $large    One of them uses the large fee.
		 */
		return max( 0.0, (float) apply_filters( 'shn_rules_fee', $fee, $products, $large ) );
	}

	/**
	 * Cost and taxes of a fee: with 'incl' the fee is the gross amount and
	 * the shipping tax is taken out of it; with 'none' it carries no tax.
	 *
	 * @return array{0: float, 1: array} Cost and taxes (rate id => amount).
	 */
	private static function cost_taxes( float $fee ): array {
		if ( 'incl' !== SHN_Settings::get()['sr_tax'] || ! wc_tax_enabled() ) {
			return array( $fee, array() );
		}
		$rates = WC_Tax::get_shipping_tax_rates();
		if ( ! $rates ) {
			return array( $fee, array() );
		}
		$taxes = WC_Tax::calc_inclusive_tax( $fee, $rates );
		$cost  = $fee - array_sum( $taxes );
		return array( round( $cost, wc_get_rounding_precision() ), $taxes );
	}

	/* =====================================================================
	 * Rates
	 * =================================================================== */

	/**
	 * woocommerce_package_rates: the partner fee and the free-shipping rules.
	 *
	 * @param WC_Shipping_Rate[] $rates   Rates of the package.
	 * @param array              $package Package.
	 */
	public static function package_rates( $rates, $package = array() ) {
		try {
			if ( ! is_array( $rates ) || ! $rates || ! is_array( $package ) || ! self::on() || ! function_exists( 'WC' ) || ! WC()->cart instanceof WC_Cart ) {
				return $rates;
			}
			$partner_package = SHN_Parcels::is_partner_package( $package );
			$partner         = array();
			$own             = 0;
			foreach ( (array) ( $package['contents'] ?? array() ) as $item ) {
				$product = is_array( $item ) && isset( $item['data'] ) ? $item['data'] : null;
				if ( ! $product instanceof WC_Product || ! $product->needs_shipping() ) {
					continue;
				}
				if ( $partner_package || self::is_partner_product( $product ) ) {
					$partner[] = $product;
				} else {
					++$own;
				}
			}

			if ( $partner && ! $own ) {
				return self::partner_rates( $rates, $partner );
			}
			$rates = self::own_rates( $rates, $package );
			if ( $partner ) {
				$rates = self::add_fee( $rates, $partner );
			}
			return $rates;
		} catch ( \Throwable $e ) {
			self::fail( $e );
			return $rates;
		}
	}

	/** A package of partner products only: one rate at the fee. */
	private static function partner_rates( array $rates, array $products ): array {
		$fee = self::fee( $products );
		if ( $fee <= 0 ) {
			return $rates;
		}
		// The cheapest partner rate (its id is what the partner's import reads);
		// without one (the store's own methods), the cheapest rate.
		$pick = null;
		foreach ( array( true, false ) as $want_partner ) {
			foreach ( $rates as $key => $rate ) {
				if ( ! $rate instanceof WC_Shipping_Rate || SHN_Parcels::is_partner_rate( (string) $rate->get_method_id() ) !== $want_partner ) {
					continue;
				}
				if ( null === $pick || (float) $rate->get_cost() < (float) $rates[ $pick ]->get_cost() ) {
					$pick = $key;
				}
			}
			if ( null !== $pick ) {
				break;
			}
		}
		if ( null === $pick ) {
			return $rates;
		}
		$rate = $rates[ $pick ];
		list( $cost, $taxes ) = self::cost_taxes( $fee );
		$rate->set_cost( (string) $cost );
		$rate->set_taxes( $taxes );
		$label = SHN_Settings::text( 'sr_t_label' );
		if ( '' !== $label ) {
			$rate->set_label( $label );
		}
		return array( $pick => $rate );
	}

	/** A package that was not split: the fee on top of every rate. */
	private static function add_fee( array $rates, array $products ): array {
		$fee = self::fee( $products );
		if ( $fee <= 0 ) {
			return $rates;
		}
		foreach ( $rates as $rate ) {
			if ( $rate instanceof WC_Shipping_Rate ) {
				// Gross, without tax of its own: a fallback for a partner outage.
				$rate->set_cost( (string) ( (float) $rate->get_cost() + $fee ) );
			}
		}
		return $rates;
	}

	/**
	 * Free shipping by the products that count: carrier rates that turned
	 * free over their own amount get their cost back, and Free shipping
	 * rates whose amount is not reached are removed.
	 */
	private static function own_rates( array $rates, array $package ): array {
		$cart = WC()->cart;
		foreach ( $rates as $key => $rate ) {
			if ( ! $rate instanceof WC_Shipping_Rate ) {
				continue;
			}
			$method = self::method( $rate );
			if ( ! $method ) {
				continue;
			}
			if ( $method instanceof WC_Shipping_Free_Shipping ) {
				if ( ! self::free_method_ok( $method, $cart ) ) {
					unset( $rates[ $key ] );
				}
				continue;
			}
			$over = SHN_Threshold::carrier_threshold( $method );
			if ( $over <= 0 || (float) $rate->get_cost() > 0 ) {
				continue;
			}
			if ( self::counted_total( $cart, 'no' ) < $over ) {
				$cost = (float) wc_format_decimal( (string) $method->get_option( 'cost', '0' ) );
				if ( $cost > 0 ) {
					$rate->set_cost( (string) $cost );
					$taxable = 'yes' === $method->get_option( 'taxable', 'no' );
					$rate->set_taxes( $taxable && wc_tax_enabled() ? WC_Tax::calc_shipping_tax( $cost, WC_Tax::get_shipping_tax_rates() ) : array() );
				}
			}
		}
		return $rates;
	}

	/** A Free shipping method is still due with the amount that counts. */
	public static function free_method_ok( WC_Shipping_Free_Shipping $method, WC_Cart $cart ): bool {
		$requires = (string) $method->requires;
		if ( '' === $requires || 'coupon' === $requires ) {
			return true; // No amount involved.
		}
		$min    = (float) wc_format_decimal( (string) $method->min_amount );
		$enough = $min <= 0 || self::counted_total( $cart, 'yes' === $method->ignore_discounts ? 'yes' : 'no' ) >= $min;
		if ( 'min_amount' === $requires || 'both' === $requires ) {
			return $enough; // 'both': WooCommerce already checked the coupon.
		}
		// 'either': a free-shipping coupon is enough on its own.
		if ( $enough ) {
			return true;
		}
		foreach ( $cart->get_coupons() as $coupon ) {
			if ( $coupon instanceof WC_Coupon && $coupon->get_free_shipping() ) {
				return true;
			}
		}
		return false;
	}

	/** The zone method instance of a rate, or null. */
	private static function method( WC_Shipping_Rate $rate ) {
		$id = (int) $rate->get_instance_id();
		if ( $id <= 0 ) {
			return null;
		}
		$method = WC_Shipping_Zones::get_shipping_method( $id );
		return $method instanceof WC_Shipping_Method ? $method : null;
	}
}

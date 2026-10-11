<?php
/**
 * SHN_Threshold — "X left for free shipping" from WooCommerce's own rules
 * (design §3.1–§3.3).
 *
 *  - Zone: the one WooCommerce matches for the cart's first shipping
 *    package (the customer's address, or the default customer location).
 *  - Threshold: the lowest min_amount of the zone's enabled free_shipping
 *    methods that ask for an amount ('min_amount', 'either', 'both');
 *    'both' counts only while a free-shipping coupon is applied. Manual
 *    sources: an amount per zone without free shipping, or one amount.
 *  - Amount that counts: a line-by-line copy of
 *    WC_Shipping_Free_Shipping::is_available() — the displayed subtotal
 *    (with or without tax, as the cart shows prices), minus coupon
 *    discounts (and their tax when prices show with tax) unless the
 *    method applies the rule before discounts, rounded to the store's
 *    decimals.
 *  - "Won" is confirmed by the methods' own is_available(), so filters of
 *    other plugins count and the bar never promises what checkout denies.
 *  - Hidden when the cart needs no shipping (virtual items only). Mixed
 *    carts count every item, as WooCommerce does.
 */

defined( 'ABSPATH' ) || exit;

final class SHN_Threshold {

	/**
	 * State of the current cart, or null when nothing should show.
	 *
	 * @return array|null { state: 'empty'|'left'|'won', threshold, total, remaining, percent }.
	 */
	public static function for_cart(): ?array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof WC_Cart ) {
			return null;
		}
		$cart = WC()->cart;
		if ( ! $cart->is_empty() && ! $cart->needs_shipping() ) {
			return null;
		}
		$packages = $cart->get_shipping_packages();
		$package  = is_array( $packages ) && $packages ? reset( $packages ) : array();
		if ( ! is_array( $package ) || ! isset( $package['destination'] ) ) {
			return null;
		}
		$zone = WC_Shipping_Zones::get_zone_matching_package( $package );
		$rule = self::rule( $zone, $cart );
		if ( null === $rule ) {
			return null;
		}

		if ( $cart->is_empty() ) {
			return $rule['threshold'] > 0 ? self::state( 'empty', $rule['threshold'], 0.0 ) : null;
		}

		$total = self::counted_total( $cart, $rule['ignore_discounts'] );
		$won   = $rule['threshold'] > 0 && $total >= $rule['threshold'];

		// WooCommerce has the last word: a free method available now means "won"
		// (coupon, "always free", filters); none available means not won.
		if ( $rule['methods'] ) {
			$available = false;
			foreach ( $rule['methods'] as $method ) {
				if ( $method->is_available( $package ) ) {
					$available = true;
					break;
				}
			}
			if ( $available ) {
				$won = true;
			} elseif ( $won && 'auto' === $rule['source'] ) {
				return null; // Another plugin refuses free shipping: say nothing rather than something wrong.
			}
		}

		if ( $won ) {
			return self::state( 'won', $rule['threshold'], $total );
		}
		return $rule['threshold'] > 0 ? self::state( 'left', $rule['threshold'], $total ) : null;
	}

	/**
	 * The rule of a zone: threshold (0 = only "won" can show), the free
	 * methods to confirm with, and their discount setting. Null = nothing to show.
	 *
	 * @param WC_Shipping_Zone $zone Zone.
	 * @param WC_Cart|null     $cart Cart (for 'both': is a free-shipping coupon applied?).
	 */
	public static function rule( $zone, ?WC_Cart $cart = null ): ?array {
		$s       = SHN_Settings::get();
		$zone_id = (int) $zone->get_id();

		$methods   = array();
		$threshold = 0.0;
		$ignore    = 'no';
		$always    = false;

		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			if ( ! $method instanceof WC_Shipping_Free_Shipping ) {
				continue;
			}
			$requires = (string) $method->requires;
			if ( 'coupon' === $requires ) {
				continue; // No amount to reach.
			}
			if ( '' === $requires ) {
				$always    = true;
				$methods[] = $method;
				continue;
			}
			if ( 'both' === $requires && ! self::has_free_coupon( $cart ) ) {
				continue; // Design question 6: only once the coupon is there.
			}
			$min = (float) wc_format_decimal( (string) $method->min_amount );
			if ( $min <= 0 ) {
				continue;
			}
			$methods[] = $method;
			if ( 0.0 === $threshold || $min < $threshold ) {
				$threshold = $min;
				$ignore    = 'yes' === $method->ignore_discounts ? 'yes' : 'no';
			}
		}

		$source = $s['fs_source'];
		if ( 'fixed' === $source ) {
			$threshold = (float) $s['fs_fixed'];
			$ignore    = 'no';
		} elseif ( 'zones' === $source && $threshold <= 0 && isset( $s['fs_zones'][ $zone_id ] ) ) {
			$threshold = (float) $s['fs_zones'][ $zone_id ];
			$ignore    = 'no';
		}

		/**
		 * Filters the free-shipping threshold of a zone (e.g. for another currency).
		 *
		 * @param float            $threshold Amount in the store currency (0 = none).
		 * @param WC_Shipping_Zone $zone      Zone.
		 */
		$threshold = (float) apply_filters( 'shn_threshold', $threshold, $zone );

		if ( $threshold <= 0 && ! $always ) {
			return null;
		}
		return array(
			'threshold'        => max( 0.0, $threshold ),
			'methods'          => $methods,
			'ignore_discounts' => $ignore,
			'source'           => $source,
			'zone_id'          => $zone_id,
		);
	}

	/** Copy of the amount WC_Shipping_Free_Shipping::is_available() compares. */
	public static function counted_total( WC_Cart $cart, string $ignore_discounts ): float {
		$total = (float) $cart->get_displayed_subtotal();
		if ( 'no' === $ignore_discounts ) {
			$total = $total - (float) $cart->get_discount_total();
			if ( $cart->display_prices_including_tax() ) {
				$total = $total - (float) $cart->get_discount_tax();
			}
		}
		return round( $total, wc_get_price_decimals() );
	}

	private static function has_free_coupon( ?WC_Cart $cart ): bool {
		if ( ! $cart ) {
			return false;
		}
		foreach ( $cart->get_coupons() as $coupon ) {
			if ( $coupon instanceof WC_Coupon && $coupon->is_valid() && $coupon->get_free_shipping() ) {
				return true;
			}
		}
		return false;
	}

	/** State array for a threshold and a counted amount. */
	public static function state( string $state, float $threshold, float $total ): array {
		$remaining = max( 0.0, round( $threshold - $total, wc_get_price_decimals() ) );
		$percent   = 'won' === $state ? 100 : ( $threshold > 0 ? (int) floor( min( 100, max( 0, $total / $threshold * 100 ) ) ) : 0 );
		return array(
			'state'     => $state,
			'threshold' => $threshold,
			'total'     => $total,
			'remaining' => $remaining,
			'percent'   => $percent,
		);
	}

	/** Zone of the store's own address (product pages, previews). */
	public static function base_zone() {
		$c = WC()->countries;
		return WC_Shipping_Zones::get_zone_matching_package(
			array(
				'destination' => array(
					'country'  => $c->get_base_country(),
					'state'    => $c->get_base_state(),
					'postcode' => $c->get_base_postcode(),
					'city'     => $c->get_base_city(),
					'address'  => '',
				),
			)
		);
	}

	/** All zones, "Locations not covered" (id 0) last: id => WC_Shipping_Zone. */
	public static function zones(): array {
		$out = array();
		foreach ( WC_Shipping_Zones::get_zones() as $z ) {
			$zone = WC_Shipping_Zones::get_zone( (int) $z['id'] );
			if ( $zone ) {
				$out[ (int) $z['id'] ] = $zone;
			}
		}
		$out[0] = WC_Shipping_Zones::get_zone( 0 );
		return $out;
	}
}

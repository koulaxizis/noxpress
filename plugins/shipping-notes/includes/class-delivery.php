<?php
/**
 * SHN_Delivery — "Delivery in X–Y business days" for a product (design §4).
 *
 * Delivery = preparation + transit, in business days.
 *  - Transit: per shipping method instance of the product-page zone
 *    (setting dl_zone; -1 = the zone of the store's own address, the same
 *    for every visitor so page caches stay correct). Methods without
 *    days are left out.
 *  - Preparation: product > product category (the slowest of the
 *    product's categories and their parents) > store; products on
 *    backorder use the backorder days when they are set.
 *  - Pre-orders: a release date (product field, or the filter
 *    shn_available_from) moves the start to that date.
 *
 * The server always prints the business-days text, which is true at any
 * time a cached page is read. Dates and the cut-off countdown are
 * computed in the browser (assets/delivery.js) from data attributes.
 */

defined( 'ABSPATH' ) || exit;

final class SHN_Delivery {

	/**
	 * Estimate data for a product, or null when nothing should show
	 * (virtual, external, not in stock, no configured method, no working day).
	 *
	 * @return array|null { lines: [ { method, pmin, pmax, tmin, tmax, sat } ], prep: [min,max], from: 'Y-m-d'|'' }.
	 */
	public static function for_product( $product ): ?array {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		$s = SHN_Settings::get();
		if ( ! $s['dl_workdays'] || $product->is_type( 'external' ) || $product->is_type( 'grouped' ) ) {
			return null;
		}
		if ( $product->is_virtual() || ( ! $product->is_in_stock() && ! $product->is_on_backorder() ) ) {
			return null;
		}

		$prep  = self::prep( $product );
		$lines = array();
		foreach ( self::methods() as $m ) {
			$lines[] = array(
				'method' => $m['title'],
				'pmin'   => $prep[0],
				'pmax'   => $prep[1],
				'tmin'   => $m['min'],
				'tmax'   => $m['max'],
				'sat'    => $m['sat'],
			);
		}
		if ( ! $lines ) {
			return null;
		}

		$from = self::available_from( $product );
		return array(
			'lines' => $lines,
			'prep'  => $prep,
			'from'  => $from,
		);
	}

	/**
	 * Configured methods of the product-page zone, in the zone's order.
	 *
	 * @return array[] { id, title, min, max, sat }.
	 */
	public static function methods(): array {
		$s    = SHN_Settings::get();
		$zone = self::zone();
		$out  = array();
		if ( ! $zone ) {
			return $out;
		}
		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			$iid = (int) $method->get_instance_id();
			if ( ! isset( $s['dl_methods'][ $iid ] ) ) {
				continue;
			}
			$cfg   = $s['dl_methods'][ $iid ];
			$out[] = array(
				'id'    => $iid,
				'title' => wp_strip_all_tags( (string) $method->get_title() ),
				'min'   => (int) $cfg['min'],
				'max'   => (int) $cfg['max'],
				'sat'   => ! empty( $cfg['sat'] ),
			);
		}
		return $out;
	}

	/** The zone used on product pages. */
	public static function zone() {
		$id = (int) SHN_Settings::get()['dl_zone'];
		if ( $id >= 0 ) {
			$zone = WC_Shipping_Zones::get_zone( $id );
			if ( $zone ) {
				return $zone;
			}
		}
		return SHN_Threshold::base_zone();
	}

	/** Preparation days [min, max] of a product. */
	public static function prep( WC_Product $product ): array {
		$s  = SHN_Settings::get();
		$id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();

		$own = self::meta_range( get_post_meta( $id, SHN_Settings::META_PREP_MIN, true ), get_post_meta( $id, SHN_Settings::META_PREP_MAX, true ) );
		if ( null !== $own ) {
			$prep = $own;
		} else {
			$prep = self::category_prep( $id );
			if ( null === $prep ) {
				$prep = array( (int) $s['dl_prep_min'], (int) $s['dl_prep_max'] );
			}
		}
		if ( ! empty( $s['dl_bo'] ) && $product->is_on_backorder() ) {
			$prep = array( max( $prep[0], (int) $s['dl_bo_min'] ), max( $prep[1], (int) $s['dl_bo_max'] ) );
		}
		return $prep;
	}

	/** The slowest preparation among the product's categories and their parents, or null. */
	private static function category_prep( int $product_id ): ?array {
		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( ! is_array( $terms ) ) {
			return null;
		}
		$ids = array();
		foreach ( $terms as $t ) {
			$ids[ (int) $t->term_id ] = true;
			foreach ( get_ancestors( (int) $t->term_id, 'product_cat', 'taxonomy' ) as $a ) {
				$ids[ (int) $a ] = true;
			}
		}
		$best = null;
		foreach ( array_keys( $ids ) as $tid ) {
			$r = self::meta_range( get_term_meta( $tid, SHN_Settings::TERM_PREP_MIN, true ), get_term_meta( $tid, SHN_Settings::TERM_PREP_MAX, true ) );
			if ( null !== $r && ( null === $best || $r[1] > $best[1] || ( $r[1] === $best[1] && $r[0] > $best[0] ) ) ) {
				$best = $r;
			}
		}
		return $best;
	}

	/** [min, max] from two stored fields, or null when both are empty. */
	public static function meta_range( $min, $max ): ?array {
		$a = SHN_Settings::day_or_null( $min );
		$b = SHN_Settings::day_or_null( $max );
		if ( null === $a && null === $b ) {
			return null;
		}
		return SHN_Settings::range( null === $a ? $b : $a, null === $b ? $a : $b );
	}

	/** Release date 'Y-m-d' while it is in the future, else ''. */
	public static function available_from( WC_Product $product ): string {
		$id   = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$date = (string) get_post_meta( $id, SHN_Settings::META_FROM, true );
		/**
		 * Filters the release date of a product (e.g. from a pre-order plugin).
		 *
		 * @param string     $date    'Y-m-d' or ''.
		 * @param WC_Product $product Product.
		 */
		$date = (string) apply_filters( 'shn_available_from', $date, $product );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}
		return $date > wp_date( 'Y-m-d' ) ? $date : '';
	}

	/** Overall [min, max] business days of an estimate. */
	public static function span( array $est ): array {
		$min = PHP_INT_MAX;
		$max = 0;
		foreach ( $est['lines'] as $l ) {
			$min = min( $min, $l['pmin'] + $l['tmin'] );
			$max = max( $max, $l['pmax'] + $l['tmax'] );
		}
		return array( $min, $max );
	}

	/** "1–3 εργάσιμες", "2 εργάσιμες", "1 εργάσιμη" with the visitor texts. */
	public static function days_text( int $min, int $max ): string {
		$unit = ( 1 === $min && 1 === $max ) ? SHN_Settings::text( 'dl_t_one' ) : SHN_Settings::text( 'dl_t_many' );
		$num  = $min === $max ? (string) $min : $min . '–' . $max;
		return trim( $num . ' ' . $unit );
	}

	/**
	 * The estimate HTML for a product ('' when nothing shows).
	 *
	 * @param WC_Product|null $product Product.
	 */
	public static function render( $product ): string {
		$est = self::for_product( $product );
		if ( null === $est ) {
			return '';
		}
		$s    = SHN_Settings::get();
		$list = 'list' === $s['dl_format'] && count( $est['lines'] ) > 1;
		$out  = '';

		if ( '' !== $est['from'] ) {
			// Pre-order: a fixed date, true on cached pages too (until the release day).
			list( $min, $max ) = self::span( $est );
			$ts                = strtotime( $est['from'] . ' 12:00:00' );
			$text              = strtr(
				SHN_Settings::text( 'dl_t_preorder' ),
				array(
					'{available}' => date_i18n( (string) get_option( 'date_format' ), $ts ? $ts : time() ),
					'{days}'      => self::days_text( $min, $max ),
				)
			);
			$out .= '<p class="shn-dl__line">' . esc_html( $text ) . '</p>';
			return '<div class="shn-dl shn-dl--preorder">' . $out . '</div>';
		}

		$tpl = array();
		if ( $list ) {
			$out .= '<p class="shn-dl__title">' . esc_html( SHN_Settings::text( 'dl_t_title' ) ) . '</p>';
			foreach ( $est['lines'] as $i => $l ) {
				$text  = strtr(
					SHN_Settings::text( 'dl_t_item' ),
					array(
						'{method}' => $l['method'],
						'{days}'   => self::days_text( $l['pmin'] + $l['tmin'], $l['pmax'] + $l['tmax'] ),
					)
				);
				$out  .= '<p class="shn-dl__line" data-i="' . (int) $i . '">' . esc_html( $text ) . '</p>';
				$tpl[] = str_replace( '{method}', $l['method'], SHN_Settings::text( 'dl_t_item_dates' ) );
			}
		} else {
			list( $min, $max ) = self::span( $est );
			$text              = str_replace( '{days}', self::days_text( $min, $max ), SHN_Settings::text( 'dl_t_est' ) );
			$out              .= '<p class="shn-dl__line" data-i="-1">' . esc_html( $text ) . '</p>';
			$tpl[]             = SHN_Settings::text( 'dl_t_dates' );
		}

		$js = ! empty( $s['dl_dates'] ) || ( ! empty( $s['dl_cutoff_line'] ) && '' !== $s['dl_cutoff'] );
		if ( ! $js ) {
			return '<div class="shn-dl">' . $out . '</div>';
		}

		if ( ! empty( $s['dl_cutoff_line'] ) && '' !== $s['dl_cutoff'] ) {
			$out .= '<p class="shn-dl__cutoff" hidden></p>';
		}
		$data = array(
			'tz'     => (string) get_option( 'timezone_string' ),
			'off'    => (int) round( (float) get_option( 'gmt_offset' ) * 60 ),
			'cut'    => (string) $s['dl_cutoff'],
			'wd'     => array_map( 'intval', $s['dl_workdays'] ),
			'hol'    => array_values( $s['dl_holidays'] ),
			'lang'   => SHN_Settings::lang(),
			'dates'  => ! empty( $s['dl_dates'] ),
			'list'   => $list,
			'lines'  => array_map(
				static function ( $l ) {
					return array( $l['pmin'], $l['pmax'], $l['tmin'], $l['tmax'], $l['sat'] ? 1 : 0 );
				},
				$est['lines']
			),
			'tpl'    => $tpl,
			'cutTpl' => ! empty( $s['dl_cutoff_line'] ) ? SHN_Settings::text( 'dl_t_cutoff' ) : '',
		);
		SHN_Front::need_delivery_js();
		return '<div class="shn-dl" data-shn-dl="' . esc_attr( (string) wp_json_encode( $data ) ) . '">' . $out . '</div>';
	}
}

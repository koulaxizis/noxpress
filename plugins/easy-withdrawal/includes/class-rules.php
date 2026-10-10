<?php
/**
 * EWD_Rules — who may withdraw from what, and until when.
 *
 * Window (design §4, option 4Γ):
 *  - Orders in an eligible status (processing, on-hold, completed; filter
 *    ewd_eligible_statuses) are open from the moment they are created.
 *  - Goods: the window closes days + grace after the order is completed
 *    (the shipping date; the period runs from delivery, which the store
 *    does not know). Before completion it stays open, so a pre-order or a
 *    late shipment never closes early.
 *  - Virtual items (services, digital content): days after payment (or
 *    creation when unpaid), without the delivery grace.
 *
 * Exclusions (Directive 2011/83 art. 16, design §5):
 *  - "personalised" flag on the product (or its parent);
 *  - the store's excluded categories and products (e.g. sealed hygiene
 *    goods);
 *  - digital items (virtual or downloadable) only when the customer gave
 *    the consent at checkout (classic field or the block checkout field).
 *
 * Quantity still available per item = ordered − refunded − asked in
 * requests that are not disputed.
 */

defined( 'ABSPATH' ) || exit;

final class EWD_Rules {

	/** Order meta: consent for digital items, Unix time (classic checkout). */
	const META_CONSENT = '_ewd_digital_consent';

	/** Order meta written by the block checkout for our additional field. */
	const META_CONSENT_BLOCK = '_wc_other/easy-withdrawal/digital-consent';

	/** Statuses (without wc-) in which a withdrawal can be asked. */
	public static function statuses(): array {
		$list = apply_filters( 'ewd_eligible_statuses', array( 'processing', 'on-hold', 'completed' ) );
		return array_values( array_filter( array_map( 'sanitize_key', (array) $list ) ) );
	}

	/** Whether the order holds the digital-content consent. */
	public static function has_consent( WC_Order $order ): bool {
		if ( (int) $order->get_meta( self::META_CONSENT ) > 0 ) {
			return true;
		}
		$block = $order->get_meta( self::META_CONSENT_BLOCK );
		return true === $block || '1' === (string) $block || 'true' === (string) $block;
	}

	/** True for virtual or downloadable products (variations included). */
	public static function is_digital( $product ): bool {
		return $product instanceof WC_Product && ( $product->is_virtual() || $product->is_downloadable() );
	}

	/**
	 * Exclusion reason msgid for a product in an order, '' when it can be returned.
	 *
	 * @param WC_Product|false|null $product Product (may be gone).
	 */
	public static function exclusion( $product, WC_Order $order ): string {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}
		$s      = EWD_Settings::get();
		$ids    = array( $product->get_id() );
		$parent = $product->get_parent_id();
		if ( $parent > 0 ) {
			$ids[] = $parent;
		}

		foreach ( $ids as $id ) {
			if ( 'yes' === get_post_meta( $id, EWD_Settings::META_PERSONAL, true ) ) {
				return 'Εξατομικευμένο προϊόν: δεν επιστρέφεται.';
			}
		}

		if ( array_intersect( $ids, $s['excl_products'] ) ) {
			return 'Εξαιρείται από το δικαίωμα υπαναχώρησης.';
		}
		if ( $s['excl_cats'] ) {
			$cats = wc_get_product_cat_ids( $parent > 0 ? $parent : $product->get_id() );
			foreach ( $cats as $cat ) {
				$tree = array_merge( array( (int) $cat ), array_map( 'intval', get_ancestors( (int) $cat, 'product_cat', 'taxonomy' ) ) );
				if ( array_intersect( $tree, $s['excl_cats'] ) ) {
					return 'Εξαιρείται από το δικαίωμα υπαναχώρησης.';
				}
			}
		}

		if ( self::is_digital( $product ) && self::has_consent( $order ) ) {
			return 'Ψηφιακό περιεχόμενο που παραδόθηκε με τη συναίνεσή σου.';
		}

		return '';
	}

	/**
	 * End of the window for an item (Unix time), or 0 while it is still open
	 * without an end (goods not shipped yet).
	 *
	 * @param WC_Product|false|null $product Product (may be gone).
	 */
	public static function deadline( WC_Order $order, $product ): int {
		$s   = EWD_Settings::get();
		$day = DAY_IN_SECONDS;

		if ( $product instanceof WC_Product && $product->is_virtual() ) {
			$start = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
			return $start ? $start->getTimestamp() + (int) $s['days'] * $day : 0;
		}

		if ( $order->has_status( 'completed' ) ) {
			$done = $order->get_date_completed();
			if ( ! $done ) {
				$done = $order->get_date_modified() ? $order->get_date_modified() : $order->get_date_created();
			}
			return $done ? $done->getTimestamp() + ( (int) $s['days'] + (int) $s['grace'] ) * $day : 0;
		}

		return 0;
	}

	/**
	 * The order's items with what can still be asked.
	 *
	 * @return array[] item_id => [ item_id, product_id, name, qty, refunded,
	 *                 asked, available, total, excluded (msgid), deadline,
	 *                 open (bool) ]
	 */
	public static function items( WC_Order $order ): array {
		$asked = EWD_Requests::asked_quantities( $order );
		$now   = time();
		$open  = $order->has_status( self::statuses() );
		$out   = array();

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$qty      = (int) $item->get_quantity();
			$refunded = (int) abs( $order->get_qty_refunded_for_item( $item_id ) );
			$a        = isset( $asked[ $item_id ] ) ? (int) $asked[ $item_id ] : 0;
			$product  = $item->get_product();
			$excluded = self::exclusion( $product, $order );
			$deadline = self::deadline( $order, $product );
			$in_time  = $open && ( 0 === $deadline || $deadline >= $now );
			$avail    = max( 0, $qty - $refunded - $a );
			$total    = (float) $order->get_line_total( $item, true, false );

			$out[ $item_id ] = array(
				'item_id'    => (int) $item_id,
				'product_id' => (int) ( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() ),
				'name'       => (string) $item->get_name(),
				'qty'        => $qty,
				'refunded'   => $refunded,
				'asked'      => $a,
				'available'  => ( '' === $excluded && $in_time ) ? $avail : 0,
				'unit'       => $qty > 0 ? $total / $qty : 0.0,
				'excluded'   => $excluded,
				'deadline'   => $deadline,
				'open'       => $in_time,
			);
		}
		return $out;
	}

	/** True when at least one item can still be withdrawn. */
	public static function order_open( WC_Order $order ): bool {
		if ( ! $order->has_status( self::statuses() ) ) {
			return false;
		}
		foreach ( self::items( $order ) as $row ) {
			if ( $row['available'] > 0 ) {
				return true;
			}
		}
		return false;
	}

	/** Latest deadline among the order's items, 0 when open without an end. */
	public static function order_deadline( WC_Order $order ): int {
		$max = -1;
		foreach ( self::items( $order ) as $row ) {
			if ( '' !== $row['excluded'] ) {
				continue;
			}
			if ( 0 === $row['deadline'] ) {
				return 0;
			}
			$max = max( $max, $row['deadline'] );
		}
		return max( 0, $max );
	}

	/**
	 * Find an order by the number the customer sees and its billing email.
	 * Supports sequential-number plugins through the filter ewd_find_order_id
	 * and the common _order_number meta.
	 */
	public static function find_order( string $number, string $email ): ?WC_Order {
		$number = trim( ltrim( trim( $number ), '#' ) );
		$email  = strtolower( trim( $email ) );
		if ( '' === $number || '' === $email || strlen( $number ) > 40 ) {
			return null;
		}

		$ids = array();
		$hit = (int) apply_filters( 'ewd_find_order_id', 0, $number );
		if ( $hit > 0 ) {
			$ids[] = $hit;
		}
		if ( ctype_digit( $number ) ) {
			$ids[] = (int) $number;
		}
		$by_meta = wc_get_orders(
			array(
				'limit'      => 5,
				'return'     => 'ids',
				'type'       => 'shop_order',
				'meta_key'   => '_order_number', // phpcs:ignore WordPress.DB.SlowDBQuery -- indexed lookup by number.
				'meta_value' => $number, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$ids = array_merge( $ids, array_map( 'intval', (array) $by_meta ) );

		foreach ( array_unique( $ids ) as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
				continue;
			}
			if ( (string) $order->get_order_number() !== $number ) {
				continue;
			}
			if ( hash_equals( strtolower( (string) $order->get_billing_email() ), $email ) ) {
				return $order;
			}
		}
		return null;
	}
}

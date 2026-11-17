<?php
/**
 * PL_Sales (1.3.0) — the two ways of selling that had no record before:
 *
 *  - «Παράδοση από τον εκδότη» (method pl_hand): a shipping method for
 *    copies the publisher hands over in person. It is never offered at
 *    checkout (is_available() is false for visitors); the publisher picks
 *    it on an order in the admin, and the title analysis counts it as
 *    «χέρι με χέρι».
 *  - event_sale(): a sale at a book fair or festival becomes a normal
 *    WooCommerce order (status completed, created_via pl-event, meta
 *    _pl_channel = event and _pl_event = the event's name, shipping line
 *    pl_hand, payment cash or card), so stock, Revenue Splitter's shares
 *    and Store Pulse see it like any other sale. No e-mail goes to anyone.
 *
 * Messages for abilities and REST are in English.
 */

defined( 'ABSPATH' ) || exit;

final class PL_Sales {

	const METHOD   = 'pl_hand';
	const MAX_ROWS = 50;

	public static function init(): void {
		add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'register' ) );
		add_action( 'woocommerce_shipping_init', array( __CLASS__, 'load_method' ) );
	}

	public static function load_method(): void {
		if ( ! class_exists( 'PL_Hand_Method' ) && class_exists( 'WC_Shipping_Method' ) ) {
			require_once PL_PATH . 'includes/class-hand-method.php';
		}
	}

	/** @param array $methods Methods. */
	public static function register( $methods ) {
		self::load_method();
		if ( class_exists( 'PL_Hand_Method' ) ) {
			$methods[ self::METHOD ] = 'PL_Hand_Method';
		}
		return $methods;
	}

	public static function payments(): array {
		return array(
			'cash' => __( 'Μετρητά', 'production-ledger' ),
			'card' => __( 'Κάρτα', 'production-ledger' ),
		);
	}

	/**
	 * A sale at an event. $in: date (Y-m-d), event (name, 1–120), payment
	 * (cash | card), note, lines: [ { product_id, qty, price (unit, VAT
	 * included; default the product's price) } ], dry_run.
	 *
	 * @param string $source manual | ability | oros (kept on the order).
	 * @return array|WP_Error
	 */
	public static function event_sale( array $in, string $source = 'ability' ) {
		$day = (string) ( $in['date'] ?? wp_date( 'Y-m-d' ) );
		if ( ! PL_Settings::valid_date( $day ) || $day > wp_date( 'Y-m-d' ) ) {
			return new WP_Error( 'pl_date', 'date must be YYYY-MM-DD, not in the future.' );
		}
		$event = mb_substr( trim( sanitize_text_field( (string) ( $in['event'] ?? '' ) ) ), 0, 120 );
		if ( '' === $event ) {
			return new WP_Error( 'pl_event', 'event (its name) is required.' );
		}
		$pay = (string) ( $in['payment'] ?? 'cash' );
		if ( ! isset( self::payments()[ $pay ] ) ) {
			return new WP_Error( 'pl_pay', 'payment must be cash or card.' );
		}
		$lines = isset( $in['lines'] ) && is_array( $in['lines'] ) ? array_values( $in['lines'] ) : array();
		if ( ! $lines || count( $lines ) > self::MAX_ROWS ) {
			return new WP_Error( 'pl_lines', 'lines must hold 1–' . self::MAX_ROWS . ' items.' );
		}
		$clean = array();
		foreach ( $lines as $i => $l ) {
			$l   = is_array( $l ) ? $l : array();
			$p   = wc_get_product( (int) ( $l['product_id'] ?? 0 ) );
			$qty = (int) ( $l['qty'] ?? 0 );
			if ( ! $p instanceof WC_Product || $p->is_type( 'variable' ) ) {
				return new WP_Error( 'pl_product', '#' . $i . ': product_id is not a sellable product (use the variation of a variable product).' );
			}
			if ( $qty < 1 || $qty > 999 ) {
				return new WP_Error( 'pl_qty', '#' . $i . ': qty must be 1–999.' );
			}
			$price = array_key_exists( 'price', $l ) && '' !== (string) $l['price'] ? PL_Entries::money( $l['price'] ) : (float) $p->get_price();
			if ( null === $price ) {
				return new WP_Error( 'pl_price', '#' . $i . ': price must be an amount ≥ 0 with at most 2 decimals.' );
			}
			if ( $p->managing_stock() && ! $p->backorders_allowed() && (int) $p->get_stock_quantity() < $qty ) {
				return new WP_Error( 'pl_stock', '#' . $i . ': only ' . (int) $p->get_stock_quantity() . ' in stock.' );
			}
			$clean[] = array(
				'product' => $p,
				'qty'     => $qty,
				'price'   => (float) $price,
			);
		}
		$total = 0.0;
		$out   = array();
		foreach ( $clean as $c ) {
			$total += $c['price'] * $c['qty'];
			$out[]  = array(
				'product_id' => $c['product']->get_id(),
				'title'      => html_entity_decode( wp_strip_all_tags( $c['product']->get_name() ), ENT_QUOTES, 'UTF-8' ),
				'qty'        => $c['qty'],
				'price'      => $c['price'],
			);
		}
		$res = array(
			'dry_run' => ! empty( $in['dry_run'] ),
			'date'    => $day,
			'event'   => $event,
			'payment' => $pay,
			'lines'   => $out,
			'total'   => round( $total, 2 ),
			'order'   => 0,
		);
		if ( $res['dry_run'] ) {
			return $res;
		}

		$o = wc_create_order(
			array(
				'status'      => 'pending',
				'created_via' => 'pl-event',
			)
		);
		if ( is_wp_error( $o ) ) {
			return $o;
		}
		foreach ( $clean as $c ) {
			// Prices include VAT (Bible: shop prices are gross): the line keeps the gross amount.
			$id   = $o->add_product( $c['product'], $c['qty'] );
			$item = $o->get_item( $id );
			if ( $item instanceof WC_Order_Item_Product ) {
				$rates = WC_Tax::get_base_tax_rates( $c['product']->get_tax_class() );
				$gross = $c['price'] * $c['qty'];
				$tax   = WC_Tax::calc_tax( $gross, $rates, true );
				$net   = $gross - array_sum( $tax );
				$item->set_subtotal( wc_format_decimal( $net ) );
				$item->set_total( wc_format_decimal( $net ) );
				$item->set_taxes(
					array(
						'subtotal' => $tax,
						'total'    => $tax,
					)
				);
				$item->save();
			}
		}
		$ship = new WC_Order_Item_Shipping();
		$ship->set_method_title( __( 'Παράδοση από τον εκδότη', 'production-ledger' ) );
		$ship->set_method_id( self::METHOD );
		$ship->set_total( '0' );
		$o->add_item( $ship );
		$o->set_payment_method( 'cash' === $pay ? 'cod' : 'pl_card' );
		$o->set_payment_method_title( 'cash' === $pay ? __( 'Μετρητά', 'production-ledger' ) : __( 'Κάρτα', 'production-ledger' ) );
		$o->update_meta_data( '_pl_channel', 'event' );
		$o->update_meta_data( '_pl_event', $event );
		$o->update_meta_data( '_pl_source', in_array( $source, array( 'manual', 'ability', 'oros' ), true ) ? $source : 'manual' );
		$tz = wp_timezone();
		$o->set_date_created( ( new DateTimeImmutable( $day . ' 12:00:00', $tz ) )->getTimestamp() );
		$o->set_date_paid( ( new DateTimeImmutable( $day . ' 12:00:00', $tz ) )->getTimestamp() );
		$o->calculate_totals( false );
		$note = mb_substr( trim( sanitize_text_field( (string) ( $in['note'] ?? '' ) ) ), 0, 300 );
		/* translators: %s: event */
		$o->add_order_note( sprintf( __( 'Πώληση σε εκδήλωση: %s', 'production-ledger' ), $event ) . ( '' !== $note ? ' · ' . $note : '' ) );
		$o->save();
		// No e-mails: a sale in person has no customer address.
		add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false', 99 );
		add_filter( 'woocommerce_email_enabled_new_order', '__return_false', 99 );
		$o->update_status( 'completed' );
		wc_maybe_reduce_stock_levels( $o->get_id() );
		remove_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false', 99 );
		remove_filter( 'woocommerce_email_enabled_new_order', '__return_false', 99 );
		$res['order'] = $o->get_id();
		$res['total'] = (float) $o->get_total();
		return $res;
	}
}

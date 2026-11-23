<?php
/**
 * ORC_Privacy — WordPress's personal data exporter and eraser (Tools →
 * Export / Erase Personal Data) for the newsletter consent kept with an
 * address's orders (Bible §12 «Προσωπικά δεδομένα»). The policy text stays
 * in ORC_Admin_UI::policy().
 *
 * Order meta (ORC_Newsletter): _orc_nl_consent (time), _orc_nl_text (the
 * checkbox text the customer saw), _orc_nl_lang, _orc_nl_queued,
 * _orc_nl_done, _orc_nl_fail. The e-mail itself is the order's billing
 * e-mail; the subscriber lives in orOS Newsletter, not on the site.
 *
 * Eraser: the consent record is the store's proof of consent (GDPR art.
 * 7(1)) and stays with the order, unless the store removes personal data
 * from orders on request (WooCommerce → Settings → Accounts & Privacy):
 * then the consent meta goes too. Either way the message reminds the
 * store to remove the address from the list in orOS Newsletter, which the
 * site cannot reach. Registered before WooCommerce's own eraser
 * (priority 5), so the orders are still found by their billing e-mail.
 * Registered whatever modules are on: old orders keep their consent.
 */

defined( 'ABSPATH' ) || exit;

final class ORC_Privacy {

	const KEY      = 'oros-connect';
	const PER_PAGE = 50;
	const META     = array( '_orc_nl_consent', '_orc_nl_text', '_orc_nl_lang', '_orc_nl_queued', '_orc_nl_done', '_orc_nl_fail' );

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ), 5 );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ), 5 );
	}

	public static function exporters( $list ): array {
		$list              = is_array( $list ) ? $list : array();
		$list[ self::KEY ] = array(
			'exporter_friendly_name' => __( 'orOS Connect: συναίνεση newsletter', 'oros-connect' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	public static function erasers( $list ): array {
		$list              = is_array( $list ) ? $list : array();
		$list[ self::KEY ] = array(
			'eraser_friendly_name' => __( 'orOS Connect: συναίνεση newsletter', 'oros-connect' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	/**
	 * One page of the address's orders that carry a consent, oldest first.
	 *
	 * @return WC_Order[]
	 */
	private static function orders( string $email, int $page ): array {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$found = wc_get_orders(
			array(
				'billing_email' => $email,
				'type'          => 'shop_order',
				'status'        => 'any',
				'limit'         => self::PER_PAGE,
				'paged'         => max( 1, $page ),
				'orderby'       => 'ID',
				'order'         => 'ASC',
				'return'        => 'objects',
			)
		);
		$out = array();
		foreach ( is_array( $found ) ? $found : array() as $o ) {
			if ( $o instanceof WC_Order ) {
				$out[] = $o;
			}
		}
		return $out;
	}

	private static function date( $ts ): string {
		return (int) $ts > 0 ? gmdate( 'Y-m-d H:i:s', (int) $ts ) . ' UTC' : '';
	}

	public static function export( $email, $page = 1 ): array {
		$orders = self::orders( (string) $email, (int) $page );
		$items  = array();
		foreach ( $orders as $order ) {
			$at = (int) $order->get_meta( '_orc_nl_consent' );
			if ( $at <= 0 ) {
				continue;
			}
			$data = array(
				array(
					'name'  => __( 'Παραγγελία', 'oros-connect' ),
					'value' => '#' . $order->get_order_number(),
				),
				array(
					'name'  => __( 'Συναίνεση', 'oros-connect' ),
					'value' => self::date( $at ),
				),
			);
			foreach ( array(
				'_orc_nl_text' => __( 'Κείμενο συναίνεσης', 'oros-connect' ),
				'_orc_nl_lang' => __( 'Γλώσσα', 'oros-connect' ),
				'_orc_nl_done' => __( 'Στάλθηκε στο orOS Newsletter', 'oros-connect' ),
			) as $k => $label ) {
				$v = $order->get_meta( $k );
				if ( '' !== (string) $v ) {
					$data[] = array(
						'name'  => $label,
						'value' => '_orc_nl_done' === $k ? self::date( $v ) : (string) $v,
					);
				}
			}
			$items[] = array(
				'group_id'    => self::KEY,
				'group_label' => __( 'Newsletter (orOS Connect)', 'oros-connect' ),
				'item_id'     => 'orc-' . $order->get_id(),
				'data'        => $data,
			);
		}
		return array(
			'data' => $items,
			'done' => count( $orders ) < self::PER_PAGE,
		);
	}

	public static function erase( $email, $page = 1 ): array {
		$orders   = self::orders( (string) $email, (int) $page );
		$scrub    = 'yes' === get_option( 'woocommerce_erasure_request_removes_order_data', 'no' );
		$removed  = false;
		$retained = 0;
		$found    = 0;
		foreach ( $orders as $order ) {
			if ( (int) $order->get_meta( '_orc_nl_consent' ) <= 0 ) {
				continue;
			}
			++$found;
			if ( $scrub ) {
				foreach ( self::META as $k ) {
					$order->delete_meta_data( $k );
				}
				$order->save();
				$removed = true;
			} else {
				++$retained;
			}
		}
		$messages = array();
		if ( $retained > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of orders */
				__( 'orOS Connect: κρατήθηκε η συναίνεση για το newsletter σε %d παραγγελίες, ως απόδειξη της συναίνεσης (ΓΚΠΔ άρθρο 7 παρ. 1). Σβήνεται μαζί με την παραγγελία, ή αμέσως αν ενεργοποιήσεις τη διαγραφή προσωπικών δεδομένων από τις παραγγελίες στις ρυθμίσεις του WooCommerce.', 'oros-connect' ),
				$retained
			);
		}
		if ( $found > 0 && 1 === max( 1, (int) $page ) ) {
			$messages[] = __( 'orOS Connect: η διεύθυνση μπορεί να είναι εγγεγραμμένη στη λίστα του orOS Newsletter. Το site δεν έχει πρόσβαση εκεί: διάγραψέ την από τη λίστα στο orOS.', 'oros-connect' );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained > 0,
			'messages'       => $messages,
			'done'           => count( $orders ) < self::PER_PAGE,
		);
	}
}

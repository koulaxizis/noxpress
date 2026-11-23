<?php
/**
 * EWD_Privacy — WordPress's personal data exporter and eraser (Tools →
 * Export / Erase Personal Data) for the withdrawal requests of an address.
 *
 * Export: every request whose e-mail, or whose order's billing e-mail, is
 * the address (date, order, items, reason, status).
 *
 * Erase: nothing is removed. A withdrawal statement is the store's record
 * of the consumer's legal act (Directive 2011/83/EU, art. 11, as amended
 * by 2023/2673) and of the refund that follows it, kept with the order as
 * WooCommerce keeps the order itself; the eraser reports it as retained
 * with the reason.
 */

defined( 'ABSPATH' ) || exit;

final class EWD_Privacy {

	/** Orders with requests per page of the export. */
	const PER_PAGE = 50;

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
	}

	public static function exporters( $list ): array {
		$list                    = is_array( $list ) ? $list : array();
		$list['easy-withdrawal'] = array(
			'exporter_friendly_name' => __( 'Easy Withdrawal: αιτήματα υπαναχώρησης', 'easy-withdrawal' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	public static function erasers( $list ): array {
		$list                    = is_array( $list ) ? $list : array();
		$list['easy-withdrawal'] = array(
			'eraser_friendly_name' => __( 'Easy Withdrawal: αιτήματα υπαναχώρησης', 'easy-withdrawal' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	/**
	 * Requests of an address on one page of orders with requests.
	 *
	 * @param string $email Address (lowercase).
	 * @param int    $page  Page, from 1.
	 * @return array array( list of array( order, request ), more pages )
	 */
	private static function find( string $email, int $page ): array {
		$orders = wc_get_orders(
			array(
				'limit'    => self::PER_PAGE,
				'page'     => max( 1, $page ),
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'type'     => 'shop_order',
				'status'   => array_keys( wc_get_order_statuses() ),
				'meta_key' => EWD_Requests::META_STATE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only orders with requests.
			)
		);
		$out    = array();
		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$billing = strtolower( (string) $order->get_billing_email() );
			foreach ( EWD_Requests::for_order( $order ) as $r ) {
				$own = isset( $r['email'] ) ? strtolower( (string) $r['email'] ) : '';
				if ( $email === $own || $email === $billing ) {
					$out[] = array( $order, $r );
				}
			}
		}
		return array( $out, count( (array) $orders ) === self::PER_PAGE );
	}

	public static function export( $email, $page = 1 ): array {
		$email = strtolower( trim( (string) $email ) );
		$items = array();
		$more  = false;
		if ( is_email( $email ) ) {
			list( $found, $more ) = self::find( $email, (int) $page );
			foreach ( $found as $pair ) {
				list( $order, $r ) = $pair;
				$data              = array(
					array(
						'name'  => __( 'Αίτημα', 'easy-withdrawal' ),
						'value' => (string) $r['id'],
					),
					array(
						'name'  => __( 'Παραγγελία', 'easy-withdrawal' ),
						'value' => '#' . $order->get_order_number(),
					),
					array(
						'name'  => __( 'Ημερομηνία', 'easy-withdrawal' ),
						'value' => gmdate( 'Y-m-d H:i', (int) $r['created'] ) . ' UTC',
					),
					array(
						'name'  => __( 'Προϊόντα', 'easy-withdrawal' ),
						'value' => EWD_Requests::items_text( (array) $r['items'] ),
					),
					array(
						'name'  => __( 'Κατάσταση', 'easy-withdrawal' ),
						'value' => EWD_Requests::status_label( (string) $r['status'] ),
					),
				);
				foreach ( array(
					'name'   => __( 'Όνομα', 'easy-withdrawal' ),
					'email'  => __( 'Email', 'easy-withdrawal' ),
					'reason' => __( 'Λόγος', 'easy-withdrawal' ),
				) as $key => $label ) {
					if ( isset( $r[ $key ] ) && '' !== (string) $r[ $key ] ) {
						$data[] = array(
							'name'  => $label,
							'value' => (string) $r[ $key ],
						);
					}
				}
				$items[] = array(
					'group_id'    => 'easy-withdrawal',
					'group_label' => __( 'Αιτήματα υπαναχώρησης', 'easy-withdrawal' ),
					'item_id'     => 'ewd-' . sanitize_key( (string) $r['id'] ),
					'data'        => $data,
				);
			}
		}
		return array(
			'data' => $items,
			'done' => ! $more,
		);
	}

	public static function erase( $email, $page = 1 ): array {
		$email = strtolower( trim( (string) $email ) );
		$found = array();
		$more  = false;
		if ( is_email( $email ) ) {
			list( $found, $more ) = self::find( $email, (int) $page );
		}
		$messages = array();
		if ( $found ) {
			$messages[] = sprintf(
				/* translators: %d: number of withdrawal requests */
				__( 'Κρατήθηκαν %d αιτήματα υπαναχώρησης: είναι το αρχείο του καταστήματος για τη δήλωση υπαναχώρησης και την επιστροφή χρημάτων, και μένουν με την παραγγελία.', 'easy-withdrawal' ),
				count( $found )
			);
		}
		return array(
			'items_removed'  => false,
			'items_retained' => ! empty( $found ),
			'messages'       => $messages,
			'done'           => ! $more,
		);
	}
}

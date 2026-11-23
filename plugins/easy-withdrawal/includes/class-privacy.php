<?php
/**
 * EWD_Privacy — WordPress's personal data exporter and eraser (Tools →
 * Export / Erase Personal Data) for the withdrawal statements stored with
 * an address's orders, and the suggested text for the privacy policy.
 *
 * The statements are the store's record of the consumer's statement
 * (art. 3ζα ν. 2251/1994, art. 11a Directive 2011/83/EU): the eraser keeps
 * them and says why (GDPR art. 17(3)(e)), unless the store has chosen to
 * remove personal data from orders on request (WooCommerce → Settings →
 * Accounts & Privacy). Then it follows that choice and anonymises the name,
 * the email and the optional reason of each statement, and keeps the rest
 * (request id, date and time, items, status) with the anonymised order.
 *
 * The eraser is registered before WooCommerce's own (priority 5), so it
 * still finds the orders by their billing email.
 */

defined( 'ABSPATH' ) || exit;

final class EWD_Privacy {

	const KEY      = 'easy-withdrawal';
	const PER_PAGE = 20;

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ), 5 );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ), 5 );
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
	}

	public static function exporters( $list ): array {
		$list              = is_array( $list ) ? $list : array();
		$list[ self::KEY ] = array(
			'exporter_friendly_name' => __( 'Easy Withdrawal: δηλώσεις υπαναχώρησης', 'easy-withdrawal' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	public static function erasers( $list ): array {
		$list              = is_array( $list ) ? $list : array();
		$list[ self::KEY ] = array(
			'eraser_friendly_name' => __( 'Easy Withdrawal: δηλώσεις υπαναχώρησης', 'easy-withdrawal' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	/**
	 * One page of the address's orders (by billing email), oldest first.
	 *
	 * @param string $email Address.
	 * @param int    $page  Page (1-based).
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

	public static function export( $email, $page = 1 ): array {
		$orders = self::orders( (string) $email, (int) $page );
		$items  = array();
		foreach ( $orders as $order ) {
			foreach ( EWD_Requests::for_order( $order ) as $r ) {
				$data = array(
					array(
						'name'  => __( 'Αίτημα', 'easy-withdrawal' ),
						'value' => (string) $r['id'],
					),
					array(
						'name'  => __( 'Παραγγελία', 'easy-withdrawal' ),
						'value' => '#' . $order->get_order_number(),
					),
					array(
						'name'  => __( 'Υποβλήθηκε', 'easy-withdrawal' ),
						'value' => EWD_Lang::datetime( (int) $r['created'] ),
					),
					array(
						'name'  => __( 'Προϊόντα', 'easy-withdrawal' ),
						'value' => EWD_Requests::items_text( $r['items'] ),
					),
					array(
						'name'  => __( 'Κατάσταση', 'easy-withdrawal' ),
						'value' => EWD_Requests::status_label( (string) $r['status'] ),
					),
				);
				foreach ( array(
					'name'   => __( 'Όνομα', 'easy-withdrawal' ),
					'email'  => __( 'Email', 'easy-withdrawal' ),
					'reason' => __( 'Αιτιολογία', 'easy-withdrawal' ),
				) as $k => $label ) {
					if ( isset( $r[ $k ] ) && '' !== (string) $r[ $k ] ) {
						$data[] = array(
							'name'  => $label,
							'value' => (string) $r[ $k ],
						);
					}
				}
				$items[] = array(
					'group_id'    => self::KEY,
					'group_label' => __( 'Δηλώσεις υπαναχώρησης', 'easy-withdrawal' ),
					'item_id'     => 'ewd-' . sanitize_key( (string) $r['id'] ),
					'data'        => $data,
				);
			}
		}
		return array(
			'data' => $items,
			'done' => count( $orders ) < self::PER_PAGE,
		);
	}

	public static function erase( $email, $page = 1 ): array {
		$orders   = self::orders( (string) $email, (int) $page );
		$removed  = false;
		$retained = 0;
		$scrub    = 'yes' === get_option( 'woocommerce_erasure_request_removes_order_data', 'no' );
		foreach ( $orders as $order ) {
			if ( ! EWD_Requests::for_order( $order ) ) {
				continue;
			}
			if ( $scrub ) {
				$removed = EWD_Requests::anonymize( $order ) > 0 || $removed;
			} else {
				$retained += count( EWD_Requests::for_order( $order ) );
			}
		}
		$messages = array();
		if ( $retained > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of withdrawal statements */
				__( 'Easy Withdrawal: κρατήθηκαν %d δηλώσεις υπαναχώρησης. Είναι το αρχείο του καταστήματος για τη δήλωση του καταναλωτή και μπορεί να χρειαστούν για νομικές αξιώσεις (ΓΚΠΔ άρθρο 17 παρ. 3 στοιχ. ε). Σβήνονται μαζί με την παραγγελία, ή ανωνυμοποιούνται αν ενεργοποιήσεις τη διαγραφή προσωπικών δεδομένων από τις παραγγελίες στις ρυθμίσεις του WooCommerce.', 'easy-withdrawal' ),
				$retained
			);
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained > 0,
			'messages'       => $messages,
			'done'           => count( $orders ) < self::PER_PAGE,
		);
	}

	public static function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'Αν υπαναχωρήσεις από μια αγορά μέσα από τη φόρμα υπαναχώρησης, κρατάμε μαζί με την παραγγελία τη δήλωσή σου: το όνομα και το email σου, τα προϊόντα και τις ποσότητες, την προαιρετική αιτιολογία, την ημερομηνία και την ώρα, τη γλώσσα και την πορεία της επιστροφής. Σου στέλνουμε αμέσως απόδειξη παραλαβής με email. Η δήλωση είναι το αρχείο μας για την υπαναχώρησή σου και την κρατάμε όσο κρατάμε και την παραγγελία. Για να εμποδίσουμε την κατάχρηση μετράμε τις αποτυχημένες αναζητήσεις παραγγελίας ανά διεύθυνση IP για 15 λεπτά· η διεύθυνση κρατιέται μόνο ως κρυπτογραφημένο αποτύπωμα.', 'easy-withdrawal' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Αν το κατάστημα ζητά συναίνεση για την άμεση παράδοση ψηφιακού περιεχομένου, κρατάμε με την παραγγελία την ημερομηνία και το κείμενο της συναίνεσης.', 'easy-withdrawal' ) . '</p>';
		wp_add_privacy_policy_content( 'Easy Withdrawal', wp_kses_post( $text ) );
	}
}

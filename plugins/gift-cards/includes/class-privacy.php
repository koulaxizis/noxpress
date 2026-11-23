<?php
/**
 * GFC_Privacy — WordPress's personal data exporter and eraser (Tools →
 * Export / Erase Personal Data) for the gift cards of an address.
 *
 * A card holds the buyer's e-mail, the recipient's name and e-mail, the
 * sender's name and the message (table {prefix}gfc_cards). The code is
 * kept only as an HMAC and its last 4 characters; the ledger holds amounts
 * and order ids only; the attempt limits are hashed keys.
 *
 * Export: every card where the address is the buyer or the recipient, or
 * that the account bought (code end, amounts, status, dates, names, message).
 *
 * Erase: on cards that are finished (used up, expired, void, or disabled
 * with no balance) the buyer e-mail, recipient name and e-mail, sender
 * name and message are blanked. A card with a balance that can still be
 * spent, or that waits for delivery or review, is kept as it is: it is
 * money the store owes, and blanking the recipient would lose it; the
 * eraser says how many were kept. Amounts, the ledger and the order link
 * always stay (accounting records).
 *
 * 1.2.0:
 *  - Retention (setting keep_days, 0 = never): the daily job clears the
 *    recipient and sender names and the message of cards finished for
 *    longer than keep_days. The e-mails stay for the eraser.
 *  - When WooCommerce removes an order's personal data, the gift card
 *    lines of that order (the _gfc data, «Προς» and «Από») are cleaned
 *    too, and so are their finished cards; lines of cards that can still
 *    be spent keep their data.
 *  - Suggested privacy policy text.
 */

defined( 'ABSPATH' ) || exit;

final class GFC_Privacy {

	/** Cards per page. */
	const PER_PAGE = 100;

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( __CLASS__, 'order_lines' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
	}

	public static function exporters( $list ): array {
		$list               = is_array( $list ) ? $list : array();
		$list['gift-cards'] = array(
			'exporter_friendly_name' => __( 'Gift Cards: δωροκάρτες', 'gift-cards' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	public static function erasers( $list ): array {
		$list               = is_array( $list ) ? $list : array();
		$list['gift-cards'] = array(
			'eraser_friendly_name' => __( 'Gift Cards: δωροκάρτες', 'gift-cards' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	/**
	 * Cards of an address, by id.
	 *
	 * @param string $email   Address.
	 * @param int    $after   Only ids above this.
	 * @return array[]
	 */
	private static function cards( string $email, int $after ): array {
		global $wpdb;
		$t    = GFC_Schema::cards();
		$user = get_user_by( 'email', $email );
		$uid  = $user instanceof WP_User ? (int) $user->ID : -1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE id > %d AND ( buyer_email = %s OR recipient_email = %s OR customer_id = %d ) ORDER BY id ASC LIMIT %d", $after, $email, $email, $uid, self::PER_PAGE ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Money from cents. */
	private static function money( $cents, string $currency ): string {
		return wc_format_decimal( (int) $cents / 100, 2 ) . ' ' . $currency;
	}

	/** True when a card can no longer be spent or delivered. */
	private static function finished( array $c ): bool {
		$status = (string) $c['status'];
		if ( in_array( $status, array( 'void', 'expired' ), true ) ) {
			return true;
		}
		return in_array( $status, array( 'active', 'disabled', 'locked' ), true ) && (int) $c['balance'] <= 0;
	}

	public static function export( $email, $page = 1 ): array {
		$email = strtolower( trim( (string) $email ) );
		$page  = max( 1, (int) $page );
		$items = array();
		$done  = true;
		if ( is_email( $email ) ) {
			// Pages walk by offset in ids: page n starts after the last id of page n-1.
			$after = 0;
			$rows  = array();
			for ( $p = 1; $p <= $page; $p++ ) {
				$rows = self::cards( $email, $after );
				if ( ! $rows ) {
					break;
				}
				$last  = end( $rows );
				$after = (int) $last['id'];
			}
			$done = count( $rows ) < self::PER_PAGE;
			foreach ( $rows as $c ) {
				$cur  = (string) $c['currency'];
				$data = array(
					array(
						'name'  => __( 'Κάρτα', 'gift-cards' ),
						'value' => '…' . (string) $c['last4'],
					),
					array(
						'name'  => __( 'Αρχικό ποσό', 'gift-cards' ),
						'value' => self::money( $c['initial'], $cur ),
					),
					array(
						'name'  => __( 'Υπόλοιπο', 'gift-cards' ),
						'value' => self::money( $c['balance'], $cur ),
					),
					array(
						'name'  => __( 'Κατάσταση', 'gift-cards' ),
						'value' => (string) $c['status'],
					),
					array(
						'name'  => __( 'Δημιουργία', 'gift-cards' ),
						'value' => (string) $c['created_at'] . ' UTC',
					),
				);
				foreach ( array(
					'buyer_email'     => __( 'Email αγοραστή', 'gift-cards' ),
					'recipient_name'  => __( 'Όνομα παραλήπτη', 'gift-cards' ),
					'recipient_email' => __( 'Email παραλήπτη', 'gift-cards' ),
					'sender_name'     => __( 'Όνομα αποστολέα', 'gift-cards' ),
					'message'         => __( 'Μήνυμα', 'gift-cards' ),
					'expires_at'      => __( 'Λήξη', 'gift-cards' ),
				) as $key => $label ) {
					if ( isset( $c[ $key ] ) && '' !== (string) $c[ $key ] ) {
						$data[] = array(
							'name'  => $label,
							'value' => (string) $c[ $key ],
						);
					}
				}
				$items[] = array(
					'group_id'    => 'gift-cards',
					'group_label' => __( 'Δωροκάρτες', 'gift-cards' ),
					'item_id'     => 'gfc-' . (int) $c['id'],
					'data'        => $data,
				);
			}
		}
		return array(
			'data' => $items,
			'done' => $done,
		);
	}

	public static function erase( $email, $page = 1 ): array {
		global $wpdb;
		$email    = strtolower( trim( (string) $email ) );
		$removed  = false;
		$kept     = 0;
		$messages = array();
		if ( is_email( $email ) ) {
			$t     = GFC_Schema::cards();
			$after = 0;
			do {
				$rows = self::cards( $email, $after );
				foreach ( $rows as $c ) {
					$after = (int) $c['id'];
					if ( ! self::finished( $c ) ) {
						++$kept;
						continue;
					}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
					$n = $wpdb->update(
						$t,
						array(
							'buyer_email'     => '',
							'recipient_name'  => '',
							'recipient_email' => '',
							'sender_name'     => '',
							'message'         => null,
							'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
						),
						array( 'id' => (int) $c['id'] )
					);
					if ( $n ) {
						$removed = true;
					}
				}
			} while ( count( $rows ) === self::PER_PAGE );
		}
		if ( $kept > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of gift cards */
				__( 'Κρατήθηκαν %d δωροκάρτες που μπορούν ακόμη να χρησιμοποιηθούν ή περιμένουν αποστολή: είναι χρήματα που οφείλει το κατάστημα. Τα στοιχεία τους σβήνονται όταν τελειώσουν, με νέο αίτημα διαγραφής.', 'gift-cards' ),
				$kept
			);
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $kept > 0,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/** Daily (GFC_Issue::daily): names and message of cards finished longer than keep_days. */
	public static function retention(): int {
		global $wpdb;
		$days = (int) GFC_Settings::get()['keep_days'];
		if ( $days <= 0 ) {
			return 0;
		}
		$t      = GFC_Schema::cards();
		$before = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE updated_at <= %s AND ( status IN ('void','expired') OR ( status IN ('active','disabled','locked') AND balance <= 0 ) ) AND ( recipient_name <> '' OR sender_name <> '' OR ( message IS NOT NULL AND message <> '' ) ) LIMIT 500", $before ) );
		$n   = 0;
		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			$n += (int) (bool) $wpdb->update(
				$t,
				array(
					'recipient_name' => '',
					'sender_name'    => '',
					'message'        => null,
					'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => (int) $id )
			);
		}
		return $n;
	}

	/**
	 * WooCommerce removes an order's personal data: clean the gift card
	 * lines whose cards are all finished, and those cards.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_lines( $order ): void {
		global $wpdb;
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$cards = GFC_Store::by_order( $order->get_id() );
		$open  = array();
		foreach ( $cards as $c ) {
			if ( ! self::finished( $c ) ) {
				$open[ (int) $c['item_id'] ] = true;
			}
		}
		foreach ( $order->get_items() as $item_id => $line ) {
			if ( ! $line->get_meta( '_gfc' ) || isset( $open[ (int) $item_id ] ) ) {
				continue;
			}
			$g = json_decode( (string) $line->get_meta( '_gfc' ), true );
			if ( is_array( $g ) ) {
				foreach ( array( 'name', 'email', 'sender', 'message' ) as $k ) {
					if ( isset( $g[ $k ] ) ) {
						$g[ $k ] = '';
					}
				}
				$line->update_meta_data( '_gfc', wp_json_encode( $g ) );
			}
			$line->delete_meta_data( 'gfc_to' );
			$line->delete_meta_data( 'gfc_from' );
			$line->save();
		}
		foreach ( $cards as $c ) {
			if ( self::finished( $c ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
				$wpdb->update(
					GFC_Schema::cards(),
					array(
						'buyer_email'     => '',
						'recipient_name'  => '',
						'recipient_email' => '',
						'sender_name'     => '',
						'message'         => null,
						'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
					),
					array( 'id' => (int) $c['id'] )
				);
			}
		}
	}

	/** Suggested text for the privacy policy (Settings → Privacy → Policy guide). */
	public static function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p>' . esc_html__( 'Όταν αγοράζεις δωροκάρτα κρατάμε το email σου, το όνομα και το email του παραλήπτη, το όνομα αποστολέα και το μήνυμα που γράφεις, για να στείλουμε την κάρτα, να σου πούμε πότε στάλθηκε και να θυμίσουμε τη λήξη. Ο κωδικός δεν αποθηκεύεται ποτέ ως κείμενο, μόνο ως κρυπτογραφημένο αποτύπωμα.', 'gift-cards' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Το ποσό, το υπόλοιπο και κάθε κίνηση της κάρτας μένουν ως λογιστικό αρχείο. Όσο η κάρτα έχει υπόλοιπο κρατάμε και τα στοιχεία επικοινωνίας της. Όταν εξαντληθεί, λήξει ή ακυρωθεί, τα ονόματα και το μήνυμα σβήνονται μετά από τον χρόνο που ορίζει το κατάστημα, και τα email σβήνονται όταν ζητήσεις διαγραφή των δεδομένων σου.', 'gift-cards' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Για να εμποδίσουμε τις δοκιμές κωδικών μετράμε τις αποτυχημένες προσπάθειες ανά διεύθυνση IP για λίγα λεπτά· η διεύθυνση κρατιέται μόνο ως κρυπτογραφημένο αποτύπωμα.', 'gift-cards' ) . '</p>';
		wp_add_privacy_policy_content( 'Gift Cards', wp_kses_post( $text ) );
	}
}

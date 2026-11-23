<?php
/**
 * GFC_Privacy — WordPress's personal data exporter and eraser (Tools →
 * Export / Erase Personal Data) for gift cards, the retention clean-up and
 * the suggested privacy policy text (Bible §12 «Προσωπικά δεδομένα»).
 *
 * An address is found as the buyer (buyer_email, or customer_id of the
 * user with that email) or as the recipient (recipient_email).
 *
 * Eraser:
 *  - An open card (not void or expired, balance above zero) is kept as it
 *    is and reported as retained: it is money the shop owes to whoever
 *    holds the code, and its emails (delivery, reminder) need the
 *    addresses (GDPR art. 17(3)(b) and (e)).
 *  - A closed card (void, expired, or used up) keeps its amounts, dates,
 *    status and ledger (accounting record) and loses what belongs to the
 *    address: as buyer the email, the customer link and the sender name,
 *    as recipient the name and the email; the message in both cases.
 *  - The order line of a card (the _gfc data and the «Προς» / «Από» lines)
 *    is cleaned the same way when WooCommerce removes the order's personal
 *    data, and only when every card of that line is closed.
 *
 * Retention (setting keep_days, 0 = never): the daily job clears the names
 * and the message of cards closed for longer than keep_days.
 */

defined( 'ABSPATH' ) || exit;

final class GFC_Privacy {

	const KEY      = 'gift-cards';
	const PER_PAGE = 50;

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( __CLASS__, 'order_lines' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
	}

	public static function exporters( $list ): array {
		$list              = is_array( $list ) ? $list : array();
		$list[ self::KEY ] = array(
			'exporter_friendly_name' => __( 'Gift Cards: δωροκάρτες', 'gift-cards' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	public static function erasers( $list ): array {
		$list              = is_array( $list ) ? $list : array();
		$list[ self::KEY ] = array(
			'eraser_friendly_name' => __( 'Gift Cards: δωροκάρτες', 'gift-cards' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	/** True when the card no longer has money on it (void, expired, used up). */
	public static function closed( array $card ): bool {
		return in_array( $card['status'], array( 'void', 'expired' ), true ) || (int) $card['balance'] <= 0 && null !== $card['code_hmac'];
	}

	/**
	 * One page of the address's cards, oldest first: by page (export) or
	 * after a card id (eraser, whose set shrinks as it works).
	 *
	 * @return array<int,array>
	 */
	private static function cards( string $email, int $page, int $after = 0 ): array {
		global $wpdb;
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return array();
		}
		$user = get_user_by( 'email', $email );
		$uid  = $user ? (int) $user->ID : -1;
		$t    = GFC_Schema::cards();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE ( buyer_email = %s OR recipient_email = %s OR customer_id = %d ) AND id > %d ORDER BY id LIMIT %d OFFSET %d", $email, $email, $uid, $after, self::PER_PAGE, ( max( 1, $page ) - 1 ) * self::PER_PAGE ), ARRAY_A );
		return array_map( array( 'GFC_Store', 'cast' ), is_array( $rows ) ? $rows : array() );
	}

	public static function export( $email, $page = 1 ): array {
		$cards = self::cards( (string) $email, (int) $page );
		$items = array();
		foreach ( $cards as $c ) {
			$data = array(
				array(
					'name'  => __( 'Κάρτα', 'gift-cards' ),
					'value' => '#' . $c['id'] . ( '' !== $c['last4'] ? ' (…' . $c['last4'] . ')' : '' ),
				),
				array(
					'name'  => __( 'Αρχικό ποσό', 'gift-cards' ),
					'value' => wp_strip_all_tags( GFC_Settings::money( $c['initial'], $c['currency'] ) ),
				),
				array(
					'name'  => __( 'Υπόλοιπο', 'gift-cards' ),
					'value' => wp_strip_all_tags( GFC_Settings::money( $c['balance'], $c['currency'] ) ),
				),
				array(
					'name'  => __( 'Κατάσταση', 'gift-cards' ),
					'value' => (string) $c['status'],
				),
				array(
					'name'  => __( 'Δημιουργήθηκε', 'gift-cards' ),
					'value' => (string) $c['created_at'] . ' UTC',
				),
			);
			if ( $c['order_id'] ) {
				$data[] = array(
					'name'  => __( 'Παραγγελία', 'gift-cards' ),
					'value' => '#' . $c['order_id'],
				);
			}
			foreach ( array(
				'buyer_email'     => __( 'Email αγοραστή', 'gift-cards' ),
				'recipient_name'  => __( 'Όνομα παραλήπτη', 'gift-cards' ),
				'recipient_email' => __( 'Email παραλήπτη', 'gift-cards' ),
				'sender_name'     => __( 'Από', 'gift-cards' ),
				'message'         => __( 'Μήνυμα', 'gift-cards' ),
				'delivered_at'    => __( 'Παραδόθηκε', 'gift-cards' ),
				'expires_at'      => __( 'Λήγει', 'gift-cards' ),
			) as $k => $label ) {
				if ( isset( $c[ $k ] ) && '' !== (string) $c[ $k ] ) {
					$data[] = array(
						'name'  => $label,
						'value' => (string) $c[ $k ] . ( in_array( $k, array( 'delivered_at', 'expires_at' ), true ) ? ' UTC' : '' ),
					);
				}
			}
			$items[] = array(
				'group_id'    => self::KEY,
				'group_label' => __( 'Δωροκάρτες', 'gift-cards' ),
				'item_id'     => 'gfc-' . $c['id'],
				'data'        => $data,
			);
		}
		return array(
			'data' => $items,
			'done' => count( $cards ) < self::PER_PAGE,
		);
	}

	public static function erase( $email, $page = 1 ): array {
		$email = sanitize_email( (string) $email );
		$user  = is_email( $email ) ? get_user_by( 'email', $email ) : false;
		$uid   = $user ? (int) $user->ID : -1;
		// One call walks every card by id (the set shrinks as cards are
		// cleaned, so offsets would skip or repeat rows).
		$retained = 0;
		$removed  = false;
		$after    = 0;
		$guard    = 0;
		do {
			$cards = self::cards( $email, 1, $after );
			foreach ( $cards as $c ) {
				$after = $c['id'];
				if ( self::closed( $c ) ) {
					$removed = self::scrub_for( $c, $email, $uid ) || $removed;
				} else {
					++$retained;
				}
			}
			++$guard;
		} while ( count( $cards ) >= self::PER_PAGE && $guard < 200 );

		$messages = array();
		if ( $retained > 0 ) {
			$messages[] = sprintf(
				/* translators: %d: number of gift cards */
				__( 'Gift Cards: κρατήθηκαν %d δωροκάρτες με υπόλοιπο. Είναι χρήματα που οφείλει το κατάστημα σε όποιον έχει τον κωδικό και τα email τους χρειάζονται για την αποστολή και τις υπενθυμίσεις (ΓΚΠΔ άρθρο 17 παρ. 3 στοιχ. β και ε). Σβήνονται όταν η κάρτα εξαντληθεί, λήξει ή ακυρωθεί.', 'gift-cards' ),
				$retained
			);
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained > 0,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Clears the personal fields of one card. With $emails the buyer and
	 * recipient emails and the customer link go too (eraser); without, only
	 * the names and the message (retention).
	 */
	public static function scrub( int $card_id, bool $emails ): bool {
		global $wpdb;
		$set = array(
			'recipient_name' => '',
			'sender_name'    => '',
			'message'        => '',
			'updated_at'     => current_time( 'mysql', true ),
		);
		if ( $emails ) {
			$set['buyer_email']     = '';
			$set['recipient_email'] = '';
			$set['customer_id']     = 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$n = $wpdb->update( GFC_Schema::cards(), $set, array( 'id' => $card_id ) );
		return (bool) $n;
	}

	/**
	 * Eraser: clears what belongs to the address on one closed card. The
	 * buyer side is the buyer email, the customer link and the sender name;
	 * the recipient side is the recipient name and email. The message goes
	 * with either side (written by one, addressed to the other).
	 */
	private static function scrub_for( array $c, string $email, int $uid ): bool {
		global $wpdb;
		$set = array( 'message' => '' );
		if ( 0 === strcasecmp( $email, (string) $c['buyer_email'] ) || ( $uid > 0 && $uid === $c['customer_id'] ) ) {
			$set['buyer_email'] = '';
			$set['customer_id'] = 0;
			$set['sender_name'] = '';
		}
		if ( 0 === strcasecmp( $email, (string) $c['recipient_email'] ) ) {
			$set['recipient_email'] = '';
			$set['recipient_name']  = '';
		}
		$set['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return (bool) $wpdb->update( GFC_Schema::cards(), $set, array( 'id' => (int) $c['id'] ) );
	}

	/** Daily: names and message of cards closed longer than keep_days. */
	public static function retention(): int {
		global $wpdb;
		$days = (int) GFC_Settings::get()['keep_days'];
		if ( $days <= 0 ) {
			return 0;
		}
		$t      = GFC_Schema::cards();
		$before = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $t WHERE updated_at <= %s AND ( status IN ('void','expired') OR ( balance <= 0 AND code_hmac IS NOT NULL ) ) AND ( recipient_name <> '' OR sender_name <> '' OR ( message IS NOT NULL AND message <> '' ) ) LIMIT 500", $before ) );
		$n   = 0;
		foreach ( $ids as $id ) {
			$n += self::scrub( (int) $id, false ) ? 1 : 0;
		}
		return $n;
	}

	/**
	 * WooCommerce removes an order's personal data: clean our order line
	 * data too, for lines whose cards are all closed.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_lines( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$cards = GFC_Store::by_order( $order->get_id() );
		$open  = array();
		foreach ( $cards as $c ) {
			if ( ! self::closed( $c ) ) {
				$open[ $c['item_id'] ] = true;
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
			if ( self::closed( $c ) ) {
				self::scrub( $c['id'], true );
			}
		}
	}

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

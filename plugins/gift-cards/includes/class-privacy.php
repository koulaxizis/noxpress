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
 */

defined( 'ABSPATH' ) || exit;

final class GFC_Privacy {

	/** Cards per page. */
	const PER_PAGE = 100;

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
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
}

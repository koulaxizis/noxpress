<?php
/**
 * GFC_Issue — cards from paid orders, review, delivery, expiry, voids.
 *
 *  - A card is created when its order is paid (self::paid): a paid status
 *    (settings, default processing / completed), and for payment methods
 *    that set "processing" before any money arrives (cash on delivery) or
 *    that the shop confirms by hand (bank transfer, cheque) only once the
 *    shop marks the order completed (or processing, for transfer and
 *    cheque). Hooked at woocommerce_order_status_{status} priority 5: one card per unit of every gift card line, with the unique
 *    issue key o:order:item:unit, so a second payment callback creates
 *    nothing. The card gets its balance (issue movement) at once, but no
 *    code yet: the code is created at delivery (GFC_Store::deliver_code).
 *  - Delivery is an Action Scheduler job (group gift-cards) at the chosen
 *    date, 09:00 site time, or right after payment, plus the optional delay
 *    (E10). Before sending, the card and the order are checked again.
 *  - Review (E10): when the gift cards of one order are worth more than the
 *    setting, the cards wait in "review" until a shop manager approves.
 *  - A refunded or cancelled purchase first gives back what unpaid orders
 *    hold on its cards, then voids the unused cards; a card already spent
 *    in a paid order is disabled (the shop can enable it again) and the
 *    order gets a note. Partial refunds of gift card
 *    lines void as many unused cards of that line.
 *  - Daily job: expiry (E6) with a reminder, missed deliveries, invariant
 *    check of every card, old limit counters, unpaid orders that took a
 *    card (GFC_Redeem::cleanup_unpaid).
 */

defined( 'ABSPATH' ) || exit;

final class GFC_Issue {

	const GROUP = 'gift-cards';

	public static function init(): void {
		foreach ( array( 'processing', 'completed', 'on-hold' ) as $st ) {
			add_action( 'woocommerce_order_status_' . $st, array( __CLASS__, 'on_paid' ), 5, 1 );
		}
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_purchase_reversed' ), 5, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_purchase_reversed' ), 5, 1 );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'on_purchase_reversed' ), 5, 1 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_partial_refund' ), 20, 2 );

		add_action( 'gfc_deliver', array( __CLASS__, 'deliver' ), 10, 1 );
		add_action( 'gfc_daily', array( __CLASS__, 'daily' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || false !== as_next_scheduled_action( 'gfc_daily', array(), self::GROUP ) ) {
			return;
		}
		$tz    = wp_timezone();
		$first = new DateTimeImmutable( 'tomorrow 03:30', $tz );
		as_schedule_recurring_action( $first->getTimestamp(), DAY_IN_SECONDS, 'gfc_daily', array(), self::GROUP );
	}

	/* =====================================================================
	 * Issue
	 * =================================================================== */

	public static function on_paid( $order_id ): void {
		try {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order instanceof WC_Order || ! self::paid( $order ) ) {
				return;
			}
			self::issue_for_order( $order );
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'gift-cards issue: ' . $e->getMessage(), array( 'source' => 'gift-cards' ) );
			}
		}
	}

	/**
	 * Whether the money of an order has arrived, as far as the shop can know.
	 * The status alone is not enough: cash on delivery puts a virtual order
	 * in "processing" with nothing paid yet.
	 */
	public static function paid( WC_Order $order ): bool {
		$status = $order->get_status();
		if ( ! in_array( $status, GFC_Settings::get()['paid_statuses'], true ) ) {
			return false;
		}
		$method = (string) $order->get_payment_method();
		/**
		 * Payment methods that do not collect the money themselves. Their
		 * orders issue cards only after the shop confirms the payment.
		 *
		 * @param string[] $methods Gateway ids.
		 */
		$after_collect = (array) apply_filters( 'gfc_collect_on_delivery_methods', array( 'cod' ) );
		$manual        = (array) apply_filters( 'gfc_manual_payment_methods', array( 'bacs', 'cheque' ) );
		if ( in_array( $method, $after_collect, true ) ) {
			return 'completed' === $status;
		}
		if ( in_array( $method, $manual, true ) ) {
			return in_array( $status, array( 'processing', 'completed' ), true );
		}
		return true;
	}

	/** @return int Cards created now. */
	public static function issue_for_order( WC_Order $order ): int {
		$s       = GFC_Settings::get();
		$lines   = array();
		$total   = 0;
		$short   = false;
		foreach ( $order->get_items() as $item_id => $line ) {
			if ( ! $line instanceof WC_Order_Item_Product ) {
				continue;
			}
			$g = GFC_Product::line_data( $line );
			if ( ! $g ) {
				continue;
			}
			$qty = max( 0, (int) $line->get_quantity() + (int) $order->get_qty_refunded_for_item( $item_id ) );
			if ( $qty <= 0 ) {
				continue;
			}
			$lines[] = array( (int) $item_id, $g, $qty );
			$total  += $g['amount'] * $qty;
			// The line must have been paid in full (no discount of any kind).
			if ( (int) round( ( (float) $line->get_total() + (float) $line->get_total_tax() ) * 100 ) < $g['amount'] * (int) $line->get_quantity() ) {
				$short = true;
			}
		}
		if ( ! $lines ) {
			return 0;
		}
		$review  = $short || ( $s['review_over'] > 0 && $total > $s['review_over'] );
		$created = array();
		foreach ( $lines as $l ) {
			list( $item_id, $g, $qty ) = $l;
			for ( $seq = 1; $seq <= $qty; $seq++ ) {
				$me  = 'me' === $g['for'];
				$res = GFC_Store::create(
					array(
						'issue_key'       => 'o:' . $order->get_id() . ':' . $item_id . ':' . $seq,
						'currency'        => $order->get_currency(),
						'status'          => $review ? 'review' : 'pending',
						'source'          => 'order',
						'order_id'        => $order->get_id(),
						'item_id'         => $item_id,
						'customer_id'     => (int) $order->get_customer_id(),
						'buyer_email'     => mb_substr( (string) $order->get_billing_email(), 0, 100 ),
						'for_me'          => $me ? 1 : 0,
						'recipient_name'  => mb_substr( $me ? trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) : $g['name'], 0, 100 ),
						'recipient_email' => mb_substr( $me ? (string) $order->get_billing_email() : $g['email'], 0, 100 ),
						'sender_name'     => $me ? '' : $g['sender'],
						'message'         => $me ? '' : $g['message'],
						'lang'            => $g['lang'],
						'deliver_at'      => self::deliver_at( $g['date'] ),
					),
					$g['amount'],
					/* translators: %s: order number */
					sprintf( __( 'Αγορά, παραγγελία #%s', 'gift-cards' ), $order->get_order_number() )
				);
				if ( $res && $res['created'] ) {
					$created[] = $res['id'];
				}
			}
		}
		if ( ! $created ) {
			return 0;
		}
		if ( $short ) {
			$order->add_order_note( __( 'Gift Cards: η γραμμή της δωροκάρτας πληρώθηκε λιγότερο από την αξία της (έκπτωση;). Οι κάρτες περιμένουν έγκριση.', 'gift-cards' ) );
		}
		if ( $review ) {
			$order->add_order_note(
				/* translators: %d: number of cards */
				sprintf( __( 'Gift Cards: δημιουργήθηκαν %d δωροκάρτες σε αναμονή έγκρισης (ποσό πάνω από το όριο ελέγχου). Έγκριση στο Noxpress › Δωροκάρτες.', 'gift-cards' ), count( $created ) )
			);
			GFC_Emails::review( $order, $created );
		} else {
			foreach ( $created as $id ) {
				self::schedule_delivery( $id );
			}
			/* translators: %d: number of cards */
			$order->add_order_note( sprintf( __( 'Gift Cards: δημιουργήθηκαν %d δωροκάρτες· ο κωδικός φτιάχνεται και στέλνεται την ημέρα αποστολής.', 'gift-cards' ), count( $created ) ) );
		}
		return count( $created );
	}

	/** UTC datetime of delivery: the chosen date at 09:00 site time, or now; plus the delay. */
	private static function deliver_at( string $date ): string {
		$tz    = wp_timezone();
		$delay = (int) GFC_Settings::get()['delay_hours'] * HOUR_IN_SECONDS;
		$ts    = time() + $delay;
		if ( '' !== $date && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$at = ( new DateTimeImmutable( $date . ' 09:00:00', $tz ) )->getTimestamp();
			$ts = max( $ts, $at );
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	public static function schedule_delivery( int $card_id ): void {
		$card = GFC_Store::get( $card_id );
		if ( ! $card || 'pending' !== $card['status'] ) {
			return;
		}
		$ts = $card['deliver_at'] ? (int) strtotime( $card['deliver_at'] . ' UTC' ) : time();
		$ts = max( time(), $ts );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( 'gfc_deliver', array( $card_id ), self::GROUP );
			}
			as_schedule_single_action( $ts, 'gfc_deliver', array( $card_id ), self::GROUP );
		} else {
			wp_schedule_single_event( $ts, 'gfc_deliver', array( $card_id ) );
		}
	}

	/** Approves cards in review (E10): they go to delivery. */
	public static function approve( int $card_id, int $user_id ): bool {
		if ( ! GFC_Store::set_status( $card_id, 'pending', __( 'Εγκρίθηκε για αποστολή.', 'gift-cards' ), $user_id, 'review' ) ) {
			return false;
		}
		self::schedule_delivery( $card_id );
		return true;
	}

	/* =====================================================================
	 * Delivery
	 * =================================================================== */

	public static function deliver( $card_id ): void {
		try {
			$card = GFC_Store::get( (int) $card_id );
			if ( ! $card || 'pending' !== $card['status'] ) {
				return;
			}
			if ( 'order' === $card['source'] ) {
				$order = wc_get_order( $card['order_id'] );
				if ( ! $order instanceof WC_Order ) {
					return;
				}
				if ( in_array( $order->get_status(), array( 'refunded', 'cancelled', 'failed' ), true ) ) {
					self::void_card( $card, __( 'Η παραγγελία αγοράς ακυρώθηκε ή επιστράφηκε.', 'gift-cards' ) );
					return;
				}
				if ( ! self::paid( $order ) ) {
					return; // Not paid (any more): the daily job looks again.
				}
			}
			$months  = (int) GFC_Settings::get()['expiry_months'];
			$expires = $months > 0 ? gmdate( 'Y-m-d H:i:s', strtotime( '+' . $months . ' months' ) ) : '';
			$code    = GFC_Store::deliver_code( $card['id'], $expires );
			if ( null === $code ) {
				return;
			}
			$card = GFC_Store::get( $card['id'] );
			if ( ! GFC_Emails::card( $card, $code ) ) {
				GFC_Store::note( $card['id'], 'status', __( 'Το email δεν στάλθηκε: χρειάζεται «Νέος κωδικός και αποστολή».', 'gift-cards' ) );
				GFC_Emails::admin_alert(
					__( 'Gift Cards: μια δωροκάρτα δεν στάλθηκε', 'gift-cards' ),
					/* translators: %d: card id */
					sprintf( __( 'Το email της δωροκάρτας #%d δεν στάλθηκε. Ο κωδικός δεν φυλάγεται, οπότε άνοιξε την κάρτα και πάτα «Νέος κωδικός και αποστολή» όταν διορθωθεί η αποστολή emails.', 'gift-cards' ), $card['id'] ),
					admin_url( 'admin.php?page=gfc-cards&card=' . $card['id'] )
				);
			}
			unset( $code );
			if ( ! $card['for_me'] && 'order' === $card['source'] ) {
				GFC_Emails::sent( $card );
			}
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'gift-cards deliver: ' . $e->getMessage(), array( 'source' => 'gift-cards' ) );
			}
		}
	}

	/* =====================================================================
	 * Reversed purchases
	 * =================================================================== */

	public static function on_purchase_reversed( $order_id ): void {
		try {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$used = 0;
			$why  = __( 'Η παραγγελία αγοράς ακυρώθηκε ή επιστράφηκε.', 'gift-cards' );
			foreach ( GFC_Store::by_order( $order->get_id() ) as $card ) {
				if ( in_array( $card['status'], array( 'active', 'disabled' ), true ) && $card['balance'] < $card['initial'] ) {
					// Money held by unpaid orders is not spent: give it back first.
					self::release_unpaid_holds( $card['id'], $order->get_id() );
					$card = GFC_Store::get( $card['id'] ) ?? $card;
				}
				if ( self::void_card( $card, $why ) ) {
					continue;
				}
				if ( 'active' === $card['status'] ) {
					GFC_Store::set_status( $card['id'], 'disabled', $why, 0, 'active' );
					++$used;
				} elseif ( 'disabled' === $card['status'] ) {
					++$used;
				}
			}
			if ( $used ) {
				$order->add_order_note(
					/* translators: %d: number of cards */
					sprintf( __( 'Gift Cards: %d δωροκάρτες αυτής της παραγγελίας έχουν ήδη χρησιμοποιηθεί, οπότε απενεργοποιήθηκαν αντί να ακυρωθούν. Δες τες στο Noxpress › Δωροκάρτες πριν επιστρέψεις χρήματα.', 'gift-cards' ), $used )
				);
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** Gives back to a card what orders that are not paid hold on it. */
	private static function release_unpaid_holds( int $card_id, int $purchase_id ): void {
		$ids = array();
		foreach ( GFC_Store::ledger( $card_id ) as $row ) {
			$oid = (int) ( $row['order_id'] ?? 0 );
			if ( $oid && $oid !== $purchase_id ) {
				$ids[ $oid ] = true;
			}
		}
		foreach ( array_keys( $ids ) as $oid ) {
			$o = wc_get_order( $oid );
			if ( ! $o instanceof WC_Order || GFC_Store::order_net( $card_id, $oid ) <= 0 ) {
				continue;
			}
			if ( $o->is_paid() || in_array( $o->get_status(), array( 'refunded' ), true ) ) {
				continue;
			}
			GFC_Redeem::restore_order( $o, true, __( 'Η αγορά της δωροκάρτας ακυρώθηκε ή επιστράφηκε.', 'gift-cards' ) );
		}
	}

	public static function on_partial_refund( $order_id, $refund_id ): void {
		try {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$cards = GFC_Store::by_order( $order->get_id() );
			if ( ! $cards ) {
				return;
			}
			foreach ( $order->get_items() as $item_id => $line ) {
				$refunded = -1 * (int) $order->get_qty_refunded_for_item( $item_id );
				if ( $refunded <= 0 ) {
					continue;
				}
				$mine   = array_filter(
					$cards,
					static function ( $c ) use ( $item_id ) {
						return (int) $c['item_id'] === (int) $item_id;
					}
				);
				$voided = count(
					array_filter(
						$mine,
						static function ( $c ) {
							return 'void' === $c['status'];
						}
					)
				);
				// Not delivered first, then unused active ones.
				usort(
					$mine,
					static function ( $a, $b ) {
						return ( 'active' === $a['status'] ? 1 : 0 ) <=> ( 'active' === $b['status'] ? 1 : 0 );
					}
				);
				foreach ( $mine as $card ) {
					if ( $voided >= $refunded ) {
						break;
					}
					if ( 'void' !== $card['status'] && self::void_card( $card, __( 'Επιστροφή χρημάτων της γραμμής αγοράς.', 'gift-cards' ) ) ) {
						++$voided;
					}
				}
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** Voids a card that was never used. False when it was used (or is already void). */
	public static function void_card( array $card, string $why, int $user_id = 0 ): bool {
		$unused = in_array( $card['status'], array( 'pending', 'review' ), true )
			|| ( in_array( $card['status'], array( 'active', 'disabled', 'expired' ), true ) && $card['balance'] === $card['initial'] );
		if ( ! $unused ) {
			return false;
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'gfc_deliver', array( $card['id'] ), self::GROUP );
		}
		$res = GFC_Store::move(
			$card['id'],
			-$card['balance'],
			'void',
			array(
				'order_id' => $card['order_id'],
				'user_id'  => $user_id,
				'note'     => $why,
				'uniq'     => 'void:' . $card['id'],
				'status'   => 'void',
			)
		);
		return $res['ok'];
	}

	/* =====================================================================
	 * Daily
	 * =================================================================== */

	public static function daily(): void {
		global $wpdb;
		$t   = GFC_Schema::cards();
		$now = gmdate( 'Y-m-d H:i:s' );

		// Expiry.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $t WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at > '1971-01-01' AND expires_at <= %s LIMIT 500", $now ) );
		foreach ( $ids as $id ) {
			$card = GFC_Store::get( (int) $id );
			if ( $card ) {
				GFC_Store::move(
					$card['id'],
					-$card['balance'],
					'expire',
					array(
						'note'   => __( 'Έληξε.', 'gift-cards' ),
						'uniq'   => 'expire:' . $card['id'],
						'status' => 'expired',
					)
				);
			}
		}

		// Reminders before expiry.
		$days = (int) GFC_Settings::get()['remind_days'];
		if ( $days > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $t WHERE status = 'active' AND reminded = 0 AND balance > 0 AND expires_at IS NOT NULL AND expires_at > '1971-01-01' AND expires_at <= %s LIMIT 200", gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) ) );
			foreach ( $ids as $id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
				$wpdb->update( $t, array( 'reminded' => 1 ), array( 'id' => (int) $id ) );
				$card = GFC_Store::get( (int) $id );
				if ( $card ) {
					GFC_Emails::remind( $card );
				}
			}
		}

		// Deliveries that were missed (job lost, plugin inactive on the day).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $t WHERE status = 'pending' AND deliver_at <= %s LIMIT 500", $now ) );
		foreach ( $ids as $id ) {
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( 'gfc_deliver', array( (int) $id ), self::GROUP ) ) {
				self::schedule_delivery( (int) $id );
			}
		}

		// Every card against its ledger.
		foreach ( GFC_Store::broken() as $b ) {
			GFC_Store::lock_card( $b['id'], 'daily check: balance ' . $b['balance'] . ' ledger ' . $b['ledger'] );
		}

		GFC_Limiter::cleanup();
		GFC_Redeem::cleanup_unpaid();
	}
}

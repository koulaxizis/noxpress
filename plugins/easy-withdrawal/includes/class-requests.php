<?php
/**
 * EWD_Requests — withdrawal requests stored with the order (design §6, 6Α).
 *
 * Order meta (CRUD only, so HPOS and the legacy posts table both work):
 *  - _ewd_requests  list of requests (newest last), each:
 *      id (string "<order number>-<n>"), created (Unix UTC), name, email,
 *      lang ('el' | 'en'), reason, items [ item_id, product_id, name, qty,
 *      unit ], status (new | progress | done | disputed), source (form |
 *      account | link), user_id, log [ t, status, user, note ]
 *  - _ewd_state     the most urgent status among the order's requests
 *                   (new > progress > disputed > done), for the admin list
 *  - _ewd_last      created time of the newest request (sorting)
 *
 * The requests are the store's record of the consumer's statement: they
 * stay with the order after uninstall (Bible §12 deviation, see the
 * bootstrap docblock).
 */

defined( 'ABSPATH' ) || exit;

final class EWD_Requests {

	const META       = '_ewd_requests';
	const META_STATE = '_ewd_state';
	const META_LAST  = '_ewd_last';

	/** Status keys in urgency order. */
	const STATUSES = array( 'new', 'progress', 'disputed', 'done' );

	/** Status msgids (translated at render). */
	public static function status_labels(): array {
		return array(
			'new'      => __( 'Νέα', 'easy-withdrawal' ),
			'progress' => __( 'Σε εξέλιξη', 'easy-withdrawal' ),
			'done'     => __( 'Ολοκληρώθηκε', 'easy-withdrawal' ),
			'disputed' => __( 'Αμφισβητείται', 'easy-withdrawal' ),
		);
	}

	public static function status_label( string $status ): string {
		$l = self::status_labels();
		return isset( $l[ $status ] ) ? $l[ $status ] : $status;
	}

	/** All requests of an order (validated shape). */
	public static function for_order( WC_Order $order ): array {
		$raw = $order->get_meta( self::META );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $r ) {
			if ( is_array( $r ) && isset( $r['id'], $r['created'], $r['items'], $r['status'] ) && is_array( $r['items'] ) ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	public static function get( WC_Order $order, string $id ): ?array {
		foreach ( self::for_order( $order ) as $r ) {
			if ( (string) $r['id'] === $id ) {
				return $r;
			}
		}
		return null;
	}

	/** Quantities per order item in requests that are not disputed. */
	public static function asked_quantities( WC_Order $order ): array {
		$out = array();
		foreach ( self::for_order( $order ) as $r ) {
			if ( 'disputed' === $r['status'] ) {
				continue;
			}
			foreach ( $r['items'] as $it ) {
				$iid         = (int) ( $it['item_id'] ?? 0 );
				$out[ $iid ] = ( $out[ $iid ] ?? 0 ) + (int) ( $it['qty'] ?? 0 );
			}
		}
		return $out;
	}

	/**
	 * Record a confirmed withdrawal statement.
	 *
	 * @param array $wanted item_id => qty (already validated by the caller
	 *                      against EWD_Rules::items(); checked again here).
	 * @return array|WP_Error The stored request.
	 */
	public static function add( WC_Order $order, array $wanted, string $name, string $reason, string $source ) {
		$rows  = EWD_Rules::items( $order );
		$items = array();
		foreach ( $wanted as $iid => $qty ) {
			$iid = (int) $iid;
			$qty = (int) $qty;
			if ( $qty <= 0 || ! isset( $rows[ $iid ] ) ) {
				continue;
			}
			if ( $qty > $rows[ $iid ]['available'] ) {
				return new WP_Error( 'ewd_qty', __( 'Η ποσότητα που ζήτησες δεν είναι πια διαθέσιμη. Δες ξανά την παραγγελία.', 'easy-withdrawal' ) );
			}
			$items[] = array(
				'item_id'    => $iid,
				'product_id' => $rows[ $iid ]['product_id'],
				'name'       => $rows[ $iid ]['name'],
				'qty'        => $qty,
				'unit'       => round( (float) $rows[ $iid ]['unit'], 4 ),
			);
		}
		if ( ! $items ) {
			return new WP_Error( 'ewd_empty', __( 'Διάλεξε τουλάχιστον ένα προϊόν.', 'easy-withdrawal' ) );
		}

		$all = self::for_order( $order );

		// A double click or a reload of the confirm step: same items within
		// ten minutes → the request already recorded is the answer.
		$sig = self::signature( $items );
		foreach ( array_reverse( $all ) as $r ) {
			if ( time() - (int) $r['created'] > 10 * MINUTE_IN_SECONDS ) {
				break;
			}
			if ( self::signature( $r['items'] ) === $sig ) {
				return $r;
			}
		}

		$now = time();
		$req = array(
			'id'      => $order->get_order_number() . '-' . ( count( $all ) + 1 ),
			'created' => $now,
			'name'    => mb_substr( $name, 0, 120 ),
			'email'   => (string) $order->get_billing_email(),
			'lang'    => EWD_Lang::lang(),
			'reason'  => mb_substr( $reason, 0, 1000 ),
			'items'   => $items,
			'status'  => 'new',
			'source'  => in_array( $source, array( 'form', 'account', 'link' ), true ) ? $source : 'form',
			'user_id' => get_current_user_id(),
			'log'     => array(
				array(
					't'      => $now,
					'status' => 'new',
					'user'   => get_current_user_id(),
					'note'   => '',
				),
			),
		);

		$all[] = $req;
		self::store( $order, $all );

		$order->add_order_note(
			EWD_Lang::with_lang(
				EWD_Lang::site(),
				static function () use ( $req ) {
					return sprintf(
						/* translators: 1: request id, 2: item list */
						__( 'Υπαναχώρηση %1$s: δήλωση του πελάτη για %2$s.', 'easy-withdrawal' ),
						$req['id'],
						EWD_Requests::items_text( $req['items'] )
					);
				}
			)
		);

		do_action( 'noxpress_withdrawal_submitted', $order->get_id(), $req );

		EWD_Emails::send_receipt( $order, $req );
		EWD_Emails::send_admin( $order, $req );

		return $req;
	}

	/**
	 * Change the status of a request (admin).
	 *
	 * @return array|WP_Error The updated request.
	 */
	public static function set_status( WC_Order $order, string $id, string $status, string $note ) {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'ewd_status', __( 'Άγνωστη κατάσταση.', 'easy-withdrawal' ) );
		}
		if ( 'disputed' === $status && '' === trim( $note ) ) {
			return new WP_Error( 'ewd_note', __( 'Η αμφισβήτηση χρειάζεται αιτιολογία: τη λαμβάνει ο πελάτης.', 'easy-withdrawal' ) );
		}
		$all = self::for_order( $order );
		$hit = null;
		$old = '';
		foreach ( $all as $i => $r ) {
			if ( (string) $r['id'] !== $id ) {
				continue;
			}
			$old = (string) $r['status'];
			if ( $old === $status && '' === trim( $note ) ) {
				return $r;
			}
			if ( 'disputed' === $old && 'disputed' !== $status ) {
				// The quantities return to the request: check they are still free.
				$rows = EWD_Rules::items( $order );
				foreach ( $r['items'] as $it ) {
					$iid  = (int) $it['item_id'];
					$free = isset( $rows[ $iid ] ) ? $rows[ $iid ]['qty'] - $rows[ $iid ]['refunded'] - $rows[ $iid ]['asked'] : 0;
					if ( (int) $it['qty'] > $free ) {
						return new WP_Error( 'ewd_qty', __( 'Τα προϊόντα του αιτήματος έχουν ζητηθεί ξανά σε νεότερο αίτημα.', 'easy-withdrawal' ) );
					}
				}
			}
			$r['status'] = $status;
			$r['log'][]  = array(
				't'      => time(),
				'status' => $status,
				'user'   => get_current_user_id(),
				'note'   => mb_substr( sanitize_textarea_field( $note ), 0, 2000 ),
			);
			$all[ $i ]   = $r;
			$hit         = $r;
			break;
		}
		if ( null === $hit ) {
			return new WP_Error( 'ewd_missing', __( 'Το αίτημα δεν βρέθηκε.', 'easy-withdrawal' ) );
		}

		self::store( $order, $all );

		$order->add_order_note(
			EWD_Lang::with_lang(
				EWD_Lang::site(),
				static function () use ( $hit, $note ) {
					$text = sprintf(
						/* translators: 1: request id, 2: status */
						__( 'Υπαναχώρηση %1$s: κατάσταση «%2$s».', 'easy-withdrawal' ),
						$hit['id'],
						EWD_Requests::status_label( $hit['status'] )
					);
					return '' !== trim( $note ) ? $text . ' ' . $note : $text;
				}
			)
		);

		do_action( 'noxpress_withdrawal_status_changed', $order->get_id(), $hit, $old );

		if ( $old !== $status && in_array( $status, array( 'done', 'disputed' ), true ) ) {
			EWD_Emails::send_status( $order, $hit );
		}
		return $hit;
	}

	/** Write the list and the lookup keys. */
	private static function store( WC_Order $order, array $all ): void {
		$state = '';
		$last  = 0;
		foreach ( self::STATUSES as $st ) {
			foreach ( $all as $r ) {
				if ( $st === $r['status'] ) {
					$state = $st;
					break 2;
				}
			}
		}
		foreach ( $all as $r ) {
			$last = max( $last, (int) $r['created'] );
		}
		$order->update_meta_data( self::META, array_values( $all ) );
		$order->update_meta_data( self::META_STATE, $state );
		$order->update_meta_data( self::META_LAST, $last );
		$order->save();
	}

	private static function signature( array $items ): string {
		$pairs = array();
		foreach ( $items as $it ) {
			$pairs[ (int) $it['item_id'] ] = (int) $it['qty'];
		}
		ksort( $pairs );
		return wp_json_encode( $pairs );
	}

	/** "2 × Book A, 1 × Book B" */
	public static function items_text( array $items ): string {
		$parts = array();
		foreach ( $items as $it ) {
			$parts[] = (int) $it['qty'] . ' × ' . (string) $it['name'];
		}
		return implode( ', ', $parts );
	}

	/** Sum of the request's items (gross, as charged). */
	public static function amount( array $req ): float {
		$sum = 0.0;
		foreach ( $req['items'] as $it ) {
			$sum += (float) ( $it['unit'] ?? 0 ) * (int) ( $it['qty'] ?? 0 );
		}
		return $sum;
	}

	/**
	 * Orders with requests, for the admin list.
	 *
	 * @param array $args state ('' | status), search (order number or email), page, per_page.
	 * @return array [ orders => WC_Order[], total => int ]
	 */
	public static function query( array $args ): array {
		$q = array(
			'limit'    => max( 1, (int) ( $args['per_page'] ?? 20 ) ),
			'page'     => max( 1, (int) ( $args['page'] ?? 1 ) ),
			'paginate' => true,
			'type'     => 'shop_order',
			'status'   => array_keys( wc_get_order_statuses() ),
			'meta_key' => self::META_LAST, // phpcs:ignore WordPress.DB.SlowDBQuery -- our lookup key.
			'orderby'  => 'meta_value_num',
			'order'    => 'DESC',
		);
		$state      = (string) ( $args['state'] ?? '' );
		$meta_query = array();
		if ( in_array( $state, self::STATUSES, true ) ) {
			$meta_query = array(
				array(
					'key'   => self::META_STATE,
					'value' => $state,
				),
			);
			$q['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery -- HPOS reads it directly.
		}
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			if ( is_email( $search ) ) {
				$q['billing_email'] = $search;
			} else {
				$ids     = array();
				$number  = ltrim( $search, '#' );
				$number  = preg_replace( '/-\d+$/', '', $number ); // A request id "1234-2" finds its order.
				$hit     = (int) apply_filters( 'ewd_find_order_id', 0, $number );
				if ( $hit > 0 ) {
					$ids[] = $hit;
				}
				if ( ctype_digit( (string) $number ) ) {
					$ids[] = (int) $number;
				}
				$q['post__in'] = $ids ? $ids : array( 0 );
			}
		}
		// The legacy (posts) order store ignores meta_query in wc_get_orders()
		// and only passes it to WP_Query through this filter.
		$cpt = static function ( $wp_args ) use ( $meta_query ) {
			if ( is_array( $wp_args ) ) {
				$wp_args['meta_query']   = isset( $wp_args['meta_query'] ) && is_array( $wp_args['meta_query'] ) ? $wp_args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery
				$wp_args['meta_query'][] = $meta_query;
			}
			return $wp_args;
		};
		$legacy = $meta_query && ! self::hpos();
		if ( $legacy ) {
			unset( $q['meta_query'] );
			add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $cpt, 10, 1 );
		}
		try {
			$res = wc_get_orders( $q );
		} finally {
			if ( $legacy ) {
				remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $cpt, 10 );
			}
		}
		return array(
			'orders' => is_object( $res ) ? $res->orders : array(),
			'total'  => is_object( $res ) ? (int) $res->total : 0,
		);
	}

	/** True when orders live in the HPOS tables. */
	private static function hpos(): bool {
		return class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/** Number of orders with a new request (menu badge), capped at 100. */
	public static function count_new(): int {
		$ids = wc_get_orders(
			array(
				'limit'      => 100,
				'return'     => 'ids',
				'type'       => 'shop_order',
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_key'   => self::META_STATE, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => 'new', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return is_array( $ids ) ? count( $ids ) : 0;
	}
}

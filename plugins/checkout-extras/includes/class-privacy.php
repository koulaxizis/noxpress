<?php
/**
 * CKE_Privacy — WordPress's personal data exporter and eraser (Tools →
 * Export / Erase Personal Data) and the privacy policy text (Bible §12
 * «Προσωπικά δεδομένα», 1.4.0).
 *
 * What Checkout Extras keeps about a customer:
 *  - the gift message on an order (_cke_gift_message; in the Checkout
 *    block also WooCommerce's additional field _wc_other/<BLOCK_MESSAGE>);
 *  - thank-you coupons tied to the customer's e-mail (coupon meta
 *    _cke_email and the coupon's e-mail restriction);
 *  - the carts of Cart Abandonment Recovery for WooCommerce, which this
 *    plugin already cleans up (CKE_Carts): the request's carts are
 *    exported and erased here too, because that plugin has no eraser.
 *
 * Eraser (priority 5, before WooCommerce's own, so the orders are still
 * found by their billing e-mail): gift messages of orders that are no
 * longer open are deleted (an open order still needs the message to be
 * packed; it is kept with a message). The address's thank-you coupons are
 * deleted (the statistics read order meta, so they stay). The address's
 * carts and their child rows are deleted. When WooCommerce anonymises an
 * order (erasure option or its own retention), the gift message goes too.
 * The gift flag and fee stay with the order as order records.
 */

defined( 'ABSPATH' ) || exit;

final class CKE_Privacy {

	const KEY      = 'checkout-extras';
	const PER_PAGE = 50;
	const OPEN     = array( 'pending', 'on-hold', 'processing' );

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ), 5 );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ), 5 );
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( __CLASS__, 'order_lines' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
	}

	public static function exporters( $list ): array {
		$list              = is_array( $list ) ? $list : array();
		$list[ self::KEY ] = array(
			'exporter_friendly_name' => __( 'Checkout Extras: δώρα, κουπόνια, καλάθια', 'checkout-extras' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	public static function erasers( $list ): array {
		$list              = is_array( $list ) ? $list : array();
		$list[ self::KEY ] = array(
			'eraser_friendly_name' => __( 'Checkout Extras: δώρα, κουπόνια, καλάθια', 'checkout-extras' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	private static function email( $email ): string {
		$email = sanitize_email( (string) $email );
		return is_email( $email ) ? strtolower( $email ) : '';
	}

	/** @return WC_Order[] One page of the address's orders, oldest first. */
	private static function orders( string $email, int $page ): array {
		if ( '' === $email || ! function_exists( 'wc_get_orders' ) ) {
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

	/** The gift message of an order, from our meta or the block field. */
	private static function message( WC_Order $order ): string {
		$m = (string) $order->get_meta( CKE_Gift::META_MESSAGE, true );
		if ( '' === $m ) {
			$m = (string) $order->get_meta( '_wc_other/' . CKE_Gift::BLOCK_MESSAGE, true );
		}
		return $m;
	}

	/** Deletes the gift message of an order; true when there was one. */
	private static function drop_message( WC_Order $order ): bool {
		$had = '' !== self::message( $order );
		$order->delete_meta_data( CKE_Gift::META_MESSAGE );
		$order->delete_meta_data( '_wc_other/' . CKE_Gift::BLOCK_MESSAGE );
		if ( $had ) {
			$order->save();
		}
		return $had;
	}

	/** @return int[] Our thank-you coupons tied to the address. */
	private static function coupons( string $email ): array {
		if ( '' === $email ) {
			return array();
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => 'shop_coupon',
					'post_status'      => 'any',
					'fields'           => 'ids',
					'numberposts'      => 500,
					'suppress_filters' => true,
					'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- admin privacy request only.
						array(
							'key'   => CKE_Coupon::META_EMAIL,
							'value' => $email,
						),
					),
				)
			)
		);
	}

	/** Cart Abandonment Recovery's cart table, or '' when it is not there or has no e-mail column. */
	private static function cart_table(): string {
		global $wpdb;
		if ( ! CKE_Carts::available() ) {
			return '';
		}
		$t = $wpdb->prefix . 'cartflows_ca_cart_abandonment';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- third-party table, name built from the prefix.
		$cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$t}" );
		return in_array( 'email', $cols, true ) && in_array( 'session_id', $cols, true ) ? $t : '';
	}

	public static function export( $email, $page = 1 ): array {
		$email = self::email( $email );
		$page  = max( 1, (int) $page );
		$items = array();

		$orders = self::orders( $email, $page );
		foreach ( $orders as $order ) {
			$data = array();
			if ( 'yes' === $order->get_meta( CKE_Gift::META, true ) ) {
				$data[] = array(
					'name'  => __( 'Δώρο', 'checkout-extras' ),
					'value' => __( 'Ναι', 'checkout-extras' ),
				);
				$msg = self::message( $order );
				if ( '' !== $msg ) {
					$data[] = array(
						'name'  => __( 'Μήνυμα δώρου', 'checkout-extras' ),
						'value' => $msg,
					);
				}
			}
			$code = (string) $order->get_meta( CKE_Coupon::META_CODE, true );
			if ( '' !== $code ) {
				$data[] = array(
					'name'  => __( 'Κουπόνι ευχαριστίας', 'checkout-extras' ),
					'value' => $code,
				);
			}
			if ( $data ) {
				$items[] = array(
					'group_id'    => 'cke-orders',
					'group_label' => __( 'Checkout Extras: παραγγελίες', 'checkout-extras' ),
					'item_id'     => 'cke-order-' . $order->get_id(),
					'data'        => array_merge(
						array(
							array(
								'name'  => __( 'Παραγγελία', 'checkout-extras' ),
								'value' => (string) $order->get_order_number(),
							),
						),
						$data
					),
				);
			}
		}

		$done = count( $orders ) < self::PER_PAGE;
		if ( $done ) {
			foreach ( self::coupons( $email ) as $id ) {
				$c = new WC_Coupon( $id );
				if ( ! $c->get_id() ) {
					continue;
				}
				$exp     = $c->get_date_expires();
				$items[] = array(
					'group_id'    => 'cke-coupons',
					'group_label' => __( 'Checkout Extras: κουπόνια ευχαριστίας', 'checkout-extras' ),
					'item_id'     => 'cke-coupon-' . $id,
					'data'        => array(
						array(
							'name'  => __( 'Κωδικός', 'checkout-extras' ),
							'value' => $c->get_code(),
						),
						array(
							'name'  => __( 'Λήξη', 'checkout-extras' ),
							'value' => $exp ? $exp->date( 'Y-m-d' ) : '',
						),
						array(
							'name'  => __( 'Χρησιμοποιήθηκε', 'checkout-extras' ),
							'value' => $c->get_usage_count() > 0 ? __( 'Ναι', 'checkout-extras' ) : __( 'Όχι', 'checkout-extras' ),
						),
					),
				);
			}

			$t = self::cart_table();
			if ( '' !== $t ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- third-party table, name built from the prefix.
				$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE LOWER(email) = %s LIMIT 200", $email ), ARRAY_A );
				foreach ( $rows as $r ) {
					$items[] = array(
						'group_id'    => 'cke-carts',
						'group_label' => __( 'Εγκαταλελειμμένα καλάθια', 'checkout-extras' ),
						'item_id'     => 'cke-cart-' . md5( (string) $r['session_id'] ),
						'data'        => array(
							array(
								'name'  => __( 'Ώρα', 'checkout-extras' ),
								'value' => (string) ( $r['time'] ?? '' ),
							),
							array(
								'name'  => __( 'Κατάσταση', 'checkout-extras' ),
								'value' => (string) ( $r['order_status'] ?? '' ),
							),
							array(
								'name'  => __( 'Σύνολο', 'checkout-extras' ),
								'value' => (string) ( $r['cart_total'] ?? '' ),
							),
						),
					);
				}
			}
		}

		return array(
			'data' => $items,
			'done' => $done,
		);
	}

	public static function erase( $email, $page = 1 ): array {
		$email = self::email( $email );
		$page  = max( 1, (int) $page );
		$out   = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
		if ( '' === $email ) {
			return $out;
		}

		$orders = self::orders( $email, $page );
		$kept   = 0;
		foreach ( $orders as $order ) {
			if ( '' === self::message( $order ) ) {
				continue;
			}
			if ( in_array( $order->get_status(), self::OPEN, true ) ) {
				++$kept;
				continue;
			}
			if ( self::drop_message( $order ) ) {
				$out['items_removed'] = true;
			}
		}
		if ( $kept > 0 ) {
			$out['items_retained'] = true;
			/* translators: %d: number of orders */
			$out['messages'][] = sprintf( __( 'Checkout Extras: το μήνυμα δώρου κρατήθηκε σε %d ανοιχτές παραγγελίες, γιατί χρειάζεται για τη συσκευασία. Ξανατρέξε τη διαγραφή όταν ολοκληρωθούν.', 'checkout-extras' ), $kept );
		}

		$out['done'] = count( $orders ) < self::PER_PAGE;
		if ( ! $out['done'] ) {
			return $out;
		}

		foreach ( self::coupons( $email ) as $id ) {
			$c = new WC_Coupon( $id );
			if ( $c->get_id() && $c->delete( true ) ) {
				$out['items_removed'] = true;
			}
		}

		$t = self::cart_table();
		if ( '' !== $t ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- third-party table, name built from the prefix.
			$ids = array_values( array_filter( array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT session_id FROM {$t} WHERE LOWER(email) = %s", $email ) ) ) ) );
			if ( $ids ) {
				$in = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
				foreach ( array( 'email_history', 'out_of_stock_notifications' ) as $child ) {
					$ct = $wpdb->prefix . 'cartflows_ca_' . $child;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check.
					if ( $ct === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $ct ) ) ) ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
						$wpdb->query( $wpdb->prepare( "DELETE FROM {$ct} WHERE ca_session_id IN ($in)", $ids ) );
					}
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- third-party table, name built from the prefix.
				if ( $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE LOWER(email) = %s", $email ) ) ) {
					$out['items_removed'] = true;
				}
			}
		}

		return $out;
	}

	/** WooCommerce anonymises an order: the gift message goes with it. */
	public static function order_lines( $order ): void {
		try {
			if ( $order instanceof WC_Order ) {
				self::drop_message( $order );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	public static function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p>' . esc_html__( 'Αν σημειώσεις ότι η παραγγελία είναι δώρο, το μήνυμα που γράφεις αποθηκεύεται με την παραγγελία για να μπει στο δέμα. Σβήνεται όταν ζητήσεις διαγραφή των δεδομένων σου, αφού ολοκληρωθεί η παραγγελία.', 'checkout-extras' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Το κουπόνι ευχαριστίας που μπορεί να πάρεις μετά από μια αγορά ισχύει μόνο για το email σου, που κρατιέται με το κουπόνι. Τα κουπόνια που λήγουν χωρίς χρήση σβήνονται λίγες ημέρες μετά τη λήξη τους, και όλα σβήνονται όταν ζητήσεις διαγραφή των δεδομένων σου.', 'checkout-extras' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Αν το κατάστημα χρησιμοποιεί ανάκτηση εγκαταλελειμμένων καλαθιών, τα καλάθια σβήνονται μετά από τις ημέρες που ορίζει το κατάστημα, και αμέσως όταν ζητήσεις διαγραφή των δεδομένων σου.', 'checkout-extras' ) . '</p>';
		wp_add_privacy_policy_content( 'Checkout Extras', wp_kses_post( $text ) );
	}
}

<?php
/**
 * GFC_Redeem — paying with a gift card (approved E2, E11).
 *
 * Cart: the customer types a code (wc-ajax gfc_apply / the Store API
 * callback for the blocks). After the attempt limit (GFC_Limiter) the
 * card is looked up by HMAC; a usable card's id goes into the WooCommerce
 * session (gfc_applied), never the code. At every cart calculation the
 * cards pay what is left of the total, after taxes and shipping, in the
 * order they were applied (woocommerce_calculated_total, priority 999).
 *
 * Order: when WooCommerce creates the order (classic:
 * woocommerce_checkout_order_created; blocks:
 * woocommerce_store_api_checkout_order_processed, both before payment) the
 * amounts are taken from the cards with GFC_Store::set_order_amount() — a
 * target per (card, order), so a reused order or a second submit writes
 * only the difference. If a card cannot pay any more (spent elsewhere a
 * second ago), everything taken in this call goes back, the order total is
 * set back to the full amount and the checkout stops with "the balance
 * changed". The order keeps the amounts in meta _gfc_cards and the
 * reduction its total carries in _gfc_reduced, so every later change
 * (recalculation by an admin, the block checkout's draft order rebuilt
 * from the cart before the payment check, a restore) sets the total to
 * "full total − what the cards pay" and never reduces it twice.
 *
 * Back to the card (E11): a failed or cancelled order gives everything
 * back and its total goes up by the same amount (a failed order can be
 * paid again, at the full price). A fully refunded order gives the card
 * part back when "restore_refund" is on. A partial refund to a card is the
 * order meta box (GFC_Admin_UI), never more than the order took. Unpaid
 * orders that took a card are cancelled after pending_hours (daily job).
 */

defined( 'ABSPATH' ) || exit;

final class GFC_Redeem {

	const SESSION = 'gfc_applied';
	const META    = '_gfc_cards';
	const REDUCED = '_gfc_reduced'; // Cents the order total is currently reduced by.
	// Starts with "woocommerce", so WooCommerce ties a guest's nonce to the guest's session.
	const NONCE = 'woocommerce-gfc-cart';

	/** @var array<int,int> card id => cents used by the last cart calculation. */
	private static $uses = array();

	/** @var bool True once the cart total was calculated in this request. */
	private static $calculated = false;

	public static function init(): void {
		// Totals: always, so carts and orders that already have a card stay right.
		add_filter( 'woocommerce_calculated_total', array( __CLASS__, 'cart_total' ), 999, 2 );
		add_action( 'woocommerce_order_after_calculate_totals', array( __CLASS__, 'order_recalculated' ), 99, 2 );
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'order_rows' ), 20, 2 );
		add_action( 'woocommerce_admin_order_totals_after_tax', array( __CLASS__, 'admin_rows' ) );
		add_action( 'woocommerce_cart_emptied', array( __CLASS__, 'clear_session' ) );

		// Debit when the order is created (both checkouts).
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'classic_reduced' ), 20 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'commit_order' ), 20 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'commit_order' ), 20 );

		// Back to the card.
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'on_failed' ), 10, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_failed' ), 10, 1 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_refunded' ), 10, 1 );

		if ( Gift_Cards::killed() ) {
			return;
		}
		// The field and its endpoints.
		add_action( 'woocommerce_before_cart_totals', array( __CLASS__, 'field_cart' ) );
		add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'field_checkout' ) );
		add_action( 'woocommerce_cart_totals_before_order_total', array( __CLASS__, 'cart_rows' ) );
		add_action( 'woocommerce_review_order_before_order_total', array( __CLASS__, 'cart_rows' ) );
		add_action( 'wc_ajax_gfc_apply', array( __CLASS__, 'ajax_apply' ) );
		add_action( 'wc_ajax_gfc_remove', array( __CLASS__, 'ajax_remove' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'form_post' ), 30 );
	}

	/* =====================================================================
	 * Session
	 * =================================================================== */

	/**
	 * The cards of this session. Each one is kept with a fingerprint of the
	 * card's code at the time it was applied (never the code), so a card
	 * whose code was replaced since drops out of every cart.
	 *
	 * @return int[]
	 */
	public static function applied(): array {
		$out = array();
		foreach ( self::applied_map() as $id => $fp ) {
			$card = GFC_Store::get( $id );
			if ( $card && '' !== $fp && hash_equals( self::fingerprint( $card ), $fp ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/** @return array<int,string> card id => fingerprint, as stored. */
	private static function applied_map(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}
		$raw = WC()->session->get( self::SESSION, array() );
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $id => $fp ) {
				if ( is_int( $id ) && $id > 0 && is_string( $fp ) ) {
					$out[ $id ] = $fp;
				}
			}
		}
		return $out;
	}

	/** A short fingerprint of the card's current code (from its HMAC). */
	private static function fingerprint( array $card ): string {
		return null === $card['code_hmac'] ? '' : substr( hash_hmac( 'sha256', 'gfc-session|' . $card['id'] . '|' . $card['code_hmac'], wp_salt( 'nonce' ) ), 0, 24 );
	}

	/** @param int[] $ids Cards to keep, in order. */
	private static function set_applied( array $ids ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$map = array();
		foreach ( $ids as $id ) {
			$card = GFC_Store::get( (int) $id );
			if ( $card ) {
				$map[ (int) $id ] = self::fingerprint( $card );
			}
		}
		WC()->session->set( self::SESSION, $map ? $map : null );
	}

	public static function clear_session(): void {
		self::set_applied( array() );
		self::$uses = array();
	}

	/**
	 * Applies a typed code. Returns [ok, message HTML].
	 *
	 * @return array{0:bool,1:string}
	 */
	public static function apply_code( string $typed ): array {
		if ( ! GFC_Settings::can_redeem_now() ) {
			return array( false, GFC_Settings::fill( 'invalid' ) );
		}
		if ( '' !== GFC_Limiter::attempt() ) {
			return array( false, GFC_Settings::fill( 'locked' ) );
		}
		$card = GFC_Store::find_by_code( $typed );
		if ( ! GFC_Store::usable( $card ) ) {
			GFC_Limiter::failure();
			return array( false, GFC_Settings::fill( 'invalid' ) );
		}
		GFC_Limiter::success();
		if ( GFC_Settings::get()['no_card_for_gc'] && GFC_Product::cart_has_gift_card() ) {
			return array( false, GFC_Settings::fill( 'no_gc_with_gc' ) );
		}
		$ids = self::applied();
		if ( ! in_array( $card['id'], $ids, true ) ) {
			if ( count( $ids ) >= (int) GFC_Settings::get()['max_cards'] ) {
				return array( false, GFC_Settings::fill( 'max_cards' ) );
			}
			$ids[] = $card['id'];
			self::set_applied( $ids );
		}
		return array( true, GFC_Settings::fill( 'applied', array( 'balance' => GFC_Settings::money( $card['balance'], $card['currency'] ) ) ) );
	}

	public static function remove_card( int $card_id ): void {
		self::set_applied(
			array_values(
				array_filter(
					self::applied(),
					static function ( $id ) use ( $card_id ) {
						return $id !== $card_id;
					}
				)
			)
		);
	}

	/* =====================================================================
	 * Cart total
	 * =================================================================== */

	public static function cart_total( $total, $cart ) {
		try {
			self::$uses       = array();
			self::$calculated = true;
			$ids              = self::applied();
			if ( ! $ids || ! GFC_Settings::can_redeem_now() ) {
				return $total;
			}
			if ( GFC_Settings::get()['no_card_for_gc'] && GFC_Product::cart_has_gift_card() ) {
				return $total;
			}
			$left = (int) round( (float) $total * 100 );
			$keep = array();
			foreach ( $ids as $id ) {
				$card = GFC_Store::get( $id );
				if ( ! GFC_Store::usable( $card ) ) {
					continue; // Spent, disabled or expired since: drop it.
				}
				$keep[] = $id;
				$use    = min( $card['balance'], $left );
				if ( $use > 0 ) {
					self::$uses[ $id ] = $use;
					$left             -= $use;
				}
			}
			if ( $keep !== $ids ) {
				self::set_applied( $keep );
			}
			return $left / 100;
		} catch ( \Throwable $e ) {
			self::$uses = array();
			return $total;
		}
	}

	/** @return array<int,int> card id => cents the current cart takes. */
	public static function uses(): array {
		if ( ! self::$calculated && function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->calculate_totals();
		}
		return self::$uses;
	}

	/* =====================================================================
	 * Order
	 * =================================================================== */

	/**
	 * Takes the amounts from the cards for a new (or reused) order.
	 *
	 * @throws Exception When a card cannot pay any more.
	 */
	public static function commit_order( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$uses = self::$calculated ? self::$uses : array();
		$prev = self::stored( $order );
		if ( ! $uses && ! array_filter( $prev ) ) {
			return;
		}
		$done = array();
		$fail = false;
		foreach ( array_unique( array_merge( array_keys( $prev ), array_keys( $uses ) ) ) as $card_id ) {
			$target = (int) ( $uses[ $card_id ] ?? 0 );
			/* translators: %s: order number */
			$res = GFC_Store::set_order_amount( (int) $card_id, $order->get_id(), $target, sprintf( __( 'Παραγγελία #%s', 'gift-cards' ), $order->get_order_number() ) );
			if ( ! $res['ok'] ) {
				$fail = true;
				break;
			}
			$done[] = (int) $card_id;
		}

		if ( $fail ) {
			// Put back what this call took and charge the full amount.
			foreach ( $done as $card_id ) {
				GFC_Store::set_order_amount( $card_id, $order->get_id(), (int) ( $prev[ $card_id ] ?? 0 ) );
			}
			self::set_reduction( $order, array_sum( $prev ) );
			self::store( $order, $prev );
			$order->save();
			self::$uses = array();
			$msg = wp_strip_all_tags( GFC_Settings::fill( 'changed' ) );
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) && did_action( 'woocommerce_store_api_checkout_order_processed' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'gfc_balance_changed', esc_html( $msg ), 409 );
			}
			throw new Exception( esc_html( $msg ) );
		}

		self::store( $order, $uses );
		self::set_reduction( $order, array_sum( $uses ) );
		$order->update_meta_data( '_gfc_has', $uses ? '1' : '0' );
		$order->save();
		if ( $uses ) {
			$parts = array();
			foreach ( $uses as $card_id => $c ) {
				$card    = GFC_Store::get( (int) $card_id );
				$parts[] = GFC_Codes::mask( $card ? $card['last4'] : '' ) . ' ' . GFC_Settings::money( $c, $order->get_currency() );
			}
			/* translators: %s: cards and amounts */
			$order->add_order_note( sprintf( __( 'Gift Cards: πληρώθηκαν από δωροκάρτα %s.', 'gift-cards' ), implode( ', ', $parts ) ) );
		}
	}

	/** @return array<int,int> card id => cents stored on the order. */
	public static function stored( WC_Order $order ): array {
		$raw = json_decode( (string) $order->get_meta( self::META ), true );
		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $id => $c ) {
				if ( (int) $id > 0 ) {
					$out[ (int) $id ] = max( 0, (int) $c );
				}
			}
		}
		return $out;
	}

	private static function store( WC_Order $order, array $uses ): void {
		$clean = array();
		foreach ( $uses as $id => $c ) {
			$clean[ (string) (int) $id ] = max( 0, (int) $c );
		}
		if ( $clean ) {
			$order->update_meta_data( self::META, wp_json_encode( $clean ) );
		} else {
			$order->delete_meta_data( self::META );
		}
	}

	/**
	 * The cards of an order with what they paid and what went back by refund.
	 *
	 * @param int|WC_Order $order
	 * @return array<int,array{card:int,last4:string,amount:float,refunded:float}>
	 */
	public static function order_cards( $order ): array {
		$order = $order instanceof WC_Order ? $order : wc_get_order( (int) $order );
		if ( ! $order instanceof WC_Order ) {
			return array();
		}
		$out = array();
		foreach ( self::stored( $order ) as $id => $c ) {
			$card  = GFC_Store::get( $id );
			$out[] = array(
				'card'     => $id,
				'last4'    => $card ? $card['last4'] : '',
				'amount'   => $c / 100,
				'refunded' => GFC_Store::order_refunded( $id, $order->get_id() ) / 100,
			);
		}
		return $out;
	}

	/** Admin recalculation: the total stays reduced by the cards. */
	public static function order_recalculated( $and_taxes, $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$sum = array_sum( self::stored( $order ) );
		// Block checkout: the draft order is rebuilt from the cart before the
		// payment check, so it takes what the cart takes now (commit_order
		// then debits exactly that).
		if ( 'checkout-draft' === $order->get_status() && self::$calculated && function_exists( 'WC' ) && WC()->cart ) {
			$sum = array_sum( self::$uses );
		}
		// calculate_totals() wrote the full total: nothing is reduced yet.
		$order->update_meta_data( self::REDUCED, 0 );
		self::set_reduction( $order, $sum );
	}

	/** Classic checkout: the order total comes from the cart, already reduced. */
	public static function classic_reduced( $order ): void {
		if ( $order instanceof WC_Order ) {
			$order->update_meta_data( self::REDUCED, self::$calculated ? array_sum( self::$uses ) : 0 );
		}
	}

	/**
	 * Makes the order total "full total − $cents" (what the cards pay),
	 * from the reduction already applied (meta _gfc_reduced). Not saved.
	 */
	private static function set_reduction( WC_Order $order, int $cents ): void {
		$cents = max( 0, $cents );
		$meta  = $order->get_meta( self::REDUCED );
		$now   = '' === $meta ? array_sum( self::stored( $order ) ) : (int) $meta;
		if ( $now !== $cents ) {
			$total = (float) $order->get_total() + ( $now - $cents ) / 100;
			$order->set_total( max( 0, round( $total, wc_get_price_decimals() ) ) );
		}
		$order->update_meta_data( self::REDUCED, $cents );
	}

	/* =====================================================================
	 * Back to the card
	 * =================================================================== */

	public static function on_failed( $order_id ): void {
		try {
			$order = wc_get_order( (int) $order_id );
			if ( $order instanceof WC_Order ) {
				self::restore_order( $order, true, __( 'Η παραγγελία απέτυχε ή ακυρώθηκε.', 'gift-cards' ) );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	public static function on_refunded( $order_id ): void {
		try {
			$order = wc_get_order( (int) $order_id );
			if ( $order instanceof WC_Order && GFC_Settings::get()['restore_refund'] ) {
				self::restore_order( $order, false, __( 'Η παραγγελία επιστράφηκε ολόκληρη.', 'gift-cards' ) );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Gives every card of the order its money back (target 0).
	 *
	 * @param bool $raise_total The order total goes up by the same amount.
	 */
	public static function restore_order( WC_Order $order, bool $raise_total, string $why ): void {
		$stored = self::stored( $order );
		if ( ! array_filter( $stored ) ) {
			return;
		}
		$back  = 0;
		$parts = array();
		$left  = array();
		foreach ( $stored as $card_id => $c ) {
			$net = GFC_Store::order_net( $card_id, $order->get_id() );
			if ( $net <= 0 ) {
				continue;
			}
			$res = GFC_Store::set_order_amount( $card_id, $order->get_id(), 0, $why );
			if ( $res['ok'] ) {
				$back   += $net;
				$card    = GFC_Store::get( $card_id );
				$parts[] = GFC_Codes::mask( $card ? $card['last4'] : '' ) . ' ' . GFC_Settings::money( $net, $order->get_currency() );
			} else {
				$left[ $card_id ] = $c;
			}
		}
		if ( ! $back ) {
			return;
		}
		if ( $raise_total ) {
			// What went back is not paid by a card any more.
			self::store( $order, $left );
			self::set_reduction( $order, array_sum( $left ) );
			$order->update_meta_data( '_gfc_has', $left ? '1' : '0' );
		}
		/* translators: %s: cards and amounts */
		$order->add_order_note( sprintf( __( 'Gift Cards: επέστρεψαν στις δωροκάρτες %s.', 'gift-cards' ), implode( ', ', $parts ) ) );
		$order->save();
	}

	/**
	 * A refund to a card from the order meta box. $token makes a double
	 * submit write nothing the second time.
	 *
	 * @return array{ok:bool,error:string}
	 */
	public static function refund_to_card( WC_Order $order, int $card_id, int $cents, int $user_id, string $token ): array {
		if ( ! isset( self::stored( $order )[ $card_id ] ) || $cents <= 0 ) {
			return array(
				'ok'    => false,
				'error' => 'card',
			);
		}
		$net = GFC_Store::order_net( $card_id, $order->get_id() );
		if ( $cents > $net ) {
			return array(
				'ok'    => false,
				'error' => 'over',
			);
		}
		$res = GFC_Store::move(
			$card_id,
			$cents,
			'refund',
			array(
				'order_id' => $order->get_id(),
				'user_id'  => $user_id,
				/* translators: %s: order number */
				'note'     => sprintf( __( 'Επιστροφή από την παραγγελία #%s', 'gift-cards' ), $order->get_order_number() ),
				'cap_order' => true,
				'uniq'     => 'rf:' . substr( preg_replace( '/[^a-z0-9]/', '', strtolower( $token ) ), 0, 60 ),
			)
		);
		if ( $res['ok'] && 'duplicate' !== $res['error'] ) {
			$card = GFC_Store::get( $card_id );
			$order->add_order_note(
				/* translators: 1: amount, 2: card */
				sprintf( __( 'Gift Cards: επιστροφή %1$s στη δωροκάρτα %2$s.', 'gift-cards' ), GFC_Settings::money( $cents, $order->get_currency() ), GFC_Codes::mask( $card ? $card['last4'] : '' ) ),
				0,
				true
			);
		}
		return array(
			'ok'    => $res['ok'],
			'error' => $res['error'],
		);
	}

	/** Unpaid orders that took a card: cancelled after pending_hours (daily job). */
	public static function cleanup_unpaid(): void {
		$hours  = (int) GFC_Settings::get()['pending_hours'];
		$orders = wc_get_orders(
			array(
				'status'       => array( 'pending' ),
				'date_created' => '<' . ( time() - $hours * HOUR_IN_SECONDS ),
				'limit'        => 100,
				// meta_key / meta_value work with both order stores (meta_query only with HPOS).
				'meta_key'     => '_gfc_has', // phpcs:ignore WordPress.DB.SlowDBQuery -- daily job, limited.
				'meta_value'   => '1', // phpcs:ignore WordPress.DB.SlowDBQuery -- daily job, limited.
			)
		);
		foreach ( $orders as $order ) {
			// Checked again on the order itself: never cancel an order that took no card.
			if ( $order instanceof WC_Order && 'pending' === $order->get_status() && '1' === (string) $order->get_meta( '_gfc_has' ) && array_filter( self::stored( $order ) ) ) {
				/* translators: %d: hours */
				$order->update_status( 'cancelled', sprintf( __( 'Gift Cards: απλήρωτη για πάνω από %d ώρες· ακυρώθηκε και το ποσό επέστρεψε στη δωροκάρτα.', 'gift-cards' ), $hours ) );
			}
		}
	}

	/* =====================================================================
	 * Display
	 * =================================================================== */

	/** Rows in the cart / checkout totals (classic). */
	public static function cart_rows(): void {
		try {
			$uses = self::$calculated ? self::$uses : array();
			foreach ( self::applied() as $id ) {
				$card = GFC_Store::get( $id );
				if ( ! $card ) {
					continue;
				}
				$use = (int) ( $uses[ $id ] ?? 0 );
				$url = wp_nonce_url( add_query_arg( 'gfc_remove', $id, wc_get_cart_url() ), self::NONCE, 'gfc_nonce' );
				echo '<tr class="gfc-row"><th>' . GFC_Settings::fill( 'row', array( 'card' => GFC_Codes::mask( $card['last4'] ) ) ) // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in fill().
					. ' <a href="' . esc_url( $url ) . '" class="gfc-remove" data-card="' . esc_attr( (string) $id ) . '">[' . esc_html( GFC_Settings::text( 'remove' ) ) . ']</a></th>'
					. '<td data-title="' . esc_attr( wp_strip_all_tags( GFC_Settings::fill( 'row', array( 'card' => GFC_Codes::mask( $card['last4'] ) ) ) ) ) . '">−' . wp_kses_post( wc_price( $use / 100 ) )
					. '<br><small>' . GFC_Settings::fill( 'row_left', array( 'balance' => GFC_Settings::money( $card['balance'] - $use, $card['currency'] ) ) ) . '</small></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in fill().
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** Rows in the order totals (thank-you page, My account, emails). */
	public static function order_rows( $rows, $order ) {
		if ( ! is_array( $rows ) || ! $order instanceof WC_Order ) {
			return $rows;
		}
		$add = array();
		foreach ( self::stored( $order ) as $id => $c ) {
			if ( $c <= 0 ) {
				continue;
			}
			$card                       = GFC_Store::get( $id );
			$add[ 'gfc_card_' . $id ] = array(
				'label' => wp_strip_all_tags( GFC_Settings::fill( 'row', array( 'card' => GFC_Codes::mask( $card ? $card['last4'] : '' ) ) ) ) . ':',
				'value' => '−' . wc_price( $c / 100, array( 'currency' => $order->get_currency() ) ),
			);
		}
		if ( ! $add ) {
			return $rows;
		}
		$out = array();
		foreach ( $rows as $k => $row ) {
			if ( 'order_total' === $k ) {
				$out = array_merge( $out, $add );
				$add = array();
			}
			$out[ $k ] = $row;
		}
		return array_merge( $out, $add );
	}

	public static function admin_rows( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		foreach ( self::stored( $order ) as $id => $c ) {
			if ( $c <= 0 ) {
				continue;
			}
			$card = GFC_Store::get( $id );
			$link = admin_url( 'admin.php?page=gfc-cards&card=' . $id );
			echo '<tr><td class="label"><a href="' . esc_url( $link ) . '">' . esc_html( __( 'Δωροκάρτα', 'gift-cards' ) . ' ' . GFC_Codes::mask( $card ? $card['last4'] : '' ) ) . '</a>:</td><td width="1%"></td><td class="total">−'
				. wp_kses_post( wc_price( $c / 100, array( 'currency' => $order->get_currency() ) ) ) . '</td></tr>';
		}
	}

	/* =====================================================================
	 * The field (classic cart / checkout)
	 * =================================================================== */

	public static function field_cart(): void {
		self::field( 'cart' );
	}

	public static function field_checkout(): void {
		self::field( 'checkout' );
	}

	private static function field( string $where ): void {
		try {
			if ( ! GFC_Settings::can_redeem_now() ) {
				return;
			}
			static $done = false;
			if ( $done ) {
				return;
			}
			$done = true;
			self::script();
			$id = 'gfc-field-' . $where;
			echo '<div class="gfc-field" id="' . esc_attr( $id ) . '" data-where="' . esc_attr( $where ) . '">';
			if ( 'cart' === $where ) {
				// A real form (the cart totals are outside the cart form): works without JavaScript.
				echo '<form method="post" action="' . esc_url( wc_get_cart_url() ) . '" class="gfc-form-apply">';
				wp_nonce_field( self::NONCE, 'gfc_nonce' );
			}
			echo '<p class="gfc-title"><strong>' . esc_html( GFC_Settings::text( 'have_card' ) ) . '</strong></p>';
			echo '<p class="gfc-line"><input type="text" name="gfc_code" class="input-text gfc-code" autocomplete="off" spellcheck="false" maxlength="24" placeholder="' . esc_attr( GFC_Settings::text( 'placeholder' ) ) . '" aria-label="' . esc_attr( GFC_Settings::text( 'placeholder' ) ) . '" /> ';
			echo '<button type="' . ( 'cart' === $where ? 'submit' : 'button' ) . '" class="button gfc-apply' . esc_attr( function_exists( 'wc_wp_theme_get_element_class_name' ) && wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ) . '" name="gfc_apply" value="1">' . esc_html( GFC_Settings::text( 'apply' ) ) . '</button></p>';
			echo '<p class="gfc-msg" role="status" aria-live="polite"></p>';
			if ( 'cart' === $where ) {
				echo '</form>';
			}
			echo '</div>';
		} catch ( \Throwable $e ) {
			return;
		}
	}

	private static function script(): void {
		$cfg = array(
			'apply'  => WC_AJAX::get_endpoint( 'gfc_apply' ),
			'remove' => WC_AJAX::get_endpoint( 'gfc_remove' ),
			'nonce'  => wp_create_nonce( self::NONCE ),
		);
		wp_enqueue_script( 'gfc-front', GFC_URL . 'assets/front.js', array( 'jquery' ), GFC_VERSION, true );
		wp_add_inline_script( 'gfc-front', 'window.gfcCfg = ' . wp_json_encode( $cfg ) . ';', 'before' );
		echo '<style id="gfc-field-css">.gfc-field{margin:0 0 1.2em}.gfc-field .gfc-line{display:flex;gap:.5em;flex-wrap:wrap;margin:0}.gfc-field .gfc-code{flex:1 1 14em;text-transform:uppercase;letter-spacing:.06em}.gfc-field .gfc-msg{margin:.4em 0 0;font-size:.92em}.gfc-field .gfc-msg.is-error{color:#b32d2e}.gfc-row small{opacity:.75}</style>';
	}

	/** wc-ajax gfc_apply (POST + nonce). */
	public static function ajax_apply(): void {
		self::ajax_guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in ajax_guard().
		$code            = isset( $_POST['code'] ) && is_string( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		list( $ok, $msg ) = self::apply_code( $code );
		wp_send_json(
			array(
				'ok'      => $ok,
				'message' => $msg,
			)
		);
	}

	/** wc-ajax gfc_remove (POST + nonce). */
	public static function ajax_remove(): void {
		self::ajax_guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in ajax_guard().
		$id = isset( $_POST['card'] ) && is_scalar( $_POST['card'] ) ? absint( $_POST['card'] ) : 0;
		self::remove_card( $id );
		wp_send_json(
			array(
				'ok'      => true,
				'message' => GFC_Settings::fill( 'removed' ),
			)
		);
	}

	private static function ajax_guard(): void {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json(
				array(
					'ok'      => false,
					'message' => GFC_Settings::fill( 'invalid' ),
				),
				403
			);
		}
		nocache_headers();
	}

	/** Without JavaScript: the cart form posts here; removal links use GET + nonce. */
	public static function form_post(): void {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- verified below.
		$nonce = isset( $_REQUEST['gfc_nonce'] ) ? sanitize_key( wp_unslash( $_REQUEST['gfc_nonce'] ) ) : '';
		if ( isset( $_GET['gfc_remove'] ) && wp_verify_nonce( $nonce, self::NONCE ) ) {
			self::remove_card( absint( $_GET['gfc_remove'] ) );
			wc_add_notice( GFC_Settings::fill( 'removed' ), 'notice' );
			wp_safe_redirect( remove_query_arg( array( 'gfc_remove', 'gfc_nonce' ) ) );
			exit;
		}
		if ( isset( $_POST['gfc_apply'], $_POST['gfc_code'] ) && is_string( $_POST['gfc_code'] ) && wp_verify_nonce( $nonce, self::NONCE ) ) {
			list( $ok, $msg ) = self::apply_code( sanitize_text_field( wp_unslash( $_POST['gfc_code'] ) ) );
			wc_add_notice( $msg, $ok ? 'success' : 'error' );
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
		// phpcs:enable
	}
}

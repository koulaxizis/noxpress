<?php
/**
 * PL_Printful — module "printful": what Printful charged for an order
 * becomes «Εκτύπωση» entries of the products in it.
 *
 *  - When an order is completed (Printful marks it shipped), a look-up is
 *    queued (Action Scheduler, group production-ledger), never run in the
 *    visitor's request.
 *  - GET https://api.printful.com/orders/@<order id> with the read-only
 *    key (Bearer; X-PF-Store-Id when a store id is set). 404 = not a
 *    Printful order (marked, never asked again).
 *  - costs.total − costs.vat − costs.tax = net, costs.vat + costs.tax =
 *    VAT. Spread over the order's products by Printful's own item prices
 *    (items[].external_id = WooCommerce line id); lines Printful does not
 *    name share nothing. When no item can be matched, the cost goes to the
 *    order's products by their value.
 *  - One entry per product with ext_ref printful:<order>:<product>: a new
 *    look-up updates it, never adds a second one.
 *  - Retries after 10 min, 1 h, 6 h, 24 h on network / 429 / 5xx; the
 *    order meta _pl_pf = done | none | fail:<reason> and a log (option
 *    pl_pf_log, 50 lines, no personal data).
 *
 * Only entries in the shop currency are made; another currency is logged.
 * The key never leaves the server; every logged text is scrubbed.
 */

defined( 'ABSPATH' ) || exit;

final class PL_Printful {

	const HOOK  = 'pl_printful_fetch';
	const META  = '_pl_pf';
	const LOG   = 'pl_pf_log';
	const TRIES = 5;
	const API   = 'https://api.printful.com/orders/@';

	public static function init(): void {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'queue' ), 20, 1 );
	}

	/** Order completed: queue one look-up (once per order). */
	public static function queue( $order_id ): void {
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || '' !== (string) $order->get_meta( self::META ) ) {
				return;
			}
			$created = $order->get_date_created();
			if ( $created && wp_date( 'Y-m-d', $created->getTimestamp() ) < PL_Settings::start() ) {
				return;
			}
			$order->update_meta_data( self::META, 'queued' );
			$order->save_meta_data();
			self::schedule( (int) $order->get_id(), 1, 5 * MINUTE_IN_SECONDS );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	public static function schedule( int $order_id, int $try, int $delay ): void {
		$args = array( $order_id, $try );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + max( 1, $delay ), self::HOOK, $args, Production_Ledger::GROUP );
			return;
		}
		wp_schedule_single_event( time() + max( 1, $delay ), self::HOOK, $args );
	}

	private static function delay( int $try ): int {
		$d = array(
			1 => 10 * MINUTE_IN_SECONDS,
			2 => HOUR_IN_SECONDS,
			3 => 6 * HOUR_IN_SECONDS,
			4 => DAY_IN_SECONDS,
		);
		return isset( $d[ $try ] ) ? $d[ $try ] : DAY_IN_SECONDS;
	}

	/** One look-up (queued callback). */
	public static function fetch( int $order_id, int $try ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( Production_Ledger::killed() || ! PL_Settings::module( 'printful' ) ) {
			self::log( $order_id, 'skip', 0, $try );
			return;
		}
		$key = PL_Settings::pf_key();
		if ( '' === $key ) {
			self::finish( $order, 'fail:nokey', $try, 0 );
			return;
		}
		$headers = array(
			'Authorization' => 'Bearer ' . $key,
			'Accept'        => 'application/json',
		);
		$store = PL_Settings::get()['pf_store'];
		if ( '' !== $store ) {
			$headers['X-PF-Store-Id'] = $store;
		}
		$r = wp_safe_remote_get(
			self::API . rawurlencode( (string) $order_id ),
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => $headers,
				'user-agent'  => 'Production Ledger/' . PL_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $r ) ) {
			self::retry_or_fail( $order, 'network', $try, 0 );
			return;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( 404 === $code ) {
			self::finish( $order, 'none', $try, $code );
			return;
		}
		if ( 401 === $code || 403 === $code ) {
			self::finish( $order, 'fail:auth', $try, $code );
			return;
		}
		if ( 429 === $code || $code >= 500 ) {
			self::retry_or_fail( $order, 'busy', $try, $code );
			return;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		if ( 200 !== $code || ! is_array( $body ) || ! isset( $body['result'] ) || ! is_array( $body['result'] ) ) {
			self::finish( $order, 'fail:answer', $try, $code );
			return;
		}
		$res = self::apply( $order, $body['result'] );
		self::finish( $order, $res, $try, $code );
	}

	private static function retry_or_fail( WC_Order $order, string $why, int $try, int $code ): void {
		if ( $try < self::TRIES ) {
			self::log( (int) $order->get_id(), $why, $code, $try );
			self::schedule( (int) $order->get_id(), $try + 1, self::delay( $try ) );
			return;
		}
		self::finish( $order, 'fail:' . $why, $try, $code );
	}

	private static function finish( WC_Order $order, string $state, int $try, int $code ): void {
		$order->update_meta_data( self::META, $state );
		$order->save_meta_data();
		self::log( (int) $order->get_id(), $state, $code, $try );
	}

	/**
	 * Turns a Printful order into entries. Returns done | none | fail:<why>.
	 *
	 * @param array $pf The "result" object of GET /orders/@id.
	 */
	public static function apply( WC_Order $order, array $pf ): string {
		$costs = isset( $pf['costs'] ) && is_array( $pf['costs'] ) ? $pf['costs'] : array();
		$cur   = isset( $costs['currency'] ) ? strtoupper( (string) $costs['currency'] ) : '';
		if ( '' !== $cur && PL_Entries::currency() !== $cur ) {
			return 'fail:currency';
		}
		$total = isset( $costs['total'] ) ? (float) $costs['total'] : 0.0;
		$vat   = ( isset( $costs['vat'] ) ? (float) $costs['vat'] : 0.0 ) + ( isset( $costs['tax'] ) ? (float) $costs['tax'] : 0.0 );
		$net   = round( $total - $vat, 2 );
		if ( $total <= 0 || $net < 0 ) {
			return 'none';
		}

		// Product of each WooCommerce line.
		$line_pid = array();
		$by_value = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$pid = PL_Report::product_of( $item );
				if ( $pid > 0 ) {
					$line_pid[ (string) $item_id ] = $pid;
					$by_value[ $pid ]              = ( isset( $by_value[ $pid ] ) ? $by_value[ $pid ] : 0.0 ) + max( 0.0, (float) $item->get_total() );
				}
			}
		}
		if ( ! $line_pid ) {
			return 'fail:nolines';
		}

		// Weights from Printful's item prices, by external_id = line id.
		$w = array();
		foreach ( isset( $pf['items'] ) && is_array( $pf['items'] ) ? $pf['items'] : array() as $it ) {
			$ext = isset( $it['external_id'] ) ? (string) $it['external_id'] : '';
			if ( '' === $ext || ! isset( $line_pid[ $ext ] ) ) {
				continue;
			}
			$amount                    = max( 0.0, (float) ( $it['price'] ?? 0 ) ) * max( 1, (int) ( $it['quantity'] ?? 1 ) );
			$w[ $line_pid[ $ext ] ]    = ( isset( $w[ $line_pid[ $ext ] ] ) ? $w[ $line_pid[ $ext ] ] : 0.0 ) + $amount;
		}
		if ( ! $w || array_sum( $w ) <= 0 ) {
			$w = $by_value;
		}
		$sum = array_sum( $w );
		if ( $sum <= 0 ) {
			$w   = array_fill_keys( array_keys( $by_value ), 1.0 );
			$sum = count( $w );
		}

		// Cents reconciled so the entries add up to the invoice exactly.
		$pids  = array_keys( $w );
		$net_c = self::split_cents( (int) round( $net * 100 ), $w, $sum );
		$vat_c = self::split_cents( (int) round( $vat * 100 ), $w, $sum );
		$day   = $order->get_date_created() ? wp_date( 'Y-m-d', $order->get_date_created()->getTimestamp() ) : wp_date( 'Y-m-d' );
		$day   = max( $day, PL_Settings::start() );
		$oid   = (int) $order->get_id();
		foreach ( $pids as $pid ) {
			$r = PL_Entries::upsert_ref(
				'printful:' . $oid . ':' . $pid,
				array(
					'date'        => $day,
					'product_id'  => $pid,
					'category'    => 'printing',
					'supplier'    => 'Printful',
					'invoice_no'  => isset( $pf['id'] ) ? 'PF-' . (int) $pf['id'] : '',
					/* translators: %s: order number */
					'description' => sprintf( __( 'Printful, παραγγελία #%s', 'production-ledger' ), $order->get_order_number() ),
					'net'         => $net_c[ $pid ] / 100,
					'vat'         => $vat_c[ $pid ] / 100,
				)
			);
			if ( is_wp_error( $r ) ) {
				return 'fail:save';
			}
		}
		return 'done';
	}

	/** Largest-remainder split of cents by weight. */
	private static function split_cents( int $cents, array $w, float $sum ): array {
		$out  = array();
		$frac = array();
		$used = 0;
		foreach ( $w as $k => $v ) {
			$raw        = $cents * $v / $sum;
			$out[ $k ]  = (int) floor( $raw );
			$frac[ $k ] = $raw - $out[ $k ];
			$used      += $out[ $k ];
		}
		arsort( $frac );
		foreach ( array_keys( $frac ) as $k ) {
			if ( $used >= $cents ) {
				break;
			}
			$out[ $k ]++;
			$used++;
		}
		return $out;
	}

	public static function log( int $order_id, string $res, int $code, int $try ): void {
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				't'     => time(),
				'order' => $order_id,
				'res'   => PL_Settings::scrub( substr( $res, 0, 40 ) ),
				'code'  => $code,
				'try'   => $try,
			)
		);
		update_option( self::LOG, array_slice( $log, 0, 50 ), false );
	}
}

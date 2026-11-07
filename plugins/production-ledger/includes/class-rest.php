<?php
/**
 * PL_Rest — module "oros": the records orOS Budget pulls (read only).
 *
 *  GET /wp-json/wc/v3/pl/records?from=Y-m-d&to=Y-m-d&page=1&per_page=500
 *
 * orOS lives in the browser and reads the shop only while it is open
 * (Bible §20 «Δεδομένα που τραβά η υπηρεσία»). Budget derives its own
 * entries from these rows (orOS Bible BR-B3: pull, never push), so every
 * row has a stable id and a modification time, and a row that should no
 * longer count comes back with del = 1 instead of disappearing:
 *
 *  - order income, one row per product of a paid order
 *      id  o<order>-p<product>    k i   cat sales | trade
 *  - shipping a customer paid, one row per order
 *      id  o<order>-s             k i   cat shipping_in
 *    An order that is cancelled, failed, fully refunded or deleted gives
 *    del = 1 rows for what it gave before (for the period asked).
 *  - ledger entries
 *      id  e<entry>               k o (cost) | i (off-site income)
 *    A soft-deleted entry gives del = 1.
 *
 * Amounts in integer cents: a = net, v = VAT (gross = a + v). Dates are
 * local Y-m-d (orders: the day they were paid, as the report and the bank).
 * For bank reconciliation an order row carries its payment method, the
 * gateway's transaction id and the order number; an entry its supplier
 * and invoice number. Test entries are left out unless test=1, and then
 * carry t = 1. «months» gives the site's monthly totals so orOS can check
 * that its own sums match to the cent.
 *
 * Authentication: under wc/v3, so WooCommerce authenticates the shop's
 * REST keys (a read key is enough) or an Application Password; the route
 * also checks manage_woocommerce. Deviation from §20 (own namespace
 * pl/v1): orOS addresses a shop only below /wp-json/wc/v3 (as RS 1.11.0).
 * PL_DISABLE answers 403. Answers are not cached; errors are in English.
 */

defined( 'ABSPATH' ) || exit;

final class PL_Rest {

	const NS       = 'wc/v3';
	const MAX_DAYS = 400;
	const PULLED   = 'pl_oros_pulled';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$date = array(
			'type'    => 'string',
			'pattern' => '^\d{4}-\d{2}-\d{2}$',
		);
		register_rest_route(
			self::NS,
			'/pl/records',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'records' ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
				'args'                => array(
					'from'     => $date,
					'to'       => $date,
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 500,
						'default' => 500,
					),
					'test'     => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	public static function allowed() {
		if ( Production_Ledger::killed() ) {
			return new WP_Error( 'pl_disabled', 'Production Ledger is disabled on this site (PL_DISABLE).', array( 'status' => 403 ) );
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'pl_auth', 'Authentication required: the shop\'s WooCommerce REST keys or an Application Password.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( Production_Ledger::CAP ) ) {
			return new WP_Error( 'pl_cap', 'This user cannot manage WooCommerce.', array( 'status' => 403 ) );
		}
		return true;
	}

	private static function cents( float $v ): int {
		return (int) round( $v * 100 );
	}

	private static function nocache( $data ): WP_REST_Response {
		$r = new WP_REST_Response( $data );
		foreach ( wp_get_nocache_headers() as $k => $v ) {
			$r->header( $k, (string) $v );
		}
		return $r;
	}

	/** Local Y-m-d of a WC date (or ''). */
	private static function day( $dt ): string {
		return $dt instanceof WC_DateTime ? wp_date( 'Y-m-d', $dt->getTimestamp() ) : '';
	}

	private static function ms( $dt ): int {
		return $dt instanceof WC_DateTime ? $dt->getTimestamp() * 1000 : 0;
	}

	/** @param WP_REST_Request $req Request. */
	public static function records( $req ) {
		$start = PL_Settings::start();
		$today = wp_date( 'Y-m-d' );
		$from  = (string) $req->get_param( 'from' );
		$to    = (string) $req->get_param( 'to' );
		$from  = PL_Settings::valid_date( $from ) ? $from : $start;
		$to    = PL_Settings::valid_date( $to ) ? $to : $today;
		$from  = max( $from, $start );
		if ( $from > $to ) {
			return self::nocache( self::envelope( $from, $to, array(), array(), 1, 1 ) );
		}
		if ( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS > self::MAX_DAYS ) {
			return new WP_Error( 'pl_range', 'The period may span at most ' . self::MAX_DAYS . ' days.', array( 'status' => 400 ) );
		}
		$test = (bool) $req->get_param( 'test' );

		list( $rows, $months ) = self::build( $from, $to, $test );

		$per   = max( 1, min( 500, (int) $req->get_param( 'per_page' ) ) );
		$pages = max( 1, (int) ceil( count( $rows ) / $per ) );
		$page  = max( 1, min( $pages, (int) $req->get_param( 'page' ) ) );
		$slice = array_slice( $rows, ( $page - 1 ) * $per, $per );

		update_option(
			self::PULLED,
			array(
				't'    => time(),
				'from' => $from,
				'to'   => $to,
				'rows' => count( $rows ),
			),
			false
		);

		return self::nocache( self::envelope( $from, $to, $slice, $months, $page, $pages, count( $rows ) ) );
	}

	private static function envelope( string $from, string $to, array $rows, array $months, int $page, int $pages, int $total = 0 ): array {
		return array(
			'source'   => 'production-ledger',
			'version'  => PL_VERSION,
			'site'     => home_url( '/' ),
			'currency' => PL_Entries::currency(),
			'start'    => PL_Settings::start(),
			'from'     => $from,
			'to'       => $to,
			'page'     => $page,
			'pages'    => $pages,
			'total'    => $total,
			'records'  => $rows,
			'months'   => $months,
		);
	}

	/**
	 * Every row of a period, ordered by id, and the monthly totals.
	 *
	 * @return array{0: array[], 1: array}
	 */
	public static function build( string $from, string $to, bool $test ): array {
		$s      = PL_Settings::get();
		$rows   = array();
		$months = array();
		$add    = static function ( string $day, string $k, int $a, int $v ) use ( &$months ) {
			$m = substr( $day, 0, 7 );
			if ( ! isset( $months[ $m ] ) ) {
				$months[ $m ] = array(
					'income_net'  => 0,
					'income_vat'  => 0,
					'expense_net' => 0,
					'expense_vat' => 0,
				);
			}
			$months[ $m ][ 'i' === $k ? 'income_net' : 'expense_net' ] += $a;
			$months[ $m ][ 'i' === $k ? 'income_vat' : 'expense_vat' ] += $v;
		};

		// Orders PAID in the range (the bank's date), whatever their status
		// now: paid ones give rows, the others (cancelled, refunded after
		// payment) give del rows, so an earlier pull is undone.
		$range  = PL_Report::paid_range( $from, $to );
		$paid_s = PL_Report::paid_statuses();
		$orders = '' === $range ? array() : wc_get_orders(
			array(
				'limit'     => -1,
				'type'      => 'shop_order',
				'status'    => array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft' ) ),
				'return'    => 'objects',
				'date_paid' => $range,
			)
		);
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$oid   = (int) $order->get_id();
			$paid  = in_array( 'wc-' . $order->get_status(), $paid_s, true );
			$day   = self::day( $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created() );
			$m     = self::ms( $order->get_date_modified() );
			$parts = PL_Report::order_parts( $order, $s );
			$base  = array(
				'order'  => $oid,
				'number' => (string) $order->get_order_number(),
				'pm'     => (string) $order->get_payment_method(),
				'pmt'    => (string) $order->get_payment_method_title(),
				'tx'     => (string) $order->get_transaction_id(),
				'paid'   => self::day( $order->get_date_paid() ),
				'status' => (string) $order->get_status(),
			);
			$line_ids = array();
			foreach ( $order->get_items() as $item ) {
				if ( $item instanceof WC_Order_Item_Product ) {
					$pid = PL_Report::product_of( $item );
					if ( $pid > 0 ) {
						$line_ids[ $pid ] = true;
					}
				}
			}
			foreach ( array_keys( $line_ids ) as $pid ) {
				$id = 'o' . $oid . '-p' . $pid;
				$l  = isset( $parts['lines'][ $pid ] ) ? $parts['lines'][ $pid ] : null;
				$a  = null === $l ? 0 : self::cents( $l['net'] );
				if ( ! $paid || $a <= 0 ) {
					$rows[ $id ] = array(
						'id'  => $id,
						'm'   => $m,
						'del' => 1,
					);
					continue;
				}
				$v           = self::cents( $l['vat'] );
				$cat         = $parts['trade'] ? 'trade' : 'sales';
				$title       = html_entity_decode( (string) get_the_title( $pid ), ENT_QUOTES, 'UTF-8' );
				$rows[ $id ] = array_merge(
					array(
						'id'    => $id,
						'm'     => $m,
						'k'     => 'i',
						'd'     => $day,
						'a'     => $a,
						'v'     => $v,
						'cat'   => $cat,
						'pid'   => $pid,
						'title' => $title,
						'qty'   => (int) $l['qty'],
					),
					$base
				);
				$add( $day, 'i', $a, $v );
			}
			$sid = 'o' . $oid . '-s';
			$sa  = self::cents( $parts['ship_net'] );
			if ( $paid && $sa > 0 ) {
				$sv           = self::cents( max( 0.0, (float) $order->get_shipping_tax() ) );
				$rows[ $sid ] = array_merge(
					array(
						'id'      => $sid,
						'm'       => $m,
						'k'       => 'i',
						'd'       => $day,
						'a'       => $sa,
						'v'       => $sv,
						'cat'     => 'shipping_in',
						'carrier' => $parts['carrier'],
					),
					$base
				);
				$add( $day, 'i', $sa, $sv );
			} elseif ( count( $order->get_items( 'shipping' ) ) > 0 ) {
				$rows[ $sid ] = array(
					'id'  => $sid,
					'm'   => $m,
					'del' => 1,
				);
			}
		}

		// Ledger entries, deleted ones included (as del rows).
		$entries = PL_Entries::query(
			array(
				'from'  => $from,
				'to'    => $to,
				'all'   => true,
				'limit' => 5000,
			)
		)['rows'];
		foreach ( $entries as $e ) {
			if ( $e['is_test'] && ! $test ) {
				continue;
			}
			$id = 'e' . $e['id'];
			$m  = (int) ( strtotime( $e['updated_at'] . ' UTC' ) * 1000 );
			if ( null !== $e['deleted_at'] ) {
				$rows[ $id ] = array(
					'id'  => $id,
					'm'   => $m,
					'del' => 1,
				);
				continue;
			}
			$k   = 'offsite' === $e['category'] ? 'i' : 'o';
			$row = array(
				'id'       => $id,
				'm'        => $m,
				'k'        => $k,
				'd'        => $e['entry_date'],
				'a'        => self::cents( $e['net_amount'] ),
				'v'        => self::cents( $e['vat_amount'] ),
				'cat'      => $e['category'],
				'scope'    => $e['product_id'] > 0 ? 'product' : ( '' !== $e['pool'] ? 'pool' : 'general' ),
				'pool'     => $e['pool'],
				'pid'      => $e['product_id'],
				'title'    => $e['product_id'] > 0 ? html_entity_decode( (string) get_the_title( $e['product_id'] ), ENT_QUOTES, 'UTF-8' ) : '',
				'supplier' => $e['supplier'],
				'inv'      => $e['invoice_no'],
				'note'     => $e['description'],
				'src'      => $e['source'],
			);
			if ( $e['is_test'] ) {
				$row['t'] = 1;
			} else {
				$add( $e['entry_date'], $k, $row['a'], $row['v'] );
			}
			$rows[ $id ] = $row;
		}

		ksort( $rows, SORT_STRING );
		ksort( $months );
		return array( array_values( $rows ), $months );
	}
}

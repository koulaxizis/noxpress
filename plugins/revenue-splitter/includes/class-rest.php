<?php
/**
 * RS_REST — read-only REST routes for orOS (1.11.0).
 *
 * orOS (useoros.online) keeps its data in the browser and reads the shop
 * only while it is open. Its CRM shows, on a partner's card, what the
 * Revenue Splitter owes that beneficiary, the titles sold this year and
 * their stock. These two routes give it the same figures as the
 * revenue-splitter/balances and revenue-splitter/report abilities:
 *
 *  GET /wp-json/wc/v3/rs/balances?name=<beneficiary>
 *      per beneficiary, all time: sales share, other income, payments and
 *      what remains payable
 *  GET /wp-json/wc/v3/rs/report?date_start=Y-m-d&date_end=Y-m-d&name=<beneficiary>
 *      the products sold in a period (default: this calendar year) with
 *      their splits and current stock; with name, only the products that
 *      beneficiary has a share in (and its share of each); totals stay the
 *      whole shop's
 *
 * Authentication: the routes live under wc/v3, so WooCommerce
 * authenticates the shop's own REST keys (ck_/cs_) that orOS already holds
 * for the shop; a read key is enough, and nothing here writes. An
 * Application Password works too. Every route also checks
 * RS_Admin_UI::CAP (manage_woocommerce). Deviation from Bible §20 (own
 * namespace rs/v1): orOS addresses a WooCommerce shop only below
 * /wp-json/wc/v3, in the browser and in its relay's allowlist.
 *
 * Amounts are integer cents, answers are not cached (nocache), errors are
 * in English. Nothing here returns an email address, a portal key or a
 * ledger note, and nothing is stored.
 */

defined( 'ABSPATH' ) || exit;

final class RS_REST {

	const NS = 'wc/v3';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$name = array(
			'type'      => 'string',
			'maxLength' => 200,
		);
		$date = array(
			'type'    => 'string',
			'pattern' => '^\d{4}-\d{2}-\d{2}$',
		);
		register_rest_route(
			self::NS,
			'/rs/balances',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'balances' ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
				'args'                => array( 'name' => $name ),
			)
		);
		register_rest_route(
			self::NS,
			'/rs/report',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'report' ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
				'args'                => array(
					'date_start' => $date,
					'date_end'   => $date,
					'name'       => $name,
				),
			)
		);
	}

	/** Shop managers only. */
	public static function allowed() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rs_auth', 'Authentication required: the shop\'s WooCommerce REST keys or an Application Password.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( RS_Admin_UI::CAP ) ) {
			return new WP_Error( 'rs_cap', 'This user cannot manage WooCommerce.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function balances( WP_REST_Request $req ) {
		$in = array();
		$n  = self::name_param( $req );
		if ( is_wp_error( $n ) ) {
			return $n;
		}
		if ( '' !== $n ) {
			$in['name'] = $n;
		}
		$r = RS_Abilities::balances( $in );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$out = array();
		foreach ( $r['beneficiaries'] as $b ) {
			$out[] = array(
				'name'      => (string) $b['name'],
				'sales'     => self::cents( $b['sales'] ),
				'income'    => self::cents( $b['income'] ),
				'payments'  => self::cents( $b['payments'] ),
				'remaining' => self::cents( $b['remaining'] ),
			);
		}
		return self::nocache(
			array(
				'currency'      => get_woocommerce_currency(),
				'sales_since'   => (string) $r['sales_since'],
				'beneficiaries' => $out,
			)
		);
	}

	public static function report( WP_REST_Request $req ) {
		$n = self::name_param( $req );
		if ( is_wp_error( $n ) ) {
			return $n;
		}
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		$in  = array(
			'date_start' => $now->format( 'Y' ) . '-01-01',
			'date_end'   => $now->format( 'Y-m-d' ),
		);
		foreach ( array( 'date_start', 'date_end' ) as $k ) {
			$v = $req->get_param( $k );
			if ( null !== $v && '' !== $v ) {
				$in[ $k ] = (string) $v;
			}
		}
		$r = RS_Abilities::report( $in );
		if ( is_wp_error( $r ) ) {
			return new WP_Error( $r->get_error_code(), $r->get_error_message(), array( 'status' => 400 ) );
		}
		$products = array();
		foreach ( $r['products'] as $p ) {
			$share = null;
			$splits = array();
			foreach ( $p['splits'] as $s ) {
				$splits[] = array(
					'name'    => (string) $s['name'],
					'percent' => (float) $s['percent'],
					'amount'  => self::cents( $s['amount'] ),
				);
				if ( '' !== $n && (string) $s['name'] === $n ) {
					$share = self::cents( $s['amount'] );
				}
			}
			if ( '' !== $n && null === $share ) {
				continue;
			}
			$row = array(
				'product_id' => (int) $p['product_id'],
				'title'      => html_entity_decode( wp_strip_all_tags( (string) $p['title'] ), ENT_QUOTES, 'UTF-8' ),
				'qty'        => (int) $p['qty'],
				'qty_free'   => (int) $p['qty_free'],
				'gross'      => self::cents( $p['gross'] ),
				'net'        => self::cents( $p['net'] ),
				'splits'     => $splits,
			) + self::stock( (int) $p['product_id'] );
			if ( '' !== $n ) {
				$row['share'] = $share;
			}
			$products[] = $row;
		}
		$out = array(
			'currency'    => get_woocommerce_currency(),
			'sales_since' => (string) $r['sales_since'],
			'period'      => $r['period'],
			'order_count' => (int) $r['order_count'],
			'totals'      => array(
				'gross' => self::cents( $r['totals']['gross'] ),
				'vat'   => self::cents( $r['totals']['vat'] ),
				'net'   => self::cents( $r['totals']['net'] ),
			),
			'products'    => $products,
		);
		if ( '' !== $n ) {
			$total = 0;
			foreach ( $products as $p ) {
				$total += $p['share'];
			}
			$out['name']  = $n;
			$out['share'] = $total;
		}
		return self::nocache( $out );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/** '' when absent; a known beneficiary's name; or WP_Error. */
	private static function name_param( WP_REST_Request $req ) {
		$v = $req->get_param( 'name' );
		if ( null === $v || '' === $v ) {
			return '';
		}
		$v = trim( (string) $v );
		if ( '' === $v || ! in_array( $v, RS_Beneficiaries::collect_names(), true ) ) {
			return new WP_Error( 'rs_name', 'Unknown beneficiary.', array( 'status' => 404 ) );
		}
		return $v;
	}

	/** The product's stock now: quantity when the shop manages it, and status. */
	private static function stock( int $id ): array {
		$p = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		if ( ! $p ) {
			return array(
				'stock'        => null,
				'stock_status' => '',
			);
		}
		return array(
			'stock'        => $p->managing_stock() ? (int) $p->get_stock_quantity() : null,
			'stock_status' => (string) $p->get_stock_status(),
		);
	}

	private static function cents( $v ): int {
		return (int) round( (float) $v * 100 );
	}

	private static function nocache( array $data ): WP_REST_Response {
		$res = new WP_REST_Response( $data, 200 );
		foreach ( wp_get_nocache_headers() as $k => $v ) {
			$res->header( $k, $v );
		}
		return $res;
	}
}

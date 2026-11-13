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
 *      per beneficiary, all time: sales share, other income, deductions
 *      (1.17.0: remaining = sales + income − deductions − payments),
 *      payments and what remains payable
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
 * ledger note.
 *
 * Contracts (RS_Contracts; source of truth: the orOS CRM), namespace
 * rs/v1 since 1.14.1 (1.14.0 had them under wc/v3, which needed a
 * WooCommerce Read/Write key; Chris chose a narrow key instead):
 *  POST /wp-json/rs/v1/contracts
 *      orOS sends the whole list of creators' contracts (full, idempotent
 *      replacement); answer { saved, skipped: [ { id, why } ] }
 *  GET  /wp-json/rs/v1/contracts?ben=<beneficiary>
 *      the stored list, for orOS to check
 *  GET  /wp-json/rs/v1/contract-requests?state=new|oros|closed|all
 *      the Author Portal's renewal requests, newest first
 *  POST /wp-json/rs/v1/contract-requests/<id>  { state: oros|closed }
 *      orOS marks a request after importing it (Bible §20 pull pattern)
 * Authentication: the contracts key (contracts_allowed()). Deviation from
 * Bible §20 («κανένα δικό μας token»): a key that can only touch the
 * contracts, so no WooCommerce key with write rights lives in orOS
 * (Bible 1.101).
 */

defined( 'ABSPATH' ) || exit;

final class RS_REST {

	const NS = 'wc/v3';

	/** 1.14.1: the contracts routes, with their own narrow key (Chris 2026-11-11). */
	const NS_CT = 'rs/v1';

	const KEY_FAIL_MAX = 10;  // Failed contracts-key attempts per IP…
	const KEY_FAIL_WIN = 900; // …per 15 minutes.

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_allowed_cors_headers', array( __CLASS__, 'cors_headers' ) );
	}

	/** orOS calls from the browser: let it send the fallback key header. */
	public static function cors_headers( $headers ) {
		$headers   = is_array( $headers ) ? $headers : array();
		$headers[] = 'X-RS-Key';
		return $headers;
	}

	/**
	 * 1.14.1: the contracts routes take the contracts key that «RS
	 * Συμφωνητικά» makes (Authorization: Bearer rsc_…, or X-RS-Key). It
	 * can do nothing but these four routes, so orOS keeps a Read-only
	 * WooCommerce key. A logged-in shop manager (cookie + nonce or an
	 * Application Password) is let in too, for tests. Failed keys are
	 * counted per IP (hashed): 10 per 15 minutes, then 429.
	 */
	public static function contracts_allowed( WP_REST_Request $req ) {
		if ( is_user_logged_in() && current_user_can( RS_Admin_UI::CAP ) ) {
			return true;
		}
		$tok  = '';
		$auth = (string) $req->get_header( 'authorization' );
		if ( 0 === stripos( $auth, 'Bearer ' ) ) {
			$tok = trim( substr( $auth, 7 ) );
		}
		if ( '' === $tok ) {
			$tok = trim( (string) $req->get_header( 'x_rs_key' ) );
		}
		if ( '' === $tok ) {
			return new WP_Error( 'rs_key', 'Authentication required: the contracts key from Revenue Splitter (Authorization: Bearer rsc_...).', array( 'status' => 401 ) );
		}
		// The right key always gets in, so failures from a shared IP (e.g. a
		// relay) cannot lock orOS out; a 48-character random key cannot be
		// guessed anyway, the limit only cuts the noise.
		if ( RS_Contracts::check_key( $tok ) ) {
			return true;
		}
		$rl = 'rs_rl_k_' . substr( hash( 'sha256', wp_salt( 'nonce' ) . '|' . RS_Portal::client_ip() ), 0, 32 );
		$n  = (int) get_transient( $rl );
		if ( $n >= self::KEY_FAIL_MAX ) {
			return new WP_Error( 'rs_key_rl', 'Too many failed attempts; try again in 15 minutes.', array( 'status' => 429 ) );
		}
		set_transient( $rl, $n + 1, self::KEY_FAIL_WIN );
		return new WP_Error( 'rs_key_bad', 'Invalid contracts key.', array( 'status' => 401 ) );
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
		register_rest_route(
			self::NS_CT,
			'/contracts',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'contracts_get' ),
					'permission_callback' => array( __CLASS__, 'contracts_allowed' ),
					'args'                => array( 'ben' => $name ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'contracts_post' ),
					'permission_callback' => array( __CLASS__, 'contracts_allowed' ),
				),
			)
		);
		register_rest_route(
			self::NS_CT,
			'/contract-requests',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'requests_get' ),
				'permission_callback' => array( __CLASS__, 'contracts_allowed' ),
				'args'                => array(
					'state' => array(
						'type'    => 'string',
						'enum'    => array( 'new', 'oros', 'closed', 'all' ),
						'default' => 'new',
					),
				),
			)
		);
		register_rest_route(
			self::NS_CT,
			'/contract-requests/(?P<id>[A-Za-z0-9_-]{1,64})',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'requests_post' ),
				'permission_callback' => array( __CLASS__, 'contracts_allowed' ),
				'args'                => array(
					'state' => array(
						'type'     => 'string',
						'enum'     => array( 'oros', 'closed' ),
						'required' => true,
					),
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
				'income'     => self::cents( $b['income'] ),
				'deductions' => self::cents( $b['deductions'] ),
				'payments'   => self::cents( $b['payments'] ),
				'remaining'  => self::cents( $b['remaining'] ),
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
	 * Contracts (1.14.0)
	 * ------------------------------------------------------------------- */

	public static function contracts_post( WP_REST_Request $req ) {
		if ( strlen( (string) $req->get_body() ) > RS_Contracts::MAX_BODY ) {
			return new WP_Error( 'rs_contracts_size', 'The body is larger than 1 MB; nothing was saved.', array( 'status' => 413 ) );
		}
		$body = $req->get_json_params();
		if ( null === $body ) {
			return new WP_Error( 'rs_contracts_json', 'The body must be JSON (Content-Type: application/json).', array( 'status' => 400 ) );
		}
		$r = RS_Contracts::ingest( $body );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return self::nocache( $r );
	}

	public static function contracts_get( WP_REST_Request $req ) {
		$v = $req->get_param( 'ben' );
		$b = null === $v ? '' : trim( (string) $v );
		return self::nocache( RS_Contracts::export( $b ) );
	}

	public static function requests_get( WP_REST_Request $req ) {
		$state = (string) $req->get_param( 'state' );
		return self::nocache( array( 'requests' => RS_Contracts::requests( '' === $state ? 'new' : $state ) ) );
	}

	public static function requests_post( WP_REST_Request $req ) {
		$id = (string) $req->get_param( 'id' );
		if ( ! RS_Contracts::set_request_state( $id, (string) $req->get_param( 'state' ) ) ) {
			return new WP_Error( 'rs_request', 'Unknown request.', array( 'status' => 404 ) );
		}
		return self::nocache( array( 'request' => RS_Contracts::request( $id ) ) );
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

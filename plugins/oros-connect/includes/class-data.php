<?php
/**
 * ORC_Data — module «Δεδομένα για orOS» (orOS Connect 1.4.0).
 *
 * One read-only gate that hands orOS the shop's figures, so its «Κατάστημα»
 * app (and Today, Calendar, CRM) can show them. It does not read other
 * plugins' options or tables (Bible §6): it runs their own registered
 * read-only abilities (WordPress Abilities API, 6.9+), from a fixed list
 * written below. An ability that is not marked readonly, or is marked
 * destructive, is never run, even if its name is on the list.
 *
 * REST (namespace wc/v3, like Revenue Splitter and Production Ledger, so
 * WooCommerce authenticates the Read key orOS already holds for the shop):
 *  - GET /wp-json/wc/v3/oros/status
 *        which areas are on, which source plugins are active, last pull
 *  - GET /wp-json/wc/v3/oros/digest?areas=sales,titles&fresh=1
 *        { v, site, at, areas: { <area>: … }, errors: { <area>: why } }
 *        answers are kept 5 minutes (fresh=1 skips a copy older than 60 s)
 *
 * Areas of phase 1 (money), each switchable in WP admin:
 *  - sales       store-pulse/overview for today, this month, this year
 *  - titles      store-pulse/sales, this year, per product
 *  - stock       store-pulse/stock
 *  - splits      revenue-splitter/balances
 *  - costs       production-ledger/report, 1 January → today
 *  - bookstores  trade-accounts/consignment-summary
 *
 * Personal data (Chris's card 3, 18/11/2026): no customer's name, email,
 * phone or address goes out of here. Every answer passes clean(): keys that
 * look like secrets or personal fields are removed, whatever the source
 * plugin returns. Creators' and bookstores' names stay: they are the
 * business partners the figures are about.
 *
 * Nothing here runs for visitors and nothing writes, except one option
 * (orc_od_seen: when orOS last pulled) and the 5-minute transients.
 * Deviation from Bible §20 (own namespace): orOS addresses a WooCommerce
 * shop only below /wp-json/wc/v3, in the browser and in its relay's
 * allowlist (same reason as RS_REST).
 */

defined( 'ABSPATH' ) || exit;

final class ORC_Data {

	const NS     = 'wc/v3';
	const SEEN   = 'orc_od_seen';
	const CACHE  = 'orc_od_c_';
	const TTL    = 300;
	const FRESH  = 60;
	const FORMAT = 1;

	/** Keys never sent, at any depth (secrets and personal fields). */
	const DROP = '/(pass|secret|token|nonce|api_?key|^key$|_key$|^ck$|^cs$|email|e_mail|phone|^tel$|telephone|mobile|address|street|postcode|^zip$|billing|shipping_(first|last|addr)|first_name|last_name|^ip$|ip_address|user_agent)/i';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Area => list of [ ability, input ] (input may be a callable for
	 * dates). Fixed here: nothing outside this list can be run.
	 */
	public static function areas(): array {
		$y = wp_date( 'Y' );
		$t = wp_date( 'Y-m-d' );
		return array(
			'sales'      => array(
				'today' => array( 'store-pulse/overview', array( 'period' => 'today' ) ),
				'month' => array( 'store-pulse/overview', array( 'period' => 'this_month' ) ),
				'year'  => array( 'store-pulse/overview', array( 'period' => 'year' ) ),
			),
			'titles'     => array(
				'year' => array(
					'store-pulse/sales',
					array(
						'period'  => 'this_year',
						'orderby' => 'qty',
						'order'   => 'desc',
						'limit'   => 500,
					),
				),
			),
			'stock'      => array( 'now' => array( 'store-pulse/stock', array() ) ),
			'splits'     => array( 'now' => array( 'revenue-splitter/balances', array() ) ),
			'costs'      => array(
				'year' => array(
					'production-ledger/report',
					array(
						'from' => $y . '-01-01',
						'to'   => $t,
					),
				),
			),
			'bookstores' => array( 'now' => array( 'trade-accounts/consignment-summary', array() ) ),
		);
	}

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/oros/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
			)
		);
		register_rest_route(
			self::NS,
			'/oros/digest',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'digest' ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
				'args'                => array(
					'areas' => array(
						'type'              => 'string',
						'default'           => '',
						'validate_callback' => static function ( $v ) {
							return is_string( $v ) && (bool) preg_match( '/^[a-z,]{0,120}$/', $v );
						},
					),
					'fresh' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/** Managers only; ORC_DISABLE closes the routes. */
	public static function allowed() {
		if ( Oros_Connect::killed() ) {
			return new WP_Error( 'orc_off', 'orOS Connect is switched off on this site (ORC_DISABLE).', array( 'status' => 403 ) );
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'orc_auth', 'Authentication required: the shop\'s WooCommerce REST keys.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( Oros_Connect::cap() ) ) {
			return new WP_Error( 'orc_cap', 'This user cannot manage orOS Connect.', array( 'status' => 403 ) );
		}
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new WP_Error( 'orc_no_abilities', 'This site has no Abilities API (WordPress 6.9+).', array( 'status' => 501 ) );
		}
		return true;
	}

	/** The areas switched on in WP admin. */
	public static function enabled(): array {
		$on = ORC_Settings::get()['od_areas'];
		return array_values( array_filter( array_keys( self::areas() ), static function ( $a ) use ( $on ) {
			return ! empty( $on[ $a ] );
		} ) );
	}

	/** A listed ability that exists and is read-only, or null. */
	private static function ability( string $name ) {
		$ab = wp_get_ability( $name );
		if ( ! $ab ) {
			return null;
		}
		$meta = method_exists( $ab, 'get_meta' ) ? (array) $ab->get_meta() : array();
		$ann  = isset( $meta['annotations'] ) ? (array) $meta['annotations'] : array();
		if ( empty( $ann['readonly'] ) || ! empty( $ann['destructive'] ) ) {
			return null;
		}
		return $ab;
	}

	public static function status(): WP_REST_Response {
		$areas = array();
		foreach ( self::areas() as $a => $calls ) {
			$ok = true;
			foreach ( $calls as $c ) {
				if ( ! self::ability( $c[0] ) ) {
					$ok = false;
				}
			}
			$areas[ $a ] = array(
				'on'     => in_array( $a, self::enabled(), true ),
				'source' => $ok,
			);
		}
		$seen = self::last_seen();
		return self::nocache(
			new WP_REST_Response(
				array(
					'v'       => self::FORMAT,
					'plugin'  => ORC_VERSION,
					'site'    => home_url( '/' ),
					'areas'   => $areas,
					'seen'    => $seen ? gmdate( 'c', $seen['t'] ) : null,
					'admin'   => admin_url( 'admin.php?page=orc-settings&tab=data' ),
				),
				200
			)
		);
	}

	public static function digest( WP_REST_Request $req ): WP_REST_Response {
		$want = array_filter( explode( ',', (string) $req->get_param( 'areas' ) ) );
		$on   = self::enabled();
		$list = $want ? array_values( array_intersect( $on, $want ) ) : $on;
		sort( $list );

		$key  = self::CACHE . md5( implode( ',', $list ) );
		$hold = get_transient( $key );
		if ( is_array( $hold ) && isset( $hold['t'], $hold['body'] ) ) {
			$age = time() - (int) $hold['t'];
			if ( ! $req->get_param( 'fresh' ) || $age < self::FRESH ) {
				self::seen( count( $list ) );
				return self::nocache( new WP_REST_Response( $hold['body'], 200 ) );
			}
		}

		$all    = self::areas();
		$out    = array();
		$errors = array();
		foreach ( $list as $a ) {
			$part = array();
			foreach ( $all[ $a ] as $slot => $c ) {
				$ab = self::ability( $c[0] );
				if ( ! $ab ) {
					$errors[ $a ] = 'source_missing';
					continue 2;
				}
				try {
					$res = $ab->execute( $c[1] ? $c[1] : null );
				} catch ( \Throwable $e ) {
					$res = new WP_Error( 'orc_fail', 'failed' );
				}
				if ( is_wp_error( $res ) ) {
					$errors[ $a ] = sanitize_key( $res->get_error_code() );
					continue 2;
				}
				$part[ $slot ] = self::clean( $res );
			}
			$out[ $a ] = $part;
		}

		$body = array(
			'v'      => self::FORMAT,
			'site'   => home_url( '/' ),
			'at'     => gmdate( 'c' ),
			'areas'  => (object) $out,
			'errors' => (object) $errors,
		);
		set_transient(
			$key,
			array(
				't'    => time(),
				'body' => $body,
			),
			self::TTL
		);
		self::seen( count( $list ) );
		return self::nocache( new WP_REST_Response( $body, 200 ) );
	}

	/** Drops secret-looking and personal keys at any depth. */
	public static function clean( $data, int $depth = 0 ) {
		if ( $depth > 12 ) {
			return null;
		}
		if ( is_object( $data ) ) {
			$data = get_object_vars( $data );
		}
		if ( ! is_array( $data ) ) {
			return is_scalar( $data ) || null === $data ? $data : null;
		}
		$out = array();
		foreach ( $data as $k => $v ) {
			if ( is_string( $k ) && preg_match( self::DROP, $k ) ) {
				continue;
			}
			$out[ $k ] = self::clean( $v, $depth + 1 );
		}
		return $out;
	}

	/** Forgets the kept answers (after the areas change). */
	public static function flush(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own prefixed transients.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::CACHE ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::CACHE ) . '%'
			)
		);
	}

	private static function seen( int $areas ): void {
		update_option(
			self::SEEN,
			array(
				't' => time(),
				'n' => $areas,
			),
			false
		);
	}

	public static function last_seen(): array {
		$s = get_option( self::SEEN, array() );
		return is_array( $s ) && isset( $s['t'] ) ? array(
			't' => (int) $s['t'],
			'n' => isset( $s['n'] ) ? (int) $s['n'] : 0,
		) : array();
	}

	private static function nocache( WP_REST_Response $res ): WP_REST_Response {
		foreach ( wp_get_nocache_headers() as $k => $v ) {
			$res->header( $k, $v );
		}
		return $res;
	}
}

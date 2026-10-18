<?php
/**
 * ORC_Offers — module «Προσφορές» (orOS Connect 1.1.0).
 *
 * orOS is static and keeps its data in the browser, so the site never
 * pushes anything into it (design, notes/oros-integration §1). While orOS
 * is open, it pulls the «I'm interested» requests of Publishing Quote,
 * makes a draft offer of each one and marks the request «In orOS». The
 * offer goes to the author only when the owner sends it from orOS.
 *
 * REST (namespace orc/v1, Bible §20):
 *  - GET  /offers?state=new&limit=50   requests, newest first, as orOS
 *                                       needs them (amounts in cents)
 *  - GET  /offers/<id>                  one request
 *  - POST /offers/<id>  { state, note } new | contact | oros | closed
 *
 * Authentication: the WooCommerce REST keys orOS already holds for the
 * shop (the namespace is declared a WooCommerce REST request, so a read
 * key reads and only a read/write key can POST), or, without WooCommerce,
 * an Application Password of a user who can manage the plugin. Every route
 * also checks the capability. ORC_DISABLE: 403.
 *
 * Nothing here runs for visitors: the routes answer only to managers, and
 * the module writes one option (orc_of_seen: when orOS last pulled).
 */

defined( 'ABSPATH' ) || exit;

final class ORC_Offers {

	const NS    = 'orc/v1';
	const SEEN  = 'orc_of_seen';
	const LIMIT = 100;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		// WooCommerce authenticates its REST keys only on its own routes:
		// ours count as such, so orOS can use the keys it already has.
		add_filter( 'woocommerce_rest_is_request_to_rest_api', array( __CLASS__, 'is_wc_rest' ) );
	}

	/** True when Publishing Quote (the source of the requests) is active. */
	public static function source(): bool {
		return function_exists( 'pbq_get_requests' ) && function_exists( 'pbq_get_request' ) && function_exists( 'pbq_set_request_state' );
	}

	public static function is_wc_rest( $is ) {
		if ( $is ) {
			return $is;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri || ! function_exists( 'rest_get_url_prefix' ) ) {
			return $is;
		}
		$prefix = trailingslashit( rest_get_url_prefix() );
		// Pretty permalinks (/wp-json/orc/v1/…) and plain ones (?rest_route=/orc/v1/…).
		return false !== strpos( $uri, $prefix . self::NS . '/' ) || false !== strpos( rawurldecode( $uri ), 'rest_route=/' . self::NS . '/' );
	}

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/offers',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'index' ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
				'args'                => array(
					'state' => array(
						'type'    => 'string',
						'enum'    => array( 'new', 'contact', 'oros', 'closed', 'all' ),
						'default' => 'new',
					),
					'limit' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => self::LIMIT,
						'default' => 50,
					),
					'page'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/offers/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'one' ),
					'permission_callback' => array( __CLASS__, 'allowed' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'mark' ),
					'permission_callback' => array( __CLASS__, 'allowed' ),
					'args'                => array(
						'state' => array(
							'type'     => 'string',
							'enum'     => array( 'new', 'contact', 'oros', 'closed' ),
							'required' => true,
						),
						'note'  => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
			)
		);
	}

	/** Managers only; ORC_DISABLE closes every route. */
	public static function allowed() {
		if ( Oros_Connect::killed() ) {
			return new WP_Error( 'orc_off', 'orOS Connect is switched off on this site (ORC_DISABLE).', array( 'status' => 403 ) );
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'orc_auth', 'Authentication required: the shop\'s WooCommerce REST keys or an Application Password.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( Oros_Connect::cap() ) ) {
			return new WP_Error( 'orc_cap', 'This user cannot manage orOS Connect.', array( 'status' => 403 ) );
		}
		if ( ! self::source() ) {
			return new WP_Error( 'orc_no_source', 'Publishing Quote is not active on this site.', array( 'status' => 409 ) );
		}
		return true;
	}

	public static function index( WP_REST_Request $req ): WP_REST_Response {
		$state = (string) $req->get_param( 'state' );
		$limit = (int) $req->get_param( 'limit' );
		$args  = array(
			'limit' => $limit,
			'page'  => (int) $req->get_param( 'page' ),
		);
		if ( 'all' !== $state ) {
			$args['state'] = $state;
		}
		$items = array();
		foreach ( pbq_get_requests( $args ) as $id ) {
			$o = self::offer( (int) $id );
			if ( $o ) {
				$items[] = $o;
			}
		}
		self::seen( count( $items ) );
		return self::nocache(
			new WP_REST_Response(
				array(
					'site'  => home_url( '/' ),
					'items' => $items,
					'more'  => count( $items ) === $limit,
				),
				200
			)
		);
	}

	public static function one( WP_REST_Request $req ) {
		$o = self::offer( (int) $req['id'] );
		if ( ! $o ) {
			return new WP_Error( 'orc_not_found', 'No such request.', array( 'status' => 404 ) );
		}
		return self::nocache( new WP_REST_Response( $o, 200 ) );
	}

	public static function mark( WP_REST_Request $req ) {
		$id   = (int) $req['id'];
		$note = mb_substr( sanitize_text_field( (string) $req->get_param( 'note' ) ), 0, 500 );
		if ( ! pbq_set_request_state( $id, (string) $req->get_param( 'state' ), $note ) ) {
			return new WP_Error( 'orc_not_found', 'No such request.', array( 'status' => 404 ) );
		}
		return self::nocache( new WP_REST_Response( self::offer( $id ), 200 ) );
	}

	/**
	 * One request in the shape orOS needs for a draft offer: the lines the
	 * author saw (typesetting already inside), and the breakdown as an
	 * internal note that never goes to the author.
	 */
	public static function offer( int $id ): ?array {
		$r = pbq_get_request( $id );
		if ( ! $r ) {
			return null;
		}
		$note = array();
		foreach ( (array) $r['breakdown'] as $row ) {
			if ( is_array( $row ) && isset( $row[0], $row[1] ) ) {
				$note[] = $row[0] . ': ' . $row[1];
			}
		}
		$vat = array();
		foreach ( (array) $r['vat'] as $v ) {
			$vat[] = array(
				'rate'   => (float) $v['rate'],
				'amount' => (int) $v['amount'],
			);
		}
		$lines = array();
		foreach ( $r['lines'] as $l ) {
			$lines[] = array(
				'label'  => (string) $l['label'],
				'amount' => (int) $l['amount'],
				'vat'    => (float) $l['vat'],
				'ask'    => (bool) $l['ask'],
			);
		}
		return array(
			'id'          => (int) $r['id'],
			'source'      => 'publishing-quote',
			'date'        => (string) $r['date'],
			'state'       => (string) $r['state'],
			'lang'        => (string) $r['lang'],
			'contact'     => array(
				'name'  => (string) $r['contact']['name'],
				'email' => (string) $r['contact']['email'],
				'phone' => (string) $r['contact']['phone'],
				'book'  => (string) $r['contact']['book'],
				'msg'   => (string) $r['contact']['msg'],
			),
			'lines'       => $lines,
			'typeset'     => (bool) $r['typeset'],
			'total'       => (int) $r['total'],
			'incl_vat'    => (bool) $r['incl_vat'],
			'vat'         => $vat,
			'per_copy'    => (int) $r['per_copy'],
			'currency'    => '' !== (string) $r['currency'] ? (string) $r['currency'] : 'EUR',
			'valid_until' => (string) $r['valid_until'],
			'note'        => implode( "\n", $note ),
			'admin_url'   => admin_url( 'admin.php?page=pbq-settings&tab=requests&view=' . (int) $r['id'] ),
		);
	}

	/** When orOS last pulled, for the admin. */
	private static function seen( int $count ): void {
		update_option(
			self::SEEN,
			array(
				't' => time(),
				'n' => $count,
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

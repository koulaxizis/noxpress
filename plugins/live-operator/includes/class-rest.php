<?php
/**
 * LOP_REST — namespace live-operator/v1, for the app page only.
 *
 * Cookie login + the wp_rest nonce (the app page is the operator's own
 * logged-in session), capability manage_options on every route, answers
 * never cached, error messages in English (Bible §20).
 *
 *   GET    /state         switch, expiry, PurioChat status, push support
 *   POST   /state         { online: bool } — online again = extend
 *   GET    /push          public key and this user's device count
 *   POST   /push          { endpoint, p256dh, auth } — this device
 *   DELETE /push          { endpoint }
 *   POST   /push/test     a test notification to this user's devices
 */

defined( 'ABSPATH' ) || exit;

final class LOP_REST {

	const NS = 'live-operator/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$perm = array( __CLASS__, 'permission' );
		register_rest_route(
			self::NS,
			'/state',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_state' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'set_state' ),
					'permission_callback' => $perm,
					'args'                => array(
						'online' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/push',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_push' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'add_push' ),
					'permission_callback' => $perm,
					'args'                => self::string_args( array( 'endpoint', 'p256dh', 'auth' ), true ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_push' ),
					'permission_callback' => $perm,
					'args'                => self::string_args( array( 'endpoint' ), true ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/push/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'test_push' ),
				'permission_callback' => $perm,
			)
		);
	}

	private static function string_args( array $names, bool $required ): array {
		$args = array();
		foreach ( $names as $name ) {
			$args[ $name ] = array(
				'type'      => 'string',
				'required'  => $required,
				'maxLength' => 1000,
			);
		}
		return $args;
	}

	/** @return true|WP_Error */
	public static function permission() {
		if ( Live_Operator::killed() ) {
			return new WP_Error( 'lop_disabled', 'Live Operator is disabled (LOP_DISABLE).', array( 'status' => 403 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'lop_forbidden', 'Only an administrator can operate the live chat.', array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/** Everything the app shows at the top. */
	public static function state_payload(): array {
		$set = LOP_Settings::get();
		$s   = LOP_Presence::state();
		return array(
			'enabled' => $set['enabled'],
			'online'  => LOP_Presence::online(),
			'until'   => $s['on'] ? $s['until'] : 0,
			'since'   => $s['on'] ? $s['since'] : 0,
			'now'     => time(),
			'hours'   => $set['hours'],
			'purio'   => array(
				'present' => LOP_Purio::present(),
				'live'    => LOP_Purio::live(),
				'mode'    => LOP_Purio::mode(),
				'timeout' => LOP_Purio::timeout(),
			),
			'push'    => array(
				'available' => LOP_Push::available(),
				'devices'   => count( LOP_Push::subscriptions( get_current_user_id() ) ),
			),
		);
	}

	private static function respond( $data, int $status = 200 ): WP_REST_Response {
		$r = new WP_REST_Response( $data, $status );
		$r->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
		$r->header( 'CDN-Cache-Control', 'no-store' );
		return $r;
	}

	public static function get_state(): WP_REST_Response {
		return self::respond( self::state_payload() );
	}

	/** @return WP_REST_Response|WP_Error */
	public static function set_state( WP_REST_Request $request ) {
		if ( ! LOP_Settings::get()['enabled'] ) {
			return new WP_Error( 'lop_switch_off', 'The switch is not enabled in the Live Operator settings.', array( 'status' => 409 ) );
		}
		if ( rest_sanitize_boolean( $request->get_param( 'online' ) ) ) {
			LOP_Presence::go_online();
		} else {
			LOP_Presence::go_offline();
		}
		return self::respond( self::state_payload() );
	}

	public static function get_push(): WP_REST_Response {
		return self::respond(
			array(
				'available' => LOP_Push::available(),
				'key'       => LOP_Push::public_key(),
				'devices'   => count( LOP_Push::subscriptions( get_current_user_id() ) ),
			)
		);
	}

	/** @return WP_REST_Response|WP_Error */
	public static function add_push( WP_REST_Request $request ) {
		$ok = LOP_Push::subscribe(
			get_current_user_id(),
			trim( (string) $request->get_param( 'endpoint' ) ),
			trim( (string) $request->get_param( 'p256dh' ) ),
			trim( (string) $request->get_param( 'auth' ) )
		);
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return self::respond( array( 'devices' => count( LOP_Push::subscriptions( get_current_user_id() ) ) ), 201 );
	}

	public static function delete_push( WP_REST_Request $request ): WP_REST_Response {
		LOP_Push::unsubscribe( get_current_user_id(), trim( (string) $request->get_param( 'endpoint' ) ) );
		return self::respond( array( 'devices' => count( LOP_Push::subscriptions( get_current_user_id() ) ) ) );
	}

	public static function test_push(): WP_REST_Response {
		$res = LOP_Push::send(
			get_current_user_id(),
			array(
				'title' => __( 'Δοκιμαστική ειδοποίηση', 'live-operator' ),
				'body'  => __( 'Οι ειδοποιήσεις φτάνουν σε αυτή τη συσκευή.', 'live-operator' ),
				'tag'   => 'lop-test',
				'url'   => LOP_App::url(),
			)
		);
		return self::respond( $res );
	}
}

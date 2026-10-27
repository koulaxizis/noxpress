<?php
/**
 * BRC_Rest — REST namespace broadcast/v1 (Bible §14.4 deviation, see the
 * bootstrap docblock).
 *
 *  POST /broadcast/v1/viber                          Viber callbacks (module viber)
 *  POST /broadcast/v1/hook/newsletter/<provider>     "campaign sent" (module newsletter)
 *
 * Both are public routes that accept only signed requests: Viber's
 * X-Viber-Content-Signature, MailerLite's Signature (HMAC-SHA256 of the raw
 * body with the webhook secret). A valid request writes one queue row at
 * most and answers at once; posting happens in the background. Answers are
 * not cached, errors are in English, BRC_DISABLE answers 403.
 */

defined( 'ABSPATH' ) || exit;

final class BRC_Rest {

	const NS = 'broadcast/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		if ( BRC_Settings::module( 'viber' ) ) {
			register_rest_route(
				self::NS,
				'/viber',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'viber' ),
					'permission_callback' => '__return_true', // Signed by Viber, checked in the callback.
				)
			);
		}
		if ( BRC_Settings::module( 'newsletter' ) ) {
			register_rest_route(
				self::NS,
				'/hook/newsletter/(?P<provider>[a-z]+)',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'newsletter' ),
					'permission_callback' => '__return_true', // Signed by the provider, checked in the callback.
				)
			);
		}
	}

	private static function answer( array $data, int $code ): WP_REST_Response {
		$r = new WP_REST_Response( $data, $code );
		foreach ( wp_get_nocache_headers() as $k => $v ) {
			$r->header( $k, (string) $v );
		}
		return $r;
	}

	public static function viber( WP_REST_Request $req ): WP_REST_Response {
		if ( Broadcast::killed() ) {
			return self::answer( array( 'error' => 'disabled' ), 403 );
		}
		require_once BRC_PATH . 'includes/class-viber.php';
		$sig = (string) $req->get_header( 'x_viber_content_signature' );
		if ( ! BRC_Viber::callback( (string) $req->get_body(), $sig ) ) {
			return self::answer( array( 'error' => 'bad signature' ), 403 );
		}
		return self::answer( array( 'status' => 0 ), 200 );
	}

	public static function newsletter( WP_REST_Request $req ): WP_REST_Response {
		if ( Broadcast::killed() ) {
			return self::answer( array( 'error' => 'disabled' ), 403 );
		}
		$provider = (string) $req->get_param( 'provider' );
		if ( 'mailerlite' !== $provider ) {
			return self::answer( array( 'error' => 'unknown provider' ), 404 );
		}
		if ( ! Broadcast::load_source( 'newsletter' ) ) {
			return self::answer( array( 'error' => 'off' ), 404 );
		}
		$res = BRC_Src_Newsletter::mailerlite( (string) $req->get_body(), (string) $req->get_header( 'signature' ) );
		if ( 'bad_signature' === $res ) {
			return self::answer( array( 'error' => 'bad signature' ), 401 );
		}
		// Always 200 otherwise, also for events we ignore: MailerLite
		// retries any other answer and would send duplicates.
		return self::answer( array( 'ok' => true ), 200 );
	}
}

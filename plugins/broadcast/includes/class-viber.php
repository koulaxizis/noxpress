<?php
/**
 * BRC_Viber — module "viber" (destination): a Viber Channel through the
 * Channels Post API.
 *
 *  1. The channel's superadmin copies the token from Viber (channel info →
 *     Settings → Developer Tools) into the settings (sealed, BRC_Crypt).
 *  2. «Σύνδεση με το Viber»: set_webhook with our REST route
 *     broadcast/v1/viber (Viber checks it at once with a signed callback),
 *     then get_account_info for the channel, its subscribers and the
 *     superadmin id, which every post sends as "from".
 *  3. Posts: text, or a picture with a caption, or a bare link.
 *
 * Callbacks are accepted only with a valid X-Viber-Content-Signature
 * (HMAC-SHA256 of the body with the token). The token goes as the
 * X-Viber-Auth-Token header (and auth_token in the body, as the API's
 * examples do) and never into the history or an error message.
 *
 * Requests: wp_safe_remote_post, no redirects, 15 s. The API base can be
 * changed with the filter brc_viber_api (local tests only).
 */

defined( 'ABSPATH' ) || exit;

final class BRC_Viber {

	const API         = 'https://chatapi.viber.com/pa/';
	const OPT         = 'brc_viber';
	const TEXT_MAX    = 7000;
	const CAPTION_MAX = 768;

	public static function api(): string {
		/**
		 * Filters the Channels Post API base (with a trailing slash). For local tests.
		 *
		 * @param string $base https://chatapi.viber.com/pa/
		 */
		return trailingslashit( (string) apply_filters( 'brc_viber_api', self::API ) );
	}

	/** The connection: channel, uri, subs, from, at, hook; empty when not connected. */
	public static function account(): array {
		$a = get_option( self::OPT, array() );
		return is_array( $a ) ? $a : array();
	}

	public static function connected(): bool {
		$a = self::account();
		return ! empty( $a['from'] ) && '' !== BRC_Crypt::get( 'viber' );
	}

	public static function webhook_url(): string {
		return rest_url( 'broadcast/v1/viber' );
	}

	/**
	 * One API call.
	 *
	 * @return array code (HTTP, 0 = network), json (array|null), error (string)
	 */
	private static function call( string $endpoint, array $body, string $token ): array {
		$body['auth_token'] = $token;
		$res                = wp_safe_remote_post(
			self::api() . $endpoint,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'       => 'application/json',
					'X-Viber-Auth-Token' => $token,
				),
				'body'        => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array(
				'code'  => 0,
				'json'  => null,
				'error' => BRC_Crypt::mask( $res->get_error_message() ),
			);
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return array(
			'code'  => (int) wp_remote_retrieve_response_code( $res ),
			'json'  => is_array( $json ) ? $json : null,
			'error' => '',
		);
	}

	/** Viber's status_message, or a short description of the failure. */
	private static function why( array $r ): string {
		if ( 0 === $r['code'] ) {
			return 'network: ' . $r['error'];
		}
		if ( is_array( $r['json'] ) ) {
			$st  = isset( $r['json']['status'] ) ? (int) $r['json']['status'] : -1;
			$msg = isset( $r['json']['status_message'] ) && is_scalar( $r['json']['status_message'] ) ? (string) $r['json']['status_message'] : '';
			return BRC_Crypt::mask( 'status ' . $st . ( '' !== $msg ? ': ' . $msg : '' ) );
		}
		return 'HTTP ' . $r['code'];
	}

	private static function ok( array $r ): bool {
		return 200 === $r['code'] && is_array( $r['json'] ) && isset( $r['json']['status'] ) && 0 === (int) $r['json']['status'];
	}

	/**
	 * set_webhook + get_account_info with the saved token.
	 *
	 * @return array ok (bool), error (string)
	 */
	public static function connect(): array {
		$token = BRC_Crypt::get( 'viber' );
		if ( '' === $token ) {
			return array(
				'ok'    => false,
				'error' => 'no token',
			);
		}
		$hook = self::call( 'set_webhook', array( 'url' => self::webhook_url() ), $token );
		if ( ! self::ok( $hook ) ) {
			return array(
				'ok'    => false,
				'error' => 'set_webhook: ' . self::why( $hook ),
			);
		}
		$info = self::call( 'get_account_info', array(), $token );
		if ( ! self::ok( $info ) ) {
			return array(
				'ok'    => false,
				'error' => 'get_account_info: ' . self::why( $info ),
			);
		}
		$j    = $info['json'];
		$from = '';
		foreach ( isset( $j['members'] ) && is_array( $j['members'] ) ? $j['members'] : array() as $m ) {
			if ( is_array( $m ) && isset( $m['role'], $m['id'] ) && 'superadmin' === $m['role'] && is_scalar( $m['id'] ) ) {
				$from = (string) $m['id'];
				break;
			}
		}
		if ( '' === $from ) {
			return array(
				'ok'    => false,
				'error' => 'get_account_info: no superadmin in members',
			);
		}
		update_option(
			self::OPT,
			array(
				'channel' => isset( $j['name'] ) && is_scalar( $j['name'] ) ? mb_substr( sanitize_text_field( (string) $j['name'] ), 0, 120 ) : '',
				'uri'     => isset( $j['uri'] ) && is_scalar( $j['uri'] ) ? mb_substr( sanitize_text_field( (string) $j['uri'] ), 0, 120 ) : '',
				'subs'    => isset( $j['subscribers_count'] ) ? (int) $j['subscribers_count'] : 0,
				'from'    => mb_substr( $from, 0, 64 ),
				'at'      => time(),
				'hook'    => self::webhook_url(),
			),
			false
		);
		return array(
			'ok'    => true,
			'error' => '',
		);
	}

	/** Removes the webhook at Viber (best effort) and forgets the connection. */
	public static function disconnect(): void {
		$token = BRC_Crypt::get( 'viber' );
		if ( '' !== $token ) {
			self::call( 'set_webhook', array( 'url' => '' ), $token );
		}
		delete_option( self::OPT );
	}

	/** Cuts a text at a word boundary to $max characters. */
	private static function cut( string $text, int $max ): string {
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $max - 1 );
		$sp  = mb_strrpos( $cut, ' ' );
		if ( false !== $sp && $sp > $max * 0.6 ) {
			$cut = mb_substr( $cut, 0, $sp );
		}
		return rtrim( $cut ) . '…';
	}

	/** The API body for a neutral message (without auth_token). */
	public static function payload( array $msg, string $from ): array {
		$text = (string) $msg['text'];
		if ( 'picture' === $msg['kind'] && mb_strlen( $text ) <= self::CAPTION_MAX ) {
			return array(
				'from'      => $from,
				'type'      => 'picture',
				'text'      => $text,
				'media'     => $msg['image'],
				'thumbnail' => $msg['image'],
			);
		}
		if ( '' === $text && '' !== $msg['url'] ) {
			return array(
				'from'  => $from,
				'type'  => 'url',
				'media' => $msg['url'],
			);
		}
		return array(
			'from' => $from,
			'type' => 'text',
			'text' => self::cut( $text, self::TEXT_MAX ),
		);
	}

	/**
	 * Posts a message.
	 *
	 * @return array ok, retry, result (message token or error), reason
	 */
	public static function post( array $msg ): array {
		$token = BRC_Crypt::get( 'viber' );
		$acc   = self::account();
		if ( '' === $token || empty( $acc['from'] ) ) {
			return array(
				'ok'     => false,
				'retry'  => false,
				'result' => 'not connected',
				'reason' => 'connect',
			);
		}
		$body = self::payload( $msg, (string) $acc['from'] );
		$r    = self::call( 'post', $body, $token );

		// A picture Viber refuses (size, type) goes once more as text.
		if ( ! self::ok( $r ) && 'picture' === $body['type'] && is_array( $r['json'] ) && 3 === (int) ( $r['json']['status'] ?? -1 ) ) {
			$msg['kind'] = 'text';
			$body        = self::payload( $msg, (string) $acc['from'] );
			$r           = self::call( 'post', $body, $token );
		}

		if ( self::ok( $r ) ) {
			$mt = isset( $r['json']['message_token'] ) && is_scalar( $r['json']['message_token'] ) ? (string) $r['json']['message_token'] : '';
			return array(
				'ok'     => true,
				'retry'  => false,
				'result' => '' !== $mt ? 'message_token ' . $mt : 'ok',
				'reason' => '',
			);
		}

		$st = is_array( $r['json'] ) && isset( $r['json']['status'] ) ? (int) $r['json']['status'] : -1;
		// Network, server errors and "too many requests" (12) are retried; bad
		// token (2), bad data (3, 4) or no webhook (10) are not.
		$retry  = 0 === $r['code'] || $r['code'] >= 500 || 429 === $r['code'] || 12 === $st;
		$reason = 0 === $r['code'] ? 'network' : ( 2 === $st || 10 === $st ? 'connect' : ( 12 === $st || 429 === $r['code'] ? 'rate' : 'refused' ) );
		return array(
			'ok'     => false,
			'retry'  => $retry,
			'result' => self::why( $r ),
			'reason' => $reason,
		);
	}

	/**
	 * A callback from Viber (REST). Valid signature: notes the time and
	 * answers 200. Nothing from the body is stored.
	 */
	public static function callback( string $body, string $signature ): bool {
		$token = BRC_Crypt::get( 'viber' );
		if ( '' === $token || '' === $signature || ! hash_equals( hash_hmac( 'sha256', $body, $token ), strtolower( $signature ) ) ) {
			return false;
		}
		BRC_Queue::note( 'viber_cb', time() );
		return true;
	}
}

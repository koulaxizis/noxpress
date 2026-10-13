<?php
/**
 * ORC_Relay — the orOS Newsletter sign-up address on the orOS relay.
 *
 * orOS Newsletter (Subscribe link → "HTML for your site") gives a form
 * whose action is https://<relay>/nl/s/<k>. The relay keeps sign-ups
 * encrypted for the list until the list's orOS pulls them; orOS then sends
 * the confirmation e-mail. Fields: email, name (≤ 120), lang (el|en),
 * website (honeypot: a filled one is thanked and dropped). Answers:
 *  - 200 page or 303 to the list's thank-you page → accepted
 *  - 400 bad e-mail, 404 unknown list / form turned off, 413 too big
 *  - 429 too many requests, 503 storage not available
 *
 * Checkout sign-ups also carry src=shop, ts (Unix seconds) and
 * sig = HMAC-SHA256(shop key, lower-case email|ts) when a shop key is set
 * and the confirmation is off for them. Relays that do not know these
 * fields ignore them (the person then gets the confirmation e-mail).
 *
 * Requests from the server only with wp_safe_remote_post (no private
 * addresses, standard ports, no redirects followed).
 */

defined( 'ABSPATH' ) || exit;

final class ORC_Relay {

	const LOG     = 'orc_nl_log';
	const LOG_MAX = 50;
	const RE      = '#https://([A-Za-z0-9-]{1,63}(?:\.[A-Za-z0-9-]{1,63})+)/nl/s/([a-z0-9]{16})(?![A-Za-z0-9])#';

	/**
	 * The canonical sign-up address from a pasted address or from the whole
	 * HTML form orOS gives; '' when there is none.
	 */
	public static function parse_link( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw || strlen( $raw ) > 8192 ) {
			return '';
		}
		$raw = html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' );
		if ( ! preg_match( self::RE, $raw, $m ) ) {
			return '';
		}
		$host = strtolower( $m[1] );
		if ( preg_match( '/^[0-9.]+$/', $host ) || 'localhost' === $host ) {
			return '';
		}
		return 'https://' . $host . '/nl/s/' . $m[2];
	}

	/** Host of a sign-up address, for display. */
	public static function host( string $url ): string {
		$h = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $h ) ? $h : '';
	}

	/**
	 * POST to the sign-up address.
	 *
	 * @return array{res:string, code:int} res: ok | bad | gone | busy | off | error | network
	 */
	public static function post( string $url, array $fields ): array {
		if ( '' === self::parse_link( $url ) ) {
			return array(
				'res'  => 'gone',
				'code' => 0,
			);
		}
		$r = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
					'Accept'       => 'text/html',
				),
				'body'        => http_build_query( $fields, '', '&', PHP_QUERY_RFC1738 ),
				'user-agent'  => 'orOS Connect/' . ORC_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $r ) ) {
			return array(
				'res'  => 'network',
				'code' => 0,
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		return array(
			'res'  => self::classify( $code ),
			'code' => $code,
		);
	}

	/** Result of an HTTP status from the relay's sign-up route. */
	public static function classify( int $code ): string {
		if ( 200 === $code || 303 === $code ) {
			return 'ok';
		}
		if ( 400 === $code || 413 === $code ) {
			return 'bad';
		}
		if ( 404 === $code || 405 === $code ) {
			return 'gone';
		}
		if ( 429 === $code ) {
			return 'busy';
		}
		if ( 503 === $code ) {
			return 'off';
		}
		return 'error';
	}

	/** True for results worth another try later. */
	public static function retryable( string $res ): bool {
		return in_array( $res, array( 'busy', 'off', 'error', 'network' ), true );
	}

	/**
	 * Connection check without a sign-up: the relay looks the list up
	 * first and thanks a filled honeypot without storing anything.
	 */
	public static function check( string $url ): array {
		return self::post(
			$url,
			array(
				'email'   => '',
				'website' => 'orc-check',
				'lang'    => 'en',
			)
		);
	}

	/**
	 * Fields of a checkout sign-up.
	 *
	 * @param string $key Shop key ('' = no signature).
	 */
	public static function fields( string $email, string $name, string $lang, string $key = '', int $ts = 0 ): array {
		$f = array(
			'email'   => $email,
			'name'    => mb_substr( $name, 0, 120 ),
			'lang'    => 'en' === $lang ? 'en' : 'el',
			'website' => '',
		);
		if ( '' !== $key ) {
			$ts         = $ts > 0 ? $ts : time();
			$f['src']   = 'shop';
			$f['ts']    = (string) $ts;
			$f['sig']   = self::sign( $key, $email, $ts );
		}
		return $f;
	}

	/** HMAC-SHA256(shop key, lower-case email|ts), hex. */
	public static function sign( string $key, string $email, int $ts ): string {
		return hash_hmac( 'sha256', strtolower( trim( $email ) ) . '|' . $ts, $key );
	}

	/** Reason of a result, in the admin language. */
	public static function reason( string $res ): string {
		$r = array(
			'ok'      => __( 'εντάξει', 'oros-connect' ),
			'bad'     => __( 'άκυρο email', 'oros-connect' ),
			'gone'    => __( 'η διεύθυνση εγγραφής δεν ισχύει ή η φόρμα έκλεισε στο orOS', 'oros-connect' ),
			'busy'    => __( 'πολλά αιτήματα, νέα προσπάθεια αργότερα', 'oros-connect' ),
			'off'     => __( 'οι εγγραφές δεν είναι διαθέσιμες στο relay του orOS', 'oros-connect' ),
			'error'   => __( 'σφάλμα του relay', 'oros-connect' ),
			'network' => __( 'δεν υπήρξε σύνδεση', 'oros-connect' ),
			'skip'    => __( 'το module ή η διεύθυνση λείπει', 'oros-connect' ),
		);
		return isset( $r[ $res ] ) ? $r[ $res ] : $res;
	}

	/* =====================================================================
	 * Send log (option, not autoloaded): last LOG_MAX attempts, no e-mails.
	 * =================================================================== */

	public static function log( int $order_id, string $res, int $code, int $try ): void {
		$log = self::get_log();
		array_unshift(
			$log,
			array(
				't'     => time(),
				'order' => $order_id,
				'res'   => $res,
				'code'  => $code,
				'try'   => $try,
			)
		);
		update_option( self::LOG, array_slice( $log, 0, self::LOG_MAX ), false );
	}

	public static function get_log(): array {
		$log = get_option( self::LOG, array() );
		$out = array();
		foreach ( is_array( $log ) ? $log : array() as $e ) {
			if ( is_array( $e ) && isset( $e['t'], $e['order'], $e['res'], $e['code'], $e['try'] ) ) {
				$out[] = array(
					't'     => (int) $e['t'],
					'order' => (int) $e['order'],
					'res'   => (string) $e['res'],
					'code'  => (int) $e['code'],
					'try'   => (int) $e['try'],
				);
			}
		}
		return $out;
	}

	public static function clear_log(): void {
		delete_option( self::LOG );
	}
}

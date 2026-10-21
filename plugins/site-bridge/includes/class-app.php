<?php
/**
 * SBR_App — the Android app of the Hub (from 1.6.0).
 *
 * The app does one thing: it opens and closes the write window of the
 * Hub's sites, through the Hub (SBR_Hub::window(), the same signed op the
 * Sites tab uses), and shows the Hub's log. Nothing new runs on the sites.
 *
 * REST routes (namespace site-bridge/v1), only while the Hub role and the
 * "Mobile app" switch are on (404 otherwise):
 *  - POST /app/pair    one-time code from the Hub's admin (QR, 15 minutes)
 *                      + the device's two public keys
 *  - GET  /app/sites   sites with level write or admin, fresh status
 *  - POST /app/window  open (minutes > 0) or close (0) one site's window
 *  - GET  /app/log     the latest rows of the Hub's log
 *  - POST /app/forget  the device removes itself
 *
 * Devices (option sbr_app_devices, autoload off): two ECDSA P-256 keys
 * made in the Android Keystore, which never leave the phone. The "read"
 * key signs without a fingerprint; the "act" key signs only after one
 * (the Keystore enforces it), and opening a window accepts only it.
 * Closing a window shuts access, so the read key is enough.
 *
 * Request signature (SHA256withECDSA, DER, base64):
 *   SBA1|<METHOD>|<route>|<device id>|<unix time>|<nonce>|<sha256 hex of body>
 * route as WordPress sees it, e.g. /site-bridge/v1/app/window. Headers
 * X-SBA-Device, X-SBA-Ts, X-SBA-Nonce, X-SBA-Key (read|act), X-SBA-Sig.
 * Time window ±300 s, a nonce is accepted once. No Authorization header
 * and no password: hosts that strip that header do not matter.
 *
 * Pairing proof: the read key signs SBA1|PAIR|<code token>|<sha256 of body>.
 */

defined( 'ABSPATH' ) || exit;

final class SBR_App {

	const DEVICES     = 'sbr_app_devices';
	const PAIRING     = 'sbr_app_pairing';
	const MAX_DEVICES = 10;
	const CODE_TTL    = 900;
	const LOG_MAX     = 100;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$routes = array(
			'/app/pair'   => array( 'POST', 'pair' ),
			'/app/sites'  => array( 'GET', 'sites' ),
			'/app/window' => array( 'POST', 'window' ),
			'/app/log'    => array( 'GET', 'log' ),
			'/app/forget' => array( 'POST', 'forget' ),
		);
		foreach ( $routes as $path => $r ) {
			register_rest_route(
				Site_Bridge::REST_NS,
				$path,
				array(
					'methods'             => $r[0],
					'callback'            => array( __CLASS__, $r[1] ),
					'permission_callback' => '__return_true', // Device signature checked in each callback.
				)
			);
		}
	}

	/** Whether ECDSA P-256 can be checked here (PHP openssl). */
	public static function supported(): bool {
		return function_exists( 'openssl_verify' ) && function_exists( 'openssl_pkey_get_public' ) && defined( 'OPENSSL_KEYTYPE_EC' );
	}

	public static function enabled(): bool {
		return SBR_Hub::enabled() && (bool) SBR_Settings::v( 'app' ) && self::supported();
	}

	/* =====================================================================
	 * Devices and pairing codes
	 * =================================================================== */

	/** id => device. */
	public static function devices(): array {
		$d = get_option( self::DEVICES, array() );
		return is_array( $d ) ? $d : array();
	}

	private static function save_devices( array $d ): void {
		update_option( self::DEVICES, $d, false );
	}

	public static function revoke( string $id ): bool {
		$d = self::devices();
		if ( ! isset( $d[ $id ] ) ) {
			return false;
		}
		unset( $d[ $id ] );
		self::save_devices( $d );
		return true;
	}

	/** A new one-time pairing code (the previous one stops working). */
	public static function new_code(): string {
		$token = SBR_Crypto::token();
		$exp   = time() + self::CODE_TTL;
		update_option(
			self::PAIRING,
			array(
				'hash' => SBR_Crypto::hash( $token ),
				'exp'  => $exp,
				'user' => get_current_user_id(),
			),
			false
		);
		$payload = array(
			'u' => Site_Bridge::rest( '' ),
			't' => $token,
			'x' => $exp,
			'n' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);
		return 'sba1.' . SBR_Crypto::b64url( Site_Bridge::json( $payload ) );
	}

	/** A base64 DER SubjectPublicKeyInfo of an EC P-256 key. */
	public static function valid_key( string $b64 ): bool {
		if ( ! self::supported() || strlen( $b64 ) > 400 ) {
			return false;
		}
		$der = base64_decode( $b64, true );
		if ( false === $der || '' === $der ) {
			return false;
		}
		$key = openssl_pkey_get_public( self::pem( $der ) );
		if ( false === $key ) {
			return false;
		}
		$det = openssl_pkey_get_details( $key );
		return is_array( $det ) && OPENSSL_KEYTYPE_EC === ( $det['type'] ?? null ) && 'prime256v1' === ( $det['ec']['curve_name'] ?? '' );
	}

	private static function pem( string $der ): string {
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	/** SHA256withECDSA (DER) check against a base64 DER P-256 public key. */
	public static function verify( string $msg, string $sig_b64, string $key_b64 ): bool {
		$sig = base64_decode( $sig_b64, true );
		$der = base64_decode( $key_b64, true );
		if ( false === $sig || false === $der || '' === $sig || strlen( $sig ) > 80 ) {
			return false;
		}
		$key = openssl_pkey_get_public( self::pem( $der ) );
		return false !== $key && 1 === openssl_verify( $msg, $sig, $key, OPENSSL_ALGO_SHA256 );
	}

	/* =====================================================================
	 * Routes
	 * =================================================================== */

	public static function pair( WP_REST_Request $req ): WP_REST_Response {
		if ( ! self::enabled() ) {
			return self::off();
		}
		if ( ! SBR_Store::hit( 'app_pair|' . Site_Bridge::ip(), 10, 50 ) ) {
			return self::fail( 429, 'rate', 'Too many pairing attempts; wait a minute.' );
		}
		$body = (string) $req->get_body();
		$data = json_decode( $body, true );
		$p    = get_option( self::PAIRING, array() );
		$tok  = is_array( $data ) ? (string) ( $data['t'] ?? '' ) : '';
		if ( '' === $tok || ! is_array( $p ) || empty( $p['hash'] ) || ! hash_equals( (string) $p['hash'], SBR_Crypto::hash( $tok ) ) ) {
			self::audit( 'app_pair', 'denied', 'Unknown or used code', array() );
			return self::fail( 403, 'bad_code', 'The pairing code is wrong or was already used. Make a new one in the Hub\'s admin.' );
		}
		if ( (int) ( $p['exp'] ?? 0 ) < time() ) {
			delete_option( self::PAIRING );
			return self::fail( 403, 'expired', 'The pairing code expired. Make a new one in the Hub\'s admin.' );
		}
		$read = (string) ( $data['read'] ?? '' );
		$act  = (string) ( $data['act'] ?? '' );
		if ( $read === $act || ! self::valid_key( $read ) || ! self::valid_key( $act ) ) {
			return self::fail( 400, 'bad_keys', 'Two different EC P-256 public keys are needed.' );
		}
		if ( ! self::verify( 'SBA1|PAIR|' . $tok . '|' . hash( 'sha256', $body ), (string) $req->get_header( 'x-sba-sig' ), $read ) ) {
			return self::fail( 401, 'bad_signature', 'The pairing request was not signed with the device key.' );
		}
		$devices = self::devices();
		if ( count( $devices ) >= self::MAX_DEVICES ) {
			return self::fail( 403, 'too_many', 'This Hub already has the most devices it allows. Revoke one in the admin first.' );
		}
		$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 60 ) : substr( $name, 0, 60 );
		$name = '' === $name ? 'Android' : $name;
		$id   = bin2hex( random_bytes( 8 ) );

		delete_option( self::PAIRING ); // One use only.
		$devices[ $id ] = array(
			'id'        => $id,
			'name'      => $name,
			'read'      => $read,
			'act'       => $act,
			'user'      => (int) ( $p['user'] ?? 0 ),
			'created'   => time(),
			'last_used' => time(),
			'ip'        => Site_Bridge::ip(),
		);
		self::save_devices( $devices );
		self::audit( 'app_pair', 'ok', '', array( 'device' => $name ), $devices[ $id ] );

		return self::ok(
			array(
				'device'  => $id,
				'hub'     => array(
					'name'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					'url'    => home_url( '/' ),
					'key_id' => SBR_Hub::hub_id(),
				),
				'minutes' => SBR_Settings::WINDOW_MINUTES,
			)
		);
	}

	public static function sites( WP_REST_Request $req ): WP_REST_Response {
		$dev = self::auth( $req, 'read' );
		if ( $dev instanceof WP_REST_Response ) {
			return $dev;
		}
		$out = array();
		foreach ( SBR_Hub::sites() as $site ) {
			$row = self::site_row( $site, true );
			if ( $row ) {
				$out[] = $row;
			}
		}
		self::audit( 'app_sites', 'ok', '', array(), $dev );
		return self::ok(
			array(
				'sites'   => $out,
				'minutes' => SBR_Settings::WINDOW_MINUTES,
				'now'     => time(),
			)
		);
	}

	public static function window( WP_REST_Request $req ): WP_REST_Response {
		$data = json_decode( (string) $req->get_body(), true );
		$data = is_array( $data ) ? $data : array();
		$min  = (int) ( $data['minutes'] ?? -1 );
		// Opening needs the fingerprint key; closing shuts access, so read is enough.
		$dev = self::auth( $req, $min > 0 ? 'act' : 'read' );
		if ( $dev instanceof WP_REST_Response ) {
			return $dev;
		}
		if ( 0 !== $min && ! in_array( $min, SBR_Settings::WINDOW_MINUTES, true ) ) {
			return self::fail( 400, 'bad_minutes', 'Unknown window length.' );
		}
		$tool  = $min > 0 ? 'app_window_open' : 'app_window_close';
		$id    = sanitize_key( (string) ( $data['site'] ?? '' ) );
		$sites = SBR_Hub::sites();
		$row   = isset( $sites[ $id ] ) ? self::site_row( $sites[ $id ], false ) : null;
		if ( ! $row ) {
			self::audit( $tool, 'denied', 'Unknown site or level read', array( 'site' => $id ), $dev );
			return self::fail( 404, 'unknown_site', 'This site is not on the Hub, or its level is not write or admin.' );
		}
		if ( $min > 0 && ! $row['can_open'] ) {
			self::audit( $tool, 'denied', 'The site does not let the Hub open its window', array( 'site' => $id ), $dev );
			return self::fail( 403, 'remote_window', 'This site does not let the Hub open its write window (its Access tab).' );
		}
		$args = array(
			'site'    => $id,
			'minutes' => $min,
		);
		$r    = SBR_Hub::window( $sites[ $id ], $min );
		if ( is_wp_error( $r ) ) {
			self::audit( $tool, 'error', $r->get_error_message(), $args, $dev );
			return self::fail( 502, 'site', $r->get_error_message() );
		}
		self::audit( $tool, 'ok', '', $args, $dev );
		if ( $min > 0 && SBR_Settings::v( 'app_email' ) ) {
			self::mail_open( $dev, (string) $row['label'], (int) ( $r['window_until'] ?? 0 ) );
		}
		$fresh = self::site_row( array_merge( $sites[ $id ], array( 'status' => $r ) ), false );
		return self::ok(
			array(
				'site' => $fresh ? $fresh : $row,
				'now'  => time(),
			)
		);
	}

	public static function log( WP_REST_Request $req ): WP_REST_Response {
		$dev = self::auth( $req, 'read' );
		if ( $dev instanceof WP_REST_Response ) {
			return $dev;
		}
		$f    = array();
		$site = sanitize_key( (string) $req->get_param( 'site' ) );
		if ( '' !== $site ) {
			$f['site'] = $site;
		}
		$limit = min( self::LOG_MAX, max( 1, (int) ( $req->get_param( 'limit' ) ? $req->get_param( 'limit' ) : 50 ) ) );
		$rows  = array();
		foreach ( SBR_Store::log_rows( $f, $limit, 0 ) as $r ) {
			$args   = (string) $r['args'];
			$rows[] = array(
				'ts'      => (int) strtotime( (string) $r['ts'] . ' UTC' ),
				'side'    => (string) $r['side'],
				'site'    => (string) $r['site'],
				'tool'    => (string) $r['tool'],
				'class'   => (string) $r['class'],
				'status'  => (string) $r['status'],
				'message' => (string) $r['message'],
				'client'  => (string) $r['client'],
				'args'    => strlen( $args ) > 300 ? substr( $args, 0, 300 ) . '…' : $args,
			);
		}
		return self::ok( array( 'rows' => $rows ) );
	}

	public static function forget( WP_REST_Request $req ): WP_REST_Response {
		$dev = self::auth( $req, 'read' );
		if ( $dev instanceof WP_REST_Response ) {
			return $dev;
		}
		self::revoke( (string) $dev['id'] );
		self::audit( 'app_forget', 'ok', '', array(), $dev );
		return self::ok( array( 'forgotten' => true ) );
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	/**
	 * What the app shows for a site, or null when it is not shown (level
	 * read / off, or unknown). $fresh asks a remote site for its status.
	 */
	private static function site_row( array $site, bool $fresh ): ?array {
		$self  = ! empty( $site['self'] );
		$error = '';
		if ( $self ) {
			$st = SBR_Site::status();
		} elseif ( $fresh ) {
			$st = SBR_Hub::status( $site );
			if ( is_wp_error( $st ) ) {
				$error = $st->get_error_message();
				$st    = (array) ( $site['status'] ?? array() );
			}
		} else {
			$st = (array) ( $site['status'] ?? array() );
		}
		$level = (string) ( $st['level'] ?? '' );
		if ( ! in_array( $level, array( 'write', 'admin' ), true ) ) {
			return null;
		}
		$until = (int) ( $st['window_until'] ?? 0 );
		return array(
			'id'           => (string) $site['id'],
			'label'        => (string) $site['label'],
			'url'          => (string) ( $st['url'] ?? preg_replace( '#/wp-json/site-bridge/v1/?$#', '/', (string) $site['url'] ) ),
			'self'         => $self,
			'level'        => $level,
			'window_until' => $until > time() ? $until : 0,
			// The Hub's own site follows the Sites tab: the owner is here.
			'can_open'     => $self || ! empty( $st['remote_window'] ),
			'reachable'    => '' === $error,
			'error'        => $error,
		);
	}

	/**
	 * Checks the device signature. Returns the device, or the error
	 * response to send back.
	 *
	 * @return array|WP_REST_Response
	 */
	private static function auth( WP_REST_Request $req, string $need ) {
		if ( ! self::enabled() ) {
			return self::off();
		}
		if ( ! SBR_Store::hit( 'app_ip|' . Site_Bridge::ip(), 60, 5000 ) ) {
			return self::fail( 429, 'rate', 'Too many calls from this address; wait a minute.' );
		}
		$id    = (string) $req->get_header( 'x-sba-device' );
		$ts    = (string) $req->get_header( 'x-sba-ts' );
		$nonce = (string) $req->get_header( 'x-sba-nonce' );
		$key   = (string) $req->get_header( 'x-sba-key' );
		$sig   = (string) $req->get_header( 'x-sba-sig' );
		$devs  = self::devices();
		if ( ! preg_match( '/^[a-f0-9]{16}$/', $id ) || ! isset( $devs[ $id ] ) ) {
			self::audit( 'app_auth', 'denied', 'Unknown device', array() );
			return self::fail( 401, 'unknown_device', 'This device is not paired with the Hub (or was revoked). Pair it again.' );
		}
		$dev = $devs[ $id ];
		if ( ! ctype_digit( $ts ) || abs( time() - (int) $ts ) > SBR_Site::SKEW ) {
			return self::fail( 401, 'clock', 'The phone\'s clock is too far from the Hub\'s.', array( 'now' => time() ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $nonce ) || ! in_array( $key, array( 'read', 'act' ), true ) ) {
			return self::fail( 401, 'bad_headers', 'Missing or invalid signature headers.' );
		}
		if ( 'act' === $need && 'act' !== $key ) {
			self::audit( 'app_auth', 'denied', 'Needs the fingerprint key', array(), $dev );
			return self::fail( 403, 'need_act', 'This needs the fingerprint key.' );
		}
		$msg = 'SBA1|' . $req->get_method() . '|' . $req->get_route() . '|' . $id . '|' . $ts . '|' . $nonce . '|' . hash( 'sha256', (string) $req->get_body() );
		if ( ! self::verify( $msg, $sig, (string) $dev[ $key ] ) ) {
			self::audit( 'app_auth', 'denied', 'Bad signature', array(), $dev );
			return self::fail( 401, 'bad_signature', 'The request was not signed by this device.' );
		}
		$seen = 'sbr_app_n_' . md5( $id . '|' . $nonce );
		if ( get_transient( $seen ) ) {
			return self::fail( 401, 'replay', 'Replayed request.' );
		}
		set_transient( $seen, 1, 2 * SBR_Site::SKEW );
		if ( ! SBR_Store::hit( 'app|' . $id, 30, 2000 ) ) {
			return self::fail( 429, 'rate', 'Too many calls from this device; wait a minute.' );
		}
		$devs[ $id ]['last_used'] = time();
		$devs[ $id ]['ip']        = Site_Bridge::ip();
		self::save_devices( $devs );
		return $devs[ $id ];
	}

	private static function ok( array $data ): WP_REST_Response {
		return Site_Bridge::raw( 200, Site_Bridge::json( array( 'ok' => true ) + $data ) );
	}

	private static function fail( int $status, string $code, string $message, array $extra = array() ): WP_REST_Response {
		return Site_Bridge::raw(
			$status,
			Site_Bridge::json(
				array(
					'ok'      => false,
					'error'   => $code,
					'message' => $message,
				) + $extra
			)
		);
	}

	private static function off(): WP_REST_Response {
		return self::fail( 404, 'app_off', 'The mobile app is off on this Hub (Site Bridge → App).' );
	}

	/** One row in the Hub's log. */
	private static function audit( string $tool, string $status, string $message, array $args, array $dev = array() ): void {
		$site = isset( $args['site'] ) ? (string) $args['site'] : '';
		unset( $args['site'] );
		SBR_Store::log(
			array(
				'side'    => 'hub',
				'client'  => 'app: ' . ( $dev ? (string) $dev['name'] : '?' ),
				'site'    => $site,
				'tool'    => $tool,
				'class'   => 'app_window_open' === $tool ? 'admin' : ( 'app_window_close' === $tool ? 'write' : 'read' ),
				'args'    => $args,
				'status'  => $status,
				'message' => $message,
			)
		);
	}

	private static function mail_open( array $dev, string $label, int $until ): void {
		$subject = sprintf( '[%s] Site Bridge: write window opened from the app', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$body    = sprintf(
			"The write window of %1\$s was opened from the Site Bridge app.\n\nDevice: %2\$s\nOpen until (UTC): %3\$s\nIP: %4\$s\n\nIf this was not you, revoke the device: %5\$s\n",
			$label,
			(string) $dev['name'],
			$until > 0 ? gmdate( 'Y-m-d H:i', $until ) : '—',
			Site_Bridge::ip(),
			admin_url( 'admin.php?page=sbr-settings&tab=app' )
		);
		wp_mail( get_option( 'admin_email' ), $subject, $body );
	}
}

<?php
/**
 * SBR_Site — the Site role.
 *
 * REST routes (namespace site-bridge/v1):
 *  - POST /site  signed requests from the paired Hub only: op "tool",
 *                "status", "window" (only when the site allows the Hub to
 *                open it) and "unpair". Every answer is signed by this
 *                site, so the Hub knows who answered.
 *  - POST /pair  one-time pairing with a code made in this site's admin.
 *
 * Request signature (Ed25519, the Hub's key):
 *   SBR1|REQ|<hub id>|<site id>|<unix time>|<nonce>|<sha256 hex of body>
 * Answer signature (this site's key):
 *   SBR1|RESP|<request nonce>|<unix time>|<sha256 hex of body>
 * Time window ±300 s; a nonce is accepted once (transients, 10 minutes).
 *
 * execute() is the single gate every tool call passes, local or remote:
 * site role on, level (off < read < write < admin), open write window for
 * write and admin, the connection's scope, rate limit, "run as" user.
 */

defined( 'ABSPATH' ) || exit;

final class SBR_Site {

	const TRUST   = 'sbr_hub_trust';
	const PAIRING = 'sbr_pairing';
	const SKEW    = 300;

	const RANK = array(
		'off'   => 0,
		'read'  => 1,
		'write' => 2,
		'admin' => 3,
	);

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			Site_Bridge::REST_NS,
			'/site',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // Signature checked in handle().
			)
		);
		register_rest_route(
			Site_Bridge::REST_NS,
			'/pair',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'pair' ),
				'permission_callback' => '__return_true', // One-time code checked in pair().
			)
		);
	}

	public static function enabled(): bool {
		return (bool) SBR_Settings::v( 'site' ) && ! Site_Bridge::killed();
	}

	/** The paired Hub, or null. */
	public static function trust(): ?array {
		$t = get_option( self::TRUST, array() );
		return is_array( $t ) && ! empty( $t['hub_pub'] ) && ! empty( $t['site_id'] ) ? $t : null;
	}

	/**
	 * Abilities that may run without an open write window, when the owner
	 * ticks "nowin" in Access (off by default). Each one only adds an item
	 * that waits for the owner on the site; level "write" and the "write"
	 * scope are still needed.
	 */
	const NO_WINDOW = array( 'broadcast/queue-add' );

	/* =====================================================================
	 * The gate
	 * =================================================================== */

	/**
	 * Runs a tool in this site after every check.
	 *
	 * @param array $ctx grant_id, client, scopes (list), site_id, via (hub|local).
	 * @return array array( 'ok' => true, 'result' => … ) or array( 'ok' => false, 'error' => array( code, message ) ).
	 */
	public static function execute( string $tool, array $args, array $ctx ): array {
		$start = microtime( true );
		$def   = SBR_Tools::def( $tool );
		$class = $def ? $def['class'] : '';
		if ( 'ability_run' === $tool ) {
			$class = SBR_Tools_Write::ability_class( $args );
		}
		$fail = self::check( $tool, $def, $class, $ctx, $args );
		if ( $fail ) {
			self::log( $tool, $class, $args, $ctx, 'denied', $fail[1], 0, $start );
			return array(
				'ok'    => false,
				'error' => array(
					'code'    => $fail[0],
					'message' => $fail[1],
				),
			);
		}

		$prev = get_current_user_id();
		wp_set_current_user( SBR_Settings::run_as_user()->ID );
		try {
			$res = SBR_Tools::run( $tool, $args );
		} catch ( \Throwable $e ) {
			$res = new WP_Error( 'sbr_exception', 'The tool failed: ' . $e->getMessage() );
		}
		wp_set_current_user( $prev );

		if ( is_wp_error( $res ) ) {
			self::log( $tool, $class, $args, $ctx, 'error', $res->get_error_message(), 0, $start );
			return array(
				'ok'    => false,
				'error' => array(
					'code'    => (string) $res->get_error_code(),
					'message' => $res->get_error_message(),
				),
			);
		}
		$extra = array();
		if ( is_array( $res ) && array_key_exists( '_log_extra', $res ) ) {
			$extra = (array) $res['_log_extra'];
			unset( $res['_log_extra'] );
		}
		$bytes = strlen( Site_Bridge::json( $res ) );
		self::log( $tool, $class, $args, $ctx, 'ok', '', $bytes, $start, $extra );
		if ( 'admin' === $class && SBR_Settings::v( 'email_admin' ) ) {
			self::mail_admin( $tool, $args, $ctx );
		}
		return array(
			'ok'     => true,
			'result' => $res,
		);
	}

	/** null when allowed, else array( code, message ). */
	private static function check( string $tool, ?array $def, string $class, array $ctx, array $args = array() ): ?array {
		if ( ! self::enabled() ) {
			return array( 'site_off', 'The Site role is off on this site.' );
		}
		if ( ! $def || ! empty( $def['hub'] ) ) {
			return array( 'unknown_tool', 'Unknown tool.' );
		}
		$level = (string) SBR_Settings::v( 'level' );
		if ( self::RANK[ $level ] < self::RANK[ $class ] ) {
			return array( 'level', sprintf( 'This site allows "%1$s" and this tool needs "%2$s". Only the owner can change the level, in WP admin → Noxpress → Site Bridge → Access.', $level, $class ) );
		}
		if ( 'read' !== $class && ! SBR_Settings::window_until() && ! self::no_window( $tool, $class, $args ) ) {
			return array( 'window_closed', 'The write window of this site is closed. Ask the owner to open it in WP admin → Noxpress → Site Bridge → Access (or from the Hub, if this site allows it), then try again.' );
		}
		$scopes = isset( $ctx['scopes'] ) && is_array( $ctx['scopes'] ) ? $ctx['scopes'] : array();
		if ( ! in_array( $class, $scopes, true ) ) {
			return array( 'scope', sprintf( 'This connection was approved without the "%s" scope. Connect again and tick it on the approval screen.', $class ) );
		}
		if ( ! SBR_Store::hit( 'site', 300, 100000 ) ) {
			return array( 'rate', 'Too many calls on this site right now; wait a minute.' );
		}
		if ( ! SBR_Settings::run_as_user() ) {
			return array( 'no_user', 'No administrator is set to run tools on this site (Access → "Run as").' );
		}
		return null;
	}

	/** True for a write ability the owner lets run without a write window. */
	public static function no_window( string $tool, string $class, array $args ): bool {
		return 'ability_run' === $tool && 'write' === $class && SBR_Settings::v( 'nowin' )
			&& in_array( SBR_Tools::a_str( $args, 'name' ), self::NO_WINDOW, true );
	}

	private static function log( string $tool, string $class, array $args, array $ctx, string $status, string $msg, int $bytes, float $start, array $extra = array() ): void {
		SBR_Store::log(
			array(
				'side'     => 'site',
				'grant_id' => (int) ( $ctx['grant_id'] ?? 0 ),
				'client'   => (string) ( $ctx['client'] ?? '' ),
				'site'     => (string) ( $ctx['site_id'] ?? '' ),
				'tool'     => $tool,
				'class'    => $class,
				'args'     => $args,
				'status'   => $status,
				'message'  => $msg,
				'bytes'    => $bytes,
				'ms'       => (int) round( 1000 * ( microtime( true ) - $start ) ),
				'extra'    => $extra,
			)
		);
	}

	private static function mail_admin( string $tool, array $args, array $ctx ): void {
		$subject = sprintf( '[%s] Site Bridge: %s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $tool );
		$body    = sprintf(
			"Site Bridge ran an admin tool on %1\$s.\n\nTool: %2\$s\nArguments: %3\$s\nConnection: %4\$s (#%5\$d)\nTime (UTC): %6\$s\n\nHistory: %7\$s\n",
			home_url( '/' ),
			$tool,
			Site_Bridge::json( SBR_Guard::redact_args( $args ) ),
			(string) ( $ctx['client'] ?? '' ),
			(int) ( $ctx['grant_id'] ?? 0 ),
			gmdate( 'Y-m-d H:i:s' ),
			admin_url( 'admin.php?page=sbr-settings&tab=log' )
		);
		wp_mail( get_option( 'admin_email' ), $subject, $body );
	}

	/** What the Hub shows for this site. */
	public static function status(): array {
		return array(
			'name'          => get_bloginfo( 'name' ),
			'url'           => home_url( '/' ),
			'version'       => SBR_VERSION,
			'level'         => SBR_Settings::v( 'level' ),
			'window_until'  => SBR_Settings::window_until(),
			'remote_window' => (bool) SBR_Settings::v( 'remote_window' ),
			'woocommerce'   => function_exists( 'WC' ),
			// Plugins whose abilities the Hub's phone app can use (1.7.0).
			'modules'       => SBR_App::modules_here(),
		);
	}

	/* =====================================================================
	 * Signed endpoint
	 * =================================================================== */

	public static function handle( WP_REST_Request $req ): WP_REST_Response {
		if ( ! self::enabled() ) {
			return Site_Bridge::raw( 403, Site_Bridge::json( array( 'error' => 'site_off' ) ) );
		}
		if ( ! SBR_Guard::ip_allowed( Site_Bridge::ip(), SBR_Settings::lines( 'site_ips' ) ) ) {
			return Site_Bridge::raw( 403, Site_Bridge::json( array( 'error' => 'ip' ) ) );
		}
		$trust = self::trust();
		if ( ! $trust ) {
			return Site_Bridge::raw( 403, Site_Bridge::json( array( 'error' => 'not_paired' ) ) );
		}
		$body  = (string) $req->get_body();
		$hub   = (string) $req->get_header( 'x_sbr_hub' );
		$ts    = (string) $req->get_header( 'x_sbr_ts' );
		$nonce = (string) $req->get_header( 'x_sbr_nonce' );
		$sig   = (string) $req->get_header( 'x_sbr_sig' );
		if ( ! hash_equals( (string) $trust['hub_id'], $hub ) || ! ctype_digit( $ts ) || abs( time() - (int) $ts ) > self::SKEW || ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $nonce ) ) {
			return Site_Bridge::raw( 401, Site_Bridge::json( array( 'error' => 'bad_request_headers' ) ) );
		}
		$msg = 'SBR1|REQ|' . $hub . '|' . $trust['site_id'] . '|' . $ts . '|' . $nonce . '|' . hash( 'sha256', $body );
		if ( ! SBR_Crypto::verify( $msg, $sig, (string) $trust['hub_pub'] ) ) {
			return Site_Bridge::raw( 401, Site_Bridge::json( array( 'error' => 'bad_signature' ) ) );
		}
		$seen = 'sbr_n_' . md5( $nonce );
		if ( get_transient( $seen ) ) {
			return Site_Bridge::raw( 401, Site_Bridge::json( array( 'error' => 'replay' ) ) );
		}
		set_transient( $seen, 1, 2 * self::SKEW );

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || ( $data['site'] ?? '' ) !== $trust['site_id'] ) {
			return self::answer( $nonce, array( 'ok' => false, 'error' => array( 'code' => 'bad_body', 'message' => 'Invalid request body.' ) ) );
		}
		update_option( 'sbr_hub_seen', time(), false );

		$op = (string) ( $data['op'] ?? '' );
		switch ( $op ) {
			case 'tool':
				$ctx            = isset( $data['ctx'] ) && is_array( $data['ctx'] ) ? $data['ctx'] : array();
				$ctx['site_id'] = $trust['site_id'];
				$ctx['via']     = 'hub';
				$args           = isset( $data['args'] ) && is_array( $data['args'] ) ? $data['args'] : array();
				return self::answer( $nonce, self::execute( (string) ( $data['tool'] ?? '' ), $args, $ctx ) );
			case 'status':
				return self::answer( $nonce, array( 'ok' => true, 'result' => self::status() ) );
			case 'window':
				if ( ! SBR_Settings::v( 'remote_window' ) ) {
					return self::answer( $nonce, array( 'ok' => false, 'error' => array( 'code' => 'remote_window', 'message' => 'This site does not let the Hub open its write window.' ) ) );
				}
				$min = (int) ( $data['minutes'] ?? 0 );
				if ( $min <= 0 ) {
					SBR_Settings::close_window();
				} else {
					SBR_Settings::open_window( $min );
				}
				SBR_Store::log(
					array(
						'side'    => 'site',
						'site'    => $trust['site_id'],
						'tool'    => $min > 0 ? 'window_open' : 'window_close',
						'class'   => 'admin',
						'args'    => array( 'minutes' => $min ),
						'status'  => 'ok',
						'message' => 'From the Hub',
					)
				);
				return self::answer( $nonce, array( 'ok' => true, 'result' => self::status() ) );
			case 'unpair':
				delete_option( self::TRUST );
				return self::answer( $nonce, array( 'ok' => true, 'result' => array( 'unpaired' => true ) ) );
		}
		return self::answer( $nonce, array( 'ok' => false, 'error' => array( 'code' => 'bad_op', 'message' => 'Unknown operation.' ) ) );
	}

	/** A signed answer. */
	private static function answer( string $nonce, array $payload ): WP_REST_Response {
		$body = Site_Bridge::json( $payload );
		$ts   = (string) time();
		return Site_Bridge::raw(
			200,
			$body,
			array(
				'X-SBR-Ts'  => $ts,
				'X-SBR-Sig' => SBR_Crypto::sign( 'SBR1|RESP|' . $nonce . '|' . $ts . '|' . hash( 'sha256', $body ) ),
			)
		);
	}

	/* =====================================================================
	 * Pairing
	 * =================================================================== */

	/** A new one-time pairing code (15 minutes). */
	public static function new_code(): string {
		$token = SBR_Crypto::token();
		$exp   = time() + 15 * MINUTE_IN_SECONDS;
		update_option(
			self::PAIRING,
			array(
				'hash' => SBR_Crypto::hash( $token ),
				'exp'  => $exp,
			),
			false
		);
		$payload = array(
			'u' => Site_Bridge::rest( '' ),
			'k' => SBR_Crypto::public_b64(),
			't' => $token,
			'x' => $exp,
			'n' => get_bloginfo( 'name' ),
		);
		return 'sbr1.' . SBR_Crypto::b64url( Site_Bridge::json( $payload ) );
	}

	public static function pair( WP_REST_Request $req ): WP_REST_Response {
		if ( ! self::enabled() ) {
			return Site_Bridge::raw( 403, Site_Bridge::json( array( 'error' => 'site_off' ) ) );
		}
		if ( ! SBR_Store::hit( 'pair|' . Site_Bridge::ip(), 10, 50 ) ) {
			return Site_Bridge::raw( 429, Site_Bridge::json( array( 'error' => 'rate' ) ) );
		}
		$p    = get_option( self::PAIRING, array() );
		$body = (string) $req->get_body();
		$data = json_decode( $body, true );
		if ( ! is_array( $p ) || empty( $p['hash'] ) || (int) ( $p['exp'] ?? 0 ) < time() ) {
			return Site_Bridge::raw( 403, Site_Bridge::json( array( 'error' => 'no_pairing' ) ) );
		}
		if ( ! is_array( $data ) || ! hash_equals( (string) $p['hash'], SBR_Crypto::hash( (string) ( $data['token'] ?? '' ) ) ) ) {
			return Site_Bridge::raw( 403, Site_Bridge::json( array( 'error' => 'bad_code' ) ) );
		}
		$hub_pub = (string) ( $data['hub_pub'] ?? '' );
		$site_id = (string) ( $data['site_id'] ?? '' );
		$hub_id  = (string) ( $data['hub_id'] ?? '' );
		if ( ! SBR_Crypto::valid_pub( $hub_pub ) || ! preg_match( '/^[a-z0-9_-]{1,64}$/', $site_id ) || SBR_Crypto::key_id( $hub_pub ) !== $hub_id
			|| abs( time() - (int) ( $data['ts'] ?? 0 ) ) > self::SKEW
			|| ! SBR_Crypto::verify( 'SBR1|PAIR|' . hash( 'sha256', $body ), (string) $req->get_header( 'x_sbr_sig' ), $hub_pub ) ) {
			return Site_Bridge::raw( 403, Site_Bridge::json( array( 'error' => 'bad_pair_request' ) ) );
		}
		delete_option( self::PAIRING );
		update_option(
			self::TRUST,
			array(
				'hub_id'  => $hub_id,
				'hub_pub' => $hub_pub,
				'hub_url' => esc_url_raw( (string) ( $data['hub_url'] ?? '' ) ),
				'site_id' => $site_id,
				'paired'  => time(),
			),
			false
		);
		SBR_Store::log(
			array(
				'side'    => 'site',
				'site'    => $site_id,
				'tool'    => 'pair',
				'class'   => 'admin',
				'args'    => array( 'hub' => (string) ( $data['hub_url'] ?? '' ) ),
				'status'  => 'ok',
				'message' => 'Paired with Hub ' . $hub_id,
			)
		);
		$out = Site_Bridge::json(
			array(
				'ok'     => true,
				'status' => self::status(),
			)
		);
		return Site_Bridge::raw( 200, $out, array( 'X-SBR-Sig' => SBR_Crypto::sign( 'SBR1|PAIRED|' . hash( 'sha256', $out ) ) ) );
	}
}

<?php
/**
 * SBR_OAuth — the Hub's OAuth 2.1 authorization server, as Claude's
 * connectors expect it (authorization code + PKCE S256, public clients,
 * refresh token rotation, RFC 9728 / RFC 8414 metadata).
 *
 *  - Clients: Client ID Metadata Documents (CIMD) from the allowed
 *    prefixes in the settings (default claude.ai and claude.com). Dynamic
 *    Client Registration exists but is off by default.
 *  - Redirect URIs: https on a host of the allowed prefixes, or a loopback
 *    address (Claude Code), matched without the port.
 *  - Consent: a page in wp-admin (WordPress login, 2FA plugins apply),
 *    administrators only; the owner picks the scopes (read, write, admin)
 *    and the sites of this connection.
 *  - Tokens: random, stored only as sha256 in {prefix}sbr_grants. Access
 *    1 hour, refresh 30 days and rotated on every use, approval 90 days.
 *    A refresh token used twice ends the connection (theft signal), except
 *    within 60 seconds of a rotation (two parallel refreshes).
 *
 * Endpoints: REST routes under site-bridge/v1 (oauth-protected-resource,
 * oauth-authorization-server, authorize, token, register) plus the same
 * metadata at the /.well-known/ paths clients probe.
 */

defined( 'ABSPATH' ) || exit;

final class SBR_OAuth {

	const ACCESS_TTL  = 3600;
	const REFRESH_TTL = 2592000;   // 30 days.
	const CONSENT_TTL = 7776000;   // 90 days.
	const CODE_TTL    = 300;
	const REQ_TTL     = 900;
	const GRACE       = 60;
	const SCOPES      = array( 'read', 'write', 'admin' );
	const DCR_OPT     = 'sbr_dcr_clients';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'parse_request', array( __CLASS__, 'well_known' ), 0 );
	}

	public static function issuer(): string {
		return untrailingslashit( Site_Bridge::rest( '' ) );
	}

	public static function resource(): string {
		return Site_Bridge::rest( 'mcp' );
	}

	public static function routes(): void {
		$open = array( 'permission_callback' => '__return_true' ); // Public OAuth endpoints; each validates its input.
		register_rest_route( Site_Bridge::REST_NS, '/oauth-protected-resource', $open + array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_prm' ) ) );
		register_rest_route( Site_Bridge::REST_NS, '/oauth-authorization-server', $open + array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_asm' ) ) );
		register_rest_route( Site_Bridge::REST_NS, '/authorize', $open + array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'authorize' ) ) );
		register_rest_route( Site_Bridge::REST_NS, '/token', $open + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'token' ) ) );
		register_rest_route( Site_Bridge::REST_NS, '/register', $open + array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'register' ) ) );
	}

	/* =====================================================================
	 * Metadata
	 * =================================================================== */

	public static function prm(): array {
		return array(
			'resource'                 => self::resource(),
			'authorization_servers'    => array( self::issuer() ),
			'scopes_supported'         => self::SCOPES,
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => 'Site Bridge',
		);
	}

	public static function asm(): array {
		$m = array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => Site_Bridge::rest( 'authorize' ),
			'token_endpoint'                        => Site_Bridge::rest( 'token' ),
			'response_types_supported'              => array( 'code' ),
			'response_modes_supported'              => array( 'query' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'scopes_supported'                      => array_merge( self::SCOPES, array( 'offline_access' ) ),
			'client_id_metadata_document_supported' => true,
			'authorization_response_iss_parameter_supported' => true,
		);
		if ( SBR_Settings::v( 'dcr' ) ) {
			$m['registration_endpoint'] = Site_Bridge::rest( 'register' );
		}
		return $m;
	}

	private static function off(): ?WP_REST_Response {
		return SBR_Hub::enabled() ? null : Site_Bridge::raw( 404, Site_Bridge::json( array( 'error' => 'not_found' ) ) );
	}

	public static function rest_prm(): WP_REST_Response {
		return self::off() ?? Site_Bridge::raw( 200, Site_Bridge::json( self::prm() ) );
	}

	public static function rest_asm(): WP_REST_Response {
		return self::off() ?? Site_Bridge::raw( 200, Site_Bridge::json( self::asm() ) );
	}

	/** The /.well-known/ paths (RFC 9728, RFC 8414, OpenID discovery). */
	public static function well_known(): void {
		if ( ! SBR_Hub::enabled() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = (string) wp_parse_url( wp_unslash( (string) $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared below.
		if ( 0 !== strpos( $path, '/.well-known/' ) ) {
			return;
		}
		$res_path = (string) wp_parse_url( self::resource(), PHP_URL_PATH );
		$iss_path = (string) wp_parse_url( self::issuer(), PHP_URL_PATH );
		$data     = null;
		if ( in_array( $path, array( '/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource' . $res_path ), true ) ) {
			$data = self::prm();
		} elseif ( in_array( $path, array( '/.well-known/oauth-authorization-server', '/.well-known/oauth-authorization-server' . $iss_path, '/.well-known/openid-configuration', '/.well-known/openid-configuration' . $iss_path ), true ) ) {
			$data = self::asm();
		}
		if ( null === $data ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'Access-Control-Allow-Origin: *' );
		echo Site_Bridge::json( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON.
		exit;
	}

	/* =====================================================================
	 * Clients
	 * =================================================================== */

	/** Hosts of the allowed client prefixes. */
	private static function trusted_hosts(): array {
		$hosts = array();
		foreach ( SBR_Settings::lines( 'clients' ) as $p ) {
			$h = strtolower( (string) wp_parse_url( $p, PHP_URL_HOST ) );
			if ( '' !== $h ) {
				$hosts[] = $h;
			}
		}
		return $hosts;
	}

	/** Loopback redirect (Claude Code): http://localhost|127.0.0.1[:port]/path. */
	private static function loopback( string $uri ): bool {
		$p = wp_parse_url( $uri );
		return is_array( $p ) && 'http' === ( $p['scheme'] ?? '' ) && in_array( $p['host'] ?? '', array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true );
	}

	/** Whether a redirect URI is acceptable at all (before the client's list). */
	public static function redirect_policy( string $uri ): bool {
		if ( self::loopback( $uri ) ) {
			return true;
		}
		$p = wp_parse_url( $uri );
		return is_array( $p ) && 'https' === ( $p['scheme'] ?? '' ) && in_array( strtolower( $p['host'] ?? '' ), self::trusted_hosts(), true ) && ! isset( $p['fragment'] );
	}

	/** $uri equals one of $registered (loopback: any port). */
	private static function redirect_matches( string $uri, array $registered ): bool {
		foreach ( $registered as $r ) {
			$r = (string) $r;
			if ( $uri === $r ) {
				return true;
			}
			if ( self::loopback( $uri ) && self::loopback( $r ) ) {
				$a = wp_parse_url( $uri );
				$b = wp_parse_url( $r );
				if ( ( $a['host'] ?? '' ) === ( $b['host'] ?? '' ) && ( $a['path'] ?? '/' ) === ( $b['path'] ?? '/' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * The client for a client_id: array( id, name, redirect_uris ) or
	 * WP_Error. CIMD documents are cached for an hour.
	 */
	public static function client( string $client_id ) {
		if ( 0 === strpos( $client_id, 'sbr_dcr_' ) ) {
			$all = get_option( self::DCR_OPT, array() );
			if ( SBR_Settings::v( 'dcr' ) && is_array( $all ) && isset( $all[ $client_id ] ) ) {
				return $all[ $client_id ];
			}
			return new WP_Error( 'invalid_client', 'Unknown client.' );
		}
		$allowed = false;
		foreach ( SBR_Settings::lines( 'clients' ) as $prefix ) {
			if ( 0 === strpos( $client_id, $prefix ) ) {
				$allowed = true;
			}
		}
		if ( ! $allowed || ! wp_http_validate_url( $client_id ) ) {
			return new WP_Error( 'invalid_client', 'This client is not allowed by the Hub (Connections → allowed clients).' );
		}
		$key    = 'sbr_cimd_' . md5( $client_id );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$res = wp_safe_remote_get(
			$client_id,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return new WP_Error( 'invalid_client', 'The client metadata document could not be read.' );
		}
		$doc = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $doc ) || ( $doc['client_id'] ?? '' ) !== $client_id || empty( $doc['redirect_uris'] ) || ! is_array( $doc['redirect_uris'] ) ) {
			return new WP_Error( 'invalid_client', 'The client metadata document is invalid.' );
		}
		$client = array(
			'id'            => $client_id,
			'name'          => sanitize_text_field( (string) ( $doc['client_name'] ?? wp_parse_url( $client_id, PHP_URL_HOST ) ) ),
			'redirect_uris' => array_values( array_filter( array_map( 'strval', $doc['redirect_uris'] ) ) ),
		);
		set_transient( $key, $client, HOUR_IN_SECONDS );
		return $client;
	}

	/* =====================================================================
	 * Authorization
	 * =================================================================== */

	/** GET /authorize: validates, stores the request, sends to the consent page. */
	public static function authorize( WP_REST_Request $req ) {
		$off = self::off();
		if ( $off ) {
			return $off;
		}
		$p = array();
		foreach ( array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource' ) as $k ) {
			$v       = $req->get_param( $k );
			$p[ $k ] = is_scalar( $v ) ? (string) $v : '';
		}
		$client = self::client( $p['client_id'] );
		if ( is_wp_error( $client ) ) {
			return self::html_error( $client->get_error_message() );
		}
		if ( ! self::redirect_policy( $p['redirect_uri'] ) || ! self::redirect_matches( $p['redirect_uri'], $client['redirect_uris'] ) ) {
			return self::html_error( 'The redirect address is not registered for this client or not allowed by the Hub.' );
		}
		// From here on errors go back to the client.
		if ( 'code' !== $p['response_type'] ) {
			return self::back( $p['redirect_uri'], array( 'error' => 'unsupported_response_type', 'state' => $p['state'] ) );
		}
		if ( 'S256' !== $p['code_challenge_method'] || ! preg_match( '/^[A-Za-z0-9_-]{43,128}$/', $p['code_challenge'] ) ) {
			return self::back( $p['redirect_uri'], array( 'error' => 'invalid_request', 'error_description' => 'PKCE S256 is required.', 'state' => $p['state'] ) );
		}
		if ( '' !== $p['resource'] && untrailingslashit( $p['resource'] ) !== untrailingslashit( self::resource() ) ) {
			return self::back( $p['redirect_uri'], array( 'error' => 'invalid_target', 'state' => $p['state'] ) );
		}
		$scopes = array_values( array_intersect( self::SCOPES, preg_split( '/\s+/', $p['scope'] ) ) );
		$rid    = SBR_Crypto::token();
		set_transient(
			'sbr_ar_' . SBR_Crypto::hash( $rid ),
			array(
				'client_id'    => $client['id'],
				'client_name'  => $client['name'],
				'redirect_uri' => $p['redirect_uri'],
				'challenge'    => $p['code_challenge'],
				'state'        => $p['state'],
				'scopes'       => $scopes ? $scopes : array( 'read' ),
				'resource'     => $p['resource'],
			),
			self::REQ_TTL
		);
		$res = new WP_REST_Response( null, 302 );
		$res->header( 'Location', admin_url( 'admin.php?page=sbr-authorize&req=' . rawurlencode( $rid ) ) );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/** A stored authorization request, or null. */
	public static function request( string $rid ): ?array {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{20,80}$/', $rid ) ) {
			return null;
		}
		$r = get_transient( 'sbr_ar_' . SBR_Crypto::hash( $rid ) );
		return is_array( $r ) ? $r : null;
	}

	/** The owner approved: makes a code and returns the redirect URL. */
	public static function approve( string $rid, array $scopes, array $sites, int $user_id ): string {
		$r = self::request( $rid );
		delete_transient( 'sbr_ar_' . SBR_Crypto::hash( $rid ) );
		$scopes = array_values( array_intersect( self::SCOPES, $scopes ) );
		if ( ! in_array( 'read', $scopes, true ) ) {
			array_unshift( $scopes, 'read' );
		}
		$known = array_keys( SBR_Hub::sites() );
		$sites = array_values( array_intersect( array_merge( array( '*' ), $known ), $sites ) );
		$code  = SBR_Crypto::token();
		set_transient(
			'sbr_code_' . SBR_Crypto::hash( $code ),
			array(
				'client_id'    => $r['client_id'],
				'client_name'  => $r['client_name'],
				'redirect_uri' => $r['redirect_uri'],
				'challenge'    => $r['challenge'],
				'scope'        => implode( ' ', $scopes ),
				'sites'        => $sites,
				'user_id'      => $user_id,
				'resource'     => $r['resource'],
			),
			self::CODE_TTL
		);
		return self::back_url(
			$r['redirect_uri'],
			array(
				'code'  => $code,
				'state' => $r['state'],
			)
		);
	}

	/** The owner refused. */
	public static function deny( string $rid ): string {
		$r = self::request( $rid );
		delete_transient( 'sbr_ar_' . SBR_Crypto::hash( $rid ) );
		return self::back_url(
			$r['redirect_uri'],
			array(
				'error' => 'access_denied',
				'state' => $r['state'],
			)
		);
	}

	private static function back_url( string $uri, array $params ): string {
		$params['iss'] = self::issuer();
		if ( '' === ( $params['state'] ?? '' ) ) {
			unset( $params['state'] );
		}
		return $uri . ( false === strpos( $uri, '?' ) ? '?' : '&' ) . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	private static function back( string $uri, array $params ): WP_REST_Response {
		$res = new WP_REST_Response( null, 302 );
		$res->header( 'Location', self::back_url( $uri, $params ) );
		return $res;
	}

	private static function html_error( string $msg ): WP_REST_Response {
		$html = '<!doctype html><meta charset="utf-8"><title>Site Bridge</title><body style="font:15px/1.5 system-ui;background:#101218;color:#eae8fa;padding:40px"><h1 style="font-size:20px">Site Bridge</h1><p>' . esc_html( $msg ) . '</p></body>';
		return Site_Bridge::raw( 400, $html, array( 'Content-Type' => 'text/html; charset=utf-8' ) );
	}

	/* =====================================================================
	 * Token endpoint
	 * =================================================================== */

	private static function terr( string $error, string $desc = '', int $status = 400 ): WP_REST_Response {
		$b = array( 'error' => $error );
		if ( '' !== $desc ) {
			$b['error_description'] = $desc;
		}
		return Site_Bridge::raw( $status, Site_Bridge::json( $b ) );
	}

	private static function param( WP_REST_Request $req, string $k ): string {
		$body = $req->get_body_params();
		$json = $req->get_json_params();
		$v    = $body[ $k ] ?? ( is_array( $json ) ? ( $json[ $k ] ?? null ) : null );
		return is_scalar( $v ) ? (string) $v : '';
	}

	public static function token( WP_REST_Request $req ): WP_REST_Response {
		$off = self::off();
		if ( $off ) {
			return $off;
		}
		if ( ! SBR_Store::hit( 'token|' . Site_Bridge::ip(), 30, 2000 ) ) {
			return self::terr( 'slow_down', 'Too many token requests.', 429 );
		}
		$grant = self::param( $req, 'grant_type' );
		if ( 'authorization_code' === $grant ) {
			return self::token_code( $req );
		}
		if ( 'refresh_token' === $grant ) {
			return self::token_refresh( $req );
		}
		return self::terr( 'unsupported_grant_type' );
	}

	private static function token_code( WP_REST_Request $req ): WP_REST_Response {
		$code = self::param( $req, 'code' );
		$key  = 'sbr_code_' . SBR_Crypto::hash( $code );
		$c    = '' === $code ? false : get_transient( $key );
		if ( ! is_array( $c ) ) {
			return self::terr( 'invalid_grant', 'Unknown or expired code.' );
		}
		delete_transient( $key );
		$verifier = self::param( $req, 'code_verifier' );
		if ( self::param( $req, 'client_id' ) !== $c['client_id']
			|| self::param( $req, 'redirect_uri' ) !== $c['redirect_uri']
			|| ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier )
			|| ! hash_equals( (string) $c['challenge'], SBR_Crypto::b64url( hash( 'sha256', $verifier, true ) ) ) ) {
			return self::terr( 'invalid_grant', 'The code does not match the client, redirect or PKCE verifier.' );
		}
		$resource = self::param( $req, 'resource' );
		if ( '' !== $resource && untrailingslashit( $resource ) !== untrailingslashit( self::resource() ) ) {
			return self::terr( 'invalid_target' );
		}
		global $wpdb;
		$access  = SBR_Crypto::token( 'sbr_at_' );
		$refresh = SBR_Crypto::token( 'sbr_rt_' );
		$now     = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- our table.
		$wpdb->insert(
			SBR_Store::grants_table(),
			array(
				'client_id'    => substr( (string) $c['client_id'], 0, 255 ),
				'client_name'  => substr( (string) $c['client_name'], 0, 190 ),
				'user_id'      => (int) $c['user_id'],
				'scope'        => (string) $c['scope'],
				'sites'        => Site_Bridge::json( array_values( (array) $c['sites'] ) ),
				'access_hash'  => SBR_Crypto::hash( $access ),
				'access_exp'   => $now + self::ACCESS_TTL,
				'refresh_hash' => SBR_Crypto::hash( $refresh ),
				'refresh_exp'  => $now + self::REFRESH_TTL,
				'created'      => $now,
				'rotated_at'   => $now,
				'consent_exp'  => $now + self::CONSENT_TTL,
				'last_ip'      => substr( Site_Bridge::ip(), 0, 45 ),
			)
		);
		SBR_Store::log(
			array(
				'side'     => 'hub',
				'grant_id' => (int) $wpdb->insert_id,
				'client'   => (string) $c['client_name'],
				'tool'     => 'connect',
				'class'    => 'admin',
				'args'     => array(
					'scope' => $c['scope'],
					'sites' => $c['sites'],
				),
				'status'   => 'ok',
				'message'  => 'New connection approved by user #' . (int) $c['user_id'],
			)
		);
		return self::tokens( $access, $refresh, (string) $c['scope'] );
	}

	private static function token_refresh( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$rt = self::param( $req, 'refresh_token' );
		if ( '' === $rt ) {
			return self::terr( 'invalid_request', 'refresh_token is missing.' );
		}
		$h     = SBR_Crypto::hash( $rt );
		$table = SBR_Store::grants_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our table.
		$g = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE refresh_hash = %s LIMIT 1", $h ), ARRAY_A );
		if ( ! $g ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our table.
			$old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE prev_refresh_hash = %s LIMIT 1", $h ), ARRAY_A );
			if ( $old && (int) $old['rotated_at'] < time() - self::GRACE && ! (int) $old['revoked'] ) {
				self::revoke( (int) $old['id'], 'Refresh token reused: possible theft, connection ended.' );
			}
			return self::terr( 'invalid_grant', 'Unknown refresh token.' );
		}
		$now = time();
		if ( (int) $g['revoked'] || (int) $g['refresh_exp'] < $now || (int) $g['consent_exp'] < $now ) {
			return self::terr( 'invalid_grant', 'The connection ended. Connect again from Claude.' );
		}
		if ( self::param( $req, 'client_id' ) !== $g['client_id'] ) {
			return self::terr( 'invalid_grant', 'Wrong client.' );
		}
		$access  = SBR_Crypto::token( 'sbr_at_' );
		$refresh = SBR_Crypto::token( 'sbr_rt_' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- our table.
		$wpdb->update(
			$table,
			array(
				'access_hash'       => SBR_Crypto::hash( $access ),
				'access_exp'        => $now + self::ACCESS_TTL,
				'refresh_hash'      => SBR_Crypto::hash( $refresh ),
				'refresh_exp'       => min( $now + self::REFRESH_TTL, (int) $g['consent_exp'] ),
				'prev_refresh_hash' => $h,
				'rotated_at'        => $now,
			),
			array(
				'id'           => (int) $g['id'],
				'refresh_hash' => $h,
			)
		);
		if ( 1 !== (int) $wpdb->rows_affected ) {
			return self::terr( 'invalid_grant', 'The refresh token was just used.' );
		}
		return self::tokens( $access, $refresh, (string) $g['scope'] );
	}

	private static function tokens( string $access, string $refresh, string $scope ): WP_REST_Response {
		return Site_Bridge::raw(
			200,
			Site_Bridge::json(
				array(
					'access_token'  => $access,
					'token_type'    => 'Bearer',
					'expires_in'    => self::ACCESS_TTL,
					'refresh_token' => $refresh,
					'scope'         => $scope,
				)
			),
			array( 'Pragma' => 'no-cache' )
		);
	}

	/* =====================================================================
	 * Dynamic Client Registration (off by default)
	 * =================================================================== */

	public static function register( WP_REST_Request $req ): WP_REST_Response {
		if ( ! SBR_Hub::enabled() || ! SBR_Settings::v( 'dcr' ) ) {
			return self::terr( 'invalid_request', 'Registration is off on this Hub.', 404 );
		}
		if ( ! SBR_Store::hit( 'dcr|' . Site_Bridge::ip(), 10, 100 ) ) {
			return self::terr( 'slow_down', '', 429 );
		}
		$d    = $req->get_json_params();
		$uris = is_array( $d ) && isset( $d['redirect_uris'] ) && is_array( $d['redirect_uris'] ) ? array_values( array_filter( array_map( 'strval', $d['redirect_uris'] ) ) ) : array();
		if ( ! $uris || count( $uris ) > 5 ) {
			return self::terr( 'invalid_redirect_uri' );
		}
		foreach ( $uris as $u ) {
			if ( ! self::redirect_policy( $u ) ) {
				return self::terr( 'invalid_redirect_uri', 'Only Claude\'s callback or a loopback address.' );
			}
		}
		$id     = SBR_Crypto::token( 'sbr_dcr_' );
		$client = array(
			'id'            => $id,
			'name'          => sanitize_text_field( (string) ( $d['client_name'] ?? 'Client' ) ),
			'redirect_uris' => $uris,
			'created'       => time(),
		);
		$all        = get_option( self::DCR_OPT, array() );
		$all        = is_array( $all ) ? $all : array();
		$all[ $id ] = $client;
		update_option( self::DCR_OPT, array_slice( $all, -20, null, true ), false );
		return Site_Bridge::raw(
			201,
			Site_Bridge::json(
				array(
					'client_id'                  => $id,
					'client_id_issued_at'        => time(),
					'client_name'                => $client['name'],
					'redirect_uris'              => $uris,
					'token_endpoint_auth_method' => 'none',
					'grant_types'                => array( 'authorization_code', 'refresh_token' ),
					'response_types'             => array( 'code' ),
				)
			)
		);
	}

	/* =====================================================================
	 * Bearer tokens and connections
	 * =================================================================== */

	/** The connection of an access token, or null. */
	public static function grant_for( string $access ): ?array {
		global $wpdb;
		if ( 0 !== strpos( $access, 'sbr_at_' ) ) {
			return null;
		}
		$table = SBR_Store::grants_table();
		$now   = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our table.
		$g = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE access_hash = %s AND revoked = 0 AND access_exp > %d AND consent_exp > %d LIMIT 1", SBR_Crypto::hash( $access ), $now, $now ), ARRAY_A );
		if ( ! $g ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our table.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET uses = uses + 1, last_used = %d, last_ip = %s WHERE id = %d", $now, substr( Site_Bridge::ip(), 0, 45 ), (int) $g['id'] ) );
		return $g;
	}

	public static function grants( bool $active_only = false ): array {
		global $wpdb;
		$table = SBR_Store::grants_table();
		$where = $active_only ? $wpdb->prepare( ' WHERE revoked = 0 AND consent_exp > %d AND refresh_exp > %d', time(), time() ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- our table, prepared above.
		$rows = $wpdb->get_results( "SELECT id, client_id, client_name, user_id, scope, sites, created, consent_exp, refresh_exp, last_used, last_ip, uses, revoked FROM {$table}{$where} ORDER BY id DESC LIMIT 200", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function revoke( int $id, string $why = 'Revoked from the admin.' ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- our table.
		$wpdb->update(
			SBR_Store::grants_table(),
			array(
				'revoked'      => 1,
				'access_hash'  => '',
				'refresh_hash' => '',
			),
			array( 'id' => $id )
		);
		SBR_Store::log(
			array(
				'side'     => 'hub',
				'grant_id' => $id,
				'tool'     => 'revoke',
				'class'    => 'admin',
				'status'   => 'ok',
				'message'  => $why,
			)
		);
	}

	public static function revoke_all(): int {
		$n = 0;
		foreach ( self::grants( true ) as $g ) {
			self::revoke( (int) $g['id'], 'Kill switch: every connection ended.' );
			++$n;
		}
		return $n;
	}
}

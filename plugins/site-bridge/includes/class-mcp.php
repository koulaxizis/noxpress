<?php
/**
 * SBR_MCP — the MCP endpoint of the Hub (Streamable HTTP, JSON answers).
 *
 * POST /wp-json/site-bridge/v1/mcp with one JSON-RPC message. No SSE and
 * no sessions: every request carries its Bearer token and gets a single
 * JSON answer, which works on any PHP host. GET and DELETE answer 405.
 * Notifications and client responses answer 202 without a body.
 *
 * Without a valid token: 401 with WWW-Authenticate pointing at the
 * protected resource metadata, which starts Claude's OAuth flow.
 *
 * Methods: initialize, ping, tools/list (only the tools the connection's
 * scopes allow), tools/call (SBR_Hub::call). A refused or failed tool
 * call is a result with isError, so the model reads why.
 *
 * GET /diag answers whether the Authorization header reached WordPress
 * (the overview's diagnostics).
 */

defined( 'ABSPATH' ) || exit;

final class SBR_MCP {

	const VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26' );

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			Site_Bridge::REST_NS,
			'/mcp',
			array(
				'methods'             => array( 'GET', 'POST', 'DELETE' ),
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // Bearer token checked in handle().
			)
		);
		register_rest_route(
			Site_Bridge::REST_NS,
			'/diag',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'diag' ),
				'permission_callback' => '__return_true', // Says only whether a header arrived.
			)
		);
	}

	public static function diag( WP_REST_Request $req ): WP_REST_Response {
		return Site_Bridge::raw(
			200,
			Site_Bridge::json(
				array(
					'authorization_header' => 'Bearer sbr-diag' === self::auth_header( $req ),
					'https'                => is_ssl(),
				)
			)
		);
	}

	private static function auth_header( WP_REST_Request $req ): string {
		$h = (string) $req->get_header( 'authorization' );
		if ( '' === $h && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$h = (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared as a token.
		}
		return trim( $h );
	}

	public static function handle( WP_REST_Request $req ): WP_REST_Response {
		if ( ! SBR_Hub::enabled() ) {
			return Site_Bridge::raw( 404, Site_Bridge::json( array( 'error' => 'not_found' ) ) );
		}
		if ( ! SBR_Guard::ip_allowed( Site_Bridge::ip(), SBR_Settings::lines( 'hub_ips' ) ) ) {
			return Site_Bridge::raw( 403, Site_Bridge::json( array( 'error' => 'ip' ) ) );
		}
		$origin = (string) $req->get_header( 'origin' );
		if ( '' !== $origin && ! self::origin_ok( $origin ) ) {
			return Site_Bridge::raw( 403, Site_Bridge::json( array( 'error' => 'origin' ) ) );
		}
		if ( 'POST' !== $req->get_method() ) {
			return Site_Bridge::raw( 405, '', array( 'Allow' => 'POST' ) );
		}

		$auth  = self::auth_header( $req );
		$token = 0 === stripos( $auth, 'Bearer ' ) ? trim( substr( $auth, 7 ) ) : '';
		$grant = '' === $token ? null : SBR_OAuth::grant_for( $token );
		if ( ! $grant ) {
			$www = 'Bearer resource_metadata="' . Site_Bridge::rest( 'oauth-protected-resource' ) . '", scope="read write admin"';
			if ( '' !== $token ) {
				$www .= ', error="invalid_token"';
			}
			return Site_Bridge::raw( 401, Site_Bridge::json( array( 'error' => 'unauthorized' ) ), array( 'WWW-Authenticate' => $www ) );
		}

		$msg = json_decode( (string) $req->get_body(), true );
		if ( ! is_array( $msg ) ) {
			return self::rpc_error( null, -32700, 'Parse error.', 400 );
		}
		if ( isset( $msg[0] ) ) {
			return self::rpc_error( null, -32600, 'Batches are not supported.', 400 );
		}
		if ( ! isset( $msg['method'] ) || ! array_key_exists( 'id', $msg ) ) {
			return Site_Bridge::raw( 202, '' );
		}
		$id     = $msg['id'];
		$params = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();
		switch ( (string) $msg['method'] ) {
			case 'initialize':
				$asked = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
				return self::rpc_result(
					$id,
					array(
						'protocolVersion' => in_array( $asked, self::VERSIONS, true ) ? $asked : self::VERSIONS[0],
						'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
						'serverInfo'      => array(
							'name'    => 'site-bridge',
							'title'   => 'Site Bridge',
							'version' => SBR_VERSION,
						),
						'instructions'    => self::instructions(),
					)
				);
			case 'ping':
				return self::rpc_result( $id, new stdClass() );
			case 'tools/list':
				return self::rpc_result( $id, array( 'tools' => self::tools_for( $grant ) ) );
			case 'tools/call':
				return self::call( $id, $grant, $params );
		}
		return self::rpc_error( $id, -32601, 'Method not found.' );
	}

	private static function origin_ok( string $origin ): bool {
		$host  = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
		$hosts = array( strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) );
		foreach ( SBR_Settings::lines( 'clients' ) as $p ) {
			$hosts[] = strtolower( (string) wp_parse_url( $p, PHP_URL_HOST ) );
		}
		return in_array( $host, $hosts, true );
	}

	private static function instructions(): string {
		return 'Site Bridge manages several WordPress / WooCommerce sites. Call list_sites first and pass its "site" id to every tool. '
			. 'Each site has a level set by its owner (off, read, write, admin); writes also need a write window that only the owner opens in WP admin. '
			. 'When a tool is refused, tell the user why and what they can change; never try to work around it. '
			. 'Prefer read tools; secrets come back as *** and personal data may be masked. Every call is recorded in the sites\' audit logs.';
	}

	/** The tools this connection's scopes allow. */
	private static function tools_for( array $grant ): array {
		$scopes = explode( ' ', (string) $grant['scope'] );
		$out    = array();
		foreach ( SBR_Tools::defs() as $name => $d ) {
			if ( in_array( $d['class'], $scopes, true ) || ( 'ability_run' === $name && in_array( 'read', $scopes, true ) ) ) {
				$out[] = SBR_Tools::mcp_def( $name, $d );
			}
		}
		return $out;
	}

	private static function call( $id, array $grant, array $params ): WP_REST_Response {
		$name = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';
		if ( ! SBR_Tools::def( $name ) ) {
			return self::rpc_error( $id, -32602, 'Unknown tool: ' . $name );
		}
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		$r    = SBR_Hub::call( $grant, $name, $args );
		if ( ! $r['ok'] ) {
			return self::rpc_result(
				$id,
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => (string) $r['error'],
						),
					),
					'isError' => true,
				)
			);
		}
		$text = Site_Bridge::json( $r['result'] );
		if ( strlen( $text ) > SBR_Tools::MAX_TEXT ) {
			$text = substr( $text, 0, SBR_Tools::MAX_TEXT ) . "\n…[truncated: the result had " . strlen( $text ) . ' bytes; ask for less, e.g. fewer rows, a smaller max_bytes or a narrower path]';
		}
		return self::rpc_result(
			$id,
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => $text,
					),
				),
				'isError' => false,
			)
		);
	}

	private static function rpc_result( $id, $result ): WP_REST_Response {
		return Site_Bridge::raw(
			200,
			Site_Bridge::json(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'result'  => $result,
				)
			)
		);
	}

	private static function rpc_error( $id, int $code, string $message, int $status = 200 ): WP_REST_Response {
		return Site_Bridge::raw(
			$status,
			Site_Bridge::json(
				array(
					'jsonrpc' => '2.0',
					'id'      => $id,
					'error'   => array(
						'code'    => $code,
						'message' => $message,
					),
				)
			)
		);
	}
}

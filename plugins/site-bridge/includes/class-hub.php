<?php
/**
 * SBR_Hub — the Hub role: the site registry and the dispatch of tool
 * calls.
 *
 * Sites live in the option sbr_sites (autoload off): id, label, REST base
 * URL, the site's public key, when it was added and last seen. When the
 * Site role is also on, this install is listed too ("self") and its calls
 * run in-process, through the same SBR_Site::execute() gate.
 *
 * Every remote call is signed with this install's key (see SBR_Site) and
 * the answer's signature is checked with the site's key from pairing. A
 * fleet call ("all") goes to the connection's sites one after the other,
 * within a total time budget.
 */

defined( 'ABSPATH' ) || exit;

final class SBR_Hub {

	const SITES   = 'sbr_sites';
	const TIMEOUT = 25;
	const BUDGET  = 55;

	public static function init(): void {
		// Nothing to hook: the MCP endpoint and the admin call in.
	}

	public static function enabled(): bool {
		return (bool) SBR_Settings::v( 'hub' ) && ! Site_Bridge::killed();
	}

	public static function hub_id(): string {
		return SBR_Crypto::key_id();
	}

	/** The id this install has as a site of its own Hub. */
	public static function self_id(): string {
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$id   = sanitize_key( str_replace( '.', '-', $host ) );
		$port = wp_parse_url( home_url( '/' ), PHP_URL_PORT );
		if ( $port ) {
			$id .= '-' . (int) $port;
		}
		return '' === $id ? 'this-site' : substr( $id, 0, 64 );
	}

	/** id => site, self first when the Site role is on. */
	public static function sites(): array {
		$out = array();
		if ( SBR_Settings::v( 'site' ) ) {
			$out[ self::self_id() ] = array(
				'id'    => self::self_id(),
				'label' => get_bloginfo( 'name' ),
				'url'   => Site_Bridge::rest( '' ),
				'self'  => true,
			);
		}
		$raw = get_option( self::SITES, array() );
		foreach ( is_array( $raw ) ? $raw : array() as $id => $s ) {
			if ( is_array( $s ) && ! isset( $out[ $id ] ) ) {
				$s['self']  = false;
				$out[ $id ] = $s;
			}
		}
		return $out;
	}

	private static function save_sites( array $sites ): void {
		$keep = array();
		foreach ( $sites as $id => $s ) {
			if ( empty( $s['self'] ) ) {
				unset( $s['self'] );
				$keep[ $id ] = $s;
			}
		}
		update_option( self::SITES, $keep, false );
	}

	/* =====================================================================
	 * Pairing from the Hub side
	 * =================================================================== */

	/** Decodes a pairing code, or WP_Error. */
	public static function decode_code( string $code ) {
		$code = trim( $code );
		if ( 0 !== strpos( $code, 'sbr1.' ) ) {
			return new WP_Error( 'sbr_code', __( 'Αυτός δεν είναι κωδικός σύζευξης του Site Bridge.', 'site-bridge' ) );
		}
		$json = SBR_Crypto::b64url_decode( substr( $code, 5 ) );
		$p    = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $p ) || empty( $p['u'] ) || empty( $p['k'] ) || empty( $p['t'] ) || ! SBR_Crypto::valid_pub( (string) $p['k'] ) ) {
			return new WP_Error( 'sbr_code', __( 'Ο κωδικός σύζευξης είναι κατεστραμμένος. Αντέγραψέ τον ξανά ολόκληρο.', 'site-bridge' ) );
		}
		if ( (int) ( $p['x'] ?? 0 ) < time() ) {
			return new WP_Error( 'sbr_code', __( 'Ο κωδικός σύζευξης έληξε. Φτιάξε νέο στο site.', 'site-bridge' ) );
		}
		$u = (string) $p['u'];
		if ( ! wp_http_validate_url( $u ) && ! self::local_ok( $u ) ) {
			return new WP_Error( 'sbr_code', __( 'Η διεύθυνση του site στον κωδικό δεν είναι έγκυρη.', 'site-bridge' ) );
		}
		if ( 0 !== strpos( $u, 'https://' ) && ! self::local_ok( $u ) ) {
			return new WP_Error( 'sbr_code', __( 'Το site πρέπει να έχει HTTPS.', 'site-bridge' ) );
		}
		return $p;
	}

	/** Plain-HTTP local addresses are accepted only on local test installs. */
	private static function local_ok( string $u ): bool {
		$host = (string) wp_parse_url( $u, PHP_URL_HOST );
		return 'local' === wp_get_environment_type() && in_array( $host, array( '127.0.0.1', 'localhost' ), true );
	}

	/** Adds a site from its pairing code. Returns the new id or WP_Error. */
	public static function add_site( string $code, string $label ) {
		$p = self::decode_code( $code );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$sites = self::sites();
		foreach ( $sites as $s ) {
			if ( ( $s['url'] ?? '' ) === $p['u'] ) {
				return new WP_Error( 'sbr_code', __( 'Αυτό το site υπάρχει ήδη στη λίστα. Αφαίρεσέ το πρώτα αν θέλεις νέα σύζευξη.', 'site-bridge' ) );
			}
		}
		$label = '' !== trim( $label ) ? sanitize_text_field( $label ) : sanitize_text_field( (string) ( $p['n'] ?? '' ) );
		$base  = sanitize_key( str_replace( array( '.', ' ' ), '-', remove_accents( '' !== $label ? $label : (string) wp_parse_url( $p['u'], PHP_URL_HOST ) ) ) );
		$base  = '' === $base ? 'site' : substr( $base, 0, 56 );
		$id    = $base;
		$n     = 2;
		while ( isset( $sites[ $id ] ) ) {
			$id = $base . '-' . $n++;
		}

		$body = Site_Bridge::json(
			array(
				'token'   => (string) $p['t'],
				'hub_id'  => self::hub_id(),
				'hub_pub' => SBR_Crypto::public_b64(),
				'hub_url' => home_url( '/' ),
				'site_id' => $id,
				'ts'      => time(),
			)
		);
		$res  = wp_remote_post(
			trailingslashit( (string) $p['u'] ) . 'pair',
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-SBR-Sig'    => SBR_Crypto::sign( 'SBR1|PAIR|' . hash( 'sha256', $body ) ),
				),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $res ) ) {
			/* translators: %s: error message */
			return new WP_Error( 'sbr_pair', sprintf( __( 'Το site δεν απάντησε: %s', 'site-bridge' ), $res->get_error_message() ) );
		}
		$out = (string) wp_remote_retrieve_body( $res );
		$sig = (string) wp_remote_retrieve_header( $res, 'x-sbr-sig' );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) || ! SBR_Crypto::verify( 'SBR1|PAIRED|' . hash( 'sha256', $out ), $sig, (string) $p['k'] ) ) {
			$err = json_decode( $out, true );
			/* translators: %s: error code from the site */
			return new WP_Error( 'sbr_pair', sprintf( __( 'Η σύζευξη απέτυχε (%s). Έλεγξε ότι ο ρόλος Site είναι ενεργός στο site και ότι ο κωδικός δεν χρησιμοποιήθηκε ή έληξε.', 'site-bridge' ), is_array( $err ) && isset( $err['error'] ) ? (string) $err['error'] : 'HTTP ' . (int) wp_remote_retrieve_response_code( $res ) ) );
		}
		$data              = json_decode( $out, true );
		$sites[ $id ]      = array(
			'id'        => $id,
			'label'     => '' !== $label ? $label : $id,
			'url'       => (string) $p['u'],
			'pub'       => (string) $p['k'],
			'added'     => time(),
			'last_seen' => time(),
			'status'    => is_array( $data ) && isset( $data['status'] ) ? $data['status'] : array(),
		);
		self::save_sites( $sites );
		return $id;
	}

	/** Removes a site (and asks it to forget this Hub). */
	public static function remove_site( string $id ): bool {
		$sites = self::sites();
		if ( ! isset( $sites[ $id ] ) || ! empty( $sites[ $id ]['self'] ) ) {
			return false;
		}
		self::send( $sites[ $id ], array( 'op' => 'unpair' ), 8 );
		unset( $sites[ $id ] );
		self::save_sites( $sites );
		return true;
	}

	/* =====================================================================
	 * Signed requests
	 * =================================================================== */

	/**
	 * One signed request to a remote site. Returns the decoded, verified
	 * answer (array with ok / result / error) or WP_Error.
	 */
	public static function send( array $site, array $payload, int $timeout = self::TIMEOUT ) {
		$payload['v']    = 1;
		$payload['site'] = $site['id'];
		$body            = Site_Bridge::json( $payload );
		$ts              = (string) time();
		$nonce           = SBR_Crypto::token();
		$msg             = 'SBR1|REQ|' . self::hub_id() . '|' . $site['id'] . '|' . $ts . '|' . $nonce . '|' . hash( 'sha256', $body );
		$res             = wp_remote_post(
			trailingslashit( (string) $site['url'] ) . 'site',
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'X-SBR-Hub'    => self::hub_id(),
					'X-SBR-Ts'     => $ts,
					'X-SBR-Nonce'  => $nonce,
					'X-SBR-Sig'    => SBR_Crypto::sign( $msg ),
				),
				'body'        => $body,
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'sbr_unreachable', 'The site did not answer: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$out  = (string) wp_remote_retrieve_body( $res );
		if ( 200 !== $code ) {
			$err = json_decode( $out, true );
			$why = is_array( $err ) && isset( $err['error'] ) ? (string) $err['error'] : 'HTTP ' . $code;
			$map = array(
				'site_off'      => 'the Site role is off there',
				'not_paired'    => 'the site is not paired with this Hub any more',
				'bad_signature' => 'the site rejected this Hub\'s signature (pair it again)',
				'ip'            => 'the site\'s IP list does not include this Hub',
				'replay'        => 'replayed request',
			);
			return new WP_Error( 'sbr_refused', 'The site refused the request: ' . ( $map[ $why ] ?? $why ) . '.' );
		}
		$rts = (string) wp_remote_retrieve_header( $res, 'x-sbr-ts' );
		$sig = (string) wp_remote_retrieve_header( $res, 'x-sbr-sig' );
		if ( ! ctype_digit( $rts ) || abs( time() - (int) $rts ) > SBR_Site::SKEW || ! SBR_Crypto::verify( 'SBR1|RESP|' . $nonce . '|' . $rts . '|' . hash( 'sha256', $out ), $sig, (string) $site['pub'] ) ) {
			return new WP_Error( 'sbr_bad_answer', 'The answer was not signed by the paired site; it was ignored.' );
		}
		$data = json_decode( $out, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'sbr_bad_answer', 'The site sent an invalid answer.' );
		}
		self::touch( (string) $site['id'], 'status' === ( $payload['op'] ?? '' ) && ! empty( $data['ok'] ) ? (array) $data['result'] : null );
		return $data;
	}

	/** Updates last_seen (and the cached status) of a site. */
	private static function touch( string $id, ?array $status ): void {
		$raw = get_option( self::SITES, array() );
		if ( ! is_array( $raw ) || ! isset( $raw[ $id ] ) ) {
			return;
		}
		$raw[ $id ]['last_seen'] = time();
		if ( null !== $status ) {
			$raw[ $id ]['status'] = $status;
		}
		update_option( self::SITES, $raw, false );
	}

	/** Status of a site (self in-process). */
	public static function status( array $site ) {
		if ( ! empty( $site['self'] ) ) {
			return SBR_Site::status();
		}
		$r = self::send( $site, array( 'op' => 'status' ), 10 );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return ! empty( $r['ok'] ) ? (array) $r['result'] : new WP_Error( 'sbr_refused', (string) ( $r['error']['message'] ?? 'Error.' ) );
	}

	/** Opens (minutes > 0) or closes a site's write window from the Hub admin. */
	public static function window( array $site, int $minutes ) {
		if ( ! empty( $site['self'] ) ) {
			if ( $minutes > 0 ) {
				SBR_Settings::open_window( $minutes );
			} else {
				SBR_Settings::close_window();
			}
			return SBR_Site::status();
		}
		$r = self::send(
			$site,
			array(
				'op'      => 'window',
				'minutes' => $minutes,
			),
			10
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return ! empty( $r['ok'] ) ? (array) $r['result'] : new WP_Error( 'sbr_refused', (string) ( $r['error']['message'] ?? 'Error.' ) );
	}

	/* =====================================================================
	 * Tool calls from a connection
	 * =================================================================== */

	/** Whether a connection may reach a site. */
	public static function grant_allows( array $grant, string $id ): bool {
		$allowed = json_decode( (string) $grant['sites'], true );
		$allowed = is_array( $allowed ) ? $allowed : array();
		return in_array( '*', $allowed, true ) || in_array( $id, $allowed, true );
	}

	/**
	 * Runs a tool for a connection. Returns array( 'ok' => bool, 'result'
	 * or 'error' => message ) where result is the tool's result, or for a
	 * fleet call an object site id => result.
	 */
	public static function call( array $grant, string $tool, array $args ): array {
		$def = SBR_Tools::def( $tool );
		if ( ! $def ) {
			return array(
				'ok'    => false,
				'error' => 'Unknown tool.',
			);
		}
		$scopes = explode( ' ', (string) $grant['scope'] );
		// ability_run: the site decides its class; read is the least it needs.
		$need = 'ability_run' === $tool ? 'read' : $def['class'];
		if ( ! in_array( $need, $scopes, true ) ) {
			return array(
				'ok'    => false,
				'error' => sprintf( 'This connection was approved without the "%s" scope. Connect again and tick it on the approval screen.', $need ),
			);
		}
		if ( ! SBR_Store::hit( 'grant|' . (int) $grant['id'], (int) SBR_Settings::v( 'rate_min' ), (int) SBR_Settings::v( 'rate_day' ) ) ) {
			return array(
				'ok'    => false,
				'error' => 'This connection reached the Hub\'s rate limit (Connections → limits). Wait a minute.',
			);
		}
		if ( 'list_sites' === $tool ) {
			return array(
				'ok'     => true,
				'result' => self::list_for( $grant ),
			);
		}

		$sites = self::sites();
		$want  = isset( $args['site'] ) && is_scalar( $args['site'] ) ? (string) $args['site'] : '';
		unset( $args['site'] );
		if ( 'all' === $want ) {
			if ( empty( $def['fleet'] ) ) {
				return array(
					'ok'    => false,
					'error' => 'This tool runs on one site at a time.',
				);
			}
			$targets = array();
			foreach ( $sites as $id => $s ) {
				if ( self::grant_allows( $grant, $id ) ) {
					$targets[ $id ] = $s;
				}
			}
			if ( ! $targets ) {
				return array(
					'ok'    => false,
					'error' => 'This connection reaches no site.',
				);
			}
			$out   = array();
			$start = time();
			foreach ( $targets as $id => $s ) {
				if ( time() - $start > self::BUDGET ) {
					$out[ $id ] = array( 'error' => 'Skipped: the call ran out of time. Ask this site on its own.' );
					continue;
				}
				$r          = self::one( $grant, $s, $tool, $args, $def['class'] );
				$out[ $id ] = $r['ok'] ? $r['result'] : array( 'error' => $r['error'] );
			}
			return array(
				'ok'     => true,
				'result' => $out,
			);
		}
		if ( ! isset( $sites[ $want ] ) ) {
			return array(
				'ok'    => false,
				'error' => 'Unknown site. Call list_sites for the ids.',
			);
		}
		if ( ! self::grant_allows( $grant, $want ) ) {
			return array(
				'ok'    => false,
				'error' => 'This connection was approved without this site.',
			);
		}
		return self::one( $grant, $sites[ $want ], $tool, $args, $def['class'] );
	}

	/** One site; logs on the Hub side. */
	private static function one( array $grant, array $site, string $tool, array $args, string $class ): array {
		$start = microtime( true );
		$ctx   = array(
			'grant_id' => (int) $grant['id'],
			'client'   => (string) $grant['client_name'],
			'scopes'   => explode( ' ', (string) $grant['scope'] ),
			'site_id'  => (string) $site['id'],
		);
		if ( ! empty( $site['self'] ) ) {
			$ctx['via'] = 'local';
			$r          = SBR_Site::execute( $tool, $args, $ctx );
		} else {
			$r = self::send(
				$site,
				array(
					'op'   => 'tool',
					'tool' => $tool,
					'args' => $args,
					'ctx'  => $ctx,
				)
			);
			if ( is_wp_error( $r ) ) {
				$r = array(
					'ok'    => false,
					'error' => array(
						'code'    => $r->get_error_code(),
						'message' => $r->get_error_message(),
					),
				);
			}
		}
		$ok  = ! empty( $r['ok'] );
		$msg = $ok ? '' : (string) ( $r['error']['message'] ?? 'Error.' );
		if ( empty( $site['self'] ) ) {
			SBR_Store::log(
				array(
					'side'     => 'hub',
					'grant_id' => (int) $grant['id'],
					'client'   => (string) $grant['client_name'],
					'site'     => (string) $site['id'],
					'tool'     => $tool,
					'class'    => $class,
					'args'     => $args,
					'status'   => $ok ? 'ok' : ( in_array( $r['error']['code'] ?? '', array( 'level', 'window_closed', 'scope', 'site_off' ), true ) ? 'denied' : 'error' ),
					'message'  => $msg,
					'bytes'    => $ok ? strlen( Site_Bridge::json( $r['result'] ) ) : 0,
					'ms'       => (int) round( 1000 * ( microtime( true ) - $start ) ),
				)
			);
		}
		return $ok ? array(
			'ok'     => true,
			'result' => $r['result'],
		) : array(
			'ok'    => false,
			'error' => $msg,
		);
	}

	/** list_sites for a connection. */
	private static function list_for( array $grant ): array {
		$out   = array();
		$start = time();
		foreach ( self::sites() as $id => $s ) {
			if ( ! self::grant_allows( $grant, $id ) ) {
				continue;
			}
			$row = array(
				'id'    => $id,
				'label' => $s['label'],
				'url'   => preg_replace( '#/wp-json/site-bridge/v1/?$#', '/', (string) $s['url'] ),
			);
			$st  = time() - $start > self::BUDGET ? new WP_Error( 'sbr_time', 'Not checked: out of time.' ) : self::status( $s );
			if ( is_wp_error( $st ) ) {
				$row['online'] = false;
				$row['error']  = $st->get_error_message();
			} else {
				$row['online']       = true;
				$row['level']        = $st['level'] ?? '';
				$row['write_window'] = ! empty( $st['window_until'] ) ? 'open until ' . gmdate( 'c', (int) $st['window_until'] ) : 'closed';
				$row['woocommerce']  = ! empty( $st['woocommerce'] );
			}
			$out[] = $row;
		}
		return array(
			'sites'        => $out,
			'scopes'       => explode( ' ', (string) $grant['scope'] ),
			'how_to_write' => 'Writes need the site\'s level (write or admin) and an open write window. Only the owner opens it, in WP admin → Noxpress → Site Bridge. Never try to work around a refusal.',
		);
	}
}

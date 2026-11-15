<?php
/**
 * NM_Dropbox — the site's own Dropbox connection (1.3.0).
 *
 * Each site connects the Dropbox account its owner picks. Nothing goes
 * through a server of ours: the site talks to Dropbox directly.
 *
 * Connection: OAuth 2 with PKCE and no redirect (Dropbox shows a code
 * that the administrator pastes back), so one Dropbox app works on every
 * site without registering their addresses. No app secret exists in the
 * code: PKCE does not need one. The app has "App folder" access only:
 * the site sees Apps/<app name>/ and nothing else of the account.
 *
 * App key (public, not a secret), first found wins:
 *  1. the constant NM_DROPBOX_APP_KEY in wp-config.php,
 *  2. the key typed in DM Settings (the owner's own Dropbox app),
 *  3. self::APP_KEY, the suite's shared app.
 *
 * Refresh token (Bible §11, secrets of an external service):
 *  - the constant NM_DROPBOX_TOKEN in wp-config.php wins when it is set;
 *  - else the option nm_dropbox_token (autoload off), sealed with
 *    AES-256-GCM by NM_Crypt::seal() with a key from the wp-config.php
 *    salts. Without real salts the connection is refused (we do not
 *    pretend to encrypt) and the page shows the constant instead;
 *  - the access token (four hours) lives in a sealed transient only;
 *  - neither ever goes into job files, logs, e-mails or a backup: the
 *    dump skips every row that matches DUMP_SKIP_RE;
 *  - names hold "token" or "key", so Site Bridge shows them as ***.
 *
 * Requests go to three fixed hosts only, with TLS verification on and no
 * redirects. Every message that leaves this class passes redact().
 */

defined( 'ABSPATH' ) || exit;

final class NM_Dropbox {

	/** The suite's shared Dropbox app (public app key; '' until it exists). */
	const APP_KEY = '';

	const TOKEN_OPT  = 'nm_dropbox_token';
	const ACCESS     = 'nm_dropbox_access_token';
	const PKCE       = 'nm_dropbox_pkce_key_';
	const OPTION     = 'nm_remote';
	const KEY_RE     = '/^[a-z0-9]{8,32}$/';
	const SCOPES     = 'account_info.read files.metadata.read files.content.read files.content.write';

	/** Option rows a backup never contains (secrets of this connection). */
	const DUMP_SKIP_RE = '/^(_transient_(timeout_)?)?nm_dropbox_/';

	/** Per-request cache of the access token. */
	private static $access = '';

	/** Strings to hide in every message (tokens of this request). */
	private static $secrets = array();

	/* ---------------------------------------------------------------
	 * Settings (nm_remote, no secrets)
	 * ------------------------------------------------------------- */

	public static function defaults(): array {
		return array(
			'app_key'  => '',
			'dest'     => 'local',
			'encrypt'  => true,
			'chunk_mb' => 8,
			'quota'    => 0,
			'account'  => '',
			'since'    => 0,
			'last_ok'  => 0,
			'broken'   => '',
		);
	}

	public static function get(): array {
		$v = get_option( self::OPTION, array() );
		return self::clean( array_merge( self::defaults(), is_array( $v ) ? $v : array() ) );
	}

	public static function save( array $v ): void {
		update_option( self::OPTION, self::clean( array_merge( self::get(), $v ) ), false );
	}

	public static function clean( array $v ): array {
		$d   = self::defaults();
		$out = array();
		$k   = isset( $v['app_key'] ) && is_string( $v['app_key'] ) ? strtolower( trim( $v['app_key'] ) ) : '';
		$out['app_key']  = preg_match( self::KEY_RE, $k ) ? $k : '';
		$out['dest']     = in_array( $v['dest'] ?? '', array( 'local', 'both', 'remote' ), true ) ? $v['dest'] : $d['dest'];
		$out['encrypt']  = ! empty( $v['encrypt'] );
		$out['chunk_mb'] = in_array( (int) ( $v['chunk_mb'] ?? 0 ), array( 4, 8, 16 ), true ) ? (int) $v['chunk_mb'] : $d['chunk_mb'];
		$out['quota']    = max( 0, min( 10000, (int) ( $v['quota'] ?? 0 ) ) );
		$out['account']  = substr( sanitize_text_field( (string) ( $v['account'] ?? '' ) ), 0, 120 );
		$out['since']    = max( 0, (int) ( $v['since'] ?? 0 ) );
		$out['last_ok']  = max( 0, (int) ( $v['last_ok'] ?? 0 ) );
		$out['broken']   = substr( sanitize_text_field( (string) ( $v['broken'] ?? '' ) ), 0, 300 );
		return $out;
	}

	/** The app key in use, '' when there is none. */
	public static function app_key(): string {
		if ( defined( 'NM_DROPBOX_APP_KEY' ) && is_string( NM_DROPBOX_APP_KEY ) && preg_match( self::KEY_RE, NM_DROPBOX_APP_KEY ) ) {
			return NM_DROPBOX_APP_KEY;
		}
		$own = self::get()['app_key'];
		return '' !== $own ? $own : self::APP_KEY;
	}

	/** Where the app key comes from: const | own | suite | none. */
	public static function app_key_source(): string {
		if ( defined( 'NM_DROPBOX_APP_KEY' ) && is_string( NM_DROPBOX_APP_KEY ) && preg_match( self::KEY_RE, NM_DROPBOX_APP_KEY ) ) {
			return 'const';
		}
		if ( '' !== self::get()['app_key'] ) {
			return 'own';
		}
		return '' !== self::APP_KEY ? 'suite' : 'none';
	}

	/* ---------------------------------------------------------------
	 * Token storage
	 * ------------------------------------------------------------- */

	/** The four salts that seal secrets are real (set, long, not the sample text). */
	public static function salts_ok(): bool {
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT' ) as $c ) {
			$v = defined( $c ) ? (string) constant( $c ) : '';
			if ( strlen( $v ) < 32 || false !== stripos( $v, 'put your unique phrase here' ) ) {
				return false;
			}
		}
		return true;
	}

	/** Where the refresh token comes from: const | option | unreadable | none. */
	public static function token_source(): string {
		if ( defined( 'NM_DROPBOX_TOKEN' ) && is_string( NM_DROPBOX_TOKEN ) && '' !== NM_DROPBOX_TOKEN ) {
			return 'const';
		}
		$v = get_option( self::TOKEN_OPT, '' );
		if ( ! is_string( $v ) || '' === $v ) {
			return 'none';
		}
		return null === NM_Crypt::unseal( $v ) ? 'unreadable' : 'option';
	}

	private static function refresh_token(): string {
		if ( defined( 'NM_DROPBOX_TOKEN' ) && is_string( NM_DROPBOX_TOKEN ) && '' !== NM_DROPBOX_TOKEN ) {
			self::hide( NM_DROPBOX_TOKEN );
			return NM_DROPBOX_TOKEN;
		}
		$v = get_option( self::TOKEN_OPT, '' );
		$t = is_string( $v ) && '' !== $v ? NM_Crypt::unseal( $v ) : null;
		if ( null === $t ) {
			return '';
		}
		self::hide( $t );
		return $t;
	}

	/** A connection that can work: an app key and a readable refresh token. */
	public static function connected(): bool {
		return '' !== self::app_key() && in_array( self::token_source(), array( 'const', 'option' ), true );
	}

	/** Forget the connection here (option, access token, pending PKCE). */
	public static function forget(): void {
		delete_option( self::TOKEN_OPT );
		delete_transient( self::ACCESS );
		self::$access = '';
		self::save(
			array(
				'account' => '',
				'since'   => 0,
				'last_ok' => 0,
				'broken'  => '',
			)
		);
	}

	/* ---------------------------------------------------------------
	 * Connect (PKCE, no redirect)
	 * ------------------------------------------------------------- */

	private static function b64url( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	/**
	 * The Dropbox page that shows the code. The verifier waits, sealed,
	 * in a transient of this administrator for ten minutes.
	 */
	public static function auth_url(): string {
		$key = self::app_key();
		if ( '' === $key || ! NM_Crypt::available() ) {
			return '';
		}
		$verifier = self::b64url( random_bytes( 48 ) );
		set_transient( self::PKCE . get_current_user_id(), NM_Crypt::seal( $verifier ), 10 * MINUTE_IN_SECONDS );
		return add_query_arg(
			array(
				'client_id'             => rawurlencode( $key ),
				'response_type'         => 'code',
				'token_access_type'     => 'offline',
				'code_challenge'        => self::b64url( hash( 'sha256', $verifier, true ) ),
				'code_challenge_method' => 'S256',
				'scope'                 => rawurlencode( self::SCOPES ),
			),
			self::base( 'www' ) . '/oauth2/authorize'
		);
	}

	/**
	 * Exchange the pasted code for a refresh token and store it.
	 *
	 * @return true|WP_Error
	 */
	public static function connect( string $code ) {
		$code = trim( $code );
		$pend = get_transient( self::PKCE . get_current_user_id() );
		delete_transient( self::PKCE . get_current_user_id() ); // One use, whatever happens.
		$verifier = is_string( $pend ) ? NM_Crypt::unseal( $pend ) : null;
		if ( null === $verifier ) {
			return new WP_Error( 'pkce', NM_Util::t( 'Ο κωδικός έληξε ή ζητήθηκε από άλλον λογαριασμό. Πάτησε ξανά «Σύνδεση με Dropbox».' ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{10,200}$/', $code ) ) {
			return new WP_Error( 'code', NM_Util::t( 'Ο κωδικός από το Dropbox δεν είναι έγκυρος.' ) );
		}
		$keep_const = 'const' === self::token_source();
		if ( ! $keep_const && ! self::salts_ok() ) {
			return new WP_Error( 'salts', NM_Util::t( 'Το wp-config.php δεν έχει πραγματικά κλειδιά (salts), οπότε το token δεν μπορεί να φυλαχτεί κρυπτογραφημένο. Πρόσθεσε τα salts ή όρισε το token με τη σταθερά NM_DROPBOX_TOKEN.' ) );
		}
		self::hide( $code );
		self::hide( $verifier );
		$r = self::oauth(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => self::app_key(),
				'code_verifier' => $verifier,
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( empty( $r['refresh_token'] ) || empty( $r['access_token'] ) ) {
			return new WP_Error( 'token', NM_Util::t( 'Το Dropbox δεν έδωσε token. Δοκίμασε ξανά.' ) );
		}
		self::hide( (string) $r['refresh_token'] );
		if ( ! $keep_const ) {
			$sealed = NM_Crypt::seal( (string) $r['refresh_token'] );
			if ( '' === $sealed ) {
				return new WP_Error( 'seal', NM_Util::t( 'Δεν γίνεται σε αυτόν τον server: η PHP δεν έχει την επέκταση OpenSSL.' ) );
			}
			update_option( self::TOKEN_OPT, $sealed, false );
		}
		self::keep_access( (string) $r['access_token'], (int) ( $r['expires_in'] ?? 14400 ) );
		$acc  = self::rpc( 'users/get_current_account', null );
		$name = '';
		if ( is_array( $acc ) ) {
			$name = self::mask_email( (string) ( $acc['email'] ?? '' ) );
		}
		self::save(
			array(
				'account' => $name,
				'since'   => time(),
				'broken'  => '',
			)
		);
		NM_Util::log( 'dropbox connected' );
		return true;
	}

	/** Revoke the token at Dropbox (best effort) and forget it here. */
	public static function disconnect(): void {
		if ( self::connected() && '' !== self::access() ) {
			self::rpc( 'auth/token/revoke', null );
		}
		self::forget();
		NM_Util::log( 'dropbox disconnected' );
	}

	public static function mask_email( string $e ): string {
		if ( ! is_email( $e ) ) {
			return '';
		}
		list( $u, $d ) = explode( '@', $e, 2 );
		return substr( $u, 0, 1 ) . str_repeat( '*', max( 2, min( 6, strlen( $u ) - 1 ) ) ) . '@' . $d;
	}

	/* ---------------------------------------------------------------
	 * Access token
	 * ------------------------------------------------------------- */

	private static function keep_access( string $tok, int $ttl ): void {
		self::$access = $tok;
		self::hide( $tok );
		$ttl = max( 60, min( 14400, $ttl ) - 300 );
		set_transient( self::ACCESS, NM_Crypt::seal( $tok ), $ttl );
	}

	/** A valid access token ('' when the connection does not work). */
	private static function access( bool $fresh = false ): string {
		if ( ! $fresh && '' !== self::$access ) {
			return self::$access;
		}
		if ( ! $fresh ) {
			$t = get_transient( self::ACCESS );
			$t = is_string( $t ) ? NM_Crypt::unseal( $t ) : null;
			if ( null !== $t && '' !== $t ) {
				self::$access = $t;
				self::hide( $t );
				return $t;
			}
		}
		$rt = self::refresh_token();
		if ( '' === $rt || '' === self::app_key() ) {
			return '';
		}
		$r = self::oauth(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $rt,
				'client_id'     => self::app_key(),
			)
		);
		if ( is_wp_error( $r ) ) {
			if ( 'invalid_grant' === $r->get_error_code() ) {
				self::save( array( 'broken' => NM_Util::t( 'Η σύνδεση με το Dropbox ακυρώθηκε (από το Dropbox ή αλλαγή κωδικού). Σύνδεσε ξανά.' ) ) );
			}
			return '';
		}
		if ( empty( $r['access_token'] ) ) {
			return '';
		}
		self::keep_access( (string) $r['access_token'], (int) ( $r['expires_in'] ?? 14400 ) );
		return self::$access;
	}

	/* ---------------------------------------------------------------
	 * HTTP
	 * ------------------------------------------------------------- */

	/** Base URL of one of the three hosts. The filter exists for tests with a local fake only. */
	private static function base( string $which ): string {
		$hosts = array(
			'api'     => 'https://api.dropboxapi.com',
			'content' => 'https://content.dropboxapi.com',
			'www'     => 'https://www.dropbox.com',
		);
		return (string) apply_filters( 'nm_dropbox_base', $hosts[ $which ], $which );
	}

	private static function hide( string $s ): void {
		if ( strlen( $s ) >= 8 ) {
			self::$secrets[ $s ] = true;
		}
	}

	/** A message without any token, code or verifier (raw and URL-encoded). */
	public static function redact( string $s ): string {
		foreach ( array_keys( self::$secrets ) as $sec ) {
			$s = str_replace( array( $sec, rawurlencode( $sec ), urlencode( $sec ) ), '***', $s );
		}
		return (string) preg_replace( '/\bsl\.[A-Za-z0-9._-]{20,}/', '***', $s );
	}

	private static function args( array $headers, $body, float $timeout ): array {
		return array(
			'timeout'     => $timeout,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => $headers,
			'body'        => $body,
			'user-agent'  => 'Data Migrator/' . NM_VERSION . '; WordPress',
		);
	}

	/** POST /oauth2/token (form). WP_Error codes: invalid_grant, retry, http. */
	private static function oauth( array $fields ) {
		$res = wp_remote_post( self::base( 'api' ) . '/oauth2/token', self::args( array( 'Content-Type' => 'application/x-www-form-urlencoded' ), $fields, 30 ) );
		$out = self::answer( $res );
		if ( is_wp_error( $out ) && false !== strpos( $out->get_error_message(), 'invalid_grant' ) ) {
			return new WP_Error( 'invalid_grant', $out->get_error_message() );
		}
		return $out;
	}

	/**
	 * Decode a response. Arrays for 2xx; WP_Error otherwise, with code
	 * 'retry' (429, 5xx, network; data: seconds to wait), 'auth' (401),
	 * 'api' (409, data: the Dropbox error object) or 'http'.
	 *
	 * @param array|WP_Error $res
	 * @return array|WP_Error
	 */
	private static function answer( $res, bool $raw = false ) {
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'retry', self::redact( NM_Util::tf( 'Το Dropbox δεν απάντησε: %s', $res->get_error_message() ) ), 5 );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		if ( $code >= 200 && $code < 300 ) {
			if ( $raw ) {
				return array(
					'body'    => $body,
					'headers' => wp_remote_retrieve_headers( $res ),
				);
			}
			$j = json_decode( $body, true );
			return is_array( $j ) ? $j : array();
		}
		$j   = json_decode( $body, true );
		$sum = is_array( $j ) ? (string) ( $j['error_summary'] ?? ( is_string( $j['error'] ?? null ) ? $j['error'] . ' ' . ( $j['error_description'] ?? '' ) : '' ) ) : substr( wp_strip_all_tags( $body ), 0, 200 );
		$sum = self::redact( trim( $sum ) );
		if ( 429 === $code || $code >= 500 ) {
			$wait = (int) wp_remote_retrieve_header( $res, 'retry-after' );
			return new WP_Error( 'retry', NM_Util::tf( 'Το Dropbox ζήτησε αναμονή (HTTP %1$d) %2$s', $code, $sum ), max( 1, min( 300, $wait ? $wait : 5 ) ) );
		}
		if ( 401 === $code ) {
			return new WP_Error( 'auth', NM_Util::tf( 'Το Dropbox δεν δέχτηκε τη σύνδεση: %s', $sum ) );
		}
		if ( 409 === $code ) {
			return new WP_Error( 'api', $sum, is_array( $j ) && isset( $j['error'] ) && is_array( $j['error'] ) ? $j['error'] : array() );
		}
		return new WP_Error( 'http', NM_Util::tf( 'Σφάλμα του Dropbox (HTTP %1$d): %2$s', $code, $sum ) );
	}

	/**
	 * Call with the access token; one fresh token on 401.
	 *
	 * @param callable $send fn( string $access ): array|WP_Error raw response.
	 * @return array|WP_Error
	 */
	private static function authed( callable $send, bool $raw = false ) {
		$tok = self::access();
		if ( '' === $tok ) {
			return new WP_Error( 'auth', self::get()['broken'] ? self::get()['broken'] : NM_Util::t( 'Δεν υπάρχει σύνδεση με το Dropbox.' ) );
		}
		$r = self::answer( $send( $tok ), $raw );
		if ( is_wp_error( $r ) && 'auth' === $r->get_error_code() ) {
			delete_transient( self::ACCESS );
			$tok = self::access( true );
			if ( '' === $tok ) {
				return $r;
			}
			$r = self::answer( $send( $tok ), $raw );
		}
		return $r;
	}

	/** RPC endpoint (JSON in, JSON out). $args null = no arguments. */
	public static function rpc( string $endpoint, $args ) {
		$url  = self::base( 'api' ) . '/2/' . $endpoint;
		$body = null === $args ? 'null' : (string) wp_json_encode( $args, JSON_UNESCAPED_SLASHES );
		return self::authed(
			static function ( $tok ) use ( $url, $body ) {
				return wp_remote_post(
					$url,
					self::args(
						array(
							'Authorization' => 'Bearer ' . $tok,
							'Content-Type'  => 'application/json',
						),
						$body,
						30
					)
				);
			}
		);
	}

	/** Content upload endpoint: argument in the Dropbox-API-Arg header, bytes in the body. */
	public static function upload( string $endpoint, array $arg, string $bytes ) {
		$url = self::base( 'content' ) . '/2/' . $endpoint;
		$hdr = self::api_arg( $arg );
		return self::authed(
			static function ( $tok ) use ( $url, $hdr, $bytes ) {
				return wp_remote_post(
					$url,
					self::args(
						array(
							'Authorization'   => 'Bearer ' . $tok,
							'Content-Type'    => 'application/octet-stream',
							'Dropbox-API-Arg' => $hdr,
						),
						$bytes,
						120
					)
				);
			}
		);
	}

	/**
	 * A byte range of a file (both ends included).
	 *
	 * @return string|WP_Error The bytes.
	 */
	public static function download( string $path, int $from, int $to_byte ) {
		$url = self::base( 'content' ) . '/2/files/download';
		$hdr = self::api_arg( array( 'path' => $path ) );
		$r   = self::authed(
			static function ( $tok ) use ( $url, $hdr, $from, $to_byte ) {
				return wp_remote_post(
					$url,
					self::args(
						array(
							'Authorization'   => 'Bearer ' . $tok,
							'Content-Type'    => 'application/octet-stream',
							'Dropbox-API-Arg' => $hdr,
							'Range'           => 'bytes=' . $from . '-' . $to_byte,
						),
						'',
						120
					)
				);
			},
			true
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( strlen( $r['body'] ) !== $to_byte - $from + 1 ) {
			return new WP_Error( 'retry', NM_Util::t( 'Το Dropbox έστειλε λιγότερα δεδομένα από όσα ζητήθηκαν.' ), 5 );
		}
		return $r['body'];
	}

	/** A small whole file (the metadata JSON). */
	public static function get_small( string $path, int $max = 262144 ) {
		$url = self::base( 'content' ) . '/2/files/download';
		$hdr = self::api_arg( array( 'path' => $path ) );
		$r   = self::authed(
			static function ( $tok ) use ( $url, $hdr, $max ) {
				return wp_remote_post(
					$url,
					self::args(
						array(
							'Authorization'   => 'Bearer ' . $tok,
							'Content-Type'    => 'application/octet-stream',
							'Dropbox-API-Arg' => $hdr,
						),
						'',
						30
					) + array( 'limit_response_size' => $max + 1 )
				);
			},
			true
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return strlen( $r['body'] ) > $max ? new WP_Error( 'big', 'too big' ) : $r['body'];
	}

	/** Header value: JSON with every non-ASCII character escaped (HTTP headers are ASCII). */
	private static function api_arg( array $arg ): string {
		return (string) wp_json_encode( $arg, JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Dropbox content hash of a byte string, block by block: the raw
	 * sha256 of each 4 MB block, appended (the caller keeps them).
	 */
	public static function block_hashes( string $bytes ): string {
		$out = '';
		$n   = strlen( $bytes );
		for ( $i = 0; $i < $n; $i += 4194304 ) {
			$out .= hash( 'sha256', substr( $bytes, $i, 4194304 ), true );
		}
		return $out;
	}

	/** A Dropbox path for one of our files: /<host>/<name>, both strictly checked. */
	public static function path( string $host, string $name ): string {
		if ( ! preg_match( '/^[a-z0-9.-]{1,80}$/', $host ) || ! preg_match( '/^[a-z0-9.-]{1,80}-\d{8}-\d{6}-[a-f0-9]{32}(-part\d{1,3})?\.zip$|^[a-f0-9]{32}\.json$/', $name ) ) {
			return '';
		}
		return '/' . $host . '/' . $name;
	}
}

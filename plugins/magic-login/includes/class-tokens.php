<?php
/**
 * MGL_Tokens — one-time login tokens, rate limits, the event log and the
 * rule of who may log in by link.
 *
 * Token: 32 random bytes (random_bytes), base64url in the link. The user
 * meta _mgl_token keeps only a JSON string { h: HMAC-SHA256 of
 * "<user id>|<token>" with wp_salt( 'auth' ), x: expiry (Unix time),
 * r: where to go after the login, l: e-mail language }. One token per
 * user: a new request replaces it, a password change or reset deletes it,
 * and using it deletes it with a compare-and-delete (only the request whose
 * DELETE removed the row logs in, so two quick clicks never make two
 * logins).
 *
 * Rate limits (transients, sliding windows): per account (the e-mail or
 * username as typed, lowercased, hashed) and per IP address (hashed with a
 * salt; never stored as is). They count whether the account exists or
 * not, so they say nothing about it.
 *
 * Log: option mgl_log (autoload off), the newest 200 events, each
 * { t: time, u: user id or 0, e: event }. No e-mail, no IP.
 */

defined( 'ABSPATH' ) || exit;

final class MGL_Tokens {

	const META    = '_mgl_token';
	const LOG     = 'mgl_log';
	const LOG_MAX = 200;

	/** Events of the log: key => label msgid. */
	public static function events(): array {
		return array(
			'sent'      => __( 'Στάλθηκε σύνδεσμος', 'magic-login' ),
			'used'      => __( 'Σύνδεση με σύνδεσμο', 'magic-login' ),
			'invalid'   => __( 'Σύνδεσμος που έληξε ή χρησιμοποιήθηκε', 'magic-login' ),
			'unknown'   => __( 'Αίτημα για email χωρίς λογαριασμό', 'magic-login' ),
			'staff'     => __( 'Αίτημα για λογαριασμό προσωπικού (χωρίς email)', 'magic-login' ),
			'test_skip' => __( 'Λειτουργία δοκιμής: ο λογαριασμός δεν είναι στη λίστα δοκιμής', 'magic-login' ),
			'limited'   => __( 'Όριο αιτημάτων', 'magic-login' ),
			'mail_fail' => __( 'Το email δεν στάλθηκε', 'magic-login' ),
		);
	}

	public static function init(): void {
		// A new password ends any pending link.
		add_action( 'after_password_reset', array( __CLASS__, 'forget_user' ) );
		add_action( 'wp_set_password', array( __CLASS__, 'forget_on_set_password' ), 10, 2 );
	}

	/** @param WP_User|int $user */
	public static function forget_user( $user ): void {
		$uid = $user instanceof WP_User ? (int) $user->ID : (int) $user;
		if ( $uid > 0 ) {
			delete_user_meta( $uid, self::META );
		}
	}

	/** wp_set_password action (WordPress 6.2+): ( $password, $user_id ). */
	public static function forget_on_set_password( $password, $user_id = 0 ): void {
		self::forget_user( (int) $user_id );
	}

	/* =====================================================================
	 * Who may log in by link
	 * =================================================================== */

	/**
	 * Customers and trade accounts may; staff never (a stolen mailbox
	 * must not open the admin): edit_posts, manage_woocommerce or
	 * manage_options means "password only".
	 */
	public static function allowed( WP_User $user ): bool {
		$ok = ! ( user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_woocommerce' ) || user_can( $user, 'manage_options' ) );
		/**
		 * Filters whether a user may log in by link. Staff stay excluded
		 * whatever the filter says.
		 *
		 * @param bool    $ok   Default decision.
		 * @param WP_User $user User.
		 */
		$ok = (bool) apply_filters( 'mgl_user_allowed', $ok, $user );
		return $ok && ! user_can( $user, 'edit_posts' ) && ! user_can( $user, 'manage_woocommerce' ) && ! user_can( $user, 'manage_options' );
	}

	/** In test mode only the listed test accounts get links. */
	public static function is_tester( WP_User $user ): bool {
		return in_array( strtolower( (string) $user->user_email ), MGL_Settings::get()['testers'], true );
	}

	/** The user behind what was typed in the login form (e-mail or username), or null. */
	public static function find_user( string $ident ) {
		$ident = trim( $ident );
		if ( '' === $ident ) {
			return null;
		}
		$user = is_email( $ident ) ? get_user_by( 'email', $ident ) : false;
		if ( ! $user ) {
			$user = get_user_by( 'login', $ident );
		}
		return $user instanceof WP_User ? $user : null;
	}

	/* =====================================================================
	 * Tokens
	 * =================================================================== */

	private static function hmac( int $uid, string $token ): string {
		return hash_hmac( 'sha256', $uid . '|' . $token, wp_salt( 'auth' ) );
	}

	/**
	 * Makes a new token for the user (the old one stops working) and
	 * returns it; only its HMAC is stored.
	 *
	 * @param WP_User $user     User.
	 * @param string  $redirect Validated local URL to go to after the login.
	 * @param string  $lang     'el' | 'en'.
	 */
	public static function issue( WP_User $user, string $redirect, string $lang ): string {
		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$entry = wp_json_encode(
			array(
				'h' => self::hmac( (int) $user->ID, $token ),
				'x' => time() + 60 * (int) MGL_Settings::get()['ttl'],
				'r' => $redirect,
				'l' => 'en' === $lang ? 'en' : 'el',
			)
		);
		// The meta API unslashes values: slash the JSON so it is stored as is.
		update_user_meta( (int) $user->ID, self::META, wp_slash( (string) $entry ) );
		return $token;
	}

	/** The link that goes in the e-mail: the My Account page with ?mgl=<id>-<token>. */
	public static function link( int $uid, string $token ): string {
		$base = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
		return add_query_arg( 'mgl', rawurlencode( $uid . '-' . $token ), $base );
	}

	/**
	 * Splits the ?mgl= value into user id and token, or null when it has
	 * the wrong shape.
	 *
	 * @return array{0:int,1:string}|null
	 */
	public static function parse( string $raw ) {
		if ( ! preg_match( '/^([1-9][0-9]{0,19})-([A-Za-z0-9_-]{43})$/', $raw, $m ) ) {
			return null;
		}
		return array( (int) $m[1], $m[2] );
	}

	/**
	 * The stored entry when the token is valid for the user (and the user
	 * may still use links), without using it up; null otherwise.
	 */
	public static function check( int $uid, string $token ) {
		$raw   = (string) get_user_meta( $uid, self::META, true );
		$entry = '' !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $entry ) || ! isset( $entry['h'], $entry['x'] ) ) {
			return null;
		}
		if ( (int) $entry['x'] < time() || ! hash_equals( (string) $entry['h'], self::hmac( $uid, $token ) ) ) {
			return null;
		}
		$user = get_user_by( 'id', $uid );
		if ( ! $user instanceof WP_User || ! self::allowed( $user ) ) {
			return null;
		}
		if ( 'test' === MGL_Settings::mode() && ! self::is_tester( $user ) ) {
			return null;
		}
		$entry['raw'] = $raw;
		return $entry;
	}

	/**
	 * Uses the token up: returns its entry when this request deleted it,
	 * null when it was not valid or another request was first.
	 */
	public static function consume( int $uid, string $token ) {
		$entry = self::check( $uid, $token );
		if ( null === $entry ) {
			return null;
		}
		// Compare-and-delete: only one request removes the row.
		if ( ! delete_user_meta( $uid, self::META, wp_slash( $entry['raw'] ) ) ) {
			return null;
		}
		unset( $entry['raw'] );
		return $entry;
	}

	/* =====================================================================
	 * Rate limits
	 * =================================================================== */

	private static function key_acct( string $ident ): string {
		return 'mgl_ra_' . md5( strtolower( trim( $ident ) ) );
	}

	private static function key_ip(): string {
		$ip = class_exists( 'WC_Geolocation' ) ? (string) WC_Geolocation::get_ip_address() : '';
		if ( '' === $ip && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
		}
		return 'mgl_ri_' . md5( $ip . '|' . wp_salt( 'nonce' ) );
	}

	/**
	 * Counts one request for the typed account and the visitor's IP and
	 * says whether either limit was already reached (then nothing is sent).
	 */
	public static function over_limit( string $ident ): bool {
		$s     = MGL_Settings::get();
		$k_acc = self::key_acct( $ident );
		$k_ip  = self::key_ip();
		$n_acc = (int) get_transient( $k_acc );
		$n_ip  = (int) get_transient( $k_ip );
		if ( $n_acc >= (int) $s['per_acct'] || $n_ip >= (int) $s['per_ip'] ) {
			return true;
		}
		set_transient( $k_acc, $n_acc + 1, MGL_Settings::ACCT_WINDOW );
		set_transient( $k_ip, $n_ip + 1, HOUR_IN_SECONDS );
		return false;
	}

	/* =====================================================================
	 * Log
	 * =================================================================== */

	public static function log( string $event, int $uid = 0 ): void {
		if ( ! isset( self::events()[ $event ] ) ) {
			return;
		}
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				't' => time(),
				'u' => max( 0, $uid ),
				'e' => $event,
			)
		);
		update_option( self::LOG, array_slice( $log, 0, self::LOG_MAX ), false );
	}

	/** The newest events first. */
	public static function entries( int $limit = self::LOG_MAX ): array {
		$log = get_option( self::LOG, array() );
		$out = array();
		foreach ( is_array( $log ) ? $log : array() as $row ) {
			if ( is_array( $row ) && isset( $row['t'], $row['e'] ) && isset( self::events()[ $row['e'] ] ) ) {
				$out[] = array(
					't' => (int) $row['t'],
					'u' => isset( $row['u'] ) ? (int) $row['u'] : 0,
					'e' => (string) $row['e'],
				);
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/** «k***@example.gr»: enough for the customer to recognise the account. */
	public static function mask_email( string $email ): string {
		$at = strrpos( $email, '@' );
		if ( false === $at || $at < 1 ) {
			return '***';
		}
		return mb_substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}
}

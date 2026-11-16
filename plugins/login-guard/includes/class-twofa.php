<?php
/**
 * LGD_TwoFA — two-step verification with an authenticator app (TOTP).
 *
 * Storage (user meta, never autoloaded options):
 *  - _lgd_2fa      array( s: sealed secret (LGD_Crypt, bound to the user
 *                  id), on: time turned on, last: last TOTP step used,
 *                  rc: HMACs of the unused recovery codes )
 *  - _lgd_2fa_p    the pending second step of one login: array( h: HMAC of
 *                  the token in the lgd_2p cookie, x: expiry, n: wrong
 *                  codes, r: remember me, to: redirect, i: interim login )
 *  - _lgd_2fa_g    grace for a role where 2FA is required: array( t: first
 *                  login seen, n: logins since )
 *  - _lgd_tv       version of the remembered browsers; a change of
 *                  password, e-mail or 2FA, and «Log out everywhere else»,
 *                  raise it and forget them all.
 *
 * Login: a right password alone never makes a session for a user with
 * 2FA. On wp-login.php and the WooCommerce login forms, authenticate
 * (priority 100000, after the gate) stores a pending step, sets the
 * lgd_2p cookie (HttpOnly, SameSite=Lax, 10 minutes) and redirects to
 * wp-login.php?action=lgd_2fa, where the code is asked. Five wrong codes
 * end the pending step; every wrong code also counts as a failed login for
 * the IP and the account (LGD_Guard::count). Any other caller of
 * authenticate (XML-RPC in «open» mode, a plugin's own login form) gets
 * an error: such accounts log in through the login form, or use an
 * Application Password (which WordPress keeps without 2FA by design).
 *
 * Strict guard (setting tfa_strict, live mode): set_auth_cookie for a user
 * with 2FA who did not pass the second step in this request destroys that
 * session token and clears the cookies (other plugins' «log in as» or
 * login links cannot skip 2FA). WP-CLI, cron and a new cookie for the user
 * who is already logged in (password change in the profile) are allowed.
 * In test mode it only writes to the log.
 *
 * Required 2FA (setting tfa_roles, live mode): a user of such a role
 * without 2FA has a grace period (tfa_grace_logins logins or
 * tfa_grace_days days, whichever ends first), then every admin page
 * except the profile redirects to the 2FA section of the profile.
 *
 * Doors: LGD_DISABLE_2FA in wp-config.php (2FA only), recovery codes,
 * another administrator with 2FA, wp login-guard 2fa reset <user>.
 */

defined( 'ABSPATH' ) || exit;

final class LGD_TwoFA {

	const META    = '_lgd_2fa';
	const PENDING = '_lgd_2fa_p';
	const GRACE   = '_lgd_2fa_g';
	const TV      = '_lgd_tv';
	const COOKIE  = 'lgd_2p';
	const TRUST   = 'lgd_td_';
	const NONCE   = 'lgd_2fa';
	const TTL     = 600;
	const TRIES   = 5;
	const CODES   = 10;

	/** User id that passed the second step in this request. */
	private static $passed = 0;

	/** User id whose new session the strict guard ended in this request. */
	private static $blocked = 0;

	/** User id the request's own auth cookie resolved to, before any login code ran (strict guard). */
	private static $cookie_uid = -1;

	/** Wrong codes per user in WRONG_WINDOW, in every mode where 2FA works. */
	const WRONG_MAX    = 10;
	const WRONG_WINDOW = 15;
	const WRONG        = '_lgd_2fa_w';

	public static function init(): void {
		add_filter( 'authenticate', array( __CLASS__, 'intercept' ), 100000, 3 );
		add_filter( 'determine_current_user', array( __CLASS__, 'note_cookie_user' ), PHP_INT_MAX );
		add_action( 'login_form_lgd_2fa', array( __CLASS__, 'page' ) );
		add_action( 'set_auth_cookie', array( __CLASS__, 'guard' ), 10, 6 );
		add_action( 'wp_login', array( __CLASS__, 'after_login' ), 1, 2 );
		add_action( 'admin_init', array( __CLASS__, 'enforce' ), 1 );
		add_action( 'admin_notices', array( __CLASS__, 'nag' ) );
		add_action( 'profile_update', array( __CLASS__, 'profile_changed' ), 10, 2 );
		add_action( 'after_password_reset', array( __CLASS__, 'bump_user' ) );
		add_action( 'wp_ajax_destroy-sessions', array( __CLASS__, 'bump_current' ), 0 );
	}

	/* =====================================================================
	 * State
	 * =================================================================== */

	/** 2FA works on this site: not switched off, real salts, OpenSSL. */
	public static function active(): bool {
		if ( Login_Guard::killed() || ( defined( 'LGD_DISABLE_2FA' ) && LGD_DISABLE_2FA ) ) {
			return false;
		}
		return 'off' !== LGD_Settings::mode() && LGD_Settings::get()['twofa'] && self::possible();
	}

	/** The server can keep secrets safely (salts and AES-256-GCM). */
	public static function possible(): bool {
		return LGD_Crypt::available() && Noxpress_Safe::has_salts();
	}

	/** The stored 2FA record of a user, or an empty array. */
	public static function rec( int $uid ): array {
		$r = get_user_meta( $uid, self::META, true );
		return is_array( $r ) && ! empty( $r['s'] ) ? $r : array();
	}

	public static function is_on( int $uid ): bool {
		return $uid > 0 && array() !== self::rec( $uid );
	}

	/** A role of this user requires 2FA (live mode), or the filter adds it. */
	public static function required( WP_User $user ): bool {
		$base = false;
		if ( LGD_Settings::live() ) {
			$base = (bool) array_intersect( (array) $user->roles, (array) LGD_Settings::get()['tfa_roles'] );
		}
		/**
		 * Require 2FA for a user. The filter can only add: a role that
		 * requires it in the settings keeps requiring it.
		 *
		 * @param bool    $required
		 * @param WP_User $user
		 */
		return $base || (bool) apply_filters( 'lgd_requires_2fa', $base, $user );
	}

	/** Required, not set up, and the grace period is over. */
	public static function grace_over( WP_User $user ): bool {
		if ( self::is_on( (int) $user->ID ) || ! self::required( $user ) ) {
			return false;
		}
		$s = LGD_Settings::get();
		$g = get_user_meta( (int) $user->ID, self::GRACE, true );
		$g = is_array( $g ) ? $g : array();
		$t = isset( $g['t'] ) ? (int) $g['t'] : time();
		$n = isset( $g['n'] ) ? (int) $g['n'] : 0;
		return time() > $t + (int) $s['tfa_grace_days'] * DAY_IN_SECONDS || $n > (int) $s['tfa_grace_logins'];
	}

	/** Raises the remembered-browser version of a user (forgets them all). */
	public static function bump( int $uid ): void {
		if ( $uid > 0 ) {
			// Random, never a counter: a reinstall cannot bring an old value back.
			update_user_meta( $uid, self::TV, bin2hex( random_bytes( 8 ) ) );
		}
	}

	public static function bump_user( $user ): void {
		if ( $user instanceof WP_User ) {
			self::bump( (int) $user->ID );
		}
	}

	/** «Log out everywhere» in a profile (wp_ajax_destroy-sessions): the user of that profile, after core's own checks. */
	public static function bump_current(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below with core's nonce.
		$uid = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		if ( $uid > 0 && current_user_can( 'edit_user', $uid ) && false !== check_ajax_referer( 'update-user_' . $uid, 'nonce', false ) ) {
			self::bump( $uid );
		}
	}

	/** profile_update: a new password or e-mail forgets the remembered browsers. */
	public static function profile_changed( $uid, $old = null ): void {
		$user = get_userdata( (int) $uid );
		if ( $user instanceof WP_User && $old instanceof WP_User && ( $old->user_pass !== $user->user_pass || $old->user_email !== $user->user_email ) ) {
			self::bump( (int) $uid );
		}
	}

	/* =====================================================================
	 * Codes
	 * =================================================================== */

	/** Ten new recovery codes (XXXXX-XXXXX, 50 bits each); their HMACs go to the record. */
	public static function new_codes( int $uid ): array {
		$plain = array();
		$hash  = array();
		for ( $i = 0; $i < self::CODES; $i++ ) {
			$c       = substr( LGD_TOTP::b32_encode( random_bytes( 7 ) ), 0, 10 );
			$plain[] = substr( $c, 0, 5 ) . '-' . substr( $c, 5 );
			$hash[]  = self::code_hash( $uid, $c );
		}
		return array( $plain, $hash );
	}

	private static function code_hash( int $uid, string $norm ): string {
		return hash_hmac( 'sha256', 'rc|' . $uid . '|' . $norm, wp_salt( 'auth' ) );
	}

	/**
	 * Checks a code from the app or a recovery code and uses it up.
	 *
	 * @return string 'totp' | 'rc' | '' (wrong).
	 */
	public static function check( int $uid, string $code ): string {
		$rec = self::rec( $uid );
		if ( array() === $rec ) {
			return '';
		}
		$code = trim( $code );
		if ( preg_match( '/^\d{3}\s?\d{3}$/', $code ) ) {
			$secret = LGD_Crypt::unseal( (string) $rec['s'], $uid );
			if ( null === $secret ) {
				return '';
			}
			$step = LGD_TOTP::verify( $secret, $code, isset( $rec['last'] ) ? (int) $rec['last'] : 0 );
			if ( $step > 0 ) {
				$old         = $rec;
				$rec['last'] = $step;
				// Compare-and-swap: the same code in two requests at once works only once.
				return update_user_meta( $uid, self::META, $rec, $old ) ? 'totp' : '';
			}
			return '';
		}
		$norm = strtoupper( (string) preg_replace( '/[^A-Za-z2-7]/', '', $code ) );
		if ( 10 !== strlen( $norm ) ) {
			return '';
		}
		$h    = self::code_hash( $uid, $norm );
		$list = isset( $rec['rc'] ) && is_array( $rec['rc'] ) ? array_values( $rec['rc'] ) : array();
		$hit  = -1;
		foreach ( $list as $i => $stored ) {
			if ( hash_equals( (string) $stored, $h ) ) {
				$hit = $i;
			}
		}
		if ( $hit < 0 ) {
			return '';
		}
		$old = $rec;
		array_splice( $list, $hit, 1 );
		$rec['rc'] = $list;
		// Compare-and-swap: two requests with the same code, only one wins.
		if ( ! update_user_meta( $uid, self::META, $rec, $old ) ) {
			return '';
		}
		return 'rc';
	}

	/* =====================================================================
	 * Turning on and off
	 * =================================================================== */

	/**
	 * Turns 2FA on with a secret the user has just confirmed with a code.
	 *
	 * @return array The recovery codes, in clear, to show once.
	 */
	public static function enable( WP_User $user, string $secret, int $step ): array {
		$uid    = (int) $user->ID;
		$sealed = LGD_Crypt::seal( $secret, $uid );
		if ( '' === $sealed ) {
			return array();
		}
		list( $plain, $hash ) = self::new_codes( $uid );
		update_user_meta(
			$uid,
			self::META,
			array(
				's'    => $sealed,
				'on'   => time(),
				'last' => $step,
				'rc'   => $hash,
			)
		);
		delete_user_meta( $uid, self::GRACE );
		self::bump( $uid );
		LGD_Store::log( '2fa_on', LGD_Store::ip(), $uid );
		self::mail( $user, 'on' );
		return $plain;
	}

	/** New recovery codes (the old ones stop working). */
	public static function regenerate( WP_User $user ): array {
		$uid = (int) $user->ID;
		$rec = self::rec( $uid );
		if ( array() === $rec ) {
			return array();
		}
		list( $plain, $hash ) = self::new_codes( $uid );
		$rec['rc']            = $hash;
		update_user_meta( $uid, self::META, $rec );
		LGD_Store::log( '2fa_codes', LGD_Store::ip(), $uid );
		return $plain;
	}

	/**
	 * Turns 2FA off for a user.
	 *
	 * @param string $by 'self' | 'admin' | 'cli'.
	 */
	public static function disable( WP_User $user, string $by ): void {
		$uid = (int) $user->ID;
		delete_user_meta( $uid, self::META );
		delete_user_meta( $uid, self::PENDING );
		delete_user_meta( $uid, LGD_Profile::SETUP );
		self::bump( $uid );
		$actor = get_current_user_id();
		LGD_Store::log( 'self' === $by ? '2fa_off' : '2fa_reset', LGD_Store::ip(), $uid, 'self' === $by ? '' : $by . ( $actor ? ' #' . $actor : '' ) );
		self::mail( $user, 'self' === $by ? 'off' : 'reset' );
	}

	/* =====================================================================
	 * Login
	 * =================================================================== */

	/** determine_current_user (last): the first answer is the request's own cookie login. */
	public static function note_cookie_user( $user_id ) {
		if ( -1 === self::$cookie_uid ) {
			self::$cookie_uid = (int) $user_id;
		}
		return $user_id;
	}

	/** True when $password is one of $user's Application Passwords (checked here, not by a request-wide flag). */
	private static function is_app_password( WP_User $user, $password ): bool {
		if ( ! is_string( $password ) || '' === $password || ! class_exists( 'WP_Application_Passwords' ) ) {
			return false;
		}
		$pw = preg_replace( '/[^a-z\d]/i', '', $password );
		if ( 24 !== strlen( (string) $pw ) ) {
			return false; // Every Application Password has 24 characters.
		}
		foreach ( (array) WP_Application_Passwords::get_user_application_passwords( (int) $user->ID ) as $item ) {
			if ( empty( $item['password'] ) ) {
				continue;
			}
			$ok = method_exists( 'WP_Application_Passwords', 'check_password' )
				? WP_Application_Passwords::check_password( $pw, $item['password'] )
				: wp_check_password( $pw, $item['password'], (int) $user->ID );
			if ( $ok ) {
				return true;
			}
		}
		return false;
	}

	/** Atomic +1 of the user's wrong-code counter; returns the new count in this window. */
	private static function wrong_bump( int $uid ): int {
		global $wpdb;
		$start = (int) get_user_meta( $uid, self::WRONG . 's', true );
		if ( $start < time() - self::WRONG_WINDOW * MINUTE_IN_SECONDS ) {
			update_user_meta( $uid, self::WRONG . 's', time() );
			update_user_meta( $uid, self::WRONG, 0 );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic increment, cache cleared below.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->usermeta} SET meta_value = meta_value + 1 WHERE user_id = %d AND meta_key = %s", $uid, self::WRONG ) );
		wp_cache_delete( $uid, 'user_meta' );
		return (int) get_user_meta( $uid, self::WRONG, true );
	}

	/** True while the user has too many wrong codes in the window. */
	private static function wrong_over( int $uid ): bool {
		$start = (int) get_user_meta( $uid, self::WRONG . 's', true );
		return $start >= time() - self::WRONG_WINDOW * MINUTE_IN_SECONDS && (int) get_user_meta( $uid, self::WRONG, true ) >= self::WRONG_MAX;
	}

	/** authenticate (100000): the second step for a user with 2FA. */
	public static function intercept( $user, $username = '', $password = '' ) {
		try {
			if ( ! $user instanceof WP_User || ! self::active() || ! self::is_on( (int) $user->ID ) ) {
				return $user;
			}
			if ( self::is_app_password( $user, $password ) ) {
				return $user; // This user's own Application Password: no 2FA by WordPress's design.
			}
			if ( self::trusted_browser( $user ) ) {
				self::$passed = (int) $user->ID;
				return $user;
			}
			if ( ! self::interactive() ) {
				return new WP_Error( 'lgd_2fa_api', esc_html( LGD_Settings::text( 'tfa_api' ) ) );
			}
			self::start( $user );
		} catch ( \Throwable $e ) {
			// Never fall back to a login without the second step.
			return new WP_Error( 'lgd_2fa_api', esc_html( LGD_Settings::text( 'tfa_api' ) ) );
		}
		return $user;
	}

	/** A login form a person submitted: wp-login.php or WooCommerce's. */
	private static function interactive(): bool {
		if ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method ) {
			return false;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- only reads which form was sent; the form's own checks already ran.
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login';
			return in_array( $action, array( 'login', '' ), true );
		}
		return isset( $_POST['woocommerce-login-nonce'] );
		// phpcs:enable
	}

	/** Stores the pending step, sets its cookie and opens the code screen. */
	private static function start( WP_User $user ): void {
		$uid   = (int) $user->ID;
		$token = self::token();
		// phpcs:disable WordPress.Security.NonceVerification -- the login form's own nonce / checks already ran.
		$remember = ! empty( $_POST['rememberme'] );
		$interim  = ! empty( $_REQUEST['interim-login'] );
		$to       = '';
		if ( isset( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ) {
			$to = wp_unslash( $_REQUEST['redirect_to'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below.
		} elseif ( isset( $_POST['woocommerce-login-nonce'] ) ) {
			$to = isset( $_POST['redirect'] ) && is_string( $_POST['redirect'] ) ? wp_unslash( $_POST['redirect'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below.
			if ( '' === $to && function_exists( 'wc_get_page_permalink' ) ) {
				$to = (string) wc_get_page_permalink( 'myaccount' );
			}
		}
		// phpcs:enable
		$to = '' !== $to ? (string) wp_validate_redirect( esc_url_raw( $to ), '' ) : '';
		update_user_meta(
			$uid,
			self::PENDING,
			array(
				'h'  => self::pending_hmac( $uid, $token ),
				'x'  => time() + self::TTL,
				'n'  => 0,
				'r'  => $remember,
				'to' => substr( $to, 0, 2000 ),
				'i'  => $interim,
			)
		);
		self::cookie( self::COOKIE, $uid . '.' . $token, time() + self::TTL );
		$url = add_query_arg( 'action', 'lgd_2fa', wp_login_url() );
		if ( $interim ) {
			$url = add_query_arg( 'interim-login', '1', $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	private static function token(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- random token.
	}

	private static function pending_hmac( int $uid, string $token ): string {
		return hash_hmac( 'sha256', '2fa|' . $uid . '|' . $token, wp_salt( 'auth' ) );
	}

	private static function cookie( string $name, string $value, int $expires ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			$name,
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		if ( $expires < time() ) {
			unset( $_COOKIE[ $name ] );
		}
	}

	/**
	 * The pending step of this browser.
	 *
	 * @return array array( WP_User, record ) or array().
	 */
	private static function pending(): array {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by the pattern.
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		if ( ! preg_match( '/^(\d{1,20})\.([A-Za-z0-9_-]{43})$/', $raw, $m ) ) {
			return array();
		}
		$uid  = (int) $m[1];
		$rec  = get_user_meta( $uid, self::PENDING, true );
		$user = get_userdata( $uid );
		if ( ! is_array( $rec ) || ! isset( $rec['h'], $rec['x'] ) || (int) $rec['x'] < time() || ! $user instanceof WP_User ) {
			return array();
		}
		if ( ! hash_equals( (string) $rec['h'], self::pending_hmac( $uid, $m[2] ) ) ) {
			return array();
		}
		return array( $user, $rec );
	}

	private static function end_pending( int $uid ): void {
		delete_user_meta( $uid, self::PENDING );
		self::cookie( self::COOKIE, '', time() - YEAR_IN_SECONDS );
	}

	/** login_form_lgd_2fa: the code screen. */
	public static function page(): void {
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
		try {
			$p = self::active() ? self::pending() : array();
			if ( array() === $p ) {
				self::render( LGD_Settings::text( 'tfa_expired' ), true, false );
			}
			list( $user, $rec ) = $p;
			$uid                = (int) $user->ID;
			if ( ! self::is_on( $uid ) ) {
				self::end_pending( $uid );
				self::render( LGD_Settings::text( 'tfa_expired' ), true, false );
			}
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : '';
			if ( 'POST' !== $method ) {
				self::render( LGD_Settings::text( 'tfa_intro' ), false, true );
			}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- nonce.
			$nonce = isset( $_POST['_lgd_nonce'] ) ? sanitize_key( wp_unslash( (string) $_POST['_lgd_nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
				self::render( LGD_Settings::text( 'tfa_intro' ), true, true );
			}
			$ip = LGD_Store::ip();
			if ( LGD_Settings::live() ) {
				$err = LGD_Guard::lock_error( $ip, (string) $user->user_login );
				if ( $err ) {
					self::end_pending( $uid );
					self::render( wp_strip_all_tags( $err->get_error_message() ), true, false );
				}
			}
			// In every mode: a new password login does not reset this limit.
			if ( self::wrong_over( $uid ) ) {
				self::end_pending( $uid );
				self::render( LGD_Settings::text( 'tfa_expired' ), true, false );
			}
			$code = isset( $_POST['lgd_code'] ) && is_string( $_POST['lgd_code'] ) ? sanitize_text_field( wp_unslash( $_POST['lgd_code'] ) ) : '';
			$kind = '' !== $code ? self::check( $uid, $code ) : '';
			if ( '' === $kind ) {
				self::wrong( $user, $rec );
			}
			self::pass( $user, $rec, $kind, ! empty( $_POST['lgd_trust'] ) );
		} catch ( \Throwable $e ) {
			self::render( LGD_Settings::text( 'tfa_expired' ), true, false );
		}
	}

	private static function wrong( WP_User $user, array $rec ): void {
		$uid = (int) $user->ID;
		LGD_Guard::count( (string) $user->user_login );
		LGD_Store::log( '2fa_fail', LGD_Store::ip(), $uid );
		/**
		 * A wrong code at the second step.
		 *
		 * @param int $user_id
		 */
		do_action( 'lgd_2fa_failed', $uid );
		$total    = self::wrong_bump( $uid );
		$rec['n'] = ( isset( $rec['n'] ) ? (int) $rec['n'] : 0 ) + 1;
		if ( $rec['n'] >= self::TRIES || $total >= self::WRONG_MAX ) {
			self::end_pending( $uid );
			self::render( LGD_Settings::text( 'tfa_expired' ), true, false );
		}
		update_user_meta( $uid, self::PENDING, $rec );
		self::render( LGD_Settings::text( 'tfa_wrong' ), true, true );
	}

	private static function pass( WP_User $user, array $rec, string $kind, bool $trust ): void {
		$uid = (int) $user->ID;
		// One use: only the request that removes the pending step goes on.
		if ( ! delete_user_meta( $uid, self::PENDING, $rec ) ) {
			self::render( LGD_Settings::text( 'tfa_expired' ), true, false );
		}
		self::cookie( self::COOKIE, '', time() - YEAR_IN_SECONDS );
		self::$passed = $uid;
		wp_set_auth_cookie( $uid, ! empty( $rec['r'] ), is_ssl() );
		$days = (int) LGD_Settings::get()['tfa_trust_days'];
		if ( $trust && $days > 0 ) {
			$exp = time() + $days * DAY_IN_SECONDS;
			self::cookie( self::TRUST . $uid, $exp . '.' . self::trust_hmac( $user, $exp ), $exp );
		}
		if ( 'rc' === $kind ) {
			$left = count( (array) self::rec( $uid )['rc'] );
			LGD_Store::log( '2fa_rc', LGD_Store::ip(), $uid, (string) $left );
			self::mail( $user, 'rc', $left );
		}
		/** Core's own action, which wp_signon() did not reach. */
		do_action( 'wp_login', $user->user_login, $user );
		/**
		 * The second step passed.
		 *
		 * @param int $user_id
		 */
		do_action( 'lgd_2fa_passed', $uid );

		if ( ! empty( $rec['i'] ) ) {
			global $interim_login;
			$interim_login = 'success'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- wp-login.php's own flag for the re-login modal.
			// WordPress's own string.
			self::render( __( 'You have logged in successfully.' ), false, false ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
		}
		$requested = isset( $rec['to'] ) ? (string) $rec['to'] : '';
		$to        = '' !== $requested ? $requested : admin_url();
		$to        = (string) apply_filters( 'login_redirect', $to, $requested, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core filter.
		wp_safe_redirect( '' !== $to ? $to : admin_url() );
		exit;
	}

	/** Prints the screen with wp-login.php's header and footer, then exits. */
	private static function render( string $text, bool $error, bool $form ): void {
		$title = LGD_Settings::text( 'tfa_title' );
		$msg   = '<p class="' . ( $error ? 'message lgd-error' : 'message' ) . '">' . esc_html( $text ) . '</p>';
		if ( function_exists( 'login_header' ) ) {
			login_header( $title, $msg );
		} else {
			echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>' . esc_html( $title ) . '</title></head><body>' . wp_kses_post( $msg );
		}
		global $interim_login;
		if ( $form ) {
			$days   = (int) LGD_Settings::get()['tfa_trust_days'];
			$action = add_query_arg( 'action', 'lgd_2fa', wp_login_url() );
			if ( ! empty( $interim_login ) ) {
				$action = add_query_arg( 'interim-login', '1', $action );
			}
			?>
			<form name="lgd2faform" id="loginform" action="<?php echo esc_url( $action ); ?>" method="post" autocomplete="off">
				<p>
					<label for="lgd_code"><?php echo esc_html( LGD_Settings::text( 'tfa_field' ) ); ?></label>
					<input type="text" name="lgd_code" id="lgd_code" class="input" value="" size="20" inputmode="numeric" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" maxlength="11" required="required" autofocus="autofocus" />
				</p>
				<?php if ( $days > 0 ) : ?>
				<p class="forgetmenot"><input name="lgd_trust" type="checkbox" id="lgd_trust" value="1" /> <label for="lgd_trust"><?php echo esc_html( LGD_Settings::text( 'tfa_trust', array( 'days' => $days ) ) ); ?></label></p>
				<?php endif; ?>
				<input type="hidden" name="_lgd_nonce" value="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>" />
				<p class="submit"><input type="submit" class="button button-primary button-large" value="<?php echo esc_attr( LGD_Settings::text( 'tfa_go' ) ); ?>" /></p>
			</form>
			<?php
		}
		if ( 'success' !== $interim_login ) {
			// WordPress's own string, in the visitor's language.
			echo '<p id="nav"><a href="' . esc_url( wp_login_url() ) . '">' . esc_html( __( 'Log in' ) ) . '</a></p>'; // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
		}
		if ( function_exists( 'login_footer' ) ) {
			login_footer();
		} else {
			echo '</body></html>';
		}
		exit;
	}

	/* =====================================================================
	 * Remembered browsers
	 * =================================================================== */

	/** HMAC over the user id, expiry, browser version and a fragment of the password hash. */
	private static function trust_hmac( WP_User $user, int $exp ): string {
		$uid  = (int) $user->ID;
		$frag = substr( (string) $user->user_pass, -12 );
		return hash_hmac( 'sha256', 'td|' . $uid . '|' . $exp . '|' . (string) get_user_meta( $uid, self::TV, true ) . '|' . $frag . '|' . ( isset( self::rec( $uid )['on'] ) ? (int) self::rec( $uid )['on'] : 0 ), wp_salt( 'auth' ) );
	}

	private static function trusted_browser( WP_User $user ): bool {
		if ( (int) LGD_Settings::get()['tfa_trust_days'] <= 0 ) {
			return false;
		}
		$name = self::TRUST . (int) $user->ID;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by the pattern.
		$raw = isset( $_COOKIE[ $name ] ) ? (string) wp_unslash( $_COOKIE[ $name ] ) : '';
		if ( ! preg_match( '/^(\d{9,11})\.([a-f0-9]{64})$/', $raw, $m ) || (int) $m[1] < time() ) {
			return false;
		}
		return hash_equals( self::trust_hmac( $user, (int) $m[1] ), $m[2] );
	}

	/* =====================================================================
	 * Strict guard
	 * =================================================================== */

	/** set_auth_cookie: a session for a user with 2FA who skipped the second step. */
	public static function guard( $cookie, $expire = 0, $expiration = 0, $user_id = 0, $scheme = '', $token = '' ): void {
		try {
			$uid = (int) $user_id;
			if ( ! self::active() || ! LGD_Settings::get()['tfa_strict'] || self::$passed === $uid || ! self::is_on( $uid ) ) {
				return;
			}
			if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
				return;
			}
			if ( self::$cookie_uid === $uid && $uid > 0 ) {
				return; // A fresh cookie for the user this request's own cookie logged in (e.g. a password change).
			}
			if ( ! LGD_Settings::live() ) {
				LGD_Store::log( 'test_session', LGD_Store::ip(), $uid );
				return;
			}
			if ( is_string( $token ) && '' !== $token && class_exists( 'WP_Session_Tokens' ) ) {
				WP_Session_Tokens::get_instance( $uid )->destroy( $token );
			}
			self::$blocked = $uid;
			LGD_Store::log( 'session_block', LGD_Store::ip(), $uid );
			/**
			 * The strict guard ended a session that skipped the second step.
			 *
			 * @param int $user_id
			 */
			do_action( 'lgd_session_blocked', $uid );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** wp_login (1): clears the cookies the guard made useless; counts grace logins. */
	public static function after_login( $login, $user = null ): void {
		try {
			if ( ! $user instanceof WP_User ) {
				return;
			}
			if ( self::$blocked === (int) $user->ID ) {
				wp_clear_auth_cookie();
				wp_set_current_user( 0 );
				return;
			}
			if ( self::active() && ! self::is_on( (int) $user->ID ) && self::required( $user ) ) {
				$g      = get_user_meta( (int) $user->ID, self::GRACE, true );
				$g      = is_array( $g ) ? $g : array();
				$g['t'] = isset( $g['t'] ) ? (int) $g['t'] : time();
				$g['n'] = ( isset( $g['n'] ) ? (int) $g['n'] : 0 ) + 1;
				update_user_meta( (int) $user->ID, self::GRACE, $g );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/* =====================================================================
	 * Required 2FA in the admin
	 * =================================================================== */

	/** admin_init: after the grace period, only the profile until 2FA is on. */
	public static function enforce(): void {
		try {
			if ( wp_doing_ajax() || ! self::active() || ! is_user_logged_in() ) {
				return;
			}
			$user = wp_get_current_user();
			if ( ! self::grace_over( $user ) ) {
				return;
			}
			global $pagenow;
			if ( in_array( (string) $pagenow, array( 'profile.php', 'admin-post.php', 'async-upload.php' ), true ) ) {
				return;
			}
			wp_safe_redirect( admin_url( 'profile.php#lgd-2fa' ) );
			exit;
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** admin_notices: «Set up 2FA» for a user whose role requires it. */
	public static function nag(): void {
		if ( ! self::active() || ! is_user_logged_in() ) {
			return;
		}
		$user = wp_get_current_user();
		if ( self::is_on( (int) $user->ID ) || ! self::required( $user ) ) {
			return;
		}
		$over = self::grace_over( $user );
		$msg  = $over
			? __( 'Ο ρόλος σου χρειάζεται επαλήθευση σε δύο βήματα. Ρύθμισέ την εδώ για να συνεχίσεις στο admin.', 'login-guard' )
			: __( 'Ο ρόλος σου χρειάζεται επαλήθευση σε δύο βήματα. Ρύθμισέ την στο προφίλ σου πριν λήξει η περίοδος χάριτος.', 'login-guard' );
		printf(
			'<div class="notice %1$s"><p>%2$s <a href="%3$s">%4$s</a></p></div>',
			$over ? 'notice-error' : 'notice-warning',
			esc_html( $msg ),
			esc_url( admin_url( 'profile.php#lgd-2fa' ) ),
			esc_html__( 'Ρύθμιση τώρα', 'login-guard' )
		);
	}

	/* =====================================================================
	 * E-mails
	 * =================================================================== */

	/**
	 * Security notice to the user, in the user's language.
	 *
	 * @param string $what 'on' | 'off' | 'reset' | 'rc'.
	 */
	public static function mail( WP_User $user, string $what, int $left = 0 ): void {
		try {
			$prev = LGD_Lang::force( LGD_Lang::lang_of( $user ) );
			$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
			/* translators: %s: site name */
			$subject = sprintf( __( 'Επαλήθευση σε δύο βήματα στο %s', 'login-guard' ), $site );
			switch ( $what ) {
				case 'on':
					/* translators: 1: user login, 2: site name */
					$first = sprintf( __( 'Η επαλήθευση σε δύο βήματα ενεργοποιήθηκε για τον λογαριασμό %1$s στο %2$s.', 'login-guard' ), $user->user_login, $site );
					break;
				case 'off':
					/* translators: 1: user login, 2: site name */
					$first = sprintf( __( 'Η επαλήθευση σε δύο βήματα απενεργοποιήθηκε για τον λογαριασμό %1$s στο %2$s.', 'login-guard' ), $user->user_login, $site );
					break;
				case 'reset':
					/* translators: 1: user login, 2: site name */
					$first = sprintf( __( 'Ένας διαχειριστής μηδένισε την επαλήθευση σε δύο βήματα του λογαριασμού %1$s στο %2$s. Ρύθμισέ την ξανά από το προφίλ σου.', 'login-guard' ), $user->user_login, $site );
					break;
				case 'codes':
					/* translators: 1: user login, 2: site name */
					$first = sprintf( __( 'Έγιναν νέοι κωδικοί ανάκτησης για τον λογαριασμό %1$s στο %2$s. Οι παλιοί δεν δουλεύουν πια.', 'login-guard' ), $user->user_login, $site );
					break;
				default:
					/* translators: 1: user login, 2: site name, 3: codes left */
					$first = sprintf( __( 'Στη σύνδεση του λογαριασμού %1$s στο %2$s χρησιμοποιήθηκε ένας κωδικός ανάκτησης. Απομένουν %3$d.', 'login-guard' ), $user->user_login, $site, $left );
			}
			$lines = array(
				$first,
				'',
				/* translators: %s: date and time */
				sprintf( __( 'Ώρα: %s', 'login-guard' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ),
				'',
				__( 'Αν δεν ήσουν εσύ, άλλαξε αμέσως τον κωδικό σου και αποσύνδεσε όλες τις άλλες συσκευές από το προφίλ σου:', 'login-guard' ),
				admin_url( 'profile.php' ),
			);
			wp_mail( (string) $user->user_email, $subject, implode( "\n", $lines ) );
			LGD_Lang::force( $prev );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/* =====================================================================
	 * Overview
	 * =================================================================== */

	/** Counts for the overview and the ability: users with 2FA, and users who need it. */
	public static function summary( int $list = 50 ): array {
		$on = get_users(
			array(
				'meta_key'     => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery -- admin page, few users.
				'meta_compare' => 'EXISTS',
				'fields'       => 'ID',
				'number'       => 2000,
			)
		);
		$roles   = (array) LGD_Settings::get()['tfa_roles'];
		$missing = array();
		$n       = 0;
		if ( LGD_Settings::live() && array() !== $roles ) {
			foreach ( get_users(
				array(
					'role__in' => $roles,
					'number'   => 2000,
				)
			) as $u ) {
				if ( ! self::is_on( (int) $u->ID ) ) {
					++$n;
					if ( count( $missing ) < $list ) {
						$missing[] = $u;
					}
				}
			}
		}
		return array(
			'on'      => count( $on ),
			'missing' => $n,
			'list'    => $missing,
		);
	}

	/** Users with Application Passwords: array( WP_User, count, last used time ). */
	public static function app_passwords( int $max = 100 ): array {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return array();
		}
		$out = array();
		foreach ( get_users(
			array(
				'meta_key'     => WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, // phpcs:ignore WordPress.DB.SlowDBQuery -- admin page, few users.
				'meta_compare' => 'EXISTS',
				'number'       => $max,
			)
		) as $u ) {
			$list = WP_Application_Passwords::get_user_application_passwords( (int) $u->ID );
			if ( ! $list ) {
				continue;
			}
			$last = 0;
			foreach ( $list as $p ) {
				$last = max( $last, isset( $p['last_used'] ) ? (int) $p['last_used'] : 0 );
			}
			$out[] = array( $u, count( $list ), $last );
		}
		return $out;
	}
}

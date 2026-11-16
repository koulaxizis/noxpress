<?php
/**
 * LGD_Guard — the login gate, failure counting and the lost-password
 * limits.
 *
 * Where passwords are checked:
 *  - wp_authenticate() (wp-login.php, the WooCommerce login forms, XML-RPC,
 *    wp_signon() of any plugin): filter authenticate at 99999, after every
 *    check of WordPress and other plugins. A locked IP or account gets the
 *    lockout message whether the password was right or wrong, so the answer
 *    says nothing. Failures arrive on wp_login_failed.
 *  - Application Passwords (REST, Basic auth): the action
 *    wp_authenticate_application_password_errors rejects a right password
 *    from a locked IP or account; failures arrive on
 *    application_password_failed_authentication.
 *
 * Counting (mode test or live; trusted addresses never count):
 *  - per IP: ip_fails in ip_window minutes → ip_lock minutes; the
 *    esc_count-th lockout of the same IP in 24 hours lasts esc_hours.
 *  - per account (any IP): acct_fails in acct_window → acct_lock minutes
 *    for password logins to that account; the unlock e-mail (LGD_Unlock)
 *    ends it early.
 *  - in test mode a lockout is only logged («would lock») and nobody is
 *    blocked.
 *
 * Lost password (wp-login.php and WooCommerce, live mode): REQ_PER_IP and
 * REQ_PER_ACCT requests an hour, counted before the account is looked up;
 * and with enum_errors an unknown name gets the same «check your e-mail»
 * page as a known one.
 */

defined( 'ABSPATH' ) || exit;

final class LGD_Guard {

	/** Error codes of WordPress that tell whether an account exists. */
	const LEAKY = array( 'invalid_username', 'invalid_email', 'incorrect_password', 'invalidcombo' );

	public static function init(): void {
		add_filter( 'authenticate', array( __CLASS__, 'gate' ), 99999, 3 );
		add_action( 'wp_login_failed', array( __CLASS__, 'failed' ), 10, 2 );
		add_filter( 'determine_current_user', array( __CLASS__, 'gate_basic' ), 19 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'gate_rest' ), 1000 );
		add_action( 'wp_authenticate_application_password_errors', array( __CLASS__, 'gate_app' ), 10, 2 );
		add_action( 'application_password_failed_authentication', array( __CLASS__, 'failed_app' ) );
		add_action( 'lostpassword_post', array( __CLASS__, 'lost_limit' ), 10, 2 );
		add_action( 'lostpassword_post', array( __CLASS__, 'lost_neutral_wc' ), 99, 2 );
		add_filter( 'lostpassword_errors', array( __CLASS__, 'lost_neutral' ), 99, 2 );
	}

	/* =====================================================================
	 * Gate
	 * =================================================================== */

	/** authenticate filter (last): lockouts and the neutral error. */
	public static function gate( $user, $username = '', $password = '' ) {
		try {
			$username = is_string( $username ) ? trim( $username ) : '';
			if ( '' === $username || ! LGD_Settings::live() ) {
				return $user;
			}
			$ip = LGD_Store::ip();
			if ( ! LGD_Store::trusted( $ip ) ) {
				$err = self::lock_error( $ip, $username );
				if ( $err ) {
					return $err;
				}
			}
			return self::neutral( $user );
		} catch ( \Throwable $e ) {
			return $user;
		}
	}

	/**
	 * The lockout error for this IP or account, or null.
	 *
	 * @return WP_Error|null
	 */
	public static function lock_error( string $ip, string $ident ) {
		$left = '' !== $ip ? LGD_Store::locked( LGD_Store::key_ip( $ip ) ) : 0;
		if ( $left > 0 ) {
			return new WP_Error( 'lgd_locked', esc_html( LGD_Settings::text( 'locked_ip', array( 'minutes' => (int) ceil( $left / 60 ) ) ) ) );
		}
		$left = '' !== $ident ? LGD_Store::locked( LGD_Store::key_ident( $ident ) ) : 0;
		if ( $left > 0 ) {
			$msg = esc_html( LGD_Settings::text( 'locked_acct', array( 'minutes' => (int) ceil( $left / 60 ) ) ) );
			if ( LGD_Settings::get()['unlock_mail'] ) {
				$msg .= ' <a href="' . esc_url( LGD_Unlock::url() ) . '">' . esc_html( LGD_Settings::text( 'unlock_link' ) ) . '</a>';
			}
			return new WP_Error( 'lgd_locked_acct', $msg );
		}
		return null;
	}

	/** «Wrong username or password» in one form, whatever WordPress found. */
	public static function neutral( $user ) {
		if ( ! is_wp_error( $user ) || ! LGD_Settings::get()['enum_errors'] ) {
			return $user;
		}
		if ( array_intersect( self::LEAKY, $user->get_error_codes() ) ) {
			return new WP_Error( 'lgd_invalid', esc_html( LGD_Settings::text( 'invalid' ) ) );
		}
		return $user;
	}

	/** Lock error found by gate_basic() for this request, or null. */
	private static $basic_lock = null;

	/**
	 * determine_current_user (19, before wp_validate_application_password at
	 * 20): a Basic-auth request from a locked IP or for a locked account is
	 * refused before any password check, so a right and a wrong password
	 * get the same 429 (no password oracle while locked).
	 */
	public static function gate_basic( $user_id ) {
		try {
			if ( ! empty( $user_id ) || ! isset( $_SERVER['PHP_AUTH_USER'] ) || ! LGD_Settings::live() ) {
				return $user_id;
			}
			$ip = LGD_Store::ip();
			if ( LGD_Store::trusted( $ip ) ) {
				return $user_id;
			}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only hashed.
			$name = sanitize_user( wp_unslash( (string) $_SERVER['PHP_AUTH_USER'] ) );
			$err  = self::lock_error( $ip, $name );
			if ( $err ) {
				self::$basic_lock = new WP_Error( $err->get_error_code(), wp_strip_all_tags( $err->get_error_message() ), array( 'status' => 429 ) );
				unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
			}
		} catch ( \Throwable $e ) {
			return $user_id;
		}
		return $user_id;
	}

	/** rest_authentication_errors: the 429 of gate_basic(). */
	public static function gate_rest( $result ) {
		return null !== self::$basic_lock ? self::$basic_lock : $result;
	}

	/** Application Passwords: a right password from a locked IP or account is refused (second line, after gate_basic()). */
	public static function gate_app( $error, $user = null ): void {
		try {
			if ( ! $error instanceof WP_Error || ! $user instanceof WP_User || ! LGD_Settings::live() ) {
				return;
			}
			$ip = LGD_Store::ip();
			if ( LGD_Store::trusted( $ip ) ) {
				return;
			}
			$err = self::lock_error( $ip, (string) $user->user_login );
			if ( $err ) {
				$error->add( $err->get_error_code(), wp_strip_all_tags( $err->get_error_message() ), array( 'status' => 429 ) );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/* =====================================================================
	 * Counting
	 * =================================================================== */

	/** wp_login_failed: ( $username, $error ). */
	public static function failed( $username, $error = null ): void {
		try {
			if ( $error instanceof WP_Error && array_intersect( array( 'lgd_locked', 'lgd_locked_acct' ), $error->get_error_codes() ) ) {
				return; // Already locked: not counted again.
			}
			self::count( is_string( $username ) ? $username : '' );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** application_password_failed_authentication: ( $error ). */
	public static function failed_app( $error ): void {
		try {
			if ( $error instanceof WP_Error && array_intersect( array( 'lgd_locked', 'lgd_locked_acct' ), $error->get_error_codes() ) ) {
				return;
			}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only hashed.
			$name = isset( $_SERVER['PHP_AUTH_USER'] ) ? sanitize_user( wp_unslash( (string) $_SERVER['PHP_AUTH_USER'] ) ) : '';
			self::count( $name );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** One failed login for the visitor's IP and the typed account. */
	public static function count( string $ident ): void {
		$ip = LGD_Store::ip();
		if ( LGD_Store::trusted( $ip ) ) {
			return;
		}
		$s = LGD_Settings::get();
		LGD_Store::stats_hit();

		if ( '' !== $ip ) {
			$kip = LGD_Store::key_ip( $ip );
			if ( ! LGD_Store::locked( $kip ) && LGD_Store::bump( 'lgd_f_' . $kip, (int) $s['ip_window'] * MINUTE_IN_SECONDS ) >= (int) $s['ip_fails'] ) {
				self::lock_ip( $kip, $ip );
			}
		}

		$ident = trim( $ident );
		if ( '' !== $ident ) {
			$kac = LGD_Store::key_ident( $ident );
			if ( ! LGD_Store::locked( $kac ) && LGD_Store::bump( 'lgd_f_' . $kac, (int) $s['acct_window'] * MINUTE_IN_SECONDS ) >= (int) $s['acct_fails'] ) {
				$user = LGD_Store::find_user( $ident );
				self::lock_user( $kac, $user ? (int) $user->ID : 0, $ip );
			}
		}
	}

	private static function lock_ip( string $key, string $ip ): void {
		$s = LGD_Settings::get();
		if ( ! LGD_Settings::live() ) {
			delete_transient( 'lgd_f_' . $key );
			LGD_Store::log( 'test_ip', $ip );
			return;
		}
		$n     = LGD_Store::bump( 'lgd_c_' . $key, DAY_IN_SECONDS );
		$long  = $n >= (int) $s['esc_count'];
		$secs  = $long ? (int) $s['esc_hours'] * HOUR_IN_SECONDS : (int) $s['ip_lock'] * MINUTE_IN_SECONDS;
		$until = LGD_Store::lock(
			$key,
			$secs,
			array(
				'k'  => 'ip',
				'ip' => $ip,
			)
		);
		LGD_Store::log( $long ? 'lock_long' : 'lock_ip', $ip, 0, (string) (int) ceil( $secs / 60 ) . ' min' );
		/**
		 * A lockout started.
		 *
		 * @param string $kind       'ip' | 'user'.
		 * @param string $ident_hash The hashed key (never an IP or name).
		 * @param int    $until      Unix time when it ends.
		 */
		do_action( 'lgd_locked', 'ip', $key, $until );
		LGD_Notify::surge();
	}

	private static function lock_user( string $key, int $uid, string $ip ): void {
		$s = LGD_Settings::get();
		if ( ! LGD_Settings::live() ) {
			delete_transient( 'lgd_f_' . $key );
			LGD_Store::log( 'test_user', $ip, $uid );
			return;
		}
		$secs  = (int) $s['acct_lock'] * MINUTE_IN_SECONDS;
		$until = LGD_Store::lock(
			$key,
			$secs,
			array(
				'k'   => 'user',
				'uid' => $uid,
			)
		);
		LGD_Store::log( 'lock_user', $ip, $uid, (string) (int) ceil( $secs / 60 ) . ' min' );
		do_action( 'lgd_locked', 'user', $key, $until );
		LGD_Notify::surge();
	}

	/* =====================================================================
	 * Lost password
	 * =================================================================== */

	/** The name typed in a lost-password form (core and WooCommerce use user_login). */
	private static function typed_login(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only; core and WooCommerce verify their own forms.
		return isset( $_POST['user_login'] ) && is_string( $_POST['user_login'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) ) ) : '';
	}

	/**
	 * Request limits, counted before the account matters.
	 *
	 * @param WP_Error      $errors
	 * @param WP_User|false $user_data
	 */
	public static function lost_limit( $errors, $user_data = false ): void {
		try {
			if ( ! $errors instanceof WP_Error || ! LGD_Settings::live() || ! LGD_Settings::get()['lostpw'] ) {
				return;
			}
			if ( self::over_requests( 'l', self::typed_login() ) ) {
				$errors->add( 'lgd_limited', esc_html( LGD_Settings::text( 'limited' ) ) );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Counts one request (lost password 'l' or unlock 'k') for the IP and
	 * the typed account; true when a limit was already reached. Logs the
	 * first refusal of a window only.
	 */
	public static function over_requests( string $kind, string $ident ): bool {
		$ip = LGD_Store::ip();
		if ( LGD_Store::trusted( $ip ) ) {
			return false;
		}
		$over  = false;
		$first = false;
		if ( '' !== $ip ) {
			$n     = LGD_Store::bump( 'lgd_r_' . LGD_Store::key_ip( $ip ) . $kind, HOUR_IN_SECONDS );
			$over  = $n > LGD_Settings::REQ_PER_IP;
			$first = LGD_Settings::REQ_PER_IP + 1 === $n;
		}
		if ( '' !== $ident ) {
			$m     = LGD_Store::bump( 'lgd_r_' . LGD_Store::key_ident( $ident ) . $kind, HOUR_IN_SECONDS );
			$over  = $over || $m > LGD_Settings::REQ_PER_ACCT;
			$first = $first || LGD_Settings::REQ_PER_ACCT + 1 === $m;
		}
		if ( $over && $first ) {
			LGD_Store::log( 'l' === $kind ? 'limit_lost' : 'limit_unl', $ip );
		}
		return $over;
	}

	/** True when only «no such account» errors are present (nothing else to tell the visitor). */
	private static function only_unknown( WP_Error $errors ): bool {
		$codes = $errors->get_error_codes();
		return ! array_diff( $codes, array( 'invalid_email', 'invalidcombo' ) );
	}

	/**
	 * wp-login.php: an unknown name gets the «check your e-mail» page.
	 *
	 * @param WP_Error      $errors
	 * @param WP_User|false $user_data
	 */
	public static function lost_neutral( $errors, $user_data = false ) {
		try {
			if ( ! $errors instanceof WP_Error || $user_data || ! LGD_Settings::live() || ! LGD_Settings::get()['enum_errors'] ) {
				return $errors;
			}
			if ( '' !== self::typed_login() && self::only_unknown( $errors ) && did_action( 'login_init' ) ) {
				// Byte for byte what wp-login.php does on success, so the
				// Location header is the same for a known and an unknown name.
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- same handling as wp-login.php; wp_safe_redirect() validates.
				$to = ! empty( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : 'wp-login.php?checkemail=confirm';
				wp_safe_redirect( $to );
				exit;
			}
		} catch ( \Throwable $e ) {
			return $errors;
		}
		return $errors;
	}

	/**
	 * WooCommerce's lost-password form: an unknown name gets the «reset
	 * link sent» page (WooCommerce has no lostpassword_errors filter).
	 *
	 * @param WP_Error      $errors
	 * @param WP_User|false $user_data
	 */
	public static function lost_neutral_wc( $errors, $user_data = false ): void {
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified its nonce before this action.
			if ( $user_data || ! isset( $_POST['wc_reset_password'] ) || ! function_exists( 'wc_get_account_endpoint_url' ) ) {
				return;
			}
			if ( ! $errors instanceof WP_Error || $errors->has_errors() || ! LGD_Settings::live() || ! LGD_Settings::get()['enum_errors'] || '' === self::typed_login() ) {
				return;
			}
			wp_safe_redirect( add_query_arg( 'reset-link-sent', 'true', wc_get_account_endpoint_url( 'lost-password' ) ) );
			exit;
		} catch ( \Throwable $e ) {
			return;
		}
	}
}

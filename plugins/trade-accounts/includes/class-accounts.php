<?php
/**
 * TRA_Accounts — trade accounts: the «Χονδρική» role, the account's
 * discount and API switch, and the Application Passwords of the feed.
 *
 * A trade account is an ordinary WordPress user with the role
 * tra_wholesale (capability "read" only, like a WooCommerce customer), so
 * it logs in, orders and sees its orders in My account as any customer.
 * Accounts are made by the shop (no self sign-up): from the plugin's
 * Accounts tab, or by giving an existing customer the role in Users.
 *
 * User meta (all deleted on uninstall; the users stay):
 *  - tra_discount  '' (the plugin's discount) or a percentage 0–90
 *  - tra_api       '1' when the account may use the Trade Feed
 *  - tra_api_seen  array( t, route, n, status ) of the last feed call
 *
 * Feed credentials: WordPress Application Passwords (Bible §20), made by
 * the plugin with its own app_id so that they can be listed and revoked
 * without touching the account's other passwords. The plain password
 * exists only in the answer of create_new_application_password() and is
 * shown once.
 */

defined( 'ABSPATH' ) || exit;

final class TRA_Accounts {

	const ROLE     = 'tra_wholesale';
	const APP_ID   = '5d3c8f0e-6a1b-4c2d-9e7f-3b4a5c6d7e8f';
	const APP_NAME = 'Trade Feed';

	/** @var array<int,bool> Per-request cache of is_trade(). */
	private static $trade = array();

	public static function init(): void {
		// The role is added on activation; this covers updates from a zip
		// and sites where someone removed it (one get_role() per request).
		add_action( 'init', array( __CLASS__, 'add_role' ), 5 );
		add_action( 'set_user_role', array( __CLASS__, 'flush' ) );
		add_action( 'add_user_role', array( __CLASS__, 'flush' ) );
		add_action( 'remove_user_role', array( __CLASS__, 'flush' ) );
	}

	public static function add_role(): void {
		if ( ! get_role( self::ROLE ) ) {
			// The label is stored as is; WordPress shows role names through
			// translate_user_role(), so it stays Greek in every language.
			add_role( self::ROLE, 'Χονδρική', array( 'read' => true ) );
		}
	}

	public static function flush(): void {
		self::$trade = array();
	}

	private static function uid( int $user_id ): int {
		return $user_id > 0 ? $user_id : get_current_user_id();
	}

	/** Whether a user has the «Χονδρική» role. 0 = current user. */
	public static function is_trade( int $user_id = 0 ): bool {
		$uid = self::uid( $user_id );
		if ( $uid <= 0 ) {
			return false;
		}
		if ( ! isset( self::$trade[ $uid ] ) ) {
			$user                = get_userdata( $uid );
			self::$trade[ $uid ] = $user instanceof WP_User && in_array( self::ROLE, (array) $user->roles, true );
		}
		return self::$trade[ $uid ];
	}

	/** The account's discount, %: its own, or the plugin's. */
	public static function discount( int $user_id = 0 ): float {
		$uid = self::uid( $user_id );
		$own = $uid > 0 ? get_user_meta( $uid, 'tra_discount', true ) : '';
		$d   = ( '' !== $own && is_numeric( $own ) ) ? TRA_Settings::percent( (float) $own ) : (float) TRA_Settings::get()['discount'];
		/**
		 * Filters the discount of a trade account, in percent.
		 *
		 * @param float $d   Discount, 0–90.
		 * @param int   $uid User id.
		 */
		return TRA_Settings::percent( (float) apply_filters( 'tra_discount', $d, $uid ) );
	}

	/** Whether the account may use the Trade Feed. */
	public static function api_on( int $user_id ): bool {
		return $user_id > 0 && '1' === (string) get_user_meta( $user_id, 'tra_api', true );
	}

	/** Trade accounts, by display name. */
	public static function all(): array {
		return get_users(
			array(
				'role'    => self::ROLE,
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'number'  => 500,
			)
		);
	}

	public static function count(): int {
		$c = count_users();
		return isset( $c['avail_roles'][ self::ROLE ] ) ? (int) $c['avail_roles'][ self::ROLE ] : 0;
	}

	/**
	 * A new trade account, or an existing customer made one. Staff (users
	 * who can edit posts or manage the shop) are never changed.
	 *
	 * @return int|WP_Error User id.
	 */
	public static function create( array $in ) {
		$email = isset( $in['email'] ) ? sanitize_email( (string) $in['email'] ) : '';
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'tra_email', __( 'Το email δεν είναι έγκυρο.', 'trade-accounts' ) );
		}
		$first   = isset( $in['first'] ) ? mb_substr( sanitize_text_field( (string) $in['first'] ), 0, 60 ) : '';
		$last    = isset( $in['last'] ) ? mb_substr( sanitize_text_field( (string) $in['last'] ), 0, 60 ) : '';
		$company = isset( $in['company'] ) ? mb_substr( sanitize_text_field( (string) $in['company'] ), 0, 120 ) : '';

		$existing = get_user_by( 'email', $email );
		if ( $existing instanceof WP_User ) {
			if ( user_can( $existing, 'edit_posts' ) || user_can( $existing, 'manage_woocommerce' ) ) {
				return new WP_Error( 'tra_staff', __( 'Αυτό το email ανήκει σε λογαριασμό προσωπικού του site. Δεν αλλάζει σε χονδρική.', 'trade-accounts' ) );
			}
			$existing->set_role( self::ROLE );
			$uid = (int) $existing->ID;
		} else {
			$login = self::login_from( $email );
			$uid   = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 24, true, true ),
					'first_name'   => $first,
					'last_name'    => $last,
					'display_name' => '' !== $company ? $company : trim( $first . ' ' . $last ),
					'role'         => self::ROLE,
				)
			);
			if ( is_wp_error( $uid ) ) {
				return $uid;
			}
			$uid = (int) $uid;
			if ( ! empty( $in['notify'] ) ) {
				// WordPress's own e-mail with a link to set the password.
				wp_new_user_notification( $uid, null, 'user' );
			}
		}

		if ( '' !== $company ) {
			update_user_meta( $uid, 'billing_company', $company );
		}
		if ( '' !== $first && '' === (string) get_user_meta( $uid, 'billing_first_name', true ) ) {
			update_user_meta( $uid, 'billing_first_name', $first );
		}
		if ( '' !== $last && '' === (string) get_user_meta( $uid, 'billing_last_name', true ) ) {
			update_user_meta( $uid, 'billing_last_name', $last );
		}
		if ( '' === (string) get_user_meta( $uid, 'billing_email', true ) ) {
			update_user_meta( $uid, 'billing_email', $email );
		}
		self::flush();
		return $uid;
	}

	/** A free login name from the e-mail's local part. */
	private static function login_from( string $email ): string {
		$base = sanitize_user( strstr( $email, '@', true ), true );
		$base = '' !== $base ? mb_substr( $base, 0, 50 ) : 'trade';
		$login = $base;
		$i     = 2;
		while ( username_exists( $login ) ) {
			$login = $base . $i;
			++$i;
		}
		return $login;
	}

	/** Saves the account's own discount ('' = the plugin's) and API switch. */
	public static function update( int $uid, string $discount, bool $api ): void {
		if ( ! self::is_trade( $uid ) ) {
			return;
		}
		$discount = trim( str_replace( ',', '.', $discount ) );
		if ( '' === $discount || ! is_numeric( $discount ) ) {
			delete_user_meta( $uid, 'tra_discount' );
		} else {
			update_user_meta( $uid, 'tra_discount', (string) TRA_Settings::percent( (float) $discount ) );
		}
		if ( $api ) {
			update_user_meta( $uid, 'tra_api', '1' );
		} else {
			delete_user_meta( $uid, 'tra_api' );
			self::revoke( $uid );
		}
	}

	/**
	 * The account goes back to customer: no wholesale prices, no feed
	 * (its feed passwords are revoked). Its orders stay as they are.
	 */
	public static function remove( int $uid ): void {
		$user = get_userdata( $uid );
		if ( ! $user instanceof WP_User || ! in_array( self::ROLE, (array) $user->roles, true ) ) {
			return;
		}
		self::revoke( $uid );
		delete_user_meta( $uid, 'tra_api' );
		delete_user_meta( $uid, 'tra_discount' );
		delete_user_meta( $uid, 'tra_api_seen' );
		$user->set_role( get_role( 'customer' ) ? 'customer' : get_option( 'default_role', 'subscriber' ) );
		self::flush();
	}

	/** Whether this site can make Application Passwords for the user (HTTPS, not disabled). */
	public static function passwords_available( int $uid = 0 ): bool {
		if ( ! class_exists( 'WP_Application_Passwords' ) || ! function_exists( 'wp_is_application_passwords_available' ) || ! wp_is_application_passwords_available() ) {
			return false;
		}
		if ( $uid > 0 && function_exists( 'wp_is_application_passwords_available_for_user' ) ) {
			$user = get_userdata( $uid );
			return $user instanceof WP_User && wp_is_application_passwords_available_for_user( $user );
		}
		return true;
	}

	/**
	 * A new feed password for the account. Earlier feed passwords stop
	 * working: one account, one password in use.
	 *
	 * @return string|WP_Error The plain password (shown once).
	 */
	public static function new_password( int $uid ) {
		if ( ! self::is_trade( $uid ) ) {
			return new WP_Error( 'tra_not_trade', __( 'Ο χρήστης δεν είναι λογαριασμός χονδρικής.', 'trade-accounts' ) );
		}
		if ( ! self::passwords_available( $uid ) ) {
			return new WP_Error( 'tra_no_app_pw', __( 'Τα Application Passwords δεν είναι διαθέσιμα σε αυτό το site (χρειάζονται HTTPS και να μην τα έχει κλείσει κάποιο plugin ασφαλείας).', 'trade-accounts' ) );
		}
		self::revoke( $uid );
		$made = WP_Application_Passwords::create_new_application_password(
			$uid,
			array(
				'name'   => self::APP_NAME,
				'app_id' => self::APP_ID,
			)
		);
		if ( is_wp_error( $made ) ) {
			return $made;
		}
		update_user_meta( $uid, 'tra_api', '1' );
		return (string) $made[0];
	}

	/** The account's feed passwords (never the hashes): uuid, created, last_used. */
	public static function passwords( int $uid ): array {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) WP_Application_Passwords::get_user_application_passwords( $uid ) as $p ) {
			if ( isset( $p['app_id'] ) && self::APP_ID === $p['app_id'] ) {
				$out[] = array(
					'uuid'      => (string) $p['uuid'],
					'created'   => isset( $p['created'] ) ? (int) $p['created'] : 0,
					'last_used' => isset( $p['last_used'] ) ? (int) $p['last_used'] : 0,
				);
			}
		}
		return $out;
	}

	/** Deletes the account's feed passwords (its other Application Passwords stay). */
	public static function revoke( int $uid ): int {
		$n = 0;
		foreach ( self::passwords( $uid ) as $p ) {
			if ( true === WP_Application_Passwords::delete_application_password( $uid, $p['uuid'] ) ) {
				++$n;
			}
		}
		return $n;
	}

	/** Last feed call of the account: array( t, route, n, status ) or empty. */
	public static function seen( int $uid ): array {
		$s = get_user_meta( $uid, 'tra_api_seen', true );
		return is_array( $s ) && isset( $s['t'] ) ? $s : array();
	}
}

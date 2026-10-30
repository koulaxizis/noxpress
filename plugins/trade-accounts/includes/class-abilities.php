<?php
/**
 * TRA_Abilities — Trade Accounts in the WordPress Abilities API, for an AI
 * or a remote tool that runs abilities (e.g. Site Bridge's ability_run),
 * with the same capability as the settings (manage_woocommerce) and the
 * same code as the Accounts tab:
 *
 *  trade-accounts/status         modules, mode, discount, scope, how many
 *                                trade accounts and eligible products, the
 *                                latest feed calls (read; no passwords)
 *  trade-accounts/accounts-list  the trade accounts: id, username, name,
 *                                e-mail, discount, feed switch, feed
 *                                password made / last used, last call (read)
 *  trade-accounts/account-create a new trade account, or an existing
 *                                customer made one (staff never); own
 *                                discount, feed switch, WordPress e-mail
 *  trade-accounts/account-update the account's discount and feed switch
 *  trade-accounts/account-remove the account goes back to customer, its
 *                                feed password is revoked (destructive:
 *                                Site Bridge asks for "admin")
 *  trade-accounts/feed-password-send
 *                                a new feed password, e-mailed straight to
 *                                the account with its username and the feed
 *                                URL; the old one stops working (destructive:
 *                                a message to a third party)
 *
 * No ability ever returns a password: feed-password-send makes it, mails it
 * and forgets it, so it is never in the caller's answer, a log or a
 * transcript. account-create sends the WordPress e-mail only when asked
 * (notify = true); the form's checkbox is on by default, the ability's is
 * off, because a remote caller should never mail a customer by omission.
 *
 * The feed e-mail goes to a bookstore, not to an admin: it is written in
 * the account's own language (its user locale: el* → Greek, anything else
 * → English) as fixed texts in this class, not through the admin
 * dictionary (Bible §9 follows the admin's rs_lang, which is not the
 * bookstore's language).
 *
 * Registered only on WordPress 6.9+, where the Abilities API exists.
 */

defined( 'ABSPATH' ) || exit;

final class TRA_Abilities {

	const CAT = 'noxpress';

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	/** The shared "noxpress" category, once for every Noxpress plugin. */
	public static function category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CAT ) ) {
			return;
		}
		wp_register_ability_category(
			self::CAT,
			array(
				'label'       => 'Noxpress',
				'description' => 'Operations of the Noxpress plugins.',
			)
		);
	}

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$none     = array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
			'default'              => array(),
		);
		$id       = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => 'User id of the trade account (from accounts-list).',
		);
		$discount = array(
			'type'        => 'string',
			'description' => 'The account\'s own discount in percent, 0–90 (e.g. "35" or "37.5"); empty = the plugin\'s discount.',
		);
		$defs     = array(
			'status'             => array(
				'label'       => 'Trade Accounts: status',
				'description' => 'Modules, mode, default discount, eligible categories and brands, the number of trade accounts and eligible products, and the latest Trade Feed calls (account id, route, status, items). Changes nothing.',
				'input'       => $none,
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'status' ),
			),
			'accounts-list'      => array(
				'label'       => 'Trade Accounts: accounts',
				'description' => 'Every trade account (role «Χονδρική»): id, username, display name, company, e-mail, own discount and the discount that applies, whether the Trade Feed is on, its feed passwords (created, last used; never the password) and its last feed call. Changes nothing.',
				'input'       => $none,
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'accounts_list' ),
			),
			'account-create'     => array(
				'label'       => 'Trade Accounts: create an account',
				'description' => 'Makes a trade account, as the Accounts tab does: a new user with a random login password, or, if the e-mail belongs to a customer, that customer gets the «Χονδρική» role. Staff accounts (edit_posts or manage_woocommerce) are never changed. notify = true sends WordPress\'s own e-mail with a link to set a login password (new users only); default false. Returns the account as accounts-list does. For the feed password use feed-password-send.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'email'    => array(
							'type'   => 'string',
							'format' => 'email',
						),
						'company'  => array(
							'type'      => 'string',
							'maxLength' => 120,
						),
						'first'    => array(
							'type'      => 'string',
							'maxLength' => 60,
						),
						'last'     => array(
							'type'      => 'string',
							'maxLength' => 60,
						),
						'discount' => $discount,
						'api'      => array(
							'type'        => 'boolean',
							'description' => 'Trade Feed on for the account. Default true.',
						),
						'notify'   => array(
							'type'        => 'boolean',
							'description' => 'Send WordPress\'s e-mail to set a login password. Default false.',
						),
					),
					'required'             => array( 'email' ),
					'additionalProperties' => false,
				),
				'ann'         => array( false, false, false ),
				'run'         => array( __CLASS__, 'account_create' ),
			),
			'account-update'     => array(
				'label'       => 'Trade Accounts: change an account',
				'description' => 'Changes a trade account\'s own discount ("" = the plugin\'s) and / or its Trade Feed switch; a field left out keeps its value. Switching the feed off revokes its feed password. Returns the account as accounts-list does.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'       => $id,
						'discount' => $discount,
						'api'      => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'ann'         => array( false, false, true ),
				'run'         => array( __CLASS__, 'account_update' ),
			),
			'account-remove'     => array(
				'label'       => 'Trade Accounts: remove an account',
				'description' => 'The trade account goes back to customer: no trade prices, the feed switch and own discount are deleted and its feed passwords revoked. The user and its orders stay.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array( 'id' => $id ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'ann'         => array( false, true, true ),
				'run'         => array( __CLASS__, 'account_remove' ),
			),
			'feed-password-send' => array(
				'label'       => 'Trade Accounts: e-mail a feed password',
				'description' => 'Makes a new Trade Feed password for a trade account and e-mails it straight to the account\'s e-mail, with its username and the feed URL, in the account\'s language. The previous feed password stops working at once. The password is never returned. If the e-mail cannot be sent the new password is revoked too, so the account has none until this runs again or the owner presses «Νέος κωδικός».',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array( 'id' => $id ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'ann'         => array( false, true, false ),
				'run'         => array( __CLASS__, 'feed_password_send' ),
			),
		);
		foreach ( $defs as $name => $d ) {
			wp_register_ability(
				'trade-accounts/' . $name,
				array(
					'label'               => $d['label'],
					'description'         => $d['description'],
					'category'            => self::CAT,
					'input_schema'        => $d['input'],
					'execute_callback'    => $d['run'],
					'permission_callback' => array( __CLASS__, 'allowed' ),
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => $d['ann'][0],
							'destructive' => $d['ann'][1],
							'idempotent'  => $d['ann'][2],
						),
						'show_in_rest' => false,
					),
				)
			);
		}
	}

	public static function allowed(): bool {
		return current_user_can( Trade_Accounts::cap() );
	}

	/* =====================================================================
	 * Read
	 * =================================================================== */

	public static function status( $in = null ): array {
		$s    = TRA_Settings::get();
		$log  = get_option( 'tra_log', array() );
		$log  = is_array( $log ) ? array_slice( $log, 0, 20 ) : array();
		$rows = array();
		foreach ( $log as $l ) {
			if ( ! is_array( $l ) || ! isset( $l['t'] ) ) {
				continue;
			}
			$rows[] = array(
				'time'    => gmdate( 'c', (int) $l['t'] ),
				'account' => isset( $l['u'] ) ? (int) $l['u'] : 0,
				'route'   => isset( $l['r'] ) ? (string) $l['r'] : '',
				'status'  => isset( $l['s'] ) ? (int) $l['s'] : 0,
				'items'   => isset( $l['n'] ) ? (int) $l['n'] : 0,
			);
		}
		return array(
			'version'         => TRA_VERSION,
			'disabled'        => Trade_Accounts::killed(),
			'modules'         => $s['mods'],
			'mode'            => $s['mode'],
			'discount'        => $s['discount'],
			'base'            => $s['base'],
			'categories'      => $s['cats'],
			'brands'          => $s['brands'],
			'invoice'         => $s['invoice'],
			'rate_per_hour'   => $s['rate'],
			'accounts'        => TRA_Accounts::count(),
			'eligible'        => TRA_Scope::count(),
			'passwords_ready' => TRA_Accounts::passwords_available(),
			'feed_url'        => rest_url( 'tra/v1/products' ),
			'calls'           => $rows,
		);
	}

	public static function accounts_list( $in = null ): array {
		$out = array();
		foreach ( TRA_Accounts::all() as $u ) {
			$out[] = self::account( (int) $u->ID );
		}
		return array(
			'count'    => count( $out ),
			'accounts' => $out,
		);
	}

	/** One trade account as the abilities answer it (never a password). */
	private static function account( int $uid ): array {
		$u = get_userdata( $uid );
		if ( ! $u instanceof WP_User ) {
			return array( 'id' => $uid );
		}
		$own  = (string) get_user_meta( $uid, 'tra_discount', true );
		$pws  = array();
		foreach ( TRA_Accounts::passwords( $uid ) as $p ) {
			$pws[] = array(
				'created'   => $p['created'] ? gmdate( 'c', $p['created'] ) : null,
				'last_used' => $p['last_used'] ? gmdate( 'c', $p['last_used'] ) : null,
			);
		}
		$seen = TRA_Accounts::seen( $uid );
		return array(
			'id'             => $uid,
			'username'       => (string) $u->user_login,
			'display_name'   => (string) $u->display_name,
			'company'        => (string) get_user_meta( $uid, 'billing_company', true ),
			'email'          => (string) $u->user_email,
			'trade'          => TRA_Accounts::is_trade( $uid ),
			'own_discount'   => '' !== $own ? (float) $own : null,
			'discount'       => TRA_Accounts::discount( $uid ),
			'api'            => TRA_Accounts::api_on( $uid ),
			'feed_passwords' => $pws,
			'last_call'      => $seen ? array(
				'time'   => gmdate( 'c', (int) $seen['t'] ),
				'route'  => isset( $seen['route'] ) ? (string) $seen['route'] : '',
				'status' => isset( $seen['status'] ) ? (int) $seen['status'] : 0,
			) : null,
			'registered'     => (string) $u->user_registered,
		);
	}

	/* =====================================================================
	 * Write
	 * =================================================================== */

	public static function account_create( $in = null ) {
		$in  = is_array( $in ) ? $in : array();
		$uid = TRA_Accounts::create(
			array(
				'email'   => isset( $in['email'] ) ? (string) $in['email'] : '',
				'first'   => isset( $in['first'] ) ? (string) $in['first'] : '',
				'last'    => isset( $in['last'] ) ? (string) $in['last'] : '',
				'company' => isset( $in['company'] ) ? (string) $in['company'] : '',
				'notify'  => ! empty( $in['notify'] ),
			)
		);
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}
		TRA_Accounts::update(
			(int) $uid,
			isset( $in['discount'] ) ? (string) $in['discount'] : '',
			! isset( $in['api'] ) || ! empty( $in['api'] )
		);
		return self::account( (int) $uid );
	}

	public static function account_update( $in = null ) {
		$uid = self::trade_id( $in );
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}
		$discount = array_key_exists( 'discount', $in ) ? (string) $in['discount'] : (string) get_user_meta( $uid, 'tra_discount', true );
		$api      = array_key_exists( 'api', $in ) ? ! empty( $in['api'] ) : TRA_Accounts::api_on( $uid );
		TRA_Accounts::update( $uid, $discount, $api );
		return self::account( $uid );
	}

	public static function account_remove( $in = null ) {
		$uid = self::trade_id( $in );
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}
		TRA_Accounts::remove( $uid );
		return array(
			'id'      => $uid,
			'removed' => ! TRA_Accounts::is_trade( $uid ),
		);
	}

	public static function feed_password_send( $in = null ) {
		$uid = self::trade_id( $in );
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}
		$u = get_userdata( $uid );
		if ( ! is_email( (string) $u->user_email ) ) {
			return new WP_Error( 'tra_email', 'The account has no valid e-mail.' );
		}
		$pw = TRA_Accounts::new_password( $uid );
		if ( is_wp_error( $pw ) ) {
			return $pw;
		}
		$mail = self::feed_mail( $u, $pw );
		$sent = wp_mail( (string) $u->user_email, $mail['subject'], $mail['body'], array( 'Content-Type: text/plain; charset=UTF-8' ) );
		unset( $pw, $mail );
		if ( ! $sent ) {
			TRA_Accounts::revoke( $uid );
			return new WP_Error( 'tra_mail', 'The e-mail could not be sent, so the new feed password was revoked too: the account has no feed password now.' );
		}
		return array(
			'id'       => $uid,
			'sent_to'  => (string) $u->user_email,
			'username' => (string) $u->user_login,
			'feed_url' => rest_url( 'tra/v1/products' ),
		);
	}

	/** The id of a trade account from the input, or an error. */
	private static function trade_id( $in ) {
		$uid = is_array( $in ) && isset( $in['id'] ) ? (int) $in['id'] : 0;
		if ( $uid <= 0 || ! TRA_Accounts::is_trade( $uid ) ) {
			return new WP_Error( 'tra_not_trade', 'No trade account with this id.' );
		}
		return $uid;
	}

	/** Subject and plain-text body of the feed e-mail, in the account's language. */
	private static function feed_mail( WP_User $u, string $pw ): array {
		$shop = (string) get_option( 'woocommerce_email_from_name', '' );
		$shop = '' !== $shop ? $shop : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$feed = rest_url( 'tra/v1/products' );
		$home = home_url( '/' );
		$el   = 0 === strpos( (string) get_user_locale( $u->ID ), 'el' );
		if ( $el ) {
			$subject = 'Πρόσβαση στο Trade Feed – ' . $shop;
			$lines   = array(
				'Καλησπέρα σας,',
				'',
				'Ο λογαριασμός χονδρικής σας στο κατάστημά μας έχει πρόσβαση στο Trade Feed: τον κατάλογο των προϊόντων μας με τιμές λιανικής και χονδρικής, για να τα εισάγετε στο δικό σας e-shop.',
				'',
				'Διεύθυνση: ' . $feed,
				'Όνομα χρήστη: ' . $u->user_login,
				'Κωδικός: ' . $pw,
				'',
				'Η σύνδεση γίνεται με HTTP Basic μέσω HTTPS. Η απάντηση είναι σε JSON· για XML προσθέστε ?output=xml.',
				'Ο κωδικός ισχύει μόνο για το Trade Feed (όχι για σύνδεση στο site) και αντικαθιστά κάθε προηγούμενο. Φυλάξτε τον με ασφάλεια· αν χαθεί, ζητήστε μας νέο.',
				'',
				$shop,
				$home,
			);
		} else {
			$subject = 'Trade Feed access – ' . $shop;
			$lines   = array(
				'Hello,',
				'',
				'Your trade account in our shop now has access to the Trade Feed: the catalogue of our products with retail and trade prices, to import into your own e-shop.',
				'',
				'URL: ' . $feed,
				'Username: ' . $u->user_login,
				'Password: ' . $pw,
				'',
				'Authentication is HTTP Basic over HTTPS. Answers are JSON; for XML add ?output=xml.',
				'The password works for the Trade Feed only (not to log in to the site) and replaces any earlier one. Keep it safe; if it is lost, ask us for a new one.',
				'',
				$shop,
				$home,
			);
		}
		return array(
			'subject' => $subject,
			'body'    => implode( "\n", $lines ),
		);
	}
}

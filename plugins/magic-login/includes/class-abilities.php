<?php
/**
 * MGL_Abilities — Magic Login in the WordPress Abilities API, for an AI or
 * a remote tool that runs abilities (e.g. Site Bridge's ability_run), with
 * the same capability as the settings (manage_woocommerce):
 *
 *  magic-login/status  mode, where the button shows, link lifetime, limits,
 *                      whether the e-mail is on, test accounts (count
 *                      only), event counts for the last 30 days and the
 *                      latest 30 events (time, user id, event). Read only:
 *                      no token, e-mail address or IP is ever returned.
 *
 * Registered only on WordPress 6.9+, where the Abilities API exists.
 */

defined( 'ABSPATH' ) || exit;

final class MGL_Abilities {

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
		wp_register_ability(
			'magic-login/status',
			array(
				'label'               => 'Magic Login: status',
				'description'         => 'Passwordless login by e-mail link: mode (off / test / live), where the button shows (My Account, checkout), link lifetime in minutes, rate limits, whether the WooCommerce e-mail is on, the number of test accounts, event counts for the last 30 days and the latest 30 events (time, user id, event: sent, used, invalid, unknown, staff, test_skip, limited, mail_fail). Changes nothing.',
				'category'            => self::CAT,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => array( __CLASS__, 'status' ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => false,
				),
			)
		);
	}

	public static function allowed(): bool {
		return current_user_can( Magic_Login::cap() );
	}

	public static function status( $in = null ): array {
		$s      = MGL_Settings::get();
		$rows   = MGL_Tokens::entries();
		$since  = time() - 30 * DAY_IN_SECONDS;
		$counts = array_fill_keys( array_keys( MGL_Tokens::events() ), 0 );
		foreach ( $rows as $r ) {
			if ( $r['t'] >= $since ) {
				++$counts[ $r['e'] ];
			}
		}
		$latest = array();
		foreach ( array_slice( $rows, 0, 30 ) as $r ) {
			$latest[] = array(
				'time'    => gmdate( 'c', $r['t'] ),
				'user_id' => $r['u'],
				'event'   => $r['e'],
			);
		}
		return array(
			'version'        => MGL_VERSION,
			'mode'           => $s['mode'],
			'disabled'       => Magic_Login::killed(),
			'email_enabled'  => MGL_Emails::enabled(),
			'my_account'     => (bool) $s['account'],
			'checkout'       => (bool) $s['checkout'],
			'checkout_login' => 'yes' === get_option( 'woocommerce_enable_checkout_login_reminder', 'no' ),
			'ttl_minutes'    => (int) $s['ttl'],
			'remember'       => (bool) $s['remember'],
			'per_account'    => (int) $s['per_acct'],
			'per_ip_hour'    => (int) $s['per_ip'],
			'test_accounts'  => count( $s['testers'] ),
			'last_30_days'   => $counts,
			'latest'         => $latest,
		);
	}
}

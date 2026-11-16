<?php
/**
 * LGD_Abilities — Login Guard in the WordPress Abilities API, for an AI or
 * a remote tool that runs abilities (e.g. Site Bridge's ability_run), with
 * the same capability as the settings (manage_options):
 *
 *  login-guard/status  mode, limits, XML-RPC and enumeration settings,
 *                      failed logins in 24 hours / 30 days, event counts,
 *                      active lockouts (by kind, no address) and the latest
 *                      30 events (time, event, user id). Read only: no IP,
 *                      hash, name or e-mail address is ever returned.
 *
 * Registered only on WordPress 6.9+, where the Abilities API exists.
 */

defined( 'ABSPATH' ) || exit;

final class LGD_Abilities {

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
			'login-guard/status',
			array(
				'label'               => 'Login Guard: status',
				'description'         => 'Login protection: mode (off / test / live), whether LGD_DISABLE is set, lockout limits, XML-RPC mode, user-enumeration protections, failed logins in the last 24 hours and 30 days, security event counts for 24 hours and 30 days, active lockouts by kind (ip / user) and the latest 30 events (time, event, user id). Never returns an IP address, hash, username or e-mail. Changes nothing.',
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
		return current_user_can( Login_Guard::CAP );
	}

	public static function status( $in = null ): array {
		$s     = LGD_Settings::get();
		$locks = array(
			'ip'   => 0,
			'user' => 0,
		);
		foreach ( LGD_Store::locks() as $m ) {
			++$locks[ isset( $m['k'] ) && 'user' === $m['k'] ? 'user' : 'ip' ];
		}
		$latest = array();
		foreach ( LGD_Store::rows( 30 ) as $r ) {
			$latest[] = array(
				'time'    => gmdate( 'c', $r['t'] ),
				'event'   => $r['ev'],
				'user_id' => $r['uid'],
			);
		}
		return array(
			'version'         => LGD_VERSION,
			'mode'            => $s['mode'],
			'disabled'        => Login_Guard::killed(),
			'limits'          => array(
				'ip_fails'       => $s['ip_fails'],
				'ip_window_min'  => $s['ip_window'],
				'ip_lock_min'    => $s['ip_lock'],
				'long_after'     => $s['esc_count'],
				'long_lock_h'    => $s['esc_hours'],
				'acct_fails'     => $s['acct_fails'],
				'acct_window_min' => $s['acct_window'],
				'acct_lock_min'  => $s['acct_lock'],
				'unlock_mail'    => $s['unlock_mail'],
				'trusted_ranges' => count( $s['allow'] ),
			),
			'xmlrpc'          => $s['xmlrpc'],
			'pingbacks_off'   => $s['pingbacks'],
			'enumeration'     => array(
				'rest_users'      => $s['enum_rest'],
				'author_scan'     => $s['enum_author'],
				'author_archives' => $s['enum_archives'],
				'sitemap_users'   => $s['enum_sitemap'],
				'oembed_author'   => $s['enum_oembed'],
				'neutral_errors'  => $s['enum_errors'],
				'lost_password'   => $s['lostpw'],
			),
			'two_factor'      => self::twofa(),
			'failed_24h'      => LGD_Store::failures( 24 ),
			'failed_30d'      => LGD_Store::failures( 24 * 30 ),
			'events_24h'      => LGD_Store::counts( time() - DAY_IN_SECONDS ),
			'events_30d'      => LGD_Store::counts( time() - 30 * DAY_IN_SECONDS ),
			'active_lockouts' => $locks,
			'latest'          => $latest,
		);
	}

	/** 2FA counts only: never names, secrets or codes. */
	private static function twofa(): array {
		$s   = LGD_Settings::get();
		$sum = LGD_TwoFA::summary( 0 );
		return array(
			'active'         => LGD_TwoFA::active(),
			'possible'       => LGD_TwoFA::possible(),
			'required_roles' => array_values( (array) $s['tfa_roles'] ),
			'strict_guard'   => (bool) $s['tfa_strict'],
			'users_on'       => (int) $sum['on'],
			'users_missing'  => (int) $sum['missing'],
			'app_passwords'  => count( LGD_TwoFA::app_passwords() ),
		);
	}
}

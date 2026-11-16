<?php
/**
 * LGD_CLI — `wp login-guard …`, the recovery door when the admin page
 * cannot be reached (Bible §11: WP-CLI runs the same code).
 *
 *   wp login-guard status
 *   wp login-guard unlock --ip=203.0.113.7
 *   wp login-guard unlock --user=<login|email|id>
 *   wp login-guard unlock --all
 *   wp login-guard mode <off|test|live>
 *   wp login-guard 2fa status
 *   wp login-guard 2fa reset <login|email|id>
 */

defined( 'ABSPATH' ) || exit;

final class LGD_CLI {

	/**
	 * Mode, failures and active lockouts.
	 *
	 * @param array $args
	 * @param array $assoc
	 */
	public function status( $args, $assoc ): void {
		$st = LGD_Abilities::status();
		\WP_CLI::line( 'Mode: ' . $st['mode'] . ( $st['disabled'] ? ' (LGD_DISABLE is set: nothing runs)' : '' ) );
		\WP_CLI::line( 'Failed logins: ' . $st['failed_24h'] . ' in 24 hours, ' . $st['failed_30d'] . ' in 30 days' );
		$rows = array();
		foreach ( LGD_Store::locks() as $m ) {
			$u      = ! empty( $m['uid'] ) ? get_userdata( (int) $m['uid'] ) : false;
			$rows[] = array(
				'kind'  => $m['k'],
				'who'   => 'user' === $m['k'] ? ( $u ? $u->user_login : '(no account)' ) : $m['ip'],
				'until' => wp_date( 'Y-m-d H:i', (int) $m['until'] ),
			);
		}
		if ( $rows ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'kind', 'who', 'until' ) );
		} else {
			\WP_CLI::line( 'No active lockouts.' );
		}
	}

	/**
	 * Ends lockouts.
	 *
	 * ## OPTIONS
	 *
	 * [--ip=<ip>]
	 * : The full IP address.
	 *
	 * [--user=<user>]
	 * : Login, e-mail or user id.
	 *
	 * [--all]
	 * : Every active lockout.
	 *
	 * @param array $args
	 * @param array $assoc
	 */
	public function unlock( $args, $assoc ): void {
		if ( ! empty( $assoc['all'] ) ) {
			\WP_CLI::success( LGD_Store::unlock_all( 'cli' ) . ' lockouts ended.' );
			return;
		}
		if ( ! empty( $assoc['ip'] ) ) {
			$ip = (string) $assoc['ip'];
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				\WP_CLI::error( 'Not an IP address.' );
			}
			LGD_Store::unlock( LGD_Store::key_ip( $ip ), 'cli' ) ? \WP_CLI::success( 'Unlocked.' ) : \WP_CLI::warning( 'That address was not locked.' );
			return;
		}
		if ( ! empty( $assoc['user'] ) ) {
			$v    = (string) $assoc['user'];
			$user = ctype_digit( $v ) ? get_userdata( (int) $v ) : LGD_Store::find_user( $v );
			$key  = $user ? LGD_Store::key_user( (int) $user->ID ) : LGD_Store::key_ident( $v );
			LGD_Store::unlock( $key, 'cli' ) ? \WP_CLI::success( 'Unlocked.' ) : \WP_CLI::warning( 'That account was not locked.' );
			return;
		}
		\WP_CLI::error( 'Give --ip, --user or --all.' );
	}

	/**
	 * Sets the mode.
	 *
	 * ## OPTIONS
	 *
	 * <mode>
	 * : off | test | live
	 *
	 * @param array $args
	 * @param array $assoc
	 */
	public function mode( $args, $assoc ): void {
		$m = isset( $args[0] ) ? (string) $args[0] : '';
		if ( ! in_array( $m, array( 'off', 'test', 'live' ), true ) ) {
			\WP_CLI::error( 'Mode must be off, test or live.' );
		}
		$s         = LGD_Settings::get();
		$s['mode'] = $m;
		LGD_Settings::save( $s );
		\WP_CLI::success( 'Mode: ' . $m );
	}
	/**
	 * Two-step verification: who has it, or reset it for one user.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : status | reset
	 *
	 * [<user>]
	 * : Login, e-mail or id (reset only).
	 *
	 * @subcommand 2fa
	 *
	 * @param array $args
	 * @param array $assoc
	 */
	public function twofa( $args, $assoc ): void {
		$action = isset( $args[0] ) ? (string) $args[0] : 'status';
		if ( 'reset' === $action ) {
			$ident = isset( $args[1] ) ? (string) $args[1] : '';
			$user  = ctype_digit( $ident ) ? get_userdata( (int) $ident ) : LGD_Store::find_user( $ident );
			if ( ! $user instanceof WP_User ) {
				\WP_CLI::error( 'No such user.' );
			}
			if ( ! LGD_TwoFA::is_on( (int) $user->ID ) ) {
				\WP_CLI::success( $user->user_login . ' has no two-step verification.' );
				return;
			}
			LGD_TwoFA::disable( $user, 'cli' );
			\WP_CLI::success( 'Two-step verification reset for ' . $user->user_login . '.' );
			return;
		}
		$sum = LGD_TwoFA::summary();
		\WP_CLI::line( 'Two-step verification: ' . ( LGD_TwoFA::active() ? 'active' : 'inactive' ) . ( defined( 'LGD_DISABLE_2FA' ) && LGD_DISABLE_2FA ? ' (LGD_DISABLE_2FA is set)' : '' ) );
		\WP_CLI::line( 'Users with 2FA: ' . $sum['on'] . '; required but missing: ' . $sum['missing'] );
		foreach ( $sum['list'] as $u ) {
			\WP_CLI::line( '  missing: ' . $u->user_login );
		}
	}
}

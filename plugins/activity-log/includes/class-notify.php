<?php
/**
 * ACL_Notify — e-mail for critical events (setting «notify», on by
 * default): a new administrator or a role raised to administrator, a
 * plugin installed / activated / deleted, a theme switched, siteurl /
 * home / admin_email / users_can_register / default_role changed, the
 * file editor used.
 *
 * Events wait in the option acl_notify_q (not autoloaded, at most 50) and
 * one summary goes out at most every 15 minutes (WP-Cron single event
 * acl_notify), through wp_mail (so through Mail Relay when it is there).
 * The e-mail names what changed and who did it; it never carries a value
 * the history hides.
 */

defined( 'ABSPATH' ) || exit;

final class ACL_Notify {

	const CRON  = 'acl_notify';
	const QUEUE = 'acl_notify_q';
	const WAIT  = 900;
	const MAX   = 50;

	public static function init(): void {
		add_action( self::CRON, array( __CLASS__, 'send' ) );
	}

	/** Called by ACL_Store::add() for a critical row. */
	public static function queue( array $row ): void {
		if ( ! ACL_Settings::get()['notify'] ) {
			return;
		}
		$q = get_option( self::QUEUE, array() );
		$q = is_array( $q ) ? $q : array();
		if ( count( $q ) < self::MAX ) {
			$q[] = array(
				'ts'           => time(),
				'action'       => $row['action'],
				'object_label' => $row['object_label'],
				'object_id'    => $row['object_id'],
				'actor_id'     => $row['actor_id'],
				'actor_label'  => $row['actor_label'],
				'context'      => $row['context'],
				'details'      => $row['details'],
			);
			update_option( self::QUEUE, $q, false );
		}
		if ( ! wp_next_scheduled( self::CRON ) ) {
			$last = (int) get_option( 'acl_notify_last', 0 );
			wp_schedule_single_event( max( time() + 60, $last + self::WAIT ), self::CRON );
		}
	}

	/** The cron: one e-mail with everything waiting. */
	public static function send(): void {
		$q = get_option( self::QUEUE, array() );
		delete_option( self::QUEUE );
		if ( ! is_array( $q ) || ! $q || ! ACL_Settings::get()['notify'] ) {
			return;
		}
		update_option( 'acl_notify_last', time(), false );

		$s    = ACL_Settings::get();
		$to   = '' !== $s['to'] ? $s['to'] : (string) get_option( 'admin_email' );
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		if ( ! is_email( $to ) ) {
			return;
		}

		// The recipient's language: the admin with that e-mail, else the site.
		$u = get_user_by( 'email', $to );
		ACL_Lang::force( $u instanceof WP_User ? ACL_Lang::for_user( (int) $u->ID ) : ACL_Lang::from_locale( (string) get_locale() ) );

		$lines = array();
		foreach ( $q as $e ) {
			if ( ! is_array( $e ) ) {
				continue;
			}
			$line = wp_date( 'Y-m-d H:i', (int) $e['ts'] ) . ' · ' . ACL_Events::action( (string) $e['action'] );
			if ( '' !== (string) $e['object_label'] ) {
				$line .= ': ' . ACL_Events::object( $e );
			}
			$line .= "\n    " . __( 'Από', 'activity-log' ) . ': ' . ACL_Events::actor( $e ) . ' (' . ACL_Events::context( (string) $e['context'] ) . ')';
			foreach ( ACL_Events::details_lines( is_array( $e['details'] ) ? $e['details'] : array() ) as $d ) {
				$line .= "\n    " . $d;
			}
			$lines[] = $line;
		}
		if ( ! $lines ) {
			ACL_Lang::force( null );
			return;
		}

		/* translators: 1: site name, 2: number of changes */
		$subject = sprintf( _n( '[%1$s] %2$d κρίσιμη αλλαγή στο site', '[%1$s] %2$d κρίσιμες αλλαγές στο site', count( $lines ), 'activity-log' ), $site, count( $lines ) );
		$body    = __( 'Το Activity Log κατέγραψε αλλαγές που αξίζει να ελέγξεις:', 'activity-log' ) . "\n\n"
			. implode( "\n\n", $lines ) . "\n\n"
			. __( 'Αν τις έκανες εσύ ή κάποιος που ξέρεις, δεν χρειάζεται τίποτα.', 'activity-log' ) . "\n"
			. __( 'Όλο το ιστορικό:', 'activity-log' ) . ' ' . admin_url( 'admin.php?page=acl-log' ) . "\n"
			. __( 'Το email αυτό κλείνει από τις Ρυθμίσεις του Activity Log.', 'activity-log' );

		ACL_Lang::force( null );
		wp_mail( $to, $subject, $body );
	}
}

<?php
/**
 * LGD_Privacy — WordPress's personal data exporter and eraser (Tools →
 * Export / Erase Personal Data) for the account of an address.
 *
 * Personal data kept by Login Guard for an account: its rows in the event
 * log ({prefix}lgd_log: time, event, shortened IP, account), the known
 * devices for the new-device e-mail (user meta _lgd_devices: hashes and
 * times) and, when set up, two-step verification (sealed secret, hashed
 * recovery codes, remembered browsers). Failure counters are salted
 * hashes in transients that expire by themselves.
 *
 * Export: the account's log rows, the number of known devices and
 * whether two-step verification is on.
 * Erase: the account's log rows and known devices are deleted. Two-step
 * verification stays: removing it would weaken the account's protection;
 * the user or an administrator turns it off in the profile.
 */

defined( 'ABSPATH' ) || exit;

final class LGD_Privacy {

	/** Log rows per page. */
	const PER_PAGE = 500;

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
	}

	public static function exporters( $list ): array {
		$list                = is_array( $list ) ? $list : array();
		$list['login-guard'] = array(
			'exporter_friendly_name' => __( 'Login Guard: ασφάλεια σύνδεσης', 'login-guard' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	public static function erasers( $list ): array {
		$list                = is_array( $list ) ? $list : array();
		$list['login-guard'] = array(
			'eraser_friendly_name' => __( 'Login Guard: ασφάλεια σύνδεσης', 'login-guard' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	/** True when the log table exists. */
	private static function has_table(): bool {
		global $wpdb;
		$t = LGD_Store::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) );
	}

	public static function export( $email, $page = 1 ): array {
		global $wpdb;
		$user  = get_user_by( 'email', (string) $email );
		$items = array();
		$done  = true;
		if ( $user instanceof WP_User ) {
			$uid   = (int) $user->ID;
			$page  = max( 1, (int) $page );
			$group = __( 'Ασφάλεια σύνδεσης', 'login-guard' );
			if ( self::has_table() ) {
				$t      = LGD_Store::table();
				$events = LGD_Store::events();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
				$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, t, ev, ip FROM {$t} WHERE uid = %d ORDER BY id ASC LIMIT %d OFFSET %d", $uid, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ), ARRAY_A );
				foreach ( $rows as $r ) {
					$ev      = (string) $r['ev'];
					$items[] = array(
						'group_id'    => 'login-guard',
						'group_label' => $group,
						'item_id'     => 'lgd-' . (int) $r['id'],
						'data'        => array(
							array(
								'name'  => __( 'Ημερομηνία', 'login-guard' ),
								'value' => gmdate( 'Y-m-d H:i', (int) $r['t'] ) . ' UTC',
							),
							array(
								'name'  => __( 'Συμβάν', 'login-guard' ),
								'value' => isset( $events[ $ev ] ) ? $events[ $ev ] : $ev,
							),
							array(
								'name'  => __( 'Διεύθυνση IP (συντομευμένη)', 'login-guard' ),
								'value' => (string) $r['ip'],
							),
						),
					);
				}
				$done = count( $rows ) < self::PER_PAGE;
			}
			if ( 1 === $page ) {
				$devices = get_user_meta( $uid, LGD_Notify::DEVICES, true );
				$items[] = array(
					'group_id'    => 'login-guard',
					'group_label' => $group,
					'item_id'     => 'lgd-account',
					'data'        => array(
						array(
							'name'  => __( 'Γνωστές συσκευές', 'login-guard' ),
							'value' => (string) ( is_array( $devices ) ? count( $devices ) : 0 ),
						),
						array(
							'name'  => __( 'Επαλήθευση δύο βημάτων', 'login-guard' ),
							'value' => '' !== (string) get_user_meta( $uid, LGD_TwoFA::META, true ) ? __( 'Ενεργή', 'login-guard' ) : __( 'Ανενεργή', 'login-guard' ),
						),
					),
				);
			}
		}
		return array(
			'data' => $items,
			'done' => $done,
		);
	}

	public static function erase( $email, $page = 1 ): array {
		global $wpdb;
		$user     = get_user_by( 'email', (string) $email );
		$removed  = false;
		$retained = false;
		$messages = array();
		if ( $user instanceof WP_User ) {
			$uid = (int) $user->ID;
			if ( self::has_table() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
				$n       = (int) $wpdb->delete( LGD_Store::table(), array( 'uid' => $uid ), array( '%d' ) );
				$removed = $n > 0;
			}
			if ( delete_user_meta( $uid, LGD_Notify::DEVICES ) ) {
				$removed = true;
			}
			if ( '' !== (string) get_user_meta( $uid, LGD_TwoFA::META, true ) ) {
				$retained   = true;
				$messages[] = __( 'Η επαλήθευση δύο βημάτων του λογαριασμού κρατήθηκε, για να μη μείνει ο λογαριασμός λιγότερο προστατευμένος. Απενεργοποιείται από το προφίλ του χρήστη.', 'login-guard' );
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}

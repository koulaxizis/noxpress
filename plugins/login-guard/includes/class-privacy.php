<?php
/**
 * LGD_Privacy — WordPress's personal data exporter and eraser (Tools →
 * Export / Erase Personal Data) for what Login Guard keeps about a user
 * account (Bible §12 «Προσωπικά δεδομένα»). The policy text stays in
 * Login_Guard::privacy().
 *
 * Found by the account that has the address:
 *  - log rows with its user id (time, event, shortened IP, browser and
 *    system), kept 30 days by LGD_Store::cleanup();
 *  - the known devices of an administrator (hashes with the time last
 *    seen, 30 days);
 *  - two-step verification: on or off and since when (never the secret or
 *    the recovery codes).
 *
 * Eraser: deletes the log rows and the known devices. Two-step
 * verification is kept and reported: it protects the person's own
 * account, and erasing it through the tools would leave the account open
 * to anyone who has the password. The user turns it off from the profile,
 * and it goes with the account when the account is deleted.
 * A visitor without an account has nothing to find: lockouts by IP are not
 * linked to an address.
 */

defined( 'ABSPATH' ) || exit;

final class LGD_Privacy {

	const KEY = 'login-guard';

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
	}

	public static function exporters( $list ): array {
		$list              = is_array( $list ) ? $list : array();
		$list[ self::KEY ] = array(
			'exporter_friendly_name' => __( 'Login Guard: ασφάλεια σύνδεσης', 'login-guard' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	public static function erasers( $list ): array {
		$list              = is_array( $list ) ? $list : array();
		$list[ self::KEY ] = array(
			'eraser_friendly_name' => __( 'Login Guard: ασφάλεια σύνδεσης', 'login-guard' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	private static function uid( $email ): int {
		$email = sanitize_email( (string) $email );
		$user  = is_email( $email ) ? get_user_by( 'email', $email ) : false;
		return $user ? (int) $user->ID : 0;
	}

	public static function export( $email, $page = 1 ): array {
		$uid   = self::uid( $email );
		$items = array();
		if ( $uid > 0 && 1 === (int) $page ) {
			global $wpdb;
			$t      = LGD_Store::table();
			$events = LGD_Store::events();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, t, ev, ip, info FROM {$t} WHERE uid = %d ORDER BY id LIMIT 1000", $uid ), ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : array() as $r ) {
				$data = array(
					array(
						'name'  => __( 'Ώρα', 'login-guard' ),
						'value' => gmdate( 'Y-m-d H:i:s', (int) $r['t'] ) . ' UTC',
					),
					array(
						'name'  => __( 'Συμβάν', 'login-guard' ),
						'value' => isset( $events[ $r['ev'] ] ) ? $events[ $r['ev'] ] : (string) $r['ev'],
					),
				);
				foreach ( array(
					'ip'   => __( 'IP (χωρίς το τελευταίο μέρος)', 'login-guard' ),
					'info' => __( 'Λεπτομέρειες', 'login-guard' ),
				) as $k => $label ) {
					if ( '' !== (string) $r[ $k ] ) {
						$data[] = array(
							'name'  => $label,
							'value' => (string) $r[ $k ],
						);
					}
				}
				$items[] = array(
					'group_id'    => self::KEY . '-log',
					'group_label' => __( 'Login Guard: ημερολόγιο', 'login-guard' ),
					'item_id'     => 'lgd-log-' . (int) $r['id'],
					'data'        => $data,
				);
			}

			$dev = get_user_meta( $uid, LGD_Notify::DEVICES, true );
			if ( is_array( $dev ) && $dev ) {
				$seen = array();
				foreach ( $dev as $t2 ) {
					$seen[] = gmdate( 'Y-m-d H:i', (int) $t2 ) . ' UTC';
				}
				$items[] = array(
					'group_id'    => self::KEY . '-account',
					'group_label' => __( 'Login Guard: λογαριασμός', 'login-guard' ),
					'item_id'     => 'lgd-devices',
					'data'        => array(
						array(
							'name'  => __( 'Γνωστές συσκευές (τελευταία σύνδεση)', 'login-guard' ),
							'value' => implode( ', ', $seen ),
						),
					),
				);
			}

			$rec     = LGD_TwoFA::rec( $uid );
			$items[] = array(
				'group_id'    => self::KEY . '-account',
				'group_label' => __( 'Login Guard: λογαριασμός', 'login-guard' ),
				'item_id'     => 'lgd-2fa',
				'data'        => array(
					array(
						'name'  => __( 'Επαλήθευση σε δύο βήματα', 'login-guard' ),
						'value' => LGD_TwoFA::is_on( $uid )
							? __( 'ενεργή', 'login-guard' ) . ( ! empty( $rec['on'] ) ? ' (' . gmdate( 'Y-m-d', (int) $rec['on'] ) . ')' : '' )
							: __( 'ανενεργή', 'login-guard' ),
					),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	public static function erase( $email, $page = 1 ): array {
		$uid      = self::uid( $email );
		$removed  = false;
		$retained = false;
		$messages = array();
		if ( $uid > 0 ) {
			global $wpdb;
			$t = LGD_Store::table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
			$n       = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE uid = %d", $uid ) );
			$removed = $n > 0;
			if ( delete_user_meta( $uid, LGD_Notify::DEVICES ) ) {
				$removed = true;
			}
			if ( LGD_TwoFA::is_on( $uid ) ) {
				$retained   = true;
				$messages[] = __( 'Login Guard: η επαλήθευση σε δύο βήματα του λογαριασμού κρατήθηκε, γιατί η διαγραφή της θα άφηνε τον λογαριασμό λιγότερο προστατευμένο. Ο χρήστης την απενεργοποιεί από το προφίλ του και σβήνεται μαζί με τον λογαριασμό.', 'login-guard' );
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

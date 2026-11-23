<?php
/**
 * MGL_Privacy — WordPress's personal data exporter and eraser (Tools →
 * Export / Erase Personal Data) for what Magic Login keeps about a user
 * account (Bible §12 «Προσωπικά δεδομένα»). The policy text stays in
 * MGL_Admin_UI.
 *
 * Found by the account that has the address:
 *  - the log entries with its user id (time and event; the log keeps the
 *    last MGL_Tokens::LOG_MAX entries of the site);
 *  - a pending sign-in link (only its expiry: the link itself is stored as
 *    an HMAC and never shown).
 *
 * Eraser: removes the account's log entries and the pending link.
 */

defined( 'ABSPATH' ) || exit;

final class MGL_Privacy {

	const KEY = 'magic-login';

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
	}

	public static function exporters( $list ): array {
		$list              = is_array( $list ) ? $list : array();
		$list[ self::KEY ] = array(
			'exporter_friendly_name' => __( 'Magic Login: σύνδεση με σύνδεσμο', 'magic-login' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	public static function erasers( $list ): array {
		$list              = is_array( $list ) ? $list : array();
		$list[ self::KEY ] = array(
			'eraser_friendly_name' => __( 'Magic Login: σύνδεση με σύνδεσμο', 'magic-login' ),
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
			$events = MGL_Tokens::events();
			$i      = 0;
			foreach ( MGL_Tokens::entries() as $row ) {
				++$i;
				if ( $uid !== $row['u'] ) {
					continue;
				}
				$items[] = array(
					'group_id'    => self::KEY,
					'group_label' => __( 'Magic Login: ημερολόγιο', 'magic-login' ),
					'item_id'     => 'mgl-' . $row['t'] . '-' . $i,
					'data'        => array(
						array(
							'name'  => __( 'Ώρα', 'magic-login' ),
							'value' => gmdate( 'Y-m-d H:i:s', $row['t'] ) . ' UTC',
						),
						array(
							'name'  => __( 'Συμβάν', 'magic-login' ),
							'value' => $events[ $row['e'] ],
						),
					),
				);
			}
			$pending = json_decode( (string) get_user_meta( $uid, MGL_Tokens::META, true ), true );
			if ( is_array( $pending ) && isset( $pending['x'] ) ) {
				$items[] = array(
					'group_id'    => self::KEY,
					'group_label' => __( 'Magic Login: ημερολόγιο', 'magic-login' ),
					'item_id'     => 'mgl-pending',
					'data'        => array(
						array(
							'name'  => __( 'Σύνδεσμος σε αναμονή, λήγει', 'magic-login' ),
							'value' => gmdate( 'Y-m-d H:i:s', (int) $pending['x'] ) . ' UTC',
						),
					),
				);
			}
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	public static function erase( $email, $page = 1 ): array {
		$uid     = self::uid( $email );
		$removed = false;
		if ( $uid > 0 ) {
			$log = get_option( MGL_Tokens::LOG, array() );
			if ( is_array( $log ) ) {
				$keep = array_values(
					array_filter(
						$log,
						static function ( $row ) use ( $uid ) {
							return ! is_array( $row ) || ! isset( $row['u'] ) || $uid !== (int) $row['u'];
						}
					)
				);
				if ( count( $keep ) !== count( $log ) ) {
					update_option( MGL_Tokens::LOG, $keep, false );
					$removed = true;
				}
			}
			if ( delete_user_meta( $uid, MGL_Tokens::META ) ) {
				$removed = true;
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}

<?php
/**
 * MGL_Privacy — WordPress's personal data exporter and eraser (Tools →
 * Export / Erase Personal Data) for the account of an address.
 *
 * Personal data kept by Magic Login: the events of the account in the log
 * (option mgl_log: time and event, no e-mail, no IP) and a pending login
 * link (user meta _mgl_token: only an HMAC and its expiry). The rate
 * limits are salted hashes in transients that expire within the hour, so
 * they identify nobody and are left to expire.
 *
 * Export: the account's log events and whether a link is pending.
 * Erase: the account's log events and the pending link are deleted.
 */

defined( 'ABSPATH' ) || exit;

final class MGL_Privacy {

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
	}

	public static function exporters( $list ): array {
		$list                = is_array( $list ) ? $list : array();
		$list['magic-login'] = array(
			'exporter_friendly_name' => __( 'Magic Login: σύνδεση με σύνδεσμο', 'magic-login' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	public static function erasers( $list ): array {
		$list                = is_array( $list ) ? $list : array();
		$list['magic-login'] = array(
			'eraser_friendly_name' => __( 'Magic Login: σύνδεση με σύνδεσμο', 'magic-login' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	/** Raw log rows (newest first). */
	private static function rows(): array {
		$log = get_option( MGL_Tokens::LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	public static function export( $email, $page = 1 ): array {
		$user  = get_user_by( 'email', (string) $email );
		$items = array();
		if ( $user instanceof WP_User ) {
			$uid    = (int) $user->ID;
			$events = MGL_Tokens::events();
			foreach ( self::rows() as $i => $row ) {
				if ( ! is_array( $row ) || ! isset( $row['u'], $row['t'], $row['e'] ) || (int) $row['u'] !== $uid ) {
					continue;
				}
				$e       = (string) $row['e'];
				$items[] = array(
					'group_id'    => 'magic-login',
					'group_label' => __( 'Σύνδεση με σύνδεσμο', 'magic-login' ),
					'item_id'     => 'mgl-' . (int) $i,
					'data'        => array(
						array(
							'name'  => __( 'Ημερομηνία', 'magic-login' ),
							'value' => gmdate( 'Y-m-d H:i', (int) $row['t'] ) . ' UTC',
						),
						array(
							'name'  => __( 'Συμβάν', 'magic-login' ),
							'value' => isset( $events[ $e ] ) ? $events[ $e ] : $e,
						),
					),
				);
			}
			if ( '' !== (string) get_user_meta( $uid, MGL_Tokens::META, true ) ) {
				$items[] = array(
					'group_id'    => 'magic-login',
					'group_label' => __( 'Σύνδεση με σύνδεσμο', 'magic-login' ),
					'item_id'     => 'mgl-token',
					'data'        => array(
						array(
							'name'  => __( 'Σύνδεσμος σε αναμονή', 'magic-login' ),
							'value' => __( 'Ναι (κρατάμε μόνο ένα αποτύπωμα του συνδέσμου, όχι τον ίδιο)', 'magic-login' ),
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
		$user    = get_user_by( 'email', (string) $email );
		$removed = false;
		if ( $user instanceof WP_User ) {
			$uid  = (int) $user->ID;
			$rows = self::rows();
			$keep = array();
			foreach ( $rows as $row ) {
				if ( is_array( $row ) && isset( $row['u'] ) && (int) $row['u'] === $uid ) {
					$removed = true;
					continue;
				}
				$keep[] = $row;
			}
			if ( $removed ) {
				update_option( MGL_Tokens::LOG, $keep, false );
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

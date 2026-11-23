<?php
/**
 * TRA_Privacy — WordPress's personal data exporter and eraser (Tools →
 * Export / Erase Personal Data) for a trade account.
 *
 * Personal data kept by Trade Accounts, beyond WooCommerce's own customer
 * and order data (which WooCommerce exports and erases itself): the trade
 * role, the account's own discount (user meta tra_discount), the feed
 * switch (tra_api), the last feed call (tra_api_seen) and its lines in the
 * feed log (option tra_log: time, account, route, status, count; no IP).
 *
 * Export: all of the above. Erase: the feed log lines and the last call
 * are deleted; the trade role, discount and feed switch stay, because
 * they are the terms of a business relationship that only the shop ends
 * (Trade Accounts → Accounts → remove), and the eraser says so.
 */

defined( 'ABSPATH' ) || exit;

final class TRA_Privacy {

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
	}

	public static function exporters( $list ): array {
		$list                   = is_array( $list ) ? $list : array();
		$list['trade-accounts'] = array(
			'exporter_friendly_name' => __( 'Trade Accounts: λογαριασμός χονδρικής', 'trade-accounts' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	public static function erasers( $list ): array {
		$list                   = is_array( $list ) ? $list : array();
		$list['trade-accounts'] = array(
			'eraser_friendly_name' => __( 'Trade Accounts: λογαριασμός χονδρικής', 'trade-accounts' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	/** Raw feed log lines (newest first). */
	private static function log(): array {
		$log = get_option( 'tra_log', array() );
		return is_array( $log ) ? $log : array();
	}

	public static function export( $email, $page = 1 ): array {
		$user  = get_user_by( 'email', (string) $email );
		$items = array();
		if ( $user instanceof WP_User ) {
			$uid   = (int) $user->ID;
			$group = __( 'Λογαριασμός χονδρικής', 'trade-accounts' );
			if ( TRA_Accounts::is_trade( $uid ) ) {
				$own     = (string) get_user_meta( $uid, 'tra_discount', true );
				$items[] = array(
					'group_id'    => 'trade-accounts',
					'group_label' => $group,
					'item_id'     => 'tra-account',
					'data'        => array(
						array(
							'name'  => __( 'Λογαριασμός χονδρικής', 'trade-accounts' ),
							'value' => __( 'Ναι', 'trade-accounts' ),
						),
						array(
							'name'  => __( 'Έκπτωση', 'trade-accounts' ),
							'value' => ( '' !== $own ? $own : (string) TRA_Accounts::discount( $uid ) ) . '%',
						),
						array(
							'name'  => __( 'Πρόσβαση στο API', 'trade-accounts' ),
							'value' => TRA_Accounts::api_on( $uid ) ? __( 'Ναι', 'trade-accounts' ) : __( 'Όχι', 'trade-accounts' ),
						),
					),
				);
			}
			foreach ( self::log() as $i => $line ) {
				if ( ! is_array( $line ) || ! isset( $line['u'], $line['t'] ) || (int) $line['u'] !== $uid ) {
					continue;
				}
				$items[] = array(
					'group_id'    => 'trade-accounts-feed',
					'group_label' => __( 'Κλήσεις στο API χονδρικής', 'trade-accounts' ),
					'item_id'     => 'tra-log-' . (int) $i,
					'data'        => array(
						array(
							'name'  => __( 'Ημερομηνία', 'trade-accounts' ),
							'value' => gmdate( 'Y-m-d H:i', (int) $line['t'] ) . ' UTC',
						),
						array(
							'name'  => __( 'Κλήση', 'trade-accounts' ),
							'value' => isset( $line['r'] ) ? (string) $line['r'] : '',
						),
						array(
							'name'  => __( 'Απάντηση', 'trade-accounts' ),
							'value' => isset( $line['s'] ) ? (string) (int) $line['s'] : '',
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
		$user     = get_user_by( 'email', (string) $email );
		$removed  = false;
		$retained = false;
		$messages = array();
		if ( $user instanceof WP_User ) {
			$uid  = (int) $user->ID;
			$keep = array();
			foreach ( self::log() as $line ) {
				if ( is_array( $line ) && isset( $line['u'] ) && (int) $line['u'] === $uid ) {
					$removed = true;
					continue;
				}
				$keep[] = $line;
			}
			if ( $removed ) {
				update_option( 'tra_log', $keep, false );
			}
			if ( delete_user_meta( $uid, 'tra_api_seen' ) ) {
				$removed = true;
			}
			if ( TRA_Accounts::is_trade( $uid ) ) {
				$retained   = true;
				$messages[] = __( 'Ο λογαριασμός χονδρικής (ρόλος, έκπτωση, πρόσβαση στο API) κρατήθηκε: είναι οι όροι μιας επαγγελματικής συνεργασίας. Τον αφαιρείς από το Trade Accounts → Λογαριασμοί.', 'trade-accounts' );
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

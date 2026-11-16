<?php
/**
 * ACL_Watch_Suite — events of other Noxpress plugins (category «suite»)
 * and of Login Guard (category «users»). Only the actions those plugins
 * already fire; each row names the object by id, never a customer.
 *
 *  noxpress_withdrawal_submitted / _status_changed  Easy Withdrawal
 *  pbq_request_state                                Publishing Quote
 *  tra_consignment_created / _settled / _paid       Trade Accounts
 *  crt_creator_changed                              Featured Creators
 *  por_released                                     Pre-Orders
 *  pl_entries_changed                               Production Ledger
 *  brc_item_sent                                    Broadcast
 *  lgd_locked / lgd_unlocked / lgd_2fa_failed /
 *  lgd_session_blocked                              Login Guard
 */

defined( 'ABSPATH' ) || exit;

final class ACL_Watch_Suite {

	public static function init(): void {
		add_action( 'noxpress_withdrawal_submitted', array( __CLASS__, 'withdrawal_submitted' ), 10, 1 );
		add_action( 'noxpress_withdrawal_status_changed', array( __CLASS__, 'withdrawal_status' ), 10, 3 );
		add_action( 'pbq_request_state', array( __CLASS__, 'quote_state' ), 10, 3 );
		add_action( 'tra_consignment_created', array( __CLASS__, 'consignment_created' ), 10, 1 );
		add_action( 'tra_consignment_settled', array( __CLASS__, 'consignment_settled' ), 10, 3 );
		add_action( 'tra_consignment_paid', array( __CLASS__, 'consignment_paid' ), 10, 3 );
		add_action( 'crt_creator_changed', array( __CLASS__, 'creator_changed' ), 10, 2 );
		add_action( 'por_released', array( __CLASS__, 'preorder_released' ), 10, 2 );
		add_action( 'pl_entries_changed', array( __CLASS__, 'ledger_changed' ), 10, 1 );
		add_action( 'brc_item_sent', array( __CLASS__, 'broadcast_sent' ), 10, 1 );

		add_action( 'lgd_locked', array( __CLASS__, 'lg_locked' ), 10, 3 );
		add_action( 'lgd_unlocked', array( __CLASS__, 'lg_unlocked' ), 10, 3 );
		add_action( 'lgd_2fa_failed', array( __CLASS__, 'lg_2fa_failed' ), 10, 1 );
		add_action( 'lgd_session_blocked', array( __CLASS__, 'lg_session_blocked' ), 10, 1 );
	}

	private static function add( string $action, string $type, int $id, string $label, array $d = array(), string $sev = 'info', string $cat = 'suite' ): void {
		try {
			ACL_Store::add(
				array(
					'category'    => $cat,
					'action'      => $action,
					'object_type' => $type,
					'object_id'   => $id,
					'label'       => $label,
					'details'     => $d,
					'severity'    => $sev,
				)
			);
		} catch ( \Throwable $e ) {
			return;
		}
	}

	private static function order_no( int $id ): string {
		$o = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : false;
		return $o ? '#' . $o->get_order_number() : '#' . $id;
	}

	public static function withdrawal_submitted( $order_id ): void {
		$id = (int) $order_id;
		self::add( 'withdrawal.submitted', 'order', $id, self::order_no( $id ), array(), 'notice' );
	}

	public static function withdrawal_status( $order_id, $new = '', $old = '' ): void {
		$id = (int) $order_id;
		$d  = array();
		ACL_Redact::diff( $d, 'status', is_scalar( $old ) ? (string) $old : '', is_scalar( $new ) ? (string) $new : '' );
		self::add( 'withdrawal.status', 'order', $id, self::order_no( $id ), $d );
	}

	public static function quote_state( $id, $state = '', $old = '' ): void {
		$d = array();
		ACL_Redact::diff( $d, 'state', (string) $old, (string) $state );
		self::add( 'quote.state', 'quote', (int) $id, '#' . (int) $id, $d );
	}

	public static function consignment_created( $id ): void {
		self::add( 'consignment.created', 'consignment', (int) $id, '#' . (int) $id );
	}

	public static function consignment_settled( $id, $row = null, $order_id = 0 ): void {
		self::add( 'consignment.settled', 'consignment', (int) $id, '#' . (int) $id, array( 'order' => array( 't' => self::order_no( (int) $order_id ) ) ), 'notice' );
	}

	public static function consignment_paid( $id, $row = null, $order_id = 0 ): void {
		self::add( 'consignment.paid', 'consignment', (int) $id, '#' . (int) $id, array( 'order' => array( 't' => self::order_no( (int) $order_id ) ) ), 'notice' );
	}

	public static function creator_changed( $creator_id, $ids = array() ): void {
		$t = get_term( (int) $creator_id );
		self::add( 'creator.changed', 'creator', (int) $creator_id, $t instanceof WP_Term ? $t->name : '#' . (int) $creator_id, array( 'products' => array( 't' => (string) count( (array) $ids ) ) ) );
	}

	public static function preorder_released( $id, $date = '' ): void {
		self::add( 'preorder.released', 'product', (int) $id, (string) get_the_title( (int) $id ), array( 'date' => array( 't' => ACL_Redact::scalar( $date ) ) ), 'notice' );
	}

	public static function ledger_changed( $ids = array() ): void {
		$n = count( (array) $ids );
		if ( $n > 0 ) {
			self::add( 'ledger.changed', 'ledger', 0, 'Production Ledger', array( 'entries' => array( 't' => (string) $n ) ) );
		}
	}

	public static function broadcast_sent( $id ): void {
		self::add( 'broadcast.sent', 'broadcast', (int) $id, '#' . (int) $id );
	}

	/* Login Guard: the IP arrives only as a salted hash and is never stored. */

	public static function lg_locked( $kind = '', $hash = '', $until = 0 ): void {
		$d = array(
			'kind'  => array( 't' => 'user' === $kind ? 'user' : 'ip' ),
			'until' => array( 't' => (int) $until > 0 ? wp_date( 'Y-m-d H:i', (int) $until ) : '' ),
		);
		self::add( 'login_guard.locked', 'login_guard', 0, 'Login Guard', $d, 'warning', 'users' );
	}

	public static function lg_unlocked( $kind = '', $hash = '', $by = 0 ): void {
		self::add( 'login_guard.unlocked', 'login_guard', 0, 'Login Guard', array( 'kind' => array( 't' => 'user' === $kind ? 'user' : 'ip' ) ), 'notice', 'users' );
	}

	public static function lg_2fa_failed( $uid ): void {
		$u = get_userdata( (int) $uid );
		self::add( 'login_guard.2fa_failed', 'user', (int) $uid, $u instanceof WP_User ? (string) $u->display_name : '#' . (int) $uid, array(), 'warning', 'users' );
	}

	public static function lg_session_blocked( $uid ): void {
		$u = get_userdata( (int) $uid );
		self::add( 'login_guard.session_blocked', 'user', (int) $uid, $u instanceof WP_User ? (string) $u->display_name : '#' . (int) $uid, array(), 'warning', 'users' );
	}
}

<?php
/**
 * LOP_Presence — the online / offline switch.
 *
 * PurioChat (availability «online») offers the «talk to a person» button
 * only while its presence transient exists, and its own inbox keeps it
 * alive only while the page is open: a phone in a pocket stops the timer
 * and goes offline in two minutes. Here the switch lives on the server:
 *
 *  - lop_state { on, since, until } (autoload, read by every availability
 *    check). until = since + the «automatic offline» hours.
 *  - pre_transient_{presence}: while the switch is on and until is in the
 *    future, PurioChat sees an operator online, without any write.
 *  - transient_{presence}: while the switch is off, PurioChat sees nobody,
 *    even if its inbox is open somewhere (unless the «inbox» setting).
 *  - Expiry is checked on every read, so it is right without cron. One
 *    single WP-Cron event (lop_auto_offline) at until clears the state and
 *    sends the «you are offline now» notification.
 *
 * Only while the «enabled» setting is on; otherwise PurioChat works as
 * before. LOP_DISABLE stops the filters (bootstrap).
 */

defined( 'ABSPATH' ) || exit;

final class LOP_Presence {

	const OPTION = 'lop_state';
	const CRON   = 'lop_auto_offline';

	public static function init(): void {
		add_filter( 'pre_transient_' . LOP_Purio::PRESENCE, array( __CLASS__, 'pre' ), 10, 1 );
		add_filter( 'transient_' . LOP_Purio::PRESENCE, array( __CLASS__, 'value' ), 10, 1 );
		add_action( self::CRON, array( __CLASS__, 'expire' ) );
	}

	/** { on: bool, since: int, until: int } */
	public static function state(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'on'    => ! empty( $raw['on'] ),
			'since' => isset( $raw['since'] ) ? max( 0, (int) $raw['since'] ) : 0,
			'until' => isset( $raw['until'] ) ? max( 0, (int) $raw['until'] ) : 0,
		);
	}

	/** The switch decides now and says online. */
	public static function online(): bool {
		if ( ! LOP_Settings::get()['enabled'] ) {
			return false;
		}
		$s = self::state();
		return $s['on'] && $s['until'] > time();
	}

	/** Online for the configured hours from now (also «extend»). */
	public static function go_online(): array {
		$now   = time();
		$s     = self::state();
		$until = $now + LOP_Settings::get()['hours'] * HOUR_IN_SECONDS;
		update_option(
			self::OPTION,
			array(
				'on'    => true,
				'since' => $s['on'] && $s['until'] > $now && $s['since'] ? $s['since'] : $now,
				'until' => $until,
			),
			true
		);
		wp_clear_scheduled_hook( self::CRON );
		wp_schedule_single_event( $until, self::CRON );
		return self::state();
	}

	public static function go_offline(): array {
		update_option(
			self::OPTION,
			array(
				'on'    => false,
				'since' => 0,
				'until' => 0,
			),
			true
		);
		wp_clear_scheduled_hook( self::CRON );
		return self::state();
	}

	/**
	 * pre_transient_ filter: a value (the time) while the switch says online.
	 *
	 * @param mixed $pre false = no short-circuit.
	 * @return mixed
	 */
	public static function pre( $pre ) {
		try {
			return self::online() ? time() : $pre;
		} catch ( \Throwable $e ) {
			return $pre;
		}
	}

	/**
	 * transient_ filter: nobody online while the switch is off.
	 *
	 * @param mixed $value Stored value (false when missing).
	 * @return mixed
	 */
	public static function value( $value ) {
		try {
			$s = LOP_Settings::get();
			if ( $s['enabled'] && ! $s['inbox'] && ! self::online() ) {
				return false;
			}
		} catch ( \Throwable $e ) {
			return $value;
		}
		return $value;
	}

	/** WP-Cron at until: the switch goes off and the phone is told. */
	public static function expire(): void {
		$s = self::state();
		if ( ! $s['on'] ) {
			return;
		}
		if ( $s['until'] > time() + 30 ) {
			// Extended in the meantime: wait for the new time.
			wp_schedule_single_event( $s['until'], self::CRON );
			return;
		}
		self::go_offline();
		if ( LOP_Settings::get()['enabled'] ) {
			LOP_Push::to_operators(
				array(
					'title' => __( 'Είσαι πλέον offline', 'live-operator' ),
					'body'  => __( 'Τελείωσε ο χρόνος online που είχες ορίσει. Άνοιξε την εφαρμογή για να γίνεις ξανά online.', 'live-operator' ),
					'tag'   => 'lop-presence',
					'url'   => LOP_App::url(),
				)
			);
		}
	}
}

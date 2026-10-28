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
 * The weekly schedule (LOP_Schedule, 1.3.0) is a second way to be
 * online: online = the manual switch OR a schedule window that is not
 * paused. «Go offline» inside a window pauses it until its end; «Go online»
 * (or «Extend») un-pauses it. The automatic offline of the manual switch
 * never pauses the schedule, and tells «offline» only when nothing keeps
 * the operator online.
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

	/** The switch decides now and says online (by hand or by schedule). */
	public static function online(): bool {
		if ( ! LOP_Settings::get()['enabled'] ) {
			return false;
		}
		return self::manual_online() || LOP_Schedule::active();
	}

	/** Online by hand («Go online» / «Extend»), before its automatic end. */
	public static function manual_online(): bool {
		if ( ! LOP_Settings::get()['enabled'] ) {
			return false;
		}
		$s = self::state();
		return $s['on'] && $s['until'] > time();
	}

	/** Online until: the later of the manual end and the window's end. */
	public static function until(): int {
		$until = self::manual_online() ? self::state()['until'] : 0;
		if ( LOP_Schedule::active() ) {
			$until = max( $until, (int) LOP_Schedule::window()['cur'][1] );
		}
		return $until;
	}

	/** «manual», «schedule», «both» or '' (offline). */
	public static function source(): string {
		$m = self::manual_online();
		$s = LOP_Schedule::active();
		return $m && $s ? 'both' : ( $m ? 'manual' : ( $s ? 'schedule' : '' ) );
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
		LOP_Schedule::resume();
		return self::state();
	}

	/** Offline now: the manual switch off, and a schedule window paused. */
	public static function go_offline(): array {
		LOP_Schedule::pause();
		return self::clear_manual();
	}

	/** The manual switch off, without touching the schedule. */
	private static function clear_manual(): array {
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
		self::clear_manual();
		if ( LOP_Settings::get()['enabled'] && ! self::online() ) {
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

<?php
/**
 * LOP_Schedule — the weekly schedule (1.3.0): online on chosen days and
 * hours, in the site's time zone (wp_timezone()).
 *
 *  lop_schedule (autoload, read by every availability check)
 *    on            the schedule decides too (off by default, Bible §14.1)
 *    notify_start  a notification when a window starts
 *    days          ISO weekday '1' (Mon) … '7' (Sun) => up to 3 ranges
 *                  [ 'HH:MM', 'HH:MM' ]; an end at or before the start
 *                  ends on the next day (22:00–02:00), '24:00' = midnight
 *    closed        up to 30 dates 'Y-m-d' that open no window (a window
 *                  is skipped when it starts on such a date); past dates
 *                  are dropped on every save
 *  lop_sched_state (autoload)
 *    pause         end of the window that «Go offline» closed early: the
 *                  window counts as offline until then, the next one opens
 *    seen_start    start of the last window told «online» (no repeat)
 *    seen_end      end of the last window told «offline» (no repeat)
 *
 * The windows are worked out on every read (correct without cron, Bible
 * §14 «a state with an end date»). One single WP-Cron event
 * (lop_schedule_tick) at the next change, start or end, only sends the
 * notifications and sets the next one. Overlapping and touching windows
 * are merged, so one long stretch tells once. Nothing of PurioChat is
 * written (LOP_Presence answers its transient).
 */

defined( 'ABSPATH' ) || exit;

final class LOP_Schedule {

	const OPTION = 'lop_schedule';
	const STATE  = 'lop_sched_state';
	const CRON   = 'lop_schedule_tick';

	const RANGES = 3;
	const CLOSED = 30;

	/** Notifications only this close to the change (late WP-Cron). */
	const LATE = 30 * MINUTE_IN_SECONDS;

	/** Per-request caches. */
	private static $cache  = null;
	private static $window = array();

	public static function init(): void {
		add_action( self::CRON, array( __CLASS__, 'tick' ) );
	}

	public static function defaults(): array {
		$days = array();
		for ( $d = 1; $d <= 7; $d++ ) {
			$days[ (string) $d ] = array();
		}
		return array(
			'on'           => false,
			'notify_start' => true,
			'days'         => $days,
			'closed'       => array(),
		);
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPTION, array() );
			self::$cache = self::sanitize( is_array( $raw ) ? $raw : array() );
		}
		return self::$cache;
	}

	/**
	 * Save (missing keys keep their value) and set the next tick.
	 *
	 * @param array $changes Raw values.
	 * @return int Ranges and dates that were not valid and were dropped.
	 */
	public static function save( array $changes ): int {
		$dropped = 0;
		$clean   = self::sanitize( array_merge( self::get(), $changes ), $dropped );
		update_option( self::OPTION, $clean, true );
		self::$cache  = $clean;
		self::$window = array();
		self::reschedule();
		return $dropped;
	}

	/**
	 * Strict validation (Bible §11).
	 *
	 * @param array $raw     Raw values.
	 * @param int   $dropped Out: invalid ranges and dates.
	 */
	public static function sanitize( array $raw, int &$dropped = 0 ): array {
		$d   = self::defaults();
		$out = array();

		foreach ( array( 'on', 'notify_start' ) as $k ) {
			$out[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}

		$days        = isset( $raw['days'] ) && is_array( $raw['days'] ) ? $raw['days'] : array();
		$out['days'] = $d['days'];
		foreach ( $out['days'] as $day => $unused ) {
			$list = isset( $days[ $day ] ) && is_array( $days[ $day ] ) ? $days[ $day ] : ( isset( $days[ (int) $day ] ) && is_array( $days[ (int) $day ] ) ? $days[ (int) $day ] : array() );
			$keep = array();
			foreach ( $list as $range ) {
				$range = is_array( $range ) ? array_values( $range ) : array();
				$from  = isset( $range[0] ) && is_scalar( $range[0] ) ? trim( (string) $range[0] ) : '';
				$to    = isset( $range[1] ) && is_scalar( $range[1] ) ? trim( (string) $range[1] ) : '';
				if ( '' === $from && '' === $to ) {
					continue; // An empty row of the form.
				}
				$a = self::minutes( $from, false );
				$b = self::minutes( $to, true );
				if ( null === $a || null === $b || $a === $b || count( $keep ) >= self::RANGES ) {
					++$dropped;
					continue;
				}
				$keep[] = array( self::hm( $a ), self::hm( $b ) );
			}
			usort(
				$keep,
				static function ( $x, $y ) {
					return strcmp( $x[0], $y[0] );
				}
			);
			$out['days'][ $day ] = $keep;
		}

		$closed = isset( $raw['closed'] ) ? $raw['closed'] : array();
		$lines  = is_array( $closed ) ? $closed : preg_split( '/[\s,;]+/', is_scalar( $closed ) ? (string) $closed : '' );
		$today  = wp_date( 'Y-m-d' );
		$dates  = array();
		foreach ( (array) $lines as $line ) {
			if ( ! is_scalar( $line ) || '' === trim( (string) $line ) ) {
				continue;
			}
			$ymd = self::date( trim( (string) $line ) );
			if ( null === $ymd ) {
				++$dropped;
				continue;
			}
			if ( $ymd >= $today ) {
				$dates[ $ymd ] = true;
			}
		}
		$dates = array_keys( $dates );
		sort( $dates );
		if ( count( $dates ) > self::CLOSED ) {
			$dropped += count( $dates ) - self::CLOSED;
			$dates    = array_slice( $dates, 0, self::CLOSED );
		}
		$out['closed'] = $dates;

		return $out;
	}

	/** 'HH:MM' → minutes of the day; '24:00' only as an end. */
	private static function minutes( string $hm, bool $end ): ?int {
		if ( ! preg_match( '/^([01]?\d|2[0-4]):([0-5]\d)$/', $hm, $m ) ) {
			return null;
		}
		$min = (int) $m[1] * 60 + (int) $m[2];
		if ( $min > 1440 || ( 1440 === $min && ! $end ) ) {
			return null;
		}
		return $min;
	}

	private static function hm( int $min ): string {
		return sprintf( '%02d:%02d', intdiv( $min, 60 ), $min % 60 );
	}

	/** 'Y-m-d' or 'd/m/Y' (also with . or -) → 'Y-m-d', or null. */
	private static function date( string $s ): ?string {
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m ) ) {
			list( , $y, $mo, $d ) = $m;
		} elseif ( preg_match( '#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $s, $m ) ) {
			list( , $d, $mo, $y ) = $m;
		} else {
			return null;
		}
		return checkdate( (int) $mo, (int) $d, (int) $y ) ? sprintf( '%04d-%02d-%02d', $y, $mo, $d ) : null;
	}

	/* =====================================================================
	 * Windows
	 * =================================================================== */

	/**
	 * The windows (merged, sorted) that touch [from, to), as [ start, end ]
	 * timestamps.
	 */
	public static function windows( int $from, int $to ): array {
		$s    = self::get();
		$tz   = wp_timezone();
		$day  = ( new DateTimeImmutable( '@' . $from ) )->setTimezone( $tz )->setTime( 0, 0 )->modify( '-1 day' );
		$last = ( new DateTimeImmutable( '@' . $to ) )->setTimezone( $tz )->setTime( 0, 0 );
		$out  = array();
		while ( $day <= $last ) {
			$ymd = $day->format( 'Y-m-d' );
			if ( ! in_array( $ymd, $s['closed'], true ) ) {
				foreach ( $s['days'][ $day->format( 'N' ) ] as $range ) {
					$a     = self::minutes( $range[0], false );
					$b     = self::minutes( $range[1], true );
					$start = self::at( $day, (int) $a );
					$end   = self::at( $b > $a ? $day : $day->modify( '+1 day' ), (int) $b );
					if ( $end > $from && $start < $to && $end > $start ) {
						$out[] = array( $start, $end );
					}
				}
			}
			$day = $day->modify( '+1 day' );
		}
		usort(
			$out,
			static function ( $x, $y ) {
				return $x[0] <=> $y[0];
			}
		);
		$merged = array();
		foreach ( $out as $w ) {
			$n = count( $merged );
			if ( $n && $w[0] <= $merged[ $n - 1 ][1] ) {
				$merged[ $n - 1 ][1] = max( $merged[ $n - 1 ][1], $w[1] );
			} else {
				$merged[] = $w;
			}
		}
		return $merged;
	}

	/** A local day at 00:00 plus minutes (1440 = the next midnight). */
	private static function at( DateTimeImmutable $day, int $min ): int {
		if ( 1440 === $min ) {
			return $day->modify( '+1 day' )->getTimestamp();
		}
		return $day->setTime( intdiv( $min, 60 ), $min % 60 )->getTimestamp();
	}

	/**
	 * Now: the window we are in, and the start of the next one.
	 *
	 * @return array{cur: array|null, next: int}
	 */
	public static function window( ?int $now = null ): array {
		$now = null === $now ? time() : $now;
		if ( isset( self::$window[ $now ] ) ) {
			return self::$window[ $now ];
		}
		$cur  = null;
		$next = 0;
		if ( self::has_ranges() ) {
			// A week ahead is enough unless closed dates hide the next
			// window: then a week more for every closed date.
			$horizons = array( 8 );
			if ( self::get()['closed'] ) {
				$horizons[] = 7 * ( count( self::get()['closed'] ) + 2 );
			}
			foreach ( $horizons as $days ) {
				foreach ( self::windows( $now - DAY_IN_SECONDS, $now + $days * DAY_IN_SECONDS ) as $w ) {
					if ( $w[0] <= $now && $now < $w[1] ) {
						$cur = $w;
					} elseif ( $w[0] > $now ) {
						$next = $w[0];
						break;
					}
				}
				if ( $next ) {
					break;
				}
			}
		}
		self::$window = array(
			$now => array(
				'cur'  => $cur,
				'next' => $next,
			),
		);
		return self::$window[ $now ];
	}

	/** At least one day has a range. */
	private static function has_ranges(): bool {
		foreach ( self::get()['days'] as $ranges ) {
			if ( $ranges ) {
				return true;
			}
		}
		return false;
	}

	/** The schedule is on, with the switch setting (LOP_Settings enabled). */
	public static function enabled(): bool {
		return LOP_Settings::get()['enabled'] && self::get()['on'];
	}

	public static function state(): array {
		$raw = get_option( self::STATE, array() );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'pause'      => isset( $raw['pause'] ) ? max( 0, (int) $raw['pause'] ) : 0,
			'seen_start' => isset( $raw['seen_start'] ) ? max( 0, (int) $raw['seen_start'] ) : 0,
			'seen_end'   => isset( $raw['seen_end'] ) ? max( 0, (int) $raw['seen_end'] ) : 0,
		);
	}

	private static function set_state( array $changes ): void {
		update_option( self::STATE, array_merge( self::state(), $changes ), true );
	}

	/** Now inside a window that is not paused. */
	public static function active(): bool {
		if ( ! self::enabled() ) {
			return false;
		}
		$w = self::window();
		return null !== $w['cur'] && self::state()['pause'] < $w['cur'][1];
	}

	/** End of the paused window, or 0. */
	public static function paused_until(): int {
		if ( ! self::enabled() ) {
			return 0;
		}
		$w = self::window();
		return null !== $w['cur'] && self::state()['pause'] >= $w['cur'][1] ? $w['cur'][1] : 0;
	}

	/** «Go offline» inside a window: closed until its end. */
	public static function pause(): void {
		if ( self::active() ) {
			self::set_state( array( 'pause' => self::window()['cur'][1] ) );
		}
	}

	/** «Go online»: a paused window counts again. */
	public static function resume(): void {
		if ( self::state()['pause'] ) {
			self::set_state( array( 'pause' => 0 ) );
		}
	}

	/* =====================================================================
	 * Cron: notifications at the changes
	 * =================================================================== */

	/** One event at the next change, or none. */
	public static function reschedule(): void {
		wp_clear_scheduled_hook( self::CRON );
		if ( Live_Operator::killed() || ! self::enabled() ) {
			return;
		}
		$w  = self::window();
		$at = null !== $w['cur'] ? $w['cur'][1] : $w['next'];
		if ( $at > 0 ) {
			wp_schedule_single_event( $at + 1, self::CRON );
		}
	}

	/** Admin, REST and cron: set the event when it went missing. */
	public static function ensure(): void {
		if ( self::enabled() && ! Live_Operator::killed() && ! wp_next_scheduled( self::CRON ) ) {
			self::reschedule();
		}
	}

	public static function tick(): void {
		if ( ! self::enabled() ) {
			return;
		}
		$now = time();
		$w   = self::window( $now );
		$st  = self::state();

		if ( null !== $w['cur'] ) {
			if ( $st['seen_start'] !== $w['cur'][0] ) {
				self::set_state( array( 'seen_start' => $w['cur'][0] ) );
				if ( self::get()['notify_start'] && $now - $w['cur'][0] < self::LATE && self::active() && ! LOP_Presence::manual_online() ) {
					LOP_Push::to_operators(
						array(
							'title' => __( 'Είσαι online (ωράριο)', 'live-operator' ),
							/* translators: %s: time, e.g. 14:00 */
							'body'  => sprintf( __( 'Οι επισκέπτες βλέπουν το «Μιλήστε με άνθρωπο» έως τις %s. Κράτα το κινητό κοντά σου.', 'live-operator' ), self::label( $w['cur'][1], $now ) ),
							'tag'   => 'lop-presence',
							'url'   => LOP_App::url(),
						)
					);
				}
			}
		} else {
			$ended = 0;
			foreach ( self::windows( $now - 2 * DAY_IN_SECONDS, $now ) as $past ) {
				if ( $past[1] <= $now ) {
					$ended = $past[1];
				}
			}
			if ( $ended && $st['seen_end'] !== $ended ) {
				self::set_state( array( 'seen_end' => $ended ) );
				if ( $now - $ended < self::LATE && $st['pause'] < $ended && ! LOP_Presence::online() ) {
					LOP_Push::to_operators(
						array(
							'title' => __( 'Είσαι πλέον offline', 'live-operator' ),
							'body'  => $w['next']
								/* translators: %s: day and time, e.g. Tue 10:00 */
								? sprintf( __( 'Τέλος του ωραρίου. Επόμενο: %s.', 'live-operator' ), self::label( $w['next'], $now ) )
								: __( 'Τέλος του ωραρίου.', 'live-operator' ),
							'tag'   => 'lop-presence',
							'url'   => LOP_App::url(),
						)
					);
				}
			}
		}
		self::reschedule();
	}

	/* =====================================================================
	 * Labels
	 * =================================================================== */

	/** Short weekday names, ISO order, from the plugin's msgids (§9). */
	public static function day_names(): array {
		return array(
			'1' => __( 'Δευ', 'live-operator' ),
			'2' => __( 'Τρι', 'live-operator' ),
			'3' => __( 'Τετ', 'live-operator' ),
			'4' => __( 'Πεμ', 'live-operator' ),
			'5' => __( 'Παρ', 'live-operator' ),
			'6' => __( 'Σαβ', 'live-operator' ),
			'7' => __( 'Κυρ', 'live-operator' ),
		);
	}

	/** «14:00» today, «Tue 10:00» this week, «25/12 10:00» later. */
	public static function label( int $ts, ?int $now = null ): string {
		$now = null === $now ? time() : $now;
		$hm  = wp_date( 'H:i', $ts );
		if ( wp_date( 'Y-m-d', $ts ) === wp_date( 'Y-m-d', $now ) ) {
			return $hm;
		}
		if ( $ts - $now < 6 * DAY_IN_SECONDS ) {
			return self::day_names()[ wp_date( 'N', $ts ) ] . ' ' . $hm;
		}
		return wp_date( 'j/n', $ts ) . ' ' . $hm;
	}

	/** For the app and the REST answers. */
	public static function payload(): array {
		$s = self::get();
		$w = self::window();
		return array(
			'on'           => $s['on'],
			'notify_start' => $s['notify_start'],
			'days'         => $s['days'],
			'closed'       => $s['closed'],
			'timezone'     => wp_timezone_string(),
			'enabled'      => self::enabled(),
			'active'       => self::active(),
			'paused_until' => self::paused_until(),
			'end'          => null !== $w['cur'] ? $w['cur'][1] : 0,
			'end_label'    => null !== $w['cur'] ? self::label( $w['cur'][1] ) : '',
			'next'         => $w['next'],
			'next_label'   => $w['next'] ? self::label( $w['next'] ) : '',
		);
	}
}

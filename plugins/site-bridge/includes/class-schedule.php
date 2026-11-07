<?php
/**
 * SBR_Schedule — the weekly schedule of the write window (1.12.0): the
 * window opens and closes by itself on chosen days and hours, in the
 * site's time zone (wp_timezone()). Same method as Live Operator's
 * LOP_Schedule (1.3.0), copied so the two plugins stay independent.
 *
 *  sbr_schedule (autoload, read by every write-window check)
 *    on       the schedule opens the window too (off by default, §14.1)
 *    days     ISO weekday '1' (Mon) … '7' (Sun) => up to 3 ranges
 *             [ 'HH:MM', 'HH:MM' ]; an end at or before the start ends
 *             on the next day (22:00–02:00), '24:00' = midnight
 *    closed   up to 30 dates 'Y-m-d' that open no window (a range is
 *             skipped when it starts on such a date); past dates are
 *             dropped on every save
 *  sbr_sched_state (autoload)
 *    pause       end of the stretch that «Close now» closed early: it
 *                counts as closed until then, the next one opens
 *    seen_start  start of the last stretch told «opened» (no repeat)
 *    seen_end    end of the last stretch told «closed» (no repeat)
 *
 * Safety (Bible §21): no stretch of open window is longer than 12 hours.
 * Ranges that touch or overlap are one stretch, so sanitize() drops a
 * range that would make a stretch longer than MAX minutes (wall clock),
 * and windows() cuts a stretch at MAX real minutes (a DST night).
 *
 * The window is worked out on every read (correct without cron, Bible
 * §14 «a state with an end date»). One WP-Cron event (sbr_schedule_tick)
 * at the next change, start or end, only writes the log, sends the
 * e-mail (SBR_Notify) and sets the next one. The option name starts
 * with sbr_, so no Site Bridge tool can change it (SBR_Guard).
 */

defined( 'ABSPATH' ) || exit;

final class SBR_Schedule {

	const OPTION = 'sbr_schedule';
	const STATE  = 'sbr_sched_state';
	const CRON   = 'sbr_schedule_tick';

	const RANGES = 3;
	const CLOSED = 30;

	/** Longest stretch of open window, in minutes (= the longest manual window). */
	const MAX = 720;

	/** Per-request caches. */
	private static $cache  = null;
	private static $window = array();

	public static function init(): void {
		add_action( self::CRON, array( __CLASS__, 'tick' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure' ) );
	}

	public static function defaults(): array {
		$days = array();
		for ( $d = 1; $d <= 7; $d++ ) {
			$days[ (string) $d ] = array();
		}
		return array(
			'on'     => false,
			'days'   => $days,
			'closed' => array(),
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

	/** Test helper and uninstall-free reset of the per-request caches. */
	public static function flush(): void {
		self::$cache  = null;
		self::$window = array();
	}

	/**
	 * Strict validation (Bible §11).
	 *
	 * @param array $raw     Raw values.
	 * @param int   $dropped Out: invalid ranges and dates.
	 */
	public static function sanitize( array $raw, int &$dropped = 0 ): array {
		$d   = self::defaults();
		$out = array( 'on' => isset( $raw['on'] ) ? (bool) $raw['on'] : $d['on'] );

		$days        = isset( $raw['days'] ) && is_array( $raw['days'] ) ? $raw['days'] : array();
		$out['days'] = $d['days'];
		$week        = array(); // Accepted ranges as minutes of the week, for the 12-hour check.
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
				$start = ( (int) $day - 1 ) * 1440 + $a;
				$end   = ( (int) $day - 1 ) * 1440 + $b + ( $b > $a ? 0 : 1440 );
				if ( ! self::fits( $week, array( $start, $end ) ) ) {
					++$dropped;
					continue;
				}
				$week[] = array( $start, $end );
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

	/**
	 * True when adding $new to the week's ranges keeps every stretch (ranges
	 * that touch or overlap, the week wrapping from Sunday to Monday) at
	 * MAX minutes or less.
	 */
	private static function fits( array $week, array $new ): bool {
		$all = array();
		foreach ( array_merge( $week, array( $new ) ) as $r ) {
			// Three copies: last week, this week, next week (wrap-around).
			foreach ( array( -10080, 0, 10080 ) as $shift ) {
				$all[] = array( $r[0] + $shift, $r[1] + $shift );
			}
		}
		usort(
			$all,
			static function ( $x, $y ) {
				return $x[0] <=> $y[0];
			}
		);
		$cur = null;
		foreach ( $all as $r ) {
			if ( null !== $cur && $r[0] <= $cur[1] ) {
				$cur[1] = max( $cur[1], $r[1] );
			} else {
				$cur = $r;
			}
			if ( $cur[1] - $cur[0] > self::MAX ) {
				return false;
			}
		}
		return true;
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
	 * The stretches (merged, sorted, at most MAX minutes) that touch
	 * [from, to), as [ start, end ] timestamps.
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
					if ( $end > $start ) {
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
		$keep = array();
		foreach ( $merged as $w ) {
			$w[1] = min( $w[1], $w[0] + self::MAX * MINUTE_IN_SECONDS ); // A DST night never makes it longer.
			if ( $w[1] > $from && $w[0] < $to ) {
				$keep[] = $w;
			}
		}
		return $keep;
	}

	/** A local day at 00:00 plus minutes (1440 = the next midnight). */
	private static function at( DateTimeImmutable $day, int $min ): int {
		if ( 1440 === $min ) {
			return $day->modify( '+1 day' )->getTimestamp();
		}
		return $day->setTime( intdiv( $min, 60 ), $min % 60 )->getTimestamp();
	}

	/**
	 * Now: the stretch we are in, and the start of the next one.
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
			// stretch: then a week more for every closed date.
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

	/** The schedule is on and the plugin is not switched off. */
	public static function enabled(): bool {
		return ! Site_Bridge::killed() && self::get()['on'];
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

	/** End of the stretch that keeps the window open now, or 0. */
	public static function open_until(): int {
		if ( ! self::enabled() ) {
			return 0;
		}
		$w = self::window();
		return null !== $w['cur'] && self::state()['pause'] < $w['cur'][1] ? (int) $w['cur'][1] : 0;
	}

	/** End of the paused stretch, or 0. */
	public static function paused_until(): int {
		if ( ! self::enabled() ) {
			return 0;
		}
		$w = self::window();
		return null !== $w['cur'] && self::state()['pause'] >= $w['cur'][1] ? (int) $w['cur'][1] : 0;
	}

	/** Start of the next stretch, or 0. */
	public static function next_open(): int {
		return self::enabled() ? (int) self::window()['next'] : 0;
	}

	/**
	 * «Close now» inside a stretch: closed until its end. Returns the end
	 * of the paused stretch, or 0 when the schedule kept nothing open.
	 */
	public static function pause(): int {
		$end = self::open_until();
		if ( $end ) {
			self::set_state( array( 'pause' => $end ) );
		}
		return $end;
	}

	/* =====================================================================
	 * Cron: log and e-mail at the changes
	 * =================================================================== */

	/** One event at the next change, or none. */
	public static function reschedule(): void {
		wp_clear_scheduled_hook( self::CRON );
		if ( ! self::enabled() ) {
			return;
		}
		$w  = self::window();
		$at = null !== $w['cur'] ? $w['cur'][1] : $w['next'];
		if ( $at > 0 ) {
			wp_schedule_single_event( $at + 1, self::CRON );
		}
	}

	/** Admin and cron: set the event when it went missing. */
	public static function ensure(): void {
		if ( self::enabled() && ! wp_next_scheduled( self::CRON ) ) {
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
				if ( $st['pause'] < $w['cur'][1] ) {
					SBR_Notify::change( 'schedule_open', 'schedule', array( 'until' => (int) $w['cur'][1] ) );
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
				if ( $st['pause'] < $ended ) {
					SBR_Notify::change(
						'schedule_close',
						'schedule',
						array(
							'ended' => (int) $ended,
							'next'  => (int) $w['next'],
							'still' => SBR_Settings::window_until(),
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
			'1' => __( 'Δευ', 'site-bridge' ),
			'2' => __( 'Τρι', 'site-bridge' ),
			'3' => __( 'Τετ', 'site-bridge' ),
			'4' => __( 'Πεμ', 'site-bridge' ),
			'5' => __( 'Παρ', 'site-bridge' ),
			'6' => __( 'Σαβ', 'site-bridge' ),
			'7' => __( 'Κυρ', 'site-bridge' ),
		);
	}

	/** «14:00» today, «Τρι 10:00» this week, «25/12 10:00» later. */
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

	/** One line per day for the log and the e-mails: «Mon 09:00–14:00». */
	public static function summary( array $s ): string {
		$en    = array( '1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat', '7' => 'Sun' );
		$lines = array( 'Schedule: ' . ( $s['on'] ? 'on' : 'off' ) );
		foreach ( $s['days'] as $d => $ranges ) {
			if ( $ranges ) {
				$lines[] = $en[ $d ] . ' ' . implode(
					', ',
					array_map(
						static function ( $r ) {
							return $r[0] . '–' . $r[1];
						},
						$ranges
					)
				);
			}
		}
		if ( $s['closed'] ) {
			$lines[] = 'Closed dates: ' . implode( ', ', $s['closed'] );
		}
		return implode( "\n", $lines );
	}
}

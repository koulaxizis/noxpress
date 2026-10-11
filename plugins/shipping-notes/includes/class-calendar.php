<?php
/**
 * SHN_Calendar — business days, cut-off and holidays (design §4.2).
 *
 * The same rules run in the browser (assets/delivery.js) so that dates on
 * cached pages are always computed at view time; this class serves the
 * admin preview, pre-order dates and the tests.
 *
 *  - Dispatch days: the store's working days (dl_workdays) that are not
 *    holidays. An order after the cut-off, or on a day off, starts on the
 *    next dispatch day.
 *  - Delivery days: Monday to Friday (and Saturday for methods that
 *    deliver on Saturdays) that are not holidays.
 *  - Estimate: start → + preparation dispatch days → + transit delivery days.
 */

defined( 'ABSPATH' ) || exit;

final class SHN_Calendar {

	/** Loop guard: no estimate goes further than this many calendar days. */
	const HORIZON = 400;

	/** Fixed public holidays of Greece ('MM-DD'). */
	const GR_FIXED = array( '01-01', '01-06', '03-25', '05-01', '08-15', '10-28', '12-25', '12-26' );

	/** True when $d is in the holiday list (recurring 'MM-DD' or dated 'YYYY-MM-DD'). */
	public static function is_holiday( DateTimeImmutable $d, array $holidays ): bool {
		return in_array( $d->format( 'm-d' ), $holidays, true ) || in_array( $d->format( 'Y-m-d' ), $holidays, true );
	}

	public static function is_dispatch_day( DateTimeImmutable $d, array $workdays, array $holidays ): bool {
		return in_array( (int) $d->format( 'N' ), $workdays, true ) && ! self::is_holiday( $d, $holidays );
	}

	public static function is_delivery_day( DateTimeImmutable $d, bool $saturday, array $holidays ): bool {
		$n = (int) $d->format( 'N' );
		return ( $n <= 5 || ( 6 === $n && $saturday ) ) && ! self::is_holiday( $d, $holidays );
	}

	/**
	 * First dispatch day for an order placed at $now (midnight of that day),
	 * or null when there is none within the horizon.
	 *
	 * @param string $cutoff 'HH:MM' or ''.
	 */
	public static function start( DateTimeImmutable $now, string $cutoff, array $workdays, array $holidays ): ?DateTimeImmutable {
		$day = $now->setTime( 0, 0 );
		if ( '' !== $cutoff && $now->format( 'H:i' ) >= $cutoff ) {
			$day = $day->modify( '+1 day' );
		}
		for ( $i = 0; $i < self::HORIZON; $i++ ) {
			if ( self::is_dispatch_day( $day, $workdays, $holidays ) ) {
				return $day;
			}
			$day = $day->modify( '+1 day' );
		}
		return null;
	}

	/** $d moved forward by $n dispatch days (0 = the same day). */
	public static function add_dispatch_days( DateTimeImmutable $d, int $n, array $workdays, array $holidays ): ?DateTimeImmutable {
		for ( $i = 0; $n > 0 && $i < self::HORIZON; $i++ ) {
			$d = $d->modify( '+1 day' );
			if ( self::is_dispatch_day( $d, $workdays, $holidays ) ) {
				--$n;
			}
		}
		return $n > 0 ? null : $d;
	}

	/** $d moved forward by $n delivery days (0 = the same day). */
	public static function add_delivery_days( DateTimeImmutable $d, int $n, bool $saturday, array $holidays ): ?DateTimeImmutable {
		for ( $i = 0; $n > 0 && $i < self::HORIZON; $i++ ) {
			$d = $d->modify( '+1 day' );
			if ( self::is_delivery_day( $d, $saturday, $holidays ) ) {
				--$n;
			}
		}
		return $n > 0 ? null : $d;
	}

	/**
	 * Earliest and latest delivery date, or null.
	 *
	 * @param DateTimeImmutable $start First dispatch day (from start()).
	 * @param array             $line  { pmin, pmax, tmin, tmax, sat }.
	 * @return DateTimeImmutable[]|null [ min, max ].
	 */
	public static function window( DateTimeImmutable $start, array $line, array $workdays, array $holidays ): ?array {
		$out = array();
		foreach ( array( 'min', 'max' ) as $k ) {
			$ship = self::add_dispatch_days( $start, (int) $line[ 'p' . $k ], $workdays, $holidays );
			$at   = $ship ? self::add_delivery_days( $ship, (int) $line[ 't' . $k ], ! empty( $line['sat'] ), $holidays ) : null;
			if ( ! $at ) {
				return null;
			}
			$out[] = $at;
		}
		return $out;
	}

	/** "Now" in the store's time zone. */
	public static function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', wp_timezone() );
	}

	/**
	 * Orthodox Easter Sunday (Gregorian date) of a year, 1900–2099
	 * (Meeus' Julian algorithm plus the 13-day calendar difference).
	 */
	public static function orthodox_easter( int $year ): DateTimeImmutable {
		$a     = $year % 4;
		$b     = $year % 7;
		$c     = $year % 19;
		$d     = ( 19 * $c + 15 ) % 30;
		$e     = ( 2 * $a + 4 * $b - $d + 34 ) % 7;
		$month = intdiv( $d + $e + 114, 31 );
		$day   = ( ( $d + $e + 114 ) % 31 ) + 1;
		$jul   = new DateTimeImmutable( sprintf( '%04d-%02d-%02d', $year, $month, $day ), new DateTimeZone( 'UTC' ) );
		return $jul->modify( '+13 days' );
	}

	/**
	 * Public holidays of Greece for a year: the fixed ones ('MM-DD') and
	 * the movable ones of Orthodox Easter ('YYYY-MM-DD'): Clean Monday, Good
	 * Friday, Easter Monday and Whit Monday.
	 */
	public static function greek_holidays( int $year ): array {
		$easter = self::orthodox_easter( $year );
		$out    = self::GR_FIXED;
		foreach ( array( '-48 days', '-2 days', '+1 day', '+50 days' ) as $shift ) {
			$out[] = $easter->modify( $shift )->format( 'Y-m-d' );
		}
		return $out;
	}
}

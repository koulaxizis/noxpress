<?php
/**
 * LOP_Hours — the public service hours (1.5.0): the weekly schedule shown
 * to visitors, from the same lop_schedule option the switch uses, so the
 * site never shows hours the chat does not keep.
 *
 * A module (Bible §20): off after activation. With
 * lop_settings['public_hours'] off this file is never loaded.
 *
 *  [lop_hours]                       the hours as one line per group of days
 *  [lop_hours format="list"]         the same as a list
 *  [lop_hours closed="1"]            plus the next closed dates
 *  [lop_hours before="Εξυπηρέτηση:"] a text in front of the hours
 *
 * Consecutive days with the same ranges are merged, so «Mon–Fri 10:00–20:00
 * · Sat–Sun 12:00–20:00» comes out of seven rows of the settings. Days with
 * no range are left out.
 *
 * With lop_settings['public_schema'] on, the same hours are added as
 * hoursAvailable to the contact points of the JSON-LD of Rank Math
 * (rank_math/json_ld) or Search Signals (noxpress_seo_graph, 1.5.1), for
 * search engines. Nothing is written on a visitor request (Bible §14.6) and any
 * error leaves the page exactly as it was (§14.31).
 */

defined( 'ABSPATH' ) || exit;

final class LOP_Hours {

	const SHORTCODE = 'lop_hours';

	/** Closed dates shown by closed="1". */
	const CLOSED_SHOWN = 6;

	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );

		if ( LOP_Settings::get()['public_schema'] ) {
			add_filter( 'rank_math/json_ld', array( __CLASS__, 'json_ld' ), 20 );
			// Search Signals (Bible §14.38): the same keyed graph shape.
			add_filter( 'noxpress_seo_graph', array( __CLASS__, 'json_ld' ), 20 );
		}
	}

	/* =====================================================================
	 * Shortcode
	 * =================================================================== */

	/**
	 * @param array|string $atts Shortcode attributes.
	 */
	public static function shortcode( $atts = array() ): string {
		try {
			$a = shortcode_atts(
				array(
					'format' => 'line',
					'closed' => '0',
					'before' => '',
					'after'  => '',
				),
				is_array( $atts ) ? $atts : array(),
				self::SHORTCODE
			);

			$groups = self::groups();
			if ( ! $groups ) {
				return '';
			}

			$rows = array();
			foreach ( $groups as $g ) {
				$rows[] = '<span class="lop-hours__days">' . esc_html( $g['days'] ) . '</span> <span class="lop-hours__time">' . esc_html( $g['time'] ) . '</span>';
			}

			if ( '1' === (string) $a['closed'] || 'yes' === strtolower( (string) $a['closed'] ) ) {
				$closed = self::closed_label();
				if ( '' !== $closed ) {
					$rows[] = '<span class="lop-hours__closed">' . esc_html( $closed ) . '</span>';
				}
			}

			$before = '' !== $a['before'] ? '<span class="lop-hours__before">' . esc_html( $a['before'] ) . '</span> ' : '';
			$after  = '' !== $a['after'] ? ' <span class="lop-hours__after">' . esc_html( $a['after'] ) . '</span>' : '';

			if ( 'list' === strtolower( (string) $a['format'] ) ) {
				// A list takes only list items (valid HTML), so the texts
				// before and after become items of their own.
				if ( '' !== $before ) {
					array_unshift( $rows, trim( $before ) );
				}
				if ( '' !== $after ) {
					$rows[] = trim( $after );
				}
				return '<ul class="lop-hours lop-hours--list"><li>' . implode( '</li><li>', $rows ) . '</li></ul>';
			}

			return '<span class="lop-hours">' . $before . implode( ' <span class="lop-hours__sep">·</span> ', $rows ) . $after . '</span>';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/* =====================================================================
	 * The groups of days
	 * =================================================================== */

	/**
	 * Days with ranges, consecutive equal ones merged.
	 *
	 * @return array<int, array{days: string, time: string, iso: int[], ranges: array}>
	 */
	public static function groups(): array {
		$days  = LOP_Schedule::get()['days'];
		$names = self::day_names();
		$out   = array();

		for ( $d = 1; $d <= 7; $d++ ) {
			$key    = (string) $d;
			$ranges = isset( $days[ $key ] ) && is_array( $days[ $key ] ) ? $days[ $key ] : array();
			if ( ! $ranges ) {
				continue;
			}
			$n = count( $out );
			if ( $n && $out[ $n - 1 ]['ranges'] === $ranges && $out[ $n - 1 ]['iso'][ count( $out[ $n - 1 ]['iso'] ) - 1 ] === $d - 1 ) {
				$out[ $n - 1 ]['iso'][] = $d;
				continue;
			}
			$out[] = array(
				'iso'    => array( $d ),
				'ranges' => $ranges,
			);
		}

		foreach ( $out as $i => $g ) {
			$first = $g['iso'][0];
			$last  = $g['iso'][ count( $g['iso'] ) - 1 ];

			if ( $first === $last ) {
				$label = $names[ $first ];
			} elseif ( $last - $first === 1 ) {
				$label = $names[ $first ] . ' ' . __( 'και', 'live-operator' ) . ' ' . $names[ $last ];
			} else {
				$label = $names[ $first ] . '–' . $names[ $last ];
			}

			$times = array();
			foreach ( $g['ranges'] as $r ) {
				$times[] = $r[0] . '–' . $r[1];
			}

			$out[ $i ]['days'] = $label;
			$out[ $i ]['time'] = implode( ', ', $times );
		}

		return $out;
	}

	/** The next closed dates as «Κλειστά: 25/12, 1/1». */
	private static function closed_label(): string {
		$dates = array();
		$today = wp_date( 'Y-m-d' );
		foreach ( LOP_Schedule::get()['closed'] as $ymd ) {
			if ( $ymd >= $today && count( $dates ) < self::CLOSED_SHOWN ) {
				$p = explode( '-', $ymd );
				if ( 3 === count( $p ) ) {
					$dates[] = (int) $p[2] . '/' . (int) $p[1];
				}
			}
		}
		if ( ! $dates ) {
			return '';
		}
		/* translators: %s: dates, e.g. 25/12, 1/1 */
		return sprintf( __( 'Κλειστά: %s', 'live-operator' ), implode( ', ', $dates ) );
	}

	/** Full weekday names, ISO order, from the plugin's msgids (§9). */
	public static function day_names(): array {
		return array(
			1 => __( 'Δευτέρα', 'live-operator' ),
			2 => __( 'Τρίτη', 'live-operator' ),
			3 => __( 'Τετάρτη', 'live-operator' ),
			4 => __( 'Πέμπτη', 'live-operator' ),
			5 => __( 'Παρασκευή', 'live-operator' ),
			6 => __( 'Σάββατο', 'live-operator' ),
			7 => __( 'Κυριακή', 'live-operator' ),
		);
	}

	/* =====================================================================
	 * Structured data
	 * =================================================================== */

	/** schema.org day names, ISO order. */
	private static function schema_days(): array {
		return array(
			1 => 'Monday',
			2 => 'Tuesday',
			3 => 'Wednesday',
			4 => 'Thursday',
			5 => 'Friday',
			6 => 'Saturday',
			7 => 'Sunday',
		);
	}

	/**
	 * hoursAvailable for a ContactPoint: one OpeningHoursSpecification per
	 * group of days and range.
	 */
	public static function specification(): array {
		$names = self::schema_days();
		$out   = array();
		foreach ( self::groups() as $g ) {
			$days = array();
			foreach ( $g['iso'] as $d ) {
				$days[] = $names[ $d ];
			}
			foreach ( $g['ranges'] as $r ) {
				$out[] = array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => $days,
					'opens'     => $r[0],
					'closes'    => '24:00' === $r[1] ? '23:59' : $r[1],
				);
			}
		}
		return $out;
	}

	/**
	 * The JSON-LD of Rank Math (rank_math/json_ld) or Search Signals
	 * (noxpress_seo_graph): the hours on every contact point that has none.
	 * Anything else in the graph is left untouched.
	 *
	 * @param mixed $data The entities the SEO plugin prints.
	 * @return mixed
	 */
	public static function json_ld( $data ) {
		try {
			if ( ! is_array( $data ) ) {
				return $data;
			}
			$spec = self::specification();
			if ( ! $spec ) {
				return $data;
			}
			foreach ( $data as $key => $entity ) {
				if ( ! is_array( $entity ) || ! isset( $entity['contactPoint'] ) || ! is_array( $entity['contactPoint'] ) ) {
					continue;
				}
				$points = isset( $entity['contactPoint']['@type'] ) ? array( $entity['contactPoint'] ) : $entity['contactPoint'];
				foreach ( $points as $i => $point ) {
					if ( is_array( $point ) && ! isset( $point['hoursAvailable'] ) ) {
						$points[ $i ]['hoursAvailable'] = $spec;
					}
				}
				$data[ $key ]['contactPoint'] = isset( $entity['contactPoint']['@type'] ) ? $points[0] : $points;
			}
			return $data;
		} catch ( \Throwable $e ) {
			return $data;
		}
	}
}

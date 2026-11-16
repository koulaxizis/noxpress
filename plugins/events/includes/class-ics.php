<?php
/**
 * EVT_Ics — «Πρόσθεσε στο ημερολόγιο» without third-party scripts.
 *
 *  - ?evt_ics=<id>  one event as an .ics file (RFC 5545, UTC times)
 *  - ?evt_feed=ics  every upcoming event (setting «ics_feed», off by default)
 *  - google()       a calendar.google.com «render» link (no script, no
 *                   request until the visitor clicks)
 *
 * Only public events, only when visitors may see the plugin (test mode:
 * managers only).
 */

defined( 'ABSPATH' ) || exit;

final class EVT_Ics {

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 5 );
	}

	public static function link( int $id ): string {
		return add_query_arg( 'evt_ics', $id, home_url( '/' ) );
	}

	public static function feed_link(): string {
		return add_query_arg( 'evt_feed', 'ics', home_url( '/' ) );
	}

	private static function utc( int $ts ): string {
		return gmdate( 'Ymd\THis\Z', $ts );
	}

	/** Where, as one line: venue, address, city, or «Online». */
	public static function location( array $e ): string {
		if ( $e['online'] && ! $e['venue'] ) {
			return '' !== $e['online_url'] ? $e['online_url'] : 'Online';
		}
		if ( ! $e['venue'] ) {
			return '';
		}
		$v = $e['venue'];
		return implode( ', ', array_filter( array( $v['name'], $v['address'], trim( $v['postcode'] . ' ' . $v['city'] ) ) ) );
	}

	public static function google( array $e ): string {
		$end = $e['end'] > $e['start'] ? $e['end'] : $e['start'] + HOUR_IN_SECONDS;
		return add_query_arg(
			array(
				'action'   => 'TEMPLATE',
				'text'     => rawurlencode( $e['title'] ),
				'dates'    => self::utc( $e['start'] ) . '/' . self::utc( $end ),
				'details'  => rawurlencode( $e['url'] ),
				'location' => rawurlencode( self::location( $e ) ),
			),
			'https://calendar.google.com/calendar/render'
		);
	}

	/** RFC 5545 text value. */
	private static function esc( string $v ): string {
		$v = str_replace( array( '\\', ';', ',', "\r\n", "\n", "\r" ), array( '\\\\', '\;', '\,', '\n', '\n', '' ), $v );
		return $v;
	}

	/** Folds a content line at 75 octets without splitting a UTF-8 character. */
	private static function fold( string $line ): string {
		$out = '';
		$cur = '';
		$len = 0;
		foreach ( preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
			$b = strlen( $ch );
			if ( $len + $b > 75 ) {
				$out .= $cur . "\r\n ";
				$cur  = '';
				$len  = 1;
			}
			$cur .= $ch;
			$len += $b;
		}
		return $out . $cur . "\r\n";
	}

	public static function vevent( array $e ): string {
		$end    = $e['end'] > $e['start'] ? $e['end'] : $e['start'] + HOUR_IN_SECONDS;
		$host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$status = 'cancelled' === $e['status'] ? 'CANCELLED' : ( 'postponed' === $e['status'] ? 'TENTATIVE' : 'CONFIRMED' );
		$mod    = (int) get_post_modified_time( 'U', true, $e['id'] );
		$lines  = array(
			'BEGIN:VEVENT',
			'UID:event-' . $e['id'] . '@' . $host,
			'DTSTAMP:' . self::utc( $mod > 0 ? $mod : time() ),
			'DTSTART:' . self::utc( $e['start'] ),
			'DTEND:' . self::utc( $end ),
			'SUMMARY:' . self::esc( $e['title'] ),
			'URL:' . self::esc( $e['url'] ),
			'STATUS:' . $status,
		);
		$loc = self::location( $e );
		if ( '' !== $loc ) {
			$lines[] = 'LOCATION:' . self::esc( $loc );
		}
		$desc = trim( $e['excerpt'] . "\n\n" . $e['url'] );
		$lines[] = 'DESCRIPTION:' . self::esc( $desc );
		$lines[] = 'END:VEVENT';
		$out     = '';
		foreach ( $lines as $l ) {
			$out .= self::fold( $l );
		}
		return $out;
	}

	public static function calendar( array $events ): string {
		$name = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$out  = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Noxpress//Events " . EVT_VERSION . "//EN\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n";
		$out .= self::fold( 'X-WR-CALNAME:' . self::esc( $name ) );
		foreach ( $events as $e ) {
			if ( $e['start'] > 0 ) {
				$out .= self::vevent( $e );
			}
		}
		return $out . "END:VCALENDAR\r\n";
	}

	/** A refused .ics request gets the theme's 404 page, not the home page. */
	private static function not_found(): void {
		global $wp_query;
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
	}

	public static function serve(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public read-only files.
		$id   = isset( $_GET['evt_ics'] ) ? absint( wp_unslash( $_GET['evt_ics'] ) ) : 0;
		$feed = isset( $_GET['evt_feed'] ) && 'ics' === sanitize_key( wp_unslash( (string) $_GET['evt_feed'] ) );
		// phpcs:enable
		if ( ! $id && ! $feed ) {
			return;
		}
		if ( ! EVT_Front::visible() ) {
			self::not_found();
			return;
		}
		if ( $id ) {
			$e = EVT_CPT::event( $id );
			if ( ! $e || $e['start'] <= 0 ) {
				self::not_found();
				return;
			}
			$body = self::calendar( array( $e ) );
			$file = 'event-' . $id . '.ics';
		} else {
			if ( ! EVT_Settings::get()['ics_feed'] ) {
				self::not_found();
				return;
			}
			$body = self::calendar( EVT_Query::find( array( 'limit' => 50 ) ) );
			$file = 'events.ics';
		}
		status_header( 200 );
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: ' . ( $feed ? 'inline' : 'attachment' ) . '; filename="' . $file . '"' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: ' . ( 'live' === EVT_Settings::mode() ? 'public, max-age=900' : 'private, no-store' ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- text/calendar, values escaped by esc().
		exit;
	}
}

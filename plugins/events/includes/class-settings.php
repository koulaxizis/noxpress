<?php
/**
 * EVT_Settings — the evt_settings option and its strict validation
 * (Bible §11, whitelists; unknown keys dropped, bad values → defaults).
 *
 * Keys:
 *  - mode        'off' | 'test' (default: only managers see the pages) | 'live'
 *  - lang        visitor texts: 'auto' (site locale) | 'el' | 'en'
 *  - base        URL base of the event pages (default 'events')
 *  - venue_base  URL base of the venue pages (default 'venues')
 *  - on_product  «Επόμενες εκδηλώσεις» box on product pages (default on)
 *  - on_creator  the same box on Featured Creators pages (default on)
 *  - box_count   events in those boxes (1–10, default 3)
 *  - ics_feed    the .ics feed of every upcoming event (default off)
 *  - schema      Event JSON-LD (default on)
 *  - delete_all  delete the events and venues on uninstall (default off)
 *  - t_<key>_el / t_<key>_en  visitor texts (Bible §14.17)
 */

defined( 'ABSPATH' ) || exit;

final class EVT_Settings {

	const OPT = 'evt_settings';

	/** @var array|null Per-request cache. */
	private static $cache = null;

	public static function texts(): array {
		return array(
			'upcoming'    => array( 'Επόμενες εκδηλώσεις', 'Upcoming events', 80 ),
			'past'        => array( 'Περασμένες εκδηλώσεις', 'Past events', 80 ),
			'none'        => array( 'Δεν υπάρχουν επερχόμενες εκδηλώσεις.', 'There are no upcoming events.', 160 ),
			'all'         => array( 'Όλες οι εκδηλώσεις', 'All events', 60 ),
			'when'        => array( 'Πότε', 'When', 40 ),
			'where'       => array( 'Πού', 'Where', 40 ),
			'entry'       => array( 'Είσοδος', 'Entry', 40 ),
			'free'        => array( 'Ελεύθερη είσοδος', 'Free entry', 60 ),
			'ticket'      => array( 'Με εισιτήριο', 'Ticketed', 60 ),
			'online'      => array( 'Online', 'Online', 40 ),
			'join'        => array( 'Σύνδεσμος της εκδήλωσης', 'Event link', 60 ),
			'tickets'     => array( 'Εισιτήρια', 'Tickets', 40 ),
			'rsvp'        => array( 'Δήλωση συμμετοχής', 'RSVP', 40 ),
			'map'         => array( 'Χάρτης', 'Map', 40 ),
			'calendar'    => array( 'Πρόσθεσε στο ημερολόγιο', 'Add to calendar', 60 ),
			'gcal'        => array( 'Google Calendar', 'Google Calendar', 40 ),
			'ics'         => array( 'Apple / Outlook (.ics)', 'Apple / Outlook (.ics)', 40 ),
			'postponed'   => array( 'Αναβλήθηκε', 'Postponed', 40 ),
			'cancelled'   => array( 'Ακυρώθηκε', 'Cancelled', 40 ),
			'rescheduled' => array( 'Νέα ημερομηνία', 'New date', 40 ),
			'related'     => array( 'Σχετικά', 'Related', 40 ),
			'with'        => array( 'Με', 'With', 40 ),
			'ended'       => array( 'Η εκδήλωση έγινε', 'This event has ended', 60 ),
		);
	}

	public static function defaults(): array {
		$d = array(
			'mode'       => 'test',
			'lang'       => 'auto',
			'base'       => 'events',
			'venue_base' => 'venues',
			'on_product' => true,
			'on_creator' => true,
			'box_count'  => 3,
			'ics_feed'   => false,
			'schema'     => true,
			'delete_all' => false,
		);
		foreach ( self::texts() as $k => $t ) {
			$d[ 't_' . $k . '_el' ] = $t[0];
			$d[ 't_' . $k . '_en' ] = $t[1];
		}
		return $d;
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPT, array() );
			self::$cache = self::sanitize( is_array( $raw ) ? $raw : array() );
		}
		return self::$cache;
	}

	public static function save( array $raw ): void {
		$old   = self::get();
		$clean = self::sanitize( $raw );
		update_option( self::OPT, $clean, true );
		self::$cache = $clean;
		if ( $old['base'] !== $clean['base'] || $old['venue_base'] !== $clean['venue_base'] ) {
			update_option( 'evt_flush', 1, true );
		}
	}

	/** A URL base: lowercase letters, digits and dashes (Latin, so links stay plain). */
	private static function slug( $v, string $def ): string {
		$v = is_string( $v ) ? sanitize_title( $v ) : '';
		if ( false !== strpos( $v, '%' ) ) {
			return $def; // Non-Latin letters (percent-encoded): keep the default.
		}
		$v = trim( (string) preg_replace( '/[^a-z0-9-]/', '', $v ), '-' );
		return ( '' !== $v && strlen( $v ) <= 40 ) ? $v : $def;
	}

	public static function sanitize( array $raw ): array {
		$d = self::defaults();
		$s = array();

		$s['mode'] = isset( $raw['mode'] ) && in_array( $raw['mode'], array( 'off', 'test', 'live' ), true ) ? $raw['mode'] : $d['mode'];
		$s['lang'] = isset( $raw['lang'] ) && in_array( $raw['lang'], array( 'auto', 'el', 'en' ), true ) ? $raw['lang'] : $d['lang'];

		$s['base']       = self::slug( $raw['base'] ?? null, $d['base'] );
		$s['venue_base'] = self::slug( $raw['venue_base'] ?? null, $d['venue_base'] );
		if ( $s['venue_base'] === $s['base'] ) {
			$s['venue_base'] = $s['base'] . '-venues';
		}

		foreach ( array( 'on_product', 'on_creator', 'ics_feed', 'schema', 'delete_all' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}
		$s['box_count']  = self::range( $raw, 'box_count', 1, 10, $d['box_count'] );

		foreach ( self::texts() as $k => $t ) {
			foreach ( array( 'el', 'en' ) as $l ) {
				$f       = 't_' . $k . '_' . $l;
				$v       = isset( $raw[ $f ] ) && is_string( $raw[ $f ] ) ? trim( sanitize_text_field( $raw[ $f ] ) ) : null;
				$s[ $f ] = null === $v ? $d[ $f ] : mb_substr( $v, 0, $t[2] );
			}
			if ( '' === $s[ 't_' . $k . '_el' ] ) {
				$s[ 't_' . $k . '_el' ] = $d[ 't_' . $k . '_el' ];
			}
		}
		return $s;
	}

	private static function range( array $raw, string $key, int $min, int $max, int $def ): int {
		$v = isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ? (int) $raw[ $key ] : $def;
		return max( $min, min( $max, $v ) );
	}

	public static function mode(): string {
		return self::get()['mode'];
	}

	public static function visitor_lang(): string {
		$l = self::get()['lang'];
		if ( 'el' === $l || 'en' === $l ) {
			return $l;
		}
		$locale = function_exists( 'determine_locale' ) && ! is_admin() ? determine_locale() : get_locale();
		return EVT_Lang::from_locale( (string) $locale );
	}

	/**
	 * A visitor text in the visitor language; an empty English text shows the
	 * Greek one (Bible §14.17). Filter evt_text( $text, $key, $lang ).
	 */
	public static function text( string $key, string $lang = '' ): string {
		$s    = self::get();
		$lang = '' !== $lang ? $lang : self::visitor_lang();
		$v    = isset( $s[ 't_' . $key . '_' . $lang ] ) ? (string) $s[ 't_' . $key . '_' . $lang ] : '';
		if ( '' === $v && 'en' === $lang ) {
			$v = isset( $s[ 't_' . $key . '_el' ] ) ? (string) $s[ 't_' . $key . '_el' ] : '';
		}
		return (string) apply_filters( 'evt_text', $v, $key, $lang );
	}
}

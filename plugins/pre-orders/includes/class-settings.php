<?php
/**
 * POR_Settings — the por_settings option (autoload: read on every product
 * view), strict validation with whitelists (Bible §11), visitor language,
 * texts and dates.
 *
 *  - mode     'off' (default) | 'test' (shop managers only) | 'live'
 *  - lang     visitor text language: 'auto' (site locale) | 'el' | 'en'
 *  - badge    where the «Pre-order» badge shows: 'both' (default) |
 *             'loop' (product cards) | 'single' (product page) | 'none'
 *  - mixed    a cart with pre-orders and available products:
 *             'allow' (default, a note in the classic cart / checkout) |
 *             'warn' (a notice that suggests two orders) |
 *             'block' (pre-orders and available products never share a cart)
 *  - date_fmt 'store' (WordPress's date format, default) | 'custom'
 *  - date_custom  a PHP date format when date_fmt is 'custom'
 *  - t_*      { el, en } visitor texts (Bible §14.17)
 *
 * The texts are store settings, not msgids. Empty Greek = the default,
 * empty English = shows the Greek.
 */

defined( 'ABSPATH' ) || exit;

final class POR_Settings {

	const OPT = 'por_settings';

	/** Text keys => max length. */
	const TEXTS = array(
		't_badge'       => 40,
		't_button'      => 40,
		't_date'        => 120,
		't_tba'         => 120,
		't_line'        => 40,
		't_mixed_allow' => 300,
		't_mixed_warn'  => 300,
		't_mixed_block' => 300,
	);

	/** @var array|null Per-request cache. */
	private static $cache = null;

	/** @var string|null Per-request "today" in the site's time zone. */
	private static $today = null;

	public static function defaults(): array {
		return array(
			'mode'          => 'off',
			'lang'          => 'auto',
			'badge'         => 'both',
			'mixed'         => 'allow',
			'date_fmt'      => 'store',
			'date_custom'   => 'j/n/Y',
			't_badge'       => array(
				'el' => 'Προπαραγγελία',
				'en' => 'Pre-order',
			),
			't_button'      => array(
				'el' => 'Προπαραγγελία',
				'en' => 'Pre-order',
			),
			't_date'        => array(
				'el' => 'Κυκλοφορεί στις {date}',
				'en' => 'Releases on {date}',
			),
			't_tba'         => array(
				'el' => 'Σύντομα διαθέσιμο',
				'en' => 'Coming soon',
			),
			't_line'        => array(
				'el' => 'Προπαραγγελία',
				'en' => 'Pre-order',
			),
			't_mixed_allow' => array(
				'el' => 'Το καλάθι σας έχει προϊόντα σε προπαραγγελία. Η παραγγελία θα αποσταλεί όταν κυκλοφορήσουν.',
				'en' => 'Your cart holds pre-order products. The order ships once they are released.',
			),
			't_mixed_warn'  => array(
				'el' => 'Το καλάθι σας έχει προϊόντα σε προπαραγγελία μαζί με διαθέσιμα προϊόντα. Η παραγγελία θα αποσταλεί ολόκληρη όταν κυκλοφορήσουν όλα. Αν θέλετε τα διαθέσιμα νωρίτερα, κάντε δύο ξεχωριστές παραγγελίες.',
				'en' => 'Your cart holds pre-order products together with available ones. The whole order ships once everything is released. If you want the available products sooner, place two separate orders.',
			),
			't_mixed_block' => array(
				'el' => 'Τα προϊόντα σε προπαραγγελία αγοράζονται σε ξεχωριστή παραγγελία από τα διαθέσιμα προϊόντα. Ολοκληρώστε πρώτα την παραγγελία που έχετε στο καλάθι.',
				'en' => 'Pre-order products are bought in a separate order from available products. Please complete the order in your cart first.',
			),
		);
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPT, array() );
			self::$cache = self::sanitize( is_array( $raw ) ? $raw : array() );
		}
		return self::$cache;
	}

	public static function save( array $s ): void {
		$s           = self::sanitize( $s );
		self::$cache = $s;
		update_option( self::OPT, $s, true );
	}

	/** Drops the per-request caches (tests, imports). */
	public static function flush(): void {
		self::$cache = null;
		self::$today = null;
	}

	/** Whitelist validation: unknown keys are dropped, bad values fall back to the defaults. */
	public static function sanitize( array $raw ): array {
		$d = self::defaults();
		$s = array();

		$s['mode']     = self::pick( $raw, 'mode', array( 'off', 'test', 'live' ), $d['mode'] );
		$s['lang']     = self::pick( $raw, 'lang', array( 'auto', 'el', 'en' ), $d['lang'] );
		$s['badge']    = self::pick( $raw, 'badge', array( 'both', 'loop', 'single', 'none' ), $d['badge'] );
		$s['mixed']    = self::pick( $raw, 'mixed', array( 'allow', 'warn', 'block' ), $d['mixed'] );
		$s['date_fmt'] = self::pick( $raw, 'date_fmt', array( 'store', 'custom' ), $d['date_fmt'] );

		$fmt                = isset( $raw['date_custom'] ) && is_string( $raw['date_custom'] ) ? self::line( $raw['date_custom'], 30 ) : '';
		$s['date_custom']   = '' !== $fmt ? $fmt : $d['date_custom'];

		foreach ( self::TEXTS as $key => $len ) {
			$s[ $key ] = $d[ $key ];
			if ( isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ) {
				foreach ( array( 'el', 'en' ) as $l ) {
					$v = isset( $raw[ $key ][ $l ] ) && is_string( $raw[ $key ][ $l ] ) ? self::line( $raw[ $key ][ $l ], $len ) : '';
					if ( 'el' === $l && '' === $v ) {
						continue; // Empty Greek = the default.
					}
					$s[ $key ][ $l ] = $v;
				}
			}
		}
		return $s;
	}

	/** One line of plain text, at most $len characters. */
	public static function line( string $v, int $len ): string {
		return mb_substr( trim( sanitize_text_field( $v ) ), 0, $len );
	}

	/** One of the allowed string values, else the default. */
	private static function pick( array $raw, string $key, array $allowed, string $default ): string {
		return isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && in_array( $raw[ $key ], $allowed, true ) ? $raw[ $key ] : $default;
	}

	/* =====================================================================
	 * Readers
	 * =================================================================== */

	/**
	 * The mode in effect: 'live' falls back to 'test' while another
	 * pre-order plugin is active (Bible §14.23: never both at once).
	 */
	public static function mode(): string {
		$mode = self::get()['mode'];
		if ( 'live' === $mode && self::rival() ) {
			return 'test';
		}
		return $mode;
	}

	/** True when the current visitor sees the pre-order treatment (mode + test mode, Bible §14.1–2). */
	public static function visible(): bool {
		if ( Pre_Orders::killed() ) {
			return false;
		}
		$mode = self::mode();
		if ( 'live' === $mode ) {
			return true;
		}
		return 'test' === $mode && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Name of another active pre-order plugin, or '' (cached per request).
	 * Any active plugin whose folder or main file names pre-orders.
	 */
	public static function rival(): string {
		static $found = null;
		if ( null !== $found ) {
			return $found;
		}
		$found  = '';
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$ours = plugin_basename( POR_FILE );
		foreach ( $active as $file ) {
			$file = (string) $file;
			if ( $file === $ours ) {
				continue;
			}
			if ( preg_match( '/pre-?orders?|preorder|prena/i', $file ) ) {
				$found = $file;
				break;
			}
		}
		return $found;
	}

	/** Visitor language for the texts: 'el' | 'en' (Bible §14.17). */
	public static function lang(): string {
		$choice = self::get()['lang'];
		if ( 'el' === $choice || 'en' === $choice ) {
			return $choice;
		}
		return 0 === strpos( strtolower( (string) get_locale() ), 'el' ) ? 'el' : 'en';
	}

	/**
	 * A visitor text in the visitor language (empty English → Greek).
	 *
	 * @param string $key A key of TEXTS.
	 */
	public static function text( string $key ): string {
		$s    = self::get();
		$lang = self::lang();
		$pair = isset( $s[ $key ] ) && is_array( $s[ $key ] ) ? $s[ $key ] : array(
			'el' => '',
			'en' => '',
		);
		$text = ( 'en' === $lang && '' !== $pair['en'] ) ? $pair['en'] : $pair['el'];
		/**
		 * Filters a visitor text of Pre-Orders (e.g. for WPML).
		 *
		 * @param string $text Text.
		 * @param string $key  Text key (t_badge, t_button, t_date, t_tba, t_line, t_mixed_*).
		 * @param string $lang 'el' | 'en'.
		 */
		return (string) apply_filters( 'por_text', $text, $key, $lang );
	}

	/** Today as 'Y-m-d' in the site's time zone. */
	public static function today(): string {
		if ( null === self::$today ) {
			self::$today = wp_date( 'Y-m-d' );
		}
		return self::$today;
	}

	/** True for a real calendar date 'Y-m-d'. */
	public static function valid_date( string $d ): bool {
		return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/** Midnight of a 'Y-m-d' date in the site's time zone, as a timestamp (0 for a bad date). */
	public static function timestamp( string $date ): int {
		if ( ! self::valid_date( $date ) ) {
			return 0;
		}
		$dt = date_create_immutable( $date . ' 00:00:00', wp_timezone() );
		return $dt ? $dt->getTimestamp() : 0;
	}

	/** A 'Y-m-d' date in the store's format for visitors. */
	public static function format_date( string $date ): string {
		$ts = self::timestamp( $date );
		if ( ! $ts ) {
			return '';
		}
		$s   = self::get();
		$fmt = 'custom' === $s['date_fmt'] ? $s['date_custom'] : (string) get_option( 'date_format', 'Y-m-d' );
		return (string) wp_date( '' !== $fmt ? $fmt : 'Y-m-d', $ts );
	}

	/**
	 * «Releases on {date}» or «Coming soon» for a release date ('' = no date).
	 *
	 * @param string $date 'Y-m-d' or ''.
	 */
	public static function release_text( string $date ): string {
		if ( '' === $date ) {
			return self::text( 't_tba' );
		}
		return str_replace( '{date}', self::format_date( $date ), self::text( 't_date' ) );
	}
}

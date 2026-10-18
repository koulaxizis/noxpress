<?php
/**
 * PKP_Settings — the pkp_settings option (autoload: read on every checkout
 * view), strict validation with whitelists (Bible §11).
 *
 *  - mode          'off' (default) | 'test' (shop managers only) | 'live'
 *  - lang          visitor text language: 'auto' (site locale) | 'el' | 'en'
 *  - hide_address  hide the shipping address when the method is chosen
 *                  (checkout form, order, emails); default on
 *  - stores        list of stores, in display order, max MAX_STORES:
 *                  { id, name, address, details_el, details_en, map, active }
 *                  id: 4–16 [a-z0-9], made once and never changed, so the
 *                  per-zone choice of stores survives edits and reorders
 *  - no_pickup     rule (1.1.0): hide the method when the package holds a
 *                  product of one of these shipping classes (e.g. print on
 *                  demand sent by a partner), or (1.1.1) when it is the
 *                  package the Printful plugin made ($package['printful']):
 *                  { on: bool (default false), printful: bool (default
 *                  true), classes: [ shipping class slugs ] }
 *  - admin_line    "Pickup at: <store>" at the top of the admin New order
 *                  email (1.1.0); default on
 *  - t_heading / t_details / t_required / t_map / t_blocked / t_admin
 *                  { el, en } texts
 *
 * Store names and addresses are the store's own data, typed once (no
 * translation); the hours / details have a Greek and an English value.
 */

defined( 'ABSPATH' ) || exit;

final class PKP_Settings {

	const OPT        = 'pkp_settings';
	const MAX_STORES = 20;

	/** Store field => max length. */
	const STORE_FIELDS = array(
		'name'       => 100,
		'address'    => 200,
		'details_el' => 300,
		'details_en' => 300,
		'map'        => 500,
	);

	/** Text keys => max length. */
	const TEXTS = array(
		't_heading'  => 100,
		't_details'  => 100,
		't_required' => 200,
		't_map'      => 50,
		't_blocked'  => 300,
		't_admin'    => 50,
	);

	/** Most shipping classes the no-pickup rule can name. */
	const MAX_CLASSES = 20;

	/** @var array|null Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'mode'         => 'off',
			'lang'         => 'auto',
			'hide_address' => true,
			'stores'       => array(),
			'no_pickup'    => array(
				'on'       => false,
				'printful' => true,
				'classes'  => array(),
			),
			'admin_line'   => true,
			't_heading'    => array(
				'el' => 'Κατάστημα παραλαβής',
				'en' => 'Pickup store',
			),
			't_details'    => array(
				'el' => 'Ώρες και διευκρινίσεις',
				'en' => 'Hours and details',
			),
			't_required'   => array(
				'el' => 'Διαλέξτε κατάστημα παραλαβής.',
				'en' => 'Please choose a pickup store.',
			),
			't_map'        => array(
				'el' => 'Χάρτης',
				'en' => 'Map',
			),
			't_blocked'    => array(
				'el' => 'Τα προϊόντα που τυπώνονται κατά παραγγελία αποστέλλονται απευθείας από τον συνεργάτη παραγωγής, γι\' αυτό η παραλαβή από το κατάστημα δεν είναι διαθέσιμη γι\' αυτά.',
				'en' => 'Print-on-demand products are shipped directly by our production partner, so store pickup is not available for them.',
			),
			't_admin'      => array(
				'el' => 'Παραλαβή από',
				'en' => 'Pickup at',
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

	/** Drops the per-request cache (tests, imports). */
	public static function flush(): void {
		self::$cache = null;
	}

	/** Whitelist validation: unknown keys are dropped, bad values fall back to the defaults. */
	public static function sanitize( array $raw ): array {
		$d = self::defaults();
		$s = array();

		$s['mode']         = self::pick( $raw, 'mode', array( 'off', 'test', 'live' ), $d['mode'] );
		$s['lang']         = self::pick( $raw, 'lang', array( 'auto', 'el', 'en' ), $d['lang'] );
		$s['hide_address'] = isset( $raw['hide_address'] ) ? (bool) $raw['hide_address'] : $d['hide_address'];
		$s['admin_line']   = isset( $raw['admin_line'] ) ? (bool) $raw['admin_line'] : $d['admin_line'];

		$np             = isset( $raw['no_pickup'] ) && is_array( $raw['no_pickup'] ) ? $raw['no_pickup'] : array();
		$s['no_pickup'] = array(
			'on'       => ! empty( $np['on'] ),
			'printful' => isset( $np['printful'] ) ? (bool) $np['printful'] : $d['no_pickup']['printful'],
			'classes'  => array(),
		);
		if ( isset( $np['classes'] ) && is_array( $np['classes'] ) ) {
			foreach ( $np['classes'] as $slug ) {
				$slug = is_string( $slug ) ? sanitize_title( $slug ) : '';
				if ( '' !== $slug && strlen( $slug ) <= 200 && ! in_array( $slug, $s['no_pickup']['classes'], true ) ) {
					$s['no_pickup']['classes'][] = $slug;
				}
				if ( count( $s['no_pickup']['classes'] ) >= self::MAX_CLASSES ) {
					break;
				}
			}
		}

		$s['stores'] = array();
		$seen        = array();
		if ( isset( $raw['stores'] ) && is_array( $raw['stores'] ) ) {
			foreach ( $raw['stores'] as $row ) {
				$store = is_array( $row ) ? self::store_row( $row ) : null;
				if ( null === $store || isset( $seen[ $store['id'] ] ) ) {
					continue;
				}
				$seen[ $store['id'] ] = true;
				$s['stores'][]        = $store;
				if ( count( $s['stores'] ) >= self::MAX_STORES ) {
					break;
				}
			}
		}

		foreach ( self::TEXTS as $key => $len ) {
			$s[ $key ] = $d[ $key ];
			if ( isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ) {
				foreach ( array( 'el', 'en' ) as $l ) {
					$v = isset( $raw[ $key ][ $l ] ) && is_string( $raw[ $key ][ $l ] ) ? self::line( $raw[ $key ][ $l ], $len ) : '';
					// Empty Greek = the default; empty English = shows the Greek.
					if ( 'el' === $l && '' === $v ) {
						continue;
					}
					$s[ $key ][ $l ] = $v;
				}
			}
		}

		return $s;
	}

	/**
	 * One validated store, or null when it has no name or no valid id.
	 *
	 * @param array $row Raw store.
	 */
	private static function store_row( array $row ): ?array {
		$id = isset( $row['id'] ) && is_string( $row['id'] ) ? $row['id'] : '';
		if ( ! self::valid_id( $id ) ) {
			return null;
		}
		$out = array( 'id' => $id );
		foreach ( self::STORE_FIELDS as $field => $len ) {
			$v = isset( $row[ $field ] ) && is_string( $row[ $field ] ) ? $row[ $field ] : '';
			if ( 'map' === $field ) {
				$v = self::url( $v, $len );
			} elseif ( 'details_el' === $field || 'details_en' === $field ) {
				$v = self::lines( $v, $len );
			} else {
				$v = self::line( $v, $len );
			}
			$out[ $field ] = $v;
		}
		if ( '' === $out['name'] ) {
			return null;
		}
		$out['active'] = ! empty( $row['active'] );
		return $out;
	}

	public static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^[a-z0-9]{4,16}$/', $id );
	}

	/** A new store id (8 hex characters). */
	public static function new_id(): string {
		return bin2hex( random_bytes( 4 ) );
	}

	/** One line of plain text, at most $len characters. */
	private static function line( string $v, int $len ): string {
		return mb_substr( trim( sanitize_text_field( $v ) ), 0, $len );
	}

	/** Plain text that keeps line breaks (at most 5 lines), at most $len characters. */
	private static function lines( string $v, int $len ): string {
		$rows = array();
		foreach ( preg_split( '/\R/u', $v ) as $row ) {
			$row = trim( sanitize_text_field( $row ) );
			if ( '' !== $row ) {
				$rows[] = $row;
			}
			if ( count( $rows ) >= 5 ) {
				break;
			}
		}
		return mb_substr( implode( "\n", $rows ), 0, $len );
	}

	/** An http(s) URL or ''. */
	private static function url( string $v, int $len ): string {
		$v = trim( $v );
		if ( '' === $v || strlen( $v ) > $len ) {
			return '';
		}
		$v = esc_url_raw( $v, array( 'http', 'https' ) );
		return ( '' !== $v && wp_http_validate_url( $v ) ) ? $v : '';
	}

	/** One of the allowed string values, else the default. */
	private static function pick( array $raw, string $key, array $allowed, string $default ): string {
		return isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && in_array( $raw[ $key ], $allowed, true ) ? $raw[ $key ] : $default;
	}

	/* =====================================================================
	 * Readers
	 * =================================================================== */

	/** Active stores, in display order. */
	public static function active_stores(): array {
		return array_values(
			array_filter(
				self::get()['stores'],
				static function ( $st ) {
					return $st['active'];
				}
			)
		);
	}

	/** An active store by id, or null. */
	public static function store( string $id ): ?array {
		foreach ( self::active_stores() as $st ) {
			if ( $st['id'] === $id ) {
				return $st;
			}
		}
		return null;
	}

	/** True when the current visitor may see the method (mode + test mode). */
	public static function visible(): bool {
		$mode = self::get()['mode'];
		if ( 'live' === $mode ) {
			return true;
		}
		return 'test' === $mode && current_user_can( 'manage_woocommerce' );
	}

	/** Visitor language for the texts: 'el' | 'en' (Bible §14.17). */
	public static function lang(): string {
		$choice = self::get()['lang'];
		if ( 'el' === $choice || 'en' === $choice ) {
			return $choice;
		}
		return 0 === strpos( strtolower( (string) get_locale() ), 'el' ) ? 'el' : 'en';
	}

	/** The hours / details of a store in the visitor language (empty English → Greek). */
	public static function details( array $store ): string {
		return ( 'en' === self::lang() && '' !== $store['details_en'] ) ? $store['details_en'] : $store['details_el'];
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
		 * Filters a visitor text of Store Pickup (e.g. for WPML).
		 *
		 * @param string $text Text.
		 * @param string $key  Text key (t_heading, t_details, t_required, t_map).
		 * @param string $lang 'el' | 'en'.
		 */
		return (string) apply_filters( 'pkp_text', $text, $key, $lang );
	}
}

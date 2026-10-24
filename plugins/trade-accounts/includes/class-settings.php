<?php
/**
 * TRA_Settings — the tra_settings option and its strict validation
 * (Bible §11, whitelists; unknown keys dropped, bad values → defaults).
 *
 * Keys:
 *  - mods       array  module => bool ("pricing", "feed"); every module is
 *                      off until switched on
 *  - mode       string 'test' (wholesale prices only for managers, as a
 *                      preview; default) | 'live' (trade accounts) | 'off'
 *  - lang       string visitor texts: 'auto' (site locale) | 'el' | 'en'
 *  - discount   float  default discount of every trade account, % (0–90)
 *  - base       string 'regular': the discount is taken off the regular
 *                      price, and a lower sale price wins; 'active': off
 *                      the price the shop shows now (sale price included)
 *  - cats       int[]  eligible product categories (their subcategories
 *                      count too); empty = no product is eligible
 *  - brands     int[]  eligible brands (product_brand); empty = any brand
 *  - physical   bool   only physical products (not virtual, not
 *                      downloadable); default on
 *  - label      bool   «Τιμή χονδρικής» next to the price (default on)
 *  - invoice    bool   trade orders need an invoice (classic checkout)
 *  - inv_field  string checkout field of the invoicing plugin
 *  - inv_value  string its value for "invoice"
 *  - rate       int    feed calls per hour and account (10–2000)
 *  - contrib    string[] attribute taxonomies (pa_…) that name people,
 *                      in the feed's "contributors" with their labels
 *  - t_<key>_el / t_<key>_en  visitor texts (Bible §14.17), keys in texts()
 *
 * Autoloaded: the shop reads it on every request of a logged-in user.
 */

defined( 'ABSPATH' ) || exit;

final class TRA_Settings {

	const OPT = 'tra_settings';

	const MODULES = array( 'pricing', 'feed' );

	/** @var array|null Per-request cache. */
	private static $cache = null;

	/**
	 * Visitor texts: key => array( default EL, default EN, max length ).
	 * Plain text (sanitize_text_field), never msgids (Bible §14.17).
	 */
	public static function texts(): array {
		return array(
			'label'   => array( 'Τιμή χονδρικής', 'Trade price', 60 ),
			'invoice' => array( 'Οι παραγγελίες χονδρικής εκδίδονται με τιμολόγιο. Διάλεξε «Τιμολόγιο» και συμπλήρωσε τα στοιχεία της επιχείρησης.', 'Trade orders are invoiced. Choose «Invoice» and fill in the business details.', 300 ),
		);
	}

	public static function defaults(): array {
		$d = array(
			'mods'      => array_fill_keys( self::MODULES, false ),
			'mode'      => 'test',
			'lang'      => 'auto',
			'discount'  => 40.0,
			'base'      => 'regular',
			'cats'      => array(),
			'brands'    => array(),
			'physical'  => true,
			'label'     => true,
			'invoice'   => false,
			'inv_field' => 'billing_receipt_type',
			'inv_value' => 'timologio',
			'rate'      => 120,
			'contrib'   => array(),
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
		$clean = self::sanitize( $raw );
		update_option( self::OPT, $clean, true );
		self::$cache = $clean;
	}

	public static function sanitize( array $raw ): array {
		$d = self::defaults();
		$s = array();

		$s['mods'] = array();
		$mods      = isset( $raw['mods'] ) && is_array( $raw['mods'] ) ? $raw['mods'] : array();
		foreach ( self::MODULES as $m ) {
			$s['mods'][ $m ] = ! empty( $mods[ $m ] );
		}

		$s['mode'] = isset( $raw['mode'] ) && in_array( $raw['mode'], array( 'test', 'live', 'off' ), true ) ? $raw['mode'] : $d['mode'];
		$s['lang'] = isset( $raw['lang'] ) && in_array( $raw['lang'], array( 'auto', 'el', 'en' ), true ) ? $raw['lang'] : $d['lang'];
		$s['base'] = isset( $raw['base'] ) && in_array( $raw['base'], array( 'regular', 'active' ), true ) ? $raw['base'] : $d['base'];

		$s['discount'] = isset( $raw['discount'] ) && is_numeric( $raw['discount'] ) ? self::percent( (float) $raw['discount'] ) : $d['discount'];

		$s['cats']   = self::ids( isset( $raw['cats'] ) ? $raw['cats'] : array(), 'product_cat' );
		$s['brands'] = self::ids( isset( $raw['brands'] ) ? $raw['brands'] : array(), 'product_brand' );

		foreach ( array( 'physical', 'label', 'invoice' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}

		foreach ( array( 'inv_field', 'inv_value' ) as $k ) {
			$v       = isset( $raw[ $k ] ) && is_string( $raw[ $k ] ) ? trim( $raw[ $k ] ) : '';
			$s[ $k ] = preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $v ) ? $v : $d[ $k ];
		}

		$rate      = isset( $raw['rate'] ) && is_numeric( $raw['rate'] ) ? (int) $raw['rate'] : $d['rate'];
		$s['rate'] = max( 10, min( 2000, $rate ) );

		$s['contrib'] = array();
		$contrib      = isset( $raw['contrib'] ) ? $raw['contrib'] : array();
		if ( is_string( $contrib ) ) {
			$contrib = preg_split( '/[\s,]+/', $contrib );
		}
		foreach ( (array) $contrib as $tax ) {
			$tax = is_string( $tax ) ? sanitize_key( $tax ) : '';
			if ( '' !== $tax && 0 === strpos( $tax, 'pa_' ) && ! in_array( $tax, $s['contrib'], true ) && count( $s['contrib'] ) < 30 ) {
				$s['contrib'][] = $tax;
			}
		}

		foreach ( self::texts() as $k => $t ) {
			foreach ( array( 'el', 'en' ) as $l ) {
				$f       = 't_' . $k . '_' . $l;
				$v       = isset( $raw[ $f ] ) && is_string( $raw[ $f ] ) ? trim( sanitize_text_field( $raw[ $f ] ) ) : null;
				$s[ $f ] = null === $v ? $d[ $f ] : mb_substr( $v, 0, $t[2] );
			}
		}
		// The invoice message never stays empty (the English one may: it falls back to Greek).
		if ( '' === $s['t_invoice_el'] ) {
			$s['t_invoice_el'] = $d['t_invoice_el'];
		}

		return $s;
	}

	/** A discount percentage kept between 0 and 90, two decimals. */
	public static function percent( float $p ): float {
		return round( max( 0.0, min( 90.0, $p ) ), 2 );
	}

	/**
	 * Term ids of a taxonomy, unique, existing only (when the taxonomy is
	 * registered: before init the ids are kept as they are).
	 */
	private static function ids( $raw, string $tax ): array {
		$out = array();
		foreach ( (array) $raw as $id ) {
			$id = is_numeric( $id ) ? (int) $id : 0;
			if ( $id <= 0 || in_array( $id, $out, true ) || count( $out ) >= 200 ) {
				continue;
			}
			if ( did_action( 'init' ) && taxonomy_exists( $tax ) && ! term_exists( $id, $tax ) ) {
				continue;
			}
			$out[] = $id;
		}
		return $out;
	}

	/** True when the module is switched on. */
	public static function module( string $m ): bool {
		$s = self::get();
		return ! empty( $s['mods'][ $m ] );
	}

	/** Visitor language: the setting, or the site locale ('auto'). */
	public static function visitor_lang(): string {
		$l = self::get()['lang'];
		if ( 'el' === $l || 'en' === $l ) {
			return $l;
		}
		$locale = function_exists( 'determine_locale' ) && ! is_admin() ? determine_locale() : get_locale();
		return TRA_Lang::from_locale( (string) $locale );
	}

	/**
	 * A visitor text in the visitor language; an empty English text shows
	 * the Greek one (Bible §14.17).
	 */
	public static function text( string $key, string $lang = '' ): string {
		$s    = self::get();
		$lang = '' !== $lang ? $lang : self::visitor_lang();
		$v    = isset( $s[ 't_' . $key . '_' . $lang ] ) ? (string) $s[ 't_' . $key . '_' . $lang ] : '';
		if ( '' === $v && 'en' === $lang ) {
			$v = isset( $s[ 't_' . $key . '_el' ] ) ? (string) $s[ 't_' . $key . '_el' ] : '';
		}
		/**
		 * Filters a visitor text (e.g. for WPML / Polylang).
		 *
		 * @param string $v    Text.
		 * @param string $key  label | invoice.
		 * @param string $lang 'el' | 'en'.
		 */
		return (string) apply_filters( 'tra_text', $v, $key, $lang );
	}
}

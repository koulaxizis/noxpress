<?php
/**
 * ORC_Settings — the orc_settings option and its strict validation
 * (Bible §11, whitelists; unknown keys dropped, bad values → defaults).
 *
 * Keys:
 *  - mods        array  module => bool. Only "newsletter" exists in 1.0.0;
 *                       every module is off until switched on.
 *  - mode        string 'test' (managers only, default) | 'live' | 'off'
 *  - lang        string visitor texts: 'auto' (site locale) | 'el' | 'en'
 *  - nl_url      string the list's sign-up address on the orOS relay,
 *                       https://<host>/nl/s/<16 a-z0-9>, or ''
 *  - nl_name     bool   name field in the form (default on)
 *  - nl_checkout bool   consent checkbox at checkout (WooCommerce, off)
 *  - nl_doi      bool   confirmation e-mail for checkout sign-ups too
 *                       (off by default: the checkout consent is enough)
 *  - nl_key      string shop key, 32 letters/digits, or '' (signs checkout
 *                       sign-ups so orOS can skip the confirmation)
 *  - t_<key>_el / t_<key>_en  visitor texts (Bible §14.17), keys in texts()
 *
 * Autoloaded: the front end reads it on every request where a form or the
 * checkout can show.
 */

defined( 'ABSPATH' ) || exit;

final class ORC_Settings {

	const OPT = 'orc_settings';

	/** Modules of this version. Future ones (stock, receipt, inbox) join here. */
	const MODULES = array( 'newsletter' );

	/** @var array|null Per-request cache. */
	private static $cache = null;

	/**
	 * Visitor texts: key => array( default EL, default EN, max length ).
	 * Plain text (sanitize_text_field), never msgids (Bible §14.17).
	 */
	public static function texts(): array {
		return array(
			'title'    => array( 'Newsletter', 'Newsletter', 120 ),
			'intro'    => array( 'Νέα και προσφορές στο email σου.', 'News and offers in your inbox.', 300 ),
			'email'    => array( 'Το email σου', 'Your email', 80 ),
			'name'     => array( 'Όνομα (προαιρετικό)', 'Name (optional)', 80 ),
			'button'   => array( 'Εγγραφή', 'Subscribe', 40 ),
			'note'     => array( 'Θα λάβεις email επιβεβαίωσης. Μπορείς να διαγραφείς όποτε θέλεις.', 'You will get a confirmation email. You can unsubscribe at any time.', 300 ),
			'checkbox' => array( 'Θέλω να λαμβάνω το newsletter με νέα και προσφορές.', 'I want to receive the newsletter with news and offers.', 300 ),
		);
	}

	public static function defaults(): array {
		$d = array(
			'mods'        => array_fill_keys( self::MODULES, false ),
			'mode'        => 'test',
			'lang'        => 'auto',
			'nl_url'      => '',
			'nl_name'     => true,
			'nl_checkout' => false,
			'nl_doi'      => false,
			'nl_key'      => '',
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

		$url         = isset( $raw['nl_url'] ) && is_string( $raw['nl_url'] ) ? $raw['nl_url'] : '';
		$s['nl_url'] = ORC_Relay::parse_link( $url );

		foreach ( array( 'nl_name', 'nl_checkout', 'nl_doi' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}

		$key         = isset( $raw['nl_key'] ) && is_string( $raw['nl_key'] ) ? trim( $raw['nl_key'] ) : '';
		$s['nl_key'] = preg_match( '/^[A-Za-z0-9]{32}$/', $key ) ? $key : '';

		foreach ( self::texts() as $k => $t ) {
			foreach ( array( 'el', 'en' ) as $i => $l ) {
				$f       = 't_' . $k . '_' . $l;
				$v       = isset( $raw[ $f ] ) && is_string( $raw[ $f ] ) ? trim( sanitize_text_field( $raw[ $f ] ) ) : null;
				$s[ $f ] = null === $v ? $d[ $f ] : mb_substr( $v, 0, $t[2] );
			}
		}
		// Required texts never stay empty (the English one may: it falls back to Greek).
		foreach ( array( 'email', 'button', 'checkbox' ) as $k ) {
			if ( '' === $s[ 't_' . $k . '_el' ] ) {
				$s[ 't_' . $k . '_el' ] = $d[ 't_' . $k . '_el' ];
			}
		}

		return $s;
	}

	/** True when the module is switched on (and the plugin is not killed). */
	public static function module( string $m ): bool {
		$s = self::get();
		return ! empty( $s['mods'][ $m ] );
	}

	/**
	 * Whether the current visitor sees the front-end parts: live for
	 * everyone, test for managers only, off for nobody.
	 */
	public static function visible(): bool {
		if ( Oros_Connect::killed() ) {
			return false;
		}
		$mode = self::get()['mode'];
		if ( 'live' === $mode ) {
			return true;
		}
		return 'test' === $mode && current_user_can( Oros_Connect::cap() );
	}

	/** Visitor language: the setting, or the site locale ('auto'). */
	public static function visitor_lang(): string {
		$l = self::get()['lang'];
		if ( 'el' === $l || 'en' === $l ) {
			return $l;
		}
		$locale = function_exists( 'determine_locale' ) && ! is_admin() ? determine_locale() : get_locale();
		return ORC_Lang::from_locale( (string) $locale );
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
		 * @param string $key  title | intro | email | name | button | note | checkbox.
		 * @param string $lang 'el' | 'en'.
		 */
		return (string) apply_filters( 'orc_text', $v, $key, $lang );
	}

	/** A fresh random shop key (32 letters and digits). */
	public static function new_key(): string {
		return wp_generate_password( 32, false, false );
	}
}

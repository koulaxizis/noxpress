<?php
/**
 * EBD_Settings — the ebd_settings option and its strict validation
 * (Bible §11, whitelists; unknown keys dropped, bad values → defaults).
 *
 * Keys:
 *  - mode         string 'off' (default: files served as before, no
 *                        library) | 'test' (stamping and library only for
 *                        shop managers) | 'live'
 *  - lang         string visitor texts: 'auto' (site locale) | 'el' | 'en'
 *  - pdf, epub    bool   stamp PDF / EPUB files (default on)
 *  - name_style   string how the buyer's name shows in the visible line and
 *                        the Ex libris page: 'initial' («Μαρία Π.», default)
 *                        | 'full' («Μαρία Παπαδοπούλου»); the metadata keep
 *                        the full name
 *  - size         int    point size of the line (5–12, default 7)
 *  - max_mb       int    larger source files are served unstamped
 *                        (1–2048 MB, default 300)
 *  - cache_days   int    days a stamped copy is kept (1–30, default 7)
 *  - clean_names  bool   «{author} - {title}.ext» as the download name
 *                        (default off)
 *  - author_tax   string attribute taxonomy of the author (pa_…, '' = none)
 *  - library      bool   the «Η βιβλιοθήκη μου» page (default on, shown only
 *                        when the mode is test / live)
 *  - lib_replace  bool   the library hides WooCommerce's «Downloads» tab
 *                        (default on)
 *  - email_link   bool   a link to the library in the order emails that
 *                        carry downloads (default on)
 *  - t_<key>_el / t_<key>_en  visitor texts (Bible §14.17), keys in texts()
 *
 * Autoloaded: the download handler and the account page read it.
 */

defined( 'ABSPATH' ) || exit;

final class EBD_Settings {

	const OPT = 'ebd_settings';

	/** @var array|null Per-request cache. */
	private static $cache = null;

	/**
	 * Visitor texts: key => array( default EL, default EN, max length ).
	 * Plain text (sanitize_text_field), never msgids (Bible §14.17).
	 */
	public static function texts(): array {
		return array(
			// Watermark.
			'line'      => array( 'Αγοράστηκε από {name} · Παραγγελία #{order} · {code}', 'Purchased by {name} · Order #{order} · {code}', 160 ),
			'ex_title'  => array( 'Ex libris', 'Ex libris', 60 ),
			'ex_owner'  => array( 'Αυτό το αντίγραφο ανήκει σε: {name}', 'This copy belongs to: {name}', 160 ),
			'ex_order'  => array( 'Παραγγελία #{order} · {date}', 'Order #{order} · {date}', 160 ),
			'ex_note'   => array( 'Σε ευχαριστούμε που το αγόρασες. Κράτησέ το για σένα.', 'Thank you for buying it. Please keep it to yourself.', 200 ),
			// Library.
			'tab'       => array( 'Η βιβλιοθήκη μου', 'My library', 60 ),
			'empty'     => array( 'Δεν έχεις ακόμα ψηφιακά βιβλία ή αρχεία.', 'You have no digital books or files yet.', 200 ),
			'browse'    => array( 'Δες τον κατάλογο', 'Browse the shop', 60 ),
			'bought'    => array( 'Αγορά: {date}', 'Bought: {date}', 80 ),
			'left'      => array( 'Λήψεις που απομένουν: {n}', 'Downloads left: {n}', 80 ),
			'unlimited' => array( 'Απεριόριστες λήψεις', 'Unlimited downloads', 80 ),
			'expires'   => array( 'Διαθέσιμο έως {date}', 'Available until {date}', 80 ),
			'email'     => array( 'Τα ψηφιακά σου αρχεία είναι πάντα στη σελίδα «{tab}» του λογαριασμού σου:', 'Your digital files are always on the «{tab}» page of your account:', 200 ),
		);
	}

	public static function defaults(): array {
		$d = array(
			'mode'        => 'off',
			'lang'        => 'auto',
			'pdf'         => true,
			'epub'        => true,
			'name_style'  => 'initial',
			'size'        => 7,
			'max_mb'      => 300,
			'cache_days'  => 7,
			'clean_names' => false,
			'author_tax'  => '',
			'library'     => true,
			'lib_replace' => true,
			'email_link'  => true,
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

		$s['mode']       = isset( $raw['mode'] ) && in_array( $raw['mode'], array( 'off', 'test', 'live' ), true ) ? $raw['mode'] : $d['mode'];
		$s['lang']       = isset( $raw['lang'] ) && in_array( $raw['lang'], array( 'auto', 'el', 'en' ), true ) ? $raw['lang'] : $d['lang'];
		$s['name_style'] = isset( $raw['name_style'] ) && in_array( $raw['name_style'], array( 'initial', 'full' ), true ) ? $raw['name_style'] : $d['name_style'];

		foreach ( array( 'pdf', 'epub', 'clean_names', 'library', 'lib_replace', 'email_link' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}

		$s['size']       = self::range( $raw, 'size', 5, 12, $d['size'] );
		$s['max_mb']     = self::range( $raw, 'max_mb', 1, 2048, $d['max_mb'] );
		$s['cache_days'] = self::range( $raw, 'cache_days', 1, 30, $d['cache_days'] );

		$tax             = isset( $raw['author_tax'] ) && is_string( $raw['author_tax'] ) ? sanitize_key( $raw['author_tax'] ) : '';
		$s['author_tax'] = ( '' === $tax || ( 0 === strpos( $tax, 'pa_' ) && strlen( $tax ) <= 32 ) ) ? $tax : '';

		foreach ( self::texts() as $k => $t ) {
			foreach ( array( 'el', 'en' ) as $l ) {
				$f       = 't_' . $k . '_' . $l;
				$v       = isset( $raw[ $f ] ) && is_string( $raw[ $f ] ) ? trim( sanitize_text_field( $raw[ $f ] ) ) : null;
				$s[ $f ] = null === $v ? $d[ $f ] : mb_substr( $v, 0, $t[2] );
			}
			// A Greek text never stays empty (an empty English one falls back to it).
			if ( '' === $s[ 't_' . $k . '_el' ] ) {
				$s[ 't_' . $k . '_el' ] = $d[ 't_' . $k . '_el' ];
			}
		}

		return $s;
	}

	/** An integer setting kept inside its range. */
	private static function range( array $raw, string $key, int $min, int $max, int $def ): int {
		$v = isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ? (int) $raw[ $key ] : $def;
		return max( $min, min( $max, $v ) );
	}

	/** 'off' | 'test' | 'live'. */
	public static function mode(): string {
		return self::get()['mode'];
	}

	/**
	 * Whether the plugin acts for the current user: live for everyone,
	 * test only for shop managers (Bible §14.2).
	 */
	public static function active_for_user(): bool {
		$mode = self::mode();
		if ( 'live' === $mode ) {
			return true;
		}
		return 'test' === $mode && current_user_can( 'manage_woocommerce' );
	}

	/** Visitor language: the setting, or the site locale ('auto'). */
	public static function visitor_lang(): string {
		$l = self::get()['lang'];
		if ( 'el' === $l || 'en' === $l ) {
			return $l;
		}
		$locale = function_exists( 'determine_locale' ) && ! is_admin() ? determine_locale() : get_locale();
		return EBD_Lang::from_locale( (string) $locale );
	}

	/**
	 * A visitor text with its placeholders filled; an empty English text
	 * shows the Greek one (Bible §14.17).
	 *
	 * @param string $key  A key of texts().
	 * @param array  $vars Placeholder => value.
	 * @param string $lang 'el' | 'en' | '' (visitor language).
	 */
	public static function text( string $key, array $vars = array(), string $lang = '' ): string {
		$s    = self::get();
		$lang = '' !== $lang ? $lang : self::visitor_lang();
		$v    = isset( $s[ 't_' . $key . '_' . $lang ] ) ? (string) $s[ 't_' . $key . '_' . $lang ] : '';
		if ( '' === $v && 'en' === $lang ) {
			$v = isset( $s[ 't_' . $key . '_el' ] ) ? (string) $s[ 't_' . $key . '_el' ] : '';
		}
		foreach ( $vars as $name => $value ) {
			$v = str_replace( '{' . $name . '}', (string) $value, $v );
		}
		// Unknown placeholders never reach the visitor.
		$v = trim( (string) preg_replace( '/\{[a-z_]+\}/', '', $v ) );
		/**
		 * Filters a visitor text (e.g. for WPML / Polylang).
		 *
		 * @param string $v    Text, placeholders filled.
		 * @param string $key  A key of EBD_Settings::texts().
		 * @param string $lang 'el' | 'en'.
		 */
		return (string) apply_filters( 'ebd_text', $v, $key, $lang );
	}
}

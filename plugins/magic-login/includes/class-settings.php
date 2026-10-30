<?php
/**
 * MGL_Settings — the mgl_settings option and its strict validation
 * (Bible §11, whitelists; unknown keys dropped, bad values → defaults).
 *
 * Keys:
 *  - mode      string 'off' (default: nothing on the front end) | 'test'
 *                     (button only with ?mgl-test, links only to the test
 *                     accounts) | 'live'
 *  - lang      string visitor texts and e-mail: 'auto' (site locale) |
 *                     'el' | 'en'
 *  - account   bool   button in the My Account login form (default on)
 *  - checkout  bool   button in the classic checkout login form (default on)
 *  - ttl       int    minutes a link stays valid (5–60, default 15)
 *  - remember  bool   «remember me» cookie after a login by link (14 days,
 *                     WordPress's own; default on)
 *  - per_acct  int    links per account in 15 minutes (1–10, default 3)
 *  - per_ip    int    requests per IP address in an hour (5–200, default 20)
 *  - testers   string[] e-mails of the test accounts (test mode, up to 10)
 *  - t_<key>_el / t_<key>_en  visitor texts (Bible §14.17), keys in texts()
 *
 * Autoloaded: the login forms read it.
 */

defined( 'ABSPATH' ) || exit;

final class MGL_Settings {

	const OPT = 'mgl_settings';

	/** Rate-limit window of the per-account limit, in seconds. */
	const ACCT_WINDOW = 900;

	/** @var array|null Per-request cache. */
	private static $cache = null;

	/**
	 * Visitor texts: key => array( default EL, default EN, max length ).
	 * Plain text (sanitize_text_field), never msgids (Bible §14.17).
	 * {minutes} and {email} are filled in on output.
	 */
	public static function texts(): array {
		return array(
			'button'  => array( 'Στείλε μου σύνδεσμο σύνδεσης', 'Email me a login link', 60 ),
			'hint'    => array( 'Χωρίς κωδικό: γράψε το email σου και πάτα το κουμπί. Θα λάβεις σύνδεσμο που ισχύει {minutes} λεπτά.', 'No password needed: enter your email and press the button. The link you get is valid for {minutes} minutes.', 200 ),
			'sent'    => array( 'Αν το email ανήκει σε λογαριασμό, σε λίγο θα λάβεις σύνδεσμο σύνδεσης. Ισχύει {minutes} λεπτά και μία φορά. Δες και στα ανεπιθύμητα.', 'If the email belongs to an account, a login link is on its way. It is valid for {minutes} minutes and works once. Check your spam folder too.', 300 ),
			'empty'   => array( 'Γράψε πρώτα το email του λογαριασμού σου.', 'Enter the email of your account first.', 200 ),
			'limit'   => array( 'Ζήτησες αρκετούς συνδέσμους. Δοκίμασε ξανά σε λίγα λεπτά ή συνδέσου με τον κωδικό σου.', 'You have asked for several links. Try again in a few minutes or log in with your password.', 200 ),
			'title'   => array( 'Σύνδεση στον λογαριασμό σου', 'Log in to your account', 100 ),
			'confirm' => array( 'Πάτα το κουμπί για να συνδεθείς ως {email}.', 'Press the button to log in as {email}.', 200 ),
			'go'      => array( 'Σύνδεση', 'Log in', 40 ),
			'invalid' => array( 'Ο σύνδεσμος έληξε ή έχει ήδη χρησιμοποιηθεί. Ζήτησε νέο από τη φόρμα σύνδεσης.', 'The link has expired or has already been used. Ask for a new one from the login form.', 200 ),
			'stale'   => array( 'Η σελίδα έμεινε ανοιχτή πολύ ώρα. Δοκίμασε ξανά.', 'The page was open for too long. Please try again.', 200 ),
		);
	}

	public static function defaults(): array {
		$d = array(
			'mode'     => 'off',
			'lang'     => 'auto',
			'account'  => true,
			'checkout' => true,
			'ttl'      => 15,
			'remember' => true,
			'per_acct' => 3,
			'per_ip'   => 20,
			'testers'  => array(),
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

		$s['mode'] = isset( $raw['mode'] ) && in_array( $raw['mode'], array( 'off', 'test', 'live' ), true ) ? $raw['mode'] : $d['mode'];
		$s['lang'] = isset( $raw['lang'] ) && in_array( $raw['lang'], array( 'auto', 'el', 'en' ), true ) ? $raw['lang'] : $d['lang'];

		foreach ( array( 'account', 'checkout', 'remember' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}

		$s['ttl']      = self::range( $raw, 'ttl', 5, 60, $d['ttl'] );
		$s['per_acct'] = self::range( $raw, 'per_acct', 1, 10, $d['per_acct'] );
		$s['per_ip']   = self::range( $raw, 'per_ip', 5, 200, $d['per_ip'] );

		$s['testers'] = array();
		$testers      = isset( $raw['testers'] ) ? $raw['testers'] : array();
		if ( is_string( $testers ) ) {
			$testers = preg_split( '/[\s,;]+/', $testers );
		}
		foreach ( (array) $testers as $e ) {
			$e = is_string( $e ) ? strtolower( trim( sanitize_email( $e ) ) ) : '';
			if ( '' !== $e && is_email( $e ) && ! in_array( $e, $s['testers'], true ) && count( $s['testers'] ) < 10 ) {
				$s['testers'][] = $e;
			}
		}

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

	/** Visitor language: the setting, or the site locale ('auto'). */
	public static function visitor_lang(): string {
		$l = self::get()['lang'];
		if ( 'el' === $l || 'en' === $l ) {
			return $l;
		}
		$locale = function_exists( 'determine_locale' ) && ! is_admin() ? determine_locale() : get_locale();
		return MGL_Lang::from_locale( (string) $locale );
	}

	/**
	 * A visitor text in the visitor language with its placeholders filled;
	 * an empty English text shows the Greek one (Bible §14.17).
	 *
	 * @param string $key  A key of texts().
	 * @param array  $vars Placeholder => value, e.g. array( 'email' => 'a***@b.gr' ).
	 * @param string $lang 'el' | 'en' | '' (visitor language).
	 */
	public static function text( string $key, array $vars = array(), string $lang = '' ): string {
		$s    = self::get();
		$lang = '' !== $lang ? $lang : self::visitor_lang();
		$v    = isset( $s[ 't_' . $key . '_' . $lang ] ) ? (string) $s[ 't_' . $key . '_' . $lang ] : '';
		if ( '' === $v && 'en' === $lang ) {
			$v = isset( $s[ 't_' . $key . '_el' ] ) ? (string) $s[ 't_' . $key . '_el' ] : '';
		}
		$vars = array_merge( array( 'minutes' => (string) $s['ttl'] ), $vars );
		foreach ( $vars as $name => $value ) {
			$v = str_replace( '{' . $name . '}', (string) $value, $v );
		}
		/**
		 * Filters a visitor text (e.g. for WPML / Polylang).
		 *
		 * @param string $v    Text, placeholders filled.
		 * @param string $key  A key of MGL_Settings::texts().
		 * @param string $lang 'el' | 'en'.
		 */
		return (string) apply_filters( 'mgl_text', $v, $key, $lang );
	}
}

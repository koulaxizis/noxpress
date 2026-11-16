<?php
/**
 * LGD_Settings — the lgd_settings option and its strict validation
 * (Bible §11, whitelists; unknown keys dropped, bad values → defaults).
 *
 * Keys:
 *  - mode        'off' (default: nothing counted or blocked) | 'test'
 *                (counted and logged, nobody blocked) | 'live'
 *  - ip_fails    failures from one IP that lock it (2–50, default 5)
 *  - ip_window   minutes in which they count (1–1440, default 15)
 *  - ip_lock     minutes an IP stays locked (1–1440, default 15)
 *  - esc_count   lockouts in 24 hours that make the next one long (2–20, 4)
 *  - esc_hours   hours of the long lockout (1–168, default 24)
 *  - acct_fails  failures on one account, from any IP, that lock password
 *                logins to it (5–500, default 20)
 *  - acct_window minutes in which they count (5–1440, default 60)
 *  - acct_lock   minutes the account stays locked (1–1440, default 15)
 *  - unlock_mail bool  «email me an unlock link» for a locked account
 *  - allow       string[] trusted IPs or CIDR ranges, never counted (≤100)
 *  - xmlrpc      'auth_off' (default: no logins, no multicall) | 'closed'
 *                (403 on xmlrpc.php) | 'open' (WordPress as it is)
 *  - pingbacks   bool  pingbacks off (default on)
 *  - enum_rest / enum_author / enum_sitemap / enum_oembed / enum_errors
 *                bool  user-enumeration protections (default on)
 *  - enum_archives bool author archives /author/name/ answer 404 (off)
 *  - lostpw      bool  limits on lost-password requests (default on)
 *  - notify_device bool new-device e-mail to administrators (default on)
 *  - notify_surge  bool attack digest to the site e-mail (default on)
 *  - surge_at    lockouts in an hour that send the digest (3–500, 10)
 *  - lang        visitor texts: 'auto' (site locale) | 'el' | 'en'
 *  - keep        bool  keep the settings and the log on uninstall
 *  - t_<key>_el / t_<key>_en  visitor texts (Bible §14.17), keys in texts()
 *
 * Autoloaded: every login reads it.
 */

defined( 'ABSPATH' ) || exit;

final class LGD_Settings {

	const OPT = 'lgd_settings';

	/** Minutes an unlock link stays valid. */
	const UNLOCK_TTL = 15;

	/** Lost-password and unlock requests: per IP and per account in an hour. */
	const REQ_PER_IP   = 5;
	const REQ_PER_ACCT = 3;

	/** @var array|null Per-request cache. */
	private static $cache = null;

	/**
	 * Visitor texts: key => array( default EL, default EN, max length ).
	 * Plain text, never msgids (Bible §14.17). {minutes}, {site} and {link}
	 * are filled in on output.
	 */
	public static function texts(): array {
		return array(
			'invalid'        => array( 'Λάθος όνομα χρήστη, email ή κωδικός.', 'Wrong username, email or password.', 200 ),
			'locked_ip'      => array( 'Πάρα πολλές αποτυχημένες προσπάθειες σύνδεσης. Δοκίμασε ξανά σε {minutes} λεπτά.', 'Too many failed login attempts. Please try again in {minutes} minutes.', 200 ),
			'locked_acct'    => array( 'Η σύνδεση σε αυτόν τον λογαριασμό είναι προσωρινά κλειδωμένη. Δοκίμασε ξανά σε {minutes} λεπτά.', 'Logins to this account are locked for a while. Please try again in {minutes} minutes.', 200 ),
			'limited'        => array( 'Πάρα πολλά αιτήματα. Δοκίμασε ξανά σε μία ώρα.', 'Too many requests. Please try again in an hour.', 200 ),
			'unlock_link'    => array( 'Στείλε μου σύνδεσμο ξεκλειδώματος', 'Email me an unlock link', 60 ),
			'unlock_title'   => array( 'Ξεκλείδωμα σύνδεσης', 'Unlock your login', 80 ),
			'unlock_intro'   => array( 'Γράψε το όνομα χρήστη ή το email του λογαριασμού. Αν είναι κλειδωμένος, θα λάβεις email με σύνδεσμο που ισχύει {minutes} λεπτά.', 'Enter the username or email of your account. If it is locked, you will get an email with a link that is valid for {minutes} minutes.', 300 ),
			'unlock_field'   => array( 'Όνομα χρήστη ή email', 'Username or email', 60 ),
			'unlock_send'    => array( 'Αποστολή συνδέσμου', 'Send the link', 40 ),
			'unlock_sent'    => array( 'Αν ο λογαριασμός είναι κλειδωμένος, σε λίγο θα λάβεις email με σύνδεσμο ξεκλειδώματος. Άνοιξέ τον σε αυτόν τον browser. Δες και στα ανεπιθύμητα.', 'If the account is locked, an email with an unlock link is on its way. Open it in this browser. Check your spam folder too.', 300 ),
			'unlock_confirm' => array( 'Πάτα το κουμπί για να ξεκλειδώσεις τη σύνδεση στον λογαριασμό σου. Δεν συνδέεσαι ακόμα: μετά γράφεις τον κωδικό σου.', 'Press the button to unlock logins to your account. It does not log you in: you enter your password afterwards.', 300 ),
			'unlock_go'      => array( 'Ξεκλείδωμα', 'Unlock', 40 ),
			'unlock_done'    => array( 'Η σύνδεση ξεκλειδώθηκε. Μπορείς να συνδεθείς με τον κωδικό σου.', 'Logins are unlocked. You can log in with your password.', 200 ),
			'unlock_invalid' => array( 'Ο σύνδεσμος έληξε, χρησιμοποιήθηκε ήδη ή άνοιξε σε άλλον browser. Ζήτησε νέο.', 'The link has expired, was already used or was opened in another browser. Please ask for a new one.', 200 ),
			'mail_subject'   => array( 'Ξεκλείδωμα σύνδεσης στο {site}', 'Unlock your login at {site}', 120 ),
			'mail_body'      => array( 'Κάποιος, ελπίζουμε εσύ, ζήτησε να ξεκλειδώσει τη σύνδεση στον λογαριασμό σου στο {site}. Άνοιξε αυτόν τον σύνδεσμο μέσα σε {minutes} λεπτά, στον ίδιο browser: {link} Αν δεν το ζήτησες εσύ, αγνόησε αυτό το email. Ο κωδικός σου δεν αλλάζει.', 'Someone, hopefully you, asked to unlock logins to your account at {site}. Open this link within {minutes} minutes, in the same browser: {link} If it was not you, ignore this email. Your password does not change.', 600 ),
		);
	}

	/** Boolean keys with their defaults. */
	private static function bools(): array {
		return array(
			'unlock_mail'   => true,
			'pingbacks'     => true,
			'enum_rest'     => true,
			'enum_author'   => true,
			'enum_sitemap'  => true,
			'enum_oembed'   => true,
			'enum_errors'   => true,
			'enum_archives' => false,
			'lostpw'        => true,
			'notify_device' => true,
			'notify_surge'  => true,
			'keep'          => false,
		);
	}

	/** Integer keys: key => array( default, min, max ). */
	private static function ints(): array {
		return array(
			'ip_fails'    => array( 5, 2, 50 ),
			'ip_window'   => array( 15, 1, 1440 ),
			'ip_lock'     => array( 15, 1, 1440 ),
			'esc_count'   => array( 4, 2, 20 ),
			'esc_hours'   => array( 24, 1, 168 ),
			'acct_fails'  => array( 20, 5, 500 ),
			'acct_window' => array( 60, 5, 1440 ),
			'acct_lock'   => array( 15, 1, 1440 ),
			'surge_at'    => array( 10, 3, 500 ),
		);
	}

	public static function defaults(): array {
		$d = array(
			'mode'   => 'off',
			'xmlrpc' => 'auth_off',
			'lang'   => 'auto',
			'allow'  => array(),
		);
		foreach ( self::bools() as $k => $v ) {
			$d[ $k ] = $v;
		}
		foreach ( self::ints() as $k => $v ) {
			$d[ $k ] = $v[0];
		}
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

	public static function mode(): string {
		return self::get()['mode'];
	}

	public static function live(): bool {
		return 'live' === self::mode();
	}

	/** Validates, stores and returns the settings. */
	public static function save( array $in ): array {
		$clean = self::sanitize( $in );
		update_option( self::OPT, $clean, true );
		self::$cache = $clean;
		return $clean;
	}

	/** Strict validation: whitelists, ranges, defaults for anything else. */
	public static function sanitize( array $in ): array {
		$d   = self::defaults();
		$out = array();

		$out['mode']   = isset( $in['mode'] ) && in_array( $in['mode'], array( 'off', 'test', 'live' ), true ) ? $in['mode'] : $d['mode'];
		$out['xmlrpc'] = isset( $in['xmlrpc'] ) && in_array( $in['xmlrpc'], array( 'auth_off', 'closed', 'open' ), true ) ? $in['xmlrpc'] : $d['xmlrpc'];
		$out['lang']   = isset( $in['lang'] ) && in_array( $in['lang'], array( 'auto', 'el', 'en' ), true ) ? $in['lang'] : $d['lang'];

		foreach ( self::bools() as $k => $def ) {
			$out[ $k ] = isset( $in[ $k ] ) ? (bool) $in[ $k ] : $def;
		}
		foreach ( self::ints() as $k => $r ) {
			$v         = isset( $in[ $k ] ) && is_numeric( $in[ $k ] ) ? (int) $in[ $k ] : $r[0];
			$out[ $k ] = max( $r[1], min( $r[2], $v ) );
		}

		$out['allow'] = self::sanitize_allow( isset( $in['allow'] ) ? $in['allow'] : array() );

		foreach ( self::texts() as $k => $t ) {
			foreach ( array( 'el' => 0, 'en' => 1 ) as $lang => $i ) {
				$key = 't_' . $k . '_' . $lang;
				$v   = isset( $in[ $key ] ) && is_string( $in[ $key ] ) ? trim( sanitize_text_field( $in[ $key ] ) ) : $t[ $i ];
				if ( function_exists( 'mb_substr' ) ) {
					$v = mb_substr( $v, 0, $t[2] );
				}
				$out[ $key ] = '' === $v && 'el' === $lang ? $t[0] : $v;
			}
		}
		return $out;
	}

	/**
	 * Trusted addresses: one IP or CIDR range per entry, normalised
	 * (inet_pton → inet_ntop, lowercase); anything else is dropped.
	 *
	 * @param mixed $raw string with one entry per line, or a list.
	 */
	public static function sanitize_allow( $raw ): array {
		$items = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw );
		$out   = array();
		foreach ( (array) $items as $it ) {
			$n = self::norm_range( is_string( $it ) ? $it : '' );
			if ( '' !== $n && ! in_array( $n, $out, true ) ) {
				$out[] = $n;
			}
			if ( count( $out ) >= 100 ) {
				break;
			}
		}
		return $out;
	}

	/** '203.0.113.7', '203.0.113.0/24', '2001:db8::/48' → normal form, or ''. */
	public static function norm_range( string $s ): string {
		$s = strtolower( trim( $s ) );
		if ( '' === $s ) {
			return '';
		}
		$bits = null;
		if ( false !== strpos( $s, '/' ) ) {
			list( $s, $b ) = explode( '/', $s, 2 );
			if ( ! ctype_digit( $b ) ) {
				return '';
			}
			$bits = (int) $b;
		}
		$bin = @inet_pton( $s ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- invalid input is dropped.
		if ( false === $bin ) {
			return '';
		}
		$max = 8 * strlen( $bin );
		$ip  = (string) inet_ntop( $bin );
		if ( null === $bits || $bits === $max ) {
			return $ip;
		}
		// No range wider than /8 (IPv4) or /32 (IPv6): too easy to trust the world.
		if ( $bits < ( 4 === strlen( $bin ) ? 8 : 32 ) || $bits > $max ) {
			return '';
		}
		return $ip . '/' . $bits;
	}

	/** A visitor text in the visitor language, placeholders filled (plain text). */
	public static function text( string $key, array $vars = array() ): string {
		$s    = self::get();
		$lang = self::visitor_lang();
		$t    = isset( $s[ 't_' . $key . '_' . $lang ] ) ? (string) $s[ 't_' . $key . '_' . $lang ] : '';
		if ( '' === $t && isset( $s[ 't_' . $key . '_el' ] ) ) {
			$t = (string) $s[ 't_' . $key . '_el' ];
		}
		foreach ( $vars as $k => $v ) {
			$t = str_replace( '{' . $k . '}', (string) $v, $t );
		}
		/**
		 * Filters a visitor text of Login Guard (e.g. for WPML).
		 *
		 * @param string $t    The text, placeholders filled.
		 * @param string $key  Key from LGD_Settings::texts().
		 * @param string $lang 'el' | 'en'.
		 */
		return (string) apply_filters( 'lgd_text', $t, $key, $lang );
	}

	/** 'el' | 'en' for visitor texts: the setting, or the site locale. */
	public static function visitor_lang(): string {
		$l = self::get()['lang'];
		if ( 'el' === $l || 'en' === $l ) {
			return $l;
		}
		return 0 === strpos( strtolower( (string) get_locale() ), 'el' ) ? 'el' : 'en';
	}
}

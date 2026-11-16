<?php
/**
 * BIS_Settings — the bis_settings option and its strict validation
 * (Bible §11, whitelists; unknown keys dropped, bad values → defaults).
 *
 * Keys:
 *  - mode        'off' | 'test' (default: only shop managers see the form)
 *                | 'live'
 *  - lang        visitor texts and e-mails: 'auto' (site locale) | 'el' | 'en'
 *  - keep_days   days an active request waits for the stock (30–365, 180)
 *  - confirm_h   hours a guest has to confirm (12–168, 48)
 *  - delay       minutes between «back in stock» and the first batch (0–120, 10)
 *  - batch       e-mails per batch (10–200, 50)
 *  - per_ip      requests per IP address in an hour (3–100, 10)
 *  - per_email   active requests per e-mail address (1–100, 20)
 *  - hide_gone   no form on Book Feed titles that left the feed (default on)
 *  - also        «Διαθέσιμο και ως» line from Product Formats (default on)
 *  - privacy     link to the privacy policy next to the consent box (default on)
 *  - t_<key>_el / t_<key>_en  visitor texts (Bible §14.17), keys in texts()
 */

defined( 'ABSPATH' ) || exit;

final class BIS_Settings {

	const OPT = 'bis_settings';

	/** @var array|null Per-request cache. */
	private static $cache = null;

	/**
	 * Visitor texts: key => array( default EL, default EN, max length ).
	 * Plain text, never msgids (Bible §14.17).
	 */
	public static function texts(): array {
		return array(
			'title'     => array( 'Ειδοποίησέ με όταν ξαναέρθει', 'Notify me when it is back', 80 ),
			'hint'      => array( 'Γράψε το email σου και θα σου στείλουμε ένα μήνυμα μόλις το προϊόν ξαναμπεί σε απόθεμα.', 'Enter your email and we will send you one message as soon as the product is back in stock.', 200 ),
			'pick'      => array( 'Η επιλογή σου έχει εξαντληθεί.', 'Your choice is out of stock.', 120 ),
			'email'     => array( 'Email', 'Email', 40 ),
			'consent'   => array( 'Συμφωνώ να λάβω ένα email για αυτό το προϊόν.', 'I agree to receive one email about this product.', 160 ),
			'button'    => array( 'Ειδοποίησέ με', 'Notify me', 40 ),
			'ok_member' => array( 'Έγινε. Θα λάβεις ένα email μόλις το προϊόν ξαναμπεί σε απόθεμα.', 'Done. You will get one email as soon as the product is back in stock.', 200 ),
			'ok_guest'  => array( 'Σχεδόν έτοιμο: σου στείλαμε email για να επιβεβαιώσεις. Δες και στα ανεπιθύμητα.', 'Almost done: we sent you an email to confirm. Check your spam folder too.', 200 ),
			'bad_email' => array( 'Γράψε ένα έγκυρο email.', 'Enter a valid email.', 120 ),
			'no_ok'     => array( 'Τσέκαρε το κουτάκι για να συνεχίσεις.', 'Tick the box to continue.', 120 ),
			'limit'     => array( 'Έχεις κάνει αρκετά αιτήματα. Δοκίμασε ξανά αργότερα.', 'You have made several requests. Try again later.', 160 ),
			'error'     => array( 'Κάτι πήγε στραβά. Δοκίμασε ξανά.', 'Something went wrong. Please try again.', 120 ),
			'confirm_q' => array( 'Πάτα το κουμπί για να επιβεβαιώσεις ότι θέλεις ένα email όταν το «{product}» ξαναμπεί σε απόθεμα.', 'Press the button to confirm that you want one email when «{product}» is back in stock.', 200 ),
			'confirm_b' => array( 'Επιβεβαίωση', 'Confirm', 40 ),
			'confirmed' => array( 'Επιβεβαιώθηκε. Θα λάβεις ένα email μόλις το «{product}» ξαναμπεί σε απόθεμα.', 'Confirmed. You will get one email as soon as «{product}» is back in stock.', 200 ),
			'unsub_q'   => array( 'Πάτα το κουμπί για να ακυρώσεις όλες τις ειδοποιήσεις αποθέματος για το {email}.', 'Press the button to cancel every stock notification for {email}.', 200 ),
			'unsub_b'   => array( 'Ακύρωση ειδοποιήσεων', 'Cancel notifications', 40 ),
			'unsubbed'  => array( 'Οι ειδοποιήσεις ακυρώθηκαν. Δεν θα λάβεις άλλο email από εμάς για αυτές.', 'The notifications are cancelled. You will get no more emails from us about them.', 200 ),
			'invalid'   => array( 'Ο σύνδεσμος έληξε ή δεν ισχύει πια.', 'The link has expired or is no longer valid.', 160 ),
			'also'      => array( 'Διαθέσιμο και ως:', 'Also available as:', 60 ),
		);
	}

	public static function defaults(): array {
		$d = array(
			'mode'      => 'test',
			'lang'      => 'auto',
			'keep_days' => 180,
			'confirm_h' => 48,
			'delay'     => 10,
			'batch'     => 50,
			'per_ip'    => 10,
			'per_email' => 20,
			'hide_gone' => true,
			'also'      => true,
			'privacy'   => true,
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

		$s['keep_days'] = self::range( $raw, 'keep_days', 30, 365, $d['keep_days'] );
		$s['confirm_h'] = self::range( $raw, 'confirm_h', 12, 168, $d['confirm_h'] );
		$s['delay']     = self::range( $raw, 'delay', 0, 120, $d['delay'] );
		$s['batch']     = self::range( $raw, 'batch', 10, 200, $d['batch'] );
		$s['per_ip']    = self::range( $raw, 'per_ip', 3, 100, $d['per_ip'] );
		$s['per_email'] = self::range( $raw, 'per_email', 1, 100, $d['per_email'] );

		foreach ( array( 'hide_gone', 'also', 'privacy' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}

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
		return BIS_Lang::from_locale( (string) $locale );
	}

	/**
	 * A visitor text in the visitor language with its placeholders filled;
	 * an empty English text shows the Greek one (Bible §14.17).
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
		/**
		 * Filters a visitor text (e.g. for WPML / Polylang).
		 *
		 * @param string $v    Text, placeholders filled.
		 * @param string $key  A key of BIS_Settings::texts().
		 * @param string $lang 'el' | 'en'.
		 */
		return (string) apply_filters( 'bis_text', $v, $key, $lang );
	}
}

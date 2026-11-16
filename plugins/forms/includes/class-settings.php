<?php
/**
 * FRM_Settings — the frm_settings option.
 *
 *  - mode        off | test | live (test: only managers see the forms)
 *  - lang        auto | el | en (visitor language; auto = Polylang / WPML
 *                current language, else the site locale)
 *  - keep        months a stored message is kept (0 = keep), default 6
 *  - spam_days   days a message in Spam is kept, default 7
 *  - pow         proof of work difficulty in bits (12–20), default 16
 *  - min_secs    seconds a visitor needs at least between the first touch
 *                of the form and «Send», default 3
 *  - rl_form     sends per 10 minutes from one visitor to one form, 5
 *  - rl_all      sends per hour from one visitor to all forms, 20
 *  - wipe        delete the stored messages on uninstall
 *  - t_{key}_el / t_{key}_en  visitor texts (Bible §14.17): plain text with
 *                a length limit; English falls back to Greek when empty.
 *
 * Per-form settings (fields, e-mail, texts, spam layers, storage) live in
 * the form itself (FRM_Forms).
 */

defined( 'ABSPATH' ) || exit;

final class FRM_Settings {

	const OPT = 'frm_settings';

	/** @var array|null */
	private static $cache = null;

	/** Visitor texts: key => array( Greek, English, max length ). */
	public static function texts(): array {
		return array(
			'submit'         => array( 'Αποστολή', 'Send', 60 ),
			'thanks'         => array( 'Ευχαριστούμε! Το μήνυμά σας στάλθηκε.', 'Thank you! Your message has been sent.', 300 ),
			'sending'        => array( 'Αποστολή…', 'Sending…', 60 ),
			'err_fields'     => array( 'Ελέγξτε τα πεδία που σημειώνονται.', 'Please check the marked fields.', 200 ),
			'err_required'   => array( 'Συμπληρώστε αυτό το πεδίο.', 'Please fill in this field.', 150 ),
			'err_email'      => array( 'Γράψτε ένα έγκυρο email.', 'Please enter a valid email.', 150 ),
			'err_tel'        => array( 'Γράψτε ένα έγκυρο τηλέφωνο.', 'Please enter a valid phone number.', 150 ),
			'err_url'        => array( 'Γράψτε μια έγκυρη διεύθυνση (https://…).', 'Please enter a valid address (https://…).', 150 ),
			'err_number'     => array( 'Γράψτε έναν αριθμό μέσα στα όρια.', 'Please enter a number within the limits.', 150 ),
			'err_date'       => array( 'Διαλέξτε μια έγκυρη ημερομηνία.', 'Please pick a valid date.', 150 ),
			'err_choice'     => array( 'Διαλέξτε μία από τις επιλογές.', 'Please pick one of the options.', 150 ),
			'err_consent'    => array( 'Χρειάζεται η συναίνεσή σας για να σταλεί το μήνυμα.', 'We need your consent to send the message.', 150 ),
			'err_long'       => array( 'Το κείμενο είναι πολύ μεγάλο.', 'The text is too long.', 150 ),
			'err_links'      => array( 'Το μήνυμα έχει πολλά links. Αφαιρέστε μερικά και ξαναστείλτε.', 'The message has too many links. Remove some and send again.', 200 ),
			'err_words'      => array( 'Το μήνυμα περιέχει λέξεις που δεν επιτρέπονται.', 'The message contains words that are not allowed.', 200 ),
			'err_letters'    => array( 'Γράψτε το μήνυμα με ελληνικά ή λατινικά γράμματα.', 'Please write the message in Greek or Latin letters.', 200 ),
			'err_mx'         => array( 'Αυτό το email δεν φαίνεται να δέχεται μηνύματα.', 'This email does not seem to accept messages.', 200 ),
			'err_limit'      => array( 'Στείλατε πολλά μηνύματα σε λίγο χρόνο. Δοκιμάστε ξανά σε λίγα λεπτά.', 'You sent many messages in a short time. Please try again in a few minutes.', 200 ),
			'err_generic'    => array( 'Το μήνυμα δεν στάλθηκε. Δοκιμάστε ξανά.', 'The message was not sent. Please try again.', 200 ),
			'err_mail'       => array( 'Το μήνυμα δεν στάλθηκε. Δοκιμάστε ξανά ή επικοινωνήστε μαζί μας με άλλο τρόπο.', 'The message was not sent. Please try again or contact us another way.', 300 ),
			'err_expired'    => array( 'Η σελίδα έμεινε ανοιχτή πολύ ώρα. Στείλτε τη φόρμα ξανά.', 'The page stayed open too long. Please send the form again.', 200 ),
			'confirm_title'  => array( 'Επιβεβαίωση αποστολής', 'Confirm sending', 100 ),
			'confirm_text'   => array( 'Πατήστε «Αποστολή» για να στείλετε το μήνυμα.', 'Press «Send» to send your message.', 300 ),
			'privacy'        => array( 'Πολιτική απορρήτου', 'Privacy policy', 80 ),
			'required_mark'  => array( 'υποχρεωτικό', 'required', 40 ),
			'product_prefix' => array( 'Ερώτηση για', 'Question about', 60 ),
		);
	}

	public static function defaults(): array {
		$d = array(
			'mode'      => 'test',
			'lang'      => 'auto',
			'keep'      => 6,
			'spam_days' => 7,
			'pow'       => 16,
			'min_secs'  => 3,
			'rl_form'   => 5,
			'rl_all'    => 20,
			'wipe'      => false,
		);
		foreach ( self::texts() as $k => $t ) {
			$d[ 't_' . $k . '_el' ] = $t[0];
			$d[ 't_' . $k . '_en' ] = $t[1];
		}
		return $d;
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPT, null );
			self::$cache = is_array( $raw ) ? self::sanitize( $raw ) : self::defaults();
		}
		return self::$cache;
	}

	public static function save( array $raw ): void {
		update_option( self::OPT, self::sanitize( $raw ), false );
		self::$cache = null;
		do_action( 'noxpress_purge_page_cache' );
	}

	private static function int_in( $v, int $min, int $max, int $def ): int {
		if ( ! is_scalar( $v ) || '' === (string) $v || ! is_numeric( $v ) ) {
			return $def;
		}
		return max( $min, min( $max, (int) $v ) );
	}

	public static function sanitize( array $raw ): array {
		$d = self::defaults();
		$s = $d;

		$mode      = isset( $raw['mode'] ) && is_string( $raw['mode'] ) ? $raw['mode'] : '';
		$s['mode'] = in_array( $mode, array( 'off', 'test', 'live' ), true ) ? $mode : $d['mode'];
		$lang      = isset( $raw['lang'] ) && is_string( $raw['lang'] ) ? $raw['lang'] : '';
		$s['lang'] = in_array( $lang, array( 'auto', 'el', 'en' ), true ) ? $lang : $d['lang'];

		$s['keep']      = self::int_in( $raw['keep'] ?? null, 0, 120, $d['keep'] );
		$s['spam_days'] = self::int_in( $raw['spam_days'] ?? null, 1, 90, $d['spam_days'] );
		$s['pow']       = self::int_in( $raw['pow'] ?? null, 12, 20, $d['pow'] );
		$s['min_secs']  = self::int_in( $raw['min_secs'] ?? null, 1, 30, $d['min_secs'] );
		$s['rl_form']   = self::int_in( $raw['rl_form'] ?? null, 1, 100, $d['rl_form'] );
		$s['rl_all']    = self::int_in( $raw['rl_all'] ?? null, 1, 500, $d['rl_all'] );
		$s['wipe']      = ! empty( $raw['wipe'] );

		foreach ( self::texts() as $k => $t ) {
			foreach ( array( 'el', 'en' ) as $l ) {
				$key = 't_' . $k . '_' . $l;
				if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ) {
					$v = mb_substr( trim( sanitize_text_field( $raw[ $key ] ) ), 0, $t[2] );
					// Greek may not be empty (it is the fallback of English).
					$s[ $key ] = ( '' === $v && 'el' === $l ) ? $d[ $key ] : $v;
				}
			}
		}
		return $s;
	}

	/** The forms show to this request: live, or test for managers; never with FRM_DISABLE. */
	public static function visible(): bool {
		if ( Noxpress_Forms::killed() ) {
			return false;
		}
		$mode = self::get()['mode'];
		if ( 'live' === $mode ) {
			return true;
		}
		return 'test' === $mode && current_user_can( Noxpress_Forms::cap() );
	}

	/** 'el' | 'en' for the visitor. */
	public static function visitor_lang(): string {
		$l = self::get()['lang'];
		if ( 'el' === $l || 'en' === $l ) {
			return $l;
		}
		if ( function_exists( 'pll_current_language' ) ) {
			$pll = (string) pll_current_language( 'slug' );
			if ( '' !== $pll ) {
				return 'el' === $pll ? 'el' : 'en';
			}
		}
		$wpml = apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WPML filter.
		if ( is_string( $wpml ) && '' !== $wpml ) {
			return 'el' === $wpml ? 'el' : 'en';
		}
		$locale = function_exists( 'determine_locale' ) && ! is_admin() ? determine_locale() : get_locale();
		return FRM_Lang::from_locale( (string) $locale );
	}

	/**
	 * A visitor text, with {placeholders} filled in (plain text, escape on output).
	 *
	 * @param string $key  A key of texts().
	 * @param string $lang 'el' | 'en' ('' = the visitor's).
	 * @param array  $vars placeholder => value.
	 */
	public static function text( string $key, string $lang = '', array $vars = array() ): string {
		$s    = self::get();
		$lang = 'el' === $lang || 'en' === $lang ? $lang : self::visitor_lang();
		$v    = isset( $s[ 't_' . $key . '_' . $lang ] ) ? (string) $s[ 't_' . $key . '_' . $lang ] : '';
		if ( '' === $v && 'en' === $lang ) {
			$v = isset( $s[ 't_' . $key . '_el' ] ) ? (string) $s[ 't_' . $key . '_el' ] : '';
		}
		foreach ( $vars as $ph => $val ) {
			$v = str_replace( '{' . $ph . '}', (string) $val, $v );
		}
		/**
		 * Filters a visitor text (e.g. for WPML / Polylang).
		 *
		 * @param string $v    Text.
		 * @param string $key  A key of FRM_Settings::texts(), or form:{id}:{key} for a form text.
		 * @param string $lang 'el' | 'en'.
		 */
		return (string) apply_filters( 'frm_text', $v, $key, $lang );
	}

	/** Pick the visitor language from a pair of strings (English falls back to Greek). */
	public static function pick( string $el, string $en, string $lang ): string {
		return ( 'en' === $lang && '' !== $en ) ? $en : $el;
	}
}

<?php
/**
 * IVD_Settings — the ivd_settings option (autoload: read on every checkout
 * view), strict validation with whitelists (Bible §11).
 *
 *  - mode          'off' | 'test' (shop managers only, the default after
 *                  activation) | 'live'
 *  - f_tax_office  'required' | 'optional' | 'hidden' (Greek customers only)
 *  - f_activity    'required' | 'optional' | 'hidden' (Greek customers only)
 *  - f_hq          'off' | 'optional': a head-office address when it
 *                  differs from the billing address
 *  - vies          check EU VAT numbers of other countries in VIES when the
 *                  order is placed
 *  - vies_invalid  'accept' (the order goes through with a note) | 'reject'
 *                  (the customer is asked to correct the number). VIES not
 *                  answering never stops an order (Chris 2026-11-16).
 *  - compat        'none' | 'timologia' | 'obs': also write the details
 *                  under the meta names of that plugin, for invoicing
 *                  providers that read them
 *  - column        «Τ» column and «Μόνο τιμολόγια» filter in the orders list
 *  - delete_customers  delete the customers' saved details on uninstall
 *  - lang          'auto' | 'el' | 'en' for the visitor texts
 *  - one { el, en } pair per key of TEXTS (Bible §14.17)
 */

defined( 'ABSPATH' ) || exit;

final class IVD_Settings {

	const OPT = 'ivd_settings';

	/** Text key => [ max length, empty allowed ]. */
	const TEXTS = array(
		'choice'     => array( 60, false ),
		'receipt'    => array( 40, false ),
		'invoice'    => array( 40, false ),
		'want'       => array( 80, false ),
		'company'    => array( 60, false ),
		'vat'        => array( 60, false ),
		'tax_office' => array( 60, false ),
		'activity'   => array( 60, false ),
		'hq'         => array( 60, false ),
		'help'       => array( 300, true ),
		'required'   => array( 150, false ),
		'vat_bad'    => array( 200, false ),
		'vies_bad'   => array( 250, false ),
		'need_invoice' => array( 200, false ),
	);

	/** @var array|null Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'mode'             => 'test',
			'f_tax_office'     => 'required',
			'f_activity'       => 'required',
			'f_hq'             => 'off',
			'vies'             => true,
			'vies_invalid'     => 'accept',
			'compat'           => 'none',
			'column'           => true,
			'delete_customers' => false,
			'lang'             => 'auto',

			'choice'           => array(
				'el' => 'Παραστατικό',
				'en' => 'Receipt or invoice',
			),
			'receipt'          => array(
				'el' => 'Απόδειξη',
				'en' => 'Receipt',
			),
			'invoice'          => array(
				'el' => 'Τιμολόγιο',
				'en' => 'Invoice',
			),
			'want'             => array(
				'el' => 'Θέλω τιμολόγιο',
				'en' => 'I need an invoice',
			),
			'company'          => array(
				'el' => 'Επωνυμία',
				'en' => 'Company name',
			),
			'vat'              => array(
				'el' => 'ΑΦΜ',
				'en' => 'VAT number',
			),
			'tax_office'       => array(
				'el' => 'ΔΟΥ',
				'en' => 'Tax office',
			),
			'activity'         => array(
				'el' => 'Δραστηριότητα',
				'en' => 'Business activity',
			),
			'hq'               => array(
				'el' => 'Διεύθυνση έδρας (αν διαφέρει)',
				'en' => 'Head office address (if different)',
			),
			'help'             => array(
				'el' => '',
				'en' => '',
			),
			'required'         => array(
				'el' => 'Για τιμολόγιο χρειάζεται το πεδίο «{field}».',
				'en' => 'An invoice needs the field "{field}".',
			),
			'vat_bad'          => array(
				'el' => 'Ο ΑΦΜ δεν είναι σωστός. Έλεγξε ότι έχει 9 ψηφία, χωρίς κενά.',
				'en' => 'The VAT number is not valid. Please check it.',
			),
			'vies_bad'         => array(
				'el' => 'Ο ΑΦΜ δεν βρέθηκε στο VIES της ΕΕ. Έλεγξε τον αριθμό και τη χώρα χρέωσης.',
				'en' => 'The VAT number was not found in the EU VIES service. Please check the number and the billing country.',
			),
			'need_invoice'     => array(
				'el' => 'Αυτή η παραγγελία εκδίδεται με τιμολόγιο. Διάλεξε «Τιμολόγιο» και συμπλήρωσε τα στοιχεία.',
				'en' => 'This order needs an invoice. Choose "Invoice" and fill in the details.',
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

	/** Validates and stores; an invalid value keeps the one saved before. */
	public static function save( array $s ): void {
		$s           = self::sanitize( $s, self::get() );
		self::$cache = $s;
		update_option( self::OPT, $s, true );
	}

	/** Drops the per-request cache (tests, imports). */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Whitelist validation: unknown keys are dropped, bad values fall back
	 * to $base (the saved settings when saving), else to the defaults.
	 */
	public static function sanitize( array $raw, array $base = array() ): array {
		$d = array_merge( self::defaults(), $base );
		$s = array();

		$s['mode']             = self::pick( $raw, 'mode', array( 'off', 'test', 'live' ), $d['mode'] );
		$s['f_tax_office']     = self::pick( $raw, 'f_tax_office', array( 'required', 'optional', 'hidden' ), $d['f_tax_office'] );
		$s['f_activity']       = self::pick( $raw, 'f_activity', array( 'required', 'optional', 'hidden' ), $d['f_activity'] );
		$s['f_hq']             = self::pick( $raw, 'f_hq', array( 'off', 'optional' ), $d['f_hq'] );
		$s['vies']             = self::flag( $raw, 'vies', $d );
		$s['vies_invalid']     = self::pick( $raw, 'vies_invalid', array( 'accept', 'reject' ), $d['vies_invalid'] );
		$s['compat']           = self::pick( $raw, 'compat', array( 'none', 'timologia', 'obs' ), $d['compat'] );
		$s['column']           = self::flag( $raw, 'column', $d );
		$s['delete_customers'] = self::flag( $raw, 'delete_customers', $d );
		$s['lang']             = self::pick( $raw, 'lang', array( 'auto', 'el', 'en' ), $d['lang'] );

		$def = self::defaults();
		foreach ( self::TEXTS as $key => $spec ) {
			$s[ $key ] = is_array( $d[ $key ] ) ? $d[ $key ] : $def[ $key ];
			if ( isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ) {
				foreach ( array( 'el', 'en' ) as $l ) {
					$v = isset( $raw[ $key ][ $l ] ) && is_string( $raw[ $key ][ $l ] ) ? $raw[ $key ][ $l ] : '';
					$v = mb_substr( trim( sanitize_text_field( $v ) ), 0, $spec[0] );
					// Empty Greek = the default (unless the text may be empty); empty English = shows the Greek.
					if ( 'el' === $l && '' === $v && ! $spec[1] ) {
						$s[ $key ]['el'] = $def[ $key ]['el'];
						continue;
					}
					$s[ $key ][ $l ] = $v;
				}
			}
		}

		return $s;
	}

	/** One of the allowed string values, else the default. */
	private static function pick( array $raw, string $key, array $allowed, string $default ): string {
		return isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && in_array( $raw[ $key ], $allowed, true ) ? $raw[ $key ] : $default;
	}

	/** A boolean (missing = the default). */
	private static function flag( array $raw, string $key, array $d ): bool {
		return isset( $raw[ $key ] ) ? (bool) $raw[ $key ] : (bool) $d[ $key ];
	}

	/* =====================================================================
	 * Readers
	 * =================================================================== */

	/** True when the current visitor sees the choice at checkout (mode + test mode). */
	public static function visible(): bool {
		if ( Invoice_Details::killed() ) {
			return false;
		}
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
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return 0 === strpos( strtolower( (string) $locale ), 'el' ) ? 'el' : 'en';
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
		 * Filters a visitor text of Invoice Details (e.g. for WPML).
		 *
		 * @param string $text Text.
		 * @param string $key  Text key (a key of IVD_Settings::TEXTS).
		 * @param string $lang 'el' | 'en'.
		 */
		return (string) apply_filters( 'ivd_text', $text, $key, $lang );
	}

	/** The "required" message for one field label. */
	public static function required_text( string $label ): string {
		return str_replace( '{field}', $label, self::text( 'required' ) );
	}
}

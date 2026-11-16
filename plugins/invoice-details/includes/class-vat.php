<?php
/**
 * IVD_Vat — VAT numbers: clean-up, the Greek check digit, the EU country
 * prefixes and the VIES client.
 *
 *  - Greek ΑΦΜ: 9 digits; the 9th is the check digit
 *    (Σ d_i · 2^(9−i) for i = 1…8, mod 11, mod 10). Checked locally, never
 *    against VIES as a gate: VIES knows only businesses registered for
 *    intra-EU trade, and many small Greek businesses are not.
 *  - Other EU countries: the number goes to VIES (European Commission,
 *    fixed host ec.europa.eu, public REST API, no key) with the country of
 *    the prefix, or of the billing address when there is no prefix.
 *  - Outside the EU: free text (letters, digits), 2–20 characters, not
 *    checked.
 *
 * A check result is an array:
 *   status  'valid' | 'invalid' | 'unavailable' | 'local_ok' | 'unchecked'
 *   source  'local' | 'vies' | ''
 *   at      Unix time of the check
 *   name, address   what VIES returned (when the country gives them)
 *   request         VIES request identifier, when returned
 *   error           VIES error code (MS_UNAVAILABLE, TIMEOUT, …) or ''
 */

defined( 'ABSPATH' ) || exit;

final class IVD_Vat {

	/** VIES REST endpoint (fixed host, Bible §14: the only external request). */
	const VIES = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s';

	/** EU member states (ISO code => VIES code). XI = Northern Ireland. */
	const EU = array(
		'AT' => 'AT',
		'BE' => 'BE',
		'BG' => 'BG',
		'CY' => 'CY',
		'CZ' => 'CZ',
		'DE' => 'DE',
		'DK' => 'DK',
		'EE' => 'EE',
		'GR' => 'EL',
		'ES' => 'ES',
		'FI' => 'FI',
		'FR' => 'FR',
		'HR' => 'HR',
		'HU' => 'HU',
		'IE' => 'IE',
		'IT' => 'IT',
		'LT' => 'LT',
		'LU' => 'LU',
		'LV' => 'LV',
		'MT' => 'MT',
		'NL' => 'NL',
		'PL' => 'PL',
		'PT' => 'PT',
		'RO' => 'RO',
		'SE' => 'SE',
		'SI' => 'SI',
		'SK' => 'SK',
		'XI' => 'XI',
	);

	/** Per-request results, so one order never asks VIES twice. */
	private static $memo = array();

	/** Upper case, without spaces, dots, dashes and slashes. */
	public static function clean( string $v ): string {
		$v = strtoupper( (string) preg_replace( '/[\s.\-\/]+/u', '', $v ) );
		return (string) preg_replace( '/[^A-Z0-9]/', '', $v );
	}

	/**
	 * Splits a number into [ ISO country, number without prefix ].
	 * A leading EU prefix wins (EL and GR both mean Greece); otherwise the
	 * billing country is used.
	 *
	 * @param string $raw     Number as typed.
	 * @param string $country Billing country (ISO 3166-1 alpha-2).
	 */
	public static function split( string $raw, string $country ): array {
		$v       = self::clean( $raw );
		$country = strtoupper( $country );
		$prefix  = substr( $v, 0, 2 );
		if ( 1 === preg_match( '/^[A-Z]{2}$/', $prefix ) ) {
			if ( 'EL' === $prefix || 'GR' === $prefix ) {
				return array( 'GR', substr( $v, 2 ) );
			}
			if ( isset( self::EU[ $prefix ] ) ) {
				return array( $prefix, substr( $v, 2 ) );
			}
		}
		return array( $country, $v );
	}

	/** True for a Greek ΑΦΜ with a correct check digit. */
	public static function greek_ok( string $n ): bool {
		if ( 1 !== preg_match( '/^\d{9}$/', $n ) || '000000000' === $n ) {
			return false;
		}
		$sum = 0;
		for ( $i = 0; $i < 8; $i++ ) {
			$sum += (int) $n[ $i ] * ( 1 << ( 8 - $i ) );
		}
		return ( $sum % 11 ) % 10 === (int) $n[8];
	}

	/** True when the ISO country is an EU member state (or XI). */
	public static function is_eu( string $country ): bool {
		return isset( self::EU[ strtoupper( $country ) ] );
	}

	/**
	 * The local check: is the number acceptable at all?
	 *
	 * @param string $n       Number without prefix.
	 * @param string $country ISO country of the number.
	 * @return bool
	 */
	public static function format_ok( string $n, string $country ): bool {
		if ( 'GR' === $country ) {
			return self::greek_ok( $n );
		}
		if ( self::is_eu( $country ) ) {
			return 1 === preg_match( '/^[A-Z0-9]{2,13}$/', $n );
		}
		return 1 === preg_match( '/^[A-Z0-9]{2,20}$/', $n );
	}

	/**
	 * Checks a number in VIES. Never throws; a network or service error
	 * gives status 'unavailable'.
	 *
	 * @param string $n       Number without prefix.
	 * @param string $country ISO country of the number (EU).
	 * @return array Check result (see the class docblock).
	 */
	public static function vies( string $n, string $country ): array {
		$country = strtoupper( $country );
		$key     = $country . $n;
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}
		$out = array(
			'status'  => 'unavailable',
			'source'  => 'vies',
			'at'      => time(),
			'name'    => '',
			'address' => '',
			'request' => '',
			'error'   => '',
		);
		if ( ! self::is_eu( $country ) || 1 !== preg_match( '/^[A-Z0-9]{2,13}$/', $n ) ) {
			$out['status'] = 'invalid';
			$out['error']  = 'INVALID_INPUT';
			return $out;
		}

		$url = sprintf( self::VIES, rawurlencode( self::EU[ $country ] ), rawurlencode( $n ) );

		/**
		 * Short-circuits the VIES request (tests): return an array shaped like
		 * the VIES JSON answer (isValid, userError, name, address,
		 * requestIdentifier), a WP_Error, or null to send the request.
		 *
		 * @param array|WP_Error|null $pre     Answer or null.
		 * @param string              $country VIES country code (EL for Greece).
		 * @param string              $number  Number without prefix.
		 */
		$body = apply_filters( 'ivd_vies_response', null, self::EU[ $country ], $n );

		if ( null === $body ) {
			$r = wp_safe_remote_get(
				$url,
				array(
					'timeout'     => 8,
					'redirection' => 0,
					'headers'     => array( 'Accept' => 'application/json' ),
					'user-agent'  => 'Invoice Details/' . IVD_VERSION . ' (WordPress)',
				)
			);
			if ( is_wp_error( $r ) ) {
				$out['error'] = 'NETWORK';
				return self::$memo[ $key ] = $out;
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			$body = json_decode( (string) wp_remote_retrieve_body( $r ), true );
			if ( ! is_array( $body ) ) {
				$out['error'] = 'HTTP_' . $code;
				return self::$memo[ $key ] = $out;
			}
		}
		if ( is_wp_error( $body ) || ! is_array( $body ) ) {
			$out['error'] = 'NETWORK';
			return self::$memo[ $key ] = $out;
		}

		$user_error = isset( $body['userError'] ) && is_string( $body['userError'] ) ? strtoupper( $body['userError'] ) : '';
		if ( isset( $body['isValid'] ) && ( 'VALID' === $user_error || 'INVALID' === $user_error || '' === $user_error ) ) {
			$out['status'] = true === $body['isValid'] ? 'valid' : 'invalid';
		} else {
			$out['error'] = '' !== $user_error ? substr( preg_replace( '/[^A-Z_]/', '', $user_error ), 0, 40 ) : 'UNKNOWN';
		}
		if ( 'valid' === $out['status'] ) {
			foreach ( array(
				'name'    => 'name',
				'address' => 'address',
				'request' => 'requestIdentifier',
			) as $k => $src ) {
				if ( isset( $body[ $src ] ) && is_string( $body[ $src ] ) && '---' !== trim( $body[ $src ] ) ) {
					$out[ $k ] = mb_substr( trim( sanitize_textarea_field( $body[ $src ] ) ), 0, 300 );
				}
			}
		}
		return self::$memo[ $key ] = $out;
	}

	/**
	 * The check of a number at order time: local for Greece and outside the
	 * EU, VIES (when on) for the other EU countries.
	 *
	 * @param string $n        Number without prefix.
	 * @param string $country  ISO country of the number.
	 * @param bool   $use_vies Ask VIES for EU numbers of other countries.
	 */
	public static function check( string $n, string $country, bool $use_vies ): array {
		$base = array(
			'status'  => 'unchecked',
			'source'  => '',
			'at'      => time(),
			'name'    => '',
			'address' => '',
			'request' => '',
			'error'   => '',
		);
		if ( 'GR' === $country ) {
			$base['status'] = self::greek_ok( $n ) ? 'local_ok' : 'invalid';
			$base['source'] = 'local';
			return $base;
		}
		if ( self::is_eu( $country ) && $use_vies ) {
			return self::vies( $n, $country );
		}
		return $base;
	}

	/** A short label for a check status (admin). */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case 'valid':
				return __( 'Έγκυρος στο VIES', 'invoice-details' );
			case 'invalid':
				return __( 'Άκυρος', 'invoice-details' );
			case 'unavailable':
				return __( 'VIES μη διαθέσιμο, έλεγχος αργότερα', 'invoice-details' );
			case 'local_ok':
				return __( 'Σωστό ψηφίο ελέγχου', 'invoice-details' );
			default:
				return __( 'Χωρίς έλεγχο', 'invoice-details' );
		}
	}
}

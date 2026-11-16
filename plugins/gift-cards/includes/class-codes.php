<?php
/**
 * GFC_Codes — gift card codes (approved E3, E4).
 *
 *  - 16 characters of Crockford base32 (0-9 A-Z without I L O U) from
 *    random_bytes(10): exactly 80 bits. Shown as XXXX-XXXX-XXXX-XXXX.
 *  - Input is normalised: spaces, dashes and case are ignored, O → 0,
 *    I / L → 1 (the usual misreadings). Anything else is invalid.
 *  - Only HMAC-SHA256(code, key) is stored, plus the last 4 characters for
 *    display. The key is 32 random bytes in the option gfc_hmac_key (its
 *    name ends in "key", so Site Bridge returns it as ***), created once
 *    and never replaced. It does not depend on the salts: a salt rotation
 *    does not break the cards. A
 *    database dump alone does not give any code: 2^80 guesses per card.
 *  - Lookup is by the HMAC (unique index), never by comparing text.
 */

defined( 'ABSPATH' ) || exit;

final class GFC_Codes {

	const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	const LEN      = 16;
	const KEY_OPT  = 'gfc_hmac_key';

	/** A new random code (plain, 16 characters). */
	public static function generate(): string {
		$bytes = random_bytes( 10 );
		$bits  = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$bits .= str_pad( decbin( ord( $bytes[ $i ] ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		for ( $i = 0; $i < self::LEN; $i++ ) {
			$out .= self::ALPHABET[ bindec( substr( $bits, $i * 5, 5 ) ) ];
		}
		return $out;
	}

	/** The typed code in its canonical form, or '' when it cannot be one. */
	public static function normalize( string $input ): string {
		if ( strlen( $input ) > 64 ) {
			return '';
		}
		$c = strtoupper( (string) preg_replace( '/[\s\-_.]+/', '', $input ) );
		$c = strtr( $c, array( 'O' => '0', 'I' => '1', 'L' => '1' ) );
		if ( self::LEN !== strlen( $c ) || strspn( $c, self::ALPHABET ) !== self::LEN ) {
			return '';
		}
		return $c;
	}

	public static function format( string $code ): string {
		return implode( '-', str_split( $code, 4 ) );
	}

	public static function last4( string $code ): string {
		return substr( $code, -4 );
	}

	/** "••••7KQ2" for display. */
	public static function mask( string $last4 ): string {
		return '' === $last4 ? '—' : '••••' . $last4;
	}

	public static function hmac( string $code ): string {
		return hash_hmac( 'sha256', $code, self::key() );
	}

	private static function key(): string {
		$k = get_option( self::KEY_OPT, false );
		if ( false === $k ) {
			$k = bin2hex( random_bytes( 32 ) );
			// add_option fails when another request created it first: read that one.
			if ( ! add_option( self::KEY_OPT, $k, '', false ) ) {
				$k = get_option( self::KEY_OPT, '' );
			}
		}
		// Never replaced once it exists: a new key would make every code invalid.
		return (string) $k;
	}
}

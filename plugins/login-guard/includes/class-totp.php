<?php
/**
 * LGD_TOTP — RFC 6238 time-based codes (RFC 4226 HOTP, SHA-1, 6 digits,
 * 30 seconds), the format every authenticator app reads.
 *
 * verify() accepts the current step and one step on either side (clock
 * drift), and only a step newer than the last one used, so a code works
 * once. Comparisons are constant-time.
 */

defined( 'ABSPATH' ) || exit;

final class LGD_TOTP {

	const PERIOD = 30;
	const DIGITS = 6;
	const DRIFT  = 1;
	const B32    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/** A new 160-bit secret, base32 (32 characters). */
	public static function new_secret(): string {
		return self::b32_encode( random_bytes( 20 ) );
	}

	public static function b32_encode( string $bin ): string {
		$bits = '';
		$len  = strlen( $bin );
		for ( $i = 0; $i < $len; $i++ ) {
			$bits .= str_pad( decbin( ord( $bin[ $i ] ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= self::B32[ bindec( str_pad( $chunk, 5, '0', STR_PAD_RIGHT ) ) ];
		}
		return $out;
	}

	/** Base32 (case, spaces and padding ignored) to binary, or '' when invalid. */
	public static function b32_decode( string $b32 ): string {
		$b32 = strtoupper( (string) preg_replace( '/[\s=-]+/', '', $b32 ) );
		if ( '' === $b32 || strspn( $b32, self::B32 ) !== strlen( $b32 ) ) {
			return '';
		}
		$bits = '';
		$len  = strlen( $b32 );
		for ( $i = 0; $i < $len; $i++ ) {
			$bits .= str_pad( decbin( (int) strpos( self::B32, $b32[ $i ] ) ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			if ( 8 === strlen( $byte ) ) {
				$out .= chr( (int) bindec( $byte ) );
			}
		}
		return $out;
	}

	/** The code for one time step. */
	public static function code( string $secret_b32, int $step ): string {
		$key = self::b32_decode( $secret_b32 );
		if ( '' === $key ) {
			return '';
		}
		$msg  = pack( 'N2', ( $step >> 32 ) & 0xFFFFFFFF, $step & 0xFFFFFFFF );
		$h    = hash_hmac( 'sha1', $msg, $key, true );
		$o    = ord( $h[19] ) & 0x0F;
		$bin  = ( ( ord( $h[ $o ] ) & 0x7F ) << 24 ) | ( ord( $h[ $o + 1 ] ) << 16 ) | ( ord( $h[ $o + 2 ] ) << 8 ) | ord( $h[ $o + 3 ] );
		$code = $bin % ( 10 ** self::DIGITS );
		return str_pad( (string) $code, self::DIGITS, '0', STR_PAD_LEFT );
	}

	public static function step( ?int $time = null ): int {
		return (int) floor( ( null === $time ? time() : $time ) / self::PERIOD );
	}

	/**
	 * The step a code belongs to (newer than $last), or 0 when it is wrong,
	 * reused or too old.
	 */
	public static function verify( string $secret_b32, string $code, int $last = 0, ?int $time = null ): int {
		$code = (string) preg_replace( '/\s+/', '', $code );
		if ( ! preg_match( '/^\d{' . self::DIGITS . '}$/', $code ) ) {
			return 0;
		}
		$now   = self::step( $time );
		$found = 0;
		for ( $d = -self::DRIFT; $d <= self::DRIFT; $d++ ) {
			$s = $now + $d;
			// Every candidate is computed, so the time does not depend on which one matches.
			if ( hash_equals( self::code( $secret_b32, $s ), $code ) && $s > $last ) {
				$found = $s;
			}
		}
		return $found;
	}

	/** otpauth:// URI for the QR code (issuer and account label, RFC-style). */
	public static function uri( string $secret_b32, string $issuer, string $account ): string {
		$issuer  = str_replace( ':', '', $issuer );
		$account = str_replace( ':', '', $account );
		return 'otpauth://totp/' . rawurlencode( $issuer ) . ':' . rawurlencode( $account )
			. '?secret=' . $secret_b32 . '&issuer=' . rawurlencode( $issuer )
			. '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
	}
}

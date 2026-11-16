<?php
/**
 * ACL_Redact — what may be written about a value.
 *
 * Judged by the NAME of the field or option (Bible §11: the mask is
 * judged at the source), never by guessing the value:
 *  - secret (password, key, token, salt, secret …): never written, the
 *    row says only that it changed. Same list as Site Bridge's guard.
 *  - personal (e-mail, phone, address, postcode, IP): never written.
 *  - anything else: written, cut to 300 characters; arrays are compared
 *    key by key (up to 30 keys, 3 levels), the rest is «changed».
 *
 * A details entry is one of:
 *   field => array( 'o' => old, 'n' => new )   both shown
 *   field => array( 'h' => 1 )                 changed, value hidden
 *   field => array( 't' => text )              a note (e.g. 'refund 5.00')
 */

defined( 'ABSPATH' ) || exit;

final class ACL_Redact {

	/** Names whose values are secrets (never stored). */
	const SECRET_RE = '/(passw|secret|token|api_?key|private_?key|consumer_?key|access_?key|auth_?key|license_?key|activation_?key|signing_?key|_salt$|^salt$|_keys$|application_passwords|session_tokens|(^|_)pass(_|$)|pwd|^(secure_auth|logged_in|nonce)_key$|(^|_)nl_key$|hmac|credential|cookie|webhook_?url|bearer|otp|totp|2fa|recovery)/i';

	/** Names whose values are personal data (never stored). */
	const PII_RE = '/(e-?mail|phone|mobile|address|postcode|post_code|zip_?code|^ip$|_ip$|ip_?addr|remote_?addr|first_?name|last_?name|company|vat_?(no|number)|afm|tax_?id|birth|iban)/i';

	const MAX_STR  = 300;
	const MAX_KEYS = 30;

	public static function is_secret( string $name ): bool {
		return (bool) preg_match( self::SECRET_RE, $name );
	}

	public static function is_pii( string $name ): bool {
		return (bool) preg_match( self::PII_RE, $name );
	}

	public static function hidden( string $name ): bool {
		return self::is_secret( $name ) || self::is_pii( $name );
	}

	/** A scalar for display: bools as 0/1, long strings cut, tags out. */
	public static function scalar( $v ): string {
		if ( null === $v ) {
			return '';
		}
		if ( is_bool( $v ) ) {
			return $v ? '1' : '0';
		}
		if ( is_object( $v ) && method_exists( $v, 'date' ) ) {
			return (string) $v->date( 'Y-m-d H:i' ); // WC_DateTime.
		}
		if ( is_array( $v ) || is_object( $v ) ) {
			return '';
		}
		$s = trim( wp_strip_all_tags( (string) $v ) );
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $s ) > self::MAX_STR : strlen( $s ) > self::MAX_STR ) {
			$s = ( function_exists( 'mb_substr' ) ? mb_substr( $s, 0, self::MAX_STR ) : substr( $s, 0, self::MAX_STR ) ) . '…';
		}
		return $s;
	}

	/**
	 * Adds the difference of one field to $details. Returns false when the
	 * values are the same (nothing added).
	 */
	public static function diff( array &$details, string $field, $old, $new, int $depth = 0 ): bool {
		if ( self::same( $old, $new ) ) {
			return false;
		}
		if ( count( $details ) >= self::MAX_KEYS ) {
			$details['…'] = array( 't' => 'more' );
			return true;
		}
		$leaf = substr( (string) preg_replace( '/^.*\./', '', $field ), 0, 120 );
		if ( self::hidden( $leaf ) || self::hidden( $field ) ) {
			$details[ $field ] = array( 'h' => 1 );
			return true;
		}
		$old = self::maybe_decode( $old );
		$new = self::maybe_decode( $new );
		if ( ( is_array( $old ) || is_array( $new ) ) && $depth < 3 ) {
			$o    = is_array( $old ) ? $old : array();
			$n    = is_array( $new ) ? $new : array();
			$keys = array_unique( array_merge( array_keys( $o ), array_keys( $n ) ) );
			$any  = false;
			foreach ( $keys as $k ) {
				$any = self::diff( $details, $field . '.' . $k, isset( $o[ $k ] ) ? $o[ $k ] : null, isset( $n[ $k ] ) ? $n[ $k ] : null, $depth + 1 ) || $any;
			}
			if ( ! $any ) {
				$details[ $field ] = array( 'h' => 1 );
			}
			return true;
		}
		if ( is_array( $old ) || is_array( $new ) || is_object( $old ) || is_object( $new ) ) {
			if ( ! ( is_object( $old ) && method_exists( $old, 'date' ) ) && ! ( is_object( $new ) && method_exists( $new, 'date' ) ) ) {
				$details[ $field ] = array( 'h' => 1 );
				return true;
			}
		}
		$details[ $field ] = array(
			'o' => self::scalar( $old ),
			'n' => self::scalar( $new ),
		);
		return true;
	}

	/** Loose equality that treats '', null and false alike, and 1 / '1'. */
	private static function same( $a, $b ): bool {
		$a = self::maybe_decode( $a );
		$b = self::maybe_decode( $b );
		if ( is_array( $a ) || is_array( $b ) || is_object( $a ) || is_object( $b ) ) {
			return wp_json_encode( $a ) === wp_json_encode( $b );
		}
		$na = null === $a || false === $a ? '' : (string) $a;
		$nb = null === $b || false === $b ? '' : (string) $b;
		return $na === $nb;
	}

	/** JSON objects or arrays stored as strings are compared as arrays. */
	private static function maybe_decode( $v ) {
		if ( is_string( $v ) && strlen( $v ) > 1 && ( '{' === $v[0] || '[' === $v[0] ) ) {
			$d = json_decode( $v, true );
			if ( is_array( $d ) ) {
				return $d;
			}
		}
		return $v;
	}
}

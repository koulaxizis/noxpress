<?php
/**
 * LOP_Web_Push — the Web Push protocol, with OpenSSL only.
 *
 *  - RFC 8291 message encryption (aes128gcm, ECDH P-256 + HKDF), one record.
 *  - RFC 8292 VAPID: an ES256 JWT for the push service, with the site's own
 *    key pair (no Firebase project, no account anywhere).
 *
 * No WordPress calls here (pure functions), so the protocol is testable
 * outside WordPress. LOP_Push stores the keys and the subscriptions and
 * sends the requests.
 */

defined( 'ABSPATH' ) || exit;

final class LOP_Web_Push {

	/** DER prefix of an uncompressed P-256 SubjectPublicKeyInfo (65-byte point follows). */
	const P256_SPKI = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

	/** True when the PHP build can do everything below. */
	public static function available(): bool {
		return function_exists( 'openssl_pkey_new' )
			&& function_exists( 'openssl_pkey_derive' )
			&& function_exists( 'openssl_sign' )
			&& defined( 'OPENSSL_KEYTYPE_EC' )
			&& in_array( 'aes-128-gcm', array_map( 'strtolower', (array) openssl_get_cipher_methods() ), true );
	}

	public static function b64u( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	/** Base64url (or standard base64) to raw, null when invalid. */
	public static function unb64u( string $text ): ?string {
		$text = strtr( trim( $text ), '-_', '+/' );
		if ( '' === $text || ! preg_match( '#^[A-Za-z0-9+/]+=*$#', $text ) ) {
			return null;
		}
		$pad = strlen( $text ) % 4;
		if ( $pad ) {
			$text .= str_repeat( '=', 4 - $pad );
		}
		$raw = base64_decode( $text, true );
		return false === $raw ? null : $raw;
	}

	/**
	 * A new P-256 key pair.
	 *
	 * @return array{pub:string,pem:string}|null pub = 65-byte uncompressed point, pem = private key.
	 */
	public static function new_keys(): ?array {
		$key = self::ec_key();
		if ( ! $key ) {
			return null;
		}
		$pem = '';
		if ( ! openssl_pkey_export( $key, $pem ) ) {
			return null;
		}
		$pub = self::point( $key );
		return null === $pub ? null : array(
			'pub' => $pub,
			'pem' => $pem,
		);
	}

	/**
	 * The encrypted body of one push message (Content-Encoding: aes128gcm).
	 *
	 * @param string $plain  Payload (at most ~3 KB).
	 * @param string $ua_pub The subscription's p256dh, raw 65 bytes.
	 * @param string $auth   The subscription's auth secret, raw 16 bytes.
	 */
	public static function encrypt( string $plain, string $ua_pub, string $auth ): ?string {
		if ( 65 !== strlen( $ua_pub ) || "\x04" !== $ua_pub[0] || 16 !== strlen( $auth ) || strlen( $plain ) > 3800 ) {
			return null;
		}
		$as_key = self::ec_key();
		if ( ! $as_key ) {
			return null;
		}
		$as_pub = self::point( $as_key );
		$peer   = openssl_pkey_get_public( self::pub_pem( $ua_pub ) );
		if ( null === $as_pub || ! $peer ) {
			return null;
		}
		$secret = openssl_pkey_derive( $peer, $as_key, 32 );
		if ( ! is_string( $secret ) || 32 !== strlen( $secret ) ) {
			return null;
		}

		// RFC 8291 §3.3–3.4.
		$prk_key = hash_hmac( 'sha256', $secret, $auth, true );
		$ikm     = hash_hmac( 'sha256', "WebPush: info\x00" . $ua_pub . $as_pub . "\x01", $prk_key, true );
		$salt    = random_bytes( 16 );
		$prk     = hash_hmac( 'sha256', $ikm, $salt, true );
		$cek     = substr( hash_hmac( 'sha256', "Content-Encoding: aes128gcm\x00\x01", $prk, true ), 0, 16 );
		$nonce   = substr( hash_hmac( 'sha256', "Content-Encoding: nonce\x00\x01", $prk, true ), 0, 12 );

		// One record: the payload and the last-record delimiter 0x02.
		$tag = '';
		$ct  = openssl_encrypt( $plain . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );
		if ( false === $ct ) {
			return null;
		}
		// Header: salt (16) | record size (uint32) | key id length (1) | key id (as_pub).
		return $salt . pack( 'N', 4096 ) . chr( 65 ) . $as_pub . $ct . $tag;
	}

	/**
	 * The Authorization header value for a push service (RFC 8292).
	 *
	 * @param string $endpoint Subscription endpoint (https).
	 * @param string $pub      VAPID public key, raw 65 bytes.
	 * @param string $pem      VAPID private key (PEM).
	 * @param string $subject  https: or mailto: contact.
	 * @param int    $now      Unix time.
	 */
	public static function vapid( string $endpoint, string $pub, string $pem, string $subject, int $now ): ?string {
		$parts = parse_url( $endpoint );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		$aud   = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		$head  = self::b64u( (string) json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );
		$claim = self::b64u(
			(string) json_encode(
				array(
					'aud' => $aud,
					'exp' => $now + 12 * 3600,
					'sub' => $subject,
				),
				JSON_UNESCAPED_SLASHES
			)
		);
		$input = $head . '.' . $claim;
		$key   = openssl_pkey_get_private( $pem );
		$der   = '';
		if ( ! $key || ! openssl_sign( $input, $der, $key, OPENSSL_ALGO_SHA256 ) ) {
			return null;
		}
		$sig = self::der_to_raw( $der );
		if ( null === $sig ) {
			return null;
		}
		return 'vapid t=' . $input . '.' . self::b64u( $sig ) . ', k=' . self::b64u( $pub );
	}

	/** PEM of a raw uncompressed P-256 public point. */
	public static function pub_pem( string $point ): string {
		$der = hex2bin( self::P256_SPKI ) . $point;
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	/** ECDSA DER signature (SEQUENCE of two INTEGERs) to the raw r|s of JWS. */
	public static function der_to_raw( string $der ): ?string {
		$len = strlen( $der );
		if ( $len < 8 || "\x30" !== $der[0] ) {
			return null;
		}
		$pos = 2;
		if ( ord( $der[1] ) & 0x80 ) {
			$pos += ord( $der[1] ) & 0x7f;
		}
		$out = '';
		for ( $i = 0; $i < 2; $i++ ) {
			if ( $pos + 2 > $len || "\x02" !== $der[ $pos ] ) {
				return null;
			}
			$n   = ord( $der[ $pos + 1 ] );
			$int = substr( $der, $pos + 2, $n );
			$pos += 2 + $n;
			$int = ltrim( $int, "\x00" );
			if ( strlen( $int ) > 32 ) {
				return null;
			}
			$out .= str_pad( $int, 32, "\x00", STR_PAD_LEFT );
		}
		return $out;
	}

	/** A fresh P-256 private key, or null. */
	private static function ec_key() {
		$key = openssl_pkey_new(
			array(
				'curve_name'       => 'prime256v1',
				'private_key_type' => OPENSSL_KEYTYPE_EC,
			)
		);
		return $key ? $key : null;
	}

	/** Uncompressed public point (65 bytes) of an EC key. */
	private static function point( $key ): ?string {
		$d = openssl_pkey_get_details( $key );
		if ( ! is_array( $d ) || empty( $d['ec']['x'] ) || empty( $d['ec']['y'] ) ) {
			return null;
		}
		$x = str_pad( (string) $d['ec']['x'], 32, "\x00", STR_PAD_LEFT );
		$y = str_pad( (string) $d['ec']['y'], 32, "\x00", STR_PAD_LEFT );
		return 32 === strlen( $x ) && 32 === strlen( $y ) ? "\x04" . $x . $y : null;
	}
}

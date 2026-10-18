<?php
/**
 * NM_Crypt — AES-256 encryption of archive entries (WinZip AE-2) and a
 * small sealed box for secrets in state files.
 *
 * AE-2, as 7-Zip, WinZip and macOS Archive Utility read it:
 * - per entry: salt (16 bytes), password verifier (2), the data XORed
 *   with AES-256 in CTR mode (little-endian counter from 1), the first 10
 *   bytes of HMAC-SHA1 over the encrypted data;
 * - keys: PBKDF2-HMAC-SHA1(password, salt, 1000 rounds, 66 bytes) =
 *   AES key (32) + HMAC key (32) + verifier (2);
 * - method 99 with extra field 0x9901 naming the real method; CRC 0.
 *
 * Every entry has its own salt, so no two entries share a key stream.
 * OpenSSL (AES-256-ECB for the counter blocks, AES-256-GCM for seal())
 * is required; available() says whether this PHP has it.
 */

defined( 'ABSPATH' ) || defined( 'NM_STANDALONE' ) || exit;

final class NM_Crypt {

	const ROUNDS   = 1000;
	const SALT     = 16;
	const MAC      = 10;
	const OVERHEAD = 28; // salt + verifier + MAC.
	const MIN      = 12; // Shortest password we accept.

	/** Key for seal() / unseal(); '' = from the site's salts (wp-config.php). */
	public static $key = '';

	public static function available(): bool {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'hash_pbkdf2' ) ) {
			return false;
		}
		$have = array_map( 'strtolower', (array) openssl_get_cipher_methods() );
		return in_array( 'aes-256-ecb', $have, true ) && in_array( 'aes-256-gcm', $have, true );
	}

	/** @return string[] AES key, HMAC key, verifier. */
	public static function derive( string $password, string $salt ): array {
		$k = hash_pbkdf2( 'sha1', $password, $salt, self::ROUNDS, 66, true );
		return array( substr( $k, 0, 32 ), substr( $k, 32, 32 ), substr( $k, 64, 2 ) );
	}

	/**
	 * XOR $data with the key stream from byte $offset of the entry, so a
	 * large entry can be done in pieces across requests.
	 */
	public static function ctr( string $key, string $data, int $offset ): string {
		$len = strlen( $data );
		if ( 0 === $len ) {
			return '';
		}
		$first  = intdiv( $offset, 16 ) + 1;
		$skip   = $offset % 16;
		$blocks = intdiv( $skip + $len + 15, 16 );
		$ctr    = array();
		for ( $i = 0; $i < $blocks; $i++ ) {
			$ctr[] = $first + $i;
			$ctr[] = 0;
		}
		$ks = openssl_encrypt( pack( 'P*', ...$ctr ), 'aes-256-ecb', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING );
		if ( false === $ks ) {
			throw new RuntimeException( 'AES-256 is not available.' );
		}
		return $data ^ substr( $ks, $skip, $len );
	}

	/** Truncated HMAC-SHA1 of a whole string. */
	public static function mac( string $mac_key, string $data ): string {
		return substr( hash_hmac( 'sha1', $data, $mac_key, true ), 0, self::MAC );
	}

	/** Truncated HMAC-SHA1 of $len bytes of an open file from $at. */
	public static function mac_file( string $mac_key, $fh, int $at, int $len ) {
		$ctx = hash_init( 'sha1', HASH_HMAC, $mac_key );
		fseek( $fh, $at );
		$left = $len;
		while ( $left > 0 ) {
			$buf = fread( $fh, (int) min( 1048576, $left ) );
			if ( false === $buf || '' === $buf ) {
				return false;
			}
			hash_update( $ctx, $buf );
			$left -= strlen( $buf );
		}
		return substr( hash_final( $ctx, true ), 0, self::MAC );
	}

	/** One entry's encrypted form (small entries, in memory). */
	public static function encrypt( string $password, string $data ): string {
		$salt                  = random_bytes( self::SALT );
		list( $ek, $mk, $pv ) = self::derive( $password, $salt );
		$ct                    = self::ctr( $ek, $data, 0 );
		return $salt . $pv . $ct . self::mac( $mk, $ct );
	}

	/**
	 * The plain data of an encrypted entry, or an error code: 'password'
	 * (wrong password) or 'auth' (data changed or damaged).
	 *
	 * @return string|array Data, or array( error ).
	 */
	public static function decrypt( string $password, string $blob ) {
		if ( strlen( $blob ) < self::OVERHEAD ) {
			return array( 'auth' );
		}
		list( $ek, $mk, $pv ) = self::derive( $password, substr( $blob, 0, self::SALT ) );
		if ( ! hash_equals( $pv, substr( $blob, self::SALT, 2 ) ) ) {
			return array( 'password' );
		}
		$ct = substr( $blob, self::SALT + 2, -self::MAC );
		if ( ! hash_equals( self::mac( $mk, $ct ), substr( $blob, -self::MAC ) ) ) {
			return array( 'auth' );
		}
		return self::ctr( $ek, $ct, 0 );
	}

	/* ---------------------------------------------------------------
	 * Sealed secrets (passwords in options and job files)
	 * ------------------------------------------------------------- */

	private static function seal_key(): string {
		if ( '' !== self::$key ) {
			return self::$key;
		}
		$m = '';
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT' ) as $c ) {
			$m .= defined( $c ) ? (string) constant( $c ) : '';
		}
		return hash( 'sha256', 'data-migrator-seal|' . $m, true );
	}

	/** AES-256-GCM, base64. '' when OpenSSL is missing. */
	public static function seal( string $plain ): string {
		if ( ! self::available() ) {
			return '';
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plain, 'aes-256-gcm', self::seal_key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $ct ? '' : 'g1.' . base64_encode( $iv . $tag . $ct );
	}

	/** The sealed value, or null (other key, damaged, no OpenSSL). */
	public static function unseal( string $sealed ): ?string {
		if ( 0 !== strpos( $sealed, 'g1.' ) || ! self::available() ) {
			return null;
		}
		$raw = base64_decode( substr( $sealed, 3 ), true );
		if ( false === $raw || strlen( $raw ) < 28 ) {
			return null;
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::seal_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $plain ? null : $plain;
	}
}

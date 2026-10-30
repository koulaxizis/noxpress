<?php
/**
 * TFI_Crypt — sealed secrets (Bible §12, 1.42).
 *
 * The feed password (the Application Password the publisher sent) is
 * kept in the tfi_pass option (autoload off), sealed with AES-256-GCM.
 * The key comes from the salts in wp-config.php, so a copy of the
 * database alone does not reveal it. When the salts
 * change the value can no longer be read: the settings page says so and
 * asks for the password again.
 */

defined( 'ABSPATH' ) || exit;

final class TFI_Crypt {

	public static function available(): bool {
		return function_exists( 'openssl_encrypt' )
			&& in_array( 'aes-256-gcm', array_map( 'strtolower', (array) openssl_get_cipher_methods() ), true );
	}

	private static function key(): string {
		$m = '';
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT' ) as $c ) {
			$m .= defined( $c ) ? (string) constant( $c ) : '';
		}
		return hash( 'sha256', 'trade-feed-import-seal|' . $m, true );
	}

	/** AES-256-GCM, base64 with the prefix g1. '' when OpenSSL is missing. */
	public static function seal( string $plain ): string {
		if ( ! self::available() ) {
			return '';
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
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
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $plain ? null : $plain;
	}
}

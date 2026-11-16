<?php
/**
 * LGD_Crypt — sealed 2FA secrets (Bible §12, pattern TFI_Crypt).
 *
 * AES-256-GCM with a key from the salts in wp-config.php, and the user id
 * as additional data, so a copy of the database alone does not reveal a
 * secret and a sealed value cannot be moved to another account. When the
 * salts change a secret can no longer be read: that user logs in with a
 * recovery code (or another door of the readme) and sets 2FA up again.
 */

defined( 'ABSPATH' ) || exit;

final class LGD_Crypt {

	public static function available(): bool {
		return function_exists( 'openssl_encrypt' )
			&& in_array( 'aes-256-gcm', array_map( 'strtolower', (array) openssl_get_cipher_methods() ), true );
	}

	private static function key(): string {
		$m = '';
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT' ) as $c ) {
			$m .= defined( $c ) ? (string) constant( $c ) : '';
		}
		return hash( 'sha256', 'login-guard-2fa-seal|' . $m, true );
	}

	/** AES-256-GCM bound to a user id, base64 with the prefix g1. '' when OpenSSL is missing. */
	public static function seal( string $plain, int $uid ): string {
		if ( ! self::available() ) {
			return '';
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'u' . $uid );
		return false === $ct ? '' : 'g1.' . base64_encode( $iv . $tag . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary to text.
	}

	/** The sealed value, or null (other key, other user, damaged, no OpenSSL). */
	public static function unseal( string $sealed, int $uid ): ?string {
		if ( 0 !== strpos( $sealed, 'g1.' ) || ! self::available() ) {
			return null;
		}
		$raw = base64_decode( substr( $sealed, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- text to binary.
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return null;
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), 'u' . $uid );
		return false === $plain ? null : $plain;
	}
}

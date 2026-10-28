<?php
/**
 * BRC_Crypt — sealed secrets (Bible §12, 1.42).
 *
 * Secrets: the Viber channel token, the newsletter webhook secret and the
 * Instagram token. A constant in wp-config.php wins (BRC_VIBER_TOKEN,
 * BRC_MAILERLITE_SECRET, BRC_INSTAGRAM_TOKEN)
 * and the settings page says so. Otherwise the secret is kept in the
 * option brc_secret_<name> (autoload off), sealed with AES-256-GCM under a
 * key made from the salts in wp-config.php, so a copy of the database
 * alone does not reveal it. When the salts change the value can no longer
 * be read: the settings page says so and asks for it again. Secrets never
 * go into the settings export, the history or an error message (mask()).
 */

defined( 'ABSPATH' ) || exit;

final class BRC_Crypt {

	/** Secret name => wp-config.php constant. */
	const SECRETS = array(
		'viber'      => 'BRC_VIBER_TOKEN',
		'mailerlite' => 'BRC_MAILERLITE_SECRET',
		'instagram'  => 'BRC_INSTAGRAM_TOKEN',
	);

	public static function available(): bool {
		return function_exists( 'openssl_encrypt' )
			&& in_array( 'aes-256-gcm', array_map( 'strtolower', (array) openssl_get_cipher_methods() ), true );
	}

	private static function key(): string {
		$m = '';
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT' ) as $c ) {
			$m .= defined( $c ) ? (string) constant( $c ) : '';
		}
		return hash( 'sha256', 'broadcast-seal|' . $m, true );
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

	/** True when the secret comes from wp-config.php. */
	public static function from_constant( string $name ): bool {
		$c = isset( self::SECRETS[ $name ] ) ? self::SECRETS[ $name ] : '';
		return '' !== $c && defined( $c ) && is_string( constant( $c ) ) && '' !== constant( $c );
	}

	/** The secret, or '' when none is set or it can no longer be read. */
	public static function get( string $name ): string {
		if ( ! isset( self::SECRETS[ $name ] ) ) {
			return '';
		}
		if ( self::from_constant( $name ) ) {
			return (string) constant( self::SECRETS[ $name ] );
		}
		$sealed = get_option( 'brc_secret_' . $name, '' );
		if ( ! is_string( $sealed ) || '' === $sealed ) {
			return '';
		}
		$plain = self::unseal( $sealed );
		return null === $plain ? '' : $plain;
	}

	/** 'none' | 'set' | 'constant' | 'unreadable' (salts changed). */
	public static function state( string $name ): string {
		if ( self::from_constant( $name ) ) {
			return 'constant';
		}
		$sealed = get_option( 'brc_secret_' . $name, '' );
		if ( ! is_string( $sealed ) || '' === $sealed ) {
			return 'none';
		}
		return null === self::unseal( $sealed ) ? 'unreadable' : 'set';
	}

	/** Seals and stores a secret; '' deletes it. False when it could not be sealed. */
	public static function put( string $name, string $plain ): bool {
		if ( ! isset( self::SECRETS[ $name ] ) ) {
			return false;
		}
		if ( '' === $plain ) {
			delete_option( 'brc_secret_' . $name );
			return true;
		}
		$sealed = self::seal( $plain );
		if ( '' === $sealed ) {
			return false;
		}
		update_option( 'brc_secret_' . $name, $sealed, false );
		return true;
	}

	/** Removes every known secret (also URL-encoded) from a text. */
	public static function mask( string $text ): string {
		foreach ( array_keys( self::SECRETS ) as $name ) {
			$v = self::get( $name );
			if ( strlen( $v ) >= 6 ) {
				$text = str_replace( array( $v, rawurlencode( $v ) ), '***', $text );
			}
		}
		return $text;
	}
}

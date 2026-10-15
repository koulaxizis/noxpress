<?php
/**
 * SBR_Crypto — Ed25519 keys and signatures, random tokens.
 *
 * One key pair per WordPress install (option sbr_keys, autoload off). The
 * Hub signs requests with it, a Site signs its answers with it. The secret
 * key is stored sealed (secretbox) with a key derived from the site's
 * AUTH_KEY / AUTH_SALT, so a copy of the database alone does not reveal
 * it. Changing those salts makes the key unreadable: the plugin then makes
 * a new pair and every pairing has to be done again (the admin says so).
 *
 * sodium_* comes from PHP or from WordPress' bundled sodium_compat.
 */

defined( 'ABSPATH' ) || exit;

final class SBR_Crypto {

	const OPT = 'sbr_keys';

	/** Per-request cache: array( 'pub' => raw, 'sec' => raw ). */
	private static $keys = null;

	/** Whether the stored key could not be opened (salts changed). */
	private static $reset = false;

	/** The key pair, created on first use. */
	public static function keys(): array {
		if ( null !== self::$keys ) {
			return self::$keys;
		}
		$raw = get_option( self::OPT, array() );
		if ( is_array( $raw ) && isset( $raw['pub'], $raw['sealed'] ) ) {
			$pub = base64_decode( (string) $raw['pub'], true );
			$sec = self::open( (string) $raw['sealed'] );
			if ( false !== $pub && null !== $sec && SODIUM_CRYPTO_SIGN_SECRETKEYBYTES === strlen( $sec ) && sodium_crypto_sign_publickey_from_secretkey( $sec ) === $pub ) {
				self::$keys = array(
					'pub' => $pub,
					'sec' => $sec,
				);
				return self::$keys;
			}
			self::$reset = true;
			update_option( 'sbr_key_reset', time(), false );
		}
		$pair = sodium_crypto_sign_keypair();
		$sec  = sodium_crypto_sign_secretkey( $pair );
		$pub  = sodium_crypto_sign_publickey( $pair );
		update_option(
			self::OPT,
			array(
				'pub'     => base64_encode( $pub ),
				'sealed'  => self::seal( $sec ),
				'created' => time(),
			),
			false
		);
		self::$keys = array(
			'pub' => $pub,
			'sec' => $sec,
		);
		return self::$keys;
	}

	/** True when this request had to replace an unreadable key. */
	public static function was_reset(): bool {
		return self::$reset;
	}

	public static function public_b64(): string {
		return base64_encode( self::keys()['pub'] );
	}

	/** Short, stable id of this install's key (shown in the admin). */
	public static function key_id( string $pub_b64 = '' ): string {
		$pub_b64 = '' === $pub_b64 ? self::public_b64() : $pub_b64;
		return substr( hash( 'sha256', $pub_b64 ), 0, 12 );
	}

	public static function sign( string $message ): string {
		return base64_encode( sodium_crypto_sign_detached( $message, self::keys()['sec'] ) );
	}

	public static function verify( string $message, string $sig_b64, string $pub_b64 ): bool {
		$sig = base64_decode( $sig_b64, true );
		$pub = base64_decode( $pub_b64, true );
		if ( false === $sig || false === $pub || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $pub ) ) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached( $sig, $message, $pub );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** A valid base64 Ed25519 public key. */
	public static function valid_pub( string $pub_b64 ): bool {
		$pub = base64_decode( $pub_b64, true );
		return false !== $pub && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $pub );
	}

	/** Random URL-safe token with a prefix (32 random bytes). */
	public static function token( string $prefix = '' ): string {
		return $prefix . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	/** What we store instead of a token. */
	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	public static function b64url( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	public static function b64url_decode( string $s ) {
		$s = strtr( $s, '-_', '+/' );
		$m = strlen( $s ) % 4;
		if ( $m ) {
			$s .= str_repeat( '=', 4 - $m );
		}
		return base64_decode( $s, true );
	}

	/* =====================================================================
	 * Sealing with the site's salts
	 * =================================================================== */

	private static function box_key(): string {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . '|' . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' ) . '|site-bridge';
		return sodium_crypto_generichash( $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	private static function seal( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::box_key() ) );
	}

	private static function open( string $sealed ): ?string {
		$raw = base64_decode( $sealed, true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::box_key()
			);
		} catch ( \Throwable $e ) {
			return null;
		}
		return false === $plain ? null : $plain;
	}
}

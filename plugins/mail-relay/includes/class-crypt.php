<?php
/**
 * MRL_Crypt — sealed secrets (Bible §12 «Μυστικά που φυλάει ένα plugin»).
 *
 * Two slots, one per connection: 'primary' and 'backup'. The secret is the
 * SMTP password (for Proton Mail: the SMTP token), the API key, or for
 * Amazon SES the secret access key.
 *
 * A constant in wp-config.php wins (MRL_PRIMARY_SECRET, MRL_BACKUP_SECRET)
 * and the page says so. Otherwise the option mrl_secret_<slot> (autoload
 * off) holds the secret sealed with AES-256-GCM under a key made from the
 * salts in wp-config.php, together with the «bind» of the connection it
 * belongs to (provider, host, port, user, region, domain). When those
 * change the secret is deleted (Bible §11 «Κωδικοί δεμένοι με το host»),
 * so a password typed for one server never goes to another.
 *
 * Without real salts or without OpenSSL nothing is stored (put() returns
 * false) and the page asks for the constant instead: no pretend
 * encryption. When the salts change, state() answers 'unreadable'.
 *
 * Option names contain "secret", so Site Bridge shows them as ***.
 */

defined( 'ABSPATH' ) || exit;

final class MRL_Crypt {

	/** Slot => wp-config.php constant. */
	const SLOTS = array(
		'primary' => 'MRL_PRIMARY_SECRET',
		'backup'  => 'MRL_BACKUP_SECRET',
	);

	const OPT = 'mrl_secret_';

	/**
	 * Option: slot => bind of the connection a wp-config.php constant
	 * belongs to. A constant is only used for that connection, like a
	 * sealed secret, so changing the server in the settings (or a restored
	 * settings file) never sends it somewhere else.
	 */
	const PIN = 'mrl_const_pin';

	/** Per-request cache of opened secrets: slot => string. */
	private static $open = array();

	public static function has_salts(): bool {
		if ( class_exists( 'Noxpress_Safe' ) && method_exists( 'Noxpress_Safe', 'has_salts' ) ) {
			return Noxpress_Safe::has_salts();
		}
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT' ) as $c ) {
			$v = defined( $c ) ? (string) constant( $c ) : '';
			if ( strlen( $v ) < 16 || false !== stripos( $v, 'put your unique phrase here' ) ) {
				return false;
			}
		}
		return true;
	}

	public static function openssl(): bool {
		return function_exists( 'openssl_encrypt' )
			&& in_array( 'aes-256-gcm', array_map( 'strtolower', (array) openssl_get_cipher_methods() ), true );
	}

	public static function available(): bool {
		return self::has_salts() && self::openssl();
	}

	private static function key(): string {
		$m = '';
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT' ) as $c ) {
			$m .= defined( $c ) ? (string) constant( $c ) : '';
		}
		return hash( 'sha256', 'mail-relay-seal|' . $m, true );
	}

	/** AES-256-GCM, base64 with the prefix g1. '' when sealing is not possible. */
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

	public static function from_constant( string $slot ): bool {
		$c = isset( self::SLOTS[ $slot ] ) ? self::SLOTS[ $slot ] : '';
		return '' !== $c && defined( $c ) && is_string( constant( $c ) ) && '' !== constant( $c );
	}

	/** Stored record: array{v:string,b:string} or null. */
	private static function record( string $slot ): ?array {
		$sealed = get_option( self::OPT . $slot, '' );
		if ( ! is_string( $sealed ) || '' === $sealed ) {
			return null;
		}
		$plain = self::unseal( $sealed );
		if ( null === $plain ) {
			return array(
				'v' => '',
				'b' => '',
				'x' => true,
			);
		}
		$rec = json_decode( $plain, true );
		if ( ! is_array( $rec ) || ! isset( $rec['v'], $rec['b'] ) || ! is_string( $rec['v'] ) || ! is_string( $rec['b'] ) ) {
			return array(
				'v' => '',
				'b' => '',
				'x' => true,
			);
		}
		return $rec;
	}

	/**
	 * The secret of a slot for the connection with this bind, or '' when
	 * none is set, it belongs to another connection or cannot be read.
	 */
	public static function get( string $slot, string $bind ): string {
		if ( ! isset( self::SLOTS[ $slot ] ) ) {
			return '';
		}
		if ( self::from_constant( $slot ) ) {
			$pin = self::pin_of( $slot );
			return ( '' !== $pin && hash_equals( $pin, $bind ) ) ? (string) constant( self::SLOTS[ $slot ] ) : '';
		}
		$k = $slot . '|' . $bind;
		if ( isset( self::$open[ $k ] ) ) {
			return self::$open[ $k ];
		}
		$rec = self::record( $slot );
		$v   = ( null !== $rec && empty( $rec['x'] ) && hash_equals( $rec['b'], $bind ) ) ? $rec['v'] : '';
		self::$open[ $k ] = $v;
		return $v;
	}

	/**
	 * 'none' | 'set' | 'unreadable' (salts changed) | 'other' (another
	 * connection) | 'constant' | 'constant_new' (not yet tied to a
	 * connection) | 'constant_other' (tied to another connection).
	 */
	public static function state( string $slot, string $bind ): string {
		if ( self::from_constant( $slot ) ) {
			$pin = self::pin_of( $slot );
			if ( '' === $pin ) {
				return 'constant_new';
			}
			return hash_equals( $pin, $bind ) ? 'constant' : 'constant_other';
		}
		$rec = self::record( $slot );
		if ( null === $rec ) {
			return 'none';
		}
		if ( ! empty( $rec['x'] ) ) {
			return 'unreadable';
		}
		return hash_equals( $rec['b'], $bind ) ? 'set' : 'other';
	}

	/**
	 * Seals and stores the secret of a slot for a bind; '' deletes it.
	 * False when it could not be sealed (no salts, no OpenSSL).
	 */
	public static function put( string $slot, string $plain, string $bind ): bool {
		if ( ! isset( self::SLOTS[ $slot ] ) ) {
			return false;
		}
		self::$open = array();
		if ( '' === $plain ) {
			delete_option( self::OPT . $slot );
			return true;
		}
		$sealed = self::seal(
			(string) wp_json_encode(
				array(
					'v' => $plain,
					'b' => $bind,
				)
			)
		);
		if ( '' === $sealed ) {
			return false;
		}
		update_option( self::OPT . $slot, $sealed, false );
		return true;
	}

	public static function is_constant_state( string $state ): bool {
		return 0 === strpos( $state, 'constant' );
	}

	private static function pin_of( string $slot ): string {
		$p = get_option( self::PIN, array() );
		return is_array( $p ) && isset( $p[ $slot ] ) && is_string( $p[ $slot ] ) ? $p[ $slot ] : '';
	}

	/** Ties the constant of a slot to a connection. */
	public static function pin( string $slot, string $bind ): void {
		$p = get_option( self::PIN, array() );
		$p = is_array( $p ) ? $p : array();
		$p[ $slot ] = $bind;
		update_option( self::PIN, $p, false );
		self::$open = array();
	}

	/**
	 * Whether the current user may tie a constant to another connection:
	 * someone who could edit wp-config.php anyway. On multisite the
	 * plugin already needs a network administrator.
	 */
	public static function can_repin(): bool {
		if ( ! current_user_can( Mail_Relay::CAP ) ) {
			return false;
		}
		if ( is_multisite() ) {
			return true;
		}
		return ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) && ! ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT );
	}

	/** Deletes a stored secret whose bind is not the current one (or that cannot be read any more is kept, so the page can say so). */
	public static function forget_other( string $slot, string $bind ): void {
		if ( 'other' === self::state( $slot, $bind ) ) {
			delete_option( self::OPT . $slot );
			self::$open = array();
		}
	}

	/** A short fingerprint of a slot's current secret (for the «tested» mark), never the secret. */
	public static function fingerprint( string $slot, string $bind ): string {
		$v = self::get( $slot, $bind );
		if ( '' === $v || ! self::available() ) {
			return ''; // Without real salts the HMAC key would be public: no secret-derived mark at all.
		}
		return substr( hash_hmac( 'sha256', $v, self::key() ), 0, 16 );
	}

	/**
	 * Removes the secrets, the user names and anything that looks like an
	 * SMTP AUTH exchange or an Authorization header from a text, so an
	 * error message can be shown and logged.
	 */
	public static function scrub( string $text, array $extra = array() ): string {
		$needles = array();
		foreach ( array( 'primary', 'backup' ) as $slot ) {
			$c = MRL_Settings::conn( $slot );
			$v = self::get( $slot, MRL_Settings::bind( $c ) );
			if ( strlen( $v ) >= 4 ) {
				$needles[] = $v;
				$needles[] = rawurlencode( $v );
				$needles[] = base64_encode( $v );
				$needles[] = base64_encode( "\0" . $c['user'] . "\0" . $v );
			}
		}
		foreach ( $extra as $e ) {
			if ( is_string( $e ) && strlen( $e ) >= 4 ) {
				$needles[] = $e;
				$needles[] = base64_encode( $e );
			}
		}
		if ( $needles ) {
			$text = str_replace( $needles, '***', $text );
		}
		$text = (string) preg_replace( '/\b(AUTH\s+(?:PLAIN|LOGIN|CRAM-MD5|XOAUTH2))\s+\S+/i', '$1 ***', $text );
		$text = (string) preg_replace( '/\b(Authorization|api-key|X-Postmark-Server-Token)\s*[:=]\s*\S+(\s+\S+)?/i', '$1: ***', $text );
		$text = (string) preg_replace( '/\b(AKIA|ASIA)[A-Z0-9]{12,}\b/', '***', $text );
		$text = (string) preg_replace( '/\b(SG\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}|xkeysib-[a-f0-9\-]{20,}|key-[a-f0-9]{20,})\b/i', '***', $text );
		return $text;
	}
}

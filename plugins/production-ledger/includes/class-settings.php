<?php
/**
 * PL_Settings — the pl_settings option (autoload no), strict validation.
 *
 *  - start      'Y-m-d': the ledger counts from this day (default
 *               2026-12-01). Nothing earlier is reported and no entry
 *               can be dated earlier.
 *  - house      beneficiary names of Revenue Splitter that are the
 *               publisher itself: their share is NOT a creator payout.
 *  - carriers   [ key => [ label, match[] ] ]: a shipping line belongs to
 *               a carrier when its method id or its name contains one of
 *               the match words (case-insensitive). Local pickup never.
 *  - pay        [ key => [ label, methods[] ] ]: payment groups for the
 *               fees of a payment provider (payment method ids).
 *  - spread_consumables  consumables pools spread over the month's shipped
 *               orders (on) or stay as one general expense (off).
 *  - mods       printful, oros: every module off after activation (§20).
 *  - pf_store   Printful store id (account tokens need it; '' = none).
 *  - drop       delete the entries table on uninstall (off).
 *
 * The Printful key is a secret (Bible §12): constant PL_PRINTFUL_KEY in
 * wp-config.php wins; otherwise option pl_pf_key, sealed with AES-256-GCM
 * under a key derived from the wp-config.php salts. It never leaves the
 * server and never appears in a settings export.
 */

defined( 'ABSPATH' ) || exit;

final class PL_Settings {

	const OPTION     = 'pl_settings';
	const SECRET     = 'pl_pf_key';
	const START      = '2026-12-01';
	const MAX_GROUPS = 12;

	/** @var array|null Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'start'              => self::START,
			'house'              => array(),
			'carriers'           => array(
				'boxnow'  => array(
					'label' => 'BOX NOW',
					'match' => array( 'box_now', 'boxnow', 'box now' ),
				),
				'courier' => array(
					'label' => 'Courier',
					'match' => array( 'flat_rate', 'courier' ),
				),
			),
			'pay'                => array(
				'viva'   => array(
					'label'   => 'Viva',
					'methods' => array( 'vivacom_smart', 'vivawallet_native' ),
				),
				'stripe' => array(
					'label'   => 'Stripe',
					'methods' => array( 'stripe', 'stripe_klarna' ),
				),
				'paypal' => array(
					'label'   => 'PayPal',
					'methods' => array( 'ppcp-gateway' ),
				),
			),
			'spread_consumables' => true,
			'mods'               => array(
				'printful' => false,
				'oros'     => false,
			),
			'pf_store'           => '',
			'drop'               => false,
		);
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPTION, array() );
			self::$cache = self::sanitize( is_array( $raw ) ? $raw : array() );
		}
		return self::$cache;
	}

	public static function module( string $key ): bool {
		$s = self::get();
		return ! empty( $s['mods'][ $key ] );
	}

	public static function start(): string {
		return self::get()['start'];
	}

	/**
	 * Strict validation of a whole settings array (stored or imported).
	 * Unknown keys go; a bad value falls back to its default.
	 */
	public static function sanitize( array $in ): array {
		$d   = self::defaults();
		$out = $d;

		if ( isset( $in['start'] ) && self::valid_date( (string) $in['start'] ) ) {
			$out['start'] = (string) $in['start'];
		}

		if ( isset( $in['house'] ) && is_array( $in['house'] ) ) {
			$h = array();
			foreach ( $in['house'] as $n ) {
				$n = trim( sanitize_text_field( (string) $n ) );
				if ( '' !== $n && mb_strlen( $n ) <= 120 ) {
					$h[ $n ] = true;
				}
			}
			$out['house'] = array_slice( array_keys( $h ), 0, 20 );
		}

		if ( isset( $in['carriers'] ) && is_array( $in['carriers'] ) ) {
			$out['carriers'] = self::groups( $in['carriers'], 'match' );
		}
		if ( isset( $in['pay'] ) && is_array( $in['pay'] ) ) {
			$out['pay'] = self::groups( $in['pay'], 'methods' );
		}

		if ( isset( $in['spread_consumables'] ) ) {
			$out['spread_consumables'] = (bool) $in['spread_consumables'];
		}
		if ( isset( $in['mods'] ) && is_array( $in['mods'] ) ) {
			foreach ( array_keys( $d['mods'] ) as $m ) {
				$out['mods'][ $m ] = ! empty( $in['mods'][ $m ] );
			}
		}
		if ( isset( $in['pf_store'] ) && preg_match( '/^\d{0,12}$/', (string) $in['pf_store'] ) ) {
			$out['pf_store'] = (string) $in['pf_store'];
		}
		if ( isset( $in['drop'] ) ) {
			$out['drop'] = (bool) $in['drop'];
		}
		return $out;
	}

	/** Carriers / payment groups: key => [ label, list[] ]. */
	private static function groups( array $raw, string $list_key ): array {
		$out = array();
		foreach ( $raw as $k => $g ) {
			$k = sanitize_key( (string) $k );
			if ( '' === $k || strlen( $k ) > 20 || ! is_array( $g ) || count( $out ) >= self::MAX_GROUPS ) {
				continue;
			}
			$label = isset( $g['label'] ) ? trim( sanitize_text_field( (string) $g['label'] ) ) : '';
			$list  = array();
			foreach ( isset( $g[ $list_key ] ) && is_array( $g[ $list_key ] ) ? $g[ $list_key ] : array() as $w ) {
				$w = strtolower( trim( sanitize_text_field( (string) $w ) ) );
				if ( '' !== $w && strlen( $w ) <= 60 ) {
					$list[ $w ] = true;
				}
			}
			if ( '' === $label || ! $list ) {
				continue;
			}
			$out[ $k ] = array(
				'label'   => mb_substr( $label, 0, 60 ),
				$list_key => array_slice( array_keys( $list ), 0, 20 ),
			);
		}
		return $out;
	}

	/**
	 * Parses the textarea form "key | Label | word, word" (one group per
	 * line) into the groups array.
	 */
	public static function parse_lines( string $text, string $list_key ): array {
		$raw = array();
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$p = array_map( 'trim', explode( '|', (string) $line ) );
			if ( count( $p ) < 3 ) {
				continue;
			}
			$raw[ $p[0] ] = array(
				'label'   => $p[1],
				$list_key => array_map( 'trim', explode( ',', $p[2] ) ),
			);
		}
		return self::groups( $raw, $list_key );
	}

	/** The groups back as textarea lines. */
	public static function to_lines( array $groups, string $list_key ): string {
		$l = array();
		foreach ( $groups as $k => $g ) {
			$l[] = $k . ' | ' . $g['label'] . ' | ' . implode( ', ', $g[ $list_key ] );
		}
		return implode( "\n", $l );
	}

	public static function save( array $in ): array {
		$clean = self::sanitize( $in );
		update_option( self::OPTION, $clean, false );
		self::$cache = $clean;
		PL_Report::bump();
		return $clean;
	}

	public static function valid_date( string $d ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) && (int) $m[1] >= 2000 && (int) $m[1] <= 2100;
	}

	/* =====================================================================
	 * Printful key (Bible §12: sealed secret, wp-config constant wins)
	 * =================================================================== */

	public static function pf_key_from_constant(): bool {
		return defined( 'PL_PRINTFUL_KEY' ) && is_string( PL_PRINTFUL_KEY ) && '' !== PL_PRINTFUL_KEY;
	}

	/** 'constant' | 'option' | 'unreadable' (salts changed) | 'none'. */
	public static function pf_key_state(): string {
		if ( self::pf_key_from_constant() ) {
			return 'constant';
		}
		$raw = (string) get_option( self::SECRET, '' );
		if ( '' === $raw ) {
			return 'none';
		}
		return '' === self::open( $raw ) ? 'unreadable' : 'option';
	}

	public static function pf_key(): string {
		if ( self::pf_key_from_constant() ) {
			return (string) constant( 'PL_PRINTFUL_KEY' ); // Read by name: the constant lives only in wp-config.php.
		}
		$raw = (string) get_option( self::SECRET, '' );
		return '' === $raw ? '' : self::open( $raw );
	}

	public static function set_pf_key( string $key ): bool {
		$key = trim( $key );
		if ( '' === $key ) {
			delete_option( self::SECRET );
			return true;
		}
		if ( ! preg_match( '/^[A-Za-z0-9_\-\.]{20,200}$/', $key ) ) {
			return false;
		}
		$sealed = self::seal( $key );
		if ( '' === $sealed ) {
			return false;
		}
		update_option( self::SECRET, $sealed, false );
		return true;
	}

	private static function crypto_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|production-ledger|' . wp_salt( 'secure_auth' ), true );
	}

	public static function seal( string $plain ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plain, 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $ct ? '' : 'v1:' . base64_encode( $iv . $tag . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function open( string $sealed ): string {
		if ( 0 !== strpos( $sealed, 'v1:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$bin = base64_decode( substr( $sealed, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $bin || strlen( $bin ) < 29 ) {
			return '';
		}
		$pt = openssl_decrypt( substr( $bin, 28 ), 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, substr( $bin, 0, 12 ), substr( $bin, 12, 16 ) );
		return false === $pt ? '' : $pt;
	}

	/** Removes a secret from any text (log lines, error excerpts). */
	public static function scrub( string $text ): string {
		$k = self::pf_key();
		if ( '' !== $k ) {
			$text = str_replace( array( $k, rawurlencode( $k ) ), '***', $text );
		}
		return $text;
	}
}

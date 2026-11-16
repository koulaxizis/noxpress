<?php
/**
 * EBD_Code — the fingerprint code of one download permission.
 *
 * "ED-" + 8 characters of Crockford base32 (no I, L, O, U) taken from
 * HMAC-SHA256( secret, "order|product|download id" ). The secret is the
 * plugin's own random option ebd_secret (made on activation), not the
 * salts, so a salt change never breaks the codes in files already sold.
 *
 * Nothing is stored per download: a lookup recomputes the code of every
 * download permission of the shop (WooCommerce's table, read in batches
 * of 2,000), which is fast for any real shop and writes nothing on a
 * visitor's download (Bible §14.5).
 */

defined( 'ABSPATH' ) || exit;

final class EBD_Code {

	const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	const PATTERN  = '/ED-[0-9A-HJKMNP-TV-Z]{8}/';

	/** The shop's secret (made on first use when the activation hook did not run). */
	private static function secret(): string {
		$s = (string) get_option( 'ebd_secret', '' );
		if ( 64 !== strlen( $s ) ) {
			$s = bin2hex( random_bytes( 32 ) );
			update_option( 'ebd_secret', $s, false );
		}
		return $s;
	}

	public static function make( int $order_id, int $product_id, string $download_id ): string {
		$mac = hash_hmac( 'sha256', $order_id . '|' . $product_id . '|' . $download_id, self::secret(), true );
		// 40 bits → 8 base32 characters.
		$bits = '';
		for ( $i = 0; $i < 5; $i++ ) {
			$bits .= str_pad( decbin( ord( $mac[ $i ] ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		for ( $i = 0; $i < 8; $i++ ) {
			$out .= self::ALPHABET[ bindec( substr( $bits, $i * 5, 5 ) ) ];
		}
		return 'ED-' . $out;
	}

	/** A code as typed by a person: upper case, O→0, I/L→1, spaces removed; '' when invalid. */
	public static function normalise( string $raw ): string {
		$c = strtoupper( (string) preg_replace( '/\s+/', '', $raw ) );
		if ( 0 !== strpos( $c, 'ED-' ) && 8 === strlen( $c ) ) {
			$c = 'ED-' . $c;
		}
		$c = 'ED-' . strtr( substr( $c, 3 ), array( 'O' => '0', 'I' => '1', 'L' => '1' ) );
		return preg_match( '/^ED-[0-9A-HJKMNP-TV-Z]{8}$/', $c ) ? $c : '';
	}

	/**
	 * The download permissions that carry a code.
	 *
	 * @return array<int, array{permission_id:int, order_id:int, product_id:int, download_id:string, user_email:string, user_id:int, download_count:int, access_granted:string}>
	 */
	public static function lookup( string $code ): array {
		global $wpdb;
		$code = self::normalise( $code );
		if ( '' === $code ) {
			return array();
		}
		$table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';
		$found = array();
		$last  = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WooCommerce's own table, read only.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT permission_id, order_id, product_id, download_id, user_email, user_id, download_count, access_granted FROM {$table} WHERE permission_id > %d ORDER BY permission_id ASC LIMIT 2000", $last ), ARRAY_A );
			foreach ( (array) $rows as $r ) {
				$last = (int) $r['permission_id'];
				if ( hash_equals( $code, self::make( (int) $r['order_id'], (int) $r['product_id'], (string) $r['download_id'] ) ) ) {
					$found[] = array(
						'permission_id'  => (int) $r['permission_id'],
						'order_id'       => (int) $r['order_id'],
						'product_id'     => (int) $r['product_id'],
						'download_id'    => (string) $r['download_id'],
						'user_email'     => (string) $r['user_email'],
						'user_id'        => (int) $r['user_id'],
						'download_count' => (int) $r['download_count'],
						'access_granted' => (string) $r['access_granted'],
					);
				}
			}
		} while ( is_array( $rows ) && 2000 === count( $rows ) );
		return $found;
	}
}

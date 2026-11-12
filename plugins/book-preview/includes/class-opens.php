<?php
/**
 * BPV_Opens — how many times visitors opened a product's sample (1.2.0).
 *
 * Asked by Christos 2026-11-12 for the Revenue Splitter Author Portal
 * (design: notes/rs-portal-profile/01-sxediasmos.md, idea 8): creators see
 * how many readers opened the sample of their book.
 *
 *  - viewer.js sends one beacon per product and page view, the first time
 *    the sample opens (admin-ajax, action bpv_open, POST id). Nothing else
 *    is sent: no cookie, no browser storage, no IP or user agent stored.
 *  - Only a product with a visible sample counts, and never while the
 *    plugin is off, in test mode or killed (BPV_DISABLE). Opens by shop
 *    staff (manage_woocommerce) do not count.
 *  - Storage: one option, bpv_opens (autoload no), { product id: { Y-m-d:
 *    count } }, local days, the last 730 days kept. At most 120 counted
 *    opens per product per hour, so a script cannot inflate the numbers
 *    without limit.
 *  - Reading: bpv_get_opens( $product_id, $from, $to ) and
 *    bpv_get_opens_by_month( $product_id, $from, $to ) (Bible §6).
 */

defined( 'ABSPATH' ) || exit;

final class BPV_Opens {

	const OPT       = 'bpv_opens';
	const ACTION    = 'bpv_open';
	const KEEP_DAYS = 730;
	const CAP_HOUR  = 120;

	public static function init(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'ajax' ) );
	}

	/** The beacon. Public and nonce-free on purpose: pages may be cached, and a count is all it can change. */
	public static function ajax(): void {
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- public counter, see the docblock.
			$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
			if ( self::countable( $id ) ) {
				self::record( $id );
			}
		} catch ( \Throwable $e ) {
			// A counter never breaks anything.
			unset( $e );
		}
		wp_die( '', '', array( 'response' => 204 ) );
	}

	private static function countable( int $id ): bool {
		if ( $id <= 0 || Book_Preview::killed() ) {
			return false;
		}
		$s = BPV_Settings::get();
		if ( empty( $s['enabled'] ) || ! empty( $s['test_mode'] ) ) {
			return false;
		}
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}
		if ( 'product' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return false;
		}
		return BPV_Samples::visible( $id );
	}

	private static function record( int $id ): void {
		$cap = 'bpv_oc_' . $id;
		$n   = (int) get_transient( $cap );
		if ( $n >= self::CAP_HOUR ) {
			return;
		}
		set_transient( $cap, $n + 1, HOUR_IN_SECONDS );

		$day = wp_date( 'Y-m-d' );
		$all = self::all();
		$key = (string) $id;
		$row = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();

		$row[ $day ] = (int) ( $row[ $day ] ?? 0 ) + 1;

		$oldest = wp_date( 'Y-m-d', time() - self::KEEP_DAYS * DAY_IN_SECONDS );
		foreach ( array_keys( $row ) as $d ) {
			if ( (string) $d < $oldest ) {
				unset( $row[ $d ] );
			}
		}
		$all[ $key ] = $row;
		update_option( self::OPT, wp_json_encode( $all ), false );
	}

	/** @return array<string,array<string,int>> */
	private static function all(): array {
		$raw = json_decode( (string) get_option( self::OPT, '' ), true );
		return is_array( $raw ) ? $raw : array();
	}

	private static function valid_day( string $d ): bool {
		$o = DateTimeImmutable::createFromFormat( '!Y-m-d', $d );
		return $o && $o->format( 'Y-m-d' ) === $d;
	}

	/** @return array<string,int> Y-m-d => count, inside [from, to] ('' = open). */
	public static function days( int $id, string $from = '', string $to = '' ): array {
		$from = self::valid_day( $from ) ? $from : '';
		$to   = self::valid_day( $to ) ? $to : '';
		$all  = self::all();
		$row  = isset( $all[ (string) $id ] ) && is_array( $all[ (string) $id ] ) ? $all[ (string) $id ] : array();
		$out  = array();
		foreach ( $row as $d => $n ) {
			$d = (string) $d;
			if ( ( '' === $from || $d >= $from ) && ( '' === $to || $d <= $to ) ) {
				$out[ $d ] = (int) $n;
			}
		}
		ksort( $out );
		return $out;
	}

	public static function total( int $id, string $from = '', string $to = '' ): int {
		return (int) array_sum( self::days( $id, $from, $to ) );
	}

	/** @return array<string,int> Y-m => count. */
	public static function by_month( int $id, string $from = '', string $to = '' ): array {
		$out = array();
		foreach ( self::days( $id, $from, $to ) as $d => $n ) {
			$m         = substr( $d, 0, 7 );
			$out[ $m ] = ( $out[ $m ] ?? 0 ) + $n;
		}
		return $out;
	}
}

if ( ! function_exists( 'bpv_get_opens' ) ) {
	/**
	 * How many times the sample of a product was opened, between two local
	 * days (Y-m-d, both included; '' = no bound). Variations: pass the parent.
	 */
	function bpv_get_opens( int $product_id, string $from = '', string $to = '' ): int {
		return BPV_Opens::total( $product_id, $from, $to );
	}
}

if ( ! function_exists( 'bpv_get_opens_by_month' ) ) {
	/** The same, per month: [ 'Y-m' => count ], oldest first, months without opens left out. */
	function bpv_get_opens_by_month( int $product_id, string $from = '', string $to = '' ): array {
		return BPV_Opens::by_month( $product_id, $from, $to );
	}
}

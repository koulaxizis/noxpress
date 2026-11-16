<?php
/**
 * GFC_Limiter — attempt limits for the code field (approved E4).
 *
 *  - Every attempt is counted BEFORE the code is looked up (Bible §11:
 *    limits on public forms), per IP and per WooCommerce session / user:
 *    limit_n attempts in limit_min minutes. A successful attempt gives its
 *    count back, so a customer with a couple of cards never hits it.
 *  - The IP is REMOTE_ADDR, or CF-Connecting-IP only when REMOTE_ADDR is a
 *    Cloudflare address (Bible §11); headers such as X-Forwarded-For are
 *    never read. Only sha256(salt | ip) is stored.
 *  - Failed attempts of the whole site are counted per hour; past
 *    site_fail_hour the field closes for one hour for everyone who is not
 *    a shop manager, and the admin gets one email.
 *  - Counters live in gfc_limits (k, n, w). Increments are single UPDATE
 *    statements (atomic); a window that has passed starts again at 0.
 */

defined( 'ABSPATH' ) || exit;

final class GFC_Limiter {

	/** Cloudflare's published ranges (cloudflare.com/ips), as Magic Login. */
	const CF_RANGES = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
		'108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
		'162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
		'2a06:98c0::/29', '2c0f:f248::/32',
	);

	/** @var string[] Keys counted by the current attempt. */
	private static $counted = array();

	/**
	 * Counts one attempt and says whether it may go on.
	 *
	 * @return string '' when allowed, 'locked' when a limit was reached.
	 */
	public static function attempt(): string {
		$s      = GFC_Settings::get();
		$window = (int) $s['limit_min'] * MINUTE_IN_SECONDS;
		if ( self::site_closed() && ! current_user_can( 'manage_woocommerce' ) ) {
			return 'locked';
		}
		self::$counted = array( self::key( 'ip|' . self::client_ip() ), self::key( 'sess|' . self::session_id() ) );
		$over          = false;
		foreach ( self::$counted as $k ) {
			if ( self::hit( $k, $window ) > (int) $s['limit_n'] ) {
				$over = true;
			}
		}
		return $over ? 'locked' : '';
	}

	/** The attempt found a usable card: give its count back. */
	public static function success(): void {
		global $wpdb;
		foreach ( self::$counted as $k ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . GFC_Schema::limits() . ' SET n = n - 1 WHERE k = %s AND n > 0', $k ) );
		}
		self::$counted = array();
	}

	/** The attempt failed: counts it for the whole site. */
	public static function failure(): void {
		$s = GFC_Settings::get();
		$n = self::hit( self::key( 'site-fail' ), HOUR_IN_SECONDS );
		if ( $n === (int) $s['site_fail_hour'] + 1 ) {
			set_transient( 'gfc_site_closed', time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS );
			GFC_Emails::admin_alert(
				__( 'Gift Cards: το πεδίο κωδικού έκλεισε για μία ώρα', 'gift-cards' ),
				/* translators: %d: number of failed attempts */
				sprintf( __( 'Έγιναν πάνω από %d λάθος προσπάθειες κωδικού δωροκάρτας μέσα σε μία ώρα. Το πεδίο έκλεισε για μία ώρα για όλους εκτός από τους διαχειριστές του καταστήματος. Μπορεί να είναι κάποιος που δοκιμάζει κωδικούς.', 'gift-cards' ), (int) $s['site_fail_hour'] ),
				admin_url( 'admin.php?page=gfc-cards&tab=summary' )
			);
		}
		self::$counted = array();
	}

	public static function site_closed(): bool {
		$until = (int) get_transient( 'gfc_site_closed' );
		return $until > time();
	}

	/** Deletes old counters (daily job). */
	public static function cleanup(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . GFC_Schema::limits() . ' WHERE w < %d', time() - DAY_IN_SECONDS ) );
	}

	/** Increments a counter in its window and returns the new value. */
	private static function hit( string $k, int $window ): int {
		global $wpdb;
		$t   = GFC_Schema::limits();
		$now = time();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET n = 0, w = %d WHERE k = %s AND w < %d", $now, $k, $now - $window ) );
		$wpdb->suppress_errors( true );
		$wpdb->query( $wpdb->prepare( "INSERT INTO $t (k, n, w) VALUES (%s, 0, %d)", $k, $now ) );
		$wpdb->suppress_errors( false );
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET n = n + 1 WHERE k = %s", $k ) );
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT n FROM $t WHERE k = %s", $k ) );
		// phpcs:enable
		return $n;
	}

	private static function key( string $raw ): string {
		return hash( 'sha256', wp_salt( 'nonce' ) . '|gfc|' . $raw );
	}

	private static function session_id(): string {
		$uid = get_current_user_id();
		if ( $uid ) {
			return 'u' . $uid;
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			return 's' . (string) WC()->session->get_customer_id();
		}
		return 'none';
	}

	/**
	 * The visitor's address: REMOTE_ADDR, or CF-Connecting-IP only when the
	 * request really comes from Cloudflare.
	 */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' !== $remote && isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::in_ranges( $remote, self::CF_RANGES ) ) {
			$cf = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( false !== filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				return $cf;
			}
		}
		return $remote;
	}

	/** True when $ip is inside one of the CIDR ranges (IPv4 or IPv6). */
	public static function in_ranges( string $ip, array $ranges ): bool {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an invalid address is simply not in a range.
		if ( false === $bin ) {
			return false;
		}
		foreach ( $ranges as $cidr ) {
			list( $net, $bits ) = explode( '/', $cidr );
			$nbin               = @inet_pton( $net ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $nbin || strlen( $nbin ) !== strlen( $bin ) ) {
				continue;
			}
			$bits  = (int) $bits;
			$bytes = intdiv( $bits, 8 );
			if ( substr( $bin, 0, $bytes ) !== substr( $nbin, 0, $bytes ) ) {
				continue;
			}
			$rest = $bits % 8;
			if ( 0 === $rest ) {
				return true;
			}
			$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
			if ( ( ord( $bin[ $bytes ] ) & $mask ) === ( ord( $nbin[ $bytes ] ) & $mask ) ) {
				return true;
			}
		}
		return false;
	}
}

<?php
/**
 * ACL_Context — who did it and from where.
 *
 * Actor: the current user (id, display name and role at that moment, so a
 * row stays right after a rename or a role change). Customers are stored
 * as the token 'customer' and shown as «Πελάτης #id», never by name. No user: 'system' (cron, queue, CLI
 * without --user, a payment gateway calling back) or 'visitor'.
 *
 * Context, first match wins: site-bridge (between sbr_call_start and
 * sbr_call_end), cli, cron, queue (Action Scheduler), rest, ajax, admin,
 * checkout (WooCommerce checkout / Store API), front.
 *
 * IP: REMOTE_ADDR, or CF-Connecting-IP only when REMOTE_ADDR belongs to
 * Cloudflare (the same rule as Magic Login, Bible §11). Headers such as
 * X-Forwarded-For are never read: anyone can send them. Stored as the
 * setting says: mask (default), full or none.
 */

defined( 'ABSPATH' ) || exit;

final class ACL_Context {

	/** Cloudflare's published ranges (cloudflare.com/ips), for CF-Connecting-IP. */
	const CF_RANGES = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
		'108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
		'162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
		'2a06:98c0::/29', '2c0f:f248::/32',
	);

	/** @var array|null Site Bridge call in progress: array( tool, ref, call ). */
	private static $bridge = null;

	/** @var int Number of Site Bridge calls seen in this request. */
	private static $calls = 0;

	/** @var bool Inside an Action Scheduler run. */
	private static $queue = false;

	public static function init(): void {
		add_action( 'sbr_call_start', array( __CLASS__, 'bridge_start' ), 10, 3 );
		add_action( 'sbr_call_end', array( __CLASS__, 'bridge_end' ), 10, 3 );
		add_action( 'action_scheduler_before_execute', array( __CLASS__, 'queue_start' ), 10, 0 );
		add_action( 'action_scheduler_after_execute', array( __CLASS__, 'queue_end' ), 10, 0 );
		add_action( 'action_scheduler_failed_execution', array( __CLASS__, 'queue_end' ), 10, 0 );
	}

	/**
	 * Site Bridge is about to run a tool (Site Bridge 1.13.0+).
	 *
	 * @param mixed $tool   Tool name.
	 * @param mixed $client OAuth client name (not stored: the ref is enough).
	 * @param mixed $ref    Id of the Site Bridge audit log row, 0 if unknown
	 *                      (Site Bridge writes its row after the tool and
	 *                      passes the id to sbr_call_end instead).
	 */
	public static function bridge_start( $tool = '', $client = '', $ref = 0 ): void {
		unset( $client );
		self::$bridge = array(
			'tool' => substr( sanitize_key( (string) $tool ), 0, 64 ),
			'ref'  => max( 0, (int) $ref ),
			'call' => ++self::$calls,
		);
	}

	/**
	 * Site Bridge finished the tool and wrote its audit row.
	 *
	 * @param mixed $tool   Tool name.
	 * @param mixed $status ok | error | denied.
	 * @param mixed $log_id Id of the Site Bridge audit log row, 0 if unknown.
	 */
	public static function bridge_end( $tool = '', $status = '', $log_id = 0 ): void {
		unset( $tool, $status );
		if ( null !== self::$bridge && (int) $log_id > 0 ) {
			ACL_Store::stamp( (int) self::$bridge['call'], 'sbr:' . (int) $log_id );
		}
		self::$bridge = null;
	}

	/** Number of the Site Bridge call in progress, 0 outside one. */
	public static function call(): int {
		return null !== self::$bridge ? (int) self::$bridge['call'] : 0;
	}

	public static function queue_start(): void {
		self::$queue = true;
	}

	public static function queue_end(): void {
		self::$queue = false;
	}

	/** 'sbr:<audit row id>' while Site Bridge runs a tool, else ''. */
	public static function ref(): string {
		return null !== self::$bridge && self::$bridge['ref'] > 0 ? 'sbr:' . self::$bridge['ref'] : '';
	}

	public static function context(): string {
		if ( null !== self::$bridge ) {
			return 'site-bridge';
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( self::$queue ) {
			return 'queue';
		}
		if ( wp_doing_cron() ) {
			return 'cron';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
			if ( false !== strpos( $uri, '/wc/store/' ) && false !== strpos( $uri, 'checkout' ) ) {
				return 'checkout';
			}
			return 'rest';
		}
		if ( wp_doing_ajax() ) {
			return 'ajax';
		}
		if ( is_admin() ) {
			return 'admin';
		}
		if ( function_exists( 'is_checkout' ) && did_action( 'wp' ) && is_checkout() ) {
			return 'checkout';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- compared only.
		if ( isset( $_GET['wc-ajax'] ) && 'checkout' === $_GET['wc-ajax'] ) {
			return 'checkout';
		}
		return 'front';
	}

	/** True for a request started by a person at the admin side. */
	public static function by_staff_tools(): bool {
		return in_array( self::context(), array( 'admin', 'ajax', 'rest', 'cli', 'site-bridge' ), true ) && self::is_staff( get_current_user_id() );
	}

	/** Staff = can edit content or run the shop. Customers and subscribers are not. */
	public static function is_staff( int $uid ): bool {
		if ( $uid <= 0 ) {
			return false;
		}
		return user_can( $uid, 'edit_posts' ) || user_can( $uid, 'manage_woocommerce' ) || user_can( $uid, 'manage_options' );
	}

	/**
	 * The actor of an event: array( id, label, role ). $uid overrides the
	 * current user (e.g. a login, where the user is not current yet).
	 */
	public static function actor( ?int $uid = null ): array {
		$uid = null === $uid ? get_current_user_id() : $uid;
		if ( $uid > 0 ) {
			$u = get_userdata( $uid );
			if ( $u instanceof WP_User ) {
				$role = $u->roles ? (string) reset( $u->roles ) : '';
				if ( ! self::is_staff( $uid ) ) {
					return array( $uid, 'customer', $role ); // Never the name: shown as «Πελάτης #id».
				}
				return array( $uid, (string) $u->display_name, $role );
			}
		}
		$ctx = self::context();
		if ( in_array( $ctx, array( 'front', 'checkout' ), true ) ) {
			return array( 0, 'visitor', '' );
		}
		return array( 0, 'system', '' );
	}

	/** The visitor's address, '' when none can be trusted. */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( false === filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::in_ranges( $remote, self::CF_RANGES ) ) {
			$cf = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( false !== filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				return $cf;
			}
		}
		return $remote;
	}

	/** The address to store, as the setting says. CLI and cron have none. */
	public static function stored_ip(): string {
		if ( in_array( self::context(), array( 'cli', 'cron', 'queue' ), true ) ) {
			return '';
		}
		$mode = ACL_Settings::get()['ip'];
		if ( 'none' === $mode ) {
			return '';
		}
		$ip = self::client_ip();
		return 'full' === $mode ? $ip : self::mask_ip( $ip );
	}

	/** IPv4 → a.b.c.0, IPv6 → first 48 bits (…::). */
	public static function mask_ip( string $ip ): string {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- invalid input gives ''.
		if ( false === $bin ) {
			return '';
		}
		if ( 4 === strlen( $bin ) ) {
			$bin[3] = "\0";
		} else {
			$bin = substr( $bin, 0, 6 ) . str_repeat( "\0", 10 );
		}
		$out = inet_ntop( $bin );
		return false === $out ? '' : $out;
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

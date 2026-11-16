<?php
/**
 * MMD_Access — who sees the site while it is closed.
 *
 *  - Logged-in users with a chosen role (and always manage_options, so an
 *    administrator never locks themselves out).
 *  - Visitors from an address of the IP list (exact or CIDR), read only
 *    from REMOTE_ADDR, or CF-Connecting-IP when REMOTE_ADDR is Cloudflare
 *    (Bible §11). Never X-Forwarded-For: anyone can send it.
 *  - Visitors with a preview link: ?mmd-preview=<id>.<token>. The token is
 *    an HMAC of the link id and time with the site salts, so nothing
 *    secret is stored; revoking deletes the id. A valid link sets the
 *    cookie mmd_preview (<id>.<expires>.<hmac>, HttpOnly, SameSite=Lax,
 *    Secure on https) until the link expires, then redirects to the same
 *    address without the token. No database write on a visitor request.
 *
 * Links: option mmd_links (autoload off; read only when the cookie or the
 * parameter is there): list of { id, label, created, expires }.
 */

defined( 'ABSPATH' ) || exit;

final class MMD_Access {

	const OPT       = 'mmd_links';
	const COOKIE    = 'mmd_preview';
	const PARAM     = 'mmd-preview';
	const MAX_LINKS = 20;

	const CF_RANGES = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
		'108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
		'162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
		'2a06:98c0::/29', '2c0f:f248::/32',
	);

	/** @var bool|null Per-request answer of passes(). */
	private static $passes = null;

	/** Filter noxpress_private_cookies (§14.34). */
	public static function private_cookie( $names ): array {
		$names   = is_array( $names ) ? $names : array();
		$names[] = self::COOKIE;
		return $names;
	}

	/** Does the current request see the normal site? */
	public static function passes(): bool {
		if ( null === self::$passes ) {
			self::$passes = self::role_passes() || self::ip_passes() || self::cookie_passes();
		}
		return self::$passes;
	}

	public static function reset(): void {
		self::$passes = null;
	}

	/** Logged in with manage_options or one of the chosen roles. */
	public static function role_passes(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( current_user_can( Maintenance_Mode::CAP ) ) {
			return true;
		}
		$user  = wp_get_current_user();
		$roles = MMD_Settings::get()['roles'];
		return (bool) array_intersect( (array) $user->roles, $roles );
	}

	/* ---------------------------------------------------------------------
	 * IP list
	 * ------------------------------------------------------------------- */

	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' !== $remote && isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::in_ranges( $remote, self::CF_RANGES ) ) {
			$cf = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( false !== filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				return $cf;
			}
		}
		return false !== filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
	}

	public static function ip_passes(): bool {
		$list = MMD_Settings::get()['ips'];
		if ( ! $list ) {
			return false;
		}
		$ip = self::client_ip();
		return '' !== $ip && self::in_ranges( $ip, $list );
	}

	/** An address or a CIDR range (IPv4 / IPv6) we can compare. */
	public static function valid_range( string $s ): bool {
		$parts = explode( '/', $s );
		if ( count( $parts ) > 2 || false === filter_var( $parts[0], FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( 2 === count( $parts ) ) {
			$max = false !== strpos( $parts[0], ':' ) ? 128 : 32;
			return ctype_digit( $parts[1] ) && (int) $parts[1] >= 8 && (int) $parts[1] <= $max;
		}
		return true;
	}

	/** True when $ip equals or is inside one of $ranges. */
	public static function in_ranges( string $ip, array $ranges ): bool {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an invalid address is simply not in a range.
		if ( false === $bin ) {
			return false;
		}
		foreach ( $ranges as $range ) {
			$parts = explode( '/', (string) $range );
			$nbin  = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $nbin || strlen( $nbin ) !== strlen( $bin ) ) {
				continue;
			}
			$bits  = isset( $parts[1] ) ? (int) $parts[1] : strlen( $bin ) * 8;
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

	/* ---------------------------------------------------------------------
	 * Preview links
	 * ------------------------------------------------------------------- */

	public static function links(): array {
		$raw = get_option( self::OPT, array() );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $l ) {
			if ( is_array( $l ) && isset( $l['id'], $l['expires'], $l['created'] ) && preg_match( '/^[a-f0-9]{12}$/', (string) $l['id'] ) ) {
				$out[ (string) $l['id'] ] = array(
					'id'      => (string) $l['id'],
					'label'   => isset( $l['label'] ) ? (string) $l['label'] : '',
					'created' => (int) $l['created'],
					'expires' => (int) $l['expires'],
				);
			}
		}
		return $out;
	}

	/** @return array|WP_Error The new link. */
	public static function create( string $label, int $days ) {
		$links = self::links();
		if ( count( $links ) >= self::MAX_LINKS ) {
			return new WP_Error( 'mmd_links_full', __( 'Έως 20 σύνδεσμοι. Ανακάλεσε κάποιον πρώτα.', 'maintenance-mode' ) );
		}
		$days          = min( 90, max( 1, $days ) );
		$id            = bin2hex( random_bytes( 6 ) );
		$link          = array(
			'id'      => $id,
			'label'   => substr( sanitize_text_field( $label ), 0, 60 ),
			'created' => time(),
			'expires' => time() + $days * DAY_IN_SECONDS,
		);
		$links[ $id ] = $link;
		update_option( self::OPT, array_values( $links ), false );
		return $link;
	}

	public static function revoke( string $id ): bool {
		$links = self::links();
		if ( ! isset( $links[ $id ] ) ) {
			return false;
		}
		unset( $links[ $id ] );
		update_option( self::OPT, array_values( $links ), false );
		return true;
	}

	/** Expired links leave the list (admin requests only). */
	public static function prune(): void {
		$links = self::links();
		$keep  = array_filter(
			$links,
			static function ( $l ) {
				return $l['expires'] > time() - 30 * DAY_IN_SECONDS;
			}
		);
		if ( count( $keep ) !== count( $links ) ) {
			update_option( self::OPT, array_values( $keep ), false );
		}
	}

	private static function token( array $l ): string {
		return substr( hash_hmac( 'sha256', 'mmd-link|' . $l['id'] . '|' . $l['created'] . '|' . $l['expires'], wp_salt( 'auth' ) ), 0, 32 );
	}

	private static function cookie_sig( string $id, int $exp ): string {
		return substr( hash_hmac( 'sha256', 'mmd-cookie|' . $id . '|' . $exp, wp_salt( 'secure_auth' ) ), 0, 32 );
	}

	public static function url( array $l ): string {
		return add_query_arg( self::PARAM, $l['id'] . '.' . self::token( $l ), home_url( '/' ) );
	}

	/** A link from «id.token», or null when it is unknown, wrong or expired. */
	public static function from_param( string $value ): ?array {
		if ( ! preg_match( '/^([a-f0-9]{12})\.([a-f0-9]{32})$/', $value, $m ) ) {
			return null;
		}
		$links = self::links();
		if ( ! isset( $links[ $m[1] ] ) ) {
			return null;
		}
		$l = $links[ $m[1] ];
		if ( $l['expires'] <= time() || ! hash_equals( self::token( $l ), $m[2] ) ) {
			return null;
		}
		return $l;
	}

	public static function cookie_passes(): bool {
		if ( empty( $_COOKIE[ self::COOKIE ] ) || ! is_string( $_COOKIE[ self::COOKIE ] ) ) {
			return false;
		}
		$v = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		if ( ! preg_match( '/^([a-f0-9]{12})\.(\d{9,11})\.([a-f0-9]{32})$/', $v, $m ) ) {
			return false;
		}
		$exp = (int) $m[2];
		if ( $exp <= time() || ! hash_equals( self::cookie_sig( $m[1], $exp ), $m[3] ) ) {
			return false;
		}
		$links = self::links();
		return isset( $links[ $m[1] ] ) && $links[ $m[1] ]['expires'] === $exp;
	}

	/**
	 * ?mmd-preview=…: a valid link sets the cookie and redirects to the
	 * same address without the parameter (no token in history or
	 * Referer). A wrong or expired link only drops the parameter.
	 */
	public static function accept_param(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the signed token is the check.
		if ( ! isset( $_GET[ self::PARAM ] ) || ! is_string( $_GET[ self::PARAM ] ) ) {
			return;
		}
		$l = self::from_param( sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $l && ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$l['id'] . '.' . $l['expires'] . '.' . self::cookie_sig( $l['id'], $l['expires'] ),
				array(
					'expires'  => $l['expires'],
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		wp_safe_redirect( remove_query_arg( self::PARAM ), 302, 'Maintenance Mode' );
		exit;
	}
}

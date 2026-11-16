<?php
/**
 * MMD_Settings — the mmd_settings option and its strict validation
 * (Bible §11: whitelists; unknown keys dropped, bad values → defaults).
 *
 * Keys:
 *  - on          bool    the switch (default off, §14.1)
 *  - mode        string  'maintenance' (503 + Retry-After) | 'coming_soon'
 *                        (200 + noindex, robots.txt Disallow, feeds closed)
 *  - start, end  int     unix times, 0 = none (site time zone in the form)
 *  - retry       int     Retry-After hours when there is no end (1–72)
 *  - roles       string[] logged-in roles that see the site
 *  - ips         string[] IPv4 / IPv6 addresses or CIDR ranges (up to 50)
 *  - rest_close  bool    wp/v2 REST routes closed to visitors who are not
 *                        logged in (default off)
 *  - lang        string  'auto' (site locale) | 'el' | 'en' (§14.17)
 *  - title_el / title_en / text_el / text_en  visitor texts; empty = the
 *                        default text of the mode (text: wp_kses_post)
 *  - logo, bg_image int  attachment ids (0 = site icon / none)
 *  - bg, fg, accent string #rrggbb
 *  - countdown   bool    count down to the end time
 *  - email, phone string contact lines; links string[] «label | URL» (6)
 *
 * Autoloaded: read on every front-end request.
 */

defined( 'ABSPATH' ) || exit;

final class MMD_Settings {

	const OPT       = 'mmd_settings';
	const MAX_TEXT  = 2000;
	const MAX_TITLE = 120;
	const MAX_IPS   = 50;
	const MAX_LINKS = 6;

	/** @var array|null Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'on'         => false,
			'mode'       => 'maintenance',
			'start'      => 0,
			'end'        => 0,
			'retry'      => 1,
			'roles'      => array( 'administrator', 'shop_manager' ),
			'ips'        => array(),
			'rest_close' => false,
			'lang'       => 'auto',
			'title_el'   => '',
			'title_en'   => '',
			'text_el'    => '',
			'text_en'    => '',
			'logo'       => 0,
			'bg_image'   => 0,
			'bg'         => '#14161a',
			'fg'         => '#f3f4f6',
			'accent'     => '#d4a24c',
			'countdown'  => false,
			'email'      => '',
			'phone'      => '',
			'links'      => array(),
		);
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPT, array() );
			self::$cache = self::clean( is_array( $raw ) ? $raw : array() );
		}
		return self::$cache;
	}

	public static function save( array $in ): array {
		$s = self::clean( $in );
		update_option( self::OPT, $s, true );
		self::$cache = $s;
		return $s;
	}

	public static function reset_cache(): void {
		self::$cache = null;
	}

	/** Validate every key; unknown keys are dropped. */
	public static function clean( array $in ): array {
		$d = self::defaults();
		$s = array();

		$s['on']         = ! empty( $in['on'] );
		$s['mode']       = isset( $in['mode'] ) && 'coming_soon' === $in['mode'] ? 'coming_soon' : 'maintenance';
		$s['start']      = isset( $in['start'] ) ? max( 0, (int) $in['start'] ) : 0;
		$s['end']        = isset( $in['end'] ) ? max( 0, (int) $in['end'] ) : 0;
		$s['retry']      = isset( $in['retry'] ) ? min( 72, max( 1, (int) $in['retry'] ) ) : $d['retry'];
		$s['rest_close'] = ! empty( $in['rest_close'] );
		$s['countdown']  = ! empty( $in['countdown'] );
		$s['lang']       = isset( $in['lang'] ) && in_array( $in['lang'], array( 'auto', 'el', 'en' ), true ) ? $in['lang'] : 'auto';

		// Roles: only roles that exist on this site (when roles are known).
		$roles = isset( $in['roles'] ) && is_array( $in['roles'] ) ? array_map( 'sanitize_key', array_map( 'strval', $in['roles'] ) ) : $d['roles'];
		$known = function_exists( 'wp_roles' ) && did_action( 'init' ) ? array_keys( wp_roles()->get_names() ) : array();
		if ( $known ) {
			$roles = array_values( array_intersect( $roles, $known ) );
		}
		$s['roles'] = array_values( array_unique( array_filter( $roles ) ) );

		$ips = isset( $in['ips'] ) ? $in['ips'] : array();
		if ( is_string( $ips ) ) {
			$ips = preg_split( '/[\s,]+/', $ips );
		}
		$s['ips'] = array();
		foreach ( (array) $ips as $ip ) {
			$ip = trim( (string) $ip );
			if ( '' !== $ip && MMD_Access::valid_range( $ip ) && ! in_array( $ip, $s['ips'], true ) ) {
				$s['ips'][] = $ip;
			}
			if ( count( $s['ips'] ) >= self::MAX_IPS ) {
				break;
			}
		}

		foreach ( array( 'title_el', 'title_en' ) as $k ) {
			$v       = isset( $in[ $k ] ) ? sanitize_text_field( (string) $in[ $k ] ) : '';
			$s[ $k ] = function_exists( 'mb_substr' ) ? mb_substr( $v, 0, self::MAX_TITLE ) : substr( $v, 0, self::MAX_TITLE );
		}
		foreach ( array( 'text_el', 'text_en' ) as $k ) {
			$v       = isset( $in[ $k ] ) ? trim( wp_kses_post( (string) $in[ $k ] ) ) : '';
			$s[ $k ] = strlen( $v ) <= self::MAX_TEXT ? $v : '';
		}

		foreach ( array( 'logo', 'bg_image' ) as $k ) {
			$id      = isset( $in[ $k ] ) ? (int) $in[ $k ] : 0;
			$s[ $k ] = $id > 0 && ( ! function_exists( 'wp_attachment_is_image' ) || wp_attachment_is_image( $id ) ) ? $id : 0;
		}
		foreach ( array( 'bg', 'fg', 'accent' ) as $k ) {
			$c       = isset( $in[ $k ] ) ? sanitize_hex_color( (string) $in[ $k ] ) : '';
			$s[ $k ] = is_string( $c ) && 7 === strlen( $c ) ? strtolower( $c ) : $d[ $k ];
		}

		$email      = isset( $in['email'] ) ? sanitize_email( (string) $in['email'] ) : '';
		$s['email'] = is_email( $email ) ? $email : '';
		$phone      = isset( $in['phone'] ) ? (string) $in['phone'] : '';
		$s['phone'] = substr( (string) preg_replace( '/[^0-9+()\-\s.]/', '', $phone ), 0, 40 );

		$links = isset( $in['links'] ) ? $in['links'] : array();
		if ( is_string( $links ) ) {
			$links = preg_split( '/\r\n|\r|\n/', $links );
		}
		$s['links'] = array();
		foreach ( (array) $links as $line ) {
			$parts = array_map( 'trim', explode( '|', (string) $line, 2 ) );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$label = substr( sanitize_text_field( $parts[0] ), 0, 40 );
			$url   = esc_url_raw( $parts[1], array( 'http', 'https', 'mailto', 'tel' ) );
			if ( '' !== $label && '' !== $url ) {
				$s['links'][] = $label . ' | ' . $url;
			}
			if ( count( $s['links'] ) >= self::MAX_LINKS ) {
				break;
			}
		}
		return $s;
	}

	/**
	 * State at a moment: off | scheduled | on | ended.
	 *
	 * @param array $s   Settings.
	 * @param int   $now Unix time.
	 */
	public static function state( array $s, int $now ): string {
		if ( empty( $s['on'] ) ) {
			return 'off';
		}
		if ( $s['start'] > 0 && $s['start'] > $now ) {
			return 'scheduled';
		}
		if ( $s['end'] > 0 && $s['end'] <= $now ) {
			return 'ended';
		}
		return 'on';
	}

	/** What the page cache was last purged for: changes at a start / end. */
	public static function fingerprint(): string {
		$s = self::get();
		return md5( self::state( $s, time() ) . '|' . $s['mode'] . '|' . $s['start'] . '|' . $s['end'] );
	}

	/** Retry-After seconds: until the end, else the hours setting. */
	public static function retry_after( array $s, int $now ): int {
		if ( $s['end'] > $now ) {
			return max( 60, $s['end'] - $now );
		}
		return (int) $s['retry'] * HOUR_IN_SECONDS;
	}
}

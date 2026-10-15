<?php
/**
 * SBR_Settings — the sbr_settings option and the write window.
 *
 * Every value is validated against a whitelist on save; get() merges the
 * stored array over the defaults, so a missing or broken option always
 * reads as the safest setting (roles off, level read, masks on).
 */

defined( 'ABSPATH' ) || exit;

final class SBR_Settings {

	const OPT    = 'sbr_settings';
	const WINDOW = 'sbr_window_until';

	const LEVELS = array( 'off', 'read', 'write', 'admin' );

	/** Write window lengths offered in the admin, in minutes. */
	const WINDOW_MINUTES = array( 15, 30, 60 );

	/** Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'hub'           => false,
			'site'          => false,
			'level'         => 'read',
			'remote_window' => false,
			'run_as'        => 0,
			'pii_mask'      => true,
			'email_admin'   => true,
			'site_ips'      => '',
			'hub_ips'       => '',
			'dcr'           => false,
			'clients'       => "https://claude.ai/\nhttps://claude.com/",
			'rate_min'      => 120,
			'rate_day'      => 10000,
			'zip_hosts'     => "https://github.com/koulaxizis/\nhttps://downloads.wordpress.org/",
			'log_days'      => 90,
		);
	}

	public static function get(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$raw = get_option( self::OPT, array() );
		$s   = self::sanitize( is_array( $raw ) ? $raw : array() );
		self::$cache = $s;
		return $s;
	}

	/** One value. */
	public static function v( string $key ) {
		$s = self::get();
		return $s[ $key ] ?? null;
	}

	public static function save( array $s ): void {
		$s = self::sanitize( $s );
		update_option( self::OPT, $s, true );
		self::$cache = null;
	}

	/** Whitelist validation of every key; unknown keys are dropped. */
	public static function sanitize( array $in ): array {
		$d   = self::defaults();
		$out = array();
		foreach ( array( 'hub', 'site', 'remote_window', 'pii_mask', 'email_admin', 'dcr' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) ? (bool) $in[ $k ] : $d[ $k ];
		}
		$out['level']  = isset( $in['level'] ) && in_array( $in['level'], self::LEVELS, true ) ? $in['level'] : $d['level'];
		$out['run_as'] = isset( $in['run_as'] ) ? max( 0, (int) $in['run_as'] ) : 0;
		foreach ( array( 'site_ips', 'hub_ips' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) ? self::clean_lines( (string) $in[ $k ], array( 'SBR_Guard', 'valid_cidr' ) ) : '';
		}
		$out['clients']   = isset( $in['clients'] ) ? self::clean_lines( (string) $in['clients'], array( __CLASS__, 'valid_prefix' ) ) : $d['clients'];
		$out['zip_hosts'] = isset( $in['zip_hosts'] ) ? self::clean_lines( (string) $in['zip_hosts'], array( __CLASS__, 'valid_prefix' ) ) : $d['zip_hosts'];
		$out['rate_min']  = isset( $in['rate_min'] ) ? min( 2000, max( 1, (int) $in['rate_min'] ) ) : $d['rate_min'];
		$out['rate_day']  = isset( $in['rate_day'] ) ? min( 1000000, max( 1, (int) $in['rate_day'] ) ) : $d['rate_day'];
		$out['log_days']  = isset( $in['log_days'] ) ? min( 3650, max( 1, (int) $in['log_days'] ) ) : $d['log_days'];
		return $out;
	}

	/** Keeps the lines that pass $check, trimmed, unique. */
	private static function clean_lines( string $text, callable $check ): string {
		$keep = array();
		foreach ( preg_split( '/[\r\n,]+/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line && call_user_func( $check, $line ) ) {
				$keep[ $line ] = true;
			}
		}
		return implode( "\n", array_keys( $keep ) );
	}

	/** An https URL prefix with a host and a path ending in "/". */
	public static function valid_prefix( string $p ): bool {
		$parts = wp_parse_url( $p );
		return is_array( $parts ) && 'https' === ( $parts['scheme'] ?? '' ) && ! empty( $parts['host'] )
			&& '/' === substr( $p, -1 ) && ! isset( $parts['query'] ) && ! isset( $parts['fragment'] ) && ! isset( $parts['user'] );
	}

	/** Lines of a multi-line setting. */
	public static function lines( string $key ): array {
		$v = (string) self::v( $key );
		return '' === $v ? array() : explode( "\n", $v );
	}

	/** First activation: the activating admin becomes the "run as" user. */
	public static function first_run(): void {
		if ( false !== get_option( self::OPT, false ) ) {
			return;
		}
		$s           = self::defaults();
		$s['run_as'] = current_user_can( 'manage_options' ) ? get_current_user_id() : 0;
		if ( ! $s['run_as'] ) { // Activated by WP-CLI or a script: the first administrator.
			$admins      = get_users(
				array(
					'role'    => 'administrator',
					'number'  => 1,
					'orderby' => 'ID',
					'fields'  => 'ID',
				)
			);
			$s['run_as'] = $admins ? (int) $admins[0] : 0;
		}
		self::save( $s );
	}

	/* =====================================================================
	 * Write window
	 * =================================================================== */

	/** Unix time the window closes, 0 when closed. */
	public static function window_until(): int {
		$t = (int) get_option( self::WINDOW, 0 );
		return $t > time() ? $t : 0;
	}

	public static function open_window( int $minutes ): int {
		$minutes = in_array( $minutes, self::WINDOW_MINUTES, true ) ? $minutes : 30;
		$until   = time() + 60 * $minutes;
		update_option( self::WINDOW, $until, false );
		return $until;
	}

	public static function close_window(): void {
		update_option( self::WINDOW, 0, false );
	}

	/** The user tools run as: a valid administrator, or null. */
	public static function run_as_user(): ?WP_User {
		$id = (int) self::v( 'run_as' );
		if ( $id <= 0 ) {
			return null;
		}
		$u = get_userdata( $id );
		return ( $u instanceof WP_User && user_can( $u, 'manage_options' ) ) ? $u : null;
	}
}

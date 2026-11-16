<?php
/**
 * ACL_Settings — the acl_settings option and its strict validation
 * (Bible §11, whitelists; unknown keys dropped, bad values → defaults).
 *
 * Keys:
 *  - cats    array<string,bool> categories on / off (see categories())
 *  - ip      string 'mask' (default: IPv4 without the last byte, IPv6 /48)
 *                   | 'full' | 'none'
 *  - days    int    days of history kept (1–730, default 90)
 *  - max     int    most rows kept (10 000–2 000 000, default 200 000)
 *  - notify  bool   e-mail for critical events (default on)
 *  - to      string where that e-mail goes ('' = the site's admin e-mail)
 *  - extra   string[] more option names to watch (up to 50)
 *
 * Autoloaded: read on every request that records something.
 */

defined( 'ABSPATH' ) || exit;

final class ACL_Settings {

	const OPT = 'acl_settings';

	/** @var array|null Per-request cache. */
	private static $cache = null;

	/**
	 * Category key => array( label, on by default ). Labels are msgids
	 * (Greek); the admin translates them on output.
	 */
	public static function categories(): array {
		$c = array(
			'products'        => array( 'Προϊόντα', true ),
			'stock_orders'    => array( 'Απόθεμα που αλλάζει από παραγγελίες', false ),
			'orders'          => array( 'Παραγγελίες (κατάσταση, επιστροφές, αλλαγές από το admin)', true ),
			'orders_new'      => array( 'Νέες παραγγελίες από το ταμείο', false ),
			'coupons'         => array( 'Κουπόνια', true ),
			'content'         => array( 'Σελίδες και άρθρα', true ),
			'users'           => array( 'Χρήστες και συνδέσεις προσωπικού', true ),
			'customer_logins' => array( 'Συνδέσεις πελατών', false ),
			'plugins'         => array( 'Plugins, θέματα και πυρήνας', true ),
			'settings'        => array( 'Ρυθμίσεις', true ),
			'media'           => array( 'Αρχεία πολυμέσων', true ),
			'suite'           => array( 'Σουίτα Noxpress', true ),
			'menus'           => array( 'Μενού', false ),
		);
		$f = apply_filters( 'acl_categories', $c );
		return is_array( $f ) ? $f : $c;
	}

	public static function defaults(): array {
		$cats = array();
		foreach ( self::categories() as $k => $c ) {
			$cats[ $k ] = (bool) $c[1];
		}
		return array(
			'cats'   => $cats,
			'ip'     => 'mask',
			'days'   => 90,
			'max'    => 200000,
			'notify' => true,
			'to'     => '',
			'extra'  => array(),
		);
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPT, array() );
			self::$cache = self::sanitize( is_array( $raw ) ? $raw : array() );
		}
		return self::$cache;
	}

	public static function save( array $raw ): void {
		$clean = self::sanitize( $raw );
		update_option( self::OPT, $clean, true );
		self::$cache = $clean;
	}

	public static function sanitize( array $raw ): array {
		$d = self::defaults();
		$s = array();

		$s['cats'] = array();
		$in        = isset( $raw['cats'] ) && is_array( $raw['cats'] ) ? $raw['cats'] : array();
		foreach ( $d['cats'] as $k => $def ) {
			$s['cats'][ $k ] = array_key_exists( $k, $in ) ? (bool) $in[ $k ] : $def;
		}

		$s['ip']     = isset( $raw['ip'] ) && in_array( $raw['ip'], array( 'mask', 'full', 'none' ), true ) ? $raw['ip'] : $d['ip'];
		$s['days']   = self::range( $raw, 'days', 1, 730, $d['days'] );
		$s['max']    = self::range( $raw, 'max', 10000, 2000000, $d['max'] );
		$s['notify'] = isset( $raw['notify'] ) ? (bool) $raw['notify'] : $d['notify'];

		$to      = isset( $raw['to'] ) && is_string( $raw['to'] ) ? sanitize_email( $raw['to'] ) : '';
		$s['to'] = is_email( $to ) ? $to : '';

		$e = isset( $raw['extra'] ) ? $raw['extra'] : array();
		if ( is_string( $e ) ) {
			$e = preg_split( '/[\s,]+/', $e );
		}
		$s['extra'] = array();
		foreach ( (array) $e as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			$name = trim( $name );
			if ( preg_match( '/^[A-Za-z0-9_\-]{1,191}$/', $name ) && ! ACL_Redact::is_secret( $name ) && ! in_array( $name, $s['extra'], true ) && count( $s['extra'] ) < 50 ) {
				$s['extra'][] = $name;
			}
		}
		return $s;
	}

	/** An integer setting kept inside its range. */
	private static function range( array $raw, string $key, int $min, int $max, int $def ): int {
		$v = isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ? (int) $raw[ $key ] : $def;
		return max( $min, min( $max, $v ) );
	}

	/** True when a category records. */
	public static function on( string $cat ): bool {
		$s = self::get();
		return ! empty( $s['cats'][ $cat ] );
	}
}

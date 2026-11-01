<?php
/**
 * RDR_Settings — the rdr_settings option and its strict validation
 * (Bible §11, whitelists; unknown keys dropped, bad values → defaults).
 *
 * Keys:
 *  - mode       string 'test' (default: rules answer only manage_options)
 *                      | 'live'
 *  - query      bool   pass the query string of the old address to the
 *                      target when the target has none (default on)
 *  - log        bool   write the 404 log (default on)
 *  - bots       bool   do not write known scanner paths (default on)
 *  - patterns   string[] scanner paths, * as wildcard (up to 100)
 *  - months     int    months of log files kept (1–12, default 3)
 *  - max_mb     int    MB per monthly log file (1–50, default 5)
 *  - per_min    int    log lines a minute (10–600, default 60)
 *  - staged     bool   a new rule starts as 302 until «Make it 301»
 *                      (default on; 7 days later the page suggests it)
 *  - keep       bool   keep rules and log on uninstall (default off)
 *
 * Autoloaded: read on every 404.
 */

defined( 'ABSPATH' ) || exit;

final class RDR_Settings {

	const OPT = 'rdr_settings';

	/** Days a staged rule stays 302 before the page suggests 301. */
	const STAGE_DAYS = 7;

	/** @var array|null Per-request cache. */
	private static $cache = null;

	/** Paths of vulnerability scanners: never written to the log. */
	public static function default_patterns(): array {
		return array(
			'.well-known/*',
			'.env*',
			'.git*',
			'*.php',
			'*.asp*',
			'*.jsp',
			'*.cgi',
			'cgi-bin/*',
			'wp-config*',
			'*/wp-config*',
			'xmlrpc*',
			'graphql*',
			'*/graphql*',
			'api/*',
			'actuator*',
			'server-status*',
			'server',
			'console',
			'shell',
			'webshell',
			'*livewire*',
			'@vite*',
			'_next*',
			'telescope*',
			'debug/*',
			'ecp/*',
			'owa/*',
			'v2/_catalog',
			'ipfs/*',
			'___proxy*',
			'*.sql*',
			'*.bak',
			'*.zip',
			'*.tar*',
			'*.ini',
			'*.log',
			'*.yml',
			'*.yaml',
			'config.json',
			'trace.axd',
			'wp-content/plugins/*',
			'wp-content/themes/*',
			'wp-includes/*',
			'*/meta-inf/*',
			'404test*',
			'404javascript*',
		);
	}

	public static function defaults(): array {
		return array(
			'mode'     => 'test',
			'query'    => true,
			'log'      => true,
			'bots'     => true,
			'patterns' => self::default_patterns(),
			'months'   => 3,
			'max_mb'   => 5,
			'per_min'  => 60,
			'staged'   => true,
			'keep'     => false,
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

		$s['mode'] = isset( $raw['mode'] ) && in_array( $raw['mode'], array( 'test', 'live' ), true ) ? $raw['mode'] : $d['mode'];
		foreach ( array( 'query', 'log', 'bots', 'staged', 'keep' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}
		$s['months']  = self::range( $raw, 'months', 1, 12, $d['months'] );
		$s['max_mb']  = self::range( $raw, 'max_mb', 1, 50, $d['max_mb'] );
		$s['per_min'] = self::range( $raw, 'per_min', 10, 600, $d['per_min'] );

		$p = isset( $raw['patterns'] ) ? $raw['patterns'] : $d['patterns'];
		if ( is_string( $p ) ) {
			$p = preg_split( '/[\r\n]+/', $p );
		}
		$s['patterns'] = array();
		foreach ( (array) $p as $line ) {
			if ( ! is_string( $line ) ) {
				continue;
			}
			$line = strtolower( trim( sanitize_text_field( $line ) ) );
			$line = ltrim( $line, '/' );
			if ( '' !== $line && '*' !== $line && strlen( $line ) <= 100 && ! in_array( $line, $s['patterns'], true ) && count( $s['patterns'] ) < 100 ) {
				$s['patterns'][] = $line;
			}
		}
		return $s;
	}

	/** An integer setting kept inside its range. */
	private static function range( array $raw, string $key, int $min, int $max, int $def ): int {
		$v = isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ? (int) $raw[ $key ] : $def;
		return max( $min, min( $max, $v ) );
	}

	public static function live(): bool {
		return 'live' === self::get()['mode'];
	}
}

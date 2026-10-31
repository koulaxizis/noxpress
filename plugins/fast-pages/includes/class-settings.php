<?php
/**
 * FPG_Settings — the fpg_settings option, strict validation (Bible §11).
 *
 *  enabled         page cache on (off after activation, Bible §14.1)
 *  test            test mode: cached pages answer only ?fpg-test=1
 *  life            hours a cached page lives (0 = until a purge)
 *  mobile          a separate cache for phones (wp_is_mobile)
 *  exclude_urls    never cached, one per line, * = anything
 *  exclude_cookies cookie name prefixes that mean "personal page"
 *  preload         fill the cache in the background from the sitemap
 *  rate            preload pages per minute
 *  preload_max     at most this many URLs per preload
 *  preload_exclude never preloaded, one per line, * = anything
 *  minify_css      minify local CSS files (per file, no concatenation)
 *  minify_js       minify local JS files (per file, no concatenation)
 *  minify_exclude  never minified: file names or parts of URLs
 *  lazy_img        loading="lazy" on images that lack it
 *  lazy_skip       the first N images stay eager (LCP)
 *  lazy_iframe     loading="lazy" on iframes that lack it
 *  lazy_exclude    never lazy: parts of src or class
 *  lazy_bg         inline CSS background images load when visible
 *  youtube         YouTube iframes as an image with a play button
 *  dims            width / height on local images that lack them
 *  hover           WordPress speculative loading: core | prefetch |
 *                  prerender | off
 *
 * The HTML optimizations only run on pages the cache stores.
 */

defined( 'ABSPATH' ) || exit;

final class FPG_Settings {

	const OPTION = 'fpg_settings';

	/** Slug of the settings page. */
	const PAGE = 'fpg-settings';

	/**
	 * Allowed lifespans in hours. At most 12 (1.0.1): WordPress nonces in
	 * a cached page stop working after 12 to 24 hours, so a longer life
	 * breaks visitors' forms and buttons.
	 */
	const LIVES = array( 1, 2, 4, 6, 8, 10, 12 );

	/** Allowed preload rates (pages per minute). */
	const RATES = array( 5, 10, 20, 30, 60 );

	const HOVER = array( 'core', 'prefetch', 'prerender', 'off' );

	/** Longest text list, in characters. */
	const MAX_TEXT = 4000;

	/** Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'enabled'         => false,
			'test'            => true,
			'life'            => 10,
			'mobile'          => false,
			'exclude_urls'    => '',
			'exclude_cookies' => '',
			'preload'         => false,
			'rate'            => 10,
			'preload_max'     => 2000,
			'preload_exclude' => '',
			'minify_css'      => false,
			'minify_js'       => false,
			'minify_exclude'  => '',
			'lazy_img'        => false,
			'lazy_skip'       => 3,
			'lazy_iframe'     => false,
			'lazy_exclude'    => '',
			'lazy_bg'         => false,
			'youtube'         => false,
			'dims'            => false,
			'hover'           => 'core',
		);
	}

	/** Keys that are true / false. */
	public static function bools(): array {
		return array( 'enabled', 'test', 'mobile', 'preload', 'minify_css', 'minify_js', 'lazy_img', 'lazy_iframe', 'lazy_bg', 'youtube', 'dims' );
	}

	/** Keys that are text lists. */
	public static function texts(): array {
		return array( 'exclude_urls', 'exclude_cookies', 'preload_exclude', 'minify_exclude', 'lazy_exclude' );
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPTION, array() );
			self::$cache = self::sanitize( is_array( $raw ) ? $raw : array() );
		}
		return self::$cache;
	}

	/** Saves and rewrites the drop-in's config. */
	public static function save( array $settings ): void {
		$clean = self::sanitize( $settings );
		update_option( self::OPTION, $clean, true );
		self::$cache = $clean;
		FPG_Setup::write_config();
	}

	/** Merges changes into the current settings and saves them. */
	public static function update( array $changes ): array {
		$s = self::get();
		foreach ( $changes as $k => $v ) {
			if ( array_key_exists( $k, $s ) ) {
				$s[ $k ] = $v;
			}
		}
		self::save( $s );
		return self::get();
	}

	public static function sanitize( array $raw ): array {
		$d = self::defaults();
		$s = array();
		foreach ( self::bools() as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? self::truthy( $raw[ $k ] ) : $d[ $k ];
		}
		$life      = isset( $raw['life'] ) ? (int) $raw['life'] : $d['life'];
		if ( ! in_array( $life, self::LIVES, true ) ) {
			// 1.0.0 also had 24, 48 and 0 (until a purge): they become 12.
			$life = ( 0 === $life || $life > 12 ) ? 12 : $d['life'];
		}
		$s['life'] = $life;
		$rate      = isset( $raw['rate'] ) ? (int) $raw['rate'] : $d['rate'];
		$s['rate'] = in_array( $rate, self::RATES, true ) ? $rate : $d['rate'];
		$max       = isset( $raw['preload_max'] ) ? (int) $raw['preload_max'] : $d['preload_max'];

		$s['preload_max'] = max( 50, min( 10000, $max ) );
		$skip             = isset( $raw['lazy_skip'] ) ? (int) $raw['lazy_skip'] : $d['lazy_skip'];
		$s['lazy_skip']   = max( 0, min( 10, $skip ) );
		$hover            = isset( $raw['hover'] ) ? (string) $raw['hover'] : $d['hover'];
		$s['hover']       = in_array( $hover, self::HOVER, true ) ? $hover : $d['hover'];
		foreach ( self::texts() as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? self::text( $raw[ $k ], 'exclude_cookies' === $k ) : $d[ $k ];
		}
		return $s;
	}

	private static function truthy( $v ): bool {
		return true === $v || 1 === $v || '1' === $v || 'yes' === $v || 'on' === $v || 'true' === $v;
	}

	/**
	 * A text list: one entry per line, trimmed, no empty lines, no
	 * duplicates, no control characters, MAX_TEXT characters at most.
	 * Cookie names keep only the characters a cookie name may have.
	 */
	private static function text( $v, bool $cookie ): string {
		$lines = is_array( $v ) ? $v : preg_split( '/\r\n|\r|\n/', (string) $v );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $line ) );
			if ( $cookie ) {
				$line = preg_replace( '/[^A-Za-z0-9_\-.*]/', '', $line );
			}
			if ( '' === $line || strlen( $line ) > 300 || in_array( $line, $out, true ) ) {
				continue;
			}
			$out[] = $line;
		}
		return substr( implode( "\n", $out ), 0, self::MAX_TEXT );
	}

	/** A text list as an array of lines. */
	public static function lines( string $key ): array {
		$v = (string) ( self::get()[ $key ] ?? '' );
		return '' === $v ? array() : explode( "\n", $v );
	}

	/** Lifespan in seconds (0 = until a purge). */
	public static function life_seconds(): int {
		return (int) self::get()['life'] * HOUR_IN_SECONDS;
	}
}

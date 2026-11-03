<?php
/**
 * SSG_Detect — other SEO plugins. While one is active, Search Signals
 * prints nothing and serves nothing (never two SEO layers); Import and
 * Compare still work.
 *
 * Read after plugins_loaded (every plugin file is loaded by then), so the
 * version constants of the others are known.
 */

defined( 'ABSPATH' ) || exit;

final class SSG_Detect {

	/** Name => [ constant or class that proves it runs, main plugin file ]. */
	const KNOWN = array(
		'Rank Math'         => array( 'RANK_MATH_VERSION', 'seo-by-rank-math/rank-math.php' ),
		'Yoast SEO'         => array( 'WPSEO_VERSION', 'wordpress-seo/wp-seo.php' ),
		'SEOPress'          => array( 'SEOPRESS_VERSION', 'wp-seopress/seopress.php' ),
		'All in One SEO'    => array( 'AIOSEO_VERSION', 'all-in-one-seo-pack/all_in_one_seo_pack.php' ),
		'The SEO Framework' => array( 'THE_SEO_FRAMEWORK_VERSION', 'autodescription/autodescription.php' ),
	);

	/** @var string|null Per-request cache. */
	private static $other = null;

	/** Name of the first other SEO plugin that runs, or ''. */
	public static function other(): string {
		if ( null !== self::$other ) {
			return self::$other;
		}
		$found = '';
		foreach ( self::KNOWN as $name => $d ) {
			if ( defined( $d[0] ) ) {
				$found = $name;
				break;
			}
		}
		/**
		 * Name of another SEO layer that runs on this site ('' = none).
		 * For a plugin we do not know: return its name and Search Signals
		 * stays passive.
		 *
		 * @param string $found
		 */
		$found = (string) apply_filters( 'ssg_other_seo', $found );
		if ( did_action( 'plugins_loaded' ) ) {
			self::$other = $found;
		}
		return $found;
	}

	/** Main file of an SEO plugin by name ('' = unknown). */
	public static function file_of( string $name ): string {
		return isset( self::KNOWN[ $name ] ) ? self::KNOWN[ $name ][1] : '';
	}

	/** Whether Rank Math is installed (active or not). */
	public static function rank_math_installed(): bool {
		return file_exists( trailingslashit( WP_PLUGIN_DIR ) . self::KNOWN['Rank Math'][1] );
	}

	/** Tests and the switch: forget the cache. */
	public static function reset(): void {
		self::$other = null;
	}
}

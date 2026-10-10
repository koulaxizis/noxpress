<?php
/**
 * SHF_Seo — filtered listing pages are not indexed.
 *
 * When one of our filters (or a price range) is in the URL:
 *  - robots "noindex, follow" through WordPress core (wp_robots) and, when
 *    present, Yoast SEO and Rank Math (so only one meta robots is printed);
 *  - canonical to the same listing without filters: through the SEO plugin
 *    when there is one, otherwise our own <link rel="canonical">.
 * Filter links already carry rel="nofollow" (SHF_Render).
 */

defined( 'ABSPATH' ) || exit;

final class SHF_Seo {

	public static function init(): void {
		add_filter( 'wp_robots', array( __CLASS__, 'wp_robots' ), 20 );
		add_filter( 'wpseo_robots', array( __CLASS__, 'yoast_robots' ), 20 );
		add_filter( 'wpseo_canonical', array( __CLASS__, 'canonical_filter' ), 20 );
		add_filter( 'rank_math/frontend/robots', array( __CLASS__, 'rank_math_robots' ), 20 );
		add_filter( 'rank_math/frontend/canonical', array( __CLASS__, 'canonical_filter' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'canonical_tag' ), 5 );
	}

	/** Filtered listing page with the SEO rules on. */
	private static function applies(): bool {
		try {
			return SHF_Request::active() && ! empty( SHF_Settings::get()['seo'] ) && SHF_Request::is_listing() && SHF_Request::has_filters();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** The same listing without filters, price, sorting or paging. */
	public static function canonical(): string {
		$url = SHF_Request::url_clear();
		$url = (string) strtok( $url, '#' );
		return remove_query_arg( 'orderby', $url );
	}

	public static function wp_robots( $robots ) {
		if ( ! is_array( $robots ) || ! self::applies() ) {
			return $robots;
		}
		unset( $robots['index'] );
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['nofollow'] );
		return $robots;
	}

	public static function yoast_robots( $robots ) {
		return self::applies() ? 'noindex, follow' : $robots;
	}

	public static function rank_math_robots( $robots ) {
		if ( ! self::applies() ) {
			return $robots;
		}
		$robots           = is_array( $robots ) ? $robots : array();
		$robots['index']  = 'noindex';
		$robots['follow'] = 'follow';
		return $robots;
	}

	public static function canonical_filter( $url ) {
		return self::applies() ? self::canonical() : $url;
	}

	/** Our own canonical, only when no SEO plugin prints one. */
	public static function canonical_tag(): void {
		if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'AIOSEO_VERSION' ) || ! self::applies() ) {
			return;
		}
		echo '<link rel="canonical" href="' . esc_url( self::canonical() ) . '" />' . "\n";
	}
}

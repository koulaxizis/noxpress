<?php
/**
 * VLG_Router — which language answers this request, and the URLs of it.
 *
 * English lives under one fixed prefix, /en/ (after the home path). The
 * prefix is read from REQUEST_URI while the plugin file loads, before
 * WordPress parses the request, and removed from it, so WordPress routes
 * /en/product/x/ exactly like /product/x/. From then on:
 *
 *  - locale / determine_locale → en_US (or en_GB): WordPress, WooCommerce,
 *    the theme and every Noxpress plugin speak English on their own (their
 *    source strings or their own dictionaries, Bible §9);
 *  - home_url → every link WordPress builds keeps /en/ (pages, products,
 *    cart, checkout, wc-ajax, REST, payment return URLs);
 *  - redirect_canonical never sends /en/x back to /x;
 *  - the answer is never stored by a page cache (Noxpress_Core::
 *    no_page_cache): Fast Pages keys its files on the request path, and
 *    the English page must never be served for the Greek URL.
 *
 * Never: wp-admin, wp-login.php, cron, CLI, XML-RPC (Bible §14.4); an
 * admin-ajax or wc-ajax call without the prefix counts as English only
 * when its referer is an /en/ page. Root-relative links (home_url( ...,
 * 'relative' ), WooCommerce's wc-ajax endpoint) get the prefix too.
 * VLG_DISABLE in wp-config.php stops all of it (§14.3).
 */

defined( 'ABSPATH' ) || exit;

final class VLG_Router {

	/** 'el' | 'en' for this request. */
	private static $lang = 'el';

	/** Home path without the trailing slash ('' for a site at the root). */
	private static $base = '';

	/** The request URI as it arrived (with /en/). */
	private static $original = '';

	/** Paths that never get the prefix (relative to home, no leading slash). */
	const SKIP = array( 'wp-admin', 'wp-login.php', 'wp-content', 'wp-includes', 'wp-cron.php', 'xmlrpc.php', 'wp-signup.php', 'wp-activate.php', 'robots.txt', 'favicon.ico', '.well-known' );

	/** Runs while the plugin file loads (before plugins_loaded and parse_request). */
	public static function boot(): void {
		try {
			$home       = (string) get_option( 'home' );
			$path       = (string) wp_parse_url( $home, PHP_URL_PATH );
			self::$base = rtrim( $path, '/' );
			if ( self::killed() || ! VLG_Settings::get()['on'] || self::skip_context() ) {
				return;
			}
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared and cut only, never printed.
			$cut = self::strip( $uri );
			if ( null !== $cut ) {
				self::$original          = $uri;
				self::$lang              = 'en';
				$_SERVER['REQUEST_URI']  = $cut;
				$_SERVER['VLG_LANG']     = 'en';
			} elseif ( ( wp_doing_ajax() || isset( $_GET['wc-ajax'] ) ) && self::referer_is_en() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence only.
				self::$lang = 'en';
			}
			if ( 'en' === self::$lang ) {
				self::hooks();
			}
		} catch ( \Throwable $e ) {
			self::$lang = 'el';
		}
	}

	public static function killed(): bool {
		return defined( 'VLG_DISABLE' ) && VLG_DISABLE;
	}

	/** Requests the language never touches. */
	private static function skip_context(): bool {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || wp_doing_cron() ) {
			return true;
		}
		return is_admin() && ! wp_doing_ajax();
	}

	/**
	 * The URI without the prefix, or null when it has none.
	 * "/en" → "/", "/en/x?y" → "/x?y", "/en?y" → "/?y"; "/english" → null.
	 */
	public static function strip( string $uri ): ?string {
		$p = self::$base . '/' . VLG_Settings::PREFIX;
		if ( $uri === $p ) {
			return self::$base . '/';
		}
		$len = strlen( $p );
		if ( strncmp( $uri, $p, $len ) !== 0 ) {
			return null;
		}
		$next = substr( $uri, $len, 1 );
		if ( '/' === $next ) {
			return self::$base . substr( $uri, $len );
		}
		if ( '?' === $next || '#' === $next ) {
			return self::$base . '/' . substr( $uri, $len );
		}
		return null;
	}

	private static function referer_is_en(): bool {
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
		if ( '' === $ref ) {
			return false;
		}
		$home = untrailingslashit( (string) get_option( 'home' ) );
		if ( strpos( $ref, $home . '/' ) !== 0 ) {
			return false;
		}
		$path = (string) wp_parse_url( $ref, PHP_URL_PATH );
		return null !== self::strip( '' === $path ? '/' : $path );
	}

	private static function hooks(): void {
		$locale = VLG_Settings::get()['locale'];
		// A switched locale (switch_to_locale(), e.g. an admin e-mail in the
		// site language, VLG_Orders) wins over the /en/ locale.
		$fn = static function ( $current ) use ( $locale ) {
			// The switcher exists only from wp-settings.php on (not while plugins load).
			return isset( $GLOBALS['wp_locale_switcher'] ) && is_object( $GLOBALS['wp_locale_switcher'] ) && is_locale_switched() ? $current : $locale;
		};
		add_filter( 'locale', $fn, 99 );
		add_filter( 'determine_locale', $fn, 99 );
		add_filter( 'home_url', array( __CLASS__, 'home_url' ), 99, 2 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'redirect_canonical' ), 99, 2 );
		add_action( 'send_headers', array( __CLASS__, 'no_cache' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ), 0 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	public static function lang(): string {
		return self::$lang;
	}

	public static function is_en(): bool {
		return 'en' === self::$lang;
	}

	/** home_url filter: the prefix right after the home path. */
	public static function home_url( $url, $path = '' ) {
		try {
			return self::add_prefix( (string) $url );
		} catch ( \Throwable $e ) {
			return $url;
		}
	}

	/** A URL of this site with the prefix (unchanged when it has it, is outside, or is skipped). */
	public static function add_prefix( string $url ): string {
		if ( self::is_relative( $url ) ) {
			return self::add_prefix_path( $url );
		}
		$home = untrailingslashit( self::raw_home( $url ) );
		if ( '' === $home || strpos( $url, $home ) !== 0 ) {
			return $url;
		}
		$rest = substr( $url, strlen( $home ) );
		if ( '' !== $rest && ! in_array( $rest[0], array( '/', '?', '#' ), true ) ) {
			return $url;
		}
		$rel = ltrim( $rest, '/' );
		if ( self::is_skipped( $rel ) || null !== self::strip( self::$base . '/' . $rel ) ) {
			return $url;
		}
		if ( '' === $rel ) {
			return $home . '/' . VLG_Settings::PREFIX . '/';
		}
		return $home . '/' . VLG_Settings::PREFIX . ( '/' === $rest[0] ? '' : '/' ) . $rest;
	}

	/** A root-relative URL ("/x", not "//host/x"): home_url( ..., 'relative' ), e.g. WooCommerce's wc-ajax endpoint. */
	private static function is_relative( string $url ): bool {
		return '' !== $url && '/' === $url[0] && ( 1 === strlen( $url ) || '/' !== $url[1] );
	}

	/** add_prefix() for a root-relative URL. */
	private static function add_prefix_path( string $url ): string {
		if ( '' !== self::$base && strpos( $url, self::$base ) !== 0 ) {
			return $url;
		}
		$rest = substr( $url, strlen( self::$base ) );
		$rest = '' === $rest ? '/' : $rest;
		if ( ! in_array( $rest[0], array( '/', '?', '#' ), true ) ) {
			return $url;
		}
		$rel = ltrim( $rest, '/' );
		if ( self::is_skipped( $rel ) || null !== self::strip( self::$base . '/' . $rel ) ) {
			return $url;
		}
		return self::$base . '/' . VLG_Settings::PREFIX . ( '/' === $rest[0] ? '' : '/' ) . $rest;
	}

	/** A URL of this site without the prefix. */
	public static function remove_prefix( string $url ): string {
		if ( self::is_relative( $url ) ) {
			$cut = self::strip( $url );
			return null === $cut ? $url : $cut;
		}
		$home = untrailingslashit( self::raw_home( $url ) );
		if ( '' === $home || strpos( $url, $home ) !== 0 ) {
			return $url;
		}
		$cut = self::strip( self::$base . substr( $url, strlen( $home ) ) );
		if ( null === $cut ) {
			return $url;
		}
		return $home . substr( $cut, strlen( self::$base ) );
	}

	/** The home option in the scheme of $url (http / https differ). */
	private static function raw_home( string $url ): string {
		$home = (string) get_option( 'home' );
		if ( 0 === strpos( $url, 'https://' ) ) {
			return set_url_scheme( $home, 'https' );
		}
		if ( 0 === strpos( $url, 'http://' ) ) {
			return set_url_scheme( $home, 'http' );
		}
		return $home;
	}

	private static function is_skipped( string $rel ): bool {
		foreach ( self::SKIP as $s ) {
			if ( $rel === $s || 0 === strpos( $rel, $s . '/' ) || 0 === strpos( $rel, $s . '?' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * redirect_canonical: WordPress compares the stripped request with links
	 * that carry the prefix; that difference alone is not a redirect.
	 */
	public static function redirect_canonical( $redirect, $requested ) {
		try {
			if ( ! is_string( $redirect ) || '' === $redirect ) {
				return $redirect;
			}
			// $requested is built from the stripped URI, so it has no prefix.
			if ( self::remove_prefix( $redirect ) === self::remove_prefix( (string) $requested ) ) {
				return false;
			}
			return self::add_prefix( $redirect );
		} catch ( \Throwable $e ) {
			return $redirect;
		}
	}

	public static function no_cache(): void {
		try {
			if ( class_exists( 'Noxpress_Core' ) && method_exists( 'Noxpress_Core', 'no_page_cache' ) ) {
				Noxpress_Core::no_page_cache();
			} elseif ( ! headers_sent() ) {
				nocache_headers();
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	public static function body_class( $classes ) {
		$classes   = is_array( $classes ) ? $classes : array();
		$classes[] = 'vlg-en';
		return $classes;
	}

	/**
	 * The two versions of the current page: array( 'el' => url, 'en' => url ).
	 * Built from the request as WordPress saw it, without tracking parameters.
	 */
	public static function versions(): array {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- escaped on output.
		$home = untrailingslashit( set_url_scheme( (string) get_option( 'home' ) ) );
		$rel  = substr( $uri, strlen( self::$base ) );
		$el   = $home . ( '' === $rel ? '/' : $rel );
		$el   = remove_query_arg( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid' ), $el );
		return array(
			'el' => $el,
			'en' => self::add_prefix( $el ),
		);
	}
}

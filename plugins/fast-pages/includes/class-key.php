<?php
/**
 * FPG_Key — which cache file answers a request, in plain PHP.
 *
 * Shared by the drop-in (serve.php, before WordPress loads: no WordPress
 * function may be called here) and by the plugin (writing, purging,
 * preloading), so both always agree on the file of a URL.
 *
 *  request()  the current request ($_SERVER, $_COOKIE) against the drop-in
 *             config: ok + file, or the reason it is not cached
 *  url()      a full URL (purge, preload, tests): its files
 *  file()     scheme + host + path (+ mobile) → absolute file path
 *
 * Files: <dir>/<host>/<2 hex>/<32 hex>[-m].html, where the 32 hex are the
 * md5 of "scheme://host/path". The name never comes from the URL itself,
 * so no request can reach a file outside this pattern.
 */

defined( 'ABSPATH' ) || exit;

final class FPG_Key {

	/** Query parameters that never change the page (tracking). */
	const IGNORED_QS = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'fbclid', 'gclid', 'gbraid', 'wbraid', 'msclkid', 'dclid', 'mc_cid', 'mc_eid', '_ga', '_gl', 'igshid', 'ttclid', 'yclid', 'ref', 'srsltid' );

	/** Query parameter of the test mode (Bible §14.2). */
	const TEST_QS = 'fpg-test';

	/** Cookies (name prefixes) of a visitor whose page is personal. */
	const COOKIES = array( 'wordpress_logged_in_', 'wp-postpass_', 'comment_author_', 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_', 'wordpress_no_cache' );

	/** File name pattern, below <dir>/<host>/. */
	const PATTERN = '/^[0-9a-f]{32}(-m)?\.html$/';

	/**
	 * The current request. Returns array( ok, reason, file, test ):
	 * ok = a cache file may answer it, file = that file, test = the
	 * request asked for the test preview (?fpg-test=1).
	 */
	public static function request( array $server, array $cookies, array $cfg ): array {
		$out    = array(
			'ok'     => false,
			'reason' => '',
			'file'   => '',
			'test'   => false,
		);
		$method = isset( $server['REQUEST_METHOD'] ) ? strtoupper( (string) $server['REQUEST_METHOD'] ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			$out['reason'] = 'method';
			return $out;
		}
		$host = isset( $server['HTTP_HOST'] ) ? self::host( (string) $server['HTTP_HOST'] ) : '';
		if ( '' === $host || empty( $cfg['hosts'] ) || ! in_array( $host, (array) $cfg['hosts'], true ) ) {
			$out['reason'] = 'host';
			return $out;
		}
		$uri = isset( $server['REQUEST_URI'] ) ? (string) $server['REQUEST_URI'] : '/';
		$cut = self::split( $uri );
		if ( null === $cut ) {
			$out['reason'] = 'url';
			return $out;
		}
		list( $path, $query ) = $cut;
		if ( isset( $query[ self::TEST_QS ] ) ) {
			$out['test'] = '1' === $query[ self::TEST_QS ];
			unset( $query[ self::TEST_QS ] );
		}
		if ( ! empty( $query ) ) {
			$out['reason'] = 'query';
			return $out;
		}
		$why = self::path_reason( $path, $cfg );
		if ( '' !== $why ) {
			$out['reason'] = $why;
			return $out;
		}
		// The test preview shows the visitors' page to a logged-in admin:
		// the cookies of the admin do not matter for it.
		if ( ! $out['test'] ) {
			$prefixes = array_merge( self::COOKIES, isset( $cfg['cookies'] ) ? (array) $cfg['cookies'] : array() );
			foreach ( array_keys( $cookies ) as $name ) {
				foreach ( $prefixes as $p ) {
					if ( '' !== $p && 0 === strpos( (string) $name, (string) $p ) ) {
						$out['reason'] = 'cookie';
						return $out;
					}
				}
			}
		}
		$https       = self::https( $server );
		$ua          = isset( $server['HTTP_USER_AGENT'] ) ? (string) $server['HTTP_USER_AGENT'] : '';
		$mobile      = ! empty( $cfg['mobile'] ) && self::is_mobile( $ua );
		$out['ok']   = true;
		$out['file'] = self::file( (string) $cfg['dir'], $https ? 'https' : 'http', $host, $path, $mobile );
		return $out;
	}

	/**
	 * The cache files of a full URL: array( desktop file, mobile file ),
	 * or an empty array when the URL is never cached.
	 */
	public static function url( string $url, array $cfg ): array {
		$p = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- also runs before WordPress.
		if ( ! is_array( $p ) || empty( $p['host'] ) || empty( $p['scheme'] ) ) {
			return array();
		}
		$host = self::host( (string) $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) );
		if ( ! in_array( $host, (array) ( $cfg['hosts'] ?? array() ), true ) ) {
			return array();
		}
		$cut = self::split( ( $p['path'] ?? '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' ) );
		if ( null === $cut || ! empty( $cut[1] ) || '' !== self::path_reason( $cut[0], $cfg ) ) {
			return array();
		}
		$scheme = 'https' === strtolower( (string) $p['scheme'] ) ? 'https' : 'http';
		return array(
			self::file( (string) $cfg['dir'], $scheme, $host, $cut[0], false ),
			self::file( (string) $cfg['dir'], $scheme, $host, $cut[0], true ),
		);
	}

	/** Absolute file path of a page. */
	public static function file( string $dir, string $scheme, string $host, string $path, bool $mobile ): string {
		$md5 = md5( $scheme . '://' . $host . $path );
		return rtrim( $dir, '/' ) . '/' . $host . '/' . substr( $md5, 0, 2 ) . '/' . $md5 . ( $mobile ? '-m' : '' ) . '.html';
	}

	/** Lower-case host with the port, only [a-z0-9.-:]; '' when invalid. */
	public static function host( string $host ): string {
		$host = strtolower( trim( $host ) );
		if ( '' === $host || strlen( $host ) > 255 || ! preg_match( '/^[a-z0-9.-]+(:[0-9]{1,5})?$/', $host ) ) {
			return '';
		}
		if ( false !== strpos( $host, '..' ) ) {
			return '';
		}
		$host = preg_replace( '/:(80|443)$/', '', $host );
		return str_replace( ':', '_', (string) $host );
	}

	/**
	 * Path and remaining query of a request URI, without the tracking
	 * parameters. null when the URI is not usable.
	 */
	public static function split( string $uri ) {
		if ( strlen( $uri ) > 2048 || false !== strpos( $uri, "\0" ) || false !== strpos( $uri, '#' ) ) {
			return null;
		}
		$q    = strpos( $uri, '?' );
		$path = false === $q ? $uri : substr( $uri, 0, $q );
		$qs   = false === $q ? '' : substr( $uri, $q + 1 );
		if ( '' === $path || '/' !== $path[0] || false !== strpos( $path, '\\' ) ) {
			return null;
		}
		$query = array();
		if ( '' !== $qs ) {
			foreach ( explode( '&', $qs ) as $pair ) {
				if ( '' === $pair ) {
					continue;
				}
				$eq   = strpos( $pair, '=' );
				$name = urldecode( false === $eq ? $pair : substr( $pair, 0, $eq ) );
				$val  = false === $eq ? '' : urldecode( substr( $pair, $eq + 1 ) );
				if ( in_array( strtolower( $name ), self::IGNORED_QS, true ) || 0 === strpos( strtolower( $name ), 'utm_' ) ) {
					continue;
				}
				$query[ $name ] = $val;
			}
		}
		return array( $path, $query );
	}

	/** Why a path is never cached ('' = it may be). */
	public static function path_reason( string $path, array $cfg ): string {
		$lower = strtolower( rawurldecode( $path ) );
		if ( false !== strpos( $lower, '/..' ) || false !== strpos( $lower, '/./' ) ) {
			return 'url';
		}
		foreach ( array( '/wp-admin', '/wp-login.php', '/wp-json', '/wp-content/', '/wp-includes/', '/xmlrpc.php', '/wp-cron.php', '/wp-signup.php', '/wp-activate.php', '/wp-comments-post.php', '/wp-trackback.php' ) as $no ) {
			if ( false !== strpos( $lower, $no ) ) {
				return 'wp';
			}
		}
		if ( preg_match( '#\.(php|xml|xsl|txt|json|ico|map)$#', $lower ) || preg_match( '#/(feed|rss|rss2|rdf|atom|embed|trackback)/?$#', $lower ) || preg_match( '#sitemap[^/]*$#', $lower ) ) {
			return 'wp';
		}
		foreach ( (array) ( $cfg['never'] ?? array() ) as $no ) {
			if ( '' !== $no && 0 === strpos( $lower, strtolower( (string) $no ) ) ) {
				return 'shop';
			}
		}
		foreach ( (array) ( $cfg['exclude'] ?? array() ) as $re ) {
			if ( '' !== $re && @preg_match( (string) $re, rawurldecode( $path ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- patterns are built and checked by FPG_Settings.
				return 'excluded';
			}
		}
		return '';
	}

	/** An exclusion line ("/cart/*", "*preview*") as a regex. */
	public static function pattern( string $line ): string {
		$line = trim( $line );
		if ( '' === $line ) {
			return '';
		}
		$parts = array_map(
			static function ( $p ) {
				return preg_quote( $p, '#' );
			},
			explode( '*', $line )
		);
		$re = implode( '.*', $parts );
		if ( '/' === $line[0] ) {
			$re = '^' . $re;
		}
		return '#' . $re . '#i';
	}

	/** HTTPS, also behind a proxy that says so (Cloudflare, load balancers). */
	public static function https( array $server ): bool {
		if ( isset( $server['HTTPS'] ) && '' !== $server['HTTPS'] && 'off' !== strtolower( (string) $server['HTTPS'] ) ) {
			return true;
		}
		if ( isset( $server['SERVER_PORT'] ) && '443' === (string) $server['SERVER_PORT'] ) {
			return true;
		}
		return isset( $server['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( (string) $server['HTTP_X_FORWARDED_PROTO'] );
	}

	/** The same test as WordPress's wp_is_mobile(), without WordPress. */
	public static function is_mobile( string $ua ): bool {
		if ( '' === $ua ) {
			return false;
		}
		foreach ( array( 'Mobile', 'Android', 'Silk/', 'Kindle', 'BlackBerry', 'Opera Mini', 'Opera Mobi' ) as $needle ) {
			if ( false !== strpos( $ua, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}

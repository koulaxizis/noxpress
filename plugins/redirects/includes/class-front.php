<?php
/**
 * RDR_Front — what a visitor request meets.
 *
 * template_redirect, priority 9: only when WordPress answers 404, before
 * WordPress's own old-slug and canonical redirects (10) and Greeklish
 * Permalinks (20), so a rule the owner wrote wins over an automatic
 * guess. Exact rule first (one indexed query), then prefix, then regex.
 * Test mode: rules answer only users with manage_options.
 *
 * template_redirect, last priority: the request is still a 404, so
 * nobody redirected it: one line in the 404 log (unless the path is on the
 * scanner list).
 *
 * GET / HEAD only; never admin, AJAX, REST, feeds, embeds, cron, CLI or
 * XML-RPC (Bible §14.4). Every callback in try/catch: on an error the
 * normal 404 stays (§14.6). Reads the database only for the lookup.
 */

defined( 'ABSPATH' ) || exit;

final class RDR_Front {

	/** True when this request was answered by a rule (410 included). */
	private static $handled = false;

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'redirect' ), 9 );
		add_action( 'template_redirect', array( __CLASS__, 'log_404' ), PHP_INT_MAX );
	}

	/** A request we may look at. */
	private static function eligible(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || is_feed() || is_embed() ) {
			return false;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		return 'GET' === $method || 'HEAD' === $method;
	}

	public static function redirect(): void {
		try {
			if ( ! is_404() || ! self::eligible() || ! RDR_Rules::ready() ) {
				return;
			}
			if ( ! RDR_Settings::live() && ! current_user_can( Redirects::CAP ) ) {
				return;
			}
			$path = RDR_Path::request();
			if ( null === $path || '' === $path || RDR_Rules::is_protected( $path ) ) {
				return;
			}
			$hit = RDR_Rules::match( $path );
			if ( ! $hit ) {
				return;
			}
			list( $rule, $caps ) = $hit;

			if ( 410 === $rule['code'] ) {
				self::$handled = true;
				status_header( 410 );
				nocache_headers();
				if ( ! headers_sent() ) {
					header( 'X-Redirects: ' . (int) $rule['id'] . ( RDR_Settings::live() ? '' : ' test' ) );
				}
				if ( RDR_Settings::live() ) { // An administrator's test visit is not a hit.
					RDR_Log::write(
						array(
							'k' => 'g',
							'p' => $path,
							'i' => $rule['id'],
						)
					);
				}
				return; // The theme's 404 template renders, with status 410.
			}

			$url = RDR_Rules::target_url( $rule, $caps );
			/**
			 * Filters the target of a rule before the redirect ('' = no redirect).
			 *
			 * @param string $url  Target URL.
			 * @param array  $rule Rule row.
			 * @param string $path Normalised request path.
			 */
			$url = (string) apply_filters( 'rdr_redirect_target', $url, $rule, $path );
			if ( '' === $url ) {
				return; // Target gone: the 404 stays and is logged.
			}
			// Never to the same address (a loop the checks could not see).
			$own = RDR_Path::norm( $url );
			if ( null !== $own && $own === $path ) {
				return;
			}
			$query = RDR_Path::request_query();
			if ( '' !== $query && RDR_Settings::get()['query'] && false === strpos( $url, '?' ) ) {
				$url .= '?' . $query;
			}

			$code = RDR_Rules::live_code( $rule );
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' !== $host && ! RDR_Path::is_own_host( $host ) ) {
				add_filter(
					'allowed_redirect_hosts',
					static function ( $hosts ) use ( $host ) {
						$hosts   = is_array( $hosts ) ? $hosts : array();
						$hosts[] = $host;
						return $hosts;
					}
				);
			}
			self::$handled = true;
			if ( RDR_Settings::live() ) { // An administrator's test visit is not a hit.
				RDR_Log::write(
					array(
						'k' => 'r',
						'p' => $path,
						'i' => $rule['id'],
						'c' => $code,
					)
				);
			}
			if ( ! headers_sent() ) {
				header( 'X-Redirects: ' . (int) $rule['id'] . ( RDR_Settings::live() ? '' : ' test' ) );
			}
			if ( wp_safe_redirect( $url, $code, 'Redirects' ) ) {
				exit;
			}
		} catch ( \Throwable $e ) {
			return; // Bible §14.6: the normal 404 stays.
		}
	}

	public static function log_404(): void {
		try {
			if ( self::$handled || ! is_404() || ! self::eligible() ) {
				return;
			}
			$s = RDR_Settings::get();
			if ( ! $s['log'] ) {
				return;
			}
			$path = RDR_Path::request();
			if ( null === $path || '' === $path ) {
				return;
			}
			if ( $s['bots'] ) {
				foreach ( $s['patterns'] as $pat ) {
					if ( RDR_Path::glob( $pat, $path ) ) {
						return;
					}
				}
			}
			$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only tested, never stored.
			$ref = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only its host is kept, sanitised below.
			$h   = strtolower( (string) wp_parse_url( $ref, PHP_URL_HOST ) );
			if ( '' !== $h ) {
				$h = RDR_Path::is_own_host( $h ) ? '=' : substr( (string) preg_replace( '/[^a-z0-9.-]/', '', $h ), 0, 100 );
			}
			RDR_Log::write(
				array(
					'k' => 'n',
					'p' => $path,
					'h' => $h,
					'b' => ( '' === $ua || preg_match( '/bot|crawl|spider|slurp|curl|wget|python|httpclient|headless|scan|fetch|preview|monitor|java\//i', $ua ) ) ? 1 : 0,
				)
			);
		} catch ( \Throwable $e ) {
			return;
		}
	}
}

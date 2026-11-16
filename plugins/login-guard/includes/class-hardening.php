<?php
/**
 * LGD_Hardening — XML-RPC and user-enumeration protections (live mode).
 *
 * XML-RPC (setting xmlrpc):
 *  - auth_off (default): xmlrpc_enabled → false, so no method that needs a
 *    login answers (WordPress's own switch), and system.multicall is
 *    removed (many password tries in one request) through our server
 *    subclass (LGD_XMLRPC_Server), since IXR adds it after xmlrpc_methods. Methods without a
 *    login stay for plugins that need them.
 *  - closed: xmlrpc.php answers 403 and nothing else runs.
 *  - open: WordPress as it is.
 *  - pingbacks: pingback.ping and pingback.extensions.getPingbacks are
 *    removed and the X-Pingback header goes (in every mode but closed).
 *
 * User enumeration (each a setting):
 *  - enum_rest:     /wp/v2/users answers 404 to requests without a logged-in
 *                   user (cookies with nonce or Application Passwords keep
 *                   working: they set the user before dispatch).
 *  - enum_errors:   the REST errors of a failed Application Password become
 *                   one neutral 401 (the login forms are in LGD_Guard).
 *  - enum_author:   /?author=N answers 404 to visitors (it redirects to
 *                   /author/<login>/ and leaks the login).
 *  - enum_archives: /author/<name>/ answers 404 to visitors (off by
 *                   default: blogs use these pages).
 *  - enum_sitemap:  no users sitemap in wp-sitemap.xml.
 *  - enum_oembed:   no author name or URL in oEmbed answers.
 */

defined( 'ABSPATH' ) || exit;

final class LGD_Hardening {

	public static function init(): void {
		$s = LGD_Settings::get();

		if ( 'closed' === $s['xmlrpc'] ) {
			self::close_xmlrpc();
		} elseif ( 'auth_off' === $s['xmlrpc'] ) {
			add_filter( 'xmlrpc_enabled', '__return_false', 99 );
			add_filter( 'wp_xmlrpc_server_class', array( __CLASS__, 'server_class' ), 99 );
		}
		if ( 'open' !== $s['xmlrpc'] || $s['pingbacks'] ) {
			add_filter( 'xmlrpc_methods', array( __CLASS__, 'methods' ), 99 );
		}
		if ( $s['pingbacks'] ) {
			add_filter( 'wp_headers', array( __CLASS__, 'headers' ), 99 );
		}

		if ( $s['enum_rest'] ) {
			add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest_users' ), 9999, 3 );
		}
		if ( $s['enum_errors'] ) {
			add_filter( 'rest_authentication_errors', array( __CLASS__, 'rest_errors' ), 999 );
		}
		if ( $s['enum_author'] ) {
			add_action( 'parse_request', array( __CLASS__, 'author_scan' ), 1 );
		}
		if ( $s['enum_archives'] ) {
			add_action( 'template_redirect', array( __CLASS__, 'author_archive' ), 1 );
		}
		if ( $s['enum_sitemap'] ) {
			add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'sitemap' ), 10, 2 );
		}
		if ( $s['enum_oembed'] ) {
			add_filter( 'oembed_response_data', array( __CLASS__, 'oembed' ), 99 );
		}
	}

	/** xmlrpc.php → 403 before WordPress builds the server. */
	private static function close_xmlrpc(): void {
		if ( ! defined( 'XMLRPC_REQUEST' ) || ! XMLRPC_REQUEST ) {
			return;
		}
		if ( ! get_transient( 'lgd_xmlrpc_logged' ) ) {
			set_transient( 'lgd_xmlrpc_logged', 1, HOUR_IN_SECONDS );
			LGD_Store::log( 'xmlrpc', LGD_Store::ip() );
		}
		status_header( 403 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'XML-RPC is disabled on this site.';
		exit;
	}

	/** wp_xmlrpc_server_class: our subclass without system.multicall, unless another plugin chose a class. */
	public static function server_class( $class ) {
		if ( 'wp_xmlrpc_server' !== $class || ! class_exists( 'wp_xmlrpc_server' ) ) {
			return $class;
		}
		require_once LGD_PATH . 'includes/class-xmlrpc-server.php';
		return class_exists( 'LGD_XMLRPC_Server' ) ? 'LGD_XMLRPC_Server' : $class;
	}

	/** xmlrpc_methods: no multicall without logins, no pingbacks. */
	public static function methods( $methods ) {
		if ( ! is_array( $methods ) ) {
			return $methods;
		}
		$s = LGD_Settings::get();
		if ( 'auth_off' === $s['xmlrpc'] ) {
			unset( $methods['system.multicall'] );
		}
		if ( $s['pingbacks'] ) {
			unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
		}
		return $methods;
	}

	public static function headers( $headers ) {
		if ( is_array( $headers ) ) {
			unset( $headers['X-Pingback'] );
		}
		return $headers;
	}

	/** rest_pre_dispatch: /wp/v2/users only for a logged-in user. */
	public static function rest_users( $result, $server = null, $request = null ) {
		try {
			if ( null !== $result || ! $request instanceof WP_REST_Request || is_user_logged_in() ) {
				return $result;
			}
			$route = strtolower( untrailingslashit( (string) $request->get_route() ) );
			if ( preg_match( '#^/wp/v2/users(?:/|$)#', $route . '/' ) ) {
				return new WP_Error( 'rest_no_route', 'No route was found matching the URL and request method.', array( 'status' => 404 ) );
			}
		} catch ( \Throwable $e ) {
			return $result;
		}
		return $result;
	}

	/** rest_authentication_errors: a failed Application Password says nothing about the account. */
	public static function rest_errors( $result ) {
		if ( is_wp_error( $result ) && array_intersect( LGD_Guard::LEAKY, $result->get_error_codes() ) ) {
			return new WP_Error( 'lgd_invalid', wp_strip_all_tags( LGD_Settings::text( 'invalid' ) ), array( 'status' => 401 ) );
		}
		return $result;
	}

	/** parse_request: /?author=N → 404 for visitors. */
	public static function author_scan( $wp ): void {
		try {
			if ( is_admin() || is_user_logged_in() || ! $wp instanceof WP ) {
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of a public query var.
			$raw = isset( $_GET['author'] ) ? wp_unslash( $_GET['author'] ) : ( isset( $wp->query_vars['author'] ) ? $wp->query_vars['author'] : '' );
			if ( is_scalar( $raw ) && '' !== (string) $raw && preg_match( '/^-?\d+(,\s*-?\d+)*$/', trim( (string) $raw ) ) ) {
				$wp->query_vars = array( 'error' => '404' );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** template_redirect: /author/<name>/ → 404 for visitors. */
	public static function author_archive(): void {
		try {
			if ( ! is_author() || is_user_logged_in() ) {
				return;
			}
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		} catch ( \Throwable $e ) {
			return;
		}
	}

	public static function sitemap( $provider, $name = '' ) {
		return 'users' === $name ? false : $provider;
	}

	public static function oembed( $data ) {
		if ( is_array( $data ) ) {
			unset( $data['author_name'], $data['author_url'] );
		}
		return $data;
	}
}

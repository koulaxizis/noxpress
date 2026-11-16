<?php
/**
 * MMD_Gate — what a visitor meets while the site is closed.
 *
 * template_redirect, priority 0 (before Redirects, Easy Withdrawal and the
 * theme). Never reached by wp-admin, wp-login.php, admin-ajax.php,
 * wp-cron.php, xmlrpc.php, the REST API or wc-api (WordPress / WooCommerce
 * answer those on parse_request), so logins, payment callbacks, webhooks,
 * Site Bridge and the apps keep working (design §4).
 *
 * Open even while closed:
 *  - robots.txt (with «Disallow: /» on a coming-soon site) and favicon;
 *  - /.well-known/ (Let's Encrypt, assetlinks.json, Site Bridge);
 *  - WooCommerce order-received and order-pay, so a payment in progress
 *    finishes;
 *  - a page with the [nox_withdrawal] shortcode (Easy Withdrawal: a legal
 *    duty, design Q6);
 *  - filter mmd_allow_request( bool $allow ) for anything else.
 * Feeds stay open in «Maintenance» and close on a coming-soon site.
 *
 * While active, every front-end answer is kept out of page caches
 * (Noxpress_Core::no_page_cache()): a visitor who passes (IP, preview link)
 * must never write the real page into a cache that others then read.
 */

defined( 'ABSPATH' ) || exit;

final class MMD_Gate {

	const VIEW = 'mmd-view';

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'gate' ), 0 );
		add_filter( 'robots_txt', array( __CLASS__, 'robots' ), 999, 2 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest' ), 5, 3 );
	}

	public static function gate(): void {
		try {
			// The page as visitors see it, for the settings page preview.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
			if ( isset( $_GET[ self::VIEW ] ) ) {
				$mode  = isset( $_GET['mode'] ) && 'coming_soon' === $_GET['mode'] ? 'coming_soon' : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( current_user_can( Maintenance_Mode::CAP ) && wp_verify_nonce( $nonce, 'mmd_view' ) ) {
					self::no_cache();
					MMD_Page::send( '' !== $mode ? $mode : MMD_Settings::get()['mode'], true );
					exit;
				}
			}

			$st = mmd_status();
			if ( 'on' === $st['state'] || 'scheduled' === $st['state'] ) {
				MMD_Access::accept_param();
			}
			if ( ! $st['active'] ) {
				return;
			}
			self::no_cache();
			if ( MMD_Access::passes() || self::allowed( $st['mode'] ) ) {
				return;
			}
			MMD_Page::send( $st['mode'], false );
			exit;
		} catch ( \Throwable $e ) {
			// On an error the site stays open (§14.6).
			return;
		}
	}

	private static function no_cache(): void {
		if ( class_exists( 'Noxpress_Core' ) && method_exists( 'Noxpress_Core', 'no_page_cache' ) ) {
			Noxpress_Core::no_page_cache();
		} elseif ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	/** Requests that stay open while the site is closed. */
	private static function allowed( string $mode ): bool {
		if ( is_robots() || ( function_exists( 'is_favicon' ) && is_favicon() ) ) {
			return true;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base && 0 === strpos( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		if ( 0 === strpos( ltrim( $path, '/' ), '.well-known/' ) ) {
			return true;
		}
		if ( 'maintenance' === $mode && is_feed() ) {
			return true;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) ) {
			return true;
		}
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'nox_withdrawal' ) ) {
				return true;
			}
		}
		/**
		 * Let a request through while the site is closed.
		 *
		 * @param bool   $allow False.
		 * @param string $mode  'maintenance' | 'coming_soon'.
		 */
		return (bool) apply_filters( 'mmd_allow_request', false, $mode );
	}

	/**
	 * robots.txt of WordPress (not a physical file): «Disallow: /» on a
	 * coming-soon site, so nothing is indexed before the launch.
	 *
	 * @param string $out
	 * @param bool   $public
	 */
	public static function robots( $out, $public = true ) {
		try {
			$st = mmd_status();
			if ( $st['active'] && 'coming_soon' === $st['mode'] ) {
				return "User-agent: *\nDisallow: /\n";
			}
		} catch ( \Throwable $e ) {
			return $out;
		}
		return $out;
	}

	/**
	 * Optional: wp/v2 closed to visitors who are not logged in (posts,
	 * pages, users of a site not yet public). Every other namespace stays
	 * open: payments, WooCommerce, Site Bridge, Broadcast, the apps.
	 *
	 * @param mixed           $result
	 * @param WP_REST_Server  $server
	 * @param WP_REST_Request $request
	 * @return mixed
	 */
	public static function rest( $result, $server, $request ) {
		try {
			if ( null !== $result || ! ( $request instanceof WP_REST_Request ) || is_user_logged_in() ) {
				return $result;
			}
			$s = MMD_Settings::get();
			if ( ! $s['rest_close'] || 0 !== strpos( $request->get_route(), '/wp/v2' ) ) {
				return $result;
			}
			$st = mmd_status();
			if ( ! $st['active'] || MMD_Access::ip_passes() || MMD_Access::cookie_passes() ) {
				return $result;
			}
			return new WP_Error( 'mmd_closed', 'This site is closed for maintenance.', array( 'status' => 503 ) );
		} catch ( \Throwable $e ) {
			return $result;
		}
	}
}


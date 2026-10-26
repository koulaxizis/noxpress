<?php
/**
 * FPG_Front — what runs outside the cache itself:
 *
 *  - hover preload: WordPress's own speculative loading (WP 6.8+), with
 *    the mode the settings pick (core default, prefetch, prerender, off)
 *    and the cart, checkout and account pages never speculated.
 *  - the admin bar menu: purge everything / this page, and the test view
 *    of this page as visitors get it (?fpg-test=1). The purge links are
 *    nonce links to admin-post.php, like WordPress's own row actions
 *    (deviation from Bible §11 "POST", written in the bootstrap docblock).
 */

defined( 'ABSPATH' ) || exit;

final class FPG_Front {

	const NONCE = 'fpg_purge';

	public static function init(): void {
		add_filter( 'wp_speculation_rules_configuration', array( __CLASS__, 'speculation' ), 20 );
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( __CLASS__, 'exclude_paths' ), 10, 1 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
		add_action( 'admin_post_fpg_purge', array( __CLASS__, 'purge_link' ) );
	}

	/** wp_speculation_rules_configuration: null = off. */
	public static function speculation( $config ) {
		$mode = FPG_Settings::get()['hover'];
		if ( 'off' === $mode ) {
			return null;
		}
		if ( 'prefetch' === $mode || 'prerender' === $mode ) {
			return array(
				'mode'      => $mode,
				'eagerness' => 'moderate',
			);
		}
		return $config;
	}

	/** The shop's personal pages are never fetched ahead. */
	public static function exclude_paths( $paths ) {
		$paths = is_array( $paths ) ? $paths : array();
		$home = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		foreach ( (array) FPG_Setup::config()['never'] as $p ) {
			$p = (string) $p;
			if ( '' !== $home && 0 === strpos( $p, $home ) ) {
				$p = substr( $p, strlen( $home ) );
			}
			$paths[] = trailingslashit( $p ) . '*';
		}
		return array_values( array_unique( $paths ) );
	}

	/* =====================================================================
	 * Admin bar
	 * =================================================================== */

	public static function admin_bar( $bar ): void {
		if ( ! is_object( $bar ) || ! current_user_can( Fast_Pages::CAP ) || ! FPG_Settings::get()['enabled'] ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'fpg',
				'title' => __( 'Fast Pages', 'fast-pages' ),
				'href'  => admin_url( 'admin.php?page=' . FPG_Settings::PAGE ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'fpg',
				'id'     => 'fpg-purge-all',
				'title'  => __( 'Καθάρισμα όλης της cache', 'fast-pages' ),
				'href'   => self::purge_href( '' ),
			)
		);
		if ( ! is_admin() ) {
			$here = self::current_url();
			$bar->add_node(
				array(
					'parent' => 'fpg',
					'id'     => 'fpg-purge-page',
					'title'  => __( 'Καθάρισμα αυτής της σελίδας', 'fast-pages' ),
					'href'   => self::purge_href( $here ),
				)
			);
			$bar->add_node(
				array(
					'parent' => 'fpg',
					'id'     => 'fpg-view',
					'title'  => __( 'Η σελίδα όπως στην cache', 'fast-pages' ),
					'href'   => add_query_arg( FPG_Key::TEST_QS, '1', $here ),
					'meta'   => array( 'target' => '_blank' ),
				)
			);
		}
	}

	/** This page's URL, without our test parameter. */
	private static function current_url(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized by esc_url_raw.
		return remove_query_arg( FPG_Key::TEST_QS, esc_url_raw( home_url( $uri ) ) );
	}

	public static function purge_href( string $url ): string {
		$args = array( 'action' => 'fpg_purge' );
		if ( '' !== $url ) {
			$args['url'] = rawurlencode( $url );
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::NONCE );
	}

	public static function purge_link(): void {
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( Fast_Pages::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'fast-pages' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		$url = isset( $_GET['url'] ) ? esc_url_raw( rawurldecode( (string) wp_unslash( $_GET['url'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' !== $url && wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			FPG_Purge::urls( array( $url ) );
			$msg = __( 'Η σελίδα καθαρίστηκε από την cache.', 'fast-pages' );
		} else {
			FPG_Purge::all();
			$url = '';
			$msg = __( 'Η cache καθαρίστηκε.', 'fast-pages' );
		}
		set_transient( 'fpg_aui_msg_' . get_current_user_id(), array( array( 'type' => 'success', 'text' => $msg ) ), 60 );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : ( '' !== $url ? $url : admin_url( 'admin.php?page=' . FPG_Settings::PAGE ) ) );
		exit;
	}
}

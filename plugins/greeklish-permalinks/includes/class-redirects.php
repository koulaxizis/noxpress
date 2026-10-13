<?php
/**
 * GLP_Redirects — 301 instead of a 404 (front end, template_redirect 20).
 *
 * Runs on GET / HEAD page requests, after WordPress's own old-slug
 * redirect (priority 10). On a 404 it tries the steps below; otherwise it
 * only sends a term archive opened by a Greek address to its Latin one
 * (the term lookup finds it through the conversion, GLP_Hooks).
 * Never in admin, AJAX, REST, feeds, cron or CLI (Bible §14.4); reads only.
 *
 *  1. Redirect table glp_map (written by the bulk conversion):
 *     - files: old upload path → new path (needs the web server to send
 *       missing files to WordPress, which most do);
 *     - paths: old path of a hierarchical post (pages) → post ID;
 *     - terms: old term slug → taxonomy → term ID.
 *  2. With the "redirect" setting: a Greek address converted the way new
 *     slugs are; 301 when a post or a term lives there.
 *
 * The probe header (X-GLP-Probe) answers the bulk page's server check:
 * a missing file under uploads that reached WordPress.
 */

defined( 'ABSPATH' ) || exit;

final class GLP_Redirects {

	const MAP = 'glp_map';

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 20 );
	}

	/** The redirect table (not autoloaded). */
	public static function map(): array {
		$m = get_option( self::MAP, array() );
		$m = is_array( $m ) ? $m : array();
		foreach ( array( 'files', 'paths', 'terms' ) as $k ) {
			$m[ $k ] = isset( $m[ $k ] ) && is_array( $m[ $k ] ) ? $m[ $k ] : array();
		}
		return $m;
	}

	public static function save_map( array $m ): void {
		if ( ! $m['files'] && ! $m['paths'] && ! $m['terms'] ) {
			delete_option( self::MAP );
			return;
		}
		update_option( self::MAP, $m, false );
	}

	public static function count_map(): int {
		$m = self::map();
		$n = count( $m['files'] ) + count( $m['paths'] );
		foreach ( $m['terms'] as $tax ) {
			$n += is_array( $tax ) ? count( $tax ) : 0;
		}
		return $n;
	}

	public static function maybe_redirect(): void {
		try {
			if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
				|| ( defined( 'WP_CLI' ) && WP_CLI ) || is_feed() || is_embed() ) {
				return;
			}
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
			if ( 'GET' !== $method && 'HEAD' !== $method ) {
				return;
			}
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed and decoded below, only compared.
			$p   = wp_parse_url( $uri );
			if ( ! is_array( $p ) || empty( $p['path'] ) ) {
				return;
			}
			$path  = rawurldecode( (string) $p['path'] );
			$query = isset( $p['query'] ) ? (string) $p['query'] : '';

			if ( ! is_404() ) {
				$target = self::term_canonical( $path );
				if ( '' !== $target ) {
					wp_safe_redirect( $target . ( '' !== $query ? '?' . $query : '' ), 301, 'Greeklish Permalinks' );
					exit;
				}
				return;
			}

			// Server check of the bulk page: this missing upload reached WordPress.
			if ( 0 === strpos( basename( $path ), 'glp-probe-' ) && '' !== self::rel_upload( $path ) ) {
				if ( ! headers_sent() ) {
					header( 'X-GLP-Probe: 1' );
				}
				return;
			}

			$target = self::from_map( $path );
			if ( '' === $target && GLP_Settings::get()['redirect'] && GLP_Converter::has_greek( $path ) ) {
				$target = self::from_greek( $path );
			}
			if ( '' === $target ) {
				return;
			}
			if ( '' !== $query && false === strpos( $target, '?' ) ) {
				$target .= '?' . $query;
			}
			wp_safe_redirect( $target, 301, 'Greeklish Permalinks' );
			exit;
		} catch ( \Throwable $e ) {
			return; // Bible §14.6: the normal 404 stays.
		}
	}

	/**
	 * A term archive opened by a Greek address while the term's slug is
	 * Latin (the lookup found it through the conversion): 301 to its own
	 * address, so there is one URL per term.
	 */
	private static function term_canonical( string $path ): string {
		if ( ! GLP_Converter::has_greek( $path ) || ! ( is_category() || is_tag() || is_tax() ) ) {
			return '';
		}
		$term = get_queried_object();
		if ( ! $term instanceof WP_Term || GLP_Converter::has_greek( rawurldecode( $term->slug ) ) ) {
			return '';
		}
		$link = get_term_link( $term );
		if ( is_wp_error( $link ) ) {
			return '';
		}
		$paged = (int) get_query_var( 'paged' );
		return $paged > 1 ? (string) get_pagenum_link( $paged ) : (string) $link;
	}

	/** Path relative to the home path, without slashes at the ends. */
	private static function rel_home( string $path ): string {
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = '/' . trim( rawurldecode( $home ), '/' );
		$path = '/' . ltrim( $path, '/' );
		if ( '/' !== $home && 0 === strpos( $path . '/', $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		return trim( $path, '/' );
	}

	/** Upload path of a request ('' when it is not under uploads). */
	public static function rel_upload( string $path ): string {
		$up   = wp_get_upload_dir();
		$base = '/' . trim( rawurldecode( (string) wp_parse_url( $up['baseurl'], PHP_URL_PATH ) ), '/' ) . '/';
		$path = '/' . ltrim( $path, '/' );
		return 0 === strpos( $path, $base ) ? substr( $path, strlen( $base ) ) : '';
	}

	private static function from_map( string $path ): string {
		$m = self::map();

		if ( $m['files'] ) {
			$rel = self::rel_upload( $path );
			if ( '' !== $rel && isset( $m['files'][ $rel ] ) ) {
				return self::upload_url( (string) $m['files'][ $rel ] );
			}
		}

		$rel = self::rel_home( $path );
		if ( '' === $rel ) {
			return '';
		}
		if ( isset( $m['paths'][ $rel ] ) ) {
			$post = get_post( (int) $m['paths'][ $rel ] );
			if ( $post && 'publish' === $post->post_status ) {
				return (string) get_permalink( $post );
			}
		}
		$parts = explode( '/', $rel );
		$last  = (string) end( $parts );
		if ( isset( $m['terms'][ $last ] ) && is_array( $m['terms'][ $last ] ) ) {
			foreach ( $m['terms'][ $last ] as $tax => $term_id ) {
				$link = self::term_link( (int) $term_id, (string) $tax );
				if ( '' !== $link ) {
					return $link;
				}
			}
		}
		return '';
	}

	/** URL of an upload path, each segment encoded. */
	public static function upload_url( string $rel ): string {
		$up = wp_get_upload_dir();
		return trailingslashit( $up['baseurl'] ) . implode( '/', array_map( 'rawurlencode', explode( '/', $rel ) ) );
	}

	private static function term_link( int $term_id, string $tax ): string {
		$tx = get_taxonomy( $tax );
		if ( ! $tx || ! $tx->public ) {
			return '';
		}
		$link = get_term_link( $term_id, $tax );
		return is_wp_error( $link ) ? '' : (string) $link;
	}

	/** Each Greek segment converted like a new slug; 301 only to something that exists. */
	private static function from_greek( string $path ): string {
		$rel = self::rel_home( $path );
		if ( '' === $rel ) {
			return '';
		}
		$segs = array();
		foreach ( explode( '/', $rel ) as $seg ) {
			$segs[] = GLP_Converter::has_greek( $seg )
				? sanitize_title_with_dashes( glp_convert( $seg, 'slug' ), '', 'save' )
				: $seg;
		}
		$new = implode( '/', $segs );
		if ( $new === $rel || '' === $new ) {
			return '';
		}
		$url = home_url( user_trailingslashit( $new ) );

		$id = url_to_postid( $url );
		if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
			return (string) get_permalink( $id );
		}

		$last = (string) end( $segs );
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
			$term = get_term_by( 'slug', $last, $tax );
			if ( $term && ! is_wp_error( $term ) ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) && trim( (string) wp_parse_url( $link, PHP_URL_PATH ), '/' ) === trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) ) {
					return (string) $link;
				}
			}
		}
		return '';
	}
}

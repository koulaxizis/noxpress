<?php
/**
 * PFD_Ctx_Import — one-off import of CTX Feed (WebAppick) feeds.
 *
 * Reads the options wf_feed_<name> (feedrules: provider, filename,
 * campaign_parameters, exclude_tags, categories, is_outOfStock) and adds
 * one feed per CTX feed with the same filters and UTM values. It never
 * writes to CTX Feed's options or files.
 *
 * The CTX file path is NOT set as the legacy path: while CTX Feed runs it
 * rewrites that file every hour. The import message names the path, to be
 * set in «Also write to this path» once CTX Feed is switched off.
 */

defined( 'ABSPATH' ) || exit;

final class PFD_Ctx_Import {

	/** CTX provider → our channel. */
	private static function channel( string $provider ): string {
		$p = strtolower( $provider );
		if ( false !== strpos( $p, 'google' ) ) {
			return 'google';
		}
		if ( false !== strpos( $p, 'facebook' ) || false !== strpos( $p, 'meta' ) ) {
			return 'meta';
		}
		if ( false !== strpos( $p, 'skroutz' ) ) {
			return 'skroutz';
		}
		if ( false !== strpos( $p, 'bestprice' ) ) {
			return 'bestprice';
		}
		return 'csv';
	}

	/** CTX feeds found: name => [ provider, url ]. */
	public static function found(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- option names of a third-party plugin, read once.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 50", $wpdb->esc_like( 'wf_feed_' ) . '%' ) );
		$out   = array();
		foreach ( (array) $names as $n ) {
			$v = get_option( (string) $n );
			if ( is_array( $v ) && isset( $v['feedrules'] ) && is_array( $v['feedrules'] ) ) {
				$out[ substr( (string) $n, 8 ) ] = array(
					'provider' => (string) ( $v['feedrules']['provider'] ?? '' ),
					'url'      => (string) ( $v['url'] ?? '' ),
					'rules'    => $v['feedrules'],
				);
			}
		}
		return $out;
	}

	/**
	 * Add our feeds. Returns [ added, paths[] ] (CTX file paths under
	 * uploads, for the message).
	 */
	public static function run(): array {
		$added = 0;
		$paths = array();
		$taken = array();
		foreach ( PFD_Settings::feeds() as $f ) {
			$taken[ $f['file'] ] = true;
		}
		foreach ( self::found() as $name => $ctx ) {
			$r  = $ctx['rules'];
			$ch = self::channel( $ctx['provider'] );
			$in = PFD_Settings::defaults( $ch );

			$file = sanitize_title( (string) ( $r['filename'] ?? $name ) );
			$file = '' !== $file ? substr( $file, 0, 40 ) : $ch;
			$base = $file;
			$i    = 2;
			while ( isset( $taken[ $file ] ) ) {
				$file = substr( $base, 0, 36 ) . '-' . $i++;
			}
			$taken[ $file ] = true;

			$in['file'] = $file;
			/* translators: %s: CTX Feed file name */
			$in['name'] = sprintf( __( '%s (από CTX Feed)', 'product-feed' ), (string) ( $r['filename'] ?? $name ) );
			$utm        = isset( $r['campaign_parameters'] ) && is_array( $r['campaign_parameters'] ) ? $r['campaign_parameters'] : array();
			foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign' ) as $k ) {
				$in[ $k ] = (string) ( $utm[ $k ] ?? '' );
			}
			$in['tags_out'] = isset( $r['exclude_tags'] ) && is_array( $r['exclude_tags'] ) ? array_map( 'absint', $r['exclude_tags'] ) : array();
			$cats           = isset( $r['categories'] ) && is_array( $r['categories'] ) ? $r['categories'] : array();
			$mode           = (string) ( $r['filter_mode']['categories'] ?? 'include' );
			$ids            = self::cat_ids( $cats );
			if ( $ids ) {
				$in[ 'exclude' === $mode ? 'cats_out' : 'cats_in' ] = $ids;
			}
			if ( isset( $r['is_outOfStock'] ) && in_array( $r['is_outOfStock'], array( 'y', 'yes', '1', 1, true ), true ) ) {
				$in['instock_only'] = true;
			}
			$in['variations'] = 'n' !== (string) ( $r['is_variations'] ?? 'y' );

			$res = PFD_Settings::put( '', $in );
			if ( ! is_wp_error( $res ) ) {
				++$added;
				$rel = self::uploads_rel( $ctx['url'] );
				if ( '' !== $rel ) {
					$paths[] = $rel;
				}
			}
		}
		return array( $added, $paths );
	}

	/** Category ids or slugs of CTX → term ids. */
	private static function cat_ids( array $cats ): array {
		$out = array();
		foreach ( $cats as $c ) {
			if ( is_numeric( $c ) ) {
				$out[] = (int) $c;
				continue;
			}
			$t = get_term_by( 'slug', sanitize_title( (string) $c ), 'product_cat' );
			if ( $t instanceof WP_Term ) {
				$out[] = (int) $t->term_id;
			}
		}
		return array_values( array_filter( array_unique( $out ) ) );
	}

	/** Path of a CTX file URL relative to uploads/, or '' (compared by URL path only). */
	private static function uploads_rel( string $url ): string {
		$up   = wp_upload_dir( null, false );
		$base = trailingslashit( (string) wp_parse_url( (string) $up['baseurl'], PHP_URL_PATH ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '/' === $base || 0 !== strpos( $path, $base ) ) {
			return '';
		}
		$rel = rawurldecode( (string) substr( $path, strlen( $base ) ) );
		return preg_match( '#^[A-Za-z0-9_\-./]+$#', $rel ) && false === strpos( $rel, '..' ) ? $rel : '';
	}
}

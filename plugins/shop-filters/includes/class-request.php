<?php
/**
 * SHF_Request — front-end gating, URL parameters and filter URLs.
 *
 *  - active(): enabled, not SHF_DISABLE, a front-end page request (never
 *    admin, AJAX, REST, feed, embed, cron, CLI or XML-RPC — Bible §14.4)
 *    and, in test mode, a shop manager.
 *  - selected(): the shf_{attribute} parameters, checked against a
 *    whitelist (attributes used in a filter set, tokens of the current
 *    options) and capped at max_values. Anything else is ignored.
 *  - URL helpers: the current listing URL without paging, with one
 *    attribute's tokens changed, or a category archive URL that keeps the
 *    other filters. Price uses WooCommerce's own min_price / max_price.
 */

defined( 'ABSPATH' ) || exit;

final class SHF_Request {

	const PARAM  = 'shf_';
	const ANCHOR = 'shf-results';

	/** Per-request caches. */
	private static $active   = null;
	private static $selected = null;

	/* =====================================================================
	 * Gating
	 * =================================================================== */

	public static function active(): bool {
		if ( null !== self::$active ) {
			return self::$active;
		}
		$s = SHF_Settings::get();
		$ok = ! empty( $s['enabled'] ) && ! Shop_Filters::killed() && self::is_page_request();
		if ( $ok && ! empty( $s['test_mode'] ) ) {
			if ( ! did_action( 'set_current_user' ) ) {
				return false; // Not cached: the user is not known yet.
			}
			$ok = current_user_can( 'manage_woocommerce' );
		}
		self::$active = $ok;
		return $ok;
	}

	/** A front-end page view (Bible §14.4). */
	public static function is_page_request(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( did_action( 'parse_query' ) && ( is_feed() || is_embed() ) ) {
			return false;
		}
		return true;
	}

	/** Product listing of the main query: shop, product category / tag / attribute archive. */
	public static function is_listing(): bool {
		return function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() );
	}

	/** Current product category archive (0 elsewhere). */
	public static function current_cat(): int {
		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$o = get_queried_object();
			return $o instanceof WP_Term ? (int) $o->term_id : 0;
		}
		return 0;
	}

	/* =====================================================================
	 * Parameters
	 * =================================================================== */

	/** Attributes used in at least one filter set (the parameter whitelist). */
	public static function allowed_attributes(): array {
		$out = array();
		foreach ( SHF_Settings::sets() as $set ) {
			foreach ( $set['filters'] as $f ) {
				if ( 'attr' === $f['type'] && SHF_Settings::is_attribute( $f['attr'] ) ) {
					$out[ $f['attr'] ] = true;
				}
			}
		}
		return array_keys( $out );
	}

	/**
	 * Selected tokens per attribute, validated.
	 *
	 * @return array<string, string[]> attribute → tokens (in URL order)
	 */
	public static function selected(): array {
		if ( null !== self::$selected ) {
			return self::$selected;
		}
		$max   = (int) SHF_Settings::get()['max_values'];
		$total = 0;
		$out   = array();
		foreach ( self::allowed_attributes() as $attr ) {
			$key = self::PARAM . $attr;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter parameters, whitelisted below.
			if ( ! isset( $_GET[ $key ] ) || ! is_string( $_GET[ $key ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tokens are matched against a whitelist.
			$raw     = substr( wp_unslash( $_GET[ $key ] ), 0, 1000 );
			$options = SHF_Groups::options( $attr );
			$tokens  = array();
			foreach ( explode( ',', $raw ) as $tok ) {
				$tok = strtolower( trim( $tok ) );
				if ( '' !== $tok && isset( $options[ $tok ] ) && ! in_array( $tok, $tokens, true ) && $total < $max ) {
					$tokens[] = $tok;
					++$total;
				}
			}
			if ( $tokens ) {
				$out[ $attr ] = $tokens;
			}
		}
		self::$selected = $out;
		return $out;
	}

	/**
	 * The price range in the URL (WooCommerce's own parameters).
	 *
	 * @return array{0:float,1:float}|null
	 */
	public static function price(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['min_price'] ) && ! isset( $_GET['max_price'] ) ) {
			return null;
		}
		$min = isset( $_GET['min_price'] ) && is_scalar( $_GET['min_price'] ) ? floatval( wp_unslash( $_GET['min_price'] ) ) : 0.0;
		$max = isset( $_GET['max_price'] ) && is_scalar( $_GET['max_price'] ) ? floatval( wp_unslash( $_GET['max_price'] ) ) : (float) PHP_INT_MAX;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return array( max( 0.0, $min ), max( 0.0, $max ) );
	}

	/** Any of our filters or a price range in the URL. */
	public static function has_filters(): bool {
		return (bool) self::selected() || null !== self::price();
	}

	/** Flush the per-request caches (tests). */
	public static function reset(): void {
		self::$active   = null;
		self::$selected = null;
	}

	/* =====================================================================
	 * URLs
	 * =================================================================== */

	/** Current listing URL without paging (query string kept). */
	public static function base_url(): string {
		$url = get_pagenum_link( 1, false );
		return remove_query_arg( array( 'paged', 'product-page' ), $url );
	}

	/** Filter parameters to carry over to another URL (ours + price + sorting). */
	public static function carried_args(): array {
		$args = array();
		foreach ( self::selected() as $attr => $tokens ) {
			$args[ self::PARAM . $attr ] = implode( ',', $tokens );
		}
		$p = self::price();
		if ( null !== $p ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['min_price'] ) ) {
				$args['min_price'] = self::num( $p[0] );
			}
			if ( isset( $_GET['max_price'] ) ) {
				$args['max_price'] = self::num( $p[1] );
			}
		}
		if ( isset( $_GET['orderby'] ) && is_string( $_GET['orderby'] ) ) {
			$args['orderby'] = sanitize_key( wp_unslash( $_GET['orderby'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return $args;
	}

	/** URL of the current listing with one attribute's tokens replaced. */
	public static function url_with( string $attr, array $tokens ): string {
		$url = self::base_url();
		$url = $tokens ? add_query_arg( self::PARAM . $attr, implode( ',', $tokens ), $url ) : remove_query_arg( self::PARAM . $attr, $url );
		return $url . '#' . self::ANCHOR;
	}

	/** URL that toggles one token of an attribute. */
	public static function url_toggle( string $attr, string $token ): string {
		$sel    = self::selected();
		$tokens = $sel[ $attr ] ?? array();
		$tokens = in_array( $token, $tokens, true ) ? array_values( array_diff( $tokens, array( $token ) ) ) : array_merge( $tokens, array( $token ) );
		return self::url_with( $attr, $tokens );
	}

	/** URL without the price range. */
	public static function url_without_price(): string {
		return remove_query_arg( array( 'min_price', 'max_price' ), self::base_url() ) . '#' . self::ANCHOR;
	}

	/** URL without any of our filters and without the price range. */
	public static function url_clear(): string {
		$keys = array( 'min_price', 'max_price' );
		foreach ( self::allowed_attributes() as $attr ) {
			$keys[] = self::PARAM . $attr;
		}
		return remove_query_arg( $keys, self::base_url() ) . '#' . self::ANCHOR;
	}

	/** Category archive URL (0 = shop page) with the other filters carried over. */
	public static function url_category( int $term_id ): string {
		$url = $term_id > 0 ? get_term_link( $term_id, 'product_cat' ) : wc_get_page_permalink( 'shop' );
		if ( is_wp_error( $url ) ) {
			return '';
		}
		return add_query_arg( self::carried_args(), (string) $url ) . '#' . self::ANCHOR;
	}

	/** Number as it should appear in a URL (no trailing .0). */
	public static function num( float $v ): string {
		return rtrim( rtrim( number_format( $v, 2, '.', '' ), '0' ), '.' );
	}
}

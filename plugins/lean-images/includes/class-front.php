<?php
/**
 * LIM_Front — serves the sidecars with <picture>.
 *
 *   <picture>
 *     <source type="image/avif" srcset="a.png.avif 300w, …" sizes="…">
 *     <source type="image/webp" srcset="a.png.webp 300w, …" sizes="…">
 *     <img … data-lim="1">          (the original tag, unchanged)
 *   </picture>
 *
 * The browser picks the first type it knows; old browsers keep the <img>.
 * The same HTML for every visitor, so page caches (Fast Pages, WP Rocket,
 * the host's) and Cloudflare stay correct: no Accept-based rewrite, no
 * Vary header, no .htaccess.
 *
 * Where: images printed by wp_get_attachment_image() (themes, WooCommerce,
 * blocks, the suite), post thumbnails, and images in content, excerpts
 * and widgets (after core added srcset / sizes / loading). With
 * «whole page» on, the final HTML of the page too, for images printed by
 * hand (page builders, theme options).
 *
 * A srcset candidate whose sidecar is missing (e.g. not smaller than its
 * source) keeps its original URL inside the <source>; a <source> is
 * printed only when at least one sidecar exists. Only uploads URLs of this
 * host, without a query string, are considered; every sidecar is checked
 * on disk first (a <source> that answers 404 shows a broken image).
 *
 * Never in admin, AJAX, REST, feeds, embeds, cron, CLI, XML-RPC, emails
 * (sent from admin / cron / AJAX requests), or when Imagify serves its
 * own <picture>. Test mode: administrators only. Errors return the
 * original HTML (Bible §14.6).
 */

defined( 'ABSPATH' ) || exit;

final class LIM_Front {

	/** Our template wrapper (WordPress before 6.9). */
	private static $template = '';

	/** Output buffer level of our buffer. */
	private static $level = 0;

	/** Per-request answer of active(). */
	private static $active = null;

	/** Formats to serve, per request. */
	private static $formats = null;

	public static function init(): void {
		if ( ! LIM_Settings::formats() ) {
			return;
		}
		add_filter( 'wp_get_attachment_image', array( __CLASS__, 'fragment' ), 20 );
		add_filter( 'post_thumbnail_html', array( __CLASS__, 'fragment' ), 20 );
		// Content: the whole filtered HTML, after core added srcset / sizes /
		// loading (wp_filter_content_tags), so an <img> that the author put
		// inside a <picture> is seen as such and left alone.
		foreach ( array( 'the_content', 'the_excerpt', 'widget_text_content', 'widget_block_content' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'fragment' ), 99 );
		}
		if ( LIM_Settings::get()['full_page'] ) {
			add_filter( 'template_include', array( __CLASS__, 'wrap' ), PHP_INT_MAX - 10 );
		}
	}

	/** True when this request gets <picture>. */
	public static function active(): bool {
		if ( null !== self::$active ) {
			return self::$active;
		}
		$on = true;
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			$on = false;
		} elseif ( did_action( 'parse_query' ) && ( is_feed() || is_embed() ) ) {
			$on = false;
		} elseif ( LIM_Tools::imagify_serves() ) {
			$on = false;
		} elseif ( LIM_Settings::get()['test'] && ! current_user_can( Lean_Images::CAP ) ) {
			$on = false;
		}
		/**
		 * Whether this request gets <picture> (e.g. false for an AMP page).
		 *
		 * @param bool $on
		 */
		$on = (bool) apply_filters( 'lim_active', $on );
		// Cache only once the user and the query are known.
		if ( did_action( 'wp' ) ) {
			self::$active = $on;
		}
		return $on;
	}

	/** Formats to serve that the server could make. */
	private static function formats(): array {
		if ( null === self::$formats ) {
			self::$formats = LIM_Settings::formats();
		}
		return self::$formats;
	}

	/* =====================================================================
	 * Filters
	 * =================================================================== */

	/** An HTML fragment (attachment image, thumbnail). */
	public static function fragment( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<img' ) ) {
			return $html;
		}
		try {
			return self::active() ? self::html( $html ) : $html;
		} catch ( \Throwable $e ) {
			return $html;
		}
	}

	/**
	 * Rewrites every <img> of $html that is not ours yet and not inside a
	 * <picture>, <noscript>, <script>, <style>, <textarea> or a comment.
	 */
	public static function html( string $html ): string {
		$parts = preg_split( '#(<!--.*?-->|<picture\b.*?</picture\s*>|<noscript\b.*?</noscript\s*>|<script\b.*?</script\s*>|<style\b.*?</style\s*>|<textarea\b.*?</textarea\s*>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return $html;
		}
		foreach ( $parts as $i => $part ) {
			if ( 1 === $i % 2 || false === stripos( $part, '<img' ) ) {
				continue;
			}
			$new = preg_replace_callback(
				'#<img\b[^>]*>#i',
				static function ( $m ) {
					return false !== strpos( $m[0], 'data-lim' ) ? $m[0] : LIM_Front::picture( $m[0] );
				},
				$part
			);
			if ( is_string( $new ) ) {
				$parts[ $i ] = $new;
			}
		}
		return implode( '', $parts );
	}

	/** One <img> tag → <picture> (or the tag unchanged). */
	public static function picture( string $tag ): string {
		$src    = self::attr( $tag, 'src' );
		$srcset = self::attr( $tag, 'srcset' );
		$sizes  = self::attr( $tag, 'sizes' );
		if ( '' === $src && '' === $srcset ) {
			return $tag;
		}
		$cands = array();
		if ( '' !== $srcset ) {
			foreach ( explode( ',', $srcset ) as $c ) {
				$c = trim( $c );
				if ( '' === $c ) {
					continue;
				}
				$bits    = preg_split( '/\s+/', $c, 2 );
				$cands[] = array( $bits[0], isset( $bits[1] ) ? trim( $bits[1] ) : '' );
			}
		} else {
			$cands[] = array( $src, '' );
		}
		if ( ! $cands ) {
			return $tag;
		}

		$sources = '';
		foreach ( self::formats() as $fmt ) {
			$list = array();
			$hits = 0;
			foreach ( $cands as $c ) {
				$url = html_entity_decode( $c[0], ENT_QUOTES );
				if ( LIM_Files::has( $url, $fmt ) ) {
					++$hits;
					$url .= '.' . $fmt;
				}
				$list[] = $url . ( '' !== $c[1] ? ' ' . $c[1] : '' );
			}
			if ( 0 === $hits ) {
				continue;
			}
			$sources .= '<source type="' . esc_attr( LIM_Convert::mime( $fmt ) ) . '" srcset="' . esc_attr( implode( ', ', $list ) ) . '"' . ( '' !== $sizes && '' !== $srcset ? ' sizes="' . esc_attr( html_entity_decode( $sizes, ENT_QUOTES ) ) . '"' : '' ) . '>';
		}
		if ( '' === $sources ) {
			return $tag;
		}
		$img = preg_replace( '#^<img\b#i', '<img data-lim="1"', $tag, 1 );
		return '<picture>' . $sources . ( is_string( $img ) ? $img : $tag ) . '</picture>';
	}

	/** Value of an attribute of a tag (raw, still HTML-encoded), or ''. */
	private static function attr( string $tag, string $name ): string {
		if ( preg_match( '#\s' . preg_quote( $name, '#' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $tag, $m ) ) {
			foreach ( array( 1, 2, 3 ) as $k ) {
				if ( isset( $m[ $k ] ) && '' !== $m[ $k ] ) {
					return $m[ $k ];
				}
			}
		}
		return '';
	}

	/* =====================================================================
	 * Whole page (optional)
	 * =================================================================== */

	public static function wrap( $template ) {
		try {
			if ( ! is_string( $template ) || '' === $template || ! self::active() ) {
				return $template;
			}
			if ( function_exists( 'wp_finalize_template_enhancement_output_buffer' ) && apply_filters( 'wp_should_output_buffer_template_for_enhancement', true ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core filter.
				// Before Fast Pages (PHP_INT_MAX), so the cached page has it.
				add_filter( 'wp_template_enhancement_output_buffer', array( __CLASS__, 'page' ), 10 );
				return $template;
			}
			self::$template = $template;
			return LIM_PATH . 'includes/template.php';
		} catch ( \Throwable $e ) {
			return $template;
		}
	}

	/** The theme's template, for includes/template.php. */
	public static function template(): string {
		return self::$template;
	}

	public static function begin(): void {
		ob_start();
		self::$level = ob_get_level();
	}

	public static function end(): void {
		// Close only our own buffer, and only when it is the top one (§14.7).
		if ( self::$level <= 0 || ob_get_level() !== self::$level ) {
			return;
		}
		$html = (string) ob_get_clean();
		echo self::page( $html ); // phpcs:ignore WordPress.Security.EscapeOutput -- the page's own HTML.
	}

	/** The whole page (pure string in, string out). */
	public static function page( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<img' ) ) {
			return $html;
		}
		try {
			return self::html( $html );
		} catch ( \Throwable $e ) {
			return $html;
		}
	}
}

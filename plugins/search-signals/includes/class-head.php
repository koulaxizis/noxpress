<?php
/**
 * SSG_Head — the front-end wiring of the meta, schema and social modules.
 *
 * Hooks are added on «wp» (priority 99), after the main query and the
 * current user are known, and only when Search_Signals::serving():
 *  - meta:   pre_get_document_title (the <title> of the theme), wp_robots
 *            (priority 10, so Shop Filters / Hide Catalog at 20 still win),
 *            the core rel_canonical replaced, meta description,
 *            prev / next, the WooCommerce generator tag removed
 *  - social: Open Graph and Twitter
 *  - schema: one JSON-LD graph; WooCommerce's own JSON-LD in the footer
 *            is removed, so the Product is printed once
 * Everything is printed at wp_head priority 1, in one block.
 */

defined( 'ABSPATH' ) || exit;

final class SSG_Head {

	public static function init(): void {
		add_action( 'wp', array( __CLASS__, 'setup' ), 99 );
	}

	public static function setup(): void {
		try {
			if ( ! Search_Signals::serving() || is_feed() || is_embed() || is_robots() || is_trackback() ) {
				return;
			}
			if ( SSG_Settings::module( 'meta' ) ) {
				add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ), 99 );
				add_filter( 'wp_robots', array( __CLASS__, 'wp_robots' ), 10 );
				remove_action( 'wp_head', 'rel_canonical' );
				remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );
				if ( SSG_Settings::get()['wc_generator'] ) {
					remove_filter( 'get_the_generator_html', 'wc_generator_tag', 10 );
					remove_filter( 'get_the_generator_xhtml', 'wc_generator_tag', 10 );
				}
			}
			if ( SSG_Settings::module( 'schema' ) && function_exists( 'WC' ) ) {
				$wc = WC();
				if ( isset( $wc->structured_data ) && is_object( $wc->structured_data ) ) {
					remove_action( 'wp_footer', array( $wc->structured_data, 'output_structured_data' ), 10 );
				}
			}
			add_action( 'wp_head', array( __CLASS__, 'print_head' ), 1 );

			/**
			 * Search Signals prints the SEO tags of this request. Plugins
			 * that would print their own canonical or JSON-LD skip it.
			 */
			do_action( 'noxpress_seo_active' );
		} catch ( \Throwable $e ) {
			Search_Signals::fail( $e );
		}
	}

	/** @param string $title */
	public static function document_title( $title ) {
		if ( Search_Signals::broken() ) {
			return $title;
		}
		try {
			$t = SSG_Meta::title( SSG_Context::current() );
			return '' !== $t ? esc_html( $t ) : $title;
		} catch ( \Throwable $e ) {
			Search_Signals::fail( $e );
			return $title;
		}
	}

	/** @param array $robots */
	public static function wp_robots( $robots ) {
		if ( Search_Signals::broken() || ! is_array( $robots ) || '0' === (string) get_option( 'blog_public' ) ) {
			return $robots;
		}
		try {
			$r = SSG_Meta::robots( SSG_Context::current() );
			if ( $r['noindex'] ) {
				unset( $robots['index'], $robots['max-snippet'], $robots['max-video-preview'] );
				$robots['noindex'] = true;
			} else {
				$robots['index']             = true;
				$robots['max-snippet']       = '-1';
				$robots['max-video-preview'] = '-1';
				$robots['max-image-preview'] = 'large';
			}
			if ( $r['nofollow'] ) {
				unset( $robots['follow'] );
				$robots['nofollow'] = true;
			} else {
				$robots['follow'] = true;
			}
			return $robots;
		} catch ( \Throwable $e ) {
			Search_Signals::fail( $e );
			return $robots;
		}
	}

	public static function print_head(): void {
		if ( Search_Signals::broken() ) {
			return;
		}
		try {
			echo self::html( SSG_Context::current() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in html().
		} catch ( \Throwable $e ) {
			Search_Signals::fail( $e );
		}
	}

	/** The whole block for a context (also used by Compare). */
	public static function html( SSG_Context $c ): string {
		$out = "\n<!-- Search Signals " . esc_html( SSG_VERSION ) . " -->\n";
		if ( SSG_Settings::module( 'meta' ) ) {
			$desc = SSG_Meta::description( $c );
			if ( '' !== $desc ) {
				$out .= '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
			}
			$can = SSG_Meta::canonical( $c );
			if ( '' !== $can && ! SSG_Meta::robots( $c )['noindex'] ) {
				$out .= '<link rel="canonical" href="' . esc_url( $can ) . '" />' . "\n";
			}
			list( $prev, $next ) = SSG_Meta::prev_next( $c );
			if ( '' !== $prev ) {
				$out .= '<link rel="prev" href="' . esc_url( $prev ) . '" />' . "\n";
			}
			if ( '' !== $next ) {
				$out .= '<link rel="next" href="' . esc_url( $next ) . '" />' . "\n";
			}
		}
		if ( SSG_Settings::module( 'social' ) ) {
			foreach ( SSG_Social::tags( $c ) as $t ) {
				if ( ! is_array( $t ) || 3 !== count( $t ) || '' === (string) $t[2] ) {
					continue;
				}
				$attr = 'name' === $t[0] ? 'name' : 'property';
				$val  = in_array( (string) $t[1], array( 'og:url', 'og:image', 'og:image:secure_url', 'twitter:image' ), true ) ? esc_url( (string) $t[2] ) : esc_attr( (string) $t[2] );
				$out .= '<meta ' . $attr . '="' . esc_attr( (string) $t[1] ) . '" content="' . $val . '" />' . "\n";
			}
		}
		if ( SSG_Settings::module( 'schema' ) ) {
			$json = SSG_Schema::json( SSG_Schema::build( $c ) );
			if ( '' !== $json ) {
				$out .= '<script type="application/ld+json" class="ssg-schema">' . $json . '</script>' . "\n";
			}
		}
		return $out . "<!-- /Search Signals -->\n\n";
	}
}

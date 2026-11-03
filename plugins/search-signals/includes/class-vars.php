<?php
/**
 * SSG_Vars — templates with %variables% (the same names as Rank Math, so
 * that imported templates keep working).
 *
 *  %title%            title of the post / page / product
 *  %sep%              the separator of the settings
 *  %sitename%         site title (Settings → General)
 *  %sitedesc%         tagline
 *  %page%             «Page 2 of 5» from page 2 on, '' on page 1
 *  %excerpt%          excerpt (products: short description), else the
 *                     text of the content, cut at a word up to 160 chars
 *  %term%             name of the term
 *  %term_description% description of the term (plain text)
 *  %search_query%     the search
 *  %name%             display name of the author (author archives)
 *  %date%             date of a date archive
 *  %pt_plural%        plural name of the post type (archives)
 *  %pt_single%        singular name of the post type
 *  %currentyear%      this year
 *  %wc_price%         price of the product, as text
 *  %wc_brand%         primary brand of the product
 *  %wc_sku%           SKU of the product
 *
 * An unknown %variable% (e.g. from an imported Rank Math template) is
 * dropped from the output, never printed raw. Values are plain text
 * (entities decoded): the caller escapes on output.
 *
 * Deviation (§14.17, visitor texts as settings): «Page 2 of 5» follows the
 * locale of the site (Greek for el*, English otherwise), as the theme's
 * own pagination does; filter ssg_page_text for anything else.
 */

defined( 'ABSPATH' ) || exit;

final class SSG_Vars {

	const KNOWN = array( 'title', 'sep', 'sitename', 'sitedesc', 'page', 'excerpt', 'term', 'term_description', 'search_query', 'name', 'date', 'pt_plural', 'pt_single', 'currentyear', 'wc_price', 'wc_brand', 'wc_sku' );

	const EXCERPT = 160;

	public static function render( string $tpl, SSG_Context $c ): string {
		if ( '' === trim( $tpl ) ) {
			return '';
		}
		$sep = SSG_Settings::get()['sep'];
		$out = preg_replace_callback(
			'/%([a-z_]+)(?:\([^%)]*\))?%/',
			static function ( $m ) use ( $c ) {
				return 'sep' === $m[1] ? "\x01" : self::value( (string) $m[1], $c );
			},
			$tpl
		);
		$out = self::plain( (string) $out );
		return self::tidy( $out, $sep );
	}

	/** One variable; '' for an unknown one. */
	public static function value( string $name, SSG_Context $c ): string {
		switch ( $name ) {
			case 'sitename':
				return self::plain( (string) get_bloginfo( 'name' ) );
			case 'sitedesc':
				return self::plain( (string) get_bloginfo( 'description' ) );
			case 'currentyear':
				return wp_date( 'Y' );
			case 'page':
				return self::page_text( $c );
			case 'title':
				return $c->has_post() ? self::plain( (string) get_the_title( $c->post_id ) ) : '';
			case 'excerpt':
				return $c->has_post() ? self::excerpt( $c->post_id ) : '';
			case 'term':
				if ( 'term' === $c->kind ) {
					$t = get_term( $c->term_id, $c->taxonomy );
					return $t instanceof WP_Term ? self::plain( $t->name ) : '';
				}
				return '';
			case 'term_description':
				return 'term' === $c->kind ? self::cut( self::plain( (string) term_description( $c->term_id ) ), self::EXCERPT ) : '';
			case 'search_query':
				return 'search' === $c->kind ? self::plain( $c->search ) : '';
			case 'name':
				if ( 'author' === $c->kind && $c->author_id ) {
					$u = get_userdata( $c->author_id );
					return $u ? self::plain( (string) $u->display_name ) : '';
				}
				return '';
			case 'date':
				return self::date_text( $c );
			case 'pt_plural':
			case 'pt_single':
				$pt  = '' !== $c->post_type ? $c->post_type : 'post';
				$obj = get_post_type_object( $pt );
				if ( ! $obj ) {
					return '';
				}
				return self::plain( 'pt_plural' === $name ? (string) $obj->labels->name : (string) $obj->labels->singular_name );
			case 'wc_price':
			case 'wc_brand':
			case 'wc_sku':
				return self::product_value( $name, $c );
		}
		return '';
	}

	private static function product_value( string $name, SSG_Context $c ): string {
		if ( 'singular' !== $c->kind || 'product' !== $c->post_type || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$p = wc_get_product( $c->post_id );
		if ( ! $p instanceof WC_Product ) {
			return '';
		}
		if ( 'wc_sku' === $name ) {
			return self::plain( (string) $p->get_sku() );
		}
		if ( 'wc_brand' === $name ) {
			$b = SSG_Meta::primary_term( $c->post_id, 'product_brand' );
			return $b ? self::plain( $b->name ) : '';
		}
		$price = $p->get_price();
		return '' === (string) $price ? '' : self::plain( wc_price( wc_get_price_to_display( $p ) ) );
	}

	/** The excerpt of a post, or the text of its content, cut at a word. */
	public static function excerpt( int $id, int $max = self::EXCERPT ): string {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || post_password_required( $post ) ) {
			return '';
		}
		$text = (string) $post->post_excerpt;
		if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
			$text = (string) $post->post_content;
		}
		if ( function_exists( 'excerpt_remove_blocks' ) ) {
			$text = excerpt_remove_blocks( $text );
		}
		$text = strip_shortcodes( $text );
		// Leftover shortcodes of plugins that are not active.
		$text = (string) preg_replace( '/\[\/?[a-z0-9_\-]+[^\]]*\]/i', ' ', $text );
		return self::cut( self::plain( $text ), $max );
	}

	/** «Page 2 of 5», only from page 2 on. */
	public static function page_text( SSG_Context $c ): string {
		if ( $c->page < 2 ) {
			return '';
		}
		$fmt = 0 === strpos( (string) get_locale(), 'el' ) ? 'Σελίδα %1$d από %2$d' : 'Page %1$d of %2$d';
		$txt = sprintf( $fmt, $c->page, max( $c->page, $c->max_pages ) );
		/**
		 * Text of %page% (visitors see it in titles).
		 *
		 * @param string $txt
		 * @param int    $page
		 * @param int    $max
		 */
		return (string) apply_filters( 'ssg_page_text', $txt, $c->page, $c->max_pages );
	}

	private static function date_text( SSG_Context $c ): string {
		// On a post, page or product: its publish date (as in Rank Math).
		if ( $c->has_post() ) {
			$d = get_the_date( '', $c->post_id );
			return is_string( $d ) ? self::plain( $d ) : '';
		}
		if ( 'date' !== $c->kind || '' === $c->date ) {
			return '';
		}
		$parts = explode( '-', $c->date );
		$ts    = strtotime( $c->date . ( 1 === count( $parts ) ? '-01-01' : ( 2 === count( $parts ) ? '-01' : '' ) ) . ' 12:00:00 UTC' );
		if ( ! $ts ) {
			return '';
		}
		$fmt = 1 === count( $parts ) ? 'Y' : ( 2 === count( $parts ) ? 'F Y' : (string) get_option( 'date_format' ) );
		return self::plain( wp_date( $fmt, $ts ) );
	}

	/** Plain text: no tags, entities decoded, one space between words. */
	public static function plain( string $s ): string {
		$s = wp_strip_all_tags( $s, true );
		$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$s = str_replace( "\xC2\xA0", ' ', $s );
		return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
	}

	/** Cut at a word, up to $max characters (no «…» added). */
	public static function cut( string $s, int $max ): string {
		if ( mb_strlen( $s ) <= $max ) {
			return $s;
		}
		$cut = mb_substr( $s, 0, $max + 1 );
		$sp  = mb_strrpos( $cut, ' ' );
		if ( false !== $sp && $sp > $max * 0.6 ) {
			$cut = mb_substr( $cut, 0, $sp );
		} else {
			$cut = mb_substr( $s, 0, $max );
		}
		return rtrim( $cut, " \t,;:-–—" );
	}

	/**
	 * Spaces and separators after the variables were filled: an empty
	 * variable leaves no double space and no separator at either end or
	 * twice in a row. \x01 marks a %sep% slot.
	 */
	private static function tidy( string $s, string $sep ): string {
		$s = (string) preg_replace( '/\s+/u', ' ', $s );
		// Separators with nothing but spaces between them collapse to one.
		$s = (string) preg_replace( '/\x01(\s*\x01)+/', "\x01", $s );
		$s = trim( $s );
		$s = trim( $s, " \x01" );
		$s = str_replace( "\x01", $sep, $s );
		return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
	}

	/** Unknown %variables% in a template (for the admin warnings). */
	public static function unknown( string $tpl ): array {
		$out = array();
		if ( preg_match_all( '/%([a-z_]+)(?:\([^%)]*\))?%/', $tpl, $m ) ) {
			foreach ( $m[1] as $v ) {
				if ( ! in_array( $v, self::KNOWN, true ) && ! in_array( $v, $out, true ) ) {
					$out[] = $v;
				}
			}
		}
		return $out;
	}
}

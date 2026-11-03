<?php
/**
 * SSG_Social — Open Graph and Twitter tags of a context (pure: tags()
 * returns them, SSG_Head prints them).
 *
 * Image, in this order: the share image of the object (_ssg_og_image),
 * its featured image (terms: their thumbnail), the first image of the
 * content, the default image of the settings (else the logo).
 */

defined( 'ABSPATH' ) || exit;

final class SSG_Social {

	/**
	 * @return array List of [ 'property'|'name', key, value ].
	 */
	public static function tags( SSG_Context $c ): array {
		if ( in_array( $c->kind, array( '404', 'other' ), true ) ) {
			return array();
		}
		$s     = SSG_Settings::get();
		$title = SSG_Meta::title( $c );
		$desc  = SSG_Meta::description( $c );
		$url   = SSG_Meta::canonical( $c );
		$type  = 'website';
		if ( 'singular' === $c->kind ) {
			$type = 'product' === $c->post_type ? 'product' : 'article';
		}

		$t   = array();
		$t[] = array( 'property', 'og:locale', (string) get_locale() );
		$t[] = array( 'property', 'og:type', $type );
		$t[] = array( 'property', 'og:title', $title );
		if ( '' !== $desc ) {
			$t[] = array( 'property', 'og:description', $desc );
		}
		if ( '' !== $url ) {
			$t[] = array( 'property', 'og:url', $url );
		}
		$t[] = array( 'property', 'og:site_name', SSG_Settings::org_name() );

		$post = $c->has_post() ? get_post( $c->post_id ) : null;
		if ( $post instanceof WP_Post ) {
			$t[] = array( 'property', 'og:updated_time', get_post_modified_time( 'c', false, $post ) );
		}

		$img = self::image( $c );
		if ( $img ) {
			$t[] = array( 'property', 'og:image', $img['url'] );
			if ( 0 === strpos( $img['url'], 'https://' ) ) {
				$t[] = array( 'property', 'og:image:secure_url', $img['url'] );
			}
			if ( $img['w'] && $img['h'] ) {
				$t[] = array( 'property', 'og:image:width', (string) $img['w'] );
				$t[] = array( 'property', 'og:image:height', (string) $img['h'] );
			}
			if ( '' !== $img['alt'] ) {
				$t[] = array( 'property', 'og:image:alt', $img['alt'] );
			}
			if ( '' !== $img['mime'] ) {
				$t[] = array( 'property', 'og:image:type', $img['mime'] );
			}
		}

		if ( $post instanceof WP_Post && 'article' === $type ) {
			$t[] = array( 'property', 'article:published_time', get_post_time( 'c', false, $post ) );
			$t[] = array( 'property', 'article:modified_time', get_post_modified_time( 'c', false, $post ) );
		}
		if ( 'product' === $type ) {
			$t = array_merge( $t, self::product( $c->post_id ) );
		}

		$t[] = array( 'name', 'twitter:card', 'summary_large_image' );
		$t[] = array( 'name', 'twitter:title', $title );
		if ( '' !== $desc ) {
			$t[] = array( 'name', 'twitter:description', $desc );
		}
		if ( '' !== $s['twitter'] ) {
			$t[] = array( 'name', 'twitter:site', $s['twitter'] );
			$t[] = array( 'name', 'twitter:creator', $s['twitter'] );
		}
		if ( $img ) {
			$t[] = array( 'name', 'twitter:image', $img['url'] );
		}

		/**
		 * Open Graph / Twitter tags before they are printed.
		 *
		 * @param array       $t [ [ 'property'|'name', key, value ], … ]
		 * @param SSG_Context $c
		 */
		$t = apply_filters( 'ssg_social_tags', $t, $c );
		return is_array( $t ) ? $t : array();
	}

	/** [ url, w, h, alt, mime ] of the share image, or []. */
	public static function image( SSG_Context $c ): array {
		$id = 0;
		if ( $c->has_post() ) {
			$id = (int) get_post_meta( $c->post_id, SSG_Meta::IMAGE, true );
			if ( ! $id ) {
				$id = (int) get_post_thumbnail_id( $c->post_id );
			}
			if ( ! $id ) {
				$first = self::first_content_image( $c->post_id );
				if ( '' !== $first ) {
					return array(
						'url'  => $first,
						'w'    => 0,
						'h'    => 0,
						'alt'  => '',
						'mime' => '',
					);
				}
			}
		} elseif ( 'term' === $c->kind ) {
			$id = (int) get_term_meta( $c->term_id, SSG_Meta::IMAGE, true );
			if ( ! $id ) {
				$id = (int) get_term_meta( $c->term_id, 'thumbnail_id', true );
			}
		}
		if ( ! $id ) {
			$s  = SSG_Settings::get();
			$id = $s['image'] ? (int) $s['image'] : (int) $s['org']['logo'];
		}
		if ( ! $id ) {
			return array();
		}
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( ! is_array( $src ) || empty( $src[0] ) ) {
			return array();
		}
		return array(
			'url'  => (string) $src[0],
			'w'    => (int) $src[1],
			'h'    => (int) $src[2],
			'alt'  => trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
			'mime' => (string) get_post_mime_type( $id ),
		);
	}

	/** First <img src> of the content of a post, when it is on this site. */
	private static function first_content_image( int $id ): string {
		$content = (string) get_post_field( 'post_content', $id );
		if ( '' === $content || ! preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m ) ) {
			return '';
		}
		$src  = esc_url_raw( html_entity_decode( $m[1], ENT_QUOTES ) );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return '' !== $src && wp_parse_url( $src, PHP_URL_HOST ) === $host ? $src : '';
	}

	/** product:* tags. */
	private static function product( int $id ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}
		$p = wc_get_product( $id );
		if ( ! $p instanceof WC_Product ) {
			return array();
		}
		$t     = array();
		$price = wc_get_price_to_display( $p );
		if ( '' !== (string) $p->get_price() ) {
			$t[] = array( 'property', 'product:price:amount', wc_format_decimal( $price, wc_get_price_decimals() ) );
			$t[] = array( 'property', 'product:price:currency', get_woocommerce_currency() );
		}
		$status = $p->get_stock_status();
		$avail  = 'instock' === $status ? 'instock' : ( 'onbackorder' === $status ? 'available for order' : 'out of stock' );
		$t[]    = array( 'property', 'product:availability', $avail );
		$brand  = SSG_Meta::primary_term( $id, 'product_brand' );
		if ( $brand ) {
			$t[] = array( 'property', 'product:brand', SSG_Vars::plain( $brand->name ) );
		}
		if ( '' !== (string) $p->get_sku() ) {
			$t[] = array( 'property', 'product:retailer_item_id', (string) $p->get_sku() );
		}
		return $t;
	}
}

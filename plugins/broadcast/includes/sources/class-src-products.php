<?php
/**
 * BRC_Src_Products — module "products" (WooCommerce): new products.
 *
 * A product that becomes "publish" (from draft, pending, private or a
 * scheduled date) is checked p_delay minutes later in the background, so
 * edits made right after publishing are in the post. Updates of products
 * that were already published are never posted, and a product is posted
 * once (dedupe key p:<id>), even if it is unpublished and published again.
 *
 * Filters: categories in / out (with their subcategories), product types,
 * only with an image, password-protected and catalog-hidden products out.
 *
 * Cooperation (Bible §6), each only when the plugin is there:
 *  - Book Feed: bkf_get_source() — other publishers' titles are skipped by
 *    default (they would flood the channel; the digest module covers them).
 *    A product Book Feed only linked (it existed with the same ISBN) is the
 *    shop's own and is posted.
 *  - Hide Catalog: hic_hidden_terms( 'listings' ) — categories hidden from
 *    the shop are skipped by default.
 *  - Pre-Orders: por_get_release() fills {preorder}.
 */

defined( 'ABSPATH' ) || exit;

final class BRC_Src_Products {

	public static function init(): void {
		add_action( 'transition_post_status', array( __CLASS__, 'transition' ), 10, 3 );
	}

	public static function transition( $new, $old, $post ): void {
		if ( 'publish' !== $new || 'publish' === $old || ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
			return;
		}
		if ( BRC_Queue::known( 'products', 'p:' . $post->ID ) ) {
			return;
		}
		BRC_Jobs::later_product( (int) $post->ID, BRC_Settings::get()['p_delay'] * MINUTE_IN_SECONDS );
	}

	/** Category ids of a product with all their parents. */
	private static function cats( WC_Product $p ): array {
		$ids = array_map( 'intval', $p->get_category_ids() );
		foreach ( $ids as $id ) {
			$ids = array_merge( $ids, array_map( 'intval', get_ancestors( $id, 'product_cat', 'taxonomy' ) ) );
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Why a product is not posted, or '' when it is.
	 *
	 * @param WC_Product $p Product.
	 */
	public static function skip_reason( WC_Product $p ): string {
		$s = BRC_Settings::get();
		if ( 'publish' !== $p->get_status() ) {
			return 'status';
		}
		if ( '' !== (string) $p->get_post_password() ) {
			return 'password';
		}
		if ( 'hidden' === $p->get_catalog_visibility() ) {
			return 'hidden';
		}
		if ( $s['p_types'] && ! in_array( $p->get_type(), $s['p_types'], true ) ) {
			return 'type';
		}
		$cats = self::cats( $p );
		if ( $s['p_cats_in'] && ! array_intersect( $cats, $s['p_cats_in'] ) ) {
			return 'category';
		}
		if ( $s['p_cats_ex'] && array_intersect( $cats, $s['p_cats_ex'] ) ) {
			return 'category';
		}
		if ( $s['p_image'] && ! $p->get_image_id() ) {
			return 'image';
		}
		if ( $s['p_skip_bf'] && function_exists( 'bkf_get_source' ) ) {
			$src = bkf_get_source( $p->get_id() );
			if ( is_array( $src ) && empty( $src['linked'] ) ) {
				return 'book_feed';
			}
		}
		if ( $s['p_skip_hidden'] && function_exists( 'hic_hidden_terms' ) ) {
			$hidden = hic_hidden_terms( 'listings' );
			if ( $hidden && array_intersect( $cats, $hidden ) ) {
				return 'hide_catalog';
			}
		}
		return '';
	}

	/** Plain-text price ("15,00 €", "από 12,00 €"), or ''. */
	private static function price( WC_Product $p ): string {
		$el = 'el' === BRC_Lang::from_locale( (string) get_locale() );
		if ( $p->is_type( 'variable' ) && is_callable( array( $p, 'get_variation_price' ) ) ) {
			$min = $p->get_variation_price( 'min', true );
			$max = $p->get_variation_price( 'max', true );
			if ( '' === (string) $min ) {
				return '';
			}
			$txt = self::plain( wc_price( (float) $min ) );
			return (float) $min === (float) $max ? $txt : ( $el ? 'από ' : 'from ' ) . $txt;
		}
		$price = $p->get_price();
		return '' === (string) $price ? '' : self::plain( wc_price( (float) wc_get_price_to_display( $p ) ) );
	}

	private static function plain( string $html ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
	}

	/** " · Προπαραγγελία, κυκλοφορεί 12/11/2026" or ''. */
	private static function preorder( WC_Product $p ): string {
		if ( ! function_exists( 'por_get_release' ) ) {
			return '';
		}
		$r = por_get_release( $p );
		if ( ! is_array( $r ) || empty( $r['active'] ) ) {
			return '';
		}
		$el   = 'el' === BRC_Lang::from_locale( (string) get_locale() );
		$text = $el ? 'Προπαραγγελία' : 'Pre-order';
		if ( ! empty( $r['date'] ) && is_string( $r['date'] ) ) {
			$t = strtotime( $r['date'] . ' 12:00:00' );
			if ( $t ) {
				$text .= ( $el ? ', κυκλοφορεί ' : ', out ' ) . wp_date( get_option( 'date_format' ), $t );
			}
		}
		return ' · ' . $text;
	}

	/** The neutral message of a product. */
	public static function message( WC_Product $p ): array {
		$s     = BRC_Settings::get();
		$img   = $p->get_image_id() ? wp_get_attachment_image_url( (int) $p->get_image_id(), 'large' ) : '';
		$terms = get_the_terms( $p->get_id(), 'product_cat' );
		$cat   = is_array( $terms ) && $terms ? $terms[0]->name : '';
		$url   = (string) get_permalink( $p->get_id() );
		$text  = BRC_Template::render(
			$s['tpl_products'],
			array(
				'title'    => $p->get_name(),
				'url'      => $url,
				'price'    => self::price( $p ),
				'preorder' => self::preorder( $p ),
				'excerpt'  => wp_trim_words( wp_strip_all_tags( (string) $p->get_short_description() ), 30, '…' ),
				'category' => wp_specialchars_decode( (string) $cat, ENT_QUOTES ),
			)
		);
		return array(
			'title' => $p->get_name(),
			'text'  => $text,
			'url'   => $url,
			'image' => is_string( $img ) ? $img : '',
			'kind'  => $img ? 'picture' : 'text',
		);
	}

	/** Background check of a product published p_delay minutes ago (BRC_Jobs). */
	public static function check( int $product_id ): void {
		$p = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $p instanceof WC_Product || $p->get_parent_id() ) {
			return;
		}
		if ( '' !== self::skip_reason( $p ) ) {
			return;
		}
		BRC_Queue::add( 'products', 'p:' . $product_id, self::message( $p ) );
	}
}

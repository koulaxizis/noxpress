<?php
/**
 * PFD_Item — one WooCommerce product → normalised feed items.
 *
 * filter() decides whether a product belongs in a feed (a reason code
 * when it does not). items() returns one item for a simple product, and
 * for a variable product the parent item with its in-feed variations in
 * 'vars' (each a full item). Writers decide whether variations become rows
 * of their own (Google, Meta, CSV) or children of one product (Skroutz,
 * BestPrice).
 *
 * Item keys: id, group, title, desc, link, image, images[], price (with
 * VAT), sale, sale_from, sale_to ('Y-m-d' local or ''), avail (in_stock |
 * out_of_stock | backorder | preorder), avail_date ('Y-m-d' or ''), qty
 * (int|null), gtin, isbn, mpn, brand, author, cat_path, cat_id, cat_ids[],
 * weight (grams, 0 = unknown), color, size, vat (rate %), ship, gcat.
 *
 * Prices are WooCommerce's real prices (never a display text of Smart
 * Formatter / Theme Patcher, Bible §14), with VAT for the store's base
 * location.
 */

defined( 'ABSPATH' ) || exit;

final class PFD_Item {

	/** @var array Per-run caches. */
	private static $hidden = null;
	private static $paths  = array();

	public static function reset(): void {
		self::$hidden = null;
		self::$paths  = array();
	}

	/**
	 * Why a product stays out of a feed, or '' when it belongs.
	 */
	public static function filter( WC_Product $p, array $f ): string {
		if ( 'publish' !== $p->get_status() || '' !== (string) get_post_field( 'post_password', $p->get_id() ) ) {
			return 'not_public';
		}
		if ( 'hidden' === $p->get_catalog_visibility() ) {
			return 'not_public';
		}
		if ( in_array( $p->get_type(), array( 'grouped', 'external' ), true ) ) {
			return 'type';
		}
		$pid  = (int) $p->get_id();
		$cats = self::cat_ids_with_parents( $pid );
		if ( $f['cats_in'] && ! array_intersect( $cats, $f['cats_in'] ) ) {
			return 'category';
		}
		if ( $f['cats_out'] && array_intersect( $cats, $f['cats_out'] ) ) {
			return 'category';
		}
		if ( $f['tags_out'] ) {
			$tags = wp_get_post_terms( $pid, 'product_tag', array( 'fields' => 'ids' ) );
			if ( is_array( $tags ) && array_intersect( array_map( 'intval', $tags ), $f['tags_out'] ) ) {
				return 'tag';
			}
		}
		$imported = self::imported( $pid );
		if ( $imported && ! $f['bookfeed'] ) {
			return 'bookfeed';
		}
		// The Book Feed switch wins over Hide Catalog: a shop that hides its
		// third-party titles from the shop page may still list them here.
		if ( ! $f['hidden'] && ! ( $imported && $f['bookfeed'] ) && self::hidden( $cats ) ) {
			return 'hidden';
		}
		if ( ! $f['digital'] && ( $p->is_downloadable() || $p->is_virtual() ) ) {
			return 'digital';
		}
		if ( 'variable' !== $p->get_type() && $f['instock_only'] && 'out_of_stock' === self::avail( $p ) ) {
			return 'out_of_stock';
		}
		return '';
	}

	/** A Book Feed import (not a product of the shop that was only linked). */
	public static function imported( int $pid ): bool {
		if ( ! function_exists( 'bkf_get_source' ) ) {
			return false;
		}
		$src = bkf_get_source( $pid );
		return is_array( $src ) && empty( $src['linked'] );
	}

	private static function hidden( array $cats ): bool {
		if ( null === self::$hidden ) {
			self::$hidden = function_exists( 'hic_hidden_terms' ) ? array_map( 'intval', (array) hic_hidden_terms( 'shop' ) ) : array();
		}
		return self::$hidden && array_intersect( $cats, self::$hidden );
	}

	/**
	 * The items of a product that passed filter(). Variations that are out
	 * of stock stay out when the feed lists only products in stock.
	 */
	public static function items( WC_Product $p, array $f ): array {
		$base = self::build( $p, $p, $f );
		if ( 'variable' !== $p->get_type() || ! $f['variations'] ) {
			if ( 'variable' === $p->get_type() ) {
				$base = self::variable_summary( $p, $base, $f );
				if ( null === $base ) {
					return array();
				}
			}
			return array( $base );
		}
		$vars = array();
		foreach ( $p->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v instanceof WC_Product_Variation || 'publish' !== $v->get_status() ) {
				continue;
			}
			$it = self::build( $v, $p, $f );
			if ( $f['instock_only'] && 'out_of_stock' === $it['avail'] ) {
				continue;
			}
			if ( $it['price'] <= 0 ) {
				continue;
			}
			$vars[] = $it;
		}
		if ( ! $vars ) {
			return array();
		}
		$base['vars'] = $vars;
		$min          = min( array_column( $vars, 'price' ) );
		$base['price'] = (float) $min;
		$base['sale']  = 0.0;
		$avails        = array_column( $vars, 'avail' );
		foreach ( array( 'in_stock', 'preorder', 'backorder', 'out_of_stock' ) as $a ) {
			if ( in_array( $a, $avails, true ) ) {
				$base['avail'] = $a;
				break;
			}
		}
		$qty = 0;
		foreach ( $vars as $v ) {
			$qty += null === $v['qty'] ? (int) $f['qty_default'] : (int) $v['qty'];
		}
		$base['qty'] = $qty;
		return array( $base );
	}

	/** A variable product sent as one item (variations off). */
	private static function variable_summary( WC_Product $p, array $base, array $f ): ?array {
		$prices = $p->get_variation_prices( true );
		$min    = $prices && ! empty( $prices['price'] ) ? (float) min( $prices['price'] ) : 0.0;
		if ( $min <= 0 ) {
			return null;
		}
		$base['price'] = $min;
		$base['sale']  = 0.0;
		if ( $f['instock_only'] && 'out_of_stock' === $base['avail'] ) {
			return null;
		}
		return $base;
	}

	/**
	 * One item. $p is the product or the variation; $parent the product
	 * (the same object for a simple product).
	 */
	private static function build( WC_Product $p, WC_Product $parent, array $f ): array {
		$pid   = (int) $parent->get_id();
		$is_v  = $p instanceof WC_Product_Variation;
		$title = self::plain( $parent->get_name() );
		if ( $is_v ) {
			$extra = self::plain( wc_get_formatted_variation( $p, true, false, false ) );
			if ( '' !== $extra ) {
				$title .= ' - ' . $extra;
			}
		}

		$regular = (float) $p->get_regular_price( 'edit' );
		$active  = (float) $p->get_price( 'edit' );
		if ( 'variable' === $p->get_type() ) {
			$regular = 0.0;
			$active  = 0.0;
		}
		$price = $regular > 0 ? self::with_tax( $p, $regular ) : self::with_tax( $p, $active );
		$sale  = 0.0;
		$from  = '';
		$to    = '';
		if ( $p->is_on_sale( 'edit' ) && $active > 0 && $active < $regular ) {
			$sale = self::with_tax( $p, $active );
			$df   = $p->get_date_on_sale_from( 'edit' );
			$dt   = $p->get_date_on_sale_to( 'edit' );
			$from = $df ? $df->date_i18n( 'Y-m-d' ) : '';
			$to   = $dt ? $dt->date_i18n( 'Y-m-d' ) : '';
		}

		$avail = self::avail( $p );
		$date  = '';
		$rel   = function_exists( 'por_get_release' ) ? por_get_release( $parent ) : null;
		if ( is_array( $rel ) && ! empty( $rel['active'] ) ) {
			$avail = 'preorder';
			$date  = (string) ( $rel['date'] ?? '' );
			if ( $is_v && ! empty( $rel['variations'][ $p->get_id() ] ) ) {
				$date = (string) $rel['variations'][ $p->get_id() ];
			}
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				$date = '';
			}
		}

		$gtin = self::gtin( $p, $f );
		if ( '' === $gtin && $is_v ) {
			$gtin = self::gtin( $parent, $f );
		}
		$isbn = ( 13 === strlen( $gtin ) && preg_match( '/^97[89]/', $gtin ) ) ? $gtin : '';
		$sku  = (string) $p->get_sku();

		$cat_id = self::primary_cat( $pid );
		$link   = $p->get_permalink();
		$link   = self::utm( (string) $link, $f );

		$images = self::images( $p, $parent );
		$weight = $p->get_weight() ? $p->get_weight() : $parent->get_weight();

		$ship = (string) $f['ship'];
		foreach ( self::cat_ids_with_parents( $pid ) as $c ) {
			if ( isset( $f['ship_cats'][ $c ] ) ) {
				$ship = (string) $f['ship_cats'][ $c ];
				break;
			}
		}
		if ( in_array( $avail, array( 'preorder', 'backorder' ), true ) && '' !== (string) $f['ship_back'] ) {
			$ship = (string) $f['ship_back'];
		}
		$gcat = (int) $f['gcat_default'];
		foreach ( self::cat_ids_with_parents( $pid ) as $c ) {
			if ( isset( $f['gcat'][ $c ] ) ) {
				$gcat = (int) $f['gcat'][ $c ];
				break;
			}
		}

		return array(
			'id'         => (string) $p->get_id(),
			'group'      => $is_v ? (string) $pid : '',
			'title'      => $title,
			'desc'       => self::description( $p, $parent, $f ),
			'link'       => $link,
			'image'      => $images[0] ?? '',
			'images'     => array_slice( $images, 1 ),
			'price'      => $price,
			'sale'       => $sale,
			'sale_from'  => $from,
			'sale_to'    => $to,
			'avail'      => $avail,
			'avail_date' => $date,
			'qty'        => $p->managing_stock() ? max( 0, (int) $p->get_stock_quantity() ) : null,
			'gtin'       => $gtin,
			'isbn'       => $isbn,
			'mpn'        => '' !== $isbn ? $isbn : $sku,
			'sku'        => $sku,
			'brand'      => self::brand( $parent, $f ),
			'author'     => self::author( $pid ),
			'cat_path'   => $cat_id ? self::cat_path( $cat_id ) : '',
			'cat_id'     => $cat_id,
			'weight'     => $weight ? (int) round( (float) wc_get_weight( (float) $weight, 'g' ) ) : 0,
			'color'      => self::attr( $p, $parent, $f['color_attr'] ),
			'size'       => self::attr( $p, $parent, $f['size_attr'] ),
			'vat'        => self::vat_rate( $p ),
			'ship'       => $ship,
			'gcat'       => $gcat,
			'vars'       => array(),
		);
	}

	/** in_stock | out_of_stock | backorder (stock status of WooCommerce). */
	public static function avail( WC_Product $p ): string {
		$s = $p->get_stock_status( 'edit' );
		if ( 'onbackorder' === $s ) {
			return 'backorder';
		}
		return 'outofstock' === $s ? 'out_of_stock' : 'in_stock';
	}

	private static function with_tax( WC_Product $p, float $amount ): float {
		if ( $amount <= 0 ) {
			return 0.0;
		}
		return round( (float) wc_get_price_including_tax( $p, array( 'price' => $amount, 'qty' => 1 ) ), 2 );
	}

	/** VAT rate (%) of the product's tax class at the store's base location. */
	private static function vat_rate( WC_Product $p ): float {
		if ( ! wc_tax_enabled() || 'taxable' !== $p->get_tax_status() || ! class_exists( 'WC_Tax' ) ) {
			return 0.0;
		}
		$rates = WC_Tax::get_base_tax_rates( $p->get_tax_class() );
		$sum   = 0.0;
		foreach ( (array) $rates as $r ) {
			$sum += isset( $r['rate'] ) ? (float) $r['rate'] : 0.0;
		}
		return round( $sum, 2 );
	}

	/** GTIN digits: WooCommerce's own field first, then a meta key of the feed. */
	private static function gtin( WC_Product $p, array $f ): string {
		$v = method_exists( $p, 'get_global_unique_id' ) ? (string) $p->get_global_unique_id( 'edit' ) : '';
		if ( '' === trim( $v ) && '' !== $f['gtin_meta'] ) {
			$v = (string) get_post_meta( $p->get_id(), $f['gtin_meta'], true );
		}
		$d = preg_replace( '/[^0-9]/', '', $v );
		return in_array( strlen( (string) $d ), array( 8, 12, 13, 14 ), true ) ? (string) $d : '';
	}

	/** GS1 check digit (EAN-8/UPC/EAN-13/ITF-14, ISBN-13 included). */
	public static function gtin_ok( string $g ): bool {
		if ( ! preg_match( '/^\d{8}$|^\d{12,14}$/', $g ) ) {
			return false;
		}
		$digits = str_split( strrev( $g ) );
		$sum    = 0;
		foreach ( $digits as $i => $d ) {
			if ( 0 === $i ) {
				continue;
			}
			$sum += (int) $d * ( 1 === $i % 2 ? 3 : 1 );
		}
		return ( ( 10 - ( $sum % 10 ) ) % 10 ) === (int) $digits[0];
	}

	private static function brand( WC_Product $parent, array $f ): string {
		$pid = (int) $parent->get_id();
		if ( 'fixed' === $f['brand_src'] ) {
			return $f['brand_fixed'];
		}
		if ( 'attr' === $f['brand_src'] && '' !== $f['brand_attr'] ) {
			$v = self::plain( (string) $parent->get_attribute( $f['brand_attr'] ) );
			$v = trim( explode( ',', $v )[0] );
			return '' !== $v ? $v : $f['brand_fixed'];
		}
		if ( taxonomy_exists( 'product_brand' ) ) {
			$t = wp_get_post_terms( $pid, 'product_brand', array( 'fields' => 'names' ) );
			if ( is_array( $t ) && $t ) {
				return self::plain( (string) $t[0] );
			}
		}
		return $f['brand_fixed'];
	}

	/** Creators (Featured Creators) as «Name, Name», up to 3. */
	private static function author( int $pid ): string {
		if ( ! function_exists( 'crt_get_creators' ) ) {
			return '';
		}
		$names = array();
		foreach ( (array) crt_get_creators( $pid ) as $c ) {
			if ( is_array( $c ) && ! empty( $c['name'] ) ) {
				$names[] = self::plain( (string) $c['name'] );
			}
		}
		return implode( ', ', array_slice( array_unique( $names ), 0, 3 ) );
	}

	private static function attr( WC_Product $p, WC_Product $parent, string $tax ): string {
		if ( '' === $tax ) {
			return '';
		}
		if ( $p instanceof WC_Product_Variation ) {
			$v = (string) $p->get_attribute( $tax );
			if ( '' !== $v ) {
				return self::plain( $v );
			}
		}
		return self::plain( (string) $parent->get_attribute( $tax ) );
	}

	/** Absolute https image URLs: the variation's image first, then the gallery. */
	private static function images( WC_Product $p, WC_Product $parent ): array {
		$ids = array();
		if ( $p->get_image_id() ) {
			$ids[] = (int) $p->get_image_id();
		}
		if ( $parent->get_image_id() ) {
			$ids[] = (int) $parent->get_image_id();
		}
		foreach ( $parent->get_gallery_image_ids() as $g ) {
			$ids[] = (int) $g;
		}
		$out = array();
		foreach ( array_unique( $ids ) as $id ) {
			$u = wp_get_attachment_image_url( $id, 'full' );
			if ( is_string( $u ) && '' !== $u ) {
				$out[] = set_url_scheme( $u );
			}
			if ( count( $out ) >= 16 ) {
				break;
			}
		}
		return $out;
	}

	private static function description( WC_Product $p, WC_Product $parent, array $f ): string {
		$long  = $p instanceof WC_Product_Variation ? (string) $p->get_description( 'edit' ) : '';
		$long  = '' !== trim( $long ) ? $long : (string) $parent->get_description( 'edit' );
		$short = (string) $parent->get_short_description( 'edit' );
		$text  = 'short' === $f['desc_src'] ? ( '' !== trim( $short ) ? $short : $long ) : ( '' !== trim( $long ) ? $long : $short );
		$text  = self::plain( strip_shortcodes( $text ) );
		return mb_substr( $text, 0, 5000 );
	}

	/** Tags, shortcodes leftovers and entities out; whitespace collapsed. */
	public static function plain( string $s ): string {
		$s = wp_strip_all_tags( str_replace( array( '</p>', '<br>', '<br/>', '<br />' ), ' ', $s ) );
		$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$s = preg_replace( '/\[[^\]]{1,80}\]/u', '', $s );
		$s = preg_replace( '/\s+/u', ' ', (string) $s );
		return trim( (string) $s );
	}

	private static function utm( string $url, array $f ): string {
		$q = array();
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign' ) as $k ) {
			if ( '' !== $f[ $k ] ) {
				$q[ $k ] = rawurlencode( $f[ $k ] );
			}
		}
		return $q ? add_query_arg( $q, $url ) : $url;
	}

	/** Category ids of a product with all their parents. */
	public static function cat_ids_with_parents( int $pid ): array {
		$ids = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'ids' ) );
		$out = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$out[] = (int) $id;
			foreach ( get_ancestors( (int) $id, 'product_cat', 'taxonomy' ) as $a ) {
				$out[] = (int) $a;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** The deepest category of a product (ties: the lowest id). */
	private static function primary_cat( int $pid ): int {
		$ids  = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'ids' ) );
		$best = 0;
		$deep = -1;
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$d = count( get_ancestors( (int) $id, 'product_cat', 'taxonomy' ) );
			if ( $d > $deep || ( $d === $deep && (int) $id < $best ) ) {
				$best = (int) $id;
				$deep = $d;
			}
		}
		return $best;
	}

	/** «Βιβλία > Λογοτεχνία» of a category. */
	public static function cat_path( int $cat ): string {
		if ( isset( self::$paths[ $cat ] ) ) {
			return self::$paths[ $cat ];
		}
		$names = array();
		foreach ( array_reverse( get_ancestors( $cat, 'product_cat', 'taxonomy' ) ) as $a ) {
			$t = get_term( (int) $a, 'product_cat' );
			if ( $t instanceof WP_Term ) {
				$names[] = self::plain( $t->name );
			}
		}
		$t = get_term( $cat, 'product_cat' );
		if ( $t instanceof WP_Term ) {
			$names[] = self::plain( $t->name );
		}
		self::$paths[ $cat ] = implode( ' > ', $names );
		return self::$paths[ $cat ];
	}
}

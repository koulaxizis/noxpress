<?php
/**
 * PFD_Ch_Google — Google Merchant Center, RSS 2.0 with the g: namespace.
 *
 * One <item> per product, or per variation with g:item_group_id. Values
 * follow the Merchant Center product data specification: availability
 * in_stock / out_of_stock / preorder / backorder (+ availability_date for
 * the last two), price «12.00 EUR», sale_price_effective_date as an ISO
 * 8601 interval, identifier_exists «no» when there is no GTIN and no
 * brand + MPN, title up to 150 characters.
 *
 * Digital books are not accepted in Shopping ads; the feed leaves out
 * downloadable / virtual products unless the feed says otherwise.
 */

defined( 'ABSPATH' ) || exit;

final class PFD_Ch_Google {

	const NS = 'http://base.google.com/ns/1.0';

	public static function head( array $f ): string {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0" xmlns:g="' . self::NS . '">' . "\n<channel>\n"
			. PFD_Channels::el( 'title', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), "\t" )
			. PFD_Channels::el( 'link', home_url( '/' ), "\t" )
			. PFD_Channels::el( 'description', $f['name'], "\t" );
	}

	public static function foot(): string {
		return "</channel>\n</rss>\n";
	}

	/** Availability value of the channel. */
	public static function avail( string $a ): string {
		return in_array( $a, array( 'in_stock', 'out_of_stock', 'preorder', 'backorder' ), true ) ? $a : 'in_stock';
	}

	public static function rows( array $items, array $f ): string {
		$out = '';
		foreach ( $items as $it ) {
			if ( $it['vars'] ) {
				foreach ( $it['vars'] as $v ) {
					$out .= static::item( $v, $f );
				}
			} else {
				$out .= static::item( $it, $f );
			}
		}
		return $out;
	}

	public static function item( array $it, array $f ): string {
		$cur = get_woocommerce_currency();
		$e   = 'PFD_Channels::el';
		$x   = "\t\t";
		$o   = "\t<item>\n";
		$o  .= $e( 'g:id', $it['id'], $x );
		$o  .= $e( 'g:title', mb_substr( $it['title'], 0, 150 ), $x );
		$o  .= $e( 'g:description', '' !== $it['desc'] ? $it['desc'] : $it['title'], $x );
		$o  .= $e( 'g:link', $it['link'], $x );
		$o  .= $e( 'g:image_link', $it['image'], $x );
		foreach ( array_slice( $it['images'], 0, 10 ) as $img ) {
			$o .= $e( 'g:additional_image_link', $img, $x );
		}
		$o .= $e( 'g:availability', static::avail( $it['avail'] ), $x );
		if ( in_array( $it['avail'], array( 'preorder', 'backorder' ), true ) && '' !== $it['avail_date'] ) {
			$o .= $e( 'g:availability_date', PFD_Channels::iso_day( $it['avail_date'] ), $x );
		}
		$o .= $e( 'g:price', PFD_Channels::money( $it['price'] ) . ' ' . $cur, $x );
		if ( $it['sale'] > 0 ) {
			$o .= $e( 'g:sale_price', PFD_Channels::money( $it['sale'] ) . ' ' . $cur, $x );
			if ( '' !== $it['sale_from'] && '' !== $it['sale_to'] ) {
				$o .= $e( 'g:sale_price_effective_date', PFD_Channels::iso_day( $it['sale_from'] ) . '/' . PFD_Channels::iso_day( gmdate( 'Y-m-d', strtotime( $it['sale_to'] . ' +1 day' ) ) ), $x );
			}
		}
		$o .= $e( 'g:condition', 'new', $x );
		$o .= $e( 'g:brand', $it['brand'], $x );
		$o .= $e( 'g:gtin', $it['gtin'], $x );
		$o .= $e( 'g:mpn', '' === $it['gtin'] ? $it['sku'] : '', $x );
		if ( '' === $it['gtin'] && ( '' === $it['brand'] || '' === $it['sku'] ) ) {
			$o .= $e( 'g:identifier_exists', 'no', $x );
		}
		$o .= $e( 'g:item_group_id', $it['group'], $x );
		$o .= $e( 'g:product_type', $it['cat_path'], $x );
		if ( $it['gcat'] > 0 ) {
			$o .= $e( 'g:google_product_category', (string) $it['gcat'], $x );
		}
		$o .= $e( 'g:color', $it['color'], $x );
		$o .= $e( 'g:size', $it['size'], $x );
		if ( $it['weight'] > 0 ) {
			$o .= $e( 'g:shipping_weight', $it['weight'] . ' g', $x );
		}
		return $o . "\t</item>\n";
	}

	public static function check( array $it, array $f ): array {
		list( $err, $warn ) = PFD_Channels::base_check( $it );
		if ( mb_strlen( $it['title'] ) > 150 ) {
			$warn[] = 'long_title';
		}
		if ( '' === $it['isbn'] && '' !== $it['gtin'] && '' === $it['brand'] ) {
			$warn[] = 'no_brand';
		}
		return array( array_values( array_unique( $err ) ), array_values( array_unique( $warn ) ) );
	}
}

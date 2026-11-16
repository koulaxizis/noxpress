<?php
/**
 * PFD_Ch_BestPrice — BestPrice.gr XML.
 *
 * The merchant specification lives behind BestPrice's merchant panel and
 * could not be read while this writer was made (2026-11-16): the field
 * names below are the commonly published BestPrice structure and MUST be
 * checked against the merchant guide before the feed is submitted. The
 * admin page says so on every BestPrice feed.
 *
 * <store><date/><products><product> productId, title, productURL,
 * imageURL, imagesURL (additional images), price (with VAT), category_id,
 * category (path), brand, ean, isbn, mpn, availability, stock (Y/N),
 * quantity, weight, color, size, description.
 */

defined( 'ABSPATH' ) || exit;

final class PFD_Ch_BestPrice {

	public static function ship_values(): array {
		return array(
			'Άμεσα διαθέσιμο',
			'Διαθέσιμο σε 1 - 3 ημέρες',
			'Διαθέσιμο σε 4 - 10 ημέρες',
			'Κατόπιν παραγγελίας',
		);
	}

	public static function head( array $f ): string {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n<store>\n"
			. "\t<date>" . esc_html( wp_date( 'Y-m-d H:i' ) ) . "</date>\n\t<products>\n";
	}

	public static function foot(): string {
		return "\t</products>\n</store>\n";
	}

	public static function rows( array $items, array $f ): string {
		$out = '';
		foreach ( $items as $it ) {
			$out .= self::product( $it, $f );
		}
		return $out;
	}

	private static function product( array $it, array $f ): string {
		$x     = "\t\t\t";
		$e     = 'PFD_Channels::el';
		$c     = 'PFD_Channels::cdata';
		$in    = 'out_of_stock' !== $it['avail'];
		$o     = "\t\t<product>\n";
		$o    .= $e( 'productId', $it['id'], $x );
		$o    .= $c( 'title', $it['title'], $x );
		$o    .= $c( 'productURL', $it['link'], $x );
		$o    .= $c( 'imageURL', $it['image'], $x );
		if ( $it['images'] ) {
			$o .= $x . "<imagesURL>\n";
			foreach ( array_slice( $it['images'], 0, 10 ) as $img ) {
				$o .= $c( 'imageURL', $img, $x . "\t" );
			}
			$o .= $x . "</imagesURL>\n";
		}
		$o .= $e( 'price', PFD_Channels::money( $it['sale'] > 0 ? $it['sale'] : $it['price'] ), $x );
		$o .= $e( 'category_id', (string) (int) $it['cat_id'], $x );
		$o .= $c( 'category', $it['cat_path'], $x );
		$o .= $c( 'brand', $it['brand'], $x );
		$o .= $e( 'ean', $it['gtin'], $x );
		$o .= $e( 'isbn', $it['isbn'], $x );
		$o .= $e( 'mpn', $it['sku'], $x );
		$o .= $c( 'availability', $in ? $it['ship'] : '', $x );
		$o .= $e( 'stock', $in ? 'Y' : 'N', $x );
		$o .= $e( 'quantity', (string) ( null === $it['qty'] ? (int) $f['qty_default'] : (int) $it['qty'] ), $x );
		if ( $it['weight'] > 0 ) {
			$o .= $e( 'weight', (string) $it['weight'], $x );
		}
		$o .= $c( 'color', $it['color'], $x );
		$sizes = array();
		foreach ( $it['vars'] as $v ) {
			if ( 'out_of_stock' !== $v['avail'] && '' !== $v['size'] ) {
				$sizes[] = $v['size'];
			}
		}
		$o .= $c( 'size', $sizes ? implode( ',', array_unique( $sizes ) ) : $it['size'], $x );
		$o .= $c( 'description', $it['desc'], $x );
		return $o . "\t\t</product>\n";
	}

	public static function check( array $it, array $f ): array {
		list( $err, $warn ) = PFD_Channels::base_check( $it );
		if ( '' === $it['cat_path'] ) {
			$err[] = 'no_category';
		}
		return array( array_values( array_unique( $err ) ), array_values( array_unique( $warn ) ) );
	}
}

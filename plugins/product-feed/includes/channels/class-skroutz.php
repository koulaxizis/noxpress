<?php
/**
 * PFD_Ch_Skroutz — Skroutz XML (developer.skroutz.gr/el/feedspec, read
 * 2026-11-16).
 *
 * <mywebstore><created_at/><products><product>…</product></products>
 * </mywebstore>. Required per product: id, name, link, image, category,
 * price_with_vat (euro, with VAT), availability, manufacturer, mpn (the
 * ISBN for books: books without one are not classified), ean (13 digits),
 * description (no HTML), quantity. Optional: additionalimage (up to 15,
 * https), vat, weight (grams), color, size (comma-separated), variations
 * (only for size-based products: variationid, availability, size,
 * quantity, link, manufacturersku, ean, price_with_vat).
 *
 * Skroutz has no «out of stock» value: an item without stock never goes
 * in. Availability is one of SHIP (the feed's choice, per category too).
 */

defined( 'ABSPATH' ) || exit;

final class PFD_Ch_Skroutz {

	/** Accepted availability texts, fastest first. */
	public static function ship_values(): array {
		return array(
			'Άμεσα διαθέσιμο',
			'Διαθέσιμο από 1 έως 3 ημέρες',
			'Διαθέσιμο από 4 έως 10 ημέρες',
			'Διαθέσιμο από 10 έως 30 ημέρες',
		);
	}

	public static function head( array $f ): string {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n<mywebstore>\n"
			. "\t<created_at>" . esc_html( wp_date( 'Y-m-d H:i' ) ) . "</created_at>\n\t<products>\n";
	}

	public static function foot(): string {
		return "\t</products>\n</mywebstore>\n";
	}

	public static function rows( array $items, array $f ): string {
		$out = '';
		foreach ( $items as $it ) {
			if ( 'out_of_stock' === $it['avail'] ) {
				continue;
			}
			$out .= self::product( $it, $f );
		}
		return $out;
	}

	private static function product( array $it, array $f ): string {
		$x = "\t\t\t";
		$e = 'PFD_Channels::el';
		$c = 'PFD_Channels::cdata';
		$o = "\t\t<product>\n";
		$o .= $e( 'id', $it['id'], $x );
		$o .= $c( 'name', $it['title'], $x );
		$o .= $c( 'link', $it['link'], $x );
		$o .= $c( 'image', $it['image'], $x );
		foreach ( array_slice( $it['images'], 0, 15 ) as $img ) {
			$o .= $c( 'additionalimage', $img, $x );
		}
		$o .= "\t\t\t<category id=\"" . (int) $it['cat_id'] . '"><![CDATA[' . str_replace( ']]>', '', $it['cat_path'] ) . "]]></category>\n";
		$o .= $e( 'price_with_vat', PFD_Channels::money( $it['sale'] > 0 ? $it['sale'] : $it['price'] ), $x );
		$o .= $e( 'vat', PFD_Channels::money( $it['vat'] ), $x );
		$o .= $e( 'availability', $it['ship'], $x );
		$o .= $c( 'manufacturer', $it['brand'], $x );
		$o .= $e( 'mpn', $it['mpn'], $x );
		$o .= $e( 'ean', 13 === strlen( $it['gtin'] ) ? $it['gtin'] : '', $x );
		$o .= $c( 'description', $it['desc'], $x );
		$o .= $e( 'quantity', (string) ( null === $it['qty'] ? (int) $f['qty_default'] : (int) $it['qty'] ), $x );
		if ( $it['weight'] > 0 ) {
			$o .= $e( 'weight', (string) $it['weight'], $x );
		}
		$o .= $c( 'color', $it['color'], $x );

		$vars = array();
		foreach ( $it['vars'] as $v ) {
			if ( 'out_of_stock' !== $v['avail'] ) {
				$vars[] = $v;
			}
		}
		$sizes = array_values( array_unique( array_filter( array_column( $vars, 'size' ) ) ) );
		if ( $sizes ) {
			$o .= $c( 'size', implode( ',', $sizes ), $x );
		} else {
			$o .= $c( 'size', $it['size'], $x );
		}
		if ( $vars && $sizes ) {
			$o .= $x . "<variations>\n";
			foreach ( $vars as $v ) {
				if ( '' === $v['size'] ) {
					continue;
				}
				$y  = $x . "\t\t";
				$o .= $x . "\t<variation>\n";
				$o .= $e( 'variationid', $v['id'], $y );
				$o .= $c( 'link', $v['link'], $y );
				$o .= $e( 'availability', $v['ship'], $y );
				$o .= $e( 'manufacturersku', $v['mpn'], $y );
				$o .= $e( 'ean', 13 === strlen( $v['gtin'] ) ? $v['gtin'] : '', $y );
				$o .= $e( 'price_with_vat', PFD_Channels::money( $v['sale'] > 0 ? $v['sale'] : $v['price'] ), $y );
				$o .= $c( 'size', $v['size'], $y );
				$o .= $e( 'quantity', (string) ( null === $v['qty'] ? (int) $f['qty_default'] : (int) $v['qty'] ), $y );
				$o .= $x . "\t</variation>\n";
			}
			$o .= $x . "</variations>\n";
		}
		return $o . "\t\t</product>\n";
	}

	public static function check( array $it, array $f ): array {
		list( $err, $warn ) = PFD_Channels::base_check( $it );
		if ( '' === $it['cat_path'] ) {
			$err[] = 'no_category';
		}
		if ( '' === $it['mpn'] ) {
			$warn[] = 'no_mpn';
		}
		if ( '' !== $it['image'] && 0 !== strpos( $it['image'], 'https://' ) ) {
			$warn[] = 'image_http';
		}
		if ( '' !== $it['gtin'] && 13 !== strlen( $it['gtin'] ) ) {
			$warn[] = 'ean_13';
		}
		return array( array_values( array_unique( $err ) ), array_values( array_unique( $warn ) ) );
	}
}

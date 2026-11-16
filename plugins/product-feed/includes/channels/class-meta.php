<?php
/**
 * PFD_Ch_Meta — Meta catalog (Facebook / Instagram), RSS 2.0 XML with the
 * g: namespace, one of the formats Meta's catalog data feeds accept.
 *
 * Same fields as Google with Meta's values: availability «in stock» /
 * «out of stock» (a pre-order or back-order item can be bought now, so it
 * is «in stock»), condition «new», price «12.00 EUR». Meta requires a
 * description and at least one of brand / gtin / mpn; an item without
 * them is an error of the check and stays out when «skip errors» is on.
 */

defined( 'ABSPATH' ) || exit;

final class PFD_Ch_Meta {

	public static function head( array $f ): string {
		return PFD_Ch_Google::head( $f );
	}

	public static function foot(): string {
		return PFD_Ch_Google::foot();
	}

	public static function rows( array $items, array $f ): string {
		$out = '';
		foreach ( $items as $it ) {
			foreach ( $it['vars'] ? $it['vars'] : array( $it ) as $one ) {
				$out .= self::item( $one, $f );
			}
		}
		return $out;
	}

	private static function item( array $it, array $f ): string {
		$xml = PFD_Ch_Google::item( $it, $f );
		$val = 'out_of_stock' === $it['avail'] ? 'out of stock' : 'in stock';
		$xml = preg_replace( '#<g:availability>[^<]*</g:availability>#', '<g:availability>' . $val . '</g:availability>', $xml );
		$xml = preg_replace( "#\t\t<g:availability_date>[^<]*</g:availability_date>\n#", '', (string) $xml );
		if ( null !== $it['qty'] ) {
			$xml = str_replace( "\t</item>\n", PFD_Channels::el( 'g:quantity_to_sell_on_facebook', (string) $it['qty'] ) . "\t</item>\n", (string) $xml );
		}
		return (string) $xml;
	}

	public static function check( array $it, array $f ): array {
		list( $err, $warn ) = PFD_Channels::base_check( $it );
		if ( '' === $it['brand'] && '' === $it['gtin'] && '' === $it['sku'] ) {
			$err[] = 'no_identifier';
		}
		return array( array_values( array_unique( $err ) ), array_values( array_unique( $warn ) ) );
	}
}

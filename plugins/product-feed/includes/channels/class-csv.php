<?php
/**
 * PFD_Ch_Csv — a general CSV with the same fields, for other services or
 * a look in a spreadsheet. UTF-8 with BOM, comma, decimal point (the
 * «Standard» format of Bible §5: services read it, Excel opens it), the
 * formula guard of Noxpress_Safe::csv_cell() on every text cell. One row
 * per product, or per variation with group_id.
 */

defined( 'ABSPATH' ) || exit;

final class PFD_Ch_Csv {

	public static function columns(): array {
		return array( 'id', 'group_id', 'title', 'description', 'link', 'image', 'additional_images', 'availability', 'availability_date', 'price', 'sale_price', 'sale_from', 'sale_to', 'currency', 'brand', 'gtin', 'isbn', 'sku', 'author', 'category', 'weight_g', 'color', 'size', 'stock', 'vat' );
	}

	public static function head( array $f ): string {
		return "\xEF\xBB\xBF" . self::line( self::columns() );
	}

	public static function foot(): string {
		return '';
	}

	public static function rows( array $items, array $f ): string {
		$cur = get_woocommerce_currency();
		$out = '';
		foreach ( $items as $it ) {
			foreach ( $it['vars'] ? $it['vars'] : array( $it ) as $r ) {
				$out .= self::line(
					array(
						$r['id'],
						$r['group'],
						$r['title'],
						$r['desc'],
						$r['link'],
						$r['image'],
						implode( ' | ', $r['images'] ),
						$r['avail'],
						$r['avail_date'],
						PFD_Channels::money( $r['price'] ),
						$r['sale'] > 0 ? PFD_Channels::money( $r['sale'] ) : '',
						$r['sale_from'],
						$r['sale_to'],
						$cur,
						$r['brand'],
						$r['gtin'],
						$r['isbn'],
						$r['sku'],
						$r['author'],
						$r['cat_path'],
						$r['weight'] > 0 ? (string) $r['weight'] : '',
						$r['color'],
						$r['size'],
						null === $r['qty'] ? '' : (string) $r['qty'],
						PFD_Channels::money( $r['vat'] ),
					)
				);
			}
		}
		return $out;
	}

	private static function line( array $cells ): string {
		$fh = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- memory stream.
		if ( ! $fh ) {
			return '';
		}
		if ( class_exists( 'Noxpress_Safe' ) ) {
			Noxpress_Safe::csv_row( $fh, $cells );
		} else {
			fputcsv( $fh, $cells, ',', '"', '' );
		}
		rewind( $fh );
		$s = (string) stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- memory stream.
		return $s;
	}

	public static function check( array $it, array $f ): array {
		list( $err, $warn ) = PFD_Channels::base_check( $it );
		return array( array_values( array_unique( $err ) ), array_values( array_unique( $warn ) ) );
	}
}

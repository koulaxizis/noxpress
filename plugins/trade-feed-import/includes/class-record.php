<?php
/**
 * TFI_Record — one title from the Trade Feed, validated and normalised.
 *
 * Everything from the publisher is treated as hostile (Bible §11): known
 * keys only, strings trimmed and capped, numbers bounded, URLs https only
 * (http accepted for images), HTML through wp_kses_post when it is
 * written, never here.
 *
 * Normalised record:
 *  id, isbn, isbn_digits, title, url, publisher, cats (source ids),
 *  contributors [ { role, key, names[] } ], attributes [ { name, slug,
 *  values[] } ], desc, short, length / width / height (cm), weight (kg),
 *  retail, trade (strings with dot decimals, '' when absent), currency,
 *  vat (the publisher's VAT rate %, or null), incl (its prices include VAT),
 *  status (instock | outofstock | onbackorder | preorder), preorder,
 *  release ('Y-m-d' or ''), image (the cover's address), modified.
 */

defined( 'ABSPATH' ) || exit;

final class TFI_Record {

	/** A feed item as a record, or null when it has no id or no title. */
	public static function from( $item ): ?array {
		if ( ! is_array( $item ) ) {
			return null;
		}
		$id    = isset( $item['id'] ) && is_numeric( $item['id'] ) ? (int) $item['id'] : 0;
		$title = self::text( $item['title'] ?? '', 300 );
		if ( $id <= 0 || '' === $title ) {
			return null;
		}

		$ids  = isset( $item['identifiers'] ) && is_array( $item['identifiers'] ) ? $item['identifiers'] : array();
		$isbn = self::text( $item['isbn'] ?? '', 40 );
		foreach ( array( 'isbn', 'gtin', 'issn' ) as $k ) {
			if ( '' === self::digits( $isbn ) && isset( $ids[ $k ] ) ) {
				$isbn = self::text( $ids[ $k ], 40 );
			}
		}

		$prices = isset( $item['prices'] ) && is_array( $item['prices'] ) ? $item['prices'] : array();
		$av     = isset( $item['availability'] ) && is_array( $item['availability'] ) ? $item['availability'] : array();
		$status = self::text( $av['status'] ?? '', 20 );
		$status = in_array( $status, array( 'instock', 'outofstock', 'onbackorder', 'preorder' ), true ) ? $status : 'instock';
		$rel    = self::text( $av['release_date'] ?? '', 10 );
		$rel    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $rel ) ? $rel : '';

		$r = array(
			'id'           => $id,
			'isbn'         => $isbn,
			'isbn_digits'  => self::digits( $isbn ),
			'title'        => $title,
			'url'          => self::url( $item['url'] ?? '' ),
			'publisher'    => self::first_name( $item['publisher'] ?? array() ),
			'cats'         => self::cat_ids( $item['categories'] ?? array() ),
			'contributors' => self::contributors( $item['contributors'] ?? array() ),
			'attributes'   => self::attributes( $item['attributes'] ?? array() ),
			'desc'         => self::html( $item['description_html'] ?? '' ),
			'short'        => self::html( $item['short_description_html'] ?? '' ),
			'retail'       => self::money( $prices['retail'] ?? null ),
			'trade'        => self::money( $prices['trade'] ?? null ),
			'currency'     => strtoupper( self::text( $prices['currency'] ?? '', 3 ) ),
			'vat'          => isset( $prices['vat_rate'] ) && is_numeric( $prices['vat_rate'] ) && (float) $prices['vat_rate'] >= 0 && (float) $prices['vat_rate'] < 100 ? (float) $prices['vat_rate'] : null,
			'incl'         => ! isset( $prices['include_tax'] ) || ! empty( $prices['include_tax'] ),
			'status'       => $status,
			'preorder'     => ! empty( $av['preorder'] ) || 'preorder' === $status,
			'release'      => $rel,
			'image'        => self::cover( $item['images'] ?? array() ),
			'modified'     => self::text( $item['date_modified'] ?? '', 40 ),
		);

		$dims = isset( $item['dimensions'] ) && is_array( $item['dimensions'] ) ? $item['dimensions'] : array();
		$unit = self::text( $dims['unit'] ?? 'cm', 5 );
		foreach ( array( 'length', 'width', 'height' ) as $k ) {
			$r[ $k ] = self::measure( $dims[ $k ] ?? '', $unit, 'dimension' );
		}
		$w           = isset( $item['weight'] ) && is_array( $item['weight'] ) ? $item['weight'] : array();
		$r['weight'] = self::measure( $w['value'] ?? '', self::text( $w['unit'] ?? 'kg', 5 ), 'weight' );

		return $r;
	}

	/** ISBN-13 / ISBN-10 / ISSN / EAN digits (X allowed last), '' when not a code. */
	public static function digits( string $code ): string {
		$d = strtoupper( (string) preg_replace( '/[^0-9Xx]/', '', $code ) );
		return preg_match( '/^(\d{13}|\d{12}|\d{9}[\dX]|\d{7}[\dX])$/', $d ) ? $d : '';
	}

	public static function text( $v, int $max ): string {
		if ( ! is_scalar( $v ) ) {
			return '';
		}
		return mb_substr( trim( sanitize_text_field( (string) $v ) ), 0, $max );
	}

	private static function html( $v ): string {
		if ( ! is_string( $v ) ) {
			return '';
		}
		return mb_substr( trim( $v ), 0, 65000 );
	}

	private static function url( $v, bool $http = false ): string {
		$u = is_string( $v ) ? trim( $v ) : '';
		$ok = $http ? '#^https?://#i' : '#^https://#i';
		if ( '' === $u || strlen( $u ) > 500 || ! preg_match( $ok, $u ) ) {
			return '';
		}
		return esc_url_raw( $u, $http ? array( 'http', 'https' ) : array( 'https' ) );
	}

	/** A price as a dot-decimal string, '' when absent or out of range. */
	private static function money( $v ): string {
		if ( null === $v || ! is_scalar( $v ) ) {
			return '';
		}
		$v = str_replace( ',', '.', trim( (string) $v ) );
		if ( '' === $v || ! is_numeric( $v ) ) {
			return '';
		}
		$f = (float) $v;
		return ( $f >= 0 && $f < 100000 ) ? wc_format_decimal( $f, wc_get_price_decimals() ) : '';
	}

	/** A dimension or weight in the store's unit, '' when absent. */
	private static function measure( $v, string $from, string $kind ): string {
		$v = is_scalar( $v ) ? str_replace( ',', '.', trim( (string) $v ) ) : '';
		if ( '' === $v || ! is_numeric( $v ) || (float) $v <= 0 || (float) $v > 100000 ) {
			return '';
		}
		$from = strtolower( $from );
		if ( 'dimension' === $kind ) {
			$to  = (string) get_option( 'woocommerce_dimension_unit', 'cm' );
			$ok  = in_array( $from, array( 'mm', 'cm', 'm', 'in', 'yd' ), true );
			$val = $ok ? wc_get_dimension( (float) $v, $to, $from ) : (float) $v;
		} else {
			$to  = (string) get_option( 'woocommerce_weight_unit', 'kg' );
			$ok  = in_array( $from, array( 'g', 'kg', 'lbs', 'oz' ), true );
			$val = $ok ? wc_get_weight( (float) $v, $to, $from ) : (float) $v;
		}
		return wc_format_decimal( round( (float) $val, 3 ) );
	}

	private static function first_name( $v ): string {
		if ( is_array( $v ) ) {
			foreach ( $v as $x ) {
				$x = self::text( $x, 120 );
				if ( '' !== $x ) {
					return $x;
				}
			}
			return '';
		}
		return self::text( $v, 120 );
	}

	private static function cat_ids( $v ): array {
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $c ) {
			if ( is_array( $c ) && isset( $c['id'] ) && is_numeric( $c['id'] ) && (int) $c['id'] > 0 && count( $out ) < 30 ) {
				$out[] = (int) $c['id'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function names( $v, int $max = 30 ): array {
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $n ) {
			$n = self::text( $n, 200 );
			if ( '' !== $n && ! in_array( $n, $out, true ) && count( $out ) < $max ) {
				$out[] = $n;
			}
		}
		return $out;
	}

	private static function contributors( $v ): array {
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $c ) {
			if ( ! is_array( $c ) || count( $out ) >= 20 ) {
				continue;
			}
			$role  = self::text( $c['role'] ?? '', 80 );
			$key   = sanitize_key( self::text( $c['role_key'] ?? '', 28 ) );
			$names = self::names( $c['names'] ?? array() );
			if ( '' !== $role && $names ) {
				$out[] = array(
					'role'  => $role,
					'key'   => '' !== $key ? $key : sanitize_title( $role ),
					'names' => $names,
				);
			}
		}
		return $out;
	}

	private static function attributes( $v ): array {
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $a ) {
			if ( ! is_array( $a ) || count( $out ) >= 30 ) {
				continue;
			}
			$name   = self::text( $a['name'] ?? '', 80 );
			$slug   = sanitize_key( self::text( $a['slug'] ?? '', 32 ) );
			$values = self::names( $a['values'] ?? array(), 50 );
			if ( '' !== $name && $values ) {
				$out[] = array(
					'name'   => $name,
					'slug'   => '' !== $slug ? $slug : sanitize_title( $name ),
					'values' => $values,
				);
			}
		}
		return $out;
	}

	/** The first image's address (the cover). */
	private static function cover( $v ): string {
		foreach ( is_array( $v ) ? $v : array() as $img ) {
			if ( is_array( $img ) && isset( $img['src'] ) ) {
				$u = self::url( $img['src'], true );
				if ( '' !== $u ) {
					return $u;
				}
			}
		}
		return '';
	}
}

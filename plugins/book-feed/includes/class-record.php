<?php
/**
 * BKF_Record — one book from the feed, validated and normalised.
 *
 * Everything from the service is treated as hostile (Bible §11): known
 * keys only, strings trimmed and capped, numbers bounded, URLs http(s)
 * only, HTML through wp_kses_post when it is written, never here.
 * A field the service sends as an array (Solr multi-valued) or as a
 * single value is read the same way.
 */

defined( 'ABSPATH' ) || exit;

final class BKF_Record {

	/** First scalar of a value (or the value itself) as a string. */
	public static function one( $v ): string {
		if ( is_array( $v ) ) {
			foreach ( $v as $x ) {
				if ( is_scalar( $x ) ) {
					return trim( (string) $x );
				}
			}
			return '';
		}
		return is_scalar( $v ) ? trim( (string) $v ) : '';
	}

	/** Every scalar of a value as strings (empty ones dropped, capped). */
	public static function many( $v, int $max = 50, int $len = 200 ): array {
		$out = array();
		foreach ( is_array( $v ) ? $v : array( $v ) as $x ) {
			if ( is_scalar( $x ) ) {
				$x = BKF_Settings::text( $x, $len );
				if ( '' !== $x ) {
					$out[] = $x;
				}
			}
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	private static function num( $v, float $min, float $max ): ?float {
		$v = self::one( $v );
		$v = str_replace( ',', '.', $v );
		if ( '' === $v || ! is_numeric( $v ) ) {
			return null;
		}
		$f = (float) $v;
		return ( $f >= $min && $f <= $max ) ? $f : null;
	}

	private static function url( $v ): string {
		$u = self::one( $v );
		if ( '' === $u || ! preg_match( '#^https?://#i', $u ) || strlen( $u ) > 500 ) {
			return '';
		}
		return esc_url_raw( $u, array( 'http', 'https' ) );
	}

	/** A Solr date (2026-10-19T08:00:00Z) as a Unix time, 0 when absent. */
	private static function time( $v ): int {
		$v = self::one( $v );
		if ( '' === $v || ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $v ) ) {
			return 0;
		}
		$t = strtotime( $v );
		return false === $t ? 0 : (int) $t;
	}

	/** ISBN digits (13 or 10, X allowed last), '' when not an ISBN. */
	public static function isbn_digits( string $isbn ): string {
		$d = strtoupper( preg_replace( '/[^0-9Xx]/', '', $isbn ) );
		return preg_match( '/^(\d{13}|\d{9}[\dX])$/', $d ) ? $d : '';
	}

	/**
	 * A normalised record, or null when the document has no product_id
	 * or no title.
	 */
	public static function from_doc( array $d ): ?array {
		$id = self::one( $d['product_id'] ?? '' );
		if ( '' === $id || strlen( $id ) > 64 || preg_match( '/[\x00-\x1f]/', $id ) ) {
			return null;
		}
		$title = BKF_Settings::text( self::one( $d['distinctive_title'] ?? '' ), 300 );
		if ( '' === $title ) {
			return null;
		}

		$isbn_raw = BKF_Settings::text( self::one( $d['isbn13'] ?? '' ), 30 );
		$isbn     = '' !== self::isbn_digits( $isbn_raw ) ? $isbn_raw : '';

		// Contributors: contr_name[i] with contr_role[i].
		$names    = self::many( $d['contr_name'] ?? array(), 60, 150 );
		$roles    = self::many( $d['contr_role'] ?? array(), 60, 80 );
		$contribs = array();
		foreach ( $names as $i => $name ) {
			// "Συγγραφέας, Εικονογράφηση": one person, two roles.
			$parts = isset( $roles[ $i ] ) ? array_values( array_filter( array_map( 'trim', explode( ',', $roles[ $i ] ) ), 'strlen' ) ) : array();
			foreach ( $parts ? $parts : array( '' ) as $role ) {
				$contribs[] = array(
					'name' => $name,
					'role' => $role,
				);
			}
		}

		$valid = self::time( $d['price_valid_until'] ?? '' );

		$desc = self::one( $d['description'] ?? '' );
		if ( strlen( $desc ) > 65535 ) {
			$desc = substr( $desc, 0, 65535 );
		}

		return array(
			'id'          => $id,
			'isbn'        => $isbn,
			'isbn_digits' => self::isbn_digits( $isbn ),
			'title'       => $title,
			'subtitle'    => BKF_Settings::text( self::one( $d['distinctive_subtitle'] ?? '' ), 300 ),
			'orig_title'  => BKF_Settings::text( self::one( $d['original_title'] ?? '' ), 300 ),
			'kind'        => BKF_Settings::text( self::one( $d['product_kind'] ?? '' ), 60 ),
			'form'        => BKF_Settings::text( self::one( $d['product_form'] ?? '' ), 80 ),
			'status'      => BKF_Settings::text( self::one( $d['publishing_status'] ?? '' ), 80 ),
			'pub_id'      => BKF_Settings::text( self::one( $d['pub_id'] ?? '' ), 40 ),
			'pub_name'    => BKF_Settings::text( self::one( $d['pub_name'] ?? '' ), 150 ),
			'imprint'     => BKF_Settings::text( self::one( $d['imprint'] ?? '' ), 150 ),
			'price'       => self::num( $d['price'] ?? '', 0, 100000 ),
			'vat'         => self::num( $d['vat'] ?? '', 0, 100 ),
			'ptype'       => BKF_Settings::text( self::one( $d['price_type'] ?? '' ), 80 ),
			'valid_until' => $valid ? gmdate( 'Y-m-d', $valid ) : '',
			'cover'       => self::url( $d['cover'] ?? '' ),
			'back'        => self::url( $d['back_cover'] ?? '' ),
			'desc'        => $desc,
			'short'       => BKF_Settings::text( self::one( $d['short_descr'] ?? '' ), 2000 ),
			'cats'        => self::many( $d['category'] ?? array(), 20, 120 ),
			'cat_main'    => BKF_Settings::text( self::one( $d['category_main'] ?? '' ), 120 ),
			'class'       => self::many( $d['classification'] ?? array(), 50, 120 ),
			'class_main'  => BKF_Settings::text( self::one( $d['classification_main'] ?? '' ), 120 ),
			'contribs'    => $contribs,
			'language'    => self::many( $d['language'] ?? array(), 10, 60 ),
			'pages'       => (int) self::num( $d['pages'] ?? '', 0, 100000 ),
			'weight'      => (int) self::num( $d['weight'] ?? '', 0, 100000 ),
			'height'      => (float) self::num( $d['height'] ?? '', 0, 1000 ),
			'width'       => (float) self::num( $d['width'] ?? '', 0, 1000 ),
			'year'        => (int) self::num( $d['pub_year'] ?? '', 0, 2200 ),
			'series'      => BKF_Settings::text( self::one( $d['coll_title'] ?? '' ), 200 ),
			'series_no'   => BKF_Settings::text( self::one( $d['coll_seq_no'] ?? '' ), 20 ),
			'rel_isbn'    => array_values( array_filter( array_map( array( __CLASS__, 'isbn_digits' ), self::many( $d['rel_isbn'] ?? array(), 20, 30 ) ) ) ),
			'updated'     => self::time( $d['last_update'] ?? '' ),
			'avq'         => null === self::num( $d['avq'] ?? '', 0, 1000000 ) ? null : (int) self::num( $d['avq'] ?? '', 0, 1000000 ),
		);
	}

	/** Values to remember for the mapping tables. */
	public static function seen_values( array $records ): array {
		$out = array_fill_keys( BKF_Settings::SEEN_KEYS, array() );
		foreach ( $records as $r ) {
			$out['kind'][]   = $r['kind'];
			$out['status'][] = $r['status'];
			$out['form'][]   = $r['form'];
			$out['ptype'][]  = $r['ptype'];
			if ( null !== $r['vat'] ) {
				$out['vat'][] = BKF_Settings::rate_key( $r['vat'] );
			}
			foreach ( $r['cats'] as $c ) {
				$out['cat'][] = $c;
			}
			foreach ( $r['contribs'] as $c ) {
				$out['role'][] = $c['role'];
			}
			foreach ( $r['class'] as $c ) {
				$out['class'][] = $c;
			}
		}
		return $out;
	}

	/** Parse a list of documents: records, plus the values seen. */
	public static function from_docs( array $docs ): array {
		$out = array();
		foreach ( $docs as $d ) {
			$r = is_array( $d ) ? self::from_doc( $d ) : null;
			if ( $r ) {
				$out[] = $r;
			}
		}
		if ( $out ) {
			BKF_Settings::note_seen( self::seen_values( $out ) );
		}
		return $out;
	}
}

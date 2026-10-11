<?php
/**
 * BPV_Pages — the page field: "1-12, 20, 35-40".
 *
 * Accepts single pages and ranges separated by commas, semicolons or
 * spaces, en / em dashes as range marks, and an open range "35-" (to the
 * end). The result is ascending and without duplicates, so the sample
 * reads like the book.
 */

defined( 'ABSPATH' ) || exit;

final class BPV_Pages {

	const MAX_LEN = 200;

	/**
	 * Parses a page list.
	 *
	 * @param string   $spec  The field value.
	 * @param int|null $total Pages in the book, or null when unknown (syntax check only).
	 * @param bool     $clamp Drop pages past the end instead of failing (bulk runs).
	 * @return array{ok:bool, pages:int[], error:string, page:int}
	 *         error: '' | empty | syntax | zero | order | range (page = the first page past the end).
	 */
	public static function parse( string $spec, ?int $total, bool $clamp = false ): array {
		$spec = trim( str_replace( array( "\u{2013}", "\u{2014}", "\u{2212}" ), '-', $spec ) );
		if ( '' === $spec ) {
			return self::fail( 'empty' );
		}
		if ( strlen( $spec ) > self::MAX_LEN ) {
			return self::fail( 'syntax' );
		}
		$pages = array();
		foreach ( preg_split( '/[,;\s]+/', (string) preg_replace( '/\s*-\s*/', '-', $spec ) ) as $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( ! preg_match( '/^(\d{1,5})(?:-(\d{1,5})?)?$/', $part, $m ) ) {
				return self::fail( 'syntax' );
			}
			$a    = (int) $m[1];
			$open = '-' === substr( $part, -1 );
			if ( $open ) {
				$b = null === $total ? $a : max( $a, $total );
			} else {
				$b = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : $a;
			}
			if ( $a < 1 || $b < 1 ) {
				return self::fail( 'zero' );
			}
			if ( $b < $a ) {
				return self::fail( 'order' );
			}
			if ( null !== $total && $b > $total ) {
				if ( ! $clamp ) {
					return array(
						'ok'    => false,
						'pages' => array(),
						'error' => 'range',
						'page'  => max( $a, $total + 1 ),
					);
				}
				$b = $total;
				if ( $a > $total ) {
					continue;
				}
			}
			if ( $b - $a > 5000 ) {
				return self::fail( 'syntax' );
			}
			for ( $p = $a; $p <= $b; $p++ ) {
				$pages[ $p ] = true;
			}
		}
		if ( ! $pages ) {
			return self::fail( $clamp ? 'range' : 'empty' );
		}
		ksort( $pages );
		return array(
			'ok'    => true,
			'pages' => array_keys( $pages ),
			'error' => '',
			'page'  => 0,
		);
	}

	private static function fail( string $error ): array {
		return array(
			'ok'    => false,
			'pages' => array(),
			'error' => $error,
			'page'  => 0,
		);
	}

	/** Compact form of a page list: [1,2,3,5] → "1-3, 5". */
	public static function compact( array $pages ): string {
		$out   = array();
		$start = null;
		$prev  = null;
		foreach ( $pages as $p ) {
			$p = (int) $p;
			if ( null === $start ) {
				$start = $p;
			} elseif ( $p !== $prev + 1 ) {
				$out[] = $start === $prev ? (string) $start : $start . '-' . $prev;
				$start = $p;
			}
			$prev = $p;
		}
		if ( null !== $start ) {
			$out[] = $start === $prev ? (string) $start : $start . '-' . $prev;
		}
		return implode( ', ', $out );
	}

	/** "First N pages after skipping K": the bulk default. */
	public static function first( int $n, int $skip ): string {
		$a = $skip + 1;
		return $a . '-' . ( $skip + max( 1, $n ) );
	}

	/** The admin message for a parse error. */
	public static function message( array $res ): string {
		switch ( $res['error'] ) {
			case 'empty':
				return __( 'Γράψε ποιες σελίδες θα έχει το δείγμα, π.χ. 1-12, 20, 35-40.', 'book-preview' );
			case 'zero':
				return __( 'Οι σελίδες αρχίζουν από το 1.', 'book-preview' );
			case 'order':
				return __( 'Σε ένα εύρος η πρώτη σελίδα πρέπει να είναι μικρότερη από την τελευταία (π.χ. 35-40).', 'book-preview' );
			case 'range':
				/* translators: %d: page number */
				return sprintf( __( 'Το PDF δεν έχει σελίδα %d.', 'book-preview' ), (int) $res['page'] );
			default:
				return __( 'Δεν καταλαβαίνω το πεδίο σελίδων. Γράψε σελίδες και εύρη με κόμμα, π.χ. 1-12, 20, 35-40.', 'book-preview' );
		}
	}
}

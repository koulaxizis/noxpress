<?php
/**
 * LVS_Search — the scorer on the server: the results page, lvs/v1/suggest
 * (server mode) and the abilities. The same rules as score() in
 * assets/live-search.js; a change here changes the JS in the same commit.
 *
 * A query is split into words (normalised; words of one letter are left
 * out unless they are digits). EVERY word must match the product, each by
 * its best way:
 *   exact code (ISBN / GTIN / SKU, 4+ characters)        40
 *   a code that starts with it (5+ characters)          15
 *   a whole word of the title                           12, start of one 10
 *   a whole word of the people (authors…)               10, start of one  9
 *   a synonym, as start of a word: title 8, people 7, terms 4
 *   the phonetic key (greeklish) at the start of a word  6
 *   inside a title word (3+ letters)                     5
 *   inside a people word (3+ letters)                    4
 *   start of a term word (category, tag, brand, other)   4
 *   one letter wrong / missing / extra / swapped (4+ letters) in a title or
 *   people word, or in its phonetic key                  3
 *   start of a short-description word (results page)     2
 * Then +30 when the whole query is the title, +15 when the title starts
 * with it. Order: out of stock last (setting «last»), score, sales.
 */

defined( 'ABSPATH' ) || exit;

final class LVS_Search {

	const FUZZY_MAX_ROWS = 20000;

	/**
	 * Query words.
	 *
	 * @return array<int, array{t: string, p: string, d: bool, alts: string[]}>
	 */
	public static function tokens( string $q ): array {
		$n   = LVS_Text::norm( $q );
		$syn = LVS_Settings::synonym_map();
		$out = array();
		foreach ( LVS_Text::words( $n ) as $w ) {
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $w, 'UTF-8' ) : strlen( $w );
			$dig = (bool) preg_match( '/\d/', $w );
			if ( $len < 2 && ! $dig ) {
				continue;
			}
			$out[] = array(
				't'    => $w,
				'p'    => LVS_Text::phon_word( $w ),
				'd'    => $dig,
				'l'    => $len,
				'alts' => $syn[ $w ] ?? array(),
			);
			if ( count( $out ) >= 8 ) {
				break;
			}
		}
		return $out;
	}

	private static function starts( string $field, string $t ): bool {
		return '' !== $field && false !== strpos( ' ' . $field, ' ' . $t );
	}

	private static function whole( string $field, string $t ): bool {
		return '' !== $field && false !== strpos( ' ' . $field . ' ', ' ' . $t . ' ' );
	}

	private static function fuzzy( string $field, string $t, int $len ): bool {
		if ( $len < 4 || '' === $field ) {
			return false;
		}
		foreach ( explode( ' ', $field ) as $w ) {
			$wl = function_exists( 'mb_strlen' ) ? mb_strlen( $w, 'UTF-8' ) : strlen( $w );
			if ( $wl < 3 ) {
				continue;
			}
			if ( LVS_Text::near( $t, $w ) ) {
				return true;
			}
			// While typing: the start of a longer word.
			if ( $wl > $len && LVS_Text::near( $t, function_exists( 'mb_substr' ) ? mb_substr( $w, 0, $len, 'UTF-8' ) : substr( $w, 0, $len ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Score of one word against one row (0 = no match). */
	public static function token_score( array $tk, array $r, bool $with_extra ): int {
		$t     = $tk['t'];
		$best  = 0;
		$codes = (string) $r['codes'];
		if ( $tk['d'] && strlen( $t ) >= 4 && '' !== $codes ) {
			if ( self::whole( $codes, $t ) ) {
				return 40;
			}
			if ( strlen( $t ) >= 5 && self::starts( $codes, $t ) ) {
				$best = 15;
			}
		}
		$title  = (string) $r['title'];
		$people = (string) $r['people'];
		$terms  = (string) $r['terms'];
		if ( self::starts( $title, $t ) ) {
			$best = max( $best, self::whole( $title, $t ) ? 12 : 10 );
		}
		if ( $best < 10 && self::starts( $people, $t ) ) {
			$best = max( $best, self::whole( $people, $t ) ? 10 : 9 );
		}
		if ( $best >= 10 ) {
			return $best;
		}
		foreach ( $tk['alts'] as $a ) {
			if ( self::starts( $title, $a ) ) {
				$best = max( $best, 8 );
			} elseif ( self::starts( $people, $a ) ) {
				$best = max( $best, 7 );
			} elseif ( self::starts( $terms, $a ) ) {
				$best = max( $best, 4 );
			}
		}
		if ( $best < 6 && strlen( $tk['p'] ) >= 2 && self::starts( (string) $r['phon'], $tk['p'] ) ) {
			$best = 6;
		}
		if ( $best < 5 && $tk['l'] >= 3 && false !== strpos( $title, $t ) ) {
			$best = 5;
		}
		if ( $best < 4 && $tk['l'] >= 3 && false !== strpos( $people, $t ) ) {
			$best = 4;
		}
		if ( $best < 4 && self::starts( $terms, $t ) ) {
			$best = 4;
		}
		if ( 0 === $best && ! $tk['d'] && ( self::fuzzy( $title, $t, $tk['l'] ) || self::fuzzy( $people, $t, $tk['l'] ) || self::fuzzy( (string) $r['phon'], $tk['p'], $tk['l'] ) ) ) {
			$best = 3;
		}
		if ( 0 === $best && $with_extra && isset( $r['extra'] ) && self::starts( (string) $r['extra'], $t ) ) {
			$best = 2;
		}
		return $best;
	}

	/**
	 * A code typed with separators («SKU-1234», «AB.12/7»): the query as one
	 * code (letters and digits only), else ''. Latin letters, digits and
	 * - . / _ only, with at least one digit and one separator.
	 */
	public static function code_query( string $q ): string {
		$q = strtolower( trim( $q ) );
		if ( ! preg_match( '/^[a-z0-9]+(?:[\-.\/_][a-z0-9]+)+$/', $q ) || ! preg_match( '/\d/', $q ) ) {
			return '';
		}
		$c = (string) preg_replace( '/[^a-z0-9]+/', '', $q );
		return strlen( $c ) >= 4 ? $c : '';
	}

	/** Score of a row for the whole query (0 = no match). */
	public static function score( array $tokens, string $qn, array $r, bool $with_extra = false, string $qc = '' ): int {
		if ( ! $tokens ) {
			return 0;
		}
		if ( '' !== $qc && self::whole( (string) $r['codes'], $qc ) ) {
			return 40;
		}
		$sum = 0;
		foreach ( $tokens as $tk ) {
			$s = self::token_score( $tk, $r, $with_extra );
			if ( 0 === $s ) {
				return 0;
			}
			$sum += $s;
		}
		$title = (string) $r['title'];
		if ( $qn === $title ) {
			$sum += 30;
		} elseif ( '' !== $qn && 0 === strpos( $title, $qn ) ) {
			$sum += 15;
		}
		return $sum;
	}

	/**
	 * Candidate rows from the table: every word must appear somewhere (a
	 * LIKE per word), so the scorer sees few rows even in a big catalogue.
	 */
	private static function candidates( array $tokens, bool $with_extra, bool $all = false, string $qc = '' ): array {
		global $wpdb;
		if ( ! LVS_Index::exists() ) {
			return array();
		}
		$t    = LVS_Index::table();
		$cols = 'product_id, title, people, terms, codes, phon, cats, card, work, oos, sales' . ( $with_extra ? ', extra' : '' );
		if ( $all ) {
			$rows = $wpdb->get_results( "SELECT {$cols} FROM {$t} LIMIT " . (int) self::FUZZY_MAX_ROWS, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return is_array( $rows ) ? $rows : array();
		}
		$where = array();
		$args  = array();
		foreach ( $tokens as $tk ) {
			$or   = array();
			$like = '%' . $wpdb->esc_like( $tk['t'] ) . '%';
			foreach ( array( 'title', 'people', 'terms', 'codes' ) as $c ) {
				$or[]   = "{$c} LIKE %s";
				$args[] = $like;
			}
			if ( $with_extra ) {
				$or[]   = 'extra LIKE %s';
				$args[] = $like;
			}
			if ( strlen( $tk['p'] ) >= 2 ) {
				$or[]   = 'phon LIKE %s';
				$args[] = '%' . $wpdb->esc_like( $tk['p'] ) . '%';
			}
			foreach ( array_slice( $tk['alts'], 0, 5 ) as $a ) {
				$al = '%' . $wpdb->esc_like( $a ) . '%';
				$or[]   = '(title LIKE %s OR people LIKE %s OR terms LIKE %s)';
				$args[] = $al;
				$args[] = $al;
				$args[] = $al;
			}
			$where[] = '(' . implode( ' OR ', $or ) . ')';
		}
		$cond = implode( ' AND ', $where );
		if ( '' !== $qc ) {
			$cond   = '(' . $cond . ') OR codes LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $qc ) . '%';
		}
		$sql  = "SELECT {$cols} FROM {$t} WHERE {$cond} LIMIT 3000";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Ranked rows for a query.
	 *
	 * @param string $context 'results' (short description too) | 'suggest'.
	 * @return array[] Rows with an added 'score', best first.
	 */
	public static function find( string $q, string $context = 'suggest', int $limit = 500 ): array {
		$tokens = self::tokens( $q );
		if ( ! $tokens ) {
			return array();
		}
		$qn    = LVS_Text::norm( $q );
		$extra = 'results' === $context && LVS_Settings::get()['excerpt'];
		$key   = 'f' . LVS_Index::version() . ':' . md5( $context . '|' . $qn . '|' . self::code_query( $q ) . '|' . $limit . '|' . LVS_Settings::get()['oos'] );
		$hit   = wp_cache_get( $key, 'lvs' );
		if ( is_array( $hit ) ) {
			return $hit;
		}

		$qc   = self::code_query( $q );
		$rows = self::candidates( $tokens, $extra, false, $qc );
		$out  = self::rank( $rows, $tokens, $qn, $extra, $qc );
		if ( ! $out && LVS_Index::count_visible() <= self::FUZZY_MAX_ROWS ) {
			// Nothing: a typo may hide behind a word that no LIKE finds.
			$out = self::rank( self::candidates( $tokens, $extra, true ), $tokens, $qn, $extra, $qc );
		}
		$out = array_slice( $out, 0, $limit );
		wp_cache_set( $key, $out, 'lvs', 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	private static function rank( array $rows, array $tokens, string $qn, bool $extra, string $qc = '' ): array {
		$oos = LVS_Settings::get()['oos'];
		$out = array();
		foreach ( $rows as $r ) {
			if ( 'hide' === $oos && (int) $r['oos'] ) {
				continue;
			}
			$s = self::score( $tokens, $qn, $r, $extra, $qc );
			if ( $s > 0 ) {
				$r['score'] = $s;
				unset( $r['extra'] );
				$out[] = $r;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) use ( $oos ) {
				if ( 'last' === $oos && (int) $a['oos'] !== (int) $b['oos'] ) {
					return (int) $a['oos'] - (int) $b['oos'];
				}
				if ( $a['score'] !== $b['score'] ) {
					return $b['score'] - $a['score'];
				}
				if ( (int) $a['sales'] !== (int) $b['sales'] ) {
					return (int) $b['sales'] - (int) $a['sales'];
				}
				return (int) $a['product_id'] - (int) $b['product_id'];
			}
		);
		return $out;
	}

	/** The one product whose code is exactly this ISBN, or 0. */
	public static function isbn_product( string $q ): int {
		global $wpdb;
		if ( ! LVS_Text::is_isbn( $q ) || ! LVS_Index::exists() ) {
			return 0;
		}
		$c  = LVS_Text::codes( $q );
		$t  = LVS_Index::table();
		$id = 0;
		foreach ( $c as $code ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT product_id FROM {$t} WHERE CONCAT(' ', codes, ' ') LIKE %s LIMIT 2", '% ' . $wpdb->esc_like( $code ) . ' %' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( is_array( $ids ) && 1 === count( $ids ) ) {
				$id = (int) $ids[0];
				break;
			}
			if ( is_array( $ids ) && count( $ids ) > 1 ) {
				return 0;
			}
		}
		return $id;
	}

	/** Rows Hide Catalog hides from search are left out (REST, abilities). */
	public static function visible_rows( array $rows ): array {
		$hidden = function_exists( 'hic_hidden_terms' ) ? hic_hidden_terms( 'search' ) : array();
		if ( ! $hidden ) {
			return $rows;
		}
		return array_values(
			array_filter(
				$rows,
				static function ( $r ) use ( $hidden ) {
					$cats = array_map( 'intval', array_filter( explode( ',', (string) $r['cats'] ), 'strlen' ) );
					return ! array_intersect( $cats, $hidden );
				}
			)
		);
	}

	/** Matching creator pages: every word at the start of a name word or its key. */
	public static function creators( string $q, int $limit = 3 ): array {
		$tokens = self::tokens( $q );
		if ( ! $tokens || ! LVS_Settings::get()['creators'] ) {
			return array();
		}
		$key = 'cr' . LVS_Index::version() . ':' . md5( LVS_Text::norm( $q ) );
		$hit = wp_cache_get( $key, 'lvs' );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$out = array();
		foreach ( LVS_File::creators() as $c ) {
			$ok = true;
			foreach ( $tokens as $tk ) {
				if ( ! self::starts( $c[2], $tk['t'] ) && ! ( strlen( $tk['p'] ) >= 2 && self::starts( $c[3], $tk['p'] ) ) ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				$out[] = $c;
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
		}
		wp_cache_set( $key, $out, 'lvs', 10 * MINUTE_IN_SECONDS );
		return $out;
	}
}

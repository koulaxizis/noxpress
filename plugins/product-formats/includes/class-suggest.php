<?php
/**
 * PFM_Suggest — work suggestions (admin only, read only).
 *
 * Signals, joined with union-find:
 *  1. Slug: a known format suffix is removed ("odoiporos-ebook" →
 *     "odoiporos"); products with the same stem form a group. A slug
 *     without a suffix is its own stem ("odoiporos").
 *  2. Title: lower case, no accents, no [..] / (..) parts, letters and
 *     digits only ("10 +1 Τραγούδια" = "10+1 τραγούδια").
 *  Upsells are a confidence signal only: they never join products.
 *
 * Exclusions: bundles (a title with " + " or " & "), variations, trashed
 * products, rejected suggestions, groups whose members already sit in one
 * work, and groups that span two works (merging is the admin's call).
 *
 * The format of each member comes from the work it is already in, then
 * the slug suffix, then the product categories (format registry). A slug
 * without a suffix does NOT mean "print".
 *
 * Nothing is written here: accept() in the admin applies a suggestion
 * after the shop manager has checked it.
 */

defined( 'ABSPATH' ) || exit;

final class PFM_Suggest {

	const STATUSES    = array( 'publish', 'draft', 'pending', 'private', 'future' );
	const MAX_MEMBERS = 30;

	/** Union-find parents. */
	private static $parent = array();

	/**
	 * Runs a scan of the whole catalog.
	 *
	 * @return array[] Suggestions, best first.
	 */
	public static function scan(): array {
		global $wpdb;

		$in = "'" . implode( "','", self::STATUSES ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed status list, admin scan.
		$rows = $wpdb->get_results( "SELECT ID, post_name, post_title, post_status FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ($in)", ARRAY_A );
		if ( ! is_array( $rows ) || ! $rows ) {
			return array();
		}

		$products = array();
		foreach ( $rows as $r ) {
			$products[ (int) $r['ID'] ] = array(
				'id'     => (int) $r['ID'],
				'slug'   => rawurldecode( (string) $r['post_name'] ),
				'title'  => (string) $r['post_title'],
				'status' => (string) $r['post_status'],
			);
		}

		$works   = self::relationships( PFM_Works::TAX );
		$cats    = self::relationships( 'product_cat' );
		$upsells = self::upsells();
		$cat_map = self::category_formats();

		self::$parent = array();
		$by_key       = array();
		foreach ( $products as $id => &$p ) {
			$p['bundle']  = (bool) preg_match( '/\s[+&]\s/u', $p['title'] );
			$p['variant'] = self::bracket( $p['title'] );
			list( $stem, $p['suffix_format'] ) = self::stem( $p['slug'] );
			if ( $p['bundle'] ) {
				continue;
			}
			$keys = array();
			if ( '' !== $stem ) {
				$keys[] = 's:' . $stem;
			}
			$norm = self::normalize( $p['title'] );
			if ( '' !== $norm ) {
				$keys[] = 't:' . $norm;
			}
			foreach ( $keys as $k ) {
				if ( isset( $by_key[ $k ] ) ) {
					self::union( $by_key[ $k ], $id );
				} else {
					$by_key[ $k ] = $id;
					self::find( $id );
				}
			}
		}
		unset( $p );

		$groups = array();
		foreach ( array_keys( self::$parent ) as $id ) {
			$groups[ self::find( $id ) ][] = $id;
		}

		$rejected = array_flip( PFM_Settings::rejected() );
		$out      = array();
		foreach ( $groups as $ids ) {
			if ( count( $ids ) < 2 || count( $ids ) > self::MAX_MEMBERS ) {
				continue;
			}
			sort( $ids );
			$sig = self::signature( $ids );
			if ( isset( $rejected[ $sig ] ) ) {
				continue;
			}
			$in_works = array();
			foreach ( $ids as $id ) {
				if ( ! empty( $works[ $id ] ) ) {
					$in_works[ (int) min( $works[ $id ] ) ] = true;
				}
			}
			if ( count( $in_works ) > 1 ) {
				continue; // Spans two works: merging is the admin's call.
			}
			$target = $in_works ? (int) key( $in_works ) : 0;
			if ( $target && count( array_filter( $ids, static function ( $id ) use ( $works ) {
				return ! empty( $works[ $id ] );
			} ) ) === count( $ids ) ) {
				continue; // Already one work.
			}
			$out[] = self::build( $ids, $sig, $target, $products, $cats, $cat_map, $upsells, $works );
		}

		usort(
			$out,
			static function ( $a, $b ) {
				if ( $a['confidence'] !== $b['confidence'] ) {
					return 'high' === $a['confidence'] ? -1 : 1;
				}
				return strcmp( $a['name'], $b['name'] );
			}
		);
		return $out;
	}

	private static function build( array $ids, string $sig, int $target, array $products, array $cats, array $cat_map, array $upsells, array $works ): array {
		$members = array();
		$stems   = array();
		foreach ( $ids as $id ) {
			$p = $products[ $id ];
			list( $stem ) = self::stem( $p['slug'] );
			$stems[ $stem ] = true;

			$format  = '';
			$source  = '';
			$variant = $p['variant'];
			if ( ! empty( $works[ $id ] ) ) {
				$format  = PFM_Works::format_of( $id );
				$variant = PFM_Works::variant_of( $id );
				$source  = 'work';
			}
			if ( '' === $format && '' !== $p['suffix_format'] ) {
				$format = $p['suffix_format'];
				$source = 'suffix';
			}
			if ( '' === $format ) {
				foreach ( $cats[ $id ] ?? array() as $cat_id ) {
					if ( isset( $cat_map[ $cat_id ] ) ) {
						$format = $cat_map[ $cat_id ];
						$source = 'cat';
						break;
					}
				}
			}
			$members[] = array(
				'id'      => $id,
				'title'   => $p['title'],
				'slug'    => $p['slug'],
				'status'  => $p['status'],
				'format'  => $format,
				'source'  => $source,
				'variant' => $variant,
				'in_work' => ! empty( $works[ $id ] ),
			);
		}
		// A bracket note becomes the subtitle only where two members share a
		// format ("[ebook]", "(audiobook)" alone would just repeat the label).
		$per_format = array_count_values( array_filter( wp_list_pluck( $members, 'format' ) ) );
		$words      = self::format_words();
		foreach ( $members as $i => $m ) {
			if ( ! $m['in_work'] && ( '' === $m['format'] || ( $per_format[ $m['format'] ] ?? 0 ) < 2 || isset( $words[ self::normalize( $m['variant'] ) ] ) ) ) {
				$members[ $i ]['variant'] = '';
			}
		}
		$members = PFM_Works::sort( $members );

		// Notes and confidence.
		$notes   = array();
		$missing = false;
		$dupe    = false;
		$seen    = array();
		foreach ( $members as $m ) {
			if ( '' === $m['format'] ) {
				$missing = true;
				continue;
			}
			$k = $m['format'] . '|' . $m['variant'];
			if ( isset( $seen[ $k ] ) ) {
				$dupe = true;
			}
			$seen[ $k ] = true;
		}
		if ( $missing ) {
			$notes[] = 'missing';
		}
		if ( $dupe ) {
			$notes[] = 'dupe';
		}
		$linked = true;
		foreach ( $ids as $id ) {
			if ( ! array_intersect( $upsells[ $id ] ?? array(), array_diff( $ids, array( $id ) ) ) ) {
				$linked = false;
				break;
			}
		}
		if ( $linked ) {
			$notes[] = 'upsells';
		}
		$high = ! $missing && ! $dupe && ( $linked || 1 === count( $stems ) );

		// Work name: the existing work, else the print title, else the first member.
		$name = '';
		if ( $target ) {
			$t    = PFM_Works::work( $target );
			$name = $t ? $t->name : '';
		}
		if ( '' === $name ) {
			$pick = $members[0];
			foreach ( $members as $m ) {
				if ( 'print' === $m['format'] ) {
					$pick = $m;
					break;
				}
			}
			$name = self::clean_title( $pick['title'] );
		}

		return array(
			'sig'        => $sig,
			'target'     => $target,
			'name'       => $name,
			'members'    => $members,
			'confidence' => $high ? 'high' : 'check',
			'notes'      => $notes,
		);
	}

	/* =====================================================================
	 * Signals
	 * =================================================================== */

	/**
	 * Slug stem and the format of its suffix.
	 *
	 * @return array{0: string, 1: string} Stem ('' for no slug), format key or ''.
	 */
	public static function stem( string $slug ): array {
		$slug = strtolower( trim( $slug ) );
		if ( '' === $slug ) {
			return array( '', '' );
		}
		$best_len = 0;
		$best     = array( $slug, '' );
		foreach ( PFM_Settings::enabled_formats() as $key => $f ) {
			foreach ( $f['suffixes'] as $suffix ) {
				$tail = '-' . $suffix;
				$len  = strlen( $tail );
				if ( $len > $best_len && strlen( $slug ) > $len && substr( $slug, -$len ) === $tail ) {
					$best_len = $len;
					$best     = array( substr( $slug, 0, -$len ), $key );
				}
			}
		}
		return $best;
	}

	/** Title key: lower case, no accents, no bracket parts, letters and digits only. */
	public static function normalize( string $title ): string {
		$t = self::clean_title( $title );
		$t = function_exists( 'mb_strtolower' ) ? mb_strtolower( $t, 'UTF-8' ) : strtolower( $t );
		$t = self::strip_accents( $t );
		$t = str_replace( 'ς', 'σ', $t );
		$t = (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', $t );
		$n = function_exists( 'mb_strlen' ) ? mb_strlen( $t, 'UTF-8' ) : strlen( $t );
		return $n >= 3 ? $t : '';
	}

	/** The title without [..], (..), {..} parts and extra spaces. */
	public static function clean_title( string $title ): string {
		$t = wp_strip_all_tags( html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) );
		$t = (string) preg_replace( '/\[[^\]]*\]|\([^)]*\)|\{[^}]*\}/u', ' ', $t );
		$t = (string) preg_replace( '/\s+/u', ' ', $t );
		return trim( $t, " \t\n\r\0\x0B-–—:·" );
	}

	/** Normalised keys, slug suffixes and labels of every format (set). */
	private static function format_words(): array {
		$out = array();
		foreach ( PFM_Settings::formats() as $f ) {
			$words = array_merge( array( $f['key'], $f['label_el'], $f['label_en'], PFM_Settings::label( $f['key'] ) ), $f['suffixes'] );
			foreach ( $words as $w ) {
				$n = self::normalize( (string) $w );
				if ( '' !== $n ) {
					$out[ $n ] = true;
				}
			}
		}
		return $out;
	}

	/** The text of the first [..] or (..) part, used as the variant. */
	public static function bracket( string $title ): string {
		$t = html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );
		if ( preg_match( '/\[([^\]]+)\]|\(([^)]+)\)/u', $t, $m ) ) {
			$v = trim( '' !== ( $m[1] ?? '' ) ? $m[1] : ( $m[2] ?? '' ) );
			return PFM_Settings::clip( sanitize_text_field( $v ), PFM_Works::MAX_VARIANT );
		}
		return '';
	}

	private static function strip_accents( string $t ): string {
		if ( class_exists( 'Normalizer' ) ) {
			$n = Normalizer::normalize( $t, Normalizer::FORM_D );
			if ( is_string( $n ) ) {
				return (string) preg_replace( '/\p{Mn}+/u', '', $n );
			}
		}
		$t = strtr(
			$t,
			array(
				'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ϊ' => 'ι', 'ΐ' => 'ι',
				'ό' => 'ο', 'ύ' => 'υ', 'ϋ' => 'υ', 'ΰ' => 'υ', 'ώ' => 'ω',
			)
		);
		return remove_accents( $t );
	}

	public static function signature( array $ids ): string {
		$ids = array_map( 'intval', $ids );
		sort( $ids );
		return substr( md5( implode( ',', $ids ) ), 0, 12 );
	}

	/* =====================================================================
	 * Data (one query each)
	 * =================================================================== */

	/** product id => term ids of one taxonomy. */
	private static function relationships( string $taxonomy ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin scan, one query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tr.object_id, tt.term_id FROM {$wpdb->term_relationships} tr
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 WHERE tt.taxonomy = %s",
				$taxonomy
			),
			ARRAY_A
		);
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$out[ (int) $r['object_id'] ][] = (int) $r['term_id'];
		}
		return $out;
	}

	/** product id => upsell ids. */
	private static function upsells(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin scan, one query.
		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_upsell_ids'", ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$v = maybe_unserialize( $r['meta_value'] );
			if ( is_array( $v ) && $v ) {
				$out[ (int) $r['post_id'] ] = array_map( 'intval', $v );
			}
		}
		return $out;
	}

	/**
	 * category id => format key, from the registry. A category set on a
	 * format also covers its subcategories; the first format in the
	 * registry order wins.
	 */
	public static function category_formats(): array {
		$map = array();
		foreach ( PFM_Settings::enabled_formats() as $key => $f ) {
			foreach ( $f['cats'] as $cat_id ) {
				if ( ! isset( $map[ $cat_id ] ) ) {
					$map[ $cat_id ] = $key;
				}
				$children = get_term_children( $cat_id, 'product_cat' );
				foreach ( is_array( $children ) ? $children : array() as $child ) {
					if ( ! isset( $map[ (int) $child ] ) ) {
						$map[ (int) $child ] = $key;
					}
				}
			}
		}
		return $map;
	}

	/* =====================================================================
	 * Union-find
	 * =================================================================== */

	private static function find( int $id ): int {
		if ( ! isset( self::$parent[ $id ] ) ) {
			self::$parent[ $id ] = $id;
			return $id;
		}
		$root = $id;
		while ( self::$parent[ $root ] !== $root ) {
			$root = self::$parent[ $root ];
		}
		while ( self::$parent[ $id ] !== $root ) {
			$next                = self::$parent[ $id ];
			self::$parent[ $id ] = $root;
			$id                  = $next;
		}
		return $root;
	}

	private static function union( int $a, int $b ): void {
		$ra = self::find( $a );
		$rb = self::find( $b );
		if ( $ra !== $rb ) {
			self::$parent[ max( $ra, $rb ) ] = min( $ra, $rb );
		}
	}
}

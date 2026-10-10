<?php
/**
 * SHF_Groups — value groups on top of an attribute's existing terms.
 *
 *  - Terms of an attribute (cached per request and in the object cache).
 *  - Filter options: groups, the automatic "Other" group, single terms;
 *    URL tokens for each of them (group slug, ASCII term slug or term id).
 *  - Helpers for the admin, which only ever PROPOSE: the range parser
 *    ("6-18M", "12 μηνών-5 ετών", "3 Ετών+" → months) and keyword rules
 *    (accent and case insensitive, whole words). Nothing is assigned until
 *    the admin saves; the front end only reads the saved term ids.
 *  - Suspicious values (bare numbers, terms without products) are flagged
 *    for the "ignore" list; product data is never changed.
 */

defined( 'ABSPATH' ) || exit;

final class SHF_Groups {

	/** Months per unit. */
	const UNIT_MONTHS = array(
		'm' => 1,
		'y' => 12,
	);

	/** Per-request caches. */
	private static $terms   = array();
	private static $options = array();

	/* =====================================================================
	 * Terms
	 * =================================================================== */

	/**
	 * Terms of an attribute, in the attribute's own order.
	 *
	 * @return array<int, array{id:int, name:string, slug:string, count:int}>
	 */
	public static function terms( string $attr ): array {
		if ( isset( self::$terms[ $attr ] ) ) {
			return self::$terms[ $attr ];
		}
		$tax   = wc_attribute_taxonomy_name( $attr );
		$key   = 'terms:' . $tax . ':' . wp_cache_get_last_changed( 'terms' );
		$found = false;
		$list  = wp_cache_get( $key, 'shf', false, $found );
		if ( ! $found || ! is_array( $list ) ) {
			$list  = array();
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
				)
			);
			if ( is_array( $terms ) ) {
				// get_terms() already applies the attribute's order (menu_order / name / id).
				foreach ( $terms as $t ) {
					$list[] = array(
						'id'    => (int) $t->term_id,
						'name'  => (string) $t->name,
						'slug'  => (string) $t->slug,
						'count' => (int) $t->count,
					);
				}
			}
			wp_cache_set( $key, $list, 'shf', HOUR_IN_SECONDS );
		}
		self::$terms[ $attr ] = $list;
		return $list;
	}

	/* =====================================================================
	 * Filter options
	 * =================================================================== */

	/**
	 * Options shown by an attribute filter, in display order.
	 *
	 * @return array<string, array{label:string, terms:int[]}> token → option
	 */
	public static function options( string $attr ): array {
		if ( isset( self::$options[ $attr ] ) ) {
			return self::$options[ $attr ];
		}
		$cfg    = SHF_Settings::groups( $attr );
		$ignore = array_flip( $cfg['ignore'] );
		$terms  = self::terms( $attr );
		$out    = array();

		if ( ! $cfg['groups'] ) {
			foreach ( $terms as $t ) {
				if ( ! isset( $ignore[ $t['id'] ] ) ) {
					$out[ self::term_token( $t, array() ) ] = array(
						'label' => $t['name'],
						'terms' => array( $t['id'] ),
					);
				}
			}
			self::$options[ $attr ] = $out;
			return $out;
		}

		$grouped = array();
		$slugs   = array();
		foreach ( $cfg['groups'] as $g ) {
			$out[ $g['slug'] ]   = array(
				'label' => $g['label'],
				'terms' => $g['terms'],
			);
			$slugs[ $g['slug'] ] = true;
			foreach ( $g['terms'] as $id ) {
				$grouped[ $id ] = true;
			}
		}

		$rest = array();
		foreach ( $terms as $t ) {
			if ( ! isset( $grouped[ $t['id'] ] ) && ! isset( $ignore[ $t['id'] ] ) ) {
				$rest[] = $t;
			}
		}
		if ( $rest && 'other' === $cfg['ungrouped'] ) {
			$out['other'] = array(
				'label' => __( 'Άλλο', 'shop-filters' ),
				'terms' => array_column( $rest, 'id' ),
			);
		} elseif ( $rest && 'show' === $cfg['ungrouped'] ) {
			foreach ( $rest as $t ) {
				$out[ self::term_token( $t, $slugs ) ] = array(
					'label' => $t['name'],
					'terms' => array( $t['id'] ),
				);
			}
		}
		self::$options[ $attr ] = $out;
		return $out;
	}

	/**
	 * URL token of a single term: its slug when it is plain ASCII and cannot
	 * be mistaken for a group or an id, otherwise its term id.
	 */
	private static function term_token( array $t, array $group_slugs ): string {
		$slug = $t['slug'];
		if ( preg_match( '/^[a-z0-9-]{1,60}$/', $slug ) && ! ctype_digit( $slug ) && ! isset( $group_slugs[ $slug ] ) && 'other' !== $slug ) {
			return $slug;
		}
		return (string) $t['id'];
	}

	/** Flush the per-request caches (admin, after a save). */
	public static function reset(): void {
		self::$terms   = array();
		self::$options = array();
	}

	/* =====================================================================
	 * Admin helpers: normalisation, range parser, keywords, suggestions
	 * =================================================================== */

	/** Lowercase, without Greek tonos / dialytika or Latin accents, single spaces. */
	public static function normalize( string $s ): string {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		$s = strtr(
			$s,
			array(
				'ά' => 'α',
				'έ' => 'ε',
				'ή' => 'η',
				'ί' => 'ι',
				'ό' => 'ο',
				'ύ' => 'υ',
				'ώ' => 'ω',
				'ΐ' => 'ι',
				'ΰ' => 'υ',
				'ϊ' => 'ι',
				'ϋ' => 'υ',
				'ς' => 'σ',
			)
		);
		$s = remove_accents( $s );
		return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
	}

	/**
	 * Parses an age-like value into a range in months.
	 *
	 * Recognised: "0-6M", "6-18 μηνών", "12 μηνών-5 ετών", "3 Ετών+",
	 * "6 μηνών +", "4-5Y", "Απο 6 μηνών", "0+", "12 ετών". Values with
	 * another unit (cm, kg…), decimals or no unit are not recognised.
	 *
	 * @return array{0:float,1:float}|null [min, max] in months; max = INF for "A+".
	 */
	public static function parse_range( string $name ) {
		$s = self::normalize( $name );
		if ( '' === $s ) {
			return null;
		}
		// Another unit or a decimal number: not an age.
		if ( preg_match( '/(εκατοστ|cm\b|κιλ|kg\b|γρ\b|gr\b|ml\b|lt\b|\d[.,]\d)/u', $s ) ) {
			return null;
		}
		$unit = '(μην\p{L}*|μ\b|m\b|months?\b|mo\b|[eε]τ\p{L}*|χρον\p{L}*|y\b|yrs?\b|years?\b)?';
		if ( ! preg_match_all( '/(\d{1,3})\s*' . $unit . '/u', $s, $m, PREG_SET_ORDER ) ) {
			return null;
		}
		$nums = array();
		foreach ( $m as $hit ) {
			$nums[] = array( (float) $hit[1], self::unit_of( $hit[2] ?? '' ) );
		}
		$plus = (bool) preg_match( '/\+/', $s ) || (bool) preg_match( '/^απο\b/u', $s );

		if ( 2 === count( $nums ) && preg_match( '/\d\s*\p{L}*\s*-\s*\d/u', $s ) ) {
			list( $a, $ua ) = $nums[0];
			list( $b, $ub ) = $nums[1];
			if ( '' === $ub ) {
				return null;
			}
			$ua = '' === $ua ? $ub : $ua;
			$lo = $a * self::UNIT_MONTHS[ $ua ];
			$hi = $b * self::UNIT_MONTHS[ $ub ];
			return $lo <= $hi ? array( $lo, $hi ) : null;
		}
		if ( 1 === count( $nums ) ) {
			list( $a, $ua ) = $nums[0];
			if ( '' === $ua ) {
				// Only "0+" makes sense without a unit.
				return ( $plus && 0.0 === $a ) ? array( 0.0, INF ) : null;
			}
			$v = $a * self::UNIT_MONTHS[ $ua ];
			return $plus ? array( $v, INF ) : array( $v, $v );
		}
		return null;
	}

	private static function unit_of( string $u ): string {
		if ( '' === $u ) {
			return '';
		}
		if ( preg_match( '/^(μ|m)/u', $u ) ) {
			return 'm';
		}
		return 'y';
	}

	/**
	 * Does a term range overlap a group range? Group bounds are in months,
	 * the upper bound is exclusive (null = no upper bound). Ranges that only
	 * touch at a boundary ("0-6 months" vs a 6-12 group) do not overlap.
	 */
	public static function overlaps( array $term, float $gmin, ?float $gmax ): bool {
		list( $t1, $t2 ) = $term;
		$gmax            = null === $gmax ? INF : $gmax;
		if ( $t1 === $t2 ) {
			return $t1 >= $gmin && $t1 < $gmax;
		}
		return $t1 < $gmax && $t2 > $gmin;
	}

	/** Keywords of a group, normalised. */
	public static function keywords( string $kw ): array {
		$out = array();
		foreach ( explode( ',', $kw ) as $k ) {
			$k = self::normalize( $k );
			if ( '' !== $k ) {
				$out[] = $k;
			}
		}
		return $out;
	}

	/** Whole-word match of any keyword inside a normalised name. */
	public static function matches_keywords( string $normalized_name, array $keywords ): bool {
		foreach ( $keywords as $k ) {
			if ( preg_match( '/(^|[^\p{L}\p{N}])' . preg_quote( $k, '/' ) . '($|[^\p{L}\p{N}])/u', $normalized_name ) ) {
				return true;
			}
		}
		return false;
	}

	/** Bare numbers ("0.490", "5,70") or terms without products. */
	public static function is_suspicious( array $t ): bool {
		return 0 === $t['count'] || (bool) preg_match( '/^[\d\s.,]+$/u', trim( $t['name'] ) );
	}

	/**
	 * Suggested groups per term, from the groups' ranges and keywords.
	 *
	 * @return array<int, string[]> term id → group slugs
	 */
	public static function suggest( string $attr, array $cfg ): array {
		$mult = self::UNIT_MONTHS[ $cfg['unit'] ] ?? 1;
		$out  = array();
		foreach ( self::terms( $attr ) as $t ) {
			$range = null;
			$norm  = self::normalize( $t['name'] );
			foreach ( $cfg['groups'] as $g ) {
				$hit = false;
				if ( null !== $g['rmin'] ) {
					if ( null === $range ) {
						$range = self::parse_range( $t['name'] );
						$range = null === $range ? false : $range;
					}
					if ( false !== $range ) {
						$hit = self::overlaps( $range, (float) $g['rmin'] * $mult, null === $g['rmax'] ? null : (float) $g['rmax'] * $mult );
					}
				}
				if ( ! $hit && '' !== $g['kw'] ) {
					$hit = self::matches_keywords( $norm, self::keywords( $g['kw'] ) );
				}
				if ( $hit ) {
					$out[ $t['id'] ][] = $g['slug'];
				}
			}
		}
		return $out;
	}

	/**
	 * Terms that belong to no group and are not ignored.
	 *
	 * @return int[]
	 */
	public static function ungrouped( string $attr ): array {
		$cfg  = SHF_Settings::groups( $attr );
		$seen = array_flip( $cfg['ignore'] );
		foreach ( $cfg['groups'] as $g ) {
			foreach ( $g['terms'] as $id ) {
				$seen[ $id ] = true;
			}
		}
		$out = array();
		foreach ( self::terms( $attr ) as $t ) {
			if ( ! isset( $seen[ $t['id'] ] ) && $t['count'] > 0 ) {
				$out[] = $t['id'];
			}
		}
		return $out;
	}
}

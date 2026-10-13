<?php
/**
 * HIC_Settings — the hic_settings option (autoload: read on every catalog
 * view), strict validation with whitelists (Bible §11).
 *
 *  - mode   'off' (default) | 'test' (shop managers only) | 'live'
 *  - purge  clear known page caches when the settings are saved (default on)
 *  - rules  list of rules, one per product category, max MAX_RULES:
 *      cat       product_cat term id
 *      children  the rule also covers the subcategories
 *      shop      products hidden from the shop page
 *      listings  … from other product listings (other categories, tags,
 *                attributes, brands)
 *      search    … from search results
 *      related   … from related products, upsells and cross-sells
 *      blocks    … from product shortcodes and blocks
 *      terms     the category itself is hidden from category lists, from
 *                a product's categories and from breadcrumbs
 *      archive   the category page: 'open' | 'noindex' | '404' | 'redirect'
 *      all_only  a product is hidden only when every category it has is
 *                hidden (default: hidden when it has any hidden category)
 */

defined( 'ABSPATH' ) || exit;

final class HIC_Settings {

	const OPT       = 'hic_settings';
	const MAX_RULES = 50;

	/** Product switches of a rule (contexts). */
	const CONTEXTS = array( 'shop', 'listings', 'search', 'related', 'blocks' );

	const ARCHIVE = array( 'open', 'noindex', '404', 'redirect' );

	/** @var array|null Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'mode'  => 'off',
			'purge' => true,
			'rules' => array(),
		);
	}

	/** An empty rule: hides nothing until a switch is on. */
	public static function rule_defaults(): array {
		return array(
			'cat'      => 0,
			'children' => true,
			'shop'     => false,
			'listings' => false,
			'search'   => false,
			'related'  => false,
			'blocks'   => false,
			'terms'    => false,
			'archive'  => 'open',
			'all_only' => false,
		);
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPT, array() );
			self::$cache = self::sanitize( is_array( $raw ) ? $raw : array() );
		}
		return self::$cache;
	}

	public static function save( array $s ): void {
		$s           = self::sanitize( $s, true );
		self::$cache = $s;
		update_option( self::OPT, $s, true );
	}

	/** Drops the per-request cache (tests, imports). */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Whitelist validation: unknown keys are dropped, bad values fall back
	 * to the defaults. With $check (on save), rules whose category does not
	 * exist are dropped; reads skip that query.
	 */
	public static function sanitize( array $raw, bool $check = false ): array {
		$d = self::defaults();
		$s = array();

		$s['mode']  = isset( $raw['mode'] ) && in_array( $raw['mode'], array( 'off', 'test', 'live' ), true ) ? $raw['mode'] : $d['mode'];
		$s['purge'] = isset( $raw['purge'] ) ? (bool) $raw['purge'] : $d['purge'];

		$s['rules'] = array();
		$seen       = array();
		if ( isset( $raw['rules'] ) && is_array( $raw['rules'] ) ) {
			foreach ( $raw['rules'] as $row ) {
				$rule = is_array( $row ) ? self::rule( $row, $check ) : null;
				if ( null === $rule || isset( $seen[ $rule['cat'] ] ) ) {
					continue;
				}
				$seen[ $rule['cat'] ] = true;
				$s['rules'][]         = $rule;
				if ( count( $s['rules'] ) >= self::MAX_RULES ) {
					break;
				}
			}
		}
		return $s;
	}

	/** One rule, or null when its category is missing (or, with $check, not a product category). */
	public static function rule( array $row, bool $check = false ): ?array {
		$cat = isset( $row['cat'] ) && is_scalar( $row['cat'] ) ? (int) $row['cat'] : 0;
		if ( $cat <= 0 ) {
			return null;
		}
		if ( $check && taxonomy_exists( 'product_cat' ) && ! term_exists( $cat, 'product_cat' ) ) {
			return null;
		}
		$r        = self::rule_defaults();
		$r['cat'] = $cat;
		foreach ( array( 'children', 'shop', 'listings', 'search', 'related', 'blocks', 'terms', 'all_only' ) as $k ) {
			if ( isset( $row[ $k ] ) ) {
				$r[ $k ] = (bool) $row[ $k ];
			}
		}
		$r['archive'] = isset( $row['archive'] ) && in_array( $row['archive'], self::ARCHIVE, true ) ? $row['archive'] : 'open';
		return $r;
	}

	/** True when the rule changes anything at all. */
	public static function rule_does_something( array $r ): bool {
		foreach ( self::CONTEXTS as $c ) {
			if ( ! empty( $r[ $c ] ) ) {
				return true;
			}
		}
		return ! empty( $r['terms'] ) || 'open' !== $r['archive'];
	}
}

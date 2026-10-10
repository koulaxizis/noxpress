<?php
/**
 * SHF_Settings — options, defaults and strict validation (Bible §11, §12).
 *
 * Options (all prefixed shf_):
 *  - shf_settings  (autoload): enabled, test_mode, apply mode, empty
 *    options, max values per request, SEO switch.
 *  - shf_sets      (autoload): filter sets. Each set has a name, the
 *    product categories it is used on (empty = default set) and an
 *    ordered list of filters (category / attribute / price).
 *  - shf_groups_{attribute} (autoload = no): value groups of one global
 *    attribute: groups (slug, label, term ids, optional range and
 *    keywords), ignored terms, what to do with values in no group.
 *
 * Terms are always referenced by term_id (a renamed term keeps its group).
 * Every write goes through the sanitize_* methods below, the forms and the
 * JSON import alike.
 */

defined( 'ABSPATH' ) || exit;

final class SHF_Settings {

	const OPT_SETTINGS = 'shf_settings';
	const OPT_SETS     = 'shf_sets';
	const OPT_GROUPS   = 'shf_groups_'; // + attribute slug (without pa_).

	const MAX_SETS     = 20;
	const MAX_FILTERS  = 15;
	const MAX_GROUPS   = 60;
	const MAX_TERMS    = 5000;

	/** Per-request caches. */
	private static $settings = null;
	private static $sets     = null;
	private static $groups   = array();

	/* =====================================================================
	 * General settings
	 * =================================================================== */

	public static function defaults(): array {
		return array(
			'enabled'    => false,
			'test_mode'  => true,
			'apply'      => 'auto',   // auto (button on narrow screens) | instant | button.
			'empty'      => 'hide',   // hide | dim.
			'max_values' => 20,
			'seo'        => true,
		);
	}

	public static function get(): array {
		if ( null === self::$settings ) {
			$raw            = get_option( self::OPT_SETTINGS, array() );
			self::$settings = self::sanitize_settings( is_array( $raw ) ? $raw : array() );
		}
		return self::$settings;
	}

	public static function save( array $s ): void {
		self::$settings = self::sanitize_settings( $s );
		update_option( self::OPT_SETTINGS, self::$settings, true );
	}

	public static function sanitize_settings( array $raw ): array {
		$d = self::defaults();
		return array(
			'enabled'    => ! empty( $raw['enabled'] ),
			'test_mode'  => isset( $raw['test_mode'] ) ? ! empty( $raw['test_mode'] ) : $d['test_mode'],
			'apply'      => isset( $raw['apply'] ) && in_array( $raw['apply'], array( 'auto', 'instant', 'button' ), true ) ? $raw['apply'] : $d['apply'],
			'empty'      => isset( $raw['empty'] ) && in_array( $raw['empty'], array( 'hide', 'dim' ), true ) ? $raw['empty'] : $d['empty'],
			'max_values' => isset( $raw['max_values'] ) ? max( 1, min( 50, (int) $raw['max_values'] ) ) : $d['max_values'],
			'seo'        => isset( $raw['seo'] ) ? ! empty( $raw['seo'] ) : $d['seo'],
		);
	}

	/* =====================================================================
	 * Attributes (global WooCommerce attributes only)
	 * =================================================================== */

	/**
	 * Global attributes: slug (without pa_) → label.
	 *
	 * @return array<string,string>
	 */
	public static function attributes(): array {
		$out = array();
		foreach ( (array) wc_get_attribute_taxonomies() as $a ) {
			if ( isset( $a->attribute_name ) && taxonomy_exists( wc_attribute_taxonomy_name( $a->attribute_name ) ) ) {
				$out[ (string) $a->attribute_name ] = (string) $a->attribute_label;
			}
		}
		return $out;
	}

	public static function is_attribute( string $slug ): bool {
		return '' !== $slug && array_key_exists( $slug, self::attributes() );
	}

	/* =====================================================================
	 * Filter sets
	 * =================================================================== */

	public static function sets(): array {
		if ( null === self::$sets ) {
			$raw        = get_option( self::OPT_SETS, array() );
			self::$sets = self::sanitize_sets( is_array( $raw ) ? $raw : array() );
		}
		return self::$sets;
	}

	public static function save_sets( array $sets ): void {
		self::$sets = self::sanitize_sets( $sets );
		update_option( self::OPT_SETS, self::$sets, true );
	}

	public static function set( string $id ): ?array {
		foreach ( self::sets() as $set ) {
			if ( $set['id'] === $id ) {
				return $set;
			}
		}
		return null;
	}

	public static function new_set_id(): string {
		$n = 1;
		foreach ( self::sets() as $set ) {
			if ( preg_match( '/^set(\d+)$/', $set['id'], $m ) ) {
				$n = max( $n, (int) $m[1] + 1 );
			}
		}
		return 'set' . $n;
	}

	public static function sanitize_sets( array $raw ): array {
		$out = array();
		$ids = array();
		foreach ( array_values( $raw ) as $set ) {
			if ( count( $out ) >= self::MAX_SETS ) {
				break;
			}
			if ( ! is_array( $set ) ) {
				continue;
			}
			$clean = self::sanitize_set( $set );
			if ( null === $clean || isset( $ids[ $clean['id'] ] ) ) {
				continue;
			}
			$ids[ $clean['id'] ] = true;
			$out[]               = $clean;
		}
		return $out;
	}

	public static function sanitize_set( array $set ): ?array {
		$id = isset( $set['id'] ) ? (string) $set['id'] : '';
		if ( ! preg_match( '/^set\d{1,4}$/', $id ) ) {
			return null;
		}
		$cats = array();
		foreach ( (array) ( $set['cats'] ?? array() ) as $c ) {
			$c = (int) $c;
			if ( $c > 0 ) {
				$cats[ $c ] = $c;
			}
		}
		$filters = array();
		$seen    = array();
		foreach ( (array) ( $set['filters'] ?? array() ) as $f ) {
			if ( count( $filters ) >= self::MAX_FILTERS ) {
				break;
			}
			$f = is_array( $f ) ? self::sanitize_filter( $f ) : null;
			if ( null === $f ) {
				continue;
			}
			$key = $f['type'] . ':' . $f['attr'];
			if ( isset( $seen[ $key ] ) ) {
				continue; // One filter per type / attribute in a set.
			}
			$seen[ $key ] = true;
			$filters[]    = $f;
		}
		return array(
			'id'      => $id,
			'name'    => isset( $set['name'] ) ? mb_substr( sanitize_text_field( (string) $set['name'] ), 0, 80 ) : '',
			'cats'    => array_values( $cats ),
			'filters' => $filters,
		);
	}

	public static function sanitize_filter( array $f ): ?array {
		$type = isset( $f['type'] ) ? (string) $f['type'] : '';
		if ( ! in_array( $type, array( 'cat', 'attr', 'price' ), true ) ) {
			return null;
		}
		$attr = '';
		if ( 'attr' === $type ) {
			$attr = isset( $f['attr'] ) ? sanitize_title( (string) $f['attr'] ) : '';
			if ( '' === $attr || strlen( $attr ) > 28 ) {
				return null;
			}
		}
		return array(
			'type'   => $type,
			'attr'   => $attr,
			'title'  => isset( $f['title'] ) ? mb_substr( sanitize_text_field( (string) $f['title'] ), 0, 80 ) : '',
			'open'   => ! empty( $f['open'] ),
			'counts' => ! isset( $f['counts'] ) || ! empty( $f['counts'] ),
		);
	}

	/**
	 * The set for the current page: the set linked to the nearest category
	 * (the category itself, then its parents), else the default set (no
	 * categories), else none.
	 */
	public static function set_for_category( int $term_id ): ?array {
		$sets = self::sets();
		if ( ! $sets ) {
			return null;
		}
		if ( $term_id > 0 ) {
			$chain = array_merge( array( $term_id ), array_map( 'intval', get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ) );
			foreach ( $chain as $cat ) {
				foreach ( $sets as $set ) {
					if ( in_array( $cat, $set['cats'], true ) ) {
						return $set;
					}
				}
			}
		}
		foreach ( $sets as $set ) {
			if ( ! $set['cats'] ) {
				return $set;
			}
		}
		return null;
	}

	/* =====================================================================
	 * Value groups (per attribute)
	 * =================================================================== */

	public static function groups_defaults(): array {
		return array(
			'groups'    => array(),
			'ignore'    => array(),
			'ungrouped' => 'hide', // hide | other | show.
			'unit'      => 'm',    // Unit of the group ranges: m (months) | y (years).
		);
	}

	public static function groups( string $attr ): array {
		if ( ! isset( self::$groups[ $attr ] ) ) {
			$raw = get_option( self::OPT_GROUPS . $attr, '' );
			if ( is_string( $raw ) && '' !== $raw ) {
				$raw = json_decode( $raw, true );
			}
			self::$groups[ $attr ] = self::sanitize_groups( is_array( $raw ) ? $raw : array() );
		}
		return self::$groups[ $attr ];
	}

	public static function save_groups( string $attr, array $g ): void {
		$clean                 = self::sanitize_groups( $g );
		self::$groups[ $attr ] = $clean;
		update_option( self::OPT_GROUPS . $attr, wp_json_encode( $clean ), false );
	}

	public static function delete_groups( string $attr ): void {
		unset( self::$groups[ $attr ] );
		delete_option( self::OPT_GROUPS . $attr );
	}

	/** True when the attribute has at least one group. */
	public static function has_groups( string $attr ): bool {
		$g = self::groups( $attr );
		return ! empty( $g['groups'] );
	}

	public static function sanitize_groups( array $raw ): array {
		$d   = self::groups_defaults();
		$out = array(
			'groups'    => array(),
			'ignore'    => self::int_list( $raw['ignore'] ?? array() ),
			'ungrouped' => isset( $raw['ungrouped'] ) && in_array( $raw['ungrouped'], array( 'hide', 'other', 'show' ), true ) ? $raw['ungrouped'] : $d['ungrouped'],
			'unit'      => isset( $raw['unit'] ) && in_array( $raw['unit'], array( 'm', 'y' ), true ) ? $raw['unit'] : $d['unit'],
		);
		$slugs = array( 'other' => true ); // Reserved for the automatic "Other" group.
		foreach ( (array) ( $raw['groups'] ?? array() ) as $g ) {
			if ( count( $out['groups'] ) >= self::MAX_GROUPS ) {
				break;
			}
			if ( ! is_array( $g ) ) {
				continue;
			}
			$label = isset( $g['label'] ) ? mb_substr( sanitize_text_field( (string) $g['label'] ), 0, 60 ) : '';
			if ( '' === $label ) {
				continue;
			}
			$slug = isset( $g['slug'] ) ? self::ascii_slug( (string) $g['slug'] ) : '';
			if ( '' === $slug ) {
				$slug = self::ascii_slug( $label );
			}
			if ( '' === $slug || ctype_digit( $slug ) ) {
				$slug = 'g' . ( count( $out['groups'] ) + 1 );
			}
			$base = $slug;
			$i    = 2;
			while ( isset( $slugs[ $slug ] ) ) {
				$slug = $base . '-' . $i++;
			}
			$slugs[ $slug ] = true;
			$out['groups'][] = array(
				'slug'  => $slug,
				'label' => $label,
				'terms' => self::int_list( $g['terms'] ?? array() ),
				'rmin'  => self::num_or_null( $g['rmin'] ?? null ),
				'rmax'  => self::num_or_null( $g['rmax'] ?? null ),
				'kw'    => isset( $g['kw'] ) ? mb_substr( sanitize_text_field( (string) $g['kw'] ), 0, 300 ) : '',
			);
		}
		return $out;
	}

	/** Lowercase ASCII slug [a-z0-9-], max 40 characters ('' when nothing is left). */
	public static function ascii_slug( string $s ): string {
		$s = strtolower( remove_accents( $s ) );
		$s = (string) preg_replace( '/[^a-z0-9]+/', '-', $s );
		return substr( trim( $s, '-' ), 0, 40 );
	}

	private static function int_list( $raw ): array {
		$out = array();
		foreach ( (array) $raw as $v ) {
			$v = (int) $v;
			if ( $v > 0 ) {
				$out[ $v ] = $v;
			}
			if ( count( $out ) >= self::MAX_TERMS ) {
				break;
			}
		}
		return array_values( $out );
	}

	private static function num_or_null( $v ): ?float {
		if ( null === $v || '' === $v || ! is_numeric( $v ) ) {
			return null;
		}
		return max( 0.0, min( 100000.0, (float) $v ) );
	}

	/** Attribute slugs that have a groups option (for backup / uninstall). */
	public static function grouped_attributes(): array {
		$out = array();
		foreach ( array_keys( self::attributes() ) as $attr ) {
			if ( false !== get_option( self::OPT_GROUPS . $attr, false ) ) {
				$out[] = $attr;
			}
		}
		return $out;
	}
}

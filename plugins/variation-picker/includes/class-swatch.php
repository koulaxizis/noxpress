<?php
/**
 * VPK_Swatch — what one swatch looks like: type of an attribute, colour /
 * image / short label of a value. Shared by the product page, the shop
 * cards, the cart dot, the admin previews and the public API.
 *
 * Data:
 *  - term meta vpk_color, vpk_color2 (#rrggbb), vpk_image (attachment id),
 *    vpk_label (short label, e.g. "XL")
 *  - product meta _vpk (array): { "attrs": { key: { "type": …, "values":
 *    { value: { color, color2, image, label } } } }, "guide": "" | "none" | id }
 *    key = sanitize_title( attribute name ), as in WooCommerce's
 *    attribute_… keys; values are the option values (term slugs for
 *    taxonomies, the text for custom attributes).
 *
 * Order of precedence: product override → attribute setting → general
 * setting ("auto"). A value's look: product override → term meta.
 */

defined( 'ABSPATH' ) || exit;

final class VPK_Swatch {

	const TERM_KEYS = array( 'vpk_color', 'vpk_color2', 'vpk_image', 'vpk_label' );
	const PMETA     = '_vpk';

	/** @var array Per-request cache of product configs. */
	private static $pconf = array();

	/**
	 * The look of a term: { color, color2, image, label } (validated).
	 *
	 * @param int $term_id Term id.
	 */
	public static function term( int $term_id ): array {
		return self::value_row(
			array(
				'color'  => get_term_meta( $term_id, 'vpk_color', true ),
				'color2' => get_term_meta( $term_id, 'vpk_color2', true ),
				'image'  => get_term_meta( $term_id, 'vpk_image', true ),
				'label'  => get_term_meta( $term_id, 'vpk_label', true ),
			)
		);
	}

	/**
	 * A validated value look.
	 *
	 * @param array $row Raw { color, color2, image, label }.
	 */
	public static function value_row( array $row ): array {
		$color  = VPK_Settings::hex( isset( $row['color'] ) ? $row['color'] : '' );
		$color2 = '' !== $color ? VPK_Settings::hex( isset( $row['color2'] ) ? $row['color2'] : '' ) : '';
		$image  = isset( $row['image'] ) && is_numeric( $row['image'] ) ? max( 0, (int) $row['image'] ) : 0;
		$label  = isset( $row['label'] ) && is_string( $row['label'] ) ? VPK_Settings::line( $row['label'], 20 ) : '';
		return array(
			'color'  => $color,
			'color2' => $color2,
			'image'  => $image,
			'label'  => $label,
		);
	}

	/** True when a value look has nothing set. */
	public static function is_empty( array $v ): bool {
		return '' === $v['color'] && 0 === $v['image'] && '' === $v['label'];
	}

	/**
	 * Saves the look of a term (empty fields delete the meta).
	 *
	 * @param int   $term_id Term id.
	 * @param array $raw     Raw { color, color2, image, label }.
	 */
	public static function save_term( int $term_id, array $raw ): void {
		$v   = self::value_row( $raw );
		$map = array(
			'vpk_color'  => $v['color'],
			'vpk_color2' => $v['color2'],
			'vpk_image'  => $v['image'] ? (string) $v['image'] : '',
			'vpk_label'  => $v['label'],
		);
		foreach ( $map as $key => $val ) {
			if ( '' === $val ) {
				delete_term_meta( $term_id, $key );
			} else {
				update_term_meta( $term_id, $key, $val );
			}
		}
	}

	/* =====================================================================
	 * Product overrides
	 * =================================================================== */

	/**
	 * The validated override config of a product.
	 *
	 * @param int $product_id Product id.
	 * @return array { attrs: key => { type, values }, guide }
	 */
	public static function product( int $product_id ): array {
		if ( isset( self::$pconf[ $product_id ] ) ) {
			return self::$pconf[ $product_id ];
		}
		$raw = get_post_meta( $product_id, self::PMETA, true );
		$out = self::sanitize_product( is_array( $raw ) ? $raw : array() );

		self::$pconf[ $product_id ] = $out;
		return $out;
	}

	/**
	 * Whitelist validation of a product override config.
	 *
	 * @param array $raw Raw config.
	 */
	public static function sanitize_product( array $raw ): array {
		$out = array(
			'attrs' => array(),
			'guide' => '',
		);
		if ( isset( $raw['guide'] ) && is_string( $raw['guide'] ) && ( 'none' === $raw['guide'] || VPK_Settings::valid_id( $raw['guide'] ) ) ) {
			$out['guide'] = $raw['guide'];
		}
		if ( isset( $raw['attrs'] ) && is_array( $raw['attrs'] ) ) {
			foreach ( $raw['attrs'] as $key => $a ) {
				if ( ! is_string( $key ) || '' === $key || strlen( $key ) > 200 || ! is_array( $a ) ) {
					continue;
				}
				$type   = isset( $a['type'] ) && is_string( $a['type'] ) && in_array( $a['type'], VPK_Settings::TYPES, true ) ? $a['type'] : '';
				$values = array();
				if ( isset( $a['values'] ) && is_array( $a['values'] ) ) {
					foreach ( $a['values'] as $val => $row ) {
						if ( ! is_array( $row ) || strlen( (string) $val ) > 200 ) {
							continue;
						}
						$v = self::value_row( $row );
						if ( ! self::is_empty( $v ) ) {
							$values[ (string) $val ] = $v;
						}
						if ( count( $values ) >= 100 ) {
							break;
						}
					}
				}
				if ( '' !== $type || $values ) {
					$out['attrs'][ $key ] = array(
						'type'   => $type,
						'values' => $values,
					);
				}
				if ( count( $out['attrs'] ) >= 20 ) {
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Puts the override config on a product object (saved with it; an
	 * empty config deletes the meta).
	 *
	 * @param WC_Product $product Product.
	 * @param array      $raw     Raw config.
	 */
	public static function set_product( WC_Product $product, array $raw ): void {
		$conf = self::sanitize_product( $raw );
		unset( self::$pconf[ (int) $product->get_id() ] );
		if ( empty( $conf['attrs'] ) && '' === $conf['guide'] ) {
			$product->delete_meta_data( self::PMETA );
			return;
		}
		$product->update_meta_data( self::PMETA, $conf );
	}

	/* =====================================================================
	 * Resolution
	 * =================================================================== */

	/**
	 * The swatch type of one attribute of one product.
	 *
	 * @param int    $product_id Product id.
	 * @param string $attribute  Attribute name (pa_… for taxonomies).
	 */
	public static function type( int $product_id, string $attribute ): string {
		$key  = sanitize_title( $attribute );
		$conf = self::product( $product_id );
		if ( isset( $conf['attrs'][ $key ] ) && '' !== $conf['attrs'][ $key ]['type'] ) {
			return $conf['attrs'][ $key ]['type'];
		}
		if ( taxonomy_exists( $attribute ) ) {
			$a = VPK_Settings::attr( $attribute );
			if ( '' !== $a['type'] ) {
				return $a['type'];
			}
		}
		return VPK_Settings::get()['auto'];
	}

	/**
	 * The look of one value of one attribute of one product.
	 *
	 * @param int          $product_id Product id.
	 * @param string       $attribute  Attribute name.
	 * @param string       $value      Option value.
	 * @param WP_Term|null $term       The term, for taxonomy attributes.
	 */
	public static function value( int $product_id, string $attribute, string $value, $term = null ): array {
		$conf = self::product( $product_id );
		$key  = sanitize_title( $attribute );
		if ( isset( $conf['attrs'][ $key ]['values'][ $value ] ) ) {
			return $conf['attrs'][ $key ]['values'][ $value ];
		}
		if ( $term instanceof WP_Term ) {
			return self::term( (int) $term->term_id );
		}
		return self::value_row( array() );
	}

	/** Shape and size of an attribute (attribute setting, else general). */
	public static function look( string $attribute ): array {
		$s = VPK_Settings::get();
		$a = taxonomy_exists( $attribute ) ? VPK_Settings::attr( $attribute ) : array(
			'shape' => '',
			'size'  => 0,
		);
		return array(
			'shape' => '' !== $a['shape'] ? $a['shape'] : $s['shape'],
			'size'  => $a['size'] ? $a['size'] : $s['size'],
		);
	}

	/**
	 * Public API body (vpk_get_term_swatch()).
	 *
	 * @param int|WP_Term $term Term or term id.
	 */
	public static function api_term( $term ): ?array {
		$term = $term instanceof WP_Term ? $term : get_term( (int) $term );
		if ( ! $term instanceof WP_Term || 0 !== strpos( $term->taxonomy, 'pa_' ) ) {
			return null;
		}
		$v = self::term( (int) $term->term_id );
		if ( self::is_empty( $v ) ) {
			return null;
		}
		$a   = VPK_Settings::attr( $term->taxonomy );
		$img = $v['image'] ? wp_get_attachment_image_url( $v['image'], 'thumbnail' ) : '';
		return array(
			'type'   => $a['type'],
			'color'  => $v['color'],
			'color2' => $v['color2'],
			'image'  => $img ? (string) $img : '',
			'label'  => '' !== $v['label'] ? $v['label'] : $term->name,
		);
	}
}

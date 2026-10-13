<?php
/**
 * VPK_Compat — other swatches plugins: detection (P8) and import (P7).
 *
 * Detection: while a known swatches plugin is active, "live" acts as "test"
 * (VPK_Settings::mode()), so visitors never see two sets of swatches; the
 * settings page says which plugin and why.
 *
 * Import (Tools, admin POST): reads the other plugins' colours, images and
 * labels from term meta and the attribute types from WooCommerce's
 * attribute table, and copies them only where Variation Picker has nothing
 * yet. Read-only towards the other plugins: nothing of theirs changes.
 *
 *  - CartFlows Variation Swatches: cfvsw_color (#hex), cfvsw_image (URL)
 *  - Variation Swatches by GetWooPlugins: product_attribute_color,
 *    secondary_color + is_dual_color = yes, product_attribute_image (id)
 *  - WCBoost Variation Swatches: swatches_color, swatches_image (id),
 *    swatches_label
 *  - attribute types: color → colour, image → image, label / button →
 *    button, radio → radio
 */

defined( 'ABSPATH' ) || exit;

final class VPK_Compat {

	/** Plugin folder => name. */
	const KNOWN = array(
		'variation-swatches-woo'                             => 'Variation Swatches for WooCommerce (CartFlows)',
		'woo-variation-swatches'                             => 'Variation Swatches for WooCommerce (GetWooPlugins)',
		'woo-variation-swatches-pro'                         => 'Variation Swatches for WooCommerce Pro (GetWooPlugins)',
		'wcboost-variation-swatches'                         => 'WCBoost – Variation Swatches',
		'yith-woocommerce-color-label-variations'            => 'YITH WooCommerce Color and Label Variations',
		'yith-woocommerce-color-and-label-variations-premium' => 'YITH WooCommerce Color and Label Variations Premium',
	);

	/** WooCommerce attribute_type => our type. */
	const TYPE_MAP = array(
		'color'  => 'color',
		'image'  => 'image',
		'label'  => 'button',
		'button' => 'button',
		'radio'  => 'radio',
	);

	/** @var string[]|null Per-request cache. */
	private static $active = null;

	/**
	 * Names of the known swatches plugins that are active.
	 *
	 * @return string[]
	 */
	public static function active_names(): array {
		if ( null !== self::$active ) {
			return self::$active;
		}
		$plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$out = array();
		foreach ( $plugins as $file ) {
			$dir = strtok( (string) $file, '/' );
			if ( isset( self::KNOWN[ $dir ] ) ) {
				$out[] = self::KNOWN[ $dir ];
			}
		}
		self::$active = array_values( array_unique( $out ) );
		return self::$active;
	}

	public static function other_active(): bool {
		return (bool) self::active_names();
	}

	/**
	 * Copies colours, images, labels and attribute types from other
	 * swatches plugins where ours are empty.
	 *
	 * @return array { terms, attrs } counts.
	 */
	public static function import(): array {
		$terms_done = 0;
		$attrs_done = 0;
		$s          = VPK_Settings::get();

		foreach ( wc_get_attribute_taxonomies() as $a ) {
			$tax = wc_attribute_taxonomy_name( $a->attribute_name );
			if ( ! taxonomy_exists( $tax ) || ! VPK_Settings::valid_taxonomy_name( $tax ) ) {
				continue;
			}
			$wc_type = (string) $a->attribute_type;
			if ( isset( self::TYPE_MAP[ $wc_type ] ) && ( ! isset( $s['attrs'][ $tax ] ) || '' === $s['attrs'][ $tax ]['type'] ) ) {
				$row                 = isset( $s['attrs'][ $tax ] ) ? $s['attrs'][ $tax ] : VPK_Settings::attr_row( array() );
				$row['type']         = self::TYPE_MAP[ $wc_type ];
				$s['attrs'][ $tax ] = $row;
				++$attrs_done;
			}

			$terms = get_terms(
				array(
					'taxonomy'               => $tax,
					'hide_empty'             => false,
					'update_term_meta_cache' => true,
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				if ( self::import_term( (int) $term->term_id ) ) {
					++$terms_done;
				}
			}
		}

		if ( $attrs_done ) {
			VPK_Settings::save( $s );
		}
		return array(
			'terms' => $terms_done,
			'attrs' => $attrs_done,
		);
	}

	/**
	 * One term: fill our empty fields from the first plugin that has them.
	 *
	 * @param int $term_id Term id.
	 * @return bool Something was copied.
	 */
	private static function import_term( int $term_id ): bool {
		$ours = VPK_Swatch::term( $term_id );
		$m    = static function ( string $k ) use ( $term_id ): string {
			$v = get_term_meta( $term_id, $k, true );
			return is_scalar( $v ) ? trim( (string) $v ) : '';
		};

		$color  = '';
		$color2 = '';
		foreach ( array( 'cfvsw_color', 'product_attribute_color', 'swatches_color' ) as $k ) {
			$c = VPK_Settings::hex( $m( $k ) );
			if ( '' !== $c ) {
				$color = $c;
				if ( 'product_attribute_color' === $k && 'yes' === $m( 'is_dual_color' ) ) {
					$color2 = VPK_Settings::hex( $m( 'secondary_color' ) );
				}
				break;
			}
		}

		$image = 0;
		foreach ( array( 'product_attribute_image', 'swatches_image' ) as $k ) {
			$v = $m( $k );
			if ( ctype_digit( $v ) && (int) $v > 0 && wp_attachment_is_image( (int) $v ) ) {
				$image = (int) $v;
				break;
			}
		}
		if ( ! $image ) {
			$url = $m( 'cfvsw_image' );
			if ( '' !== $url ) {
				$id    = (int) attachment_url_to_postid( $url );
				$image = ( $id && wp_attachment_is_image( $id ) ) ? $id : 0;
			}
		}

		$label = VPK_Settings::line( $m( 'swatches_label' ), 20 );

		$new     = $ours;
		$changed = false;
		if ( '' === $ours['color'] && '' !== $color ) {
			$new['color']  = $color;
			$new['color2'] = $color2;
			$changed       = true;
		}
		if ( 0 === $ours['image'] && $image ) {
			$new['image'] = $image;
			$changed      = true;
		}
		if ( '' === $ours['label'] && '' !== $label ) {
			$new['label'] = $label;
			$changed      = true;
		}
		if ( $changed ) {
			VPK_Swatch::save_term( $term_id, $new );
		}
		return $changed;
	}
}

<?php
/**
 * VPK_Loop — swatches on shop cards (P1).
 *
 * One attribute per card (the one chosen in the settings, else the first
 * attribute of the product with a colour / image / variation image type),
 * from the stored availability map only (Bible §14.10: a page view never
 * builds a map; a product without one simply shows no swatches until the
 * background build reaches it).
 *
 * Positions: after the title or the price (inside WooCommerce's card link,
 * so the swatches are <span>s and front.js turns a click into a link to the
 * product with the value chosen) or after the button (real links). Hover
 * or focus swaps the card image to the value's variation image. The cards
 * of Theme Patcher run the same hooks, so the swatches appear there too.
 *
 * Classic templates only: the Product Collection block of block themes does
 * not run these hooks.
 */

defined( 'ABSPATH' ) || exit;

final class VPK_Loop {

	const HOOKS = array(
		'after_title'  => array( 'woocommerce_shop_loop_item_title', 20 ),
		'after_price'  => array( 'woocommerce_after_shop_loop_item_title', 15 ),
		'after_button' => array( 'woocommerce_after_shop_loop_item', 20 ),
	);

	public static function init(): void {
		$s = VPK_Settings::get()['shop'];
		if ( ! $s['enabled'] ) {
			return;
		}
		$hook = self::HOOKS[ $s['position'] ];
		add_action( $hook[0], array( __CLASS__, 'render' ), $hook[1] );
	}

	public static function render(): void {
		if ( ! VPK_Front::active() ) {
			return;
		}
		try {
			global $product;
			if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
				return;
			}
			$html = self::html( $product );
			if ( '' !== $html ) {
				VPK_Front::assets();
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
			}
		} catch ( \Throwable $e ) {
			VPK_Front::fail();
		}
	}

	/**
	 * The attribute shown on a card, or ''.
	 *
	 * @param WC_Product $product Variable product.
	 */
	private static function attribute( WC_Product $product ): string {
		$pid    = (int) $product->get_id();
		$chosen = VPK_Settings::get()['shop']['attr'];
		$names  = array();
		foreach ( $product->get_attributes() as $a ) {
			if ( $a instanceof WC_Product_Attribute && $a->get_variation() ) {
				$names[] = $a->get_name();
			}
		}
		if ( '' !== $chosen ) {
			return in_array( $chosen, $names, true ) && 'select' !== VPK_Swatch::type( $pid, $chosen ) ? $chosen : '';
		}
		foreach ( $names as $name ) {
			if ( in_array( VPK_Swatch::type( $pid, $name ), array( 'color', 'image', 'variation_image' ), true ) ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * The swatches of one card, or ''.
	 *
	 * @param WC_Product $product Variable product.
	 */
	public static function html( WC_Product $product ): string {
		$pid = (int) $product->get_id();
		$map = VPK_Map::stored( $pid );
		if ( null === $map || ! $map['v'] ) {
			return '';
		}
		$attribute = self::attribute( $product );
		if ( '' === $attribute ) {
			return '';
		}
		$key    = 'attribute_' . sanitize_title( $attribute );
		$values = VPK_Map::values( $map, $key );
		if ( ! $values ) {
			return '';
		}
		$any = isset( $values[''] );
		unset( $values[''] );

		// Option values in WooCommerce's order.
		$options = array();
		foreach ( $product->get_attributes() as $a ) {
			if ( $a instanceof WC_Product_Attribute && $a->get_name() === $attribute ) {
				$options = $a->is_taxonomy() ? wp_list_pluck( $a->get_terms() ? $a->get_terms() : array(), 'slug' ) : $a->get_options();
				break;
			}
		}
		$options = array_values(
			array_filter(
				array_map( 'strval', $options ),
				static function ( $o ) use ( $values, $any ) {
					return $any || isset( $values[ $o ] );
				}
			)
		);
		$items = VPK_Front::items( $product, $attribute, $options );
		if ( ! $items ) {
			return '';
		}

		$s      = VPK_Settings::get();
		$type   = VPK_Swatch::type( $pid, $attribute );
		$images = VPK_Map::images( $map, $key );
		$inside = 'after_button' !== $s['shop']['position'];
		$limit  = (int) $s['shop']['limit'];
		$link   = (string) $product->get_permalink();
		$shape  = VPK_Swatch::look( $attribute )['shape'];
		$label  = wp_strip_all_tags( wc_attribute_label( $attribute, $product ) );

		$out   = '';
		$shown = 0;
		$more  = 0;
		foreach ( $items as $it ) {
			$in = $any || ! empty( $values[ $it['value'] ] );
			if ( ! $in && 'hide' === $s['oos'] ) {
				continue;
			}
			if ( $limit && $shown >= $limit ) {
				++$more;
				continue;
			}
			++$shown;
			// WooCommerce preselects from the request value: a term slug is
			// stored percent-encoded (non-Latin), so it is decoded first.
			$q    = 0 === strpos( $key, 'attribute_pa_' ) ? rawurldecode( $it['value'] ) : $it['value'];
			$out .= self::swatch( $it, $type, $images, $in, $inside, $s['shop']['preselect'] ? add_query_arg( $key, rawurlencode( $q ), $link ) : $link );
		}
		if ( '' === $out ) {
			return '';
		}
		if ( $more ) {
			$plus = '+' . (int) $more;
			$out .= $inside
				? '<span class="vpk-more" data-vpk-href="' . esc_url( $link ) . '">' . esc_html( $plus ) . '</span>'
				: '<a class="vpk-more" href="' . esc_url( $link ) . '">' . esc_html( $plus ) . '</a>';
		}
		return '<div class="vpk-root vpk-loop vpk-shape--' . esc_attr( $shape ) . '"' . ( $inside ? '' : ' role="group" aria-label="' . esc_attr( $label ) . '"' ) . '>' . $out . '</div>';
	}

	/**
	 * One card swatch.
	 *
	 * @param array  $it     Item { value, name, look }.
	 * @param string $type   Swatch type.
	 * @param array  $images value => variation image id.
	 * @param bool   $in     In stock.
	 * @param bool   $inside Inside the card link (spans, not links).
	 * @param string $href   Link.
	 */
	private static function swatch( array $it, string $type, array $images, bool $in, bool $inside, string $href ): string {
		$look  = $it['look'];
		$name  = wp_strip_all_tags( $it['name'] );
		$short = '' !== $look['label'] ? $look['label'] : $name;
		$inner = '';
		$kind  = 'label';

		$img = 0;
		if ( 'variation_image' === $type && isset( $images[ $it['value'] ] ) ) {
			$img = (int) $images[ $it['value'] ];
		} elseif ( 'color' !== $type && $look['image'] ) {
			$img = $look['image'];
		}
		if ( 'color' === $type && '' !== $look['color'] ) {
			$kind = 'color';
		} elseif ( $img ) {
			$kind = 'image';
		} elseif ( '' !== $look['color'] && 'button' !== $type && 'radio' !== $type ) {
			$kind = 'color';
		} elseif ( $look['image'] && 'button' !== $type && 'radio' !== $type ) {
			$kind = 'image';
			$img  = $look['image'];
		}

		if ( 'color' === $kind ) {
			$bg    = '' !== $look['color2'] ? 'linear-gradient(135deg,' . $look['color'] . ' 50%,' . $look['color2'] . ' 50%)' : $look['color'];
			$inner = '<span class="vpk-sw__color" style="background:' . esc_attr( $bg ) . '"></span>';
		} elseif ( 'image' === $kind ) {
			$src = (string) wp_get_attachment_image_url( $img, 'thumbnail' );
			if ( '' === $src ) {
				$kind  = 'label';
				$inner = '<span class="vpk-sw__label">' . esc_html( $short ) . '</span>';
			} else {
				$inner = '<img class="vpk-sw__img" src="' . esc_url( $src ) . '" alt="" loading="lazy" decoding="async" />';
			}
		} else {
			$inner = '<span class="vpk-sw__label">' . esc_html( $short ) . '</span>';
		}

		// Card image swap: the value's variation image (whatever the swatch type).
		$swap = '';
		$vimg = isset( $images[ $it['value'] ] ) ? (int) $images[ $it['value'] ] : 0;
		if ( $vimg ) {
			$src = wp_get_attachment_image_src( $vimg, 'woocommerce_thumbnail' );
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				$swap = ' data-vpk-src="' . esc_url( (string) $src[0] ) . '"';
				$set  = wp_get_attachment_image_srcset( $vimg, 'woocommerce_thumbnail' );
				if ( is_string( $set ) && '' !== $set ) {
					$swap .= ' data-vpk-srcset="' . esc_attr( $set ) . '"';
				}
			}
		}

		$cls = 'vpk-sw vpk-sw--' . $kind . ( $in ? '' : ' vpk-is-oos' );
		if ( $inside ) {
			return '<span class="' . esc_attr( $cls ) . '" data-vpk-href="' . esc_url( $href ) . '" data-vpk-tip="' . esc_attr( $name ) . '"' . $swap . '>' . $inner . '</span>';
		}
		return '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $href ) . '" aria-label="' . esc_attr( $name ) . '" data-vpk-tip="' . esc_attr( $name ) . '"' . $swap . '>' . $inner . '</a>';
	}
}

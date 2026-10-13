<?php
/**
 * VPK_Guide — size guide button and dialog (P3).
 *
 * Which guide a product gets: its own choice (product tab: a guide or
 * "none"), else the first active guide whose categories include one of the
 * product's categories (no categories = every product). A guide is shown
 * next to its attribute when the product has that attribute; a guide
 * without an attribute goes under the last attribute.
 *
 * Markup: a button and a native <dialog> (with a fallback for browsers
 * without showModal). The table is plain text cells, escaped.
 */

defined( 'ABSPATH' ) || exit;

final class VPK_Guide {

	/** @var array Guides already printed (id => true): one dialog per page and guide. */
	private static $printed = array();

	/**
	 * The guide of a product, or null.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function for_product( WC_Product $product ): ?array {
		$guides = VPK_Settings::guides();
		if ( ! $guides ) {
			return null;
		}
		$pid  = (int) $product->get_id();
		$conf = VPK_Swatch::product( $pid );
		if ( 'none' === $conf['guide'] ) {
			return null;
		}
		if ( '' !== $conf['guide'] ) {
			foreach ( $guides as $g ) {
				if ( $g['id'] === $conf['guide'] ) {
					return $g;
				}
			}
			return null;
		}
		$cats = array_map( 'intval', $product->get_category_ids() );
		foreach ( $guides as $g ) {
			if ( ! $g['active'] ) {
				continue;
			}
			if ( ! $g['cats'] || array_intersect( $g['cats'], self::with_ancestors( $cats ) ) ) {
				return $g;
			}
		}
		return null;
	}

	/**
	 * Category ids plus their ancestors (a guide for "Clothes" covers "T-shirts").
	 *
	 * @param int[] $cats Category ids.
	 */
	private static function with_ancestors( array $cats ): array {
		$out = $cats;
		foreach ( $cats as $c ) {
			foreach ( get_ancestors( $c, 'product_cat', 'taxonomy' ) as $a ) {
				$out[] = (int) $a;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The guide button (and its dialog) for one attribute, or ''.
	 *
	 * @param WC_Product $product   Product.
	 * @param string     $attribute Attribute name.
	 * @param bool       $is_last   This is the product's last attribute.
	 */
	public static function button( WC_Product $product, string $attribute, bool $is_last ): string {
		$g = self::for_product( $product );
		if ( null === $g ) {
			return '';
		}
		$attrs = $product->get_variation_attributes();
		$here  = '' !== $g['attr'] && isset( $attrs[ $g['attr'] ] )
			? $g['attr'] === $attribute
			: $is_last;
		if ( ! $here ) {
			return '';
		}
		$did = 'vpk-guide-' . $g['id'] . '-' . (int) $product->get_id();
		$out = '<button type="button" class="vpk-guide-btn" aria-haspopup="dialog" data-vpk-guide="' . esc_attr( $did ) . '">'
			. '<svg class="vpk-guide-btn__icon" viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path d="M2 6.5h16v7H2z" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M5 6.5v3M8 6.5v2M11 6.5v3M14 6.5v2" stroke="currentColor" stroke-width="1.5"/></svg>'
			. '<span>' . esc_html( VPK_Settings::text( 't_guide' ) ) . '</span></button>';
		if ( ! isset( self::$printed[ $did ] ) ) {
			self::$printed[ $did ] = true;
			$out                  .= self::dialog( $g, $did );
		}
		return $out;
	}

	/**
	 * The dialog of a guide.
	 *
	 * @param array  $g   Guide.
	 * @param string $did Dialog id.
	 */
	private static function dialog( array $g, string $did ): string {
		$title = VPK_Settings::guide_text( $g, 'title' );
		$rows  = VPK_Settings::table_rows( VPK_Settings::guide_text( $g, 'table' ) );
		$note  = VPK_Settings::guide_text( $g, 'note' );

		$html  = '<dialog class="vpk-root vpk-dialog" id="' . esc_attr( $did ) . '" aria-labelledby="' . esc_attr( $did ) . '-t">';
		$html .= '<div class="vpk-dialog__head"><h2 class="vpk-dialog__title" id="' . esc_attr( $did ) . '-t">' . esc_html( $title ) . '</h2>';
		$html .= '<button type="button" class="vpk-dialog__close" data-vpk-close aria-label="' . esc_attr( VPK_Settings::text( 't_close' ) ) . '">'
			. '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button></div>';
		$html .= '<div class="vpk-dialog__body">';
		if ( $rows ) {
			$html .= '<div class="vpk-table-wrap"><table class="vpk-table"><thead><tr>';
			foreach ( $rows[0] as $cell ) {
				$html .= '<th scope="col">' . esc_html( $cell ) . '</th>';
			}
			$html .= '</tr></thead><tbody>';
			foreach ( array_slice( $rows, 1 ) as $row ) {
				$html .= '<tr>';
				foreach ( $row as $j => $cell ) {
					$html .= 0 === $j ? '<th scope="row">' . esc_html( $cell ) . '</th>' : '<td>' . esc_html( $cell ) . '</td>';
				}
				$html .= '</tr>';
			}
			$html .= '</tbody></table></div>';
		}
		if ( $g['image'] ) {
			$img = wp_get_attachment_image( (int) $g['image'], 'large', false, array( 'class' => 'vpk-dialog__img', 'loading' => 'lazy' ) );
			if ( $img ) {
				$html .= $img;
			}
		}
		if ( '' !== $note ) {
			$html .= '<p class="vpk-dialog__note">' . esc_html( $note ) . '</p>';
		}
		$html .= '</div></dialog>';
		return $html;
	}
}

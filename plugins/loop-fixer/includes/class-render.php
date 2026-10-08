<?php
/**
 * LF_Render — builds the slot (rating / price / add-to-cart button) for
 * one product, and the scoped CSS of an area.
 *
 * Rendering uses WooCommerce's own loop functions, so taxes, sale prices,
 * price filters, product types (simple / variable / external / out of
 * stock) and button texts behave exactly as in a standard product card:
 *  - woocommerce_template_loop_rating()       → loop/rating.php
 *  - woocommerce_template_loop_price()        → loop/price.php
 *  - woocommerce_template_loop_add_to_cart()  → loop/add-to-cart.php
 *
 * Called from inside the theme's loop, while the card is being drawn, so
 * the global $product / $post are the card's own.
 */

defined( 'ABSPATH' ) || exit;

final class LF_Render {

	/** True once a slot with an add-to-cart button was rendered. */
	private static $has_button = false;

	/**
	 * Slot HTML for a product, '' when nothing to show.
	 *
	 * @param int    $product_id Card product.
	 * @param array  $cfg        Area config (LF_Settings::sanitize_area()).
	 * @param string $area_id    Area id (for the CSS class).
	 */
	public static function slot( int $product_id, array $cfg, string $area_id ): string {

		if ( empty( $cfg['parts'] ) ) {
			return '';
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_visible() ) {
			return '';
		}

		$prev_product = isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null;
		$level        = ob_get_level();
		$html         = '';

		try {
			$GLOBALS['product'] = $product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restored below.

			ob_start();
			foreach ( $cfg['parts'] as $part ) {
				switch ( $part ) {
					case 'rating':
						woocommerce_template_loop_rating();
						break;
					case 'price':
						woocommerce_template_loop_price();
						break;
					case 'button':
						woocommerce_template_loop_add_to_cart();
						self::$has_button = true;
						break;
				}
			}
			$html = (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			// Close whatever was opened here and give up on this card.
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			$GLOBALS['product'] = $prev_product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			throw $e;
		}

		$GLOBALS['product'] = $prev_product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

		if ( '' === trim( $html ) ) {
			return '';
		}

		return '<div class="lf-slot ' . esc_attr( self::area_class( $area_id ) ) . '">' . $html . '</div>';
	}

	/** CSS class of an area: lf-slot--shop, lf-slot--file-1a2b3c4d. */
	public static function area_class( string $area_id ): string {
		if ( LF_Areas::is_file_area( $area_id ) ) {
			return 'lf-slot--file-' . substr( md5( $area_id ), 0, 8 );
		}
		return 'lf-slot--' . sanitize_html_class( $area_id );
	}

	/**
	 * Scoped CSS of an area, '' when the area defines none (the slot then
	 * inherits the theme's styling).
	 */
	public static function css( string $area_id, array $cfg ): string {

		$cls   = '.' . self::area_class( $area_id );
		$s     = $cfg['style'];
		$rules = array();

		$slot = array();
		if ( '' !== $s['align'] ) {
			$slot[] = 'text-align:' . $s['align'];
		}
		if ( '' !== $s['gap'] ) {
			$slot[] = 'margin-top:' . (int) $s['gap'] . 'px';
		}
		if ( $slot ) {
			$rules[] = $cls . '{' . implode( ';', $slot ) . '}';
		}
		if ( '' !== $s['price'] ) {
			$rules[] = $cls . ' .price,' . $cls . ' .price *{color:' . $s['price'] . '}';
		}
		if ( '' !== $s['btn_bg'] || '' !== $s['btn_text'] ) {
			$btn = array();
			if ( '' !== $s['btn_bg'] ) {
				$btn[] = 'background:' . $s['btn_bg'];
				$btn[] = 'border-color:' . $s['btn_bg'];
			}
			if ( '' !== $s['btn_text'] ) {
				$btn[] = 'color:' . $s['btn_text'];
			}
			$rules[] = $cls . ' .button,' . $cls . ' .added_to_cart{' . implode( ';', $btn ) . '}';
		}
		if ( '' !== $s['price_hover'] && '' !== $cfg['card'] ) {
			$sels = array();
			foreach ( array_map( 'trim', explode( ',', $cfg['card'] ) ) as $card ) {
				if ( '' !== $card ) {
					$sels[] = $card . ':hover ' . $cls . ' .price';
					$sels[] = $card . ':hover ' . $cls . ' .price *';
				}
			}
			if ( $sels ) {
				$rules[] = implode( ',', $sels ) . '{color:' . $s['price_hover'] . '}';
			}
		}
		if ( '' !== $cfg['css'] ) {
			$rules[] = $cfg['css'];
		}

		if ( ! $rules ) {
			return '';
		}

		$id = 'lf-css-' . substr( md5( $area_id ), 0, 8 );
		return '<style id="' . esc_attr( $id ) . '">' . implode( "\n", $rules ) . '</style>';
	}

	/**
	 * AJAX add to cart needs wc-add-to-cart. WooCommerce registers it on
	 * every front-end page but may enqueue it only where its own loops run,
	 * so enqueue it (footer) when a slot printed a button.
	 */
	public static function maybe_enqueue_cart_script(): void {
		if ( ! self::$has_button ) {
			return;
		}
		if ( wp_script_is( 'wc-add-to-cart', 'registered' ) && ! wp_script_is( 'wc-add-to-cart', 'enqueued' ) ) {
			wp_enqueue_script( 'wc-add-to-cart' );
		}
	}
}

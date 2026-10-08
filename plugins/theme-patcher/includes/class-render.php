<?php
/**
 * TP_Render — builds the slot (sale badge / rating / price / add-to-cart
 * button / card hooks) for one product, and the scoped CSS of an area.
 *
 * Rendering uses WooCommerce's own loop functions, so taxes, sale prices,
 * price filters, product types (simple / variable / external / out of
 * stock) and button texts behave exactly as in a standard product card:
 *  - woocommerce_template_loop_rating()       → loop/rating.php
 *  - woocommerce_template_loop_price()        → loop/price.php
 *  - woocommerce_template_loop_add_to_cart()  → loop/add-to-cart.php
 * The sale badge shows the discount percentage for every product type
 * (variable products: the largest discount among their variations).
 * Card hooks: woocommerce_after_shop_loop_item_title and
 * woocommerce_after_shop_loop_item run with WooCommerce's own callbacks
 * (rating, price, link close, button) taken off for the duration, so
 * only what other plugins add there is printed.
 *
 * Called from inside the theme's loop, while the card is being drawn, so
 * the global $product / $post are the card's own.
 */

defined( 'ABSPATH' ) || exit;

final class TP_Render {

	/** True once a slot with an add-to-cart button was rendered. */
	private static $has_button = false;

	/** True while a slot is being rendered (TP_Runtime ignores its own output). */
	private static $rendering = false;

	/** WooCommerce's own callbacks on the card hooks run by the "hooks" option. */
	const WC_CARD_CALLBACKS = array(
		'woocommerce_after_shop_loop_item_title' => array(
			'woocommerce_template_loop_rating' => 5,
			'woocommerce_template_loop_price'  => 10,
		),
		'woocommerce_after_shop_loop_item'       => array(
			'woocommerce_template_loop_product_link_close' => 5,
			'woocommerce_template_loop_add_to_cart'        => 10,
		),
	);

	public static function rendering(): bool {
		return self::$rendering;
	}

	/**
	 * Slot HTML for a product, '' when nothing to show.
	 *
	 * @param int    $product_id Card product.
	 * @param array  $cfg        Area config (TP_Settings::sanitize_area()).
	 * @param string $area_id    Area id (for the CSS class).
	 */
	public static function slot( int $product_id, array $cfg, string $area_id ): string {

		if ( empty( $cfg['parts'] ) && empty( $cfg['hooks'] ) ) {
			return '';
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_visible() ) {
			return '';
		}

		$prev_product = isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null;
		$level        = ob_get_level();
		$html         = '';

		$removed      = array();
		self::$rendering = true;

		try {
			$GLOBALS['product'] = $product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restored below.

			ob_start();
			foreach ( $cfg['parts'] as $part ) {
				switch ( $part ) {
					case 'sale_badge':
						echo self::sale_badge( $product ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_html.
						break;
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
			if ( ! empty( $cfg['hooks'] ) ) {
				$removed = self::detach_wc_callbacks();
				$before  = ob_get_length();
				do_action( 'woocommerce_after_shop_loop_item_title' );
				do_action( 'woocommerce_after_shop_loop_item' );
				self::attach( $removed );
				$removed = array();
				if ( ob_get_length() > $before && ! in_array( 'button', $cfg['parts'], true ) ) {
					self::$has_button = true; // Third-party buttons may use wc-add-to-cart too.
				}
			}
			$html = (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			// Close whatever was opened here and give up on this card.
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			self::attach( $removed );
			$GLOBALS['product'] = $prev_product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			self::$rendering    = false;
			throw $e;
		}

		$GLOBALS['product'] = $prev_product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		self::$rendering    = false;

		if ( '' === trim( $html ) ) {
			return '';
		}

		return '<div class="tp-slot ' . esc_attr( self::area_class( $area_id ) ) . '">' . $html . '</div>';
	}

	/** Take WooCommerce's own card callbacks off (only those actually attached). */
	private static function detach_wc_callbacks(): array {
		$removed = array();
		foreach ( self::WC_CARD_CALLBACKS as $hook => $callbacks ) {
			foreach ( $callbacks as $cb => $prio ) {
				if ( has_action( $hook, $cb ) === $prio ) {
					remove_action( $hook, $cb, $prio );
					$removed[] = array( $hook, $cb, $prio );
				}
			}
		}
		return $removed;
	}

	private static function attach( array $removed ): void {
		foreach ( $removed as $r ) {
			add_action( $r[0], $r[1], $r[2] );
		}
	}

	/** Discount percentage, 0 when not on sale (variable: largest variation discount). */
	public static function sale_percent( WC_Product $product ): int {
		if ( ! $product->is_on_sale() ) {
			return 0;
		}
		$best = 0;
		if ( $product->is_type( 'variable' ) && is_callable( array( $product, 'get_variation_prices' ) ) ) {
			$prices = $product->get_variation_prices();
			foreach ( $prices['regular_price'] as $vid => $regular ) {
				$sale = isset( $prices['sale_price'][ $vid ] ) ? (float) $prices['sale_price'][ $vid ] : (float) $regular;
				$best = max( $best, self::percent( (float) $regular, $sale ) );
			}
			return $best;
		}
		if ( $product->is_type( 'grouped' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child && $child->is_visible() ) {
					$best = max( $best, self::sale_percent( $child ) );
				}
			}
			return $best;
		}
		return self::percent( (float) $product->get_regular_price(), (float) $product->get_sale_price() );
	}

	private static function percent( float $regular, float $sale ): int {
		if ( $regular <= 0 || $sale < 0 || $sale >= $regular ) {
			return 0;
		}
		return (int) round( ( $regular - $sale ) / $regular * 100 );
	}

	private static function sale_badge( WC_Product $product ): string {
		$pct = self::sale_percent( $product );
		if ( $pct <= 0 ) {
			return '';
		}
		return '<span class="tp-badge onsale">' . esc_html( '-' . $pct . '%' ) . '</span>';
	}

	/** CSS class of an area: tp-slot--shop, tp-slot--file-1a2b3c4d. */
	public static function area_class( string $area_id ): string {
		if ( TP_Areas::is_file_area( $area_id ) ) {
			return 'tp-slot--file-' . substr( md5( $area_id ), 0, 8 );
		}
		return 'tp-slot--' . sanitize_html_class( $area_id );
	}

	/**
	 * Scoped CSS rules of an area, '' when the area defines none (the slot
	 * then inherits the theme's styling). TP_Page prints them in <head>.
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
		$cards = '' !== $cfg['card'] ? array_filter( array_map( 'trim', explode( ',', $cfg['card'] ) ) ) : array();

		if ( in_array( 'sale_badge', $cfg['parts'], true ) ) {
			$badge = array( 'display:inline-block', 'padding:2px 8px', 'border-radius:4px', 'font-size:13px', 'font-weight:700', 'line-height:1.6' );
			$badge[] = 'background:' . ( '' !== $s['badge_bg'] ? $s['badge_bg'] : '#e2401c' );
			$badge[] = 'color:' . ( '' !== $s['badge_text'] ? $s['badge_text'] : '#fff' );
			$rules[] = $cls . ' .tp-badge{' . implode( ';', $badge ) . '}';
			if ( $cards ) {
				// Top left corner of the card.
				$rules[] = implode( ',', $cards ) . '{position:relative}';
				$sels    = array();
				foreach ( $cards as $card ) {
					$sels[] = $card . ' ' . $cls . ' .tp-badge';
				}
				$rules[] = implode( ',', $sels ) . '{position:absolute;top:8px;left:8px;z-index:3;margin:0}';
			}
			if ( '' !== $cfg['badge_hide'] ) {
				$rules[] = self::scoped( $cards, $cfg['badge_hide'] ) . '{display:none!important}';
			}
		}

		$t = $cfg['title'];
		if ( '' !== $t['sel'] && ( '' !== $t['lines'] || '' !== $t['size'] ) ) {
			$title = array();
			if ( '' !== $t['lines'] ) {
				$title[] = 'display:-webkit-box';
				$title[] = '-webkit-box-orient:vertical';
				$title[] = '-webkit-line-clamp:' . (int) $t['lines'];
				$title[] = 'line-clamp:' . (int) $t['lines'];
				$title[] = 'overflow:hidden';
			}
			if ( '' !== $t['size'] ) {
				$title[] = 'font-size:' . (int) $t['size'] . 'px';
			}
			$rules[] = self::scoped( $cards, $t['sel'] ) . '{' . implode( ';', $title ) . '}';
		}

		if ( '' !== $cfg['css'] ) {
			$rules[] = $cfg['css'];
		}

		return $rules ? implode( "\n", $rules ) . "\n" : '';
	}

	/** "card sel" for every card × selector pair (the selector alone when no card selector). */
	private static function scoped( array $cards, string $sel ): string {
		$parts = array_filter( array_map( 'trim', explode( ',', $sel ) ) );
		if ( ! $cards ) {
			return implode( ',', $parts );
		}
		$out = array();
		foreach ( $cards as $card ) {
			foreach ( $parts as $p ) {
				$out[] = $card . ' ' . $p;
			}
		}
		return implode( ',', $out );
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

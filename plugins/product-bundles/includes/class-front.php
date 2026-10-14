<?php
/**
 * PBN_Front — the bundle's product page: contents, choices, savings, form.
 *
 * Classic themes print it through woocommerce_nox_bundle_add_to_cart (the
 * action WooCommerce fires for the product type); block themes through
 * the same action, which the "Add to Cart Form" and "Add to Cart with
 * Options" blocks fire for product types they do not know.
 *
 *  - The products and quantities come only from the bundle (_pbn_items);
 *    the form only sends the choices of the choice rows (pbn_choice[row]),
 *    checked against a whitelist in PBN_Cart.
 *  - The "Separately … · You save …" line follows the choices in the
 *    browser (front.js); the cart computes the real prices on the server.
 *  - CSS and JS load only on bundle pages (Bible §14.9).
 */

defined( 'ABSPATH' ) || exit;

final class PBN_Front {

	/** @var bool Set after an error: the plugin stays out of the page (Bible §14.6). */
	private static $broken = false;

	public static function init(): void {
		add_action( 'woocommerce_' . PBN_Product::TYPE . '_add_to_cart', array( __CLASS__, 'render' ), 30 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
	}

	/** True on the page of a bundle. */
	private static function on_bundle_page(): bool {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return false;
		}
		$p = wc_get_product( get_queried_object_id() );
		return $p instanceof PBN_Product;
	}

	public static function assets(): void {
		if ( self::$broken ) {
			return;
		}
		try {
			if ( ! self::on_bundle_page() ) {
				return;
			}
			wp_enqueue_style( 'pbn-front', PBN_URL . 'assets/front.css', array(), PBN_VERSION );
			wp_enqueue_script( 'pbn-front', PBN_URL . 'assets/front.js', array(), PBN_VERSION, true );
		} catch ( \Throwable $e ) {
			self::$broken = true;
		}
	}

	/** woocommerce_nox_bundle_add_to_cart */
	public static function render(): void {
		global $product;
		if ( self::$broken || ! $product instanceof PBN_Product ) {
			return;
		}
		$level = ob_get_level();
		try {
			$html = self::html( $product );
		} catch ( \Throwable $e ) {
			// Close only the buffers html() opened.
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			self::$broken = true;
			return;
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
	}

	/**
	 * Display price of a product for a quantity (tax as the shop shows it).
	 *
	 * @param WC_Product $p     Product.
	 * @param float      $price Price as stored.
	 * @param int        $qty   Quantity.
	 */
	private static function shown( WC_Product $p, float $price, int $qty ): float {
		return (float) wc_get_price_to_display(
			$p,
			array(
				'price' => $price,
				'qty'   => $qty,
			)
		);
	}

	/** Plain text price ("12,50 €"), for texts and data attributes. */
	private static function plain_price( float $amount ): string {
		return trim( html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * The whole block.
	 *
	 * @param PBN_Product $b Bundle.
	 */
	public static function html( PBN_Product $b ): string {
		$s    = PBN_Settings::get();
		$rows = PBN_Bundle::rows( $b );
		if ( empty( $rows ) ) {
			return '';
		}
		$buy = $b->is_purchasable() && $b->is_in_stock();
		$q   = PBN_Bundle::quote( $b, array(), 'min' );

		// Data for front.js: display values of the fixed rows and of every choice.
		$fixed = 0.0;
		$items = '';
		foreach ( $rows as $i => $row ) {
			$items .= self::row_html( $b, $i, $row, $buy, $fixed );
		}

		$type = 'price' === (string) $b->get_meta( PBN_Bundle::META_DTYPE, true, 'edit' ) ? 'price' : 'percent';
		$disc = $b->get_meta( PBN_Bundle::META_DISC, true, 'edit' );
		$data = array(
			't'     => $type,
			'd'     => is_numeric( $disc ) ? (float) $disc : 0.0,
			'fixed' => $fixed,
			'min_v' => $q['ok'] ? $q['display_value'] : 0.0,
			'min_p' => $q['ok'] ? $q['display_price'] : 0.0,
			'dec'   => wc_get_price_decimals(),
			'ds'    => wc_get_price_decimal_separator(),
			'ts'    => wc_get_price_thousand_separator(),
			'fmt'   => get_woocommerce_price_format(),
			'sym'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'save'  => PBN_Settings::text( 't_savings' ),
		);

		$out  = '<div class="pbn-root" data-pbn="' . esc_attr( (string) wp_json_encode( $data ) ) . '">';
		$list = '';
		if ( $s['show_contents'] || PBN_Bundle::has_choices( $b ) ) {
			$list .= '<div class="pbn-box">';
			if ( $s['show_contents'] ) {
				$list .= '<p class="pbn-box__title">' . esc_html( PBN_Settings::text( 't_contains' ) ) . '</p>';
			}
			$list .= '<div class="pbn-items">' . $items . '</div></div>';
		}
		if ( $s['show_savings'] && $q['ok'] && $q['display_price'] < $q['display_value'] - 0.004 ) {
			$list .= '<p class="pbn-savings" aria-live="polite">' . esc_html( self::savings_text( $q['display_value'], $q['display_price'] ) ) . '</p>';
		}

		if ( ! $buy ) {
			$out .= $list . wc_get_stock_html( $b ) . '</div>';
			return $out;
		}

		ob_start();
		do_action( 'woocommerce_before_add_to_cart_form' );
		$before = (string) ob_get_clean();

		$out .= $before;
		$out .= '<form class="cart pbn-form" action="' . esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', $b->get_permalink() ) ) . '" method="post" enctype="multipart/form-data">';
		$out .= $list;

		ob_start();
		echo wc_get_stock_html( $b ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's filterable HTML.
		do_action( 'woocommerce_before_add_to_cart_button' );
		do_action( 'woocommerce_before_add_to_cart_quantity' );
		$avail = PBN_Bundle::availability( $b );
		woocommerce_quantity_input(
			array(
				'min_value'   => 1,
				'max_value'   => $b->is_sold_individually() ? 1 : ( -1 === $avail['count'] ? -1 : max( 1, $avail['count'] ) ),
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- repopulates the field only.
				'input_value' => isset( $_POST['quantity'] ) ? wc_stock_amount( wp_unslash( $_POST['quantity'] ) ) : 1,
			),
			$b
		);
		do_action( 'woocommerce_after_add_to_cart_quantity' );
		$theme_class = function_exists( 'wc_wp_theme_get_element_class_name' ) ? (string) wc_wp_theme_get_element_class_name( 'button' ) : '';
		echo '<button type="submit" name="add-to-cart" value="' . esc_attr( (string) $b->get_id() ) . '" class="single_add_to_cart_button button alt' . ( '' !== $theme_class ? ' ' . esc_attr( $theme_class ) : '' ) . '">' . esc_html( $b->single_add_to_cart_text() ) . '</button>';
		do_action( 'woocommerce_after_add_to_cart_button' );
		$out .= (string) ob_get_clean();

		$out .= '</form>';
		ob_start();
		do_action( 'woocommerce_after_add_to_cart_form' );
		$out .= (string) ob_get_clean();
		$out .= '</div>';
		return $out;
	}

	/**
	 * "Separately 44,00 € · You save 8,80 € (20 %)".
	 *
	 * @param float $value Display value.
	 * @param float $price Display price.
	 */
	private static function savings_text( float $value, float $price ): string {
		$save = max( 0.0, $value - $price );
		$pct  = $value > 0 ? (int) round( $save / $value * 100 ) : 0;
		return PBN_Settings::textf( 't_savings', array( self::plain_price( $value ), self::plain_price( $save ), $pct . '%' ) );
	}

	/**
	 * One row of the contents.
	 *
	 * @param PBN_Product $b     Bundle.
	 * @param int         $i     Row index.
	 * @param array       $row   Row.
	 * @param bool        $buy   The form is shown (choices are selects).
	 * @param float       $fixed Running display value of the fixed rows.
	 */
	private static function row_html( PBN_Product $b, int $i, array $row, bool $buy, float &$fixed ): string {
		$qty = (int) $row['q'];
		if ( 'fixed' === $row['m'] ) {
			$p = PBN_Bundle::resolve( $row, null );
			if ( ! $p ) {
				return '';
			}
			$shown  = '' !== $p->get_price() ? self::shown( $p, (float) $p->get_price(), $qty ) : 0.0;
			$fixed += $shown;
			$name   = $p->get_name();
			return self::row_shell( $p, $name, $qty, '<span class="pbn-item__price">' . wp_kses_post( wc_price( $shown ) ) . '</span>' );
		}

		$parent = PBN_Bundle::product( (int) $row['p'] );
		if ( ! $parent ) {
			return '';
		}
		$choices = PBN_Bundle::choices( $row );
		if ( ! $buy ) {
			return self::row_shell( $parent, $parent->get_name(), $qty, '' );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- repopulates the select only.
		$posted = isset( $_REQUEST['pbn_choice'][ $i ] ) && is_scalar( $_REQUEST['pbn_choice'][ $i ] ) ? (int) $_REQUEST['pbn_choice'][ $i ] : 0;
		$id     = 'pbn-choice-' . (int) $b->get_id() . '-' . $i;
		$select = '<label class="pbn-choice__label" for="' . esc_attr( $id ) . '">' . esc_html( PBN_Settings::text( 't_choose' ) ) . '</label>'
			. '<select class="pbn-choice" id="' . esc_attr( $id ) . '" name="pbn_choice[' . esc_attr( (string) $i ) . ']" required>'
			. '<option value="">' . esc_html( PBN_Settings::text( 't_choose' ) ) . '&hellip;</option>';
		foreach ( $choices as $vid => $v ) {
			$ok      = PBN_Bundle::units_available( $v, $qty ) !== 0;
			$label   = wc_get_formatted_variation( $v, true, false, false );
			$label   = $ok ? $label : $label . ' (' . __( 'Out of stock', 'woocommerce' ) . ')'; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WooCommerce's own label.
			$select .= '<option value="' . esc_attr( (string) $vid ) . '" data-d="' . esc_attr( (string) self::shown( $v, (float) $v->get_price(), $qty ) ) . '"' . selected( $posted, $vid, false ) . disabled( ! $ok, true, false ) . '>' . esc_html( wp_strip_all_tags( $label ) ) . '</option>';
		}
		$select .= '</select>';
		return self::row_shell( $parent, $parent->get_name(), $qty, '<span class="pbn-item__price" data-pbn-row="' . esc_attr( (string) $i ) . '"></span>', $select );
	}

	/**
	 * Markup of a row: image, name (linked when the product has a page
	 * visitors can see), quantity, price, optional select.
	 *
	 * @param WC_Product $p      Product (a variation links to its parent).
	 * @param string     $name   Name.
	 * @param int        $qty    Quantity per bundle.
	 * @param string     $price  Price HTML.
	 * @param string     $select Select HTML.
	 */
	private static function row_shell( WC_Product $p, string $name, int $qty, string $price, string $select = '' ): string {
		$page = $p->is_type( 'variation' ) ? PBN_Bundle::product( (int) $p->get_parent_id() ) : $p;
		$link = $page && 'publish' === $page->get_status() && $page->is_visible() ? $page->get_permalink() : '';
		if ( $link && $p->is_type( 'variation' ) ) {
			$link = $p->get_permalink();
		}
		$img   = $p->get_image( 'woocommerce_gallery_thumbnail', array( 'class' => 'pbn-item__img' ) );
		$title = esc_html( $name );
		if ( $link ) {
			$title = '<a href="' . esc_url( $link ) . '">' . $title . '</a>';
		}
		$html  = '<div class="pbn-item">';
		$html .= '<span class="pbn-item__thumb">' . wp_kses_post( $img ) . '</span>';
		$html .= '<span class="pbn-item__body"><span class="pbn-item__name">' . $title . ( $qty > 1 ? ' <span class="pbn-item__qty">&times;&nbsp;' . esc_html( (string) $qty ) . '</span>' : '' ) . '</span>';
		if ( '' !== $select ) {
			$html .= '<span class="pbn-item__choice">' . $select . '</span>';
		}
		$html .= '</span>' . $price . '</div>';
		return $html;
	}
}

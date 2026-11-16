<?php
/**
 * GFC_Product_Gift_Card — WC_Product for the type gfc_gift_card.
 *
 * Loaded only when WooCommerce asks for the class (woocommerce_product_class).
 */

defined( 'ABSPATH' ) || exit;

class GFC_Product_Gift_Card extends WC_Product {

	public function get_type() {
		return GFC_Product::TYPE;
	}

	/** Always virtual: nothing ships. */
	public function get_virtual( $context = 'view' ) {
		return true;
	}

	public function is_virtual() {
		return true;
	}

	public function is_sold_individually() {
		return false;
	}

	/** The cart line needs the form (amount, recipient): no AJAX add from lists. */
	public function supports( $feature ) {
		return 'ajax_add_to_cart' === $feature ? false : parent::supports( $feature );
	}

	public function add_to_cart_url() {
		return apply_filters( 'woocommerce_product_add_to_cart_url', $this->get_permalink(), $this );
	}

	public function add_to_cart_text() {
		$text = $this->is_purchasable() ? GFC_Settings::text( 'btn_choose' ) : __( 'Read more', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WooCommerce's own string.
		return apply_filters( 'woocommerce_product_add_to_cart_text', $text, $this );
	}

	public function single_add_to_cart_text() {
		return apply_filters( 'woocommerce_product_single_add_to_cart_text', __( 'Add to cart', 'woocommerce' ), $this ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WooCommerce's own string.
	}

	public function is_purchasable() {
		$a  = GFC_Product::amounts( $this );
		$ok = $this->exists() && ( 'publish' === $this->get_status() || current_user_can( 'edit_post', $this->get_id() ) ) && ( $a['amounts'] || $a['custom'] );
		// Test mode: only shop managers can buy (and redeem) gift cards.
		$ok = $ok && ( 'live' === GFC_Settings::get()['mode'] || current_user_can( 'manage_woocommerce' ) );
		return apply_filters( 'woocommerce_is_purchasable', $ok, $this );
	}

	/** "€10 – €50" from the amounts. */
	public function get_price_html( $deprecated = '' ) {
		$a    = GFC_Product::amounts( $this );
		$list = $a['amounts'];
		if ( $a['custom'] ) {
			$list[] = $a['min'];
			$list[] = $a['max'];
		}
		if ( ! $list ) {
			return apply_filters( 'woocommerce_get_price_html', '', $this );
		}
		$lo   = min( $list );
		$hi   = max( $list );
		$html = $lo === $hi ? wc_price( $lo / 100 ) : wc_format_price_range( $lo / 100, $hi / 100 );
		return apply_filters( 'woocommerce_get_price_html', $html, $this );
	}
}

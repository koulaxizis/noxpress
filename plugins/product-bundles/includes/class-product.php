<?php
/**
 * PBN_Product — the "Bundle" product type (nox_bundle).
 *
 * The bundle itself is the shop window: page, images, URL, SEO, reviews,
 * a price computed from its contents and an availability computed from
 * their stock. It never becomes an order line: adding it to the cart puts
 * its products in the cart instead (PBN_Cart).
 *
 * Stored on the product (post meta, see PBN_Bundle):
 *  - _pbn_items          contents (JSON rows)
 *  - _pbn_discount_type  'percent' | 'price'
 *  - _pbn_discount       percent (0–99) or the bundle price
 *  - _pbn_max            most bundles ever sold (0 = no limit)
 *  - derived: _regular_price (value of the contents), _sale_price /
 *    _price (bundle price), _stock_status, _pbn_value_max, _pbn_price_max
 *    (when a choice changes the price), _pbn_avail.
 *
 * The type name is nox_bundle, not bundle: WooCommerce Product Bundles
 * uses "bundle".
 */

defined( 'ABSPATH' ) || exit;

class PBN_Product extends WC_Product {

	const TYPE = 'nox_bundle';

	/**
	 * Get internal type.
	 *
	 * @return string
	 */
	public function get_type() {
		return self::TYPE;
	}

	/**
	 * AJAX add to cart only without choices (a size to pick needs the page).
	 *
	 * @param string $feature Feature.
	 * @return bool
	 */
	public function supports( $feature ) {
		if ( 'ajax_add_to_cart' === $feature ) {
			$ok = ! PBN_Bundle::has_choices( $this );
			return (bool) apply_filters( 'woocommerce_product_supports', $ok, $feature, $this );
		}
		return parent::supports( $feature );
	}

	/**
	 * Purchasable: published (or editable by the user), priced, with
	 * contents, and bundles on sale for this visitor (mode / test mode).
	 *
	 * @return bool
	 */
	public function is_purchasable() {
		$ok = $this->exists()
			&& ( 'publish' === $this->get_status() || current_user_can( 'edit_post', $this->get_id() ) )
			&& '' !== $this->get_price()
			&& PBN_Settings::sellable()
			&& ! empty( PBN_Bundle::rows( $this ) );
		return (bool) apply_filters( 'woocommerce_is_purchasable', $ok, $this );
	}

	/**
	 * Bundles never manage their own stock: availability comes from the
	 * contents (PBN_Bundle::sync).
	 *
	 * @param string $context What the value is for.
	 * @return bool
	 */
	public function get_manage_stock( $context = 'view' ) {
		return false;
	}

	/**
	 * Add-to-cart link: the product page when the visitor has to choose.
	 *
	 * @return string
	 */
	public function add_to_cart_url() {
		$url = $this->get_permalink();
		if ( $this->is_purchasable() && $this->is_in_stock() && ! PBN_Bundle::has_choices( $this ) ) {
			$url = remove_query_arg( 'added-to-cart', add_query_arg( array( 'add-to-cart' => $this->get_id() ), $url ) );
		}
		return apply_filters( 'woocommerce_product_add_to_cart_url', $url, $this );
	}

	/**
	 * Add-to-cart text.
	 *
	 * @return string
	 */
	public function add_to_cart_text() {
		if ( ! $this->is_purchasable() || ! $this->is_in_stock() ) {
			$text = __( 'Read more', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WooCommerce's own label.
		} elseif ( PBN_Bundle::has_choices( $this ) ) {
			$text = PBN_Settings::text( 't_options' );
		} else {
			$text = __( 'Add to cart', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WooCommerce's own label.
		}
		return apply_filters( 'woocommerce_product_add_to_cart_text', $text, $this );
	}

	/**
	 * Single add-to-cart button text.
	 *
	 * @return string
	 */
	public function single_add_to_cart_text() {
		return apply_filters( 'woocommerce_product_single_add_to_cart_text', __( 'Add to cart', 'woocommerce' ), $this ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WooCommerce's own label.
	}

	/**
	 * Accessible description of the button.
	 *
	 * @return string
	 */
	public function add_to_cart_description() {
		/* translators: %s: product name */
		$text = $this->is_purchasable() && $this->is_in_stock() && ! PBN_Bundle::has_choices( $this ) ? __( 'Add to cart: &ldquo;%s&rdquo;', 'woocommerce' ) : __( 'Read more about &ldquo;%s&rdquo;', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WooCommerce's own label.
		return apply_filters( 'woocommerce_product_add_to_cart_description', sprintf( $text, $this->get_name() ), $this );
	}

	/**
	 * Price HTML: the value of the contents struck through and the bundle
	 * price, "From …" when a choice changes the price. Shown with the tax
	 * of each product inside (a bundle can mix VAT rates).
	 *
	 * @param string $deprecated Unused.
	 * @return string
	 */
	public function get_price_html( $deprecated = '' ) {
		if ( '' === $this->get_price() ) {
			return apply_filters( 'woocommerce_empty_price_html', '', $this );
		}
		try {
			$q = PBN_Bundle::quote( $this, array(), 'min' );
		} catch ( \Throwable $e ) {
			$q = null;
		}
		if ( null === $q || ! $q['ok'] ) {
			$html = wc_price( wc_get_price_to_display( $this ) ) . $this->get_price_suffix();
			return apply_filters( 'woocommerce_get_price_html', $html, $this );
		}
		if ( $q['display_price'] < $q['display_value'] - 0.004 ) {
			$html = wc_format_sale_price( $q['display_value'], $q['display_price'] );
		} else {
			$html = wc_price( $q['display_price'] );
		}
		if ( PBN_Bundle::price_varies( $this ) ) {
			$html = '<span class="pbn-from">' . esc_html( PBN_Settings::text( 't_from' ) ) . '</span> ' . $html;
		}
		$html .= $this->get_price_suffix();
		return apply_filters( 'woocommerce_get_price_html', $html, $this );
	}
}

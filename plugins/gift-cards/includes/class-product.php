<?php
/**
 * GFC_Product — the product type "Gift card" (gfc_gift_card, Bible §14.25).
 *
 *  - Always virtual; tax status "None" when the product is first saved
 *    (approved E7, changeable).
 *  - Amounts (E8): a list of fixed amounts and, optionally, the customer's
 *    own amount between a minimum and a maximum. Stored in cents in product
 *    meta (_gfc_amounts "1000,2000", _gfc_custom, _gfc_min, _gfc_max). The
 *    product price is the lowest amount (derived), so WooCommerce treats it
 *    as purchasable; the line price is the chosen amount.
 *  - The form on the product page (woocommerce_gfc_gift_card_add_to_cart,
 *    also called by the blocks): amount, for me / as a gift, recipient,
 *    sender, message (300 characters, plain text) and a delivery date up to
 *    12 months ahead (E9). The choice travels in the cart item (gfc) and
 *    onto the order line (hidden _gfc + readable rows).
 *  - No coupon discounts a gift card (E13), and suite plugins ask
 *    gfc_is_gift_card_product() before changing a price.
 */

defined( 'ABSPATH' ) || exit;

final class GFC_Product {

	const TYPE    = 'gfc_gift_card';
	const MSG_MAX = 300;

	public static function init(): void {
		add_filter( 'product_type_selector', array( __CLASS__, 'selector' ) );
		add_filter( 'woocommerce_product_class', array( __CLASS__, 'product_class' ), 10, 2 );
		add_filter( 'woocommerce_data_stores', array( __CLASS__, 'data_store' ) );

		if ( is_admin() ) {
			add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'admin_fields' ) );
			add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'admin_save' ) );
			add_action( 'admin_footer', array( __CLASS__, 'admin_js' ) );
		}

		// Cart and order lines: always (they keep existing carts right).
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'from_session' ), 20, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_prices' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_line' ), 10, 3 );
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( __CLASS__, 'no_coupon' ), 99, 2 );
		add_filter( 'woocommerce_order_item_display_meta_key', array( __CLASS__, 'meta_label' ), 10, 2 );
		// Suite contract (Bible §6): a gift-card line is stored value, not a product sale.
		add_filter( 'noxpress_is_stored_value_item', array( __CLASS__, 'stored_value' ), 10, 2 );

		if ( Gift_Cards::killed() ) {
			add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'not_purchasable' ), 99, 2 );
			return;
		}
		add_action( 'woocommerce_' . self::TYPE . '_add_to_cart', array( __CLASS__, 'form' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'cart_item_data' ), 10, 2 );
	}

	/* =====================================================================
	 * Type registration
	 * =================================================================== */

	public static function selector( $types ): array {
		$types                = is_array( $types ) ? $types : array();
		$types[ self::TYPE ] = __( 'Δωροκάρτα', 'gift-cards' );
		return $types;
	}

	public static function product_class( $class, $type ) {
		if ( self::TYPE === $type ) {
			require_once GFC_PATH . 'includes/class-product-type.php';
			return 'GFC_Product_Gift_Card';
		}
		return $class;
	}

	public static function data_store( $stores ): array {
		$stores                          = is_array( $stores ) ? $stores : array();
		$stores[ 'product-' . self::TYPE ] = 'WC_Product_Data_Store_CPT';
		return $stores;
	}

	/** @param int|WC_Product|null $product */
	public static function is_gift_card( $product ): bool {
		if ( is_numeric( $product ) ) {
			$product = wc_get_product( (int) $product );
		}
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		if ( $product->is_type( 'variation' ) ) {
			return false;
		}
		return self::TYPE === $product->get_type();
	}

	/** True when the cart has a gift card line. */
	public static function cart_has_gift_card(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item['gfc'] ) ) {
				return true;
			}
		}
		return false;
	}

	/* =====================================================================
	 * Amounts
	 * =================================================================== */

	/**
	 * @return array{amounts:int[],custom:bool,min:int,max:int}
	 */
	public static function amounts( WC_Product $product ): array {
		$list = array();
		foreach ( explode( ',', (string) $product->get_meta( '_gfc_amounts' ) ) as $c ) {
			$c = (int) $c;
			if ( $c > 0 ) {
				$list[] = $c;
			}
		}
		$list = array_values( array_unique( $list ) );
		sort( $list );
		$min = max( 100, (int) $product->get_meta( '_gfc_min' ) );
		$max = max( $min, (int) $product->get_meta( '_gfc_max' ) );
		return array(
			'amounts' => $list,
			'custom'  => 'yes' === $product->get_meta( '_gfc_custom' ),
			'min'     => $min,
			'max'     => $max,
		);
	}

	/** Is $cents a valid amount for the product? */
	public static function valid_amount( WC_Product $product, int $cents ): bool {
		$a = self::amounts( $product );
		if ( in_array( $cents, $a['amounts'], true ) ) {
			return true;
		}
		return $a['custom'] && $cents >= $a['min'] && $cents <= $a['max'];
	}

	/* =====================================================================
	 * Admin: product data
	 * =================================================================== */

	public static function admin_fields(): void {
		global $product_object;
		$p = $product_object instanceof WC_Product ? $product_object : null;
		$a = $p ? self::amounts( $p ) : array(
			'amounts' => array(),
			'custom'  => false,
			'min'     => 1000,
			'max'     => 20000,
		);
		$fmt = static function ( int $c ): string {
			return wc_format_localized_decimal( wc_format_decimal( $c / 100, 2 ) );
		};
		echo '<div class="options_group show_if_' . esc_attr( self::TYPE ) . '">';
		woocommerce_wp_text_input(
			array(
				'id'          => 'gfc_amounts',
				'label'       => __( 'Ποσά', 'gift-cards' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'value'       => implode( ', ', array_map( $fmt, $a['amounts'] ) ),
				'placeholder' => '10, 20, 30, 50',
				'desc_tip'    => true,
				'description' => __( 'Σταθερά ποσά χωρισμένα με κόμμα ή κενό (π.χ. 10 20 30 50). Ο πελάτης διαλέγει ένα.', 'gift-cards' ),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => 'gfc_custom',
				'label'       => __( 'Δικό του ποσό', 'gift-cards' ),
				'value'       => $a['custom'] ? 'yes' : 'no',
				'description' => __( 'Ο πελάτης γράφει δικό του ποσό, ανάμεσα στο ελάχιστο και στο μέγιστο.', 'gift-cards' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => 'gfc_min',
				'label' => __( 'Ελάχιστο', 'gift-cards' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'value' => $fmt( $a['min'] ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => 'gfc_max',
				'label' => __( 'Μέγιστο', 'gift-cards' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'value' => $fmt( $a['max'] ),
			)
		);
		echo '<p class="form-field"><span class="description">' . esc_html__( 'Η δωροκάρτα είναι πάντα εικονική. Ο φόρος της μπαίνει «Κανένας» στην πρώτη αποθήκευση· άλλαξέ τον μόνο αν το πει ο λογιστής σου.', 'gift-cards' ) . '</span></p>';
		wp_nonce_field( 'gfc_product', 'gfc_product_nonce' );
		echo '</div>';
	}

	public static function admin_save( WC_Product $product ): void {
		if ( ! isset( $_POST['gfc_product_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['gfc_product_nonce'] ) ), 'gfc_product' ) ) {
			return;
		}
		if ( ! self::is_gift_card( $product ) || ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}
		$amounts = array();
		$raw     = isset( $_POST['gfc_amounts'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['gfc_amounts'] ) ) : '';
		// Comma or space separated; a decimal comma between two digits ("12,50") stays a decimal.
		$raw = (string) preg_replace( '/(\d),(\d{1,2})(?!\d)/', '$1.$2', $raw );
		foreach ( preg_split( '/[\s,;]+/', $raw ) as $v ) {
			$c = GFC_Settings::to_cents( (string) $v );
			if ( null !== $c && $c >= 100 && $c <= 100000000 ) {
				$amounts[] = $c;
			}
		}
		$amounts = array_values( array_unique( $amounts ) );
		sort( $amounts );
		$min = GFC_Settings::to_cents( isset( $_POST['gfc_min'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['gfc_min'] ) ) : '' );
		$max = GFC_Settings::to_cents( isset( $_POST['gfc_max'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['gfc_max'] ) ) : '' );
		$min = null === $min ? 1000 : max( 100, $min );
		$max = null === $max ? max( $min, 20000 ) : max( $min, min( 100000000, $max ) );

		$custom = isset( $_POST['gfc_custom'] ) ? 'yes' : 'no';
		$product->update_meta_data( '_gfc_amounts', implode( ',', $amounts ) );
		$product->update_meta_data( '_gfc_custom', $custom );
		$product->update_meta_data( '_gfc_min', $min );
		$product->update_meta_data( '_gfc_max', $max );

		// The product price is derived: the lowest possible amount.
		$low = $amounts ? $amounts[0] : ( 'yes' === $custom ? $min : 0 );
		$product->set_regular_price( $low > 0 ? wc_format_decimal( $low / 100, 2 ) : '' );
		$product->set_sale_price( '' );
		$product->set_virtual( true );
		$product->set_downloadable( false );
		if ( 'yes' !== $product->get_meta( '_gfc_tax_init' ) ) {
			$product->set_tax_status( 'none' );
			$product->update_meta_data( '_gfc_tax_init', 'yes' );
		}
	}

	/** Shows our fields only for the gift card type (product edit screen). */
	public static function admin_js(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}
		?>
		<script>
		jQuery( function ( $ ) {
			$( '.options_group.pricing' ).addClass( 'hide_if_<?php echo esc_js( self::TYPE ); ?>' );
			$( '#_virtual, #_downloadable' ).closest( 'label' ).addClass( 'hide_if_<?php echo esc_js( self::TYPE ); ?>' );
			$( '.inventory_options' ).addClass( 'show_if_<?php echo esc_js( self::TYPE ); ?>' );
			$( 'select#product-type' ).trigger( 'change' );
		} );
		</script>
		<?php
	}

	/* =====================================================================
	 * Product page form
	 * =================================================================== */

	public static function form(): void {
		try {
			global $product;
			if ( ! $product instanceof WC_Product || ! self::is_gift_card( $product ) || ! $product->is_purchasable() ) {
				return;
			}
			$a        = self::amounts( $product );
			$cur      = get_woocommerce_currency();
			$max_date = gmdate( 'Y-m-d', strtotime( '+12 months', current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- local date for the date input.
			$today    = current_time( 'Y-m-d' );
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- refill after a failed add to cart (WooCommerce's own form has no nonce).
			$old = static function ( string $k ): string {
				return isset( $_POST[ $k ] ) && is_scalar( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $k ] ) ) : '';
			};
			// phpcs:enable
			$sel = $old( 'gfc_amount' );
			if ( '' === $sel && $a['amounts'] ) {
				$sel = (string) $a['amounts'][0];
			}
			$for = 'me' === $old( 'gfc_for' ) ? 'me' : 'gift';
			do_action( 'woocommerce_before_add_to_cart_form' );
			?>
			<form class="cart gfc-form" action="<?php echo esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', $product->get_permalink() ) ); ?>" method="post" enctype="multipart/form-data">
				<?php do_action( 'woocommerce_before_add_to_cart_button' ); ?>
				<fieldset class="gfc-amounts">
					<legend><?php echo esc_html( GFC_Settings::text( 'amount' ) ); ?></legend>
					<?php foreach ( $a['amounts'] as $c ) : ?>
						<label class="gfc-amount"><input type="radio" name="gfc_amount" value="<?php echo esc_attr( (string) $c ); ?>" <?php checked( (string) $c, $sel ); ?> /> <?php echo esc_html( GFC_Settings::money( $c, $cur ) ); ?></label>
					<?php endforeach; ?>
					<?php if ( $a['custom'] ) : ?>
						<label class="gfc-amount gfc-amount--custom"><input type="radio" name="gfc_amount" value="custom" <?php checked( 'custom', $a['amounts'] ? $sel : 'custom' ); ?> />
							<?php echo GFC_Settings::fill( 'custom_amount', array( 'min' => GFC_Settings::money( $a['min'], $cur ), 'max' => GFC_Settings::money( $a['max'], $cur ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in fill(). ?>
						</label>
						<input type="text" inputmode="decimal" class="input-text gfc-custom" name="gfc_custom" value="<?php echo esc_attr( $old( 'gfc_custom' ) ); ?>" maxlength="12" aria-label="<?php echo esc_attr( GFC_Settings::text( 'amount' ) ); ?>" />
					<?php endif; ?>
				</fieldset>
				<p class="gfc-for">
					<label><input type="radio" name="gfc_for" value="gift" <?php checked( 'gift', $for ); ?> /> <?php echo esc_html( GFC_Settings::text( 'for_gift' ) ); ?></label>
					<label><input type="radio" name="gfc_for" value="me" <?php checked( 'me', $for ); ?> /> <?php echo esc_html( GFC_Settings::text( 'for_me' ) ); ?></label>
				</p>
				<div class="gfc-gift"<?php echo 'me' === $for ? ' hidden' : ''; ?>>
					<p><label for="gfc_rcpt_name"><?php echo esc_html( GFC_Settings::text( 'rcpt_name' ) ); ?></label><input type="text" class="input-text" id="gfc_rcpt_name" name="gfc_rcpt_name" maxlength="100" value="<?php echo esc_attr( $old( 'gfc_rcpt_name' ) ); ?>" /></p>
					<p><label for="gfc_rcpt_email"><?php echo esc_html( GFC_Settings::text( 'rcpt_email' ) ); ?></label><input type="email" class="input-text" id="gfc_rcpt_email" name="gfc_rcpt_email" maxlength="100" value="<?php echo esc_attr( $old( 'gfc_rcpt_email' ) ); ?>" /></p>
					<p><label for="gfc_sender"><?php echo esc_html( GFC_Settings::text( 'sender' ) ); ?></label><input type="text" class="input-text" id="gfc_sender" name="gfc_sender" maxlength="100" value="<?php echo esc_attr( $old( 'gfc_sender' ) ); ?>" /></p>
					<p><label for="gfc_message"><?php echo esc_html( GFC_Settings::text( 'message' ) ); ?></label><textarea class="input-text" id="gfc_message" name="gfc_message" maxlength="<?php echo (int) self::MSG_MAX; ?>" rows="3"><?php echo esc_textarea( $old( 'gfc_message' ) ); ?></textarea></p>
					<p><label for="gfc_date"><?php echo esc_html( GFC_Settings::text( 'date' ) ); ?></label><input type="date" class="input-text" id="gfc_date" name="gfc_date" min="<?php echo esc_attr( $today ); ?>" max="<?php echo esc_attr( $max_date ); ?>" value="<?php echo esc_attr( $old( 'gfc_date' ) ); ?>" /><small><?php echo esc_html( GFC_Settings::text( 'date_hint' ) ); ?></small></p>
				</div>
				<?php
				woocommerce_quantity_input(
					array(
						'min_value'   => 1,
						'max_value'   => 10,
						'input_value' => 1,
					)
				);
				?>
				<button type="submit" name="add-to-cart" value="<?php echo esc_attr( (string) $product->get_id() ); ?>" class="single_add_to_cart_button button alt<?php echo esc_attr( function_exists( 'wc_wp_theme_get_element_class_name' ) && wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>"><?php echo esc_html( $product->single_add_to_cart_text() ); ?></button>
				<?php do_action( 'woocommerce_after_add_to_cart_button' ); ?>
			</form>
			<style id="gfc-form-css">
			.gfc-form .gfc-amounts{border:0;padding:0;margin:0 0 1em}
			.gfc-form .gfc-amount{display:inline-block;margin:0 1em .4em 0;cursor:pointer}
			.gfc-form .gfc-custom{max-width:9em;margin-top:.3em}
			.gfc-form .gfc-for label{display:inline-block;margin-right:1.2em;cursor:pointer}
			.gfc-form .gfc-gift p label{display:block;font-weight:600}
			.gfc-form .gfc-gift p input,.gfc-form .gfc-gift p textarea{width:100%;max-width:28em}
			.gfc-form .gfc-gift small{display:block;opacity:.75}
			.gfc-form .gfc-gift[hidden]{display:none}
			</style>
			<script>
			( function () {
				var f = document.currentScript && document.currentScript.previousElementSibling && document.currentScript.previousElementSibling.previousElementSibling;
				if ( ! f || ! f.classList || ! f.classList.contains( 'gfc-form' ) ) { return; }
				var gift = f.querySelector( '.gfc-gift' );
				f.addEventListener( 'change', function ( e ) {
					if ( e.target && 'gfc_for' === e.target.name ) { gift.hidden = 'me' === e.target.value; }
				} );
				var c = f.querySelector( '.gfc-custom' );
				if ( c ) {
					c.addEventListener( 'focus', function () {
						var r = f.querySelector( 'input[name="gfc_amount"][value="custom"]' );
						if ( r ) { r.checked = true; }
					} );
				}
			}() );
			</script>
			<?php
			do_action( 'woocommerce_after_add_to_cart_form' );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/* =====================================================================
	 * Cart
	 * =================================================================== */

	/**
	 * The posted choice, validated. Returns [data, error key].
	 *
	 * @return array{0:?array,1:string}
	 */
	private static function posted( WC_Product $product ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce's add to cart form has no nonce; nothing here is privileged.
		$get = static function ( string $k ): string {
			return isset( $_POST[ $k ] ) && is_scalar( $_POST[ $k ] ) ? (string) wp_unslash( $_POST[ $k ] ) : '';
		};
		// phpcs:enable
		$sel = sanitize_text_field( $get( 'gfc_amount' ) );
		if ( 'custom' === $sel ) {
			$cents = GFC_Settings::to_cents( sanitize_text_field( $get( 'gfc_custom' ) ) );
		} else {
			$cents = ctype_digit( $sel ) ? (int) $sel : null;
		}
		if ( null === $cents || ! self::valid_amount( $product, $cents ) ) {
			return array( null, 'err_amount' );
		}
		$for  = 'me' === $get( 'gfc_for' ) ? 'me' : 'gift';
		$data = array(
			'amount'  => $cents,
			'for'     => $for,
			'name'    => '',
			'email'   => '',
			'sender'  => '',
			'message' => '',
			'date'    => '',
		);
		if ( 'gift' === $for ) {
			$data['name']    = mb_substr( sanitize_text_field( $get( 'gfc_rcpt_name' ) ), 0, 100 );
			$data['email']   = sanitize_email( $get( 'gfc_rcpt_email' ) );
			$data['sender']  = mb_substr( sanitize_text_field( $get( 'gfc_sender' ) ), 0, 100 );
			$data['message'] = mb_substr( sanitize_textarea_field( $get( 'gfc_message' ) ), 0, self::MSG_MAX );
			if ( '' === $data['name'] || '' === $data['email'] || ! is_email( $data['email'] ) || strlen( $data['email'] ) > 100 ) {
				return array( null, 'err_fields' );
			}
			$date = sanitize_text_field( $get( 'gfc_date' ) );
			if ( '' !== $date ) {
				$max = gmdate( 'Y-m-d', strtotime( '+12 months', current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- local date.
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || $date < current_time( 'Y-m-d' ) || $date > $max ) {
					return array( null, 'err_date' );
				}
				$data['date'] = $date;
			}
		}
		return array( $data, '' );
	}

	public static function validate( $passed, $product_id, $qty = 1 ) {
		try {
			$product = wc_get_product( (int) $product_id );
			if ( ! $passed || ! $product || ! self::is_gift_card( $product ) ) {
				return $passed;
			}
			list( $data, $err ) = self::posted( $product );
			if ( ! $data ) {
				$max = date_i18n( get_option( 'date_format' ), strtotime( '+12 months', current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
				wc_add_notice( GFC_Settings::fill( $err, array( 'max' => $max ) ), 'error' );
				return false;
			}
			return $passed;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public static function cart_item_data( $data, $product_id ) {
		$data    = is_array( $data ) ? $data : array();
		$product = wc_get_product( (int) $product_id );
		if ( ! $product || ! self::is_gift_card( $product ) ) {
			return $data;
		}
		list( $gfc ) = self::posted( $product );
		if ( $gfc ) {
			$data['gfc'] = $gfc;
		}
		return $data;
	}

	public static function from_session( $item, $values ) {
		if ( isset( $values['gfc'] ) && is_array( $values['gfc'] ) ) {
			$item['gfc'] = $values['gfc'];
			if ( isset( $item['data'] ) && $item['data'] instanceof WC_Product ) {
				$item['data']->set_price( wc_format_decimal( (int) $values['gfc']['amount'] / 100, 2 ) );
			}
		}
		return $item;
	}

	public static function set_prices( $cart ): void {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item['gfc'] ) || ! isset( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}
			if ( ! self::is_gift_card( $item['data'] ) || ! self::valid_amount( $item['data'], (int) $item['gfc']['amount'] ) ) {
				// The product changed since: the line cannot be sold at a stale amount.
				$cart->remove_cart_item( $key );
				continue;
			}
			$item['data']->set_price( wc_format_decimal( (int) $item['gfc']['amount'] / 100, 2 ) );
		}
	}

	public static function item_data( $rows, $item ) {
		$rows = is_array( $rows ) ? $rows : array();
		if ( empty( $item['gfc'] ) || ! is_array( $item['gfc'] ) ) {
			return $rows;
		}
		$g = $item['gfc'];
		if ( 'me' === $g['for'] ) {
			$rows[] = array(
				'key'   => GFC_Settings::text( 'for_me' ),
				'value' => GFC_Settings::text( 'line_me' ),
			);
			return $rows;
		}
		$rows[] = array(
			'key'   => GFC_Settings::text( 'line_to' ),
			'value' => $g['name'] . ' <' . $g['email'] . '>',
		);
		if ( '' !== $g['sender'] ) {
			$rows[] = array(
				'key'   => GFC_Settings::text( 'line_from' ),
				'value' => $g['sender'],
			);
		}
		$rows[] = array(
			'key'   => GFC_Settings::text( 'line_date' ),
			'value' => '' !== $g['date'] ? date_i18n( get_option( 'date_format' ), strtotime( $g['date'] . ' 12:00' ) ) : GFC_Settings::text( 'line_now' ),
		);
		return $rows;
	}

	/** Copies the choice onto the order line (Store API calls this hook too). */
	public static function order_line( $line, $key, $values ): void {
		if ( ! $line instanceof WC_Order_Item_Product || empty( $values['gfc'] ) || ! is_array( $values['gfc'] ) ) {
			return;
		}
		$g = $values['gfc'];
		$line->update_meta_data( '_gfc', wp_json_encode( $g ) );
		$line->update_meta_data( '_gfc_lang', GFC_Settings::visitor_lang() );
		if ( 'me' === $g['for'] ) {
			$line->update_meta_data( 'gfc_for', GFC_Settings::text( 'line_me' ) );
			return;
		}
		$line->update_meta_data( 'gfc_to', $g['name'] . ' <' . $g['email'] . '>' );
		if ( '' !== $g['sender'] ) {
			$line->update_meta_data( 'gfc_from', $g['sender'] );
		}
		$line->update_meta_data( 'gfc_date', '' !== $g['date'] ? date_i18n( get_option( 'date_format' ), strtotime( $g['date'] . ' 12:00' ) ) : GFC_Settings::text( 'line_now' ) );
	}

	/** Readable labels for the visible order line meta. */
	public static function meta_label( $display_key, $meta ) {
		$map = array(
			'gfc_to'   => 'line_to',
			'gfc_from' => 'line_from',
			'gfc_date' => 'line_date',
			'gfc_for'  => 'for_me',
		);
		if ( is_object( $meta ) && isset( $meta->key, $map[ $meta->key ] ) ) {
			return GFC_Settings::text( $map[ $meta->key ] );
		}
		return $display_key;
	}

	/** The choice stored on an order line, or null. */
	/**
	 * noxpress_is_stored_value_item: true for an order line of a gift card.
	 *
	 * @param bool  $is   Current value.
	 * @param mixed $item Order item.
	 */
	public static function stored_value( $is, $item ): bool {
		return (bool) $is || ( $item instanceof WC_Order_Item_Product && null !== self::line_data( $item ) );
	}

	public static function line_data( WC_Order_Item_Product $line ): ?array {
		$g = json_decode( (string) $line->get_meta( '_gfc' ), true );
		if ( ! is_array( $g ) || empty( $g['amount'] ) ) {
			return null;
		}
		return array(
			'amount'  => (int) $g['amount'],
			'for'     => 'me' === ( $g['for'] ?? '' ) ? 'me' : 'gift',
			'name'    => (string) ( $g['name'] ?? '' ),
			'email'   => (string) ( $g['email'] ?? '' ),
			'sender'  => (string) ( $g['sender'] ?? '' ),
			'message' => (string) ( $g['message'] ?? '' ),
			'date'    => (string) ( $g['date'] ?? '' ),
			'lang'    => 'en' === $line->get_meta( '_gfc_lang' ) ? 'en' : 'el',
		);
	}

	public static function no_coupon( $valid, $product ) {
		return self::is_gift_card( $product ) ? false : $valid;
	}

	public static function not_purchasable( $purchasable, $product ) {
		return self::is_gift_card( $product ) ? false : $purchasable;
	}
}

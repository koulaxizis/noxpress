<?php
/**
 * EWD_Checkout — the two product-side exclusions (design §5).
 *
 *  - "Personalised" checkbox in the product's General tab (product meta
 *    _ewd_personalized = yes). Admin only.
 *  - Consent for digital items (setting "consent", off by default). When
 *    the cart holds virtual or downloadable products, the checkout asks
 *    for the consent the Directive requires before the right is lost:
 *      classic checkout → a required checkbox above "Place order", saved
 *        as _ewd_digital_consent (Unix time) and _ewd_digital_consent_text;
 *      block checkout (WC 9.9+) → an additional order field
 *        easy-withdrawal/digital-consent, shown and required only when the
 *        cart has digital items (cart extension data easy-withdrawal.digital).
 *    Without the consent the digital items stay returnable.
 *
 * The checkout parts follow the mode (live for everyone, test for shop
 * managers) and EWD_DISABLE.
 */

defined( 'ABSPATH' ) || exit;

final class EWD_Checkout {

	const FIELD_ID = 'easy-withdrawal/digital-consent';

	public static function init(): void {
		if ( is_admin() ) {
			add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_field' ) );
			add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'product_save' ) );
		}

		if ( Easy_Withdrawal::killed() ) {
			return;
		}
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'classic_field' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'classic_validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'classic_save' ), 10, 2 );
		add_action( 'woocommerce_init', array( __CLASS__, 'block_register' ) );
	}

	/* =====================================================================
	 * Product flag
	 * =================================================================== */

	public static function product_field(): void {
		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => EWD_Settings::META_PERSONAL,
				'label'       => __( 'Εξατομικευμένο', 'easy-withdrawal' ),
				'description' => __( 'Φτιάχνεται κατά παραγγελία ή με προδιαγραφές του πελάτη: εξαιρείται από την υπαναχώρηση (Easy Withdrawal).', 'easy-withdrawal' ),
			)
		);
		echo '</div>';
	}

	public static function product_save( $product ): void {
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product form nonce.
		$on = isset( $_POST[ EWD_Settings::META_PERSONAL ] ) && 'yes' === sanitize_key( wp_unslash( $_POST[ EWD_Settings::META_PERSONAL ] ) );
		if ( $on ) {
			$product->update_meta_data( EWD_Settings::META_PERSONAL, 'yes' );
		} else {
			$product->delete_meta_data( EWD_Settings::META_PERSONAL );
		}
	}

	/* =====================================================================
	 * Consent: shared
	 * =================================================================== */

	private static function active(): bool {
		return (bool) EWD_Settings::get()['consent'] && EWD_Settings::visible();
	}

	/** True when the cart holds a virtual or downloadable product that is not excluded otherwise. */
	public static function cart_has_digital(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $line ) {
			$p = isset( $line['data'] ) ? $line['data'] : null;
			if ( EWD_Rules::is_digital( $p ) ) {
				return true;
			}
		}
		return false;
	}

	/* =====================================================================
	 * Consent: classic checkout
	 * =================================================================== */

	public static function classic_field(): void {
		try {
			if ( ! self::active() || ! self::cart_has_digital() ) {
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- display only (re-render after a failed submit).
			$checked = ! empty( $_POST['ewd_digital_consent'] );
			echo '<p class="form-row validate-required ewd-consent" id="ewd_digital_consent_field">';
			echo '<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">';
			echo '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="ewd_digital_consent" id="ewd_digital_consent" value="1"' . checked( $checked, true, false ) . ' /> ';
			echo '<span>' . esc_html( EWD_Settings::text( 'consent' ) ) . '</span>&nbsp;<abbr class="required" title="required">*</abbr>';
			echo '</label></p>';
		} catch ( \Throwable $e ) {
			return;
		}
	}

	public static function classic_validate( $data, $errors ): void {
		if ( ! $errors instanceof WP_Error || ! self::active() || ! self::cart_has_digital() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce.
		if ( empty( $_POST['ewd_digital_consent'] ) ) {
			$errors->add( 'ewd_digital_consent', __( 'Για τα ψηφιακά προϊόντα χρειάζεται η συναίνεσή σου στην άμεση παράδοση.', 'easy-withdrawal' ) );
		}
	}

	public static function classic_save( $order, $data ): void {
		if ( ! $order instanceof WC_Order || ! self::active() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce.
		if ( ! empty( $_POST['ewd_digital_consent'] ) && self::cart_has_digital() ) {
			$order->update_meta_data( EWD_Rules::META_CONSENT, time() );
			$order->update_meta_data( '_ewd_digital_consent_text', EWD_Settings::text( 'consent' ) );
		}
	}

	/* =====================================================================
	 * Consent: block checkout (WooCommerce 9.9+: conditional field rules)
	 * =================================================================== */

	public static function block_register(): void {
		try {
			if ( ! self::active() || ! function_exists( 'woocommerce_register_additional_checkout_field' ) || ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
				return;
			}
			if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '9.9', '<' ) ) {
				return;
			}

			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
					'namespace'       => 'easy-withdrawal',
					'data_callback'   => static function () {
						return array( 'digital' => self::cart_has_digital() );
					},
					'schema_callback' => static function () {
						return array(
							'digital' => array(
								'description' => 'Cart holds virtual or downloadable products.',
								'type'        => 'boolean',
								'readonly'    => true,
							),
						);
					},
					'schema_type'     => ARRAY_A,
				)
			);

			$digital = array(
				'cart' => array(
					'properties' => array(
						'extensions' => array(
							'properties' => array(
								'easy-withdrawal' => array(
									'properties' => array(
										'digital' => array( 'const' => true ),
									),
									'required'   => array( 'digital' ),
								),
							),
							'required'   => array( 'easy-withdrawal' ),
						),
					),
				),
			);

			woocommerce_register_additional_checkout_field(
				array(
					'id'       => self::FIELD_ID,
					'label'    => EWD_Settings::text( 'consent' ),
					'location' => 'order',
					'type'     => 'checkbox',
					'required' => array(
						'type'       => 'object',
						'properties' => $digital,
					),
					'hidden'   => array(
						'type' => 'object',
						'not'  => array(
							'type'       => 'object',
							'properties' => $digital,
						),
					),
				)
			);
		} catch ( \Throwable $e ) {
			return;
		}
	}
}

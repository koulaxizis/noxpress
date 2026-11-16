<?php
/**
 * IVD_Checkout — the receipt / invoice choice at checkout.
 *
 * Classic checkout
 *  - After the billing form (woocommerce_after_checkout_billing_form):
 *    radios «Απόδειξη / Τιμολόγιο» and, for an invoice, company (only when
 *    the store hides WooCommerce's own company field), VAT number, ΔΟΥ and
 *    activity (Greek billing address only), head office (optional). A small
 *    script shows the fields for an invoice and the Greek ones for Greece.
 *  - Values survive update_order_review through the posted form (post_data),
 *    never a cookie or the session (Bible §14.5). A hidden marker tells
 *    "not chosen" from "the form is not there".
 *  - Validation in woocommerce_after_checkout_validation: required fields,
 *    the Greek check digit, VIES for EU numbers of other countries (when
 *    on). VIES not answering never stops the order; a number VIES does not
 *    know stops it only with vies_invalid = reject.
 *  - Saved in woocommerce_checkout_create_order (IVD_Data::store), and for
 *    logged-in customers on their account for the next order.
 *
 * Checkout block (WooCommerce 9.9+)
 *  - Additional checkout fields in the "contact" location (saved on the
 *    customer by WooCommerce, editable in My account › Account details):
 *    a checkbox «Θέλω τιμολόγιο» and the fields, shown and required through
 *    JSON-schema conditions (ticked; Greek billing country for ΔΟΥ and
 *    activity).
 *  - Number checks in woocommerce_blocks_validate_location_contact_fields;
 *    VIES and "invoice required" in woocommerce_store_api_checkout_order_processed,
 *    which runs before payment (a RouteException stops the order there),
 *    where the fields are also copied to our meta.
 *
 * Another plugin may ask for an invoice (filter ivd_require_invoice, e.g.
 * Trade Accounts for trade orders): the invoice is preselected (classic)
 * and a receipt is refused in both checkouts.
 *
 * Every callback is wrapped in try/catch (Bible §14.6).
 */

defined( 'ABSPATH' ) || exit;

final class IVD_Checkout {

	/** Set after the first error: the part stays out for the rest of the request. */
	private static $broken = false;

	/** Check result of the classic validation, reused when the order is created. */
	private static $check = null;

	public static function init(): void {
		add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'render' ), 20 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_classic' ), 20, 2 );
		add_action( 'woocommerce_init', array( __CLASS__, 'block_register' ) );
		add_action( 'woocommerce_blocks_validate_location_contact_fields', array( __CLASS__, 'block_validate' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'save_block' ), 5 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'note_classic' ), 20, 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	private static function fail( \Throwable $e ): void {
		self::$broken = true;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only.
			error_log( 'Invoice Details (checkout): ' . $e->getMessage() );
		}
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	/** True when the choice may show for the current visitor. */
	private static function offered(): bool {
		return ! self::$broken && IVD_Settings::visible();
	}

	/** Public form of offered() for ivd_offered(). */
	public static function is_offered(): bool {
		return self::offered();
	}

	/** True when the store hides WooCommerce's company field (we show ours). */
	public static function own_company(): bool {
		return 'hidden' === get_option( 'woocommerce_checkout_company_field', 'optional' );
	}

	/** True when another plugin asks for an invoice for the current cart. */
	public static function invoice_required(): bool {
		$cart = function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;
		/**
		 * Asks for an invoice for this cart (e.g. Trade Accounts for trade orders).
		 *
		 * @param bool         $required Default false.
		 * @param WC_Cart|null $cart     Cart.
		 */
		return (bool) apply_filters( 'ivd_require_invoice', false, $cart );
	}

	/** A field mode for the current settings: 'required' | 'optional' | 'hidden'. */
	private static function mode( string $field ): string {
		$s = IVD_Settings::get();
		switch ( $field ) {
			case 'tax_office':
				return $s['f_tax_office'];
			case 'activity':
				return $s['f_activity'];
			case 'hq':
				return 'optional' === $s['f_hq'] ? 'optional' : 'hidden';
			default:
				return 'required';
		}
	}

	/** The label of a field (visitor text). */
	private static function label( string $field ): string {
		return IVD_Settings::text( $field );
	}

	/**
	 * The posted values of the classic checkout: the checkout POST or the
	 * update_order_review post_data. WooCommerce verifies its own nonces.
	 *
	 * @return array|null null when our form part was not posted.
	 */
	private static function posted(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its checkout / update_order_review nonces; only whitelisted fields are read.
		$src = null;
		if ( isset( $_POST['ivd_present'] ) ) {
			$src = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only our fields are read, each cleaned below.
		} elseif ( isset( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) {
			$data = array();
			wp_parse_str( wp_unslash( $_POST['post_data'] ), $data ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only our fields are read, each cleaned below.
			if ( isset( $data['ivd_present'] ) ) {
				$src = $data;
			}
		}
		// phpcs:enable
		if ( null === $src ) {
			return null;
		}
		$country = isset( $src['billing_country'] ) && is_string( $src['billing_country'] ) ? strtoupper( substr( sanitize_key( $src['billing_country'] ), 0, 2 ) ) : '';
		$out     = array(
			'invoice' => isset( $src['ivd_type'] ) && 'invoice' === $src['ivd_type'],
			'country' => $country,
		);
		foreach ( array( 'company', 'vat', 'tax_office', 'activity', 'hq' ) as $f ) {
			$out[ $f ] = isset( $src[ 'ivd_' . $f ] ) ? IVD_Data::clean( $f, $src[ 'ivd_' . $f ] ) : '';
		}
		if ( ! self::own_company() ) {
			$out['company'] = isset( $src['billing_company'] ) ? IVD_Data::clean( 'company', $src['billing_company'] ) : '';
		}
		return $out;
	}

	/**
	 * Checks the values of an invoice. Returns error messages (field => text).
	 *
	 * @param array $v       Values (company, vat, tax_office, activity, hq).
	 * @param string $billing Billing country.
	 */
	public static function errors( array $v, string $billing ): array {
		$err = array();
		if ( '' === $v['company'] ) {
			$err['company'] = IVD_Settings::required_text( self::label( 'company' ) );
		}
		if ( '' === $v['vat'] ) {
			$err['vat'] = IVD_Settings::required_text( self::label( 'vat' ) );
		} else {
			list( $country, $n ) = IVD_Vat::split( $v['vat'], $billing );
			if ( ! IVD_Vat::format_ok( $n, $country ) ) {
				$err['vat'] = IVD_Settings::text( 'vat_bad' );
			}
		}
		if ( 'GR' === strtoupper( $billing ) ) {
			foreach ( array( 'tax_office', 'activity' ) as $f ) {
				if ( 'required' === self::mode( $f ) && '' === $v[ $f ] ) {
					$err[ $f ] = IVD_Settings::required_text( self::label( $f ) );
				}
			}
		}
		return $err;
	}

	/**
	 * The details to store from checked values.
	 *
	 * @param array  $v       Values.
	 * @param string $billing Billing country.
	 */
	private static function details( array $v, string $billing ): array {
		list( $country, $n ) = IVD_Vat::split( $v['vat'], $billing );
		$gr                  = 'GR' === strtoupper( $billing );
		return array(
			'company'    => $v['company'],
			'vat'        => $n,
			'country'    => $country,
			'tax_office' => $gr && 'hidden' !== self::mode( 'tax_office' ) ? $v['tax_office'] : '',
			'activity'   => $gr && 'hidden' !== self::mode( 'activity' ) ? $v['activity'] : '',
			'hq'         => 'hidden' !== self::mode( 'hq' ) ? $v['hq'] : '',
		);
	}

	/** The check of stored details (local / VIES). */
	private static function run_check( array $d ): array {
		return IVD_Vat::check( $d['vat'], $d['country'], (bool) IVD_Settings::get()['vies'] );
	}

	/* =====================================================================
	 * Classic checkout
	 * =================================================================== */

	public static function render(): void {
		if ( ! self::offered() ) {
			return;
		}
		try {
			$p = self::posted();
			if ( null === $p ) {
				$saved = IVD_Data::customer( get_current_user_id() );
				$p     = array(
					'invoice'    => $saved['invoice'] || self::invoice_required(),
					'company'    => '',
					'vat'        => $saved['vat'],
					'tax_office' => $saved['tax_office'],
					'activity'   => $saved['activity'],
					'hq'         => $saved['hq'],
				);
			}
			echo '<div class="ivd-box" id="ivd-box">';
			echo '<input type="hidden" name="ivd_present" value="1" />';
			self::type_field( $p['invoice'] ? 'invoice' : 'receipt' );
			$help = IVD_Settings::text( 'help' );
			if ( '' !== $help ) {
				echo '<p class="ivd-help">' . esc_html( $help ) . '</p>';
			}
			echo '<div class="ivd-fields">';
			if ( self::own_company() ) {
				self::text_field( 'company', $p['company'], true, 'form-row-wide' );
			}
			self::text_field( 'vat', $p['vat'], true, 'form-row-first' );
			if ( 'hidden' !== self::mode( 'tax_office' ) ) {
				self::text_field( 'tax_office', $p['tax_office'], 'required' === self::mode( 'tax_office' ), 'form-row-last ivd-gr' );
			}
			if ( 'hidden' !== self::mode( 'activity' ) ) {
				self::text_field( 'activity', $p['activity'], 'required' === self::mode( 'activity' ), 'form-row-wide ivd-gr' );
			}
			if ( 'hidden' !== self::mode( 'hq' ) ) {
				self::text_field( 'hq', $p['hq'], false, 'form-row-wide' );
			}
			echo '</div></div>';
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * The «Receipt / Invoice» radios of the classic form, in WooCommerce's own
	 * markup but with one required mark on the group (woocommerce_form_field
	 * puts the mark, or «(optional)», after every option).
	 */
	private static function type_field( string $value ): void {
		$label = IVD_Settings::text( 'choice' );
		echo '<p class="form-row form-row-wide ivd-type validate-required" id="ivd_type_field" role="radiogroup" aria-labelledby="ivd_type_label" aria-required="true">';
		echo '<label id="ivd_type_label">' . esc_html( $label ) . '&nbsp;<span class="required" aria-hidden="true">*</span></label>';
		echo '<span class="woocommerce-input-wrapper">';
		foreach ( array( 'receipt', 'invoice' ) as $k ) {
			echo '<input type="radio" class="input-radio" value="' . esc_attr( $k ) . '" name="ivd_type" id="ivd_type_' . esc_attr( $k ) . '" ' . checked( $value, $k, false ) . ' />';
			echo '<label for="ivd_type_' . esc_attr( $k ) . '" class="radio">' . esc_html( IVD_Settings::text( $k ) ) . '</label>';
		}
		echo '</span></p>';
	}

	/** One text field of the classic form. */
	private static function text_field( string $f, string $value, bool $required, string $class ): void {
		woocommerce_form_field(
			'ivd_' . $f,
			array(
				'type'              => 'text',
				'label'             => esc_html( self::label( $f ) ),
				'required'          => $required,
				'class'             => array_merge( explode( ' ', $class ), array( 'ivd-field' ) ),
				'custom_attributes' => array( 'maxlength' => (string) IVD_Data::MAX[ $f ] ),
			),
			$value
		);
	}

	/**
	 * woocommerce_after_checkout_validation.
	 *
	 * @param array    $data   Posted data.
	 * @param WP_Error $errors Errors.
	 */
	public static function validate( $data, $errors ): void {
		self::$check = null;
		if ( ! $errors instanceof WP_Error || ! self::offered() ) {
			return;
		}
		try {
			$p = self::posted();
			if ( null === $p ) {
				return;
			}
			if ( ! $p['invoice'] ) {
				if ( self::invoice_required() ) {
					$errors->add( 'ivd_type', esc_html( IVD_Settings::text( 'need_invoice' ) ), array( 'id' => 'ivd_type' ) );
				}
				return;
			}
			$err = self::errors( $p, $p['country'] );
			foreach ( $err as $f => $text ) {
				$id = ( 'company' === $f && ! self::own_company() ) ? 'billing_company' : 'ivd_' . $f;
				$errors->add( 'ivd_' . $f, esc_html( $text ), array( 'id' => $id ) );
			}
			if ( $err || $errors->has_errors() ) {
				return;
			}
			$d           = self::details( $p, $p['country'] );
			self::$check = self::run_check( $d );
			if ( 'invalid' === self::$check['status'] && 'vies' === self::$check['source'] && 'reject' === IVD_Settings::get()['vies_invalid'] ) {
				$errors->add( 'ivd_vat', esc_html( IVD_Settings::text( 'vies_bad' ) ), array( 'id' => 'ivd_vat' ) );
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * woocommerce_checkout_create_order: the details on the order (classic).
	 *
	 * @param WC_Order $order Order being created.
	 * @param array    $data  Posted data.
	 */
	public static function save_classic( $order, $data ): void {
		if ( ! $order instanceof WC_Order || ! self::offered() ) {
			return;
		}
		try {
			$p = self::posted();
			if ( null === $p ) {
				return;
			}
			if ( ! $p['invoice'] ) {
				IVD_Data::store( $order, null );
				IVD_Data::save_customer( (int) $order->get_customer_id(), null );
				return;
			}
			$d     = self::details( $p, $p['country'] );
			$check = is_array( self::$check ) ? self::$check : self::run_check( $d );
			IVD_Data::store( $order, $d, $check );
			IVD_Data::save_customer( (int) $order->get_customer_id(), $d );
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/* =====================================================================
	 * Checkout block (additional checkout fields)
	 * =================================================================== */

	/** JSON-schema condition (customer.additional_fields, the contact location): the invoice box is ticked (and the billing country is Greece). */
	private static function cond( bool $greek ): array {
		// Contact-location fields live in customer.additional_fields of the document.
		$customer = array(
			'additional_fields' => array(
				'properties' => array(
					IVD_Data::block_id( 'invoice' ) => array( 'const' => true ),
				),
				'required'   => array( IVD_Data::block_id( 'invoice' ) ),
			),
		);
		if ( $greek ) {
			$customer['billing_address'] = array(
				'properties' => array(
					'country' => array( 'const' => 'GR' ),
				),
			);
		}
		$props = array(
			'customer' => array(
				'properties' => $customer,
			),
		);
		return array(
			'type'       => 'object',
			'properties' => $props,
		);
	}

	/** woocommerce_init: the block fields. */
	public static function block_register(): void {
		try {
			if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) || ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '9.9', '<' ) ) {
				return;
			}
			if ( ! self::offered() ) {
				return;
			}
			woocommerce_register_additional_checkout_field(
				array(
					'id'                         => IVD_Data::block_id( 'invoice' ),
					'label'                      => IVD_Settings::text( 'want' ),
					'optionalLabel'              => IVD_Settings::text( 'want' ),
					'location'                   => 'contact',
					'type'                       => 'checkbox',
					'show_in_order_confirmation' => false,
				)
			);
			$fields = array();
			if ( self::own_company() ) {
				$fields['company'] = false;
			}
			$fields['vat'] = false;
			foreach ( array( 'tax_office', 'activity' ) as $f ) {
				if ( 'hidden' !== self::mode( $f ) ) {
					$fields[ $f ] = true;
				}
			}
			if ( 'hidden' !== self::mode( 'hq' ) ) {
				$fields['hq'] = false;
			}
			foreach ( $fields as $f => $greek ) {
				$cond     = self::cond( $greek );
				$required = 'hq' !== $f && 'required' === self::mode( $f );
				woocommerce_register_additional_checkout_field(
					array(
						'id'                         => IVD_Data::block_id( $f ),
						'label'                      => self::label( $f ),
						'optionalLabel'              => self::label( $f ),
						'location'                   => 'contact',
						'type'                       => 'text',
						'show_in_order_confirmation' => false,
						'attributes'                 => array( 'maxLength' => IVD_Data::MAX[ $f ] ),
						'required'                   => $required ? $cond : false,
						'hidden'                     => array(
							'type' => 'object',
							'not'  => $cond,
						),
						'sanitize_callback'          => static function ( $value ) use ( $f ) {
							return IVD_Data::clean( $f, $value );
						},
					)
				);
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/** The block values of a field list (field ids => values) in our names. */
	private static function block_values( callable $get ): array {
		$out = array( 'invoice' => in_array( $get( 'invoice' ), array( true, 1, '1', 'yes', 'true' ), true ) );
		foreach ( array( 'company', 'vat', 'tax_office', 'activity', 'hq' ) as $f ) {
			$out[ $f ] = IVD_Data::clean( $f, $get( $f ) );
		}
		return $out;
	}

	/**
	 * woocommerce_blocks_validate_location_contact_fields: the number checks
	 * (also run by My account › Account details).
	 *
	 * @param WP_Error $errors Errors.
	 * @param array    $fields Field id => value.
	 * @param string   $group  Group.
	 */
	public static function block_validate( $errors, $fields, $group ): void {
		if ( ! $errors instanceof WP_Error || ! is_array( $fields ) || self::$broken ) {
			return;
		}
		try {
			$v = self::block_values(
				static function ( $f ) use ( $fields ) {
					$id = IVD_Data::block_id( $f );
					return isset( $fields[ $id ] ) ? $fields[ $id ] : '';
				}
			);
			if ( ! $v['invoice'] ) {
				return;
			}
			if ( ! self::own_company() ) {
				// The company is WooCommerce's billing field: checked when the order is placed.
				$v['company'] = 'x';
			}
			$country = function_exists( 'WC' ) && WC()->customer ? (string) WC()->customer->get_billing_country() : '';
			$err     = self::errors( $v, $country );
			// Required fields are already enforced by the schema; only the number check is ours here.
			if ( isset( $err['vat'] ) && '' !== $v['vat'] ) {
				$errors->add( 'ivd_vat', $err['vat'] );
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * woocommerce_store_api_checkout_order_processed (before payment): the
	 * final checks and the copy to our meta.
	 *
	 * @param WC_Order $order Order.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException To stop the order.
	 */
	public static function save_block( $order ): void {
		if ( self::$broken || ! $order instanceof WC_Order || ! self::offered() ) {
			return;
		}
		$stop = '';
		try {
			$v = self::block_values(
				static function ( $f ) use ( $order ) {
					return $order->get_meta( IVD_Data::block_key( $f ), true );
				}
			);
			if ( ! $v['invoice'] ) {
				if ( self::invoice_required() ) {
					$stop = IVD_Settings::text( 'need_invoice' );
				} else {
					IVD_Data::store( $order, null );
					$order->save();
				}
			} else {
				if ( ! self::own_company() ) {
					$v['company'] = (string) $order->get_billing_company();
				}
				$billing = (string) $order->get_billing_country();
				$err     = self::errors( $v, $billing );
				if ( $err ) {
					$stop = (string) reset( $err );
				} else {
					$d     = self::details( $v, $billing );
					$check = self::run_check( $d );
					if ( 'invalid' === $check['status'] && 'vies' === $check['source'] && 'reject' === IVD_Settings::get()['vies_invalid'] ) {
						$stop = IVD_Settings::text( 'vies_bad' );
					} else {
						IVD_Data::store( $order, $d, $check );
						$order->save();
						self::note( $order, $check );
					}
				}
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
			return;
		}
		if ( '' !== $stop ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'ivd_invoice', esc_html( $stop ), 400 );
		}
	}

	/**
	 * An order note when the VIES check did not confirm the number.
	 *
	 * @param WC_Order $order Order (saved).
	 * @param array    $check Check result.
	 */
	private static function note( WC_Order $order, array $check ): void {
		if ( 'vies' !== ( $check['source'] ?? '' ) ) {
			return;
		}
		if ( 'unavailable' === $check['status'] ) {
			/* translators: %s: VIES error code */
			$order->add_order_note( sprintf( __( 'Invoice Details: το VIES δεν απάντησε (%s). Κάνε «Έλεγχος VIES» αργότερα από το κουτί «Στοιχεία τιμολογίου».', 'invoice-details' ), (string) $check['error'] ) );
		} elseif ( 'invalid' === $check['status'] ) {
			$order->add_order_note( __( 'Invoice Details: ο ΑΦΜ δεν βρέθηκε στο VIES. Η παραγγελία έγινε δεκτή· έλεγξε τον αριθμό πριν κόψεις τιμολόγιο.', 'invoice-details' ) );
		}
	}

	/**
	 * woocommerce_checkout_order_processed: the note of the classic checkout
	 * (the order has an ID only now).
	 *
	 * @param int      $order_id Order ID.
	 * @param array    $data     Posted data.
	 * @param WC_Order $order    Order.
	 */
	public static function note_classic( $order_id, $data = array(), $order = null ): void {
		try {
			$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
			if ( $order instanceof WC_Order && is_array( self::$check ) && 'invoice' === $order->get_meta( IVD_Data::META_TYPE, true ) ) {
				self::note( $order, self::$check );
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/* =====================================================================
	 * Assets (classic checkout only)
	 * =================================================================== */

	public static function assets(): void {
		if ( ! self::offered() || ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}
		$aade = class_exists( 'IVD_Aade' ) ? IVD_Aade::script_data() : null;
		$data = null !== $aade ? 'window.ivdAade = ' . wp_json_encode( $aade ) . ';' : '';
		if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' ) ) {
			if ( '' !== $data ) {
				wp_enqueue_script( 'ivd-block', IVD_URL . 'assets/block.js', array( 'wp-data', 'wc-blocks-data-store' ), IVD_VERSION, true );
				wp_add_inline_script( 'ivd-block', $data, 'before' );
			}
			return;
		}
		wp_enqueue_script( 'ivd-front', IVD_URL . 'assets/front.js', array(), IVD_VERSION, true );
		if ( '' !== $data ) {
			wp_add_inline_script( 'ivd-front', $data, 'before' );
		}
		wp_register_style( 'ivd-front', false, array(), IVD_VERSION );
		wp_enqueue_style( 'ivd-front' );
		wp_add_inline_style( 'ivd-front', '#ivd-box{margin:1em 0 0;clear:both}#ivd-box .ivd-type label.radio,#ivd-box .ivd-type input{display:inline-block;margin-right:.4em}#ivd-box .ivd-type label.radio{margin-right:1.2em}#ivd-box .ivd-fields::after{content:"";display:table;clear:both}#ivd-box .ivd-help{font-size:.9em;opacity:.85}' );
	}
}

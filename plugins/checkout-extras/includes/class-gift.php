<?php
/**
 * CKE_Gift — "This is a gift" at checkout and everything the order shows.
 *
 *  - Classic checkout: a checkbox (WooCommerce form field, so the theme
 *    styles it), an optional help text and an optional gift message with a
 *    character counter. Three positions: before the customer details, after
 *    the order notes, or before the "Place order" button. The values
 *    survive WooCommerce's update_order_review refreshes through the posted
 *    form (post_data), never through a cookie or the session (Bible §14.5,
 *    §14.21).
 *  - Fee: with g_fee > 0 a WooCommerce fee line ("Gift wrapping") is added in
 *    woocommerce_cart_calculate_fees, read from the posted form
 *    (update_order_review's post_data, or the checkout POST). Ticking the
 *    box refreshes the order review (small script). When shop prices
 *    include tax, the fee amount is entered including tax too.
 *  - Hidden when nothing in the cart ships (g_shippable_only).
 *  - Validation in woocommerce_after_checkout_validation (required message,
 *    length).
 *  - Saved through CRUD on the order: _cke_gift ('yes'), _cke_gift_message,
 *    _cke_gift_fee (the amount at the time of the order).
 *  - Shows on the order: a "Gift: Yes" row (+ message) in the order totals
 *    (thank-you page, My account, emails), a meta box on the admin order
 *    screen, a 🎁 column and a "Gifts only" filter in the orders list (HPOS
 *    and legacy), and a line at the top of the admin New order email.
 *  - Checkout block: a checkbox and a text field through WooCommerce's
 *    additional checkout fields (location "order"), copied to our meta in
 *    woocommerce_store_api_checkout_order_processed. The block cannot add a
 *    fee from a field without session writes, so with g_fee > 0 the option
 *    is not offered there (documented deviation).
 *
 * Every callback is wrapped in try/catch (Bible §14.6).
 */

defined( 'ABSPATH' ) || exit;

final class CKE_Gift {

	const META         = '_cke_gift';
	const META_MESSAGE = '_cke_gift_message';
	const META_FEE     = '_cke_gift_fee';

	/** Block checkout field ids (namespace/name). */
	const BLOCK_GIFT    = 'checkout-extras/gift';
	const BLOCK_MESSAGE = 'checkout-extras/gift-message';

	/** Fee line id prefix, so the fee is ours in any language. */
	const FEE_ID = 'cke-gift';

	/** Set after the first error: the part stays out for the rest of the request. */
	private static $broken = false;

	/** Front-end hooks (not registered with CKE_DISABLE). */
	public static function init_front(): void {
		$pos = array(
			'before_customer' => 'woocommerce_checkout_before_customer_details',
			'after_notes'     => 'woocommerce_after_order_notes',
			'before_submit'   => 'woocommerce_review_order_before_submit',
		);
		$at  = CKE_Settings::get()['g_position'];
		add_action( isset( $pos[ $at ] ) ? $pos[ $at ] : $pos['before_customer'], array( __CLASS__, 'render' ), 5 );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'add_fee' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_classic' ), 10, 2 );
		add_action( 'woocommerce_init', array( __CLASS__, 'block_register' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'save_block' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** Display hooks for orders that already carry the gift (front + admin). */
	public static function init_display(): void {
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'totals' ), 10, 2 );
		add_action( 'woocommerce_email_order_details', array( __CLASS__, 'admin_line' ), 5, 4 );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ), 30 );
			// Orders list, HPOS and legacy.
			add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'columns' ), 20 );
			add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'column_hpos' ), 10, 2 );
			add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'columns' ), 20 );
			add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'column_legacy' ), 10, 2 );
			add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( __CLASS__, 'filter_select' ) );
			add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( __CLASS__, 'filter_hpos' ) );
			add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_select_legacy' ) );
			add_action( 'pre_get_posts', array( __CLASS__, 'filter_legacy' ) );
		}
	}

	/** Records the error and switches the part off for this request. */
	private static function fail( \Throwable $e ): void {
		self::$broken = true;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only.
			error_log( 'Checkout Extras (gift): ' . $e->getMessage() );
		}
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	/** True when the gift option may show for the current cart. */
	private static function offered(): bool {
		if ( self::$broken || ! CKE_Settings::visible( 'g' ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		if ( CKE_Settings::get()['g_shippable_only'] && ! WC()->cart->needs_shipping() ) {
			return false;
		}
		return true;
	}

	/**
	 * The posted gift values: from the checkout POST or the AJAX refresh's
	 * post_data. WooCommerce verifies its own nonces for both.
	 *
	 * @return array { gift: bool, message: string }
	 */
	private static function posted(): array {
		$out = array(
			'gift'    => false,
			'message' => '',
		);
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its checkout / update_order_review nonces; we only read two whitelisted fields.
		$src = null;
		if ( isset( $_POST['cke_gift_present'] ) ) {
			$src = $_POST;
		} elseif ( isset( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) {
			$data = array();
			wp_parse_str( wp_unslash( $_POST['post_data'] ), $data ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only our two fields are read, sanitized below.
			$src = $data;
			if ( isset( $src['cke_gift_message'] ) && is_string( $src['cke_gift_message'] ) ) {
				// wp_parse_str() already unslashed; keep the shape of $_POST.
				$src['cke_gift_message'] = wp_slash( $src['cke_gift_message'] );
			}
		}
		if ( is_array( $src ) ) {
			$out['gift'] = isset( $src['cke_gift'] ) && '1' === (string) ( is_scalar( $src['cke_gift'] ) ? $src['cke_gift'] : '' );
			if ( isset( $src['cke_gift_message'] ) && is_string( $src['cke_gift_message'] ) ) {
				$out['message'] = self::clean_message( wp_unslash( $src['cke_gift_message'] ) );
			}
		}
		// phpcs:enable
		return $out;
	}

	/** Plain text with line breaks (at most 10 lines); length is checked by validate(), not cut. */
	public static function clean_message( string $v ): string {
		$rows = array();
		foreach ( preg_split( '/\R/u', $v ) as $row ) {
			$row = trim( sanitize_text_field( $row ) );
			if ( '' !== $row ) {
				$rows[] = $row;
			}
			if ( count( $rows ) >= 10 ) {
				break;
			}
		}
		return implode( "\n", $rows );
	}

	/** True when the message part is on. */
	private static function message_on(): bool {
		return 'off' !== CKE_Settings::get()['g_msg'];
	}

	/* =====================================================================
	 * Classic checkout
	 * =================================================================== */

	/** The checkbox, help text and message, at the chosen checkout position. */
	public static function render(): void {
		if ( ! self::offered() ) {
			return;
		}
		try {
			$s      = CKE_Settings::get();
			$posted = self::posted();
			$fee    = CKE_Settings::fee();
			$label  = CKE_Settings::text( 'g_label' );
			if ( $fee > 0 ) {
				$label .= ' (+' . html_entity_decode( wp_strip_all_tags( wc_price( $fee ) ), ENT_QUOTES, 'UTF-8' ) . ')';
			}

			echo '<div class="cke-gift" id="cke-gift" data-fee="' . ( $fee > 0 ? '1' : '0' ) . '">';
			echo '<input type="hidden" name="cke_gift_present" value="1" />';
			woocommerce_form_field(
				'cke_gift',
				array(
					'type'  => 'checkbox',
					'class' => array( 'form-row-wide', 'cke-gift__check' ),
					'label' => esc_html( $label ),
				),
				$posted['gift'] ? '1' : ''
			);
			$help = CKE_Settings::text( 'g_help' );
			if ( '' !== $help ) {
				echo '<p class="cke-gift__help">' . nl2br( esc_html( $help ), false ) . '</p>';
			}
			if ( self::message_on() ) {
				echo '<div class="cke-gift__more">';
				woocommerce_form_field(
					'cke_gift_message',
					array(
						'type'              => 'textarea',
						'class'             => array( 'form-row-wide', 'cke-gift__message' ),
						'label'             => esc_html( CKE_Settings::text( 'g_msg_label' ) ),
						'required'          => 'required' === $s['g_msg'],
						'custom_attributes' => array(
							'maxlength' => (string) $s['g_max'],
							'rows'      => '3',
						),
					),
					$posted['message']
				);
				echo '<p class="cke-gift__count" aria-live="polite"><span class="cke-gift__used">' . (int) mb_strlen( $posted['message'] ) . '</span> / ' . (int) $s['g_max'] . '</p>';
				echo '</div>';
			}
			echo '</div>';
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * woocommerce_cart_calculate_fees: the gift fee, when ticked in the
	 * posted checkout form. Store API requests never get it (the block has
	 * no fee, see the class docblock).
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function add_fee( $cart ): void {
		if ( ! $cart instanceof WC_Cart || CKE_Settings::fee() <= 0 || Checkout_Extras::is_rest() || ! self::offered() ) {
			return;
		}
		try {
			if ( ! self::posted()['gift'] ) {
				return;
			}
			$s       = CKE_Settings::get();
			$amount  = CKE_Settings::fee();
			$taxable = 'taxable' === $s['g_tax'] && wc_tax_enabled();
			if ( $taxable && wc_prices_include_tax() ) {
				// Fees are always net in WooCommerce: take the tax out, so the
				// customer pays the amount the shop typed.
				$rates  = WC_Tax::get_rates( $s['g_tax_class'], WC()->customer );
				$amount = $amount - array_sum( WC_Tax::calc_inclusive_tax( $amount, $rates ) );
			}
			$cart->fees_api()->add_fee(
				array(
					'id'        => self::FEE_ID,
					'name'      => CKE_Settings::text( 'g_fee_name' ),
					'amount'    => (float) $amount,
					'taxable'   => $taxable,
					'tax_class' => $s['g_tax_class'],
				)
			);
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * woocommerce_after_checkout_validation: required message and its length.
	 *
	 * @param array    $data   Posted data.
	 * @param WP_Error $errors Errors.
	 */
	public static function validate( $data, $errors ): void {
		if ( ! $errors instanceof WP_Error || ! self::offered() || ! self::message_on() ) {
			return;
		}
		try {
			$p = self::posted();
			if ( ! $p['gift'] ) {
				return;
			}
			$s = CKE_Settings::get();
			if ( 'required' === $s['g_msg'] && '' === $p['message'] ) {
				$errors->add( 'cke_gift_message', CKE_Settings::text( 'g_msg_required' ), array( 'id' => 'cke_gift_message' ) );
			} elseif ( mb_strlen( $p['message'] ) > $s['g_max'] ) {
				$errors->add( 'cke_gift_message', CKE_Settings::fill( CKE_Settings::text( 'g_msg_long' ), array( 'max' => (string) $s['g_max'] ) ), array( 'id' => 'cke_gift_message' ) );
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * woocommerce_checkout_create_order: the gift on the order (classic).
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
			if ( $p['gift'] ) {
				self::store( $order, self::message_on() ? $p['message'] : '', CKE_Settings::fee() );
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * Writes the gift meta on an order (no save: the caller saves).
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $message Gift message.
	 * @param float    $fee     Fee at the time of the order.
	 */
	private static function store( WC_Order $order, string $message, float $fee ): void {
		$order->update_meta_data( self::META, 'yes' );
		if ( '' !== $message ) {
			$order->update_meta_data( self::META_MESSAGE, mb_substr( $message, 0, 1000 ) );
		}
		$order->update_meta_data( self::META_FEE, wc_format_decimal( $fee ) );
	}

	/* =====================================================================
	 * Checkout block (additional checkout fields)
	 * =================================================================== */

	/** woocommerce_init: the block fields, only when no fee applies. */
	public static function block_register(): void {
		try {
			if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) || ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
				return;
			}
			if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '9.9', '<' ) || CKE_Settings::fee() > 0 || ! CKE_Settings::visible( 'g' ) ) {
				return;
			}
			$s = CKE_Settings::get();

			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
					'namespace'       => 'checkout-extras',
					'data_callback'   => static function () use ( $s ) {
						$ships = function_exists( 'WC' ) && WC()->cart ? WC()->cart->needs_shipping() : false;
						return array( 'gift' => $ships || ! $s['g_shippable_only'] );
					},
					'schema_callback' => static function () {
						return array(
							'gift' => array(
								'description' => 'The gift option is offered for this cart.',
								'type'        => 'boolean',
								'readonly'    => true,
							),
						);
					},
					'schema_type'     => ARRAY_A,
				)
			);

			$offered = array(
				'cart' => array(
					'properties' => array(
						'extensions' => array(
							'properties' => array(
								'checkout-extras' => array(
									'properties' => array(
										'gift' => array( 'const' => true ),
									),
									'required'   => array( 'gift' ),
								),
							),
							'required'   => array( 'checkout-extras' ),
						),
					),
				),
			);

			woocommerce_register_additional_checkout_field(
				array(
					'id'                         => self::BLOCK_GIFT,
					'label'                      => CKE_Settings::text( 'g_label' ),
					'location'                   => 'order',
					'type'                       => 'checkbox',
					'show_in_order_confirmation' => false,
					'hidden'                     => array(
						'type' => 'object',
						'not'  => array(
							'type'       => 'object',
							'properties' => $offered,
						),
					),
				)
			);

			if ( ! self::message_on() ) {
				return;
			}
			$ticked = array(
				'checkout' => array(
					'properties' => array(
						'additional_fields' => array(
							'properties' => array(
								self::BLOCK_GIFT => array( 'const' => true ),
							),
							'required'   => array( self::BLOCK_GIFT ),
						),
					),
				),
			);
			$max    = (int) $s['g_max'];
			woocommerce_register_additional_checkout_field(
				array(
					'id'                         => self::BLOCK_MESSAGE,
					'label'                      => CKE_Settings::text( 'g_msg_label' ),
					'location'                   => 'order',
					'type'                       => 'text',
					'show_in_order_confirmation' => false,
					'attributes'                 => array( 'maxLength' => $max ),
					'required'                   => 'required' === $s['g_msg'] ? array(
						'type'       => 'object',
						'properties' => $ticked,
					) : false,
					'hidden'                     => array(
						'type' => 'object',
						'not'  => array(
							'type'       => 'object',
							'properties' => $ticked,
						),
					),
					'sanitize_callback'          => static function ( $value ) {
						return is_string( $value ) ? self::clean_message( $value ) : '';
					},
					'validate_callback'          => static function ( $value ) use ( $max ) {
						if ( is_string( $value ) && mb_strlen( $value ) > $max ) {
							return new WP_Error( 'cke_gift_message', CKE_Settings::fill( CKE_Settings::text( 'g_msg_long' ), array( 'max' => (string) $max ) ) );
						}
						return true;
					},
				)
			);
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * woocommerce_store_api_checkout_order_processed: copies the block
	 * fields to our meta, so the admin, emails and the API read one place.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function save_block( $order ): void {
		if ( self::$broken || ! $order instanceof WC_Order ) {
			return;
		}
		try {
			$gift = $order->get_meta( '_wc_other/' . self::BLOCK_GIFT, true );
			if ( ! in_array( $gift, array( true, 1, '1', 'yes', 'true' ), true ) ) {
				return;
			}
			$message = (string) $order->get_meta( '_wc_other/' . self::BLOCK_MESSAGE, true );
			self::store( $order, self::message_on() ? self::clean_message( $message ) : '', 0.0 );
			$order->save();
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/* =====================================================================
	 * Reading the gift of an order
	 * =================================================================== */

	/**
	 * The gift of an order (public API cke_get_gift()), or null.
	 *
	 * @param int|WC_Order $order Order or order ID.
	 * @return array|null { gift: true, message: string, fee: float }
	 */
	public static function order_gift( $order ): ?array {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof WC_Order || 'yes' !== $order->get_meta( self::META, true ) ) {
			return null;
		}
		return array(
			'gift'    => true,
			'message' => (string) $order->get_meta( self::META_MESSAGE, true ),
			'fee'     => (float) $order->get_meta( self::META_FEE, true ),
		);
	}

	/**
	 * woocommerce_get_order_item_totals: "Gift: Yes" (+ the message) before
	 * the order total (thank-you page, My account, emails).
	 *
	 * @param array    $rows  Totals rows.
	 * @param WC_Order $order Order.
	 */
	public static function totals( $rows, $order ) {
		if ( self::$broken || ! is_array( $rows ) || ! $order instanceof WC_Order ) {
			return $rows;
		}
		try {
			$gift = self::order_gift( $order );
			if ( null === $gift ) {
				return $rows;
			}
			$value = esc_html( CKE_Settings::text( 'g_yes' ) );
			if ( '' !== $gift['message'] ) {
				$value .= '<br /><em class="cke-gift-message">' . nl2br( esc_html( $gift['message'] ), false ) . '</em>';
			}
			$row = array(
				'cke_gift' => array(
					'label' => esc_html( CKE_Settings::text( 'g_row' ) ) . ':',
					'value' => $value,
				),
			);
			$at  = array_search( 'order_total', array_keys( $rows ), true );
			if ( false === $at ) {
				return $rows + $row;
			}
			return array_slice( $rows, 0, $at, true ) + $row + array_slice( $rows, $at, null, true );
		} catch ( \Throwable $e ) {
			self::fail( $e );
			return $rows;
		}
	}

	/**
	 * woocommerce_email_order_details (priority 5): "🎁 Gift" (+ message) at
	 * the top of the admin New order email.
	 *
	 * @param WC_Order      $order         Order.
	 * @param bool          $sent_to_admin Sent to the admin.
	 * @param bool          $plain_text    Plain text email.
	 * @param WC_Email|null $email         Email.
	 */
	public static function admin_line( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( self::$broken || ! $sent_to_admin || ! $order instanceof WC_Order || ! CKE_Settings::get()['g_admin_line'] ) {
			return;
		}
		if ( ! is_object( $email ) || ! isset( $email->id ) || 'new_order' !== $email->id ) {
			return;
		}
		try {
			$gift = self::order_gift( $order );
			if ( null === $gift ) {
				return;
			}
			$line = '🎁 ' . CKE_Settings::text( 'g_row' );
			if ( $plain_text ) {
				echo esc_html( $line ) . "\n";
				if ( '' !== $gift['message'] ) {
					echo esc_html( CKE_Settings::text( 'g_msg_label' ) . ': ' . str_replace( "\n", ' / ', $gift['message'] ) ) . "\n";
				}
				echo "\n";
			} else {
				echo '<p class="cke-admin-line" style="font-size:1.15em;margin:0 0 16px;"><strong>' . esc_html( $line ) . '</strong>';
				if ( '' !== $gift['message'] ) {
					echo '<br />' . esc_html( CKE_Settings::text( 'g_msg_label' ) ) . ': <em>' . nl2br( esc_html( $gift['message'] ), false ) . '</em>';
				}
				echo '</p>';
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/* =====================================================================
	 * Admin: meta box, orders list
	 * =================================================================== */

	/** add_meta_boxes: "Gift" on the order screen, only for gift orders. */
	public static function meta_box(): void {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'cke-gift', __( 'Δώρο', 'checkout-extras' ), array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'high' );
	}

	/**
	 * Meta box body.
	 *
	 * @param WP_Post|WC_Order $object Post (legacy) or order (HPOS).
	 */
	public static function render_meta_box( $object ): void {
		$order = $object instanceof WC_Order ? $object : wc_get_order( $object instanceof WP_Post ? $object->ID : 0 );
		$gift  = $order instanceof WC_Order ? self::order_gift( $order ) : null;
		if ( null === $gift ) {
			echo '<p class="description">' . esc_html__( 'Η παραγγελία δεν είναι δώρο.', 'checkout-extras' ) . '</p>';
			return;
		}
		echo '<p><strong>🎁 ' . esc_html__( 'Ο πελάτης σημείωσε ότι είναι δώρο.', 'checkout-extras' ) . '</strong></p>';
		if ( '' !== $gift['message'] ) {
			echo '<p>' . esc_html__( 'Αφιέρωση:', 'checkout-extras' ) . '</p>';
			echo '<blockquote style="margin:0 0 8px;padding:6px 10px;border-left:3px solid #6d4aff;background:#f6f4ff;white-space:pre-wrap;">' . esc_html( $gift['message'] ) . '</blockquote>';
		}
		if ( $gift['fee'] > 0 ) {
			echo '<p>' . esc_html__( 'Χρέωση συσκευασίας:', 'checkout-extras' ) . ' ' . wp_kses_post( wc_price( $gift['fee'], array( 'currency' => $order->get_currency() ) ) ) . '</p>';
		}
	}

	/** Orders list: a narrow 🎁 column after the order number. */
	public static function columns( $cols ) {
		if ( ! is_array( $cols ) || ! CKE_Settings::get()['g_column'] ) {
			return $cols;
		}
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'order_number' === $k ) {
				$out['cke_gift'] = '<span title="' . esc_attr__( 'Δώρο', 'checkout-extras' ) . '">🎁</span>';
			}
		}
		if ( ! isset( $out['cke_gift'] ) ) {
			$out['cke_gift'] = '<span title="' . esc_attr__( 'Δώρο', 'checkout-extras' ) . '">🎁</span>';
		}
		return $out;
	}

	/** HPOS column cell. */
	public static function column_hpos( $column, $order ): void {
		if ( 'cke_gift' === $column && $order instanceof WC_Order ) {
			self::cell( $order );
		}
	}

	/** Legacy column cell. */
	public static function column_legacy( $column, $post_id ): void {
		if ( 'cke_gift' === $column ) {
			$order = wc_get_order( (int) $post_id );
			if ( $order instanceof WC_Order ) {
				self::cell( $order );
			}
		}
	}

	private static function cell( WC_Order $order ): void {
		$gift = self::order_gift( $order );
		if ( null !== $gift ) {
			$title = '' !== $gift['message'] ? $gift['message'] : __( 'Δώρο', 'checkout-extras' );
			echo '<span title="' . esc_attr( $title ) . '">🎁</span>';
		}
	}

	/** True when the "Gifts only" filter is on (read-only list filter). */
	private static function filter_on(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		return isset( $_GET['cke_gift'] ) && '1' === sanitize_key( wp_unslash( (string) $_GET['cke_gift'] ) );
	}

	/** The "Gifts only" select (HPOS orders list). */
	public static function filter_select(): void {
		echo '<select name="cke_gift"><option value="">' . esc_html__( 'Όλες οι παραγγελίες', 'checkout-extras' ) . '</option><option value="1" ' . selected( self::filter_on(), true, false ) . '>' . esc_html__( 'Μόνο δώρα', 'checkout-extras' ) . '</option></select>';
	}

	/** The "Gifts only" select (legacy orders list). */
	public static function filter_select_legacy( $post_type ): void {
		if ( 'shop_order' === $post_type ) {
			self::filter_select();
		}
	}

	/** HPOS list query: gift orders only. */
	public static function filter_hpos( $args ) {
		if ( is_array( $args ) && self::filter_on() ) {
			$args['meta_query']   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
			$args['meta_query'][] = array(
				'key'   => self::META,
				'value' => 'yes',
			);
		}
		return $args;
	}

	/** Legacy list query: gift orders only. */
	public static function filter_legacy( $query ): void {
		if ( ! $query instanceof WP_Query || ! $query->is_main_query() || ! is_admin() || 'shop_order' !== $query->get( 'post_type' ) || ! self::filter_on() ) {
			return;
		}
		$mq   = (array) $query->get( 'meta_query' );
		$mq[] = array(
			'key'   => self::META,
			'value' => 'yes',
		);
		$query->set( 'meta_query', $mq );
	}

	/* =====================================================================
	 * Assets (classic checkout only, when the option can show)
	 * =================================================================== */

	public static function assets(): void {
		if ( self::$broken || ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || ! CKE_Settings::visible( 'g' ) ) {
			return;
		}
		try {
			wp_register_style( 'cke-gift', false, array(), CKE_VERSION );
			wp_enqueue_style( 'cke-gift' );
			wp_add_inline_style( 'cke-gift', self::css() );
			wp_enqueue_script( 'cke-front', CKE_URL . 'assets/front.js', array( 'jquery' ), CKE_VERSION, true );
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/** Scoped CSS that survives theme rules (Bible §14.13); form fields keep the theme's look. */
	private static function css(): string {
		return '.cke-gift{display:block!important;margin:0 0 1.2em!important;padding:0!important;border:0!important;background:none!important;float:none!important;clear:both}'
			. '.cke-gift .cke-gift__help{margin:-.4em 0 .8em!important;font-size:.9em!important;opacity:.85;text-transform:none!important;letter-spacing:normal!important}'
			. '.cke-gift .cke-gift__count{margin:-.6em 0 0!important;font-size:.85em!important;opacity:.7;text-align:end!important}'
			. '.cke-gift.cke-js .cke-gift__more[hidden]{display:none!important}'
			// Id rules of the theme (e.g. #content p{display:flex}) beat classes: the layout of our rows goes through the id.
			. '#cke-gift p{display:block!important;float:none!important;width:auto!important}'
			. '#cke-gift .cke-gift__count{text-transform:none!important;letter-spacing:normal!important}'
			. '#cke-gift textarea{display:block!important;width:100%!important;max-width:100%!important;height:auto!important;min-height:4.5em!important}'
			. '#cke-gift input[type=checkbox]{width:auto!important;height:auto!important;display:inline-block!important}';
	}
}

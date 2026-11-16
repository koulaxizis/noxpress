<?php
/**
 * GFC_Blocks — the Cart and Checkout blocks (approved E12).
 *
 *  - Store API cart data (namespace gift-cards, read only, Bible §14.4):
 *    the applied cards with masked code, amount and balance after.
 *  - Store API update callback (namespace gift-cards, actions apply /
 *    remove), called by the block script with extensionCartUpdate(), which
 *    sends the Store API nonce. Same rules as the classic field
 *    (GFC_Redeem::apply_code: limits before the lookup, no code stored).
 *  - The script adds the field and the rows to the order summary
 *    (ExperimentalDiscountsMeta slot); it loads only on pages with the
 *    Cart or Checkout block.
 */

defined( 'ABSPATH' ) || exit;

final class GFC_Blocks {

	const NS = 'gift-cards';

	public static function init(): void {
		// WooCommerce Blocks loads before plugins_loaded 20 (where this runs).
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register' ) );
		}
		if ( ! Gift_Cards::killed() ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		}
	}

	public static function register(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => self::NS,
				'data_callback'   => array( __CLASS__, 'cart_data' ),
				'schema_callback' => array( __CLASS__, 'cart_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) && ! Gift_Cards::killed() ) {
			woocommerce_store_api_register_update_callback(
				array(
					'namespace' => self::NS,
					'callback'  => array( __CLASS__, 'update' ),
				)
			);
		}
	}

	public static function cart_schema(): array {
		return array(
			'cards'   => array(
				'description' => 'Applied gift cards',
				'type'        => 'array',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer' ),
						'label'   => array( 'type' => 'string' ),
						'amount'  => array( 'type' => 'string' ),
						'balance' => array( 'type' => 'string' ),
					),
				),
			),
			'enabled' => array(
				'description' => 'The field is available',
				'type'        => 'boolean',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}

	public static function cart_data(): array {
		$out = array(
			'cards'   => array(),
			'enabled' => GFC_Settings::can_redeem_now(),
		);
		try {
			$uses = GFC_Redeem::uses();
			foreach ( GFC_Redeem::applied() as $id ) {
				$card = GFC_Store::get( $id );
				if ( ! $card ) {
					continue;
				}
				$use            = (int) ( $uses[ $id ] ?? 0 );
				$out['cards'][] = array(
					'id'      => $id,
					'label'   => wp_strip_all_tags( GFC_Settings::fill( 'row', array( 'card' => GFC_Codes::mask( $card['last4'] ) ) ) ),
					'amount'  => '−' . GFC_Settings::money( $use, $card['currency'] ),
					'balance' => wp_strip_all_tags( GFC_Settings::fill( 'row_left', array( 'balance' => GFC_Settings::money( $card['balance'] - $use, $card['currency'] ) ) ) ),
				);
			}
		} catch ( \Throwable $e ) {
			$out['cards'] = array();
		}
		return $out;
	}

	/**
	 * extensionCartUpdate( { namespace: 'gift-cards', data: { action, code | card } } ).
	 *
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException On a refused code.
	 */
	public static function update( $data ): void {
		$data   = is_array( $data ) ? $data : array();
		$action = isset( $data['action'] ) && is_string( $data['action'] ) ? $data['action'] : '';
		if ( 'remove' === $action ) {
			GFC_Redeem::remove_card( isset( $data['card'] ) && is_numeric( $data['card'] ) ? absint( $data['card'] ) : 0 );
			return;
		}
		if ( 'apply' !== $action ) {
			return;
		}
		$code             = isset( $data['code'] ) && is_string( $data['code'] ) ? sanitize_text_field( $data['code'] ) : '';
		list( $ok, $msg ) = GFC_Redeem::apply_code( $code );
		if ( ! $ok ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'gfc_refused', esc_html( wp_strip_all_tags( $msg ) ), 400 );
		}
	}

	public static function assets(): void {
		if ( ! function_exists( 'is_cart' ) || ! ( is_cart() || is_checkout() ) || is_order_received_page() ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! ( has_block( 'woocommerce/cart', $post ) || has_block( 'woocommerce/checkout', $post ) ) ) {
			return;
		}
		if ( ! GFC_Settings::can_redeem_now() ) {
			return;
		}
		wp_enqueue_script( 'gfc-blocks', GFC_URL . 'assets/blocks.js', array( 'wp-plugins', 'wp-element', 'wp-data', 'wc-blocks-checkout' ), GFC_VERSION, true );
		wp_add_inline_script(
			'gfc-blocks',
			'window.gfcBlocks = ' . wp_json_encode(
				array(
					'title'       => GFC_Settings::text( 'have_card' ),
					'placeholder' => GFC_Settings::text( 'placeholder' ),
					'apply'       => GFC_Settings::text( 'apply' ),
					'remove'      => GFC_Settings::text( 'remove' ),
					'applied'     => wp_strip_all_tags( GFC_Settings::fill( 'removed' ) ),
				)
			) . ';',
			'before'
		);
		wp_add_inline_style( 'wc-blocks-style', '.gfc-b{margin:0 0 12px}.gfc-b__line{display:flex;gap:8px}.gfc-b__line input{flex:1;min-width:0;text-transform:uppercase;padding:8px}.gfc-b__msg{font-size:.9em;margin:6px 0 0}.gfc-b__msg.is-error{color:#cc1818}.gfc-b__row{display:flex;justify-content:space-between;gap:8px;margin:6px 0 0}.gfc-b__row small{display:block;opacity:.75}.gfc-b__x{background:none;border:0;padding:0;text-decoration:underline;cursor:pointer;font-size:.85em}' );
	}
}

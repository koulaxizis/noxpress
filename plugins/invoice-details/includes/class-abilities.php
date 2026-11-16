<?php
/**
 * IVD_Abilities — Invoice Details in the WordPress Abilities API (read
 * only), for diagnosis from a remote tool (e.g. Site Bridge's ability_run),
 * with the capability of the settings (manage_woocommerce):
 *
 *  invoice-details/get-order   the invoice details of one order (type,
 *                              company, VAT number, country, ΔΟΥ, activity,
 *                              head office, last check, source). Changes
 *                              nothing and never calls VIES.
 *
 * Registered only on WordPress 6.9+, where the Abilities API exists.
 */

defined( 'ABSPATH' ) || exit;

final class IVD_Abilities {

	const CAT = 'noxpress';

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	/** The shared "noxpress" category, once for every Noxpress plugin. */
	public static function category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CAT ) ) {
			return;
		}
		wp_register_ability_category(
			self::CAT,
			array(
				'label'       => 'Noxpress',
				'description' => 'Operations of the Noxpress plugins.',
			)
		);
	}

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			'invoice-details/get-order',
			array(
				'label'               => 'Invoice Details: order',
				'description'         => 'The receipt / invoice choice of one order and, for an invoice, the company name, VAT number (without prefix) and its country, tax office (ΔΟΥ), business activity, head-office address, the last number check (local check digit or VIES, with time) and where the details come from (ivd, or the old Timologia / OBS meta). Changes nothing and never calls VIES.',
				'category'            => self::CAT,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'order_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'order_id' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'get_order' ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => false,
				),
			)
		);
	}

	public static function allowed(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * The ability: one order's details.
	 *
	 * @param array|null $in Input.
	 * @return array|WP_Error
	 */
	public static function get_order( $in = null ) {
		$id    = is_array( $in ) && isset( $in['order_id'] ) ? absint( $in['order_id'] ) : 0;
		$order = $id ? wc_get_order( $id ) : null;
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'ivd_no_order', 'No order with this id.' );
		}
		$d = IVD_Data::get( $order );
		return array(
			'order_id' => $id,
			'type'     => null === $d ? 'receipt' : 'invoice',
			'details'  => $d,
		);
	}
}

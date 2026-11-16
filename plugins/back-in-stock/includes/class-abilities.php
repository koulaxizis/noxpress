<?php
/**
 * BIS_Abilities — Back in Stock in the WordPress Abilities API, with the
 * same capability as the settings (manage_woocommerce):
 *
 *  back-in-stock/demand  mode, whether the e-mails are on, totals and the
 *                        products with the most waiting requests (active,
 *                        pending, sent in 30 days, in stock now). Read only:
 *                        no e-mail address is ever returned.
 *
 * Registered only on WordPress 6.9+, where the Abilities API exists.
 */

defined( 'ABSPATH' ) || exit;

final class BIS_Abilities {

	const CAT = 'noxpress';

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

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
			'back-in-stock/demand',
			array(
				'label'               => 'Back in Stock: demand',
				'description'         => 'Back-in-stock requests: mode (off / test / live), whether the two WooCommerce e-mails are on, totals (active, pending confirmation, sent in the last 30 days) and up to `limit` products or variations with the most waiting requests, each with its name, SKU, stock status and counts. No e-mail address is returned. Changes nothing.',
				'category'            => self::CAT,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'limit' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => array( __CLASS__, 'demand' ),
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
		return current_user_can( Back_In_Stock::cap() );
	}

	public static function demand( $in = null ): array {
		$limit = is_array( $in ) && isset( $in['limit'] ) ? max( 1, min( 100, (int) $in['limit'] ) ) : 20;
		$items = array();
		foreach ( BIS_Store::demand( $limit ) as $d ) {
			$p       = wc_get_product( $d['variation_id'] ? $d['variation_id'] : $d['product_id'] );
			$items[] = array(
				'product_id'   => $d['product_id'],
				'variation_id' => $d['variation_id'],
				'name'         => $p ? wp_strip_all_tags( $p->get_name() ) : '',
				'sku'          => $p ? (string) $p->get_sku() : '',
				'stock_status' => $p ? (string) $p->get_stock_status() : '',
				'active'       => $d['active'],
				'pending'      => $d['pending'],
				'sent_30_days' => $d['sent30'],
			);
		}
		return array(
			'version'         => BIS_VERSION,
			'mode'            => BIS_Settings::mode(),
			'disabled'        => Back_In_Stock::killed(),
			'restock_email'   => BIS_Emails::enabled( BIS_Emails::RESTOCK ),
			'confirm_email'   => BIS_Emails::enabled( BIS_Emails::CONFIRM ),
			'totals'          => BIS_Store::totals(),
			'products'        => $items,
		);
	}
}

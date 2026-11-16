<?php
/**
 * DSR_Abilities — Discount Rules in the WordPress Abilities API, read
 * only (approved security model Σ10: no ability changes a rule or a
 * price), with the same capability as the plugin page:
 *
 *  discount-rules/rules    every rule: id, name, type, status now, dates,
 *                          discount, scope (ids), who, cart minimum
 *  discount-rules/preview  what a cart of given products and quantities
 *                          would get now (the same calculation as the
 *                          cart and the «Δοκιμή καλαθιού» tab), as a guest
 *                          or as a customer; nothing is stored
 *
 * Registered only on WordPress 6.9+, where the Abilities API exists.
 */

defined( 'ABSPATH' ) || exit;

final class DSR_Abilities {

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
		$perm = static function () {
			return current_user_can( 'manage_woocommerce' );
		};
		wp_register_ability(
			'discount-rules/rules',
			array(
				'label'               => 'Discount Rules: rules',
				'description'         => 'Every discount rule: id, name, type (sale, tiers, xy = buy X pay Y, cart), status now (active, scheduled, expired, off), start and end (local time), the discount, which products (all, product ids, category, tag and brand ids, exclusions), who (all, guests, customers) and the cart minimum; plus the mode and the order cap. Changes nothing.',
				'category'            => self::CAT,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => array( __CLASS__, 'rules' ),
				'permission_callback' => $perm,
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
		wp_register_ability(
			'discount-rules/preview',
			array(
				'label'               => 'Discount Rules: preview a cart',
				'description'         => 'What a cart would get now from the rules, with the same calculation as the real cart: items [ { product_id, qty } ] (at most 20; variations by their own id), customer = true for a logged-in customer (default: a guest). Returns each line with its rule, price before and after, the cart discount, whether the order cap cut it, and the hints. Nothing is stored and no cart is touched.',
				'category'            => self::CAT,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'items'    => array(
							'type'     => 'array',
							'maxItems' => 20,
							'items'    => array(
								'type'                 => 'object',
								'properties'           => array(
									'product_id' => array(
										'type'    => 'integer',
										'minimum' => 1,
									),
									'qty'        => array(
										'type'    => 'integer',
										'minimum' => 1,
										'maximum' => 9999,
									),
								),
								'required'             => array( 'product_id', 'qty' ),
								'additionalProperties' => false,
							),
						),
						'customer' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'items' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'preview' ),
				'permission_callback' => $perm,
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	public static function rules(): array {
		$out = array();
		foreach ( DSR_Rules::all() as $r ) {
			$out[] = array(
				'id'          => $r['id'],
				'name'        => $r['name'],
				'type'        => $r['type'],
				'status'      => DSR_Rules::status( $r ),
				'from'        => $r['from'],
				'to'          => $r['to'],
				'kind'        => $r['kind'],
				'amount'      => $r['amount'],
				'tiers'       => $r['tiers'],
				'buy'         => 'xy' === $r['type'] ? $r['buy'] : null,
				'pay'         => 'xy' === $r['type'] ? $r['pay'] : null,
				'count'       => $r['count'],
				'who'         => $r['who'],
				'min'         => $r['min'],
				'all'         => 'yes' === $r['all'],
				'products'    => $r['products'],
				'cats'        => $r['cats'],
				'tags'        => $r['tags'],
				'brands'      => $r['brands'],
				'ex_products' => $r['ex_products'],
				'ex_cats'     => $r['ex_cats'],
			);
		}
		$s = DSR_Settings::get();
		return array(
			'mode'  => Discount_Rules::killed() ? 'disabled' : $s['mode'],
			'cap'   => $s['cap'],
			'rules' => $out,
		);
	}

	/**
	 * @param mixed $input Input.
	 */
	public static function preview( $input = array() ): array {
		$input = is_array( $input ) ? $input : array();
		$items = isset( $input['items'] ) && is_array( $input['items'] ) ? array_slice( $input['items'], 0, 20 ) : array();
		return self::run( $items, ! empty( $input['customer'] ), time() );
	}

	/**
	 * The calculation for a list of products, for the abilities and the
	 * test cart of the admin.
	 *
	 * @param array $items    [ { product_id, qty } ].
	 * @param bool  $customer Logged-in customer.
	 * @param int   $ts       Time.
	 */
	public static function run( array $items, bool $customer, int $ts ): array {
		$lines    = array();
		$subtotal = 0.0;
		$n        = 0;
		foreach ( $items as $it ) {
			if ( ! is_array( $it ) || ! isset( $it['product_id'], $it['qty'] ) ) {
				continue;
			}
			$p   = wc_get_product( (int) $it['product_id'] );
			$qty = max( 0, min( 9999, (int) $it['qty'] ) );
			if ( ! $p instanceof WC_Product || $qty <= 0 || ! $p->is_purchasable() || $p->is_type( 'variable' ) || $p->is_type( 'grouped' ) || DSR_Engine::is_stored_value( $p ) ) {
				continue;
			}
			$price     = (float) $p->get_price( 'edit' );
			$subtotal += $price * $qty;
			$lines[ 'line' . ( ++$n ) ] = array(
				'product' => $p,
				'qty'     => $qty,
				'price'   => $price,
			);
		}
		$ctx = array(
			'ts'        => $ts,
			'logged_in' => $customer,
			'subtotal'  => $subtotal,
		);
		$res = DSR_Engine::compute( $lines, $ctx );
		$out = array();
		foreach ( $lines as $k => $l ) {
			$r     = isset( $res['lines'][ $k ] ) ? $res['lines'][ $k ] : null;
			$out[] = array(
				'product_id' => (int) $l['product']->get_id(),
				'name'       => $l['product']->get_name(),
				'qty'        => $l['qty'],
				'price'      => round( $l['price'], wc_get_price_decimals() ),
				'rule'       => $r ? (int) $r['rule'] : 0,
				'rule_name'  => $r ? (string) $r['name'] : '',
				'unit'       => $r ? round( $r['unit'], wc_get_price_decimals() + 2 ) : round( $l['price'], wc_get_price_decimals() ),
				'discount'   => $r ? round( $r['discount'], wc_get_price_decimals() ) : 0.0,
			);
		}
		return array(
			'lines'    => $out,
			'subtotal' => round( $subtotal, wc_get_price_decimals() ),
			'cart'     => $res['cart'] ? array(
				'rule'   => (int) $res['cart']['rule'],
				'name'   => (string) $res['cart']['name'],
				'amount' => (float) $res['cart']['amount'],
			) : null,
			'discount' => $res['total'],
			'total'    => round( $subtotal - $res['total'], wc_get_price_decimals() ),
			'capped'   => $res['capped'],
			'hints'    => $res['hints'],
			'note'     => 'Prices as stored (with or without tax like the store). Trade accounts and bundle lines never get rules.',
		);
	}
}

<?php
/**
 * TRA_Abilities — Trade Accounts in the WordPress Abilities API.
 *
 *  trade-accounts/status   modules, mode, discount, scope, how many trade
 *                          accounts and eligible products, the latest feed
 *                          calls (read; no e-mails, no passwords)
 *
 * Same capability as the settings (manage_woocommerce). Registered only on
 * WordPress 6.9+, where the Abilities API exists.
 */

defined( 'ABSPATH' ) || exit;

final class TRA_Abilities {

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
			'trade-accounts/status',
			array(
				'label'               => 'Trade Accounts: status',
				'description'         => 'Modules, mode, default discount, eligible categories and brands, the number of trade accounts and eligible products, and the latest Trade Feed calls (account id, route, status, items). Changes nothing.',
				'category'            => self::CAT,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => array( __CLASS__, 'status' ),
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
		return current_user_can( Trade_Accounts::cap() );
	}

	public static function status( $in = null ): array {
		$s    = TRA_Settings::get();
		$log  = get_option( 'tra_log', array() );
		$log  = is_array( $log ) ? array_slice( $log, 0, 20 ) : array();
		$rows = array();
		foreach ( $log as $l ) {
			if ( ! is_array( $l ) || ! isset( $l['t'] ) ) {
				continue;
			}
			$rows[] = array(
				'time'    => gmdate( 'c', (int) $l['t'] ),
				'account' => isset( $l['u'] ) ? (int) $l['u'] : 0,
				'route'   => isset( $l['r'] ) ? (string) $l['r'] : '',
				'status'  => isset( $l['s'] ) ? (int) $l['s'] : 0,
				'items'   => isset( $l['n'] ) ? (int) $l['n'] : 0,
			);
		}
		return array(
			'version'         => TRA_VERSION,
			'disabled'        => Trade_Accounts::killed(),
			'modules'         => $s['mods'],
			'mode'            => $s['mode'],
			'discount'        => $s['discount'],
			'base'            => $s['base'],
			'categories'      => $s['cats'],
			'brands'          => $s['brands'],
			'invoice'         => $s['invoice'],
			'rate_per_hour'   => $s['rate'],
			'accounts'        => TRA_Accounts::count(),
			'eligible'        => TRA_Scope::count(),
			'passwords_ready' => TRA_Accounts::passwords_available(),
			'feed_url'        => rest_url( 'tra/v1/products' ),
			'calls'           => $rows,
		);
	}
}

<?php
/**
 * CKE_Abilities — the store vacation in the WordPress Abilities API, for a
 * remote tool that runs abilities (e.g. Site Bridge's ability_run), with
 * the same capability (manage_woocommerce) and the same validation as the
 * settings page:
 *
 *  checkout-extras/vacation-status  mode, kind, dates, phase, what visitors
 *                                   get now, exemptions (read)
 *  checkout-extras/vacation-set     mode, kind, dates, warning days, the
 *                                   two exemption switches; purges the
 *                                   page cache and reschedules the purge
 *                                   jobs, as the Save button does
 *
 * Registered only on WordPress 6.9+, where the Abilities API exists.
 */

defined( 'ABSPATH' ) || exit;

final class CKE_Abilities {

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
		$date = array(
			'type'        => 'string',
			'description' => 'Date and time in the site time zone, "Y-m-d H:i" (e.g. "2026-12-20 18:00"); "" = none.',
		);
		$defs = array(
			'vacation-status' => array(
				'label'       => 'Checkout Extras: store vacation status',
				'description' => 'The store vacation of Checkout Extras: mode (off, test = shop managers only, live), kind (closed = no orders, later = orders accepted and shipped after the vacation), start and end, warning days, phase (off, scheduled, warn, active, ended), whether visitors can buy now, and the exemptions. Changes nothing.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'status' ),
			),
			'vacation-set'    => array(
				'label'       => 'Checkout Extras: set the store vacation',
				'description' => 'Changes the store vacation, validated as in WP admin: mode (off, test, live), kind (closed, later), start, end, warn_days (0-60), allow_digital, allow_preorders. Missing fields keep their value; an invalid value keeps the current one. mode = live with kind = closed stops buying for every visitor (the site stays open). Purges the page cache. Returns the status.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'mode'            => array(
							'type' => 'string',
							'enum' => array( 'off', 'test', 'live' ),
						),
						'kind'            => array(
							'type' => 'string',
							'enum' => CKE_Settings::KINDS,
						),
						'start'           => $date,
						'end'             => $date,
						'warn_days'       => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 60,
						),
						'allow_digital'   => array( 'type' => 'boolean' ),
						'allow_preorders' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'ann'         => array( false, false, true ),
				'run'         => array( __CLASS__, 'set' ),
			),
		);
		foreach ( $defs as $name => $d ) {
			wp_register_ability(
				'checkout-extras/' . $name,
				array(
					'label'               => $d['label'],
					'description'         => $d['description'],
					'category'            => self::CAT,
					'input_schema'        => $d['input'],
					'execute_callback'    => $d['run'],
					'permission_callback' => array( __CLASS__, 'allowed' ),
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => $d['ann'][0],
							'destructive' => $d['ann'][1],
							'idempotent'  => $d['ann'][2],
						),
						'show_in_rest' => false,
					),
				)
			);
		}
	}

	public static function allowed(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/** The vacation status. */
	public static function status( $in = null ): array {
		$s     = CKE_Settings::get();
		$phase = CKE_Vacation::phase();
		$buy   = Checkout_Extras::killed() || 'live' !== $s['v_mode'] || 'later' === $s['v_kind'] || 'active' !== $phase;
		return array(
			'version'          => CKE_VERSION,
			'disabled'         => Checkout_Extras::killed(),
			'mode'             => $s['v_mode'],
			'kind'             => $s['v_kind'],
			'start'            => $s['v_start'],
			'end'              => $s['v_end'],
			'timezone'         => wp_timezone_string(),
			'warn_days'        => (int) $s['v_warn_days'],
			'phase'            => $phase,
			'visitors_can_buy' => $buy,
			'allow_digital'    => (bool) $s['v_allow_digital'],
			'allow_preorders'  => (bool) $s['v_allow_preorders'],
			'exempt_roles'     => $s['v_roles'],
			'summary'          => CKE_Vacation::admin_status()[0],
		);
	}

	/** Changes the vacation settings. */
	public static function set( $in = null ): array {
		$in     = is_array( $in ) ? $in : array();
		$before = CKE_Settings::get();
		$raw    = $before;
		$map    = array(
			'mode'            => 'v_mode',
			'kind'            => 'v_kind',
			'start'           => 'v_start',
			'end'             => 'v_end',
			'warn_days'       => 'v_warn_days',
			'allow_digital'   => 'v_allow_digital',
			'allow_preorders' => 'v_allow_preorders',
		);
		foreach ( $map as $k => $key ) {
			if ( array_key_exists( $k, $in ) ) {
				$raw[ $key ] = $in[ $k ];
			}
		}
		CKE_Settings::save( $raw );
		CKE_Vacation::changed( $before );
		return self::status();
	}
}

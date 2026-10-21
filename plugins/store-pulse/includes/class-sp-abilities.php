<?php
/**
 * SP_Abilities — Store Pulse in the WordPress Abilities API (1.7.0).
 *
 * What the dashboard and the Sales screen show, for an AI or a remote
 * tool that runs abilities (e.g. Site Bridge's ability_run), with the
 * same capability (SP_Admin::CAP) and the same cached figures. All read:
 *
 *  store-pulse/overview  the dashboard cards of a period: pending orders,
 *                        completed (count, gross, customers, AOV, top
 *                        sellers), refunds, cancelled, stock counts and,
 *                        with Revenue Splitter, the money cards
 *  store-pulse/stock     the low-stock and out-of-stock lists
 *  store-pulse/sales     the Sales screen's rows per product (what the CSV
 *                        holds), with totals
 *
 * Nothing here returns a customer's name, email or address: customers
 * are a count. Registered only on WordPress 6.9+.
 */

defined( 'ABSPATH' ) || exit;

final class SP_Abilities {

	const CAT = 'noxpress';

	/** Rows the sales ability returns at most. */
	const MAX_ROWS = 500;

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
			'type'    => 'string',
			'pattern' => '^\d{4}-\d{2}-\d{2}$',
		);
		$defs = array(
			'overview' => array(
				'label'       => 'Store Pulse: overview',
				'description' => 'The dashboard for a period (period: today, yesterday, 7d, 15d, month = last 30 days; default month; or from/to local dates): pending orders (and those older than 7 days), completed orders (count, gross after refunds, customers as a count, average order, top 10 products), refunds, cancelled, low/out of stock counts and, with Revenue Splitter, the publisher\'s share and what is owed to the others. Changes nothing.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'period' => array(
							'type' => 'string',
							'enum' => SP_Data::PRESETS,
						),
						'from'   => $date,
						'to'     => $date,
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'run'         => array( __CLASS__, 'overview' ),
			),
			'stock'    => array(
				'label'       => 'Store Pulse: stock',
				'description' => 'Products with low stock (0 < stock <= threshold; default the Settings value) and out of stock: full counts and the first 50 of each (id, title, stock). Changes nothing.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'threshold' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 10000,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'run'         => array( __CLASS__, 'stock' ),
			),
			'sales'    => array(
				'label'       => 'Store Pulse: sales by product',
				'description' => 'The Sales screen\'s report: one row per product (or variation with split) with id, name, SKU, categories, quantity, before discount, discount, sales, VAT, net, refunded quantity and amount, orders and stock, plus totals. Same fields as the screen: period (today, yesterday, 7d, 30d, this_month, last_month, this_year, last_year, all, custom with from/to), basis (completed, paid, created), statuses (e.g. wc-completed), scope (all, cats, products) with cats or products ids, split, free, refunds (default true), orderby (name, qty, sales), order (asc, desc). limit (default 100, at most 500). Changes nothing.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'period'   => array(
							'type' => 'string',
							'enum' => array_keys( SP_Sales::PERIODS ),
						),
						'from'     => $date,
						'to'       => $date,
						'basis'    => array(
							'type' => 'string',
							'enum' => array_keys( SP_Sales::BASES ),
						),
						'statuses' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'scope'    => array(
							'type' => 'string',
							'enum' => array( 'all', 'cats', 'products' ),
						),
						'cats'     => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'products' => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'integer' ),
							'maxItems' => SP_Sales::MAX_PIDS,
						),
						'split'    => array( 'type' => 'boolean' ),
						'free'     => array( 'type' => 'boolean' ),
						'refunds'  => array( 'type' => 'boolean' ),
						'orderby'  => array(
							'type' => 'string',
							'enum' => array_keys( SP_Sales::ORDERBY ),
						),
						'order'    => array(
							'type' => 'string',
							'enum' => array( 'asc', 'desc' ),
						),
						'limit'    => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => self::MAX_ROWS,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'run'         => array( __CLASS__, 'sales' ),
			),
		);
		foreach ( $defs as $id => $d ) {
			wp_register_ability(
				'store-pulse/' . $id,
				array(
					'label'               => $d['label'],
					'description'         => $d['description'],
					'category'            => self::CAT,
					'input_schema'        => $d['input'],
					'execute_callback'    => $d['run'],
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
	}

	public static function allowed(): bool {
		return current_user_can( SP_Admin::CAP );
	}

	public static function overview( $in = null ) {
		$in = is_array( $in ) ? $in : array();
		if ( isset( $in['from'] ) || isset( $in['to'] ) ) {
			$s = isset( $in['from'] ) ? self::ymd( $in['from'] ) : '';
			$e = isset( $in['to'] ) ? self::ymd( $in['to'] ) : '';
			if ( '' === $s || '' === $e || $s > $e ) {
				return new WP_Error( 'sp_dates', 'Give both from and to (YYYY-MM-DD), from not after to.' );
			}
			$range = array( $s, $e );
		} else {
			$range = SP_Data::period_range( isset( $in['period'] ) ? (string) $in['period'] : 'month' );
			if ( null === $range ) {
				return new WP_Error( 'sp_period', 'Unknown period.' );
			}
		}
		list( $s, $e ) = $range;
		$threshold     = SP_Data::safe_threshold();
		$stock         = SP_Data::stock( $threshold );
		$money         = SP_Data::money( $s, $e, SP_Data::safe_publisher() );
		return array(
			'version'   => SP_VERSION,
			'period'    => array(
				'start' => $s,
				'end'   => $e,
			),
			'pending'   => SP_Data::pending(),
			'completed' => SP_Data::completed( $s, $e ),
			'refunds'   => SP_Data::refunds( $s, $e ),
			'cancelled' => SP_Data::cancelled( $s, $e ),
			'stock'     => array(
				'threshold' => $threshold,
				'low'       => (int) $stock['low']['count'],
				'out'       => (int) $stock['out']['count'],
			),
			'money'     => null === $money ? null : array( 'publisher' => SP_Data::safe_publisher() ) + $money,
		);
	}

	public static function stock( $in = null ): array {
		$t = is_array( $in ) && isset( $in['threshold'] ) ? max( 0, (int) $in['threshold'] ) : SP_Data::safe_threshold();
		return array( 'threshold' => $t ) + SP_Data::stock( $t );
	}

	public static function sales( $in = null ) {
		$in = is_array( $in ) ? $in : array();
		$d  = SP_Sales::defaults();
		// Booleans the caller left out keep the screen's defaults
		// (sanitize() reads a missing checkbox as off).
		foreach ( array( 'split', 'free', 'refunds' ) as $k ) {
			if ( ! array_key_exists( $k, $in ) ) {
				$in[ $k ] = $d[ $k ];
			}
		}
		if ( ! isset( $in['statuses'] ) ) {
			$in['statuses'] = $d['statuses'];
		}
		$in['cols']   = array_keys( SP_Sales::COLUMNS );
		$in['totals'] = true;
		$args         = SP_Sales::sanitize( $in );
		$errors       = SP_Sales::errors();
		if ( $errors ) {
			return new WP_Error( 'sp_args', implode( ' ', $errors ) );
		}
		$limit  = isset( $in['limit'] ) ? max( 1, min( self::MAX_ROWS, (int) $in['limit'] ) ) : 100;
		$report = SP_Sales::report( $args );
		$rows   = SP_Sales::sorted( $report['rows'], $args );
		$range  = SP_Sales::range( $args );
		return array(
			'period' => null === $range ? null : array(
				'start' => $range[0],
				'end'   => $range[1],
			),
			'basis'  => $args['basis'],
			'vat'    => $report['vat'],
			'count'  => count( $rows ),
			'rows'   => array_slice( $rows, 0, $limit ),
			'totals' => SP_Sales::totals( $report ),
		);
	}

	private static function ymd( $v ): string {
		if ( ! is_string( $v ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) ) {
			return '';
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $v : '';
	}
}

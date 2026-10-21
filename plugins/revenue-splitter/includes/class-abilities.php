<?php
/**
 * RS_Abilities — Revenue Splitter in the WordPress Abilities API (1.10.0).
 *
 * What the Reports, Ledger and Settings screens show, and the monthly
 * report emails, for an AI or a remote tool that runs abilities (e.g.
 * Site Bridge's ability_run), with the same capability (RS_Admin_UI::CAP)
 * and the same code paths:
 *
 *  revenue-splitter/report           the sales report of a period (read)
 *  revenue-splitter/balances         lifetime sales, other income, payments
 *                                    and what is owed, per beneficiary (read)
 *  revenue-splitter/monthly-status   who gets the monthly report, and
 *                                    whether last month's went out (read)
 *  revenue-splitter/monthly-preview  one beneficiary's monthly report, as
 *                                    figures (read)
 *  revenue-splitter/send-test        a test email to the site's admin address
 *  revenue-splitter/monthly-send     last month's reports, now (destructive:
 *                                    emails leave the site)
 *
 * Nothing here returns an email address, a portal key or a ledger note.
 * Registered only on WordPress 6.9+, where the Abilities API exists.
 */

defined( 'ABSPATH' ) || exit;

final class RS_Abilities {

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
		$none = array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
			'default'              => array(),
		);
		$date = array(
			'type'    => 'string',
			'pattern' => '^\d{4}-\d{2}-\d{2}$',
		);
		$name = array(
			'type'        => 'string',
			'description' => 'A beneficiary\'s name, as in the products\' splits.',
		);
		$defs = array(
			'report'          => array(
				'label'       => 'Revenue Splitter: sales report',
				'description' => 'The report of the Reports screen for a period (local dates, inclusive; default the last 30 days; clamped to the sales start date): totals (gross, VAT, net), order count, each beneficiary\'s share, channels, warnings and, unless products is false, the products with their splits. Optional product_ids (at most 100). Changes nothing.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'date_start'  => $date,
						'date_end'    => $date,
						'product_ids' => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'integer' ),
							'maxItems' => 100,
						),
						'products'    => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'report' ),
			),
			'balances'        => array(
				'label'       => 'Revenue Splitter: balances',
				'description' => 'Per beneficiary, all time: sales share, other income and payments from the ledger, and what remains payable (as "wp rs balance"). Optional name for one beneficiary. Reports are cached for 5 minutes. Changes nothing.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array( 'name' => $name ),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'balances' ),
			),
			'monthly-status'  => array(
				'label'       => 'Revenue Splitter: monthly report status',
				'description' => 'The beneficiaries who chose the monthly report (and whether each has an email; the addresses are not shown), the month the next report covers and whether it was already sent. Changes nothing.',
				'input'       => $none,
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'monthly_status' ),
			),
			'monthly-preview' => array(
				'label'       => 'Revenue Splitter: preview a monthly report',
				'description' => 'The figures of one beneficiary\'s monthly report: the month\'s share, the remaining payable balance and the products (title, quantity, percent, share). month is YYYY-MM (default last month). Nothing is sent.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'name'  => $name,
						'month' => array(
							'type'    => 'string',
							'pattern' => '^\d{4}-\d{2}$',
						),
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'monthly_preview' ),
			),
			'send-test'       => array(
				'label'       => 'Revenue Splitter: send a test email',
				'description' => 'Sends the Settings screen\'s test email to the site\'s admin address, through the same path as the monthly reports. Reports whether WordPress accepted it.',
				'input'       => $none,
				'ann'         => array( false, false, false ),
				'run'         => array( __CLASS__, 'send_test' ),
			),
			'monthly-send'    => array(
				'label'       => 'Revenue Splitter: send the monthly reports',
				'description' => 'Sends last month\'s report now to every beneficiary who chose it, as the daily check does on the 1st. Once a month is sent it is not sent again unless resend is true. With name, sends that one beneficiary\'s report only (to the email on file) and the monthly run is left as it is. Emails cannot be taken back: check revenue-splitter/monthly-status and monthly-preview first.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'name'   => $name,
						'resend' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'ann'         => array( false, true, false ),
				'run'         => array( __CLASS__, 'monthly_send' ),
			),
		);
		foreach ( $defs as $id => $d ) {
			wp_register_ability(
				'revenue-splitter/' . $id,
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
		return current_user_can( RS_Admin_UI::CAP );
	}

	/* ---------------------------------------------------------------------
	 * Read
	 * ------------------------------------------------------------------- */

	public static function report( $in = null ) {
		$in   = is_array( $in ) ? $in : array();
		$args = array();
		foreach ( array( 'date_start', 'date_end' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				if ( ! self::is_date( $in[ $k ] ) ) {
					return new WP_Error( 'rs_date', 'Dates are YYYY-MM-DD.' );
				}
				$args[ $k ] = $in[ $k ];
			}
		}
		if ( isset( $args['date_start'], $args['date_end'] ) && $args['date_start'] > $args['date_end'] ) {
			return new WP_Error( 'rs_date', 'date_start is after date_end.' );
		}
		if ( ! empty( $in['product_ids'] ) && is_array( $in['product_ids'] ) ) {
			$args['product_ids'] = array_slice( array_values( array_filter( array_map( 'absint', $in['product_ids'] ) ) ), 0, 100 );
		}
		$r = RS_Reports::run( $args );
		if ( isset( $in['products'] ) && false === $in['products'] ) {
			unset( $r['products'] );
		}
		$r['sales_since'] = RS_Reports::sales_since();
		return $r;
	}

	public static function balances( $in = null ) {
		$in   = is_array( $in ) ? $in : array();
		$only = '';
		if ( isset( $in['name'] ) ) {
			$only = self::name( $in );
			if ( is_wp_error( $only ) ) {
				return $only;
			}
		}
		$today = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );
		$sales = RS_Reports::lifetime_beneficiaries();
		$names = '' !== $only ? array( $only ) : array_values( array_unique( array_merge( RS_Beneficiaries::collect_names(), array_keys( $sales ) ) ) );
		$out   = array();
		foreach ( $names as $n ) {
			$n      = (string) $n;
			$s      = (float) ( $sales[ $n ] ?? 0.0 );
			$income = RS_Ledger::sum( $n, '2000-01-01', $today, 'income' );
			$paid   = RS_Ledger::sum( $n, '2000-01-01', $today, 'payment' );
			$out[]  = array(
				'name'      => $n,
				'sales'     => round( $s, 2 ),
				'income'    => round( $income, 2 ),
				'payments'  => round( $paid, 2 ),
				'remaining' => round( $s + $income - $paid, 2 ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['remaining'] <=> $a['remaining'];
			}
		);
		return array(
			'sales_since'   => RS_Reports::sales_since(),
			'beneficiaries' => $out,
		);
	}

	public static function monthly_status( $in = null ): array {
		$prev       = new DateTimeImmutable( 'first day of previous month', wp_timezone() );
		$last       = (string) get_option( RS_Emails::OPT_LAST_RUN, '' );
		$recipients = array();
		foreach ( array_keys( RS_Emails::get_report_map() ) as $n ) {
			$recipients[] = array(
				'name'      => (string) $n,
				'has_email' => '' !== RS_Emails::get_email( (string) $n ),
			);
		}
		$next = wp_next_scheduled( RS_Emails::CRON_HOOK );
		return array(
			'month'      => $prev->format( 'Y-m' ),
			'sent'       => $last === $prev->format( 'Y-m' ),
			'last_sent'  => $last,
			'next_check' => $next ? gmdate( 'c', (int) $next ) : null,
			'recipients' => $recipients,
		);
	}

	public static function monthly_preview( $in = null ) {
		$who = self::name( $in );
		if ( is_wp_error( $who ) ) {
			return $who;
		}
		$month = is_array( $in ) && isset( $in['month'] ) ? (string) $in['month'] : '';
		if ( '' === $month ) {
			$m = new DateTimeImmutable( 'first day of previous month', wp_timezone() );
		} else {
			$m = preg_match( '/^\d{4}-\d{2}$/', $month ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $month . '-01', wp_timezone() ) : false;
			if ( ! $m || $m->format( 'Y-m' ) !== $month ) {
				return new WP_Error( 'rs_month', 'month is YYYY-MM.' );
			}
		}
		$data = RS_Emails::monthly_data( $who, $m->format( 'Y-m-01' ), $m->format( 'Y-m-t' ) );
		return array(
			'name'      => $who,
			'month'     => $m->format( 'Y-m' ),
			'opted_in'  => RS_Emails::is_opted_in( $who ),
			'has_email' => '' !== RS_Emails::get_email( $who ),
			'share'     => $data['share'],
			'remaining' => $data['remaining'],
			'products'  => $data['rows'],
		);
	}

	/* ---------------------------------------------------------------------
	 * Write
	 * ------------------------------------------------------------------- */

	public static function send_test( $in = null ): array {
		return array( 'sent' => RS_Emails::send_test( (string) get_option( 'admin_email', '' ) ) );
	}

	public static function monthly_send( $in = null ) {
		$in     = is_array( $in ) ? $in : array();
		$resend = ! empty( $in['resend'] );
		$only   = '';
		if ( isset( $in['name'] ) ) {
			$only = self::name( $in );
			if ( is_wp_error( $only ) ) {
				return $only;
			}
		}
		$r = RS_Emails::send_monthly( $resend, $only );
		if ( '' === $only && $r['already'] && ! $resend ) {
			$r['reason'] = 'Already sent for this month; pass resend to send it again.';
		}
		return $r;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	private static function is_date( $v ): bool {
		if ( ! is_string( $v ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/** A known beneficiary's name from the input, or WP_Error. */
	private static function name( $in ) {
		$n = is_array( $in ) && isset( $in['name'] ) && is_string( $in['name'] ) ? trim( $in['name'] ) : '';
		if ( '' === $n || ! in_array( $n, RS_Beneficiaries::collect_names(), true ) ) {
			return new WP_Error( 'rs_name', 'Unknown beneficiary.' );
		}
		return $n;
	}
}

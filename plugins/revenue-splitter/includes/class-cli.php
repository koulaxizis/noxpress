<?php
/**
 * RS_CLI — WP-CLI commands (v1.3.0).
 *
 * Διαθέσιμες εντολές:
 *
 *   wp rs report [--start=Y-m-d] [--end=Y-m-d] [--product=<id>]
 *               [--ben=<name>] [--format=table|json]
 *   wp rs ledger-add --type=income|payment --ben=<name> --amount=<n>
 *                   --note="<reason>" [--date=Y-m-d]
 *   wp rs ledger-list [--ben=<name>] [--type=income|payment] [--format=table|json]
 *   wp rs ledger-delete --id=<id>
 *   wp rs balance --ben=<name>
 *   wp rs backup
 *
 * Σχέδιο: οι εντολές δεν έχουν δικό τους state — βάζουν απλώς κλήσεις
 * στα ίδια PUBLIC APIs που χρησιμοποιεί το web UI (RS_Reports,
 * RS_Ledger). Μία λογική, μηδέν drift.
 *
 * v1.3.1 (#19): required-args check με isset() + ''!== (όχι empty())
 * ώστε --amount="0" / --note="0" να φτάνουν στο RS_Ledger::add() και να
 * παίρνουν το σωστό (επιχειρησιακό) μήνυμα απόκρισης. Cleanup: αφαίρεση
 * αχρησιμοποίητου stamp() helper.
 *
 * Note: η έξοδος είναι ΣΤΟΙΧΗΜΕΝΗ στα Αγγλικά (CLI convention) — τα
 * επιχειρησιακά μηνύματα των classes μένουν όμως μεταφρασμένα ως
 * δύναται (gettext), οπότε η ροή είναι σταθερή.
 */

defined( 'ABSPATH' ) || exit;

final class RS_CLI {

	public static function init(): void {
		WP_CLI::add_command( 'rs report', array( __CLASS__, 'report' ) );
		WP_CLI::add_command( 'rs ledger-add', array( __CLASS__, 'ledger_add' ) );
		WP_CLI::add_command( 'rs ledger-list', array( __CLASS__, 'ledger_list' ) );
		WP_CLI::add_command( 'rs ledger-delete', array( __CLASS__, 'ledger_delete' ) );
		WP_CLI::add_command( 'rs balance', array( __CLASS__, 'balance' ) );
		WP_CLI::add_command( 'rs backup', array( __CLASS__, 'backup' ) );
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	/** Αριθμός 2 δεκαδικών, dot decimal (CLI-friendly, όχι i18n). */
	private static function fmt( $n ): string {
		return number_format( (float) $n, 2, '.', '' );
	}

	/** Έλεγχος 'Y-m-d'. */
	private static function is_date( string $d ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/* =====================================================================
	 * wp rs report
	 * =================================================================== */

	/**
	 * Show the sales report for a period.
	 *
	 * ## OPTIONS
	 *
	 * [--start=<date>]
	 * : Period start (Y-m-d). Default: 30 days ago.
	 *
	 * [--end=<date>]
	 * : Period end (Y-m-d). Default: today.
	 *
	 * [--product=<id>]
	 * : Limit to a single product ID.
	 *
	 * [--ben=<name>]
	 * : Limit to a single beneficiary (splits filtered accordingly).
	 *
	 * [--format=<format>]
	 * : Output format: table or json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rs report
	 *     wp rs report --start=2026-08-01 --end=2026-08-31 --format=json
	 *     wp rs report --product=128 --ben="Christos Koulaxizis"
	 */
	public static function report( array $args, array $assoc ): void {

		$now = new DateTimeImmutable( 'now', wp_timezone() );

		$start = isset( $assoc['start'] ) ? (string) $assoc['start'] : $now->modify( '-29 days' )->format( 'Y-m-d' );
		$end   = isset( $assoc['end'] ) ? (string) $assoc['end'] : $now->format( 'Y-m-d' );

		if ( ! self::is_date( $start ) || ! self::is_date( $end ) || $start > $end ) {
			WP_CLI::error( 'Invalid --start/--end (expected Y-m-d, start <= end).' );
		}

		$run = array(
			'date_start' => $start,
			'date_end'   => $end,
		);

		if ( ! empty( $assoc['product'] ) ) {
			$pid = absint( $assoc['product'] );
			if ( $pid > 0 ) {
				$run['product_ids'] = array( $pid );
			}
		}

		$report = RS_Reports::run( $run );

		$ben = isset( $assoc['ben'] ) ? trim( (string) $assoc['ben'] ) : '';

		$items = array();
		foreach ( $report['products'] as $p ) {

			$splits = $p['splits'];
			if ( '' !== $ben ) {
				$splits = array_values(
					array_filter(
						$splits,
						static function ( $s ) use ( $ben ) {
							return (string) $s['name'] === $ben;
						}
					)
				);
				if ( empty( $splits ) ) {
					continue;
				}
			}

			$items[] = array(
				'id'      => (int) $p['product_id'],
				'product' => (string) $p['title'],
				'qty'     => (int) $p['qty'],
				'gross'   => self::fmt( $p['gross'] ),
				'vat'     => self::fmt( $p['vat'] ),
				'net'     => self::fmt( $p['net'] ),
				'splits'  => implode(
					'; ',
					array_map(
						static function ( $s ) {
							return $s['name'] . ' ' . self::fmt( $s['amount'] ) . ' (' . $s['percent'] . '%)';
						},
						$splits
					)
				),
			);
		}

		WP_CLI::line( 'Revenue Splitter — period ' . $start . ' → ' . $end . ' (' . (int) $report['order_count'] . ' orders)' );

		$format = isset( $assoc['format'] ) ? (string) $assoc['format'] : 'table';

		if ( 'json' === $format ) {
			WP_CLI::line(
				wp_json_encode(
					array(
						'period'        => $report['period'],
						'order_count'  => $report['order_count'],
						'products'     => $items,
						'totals'       => $report['totals'],
						'beneficiaries' => $report['beneficiaries'],
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
				)
			);
		} else {
			WP_CLI\Utils\format_items( $format, $items, array( 'id', 'product', 'qty', 'gross', 'vat', 'net', 'splits' ) );

			$t = $report['totals'];
			WP_CLI::line( 'TOTALS: gross ' . self::fmt( $t['gross'] ) . ' | vat ' . self::fmt( $t['vat'] ) . ' | net ' . self::fmt( $t['net'] ) );
		}
	}

	/* =====================================================================
	 * wp rs ledger-add
	 * =================================================================== */

	/**
	 * Add a ledger entry (non-sales income or payment).
	 *
	 * ## OPTIONS
	 *
	 * --type=<type>
	 * : Entry type: income or payment.
	 *
	 * --ben=<name>
	 * : Beneficiary name (must exist in Settings).
	 *
	 * --amount=<amount>
	 * : Amount (payments: positive only; income: + or -, non-zero).
	 *
	 * --note=<note>
	 * : Mandatory reason.
	 *
	 * [--date=<date>]
	 * : Entry date (Y-m-d). Default: today.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rs ledger-add --type=income --ben="Maria Papadopoulou" --amount=-40 --note="correction: double entry June"
	 *     wp rs ledger-add --type=payment --ben="Maria Papadopoulou" --amount=250 --note="Q3 payout" --date=2026-09-30
	 */
	public static function ledger_add( array $args, array $assoc ): void {

		// v1.3.1 (#19): isset() + ''!== — ΟΧΙ empty(). Έτσι το --amount="0"
		// φτάνει στο RS_Ledger::add() και παίρνει το επιχειρησιακό μήνυμα
		// («Το ποσό δεν μπορεί να είναι μηδέν») αντί για σαφώς λάθος
		// «Missing required --amount».
		$required = array( 'type', 'ben', 'amount', 'note' );
		foreach ( $required as $r ) {
			if ( ! isset( $assoc[ $r ] ) || '' === (string) $assoc[ $r ] ) {
				WP_CLI::error( 'Missing required --' . $r . '.' );
			}
		}

		$now = new DateTimeImmutable( 'now', wp_timezone() );

		$entry = array(
			'type'        => (string) $assoc['type'],
			'date'        => isset( $assoc['date'] ) ? (string) $assoc['date'] : $now->format( 'Y-m-d' ),
			'beneficiary' => (string) $assoc['ben'],
			'amount'      => (string) $assoc['amount'],
			'note'        => (string) $assoc['note'],
		);

		$res = RS_Ledger::add( $entry );

		if ( true === $res ) {
			WP_CLI::success( 'Ledger entry added: ' . $entry['type'] . ' ' . $entry['amount'] . ' → ' . $entry['beneficiary'] . ' (' . $entry['date'] . ')' );
		} else {
			WP_CLI::error( 'Rejected by RS_Ledger::add(): ' . (string) $res );
		}
	}

	/* =====================================================================
	 * wp rs ledger-list
	 * =================================================================== */

	/**
	 * List ledger entries.
	 *
	 * ## OPTIONS
	 *
	 * [--ben=<name>]
	 * : Filter by beneficiary.
	 *
	 * [--type=<type>]
	 * : Filter by type: income or payment.
	 *
	 * [--format=<format>]
	 * : Output format: table or json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rs ledger-list
	 *     wp rs ledger-list --ben="Maria Papadopoulou" --type=payment
	 */
	public static function ledger_list( array $args, array $assoc ): void {

		$ben  = isset( $assoc['ben'] ) ? (string) $assoc['ben'] : null;
		$type = isset( $assoc['type'] ) ? (string) $assoc['type'] : null;

		if ( null !== $type && ! in_array( $type, array( 'income', 'payment' ), true ) ) {
			WP_CLI::error( 'Invalid --type (income|payment).' );
		}

		$entries = ( null !== $ben )
			? RS_Ledger::for_beneficiary( $ben, $type )
			: RS_Ledger::all();

		// Τυπικό φίλτρο τύπου όταν δεν δόθηκε --ben (for_beneficiary κάνει και τα δύο).
		if ( null === $ben && null !== $type ) {
			$entries = array_values(
				array_filter(
					$entries,
					static function ( $e ) use ( $type ) {
						return $e['type'] === $type;
					}
				)
			);
		}

		if ( empty( $entries ) ) {
			WP_CLI::log( 'No ledger entries found.' );
			return;
		}

		$items = array_map(
			static function ( array $e ) {
				return array(
					'id'     => (int) $e['id'],
					'date'   => $e['date'],
					'type'   => $e['type'],
					'ben'    => $e['beneficiary'],
					'amount' => self::fmt( $e['amount'] ),
					'note'   => $e['note'],
				);
			},
			$entries
		);

		$format = isset( $assoc['format'] ) ? (string) $assoc['format'] : 'table';

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		} else {
			WP_CLI\Utils\format_items( $format, $items, array( 'id', 'date', 'type', 'ben', 'amount', 'note' ) );
		}
	}

	/* =====================================================================
	 * wp rs ledger-delete
	 * =================================================================== */

	/**
	 * Delete a ledger entry by ID.
	 *
	 * ## OPTIONS
	 *
	 * --id=<id>
	 * : Entry ID (see: wp rs ledger-list).
	 *
	 * ## EXAMPLES
	 *
	 *     wp rs ledger-delete --id=7
	 */
	public static function ledger_delete( array $args, array $assoc ): void {

		$id = isset( $assoc['id'] ) ? absint( $assoc['id'] ) : 0;
		if ( $id <= 0 ) {
			WP_CLI::error( 'Missing or invalid --id.' );
		}

		if ( RS_Ledger::delete( $id ) ) {
			WP_CLI::success( 'Ledger entry #' . $id . ' deleted.' );
		} else {
			WP_CLI::error( 'Entry #' . $id . ' not found.' );
		}
	}

	/* =====================================================================
	 * wp rs balance
	 * =================================================================== */

	/**
	 * Show the LIFETIME balance of a beneficiary (all-time sales +
	 * non-sales income − payments).
	 *
	 * ## OPTIONS
	 *
	 * --ben=<name>
	 * : Beneficiary name.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rs balance --ben="Maria Papadopoulou"
	 */
	public static function balance( array $args, array $assoc ): void {

		$ben = isset( $assoc['ben'] ) ? trim( (string) $assoc['ben'] ) : '';
		if ( '' === $ben ) {
			WP_CLI::error( 'Missing --ben.' );
		}

		$now = new DateTimeImmutable( 'now', wp_timezone() );

		$sales  = RS_Reports::lifetime_beneficiaries()[ $ben ] ?? 0.0;
		$income = RS_Ledger::sum( $ben, '2000-01-01', $now->format( 'Y-m-d' ), 'income' );
		$paid   = RS_Ledger::sum( $ben, '2000-01-01', $now->format( 'Y-m-d' ), 'payment' );

		WP_CLI::line( 'Lifetime balance for "' . $ben . '"' );
		WP_CLI::line( '  All-time sales:      ' . self::fmt( $sales ) );
		WP_CLI::line( '  Non-sales income:    ' . self::fmt( $income ) );
		WP_CLI::line( '  Payments:            ' . self::fmt( $paid ) );
		WP_CLI::line( '  Remaining payable:   ' . self::fmt( $sales + $income - $paid ) );
	}

	/* =====================================================================
	 * wp rs backup
	 * =================================================================== */

	/**
	 * Dump the full plugin state as JSON (stdout — pipe to file to save).
	 *
	 * ## EXAMPLES
	 *
	 *     wp rs backup > rs-backup-2026-09-04.json
	 */
	public static function backup( array $args, array $assoc ): void {
		WP_CLI::line(
			wp_json_encode(
				RS_Admin_UI::export_state(),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
		);
	}
}
<?php
/**
 * GFC_CLI — wp gift-cards … (read only).
 *
 *   wp gift-cards list [--status=<status>] [--limit=<n>] [--format=<f>]
 *   wp gift-cards summary
 *   wp gift-cards check
 *
 * No command shows or changes a code or a balance: changes go through the
 * admin, where every one is written to the ledger with its user.
 */

defined( 'ABSPATH' ) || exit;

final class GFC_CLI {

	public static function init(): void {
		WP_CLI::add_command( 'gift-cards', __CLASS__ );
	}

	/**
	 * Lists gift cards (masked codes only).
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : pending, review, active, disabled, void, expired or locked.
	 *
	 * [--limit=<n>]
	 * : At most this many (default 50).
	 *
	 * [--format=<format>]
	 * : table, csv, json (default table).
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ): void {
		global $wpdb;
		$t      = GFC_Schema::cards();
		$status = isset( $assoc['status'] ) ? sanitize_key( $assoc['status'] ) : '';
		$limit  = isset( $assoc['limit'] ) ? max( 1, min( 5000, (int) $assoc['limit'] ) ) : 50;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$rows = in_array( $status, GFC_Store::STATUSES, true )
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		// phpcs:enable
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'        => (int) $r['id'],
				'card'      => GFC_Codes::mask( (string) $r['last4'] ),
				'initial'   => wc_format_decimal( (int) $r['initial'] / 100, 2 ),
				'balance'   => wc_format_decimal( (int) $r['balance'] / 100, 2 ),
				'currency'  => $r['currency'],
				'status'    => $r['status'],
				'order'     => (int) $r['order_id'],
				'delivered' => (string) $r['delivered_at'],
				'expires'   => (string) $r['expires_at'],
			);
		}
		WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $out, array( 'id', 'card', 'initial', 'balance', 'currency', 'status', 'order', 'delivered', 'expires' ) );
	}

	/**
	 * Open balances (what the shop owes) and cards by status.
	 */
	public function summary( $args, $assoc ): void {
		global $wpdb;
		$t = GFC_Schema::cards();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, no input.
		$owed = $wpdb->get_results( "SELECT currency, SUM(balance) AS s, COUNT(*) AS n FROM $t WHERE status IN ('pending','review','active','disabled','locked') AND balance > 0 GROUP BY currency", ARRAY_A );
		$byst = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM $t GROUP BY status", ARRAY_A );
		// phpcs:enable
		foreach ( (array) $owed as $o ) {
			WP_CLI::log( sprintf( 'Open balances: %s %s on %d cards', wc_format_decimal( (int) $o['s'] / 100, 2 ), $o['currency'], (int) $o['n'] ) );
		}
		foreach ( (array) $byst as $b ) {
			WP_CLI::log( sprintf( '%-9s %d', $b['status'], (int) $b['n'] ) );
		}
	}

	/**
	 * Checks that every balance equals the sum of its ledger (read only).
	 */
	public function check( $args, $assoc ): void {
		$broken = GFC_Store::broken();
		if ( ! $broken ) {
			WP_CLI::success( 'Every balance equals the sum of its ledger.' );
			return;
		}
		foreach ( $broken as $b ) {
			WP_CLI::warning( sprintf( 'Card #%d: balance %d, ledger %d (cents).', $b['id'], $b['balance'], $b['ledger'] ) );
		}
		WP_CLI::halt( 1 );
	}
}

<?php
/**
 * EVT_Ticket_Store — the ticket table and the ticket codes (1.1.0, Π25).
 *
 * Table {prefix}evt_tickets, one row per ticket (quantity 3 = 3 rows):
 *  id, event_id, product_id (the variation when there is one), order_id,
 *  item_id, seq (1..qty inside the order item), user_id, code_hash,
 *  status 'valid' | 'void', used (datetime UTC) and used_by (user id),
 *  name (optional attendee name, Π27), created.
 *
 * Codes: 10 characters from an alphabet without 0/O/1/I (32 symbols,
 * 50 bits), derived with HMAC-SHA256 from the plugin's own random key
 * (option evt_ticket_secret, never autoloaded, not tied to the WordPress salts)
 * and the ticket row, so they can be shown again to the buyer without being
 * stored. The table keeps only an HMAC of the code (code_hash) for the
 * check-in lookup. Never a serial number.
 */

defined( 'ABSPATH' ) || exit;

final class EVT_Ticket_Store {

	const DB_VERSION = '1';
	const DB_OPT     = 'evt_db_version';
	const KEY_OPT    = 'evt_ticket_secret'; // A name Site Bridge masks as a secret.
	const ALPHABET   = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'evt_tickets';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL DEFAULT 0,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  item_id bigint(20) unsigned NOT NULL DEFAULT 0,
  seq int(10) unsigned NOT NULL DEFAULT 1,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  code_hash char(64) NOT NULL DEFAULT '',
  status varchar(10) NOT NULL DEFAULT 'valid',
  used datetime DEFAULT NULL,
  used_by bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(120) NOT NULL DEFAULT '',
  created datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY item_seq (item_id,seq),
  KEY code_hash (code_hash),
  KEY event (event_id,status),
  KEY order_id (order_id),
  KEY user_id (user_id)
) {$charset};";
		dbDelta( $sql );
		self::key();
		update_option( self::DB_OPT, self::DB_VERSION, false );
	}

	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== (string) get_option( self::DB_OPT, '' ) ) {
			self::install();
			update_option( 'evt_flush', 1, true ); // The «Tickets» account endpoint.
		}
	}

	/** The plugin's own secret (32 random bytes, hex), created once. */
	private static function key(): string {
		$k = (string) get_option( self::KEY_OPT, '' );
		if ( 64 !== strlen( $k ) ) {
			$k = bin2hex( random_bytes( 32 ) );
			update_option( self::KEY_OPT, $k, false );
		}
		return $k;
	}

	/** HMAC with the plugin key, for a purpose label and a value. */
	public static function mac( string $purpose, string $value ): string {
		return hash_hmac( 'sha256', $purpose . '|' . $value, self::key() );
	}

	/** The code of a ticket row: 10 symbols, derived (Π25). */
	public static function code_for( array $row ): string {
		$raw = hash_hmac( 'sha256', 'code|' . (int) $row['id'] . '|' . (int) $row['order_id'] . '|' . (int) $row['item_id'] . '|' . (int) $row['seq'], self::key(), true );
		$out = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$out .= self::ALPHABET[ ord( $raw[ $i ] ) & 31 ];
		}
		return $out;
	}

	/** Normalizes a typed or scanned code (spaces, dashes, case); '' when it cannot be one. */
	public static function norm_code( string $code ): string {
		$c = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $code ) );
		return 10 === strlen( $c ) && 10 === strspn( $c, self::ALPHABET ) ? $c : '';
	}

	/** «ABCDE-FGHJK» for display. */
	public static function pretty( string $code ): string {
		return substr( $code, 0, 5 ) . '-' . substr( $code, 5 );
	}

	public static function code_hash( string $code ): string {
		return self::mac( 'lookup', $code );
	}

	/* =====================================================================
	 * Rows
	 * =================================================================== */

	private static function cast( $r ): array {
		$r = (array) $r;
		foreach ( array( 'id', 'event_id', 'product_id', 'order_id', 'item_id', 'seq', 'user_id', 'used_by' ) as $k ) {
			$r[ $k ] = (int) ( $r[ $k ] ?? 0 );
		}
		return $r;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );
		return $r ? self::cast( $r ) : null;
	}

	/** The ticket of a code, or null. */
	public static function by_code( string $code ): ?array {
		global $wpdb;
		$code = self::norm_code( $code );
		if ( '' === $code ) {
			return null;
		}
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, indexed.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE code_hash = %s", self::code_hash( $code ) ), ARRAY_A );
		foreach ( (array) $rows as $r ) {
			$r = self::cast( $r );
			if ( hash_equals( self::code_for( $r ), $code ) ) {
				return $r;
			}
		}
		return null;
	}

	/** @return array[] Rows of an order, by item and seq. */
	public static function for_order( int $order_id ): array {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE order_id = %d ORDER BY item_id, seq", $order_id ), ARRAY_A );
		return array_map( array( __CLASS__, 'cast' ), (array) $rows );
	}

	/** @return array[] Rows of an event (attendee list). */
	public static function for_event( int $event_id, bool $valid_only = false ): array {
		global $wpdb;
		$t   = self::table();
		$sql = $valid_only
			? $wpdb->prepare( "SELECT * FROM {$t} WHERE event_id = %d AND status = 'valid' ORDER BY order_id, item_id, seq", $event_id )
			: $wpdb->prepare( "SELECT * FROM {$t} WHERE event_id = %d ORDER BY order_id, item_id, seq", $event_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- own table, prepared above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return array_map( array( __CLASS__, 'cast' ), (array) $rows );
	}

	/** @return int[] Order ids with valid tickets of an event, after an order id (batches). */
	public static function orders_of_event( int $event_id, int $after, int $limit ): array {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM {$t} WHERE event_id = %d AND status = 'valid' AND order_id > %d ORDER BY order_id LIMIT %d", $event_id, $after, $limit ) );
		return array_map( 'intval', (array) $ids );
	}

	/** Totals of an event: valid, used, void. */
	public static function counts( int $event_id ): array {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(status = 'valid') AS valid, SUM(status = 'valid' AND used IS NOT NULL) AS used, SUM(status = 'void') AS void FROM {$t} WHERE event_id = %d", $event_id ), ARRAY_A );
		return array(
			'valid' => (int) ( $r['valid'] ?? 0 ),
			'used'  => (int) ( $r['used'] ?? 0 ),
			'void'  => (int) ( $r['void'] ?? 0 ),
		);
	}

	/** A new valid ticket row; returns its id (0 on failure). */
	public static function add( int $event_id, int $product_id, int $order_id, int $item_id, int $seq, int $user_id ): int {
		global $wpdb;
		$t  = self::table();
		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			$t,
			array(
				'event_id'   => $event_id,
				'product_id' => $product_id,
				'order_id'   => $order_id,
				'item_id'    => $item_id,
				'seq'        => $seq,
				'user_id'    => $user_id,
				'status'     => 'valid',
				'created'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		if ( ! $ok ) {
			return 0;
		}
		$id   = (int) $wpdb->insert_id;
		$code = self::code_for(
			array(
				'id'       => $id,
				'order_id' => $order_id,
				'item_id'  => $item_id,
				'seq'      => $seq,
			)
		);
		$wpdb->update( $t, array( 'code_hash' => self::code_hash( $code ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return $id;
	}

	public static function set_status( int $id, string $status ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => 'void' === $status ? 'void' : 'valid' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
	}

	/**
	 * Marks a valid, unused ticket as used, atomically: true only for the
	 * request that changed the row (two doors scanning at once).
	 */
	public static function use_ticket( int $id, int $by ): bool {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, conditional update.
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET used = %s, used_by = %d WHERE id = %d AND status = 'valid' AND used IS NULL", gmdate( 'Y-m-d H:i:s' ), $by, $id ) );
		return 1 === (int) $n;
	}

	/** Undo a check-in (admin, a scan by mistake). */
	public static function unuse( int $id ): void {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET used = NULL, used_by = 0 WHERE id = %d", $id ) );
	}

	public static function set_name( int $id, string $name ): void {
		global $wpdb;
		$name = mb_substr( trim( sanitize_text_field( $name ) ), 0, 120 );
		$wpdb->update( self::table(), array( 'name' => $name ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
	}

	/** Clears the attendee names of an event (Π27 lifetime, eraser). */
	public static function clear_names_of_event( int $event_id ): int {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET name = '' WHERE event_id = %d AND name <> ''", $event_id ) );
	}

	/** @return int[] Event ids that have attendee names stored. */
	public static function events_with_names(): array {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, no input.
		return array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT event_id FROM {$t} WHERE name <> ''" ) );
	}

	public static function clear_names_of_order( int $order_id ): int {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET name = '' WHERE order_id = %d AND name <> ''", $order_id ) );
	}

	/** @return array[] Rows of a user (My account → Tickets), newest order first. */
	public static function for_user( int $user_id, int $limit = 100 ): array {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d ORDER BY order_id DESC, item_id, seq LIMIT %d", $user_id, $limit ), ARRAY_A );
		return array_map( array( __CLASS__, 'cast' ), (array) $rows );
	}
}

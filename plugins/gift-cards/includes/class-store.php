<?php
/**
 * GFC_Store — cards and the append-only ledger (design §6).
 *
 * Rules every money movement follows:
 *  - Amounts are integer cents. A card has one currency.
 *  - balance = SUM(ledger.delta) for the card, always. Each movement runs
 *    in a transaction: the card row is locked (SELECT … FOR UPDATE on
 *    MySQL; SQLite serialises writers), the balance changes with a
 *    conditional UPDATE (… AND balance >= X for a debit), one ledger row
 *    is inserted, and the invariant is checked before COMMIT. A broken
 *    invariant rolls back, locks the card and tells the admin.
 *  - Ledger rows are never updated or deleted by the plugin.
 *  - Order redemptions are kept as a target per (card, order): the net
 *    amount the order took from the card. Setting a new target writes only
 *    the difference, so a checkout that runs twice for the same order, a
 *    retry or a restore can never take or give back twice.
 *  - One-off movements (an admin refund to a card, a void) carry a unique
 *    key (uniq), so a double submit writes nothing the second time.
 *
 * Statuses: pending (paid, waiting for its delivery date), review (waiting
 * for manual approval, E10), active, disabled (by the admin), void (the
 * purchase was refunded), expired, locked (the invariant failed).
 */

defined( 'ABSPATH' ) || exit;

final class GFC_Store {

	const STATUSES = array( 'pending', 'review', 'active', 'disabled', 'void', 'expired', 'locked' );

	/** Types that belong to an order's redemption (net per order). */
	const ORDER_TYPES = array( 'redeem', 'restore', 'refund' );

	/* =====================================================================
	 * Reads
	 * =================================================================== */

	public static function get( int $id ): ?array {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GFC_Schema::cards() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? self::cast( $row ) : null;
	}

	/** The card for a typed code (normalised + HMAC lookup), or null. */
	public static function find_by_code( string $typed ): ?array {
		global $wpdb;
		$code = GFC_Codes::normalize( $typed );
		if ( '' === $code ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GFC_Schema::cards() . ' WHERE code_hmac = %s', GFC_Codes::hmac( $code ) ), ARRAY_A );
		return $row ? self::cast( $row ) : null;
	}

	/** True when the card can pay now (status, balance, expiry, currency). */
	public static function usable( ?array $card, string $currency = '' ): bool {
		if ( ! $card || 'active' !== $card['status'] || $card['balance'] <= 0 ) {
			return false;
		}
		if ( self::has_date( $card['expires_at'] ) && strtotime( $card['expires_at'] . ' UTC' ) <= time() ) {
			return false;
		}
		$currency = '' !== $currency ? $currency : get_woocommerce_currency();
		return $card['currency'] === $currency;
	}

	/** A real datetime (not NULL, '' or a MySQL zero date). */
	public static function has_date( $value ): bool {
		return is_string( $value ) && '' !== $value && 0 !== strpos( $value, '0000-00-00' );
	}

	/** @return array<int,array> Ledger rows of a card, newest first. */
	public static function ledger( int $card_id, int $limit = 500 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . GFC_Schema::ledger() . ' WHERE card_id = %d ORDER BY id DESC LIMIT %d', $card_id, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Net cents an order took from a card (redeem − restore − refund). */
	public static function order_net( int $card_id, int $order_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, fixed type list.
		$sum = $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(delta) FROM ' . GFC_Schema::ledger() . " WHERE card_id = %d AND order_id = %d AND type IN ('redeem','restore','refund')", $card_id, $order_id ) );
		return -1 * (int) $sum;
	}

	/** Cents refunded to a card for an order. */
	public static function order_refunded( int $card_id, int $order_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(delta) FROM ' . GFC_Schema::ledger() . " WHERE card_id = %d AND order_id = %d AND type = 'refund'", $card_id, $order_id ) );
	}

	/** @return array<int,array> Cards issued from an order. */
	public static function by_order( int $order_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . GFC_Schema::cards() . " WHERE order_id = %d AND source = 'order' ORDER BY id", $order_id ), ARRAY_A );
		return array_map( array( __CLASS__, 'cast' ), is_array( $rows ) ? $rows : array() );
	}

	/** Ints as ints. */
	public static function cast( array $row ): array {
		foreach ( array( 'id', 'initial', 'balance', 'order_id', 'item_id', 'customer_id', 'for_me', 'reminded' ) as $k ) {
			if ( isset( $row[ $k ] ) ) {
				$row[ $k ] = (int) $row[ $k ];
			}
		}
		// '' or a MySQL zero date means "no date".
		foreach ( array( 'deliver_at', 'delivered_at', 'expires_at' ) as $k ) {
			if ( array_key_exists( $k, $row ) && ! self::has_date( $row[ $k ] ) ) {
				$row[ $k ] = null;
			}
		}
		return $row;
	}

	/* =====================================================================
	 * Issue
	 * =================================================================== */

	/**
	 * Creates a card and its issue movement in one transaction. Idempotent
	 * on issue_key: an existing card is returned with created = false.
	 *
	 * @param array $data Card columns (issue_key required).
	 * @return array{id:int,created:bool}|null
	 */
	public static function create( array $data, int $initial, string $note = '', int $user_id = 0 ): ?array {
		global $wpdb;
		if ( $initial <= 0 || empty( $data['issue_key'] ) ) {
			return null;
		}
		$table = GFC_Schema::cards();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$have = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE issue_key = %s", $data['issue_key'] ) );
		if ( $have ) {
			return array(
				'id'      => $have,
				'created' => false,
			);
		}
		$now  = current_time( 'mysql', true );
		$row  = array_merge(
			array(
				'code_hmac'  => null,
				'currency'   => get_woocommerce_currency(),
				'status'     => 'pending',
				'source'     => 'order',
				'created_at' => $now,
				'updated_at' => $now,
			),
			$data,
			array(
				'initial' => $initial,
				'balance' => $initial,
			)
		);
		$allow = array( 'issue_key', 'code_hmac', 'last4', 'initial', 'balance', 'currency', 'status', 'source', 'order_id', 'item_id', 'customer_id', 'buyer_email', 'for_me', 'recipient_name', 'recipient_email', 'sender_name', 'message', 'lang', 'deliver_at', 'delivered_at', 'expires_at', 'created_at', 'updated_at' );
		$row   = array_intersect_key( $row, array_flip( $allow ) );

		self::begin();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$ok = $wpdb->insert( $table, $row );
		if ( ! $ok ) {
			self::rollback();
			// A parallel request inserted the same issue_key first.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			$have = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE issue_key = %s", $data['issue_key'] ) );
			return $have ? array(
				'id'      => $have,
				'created' => false,
			) : null;
		}
		$id = (int) $wpdb->insert_id;
		if ( ! self::insert_ledger( $id, $initial, $initial, 'issue', (int) ( $data['order_id'] ?? 0 ), 0, $user_id, $note, 'issue:' . $id ) ) {
			self::rollback();
			return null;
		}
		self::commit();
		return array(
			'id'      => $id,
			'created' => true,
		);
	}

	/* =====================================================================
	 * Movements
	 * =================================================================== */

	/**
	 * One atomic movement.
	 *
	 * @param array $ctx {
	 *   @type int    $order_id
	 *   @type int    $refund_id
	 *   @type int    $user_id
	 *   @type string $note
	 *   @type string $uniq        Unique key (idempotent one-off movements).
	 *   @type bool   $need_active Debits from redemption need an active card.
	 *   @type string $status      New status to set with the movement.
	 * }
	 * @return array{ok:bool,error:string,balance:int}
	 */
	public static function move( int $card_id, int $delta, string $type, array $ctx = array() ): array {
		if ( ! empty( $ctx['uniq'] ) && self::uniq_exists( (string) $ctx['uniq'] ) ) {
			$card = self::get( $card_id );
			return self::res( true, 'duplicate', $card ? $card['balance'] : 0 );
		}
		self::begin();
		$card = self::lock_row( $card_id );
		if ( ! $card ) {
			self::rollback();
			return self::res( false, 'missing', 0 );
		}
		return self::apply_locked( $card, $delta, $type, $ctx );
	}

	/**
	 * Sets how much an order takes from a card (target, in cents) and writes
	 * only the difference: redeem when the target is higher than what the
	 * order already took, restore when it is lower. The net is read after
	 * the card row is locked, so two parallel calls for the same order
	 * cannot both take the difference.
	 *
	 * @return array{ok:bool,error:string,balance:int}
	 */
	public static function set_order_amount( int $card_id, int $order_id, int $target, string $note = '' ): array {
		$target = max( 0, $target );
		self::begin();
		$card = self::lock_row( $card_id );
		if ( ! $card ) {
			self::rollback();
			return self::res( false, 'missing', 0 );
		}
		$diff = $target - self::order_net( $card_id, $order_id );
		if ( 0 === $diff ) {
			self::commit();
			return self::res( true, '', $card['balance'] );
		}
		return self::apply_locked(
			$card,
			-$diff,
			$diff > 0 ? 'redeem' : 'restore',
			array(
				'order_id'    => $order_id,
				'note'        => $note,
				'need_active' => $diff > 0,
			)
		);
	}

	/**
	 * The movement itself, inside the open transaction with the card row
	 * locked. Always closes the transaction (commit or rollback).
	 */
	private static function apply_locked( array $card, int $delta, string $type, array $ctx ): array {
		global $wpdb;
		$table   = GFC_Schema::cards();
		$card_id = $card['id'];

		if ( 'locked' === $card['status'] ) {
			self::rollback();
			return self::res( false, 'locked', $card['balance'] );
		}
		if ( ! empty( $ctx['need_active'] ) && $delta < 0 && ! self::usable( $card, $card['currency'] ) ) {
			self::rollback();
			return self::res( false, 'unusable', $card['balance'] );
		}

		if ( ! empty( $ctx['cap_order'] ) && $delta > self::order_net( $card_id, (int) ( $ctx['order_id'] ?? 0 ) ) ) {
			self::rollback();
			return self::res( false, 'over', $card['balance'] );
		}

		$now = current_time( 'mysql', true );
		$set = 'balance = balance + %d, updated_at = %s';
		$arg = array( $delta, $now );
		if ( ! empty( $ctx['status'] ) && in_array( $ctx['status'], self::STATUSES, true ) ) {
			$set  .= ', status = %s';
			$arg[] = $ctx['status'];
		}
		$arg[] = $card_id;
		$where = 'id = %d';
		if ( $delta < 0 ) {
			$where .= ' AND balance >= %d';
			$arg[]  = -$delta;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- own table, placeholders built above.
		$n = $wpdb->query( $wpdb->prepare( "UPDATE $table SET $set WHERE $where", $arg ) );
		if ( 1 !== (int) $n ) {
			self::rollback();
			return self::res( false, 'balance', $card['balance'] );
		}
		$after = $card['balance'] + $delta;
		$ok    = self::insert_ledger(
			$card_id,
			$delta,
			$after,
			$type,
			(int) ( $ctx['order_id'] ?? 0 ),
			(int) ( $ctx['refund_id'] ?? 0 ),
			(int) ( $ctx['user_id'] ?? 0 ),
			(string) ( $ctx['note'] ?? '' ),
			! empty( $ctx['uniq'] ) ? (string) $ctx['uniq'] : null
		);
		if ( ! $ok ) {
			self::rollback();
			// A parallel request wrote the same one-off movement first.
			$dup = ! empty( $ctx['uniq'] ) && self::uniq_exists( (string) $ctx['uniq'] );
			return self::res( $dup, $dup ? 'duplicate' : 'ledger', $card['balance'] );
		}
		if ( ! self::invariant_holds( $card_id, $after ) ) {
			self::rollback();
			self::lock_card( $card_id, 'invariant after ' . $type );
			return self::res( false, 'invariant', $card['balance'] );
		}
		self::commit();
		return self::res( true, '', $after );
	}

	/**
	 * Delivers a pending card: creates its code, stores only the HMAC and
	 * activates it. Returns the plain code once, or null when the card was
	 * not pending (already delivered by a parallel run, voided, in review).
	 */
	public static function deliver_code( int $card_id, string $expires_at = '' ): ?string {
		global $wpdb;
		$table = GFC_Schema::cards();
		for ( $try = 0; $try < 3; $try++ ) {
			$code = GFC_Codes::generate();
			$now  = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			// prepare() turns null into '', so "no expiry" is written as a literal NULL.
			$exp_sql = '' !== $expires_at ? $wpdb->prepare( '%s', $expires_at ) : 'NULL';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table; $exp_sql is prepared above or the literal NULL.
			$n = $wpdb->query(
				$wpdb->prepare(
					"UPDATE $table SET code_hmac = %s, last4 = %s, status = 'active', delivered_at = %s, expires_at = $exp_sql, updated_at = %s WHERE id = %d AND status = 'pending' AND code_hmac IS NULL",
					GFC_Codes::hmac( $code ),
					GFC_Codes::last4( $code ),
					$now,
					$now,
					$card_id
				)
			);
			if ( 1 === (int) $n ) {
				self::note( $card_id, 'status', __( 'Παραδόθηκε: δημιουργήθηκε ο κωδικός.', 'gift-cards' ) );
				return $code;
			}
			$card = self::get( $card_id );
			if ( ! $card || 'pending' !== $card['status'] || null !== $card['code_hmac'] ) {
				return null;
			}
			// Else: an HMAC collision (never in practice) — try another code.
		}
		return null;
	}

	/**
	 * A new code for a delivered card ("new code and resend", E3): the old
	 * code stops working at once, the balance stays.
	 */
	public static function replace_code( int $card_id, int $user_id ): ?string {
		global $wpdb;
		$table = GFC_Schema::cards();
		$card  = self::get( $card_id );
		if ( ! $card || ! in_array( $card['status'], array( 'active', 'disabled' ), true ) || null === $card['code_hmac'] ) {
			return null;
		}
		$code = GFC_Codes::generate();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$n = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET code_hmac = %s, last4 = %s, updated_at = %s WHERE id = %d AND code_hmac = %s",
				GFC_Codes::hmac( $code ),
				GFC_Codes::last4( $code ),
				current_time( 'mysql', true ),
				$card_id,
				$card['code_hmac']
			)
		);
		if ( 1 !== (int) $n ) {
			return null;
		}
		/* translators: 1: old last 4, 2: new last 4 */
		self::note( $card_id, 'recode', sprintf( __( 'Νέος κωδικός: ο %1$s ακυρώθηκε, νέος %2$s.', 'gift-cards' ), GFC_Codes::mask( $card['last4'] ), GFC_Codes::mask( GFC_Codes::last4( $code ) ) ), $user_id );
		return $code;
	}

	/** Changes the status (with a zero movement in the ledger, for the history). */
	public static function set_status( int $card_id, string $status, string $note, int $user_id = 0, string $from = '' ): bool {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		$table = GFC_Schema::cards();
		$sql   = "UPDATE $table SET status = %s, updated_at = %s WHERE id = %d";
		$args  = array( $status, current_time( 'mysql', true ), $card_id );
		if ( '' !== $from ) {
			$sql   .= ' AND status = %s';
			$args[] = $from;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- own table, prepared here.
		$n = $wpdb->query( $wpdb->prepare( $sql, $args ) );
		if ( 1 !== (int) $n ) {
			return false;
		}
		self::note( $card_id, 'status', $note, $user_id );
		return true;
	}

	/** Updates delivery fields (only while the card is pending or in review). */
	public static function update_delivery( int $card_id, array $fields ): bool {
		global $wpdb;
		$allow = array_intersect_key( $fields, array_flip( array( 'recipient_name', 'recipient_email', 'sender_name', 'message', 'deliver_at' ) ) );
		if ( ! $allow ) {
			return false;
		}
		$allow['updated_at'] = current_time( 'mysql', true );
		$card                = self::get( $card_id );
		if ( ! $card || ! in_array( $card['status'], array( 'pending', 'review' ), true ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return false !== $wpdb->update( GFC_Schema::cards(), $allow, array( 'id' => $card_id ) );
	}

	/** A zero movement that records an event in the card history. */
	public static function note( int $card_id, string $type, string $note, int $user_id = 0 ): void {
		$card = self::get( $card_id );
		if ( $card ) {
			self::insert_ledger( $card_id, 0, $card['balance'], $type, 0, 0, $user_id, $note, null );
		}
	}

	/* =====================================================================
	 * Invariants
	 * =================================================================== */

	public static function invariant_holds( int $card_id, int $balance ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$sum = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(delta) FROM ' . GFC_Schema::ledger() . ' WHERE card_id = %d', $card_id ) );
		return $sum === $balance && $balance >= 0;
	}

	/**
	 * Cards whose balance does not equal the ledger sum, or is negative.
	 *
	 * @return array<int,array{id:int,balance:int,ledger:int}>
	 */
	public static function broken(): array {
		global $wpdb;
		$c = GFC_Schema::cards();
		$l = GFC_Schema::ledger();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own tables, no input.
		$rows = $wpdb->get_results( "SELECT c.id, c.balance, COALESCE(SUM(l.delta), 0) AS ledger FROM $c c LEFT JOIN $l l ON l.card_id = c.id GROUP BY c.id, c.balance HAVING c.balance <> COALESCE(SUM(l.delta), 0) OR c.balance < 0", ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$out[] = array(
				'id'      => (int) $r['id'],
				'balance' => (int) $r['balance'],
				'ledger'  => (int) $r['ledger'],
			);
		}
		return $out;
	}

	/** Locks a card after a failed check and tells the admin (once per card). */
	public static function lock_card( int $card_id, string $why ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . GFC_Schema::cards() . " SET status = 'locked', updated_at = %s WHERE id = %d AND status <> 'locked'", current_time( 'mysql', true ), $card_id ) );
		if ( 1 === (int) $n ) {
			self::note( $card_id, 'status', 'Locked: ' . $why );
			GFC_Emails::admin_alert(
				__( 'Gift Cards: μια δωροκάρτα κλείδωσε', 'gift-cards' ),
				/* translators: %d: card id */
				sprintf( __( 'Η δωροκάρτα #%d κλείδωσε γιατί το υπόλοιπό της δεν συμφωνεί με το ημερολόγιο κινήσεων. Δεν μπορεί να χρησιμοποιηθεί μέχρι να τη δεις.', 'gift-cards' ), $card_id ),
				admin_url( 'admin.php?page=gfc-cards&card=' . $card_id )
			);
		}
	}

	/* =====================================================================
	 * Internals
	 * =================================================================== */

	private static function res( bool $ok, string $error, int $balance ): array {
		return array(
			'ok'      => $ok,
			'error'   => $error,
			'balance' => $balance,
		);
	}

	private static function uniq_exists( string $uniq ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . GFC_Schema::ledger() . ' WHERE uniq = %s', $uniq ) );
	}

	private static function insert_ledger( int $card_id, int $delta, int $after, string $type, int $order_id, int $refund_id, int $user_id, string $note, ?string $uniq ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return (bool) $wpdb->insert(
			GFC_Schema::ledger(),
			array(
				'card_id'       => $card_id,
				'delta'         => $delta,
				'balance_after' => $after,
				'type'          => substr( $type, 0, 10 ),
				'order_id'      => $order_id,
				'refund_id'     => $refund_id,
				'user_id'       => $user_id,
				'note'          => mb_substr( $note, 0, 255 ),
				'uniq'          => $uniq,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%s', null === $uniq ? null : '%s', '%s' )
		);
	}

	private static function lock_row( int $card_id ): ?array {
		global $wpdb;
		$lock = self::is_sqlite() ? '' : ' FOR UPDATE';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GFC_Schema::cards() . ' WHERE id = %d' . $lock, $card_id ), ARRAY_A );
		return $row ? self::cast( $row ) : null;
	}

	private static function is_sqlite(): bool {
		return ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) || class_exists( 'WP_SQLite_DB', false );
	}

	/** @var int Transaction depth (nested calls share the outer one). */
	private static $depth = 0;

	private static function begin(): void {
		global $wpdb;
		if ( 0 === self::$depth ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transaction control.
			$wpdb->query( 'START TRANSACTION' );
		}
		++self::$depth;
	}

	private static function commit(): void {
		global $wpdb;
		self::$depth = max( 0, self::$depth - 1 );
		if ( 0 === self::$depth ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transaction control.
			$wpdb->query( 'COMMIT' );
		}
	}

	private static function rollback(): void {
		global $wpdb;
		self::$depth = max( 0, self::$depth - 1 );
		if ( 0 === self::$depth ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transaction control.
			$wpdb->query( 'ROLLBACK' );
		}
	}
}

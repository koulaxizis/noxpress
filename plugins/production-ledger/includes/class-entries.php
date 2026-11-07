<?php
/**
 * PL_Entries — table {prefix}pl_entries: one row per cost, general expense
 * or off-site income line, typed from a supplier invoice (by hand, by an
 * ability in a write window) or made by a module (Printful).
 *
 * Kinds of rows:
 *  - product_id > 0                  a cost (or off-site income) of one product
 *  - product_id = 0, pool ≠ ''       a pooled cost (shipping of a carrier,
 *                                    consumables, a payment provider's fees)
 *                                    spread over that month's orders
 *  - product_id = 0, pool = ''       a general expense (never allocated)
 *
 * Amounts are net (excl. VAT) with the VAT in its own column, in the shop
 * currency only. Deleting is soft (deleted_at) and can be undone; nothing
 * is ever removed for good except test rows by the explicit «purge test
 * rows» tool. Rows made by a module carry ext_ref (unique among live
 * rows): the same source updates its row and never makes a second one.
 *
 * Schema version in option pl_db; dbDelta on activation and on admin_init
 * when the version changes (Bible §12).
 */

defined( 'ABSPATH' ) || exit;

final class PL_Entries {

	const DB_VERSION = '1';
	const MAX_BATCH  = 100;

	/** Category slug => [ Greek label (msgid), kind cost|income, allowed for product|pool|general ]. */
	public static function categories(): array {
		return array(
			'printing'     => array( __( 'Εκτύπωση', 'production-ledger' ), 'cost' ),
			'editing'      => array( __( 'Επιμέλεια/διόρθωση', 'production-ledger' ), 'cost' ),
			'design'       => array( __( 'Σχέδιο/εικονογράφηση', 'production-ledger' ), 'cost' ),
			'sound'        => array( __( 'Ήχος/mastering', 'production-ledger' ), 'cost' ),
			'isbn'         => array( __( 'ISBN/άδειες', 'production-ledger' ), 'cost' ),
			'shipping'     => array( __( 'Μεταφορικά', 'production-ledger' ), 'cost' ),
			'consumables'  => array( __( 'Αναλώσιμα', 'production-ledger' ), 'cost' ),
			'promotion'    => array( __( 'Προώθηση', 'production-ledger' ), 'cost' ),
			'events'       => array( __( 'Εκδηλώσεις', 'production-ledger' ), 'cost' ),
			'payment_fees' => array( __( 'Προμήθειες πληρωμών', 'production-ledger' ), 'cost' ),
			'creators'     => array( __( 'Δημιουργοί', 'production-ledger' ), 'cost' ),
			'general'      => array( __( 'Γενικό έξοδο', 'production-ledger' ), 'cost' ),
			'offsite'      => array( __( 'Έσοδα εκτός site', 'production-ledger' ), 'income' ),
		);
	}

	public static function label( string $cat ): string {
		$c = self::categories();
		return isset( $c[ $cat ] ) ? $c[ $cat ][0] : $cat;
	}

	/** Categories that can be pooled, and the pool keys each accepts. */
	public static function pools(): array {
		$s   = PL_Settings::get();
		$out = array(
			'shipping'     => array(),
			'consumables'  => array( 'all' => __( 'Όλες οι αποστολές του μήνα', 'production-ledger' ) ),
			'payment_fees' => array(),
		);
		foreach ( $s['carriers'] as $k => $g ) {
			$out['shipping'][ $k ] = $g['label'];
		}
		foreach ( $s['pay'] as $k => $g ) {
			$out['payment_fees'][ $k ] = $g['label'];
		}
		return $out;
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pl_entries';
	}

	public static function install(): void {
		if ( self::DB_VERSION === get_option( 'pl_db' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_date date NOT NULL,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  category varchar(20) NOT NULL,
  pool varchar(20) NOT NULL DEFAULT '',
  supplier varchar(120) NOT NULL DEFAULT '',
  invoice_no varchar(60) NOT NULL DEFAULT '',
  description varchar(255) NOT NULL DEFAULT '',
  net_amount decimal(12,2) NOT NULL DEFAULT 0,
  vat_amount decimal(12,2) NOT NULL DEFAULT 0,
  vat_rate decimal(5,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT 'EUR',
  qty_basis int(10) unsigned DEFAULT NULL,
  attachment_url varchar(500) NOT NULL DEFAULT '',
  source varchar(10) NOT NULL DEFAULT 'manual',
  ext_ref varchar(80) NOT NULL DEFAULT '',
  is_test tinyint(1) NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY entry_date (entry_date),
  KEY product_id (product_id),
  KEY category (category),
  KEY ext_ref (ext_ref),
  KEY updated_at (updated_at)
) {$charset};"
		);
		update_option( 'pl_db', self::DB_VERSION, false );
	}

	/* =====================================================================
	 * Validation
	 * =================================================================== */

	/**
	 * A clean row from input, or WP_Error with an English message for
	 * abilities (the admin shows its own Greek text per code).
	 *
	 * @param array      $in  Fields: date, product_id, category, pool, supplier,
	 *                        invoice_no, description, net, vat, vat_rate,
	 *                        qty_basis, attachment_url, test.
	 * @param array|null $old The row being updated (fields not in $in stay).
	 * @return array|WP_Error
	 */
	public static function validate( array $in, ?array $old = null ) {
		$get = static function ( string $k, $def ) use ( $in, $old ) {
			if ( array_key_exists( $k, $in ) ) {
				return $in[ $k ];
			}
			return null !== $old && array_key_exists( $k, $old ) ? $old[ $k ] : $def;
		};

		$date = (string) $get( 'date', '' );
		if ( ! PL_Settings::valid_date( $date ) ) {
			return new WP_Error( 'pl_date', 'date must be YYYY-MM-DD.' );
		}
		if ( $date < PL_Settings::start() ) {
			return new WP_Error( 'pl_early', 'date is before the ledger start date (' . PL_Settings::start() . ').' );
		}
		$today = wp_date( 'Y-m-d' );
		if ( $date > gmdate( 'Y-m-d', strtotime( $today . ' +366 days' ) ) ) {
			return new WP_Error( 'pl_late', 'date is more than a year ahead.' );
		}

		$cat  = sanitize_key( (string) $get( 'category', '' ) );
		$cats = self::categories();
		if ( ! isset( $cats[ $cat ] ) ) {
			return new WP_Error( 'pl_cat', 'category must be one of: ' . implode( ', ', array_keys( $cats ) ) . '.' );
		}

		$pid  = max( 0, (int) $get( 'product_id', 0 ) );
		$pool = sanitize_key( (string) $get( 'pool', '' ) );
		if ( $pid > 0 ) {
			$p = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
			if ( ! $p instanceof WC_Product ) {
				return new WP_Error( 'pl_product', 'product_id is not a product of this shop.' );
			}
			if ( $p instanceof WC_Product_Variation ) {
				$pid = (int) $p->get_parent_id(); // The unit is the product, not its variation.
			}
			$pool = '';
			if ( 'general' === $cat ) {
				return new WP_Error( 'pl_general', 'category general is for general expenses (product_id 0).' );
			}
		} else {
			if ( 'offsite' === $cat ) {
				return new WP_Error( 'pl_offsite', 'off-site income needs a product_id.' );
			}
			if ( '' !== $pool ) {
				$pools = self::pools();
				if ( ! isset( $pools[ $cat ] ) || ! isset( $pools[ $cat ][ $pool ] ) ) {
					return new WP_Error( 'pl_pool', 'pool is not valid for this category (shipping: a carrier key; consumables: all; payment_fees: a payment group key).' );
				}
			}
		}

		$net = self::money( $get( 'net', null ) );
		if ( null === $net ) {
			return new WP_Error( 'pl_amount', 'net must be an amount ≥ 0 with at most 2 decimals.' );
		}
		$rate = $get( 'vat_rate', null );
		$rate = null === $rate || '' === $rate ? null : (float) str_replace( ',', '.', (string) $rate );
		if ( null !== $rate && ( $rate < 0 || $rate > 100 ) ) {
			return new WP_Error( 'pl_rate', 'vat_rate must be 0–100.' );
		}
		$vat = $get( 'vat', null );
		$vat = null === $vat || '' === $vat ? null : self::money( $vat );
		if ( array_key_exists( 'vat', $in ) && '' !== $in['vat'] && null !== $in['vat'] && null === $vat ) {
			return new WP_Error( 'pl_vat', 'vat must be an amount ≥ 0.' );
		}
		if ( null === $vat ) {
			$vat = null === $rate ? 0.0 : round( $net * $rate / 100, 2 );
		}
		if ( null === $rate ) {
			$rate = $net > 0 ? round( $vat / $net * 100, 2 ) : 0.0;
		}

		$qty = $get( 'qty_basis', null );
		$qty = null === $qty || '' === $qty ? null : (int) $qty;
		if ( null !== $qty && ( $qty < 0 || $qty > 1000000 ) ) {
			return new WP_Error( 'pl_qty', 'qty_basis must be 0–1000000.' );
		}

		$url = trim( (string) $get( 'attachment_url', '' ) );
		if ( '' !== $url ) {
			$url = esc_url_raw( $url, array( 'https', 'http' ) );
			if ( '' === $url || strlen( $url ) > 500 ) {
				return new WP_Error( 'pl_url', 'attachment_url must be an http(s) address (≤ 500 characters).' );
			}
		}

		return array(
			'entry_date'     => $date,
			'product_id'     => $pid,
			'category'       => $cat,
			'pool'           => $pool,
			'supplier'       => mb_substr( trim( sanitize_text_field( (string) $get( 'supplier', '' ) ) ), 0, 120 ),
			'invoice_no'     => mb_substr( trim( sanitize_text_field( (string) $get( 'invoice_no', '' ) ) ), 0, 60 ),
			'description'    => mb_substr( trim( sanitize_text_field( (string) $get( 'description', '' ) ) ), 0, 255 ),
			'net_amount'     => $net,
			'vat_amount'     => (float) $vat,
			'vat_rate'       => (float) $rate,
			'currency'       => self::currency(),
			'qty_basis'      => $qty,
			'attachment_url' => $url,
			'is_test'        => ! empty( $get( 'test', ! empty( $old['is_test'] ) ) ) ? 1 : 0,
		);
	}

	/** A non-negative amount with ≤ 2 decimals, or null. Accepts "12,50". */
	public static function money( $v ): ?float {
		if ( is_int( $v ) || is_float( $v ) ) {
			$s = number_format( (float) $v, 6, '.', '' );
		} else {
			$s = str_replace( ' ', '', trim( (string) $v ) );
			// Greek format: 1.234,56 → 1234.56 (a dot is a thousands mark only when a comma is present).
			if ( false !== strpos( $s, ',' ) ) {
				$s = str_replace( array( '.', ',' ), array( '', '.' ), $s );
			}
		}
		if ( ! preg_match( '/^\d{1,10}(\.\d+)?$/', $s ) ) {
			return null;
		}
		$f = (float) $s;
		if ( abs( round( $f, 2 ) - $f ) > 0.000001 ) {
			return null;
		}
		return round( $f, 2 );
	}

	public static function currency(): string {
		return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'EUR';
	}

	/* =====================================================================
	 * Writes
	 * =================================================================== */

	/**
	 * Adds one row.
	 *
	 * @param array  $in      See validate().
	 * @param string $source  manual | ability | auto.
	 * @param string $ext_ref Unique source reference for module rows ('' = none).
	 * @return int|WP_Error Row id.
	 */
	public static function add( array $in, string $source = 'manual', string $ext_ref = '' ) {
		$row = self::validate( $in );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		global $wpdb;
		$now                = current_time( 'mysql', true );
		$row['source']      = in_array( $source, array( 'manual', 'ability', 'auto' ), true ) ? $source : 'manual';
		$row['ext_ref']     = substr( preg_replace( '/[^A-Za-z0-9:_\-.]/', '', $ext_ref ), 0, 80 );
		$row['created_by']  = get_current_user_id();
		$row['created_at']  = $now;
		$row['updated_at']  = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
		$ok = $wpdb->insert( self::table(), $row );
		if ( ! $ok ) {
			return new WP_Error( 'pl_db', 'The entry could not be saved.' );
		}
		$id = (int) $wpdb->insert_id;
		self::changed( array( $id ) );
		return $id;
	}

	/**
	 * Updates a live row (fields not given stay).
	 *
	 * @return array|WP_Error The row after the change.
	 */
	public static function update( int $id, array $in ) {
		$old = self::get( $id );
		if ( ! $old || null !== $old['deleted_at'] ) {
			return new WP_Error( 'pl_missing', 'No live entry with this id.' );
		}
		$map = array(
			'date'           => $old['entry_date'],
			'product_id'     => $old['product_id'],
			'category'       => $old['category'],
			'pool'           => $old['pool'],
			'supplier'       => $old['supplier'],
			'invoice_no'     => $old['invoice_no'],
			'description'    => $old['description'],
			'net'            => $old['net_amount'],
			'vat_rate'       => $old['vat_rate'],
			'qty_basis'      => $old['qty_basis'],
			'attachment_url' => $old['attachment_url'],
			'test'           => $old['is_test'],
		);
		// A new net or rate without a new VAT amount recomputes the VAT.
		if ( ! array_key_exists( 'vat', $in ) && ! array_key_exists( 'net', $in ) && ! array_key_exists( 'vat_rate', $in ) ) {
			$map['vat'] = $old['vat_amount'];
		}
		$row = self::validate( $in, $map );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		global $wpdb;
		$row['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
		$wpdb->update( self::table(), $row, array( 'id' => $id ) );
		self::changed( array( $id ) );
		return self::get( $id );
	}

	/** Soft delete (undo with restore()). */
	public static function delete( int $id ): bool {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET deleted_at = %s, updated_at = %s WHERE id = %d AND deleted_at IS NULL', $now, $now, $id ) );
		if ( $n ) {
			self::changed( array( $id ) );
		}
		return (bool) $n;
	}

	public static function restore( int $id ): bool {
		$row = self::get( $id );
		if ( ! $row || null === $row['deleted_at'] ) {
			return false;
		}
		// A module row whose source made a new row meanwhile stays deleted.
		if ( '' !== $row['ext_ref'] && self::by_ref( $row['ext_ref'] ) ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET deleted_at = NULL, updated_at = %s WHERE id = %d', current_time( 'mysql', true ), $id ) );
		if ( $n ) {
			self::changed( array( $id ) );
		}
		return (bool) $n;
	}

	/**
	 * Module rows: adds the row for ext_ref, or updates the live one.
	 *
	 * @return int|WP_Error
	 */
	public static function upsert_ref( string $ext_ref, array $in ) {
		$live = self::by_ref( $ext_ref );
		if ( $live ) {
			$r = self::update( (int) $live['id'], $in );
			return is_wp_error( $r ) ? $r : (int) $live['id'];
		}
		return self::add( $in, 'auto', $ext_ref );
	}

	/** Deletes the test rows for good. @return int Rows removed. */
	public static function purge_test(): int {
		global $wpdb;
		$ids = array_map( 'intval', (array) $wpdb->get_col( 'SELECT id FROM ' . self::table() . ' WHERE is_test = 1' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $ids ) {
			return 0;
		}
		$wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE is_test = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		self::changed( $ids );
		return count( $ids );
	}

	private static function changed( array $ids ): void {
		PL_Report::bump();
		/**
		 * After entries were added, changed, deleted or restored.
		 *
		 * @param int[] $ids Entry ids.
		 */
		do_action( 'pl_entries_changed', $ids );
	}

	/* =====================================================================
	 * Reads
	 * =================================================================== */

	private static function cast( array $r ): array {
		return array(
			'id'             => (int) $r['id'],
			'entry_date'     => (string) $r['entry_date'],
			'product_id'     => (int) $r['product_id'],
			'category'       => (string) $r['category'],
			'pool'           => (string) $r['pool'],
			'supplier'       => (string) $r['supplier'],
			'invoice_no'     => (string) $r['invoice_no'],
			'description'    => (string) $r['description'],
			'net_amount'     => round( (float) $r['net_amount'], 2 ),
			'vat_amount'     => round( (float) $r['vat_amount'], 2 ),
			'vat_rate'       => round( (float) $r['vat_rate'], 2 ),
			'currency'       => (string) $r['currency'],
			'qty_basis'      => null === $r['qty_basis'] ? null : (int) $r['qty_basis'],
			'attachment_url' => (string) $r['attachment_url'],
			'source'         => (string) $r['source'],
			'ext_ref'        => (string) $r['ext_ref'],
			'is_test'        => (int) $r['is_test'],
			'created_by'     => (int) $r['created_by'],
			'created_at'     => (string) $r['created_at'],
			'updated_at'     => (string) $r['updated_at'],
			'deleted_at'     => null === $r['deleted_at'] ? null : (string) $r['deleted_at'],
		);
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return is_array( $r ) ? self::cast( $r ) : null;
	}

	public static function by_ref( string $ext_ref ): ?array {
		if ( '' === $ext_ref ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE ext_ref = %s AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', $ext_ref ), ARRAY_A );
		return is_array( $r ) ? self::cast( $r ) : null;
	}

	/**
	 * Rows by filters.
	 *
	 * @param array $f from, to (Y-m-d), product_id (int|null), scope
	 *                 (product | pool | general | ''), category, deleted
	 *                 (bool: only deleted), test (null all | bool), search,
	 *                 changed_after (mysql GMT), limit, offset.
	 * @return array{rows: array[], total: int}
	 */
	public static function query( array $f = array() ): array {
		global $wpdb;
		$w    = array( '1=1' );
		$args = array();
		if ( ! empty( $f['from'] ) && PL_Settings::valid_date( (string) $f['from'] ) ) {
			$w[]    = 'entry_date >= %s';
			$args[] = (string) $f['from'];
		}
		if ( ! empty( $f['to'] ) && PL_Settings::valid_date( (string) $f['to'] ) ) {
			$w[]    = 'entry_date <= %s';
			$args[] = (string) $f['to'];
		}
		if ( isset( $f['product_id'] ) && null !== $f['product_id'] && '' !== $f['product_id'] ) {
			$w[]    = 'product_id = %d';
			$args[] = (int) $f['product_id'];
		}
		$scope = isset( $f['scope'] ) ? (string) $f['scope'] : '';
		if ( 'product' === $scope ) {
			$w[] = 'product_id > 0';
		} elseif ( 'pool' === $scope ) {
			$w[] = "product_id = 0 AND pool <> ''";
		} elseif ( 'general' === $scope ) {
			$w[] = "product_id = 0 AND pool = ''";
		} elseif ( 'costs' === $scope ) {
			$w[] = "NOT ( product_id = 0 AND pool = '' )";
		}
		if ( ! empty( $f['category'] ) && isset( self::categories()[ (string) $f['category'] ] ) ) {
			$w[]    = 'category = %s';
			$args[] = (string) $f['category'];
		}
		if ( ! empty( $f['all'] ) ) {
			// Live and deleted rows (the orOS feed needs both).
		} elseif ( ! empty( $f['deleted'] ) ) {
			$w[] = 'deleted_at IS NOT NULL';
		} else {
			$w[] = 'deleted_at IS NULL';
		}
		if ( isset( $f['test'] ) && null !== $f['test'] ) {
			$w[]    = 'is_test = %d';
			$args[] = $f['test'] ? 1 : 0;
		}
		if ( ! empty( $f['search'] ) ) {
			$like   = '%' . $wpdb->esc_like( (string) $f['search'] ) . '%';
			$w[]    = '( supplier LIKE %s OR description LIKE %s OR invoice_no LIKE %s )';
			$args[] = $like;
			$args[] = $like;
			$args[] = $like;
		}
		if ( ! empty( $f['changed_after'] ) ) {
			$w[]    = 'updated_at > %s';
			$args[] = (string) $f['changed_after'];
		}
		$limit  = isset( $f['limit'] ) ? max( 1, min( 5000, (int) $f['limit'] ) ) : 200;
		$offset = isset( $f['offset'] ) ? max( 0, (int) $f['offset'] ) : 0;
		$where  = implode( ' AND ', $w );
		$t      = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders -- our own table; $where holds only fixed SQL with placeholders.
		$count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
		$rows_sql  = "SELECT * FROM {$t} WHERE {$where} ORDER BY entry_date DESC, id DESC LIMIT %d OFFSET %d";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );
		$raw       = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $args, array( $limit, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		$rows = array();
		foreach ( is_array( $raw ) ? $raw : array() as $r ) {
			$rows[] = self::cast( $r );
		}
		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/** Every live non-test row in a date range (for the report). */
	public static function in_range( string $from, string $to ): array {
		return self::query(
			array(
				'from'  => $from,
				'to'    => $to,
				'test'  => false,
				'limit' => 5000,
			)
		)['rows'];
	}

	/** A row for the outside (abilities, CSV): no user ids. */
	public static function out( array $r ): array {
		$title = $r['product_id'] > 0 ? get_the_title( $r['product_id'] ) : '';
		return array(
			'id'             => $r['id'],
			'date'           => $r['entry_date'],
			'product_id'     => $r['product_id'],
			'product'        => html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' ),
			'scope'          => $r['product_id'] > 0 ? 'product' : ( '' !== $r['pool'] ? 'pool' : 'general' ),
			'category'       => $r['category'],
			'pool'           => $r['pool'],
			'supplier'       => $r['supplier'],
			'invoice_no'     => $r['invoice_no'],
			'description'    => $r['description'],
			'net'            => $r['net_amount'],
			'vat'            => $r['vat_amount'],
			'vat_rate'       => $r['vat_rate'],
			'currency'       => $r['currency'],
			'qty_basis'      => $r['qty_basis'],
			'attachment_url' => $r['attachment_url'],
			'source'         => $r['source'],
			'test'           => (bool) $r['is_test'],
			'deleted'        => null !== $r['deleted_at'],
			'updated'        => $r['updated_at'],
		);
	}
}

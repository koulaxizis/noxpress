<?php
/**
 * WSH_Store — the lists of signed-in customers ({prefix}wsh_items and
 * {prefix}wsh_lists), the share tokens and the per-product counters.
 *
 * One row per (user, product, variation): product_id is always the parent
 * product, variation_id the chosen variation or 0. A product is in one list
 * of the customer at a time: list_id 0 is the main list (no row in
 * wsh_lists, share token in user meta wsh_share), other ids are named lists
 * (1.1.0, setting "lists"). "price" is the display price when the item was
 * added (for "The price dropped by …"); alert_price and was_out are the
 * state of the email alerts (WSH_Alerts).
 *
 * Writes come only from the signed-in customer's own clicks (WSH_Ajax,
 * WSH_List form, nonce + whitelist), from the admin (import, privacy
 * eraser) and from WooCommerce's order hooks (_wsh_bought). Guests are
 * never stored: their list lives in their browser (Bible §14.28).
 *
 * Product meta (derived, always deleted on uninstall):
 *  - _wsh_count   customers with the product in their list
 *  - _wsh_bought  order lines bought from a list (paid orders)
 */

defined( 'ABSPATH' ) || exit;

final class WSH_Store {

	/** Schema version (option wsh_db). */
	const DB_VERSION = '2';

	/** Named lists per customer (besides the main list). Filter: wsh_max_lists. */
	const MAX_LISTS = 10;

	/** Length of a list name. */
	const NAME_LEN = 60;

	/** Items per list. Filter: wsh_max_items. */
	const MAX_ITEMS = 200;

	/** Write requests per customer per minute. */
	const RATE = 60;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wsh_items';
	}

	public static function lists_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wsh_lists';
	}

	/** Creates or upgrades the table (activation, admin_init when wsh_db differs). */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$lists   = self::lists_table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  list_id bigint(20) unsigned NOT NULL DEFAULT 0,
  product_id bigint(20) unsigned NOT NULL,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  added datetime NOT NULL,
  price decimal(19,4) DEFAULT NULL,
  alert_price decimal(19,4) DEFAULT NULL,
  was_out tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY item (user_id,product_id,variation_id),
  KEY product_id (product_id),
  KEY user_list (user_id,list_id)
) {$charset};
CREATE TABLE {$lists} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  name varchar(191) NOT NULL DEFAULT '',
  token varchar(20) DEFAULT NULL,
  created datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY token (token)
) {$charset};"
		);
		update_option( 'wsh_db', self::DB_VERSION, true );
	}

	/** True once the table exists (wsh_db set). */
	public static function ready(): bool {
		return self::DB_VERSION === get_option( 'wsh_db' );
	}

	public static function max_items(): int {
		return max( 1, min( 1000, (int) apply_filters( 'wsh_max_items', self::MAX_ITEMS ) ) );
	}

	/**
	 * The items of a customer, newest first.
	 *
	 * @param int      $user_id User id.
	 * @param int|null $list    A list id (0 = main list), or null for every list.
	 * @return array[] Each { p, v, added (GMT 'Y-m-d H:i:s'), price (float|null), list }.
	 */
	public static function items( int $user_id, ?int $list = null ): array {
		global $wpdb;
		if ( $user_id <= 0 || ! self::ready() ) {
			return array();
		}
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		if ( null === $list ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, variation_id, added, price, list_id FROM {$table} WHERE user_id = %d ORDER BY added DESC, id DESC LIMIT %d", $user_id, self::max_items() ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, variation_id, added, price, list_id FROM {$table} WHERE user_id = %d AND list_id = %d ORDER BY added DESC, id DESC LIMIT %d", $user_id, $list, self::max_items() ), ARRAY_A );
		}
		// phpcs:enable
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'p'     => (int) $r['product_id'],
				'v'     => (int) $r['variation_id'],
				'added' => (string) $r['added'],
				'price' => null === $r['price'] ? null : (float) $r['price'],
				'list'  => (int) $r['list_id'],
			);
		}
		return $out;
	}

	/**
	 * Validates a (product, variation) pair: a published product that is
	 * not a variation itself; a variation must belong to it.
	 *
	 * @param int $p Product id.
	 * @param int $v Variation id or 0.
	 * @return array|null [ parent id, variation id ] or null.
	 */
	public static function valid_pair( int $p, int $v ): ?array {
		if ( $p <= 0 || $v < 0 ) {
			return null;
		}
		$product = wc_get_product( $p );
		if ( ! $product ) {
			return null;
		}
		// A variation id given as the product: use its parent.
		if ( $product->is_type( 'variation' ) ) {
			$v       = $p;
			$p       = (int) $product->get_parent_id();
			$product = wc_get_product( $p );
			if ( ! $product ) {
				return null;
			}
		}
		if ( 'publish' !== $product->get_status() || '' !== (string) get_post_field( 'post_password', $p ) ) {
			return null;
		}
		if ( $v > 0 ) {
			$var = wc_get_product( $v );
			if ( ! $var || ! $var->is_type( 'variation' ) || (int) $var->get_parent_id() !== $p || 'publish' !== $var->get_status() ) {
				return null;
			}
		}
		return array( $p, $v );
	}

	/**
	 * Adds an item. Returns 'added', 'exists', 'full' or 'invalid'.
	 *
	 * @param int $user_id User id.
	 * @param int $p       Product id.
	 * @param int $v       Variation id or 0.
	 * @param int $time    Unix time it was added (merge keeps the browser's time).
	 * @param int $list    List id (0 = main list; an unknown id falls back to 0).
	 */
	public static function add( int $user_id, int $p, int $v, int $time = 0, int $list = 0 ): string {
		global $wpdb;
		$pair = self::valid_pair( $p, $v );
		if ( $user_id <= 0 || null === $pair || ! self::ready() ) {
			return 'invalid';
		}
		$list = self::owns_list( $user_id, $list ) ? $list : 0;
		list( $p, $v ) = $pair;
		$table         = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d AND product_id = %d AND variation_id = %d", $user_id, $p, $v ) );
		if ( $exists ) {
			return 'exists';
		}
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) );
		if ( $count >= self::max_items() ) {
			return 'full';
		}
		$now  = time();
		$time = ( $time > 0 && $time <= $now ) ? $time : $now;
		$ok   = $wpdb->insert(
			$table,
			array(
				'user_id'      => $user_id,
				'list_id'      => $list,
				'product_id'   => $p,
				'variation_id' => $v,
				'added'        => gmdate( 'Y-m-d H:i:s', $time ),
				'price'        => self::display_price( $p, $v ),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%f' )
		);
		// phpcs:enable
		if ( ! $ok ) {
			return 'invalid';
		}
		self::recount( $p );
		/**
		 * Fires after a signed-in customer adds a product to the wishlist.
		 *
		 * @param int $user_id      User id.
		 * @param int $product_id   Product id (parent).
		 * @param int $variation_id Variation id or 0.
		 */
		do_action( 'wsh_item_added', $user_id, $p, $v );
		return 'added';
	}

	/**
	 * Removes an item (exact pair).
	 *
	 * @param int $user_id User id.
	 * @param int $p       Product id.
	 * @param int $v       Variation id or 0.
	 */
	public static function remove( int $user_id, int $p, int $v ): bool {
		global $wpdb;
		if ( $user_id <= 0 || $p <= 0 || $v < 0 || ! self::ready() ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$n = (int) $wpdb->delete(
			self::table(),
			array(
				'user_id'      => $user_id,
				'product_id'   => $p,
				'variation_id' => $v,
			),
			array( '%d', '%d', '%d' )
		);
		if ( $n ) {
			self::recount( $p );
			/**
			 * Fires after a product leaves a signed-in customer's wishlist.
			 *
			 * @param int $user_id      User id.
			 * @param int $product_id   Product id (parent).
			 * @param int $variation_id Variation id or 0.
			 */
			do_action( 'wsh_item_removed', $user_id, $p, $v );
		}
		return $n > 0;
	}

	/**
	 * Empties a list (or every list). Returns the number of items removed.
	 *
	 * @param int      $user_id User id.
	 * @param int|null $list    List id, or null for every list.
	 */
	public static function clear( int $user_id, ?int $list = null ): int {
		global $wpdb;
		if ( $user_id <= 0 || ! self::ready() ) {
			return 0;
		}
		$items = self::items( $user_id, $list );
		$where = array( 'user_id' => $user_id );
		if ( null !== $list ) {
			$where['list_id'] = $list;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$n = (int) $wpdb->delete( self::table(), $where, array_fill( 0, count( $where ), '%d' ) );
		$done = array();
		foreach ( $items as $it ) {
			if ( ! isset( $done[ $it['p'] ] ) ) {
				$done[ $it['p'] ] = true;
				self::recount( $it['p'] );
			}
			do_action( 'wsh_item_removed', $user_id, $it['p'], $it['v'] );
		}
		return $n;
	}

	/** True when the pair is in the list. */
	public static function has( int $user_id, int $p, int $v ): bool {
		global $wpdb;
		if ( $user_id <= 0 || ! self::ready() ) {
			return false;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d AND product_id = %d AND variation_id = %d", $user_id, $p, $v ) );
	}

	/**
	 * Moves an item to another list of the same customer.
	 *
	 * @param int $user_id User id.
	 * @param int $p       Product id.
	 * @param int $v       Variation id or 0.
	 * @param int $list    Target list id (0 = main list).
	 */
	public static function move( int $user_id, int $p, int $v, int $list ): bool {
		global $wpdb;
		if ( $user_id <= 0 || ! self::ready() || ! self::owns_list( $user_id, $list ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return false !== $wpdb->update(
			self::table(),
			array( 'list_id' => $list ),
			array(
				'user_id'      => $user_id,
				'product_id'   => $p,
				'variation_id' => $v,
			),
			array( '%d' ),
			array( '%d', '%d', '%d' )
		);
	}

	/* =====================================================================
	 * Named lists ({prefix}wsh_lists)
	 * =================================================================== */

	public static function max_lists(): int {
		return max( 1, min( 50, (int) apply_filters( 'wsh_max_lists', self::MAX_LISTS ) ) );
	}

	/** True for 0 (the main list) and for the customer's own named lists. */
	public static function owns_list( int $user_id, int $list ): bool {
		global $wpdb;
		if ( 0 === $list ) {
			return true;
		}
		if ( $list < 0 || $user_id <= 0 || ! self::ready() ) {
			return false;
		}
		$t = self::lists_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE id = %d AND user_id = %d", $list, $user_id ) );
	}

	/**
	 * A customer's named lists, oldest first (the main list is not here).
	 *
	 * @param int $user_id User id.
	 * @return array[] Each { id, name, token }.
	 */
	public static function lists( int $user_id ): array {
		global $wpdb;
		if ( $user_id <= 0 || ! self::ready() ) {
			return array();
		}
		$t = self::lists_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, token FROM {$t} WHERE user_id = %d ORDER BY id ASC LIMIT %d", $user_id, self::max_lists() ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'    => (int) $r['id'],
				'name'  => (string) $r['name'],
				'token' => (string) $r['token'],
			);
		}
		return $out;
	}

	/** Items per list of a customer: list id => count. */
	public static function list_counts( int $user_id ): array {
		global $wpdb;
		if ( $user_id <= 0 || ! self::ready() ) {
			return array();
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT list_id, COUNT(*) AS n FROM {$table} WHERE user_id = %d GROUP BY list_id", $user_id ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['list_id'] ] = (int) $r['n'];
		}
		return $out;
	}

	/** A list name: one line, trimmed, at most NAME_LEN characters. */
	public static function clean_name( string $name ): string {
		return mb_substr( trim( sanitize_text_field( $name ) ), 0, self::NAME_LEN );
	}

	/**
	 * Creates a named list. Returns its id, or 0 (empty name, limit reached).
	 *
	 * @param int    $user_id User id.
	 * @param string $name    Name.
	 */
	public static function list_create( int $user_id, string $name ): int {
		global $wpdb;
		$name = self::clean_name( $name );
		if ( $user_id <= 0 || '' === $name || ! self::ready() || count( self::lists( $user_id ) ) >= self::max_lists() ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$ok = $wpdb->insert(
			self::lists_table(),
			array(
				'user_id' => $user_id,
				'name'    => $name,
				'created' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Renames a named list.
	 *
	 * @param int    $user_id User id.
	 * @param int    $list    List id.
	 * @param string $name    New name.
	 */
	public static function list_rename( int $user_id, int $list, string $name ): bool {
		global $wpdb;
		$name = self::clean_name( $name );
		if ( $list <= 0 || '' === $name || ! self::owns_list( $user_id, $list ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return false !== $wpdb->update( self::lists_table(), array( 'name' => $name ), array( 'id' => $list ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Deletes a named list with its items.
	 *
	 * @param int $user_id User id.
	 * @param int $list    List id.
	 */
	public static function list_delete( int $user_id, int $list ): bool {
		global $wpdb;
		if ( $list <= 0 || ! self::owns_list( $user_id, $list ) ) {
			return false;
		}
		self::clear( $user_id, $list );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return (bool) $wpdb->delete( self::lists_table(), array( 'id' => $list ), array( '%d' ) );
	}

	/**
	 * Every list of a customer, the main one first: { id, name, count }.
	 *
	 * @param int $user_id User id.
	 */
	public static function all_lists( int $user_id ): array {
		$counts = self::list_counts( $user_id );
		$out    = array(
			array(
				'id'    => 0,
				'name'  => WSH_Settings::text( 't_default' ),
				'count' => isset( $counts[0] ) ? $counts[0] : 0,
			),
		);
		foreach ( self::lists( $user_id ) as $l ) {
			$out[] = array(
				'id'    => $l['id'],
				'name'  => $l['name'],
				'count' => isset( $counts[ $l['id'] ] ) ? $counts[ $l['id'] ] : 0,
			);
		}
		return $out;
	}

	/**
	 * Display price of a product / variation now (tax as the shop shows it).
	 *
	 * @param int $p Product id.
	 * @param int $v Variation id or 0.
	 */
	public static function display_price( int $p, int $v ): ?float {
		$product = wc_get_product( $v > 0 ? $v : $p );
		if ( ! $product || '' === (string) $product->get_price() ) {
			return null;
		}
		return (float) wc_get_price_to_display( $product );
	}

	/**
	 * Recounts _wsh_count of a product (customers with it in their list).
	 *
	 * @param int $p Product id.
	 */
	public static function recount( int $p ): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE product_id = %d", $p ) );
		if ( $n > 0 ) {
			update_post_meta( $p, '_wsh_count', $n );
		} else {
			delete_post_meta( $p, '_wsh_count' );
		}
	}

	/** Recounts every product (after an import). */
	public static function recount_all(): void {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery -- own table, derived meta.
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_wsh_count' ) );
		$rows = $wpdb->get_results( "SELECT product_id, COUNT(DISTINCT user_id) AS n FROM {$table} GROUP BY product_id", ARRAY_A );
		// phpcs:enable
		foreach ( (array) $rows as $r ) {
			update_post_meta( (int) $r['product_id'], '_wsh_count', (int) $r['n'] );
		}
		if ( function_exists( 'wp_cache_flush_group' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'post_meta' );
		}
	}

	/**
	 * Rate limit for a customer's write requests: true when allowed.
	 *
	 * @param int $user_id User id.
	 */
	public static function rate_ok( int $user_id ): bool {
		$key = 'wsh_rl_' . $user_id;
		$n   = (int) get_transient( $key );
		if ( $n >= self::RATE ) {
			return false;
		}
		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/* =====================================================================
	 * Share token (user meta wsh_share)
	 * =================================================================== */

	/**
	 * The share token of a list, or '' when the list is private.
	 *
	 * @param int $user_id User id.
	 * @param int $list    List id (0 = main list, token in user meta wsh_share).
	 */
	public static function token( int $user_id, int $list = 0 ): string {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return '';
		}
		if ( $list > 0 ) {
			$lt = self::lists_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			$t = self::ready() ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT token FROM {$lt} WHERE id = %d AND user_id = %d", $list, $user_id ) ) : '';
		} else {
			$t = (string) get_user_meta( $user_id, 'wsh_share', true );
		}
		return 1 === preg_match( '/^[A-Za-z0-9]{20}$/', $t ) ? $t : '';
	}

	/**
	 * A new random token (the old link stops working). '' for a list that is not the customer's.
	 *
	 * @param int $user_id User id.
	 * @param int $list    List id.
	 */
	public static function new_token( int $user_id, int $list = 0 ): string {
		global $wpdb;
		if ( ! self::owns_list( $user_id, $list ) ) {
			return '';
		}
		$t = wp_generate_password( 20, false, false );
		if ( $list > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			$wpdb->update( self::lists_table(), array( 'token' => $t ), array( 'id' => $list ), array( '%s' ), array( '%d' ) );
		} else {
			update_user_meta( $user_id, 'wsh_share', $t );
		}
		return $t;
	}

	/**
	 * @param int $user_id User id.
	 * @param int $list    List id.
	 */
	public static function token_off( int $user_id, int $list = 0 ): void {
		global $wpdb;
		if ( $list > 0 ) {
			if ( self::owns_list( $user_id, $list ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
				$wpdb->update( self::lists_table(), array( 'token' => null ), array( 'id' => $list ), array( '%s' ), array( '%d' ) );
			}
			return;
		}
		delete_user_meta( $user_id, 'wsh_share' );
	}

	/**
	 * The owner of a share token: [ user id, list id ], or null.
	 *
	 * @param string $token Token.
	 */
	public static function token_owner( string $token ): ?array {
		global $wpdb;
		if ( 1 !== preg_match( '/^[A-Za-z0-9]{20}$/', $token ) ) {
			return null;
		}
		$uid = self::token_user( $token );
		if ( $uid ) {
			return array( $uid, 0 );
		}
		if ( ! self::ready() ) {
			return null;
		}
		$lt = self::lists_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, user_id, token FROM {$lt} WHERE token = %s LIMIT 1", $token ), ARRAY_A );
		// The column follows the DB collation (case-insensitive): check exactly.
		if ( $row && hash_equals( (string) $row['token'], $token ) ) {
			return array( (int) $row['user_id'], (int) $row['id'] );
		}
		return null;
	}

	/** The user id of a main-list share token, or 0. */
	public static function token_user( string $token ): int {
		if ( 1 !== preg_match( '/^[A-Za-z0-9]{20}$/', $token ) ) {
			return 0;
		}
		$users = get_users(
			array(
				'meta_key'    => 'wsh_share', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $token, // phpcs:ignore WordPress.DB.SlowDBQuery
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => false,
			)
		);
		$uid = $users ? (int) $users[0] : 0;
		// meta_value comparison follows the DB collation (case-insensitive): check exactly.
		return ( $uid && hash_equals( self::token( $uid ), $token ) ) ? $uid : 0;
	}
}

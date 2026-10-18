<?php
/**
 * WSH_Store — the lists of signed-in customers ({prefix}wsh_items), the
 * share token (user meta wsh_share) and the per-product counters.
 *
 * One row per (user, product, variation): product_id is always the parent
 * product, variation_id the chosen variation or 0. "price" is the display
 * price when the item was added (for "The price dropped by …").
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
	const DB_VERSION = '1';

	/** Items per list. Filter: wsh_max_items. */
	const MAX_ITEMS = 200;

	/** Write requests per customer per minute. */
	const RATE = 60;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wsh_items';
	}

	/** Creates or upgrades the table (activation, admin_init when wsh_db differs). */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  added datetime NOT NULL,
  price decimal(19,4) DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY item (user_id,product_id,variation_id),
  KEY product_id (product_id)
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
	 * @param int $user_id User id.
	 * @return array[] Each { p, v, added (GMT 'Y-m-d H:i:s'), price (float|null) }.
	 */
	public static function items( int $user_id ): array {
		global $wpdb;
		if ( $user_id <= 0 || ! self::ready() ) {
			return array();
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, variation_id, added, price FROM {$table} WHERE user_id = %d ORDER BY added DESC, id DESC LIMIT %d", $user_id, self::max_items() ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'p'     => (int) $r['product_id'],
				'v'     => (int) $r['variation_id'],
				'added' => (string) $r['added'],
				'price' => null === $r['price'] ? null : (float) $r['price'],
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
	 */
	public static function add( int $user_id, int $p, int $v, int $time = 0 ): string {
		global $wpdb;
		$pair = self::valid_pair( $p, $v );
		if ( $user_id <= 0 || null === $pair || ! self::ready() ) {
			return 'invalid';
		}
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
				'product_id'   => $p,
				'variation_id' => $v,
				'added'        => gmdate( 'Y-m-d H:i:s', $time ),
				'price'        => self::display_price( $p, $v ),
			),
			array( '%d', '%d', '%d', '%s', '%f' )
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
	 * Empties a list. Returns the number of items removed.
	 *
	 * @param int $user_id User id.
	 */
	public static function clear( int $user_id ): int {
		global $wpdb;
		if ( $user_id <= 0 || ! self::ready() ) {
			return 0;
		}
		$items = self::items( $user_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$n = (int) $wpdb->delete( self::table(), array( 'user_id' => $user_id ), array( '%d' ) );
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

	/** The customer's share token, or '' when the list is private. */
	public static function token( int $user_id ): string {
		$t = $user_id > 0 ? (string) get_user_meta( $user_id, 'wsh_share', true ) : '';
		return 1 === preg_match( '/^[A-Za-z0-9]{20}$/', $t ) ? $t : '';
	}

	/** A new random token (the old link stops working). */
	public static function new_token( int $user_id ): string {
		$t = wp_generate_password( 20, false, false );
		update_user_meta( $user_id, 'wsh_share', $t );
		return $t;
	}

	public static function token_off( int $user_id ): void {
		delete_user_meta( $user_id, 'wsh_share' );
	}

	/** The user id of a share token, or 0. */
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

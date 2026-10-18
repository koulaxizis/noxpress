<?php
/**
 * WSH_Import — other wishlist plugins: detection (Bible §14.23: while one
 * is active, Wish Shelf stays in test mode) and import from WishSuite,
 * YITH WooCommerce Wishlist and TI WooCommerce Wishlist.
 *
 * The import only reads the other plugin's tables and copies what is not
 * already in Wish Shelf; their data is never changed. Only signed-in
 * customers' lists: guest lists of other plugins live in their visitors'
 * browsers or sessions. A product is in one list per customer, so the first
 * list it is found in wins.
 *
 * Tables, as the plugins' own code creates them (read 2026-10-18):
 *  - WishSuite: {prefix}wishsuite_list (user_id, product_id, date_added).
 *  - YITH:      {prefix}yith_wcwl_lists (ID, user_id, wishlist_name,
 *               is_default) and {prefix}yith_wcwl (prod_id — a product or
 *               a variation —, user_id, wishlist_id, dateadded).
 *  - TI:        {prefix}tinvwl_lists (ID, author, title, type: default |
 *               list) and {prefix}tinvwl_items (wishlist_id, product_id,
 *               variation_id, author, date).
 * Their default list goes to the main list; the others become named lists
 * when named lists are on (else the main list too).
 */

defined( 'ABSPATH' ) || exit;

final class WSH_Import {

	/** Plugin folder => name. */
	const OTHERS = array(
		'wishsuite'                 => 'WishSuite',
		'yith-woocommerce-wishlist' => 'YITH WooCommerce Wishlist',
		'ti-woocommerce-wishlist'   => 'TI WooCommerce Wishlist',
		'addonify-wishlist'         => 'Addonify Wishlist',
		'woo-smart-wishlist'        => 'WPC Smart Wishlist',
		'wt-woocommerce-wishlist'   => 'Wishlist for WooCommerce (WebToffee)',
	);

	/** @var string[]|null Per-request cache. */
	private static $active = null;

	/** Names of the other wishlist plugins that are active on this site. */
	public static function active_names(): array {
		if ( null !== self::$active ) {
			return self::$active;
		}
		$plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$out = array();
		foreach ( $plugins as $file ) {
			$dir = strtok( (string) $file, '/' );
			if ( isset( self::OTHERS[ $dir ] ) ) {
				$out[ $dir ] = self::OTHERS[ $dir ];
			}
		}
		self::$active = array_values( $out );
		return self::$active;
	}

	public static function other_active(): bool {
		return (bool) self::active_names();
	}

	/** Sources: key => [ name, tables that must exist (without prefix) ]. */
	const SOURCES = array(
		'wishsuite' => array( 'WishSuite', array( 'wishsuite_list' ) ),
		'yith'      => array( 'YITH WooCommerce Wishlist', array( 'yith_wcwl', 'yith_wcwl_lists' ) ),
		'ti'        => array( 'TI WooCommerce Wishlist', array( 'tinvwl_items', 'tinvwl_lists' ) ),
	);

	/** Rows read per import. */
	const MAX_ROWS = 20000;

	/**
	 * True when every table of a source exists.
	 *
	 * @param string $src Source key.
	 */
	private static function has_tables( string $src ): bool {
		global $wpdb;
		if ( ! isset( self::SOURCES[ $src ] ) ) {
			return false;
		}
		foreach ( self::SOURCES[ $src ][1] as $t ) {
			$t = $wpdb->prefix . $t;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only check of another plugin's table.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) !== $t ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The rows of a source, signed-in customers only, oldest first:
	 * each { user, p, v, time (Unix), list (source list id or 0 = default), name }.
	 *
	 * @param string $src Source key.
	 */
	private static function rows( string $src ): array {
		global $wpdb;
		if ( ! self::has_tables( $src ) ) {
			return array();
		}
		$p   = $wpdb->prefix;
		$out = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only, table names checked above.
		if ( 'wishsuite' === $src ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, product_id, date_added FROM {$p}wishsuite_list WHERE user_id > 0 ORDER BY user_id ASC LIMIT %d", self::MAX_ROWS ), ARRAY_A );
			foreach ( (array) $rows as $r ) {
				$out[] = array(
					'user' => (int) $r['user_id'],
					'p'    => (int) $r['product_id'],
					'v'    => 0,
					'time' => self::local_time( (string) $r['date_added'] ),
					'list' => 0,
					'name' => '',
				);
			}
		} elseif ( 'yith' === $src ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT i.user_id, i.prod_id, i.dateadded, l.ID AS lid, l.wishlist_name, l.is_default FROM {$p}yith_wcwl i LEFT JOIN {$p}yith_wcwl_lists l ON l.ID = i.wishlist_id WHERE i.user_id > 0 ORDER BY i.user_id ASC, i.ID ASC LIMIT %d", self::MAX_ROWS ), ARRAY_A );
			foreach ( (array) $rows as $r ) {
				$default = empty( $r['lid'] ) || ! empty( $r['is_default'] );
				$out[]   = array(
					'user' => (int) $r['user_id'],
					// A variation id as the product: WSH_Store::valid_pair() finds its parent.
					'p'    => (int) $r['prod_id'],
					'v'    => 0,
					'time' => self::local_time( (string) $r['dateadded'] ),
					'list' => $default ? 0 : (int) $r['lid'],
					'name' => $default ? '' : (string) $r['wishlist_name'],
				);
			}
		} elseif ( 'ti' === $src ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT i.author, i.product_id, i.variation_id, i.date, l.ID AS lid, l.title, l.type FROM {$p}tinvwl_items i LEFT JOIN {$p}tinvwl_lists l ON l.ID = i.wishlist_id WHERE i.author > 0 ORDER BY i.author ASC, i.ID ASC LIMIT %d", self::MAX_ROWS ), ARRAY_A );
			foreach ( (array) $rows as $r ) {
				$default = empty( $r['lid'] ) || 'default' === (string) $r['type'];
				$out[]   = array(
					'user' => (int) $r['author'],
					'p'    => (int) $r['product_id'],
					'v'    => (int) $r['variation_id'],
					'time' => self::local_time( (string) $r['date'] ),
					'list' => $default ? 0 : (int) $r['lid'],
					'name' => $default ? '' : (string) $r['title'],
				);
			}
		}
		// phpcs:enable
		return $out;
	}

	/**
	 * Local "Y-m-d H:i:s" (as these plugins save it) to Unix time, or 0.
	 *
	 * @param string $date Date.
	 */
	private static function local_time( string $date ): int {
		if ( '' === $date || 0 === strpos( $date, '0000' ) ) {
			return 0;
		}
		$t = strtotime( $date . ' UTC' );
		return $t ? $t - (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) : 0;
	}

	/**
	 * What an import would read: { table, rows, users }.
	 *
	 * @param string $src Source key.
	 */
	public static function preview( string $src = 'wishsuite' ): array {
		if ( ! self::has_tables( $src ) ) {
			return array(
				'table' => false,
				'rows'  => 0,
				'users' => 0,
			);
		}
		$rows  = self::rows( $src );
		$users = array();
		foreach ( $rows as $r ) {
			$users[ $r['user'] ] = true;
		}
		return array(
			'table' => true,
			'rows'  => count( $rows ),
			'users' => count( $users ),
		);
	}

	/**
	 * Copies a source's lists (signed-in customers). Returns { added, skipped }.
	 *
	 * @param string $src Source key.
	 */
	public static function import( string $src ): array {
		$out = array(
			'added'   => 0,
			'skipped' => 0,
		);
		if ( ! WSH_Store::ready() ) {
			return $out;
		}
		$named = (bool) WSH_Settings::get()['lists'];
		$map   = array();
		foreach ( self::rows( $src ) as $r ) {
			if ( $r['user'] <= 0 || $r['p'] <= 0 || ! get_userdata( $r['user'] ) ) {
				++$out['skipped'];
				continue;
			}
			$list = 0;
			if ( $named && $r['list'] > 0 ) {
				$k = $r['user'] . ':' . $r['list'];
				if ( ! isset( $map[ $k ] ) ) {
					// Same name already there (an earlier import): reuse it.
					$map[ $k ] = 0;
					$name      = WSH_Store::clean_name( $r['name'] );
					foreach ( WSH_Store::lists( $r['user'] ) as $l ) {
						if ( '' !== $name && $l['name'] === $name ) {
							$map[ $k ] = $l['id'];
						}
					}
					if ( ! $map[ $k ] && '' !== $name ) {
						$map[ $k ] = WSH_Store::list_create( $r['user'], $name );
					}
				}
				$list = $map[ $k ];
			}
			if ( 'added' === WSH_Store::add( $r['user'], $r['p'], $r['v'], $r['time'], $list ) ) {
				++$out['added'];
			} else {
				++$out['skipped'];
			}
		}
		return $out;
	}

	/** Kept for 1.0.0 callers. */
	public static function import_wishsuite(): array {
		return self::import( 'wishsuite' );
	}
}

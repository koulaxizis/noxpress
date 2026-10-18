<?php
/**
 * WSH_Import — other wishlist plugins: detection (Bible §14.23: while one
 * is active, Wish Shelf stays in test mode) and import from WishSuite.
 *
 * The import only reads the other plugin's table and copies what is not
 * already in Wish Shelf; their data is never changed. Guest lists of other
 * plugins live in their visitors' browsers and cannot be imported.
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

	/** The WishSuite table, or '' when it does not exist. */
	private static function wishsuite_table(): string {
		global $wpdb;
		$t = $wpdb->prefix . 'wishsuite_list';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only check of another plugin's table.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) === $t ? $t : '';
	}

	/** What an import would read: { rows, users } (0 / 0 without the table). */
	public static function preview(): array {
		global $wpdb;
		$t = self::wishsuite_table();
		if ( '' === $t ) {
			return array(
				'table' => false,
				'rows'  => 0,
				'users' => 0,
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only, table name checked above.
		$r = $wpdb->get_row( "SELECT COUNT(*) AS n, COUNT(DISTINCT user_id) AS u FROM {$t} WHERE user_id > 0", ARRAY_A );
		return array(
			'table' => true,
			'rows'  => (int) ( $r['n'] ?? 0 ),
			'users' => (int) ( $r['u'] ?? 0 ),
		);
	}

	/**
	 * Copies WishSuite's lists (signed-in customers). Returns
	 * { added, skipped } counts.
	 */
	public static function import_wishsuite(): array {
		global $wpdb;
		$t   = self::wishsuite_table();
		$out = array(
			'added'   => 0,
			'skipped' => 0,
		);
		if ( '' === $t || ! WSH_Store::ready() ) {
			return $out;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only, table name checked above.
		$rows = $wpdb->get_results( "SELECT * FROM {$t} WHERE user_id > 0 ORDER BY user_id ASC LIMIT 20000", ARRAY_A );
		foreach ( (array) $rows as $r ) {
			$uid  = isset( $r['user_id'] ) ? (int) $r['user_id'] : 0;
			$pid  = isset( $r['product_id'] ) ? (int) $r['product_id'] : 0;
			$time = isset( $r['date_added'] ) ? strtotime( (string) $r['date_added'] ) : 0;
			if ( $uid <= 0 || $pid <= 0 || ! get_userdata( $uid ) ) {
				++$out['skipped'];
				continue;
			}
			// WishSuite saves local time; the browser-time rule of add() needs Unix time.
			$time = $time ? $time - (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) : 0;
			$res  = WSH_Store::add( $uid, $pid, 0, (int) $time );
			if ( 'added' === $res ) {
				++$out['added'];
			} else {
				++$out['skipped'];
			}
		}
		return $out;
	}
}

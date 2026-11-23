<?php
/**
 * CKE_Carts — retention of the carts that Cart Abandonment Recovery for
 * WooCommerce (CartFlows) keeps (1.3.0).
 *
 * That plugin stores every tracked cart (e-mail, cart, checkout fields,
 * status) in {prefix}cartflows_ca_cart_abandonment and keeps it forever;
 * it has no retention setting. With «Διαγραφή καλαθιών μετά από N ημέρες»
 * (ca_days, 0 = off, the default) a daily Action Scheduler job deletes the
 * carts whose time is older than N days, with their rows in the e-mail
 * history and out-of-stock tables (child tables, deleted first in case
 * the foreign keys are missing), at most BATCH carts per run.
 *
 * Only runs while the cart table exists; reads and writes nothing else.
 * Never on a visitor request (Bible §14.5): Action Scheduler and the
 * admin «Διαγραφή τώρα» button only.
 */

defined( 'ABSPATH' ) || exit;

final class CKE_Carts {

	const HOOK  = 'cke_carts_cleanup';
	const GROUP = 'checkout-extras';
	const BATCH = 500;

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'on_cleanup' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
	}

	/** Table name of the carts. */
	private static function table( string $suffix = 'cart_abandonment' ): string {
		global $wpdb;
		return $wpdb->prefix . 'cartflows_ca_' . $suffix;
	}

	/** True when a table exists. */
	private static function exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- schema check.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/** True when Cart Abandonment Recovery's table is there. */
	public static function available(): bool {
		return self::exists( self::table() );
	}

	/** admin_init: the daily job is scheduled while retention is on, removed when off. */
	public static function schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || wp_doing_ajax() ) {
			return;
		}
		try {
			$on  = CKE_Settings::get()['ca_days'] > 0;
			$has = as_has_scheduled_action( self::HOOK, array(), self::GROUP );
			if ( $on && ! $has ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, array(), self::GROUP );
			} elseif ( ! $on && $has ) {
				as_unschedule_all_actions( self::HOOK );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** HOOK (Action Scheduler): an action callback returns nothing. */
	public static function on_cleanup(): void {
		try {
			self::cleanup();
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Deletes the carts older than ca_days (one batch).
	 *
	 * @return int Carts deleted.
	 */
	public static function cleanup(): int {
		global $wpdb;
		$days = (int) CKE_Settings::get()['ca_days'];
		if ( $days <= 0 || ! self::available() ) {
			return 0;
		}
		$t   = self::table();
		$cut = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// The plugin writes "time" in the site's time zone (current_time): compare in the same.
		$cut = get_date_from_gmt( $cut );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- third-party table, name built from the prefix.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT session_id FROM {$t} WHERE time IS NOT NULL AND time < %s ORDER BY time ASC LIMIT %d", $cut, self::BATCH ) );
		$ids = array_values( array_filter( array_map( 'strval', (array) $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		foreach ( array( 'email_history', 'out_of_stock_notifications' ) as $child ) {
			$ct = self::table( $child );
			if ( self::exists( $ct ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$ct} WHERE ca_session_id IN ($in)", $ids ) );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
		$n = $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE session_id IN ($in)", $ids ) );
		return max( 0, (int) $n );
	}
}

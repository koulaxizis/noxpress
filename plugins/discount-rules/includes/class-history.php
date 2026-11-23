<?php
/**
 * DSR_History — the price history behind «Χαμηλότερη τιμή των τελευταίων
 * 30 ημερών» (EU Omnibus Directive 2019/2161: a reduced price shows the
 * lowest price of the 30 days before the reduction).
 *
 * Table {prefix}dsr_prices ( product_id, since DATE, price ): one row
 * whenever the lowest price of a product (or variation) changes, at most
 * one per day (the lowest of that day). The price is the one a guest
 * pays: WooCommerce's price (sale included) or a «Προσφορά» rule when
 * lower, as stored (with or without tax like the store's prices).
 *
 * Written only by the daily job (Action Scheduler, dsr_price_day at 00:30
 * local time, in batches of 200 products) and when a product is saved in
 * the admin or by WooCommerce's scheduled sales: never on a visitor
 * request (Bible §14.5). Rows older than 400 days are deleted.
 *
 * lowest() reads the rows that cover the 30 days before the reduction
 * started (the rule's start, or WooCommerce's sale start date, or the day
 * the current price began). With no rows yet (a new install), null: the
 * page then shows nothing rather than a guess.
 *
 * Greek law, art. 9ι ν. 2251/1994 (added by ν. 5111/2024, art. 6a of
 * Directive 98/6/EC as amended by 2019/2161), two special cases:
 *  - A product on the market for less than 30 days: the reference is the
 *    lowest price since it was launched. The rows start on the day the
 *    product was first recorded, so lowest() already reads only those.
 *  - Progressive reductions in one uninterrupted period of at most 60
 *    days: the reference is the price before the first reduction.
 *    run_start() walks back from the current reduction while each earlier
 *    price change was itself a reduction that started within 60 days of
 *    the current one, and returns when the first of them started.
 */

defined( 'ABSPATH' ) || exit;

final class DSR_History {

	const DB_VERSION = '1';
	const OPT_DB     = 'dsr_db';
	const BATCH      = 200;
	const KEEP_DAYS  = 400;
	const GROUP      = 'discount-rules';

	public static function init(): void {
		add_action( 'dsr_price_day', array( __CLASS__, 'day' ) );
		add_action( 'dsr_price_batch', array( __CLASS__, 'batch' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'saved' ), 30, 1 );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'saved' ), 30, 1 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'saved' ), 30, 1 );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'saved' ), 30, 1 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'dsr_prices';
	}

	/** The table and the daily job (activation, or an update that added them). */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				product_id bigint(20) unsigned NOT NULL,
				since date NOT NULL,
				price decimal(19,4) NOT NULL,
				PRIMARY KEY  (product_id,since)
			) {$charset};"
		);
		update_option( self::OPT_DB, self::DB_VERSION, false );
		self::schedule();
	}

	public static function maybe_install(): void {
		if ( self::DB_VERSION !== (string) get_option( self::OPT_DB, '' ) && current_user_can( 'activate_plugins' ) ) {
			self::install();
		}
	}

	/** The daily job at 00:30 local time. */
	public static function schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( as_has_scheduled_action( 'dsr_price_day', null, self::GROUP ) ) {
			return;
		}
		$next = date_create_immutable( 'tomorrow 00:30', wp_timezone() );
		as_schedule_recurring_action( $next ? $next->getTimestamp() : time() + DAY_IN_SECONDS, DAY_IN_SECONDS, 'dsr_price_day', array(), self::GROUP );
	}

	/** True when the lowest-price line is in use. */
	public static function enabled(): bool {
		return 'off' !== DSR_Settings::get()['omnibus'];
	}

	/* =====================================================================
	 * Writing
	 * =================================================================== */

	/** The daily job: prune, then the first batch. */
	public static function day(): void {
		if ( ! self::enabled() ) {
			return;
		}
		global $wpdb;
		$cut = wp_date( 'Y-m-d', time() - self::KEEP_DAYS * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE since < %s', $cut ) );
		self::batch( 0 );
	}

	/**
	 * One batch of products and variations, then the next one.
	 *
	 * @param mixed $offset Offset.
	 */
	public static function batch( $offset ): void {
		if ( ! self::enabled() ) {
			return;
		}
		global $wpdb;
		$offset = max( 0, (int) $offset );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a plain id list, paged.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status = 'publish' ORDER BY ID ASC LIMIT %d OFFSET %d",
				self::BATCH,
				$offset
			)
		);
		foreach ( (array) $ids as $id ) {
			self::record_product( (int) $id );
		}
		if ( count( (array) $ids ) === self::BATCH && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'dsr_price_batch', array( $offset + self::BATCH ), self::GROUP );
		}
	}

	/**
	 * A product was saved (admin, import, scheduled sale).
	 *
	 * @param mixed $id Product id.
	 */
	public static function saved( $id ): void {
		if ( self::enabled() ) {
			try {
				self::record_product( (int) $id );
			} catch ( \Throwable $e ) {
				return;
			}
		}
	}

	/** Today's lowest guest price of one product (variable and grouped parents are skipped). */
	public static function record_product( int $id ): void {
		$p = wc_get_product( $id );
		if ( ! $p instanceof WC_Product || $p->is_type( 'variable' ) || $p->is_type( 'grouped' ) || $p->is_type( 'external' ) ) {
			return;
		}
		$price = (float) $p->get_price( 'edit' );
		if ( $price <= 0 ) {
			return;
		}
		$ctx  = array(
			'ts'        => time(),
			'logged_in' => false,
			'subtotal'  => 0.0,
		);
		$sale = 'live' === DSR_Settings::get()['mode'] && ! Discount_Rules::killed() ? DSR_Engine::sale_for( $p, $ctx ) : null;
		if ( null !== $sale ) {
			$price = min( $price, (float) $sale['price'] );
		}
		self::record( $id, $price, wp_date( 'Y-m-d' ) );
	}

	/**
	 * One price for a day: a new row when it changed, the lower one when the
	 * day already has a row.
	 *
	 * @param int    $id    Product id.
	 * @param float  $price Price.
	 * @param string $day   'Y-m-d' (local).
	 */
	public static function record( int $id, float $price, string $day ): void {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$last = $wpdb->get_row( $wpdb->prepare( "SELECT since, price FROM {$t} WHERE product_id = %d ORDER BY since DESC LIMIT 1", $id ), ARRAY_A );
		$p    = round( $price, 4 );
		if ( is_array( $last ) ) {
			if ( abs( (float) $last['price'] - $p ) < 0.00005 ) {
				return;
			}
			if ( $last['since'] === $day ) {
				if ( $p < (float) $last['price'] ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
					$wpdb->update( $t, array( 'price' => $p ), array( 'product_id' => $id, 'since' => $day ), array( '%f' ), array( '%d', '%s' ) );
				}
				return;
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$wpdb->replace( $t, array( 'product_id' => $id, 'since' => $day, 'price' => $p ), array( '%d', '%s', '%f' ) );
	}

	/* =====================================================================
	 * Reading
	 * =================================================================== */

	/**
	 * The lowest price of the $days days before a reduction started.
	 *
	 * @param int $id    Product or variation id.
	 * @param int $start Timestamp the reduction started.
	 * @param int $days  Days (30).
	 * @return float|null As stored; null without history for that window.
	 */
	public static function lowest( int $id, int $start, int $days = 30 ): ?float {
		global $wpdb;
		if ( $start <= 0 ) {
			return null;
		}
		$end   = wp_date( 'Y-m-d', $start - DAY_IN_SECONDS );
		$begin = wp_date( 'Y-m-d', $start - $days * DAY_IN_SECONDS );
		$t     = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, a few rows.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT since, price FROM {$t} WHERE product_id = %d AND since <= %s ORDER BY since DESC LIMIT 400", $id, $end ), ARRAY_A );
		$low  = null;
		foreach ( (array) $rows as $row ) {
			$v   = (float) $row['price'];
			$low = null === $low ? $v : min( $low, $v );
			if ( (string) $row['since'] <= $begin ) {
				break; // This row covers the first day of the window.
			}
		}
		return $low;
	}

	/**
	 * Start of a run of progressive reductions (art. 9ι par. 3): from the
	 * start of the current reduction, steps back to the previous price
	 * change while that change lowered the price and started at most
	 * $max days before the current reduction. Without such a run, $start.
	 *
	 * @param int $id    Product or variation id.
	 * @param int $start Timestamp the current reduction started.
	 * @param int $max   Days (60).
	 */
	public static function run_start( int $id, int $start, int $max = 60 ): int {
		global $wpdb;
		if ( $start <= 0 ) {
			return $start;
		}
		$limit = wp_date( 'Y-m-d', $start - $max * DAY_IN_SECONDS );
		$t     = self::table();
		// The rows before the reduction, newest first (one more than the run, for the price before it).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, a few rows.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT since, price FROM {$t} WHERE product_id = %d AND since < %s ORDER BY since DESC LIMIT 120", $id, wp_date( 'Y-m-d', $start ) ), ARRAY_A );
		$rows = array_values( (array) $rows );
		$run  = $start;
		for ( $i = 0; $i + 1 < count( $rows ); $i++ ) {
			$cur  = $rows[ $i ];
			$prev = $rows[ $i + 1 ];
			if ( (string) $cur['since'] < $limit || (float) $cur['price'] >= (float) $prev['price'] ) {
				break;
			}
			$dt = date_create_immutable( $cur['since'] . ' 00:00:00', wp_timezone() );
			if ( ! $dt ) {
				break;
			}
			$run = $dt->getTimestamp();
		}
		return $run;
	}

	/**
	 * When the current lower price of a product began, for a WooCommerce
	 * sale price: its own start date, else the day its price row began.
	 *
	 * @param WC_Product $p Product or variation.
	 */
	public static function sale_start( WC_Product $p ): int {
		$from = $p->get_date_on_sale_from( 'edit' );
		if ( $from ) {
			return (int) $from->getTimestamp();
		}
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$since = $wpdb->get_var( $wpdb->prepare( "SELECT since FROM {$t} WHERE product_id = %d ORDER BY since DESC LIMIT 1", (int) $p->get_id() ) );
		if ( ! $since ) {
			return 0;
		}
		$dt = date_create_immutable( $since . ' 00:00:00', wp_timezone() );
		return $dt ? $dt->getTimestamp() : 0;
	}
}

<?php
/**
 * BIS_Sender — from «back in stock» to the e-mails.
 *
 *  - The stock hooks of WooCommerce's CRUD (woocommerce_product_set_stock_status,
 *    woocommerce_variation_set_stock_status) fire for every change that
 *    goes through WooCommerce: the product screen, REST, CSV import, Book
 *    Feed, orders. A change to "instock" with active requests schedules
 *    bis_send( product, variation, 0 ) after the delay (Action Scheduler,
 *    group back-in-stock), once per product.
 *  - bis_send checks the stock again (a 0 → 5 → 0 slip sends nothing),
 *    sends one batch in id order and schedules the next batch after the
 *    last id. A request is marked sent only once its e-mail has left, so a
 *    failure stays active and is retried by the daily job.
 *  - bis_daily (03:17 site time) deletes expired requests and schedules
 *    bis_send for every product that is in stock and still has active
 *    requests (stock changed outside WooCommerce, an earlier failure).
 */

defined( 'ABSPATH' ) || exit;

final class BIS_Sender {

	const GROUP = 'back-in-stock';

	public static function init(): void {
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'on_stock' ), 20, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'on_stock' ), 20, 3 );
		add_action( 'bis_send', array( __CLASS__, 'run' ), 10, 3 );
		add_action( 'bis_daily', array( __CLASS__, 'daily' ) );
		add_action( 'init', array( __CLASS__, 'schedule_daily' ), 20 );
	}

	/** Pair (product_id, variation_id) of a product or a variation id. */
	public static function pair( int $id ): array {
		$p = $id > 0 ? wc_get_product( $id ) : null;
		if ( $p && $p->is_type( 'variation' ) ) {
			return array( (int) $p->get_parent_id(), (int) $p->get_id() );
		}
		return array( $id, 0 );
	}

	/** The product (or variation) of a pair can be bought again. */
	public static function in_stock( int $product_id, int $variation_id ): bool {
		$p = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );
		if ( ! $p || 'instock' !== $p->get_stock_status() ) {
			return false;
		}
		if ( $variation_id > 0 ) {
			$parent = wc_get_product( $product_id );
			return $parent && 'publish' === $parent->get_status();
		}
		return 'publish' === $p->get_status();
	}

	/**
	 * @param int        $id      Product or variation id.
	 * @param string     $status  New stock status.
	 * @param WC_Product $product The product (unused).
	 */
	public static function on_stock( $id, $status = '', $product = null ): void {
		try {
			if ( 'off' === BIS_Settings::mode() || 'instock' !== $status || ! BIS_Emails::enabled( BIS_Emails::RESTOCK ) ) {
				return;
			}
			list( $pid, $vid ) = self::pair( (int) $id );
			if ( $pid > 0 && BIS_Store::count_active( $pid, $vid ) > 0 ) {
				self::schedule( $pid, $vid, (int) BIS_Settings::get()['delay'] * MINUTE_IN_SECONDS );
			}
		} catch ( \Throwable $e ) {
			self::log( $e->getMessage() );
		}
	}

	/** Schedules the first batch of a pair, unless one is already waiting. */
	public static function schedule( int $product_id, int $variation_id, int $delay ): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		$args = array( $product_id, $variation_id, 0 );
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'bis_send', $args, self::GROUP ) ) {
			return true;
		}
		as_schedule_single_action( time() + max( 0, $delay ), 'bis_send', $args, self::GROUP );
		return true;
	}

	/**
	 * One batch.
	 *
	 * @param int $product_id   Product id.
	 * @param int $variation_id Variation id (0 = simple).
	 * @param int $after        Last id of the previous batch.
	 */
	public static function run( $product_id, $variation_id = 0, $after = 0 ): void {
		$product_id   = (int) $product_id;
		$variation_id = (int) $variation_id;
		if ( 'off' === BIS_Settings::mode() || ! BIS_Emails::enabled( BIS_Emails::RESTOCK ) || ! self::in_stock( $product_id, $variation_id ) ) {
			return;
		}
		$batch = (int) BIS_Settings::get()['batch'];
		$rows  = BIS_Store::due( $product_id, $variation_id, (int) $after, $batch );
		$last  = (int) $after;
		$fail  = 0;
		foreach ( $rows as $row ) {
			$last = (int) $row['id'];
			try {
				if ( BIS_Emails::send_restock( $row ) ) {
					BIS_Store::mark_sent( (int) $row['id'] );
					/**
					 * After a back-in-stock e-mail left.
					 *
					 * @param int $request_id   Request id.
					 * @param int $product_id   Product id.
					 * @param int $variation_id Variation id.
					 */
					do_action( 'bis_sent', (int) $row['id'], $product_id, $variation_id );
				} else {
					++$fail;
				}
			} catch ( \Throwable $e ) {
				++$fail;
				self::log( $e->getMessage() );
			}
		}
		if ( $fail > 0 ) {
			/* translators: 1: failed e-mails, 2: product id */
			self::log( sprintf( '%1$d back-in-stock e-mails were not sent for product %2$d; they stay active and the daily job retries them.', $fail, $variation_id ? $variation_id : $product_id ) );
		}
		if ( count( $rows ) >= $batch && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 30, 'bis_send', array( $product_id, $variation_id, $last ), self::GROUP );
		}
	}

	/** «Στείλε τώρα» in the admin: the first batch in a few seconds. */
	public static function send_now( int $product_id, int $variation_id ): bool {
		if ( ! self::in_stock( $product_id, $variation_id ) ) {
			return false;
		}
		return self::schedule( $product_id, $variation_id, 5 );
	}

	public static function schedule_daily(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		// Checked once an hour at most, not on every request.
		if ( get_transient( 'bis_daily_ok' ) ) {
			return;
		}
		if ( false === as_next_scheduled_action( 'bis_daily', array(), self::GROUP ) ) {
			$tz    = wp_timezone();
			$first = new DateTimeImmutable( 'tomorrow 03:17', $tz );
			as_schedule_recurring_action( $first->getTimestamp(), DAY_IN_SECONDS, 'bis_daily', array(), self::GROUP );
		}
		set_transient( 'bis_daily_ok', 1, HOUR_IN_SECONDS );
	}

	public static function daily(): void {
		BIS_Store::cleanup();
		if ( 'off' === BIS_Settings::mode() || ! BIS_Emails::enabled( BIS_Emails::RESTOCK ) ) {
			return;
		}
		foreach ( BIS_Store::waiting_pairs() as $p ) {
			$pid = (int) $p['product_id'];
			$vid = (int) $p['variation_id'];
			if ( self::in_stock( $pid, $vid ) ) {
				self::schedule( $pid, $vid, 60 );
			}
		}
	}

	private static function log( string $msg ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $msg, array( 'source' => 'back-in-stock' ) );
		}
	}
}

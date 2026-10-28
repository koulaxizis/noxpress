<?php
/**
 * BRC_Jobs — background work (Bible §12): Action Scheduler of WooCommerce
 * with the group "broadcast", otherwise WP-Cron.
 *
 * Hooks:
 *  - brc_tick           every 5 minutes while a module is on: posts due
 *                       items, checks YouTube and Instagram, the weekly
 *                       digest, retention
 *  - brc_run            one extra run soon after something was queued
 *  - brc_product_check  ( product id ): a product published p_delay
 *                       minutes ago is checked and queued (products module)
 *
 * Every hook is registered even when its module is off: the handler then
 * stops quietly, so no job is ever left without a callback (Bible §20).
 * A run holds the brc_lock option, so two runs never post the same item;
 * a lock older than 5 minutes is taken over.
 */

defined( 'ABSPATH' ) || exit;

final class BRC_Jobs {

	const GROUP    = 'broadcast';
	const BUDGET   = 20;   // Seconds per run.
	const PER_RUN  = 10;   // Items posted per run at most.
	const RETRY_IN = array( 60, 300, 1800 );

	/** @var bool A run was already asked for in this request. */
	private static $soon = false;

	public static function init(): void {
		add_action( 'brc_tick', array( __CLASS__, 'run' ) );
		add_action( 'brc_run', array( __CLASS__, 'run' ) );
		add_action( 'brc_product_check', array( __CLASS__, 'product_check' ), 10, 1 );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure' ) );
	}

	public static function schedules( $s ): array {
		$s             = is_array( $s ) ? $s : array();
		$s['brc_5min'] = array(
			'interval' => 300,
			'display'  => 'Broadcast (5 min)',
		);
		return $s;
	}

	private static function as_ok(): bool {
		return function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' );
	}

	/** True when any module is on (the tick has work). */
	private static function needed(): bool {
		foreach ( BRC_Settings::MODULES as $m ) {
			if ( BRC_Settings::module( $m ) ) {
				return true;
			}
		}
		return false;
	}

	/** Schedules the 5-minute tick while a module is on, removes it otherwise. */
	public static function ensure(): void {
		if ( ! self::needed() || Broadcast::killed() ) {
			self::unschedule_all();
			return;
		}
		if ( self::as_ok() ) {
			if ( false === as_next_scheduled_action( 'brc_tick', array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + 60, 300, 'brc_tick', array(), self::GROUP );
			}
			wp_clear_scheduled_hook( 'brc_tick' );
			return;
		}
		if ( ! wp_next_scheduled( 'brc_tick' ) ) {
			wp_schedule_event( time() + 60, 'brc_5min', 'brc_tick' );
		}
	}

	public static function unschedule_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			// Hook only or group only: with an args array Action Scheduler
			// matches only actions without args (Bible §12).
			as_unschedule_all_actions( 'brc_tick' );
			as_unschedule_all_actions( 'brc_run' );
			as_unschedule_all_actions( 'brc_product_check' );
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
		wp_unschedule_hook( 'brc_tick' );
		wp_unschedule_hook( 'brc_run' );
		wp_unschedule_hook( 'brc_product_check' );
	}

	/** One extra run in $delay seconds (once per request). */
	public static function soon( int $delay ): void {
		if ( self::$soon ) {
			return;
		}
		self::$soon = true;
		$at         = time() + max( 5, $delay );
		if ( self::as_ok() ) {
			$next = as_next_scheduled_action( 'brc_run', array(), self::GROUP );
			if ( ! is_int( $next ) || $next > $at ) {
				as_schedule_single_action( $at, 'brc_run', array(), self::GROUP );
			}
			return;
		}
		// WP-Cron refuses the same event within 10 minutes; the tick covers it then.
		wp_schedule_single_event( $at, 'brc_run' );
	}

	/** A product check later (products module). */
	public static function later_product( int $product_id, int $delay ): void {
		$at   = time() + max( 30, $delay );
		$args = array( $product_id );
		if ( self::as_ok() ) {
			if ( false === as_next_scheduled_action( 'brc_product_check', $args, self::GROUP ) ) {
				as_schedule_single_action( $at, 'brc_product_check', $args, self::GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( 'brc_product_check', $args ) ) {
			wp_schedule_single_event( $at, 'brc_product_check', $args );
		}
	}

	public static function product_check( $product_id = 0 ): void {
		if ( Broadcast::killed() || ! BRC_Settings::module( 'products' ) || ! Broadcast::load_source( 'products' ) ) {
			return;
		}
		BRC_Src_Products::check( (int) $product_id );
	}

	/* ---------------------------------------------------------------------
	 * The run
	 * ------------------------------------------------------------------- */

	private static function lock(): bool {
		if ( add_option( 'brc_lock', (string) time(), '', false ) ) {
			return true;
		}
		$t = (int) get_option( 'brc_lock', 0 );
		if ( $t < time() - 5 * MINUTE_IN_SECONDS ) {
			update_option( 'brc_lock', (string) time(), false );
			return true;
		}
		return false;
	}

	private static function unlock(): void {
		delete_option( 'brc_lock' );
	}

	public static function run(): void {
		if ( Broadcast::killed() || ! self::lock() ) {
			return;
		}
		$start = time();
		try {
			BRC_Settings::flush();
			$s = BRC_Settings::get();

			if ( 'off' !== $s['mode'] ) {
				BRC_Queue::unstick();
				foreach ( BRC_Queue::due( self::PER_RUN ) as $id ) {
					if ( time() - $start > self::BUDGET ) {
						break;
					}
					if ( BRC_Queue::claim( $id ) ) {
						self::send_one( $id );
					}
				}

				if ( BRC_Settings::module( 'youtube' ) && time() - $start < self::BUDGET && Broadcast::load_source( 'youtube' ) ) {
					BRC_Src_Youtube::maybe_poll();
				}
				if ( BRC_Settings::module( 'instagram' ) && time() - $start < self::BUDGET && Broadcast::load_source( 'instagram' ) ) {
					BRC_Src_Instagram::maybe_poll();
				}
				if ( BRC_Settings::module( 'digest' ) && time() - $start < self::BUDGET && Broadcast::load_source( 'digest' ) ) {
					BRC_Src_Digest::maybe_run();
				}
			}

			$notes = BRC_Queue::notes();
			if ( empty( $notes['cleanup'] ) || (int) $notes['cleanup'] < time() - DAY_IN_SECONDS ) {
				BRC_Queue::cleanup( $s['keep_days'] );
				BRC_Queue::note( 'cleanup', time() );
			}
		} catch ( \Throwable $e ) {
			BRC_Queue::note( 'error', array( time(), mb_substr( BRC_Crypt::mask( $e->getMessage() ), 0, 300 ) ) );
		} finally {
			self::unlock();
		}

		// More due now (a burst, or the budget ran out): one more run soon.
		$next = BRC_Queue::next_due();
		if ( null !== $next && $next < 300 && 'off' !== BRC_Settings::get()['mode'] ) {
			self::soon( $next );
		}
	}

	/** Posts one claimed item to its destination. */
	public static function send_one( int $id ): void {
		$row = BRC_Queue::get( $id );
		if ( ! $row || 'sending' !== $row['status'] ) {
			return;
		}
		$s = BRC_Settings::get();

		if ( ! BRC_Settings::module( $row['dest'] ) ) {
			BRC_Queue::finish( $id, 'skipped', '', 'off' );
			return;
		}
		if ( 'test' === $s['mode'] ) {
			BRC_Queue::finish( $id, 'test', self::preview( $row ) );
			return;
		}

		$r = array(
			'ok'     => false,
			'retry'  => false,
			'result' => 'unknown destination',
			'reason' => 'dest',
		);
		if ( 'instagram' === $row['source'] && Broadcast::load_source( 'instagram' ) ) {
			$row['msg'] = BRC_Src_Instagram::fresh( $row['msg'], $row['skey'] );
		}
		if ( 'viber' === $row['dest'] ) {
			require_once BRC_PATH . 'includes/class-viber.php';
			$r = BRC_Viber::post( $row['msg'] );
		}

		if ( $r['ok'] ) {
			BRC_Queue::finish( $id, 'sent', (string) $r['result'] );
			/**
			 * After an item was posted.
			 *
			 * @param int   $id  Queue row id.
			 * @param array $row The row before the post (source, skey, dest, msg).
			 */
			do_action( 'brc_item_sent', $id, $row );
			return;
		}
		$tries = $row['tries'];
		if ( $r['retry'] && $tries < count( self::RETRY_IN ) ) {
			BRC_Queue::finish( $id, 'queued', (string) $r['result'], (string) $r['reason'], self::RETRY_IN[ $tries ] );
			return;
		}
		BRC_Queue::finish( $id, 'failed', (string) $r['result'], (string) $r['reason'] );
	}

	/** What the destination would get, for test mode. */
	private static function preview( array $row ): string {
		if ( 'viber' === $row['dest'] ) {
			require_once BRC_PATH . 'includes/class-viber.php';
			return 'test: ' . wp_json_encode( BRC_Viber::payload( $row['msg'], 'FROM' ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		return 'test';
	}
}

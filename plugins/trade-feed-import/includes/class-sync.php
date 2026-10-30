<?php
/**
 * TFI_Sync — the daily job (Action Scheduler, group trade-feed-import).
 *
 * One job at a time, state in tfi_job (autoload off):
 *  1. pages  every page of the feed (100 titles each, by id): titles of
 *            ours are updated (TFI_Importer::update), new titles are
 *            imported when the setting auto_new is on, titles whose ISBN
 *            belongs to a product that is not ours are only counted.
 *  2. gone   our products the feed no longer listed: TFI_Importer::gone().
 *            Skipped when the feed listed nothing at all, so a wrong
 *            answer never takes a whole catalogue off sale.
 *
 * Each step works for at most 10 s, saves the state and schedules the
 * next one. A lock (tfi_lock, 120 s) keeps two steps from running
 * together; a safety action two minutes later picks the job up again if
 * a step dies; a job of 6 hours or 500 steps is stopped and reported. A
 * 429 of the publisher moves the next step to its Retry-After. The daily
 * run (tfi_daily) is at the chosen hour in the site's time zone. The
 * summary of the last job is in tfi_last.
 */

defined( 'ABSPATH' ) || exit;

final class TFI_Sync {

	const JOB    = 'tfi_job';
	const LOCK   = 'tfi_lock';
	const LAST   = 'tfi_last';
	const GROUP  = 'trade-feed-import';
	const BUDGET = 10;

	/** The titles of the last full listing (light rows, for the Titles tab). */
	const CATALOG = 'tfi_catalog';

	public static function init(): void {
		add_action( 'tfi_step', array( __CLASS__, 'step' ) );
		add_action( 'tfi_safety', array( __CLASS__, 'step' ) );
		add_action( 'tfi_daily', array( __CLASS__, 'daily' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_daily' ) );
	}

	/* ---------------------------------------------------------------------
	 * Scheduling
	 * ------------------------------------------------------------------- */

	private static function as_ok(): bool {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_next_scheduled_action' );
	}

	private static function schedule( string $hook, int $ts ): void {
		if ( self::as_ok() ) {
			as_schedule_single_action( $ts, $hook, array(), self::GROUP );
		} else {
			wp_schedule_single_event( $ts, $hook );
		}
	}

	public static function next( string $hook ) {
		if ( self::as_ok() ) {
			return as_next_scheduled_action( $hook, array(), self::GROUP );
		}
		return wp_next_scheduled( $hook );
	}

	private static function unschedule( string $hook ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, array(), self::GROUP );
		}
		wp_clear_scheduled_hook( $hook );
	}

	/** The next daily run (Unix) at the chosen hour, site time zone. */
	public static function next_daily_time(): int {
		$tz  = wp_timezone();
		$now = new DateTimeImmutable( 'now', $tz );
		$at  = $now->setTime( (int) TFI_Settings::get()['hour'], 0, 0 );
		if ( $at <= $now ) {
			$at = $at->modify( '+1 day' );
		}
		return $at->getTimestamp();
	}

	/** Exactly one daily run while the sync is on and connected; wakes a stuck job (admin only). */
	public static function ensure_daily(): void {
		if ( self::job() && ! self::busy() && ! self::next( 'tfi_step' ) && ! self::next( 'tfi_safety' ) ) {
			self::schedule( 'tfi_step', time() );
		}
		$on = TFI_Settings::get()['sync_on'] && TFI_Settings::connected() && ! Trade_Feed_Import::killed();
		if ( ! $on ) {
			if ( self::next( 'tfi_daily' ) ) {
				self::unschedule( 'tfi_daily' );
			}
			return;
		}
		if ( ! self::next( 'tfi_daily' ) ) {
			self::schedule( 'tfi_daily', self::next_daily_time() );
		}
	}

	/** After the settings change: the daily run moves to the new hour. */
	public static function reschedule_daily(): void {
		self::unschedule( 'tfi_daily' );
		self::ensure_daily();
	}

	public static function daily(): void {
		self::schedule( 'tfi_daily', self::next_daily_time() );
		if ( TFI_Settings::get()['sync_on'] ) {
			self::start();
		}
	}

	/* ---------------------------------------------------------------------
	 * Job
	 * ------------------------------------------------------------------- */

	public static function job(): ?array {
		$j = get_option( self::JOB, null );
		return is_array( $j ) && isset( $j['phase'] ) ? $j : null;
	}

	private static function busy(): bool {
		return (int) get_transient( self::LOCK ) > time();
	}

	/**
	 * Starts a job; false when one is already running or the plugin is not
	 * connected.
	 *
	 * @param bool $first The first import (set-up guide): every new title is
	 *                    imported and products of the shop with the same
	 *                    ISBN are linked, whatever the settings say.
	 */
	public static function start( bool $first = false ): bool {
		if ( self::job() || ! TFI_Settings::connected() || Trade_Feed_Import::killed() ) {
			return false;
		}
		update_option(
			self::JOB,
			array(
				'phase'   => 'pages',
				'first'   => $first,
				'list'    => array(),
				'page'    => 1,
				'pages'   => 0,
				'seen'    => array(),
				'started' => time(),
				'steps'   => 0,
				'n'       => array(
					'listed'    => 0,
					'updated'   => 0,
					'unchanged' => 0,
					'created'   => 0,
					'exists'    => 0,
					'linked'    => 0,
					'gone'      => 0,
					'errors'    => 0,
				),
				'error'   => '',
			),
			false
		);
		self::schedule( 'tfi_step', time() );
		return true;
	}

	public static function cancel(): void {
		$j = self::job();
		if ( $j ) {
			self::finish( $j, __( 'Ακυρώθηκε.', 'trade-feed-import' ) );
		}
	}

	public static function step(): void {
		$j = self::job();
		if ( ! $j || self::busy() ) {
			return;
		}
		set_transient( self::LOCK, time() + 120, 120 );
		self::schedule( 'tfi_safety', time() + 120 );

		try {
			$j = self::run( $j );
		} catch ( \Throwable $e ) {
			$j['error'] = mb_substr( $e->getMessage(), 0, 300 );
			$j['phase'] = 'done';
		}

		$ids = TFI_Importer::take_changed();
		if ( $ids ) {
			do_action( 'noxpress_products_changed', $ids );
		}

		self::unschedule( 'tfi_safety' );
		delete_transient( self::LOCK );

		if ( 'done' === $j['phase'] ) {
			self::finish( $j, $j['error'] );
			return;
		}
		update_option( self::JOB, $j, false );
		$wait = TFI_Client::waiting();
		self::schedule( 'tfi_step', time() + ( $wait > 0 ? $wait + 5 : 0 ) );
	}

	/** Works on the job for at most BUDGET seconds. */
	private static function run( array $j ): array {
		$t0 = time();
		++$j['steps'];
		if ( $j['steps'] > 500 || time() - (int) $j['started'] > 6 * HOUR_IN_SECONDS ) {
			$j['error'] = __( 'Ο συγχρονισμός σταμάτησε: κράτησε πάνω από 6 ώρες ή 500 βήματα.', 'trade-feed-import' );
			$j['phase'] = 'done';
			return $j;
		}
		$first = ! empty( $j['first'] );
		$auto  = $first || TFI_Settings::get()['auto_new'];

		while ( 'pages' === $j['phase'] && time() - $t0 < self::BUDGET ) {
			if ( TFI_Client::waiting() > 0 ) {
				return $j;
			}
			$res = TFI_Client::products( (int) $j['page'] );
			if ( is_wp_error( $res ) ) {
				if ( 'tfi_rate' === $res->get_error_code() ) {
					return $j; // Next step after the publisher's Retry-After.
				}
				$j['error'] = $res->get_error_message();
				$j['phase'] = 'done'; // Nothing is marked gone after a failed page.
				return $j;
			}
			$j['pages'] = (int) $res['total_pages'];
			$records    = array_values( array_filter( array_map( array( 'TFI_Record', 'from' ), $res['items'] ) ) );
			$where      = TFI_Importer::lookup( $records );
			foreach ( $records as $r ) {
				$j['seen'][] = (int) $r['id'];
				$j['list'][] = self::row( $r );
				++$j['n']['listed'];
				$w = $where[ $r['id'] ];
				if ( 'ours' === $w['state'] ) {
					$out = TFI_Importer::update( $w['pid'], $r );
				} elseif ( 'new' === $w['state'] && $auto ) {
					$out = TFI_Importer::import( $r );
				} elseif ( 'exists' === $w['state'] && $first ) {
					$out = array( 'result' => TFI_Importer::link( $w['pid'], $r ) ? 'linked' : 'exists' );
				} elseif ( 'exists' === $w['state'] ) {
					$out = array( 'result' => 'exists' );
				} else {
					continue;
				}
				$k = 'error' === $out['result'] ? 'errors' : $out['result'];
				if ( isset( $j['n'][ $k ] ) ) {
					++$j['n'][ $k ];
				}
			}
			if ( (int) $j['page'] >= (int) $res['total_pages'] || ! $res['items'] ) {
				$j['phase'] = 'gone';
			} else {
				++$j['page'];
			}
		}

		if ( 'gone' === $j['phase'] ) {
			if ( $j['list'] ) {
				update_option( self::CATALOG, $j['list'], false );
				update_option( self::CATALOG . '_at', time(), false );
			}
			if ( $j['seen'] ) {
				$seen = array_flip( array_map( 'intval', $j['seen'] ) );
				foreach ( TFI_Importer::ours() as $rid => $pid ) {
					if ( ! isset( $seen[ $rid ] ) && '1' !== (string) get_post_meta( $pid, '_tfi_gone', true ) ) {
						TFI_Importer::gone( $pid );
						++$j['n']['gone'];
					}
				}
			} elseif ( TFI_Importer::ours() ) {
				$j['error'] = __( 'Ο εκδότης δεν έδωσε κανέναν τίτλο: τα προϊόντα σου έμειναν όπως ήταν. Έλεγξε τη σύνδεση.', 'trade-feed-import' );
			}
			$j['phase'] = 'done';
		}
		return $j;
	}

	private static function finish( array $j, string $error ): void {
		delete_option( self::JOB );
		self::unschedule( 'tfi_step' );
		self::unschedule( 'tfi_safety' );
		$last = array(
			'started' => (int) $j['started'],
			'ended'   => time(),
			'n'       => $j['n'],
			'first'   => ! empty( $j['first'] ),
			'error'   => $error,
		);
		update_option( self::LAST, $last, false );
		TFI_Log::add(
			'sync',
			0,
			'',
			'',
			array(),
			sprintf(
				/* translators: 1: listed, 2: updated, 3: created, 4: linked, 5: gone, 6: errors */
				__( 'Στο feed: %1$d · ενημερώθηκαν: %2$d · νέα: %3$d · συνδέθηκαν: %4$d · εκτός feed: %5$d · σφάλματα: %6$d', 'trade-feed-import' ),
				(int) $j['n']['listed'],
				(int) $j['n']['updated'],
				(int) $j['n']['created'],
				(int) $j['n']['linked'],
				(int) $j['n']['gone'],
				(int) $j['n']['errors']
			) . ( '' !== $error ? ' · ' . $error : '' )
		);
	}

	/** A light row of the catalogue (no descriptions). */
	public static function row( array $r ): array {
		return array(
			'id'     => (int) $r['id'],
			'isbn'   => $r['isbn'],
			'd'      => $r['isbn_digits'],
			'title'  => $r['title'],
			'pub'    => $r['publisher'],
			'retail' => $r['retail'],
			'trade'  => $r['trade'],
			'status' => $r['status'],
			'rel'    => $r['release'],
		);
	}

	/** The light rows of the last full listing. */
	public static function catalog(): array {
		$c = get_option( self::CATALOG, array() );
		return is_array( $c ) ? array_values( array_filter( $c, 'is_array' ) ) : array();
	}

	public static function last(): ?array {
		$l = get_option( self::LAST, null );
		return is_array( $l ) ? $l : null;
	}
}

<?php
/**
 * LIM_Queue — background batches and the library hooks.
 *
 * One event every 5 minutes (Action Scheduler with group "lean-images"
 * when it is there, e.g. with WooCommerce; else WP-Cron), scheduled only
 * while «running» is on. Each batch converts waiting attachments (newest
 * first) until it reaches the batch size, the time budget, or 80% of the
 * memory limit. A lock (an option added atomically, 10 minutes at most)
 * keeps two batches from running together, whoever starts them (event,
 * button, ability).
 *
 * The host's load average is not used by default: on shared hosting it is
 * the load of the whole machine, which would pause us forever. A site can
 * set one with the filter lim_max_load (e.g. 4.0); 0 = off.
 *
 * Library hooks: a new upload waits for the next batch (never converted
 * inside the upload request); a regenerate, an edit or a rename (other
 * file names in the metadata) deletes our old sidecars and waits again;
 * deleting an attachment deletes our sidecars.
 *
 * After batches that made files (and not in test mode), the page cache is
 * purged at most once an hour and once when the queue is empty, through
 * Noxpress Core (Fast Pages, WP Rocket, LiteSpeed…) or the suite hook.
 */

defined( 'ABSPATH' ) || exit;

final class LIM_Queue {

	const HOOK   = 'lim_tick';
	const GROUP  = 'lean-images';
	const LOCK   = 'lim_lock';
	const LAST   = 'lim_last';
	const STATS  = 'lim_stats';
	const PURGED = 'lim_purged';

	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval -- 5 minutes, by design.
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'init', array( __CLASS__, 'ensure' ), 20 );

		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'generated' ), 99, 2 );
		add_filter( 'wp_update_attachment_metadata', array( __CLASS__, 'updated' ), 99, 2 );
		add_action( 'delete_attachment', array( __CLASS__, 'deleted' ) );
	}

	public static function schedules( $s ) {
		$s                  = is_array( $s ) ? $s : array();
		$s['lim_five_min'] = array(
			'interval' => 300,
			'display'  => 'Lean Images: every 5 minutes',
		);
		return $s;
	}

	private static function has_as(): bool {
		return function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_unschedule_all_actions' );
	}

	/** The event exists exactly while running (and not killed). */
	public static function ensure(): void {
		$want = LIM_Settings::get()['running'] && ! Lean_Images::killed();
		if ( ! $want ) {
			if ( wp_next_scheduled( self::HOOK ) || ( self::has_as() && as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) ) {
				self::unschedule();
			}
			return;
		}
		if ( self::has_as() ) {
			if ( ! as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + 60, 300, self::HOOK, array(), self::GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'lim_five_min', self::HOOK );
		}
	}

	public static function unschedule(): void {
		if ( self::has_as() ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** Starts or pauses the batches. */
	public static function set_running( bool $on ): void {
		LIM_Settings::update( array( 'running' => $on ) );
		self::ensure();
	}

	/* =====================================================================
	 * Batches
	 * =================================================================== */

	/** The event: one batch when allowed now. */
	public static function tick(): void {
		$s = LIM_Settings::get();
		if ( ! $s['running'] || Lean_Images::killed() ) {
			return;
		}
		if ( $s['night'] && ! self::night() ) {
			return;
		}
		self::batch();
	}

	/** True between 01:00 and 07:00, site time. */
	public static function night(): bool {
		$h = (int) wp_date( 'G' );
		return $h >= 1 && $h < 7;
	}

	/**
	 * Runs one batch. Returns the summary also stored in lim_last, or
	 * array( 'busy' => true ) when another batch holds the lock.
	 */
	public static function batch( int $max = 0, int $budget = 0 ): array {
		if ( ! self::lock() ) {
			return array( 'busy' => true );
		}
		$s      = LIM_Settings::get();
		$max    = $max > 0 ? $max : (int) $s['batch'];
		$budget = $budget > 0 ? $budget : (int) $s['budget'];
		$sum    = array(
			't'      => time(),
			'done'   => 0,
			'retry'  => 0,
			'failed' => 0,
			'made'   => 0,
			'src'    => 0,
			'out'    => 0,
			'secs'   => 0.0,
			'why'    => 'empty',
			'left'   => 0,
			'err'    => '',
		);
		$start = microtime( true );
		$keep  = true;
		try {
			if ( ! array_filter( LIM_Settings::formats(), array( 'LIM_Convert', 'supports' ) ) ) {
				$sum['why'] = 'unsupported';
			} else {
				$ids = self::pending( $max );
				// Nothing to do: keep the summary of the last real batch.
				$keep = ! empty( $ids );
				foreach ( $ids as $i => $id ) {
					if ( $i > 0 ) {
						$stop = self::stop_reason( $start, $budget );
						if ( '' !== $stop ) {
							$sum['why'] = $stop;
							break;
						}
					}
					$r = LIM_Convert::attachment( (int) $id );
					if ( isset( $sum[ $r['state'] ] ) ) {
						++$sum[ $r['state'] ];
					}
					$sum['made'] += $r['made'];
					$sum['src']  += $r['src'];
					$sum['out']  += $r['out'];
					if ( '' !== $r['err'] ) {
						$sum['err'] = '#' . $id . ' ' . $r['err'];
					}
					$sum['why'] = 'size';
				}
			}
		} catch ( \Throwable $e ) {
			$sum['why'] = 'error';
			$sum['err'] = $e->getMessage();
		}
		$sum['secs'] = round( microtime( true ) - $start, 1 );
		$sum['left'] = self::count_pending();
		if ( $keep ) {
			update_option( self::LAST, $sum, false );
		}
		self::unlock();

		if ( $sum['made'] > 0 ) {
			self::maybe_purge( 0 === $sum['left'] );
		}
		return $sum;
	}

	/** '' to go on, or why the batch stops before the next attachment. */
	private static function stop_reason( float $start, int $budget ): string {
		if ( microtime( true ) - $start >= $budget ) {
			return 'time';
		}
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit > 0 && memory_get_usage( true ) > 0.8 * $limit ) {
			return 'memory';
		}
		$max_load = (float) apply_filters( 'lim_max_load', 0.0 );
		if ( $max_load > 0 && function_exists( 'sys_getloadavg' ) ) {
			$load = sys_getloadavg();
			if ( is_array( $load ) && isset( $load[0] ) && (float) $load[0] > $max_load ) {
				return 'load';
			}
		}
		return '';
	}

	private static function lock(): bool {
		if ( add_option( self::LOCK, time(), '', false ) ) {
			return true;
		}
		$since = (int) get_option( self::LOCK, 0 );
		if ( $since > 0 && time() - $since > 600 ) {
			// A batch that died (fatal, timeout): take over.
			delete_option( self::LOCK );
			return add_option( self::LOCK, time(), '', false );
		}
		return false;
	}

	private static function unlock(): void {
		delete_option( self::LOCK );
	}

	/** True while a batch runs. */
	public static function busy(): bool {
		$since = (int) get_option( self::LOCK, 0 );
		return $since > 0 && time() - $since <= 600;
	}

	/** Mime types for SQL. */
	private static function mime_sql(): string {
		return "'" . implode( "','", array_map( 'esc_sql', LIM_Files::MIMES ) ) . "'";
	}

	/** Waiting attachment ids: never tried first (newest first), then retries. */
	public static function pending( int $max ): array {
		global $wpdb;
		$max = max( 1, min( 500, $max ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- mime list is constant and escaped.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type = 'attachment' AND p.post_mime_type IN (" . self::mime_sql() . ") AND ( m.meta_id IS NULL OR m.meta_value = 'retry' ) ORDER BY ( m.meta_id IS NULL ) DESC, p.ID DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				LIM_Files::STATE,
				$max
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/** Any convertible attachment ids, newest first (for the sample). */
	public static function any( int $max ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN (" . self::mime_sql() . ') ORDER BY ID DESC LIMIT %d', max( 1, min( 500, $max ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $ids );
	}

	/** array( total, done, retry, failed, waiting ). */
	public static function counts(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN (" . self::mime_sql() . ')' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.meta_value AS state, COUNT(*) AS n FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type = 'attachment' AND p.post_mime_type IN (" . self::mime_sql() . ') GROUP BY m.meta_value', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				LIM_Files::STATE
			)
		);
		$c = array(
			'total'  => $total,
			'done'   => 0,
			'retry'  => 0,
			'failed' => 0,
		);
		foreach ( (array) $rows as $r ) {
			if ( isset( $c[ $r->state ] ) && 'total' !== $r->state ) {
				$c[ $r->state ] = (int) $r->n;
			}
		}
		$c['waiting'] = max( 0, $total - $c['done'] - $c['failed'] - $c['retry'] ) + $c['retry'];
		return $c;
	}

	public static function count_pending(): int {
		return self::counts()['waiting'];
	}

	/* =====================================================================
	 * Stats (sums of the records, kept up to date per attachment)
	 * =================================================================== */

	public static function stats(): array {
		$s = get_option( self::STATS, array() );
		$s = is_array( $s ) ? $s : array();
		foreach ( array( 'src_webp', 'webp', 'src_avif', 'avif', 'files' ) as $k ) {
			$s[ $k ] = isset( $s[ $k ] ) ? max( 0, (int) $s[ $k ] ) : 0;
		}
		return $s;
	}

	private static function stats_delta( array $rec, int $sign ): void {
		if ( empty( $rec['f'] ) || ! is_array( $rec['f'] ) ) {
			return;
		}
		$s = self::stats();
		foreach ( $rec['f'] as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			foreach ( LIM_Files::FORMATS as $fmt ) {
				if ( ! empty( $f[ $fmt ] ) ) {
					$s[ 'src_' . $fmt ] += $sign * (int) ( isset( $f['s'] ) ? $f['s'] : 0 );
					$s[ $fmt ]          += $sign * (int) $f[ $fmt ];
					$s['files']         += $sign;
				}
			}
		}
		foreach ( $s as $k => $v ) {
			$s[ $k ] = max( 0, (int) $v );
		}
		update_option( self::STATS, $s, false );
	}

	public static function stats_add( array $rec ): void {
		self::stats_delta( $rec, 1 );
	}

	public static function stats_remove( array $rec ): void {
		self::stats_delta( $rec, -1 );
	}

	/* =====================================================================
	 * Library hooks
	 * =================================================================== */

	/** A new upload or a regenerate: our old files go, the image waits. */
	public static function generated( $meta, $id ) {
		try {
			$id = (int) $id;
			if ( $id > 0 && LIM_Files::record( $id ) ) {
				LIM_Files::forget( $id );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $meta;
	}

	/** Metadata saved: when its files changed (edit, rename), start over. */
	public static function updated( $meta, $id ) {
		try {
			$id  = (int) $id;
			$rec = $id > 0 ? LIM_Files::record( $id ) : array();
			if ( $rec && isset( $rec['sig'] ) && LIM_Files::signature( $meta ) !== $rec['sig'] ) {
				LIM_Files::forget( $id );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $meta;
	}

	public static function deleted( $id ): void {
		try {
			LIM_Files::forget( (int) $id );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/* =====================================================================
	 * Page cache
	 * =================================================================== */

	/** Purges at most once an hour, and when the queue is empty. */
	public static function maybe_purge( bool $finished ): void {
		if ( LIM_Settings::get()['test'] ) {
			return;
		}
		$last = (int) get_option( self::PURGED, 0 );
		if ( $finished || time() - $last >= HOUR_IN_SECONDS ) {
			self::purge();
		}
	}

	/** Purges every page cache we know (names of the caches purged). */
	public static function purge(): array {
		update_option( self::PURGED, time(), false );
		if ( class_exists( 'Noxpress_Core' ) && method_exists( 'Noxpress_Core', 'purge_page_cache' ) ) {
			$r = Noxpress_Core::purge_page_cache();
			return is_array( $r ) ? $r : array();
		}
		do_action( 'noxpress_purge_page_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- suite hook (Bible §6).
		return array();
	}
}

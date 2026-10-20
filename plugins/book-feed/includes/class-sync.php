<?php
/**
 * BKF_Sync — the background job (Bible §11 1.40, §12).
 *
 * One job at a time, state in bkf_job (autoload off):
 *  1. linked   our products in batches of 50: their records are fetched by
 *              product_id and applied (BKF_Importer::update); a title the
 *              feed no longer returns is marked gone. A batch where the
 *              feed returns nothing at all stops the job instead (a wrong
 *              query must never take a whole catalogue off sale).
 *  2. rules    each rule that is due (every X days, 1.7.0; the list is
 *              fixed when the job starts): the titles matching its
 *              search (newest first, since the rule's last run, less
 *              one day), new ones imported with the rule's status, up
 *              to the rule's limit per run. "Run now" on a rule starts
 *              a job with that rule alone and no phase 1.
 *
 * When rules brought new titles, the end of the job sends one summary
 * email (setting notify) and writes one history line per rule.
 *
 * Steps run through Action Scheduler (group book-feed): each step works
 * for at most 10 s, saves the state and schedules the next one. A lock
 * (bkf_lock, 120 s) keeps two steps from running together; a safety
 * action one minute later picks the job up again if a step dies; a job
 * that runs for 12 hours or 2000 steps is stopped and reported. The
 * daily run (bkf_daily) is scheduled for the chosen hour in the site's
 * time zone. WP-CLI runs the same steps in its own process.
 */

defined( 'ABSPATH' ) || exit;

final class BKF_Sync {

	const JOB    = 'bkf_job';
	const LOCK   = 'bkf_lock';
	const LAST   = 'bkf_last';
	const GROUP  = 'book-feed';
	const BUDGET = 10;
	const BATCH  = 50;
	const PAGE   = 50;

	public static function init(): void {
		add_action( 'bkf_step', array( __CLASS__, 'step' ) );
		add_action( 'bkf_safety', array( __CLASS__, 'step' ) );
		add_action( 'bkf_daily', array( __CLASS__, 'daily' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_daily' ) );
	}

	/* ---------------------------------------------------------------------
	 * Scheduling
	 * ------------------------------------------------------------------- */

	public static function as_ok(): bool {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_next_scheduled_action' );
	}

	public static function schedule( string $hook, int $ts ): void {
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

	public static function unschedule( string $hook ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook );
		}
		wp_clear_scheduled_hook( $hook );
	}

	/** The next daily run time (Unix) at the chosen hour, site time zone. */
	public static function next_daily_time(): int {
		$s   = BKF_Settings::get();
		$tz  = wp_timezone();
		$now = new DateTimeImmutable( 'now', $tz );
		$at  = $now->setTime( (int) $s['hour'], 0, 0 );
		if ( $at <= $now ) {
			$at = $at->modify( '+1 day' );
		}
		return $at->getTimestamp();
	}

	/**
	 * Keeps exactly one daily run scheduled while the sync is on, and wakes
	 * a job left without a scheduled step (admin only).
	 */
	public static function ensure_daily(): void {
		if ( self::job() && ! self::busy() && ! self::next( 'bkf_step' ) && ! self::next( 'bkf_safety' ) ) {
			self::schedule( 'bkf_step', time() );
		}
		$s = BKF_Settings::get();
		if ( ! $s['sync_on'] ) {
			if ( self::next( 'bkf_daily' ) ) {
				self::unschedule( 'bkf_daily' );
			}
			return;
		}
		if ( ! self::next( 'bkf_daily' ) ) {
			self::schedule( 'bkf_daily', self::next_daily_time() );
		}
	}

	/** After the settings change: the daily run moves to the new hour. */
	public static function reschedule_daily(): void {
		self::unschedule( 'bkf_daily' );
		self::ensure_daily();
	}

	public static function daily(): void {
		self::unschedule( 'bkf_daily' );
		self::ensure_daily();
		if ( BKF_Settings::ready() ) {
			self::start( 'daily' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Job state
	 * ------------------------------------------------------------------- */

	public static function job(): ?array {
		$j = get_option( self::JOB, null );
		return is_array( $j ) && isset( $j['id'], $j['phase'] ) ? $j : null;
	}

	/** Summary of the last finished job, or null. */
	public static function last(): ?array {
		$l = get_option( self::LAST, null );
		return is_array( $l ) && isset( $l['t'] ) ? $l : null;
	}

	private static function empty_stats(): array {
		return array(
			'checked'  => 0,
			'updated'  => 0,
			'gone'     => 0,
			'imported' => 0,
			'exists'   => 0,
			'errors'   => 0,
		);
	}

	/**
	 * Starts a job unless one is running.
	 *
	 * @param string $why  manual | daily | cli | rule.
	 * @param string $only a rule id: that rule alone, now, without phase 1.
	 */
	public static function start( string $why, bool $schedule = true, string $only = '' ): bool {
		if ( self::job() ) {
			return false;
		}
		$due = array();
		foreach ( BKF_Settings::rules() as $r ) {
			if ( '' !== $only ? $r['id'] === $only : BKF_Settings::rule_due( $r ) ) {
				$due[] = $r['id'];
			}
		}
		if ( '' !== $only && ! $due ) {
			return false;
		}
		$job = array(
			'id'       => substr( md5( uniqid( '', true ) ), 0, 12 ),
			'why'      => '' !== $only ? 'rule' : ( in_array( $why, array( 'manual', 'daily', 'cli' ), true ) ? $why : 'manual' ),
			'phase'    => '' !== $only ? 'rules' : 'linked',
			'cursor'   => 0,
			'rule'     => 0,
			'start'    => 0,
			'per_rule' => 0,
			'rules_ok' => array(),
			'due'      => $due,
			'per'      => array(),
			'stats'    => self::empty_stats(),
			'began'    => time(),
			'steps'    => 0,
			'error'    => '',
		);
		update_option( self::JOB, $job, false );
		if ( $schedule ) {
			self::schedule( 'bkf_step', time() );
		}
		return true;
	}

	/** Stops the running job (it is reported as stopped). */
	public static function cancel(): void {
		$job = self::job();
		if ( $job ) {
			$job['error'] = __( 'Ο συγχρονισμός σταμάτησε με το χέρι.', 'book-feed' );
			self::finish( $job );
		}
		self::unschedule( 'bkf_step' );
		self::unschedule( 'bkf_safety' );
		delete_option( self::LOCK );
	}

	private static function lock(): bool {
		$now = time();
		if ( add_option( self::LOCK, $now, '', false ) ) {
			return true;
		}
		$at = (int) get_option( self::LOCK, 0 );
		if ( $now - $at > 120 ) {
			update_option( self::LOCK, $now, false );
			return true;
		}
		return false;
	}

	private static function unlock(): void {
		delete_option( self::LOCK );
	}

	/** True when another step holds the lock (used by the AJAX import). */
	public static function busy(): bool {
		$at = (int) get_option( self::LOCK, 0 );
		return $at && time() - $at <= 120;
	}

	/** Runs a callback under the lock; false when busy. */
	public static function with_lock( callable $fn ) {
		if ( ! self::lock() ) {
			return false;
		}
		try {
			return $fn();
		} finally {
			self::unlock();
		}
	}

	/* ---------------------------------------------------------------------
	 * Steps
	 * ------------------------------------------------------------------- */

	/** One step (Action Scheduler / WP-Cron). */
	public static function step(): void {
		$job = self::job();
		if ( ! $job || ! self::lock() ) {
			return;
		}
		try {
			// Safety: picks the job up again if this step dies.
			self::unschedule( 'bkf_safety' );
			self::schedule( 'bkf_safety', time() + 60 );
			$job = self::work( $job, self::BUDGET );
			if ( 'done' === $job['phase'] ) {
				self::finish( $job );
			} else {
				update_option( self::JOB, $job, false );
				self::schedule( 'bkf_step', time() );
			}
			self::unschedule( 'bkf_safety' );
		} catch ( \Throwable $e ) {
			$job['error'] = $e->getMessage();
			self::finish( $job );
			self::unschedule( 'bkf_safety' );
		} finally {
			self::unlock();
			self::changed();
		}
	}

	/** Runs the whole job in this process (WP-CLI). */
	public static function run_inline( ?callable $tick = null ): ?array {
		$job = self::job();
		if ( ! $job ) {
			return null;
		}
		self::unschedule( 'bkf_step' );
		while ( $job && 'done' !== $job['phase'] ) {
			if ( ! self::lock() ) {
				sleep( 2 );
				continue;
			}
			try {
				$job = self::work( $job, self::BUDGET );
				update_option( self::JOB, $job, false );
			} catch ( \Throwable $e ) {
				$job['error'] = $e->getMessage();
				$job['phase'] = 'done';
			} finally {
				self::unlock();
				self::changed();
				BKF_Log::flush();
			}
			if ( $tick ) {
				$tick( $job );
			}
		}
		self::finish( $job );
		return self::last();
	}

	/** Fires noxpress_products_changed for what this request changed. */
	private static function changed(): void {
		$ids = BKF_Importer::take_changed();
		if ( $ids ) {
			do_action( 'noxpress_products_changed', $ids );
		}
	}

	/** Works on the job for up to $budget seconds; returns the new state. */
	private static function work( array $job, int $budget ): array {
		$t0 = microtime( true );
		++$job['steps'];
		if ( $job['steps'] > 2000 || time() - (int) $job['began'] > 12 * HOUR_IN_SECONDS ) {
			$job['error'] = __( 'Ο συγχρονισμός δεν τελείωσε σε 12 ώρες και σταμάτησε.', 'book-feed' );
			$job['phase'] = 'done';
			return $job;
		}
		$s = BKF_Settings::get();
		while ( 'done' !== $job['phase'] && microtime( true ) - $t0 < $budget ) {
			if ( 'linked' === $job['phase'] ) {
				$job = self::step_linked( $job );
			} elseif ( 'rules' === $job['phase'] ) {
				$job = self::step_rules( $job, $t0, $budget );
			}
			if ( '' !== $job['error'] ) {
				$job['phase'] = 'done';
			}
			if ( 'done' !== $job['phase'] && $s['pause'] > 0 ) {
				usleep( $s['pause'] * 1000 );
			}
		}
		return $job;
	}

	/** Phase 1: one batch of our products. */
	private static function step_linked( array $job ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- batched meta scan.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_bkf_source_id' AND pm.post_id > %d
				   AND p.post_type = 'product' AND p.post_status <> 'trash'
				 ORDER BY pm.post_id ASC LIMIT %d",
				(int) $job['cursor'],
				self::BATCH
			)
		);
		if ( ! $rows ) {
			$job['phase'] = 'rules';
			return $job;
		}
		$map = array();
		foreach ( $rows as $row ) {
			$map[ (string) $row->meta_value ] = (int) $row->post_id;
		}
		$res = BKF_Client::query(
			array(
				'q'    => BKF_Client::book_query( array( 'ids' => array_keys( $map ) ) ),
				'fl'   => BKF_Client::BOOK_FIELDS,
				'rows' => min( BKF_Client::MAX_ROWS, count( $map ) * 2 ),
			)
		);
		if ( is_wp_error( $res ) ) {
			$job['error'] = $res->get_error_message();
			BKF_Log::add( 'error', 0, '', '', array(), $job['error'] );
			return $job;
		}
		$records = BKF_Record::from_docs( $res['docs'] );
		if ( ! $records && count( $map ) >= 3 ) {
			$job['error'] = __( 'Η υπηρεσία δεν επέστρεψε κανέναν από τους τίτλους του καταστήματος. Ο συγχρονισμός σταμάτησε χωρίς να αλλάξει κάτι· έλεγξε τη σύνδεση στις Ρυθμίσεις.', 'book-feed' );
			BKF_Log::add( 'error', 0, '', '', array(), $job['error'] );
			return $job;
		}
		$found = array();
		foreach ( $records as $r ) {
			if ( isset( $map[ $r['id'] ] ) ) {
				$found[ $r['id'] ] = true;
				++$job['stats']['checked'];
				try {
					if ( BKF_Importer::update( $map[ $r['id'] ], $r ) ) {
						++$job['stats']['updated'];
					}
				} catch ( \Throwable $e ) {
					++$job['stats']['errors'];
					BKF_Log::add( 'error', $map[ $r['id'] ], $r['isbn'], $r['title'], array(), $e->getMessage() );
				}
			}
		}
		foreach ( $map as $id => $pid ) {
			if ( ! isset( $found[ $id ] ) ) {
				if ( '1' !== (string) get_post_meta( $pid, '_bkf_gone', true ) ) {
					++$job['stats']['gone'];
				}
				BKF_Importer::gone( $pid );
			}
		}
		$job['cursor'] = max( array_values( $map ) );
		return $job;
	}

	/** Phase 2: one page of the current rule. */
	private static function step_rules( array $job, float $t0, int $budget ): array {
		$rules = self::due_rules( $job );
		if ( ! isset( $rules[ $job['rule'] ] ) ) {
			$job['phase'] = 'done';
			return $job;
		}
		$rule  = $rules[ $job['rule'] ];
		$since = $rule['last'] > 0 ? $rule['last'] - DAY_IN_SECONDS : 0;
		$res   = BKF_Client::query(
			array(
				'q'     => BKF_Client::book_query(
					array(
						'pub_id'    => $rule['pub_id'],
						'title'     => $rule['title'],
						'author'    => $rule['author'],
						'year_from' => $rule['year_from'],
						'year_to'   => $rule['year_to'],
						'kind'      => $rule['kind'],
						'pstat'     => $rule['pstat'],
						'since'     => $since,
					)
				),
				'fl'    => BKF_Client::BOOK_FIELDS,
				'rows'  => self::PAGE,
				'start' => (int) $job['start'],
				'sort'  => 'pub_year desc, product_id asc',
			)
		);
		if ( is_wp_error( $res ) ) {
			// One rule failing does not stop the others.
			++$job['stats']['errors'];
			/* translators: 1: rule name, 2: error */
			BKF_Log::add( 'error', 0, '', '', array(), sprintf( __( 'Κανόνας «%1$s»: %2$s', 'book-feed' ), self::rule_name( $rule ), $res->get_error_message() ) );
			return self::next_rule( $job, false );
		}
		$records = BKF_Record::from_docs( $res['docs'] );
		$cut     = false;
		foreach ( $records as $r ) {
			if ( $job['per_rule'] >= $rule['max'] ) {
				break;
			}
			if ( microtime( true ) - $t0 > $budget + 20 ) {
				// Out of time mid-page: the next step reads this page again.
				// Titles imported already are skipped by find_by_source().
				$cut = true;
				break;
			}
			if ( BKF_Importer::find_by_source( $r['id'] ) ) {
				continue; // ours already: phase 1 keeps it in sync
			}
			$out = BKF_Importer::import(
				$r,
				array(
					'status' => $rule['status'],
					'cat'    => $rule['cat'],
					'mode'   => $rule['mode'],
					'url'    => $rule['url'],
					'button' => $rule['button'],
					'rule'   => $rule['id'],
				)
			);
			if ( 'imported' === $out['result'] ) {
				++$job['stats']['imported'];
				++$job['per_rule'];
				$job['per'][ $rule['id'] ] = (int) ( $job['per'][ $rule['id'] ] ?? 0 ) + 1;
			} elseif ( 'exists' === $out['result'] ) {
				++$job['stats']['exists'];
			} elseif ( 'error' === $out['result'] ) {
				++$job['stats']['errors'];
			}
		}
		if ( $cut ) {
			return $job;
		}
		$job['start'] += self::PAGE;
		if ( $job['per_rule'] >= $rule['max'] || $job['start'] >= $res['num'] || ! $res['docs'] ) {
			return self::next_rule( $job, true );
		}
		return $job;
	}

	private static function next_rule( array $job, bool $ok ): array {
		$rules = self::due_rules( $job );
		if ( $ok && isset( $rules[ $job['rule'] ] ) ) {
			$job['rules_ok'][] = $rules[ $job['rule'] ]['id'];
		}
		++$job['rule'];
		$job['start']    = 0;
		$job['per_rule'] = 0;
		return $job;
	}

	/**
	 * The active rules this job runs, in their saved order. A job started
	 * before 1.7.0 has no list: every active rule, as then.
	 */
	private static function due_rules( array $job ): array {
		$due = isset( $job['due'] ) && is_array( $job['due'] ) ? $job['due'] : null;
		$out = array();
		foreach ( BKF_Settings::rules() as $r ) {
			if ( null === $due ? $r['on'] : in_array( $r['id'], $due, true ) ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	public static function rule_name( array $rule ): string {
		if ( '' !== $rule['name'] ) {
			return $rule['name'];
		}
		if ( '' !== $rule['pub_name'] || '' !== $rule['pub_id'] ) {
			return '' !== $rule['pub_name'] ? $rule['pub_name'] : $rule['pub_id'];
		}
		return implode( ' · ', array_filter( array( $rule['title'], $rule['author'] ), 'strlen' ) );
	}

	/** Ends a job: the rules that ran get their time, a summary is kept. */
	private static function finish( array $job ): void {
		if ( ! empty( $job['rules_ok'] ) ) {
			$rules = BKF_Settings::rules();
			foreach ( $rules as $i => $r ) {
				if ( in_array( $r['id'], $job['rules_ok'], true ) ) {
					$rules[ $i ]['last'] = (int) $job['began'];
				}
			}
			BKF_Settings::save_rules( $rules );
		}
		$last = array(
			't'     => time(),
			'began' => (int) $job['began'],
			'why'   => (string) $job['why'],
			'stats' => is_array( $job['stats'] ) ? $job['stats'] : self::empty_stats(),
			'error' => (string) $job['error'],
		);
		update_option( self::LAST, $last, false );
		delete_option( self::JOB );
		$st = $last['stats'];
		/* translators: 1: checked, 2: updated, 3: gone, 4: imported, 5: already in the store, 6: errors */
		$msg = sprintf( __( 'Έλεγχος %1$d, ενημερώσεις %2$d, εκτός καταλόγου %3$d, νέοι τίτλοι %4$d, υπάρχουν ήδη %5$d, σφάλματα %6$d.', 'book-feed' ), $st['checked'], $st['updated'], $st['gone'], $st['imported'], $st['exists'], $st['errors'] );
		BKF_Log::add( 'run', 0, '', '', array(), '' !== $last['error'] ? $msg . ' ' . $last['error'] : $msg );
		self::report_rules( $job );
	}

	/**
	 * One history line per rule that ran ("0 new" included, so a rule
	 * that never finds anything shows), and one email when any brought
	 * new titles.
	 */
	private static function report_rules( array $job ): void {
		$ran = isset( $job['rules_ok'] ) && is_array( $job['rules_ok'] ) ? $job['rules_ok'] : array();
		$per = isset( $job['per'] ) && is_array( $job['per'] ) ? $job['per'] : array();
		if ( ! $ran && ! $per ) {
			return;
		}
		$lines = array();
		$total = 0;
		foreach ( BKF_Settings::rules() as $r ) {
			if ( ! in_array( $r['id'], $ran, true ) && ! isset( $per[ $r['id'] ] ) ) {
				continue;
			}
			$n      = (int) ( $per[ $r['id'] ] ?? 0 );
			$total += $n;
			/* translators: 1: rule name, 2: new titles */
			BKF_Log::add( 'run', 0, '', '', array(), sprintf( __( 'Κανόνας «%1$s»: νέοι τίτλοι %2$d.', 'book-feed' ), self::rule_name( $r ), $n ) );
			if ( $n ) {
				$lines[] = sprintf(
					/* translators: 1: rule name, 2: new titles, 3: draft or published */
					__( '%1$s: νέοι τίτλοι %2$d (%3$s)', 'book-feed' ),
					self::rule_name( $r ),
					$n,
					'publish' === $r['status'] ? __( 'δημοσιευμένοι', 'book-feed' ) : __( 'πρόχειρα', 'book-feed' )
				);
			}
		}
		$s = BKF_Settings::get();
		if ( ! $total || ! $s['notify'] ) {
			return;
		}
		$to = '' !== $s['notify_to'] ? $s['notify_to'] : (string) get_option( 'admin_email' );
		if ( ! is_email( $to ) ) {
			return;
		}
		$site = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		/* translators: 1: site name, 2: new titles */
		$subject = sprintf( __( '[%1$s] Book Feed: νέοι τίτλοι %2$d', 'book-feed' ), $site, $total );
		$body    = implode( "\n", $lines ) . "\n\n" . __( 'Τα προϊόντα:', 'book-feed' ) . ' ' . admin_url( 'edit.php?post_type=product' ) . "\n" . __( 'Ιστορικό:', 'book-feed' ) . ' ' . admin_url( 'admin.php?page=bkf-feed&tab=log' ) . "\n";
		wp_mail( $to, $subject, $body );
	}
}

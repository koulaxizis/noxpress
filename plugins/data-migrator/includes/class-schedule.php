<?php
/**
 * NM_Schedule — scheduled backups (1.1.0).
 *
 * Schedules live in the nm_schedules option (autoload off), at most
 * three: what (full or database only), how often (day, week, month),
 * when (site time zone) and how many to keep.
 *
 * How a scheduled backup runs, without a browser:
 *  - One event (nm_sched_tick) waits for the next due schedule. Action
 *    Scheduler (group data-migrator) when WooCommerce is there, else
 *    WP-Cron (Bible §12).
 *  - The tick starts the backup job (NM_Backup, the same job as a manual
 *    backup) and runs its steps one after the other: each step is a short
 *    background request (a non-blocking loopback to admin-ajax with a
 *    random token), and an event a minute later is the safety net when
 *    the host blocks loopbacks.
 *
 * Production safety (the site must never go down for a backup):
 *  - One job at a time: a manual backup, an analysis or a restore makes
 *    the schedule wait (30 minutes, up to 12 times), never run in parallel.
 *  - Background steps are capped at 10 seconds, so a PHP worker is never
 *    held for long.
 *  - Before the archive is written the job checks the free disk space
 *    (files + database + a reserve) and skips when it does not fit. While
 *    it writes, it stops and deletes its parts when the free space falls
 *    under the reserve (NM_Backup::RESERVE).
 *  - A folder that the HTTP self-test finds downloadable pauses every
 *    schedule (a forgotten full backup in a public folder is a leak).
 *  - A job that stops making progress (eight attempts in a row without
 *    progress, or 12 hours) is cancelled and reported.
 *  - Retention deletes only scheduled backups, never manual ones, and
 *    only after a successful run: a failure never removes the last good
 *    backup.
 */

defined( 'ABSPATH' ) || exit;

final class NM_Schedule {

	const OPTION = 'nm_schedules';
	const MAX    = 3;
	const TICK   = 'nm_sched_tick';
	const STEP   = 'nm_sched_step';
	const GROUP  = 'data-migrator';

	/** Seconds per background step. */
	const STEP_CAP = 10.0;

	/** Wait while another job runs: 30 minutes, at most 12 times. */
	const RETRY_WAIT = 1800;
	const RETRY_MAX  = 12;

	/** Attempts without progress before a job is cancelled. */
	const STALL_MAX = 8;

	const FREQS = array( 'daily', 'weekly', 'monthly' );

	public static function init(): void {
		add_action( self::TICK, array( __CLASS__, 'tick' ) );
		add_action( self::STEP, array( __CLASS__, 'step' ) );
		add_action( 'wp_ajax_nopriv_nm_sched_kick', array( __CLASS__, 'ajax_kick' ) );
		add_action( 'wp_ajax_nm_sched_kick', array( __CLASS__, 'ajax_kick' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure' ) );
	}

	/* ---------------------------------------------------------------
	 * Data
	 * ------------------------------------------------------------- */

	public static function defaults(): array {
		return array(
			'id'     => '',
			'on'     => true,
			'scope'  => 'db',
			'freq'   => 'daily',
			'time'   => '03:00',
			'dow'    => 1,
			'dom'    => 1,
			'keep'   => 7,
			'next'   => 0,
			'tries'  => 0,
			'last'   => array(),
		);
	}

	/** Validate one schedule. */
	public static function clean( array $s ): array {
		$d   = self::defaults();
		$out = array();

		$out['id']    = isset( $s['id'] ) && is_string( $s['id'] ) && preg_match( '/^[a-f0-9]{8}$/', $s['id'] ) ? $s['id'] : NM_Util::hex( 4 );
		$out['on']    = ! empty( $s['on'] );
		$out['scope'] = in_array( $s['scope'] ?? '', array( 'full', 'db' ), true ) ? $s['scope'] : $d['scope'];
		$out['freq']  = in_array( $s['freq'] ?? '', self::FREQS, true ) ? $s['freq'] : $d['freq'];
		$t            = isset( $s['time'] ) && is_string( $s['time'] ) && preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $s['time'] ) ? $s['time'] : $d['time'];
		$out['time']  = $t;
		$out['dow']   = max( 1, min( 7, (int) ( $s['dow'] ?? $d['dow'] ) ) );
		$out['dom']   = max( 1, min( 28, (int) ( $s['dom'] ?? $d['dom'] ) ) );
		$out['keep']  = max( 1, min( 60, (int) ( $s['keep'] ?? $d['keep'] ) ) );
		$out['next']  = max( 0, (int) ( $s['next'] ?? 0 ) );
		$out['tries'] = max( 0, min( 100, (int) ( $s['tries'] ?? 0 ) ) );
		$last         = isset( $s['last'] ) && is_array( $s['last'] ) ? $s['last'] : array();
		$out['last']  = array(
			'time'   => max( 0, (int) ( $last['time'] ?? 0 ) ),
			'status' => in_array( $last['status'] ?? '', array( 'done', 'failed', 'skipped' ), true ) ? $last['status'] : '',
			'msg'    => substr( (string) ( $last['msg'] ?? '' ), 0, 500 ),
			'backup' => preg_match( NM_Storage::ID_RE, (string) ( $last['backup'] ?? '' ) ) ? (string) $last['backup'] : '',
		);
		return $out;
	}

	/** Every schedule, keyed by id. */
	public static function all(): array {
		$v   = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $s ) {
			if ( is_array( $s ) ) {
				$s               = self::clean( $s );
				$out[ $s['id'] ] = $s;
			}
		}
		return array_slice( $out, 0, self::MAX, true );
	}

	public static function save_all( array $all ): void {
		$clean = array();
		foreach ( $all as $s ) {
			$s                 = self::clean( (array) $s );
			$clean[ $s['id'] ] = $s;
		}
		update_option( self::OPTION, array_slice( $clean, 0, self::MAX, true ), false );
		self::arm();
	}

	/** Add or replace a schedule from the form. Returns the saved one. */
	public static function put( array $in ): array {
		$all = self::all();
		$id  = (string) ( $in['id'] ?? '' );
		$old = $all[ $id ] ?? null;
		$s   = self::clean( array_merge( $old ? $old : array(), $in ) );
		// A changed time or frequency counts from now.
		$s['next']       = $s['on'] ? self::next_run( $s, time() ) : 0;
		$s['tries']      = 0;
		$all[ $s['id'] ] = $s;
		self::save_all( $all );
		return $s;
	}

	public static function remove( string $id ): bool {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		unset( $all[ $id ] );
		self::save_all( $all );
		return true;
	}

	/**
	 * The next run after $from (a timestamp), in the site's time zone.
	 * Monthly runs use days 1–28, so every month has the day.
	 */
	public static function next_run( array $s, int $from ): int {
		$tz = wp_timezone();
		list( $h, $m ) = array_map( 'intval', explode( ':', (string) $s['time'] ) );
		$now  = ( new DateTimeImmutable( '@' . $from ) )->setTimezone( $tz );
		$cand = $now->setTime( $h, $m, 0 );
		if ( 'weekly' === $s['freq'] ) {
			$diff = ( (int) $s['dow'] - (int) $now->format( 'N' ) + 7 ) % 7;
			$cand = $cand->modify( '+' . $diff . ' days' );
			if ( $cand->getTimestamp() <= $from ) {
				$cand = $cand->modify( '+7 days' );
			}
		} elseif ( 'monthly' === $s['freq'] ) {
			$cand = $cand->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'n' ), (int) $s['dom'] );
			if ( $cand->getTimestamp() <= $from ) {
				$cand = $cand->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'n' ) + 1, (int) $s['dom'] );
			}
		} elseif ( $cand->getTimestamp() <= $from ) {
			$cand = $cand->modify( '+1 day' );
		}
		return $cand->getTimestamp();
	}

	/* ---------------------------------------------------------------
	 * Events (Action Scheduler with WooCommerce, else WP-Cron)
	 * ------------------------------------------------------------- */

	private static function use_as(): bool {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_unschedule_all_actions' ) && function_exists( 'as_next_scheduled_action' );
	}

	/** Replace the pending event of $hook with one at $ts. */
	private static function at( string $hook, int $ts ): void {
		self::clear( $hook );
		if ( self::use_as() ) {
			as_schedule_single_action( $ts, $hook, array(), self::GROUP );
		} else {
			wp_schedule_single_event( $ts, $hook );
		}
	}

	private static function clear( string $hook ): void {
		if ( self::use_as() ) {
			as_unschedule_all_actions( $hook, array(), self::GROUP );
		}
		wp_clear_scheduled_hook( $hook );
	}

	/** Timestamp of the pending event of $hook, 0 when none. */
	private static function pending( string $hook ): int {
		if ( self::use_as() ) {
			$t = as_next_scheduled_action( $hook, array(), self::GROUP );
			if ( is_int( $t ) ) {
				return $t;
			}
			if ( true === $t ) {
				return time(); // Running now.
			}
		}
		$t = wp_next_scheduled( $hook );
		return $t ? (int) $t : 0;
	}

	/** Every event of ours, both systems (deactivation). */
	public static function clear_all(): void {
		self::clear( self::TICK );
		self::clear( self::STEP );
	}

	/** Point the tick at the earliest enabled schedule. */
	public static function arm(): void {
		$next = 0;
		foreach ( self::all() as $s ) {
			if ( $s['on'] && $s['next'] > 0 && ( 0 === $next || $s['next'] < $next ) ) {
				$next = $s['next'];
			}
		}
		if ( 0 === $next ) {
			self::clear( self::TICK );
			return;
		}
		if ( self::pending( self::TICK ) !== $next ) {
			self::at( self::TICK, $next );
		}
	}

	/**
	 * Admin requests: keep the event in place. It can be lost when the
	 * database is restored from another site or another plugin clears the
	 * cron array. Cheap: one option read and one event lookup.
	 */
	public static function ensure(): void {
		if ( wp_doing_ajax() ) {
			return; // Page loads are enough; not on every heartbeat.
		}
		$all = self::all();
		if ( ! $all ) {
			return;
		}
		$dirty = false;
		foreach ( $all as $id => $s ) {
			if ( $s['on'] && ! $s['next'] ) {
				$all[ $id ]['next'] = self::next_run( $s, time() );
				$dirty              = true;
			}
		}
		if ( $dirty ) {
			self::save_all( $all );
			return;
		}
		self::arm();
		// A job left without its step event (the event was lost).
		$job = self::job();
		if ( $job && 'running' === $job['status'] && ! self::pending( self::STEP ) ) {
			self::at( self::STEP, time() + 60 );
		}
	}

	/* ---------------------------------------------------------------
	 * Run
	 * ------------------------------------------------------------- */

	private static function state(): array {
		$st = get_option( 'nm_state', array() );
		return is_array( $st ) ? $st : array();
	}

	private static function set_state( array $changes ): void {
		$st = array_merge( self::state(), $changes );
		update_option( 'nm_state', $st, false );
	}

	/** The scheduled job in progress, or null. */
	public static function job() {
		$id = (string) ( self::state()['sched_job'] ?? '' );
		return '' === $id ? null : NM_Storage::job( $id );
	}

	/** Due schedules start here. */
	public static function tick(): void {
		if ( is_multisite() ) {
			return;
		}
		if ( '' === NM_Storage::dir() ) {
			// Record it (and move on to the next run) instead of firing again and again.
			foreach ( self::all() as $s ) {
				if ( $s['on'] && $s['next'] > 0 && $s['next'] <= time() ) {
					self::finish_run( $s, 'failed', NM_Util::t( 'Δεν βρέθηκε φάκελος με δικαίωμα εγγραφής για τα αντίγραφα. Όρισε έναν στις Ρυθμίσεις.' ), '' );
				}
			}
			self::arm();
			return;
		}
		$lock = NM_Util::lock( NM_Storage::sub( 'jobs' ) . '/sched.lock' );
		if ( ! $lock ) {
			return;
		}
		try {
			$job = self::job();
			if ( $job && 'running' !== $job['status'] ) {
				self::finish_job( $job ); // The chain stopped before the end.
				$job = null;
			} elseif ( $job && ! self::pending( self::STEP ) ) {
				self::at( self::STEP, time() + 60 );
			}
			if ( ! $job ) {
				$now = time();
				$due = array_filter(
					self::all(),
					static function ( $s ) use ( $now ) {
						return $s['on'] && $s['next'] > 0 && $s['next'] <= $now;
					}
				);
				uasort(
					$due,
					static function ( $a, $b ) {
						return $a['next'] - $b['next'];
					}
				);
				foreach ( $due as $s ) {
					if ( true === self::start( $s ) ) {
						break; // One job at a time; the others wait for the next tick.
					}
				}
			}
		} finally {
			NM_Util::unlock( $lock );
		}
		self::arm();
	}

	/**
	 * Start the backup of a schedule now (tick, "Run now", CLI).
	 *
	 * @param bool $manual "Run now": a busy site is an error for the
	 *                     user, not a reason to retry later.
	 * @return true|WP_Error
	 */
	public static function start( array $s, bool $manual = false ) {
		$all = self::all();
		if ( ! isset( $all[ $s['id'] ] ) ) {
			return new WP_Error( 'gone', NM_Util::t( 'Το πρόγραμμα δεν βρέθηκε.' ) );
		}
		$s   = $all[ $s['id'] ];
		$now = time();

		// A downloadable folder pauses the schedules.
		$web = NM_Storage::web_test();
		if ( 'exposed' === $web ) {
			$msg = NM_Util::t( 'Ο φάκελος των αντιγράφων κατεβαίνει από τον browser, γι\' αυτό τα προγραμματισμένα αντίγραφα σταμάτησαν. Όρισε φάκελο έξω από το web root στις Ρυθμίσεις.' );
			self::finish_run( $s, 'skipped', $msg, '' );
			return new WP_Error( 'exposed', $msg );
		}

		// Never next to another job: wait for it.
		if ( NM_Backup::running() ) {
			$msg = NM_Util::t( 'Τρέχει ήδη μια εργασία του Data Migrator. Περίμενε να τελειώσει ή ακύρωσέ την.' );
			if ( $manual ) {
				return new WP_Error( 'busy', $msg );
			}
			if ( $s['tries'] < self::RETRY_MAX ) {
				$s['tries']++;
				$s['next']       = $now + self::RETRY_WAIT;
				$all[ $s['id'] ] = $s;
				self::save_all( $all );
				NM_Util::log( 'schedule ' . $s['id'] . ': another job is running, retry ' . $s['tries'] );
				return new WP_Error( 'busy', $msg );
			}
			self::finish_run( $s, 'skipped', $msg, '' );
			return new WP_Error( 'busy', $msg );
		}

		$job = NM_Backup::start(
			array(
				'scope'   => $s['scope'],
				'part_mb' => (int) NM_Settings::get()['part_mb'],
				'sched'   => $s['id'],
			)
		);
		if ( is_wp_error( $job ) ) {
			self::finish_run( $s, 'failed', $job->get_error_message(), '' );
			return $job;
		}
		$s['tries']      = 0;
		$s['next']       = $s['on'] ? self::next_run( $s, $now ) : 0;
		$all[ $s['id'] ] = $s;
		self::save_all( $all );
		self::set_state(
			array(
				'sched_job'   => (string) $job['id'],
				'sched_token' => NM_Util::hex( 16 ),
				'sched_stall' => 0,
			)
		);
		NM_Util::log( 'schedule ' . $s['id'] . ': backup ' . $job['backup'] . ' started' );
		self::at( self::STEP, time() + 60 );
		self::kick();
		return true;
	}

	/**
	 * One background step of the scheduled job (loopback or event).
	 * The job's own lock keeps two requests from working at once.
	 */
	public static function step(): void {
		$job = self::job();
		if ( ! $job ) {
			self::clear( self::STEP );
			return;
		}
		if ( 'running' !== $job['status'] ) {
			self::finish_job( $job );
			return;
		}
		// Re-arm the safety net first: a fatal error inside the step must
		// not end the chain.
		self::at( self::STEP, time() + 60 );

		$st    = self::state();
		$stall = (int) ( $st['sched_stall'] ?? 0 );
		if ( $stall >= self::STALL_MAX || time() - (int) $job['created'] > 12 * HOUR_IN_SECONDS ) {
			NM_Backup::cancel( (string) $job['id'], NM_Util::t( 'Η εργασία σταμάτησε να προχωρά και ακυρώθηκε.' ) );
			self::finish_job( (array) NM_Storage::job( (string) $job['id'] ) );
			return;
		}
		$before = self::sig( $job );
		self::set_state( array( 'sched_stall' => $stall + 1 ) );

		NM_Util::$cap = self::STEP_CAP;
		$p            = NM_Backup::step( (string) $job['id'] );
		NM_Util::$cap = 0.0;

		if ( 'busy' === $p['status'] ) {
			self::set_state( array( 'sched_stall' => $stall ) ); // Someone else is working on it.
			return;
		}
		$job = NM_Storage::job( (string) $job['id'] );
		if ( ! $job ) {
			self::set_state( array( 'sched_job' => '' ) );
			return;
		}
		if ( self::sig( $job ) !== $before ) {
			self::set_state( array( 'sched_stall' => 0 ) );
		}
		if ( 'running' === $job['status'] ) {
			self::kick();
			return;
		}
		self::finish_job( $job );
	}

	/** Progress signature: changes whenever the job moves. */
	private static function sig( array $job ): string {
		$ss = (array) ( $job['ss'] ?? array() );
		unset( $ss['w'] );
		return md5( (string) wp_json_encode( array( $job['phase'] ?? '', $ss, $job['parts'] ?? array(), $job['stats'] ?? array() ) ) );
	}

	/** Fire the next step in its own request, without waiting for it. */
	public static function kick(): void {
		$token = (string) ( self::state()['sched_token'] ?? '' );
		if ( '' === $token || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action' => 'nm_sched_kick',
					'token'  => $token,
				),
			)
		);
	}

	/**
	 * Loopback endpoint. No nonce or capability: it runs as nobody, from
	 * the site itself. The 128-bit token changes with every run, and the
	 * only thing it can do is advance the scheduled job already running.
	 */
	public static function ajax_kick(): void {
		$token = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- token checked below (see docblock).
		$want  = (string) ( self::state()['sched_token'] ?? '' );
		if ( '' === $want || ! hash_equals( $want, $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		NM_Util::prepare_request();
		self::step();
		wp_die( '', '', array( 'response' => 200 ) );
	}

	/* ---------------------------------------------------------------
	 * After a run
	 * ------------------------------------------------------------- */

	/** A scheduled job ended (done, failed, cancelled). */
	private static function finish_job( array $job ): void {
		// Once only: the loopback and the safety-net event may both get here.
		$lock = NM_Util::lock( NM_Storage::sub( 'jobs' ) . '/sched-end.lock' );
		if ( ! $lock ) {
			return;
		}
		$cur = (string) ( self::state()['sched_job'] ?? '' );
		if ( '' === $cur || (string) ( $job['id'] ?? '' ) !== $cur ) {
			NM_Util::unlock( $lock );
			return;
		}
		self::clear( self::STEP );
		self::set_state(
			array(
				'sched_job'   => '',
				'sched_token' => '',
				'sched_stall' => 0,
			)
		);
		NM_Util::unlock( $lock );
		$all = self::all();
		$sid = (string) ( $job['sched'] ?? '' );
		if ( ! isset( $all[ $sid ] ) ) {
			return; // The schedule was deleted meanwhile; the backup stays.
		}
		if ( 'done' === $job['status'] ) {
			self::retention( $all[ $sid ] );
			$m = NM_Storage::meta( (string) $job['backup'] );
			self::finish_run( $all[ $sid ], 'done', NM_Util::tf( 'Αντίγραφο %1$s, %2$s.', self::when( (int) $job['created'] ), NM_Util::size( (float) ( $m['size'] ?? 0 ) ) ), (string) $job['backup'] );
		} else {
			self::finish_run( $all[ $sid ], 'failed', (string) ( $job['error'] ?? NM_Util::t( 'Ακυρώθηκε.' ) ), '' );
		}
	}

	/** Record the outcome on the schedule and send the email. */
	private static function finish_run( array $s, string $status, string $msg, string $backup ): void {
		$all = self::all();
		if ( isset( $all[ $s['id'] ] ) ) {
			$s = $all[ $s['id'] ];
		}
		$s['last'] = array(
			'time'   => time(),
			'status' => $status,
			'msg'    => $msg,
			'backup' => $backup,
		);
		if ( 'done' !== $status ) {
			$s['tries'] = 0;
			if ( $s['on'] && ( ! $s['next'] || $s['next'] <= time() ) ) {
				$s['next'] = self::next_run( $s, time() );
			}
		}
		$all[ $s['id'] ] = $s;
		self::save_all( $all );
		NM_Util::log( 'schedule ' . $s['id'] . ': ' . $status . ( '' === $msg ? '' : ' (' . $msg . ')' ), 'failed' === $status );
		self::mail( $s, $status, $msg );
	}

	/**
	 * Keep the newest N backups of the schedule, then the size limit of
	 * all scheduled backups. Manual backups are never touched, and the
	 * newest backup of each schedule always stays.
	 */
	public static function retention( array $s ): void {
		$mine   = array();
		$sched  = array();
		$failed = array();
		foreach ( NM_Storage::all() as $m ) { // Newest first.
			if ( empty( $m['sched'] ) ) {
				continue;
			}
			if ( 'failed' === ( $m['status'] ?? '' ) && $m['sched'] === $s['id'] ) {
				$failed[] = $m;
				continue;
			}
			if ( 'done' !== ( $m['status'] ?? '' ) ) {
				continue;
			}
			$sched[] = $m;
			if ( $m['sched'] === $s['id'] ) {
				$mine[] = $m;
			}
		}
		$gone = array();
		foreach ( array_slice( $mine, (int) $s['keep'] ) as $m ) {
			NM_Storage::delete( (string) $m['id'] );
			$gone[ $m['id'] ] = true;
		}
		// Failed runs leave only a row in the list: keep the latest one.
		foreach ( array_slice( $failed, 1 ) as $m ) {
			NM_Storage::delete( (string) $m['id'] );
		}

		$quota = (float) NM_Settings::get()['sched_quota'] * 1073741824;
		if ( $quota <= 0 ) {
			return;
		}
		$newest = array();
		$total  = 0.0;
		foreach ( $sched as $m ) {
			if ( isset( $gone[ $m['id'] ] ) ) {
				continue;
			}
			if ( ! isset( $newest[ $m['sched'] ] ) ) {
				$newest[ $m['sched'] ] = $m['id'];
			}
			$total += (float) ( $m['size'] ?? 0 );
		}
		foreach ( array_reverse( $sched ) as $m ) { // Oldest first.
			if ( $total <= $quota ) {
				break;
			}
			if ( isset( $gone[ $m['id'] ] ) || in_array( $m['id'], $newest, true ) ) {
				continue;
			}
			NM_Storage::delete( (string) $m['id'] );
			$total -= (float) ( $m['size'] ?? 0 );
		}
	}

	private static function when( int $ts ): string {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	/** Text-only email with a link to the admin page; never an attachment. */
	private static function mail( array $s, string $status, string $msg ): void {
		$mode = (string) NM_Settings::get()['sched_mail'];
		if ( 'none' === $mode || ( 'fail' === $mode && 'done' === $status ) ) {
			return;
		}
		$to = (string) get_option( 'admin_email' );
		if ( ! is_email( $to ) ) {
			return;
		}
		$site    = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$subject = 'done' === $status
			? NM_Util::tf( '[%s] Data Migrator: το προγραμματισμένο αντίγραφο έγινε', $site )
			: ( 'skipped' === $status ? NM_Util::tf( '[%s] Data Migrator: το προγραμματισμένο αντίγραφο παραλείφθηκε', $site ) : NM_Util::tf( '[%s] Data Migrator: το προγραμματισμένο αντίγραφο απέτυχε', $site ) );
		$body    = $msg . "\n\n" . NM_Util::tf( 'Τα αντίγραφα και το log: %s', admin_url( 'admin.php?page=nm-backups' ) ) . "\n";
		wp_mail( $to, $subject, $body );
	}

	/** Day names in the plugin's language, 1 = Monday … 7 = Sunday. */
	public static function days(): array {
		return array(
			1 => NM_Util::t( 'Δευτέρα' ),
			2 => NM_Util::t( 'Τρίτη' ),
			3 => NM_Util::t( 'Τετάρτη' ),
			4 => NM_Util::t( 'Πέμπτη' ),
			5 => NM_Util::t( 'Παρασκευή' ),
			6 => NM_Util::t( 'Σάββατο' ),
			7 => NM_Util::t( 'Κυριακή' ),
		);
	}

	/** Human label of a schedule's frequency and time. */
	public static function label( array $s ): string {
		if ( 'weekly' === $s['freq'] ) {
			return NM_Util::tf( 'Κάθε %1$s, %2$s', self::days()[ (int) $s['dow'] ], $s['time'] );
		}
		if ( 'monthly' === $s['freq'] ) {
			return NM_Util::tf( 'Κάθε μήνα, στις %1$d, %2$s', (int) $s['dom'], $s['time'] );
		}
		return NM_Util::tf( 'Κάθε μέρα, %s', $s['time'] );
	}
}

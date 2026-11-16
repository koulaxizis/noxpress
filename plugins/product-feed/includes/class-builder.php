<?php
/**
 * PFD_Builder — builds the feed files in the background.
 *
 *  - pfd_tick (Action Scheduler, hourly, group product-feed): starts every
 *    active feed whose last build is older than its «every» hours, and
 *    restarts a build that stopped (no batch for 30 minutes).
 *  - pfd_batch( id ): 100 products per batch, appended to a temporary
 *    file next to the feed (.<file>.tmp-<random>); the last batch writes
 *    the end of the file, renames it over the feed in one step and copies
 *    it (the same way) to the legacy path when there is one.
 *  - pfd_changed: 10 minutes after a change of price, stock or a product
 *    (debounced: one pending action at most), rebuilds every feed with
 *    «on_change».
 *
 * A failed build leaves the previous good file in place and records the
 * error in the status. PFD_DISABLE stops every build.
 *
 * The build report keeps counts per reason (left out) and per warning,
 * and the first 300 issues (product id, title, codes) for the «Check» tab.
 */

defined( 'ABSPATH' ) || exit;

final class PFD_Builder {

	const BATCH      = 100;
	const MAX_ISSUES = 300;
	const DEBOUNCE   = 600;
	const STALE      = 1800;

	public static function init(): void {
		add_action( 'pfd_tick', array( __CLASS__, 'tick' ) );
		add_action( 'pfd_batch', array( __CLASS__, 'batch' ), 10, 1 );
		add_action( 'pfd_changed', array( __CLASS__, 'changed' ) );

		foreach ( array( 'woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'noxpress_products_changed' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'mark_changed' ), 99, 0 );
		}
		add_action( 'trashed_post', array( __CLASS__, 'mark_changed_post' ), 99, 1 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'schedule_tick' ) );
		}
	}

	/** The hourly tick exists (cheap: Action Scheduler caches the look-up). */
	public static function schedule_tick(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! did_action( 'action_scheduler_init' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( 'pfd_tick', array(), Product_Feed::GROUP ) ) {
			as_schedule_recurring_action( time() + 300, HOUR_IN_SECONDS, 'pfd_tick', array(), Product_Feed::GROUP );
		}
	}

	public static function mark_changed_post( $post_id ): void {
		if ( 'product' === get_post_type( (int) $post_id ) ) {
			self::mark_changed();
		}
	}

	/**
	 * Queue one rebuild in 10 minutes, at most one pending. A transient
	 * keeps it to one look-up per 10 minutes even on a busy checkout.
	 */
	public static function mark_changed(): void {
		static $done = false;
		if ( $done || Product_Feed::killed() ) {
			return;
		}
		$done = true;
		if ( get_transient( 'pfd_dirty' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$any = false;
		foreach ( PFD_Settings::feeds() as $f ) {
			if ( $f['active'] && $f['on_change'] ) {
				$any = true;
				break;
			}
		}
		if ( ! $any ) {
			return;
		}
		set_transient( 'pfd_dirty', 1, self::DEBOUNCE );
		if ( ! as_has_scheduled_action( 'pfd_changed', array(), Product_Feed::GROUP ) ) {
			as_schedule_single_action( time() + self::DEBOUNCE, 'pfd_changed', array(), Product_Feed::GROUP );
		}
	}

	public static function changed(): void {
		foreach ( PFD_Settings::feeds() as $id => $f ) {
			if ( $f['active'] && $f['on_change'] ) {
				self::start( (string) $id, 'change' );
			}
		}
	}

	public static function tick(): void {
		if ( Product_Feed::killed() ) {
			return;
		}
		$now = time();
		foreach ( PFD_Settings::feeds() as $id => $f ) {
			if ( ! $f['active'] ) {
				continue;
			}
			$st = PFD_Settings::status( (string) $id );
			if ( 'running' === $st['state'] ) {
				$last = max( (int) $st['started'], (int) ( $st['run']['beat'] ?? 0 ) );
				if ( $now - $last > self::STALE ) {
					self::start( (string) $id, 'restart', true );
				}
				continue;
			}
			if ( $now - (int) $st['finished'] >= (int) $f['every'] * HOUR_IN_SECONDS - 120 ) {
				self::start( (string) $id, 'schedule' );
			}
		}
	}

	/**
	 * Start a build. Returns false when the feed is missing, already
	 * building (unless $force) or the folder cannot be written.
	 */
	public static function start( string $id, string $why = 'manual', bool $force = false ): bool {
		$f = PFD_Settings::feed( $id );
		if ( ! $f || Product_Feed::killed() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}
		$st = PFD_Settings::status( $id );
		if ( 'running' === $st['state'] && ! $force && time() - max( (int) $st['started'], (int) ( $st['run']['beat'] ?? 0 ) ) <= self::STALE ) {
			return false;
		}
		self::drop_tmp( $st );
		$dir = PFD_Settings::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			$st['state'] = 'idle';
			$st['error'] = 'mkdir';
			PFD_Settings::set_status( $id, $st );
			return false;
		}
		self::guard_dir( $dir );
		$tmp = $dir . '/.' . $f['file'] . '.tmp-' . strtolower( wp_generate_password( 8, false, false ) );
		$w   = PFD_Channels::writer( $f['channel'] );
		if ( false === file_put_contents( $tmp, $w::head( $f ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- streamed build file in our own folder.
			$st['state'] = 'idle';
			$st['error'] = 'write';
			PFD_Settings::set_status( $id, $st );
			return false;
		}
		$st['state']   = 'running';
		$st['started'] = time();
		$st['why']     = $why;
		$st['cursor']  = 0;
		$st['tmp']     = $tmp;
		$st['error']   = '';
		$st['run']     = array(
			'count'    => 0,
			'products' => 0,
			'skipped'  => array(),
			'warnings' => array(),
			'issues'   => array(),
			'beat'     => time(),
		);
		PFD_Settings::set_status( $id, $st );
		as_unschedule_all_actions( 'pfd_batch', array( $id ), Product_Feed::GROUP );
		as_enqueue_async_action( 'pfd_batch', array( $id ), Product_Feed::GROUP );
		return true;
	}

	/** One batch of a running build. */
	public static function batch( $id = '' ): void {
		$id = (string) $id;
		$f  = PFD_Settings::feed( $id );
		$st = PFD_Settings::status( $id );
		if ( ! $f || 'running' !== $st['state'] || Product_Feed::killed() ) {
			return;
		}
		$tmp = (string) $st['tmp'];
		if ( ! self::own_tmp( $tmp ) || ! is_file( $tmp ) ) {
			self::fail( $id, $st, 'tmp' );
			return;
		}
		try {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- keyset paging over published product ids.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d", (int) $st['cursor'], self::BATCH ) );
			$ids = array_map( 'intval', (array) $ids );

			PFD_Item::reset();
			$w   = PFD_Channels::writer( $f['channel'] );
			$buf = '';
			$run = $st['run'];
			foreach ( $ids as $pid ) {
				$p = wc_get_product( $pid );
				if ( ! $p instanceof WC_Product ) {
					continue;
				}
				++$run['products'];
				$why = PFD_Item::filter( $p, $f );
				if ( '' === $why ) {
					$items = PFD_Item::items( $p, $f );
					$why   = $items ? '' : 'out_of_stock';
				}
				if ( '' !== $why ) {
					$run['skipped'][ $why ] = ( $run['skipped'][ $why ] ?? 0 ) + 1;
					continue;
				}
				list( $err, $warn ) = $w::check( $items[0], $f );
				foreach ( $warn as $c ) {
					$run['warnings'][ $c ] = ( $run['warnings'][ $c ] ?? 0 ) + 1;
				}
				if ( ( $err || $warn ) && count( $run['issues'] ) < self::MAX_ISSUES ) {
					$run['issues'][] = array( $pid, mb_substr( $items[0]['title'], 0, 90 ), $err, $warn );
				}
				if ( $err && $f['skip_errors'] ) {
					$run['skipped']['errors'] = ( $run['skipped']['errors'] ?? 0 ) + 1;
					continue;
				}
				$buf .= $w::rows( $items, $f );
				$run['count'] += $items[0]['vars'] && ! PFD_Channels::grouped( $f['channel'] ) ? count( $items[0]['vars'] ) : 1;
			}
			if ( '' !== $buf && false === file_put_contents( $tmp, $buf, FILE_APPEND ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- streamed build file.
				self::fail( $id, $st, 'write' );
				return;
			}
			$run['beat']  = time();
			$st['run']    = $run;
			$st['cursor'] = $ids ? max( $ids ) : (int) $st['cursor'];
			PFD_Settings::set_status( $id, $st );

			if ( count( $ids ) < self::BATCH ) {
				self::finish( $id, $f, $st );
			} else {
				as_enqueue_async_action( 'pfd_batch', array( $id ), Product_Feed::GROUP );
			}
		} catch ( \Throwable $e ) {
			self::fail( $id, $st, 'exception: ' . mb_substr( $e->getMessage(), 0, 200 ) );
		}
	}

	private static function finish( string $id, array $f, array $st ): void {
		$w   = PFD_Channels::writer( $f['channel'] );
		$tmp = (string) $st['tmp'];
		if ( false === file_put_contents( $tmp, $w::foot(), FILE_APPEND ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- streamed build file.
			self::fail( $id, $st, 'write' );
			return;
		}
		$dest = PFD_Settings::feed_path( $f );
		if ( ! @rename( $tmp, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace in our own folder.
			self::fail( $id, $st, 'rename' );
			return;
		}
		$legacy = PFD_Settings::legacy_path( $f );
		$lerr   = '';
		if ( '' !== $legacy ) {
			$lerr = self::copy_legacy( $dest, $legacy );
		}
		$run            = $st['run'];
		$st['state']    = 'idle';
		$st['finished'] = time();
		$st['count']    = (int) $run['count'];
		$st['products'] = (int) $run['products'];
		$st['skipped']  = $run['skipped'];
		$st['warnings'] = $run['warnings'];
		$st['issues']   = $run['issues'];
		$st['size']     = (int) @filesize( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- just written.
		$st['error']    = $lerr;
		$st['tmp']      = '';
		$st['run']      = array();
		PFD_Settings::set_status( $id, $st );
		do_action( 'pfd_feed_built', $id, $st );
	}

	/** Copy to the legacy path through a temporary file and one rename. */
	private static function copy_legacy( string $src, string $dest ): string {
		$dir = dirname( $dest );
		if ( ! wp_mkdir_p( $dir ) ) {
			return 'legacy';
		}
		$tmp = $dir . '/.pfd-' . strtolower( wp_generate_password( 8, false, false ) );
		if ( ! @copy( $src, $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported in the status.
			return 'legacy';
		}
		if ( ! @rename( $tmp, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace.
			wp_delete_file( $tmp );
			return 'legacy';
		}
		return '';
	}

	private static function fail( string $id, array $st, string $error ): void {
		self::drop_tmp( $st );
		$st['state'] = 'idle';
		$st['error'] = $error;
		$st['tmp']   = '';
		$st['run']   = array();
		PFD_Settings::set_status( $id, $st );
	}

	/** Stop a running build (the old file stays). */
	public static function stop( string $id ): void {
		$st = PFD_Settings::status( $id );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'pfd_batch', array( $id ), Product_Feed::GROUP );
		}
		if ( 'running' === $st['state'] ) {
			self::fail( $id, $st, 'stopped' );
		}
	}

	private static function drop_tmp( array $st ): void {
		$tmp = (string) ( $st['tmp'] ?? '' );
		if ( '' !== $tmp && self::own_tmp( $tmp ) && is_file( $tmp ) ) {
			wp_delete_file( $tmp );
		}
	}

	/** A temporary file of ours: inside our folder, our naming. */
	private static function own_tmp( string $tmp ): bool {
		return 0 === strpos( $tmp, PFD_Settings::dir() . '/.' ) && (bool) preg_match( '/\.tmp-[a-z0-9]{8}$/', $tmp ) && false === strpos( $tmp, '..' );
	}

	/** index.php in our folders: no directory listing. */
	private static function guard_dir( string $dir ): void {
		foreach ( array( dirname( $dir ), $dir ) as $d ) {
			if ( ! is_file( $d . '/index.php' ) ) {
				file_put_contents( $d . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- an empty guard file.
			}
		}
	}

	/**
	 * Preview: the first $max items of a feed (or one product), built in
	 * this request without writing anything.
	 *
	 * @return array{ text: string, lines: array }
	 */
	public static function preview( array $f, int $max = 20, int $only = 0 ): array {
		global $wpdb;
		PFD_Item::reset();
		$w     = PFD_Channels::writer( $f['channel'] );
		$body  = '';
		$lines = array();
		$n     = 0;
		$last  = 0;
		$seen  = 0;
		while ( $n < $max && $seen < 2000 ) {
			if ( $only ) {
				$ids = array( $only );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- keyset paging, admin preview.
				$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT 50", $last ) ) );
			}
			if ( ! $ids ) {
				break;
			}
			foreach ( $ids as $pid ) {
				$last = $pid;
				++$seen;
				$p = wc_get_product( $pid );
				if ( ! $p instanceof WC_Product ) {
					continue;
				}
				$why   = PFD_Item::filter( $p, $f );
				$items = '' === $why ? PFD_Item::items( $p, $f ) : array();
				if ( '' === $why && ! $items ) {
					$why = 'out_of_stock';
				}
				if ( '' !== $why ) {
					if ( $only ) {
						$lines[] = array( $pid, $p->get_name(), array( 'skip_' . $why ), array() );
					}
					continue;
				}
				list( $err, $warn ) = $w::check( $items[0], $f );
				$lines[] = array( $pid, $items[0]['title'], $err, $warn );
				$body   .= $w::rows( $items, $f );
				++$n;
				if ( $n >= $max ) {
					break;
				}
			}
			if ( $only ) {
				break;
			}
		}
		return array(
			'text'  => $w::head( $f ) . $body . $w::foot(),
			'lines' => $lines,
		);
	}
}

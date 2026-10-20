<?php
/**
 * BKF_Bulk — "import all results" of a search, in the background (1.7.0).
 *
 * The browser sends only the search's filters; the feed is asked again
 * here, page by page (50, a fixed order), and each title is imported
 * with the chosen status and category. Titles already in the store
 * (ours, or the same ISBN) are counted and skipped.
 *
 * One bulk import at a time, state in bkf_bulk (autoload off):
 *  { id, f (filters), status, cat, pos, limit, num, stats, began, steps,
 *    by (user id), error }. limit = the results, at most bulk_max
 *  (setting, 1000 by default): a search without filters never fills a
 *  store with the whole catalogue.
 *
 * Steps run through Action Scheduler (group book-feed, hook
 * bkf_bulk_step), at most 10 s each, under the sync's lock (BKF_Sync::
 * with_lock), so a sync step and an import never touch a product
 * together; while the lock is held the step tries again in 30 s. An
 * admin page view wakes a bulk import left without a scheduled step.
 * 12 hours or 2000 steps stop it. When it ends, the summary goes to the
 * history and to bkf_bulk_last (shown on the page).
 */

defined( 'ABSPATH' ) || exit;

final class BKF_Bulk {

	const OPTION = 'bkf_bulk';
	const LAST   = 'bkf_bulk_last';
	const HOOK   = 'bkf_bulk_step';
	const PAGE   = 50;
	const BUDGET = 10;
	const WAIT   = 30;

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'step' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure' ) );
	}

	public static function get(): ?array {
		$b = get_option( self::OPTION, null );
		return is_array( $b ) && isset( $b['id'], $b['f'] ) ? $b : null;
	}

	/** Summary of the last finished bulk import, or null. */
	public static function last(): ?array {
		$l = get_option( self::LAST, null );
		return is_array( $l ) && isset( $l['t'] ) ? $l : null;
	}

	private static function empty_stats(): array {
		return array(
			'imported' => 0,
			'exists'   => 0,
			'errors'   => 0,
		);
	}

	/**
	 * Starts a bulk import of a search's results.
	 *
	 * @param array $f   filters (as BKF_Admin_Ajax::filters()), pub_id resolved.
	 * @param int   $num the results the feed reports for them.
	 * @return bool false while another bulk import runs.
	 */
	public static function start( array $f, string $status, int $cat, int $num ): bool {
		if ( self::get() ) {
			return false;
		}
		$b = array(
			'id'     => substr( md5( uniqid( '', true ) ), 0, 12 ),
			'f'      => $f,
			'status' => in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft',
			'cat'    => $cat,
			'pos'    => 0,
			'limit'  => min( max( 0, $num ), (int) BKF_Settings::get()['bulk_max'] ),
			'num'    => max( 0, $num ),
			'stats'  => self::empty_stats(),
			'began'  => time(),
			'steps'  => 0,
			'by'     => get_current_user_id(),
			'error'  => '',
		);
		update_option( self::OPTION, $b, false );
		BKF_Sync::schedule( self::HOOK, time() );
		return true;
	}

	/** Stops the running bulk import (what is imported stays). */
	public static function stop(): void {
		$b = self::get();
		BKF_Sync::unschedule( self::HOOK );
		if ( $b ) {
			$b['error'] = __( 'Η μαζική εισαγωγή σταμάτησε με το χέρι.', 'book-feed' );
			self::finish( $b );
		}
	}

	/** Wakes a bulk import left without a scheduled step (admin only). */
	public static function ensure(): void {
		$b = self::get();
		if ( $b && ! BKF_Sync::busy() && ! BKF_Sync::next( self::HOOK ) && time() - (int) ( $b['at'] ?? $b['began'] ) > 120 ) {
			BKF_Sync::schedule( self::HOOK, time() );
		}
	}

	/** One step (Action Scheduler / WP-Cron). */
	public static function step(): void {
		$b = self::get();
		if ( ! $b ) {
			return;
		}
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 120 );
		}
		$out = BKF_Sync::with_lock(
			static function () use ( $b ) {
				try {
					$b = self::work( $b );
				} catch ( \Throwable $e ) {
					$b['error'] = $e->getMessage();
				}
				BKF_Log::flush();
				return $b;
			}
		);
		if ( false === $out ) {
			// A sync step holds the lock: try again shortly.
			BKF_Sync::schedule( self::HOOK, time() + self::WAIT );
			return;
		}
		$ids = BKF_Importer::take_changed();
		if ( $ids ) {
			do_action( 'noxpress_products_changed', $ids );
		}
		// Stopped from the page while this step ran: keep that.
		$now = self::get();
		if ( ! $now || $now['id'] !== $out['id'] ) {
			return;
		}
		if ( '' !== $out['error'] || $out['pos'] >= $out['limit'] ) {
			self::finish( $out );
			return;
		}
		$out['at'] = time();
		update_option( self::OPTION, $out, false );
		BKF_Sync::schedule( self::HOOK, time() );
	}

	/** Works for up to BUDGET seconds; returns the new state. */
	private static function work( array $b ): array {
		$t0 = microtime( true );
		++$b['steps'];
		if ( $b['steps'] > 2000 || time() - (int) $b['began'] > 12 * HOUR_IN_SECONDS ) {
			$b['error'] = __( 'Η μαζική εισαγωγή δεν τελείωσε σε 12 ώρες και σταμάτησε.', 'book-feed' );
			return $b;
		}
		$opt = array(
			'status' => $b['status'],
			'cat'    => (int) $b['cat'],
			'mode'   => 'sell',
		);
		while ( $b['pos'] < $b['limit'] && microtime( true ) - $t0 < self::BUDGET ) {
			$res = BKF_Client::query(
				array(
					'q'     => BKF_Client::book_query( $b['f'] ),
					'fl'    => BKF_Client::BOOK_FIELDS,
					'rows'  => min( self::PAGE, $b['limit'] - $b['pos'] ),
					'start' => (int) $b['pos'],
					'sort'  => 'pub_year desc, product_id asc',
				)
			);
			if ( is_wp_error( $res ) ) {
				$b['error'] = $res->get_error_message();
				BKF_Log::add( 'error', 0, '', '', array(), $b['error'] );
				return $b;
			}
			if ( ! $res['docs'] ) {
				$b['limit'] = $b['pos']; // the feed has fewer results than it said
				return $b;
			}
			// Records come in feed order; the position moves one doc at a
			// time, so a step that runs out of time goes on from there.
			$cut = false;
			foreach ( BKF_Record::from_docs( $res['docs'] ) as $r ) {
				if ( microtime( true ) - $t0 >= self::BUDGET + 20 ) {
					$cut = true;
					break;
				}
				++$b['pos'];
				if ( BKF_Importer::find_by_source( $r['id'] ) ) {
					++$b['stats']['exists'];
					continue;
				}
				$row = BKF_Importer::import( $r, $opt );
				if ( 'imported' === $row['result'] ) {
					++$b['stats']['imported'];
				} elseif ( 'exists' === $row['result'] || 'updated' === $row['result'] ) {
					++$b['stats']['exists'];
				} else {
					++$b['stats']['errors'];
				}
			}
			if ( ! $cut ) {
				// A doc the record parser dropped still counts as passed.
				$b['pos'] = max( $b['pos'], min( $b['limit'], (int) $res['start'] + count( $res['docs'] ) ) );
			}
		}
		return $b;
	}

	private static function finish( array $b ): void {
		$last = array(
			't'      => time(),
			'began'  => (int) $b['began'],
			'pos'    => (int) $b['pos'],
			'limit'  => (int) $b['limit'],
			'num'    => (int) $b['num'],
			'status' => (string) $b['status'],
			'stats'  => is_array( $b['stats'] ) ? array_merge( self::empty_stats(), $b['stats'] ) : self::empty_stats(),
			'error'  => (string) $b['error'],
		);
		update_option( self::LAST, $last, false );
		delete_option( self::OPTION );
		BKF_Sync::unschedule( self::HOOK );
		$msg = self::text( $last );
		BKF_Log::add( 'bulk', 0, '', '', array(), '' !== $last['error'] ? $msg . ' ' . $last['error'] : $msg );
		BKF_Log::flush();
	}

	/** "142 / 380: new 120, already in the store 20, errors 2." */
	public static function text( array $b ): string {
		$st = array_merge( self::empty_stats(), is_array( $b['stats'] ?? null ) ? $b['stats'] : array() );
		/* translators: 1: titles gone through, 2: titles to go through, 3: imported, 4: already in the store, 5: errors */
		return sprintf( __( '%1$d / %2$d: νέοι τίτλοι %3$d, υπάρχουν ήδη %4$d, σφάλματα %5$d.', 'book-feed' ), (int) $b['pos'], (int) $b['limit'], $st['imported'], $st['exists'], $st['errors'] );
	}
}

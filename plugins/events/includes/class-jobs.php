<?php
/**
 * EVT_Jobs — keeps cached pages honest.
 *
 *  - On every save of an event (and on trash / delete) the page caches of
 *    the event, the archive and the pages that show it (its products and
 *    creators) are purged through noxpress_purge_page_cache (Fast Pages;
 *    any cache plugin can listen), and evt_event_changed( $id ) fires.
 *  - The first time an event is published, evt_event_published( $id )
 *    fires (Broadcast can post it).
 *  - A job 5 minutes after the end of each event purges the same pages,
 *    so a cached page does not keep showing a past event as upcoming.
 *    Action Scheduler when it exists (WooCommerce), else WP-Cron.
 */

defined( 'ABSPATH' ) || exit;

final class EVT_Jobs {

	const HOOK  = 'evt_after_end';
	const GROUP = 'events';

	public static function init(): void {
		add_action( 'save_post_' . EVT_CPT::PT, array( __CLASS__, 'saved' ), 50, 1 );
		add_action( 'transition_post_status', array( __CLASS__, 'transition' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'deleted' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'deleted' ) );
		add_action( self::HOOK, array( __CLASS__, 'after_end' ) );
	}

	/** URLs where an event shows. */
	public static function urls( int $id ): array {
		$urls = array();
		$link = get_permalink( $id );
		if ( $link ) {
			$urls[] = (string) $link;
		}
		$arch = get_post_type_archive_link( EVT_CPT::PT );
		if ( $arch ) {
			$urls[] = (string) $arch;
		}
		foreach ( array( '_evt_product', '_evt_creator' ) as $k ) {
			foreach ( EVT_CPT::ids( get_post_meta( $id, $k, false ) ) as $pid ) {
				$u = get_permalink( $pid );
				if ( $u ) {
					$urls[] = (string) $u;
				}
			}
		}
		$terms = get_the_terms( $id, EVT_CPT::TAX );
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$u = get_term_link( $t );
			if ( ! is_wp_error( $u ) ) {
				$urls[] = (string) $u;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	public static function purge( int $id ): void {
		do_action( 'noxpress_purge_page_cache', self::urls( $id ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- suite hook (Bible §6).
	}

	public static function saved( $post_id ): void {
		$post_id = (int) $post_id;
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		try {
			self::purge( $post_id );
			self::schedule( $post_id );
			/**
			 * After an event was saved (any status).
			 *
			 * @param int $post_id Event id.
			 */
			do_action( 'evt_event_changed', $post_id );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	public static function transition( $new, $old, $post ): void {
		if ( 'publish' !== $new || 'publish' === $old || ! $post instanceof WP_Post || EVT_CPT::PT !== $post->post_type ) {
			return;
		}
		if ( get_post_meta( $post->ID, '_evt_announced', true ) ) {
			return;
		}
		update_post_meta( $post->ID, '_evt_announced', time() );
		/**
		 * The first time an event is published.
		 *
		 * @param int $event_id Event id.
		 */
		do_action( 'evt_event_published', (int) $post->ID );
	}

	public static function deleted( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post || EVT_CPT::PT !== $post->post_type ) {
			return;
		}
		self::purge( (int) $post_id );
		self::unschedule( (int) $post_id );
	}

	private static function unschedule( int $id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array( $id ), self::GROUP );
		}
		wp_clear_scheduled_hook( self::HOOK, array( $id ) );
	}

	/** One purge job after the end (replaces an earlier one). */
	public static function schedule( int $id ): void {
		self::unschedule( $id );
		$end = (int) get_post_meta( $id, '_evt_end', true );
		if ( $end <= 0 || 'publish' !== get_post_status( $id ) ) {
			return;
		}
		$at = max( time() + 60, $end + 5 * MINUTE_IN_SECONDS );
		if ( $end + 30 * DAY_IN_SECONDS < time() ) {
			return; // Long past: nothing on a page shows it as upcoming.
		}
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $at, self::HOOK, array( $id ), self::GROUP );
		} else {
			wp_schedule_single_event( $at, self::HOOK, array( $id ) );
		}
	}

	public static function after_end( $id ): void {
		self::purge( (int) $id );
	}
}

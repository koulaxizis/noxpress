<?php
/**
 * LIM_Tools — the Tools tab and Imagify.
 *
 *  - imagify_serves()  Imagify is active and prints its own <picture>:
 *                      we wait (never <picture> inside <picture>).
 *  - retry_failed()    failed attachments wait again
 *  - one()             converts one attachment now
 *  - orphans()         sidecars whose source file is gone
 *  - delete_all()      every sidecar we made or adopted, in steps
 *
 * Imagify's settings are only read, never written (Bible §14.17). Its
 * .webp files next to the sources are adopted by the batches (LIM_Convert).
 */

defined( 'ABSPATH' ) || exit;

final class LIM_Tools {

	public static function imagify_active(): bool {
		return defined( 'IMAGIFY_VERSION' ) || class_exists( 'Imagify', false );
	}

	/** Imagify displays next-gen images itself (its «Display» setting). */
	public static function imagify_serves(): bool {
		if ( ! self::imagify_active() ) {
			return false;
		}
		$o = get_option( 'imagify_settings', array() );
		return is_array( $o ) && ( ! empty( $o['display_nextgen'] ) || ! empty( $o['display_webp'] ) );
	}

	/** Failed attachments wait again (tries reset). Returns how many. */
	public static function retry_failed(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'failed'", LIM_Files::STATE ) );
		foreach ( (array) $ids as $id ) {
			update_post_meta( (int) $id, LIM_Files::STATE, 'retry' );
			delete_post_meta( (int) $id, LIM_Files::TRIES );
		}
		return count( (array) $ids );
	}

	/** Converts one attachment now (no lock needed: one item). */
	public static function one( int $id ): array {
		if ( LIM_Queue::busy() ) {
			return array( 'state' => 'busy' );
		}
		$r = LIM_Convert::attachment( $id );
		if ( $r['made'] > 0 ) {
			LIM_Queue::maybe_purge( true );
		}
		return $r;
	}

	/**
	 * Attachment ids with a record, after $after, in id order.
	 */
	private static function recorded( int $after, int $max ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s) AND post_id > %d ORDER BY post_id ASC LIMIT %d", LIM_Files::META, LIM_Files::STATE, $after, $max ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Sidecars whose source is gone are deleted and leave the record.
	 *
	 * @return array( 'checked', 'deleted', 'done' => bool )
	 */
	public static function orphans( int $budget = 20 ): array {
		$start = microtime( true );
		$res   = array(
			'checked' => 0,
			'deleted' => 0,
			'done'    => false,
		);
		list( $base ) = LIM_Files::uploads();
		$after        = 0;
		while ( microtime( true ) - $start < $budget ) {
			$ids = self::recorded( $after, 200 );
			if ( ! $ids ) {
				$res['done'] = true;
				break;
			}
			foreach ( $ids as $id ) {
				$after = $id;
				$rec   = LIM_Files::record( $id );
				if ( empty( $rec['f'] ) || ! is_array( $rec['f'] ) ) {
					continue;
				}
				++$res['checked'];
				$dir     = isset( $rec['dir'] ) && is_string( $rec['dir'] ) ? trim( wp_normalize_path( $rec['dir'] ), '/' ) : '';
				$changed = false;
				foreach ( $rec['f'] as $name => $f ) {
					$src = $base . '/' . ( '' === $dir ? '' : $dir . '/' ) . wp_basename( (string) $name );
					if ( is_file( $src ) ) {
						continue;
					}
					foreach ( LIM_Files::FORMATS as $fmt ) {
						if ( is_array( $f ) && ! empty( $f[ $fmt ] ) && LIM_Files::unlink_sidecar( $src, $fmt ) ) {
							++$res['deleted'];
						}
					}
					unset( $rec['f'][ $name ] );
					$changed = true;
				}
				if ( $changed ) {
					LIM_Queue::stats_remove( LIM_Files::record( $id ) );
					LIM_Files::save_record( $id, $rec );
					LIM_Queue::stats_add( $rec );
				}
			}
		}
		return $res;
	}

	/**
	 * Deletes every sidecar we made or adopted, and the records, until
	 * the budget ends. Pauses the batches first.
	 *
	 * @return array( 'deleted', 'items', 'left' )
	 */
	public static function delete_all( int $budget = 20 ): array {
		LIM_Queue::set_running( false );
		$start = microtime( true );
		$res   = array(
			'deleted' => 0,
			'items'   => 0,
			'left'    => 0,
		);
		while ( microtime( true ) - $start < $budget ) {
			$ids = self::recorded( 0, 200 );
			if ( ! $ids ) {
				break;
			}
			foreach ( $ids as $id ) {
				$res['deleted'] += LIM_Files::forget( $id );
				++$res['items'];
				if ( microtime( true ) - $start >= $budget ) {
					break;
				}
			}
		}
		$res['left'] = count( self::recorded( 0, 1 ) );
		if ( 0 === $res['left'] ) {
			delete_option( LIM_Queue::STATS );
			delete_option( LIM_Queue::LAST );
		}
		LIM_Queue::purge();
		return $res;
	}
}

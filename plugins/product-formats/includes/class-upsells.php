<?php
/**
 * PFM_Upsells — permanent cleanup of upsells that only point to other
 * formats of the same work (admin only).
 *
 *  - plan(): every product of a work whose upsells contain members of
 *    its own work, with what goes and what stays. Upsells to other
 *    products are always kept.
 *  - Cleanup runs in batches (Bible §5) through AJAX with a progress bar.
 *    Before a product changes, its old upsell list goes into a snapshot
 *    (option pfm_upsell_snapshots, autoload = no, the last 10 runs).
 *  - restore(): puts the old upsells back, keeping any upsell added since.
 *  - Writes use WooCommerce's CRUD (set_upsell_ids + save) and fire
 *    noxpress_products_changed( $ids ) (Bible §6).
 */

defined( 'ABSPATH' ) || exit;

final class PFM_Upsells {

	const OPT_SNAPSHOTS = 'pfm_upsell_snapshots';
	const MAX_RUNS      = 10;
	const BATCH         = 20;

	/**
	 * Products whose upsells point to their own work.
	 *
	 * @return array[] Each: id, work, remove (ids), keep (ids).
	 */
	public static function plan(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin tool, one query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value, tt.term_id FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = pm.post_id
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
				 WHERE pm.meta_key = '_upsell_ids'
				 ORDER BY pm.post_id",
				PFM_Works::TAX
			),
			ARRAY_A
		);
		$siblings = array();
		$out      = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$ids = maybe_unserialize( $r['meta_value'] );
			if ( ! is_array( $ids ) || ! $ids ) {
				continue;
			}
			$ids  = array_map( 'intval', $ids );
			$work = (int) $r['term_id'];
			if ( ! isset( $siblings[ $work ] ) ) {
				$siblings[ $work ] = wp_list_pluck( PFM_Works::members( $work ), 'id' );
			}
			$pid    = (int) $r['post_id'];
			$remove = array_values( array_intersect( $ids, array_diff( $siblings[ $work ], array( $pid ) ) ) );
			if ( ! $remove ) {
				continue;
			}
			$out[ $pid ] = array(
				'id'     => $pid,
				'work'   => $work,
				'remove' => $remove,
				'keep'   => array_values( array_diff( $ids, $remove ) ),
			);
		}
		return array_values( $out );
	}

	/** Starts a run: an empty snapshot. Returns its id. */
	public static function start(): string {
		$runs = self::runs();
		$id   = gmdate( 'YmdHis' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 6 );
		array_unshift(
			$runs,
			array(
				'id'       => $id,
				'time'     => time(),
				'user'     => get_current_user_id(),
				'items'    => array(),
				'restored' => 0,
			)
		);
		self::save_runs( array_slice( $runs, 0, self::MAX_RUNS ) );
		return $id;
	}

	/**
	 * One batch of a run.
	 *
	 * @return array{done: int, left: int}|null Null when the run is unknown.
	 */
	public static function batch( string $run_id ): ?array {
		$runs = self::runs();
		$idx  = self::index_of( $runs, $run_id );
		if ( null === $idx || ! empty( $runs[ $idx ]['restored'] ) ) {
			return null;
		}
		$plan    = self::plan();
		$changed = array();
		foreach ( array_slice( $plan, 0, self::BATCH ) as $item ) {
			$p = wc_get_product( $item['id'] );
			if ( ! $p ) {
				continue;
			}
			$old = array_map( 'intval', $p->get_upsell_ids( 'edit' ) );
			if ( ! isset( $runs[ $idx ]['items'][ (string) $item['id'] ] ) ) {
				$runs[ $idx ]['items'][ (string) $item['id'] ] = $old;
			}
			// Save the snapshot BEFORE the product changes.
			self::save_runs( $runs );
			$p->set_upsell_ids( array_values( array_diff( $old, $item['remove'] ) ) );
			$p->save();
			$changed[] = $item['id'];
		}
		if ( $changed ) {
			do_action( 'noxpress_products_changed', $changed );
		}
		return array(
			'done' => count( $changed ),
			'left' => max( 0, count( $plan ) - count( $changed ) ),
		);
	}

	/**
	 * Puts back the upsells of a run (upsells added since are kept).
	 *
	 * @return int|null Products restored, null for an unknown run.
	 */
	public static function restore( string $run_id ): ?int {
		$runs = self::runs();
		$idx  = self::index_of( $runs, $run_id );
		if ( null === $idx ) {
			return null;
		}
		$changed = array();
		foreach ( $runs[ $idx ]['items'] as $pid => $old ) {
			$p = wc_get_product( (int) $pid );
			if ( ! $p || ! is_array( $old ) ) {
				continue;
			}
			$now  = array_map( 'intval', $p->get_upsell_ids( 'edit' ) );
			$next = array_values( array_unique( array_merge( array_map( 'intval', $old ), $now ) ) );
			if ( $next !== $now ) {
				$p->set_upsell_ids( $next );
				$p->save();
				$changed[] = (int) $pid;
			}
		}
		$runs[ $idx ]['restored'] = time();
		self::save_runs( $runs );
		if ( $changed ) {
			do_action( 'noxpress_products_changed', $changed );
		}
		return count( $changed );
	}

	public static function delete_run( string $run_id ): bool {
		$runs = self::runs();
		$idx  = self::index_of( $runs, $run_id );
		if ( null === $idx ) {
			return false;
		}
		array_splice( $runs, $idx, 1 );
		self::save_runs( $runs );
		return true;
	}

	/** Snapshots, newest first (sanitized). */
	public static function runs(): array {
		$raw = get_option( self::OPT_SNAPSHOTS, array() );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $r ) {
			if ( ! is_array( $r ) || ! isset( $r['id'] ) || ! preg_match( '/^\d{14}-[0-9a-f]{6}$/', (string) $r['id'] ) ) {
				continue;
			}
			$items = array();
			foreach ( is_array( $r['items'] ?? null ) ? $r['items'] : array() as $pid => $ids ) {
				if ( (int) $pid > 0 && is_array( $ids ) ) {
					$items[ (string) (int) $pid ] = array_map( 'intval', $ids );
				}
			}
			$out[] = array(
				'id'       => (string) $r['id'],
				'time'     => (int) ( $r['time'] ?? 0 ),
				'user'     => (int) ( $r['user'] ?? 0 ),
				'items'    => $items,
				'restored' => (int) ( $r['restored'] ?? 0 ),
			);
		}
		return $out;
	}

	private static function save_runs( array $runs ): void {
		update_option( self::OPT_SNAPSHOTS, array_values( $runs ), false );
	}

	private static function index_of( array $runs, string $run_id ): ?int {
		foreach ( $runs as $i => $r ) {
			if ( $r['id'] === $run_id ) {
				return (int) $i;
			}
		}
		return null;
	}
}

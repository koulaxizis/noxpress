<?php
/**
 * EBD_Limits — per-product switch and download limits.
 *
 * WooCommerce already keeps a download limit and an expiry per product and
 * copies them into each permission when an order is paid; the order screen
 * («Downloadable product permissions») edits a permission's remaining
 * downloads and expiry. This class adds only what is missing:
 *  - «Χωρίς υδατογράφημα» in the product's Downloads options (meta
 *    _ebd_off = 'yes'; a variation follows its parent);
 *  - defaults for NEW downloadable products (option ebd_limits: on, limit,
 *    expiry), filled in only when the product is created with both fields
 *    empty (unlimited / never);
 *  - a bulk apply to every downloadable product (Bible §12, «Μαζικές
 *    αλλαγές»): the previous values of each batch are written to the
 *    backup (option ebd_limits_backup) before the batch changes; a restore
 *    writes back only values that still match what the bulk apply wrote.
 *
 * Existing permissions (books already bought) never change here.
 */

defined( 'ABSPATH' ) || exit;

final class EBD_Limits {

	const OPT    = 'ebd_limits';
	const BACKUP = 'ebd_limits_backup';
	const META   = '_ebd_off';
	const BATCH  = 200;

	public static function init(): void {
		if ( is_admin() ) {
			add_action( 'woocommerce_product_options_downloads', array( __CLASS__, 'field' ) );
			add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product' ), 20 );
		}
	}

	/** True when the product (or its parent) has the watermark switched off. */
	public static function is_off( WC_Product $product ): bool {
		if ( 'yes' === $product->get_meta( self::META ) ) {
			return true;
		}
		$pid = $product->get_parent_id();
		if ( $pid ) {
			$parent = wc_get_product( $pid );
			return $parent instanceof WC_Product && 'yes' === $parent->get_meta( self::META );
		}
		return false;
	}

	/** Options: on (bool), limit (int|-1), expiry (days|-1). */
	public static function options(): array {
		$o = get_option( self::OPT, array() );
		$o = is_array( $o ) ? $o : array();
		return array(
			'on'     => ! empty( $o['on'] ),
			'limit'  => isset( $o['limit'] ) ? max( -1, min( 1000, (int) $o['limit'] ) ) : -1,
			'expiry' => isset( $o['expiry'] ) ? max( -1, min( 36500, (int) $o['expiry'] ) ) : -1,
		);
	}

	public static function save_options( bool $on, int $limit, int $expiry ): void {
		update_option(
			self::OPT,
			array(
				'on'     => $on,
				'limit'  => $limit <= 0 ? -1 : min( 1000, $limit ),
				'expiry' => $expiry <= 0 ? -1 : min( 36500, $expiry ),
			),
			false
		);
	}

	/** The checkbox in the product's Downloads options. */
	public static function field(): void {
		try {
			woocommerce_wp_checkbox(
				array(
					'id'          => self::META,
					'label'       => __( 'Χωρίς υδατογράφημα', 'ebook-delivery' ),
					'description' => __( 'Το Ebook Delivery στέλνει τα αρχεία αυτού του προϊόντος όπως είναι.', 'ebook-delivery' ),
				)
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Saves the checkbox and fills the defaults of a new product
	 * (WooCommerce verified its own nonce before this hook).
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public static function save_product( $product ): void {
		try {
			if ( ! $product instanceof WC_Product || ! current_user_can( 'edit_product', $product->get_id() ) ) {
				return;
			}
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce's product nonce.
			$product->update_meta_data( self::META, isset( $_POST[ self::META ] ) ? 'yes' : '' );
			$new = isset( $_POST['original_post_status'] ) && 'auto-draft' === sanitize_key( wp_unslash( $_POST['original_post_status'] ) );
			// phpcs:enable
			$o = self::options();
			if ( $new && $o['on'] && $product->is_downloadable() && (int) $product->get_download_limit() < 0 && (int) $product->get_download_expiry() < 0 ) {
				$product->set_download_limit( $o['limit'] );
				$product->set_download_expiry( $o['expiry'] );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Ids of every downloadable product and variation.
	 *
	 * @return int[]
	 */
	public static function downloadable_ids(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin tool, read only.
		$ids = $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_downloadable' AND m.meta_value = 'yes' WHERE p.post_type IN ( 'product', 'product_variation' ) AND p.post_status NOT IN ( 'trash', 'auto-draft' ) ORDER BY p.ID ASC" );
		return array_map( 'intval', (array) $ids );
	}

	/** Dry run: how many products would change. */
	public static function preview( int $limit, int $expiry ): array {
		$limit  = $limit <= 0 ? -1 : $limit;
		$expiry = $expiry <= 0 ? -1 : $expiry;
		$total  = 0;
		$change = 0;
		foreach ( self::downloadable_ids() as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p instanceof WC_Product ) {
				continue;
			}
			++$total;
			if ( (int) $p->get_download_limit() !== $limit || (int) $p->get_download_expiry() !== $expiry ) {
				++$change;
			}
		}
		return array(
			'total'  => $total,
			'change' => $change,
		);
	}

	/**
	 * Applies a limit and an expiry to every downloadable product, batch by
	 * batch, writing the backup of each batch first.
	 *
	 * @return array{changed:int, error:string}
	 */
	public static function apply( int $limit, int $expiry ): array {
		$limit  = $limit <= 0 ? -1 : min( 1000, $limit );
		$expiry = $expiry <= 0 ? -1 : min( 36500, $expiry );
		$backup = array(
			't'      => time(),
			'limit'  => $limit,
			'expiry' => $expiry,
			'items'  => array(),
		);
		$changed = 0;
		foreach ( array_chunk( self::downloadable_ids(), self::BATCH ) as $batch ) {
			$todo = array();
			foreach ( $batch as $id ) {
				$p = wc_get_product( $id );
				if ( $p instanceof WC_Product && ( (int) $p->get_download_limit() !== $limit || (int) $p->get_download_expiry() !== $expiry ) ) {
					$backup['items'][ $id ] = array( (int) $p->get_download_limit(), (int) $p->get_download_expiry() );
					$todo[ $id ]            = $p;
				}
			}
			if ( ! $todo ) {
				continue;
			}
			// The backup of this batch is written before the batch changes.
			update_option( self::BACKUP, $backup, false );
			$saved = get_option( self::BACKUP );
			if ( ! is_array( $saved ) || count( (array) ( $saved['items'] ?? array() ) ) !== count( $backup['items'] ) ) {
				// Bible §12: the batch is not applied, and what changed so far
				// goes back (the stored backup still covers those batches).
				if ( $changed > 0 ) {
					self::restore();
				}
				return array(
					'changed' => 0,
					'error'   => 'backup',
				);
			}
			foreach ( $todo as $p ) {
				$p->set_download_limit( $limit );
				$p->set_download_expiry( $expiry );
				$p->save();
				++$changed;
			}
		}
		return array(
			'changed' => $changed,
			'error'   => '',
		);
	}

	/** The last backup, or null. */
	public static function backup(): ?array {
		$b = get_option( self::BACKUP );
		return is_array( $b ) && isset( $b['items'] ) && is_array( $b['items'] ) ? $b : null;
	}

	/**
	 * Restores the backup: only products that still have the values the
	 * bulk apply wrote (anything changed by hand since is left alone).
	 *
	 * @return array{restored:int, skipped:int}
	 */
	public static function restore(): array {
		$b = self::backup();
		if ( ! $b ) {
			return array(
				'restored' => 0,
				'skipped'  => 0,
			);
		}
		$restored = 0;
		$skipped  = 0;
		foreach ( $b['items'] as $id => $prev ) {
			$p = wc_get_product( (int) $id );
			if ( ! $p instanceof WC_Product || ! is_array( $prev ) || 2 !== count( $prev ) ) {
				++$skipped;
				continue;
			}
			if ( (int) $p->get_download_limit() !== (int) $b['limit'] || (int) $p->get_download_expiry() !== (int) $b['expiry'] ) {
				++$skipped;
				continue;
			}
			$p->set_download_limit( (int) $prev[0] );
			$p->set_download_expiry( (int) $prev[1] );
			$p->save();
			++$restored;
		}
		delete_option( self::BACKUP );
		return array(
			'restored' => $restored,
			'skipped'  => $skipped,
		);
	}
}

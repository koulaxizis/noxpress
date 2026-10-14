<?php
/**
 * PBN_Convert — turns an existing product into a bundle in place, one
 * product at a time, with a backup and undo (approved question 15,
 * Bible §12 and §14.24).
 *
 * The product keeps its id, URL, images, reviews and SEO. Before the change
 * the backup _pbn_backup stores the old type, the meta the change touches
 * and the variations it moves to the trash (a variable product's own sizes
 * become the customer's choice on the product inside the bundle).
 *
 * Suggested contents: the products linked from the short description and
 * the description, which the user confirms row by row.
 */

defined( 'ABSPATH' ) || exit;

final class PBN_Convert {

	const BACKUP = '_pbn_backup';

	/** Meta the conversion overwrites (restored by undo()). */
	const KEPT = array(
		'_regular_price',
		'_sale_price',
		'_price',
		'_sale_price_dates_from',
		'_sale_price_dates_to',
		'_stock_status',
		'_manage_stock',
		'_stock',
		'_backorders',
		'_weight',
		'_length',
		'_width',
		'_height',
		'_virtual',
		'_downloadable',
		'_tax_status',
		'_tax_class',
		'_product_attributes',
		'_default_attributes',
	);

	/**
	 * Products that can be converted: simple or variable, not a bundle.
	 *
	 * @param mixed $p Product.
	 */
	public static function convertible( $p ): bool {
		return $p instanceof WC_Product && ! $p instanceof PBN_Product && $p->is_type( array( 'simple', 'variable' ) ) && 'trash' !== $p->get_status();
	}

	/**
	 * Products linked from the texts of a product, as suggested rows.
	 *
	 * @param WC_Product $p Product to convert.
	 * @return array[] Rows { p, q, m, v }.
	 */
	public static function suggest( WC_Product $p ): array {
		$text = (string) $p->get_short_description( 'edit' ) . ' ' . (string) $p->get_description( 'edit' );
		$ids  = array();
		if ( preg_match_all( '/href\s*=\s*(["\'])(.*?)\1/i', $text, $m ) ) {
			foreach ( $m[2] as $url ) {
				$url = html_entity_decode( (string) $url, ENT_QUOTES );
				$id  = (int) url_to_postid( $url );
				if ( ! $id && preg_match( '/[?&](?:p|product_id|add-to-cart)=(\d+)/', $url, $q ) ) {
					$id = (int) $q[1];
				}
				if ( $id && $id !== (int) $p->get_id() && 'product' === get_post_type( $id ) ) {
					$ids[ $id ] = $id;
				}
			}
		}
		$rows = array();
		foreach ( $ids as $id ) {
			$rows[] = array(
				'p' => $id,
				'q' => 1,
			);
		}
		return PBN_Bundle::validate_rows( $rows, (int) $p->get_id() )['rows'];
	}

	/**
	 * Converts a product into a bundle.
	 *
	 * @param int    $id    Product id.
	 * @param array  $rows  Rows (validated here).
	 * @param string $dtype 'percent' | 'price'.
	 * @param float  $disc  Discount value.
	 * @return true|string True or an error message.
	 */
	public static function convert( int $id, array $rows, string $dtype, float $disc ) {
		$p = wc_get_product( $id );
		if ( ! self::convertible( $p ) ) {
			return __( 'Μετατρέπονται μόνο απλά και μεταβλητά προϊόντα.', 'product-bundles' );
		}
		$rows = PBN_Bundle::validate_rows( $rows, $id )['rows'];
		if ( empty( $rows ) ) {
			return __( 'Διάλεξε τουλάχιστον ένα προϊόν για το πακέτο.', 'product-bundles' );
		}
		if ( get_post_meta( $id, self::BACKUP, true ) ) {
			return __( 'Αυτό το προϊόν έχει ήδη αντίγραφο από προηγούμενη μετατροπή. Αναίρεσέ την πρώτα.', 'product-bundles' );
		}

		// 1. Backup first: no backup, no change.
		$meta = array();
		foreach ( self::KEPT as $k ) {
			if ( metadata_exists( 'post', $id, $k ) ) {
				$meta[ $k ] = get_post_meta( $id, $k, true );
			}
		}
		$kids = $p->is_type( 'variable' ) ? array_map( 'intval', $p->get_children() ) : array();
		$backup = array(
			'type'     => $p->get_type(),
			'meta'     => $meta,
			'children' => $kids,
			'date'     => gmdate( 'c' ),
			'user'     => get_current_user_id(),
			'version'  => PBN_VERSION,
		);
		if ( ! update_post_meta( $id, self::BACKUP, wp_slash( $backup ) ) ) {
			return __( 'Το αντίγραφο δεν γράφτηκε: δεν άλλαξε τίποτα.', 'product-bundles' );
		}

		// 2. Variations of a variable product go to the trash (restored by undo).
		foreach ( $kids as $vid ) {
			wp_trash_post( $vid );
		}

		// 3. New type, contents and discount; the save derives price and stock.
		wp_set_object_terms( $id, PBN_Product::TYPE, 'product_type' );
		wc_delete_product_transients( $id );
		clean_post_cache( $id );
		WC_Cache_Helper::invalidate_cache_group( 'product_' . $id ); // The cached product type.
		$b = wc_get_product( $id );
		if ( ! $b instanceof PBN_Product ) {
			self::undo( $id );
			return __( 'Η μετατροπή δεν ολοκληρώθηκε και αναιρέθηκε.', 'product-bundles' );
		}
		$b->update_meta_data( PBN_Bundle::META_ITEMS, wp_json_encode( $rows ) );
		$b->update_meta_data( PBN_Bundle::META_DTYPE, 'price' === $dtype ? 'price' : 'percent' );
		$b->update_meta_data( PBN_Bundle::META_DISC, wc_format_decimal( 'percent' === $dtype ? min( 99.0, max( 0.0, $disc ) ) : max( 0.0, $disc ) ) );
		$b->set_attributes( array() );
		$b->set_default_attributes( array() );
		$b->set_virtual( false );
		$b->set_downloadable( false );
		$b->save();
		return true;
	}

	/**
	 * Undoes a conversion from its backup.
	 *
	 * @param int $id Product id.
	 * @return true|string True or an error message.
	 */
	public static function undo( int $id ) {
		$backup = get_post_meta( $id, self::BACKUP, true );
		if ( ! is_array( $backup ) || empty( $backup['type'] ) || ! is_string( $backup['type'] ) ) {
			return __( 'Δεν υπάρχει αντίγραφο για αυτό το προϊόν.', 'product-bundles' );
		}
		$type = sanitize_key( $backup['type'] );
		if ( ! in_array( $type, array( 'simple', 'variable' ), true ) ) {
			return __( 'Το αντίγραφο δεν είναι έγκυρο.', 'product-bundles' );
		}

		wp_set_object_terms( $id, $type, 'product_type' );
		foreach ( self::KEPT as $k ) {
			delete_post_meta( $id, $k );
		}
		if ( isset( $backup['meta'] ) && is_array( $backup['meta'] ) ) {
			foreach ( $backup['meta'] as $k => $v ) {
				if ( in_array( $k, self::KEPT, true ) ) {
					update_post_meta( $id, $k, wp_slash( $v ) );
				}
			}
		}
		foreach ( array( PBN_Bundle::META_ITEMS, PBN_Bundle::META_DTYPE, PBN_Bundle::META_DISC, PBN_Bundle::META_MAX, PBN_Bundle::META_VMAX, PBN_Bundle::META_PMAX, PBN_Bundle::META_AVAIL ) as $k ) {
			delete_post_meta( $id, $k );
		}

		// Variations back with the status they had.
		$restore = static function ( $new_status, $post_id, $previous ) {
			return $previous ? $previous : $new_status;
		};
		add_filter( 'wp_untrash_post_status', $restore, 10, 3 );
		if ( isset( $backup['children'] ) && is_array( $backup['children'] ) ) {
			foreach ( $backup['children'] as $vid ) {
				if ( 'trash' === get_post_status( (int) $vid ) ) {
					wp_untrash_post( (int) $vid );
				}
			}
		}
		remove_filter( 'wp_untrash_post_status', $restore, 10 );

		delete_post_meta( $id, self::BACKUP );
		PBN_Bundle::index_bundle( $id, array() );
		wc_delete_product_transients( $id );
		clean_post_cache( $id );
		WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );

		// Lookup table and derived data of the restored product.
		$p = wc_get_product( $id );
		if ( $p instanceof WC_Product ) {
			if ( $p->is_type( 'variable' ) && class_exists( 'WC_Product_Variable' ) ) {
				WC_Product_Variable::sync( $id );
			}
			$p->save();
		}
		return true;
	}

	/**
	 * Products with a conversion backup.
	 *
	 * @return int[]
	 */
	public static function converted(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin screen, small result.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id ASC LIMIT 500", self::BACKUP ) ) );
	}
}

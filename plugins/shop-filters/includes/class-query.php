<?php
/**
 * SHF_Query — applies the active attribute filters to the main product query.
 *
 * Runs on woocommerce_product_query, which WooCommerce fires for the main
 * query of the shop and of product taxonomy archives only, so themes that
 * draw the product list with their own HTML (but the main query) are
 * filtered too. The query vars are saved first: SHF_Index counts from them.
 *
 * The matching products come from SHF_Index (the same sets the counts use)
 * and are passed as post__in, so results and counts always agree. Price is
 * left to WooCommerce (min_price / max_price). Any error leaves the query
 * as it was (Bible §14.6).
 */

defined( 'ABSPATH' ) || exit;

final class SHF_Query {

	public static function init(): void {
		add_action( 'woocommerce_product_query', array( __CLASS__, 'apply' ), 50 );
	}

	/** @param WP_Query $q Main product query. */
	public static function apply( $q ): void {
		try {
			if ( ! $q instanceof WP_Query || ! $q->is_main_query() || ! SHF_Request::active() ) {
				return;
			}
			SHF_Index::set_snapshot( $q->query_vars );

			$sets = array();
			foreach ( SHF_Request::selected() as $attr => $tokens ) {
				$sets[] = SHF_Index::attr_ids( $attr, $tokens );
			}
			if ( ! $sets ) {
				return;
			}
			$ids = array_keys( SHF_Index::intersect( $sets ) );

			$current = (array) $q->get( 'post__in' );
			$current = array_filter( array_map( 'intval', $current ) );
			if ( $current ) {
				$ids = array_values( array_intersect( $ids, $current ) );
			}
			$q->set( 'post__in', $ids ? $ids : array( 0 ) );
		} catch ( \Throwable $e ) {
			return;
		}
	}
}

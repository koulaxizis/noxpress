<?php
/**
 * WSH_Report — what customers want: the products most often in lists
 * (signed-in customers, _wsh_count), stock, price and how many order lines
 * were bought from a list (_wsh_bought, guests included). The ♥ column in
 * Products. CSV export (Bible §5 recipe).
 */

defined( 'ABSPATH' ) || exit;

final class WSH_Report {

	const LIMIT = 100;

	public static function init(): void {
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
		add_filter( 'manage_edit-product_sortable_columns', array( __CLASS__, 'sortable' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'sort' ) );
	}

	/**
	 * @param array $cols Columns.
	 */
	public static function column( $cols ) {
		if ( ! is_array( $cols ) ) {
			return $cols;
		}
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'price' === $k ) {
				$out['wsh'] = '<span class="wsh-col-heart" title="' . esc_attr__( 'Σε λίστες επιθυμιών', 'wish-shelf' ) . '">♥</span><span class="screen-reader-text">' . esc_html__( 'Σε λίστες επιθυμιών', 'wish-shelf' ) . '</span>';
			}
		}
		if ( ! isset( $out['wsh'] ) ) {
			$out['wsh'] = '♥';
		}
		return $out;
	}

	/**
	 * @param string $col     Column.
	 * @param int    $post_id Product id.
	 */
	public static function column_value( $col, $post_id ): void {
		if ( 'wsh' === $col ) {
			$n = (int) get_post_meta( (int) $post_id, '_wsh_count', true );
			echo $n ? (int) $n : '<span aria-hidden="true">–</span>';
		}
	}

	/**
	 * @param array $cols Sortable columns.
	 */
	public static function sortable( $cols ) {
		if ( is_array( $cols ) ) {
			$cols['wsh'] = 'wsh';
		}
		return $cols;
	}

	/**
	 * Sort by _wsh_count, products without it as 0. A meta_query with
	 * EXISTS / NOT EXISTS would sort the rest by any other meta value, so the
	 * join is added to this query only.
	 *
	 * @param WP_Query $q Query.
	 */
	public static function sort( $q ): void {
		if ( ! is_admin() || ! $q instanceof WP_Query || ! $q->is_main_query() || 'wsh' !== $q->get( 'orderby' ) || 'product' !== $q->get( 'post_type' ) ) {
			return;
		}
		$q->set( 'wsh_sort', 'asc' === strtolower( (string) $q->get( 'order' ) ) ? 'ASC' : 'DESC' );
		add_filter( 'posts_clauses', array( __CLASS__, 'sort_clauses' ), 10, 2 );
	}

	/**
	 * @param array    $clauses SQL clauses.
	 * @param WP_Query $q       Query.
	 */
	public static function sort_clauses( $clauses, $q ) {
		global $wpdb;
		if ( ! is_array( $clauses ) || ! $q instanceof WP_Query || ! $q->get( 'wsh_sort' ) ) {
			return $clauses;
		}
		remove_filter( 'posts_clauses', array( __CLASS__, 'sort_clauses' ), 10 );
		$dir                = 'ASC' === $q->get( 'wsh_sort' ) ? 'ASC' : 'DESC';
		$clauses['join']   .= " LEFT JOIN {$wpdb->postmeta} AS wsh_m ON ( {$wpdb->posts}.ID = wsh_m.post_id AND wsh_m.meta_key = '_wsh_count' )";
		$clauses['orderby'] = "CAST( COALESCE( wsh_m.meta_value, '0' ) AS SIGNED ) {$dir}, {$wpdb->posts}.post_date DESC";
		return $clauses;
	}

	/**
	 * Report rows, most wanted first.
	 *
	 * @return array[] Each { id, name, sku, wishes, bought, stock, price }.
	 */
	public static function rows(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => self::LIMIT,
				'fields'         => 'ids',
				'meta_key'       => '_wsh_count', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		// Products bought from a list but no longer in any list.
		$bought = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => self::LIMIT,
				'fields'         => 'ids',
				'meta_key'       => '_wsh_bought', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		$ids = array_values( array_unique( array_merge( array_map( 'intval', $ids ), array_map( 'intval', $bought ) ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			$in = $p->is_in_stock() || ( $p->is_type( 'variable' ) && $p->child_is_in_stock() );
			$out[] = array(
				'id'     => $id,
				'name'   => wp_strip_all_tags( $p->get_name() ),
				'sku'    => (string) $p->get_sku(),
				'wishes' => (int) get_post_meta( $id, '_wsh_count', true ),
				'bought' => (int) get_post_meta( $id, '_wsh_bought', true ),
				'stock'  => $in ? 'in' : 'out',
				'price'  => '' === (string) $p->get_price() ? null : (float) wc_get_price_to_display( $p ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return array( $b['wishes'], $b['bought'] ) <=> array( $a['wishes'], $a['bought'] );
			}
		);
		return $out;
	}

	/** Totals: { customers, items } in lists. */
	public static function totals(): array {
		global $wpdb;
		if ( ! WSH_Store::ready() ) {
			return array(
				'customers' => 0,
				'items'     => 0,
			);
		}
		$t = WSH_Store::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$r = $wpdb->get_row( "SELECT COUNT(*) AS n, COUNT(DISTINCT user_id) AS u FROM {$t}", ARRAY_A );
		return array(
			'customers' => (int) ( $r['u'] ?? 0 ),
			'items'     => (int) ( $r['n'] ?? 0 ),
		);
	}

	/**
	 * Sends the CSV (Bible §5): UTF-8 with BOM, headers in the admin
	 * language, "Excel Greek" (; and decimal comma) or "Standard".
	 *
	 * @param string $format 'excel' | 'std'.
	 */
	public static function csv( string $format ): void {
		$sep  = 'excel' === $format ? ';' : ',';
		$dec  = 'excel' === $format ? ',' : '.';
		$rows = self::rows();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="wish-shelf-report-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv(
			$out,
			array(
				__( 'ID', 'wish-shelf' ),
				__( 'Προϊόν', 'wish-shelf' ),
				__( 'SKU', 'wish-shelf' ),
				__( 'Σε λίστες', 'wish-shelf' ),
				__( 'Αγοράστηκαν από λίστα', 'wish-shelf' ),
				__( 'Απόθεμα', 'wish-shelf' ),
				__( 'Τιμή', 'wish-shelf' ),
			),
			$sep,
			'"',
			'\\'
		);
		foreach ( $rows as $r ) {
			fputcsv(
				$out,
				array(
					$r['id'],
					self::cell( $r['name'] ),
					self::cell( $r['sku'] ),
					$r['wishes'],
					$r['bought'],
					'in' === $r['stock'] ? __( 'Σε απόθεμα', 'wish-shelf' ) : __( 'Εξαντλημένο', 'wish-shelf' ),
					null === $r['price'] ? '' : str_replace( '.', $dec, number_format( $r['price'], wc_get_price_decimals(), '.', '' ) ),
				),
				$sep,
				'"',
				'\\'
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Formula protection for text cells only (Bible §5).
	 *
	 * @param string $v Text.
	 */
	private static function cell( string $v ): string {
		return ( '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ) ? "'" . $v : $v;
	}
}

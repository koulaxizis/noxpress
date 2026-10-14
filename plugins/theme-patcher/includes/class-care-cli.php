<?php
/**
 * TP_Care_CLI — `wp theme-patcher care …` (read only).
 *
 *   wp theme-patcher care resolve <product_id>   profile and manufacturer keys
 *   wp theme-patcher care resolve --all          every product that gets a tab
 *
 * The resolution is the same as on the front end; the module switch, the
 * master switch and the test mode are reported, not applied.
 */

defined( 'ABSPATH' ) || exit;

final class TP_Care_CLI {

	/**
	 * Show which care profile and manufacturer a product resolves to.
	 *
	 * ## OPTIONS
	 *
	 * [<product_id>]
	 * : Product ID.
	 *
	 * [--all]
	 * : List every published product that gets the tab.
	 *
	 * [--format=<format>]
	 * : Output format for --all (table, csv, json, yaml, count, ids).
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp theme-patcher care resolve 123
	 *     wp theme-patcher care resolve --all --format=csv
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function resolve( $args, $assoc_args ): void {
		$all = ! empty( $assoc_args['all'] );
		if ( ! TP_Care::get()['enabled'] ) {
			\WP_CLI::warning( 'The Care & Safety tab is switched off in the Theme Patcher settings.' );
		}

		if ( ! $all ) {
			$id      = isset( $args[0] ) ? absint( $args[0] ) : 0;
			$product = $id ? wc_get_product( $id ) : null;
			if ( ! $product instanceof WC_Product ) {
				\WP_CLI::error( 'Give a product ID or --all.' );
			}
			$r = TP_Care::resolve( $product );
			\WP_CLI::line( 'profile: ' . ( null !== $r['profile'] ? $r['profile']['key'] : '-' ) );
			\WP_CLI::line( 'manufacturer: ' . ( null !== $r['maker'] ? $r['maker']['key'] : '-' ) );
			\WP_CLI::line( 'reason: ' . $r['reason'] );
			return;
		}

		$rows = array();
		$page = 1;
		do {
			$ids = wc_get_products(
				array(
					'status'  => 'publish',
					'limit'   => 200,
					'page'    => $page,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'return'  => 'ids',
				)
			);
			foreach ( $ids as $id ) {
				$product = wc_get_product( $id );
				if ( ! $product instanceof WC_Product ) {
					continue;
				}
				$r = TP_Care::resolve( $product );
				if ( null === $r['profile'] ) {
					continue;
				}
				$rows[] = array(
					'id'           => (int) $id,
					'name'         => $product->get_name(),
					'sku'          => $product->get_sku(),
					'profile'      => $r['profile']['key'],
					'manufacturer' => null !== $r['maker'] ? $r['maker']['key'] : '',
					'reason'       => $r['reason'],
				);
			}
			++$page;
			$more = 200 === count( $ids );
		} while ( $more );

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'name', 'sku', 'profile', 'manufacturer', 'reason' ) );
	}
}

<?php
/**
 * POR_Orders — orders that hold pre-orders.
 *
 * What the checkout saved (POR_Cart::snapshot()):
 *  - order line: a readable meta row («Pre-order: Releases on …», shown in
 *    the admin, emails, thank-you page and My account even without the
 *    plugin) and the hidden _por_release ('Y-m-d' or 'tba') and _por_key
 *    (the readable row's key, so a new date can replace its value)
 *  - order: _por_has 'yes' and _por_items ',12,15,' (the product or
 *    variation IDs on pre-order, for the filters and the jobs)
 *
 * Here:
 *  - Orders list (HPOS and legacy): a «Pre-order» column with the release
 *    date of the lines still waiting, and a filter «With pre-orders» (also
 *    per product, ?por_product=ID, linked from the plugin page).
 *  - Jobs (Action Scheduler, group pre-orders, hook por_orders_job), in
 *    batches of 20 orders, only for orders that are processing or on hold:
 *      released  the release job of a product ran: the lines are marked
 *                _por_released, a note goes on the order and the
 *                «Released» email to the customer (if enabled)
 *      date      the shop changed the release date and asked to update the
 *                open orders: the lines get the new date, a note, and the
 *                «New release date» email (if enabled and asked)
 *  - Order queries through wc_get_orders() only (Bible §1.3); the legacy
 *    store gets the meta_query through its own query filter.
 */

defined( 'ABSPATH' ) || exit;

final class POR_Orders {

	const M_HAS      = '_por_has';
	const M_ITEMS    = '_por_items';
	const I_RELEASE  = '_por_release';
	const I_KEY      = '_por_key';
	const I_RELEASED = '_por_released';
	const JOB        = 'por_orders_job';
	const BATCH      = 20;
	const OPEN       = array( 'processing', 'on-hold' );

	public static function init(): void {
		add_action( self::JOB, array( __CLASS__, 'run_job' ), 10, 6 );

		if ( is_admin() ) {
			// HPOS orders screen.
			add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'columns' ), 20 );
			add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'column_hpos' ), 10, 2 );
			add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( __CLASS__, 'filter_hpos' ), 20, 2 );
			add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( __CLASS__, 'query_hpos' ) );
			// Legacy orders screen.
			add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'columns' ), 20 );
			add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'column_legacy' ), 10, 2 );
			add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_legacy' ), 20 );
			add_action( 'pre_get_posts', array( __CLASS__, 'query_legacy' ) );
		}
	}

	/* =====================================================================
	 * Queries
	 * =================================================================== */

	/** True when orders live in the HPOS tables. */
	public static function hpos(): bool {
		return class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/** meta_query clause: orders with pre-orders, optionally of one product. */
	private static function clause( int $product_id ): array {
		if ( $product_id > 0 ) {
			return array(
				'key'     => self::M_ITEMS,
				'value'   => ',' . $product_id . ',',
				'compare' => 'LIKE',
			);
		}
		return array(
			'key'   => self::M_HAS,
			'value' => 'yes',
		);
	}

	/**
	 * wc_get_orders() with our meta_query on both order stores.
	 *
	 * @param array $q          wc_get_orders arguments.
	 * @param int   $product_id 0 = every order with pre-orders.
	 * @return mixed What wc_get_orders() returns.
	 */
	public static function find( array $q, int $product_id = 0 ) {
		$clause = self::clause( $product_id );
		if ( self::hpos() ) {
			$q['meta_query'] = array( $clause ); // phpcs:ignore WordPress.DB.SlowDBQuery -- our own flag, admin and background only.
			return wc_get_orders( $q );
		}
		// The legacy (posts) store ignores meta_query in wc_get_orders() and
		// only passes it to WP_Query through this filter.
		$cpt = static function ( $wp_args ) use ( $clause ) {
			if ( is_array( $wp_args ) ) {
				$wp_args['meta_query']   = isset( $wp_args['meta_query'] ) && is_array( $wp_args['meta_query'] ) ? $wp_args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery
				$wp_args['meta_query'][] = $clause;
			}
			return $wp_args;
		};
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $cpt, 10, 1 );
		try {
			return wc_get_orders( $q );
		} finally {
			remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $cpt, 10 );
		}
	}

	/** Number of open orders (processing, on hold) waiting for a product's release. */
	public static function count_open( int $product_id ): int {
		$res = self::find(
			array(
				'status'   => self::OPEN,
				'type'     => 'shop_order',
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
			),
			$product_id
		);
		return is_object( $res ) ? (int) $res->total : 0;
	}

	/**
	 * The pre-order lines of an order for a product (or every product).
	 *
	 * @return WC_Order_Item_Product[] item_id => item
	 */
	public static function lines( WC_Order $order, int $product_id = 0 ): array {
		$out = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || '' === (string) $item->get_meta( self::I_RELEASE, true ) ) {
				continue;
			}
			if ( $product_id > 0 && $product_id !== (int) $item->get_product_id() && $product_id !== (int) $item->get_variation_id() ) {
				continue;
			}
			$out[ $item_id ] = $item;
		}
		return $out;
	}

	/** The product (or variation) ID a pre-order line was bought as. */
	public static function line_product( WC_Order_Item_Product $item ): int {
		return (int) ( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() );
	}

	/* =====================================================================
	 * Jobs (released / new date)
	 * =================================================================== */

	/**
	 * Queues a job over the open orders of a product.
	 *
	 * @param string $kind   'released' | 'date'.
	 * @param int    $id     Product or variation ID.
	 * @param string $date   Release date or new date ('Y-m-d' or '' for «Coming soon»).
	 * @param string $old    Previous date (kind 'date').
	 * @param bool   $notify Send the «New release date» email (kind 'date').
	 */
	public static function queue( string $kind, int $id, string $date, string $old, bool $notify = true ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		as_enqueue_async_action( self::JOB, array( $kind, $id, $date, $old, $notify ? 1 : 0, 0 ), POR_Product::GROUP );
	}

	/**
	 * Job runner: one batch, then the next batch as a new job.
	 *
	 * @param string $kind   'released' | 'date'.
	 * @param int    $id     Product or variation ID.
	 * @param string $date   Date.
	 * @param string $old    Previous date.
	 * @param int    $notify 1 = send the email.
	 * @param int    $after  Last order ID done.
	 */
	public static function run_job( $kind, $id, $date, $old = '', $notify = 1, $after = 0 ): void {
		$kind  = in_array( $kind, array( 'released', 'date' ), true ) ? $kind : '';
		$id    = (int) $id;
		$date  = POR_Settings::valid_date( (string) $date ) ? (string) $date : '';
		$old   = POR_Settings::valid_date( (string) $old ) ? (string) $old : '';
		$after = (int) $after;
		if ( '' === $kind || $id <= 0 ) {
			return;
		}

		// IDs in ascending order after the last one done: a batch never
		// sees an order twice, even when the job changes the orders.
		$ids = self::find(
			array(
				'status'  => self::OPEN,
				'type'    => 'shop_order',
				'limit'   => -1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			),
			$id
		);
		$ids = array_values(
			array_filter(
				array_map( 'intval', (array) $ids ),
				static function ( $oid ) use ( $after ) {
					return $oid > $after;
				}
			)
		);
		sort( $ids );
		$batch = array_slice( $ids, 0, self::BATCH );

		foreach ( $batch as $oid ) {
			$order = wc_get_order( $oid );
			if ( $order instanceof WC_Order ) {
				try {
					if ( 'released' === $kind ) {
						self::mark_released( $order, $id, $date );
					} else {
						self::change_date( $order, $id, $date, $old, (bool) $notify );
					}
				} catch ( \Throwable $e ) {
					wc_get_logger()->error( 'Pre-Orders: order ' . $oid . ': ' . $e->getMessage(), array( 'source' => 'pre-orders' ) );
				}
			}
			$after = $oid;
		}

		if ( count( $ids ) > self::BATCH ) {
			as_enqueue_async_action( self::JOB, array( $kind, $id, $date, $old, (int) $notify, $after ), POR_Product::GROUP );
		}
	}

	/** Release day: mark the lines, note the order, email the customer once. */
	private static function mark_released( WC_Order $order, int $id, string $date ): void {
		$items = array();
		foreach ( self::lines( $order, $id ) as $item_id => $item ) {
			if ( '' !== (string) $item->get_meta( self::I_RELEASED, true ) ) {
				continue;
			}
			$item->update_meta_data( self::I_RELEASED, '' !== $date ? $date : POR_Settings::today() );
			$item->save();
			$items[ $item_id ] = $item;
		}
		if ( ! $items ) {
			return;
		}
		$names = array();
		foreach ( $items as $item ) {
			$names[] = $item->get_name();
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: product names, 2: date */
				__( 'Pre-Orders: κυκλοφόρησε %1$s (%2$s).', 'pre-orders' ),
				'«' . implode( '», «', $names ) . '»',
				'' !== $date ? $date : POR_Settings::today()
			)
		);
		POR_Emails::released( $order, array_keys( $items ) );
	}

	/** New release date: update the lines and their readable row, note, email. */
	private static function change_date( WC_Order $order, int $id, string $date, string $old, bool $notify ): void {
		$items = array();
		foreach ( self::lines( $order, $id ) as $item_id => $item ) {
			$cur = (string) $item->get_meta( self::I_RELEASE, true );
			if ( '' !== (string) $item->get_meta( self::I_RELEASED, true ) || $cur === ( '' !== $date ? $date : 'tba' ) ) {
				continue;
			}
			$item->update_meta_data( self::I_RELEASE, '' !== $date ? $date : 'tba' );
			$key = (string) $item->get_meta( self::I_KEY, true );
			if ( '' !== $key ) {
				$item->update_meta_data( $key, POR_Settings::release_text( $date ) );
			}
			$item->save();
			$items[ $item_id ] = $item;
		}
		if ( ! $items ) {
			return;
		}
		$names = array();
		foreach ( $items as $item ) {
			$names[] = $item->get_name();
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: product names, 2: old date, 3: new date */
				__( 'Pre-Orders: νέα ημερομηνία κυκλοφορίας για %1$s: %2$s → %3$s.', 'pre-orders' ),
				'«' . implode( '», «', $names ) . '»',
				'' !== $old ? $old : '—',
				'' !== $date ? $date : '—'
			)
		);
		if ( $notify ) {
			POR_Emails::new_date( $order, array_keys( $items ) );
		}
	}

	/* =====================================================================
	 * Orders list (HPOS + legacy)
	 * =================================================================== */

	/** Adds the «Pre-order» column after the status. */
	public static function columns( $cols ): array {
		$cols = is_array( $cols ) ? $cols : array();
		$out  = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'order_status' === $k ) {
				$out['por'] = __( 'Προπαραγγελία', 'pre-orders' );
			}
		}
		if ( ! isset( $out['por'] ) ) {
			$out['por'] = __( 'Προπαραγγελία', 'pre-orders' );
		}
		return $out;
	}

	public static function column_hpos( $column, $order ): void {
		if ( 'por' === $column && $order instanceof WC_Order ) {
			self::cell( $order );
		}
	}

	public static function column_legacy( $column, $post_id ): void {
		if ( 'por' !== $column ) {
			return;
		}
		$order = wc_get_order( (int) $post_id );
		if ( $order instanceof WC_Order ) {
			self::cell( $order );
		}
	}

	/** Cell: earliest date still waiting, or «released». */
	private static function cell( WC_Order $order ): void {
		if ( 'yes' !== $order->get_meta( self::M_HAS, true ) ) {
			return;
		}
		$waiting = array();
		$tba     = false;
		foreach ( self::lines( $order ) as $item ) {
			if ( '' !== (string) $item->get_meta( self::I_RELEASED, true ) ) {
				continue;
			}
			$d = (string) $item->get_meta( self::I_RELEASE, true );
			if ( POR_Settings::valid_date( $d ) ) {
				$waiting[] = $d;
			} else {
				$tba = true;
			}
		}
		if ( $waiting ) {
			$label = wp_date( (string) get_option( 'date_format', 'Y-m-d' ), POR_Settings::timestamp( min( $waiting ) ) );
			echo '<mark class="order-status status-on-hold por-mark"><span>' . esc_html( $label ) . '</span></mark>';
		} elseif ( $tba ) {
			echo '<mark class="order-status status-on-hold por-mark"><span>' . esc_html__( 'χωρίς ημερομηνία', 'pre-orders' ) . '</span></mark>';
		} else {
			echo '<span class="por-mark-done">' . esc_html__( 'κυκλοφόρησε', 'pre-orders' ) . '</span>';
		}
	}

	/** The filter's current value: '' | 'all' | product ID as string. */
	private static function current_filter(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		if ( isset( $_GET['por_product'] ) && is_scalar( $_GET['por_product'] ) && (int) $_GET['por_product'] > 0 ) {
			return (string) (int) $_GET['por_product'];
		}
		if ( isset( $_GET['por_filter'] ) && 'all' === sanitize_key( wp_unslash( (string) $_GET['por_filter'] ) ) ) {
			return 'all';
		}
		// phpcs:enable
		return '';
	}

	private static function dropdown(): void {
		$cur = self::current_filter();
		echo '<select name="por_filter" id="por_filter"><option value="">' . esc_html__( 'Όλες οι παραγγελίες', 'pre-orders' ) . '</option>';
		echo '<option value="all" ' . selected( '' !== $cur, true, false ) . '>' . esc_html__( 'Με προπαραγγελία', 'pre-orders' ) . '</option></select>';
		if ( '' !== $cur && 'all' !== $cur ) {
			echo '<input type="hidden" name="por_product" value="' . esc_attr( $cur ) . '" />';
		}
	}

	public static function filter_hpos( $order_type = 'shop_order', $which = 'top' ): void {
		if ( 'shop_order' === $order_type && 'top' === $which ) {
			self::dropdown();
		}
	}

	public static function filter_legacy( $post_type ): void {
		if ( 'shop_order' === $post_type ) {
			self::dropdown();
		}
	}

	/** HPOS list query: our meta_query. */
	public static function query_hpos( $args ): array {
		$args = is_array( $args ) ? $args : array();
		$cur  = self::current_filter();
		if ( '' !== $cur ) {
			$args['meta_query']   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery
			$args['meta_query'][] = self::clause( 'all' === $cur ? 0 : (int) $cur );
		}
		return $args;
	}

	/** Legacy list query (edit.php?post_type=shop_order). */
	public static function query_legacy( $q ): void {
		if ( ! $q instanceof WP_Query || ! $q->is_main_query() || 'shop_order' !== $q->get( 'post_type' ) ) {
			return;
		}
		global $pagenow;
		if ( 'edit.php' !== $pagenow ) {
			return;
		}
		$cur = self::current_filter();
		if ( '' === $cur ) {
			return;
		}
		$mq   = $q->get( 'meta_query' );
		$mq   = is_array( $mq ) ? $mq : array();
		$mq[] = self::clause( 'all' === $cur ? 0 : (int) $cur );
		$q->set( 'meta_query', $mq ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}
}

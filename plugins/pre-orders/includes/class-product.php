<?php
/**
 * POR_Product — the pre-order data of products and variations, the derived
 * data of the catalog, and the release day.
 *
 * Stored on the simple product or on the variation (product meta, CRUD):
 *  - _por_on    'yes' while the product is on pre-order
 *  - _por_date  release date 'Y-m-d', or '' (no date yet: «Coming soon»)
 *  - _por_text  optional text for this product, instead of the general one
 *
 * A pre-order is ACTIVE while _por_on is 'yes' and the date is empty or
 * later than today (site time zone). On the release date it is a normal
 * product again: the check runs on every view, so this holds without cron.
 *
 * Derived data (Bible §14.10, rebuilt on every product save, never on a
 * page view): _por_sort on the catalog product (the simple product, or the
 * parent of variations) = its earliest active release date, '9999-12-31'
 * for «Coming soon»; deleted when nothing is on pre-order. It drives the
 * badge of variable products, the «Coming soon» list and the admin filter.
 * The option por_horizon is the latest active date of the whole store
 * ('' = none): with nothing on pre-order, the front end adds nothing.
 *
 * Release day: one Action Scheduler job per product (group pre-orders,
 * hook por_release) at 00:01 of the release date. It removes the
 * pre-order from the product and saves it with CRUD, which is what page
 * caches (WP Rocket, LiteSpeed …), WooCommerce's transients and feeds
 * already listen to, then fires por_released and queues the «Released»
 * emails (POR_Orders). If cron does not run, the store is still right;
 * only a page cache may hold the old page until it expires.
 */

defined( 'ABSPATH' ) || exit;

final class POR_Product {

	const M_ON    = '_por_on';
	const M_DATE  = '_por_date';
	const M_TEXT  = '_por_text';
	const M_SORT  = '_por_sort';
	const TBA     = '9999-12-31';
	const HORIZON = 'por_horizon';
	const GROUP   = 'pre-orders';
	const HOOK    = 'por_release';
	const TEXT_LEN = 120;

	public static function init(): void {
		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'woocommerce_ajax_save_product_variations', array( __CLASS__, 'sync_parent' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_delete' ) );
		add_action( self::HOOK, array( __CLASS__, 'on_release' ) );
		add_action( 'por_resync', array( __CLASS__, 'resync_all' ) );
	}

	/* =====================================================================
	 * Readers
	 * =================================================================== */

	/**
	 * Raw pre-order data of a simple product or a variation.
	 *
	 * @return array{on: bool, date: string, text: string}
	 */
	public static function raw( WC_Product $p ): array {
		$date = (string) $p->get_meta( self::M_DATE, true, 'edit' );
		return array(
			'on'   => 'yes' === $p->get_meta( self::M_ON, true, 'edit' ),
			'date' => POR_Settings::valid_date( $date ) ? $date : '',
			'text' => (string) $p->get_meta( self::M_TEXT, true, 'edit' ),
		);
	}

	/** True when a raw record is an active pre-order today. */
	public static function is_active_raw( array $r ): bool {
		return $r['on'] && ( '' === $r['date'] || $r['date'] > POR_Settings::today() );
	}

	/**
	 * The active pre-order of a simple product or a variation, or null.
	 * Variable parents: see catalog_date().
	 *
	 * @return array{date: string, text: string}|null
	 */
	public static function active( WC_Product $p ): ?array {
		if ( $p->is_type( 'variable' ) || $p->is_type( 'grouped' ) || $p->is_type( 'external' ) ) {
			return null;
		}
		$r  = self::raw( $p );
		$on = self::is_active_raw( $r );
		/**
		 * Filters whether a product or variation is on pre-order right now.
		 *
		 * @param bool       $on      Active pre-order.
		 * @param WC_Product $product Product or variation.
		 */
		if ( ! apply_filters( 'por_is_preorder', $on, $p ) ) {
			return null;
		}
		return array(
			'date' => $r['date'],
			'text' => $r['text'],
		);
	}

	/**
	 * The earliest active release date among a catalog product's pre-orders
	 * ('' = «Coming soon»), or null when none is active. Works for simple and
	 * variable products from the derived _por_sort (no variation loads).
	 */
	public static function catalog_date( WC_Product $p ): ?string {
		if ( ! $p->is_type( 'variable' ) ) {
			$a = self::active( $p );
			return null === $a ? null : $a['date'];
		}
		$sort = (string) $p->get_meta( self::M_SORT, true, 'edit' );
		if ( self::TBA === $sort ) {
			return '';
		}
		if ( ! POR_Settings::valid_date( $sort ) || $sort <= POR_Settings::today() ) {
			return null;
		}
		return $sort;
	}

	/** The visitor text of an active pre-order: the product's own, else «Releases on {date}». */
	public static function availability_text( array $active ): string {
		if ( '' !== trim( $active['text'] ) ) {
			return str_replace( '{date}', POR_Settings::format_date( $active['date'] ), $active['text'] );
		}
		return POR_Settings::release_text( $active['date'] );
	}

	/** True when the store may have an active pre-order today (front end shortcut). */
	public static function any_active(): bool {
		$h = (string) get_option( self::HORIZON, '' );
		return '' !== $h && $h > POR_Settings::today();
	}

	/**
	 * Public API data (por_get_release()).
	 *
	 * @param int|WC_Product $product Product, variation or ID.
	 * @return array|null { active: true, date: 'Y-m-d'|'', text: string, variations?: [ id => 'Y-m-d'|'' ] }
	 */
	public static function api( $product ): ?array {
		$p = $product instanceof WC_Product ? $product : wc_get_product( (int) $product );
		if ( ! $p instanceof WC_Product ) {
			return null;
		}
		if ( $p->is_type( 'variable' ) ) {
			$vars = array();
			foreach ( $p->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				$a = $v instanceof WC_Product ? self::active( $v ) : null;
				if ( null !== $a ) {
					$vars[ (int) $vid ] = $a['date'];
				}
			}
			if ( ! $vars ) {
				return null;
			}
			$dated = array_filter( $vars );
			$first = $dated ? min( $dated ) : '';
			return array(
				'active'     => true,
				'date'       => $first,
				'text'       => POR_Settings::release_text( $first ),
				'variations' => $vars,
			);
		}
		$a = self::active( $p );
		if ( null === $a ) {
			return null;
		}
		return array(
			'active' => true,
			'date'   => $a['date'],
			'text'   => self::availability_text( $a ),
		);
	}

	/* =====================================================================
	 * Writers (admin forms, quick / bulk edit, release)
	 * =================================================================== */

	/**
	 * Sets the pre-order fields on a product object (saved by the caller).
	 *
	 * @param WC_Product $p    Simple product or variation.
	 * @param bool       $on   On pre-order.
	 * @param string     $date 'Y-m-d' or '' (already validated).
	 * @param string|null $text Own text, null = leave as is.
	 */
	public static function set( WC_Product $p, bool $on, string $date, ?string $text = null ): void {
		if ( $on ) {
			$p->update_meta_data( self::M_ON, 'yes' );
			if ( '' !== $date ) {
				$p->update_meta_data( self::M_DATE, $date );
			} else {
				$p->delete_meta_data( self::M_DATE );
			}
		} else {
			$p->delete_meta_data( self::M_ON );
			$p->delete_meta_data( self::M_DATE );
		}
		if ( null !== $text ) {
			$text = POR_Settings::line( $text, self::TEXT_LEN );
			if ( '' !== $text ) {
				$p->update_meta_data( self::M_TEXT, $text );
			} else {
				$p->delete_meta_data( self::M_TEXT );
			}
		}
	}

	/* =====================================================================
	 * Derived data and scheduling
	 * =================================================================== */

	/**
	 * woocommerce_new/update_product(_variation): keep the derived data and
	 * the release job in step. Writes only when something changed, so the
	 * stock saves of a checkout cost one meta read for other products.
	 *
	 * @param int        $id      Product ID.
	 * @param WC_Product $product Product (WooCommerce passes it since 3.x).
	 */
	public static function on_save( $id, $product = null ): void {
		$p = $product instanceof WC_Product ? $product : wc_get_product( (int) $id );
		if ( ! $p instanceof WC_Product ) {
			return;
		}
		try {
			if ( $p->is_type( 'variation' ) ) {
				$parent = (int) $p->get_parent_id();
				if ( self::raw( $p )['on'] || ( $parent && '' !== (string) get_post_meta( $parent, self::M_SORT, true ) ) ) {
					self::schedule( $p );
					if ( $parent ) {
						self::sync_parent( $parent );
					}
					self::refresh_horizon();
				}
				return;
			}
			if ( $p->is_type( 'variable' ) ) {
				// Variations keep the parent in step; here only a parent that has pre-orders.
				if ( '' !== (string) get_post_meta( (int) $p->get_id(), self::M_SORT, true ) ) {
					self::sync_parent( (int) $p->get_id() );
					self::refresh_horizon();
				}
				return;
			}
			$r = self::raw( $p );
			if ( ! $r['on'] && '' === (string) get_post_meta( (int) $p->get_id(), self::M_SORT, true ) ) {
				// Not a pre-order: only drop a release job it may still have.
				if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, array( (int) $p->get_id() ), self::GROUP ) ) {
					as_unschedule_all_actions( self::HOOK, array( (int) $p->get_id() ), self::GROUP );
				}
				return;
			}
			self::schedule( $p );
			$sort = self::is_active_raw( $r ) ? ( '' === $r['date'] ? self::TBA : $r['date'] ) : '';
			self::write_sort( (int) $p->get_id(), $sort );
			self::refresh_horizon();
		} catch ( \Throwable $e ) {
			// Never break a product save (Bible §14.6).
			return;
		}
	}

	/** Rebuilds _por_sort of a variable product from its variations. */
	public static function sync_parent( $parent_id ): void {
		$parent_id = (int) $parent_id;
		$parent    = $parent_id ? wc_get_product( $parent_id ) : null;
		if ( ! $parent instanceof WC_Product ) {
			return;
		}
		$ids = $parent->is_type( 'variable' ) ? array_map( 'intval', $parent->get_children() ) : array();
		if ( $ids ) {
			update_meta_cache( 'post', $ids );
		}
		$best  = '';
		$today = POR_Settings::today();
		foreach ( $ids as $vid ) {
			if ( 'yes' !== get_post_meta( $vid, self::M_ON, true ) ) {
				continue;
			}
			$d = (string) get_post_meta( $vid, self::M_DATE, true );
			$d = POR_Settings::valid_date( $d ) ? $d : self::TBA;
			if ( $d <= $today ) {
				continue;
			}
			if ( '' === $best || $d < $best ) {
				$best = $d;
			}
		}
		self::write_sort( $parent_id, $best );
	}

	/** Writes or deletes _por_sort, only on change. */
	private static function write_sort( int $id, string $sort ): void {
		$old = (string) get_post_meta( $id, self::M_SORT, true );
		if ( $old === $sort ) {
			return;
		}
		if ( '' === $sort ) {
			delete_post_meta( $id, self::M_SORT );
		} else {
			update_post_meta( $id, self::M_SORT, $sort );
		}
	}

	/** por_horizon = the latest active release date of the store ('' = none). */
	public static function refresh_horizon(): void {
		global $wpdb;
		// Every product or variation on pre-order, «Coming soon» counted as the far future.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one aggregate over our own meta, run on pre-order saves only.
		$max = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX( CASE WHEN d.meta_value IS NULL OR d.meta_value = '' THEN %s ELSE d.meta_value END )
				FROM {$wpdb->postmeta} o
				LEFT JOIN {$wpdb->postmeta} d ON d.post_id = o.post_id AND d.meta_key = %s
				WHERE o.meta_key = %s AND o.meta_value = 'yes'",
				self::TBA,
				self::M_DATE,
				self::M_ON
			)
		);
		$h = (string) $max;
		$h = ( '' !== $h && $h > POR_Settings::today() ) ? $h : '';
		if ( (string) get_option( self::HORIZON, '' ) !== $h ) {
			update_option( self::HORIZON, $h, true );
		}
	}

	/** One release job per product or variation, at 00:01 of its release date. */
	private static function schedule( WC_Product $p ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$id   = (int) $p->get_id();
		$args = array( $id );
		$r    = self::raw( $p );
		$ts   = ( $r['on'] && '' !== $r['date'] && $r['date'] > POR_Settings::today() ) ? POR_Settings::timestamp( $r['date'] ) + 60 : 0;
		$next = as_next_scheduled_action( self::HOOK, $args, self::GROUP );
		if ( is_int( $next ) && $next === $ts ) {
			return;
		}
		if ( false !== $next ) {
			as_unschedule_all_actions( self::HOOK, $args, self::GROUP );
		}
		if ( $ts ) {
			as_schedule_single_action( $ts, self::HOOK, $args, self::GROUP );
		}
	}

	/** before_delete_post / wp_trash_post: drop the release job of a product. */
	public static function on_delete( $post_id ): void {
		$type = get_post_type( (int) $post_id );
		if ( ( 'product' !== $type && 'product_variation' !== $type ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		if ( 'yes' === get_post_meta( (int) $post_id, self::M_ON, true ) ) {
			as_unschedule_all_actions( self::HOOK, array( (int) $post_id ), self::GROUP );
		}
	}

	/**
	 * Release job (Action Scheduler, hook por_release).
	 *
	 * @param int $id Product or variation ID.
	 */
	public static function on_release( $id ): void {
		$p = wc_get_product( (int) $id );
		if ( ! $p instanceof WC_Product ) {
			return;
		}
		$r = self::raw( $p );
		if ( ! $r['on'] || '' === $r['date'] ) {
			return;
		}
		if ( $r['date'] > POR_Settings::today() ) {
			self::schedule( $p ); // Run too early (clock change): try again on the day.
			return;
		}
		$date = $r['date'];
		self::set( $p, false, '', '' );
		$p->save(); // Page caches, WooCommerce transients and feeds follow this save.
		if ( $p->is_type( 'variation' ) && $p->get_parent_id() ) {
			$parent = wc_get_product( $p->get_parent_id() );
			if ( $parent instanceof WC_Product ) {
				$parent->save();
			}
		}
		clean_post_cache( (int) $id );

		/**
		 * Fires when a pre-order product or variation is released (release job).
		 *
		 * @param int    $id   Product or variation ID.
		 * @param string $date Release date 'Y-m-d'.
		 */
		do_action( 'por_released', (int) $id, $date );

		POR_Orders::queue( 'released', (int) $id, $date, '' );
	}

	/**
	 * Background job (por_resync, after activation): rebuilds the derived data
	 * and the release jobs of every product on pre-order, in batches.
	 *
	 * @param int $after Last product ID done.
	 */
	public static function resync_all( $after = 0 ): void {
		global $wpdb;
		$after = (int) $after;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- background batch over our own meta.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s ) AND post_id > %d ORDER BY post_id ASC LIMIT 50",
				self::M_ON,
				self::M_SORT,
				$after
			)
		);
		foreach ( (array) $ids as $pid ) {
			self::on_save( (int) $pid );
			$after = (int) $pid;
		}
		if ( count( (array) $ids ) === 50 && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'por_resync', array( $after ), self::GROUP );
		} else {
			self::refresh_horizon();
		}
	}
}

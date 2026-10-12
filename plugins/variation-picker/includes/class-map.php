<?php
/**
 * VPK_Map — the availability map of a variable product (Bible §14.10).
 *
 * A compact list of the product's purchasable variations, built when the
 * product (or one of its variations, or their stock) is saved and stored in
 * the post meta _vpk_map (JSON):
 *
 *   { "ver": 1, "a": [ "attribute_pa_chroma", "attribute_pa_megethos" ],
 *     "v": [ [ "mayro", "s", 1, 25, -1, 80954 ], … ] }
 *
 * Each row: the attribute values in the order of "a" ('' = any), in stock
 * (1/0), display price, stock quantity (-1 = not tracked or backorders
 * allowed), variation image id (0 = none).
 *
 * Page views only read it. It lets the swatches know which combinations
 * exist even when WooCommerce does not print its variation list (more than
 * 30 variations, woocommerce_ajax_variation_threshold), and gives the
 * variation images to the "variation image" swatches and to the shop.
 *
 * When to build: admin, REST, cron and CLI requests build at shutdown. A
 * stock change during a visitor request (an order) only queues an Action
 * Scheduler job (group variation-picker), so the visitor request stays
 * light. A missing map is built in memory (never stored) for products under
 * the threshold, where WooCommerce loads the variations anyway.
 */

defined( 'ABSPATH' ) || exit;

final class VPK_Map {

	const META    = '_vpk_map';
	const VER     = 1;
	const GROUP   = 'variation-picker';
	const HOOK    = 'vpk_build_map';
	const HOOK_ALL = 'vpk_build_all';
	const BATCH   = 50;
	const MAX_ROWS = 1500;

	/** @var int[] Products to rebuild at shutdown (id => id). */
	private static $queue = array();

	/** @var array Per-request cache: product id => map|false. */
	private static $cache = array();

	public static function init(): void {
		add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'on_save' ), 20, 1 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_variation' ), 20, 1 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'on_variation_status' ), 20, 3 );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_save' ), 20, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ), 20, 2 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_delete' ), 20, 1 );
		add_action( self::HOOK, array( __CLASS__, 'run_one' ), 10, 1 );
		add_action( self::HOOK_ALL, array( __CLASS__, 'run_batch' ), 10, 1 );
	}

	/* =====================================================================
	 * Triggers
	 * =================================================================== */

	/**
	 * A product object was saved (or its stock set).
	 *
	 * @param mixed $product WC_Product.
	 */
	public static function on_save( $product ): void {
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		if ( $product->is_type( 'variation' ) ) {
			self::queue( (int) $product->get_parent_id() );
		} elseif ( $product->is_type( 'variable' ) ) {
			self::queue( (int) $product->get_id() );
		}
	}

	/** @param mixed $variation WC_Product_Variation. */
	public static function on_variation( $variation ): void {
		if ( $variation instanceof WC_Product ) {
			self::queue( (int) $variation->get_parent_id() );
		}
	}

	/**
	 * @param mixed $id      Variation id.
	 * @param mixed $status  Stock status.
	 * @param mixed $product Variation.
	 */
	public static function on_variation_status( $id, $status = '', $product = null ): void {
		$parent = $product instanceof WC_Product ? (int) $product->get_parent_id() : (int) wp_get_post_parent_id( (int) $id );
		self::queue( $parent );
	}

	/**
	 * A variation is trashed or deleted: its parent's map changes.
	 *
	 * @param mixed $post_id Post id.
	 * @param mixed $post    WP_Post (before_delete_post only).
	 */
	public static function on_delete( $post_id, $post = null ): void {
		$post = $post instanceof WP_Post ? $post : get_post( (int) $post_id );
		if ( $post instanceof WP_Post && 'product_variation' === $post->post_type ) {
			self::queue( (int) $post->post_parent );
		}
	}

	private static function queue( int $id ): void {
		if ( $id <= 0 ) {
			return;
		}
		if ( empty( self::$queue ) ) {
			add_action( 'shutdown', array( __CLASS__, 'flush_queue' ), 5 );
		}
		self::$queue[ $id ] = $id;
		unset( self::$cache[ $id ] );
	}

	/** True when this request may build maps directly (no visitor waits for it). */
	private static function direct(): bool {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return true;
		}
		if ( ! current_user_can( 'edit_products' ) ) {
			return false;
		}
		return ( is_admin() && ! wp_doing_ajax() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax();
	}

	public static function flush_queue(): void {
		$ids         = self::$queue;
		self::$queue = array();
		$direct      = self::direct();
		foreach ( $ids as $id ) {
			try {
				if ( $direct ) {
					self::store( $id );
				} else {
					self::enqueue( $id );
				}
			} catch ( \Throwable $e ) {
				// A map is derived data: the next save rebuilds it.
				continue;
			}
		}
	}

	/** Queues one rebuild in Action Scheduler (once per product). */
	public static function enqueue( int $id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		$args = array( $id );
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return;
		}
		as_enqueue_async_action( self::HOOK, $args, self::GROUP );
	}

	/** Queues a rebuild of every variable product, in batches. */
	public static function enqueue_all(): bool {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_ALL, null, self::GROUP );
		}
		as_enqueue_async_action( self::HOOK_ALL, array( 0 ), self::GROUP );
		return true;
	}

	/** @param mixed $id Product id (Action Scheduler). */
	public static function run_one( $id ): void {
		self::store( (int) $id );
	}

	/** @param mixed $after Last product id of the previous batch (Action Scheduler). */
	public static function run_batch( $after ): void {
		$ids = self::variable_ids( (int) $after, self::BATCH );
		foreach ( $ids as $id ) {
			try {
				self::store( $id );
			} catch ( \Throwable $e ) {
				continue;
			}
		}
		if ( count( $ids ) === self::BATCH && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_ALL, array( (int) end( $ids ) ), self::GROUP );
		}
	}

	/**
	 * Variable product ids after $after (any status but trash), ascending.
	 *
	 * @param int $after Id to start after.
	 * @param int $limit Max ids.
	 * @return int[]
	 */
	public static function variable_ids( int $after, int $limit ): array {
		$term = get_term_by( 'slug', 'variable', 'product_type' );
		if ( ! $term ) {
			return array();
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only id list for a background batch.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				 WHERE tr.term_taxonomy_id = %d AND p.post_type = 'product' AND p.post_status <> 'trash' AND p.ID > %d
				 ORDER BY p.ID ASC LIMIT %d",
				(int) $term->term_taxonomy_id,
				$after,
				$limit
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Map coverage for the tools page: { variable, mapped }.
	 */
	public static function coverage(): array {
		$term = get_term_by( 'slug', 'variable', 'product_type' );
		if ( ! $term ) {
			return array(
				'variable' => 0,
				'mapped'   => 0,
			);
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- read-only counts on an admin page.
		$variable = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				 WHERE tr.term_taxonomy_id = %d AND p.post_type = 'product' AND p.post_status <> 'trash'",
				(int) $term->term_taxonomy_id
			)
		);
		$mapped   = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				 WHERE tr.term_taxonomy_id = %d AND p.post_type = 'product' AND p.post_status <> 'trash'",
				self::META,
				(int) $term->term_taxonomy_id
			)
		);
		// phpcs:enable
		return array(
			'variable' => $variable,
			'mapped'   => $mapped,
		);
	}

	/* =====================================================================
	 * Build / store / read
	 * =================================================================== */

	/** Builds and stores the map of one product (removes it when the product is not variable). */
	public static function store( int $id ): void {
		$product = wc_get_product( $id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			delete_post_meta( $id, self::META );
			return;
		}
		$map = self::build( $product );
		update_post_meta( $id, self::META, wp_slash( (string) wp_json_encode( $map ) ) );
		self::$cache[ $id ] = $map;
	}

	/**
	 * The map of a variable product (see the class docblock).
	 *
	 * @param WC_Product $product Variable product.
	 */
	public static function build( WC_Product $product ): array {
		$keys = array();
		foreach ( array_keys( $product->get_variation_attributes() ) as $name ) {
			$keys[] = 'attribute_' . sanitize_title( $name );
		}
		$hide_oos = 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' );
		$rows     = array();
		foreach ( $product->get_children() as $child_id ) {
			$v = wc_get_product( $child_id );
			if ( ! $v || ! $v->is_type( 'variation' ) || 'publish' !== $v->get_status() || '' === $v->get_price() ) {
				continue;
			}
			$in = $v->is_in_stock();
			if ( $hide_oos && ! $in ) {
				continue;
			}
			$attrs = $v->get_variation_attributes();
			$row   = array();
			foreach ( $keys as $k ) {
				$row[] = isset( $attrs[ $k ] ) ? (string) $attrs[ $k ] : '';
			}
			$qty   = ( $v->managing_stock() && ! $v->backorders_allowed() ) ? max( 0, (int) $v->get_stock_quantity() ) : -1;
			$row[] = $in ? 1 : 0;
			$row[] = round( (float) wc_get_price_to_display( $v ), 4 );
			$row[] = $qty;
			$row[] = (int) $v->get_image_id();
			$rows[] = $row;
			if ( count( $rows ) >= self::MAX_ROWS ) {
				break;
			}
		}
		return array(
			'ver' => self::VER,
			'a'   => $keys,
			'v'   => $rows,
		);
	}

	/**
	 * The map of a product: stored, or built in memory for small products,
	 * or null.
	 *
	 * @param WC_Product $product Variable product.
	 */
	public static function get( WC_Product $product ): ?array {
		$id = (int) $product->get_id();
		if ( array_key_exists( $id, self::$cache ) ) {
			return false === self::$cache[ $id ] ? null : self::$cache[ $id ];
		}
		$map = self::decode( (string) get_post_meta( $id, self::META, true ) );
		if ( null === $map ) {
			$threshold = (int) apply_filters( 'woocommerce_ajax_variation_threshold', 30, $product );
			if ( count( $product->get_children() ) <= $threshold ) {
				$map = self::build( $product );
			}
		}
		self::$cache[ $id ] = null === $map ? false : $map;
		return $map;
	}

	/**
	 * Only the stored map (shop cards: never builds on a page view).
	 *
	 * @param int $id Product id.
	 */
	public static function stored( int $id ): ?array {
		if ( array_key_exists( $id, self::$cache ) ) {
			return false === self::$cache[ $id ] ? null : self::$cache[ $id ];
		}
		$map = self::decode( (string) get_post_meta( $id, self::META, true ) );
		self::$cache[ $id ] = null === $map ? false : $map;
		return $map;
	}

	/** A validated map, or null. */
	private static function decode( string $json ): ?array {
		if ( '' === $json ) {
			return null;
		}
		$map = json_decode( $json, true );
		if ( ! is_array( $map ) || ! isset( $map['ver'], $map['a'], $map['v'] ) || self::VER !== (int) $map['ver'] || ! is_array( $map['a'] ) || ! is_array( $map['v'] ) ) {
			return null;
		}
		$n = count( $map['a'] );
		foreach ( $map['v'] as $row ) {
			if ( ! is_array( $row ) || count( $row ) !== $n + 4 ) {
				return null;
			}
		}
		return $map;
	}

	/**
	 * The image id of each value of one attribute: the image of the first
	 * variation with that value and its own image.
	 *
	 * @param array  $map Map.
	 * @param string $key attribute_… key.
	 * @return array value => image id
	 */
	public static function images( array $map, string $key ): array {
		$col = array_search( $key, $map['a'], true );
		if ( false === $col ) {
			return array();
		}
		$n   = count( $map['a'] );
		$out = array();
		foreach ( $map['v'] as $row ) {
			$val = (string) $row[ $col ];
			$img = (int) $row[ $n + 3 ];
			if ( '' !== $val && $img > 0 && ! isset( $out[ $val ] ) ) {
				$out[ $val ] = $img;
			}
		}
		return $out;
	}

	/**
	 * The values of one attribute that have at least one variation, in map
	 * order, with an "in stock" flag.
	 *
	 * @param array  $map Map.
	 * @param string $key attribute_… key.
	 * @return array value => bool in stock ('' rows mean "any": every value)
	 */
	public static function values( array $map, string $key ): array {
		$col = array_search( $key, $map['a'], true );
		if ( false === $col ) {
			return array();
		}
		$n   = count( $map['a'] );
		$out = array();
		$any = false;
		foreach ( $map['v'] as $row ) {
			$val = (string) $row[ $col ];
			$in  = (bool) $row[ $n ];
			if ( '' === $val ) {
				$any = $any || $in;
				continue;
			}
			$out[ $val ] = ( isset( $out[ $val ] ) && $out[ $val ] ) || $in;
		}
		if ( $any ) {
			foreach ( $out as $k => $in ) {
				$out[ $k ] = true;
			}
			$out[''] = true;
		}
		return $out;
	}
}

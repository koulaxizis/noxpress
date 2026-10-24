<?php
/**
 * HMS_Stats — the [hs_stats] shortcode: store counters (available
 * products, completed orders, buyers, and available products per group of
 * categories) that follow the store as it changes.
 *
 *   [hs_stats show="products,orders,buyers" heading="…" class="…"]
 *   [hs_stats show="groups" size="small"]
 *
 * show takes the store keys, "groups" (every category group, in the order
 * of the settings) and single group keys, in any order. size="small" adds
 * the class hms-stats--small (a smaller row, e.g. under the store row).
 *
 * Counts:
 *  - products  published products without a password, not hidden from
 *              the catalog, in stock or on backorder (variations count
 *              once, through their parent)
 *  - orders    orders with the status completed (wc_get_orders, HPOS safe)
 *  - buyers    distinct customers among those orders: the customer ID for
 *              accounts, the billing e-mail (lowercase) for guests
 *  - groups    per category group of the settings (1.3.0): available
 *              products (as above) in any of its categories or their
 *              subcategories, each product once per group
 *
 * Storage (Bible §14.10, §14.23): the counts are derived data, so they are
 * computed in the background and kept in ONE option, hms_stats_data
 * (autoload off): {products, orders, buyers, groups: {key: n}, time}. A
 * rebuild is queued with the Action Scheduler of WooCommerce (WP-Cron
 * without it), 60 s later and once, whenever an order enters or leaves the
 * status completed, a product is published / unpublished / trashed, its
 * stock status or its categories change, a product category is created,
 * edited or deleted, or the groups are saved; bulk edits give one rebuild. A recurring action every
 * 12 hours catches what no hook reports (e.g. an order deleted from the
 * database). The page view and the REST route only read.
 *
 * Output: the numbers are printed in the HTML (no JavaScript needed). A
 * small script (assets/stats.js), enqueued only where the shortcode
 * rendered, asks the REST route for the current numbers, so a page that a
 * page cache keeps for hours still shows the latest ones, and counts up
 * from 0 when the counters come into view (not with prefers-reduced-motion).
 *
 * Display: each counter has a prefix, a label and a rounding step (store
 * settings, Bible §14.17). With a step, the number shown is the largest
 * multiple of the step below the count ("Over 600" for 605); a count not
 * above the step is shown as it is. Groups are always exact, with a label
 * and no prefix.
 *
 * Deviations from the Bible (with reason):
 *  - §14.4 (never in REST): GET /home-sections/v1/stats is the purpose of
 *    the live counters. It is public, reads the stored option only, takes
 *    no input, returns only the numbers the page already shows, writes
 *    nothing and sends nocache_headers() (as in §14.26).
 *  - §14.5 (no write on a visitor request): an order or a stock change
 *    made at checkout queues the rebuild (one Action Scheduler row). The
 *    order itself is written by WooCommerce in the same request anyway.
 *  - §1.1 / HPOS: orders are read only through wc_get_orders().
 */

defined( 'ABSPATH' ) || exit;

final class HMS_Stats {

	const TAG    = 'hs_stats';
	const OPT    = 'hms_stats_data';
	const HOOK   = 'hms_stats_rebuild';
	const DAILY  = 'hms_stats_refresh';
	const GROUP  = 'home-sections';
	const NS     = 'home-sections/v1';
	const ROUTE  = '/stats';
	const DELAY  = 60;
	const PERIOD = 43200; // 12 h.

	/** Prefix of a group in the markup and the REST response (cat-printed). */
	const GPRE = 'cat-';

	/** Orders read per page while counting buyers. */
	const PAGE = 200;

	/** True after an error: the rest of the request runs without us (Bible §14.6). */
	private static $off = false;

	public static function init(): void {
		// Registered even with HMS_DISABLE, so the shortcode never shows
		// as text (Bible §14); it then prints nothing.
		add_shortcode( self::TAG, array( __CLASS__, 'shortcode' ) );

		add_action( self::HOOK, array( __CLASS__, 'rebuild' ) );
		add_action( self::DAILY, array( __CLASS__, 'rebuild' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure' ) );

		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status' ), 10, 3 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'queue' ) );
		add_action( 'woocommerce_product_set_visibility', array( __CLASS__, 'queue' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'on_terms' ), 10, 6 );
		foreach ( array( 'created_product_cat', 'edited_product_cat', 'delete_product_cat' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'queue' ) );
		}

		if ( ! Home_Sections::killed() ) {
			add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'head_style' ) );
		}
	}

	/** The counter keys, in their default order. */
	public static function keys(): array {
		return array( 'products', 'orders', 'buyers' );
	}

	/** The rounding steps a counter may use (0 = exact). */
	public static function steps(): array {
		return array( 0, 10, 50, 100, 1000 );
	}

	/* =====================================================================
	 * Counting (background only)
	 * =================================================================== */

	/**
	 * Available products: published, visible, in stock or on backorder;
	 * with $cats, only those in one of these categories or below them.
	 *
	 * @param int[] $cats product_cat term ids (none = every product).
	 */
	public static function count_products( array $cats = array() ): int {
		$tax = array(
			array(
				'taxonomy' => 'product_visibility',
				'field'    => 'name',
				'terms'    => array( 'exclude-from-catalog' ),
				'operator' => 'NOT IN',
			),
		);
		if ( $cats ) {
			$tax['relation'] = 'AND';
			$tax[]           = array(
				'taxonomy'         => 'product_cat',
				'field'            => 'term_id',
				'terms'            => array_map( 'intval', $cats ),
				'include_children' => true,
				'operator'         => 'IN',
			);
		}
		$q = new WP_Query(
			array(
				'post_type'           => 'product',
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => 1,
				'fields'              => 'ids',
				'ignore_sticky_posts' => true,
				'suppress_filters'    => true,
				'meta_query'          => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- background count, as WooCommerce stores it.
					array(
						'key'     => '_stock_status',
						'value'   => array( 'instock', 'onbackorder' ),
						'compare' => 'IN',
					),
				),
				'tax_query'           => $tax, // phpcs:ignore WordPress.DB.SlowDBQuery -- catalog visibility (as WooCommerce does) and the group's categories.
			)
		);
		return (int) $q->found_posts;
	}

	/** Completed orders, and the distinct customers among them. */
	public static function count_orders(): array {
		$orders = 0;
		$seen   = array();
		$page   = 1;
		do {
			$res = wc_get_orders(
				array(
					'status'   => array( 'wc-completed' ),
					'type'     => 'shop_order',
					'limit'    => self::PAGE,
					'page'     => $page,
					'paginate' => true,
					'orderby'  => 'ID',
					'order'    => 'ASC',
				)
			);
			if ( 1 === $page ) {
				$orders = (int) $res->total;
			}
			foreach ( $res->orders as $o ) {
				if ( ! $o instanceof WC_Order ) {
					continue;
				}
				$cid   = (int) $o->get_customer_id();
				$email = strtolower( trim( (string) $o->get_billing_email() ) );
				if ( $cid > 0 ) {
					$seen[ 'u' . $cid ] = true;
				} elseif ( '' !== $email ) {
					$seen[ 'e' . md5( $email ) ] = true;
				} else {
					$seen[ 'o' . $o->get_id() ] = true;
				}
			}
			++$page;
		} while ( $page <= (int) $res->max_num_pages );

		return array( $orders, count( $seen ) );
	}

	/** Counts everything and stores it. Background, admin or CLI only. */
	public static function rebuild(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		list( $orders, $buyers ) = self::count_orders();
		$groups                  = array();
		foreach ( HMS_Settings::get()['stats']['groups'] as $g ) {
			$groups[ $g['key'] ] = self::count_products( $g['cats'] );
		}
		$data = array(
			'products' => self::count_products(),
			'orders'   => $orders,
			'buyers'   => $buyers,
			'groups'   => $groups,
			'time'     => time(),
		);
		update_option( self::OPT, $data, false );
	}

	/** The stored counts, or null before the first rebuild. */
	public static function data(): ?array {
		$raw = get_option( self::OPT, null );
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$out = array();
		foreach ( array_merge( self::keys(), array( 'time' ) ) as $k ) {
			$out[ $k ] = isset( $raw[ $k ] ) && is_numeric( $raw[ $k ] ) ? max( 0, (int) $raw[ $k ] ) : 0;
		}
		// Only the groups of the settings, in their order; a group saved
		// after the last count is missing until the next one.
		$out['groups'] = array();
		$stored        = isset( $raw['groups'] ) && is_array( $raw['groups'] ) ? $raw['groups'] : array();
		foreach ( HMS_Settings::get()['stats']['groups'] as $g ) {
			if ( isset( $stored[ $g['key'] ] ) && is_numeric( $stored[ $g['key'] ] ) ) {
				$out['groups'][ $g['key'] ] = max( 0, (int) $stored[ $g['key'] ] );
			}
		}
		return $out;
	}

	/* =====================================================================
	 * Scheduling
	 * =================================================================== */

	/** Queues one rebuild, DELAY seconds from now, unless one is waiting. */
	public static function queue(): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
				as_schedule_single_action( time() + self::DELAY, self::HOOK, array(), self::GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + self::DELAY, self::HOOK );
		}
	}

	/**
	 * admin_init: the recurring refresh exists, and a first rebuild is
	 * queued when nothing is stored yet (after install or update).
	 */
	public static function ensure(): void {
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::DAILY, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + self::PERIOD, self::PERIOD, self::DAILY, array(), self::GROUP );
			}
		} elseif ( ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( time() + self::PERIOD, 'twicedaily', self::DAILY );
		}
		if ( null === self::data() ) {
			self::queue();
		}
	}

	/** woocommerce_order_status_changed: only moves into or out of completed. */
	public static function on_order_status( $order_id, $from, $to ): void {
		if ( 'completed' === $from || 'completed' === $to ) {
			self::queue();
		}
	}

	/** set_object_terms: the categories of a published product change (drafts count nowhere). */
	public static function on_terms( $object_id, $terms, $tt_ids, $taxonomy, $append = false, $old_tt_ids = array() ): void {
		if ( 'product_cat' !== $taxonomy || 'product' !== get_post_type( (int) $object_id ) || 'publish' !== get_post_status( (int) $object_id ) ) {
			return;
		}
		$new = array_map( 'intval', (array) $tt_ids );
		$old = array_map( 'intval', (array) $old_tt_ids );
		sort( $new );
		sort( $old );
		if ( $new !== $old ) {
			self::queue();
		}
	}

	/** transition_post_status: a product enters or leaves "publish". */
	public static function on_transition( $new_status, $old_status, $post ): void {
		if ( $post instanceof WP_Post && 'product' === $post->post_type && $new_status !== $old_status && ( 'publish' === $new_status || 'publish' === $old_status ) ) {
			self::queue();
		}
	}

	/* =====================================================================
	 * Display values
	 * =================================================================== */

	/** The number shown for a count and a step. */
	public static function shown( int $n, int $step ): int {
		if ( $step > 0 && $n > $step ) {
			return (int) ( floor( ( $n - 1 ) / $step ) * $step );
		}
		return $n;
	}

	/** The numbers shown, per key, or null before the first rebuild. */
	public static function values(): ?array {
		$d = self::data();
		if ( null === $d ) {
			return null;
		}
		$items = HMS_Settings::get()['stats']['items'];
		$out   = array();
		foreach ( self::keys() as $k ) {
			$out[ $k ] = self::shown( $d[ $k ], (int) $items[ $k ]['step'] );
		}
		foreach ( $d['groups'] as $k => $n ) {
			$out[ self::GPRE . $k ] = $n;
		}
		return $out;
	}

	/* =====================================================================
	 * REST (read only)
	 * =================================================================== */

	public static function register_route(): void {
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest' ),
				'permission_callback' => '__return_true',
				'args'                => array(),
			)
		);
	}

	public static function rest() {
		nocache_headers();
		try {
			$v = self::values();
		} catch ( \Throwable $e ) {
			$v = null;
		}
		if ( null === $v ) {
			return new WP_Error( 'hms_stats_empty', 'No counts yet.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( $v );
	}

	/* =====================================================================
	 * Attributes
	 * =================================================================== */

	/**
	 * Normalised attributes.
	 *
	 * @param mixed $atts Raw shortcode attributes.
	 * show holds the keys of the markup: store keys as they are, groups
	 * with GPRE (cat-printed).
	 *
	 * @return array{show:string[],heading:string,class:string,size:string}
	 */
	public static function parse_atts( $atts ): array {
		$atts   = is_array( $atts ) ? $atts : array();
		$show   = array();
		$groups = array();
		foreach ( HMS_Settings::get()['stats']['groups'] as $g ) {
			$groups[] = $g['key'];
		}
		$raw = isset( $atts['show'] ) && is_scalar( $atts['show'] ) ? strtolower( (string) $atts['show'] ) : '';
		foreach ( preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $k ) {
			if ( in_array( $k, self::keys(), true ) ) {
				$show[ $k ] = $k;
			} elseif ( 'groups' === $k ) {
				foreach ( $groups as $g ) {
					$show[ self::GPRE . $g ] = self::GPRE . $g;
				}
			} elseif ( in_array( $k, $groups, true ) ) {
				$show[ self::GPRE . $k ] = self::GPRE . $k;
			}
		}
		$heading = isset( $atts['heading'] ) && is_scalar( $atts['heading'] ) ? sanitize_text_field( (string) $atts['heading'] ) : '';
		$class   = isset( $atts['class'] ) && is_scalar( $atts['class'] ) ? (string) $atts['class'] : '';
		$class   = implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', $class, -1, PREG_SPLIT_NO_EMPTY ) ) ) );
		$size = isset( $atts['size'] ) && is_scalar( $atts['size'] ) && 'small' === strtolower( trim( (string) $atts['size'] ) ) ? 'small' : '';
		return array(
			'show'    => $show ? array_values( $show ) : self::keys(),
			'heading' => $heading,
			'class'   => $class,
			'size'    => $size,
		);
	}

	/* =====================================================================
	 * Output
	 * =================================================================== */

	/** The shortcode callback. */
	public static function shortcode( $atts ): string {
		if ( self::$off || Home_Sections::killed() ) {
			return '';
		}
		try {
			$v = self::values();
			if ( null === $v ) {
				return '';
			}
			$html = self::html( self::parse_atts( $atts ), $v );
			if ( '' !== $html ) {
				self::enqueue();
			}
			return $html;
		} catch ( \Throwable $e ) {
			self::$off = true;
			return '';
		}
	}

	/** The markup for a set of values. */
	public static function html( array $a, array $v ): string {
		$items = '';
		foreach ( $a['show'] as $k ) {
			if ( ! isset( $v[ $k ] ) ) {
				continue;
			}
			$n      = (int) $v[ $k ];
			$prefix = self::text( $k, 'prefix' );
			$label  = self::text( $k, 'label' );
			$items .= '<li class="hms-stats__item hms-stats__item--' . esc_attr( $k ) . '">'
				. '<span class="hms-stats__num">'
				. ( '' !== $prefix ? '<span class="hms-stats__pre">' . esc_html( $prefix ) . '</span> ' : '' )
				. '<span class="hms-stats__val" data-hms-key="' . esc_attr( $k ) . '" data-hms-n="' . esc_attr( (string) $n ) . '">' . esc_html( number_format_i18n( $n ) ) . '</span>'
				. '</span>'
				. ( '' !== $label ? '<span class="hms-stats__label">' . esc_html( $label ) . '</span>' : '' )
				. '</li>';
		}
		if ( '' === $items ) {
			return '';
		}
		$size = isset( $a['size'] ) && 'small' === $a['size'] ? ' hms-stats--small' : '';
		$out  = '<section class="hms-stats' . $size . ( '' !== $a['class'] ? ' ' . esc_attr( $a['class'] ) : '' ) . '"'
			. ( '' !== $a['heading'] ? '' : ' aria-label="' . esc_attr( self::text( 'section', 'label' ) ) . '"' ) . '>';
		if ( '' !== $a['heading'] ) {
			$out .= '<h2 class="hms-stats__heading">' . esc_html( $a['heading'] ) . '</h2>';
		}
		return $out . '<ul class="hms-stats__list">' . $items . '</ul></section>';
	}

	/** A visitor text in the store's language (Bible §14.17). */
	public static function text( string $key, string $part ): string {
		$s    = HMS_Settings::get()['stats'];
		$lang = 'auto' === $s['lang'] ? HMS_Lang::from_locale( (string) get_locale() ) : $s['lang'];
		if ( 'section' === $key ) {
			$row = $s['section'];
		} elseif ( 0 === strpos( $key, self::GPRE ) ) {
			$row = array(
				'el' => '',
				'en' => '',
			);
			if ( 'label' === $part ) {
				foreach ( $s['groups'] as $g ) {
					if ( self::GPRE . $g['key'] === $key ) {
						$row = $g['label'];
						break;
					}
				}
			}
		} else {
			$row = isset( $s['items'][ $key ][ $part ] ) ? $s['items'][ $key ][ $part ] : array(
				'el' => '',
				'en' => '',
			);
		}
		$text = 'en' === $lang && '' !== $row['en'] ? $row['en'] : $row['el'];
		$text = apply_filters( 'hms_stats_text', $text, $key, $part, $lang );
		return is_string( $text ) ? $text : '';
	}

	/* =====================================================================
	 * Assets
	 * =================================================================== */

	/** wp_enqueue_scripts: the stylesheet in <head> when the page holds the shortcode. */
	public static function head_style(): void {
		$post = get_queried_object();
		if ( is_singular() && $post instanceof WP_Post && has_shortcode( (string) $post->post_content, self::TAG ) ) {
			self::style();
		}
	}

	private static function style(): void {
		if ( apply_filters( 'hms_stats_style', true ) && ! wp_style_is( 'hms-stats', 'enqueued' ) ) {
			wp_enqueue_style( 'hms-stats', HMS_URL . 'assets/stats.css', array(), HMS_VERSION );
		}
	}

	/** The live script, once, in the footer (and the stylesheet, if the head missed it). */
	private static function enqueue(): void {
		self::style();
		if ( ! apply_filters( 'hms_stats_script', true ) || wp_script_is( 'hms-stats', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_script( 'hms-stats', HMS_URL . 'assets/stats.js', array(), HMS_VERSION, true );
		wp_add_inline_script(
			'hms-stats',
			'window.HMS_STATS_CFG=' . wp_json_encode(
				array(
					'url'    => esc_url_raw( rest_url( self::NS . self::ROUTE ) ),
					'locale' => str_replace( '_', '-', (string) get_locale() ),
				)
			) . ';',
			'before'
		);
	}
}

<?php
/**
 * TP_Categories — product category images and category lists drawn by
 * the theme.
 *
 *  - Image fallback: a category without its own image shows the image of
 *    one of its products (most recent, or best selling). The map
 *    term id => attachment id is stored in the option `tp_cat_images`
 *    and is computed only in the admin: on the "rebuild" action, when a
 *    product is saved and when a category is created or edited — never
 *    on a page view. On the front end the map is read through the
 *    `get_term_metadata` filter for `thumbnail_id`; categories with an
 *    image of their own are never touched, and nothing is written to the
 *    category itself.
 *  - Lists: a theme file that draws category tiles with get_terms() can
 *    get its own choice of categories, in its own order, through
 *    `get_terms_args` — only for calls made from that file.
 *  - image_map(): full image URL => [ attachment id, category name ], used
 *    by TP_Page to swap full-size category images for a smaller size and
 *    fill empty alt texts.
 */

defined( 'ABSPATH' ) || exit;

final class TP_Categories {

	const OPT = 'tp_cat_images';
	const TAX = 'product_cat';

	/** Fallback active on this request. */
	private static $fallback = false;

	/** Lists of the active theme (front end). */
	private static $lists = array();

	/** Re-entry guard for the meta filter. */
	private static $reading = false;

	/** Counters for the page probe. */
	private static $stats = array(
		'fallback' => 0,
		'lists'    => 0,
	);

	public static function init(): void {
		add_action( 'wp', array( __CLASS__, 'boot' ), 1 );

		// Map upkeep (admin side, any request that saves products or categories).
		add_action( 'save_post_product', array( __CLASS__, 'on_product_saved' ), 20, 2 );
		add_action( 'created_' . self::TAX, array( __CLASS__, 'on_term_saved' ), 20 );
		add_action( 'edited_' . self::TAX, array( __CLASS__, 'on_term_saved' ), 20 );
		add_action( 'delete_' . self::TAX, array( __CLASS__, 'on_term_deleted' ), 20 );
	}

	public static function boot(): void {
		try {
			if ( ! TP_Runtime::request_ok() || ! TP_Runtime::applies() ) {
				return;
			}
			$cats = TP_Settings::theme()['cats'];
			if ( $cats['fallback'] ) {
				self::$fallback = true;
				add_filter( 'get_term_metadata', array( __CLASS__, 'term_meta' ), 10, 4 );
			}
			if ( $cats['lists'] ) {
				self::$lists = $cats['lists'];
				add_filter( 'get_terms_args', array( __CLASS__, 'terms_args' ), 999, 2 );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/* =====================================================================
	 * Image fallback
	 * =================================================================== */

	/** Stored map: term id => attachment id. */
	public static function map(): array {
		$map = get_option( self::OPT, array() );
		return is_array( $map ) ? array_map( 'intval', $map ) : array();
	}

	/**
	 * @param mixed  $value     null, or a short-circuit value.
	 * @param int    $object_id Term id.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Single value requested.
	 */
	public static function term_meta( $value, $object_id, $meta_key, $single ) {
		if ( null !== $value || 'thumbnail_id' !== $meta_key || self::$reading ) {
			return $value;
		}
		try {
			$map = self::map();
			$id  = (int) $object_id;
			if ( empty( $map[ $id ] ) ) {
				return $value;
			}
			self::$reading = true;
			$own           = (int) get_metadata_raw( 'term', $id, 'thumbnail_id', true );
			self::$reading = false;
			if ( $own > 0 ) {
				return $value;
			}
			++self::$stats['fallback'];
			return array( (string) $map[ $id ] );
		} catch ( \Throwable $e ) {
			self::$reading = false;
			return $value;
		}
	}

	/** Recompute the whole map (admin action). Returns the number of categories with a fallback. */
	public static function rebuild(): int {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAX,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		$map = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $tid ) {
				$att = self::pick( (int) $tid );
				if ( $att > 0 ) {
					$map[ (int) $tid ] = $att;
				}
			}
		}
		update_option( self::OPT, $map, false );
		return count( $map );
	}

	/** Image of the chosen product of a category (children included), 0 when none. */
	public static function pick( int $term_id ): int {
		$source = TP_Settings::theme()['cats']['source'];
		$args   = array(
			'post_type'        => 'product',
			'post_status'      => 'publish',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- admin only.
				array(
					'taxonomy'         => self::TAX,
					'terms'            => array( $term_id ),
					'include_children' => true,
				),
			),
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin only.
				array(
					'key'     => '_thumbnail_id',
					'compare' => 'EXISTS',
				),
			),
		);
		if ( 'popular' === $source ) {
			$args['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['orderby']  = array(
				'meta_value_num' => 'DESC',
				'date'           => 'DESC',
			);
		} else {
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
		}
		$ids = get_posts( $args );
		return $ids ? (int) get_post_thumbnail_id( (int) $ids[0] ) : 0;
	}

	private static function enabled_somewhere(): bool {
		$t = TP_Settings::theme();
		return $t['cats']['fallback'] || '' !== $t['page']['cat_img_size'] || $t['page']['img_alt'];
	}

	/** Update the categories of a saved product (and their parents). */
	public static function on_product_saved( $post_id, $post = null ): void {
		try {
			if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! self::enabled_somewhere() ) {
				return;
			}
			$terms = wp_get_post_terms( (int) $post_id, self::TAX, array( 'fields' => 'ids' ) );
			if ( ! is_array( $terms ) || ! $terms ) {
				return;
			}
			$all = array();
			foreach ( $terms as $tid ) {
				$all[] = (int) $tid;
				foreach ( get_ancestors( (int) $tid, self::TAX, 'taxonomy' ) as $a ) {
					$all[] = (int) $a;
				}
			}
			self::update_terms( array_unique( $all ) );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	public static function on_term_saved( $term_id ): void {
		try {
			if ( self::enabled_somewhere() ) {
				self::update_terms( array( (int) $term_id ) );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	public static function on_term_deleted( $term_id ): void {
		$map = self::map();
		if ( isset( $map[ (int) $term_id ] ) ) {
			unset( $map[ (int) $term_id ] );
			update_option( self::OPT, $map, false );
		}
	}

	private static function update_terms( array $ids ): void {
		$map = self::map();
		$old = $map;
		foreach ( $ids as $tid ) {
			$att = self::pick( (int) $tid );
			if ( $att > 0 ) {
				$map[ (int) $tid ] = $att;
			} else {
				unset( $map[ (int) $tid ] );
			}
		}
		if ( $map !== $old ) {
			update_option( self::OPT, $map, false );
		}
	}

	/** Categories with and without their own image (admin summary). */
	public static function summary(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAX,
				'hide_empty' => false,
			)
		);
		$map = self::map();
		$out = array(
			'total'    => 0,
			'own'      => 0,
			'fallback' => 0,
			'none'     => array(),
		);
		if ( ! is_array( $terms ) ) {
			return $out;
		}
		self::$reading = true;
		foreach ( $terms as $t ) {
			++$out['total'];
			if ( (int) get_term_meta( $t->term_id, 'thumbnail_id', true ) > 0 ) {
				++$out['own'];
			} elseif ( ! empty( $map[ $t->term_id ] ) ) {
				++$out['fallback'];
			} else {
				$out['none'][] = $t->name;
			}
		}
		self::$reading = false;
		return $out;
	}

	/* =====================================================================
	 * Category lists (get_terms from a chosen theme file)
	 * =================================================================== */

	/**
	 * @param array $args       get_terms() arguments.
	 * @param array $taxonomies Taxonomies.
	 */
	public static function terms_args( $args, $taxonomies ) {
		if ( ! is_array( $args ) || ! is_array( $taxonomies ) || array( self::TAX ) !== array_values( $taxonomies ) ) {
			return $args;
		}
		try {
			$file = self::caller_file();
			if ( '' === $file ) {
				return $args;
			}
			foreach ( self::$lists as $list ) {
				if ( $list['file'] !== $file ) {
					continue;
				}
				if ( $list['include'] ) {
					$args['include']      = $list['include'];
					$args['orderby']      = 'include';
					$args['order']        = 'ASC';
					$args['parent']       = '';
					$args['child_of']     = 0;
					$args['exclude']      = array();
					$args['exclude_tree'] = array();
					$args['number']       = 0;
				}
				if ( $list['hide_empty'] ) {
					$args['hide_empty'] = true;
				}
				++self::$stats['lists'];
				break;
			}
		} catch ( \Throwable $e ) {
			return $args;
		}
		return $args;
	}

	/** Theme-relative file that called get_terms(), '' when not a theme file. */
	private static function caller_file(): string {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- short, no args.
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 );
		foreach ( $trace as $frame ) {
			if ( isset( $frame['function'] ) && 'get_terms' === $frame['function'] && empty( $frame['class'] ) && ! empty( $frame['file'] ) ) {
				return TP_Areas::theme_relative( $frame['file'] );
			}
		}
		return '';
	}

	/** Theme files that call get_terms() for product categories (admin). */
	public static function list_files(): array {
		$out = array();
		foreach ( TP_Detector::theme_php_files() as $rel => $abs ) {
			$src = (string) file_get_contents( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
			if ( false !== strpos( $src, 'get_terms' ) && false !== strpos( $src, self::TAX ) ) {
				$out[] = $rel;
			}
		}
		return $out;
	}

	/* =====================================================================
	 * Image map for TP_Page
	 * =================================================================== */

	/** Full image URL => [ attachment id, category name ] of every category image (own or fallback). */
	public static function image_map(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAX,
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $terms ) ) {
			return $cache;
		}
		$pairs = array();
		foreach ( $terms as $t ) {
			$att = (int) get_term_meta( $t->term_id, 'thumbnail_id', true ); // Fallback included when on.
			if ( $att > 0 ) {
				$pairs[] = array( $att, $t->name );
			}
		}
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_column( $pairs, 0 ), false, true );
		}
		foreach ( $pairs as $p ) {
			$url = wp_get_attachment_url( $p[0] );
			if ( is_string( $url ) && '' !== $url && ! isset( $cache[ $url ] ) ) {
				$cache[ $url ] = $p;
			}
		}
		return $cache;
	}

	public static function stats(): array {
		return self::$stats;
	}
}

<?php
/**
 * PFM_Works — works, formats and the member map.
 *
 * Data model:
 *  - Hidden taxonomy pfm_work on products: one term = one work (no UI, no
 *    REST, no rewrite, no query var). A product belongs to one work at most.
 *  - Product meta _pfm_format (a key of the format registry) and
 *    _pfm_variant (optional subtitle, e.g. "Those days were Real mix").
 *  - Term meta pfm_members: the precomputed member list of a work
 *    (id, format, variant, status, visible), JSON. It is rebuilt in save
 *    hooks and admin actions (Bible §14.10); page views only read it.
 *
 * Rebuilds are queued and run once per request (on shutdown, or at once
 * via flush()), so a bulk edit of 50 products rebuilds each work once.
 * After a rebuild the pages of every member are purged from the page
 * cache (core object cache, WooCommerce transients, WP Rocket, LiteSpeed),
 * because each page shows the prices of its siblings.
 */

defined( 'ABSPATH' ) || exit;

final class PFM_Works {

	const TAX          = 'pfm_work';
	const META_FORMAT  = '_pfm_format';
	const META_VARIANT = '_pfm_variant';
	const TERM_MEMBERS = 'pfm_members';
	const MAX_VARIANT  = 80;

	/** Work term ids waiting for a rebuild (term_id => true). */
	private static $queue = array();

	/** Works of products being deleted (post_id => term_id). */
	private static $deleting = array();

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );

		add_action( 'save_post_product', array( __CLASS__, 'on_product_change' ), 99 );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_product_change' ), 99 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_change' ), 99 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'on_variation_change' ), 99 );
		add_action( 'trashed_post', array( __CLASS__, 'on_product_change' ), 99 );
		add_action( 'untrashed_post', array( __CLASS__, 'on_product_change' ), 99 );
		add_action( 'before_delete_post', array( __CLASS__, 'before_delete' ), 10 );
		add_action( 'deleted_post', array( __CLASS__, 'after_delete' ), 10 );
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 1 );
	}

	public static function register(): void {
		register_taxonomy(
			self::TAX,
			array( 'product' ),
			array(
				'labels'             => array( 'name' => 'Product Formats works' ),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'show_tagcloud'      => false,
				'show_admin_column'  => false,
				'hierarchical'       => false,
				'rewrite'            => false,
				'query_var'          => false,
			)
		);
	}

	/* =====================================================================
	 * Reading
	 * =================================================================== */

	/** The work (term id) of a product, or 0. Uses the object term cache. */
	public static function work_of( int $product_id ): int {
		if ( $product_id <= 0 ) {
			return 0;
		}
		$terms = get_the_terms( $product_id, self::TAX );
		if ( ! is_array( $terms ) || ! $terms ) {
			return 0;
		}
		$ids = array_map(
			static function ( $t ) {
				return (int) $t->term_id;
			},
			$terms
		);
		sort( $ids );
		return (int) $ids[0];
	}

	public static function work( int $term_id ): ?WP_Term {
		$t = $term_id > 0 ? get_term( $term_id, self::TAX ) : null;
		return $t instanceof WP_Term ? $t : null;
	}

	/** Format key of a product ('' when not set). */
	public static function format_of( int $product_id ): string {
		return sanitize_key( (string) get_post_meta( $product_id, self::META_FORMAT, true ) );
	}

	public static function variant_of( int $product_id ): string {
		return (string) get_post_meta( $product_id, self::META_VARIANT, true );
	}

	/**
	 * Members of a work, sorted by the format registry, then variant, then
	 * id. Reads the stored map; when it is missing (e.g. right after an
	 * import), it is computed in memory and stored only outside visitor
	 * requests (Bible §14.5).
	 *
	 * @return array[] Each: id, format, variant, status, visible.
	 */
	public static function members( int $term_id ): array {
		if ( $term_id <= 0 ) {
			return array();
		}
		$raw  = get_term_meta( $term_id, self::TERM_MEMBERS, true );
		$list = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $list ) ) {
			$list = self::compute( $term_id );
			if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				update_term_meta( $term_id, self::TERM_MEMBERS, wp_json_encode( $list ) );
			}
		}
		$out = array();
		foreach ( $list as $m ) {
			if ( is_array( $m ) && isset( $m['id'] ) ) {
				$out[] = array(
					'id'      => (int) $m['id'],
					'format'  => sanitize_key( (string) ( $m['format'] ?? '' ) ),
					'variant' => (string) ( $m['variant'] ?? '' ),
					'status'  => sanitize_key( (string) ( $m['status'] ?? '' ) ),
					'visible' => ! empty( $m['visible'] ),
				);
			}
		}
		return self::sort( $out );
	}

	/** Members a visitor may see: published, not hidden from the catalog, no password. */
	public static function visible_members( int $term_id ): array {
		return array_values(
			array_filter(
				self::members( $term_id ),
				static function ( $m ) {
					return 'publish' === $m['status'] && $m['visible'];
				}
			)
		);
	}

	public static function sort( array $members ): array {
		usort(
			$members,
			static function ( $a, $b ) {
				$ra = PFM_Settings::format_rank( $a['format'] );
				$rb = PFM_Settings::format_rank( $b['format'] );
				if ( $ra !== $rb ) {
					return $ra <=> $rb;
				}
				$v = strcmp( $a['variant'], $b['variant'] );
				return 0 !== $v ? $v : ( $a['id'] <=> $b['id'] );
			}
		);
		return $members;
	}

	/** The member list from the database (no cache). */
	private static function compute( int $term_id ): array {
		$ids = get_objects_in_term( $term_id, self::TAX );
		if ( ! is_array( $ids ) ) {
			return array();
		}
		$out = array();
		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || 'product' !== $post->post_type || 'trash' === $post->post_status ) {
				continue;
			}
			$visible = '' === (string) $post->post_password;
			if ( $visible ) {
				$product = wc_get_product( $id );
				$visible = $product && 'hidden' !== $product->get_catalog_visibility();
			}
			$out[] = array(
				'id'      => $id,
				'format'  => self::format_of( $id ),
				'variant' => self::variant_of( $id ),
				'status'  => (string) $post->post_status,
				'visible' => $visible,
			);
		}
		return $out;
	}

	/**
	 * Public API (pfm_get_work): the work of a product with its members,
	 * filtered by `pfm_formats`. Null when the product is in no work.
	 */
	public static function public_work( int $product_id ): ?array {
		$term_id = self::work_of( $product_id );
		$term    = self::work( $term_id );
		if ( null === $term ) {
			return null;
		}
		$work = array(
			'id'      => $term_id,
			'name'    => $term->name,
			'members' => self::members( $term_id ),
		);
		/**
		 * Filters a work as other plugins read it (e.g. a future Books plugin
		 * building schema.org workExample entries).
		 *
		 * @param array $work       { id, name, members[] }.
		 * @param int   $product_id The product asked for.
		 */
		$work = apply_filters( 'pfm_formats', $work, $product_id );
		return is_array( $work ) ? $work : null;
	}

	/* =====================================================================
	 * Writing (admin, product tab, suggestions)
	 * =================================================================== */

	/**
	 * Creates a work. A name that already exists gets " (2)", " (3)"…,
	 * because two different works may share a title.
	 *
	 * @return int Term id, 0 on failure.
	 */
	public static function create( string $name ): int {
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			return 0;
		}
		$name = PFM_Settings::clip( $name, 190 );
		$try  = $name;
		for ( $i = 2; $i < 50; $i++ ) {
			$r = wp_insert_term( $try, self::TAX );
			if ( is_array( $r ) && isset( $r['term_id'] ) ) {
				return (int) $r['term_id'];
			}
			$try = $name . ' (' . $i . ')';
		}
		return 0;
	}

	/** Existing work by exact name (case insensitive), or 0. */
	public static function find_by_name( string $name ): int {
		$name = trim( $name );
		if ( '' === $name ) {
			return 0;
		}
		$t = get_term_by( 'name', $name, self::TAX );
		return $t instanceof WP_Term ? (int) $t->term_id : 0;
	}

	public static function rename( int $term_id, string $name ): bool {
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name || null === self::work( $term_id ) ) {
			return false;
		}
		$r = wp_update_term( $term_id, self::TAX, array( 'name' => PFM_Settings::clip( $name, 190 ) ) );
		return is_array( $r );
	}

	/**
	 * Puts a product in a work (moving it out of any other work) with a
	 * format and a variant. Both works are queued for a rebuild.
	 */
	public static function assign( int $product_id, int $term_id, string $format, string $variant ): bool {
		$post = get_post( $product_id );
		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type || null === self::work( $term_id ) ) {
			return false;
		}
		$old = self::work_of( $product_id );
		$r   = wp_set_object_terms( $product_id, array( $term_id ), self::TAX, false );
		if ( is_wp_error( $r ) ) {
			return false;
		}
		self::set_format( $product_id, $format, $variant );
		self::queue( $term_id );
		if ( $old && $old !== $term_id ) {
			self::queue( $old );
		}
		return true;
	}

	/** Format and variant only (the product stays in its work). */
	public static function set_format( int $product_id, string $format, string $variant ): void {
		$format = sanitize_key( $format );
		if ( '' !== $format && null !== PFM_Settings::format( $format ) ) {
			update_post_meta( $product_id, self::META_FORMAT, $format );
		} else {
			delete_post_meta( $product_id, self::META_FORMAT );
		}
		$variant = PFM_Settings::clip( trim( sanitize_text_field( $variant ) ), self::MAX_VARIANT );
		if ( '' !== $variant ) {
			update_post_meta( $product_id, self::META_VARIANT, $variant );
		} else {
			delete_post_meta( $product_id, self::META_VARIANT );
		}
		$work = self::work_of( $product_id );
		if ( $work ) {
			self::queue( $work );
		}
	}

	/** Takes a product out of its work. A work left empty is deleted. */
	public static function unassign( int $product_id ): void {
		$old = self::work_of( $product_id );
		wp_delete_object_term_relationships( $product_id, self::TAX );
		delete_post_meta( $product_id, self::META_FORMAT );
		delete_post_meta( $product_id, self::META_VARIANT );
		clean_object_term_cache( $product_id, 'product' );
		self::purge( array( $product_id ) );
		if ( $old ) {
			self::queue( $old );
		}
	}

	/** Deletes a work (never the products) and the format meta of its members. */
	public static function delete( int $term_id ): void {
		$ids = get_objects_in_term( $term_id, self::TAX );
		$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		foreach ( $ids as $id ) {
			delete_post_meta( $id, self::META_FORMAT );
			delete_post_meta( $id, self::META_VARIANT );
		}
		wp_delete_term( $term_id, self::TAX );
		foreach ( $ids as $id ) {
			clean_object_term_cache( $id, 'product' );
		}
		unset( self::$queue[ $term_id ] );
		self::purge( $ids );
	}

	/** Moves every member of $from into $to, then deletes $from. */
	public static function merge( int $from, int $to ): int {
		if ( $from === $to || null === self::work( $from ) || null === self::work( $to ) ) {
			return 0;
		}
		$n = 0;
		foreach ( self::members( $from ) as $m ) {
			if ( self::assign( $m['id'], $to, $m['format'], $m['variant'] ) ) {
				++$n;
			}
		}
		self::flush();
		if ( null !== self::work( $from ) && ! self::members( $from ) ) {
			wp_delete_term( $from, self::TAX );
		}
		return $n;
	}

	/* =====================================================================
	 * Rebuild queue and hooks
	 * =================================================================== */

	public static function queue( int $term_id ): void {
		if ( $term_id > 0 ) {
			self::$queue[ $term_id ] = true;
		}
	}

	/** Rebuilds the queued works now (also runs on shutdown). */
	public static function flush(): void {
		while ( self::$queue ) {
			$ids         = array_keys( self::$queue );
			self::$queue = array();
			foreach ( $ids as $term_id ) {
				self::rebuild( (int) $term_id );
			}
		}
	}

	/** Recomputes and stores one member map; deletes a work left empty. */
	public static function rebuild( int $term_id ): void {
		try {
			if ( null === self::work( $term_id ) ) {
				return;
			}
			$list = self::compute( $term_id );
			if ( ! $list && ! get_objects_in_term( $term_id, self::TAX ) ) {
				wp_delete_term( $term_id, self::TAX );
				return;
			}
			update_term_meta( $term_id, self::TERM_MEMBERS, wp_json_encode( $list ) );
			self::purge(
				array_map(
					static function ( $m ) {
						return (int) $m['id'];
					},
					$list
				)
			);
			/**
			 * Fires after the member map of a work was rebuilt.
			 *
			 * @param int   $term_id Work term id.
			 * @param array $list    Members.
			 */
			do_action( 'pfm_work_rebuilt', $term_id, $list );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** Page caches of products whose "Available formats" block changed. */
	public static function purge( array $ids ): void {
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( $id <= 0 ) {
				continue;
			}
			clean_post_cache( $id );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $id );
			}
			if ( function_exists( 'rocket_clean_post' ) ) {
				rocket_clean_post( $id );
			}
			do_action( 'litespeed_purge_post', $id );
		}
	}

	/** save_post_product, woocommerce_*_product, (un)trashed_post. */
	public static function on_product_change( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		$work = self::work_of( $post_id );
		if ( $work ) {
			self::queue( $work );
		}
	}

	/** A variation changed: its parent's price may show on sibling pages. */
	public static function on_variation_change( $variation_id ): void {
		$parent = (int) wp_get_post_parent_id( (int) $variation_id );
		if ( $parent ) {
			self::on_product_change( $parent );
		}
	}

	public static function before_delete( $post_id ): void {
		$post_id = (int) $post_id;
		if ( 'product' === get_post_type( $post_id ) ) {
			$work = self::work_of( $post_id );
			if ( $work ) {
				self::$deleting[ $post_id ] = $work;
			}
		}
	}

	public static function after_delete( $post_id ): void {
		$post_id = (int) $post_id;
		if ( isset( self::$deleting[ $post_id ] ) ) {
			self::queue( self::$deleting[ $post_id ] );
			unset( self::$deleting[ $post_id ] );
		}
	}

	/* =====================================================================
	 * Admin listing helpers
	 * =================================================================== */

	/**
	 * Works for the admin table.
	 *
	 * @return array{0: WP_Term[], 1: int} Terms of the page and the total.
	 */
	public static function page( string $search, int $page, int $per_page ): array {
		$args = array(
			'taxonomy'   => self::TAX,
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);
		if ( '' !== $search ) {
			$args['name__like'] = $search;
		}
		$total = (int) wp_count_terms( $args );
		$terms = get_terms(
			$args + array(
				'number' => $per_page,
				'offset' => max( 0, ( $page - 1 ) * $per_page ),
			)
		);
		return array( is_array( $terms ) ? $terms : array(), $total );
	}

	public static function count(): int {
		$n = wp_count_terms(
			array(
				'taxonomy'   => self::TAX,
				'hide_empty' => false,
			)
		);
		return is_numeric( $n ) ? (int) $n : 0;
	}
}

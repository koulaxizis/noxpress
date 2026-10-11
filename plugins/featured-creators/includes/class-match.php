<?php
/**
 * CRT_Match — which attribute terms belong to which creator.
 *
 * Rules (design §4.2):
 *  - The role taxonomies are the global attributes chosen in the settings.
 *  - Automatic: a creator owns every term of a role taxonomy whose slug is
 *    the creator's key (_crt_key, default: the post slug).
 *  - Manual: extra terms (pseudonyms) are owned too; excluded terms
 *    (homonyms) are not.
 *  - One owner per term. A manual claim beats automatic ones. Two manual
 *    claims, or two automatic ones without a manual one, are a conflict:
 *    nobody owns the term until it is resolved (option crt_conflicts).
 *
 * Everything is computed when a creator, a role term, a product's role
 * terms or the settings change (Bible §14.10) and stored:
 *  - term meta crt_creator = owner id, on the terms
 *  - _crt_terms / _crt_roles / _crt_count on the creators
 * A page view only reads. Creator pages read their products with one
 * query, cached in the object cache with the posts/terms "last changed".
 *
 * When what visitors see changes, the affected products' caches are
 * cleaned (clean_post_cache, which page-cache plugins listen to) and
 * crt_creator_changed( $creator_id, $product_ids ) fires.
 */

defined( 'ABSPATH' ) || exit;

final class CRT_Match {

	const TERM_META = 'crt_creator';
	const OPT_CONF  = 'crt_conflicts';
	const GROUP     = 'featured-creators';

	/** Work queued for shutdown. */
	private static $dirty_all   = false;
	private static $dirty_stats = false;
	private static $purge       = array(); // creator id => true.

	/** Per-request cache of product_creators(). */
	private static $pc = array();

	public static function init(): void {
		add_action( 'save_post_' . CRT_CPT::PT, array( __CLASS__, 'on_creator_save' ), 20, 1 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'on_deleted' ), 10, 2 );
		add_action( 'created_term', array( __CLASS__, 'on_term' ), 10, 3 );
		add_action( 'edited_term', array( __CLASS__, 'on_term' ), 10, 3 );
		add_action( 'delete_term', array( __CLASS__, 'on_term' ), 10, 3 );
		add_action( 'set_object_terms', array( __CLASS__, 'on_object_terms' ), 10, 6 );
		add_action( 'shutdown', array( __CLASS__, 'flush' ) );
	}

	/* =====================================================================
	 * Change detection (admin, REST, CLI — never on a visitor's page view)
	 * =================================================================== */

	public static function on_creator_save( $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::$dirty_all             = true;
		self::$purge[ (int) $post_id ] = true;
	}

	/** Status changes of creators (publish ↔ other) and products. */
	public static function on_transition( $new, $old, $post ): void {
		if ( ! $post instanceof WP_Post || $new === $old ) {
			return;
		}
		if ( CRT_CPT::PT === $post->post_type ) {
			self::$dirty_all              = true;
			self::$purge[ (int) $post->ID ] = true;
		} elseif ( 'product' === $post->post_type && ( 'publish' === $new || 'publish' === $old ) ) {
			self::$dirty_stats = true;
		}
	}

	public static function on_deleted( $post_id, $post = null ): void {
		$type = $post instanceof WP_Post ? $post->post_type : get_post_type( $post_id );
		if ( CRT_CPT::PT === $type ) {
			self::$dirty_all = true;
		} elseif ( 'product' === $type ) {
			self::$dirty_stats = true;
		}
	}

	public static function on_term( $term_id, $tt_id, $taxonomy ): void {
		if ( in_array( $taxonomy, CRT_Settings::role_taxes(), true ) ) {
			self::$dirty_all = true;
		}
	}

	public static function on_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append = false, $old_tt_ids = array() ): void {
		// Role terms, and catalog visibility (hidden products are not counted).
		if ( 'product_visibility' !== $taxonomy && ! in_array( $taxonomy, CRT_Settings::role_taxes(), true ) ) {
			return;
		}
		if ( 'product' !== get_post_type( $object_id ) ) {
			return;
		}
		$new = array_map( 'intval', (array) $tt_ids );
		$old = array_map( 'intval', (array) $old_tt_ids );
		sort( $new );
		sort( $old );
		if ( $new !== $old ) {
			self::$dirty_stats = true;
		}
	}

	/** Runs the queued work once, at the end of the request. */
	public static function flush(): void {
		try {
			if ( self::$dirty_all ) {
				self::recompute();
			} elseif ( self::$dirty_stats ) {
				self::refresh_stats();
			}
			foreach ( array_keys( self::$purge ) as $cid ) {
				self::purge_creator( (int) $cid );
			}
		} catch ( \Throwable $e ) {
			// Never break the request that saved something; the next save retries.
			unset( $e );
		}
		self::$dirty_all   = false;
		self::$dirty_stats = false;
		self::$purge       = array();
	}

	/* =====================================================================
	 * Recompute
	 * =================================================================== */

	/** Every term of the role taxonomies: tt_id => WP_Term. */
	public static function role_terms(): array {
		$taxes = CRT_Settings::role_taxes();
		if ( ! $taxes ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'               => $taxes,
				'hide_empty'             => false,
				'update_term_meta_cache' => true,
			)
		);
		$out = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( $t instanceof WP_Term ) {
					$out[ (int) $t->term_taxonomy_id ] = $t;
				}
			}
		}
		return $out;
	}

	/** Creators that can own terms (everything but trash and auto-drafts). */
	public static function creator_ids(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => CRT_CPT::PT,
					'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			)
		);
	}

	/**
	 * Who claims what: tt_id => { manual: int[], auto: int[] }.
	 *
	 * @param WP_Term[] $terms tt_id => term.
	 */
	public static function claims( array $terms, array $creators ): array {
		$by_slug = array();
		foreach ( $terms as $tt => $t ) {
			$by_slug[ urldecode( (string) $t->slug ) ][] = $tt;
		}
		$claims = array();
		foreach ( $creators as $cid ) {
			$exclude = array_flip( CRT_CPT::ids_meta( $cid, CRT_CPT::M_EXCLUDE ) );
			$key     = CRT_CPT::key( $cid );
			if ( '' !== $key && isset( $by_slug[ $key ] ) ) {
				foreach ( $by_slug[ $key ] as $tt ) {
					if ( ! isset( $exclude[ $tt ] ) ) {
						$claims[ $tt ]['auto'][] = $cid;
					}
				}
			}
			foreach ( CRT_CPT::ids_meta( $cid, CRT_CPT::M_EXTRA ) as $tt ) {
				if ( isset( $terms[ $tt ] ) ) {
					$claims[ $tt ]['manual'][] = $cid;
				}
			}
		}
		return $claims;
	}

	/**
	 * Owners and conflicts from the claims.
	 *
	 * @return array{0: array<int,int>, 1: array<int,int[]>} [ tt => owner ], [ tt => creators ].
	 */
	public static function resolve( array $claims ): array {
		$owner     = array();
		$conflicts = array();
		foreach ( $claims as $tt => $c ) {
			$manual = isset( $c['manual'] ) ? array_values( array_unique( $c['manual'] ) ) : array();
			$auto   = isset( $c['auto'] ) ? array_values( array_unique( $c['auto'] ) ) : array();
			if ( 1 === count( $manual ) ) {
				$owner[ $tt ] = $manual[0];
			} elseif ( count( $manual ) > 1 ) {
				$conflicts[ $tt ] = $manual;
			} elseif ( 1 === count( $auto ) ) {
				$owner[ $tt ] = $auto[0];
			} elseif ( count( $auto ) > 1 ) {
				$conflicts[ $tt ] = $auto;
			}
		}
		return array( $owner, $conflicts );
	}

	/** Full recompute: owners, term meta, creator terms, stats, conflicts. */
	public static function recompute(): void {
		$terms    = self::role_terms();
		$creators = self::creator_ids();
		list( $owner, $conflicts ) = self::resolve( self::claims( $terms, $creators ) );

		$changed_tts = array();

		// Term meta on the role terms.
		foreach ( $terms as $tt => $t ) {
			$old = (int) get_term_meta( (int) $t->term_id, self::TERM_META, true );
			$new = isset( $owner[ $tt ] ) ? (int) $owner[ $tt ] : 0;
			if ( $old === $new ) {
				continue;
			}
			if ( $new ) {
				update_term_meta( (int) $t->term_id, self::TERM_META, $new );
			} else {
				delete_term_meta( (int) $t->term_id, self::TERM_META );
			}
			$changed_tts[] = (int) $tt;
		}

		// Terms outside the role taxonomies (a role was removed).
		global $wpdb;
		$role_term_ids = array_map(
			static function ( $t ) {
				return (int) $t->term_id;
			},
			$terms
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- small admin-only lookup.
		$stale = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s", self::TERM_META ) );
		foreach ( array_map( 'intval', (array) $stale ) as $term_id ) {
			if ( ! in_array( $term_id, $role_term_ids, true ) ) {
				delete_term_meta( $term_id, self::TERM_META );
				$t = get_term( $term_id );
				if ( $t instanceof WP_Term ) {
					$changed_tts[] = (int) $t->term_taxonomy_id;
				}
			}
		}

		// The creators' own lists.
		$owned = array();
		foreach ( $owner as $tt => $cid ) {
			$owned[ $cid ][] = (int) $tt;
		}
		foreach ( $creators as $cid ) {
			$list = isset( $owned[ $cid ] ) ? $owned[ $cid ] : array();
			sort( $list );
			if ( CRT_CPT::ids_meta( $cid, CRT_CPT::M_TERMS ) !== $list ) {
				CRT_CPT::set_json_meta( $cid, CRT_CPT::M_TERMS, $list );
			}
		}

		if ( $conflicts ) {
			update_option( self::OPT_CONF, $conflicts, false );
		} else {
			delete_option( self::OPT_CONF );
		}

		self::refresh_stats( $creators );

		if ( $changed_tts ) {
			self::purge_tts( $changed_tts, 0 );
		}
		self::$pc = array();
	}

	/**
	 * Roles with published products and the product count of every creator,
	 * with one query.
	 *
	 * @param int[]|null $creators null = all.
	 */
	public static function refresh_stats( ?array $creators = null ): void {
		global $wpdb;
		$creators = null === $creators ? self::creator_ids() : $creators;
		$terms    = self::role_terms();
		$tt_owner = array();
		foreach ( $creators as $cid ) {
			foreach ( CRT_CPT::ids_meta( $cid, CRT_CPT::M_TERMS ) as $tt ) {
				if ( isset( $terms[ $tt ] ) ) {
					$tt_owner[ $tt ] = $cid;
				}
			}
		}

		$rows = array();
		if ( $tt_owner ) {
			$in = implode( ',', array_map( 'intval', array_keys( $tt_owner ) ) );
			// Products hidden from the catalog are not on the creator page, so not counted.
			$hidden = get_term_by( 'slug', 'exclude-from-catalog', 'product_visibility' );
			$not    = $hidden instanceof WP_Term ? ' AND NOT EXISTS ( SELECT 1 FROM ' . $wpdb->term_relationships . ' h WHERE h.object_id = p.ID AND h.term_taxonomy_id = ' . (int) $hidden->term_taxonomy_id . ' )' : '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only; admin-only.
			$rows = $wpdb->get_results( "SELECT tr.term_taxonomy_id AS tt, tr.object_id AS pid FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.post_type = 'product' AND p.post_status = 'publish' AND tr.term_taxonomy_id IN ($in)$not" );
		}

		$roles    = array();
		$products = array();
		foreach ( (array) $rows as $r ) {
			$tt                               = (int) $r->tt;
			$cid                              = $tt_owner[ $tt ];
			$roles[ $cid ][ $terms[ $tt ]->taxonomy ] = true;
			$products[ $cid ][ (int) $r->pid ] = true;
		}

		$order = CRT_Settings::role_taxes();
		foreach ( $creators as $cid ) {
			$list = array();
			foreach ( $order as $tax ) {
				if ( isset( $roles[ $cid ][ $tax ] ) ) {
					$list[] = $tax;
				}
			}
			if ( CRT_CPT::json_meta( $cid, CRT_CPT::M_ROLES ) !== $list ) {
				CRT_CPT::set_json_meta( $cid, CRT_CPT::M_ROLES, $list );
			}
			$count = isset( $products[ $cid ] ) ? count( $products[ $cid ] ) : 0;
			if ( (int) get_post_meta( $cid, CRT_CPT::M_COUNT, true ) !== $count || '' === get_post_meta( $cid, CRT_CPT::M_COUNT, true ) ) {
				update_post_meta( $cid, CRT_CPT::M_COUNT, $count );
			}
		}
	}

	/* =====================================================================
	 * Cache purge (design §5.5)
	 * =================================================================== */

	/** Products that carry any of these terms. */
	public static function products_of_tts( array $tts ): array {
		global $wpdb;
		$tts = array_filter( array_map( 'intval', $tts ) );
		if ( ! $tts ) {
			return array();
		}
		$in = implode( ',', $tts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only; admin-only.
		$ids = $wpdb->get_col( "SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.post_type = 'product' AND tr.term_taxonomy_id IN ($in)" );
		return array_map( 'intval', (array) $ids );
	}

	private static function purge_tts( array $tts, int $creator_id ): void {
		$ids = self::products_of_tts( $tts );
		foreach ( $ids as $pid ) {
			clean_post_cache( $pid );
		}
		/**
		 * What visitors see about a creator changed (status, name, photo,
		 * the terms it owns). Cache plugins may purge these products.
		 *
		 * @param int   $creator_id  0 when several creators changed at once.
		 * @param int[] $product_ids Products whose pages show the creator.
		 */
		do_action( 'crt_creator_changed', $creator_id, $ids );
	}

	/** Purges the products of one creator (its current terms). */
	public static function purge_creator( int $cid ): void {
		$tts = CRT_CPT::ids_meta( $cid, CRT_CPT::M_TERMS );
		if ( $tts ) {
			self::purge_tts( $tts, $cid );
		}
		clean_post_cache( $cid );
	}

	/* =====================================================================
	 * Reading (front end)
	 * =================================================================== */

	/** The owner of a term (0 = none). Term meta is primed by get_terms. */
	public static function owner( int $term_id ): int {
		return (int) get_term_meta( $term_id, self::TERM_META, true );
	}

	/**
	 * The public creators of a product: [ { id, name, url, roles[] } ], in
	 * the order of the roles.
	 */
	public static function product_creators( int $product_id ): array {
		if ( 'product_variation' === get_post_type( $product_id ) ) {
			$product_id = (int) wp_get_post_parent_id( $product_id );
		}
		if ( isset( self::$pc[ $product_id ] ) ) {
			return self::$pc[ $product_id ];
		}
		$out   = array();
		$taxes = CRT_Settings::role_taxes();
		if ( $taxes && $product_id > 0 ) {
			foreach ( $taxes as $tax ) {
				$terms = get_the_terms( $product_id, $tax );
				if ( ! is_array( $terms ) ) {
					continue;
				}
				foreach ( $terms as $t ) {
					$cid = self::owner( (int) $t->term_id );
					if ( ! CRT_CPT::is_public( $cid ) ) {
						continue;
					}
					if ( ! isset( $out[ $cid ] ) ) {
						$out[ $cid ] = array(
							'id'    => $cid,
							'name'  => get_the_title( $cid ),
							'url'   => (string) get_permalink( $cid ),
							'roles' => array(),
						);
					}
					if ( ! in_array( $tax, $out[ $cid ]['roles'], true ) ) {
						$out[ $cid ]['roles'][] = $tax;
					}
				}
			}
		}
		self::$pc[ $product_id ] = array_values( $out );
		return self::$pc[ $product_id ];
	}

	/** Term ids of product_cat that mark bundles (children included). */
	private static function bundle_cat_ids(): array {
		$ids = array();
		foreach ( CRT_Settings::get()['bundle_cats'] as $id ) {
			$ids[] = (int) $id;
			$kids  = get_term_children( (int) $id, 'product_cat' );
			if ( is_array( $kids ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $kids ) );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * The products on a creator's page.
	 *
	 * @return array { roles: tax => int[], bundles: int[], formats: pid => int }
	 *               formats = number of visible formats of the work a card
	 *               stands for (Product Formats), only when > 1.
	 */
	public static function creator_products( int $cid ): array {
		$empty = array(
			'roles'   => array(),
			'bundles' => array(),
			'formats' => array(),
		);
		$tts   = CRT_CPT::ids_meta( $cid, CRT_CPT::M_TERMS );
		$taxes = CRT_Settings::role_taxes();
		if ( ! $tts || ! $taxes ) {
			return $empty;
		}

		$s   = CRT_Settings::get();
		$key = 'p' . $cid . ':' . md5( wp_json_encode( array( $tts, $taxes, $s['bundle_cats'], $s['bundle_types'], $s['formats'], get_option( 'woocommerce_hide_out_of_stock_items' ) ) ) ) . ':' . wp_cache_get_last_changed( 'posts' ) . ':' . wp_cache_get_last_changed( 'terms' );
		$hit = wp_cache_get( $key, self::GROUP );
		if ( is_array( $hit ) ) {
			return $hit;
		}

		$by_tax = array();
		foreach ( $tts as $tt ) {
			$t = get_term_by( 'term_taxonomy_id', $tt );
			if ( $t instanceof WP_Term && in_array( $t->taxonomy, $taxes, true ) ) {
				$by_tax[ $t->taxonomy ][] = (int) $tt;
			}
		}
		if ( ! $by_tax ) {
			return $empty;
		}

		$or = array( 'relation' => 'OR' );
		foreach ( $by_tax as $tax => $list ) {
			$or[] = array(
				'taxonomy' => $tax,
				'field'    => 'term_taxonomy_id',
				'terms'    => $list,
				'operator' => 'IN',
			);
		}
		$hidden = array( 'exclude-from-catalog' );
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
			$hidden[] = 'outofstock';
		}
		$q   = new WP_Query(
			array(
				'post_type'           => 'product',
				'post_status'         => 'publish',
				'posts_per_page'      => 500,
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'suppress_filters'    => true,
				'orderby'             => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					'relation' => 'AND',
					$or,
					array(
						'taxonomy' => 'product_visibility',
						'field'    => 'slug',
						'terms'    => $hidden,
						'operator' => 'NOT IN',
					),
				),
			)
		);
		$ids = array_map( 'intval', $q->posts );
		if ( ! $ids ) {
			wp_cache_set( $key, $empty, self::GROUP, HOUR_IN_SECONDS );
			return $empty;
		}

		// Which of the creator's terms each product carries.
		$owned  = array_flip( $tts );
		$p_tax  = array();
		$rels   = wp_get_object_terms( $ids, array_keys( $by_tax ), array( 'fields' => 'all_with_object_id' ) );
		if ( is_array( $rels ) ) {
			foreach ( $rels as $t ) {
				if ( isset( $owned[ (int) $t->term_taxonomy_id ] ) ) {
					$p_tax[ (int) $t->object_id ][ $t->taxonomy ] = true;
				}
			}
		}

		// Bundles: by category (children included) or product type.
		$bundle    = array();
		$cat_ids   = self::bundle_cat_ids();
		$types     = $s['bundle_types'];
		if ( $cat_ids ) {
			$cats = wp_get_object_terms( $ids, 'product_cat', array( 'fields' => 'all_with_object_id' ) );
			if ( is_array( $cats ) ) {
				foreach ( $cats as $t ) {
					if ( in_array( (int) $t->term_id, $cat_ids, true ) ) {
						$bundle[ (int) $t->object_id ] = true;
					}
				}
			}
		}
		if ( $types ) {
			foreach ( $ids as $pid ) {
				if ( ! isset( $bundle[ $pid ] ) && in_array( WC_Product_Factory::get_product_type( $pid ), $types, true ) ) {
					$bundle[ $pid ] = true;
				}
			}
		}

		$out = $empty;
		foreach ( $ids as $pid ) {
			if ( isset( $bundle[ $pid ] ) ) {
				$out['bundles'][] = $pid;
				continue;
			}
			foreach ( $taxes as $tax ) {
				if ( isset( $p_tax[ $pid ][ $tax ] ) ) {
					$out['roles'][ $tax ][] = $pid;
				}
			}
		}

		// Product Formats: one card per work in every group, at the place of
		// its newest member, showing the first format (Product Formats' order)
		// the group has.
		if ( $s['formats'] && function_exists( 'pfm_get_work' ) ) {
			$collapse = static function ( array $list ) use ( &$out ): array {
				$in   = array_flip( $list );
				$seen = array();
				$keep = array();
				foreach ( $list as $pid ) {
					$work = pfm_get_work( $pid );
					if ( ! is_array( $work ) || empty( $work['id'] ) || empty( $work['members'] ) ) {
						$keep[] = $pid;
						continue;
					}
					$wid = (int) $work['id'];
					if ( isset( $seen[ $wid ] ) ) {
						continue;
					}
					$seen[ $wid ] = true;
					$show         = $pid;
					$n            = 0;
					foreach ( (array) $work['members'] as $m ) {
						if ( ! is_array( $m ) || empty( $m['visible'] ) || ( isset( $m['status'] ) && 'publish' !== $m['status'] ) ) {
							continue;
						}
						if ( 0 === $n++ ) {
							$show = -1; // Look for the first listed member below.
						}
						if ( -1 === $show && isset( $m['id'], $in[ (int) $m['id'] ] ) ) {
							$show = (int) $m['id'];
						}
					}
					if ( -1 === $show ) {
						$show = $pid;
					}
					$keep[] = $show;
					if ( $n > 1 ) {
						$out['formats'][ $show ] = $n;
					}
				}
				return $keep;
			};
			foreach ( $out['roles'] as $tax => $list ) {
				$out['roles'][ $tax ] = $collapse( $list );
			}
			$out['bundles'] = $collapse( $out['bundles'] );
		}

		wp_cache_set( $key, $out, self::GROUP, HOUR_IN_SECONDS );
		return $out;
	}

	/* =====================================================================
	 * Admin views
	 * =================================================================== */

	/**
	 * All people found in the role terms, one per slug:
	 * slug => { name, taxes[], tts[], products, owner }.
	 */
	public static function persons(): array {
		global $wpdb;
		$terms = self::role_terms();
		$out   = array();
		foreach ( $terms as $tt => $t ) {
			$slug = urldecode( (string) $t->slug );
			if ( ! isset( $out[ $slug ] ) ) {
				$out[ $slug ] = array(
					'name'     => $t->name,
					'taxes'    => array(),
					'tts'      => array(),
					'products' => 0,
					'owner'    => 0,
				);
			}
			$out[ $slug ]['taxes'][] = $t->taxonomy;
			$out[ $slug ]['tts'][]   = (int) $tt;
			$own                     = self::owner( (int) $t->term_id );
			if ( $own ) {
				$out[ $slug ]['owner'] = $own;
			}
		}
		if ( $terms ) {
			$in = implode( ',', array_map( 'intval', array_keys( $terms ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only; admin-only.
			$rows  = $wpdb->get_results( "SELECT tr.term_taxonomy_id AS tt, tr.object_id AS pid FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.post_type = 'product' AND p.post_status = 'publish' AND tr.term_taxonomy_id IN ($in)" );
			$count = array();
			foreach ( (array) $rows as $r ) {
				$slug                                 = urldecode( (string) $terms[ (int) $r->tt ]->slug );
				$count[ $slug ][ (int) $r->pid ] = true;
			}
			foreach ( $count as $slug => $p ) {
				$out[ $slug ]['products'] = count( $p );
			}
		}
		uasort(
			$out,
			static function ( $a, $b ) {
				return strcmp( CRT_Settings::fold( $a['name'] ), CRT_Settings::fold( $b['name'] ) );
			}
		);
		return $out;
	}

	/** Conflicts: tt_id => creator ids. */
	public static function conflicts(): array {
		$c = get_option( self::OPT_CONF, array() );
		return is_array( $c ) ? $c : array();
	}

	/** Published products count per term: tt_id => n. */
	public static function term_counts( array $tts ): array {
		global $wpdb;
		$tts = array_filter( array_map( 'intval', $tts ) );
		if ( ! $tts ) {
			return array();
		}
		$in = implode( ',', $tts );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only; admin-only.
		$rows = $wpdb->get_results( "SELECT tr.term_taxonomy_id AS tt, COUNT(DISTINCT tr.object_id) AS n FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.post_type = 'product' AND p.post_status = 'publish' AND tr.term_taxonomy_id IN ($in) GROUP BY tr.term_taxonomy_id" );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->tt ] = (int) $r->n;
		}
		return $out;
	}
}

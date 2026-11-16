<?php
/**
 * LVS_Index — the search index: table {prefix}lvs_index, one row per
 * product that a visitor may find (published, no password, not «hidden
 * from search» in WooCommerce, not out of stock when the store hides those).
 *
 * Columns (all text normalised with LVS_Text::norm()):
 *  product_id  the product
 *  title       the name
 *  people      the terms of the «people» attributes (authors…)
 *  terms       categories, tags, brands, other searched attributes
 *  codes       ISBN / GTIN / SKU of the product and its variations,
 *              space separated, with the other ISBN form
 *  phon        phonetic key of title + people (greeklish)
 *  extra       short description (results page only)
 *  cats        product category ids, «,12,34,» (Hide Catalog at file time)
 *  card        JSON of what a suggestion shows (name, URL, image, byline,
 *              guest prices, pre-order, format)
 *  work        Product Formats work id (0 = none)
 *  oos         1 = out of stock
 *  sales       total_sales (ties)
 *  updated     unix time of the row
 *
 * When rows are written (never on a plain page view, Bible §14.5):
 *  - save hooks of products and terms, in whatever request WooCommerce or
 *    WordPress saves them: the ids go to the lvs_queue option at shutdown
 *    (once per request) and a single cron event «lvs_flush» builds them a
 *    minute later, together with the public file;
 *  - hourly «lvs_hourly»: products modified since the last run (catch-up
 *    for imports that bypass the hooks) and old index files;
 *  - nightly «lvs_full»: every product again, in batches of 200 through
 *    single cron events, then rows of products that are gone are removed;
 *  - the «Rebuild» button of the admin page (AJAX, batches of 200).
 * Prices are computed as a guest (the current user is set to 0 for the
 * batch), so a shop manager or a trade account never leaks a price.
 */

defined( 'ABSPATH' ) || exit;

final class LVS_Index {

	const DB_VER   = '1';
	const OPT_DB   = 'lvs_db';
	const OPT_Q    = 'lvs_queue';
	const OPT_ST   = 'lvs_state';
	const OPT_VER  = 'lvs_ver';
	const BATCH    = 200;
	const Q_MAX    = 5000;

	/** @var int[] Ids changed in this request (written at shutdown). */
	private static $pending = array();

	/** @var bool */
	private static $hooked = false;

	/** @var int|null */
	private static $visible = null;

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'lvs_flush', array( __CLASS__, 'flush' ) );
		add_action( 'lvs_hourly', array( __CLASS__, 'hourly' ) );
		add_action( 'lvs_daily', array( __CLASS__, 'daily' ) );
		add_action( 'lvs_full', array( __CLASS__, 'full_step' ), 10, 2 );

		// Product changes.
		add_action( 'save_post_product', array( __CLASS__, 'on_post' ), 20, 1 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_post' ), 20, 1 );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_post' ), 20, 1 );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'on_post' ), 20, 1 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'on_variation' ), 20, 1 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'on_variation' ), 20, 1 );
		add_action( 'trashed_post', array( __CLASS__, 'on_post' ), 20, 1 );
		add_action( 'untrashed_post', array( __CLASS__, 'on_post' ), 20, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_post' ), 20, 1 );
		add_action( 'set_object_terms', array( __CLASS__, 'on_terms' ), 20, 1 );
		add_action( 'edited_term', array( __CLASS__, 'on_term_edit' ), 20, 3 );
		add_action( 'noxpress_products_changed', array( __CLASS__, 'on_many' ), 20, 1 );
		// Featured Creators: names or pages changed (only the file changes).
		add_action( 'crt_creator_changed', array( __CLASS__, 'on_creator' ), 20, 0 );
		// Scheduled sales start or end: every price may change.
		add_action( 'woocommerce_scheduled_sales', array( __CLASS__, 'start_full' ), 99, 0 );
	}

	/* =====================================================================
	 * Table
	 * =================================================================== */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lvs_index';
	}

	public static function maybe_install(): void {
		if ( self::DB_VER !== get_option( self::OPT_DB ) ) {
			self::install();
		}
		if ( ! wp_next_scheduled( 'lvs_hourly' ) ) {
			self::schedule();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$t} (
			product_id bigint(20) unsigned NOT NULL,
			title text NOT NULL,
			people text NOT NULL,
			terms text NOT NULL,
			codes text NOT NULL,
			phon text NOT NULL,
			extra text NOT NULL,
			cats text NOT NULL,
			card longtext NOT NULL,
			work bigint(20) unsigned NOT NULL DEFAULT 0,
			oos tinyint(1) NOT NULL DEFAULT 0,
			sales bigint(20) NOT NULL DEFAULT 0,
			updated int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (product_id)
			) {$charset};"
		);
		update_option( self::OPT_DB, self::DB_VER, false );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( 'lvs_hourly' ) ) {
			wp_schedule_event( time() + 600, 'hourly', 'lvs_hourly' );
		}
		if ( ! wp_next_scheduled( 'lvs_daily' ) ) {
			// 03:40 site time, after most backups.
			$at = strtotime( 'tomorrow 03:40', current_time( 'timestamp' ) ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			wp_schedule_event( $at ? $at : time() + DAY_IN_SECONDS, 'daily', 'lvs_daily' );
		}
	}

	public static function exists(): bool {
		global $wpdb;
		$t = self::table();
		return $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function state(): array {
		$s = get_option( self::OPT_ST, array() );
		return is_array( $s ) ? $s : array();
	}

	private static function set_state( array $patch ): void {
		update_option( self::OPT_ST, array_merge( self::state(), $patch ), false );
	}

	/** Changes on every write, for cache keys. */
	public static function version(): int {
		return (int) get_option( self::OPT_VER, 0 );
	}

	private static function bump(): void {
		update_option( self::OPT_VER, self::version() + 1, false );
		self::$visible = null;
	}

	/** Rows in the index (cached per request). */
	public static function count_visible(): int {
		if ( null === self::$visible ) {
			global $wpdb;
			$t             = self::table();
			$n             = self::exists() ? $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ) : 0; // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::$visible = (int) $n;
		}
		return self::$visible;
	}

	/* =====================================================================
	 * Change hooks → queue
	 * =================================================================== */

	public static function on_post( $id ): void {
		$id = (int) $id;
		if ( $id <= 0 ) {
			return;
		}
		$type = get_post_type( $id );
		if ( 'product' === $type ) {
			self::mark( array( $id ) );
		} elseif ( 'product_variation' === $type ) {
			self::mark( array( (int) wp_get_post_parent_id( $id ) ) );
		}
	}

	public static function on_variation( $id ): void {
		$parent = (int) wp_get_post_parent_id( (int) $id );
		if ( $parent > 0 ) {
			self::mark( array( $parent ) );
		}
	}

	public static function on_terms( $object_id ): void {
		if ( 'product' === get_post_type( (int) $object_id ) ) {
			self::mark( array( (int) $object_id ) );
		}
	}

	/** A term was renamed: its products change (up to Q_MAX, else a full pass). */
	public static function on_term_edit( $term_id, $tt_id, $taxonomy ): void {
		if ( ! in_array( (string) $taxonomy, self::taxonomies(), true ) ) {
			return;
		}
		$ids = get_objects_in_term( (int) $term_id, (string) $taxonomy );
		if ( is_wp_error( $ids ) ) {
			return;
		}
		if ( count( $ids ) > self::Q_MAX ) {
			self::start_full();
			return;
		}
		self::mark( array_map( 'intval', $ids ) );
	}

	public static function on_many( $ids ): void {
		self::mark( array_map( 'intval', (array) $ids ) );
	}

	public static function on_creator(): void {
		self::later();
	}

	/** Taxonomies whose terms are in the index. */
	public static function taxonomies(): array {
		$s    = LVS_Settings::get();
		$list = array_merge( $s['people'], $s['attrs'] );
		if ( $s['cats'] ) {
			$list[] = 'product_cat';
		}
		if ( $s['tags'] ) {
			$list[] = 'product_tag';
		}
		if ( $s['brands'] ) {
			$list = array_merge( $list, self::brand_taxes() );
		}
		$list[] = 'product_visibility';
		return array_values( array_unique( $list ) );
	}

	/** Brand taxonomies that exist (WooCommerce 9.6+ and common plugins). */
	public static function brand_taxes(): array {
		$out = array();
		foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand' ) as $t ) {
			if ( taxonomy_exists( $t ) ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	private static function mark( array $ids ): void {
		foreach ( $ids as $id ) {
			if ( $id > 0 ) {
				self::$pending[ $id ] = true;
			}
		}
		if ( self::$pending && ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'write_pending' ), 5 );
		}
	}

	/** Shutdown: one option write and one cron event for the whole request. */
	public static function write_pending(): void {
		if ( ! self::$pending ) {
			return;
		}
		try {
			$q = get_option( self::OPT_Q, array() );
			$q = is_array( $q ) ? $q : array();
			foreach ( array_keys( self::$pending ) as $id ) {
				$q[ (int) $id ] = 1;
			}
			self::$pending = array();
			if ( count( $q ) > self::Q_MAX ) {
				delete_option( self::OPT_Q );
				self::start_full();
				return;
			}
			update_option( self::OPT_Q, $q, false );
			self::later();
		} catch ( \Throwable $e ) {
			self::$pending = array();
		}
	}

	private static function later(): void {
		if ( ! wp_next_scheduled( 'lvs_flush' ) ) {
			wp_schedule_single_event( time() + 60, 'lvs_flush' );
		}
	}

	/* =====================================================================
	 * Cron
	 * =================================================================== */

	/** The queue: up to 500 products, then the file. */
	public static function flush(): void {
		$q = get_option( self::OPT_Q, array() );
		$q = is_array( $q ) ? array_map( 'intval', array_keys( $q ) ) : array();
		$now   = array_slice( $q, 0, 500 );
		$rest  = array_slice( $q, 500 );
		if ( $rest ) {
			update_option( self::OPT_Q, array_fill_keys( $rest, 1 ), false );
			wp_schedule_single_event( time() + 30, 'lvs_flush' );
		} else {
			delete_option( self::OPT_Q );
		}
		if ( $now ) {
			self::build_many( $now );
		}
		LVS_File::maybe_build();
	}

	/** Catch-up for products changed outside the hooks, and old files. */
	public static function hourly(): void {
		global $wpdb;
		$st   = self::state();
		$last = isset( $st['hourly'] ) ? (int) $st['hourly'] : time() - HOUR_IN_SECONDS;
		$ids  = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_modified_gmt > %s LIMIT 2000",
				gmdate( 'Y-m-d H:i:s', $last - 120 )
			)
		);
		self::set_state( array( 'hourly' => time() ) );
		if ( empty( $st['built'] ) ) {
			// Never built in full (e.g. activated by a tool that skips the
			// activation hook, or the first pass was lost): start one now.
			self::start_full();
		}
		if ( $ids ) {
			self::build_many( array_map( 'intval', $ids ) );
			LVS_File::maybe_build();
		} elseif ( LVS_Settings::get()['mode'] !== 'off' && '' === LVS_File::current_url() && 'file' === LVS_Settings::engine() ) {
			LVS_File::maybe_build();
		}
		LVS_File::cleanup();
	}

	public static function daily(): void {
		LVS_Stats::cleanup();
		self::start_full();
	}

	/** Starts a full pass through single cron events. */
	public static function start_full(): void {
		$st = self::state();
		if ( ! empty( $st['full_run'] ) && (int) $st['full_run'] > time() - 2 * HOUR_IN_SECONDS ) {
			return; // One is running.
		}
		$start = time();
		self::set_state( array( 'full_run' => $start ) );
		wp_schedule_single_event( time() + 5, 'lvs_full', array( 0, $start ) );
	}

	public static function full_step( $offset, $start ): void {
		$r = self::batch( (int) $offset, (int) $start );
		if ( $r['done'] ) {
			self::set_state( array( 'full_run' => 0 ) );
			return;
		}
		wp_schedule_single_event( time() + 10, 'lvs_full', array( $r['next'], (int) $start ) );
	}

	/**
	 * One batch of a full pass (cron or the admin button).
	 *
	 * @return array{done: bool, next: int, total: int}
	 */
	public static function batch( int $offset, int $start ): array {
		global $wpdb;
		if ( ! self::exists() ) {
			self::install();
		}
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('trash','auto-draft')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids   = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('trash','auto-draft') ORDER BY ID ASC LIMIT %d OFFSET %d",
				self::BATCH,
				$offset
			)
		);
		if ( $ids ) {
			self::build_many( array_map( 'intval', $ids ) );
		}
		$next = $offset + self::BATCH;
		$done = ! $ids || $next >= $total;
		if ( $done ) {
			// Rows not touched since the pass started belong to products that are gone.
			$t = self::table();
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE updated < %d", $start ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::bump();
			self::set_state(
				array(
					'built' => time(),
					'count' => self::count_visible(),
				)
			);
			LVS_File::maybe_build( true );
		}
		return array(
			'done'  => $done,
			'next'  => $next,
			'total' => $total,
		);
	}

	/* =====================================================================
	 * Rows
	 * =================================================================== */

	/** Builds or removes the rows of these products, as a guest. */
	public static function build_many( array $ids ): void {
		if ( ! self::exists() ) {
			self::install();
		}
		$user = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			foreach ( array_unique( $ids ) as $id ) {
				try {
					self::build_one( (int) $id );
				} catch ( \Throwable $e ) {
					continue;
				}
			}
		} finally {
			wp_set_current_user( $user );
		}
		self::bump();
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'product_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** True when a visitor may find this product. */
	public static function findable( $p ): bool {
		if ( ! $p instanceof WC_Product || $p->is_type( 'variation' ) ) {
			return false;
		}
		if ( 'publish' !== $p->get_status() || '' !== (string) $p->get_post_password() ) {
			return false;
		}
		if ( in_array( $p->get_catalog_visibility(), array( 'hidden', 'catalog' ), true ) ) {
			return false; // «Shop only» and «Hidden» are not in search results.
		}
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && ! $p->is_in_stock() ) {
			return false;
		}
		return (bool) apply_filters( 'lvs_findable', true, $p );
	}

	public static function build_one( int $id ): void {
		global $wpdb;
		$p = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		if ( ! self::findable( $p ) ) {
			self::delete( $id );
			return;
		}
		$row = self::row( $p );
		$wpdb->replace( self::table(), $row, array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** The row of one findable product. */
	public static function row( WC_Product $p ): array {
		$s  = LVS_Settings::get();
		$id = $p->get_id();

		$name   = wp_strip_all_tags( $p->get_name() );
		$people = array();
		foreach ( $s['people'] as $tax ) {
			$people = array_merge( $people, self::term_names( $id, $tax ) );
		}
		$people = array_values( array_unique( $people ) );

		$terms = array();
		if ( $s['cats'] ) {
			$terms = array_merge( $terms, self::term_names( $id, 'product_cat' ) );
		}
		if ( $s['tags'] ) {
			$terms = array_merge( $terms, self::term_names( $id, 'product_tag' ) );
		}
		if ( $s['brands'] ) {
			foreach ( self::brand_taxes() as $tax ) {
				$terms = array_merge( $terms, self::term_names( $id, $tax ) );
			}
		}
		foreach ( $s['attrs'] as $tax ) {
			$terms = array_merge( $terms, self::term_names( $id, $tax ) );
		}

		$codes = array();
		if ( $s['codes'] ) {
			foreach ( self::raw_codes( $p ) as $raw ) {
				$codes = array_merge( $codes, LVS_Text::codes( $raw ) );
			}
		}
		$codes = array_values( array_unique( $codes ) );

		$cats = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'ids' ) );
		$cats = is_wp_error( $cats ) ? array() : array_map( 'intval', $cats );

		$work   = 0;
		$format = '';
		if ( function_exists( 'pfm_get_work' ) ) {
			$w = pfm_get_work( $id );
			if ( is_array( $w ) && ! empty( $w['id'] ) ) {
				$work = (int) $w['id'];
				foreach ( (array) ( $w['members'] ?? array() ) as $m ) {
					if ( is_array( $m ) && (int) ( $m['id'] ?? 0 ) === $id ) {
						$format = (string) ( $m['format'] ?? '' );
					}
				}
			}
		}

		$n_title  = LVS_Text::norm( $name );
		$n_people = LVS_Text::norm( implode( ' ', $people ) );
		$extra    = '';
		if ( $s['excerpt'] ) {
			$extra = LVS_Text::norm( wp_strip_all_tags( (string) $p->get_short_description() ) );
			$extra = function_exists( 'mb_substr' ) ? mb_substr( $extra, 0, 800 ) : substr( $extra, 0, 800 );
		}

		$card = array_merge(
			array(
				't' => html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'u' => (string) get_permalink( $id ),
				'i' => self::image( $p ),
				'b' => html_entity_decode( implode( ', ', array_slice( $people, 0, 3 ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'f' => $format,
				'l' => self::format_label( $format ),
				'o' => self::preorder( $p ) ? 1 : 0,
			),
			self::prices( $p )
		);

		return array(
			'product_id' => $id,
			'title'      => $n_title,
			'people'     => $n_people,
			'terms'      => LVS_Text::norm( implode( ' ', array_unique( $terms ) ) ),
			'codes'      => implode( ' ', $codes ),
			'phon'       => LVS_Text::phon( trim( $n_title . ' ' . $n_people ) ),
			'extra'      => $extra,
			'cats'       => $cats ? ',' . implode( ',', $cats ) . ',' : '',
			'card'       => (string) wp_json_encode( $card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'work'       => $work,
			'oos'        => $p->is_in_stock() ? 0 : 1,
			'sales'      => (int) $p->get_total_sales(),
			'updated'    => time(),
		);
	}

	/** @return string[] Term names, decoded. */
	private static function term_names( int $id, string $tax ): array {
		if ( ! taxonomy_exists( $tax ) ) {
			return array();
		}
		$t = get_the_terms( $id, $tax );
		if ( ! is_array( $t ) ) {
			return array();
		}
		$out = array();
		foreach ( $t as $term ) {
			$out[] = html_entity_decode( (string) $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return $out;
	}

	/** @return string[] SKU, GTIN and ISBN fields of the product and its variations. */
	private static function raw_codes( WC_Product $p ): array {
		$keys = (array) apply_filters( 'lvs_code_keys', array( '_bkf_isbn', '_tfi_isbn' ) );
		$out  = array();
		$list = array( $p );
		if ( $p->is_type( 'variable' ) ) {
			foreach ( array_slice( $p->get_children(), 0, 100 ) as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v ) {
					$list[] = $v;
				}
			}
		}
		foreach ( $list as $x ) {
			$out[] = (string) $x->get_sku( 'edit' );
			if ( method_exists( $x, 'get_global_unique_id' ) ) {
				$out[] = (string) $x->get_global_unique_id( 'edit' );
			}
		}
		foreach ( $keys as $k ) {
			if ( is_string( $k ) && '' !== $k ) {
				$out[] = (string) get_post_meta( $p->get_id(), $k, true );
			}
		}
		return array_values( array_filter( array_unique( $out ), 'strlen' ) );
	}

	private static function image( WC_Product $p ): string {
		$img = (int) $p->get_image_id();
		if ( $img <= 0 ) {
			return '';
		}
		$src = wp_get_attachment_image_src( $img, 'woocommerce_gallery_thumbnail' );
		return is_array( $src ) && ! empty( $src[0] ) ? (string) $src[0] : '';
	}

	private static function preorder( WC_Product $p ): bool {
		if ( function_exists( 'por_get_release' ) ) {
			$r = por_get_release( $p );
			return is_array( $r ) && ! empty( $r['active'] );
		}
		return false;
	}

	/**
	 * Guest prices for display: p = price (lowest of a range), q = highest
	 * of a range (0 = no range), r = regular price when on sale (0 = not).
	 * No price at all (e.g. an audio product without one): p = ''.
	 */
	private static function prices( WC_Product $p ): array {
		$out = array(
			'p' => '',
			'q' => 0,
			'r' => 0,
		);
		if ( $p->is_type( 'variable' ) && method_exists( $p, 'get_variation_price' ) ) {
			$min = $p->get_variation_price( 'min', true );
			$max = $p->get_variation_price( 'max', true );
			if ( '' !== (string) $min ) {
				$out['p'] = (float) $min;
				$out['q'] = (float) $max > (float) $min ? (float) $max : 0;
				if ( 0 === $out['q'] && $p->is_on_sale() ) {
					$reg      = $p->get_variation_regular_price( 'min', true );
					$out['r'] = (float) $reg > (float) $min ? (float) $reg : 0;
				}
			}
			return $out;
		}
		$price = $p->get_price();
		if ( '' === (string) $price ) {
			return $out;
		}
		$out['p'] = (float) wc_get_price_to_display( $p );
		if ( $p->is_on_sale() && '' !== (string) $p->get_regular_price() ) {
			$reg      = (float) wc_get_price_to_display( $p, array( 'price' => $p->get_regular_price() ) );
			$out['r'] = $reg > $out['p'] ? $reg : 0;
		}
		return $out;
	}

	/** Label of a Product Formats key (see the bootstrap's deviations). */
	public static function format_label( string $key ): string {
		if ( '' === $key ) {
			return '';
		}
		$label = '';
		if ( class_exists( 'PFM_Settings' ) && method_exists( 'PFM_Settings', 'label' ) ) {
			try {
				$label = (string) PFM_Settings::label( $key );
			} catch ( \Throwable $e ) {
				$label = '';
			}
		}
		return (string) apply_filters( 'lvs_format_label', $label, $key );
	}

	/* =====================================================================
	 * Reading
	 * =================================================================== */

	/** Every row (file build): ordered by sales, then id. */
	public static function all_rows(): array {
		global $wpdb;
		if ( ! self::exists() ) {
			return array();
		}
		$t    = self::table();
		$rows = $wpdb->get_results( "SELECT product_id, title, people, terms, codes, phon, cats, card, work, oos, sales FROM {$t} ORDER BY sales DESC, product_id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}
}

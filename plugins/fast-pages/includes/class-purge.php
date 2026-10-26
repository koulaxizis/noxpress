<?php
/**
 * FPG_Purge — which cached pages go when content changes.
 *
 * One post / product changes (save, status, delete, stock, comment): its
 * page, the home page, the posts page, the shop, its archive, its terms and
 * its author. Collected during the request and deleted once on shutdown;
 * more than MAX_URLS pages = the whole cache. Site-wide changes (theme,
 * menus, widgets, Customizer, permalinks, plugin and theme updates, WP
 * Rocket-style "purge all" from the suite) clear everything.
 *
 * Public API for the suite (Bible §6):
 *  do_action( 'noxpress_purge_page_cache', array $urls = array() )
 *  (no URLs = everything). A no-op while Fast Pages is not installed.
 * It also listens to noxpress_products_changed and crt_creator_changed.
 *
 * Only files are deleted (never a database write from a visitor's request,
 * Bible §14.5); the preload refills them in admin and cron requests.
 */

defined( 'ABSPATH' ) || exit;

final class FPG_Purge {

	const MAX_URLS = 200;

	/** URLs waiting for shutdown. */
	private static $urls = array();

	/** Everything goes on shutdown. */
	private static $all = false;

	private static $hooked = false;

	public static function init(): void {
		add_action( 'clean_post_cache', array( __CLASS__, 'on_post' ), 10, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_status' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_post_id' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_post_id' ) );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_product' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_product' ) );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'on_post_id' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'on_post_id' ) );
		add_action( 'wp_set_comment_status', array( __CLASS__, 'on_comment' ) );
		add_action( 'comment_post', array( __CLASS__, 'on_comment' ) );
		add_action( 'edit_comment', array( __CLASS__, 'on_comment' ) );
		add_action( 'edited_term', array( __CLASS__, 'on_term' ), 10, 3 );
		add_action( 'delete_term', array( __CLASS__, 'on_term' ), 10, 3 );

		foreach ( array( 'switch_theme', 'customize_save_after', 'wp_update_nav_menu', 'update_option_sidebars_widgets', 'update_option_permalink_structure', 'update_option_category_base', 'update_option_tag_base', 'update_option_show_on_front', 'update_option_page_on_front', 'update_option_page_for_posts', 'update_option_blogname', 'update_option_blogdescription', 'upgrader_process_complete', 'activated_plugin', 'deactivated_plugin', 'update_option_woocommerce_permalinks', 'woocommerce_settings_saved' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_site' ) );
		}

		add_action( 'noxpress_purge_page_cache', array( __CLASS__, 'on_suite' ) );
		add_action( 'noxpress_products_changed', array( __CLASS__, 'on_products' ) );
		add_action( 'crt_creator_changed', array( __CLASS__, 'on_creator' ), 10, 2 );
	}

	/* =====================================================================
	 * Hooks
	 * =================================================================== */

	public static function on_post( $post_id, $post = null ): void {
		self::post( (int) $post_id );
	}

	public static function on_post_id( $post_id ): void {
		self::post( (int) $post_id );
	}

	public static function on_status( $new, $old, $post ): void {
		if ( $new !== $old && $post instanceof WP_Post && ( 'publish' === $new || 'publish' === $old ) ) {
			self::post( (int) $post->ID, 'publish' === $old );
		}
	}

	public static function on_product( $product ): void {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			self::post( (int) $product->get_id() );
		}
	}

	public static function on_comment( $comment_id ): void {
		$c = get_comment( (int) $comment_id );
		if ( $c && (int) $c->comment_post_ID > 0 ) {
			self::add_urls( array( get_permalink( (int) $c->comment_post_ID ) ) );
		}
	}

	public static function on_term( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		$tax = get_taxonomy( (string) $taxonomy );
		if ( ! $tax || ! $tax->public ) {
			return;
		}
		$link = get_term_link( (int) $term_id, (string) $taxonomy );
		self::add_urls( array( is_wp_error( $link ) ? '' : $link, home_url( '/' ) ) );
	}

	public static function on_site(): void {
		self::all_on_shutdown();
	}

	/** noxpress_purge_page_cache( array $urls = array() ): empty = everything. */
	public static function on_suite( $urls = array() ): void {
		$urls = array_filter( array_map( 'strval', (array) $urls ) );
		if ( $urls ) {
			self::add_urls( $urls );
		} else {
			self::all_on_shutdown();
		}
	}

	public static function on_products( $ids = array() ): void {
		foreach ( array_slice( array_map( 'intval', (array) $ids ), 0, self::MAX_URLS ) as $id ) {
			self::post( $id );
		}
		if ( count( (array) $ids ) > self::MAX_URLS ) {
			self::all_on_shutdown();
		}
	}

	public static function on_creator( $creator_id = 0, $product_ids = array() ): void {
		self::on_products( $product_ids );
		self::post( (int) $creator_id );
	}

	/* =====================================================================
	 * Collect
	 * =================================================================== */

	/** The pages that show a post. $was_public: it just left "publish". */
	private static function post( int $id, bool $was_public = false ): void {
		if ( $id <= 0 || self::$all ) {
			return;
		}
		$post = get_post( $id );
		if ( ! $post || 'revision' === $post->post_type || 'nav_menu_item' === $post->post_type ) {
			return;
		}
		if ( 'product_variation' === $post->post_type && $post->post_parent ) {
			self::post( (int) $post->post_parent, $was_public );
			return;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->public ) {
			return;
		}
		if ( 'publish' !== $post->post_status && ! $was_public && 'trash' !== $post->post_status ) {
			return; // A draft that never was public.
		}
		$urls = array( home_url( '/' ) );
		$link = get_permalink( $post );
		if ( $link ) {
			$urls[] = $link;
			// A trashed post's permalink gets __trashed: purge the old one too.
			if ( 'trash' === $post->post_status ) {
				$urls[] = str_replace( '__trashed', '', $link );
			}
		}
		if ( 'post' === $post->post_type && (int) get_option( 'page_for_posts' ) ) {
			$urls[] = get_permalink( (int) get_option( 'page_for_posts' ) );
		}
		$archive = get_post_type_archive_link( $post->post_type );
		if ( $archive ) {
			$urls[] = $archive;
		}
		if ( 'product' === $post->post_type && function_exists( 'wc_get_page_permalink' ) ) {
			$urls[] = wc_get_page_permalink( 'shop' );
		}
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
			if ( ! $tax->public ) {
				continue;
			}
			$terms = get_the_terms( $post, $tax->name );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $t ) {
					$l = get_term_link( $t );
					if ( ! is_wp_error( $l ) ) {
						$urls[] = $l;
					}
					foreach ( get_ancestors( (int) $t->term_id, $tax->name, 'taxonomy' ) as $anc ) {
						$l = get_term_link( (int) $anc, $tax->name );
						if ( ! is_wp_error( $l ) ) {
							$urls[] = $l;
						}
					}
				}
			}
		}
		if ( post_type_supports( $post->post_type, 'author' ) && $post->post_author ) {
			$urls[] = get_author_posts_url( (int) $post->post_author );
		}
		self::add_urls( $urls );
	}

	private static function add_urls( array $urls ): void {
		if ( self::$all ) {
			return;
		}
		foreach ( $urls as $u ) {
			$u = (string) $u;
			if ( '' !== $u ) {
				self::$urls[ $u ] = true;
			}
		}
		if ( count( self::$urls ) > self::MAX_URLS ) {
			self::all_on_shutdown();
			return;
		}
		self::hook();
	}

	private static function all_on_shutdown(): void {
		self::$all  = true;
		self::$urls = array();
		self::hook();
	}

	private static function hook(): void {
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 0 );
		}
	}

	/** Deletes what was collected. */
	public static function flush(): void {
		try {
			if ( self::$all ) {
				self::all();
			} elseif ( self::$urls ) {
				self::urls( array_keys( self::$urls ) );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		self::$all  = false;
		self::$urls = array();
	}

	/* =====================================================================
	 * Delete now
	 * =================================================================== */

	/** Deletes the cached pages of these URLs. Returns how many files. */
	public static function urls( array $urls ): int {
		$cfg = FPG_Setup::config();
		$n   = 0;
		foreach ( $urls as $u ) {
			foreach ( FPG_Key::url( (string) $u, $cfg ) as $file ) {
				if ( FPG_Files::delete_page( $file ) ) {
					++$n;
				}
			}
		}
		if ( ! self::visitor() ) {
			FPG_Preload::urgent( $urls );
		}
		return $n;
	}

	/** Deletes the whole cache. Returns how many pages. */
	public static function all(): int {
		$n = FPG_Files::purge_all( true );
		if ( ! self::visitor() ) {
			FPG_Setup::set_state( array( 'last_purge' => time() ) );
			FPG_Preload::after_purge();
		}
		return $n;
	}

	/** A visitor's request: delete files only, no database writes. */
	private static function visitor(): bool {
		return ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! is_user_logged_in();
	}
}

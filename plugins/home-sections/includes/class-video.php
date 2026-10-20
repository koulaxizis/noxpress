<?php
/**
 * HMS_Video — the [hs_video] shortcode: the newest product video (a book
 * trailer or a presentation) from the products' own video tabs.
 *
 *   [hs_video type="trailer|presentation" id="123" exclude="1,2"
 *             category="slug,slug" preorders="no" heading="…"]
 *
 * Source (filter hms_video_source): product meta wb_custom_tabs of
 * "Custom Product Tabs for WooCommerce", a list of tabs {title, content,
 * position, tab_type, nickname}. The trailer is the tab at position 30,
 * the presentation the tab at position 31 (stored as an int or a numeric
 * string). The video ID comes from [wb_cpt_youtube_embed_shortcode
 * video_id="ID" …] in the tab content, or from a youtube.com/watch?v=ID,
 * youtu.be/ID or /embed/ID URL; IDs must match [A-Za-z0-9_-]{11}.
 *
 * Picking: the newest 50 published, visible products that have the meta
 * (post_date, then ID), parsed in PHP, first match wins. A meta_query on
 * the serialized array would be fragile. The result (product ID + video
 * ID, or "none") is kept in ONE transient for all attribute sets
 * (hms_video_cache, 12 h), cleared whenever a product or its tabs change.
 * The product itself (name, people, link) is read at render time, so a
 * renamed product needs no flush.
 *
 * Output: the poster markup a click-to-load player needs (heading, button
 * with data-src, meta line). No iframe is ever printed server-side; the
 * small script assets/video.js swaps the button for the iframe on click.
 * It is enqueued only when the shortcode rendered, guarded by
 * window.HMS_YT, and it skips a button that a page script of its own has
 * already replaced, so both can run on the same page. Class names come
 * from the filter hms_video_classes; texts are store settings (Bible
 * §14.17). Nothing found → ''.
 */

defined( 'ABSPATH' ) || exit;

final class HMS_Video {

	const TAG   = 'hs_video';
	const CACHE = 'hms_video_cache';
	const TTL   = 43200; // 12 h.

	/** Products scanned per query. */
	const SCAN = 50;

	/** Attribute sets kept in the cache transient. */
	const MAX_KEYS = 50;

	const ID_RE = '[A-Za-z0-9_-]{11}';

	/** True after an error: the rest of the request runs without us (Bible §14.6). */
	private static $off = false;

	public static function init(): void {
		// Registered even with HMS_DISABLE, so the shortcode never shows
		// as text (Bible §14); render() then prints nothing.
		add_shortcode( self::TAG, array( __CLASS__, 'shortcode' ) );

		add_action( 'save_post_product', array( __CLASS__, 'flush' ) );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'flush' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'flush' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'on_post' ) );
		add_action( 'trashed_post', array( __CLASS__, 'on_post' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'on_post' ) );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
	}

	/* =====================================================================
	 * Configuration (filters)
	 * =================================================================== */

	/** The video types (values of type=""). */
	public static function types(): array {
		return array( 'trailer', 'presentation' );
	}

	/**
	 * Where the videos live.
	 *
	 * @return array{meta_key:string,positions:array<string,int>}
	 */
	public static function source(): array {
		$def = array(
			'meta_key'  => 'wb_custom_tabs', // phpcs:ignore WordPress.DB.SlowDBQuery -- array key, not a query arg.
			'positions' => array(
				'trailer'      => 30,
				'presentation' => 31,
			),
		);
		$src = apply_filters( 'hms_video_source', $def );
		$out = $def;
		if ( is_array( $src ) ) {
			if ( isset( $src['meta_key'] ) && is_string( $src['meta_key'] ) && '' !== $src['meta_key'] ) {
				$out['meta_key'] = $src['meta_key'];
			}
			if ( isset( $src['positions'] ) && is_array( $src['positions'] ) ) {
				foreach ( self::types() as $t ) {
					if ( isset( $src['positions'][ $t ] ) && is_numeric( $src['positions'][ $t ] ) ) {
						$out['positions'][ $t ] = (int) $src['positions'][ $t ];
					}
				}
			}
		}
		return $out;
	}

	/** The taxonomy whose terms follow the product name ('' = none). */
	public static function people_taxonomy(): string {
		$tax = apply_filters( 'hms_video_people_taxonomy', 'pa_syggrafeis' );
		return is_string( $tax ) ? $tax : '';
	}

	/**
	 * CSS class names of the output.
	 *
	 * @return array{heading:string,button:string,play:string,meta:string}
	 */
	public static function classes(): array {
		$def = array(
			'heading' => 'wp-block-heading glk-h',
			'button'  => 'glk-yt',
			'play'    => 'glk-yt-play',
			'meta'    => 'glk-yt-meta',
		);
		$raw = apply_filters( 'hms_video_classes', $def );
		$out = array();
		foreach ( $def as $k => $v ) {
			$val       = is_array( $raw ) && isset( $raw[ $k ] ) && is_string( $raw[ $k ] ) ? $raw[ $k ] : $v;
			$tokens    = array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', $val, -1, PREG_SPLIT_NO_EMPTY ) ) );
			$out[ $k ] = implode( ' ', $tokens );
		}
		if ( '' === $out['button'] ) {
			$out['button'] = $def['button']; // The script needs one.
		}
		return $out;
	}

	/* =====================================================================
	 * Attributes
	 * =================================================================== */

	/**
	 * Normalised attributes.
	 *
	 * @param mixed $atts Raw shortcode attributes.
	 * @return array{type:string,id:int,exclude:int[],category:string[],preorders:bool,heading:?string}
	 */
	public static function parse_atts( $atts ): array {
		$atts = is_array( $atts ) ? $atts : array();
		$get  = static function ( string $k ) use ( $atts ): string {
			return isset( $atts[ $k ] ) && is_scalar( $atts[ $k ] ) ? trim( (string) $atts[ $k ] ) : '';
		};

		$type = strtolower( $get( 'type' ) );
		$type = in_array( $type, self::types(), true ) ? $type : ( '' === $type ? 'trailer' : '' );

		$cats = array();
		foreach ( preg_split( '/[\s,]+/', $get( 'category' ), -1, PREG_SPLIT_NO_EMPTY ) as $slug ) {
			$slug = sanitize_title( $slug );
			if ( '' !== $slug ) {
				$cats[ $slug ] = $slug;
			}
			if ( count( $cats ) >= 20 ) {
				break;
			}
		}
		$cats = array_values( $cats );
		sort( $cats );

		$exclude = HMS_Settings::int_list( $get( 'exclude' ), HMS_Settings::MAX_IDS );
		sort( $exclude );

		$ids = HMS_Settings::int_list( $get( 'id' ), 1 );

		$heading = null;
		if ( isset( $atts['heading'] ) && is_scalar( $atts['heading'] ) ) {
			$heading = sanitize_text_field( (string) $atts['heading'] );
		}

		return array(
			'type'      => $type,
			'id'        => $ids ? $ids[0] : 0,
			'exclude'   => $exclude,
			'category'  => $cats,
			'preorders' => 'no' !== strtolower( $get( 'preorders' ) ),
			'heading'   => $heading,
		);
	}

	/* =====================================================================
	 * Video IDs
	 * =================================================================== */

	/** The first valid YouTube ID in a tab's content, or ''. */
	public static function video_id( string $content ): string {
		$id   = self::ID_RE;
		$end  = '(?![A-Za-z0-9_-])';
		$pats = array(
			'/\bvideo_id\s*=\s*(?:["\']|&quot;|&#0?34;|&#82(?:21|43);)?(' . $id . ')' . $end . '/i',
			'#youtube(?:-nocookie)?\.com/watch\?(?:[^"\'\s<\]]*?&(?:amp;|\#038;)?)?v=(' . $id . ')' . $end . '#i',
			'#youtu\.be/(' . $id . ')' . $end . '#i',
			'#/embed/(' . $id . ')' . $end . '#i',
		);
		$best = '';
		$pos  = PHP_INT_MAX;
		foreach ( $pats as $p ) {
			if ( preg_match( $p, $content, $m, PREG_OFFSET_CAPTURE ) && $m[1][1] < $pos ) {
				$pos  = $m[1][1];
				$best = $m[1][0];
			}
		}
		return $best;
	}

	/** The video ID of a product for a type, or ''. */
	public static function product_video( int $product_id, string $type ): string {
		$src = self::source();
		if ( ! isset( $src['positions'][ $type ] ) ) {
			return '';
		}
		$want = $src['positions'][ $type ];
		$tabs = get_post_meta( $product_id, $src['meta_key'], true );
		if ( ! is_array( $tabs ) ) {
			return '';
		}
		foreach ( $tabs as $tab ) {
			if ( ! is_array( $tab ) || ! isset( $tab['position'], $tab['content'] ) ) {
				continue;
			}
			if ( ! is_numeric( $tab['position'] ) || (int) $tab['position'] !== $want || ! is_string( $tab['content'] ) ) {
				continue;
			}
			$vid = self::video_id( $tab['content'] );
			if ( '' !== $vid ) {
				return $vid;
			}
		}
		return '';
	}

	/* =====================================================================
	 * Picking
	 * =================================================================== */

	/** True when a product may be shown: published, no password. */
	private static function showable( int $product_id ): bool {
		$p = get_post( $product_id );
		return $p instanceof WP_Post && 'product' === $p->post_type && 'publish' === $p->post_status && '' === $p->post_password;
	}

	/** The WP_Query args of the scan. */
	public static function query_args( array $a ): array {
		$src  = self::source();
		$hide = array( 'exclude-from-catalog' );
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
			$hide[] = 'outofstock';
		}
		$args = array(
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'has_password'        => false,
			'posts_per_page'      => self::SCAN,
			'orderby'             => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'meta_query'          => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- EXISTS on one key, 50 rows.
				array(
					'key'     => $src['meta_key'],
					'compare' => 'EXISTS',
				),
			),
			'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- catalog visibility, as WooCommerce does.
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => $hide,
					'operator' => 'NOT IN',
				),
			),
		);
		if ( $a['category'] ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => $a['category'],
				'operator' => 'IN',
			);
		}
		return HMS_Markers::apply(
			$args,
			array(
				'ids'       => $a['exclude'],
				'cat_slugs' => array(),
				'cat_ids'   => array(),
				'preorders' => ! $a['preorders'],
				'titles'    => array(),
			)
		);
	}

	/**
	 * Finds the product and its video, without the cache.
	 *
	 * @return array{0:int,1:string} product ID and video ID, or array( 0, '' ).
	 */
	public static function find( array $a ): array {
		if ( '' === $a['type'] ) {
			return array( 0, '' );
		}
		if ( $a['id'] > 0 ) {
			if ( ! self::showable( $a['id'] ) ) {
				return array( 0, '' );
			}
			$vid = self::product_video( $a['id'], $a['type'] );
			return '' === $vid ? array( 0, '' ) : array( $a['id'], $vid );
		}
		$q = new WP_Query( self::query_args( $a ) );
		foreach ( $q->posts as $pid ) {
			$vid = self::product_video( (int) $pid, $a['type'] );
			if ( '' !== $vid ) {
				return array( (int) $pid, $vid );
			}
		}
		return array( 0, '' );
	}

	/** Cache key of an attribute set (heading excluded: it does not change the pick). */
	private static function key( array $a ): string {
		return md5( wp_json_encode( array( $a['type'], $a['id'], $a['exclude'], $a['category'], $a['preorders'] ) ) );
	}

	/**
	 * find() through the transient. A cached product that is no longer
	 * published, or whose tab changed, is looked up again.
	 */
	public static function pick( array $a ): array {
		$key   = self::key( $a );
		$cache = get_transient( self::CACHE );
		$cache = is_array( $cache ) ? $cache : array();
		if ( isset( $cache[ $key ] ) && is_array( $cache[ $key ] ) && 2 === count( $cache[ $key ] ) ) {
			list( $pid, $vid ) = $cache[ $key ];
			$pid               = (int) $pid;
			$vid               = (string) $vid;
			if ( 0 === $pid ) {
				return array( 0, '' );
			}
			if ( self::showable( $pid ) && self::product_video( $pid, $a['type'] ) === $vid ) {
				return array( $pid, $vid );
			}
		}
		$found = self::find( $a );
		unset( $cache[ $key ] );
		$cache[ $key ] = $found;
		if ( count( $cache ) > self::MAX_KEYS ) {
			$cache = array_slice( $cache, -self::MAX_KEYS, null, true );
		}
		// The one write on a visitor request (deviation from Bible §14.5,
		// see the bootstrap docblock).
		set_transient( self::CACHE, $cache, self::TTL );
		return $found;
	}

	/* =====================================================================
	 * Cache invalidation
	 * =================================================================== */

	public static function flush(): void {
		delete_transient( self::CACHE );
	}

	/** transition_post_status: products only (publishing a scheduled one too). */
	public static function on_transition( $new_status, $old_status, $post ): void {
		if ( $post instanceof WP_Post && 'product' === $post->post_type ) {
			self::flush();
		}
	}

	/** deleted_post, trashed_post, untrashed_post. */
	public static function on_post( $post_id ): void {
		if ( 'product' === get_post_type( (int) $post_id ) ) {
			self::flush();
		}
	}

	/** added / updated / deleted_post_meta for the tabs meta key. */
	public static function on_meta( $meta_id, $object_id, $meta_key ): void {
		if ( self::source()['meta_key'] === $meta_key ) {
			self::flush();
		}
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
			$a                 = self::parse_atts( $atts );
			list( $pid, $vid ) = self::pick( $a );
			if ( 0 === $pid ) {
				return '';
			}
			$html = self::html( $a, $pid, $vid );
			if ( '' !== $html ) {
				self::enqueue();
			}
			return $html;
		} catch ( \Throwable $e ) {
			self::$off = true;
			return '';
		}
	}

	/** The markup for one product and video (no iframe). */
	public static function html( array $a, int $pid, string $vid ): string {
		if ( ! preg_match( '/^' . self::ID_RE . '$/', $vid ) ) {
			return '';
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
		$name    = $product ? $product->get_name() : get_the_title( $pid );
		$link    = get_permalink( $pid );
		if ( ! is_string( $link ) || '' === $link ) {
			return '';
		}
		$c = self::classes();

		$heading = null !== $a['heading'] ? $a['heading'] : self::text( $a['type'] . '_heading' );
		$title   = str_replace( '{name}', $name, self::text( $a['type'] . '_title' ) );
		$play    = str_replace( '{title}', $title, self::text( 'play' ) );
		$people  = self::people( $pid );
		$src     = esc_url_raw( 'https://www.youtube-nocookie.com/embed/' . $vid . '?autoplay=1&rel=0' );

		$out = '';
		if ( '' !== $heading ) {
			$out .= '<h2' . self::class_attr( $c['heading'] ) . '>' . esc_html( $heading ) . "</h2>\n";
		}
		$out .= '<button type="button"' . self::class_attr( $c['button'] ) . ' data-src="' . esc_attr( $src ) . '" data-kind="yt" data-title="' . esc_attr( $title ) . '" aria-label="' . esc_attr( $play ) . '">'
			. '<img src="' . esc_url( 'https://i.ytimg.com/vi/' . $vid . '/hqdefault.jpg' ) . '" alt="" loading="lazy" width="480" height="360">'
			. '<span' . self::class_attr( $c['play'] ) . ' aria-hidden="true"></span></button>' . "\n";
		$out .= '<p' . self::class_attr( $c['meta'] ) . '><strong>' . esc_html( $name ) . '</strong>'
			. ( '' !== $people ? esc_html( $people ) . ' · ' : '' )
			. '<a href="' . esc_url( $link ) . '">' . esc_html( self::text( 'link' ) ) . '</a></p>';
		return $out;
	}

	private static function class_attr( string $classes ): string {
		return '' === $classes ? '' : ' class="' . esc_attr( $classes ) . '"';
	}

	/** The people terms of a product, comma-separated, in the attribute's order. */
	public static function people( int $pid ): string {
		$tax = self::people_taxonomy();
		if ( '' === $tax || ! taxonomy_exists( $tax ) ) {
			return '';
		}
		if ( function_exists( 'wc_get_product_terms' ) ) {
			$names = wc_get_product_terms( $pid, $tax, array( 'fields' => 'names' ) );
		} else {
			$terms = get_the_terms( $pid, $tax );
			$names = is_array( $terms ) ? wp_list_pluck( $terms, 'name' ) : array();
		}
		return is_array( $names ) ? implode( ', ', array_map( 'strval', $names ) ) : '';
	}

	/** A visitor text in the store's language (Bible §14.17). */
	public static function text( string $key ): string {
		$s     = HMS_Settings::get()['video'];
		$lang  = 'auto' === $s['lang'] ? HMS_Lang::from_locale( (string) get_locale() ) : $s['lang'];
		$texts = isset( $s['texts'][ $key ] ) ? $s['texts'][ $key ] : array(
			'el' => '',
			'en' => '',
		);
		$text  = 'en' === $lang && '' !== $texts['en'] ? $texts['en'] : $texts['el'];
		$text  = apply_filters( 'hms_video_text', $text, $key, $lang );
		return is_string( $text ) ? $text : '';
	}

	/** The click-to-load script, once, in the footer. */
	private static function enqueue(): void {
		if ( ! apply_filters( 'hms_video_script', true ) || wp_script_is( 'hms-video', 'enqueued' ) ) {
			return;
		}
		$c     = self::classes();
		$first = strtok( $c['button'], ' ' );
		wp_enqueue_script( 'hms-video', HMS_URL . 'assets/video.js', array(), HMS_VERSION, true );
		wp_add_inline_script( 'hms-video', 'window.HMS_YT_CFG=' . wp_json_encode( array( 'button' => (string) $first ) ) . ';', 'before' );
	}
}

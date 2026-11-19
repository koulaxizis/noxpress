<?php
/**
 * VLG_Content — what the English visitor reads, and the switch.
 *
 * English texts of a post, page or product are post meta, written in the
 * editor box (VLG_Meta_Box):
 *   _vlg_title    title / product name
 *   _vlg_excerpt  short description / excerpt
 *   _vlg_content  description / content (HTML, the same filters as the content)
 * An empty field keeps the Greek one. Short texts (attribute names and
 * values, categories, menu items, shipping and payment labels) come from
 * the phrase list of the settings. Menus: a theme location can show
 * another menu in English.
 *
 * All of it only in English requests (VLG_Router::is_en()); the Greek site
 * is never changed, except the switch, the hreflang links and the
 * front.css they need (§14: one small file, only when shown).
 */

defined( 'ABSPATH' ) || exit;

final class VLG_Content {

	const META = array( '_vlg_title', '_vlg_excerpt', '_vlg_content' );

	public static function init(): void {
		add_shortcode( 'vlg_switch', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'float_switch' ), 5 );
		add_action( 'wp_head', array( __CLASS__, 'hreflang' ), 2 );

		if ( ! VLG_Router::is_en() ) {
			return;
		}
		add_filter( 'the_title', array( __CLASS__, 'title' ), 1, 2 );
		add_filter( 'single_post_title', array( __CLASS__, 'single_title' ), 1, 2 );
		add_filter( 'the_content', array( __CLASS__, 'content' ), 0 );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'excerpt' ), 1, 2 );
		add_filter( 'woocommerce_short_description', array( __CLASS__, 'short_description' ), 0 );
		add_filter( 'woocommerce_product_get_name', array( __CLASS__, 'product_name' ), 1, 2 );
		add_filter( 'woocommerce_product_variation_get_name', array( __CLASS__, 'variation_name' ), 1, 2 );
		add_filter( 'woocommerce_product_title', array( __CLASS__, 'product_title' ), 1, 2 );
		add_filter( 'woocommerce_product_get_short_description', array( __CLASS__, 'product_short' ), 1, 2 );
		add_filter( 'woocommerce_product_get_description', array( __CLASS__, 'product_long' ), 1, 2 );
		add_action( 'woocommerce_before_single_product', array( __CLASS__, 'product_note' ), 5 );
		add_filter( 'the_content', array( __CLASS__, 'page_note' ), 20 );

		// The <title>, meta description and social texts (no SEO plugin, Rank Math, Yoast).
		add_filter( 'document_title_parts', array( __CLASS__, 'doc_title_parts' ), 20 );
		foreach ( array( 'rank_math/frontend/title', 'rank_math/opengraph/facebook/og_title', 'rank_math/opengraph/twitter/title', 'wpseo_title', 'wpseo_opengraph_title', 'wpseo_twitter_title' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'seo_title' ), 20 );
		}
		foreach ( array( 'rank_math/frontend/description', 'rank_math/opengraph/facebook/og_description', 'rank_math/opengraph/twitter/description', 'wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'seo_description' ), 20 );
		}

		// Short texts.
		add_filter( 'woocommerce_attribute_label', array( __CLASS__, 'phrase' ), 20 );
		add_filter( 'woocommerce_variation_option_name', array( __CLASS__, 'phrase' ), 20 );
		add_filter( 'woocommerce_attribute', array( __CLASS__, 'phrase_in' ), 20 );
		add_filter( 'nav_menu_item_title', array( __CLASS__, 'phrase' ), 20 );
		add_filter( 'woocommerce_gateway_title', array( __CLASS__, 'phrase' ), 20 );
		add_filter( 'woocommerce_gateway_description', array( __CLASS__, 'phrase' ), 20 );
		add_filter( 'woocommerce_shipping_rate_label', array( __CLASS__, 'phrase' ), 20 );
		add_filter( 'woocommerce_cart_shipping_method_full_label', array( __CLASS__, 'phrase_in' ), 20 );
		add_filter( 'get_term', array( __CLASS__, 'term' ), 20, 2 );
		add_filter( 'theme_mod_nav_menu_locations', array( __CLASS__, 'menus' ), 20 );
	}

	/* ---------------------------------------------------------------------
	 * English texts of a post
	 * ------------------------------------------------------------------- */

	/** The English text of a post field ('' when it has none). */
	public static function get( int $post_id, string $key ): string {
		if ( $post_id <= 0 || ! in_array( $key, self::META, true ) ) {
			return '';
		}
		return trim( (string) get_post_meta( $post_id, $key, true ) );
	}

	/** Whether a post has any English text. */
	public static function has_any( int $post_id ): bool {
		foreach ( self::META as $k ) {
			if ( '' !== self::get( $post_id, $k ) ) {
				return true;
			}
		}
		return false;
	}

	public static function title( $title, $id = 0 ) {
		$en = self::get( (int) $id, '_vlg_title' );
		return '' !== $en ? esc_html( $en ) : $title;
	}

	public static function single_title( $title, $post = null ) {
		$id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
		$en = self::get( $id, '_vlg_title' );
		return '' !== $en ? $en : $title;
	}

	/** the_content at priority 0: the English HTML, then every usual filter. */
	public static function content( $content ) {
		$id = (int) get_the_ID();
		$en = self::get( $id, '_vlg_content' );
		return '' !== $en ? wp_kses_post( $en ) : $content;
	}

	public static function excerpt( $excerpt, $post = null ) {
		$id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : (int) get_the_ID();
		$en = self::get( $id, '_vlg_excerpt' );
		return '' !== $en ? wp_kses_post( $en ) : $excerpt;
	}

	public static function short_description( $html ) {
		global $post;
		$id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
		$en = self::get( $id, '_vlg_excerpt' );
		return '' !== $en ? wp_kses_post( wpautop( $en ) ) : $html;
	}

	public static function product_name( $name, $product ) {
		$id = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
		$en = self::get( $id, '_vlg_title' );
		return '' !== $en ? $en : $name;
	}

	/** «Κούπα orOS - Μαύρο» → «orOS mug - Black»: the parent's English name, then phrases. */
	public static function variation_name( $name, $variation ) {
		if ( ! is_object( $variation ) || ! method_exists( $variation, 'get_parent_id' ) ) {
			return $name;
		}
		$pid = (int) $variation->get_parent_id();
		$en  = self::get( $pid, '_vlg_title' );
		if ( '' !== $en ) {
			$el   = (string) get_post_field( 'post_title', $pid, 'raw' );
			$name = '' !== $el ? str_replace( $el, $en, (string) $name ) : $name;
		}
		return self::phrase_in( $name );
	}

	/** A variation's title (the block cart and the Store API use it): the parent's English name. */
	public static function product_title( $title, $product = null ) {
		$id = is_object( $product ) && method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
		if ( $id <= 0 && is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$id = (int) $product->get_id();
		}
		$en = self::get( $id, '_vlg_title' );
		return '' !== $en ? $en : $title;
	}

	public static function product_short( $text, $product ) {
		$id = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
		$en = self::get( $id, '_vlg_excerpt' );
		return '' !== $en ? $en : $text;
	}

	public static function product_long( $text, $product ) {
		$id = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
		$en = self::get( $id, '_vlg_content' );
		return '' !== $en ? $en : $text;
	}

	/* ---------------------------------------------------------------------
	 * "Only in Greek"
	 * ------------------------------------------------------------------- */

	private static function note_html(): string {
		// Only English visitors see it, so it is always in English.
		return '<p class="vlg-note" lang="en">This page is only available in Greek. Your browser can translate it for you.</p>';
	}

	public static function product_note(): void {
		if ( VLG_Settings::get()['note'] && ! self::has_any( (int) get_the_ID() ) ) {
			echo self::note_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in note_html().
		}
	}

	public static function page_note( $content ) {
		if ( ! VLG_Settings::get()['note'] || ! is_singular( array( 'page', 'post' ) ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return $content;
		}
		return self::has_any( (int) get_the_ID() ) ? $content : self::note_html() . $content;
	}

	/* ---------------------------------------------------------------------
	 * Search engines and sharing: the <title>, the meta description, og/twitter
	 * ------------------------------------------------------------------- */

	/** The post the head of this request describes: a single post, the shop page or the front page. */
	private static function head_post_id(): int {
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return (int) wc_get_page_id( 'shop' );
		}
		if ( is_front_page() && 'page' === get_option( 'show_on_front' ) ) {
			return (int) get_option( 'page_on_front' );
		}
		return is_singular() ? (int) get_queried_object_id() : 0;
	}

	/** Without an SEO plugin: the title part of <title>. */
	public static function doc_title_parts( $parts ) {
		if ( ! is_array( $parts ) || ! isset( $parts['title'] ) ) {
			return $parts;
		}
		$parts['title'] = self::seo_title( (string) $parts['title'] );
		return $parts;
	}

	/** «Κούπα orOS | ΓΛΑΡΟΛΥΚΟΙ» → «orOS Mug | ΓΛΑΡΟΛΥΚΟΙ»: the Greek title inside it becomes the English one. */
	public static function seo_title( $title ) {
		$id = self::head_post_id();
		$en = self::get( $id, '_vlg_title' );
		if ( '' === $en || ! is_string( $title ) || '' === $title ) {
			return $title;
		}
		$el = trim( (string) get_post_field( 'post_title', $id, 'raw' ) );
		if ( '' === $el ) {
			return $title;
		}
		foreach ( array( $el, esc_html( $el ), wptexturize( $el ) ) as $form ) {
			if ( false !== strpos( $title, $form ) ) {
				return str_replace( $form, $en, $title );
			}
		}
		return $title;
	}

	/** The English short description (else the description) as plain text of at most 160 characters. */
	public static function seo_description( $description ) {
		$id = self::head_post_id();
		$en = self::get( $id, '_vlg_excerpt' );
		if ( '' === $en ) {
			$en = self::get( $id, '_vlg_content' );
		}
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $en ) ) ) );
		if ( '' === $text ) {
			return $description;
		}
		if ( mb_strlen( $text ) > 160 ) {
			$cut  = mb_substr( $text, 0, 159 );
			$sp   = mb_strrpos( $cut, ' ' );
			$text = rtrim( false !== $sp && $sp > 100 ? mb_substr( $cut, 0, $sp ) : $cut, ' ,;:.' ) . '…';
		}
		return $text;
	}

	/* ---------------------------------------------------------------------
	 * Short texts, terms, menus
	 * ------------------------------------------------------------------- */

	/** The whole text, when it is one phrase of the list. */
	public static function phrase( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return $text;
		}
		$map = VLG_Settings::get()['phrases'];
		$key = trim( wp_strip_all_tags( $text ) );
		return isset( $map[ $key ] ) ? str_replace( $key, $map[ $key ], $text ) : $text;
	}

	/** Every phrase of the list inside a longer text (longest first). */
	public static function phrase_in( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return $text;
		}
		$map = VLG_Settings::get()['phrases'];
		if ( ! $map ) {
			return $text;
		}
		uksort(
			$map,
			static function ( $a, $b ) {
				return strlen( (string) $b ) - strlen( (string) $a );
			}
		);
		return strtr( $text, $map );
	}

	/** Product categories, tags and attribute values. */
	public static function term( $term, $taxonomy = '' ) {
		if ( ! is_object( $term ) || ! isset( $term->name ) ) {
			return $term;
		}
		$tax = (string) $taxonomy;
		if ( 'product_cat' !== $tax && 'product_tag' !== $tax && 0 !== strpos( $tax, 'pa_' ) ) {
			return $term;
		}
		$en = self::phrase( (string) $term->name );
		if ( $en !== $term->name ) {
			$term       = clone $term;
			$term->name = $en;
		}
		return $term;
	}

	public static function menus( $locations ) {
		$locations = is_array( $locations ) ? $locations : array();
		foreach ( VLG_Settings::get()['menus'] as $loc => $id ) {
			if ( isset( $locations[ $loc ] ) || array_key_exists( $loc, (array) get_registered_nav_menus() ) ) {
				$locations[ $loc ] = (int) $id;
			}
		}
		return $locations;
	}

	/* ---------------------------------------------------------------------
	 * Switch and hreflang
	 * ------------------------------------------------------------------- */

	private static function active(): bool {
		return VLG_Settings::get()['on'] && ! VLG_Router::killed() && ! is_admin();
	}

	public static function switch_html( string $class = '' ): string {
		$v  = VLG_Router::versions();
		$en = VLG_Router::is_en();
		// The thank-you page of an English order moves to /en/ (VLG_Orders); the Greek link says it stays.
		if ( $en && function_exists( 'is_order_received_page' ) && ( is_order_received_page() || is_checkout_pay_page() ) ) {
			$v['el'] = add_query_arg( 'vlg', 'el', $v['el'] );
		}
		$out = '<nav class="vlg-switch' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '" aria-label="Language / Γλώσσα">';
		$out .= $en ? '<a href="' . esc_url( $v['el'] ) . '" hreflang="el" lang="el">EL</a>' : '<span class="is-current" aria-current="true"><b>EL</b></span>';
		$out .= $en ? '<span class="is-current" aria-current="true"><b>EN</b></span>' : '<a href="' . esc_url( $v['en'] ) . '" hreflang="en" lang="en">EN</a>';
		return $out . '</nav>';
	}

	public static function shortcode(): string {
		if ( ! self::active() ) {
			return '';
		}
		wp_enqueue_style( 'vlg-front' );
		return self::switch_html();
	}

	public static function assets(): void {
		wp_register_style( 'vlg-front', VLG_URL . 'assets/front.css', array(), VLG_VERSION );
		if ( ! self::active() ) {
			return;
		}
		$float = self::show_float();
		$geo   = ! $float && self::show_geo();
		if ( $float || $geo || VLG_Router::is_en() ) {
			wp_enqueue_style( 'vlg-front' );
		}
		if ( $geo ) {
			wp_enqueue_script( 'vlg-geo', VLG_URL . 'assets/geo.js', array(), VLG_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		}
	}

	/**
	 * True when the switch goes out hidden, for assets/geo.js to show it to
	 * a visitor outside Greece (setting 'abroad'). The page HTML is the same
	 * for every visitor, so page caches stay correct.
	 */
	private static function show_geo(): bool {
		$s = VLG_Settings::get();
		return $s['abroad'] && 'float' !== $s['switch'];
	}

	/**
	 * True when the floating switch shows on this request: every page
	 * ('float'), or only a single product with English texts ('products').
	 * Filter vlg_show_switch( bool $show, int $post_id ) has the last word.
	 */
	private static function show_float(): bool {
		$mode = VLG_Settings::get()['switch'];
		$id   = is_singular() ? (int) get_queried_object_id() : 0;
		$show = 'float' === $mode;
		if ( 'products' === $mode ) {
			$show = $id > 0 && function_exists( 'is_product' ) && is_product() && self::has_any( $id );
		}
		return (bool) apply_filters( 'vlg_show_switch', $show, $id );
	}

	public static function float_switch(): void {
		try {
			if ( ! self::active() ) {
				return;
			}
			if ( self::show_float() ) {
				echo self::switch_html( 'vlg-float' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in switch_html().
			} elseif ( self::show_geo() ) {
				echo str_replace( '<nav ', '<nav hidden data-vlg-geo ', self::switch_html( 'vlg-float' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in switch_html().
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** hreflang el / en / x-default where the page has English texts. */
	public static function hreflang(): void {
		try {
			if ( ! self::active() || ! VLG_Settings::get()['hreflang'] || ! is_singular() || ! self::has_any( (int) get_queried_object_id() ) ) {
				return;
			}
			$v = VLG_Router::versions();
			printf( "<link rel=\"alternate\" hreflang=\"el\" href=\"%s\" />\n", esc_url( $v['el'] ) );
			printf( "<link rel=\"alternate\" hreflang=\"en\" href=\"%s\" />\n", esc_url( $v['en'] ) );
			printf( "<link rel=\"alternate\" hreflang=\"x-default\" href=\"%s\" />\n", esc_url( $v['el'] ) );
		} catch ( \Throwable $e ) {
			return;
		}
	}
}

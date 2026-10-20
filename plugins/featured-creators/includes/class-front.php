<?php
/**
 * CRT_Front — everything the visitor sees (Bible §14).
 *
 *  - Creator page: classic themes get templates/single-creator.php
 *    (get_header / get_footer, so the theme's container and spacing stay,
 *    e.g. Astra); block themes get the block template
 *    featured-creators//single-nox_creator (WP 6.7+) with the
 *    noxpress/creator block, or, before WP 6.7, the page inside the
 *    theme's own single template (the_content).
 *  - Links: rows of the role attributes in "Additional information"
 *    (woocommerce_display_product_attributes) — a name becomes a link when
 *    its creator is public.
 *  - Creator box on the product: end of the Description tab (its callback
 *    is wrapped), after the tabs, or [nox_creator_box] / block only.
 *  - Creator tab on the product (1.1.0): the public creators of the "tab"
 *    roles with photo, short biography, links and their latest press
 *    items; the title follows the creator's gender and the role
 *    («Ο συγγραφέας», «Η εικονογράφος», «Οι συγγραφείς»).
 *  - Index: [nox_creators] / block, role filter by link (?crt_role=…) and,
 *    with JavaScript, without reload; no AJAX.
 *
 * Rules: test mode after activation (shop managers only); 'off' hides
 * everything; CRT_DISABLE stops every hook; creator pages answer 404 to
 * anyone who may not see them; never in admin, AJAX, REST, feeds, embeds,
 * cron, CLI or XML-RPC; every callback in try/catch — on an error the
 * plugin stops for the rest of the request and the original output stays.
 * CSS only where something of ours shows, scoped to our own classes (the
 * product cards stay the theme's).
 */

defined( 'ABSPATH' ) || exit;

final class CRT_Front {

	const SC_INDEX = 'nox_creators';
	const SC_BOX   = 'nox_creator_box';
	const QV_ROLE  = 'crt_role';

	/** Per-request state. */
	private static $broken    = false;
	private static $css       = false;
	private static $js        = false;
	private static $in_render = false;
	private static $box_done  = array();
	private static $tab_cbs   = array();
	private static $formats   = array();

	public static function init(): void {
		add_shortcode( self::SC_INDEX, array( __CLASS__, 'shortcode_index' ) );
		add_shortcode( self::SC_BOX, array( __CLASS__, 'shortcode_box' ) );
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
		add_action( 'init', array( __CLASS__, 'register_block_template' ), 20 );

		add_action( 'template_redirect', array( __CLASS__, 'gate_page' ), 5 );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 50 );
		add_filter( 'the_content', array( __CLASS__, 'content_fallback' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );

		add_filter( 'woocommerce_display_product_attributes', array( __CLASS__, 'attribute_links' ), 20, 2 );
		add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'tabs' ), 98 );
		add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'creator_tab' ), 98 );
		add_action( 'woocommerce_after_single_product_summary', array( __CLASS__, 'box_after_tabs' ), 12 );

		// Sitemaps list creators only when everyone may see them.
		add_filter( 'wp_sitemaps_post_types', array( __CLASS__, 'core_sitemaps' ) );
		add_filter( 'rank_math/sitemap/exclude_post_type', array( __CLASS__, 'rank_math_sitemap' ), 10, 2 );
	}

	/* =====================================================================
	 * Gating
	 * =================================================================== */

	/** Whether the visitor sees anything of the plugin now. */
	public static function visible(): bool {
		if ( self::$broken || Featured_Creators::killed() || ! self::context_ok() ) {
			return false;
		}
		$mode = CRT_Settings::get()['mode'];
		if ( 'live' === $mode ) {
			return true;
		}
		if ( 'test' === $mode ) {
			return did_action( 'set_current_user' ) && current_user_can( 'manage_woocommerce' );
		}
		return false;
	}

	/** A front-end page view. */
	private static function context_ok(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( did_action( 'parse_query' ) && ( is_feed() || is_embed() ) ) {
			return false;
		}
		return true;
	}

	/** Stops the plugin for the rest of the request (Bible §14.6). */
	private static function fail(): void {
		self::$broken = true;
	}

	/** Creator pages: 404 for anyone who may not see them. */
	public static function gate_page(): void {
		try {
			if ( ! is_singular( CRT_CPT::PT ) || self::visible() ) {
				return;
			}
			// Logged-in editors keep WordPress's own preview of drafts.
			if ( is_preview() && current_user_can( 'edit_post', get_queried_object_id() ) ) {
				return;
			}
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		} catch ( \Throwable $e ) {
			self::fail();
		}
	}

	public static function core_sitemaps( $types ) {
		if ( is_array( $types ) && 'live' !== CRT_Settings::get()['mode'] ) {
			unset( $types[ CRT_CPT::PT ] );
		}
		return $types;
	}

	public static function rank_math_sitemap( $exclude, $type ) {
		return CRT_CPT::PT === $type && 'live' !== CRT_Settings::get()['mode'] ? true : $exclude;
	}

	/* =====================================================================
	 * Assets
	 * =================================================================== */

	public static function assets(): void {
		try {
			if ( ! self::visible() ) {
				return;
			}
			$need = false;
			if ( is_singular( CRT_CPT::PT ) ) {
				$need = true;
			} elseif ( is_singular() ) {
				$post = get_post();
				if ( $post instanceof WP_Post && ( has_shortcode( $post->post_content, self::SC_INDEX ) || has_shortcode( $post->post_content, self::SC_BOX ) || has_block( 'noxpress/creators', $post ) || has_block( 'noxpress/creator-box', $post ) ) ) {
					$need = true;
				}
				if ( function_exists( 'is_product' ) && is_product() ) {
					$need = (bool) CRT_Match::product_creators( (int) get_queried_object_id() );
				}
			}
			if ( $need ) {
				self::css();
			}
		} catch ( \Throwable $e ) {
			self::fail();
		}
	}

	/** Our stylesheet, once (late calls print it in the footer). */
	private static function css(): void {
		if ( self::$css ) {
			return;
		}
		self::$css = true;
		wp_enqueue_style( 'crt-front', CRT_URL . 'assets/front.css', array(), CRT_VERSION );
	}

	private static function js(): void {
		if ( self::$js ) {
			return;
		}
		self::$js = true;
		wp_enqueue_script( 'crt-index', CRT_URL . 'assets/index.js', array(), CRT_VERSION, true );
	}

	/* =====================================================================
	 * Creator page
	 * =================================================================== */

	/** Classic themes: our template, unless the theme has its own. */
	public static function template_include( $template ) {
		try {
			if ( ! is_singular( CRT_CPT::PT ) || ! self::visible() || wp_is_block_theme() ) {
				return $template;
			}
			if ( is_string( $template ) && 'single-' . CRT_CPT::PT . '.php' === basename( $template ) ) {
				return $template; // The theme's own template wins.
			}
			add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
			return CRT_PATH . 'templates/single-creator.php';
		} catch ( \Throwable $e ) {
			self::fail();
			return $template;
		}
	}

	/** Marks pages printed with our classic template (front.css: full width). */
	public static function body_class( $classes ) {
		if ( is_array( $classes ) ) {
			$classes[] = 'crt-creator-page';
		}
		return $classes;
	}

	/** Block themes (WP 6.7+): a block template the Site Editor can change. */
	public static function register_block_template(): void {
		if ( ! function_exists( 'register_block_template' ) || ! wp_is_block_theme() ) {
			return;
		}
		register_block_template(
			'featured-creators//single-' . CRT_CPT::PT,
			array(
				'title'       => __( 'Σελίδα δημιουργού', 'featured-creators' ),
				'description' => __( 'Η σελίδα ενός δημιουργού (Featured Creators).', 'featured-creators' ),
				'post_types'  => array( CRT_CPT::PT ),
				'content'     => '<!-- wp:template-part {"slug":"header","area":"header","tagName":"header"} /-->'
					. '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group">'
					. '<!-- wp:noxpress/creator {"align":"wide"} /-->'
					. '</main><!-- /wp:group -->'
					. '<!-- wp:template-part {"slug":"footer","area":"footer","tagName":"footer"} /-->',
			)
		);
	}

	/**
	 * Block themes before WP 6.7 (no plugin block templates): the page goes
	 * inside the theme's single template, without our own title.
	 */
	public static function content_fallback( $content ) {
		if ( self::$in_render || function_exists( 'register_block_template' ) || ! wp_is_block_theme() ) {
			return $content;
		}
		try {
			if ( ! is_singular( CRT_CPT::PT ) || ! in_the_loop() || ! is_main_query() || get_the_ID() !== get_queried_object_id() || ! self::visible() ) {
				return $content;
			}
			self::css();
			return self::render_page( (int) get_the_ID(), false, (string) $content );
		} catch ( \Throwable $e ) {
			self::fail();
			return $content;
		}
	}

	/**
	 * The whole creator page.
	 *
	 * @param bool        $title Print the name as h1.
	 * @param string|null $bio   Biography HTML already filtered (null = filter it here).
	 */
	public static function render_page( int $cid, bool $title = true, ?string $bio = null ): string {
		$post = get_post( $cid );
		if ( ! $post instanceof WP_Post || CRT_CPT::PT !== $post->post_type ) {
			return '';
		}
		if ( post_password_required( $post ) ) {
			return get_the_password_form( $post );
		}
		$s    = CRT_Settings::get();
		$name = get_the_title( $post );

		if ( null === $bio ) {
			self::$in_render = true;
			$bio             = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core filter.
			$bio             = str_replace( ']]>', ']]&gt;', (string) $bio );
			self::$in_render = false;
		}

		$h  = '<div class="crt-root crt-page" style="padding-top:' . esc_attr( $s['top'] ) . '">';
		$h .= '<header class="crt-head">';
		if ( has_post_thumbnail( $post ) ) {
			$h .= '<div class="crt-head__photo">' . get_the_post_thumbnail(
				$post,
				'medium',
				array(
					'class'   => 'crt-head__img',
					'alt'     => $name,
					'loading' => 'eager',
				)
			) . '</div>';
		}
		$h .= '<div class="crt-head__id">';
		if ( $title ) {
			$h .= '<h1 class="crt-head__name">' . esc_html( $name ) . '</h1>';
		}
		$roles = self::roles_line( $cid );
		if ( '' !== $roles ) {
			$h .= '<p class="crt-head__roles">' . esc_html( $roles ) . '</p>';
		}
		$h .= self::links_html( $cid );
		$h .= '</div></header>';

		if ( '' !== trim( wp_strip_all_tags( $bio, true ) ) || false !== strpos( $bio, '<img' ) ) {
			$h .= '<div class="crt-bio entry-content">' . $bio . '</div>';
		}

		$prod = CRT_Match::creator_products( $cid );
		foreach ( CRT_Settings::role_taxes() as $tax ) {
			if ( empty( $prod['roles'][ $tax ] ) || ! CRT_Settings::has_section( $tax ) ) {
				continue;
			}
			$h .= '<section class="crt-sec crt-sec--role" data-role="' . esc_attr( $tax ) . '">';
			$h .= '<h2 class="crt-h">' . esc_html( CRT_Settings::role_text( $tax, 'h' ) ) . '</h2>';
			$h .= self::loop( $prod['roles'][ $tax ], $prod['formats'] );
			$h .= '</section>';
		}
		if ( ! empty( $prod['bundles'] ) ) {
			$h .= '<section class="crt-sec crt-sec--bundles">';
			$h .= '<h2 class="crt-h">' . esc_html( CRT_Settings::text( 't_bundles' ) ) . '</h2>';
			$h .= self::loop( $prod['bundles'], $prod['formats'] );
			$h .= '</section>';
		}

		$h .= self::press_html( $cid );
		$h .= '</div>';
		return $h;
	}

	/** "Poet, musician" or the names of the roles with products. */
	public static function roles_line( int $cid ): string {
		$text = trim( (string) get_post_meta( $cid, CRT_CPT::M_ROLES_TEXT, true ) );
		return '' !== $text ? $text : self::roles_line_auto( $cid );
	}

	/** The names of the roles where the creator has published products. */
	public static function roles_line_auto( int $cid ): string {
		$names = array();
		foreach ( CRT_CPT::json_meta( $cid, CRT_CPT::M_ROLES ) as $tax ) {
			if ( is_string( $tax ) && null !== CRT_Settings::role( $tax ) ) {
				$names[] = CRT_Settings::role_text( $tax, 'l' );
			}
		}
		return implode( ' · ', $names );
	}

	/**
	 * Products as the theme's own WooCommerce cards.
	 *
	 * @param int[]           $ids
	 * @param array<int, int> $formats pid => number of formats.
	 */
	private static function loop( array $ids, array $formats ): string {
		global $post;
		$orig = $post;
		$cols = (int) CRT_Settings::get()['columns'];

		self::$formats = $formats;
		add_action( 'woocommerce_after_shop_loop_item', array( __CLASS__, 'formats_line' ), 9 );

		wc_setup_loop(
			array(
				'name'         => 'crt_creator',
				'columns'      => $cols,
				'is_shortcode' => true,
				'is_paginated' => false,
				'total'        => count( $ids ),
				'per_page'     => count( $ids ),
			)
		);
		ob_start();
		echo '<div class="woocommerce columns-' . (int) $cols . ' crt-products">';
		woocommerce_product_loop_start();
		foreach ( $ids as $id ) {
			$post = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restored below.
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			setup_postdata( $post );
			wc_get_template_part( 'content', 'product' );
		}
		woocommerce_product_loop_end();
		echo '</div>';
		$html = (string) ob_get_clean();

		$post = $orig; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- restore.
		if ( $orig instanceof WP_Post ) {
			setup_postdata( $orig );
		} else {
			wp_reset_postdata();
		}
		wc_reset_loop();
		remove_action( 'woocommerce_after_shop_loop_item', array( __CLASS__, 'formats_line' ), 9 );
		self::$formats = array();
		return $html;
	}

	/** "In 3 formats" under a card that stands for a whole work. */
	public static function formats_line(): void {
		$id = (int) get_the_ID();
		if ( isset( self::$formats[ $id ] ) ) {
			echo '<span class="crt-formats">' . esc_html( str_replace( '{n}', (string) (int) self::$formats[ $id ], CRT_Settings::text( 't_formats' ) ) ) . '</span>';
		}
	}

	/* ---------------------------------------------------------------------
	 * Links (site, music services, social networks)
	 * ------------------------------------------------------------------- */

	/** Known services: host fragment => [ label, icon ]. */
	private static function services(): array {
		return array(
			'open.spotify.com' => array( 'Spotify', 'music' ),
			'spotify.com'      => array( 'Spotify', 'music' ),
			'music.apple.com'  => array( 'Apple Music', 'music' ),
			'bandcamp.com'     => array( 'Bandcamp', 'music' ),
			'soundcloud.com'   => array( 'SoundCloud', 'music' ),
			'deezer.com'       => array( 'Deezer', 'music' ),
			'tidal.com'        => array( 'TIDAL', 'music' ),
			'music.youtube.com' => array( 'YouTube Music', 'music' ),
			'youtube.com'      => array( 'YouTube', 'video' ),
			'youtu.be'         => array( 'YouTube', 'video' ),
			'vimeo.com'        => array( 'Vimeo', 'video' ),
			'instagram.com'    => array( 'Instagram', 'social' ),
			'facebook.com'     => array( 'Facebook', 'social' ),
			'tiktok.com'       => array( 'TikTok', 'social' ),
			'x.com'            => array( 'X', 'social' ),
			'twitter.com'      => array( 'X', 'social' ),
			'bsky.app'         => array( 'Bluesky', 'social' ),
			'threads.net'      => array( 'Threads', 'social' ),
			'threads.com'      => array( 'Threads', 'social' ),
			'linkedin.com'     => array( 'LinkedIn', 'social' ),
			'goodreads.com'    => array( 'Goodreads', 'book' ),
		);
	}

	/** [ label, icon ] of a URL. */
	public static function service( string $url ): array {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = preg_replace( '/^(www\.|m\.)/', '', $host );
		foreach ( self::services() as $frag => $info ) {
			if ( $host === $frag || ( strlen( $host ) > strlen( $frag ) && substr( $host, -strlen( $frag ) - 1 ) === '.' . $frag ) ) {
				return $info;
			}
		}
		return array( (string) $host, 'web' );
	}

	/** Inline SVG icons (no icon fonts, Bible §14.13). */
	private static function icon( string $kind ): string {
		$paths = array(
			'web'    => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
			'music'  => '<path d="M9 18V5l11-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="17" cy="16" r="3"/>',
			'video'  => '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M10 9l5 3-5 3z"/>',
			'social' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
			'book'   => '<path d="M4 4h7a3 3 0 0 1 3 3v13a2 2 0 0 0-2-2H4zM20 4h-5a3 3 0 0 0-3 3"/>',
		);
		$p = isset( $paths[ $kind ] ) ? $paths[ $kind ] : $paths['web'];
		return '<svg class="crt-ico" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
	}

	public static function links_html( int $cid ): string {
		$links = CRT_CPT::links( $cid );
		if ( ! $links ) {
			return '';
		}
		$h = '<p class="crt-links">';
		foreach ( $links as $l ) {
			list( $label, $kind ) = self::service( $l['url'] );
			if ( '' !== $l['label'] ) {
				$label = $l['label'];
			}
			$h .= '<a class="crt-link" href="' . esc_url( $l['url'] ) . '" target="_blank" rel="me noopener noreferrer">' . self::icon( $kind ) . '<span>' . esc_html( $label ) . '</span></a>';
		}
		return $h . '</p>';
	}

	/* ---------------------------------------------------------------------
	 * Interviews, videos, reviews
	 * ------------------------------------------------------------------- */

	private static function press_html( int $cid ): string {
		$items = CRT_CPT::press( $cid );
		if ( ! $items ) {
			return '';
		}
		$titles = array(
			'interview' => CRT_Settings::text( 't_interviews' ),
			'video'     => CRT_Settings::text( 't_videos' ),
			'review'    => CRT_Settings::text( 't_reviews' ),
		);
		$h = '';
		foreach ( $titles as $type => $title ) {
			$rows = '';
			foreach ( $items as $it ) {
				if ( $it['type'] !== $type ) {
					continue;
				}
				$rows .= '<div class="crt-item">';
				if ( $it['image'] ) {
					$img = wp_get_attachment_image( $it['image'], 'thumbnail', false, array( 'class' => 'crt-item__img', 'alt' => '' ) );
					$rows .= '' !== $it['url'] ? '<a class="crt-item__thumb" href="' . esc_url( $it['url'] ) . '" target="_blank" rel="noopener noreferrer" tabindex="-1" aria-hidden="true">' . $img . '</a>' : '<span class="crt-item__thumb">' . $img . '</span>';
				}
				$rows .= '<div class="crt-item__body">';
				$meta  = array();
				if ( '' !== $it['date'] ) {
					$ts     = strtotime( $it['date'] . ' 12:00:00' );
					$meta[] = '<time class="crt-item__date" datetime="' . esc_attr( $it['date'] ) . '">' . esc_html( date_i18n( (string) get_option( 'date_format' ), $ts ) ) . '</time>';
				}
				if ( '' !== $it['outlet'] ) {
					$meta[] = '<span class="crt-item__outlet">' . esc_html( $it['outlet'] ) . '</span>';
				}
				if ( $meta ) {
					$rows .= '<span class="crt-item__meta">' . implode( ' · ', $meta ) . '</span>';
				}
				$label = '' !== $it['title'] ? $it['title'] : (string) wp_parse_url( $it['url'], PHP_URL_HOST );
				$rows .= '' !== $it['url']
					? '<a class="crt-item__title" href="' . esc_url( $it['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>'
					: '<span class="crt-item__title">' . esc_html( $label ) . '</span>';
				$rows .= '</div></div>';
			}
			if ( '' !== $rows ) {
				$h .= '<section class="crt-sec crt-press crt-press--' . esc_attr( $type ) . '"><h2 class="crt-h">' . esc_html( $title ) . '</h2><div class="crt-list">' . $rows . '</div></section>';
			}
		}
		return $h;
	}

	/* =====================================================================
	 * Links in "Additional information"
	 * =================================================================== */

	/**
	 * Rebuilds the value of a role row the way WooCommerce builds it
	 * (wc_display_product_attributes), with creator links. Rows without a
	 * public creator stay byte-identical.
	 *
	 * @param array      $rows    key => { label, value }.
	 * @param WC_Product $product
	 */
	public static function attribute_links( $rows, $product ) {
		if ( ! is_array( $rows ) || ! $product instanceof WC_Product || ! CRT_Settings::get()['links'] || ! self::visible() ) {
			return $rows;
		}
		try {
			$taxes = CRT_Settings::role_taxes();
			foreach ( $product->get_attributes() as $attribute ) {
				if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->is_taxonomy() || ! in_array( $attribute->get_name(), $taxes, true ) ) {
					continue;
				}
				$key = 'attribute_' . sanitize_title_with_dashes( $attribute->get_name() );
				if ( ! isset( $rows[ $key ] ) || ! is_array( $rows[ $key ] ) ) {
					continue;
				}
				$terms = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'all' ) );
				$tax   = $attribute->get_taxonomy_object();
				$any   = false;
				$values = array();
				foreach ( $terms as $t ) {
					$name = esc_html( $t->name );
					$cid  = CRT_Match::owner( (int) $t->term_id );
					if ( CRT_CPT::is_public( $cid ) ) {
						$any      = true;
						$values[] = '<a href="' . esc_url( get_permalink( $cid ) ) . '" class="crt-attr-link">' . $name . '</a>';
					} elseif ( $tax && ! empty( $tax->attribute_public ) ) {
						$values[] = '<a href="' . esc_url( get_term_link( $t->term_id, $attribute->get_name() ) ) . '" rel="tag">' . $name . '</a>';
					} else {
						$values[] = $name;
					}
				}
				if ( $any ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WooCommerce's own filter, applied as WooCommerce applies it.
					$rows[ $key ]['value'] = apply_filters( 'woocommerce_attribute', wpautop( wptexturize( implode( ', ', $values ) ) ), $attribute, $values );
				}
			}
		} catch ( \Throwable $e ) {
			self::fail();
		}
		return $rows;
	}

	/* =====================================================================
	 * Creator box on the product
	 * =================================================================== */

	/** Creators for the box: public, in a "box" role, at most box_max. */
	public static function box_creators( int $product_id ): array {
		$s   = CRT_Settings::get();
		$box = array();
		foreach ( $s['roles'] as $r ) {
			if ( $r['box'] ) {
				$box[] = $r['tax'];
			}
		}
		$out = array();
		foreach ( CRT_Match::product_creators( $product_id ) as $c ) {
			$roles = array_values( array_intersect( $c['roles'], $box ) );
			if ( ! $roles ) {
				continue;
			}
			$c['roles'] = $roles;
			$out[]      = $c;
			if ( count( $out ) >= $s['box_max'] ) {
				break;
			}
		}
		return $out;
	}

	public static function render_box( int $product_id ): string {
		$list = self::box_creators( $product_id );
		if ( ! $list ) {
			return '';
		}
		self::css();
		$title = CRT_Settings::text( 't_box' );
		$more  = CRT_Settings::text( 't_more' );
		$h     = '<div class="crt-root crt-box">';
		if ( '' !== $title ) {
			$h .= '<h2 class="crt-box__title">' . esc_html( $title ) . '</h2>';
		}
		$h .= '<div class="crt-box__cards">';
		foreach ( $list as $c ) {
			$roles = array();
			foreach ( $c['roles'] as $tax ) {
				$roles[] = CRT_Settings::role_text( $tax, 'l' );
			}
			$h .= '<div class="crt-card">';
			$h .= '<a class="crt-card__img" href="' . esc_url( $c['url'] ) . '" tabindex="-1" aria-hidden="true">' . self::avatar( (int) $c['id'] ) . '</a>';
			$h .= '<div class="crt-card__body">';
			$h .= '<a class="crt-card__name" href="' . esc_url( $c['url'] ) . '">' . esc_html( $c['name'] ) . '</a>';
			$h .= '<span class="crt-card__role">' . esc_html( implode( ' · ', $roles ) ) . '</span>';
			$bio = self::short_bio( (int) $c['id'] );
			if ( '' !== $bio ) {
				$h .= '<span class="crt-card__bio">' . esc_html( $bio ) . '</span>';
			}
			$h .= '<a class="crt-card__more" href="' . esc_url( $c['url'] ) . '">' . esc_html( $more ) . ' <span aria-hidden="true">→</span></a>';
			$h .= '</div></div>';
		}
		$h .= '</div></div>';
		return $h;
	}

	/** The photo, or an empty circle with the initial. */
	private static function avatar( int $cid ): string {
		if ( has_post_thumbnail( $cid ) ) {
			return get_the_post_thumbnail(
				$cid,
				'thumbnail',
				array(
					'class'   => 'crt-avatar',
					'alt'     => '',
					'loading' => 'lazy',
				)
			);
		}
		$name    = (string) get_the_title( $cid );
		$initial = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 );
		return '<span class="crt-avatar crt-avatar--empty">' . esc_html( $initial ) . '</span>';
	}

	/** Excerpt, else the start of the biography (plain text). */
	public static function short_bio( int $cid ): string {
		$p = get_post( $cid );
		if ( ! $p instanceof WP_Post ) {
			return '';
		}
		$text = '' !== trim( $p->post_excerpt ) ? $p->post_excerpt : excerpt_remove_blocks( strip_shortcodes( $p->post_content ) );
		return wp_trim_words( wp_strip_all_tags( $text, true ), 30, '…' );
	}

	/** Wraps the Description tab: original content, then the box. */
	public static function tabs( $tabs ) {
		if ( ! is_array( $tabs ) || ! CRT_Settings::get()['box'] || 'description' !== CRT_Settings::get()['box_pos'] || ! isset( $tabs['description']['callback'] ) || ! self::visible() ) {
			return $tabs;
		}
		try {
			$pid = (int) get_the_ID();
			if ( ! self::box_creators( $pid ) ) {
				return $tabs;
			}
			self::$tab_cbs[ $pid ]         = $tabs['description']['callback'];
			$tabs['description']['callback'] = array( __CLASS__, 'description_tab' );
		} catch ( \Throwable $e ) {
			self::fail();
		}
		return $tabs;
	}

	/** The wrapped Description tab callback. */
	public static function description_tab( $key = 'description', $tab = array() ): void {
		$pid = (int) get_the_ID();
		$cb  = isset( self::$tab_cbs[ $pid ] ) ? self::$tab_cbs[ $pid ] : 'woocommerce_product_description_tab';
		if ( is_callable( $cb ) ) {
			call_user_func( $cb, $key, $tab );
		}
		self::print_box_once( $pid );
	}

	/**
	 * After the tabs: the 'after' position, and the fallback of the
	 * 'description' position when the product has no Description tab.
	 */
	public static function box_after_tabs(): void {
		$s = CRT_Settings::get();
		if ( ! $s['box'] || 'none' === $s['box_pos'] || ! self::visible() ) {
			return;
		}
		$pid = (int) get_the_ID();
		if ( 'description' === $s['box_pos'] && isset( self::$tab_cbs[ $pid ] ) ) {
			return; // Printed inside the tab.
		}
		self::print_box_once( $pid );
	}

	private static function print_box_once( int $pid ): void {
		if ( isset( self::$box_done[ $pid ] ) || ! self::visible() ) {
			return;
		}
		try {
			$html = self::render_box( $pid );
			if ( '' !== $html ) {
				self::$box_done[ $pid ] = true;
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render_box().
			}
		} catch ( \Throwable $e ) {
			self::fail();
		}
	}

	/* =====================================================================
	 * Creator tab on the product (1.1.0)
	 * =================================================================== */

	/** Creators for the tab: public, in a "tab" role, at most 6. */
	public static function tab_creators( int $product_id ): array {
		$taxes = CRT_Settings::tab_taxes();
		if ( ! $taxes ) {
			return array();
		}
		$out = array();
		foreach ( CRT_Match::product_creators( $product_id ) as $c ) {
			$roles = array_values( array_intersect( $c['roles'], $taxes ) );
			if ( ! $roles ) {
				continue;
			}
			$c['roles'] = $roles;
			$out[]      = $c;
			if ( count( $out ) >= 6 ) {
				break;
			}
		}
		return $out;
	}

	/** Lower case (multibyte safe), for a role name inside a sentence. */
	private static function lower( string $v ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $v, 'UTF-8' ) : strtolower( $v );
	}

	/** First letter in upper case (multibyte safe). */
	private static function ucfirst( string $v ): string {
		if ( ! function_exists( 'mb_substr' ) ) {
			return ucfirst( $v );
		}
		return mb_strtoupper( mb_substr( $v, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $v, 1, null, 'UTF-8' );
	}

	/**
	 * «Ο συγγραφέας» / «Η εικονογράφος» / «Συγγραφέας» for one creator,
	 * «Οι συγγραφείς» for several in the same first role, else «Οι δημιουργοί».
	 */
	public static function tab_title( array $list ): string {
		if ( 1 === count( $list ) ) {
			$g    = CRT_CPT::gender( (int) $list[0]['id'] );
			$key  = 'm' === $g ? 't_tab_m' : ( 'f' === $g ? 't_tab_f' : 't_tab_n' );
			$role = self::lower( CRT_Settings::role_text( $list[0]['roles'][0], 'l' ) );
			return self::ucfirst( str_replace( '{role}', $role, CRT_Settings::text( $key ) ) );
		}
		$first = array_unique(
			array_map(
				static function ( $c ) {
					return $c['roles'][0];
				},
				$list
			)
		);
		if ( 1 === count( $first ) ) {
			$roles = self::lower( CRT_Settings::role_text( reset( $first ), 'p' ) );
			return self::ucfirst( str_replace( '{roles}', $roles, CRT_Settings::text( 't_tab_many' ) ) );
		}
		return CRT_Settings::text( 't_tab_mixed' );
	}

	/** Adds the tab when the product has creators for it. */
	public static function creator_tab( $tabs ) {
		$s = CRT_Settings::get();
		if ( ! is_array( $tabs ) || ! $s['tab'] || ! self::visible() ) {
			return $tabs;
		}
		try {
			$list = self::tab_creators( (int) get_the_ID() );
			if ( ! $list ) {
				return $tabs;
			}
			$tabs['crt_creators'] = array(
				'title'    => self::tab_title( $list ),
				'priority' => (int) $s['tab_priority'],
				'callback' => array( __CLASS__, 'creator_tab_content' ),
			);
		} catch ( \Throwable $e ) {
			self::fail();
		}
		return $tabs;
	}

	/** The tab callback. */
	public static function creator_tab_content( $key = 'crt_creators', $tab = array() ): void {
		if ( ! self::visible() ) {
			return;
		}
		try {
			echo self::render_tab( (int) get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render_tab().
		} catch ( \Throwable $e ) {
			self::fail();
		}
	}

	public static function render_tab( int $product_id ): string {
		$list = self::tab_creators( $product_id );
		if ( ! $list ) {
			return '';
		}
		self::css();
		$more = CRT_Settings::text( 't_more' );
		$h    = '<div class="crt-root crt-tab">';
		foreach ( $list as $c ) {
			$cid = (int) $c['id'];
			$h  .= '<section class="crt-tab__person">';
			$h  .= '<a class="crt-tab__photo" href="' . esc_url( $c['url'] ) . '" tabindex="-1" aria-hidden="true">' . self::avatar( $cid ) . '</a>';
			$h  .= '<div class="crt-tab__body">';
			$h  .= '<h3 class="crt-tab__name"><a href="' . esc_url( $c['url'] ) . '">' . esc_html( $c['name'] ) . '</a></h3>';
			$roles = self::roles_line( $cid );
			if ( '' !== $roles ) {
				$h .= '<p class="crt-tab__roles">' . esc_html( $roles ) . '</p>';
			}
			$bio = self::tab_bio( $cid );
			if ( '' !== $bio ) {
				$h .= '<p class="crt-tab__bio">' . esc_html( $bio ) . '</p>';
			}
			$h .= self::links_html( $cid );
			$h .= self::tab_press( $cid );
			$h .= '<p class="crt-tab__more"><a class="crt-card__more" href="' . esc_url( $c['url'] ) . '">' . esc_html( $more ) . ' <span aria-hidden="true">→</span></a></p>';
			$h .= '</div></section>';
		}
		return $h . '</div>';
	}

	/** Excerpt, else the start of the biography: about 60 words. */
	private static function tab_bio( int $cid ): string {
		$p = get_post( $cid );
		if ( ! $p instanceof WP_Post ) {
			return '';
		}
		$text = '' !== trim( $p->post_excerpt ) ? $p->post_excerpt : excerpt_remove_blocks( strip_shortcodes( $p->post_content ) );
		return wp_trim_words( wp_strip_all_tags( $text, true ), 60, '…' );
	}

	/** The 3 newest press items, as a short list. */
	private static function tab_press( int $cid ): string {
		$items = array_slice( CRT_CPT::press( $cid ), 0, 3 );
		if ( ! $items ) {
			return '';
		}
		$h = '<ul class="crt-tab__press">';
		foreach ( $items as $it ) {
			$meta = array();
			if ( '' !== $it['date'] ) {
				$meta[] = '<time datetime="' . esc_attr( $it['date'] ) . '">' . esc_html( date_i18n( (string) get_option( 'date_format' ), (int) strtotime( $it['date'] . ' 12:00:00' ) ) ) . '</time>';
			}
			if ( '' !== $it['outlet'] ) {
				$meta[] = esc_html( $it['outlet'] );
			}
			$label = '' !== $it['title'] ? $it['title'] : (string) wp_parse_url( $it['url'], PHP_URL_HOST );
			$h    .= '<li>';
			if ( $meta ) {
				$h .= '<span class="crt-item__meta">' . implode( ' · ', $meta ) . '</span> ';
			}
			$h .= '' !== $it['url']
				? '<a href="' . esc_url( $it['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>'
				: esc_html( $label );
			$h .= '</li>';
		}
		return $h . '</ul>';
	}

	/** [nox_creator_box id="123"] — id defaults to the current product. */
	public static function shortcode_box( $atts = array() ): string {
		if ( ! self::visible() ) {
			return '';
		}
		try {
			$atts = shortcode_atts( array( 'id' => 0 ), is_array( $atts ) ? $atts : array(), self::SC_BOX );
			$pid  = (int) $atts['id'] > 0 ? (int) $atts['id'] : (int) get_the_ID();
			if ( 'product' !== get_post_type( $pid ) ) {
				return '';
			}
			self::$box_done[ $pid ] = true; // No second box on the same page.
			return self::render_box( $pid );
		} catch ( \Throwable $e ) {
			self::fail();
			return '';
		}
	}

	/* =====================================================================
	 * Index
	 * =================================================================== */

	/** Sort key: script of the site's language first, then the folded name. */
	private static function sort_key( string $name ): string {
		$f     = CRT_Settings::fold( $name );
		$first = function_exists( 'mb_substr' ) ? mb_substr( $f, 0, 1 ) : substr( $f, 0, 1 );
		$greek = (bool) preg_match( '/\p{Greek}/u', $first );
		$latin = (bool) preg_match( '/[a-z]/', $first );
		$el    = 'el' === CRT_Settings::lang();
		if ( $greek ) {
			$g = $el ? '0' : '1';
		} elseif ( $latin ) {
			$g = $el ? '1' : '0';
		} else {
			$g = '2';
		}
		return $g . $f;
	}

	/** Index letter of a name ('#' for digits and symbols). */
	private static function letter( string $name ): string {
		$f     = CRT_Settings::fold( $name );
		$first = function_exists( 'mb_substr' ) ? mb_substr( $f, 0, 1 ) : substr( $f, 0, 1 );
		if ( ! preg_match( '/\p{L}/u', $first ) ) {
			return '#';
		}
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $first, 'UTF-8' ) : strtoupper( $first );
	}

	/** [nox_creators role="pa_…"] */
	public static function shortcode_index( $atts = array() ): string {
		if ( ! self::visible() ) {
			return '';
		}
		try {
			$atts = shortcode_atts( array( 'role' => '' ), is_array( $atts ) ? $atts : array(), self::SC_INDEX );
			return self::render_index( (string) $atts['role'] );
		} catch ( \Throwable $e ) {
			self::fail();
			return '';
		}
	}

	public static function render_index( string $role = '' ): string {
		$taxes = CRT_Settings::role_taxes();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter link.
		$get = isset( $_GET[ self::QV_ROLE ] ) && is_string( $_GET[ self::QV_ROLE ] ) ? sanitize_key( wp_unslash( $_GET[ self::QV_ROLE ] ) ) : '';
		if ( '' !== $get ) {
			$role = $get;
		}
		if ( ! in_array( $role, $taxes, true ) ) {
			$role = '';
		}

		$ids = get_posts(
			array(
				'post_type'        => CRT_CPT::PT,
				'post_status'      => 'publish',
				'has_password'     => false,
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		if ( ! $ids ) {
			return '';
		}
		update_postmeta_cache( $ids );
		$thumbs = array();
		foreach ( $ids as $id ) {
			$t = (int) get_post_meta( (int) $id, '_thumbnail_id', true );
			if ( $t ) {
				$thumbs[] = $t;
			}
		}
		if ( $thumbs ) {
			_prime_post_caches( $thumbs, false, true );
		}

		$people = array();
		$used   = array();
		foreach ( $ids as $id ) {
			$id    = (int) $id;
			$sort  = CRT_CPT::sort_name( $id );
			$roles = array_values( array_intersect( (array) CRT_CPT::json_meta( $id, CRT_CPT::M_ROLES ), $taxes ) );
			foreach ( $roles as $r ) {
				$used[ $r ] = true;
			}
			$people[] = array(
				'id'     => $id,
				'name'   => get_the_title( $id ),
				'key'    => self::sort_key( $sort ),
				'letter' => self::letter( $sort ),
				'roles'  => $roles,
			);
		}
		usort(
			$people,
			static function ( $a, $b ) {
				return strcmp( $a['key'], $b['key'] );
			}
		);

		self::css();
		self::js();

		$base = remove_query_arg( self::QV_ROLE );
		$h    = '<div class="crt-root crt-index" data-crt-index>';

		// Role filter: plain links (work without JS and with page caches).
		$buttons = array( '' => CRT_Settings::text( 't_all' ) );
		foreach ( $taxes as $tax ) {
			if ( isset( $used[ $tax ] ) ) {
				$buttons[ $tax ] = CRT_Settings::role_text( $tax, 'l' );
			}
		}
		if ( count( $buttons ) > 2 ) {
			$h .= '<nav class="crt-filter" aria-label="' . esc_attr( CRT_Settings::text( 't_all' ) ) . '">';
			foreach ( $buttons as $tax => $label ) {
				$url = '' === $tax ? $base : add_query_arg( self::QV_ROLE, $tax, $base );
				$on  = $tax === $role;
				$h  .= '<a class="crt-filter__btn' . ( $on ? ' is-on' : '' ) . '" href="' . esc_url( $url ) . '" data-role="' . esc_attr( $tax ) . '"' . ( $on ? ' aria-current="true"' : '' ) . '>' . esc_html( $label ) . '</a>';
			}
			$h .= '</nav>';
		}

		// Letters.
		$groups = array();
		foreach ( $people as $p ) {
			$groups[ $p['letter'] ][] = $p;
		}
		$i       = 0;
		$anchors = '';
		$body    = '';
		foreach ( $groups as $letter => $list ) {
			++$i;
			$gid   = 'crt-l-' . $i;
			$cards = '';
			$shown = 0;
			foreach ( $list as $p ) {
				$hidden = '' !== $role && ! in_array( $role, $p['roles'], true );
				if ( ! $hidden ) {
					++$shown;
				}
				$url    = get_permalink( $p['id'] );
				$labels = array();
				foreach ( $p['roles'] as $r ) {
					$labels[] = CRT_Settings::role_text( $r, 'l' );
				}
				$cards .= '<div class="crt-card crt-card--index" data-roles="' . esc_attr( implode( ' ', $p['roles'] ) ) . '"' . ( $hidden ? ' hidden' : '' ) . '>';
				$cards .= '<a class="crt-card__img" href="' . esc_url( $url ) . '" tabindex="-1" aria-hidden="true">' . self::avatar( $p['id'] ) . '</a>';
				$cards .= '<div class="crt-card__body"><a class="crt-card__name" href="' . esc_url( $url ) . '">' . esc_html( $p['name'] ) . '</a>';
				if ( $labels ) {
					$cards .= '<span class="crt-card__role">' . esc_html( implode( ' · ', $labels ) ) . '</span>';
				}
				$cards .= '</div></div>';
			}
			$anchors .= '<a class="crt-letters__a" href="#' . esc_attr( $gid ) . '" data-group="' . esc_attr( $gid ) . '"' . ( $shown ? '' : ' hidden' ) . '>' . esc_html( $letter ) . '</a>';
			$body    .= '<div class="crt-group" id="' . esc_attr( $gid ) . '"' . ( $shown ? '' : ' hidden' ) . '><h2 class="crt-group__letter">' . esc_html( $letter ) . '</h2><div class="crt-group__cards">' . $cards . '</div></div>';
		}
		if ( count( $groups ) > 1 ) {
			$h .= '<nav class="crt-letters">' . $anchors . '</nav>';
		}
		$h .= $body . '</div>';
		return $h;
	}

	/* =====================================================================
	 * Blocks (dynamic, server-rendered, same markup as the shortcodes)
	 * =================================================================== */

	public static function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script( 'crt-blocks', CRT_URL . 'assets/blocks.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components' ), CRT_VERSION, true );
		wp_localize_script(
			'crt-blocks',
			'crtBlocks',
			array(
				'indexTitle' => __( 'Δημιουργοί (Featured Creators)', 'featured-creators' ),
				'indexDesc'  => __( 'Ευρετήριο των δημοσιευμένων δημιουργών, με φίλτρο ρόλου.', 'featured-creators' ),
				'boxTitle'   => __( 'Πλαίσιο δημιουργών (Featured Creators)', 'featured-creators' ),
				'boxDesc'    => __( 'Οι δημιουργοί ενός προϊόντος, με φωτογραφία και σύνδεσμο.', 'featured-creators' ),
				'pageTitle'  => __( 'Σελίδα δημιουργού (Featured Creators)', 'featured-creators' ),
				'pageDesc'   => __( 'Ολόκληρη η σελίδα του δημιουργού: κεφαλίδα, προϊόντα ανά ρόλο, λίστες.', 'featured-creators' ),
				'roleLabel'  => __( 'Ρόλος από προεπιλογή (π.χ. pa_syggrafeis)', 'featured-creators' ),
				'idLabel'    => __( 'ID προϊόντος (κενό = το τρέχον)', 'featured-creators' ),
				'note'       => __( 'Εμφανίζεται στο site με τις ρυθμίσεις του Featured Creators.', 'featured-creators' ),
			)
		);
		register_block_type(
			'noxpress/creators',
			array(
				'api_version'     => 2,
				'editor_script'   => 'crt-blocks',
				'attributes'      => array(
					'role' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'render_callback' => array( __CLASS__, 'render_block_index' ),
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide', 'full' ),
				),
			)
		);
		register_block_type(
			'noxpress/creator-box',
			array(
				'api_version'     => 2,
				'editor_script'   => 'crt-blocks',
				'attributes'      => array(
					'productId' => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
				'uses_context'    => array( 'postId', 'postType' ),
				'render_callback' => array( __CLASS__, 'render_block_box' ),
				'supports'        => array( 'html' => false ),
			)
		);
		register_block_type(
			'noxpress/creator',
			array(
				'api_version'     => 2,
				'editor_script'   => 'crt-blocks',
				'uses_context'    => array( 'postId', 'postType' ),
				'render_callback' => array( __CLASS__, 'render_block_page' ),
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide', 'full' ),
				),
			)
		);
	}

	public static function render_block_index( $attributes = array() ): string {
		if ( Featured_Creators::killed() ) {
			return '';
		}
		$role = is_array( $attributes ) && isset( $attributes['role'] ) ? (string) $attributes['role'] : '';
		return self::shortcode_index( array( 'role' => $role ) );
	}

	public static function render_block_box( $attributes = array(), $content = '', $block = null ): string {
		if ( Featured_Creators::killed() ) {
			return '';
		}
		$pid = is_array( $attributes ) && ! empty( $attributes['productId'] ) ? (int) $attributes['productId'] : 0;
		if ( ! $pid && $block instanceof WP_Block && ! empty( $block->context['postId'] ) ) {
			$pid = (int) $block->context['postId'];
		}
		return self::shortcode_box( array( 'id' => $pid ) );
	}

	public static function render_block_page( $attributes = array(), $content = '', $block = null ): string {
		if ( Featured_Creators::killed() || ! self::visible() ) {
			return '';
		}
		try {
			$cid = $block instanceof WP_Block && ! empty( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_queried_object_id();
			if ( CRT_CPT::PT !== get_post_type( $cid ) ) {
				return '';
			}
			self::css();
			$align = is_array( $attributes ) && ! empty( $attributes['align'] ) && in_array( $attributes['align'], array( 'wide', 'full' ), true ) ? ' align' . $attributes['align'] : '';
			return '<div class="wp-block-noxpress-creator' . esc_attr( $align ) . '">' . self::render_page( $cid ) . '</div>';
		} catch ( \Throwable $e ) {
			self::fail();
			return '';
		}
	}
}

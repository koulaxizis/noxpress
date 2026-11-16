<?php
/**
 * PTB_Front — what visitors see.
 *
 *  - woocommerce_product_tabs (priority 990, after the suite's own tabs at
 *    25–98): adds the product's tabs (key ptb_<id>), the shared tabs that
 *    match it (ptb_s_<id>) and those shared by the other formats of its
 *    work (ptb_w<product>_<id>); applies the order setting to the known
 *    keys (priority, hide, new title); while "Custom Product Tabs for
 *    WooCommerce" still runs, removes its tabs (wb_cptb_*) on products
 *    already imported; drops a tab of ours that holds only [book_preview]
 *    when Book Preview shows its own tab. Unknown tabs stay as they are.
 *  - Tab content: HTML through wpautop + do_shortcode, as post content;
 *    video tabs print a poster button. The iframe is never printed by
 *    PHP: assets/front.js puts it in place on a click, from
 *    youtube-nocookie.com or player.vimeo.com (dnt=1).
 *  - [nox_video src="…" title="…"]: the same player anywhere.
 *  - Bridge: [wb_cpt_youtube_embed_shortcode video_id="…"] is registered
 *    while that plugin is inactive (setting "bridge"), so old content
 *    shows the player instead of raw text.
 *
 * Bible §14: mode off → nothing; test → shop managers only; product
 * pages only (the shortcodes also elsewhere); every callback in
 * try/catch: on an error the tabs of the page stay as they were and the
 * plugin stops for the rest of the request. Nothing is written.
 */

defined( 'ABSPATH' ) || exit;

final class PTB_Front {

	const SC_VIDEO  = 'nox_video';
	const SC_BRIDGE = 'wb_cpt_youtube_embed_shortcode';

	/** True after an error: the rest of the request runs without us. */
	private static $broken = false;

	/** Per-request cache of visible(). */
	private static $visible = null;

	public static function init(): void {
		add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'tabs' ), 990 );
		add_shortcode( self::SC_VIDEO, array( __CLASS__, 'sc_video' ) );
		add_action( 'init', array( __CLASS__, 'bridge' ), 20 );
	}

	/* =====================================================================
	 * Gating
	 * =================================================================== */

	/** Mode, user and request type allow our tabs now. */
	public static function visible(): bool {
		if ( self::$broken || Product_Tabs::killed() || ! self::is_page_request() ) {
			return false;
		}
		if ( null !== self::$visible ) {
			return self::$visible;
		}
		$mode = PTB_Settings::get()['mode'];
		if ( 'live' === $mode ) {
			self::$visible = true;
		} elseif ( 'test' === $mode ) {
			if ( ! did_action( 'set_current_user' ) ) {
				return false; // Not cached: the user is not known yet.
			}
			self::$visible = current_user_can( Product_Tabs::CAP );
		} else {
			self::$visible = false;
		}
		return self::$visible;
	}

	/** A front-end page view (Bible §14.4). */
	public static function is_page_request(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( did_action( 'parse_query' ) && ( is_feed() || is_embed() ) ) {
			return false;
		}
		return true;
	}

	/** Whether "Custom Product Tabs for WooCommerce" is active. */
	public static function wb_active(): bool {
		return class_exists( 'Wb_Custom_Product_Tabs_For_Woocommerce', false ) || defined( 'WB_CUSTOM_PRODUCT_TABS_FOR_WOOCOMMERCE_VERSION' ) || PTB_Import::wb_plugin_active();
	}

	/* =====================================================================
	 * The tabs
	 * =================================================================== */

	/**
	 * woocommerce_product_tabs.
	 *
	 * @param mixed $tabs WooCommerce's tabs.
	 * @return mixed
	 */
	public static function tabs( $tabs ) {
		if ( ! is_array( $tabs ) || ! self::visible() ) {
			return $tabs;
		}
		$orig = $tabs;
		try {
			global $product;
			if ( ! $product instanceof WC_Product ) {
				return $tabs;
			}
			$pid  = (int) $product->get_id();
			$data = PTB_Tabs::get( $pid );
			$add  = array();

			foreach ( $data['tabs'] as $t ) {
				if ( $t['on'] ) {
					$add[ 'ptb_' . $t['id'] ] = self::entry( $t, $t['title'], $t['content'] );
				}
			}
			foreach ( PTB_Tabs::shared_for( $product ) as $t ) {
				if ( ! $t['on'] || in_array( $t['id'], $data['hide'], true ) ) {
					continue;
				}
				$content = isset( $data['over'][ $t['id'] ] ) ? $data['over'][ $t['id'] ] : PTB_Settings::pick( $t['content_el'], '' !== trim( $t['content_en'] ) ? $t['content_en'] : '' );
				$add[ 'ptb_s_' . $t['id'] ] = self::entry(
					array(
						'type'     => isset( $data['over'][ $t['id'] ] ) ? 'text' : $t['type'],
						'video'    => $t['video'],
						'role'     => '',
						'priority' => $t['priority'],
					),
					PTB_Settings::pick( $t['title_el'], $t['title_en'] ),
					$content
				);
			}
			foreach ( PTB_Tabs::from_work( $pid, $data['tabs'] ) as $t ) {
				$add[ 'ptb_w' . $t['from'] . '_' . $t['id'] ] = self::entry( $t, $t['title'], $t['content'] );
			}

			// Book Preview shows its own tab: a tab of ours with only [book_preview] would repeat it.
			if ( isset( $tabs['bpv_preview'] ) ) {
				foreach ( $add as $k => $e ) {
					if ( 'text' === $e['ptb']['type'] && preg_match( '/^\s*(?:<p[^>]*>)?\s*\[book_preview\]\s*(?:<\/p>)?\s*$/i', $e['ptb']['content'] ) ) {
						unset( $add[ $k ] );
					}
				}
			}

			// The old plugin's tabs, while it runs, on products already imported.
			$s = PTB_Settings::get();
			if ( $data['imported'] && $s['hide_wb'] ) {
				foreach ( array_keys( $tabs ) as $k ) {
					if ( is_string( $k ) && 0 === strpos( $k, 'wb_cptb' ) ) {
						unset( $tabs[ $k ] );
					}
				}
			}

			$tabs = array_merge( $tabs, $add );

			// Order, titles and hiding of the known tabs.
			$en = 'en' === PTB_Settings::front_lang();
			foreach ( $s['order'] as $k => $o ) {
				if ( ! isset( $tabs[ $k ] ) || ! is_array( $tabs[ $k ] ) ) {
					continue;
				}
				if ( $o['hide'] ) {
					unset( $tabs[ $k ] );
					continue;
				}
				if ( $o['priority'] > 0 ) {
					$tabs[ $k ]['priority'] = $o['priority'];
				}
				$title = $en && '' !== $o['en'] ? $o['en'] : $o['el'];
				if ( '' !== $title ) {
					$tabs[ $k ]['title'] = $title;
				}
			}

			$tabs = apply_filters( 'ptb_tabs', $tabs, $pid );
			return is_array( $tabs ) ? $tabs : $orig;
		} catch ( \Throwable $e ) {
			self::$broken = true;
			return $orig;
		}
	}

	/** A WooCommerce tab entry for one of our tabs. */
	private static function entry( array $t, string $title, string $content ): array {
		if ( '' === $title && 'video' === $t['type'] ) {
			$title = PTB_Settings::text( '' !== $t['role'] ? $t['role'] : 'trailer' );
		}
		return array(
			'title'    => $title,
			'priority' => (int) $t['priority'],
			'callback' => array( __CLASS__, 'tab_content' ),
			'ptb'      => array(
				'type'    => $t['type'],
				'video'   => (string) $t['video'],
				'content' => $content,
				'title'   => $title,
			),
		);
	}

	/**
	 * Tab callback; WooCommerce passes the key and the tab entry.
	 *
	 * @param string $key Tab key.
	 * @param array  $tab Tab entry.
	 */
	public static function tab_content( $key = '', $tab = array() ): void {
		try {
			$d = is_array( $tab ) && isset( $tab['ptb'] ) && is_array( $tab['ptb'] ) ? $tab['ptb'] : null;
			if ( null === $d ) {
				return;
			}
			if ( 'video' === $d['type'] ) {
				echo self::player( (string) $d['video'], (string) $d['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in player().
				return;
			}
			echo self::content( (string) $d['content'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- saved through wp_kses_post unless the author had unfiltered_html (Bible §11).
		} catch ( \Throwable $e ) {
			self::$broken = true;
		}
	}

	/** HTML of a text tab, as WordPress prints post content. */
	public static function content( string $html ): string {
		$out = do_shortcode( shortcode_unautop( wpautop( $html ) ) );
		$out = apply_filters( 'ptb_tab_content', $out, $html );
		return is_string( $out ) ? $out : '';
	}

	/* =====================================================================
	 * The player
	 * =================================================================== */

	/** Poster button for a 'yt:' / 'vm:' value; '' when invalid. */
	public static function player( string $video, string $title ): string {
		$src = '';
		$img = '';
		if ( preg_match( '/^yt:(' . PTB_Tabs::YT_RE . ')$/', $video, $m ) ) {
			$src = 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?autoplay=1&rel=0';
			if ( PTB_Settings::get()['thumbs'] ) {
				$img = 'https://i.ytimg.com/vi/' . $m[1] . '/hqdefault.jpg';
			}
		} elseif ( preg_match( '/^vm:(\d{1,12})$/', $video, $m ) ) {
			$src = 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1&dnt=1';
		}
		if ( '' === $src ) {
			return '';
		}
		self::enqueue();
		$label = sprintf( PTB_Settings::text( 'play' ), $title );
		return '<div class="ptb-video"><button type="button" class="ptb-play" data-src="' . esc_attr( $src ) . '" data-title="' . esc_attr( $title ) . '" aria-label="' . esc_attr( $label ) . '">'
			. ( '' !== $img ? '<img src="' . esc_url( $img ) . '" alt="" loading="lazy" width="480" height="360">' : '' )
			. '<span class="ptb-play-icon" aria-hidden="true"></span></button></div>';
	}

	/** CSS and the click-to-load script, once, in the footer. */
	private static function enqueue(): void {
		if ( wp_script_is( 'ptb-front', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style( 'ptb-front', PTB_URL . 'assets/front.css', array(), PTB_VERSION );
		wp_enqueue_script( 'ptb-front', PTB_URL . 'assets/front.js', array(), PTB_VERSION, true );
		// Printed in the body: the style goes out in the footer then.
		if ( did_action( 'wp_print_styles' ) ) {
			add_action(
				'wp_footer',
				static function () {
					wp_print_styles( array( 'ptb-front' ) );
				},
				5
			);
		}
	}

	/* =====================================================================
	 * Shortcodes
	 * =================================================================== */

	/** [nox_video src="URL or id" title="…"]. */
	public static function sc_video( $atts ): string {
		if ( self::$broken || Product_Tabs::killed() ) {
			return '';
		}
		try {
			$atts  = shortcode_atts(
				array(
					'src'   => '',
					'id'    => '',
					'title' => '',
				),
				is_array( $atts ) ? $atts : array(),
				self::SC_VIDEO
			);
			$video = PTB_Tabs::parse_video( '' !== (string) $atts['src'] ? (string) $atts['src'] : (string) $atts['id'] );
			$title = PTB_Settings::text_field( (string) $atts['title'] );
			return self::player( $video, '' !== $title ? $title : PTB_Settings::text( 'trailer' ) );
		} catch ( \Throwable $e ) {
			self::$broken = true;
			return '';
		}
	}

	/** Registers the bridge shortcode when nobody else has it. */
	public static function bridge(): void {
		if ( ! PTB_Settings::get()['bridge'] || shortcode_exists( self::SC_BRIDGE ) ) {
			return;
		}
		add_shortcode( self::SC_BRIDGE, array( __CLASS__, 'sc_bridge' ) );
	}

	/** [wb_cpt_youtube_embed_shortcode video_id="…" width="…" height="…"]. */
	public static function sc_bridge( $atts ): string {
		if ( self::$broken ) {
			return '';
		}
		try {
			$atts = is_array( $atts ) ? $atts : array();
			$vid  = isset( $atts['video_id'] ) && is_scalar( $atts['video_id'] ) ? (string) $atts['video_id'] : '';
			return self::player( PTB_Tabs::parse_video( $vid ), PTB_Settings::text( 'trailer' ) );
		} catch ( \Throwable $e ) {
			self::$broken = true;
			return '';
		}
	}
}

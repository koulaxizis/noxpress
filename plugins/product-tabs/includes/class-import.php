<?php
/**
 * PTB_Import — from "Custom Product Tabs for WooCommerce" (WebBuilders),
 * Bible §14 "replacing a third-party plugin".
 *
 * Its data: product meta wb_custom_tabs, a list of tabs { title,
 * content, position, tab_type, nickname, slug }. Only "local" tabs (the
 * product's own) are read; any other tab_type is counted and left out.
 *
 *  - preview()  counts, without writing anything
 *  - run()      appends the converted tabs to each product's _ptb_tabs
 *               (src "wb") and marks it imported; a product already
 *               imported is skipped, so a second run adds nothing
 *  - undo()     removes every tab with src "wb" and the imported mark
 *
 * The old meta is never changed or deleted. Conversion: a tab whose
 * content is only [wb_cpt_youtube_embed_shortcode video_id="X" …] (in a
 * <p> or not) becomes a video tab with the id X; position 30 gives the
 * role "trailer", 31 "presentation" (the positions Home Sections read).
 * Everything else is copied as it is (HTML and shortcodes), through
 * wp_kses_post unless the user running the import has unfiltered_html.
 * The position becomes the tab priority.
 */

defined( 'ABSPATH' ) || exit;

final class PTB_Import {

	const META  = 'wb_custom_tabs';
	const LIMIT = 2000;

	const ROLE_BY_POSITION = array(
		30 => 'trailer',
		31 => 'presentation',
	);

	/** Whether the old plugin is active (by its folder in active_plugins). */
	public static function wb_plugin_active(): bool {
		$list = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$list = array_merge( $list, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		foreach ( $list as $p ) {
			if ( is_string( $p ) && 0 === strpos( $p, 'wb-custom-product-tabs-for-woocommerce/' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Ids of products that have the old meta (any status but trash). */
	public static function products(): array {
		$q = new WP_Query(
			array(
				'post_type'      => array( 'product' ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => self::LIMIT,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- admin tool, EXISTS on one key.
					array(
						'key'     => self::META,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		return array_map( 'intval', $q->posts );
	}

	/** The old tabs of a product, as stored. */
	public static function old_tabs( int $pid ): array {
		$raw = get_post_meta( $pid, self::META, true );
		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : array();
	}

	/**
	 * One old tab → our tab, or null (empty, or not a local tab).
	 *
	 * @param array $old  Old tab.
	 * @param bool  $kses wp_kses_post on the content.
	 */
	public static function convert( array $old, bool $kses ): ?array {
		$type = isset( $old['tab_type'] ) && is_string( $old['tab_type'] ) ? $old['tab_type'] : 'local';
		if ( 'local' !== $type ) {
			return null;
		}
		$content = isset( $old['content'] ) && is_string( $old['content'] ) ? $old['content'] : '';
		$title   = isset( $old['title'] ) && is_scalar( $old['title'] ) ? (string) $old['title'] : '';
		$pos     = isset( $old['position'] ) && is_numeric( $old['position'] ) ? (int) $old['position'] : 40;
		$row     = array(
			'title'    => $title,
			'priority' => max( 1, min( 200, $pos ) ),
			'on'       => true,
			'src'      => 'wb',
		);
		$vid = self::only_video( $content );
		if ( '' !== $vid ) {
			$row['type']  = 'video';
			$row['video'] = 'yt:' . $vid;
			$row['role']  = isset( self::ROLE_BY_POSITION[ $pos ] ) ? self::ROLE_BY_POSITION[ $pos ] : '';
		} else {
			$row['type']    = 'text';
			$row['content'] = $content;
		}
		return PTB_Tabs::tab( $row, $kses );
	}

	/** The video id when the content is only the old plugin's YouTube shortcode. */
	public static function only_video( string $content ): string {
		$re = '/^\s*(?:<p[^>]*>)?\s*\[wb_cpt_youtube_embed_shortcode\b([^\]]*)\]\s*(?:<\/p>)?\s*$/i';
		if ( ! preg_match( $re, $content, $m ) ) {
			return '';
		}
		$atts = shortcode_parse_atts( $m[1] );
		$vid  = is_array( $atts ) && isset( $atts['video_id'] ) && is_string( $atts['video_id'] ) ? $atts['video_id'] : '';
		return preg_match( '/^' . PTB_Tabs::YT_RE . '$/', $vid ) ? $vid : '';
	}

	/**
	 * What an import would do.
	 *
	 * @return array{products:int,with_tabs:int,tabs:int,videos:int,text:int,book_preview:int,skipped:int,imported:int}
	 */
	public static function preview(): array {
		$r = array(
			'products'     => 0,
			'with_tabs'    => 0,
			'tabs'         => 0,
			'videos'       => 0,
			'text'         => 0,
			'book_preview' => 0,
			'skipped'      => 0,
			'imported'     => 0,
		);
		foreach ( self::products() as $pid ) {
			++$r['products'];
			$old = self::old_tabs( $pid );
			if ( ! $old ) {
				continue;
			}
			++$r['with_tabs'];
			if ( PTB_Tabs::get( $pid )['imported'] ) {
				++$r['imported'];
			}
			foreach ( $old as $o ) {
				$t = self::convert( $o, false );
				if ( null === $t ) {
					++$r['skipped'];
					continue;
				}
				++$r['tabs'];
				if ( 'video' === $t['type'] ) {
					++$r['videos'];
				} else {
					++$r['text'];
					if ( false !== stripos( $t['content'], '[book_preview' ) ) {
						++$r['book_preview'];
					}
				}
			}
		}
		return $r;
	}

	/**
	 * Imports every product not imported yet.
	 *
	 * @param bool $kses wp_kses_post on the contents (user without unfiltered_html).
	 * @return array{products:int,tabs:int,ids:int[]}
	 */
	public static function run( bool $kses ): array {
		$done = array(
			'products' => 0,
			'tabs'     => 0,
			'ids'      => array(),
		);
		foreach ( self::products() as $pid ) {
			$data = PTB_Tabs::get( $pid );
			if ( $data['imported'] ) {
				continue;
			}
			$new = array();
			foreach ( self::old_tabs( $pid ) as $o ) {
				$t = self::convert( $o, $kses );
				if ( null !== $t ) {
					$new[] = $t;
				}
			}
			if ( ! $new ) {
				continue;
			}
			$data['tabs']     = array_merge( $data['tabs'], $new );
			$data['imported'] = true;
			if ( PTB_Tabs::put( $pid, $data ) ) {
				++$done['products'];
				$done['tabs'] += count( $new );
				$done['ids'][] = $pid;
			}
		}
		self::changed( $done['ids'] );
		return $done;
	}

	/**
	 * Removes what the import added.
	 *
	 * @return array{products:int,ids:int[]}
	 */
	public static function undo(): array {
		$done = array(
			'products' => 0,
			'ids'      => array(),
		);
		$q    = new WP_Query(
			array(
				'post_type'      => array( 'product' ),
				'post_status'    => 'any',
				'posts_per_page' => self::LIMIT,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery -- admin tool.
					array(
						'key'     => PTB_Tabs::META,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		foreach ( array_map( 'intval', $q->posts ) as $pid ) {
			$data = PTB_Tabs::get( $pid );
			if ( ! $data['imported'] ) {
				continue;
			}
			$data['tabs']     = array_values(
				array_filter(
					$data['tabs'],
					static function ( $t ) {
						return 'wb' !== $t['src'];
					}
				)
			);
			$data['imported'] = false;
			if ( PTB_Tabs::put( $pid, $data ) ) {
				++$done['products'];
				$done['ids'][] = $pid;
			}
		}
		self::changed( $done['ids'] );
		return $done;
	}

	/** Suite notice and page cache for changed products. */
	private static function changed( array $ids ): void {
		if ( ! $ids ) {
			return;
		}
		PTB_Tabs::flush();
		do_action( 'noxpress_products_changed', $ids );
		if ( PTB_Settings::get()['purge'] && class_exists( 'Noxpress_Core' ) ) {
			Noxpress_Core::purge_page_cache( $ids );
		}
	}
}

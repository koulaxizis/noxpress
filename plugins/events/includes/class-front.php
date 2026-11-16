<?php
/**
 * EVT_Front — the visitor side.
 *
 *  - Test mode: event pages, the archive and venue pages answer 404 to
 *    visitors who may not edit posts; lists and boxes stay empty; nothing
 *    goes into sitemaps or search results.
 *  - Event page: a details box before the content (when, where, map link,
 *    online link, entry, tickets / RSVP button, add to calendar, related
 *    products and creators), a status banner (postponed, cancelled, new
 *    date) and an «ended» note for a past event.
 *  - Archive (/events/): upcoming events by start date; ?evt_past=1 lists
 *    the past ones, newest first. Title «Επόμενες / Περασμένες
 *    εκδηλώσεις» and, in the archive description, a link to the other list.
 *  - [nx_events] (and the block): upcoming (or past) events, filters
 *    creator, product, venue, city ("current" = the page's product or
 *    creator), count, title.
 *  - «Επόμενες εκδηλώσεις» boxes: on product pages (after the summary) and
 *    on Featured Creators pages (after the page), only when there are any.
 *
 * Maps are links only (no embed), nothing is written on a visitor request,
 * every callback is wrapped in try/catch(\Throwable).
 */

defined( 'ABSPATH' ) || exit;

final class EVT_Front {

	/** @var bool Set after an error: the plugin stays out of the rest of the request. */
	private static $broken = false;

	/** @var bool The product box was printed in this request. */
	private static $product_done = false;

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'gate' ), 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'main_query' ) );
		add_filter( 'the_content', array( __CLASS__, 'content' ), 20 );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'excerpt' ), 20, 2 );
		add_shortcode( 'nx_events', array( __CLASS__, 'shortcode' ) );
		add_action( 'woocommerce_after_single_product_summary', array( __CLASS__, 'product_box_hook' ), 12 );
		add_filter( 'render_block', array( __CLASS__, 'product_box_block' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		add_filter( 'get_the_archive_title', array( __CLASS__, 'archive_title' ), 20 );
		add_filter( 'get_the_archive_description', array( __CLASS__, 'archive_description' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'wp_sitemaps_post_types', array( __CLASS__, 'core_sitemap_types' ) );
		add_filter( 'wp_sitemaps_taxonomies', array( __CLASS__, 'core_sitemap_taxes' ) );
		add_filter( 'noxpress_seo_sitemap_entry', array( __CLASS__, 'seo_sitemap' ), 10, 3 );
	}

	private static function fail( \Throwable $e ): void {
		self::$broken = true;
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $e->getMessage(), array( 'source' => 'events' ) );
		}
	}

	/** Visitors may see events: live, or test mode for someone who edits posts. */
	public static function visible(): bool {
		$mode = EVT_Settings::mode();
		if ( self::$broken || 'off' === $mode || Events_Plugin::killed() ) {
			return false;
		}
		return 'live' === $mode || current_user_can( 'edit_posts' );
	}

	/** Visible in the strict, public sense (sitemaps, search). */
	private static function public_mode(): bool {
		return 'live' === EVT_Settings::mode() && ! Events_Plugin::killed();
	}

	/* =====================================================================
	 * Test mode, queries, sitemaps
	 * =================================================================== */

	public static function gate(): void {
		if ( ! ( is_singular( EVT_CPT::PT ) || is_post_type_archive( EVT_CPT::PT ) || is_tax( EVT_CPT::TAX ) ) || self::visible() ) {
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	public static function main_query( $q ): void {
		if ( ! $q instanceof WP_Query || is_admin() || ! $q->is_main_query() ) {
			return;
		}
		try {
			if ( $q->is_search() && ! self::public_mode() ) {
				$types = $q->get( 'post_type' );
				if ( empty( $types ) || 'any' === $types ) {
					$types = array_values( array_diff( get_post_types( array( 'exclude_from_search' => false ) ), array( EVT_CPT::PT ) ) );
					$q->set( 'post_type', $types );
				}
				return;
			}
			if ( $q->is_post_type_archive( EVT_CPT::PT ) || $q->is_tax( EVT_CPT::TAX ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only switch.
				$past = isset( $_GET['evt_past'] );
				$q->set( 'meta_key', '_evt_start' );
				$q->set( 'orderby', 'meta_value_num' );
				$q->set( 'order', $past ? 'DESC' : 'ASC' );
				$q->set(
					'meta_query', // phpcs:ignore WordPress.DB.SlowDBQuery -- archive of one post type.
					array(
						array(
							'key'     => '_evt_end',
							'value'   => time(),
							'compare' => $past ? '<' : '>=',
							'type'    => 'NUMERIC',
						),
					)
				);
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	public static function core_sitemap_types( $types ) {
		if ( is_array( $types ) && ! self::public_mode() ) {
			unset( $types[ EVT_CPT::PT ] );
		}
		return $types;
	}

	public static function core_sitemap_taxes( $taxes ) {
		if ( is_array( $taxes ) && ! self::public_mode() ) {
			unset( $taxes[ EVT_CPT::TAX ] );
		}
		return $taxes;
	}

	/** Search Signals sitemap: only in live mode; events that ended over a year ago are left out. */
	public static function seo_sitemap( $entry, $type = '', $object = null ) {
		if ( $object instanceof WP_Post && EVT_CPT::PT === $object->post_type ) {
			if ( ! self::public_mode() ) {
				return false;
			}
			$end = (int) get_post_meta( $object->ID, '_evt_end', true );
			if ( $end > 0 && $end < time() - YEAR_IN_SECONDS ) {
				return false;
			}
		}
		if ( $object instanceof WP_Term && EVT_CPT::TAX === $object->taxonomy && ! self::public_mode() ) {
			return false;
		}
		return $entry;
	}

	private static function past_view(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only switch.
		return isset( $_GET['evt_past'] );
	}

	public static function archive_title( $title ) {
		if ( self::$broken || ! is_post_type_archive( EVT_CPT::PT ) || ! self::visible() ) {
			return $title;
		}
		return esc_html( EVT_Settings::text( self::past_view() ? 'past' : 'upcoming' ) );
	}

	/** A link to the other list (past ↔ upcoming) under the archive title. */
	public static function archive_description( $desc ) {
		if ( self::$broken || ! is_post_type_archive( EVT_CPT::PT ) || ! self::visible() ) {
			return $desc;
		}
		$base = (string) get_post_type_archive_link( EVT_CPT::PT );
		$url  = self::past_view() ? $base : add_query_arg( 'evt_past', '1', $base );
		$text = EVT_Settings::text( self::past_view() ? 'upcoming' : 'past' );
		return (string) $desc . '<p class="evt-switch"><a href="' . esc_url( $url ) . '" rel="nofollow">' . esc_html( $text ) . '</a></p>';
	}

	/** Registered on init so the block (editor and front) can use the handle. */
	public static function register_assets(): void {
		wp_register_style( 'evt-front', EVT_URL . 'assets/front.css', array(), EVT_VERSION );
	}

	public static function assets(): void {
		if ( ! self::visible() ) {
			return;
		}
		$on = is_singular( array( EVT_CPT::PT, 'nox_creator' ) ) || is_post_type_archive( EVT_CPT::PT ) || is_tax( EVT_CPT::TAX ) || ( function_exists( 'is_product' ) && is_product() );
		if ( $on ) {
			wp_enqueue_style( 'evt-front' );
		}
	}

	/* =====================================================================
	 * Event page
	 * =================================================================== */

	public static function content( $content ) {
		if ( self::$broken || ! is_string( $content ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		try {
			if ( is_singular( EVT_CPT::PT ) && self::visible() && (int) get_the_ID() === (int) get_queried_object_id() ) {
				$e = EVT_CPT::event( (int) get_the_ID() );
				return $e ? self::details( $e ) . $content : $content;
			}
			if ( is_singular( 'nox_creator' ) && EVT_Settings::get()['on_creator'] && self::visible() && (int) get_the_ID() === (int) get_queried_object_id() ) {
				return $content . self::box( array( 'creator' => (int) get_the_ID() ) );
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
		return $content;
	}

	/** «When» line before the excerpt in archives and search results. */
	public static function excerpt( $text, $post = null ) {
		if ( self::$broken || is_admin() || ! $post instanceof WP_Post || EVT_CPT::PT !== $post->post_type || is_singular( EVT_CPT::PT ) ) {
			return $text;
		}
		try {
			$e = EVT_CPT::event( (int) $post->ID );
			if ( $e && $e['start'] > 0 ) {
				$where = self::where_short( $e );
				return EVT_CPT::when( $e ) . ( '' !== $where ? ' · ' . $where : '' ) . ( '' !== (string) $text ? ' — ' . $text : '' );
			}
		} catch ( \Throwable $ex ) {
			self::fail( $ex );
		}
		return $text;
	}

	private static function status_text( string $status ): string {
		return in_array( $status, array( 'postponed', 'cancelled', 'rescheduled' ), true ) ? EVT_Settings::text( $status ) : '';
	}

	/** «Venue, City» or «Online». */
	public static function where_short( array $e ): string {
		if ( $e['venue'] ) {
			return implode( ', ', array_filter( array( $e['venue']['name'], $e['venue']['city'] ) ) );
		}
		return $e['online'] ? EVT_Settings::text( 'online' ) : '';
	}

	private static function button_class(): string {
		return 'button evt-button' . ( function_exists( 'wc_wp_theme_get_element_class_name' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' );
	}

	/** The details box of the event page. */
	public static function details( array $e ): string {
		wp_enqueue_style( 'evt-front' );
		$status = self::status_text( $e['status'] );
		$h      = '<div class="evt-root evt-details">';
		if ( '' !== $status ) {
			$h .= '<p class="evt-banner evt-banner--' . esc_attr( $e['status'] ) . '">' . esc_html( $status ) . '</p>';
		} elseif ( $e['ended'] ) {
			$h .= '<p class="evt-banner evt-banner--ended">' . esc_html( EVT_Settings::text( 'ended' ) ) . '</p>';
		}
		$h .= '<dl class="evt-facts">';
		if ( $e['start'] > 0 ) {
			$h .= '<dt>' . esc_html( EVT_Settings::text( 'when' ) ) . '</dt><dd><time datetime="' . esc_attr( wp_date( 'c', $e['start'] ) ) . '">' . esc_html( EVT_CPT::when( $e ) ) . '</time></dd>';
		}
		if ( $e['venue'] || $e['online'] ) {
			$h .= '<dt>' . esc_html( EVT_Settings::text( 'where' ) ) . '</dt><dd>';
			if ( $e['venue'] ) {
				$v   = $e['venue'];
				$h  .= '' !== $v['url'] ? '<a href="' . esc_url( $v['url'] ) . '">' . esc_html( $v['name'] ) . '</a>' : esc_html( $v['name'] );
				$adr = implode( ', ', array_filter( array( $v['address'], trim( $v['postcode'] . ' ' . $v['city'] ) ) ) );
				if ( '' !== $adr ) {
					$h .= '<br />' . esc_html( $adr );
				}
				if ( '' !== $v['map'] ) {
					$h .= ' · <a href="' . esc_url( $v['map'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( EVT_Settings::text( 'map' ) ) . '</a>';
				}
			}
			if ( $e['online'] ) {
				$h .= ( $e['venue'] ? '<br />' : '' ) . esc_html( EVT_Settings::text( 'online' ) );
				if ( '' !== $e['online_url'] && ! $e['ended'] && 'cancelled' !== $e['status'] ) {
					$h .= ' · <a href="' . esc_url( $e['online_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( EVT_Settings::text( 'join' ) ) . '</a>';
				}
			}
			$h .= '</dd>';
		}
		if ( '' !== $e['entry'] ) {
			$entry = EVT_Settings::text( 'free' === $e['entry'] ? 'free' : 'ticket' );
			if ( 'ticket' === $e['entry'] && '' !== $e['price'] ) {
				$entry .= ': ' . $e['price'];
			}
			$h .= '<dt>' . esc_html( EVT_Settings::text( 'entry' ) ) . '</dt><dd>' . esc_html( $entry ) . '</dd>';
		}
		$with = self::creator_links( $e['creators'] );
		if ( '' !== $with ) {
			$h .= '<dt>' . esc_html( EVT_Settings::text( 'with' ) ) . '</dt><dd>' . $with . '</dd>';
		}
		$rel = self::product_links( $e['products'] );
		if ( '' !== $rel ) {
			$h .= '<dt>' . esc_html( EVT_Settings::text( 'related' ) ) . '</dt><dd>' . $rel . '</dd>';
		}
		$h .= '</dl>';

		// Tickets sold in the shop (1.1.0) replace the external tickets link.
		$box = class_exists( 'EVT_Tickets' ) ? EVT_Tickets::box( $e ) : '';
		$h  .= $box;

		if ( ! $e['ended'] && 'cancelled' !== $e['status'] ) {
			$h .= '<p class="evt-actions">';
			if ( '' !== $e['link'] && '' === $box ) {
				$h .= '<a class="' . esc_attr( self::button_class() ) . '" href="' . esc_url( $e['link'] ) . '" target="_blank" rel="noopener">' . esc_html( EVT_Settings::text( 'rsvp' === $e['link_kind'] ? 'rsvp' : 'tickets' ) ) . '</a> ';
			}
			if ( $e['start'] > 0 ) {
				$h .= '<span class="evt-cal">' . esc_html( EVT_Settings::text( 'calendar' ) ) . ': '
					. '<a href="' . esc_url( EVT_Ics::google( $e ) ) . '" target="_blank" rel="noopener noreferrer nofollow">' . esc_html( EVT_Settings::text( 'gcal' ) ) . '</a> · '
					. '<a href="' . esc_url( EVT_Ics::link( $e['id'] ) ) . '" rel="nofollow">' . esc_html( EVT_Settings::text( 'ics' ) ) . '</a></span>';
			}
			$h .= '</p>';
		}
		return $h . '</div>';
	}

	private static function creator_links( array $ids ): string {
		$out = array();
		foreach ( $ids as $cid ) {
			$c = get_post( $cid );
			if ( $c instanceof WP_Post && 'publish' === $c->post_status && '' === (string) $c->post_password ) {
				$out[] = '<a href="' . esc_url( (string) get_permalink( $c ) ) . '">' . esc_html( wp_strip_all_tags( get_the_title( $c ) ) ) . '</a>';
			}
		}
		return implode( ', ', $out );
	}

	private static function product_links( array $ids ): string {
		$out = array();
		foreach ( $ids as $pid ) {
			$p = get_post( $pid );
			if ( $p instanceof WP_Post && 'publish' === $p->post_status && '' === (string) $p->post_password ) {
				$out[] = '<a href="' . esc_url( (string) get_permalink( $p ) ) . '">' . esc_html( wp_strip_all_tags( get_the_title( $p ) ) ) . '</a>';
			}
		}
		return implode( ', ', $out );
	}

	/* =====================================================================
	 * Lists
	 * =================================================================== */

	/**
	 * A list of events (HTML), '' when there are none and $empty is false.
	 *
	 * @param array  $events Events.
	 * @param string $title  Heading ('' = none).
	 * @param bool   $empty  Show the «no events» text.
	 * @param bool   $more   Link to the archive.
	 */
	public static function render_list( array $events, string $title, bool $empty = true, bool $more = false ): string {
		if ( ! $events && ! $empty ) {
			return '';
		}
		wp_enqueue_style( 'evt-front' );
		$h = '<div class="evt-root evt-list-wrap">';
		if ( '' !== $title ) {
			$h .= '<h2 class="evt-list-title">' . esc_html( $title ) . '</h2>';
		}
		if ( ! $events ) {
			$h .= '<p class="evt-none">' . esc_html( EVT_Settings::text( 'none' ) ) . '</p>';
		} else {
			$h .= '<ul class="evt-list">';
			foreach ( $events as $e ) {
				$status = self::status_text( $e['status'] );
				$where  = self::where_short( $e );
				$h     .= '<li class="evt-item' . ( '' !== $status ? ' evt-item--' . esc_attr( $e['status'] ) : '' ) . '">';
				if ( $e['start'] > 0 ) {
					$h .= '<time class="evt-date" datetime="' . esc_attr( wp_date( 'c', $e['start'] ) ) . '"><span class="evt-date__d">' . esc_html( wp_date( 'j', $e['start'] ) ) . '</span><span class="evt-date__m">' . esc_html( wp_date( 'M', $e['start'] ) ) . '</span></time>';
				}
				$h .= '<div class="evt-item__body"><a class="evt-item__title" href="' . esc_url( $e['url'] ) . '">' . esc_html( $e['title'] ) . '</a>';
				$h .= '<span class="evt-item__meta">' . esc_html( EVT_CPT::when( $e ) . ( '' !== $where ? ' · ' . $where : '' ) ) . '</span>';
				if ( '' !== $status ) {
					$h .= '<span class="evt-badge">' . esc_html( $status ) . '</span>';
				}
				$h .= '</div></li>';
			}
			$h .= '</ul>';
		}
		if ( $more ) {
			$arch = get_post_type_archive_link( EVT_CPT::PT );
			if ( $arch ) {
				$h .= '<p class="evt-more"><a href="' . esc_url( (string) $arch ) . '">' . esc_html( EVT_Settings::text( 'all' ) ) . '</a></p>';
			}
		}
		return $h . '</div>';
	}

	/** «Επόμενες εκδηλώσεις» box for a product or a creator ('' when none). */
	public static function box( array $filter ): string {
		$filter['limit'] = (int) EVT_Settings::get()['box_count'];
		return self::render_list( EVT_Query::find( $filter ), EVT_Settings::text( 'upcoming' ), false, true );
	}

	/**
	 * [nx_events count="5" creator="" product="" venue="" city="" past="0" title=""]
	 * creator / product = an id or "current".
	 */
	public static function shortcode( $atts ): string {
		if ( self::$broken || ! self::visible() ) {
			return '';
		}
		try {
			$a = shortcode_atts(
				array(
					'count'   => 5,
					'creator' => '',
					'product' => '',
					'venue'   => '',
					'city'    => '',
					'past'    => '0',
					'title'   => '',
				),
				is_array( $atts ) ? $atts : array(),
				'nx_events'
			);
			return self::render_list( EVT_Query::find( self::args( $a ) ), sanitize_text_field( (string) $a['title'] ), true, false );
		} catch ( \Throwable $e ) {
			self::fail( $e );
			return '';
		}
	}

	/** Shortcode / block attributes → EVT_Query::find() args. */
	public static function args( array $a ): array {
		$cur = (int) get_queried_object_id();
		$id  = static function ( $v, string $pt ) use ( $cur ) {
			if ( 'current' === $v ) {
				return get_post_type( $cur ) === $pt ? $cur : -1;
			}
			return absint( $v );
		};
		$args = array(
			'limit' => max( 1, min( 50, (int) $a['count'] ) ),
			'past'  => in_array( (string) $a['past'], array( '1', 'true', 'yes' ), true ),
			'city'  => sanitize_text_field( (string) $a['city'] ),
			'venue' => absint( $a['venue'] ),
		);
		$c = $id( $a['creator'], 'nox_creator' );
		$p = $id( $a['product'], 'product' );
		if ( -1 === $c || -1 === $p ) {
			$args['creator'] = PHP_INT_MAX; // "current" on another page: nothing matches.
		} else {
			$args['creator'] = $c;
			$args['product'] = $p;
		}
		return $args;
	}

	/* =====================================================================
	 * Product page box
	 * =================================================================== */

	public static function product_box_hook(): void {
		global $product;
		if ( $product instanceof WC_Product ) {
			echo self::product_box( (int) $product->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render_list().
		}
	}

	public static function product_box_block( $html, $block ) {
		if ( ! is_array( $block ) || 'woocommerce/product-details' !== ( $block['blockName'] ?? '' ) || ! is_singular( 'product' ) ) {
			return $html;
		}
		return $html . self::product_box( (int) get_queried_object_id() );
	}

	private static function product_box( int $pid ): string {
		if ( self::$broken || self::$product_done || $pid <= 0 || ! EVT_Settings::get()['on_product'] || ! self::visible() ) {
			return '';
		}
		try {
			self::$product_done = true;
			return self::box( array( 'product' => $pid ) );
		} catch ( \Throwable $e ) {
			self::fail( $e );
			return '';
		}
	}
}

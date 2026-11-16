<?php
/**
 * EVT_CPT — the nox_event post type, the nox_venue taxonomy, the event
 * meta and the normalized event array.
 *
 * Event meta (all registered, edit context only in REST):
 *  - _evt_start, _evt_end     int, Unix time (the site's time zone is
 *                             applied only on input and output)
 *  - _evt_status              'scheduled' | 'postponed' | 'cancelled' | 'rescheduled'
 *  - _evt_prev_start          int, the old start of a rescheduled event
 *  - _evt_online              '1' = online (then _evt_online_url)
 *  - _evt_entry               '' | 'free' | 'ticket', _evt_price (text)
 *  - _evt_link, _evt_link_kind  external tickets / RSVP link, 'tickets' | 'rsvp'
 *  - _evt_product, _evt_creator  one meta row per id (WooCommerce
 *                             products, Featured Creators posts), so the
 *                             lists can filter by them
 *  - _evt_kind                schema.org type (KINDS)
 * Venue term meta: evt_address, evt_city, evt_postcode, evt_country, evt_map.
 */

defined( 'ABSPATH' ) || exit;

final class EVT_CPT {

	const PT  = 'nox_event';
	const TAX = 'nox_venue';

	const STATUSES = array( 'scheduled', 'postponed', 'cancelled', 'rescheduled' );

	const KINDS = array( 'Event', 'LiteraryEvent', 'MusicEvent', 'EducationEvent', 'ExhibitionEvent', 'TheaterEvent', 'ScreeningEvent', 'SocialEvent', 'ChildrensEvent', 'Festival' );

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
	}

	public static function register(): void {
		$s = EVT_Settings::get();
		register_post_type(
			self::PT,
			array(
				'labels'          => array(
					'name'               => __( 'Εκδηλώσεις', 'events' ),
					'singular_name'      => __( 'Εκδήλωση', 'events' ),
					'add_new'            => __( 'Νέα εκδήλωση', 'events' ),
					'add_new_item'       => __( 'Νέα εκδήλωση', 'events' ),
					'edit_item'          => __( 'Επεξεργασία εκδήλωσης', 'events' ),
					'new_item'           => __( 'Νέα εκδήλωση', 'events' ),
					'view_item'          => __( 'Προβολή εκδήλωσης', 'events' ),
					'search_items'       => __( 'Αναζήτηση εκδηλώσεων', 'events' ),
					'not_found'          => __( 'Δεν βρέθηκαν εκδηλώσεις.', 'events' ),
					'not_found_in_trash' => __( 'Δεν βρέθηκαν εκδηλώσεις στον κάδο.', 'events' ),
					'all_items'          => __( 'Εκδηλώσεις', 'events' ),
					'menu_name'          => __( 'Εκδηλώσεις', 'events' ),
				),
				'public'          => true,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'show_in_rest'    => true,
				'has_archive'     => $s['base'],
				'rewrite'         => array(
					'slug'       => $s['base'],
					'with_front' => false,
				),
				'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'menu_icon'       => 'dashicons-calendar-alt',
			)
		);

		register_taxonomy(
			self::TAX,
			self::PT,
			array(
				'labels'            => array(
					'name'          => __( 'Χώροι', 'events' ),
					'singular_name' => __( 'Χώρος', 'events' ),
					'add_new_item'  => __( 'Νέος χώρος', 'events' ),
					'edit_item'     => __( 'Επεξεργασία χώρου', 'events' ),
					'search_items'  => __( 'Αναζήτηση χώρων', 'events' ),
					'not_found'     => __( 'Δεν βρέθηκαν χώροι.', 'events' ),
					'menu_name'     => __( 'Χώροι', 'events' ),
				),
				'public'            => true,
				'hierarchical'      => false,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'meta_box_cb'       => false,
				'rewrite'           => array(
					'slug'       => $s['venue_base'],
					'with_front' => false,
				),
			)
		);

		$auth = static function () {
			return current_user_can( 'edit_posts' );
		};
		$int  = array( '_evt_start', '_evt_end', '_evt_prev_start' );
		foreach ( $int as $k ) {
			register_post_meta(
				self::PT,
				$k,
				array(
					'type'          => 'integer',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => $auth,
				)
			);
		}
		foreach ( array( '_evt_status', '_evt_online', '_evt_online_url', '_evt_entry', '_evt_price', '_evt_link', '_evt_link_kind', '_evt_kind' ) as $k ) {
			register_post_meta(
				self::PT,
				$k,
				array(
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => $auth,
				)
			);
		}
	}

	/** Rewrite rules are flushed once after the URL bases change. */
	public static function maybe_flush(): void {
		if ( get_option( 'evt_flush' ) ) {
			delete_option( 'evt_flush' );
			flush_rewrite_rules( false );
		}
	}

	/** Published, no password. */
	public static function is_public( int $id ): bool {
		$p = $id > 0 ? get_post( $id ) : null;
		return $p instanceof WP_Post && self::PT === $p->post_type && 'publish' === $p->post_status && '' === (string) $p->post_password;
	}

	/** int[] from a meta value. */
	public static function ids( $v ): array {
		$v = is_array( $v ) ? $v : ( is_string( $v ) && '' !== $v ? explode( ',', $v ) : array() );
		return array_values( array_unique( array_filter( array_map( 'absint', $v ) ) ) );
	}

	/** The venue of an event as an array, or null. */
	public static function venue( int $event_id ): ?array {
		$terms = get_the_terms( $event_id, self::TAX );
		if ( ! is_array( $terms ) || ! $terms ) {
			return null;
		}
		$t    = $terms[0];
		$meta = static function ( $k ) use ( $t ) {
			return trim( (string) get_term_meta( $t->term_id, $k, true ) );
		};
		$link = get_term_link( $t );
		return array(
			'id'       => (int) $t->term_id,
			'name'     => $t->name,
			'address'  => $meta( 'evt_address' ),
			'city'     => $meta( 'evt_city' ),
			'postcode' => $meta( 'evt_postcode' ),
			'country'  => '' !== $meta( 'evt_country' ) ? strtoupper( $meta( 'evt_country' ) ) : 'GR',
			'map'      => $meta( 'evt_map' ),
			'url'      => is_wp_error( $link ) ? '' : (string) $link,
		);
	}

	/**
	 * The event as an array, or null when it does not exist or is not public.
	 *
	 * Keys: id, title, url, start, end (Unix), status, prev_start, online,
	 * online_url, entry, price, link, link_kind, products, creators, kind,
	 * venue (array|null), image (URL), excerpt, ended (bool).
	 *
	 * @param int  $id  Event id.
	 * @param bool $any Also drafts and private events (admin, abilities).
	 */
	public static function event( int $id, bool $any = false ): ?array {
		$p = $id > 0 ? get_post( $id ) : null;
		if ( ! $p instanceof WP_Post || self::PT !== $p->post_type || ( ! $any && ! self::is_public( $id ) ) ) {
			return null;
		}
		$m     = static function ( $k ) use ( $id ) {
			return get_post_meta( $id, $k, true );
		};
		$start = (int) $m( '_evt_start' );
		$end   = (int) $m( '_evt_end' );
		if ( $end < $start ) {
			$end = $start;
		}
		$status = (string) $m( '_evt_status' );
		$kind   = (string) $m( '_evt_kind' );
		$entry  = (string) $m( '_evt_entry' );
		$lk     = (string) $m( '_evt_link_kind' );
		$img    = has_post_thumbnail( $p ) ? (string) get_the_post_thumbnail_url( $p, 'large' ) : '';
		return array(
			'id'         => $id,
			'title'      => wp_strip_all_tags( get_the_title( $p ) ),
			'url'        => (string) get_permalink( $p ),
			'status'     => in_array( $status, self::STATUSES, true ) ? $status : 'scheduled',
			'start'      => $start,
			'end'        => $end,
			'prev_start' => (int) $m( '_evt_prev_start' ),
			'online'     => '1' === (string) $m( '_evt_online' ),
			'online_url' => (string) $m( '_evt_online_url' ),
			'entry'      => in_array( $entry, array( 'free', 'ticket' ), true ) ? $entry : '',
			'price'      => (string) $m( '_evt_price' ),
			'link'       => (string) $m( '_evt_link' ),
			'link_kind'  => 'rsvp' === $lk ? 'rsvp' : 'tickets',
			'products'   => self::ids( get_post_meta( $id, '_evt_product', false ) ),
			'creators'   => self::ids( get_post_meta( $id, '_evt_creator', false ) ),
			'kind'       => in_array( $kind, self::KINDS, true ) ? $kind : 'Event',
			'venue'      => self::venue( $id ),
			'image'      => $img,
			'excerpt'    => self::excerpt( $p ),
			'ended'      => ( $end > 0 ? $end : $start ) < time(),
		);
	}

	/**
	 * Plain excerpt without the get_the_excerpt filters (EVT_Front prefixes
	 * the date there, and that filter reads this array: no recursion).
	 */
	private static function excerpt( WP_Post $p ): string {
		$raw = '' !== trim( (string) $p->post_excerpt ) ? (string) $p->post_excerpt : strip_shortcodes( (string) $p->post_content );
		$raw = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( excerpt_remove_blocks( $raw ) ) ) );
		return wp_trim_words( $raw, 40, '…' );
	}

	/** Unix time of a date ("Y-m-d") + time ("H:i") in the site's time zone, or 0. */
	public static function local_ts( string $date, string $time ): int {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return 0;
		}
		$time = preg_match( '/^\d{2}:\d{2}$/', $time ) ? $time : '00:00';
		$dt   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, wp_timezone() );
		return $dt && $dt->format( 'Y-m-d H:i' ) === $date . ' ' . $time ? $dt->getTimestamp() : 0;
	}

	/** Date + time in the site's time zone. */
	public static function fmt_date( int $ts, bool $time = true ): string {
		if ( $ts <= 0 ) {
			return '';
		}
		$f = (string) get_option( 'date_format' );
		if ( $time ) {
			$f .= ' ' . (string) get_option( 'time_format' );
		}
		return wp_date( $f, $ts );
	}

	/** «12 Δεκ 2026, 19:00 – 21:00» or two dates for a multi-day event. */
	public static function when( array $e ): string {
		if ( $e['start'] <= 0 ) {
			return '';
		}
		$out = self::fmt_date( $e['start'] );
		if ( $e['end'] > $e['start'] ) {
			$same = wp_date( 'Y-m-d', $e['start'] ) === wp_date( 'Y-m-d', $e['end'] );
			$out .= ' – ' . ( $same ? wp_date( (string) get_option( 'time_format' ), $e['end'] ) : self::fmt_date( $e['end'] ) );
		}
		return $out;
	}
}

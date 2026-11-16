<?php
/**
 * EVT_Query — lists of events. «Upcoming» means «has not ended yet» and is
 * checked against the current time on every read (Bible §14.27), so a
 * cached list never needs a job to drop a past event; the jobs only purge
 * page caches.
 */

defined( 'ABSPATH' ) || exit;

final class EVT_Query {

	/**
	 * Public events, as EVT_CPT::event() arrays.
	 *
	 * @param array $args {
	 *   @type int    $creator  Featured Creators post id.
	 *   @type int    $product  Product id.
	 *   @type int    $venue    nox_venue term id.
	 *   @type string $city     Venue city (exact, case-insensitive).
	 *   @type int    $limit    1–50 (default 10).
	 *   @type bool   $past     Past events, newest first (default upcoming).
	 *   @type int    $page     Page, from 1.
	 * }
	 */
	public static function find( array $args = array() ): array {
		$limit = isset( $args['limit'] ) ? max( 1, min( 50, (int) $args['limit'] ) ) : 10;
		$past  = ! empty( $args['past'] );
		$now   = time();
		$meta  = array(
			'relation' => 'AND',
			'start'    => array(
				'key'     => '_evt_start',
				'compare' => 'EXISTS',
				'type'    => 'NUMERIC',
			),
			array(
				'key'     => '_evt_end',
				'value'   => $now,
				'compare' => $past ? '<' : '>=',
				'type'    => 'NUMERIC',
			),
		);
		if ( ! empty( $args['creator'] ) ) {
			$meta[] = array(
				'key'   => '_evt_creator',
				'value' => (int) $args['creator'],
			);
		}
		if ( ! empty( $args['product'] ) ) {
			$meta[] = array(
				'key'   => '_evt_product',
				'value' => (int) $args['product'],
			);
		}
		$q = array(
			'post_type'              => EVT_CPT::PT,
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => $limit,
			'paged'                  => max( 1, (int) ( $args['page'] ?? 1 ) ),
			'meta_query'             => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery -- indexed by post type, small sets.
			'orderby'                => array( 'start' => $past ? 'DESC' : 'ASC' ),
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => true,
			'suppress_filters'       => false,
		);
		$tax = array();
		if ( ! empty( $args['venue'] ) ) {
			$tax[] = array(
				'taxonomy' => EVT_CPT::TAX,
				'field'    => 'term_id',
				'terms'    => (int) $args['venue'],
			);
		}
		if ( ! empty( $args['city'] ) ) {
			$ids = self::venues_in_city( (string) $args['city'] );
			if ( ! $ids ) {
				return array();
			}
			$tax[] = array(
				'taxonomy' => EVT_CPT::TAX,
				'field'    => 'term_id',
				'terms'    => $ids,
			);
		}
		if ( $tax ) {
			$q['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery -- small sets.
		}
		$out = array();
		foreach ( get_posts( $q ) as $p ) {
			$e = EVT_CPT::event( (int) $p->ID );
			if ( $e ) {
				$out[] = $e;
			}
		}
		return $out;
	}

	/** Venue term ids whose city matches. */
	public static function venues_in_city( string $city ): array {
		$city = mb_strtolower( trim( $city ) );
		if ( '' === $city ) {
			return array();
		}
		$ids   = array();
		$terms = get_terms(
			array(
				'taxonomy'   => EVT_CPT::TAX,
				'hide_empty' => false,
				'number'     => 500,
			)
		);
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			if ( mb_strtolower( trim( (string) get_term_meta( $t->term_id, 'evt_city', true ) ) ) === $city ) {
				$ids[] = (int) $t->term_id;
			}
		}
		return $ids;
	}
}

<?php
/**
 * EVT_Abilities — Events in the WordPress Abilities API (category noxpress):
 *
 *  events/list          upcoming (or past) events with date, venue, status,
 *                       entry and links. Read only. Drafts and private
 *                       events are left out. Capability edit_posts.
 *  events/create-draft  a DRAFT event (title, text, start, end, venue by
 *                       name, online link, entry, price, external link).
 *                       Never publishes. Capability edit_others_posts.
 *
 * Registered only on WordPress 6.9+, where the Abilities API exists.
 */

defined( 'ABSPATH' ) || exit;

final class EVT_Abilities {

	const CAT = 'noxpress';

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	public static function category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CAT ) ) {
			return;
		}
		wp_register_ability_category(
			self::CAT,
			array(
				'label'       => 'Noxpress',
				'description' => 'Operations of the Noxpress plugins.',
			)
		);
	}

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			'events/list',
			array(
				'label'               => 'Events: list',
				'description'         => 'Published events: upcoming by default (start order), or past ones with `past` (newest first). Optional filters: `city` (venue city), `creator` (Featured Creators post id), `product` (product id). Each event has its id, title, URL, start and end (ISO 8601 with the site offset), status, venue, online flag, entry and price. Changes nothing.',
				'category'            => self::CAT,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'past'    => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'city'    => array(
							'type'      => 'string',
							'maxLength' => 80,
						),
						'creator' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'product' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'limit'   => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
							'default' => 20,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'execute_callback'    => array( __CLASS__, 'list_events' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => false,
				),
			)
		);
		wp_register_ability(
			'events/create-draft',
			array(
				'label'               => 'Events: create a draft',
				'description'         => 'Creates a DRAFT event for a person to check and publish. `start` and `end` are local site times "YYYY-MM-DD HH:MM" (end defaults to two hours after the start). `venue` is the name of a venue: an existing one is used, otherwise a new venue is created with `city` and `address`. Never publishes and never changes an existing event. Returns the id and the edit link.',
				'category'            => self::CAT,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'title', 'start' ),
					'properties'           => array(
						'title'      => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 200,
						),
						'content'    => array(
							'type'      => 'string',
							'maxLength' => 20000,
						),
						'start'      => array(
							'type'    => 'string',
							'pattern' => '^\\d{4}-\\d{2}-\\d{2}( \\d{2}:\\d{2})?$',
						),
						'end'        => array(
							'type'    => 'string',
							'pattern' => '^\\d{4}-\\d{2}-\\d{2}( \\d{2}:\\d{2})?$',
						),
						'venue'      => array(
							'type'      => 'string',
							'maxLength' => 120,
						),
						'city'       => array(
							'type'      => 'string',
							'maxLength' => 80,
						),
						'address'    => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'online_url' => array(
							'type'      => 'string',
							'maxLength' => 500,
						),
						'entry'      => array(
							'type' => 'string',
							'enum' => array( '', 'free', 'ticket' ),
						),
						'price'      => array(
							'type'      => 'string',
							'maxLength' => 80,
						),
						'link'       => array(
							'type'      => 'string',
							'maxLength' => 500,
						),
						'kind'       => array(
							'type' => 'string',
							'enum' => EVT_CPT::KINDS,
						),
					),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( __CLASS__, 'create_draft' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_others_posts' );
				},
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => false,
				),
			)
		);
	}

	private static function out( array $e ): array {
		return array(
			'id'      => $e['id'],
			'title'   => $e['title'],
			'url'     => $e['url'],
			'start'   => $e['start'] > 0 ? wp_date( 'c', $e['start'] ) : '',
			'end'     => $e['end'] > 0 ? wp_date( 'c', $e['end'] ) : '',
			'status'  => $e['status'],
			'ended'   => $e['ended'],
			'venue'   => $e['venue'] ? array(
				'name'    => $e['venue']['name'],
				'address' => $e['venue']['address'],
				'city'    => $e['venue']['city'],
			) : null,
			'online'  => $e['online'],
			'entry'   => $e['entry'],
			'price'   => $e['price'],
			'link'    => $e['link'],
			'kind'    => $e['kind'],
		);
	}

	public static function list_events( $in = null ): array {
		$in   = is_array( $in ) ? $in : array();
		$args = array(
			'past'    => ! empty( $in['past'] ),
			'city'    => isset( $in['city'] ) ? sanitize_text_field( (string) $in['city'] ) : '',
			'creator' => isset( $in['creator'] ) ? absint( $in['creator'] ) : 0,
			'product' => isset( $in['product'] ) ? absint( $in['product'] ) : 0,
			'limit'   => isset( $in['limit'] ) ? max( 1, min( 50, (int) $in['limit'] ) ) : 20,
		);
		return array(
			'mode'   => EVT_Settings::mode(),
			'events' => array_map( array( __CLASS__, 'out' ), EVT_Query::find( $args ) ),
		);
	}

	/** "YYYY-MM-DD[ HH:MM]" in the site time zone → Unix, 0 when invalid. */
	private static function local( string $v ): int {
		$v = trim( $v );
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})(?: (\d{2}:\d{2}))?$/', $v, $m ) ) {
			return 0;
		}
		return EVT_CPT::local_ts( $m[1], $m[2] ?? '' );
	}

	public static function create_draft( $in = null ) {
		$in    = is_array( $in ) ? $in : array();
		$title = isset( $in['title'] ) ? sanitize_text_field( (string) $in['title'] ) : '';
		$start = self::local( (string) ( $in['start'] ?? '' ) );
		if ( '' === $title || $start <= 0 ) {
			return new WP_Error( 'evt_bad_input', 'A title and a valid start ("YYYY-MM-DD HH:MM") are required.' );
		}
		$end = self::local( (string) ( $in['end'] ?? '' ) );
		if ( $end <= $start ) {
			$end = $start + 2 * HOUR_IN_SECONDS;
		}
		$id = wp_insert_post(
			array(
				'post_type'    => EVT_CPT::PT,
				'post_status'  => 'draft',
				'post_title'   => mb_substr( $title, 0, 200 ),
				'post_content' => isset( $in['content'] ) ? wp_kses_post( (string) $in['content'] ) : '',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$id  = (int) $id;
		$url = static function ( $v ) {
			$v = esc_url_raw( trim( (string) $v ) );
			return preg_match( '#^https?://#i', $v ) ? $v : '';
		};
		update_post_meta( $id, '_evt_start', $start );
		update_post_meta( $id, '_evt_end', $end );
		update_post_meta( $id, '_evt_status', 'scheduled' );
		$kind = isset( $in['kind'] ) && in_array( $in['kind'], EVT_CPT::KINDS, true ) ? $in['kind'] : 'Event';
		update_post_meta( $id, '_evt_kind', $kind );
		$online = $url( $in['online_url'] ?? '' );
		update_post_meta( $id, '_evt_online', '' !== $online ? '1' : '' );
		update_post_meta( $id, '_evt_online_url', $online );
		$entry = isset( $in['entry'] ) && in_array( $in['entry'], array( 'free', 'ticket' ), true ) ? $in['entry'] : '';
		update_post_meta( $id, '_evt_entry', $entry );
		update_post_meta( $id, '_evt_price', mb_substr( sanitize_text_field( (string) ( $in['price'] ?? '' ) ), 0, 80 ) );
		update_post_meta( $id, '_evt_link', $url( $in['link'] ?? '' ) );
		update_post_meta( $id, '_evt_link_kind', 'tickets' );

		$venue = isset( $in['venue'] ) ? mb_substr( sanitize_text_field( (string) $in['venue'] ), 0, 120 ) : '';
		if ( '' !== $venue ) {
			$t = term_exists( $venue, EVT_CPT::TAX );
			if ( ! $t && current_user_can( 'manage_categories' ) ) {
				$t = wp_insert_term( $venue, EVT_CPT::TAX );
				if ( is_array( $t ) ) {
					update_term_meta( (int) $t['term_id'], 'evt_city', mb_substr( sanitize_text_field( (string) ( $in['city'] ?? '' ) ), 0, 80 ) );
					update_term_meta( (int) $t['term_id'], 'evt_address', mb_substr( sanitize_text_field( (string) ( $in['address'] ?? '' ) ), 0, 200 ) );
					update_term_meta( (int) $t['term_id'], 'evt_country', 'GR' );
				}
			}
			if ( is_array( $t ) && isset( $t['term_id'] ) ) {
				wp_set_object_terms( $id, array( (int) $t['term_id'] ), EVT_CPT::TAX, false );
			}
		}
		return array(
			'id'        => $id,
			'status'    => 'draft',
			'edit_link' => admin_url( 'post.php?post=' . $id . '&action=edit' ),
		);
	}
}

<?php
/**
 * EVT_Schema — Event JSON-LD on the event page.
 *
 * The type comes from the event (Event, LiteraryEvent, MusicEvent…).
 * startDate / endDate in ISO 8601 with the site's offset, eventStatus,
 * eventAttendanceMode, location (Place + PostalAddress, VirtualLocation or
 * both), image, description, offers (from the entry, price text and
 * tickets link), performer (Featured Creators), about (the products).
 *
 *  - With Search Signals: inside its graph (noxpress_seo_graph, Bible §14.38).
 *  - With Rank Math: inside its graph (rank_math/json_ld).
 *  - Otherwise our own <script type="application/ld+json"> in wp_head.
 *
 * Only for a public event, only when visitors may see the plugin.
 */

defined( 'ABSPATH' ) || exit;

final class EVT_Schema {

	/** @var bool Our node went into an SEO plugin's graph in this request. */
	private static $in_graph = false;

	public static function init(): void {
		add_filter( 'noxpress_seo_graph', array( __CLASS__, 'graph' ), 50, 2 );
		add_filter( 'rank_math/json_ld', array( __CLASS__, 'graph' ), 50, 2 );
		add_action( 'wp_head', array( __CLASS__, 'own' ), 30 );
	}

	private static function current(): ?array {
		if ( ! EVT_Settings::get()['schema'] || ! is_singular( EVT_CPT::PT ) || ! EVT_Front::visible() ) {
			return null;
		}
		return EVT_CPT::event( (int) get_queried_object_id() );
	}

	private static function iso( int $ts ): string {
		return wp_date( 'c', $ts );
	}

	private static function place( array $v ): array {
		$addr = array_filter(
			array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => $v['address'],
				'addressLocality' => $v['city'],
				'postalCode'      => $v['postcode'],
				'addressCountry'  => $v['country'],
			)
		);
		$place = array(
			'@type'   => 'Place',
			'name'    => $v['name'],
			'address' => $addr,
		);
		if ( '' !== $v['map'] ) {
			$place['hasMap'] = $v['map'];
		}
		return $place;
	}

	/** The Event node of an event array. */
	public static function node( array $e ): array {
		$n = array(
			'@type'       => $e['kind'],
			'@id'         => $e['url'] . '#event',
			'name'        => $e['title'],
			'url'         => $e['url'],
			'startDate'   => self::iso( $e['start'] ),
			'eventStatus' => 'https://schema.org/Event' . array(
				'scheduled'   => 'Scheduled',
				'postponed'   => 'Postponed',
				'cancelled'   => 'Cancelled',
				'rescheduled' => 'Rescheduled',
			)[ $e['status'] ],
		);
		if ( $e['end'] > $e['start'] ) {
			$n['endDate'] = self::iso( $e['end'] );
		}
		if ( 'rescheduled' === $e['status'] && $e['prev_start'] > 0 ) {
			$n['previousStartDate'] = self::iso( $e['prev_start'] );
		}
		$loc = array();
		if ( $e['venue'] ) {
			$loc[] = self::place( $e['venue'] );
		}
		if ( $e['online'] ) {
			$loc[] = array_filter(
				array(
					'@type' => 'VirtualLocation',
					'url'   => '' !== $e['online_url'] ? $e['online_url'] : $e['url'],
				)
			);
		}
		if ( $loc ) {
			$n['location'] = 1 === count( $loc ) ? $loc[0] : $loc;
		}
		$n['eventAttendanceMode'] = 'https://schema.org/' . ( $e['online'] ? ( $e['venue'] ? 'MixedEventAttendanceMode' : 'OnlineEventAttendanceMode' ) : 'OfflineEventAttendanceMode' );
		if ( '' !== $e['image'] ) {
			$n['image'] = array( $e['image'] );
		}
		if ( '' !== $e['excerpt'] ) {
			$n['description'] = $e['excerpt'];
		}
		if ( 'free' === $e['entry'] || 'ticket' === $e['entry'] || '' !== $e['link'] ) {
			$offer = array(
				'@type' => 'Offer',
				'url'   => '' !== $e['link'] ? $e['link'] : $e['url'],
			);
			if ( 'free' === $e['entry'] ) {
				$offer['price'] = '0';
			} elseif ( preg_match( '/(\d+(?:[.,]\d{1,2})?)/', $e['price'], $m ) ) {
				$offer['price'] = str_replace( ',', '.', $m[1] );
			}
			if ( isset( $offer['price'] ) ) {
				$offer['priceCurrency'] = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';
			}
			if ( 'cancelled' !== $e['status'] ) {
				$offer['availability'] = 'https://schema.org/InStock';
			}
			$n['offers'] = $offer;
			if ( 'free' === $e['entry'] ) {
				$n['isAccessibleForFree'] = true;
			}
		}
		$people = array();
		foreach ( $e['creators'] as $cid ) {
			$c = get_post( $cid );
			if ( $c instanceof WP_Post && 'publish' === $c->post_status && '' === (string) $c->post_password ) {
				$people[] = array(
					'@type' => 'Person',
					'name'  => wp_strip_all_tags( get_the_title( $c ) ),
					'url'   => (string) get_permalink( $c ),
				);
			}
		}
		if ( $people ) {
			$n['performer'] = 1 === count( $people ) ? $people[0] : $people;
		}
		$about = array();
		foreach ( $e['products'] as $pid ) {
			if ( 'publish' === get_post_status( $pid ) ) {
				$about[] = array(
					'@type' => 'Product',
					'name'  => wp_strip_all_tags( get_the_title( $pid ) ),
					'url'   => (string) get_permalink( $pid ),
				);
			}
		}
		if ( $about ) {
			$n['about'] = 1 === count( $about ) ? $about[0] : $about;
		}
		$n['organizer'] = array(
			'@type' => 'Organization',
			'name'  => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
			'url'   => home_url( '/' ),
		);
		/**
		 * Filters the Event node.
		 *
		 * @param array $n Node.
		 * @param array $e Event (evt_get_event()).
		 */
		return (array) apply_filters( 'evt_schema', $n, $e );
	}

	/** Search Signals / Rank Math graph: our node is added once. */
	public static function graph( $data, $ctx = null ) {
		if ( ! is_array( $data ) || self::$in_graph ) {
			return $data;
		}
		try {
			if ( is_object( $ctx ) && isset( $ctx->post_type, $ctx->post_id ) ) {
				// Search Signals builds a graph for a given address (abilities).
				$e = EVT_CPT::PT === $ctx->post_type && EVT_Settings::get()['schema'] && EVT_Front::visible() ? EVT_CPT::event( (int) $ctx->post_id ) : null;
			} else {
				$e = self::current();
			}
			if ( $e && $e['start'] > 0 ) {
				$data['evt_event'] = self::node( $e );
				self::$in_graph    = true;
			}
		} catch ( \Throwable $ex ) {
			unset( $ex );
		}
		return $data;
	}

	public static function own(): void {
		if ( self::$in_graph ) {
			return;
		}
		try {
			$e = self::current();
			if ( ! $e || $e['start'] <= 0 ) {
				return;
			}
			$node = array_merge( array( '@context' => 'https://schema.org' ), self::node( $e ) );
			echo '<script type="application/ld+json" class="evt-schema">' . wp_json_encode( $node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with JSON_HEX_TAG.
		} catch ( \Throwable $ex ) {
			unset( $ex );
		}
	}
}

<?php
/**
 * LVS_Rest — two public routes (Bible §22, «Δημόσια σημεία»):
 *
 *  GET  lvs/v1/suggest?q=  suggestions in server mode (big catalogues, or
 *       the engine set to «server»). Only what the file would hold: the
 *       findable products of the index (published, not hidden from search
 *       or by Hide Catalog), guest prices, creator pages. Read only; results
 *       kept 10 minutes in the object cache per phrase and index version.
 *  POST lvs/v1/log  {q, n}  a picked suggestion, for the statistics file.
 *       Writes only to the private file of LVS_Stats, with its limits
 *       (live mode, statistics on, no bots or shop managers, 120 lines a
 *       minute, 5 MB a month, no «@»). Answers 204 in every case, so it
 *       tells nothing.
 * Both answer 404 while the plugin is off, or in test mode to anyone but a
 * shop manager with a REST nonce.
 */

defined( 'ABSPATH' ) || exit;

final class LVS_Rest {

	const NS = 'lvs/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/suggest',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'suggest' ),
				'permission_callback' => '__return_true', // Public (§22): search suggestions, only findable products and guest prices.
				'args'                => array(
					'q' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 100,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/log',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'log' ),
				'permission_callback' => '__return_true', // Public (§22): a picked suggestion to the private statistics file, rate limited, no IP.
				'args'                => array(
					'q' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 100,
					),
					'n' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 100000,
						'default' => 0,
					),
				),
			)
		);
	}

	private static function off(): bool {
		return ! LVS_Settings::on_for_request();
	}

	public static function suggest( WP_REST_Request $req ) {
		if ( self::off() ) {
			return new WP_Error( 'rest_no_route', 'No route was found matching the URL and request method.', array( 'status' => 404 ) );
		}
		$q     = (string) $req->get_param( 'q' );
		$limit = max( 3, (int) LVS_Settings::get()['limit'] ) * 4;
		$rows  = LVS_Search::visible_rows( LVS_Search::find( $q, 'suggest', 200 ) );
		$rows  = array_slice( $rows, 0, $limit );
		$out   = self::items( $rows );
		$out['cr'] = LVS_Search::creators( $q, 3 );
		$res   = rest_ensure_response( $out );
		$res->header( 'Cache-Control', is_user_logged_in() ? 'private, no-store' : 'public, max-age=300' );
		return $res;
	}

	/** Rows in the file's shape, with the works and creators they need. */
	public static function items( array $rows ): array {
		global $wpdb;
		$s     = LVS_Settings::get();
		$items = array();
		$wids  = array();
		foreach ( $rows as $r ) {
			$items[] = self::item( $r, $s['group'] );
			if ( $s['group'] && (int) $r['work'] ) {
				$wids[ (int) $r['work'] ] = true;
			}
		}
		$works = array();
		$extra = array();
		if ( $wids && LVS_Index::exists() ) {
			$t    = LVS_Index::table();
			$in   = implode( ',', array_map( 'intval', array_keys( $wids ) ) );
			$more = $wpdb->get_results( "SELECT product_id, title, people, terms, codes, phon, cats, card, work, oos, sales FROM {$t} WHERE work IN ({$in}) ORDER BY product_id ASC LIMIT 500", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$more = LVS_Search::visible_rows( is_array( $more ) ? $more : array() );
			$have = array_flip( array_map( 'intval', wp_list_pluck( $rows, 'product_id' ) ) );
			foreach ( $more as $m ) {
				if ( 'hide' === $s['oos'] && (int) $m['oos'] ) {
					continue;
				}
				$works[ (int) $m['work'] ][] = (int) $m['product_id'];
				if ( ! isset( $have[ (int) $m['product_id'] ] ) ) {
					$extra[] = self::item( $m, true );
				}
			}
			foreach ( $works as $w => $ids ) {
				if ( count( $ids ) < 2 ) {
					unset( $works[ $w ] );
				}
			}
		}
		return array(
			'items' => $items,
			'more'  => $extra,
			'works' => (object) $works,
			'cr'    => array(),
		);
	}

	private static function item( array $r, bool $group ): array {
		$card = json_decode( (string) $r['card'], true );
		return array(
			(int) $r['product_id'],
			(string) $r['title'],
			(string) $r['people'],
			(string) $r['terms'],
			(string) $r['codes'],
			(string) $r['phon'],
			$group ? (int) $r['work'] : 0,
			(int) $r['oos'],
			(int) $r['sales'],
			is_array( $card ) ? $card : array(),
		);
	}

	public static function log( WP_REST_Request $req ) {
		if ( ! self::off() ) {
			LVS_Stats::write( (string) $req->get_param( 'q' ), (int) $req->get_param( 'n' ), 's' );
		}
		return new WP_REST_Response( null, 204 );
	}
}

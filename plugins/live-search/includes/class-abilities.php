<?php
/**
 * LVS_Abilities — Live Search in the WordPress Abilities API, for an AI or
 * a remote tool that runs abilities (e.g. Site Bridge's ability_run), with
 * the same capability (manage_woocommerce) and the same code as the admin:
 *
 *  live-search/test    what a phrase finds, ranked, as the results page
 *                      (or the suggestions) would show it (read)
 *  live-search/stats   phrases of the last N days, with those that found
 *                      nothing (read)
 *  live-search/status  mode, engine, index rows, file, queue (read)
 *
 * Registered only on WordPress 6.9+, where the Abilities API exists.
 */

defined( 'ABSPATH' ) || exit;

final class LVS_Abilities {

	const CAT = 'noxpress';

	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	/** The shared "noxpress" category, once for every Noxpress plugin. */
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
		$defs = array(
			'test'   => array(
				'label'       => 'Live Search: test a phrase',
				'description' => 'What a search phrase finds in this shop, best first, with the same rules as the visitor\'s search (no accents, greeklish, ISBN, authors, one-letter typos): id, title, score, out of stock, URL. context "results" also searches the short description, like the results page; "suggest" is what the suggestions see. Also whether an exact ISBN opens one product directly, and the matching creator pages.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'q'       => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 100,
						),
						'context' => array(
							'type'    => 'string',
							'enum'    => array( 'results', 'suggest' ),
							'default' => 'results',
						),
						'limit'   => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
					),
					'required'             => array( 'q' ),
					'additionalProperties' => false,
				),
				'run'         => array( __CLASS__, 'test' ),
			),
			'stats'  => array(
				'label'       => 'Live Search: search statistics',
				'description' => 'What visitors searched in the last N days (from the private statistics file: phrase, times, how many of them found nothing, results the last time, last time), most searched first, and the phrases that found nothing. No IP or user is ever recorded.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'days'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 90,
							'default' => 30,
						),
						'limit' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 200,
							'default' => 50,
						),
					),
					'additionalProperties' => false,
				),
				'run'         => array( __CLASS__, 'stats' ),
			),
			'status' => array(
				'label'       => 'Live Search: status',
				'description' => 'Mode (off / test: shop managers only / live), engine now (file: suggestions from a static file in the browser; server: from the REST route), products in the index, the file (name, bytes, products, built), products waiting in the queue, last full pass.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => new stdClass(),
					'additionalProperties' => false,
				),
				'run'         => array( __CLASS__, 'status' ),
			),
		);
		foreach ( $defs as $name => $d ) {
			wp_register_ability(
				'live-search/' . $name,
				array(
					'label'               => $d['label'],
					'description'         => $d['description'],
					'category'            => self::CAT,
					'input_schema'        => $d['input'],
					'execute_callback'    => $d['run'],
					'permission_callback' => array( __CLASS__, 'allowed' ),
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
		}
	}

	public static function allowed(): bool {
		return current_user_can( Live_Search::CAP );
	}

	public static function test( $in = null ): array {
		$in      = is_array( $in ) ? $in : array();
		$q       = isset( $in['q'] ) ? (string) $in['q'] : '';
		$context = isset( $in['context'] ) && 'suggest' === $in['context'] ? 'suggest' : 'results';
		$limit   = isset( $in['limit'] ) ? max( 1, min( 100, (int) $in['limit'] ) ) : 20;
		$rows    = LVS_Search::visible_rows( LVS_Search::find( $q, $context, 500 ) );
		$out     = array();
		foreach ( array_slice( $rows, 0, $limit ) as $r ) {
			$card  = json_decode( (string) $r['card'], true );
			$out[] = array(
				'id'           => (int) $r['product_id'],
				'title'        => is_array( $card ) ? (string) ( $card['t'] ?? '' ) : '',
				'score'        => (int) $r['score'],
				'out_of_stock' => (bool) $r['oos'],
				'url'          => is_array( $card ) ? (string) ( $card['u'] ?? '' ) : '',
			);
		}
		$isbn = LVS_Search::isbn_product( $q );
		return array(
			'query'      => $q,
			'normalised' => LVS_Text::norm( $q ),
			'phonetic'   => LVS_Text::phon( LVS_Text::norm( $q ) ),
			'found'      => count( $rows ),
			'results'    => $out,
			'opens'      => $isbn > 0 && LVS_Settings::get()['isbn_direct'] ? (string) get_permalink( $isbn ) : '',
			'creators'   => array_map(
				static function ( $c ) {
					return array(
						'name' => $c[0],
						'url'  => $c[1],
					);
				},
				LVS_Search::creators( $q, 5 )
			),
		);
	}

	public static function stats( $in = null ): array {
		$in    = is_array( $in ) ? $in : array();
		$days  = isset( $in['days'] ) ? max( 1, min( 90, (int) $in['days'] ) ) : 30;
		$limit = isset( $in['limit'] ) ? max( 1, min( 200, (int) $in['limit'] ) ) : 50;
		$r     = LVS_Stats::report( $days );
		$fmt   = static function ( $x ) {
			return array(
				'phrase'      => $x['q'],
				'times'       => $x['count'],
				'found_none'  => $x['zero'],
				'last_found'  => $x['found'],
				'last'        => gmdate( 'c', $x['last'] ),
			);
		};
		$zero  = array_values(
			array_filter(
				$r['rows'],
				static function ( $x ) {
					return 0 === $x['found'];
				}
			)
		);
		return array(
			'days'        => $days,
			'searches'    => $r['total'],
			'found_none'  => $r['zero'],
			'stats_on'    => LVS_Settings::get()['stats'],
			'top'         => array_map( $fmt, array_slice( $r['rows'], 0, $limit ) ),
			'nothing'     => array_map( $fmt, array_slice( $zero, 0, $limit ) ),
		);
	}

	public static function status( $in = null ): array {
		$s  = LVS_Settings::get();
		$f  = LVS_File::state();
		$st = LVS_Index::state();
		$q  = get_option( LVS_Index::OPT_Q, array() );
		return array(
			'mode'       => $s['mode'],
			'engine'     => LVS_Settings::engine(),
			'products'   => LVS_Index::count_visible(),
			'file'       => array(
				'url'      => LVS_File::current_url(),
				'bytes'    => (int) ( $f['bytes'] ?? 0 ),
				'products' => (int) ( $f['count'] ?? 0 ),
				'built'    => ! empty( $f['built'] ) ? gmdate( 'c', (int) $f['built'] ) : '',
			),
			'queue'      => is_array( $q ) ? count( $q ) : 0,
			'full_built' => ! empty( $st['built'] ) ? gmdate( 'c', (int) $st['built'] ) : '',
			'disabled'   => Live_Search::killed(),
		);
	}
}

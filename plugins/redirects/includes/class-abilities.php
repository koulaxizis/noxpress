<?php
/**
 * RDR_Abilities — Redirects in the WordPress Abilities API, for an AI or a
 * remote tool that runs abilities (e.g. Site Bridge's ability_run), with
 * the same capability (manage_options) and the same code as the admin:
 *
 *  redirects/list      rules, with search (read)
 *  redirects/top-404   the 404 report of the last N days, with
 *                      suggestions for the top paths (read)
 *  redirects/test-url  what a visit to an address would meet (read)
 *  redirects/add       adds one rule, with every check of the admin page;
 *                      a 301 starts staged (302) when the setting says so
 *
 * Registered only on WordPress 6.9+, where the Abilities API exists.
 */

defined( 'ABSPATH' ) || exit;

final class RDR_Abilities {

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
			'list'     => array(
				'label'       => 'Redirects: list rules',
				'description' => 'Redirect rules of this site, newest first: id, from (a path, or a regex), match (exact / prefix / regex), target URL now, whether the target still exists, code (301 / 302 / 307 / 410), staged (a 301 that answers 302 until it is confirmed), active, note, hits in the last 30 days. Also the mode (test: rules answer only administrators; live) and the counts.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'search' => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 500,
							'default' => 100,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'list_rules' ),
			),
			'top-404'  => array(
				'label'       => 'Redirects: top 404',
				'description' => 'Addresses that answered 404 and that no rule, WordPress or Greeklish Permalinks redirected, from the private log file (no IP, user agent or query string is ever logged; scanner paths are not logged). Per path: hits, first and last time, hits by crawlers, hits from links of this site, top referrer hosts, and up to 3 suggested targets for the first 20 paths. Ignored paths are left out.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'days'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 366,
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
					'default'              => array(),
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'top_404' ),
			),
			'test-url' => array(
				'label'       => 'Redirects: test an address',
				'description' => 'What a visit to an address of this site would meet, without opening it: result (exists, rule, old_slug = WordPress redirects it, greeklish = Greeklish Permalinks may redirect it, protected, external, 404), the rule id, the code and the target.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'url' => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 2000,
						),
					),
					'required'             => array( 'url' ),
					'additionalProperties' => false,
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'test_url' ),
			),
			'add'      => array(
				'label'       => 'Redirects: add a rule',
				'description' => 'Adds one rule with the checks of WP admin (old address of this site, never the home page, admin, login, REST, cart, checkout or account; no loops; chains flattened; one exact rule per address). from: a path or URL of this site, or a regex for match = regex (on the decoded, lower-case path without the first slash; $1…$9 in the target). to: a path, a URL, or empty with code 410. A 301 starts as 302 until it is confirmed in WP admin when the «start as 302» setting is on. Returns the rule or an error.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'from'  => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 500,
						),
						'to'    => array(
							'type'      => 'string',
							'maxLength' => 2000,
							'default'   => '',
						),
						'code'  => array(
							'type'    => 'integer',
							'enum'    => array( 301, 302, 307, 410 ),
							'default' => 301,
						),
						'match' => array(
							'type'    => 'string',
							'enum'    => array( 'exact', 'prefix', 'regex' ),
							'default' => 'exact',
						),
						'note'  => array(
							'type'      => 'string',
							'maxLength' => 200,
							'default'   => '',
						),
					),
					'required'             => array( 'from' ),
					'additionalProperties' => false,
				),
				'ann'         => array( false, false, false ),
				'run'         => array( __CLASS__, 'add' ),
			),
		);
		foreach ( $defs as $name => $d ) {
			wp_register_ability(
				'redirects/' . $name,
				array(
					'label'               => $d['label'],
					'description'         => $d['description'],
					'category'            => self::CAT,
					'input_schema'        => $d['input'],
					'execute_callback'    => $d['run'],
					'permission_callback' => array( __CLASS__, 'allowed' ),
					'meta'                => array(
						'annotations'  => array(
							'readonly'    => $d['ann'][0],
							'destructive' => $d['ann'][1],
							'idempotent'  => $d['ann'][2],
						),
						'show_in_rest' => false,
					),
				)
			);
		}
	}

	public static function allowed(): bool {
		return current_user_can( Redirects::CAP );
	}

	/** A rule for the outside. */
	public static function out( array $r, array $hits = array() ): array {
		list( $url, $ok ) = RDR_Rules::describe_target( $r );
		return array(
			'id'          => $r['id'],
			'from'        => 'regex' === $r['match'] ? $r['src'] : '/' . $r['src'],
			'match'       => $r['match'],
			'to'          => $url,
			'target_ok'   => $ok,
			'code'        => $r['code'],
			'answers'     => RDR_Rules::live_code( $r ),
			'staged'      => $r['staged'],
			'active'      => $r['active'],
			'note'        => $r['note'],
			'created'     => gmdate( 'c', $r['created'] ),
			'hits_30days' => isset( $hits[ $r['id'] ] ) ? (int) $hits[ $r['id'] ] : 0,
		);
	}

	public static function list_rules( $in = null ): array {
		$in   = is_array( $in ) ? $in : array();
		$all  = RDR_Rules::all(
			array(
				'search' => isset( $in['search'] ) ? (string) $in['search'] : '',
				'per'    => isset( $in['limit'] ) ? (int) $in['limit'] : 100,
			)
		);
		$hits = RDR_Log::hits( 30 );
		$rows = array();
		foreach ( $all['rows'] as $r ) {
			$rows[] = self::out( $r, $hits );
		}
		return array(
			'version'  => RDR_VERSION,
			'mode'     => RDR_Settings::get()['mode'],
			'disabled' => Redirects::killed(),
			'counts'   => RDR_Rules::counts(),
			'total'    => $all['total'],
			'rules'    => $rows,
		);
	}

	public static function top_404( $in = null ): array {
		$in    = is_array( $in ) ? $in : array();
		$days  = isset( $in['days'] ) ? max( 1, min( 366, (int) $in['days'] ) ) : 30;
		$limit = isset( $in['limit'] ) ? max( 1, min( 200, (int) $in['limit'] ) ) : 50;
		$ign   = get_option( 'rdr_ignored', array() );
		$rep   = RDR_Log::report( $days, is_array( $ign ) ? $ign : array() );
		if ( ! class_exists( 'RDR_Suggest' ) ) {
			require_once RDR_PATH . 'includes/class-suggest.php';
		}
		$rows = array();
		foreach ( array_slice( $rep['rows'], 0, $limit ) as $i => $r ) {
			$rows[] = array(
				'path'        => '/' . $r['path'],
				'hits'        => $r['hits'],
				'first'       => gmdate( 'c', $r['first'] ),
				'last'        => gmdate( 'c', $r['last'] ),
				'crawlers'    => $r['bots'],
				'from_site'   => $r['self'],
				'referrers'   => $r['hosts'],
				'suggestions' => $i < 20 ? RDR_Suggest::for_path( $r['path'] ) : array(),
			);
		}
		return array(
			'days'       => $days,
			'log'        => (bool) RDR_Settings::get()['log'],
			'hits'       => $rep['total'],
			'paths'      => $rep['paths'],
			'crawlers'   => $rep['bots'],
			'from_site'  => $rep['self'],
			'rows'       => $rows,
		);
	}

	public static function test_url( $in = null ): array {
		$url = is_array( $in ) && isset( $in['url'] ) ? (string) $in['url'] : '';
		return RDR_Rules::explain( $url );
	}

	public static function add( $in = null ) {
		$in = is_array( $in ) ? $in : array();
		$r  = RDR_Rules::save(
			array(
				'src'    => isset( $in['from'] ) ? (string) $in['from'] : '',
				'target' => isset( $in['to'] ) ? (string) $in['to'] : '',
				'code'   => isset( $in['code'] ) ? (int) $in['code'] : 301,
				'match'  => isset( $in['match'] ) ? (string) $in['match'] : 'exact',
				'staged' => (bool) RDR_Settings::get()['staged'],
				'note'   => isset( $in['note'] ) && '' !== $in['note'] ? (string) $in['note'] : 'ability',
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$out          = self::out( $r );
		$out['notes'] = $r['msgs'];
		return $out;
	}
}

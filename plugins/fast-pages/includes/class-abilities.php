<?php
/**
 * FPG_Abilities — Fast Pages in the WordPress Abilities API, for an AI or
 * a remote tool that runs abilities (e.g. Site Bridge's ability_run), with
 * the same capability (manage_options) and the same code as the buttons:
 *
 *  fast-pages/status        on / off and why, test mode, checks, cached
 *                           pages and size, preload, last purge (read)
 *  fast-pages/settings-get  every setting (read)
 *  fast-pages/test-url      requests a page of this site twice as a
 *                           visitor: status, X-Fast-Pages, time, size (read)
 *  fast-pages/purge         everything, some URLs or some posts
 *  fast-pages/preload       starts or stops the preload
 *  fast-pages/settings-set  changes settings (validated as in the admin);
 *                           not "enabled": turning the page cache on or
 *                           off edits wp-config.php and stays a button
 *  fast-pages/db-cleanup    deletes post revisions and expired transients
 *                           (destructive: Site Bridge asks for "admin")
 *
 * Registered only on WordPress 6.9+, where the Abilities API exists.
 */

defined( 'ABSPATH' ) || exit;

final class FPG_Abilities {

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

	/** Settings the settings-set ability may change, with their schema. */
	private static function settable(): array {
		$bool = array( 'type' => 'boolean' );
		$text = array(
			'type'        => 'string',
			'description' => 'One entry per line, * = anything.',
		);
		return array(
			'test'            => $bool,
			'life'            => array(
				'type' => 'integer',
				'enum' => FPG_Settings::LIVES,
			),
			'mobile'          => $bool,
			'exclude_urls'    => $text,
			'exclude_cookies' => $text,
			'preload'         => $bool,
			'rate'            => array(
				'type' => 'integer',
				'enum' => FPG_Settings::RATES,
			),
			'preload_max'     => array(
				'type'    => 'integer',
				'minimum' => 50,
				'maximum' => 10000,
			),
			'preload_exclude' => $text,
			'minify_css'      => $bool,
			'minify_js'       => $bool,
			'minify_exclude'  => $text,
			'lazy_img'        => $bool,
			'lazy_skip'       => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 10,
			),
			'lazy_iframe'     => $bool,
			'lazy_exclude'    => $text,
			'lazy_bg'         => $bool,
			'youtube'         => $bool,
			'dims'            => $bool,
			'hover'           => array(
				'type' => 'string',
				'enum' => FPG_Settings::HOVER,
			),
		);
	}

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$none = array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
			'default'              => array(),
		);
		$defs = array(
			'status'       => array(
				'label'       => 'Fast Pages: status',
				'description' => 'Whether the page cache answers visitors (ready), and if not why (checks: conflicts, drop-in, WP_CACHE, folder, FPG_DISABLE), test mode, cached pages and their size, minified files, the preload job, the last full purge. Changes nothing.',
				'input'       => $none,
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'status' ),
			),
			'settings-get' => array(
				'label'       => 'Fast Pages: settings',
				'description' => 'Every setting of Fast Pages (cache, preload, optimizations, hover preload). Changes nothing.',
				'input'       => $none,
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'settings_get' ),
			),
			'test-url'     => array(
				'label'       => 'Fast Pages: test a URL',
				'description' => 'Requests one page of this site twice as a logged-out visitor and returns, for each request, the HTTP status, the X-Fast-Pages header (HIT, MISS, BYPASS with the reason), the time in ms and the size. The second request is normally a HIT. In test mode, ?fpg-test=1 is added so the cache answers.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'url' => array(
							'type'        => 'string',
							'description' => 'A path such as /shop/ or a full URL on this site.',
						),
					),
					'required'             => array( 'url' ),
					'additionalProperties' => false,
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'test_url' ),
			),
			'purge'        => array(
				'label'       => 'Fast Pages: purge the cache',
				'description' => 'Deletes cached pages: everything (all = true), some URLs of this site, or the pages that show some posts / products (post_ids: the post, home, shop, archives, terms, author). With the preload on, they are requested again in the background.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'all'      => array( 'type' => 'boolean' ),
						'urls'     => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
							'maxItems' => 200,
						),
						'post_ids' => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'integer' ),
							'maxItems' => 200,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'ann'         => array( false, false, true ),
				'run'         => array( __CLASS__, 'purge' ),
			),
			'preload'      => array(
				'label'       => 'Fast Pages: preload',
				'description' => 'Starts (action = start) or stops (action = stop) the background job that requests every page of the sitemap so the cache is filled. Follow it with fast-pages/status.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'action' => array(
							'type' => 'string',
							'enum' => array( 'start', 'stop' ),
						),
					),
					'required'             => array( 'action' ),
					'additionalProperties' => false,
				),
				'ann'         => array( false, false, true ),
				'run'         => array( __CLASS__, 'preload' ),
			),
			'settings-set' => array(
				'label'       => 'Fast Pages: change settings',
				'description' => 'Changes settings, validated as in WP admin; unknown or invalid values keep the current one. Returns all settings. "enabled" is not here: turning the page cache on or off edits wp-config.php and is done by the owner in WP admin. test = false makes the cache answer every visitor.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => self::settable(),
					'additionalProperties' => false,
				),
				'ann'         => array( false, false, true ),
				'run'         => array( __CLASS__, 'settings_set' ),
			),
			'db-cleanup'   => array(
				'label'       => 'Fast Pages: database cleanup',
				'description' => 'Deletes post revisions (at most 500 per run, through WordPress) and expired transients. Cannot be undone. Returns the counts before and after.',
				'input'       => $none,
				'ann'         => array( false, true, false ),
				'run'         => array( __CLASS__, 'db_cleanup' ),
			),
		);
		foreach ( $defs as $name => $d ) {
			wp_register_ability(
				'fast-pages/' . $name,
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
		return current_user_can( Fast_Pages::CAP );
	}

	/* =====================================================================
	 * Read
	 * =================================================================== */

	/** The checks of the Overview tab: key => array( ok, text ). */
	public static function checks(): array {
		$conf  = FPG_Setup::conflicts();
		$drop  = FPG_Setup::dropin();
		$wc    = FPG_Setup::wp_cache_on();
		$dirok = FPG_Files::writable();
		return array(
			'killed'   => array( ! Fast_Pages::killed(), Fast_Pages::killed() ? 'FPG_DISABLE is set in wp-config.php.' : 'ok' ),
			'conflict' => array( ! $conf, $conf ? 'Another page cache is active: ' . implode( ', ', $conf ) . '.' : 'ok' ),
			'dropin'   => array( 'ours' === $drop, 'ours' === $drop ? 'ok' : ( 'foreign' === $drop ? 'wp-content/advanced-cache.php belongs to another plugin.' : 'wp-content/advanced-cache.php is missing.' ) ),
			'wp_cache' => array( $wc, $wc ? 'ok' : "WP_CACHE is not on in wp-config.php: add define( 'WP_CACHE', true );" ),
			'folder'   => array( $dirok, $dirok ? 'ok' : 'wp-content/cache/fast-pages/ is not writable.' ),
		);
	}

	public static function status( $in = null ): array {
		$s      = FPG_Settings::get();
		$st     = FPG_Setup::state();
		$checks = array();
		foreach ( self::checks() as $k => $c ) {
			$checks[ $k ] = $c[1];
		}
		return array(
			'version'    => FPG_VERSION,
			'enabled'    => $s['enabled'],
			'ready'      => FPG_Setup::ready(),
			'test_mode'  => $s['test'],
			'checks'     => $checks,
			'minifiers'  => FPG_Setup::minifiers(),
			'cache'      => FPG_Files::stats(),
			'life_hours' => $s['life'],
			'preload'    => FPG_Preload::summary(),
			'last_purge' => $st['last_purge'] ? gmdate( 'c', $st['last_purge'] ) : null,
		);
	}

	public static function settings_get( $in = null ): array {
		return FPG_Settings::get();
	}

	public static function test_url( $in = null ) {
		$url = trim( (string) ( is_array( $in ) ? ( $in['url'] ?? '' ) : '' ) );
		if ( '' === $url ) {
			return new WP_Error( 'fpg_url', 'Give a path or a URL of this site.' );
		}
		if ( '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
			$url = home_url( $url );
		}
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return new WP_Error( 'fpg_url', 'Only URLs of this site can be tested.' );
		}
		if ( FPG_Settings::get()['test'] ) {
			$url = add_query_arg( FPG_Key::TEST_QS, '1', $url );
		}
		$tries = array();
		for ( $i = 0; $i < 2; $i++ ) {
			$t0      = microtime( true );
			$r       = wp_remote_get(
				$url,
				array(
					'timeout'     => 20,
					'redirection' => 0,
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core filter.
					'cookies'     => array(),
					'user-agent'  => 'Mozilla/5.0 (compatible; FastPagesTest/1.0)',
				)
			);
			$tries[] = is_wp_error( $r ) ? array( 'error' => $r->get_error_message() ) : array(
				'status'       => (int) wp_remote_retrieve_response_code( $r ),
				'x_fast_pages' => (string) wp_remote_retrieve_header( $r, 'x-fast-pages' ),
				'ms'           => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
				'bytes'        => strlen( (string) wp_remote_retrieve_body( $r ) ),
				'location'     => (string) wp_remote_retrieve_header( $r, 'location' ),
			);
		}
		return array(
			'url'   => $url,
			'tries' => $tries,
		);
	}

	/* =====================================================================
	 * Write
	 * =================================================================== */

	public static function purge( $in = null ): array {
		$in = is_array( $in ) ? $in : array();
		if ( ! empty( $in['all'] ) ) {
			return array( 'deleted' => FPG_Purge::all() );
		}
		$urls = array();
		foreach ( array_slice( (array) ( $in['urls'] ?? array() ), 0, 200 ) as $u ) {
			$u = trim( (string) $u );
			if ( '' !== $u && '/' === $u[0] && ( ! isset( $u[1] ) || '/' !== $u[1] ) ) {
				$u = home_url( $u );
			}
			$urls[] = esc_url_raw( $u );
		}
		foreach ( array_slice( (array) ( $in['post_ids'] ?? array() ), 0, 200 ) as $id ) {
			$link = get_permalink( (int) $id );
			if ( $link ) {
				$urls[] = $link;
			}
		}
		if ( ! $urls ) {
			return array(
				'deleted' => 0,
				'note'    => 'Nothing given: pass all = true, urls or post_ids.',
			);
		}
		return array( 'deleted' => FPG_Purge::urls( $urls ) );
	}

	public static function preload( $in = null ) {
		$act = is_array( $in ) ? (string) ( $in['action'] ?? '' ) : '';
		if ( 'stop' === $act ) {
			FPG_Preload::stop();
			return FPG_Preload::summary();
		}
		if ( ! FPG_Setup::ready() ) {
			return new WP_Error( 'fpg_ready', 'The page cache is not ready (see fast-pages/status).' );
		}
		FPG_Preload::start();
		return FPG_Preload::summary();
	}

	public static function settings_set( $in = null ): array {
		$in = is_array( $in ) ? array_intersect_key( $in, self::settable() ) : array();
		$s  = FPG_Settings::update( $in );
		if ( array_intersect_key( $in, array_flip( array( 'minify_css', 'minify_js', 'lazy_img', 'lazy_iframe', 'lazy_bg', 'youtube', 'dims', 'mobile', 'exclude_urls', 'exclude_cookies', 'lazy_skip', 'lazy_exclude', 'minify_exclude' ) ) ) ) {
			FPG_Purge::all();
		}
		return $s;
	}

	public static function db_cleanup( $in = null ): array {
		return FPG_Tools::db_cleanup();
	}
}

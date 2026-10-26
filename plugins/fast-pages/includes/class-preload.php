<?php
/**
 * FPG_Preload — fills the cache in the background, so visitors find pages
 * ready (Bible §11, "without a browser").
 *
 * The URL list comes from the sitemap (Rank Math's sitemap_index.xml, else
 * the WordPress wp-sitemap.xml), else from the published posts, pages and
 * products. A job requests RATE pages a minute, as a logged-out visitor,
 * skipping pages that are already cached and fresh; the job state is the
 * option fpg_preload (no autoload). One tick per minute on the Action
 * Scheduler (group fast-pages), else WP-Cron; each tick at most 20 s.
 *
 * Purged pages are refilled first (urgent list), a full purge restarts the
 * job, and the hourly upkeep deletes expired pages and starts the job again
 * once a lifespan has passed since the last one.
 */

defined( 'ABSPATH' ) || exit;

final class FPG_Preload {

	const OPTION  = 'fpg_preload';
	const URGENT  = 'fpg_preload_urgent';
	const TICK    = 'fpg_preload_tick';
	const UPKEEP  = 'fpg_upkeep';
	const GROUP   = 'fast-pages';
	const BUDGET  = 20;
	const UA      = 'Mozilla/5.0 (compatible; FastPagesPreload/1.0; +https://noxpress.tech)';
	const UA_M    = 'Mozilla/5.0 (Linux; Android 14; Mobile; FastPagesPreload/1.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';
	const MAX_MAP = 50;

	public static function init(): void {
		add_action( self::TICK, array( __CLASS__, 'tick' ) );
		add_action( self::UPKEEP, array( __CLASS__, 'upkeep' ) );
		add_action( 'init', array( __CLASS__, 'schedule_upkeep' ), 99 );
	}

	/* =====================================================================
	 * Job state
	 * =================================================================== */

	public static function job(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'state'   => in_array( $raw['state'] ?? '', array( 'running', 'done', 'stopped' ), true ) ? $raw['state'] : '',
			'urls'    => isset( $raw['urls'] ) && is_array( $raw['urls'] ) ? array_values( array_map( 'strval', $raw['urls'] ) ) : array(),
			'pos'     => isset( $raw['pos'] ) ? max( 0, (int) $raw['pos'] ) : 0,
			'done'    => isset( $raw['done'] ) ? (int) $raw['done'] : 0,
			'skipped' => isset( $raw['skipped'] ) ? (int) $raw['skipped'] : 0,
			'errors'  => isset( $raw['errors'] ) ? (int) $raw['errors'] : 0,
			'source'  => isset( $raw['source'] ) ? (string) $raw['source'] : '',
			'started' => isset( $raw['started'] ) ? (int) $raw['started'] : 0,
			'ended'   => isset( $raw['ended'] ) ? (int) $raw['ended'] : 0,
		);
	}

	private static function save_job( array $job ): void {
		update_option( self::OPTION, $job, false );
	}

	/** Summary for the admin page and the status ability. */
	public static function summary(): array {
		$j = self::job();
		return array(
			'state'   => $j['state'] ? $j['state'] : 'never',
			'total'   => count( $j['urls'] ),
			'pos'     => min( $j['pos'], count( $j['urls'] ) ),
			'done'    => $j['done'],
			'skipped' => $j['skipped'],
			'errors'  => $j['errors'],
			'source'  => $j['source'],
			'started' => $j['started'] ? gmdate( 'c', $j['started'] ) : null,
			'ended'   => $j['ended'] ? gmdate( 'c', $j['ended'] ) : null,
			'urgent'  => count( self::urgent_list() ),
		);
	}

	/* =====================================================================
	 * Start / stop
	 * =================================================================== */

	/** Starts a full preload (the list is built on the first tick). */
	public static function start(): bool {
		if ( ! FPG_Setup::ready() ) {
			return false;
		}
		self::save_job(
			array(
				'state'   => 'running',
				'urls'    => array(),
				'pos'     => 0,
				'done'    => 0,
				'skipped' => 0,
				'errors'  => 0,
				'source'  => '',
				'started' => time(),
				'ended'   => 0,
			)
		);
		self::schedule( 5 );
		return true;
	}

	public static function stop(): void {
		$j = self::job();
		if ( 'running' === $j['state'] ) {
			$j['state'] = 'stopped';
			$j['ended'] = time();
			self::save_job( $j );
		}
		delete_option( self::URGENT );
		self::unschedule();
	}

	/** After a full purge: start again when the preload is on. */
	public static function after_purge(): void {
		if ( FPG_Settings::get()['preload'] && FPG_Setup::ready() ) {
			self::start();
		}
	}

	/** Purged pages: refill them first (admin, cron, CLI only). */
	public static function urgent( array $urls ): void {
		if ( ! FPG_Settings::get()['preload'] || ! FPG_Setup::ready() || ! $urls ) {
			return;
		}
		$list = array_values( array_unique( array_merge( self::urgent_list(), array_map( 'strval', $urls ) ) ) );
		update_option( self::URGENT, array_slice( $list, 0, 500 ), false );
		self::schedule( 30 );
	}

	private static function urgent_list(): array {
		$v = get_option( self::URGENT, array() );
		return is_array( $v ) ? array_values( array_map( 'strval', $v ) ) : array();
	}

	/* =====================================================================
	 * Scheduling
	 * =================================================================== */

	private static function schedule( int $in ): void {
		if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
			if ( ! as_has_scheduled_action( self::TICK, array(), self::GROUP ) ) {
				as_schedule_single_action( time() + $in, self::TICK, array(), self::GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::TICK ) ) {
			wp_schedule_single_event( time() + $in, self::TICK );
		}
	}

	private static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::TICK );
		}
		wp_clear_scheduled_hook( self::TICK );
	}

	/** Hourly upkeep, only while the page cache is on (admin requests). */
	public static function schedule_upkeep(): void {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}
		$on = FPG_Settings::get()['enabled'];
		$at = wp_next_scheduled( self::UPKEEP );
		if ( $on && ! $at ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::UPKEEP );
		} elseif ( ! $on && $at ) {
			wp_clear_scheduled_hook( self::UPKEEP );
		}
	}

	/** Deletes expired pages; restarts the preload once a lifespan passed. */
	public static function upkeep(): void {
		if ( ! FPG_Settings::get()['enabled'] ) {
			return;
		}
		$life = FPG_Settings::life_seconds();
		FPG_Files::purge_older( $life );
		if ( ! FPG_Settings::get()['preload'] || ! FPG_Setup::ready() ) {
			return;
		}
		$j     = self::job();
		$every = $life > 0 ? $life : DAY_IN_SECONDS;
		if ( 'running' !== $j['state'] && $j['started'] + $every <= time() + 300 ) {
			self::start();
		}
	}

	/* =====================================================================
	 * Tick
	 * =================================================================== */

	public static function tick(): void {
		$s = FPG_Settings::get();
		if ( ! FPG_Setup::ready() ) {
			return;
		}
		$start  = time();
		$rate   = (int) $s['rate'];
		$fetch  = 0;
		$urgent = self::urgent_list();
		if ( $urgent ) {
			$take = array_splice( $urgent, 0, $rate );
			update_option( self::URGENT, $urgent, false );
			foreach ( $take as $u ) {
				$fetch += self::warm( $u, $s['mobile'] ) ? 1 : 0;
			}
		}

		$j = self::job();
		if ( 'running' === $j['state'] ) {
			if ( ! $j['urls'] && 0 === $j['pos'] ) {
				list( $j['urls'], $j['source'] ) = self::collect( (int) $s['preload_max'] );
			}
			while ( $j['pos'] < count( $j['urls'] ) && $fetch < $rate && time() - $start < self::BUDGET ) {
				$u = $j['urls'][ $j['pos'] ];
				++$j['pos'];
				if ( self::fresh( $u ) ) {
					++$j['skipped'];
					continue;
				}
				++$fetch;
				if ( self::warm( $u, $s['mobile'] ) ) {
					++$j['done'];
				} else {
					++$j['errors'];
				}
			}
			if ( $j['pos'] >= count( $j['urls'] ) ) {
				$j['state'] = 'done';
				$j['ended'] = time();
			}
			self::save_job( $j );
		}

		if ( 'running' === $j['state'] || self::urgent_list() ) {
			self::schedule( 60 );
		}
	}

	/** The page is cached and fresh (desktop file). */
	private static function fresh( string $url ): bool {
		$files = FPG_Key::url( $url, FPG_Setup::config() );
		if ( ! $files ) {
			return true; // Never cached: nothing to do.
		}
		$t    = @filemtime( $files[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$life = FPG_Settings::life_seconds();
		return false !== $t && ( 0 === $life || $t + $life > time() + 60 );
	}

	/** Requests a page as a visitor (and as a phone, with the mobile cache). */
	private static function warm( string $url, bool $mobile ): bool {
		$ok = self::get( $url, self::UA );
		if ( $mobile ) {
			self::get( $url, self::UA_M );
		}
		return $ok;
	}

	private static function get( string $url, string $ua ): bool {
		$r = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'user-agent'  => $ua,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core filter.
				'headers'     => array( 'Accept' => 'text/html' ),
				'cookies'     => array(),
			)
		);
		return ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r );
	}

	/* =====================================================================
	 * URL list
	 * =================================================================== */

	/** array( urls, source ). */
	public static function collect( int $max ): array {
		$cfg     = FPG_Setup::config();
		$exclude = array();
		foreach ( FPG_Settings::lines( 'preload_exclude' ) as $line ) {
			$re = FPG_Key::pattern( $line );
			if ( '' !== $re ) {
				$exclude[] = $re;
			}
		}
		$source = 'posts';
		$urls   = self::from_sitemap( $max );
		if ( $urls ) {
			$source = 'sitemap';
		} else {
			$urls = self::from_posts( $max );
		}
		array_unshift( $urls, home_url( '/' ) );
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			array_splice( $urls, 1, 0, array( wc_get_page_permalink( 'shop' ) ) );
		}
		$out = array();
		foreach ( $urls as $u ) {
			$u = (string) $u;
			if ( isset( $out[ $u ] ) || ! FPG_Key::url( $u, $cfg ) ) {
				continue;
			}
			$path = (string) wp_parse_url( $u, PHP_URL_PATH );
			foreach ( $exclude as $re ) {
				if ( @preg_match( $re, rawurldecode( $path ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					continue 2;
				}
			}
			$out[ $u ] = true;
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return array( array_keys( $out ), $source );
	}

	/** The sitemap index to read, or ''. */
	public static function sitemap_url(): string {
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return home_url( '/sitemap_index.xml' );
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return home_url( '/sitemap_index.xml' );
		}
		if ( function_exists( 'wp_sitemaps_get_server' ) ) {
			$server = wp_sitemaps_get_server();
			if ( $server && method_exists( $server, 'sitemaps_enabled' ) && $server->sitemaps_enabled() ) {
				return home_url( '/wp-sitemap.xml' );
			}
		}
		return '';
	}

	private static function from_sitemap( int $max ): array {
		$index = self::sitemap_url();
		if ( '' === $index ) {
			return array();
		}
		$queue = array( $index );
		$seen  = array();
		$urls  = array();
		$host  = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		while ( $queue && count( $seen ) < self::MAX_MAP && count( $urls ) < $max ) {
			$map          = array_shift( $queue );
			$seen[ $map ] = true;
			$r            = wp_remote_get(
				$map,
				array(
					'timeout'   => 15,
					'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core filter.
				)
			);
			if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
				continue;
			}
			$xml = (string) wp_remote_retrieve_body( $r );
			if ( ! preg_match_all( '#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)\s*(?:\]\]>)?\s*</loc>#i', $xml, $m ) ) {
				continue;
			}
			$is_index = false !== stripos( $xml, '<sitemapindex' );
			foreach ( $m[1] as $loc ) {
				$loc = html_entity_decode( $loc, ENT_QUOTES | ENT_XML1, 'UTF-8' );
				if ( wp_parse_url( $loc, PHP_URL_HOST ) !== $host ) {
					continue;
				}
				if ( $is_index ) {
					if ( ! isset( $seen[ $loc ] ) ) {
						$queue[] = $loc;
					}
				} else {
					$urls[] = $loc;
				}
			}
		}
		return array_slice( array_values( array_unique( $urls ) ), 0, $max );
	}

	private static function from_posts( int $max ): array {
		$types = array_values( array_intersect( array( 'page', 'post', 'product' ), get_post_types( array( 'public' => true ) ) ) );
		$ids   = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => 'publish',
				'posts_per_page'   => $max,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'has_password'     => false,
				'suppress_filters' => false,
			)
		);
		$urls  = array();
		foreach ( $ids as $id ) {
			$urls[] = get_permalink( (int) $id );
		}
		return array_filter( $urls );
	}
}

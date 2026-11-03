<?php
/**
 * SSG_Abilities — Search Signals in the WordPress Abilities API, for an AI
 * or a remote tool that runs abilities (e.g. Site Bridge's ability_run),
 * with the same capability (manage_options) and the same code as the admin:
 *
 *  search-signals/status          mode, modules, other SEO plugin, static
 *                                 llms files, switch, import (read)
 *  search-signals/get-meta        title, description, robots, canonical,
 *                                 Open Graph of a post, a term or a URL,
 *                                 with the own (override) values (read)
 *  search-signals/set-meta        writes the own title, description,
 *                                 robots and canonical of a post or a term
 *  search-signals/json-ld         the JSON-LD graph of a URL (read)
 *  search-signals/sitemap-status  sitemap files and counts (read)
 *  search-signals/compare         live page vs our output for up to 10
 *                                 URLs of this site (read; loopback GETs)
 *
 * The output is what Search Signals computes, also while it is passive or
 * in test mode: the same code the front end runs once it is live.
 * Registered only on WordPress 6.9+, where the Abilities API exists.
 */

defined( 'ABSPATH' ) || exit;

final class SSG_Abilities {

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

	/** Input: one object by id (post), term id + taxonomy, or a URL of this site. */
	private static function target_schema( bool $url_only = false ): array {
		$p = array(
			'url' => array(
				'type'      => 'string',
				'maxLength' => 2000,
			),
		);
		if ( ! $url_only ) {
			$p['id']       = array(
				'type'    => 'integer',
				'minimum' => 1,
			);
			$p['term_id']  = array(
				'type'    => 'integer',
				'minimum' => 1,
			);
			$p['taxonomy'] = array(
				'type'      => 'string',
				'maxLength' => 32,
			);
		}
		return $p;
	}

	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$empty = array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
			'default'              => array(),
		);
		$defs  = array(
			'status'         => array(
				'label'       => 'Search Signals: status',
				'description' => 'Version, mode (off / test: only administrators see the output / live), modules (meta, schema, social, sitemaps, llms), the other SEO plugin that keeps Search Signals passive (e.g. Rank Math) if any, whether SSG_DISABLE is set, whether this site is open to search engines, static llms.txt / llms-full.txt files in the web root and their backups, the switch-over state and the last Rank Math import.',
				'input'       => $empty,
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'status' ),
			),
			'get-meta'       => array(
				'label'       => 'Search Signals: get SEO meta',
				'description' => 'What Search Signals prints for one object: kind, title, description, robots (noindex / nofollow), canonical, Open Graph / Twitter tags, and the own (override) values stored on the object. Give id (a post, page or product), or term_id (+ taxonomy), or url (an address of this site).',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => self::target_schema(),
					'additionalProperties' => false,
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'get_meta' ),
			),
			'set-meta'       => array(
				'label'       => 'Search Signals: set SEO meta',
				'description' => 'Writes the own SEO fields of a post (id) or a term (term_id + taxonomy), the same fields as the box in the editor. Only the fields given change; an empty string (or an empty robots list) removes the override, so the template applies again. title and description accept the template variables (%title%, %sep%, %sitename%…), up to 300 characters. robots: a list of noindex / nofollow. canonical: an absolute http(s) URL. Returns the result as get-meta.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'          => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'term_id'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'taxonomy'    => array(
							'type'      => 'string',
							'maxLength' => 32,
						),
						'title'       => array(
							'type'      => 'string',
							'maxLength' => 300,
						),
						'description' => array(
							'type'      => 'string',
							'maxLength' => 300,
						),
						'robots'      => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'noindex', 'nofollow' ),
							),
							'maxItems'    => 2,
							'uniqueItems' => true,
						),
						'canonical'   => array(
							'type'      => 'string',
							'maxLength' => 500,
						),
					),
					'additionalProperties' => false,
				),
				'ann'         => array( false, false, true ),
				'run'         => array( __CLASS__, 'set_meta' ),
			),
			'json-ld'        => array(
				'label'       => 'Search Signals: JSON-LD of a URL',
				'description' => 'The JSON-LD graph Search Signals builds for an address of this site (Organization, WebSite with SearchAction, WebPage, BreadcrumbList, Product from WooCommerce or Article, plus the nodes other plugins add through the noxpress_seo_graph filter), as the @graph list and the list of @type values.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => self::target_schema( true ),
					'required'             => array( 'url' ),
					'additionalProperties' => false,
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'json_ld' ),
			),
			'sitemap-status' => array(
				'label'       => 'Search Signals: sitemap status',
				'description' => 'The sitemap index URL, every sitemap file with its type and number of URLs, URLs per file, whether images are listed, whether the core wp-sitemap.xml is on, whether robots.txt is virtual (our Sitemap line is added) or a static file, and whether the site is open to search engines.',
				'input'       => $empty,
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'sitemap_status' ),
			),
			'compare'        => array(
				'label'       => 'Search Signals: compare with the live page',
				'description' => 'For up to 10 addresses of this site (or the sample set of the admin page when none is given): what the live page prints now (e.g. from Rank Math) and what Search Signals would print, field by field (title, description, robots, canonical, Open Graph, JSON-LD types, Product price / availability), with a status per field: same, text (different), missing (we print nothing), extra (only we print it). The live page is fetched with a GET to this site itself.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'urls' => array(
							'type'     => 'array',
							'items'    => array(
								'type'      => 'string',
								'maxLength' => 2000,
							),
							'maxItems' => 10,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'compare' ),
			),
		);
		foreach ( $defs as $name => $d ) {
			wp_register_ability(
				'search-signals/' . $name,
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
		return current_user_can( Search_Signals::CAP );
	}

	/* =====================================================================
	 * Read
	 * =================================================================== */

	public static function status( $in = null ): array {
		Search_Signals::load_tools();
		require_once SSG_PATH . 'includes/class-static.php';
		$s    = SSG_Settings::get();
		$mods = array();
		foreach ( SSG_Settings::MODULES as $m ) {
			$mods[ $m ] = ! empty( $s['mods'][ $m ] );
		}
		$static = array();
		foreach ( SSG_Static::found() as $f ) {
			$static[] = array(
				'name'  => isset( $f['name'] ) ? (string) $f['name'] : '',
				'bytes' => isset( $f['size'] ) ? (int) $f['size'] : 0,
				'email' => ! empty( $f['email'] ),
			);
		}
		$sw  = get_option( 'ssg_switch', array() );
		$log = get_option( 'ssg_import_log', array() );
		return array(
			'version'        => SSG_VERSION,
			'mode'           => $s['mode'],
			'modules'        => $mods,
			'other_seo'      => SSG_Detect::other(),
			'passive'        => '' !== SSG_Detect::other(),
			'disabled'       => Search_Signals::killed(),
			'search_engines' => '1' === (string) get_option( 'blog_public' ),
			'static_llms'    => $static,
			'static_backups' => count( SSG_Static::backups() ),
			'switched'       => is_array( $sw ) && ! empty( $sw['time'] ) ? array(
				'from' => isset( $sw['other'] ) ? (string) $sw['other'] : '',
				'time' => gmdate( 'c', (int) $sw['time'] ),
			) : null,
			'last_import'    => is_array( $log ) && ! empty( $log['time'] ) ? array(
				'time'    => gmdate( 'c', (int) $log['time'] ),
				'changes' => isset( $log['meta'] ) && is_array( $log['meta'] ) ? count( $log['meta'] ) : 0,
			) : null,
		);
	}

	/** The context of an input (id, term_id + taxonomy, or url), or an error. */
	private static function context( array $in ) {
		if ( ! empty( $in['id'] ) ) {
			$p = get_post( (int) $in['id'] );
			if ( ! $p instanceof WP_Post || 'revision' === $p->post_type ) {
				return new WP_Error( 'ssg_not_found', 'No such post.' );
			}
			return SSG_Context::from_post( (int) $p->ID );
		}
		if ( ! empty( $in['term_id'] ) ) {
			$t = get_term( (int) $in['term_id'], isset( $in['taxonomy'] ) ? sanitize_key( (string) $in['taxonomy'] ) : '' );
			if ( ! $t instanceof WP_Term ) {
				return new WP_Error( 'ssg_not_found', 'No such term.' );
			}
			return SSG_Context::from_term( (int) $t->term_id, (string) $t->taxonomy );
		}
		if ( ! empty( $in['url'] ) ) {
			$c = SSG_Context::from_url( (string) $in['url'] );
			return null === $c ? new WP_Error( 'ssg_url', 'Not an address of this site.' ) : $c;
		}
		return new WP_Error( 'ssg_input', 'Give id, term_id or url.' );
	}

	/** The result of get-meta for a context. */
	private static function meta_out( SSG_Context $c ): array {
		$r    = SSG_Meta::robots( $c );
		$can  = SSG_Meta::canonical( $c );
		$tags = array();
		foreach ( SSG_Social::tags( $c ) as $t ) {
			if ( is_array( $t ) && isset( $t[1], $t[2] ) ) {
				$tags[] = array( (string) $t[1], SSG_Vars::plain( (string) $t[2] ) );
			}
		}
		$own = array(
			'title'       => (string) SSG_Meta::own( $c, SSG_Meta::TITLE ),
			'description' => (string) SSG_Meta::own( $c, SSG_Meta::DESC ),
			'robots'      => array_values( array_intersect( (array) SSG_Meta::own( $c, SSG_Meta::ROBOTS ), array( 'noindex', 'nofollow' ) ) ),
			'canonical'   => (string) SSG_Meta::own( $c, SSG_Meta::CANON ),
			'image'       => (int) SSG_Meta::own( $c, SSG_Meta::IMAGE ),
		);
		return array(
			'kind'        => $c->kind,
			'id'          => $c->has_post() ? $c->post_id : null,
			'term_id'     => 'term' === $c->kind ? $c->term_id : null,
			'taxonomy'    => 'term' === $c->kind ? $c->taxonomy : null,
			'title'       => SSG_Meta::title( $c ),
			'description' => SSG_Meta::description( $c ),
			'robots'      => array(
				'noindex'  => $r['noindex'],
				'nofollow' => $r['nofollow'],
			),
			'canonical'   => $r['noindex'] ? '' : $can,
			'social'      => $tags,
			'own'         => $own,
		);
	}

	public static function get_meta( $in = null ) {
		Search_Signals::load_core();
		$c = self::context( is_array( $in ) ? $in : array() );
		return is_wp_error( $c ) ? $c : self::meta_out( $c );
	}

	public static function json_ld( $in = null ) {
		Search_Signals::load_core();
		$in = is_array( $in ) ? $in : array();
		$c  = self::context( array( 'url' => isset( $in['url'] ) ? (string) $in['url'] : '' ) );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$nodes = SSG_Schema::build( $c );
		$types = array();
		foreach ( $nodes as $n ) {
			if ( is_array( $n ) && isset( $n['@type'] ) ) {
				foreach ( (array) $n['@type'] as $t ) {
					$types[] = (string) $t;
				}
			}
		}
		return array(
			'kind'  => $c->kind,
			'types' => array_values( array_unique( $types ) ),
			'graph' => array_values( $nodes ),
		);
	}

	public static function sitemap_status( $in = null ): array {
		Search_Signals::load_tools();
		$out           = SSG_Sitemaps::status();
		$out['module'] = SSG_Settings::module( 'sitemaps' );
		$out['served'] = $out['module'] && '' === SSG_Detect::other() && ! Search_Signals::killed() && 'live' === SSG_Settings::get()['mode'];
		return $out;
	}

	public static function compare( $in = null ): array {
		Search_Signals::load_tools();
		$urls = is_array( $in ) && ! empty( $in['urls'] ) && is_array( $in['urls'] ) ? array_slice( array_map( 'strval', $in['urls'] ), 0, 10 ) : array_slice( SSG_Compare::urls(), 0, 10 );
		return SSG_Compare::run( $urls );
	}

	/* =====================================================================
	 * Write
	 * =================================================================== */

	public static function set_meta( $in = null ) {
		Search_Signals::load_core();
		require_once SSG_PATH . 'includes/class-editor.php';
		$in = is_array( $in ) ? $in : array();
		if ( empty( $in['id'] ) && empty( $in['term_id'] ) ) {
			return new WP_Error( 'ssg_input', 'Give id or term_id.' );
		}
		$c = self::context( array_intersect_key( $in, array_flip( array( 'id', 'term_id', 'taxonomy' ) ) ) );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$v = array();
		if ( array_key_exists( 'title', $in ) ) {
			$v['title'] = mb_substr( SSG_Settings::clean( (string) $in['title'] ), 0, 300 );
		}
		if ( array_key_exists( 'description', $in ) ) {
			$v['desc'] = mb_substr( SSG_Settings::clean( (string) $in['description'] ), 0, 300 );
		}
		if ( array_key_exists( 'robots', $in ) ) {
			$v['robots'] = array_values( array_intersect( array( 'noindex', 'nofollow' ), (array) $in['robots'] ) );
		}
		if ( array_key_exists( 'canonical', $in ) ) {
			$u = trim( (string) $in['canonical'] );
			if ( '' !== $u ) {
				$u = esc_url_raw( $u, array( 'http', 'https' ) );
				if ( '' === $u || strlen( $u ) > 500 || ! wp_http_validate_url( $u ) ) {
					return new WP_Error( 'ssg_canonical', 'canonical must be an absolute http(s) URL.' );
				}
			}
			$v['canonical'] = $u;
		}
		if ( ! $v ) {
			return new WP_Error( 'ssg_input', 'Nothing to change.' );
		}
		if ( $c->has_post() ) {
			SSG_Editor::store( 'post', $c->post_id, $v );
			clean_post_cache( $c->post_id );
			Noxpress_Core::purge_page_cache( array( $c->post_id ) );
		} else {
			SSG_Editor::store( 'term', $c->term_id, $v );
			clean_term_cache( $c->term_id, $c->taxonomy );
			Noxpress_Core::purge_page_cache();
		}
		return self::meta_out( $c );
	}
}

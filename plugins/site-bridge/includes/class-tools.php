<?php
/**
 * SBR_Tools — tool definitions and the read tools.
 *
 * Every tool has a class: read (no change), write (content, options,
 * media, caches; needs the site's level "write" and an open write window)
 * or admin (plugins, themes, update channel; needs level "admin" and an
 * open window). The Hub adds the "site" argument to every definition.
 *
 * Tools run inside the site, through WordPress APIs only: no PHP, shell or
 * WP-CLI is ever executed. Results pass through SBR_Guard (secrets out,
 * personal data masked when the setting is on).
 */

defined( 'ABSPATH' ) || exit;

final class SBR_Tools {

	const MAX_TEXT = 400000;

	/** name => definition. */
	public static function defs(): array {
		$str  = array( 'type' => 'string' );
		$int  = array( 'type' => 'integer' );
		$bool = array( 'type' => 'boolean' );
		$obj  = array(
			'type'                 => 'object',
			'additionalProperties' => true,
		);
		$strs = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);
		return array(
			'list_sites'             => array(
				'class'       => 'read',
				'hub'         => true,
				'title'       => 'List sites',
				'description' => 'Lists the sites this connection may reach, with their access level (off, read, write, admin), whether a write window is open, and whether they answer. Call it first: every other tool needs a site id from here.',
				'props'       => array(),
			),
			'site_info'              => array(
				'class'       => 'read',
				'fleet'       => true,
				'title'       => 'Site info',
				'description' => 'WordPress, WooCommerce and PHP versions, HPOS, theme, active plugins with versions, the Noxpress suite and its update channel, locale, timezone, permalinks, and this site\'s Site Bridge level and write window.',
				'props'       => array(),
			),
			'plugins_list'           => array(
				'class'       => 'read',
				'fleet'       => true,
				'title'       => 'List plugins',
				'description' => 'Every installed plugin: slug, file, name, version, active, available update, auto-update.',
				'props'       => array(),
			),
			'themes_list'            => array(
				'class'       => 'read',
				'fleet'       => true,
				'title'       => 'List themes',
				'description' => 'Every installed theme with version, parent, active and available update.',
				'props'       => array(),
			),
			'option_get'             => array(
				'class'       => 'read',
				'title'       => 'Read options',
				'description' => 'Reads options by name (up to 50), or with "search" lists option names matching a pattern (* is a wildcard) with their size and autoload, without values. Secret values come back as ***.',
				'props'       => array(
					'names'  => $strs + array( 'description' => 'Option names.' ),
					'search' => $str + array( 'description' => 'Name pattern, e.g. woocommerce_*_settings.' ),
				),
			),
			'db_tables'              => array(
				'class'       => 'read',
				'fleet'       => true,
				'title'       => 'List database tables',
				'description' => 'Names of the database tables, optionally filtered by a substring, optionally with row counts.',
				'props'       => array(
					'like'   => $str + array( 'description' => 'Substring of the table name.' ),
					'counts' => $bool + array( 'description' => 'Count rows (slower).' ),
				),
			),
			'db_describe'            => array(
				'class'       => 'read',
				'title'       => 'Describe a table',
				'description' => 'Columns of a database table (DESCRIBE).',
				'props'       => array( 'table' => $str + array( 'description' => 'Full table name with prefix.' ) ),
				'required'    => array( 'table' ),
			),
			'db_select'              => array(
				'class'       => 'read',
				'fleet'       => true,
				'title'       => 'Read-only SQL',
				'description' => 'Runs one read-only statement (SELECT, SHOW, DESCRIBE, EXPLAIN, WITH … SELECT). No comments or semicolons; string literals naming secrets are refused; the password, API key and payment token tables and columns are never readable. When you read option_value or meta_value from options or usermeta, select option_name or meta_key too. Rows are capped (max_rows, at most 500) and the query has a 5 second time limit on MySQL / MariaDB. The table prefix is in site_info.',
				'props'       => array(
					'sql'      => $str,
					'max_rows' => $int + array( 'description' => 'Default 100, at most 500.' ),
				),
				'required'    => array( 'sql' ),
			),
			'rest_get'               => array(
				'class'       => 'read',
				'fleet'       => true,
				'title'       => 'REST GET',
				'description' => 'An internal GET request to any REST route of the site, as the site\'s Site Bridge user, without HTTP or API keys, e.g. /wc/v3/products, /wc/v3/orders, /wp/v2/pages. Returns status, data and the paging headers.',
				'props'       => array(
					'route' => $str + array( 'description' => 'Route starting with /, e.g. /wc/v3/products.' ),
					'query' => $obj + array( 'description' => 'Query parameters, e.g. {"per_page": 20, "status": "publish"}.' ),
				),
				'required'    => array( 'route' ),
			),
			'page_html'              => array(
				'class'       => 'read',
				'title'       => 'Page HTML',
				'description' => 'Fetches a page of the site as a logged-out visitor sees it (the site requests itself) and returns status, a few headers and the HTML.',
				'props'       => array(
					'path'      => $str + array( 'description' => 'Path such as /shop/ or a full URL on this site.' ),
					'max_bytes' => $int + array( 'description' => 'Default 300000, at most 2000000.' ),
				),
				'required'    => array( 'path' ),
			),
			'files_list'             => array(
				'class'       => 'read',
				'title'       => 'List files',
				'description' => 'Lists files and folders under wp-content (themes, plugins, mu-plugins, languages…). Uploads, configuration, keys, databases, backups and .git are never listed.',
				'props'       => array(
					'path'  => $str + array( 'description' => 'Folder relative to wp-content, e.g. themes/storefront. Empty for wp-content itself.' ),
					'depth' => $int + array( 'description' => '1 to 3, default 1.' ),
				),
			),
			'file_read'              => array(
				'class'       => 'read',
				'title'       => 'Read a file',
				'description' => 'Reads a text file under wp-content (not uploads) by lines.',
				'props'       => array(
					'path'      => $str + array( 'description' => 'Relative to wp-content, e.g. themes/storefront/functions.php.' ),
					'from_line' => $int + array( 'description' => 'First line, default 1.' ),
					'max_lines' => $int + array( 'description' => 'Default 400, at most 2000.' ),
				),
				'required'    => array( 'path' ),
			),
			'files_search'           => array(
				'class'       => 'read',
				'title'       => 'Search files',
				'description' => 'Searches text files under a wp-content folder for a plain string and returns path, line number and line.',
				'props'       => array(
					'path'           => $str + array( 'description' => 'Folder relative to wp-content, e.g. plugins/woocommerce.' ),
					'text'           => $str,
					'case_sensitive' => $bool,
					'extensions'     => $strs + array( 'description' => 'Default: php, js, css, html, twig, json, txt, md, xml.' ),
					'max_results'    => $int + array( 'description' => 'Default 100, at most 300.' ),
				),
				'required'    => array( 'path', 'text' ),
			),
			'upload_read'            => array(
				'class'       => 'read',
				'title'       => 'Read an uploaded file',
				'description' => 'Reads a file from the uploads folder (e.g. a PDF or an image) and returns it as base64 with its type and sha256.',
				'props'       => array(
					'path'      => $str + array( 'description' => 'Relative to the uploads folder, e.g. 2026/10/book.pdf or woocommerce_uploads/…' ),
					'max_bytes' => $int + array( 'description' => 'Default 1000000, at most 5000000.' ),
				),
				'required'    => array( 'path' ),
			),
			'logs_tail'              => array(
				'class'       => 'read',
				'title'       => 'Read logs',
				'description' => 'Without "log", lists the available logs (debug.log, WooCommerce log files). With "log", returns its last lines, optionally only those containing "grep". E-mail addresses are masked when the site masks personal data.',
				'props'       => array(
					'log'   => $str + array( 'description' => '"debug" or "wc/<file name>".' ),
					'lines' => $int + array( 'description' => 'Default 200, at most 1000.' ),
					'grep'  => $str,
				),
			),
			'cron_list'              => array(
				'class'       => 'read',
				'title'       => 'Scheduled events',
				'description' => 'WP-Cron events (hook, next run, schedule) and Action Scheduler counts by status, with past-due pending actions.',
				'props'       => array(),
			),
			'site_health'            => array(
				'class'       => 'read',
				'title'       => 'Site Health',
				'description' => 'WordPress Site Health information. Without "section", lists the sections and the last status test result; with "section" (e.g. wp-core, wp-server, wp-database, wp-plugins-active, wp-constants), returns its fields.',
				'props'       => array( 'section' => $str ),
			),
			'abilities_list'         => array(
				'class'       => 'read',
				'title'       => 'List abilities',
				'description' => 'Abilities registered with the WordPress Abilities API (WordPress 6.9+): name, label, description, annotations, input schema. "search" keeps only those whose name, label or category contains it (e.g. "book-feed", "noxpress"); "brief" leaves out the descriptions and schemas.',
				'props'       => array(
					'search' => $str + array( 'description' => 'Substring of the name, label or category, e.g. book-feed/.' ),
					'brief'  => $bool + array( 'description' => 'Names, labels and annotations only.' ),
				),
			),
			'bridge_status'          => array(
				'class'       => 'read',
				'fleet'       => true,
				'title'       => 'Site Bridge status',
				'description' => 'This site\'s Site Bridge settings as Claude is bound by them: version, level, write window (until, minutes left), whether the Hub may open it, the run-as administrator, personal data masking, admin e-mails, IP lists (on / off and size), rate limits, log retention, the Hub it is paired with and when it last called. Changes nothing; only the owner changes these in WP admin.',
				'props'       => array(),
			),
			'audit_log'              => array(
				'class'       => 'read',
				'title'       => 'Audit log',
				'description' => 'This site\'s Site Bridge log, newest first: time (UTC), side, connection, tool, class, arguments (secrets redacted), status, message, size and duration. Filters: tool, status (ok, denied, error), side (site, hub), from / to (Y-m-d, UTC). IP addresses are masked when the site masks personal data.',
				'props'       => array(
					'tool'   => $str,
					'status' => array(
						'type' => 'string',
						'enum' => array( 'ok', 'denied', 'error' ),
					),
					'side'   => array(
						'type' => 'string',
						'enum' => array( 'site', 'hub' ),
					),
					'from'   => $str + array( 'description' => 'Y-m-d (UTC).' ),
					'to'     => $str + array( 'description' => 'Y-m-d (UTC).' ),
					'limit'  => $int + array( 'description' => 'Default 50, at most 200.' ),
					'offset' => $int,
				),
			),
			'core_update_check'      => array(
				'class'       => 'read',
				'fleet'       => true,
				'title'       => 'Core update check',
				'description' => 'Asks WordPress.org whether a WordPress update exists and returns the offers. Changes nothing.',
				'props'       => array(),
			),
			'rest_write'             => array(
				'class'       => 'write',
				'title'       => 'REST write',
				'description' => 'An internal POST, PUT, PATCH or DELETE to a REST route, as the site\'s Site Bridge user (e.g. update a product with /wc/v3/products/123). Users, plugins, themes, site settings, application passwords and batch routes are refused. Needs level write and an open write window.',
				'props'       => array(
					'method' => array(
						'type' => 'string',
						'enum' => array( 'POST', 'PUT', 'PATCH', 'DELETE' ),
					),
					'route'  => $str,
					'body'   => $obj,
					'query'  => $obj,
				),
				'required'    => array( 'method', 'route' ),
			),
			'option_set'             => array(
				'class'       => 'write',
				'title'       => 'Set an option',
				'description' => 'Sets an option (any JSON value). Core URLs, roles, active plugins and theme, cron, secrets and Site Bridge / Noxpress Core options are refused. The old value is kept in the audit log. Needs level write and an open write window.',
				'props'       => array(
					'name'     => $str,
					'value'    => array( 'description' => 'Any JSON value.' ),
					'autoload' => $bool,
				),
				'required'    => array( 'name', 'value' ),
			),
			'media_upload_from_url'  => array(
				'class'       => 'write',
				'title'       => 'Upload media from a URL',
				'description' => 'Downloads a public file into the media library (WordPress decides the allowed types). Needs level write and an open write window.',
				'props'       => array(
					'url'     => $str,
					'title'   => $str,
					'alt'     => $str,
					'post_id' => $int + array( 'description' => 'Attach to this post or product.' ),
				),
				'required'    => array( 'url' ),
			),
			'cache_flush'            => array(
				'class'       => 'write',
				'title'       => 'Flush caches',
				'description' => 'Flushes the object cache and the WooCommerce product transients. Needs level write and an open write window.',
				'props'       => array(),
			),
			'ability_run'            => array(
				'class'       => 'write',
				'title'       => 'Run an ability',
				'description' => 'Runs a WordPress ability with an input. Read-only abilities need only level read; destructive ones need level admin; others need write. Write and admin need an open write window.',
				'props'       => array(
					'name'  => $str,
					'input' => array( 'description' => 'The ability\'s input.' ),
				),
				'required'    => array( 'name' ),
			),
			'write_window_close'     => array(
				'class'       => 'write',
				'title'       => 'Close the write window',
				'description' => 'Closes this site\'s write window now, when the work is done, so nothing more can change until the owner opens it again. It can only close the window, never open or extend it.',
				'props'       => array(),
			),
			'plugins_install'        => array(
				'class'       => 'admin',
				'title'       => 'Install plugins',
				'description' => 'Installs plugins by slug (WordPress.org, or the Noxpress catalog through Noxpress Core) or by zip URL from the allowed hosts in the site\'s settings; optionally activates them. Needs level admin and an open write window.',
				'props'       => array(
					'items'    => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'slug'    => $str,
								'zip_url' => $str,
							),
						),
					),
					'activate' => $bool,
				),
				'required'    => array( 'items' ),
			),
			'plugins_activate'       => array(
				'class'       => 'admin',
				'title'       => 'Activate plugins',
				'description' => 'Activates one or more installed plugins (slugs or plugin files). Needs level admin and an open write window.',
				'props'       => array( 'plugins' => $strs ),
				'required'    => array( 'plugins' ),
			),
			'plugins_deactivate'     => array(
				'class'       => 'admin',
				'title'       => 'Deactivate plugins',
				'description' => 'Deactivates one or more plugins (never Site Bridge itself). Needs level admin and an open write window.',
				'props'       => array( 'plugins' => $strs ),
				'required'    => array( 'plugins' ),
			),
			'plugins_update'         => array(
				'class'       => 'admin',
				'title'       => 'Update plugins',
				'description' => 'Updates plugins that have an update ("all" for every one). Needs level admin and an open write window.',
				'props'       => array( 'plugins' => $strs ),
				'required'    => array( 'plugins' ),
			),
			'themes_update'          => array(
				'class'       => 'admin',
				'title'       => 'Update themes',
				'description' => 'Updates themes that have an update ("all" for every one). Needs level admin and an open write window.',
				'props'       => array( 'themes' => $strs ),
				'required'    => array( 'themes' ),
			),
			'noxpress_channel_set'   => array(
				'class'       => 'admin',
				'title'       => 'Noxpress update channel',
				'description' => 'Sets the Noxpress suite update channel (stable or beta) and checks for updates. Needs level admin and an open write window.',
				'props'       => array(
					'channel' => array(
						'type' => 'string',
						'enum' => array( 'stable', 'beta' ),
					),
				),
				'required'    => array( 'channel' ),
			),
		);
	}

	public static function def( string $name ): ?array {
		$d = self::defs();
		return $d[ $name ] ?? null;
	}

	/** MCP definition (with the site argument when the tool needs one). */
	public static function mcp_def( string $name, array $d ): array {
		$props    = $d['props'];
		$required = $d['required'] ?? array();
		if ( empty( $d['hub'] ) ) {
			$props    = array(
				'site' => array(
					'type'        => 'string',
					'description' => ! empty( $d['fleet'] ) ? 'Site id from list_sites, or "all" for every site this connection reaches.' : 'Site id from list_sites.',
				),
			) + $props;
			$required = array_merge( array( 'site' ), $required );
		}
		$schema = array(
			'type'       => 'object',
			'properties' => $props ? $props : new stdClass(),
		);
		if ( $required ) {
			$schema['required'] = $required;
		}
		$read = 'read' === $d['class'];
		return array(
			'name'        => $name,
			'title'       => $d['title'],
			'description' => $d['description'],
			'inputSchema' => $schema,
			'annotations' => array(
				'title'           => $d['title'],
				'readOnlyHint'    => $read,
				'destructiveHint' => 'admin' === $d['class'] || in_array( $name, array( 'rest_write', 'option_set' ), true ),
				'idempotentHint'  => $read,
				'openWorldHint'   => in_array( $name, array( 'media_upload_from_url', 'plugins_install', 'core_update_check' ), true ),
			),
		);
	}

	/**
	 * Runs a tool inside this site (the caller has done every check).
	 *
	 * @return array|WP_Error
	 */
	public static function run( string $name, array $args ) {
		$method = 'tool_' . $name;
		if ( method_exists( __CLASS__, $method ) ) {
			return self::$method( $args );
		}
		if ( method_exists( 'SBR_Tools_Write', $method ) ) {
			return SBR_Tools_Write::$method( $args );
		}
		return new WP_Error( 'sbr_tool', 'Unknown tool.' );
	}

	/* =====================================================================
	 * Argument helpers
	 * =================================================================== */

	public static function a_str( array $a, string $k, string $def = '' ): string {
		return isset( $a[ $k ] ) && is_scalar( $a[ $k ] ) ? (string) $a[ $k ] : $def;
	}

	public static function a_int( array $a, string $k, int $def, int $min, int $max ): int {
		$v = isset( $a[ $k ] ) && is_numeric( $a[ $k ] ) ? (int) $a[ $k ] : $def;
		return min( $max, max( $min, $v ) );
	}

	public static function a_bool( array $a, string $k ): bool {
		return ! empty( $a[ $k ] ) && 'false' !== $a[ $k ];
	}

	public static function a_list( array $a, string $k, int $max = 100 ): array {
		$v = $a[ $k ] ?? array();
		if ( is_string( $v ) ) {
			$v = array( $v );
		}
		if ( ! is_array( $v ) ) {
			return array();
		}
		$out = array();
		foreach ( $v as $x ) {
			if ( is_scalar( $x ) && '' !== trim( (string) $x ) ) {
				$out[] = trim( (string) $x );
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, $max );
	}

	public static function a_obj( array $a, string $k ): array {
		return isset( $a[ $k ] ) && is_array( $a[ $k ] ) ? $a[ $k ] : array();
	}

	private static function admin_includes(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}

	/* =====================================================================
	 * Read tools
	 * =================================================================== */

	private static function tool_site_info( array $a ): array {
		global $wpdb, $wp_version;
		self::admin_includes();
		$plugins = get_plugins();
		$active  = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			if ( isset( $plugins[ $file ] ) ) {
				$active[] = array(
					'file'    => $file,
					'name'    => $plugins[ $file ]['Name'],
					'version' => $plugins[ $file ]['Version'],
				);
			}
		}
		$theme = wp_get_theme();
		$out   = array(
			'name'          => get_bloginfo( 'name' ),
			'url'           => home_url( '/' ),
			'wordpress'     => $wp_version,
			'php'           => PHP_VERSION,
			'database'      => self::db_engine(),
			'table_prefix'  => $wpdb->prefix,
			'multisite'     => is_multisite(),
			'locale'        => get_locale(),
			'timezone'      => wp_timezone_string(),
			'permalinks'    => (string) get_option( 'permalink_structure' ),
			'memory_limit'  => (string) ini_get( 'memory_limit' ),
			'object_cache'  => wp_using_ext_object_cache(),
			'file_mods'     => ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ),
			'theme'         => array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'slug'    => $theme->get_stylesheet(),
				'parent'  => $theme->parent() ? $theme->parent()->get_stylesheet() : '',
				'block'   => method_exists( $theme, 'is_block_theme' ) ? $theme->is_block_theme() : false,
			),
			'woocommerce'   => null,
			'plugins'       => $active,
			'noxpress'      => self::noxpress( $plugins ),
			'site_bridge'   => array(
				'version'      => SBR_VERSION,
				'level'        => SBR_Settings::v( 'level' ),
				'window_until' => SBR_Settings::window_until() ? gmdate( 'c', SBR_Settings::window_until() ) : null,
				'pii_mask'     => (bool) SBR_Settings::v( 'pii_mask' ),
			),
		);
		if ( function_exists( 'WC' ) ) {
			$hpos = null;
			if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
				$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
			}
			$out['woocommerce'] = array(
				'version'  => WC()->version,
				'hpos'     => $hpos,
				'currency' => get_option( 'woocommerce_currency' ),
				'country'  => get_option( 'woocommerce_default_country' ),
			);
		}
		return $out;
	}

	private static function noxpress( array $plugins ): ?array {
		if ( ! class_exists( 'Noxpress_Core' ) ) {
			return null;
		}
		$suite = array();
		foreach ( array_keys( Noxpress_Core::catalog() ) as $slug ) {
			foreach ( $plugins as $file => $p ) {
				if ( 0 === strpos( $file, $slug . '/' ) ) {
					$suite[ $slug ] = array(
						'version' => $p['Version'],
						'active'  => is_plugin_active( $file ),
					);
				}
			}
		}
		return array(
			'core'    => defined( 'NOXPRESS_CORE' ) ? NOXPRESS_CORE : '',
			'channel' => class_exists( 'Noxpress_Updater' ) ? Noxpress_Updater::channel() : '',
			'plugins' => $suite,
		);
	}

	public static function db_engine(): string {
		global $wpdb;
		if ( ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) || ( defined( 'DATABASE_TYPE' ) && 'sqlite' === DATABASE_TYPE ) ) {
			return 'sqlite';
		}
		$info = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
		return false !== stripos( $info, 'mariadb' ) ? 'mariadb' : 'mysql';
	}

	private static function tool_plugins_list( array $a ): array {
		self::admin_includes();
		$updates = get_site_transient( 'update_plugins' );
		$auto    = (array) get_site_option( 'auto_update_plugins', array() );
		$out     = array();
		foreach ( get_plugins() as $file => $p ) {
			$new   = isset( $updates->response[ $file ]->new_version ) ? (string) $updates->response[ $file ]->new_version : '';
			$out[] = array(
				'slug'        => false !== strpos( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' ),
				'file'        => $file,
				'name'        => $p['Name'],
				'version'     => $p['Version'],
				'active'      => is_plugin_active( $file ),
				'update'      => $new,
				'auto_update' => in_array( $file, $auto, true ),
			);
		}
		$mu = array();
		foreach ( get_mu_plugins() as $file => $p ) {
			$mu[] = array(
				'file'    => $file,
				'name'    => $p['Name'],
				'version' => $p['Version'],
			);
		}
		return array(
			'plugins'    => $out,
			'mu_plugins' => $mu,
		);
	}

	private static function tool_themes_list( array $a ): array {
		self::admin_includes();
		$updates = get_site_transient( 'update_themes' );
		$active  = get_stylesheet();
		$out     = array();
		foreach ( wp_get_themes() as $slug => $t ) {
			$out[] = array(
				'slug'    => $slug,
				'name'    => $t->get( 'Name' ),
				'version' => $t->get( 'Version' ),
				'parent'  => $t->parent() ? $t->parent()->get_stylesheet() : '',
				'active'  => $slug === $active,
				'update'  => isset( $updates->response[ $slug ]['new_version'] ) ? (string) $updates->response[ $slug ]['new_version'] : '',
			);
		}
		return array( 'themes' => $out );
	}

	private static function tool_option_get( array $a ) {
		global $wpdb;
		$search = self::a_str( $a, 'search' );
		if ( '' !== $search ) {
			$like = str_replace( '\*', '%', $wpdb->esc_like( str_replace( '*', "\x01", $search ) ) );
			$like = str_replace( "\x01", '%', $like );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, LENGTH(option_value) AS bytes, autoload FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT 300", $like ), ARRAY_A );
			return array( 'options' => is_array( $rows ) ? $rows : array() );
		}
		$names = self::a_list( $a, 'names', 50 );
		if ( ! $names ) {
			return new WP_Error( 'sbr_args', 'Give "names" or "search".' );
		}
		$out = array();
		foreach ( $names as $n ) {
			$exists = null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $n ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( ! $exists ) {
				$out[ $n ] = array( 'exists' => false );
				continue;
			}
			if ( SBR_Guard::is_secret( $n ) ) {
				$out[ $n ] = array(
					'exists' => true,
					'value'  => SBR_Guard::MASK,
				);
				continue;
			}
			$v    = get_option( $n );
			$v    = SBR_Guard::clean_tree( is_object( $v ) ? get_object_vars( $v ) : $v );
			if ( SBR_Settings::v( 'pii_mask' ) && is_scalar( $v ) && SBR_Guard::is_pii( $n ) ) {
				$v = SBR_Guard::mask_value( $n, $v );
			}
			$json = Site_Bridge::json( $v );
			if ( strlen( $json ) > 200000 ) {
				$out[ $n ] = array(
					'exists'    => true,
					'truncated' => true,
					'bytes'     => strlen( $json ),
					'value'     => substr( $json, 0, 200000 ),
				);
				continue;
			}
			$out[ $n ] = array(
				'exists' => true,
				'value'  => $v,
			);
		}
		return array( 'options' => $out );
	}

	private static function tool_db_tables( array $a ): array {
		global $wpdb;
		$like   = self::a_str( $a, 'like' );
		$tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$tables = is_array( $tables ) ? $tables : array();
		if ( '' !== $like ) {
			$tables = array_values(
				array_filter(
					$tables,
					static function ( $t ) use ( $like ) {
						return false !== stripos( $t, $like );
					}
				)
			);
		}
		$out = array();
		foreach ( $tables as $t ) {
			$row = array( 'table' => $t );
			if ( self::a_bool( $a, 'counts' ) && count( $tables ) <= 150 && preg_match( '/^[A-Za-z0-9_$]+$/', $t ) ) {
				$blocked = false;
				foreach ( SBR_Guard::BLOCKED_TABLES as $b ) {
					$blocked = $blocked || ( substr( $t, -strlen( $b ) ) === $b );
				}
				$row['rows'] = $blocked ? null : (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- name from SHOW TABLES, validated.
			}
			$out[] = $row;
		}
		return array( 'tables' => $out );
	}

	private static function tool_db_describe( array $a ) {
		global $wpdb;
		$t      = self::a_str( $a, 'table' );
		$tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! in_array( $t, (array) $tables, true ) || ! preg_match( '/^[A-Za-z0-9_$]+$/', $t ) ) {
			return new WP_Error( 'sbr_args', 'Unknown table.' );
		}
		$cols = $wpdb->get_results( "DESCRIBE `{$t}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- validated name.
		return array(
			'table'   => $t,
			'columns' => is_array( $cols ) ? $cols : array(),
		);
	}

	private static function tool_db_select( array $a ) {
		$max = self::a_int( $a, 'max_rows', 100, 1, 500 );
		$v   = SBR_Guard::sql( self::a_str( $a, 'sql' ), $max );
		if ( isset( $v['error'] ) ) {
			return new WP_Error( 'sbr_sql', $v['error'] );
		}
		$db = self::read_db();
		if ( is_wp_error( $db ) ) {
			return $db;
		}
		$sql    = $v['sql'];
		$engine = self::db_engine();
		if ( 'select' === $v['kind'] && preg_match( '/^\s*SELECT\b/i', $sql ) ) {
			if ( 'mysql' === $engine ) {
				$sql = (string) preg_replace( '/^\s*SELECT\b/i', 'SELECT /*+ MAX_EXECUTION_TIME(5000) */', $sql, 1 );
			} elseif ( 'mariadb' === $engine ) {
				$sql = 'SET STATEMENT max_statement_time=5 FOR ' . $sql;
			}
		}
		$db->suppress_errors( true );
		$start = microtime( true );
		$rows  = $db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- validated read-only statement.
		$ms    = (int) round( 1000 * ( microtime( true ) - $start ) );
		if ( '' !== (string) $db->last_error ) {
			// Plain text (the SQLite drop-in reports HTML), first 500 characters.
			$err = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $db->last_error ) ) );
			return new WP_Error( 'sbr_sql', 'Database error: ' . substr( $err, 0, 500 ) );
		}
		$rows      = is_array( $rows ) ? $rows : array();
		$truncated = count( $rows ) > $max;
		if ( $truncated ) {
			$rows = array_slice( $rows, 0, $max );
		}
		return array(
			'rows'      => SBR_Guard::clean_rows( $rows, $v['sql'] ),
			'count'     => count( $rows ),
			'truncated' => $truncated,
			'ms'        => $ms,
			'read_only_user' => $db !== $GLOBALS['wpdb'],
		);
	}

	/** $wpdb, or a connection as the read-only MySQL user when configured. */
	private static function read_db() {
		global $wpdb;
		if ( ! defined( 'SBR_DB_READONLY_USER' ) || '' === (string) SBR_DB_READONLY_USER ) {
			return $wpdb;
		}
		$db = new wpdb( (string) SBR_DB_READONLY_USER, defined( 'SBR_DB_READONLY_PASSWORD' ) ? (string) SBR_DB_READONLY_PASSWORD : '', DB_NAME, DB_HOST );
		if ( empty( $db->dbh ) || ! empty( $db->error ) ) {
			return new WP_Error( 'sbr_sql', 'The read-only database user (SBR_DB_READONLY_USER) could not connect.' );
		}
		$db->set_prefix( $wpdb->prefix );
		return $db;
	}

	private static function tool_rest_get( array $a ) {
		return self::rest( 'GET', self::a_str( $a, 'route' ), self::a_obj( $a, 'query' ), array() );
	}

	/** An internal REST request as the current user. */
	public static function rest( string $method, string $route, array $query, array $body ) {
		$route = '/' . ltrim( $route, '/' );
		if ( false !== strpos( $route, '?' ) ) {
			$parts = explode( '?', $route, 2 );
			$route = $parts[0];
			parse_str( $parts[1], $more );
			$query = array_merge( (array) $more, $query );
		}
		$why = SBR_Guard::blocked_route( $route, $method );
		if ( '' !== $why ) {
			return new WP_Error( 'sbr_route', $why );
		}
		$req = new WP_REST_Request( $method, $route );
		if ( $query ) {
			$req->set_query_params( $query );
		}
		if ( $body ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( (string) wp_json_encode( $body ) );
			$req->set_body_params( $body );
		}
		$res    = rest_do_request( $req );
		$server = rest_get_server();
		$data   = $server->response_to_data( $res, false );
		$h      = $res->get_headers();
		$out    = array(
			'status' => $res->get_status(),
			'data'   => SBR_Guard::clean_tree( $data ),
		);
		foreach ( array( 'X-WP-Total', 'X-WP-TotalPages' ) as $k ) {
			if ( isset( $h[ $k ] ) ) {
				$out['headers'][ $k ] = $h[ $k ];
			}
		}
		return $out;
	}

	private static function tool_page_html( array $a ) {
		$path = self::a_str( $a, 'path', '/' );
		$home = wp_parse_url( home_url( '/' ) );
		if ( preg_match( '#^https?://#i', $path ) ) {
			$p = wp_parse_url( $path );
			if ( ! is_array( $p ) || strtolower( $p['host'] ?? '' ) !== strtolower( $home['host'] ?? '' ) ) {
				return new WP_Error( 'sbr_args', 'Only pages of this site.' );
			}
			$url = $path;
		} else {
			$url = home_url( '/' . ltrim( $path, '/' ) );
		}
		$max = self::a_int( $a, 'max_bytes', 300000, 1000, 2000000 );
		$res = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'user-agent'  => 'Mozilla/5.0 (compatible; SiteBridge/' . SBR_VERSION . '; +https://noxpress.tech)',
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter for loopbacks.
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'sbr_http', 'The site did not answer its own request: ' . $res->get_error_message() );
		}
		$body    = (string) wp_remote_retrieve_body( $res );
		$headers = array();
		foreach ( array( 'content-type', 'cache-control', 'x-cache', 'cf-cache-status', 'x-litespeed-cache', 'location', 'link' ) as $k ) {
			$v = wp_remote_retrieve_header( $res, $k );
			if ( '' !== $v && array() !== $v ) {
				$headers[ $k ] = is_array( $v ) ? implode( ', ', $v ) : $v;
			}
		}
		return array(
			'url'       => $url,
			'status'    => (int) wp_remote_retrieve_response_code( $res ),
			'headers'   => $headers,
			'bytes'     => strlen( $body ),
			'truncated' => strlen( $body ) > $max,
			'html'      => SBR_Guard::mask_text( substr( $body, 0, $max ) ),
		);
	}

	private static function tool_files_list( array $a ) {
		$rel   = trim( self::a_str( $a, 'path' ), '/' );
		$dir   = SBR_Guard::path_in( WP_CONTENT_DIR, $rel, array( 'uploads' ) );
		$depth = self::a_int( $a, 'depth', 1, 1, 3 );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		if ( ! is_dir( $dir ) ) {
			return new WP_Error( 'sbr_path', 'Not a folder.' );
		}
		$out = array();
		self::walk( $dir, '' === $rel ? '' : $rel . '/', $depth, $out, '' === $rel );
		return array(
			'path'      => $rel,
			'entries'   => $out,
			'truncated' => count( $out ) >= 2000,
		);
	}

	private static function walk( string $dir, string $prefix, int $depth, array &$out, bool $top ): void {
		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $name ) {
			if ( '.' === $name || '..' === $name || count( $out ) >= 2000 ) {
				continue;
			}
			$full   = $dir . '/' . $name;
			$is_dir = is_dir( $full );
			if ( ! SBR_Guard::listable( $name, $is_dir ) || ( $top && 'uploads' === strtolower( $name ) ) ) {
				continue;
			}
			$out[] = array(
				'path'  => $prefix . $name . ( $is_dir ? '/' : '' ),
				'type'  => $is_dir ? 'dir' : 'file',
				'size'  => $is_dir ? null : (int) @filesize( $full ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				'mtime' => gmdate( 'c', (int) @filemtime( $full ) ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			);
			if ( $is_dir && $depth > 1 && ! is_link( $full ) ) {
				self::walk( $full, $prefix . $name . '/', $depth - 1, $out, false );
			}
		}
	}

	private static function tool_file_read( array $a ) {
		$rel  = self::a_str( $a, 'path' );
		$file = SBR_Guard::path_in( WP_CONTENT_DIR, $rel, array( 'uploads' ) );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! is_file( $file ) ) {
			return new WP_Error( 'sbr_path', 'Not a file.' );
		}
		$size = (int) filesize( $file );
		if ( $size > 5000000 ) {
			return new WP_Error( 'sbr_path', 'The file is larger than 5 MB.' );
		}
		$text = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( false !== strpos( substr( $text, 0, 8000 ), "\0" ) ) {
			return new WP_Error( 'sbr_path', 'Binary file.' );
		}
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		$from  = self::a_int( $a, 'from_line', 1, 1, max( 1, count( $lines ) ) );
		$max   = self::a_int( $a, 'max_lines', 400, 1, 2000 );
		$part  = array_slice( $lines, $from - 1, $max );
		return array(
			'path'        => ltrim( str_replace( '\\', '/', $rel ), '/' ),
			'size'        => $size,
			'mtime'       => gmdate( 'c', (int) filemtime( $file ) ),
			'total_lines' => count( $lines ),
			'from_line'   => $from,
			'to_line'     => $from + count( $part ) - 1,
			'content'     => implode( "\n", $part ),
		);
	}

	private static function tool_files_search( array $a ) {
		$rel  = trim( self::a_str( $a, 'path' ), '/' );
		$dir  = SBR_Guard::path_in( WP_CONTENT_DIR, $rel, array( 'uploads' ) );
		$text = self::a_str( $a, 'text' );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		if ( '' === $text || ! is_dir( $dir ) ) {
			return new WP_Error( 'sbr_args', 'Give a folder and a text.' );
		}
		$exts = array_map( 'strtolower', self::a_list( $a, 'extensions', 20 ) );
		$exts = $exts ? $exts : array( 'php', 'js', 'css', 'html', 'twig', 'json', 'txt', 'md', 'xml' );
		$max  = self::a_int( $a, 'max_results', 100, 1, 300 );
		$cs   = self::a_bool( $a, 'case_sensitive' );
		$it   = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				static function ( $cur ) {
					return SBR_Guard::listable( $cur->getFilename(), $cur->isDir() ) && ! ( $cur->isDir() && 'node_modules' === $cur->getFilename() );
				}
			)
		);
		$hits    = array();
		$scanned = 0;
		$root    = realpath( WP_CONTENT_DIR );
		foreach ( $it as $f ) {
			if ( count( $hits ) >= $max || $scanned >= 8000 ) {
				break;
			}
			if ( ! $f->isFile() || $f->getSize() > 1000000 || ! in_array( strtolower( $f->getExtension() ), $exts, true ) ) {
				continue;
			}
			++$scanned;
			$h = fopen( $f->getPathname(), 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $h ) {
				continue;
			}
			$n = 0;
			while ( false !== ( $line = fgets( $h ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				++$n;
				if ( ( $cs ? strpos( $line, $text ) : stripos( $line, $text ) ) !== false ) {
					$path   = str_replace( '\\', '/', substr( $f->getPathname(), strlen( (string) $root ) + 1 ) );
					$hits[] = array(
						'path' => $path,
						'line' => $n,
						'text' => substr( rtrim( $line ), 0, 300 ),
					);
					if ( count( $hits ) >= $max ) {
						break;
					}
				}
			}
			fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return array(
			'matches'       => $hits,
			'files_scanned' => $scanned,
			'truncated'     => count( $hits ) >= $max || $scanned >= 8000,
		);
	}

	private static function tool_upload_read( array $a ) {
		$up   = wp_upload_dir( null, false );
		$file = SBR_Guard::path_in( (string) $up['basedir'], self::a_str( $a, 'path' ) );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! is_file( $file ) ) {
			return new WP_Error( 'sbr_path', 'Not a file.' );
		}
		$max  = self::a_int( $a, 'max_bytes', 1000000, 1000, 5000000 );
		$size = (int) filesize( $file );
		if ( $size > $max ) {
			return new WP_Error( 'sbr_path', sprintf( 'The file is %d bytes, more than max_bytes (%d).', $size, $max ) );
		}
		$data = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$type = wp_check_filetype( $file );
		return array(
			'path'   => ltrim( str_replace( '\\', '/', self::a_str( $a, 'path' ) ), '/' ),
			'size'   => $size,
			'mime'   => $type['type'] ? $type['type'] : 'application/octet-stream',
			'sha256' => hash( 'sha256', $data ),
			'base64' => base64_encode( $data ),
		);
	}

	/** name => absolute path of the readable logs. */
	private static function logs(): array {
		$out   = array();
		$debug = WP_CONTENT_DIR . '/debug.log';
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG && ! in_array( strtolower( WP_DEBUG_LOG ), array( '1', 'true' ), true ) ) {
			$debug = WP_DEBUG_LOG;
		}
		if ( is_file( $debug ) ) {
			$out['debug'] = $debug;
		}
		$dir = defined( 'WC_LOG_DIR' ) ? WC_LOG_DIR : '';
		if ( '' === $dir ) {
			$up  = wp_upload_dir( null, false );
			$dir = $up['basedir'] . '/wc-logs/';
		}
		$files = is_dir( $dir ) ? glob( trailingslashit( $dir ) . '*.log' ) : array();
		if ( is_array( $files ) ) {
			usort(
				$files,
				static function ( $x, $y ) {
					return (int) filemtime( $y ) - (int) filemtime( $x );
				}
			);
			foreach ( array_slice( $files, 0, 40 ) as $f ) {
				$out[ 'wc/' . basename( $f ) ] = $f;
			}
		}
		return $out;
	}

	private static function tool_logs_tail( array $a ) {
		$logs = self::logs();
		$name = self::a_str( $a, 'log' );
		if ( '' === $name ) {
			$list = array();
			foreach ( $logs as $k => $f ) {
				$list[] = array(
					'log'   => $k,
					'size'  => (int) filesize( $f ),
					'mtime' => gmdate( 'c', (int) filemtime( $f ) ),
				);
			}
			return array( 'logs' => $list );
		}
		if ( ! isset( $logs[ $name ] ) ) {
			return new WP_Error( 'sbr_args', 'Unknown log. Call logs_tail without "log" for the list.' );
		}
		$n    = self::a_int( $a, 'lines', 200, 1, 1000 );
		$grep = self::a_str( $a, 'grep' );
		$file = $logs[ $name ];
		$size = (int) filesize( $file );
		$read = min( $size, '' === $grep ? 1000000 : 4000000 );
		$h    = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $h ) {
			return new WP_Error( 'sbr_path', 'The log could not be opened.' );
		}
		fseek( $h, $size - $read );
		$chunk = (string) fread( $h, $read ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$lines = preg_split( '/\r\n|\n/', rtrim( $chunk ) );
		if ( $read < $size ) {
			array_shift( $lines );
		}
		if ( '' !== $grep ) {
			$lines = array_values(
				array_filter(
					$lines,
					static function ( $l ) use ( $grep ) {
						return false !== stripos( $l, $grep );
					}
				)
			);
		}
		$lines = array_slice( $lines, -$n );
		return array(
			'log'   => $name,
			'size'  => $size,
			'lines' => count( $lines ),
			'text'  => SBR_Guard::mask_text( implode( "\n", $lines ) ),
		);
	}

	private static function tool_cron_list( array $a ): array {
		global $wpdb;
		$events = array();
		$cron   = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		foreach ( (array) $cron as $ts => $hooks ) {
			foreach ( (array) $hooks as $hook => $items ) {
				foreach ( (array) $items as $item ) {
					if ( count( $events ) >= 300 ) {
						break 3;
					}
					$events[] = array(
						'hook'     => $hook,
						'next'     => gmdate( 'c', (int) $ts ),
						'schedule' => $item['schedule'] ?? '',
						'args'     => count( (array) ( $item['args'] ?? array() ) ),
					);
				}
			}
		}
		$as    = null;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
			$late = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND scheduled_date_gmt < %s", gmdate( 'Y-m-d H:i:s', time() - 600 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
			$as   = array(
				'by_status'           => is_array( $rows ) ? $rows : array(),
				'pending_late_10_min' => $late,
			);
		}
		return array(
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'events'           => $events,
			'action_scheduler' => $as,
		);
	}

	private static function tool_site_health( array $a ) {
		self::admin_includes();
		if ( ! class_exists( 'WP_Debug_Data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
		}
		if ( ! class_exists( 'WP_Site_Health' ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-site-health.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}
		$data    = WP_Debug_Data::debug_data();
		$section = self::a_str( $a, 'section' );
		if ( '' === $section ) {
			$list = array();
			foreach ( $data as $k => $s ) {
				$list[] = array(
					'section' => $k,
					'label'   => $s['label'] ?? $k,
					'fields'  => count( (array) ( $s['fields'] ?? array() ) ),
				);
			}
			$status = get_transient( 'health-check-site-status-result' );
			return array(
				'sections' => $list,
				'status'   => is_string( $status ) ? json_decode( $status, true ) : null,
			);
		}
		if ( ! isset( $data[ $section ] ) ) {
			return new WP_Error( 'sbr_args', 'Unknown section.' );
		}
		$fields = array();
		foreach ( (array) ( $data[ $section ]['fields'] ?? array() ) as $k => $f ) {
			$fields[ $k ] = array(
				'label' => $f['label'] ?? $k,
				'value' => $f['value'] ?? '',
			);
		}
		return array(
			'section' => $section,
			'fields'  => SBR_Guard::clean_tree( $fields ),
		);
	}

	private static function tool_abilities_list( array $a ) {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return new WP_Error( 'sbr_tool', 'This site has no Abilities API (WordPress 6.9+).' );
		}
		$find  = strtolower( self::a_str( $a, 'search' ) );
		$brief = self::a_bool( $a, 'brief' );
		$out   = array();
		foreach ( wp_get_abilities() as $ab ) {
			$meta  = method_exists( $ab, 'get_meta' ) ? (array) $ab->get_meta() : array();
			$label = method_exists( $ab, 'get_label' ) ? (string) $ab->get_label() : '';
			$cat   = method_exists( $ab, 'get_category' ) ? (string) $ab->get_category() : '';
			if ( '' !== $find && false === strpos( strtolower( $ab->get_name() . ' ' . $label . ' ' . $cat ), $find ) ) {
				continue;
			}
			$row = array(
				'name'        => $ab->get_name(),
				'label'       => $label,
				'category'    => $cat,
				'annotations' => $meta['annotations'] ?? null,
			);
			if ( ! $brief ) {
				$row['description']  = method_exists( $ab, 'get_description' ) ? $ab->get_description() : '';
				$row['input_schema'] = method_exists( $ab, 'get_input_schema' ) ? $ab->get_input_schema() : null;
			}
			$out[] = $row;
		}
		return array( 'abilities' => $out );
	}

	private static function tool_bridge_status( array $a ): array {
		$s     = SBR_Settings::get();
		$until = SBR_Settings::window_until();
		$user  = SBR_Settings::run_as_user();
		$trust = SBR_Site::trust();
		$seen  = (int) get_option( 'sbr_hub_seen', 0 );
		$ips   = static function ( string $key ): array {
			$n = count( SBR_Settings::lines( $key ) );
			return array(
				'on'      => $n > 0,
				'entries' => $n,
			);
		};
		return array(
			'version'       => SBR_VERSION,
			'roles'         => array(
				'hub'  => (bool) $s['hub'],
				'site' => (bool) $s['site'],
			),
			'level'         => $s['level'],
			'write_window'  => array(
				'open'         => $until > 0,
				'until'        => $until ? gmdate( 'c', $until ) : null,
				'minutes_left' => $until ? (int) ceil( max( 0, $until - time() ) / 60 ) : 0,
				'hub_may_open' => (bool) $s['remote_window'],
			),
			'run_as'        => $user ? array(
				'id'   => $user->ID,
				'name' => $user->display_name,
			) : null,
			'pii_mask'      => (bool) $s['pii_mask'],
			'email_admin'   => (bool) $s['email_admin'],
			'ip_lists'      => array(
				'site' => $ips( 'site_ips' ),
				'hub'  => $ips( 'hub_ips' ),
			),
			'rate'          => array(
				'per_minute' => (int) $s['rate_min'],
				'per_day'    => (int) $s['rate_day'],
			),
			'log_days'      => (int) $s['log_days'],
			'hub'           => array(
				'paired'    => null !== $trust,
				'url'       => is_array( $trust ) && isset( $trust['hub_url'] ) ? (string) $trust['hub_url'] : '',
				'since'     => is_array( $trust ) && ! empty( $trust['paired'] ) ? gmdate( 'c', (int) $trust['paired'] ) : null,
				'last_call' => $seen ? gmdate( 'c', $seen ) : null,
			),
			'owner_only'    => 'Level, write window, run-as, IP lists and pairing change only in WP admin → Noxpress → Site Bridge.',
		);
	}

	private static function tool_audit_log( array $a ): array {
		$f = array(
			'tool'   => sanitize_key( self::a_str( $a, 'tool' ) ),
			'status' => in_array( self::a_str( $a, 'status' ), array( 'ok', 'denied', 'error' ), true ) ? self::a_str( $a, 'status' ) : '',
			'side'   => in_array( self::a_str( $a, 'side' ), array( 'site', 'hub' ), true ) ? self::a_str( $a, 'side' ) : '',
			'from'   => self::a_str( $a, 'from' ),
			'to'     => self::a_str( $a, 'to' ),
		);
		$limit = self::a_int( $a, 'limit', 50, 1, 200 );
		$off   = self::a_int( $a, 'offset', 0, 0, 1000000 );
		$pii   = (bool) SBR_Settings::v( 'pii_mask' );
		$rows  = array();
		foreach ( SBR_Store::log_rows( $f, $limit, $off ) as $r ) {
			$args       = json_decode( (string) $r['args'], true );
			$r['args']  = null !== $args ? $args : (string) $r['args'];
			$r['ip']    = $pii ? SBR_Guard::mask_ip( (string) $r['ip'] ) : (string) $r['ip'];
			$r['id']    = (int) $r['id'];
			$r['bytes'] = (int) $r['bytes'];
			$r['ms']    = (int) $r['ms'];
			$rows[]     = $r;
		}
		return array(
			'total'  => SBR_Store::log_count( $f ),
			'offset' => $off,
			'rows'   => $rows,
		);
	}

	private static function tool_core_update_check( array $a ): array {
		self::admin_includes();
		wp_version_check( array(), true );
		$offers = array();
		foreach ( (array) get_core_updates() as $u ) {
			$offers[] = array(
				'response' => $u->response ?? '',
				'version'  => $u->version ?? '',
				'php'      => $u->php_version ?? '',
			);
		}
		return array(
			'current' => get_bloginfo( 'version' ),
			'offers'  => $offers,
		);
	}
}

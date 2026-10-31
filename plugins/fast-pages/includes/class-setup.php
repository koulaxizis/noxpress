<?php
/**
 * FPG_Setup — what the page cache needs outside the plugin folder:
 *
 *  - wp-content/advanced-cache.php, our drop-in (a stub that loads
 *    includes/serve.php). Never written over a drop-in that is not ours.
 *  - define( 'WP_CACHE', true ) in wp-config.php: added with the button and
 *    a confirmation (Bible §14.34), only when the file is writable and the
 *    result still parses; otherwise the page shows the line to add by hand.
 *    Removed again on deactivation, only if we added it.
 *  - wp-content/cache/fast-pages/config.php, the drop-in's settings.
 *  - Other page caches (WP Rocket, LiteSpeed Cache, …): while one is active
 *    the page cache does not start (Bible §14.23).
 *
 * State in the option fpg_state (no autoload): wpconfig '' | 'added' |
 * 'changed' (and the original line), the last purge, the last import.
 */

defined( 'ABSPATH' ) || exit;

final class FPG_Setup {

	const STATE  = 'fpg_state';
	const MARK   = 'FAST-PAGES-DROPIN';
	const LINE   = "define( 'WP_CACHE', true ); // Added by Fast Pages";
	const DEFINE = '/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*([^)]*)\)\s*;/i';

	/** Active plugins that run their own page cache: basename => name. */
	const PAGE_CACHES = array(
		'wp-rocket/wp-rocket.php'                    => 'WP Rocket',
		'litespeed-cache/litespeed-cache.php'        => 'LiteSpeed Cache',
		'w3-total-cache/w3-total-cache.php'          => 'W3 Total Cache',
		'wp-super-cache/wp-cache.php'                => 'WP Super Cache',
		'wp-fastest-cache/wpFastestCache.php'        => 'WP Fastest Cache',
		'cache-enabler/cache-enabler.php'            => 'Cache Enabler',
		'comet-cache/comet-cache.php'                => 'Comet Cache',
		'hummingbird-performance/wp-hummingbird.php' => 'Hummingbird',
		'breeze/breeze.php'                          => 'Breeze',
		'sg-cachepress/sg-cachepress.php'            => 'Speed Optimizer (SiteGround)',
		'wp-optimize/wp-optimize.php'                => 'WP-Optimize',
		'swift-performance-lite/performance.php'     => 'Swift Performance',
		'nitropack/main.php'                         => 'NitroPack',
	);

	/** Active plugins that minify CSS / JS. */
	const MINIFIERS = array(
		'autoptimize/autoptimize.php'   => 'Autoptimize',
		'fast-velocity-minify/fvm.php'  => 'Fast Velocity Minify',
		'perfmatters/perfmatters.php'   => 'Perfmatters',
		'wp-rocket/wp-rocket.php'       => 'WP Rocket',
		'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
		'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
	);

	/* =====================================================================
	 * State
	 * =================================================================== */

	public static function state(): array {
		$raw = get_option( self::STATE, array() );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'wpconfig'   => in_array( $raw['wpconfig'] ?? '', array( 'added', 'changed' ), true ) ? $raw['wpconfig'] : '',
			'orig'       => isset( $raw['orig'] ) ? (string) $raw['orig'] : '',
			'last_purge' => isset( $raw['last_purge'] ) ? (int) $raw['last_purge'] : 0,
			'imported'   => isset( $raw['imported'] ) ? (int) $raw['imported'] : 0,
		);
	}

	public static function set_state( array $changes ): void {
		$s = array_merge( self::state(), $changes );
		update_option( self::STATE, $s, false );
	}

	/* =====================================================================
	 * Checks
	 * =================================================================== */

	/** Names of active plugins from a basename => name list. */
	private static function active_of( array $list ): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$out = array();
		foreach ( $list as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/** Other page caches that are active. */
	public static function conflicts(): array {
		$out = self::active_of( self::PAGE_CACHES );
		if ( defined( 'WP_ROCKET_VERSION' ) && ! in_array( 'WP Rocket', $out, true ) ) {
			$out[] = 'WP Rocket';
		}
		return $out;
	}

	/** Other minifiers that are active. */
	public static function minifiers(): array {
		return self::active_of( self::MINIFIERS );
	}

	public static function dropin_file(): string {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	/** 'ours' | 'foreign' | 'none'. */
	public static function dropin(): string {
		$f = self::dropin_file();
		if ( ! file_exists( $f ) ) {
			return 'none';
		}
		$head = (string) @file_get_contents( $f, false, null, 0, 2048 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return false !== strpos( $head, self::MARK ) ? 'ours' : 'foreign';
	}

	/** The drop-in ran in this request (WP_CACHE on and our file). */
	public static function dropin_loaded(): bool {
		return defined( 'FPG_DROPIN' );
	}

	public static function wp_cache_on(): bool {
		return defined( 'WP_CACHE' ) && WP_CACHE;
	}

	/** The wp-config.php WordPress uses ('' when not found). */
	public static function wp_config(): string {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		$up = dirname( ABSPATH ) . '/wp-config.php';
		if ( @file_exists( $up ) && ! @file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- open_basedir.
			return $up;
		}
		return '';
	}

	/** The page cache can answer requests now. */
	public static function ready(): bool {
		return FPG_Settings::get()['enabled'] && ! Fast_Pages::killed() && 'ours' === self::dropin() && self::wp_cache_on() && ! self::conflicts();
	}

	/* =====================================================================
	 * Config for the drop-in
	 * =================================================================== */

	/** Paths of the WooCommerce cart, checkout and account pages. */
	private static function shop_paths(): array {
		$out = array();
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return $out;
		}
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
			$id = (int) wc_get_page_id( $page );
			if ( $id <= 0 || (int) get_option( 'page_on_front' ) === $id ) {
				continue;
			}
			$path = (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH );
			if ( '' !== $path && '/' !== $path && $path !== $home ) {
				$out[] = $path;
			}
		}
		return $out;
	}

	/** Hosts the cache answers: the host of home_url(). */
	public static function hosts(): array {
		$p    = wp_parse_url( home_url( '/' ) );
		$host = FPG_Key::host( ( $p['host'] ?? '' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) );
		return '' === $host ? array() : array( $host );
	}

	/** Per-request cache of config(). */
	private static $config = null;

	/** The config array the drop-in and FPG_Key read. */
	public static function config(): array {
		if ( null !== self::$config ) {
			return self::$config;
		}
		$s       = FPG_Settings::get();
		$exclude = array();
		foreach ( FPG_Settings::lines( 'exclude_urls' ) as $line ) {
			$re = FPG_Key::pattern( $line );
			if ( '' !== $re && false !== @preg_match( $re, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a bad pattern is skipped.
				$exclude[] = $re;
			}
		}
		$cookies = array();
		/**
		 * Cookies (name prefixes) that mean "this visitor's pages are
		 * personal": a plugin with its own session cookie (not a WordPress
		 * login) adds its name here (Bible §14.34).
		 *
		 * @param string[] $names Cookie names or prefixes.
		 */
		$extra = apply_filters( 'noxpress_private_cookies', array() );
		$lines = array_merge( FPG_Settings::lines( 'exclude_cookies' ), is_array( $extra ) ? array_map( 'strval', $extra ) : array() );
		foreach ( $lines as $line ) {
			$line = rtrim( $line, '*' );
			if ( '' !== $line && false === strpos( $line, '*' ) ) {
				$cookies[] = $line;
			}
		}
		self::$config = array(
			'v'       => FPG_VERSION,
			'on'      => $s['enabled'] && ! Fast_Pages::killed() && ! self::conflicts(),
			'test'    => $s['test'],
			'hosts'   => self::hosts(),
			'life'    => FPG_Settings::life_seconds(),
			'mobile'  => $s['mobile'],
			'cookies' => $cookies,
			'exclude' => $exclude,
			'never'   => self::shop_paths(),
			'dir'     => FPG_Files::dir(),
			'charset' => (string) get_option( 'blog_charset', 'UTF-8' ),
		);
		return self::$config;
	}

	public static function write_config(): bool {
		self::$config = null;
		if ( ! FPG_Files::ensure() ) {
			return false;
		}
		$php = "<?php\n// Fast Pages: settings of the page cache drop-in. Written by the plugin; do not edit.\ndefined( 'ABSPATH' ) || exit;\nreturn " . var_export( self::config(), true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- a PHP config file.
		return FPG_Files::write( FPG_Files::dir() . '/config.php', $php );
	}

	/**
	 * The config or our drop-in on disk is from another version or another
	 * folder (the site was moved, e.g. with Data Migrator): write it again.
	 */
	public static function maybe_refresh(): void {
		$f = FPG_Files::dir() . '/config.php';
		if ( ! FPG_Settings::get()['enabled'] ) {
			return;
		}
		$head = is_file( $f ) ? (string) @file_get_contents( $f ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( false === strpos( $head, "'v' => '" . FPG_VERSION . "'" ) || false === strpos( $head, var_export( FPG_Files::dir(), true ) ) ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- compared with the written file.
			self::write_config();
		}
		if ( 'ours' === self::dropin() ) {
			$drop = (string) @file_get_contents( self::dropin_file() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			if ( false === strpos( $drop, var_export( FPG_PATH . 'includes/serve.php', true ) ) || false === strpos( $drop, 'WP_PLUGIN_DIR' ) ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- 1.0.1: also a 1.0.0 drop-in.
				self::install_dropin();
			}
		}
	}

	/* =====================================================================
	 * Drop-in
	 * =================================================================== */

	public static function install_dropin(): bool {
		if ( 'foreign' === self::dropin() ) {
			return false;
		}
		$serve = FPG_PATH . 'includes/serve.php';
		$php   = "<?php\n/**\n * Fast Pages: page cache drop-in (" . self::MARK . ").\n * Written by the Fast Pages plugin (noxpress.tech) and removed when it is\n * deactivated. It loads the plugin's serve.php; without it, nothing happens.\n */\n\n"
			. "defined( 'ABSPATH' ) || exit;\n\ndefine( 'FPG_DROPIN', 1 );\n\n"
			// 1.0.1: the plugin inside this site's own plugins folder first, so a
			// copied site (staging, migration) never runs the original's files.
			. '$fpg_serve = ( defined( \'WP_PLUGIN_DIR\' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . \'/plugins\' ) . ' . var_export( '/' . basename( FPG_PATH ) . '/includes/serve.php', true ) . ";\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- a PHP file.
			. "if ( ! is_file( \$fpg_serve ) ) {\n\t\$fpg_serve = " . var_export( $serve, true ) . ";\n}\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- a PHP file.
			. "if ( is_file( \$fpg_serve ) ) {\n\tinclude_once \$fpg_serve;\n}\nunset( \$fpg_serve );\n";
		return FPG_Files::write( self::dropin_file(), $php );
	}

	public static function remove_dropin(): void {
		if ( 'ours' === self::dropin() ) {
			@unlink( self::dropin_file() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}

	/* =====================================================================
	 * wp-config.php
	 * =================================================================== */

	/** Reads, changes, checks and writes wp-config.php. '' = done. */
	private static function edit_wp_config( callable $change ): string {
		$file = self::wp_config();
		if ( '' === $file ) {
			return 'missing';
		}
		if ( ! is_writable( $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- the real file mode matters here.
			return 'readonly';
		}
		$old = (string) @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( '' === $old ) {
			return 'unreadable';
		}
		$new = $change( $old );
		if ( ! is_string( $new ) ) {
			return 'unknown';
		}
		if ( $new === $old ) {
			return '';
		}
		if ( ! self::parses( $new ) ) {
			return 'parse';
		}
		// In place, so that owner and permissions stay as they are. No
		// backup file next to it: a copy of wp-config.php in the web root
		// could be downloaded. The old content stays in memory instead.
		if ( false === @file_put_contents( $file, $new, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return 'write';
		}
		clearstatcache( true, $file );
		if ( @file_get_contents( $file ) !== $new ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			@file_put_contents( $file, $old, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return 'write';
		}
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $file, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return '';
	}

	/** The PHP code still parses. */
	private static function parses( string $php ): bool {
		try {
			token_get_all( $php, TOKEN_PARSE );
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Turns WP_CACHE on. '' = done (or already on), otherwise the reason:
	 * missing | readonly | unreadable | unknown | parse | write | other.
	 */
	public static function add_wp_cache(): string {
		if ( self::wp_cache_on() ) {
			return '';
		}
		$how  = '';
		$orig = '';
		$res  = self::edit_wp_config(
			static function ( $php ) use ( &$how, &$orig ) {
				if ( preg_match( self::DEFINE, $php, $m ) ) {
					$val = strtolower( trim( $m[1] ) );
					if ( ! in_array( $val, array( 'false', '0', "''", '""' ), true ) ) {
						return null; // A value we do not understand: by hand.
					}
					$how  = 'changed';
					$orig = $m[0];
					return str_replace( $m[0], self::LINE, $php );
				}
				if ( ! preg_match( '/^\s*<\?php[ \t]*(\r?\n)/', $php, $m ) ) {
					return null;
				}
				$how = 'added';
				return substr( $php, 0, strlen( $m[0] ) ) . self::LINE . $m[1] . substr( $php, strlen( $m[0] ) );
			}
		);
		if ( '' === $res && '' !== $how ) {
			self::set_state(
				array(
					'wpconfig' => $how,
					'orig'     => $orig,
				)
			);
		}
		return $res;
	}

	/** Removes our WP_CACHE line (only what we added or changed). */
	public static function remove_wp_cache(): string {
		$st = self::state();
		if ( '' === $st['wpconfig'] ) {
			return '';
		}
		$res = self::edit_wp_config(
			static function ( $php ) use ( $st ) {
				if ( false === strpos( $php, self::LINE ) ) {
					return $php;
				}
				if ( 'changed' === $st['wpconfig'] && '' !== $st['orig'] ) {
					return str_replace( self::LINE, $st['orig'], $php );
				}
				return (string) preg_replace( '/' . preg_quote( self::LINE, '/' ) . '\r?\n?/', '', $php, 1 );
			}
		);
		if ( '' === $res ) {
			self::set_state(
				array(
					'wpconfig' => '',
					'orig'     => '',
				)
			);
		}
		return $res;
	}

	/* =====================================================================
	 * Turn the page cache on / off
	 * =================================================================== */

	/**
	 * Starts the page cache: folder, drop-in, WP_CACHE, config. Returns
	 * array( ok, problems[] ), each problem a code for the admin page.
	 */
	public static function enable(): array {
		$problems = array();
		if ( Fast_Pages::killed() ) {
			return array( false, array( 'killed' ) );
		}
		if ( self::conflicts() ) {
			return array( false, array( 'conflict' ) );
		}
		if ( 'foreign' === self::dropin() ) {
			return array( false, array( 'foreign' ) );
		}
		if ( ! FPG_Files::ensure() ) {
			return array( false, array( 'folder' ) );
		}
		if ( ! self::install_dropin() ) {
			return array( false, array( 'dropin' ) );
		}
		$wc = self::add_wp_cache();
		if ( '' !== $wc ) {
			$problems[] = 'wpconfig_' . $wc;
		}
		FPG_Settings::update( array( 'enabled' => true ) );
		return array( true, $problems );
	}

	/** Stops the page cache and removes what it put outside the plugin. */
	public static function disable(): array {
		FPG_Settings::update( array( 'enabled' => false ) );
		FPG_Files::purge_all( true );
		self::remove_dropin();
		$wc = self::remove_wp_cache();
		FPG_Preload::stop();
		return '' === $wc ? array() : array( 'wpconfig_' . $wc );
	}
}

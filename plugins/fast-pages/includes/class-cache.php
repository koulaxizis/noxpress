<?php
/**
 * FPG_Cache — writes the cached page at the end of a visitor's page view.
 *
 * WordPress 6.9+: the page is taken from WordPress's own template
 * output buffer (wp_template_enhancement_output_buffer, last priority),
 * so it is the HTML the visitor gets, after core has filled in its late
 * styles (wp_hoist_late_printed_styles). Adding the filter in
 * template_include is early enough for core to start that buffer.
 *
 * Older WordPress (Bible §14.11): a wrapper template (template_include,
 * last priority) runs the theme's template inside our output buffer at
 * file scope (includes/template.php), so templates keep the global scope.
 * The buffer is ours and closed only when it is still the top one (§14.7).
 *
 * A page is stored only when every check passes (anything unclear = not
 * cached): GET, no logged-in user, the drop-in agreed (FPG_Key), status
 * 200, HTML with </html>, no DONOTCACHEPAGE, not a 404 / search / feed /
 * preview / password page, not cart / checkout / account, no Set-Cookie
 * and no no-cache / no-store / private header (nocache_headers()).
 *
 * The optimizations (FPG_Optimize) run on the stored HTML only, once.
 * Writing files from a visitor's request is the purpose of the plugin;
 * it never writes to the database here (Bible §14.5).
 */

defined( 'ABSPATH' ) || exit;

final class FPG_Cache {

	/** The theme's template, while ours wraps it. */
	private static $template = '';

	/** Output buffer level of our buffer. */
	private static $level = 0;

	/** The request as the drop-in saw it (or as we compute it). */
	private static $req = null;

	public static function init(): void {
		add_filter( 'template_include', array( __CLASS__, 'wrap' ), PHP_INT_MAX );
	}

	/** The request per FPG_Key (from the drop-in when it ran). */
	public static function req(): array {
		if ( null === self::$req ) {
			$g = isset( $GLOBALS['fpg_request'] ) && is_array( $GLOBALS['fpg_request'] ) ? $GLOBALS['fpg_request'] : null;
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared and hashed only.
			self::$req = $g ? $g : FPG_Key::request( $_SERVER, $_COOKIE, FPG_Setup::config() );
		}
		return self::$req;
	}

	/** Worth trying: the drop-in can serve what we write. */
	private static function candidate(): bool {
		// config()['on']: enabled, not killed, no other page cache active.
		if ( ! FPG_Setup::dropin_loaded() || ! FPG_Settings::get()['enabled'] || Fast_Pages::killed() || empty( FPG_Setup::config()['on'] ) ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( is_user_logged_in() ) {
			return false;
		}
		return (bool) self::req()['ok'];
	}

	public static function wrap( $template ) {
		try {
			if ( ! is_string( $template ) || '' === $template || ! self::candidate() ) {
				return $template;
			}
			if ( function_exists( 'wp_finalize_template_enhancement_output_buffer' ) && apply_filters( 'wp_should_output_buffer_template_for_enhancement', true ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core filter.
				add_filter( 'wp_template_enhancement_output_buffer', array( __CLASS__, 'finish' ), PHP_INT_MAX );
				return $template;
			}
			self::$template = $template;
			return FPG_PATH . 'includes/template.php';
		} catch ( \Throwable $e ) {
			return $template;
		}
	}

	/** The wrapped template (includes/template.php). */
	public static function template(): string {
		return self::$template;
	}

	public static function begin(): void {
		ob_start();
		self::$level = ob_get_level();
	}

	public static function end(): void {
		if ( ob_get_level() !== self::$level || self::$level < 1 ) {
			return; // Not our buffer on top: leave every buffer alone.
		}
		$html = (string) ob_get_clean();
		echo self::finish( $html ); // phpcs:ignore WordPress.Security.EscapeOutput -- the page itself.
	}

	/** Stores the page when it may be cached; returns what to send. */
	public static function finish( $html ): string {
		$html = (string) $html;
		try {
			$why = self::reason( $html );
			if ( '' !== $why ) {
				self::header( 'BYPASS (' . $why . ')' );
				return $html;
			}
			$out = FPG_Optimize::run( $html );
			if ( FPG_Files::write( self::req()['file'], $out ) ) {
				self::header( 'MISS' );
			}
			return $out;
		} catch ( \Throwable $e ) {
			return $html;
		}
	}

	/** Why this page is not stored ('' = it is). */
	private static function reason( string $html ): string {
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return 'DONOTCACHEPAGE';
		}
		if ( is_user_logged_in() ) {
			return 'user';
		}
		if ( ! self::req()['ok'] ) {
			return self::req()['reason'];
		}
		$code = http_response_code();
		if ( false !== $code && 200 !== (int) $code ) {
			return 'status';
		}
		if ( is_404() || is_search() || is_feed() || is_preview() || is_trackback() || is_robots() || is_embed() ) {
			return 'page';
		}
		if ( is_singular() && post_password_required() ) {
			return 'password';
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return 'shop';
		}
		foreach ( headers_list() as $h ) {
			$h = strtolower( (string) $h );
			if ( 0 === strpos( $h, 'set-cookie:' ) ) {
				return 'cookie set';
			}
			if ( 0 === strpos( $h, 'cache-control:' ) && preg_match( '/no-cache|no-store|private/', $h ) ) {
				return 'no-cache';
			}
			if ( 0 === strpos( $h, 'content-type:' ) && false === strpos( $h, 'text/html' ) ) {
				return 'type';
			}
		}
		if ( strlen( $html ) < 255 || false === stripos( $html, '</html>' ) ) {
			return 'html';
		}
		/**
		 * Last word on storing a page (false = not cached).
		 *
		 * @param bool   $cache Store it.
		 * @param string $html  The page.
		 */
		if ( ! apply_filters( 'fpg_cacheable', true, $html ) ) {
			return 'filter';
		}
		return '';
	}

	private static function header( string $value ): void {
		if ( ! headers_sent() ) {
			header( 'X-Fast-Pages: ' . $value );
		}
	}
}

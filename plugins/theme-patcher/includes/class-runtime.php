<?php
/**
 * TP_Runtime — injection of the slot into theme-drawn product cards.
 *
 * How it works (per loop, never per page):
 *
 *  1. Context. On `loop_start( $query )` the loop is mapped to an area:
 *     the main query on a product archive → "shop"; any other product
 *     query → "file:<theme file running the loop>" (found once per loop
 *     with a short debug_backtrace). Templates that loop with foreach +
 *     setup_postdata (related / up-sells / cross-sells) get their context
 *     from `woocommerce_before_template_part` instead. When the area is in
 *     inject mode, an output buffer opens with self::process() as handler.
 *  2. Card. `the_post` tells which product is being drawn. When the
 *     standard card hooks fire for it (woocommerce_before_shop_loop_item …)
 *     the card is a standard one and is skipped.
 *  3. Marker. When the anchor fires for the card (`the_title` or
 *     `post_thumbnail_html`), the slot is rendered right then (correct
 *     global $product) and a marker <!--tp:{nonce}:{n}--> is appended.
 *     The nonce is random per request, so product titles cannot forge it.
 *  4. Placement. self::process() is pure string work (no ob_* calls, as
 *     PHP requires inside a handler): for each marker it takes the first
 *     occurrence that is not inside a tag or attribute, finds the first
 *     closing tag </{tag}> after it (before the next card's marker) and
 *     inserts the slot there. Every marker is always removed.
 *  5. Close. On `loop_end` / `woocommerce_after_template_part` the buffer
 *     is flushed only if it is the top one and ours; otherwise PHP flushes
 *     it at shutdown and the handler still runs — no marker can leak.
 *
 * Safety (Bible §14): runs only on front-end page requests; never in
 * admin, AJAX, REST, feeds, embeds, cron, CLI or XML-RPC; block themes are
 * skipped; any Throwable disables the runtime for the rest of the request
 * and leaves the original output untouched; no database writes except the
 * admin-only probe transient.
 */

defined( 'ABSPATH' ) || exit;

final class TP_Runtime {

	const HANDLER      = 'TP_Runtime::process';
	const MAX_BUFFER   = 8388608; // 8 MB — larger buffers are only cleaned of markers.
	const PROBE_ARG    = 'tp_probe';
	const PROBE_NONCE  = 'tp_probe';
	const PROBE_TTL    = 3600;
	const STD_HOOKS    = array(
		'woocommerce_before_shop_loop_item',
		'woocommerce_before_shop_loop_item_title',
		'woocommerce_shop_loop_item_title',
		'woocommerce_after_shop_loop_item',
	);

	/** Runtime switched on for this request. */
	private static $on = false;

	/** Set after any Throwable: no further work this request. */
	private static $failed = false;

	/** Random marker nonce (per request). */
	private static $nonce = '';

	/** Marker counter. */
	private static $seq = 0;

	/** token (int) => [ 'html' => slot, 'area' => id, 'tag' => tag ]. */
	private static $slots = array();

	/** Context stack (nested loops). */
	private static $stack = array();

	/** Probe mode (admin with nonce) and its report. */
	private static $probe  = false;
	private static $report = array();

	/* =====================================================================
	 * Boot
	 * =================================================================== */

	public static function init(): void {
		// 'wp': the main query has run and the current user is known.
		add_action( 'wp', array( __CLASS__, 'boot' ), 20 );
	}

	/**
	 * Common front-end gate (also used by TP_Replace): a normal page
	 * request on a classic theme.
	 */
	public static function request_ok(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return false;
		}
		if ( is_feed() || is_embed() || is_robots() || is_trackback() ) {
			return false;
		}
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return false;
		}
		return true;
	}

	/** True when fixes must apply to the current visitor. */
	public static function applies(): bool {
		if ( self::is_probe() ) {
			return true; // Admin preview: works even with the master switch off.
		}
		$s = TP_Settings::get();
		if ( empty( $s['enabled'] ) ) {
			return false;
		}
		if ( ! empty( $s['test_mode'] ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}
		return true;
	}

	/** Valid probe request: ?tp_probe=<nonce> by a shop manager. */
	public static function is_probe(): bool {
		static $probe = null;
		if ( null !== $probe ) {
			return $probe;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the value IS the nonce, verified here.
		$raw   = isset( $_GET[ self::PROBE_ARG ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::PROBE_ARG ] ) ) : '';
		$probe = '' !== $raw
			&& current_user_can( 'manage_woocommerce' )
			&& false !== wp_verify_nonce( $raw, self::PROBE_NONCE );
		return $probe;
	}

	public static function boot(): void {
		try {
			if ( ! self::request_ok() || ! self::applies() ) {
				return;
			}
			self::$probe = self::is_probe();
			if ( ! self::$probe && ! TP_Settings::has_active_areas() ) {
				return;
			}

			self::$on    = true;
			self::$nonce = strtolower( wp_generate_password( 12, false, false ) );

			add_action( 'loop_start', array( __CLASS__, 'on_loop_start' ), 1 );
			add_action( 'the_post', array( __CLASS__, 'on_the_post' ), 1, 2 );
			add_action( 'loop_end', array( __CLASS__, 'on_loop_end' ), 999 );
			add_action( 'woocommerce_before_template_part', array( __CLASS__, 'on_before_template' ), 1, 4 );
			add_action( 'woocommerce_after_template_part', array( __CLASS__, 'on_after_template' ), 999, 4 );
			foreach ( self::STD_HOOKS as $hook ) {
				add_action( $hook, array( __CLASS__, 'on_standard_hook' ), 0 );
			}
			add_filter( 'the_title', array( __CLASS__, 'on_title' ), 999, 2 );
			add_filter( 'post_thumbnail_html', array( __CLASS__, 'on_thumbnail' ), 999, 2 );
			add_filter( 'post_thumbnail_size', array( __CLASS__, 'thumbnail_size' ), 999, 2 );
			add_action( 'wp_footer', array( 'TP_Render', 'maybe_enqueue_cart_script' ), 5 );

			if ( self::$probe ) {
				self::$report = array(
					'url'   => esc_url_raw( home_url( add_query_arg( array() ) ) ),
					'time'  => time(),
					'theme' => TP_Settings::theme_key(),
					'loops' => array(),
				);
				add_action( 'shutdown', array( __CLASS__, 'save_probe' ), 0 );
			}
		} catch ( \Throwable $e ) {
			self::fail();
		}
	}

	/** Stop all work for the rest of the request. */
	private static function fail(): void {
		self::$failed = true;
		self::$on     = false;
	}

	private static function live(): bool {
		return self::$on && ! self::$failed;
	}

	/* =====================================================================
	 * Contexts
	 * =================================================================== */

	private static function top(): ?array {
		$n = count( self::$stack );
		return $n ? self::$stack[ $n - 1 ] : null;
	}

	private static function set_top( array $ctx ): void {
		$n = count( self::$stack );
		if ( $n ) {
			self::$stack[ $n - 1 ] = $ctx;
		}
	}

	/** Push a context; opens our buffer when the area injects. */
	private static function push( string $area, string $key, string $file ): void {
		$cfg    = TP_Settings::area( $area );
		$inject = 'inject' === TP_Settings::mode( $area );

		$ctx = array(
			'area'    => $area,
			'key'     => $key,
			'file'    => $file,
			'cfg'     => $cfg,
			'inject'  => $inject,
			'level'   => 0,
			'card'    => 0,
			'std'     => false,
			'token'   => 0,
			'cards'   => 0,
			'std_n'   => 0,
			'marked'  => 0,
			'started' => microtime( true ),
		);

		if ( $inject ) {
			ob_start( array( __CLASS__, 'process' ) );
			$ctx['level'] = ob_get_level();
		}
		self::$stack[] = $ctx;
	}

	/** Pop the top context; flush our buffer when it is safely on top. */
	private static function pop(): void {
		$ctx = array_pop( self::$stack );
		if ( null === $ctx ) {
			return;
		}
		self::close_card( $ctx );

		if ( $ctx['inject'] && $ctx['level'] > 0 && ob_get_level() === $ctx['level'] ) {
			$handlers = ob_list_handlers();
			if ( self::HANDLER === end( $handlers ) ) {
				ob_end_flush();
			}
			// Otherwise: left open — PHP flushes it at shutdown through process().
		}

		if ( self::$probe ) {
			self::$report['loops'][] = array(
				'area'     => $ctx['area'],
				'file'     => $ctx['file'],
				'mode'     => TP_Settings::mode( $ctx['area'] ),
				'cards'    => $ctx['cards'],
				'standard' => $ctx['std_n'],
				'marked'   => $ctx['marked'],
				'ms'       => round( ( microtime( true ) - $ctx['started'] ) * 1000, 1 ),
			);
		}
	}

	/** Count the finished card. */
	private static function close_card( array &$ctx ): void {
		if ( $ctx['card'] > 0 ) {
			++$ctx['cards'];
			if ( $ctx['std'] ) {
				++$ctx['std_n'];
			}
		}
		$ctx['card']  = 0;
		$ctx['std']   = false;
		$ctx['token'] = 0;
	}

	/* =====================================================================
	 * Hooks — loops
	 * =================================================================== */

	public static function on_loop_start( $query ): void {
		if ( ! self::live() || ! ( $query instanceof WP_Query ) ) {
			return;
		}
		try {
			$top = self::top();
			// A template context (related…) without its own loop yet adopts this one.
			if ( null !== $top && 0 === strpos( $top['key'], 'tpl:' ) && false === strpos( $top['key'], '#' ) ) {
				$top['key'] .= '#' . spl_object_hash( $query );
				self::set_top( $top );
				return;
			}

			$key = 'q:' . spl_object_hash( $query );

			if ( $query->is_main_query() ) {
				if ( TP_Areas::is_product_archive() ) {
					self::push_if_wanted( 'shop', $key, '' );
				}
				return;
			}

			if ( ! self::is_product_query( $query ) ) {
				return;
			}

			$file = self::loop_file();
			if ( '' === $file ) {
				return;
			}
			self::push_if_wanted( TP_Areas::FILE_PREFIX . $file, $key, $file );
		} catch ( \Throwable $e ) {
			self::fail();
		}
	}

	/**
	 * Push only for areas that do something (or in probe mode, where every
	 * product loop is recorded so the admin sees what exists).
	 */
	private static function push_if_wanted( string $area, string $key, string $file ): void {
		if ( 'inject' === TP_Settings::mode( $area ) || self::$probe ) {
			self::push( $area, $key, $file );
		}
	}

	public static function on_loop_end( $query ): void {
		if ( ! self::live() || ! ( $query instanceof WP_Query ) ) {
			return;
		}
		try {
			$top = self::top();
			if ( null !== $top && 'q:' . spl_object_hash( $query ) === $top['key'] ) {
				self::pop();
			}
		} catch ( \Throwable $e ) {
			self::fail();
		}
	}

	public static function on_before_template( $template_name, $template_path = '', $located = '', $args = array() ): void {
		if ( ! self::live() ) {
			return;
		}
		try {
			$area = TP_Areas::area_for_template( (string) $template_name );
			if ( '' === $area ) {
				return;
			}
			$file = TP_Areas::theme_relative( (string) $located );
			self::push_if_wanted( $area, 'tpl:' . $template_name, $file );
		} catch ( \Throwable $e ) {
			self::fail();
		}
	}

	public static function on_after_template( $template_name, $template_path = '', $located = '', $args = array() ): void {
		if ( ! self::live() ) {
			return;
		}
		try {
			$top = self::top();
			if ( null !== $top && 0 === strpos( $top['key'], 'tpl:' . $template_name ) ) {
				self::pop();
			}
		} catch ( \Throwable $e ) {
			self::fail();
		}
	}

	/** A non-main query that returns products only. */
	private static function is_product_query( WP_Query $q ): bool {
		$pt = $q->get( 'post_type' );
		if ( is_string( $pt ) ) {
			return 'product' === $pt;
		}
		if ( is_array( $pt ) && $pt ) {
			return array() === array_diff( $pt, array( 'product' ) );
		}
		return false;
	}

	/**
	 * Theme-relative file running the current loop: the first stack frame
	 * that belongs to the active theme. Called once per loop.
	 */
	private static function loop_file(): string {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- once per loop, no args.
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 16 );
		foreach ( $trace as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$rel = TP_Areas::theme_relative( $frame['file'] );
			if ( '' !== $rel ) {
				return $rel;
			}
		}
		return '';
	}

	/* =====================================================================
	 * Hooks — cards
	 * =================================================================== */

	public static function on_the_post( $post, $query = null ): void {
		if ( ! self::live() ) {
			return;
		}
		try {
			$top = self::top();
			if ( null === $top || ! ( $post instanceof WP_Post ) ) {
				return;
			}
			// Loop contexts accept only their own query's posts; template
			// contexts (foreach + setup_postdata) accept any.
			$own = ( $query instanceof WP_Query ) && false !== strpos( $top['key'], spl_object_hash( $query ) );
			if ( ! $own && 0 !== strpos( $top['key'], 'tpl:' ) ) {
				// Another product loop runs while this context is still open
				// (e.g. its loop was left with "break"): its last card is over,
				// so the same product drawn elsewhere gets no second marker.
				if ( $top['card'] > 0 && 'product' === $post->post_type ) {
					self::close_card( $top );
					self::set_top( $top );
				}
				return;
			}
			self::close_card( $top );
			if ( 'product' === $post->post_type ) {
				$top['card'] = (int) $post->ID;
			}
			self::set_top( $top );
		} catch ( \Throwable $e ) {
			self::fail();
		}
	}

	/** Standard card hooks fired → this card is a standard one (not when we run them). */
	public static function on_standard_hook(): void {
		if ( ! self::live() || TP_Render::rendering() ) {
			return;
		}
		$top = self::top();
		if ( null !== $top && $top['card'] > 0 && ! $top['std'] ) {
			$top['std'] = true;
			self::set_top( $top );
		}
	}

	public static function on_title( $title, $id = 0 ) {
		return self::anchor( $title, (int) $id, 'title' );
	}

	public static function on_thumbnail( $html, $post_id = 0 ) {
		return self::anchor( $html, (int) $post_id, 'thumbnail' );
	}

	/**
	 * Card image size of the area (e.g. woocommerce_thumbnail instead of a
	 * full-size image), for the card being drawn only.
	 *
	 * @param string|int[] $size    Requested size.
	 * @param int          $post_id Post id.
	 */
	public static function thumbnail_size( $size, $post_id = 0 ) {
		if ( ! self::live() ) {
			return $size;
		}
		try {
			$top = self::top();
			if ( null === $top || ! $top['inject'] || $top['card'] !== (int) $post_id || '' === $top['cfg']['thumb'] ) {
				return $size;
			}
			return TP_Settings::size_exists( $top['cfg']['thumb'] ) ? $top['cfg']['thumb'] : $size;
		} catch ( \Throwable $e ) {
			return $size;
		}
	}

	/**
	 * Append the card's marker when this is the anchor of the current
	 * card (same product, inject area, configured anchor, not standard).
	 */
	private static function anchor( $value, int $id, string $kind ) {
		if ( ! self::live() || ! is_string( $value ) || $id <= 0 || TP_Render::rendering() ) {
			return $value;
		}
		try {
			$top = self::top();
			if ( null === $top || ! $top['inject'] || $top['card'] !== $id || $top['std'] ) {
				return $value;
			}
			if ( $top['cfg']['anchor'] !== $kind ) {
				return $value;
			}

			if ( 0 === $top['token'] ) {
				$html = TP_Render::slot( $id, $top['cfg'], $top['area'] );
				if ( '' === $html ) {
					$top['token'] = -1; // Nothing to show for this card.
					self::set_top( $top );
					return $value;
				}
				$token                 = ++self::$seq;
				self::$slots[ $token ] = array(
					'html' => $html,
					'area' => $top['area'],
					'tag'  => $top['cfg']['tag'],
				);
				$top['token'] = $token;
				++$top['marked'];
				self::set_top( $top );
			}

			if ( $top['token'] > 0 ) {
				return $value . self::marker( $top['token'] );
			}
		} catch ( \Throwable $e ) {
			self::fail();
		}
		return $value;
	}

	private static function marker( int $token ): string {
		return '<!--tp:' . self::$nonce . ':' . $token . '-->';
	}

	/* =====================================================================
	 * Output handler (pure string work — no ob_* calls allowed here)
	 * =================================================================== */

	/**
	 * @param string $buffer Captured output.
	 * @param int    $phase  PHP_OUTPUT_HANDLER_* flags (unused).
	 */
	public static function process( $buffer, $phase = 0 ) {
		if ( ! is_string( $buffer ) || '' === self::$nonce ) {
			return $buffer;
		}
		$prefix = '<!--tp:' . self::$nonce . ':';
		if ( false === strpos( $buffer, $prefix ) ) {
			return $buffer;
		}
		$pattern = '/<!--tp:' . preg_quote( self::$nonce, '/' ) . ':(\d+)-->/';

		try {
			if ( self::$failed || strlen( $buffer ) > self::MAX_BUFFER ) {
				return self::strip( $buffer, $pattern );
			}
			return self::place( $buffer, $pattern );
		} catch ( \Throwable $e ) {
			self::$failed = true;
			return self::strip( $buffer, $pattern );
		}
	}

	private static function strip( string $buffer, string $pattern ): string {
		$out = preg_replace( $pattern, '', $buffer );
		return is_string( $out ) ? $out : $buffer;
	}

	private static function place( string $buffer, string $pattern ): string {

		if ( ! preg_match_all( $pattern, $buffer, $m, PREG_OFFSET_CAPTURE ) ) {
			return $buffer;
		}

		// Every marker occurrence is removed.
		$edits  = array(); // [ pos, delete_len, insert, order ] — order 0 = delete first on ties.
		$chosen = array(); // token => [ start, end ] of the occurrence in text context.
		foreach ( $m[0] as $i => $occ ) {
			$pos   = (int) $occ[1];
			$len   = strlen( $occ[0] );
			$token = (int) $m[1][ $i ][0];

			$edits[] = array( $pos, $len, '', 0 );

			if ( ! isset( $chosen[ $token ] ) && isset( self::$slots[ $token ] ) && ! self::inside_tag( $buffer, $pos ) ) {
				$chosen[ $token ] = array( $pos, $pos + $len );
			}
		}

		// Boundaries: a slot must land before the next card's marker.
		$starts = array();
		foreach ( $chosen as $c ) {
			$starts[] = $c[0];
		}
		sort( $starts );

		foreach ( $chosen as $token => $c ) {
			$limit = strlen( $buffer );
			foreach ( $starts as $s ) {
				if ( $s > $c[0] ) {
					$limit = $s;
					break;
				}
			}
			$slot = self::$slots[ $token ];
			$at   = self::closing_tag_end( $buffer, $slot['tag'], $c[1], $limit );
			if ( $at < 0 ) {
				self::count_miss( $slot['area'] );
				unset( self::$slots[ $token ] );
				continue;
			}
			$edits[] = array( $at, 0, $slot['html'], 1 );
			unset( self::$slots[ $token ] );
		}

		// Apply from the end: higher positions first; on ties delete before insert.
		usort(
			$edits,
			static function ( $a, $b ) {
				if ( $a[0] !== $b[0] ) {
					return $b[0] <=> $a[0];
				}
				return $a[3] <=> $b[3];
			}
		);
		foreach ( $edits as $e ) {
			$buffer = substr( $buffer, 0, $e[0] ) . $e[2] . substr( $buffer, $e[0] + $e[1] );
		}
		return $buffer;
	}

	/** Position is inside a tag (e.g. an attribute value) — last "<" after last ">". */
	private static function inside_tag( string $buffer, int $pos ): bool {
		if ( 0 === $pos ) {
			return false;
		}
		$offset = $pos - strlen( $buffer ) - 1; // Search backwards from $pos - 1.
		$lt     = strrpos( $buffer, '<', $offset );
		$gt     = strrpos( $buffer, '>', $offset );
		if ( false === $lt ) {
			return false;
		}
		return false === $gt || $lt > $gt;
	}

	/**
	 * Offset just after the first "</tag>" at or after $from and before
	 * $limit, or -1.
	 */
	private static function closing_tag_end( string $buffer, string $tag, int $from, int $limit ): int {
		$needle = '</' . $tag;
		$n      = strlen( $needle );
		$pos    = $from;
		while ( true ) {
			$p = stripos( $buffer, $needle, $pos );
			if ( false === $p || $p >= $limit ) {
				return -1;
			}
			$next = isset( $buffer[ $p + $n ] ) ? $buffer[ $p + $n ] : '';
			if ( '>' === $next || ctype_space( $next ) ) {
				$gt = strpos( $buffer, '>', $p + $n );
				return false === $gt ? -1 : $gt + 1;
			}
			$pos = $p + $n; // e.g. "</h3x" or "</a" matched "</abbr" — keep looking.
		}
	}

	/* =====================================================================
	 * Probe report (admin only, with nonce)
	 * =================================================================== */

	private static $misses = array();

	private static function count_miss( string $area ): void {
		self::$misses[ $area ] = isset( self::$misses[ $area ] ) ? self::$misses[ $area ] + 1 : 1;
	}

	public static function save_probe(): void {
		if ( ! self::$probe ) {
			return;
		}
		// Buffers still open (e.g. a loop the theme left with "break") are
		// flushed by PHP after shutdown hooks; flush ours now so their
		// misses are counted in the report.
		while ( ob_get_level() > 0 ) {
			$handlers = ob_list_handlers();
			if ( self::HANDLER !== end( $handlers ) ) {
				break;
			}
			ob_end_flush();
		}
		foreach ( self::$report['loops'] as $i => $loop ) {
			$miss = isset( self::$misses[ $loop['area'] ] ) ? self::$misses[ $loop['area'] ] : 0;
			self::$report['loops'][ $i ]['missed'] = $miss;
			self::$misses[ $loop['area'] ]         = 0; // Count once per area.
		}
		self::$report['failed'] = self::$failed;
		self::$report['patch']  = array(
			'page'  => TP_Page::stats(),
			'theme' => TP_Theme::stats(),
			'cats'  => TP_Categories::stats(),
		);
		set_transient( 'tp_probe_' . get_current_user_id(), self::$report, self::PROBE_TTL );
	}
}

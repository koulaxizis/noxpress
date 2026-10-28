<?php
/**
 * BRC_Settings — the brc_settings option and its strict validation
 * (Bible §11, whitelists; unknown keys dropped, bad values → defaults).
 *
 * Keys:
 *  - mods          array  module => bool, every module off until switched on
 *  - mode          string 'test' (nothing leaves the site, default) | 'live' | 'off'
 *  - approve       array  source => bool, "needs approval" (on by default;
 *                         the queue source always waits for «Δημοσιεύτηκε»)
 *  - trial_at      int    when the first source was switched on (the 14-day
 *                         trial notice), 0 before that
 *  - trial_done    bool   the trial notice was answered
 *  - lim_hour      int    brake: items per source per hour (1–100, 5)
 *  - lim_day       int    brake: items per source per day (1–500, 20)
 *  - keep_days     int    history kept (7–730 days, 90)
 *  - p_delay       int    products: minutes after publishing (0–60, 5)
 *  - p_cats_in     int[]  products: only these categories (empty = all)
 *  - p_cats_ex     int[]  products: never these categories
 *  - p_types       str[]  products: only these product types (empty = all)
 *  - p_image       bool   products: only with an image
 *  - p_skip_bf     bool   products: skip titles imported by Book Feed (on)
 *  - p_skip_hidden bool   products: skip categories Hide Catalog hides (on)
 *  - nl_link       string newsletter: fixed link for every campaign, or ''
 *  - vb_hook       string viber: webhook URL on another site, or '' (this site)
 *  - nl_preview    bool   newsletter: use the campaign's preview_url
 *  - yt_channels   str[]  youtube: channel ids UC + 22 characters (max 5)
 *  - yt_shorts     bool   youtube: include Shorts (off)
 *  - yt_live       bool   youtube: include live streams (off)
 *  - yt_every      int    youtube: minutes between checks (15–120, 30)
 *  - ig_reels      bool   instagram: also videos and Reels (off)
 *  - ig_tags       bool   instagram: remove the hashtags at the end (on)
 *  - ig_every      int    instagram: minutes between checks (15–120, 30)
 *  - dg_day        int    digest: 0 (Sunday) – 6 (Saturday), 5
 *  - dg_hour       int    digest: 0–23, 18
 *  - dg_max        int    digest: titles listed (1–20, 10)
 *  - dg_url        string digest: link of the message, or '' (the shop)
 *  - tpl_<source>  string template per source (BRC_Template)
 *
 * Autoloaded: init() reads the modules on every request.
 */

defined( 'ABSPATH' ) || exit;

final class BRC_Settings {

	const OPT = 'brc_settings';

	/** All modules: sources then destinations. */
	const MODULES = array( 'products', 'newsletter', 'youtube', 'instagram', 'queue', 'digest', 'viber' );

	/** Sources whose items may wait for approval. */
	const APPROVABLE = array( 'products', 'newsletter', 'youtube', 'instagram', 'digest' );

	/** @var array|null Per-request cache. */
	private static $cache = null;

	/** Default templates in the site's language. */
	public static function default_templates(): array {
		$el = 'el' === BRC_Lang::from_locale( (string) get_locale() );
		if ( $el ) {
			return array(
				'products'   => "Νέο στο κατάστημα: {title}\n{price}{preorder}\n{url}",
				'newsletter' => "📬 Νέο newsletter: {title}\n{url}",
				'youtube'    => "🎬 {title}\n{url}",
				'instagram'  => "{text}\n{url}",
				'queue'      => "{text}\n{url}",
				'digest'     => "📚 {count} νέοι τίτλοι από άλλους εκδότες\n{list}\n{url}",
			);
		}
		return array(
			'products'   => "New in the shop: {title}\n{price}{preorder}\n{url}",
			'newsletter' => "📬 New newsletter: {title}\n{url}",
			'youtube'    => "🎬 {title}\n{url}",
			'instagram'  => "{text}\n{url}",
			'queue'      => "{text}\n{url}",
			'digest'     => "📚 {count} new titles from other publishers\n{list}\n{url}",
		);
	}

	public static function defaults(): array {
		$d = array(
			'mods'          => array_fill_keys( self::MODULES, false ),
			'mode'          => 'test',
			'approve'       => array_fill_keys( self::APPROVABLE, true ),
			'trial_at'      => 0,
			'trial_done'    => false,
			'lim_hour'      => 5,
			'lim_day'       => 20,
			'keep_days'     => 90,
			'p_delay'       => 5,
			'p_cats_in'     => array(),
			'p_cats_ex'     => array(),
			'p_types'       => array(),
			'p_image'       => false,
			'p_skip_bf'     => true,
			'p_skip_hidden' => true,
			'nl_link'       => '',
			'nl_preview'    => false,
			'yt_channels'   => array(),
			'yt_shorts'     => false,
			'yt_live'       => false,
			'yt_every'      => 30,
			'ig_reels'      => false,
			'ig_tags'       => true,
			'ig_every'      => 30,
			'dg_day'        => 5,
			'dg_hour'       => 18,
			'dg_max'        => 10,
			'dg_url'        => '',
			'vb_hook'       => '',
		);
		foreach ( self::default_templates() as $src => $tpl ) {
			$d[ 'tpl_' . $src ] = $tpl;
		}
		return $d;
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPT, array() );
			self::$cache = self::sanitize( is_array( $raw ) ? $raw : array() );
		}
		return self::$cache;
	}

	public static function save( array $raw ): void {
		$clean = self::sanitize( $raw );
		// The trial starts the first time a source is switched on.
		if ( ! $clean['trial_at'] ) {
			foreach ( array_keys( Broadcast::SOURCES ) as $src ) {
				if ( $clean['mods'][ $src ] ) {
					$clean['trial_at'] = time();
					break;
				}
			}
		}
		update_option( self::OPT, $clean, true );
		self::$cache = $clean;
	}

	/** Drops the per-request cache (after another request changed the option). */
	public static function flush(): void {
		self::$cache = null;
	}

	private static function int_in( array $raw, string $k, int $min, int $max, int $def ): int {
		if ( ! isset( $raw[ $k ] ) || ! is_numeric( $raw[ $k ] ) ) {
			return $def;
		}
		$v = (int) $raw[ $k ];
		return $v < $min || $v > $max ? $def : $v;
	}

	private static function ids( $v ): array {
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$out[] = (int) $id;
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 200 );
	}

	/**
	 * Plain text for a post: no tags, no control characters, newlines kept.
	 * Not sanitize_textarea_field(), which drops percent-encoded octets and
	 * so breaks links with a Greek slug (…/%ce%b2…) inside the text.
	 */
	public static function plain( string $v ): string {
		$v = wp_check_invalid_utf8( str_replace( array( "\r\n", "\r" ), "\n", $v ) );
		// A lone "<" (e.g. "<3") survives as itself, not as &lt;.
		$v = str_replace( '&lt;', '<', wp_strip_all_tags( wp_pre_kses_less_than( $v ) ) );
		$v = (string) preg_replace( '/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}]+/u', '', $v );
		return trim( (string) preg_replace( '/[ \t]+$/m', '', $v ) );
	}

	/** An https URL without credentials, or ''. */
	public static function clean_url( $v ): string {
		$v = is_string( $v ) ? trim( $v ) : '';
		if ( '' === $v || ! preg_match( '#^https://#i', $v ) ) {
			return '';
		}
		$url = esc_url_raw( $v, array( 'https' ) );
		$p   = wp_parse_url( $url );
		if ( ! $p || empty( $p['host'] ) || isset( $p['user'] ) || isset( $p['pass'] ) ) {
			return '';
		}
		return mb_substr( $url, 0, 500 );
	}

	public static function sanitize( array $raw ): array {
		$d = self::defaults();
		$s = array();

		$s['mods'] = array();
		$mods      = isset( $raw['mods'] ) && is_array( $raw['mods'] ) ? $raw['mods'] : array();
		foreach ( self::MODULES as $m ) {
			$s['mods'][ $m ] = ! empty( $mods[ $m ] );
		}

		$s['mode'] = isset( $raw['mode'] ) && in_array( $raw['mode'], array( 'test', 'live', 'off' ), true ) ? $raw['mode'] : $d['mode'];

		$s['approve'] = array();
		$ap           = isset( $raw['approve'] ) && is_array( $raw['approve'] ) ? $raw['approve'] : array();
		foreach ( self::APPROVABLE as $src ) {
			$s['approve'][ $src ] = array_key_exists( $src, $ap ) ? (bool) $ap[ $src ] : true;
		}

		$s['trial_at']   = isset( $raw['trial_at'] ) && is_numeric( $raw['trial_at'] ) ? max( 0, (int) $raw['trial_at'] ) : 0;
		$s['trial_done'] = ! empty( $raw['trial_done'] );

		$s['lim_hour']  = self::int_in( $raw, 'lim_hour', 1, 100, $d['lim_hour'] );
		$s['lim_day']   = self::int_in( $raw, 'lim_day', 1, 500, $d['lim_day'] );
		$s['keep_days'] = self::int_in( $raw, 'keep_days', 7, 730, $d['keep_days'] );
		$s['p_delay']   = self::int_in( $raw, 'p_delay', 0, 60, $d['p_delay'] );

		$s['p_cats_in'] = self::ids( isset( $raw['p_cats_in'] ) ? $raw['p_cats_in'] : array() );
		$s['p_cats_ex'] = self::ids( isset( $raw['p_cats_ex'] ) ? $raw['p_cats_ex'] : array() );

		$s['p_types'] = array();
		foreach ( isset( $raw['p_types'] ) && is_array( $raw['p_types'] ) ? $raw['p_types'] : array() as $t ) {
			$t = is_string( $t ) ? sanitize_key( $t ) : '';
			if ( '' !== $t && strlen( $t ) <= 40 ) {
				$s['p_types'][] = $t;
			}
		}
		$s['p_types'] = array_slice( array_values( array_unique( $s['p_types'] ) ), 0, 20 );

		foreach ( array( 'p_image', 'p_skip_bf', 'p_skip_hidden', 'nl_preview', 'yt_shorts', 'yt_live', 'ig_reels', 'ig_tags' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}

		$s['nl_link'] = self::clean_url( isset( $raw['nl_link'] ) ? $raw['nl_link'] : '' );
		$s['dg_url']  = self::clean_url( isset( $raw['dg_url'] ) ? $raw['dg_url'] : '' );
		$s['vb_hook'] = self::clean_url( isset( $raw['vb_hook'] ) ? $raw['vb_hook'] : '' );

		$s['yt_channels'] = array();
		foreach ( isset( $raw['yt_channels'] ) && is_array( $raw['yt_channels'] ) ? $raw['yt_channels'] : array() as $c ) {
			if ( is_string( $c ) && preg_match( '/^UC[A-Za-z0-9_-]{22}$/', $c ) ) {
				$s['yt_channels'][] = $c;
			}
		}
		$s['yt_channels'] = array_slice( array_values( array_unique( $s['yt_channels'] ) ), 0, 5 );
		$s['yt_every']    = self::int_in( $raw, 'yt_every', 15, 120, $d['yt_every'] );
		$s['ig_every']    = self::int_in( $raw, 'ig_every', 15, 120, $d['ig_every'] );

		$s['dg_day']  = self::int_in( $raw, 'dg_day', 0, 6, $d['dg_day'] );
		$s['dg_hour'] = self::int_in( $raw, 'dg_hour', 0, 23, $d['dg_hour'] );
		$s['dg_max']  = self::int_in( $raw, 'dg_max', 1, 20, $d['dg_max'] );

		foreach ( array_keys( self::default_templates() ) as $src ) {
			$k = 'tpl_' . $src;
			$v = isset( $raw[ $k ] ) && is_string( $raw[ $k ] ) ? self::plain( $raw[ $k ] ) : '';
			// An empty template would post nothing: it falls back to the default.
			$s[ $k ] = '' === $v ? $d[ $k ] : mb_substr( $v, 0, 2000 );
		}

		return $s;
	}

	/** True when the module is switched on. */
	public static function module( string $m ): bool {
		$s = self::get();
		return ! empty( $s['mods'][ $m ] );
	}

	/** True when items of this source wait for approval. */
	public static function needs_approval( string $src ): bool {
		if ( 'queue' === $src ) {
			return true;
		}
		$s = self::get();
		return ! isset( $s['approve'][ $src ] ) || ! empty( $s['approve'][ $src ] );
	}

	/** True when the 14-day trial is over and the notice is still due. */
	public static function trial_due(): bool {
		$s = self::get();
		if ( $s['trial_done'] || ! $s['trial_at'] || $s['trial_at'] > time() - 14 * DAY_IN_SECONDS ) {
			return false;
		}
		foreach ( self::APPROVABLE as $src ) {
			if ( $s['mods'][ $src ] && $s['approve'][ $src ] ) {
				return true;
			}
		}
		return false;
	}

	/** Active destinations (modules that are on). */
	public static function destinations(): array {
		$out = array();
		foreach ( Broadcast::DESTS as $d ) {
			if ( self::module( $d ) ) {
				$out[] = $d;
			}
		}
		return $out;
	}
}

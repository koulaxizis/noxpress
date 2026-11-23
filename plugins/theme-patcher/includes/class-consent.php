<?php
/**
 * TP_Consent — scripts that load only after cookie consent (1.8.0).
 *
 * Some plugins enqueue third-party scripts on every page, before the
 * visitor answers the cookie banner (e.g. MailerLite for WooCommerce:
 * mailerlite-rewrite-universal / mailerlite-classic-universal, which load
 * assets.mailerlite.com/js/universal.js). For the script handles listed in
 * the settings, the <script src> tag is printed inert:
 *
 *   <script type="text/plain" data-tp-consent="analytics functional" data-tp-src="…">
 *
 * and a small inline script turns it into a real script once the visitor
 * accepts one of the chosen categories in CookieYes (the cookieyes-consent
 * cookie on page load, the cookieyes_consent_update event after a choice).
 * Inline before/after scripts and wp_localize_script data of the handle
 * stay as they are: they only set variables and load nothing.
 *
 * Client side on purpose: it works the same on pages served from a page
 * cache (Fast Pages), where PHP never sees the visitor's cookie.
 *
 * Rules (Bible §14): off by default; follows the master switch and test
 * mode of tp_settings; TP_DISABLE stops it (not even initialised); only
 * front-end page requests (never admin, AJAX, REST, feeds, cron, CLI);
 * never writes to the database; every callback is wrapped in try/catch and
 * returns the original tag on any Throwable. Without CookieYes the listed
 * scripts never load (a visitor who has not consented gets nothing): the
 * admin screen warns about it.
 */

defined( 'ABSPATH' ) || exit;

final class TP_Consent {

	/** Late, so other plugins have changed the tag first. */
	const PRIORITY = 100;

	/** Gate result for this request (null = not decided yet). */
	private static $gate = null;

	/** True once a tag was held back on this page (prints the loader). */
	private static $held = false;

	/** Set after any Throwable: no further work this request. */
	private static $failed = false;

	public static function init(): void {
		add_filter( 'script_loader_tag', array( __CLASS__, 'tag' ), self::PRIORITY, 3 );
		add_action( 'wp_footer', array( __CLASS__, 'loader' ), 999 );
	}

	/** True when CookieYes (cookie-law-info) is active. */
	public static function cookieyes_active(): bool {
		return defined( 'CLI_VERSION' ) || defined( 'CKY_APP_ASSETS_URL' ) || class_exists( '\CookieYes\Lite\Includes\CLI' ) || function_exists( 'cky_disable_banner' );
	}

	/** True when the gate applies to this request and visitor. */
	public static function on(): bool {
		if ( self::$failed ) {
			return false;
		}
		if ( null !== self::$gate ) {
			return self::$gate;
		}
		if ( defined( 'TP_DISABLE' ) && TP_DISABLE ) {
			return false;
		}
		$s  = TP_Settings::get();
		$c  = $s['consent'];
		$on = ! empty( $s['enabled'] )
			&& ! empty( $c['on'] )
			&& ! empty( $c['handles'] )
			&& ! empty( $c['cats'] )
			&& ( empty( $s['test_mode'] ) || current_user_can( 'manage_woocommerce' ) )
			&& ! wp_doing_ajax()
			&& TP_Prices::request_ok();
		if ( did_action( 'wp' ) ) {
			self::$gate = $on;
		}
		return $on;
	}

	/**
	 * script_loader_tag: the listed handles get an inert <script src> tag.
	 *
	 * @param mixed $tag    Tag HTML (may include inline before/after scripts).
	 * @param mixed $handle Script handle.
	 * @param mixed $src    Script URL.
	 * @return mixed
	 */
	public static function tag( $tag, $handle = '', $src = '' ) {
		if ( ! is_string( $tag ) || ! is_string( $handle ) || '' === $handle ) {
			return $tag;
		}
		try {
			$c = TP_Settings::get()['consent'];
			if ( ! in_array( $handle, $c['handles'], true ) || ! self::on() ) {
				return $tag;
			}
			$cats = implode( ' ', $c['cats'] );
			$done = 0;
			$out  = preg_replace_callback(
				'#<script\b([^>]*?)\ssrc\s*=\s*([\'"])(.*?)\2([^>]*)>#is',
				static function ( $m ) use ( $cats ) {
					$attrs = $m[1] . ' ' . $m[4];
					// Drop type / async / defer / src: the loader adds a fresh script.
					$attrs = preg_replace( '#\s(?:type|async|defer|nomodule)(?:\s*=\s*([\'"]).*?\1)?(?=\s|$)#is', ' ', ' ' . $attrs );
					$attrs = trim( preg_replace( '#\s+#', ' ', (string) $attrs ) );
					return '<script type="text/plain" data-tp-consent="' . esc_attr( $cats ) . '" data-tp-src="' . esc_attr( html_entity_decode( $m[3], ENT_QUOTES ) ) . '"' . ( '' !== $attrs ? ' ' . $attrs : '' ) . '>';
				},
				$tag,
				1,
				$done
			);
			if ( ! is_string( $out ) || 1 !== $done ) {
				return $tag;
			}
			self::$held = true;
			return $out;
		} catch ( \Throwable $e ) {
			self::$failed = true;
			return $tag;
		}
	}

	/** Footer: the loader, only on pages where a tag was held back. */
	public static function loader(): void {
		if ( ! self::$held || self::$failed ) {
			return;
		}
		try {
			echo "<script id=\"tp-consent-js\">\n" . self::js() . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static script.
		} catch ( \Throwable $e ) {
			self::$failed = true;
		}
	}

	/** The loader (ES5, no dependencies). */
	private static function js(): string {
		return <<<'JS'
(function () {
	function accepted() {
		var m = document.cookie.match( /(?:^|;\s*)cookieyes-consent=([^;]*)/ ), out = {};
		if ( ! m ) {
			return out;
		}
		var parts = decodeURIComponent( m[1] ).split( ',' );
		for ( var i = 0; i < parts.length; i++ ) {
			var kv = parts[ i ].split( ':' );
			if ( 2 === kv.length && 'yes' === kv[1] ) {
				out[ kv[0] ] = true;
			}
		}
		return out;
	}
	function run( ok ) {
		var list = document.querySelectorAll( 'script[type="text/plain"][data-tp-consent][data-tp-src]' );
		for ( var i = 0; i < list.length; i++ ) {
			var el = list[ i ], cats = el.getAttribute( 'data-tp-consent' ).split( ' ' ), go = false;
			for ( var j = 0; j < cats.length; j++ ) {
				if ( ok[ cats[ j ] ] ) {
					go = true;
				}
			}
			if ( ! go ) {
				continue;
			}
			var s = document.createElement( 'script' );
			for ( var k = 0; k < el.attributes.length; k++ ) {
				var a = el.attributes[ k ].name;
				if ( 'type' !== a && 'data-tp-consent' !== a && 'data-tp-src' !== a ) {
					s.setAttribute( a, el.attributes[ k ].value );
				}
			}
			s.src = el.getAttribute( 'data-tp-src' );
			s.async = false;
			el.parentNode.replaceChild( s, el );
		}
	}
	function check() {
		run( accepted() );
	}
	document.addEventListener( 'cookieyes_consent_update', function ( e ) {
		var ok = accepted();
		if ( e && e.detail && e.detail.accepted ) {
			for ( var i = 0; i < e.detail.accepted.length; i++ ) {
				ok[ e.detail.accepted[ i ] ] = true;
			}
		}
		run( ok );
	} );
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', check );
	} else {
		check();
	}
}());
JS;
	}
}

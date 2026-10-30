<?php
/**
 * TFI_Settings — the tfi_settings option and its strict validation
 * (Bible §11, whitelists; unknown keys dropped, bad values → defaults).
 *
 * Keys:
 *  - url        string the publisher's site, https only (the feed lives at
 *                      <url>/wp-json/tra/v1/)
 *  - user       string the Trade Feed username (or e-mail)
 *  - status     string new products: 'draft' (default) | 'publish'
 *  - cat        int    local category for new products (0 = the
 *                      WooCommerce default category)
 *  - cat_map    array  source category id => local category id (0 = use
 *                      `cat`); a product gets every mapped category
 *  - brand      bool   the publisher as a brand (product_brand), created
 *                      when missing (default on)
 *  - tax_class  string local tax class slug for new products ('' =
 *                      standard)
 *  - cost       bool   the trade price as the WooCommerce cost of goods,
 *                      where the store has that field (default on)
 *  - sync_on    bool   daily sync (default on)
 *  - hour       int    hour of the daily sync, site time zone (0–23)
 *  - auto_new   bool   the daily sync also imports titles that are new in
 *                      the feed, with `status` (default off)
 *  - gone       string a title the feed no longer lists: 'outofstock'
 *                      (default) | 'draft'
 *  - purge      bool   uninstall deletes the products the plugin created
 *                      (not the linked ones, which are the shop's own);
 *                      default off (Bible §12)
 *
 * The password is not here: tfi_pass, sealed (TFI_Crypt), autoload off.
 * The source categories (for the mapping) are cached in tfi_cats.
 */

defined( 'ABSPATH' ) || exit;

final class TFI_Settings {

	const OPT  = 'tfi_settings';
	const PASS = 'tfi_pass';
	const CATS = 'tfi_cats';

	/** Fields that follow the feed while they are what the plugin wrote (locks). */
	const SYNC_FIELDS = array( 'title', 'desc', 'short', 'image', 'attrs', 'dims' );

	/** @var array|null Per-request cache. */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			'url'       => '',
			'user'      => '',
			'status'    => 'draft',
			'cat'       => 0,
			'cat_map'   => array(),
			'brand'     => true,
			'tax_class' => '',
			'cost'      => true,
			'sync_on'   => true,
			'hour'      => 5,
			'auto_new'  => false,
			'gone'      => 'outofstock',
			'purge'     => false,
		);
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
		update_option( self::OPT, $clean, false );
		self::$cache = $clean;
	}

	public static function sanitize( array $raw ): array {
		$d = self::defaults();
		$s = array();

		$s['url']  = isset( $raw['url'] ) && is_string( $raw['url'] ) ? self::site_url( $raw['url'] ) : $d['url'];
		$s['user'] = isset( $raw['user'] ) && is_string( $raw['user'] ) ? mb_substr( trim( sanitize_text_field( $raw['user'] ) ), 0, 100 ) : $d['user'];

		$s['status'] = isset( $raw['status'] ) && in_array( $raw['status'], array( 'draft', 'publish' ), true ) ? $raw['status'] : $d['status'];
		$s['gone']   = isset( $raw['gone'] ) && in_array( $raw['gone'], array( 'outofstock', 'draft' ), true ) ? $raw['gone'] : $d['gone'];

		$s['cat'] = isset( $raw['cat'] ) && is_numeric( $raw['cat'] ) ? self::term_id( (int) $raw['cat'] ) : 0;

		$s['cat_map'] = array();
		if ( isset( $raw['cat_map'] ) && is_array( $raw['cat_map'] ) ) {
			foreach ( $raw['cat_map'] as $src => $local ) {
				$src   = is_numeric( $src ) ? (int) $src : 0;
				$local = is_numeric( $local ) ? self::term_id( (int) $local ) : 0;
				if ( $src > 0 && $local > 0 && count( $s['cat_map'] ) < 500 ) {
					$s['cat_map'][ $src ] = $local;
				}
			}
		}

		foreach ( array( 'brand', 'cost', 'sync_on', 'auto_new', 'purge' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}

		$s['tax_class'] = '';
		if ( isset( $raw['tax_class'] ) && is_string( $raw['tax_class'] ) && '' !== $raw['tax_class'] ) {
			$tc = sanitize_title( $raw['tax_class'] );
			if ( ! did_action( 'init' ) || ! class_exists( 'WC_Tax' ) || in_array( $tc, WC_Tax::get_tax_class_slugs(), true ) ) {
				$s['tax_class'] = $tc;
			}
		}

		$hour      = isset( $raw['hour'] ) && is_numeric( $raw['hour'] ) ? (int) $raw['hour'] : $d['hour'];
		$s['hour'] = max( 0, min( 23, $hour ) );

		return $s;
	}

	/**
	 * The publisher's site as https://host[/path] without a trailing slash,
	 * '' when it is not an https address. A pasted feed address
	 * (…/wp-json/tra/v1/products) is cut back to the site.
	 */
	public static function site_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$url = (string) preg_replace( '#/wp-json(/.*)?$#i', '', $url );
		$p   = wp_parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) || 'https' !== strtolower( (string) ( $p['scheme'] ?? '' ) ) || isset( $p['user'] ) || isset( $p['pass'] ) ) {
			return '';
		}
		$host = strtolower( (string) $p['host'] );
		if ( ! preg_match( '/^[a-z0-9.\-]{1,253}$/', $host ) ) {
			return '';
		}
		$path = isset( $p['path'] ) ? rtrim( (string) $p['path'], '/' ) : '';
		$port = isset( $p['port'] ) ? ':' . (int) $p['port'] : '';
		return esc_url_raw( 'https://' . $host . $port . $path, array( 'https' ) );
	}

	/**
	 * The connection details from the text of the publisher's e-mail
	 * («Πρόσβαση στο Trade Feed», Greek or English), pasted as it is:
	 * { url, user, pass }, each '' when not found. The password is the
	 * WordPress Application Password (24 letters and digits, in groups of
	 * four or not).
	 */
	public static function parse_mail( string $text ): array {
		$text = str_replace( array( "\r\n", "\r", "\xc2\xa0" ), array( "\n", "\n", ' ' ), $text );
		$text = mb_substr( $text, 0, 20000 );
		$out  = array(
			'url'  => '',
			'user' => '',
			'pass' => '',
		);
		if ( preg_match( '#https://[^\s<>"\']+/wp-json/tra/v1[^\s<>"\']*#i', $text, $m ) ) {
			$out['url'] = self::site_url( $m[0] );
		} elseif ( preg_match( '#(?:Διεύθυνση|URL)\s*:\s*(\S+)#iu', $text, $m ) ) {
			$out['url'] = self::site_url( $m[1] );
		}
		if ( preg_match( '#(?:Όνομα\s+χρήστη|Ονομα\s+χρηστη|Username|User\s*name)\s*:\s*([^\s]+)#iu', $text, $m ) ) {
			$out['user'] = mb_substr( sanitize_text_field( $m[1] ), 0, 100 );
		}
		if ( preg_match( '#(?:Κωδικός|Κωδικος|Password)\s*:\s*((?:[A-Za-z0-9]{4}\s?){6})#u', $text, $m ) ) {
			$out['pass'] = self::app_password( $m[1] );
		} elseif ( preg_match( '#\b([A-Za-z0-9]{4}(?: [A-Za-z0-9]{4}){5})\b#', $text, $m ) ) {
			$out['pass'] = self::app_password( $m[1] );
		}
		return $out;
	}

	/** An Application Password as typed or pasted: letters and digits only, '' when not 24 of them. */
	public static function app_password( string $raw ): string {
		$pw = (string) preg_replace( '/[^A-Za-z0-9]/', '', $raw );
		return 24 === strlen( $pw ) ? $pw : trim( $raw );
	}

	/** The host of the publisher's site ('' when not set): the _tfi_source marker. */
	public static function host(): string {
		$u = self::get()['url'];
		return '' === $u ? '' : strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) );
	}

	private static function term_id( int $id ): int {
		if ( $id <= 0 ) {
			return 0;
		}
		if ( did_action( 'init' ) && taxonomy_exists( 'product_cat' ) && ! term_exists( $id, 'product_cat' ) ) {
			return 0;
		}
		return $id;
	}

	/* ---------------------------------------------------------------------
	 * Password (sealed)
	 * ------------------------------------------------------------------- */

	public static function set_password( string $plain ): bool {
		$plain = trim( $plain );
		if ( '' === $plain ) {
			delete_option( self::PASS );
			return true;
		}
		$sealed = TFI_Crypt::seal( $plain );
		if ( '' === $sealed ) {
			return false;
		}
		update_option( self::PASS, $sealed, false );
		return true;
	}

	/** The password, '' when none, null when it is stored but cannot be read (salts changed). */
	public static function password(): ?string {
		$sealed = (string) get_option( self::PASS, '' );
		if ( '' === $sealed ) {
			return '';
		}
		return TFI_Crypt::unseal( $sealed );
	}

	public static function has_password(): bool {
		return '' !== (string) get_option( self::PASS, '' );
	}

	/** True when the address, the username and a readable password are set. */
	public static function connected(): bool {
		$s  = self::get();
		$pw = self::password();
		return '' !== $s['url'] && '' !== $s['user'] && is_string( $pw ) && '' !== $pw;
	}

	/* ---------------------------------------------------------------------
	 * Source categories (cache for the mapping)
	 * ------------------------------------------------------------------- */

	/** @return array<int, array{name:string, parent:int}> */
	public static function source_cats(): array {
		$raw = get_option( self::CATS, array() );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $id => $c ) {
			if ( is_numeric( $id ) && is_array( $c ) && isset( $c['name'] ) ) {
				$out[ (int) $id ] = array(
					'name'   => mb_substr( sanitize_text_field( (string) $c['name'] ), 0, 120 ),
					'parent' => isset( $c['parent'] ) ? (int) $c['parent'] : 0,
				);
			}
		}
		return $out;
	}

	public static function save_source_cats( array $items ): void {
		$out = array();
		foreach ( $items as $c ) {
			if ( is_array( $c ) && isset( $c['id'], $c['name'] ) && is_numeric( $c['id'] ) && count( $out ) < 500 ) {
				$out[ (int) $c['id'] ] = array(
					'name'   => mb_substr( sanitize_text_field( (string) $c['name'] ), 0, 120 ),
					'parent' => isset( $c['parent'] ) && is_numeric( $c['parent'] ) ? (int) $c['parent'] : 0,
				);
			}
		}
		update_option( self::CATS, $out, false );
	}
}

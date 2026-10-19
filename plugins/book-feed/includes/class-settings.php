<?php
/**
 * BKF_Settings — options, strict validation.
 *
 *  bkf_settings (autoload off)
 *    url, user        the service address and user name
 *    https            try https:// first (falls back to the address as given)
 *    status           status of products imported by hand: draft | publish
 *    subtitle         title (after ": ") | attr | none
 *    isbn             as_is | digits (how the ISBN is written to the product)
 *    sku              fill an empty SKU with the ISBN
 *    descriptions     import description and short description
 *    images           download the cover into the media library
 *    back_cover       the back cover goes to the gallery
 *    targets          field => '' (skip) | local | pa_* (global attribute)
 *                     | brand (publisher only)
 *    roles            contributor role => target (as above, no brand)
 *    cats             feed category => product_cat id
 *    default_cat      product_cat id for titles without a mapped category
 *    tax              VAT rate (string) => tax class slug ('' = standard)
 *    stock            availability text => instock | onbackorder | outofstock
 *    gone             what happens to a title that leaves the feed:
 *                     outofstock | draft
 *    sync_on / hour   the daily sync and its hour (site time zone)
 *    fields           fields kept in sync besides price and availability
 *    guard_pct        default largest discount on a fixed price, %
 *    pause            pause between pages of the feed, ms
 *    delete_products  delete the imported products on uninstall (off)
 *
 *  bkf_rules (autoload off): automatic imports per publisher, each
 *    { id, on, name, pub_id, pub_name, kind, year_from, cat, mode,
 *      url, button, max, last }. New titles always come in as drafts.
 *
 *  bkf_seen (autoload off): values met in the feed (kinds, availability,
 *    roles, categories, VAT rates, price types, forms), so the mapping
 *    tables list real values instead of guesses.
 *
 * The password: the constant BKF_PASSWORD in wp-config.php, else the
 * bkf_pass option (autoload off) sealed with the site's keys (BKF_Crypt).
 * It is never stored in plain text and never shown.
 */

defined( 'ABSPATH' ) || exit;

final class BKF_Settings {

	const OPTION = 'bkf_settings';
	const RULES  = 'bkf_rules';
	const SEEN   = 'bkf_seen';
	const PASS   = 'bkf_pass';

	const DEFAULT_URL = 'http://ws.bookpoint.gr/solr/index.php';

	/** Fields that can map to an attribute. */
	const TARGET_KEYS = array( 'publisher', 'year', 'pages', 'language', 'series', 'form', 'isbn', 'kind', 'subtitle' );

	/** Fields kept in sync besides price and availability. */
	const SYNC_FIELDS = array( 'title', 'desc', 'short', 'image', 'attrs', 'dims', 'tax' );

	/** Seen-value lists and their caps. */
	const SEEN_KEYS = array( 'kind', 'status', 'role', 'cat', 'vat', 'ptype', 'form' );
	const SEEN_MAX  = 200;

	const MAX_RULES = 50;

	private static $cache = null;

	public static function defaults(): array {
		return array(
			'url'             => self::DEFAULT_URL,
			'user'            => '',
			'https'           => true,
			'status'          => 'draft',
			'subtitle'        => 'title',
			'isbn'            => 'as_is',
			'sku'             => true,
			'descriptions'    => true,
			'images'          => true,
			'back_cover'      => false,
			'targets'         => array(
				'publisher' => 'local',
				'year'      => 'local',
				'pages'     => 'local',
				'language'  => 'local',
				'series'    => 'local',
				'form'      => 'local',
				'isbn'      => 'local',
				'kind'      => '',
				'subtitle'  => 'local',
			),
			'roles'           => array(),
			'cats'            => array(),
			'default_cat'     => 0,
			'tax'             => array(),
			'stock'           => array(),
			'gone'            => 'outofstock',
			'sync_on'         => true,
			'hour'            => 4,
			'fields'          => self::SYNC_FIELDS,
			'guard_pct'       => 10,
			'pause'           => 500,
			'delete_products' => false,
		);
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$v           = get_option( self::OPTION, array() );
			self::$cache = self::clean( array_merge( self::defaults(), is_array( $v ) ? $v : array() ) );
		}
		return self::$cache;
	}

	public static function save( array $v ): void {
		self::$cache = null;
		update_option( self::OPTION, self::clean( $v ), false );
		self::$cache = null;
	}

	/** A short single-line text. */
	public static function text( $v, int $max = 200 ): string {
		if ( ! is_scalar( $v ) ) {
			return '';
		}
		$v = trim( preg_replace( '/[\x00-\x1f\x7f]+/u', ' ', (string) $v ) );
		if ( function_exists( 'mb_substr' ) ) {
			$v = mb_substr( $v, 0, $max );
		} else {
			$v = substr( $v, 0, $max );
		}
		return $v;
	}

	/** A valid target for a field ('' | local | pa_* | brand where allowed). */
	public static function target( $v, bool $brand = false ): string {
		$v = is_string( $v ) ? $v : '';
		if ( '' === $v || 'local' === $v ) {
			return $v;
		}
		if ( $brand && 'brand' === $v ) {
			return taxonomy_exists( 'product_brand' ) ? 'brand' : 'local';
		}
		if ( 0 === strpos( $v, 'pa_' ) && preg_match( '/^pa_[a-z0-9_\-%]{1,28}$/', $v ) && wc_attribute_taxonomy_id_by_name( $v ) ) {
			return $v;
		}
		return 'local';
	}

	public static function clean( array $v ): array {
		$d   = self::defaults();
		$out = array();

		$url = isset( $v['url'] ) && is_string( $v['url'] ) ? trim( $v['url'] ) : '';
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		$out['url'] = ( '' !== $url && wp_parse_url( $url, PHP_URL_HOST ) && ! wp_parse_url( $url, PHP_URL_QUERY ) && ! wp_parse_url( $url, PHP_URL_USER ) ) ? $url : $d['url'];

		$out['user']   = self::text( $v['user'] ?? '', 100 );
		$out['https']  = ! empty( $v['https'] );
		$out['status'] = in_array( $v['status'] ?? '', array( 'draft', 'publish' ), true ) ? $v['status'] : $d['status'];
		$out['subtitle'] = in_array( $v['subtitle'] ?? '', array( 'title', 'attr', 'none' ), true ) ? $v['subtitle'] : $d['subtitle'];
		$out['isbn']     = in_array( $v['isbn'] ?? '', array( 'as_is', 'digits' ), true ) ? $v['isbn'] : $d['isbn'];
		$out['sku']          = ! empty( $v['sku'] );
		$out['descriptions'] = ! empty( $v['descriptions'] );
		$out['images']       = ! empty( $v['images'] );
		$out['back_cover']   = ! empty( $v['back_cover'] );

		$out['targets'] = array();
		$t              = isset( $v['targets'] ) && is_array( $v['targets'] ) ? $v['targets'] : array();
		foreach ( self::TARGET_KEYS as $k ) {
			$out['targets'][ $k ] = array_key_exists( $k, $t ) ? self::target( $t[ $k ], 'publisher' === $k ) : $d['targets'][ $k ];
		}

		$out['roles'] = array();
		foreach ( ( isset( $v['roles'] ) && is_array( $v['roles'] ) ? $v['roles'] : array() ) as $role => $target ) {
			$role = self::text( $role, 80 );
			if ( '' !== $role && count( $out['roles'] ) < self::SEEN_MAX ) {
				$out['roles'][ $role ] = self::target( $target );
			}
		}

		$out['cats'] = array();
		foreach ( ( isset( $v['cats'] ) && is_array( $v['cats'] ) ? $v['cats'] : array() ) as $cat => $term ) {
			$cat  = self::text( $cat, 120 );
			$term = (int) $term;
			if ( '' !== $cat && $term > 0 && term_exists( $term, 'product_cat' ) && count( $out['cats'] ) < self::SEEN_MAX ) {
				$out['cats'][ $cat ] = $term;
			}
		}
		$dc                 = (int) ( $v['default_cat'] ?? 0 );
		$out['default_cat'] = ( $dc > 0 && term_exists( $dc, 'product_cat' ) ) ? $dc : 0;

		$classes    = self::tax_classes();
		$out['tax'] = array();
		foreach ( ( isset( $v['tax'] ) && is_array( $v['tax'] ) ? $v['tax'] : array() ) as $rate => $class ) {
			$rate = self::rate_key( $rate );
			if ( '' !== $rate && is_string( $class ) && isset( $classes[ $class ] ) ) {
				$out['tax'][ $rate ] = $class;
			}
		}

		$out['stock'] = array();
		foreach ( ( isset( $v['stock'] ) && is_array( $v['stock'] ) ? $v['stock'] : array() ) as $status => $to ) {
			$status = self::text( $status, 80 );
			if ( '' !== $status && in_array( $to, array( 'instock', 'onbackorder', 'outofstock' ), true ) && count( $out['stock'] ) < self::SEEN_MAX ) {
				$out['stock'][ $status ] = $to;
			}
		}

		$out['gone']    = in_array( $v['gone'] ?? '', array( 'outofstock', 'draft' ), true ) ? $v['gone'] : $d['gone'];
		$out['sync_on'] = ! empty( $v['sync_on'] );
		$out['hour']    = max( 0, min( 23, (int) ( $v['hour'] ?? $d['hour'] ) ) );

		$f             = isset( $v['fields'] ) && is_array( $v['fields'] ) ? $v['fields'] : array();
		$out['fields'] = array_values( array_intersect( self::SYNC_FIELDS, array_map( 'strval', $f ) ) );

		$out['guard_pct']       = max( 0, min( 100, (int) ( $v['guard_pct'] ?? $d['guard_pct'] ) ) );
		$out['pause']           = max( 0, min( 5000, (int) ( $v['pause'] ?? $d['pause'] ) ) );
		$out['delete_products'] = ! empty( $v['delete_products'] );
		return $out;
	}

	/** Tax classes: slug => name ('' = standard). */
	public static function tax_classes(): array {
		$out = array( '' => __( 'Κανονικός συντελεστής', 'book-feed' ) );
		if ( class_exists( 'WC_Tax' ) ) {
			foreach ( WC_Tax::get_tax_classes() as $name ) {
				$out[ sanitize_title( $name ) ] = $name;
			}
		}
		return $out;
	}

	/** Normalised key for a VAT rate: "6", "24", "13.5". */
	public static function rate_key( $rate ): string {
		if ( ! is_scalar( $rate ) || ! is_numeric( str_replace( ',', '.', (string) $rate ) ) ) {
			return '';
		}
		$f = (float) str_replace( ',', '.', (string) $rate );
		if ( $f < 0 || $f > 100 ) {
			return '';
		}
		return rtrim( rtrim( number_format( $f, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Tax class for a VAT rate: the mapping, else the class whose rate for
	 * the store's country equals it, else null (leave the class as it is).
	 */
	public static function tax_class_for( float $vat ): ?string {
		$key = self::rate_key( $vat );
		$s   = self::get();
		if ( isset( $s['tax'][ $key ] ) ) {
			return $s['tax'][ $key ];
		}
		return self::auto_tax_class( $vat );
	}

	/** The tax class whose rate for the store's base country equals $vat, or null. */
	public static function auto_tax_class( float $vat ): ?string {
		if ( ! class_exists( 'WC_Tax' ) || ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() ) {
			return null;
		}
		$country = WC()->countries ? WC()->countries->get_base_country() : '';
		foreach ( array_keys( self::tax_classes() ) as $class ) {
			$rates = WC_Tax::find_rates(
				array(
					'country'   => $country,
					'state'     => '',
					'postcode'  => '',
					'city'      => '',
					'tax_class' => $class,
				)
			);
			foreach ( $rates as $r ) {
				if ( isset( $r['rate'] ) && abs( (float) $r['rate'] - $vat ) < 0.001 ) {
					return $class;
				}
			}
		}
		return null;
	}

	/** Stock status for an availability text: the mapping, else a guess only for the text the guide shows. */
	public static function stock_for( string $status ): string {
		$s = self::get();
		if ( isset( $s['stock'][ $status ] ) ) {
			return $s['stock'][ $status ];
		}
		// «Κυκλοφορεί» is the one value the service guide shows. Anything
		// unknown is not offered for sale until it is mapped.
		return 'Κυκλοφορεί' === $status ? 'instock' : 'outofstock';
	}

	/** Target of a contributor role (unmapped roles: local attribute). */
	public static function role_target( string $role ): string {
		$s = self::get();
		return isset( $s['roles'][ $role ] ) ? $s['roles'][ $role ] : 'local';
	}

	/* ---------------------------------------------------------------------
	 * Rules
	 * ------------------------------------------------------------------- */

	public static function rules(): array {
		$v   = get_option( self::RULES, array() );
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $r ) {
			$r = is_array( $r ) ? self::clean_rule( $r ) : null;
			if ( $r ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	public static function save_rules( array $rules ): void {
		$out = array();
		foreach ( $rules as $r ) {
			$r = is_array( $r ) ? self::clean_rule( $r ) : null;
			if ( $r && count( $out ) < self::MAX_RULES ) {
				$out[] = $r;
			}
		}
		update_option( self::RULES, $out, false );
	}

	public static function clean_rule( array $r ): ?array {
		$pub = self::text( $r['pub_id'] ?? '', 40 );
		if ( '' === $pub || ! preg_match( '/^[A-Za-z0-9_\-]+$/', $pub ) ) {
			return null;
		}
		$id   = isset( $r['id'] ) && is_string( $r['id'] ) && preg_match( '/^[a-f0-9]{8}$/', $r['id'] ) ? $r['id'] : substr( md5( uniqid( '', true ) ), 0, 8 );
		$cat  = (int) ( $r['cat'] ?? 0 );
		$mode = in_array( $r['mode'] ?? '', array( 'sell', 'external' ), true ) ? $r['mode'] : 'sell';
		$url  = self::text( $r['url'] ?? '', 300 );
		if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
			$url = '';
		}
		if ( 'external' === $mode && '' === $url ) {
			$mode = 'sell';
		}
		return array(
			'id'        => $id,
			'on'        => ! empty( $r['on'] ),
			'name'      => self::text( $r['name'] ?? '', 100 ),
			'pub_id'    => $pub,
			'pub_name'  => self::text( $r['pub_name'] ?? '', 150 ),
			'kind'      => self::text( $r['kind'] ?? '', 60 ),
			'year_from' => max( 0, min( 2100, (int) ( $r['year_from'] ?? 0 ) ) ),
			'cat'       => ( $cat > 0 && term_exists( $cat, 'product_cat' ) ) ? $cat : 0,
			'mode'      => $mode,
			'url'       => $url,
			'button'    => self::text( $r['button'] ?? '', 60 ),
			'max'       => max( 1, min( 500, (int) ( $r['max'] ?? 50 ) ) ),
			'last'      => max( 0, (int) ( $r['last'] ?? 0 ) ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Values seen in the feed
	 * ------------------------------------------------------------------- */

	public static function seen(): array {
		$v   = get_option( self::SEEN, array() );
		$v   = is_array( $v ) ? $v : array();
		$out = array();
		foreach ( self::SEEN_KEYS as $k ) {
			$list      = isset( $v[ $k ] ) && is_array( $v[ $k ] ) ? $v[ $k ] : array();
			$out[ $k ] = array_slice( array_values( array_unique( array_filter( array_map( array( __CLASS__, 'seen_text' ), $list ), 'strlen' ) ) ), 0, self::SEEN_MAX );
		}
		return $out;
	}

	private static function seen_text( $v ): string {
		return self::text( $v, 120 );
	}

	/** Add values met in the feed (admin, cron and CLI only). */
	public static function note_seen( array $values ): void {
		$seen    = self::seen();
		$changed = false;
		foreach ( $values as $k => $list ) {
			if ( ! isset( $seen[ $k ] ) ) {
				continue;
			}
			foreach ( (array) $list as $item ) {
				$item = self::text( $item, 120 );
				if ( '' !== $item && ! in_array( $item, $seen[ $k ], true ) && count( $seen[ $k ] ) < self::SEEN_MAX ) {
					$seen[ $k ][] = $item;
					$changed      = true;
				}
			}
		}
		if ( $changed ) {
			foreach ( $seen as $k => $list ) {
				sort( $seen[ $k ], SORT_STRING );
			}
			update_option( self::SEEN, $seen, false );
		}
	}

	/* ---------------------------------------------------------------------
	 * Password
	 * ------------------------------------------------------------------- */

	/** Where the password comes from: const | option | unreadable | none. */
	public static function password_source(): string {
		if ( defined( 'BKF_PASSWORD' ) && is_string( BKF_PASSWORD ) && '' !== BKF_PASSWORD ) {
			return 'const';
		}
		$v = get_option( self::PASS, '' );
		if ( ! is_string( $v ) || '' === $v ) {
			return 'none';
		}
		return null === BKF_Crypt::unseal( $v ) ? 'unreadable' : 'option';
	}

	/** The password, or null when there is none to read. */
	public static function password(): ?string {
		if ( defined( 'BKF_PASSWORD' ) && is_string( BKF_PASSWORD ) && '' !== BKF_PASSWORD ) {
			return BKF_PASSWORD;
		}
		$v = get_option( self::PASS, '' );
		return is_string( $v ) && '' !== $v ? BKF_Crypt::unseal( $v ) : null;
	}

	/** Store a password ('' removes it). False when it cannot be sealed. */
	public static function set_password( string $pw ): bool {
		if ( '' === $pw ) {
			delete_option( self::PASS );
			return true;
		}
		$sealed = BKF_Crypt::seal( $pw );
		if ( '' === $sealed ) {
			return false;
		}
		update_option( self::PASS, $sealed, false );
		return true;
	}

	/** True when user and password are there. */
	public static function ready(): bool {
		$s = self::get();
		return '' !== $s['user'] && null !== self::password() && '' !== (string) self::password();
	}

	/** Global product attributes: taxonomy => label. */
	public static function attributes(): array {
		$out = array();
		foreach ( wc_get_attribute_taxonomies() as $a ) {
			$out[ wc_attribute_taxonomy_name( $a->attribute_name ) ] = $a->attribute_label;
		}
		return $out;
	}
}

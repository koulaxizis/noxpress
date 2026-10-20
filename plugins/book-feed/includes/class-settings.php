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
 *    pub_cover        take the cover from the publisher's page when it
 *                     is larger than the catalogue's (1.4.0)
 *    targets          field => '' (skip) | local | pa_* (global attribute)
 *                     | brand (publisher only); genre = the feed's
 *                     categories as attribute values
 *    roles            contributor role => target (as above, no brand)
 *    order            attribute names (as on the product) in the order
 *                     they should appear; the others follow
 *    rename           feed value => store value ('' drops the value), for
 *                     publisher, form, kind, genre, language, series, tags
 *    cats             feed category => product_cat id
 *    cat_parents      also add the parents of the mapped categories
 *    default_cat      product_cat id for titles without a mapped category
 *    pub_cat          product_cat id under which each publisher gets a
 *                     subcategory (0 = off, 1.5.0; BKF_Importer)
 *    tags             '' | class (subjects and keywords) | cats: product
 *                     tags on import, at most tags_max
 *    img_alt          cover alt text template, img_name file name template:
 *                     {title} {subtitle} {authors} {publisher} {isbn}
 *    pub_prices       pub_id => { name, url }: the book's page on the
 *                     publisher's site ({isbn}, {isbn13}, {title}); its
 *                     price replaces the catalogue's (BKF_Pub_Price)
 *    pub_labels       words that stand before the publisher's price in a
 *                     page's text ("Τιμή εκδότη"), read when the page has
 *                     no structured price
 *    names            as_is | first_last: "Norbury, James" → "James Norbury"
 *    tax              VAT rate (string) => tax class slug ('' = standard)
 *    stock            availability text => instock | onbackorder | outofstock
 *    gone             what happens to a title that leaves the feed:
 *                     outofstock | draft
 *    sync_on / hour   the daily sync and its hour (site time zone)
 *    fields           fields kept in sync besides price and availability
 *    guard_pct        default largest discount on a fixed price, %
 *    pause            pause between pages of the feed, ms
 *    bulk_max         most titles one "import all results" goes through
 *                     (100–5000, 1.7.0; BKF_Bulk)
 *    notify / notify_to  a summary email when rules bring new titles
 *                     (on; '' = the site's admin address, 1.7.0)
 *    delete_products  delete the imported products on uninstall (off)
 *
 *  bkf_rules (autoload off): automatic imports, each
 *    { id, on, name, pub_id, pub_name, title, author, kind, pstat,
 *      year_from, year_to, cat, mode, url, button, max, every, status,
 *      last }. pstat = one publishing status, e.g. «Κυκλοφορεί» (1.8.0).
 *    A rule needs a publisher, title words or a contributor (1.7.0: any
 *    saved search). every = days between runs (1–90; rules saved before
 *    1.7.0 have none and keep running daily); status = draft | publish
 *    for the new titles (before 1.7.0 always draft).
 *
 *  bkf_seen (autoload off): values met in the feed (kinds, availability,
 *    roles, categories, VAT rates, price types, forms, subjects), so the mapping
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

	const DEFAULT_URL = 'https://ws.bookpoint.gr/solr/index.php';

	/** Fields that can map to an attribute. */
	const TARGET_KEYS = array( 'publisher', 'year', 'pages', 'language', 'series', 'form', 'isbn', 'kind', 'genre', 'subtitle' );

	/** Placeholders of the image templates. */
	const IMG_TAGS = array( '{title}', '{subtitle}', '{authors}', '{publisher}', '{isbn}' );

	const MAX_ORDER  = 40;
	const MAX_PUB    = 100;
	const MAX_RENAME = 200;
	const MAX_LABELS = 10;

	/** Fields kept in sync besides price and availability. */
	const SYNC_FIELDS = array( 'title', 'desc', 'short', 'image', 'attrs', 'dims', 'tax' );

	/** Seen-value lists and their caps. */
	const SEEN_KEYS = array( 'kind', 'status', 'role', 'cat', 'vat', 'ptype', 'form', 'class' );
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
			'pub_cover'       => true,
			'targets'         => array(
				'publisher' => 'local',
				'year'      => 'local',
				'pages'     => 'local',
				'language'  => 'local',
				'series'    => 'local',
				'form'      => 'local',
				'isbn'      => 'local',
				'kind'      => '',
				'genre'     => '',
				'subtitle'  => 'local',
			),
			'roles'           => array(),
			'order'           => array(),
			'rename'          => array(),
			'cats'            => array(),
			'cat_parents'     => false,
			'default_cat'     => 0,
			'pub_cat'         => 0,
			'tags'            => '',
			'tags_max'        => 10,
			'img_alt'         => '{title}',
			'img_name'        => '{title}-{isbn}',
			'pub_prices'      => array(),
			'pub_labels'      => array( 'Τιμή εκδότη' ),
			'names'           => 'as_is',
			'tax'             => array(),
			'stock'           => array(),
			'gone'            => 'outofstock',
			'sync_on'         => true,
			'hour'            => 4,
			'fields'          => self::SYNC_FIELDS,
			'guard_pct'       => 10,
			'pause'           => 500,
			'bulk_max'        => 1000,
			'notify'          => true,
			'notify_to'       => '',
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
		$out['pub_cover']    = ! empty( $v['pub_cover'] );

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

		$out['order'] = array();
		foreach ( ( isset( $v['order'] ) && is_array( $v['order'] ) ? $v['order'] : array() ) as $name ) {
			$name = self::text( $name, 100 );
			if ( '' !== $name && ! in_array( $name, $out['order'], true ) && count( $out['order'] ) < self::MAX_ORDER ) {
				$out['order'][] = $name;
			}
		}

		$out['rename'] = array();
		foreach ( ( isset( $v['rename'] ) && is_array( $v['rename'] ) ? $v['rename'] : array() ) as $from => $to ) {
			$from = self::text( $from, 150 );
			if ( '' !== $from && count( $out['rename'] ) < self::MAX_RENAME ) {
				$out['rename'][ $from ] = self::text( $to, 150 );
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
		$out['cat_parents'] = ! empty( $v['cat_parents'] );
		$pc                 = (int) ( $v['pub_cat'] ?? 0 );
		$out['pub_cat']     = ( $pc > 0 && term_exists( $pc, 'product_cat' ) ) ? $pc : 0;
		$out['tags']        = in_array( $v['tags'] ?? '', array( '', 'class', 'cats' ), true ) ? $v['tags'] : $d['tags'];
		$out['tags_max']    = max( 1, min( 50, (int) ( $v['tags_max'] ?? $d['tags_max'] ) ) );
		$out['pub_prices'] = array();
		foreach ( ( isset( $v['pub_prices'] ) && is_array( $v['pub_prices'] ) ? $v['pub_prices'] : array() ) as $pub => $row ) {
			$pub = self::text( $pub, 40 );
			$url = is_array( $row ) ? self::text( $row['url'] ?? '', 300 ) : '';
			if ( '' === $pub || ! preg_match( '/^[A-Za-z0-9_\-]+$/', $pub ) || ! self::pub_url_ok( $url ) || count( $out['pub_prices'] ) >= self::MAX_PUB ) {
				continue;
			}
			$out['pub_prices'][ $pub ] = array(
				'name' => self::text( $row['name'] ?? '', 150 ),
				'url'  => $url,
			);
		}
		$out['pub_labels'] = array();
		foreach ( ( isset( $v['pub_labels'] ) && is_array( $v['pub_labels'] ) ? $v['pub_labels'] : $d['pub_labels'] ) as $label ) {
			$label = self::text( $label, 60 );
			if ( '' !== $label && ! in_array( $label, $out['pub_labels'], true ) && count( $out['pub_labels'] ) < self::MAX_LABELS ) {
				$out['pub_labels'][] = $label;
			}
		}
		$out['names'] = in_array( $v['names'] ?? '', array( 'as_is', 'first_last' ), true ) ? $v['names'] : $d['names'];
		foreach ( array( 'img_alt', 'img_name' ) as $k ) {
			$t         = self::text( $v[ $k ] ?? '', 200 );
			$out[ $k ] = '' !== $t ? $t : $d[ $k ];
		}

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
		$out['bulk_max']        = max( 100, min( 5000, (int) ( $v['bulk_max'] ?? $d['bulk_max'] ) ) );
		$out['notify']          = ! empty( $v['notify'] );
		$to                     = sanitize_email( (string) ( $v['notify_to'] ?? '' ) );
		$out['notify_to']       = is_email( $to ) ? $to : '';
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

	/** A publisher page template: http(s), a host, and {isbn} or {isbn13} in it. */
	public static function pub_url_ok( string $url ): bool {
		return (bool) preg_match( '#^https?://[^/\s{}]+#i', $url ) && ( false !== strpos( $url, '{isbn}' ) || false !== strpos( $url, '{isbn13}' ) );
	}

	/** A feed value as the store writes it: the rename table, else as is ('' = drop). */
	/** A page address for one product: http(s), no placeholders needed. */
	public static function page_url_ok( string $url ): bool {
		return strlen( $url ) <= 300 && (bool) preg_match( '#^https?://[^/\s{}]+#i', $url );
	}

	/**
	 * A contributor's name as the store writes it: "Norbury, James" becomes
	 * "James Norbury" when the settings say so (names: first_last).
	 * Only a single comma between two parts is turned.
	 */
	public static function person( string $name ): string {
		if ( 'first_last' !== self::get()['names'] || ! preg_match( '/^([^,]+),\s*([^,]+)$/u', $name, $m ) ) {
			return $name;
		}
		return trim( $m[2] ) . ' ' . trim( $m[1] );
	}

	public static function renamed( string $v ): string {
		$s = self::get();
		return array_key_exists( $v, $s['rename'] ) ? $s['rename'][ $v ] : $v;
	}

	/** The rename table as text, one "from = to" per line. */
	public static function rename_text( array $map ): string {
		$out = array();
		foreach ( $map as $from => $to ) {
			$out[] = $from . ' = ' . $to;
		}
		return implode( "\n", $out );
	}

	/** Rename table from text: "from = to" per line ("from =" drops the value). */
	public static function parse_rename( string $text ): array {
		$out = array();
		foreach ( preg_split( '/\R/u', $text ) as $line ) {
			$pos = strpos( $line, '=' );
			if ( false === $pos ) {
				continue;
			}
			$from = trim( substr( $line, 0, $pos ) );
			if ( '' !== $from ) {
				$out[ $from ] = trim( substr( $line, $pos + 1 ) );
			}
		}
		return $out;
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
		$pub    = self::text( $r['pub_id'] ?? '', 40 );
		$title  = self::text( $r['title'] ?? '', 200 );
		$author = self::text( $r['author'] ?? '', 200 );
		if ( '' !== $pub && ! preg_match( '/^[A-Za-z0-9_\-]+$/', $pub ) ) {
			return null;
		}
		// Something to narrow the feed: never "every title of the catalogue".
		if ( '' === $pub . $title . $author ) {
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
			'title'     => $title,
			'author'    => $author,
			'kind'      => self::text( $r['kind'] ?? '', 60 ),
			'pstat'     => self::text( $r['pstat'] ?? '', 80 ),
			'year_from' => max( 0, min( 2100, (int) ( $r['year_from'] ?? 0 ) ) ),
			'year_to'   => max( 0, min( 2100, (int) ( $r['year_to'] ?? 0 ) ) ),
			'cat'       => ( $cat > 0 && term_exists( $cat, 'product_cat' ) ) ? $cat : 0,
			'mode'      => $mode,
			'url'       => $url,
			'button'    => self::text( $r['button'] ?? '', 60 ),
			'max'       => max( 1, min( 500, (int) ( $r['max'] ?? 50 ) ) ),
			// Rules from before 1.7.0 have no frequency: they keep running daily.
			'every'     => max( 1, min( 90, (int) ( $r['every'] ?? 1 ) ) ),
			'status'    => in_array( $r['status'] ?? '', array( 'draft', 'publish' ), true ) ? $r['status'] : 'draft',
			'last'      => max( 0, (int) ( $r['last'] ?? 0 ) ),
		);
	}

	/** True when a rule should run in a job that starts now. */
	public static function rule_due( array $r ): bool {
		// Six hours of slack: a daily run at 04:00 is not missed because
		// the last one finished at 04:20.
		return $r['on'] && ( ! $r['last'] || time() - $r['last'] >= $r['every'] * DAY_IN_SECONDS - 6 * HOUR_IN_SECONDS );
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

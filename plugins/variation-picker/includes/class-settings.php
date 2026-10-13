<?php
/**
 * VPK_Settings — the vpk_settings and vpk_guides options, strict
 * validation with whitelists (Bible §11).
 *
 * vpk_settings (autoload: read on every product page):
 *  - mode            'off' (default) | 'test' (shop managers only) | 'live'
 *  - auto            attributes without a type: 'button' (default) | 'select'
 *  - shape / size / font / border / border_color / active_color   look
 *  - unavailable     combinations that do not exist: 'blur' | 'cross' | 'hide'
 *  - oos             combinations out of stock: 'blur' | 'cross' | 'hide'
 *  - selected_label  "Colour: Black" next to the attribute name
 *  - tooltip         'none' | 'text' | 'image'
 *  - auto_select     pick the only possible value of an attribute
 *  - deselect        a second click clears the value
 *  - url_sync        the chosen values go into the page URL (shareable link)
 *  - price_diff      "+3 €" in the tooltip when a value costs more
 *  - low_stock       "Only N left" in the tooltip at or below N (0 = off)
 *  - cart_dot        colour dot next to the colour in the cart / mini-cart
 *  - shop            { enabled, attr, position, limit, size, swap_image, preselect }
 *  - attrs           taxonomy => { type, shape, size }
 *  - lang, t_*       visitor texts (Bible §14.17)
 *  - delete_data     delete term colours / images and product overrides on uninstall
 *
 * vpk_guides: size guides, list of { id, active, title_el, title_en, attr,
 * cats, table_el, table_en, note_el, note_en, image }.
 */

defined( 'ABSPATH' ) || exit;

final class VPK_Settings {

	const OPT        = 'vpk_settings';
	const OPT_GUIDES = 'vpk_guides';
	const MAX_GUIDES = 20;

	/** Swatch types (per attribute and per product). */
	const TYPES = array( 'button', 'color', 'image', 'variation_image', 'radio', 'select' );

	/** Text keys => max length. */
	const TEXTS = array(
		't_guide'   => 60,
		't_low'     => 80,
		't_close'   => 40,
		't_unavail' => 60,
		't_oos'     => 60,
	);

	/** @var array|null Per-request caches. */
	private static $cache  = null;
	private static $guides = null;

	public static function defaults(): array {
		return array(
			'mode'           => 'off',
			'auto'           => 'button',
			'shape'          => 'rounded',
			'size'           => 32,
			'font'           => 13,
			'border'         => 1,
			'border_color'   => '#c8c8c8',
			'active_color'   => '#111111',
			'unavailable'    => 'blur',
			'oos'            => 'blur',
			'selected_label' => true,
			'tooltip'        => 'text',
			'auto_select'    => false,
			'deselect'       => true,
			'url_sync'       => true,
			'price_diff'     => false,
			'low_stock'      => 0,
			'cart_dot'       => false,
			'shop'           => array(
				'enabled'    => false,
				'attr'       => '',
				'position'   => 'after_price',
				'limit'      => 5,
				'size'       => 22,
				'swap_image' => true,
				'preselect'  => true,
			),
			'attrs'          => array(),
			'lang'           => 'auto',
			't_guide'        => array(
				'el' => 'Οδηγός μεγεθών',
				'en' => 'Size guide',
			),
			't_low'          => array(
				'el' => 'Μόνο %d διαθέσιμα',
				'en' => 'Only %d left',
			),
			't_close'        => array(
				'el' => 'Κλείσιμο',
				'en' => 'Close',
			),
			't_unavail'      => array(
				'el' => 'Μη διαθέσιμο',
				'en' => 'Unavailable',
			),
			't_oos'          => array(
				'el' => 'Εξαντλημένο',
				'en' => 'Out of stock',
			),
			'delete_data'    => false,
		);
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPT, array() );
			self::$cache = self::sanitize( is_array( $raw ) ? $raw : array() );
		}
		return self::$cache;
	}

	public static function save( array $s ): void {
		$s           = self::sanitize( $s );
		self::$cache = $s;
		update_option( self::OPT, $s, true );
	}

	/** Drops the per-request caches (tests, imports). */
	public static function flush(): void {
		self::$cache  = null;
		self::$guides = null;
	}

	/** Whitelist validation: unknown keys are dropped, bad values fall back to the defaults. */
	public static function sanitize( array $raw ): array {
		$d = self::defaults();
		$s = array();

		$s['mode']           = self::pick( $raw, 'mode', array( 'off', 'test', 'live' ), $d['mode'] );
		$s['auto']           = self::pick( $raw, 'auto', array( 'button', 'select' ), $d['auto'] );
		$s['shape']          = self::pick( $raw, 'shape', array( 'square', 'rounded', 'circle' ), $d['shape'] );
		$s['size']           = self::int( $raw, 'size', 20, 80, $d['size'] );
		$s['font']           = self::int( $raw, 'font', 10, 20, $d['font'] );
		$s['border']         = self::int( $raw, 'border', 0, 4, $d['border'] );
		$s['border_color']   = self::color( $raw, 'border_color', $d['border_color'] );
		$s['active_color']   = self::color( $raw, 'active_color', $d['active_color'] );
		$s['unavailable']    = self::pick( $raw, 'unavailable', array( 'blur', 'cross', 'hide' ), $d['unavailable'] );
		$s['oos']            = self::pick( $raw, 'oos', array( 'blur', 'cross', 'hide' ), $d['oos'] );
		$s['tooltip']        = self::pick( $raw, 'tooltip', array( 'none', 'text', 'image' ), $d['tooltip'] );
		$s['low_stock']      = self::int( $raw, 'low_stock', 0, 100, $d['low_stock'] );
		$s['lang']           = self::pick( $raw, 'lang', array( 'auto', 'el', 'en' ), $d['lang'] );
		foreach ( array( 'selected_label', 'auto_select', 'deselect', 'url_sync', 'price_diff', 'cart_dot', 'delete_data' ) as $k ) {
			$s[ $k ] = isset( $raw[ $k ] ) ? (bool) $raw[ $k ] : $d[ $k ];
		}

		$shop      = isset( $raw['shop'] ) && is_array( $raw['shop'] ) ? $raw['shop'] : array();
		$s['shop'] = array(
			'enabled'    => isset( $shop['enabled'] ) ? (bool) $shop['enabled'] : $d['shop']['enabled'],
			'attr'       => isset( $shop['attr'] ) && is_string( $shop['attr'] ) && self::valid_taxonomy_name( $shop['attr'] ) ? $shop['attr'] : '',
			'position'   => self::pick( $shop, 'position', array( 'after_title', 'after_price', 'after_button' ), $d['shop']['position'] ),
			'limit'      => self::int( $shop, 'limit', 0, 20, $d['shop']['limit'] ),
			'size'       => self::int( $shop, 'size', 14, 48, $d['shop']['size'] ),
			'swap_image' => isset( $shop['swap_image'] ) ? (bool) $shop['swap_image'] : $d['shop']['swap_image'],
			'preselect'  => isset( $shop['preselect'] ) ? (bool) $shop['preselect'] : $d['shop']['preselect'],
		);

		$s['attrs'] = array();
		if ( isset( $raw['attrs'] ) && is_array( $raw['attrs'] ) ) {
			foreach ( $raw['attrs'] as $tax => $row ) {
				if ( ! is_string( $tax ) || ! self::valid_taxonomy_name( $tax ) || ! is_array( $row ) ) {
					continue;
				}
				$a = self::attr_row( $row );
				if ( '' !== $a['type'] || '' !== $a['shape'] || $a['size'] ) {
					$s['attrs'][ $tax ] = $a;
				}
				if ( count( $s['attrs'] ) >= 200 ) {
					break;
				}
			}
		}

		foreach ( self::TEXTS as $key => $len ) {
			$s[ $key ] = $d[ $key ];
			if ( isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ) {
				foreach ( array( 'el', 'en' ) as $l ) {
					$v = isset( $raw[ $key ][ $l ] ) && is_string( $raw[ $key ][ $l ] ) ? self::line( $raw[ $key ][ $l ], $len ) : '';
					// Empty Greek = the default; empty English = shows the Greek.
					if ( 'el' === $l && '' === $v ) {
						continue;
					}
					$s[ $key ][ $l ] = $v;
				}
			}
		}

		return $s;
	}

	/**
	 * One attribute row: { type, shape, size } ('' / 0 = the general setting).
	 *
	 * @param array $row Raw row.
	 */
	public static function attr_row( array $row ): array {
		$type  = isset( $row['type'] ) && is_string( $row['type'] ) && in_array( $row['type'], self::TYPES, true ) ? $row['type'] : '';
		$shape = isset( $row['shape'] ) && is_string( $row['shape'] ) && in_array( $row['shape'], array( 'square', 'rounded', 'circle' ), true ) ? $row['shape'] : '';
		$size  = isset( $row['size'] ) && is_numeric( $row['size'] ) ? (int) $row['size'] : 0;
		$size  = ( $size >= 20 && $size <= 80 ) ? $size : 0;
		return array(
			'type'  => $type,
			'shape' => $shape,
			'size'  => $size,
		);
	}

	/** A WooCommerce attribute taxonomy name (pa_…). */
	public static function valid_taxonomy_name( string $tax ): bool {
		// wc_sanitize_taxonomy_name() keeps non-Latin letters (e.g. pa_χρώμα).
		return 1 === preg_match( '/^pa_[\p{Ll}\p{Lo}\p{N}_\-%]{1,96}$/u', $tax );
	}

	/** One line of plain text, at most $len characters. */
	public static function line( string $v, int $len ): string {
		return mb_substr( trim( sanitize_text_field( $v ) ), 0, $len );
	}

	/** A #rrggbb colour or ''. */
	public static function hex( $v ): string {
		if ( ! is_string( $v ) ) {
			return '';
		}
		$v = strtolower( trim( $v ) );
		if ( 1 === preg_match( '/^#[0-9a-f]{3}$/', $v ) ) {
			$v = '#' . $v[1] . $v[1] . $v[2] . $v[2] . $v[3] . $v[3];
		}
		return 1 === preg_match( '/^#[0-9a-f]{6}$/', $v ) ? $v : '';
	}

	private static function color( array $raw, string $key, string $default ): string {
		$v = isset( $raw[ $key ] ) ? self::hex( $raw[ $key ] ) : '';
		return '' !== $v ? $v : $default;
	}

	/** One of the allowed string values, else the default. */
	private static function pick( array $raw, string $key, array $allowed, string $default ): string {
		return isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && in_array( $raw[ $key ], $allowed, true ) ? $raw[ $key ] : $default;
	}

	/** An integer in [min, max], else the default. */
	private static function int( array $raw, string $key, int $min, int $max, int $default ): int {
		if ( ! isset( $raw[ $key ] ) || ! is_numeric( $raw[ $key ] ) ) {
			return $default;
		}
		$v = (int) $raw[ $key ];
		return ( $v >= $min && $v <= $max ) ? $v : $default;
	}

	/* =====================================================================
	 * Size guides
	 * =================================================================== */

	public static function guides(): array {
		if ( null === self::$guides ) {
			$raw          = get_option( self::OPT_GUIDES, array() );
			self::$guides = self::sanitize_guides( is_array( $raw ) ? $raw : array() );
		}
		return self::$guides;
	}

	public static function save_guides( array $guides ): void {
		$guides       = self::sanitize_guides( $guides );
		self::$guides = $guides;
		update_option( self::OPT_GUIDES, $guides, true );
	}

	public static function sanitize_guides( array $raw ): array {
		$out  = array();
		$seen = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$g = self::guide_row( $row );
			if ( null === $g || isset( $seen[ $g['id'] ] ) ) {
				continue;
			}
			$seen[ $g['id'] ] = true;
			$out[]            = $g;
			if ( count( $out ) >= self::MAX_GUIDES ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * One validated guide, or null without an id or a title.
	 *
	 * @param array $row Raw guide.
	 */
	private static function guide_row( array $row ): ?array {
		$str = static function ( string $k ) use ( $row ): string {
			return isset( $row[ $k ] ) && is_string( $row[ $k ] ) ? $row[ $k ] : '';
		};
		$id = $str( 'id' );
		if ( ! self::valid_id( $id ) ) {
			return null;
		}
		$g = array(
			'id'       => $id,
			'active'   => ! empty( $row['active'] ),
			'title_el' => self::line( $str( 'title_el' ), 100 ),
			'title_en' => self::line( $str( 'title_en' ), 100 ),
			'attr'     => self::valid_taxonomy_name( $str( 'attr' ) ) ? $str( 'attr' ) : '',
			'cats'     => array(),
			'table_el' => self::table( $str( 'table_el' ) ),
			'table_en' => self::table( $str( 'table_en' ) ),
			'note_el'  => self::line( $str( 'note_el' ), 300 ),
			'note_en'  => self::line( $str( 'note_en' ), 300 ),
			'image'    => isset( $row['image'] ) && is_numeric( $row['image'] ) ? max( 0, (int) $row['image'] ) : 0,
		);
		if ( isset( $row['cats'] ) && is_array( $row['cats'] ) ) {
			foreach ( $row['cats'] as $c ) {
				if ( is_numeric( $c ) && (int) $c > 0 ) {
					$g['cats'][] = (int) $c;
				}
			}
			$g['cats'] = array_slice( array_values( array_unique( $g['cats'] ) ), 0, 50 );
		}
		if ( '' === $g['title_el'] ) {
			return null;
		}
		return $g;
	}

	/**
	 * A size table as text: up to 30 rows (one per line), up to 12 cells
	 * per row separated by "|", each cell plain text.
	 *
	 * @param string $v Raw text.
	 */
	public static function table( string $v ): string {
		$rows = array();
		foreach ( preg_split( '/\R/u', $v ) as $line ) {
			$cells = array();
			foreach ( array_slice( explode( '|', $line ), 0, 12 ) as $c ) {
				$cells[] = mb_substr( trim( sanitize_text_field( $c ) ), 0, 60 );
			}
			if ( '' === implode( '', $cells ) ) {
				continue;
			}
			$rows[] = implode( ' | ', $cells );
			if ( count( $rows ) >= 30 ) {
				break;
			}
		}
		return implode( "\n", $rows );
	}

	/** Parsed table: array of rows, each an array of cells (first row = header). */
	public static function table_rows( string $table ): array {
		$out = array();
		if ( '' === $table ) {
			return $out;
		}
		foreach ( explode( "\n", $table ) as $line ) {
			$out[] = array_map( 'trim', explode( '|', $line ) );
		}
		return $out;
	}

	public static function valid_id( string $id ): bool {
		return 1 === preg_match( '/^[a-z0-9]{4,16}$/', $id );
	}

	/** A new guide id (8 hex characters). */
	public static function new_id(): string {
		return bin2hex( random_bytes( 4 ) );
	}

	/* =====================================================================
	 * Readers
	 * =================================================================== */

	/**
	 * The effective mode: 'off' | 'test' | 'live'. Another swatches plugin
	 * active turns 'live' into 'test', so visitors never see two sets of
	 * swatches (VPK_Compat).
	 */
	public static function mode(): string {
		$mode = self::get()['mode'];
		if ( 'live' === $mode && class_exists( 'VPK_Compat' ) && VPK_Compat::other_active() ) {
			return 'test';
		}
		return $mode;
	}

	/** True when the current visitor may see the swatches (mode + test mode). */
	public static function visible(): bool {
		$mode = self::mode();
		if ( 'live' === $mode ) {
			return true;
		}
		return 'test' === $mode && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * The settings of one attribute taxonomy: { type, shape, size } with
	 * '' / 0 meaning "the general setting".
	 *
	 * @param string $tax Taxonomy name (pa_…).
	 */
	public static function attr( string $tax ): array {
		$attrs = self::get()['attrs'];
		return isset( $attrs[ $tax ] ) ? $attrs[ $tax ] : array(
			'type'  => '',
			'shape' => '',
			'size'  => 0,
		);
	}

	/** Visitor language for the texts: 'el' | 'en' (Bible §14.17). */
	public static function lang(): string {
		$choice = self::get()['lang'];
		if ( 'el' === $choice || 'en' === $choice ) {
			return $choice;
		}
		return 0 === strpos( strtolower( (string) get_locale() ), 'el' ) ? 'el' : 'en';
	}

	/**
	 * A visitor text in the visitor language (empty English → Greek).
	 *
	 * @param string $key A key of TEXTS.
	 */
	public static function text( string $key ): string {
		$s    = self::get();
		$lang = self::lang();
		$pair = isset( $s[ $key ] ) && is_array( $s[ $key ] ) ? $s[ $key ] : array(
			'el' => '',
			'en' => '',
		);
		$text = ( 'en' === $lang && '' !== $pair['en'] ) ? $pair['en'] : $pair['el'];
		/**
		 * Filters a visitor text of Variation Picker (e.g. for WPML).
		 *
		 * @param string $text Text.
		 * @param string $key  Text key (t_guide, t_low, t_close, t_unavail, t_oos).
		 * @param string $lang 'el' | 'en'.
		 */
		return (string) apply_filters( 'vpk_text', $text, $key, $lang );
	}

	/**
	 * A guide field in the visitor language (empty English → Greek).
	 *
	 * @param array  $g     Guide.
	 * @param string $field 'title' | 'table' | 'note'.
	 */
	public static function guide_text( array $g, string $field ): string {
		$el = isset( $g[ $field . '_el' ] ) ? (string) $g[ $field . '_el' ] : '';
		$en = isset( $g[ $field . '_en' ] ) ? (string) $g[ $field . '_en' ] : '';
		return ( 'en' === self::lang() && '' !== $en ) ? $en : $el;
	}
}

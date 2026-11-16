<?php
/**
 * PFM_Book — book details (1.3.0): schema.org properties and the
 * "Publication details" table, from attributes the shop already has.
 *
 * Settings (option pfm_book, autoload): which product attribute holds the
 * pages, year, language, edition, series and binding; the words that mark
 * a hardcover; schema on / off; the table (on / off, position, title).
 * Nothing changes on the front end until a switch is on.
 *
 * Schema (Bible §14.38): the Product node is never printed here. The
 * properties go into the node the page already has:
 *  - woocommerce_structured_data_product (priority 30, after Featured
 *    Creators at 20): WooCommerce's own markup, and Search Signals,
 *    whose Product node is built by WooCommerce;
 *  - rank_math/json_ld (priority 100, after Featured Creators at 99).
 * A node whose type has Book or Audiobook (Featured Creators maps it from
 * the category) gets the properties. With "add_type" on, a product of
 * format print / ebook becomes [ "Product", "Book" ] and an audiobook
 * [ "Product", "Audiobook" ] when no other work type is there.
 * Properties: numberOfPages, datePublished, inLanguage, bookEdition,
 * isPartOf (BookSeries), bookFormat (from the format and the binding),
 * isbn (from the GTIN field) and workExample (the other formats of the
 * work a visitor may see). Filter pfm_book_schema( $node, $product ).
 *
 * Table: shortcode [nox_book_details] (or id="123"), or automatic below
 * the product meta / above the tabs (classic hooks, Astra, block themes
 * through render_block), once per page. Only filled rows.
 *
 * Bible §14: PFM_DISABLE stops it (the bootstrap does not call init()),
 * test mode of Product Formats = only shop managers see it, page requests
 * only, every callback in try/catch, nothing written on visitor requests.
 */

defined( 'ABSPATH' ) || exit;

final class PFM_Book {

	const OPT       = 'pfm_book';
	const SHORTCODE = 'nox_book_details';

	/** Mapped fields: key => default msgid of the row label. */
	const FIELDS = array(
		'pages'   => 'Σελίδες',
		'year'    => 'Έτος έκδοσης',
		'lang'    => 'Γλώσσα',
		'edition' => 'Έκδοση',
		'series'  => 'Σειρά',
		'binding' => 'Δέσιμο',
	);

	/** Words in attribute labels / slugs that suggest each field. */
	const HINTS = array(
		'pages'   => array( 'σελίδ', 'σελιδ', 'selid', 'pages', 'page-count' ),
		'year'    => array( 'έτος', 'ετος', 'etos', 'year', 'χρονολογ' ),
		'lang'    => array( 'γλώσσ', 'γλωσσ', 'glossa', 'language' ),
		'edition' => array( 'έκδοση', 'εκδοση', 'ekdosi', 'edition' ),
		'series'  => array( 'σειρά', 'σειρα', 'seira', 'series' ),
		'binding' => array( 'δέσιμο', 'δεσιμο', 'desimo', 'binding' ),
	);

	const POSITIONS = array( 'after_meta', 'before_tabs', 'manual' );

	const TABLE_ROWS = array( 'format', 'isbn', 'pages', 'year', 'binding', 'dimensions', 'lang', 'edition', 'series' );

	/** Per-request state. */
	private static $settings   = null;
	private static $active     = null;
	private static $table_done = false;
	private static $broken     = false;

	/* =====================================================================
	 * Settings
	 * =================================================================== */

	public static function defaults(): array {
		return array(
			'schema'      => false,
			'add_type'    => true,
			'attrs'       => array_fill_keys( array_keys( self::FIELDS ), '' ),
			'hardcover'   => array( 'σκληρόδετο', 'σκληρόδετη', 'σκληρό', 'hardcover', 'hardback' ),
			'table'       => false,
			'position'    => 'after_meta',
			'title_el'    => '',
			'title_en'    => '',
			'rows'        => self::TABLE_ROWS,
		);
	}

	public static function get(): array {
		if ( null === self::$settings ) {
			$raw            = get_option( self::OPT, array() );
			self::$settings = self::sanitize( is_array( $raw ) ? $raw : array() );
		}
		return self::$settings;
	}

	public static function sanitize( array $in ): array {
		$d   = self::defaults();
		$out = array();
		foreach ( array( 'schema', 'add_type', 'table' ) as $k ) {
			$out[ $k ] = array_key_exists( $k, $in ) ? (bool) $in[ $k ] : $d[ $k ];
		}
		$attrs = isset( $in['attrs'] ) && is_array( $in['attrs'] ) ? $in['attrs'] : array();
		$out['attrs'] = array();
		foreach ( array_keys( self::FIELDS ) as $f ) {
			$v                  = isset( $attrs[ $f ] ) && is_scalar( $attrs[ $f ] ) ? sanitize_title( (string) $attrs[ $f ] ) : '';
			$out['attrs'][ $f ] = ( '' !== $v && 0 === strpos( $v, 'pa_' ) && strlen( $v ) <= 32 ) ? $v : '';
		}
		$words = $in['hardcover'] ?? $d['hardcover'];
		if ( is_string( $words ) ) {
			$words = explode( ',', $words );
		}
		$out['hardcover'] = array();
		foreach ( is_array( $words ) ? $words : array() as $w ) {
			if ( ! is_scalar( $w ) ) {
				continue;
			}
			$w = self::lower( trim( sanitize_text_field( (string) $w ) ) );
			if ( '' !== $w && self::len( $w ) <= 40 && ! in_array( $w, $out['hardcover'], true ) && count( $out['hardcover'] ) < 20 ) {
				$out['hardcover'][] = $w;
			}
		}
		$pos             = isset( $in['position'] ) ? (string) $in['position'] : $d['position'];
		$out['position'] = in_array( $pos, self::POSITIONS, true ) ? $pos : $d['position'];
		foreach ( array( 'title_el', 'title_en' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? PFM_Settings::clip( sanitize_text_field( (string) $in[ $k ] ), PFM_Settings::MAX_TITLE ) : '';
		}
		$rows        = isset( $in['rows'] ) && is_array( $in['rows'] ) ? $in['rows'] : $d['rows'];
		$out['rows'] = array_values( array_intersect( self::TABLE_ROWS, array_map( 'strval', $rows ) ) );
		return $out;
	}

	public static function save( array $in ): void {
		$clean = self::sanitize( $in );
		update_option( self::OPT, $clean, true );
		self::$settings = $clean;
	}

	/**
	 * Suggested attribute per field, from the labels and slugs of the
	 * shop's global attributes ('' when nothing fits).
	 *
	 * @return array field => taxonomy (pa_…)
	 */
	public static function suggest_attrs(): array {
		$out  = array_fill_keys( array_keys( self::FIELDS ), '' );
		$list = function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array();
		foreach ( self::HINTS as $f => $hints ) {
			foreach ( $list as $a ) {
				if ( in_array( 'pa_' . (string) $a->attribute_name, $out, true ) ) {
					continue; // One attribute per field: "Έτος έκδοσης" is the year, not the edition.
				}
				$hay = self::lower( (string) $a->attribute_label . ' ' . (string) $a->attribute_name );
				foreach ( $hints as $h ) {
					if ( false !== strpos( $hay, $h ) ) {
						$out[ $f ] = 'pa_' . (string) $a->attribute_name;
						continue 3;
					}
				}
			}
		}
		return $out;
	}

	/* =====================================================================
	 * Reading the data of a product
	 * =================================================================== */

	/** The text of a mapped attribute of a product ('' when not mapped or empty). */
	public static function value( WC_Product $p, string $field ): string {
		$s   = self::get();
		$tax = $s['attrs'][ $field ] ?? '';
		if ( '' === $tax ) {
			return '';
		}
		$v = (string) $p->get_attribute( $tax );
		if ( '' === $v && $p->get_parent_id() ) {
			$parent = wc_get_product( $p->get_parent_id() );
			$v      = $parent ? (string) $parent->get_attribute( $tax ) : '';
		}
		return trim( wp_strip_all_tags( $v ) );
	}

	/** Number of pages (first whole number of the value), 0 when none. */
	public static function pages( WC_Product $p ): int {
		if ( preg_match( '/\d{1,5}/', self::value( $p, 'pages' ), $m ) ) {
			$n = (int) $m[0];
			return $n > 0 && $n <= 20000 ? $n : 0;
		}
		return 0;
	}

	/** Publication year (four digits) or ''. */
	public static function year( WC_Product $p ): string {
		if ( preg_match( '/(?<!\d)(1[4-9]\d\d|20\d\d|2100)(?!\d)/', self::value( $p, 'year' ), $m ) ) {
			return $m[1];
		}
		return '';
	}

	/** ISBN-13 from the GTIN field (digits, 978 / 979), or ''. */
	public static function isbn( WC_Product $p ): string {
		$raw = method_exists( $p, 'get_global_unique_id' ) ? (string) $p->get_global_unique_id() : '';
		$d   = (string) preg_replace( '/[^0-9]/', '', $raw );
		return ( 13 === strlen( $d ) && preg_match( '/^97[89]/', $d ) ) ? $d : '';
	}

	/** Whether the binding text names a hardcover. */
	public static function is_hardcover( string $binding ): bool {
		$b = self::lower( $binding );
		foreach ( self::get()['hardcover'] as $w ) {
			if ( '' !== $w && false !== strpos( $b, $w ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * schema.org bookFormat of a product, '' when unknown. Print needs a
	 * binding to tell hardcover from paperback.
	 */
	public static function book_format( WC_Product $p ): string {
		$format = PFM_Works::format_of( (int) ( $p->get_parent_id() ? $p->get_parent_id() : $p->get_id() ) );
		switch ( $format ) {
			case 'ebook':
				return 'https://schema.org/EBook';
			case 'audiobook':
				return 'https://schema.org/AudiobookFormat';
			case 'print':
				$binding = self::value( $p, 'binding' );
				if ( '' === $binding ) {
					return '';
				}
				return self::is_hardcover( $binding ) ? 'https://schema.org/Hardcover' : 'https://schema.org/Paperback';
		}
		return '';
	}

	/* =====================================================================
	 * Front end
	 * =================================================================== */

	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_filter( 'woocommerce_structured_data_product', array( __CLASS__, 'wc_product' ), 30, 2 );
		add_filter( 'rank_math/json_ld', array( __CLASS__, 'rank_math' ), 100, 2 );
		add_action( 'wp', array( __CLASS__, 'setup' ) );
	}

	/** Shown to this visitor: Product Formats' test mode applies. */
	public static function active(): bool {
		if ( self::$broken ) {
			return false;
		}
		if ( null !== self::$active ) {
			return self::$active;
		}
		if ( ! PFM_Front::is_page_request() ) {
			return false;
		}
		$ok = true;
		if ( ! empty( PFM_Settings::get()['test_mode'] ) ) {
			if ( ! did_action( 'set_current_user' ) ) {
				return false;
			}
			$ok = current_user_can( 'manage_woocommerce' );
		}
		self::$active = $ok;
		return $ok;
	}

	public static function setup(): void {
		try {
			$s = self::get();
			if ( empty( $s['table'] ) || 'manual' === $s['position'] || ! self::active() || ! function_exists( 'is_product' ) || ! is_product() ) {
				return;
			}
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
			$blocks = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
			if ( 'after_meta' === $s['position'] ) {
				if ( ! $blocks ) {
					add_action( 'woocommerce_product_meta_end', array( __CLASS__, 'print_table' ), 50 );
					add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'print_table' ), 45 );
				}
				add_filter( 'render_block_woocommerce/product-meta', array( __CLASS__, 'block_after' ), 10, 3 );
			} else {
				if ( ! $blocks ) {
					add_action( 'woocommerce_after_single_product_summary', array( __CLASS__, 'print_table' ), 6 );
				}
				add_filter( 'render_block_woocommerce/product-details', array( __CLASS__, 'block_before' ), 10, 3 );
			}
		} catch ( \Throwable $e ) {
			self::$broken = true;
		}
	}

	public static function enqueue(): void {
		wp_enqueue_style( 'pfm-front', PFM_URL . 'assets/front.css', array(), PFM_VERSION );
	}

	public static function print_table(): void {
		if ( self::$table_done ) {
			return;
		}
		$html = self::table( self::current_id() );
		if ( '' !== $html ) {
			self::$table_done = true;
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in table().
		}
	}

	public static function block_after( $content, $parsed = null, $block = null ) {
		return self::attach( (string) $content, $block, false );
	}

	public static function block_before( $content, $parsed = null, $block = null ) {
		return self::attach( (string) $content, $block, true );
	}

	private static function attach( string $content, $block, bool $before ): string {
		try {
			if ( self::$table_done ) {
				return $content;
			}
			if ( is_object( $block ) && isset( $block->context['postId'] ) && (int) $block->context['postId'] !== (int) get_queried_object_id() ) {
				return $content;
			}
			$html = self::table( self::current_id() );
			if ( '' === $html ) {
				return $content;
			}
			self::$table_done = true;
			return $before ? $html . $content : $content . $html;
		} catch ( \Throwable $e ) {
			self::$broken = true;
			return $content;
		}
	}

	private static function current_id(): int {
		global $product;
		if ( $product instanceof WC_Product ) {
			return (int) $product->get_id();
		}
		return (int) get_queried_object_id();
	}

	/** [nox_book_details] or [nox_book_details id="123"]. */
	public static function shortcode( $atts ): string {
		try {
			if ( empty( self::get()['table'] ) || ! self::active() ) {
				return '';
			}
			$atts = shortcode_atts( array( 'id' => 0 ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
			$id   = absint( $atts['id'] );
			if ( ! $id ) {
				$id = self::current_id();
			}
			$html = self::table( $id );
			if ( '' !== $html ) {
				self::enqueue();
				if ( $id === self::current_id() ) {
					self::$table_done = true;
				}
			}
			return $html;
		} catch ( \Throwable $e ) {
			self::$broken = true;
			return '';
		}
	}

	/** Rows of the table for a product: list of [ label, value ]. */
	public static function rows( WC_Product $p ): array {
		$s    = self::get();
		$rows = array();
		foreach ( $s['rows'] as $r ) {
			$v = '';
			switch ( $r ) {
				case 'format':
					$v = PFM_Settings::label( PFM_Works::format_of( (int) $p->get_id() ) );
					$l = __( 'Μορφή', 'product-formats' );
					break;
				case 'isbn':
					$v = self::isbn( $p );
					$l = 'ISBN';
					break;
				case 'pages':
					$n = self::pages( $p );
					$v = $n ? number_format_i18n( $n ) : '';
					$l = __( 'Σελίδες', 'product-formats' );
					break;
				case 'year':
					$v = self::year( $p );
					$l = __( 'Έτος έκδοσης', 'product-formats' );
					break;
				case 'dimensions':
					$v = $p->has_dimensions() ? html_entity_decode( wp_strip_all_tags( wc_format_dimensions( $p->get_dimensions( false ) ) ), ENT_QUOTES, 'UTF-8' ) : '';
					$l = __( 'Διαστάσεις', 'product-formats' );
					break;
				default:
					$v = self::value( $p, $r );
					$l = __( self::FIELDS[ $r ], 'product-formats' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- msgid from FIELDS.
			}
			if ( '' !== trim( (string) $v ) ) {
				$rows[] = array( $l, (string) $v );
			}
		}
		return $rows;
	}

	public static function table( int $product_id ): string {
		try {
			if ( $product_id <= 0 || 'product' !== get_post_type( $product_id ) ) {
				return '';
			}
			$post = get_post( $product_id );
			if ( ! $post || ( 'publish' === $post->post_status ? post_password_required( $post ) : ! current_user_can( 'read_post', $product_id ) ) ) {
				return '';
			}
			$p = wc_get_product( $product_id );
			if ( ! $p ) {
				return '';
			}
			$rows = self::rows( $p );
			if ( ! $rows ) {
				return '';
			}
			$s     = self::get();
			$title = 'en' === PFM_Lang::lang() ? $s['title_en'] : $s['title_el'];
			if ( '' === $title ) {
				$title = __( 'Στοιχεία έκδοσης', 'product-formats' );
			}
			$items = '';
			foreach ( $rows as $r ) {
				$items .= '<div class="pfm-book__row"><dt>' . esc_html( $r[0] ) . '</dt><dd>' . esc_html( $r[1] ) . '</dd></div>';
			}
			return '<div class="pfm-book"><div class="pfm-book__title">' . esc_html( $title ) . '</div><dl class="pfm-book__list">' . $items . '</dl></div>';
		} catch ( \Throwable $e ) {
			self::$broken = true;
			return '';
		}
	}

	/* =====================================================================
	 * Schema
	 * =================================================================== */

	/** woocommerce_structured_data_product. */
	public static function wc_product( $markup, $product = null ) {
		if ( ! is_array( $markup ) || ! $product instanceof WC_Product ) {
			return $markup;
		}
		try {
			return self::patch( $markup, $product );
		} catch ( \Throwable $e ) {
			return $markup;
		}
	}

	/** rank_math/json_ld: the Product node of the viewed product. */
	public static function rank_math( $data, $jsonld = null ) {
		if ( ! is_array( $data ) || ! function_exists( 'is_product' ) || ! is_product() ) {
			return $data;
		}
		try {
			$p = wc_get_product( (int) get_queried_object_id() );
			if ( ! $p ) {
				return $data;
			}
			foreach ( $data as $k => $node ) {
				if ( is_array( $node ) && isset( $node['@type'] ) && in_array( 'Product', (array) $node['@type'], true ) ) {
					$data[ $k ] = self::patch( $node, $p );
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $data;
	}

	/** The node with the book properties (unchanged when off or not a book). */
	public static function patch( array $node, WC_Product $p ): array {
		$s = self::get();
		if ( empty( $s['schema'] ) || Product_Formats::killed() ) {
			return $node;
		}
		if ( ! empty( PFM_Settings::get()['test_mode'] ) && ! ( did_action( 'set_current_user' ) && current_user_can( 'manage_woocommerce' ) ) ) {
			return $node;
		}
		$pid    = (int) ( $p->get_parent_id() ? $p->get_parent_id() : $p->get_id() );
		$format = PFM_Works::format_of( $pid );
		$types  = isset( $node['@type'] ) ? (array) $node['@type'] : array( 'Product' );
		$works  = array( 'Book', 'Audiobook', 'Movie', 'MusicAlbum', 'MusicRecording', 'CreativeWork' );
		if ( ! array_intersect( $types, $works ) && ! empty( $s['add_type'] ) ) {
			if ( in_array( $format, array( 'print', 'ebook' ), true ) ) {
				$types[] = 'Book';
			} elseif ( 'audiobook' === $format ) {
				$types[] = 'Audiobook';
			}
		}
		$is_book  = in_array( 'Book', $types, true );
		$is_audio = in_array( 'Audiobook', $types, true );
		if ( ! $is_book && ! $is_audio ) {
			return $node;
		}
		$node['@type'] = array_values( array_unique( $types ) );

		$pages = self::pages( $p );
		if ( $pages && ! $is_audio && empty( $node['numberOfPages'] ) ) {
			$node['numberOfPages'] = $pages;
		}
		$year = self::year( $p );
		if ( '' !== $year && empty( $node['datePublished'] ) ) {
			$node['datePublished'] = $year;
		}
		foreach ( array(
			'lang'    => 'inLanguage',
			'edition' => 'bookEdition',
		) as $f => $prop ) {
			$v = self::value( $p, $f );
			if ( '' !== $v && empty( $node[ $prop ] ) ) {
				$node[ $prop ] = $v;
			}
		}
		$series = self::value( $p, 'series' );
		if ( '' !== $series && empty( $node['isPartOf'] ) ) {
			$node['isPartOf'] = array(
				'@type' => 'BookSeries',
				'name'  => $series,
			);
		}
		$bf = self::book_format( $p );
		if ( '' !== $bf && empty( $node['bookFormat'] ) ) {
			$node['bookFormat'] = $bf;
		}
		$isbn = self::isbn( $p );
		if ( '' !== $isbn && empty( $node['isbn'] ) ) {
			$node['isbn'] = $isbn;
		}
		$examples = self::work_examples( $pid );
		if ( $examples && empty( $node['workExample'] ) ) {
			$node['workExample'] = $examples;
		}

		/**
		 * Filter: the Product node with the book properties of Product Formats.
		 *
		 * @param array      $node    The node.
		 * @param WC_Product $product The product.
		 */
		$node = apply_filters( 'pfm_book_schema', $node, $p );
		return is_array( $node ) ? $node : array();
	}

	/** The other formats of the work a visitor may see, as small book nodes. */
	public static function work_examples( int $product_id ): array {
		$work = PFM_Works::work_of( $product_id );
		if ( ! $work ) {
			return array();
		}
		$out = array();
		foreach ( PFM_Works::visible_members( $work ) as $m ) {
			if ( $m['id'] === $product_id || ! in_array( $m['format'], array( 'print', 'ebook', 'audiobook' ), true ) ) {
				continue;
			}
			$o = wc_get_product( $m['id'] );
			if ( ! $o ) {
				continue;
			}
			$ex = array(
				'@type' => 'audiobook' === $m['format'] ? 'Audiobook' : 'Book',
				'name'  => wp_strip_all_tags( $o->get_name() ),
				'url'   => (string) $o->get_permalink(),
			);
			$bf = self::book_format( $o );
			if ( '' !== $bf ) {
				$ex['bookFormat'] = $bf;
			}
			$isbn = self::isbn( $o );
			if ( '' !== $isbn ) {
				$ex['isbn'] = $isbn;
			}
			$out[] = $ex;
			if ( count( $out ) >= 10 ) {
				break;
			}
		}
		return $out;
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	public static function lower( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	private static function len( string $s ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
	}
}

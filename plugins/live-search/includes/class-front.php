<?php
/**
 * LVS_Front — what the visitor meets.
 *
 * Suggestions: assets/live-search.js enhances the search forms that are
 * already on the page (the theme's, WooCommerce's widget and blocks, the
 * WordPress search block, [lvs_search]) on the first focus of their field:
 * nothing is replaced, so every form keeps the theme's look (§14.13), and
 * nothing is downloaded before a visitor clicks into a search field. The
 * config printed with the script is the same for every guest, so pages
 * stay in the page cache; a logged-in trade account gets no prices
 * (Q5, 2026-11-16), a shop manager in test mode gets a REST nonce.
 *
 * Results page: WooCommerce's own product search (?s=…&post_type=product)
 * with the theme's template. Only the matching changes: the products our
 * index finds, in our order (unless the visitor picked another sorting),
 * through post__in on the main query, so WooCommerce's visibility, Hide
 * Catalog and Shop Filters apply as on any other listing. An exact ISBN
 * of one product opens that product (Q9). The phrase and the number of
 * results go to the statistics file (Q6).
 */

defined( 'ABSPATH' ) || exit;

final class LVS_Front {

	/** @var WP_Query|null The main search query we changed. */
	private static $query = null;

	/** @var int */
	private static $found = 0;

	/** @var bool */
	private static $printed = false;

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_shortcode( 'lvs_search', array( __CLASS__, 'shortcode' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'pre_get_posts' ), 20 );
		add_filter( 'posts_search', array( __CLASS__, 'posts_search' ), 99, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'template_redirect' ), 5 );
	}

	/** Our front end runs on this request. */
	private static function on(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || is_embed() ) {
			return false;
		}
		return LVS_Settings::on_for_request();
	}

	/* =====================================================================
	 * Suggestions
	 * =================================================================== */

	public static function assets(): void {
		try {
			if ( ! self::on() ) {
				return;
			}
			wp_enqueue_style( 'lvs', LVS_URL . 'assets/live-search.css', array(), LVS_VERSION );
			// WordPress 6.3+ reads «defer»; older versions take the array as «in footer».
			wp_enqueue_script(
				'lvs',
				LVS_URL . 'assets/live-search.js',
				array(),
				LVS_VERSION,
				array(
					'strategy'  => 'defer',
					'in_footer' => true,
				)
			);
			wp_add_inline_script( 'lvs', 'window.lvsConfig=' . wp_json_encode( self::config(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** The config of the script (the same for every guest). */
	public static function config(): array {
		$s      = LVS_Settings::get();
		$engine = LVS_Settings::engine();
		$file   = 'file' === $engine ? LVS_File::current_url() : '';
		if ( '' === $file ) {
			$engine = 'server';
		}
		$trade = is_user_logged_in() && function_exists( 'tra_is_trade' ) && tra_is_trade( get_current_user_id() );
		$lang  = LVS_Settings::visitor_lang();
		$cfg   = array(
			'engine'   => $engine,
			'file'     => $file,
			'rest'     => esc_url_raw( rest_url( 'lvs/v1/suggest' ) ),
			'log'      => ( $s['stats'] && 'live' === $s['mode'] ) ? esc_url_raw( rest_url( 'lvs/v1/log' ) ) : '',
			'min'      => $s['min_chars'],
			'limit'    => $s['limit'],
			'img'      => $s['image'],
			'price'    => $s['price'] && ! $trade,
			'byline'   => $s['byline'],
			'oos'      => $s['oos'],
			'isbn'     => $s['isbn_direct'],
			'group'    => $s['group'],
			'creators' => $s['creators'],
			'forms'    => $s['forms'],
			'home'     => esc_url_raw( home_url( '/' ) ),
			'lang'     => $lang,
			't'        => array_merge(
				array(
					'placeholder' => LVS_Settings::text( 'placeholder' ),
					'none'        => LVS_Settings::text( 'none' ),
					'all'         => LVS_Settings::text( 'all' ),
				),
				self::labels( $lang )
			),
			'cur'      => self::currency(),
		);
		if ( 'test' === $s['mode'] && is_user_logged_in() ) {
			$cfg['nonce'] = wp_create_nonce( 'wp_rest' );
		}
		return $cfg;
	}

	/** Fixed visitor labels, by the visitor's language. */
	private static function labels( string $lang ): array {
		if ( 'en' === $lang ) {
			return array(
				'products' => 'Products',
				'creators' => 'Creators',
				'preorder' => 'Pre-order',
				'out'      => 'Out of stock',
				'count1'   => '1 result',
				'countN'   => '%d results',
				'loading'  => 'Searching…',
				'close'    => 'Close',
			);
		}
		return array(
			'products' => 'Προϊόντα',
			'creators' => 'Δημιουργοί',
			'preorder' => 'Προπαραγγελία',
			'out'      => 'Εξαντλημένο',
			'count1'   => '1 αποτέλεσμα',
			'countN'   => '%d αποτελέσματα',
			'loading'  => 'Αναζήτηση…',
			'close'    => 'Κλείσιμο',
		);
	}

	/** WooCommerce's price format, for prices built in the browser. */
	private static function currency(): array {
		return array(
			's'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'pos' => (string) get_option( 'woocommerce_currency_pos', 'left' ),
			'dec' => (int) wc_get_price_decimals(),
			'ds'  => (string) wc_get_price_decimal_separator(),
			'ts'  => (string) wc_get_price_thousand_separator(),
		);
	}

	/** [lvs_search]: WooCommerce's product search form, enhanced by the script. */
	public static function shortcode(): string {
		try {
			if ( function_exists( 'get_product_search_form' ) ) {
				return '<div class="lvs-shortcode">' . get_product_search_form( false ) . '</div>';
			}
		} catch ( \Throwable $e ) {
			return '';
		}
		return '';
	}

	/* =====================================================================
	 * Results page
	 * =================================================================== */

	/** True when the query is the main product search of the front end. */
	private static function is_product_search( WP_Query $q ): bool {
		if ( ! $q->is_main_query() || ! $q->is_search() ) {
			return false;
		}
		$pt = $q->get( 'post_type' );
		if ( is_array( $pt ) ) {
			$pt = 1 === count( $pt ) ? reset( $pt ) : '';
		}
		return 'product' === $pt && '' !== trim( (string) $q->get( 's' ) );
	}

	public static function pre_get_posts( $q ): void {
		try {
			if ( ! $q instanceof WP_Query || ! self::is_product_search( $q ) || ! self::on() ) {
				return;
			}
			$rows = LVS_Search::find( (string) $q->get( 's' ), 'results', 500 );
			$ids  = array_map(
				static function ( $r ) {
					return (int) $r['product_id'];
				},
				$rows
			);
			self::$query = $q;
			self::$found = count( $ids );
			$q->set( 'post__in', $ids ? $ids : array( 0 ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sorting choice.
			$order = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( (string) $_GET['orderby'] ) ) : '';
			if ( '' === $order || 'relevance' === $order ) {
				$q->set( 'orderby', 'post__in' );
				$q->set( 'order', 'ASC' );
			}
		} catch ( \Throwable $e ) {
			self::$query = null;
		}
	}

	/** WordPress's own LIKE search is replaced by our list of ids. */
	public static function posts_search( $search, $q ) {
		if ( null !== self::$query && $q === self::$query ) {
			return '';
		}
		return $search;
	}

	public static function template_redirect(): void {
		if ( null === self::$query || self::$printed ) {
			return;
		}
		self::$printed = true;
		try {
			$s     = (string) self::$query->get( 's' );
			$paged = max( 1, (int) self::$query->get( 'paged' ) );
			if ( 1 !== $paged ) {
				return;
			}
			if ( LVS_Settings::get()['isbn_direct'] ) {
				$id = LVS_Search::isbn_product( $s );
				if ( $id > 0 && in_array( $id, array_map( 'intval', (array) self::$query->get( 'post__in' ) ), true ) ) {
					LVS_Stats::write( $s, 1, 'r' );
					wp_safe_redirect( get_permalink( $id ), 302 );
					exit;
				}
			}
			$n = (int) self::$query->found_posts;
			LVS_Stats::write( $s, $n, 'r' );
		} catch ( \Throwable $e ) {
			return;
		}
	}
}

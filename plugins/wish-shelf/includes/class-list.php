<?php
/**
 * WSH_List — the list page ([nox_wishlist], block, My Account tab), shared
 * views (?wsh=…), the list form (add to cart, remove, empty) and the
 * "bought from a list" marks on orders.
 *
 * Three views:
 *  - own     a signed-in customer's list, rendered on the server; with
 *            named lists (1.1.0) one list at a time (?wsh_list=<id>), with
 *            tabs, create / rename / delete, "Move" per row and the email
 *            alerts opt-in (WSH_Alerts);
 *  - guest   a placeholder that front.js fills from the browser's list
 *            through the read-only WSH_Ajax::render endpoint;
 *  - shared  ?wsh=<token> (a customer's shared list, main or named) or ?wsh=12,45-91
 *            (a guest's link with the ids in it): read-only, noindex.
 *
 * Page caches: the guest view is the same HTML for every guest, so the
 * page stays cacheable for them; the own and shared views send
 * DONOTCACHEPAGE and nocache headers.
 */

defined( 'ABSPATH' ) || exit;

final class WSH_List {

	const NONCE = 'wsh_list';

	/** Keys in a share link or a request: "12" or "12-91". */
	const MAX_KEYS = 200;

	/**
	 * The own view being rendered: { list (id), lists (all_lists()) }; lists
	 * is empty when named lists are off.
	 *
	 * @var array
	 */
	private static $own = array(
		'list'  => 0,
		'lists' => array(),
	);

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'headers' ), 5 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'handle_form' ), 20 );
		// Bought from a list: a mark in the WooCommerce session, copied to the order line.
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'mark_line' ), 10, 3 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'count_bought' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'count_bought' ) );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_meta' ) );
	}

	/* =====================================================================
	 * Keys
	 * =================================================================== */

	/**
	 * Parses "12,45-91" (or an array of such keys) into unique pairs.
	 *
	 * @param mixed $raw String or array.
	 * @return array[] Each [ p, v ].
	 */
	public static function parse_keys( $raw ): array {
		$parts = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$out   = array();
		foreach ( $parts as $k ) {
			if ( ! is_string( $k ) && ! is_int( $k ) ) {
				continue;
			}
			if ( 1 !== preg_match( '/^(\d{1,19})(?:-(\d{1,19}))?$/', trim( (string) $k ), $m ) ) {
				continue;
			}
			$p = (int) $m[1];
			$v = isset( $m[2] ) ? (int) $m[2] : 0;
			if ( $p > 0 ) {
				$out[ $p . '-' . $v ] = array( $p, $v );
			}
			if ( count( $out ) >= self::MAX_KEYS ) {
				break;
			}
		}
		return array_values( $out );
	}

	public static function key( int $p, int $v ): string {
		return $v > 0 ? $p . '-' . $v : (string) $p;
	}

	/** The ?wsh= value of this request ('' when none). */
	private static function share_param(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view.
		return isset( $_GET['wsh'] ) && is_string( $_GET['wsh'] ) ? sanitize_text_field( wp_unslash( $_GET['wsh'] ) ) : '';
	}

	/** True on the list page (or a page with the shortcode / block). */
	private static function on_list_page(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$id = WSH_Settings::page_id();
		if ( $id && is_page( $id ) ) {
			return true;
		}
		$post = get_post();
		return $post && ( has_shortcode( $post->post_content, 'nox_wishlist' ) || false !== strpos( $post->post_content, '<!-- wp:noxpress/wishlist ' ) || false !== strpos( $post->post_content, '<!-- wp:noxpress/wishlist /-->' ) );
	}

	/** No page cache for the own and shared views (Bible §14.15 pattern). */
	public static function headers(): void {
		try {
			if ( ! WSH_Front::active() ) {
				return;
			}
			$own = is_user_logged_in() && ( self::on_list_page() || self::on_account_tab() );
			if ( $own || ( '' !== self::share_param() && self::on_list_page() ) ) {
				if ( ! defined( 'DONOTCACHEPAGE' ) ) {
					define( 'DONOTCACHEPAGE', true );
				}
				nocache_headers();
			}
		} catch ( \Throwable $e ) {
			WSH_Front::fail();
		}
	}

	private static function on_account_tab(): bool {
		$s = WSH_Settings::get();
		return $s['account'] && function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( $s['endpoint'] );
	}

	/**
	 * Shared views are not for search engines.
	 *
	 * @param array $robots wp_robots directives.
	 */
	public static function robots( $robots ) {
		if ( is_array( $robots ) && '' !== self::share_param() && self::on_list_page() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/* =====================================================================
	 * Shortcode / block
	 * =================================================================== */

	/** [nox_wishlist]: the view that fits the request. */
	public static function shortcode(): string {
		try {
			if ( ! WSH_Front::active() ) {
				return '';
			}
			WSH_Front::assets();
			$notices = self::notices() . WSH_Alerts::unsub_note();
			$param   = self::share_param();
			if ( '' !== $param ) {
				return $notices . self::render_shared( $param );
			}
			$uid = get_current_user_id();
			if ( $uid ) {
				return $notices . self::render_own( $uid );
			}
			if ( 'login' === WSH_Settings::get()['guests'] ) {
				return $notices . self::login_box();
			}
			return $notices . self::guest_placeholder();
		} catch ( \Throwable $e ) {
			WSH_Front::fail();
			return '';
		}
	}

	/**
	 * WooCommerce notices still waiting (e.g. "added to your cart" after the
	 * list's form), for themes that print none on a normal page (block
	 * themes without the Store Notices block). Themes that already printed
	 * them leave nothing here.
	 */
	private static function notices(): string {
		if ( ! function_exists( 'wc_notice_count' ) || ! WC()->session || ! wc_notice_count() ) {
			return '';
		}
		return '<div class="woocommerce-notices-wrapper">' . wc_print_notices( true ) . '</div>';
	}

	/** The My Account tab. */
	public static function account_tab(): void {
		try {
			$uid = get_current_user_id();
			if ( ! $uid || ! WSH_Front::active() ) {
				return;
			}
			WSH_Front::assets();
			echo self::render_own( $uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_own().
		} catch ( \Throwable $e ) {
			WSH_Front::fail();
		}
	}

	private static function login_box(): string {
		$url = (string) wc_get_page_permalink( 'myaccount' );
		return '<div class="wsh-root wsh-list wsh-list--login"><p class="wsh-empty"><a href="' . esc_url( $url ) . '">' . esc_html( WSH_Settings::text( 't_login' ) ) . '</a></p></div>';
	}

	/** The guest view: front.js fills it from the browser's list. */
	private static function guest_placeholder(): string {
		return '<div class="wsh-root wsh-list wsh-list--guest" data-wsh-guest="1" aria-live="polite"><p class="wsh-empty" data-wsh-empty hidden>' . esc_html( WSH_Settings::text( 't_empty' ) ) . '</p><div class="wsh-load" data-wsh-body></div></div>';
	}

	/**
	 * A shared view: a customer's token or a list of keys.
	 *
	 * @param string $param ?wsh= value.
	 */
	private static function render_shared( string $param ): string {
		$s = WSH_Settings::get();
		if ( ! $s['share'] ) {
			return self::empty_box();
		}
		$rows = array();
		$name = '';
		if ( 1 === preg_match( '/^[A-Za-z0-9]{20}$/', $param ) ) {
			$owner = WSH_Store::token_owner( $param );
			// Named lists off: the main link shows everything, named links nothing.
			if ( $owner && ( self::lists_on() || 0 === $owner[1] ) ) {
				if ( $owner[1] > 0 ) {
					foreach ( WSH_Store::lists( $owner[0] ) as $l ) {
						if ( $l['id'] === $owner[1] ) {
							$name = $l['name'];
						}
					}
				}
				foreach ( WSH_Store::items( $owner[0], self::lists_on() ? $owner[1] : null ) as $it ) {
					$rows[] = array(
						'p'     => $it['p'],
						'v'     => $it['v'],
						'added' => '',
						'price' => null,
					);
				}
			}
		} else {
			$rows = self::rows_from_keys( self::parse_keys( $param ) );
		}
		self::$own['name'] = $name;
		$html              = self::render( $rows, 'shared' );
		self::$own['name'] = '';
		return $html;
	}

	/**
	 * Rows from key pairs (guest and shared views: no date, no old price).
	 *
	 * @param array[] $pairs Each [ p, v ].
	 */
	public static function rows_from_keys( array $pairs ): array {
		$rows = array();
		foreach ( $pairs as $pv ) {
			$rows[] = array(
				'p'     => (int) $pv[0],
				'v'     => (int) $pv[1],
				'added' => '',
				'price' => null,
			);
		}
		return $rows;
	}

	/** Named lists are on (setting). */
	public static function lists_on(): bool {
		return (bool) WSH_Settings::get()['lists'];
	}

	/**
	 * The list of this request (?wsh_list=), when it is the customer's; else 0.
	 *
	 * @param int $uid User id.
	 */
	private static function current_list( int $uid ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only choice of view.
		$id = isset( $_GET['wsh_list'] ) && is_string( $_GET['wsh_list'] ) ? absint( $_GET['wsh_list'] ) : 0;
		return ( $id && self::lists_on() && WSH_Store::owns_list( $uid, $id ) ) ? $id : 0;
	}

	/**
	 * A signed-in customer's own view (one list at a time when named lists are on).
	 *
	 * @param int $uid User id.
	 */
	private static function render_own( int $uid ): string {
		if ( ! self::lists_on() ) {
			self::$own = array(
				'list'  => 0,
				'lists' => array(),
			);
			return self::render( WSH_Store::items( $uid ), 'own' ) . WSH_Alerts::optin_box( $uid );
		}
		$list      = self::current_list( $uid );
		self::$own = array(
			'list'  => $list,
			'lists' => WSH_Store::all_lists( $uid ),
		);
		$html      = self::lists_bar( $uid ) . self::render( WSH_Store::items( $uid, $list ), 'own' ) . WSH_Alerts::optin_box( $uid );
		self::$own = array(
			'list'  => 0,
			'lists' => array(),
		);
		return $html;
	}

	/** URL of one of the customer's lists on this page. */
	private static function list_link( int $id ): string {
		$url = remove_query_arg( array( 'wsh_list', 'wsh_done' ) );
		return $id ? add_query_arg( 'wsh_list', $id, $url ) : $url;
	}

	/**
	 * Tabs of the customer's lists, and the forms to create / rename / delete.
	 *
	 * @param int $uid User id.
	 */
	private static function lists_bar( int $uid ): string {
		$cur   = self::$own['list'];
		$lists = self::$own['lists'];
		$h     = '<div class="wsh-root wsh-lists" data-wsh-lists><nav class="wsh-lists__tabs" aria-label="' . esc_attr( WSH_Settings::text( 't_title' ) ) . '"><ul>';
		foreach ( $lists as $l ) {
			$on = $l['id'] === $cur;
			$h .= '<li' . ( $on ? ' class="is-current"' : '' ) . '><a href="' . esc_url( self::list_link( $l['id'] ) ) . '"' . ( $on ? ' aria-current="page"' : '' ) . '>' . esc_html( $l['name'] ) . ' <span class="wsh-lists__n">' . (int) $l['count'] . '</span></a></li>';
		}
		$h .= '</ul></nav>';

		$form = '<form method="post" class="wsh-lists__form" action="' . esc_url( remove_query_arg( 'wsh_done' ) ) . '">' . wp_nonce_field( self::NONCE, 'wsh_nonce', false, false ) . '<input type="hidden" name="wsh_form" value="1" /><input type="hidden" name="wsh_list_id" value="' . (int) $cur . '" />';
		$h   .= '<details class="wsh-lists__manage"><summary>' . esc_html( WSH_Settings::text( 't_new_list' ) ) . ( $cur ? ' · ' . esc_html( WSH_Settings::text( 't_rename' ) ) : '' ) . '</summary>';
		if ( count( $lists ) - 1 < WSH_Store::max_lists() ) {
			$h .= $form . '<label class="wsh-lists__field"><span>' . esc_html( WSH_Settings::text( 't_list_name' ) ) . '</span><input type="text" name="wsh_list_name" maxlength="' . (int) WSH_Store::NAME_LEN . '" required /></label><button type="submit" name="wsh_list_op" value="create" class="' . esc_attr( self::btn_class( 'wsh-list-create' ) ) . '">' . esc_html( WSH_Settings::text( 't_create' ) ) . '</button></form>';
		}
		if ( $cur ) {
			$name = '';
			foreach ( $lists as $l ) {
				if ( $l['id'] === $cur ) {
					$name = $l['name'];
				}
			}
			$h .= $form . '<label class="wsh-lists__field"><span>' . esc_html( WSH_Settings::text( 't_list_name' ) ) . '</span><input type="text" name="wsh_list_name" value="' . esc_attr( $name ) . '" maxlength="' . (int) WSH_Store::NAME_LEN . '" required /></label><button type="submit" name="wsh_list_op" value="rename" class="' . esc_attr( self::btn_class( 'wsh-list-rename' ) ) . '">' . esc_html( WSH_Settings::text( 't_rename' ) ) . '</button>';
			$h .= '<button type="submit" name="wsh_list_op" value="delete" class="wsh-clear" data-wsh-del-list formnovalidate>' . esc_html( WSH_Settings::text( 't_del_list' ) ) . '</button></form>';
		}
		return $h . '</details></div>';
	}

	private static function empty_box(): string {
		return '<div class="wsh-root wsh-list"><p class="wsh-empty">' . esc_html( WSH_Settings::text( 't_empty' ) ) . '</p></div>';
	}

	/* =====================================================================
	 * Rendering
	 * =================================================================== */

	/**
	 * Resolves rows to products, dropping what cannot be shown.
	 *
	 * @param array $rows Rows { p, v, added, price }.
	 * @return array Rows with 'product' (parent), 'item' (variation or parent), 'key'.
	 */
	private static function resolve( array $rows ): array {
		$out = array();
		// 1.2.1: categories hidden by Hide Catalog stay hidden here too.
		$hidden = function_exists( 'hic_hidden_terms' ) ? hic_hidden_terms( 'listings' ) : array();
		foreach ( $rows as $r ) {
			$pair = WSH_Store::valid_pair( (int) $r['p'], (int) $r['v'] );
			if ( null === $pair ) {
				continue;
			}
			if ( $hidden && array_intersect( $hidden, array_map( 'intval', wc_get_product_term_ids( $pair[0], 'product_cat' ) ) ) ) {
				continue;
			}
			$product = wc_get_product( $pair[0] );
			$item    = $pair[1] ? wc_get_product( $pair[1] ) : $product;
			if ( ! $product || ! $item ) {
				continue;
			}
			$key         = self::key( $pair[0], $pair[1] );
			$out[ $key ] = array(
				'p'       => $pair[0],
				'v'       => $pair[1],
				'added'   => (string) $r['added'],
				'price'   => $r['price'],
				'product' => $product,
				'item'    => $item,
				'key'     => $key,
			);
		}
		return array_values( $out );
	}

	/**
	 * True when the item goes to the cart from the list without a visit to
	 * its page: a simple product, or a variation with every attribute set.
	 *
	 * @param WC_Product $item Product or variation.
	 */
	public static function direct_cart( WC_Product $item ): bool {
		if ( ! $item->is_purchasable() || ! $item->is_in_stock() ) {
			return false;
		}
		if ( $item->is_type( 'simple' ) ) {
			return true;
		}
		if ( $item->is_type( 'variation' ) ) {
			foreach ( $item->get_variation_attributes() as $value ) {
				if ( '' === (string) $value ) {
					return false;
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * The list HTML.
	 *
	 * @param array  $rows Rows { p, v, added, price }.
	 * @param string $mode 'own' | 'guest' | 'shared'.
	 */
	public static function render( array $rows, string $mode ): string {
		$s     = WSH_Settings::get();
		$items = self::resolve( $rows );
		$title = '';
		if ( 'shared' === $mode ) {
			$sub   = ! empty( self::$own['name'] ) ? ': ' . self::$own['name'] : '';
			$title = '<h2 class="wsh-title">' . esc_html( WSH_Settings::text( 't_shared' ) . $sub ) . '</h2>';
		}
		if ( ! $items ) {
			return '<div class="wsh-root wsh-list wsh-list--' . esc_attr( $mode ) . '" data-wsh-mode="' . esc_attr( $mode ) . '">' . $title . '<p class="wsh-empty">' . esc_html( WSH_Settings::text( 't_empty' ) ) . '</p></div>';
		}

		$body = 'cards' === $s['layout'] ? self::cards( $items ) : self::table( $items, $mode );

		$html  = '<div class="wsh-root wsh-list wsh-list--' . esc_attr( $mode ) . ' wsh-list--' . esc_attr( $s['layout'] ) . '" data-wsh-mode="' . esc_attr( $mode ) . '">';
		$html .= $title;
		$html .= '<form method="post" class="wsh-form" action="' . esc_url( self::form_action() ) . '">';
		$html .= wp_nonce_field( self::NONCE, 'wsh_nonce', false, false );
		$html .= '<input type="hidden" name="wsh_form" value="1" />';
		if ( 'own' === $mode ) {
			$html .= '<input type="hidden" name="wsh_list_id" value="' . (int) self::$own['list'] . '" />';
		}
		foreach ( $items as $it ) {
			$html .= '<input type="hidden" name="wsh_keys[]" value="' . esc_attr( $it['key'] ) . '" />';
		}
		$html .= $body;
		$html .= self::actions( $items, $mode );
		$html .= '</form>';
		if ( 'shared' !== $mode && $s['share'] ) {
			$html .= self::share_bar( $mode );
		}
		$html .= '<p class="wsh-empty" data-wsh-empty hidden>' . esc_html( WSH_Settings::text( 't_empty' ) ) . '</p>';
		return $html . '</div>';
	}

	/** The form posts back to the page it is on (the list page in AJAX renders). */
	private static function form_action(): string {
		if ( wp_doing_ajax() ) {
			$url = WSH_Settings::list_url();
			return '' !== $url ? $url : home_url( '/' );
		}
		return remove_query_arg( array( 'wsh_done' ) );
	}

	/** Theme button classes (Bible §14.16). */
	private static function btn_class( string $extra = '' ): string {
		$c = 'button';
		if ( function_exists( 'wc_wp_theme_get_element_class_name' ) ) {
			$theme = (string) wc_wp_theme_get_element_class_name( 'button' );
			if ( '' !== $theme ) {
				$c .= ' ' . $theme;
			}
		}
		return trim( $c . ' ' . $extra );
	}

	/**
	 * Table layout.
	 *
	 * @param array  $items Resolved rows.
	 * @param string $mode  View.
	 */
	private static function table( array $items, string $mode ): string {
		$s    = WSH_Settings::get();
		$c    = $s['cols'];
		$bulk = $s['bulk'];
		$h    = '<table class="wsh-table shop_table shop_table_responsive"><thead><tr>';
		if ( $bulk ) {
			$h .= '<th class="wsh-c-sel"><label class="wsh-sel"><input type="checkbox" data-wsh-all aria-label="' . esc_attr( WSH_Settings::text( 't_selected' ) ) . '" /><span class="wsh-box" aria-hidden="true"></span></label></th>';
		}
		if ( $c['image'] ) {
			$h .= '<th class="wsh-c-img"><span class="screen-reader-text">' . esc_html( WSH_Settings::text( 't_product' ) ) . '</span></th>';
		}
		$h .= '<th class="wsh-c-name">' . esc_html( WSH_Settings::text( 't_product' ) ) . '</th>';
		foreach ( array(
			'price' => 't_price',
			'stock' => 't_stock',
			'date'  => 't_date',
			'qty'   => 't_qty',
		) as $col => $tk ) {
			if ( $c[ $col ] && ! ( 'date' === $col && 'own' !== $mode ) ) {
				$h .= '<th class="wsh-c-' . esc_attr( $col ) . '">' . esc_html( WSH_Settings::text( $tk ) ) . '</th>';
			}
		}
		if ( $c['cart'] ) {
			$h .= '<th class="wsh-c-cart"><span class="screen-reader-text">' . esc_html( WSH_Settings::text( 't_cart' ) ) . '</span></th>';
		}
		if ( $c['remove'] || 'shared' === $mode ) {
			$h .= '<th class="wsh-c-rem"><span class="screen-reader-text">' . esc_html( WSH_Settings::text( 't_remove' ) ) . '</span></th>';
		}
		$h .= '</tr></thead><tbody>';

		foreach ( $items as $it ) {
			$h .= self::row( $it, $mode );
		}
		return $h . '</tbody></table>';
	}

	/**
	 * One table row.
	 *
	 * @param array  $it   Resolved row.
	 * @param string $mode View.
	 */
	private static function row( array $it, string $mode ): string {
		$s       = WSH_Settings::get();
		$c       = $s['cols'];
		$product = $it['product'];
		$item    = $it['item'];
		$key     = $it['key'];
		$url     = $item->get_permalink();
		$direct  = self::direct_cart( $item );

		$h = '<tr class="wsh-row" data-wsh-key="' . esc_attr( $key ) . '">';
		if ( $s['bulk'] ) {
			$h .= '<td class="wsh-c-sel">';
			if ( $direct ) {
				$h .= '<label class="wsh-sel"><input type="checkbox" name="wsh_sel[]" value="' . esc_attr( $key ) . '" aria-label="' . esc_attr( wp_strip_all_tags( $item->get_name() ) ) . '" /><span class="wsh-box" aria-hidden="true"></span></label>';
			}
			$h .= '</td>';
		}
		if ( $c['image'] ) {
			$h .= '<td class="wsh-c-img"><a href="' . esc_url( $url ) . '">' . $item->get_image( 'woocommerce_gallery_thumbnail' ) . '</a></td>';
		}
		$name = '<a href="' . esc_url( $url ) . '">' . esc_html( wp_strip_all_tags( $product->get_name() ) ) . '</a>';
		if ( $it['v'] ) {
			$attrs = wc_get_formatted_variation( $item, true, true, false );
			if ( '' !== $attrs ) {
				$name .= '<span class="wsh-var">' . esc_html( wp_strip_all_tags( $attrs ) ) . '</span>';
			}
		}
		if ( 'own' === $mode ) {
			$name .= self::move_control( $key );
		}
		$h .= '<td class="wsh-c-name" data-title="' . esc_attr( WSH_Settings::text( 't_product' ) ) . '">' . $name . '</td>';

		if ( $c['price'] ) {
			$price = $item->get_price_html();
			$drop  = '';
			if ( 'own' === $mode && $s['price_drop'] && null !== $it['price'] && '' !== (string) $item->get_price() ) {
				$now = (float) wc_get_price_to_display( $item );
				if ( $now < (float) $it['price'] - 0.005 ) {
					$amount = wp_strip_all_tags( wc_price( (float) $it['price'] - $now ) );
					$drop   = '<span class="wsh-drop">' . esc_html( str_replace( '{amount}', html_entity_decode( $amount, ENT_QUOTES, 'UTF-8' ), WSH_Settings::text( 't_drop' ) ) ) . '</span>';
				}
			}
			$h .= '<td class="wsh-c-price" data-title="' . esc_attr( WSH_Settings::text( 't_price' ) ) . '">' . wp_kses_post( $price ) . $drop . '</td>';
		}
		if ( $c['stock'] ) {
			$h .= '<td class="wsh-c-stock" data-title="' . esc_attr( WSH_Settings::text( 't_stock' ) ) . '">' . self::stock( $item ) . '</td>';
		}
		if ( $c['date'] && 'own' === $mode ) {
			$date = '';
			if ( '' !== $it['added'] ) {
				$ts   = strtotime( $it['added'] . ' UTC' );
				$date = $ts ? wp_date( (string) get_option( 'date_format' ), $ts ) : '';
			}
			$h .= '<td class="wsh-c-date" data-title="' . esc_attr( WSH_Settings::text( 't_date' ) ) . '">' . esc_html( (string) $date ) . '</td>';
		}
		if ( $c['qty'] ) {
			$h .= '<td class="wsh-c-qty" data-title="' . esc_attr( WSH_Settings::text( 't_qty' ) ) . '">';
			if ( $direct && ! $item->is_sold_individually() ) {
				$max = $item->get_max_purchase_quantity();
				$h  .= '<input type="number" class="input-text qty text" name="wsh_qty[' . esc_attr( $key ) . ']" value="1" min="1" step="1"' . ( $max > 0 ? ' max="' . (int) $max . '"' : '' ) . ' inputmode="numeric" aria-label="' . esc_attr( WSH_Settings::text( 't_qty' ) ) . '" />';
			}
			$h .= '</td>';
		}
		if ( $c['cart'] ) {
			$h .= '<td class="wsh-c-cart">';
			if ( $direct ) {
				$h .= '<button type="submit" name="wsh_add" value="' . esc_attr( $key ) . '" class="' . esc_attr( self::btn_class( 'wsh-add' ) ) . '">' . esc_html( WSH_Settings::text( 't_cart' ) ) . '</button>';
			} elseif ( $item->is_in_stock() || $product->is_type( 'variable' ) ) {
				$h .= '<a href="' . esc_url( $url ) . '" class="' . esc_attr( self::btn_class( 'wsh-opt' ) ) . '">' . esc_html( WSH_Settings::text( 't_options' ) ) . '</a>';
			}
			$h .= '</td>';
		}
		if ( 'shared' === $mode ) {
			$h .= '<td class="wsh-c-rem">' . WSH_Front::button( $it['p'], 'list' ) . '</td>';
		} elseif ( $c['remove'] ) {
			$label = WSH_Settings::text( 't_remove' ) . ': ' . wp_strip_all_tags( $item->get_name() );
			$h    .= '<td class="wsh-c-rem"><button type="submit" name="wsh_remove" value="' . esc_attr( $key ) . '" class="wsh-remove" data-wsh-remove="' . esc_attr( $key ) . '" aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '"><span aria-hidden="true">&times;</span></button></td>';
		}
		return $h . '</tr>';
	}

	/**
	 * "Move to another list" for a row of the own view (named lists only).
	 *
	 * @param string $key Item key.
	 */
	private static function move_control( string $key ): string {
		$lists = self::$own['lists'];
		if ( count( $lists ) < 2 ) {
			return '';
		}
		$h = '<span class="wsh-move"><select name="wsh_to[' . esc_attr( $key ) . ']" aria-label="' . esc_attr( WSH_Settings::text( 't_move' ) ) . '">';
		foreach ( $lists as $l ) {
			if ( $l['id'] !== self::$own['list'] ) {
				$h .= '<option value="' . (int) $l['id'] . '">' . esc_html( $l['name'] ) . '</option>';
			}
		}
		return $h . '</select><button type="submit" name="wsh_move" value="' . esc_attr( $key ) . '" class="wsh-link">' . esc_html( WSH_Settings::text( 't_move' ) ) . '</button></span>';
	}

	/**
	 * Stock line (WooCommerce's own texts).
	 *
	 * @param WC_Product $item Product or variation.
	 */
	private static function stock( WC_Product $item ): string {
		$html = wc_get_stock_html( $item );
		if ( '' !== trim( wp_strip_all_tags( $html ) ) ) {
			return wp_kses_post( $html );
		}
		$in = $item->is_in_stock();
		if ( $item->is_type( 'variable' ) ) {
			$in = $item->is_in_stock() || $item->child_is_in_stock();
		}
		$txt = $in ? __( 'In stock', 'woocommerce' ) : __( 'Out of stock', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WooCommerce's own text.
		return '<p class="stock ' . ( $in ? 'in-stock' : 'out-of-stock' ) . '">' . esc_html( $txt ) . '</p>';
	}

	/**
	 * Cards layout: the theme's own product cards (Bible §14.19).
	 *
	 * @param array $items Resolved rows.
	 */
	private static function cards( array $items ): string {
		$ids = array();
		foreach ( $items as $it ) {
			$ids[ $it['p'] ] = true;
		}
		$ids = array_keys( $ids );
		ob_start();
		wc_setup_loop(
			array(
				'name'         => 'wsh',
				'is_shortcode' => true,
				'is_paginated' => false,
				'total'        => count( $ids ),
				'per_page'     => count( $ids ),
			)
		);
		woocommerce_product_loop_start();
		$orig = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- the WooCommerce loop pattern.
			setup_postdata( $post );
			wc_get_template_part( 'content', 'product' );
		}
		woocommerce_product_loop_end();
		$GLOBALS['post'] = $orig; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		wp_reset_postdata();
		wc_reset_loop();
		return '<div class="wsh-cards woocommerce">' . (string) ob_get_clean() . '</div>';
	}

	/**
	 * Bulk actions under the list.
	 *
	 * @param array  $items Resolved rows.
	 * @param string $mode  View.
	 */
	private static function actions( array $items, string $mode ): string {
		$s = WSH_Settings::get();
		if ( ! $s['bulk'] || 'cards' === $s['layout'] ) {
			if ( 'cards' === $s['layout'] && $s['bulk'] && 'shared' !== $mode ) {
				return '<div class="wsh-actions"><button type="submit" name="wsh_clear" value="1" class="wsh-clear" data-wsh-clear>' . esc_html( WSH_Settings::text( 't_clear' ) ) . '</button></div>';
			}
			return '';
		}
		$any = false;
		foreach ( $items as $it ) {
			if ( self::direct_cart( $it['item'] ) ) {
				$any = true;
				break;
			}
		}
		$h = '<div class="wsh-actions">';
		if ( $any ) {
			$h .= '<button type="submit" name="wsh_bulk" value="all" class="' . esc_attr( self::btn_class( 'wsh-all' ) ) . '">' . esc_html( WSH_Settings::text( 't_all' ) ) . '</button>';
			$h .= '<button type="submit" name="wsh_bulk" value="selected" class="' . esc_attr( self::btn_class( 'wsh-selected' ) ) . '" data-wsh-selected>' . esc_html( WSH_Settings::text( 't_selected' ) ) . '</button>';
		}
		if ( 'shared' !== $mode ) {
			$h .= '<button type="submit" name="wsh_clear" value="1" class="wsh-clear" data-wsh-clear>' . esc_html( WSH_Settings::text( 't_clear' ) ) . '</button>';
		}
		return $h . '</div>';
	}

	/**
	 * Share bar. A guest's link is built by front.js from the browser's list;
	 * a customer's link carries the token (none = private).
	 *
	 * @param string $mode 'own' | 'guest'.
	 */
	private static function share_bar( string $mode ): string {
		$list = 'own' === $mode ? (int) self::$own['list'] : 0;
		$h    = '<div class="wsh-share" data-wsh-share-bar data-wsh-list="' . $list . '">';
		$h   .= '<span class="wsh-share__label">' . esc_html( WSH_Settings::text( 't_share' ) ) . '</span>';
		if ( 'own' === $mode ) {
			$token = WSH_Store::token( get_current_user_id(), $list );
			$url   = '' !== $token ? self::share_url( $token ) : '';
			$h    .= '<input type="text" class="wsh-share__url" readonly value="' . esc_attr( $url ) . '" data-wsh-share-url' . ( '' === $url ? ' hidden' : '' ) . ' aria-label="' . esc_attr( WSH_Settings::text( 't_share' ) ) . '" />';
			$h    .= '<button type="button" class="wsh-link" data-wsh-copy' . ( '' === $url ? ' hidden' : '' ) . '>' . esc_html( WSH_Settings::text( 't_copy' ) ) . '</button>';
			$h    .= '<button type="button" class="wsh-link" data-wsh-native hidden>' . esc_html( WSH_Settings::text( 't_share' ) ) . '</button>';
			$h    .= '<button type="button" class="wsh-link" data-wsh-token="new">' . esc_html( '' === $url ? WSH_Settings::text( 't_share' ) : WSH_Settings::text( 't_share_new' ) ) . '</button>';
			$h    .= '<button type="button" class="wsh-link" data-wsh-token="off"' . ( '' === $url ? ' hidden' : '' ) . '>' . esc_html( WSH_Settings::text( 't_share_off' ) ) . '</button>';
		} else {
			$h .= '<input type="text" class="wsh-share__url" readonly value="" data-wsh-share-url data-wsh-guest-url aria-label="' . esc_attr( WSH_Settings::text( 't_share' ) ) . '" />';
			$h .= '<button type="button" class="wsh-link" data-wsh-copy>' . esc_html( WSH_Settings::text( 't_copy' ) ) . '</button>';
			$h .= '<button type="button" class="wsh-link" data-wsh-native hidden>' . esc_html( WSH_Settings::text( 't_share' ) ) . '</button>';
		}
		return $h . '</div>';
	}

	/** A customer's share URL. */
	public static function share_url( string $token ): string {
		$base = WSH_Settings::page_id() ? (string) get_permalink( WSH_Settings::page_id() ) : '';
		return '' === $base ? '' : add_query_arg( 'wsh', rawurlencode( $token ), $base );
	}

	/* =====================================================================
	 * Form: add to cart, remove, empty (POST, nonce)
	 * =================================================================== */

	public static function handle_form(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below.
		if ( empty( $_POST['wsh_form'] ) || ! isset( $_POST['wsh_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wsh_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		try {
			if ( ! WSH_Front::active() || ! function_exists( 'WC' ) || ! WC()->cart ) {
				return;
			}
			$uid  = get_current_user_id();
			$keys = isset( $_POST['wsh_keys'] ) && is_array( $_POST['wsh_keys'] ) ? self::parse_keys( array_map( 'strval', wp_unslash( $_POST['wsh_keys'] ) ) ) : array();
			$done = array();
			// The list of the form (named lists); null = every list.
			$list = null;
			if ( $uid && self::lists_on() ) {
				$list = isset( $_POST['wsh_list_id'] ) && is_scalar( $_POST['wsh_list_id'] ) ? absint( $_POST['wsh_list_id'] ) : 0;
				$list = WSH_Store::owns_list( $uid, $list ) ? $list : 0;
			}

			// Email alerts opt-in (signed-in customers).
			if ( $uid && isset( $_POST['wsh_alerts_save'] ) ) {
				if ( WSH_Store::rate_ok( $uid ) ) {
					WSH_Alerts::set_optin( $uid, ! empty( $_POST['wsh_alerts'] ) );
				}
				self::back( array() );
			}

			// Named lists: create, rename, delete.
			if ( $uid && null !== $list && isset( $_POST['wsh_list_op'] ) && is_string( $_POST['wsh_list_op'] ) ) {
				$op   = sanitize_key( wp_unslash( $_POST['wsh_list_op'] ) );
				$name = isset( $_POST['wsh_list_name'] ) && is_string( $_POST['wsh_list_name'] ) ? wp_unslash( $_POST['wsh_list_name'] ) : '';
				$to   = $list;
				if ( WSH_Store::rate_ok( $uid ) ) {
					if ( 'create' === $op ) {
						$new = WSH_Store::list_create( $uid, $name );
						$to  = $new ? $new : $list;
					} elseif ( 'rename' === $op ) {
						WSH_Store::list_rename( $uid, $list, $name );
					} elseif ( 'delete' === $op && WSH_Store::list_delete( $uid, $list ) ) {
						$to = 0;
					}
				}
				self::redirect( self::back_url( $to ) );
			}

			// Move an item to another of the customer's lists.
			if ( $uid && null !== $list && isset( $_POST['wsh_move'] ) && is_string( $_POST['wsh_move'] ) ) {
				$pair = self::parse_keys( array( wp_unslash( $_POST['wsh_move'] ) ) );
				$tos  = isset( $_POST['wsh_to'] ) && is_array( $_POST['wsh_to'] ) ? wp_unslash( $_POST['wsh_to'] ) : array();
				if ( $pair && WSH_Store::rate_ok( $uid ) ) {
					$k = self::key( $pair[0][0], $pair[0][1] );
					if ( isset( $tos[ $k ] ) && is_scalar( $tos[ $k ] ) ) {
						WSH_Store::move( $uid, $pair[0][0], $pair[0][1], absint( $tos[ $k ] ) );
					}
				}
				self::back( array() );
			}

			if ( isset( $_POST['wsh_remove'] ) && is_string( $_POST['wsh_remove'] ) ) {
				$pair = self::parse_keys( array( wp_unslash( $_POST['wsh_remove'] ) ) );
				if ( $uid && $pair && WSH_Store::rate_ok( $uid ) ) {
					WSH_Store::remove( $uid, $pair[0][0], $pair[0][1] );
				}
				if ( $pair ) {
					$done[] = self::key( $pair[0][0], $pair[0][1] );
				}
				self::back( $done );
			}

			if ( isset( $_POST['wsh_clear'] ) ) {
				if ( $uid && WSH_Store::rate_ok( $uid ) ) {
					WSH_Store::clear( $uid, $list );
				}
				foreach ( $keys as $pv ) {
					$done[] = self::key( $pv[0], $pv[1] );
				}
				self::back( $done );
			}

			$targets = array();
			if ( isset( $_POST['wsh_add'] ) && is_string( $_POST['wsh_add'] ) ) {
				$targets = self::parse_keys( array( wp_unslash( $_POST['wsh_add'] ) ) );
			} elseif ( isset( $_POST['wsh_bulk'] ) && 'selected' === $_POST['wsh_bulk'] ) {
				$targets = isset( $_POST['wsh_sel'] ) && is_array( $_POST['wsh_sel'] ) ? self::parse_keys( array_map( 'strval', wp_unslash( $_POST['wsh_sel'] ) ) ) : array();
			} elseif ( isset( $_POST['wsh_bulk'] ) && 'all' === $_POST['wsh_bulk'] ) {
				$targets = $keys;
			}
			if ( ! $targets ) {
				return;
			}
			$qtys = isset( $_POST['wsh_qty'] ) && is_array( $_POST['wsh_qty'] ) ? wp_unslash( $_POST['wsh_qty'] ) : array();
			// phpcs:enable

			$added   = array();
			$skipped = false;
			foreach ( $targets as $pv ) {
				$key  = self::key( $pv[0], $pv[1] );
				$pair = WSH_Store::valid_pair( $pv[0], $pv[1] );
				$item = $pair ? wc_get_product( $pair[1] ? $pair[1] : $pair[0] ) : null;
				if ( ! $item || ! self::direct_cart( $item ) ) {
					$skipped = true;
					continue;
				}
				$qty = isset( $qtys[ $key ] ) && is_scalar( $qtys[ $key ] ) ? (int) $qtys[ $key ] : 1;
				$qty = $item->is_sold_individually() ? 1 : max( 1, min( 999, $qty ) );
				$ok  = false;
				if ( $pair[1] ) {
					if ( apply_filters( 'woocommerce_add_to_cart_validation', true, $pair[0], $qty, $pair[1], $item->get_variation_attributes() ) ) {
						$ok = WC()->cart->add_to_cart( $pair[0], $qty, $pair[1], $item->get_variation_attributes() );
					}
				} elseif ( apply_filters( 'woocommerce_add_to_cart_validation', true, $pair[0], $qty ) ) {
					$ok = WC()->cart->add_to_cart( $pair[0], $qty );
				}
				if ( ! $ok ) {
					$skipped = true;
					continue;
				}
				$id           = $pair[1] ? $pair[1] : $pair[0];
				$added[ $id ] = isset( $added[ $id ] ) ? $added[ $id ] + $qty : $qty;
				self::mark_session( $pair[0], $pair[1] );
				if ( WSH_Settings::get()['remove_after'] ) {
					if ( $uid ) {
						WSH_Store::remove( $uid, $pair[0], $pair[1] );
					}
					$done[] = $key;
				}
			}
			if ( $added ) {
				wc_add_to_cart_message( $added, true );
			}
			if ( $skipped ) {
				wc_add_notice( WSH_Settings::text( 't_skipped' ), 'notice' );
			}
			if ( $added && 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ) ) {
				self::redirect( $done ? add_query_arg( 'wsh_done', rawurlencode( implode( ',', $done ) ), wc_get_cart_url() ) : wc_get_cart_url() );
			}
			self::back( $done );
		} catch ( \Throwable $e ) {
			WSH_Front::fail();
		}
	}

	/**
	 * PRG back to the page of the form, telling front.js which items left
	 * the list (guests remove them from the browser list).
	 *
	 * @param string[] $done Keys.
	 */
	private static function back( array $done ): void {
		$url = self::back_url( null );
		self::redirect( $done ? add_query_arg( 'wsh_done', rawurlencode( implode( ',', $done ) ), $url ) : $url );
	}

	/**
	 * The page of the form, without wsh_done; with ?wsh_list= set when $list is given.
	 *
	 * @param int|null $list List to show (0 = main), or null to keep the referer's.
	 */
	private static function back_url( ?int $list ): string {
		$url = wp_get_referer();
		if ( ! $url ) {
			$url = WSH_Settings::list_url();
		}
		if ( ! $url ) {
			$url = home_url( '/' );
		}
		$url = remove_query_arg( 'wsh_done', $url );
		if ( null !== $list ) {
			$url = $list ? add_query_arg( 'wsh_list', $list, $url ) : remove_query_arg( 'wsh_list', $url );
		}
		return $url;
	}

	/**
	 * @param string $url Target.
	 */
	private static function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/* =====================================================================
	 * Bought from a list
	 * =================================================================== */

	/**
	 * Remembers in the WooCommerce session that this product went to the
	 * cart from a list (the cart session already exists at this point).
	 *
	 * @param int $p Product id.
	 * @param int $v Variation id.
	 */
	private static function mark_session( int $p, int $v ): void {
		if ( ! WC()->session ) {
			return;
		}
		$marks = WC()->session->get( 'wsh_from' );
		$marks = is_array( $marks ) ? $marks : array();
		$marks[ self::key( $p, $v ) ] = 1;
		WC()->session->set( 'wsh_from', array_slice( $marks, -200, null, true ) );
	}

	/**
	 * Copies the mark to the order line (hidden meta _wsh_from).
	 *
	 * @param WC_Order_Item_Product $item          Order line.
	 * @param string                $cart_item_key Cart key.
	 * @param array                 $values        Cart item.
	 */
	public static function mark_line( $item, $cart_item_key, $values ): void {
		try {
			if ( ! function_exists( 'WC' ) || ! WC()->session || ! $item instanceof WC_Order_Item_Product ) {
				return;
			}
			$marks = WC()->session->get( 'wsh_from' );
			if ( ! is_array( $marks ) || ! $marks ) {
				return;
			}
			$key = self::key( (int) $item->get_product_id(), (int) $item->get_variation_id() );
			if ( isset( $marks[ $key ] ) || isset( $marks[ (string) $item->get_product_id() ] ) ) {
				$item->add_meta_data( '_wsh_from', 1, true );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Paid order: +1 to _wsh_bought of every product bought from a list
	 * (once per order, flag _wsh_counted).
	 *
	 * @param int $order_id Order id.
	 */
	public static function count_bought( $order_id ): void {
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order || $order->get_meta( '_wsh_counted' ) ) {
				return;
			}
			$any = false;
			foreach ( $order->get_items() as $item ) {
				if ( $item instanceof WC_Order_Item_Product && $item->get_meta( '_wsh_from' ) ) {
					$p = (int) $item->get_product_id();
					if ( $p > 0 ) {
						update_post_meta( $p, '_wsh_bought', (int) get_post_meta( $p, '_wsh_bought', true ) + 1 );
						$any = true;
					}
				}
			}
			if ( $any ) {
				$order->update_meta_data( '_wsh_counted', 1 );
				$order->save_meta_data();
			}
			if ( WC()->session ) {
				WC()->session->set( 'wsh_from', null );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * @param array $keys Hidden order item meta keys.
	 */
	public static function hidden_meta( $keys ) {
		if ( is_array( $keys ) ) {
			$keys[] = '_wsh_from';
		}
		return $keys;
	}
}

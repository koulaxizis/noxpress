<?php
/**
 * EVT_Tickets — paid (or free) tickets through WooCommerce (1.1.0, Π22–Π31).
 *
 *  - A ticket is a normal WooCommerce product linked to the event (event
 *    meta _evt_ticket = product id, product meta _evt_event = event id).
 *    Ticket types (full, reduced, student) are variations. Seats = stock:
 *    WooCommerce holds stock for orders waiting for payment and gives it
 *    back on cancellation or failure (Π23). The product is hidden from the
 *    catalogue; its own page redirects to the event.
 *  - Sales window (Π24): from the optional «sales start» of the event to
 *    close_h hours before its start. Outside it, and for a cancelled or
 *    postponed event, the product is not purchasable on the storefront and
 *    in the Store API; adding to the cart and the checkout refuse it too
 *    (Bible §14.42: three levels on the server, never only the button).
 *    Never in WP admin, cron or REST with a user (orders made by staff).
 *  - Codes (Π25): when an order becomes processing or completed, each
 *    ticket gets a row with a derived code (EVT_Ticket_Store). Refunds and
 *    cancellations void the extra tickets (unused ones first).
 *  - Delivery (Π26): the codes and a link to the ticket page under each
 *    order item (thank-you page, order emails, My account), and the
 *    «Tickets» tab in My account. The ticket page (?evt_tk=<order>&k=<HMAC>)
 *    works without logging in, shows a QR code drawn in the browser, is
 *    never cached and is noindex.
 *  - Attendee names (Π27, per event, off by default): typed on the ticket
 *    page, deleted names_days after the event, in the privacy exporter and
 *    eraser.
 *  - Schema offers from the product (Π30), page cache purge of the event
 *    when the stock changes.
 */

defined( 'ABSPATH' ) || exit;

final class EVT_Tickets {

	const ENDPOINT = 'evt-tickets';
	const DAILY    = 'evt_daily';
	const PAID     = array( 'processing', 'completed' );
	const DEAD     = array( 'cancelled', 'refunded', 'failed' );

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'endpoint' ) );
		add_action( 'admin_init', array( 'EVT_Ticket_Store', 'maybe_upgrade' ) );

		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'purchasable' ), 50, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'purchasable' ), 50, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'add_validation' ), 50, 3 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart' ) );
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'store_api_errors' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'cart_redirect' ), 20, 2 );

		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'status_changed' ), 20, 3 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'sync' ), 20 );
		add_action( 'woocommerce_order_item_meta_end', array( __CLASS__, 'item_codes' ), 20, 4 );

		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu' ), 30 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'account_page' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );

		if ( ! Events_Plugin::killed() ) {
			add_action( 'template_redirect', array( __CLASS__, 'ticket_page' ), 4 );
			add_action( 'template_redirect', array( __CLASS__, 'product_redirect' ), 6 );
		}

		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'stock_changed' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'stock_changed' ) );
		add_filter( 'evt_schema', array( __CLASS__, 'schema' ), 5, 2 );

		add_action( self::DAILY, array( __CLASS__, 'daily' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule_daily' ) );

		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
	}

	/* =====================================================================
	 * Links between events and products
	 * =================================================================== */

	/** The published ticket product of an event, or null. */
	public static function product_of( int $event_id ): ?WC_Product {
		$pid = (int) get_post_meta( $event_id, '_evt_ticket', true );
		if ( $pid <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$p = wc_get_product( $pid );
		if ( ! $p || 'publish' !== $p->get_status() || (int) $p->get_meta( '_evt_event' ) !== $event_id ) {
			return null;
		}
		return $p;
	}

	/** The event of a ticket product (or of its variation), 0 when it is not a ticket. */
	public static function event_of( $product ): int {
		if ( ! $product instanceof WC_Product ) {
			return 0;
		}
		$p   = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
		$eid = $p ? (int) $p->get_meta( '_evt_event' ) : 0;
		if ( $eid <= 0 || EVT_CPT::PT !== get_post_type( $eid ) || (int) get_post_meta( $eid, '_evt_ticket', true ) !== (int) $p->get_id() ) {
			return 0;
		}
		return $eid;
	}

	/** Links a product to an event both ways (one ticket product per event). */
	public static function link( int $event_id, int $product_id ): void {
		$old = (int) get_post_meta( $event_id, '_evt_ticket', true );
		if ( $old > 0 && $old !== $product_id ) {
			$op = wc_get_product( $old );
			if ( $op && (int) $op->get_meta( '_evt_event' ) === $event_id ) {
				$op->delete_meta_data( '_evt_event' );
				$op->save();
			}
		}
		if ( $product_id <= 0 ) {
			delete_post_meta( $event_id, '_evt_ticket' );
			return;
		}
		$p = wc_get_product( $product_id );
		if ( ! $p || $p->get_parent_id() ) {
			return;
		}
		$prev = (int) $p->get_meta( '_evt_event' );
		if ( $prev > 0 && $prev !== $event_id ) {
			delete_post_meta( $prev, '_evt_ticket' ); // A product is the ticket of one event only.
		}
		$p->update_meta_data( '_evt_event', $event_id );
		$p->save();
		update_post_meta( $event_id, '_evt_ticket', $product_id );
	}

	/** Creates a hidden virtual ticket product for an event (Π22). */
	public static function create_product( int $event_id, string $price, int $seats ): int {
		if ( ! class_exists( 'WC_Product_Simple' ) || ! current_user_can( 'publish_products' ) ) {
			return 0;
		}
		$p = new WC_Product_Simple();
		/* translators: %s: event title */
		$p->set_name( sprintf( __( 'Εισιτήριο: %s', 'events' ), wp_strip_all_tags( get_the_title( $event_id ) ) ) );
		$p->set_status( 'publish' );
		$p->set_virtual( true );
		$p->set_catalog_visibility( 'hidden' );
		$p->set_regular_price( wc_format_decimal( $price ) );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( max( 0, $seats ) );
		$p->set_backorders( 'no' );
		$p->set_reviews_allowed( false );
		$p->update_meta_data( '_evt_event', $event_id );
		$id = (int) $p->save();
		if ( $id > 0 ) {
			self::link( $event_id, $id );
		}
		return $id;
	}

	/* =====================================================================
	 * Sales window (Π24)
	 * =================================================================== */

	/** A storefront or Store API request (never WP admin, cron or REST with a user). */
	private static function storefront(): bool {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only compared.
			return false !== strpos( $uri, '/wc/store/' ) || false !== strpos( $uri, 'wc%2Fstore' ) || false !== strpos( $uri, 'rest_route=/wc/store' );
		}
		if ( is_admin() ) {
			if ( ! wp_doing_ajax() ) {
				return false;
			}
			$ref = (string) wp_get_referer();
			return '' === $ref || 0 !== strpos( $ref, admin_url() );
		}
		return true;
	}

	/**
	 * 'open' | 'soon' | 'closed' | 'sold_out' | 'off' for an event's tickets.
	 *
	 * @param array      $e Event (EVT_CPT::event()).
	 * @param WC_Product $p The ticket product.
	 */
	public static function state( array $e, WC_Product $p ): string {
		$mode = EVT_Settings::mode();
		if ( 'off' === $mode || Events_Plugin::killed() || ( 'test' === $mode && ! current_user_can( 'edit_posts' ) ) ) {
			return 'off';
		}
		$now   = time();
		$close = $e['start'] - (int) EVT_Settings::get()['close_h'] * HOUR_IN_SECONDS;
		if ( in_array( $e['status'], array( 'cancelled', 'postponed' ), true ) || $e['ended'] || $e['start'] <= 0 || $now >= $close ) {
			return 'closed';
		}
		$from = (int) get_post_meta( $e['id'], '_evt_sales_from', true );
		if ( $from > $now ) {
			return 'soon';
		}
		return $p->is_in_stock() ? 'open' : 'sold_out';
	}

	/** Why a ticket product cannot be sold now ('' = it can). */
	private static function refusal( WC_Product $product ): string {
		$eid = self::event_of( $product );
		if ( ! $eid ) {
			return '';
		}
		$e      = EVT_CPT::event( $eid );
		$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
		if ( ! $e || ! $parent ) {
			return EVT_Settings::text( 'sales_closed' );
		}
		$st = self::state( $e, $parent );
		if ( 'open' === $st || 'sold_out' === $st ) {
			return ''; // Stock itself is WooCommerce's job.
		}
		return EVT_Settings::text( 'soon' === $st ? 'sales_soon' : 'sales_closed' );
	}

	public static function purchasable( $ok, $product ) {
		if ( ! $ok || ! $product instanceof WC_Product || ! self::storefront() ) {
			return $ok;
		}
		try {
			return '' === self::refusal( $product );
		} catch ( \Throwable $e ) {
			return false; // Fail closed: a ticket is never sold outside its window.
		}
	}

	public static function add_validation( $ok, $product_id, $qty = 1 ) {
		if ( ! $ok || ! self::storefront() ) {
			return $ok;
		}
		$p = wc_get_product( absint( $product_id ) );
		$r = $p ? self::refusal( $p ) : '';
		if ( '' !== $r ) {
			wc_add_notice( $r, 'error' );
			return false;
		}
		return $ok;
	}

	/** Checkout level: a ticket whose window closed while it sat in the cart. */
	public static function check_cart(): void {
		if ( ! self::storefront() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$p = isset( $item['data'] ) && $item['data'] instanceof WC_Product ? $item['data'] : null;
			$r = $p ? self::refusal( $p ) : '';
			if ( '' !== $r ) {
				wc_add_notice( wp_strip_all_tags( $p->get_name() ) . ': ' . $r, 'error' );
			}
		}
	}

	public static function store_api_errors( $errors, $cart ): void {
		if ( ! $errors instanceof WP_Error || ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			$p = isset( $item['data'] ) && $item['data'] instanceof WC_Product ? $item['data'] : null;
			$r = $p ? self::refusal( $p ) : '';
			if ( '' !== $r ) {
				$errors->add( 'evt_ticket_closed', wp_strip_all_tags( $p->get_name() ) . ': ' . $r );
			}
		}
	}

	/** After «Buy a ticket» on the event page: straight to the cart. */
	public static function cart_redirect( $url, $product = null ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce's own add-to-cart request.
		$pid = isset( $_REQUEST['add-to-cart'] ) ? absint( $_REQUEST['add-to-cart'] ) : 0;
		$p   = $product instanceof WC_Product ? $product : ( $pid ? wc_get_product( $pid ) : null );
		return $p && self::event_of( $p ) ? wc_get_cart_url() : $url;
	}

	/* =====================================================================
	 * Codes (Π25)
	 * =================================================================== */

	public static function status_changed( $order_id, $from, $to ): void {
		if ( in_array( $to, self::PAID, true ) || in_array( $to, self::DEAD, true ) ) {
			self::sync( (int) $order_id );
		}
	}

	/**
	 * Brings the ticket rows of an order to what it paid for: quantity minus
	 * refunded quantity per item while it is paid, nothing once it is
	 * cancelled, refunded or failed.
	 */
	public static function sync( $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
			return;
		}
		$paid = in_array( $order->get_status(), self::PAID, true );
		$dead = in_array( $order->get_status(), self::DEAD, true );
		if ( ! $paid && ! $dead ) {
			return; // Pending / on hold: no tickets yet, nothing to void.
		}
		$rows = array();
		foreach ( EVT_Ticket_Store::for_order( $order->get_id() ) as $r ) {
			$rows[ $r['item_id'] ][] = $r;
		}
		$made = 0;
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$p   = $item->get_product();
			$eid = $p ? self::event_of( $p ) : 0;
			$mine = $rows[ $item_id ] ?? array();
			if ( ! $eid && ! $mine ) {
				continue;
			}
			$want = $paid ? max( 0, (int) $item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( $item_id ) ) ) : 0;
			$valid = array_values(
				array_filter(
					$mine,
					static function ( $r ) {
						return 'valid' === $r['status'];
					}
				)
			);
			if ( count( $valid ) > $want ) {
				// Void the extra ones: unused first, newest first.
				usort(
					$valid,
					static function ( $a, $b ) {
						$ua = null === $a['used'] ? 0 : 1;
						$ub = null === $b['used'] ? 0 : 1;
						return $ua === $ub ? $b['seq'] <=> $a['seq'] : $ua <=> $ub;
					}
				);
				foreach ( array_slice( $valid, 0, count( $valid ) - $want ) as $r ) {
					EVT_Ticket_Store::set_status( $r['id'], 'void' );
				}
				continue;
			}
			$need = $want - count( $valid );
			if ( $need <= 0 || ! $eid ) {
				continue;
			}
			// An order paid again after a cancellation gets its old codes back first.
			foreach ( $mine as $r ) {
				if ( $need > 0 && 'void' === $r['status'] ) {
					EVT_Ticket_Store::set_status( $r['id'], 'valid' );
					--$need;
				}
			}
			$seq = $mine ? max( array_column( $mine, 'seq' ) ) : 0;
			$pid = $item->get_variation_id() ? (int) $item->get_variation_id() : (int) $item->get_product_id();
			for ( $i = 0; $i < $need; $i++ ) {
				if ( EVT_Ticket_Store::add( $eid, $pid, $order->get_id(), (int) $item_id, ++$seq, (int) $order->get_customer_id() ) ) {
					++$made;
				}
			}
		}
		if ( $made > 0 ) {
			/* translators: %d: number of tickets */
			$order->add_order_note( sprintf( __( 'Events: εισιτήρια που εκδόθηκαν: %d.', 'events' ), $made ) );
		}
	}

	/* =====================================================================
	 * Delivery (Π26)
	 * =================================================================== */

	/** The ticket page of an order (no login: HMAC of the order and its key). */
	public static function page_url( WC_Order $order ): string {
		return add_query_arg(
			array(
				'evt_tk' => $order->get_id(),
				'k'      => self::page_key( $order ),
			),
			home_url( '/' )
		);
	}

	private static function page_key( WC_Order $order ): string {
		return substr( EVT_Ticket_Store::mac( 'page', $order->get_id() . '|' . $order->get_order_key() ), 0, 32 );
	}

	private static function status_text( array $r ): string {
		if ( 'void' === $r['status'] ) {
			return EVT_Settings::text( 'void' );
		}
		if ( null !== $r['used'] ) {
			return EVT_Settings::text( 'used' ) . ' ' . wp_date( (string) get_option( 'time_format' ), strtotime( $r['used'] . ' UTC' ) );
		}
		return '';
	}

	/** Codes under a ticket item: thank-you page, emails, My account → order. */
	public static function item_codes( $item_id, $item, $order, $plain = false ): void {
		if ( ! $order instanceof WC_Order || ! in_array( $order->get_status(), self::PAID, true ) ) {
			return;
		}
		$rows = array_filter(
			EVT_Ticket_Store::for_order( $order->get_id() ),
			static function ( $r ) use ( $item_id ) {
				return (int) $item_id === $r['item_id'];
			}
		);
		if ( ! $rows ) {
			return;
		}
		$lang = self::order_lang( $order );
		$codes = array();
		foreach ( $rows as $r ) {
			$st      = self::status_text( $r );
			$codes[] = EVT_Ticket_Store::pretty( EVT_Ticket_Store::code_for( $r ) ) . ( '' !== $st ? ' (' . $st . ')' : '' );
		}
		$label = EVT_Settings::text( 'ticket_code', $lang );
		$link  = self::page_url( $order );
		if ( $plain ) {
			echo "\n" . esc_html( $label ) . ': ' . esc_html( implode( ', ', $codes ) ) . "\n" . esc_html( EVT_Settings::text( 'show_tickets', $lang ) ) . ': ' . esc_url_raw( $link ) . "\n";
			return;
		}
		echo '<div class="evt-codes" style="margin-top:.5em;"><strong>' . esc_html( $label ) . ':</strong> <code>' . implode( '</code>, <code>', array_map( 'esc_html', $codes ) ) . '</code><br />';
		echo '<a href="' . esc_url( $link ) . '">' . esc_html( EVT_Settings::text( 'show_tickets', $lang ) ) . '</a></div>';
	}

	/** Visitor language of an order: the setting, or the site language. */
	private static function order_lang( WC_Order $order ): string {
		$l = (string) $order->get_meta( 'wpml_language' );
		if ( 'el' === $l || 'en' === $l ) {
			return $l;
		}
		return EVT_Settings::visitor_lang();
	}

	public static function endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public static function query_vars( $vars ): array {
		$vars                   = is_array( $vars ) ? $vars : array();
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	public static function account_menu( $items ): array {
		$items = is_array( $items ) ? $items : array();
		if ( ! is_user_logged_in() || ! EVT_Ticket_Store::for_user( get_current_user_id(), 1 ) ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $k => $v ) {
			if ( 'customer-logout' === $k ) {
				$out[ self::ENDPOINT ] = EVT_Settings::text( 'my_tickets' );
			}
			$out[ $k ] = $v;
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = EVT_Settings::text( 'my_tickets' );
		}
		return $out;
	}

	public static function account_page(): void {
		$by = array();
		foreach ( EVT_Ticket_Store::for_user( get_current_user_id(), 300 ) as $r ) {
			$by[ $r['order_id'] ][] = $r;
		}
		if ( ! $by ) {
			echo '<p>' . esc_html( EVT_Settings::text( 'no_tickets' ) ) . '</p>';
			return;
		}
		echo '<table class="shop_table evt-account"><tbody>';
		foreach ( $by as $oid => $rows ) {
			$order = wc_get_order( $oid );
			if ( ! $order || (int) $order->get_customer_id() !== get_current_user_id() ) {
				continue;
			}
			$events = array();
			foreach ( $rows as $r ) {
				if ( 'valid' === $r['status'] ) {
					$events[ $r['event_id'] ] = ( $events[ $r['event_id'] ] ?? 0 ) + 1;
				}
			}
			foreach ( $events as $eid => $n ) {
				$e = EVT_CPT::event( (int) $eid, true );
				echo '<tr><td>' . esc_html( $e ? $e['title'] : '#' . $eid ) . '<br /><small>' . esc_html( $e ? EVT_CPT::when( $e ) : '' ) . '</small></td>';
				echo '<td>× ' . (int) $n . '</td>';
				echo '<td><a class="button" href="' . esc_url( self::page_url( $order ) ) . '">' . esc_html( EVT_Settings::text( 'show_tickets' ) ) . '</a></td></tr>';
			}
		}
		echo '</tbody></table>';
	}

	/** The ticket product's own page shows the event instead. */
	public static function product_redirect(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$p   = wc_get_product( get_queried_object_id() );
		$eid = $p ? self::event_of( $p ) : 0;
		if ( $eid && EVT_CPT::is_public( $eid ) && ! is_preview() ) {
			wp_safe_redirect( (string) get_permalink( $eid ), 302 );
			exit;
		}
	}

	/** ?evt_tk=<order>&k=<key>: the tickets of an order, with QR codes. */
	public static function ticket_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- the HMAC key authorises; the names form has its own nonce.
		if ( ! isset( $_GET['evt_tk'] ) ) {
			return;
		}
		$oid   = absint( $_GET['evt_tk'] );
		$k     = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['k'] ) ) : '';
		$order = $oid ? wc_get_order( $oid ) : null;
		nocache_headers();
		header( 'Cache-Control: no-store, private' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || '' === $k || ! hash_equals( self::page_key( $order ), $k ) ) {
			status_header( 404 );
			self::page_shell( '', '<p>' . esc_html( EVT_Settings::text( 'no_tickets' ) ) . '</p>' );
			exit;
		}
		$rows = EVT_Ticket_Store::for_order( $order->get_id() );

		if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '' ) && isset( $_POST['evt_names_nonce'] ) ) {
			if ( wp_verify_nonce( sanitize_key( wp_unslash( $_POST['evt_names_nonce'] ) ), 'evt_names_' . $order->get_id() ) ) {
				$names = isset( $_POST['evt_name'] ) && is_array( $_POST['evt_name'] ) ? wp_unslash( $_POST['evt_name'] ) : array();
				foreach ( $rows as $r ) {
					if ( isset( $names[ $r['id'] ] ) && is_scalar( $names[ $r['id'] ] ) && self::names_open( $r ) ) {
						EVT_Ticket_Store::set_name( $r['id'], (string) $names[ $r['id'] ] );
					}
				}
			}
			wp_safe_redirect( add_query_arg( 'saved', '1', self::page_url( $order ) ) );
			exit;
		}
		// phpcs:enable

		$lang = self::order_lang( $order );
		$by   = array();
		foreach ( $rows as $r ) {
			$by[ $r['event_id'] ][] = $r;
		}
		$h    = '';
		$form = false;
		foreach ( $by as $eid => $list ) {
			$e  = EVT_CPT::event( (int) $eid, true );
			$h .= '<section class="evt-tk-event"><h2>' . esc_html( $e ? $e['title'] : '#' . $eid ) . '</h2>';
			if ( $e ) {
				$where = EVT_Front::where_short( $e );
				$h    .= '<p class="evt-tk-when">' . esc_html( EVT_CPT::when( $e ) . ( '' !== $where ? ' · ' . $where : '' ) ) . '</p>';
				if ( 'cancelled' === $e['status'] || 'postponed' === $e['status'] ) {
					$h .= '<p class="evt-tk-banner">' . esc_html( EVT_Settings::text( $e['status'], $lang ) ) . '</p>';
				}
			}
			foreach ( $list as $r ) {
				$code = EVT_Ticket_Store::code_for( $r );
				$st   = self::status_text( $r );
				$live = 'valid' === $r['status'];
				$h   .= '<div class="evt-tk' . ( $live ? '' : ' evt-tk--off' ) . '">';
				if ( $live && null === $r['used'] ) {
					$h .= '<canvas class="evt-qr" data-evt-qr="' . esc_attr( $code ) . '" role="img" aria-label="' . esc_attr( EVT_Settings::text( 'ticket_code', $lang ) . ' ' . $code ) . '"></canvas>';
				}
				$h .= '<p class="evt-tk-code">' . esc_html( EVT_Settings::text( 'ticket_code', $lang ) ) . '<br /><strong>' . esc_html( EVT_Ticket_Store::pretty( $code ) ) . '</strong></p>';
				if ( '' !== $st ) {
					$h .= '<p class="evt-tk-status">' . esc_html( $st ) . '</p>';
				}
				if ( self::names_open( $r ) ) {
					$form = true;
					$h   .= '<label class="evt-tk-name">' . esc_html( EVT_Settings::text( 'attendee', $lang ) ) . '<input type="text" name="evt_name[' . (int) $r['id'] . ']" value="' . esc_attr( $r['name'] ) . '" maxlength="120" autocomplete="name" /></label>';
				} elseif ( '' !== $r['name'] ) {
					$h .= '<p class="evt-tk-name">' . esc_html( $r['name'] ) . '</p>';
				}
				$h .= '</div>';
			}
			$h .= '</section>';
		}
		if ( '' === $h ) {
			$h = '<p>' . esc_html( EVT_Settings::text( 'no_tickets', $lang ) ) . '</p>';
		} else {
			$h = '<p class="evt-tk-note">' . esc_html( EVT_Settings::text( 'at_door', $lang ) ) . '</p>' . $h;
		}
		if ( $form ) {
			$h = '<form method="post" action="' . esc_url( self::page_url( $order ) ) . '">' . wp_nonce_field( 'evt_names_' . $order->get_id(), 'evt_names_nonce', false, false ) . $h
				. '<p><button type="submit">' . esc_html( EVT_Settings::text( 'save_names', $lang ) ) . '</button></p></form>';
		}
		self::page_shell( EVT_Settings::text( 'my_tickets', $lang ), $h );
		exit;
	}

	/** The name of a valid ticket can be typed while its event allows names and has not ended. */
	private static function names_open( array $r ): bool {
		if ( 'valid' !== $r['status'] || '1' !== (string) get_post_meta( $r['event_id'], '_evt_names', true ) ) {
			return false;
		}
		$end = (int) get_post_meta( $r['event_id'], '_evt_end', true );
		return $end <= 0 || $end > time();
	}

	private static function page_shell( string $title, string $body ): void {
		$site = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<meta name="referrer" content="no-referrer" />
<title><?php echo esc_html( ( '' !== $title ? $title . ' · ' : '' ) . $site ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( EVT_URL . 'assets/tickets.css?ver=' . EVT_VERSION ); ?>" />
</head>
<body class="evt-tk-page">
<main class="evt-tk-main">
<p class="evt-tk-site"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( $site ); ?></a></p>
<?php if ( '' !== $title ) : ?><h1><?php echo esc_html( $title ); ?></h1><?php endif; ?>
<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above. ?>
</main>
<script src="<?php echo esc_url( EVT_URL . 'assets/qr.js?ver=' . EVT_VERSION ); ?>"></script>
</body>
</html>
		<?php
	}

	/* =====================================================================
	 * Event page box, schema, cache
	 * =================================================================== */

	/** The «Buy a ticket» box on the event page (static HTML: page caches keep working). */
	public static function box( array $e ): string {
		$p = self::product_of( $e['id'] );
		if ( ! $p ) {
			return '';
		}
		$st = self::state( $e, $p );
		if ( 'off' === $st ) {
			return '';
		}
		$h = '<div class="evt-buy">';
		if ( 'open' !== $st ) {
			$map = array(
				'soon'     => 'sales_soon',
				'closed'   => 'sales_closed',
				'sold_out' => 'sold_out',
			);
			$h  .= '<p class="evt-buy__note">' . esc_html( EVT_Settings::text( $map[ $st ] ) ) . '</p>';
			return $h . '</div>';
		}
		$action = esc_url( (string) get_permalink( $e['id'] ) );
		$rows   = array();
		if ( $p->is_type( 'variable' ) ) {
			foreach ( $p->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v && $v->is_purchasable() && $v->is_in_stock() && 'publish' === $v->get_status() ) {
					$rows[] = $v;
				}
			}
		} elseif ( $p->is_purchasable() ) {
			$rows[] = $p;
		}
		foreach ( $rows as $v ) {
			$max  = $v->get_max_purchase_quantity();
			$max  = $max > 0 ? min( 20, $max ) : 20;
			$name = $v->get_parent_id() ? wc_get_formatted_variation( $v, true, false, false ) : '';
			$h   .= '<form class="evt-buy__row" method="post" action="' . $action . '">';
			$h   .= '<span class="evt-buy__type">' . ( '' !== $name ? esc_html( wp_strip_all_tags( $name ) ) . ' · ' : '' ) . wp_kses_post( $v->get_price_html() ) . '</span>';
			$h   .= '<input type="number" class="evt-buy__qty" name="quantity" value="1" min="1" max="' . (int) $max . '" step="1" aria-label="' . esc_attr__( 'Ποσότητα', 'events' ) . '" />';
			if ( $v->get_parent_id() ) {
				$h .= '<input type="hidden" name="add-to-cart" value="' . (int) $v->get_parent_id() . '" /><input type="hidden" name="variation_id" value="' . (int) $v->get_id() . '" />';
				foreach ( $v->get_variation_attributes() as $an => $av ) {
					$h .= '<input type="hidden" name="' . esc_attr( $an ) . '" value="' . esc_attr( $av ) . '" />';
				}
			} else {
				$h .= '<input type="hidden" name="add-to-cart" value="' . (int) $v->get_id() . '" />';
			}
			$h .= '<button type="submit" class="button evt-button">' . esc_html( EVT_Settings::text( 'buy' ) ) . '</button></form>';
		}
		if ( ! $rows ) {
			$h .= '<p class="evt-buy__note">' . esc_html( EVT_Settings::text( 'sold_out' ) ) . '</p>';
		}
		return $h . '</div>';
	}

	/** Offers from the ticket product (Π30). */
	public static function schema( $n, $e ) {
		if ( ! is_array( $n ) || ! is_array( $e ) || empty( $e['id'] ) ) {
			return $n;
		}
		$p = self::product_of( (int) $e['id'] );
		if ( ! $p ) {
			return $n;
		}
		$price = $p->is_type( 'variable' ) ? $p->get_variation_price( 'min' ) : $p->get_price();
		$offer = array(
			'@type'         => 'Offer',
			'url'           => $e['url'],
			'price'         => (string) wc_format_decimal( $price, wc_get_price_decimals() ),
			'priceCurrency' => get_woocommerce_currency(),
		);
		if ( 'cancelled' !== $e['status'] && ! $e['ended'] ) {
			$offer['availability'] = $p->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut';
		}
		$from = (int) get_post_meta( (int) $e['id'], '_evt_sales_from', true );
		if ( $from > 0 ) {
			$offer['validFrom'] = wp_date( 'c', $from );
		}
		$n['offers'] = $offer;
		if ( (float) $price <= 0 ) {
			$n['isAccessibleForFree'] = true;
		}
		return $n;
	}

	/** Stock changed (a sale, a cancellation): the event page shows seats. */
	public static function stock_changed( $product ): void {
		$eid = $product instanceof WC_Product ? self::event_of( $product ) : 0;
		if ( $eid ) {
			EVT_Jobs::purge( $eid );
		}
	}

	/* =====================================================================
	 * Daily: attendee names lifetime (Π27)
	 * =================================================================== */

	public static function schedule_daily(): void {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			if ( false === as_next_scheduled_action( self::DAILY, array(), 'events' ) ) {
				as_schedule_recurring_action( strtotime( 'tomorrow 04:23', time() ), DAY_IN_SECONDS, self::DAILY, array(), 'events' );
			}
		} elseif ( ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY );
		}
	}

	public static function daily(): void {
		$days = (int) EVT_Settings::get()['names_days'];
		foreach ( EVT_Ticket_Store::events_with_names() as $eid ) {
			$end = (int) get_post_meta( $eid, '_evt_end', true );
			if ( ! get_post( $eid ) || ( $end > 0 && $end + $days * DAY_IN_SECONDS < time() ) ) {
				EVT_Ticket_Store::clear_names_of_event( $eid );
			}
		}
	}

	/* =====================================================================
	 * Privacy
	 * =================================================================== */

	public static function exporters( $list ): array {
		$list                   = is_array( $list ) ? $list : array();
		$list['events-tickets'] = array(
			'exporter_friendly_name' => __( 'Εισιτήρια εκδηλώσεων', 'events' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	public static function erasers( $list ): array {
		$list                   = is_array( $list ) ? $list : array();
		$list['events-tickets'] = array(
			'eraser_friendly_name' => __( 'Εισιτήρια εκδηλώσεων', 'events' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	/** @return int[] Order ids of an email address (billing email or account). */
	private static function orders_of_email( string $email ): array {
		if ( ! function_exists( 'wc_get_orders' ) || ! is_email( $email ) ) {
			return array();
		}
		$ids  = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => 500,
				'return'        => 'ids',
			)
		);
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$ids = array_merge(
				(array) $ids,
				(array) wc_get_orders(
					array(
						'customer_id' => $user->ID,
						'limit'       => 500,
						'return'      => 'ids',
					)
				)
			);
		}
		return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
	}

	public static function export( $email, $page = 1 ): array {
		$data = array();
		foreach ( self::orders_of_email( (string) $email ) as $oid ) {
			foreach ( EVT_Ticket_Store::for_order( $oid ) as $r ) {
				$data[] = array(
					'group_id'    => 'events-tickets',
					'group_label' => __( 'Εισιτήρια εκδηλώσεων', 'events' ),
					'item_id'     => 'evt-ticket-' . $r['id'],
					'data'        => array(
						array(
							'name'  => __( 'Εκδήλωση', 'events' ),
							'value' => wp_strip_all_tags( get_the_title( $r['event_id'] ) ),
						),
						array(
							'name'  => __( 'Παραγγελία', 'events' ),
							'value' => '#' . $oid,
						),
						array(
							'name'  => __( 'Κωδικός', 'events' ),
							'value' => EVT_Ticket_Store::pretty( EVT_Ticket_Store::code_for( $r ) ),
						),
						array(
							'name'  => __( 'Όνομα συμμετέχοντα', 'events' ),
							'value' => $r['name'],
						),
						array(
							'name'  => __( 'Είσοδος', 'events' ),
							'value' => null === $r['used'] ? '' : (string) $r['used'] . ' UTC',
						),
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	public static function erase( $email, $page = 1 ): array {
		$n = 0;
		foreach ( self::orders_of_email( (string) $email ) as $oid ) {
			$n += EVT_Ticket_Store::clear_names_of_order( $oid );
		}
		return array(
			'items_removed'  => $n > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}

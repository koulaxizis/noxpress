<?php
/**
 * GFC_Account — My account › Gift cards (endpoint "gift-cards").
 *
 *  - The cards the customer bought: masked code, amount, status, expiry.
 *    The balance is shown only for cards bought "for me": a gift belongs
 *    to its recipient, and the buyer does not see how it is spent.
 *  - The orders the customer paid with a card, and how much each took.
 * Never a code (it is not stored, E3). The menu item appears only when
 * the customer has a card or has used one.
 */

defined( 'ABSPATH' ) || exit;

final class GFC_Account {

	const EP = 'gift-cards';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 20 );
		add_action( 'woocommerce_account_' . self::EP . '_endpoint', array( __CLASS__, 'render' ) );
		add_filter( 'woocommerce_endpoint_' . self::EP . '_title', array( __CLASS__, 'title' ) );
	}

	public static function endpoint(): void {
		add_rewrite_endpoint( self::EP, EP_ROOT | EP_PAGES );
		if ( get_option( 'gfc_flush_rewrite' ) ) {
			delete_option( 'gfc_flush_rewrite' );
			flush_rewrite_rules( false );
		}
	}

	public static function query_vars( $vars ) {
		$vars              = is_array( $vars ) ? $vars : array();
		$vars[ self::EP ] = self::EP;
		return $vars;
	}

	public static function title(): string {
		return GFC_Settings::text( 'account_title' );
	}

	public static function menu( $items ) {
		$items = is_array( $items ) ? $items : array();
		$uid   = get_current_user_id();
		if ( ! $uid || ( ! self::bought( $uid, 1 ) && ! self::used( $uid, 1 ) ) ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $k => $v ) {
			if ( 'customer-logout' === $k ) {
				$out[ self::EP ] = GFC_Settings::text( 'account_title' );
			}
			$out[ $k ] = $v;
		}
		if ( ! isset( $out[ self::EP ] ) ) {
			$out[ self::EP ] = GFC_Settings::text( 'account_title' );
		}
		return $out;
	}

	/** Cards bought by a customer (newest first). */
	public static function bought( int $uid, int $limit = 100 ): array {
		global $wpdb;
		$t = GFC_Schema::cards();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE customer_id = %d AND source = 'order' AND status <> 'void' ORDER BY id DESC LIMIT %d", $uid, $limit ), ARRAY_A );
		return array_map( array( 'GFC_Store', 'cast' ), is_array( $rows ) ? $rows : array() );
	}

	/** Orders of a customer that a card paid (newest first). */
	public static function used( int $uid, int $limit = 50 ): array {
		$orders = wc_get_orders(
			array(
				'customer_id' => $uid,
				'limit'       => $limit,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'type'        => 'shop_order',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_gfc_has',
						'value' => '1',
					),
				),
			)
		);
		return is_array( $orders ) ? $orders : array();
	}

	private static function status_label( array $card ): string {
		switch ( $card['status'] ) {
			case 'pending':
				return GFC_Settings::text( 'st_pending' );
			case 'review':
				return GFC_Settings::text( 'st_review' );
			case 'active':
				return $card['balance'] > 0 ? GFC_Settings::text( 'st_active' ) : GFC_Settings::text( 'st_used' );
			default:
				return GFC_Settings::text( 'st_off' );
		}
	}

	private static function date( ?string $gmt ): string {
		return $gmt ? wp_date( (string) get_option( 'date_format' ), (int) strtotime( $gmt . ' UTC' ) ) : '—';
	}

	public static function render(): void {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return;
		}
		$bought = self::bought( $uid );
		$used   = self::used( $uid );
		if ( ! $bought && ! $used ) {
			echo '<p>' . esc_html( GFC_Settings::text( 'acc_none' ) ) . '</p>';
			return;
		}

		if ( $bought ) {
			echo '<h3>' . esc_html( GFC_Settings::text( 'acc_bought' ) ) . '</h3>';
			echo '<table class="woocommerce-table shop_table gfc-acc"><thead><tr>';
			foreach ( array( 'pdf_code', 'amount', 'line_to', 'line_date' ) as $k ) {
				echo '<th>' . esc_html( GFC_Settings::text( $k ) ) . '</th>';
			}
			echo '<th></th></tr></thead><tbody>';
			foreach ( $bought as $c ) {
				$to = $c['for_me'] ? GFC_Settings::text( 'for_me' ) : $c['recipient_name'];
				$am = GFC_Settings::money( $c['initial'], $c['currency'] );
				if ( $c['for_me'] && 'active' === $c['status'] ) {
					$am .= ' · ' . wp_strip_all_tags( GFC_Settings::fill( 'row_left', array( 'balance' => GFC_Settings::money( $c['balance'], $c['currency'] ) ) ) );
				}
				$when = $c['delivered_at'] ? self::date( $c['delivered_at'] ) : self::date( $c['deliver_at'] );
				if ( $c['expires_at'] ) {
					$when .= ' · ' . GFC_Settings::fill_plain( 'email_expires', array( 'date' => self::date( $c['expires_at'] ) ) );
				}
				echo '<tr>'
					. '<td>' . esc_html( GFC_Codes::mask( (string) $c['last4'] ) ) . '</td>'
					. '<td>' . esc_html( $am ) . '</td>'
					. '<td>' . esc_html( (string) $to ) . '</td>'
					. '<td>' . esc_html( $when ) . '</td>'
					. '<td>' . esc_html( self::status_label( $c ) ) . '</td>'
					. '</tr>';
			}
			echo '</tbody></table>';
		}

		if ( $used ) {
			echo '<h3>' . esc_html( GFC_Settings::text( 'acc_used' ) ) . '</h3>';
			echo '<table class="woocommerce-table shop_table gfc-acc"><tbody>';
			foreach ( $used as $order ) {
				foreach ( GFC_Redeem::order_cards( $order ) as $row ) {
					echo '<tr>'
						. '<td><a href="' . esc_url( $order->get_view_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>'
						. '<td>' . esc_html( $order->get_date_created() ? wp_date( (string) get_option( 'date_format' ), $order->get_date_created()->getTimestamp() ) : '—' ) . '</td>'
						. '<td>' . esc_html( GFC_Codes::mask( (string) $row['last4'] ) ) . '</td>'
						. '<td>−' . esc_html( GFC_Settings::money( (int) round( $row['amount'] * 100 ), $order->get_currency() ) ) . '</td>'
						. '</tr>';
				}
			}
			echo '</tbody></table>';
		}
	}
}

<?php
/**
 * WSH_Alerts — email alerts for signed-in customers (1.1.0): a product in
 * their wishlist dropped in price or is back in stock.
 *
 * Three switches, all needed:
 *  - the email "Wishlist alert" is on in WooCommerce → Settings → Emails
 *    (off by default, Bible §14.27);
 *  - the store mode is "live" (no emails from test mode);
 *  - the customer ticked the box under their list (user meta wsh_alerts,
 *    unticked by default). Every email has a link that switches it off
 *    without signing in (HMAC of the user id).
 *
 * One Action Scheduler job a day (group wish-shelf) checks the opted-in
 * customers in batches of 50, by ascending user id; each batch queues the
 * next. One email per customer with all the news. State per item in the
 * wsh_items table: alert_price (the price of the last alert; until then
 * the price when added) and was_out (out of stock at the last check), so
 * the same news is never sent twice. Writes from cron, not from a visitor
 * request (Bible §14.5).
 */

defined( 'ABSPATH' ) || exit;

final class WSH_Alerts {

	const HOOK  = 'wsh_alerts_run';
	const GROUP = 'wish-shelf';
	const BATCH = 50;
	const META  = 'wsh_alerts';

	/** The WC_Email id (its settings: option woocommerce_wsh_alert_settings). */
	const EMAIL_ID = 'wsh_alert';

	public static function init(): void {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
		add_action( 'woocommerce_update_options_email_' . self::EMAIL_ID, array( __CLASS__, 'schedule' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'unsubscribe' ), 1 );
	}

	public static function register( $emails ): array {
		$emails = is_array( $emails ) ? $emails : array();
		require_once WSH_PATH . 'includes/class-email-alert.php';
		$emails['WSH_Email_Alert'] = new WSH_Email_Alert();
		return $emails;
	}

	/** The email is on in WooCommerce → Settings → Emails (read without loading the mailer). */
	public static function email_on(): bool {
		$o = get_option( 'woocommerce_' . self::EMAIL_ID . '_settings' );
		return is_array( $o ) && isset( $o['enabled'] ) && 'yes' === $o['enabled'];
	}

	/** Alerts can go out: email on, at least one kind on, mode live. */
	public static function available(): bool {
		$s = WSH_Settings::get();
		return self::email_on() && ( $s['alert_price'] || $s['alert_stock'] ) && 'live' === WSH_Settings::mode() && ! Wish_Shelf::killed();
	}

	/** schedule() at most once an hour from admin pages (saves call schedule() directly). */
	public static function maybe_schedule(): void {
		if ( get_transient( 'wsh_alerts_chk' ) ) {
			return;
		}
		set_transient( 'wsh_alerts_chk', 1, HOUR_IN_SECONDS );
		self::schedule();
	}

	/** Keeps the daily job scheduled exactly while alerts are available. */
	public static function schedule(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! WSH_Store::ready() ) {
			return;
		}
		$next = as_next_scheduled_action( self::HOOK, array( 0 ), self::GROUP );
		if ( self::available() ) {
			if ( ! $next ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, array( 0 ), self::GROUP );
			}
		} elseif ( $next ) {
			as_unschedule_all_actions( self::HOOK, null, self::GROUP );
		}
	}

	/* =====================================================================
	 * Opt-in
	 * =================================================================== */

	public static function opted_in( int $uid ): bool {
		return $uid > 0 && '1' === (string) get_user_meta( $uid, self::META, true );
	}

	/** Customers who asked for the alerts. */
	public static function optin_count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin count.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = '1'", self::META ) );
	}

	public static function set_optin( int $uid, bool $on ): void {
		if ( $uid <= 0 ) {
			return;
		}
		if ( $on ) {
			update_user_meta( $uid, self::META, '1' );
		} else {
			delete_user_meta( $uid, self::META );
		}
	}

	/**
	 * The opt-in box under the customer's own list ('' while alerts are not available).
	 *
	 * @param int $uid User id.
	 */
	public static function optin_box( int $uid ): string {
		if ( $uid <= 0 || ! self::available() ) {
			return '';
		}
		$h  = '<form method="post" class="wsh-root wsh-alerts" action="' . esc_url( remove_query_arg( array( 'wsh_done', 'wsh_unsubbed' ) ) ) . '">';
		$h .= wp_nonce_field( WSH_List::NONCE, 'wsh_nonce', false, false ) . '<input type="hidden" name="wsh_form" value="1" />';
		$h .= '<label class="wsh-sel wsh-alerts__opt"><input type="checkbox" name="wsh_alerts" value="1"' . checked( self::opted_in( $uid ), true, false ) . ' /><span class="wsh-box" aria-hidden="true"></span> <span>' . esc_html( WSH_Settings::text( 't_alerts' ) ) . '</span></label>';
		$h .= '<button type="submit" name="wsh_alerts_save" value="1" class="wsh-link">' . esc_html( WSH_Settings::text( 't_save' ) ) . '</button>';
		return $h . '</form>';
	}

	/* =====================================================================
	 * Unsubscribe link (no sign-in)
	 * =================================================================== */

	private static function sig( int $uid ): string {
		return substr( hash_hmac( 'sha256', 'wsh_unsub|' . $uid, wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function unsub_url( int $uid ): string {
		return add_query_arg(
			array(
				'wsh_unsub' => $uid,
				'k'         => self::sig( $uid ),
			),
			home_url( '/' )
		);
	}

	/** ?wsh_unsub=<uid>&k=<sig>: switches the alerts off and says so. */
	public static function unsubscribe(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- signed link from an email.
		if ( ! isset( $_GET['wsh_unsub'], $_GET['k'] ) || ! is_string( $_GET['wsh_unsub'] ) || ! is_string( $_GET['k'] ) ) {
			return;
		}
		$uid = absint( $_GET['wsh_unsub'] );
		$k   = sanitize_key( wp_unslash( $_GET['k'] ) );
		// phpcs:enable
		if ( $uid <= 0 || ! hash_equals( self::sig( $uid ), $k ) ) {
			return;
		}
		self::set_optin( $uid, false );
		$url = WSH_Settings::list_url();
		if ( '' !== $url && WSH_Settings::page_id() ) {
			wp_safe_redirect( add_query_arg( 'wsh_unsubbed', 1, $url ) );
			exit;
		}
		wp_die( esc_html( WSH_Settings::text( 't_unsub' ) ), esc_html( WSH_Settings::text( 't_title' ) ), array( 'response' => 200 ) );
	}

	/** The note on the list page after the link ('' otherwise). */
	public static function unsub_note(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( empty( $_GET['wsh_unsubbed'] ) ) {
			return '';
		}
		return '<p class="wsh-root wsh-note" role="status">' . esc_html( WSH_Settings::text( 't_unsub' ) ) . '</p>';
	}

	/* =====================================================================
	 * The daily job
	 * =================================================================== */

	/**
	 * One batch: customers with an id above $after.
	 *
	 * @param int $after Last user id of the previous batch (0 = start).
	 */
	public static function run( $after = 0 ): void {
		try {
			if ( ! self::available() || ! WSH_Store::ready() ) {
				return;
			}
			global $wpdb;
			$after = max( 0, (int) $after );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ordered id batch over usermeta.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = '1' AND user_id > %d ORDER BY user_id ASC LIMIT %d", self::META, $after, self::BATCH ) );
			$ids = array_map( 'intval', (array) $ids );
			foreach ( $ids as $uid ) {
				self::check_user( $uid );
			}
			if ( count( $ids ) === self::BATCH && function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::HOOK, array( (int) end( $ids ) ), self::GROUP );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Checks one customer's items and sends one email with the news.
	 * Returns the number of lines sent (tests).
	 *
	 * @param int $uid User id.
	 */
	public static function check_user( int $uid ): int {
		global $wpdb;
		$user = get_userdata( $uid );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return 0;
		}
		$s     = WSH_Settings::get();
		$table = WSH_Store::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, product_id, variation_id, price, alert_price, was_out FROM {$table} WHERE user_id = %d ORDER BY added DESC LIMIT %d", $uid, WSH_Store::max_items() ), ARRAY_A );
		$lines = array();
		$after = array();
		foreach ( (array) $rows as $r ) {
			$pair = WSH_Store::valid_pair( (int) $r['product_id'], (int) $r['variation_id'] );
			$item = $pair ? wc_get_product( $pair[1] ? $pair[1] : $pair[0] ) : null;
			if ( ! $item ) {
				continue;
			}
			$id  = (int) $r['id'];
			$in  = $item->is_in_stock();
			$now = WSH_Store::display_price( $pair[0], $pair[1] );

			if ( $s['alert_stock'] ) {
				if ( ! $in && ! (int) $r['was_out'] ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
					$wpdb->update( $table, array( 'was_out' => 1 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
				} elseif ( $in && (int) $r['was_out'] ) {
					$lines[]          = self::line( $item, 'stock', null, $now );
					$after[ $id ]     = isset( $after[ $id ] ) ? $after[ $id ] : array();
					$after[ $id ]['s'] = 1;
				}
			}
			if ( $s['alert_price'] && $in && null !== $now ) {
				$ref = null !== $r['alert_price'] ? (float) $r['alert_price'] : ( null !== $r['price'] ? (float) $r['price'] : null );
				if ( null !== $ref && $ref > 0 && $now <= $ref * ( 1 - $s['alert_drop'] / 100 ) + 0.00001 ) {
					$lines[]           = self::line( $item, 'drop', $ref, $now );
					$after[ $id ]      = isset( $after[ $id ] ) ? $after[ $id ] : array();
					$after[ $id ]['p'] = $now;
				}
			}
		}
		if ( ! $lines ) {
			return 0;
		}
		$sent = self::send( $user, $lines );
		if ( $sent ) {
			// The news is marked only once it left, so a failed send retries tomorrow.
			foreach ( $after as $id => $ch ) {
				$data = array();
				$fmt  = array();
				if ( isset( $ch['s'] ) ) {
					$data['was_out'] = 0;
					$fmt[]           = '%d';
				}
				if ( isset( $ch['p'] ) ) {
					$data['alert_price'] = $ch['p'];
					$fmt[]               = '%f';
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
				$wpdb->update( $table, $data, array( 'id' => (int) $id ), $fmt, array( '%d' ) );
			}
		}
		return $sent ? count( $lines ) : 0;
	}

	/**
	 * One line of the email.
	 *
	 * @param WC_Product $item Product or variation.
	 * @param string     $kind 'drop' | 'stock'.
	 * @param float|null $old  Old price (drop).
	 * @param float|null $now  Price now.
	 */
	private static function line( WC_Product $item, string $kind, ?float $old, ?float $now ): array {
		return array(
			'kind'  => $kind,
			'name'  => wp_strip_all_tags( $item->get_name() ),
			'url'   => (string) $item->get_permalink(),
			'old'   => $old,
			'now'   => $now,
			'image' => (int) $item->get_image_id(),
		);
	}

	/**
	 * Sends the email in the visitor language of the plugin.
	 *
	 * @param WP_User $user  Customer.
	 * @param array[] $lines Lines.
	 */
	private static function send( WP_User $user, array $lines ): bool {
		$mailer = function_exists( 'WC' ) ? WC()->mailer() : null;
		$all    = $mailer ? $mailer->get_emails() : array();
		$email  = isset( $all['WSH_Email_Alert'] ) ? $all['WSH_Email_Alert'] : null;
		if ( ! $email || ! $email->is_enabled() ) {
			return false;
		}
		return (bool) WSH_Lang::with_lang(
			WSH_Settings::lang(),
			static function () use ( $email, $user, $lines ) {
				return $email->trigger( $user, $lines );
			}
		);
	}

	/**
	 * Text of a line, in the visitor language ({old}, {new} filled).
	 *
	 * @param array $line Line.
	 */
	public static function line_text( array $line ): string {
		if ( 'stock' === $line['kind'] ) {
			return WSH_Settings::text( 't_mail_stock' );
		}
		$fmt = static function ( $v ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( (float) $v ) ), ENT_QUOTES, 'UTF-8' );
		};
		return strtr(
			WSH_Settings::text( 't_mail_drop' ),
			array(
				'{old}' => $fmt( $line['old'] ),
				'{new}' => $fmt( $line['now'] ),
			)
		);
	}
}

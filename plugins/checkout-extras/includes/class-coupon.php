<?php
/**
 * CKE_Coupon — the thank-you coupon: issue, deliver, measure, clean up.
 *
 *  - Issue: when an order reaches one of the chosen statuses
 *    (woocommerce_order_status_{status}, priority 5, so the coupon exists
 *    before WooCommerce sends that status's email), once per order. Never
 *    on a page view (Bible §14.5) and never for pending, failed, cancelled
 *    or refunded orders. Issue rules: minimum order total, not for an order
 *    that used one of our coupons, one active coupon per email, days
 *    between two coupons, first order only, orders made only of excluded
 *    categories. Filter cke_coupon_args (false = no coupon).
 *  - The coupon is a normal WC_Coupon (CRUD), usage limit 1, by default
 *    only for the order's billing email. Meta on the coupon: _cke_generated
 *    (source order id), _cke_email. Meta on the order: _cke_coupon_id,
 *    _cke_coupon_code (store record, kept after uninstall, Bible §12).
 *  - Delivery: the thank-you page box (CKE_Thankyou), a block in the
 *    chosen WooCommerce customer emails (default) or a separate
 *    WooCommerce email "Thank-you coupon", and an optional reminder email
 *    before expiry (Action Scheduler).
 *  - Measuring: an order that uses one of our coupons gets _cke_redeemed
 *    (code), and the source order _cke_used_in (order id). The stats read
 *    order meta, so they survive the cleanup of expired coupons.
 *  - Cleanup: daily (Action Scheduler, group checkout-extras), our own
 *    expired coupons that were never used, N days after expiry. Coupons of
 *    other plugins are never touched.
 */

defined( 'ABSPATH' ) || exit;

final class CKE_Coupon {

	const META_SOURCE   = '_cke_generated';
	const META_EMAIL    = '_cke_email';
	const META_ID       = '_cke_coupon_id';
	const META_CODE     = '_cke_coupon_code';
	const META_REDEEMED = '_cke_redeemed';
	const META_USED_IN  = '_cke_used_in';

	const GROUP       = 'checkout-extras';
	const HOOK_CLEAN  = 'cke_cleanup';
	const HOOK_REMIND = 'cke_coupon_reminder';

	/** Most coupons deleted per cleanup run. */
	const CLEAN_BATCH = 50;

	/** Characters for codes: no 0/O, 1/l/I. */
	const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

	/** @var bool Set after the first error. */
	private static $broken = false;

	public static function init(): void {
		foreach ( CKE_Settings::STATUSES as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( __CLASS__, 'on_status' ), 5, 2 );
		}
		// Redemption tracking runs in every mode: it only records what happened.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'track_redeem' ), 20, 4 );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_block' ), 20, 4 );
		add_action( self::HOOK_CLEAN, array( __CLASS__, 'cleanup' ) );
		add_action( self::HOOK_REMIND, array( __CLASS__, 'remind' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
	}

	private static function fail( \Throwable $e ): void {
		self::$broken = true;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only.
			error_log( 'Checkout Extras (coupon): ' . $e->getMessage() );
		}
	}

	/* =====================================================================
	 * Issue
	 * =================================================================== */

	/**
	 * True when the coupon part may issue for this order (mode + test mode:
	 * in test mode only orders of shop managers get a coupon).
	 */
	private static function active_for( WC_Order $order ): bool {
		if ( Checkout_Extras::killed() ) {
			return false;
		}
		$mode = CKE_Settings::get()['c_mode'];
		if ( 'live' === $mode ) {
			return true;
		}
		$uid = (int) $order->get_customer_id();
		return 'test' === $mode && $uid > 0 && user_can( $uid, 'manage_woocommerce' );
	}

	/**
	 * woocommerce_order_status_{status}: issue the coupon once.
	 *
	 * @param int      $order_id Order id.
	 * @param WC_Order $order    The order being transitioned (the same object the status emails get).
	 */
	public static function on_status( $order_id, $order = null ): void {
		if ( self::$broken ) {
			return;
		}
		try {
			$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || ! in_array( $order->get_status(), CKE_Settings::get()['c_statuses'], true ) ) {
				return;
			}
			self::issue( $order );
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * Why an order gets no coupon, or '' when it may get one. With
	 * $ignore_status the status rule is skipped (the thank-you page uses it
	 * to promise a coupon for an order that is not paid yet).
	 */
	public static function refusal( WC_Order $order, bool $ignore_status = false ): string {
		$s = CKE_Settings::get();
		if ( ! self::active_for( $order ) ) {
			return 'mode';
		}
		if ( '' !== (string) $order->get_meta( self::META_CODE, true ) ) {
			return 'has';
		}
		if ( ! $ignore_status && ! in_array( $order->get_status(), $s['c_statuses'], true ) ) {
			return 'status';
		}
		if ( $order->get_parent_id() > 0 || 'shop_order' !== $order->get_type() ) {
			return 'type';
		}
		$email = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return 'email';
		}
		if ( '' !== $s['c_min_total'] && (float) $order->get_total() < (float) $s['c_min_total'] ) {
			return 'min_total';
		}
		if ( $s['c_skip_used'] && self::order_used_ours( $order ) ) {
			return 'used';
		}
		if ( $s['c_skip_cats'] && self::only_skipped_cats( $order, $s['c_skip_cats'] ) ) {
			return 'cats';
		}
		if ( $s['c_one_active'] && self::has_active( $email ) ) {
			return 'active';
		}
		if ( $s['c_days_between'] > 0 && self::issued_since( $email, time() - $s['c_days_between'] * DAY_IN_SECONDS ) ) {
			return 'recent';
		}
		if ( $s['c_first_only'] && self::has_earlier_order( $order, $email ) ) {
			return 'first';
		}
		return '';
	}

	/**
	 * Creates the coupon for an order when the rules allow it.
	 *
	 * @return WC_Coupon|null The new coupon, or null.
	 */
	public static function issue( WC_Order $order ): ?WC_Coupon {
		if ( '' !== self::refusal( $order ) ) {
			return null;
		}
		$s     = CKE_Settings::get();
		$email = strtolower( trim( (string) $order->get_billing_email() ) );
		$tz    = wp_timezone();
		$exp   = ( new DateTimeImmutable( 'now', $tz ) )->modify( '+' . (int) $s['c_expiry'] . ' days' )->setTime( 23, 59, 59 );

		$args = array(
			'discount_type'               => $s['c_type'],
			'amount'                      => $s['c_amount'],
			'free_shipping'               => $s['c_free_ship'],
			'date_expires'                => $exp->getTimestamp(),
			'minimum_amount'              => $s['c_min_spend'],
			'maximum_amount'              => $s['c_max_spend'],
			'individual_use'              => $s['c_individual'],
			'exclude_sale_items'          => $s['c_exclude_sale'],
			'product_ids'                 => $s['c_products'],
			'excluded_product_ids'        => $s['c_ex_products'],
			'product_categories'          => $s['c_cats'],
			'excluded_product_categories' => $s['c_ex_cats'],
			'limit_usage_to_x_items'      => $s['c_limit_items'] > 0 ? $s['c_limit_items'] : null,
			'usage_limit'                 => 1,
			'usage_limit_per_user'        => 1,
			'email_restrictions'          => $s['c_email_restrict'] ? array( $email ) : array(),
		);
		/**
		 * Filters the coupon a thank-you order gets, before it is created.
		 * Return false for no coupon.
		 *
		 * @param array|false $args  WC_Coupon props (set_{key}).
		 * @param WC_Order    $order Source order.
		 */
		$args = apply_filters( 'cke_coupon_args', $args, $order );
		if ( ! is_array( $args ) ) {
			return null;
		}

		$coupon = new WC_Coupon();
		$coupon->set_code( self::new_code( $s['c_prefix'] ) );
		$coupon->set_description(
			sprintf(
				/* translators: %s: order number */
				__( 'Checkout Extras: κουπόνι ευχαριστίας για την παραγγελία #%s', 'checkout-extras' ),
				$order->get_order_number()
			)
		);
		foreach ( $args as $prop => $value ) {
			$setter = 'set_' . $prop;
			if ( is_string( $prop ) && null !== $value && is_callable( array( $coupon, $setter ) ) ) {
				$coupon->{$setter}( $value );
			}
		}
		$coupon->update_meta_data( self::META_SOURCE, (string) $order->get_id() );
		$coupon->update_meta_data( self::META_EMAIL, $email );
		$coupon->save();
		if ( ! $coupon->get_id() ) {
			return null;
		}

		$order->update_meta_data( self::META_ID, (string) $coupon->get_id() );
		$order->update_meta_data( self::META_CODE, $coupon->get_code() );
		$order->save_meta_data();
		$order->add_order_note(
			sprintf(
				/* translators: %s: coupon code */
				__( 'Checkout Extras: δημιουργήθηκε το κουπόνι ευχαριστίας %s.', 'checkout-extras' ),
				$coupon->get_code()
			)
		);

		self::schedule_reminder( $coupon );

		/**
		 * Fires after a thank-you coupon was created for an order.
		 *
		 * @param WC_Coupon $coupon Coupon.
		 * @param WC_Order  $order  Source order.
		 */
		do_action( 'cke_coupon_created', $coupon, $order );
		return $coupon;
	}

	/** A code that does not exist yet: prefix + 8 characters. */
	private static function new_code( string $prefix ): string {
		$len = strlen( self::ALPHABET );
		for ( $try = 0; $try < 20; $try++ ) {
			$code = $prefix;
			for ( $i = 0; $i < 8; $i++ ) {
				$code .= self::ALPHABET[ random_int( 0, $len - 1 ) ];
			}
			if ( ! wc_get_coupon_id_by_code( $code ) ) {
				return $code;
			}
		}
		return $prefix . strtolower( wp_generate_password( 12, false, false ) );
	}

	/** True when the order used one of our coupons. */
	private static function order_used_ours( WC_Order $order ): bool {
		foreach ( $order->get_coupon_codes() as $code ) {
			if ( self::is_ours( (string) $code ) ) {
				return true;
			}
		}
		return false;
	}

	/** True when a code is one of our thank-you coupons. */
	public static function is_ours( string $code ): bool {
		$id = wc_get_coupon_id_by_code( $code );
		return $id > 0 && '' !== (string) get_post_meta( $id, self::META_SOURCE, true );
	}

	/** True when every product of the order is in one of the categories (or their children). */
	private static function only_skipped_cats( WC_Order $order, array $cats ): bool {
		$all = $cats;
		foreach ( $cats as $c ) {
			$all = array_merge( $all, array_map( 'intval', (array) get_term_children( $c, 'product_cat' ) ) );
		}
		$any = false;
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$any = true;
			$pid = (int) $item->get_product_id();
			$in  = wc_get_product_cat_ids( $pid );
			if ( ! array_intersect( array_map( 'intval', $in ), $all ) ) {
				return false;
			}
		}
		return $any;
	}

	/** Our coupons for an email, newest first (ids). */
	private static function coupons_for( string $email, int $limit = 20 ): array {
		$q = new WP_Query(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, indexed by meta_key.
					array(
						'key'   => self::META_EMAIL,
						'value' => $email,
					),
				),
			)
		);
		return array_map( 'intval', $q->posts );
	}

	/** True when the email holds one of our coupons that is unused and not expired. */
	private static function has_active( string $email ): bool {
		foreach ( self::coupons_for( $email ) as $id ) {
			if ( 'active' === self::state( new WC_Coupon( $id ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** True when the email got one of our coupons after a timestamp. */
	private static function issued_since( string $email, int $since ): bool {
		foreach ( self::coupons_for( $email, 1 ) as $id ) {
			$c       = new WC_Coupon( $id );
			$created = $c->get_date_created();
			if ( $created && $created->getTimestamp() >= $since ) {
				return true;
			}
		}
		return false;
	}

	/** True when the email placed a paid order before this one. */
	private static function has_earlier_order( WC_Order $order, string $email ): bool {
		$ids = wc_get_orders(
			array(
				'billing_email' => $email,
				'status'        => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
				'exclude'       => array( $order->get_id() ),
				'limit'         => 1,
				'return'        => 'ids',
				'type'          => 'shop_order',
			)
		);
		return ! empty( $ids );
	}

	/**
	 * 'active' | 'used' | 'expired' for one of our coupons.
	 *
	 * @param WC_Coupon $c Coupon.
	 */
	public static function state( WC_Coupon $c ): string {
		if ( ! $c->get_id() ) {
			return 'deleted';
		}
		if ( $c->get_usage_count() > 0 ) {
			return 'used';
		}
		$exp = $c->get_date_expires();
		if ( $exp && $exp->getTimestamp() < time() ) {
			return 'expired';
		}
		return 'active';
	}

	/** The coupon of an order, or null (deleted, or none). */
	public static function for_order( WC_Order $order ): ?WC_Coupon {
		$id = (int) $order->get_meta( self::META_ID, true );
		if ( $id <= 0 || 'shop_coupon' !== get_post_type( $id ) ) {
			return null;
		}
		$c = new WC_Coupon( $id );
		return $c->get_id() ? $c : null;
	}

	/* =====================================================================
	 * Texts
	 * =================================================================== */

	/** "10 %" or "5,00 €" (plain text) for a coupon. */
	public static function amount_text( WC_Coupon $c ): string {
		if ( 'percent' === $c->get_discount_type() ) {
			return wc_format_localized_decimal( (string) (float) $c->get_amount() ) . '%';
		}
		return html_entity_decode( wp_strip_all_tags( wc_price( (float) $c->get_amount() ) ), ENT_QUOTES, 'UTF-8' );
	}

	/** Placeholder values for a coupon (plain text). */
	public static function vars( WC_Coupon $c, ?WC_Order $order = null ): array {
		$exp = $c->get_date_expires();
		$min = (float) $c->get_minimum_amount();
		return array(
			'amount'     => self::amount_text( $c ),
			'code'       => strtoupper( $c->get_code() ), // Codes are case-insensitive; shown as in the box.
			'expiry'     => $exp ? wc_format_datetime( $exp, wc_date_format() ) : '',
			'min_spend'  => $min > 0 ? html_entity_decode( wp_strip_all_tags( wc_price( $min ) ), ENT_QUOTES, 'UTF-8' ) : '',
			'first_name' => $order ? (string) $order->get_billing_first_name() : '',
			'shop_name'  => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		);
	}

	/* =====================================================================
	 * Emails
	 * =================================================================== */

	/**
	 * woocommerce_email_after_order_table: the coupon box in the chosen
	 * customer emails (c_email = 'order'), while it is active.
	 *
	 * @param WC_Order      $order         Order.
	 * @param bool          $sent_to_admin Sent to the admin.
	 * @param bool          $plain_text    Plain text.
	 * @param WC_Email|null $email         Email.
	 */
	public static function email_block( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( self::$broken || $sent_to_admin || ! $order instanceof WC_Order || Checkout_Extras::killed() ) {
			return;
		}
		$s = CKE_Settings::get();
		if ( 'order' !== $s['c_email'] || ! is_object( $email ) || ! isset( $email->id ) || ! in_array( $email->id, $s['c_email_ids'], true ) ) {
			return;
		}
		try {
			$c = self::for_order( $order );
			if ( ! $c || 'active' !== self::state( $c ) ) {
				return;
			}
			echo self::email_html( $c, $order, (bool) $plain_text, 'c_body' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in email_html().
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * The coupon box for emails (inline styles, email clients ignore classes).
	 *
	 * @param WC_Coupon $c        Coupon.
	 * @param WC_Order  $order    Source order.
	 * @param bool      $plain    Plain text email.
	 * @param string    $body_key Text key for the body.
	 */
	public static function email_html( WC_Coupon $c, WC_Order $order, bool $plain, string $body_key ): string {
		$vars  = self::vars( $c, $order );
		$title = CKE_Settings::fill( CKE_Settings::text( 'c_title' ), $vars );
		$body  = CKE_Settings::fill( CKE_Settings::text( $body_key ), $vars );
		if ( $plain ) {
			$out = "\n";
			if ( '' !== $title ) {
				$out .= wp_strip_all_tags( $title ) . "\n";
			}
			return $out . wp_strip_all_tags( $body ) . "\n" . $c->get_code() . "\n\n";
		}
		$out = '<div class="cke-email-coupon" style="margin:24px 0;padding:18px 20px;border:2px dashed #6d4aff;border-radius:6px;text-align:center;">';
		if ( '' !== $title ) {
			$out .= '<h2 style="margin:0 0 8px;">' . esc_html( $title ) . '</h2>';
		}
		$out .= '<p style="margin:0 0 12px;">' . nl2br( esc_html( $body ), false ) . '</p>';
		$out .= '<p style="margin:0;font-size:22px;font-weight:700;letter-spacing:2px;font-family:monospace;">' . esc_html( strtoupper( $c->get_code() ) ) . '</p>';
		$out .= '</div>';
		return $out;
	}

	/* =====================================================================
	 * Redemption tracking
	 * =================================================================== */

	/**
	 * woocommerce_order_status_changed: an order that used one of our
	 * coupons is marked, and so is the order that issued it.
	 */
	public static function track_redeem( $order_id, $from, $to, $order = null ): void {
		if ( self::$broken || ! in_array( $to, array( 'processing', 'completed', 'on-hold' ), true ) ) {
			return;
		}
		try {
			$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || '' !== (string) $order->get_meta( self::META_REDEEMED, true ) ) {
				return;
			}
			foreach ( $order->get_coupon_codes() as $code ) {
				$cid = wc_get_coupon_id_by_code( (string) $code );
				$src = $cid ? (int) get_post_meta( $cid, self::META_SOURCE, true ) : 0;
				if ( $src <= 0 ) {
					continue;
				}
				$order->update_meta_data( self::META_REDEEMED, (string) $code );
				$order->save_meta_data();
				$source = wc_get_order( $src );
				if ( $source instanceof WC_Order && $source->get_id() !== $order->get_id() ) {
					$source->update_meta_data( self::META_USED_IN, (string) $order->get_id() );
					$source->save_meta_data();
				}
				return;
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/* =====================================================================
	 * Background: cleanup and reminders (Action Scheduler)
	 * =================================================================== */

	/** admin_init: the daily cleanup is scheduled while it is on, removed when off. */
	public static function schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || wp_doing_ajax() ) {
			return;
		}
		try {
			$on  = CKE_Settings::get()['c_cleanup'] && 'off' !== CKE_Settings::get()['c_mode'];
			$has = as_has_scheduled_action( self::HOOK_CLEAN, array(), self::GROUP );
			if ( $on && ! $has ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK_CLEAN, array(), self::GROUP );
			} elseif ( ! $on && $has ) {
				as_unschedule_all_actions( self::HOOK_CLEAN );
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/**
	 * Deletes our own expired, never used coupons, c_cleanup_days after
	 * their expiry, at most CLEAN_BATCH per run.
	 *
	 * @return int Coupons deleted.
	 */
	public static function cleanup(): int {
		$s = CKE_Settings::get();
		if ( ! $s['c_cleanup'] ) {
			return 0;
		}
		$before = time() - $s['c_cleanup_days'] * DAY_IN_SECONDS;
		$q      = new WP_Query(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => self::CLEAN_BATCH,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- daily background job.
					'relation' => 'AND',
					array(
						'key'     => self::META_SOURCE,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => 'date_expires',
						'value'   => $before,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => 'usage_count',
							'value'   => 0,
							'compare' => '<=',
							'type'    => 'NUMERIC',
						),
						array(
							'key'     => 'usage_count',
							'compare' => 'NOT EXISTS',
						),
					),
				),
			)
		);
		$n = 0;
		foreach ( $q->posts as $id ) {
			$c = new WC_Coupon( (int) $id );
			$e = $c->get_date_expires();
			// Double check through CRUD before deleting.
			if ( $c->get_id() && '' !== (string) $c->get_meta( self::META_SOURCE, true ) && 0 === (int) $c->get_usage_count() && $e && $e->getTimestamp() < $before ) {
				$c->delete( true );
				++$n;
			}
		}
		return $n;
	}

	/** Schedules the reminder for a new coupon (when on and still in the future). */
	private static function schedule_reminder( WC_Coupon $c ): void {
		$s = CKE_Settings::get();
		if ( ! $s['c_remind'] || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$exp = $c->get_date_expires();
		if ( ! $exp ) {
			return;
		}
		$at = $exp->getTimestamp() - $s['c_remind_days'] * DAY_IN_SECONDS;
		if ( $at > time() + HOUR_IN_SECONDS ) {
			as_schedule_single_action( $at, self::HOOK_REMIND, array( (int) $c->get_id() ), self::GROUP );
		}
	}

	/**
	 * The reminder email (Action Scheduler), only when the coupon is still
	 * active and the reminder still on.
	 *
	 * @param int $coupon_id Coupon id.
	 */
	public static function remind( $coupon_id ): void {
		if ( Checkout_Extras::killed() || ! CKE_Settings::get()['c_remind'] ) {
			return;
		}
		$c = new WC_Coupon( absint( $coupon_id ) );
		if ( ! $c->get_id() || 'active' !== self::state( $c ) ) {
			return;
		}
		$order = wc_get_order( (int) $c->get_meta( self::META_SOURCE, true ) );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$mailer = WC()->mailer();
		$emails = $mailer ? $mailer->get_emails() : array();
		if ( isset( $emails['CKE_Email_Reminder'] ) ) {
			$emails['CKE_Email_Reminder']->trigger( $c, $order );
		}
	}

	/* =====================================================================
	 * Stats
	 * =================================================================== */

	/**
	 * Coupons issued for orders created in [from, to] (site dates, Y-m-d),
	 * with their state.
	 *
	 * @return array { issued, used, revenue, discount, rows[] }
	 */
	public static function stats( string $from, string $to, int $max_rows = 50 ): array {
		$out  = array(
			'issued'   => 0,
			'used'     => 0,
			'revenue'  => 0.0,
			'discount' => 0.0,
			'rows'     => array(),
		);
		// Bible §1.3: local day bounds as timestamps.
		$tz      = wp_timezone();
		$from_ts = ( new DateTimeImmutable( $from . ' 00:00:00', $tz ) )->getTimestamp();
		$to_ts   = ( new DateTimeImmutable( $to . ' 23:59:59', $tz ) )->getTimestamp();
		$page    = 1;
		do {
			$orders = wc_get_orders(
				array(
					'type'         => 'shop_order',
					'status'       => array_keys( wc_get_order_statuses() ),
					'date_created' => $from_ts . '...' . $to_ts,
					'meta_key'     => self::META_CODE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin report.
					'meta_compare' => 'EXISTS',
					'orderby'      => 'date',
					'order'        => 'DESC',
					'limit'        => 200,
					'paged'        => $page,
				)
			);
			foreach ( $orders as $o ) {
				if ( ! $o instanceof WC_Order ) {
					continue;
				}
				++$out['issued'];
				$code   = (string) $o->get_meta( self::META_CODE, true );
				$usedin = (int) $o->get_meta( self::META_USED_IN, true );
				$c      = self::for_order( $o );
				$state  = $c ? self::state( $c ) : 'deleted';
				$used   = null;
				if ( $usedin > 0 ) {
					$used = wc_get_order( $usedin );
					if ( $used instanceof WC_Order ) {
						++$out['used'];
						$out['revenue'] += (float) $used->get_total();
						foreach ( $used->get_items( 'coupon' ) as $ci ) {
							if ( $ci instanceof WC_Order_Item_Coupon && strtolower( $ci->get_code() ) === strtolower( $code ) ) {
								$out['discount'] += (float) $ci->get_discount() + (float) $ci->get_discount_tax();
							}
						}
						$state = 'used';
					}
				}
				if ( count( $out['rows'] ) < $max_rows ) {
					$exp           = $c ? $c->get_date_expires() : null;
					$out['rows'][] = array(
						'code'    => $code,
						'order'   => $o,
						'email'   => (string) $o->get_billing_email(),
						'expiry'  => $exp ? wc_format_datetime( $exp, wc_date_format() ) : '',
						'state'   => $state,
						'used_in' => $used instanceof WC_Order ? $used : null,
					);
				}
			}
			++$page;
		} while ( count( $orders ) === 200 && $page <= 50 );
		return $out;
	}
}

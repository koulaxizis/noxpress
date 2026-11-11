<?php
/**
 * TRA_Consign — module "consign": books given to a trade account on
 * consignment (παρακαταθήκη), from delivery to settlement and payment.
 *
 * One consignment = one bookstore (a trade account), the day the books were
 * given, a settle-by date, lines (product, copies, unit price) and its
 * history. Stored as a private post type tra_consignment (never public,
 * no admin screen of its own: the Consignments tab of Trade Accounts) with:
 *  - post meta _trc          the record (array, see blank())
 *  - post meta _trc_account  the account's user id (index)
 *  - post meta _trc_state    open | closed (index)
 *  - post meta _trc_due      settle-by date Y-m-d (index)
 *
 * Money is counted only when it is collected (Christos, 11/11/2026):
 *  - giving books is not a sale: copies leave the shop's stock (products
 *    that manage stock), nothing reaches orders, Revenue Splitter, the
 *    Production Ledger or reports;
 *  - a settlement («Εκκαθάριση»: the bookstore sold X, returns Y) makes an
 *    ordinary WooCommerce order for the X copies only, at the price of the
 *    delivery, in status «Σε αναμονή» (on-hold), which Revenue Splitter
 *    does not count (it counts completed and processing); the Y copies go
 *    back to stock;
 *  - «Εισπράχθηκε» completes that order: only then do Revenue Splitter,
 *    the Production Ledger and Store Pulse count it. Completing the order
 *    from the WooCommerce orders screen does the same; cancelling it puts
 *    its copies back as still at the bookstore.
 *
 * Settlement orders never touch stock again (their copies left at
 * delivery: the order is marked as stock-reduced) and send no e-mail to
 * the bookstore or the shop, unless the setting c_mail is on (the
 * «completed» e-mail when the money is collected).
 *
 * Stock follows each product's current setting: a delivery reduces the
 * stock of products that manage stock, a return increases it. A product
 * without stock management is recorded the same way, with no stock change.
 *
 * Amounts are integer cents, VAT included, like the shop's prices
 * (Bible §20). Dates are local dates Y-m-d.
 *
 * Read by orOS (REST GET /wc/v3/tra/consignments, WooCommerce REST keys
 * with read access, manage_woocommerce) and by Revenue Splitter's author
 * portal (tra_consignment_lines()).
 */

defined( 'ABSPATH' ) || exit;

final class TRA_Consign {

	const CPT       = 'tra_consignment';
	const META      = '_trc';
	const ORDER_META = '_tra_consignment';
	const MAX_LINES = 60;
	const MAX_QTY   = 9999;

	/** @var bool True while this class completes an order (no loop through the status hook). */
	private static $busy = false;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_completed' ), 20, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_cancelled' ), 20, 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		if ( is_admin() ) {
			add_action( 'woocommerce_product_options_stock_status', array( __CLASS__, 'product_box' ) );
		}
	}

	public static function register(): void {
		register_post_type(
			self::CPT,
			array(
				'label'               => 'Trade consignments',
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/* =====================================================================
	 * Record
	 * =================================================================== */

	private static function blank(): array {
		return array(
			'v'       => 1,
			'account' => 0,
			'given'   => '',
			'due'     => '',
			'doc'     => '',
			'note'    => '',
			'lines'   => array(),
			'orders'  => array(),
			'log'     => array(),
		);
	}

	/** Today as a local date Y-m-d. */
	public static function today(): string {
		return wp_date( 'Y-m-d' );
	}

	/** A valid Y-m-d date, or ''. */
	public static function date( string $d ): string {
		$d = trim( $d );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return $d;
	}

	/** A date plus a number of days. */
	public static function add_days( string $d, int $days ): string {
		$t = strtotime( $d . ' 12:00:00 UTC' );
		return false === $t ? '' : gmdate( 'Y-m-d', $t + $days * DAY_IN_SECONDS );
	}

	/** Cents from a decimal string ("12,50", "12.5") or number; null when not a price. */
	public static function cents( $v ): ?int {
		if ( is_int( $v ) || is_float( $v ) ) {
			return $v < 0 ? null : (int) round( $v * 100 );
		}
		$v = str_replace( array( ' ', '€' ), '', str_replace( ',', '.', trim( (string) $v ) ) );
		if ( '' === $v || ! is_numeric( $v ) || (float) $v < 0 ) {
			return null;
		}
		return (int) round( (float) $v * 100 );
	}

	/** Cents as a decimal string with the shop's decimals. */
	public static function money( int $c ): string {
		return number_format( $c / 100, wc_get_price_decimals(), '.', '' );
	}

	/** The record of a consignment, or null. */
	private static function raw( int $id ): ?array {
		$p = get_post( $id );
		if ( ! $p instanceof WP_Post || self::CPT !== $p->post_type || 'trash' === $p->post_status ) {
			return null;
		}
		$r = get_post_meta( $id, self::META, true );
		return is_array( $r ) ? array_merge( self::blank(), $r ) : null;
	}

	private static function store( int $id, array $r ): void {
		update_post_meta( $id, self::META, $r );
		update_post_meta( $id, '_trc_account', (int) $r['account'] );
		update_post_meta( $id, '_trc_state', self::open_qty( $r ) > 0 ? 'open' : 'closed' );
		update_post_meta( $id, '_trc_due', (string) $r['due'] );
	}

	private static function log( array &$r, string $action, string $text ): void {
		$r['log'][] = array(
			't' => time(),
			'u' => get_current_user_id(),
			'a' => $action,
			'd' => mb_substr( $text, 0, 500 ),
		);
		if ( count( $r['log'] ) > 200 ) {
			$r['log'] = array_slice( $r['log'], -200 );
		}
	}

	/** Copies still at the bookstore. */
	private static function open_qty( array $r ): int {
		$n = 0;
		foreach ( $r['lines'] as $l ) {
			$n += max( 0, (int) $l['q'] - (int) $l['sold'] - (int) $l['ret'] );
		}
		return $n;
	}

	/** The account's name for people: company, else display name. */
	public static function account_name( int $uid ): string {
		$company = (string) get_user_meta( $uid, 'billing_company', true );
		if ( '' !== $company ) {
			return $company;
		}
		$u = get_userdata( $uid );
		return $u instanceof WP_User ? (string) $u->display_name : '#' . $uid;
	}

	/** The default unit price (cents, VAT included) of a product for an account. */
	public static function default_price( WC_Product $product, int $uid ): ?int {
		$discount = TRA_Accounts::discount( $uid );
		$price    = TRA_Scope::trade_price( $product, $discount );
		return null === $price ? null : self::cents( $price );
	}

	/* =====================================================================
	 * Changes
	 * =================================================================== */

	/**
	 * A new consignment. $in: account (user id of a trade account), given
	 * (Y-m-d, default today), due (Y-m-d, default given + c_days), doc
	 * (delivery note number), note, lines: list of {product, qty, price}
	 * (price as a decimal string or cents in price_c; empty = the account's
	 * trade price). Copies leave the stock of products that manage stock.
	 *
	 * @return int|WP_Error Consignment id.
	 */
	public static function create( array $in ) {
		$uid = isset( $in['account'] ) ? (int) $in['account'] : 0;
		if ( $uid <= 0 || ! TRA_Accounts::is_trade( $uid ) ) {
			return new WP_Error( 'trc_account', __( 'Διάλεξε λογαριασμό χονδρικής (βιβλιοπωλείο).', 'trade-accounts' ) );
		}
		$given = isset( $in['given'] ) && '' !== (string) $in['given'] ? self::date( (string) $in['given'] ) : self::today();
		if ( '' === $given ) {
			return new WP_Error( 'trc_date', __( 'Η ημερομηνία παράδοσης δεν είναι έγκυρη.', 'trade-accounts' ) );
		}
		$due = isset( $in['due'] ) && '' !== (string) $in['due'] ? self::date( (string) $in['due'] ) : self::add_days( $given, (int) TRA_Settings::get()['c_days'] );
		if ( '' === $due || $due < $given ) {
			return new WP_Error( 'trc_due', __( 'Η προθεσμία εκκαθάρισης δεν είναι έγκυρη (πρέπει να είναι μετά την παράδοση).', 'trade-accounts' ) );
		}

		$lines = array();
		foreach ( isset( $in['lines'] ) && is_array( $in['lines'] ) ? $in['lines'] : array() as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$pid = isset( $l['product'] ) ? (int) $l['product'] : 0;
			$qty = isset( $l['qty'] ) ? (int) $l['qty'] : 0;
			if ( $pid <= 0 && $qty <= 0 ) {
				continue; // An empty row of the form.
			}
			$product = $pid > 0 ? wc_get_product( $pid ) : null;
			if ( ! $product instanceof WC_Product || $product->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
				/* translators: %d: product id */
				return new WP_Error( 'trc_product', sprintf( __( 'Το προϊόν #%d δεν υπάρχει ή δεν είναι απλό προϊόν.', 'trade-accounts' ), $pid ) );
			}
			if ( $qty < 1 || $qty > self::MAX_QTY ) {
				/* translators: %s: product name */
				return new WP_Error( 'trc_qty', sprintf( __( 'Τα τεμάχια του «%s» πρέπει να είναι από 1 έως 9999.', 'trade-accounts' ), $product->get_name() ) );
			}
			if ( isset( $lines[ $pid ] ) ) {
				/* translators: %s: product name */
				return new WP_Error( 'trc_dup', sprintf( __( 'Το «%s» υπάρχει δύο φορές: βάλε όλα τα τεμάχια σε μία γραμμή.', 'trade-accounts' ), $product->get_name() ) );
			}
			$price = null;
			if ( isset( $l['price_c'] ) && is_numeric( $l['price_c'] ) && (int) $l['price_c'] >= 0 ) {
				$price = (int) $l['price_c'];
			} elseif ( isset( $l['price'] ) && '' !== trim( (string) $l['price'] ) ) {
				$price = self::cents( (string) $l['price'] );
				if ( null === $price ) {
					/* translators: %s: product name */
					return new WP_Error( 'trc_price', sprintf( __( 'Η τιμή του «%s» δεν είναι έγκυρη.', 'trade-accounts' ), $product->get_name() ) );
				}
			}
			if ( null === $price ) {
				$price = self::default_price( $product, $uid );
			}
			if ( null === $price ) {
				/* translators: %s: product name */
				return new WP_Error( 'trc_price', sprintf( __( 'Το «%s» δεν έχει τιμή: γράψε την τιμή μονάδας.', 'trade-accounts' ), $product->get_name() ) );
			}
			$lines[ $pid ] = array(
				'p'     => $pid,
				'name'  => $product->get_name(),
				'isbn'  => self::isbn( $product ),
				'q'     => $qty,
				'pc'    => $price,
				'sold'  => 0,
				'ret'   => 0,
				'stock' => $product->managing_stock(),
			);
		}
		if ( ! $lines ) {
			return new WP_Error( 'trc_lines', __( 'Πρόσθεσε τουλάχιστον έναν τίτλο με τεμάχια.', 'trade-accounts' ) );
		}
		if ( count( $lines ) > self::MAX_LINES ) {
			return new WP_Error( 'trc_lines', __( 'Πάρα πολλές γραμμές σε μία παρακαταθήκη (έως 60).', 'trade-accounts' ) );
		}

		$name = self::account_name( $uid );
		$id   = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'post_title'  => $name . ' · ' . $given,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$id = (int) $id;

		$r            = self::blank();
		$r['account'] = $uid;
		$r['given']   = $given;
		$r['due']     = $due;
		$r['doc']     = isset( $in['doc'] ) ? mb_substr( sanitize_text_field( (string) $in['doc'] ), 0, 60 ) : '';
		$r['note']    = isset( $in['note'] ) ? mb_substr( sanitize_textarea_field( (string) $in['note'] ), 0, 2000 ) : '';
		$r['lines']   = array_values( $lines );

		$moved = array();
		foreach ( $r['lines'] as $l ) {
			if ( $l['stock'] ) {
				$product = wc_get_product( $l['p'] );
				if ( $product instanceof WC_Product ) {
					wc_update_product_stock( $product, (int) $l['q'], 'decrease' );
					$moved[] = $l['name'] . ' −' . $l['q'];
				}
			}
		}
		$total = 0;
		$count = 0;
		foreach ( $r['lines'] as $l ) {
			$total += $l['pc'] * $l['q'];
			$count += $l['q'];
		}
		self::log(
			$r,
			'give',
			/* translators: 1: copies, 2: amount, 3: stock changes */
			sprintf( __( 'Παράδοση %1$d τεμαχίων, αξία %2$s. Απόθεμα: %3$s', 'trade-accounts' ), $count, self::money( $total ), $moved ? implode( ', ', $moved ) : __( 'χωρίς αλλαγή (χωρίς διαχείριση αποθέματος)', 'trade-accounts' ) )
		);
		self::store( $id, $r );

		/**
		 * After a consignment is recorded.
		 *
		 * @param int   $id Consignment id.
		 * @param array $c  The consignment as get() answers it.
		 */
		do_action( 'tra_consignment_created', $id, self::get( $id ) );
		return $id;
	}

	/**
	 * A settlement: per product, copies sold and copies returned. Sold
	 * copies become one on-hold order; returned ones go back to stock.
	 *
	 * @param array  $sold product id => copies sold.
	 * @param array  $ret  product id => copies returned.
	 * @param string $date Y-m-d of the settlement (default today).
	 * @return array|WP_Error {order: int (0 when nothing sold), open: int}.
	 */
	public static function settle( int $id, array $sold, array $ret, string $date = '' ) {
		$r = self::raw( $id );
		if ( null === $r ) {
			return new WP_Error( 'trc_none', __( 'Η παρακαταθήκη δεν βρέθηκε.', 'trade-accounts' ) );
		}
		$date = '' !== $date ? self::date( $date ) : self::today();
		if ( '' === $date ) {
			return new WP_Error( 'trc_date', __( 'Η ημερομηνία εκκαθάρισης δεν είναι έγκυρη.', 'trade-accounts' ) );
		}

		$sell = array();
		$back = array();
		foreach ( $r['lines'] as $l ) {
			$pid  = (int) $l['p'];
			$open = (int) $l['q'] - (int) $l['sold'] - (int) $l['ret'];
			$s    = isset( $sold[ $pid ] ) ? max( 0, (int) $sold[ $pid ] ) : 0;
			$b    = isset( $ret[ $pid ] ) ? max( 0, (int) $ret[ $pid ] ) : 0;
			if ( $s + $b > $open ) {
				/* translators: 1: product name, 2: copies still at the bookstore */
				return new WP_Error( 'trc_over', sprintf( __( 'Στο «%1$s» έμειναν %2$d τεμάχια στο βιβλιοπωλείο: πουλημένα και επιστροφές δεν μπορούν να είναι περισσότερα.', 'trade-accounts' ), $l['name'], $open ) );
			}
			if ( $s > 0 ) {
				$sell[ $pid ] = $s;
			}
			if ( $b > 0 ) {
				$back[ $pid ] = $b;
			}
		}
		if ( ! $sell && ! $back ) {
			return new WP_Error( 'trc_empty', __( 'Γράψε πόσα πουλήθηκαν ή επιστράφηκαν.', 'trade-accounts' ) );
		}

		$order_id = 0;
		if ( $sell ) {
			$order_id = self::make_order( $id, $r, $sell, $date );
			if ( is_wp_error( $order_id ) ) {
				return $order_id;
			}
		}

		$moved = array();
		foreach ( $r['lines'] as $i => $l ) {
			$pid = (int) $l['p'];
			if ( isset( $sell[ $pid ] ) ) {
				$r['lines'][ $i ]['sold'] = (int) $l['sold'] + $sell[ $pid ];
			}
			if ( isset( $back[ $pid ] ) ) {
				$r['lines'][ $i ]['ret'] = (int) $l['ret'] + $back[ $pid ];
				$product                 = wc_get_product( $pid );
				if ( $product instanceof WC_Product && $product->managing_stock() ) {
					wc_update_product_stock( $product, $back[ $pid ], 'increase' );
					$moved[] = $l['name'] . ' +' . $back[ $pid ];
				}
			}
		}

		$parts = array();
		if ( $sell ) {
			/* translators: 1: copies, 2: order number */
			$parts[] = sprintf( __( 'πουλήθηκαν %1$d (παραγγελία #%2$d, σε αναμονή μέχρι την είσπραξη)', 'trade-accounts' ), array_sum( $sell ), $order_id );
		}
		if ( $back ) {
			/* translators: %d: copies */
			$parts[] = sprintf( __( 'επιστράφηκαν %d', 'trade-accounts' ), array_sum( $back ) );
		}
		if ( $moved ) {
			/* translators: %s: stock changes */
			$parts[] = sprintf( __( 'απόθεμα %s', 'trade-accounts' ), implode( ', ', $moved ) );
		}
		/* translators: 1: date, 2: what happened */
		self::log( $r, 'settle', sprintf( __( 'Εκκαθάριση %1$s: %2$s.', 'trade-accounts' ), $date, implode( ', ', $parts ) ) );
		self::store( $id, $r );

		do_action( 'tra_consignment_settled', $id, self::get( $id ), $order_id );
		return array(
			'order' => (int) $order_id,
			'open'  => self::open_qty( $r ),
		);
	}

	/**
	 * The settlement order: the sold copies at the delivery's price, for
	 * the account, on-hold, stock already reduced, no e-mails.
	 *
	 * @return int|WP_Error
	 */
	private static function make_order( int $id, array &$r, array $sell, string $date ) {
		$uid      = (int) $r['account'];
		$discount = TRA_Accounts::discount( $uid );

		$mute = static function () {
			return false;
		};
		add_filter( 'woocommerce_email_enabled_new_order', $mute, 99 );
		add_filter( 'woocommerce_email_enabled_customer_on_hold_order', $mute, 99 );
		add_filter( 'woocommerce_email_enabled_customer_processing_order', $mute, 99 );

		try {
			$order = wc_create_order(
				array(
					'customer_id' => $uid,
					'created_via' => 'tra-consignment',
				)
			);
			if ( is_wp_error( $order ) ) {
				return $order;
			}
			if ( ! $order instanceof WC_Order ) {
				return new WP_Error( 'trc_order', __( 'Η παραγγελία δεν δημιουργήθηκε.', 'trade-accounts' ) );
			}

			if ( class_exists( 'WC_Customer' ) ) {
				$customer = new WC_Customer( $uid );
				foreach ( array( 'billing', 'shipping' ) as $type ) {
					$getter = 'get_' . $type;
					foreach ( (array) $customer->$getter() as $k => $v ) {
						$setter = 'set_' . $type . '_' . $k;
						if ( is_callable( array( $order, $setter ) ) ) {
							$order->$setter( $v );
						}
					}
				}
			}

			foreach ( $r['lines'] as $l ) {
				$pid = (int) $l['p'];
				if ( ! isset( $sell[ $pid ] ) ) {
					continue;
				}
				$product = wc_get_product( $pid );
				$qty     = (int) $sell[ $pid ];
				$gross   = ( (int) $l['pc'] * $qty ) / 100;
				$net     = $gross;
				if ( $product instanceof WC_Product && wc_tax_enabled() && $product->is_taxable() ) {
					$rates = WC_Tax::get_base_tax_rates( $product->get_tax_class( 'edit' ) );
					$net   = $gross - array_sum( WC_Tax::calc_tax( $gross, $rates, true ) );
				}
				if ( $product instanceof WC_Product ) {
					$item_id = $order->add_product(
						$product,
						$qty,
						array(
							'subtotal' => $net,
							'total'    => $net,
						)
					);
				} else {
					$item = new WC_Order_Item_Product();
					$item->set_name( (string) $l['name'] );
					$item->set_quantity( $qty );
					$item->set_subtotal( $net );
					$item->set_total( $net );
					$order->add_item( $item );
					$item->save();
					$item_id = $item->get_id();
				}
				$item = $order->get_item( $item_id );
				if ( $item instanceof WC_Order_Item_Product ) {
					$item->add_meta_data( '_tra_discount', (string) $discount, true );
					$item->save();
				}
			}

			$order->update_meta_data( '_tra_trade', (string) $discount );
			$order->update_meta_data( self::ORDER_META, (string) $id );
			$order->set_payment_method_title( __( 'Εκκαθάριση παρακαταθήκης', 'trade-accounts' ) );
			$created = strtotime( $date . ' 12:00:00' );
			if ( $date !== self::today() && false !== $created ) {
				$order->set_date_created( $created );
			}
			$order->calculate_totals( true );
			$order->save();

			// The copies left the stock at delivery: never again.
			$store = $order->get_data_store();
			if ( is_object( $store ) && method_exists( $store, 'set_stock_reduced' ) ) {
				$store->set_stock_reduced( $order->get_id(), true );
			}

			$order->update_status(
				'on-hold',
				/* translators: 1: consignment id, 2: date */
				sprintf( __( 'Εκκαθάριση της παρακαταθήκης #%1$d (%2$s). Τα τεμάχια είχαν φύγει από το απόθεμα στην παράδοση. Μετράει στις πωλήσεις όταν σημειωθεί η είσπραξη.', 'trade-accounts' ), $id, $date )
			);

			$r['orders'][] = array(
				'id'    => $order->get_id(),
				'date'  => $date,
				'lines' => $sell,
				'paid'  => '',
				'gone'  => false,
			);
			return (int) $order->get_id();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'trc_order', __( 'Η παραγγελία δεν δημιουργήθηκε.', 'trade-accounts' ) . ' ' . $e->getMessage() );
		} finally {
			remove_filter( 'woocommerce_email_enabled_new_order', $mute, 99 );
			remove_filter( 'woocommerce_email_enabled_customer_on_hold_order', $mute, 99 );
			remove_filter( 'woocommerce_email_enabled_customer_processing_order', $mute, 99 );
		}
	}

	/**
	 * «Εισπράχθηκε»: the settlement order is completed (paid on $date) and
	 * from now on counts in Revenue Splitter and the reports.
	 *
	 * @return true|WP_Error
	 */
	public static function paid( int $id, int $order_id, string $date = '' ) {
		$r = self::raw( $id );
		if ( null === $r ) {
			return new WP_Error( 'trc_none', __( 'Η παρακαταθήκη δεν βρέθηκε.', 'trade-accounts' ) );
		}
		$date = '' !== $date ? self::date( $date ) : self::today();
		if ( '' === $date ) {
			return new WP_Error( 'trc_date', __( 'Η ημερομηνία είσπραξης δεν είναι έγκυρη.', 'trade-accounts' ) );
		}
		$i = self::order_index( $r, $order_id );
		if ( $i < 0 ) {
			return new WP_Error( 'trc_order', __( 'Η παραγγελία δεν ανήκει σε αυτή την παρακαταθήκη.', 'trade-accounts' ) );
		}
		if ( '' !== $r['orders'][ $i ]['paid'] ) {
			return new WP_Error( 'trc_paid', __( 'Η είσπραξη έχει ήδη σημειωθεί.', 'trade-accounts' ) );
		}
		if ( ! empty( $r['orders'][ $i ]['gone'] ) ) {
			return new WP_Error( 'trc_gone', __( 'Η παραγγελία ακυρώθηκε.', 'trade-accounts' ) );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'trc_order', __( 'Η παραγγελία δεν βρέθηκε.', 'trade-accounts' ) );
		}

		$r['orders'][ $i ]['paid'] = $date;
		/* translators: 1: order number, 2: amount, 3: date */
		self::log( $r, 'paid', sprintf( __( 'Είσπραξη παραγγελίας #%1$d, %2$s, στις %3$s.', 'trade-accounts' ), $order_id, wc_format_decimal( $order->get_total(), wc_get_price_decimals() ), $date ) );
		self::store( $id, $r );

		$mute = static function () {
			return false;
		};
		if ( ! TRA_Settings::get()['c_mail'] ) {
			add_filter( 'woocommerce_email_enabled_customer_completed_order', $mute, 99 );
		}
		self::$busy = true;
		try {
			$paid_at = strtotime( $date . ' 12:00:00' );
			$order->set_date_paid( false !== $paid_at && $date !== self::today() ? $paid_at : time() );
			$order->update_status( 'completed', __( 'Η είσπραξη της παρακαταθήκης σημειώθηκε.', 'trade-accounts' ) );
		} finally {
			self::$busy = false;
			remove_filter( 'woocommerce_email_enabled_customer_completed_order', $mute, 99 );
		}
		do_action( 'tra_consignment_paid', $id, self::get( $id ), $order_id );
		return true;
	}

	/**
	 * A new settle-by date.
	 *
	 * @return true|WP_Error
	 */
	public static function extend( int $id, string $due ) {
		$r = self::raw( $id );
		if ( null === $r ) {
			return new WP_Error( 'trc_none', __( 'Η παρακαταθήκη δεν βρέθηκε.', 'trade-accounts' ) );
		}
		$due = self::date( $due );
		if ( '' === $due || $due < $r['given'] ) {
			return new WP_Error( 'trc_due', __( 'Η προθεσμία εκκαθάρισης δεν είναι έγκυρη (πρέπει να είναι μετά την παράδοση).', 'trade-accounts' ) );
		}
		/* translators: 1: old date, 2: new date */
		self::log( $r, 'extend', sprintf( __( 'Νέα προθεσμία: %1$s → %2$s.', 'trade-accounts' ), $r['due'], $due ) );
		$r['due'] = $due;
		self::store( $id, $r );
		return true;
	}

	/**
	 * A note in the history (a call, an agreement…), and/or a new delivery
	 * note number.
	 *
	 * @return true|WP_Error
	 */
	public static function note( int $id, string $text, ?string $doc = null ) {
		$r = self::raw( $id );
		if ( null === $r ) {
			return new WP_Error( 'trc_none', __( 'Η παρακαταθήκη δεν βρέθηκε.', 'trade-accounts' ) );
		}
		$text = trim( sanitize_textarea_field( $text ) );
		if ( null !== $doc ) {
			$doc = mb_substr( sanitize_text_field( $doc ), 0, 60 );
			if ( $doc !== $r['doc'] ) {
				/* translators: %s: delivery note number */
				self::log( $r, 'doc', sprintf( __( 'Αριθμός δελτίου: %s', 'trade-accounts' ), '' !== $doc ? $doc : '—' ) );
				$r['doc'] = $doc;
			}
		}
		if ( '' !== $text ) {
			self::log( $r, 'note', $text );
		}
		self::store( $id, $r );
		return true;
	}

	private static function order_index( array $r, int $order_id ): int {
		foreach ( $r['orders'] as $i => $o ) {
			if ( (int) $o['id'] === $order_id ) {
				return (int) $i;
			}
		}
		return -1;
	}

	/** The consignment of a settlement order, or 0. */
	private static function of_order( $order ): int {
		$order = $order instanceof WC_Order ? $order : wc_get_order( (int) $order );
		return $order instanceof WC_Order ? (int) $order->get_meta( self::ORDER_META ) : 0;
	}

	/** Completed from the orders screen: the money counts as collected today. */
	public static function on_completed( $order_id ): void {
		if ( self::$busy ) {
			return;
		}
		$id = self::of_order( $order_id );
		$r  = $id ? self::raw( $id ) : null;
		if ( null === $r ) {
			return;
		}
		$i = self::order_index( $r, (int) $order_id );
		if ( $i < 0 || '' !== $r['orders'][ $i ]['paid'] ) {
			return;
		}
		$r['orders'][ $i ]['paid'] = self::today();
		/* translators: %d: order number */
		self::log( $r, 'paid', sprintf( __( 'Η παραγγελία #%d ολοκληρώθηκε από τις παραγγελίες: σημειώθηκε ως είσπραξη.', 'trade-accounts' ), (int) $order_id ) );
		self::store( $id, $r );
	}

	/** Cancelled: its copies are counted as still at the bookstore. */
	public static function on_cancelled( $order_id ): void {
		$id = self::of_order( $order_id );
		$r  = $id ? self::raw( $id ) : null;
		if ( null === $r ) {
			return;
		}
		$i = self::order_index( $r, (int) $order_id );
		if ( $i < 0 || ! empty( $r['orders'][ $i ]['gone'] ) ) {
			return;
		}
		$lines = (array) $r['orders'][ $i ]['lines'];
		foreach ( $r['lines'] as $k => $l ) {
			$pid = (int) $l['p'];
			if ( isset( $lines[ $pid ] ) ) {
				$r['lines'][ $k ]['sold'] = max( 0, (int) $l['sold'] - (int) $lines[ $pid ] );
			}
		}
		$r['orders'][ $i ]['gone'] = true;
		$r['orders'][ $i ]['paid'] = '';
		/* translators: %d: order number */
		self::log( $r, 'cancel', sprintf( __( 'Η παραγγελία #%d ακυρώθηκε: τα τεμάχιά της μετρούν πάλι ως στο βιβλιοπωλείο.', 'trade-accounts' ), (int) $order_id ) );
		self::store( $id, $r );
	}

	/* =====================================================================
	 * Reading
	 * =================================================================== */

	private static function isbn( WC_Product $product ): string {
		$id = method_exists( $product, 'get_global_unique_id' ) ? (string) $product->get_global_unique_id() : '';
		return '' !== $id ? $id : (string) $product->get_sku();
	}

	/**
	 * A consignment for screens, REST and abilities, or null:
	 * id, account {id, name, email}, given, due, doc, note, state
	 * (open | overdue | closed), next_check (Y-m-d, '' when closed), lines
	 * {product, name, isbn, given, sold, returned, open, price, open_value},
	 * totals {given, sold, returned, open, open_value, sold_value,
	 * collected, to_collect}, orders {id, date, paid, cancelled, total,
	 * status, copies}, history {time, user, action, text}.
	 */
	public static function get( int $id ): ?array {
		$r = self::raw( $id );
		if ( null === $r ) {
			return null;
		}
		$uid   = (int) $r['account'];
		$u     = get_userdata( $uid );
		$lines = array();
		$tot   = array_fill_keys( array( 'given', 'sold', 'returned', 'open' ), 0 );
		$ov    = 0;
		foreach ( $r['lines'] as $l ) {
			$open    = max( 0, (int) $l['q'] - (int) $l['sold'] - (int) $l['ret'] );
			$lines[] = array(
				'product'    => (int) $l['p'],
				'name'       => (string) $l['name'],
				'isbn'       => (string) $l['isbn'],
				'given'      => (int) $l['q'],
				'sold'       => (int) $l['sold'],
				'returned'   => (int) $l['ret'],
				'open'       => $open,
				'price'      => self::money( (int) $l['pc'] ),
				'open_value' => self::money( (int) $l['pc'] * $open ),
			);
			$tot['given']    += (int) $l['q'];
			$tot['sold']     += (int) $l['sold'];
			$tot['returned'] += (int) $l['ret'];
			$tot['open']     += $open;
			$ov              += (int) $l['pc'] * $open;
		}
		$orders    = array();
		$collected = 0;
		$pending   = 0;
		foreach ( $r['orders'] as $o ) {
			$order    = wc_get_order( (int) $o['id'] );
			$total_c  = $order instanceof WC_Order ? self::cents( (float) $order->get_total() ) : 0;
			$total_c  = null === $total_c ? 0 : $total_c;
			$gone     = ! empty( $o['gone'] );
			$orders[] = array(
				'id'        => (int) $o['id'],
				'date'      => (string) $o['date'],
				'paid'      => (string) $o['paid'],
				'cancelled' => $gone,
				'total'     => self::money( $total_c ),
				'status'    => $order instanceof WC_Order ? (string) $order->get_status() : 'deleted',
				'copies'    => array_sum( array_map( 'intval', (array) $o['lines'] ) ),
				'edit_url'  => $order instanceof WC_Order ? (string) $order->get_edit_order_url() : '',
			);
			if ( $gone ) {
				continue;
			}
			if ( '' !== $o['paid'] ) {
				$collected += $total_c;
			} else {
				$pending += $total_c;
			}
		}
		$today = self::today();
		$state = $tot['open'] > 0 ? ( $r['due'] < $today ? 'overdue' : 'open' ) : 'closed';
		$check = '';
		$every = (int) TRA_Settings::get()['c_check'];
		if ( $tot['open'] > 0 && $every > 0 ) {
			$last = $r['given'];
			foreach ( $r['log'] as $e ) {
				$d = wp_date( 'Y-m-d', (int) $e['t'] );
				if ( in_array( $e['a'], array( 'settle', 'note', 'extend' ), true ) && $d > $last ) {
					$last = $d;
				}
			}
			$check = self::add_days( $last, $every );
			if ( $check > $r['due'] ) {
				$check = $r['due'];
			}
		}
		$history = array();
		foreach ( array_reverse( $r['log'] ) as $e ) {
			$by        = get_userdata( (int) $e['u'] );
			$history[] = array(
				'time'   => gmdate( 'c', (int) $e['t'] ),
				'user'   => $by instanceof WP_User ? (string) $by->display_name : '',
				'action' => (string) $e['a'],
				'text'   => (string) $e['d'],
			);
		}
		return array(
			'id'         => $id,
			'account'    => array(
				'id'    => $uid,
				'name'  => self::account_name( $uid ),
				'email' => $u instanceof WP_User ? (string) $u->user_email : '',
				'city'  => (string) get_user_meta( $uid, 'billing_city', true ),
			),
			'given'      => (string) $r['given'],
			'due'        => (string) $r['due'],
			'doc'        => (string) $r['doc'],
			'note'       => (string) $r['note'],
			'state'      => $state,
			'next_check' => $check,
			'lines'      => $lines,
			'totals'     => array(
				'given'      => $tot['given'],
				'sold'       => $tot['sold'],
				'returned'   => $tot['returned'],
				'open'       => $tot['open'],
				'open_value' => self::money( $ov ),
				'to_collect' => self::money( $pending ),
				'collected'  => self::money( $collected ),
			),
			'orders'     => $orders,
			'history'    => $history,
		);
	}

	/**
	 * Consignments, newest delivery first. $args: state (open | closed |
	 * overdue | all, default all), account (user id), product (id),
	 * limit (default 200, at most 500).
	 */
	public static function query( array $args = array() ): array {
		$state = isset( $args['state'] ) ? (string) $args['state'] : 'all';
		$meta  = array();
		if ( 'open' === $state || 'overdue' === $state ) {
			$meta[] = array(
				'key'   => '_trc_state',
				'value' => 'open',
			);
		} elseif ( 'closed' === $state ) {
			$meta[] = array(
				'key'   => '_trc_state',
				'value' => 'closed',
			);
		}
		if ( ! empty( $args['account'] ) ) {
			$meta[] = array(
				'key'   => '_trc_account',
				'value' => (int) $args['account'],
			);
		}
		$limit = isset( $args['limit'] ) ? max( 1, min( 500, (int) $args['limit'] ) ) : 200;
		$ids   = get_posts(
			array(
				'post_type'        => self::CPT,
				'post_status'      => 'publish',
				'posts_per_page'   => $limit,
				'fields'           => 'ids',
				'orderby'          => 'date',
				'order'            => 'DESC',
				'meta_query'       => $meta ? array_merge( array( 'relation' => 'AND' ), $meta ) : array(), // phpcs:ignore WordPress.DB.SlowDBQuery -- indexed meta of a small post type.
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);
		$out     = array();
		$product = isset( $args['product'] ) ? (int) $args['product'] : 0;
		foreach ( $ids as $id ) {
			$c = self::get( (int) $id );
			if ( null === $c ) {
				continue;
			}
			if ( 'overdue' === $state && 'overdue' !== $c['state'] ) {
				continue;
			}
			if ( $product > 0 && ! in_array( $product, wp_list_pluck( $c['lines'], 'product' ), true ) ) {
				continue;
			}
			$out[] = $c;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $b['given'], $a['given'] );
			}
		);
		return $out;
	}

	/**
	 * Copies still at bookstores, per product (for Revenue Splitter's
	 * author portal and the product screen): product id => list of
	 * {consignment, account, name, city, given, due, open, overdue}.
	 *
	 * @param int[] $products Product ids; empty = every product.
	 */
	public static function open_lines( array $products = array() ): array {
		$want = array_map( 'intval', $products );
		$out  = array();
		foreach ( self::query( array( 'state' => 'open' ) ) as $c ) {
			foreach ( $c['lines'] as $l ) {
				if ( $l['open'] <= 0 || ( $want && ! in_array( $l['product'], $want, true ) ) ) {
					continue;
				}
				$out[ $l['product'] ][] = array(
					'consignment' => $c['id'],
					'account'     => $c['account']['id'],
					'name'        => $c['account']['name'],
					'city'        => $c['account']['city'],
					'given'       => $c['given'],
					'due'         => $c['due'],
					'open'        => $l['open'],
					'overdue'     => 'overdue' === $c['state'],
				);
			}
		}
		return $out;
	}

	/** Open copies, overdue consignments and value at bookstores, for the tab's header. */
	public static function summary(): array {
		$open    = self::query( array( 'state' => 'open' ) );
		$copies  = 0;
		$value   = 0;
		$overdue = 0;
		$pending = 0;
		foreach ( $open as $c ) {
			$copies += $c['totals']['open'];
			$value  += (int) self::cents( $c['totals']['open_value'] );
			$overdue += 'overdue' === $c['state'] ? 1 : 0;
		}
		foreach ( self::query( array( 'state' => 'all' ) ) as $c ) {
			$pending += (int) self::cents( $c['totals']['to_collect'] );
		}
		return array(
			'consignments' => count( $open ),
			'copies'       => $copies,
			'open_value'   => self::money( $value ),
			'overdue'      => $overdue,
			'to_collect'   => self::money( $pending ),
		);
	}

	/* =====================================================================
	 * Product screen
	 * =================================================================== */

	public static function product_box(): void {
		global $product_object;
		if ( ! $product_object instanceof WC_Product || ! $product_object->get_id() ) {
			return;
		}
		$rows = self::open_lines( array( $product_object->get_id() ) );
		$rows = isset( $rows[ $product_object->get_id() ] ) ? $rows[ $product_object->get_id() ] : array();
		$n    = array_sum( wp_list_pluck( $rows, 'open' ) );
		echo '<p class="form-field"><label>' . esc_html__( 'Σε παρακαταθήκη', 'trade-accounts' ) . '</label>';
		if ( ! $rows ) {
			echo '<span class="description">' . esc_html__( 'κανένα τεμάχιο σε βιβλιοπωλεία', 'trade-accounts' ) . '</span>';
		} else {
			$names = array();
			foreach ( $rows as $row ) {
				$names[] = $row['name'] . ' (' . $row['open'] . ')';
			}
			/* translators: 1: copies, 2: list of bookstores */
			echo '<span class="description">' . esc_html( sprintf( __( '%1$d τεμάχια: %2$s', 'trade-accounts' ), $n, implode( ', ', $names ) ) ) . '</span>';
		}
		echo '</p>';
	}

	/* =====================================================================
	 * REST (read): /wc/v3/tra/consignments
	 * =================================================================== */

	public static function rest(): void {
		register_rest_route(
			'wc/v3',
			'/tra/consignments',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_list' ),
				'permission_callback' => array( __CLASS__, 'rest_allowed' ),
				'args'                => array(
					'state'   => array(
						'type'    => 'string',
						'enum'    => array( 'all', 'open', 'overdue', 'closed' ),
						'default' => 'all',
					),
					'account' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'product' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
		register_rest_route(
			'wc/v3',
			'/tra/consignments/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_one' ),
				'permission_callback' => array( __CLASS__, 'rest_allowed' ),
			)
		);
	}

	public static function rest_allowed(): bool {
		return current_user_can( Trade_Accounts::cap() );
	}

	public static function rest_list( WP_REST_Request $req ): WP_REST_Response {
		$list = self::query(
			array(
				'state'   => (string) $req->get_param( 'state' ),
				'account' => (int) $req->get_param( 'account' ),
				'product' => (int) $req->get_param( 'product' ),
			)
		);
		$res = new WP_REST_Response(
			array(
				'v'            => 1,
				'sent'         => gmdate( 'c' ),
				'summary'      => self::summary(),
				'consignments' => $list,
			)
		);
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/** @return WP_REST_Response|WP_Error */
	public static function rest_one( WP_REST_Request $req ) {
		$c = self::get( (int) $req['id'] );
		if ( null === $c ) {
			return new WP_Error( 'trc_none', 'Consignment not found.', array( 'status' => 404 ) );
		}
		return new WP_REST_Response( $c );
	}

	/* =====================================================================
	 * Abilities (registered by TRA_Abilities when the module is on)
	 * =================================================================== */

	public static function ability_defs(): array {
		$id    = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => 'Consignment id (from consignments-list).',
		);
		$date  = array(
			'type'        => 'string',
			'description' => 'Local date YYYY-MM-DD; empty = today.',
		);
		$count = array(
			'type'                 => 'object',
			'description'          => 'Product id => copies, e.g. {"77133": 2}.',
			'additionalProperties' => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
		);
		return array(
			'consignments-list'  => array(
				'label'       => 'Trade Accounts: consignments',
				'description' => 'Books given to bookstores on consignment: account, delivery and settle-by dates, state (open, overdue, closed), next check date, lines (given, sold, returned, still at the bookstore, unit price), settlement orders (on-hold until paid, then completed) and history. Filters: state, account, product. Changes nothing.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'state'   => array(
							'type' => 'string',
							'enum' => array( 'all', 'open', 'overdue', 'closed' ),
						),
						'account' => array( 'type' => 'integer' ),
						'product' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'ann'         => array( true, false, true ),
				'run'         => array( __CLASS__, 'ab_list' ),
			),
			'consignment-create' => array(
				'label'       => 'Trade Accounts: record a consignment',
				'description' => 'Records books given to a trade account on consignment. Copies leave the stock of products that manage stock; nothing is a sale yet (no order, nothing in Revenue Splitter). Price per line as a decimal string, VAT included; empty = the account\'s trade price. due empty = delivery + the default days of the settings.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'account' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'User id of the trade account (accounts-list).',
						),
						'given'   => $date,
						'due'     => $date,
						'doc'     => array(
							'type'      => 'string',
							'maxLength' => 60,
						),
						'note'    => array(
							'type'      => 'string',
							'maxLength' => 2000,
						),
						'lines'   => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => self::MAX_LINES,
							'items'    => array(
								'type'                 => 'object',
								'properties'           => array(
									'product' => array(
										'type'    => 'integer',
										'minimum' => 1,
									),
									'qty'     => array(
										'type'    => 'integer',
										'minimum' => 1,
										'maximum' => self::MAX_QTY,
									),
									'price'   => array( 'type' => 'string' ),
								),
								'required'             => array( 'product', 'qty' ),
								'additionalProperties' => false,
							),
						),
					),
					'required'             => array( 'account', 'lines' ),
					'additionalProperties' => false,
				),
				'ann'         => array( false, false, false ),
				'run'         => array( __CLASS__, 'ab_create' ),
			),
			'consignment-settle' => array(
				'label'       => 'Trade Accounts: settle a consignment',
				'description' => 'Records what the bookstore sold and returned. Sold copies become one WooCommerce order for the account in status on-hold (not counted by Revenue Splitter until paid; no e-mails; stock not touched again). Returned copies go back to stock.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'       => $id,
						'sold'     => $count,
						'returned' => $count,
						'date'     => $date,
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'ann'         => array( false, false, false ),
				'run'         => array( __CLASS__, 'ab_settle' ),
			),
			'consignment-paid'   => array(
				'label'       => 'Trade Accounts: mark a settlement paid',
				'description' => 'The money of a settlement order was collected: the order becomes completed (paid on date) and from then on counts in Revenue Splitter and the reports. Sends the bookstore no e-mail unless the setting says so.',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'    => $id,
						'order' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'date'  => $date,
					),
					'required'             => array( 'id', 'order' ),
					'additionalProperties' => false,
				),
				'ann'         => array( false, false, false ),
				'run'         => array( __CLASS__, 'ab_paid' ),
			),
			'consignment-extend' => array(
				'label'       => 'Trade Accounts: new settle-by date',
				'description' => 'Moves the settle-by date of a consignment and/or adds a note to its history (doc = delivery note number).',
				'input'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'   => $id,
						'due'  => array( 'type' => 'string' ),
						'note' => array(
							'type'      => 'string',
							'maxLength' => 2000,
						),
						'doc'  => array(
							'type'      => 'string',
							'maxLength' => 60,
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'ann'         => array( false, false, true ),
				'run'         => array( __CLASS__, 'ab_extend' ),
			),
		);
	}

	public static function ab_list( $in = null ): array {
		$in   = is_array( $in ) ? $in : array();
		$list = self::query( $in );
		return array(
			'summary'      => self::summary(),
			'count'        => count( $list ),
			'consignments' => $list,
		);
	}

	public static function ab_create( $in = null ) {
		$id = self::create( is_array( $in ) ? $in : array() );
		return is_wp_error( $id ) ? $id : self::get( (int) $id );
	}

	public static function ab_settle( $in = null ) {
		$in  = is_array( $in ) ? $in : array();
		$res = self::settle(
			isset( $in['id'] ) ? (int) $in['id'] : 0,
			isset( $in['sold'] ) && is_array( $in['sold'] ) ? $in['sold'] : array(),
			isset( $in['returned'] ) && is_array( $in['returned'] ) ? $in['returned'] : array(),
			isset( $in['date'] ) ? (string) $in['date'] : ''
		);
		return is_wp_error( $res ) ? $res : array_merge( $res, array( 'consignment' => self::get( (int) $in['id'] ) ) );
	}

	public static function ab_paid( $in = null ) {
		$in  = is_array( $in ) ? $in : array();
		$res = self::paid( isset( $in['id'] ) ? (int) $in['id'] : 0, isset( $in['order'] ) ? (int) $in['order'] : 0, isset( $in['date'] ) ? (string) $in['date'] : '' );
		return is_wp_error( $res ) ? $res : self::get( (int) $in['id'] );
	}

	public static function ab_extend( $in = null ) {
		$in = is_array( $in ) ? $in : array();
		$id = isset( $in['id'] ) ? (int) $in['id'] : 0;
		if ( ! empty( $in['due'] ) ) {
			$res = self::extend( $id, (string) $in['due'] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		if ( isset( $in['note'] ) || isset( $in['doc'] ) ) {
			$res = self::note( $id, isset( $in['note'] ) ? (string) $in['note'] : '', isset( $in['doc'] ) ? (string) $in['doc'] : null );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		$c = self::get( $id );
		return null === $c ? new WP_Error( 'trc_none', 'Consignment not found.' ) : $c;
	}
}

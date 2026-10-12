<?php
/**
 * SP_Sales — "SP Πωλήσεις": sales per product for any period, with an
 * on-screen preview and a CSV download (1.6.0).
 *
 * Replaces third-party "sales by product" exporters (e.g. Ninjalytics).
 * Works on its own; with Revenue Splitter active, VAT comes from the
 * Revenue Splitter rate of each product (RS_VAT::get_rate, Bible §6),
 * otherwise from the tax WooCommerce stored on each order line.
 *
 * Figures (per row = product, or variation when split):
 *  - qty       sold units, minus refunded units when refunds are subtracted.
 *  - before    line subtotal + subtotal tax (price before coupons).
 *  - discount  before − line total incl. tax (coupon discounts).
 *  - sales     line total + line tax (what the customer paid), minus
 *              refunds when refunds are subtracted. The main amount.
 *  - vat, net  VAT inside "sales" and sales − VAT.
 *  - ref_qty, ref_amount: refunded units and amount (always shown as is).
 *  - orders    distinct orders with at least one counted line.
 * Shipping and fees never count. Refunds per line: SP_Data::line_refunds().
 *
 * Orders: wc_get_orders only (HPOS and legacy), BATCH per page, filtered
 * by the chosen date (completed, paid or created) with local-day bounds
 * as timestamps, and checked again in PHP.
 *
 * Security (Bible §11): the page is read-only. The CSV is a privileged
 * GET handled on admin_init (slug + nonce + capability). Every parameter
 * goes through a whitelist. The only write is the user's last choices
 * (user option sp_sales_last), and only with a valid nonce.
 */

defined( 'ABSPATH' ) || exit;

final class SP_Sales {

	const SLUG     = 'sp-sales';
	const NONCE    = 'sp-sales';
	const LAST     = 'sp_sales_last';
	const PREVIEW  = 50;
	const MAX_PIDS = 100;

	/** Period presets → msgid labels. */
	const PERIODS = array(
		'today'      => 'Σήμερα',
		'yesterday'  => 'Χθες',
		'7d'         => 'Τελευταίες 7 ημέρες',
		'30d'        => 'Τελευταίες 30 ημέρες',
		'this_month' => 'Τρέχων μήνας',
		'last_month' => 'Προηγούμενος μήνας',
		'this_year'  => 'Τρέχον έτος',
		'last_year'  => 'Προηγούμενο έτος',
		'all'        => 'Από την αρχή',
		'custom'     => 'Από - έως',
	);

	/** Which order date places an order in the period. */
	const BASES = array(
		'completed' => 'Ημερομηνία ολοκλήρωσης',
		'paid'      => 'Ημερομηνία πληρωμής',
		'created'   => 'Ημερομηνία δημιουργίας',
	);

	/** Columns in their fixed order → msgid labels. */
	const COLUMNS = array(
		'id'         => 'ID',
		'name'       => 'Προϊόν',
		'sku'        => 'SKU',
		'cats'       => 'Κατηγορίες',
		'qty'        => 'Τεμάχια',
		'before'     => 'Πριν την έκπτωση',
		'discount'   => 'Έκπτωση',
		'sales'      => 'Πωλήσεις',
		'vat'        => 'ΦΠΑ',
		'net'        => 'Καθαρό',
		'ref_qty'    => 'Επιστραμμένα τεμάχια',
		'ref_amount' => 'Ποσό επιστροφών',
		'orders'     => 'Παραγγελίες',
		'stock'      => 'Στοκ',
	);

	/** Columns holding amounts (two decimals). */
	const MONEY_COLS = array( 'before', 'discount', 'sales', 'vat', 'net', 'ref_amount' );

	/** Columns holding whole numbers. */
	const INT_COLS = array( 'qty', 'ref_qty', 'orders' );

	const ORDERBY = array(
		'name'  => 'Όνομα',
		'qty'   => 'Τεμάχια',
		'sales' => 'Πωλήσεις',
	);

	const FORMATS = array(
		'el'  => 'Excel ελληνικά (; και δεκαδικό κόμμα)',
		'std' => 'Τυπικό (, και δεκαδική τελεία)',
	);

	/** Validation messages of this request. */
	private static $errors = array();

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_download' ) );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/* =====================================================================
	 * Arguments
	 * =================================================================== */

	/** Defaults: the "Sales by Product" preset that Ninjalytics users know. */
	public static function defaults(): array {
		return array(
			'period'   => 'this_year',
			'from'     => '',
			'to'       => '',
			'basis'    => 'completed',
			'statuses' => array( 'wc-completed' ),
			'scope'    => 'all',
			'cats'     => array(),
			'products' => array(),
			'split'    => false,
			'free'     => false,
			'refunds'  => true,
			'totals'   => true,
			'cols'     => array( 'name', 'sku', 'sales', 'qty' ),
			'orderby'  => 'name',
			'order'    => 'asc',
			'format'   => 'el' === SP_Lang::get_lang() ? 'el' : 'std',
		);
	}

	/** The user's last choices (validated again), or the defaults. */
	public static function saved(): array {
		$raw = get_user_option( self::LAST );
		if ( ! is_array( $raw ) ) {
			return self::defaults();
		}
		$args         = self::sanitize( $raw, false );
		self::$errors = array(); // Stored values never raise messages.
		return $args;
	}

	/**
	 * Whitelists every field. With $report, invalid input adds a message
	 * to self::$errors and falls back to the default for that field.
	 */
	public static function sanitize( array $in, bool $report = true ): array {

		$d   = self::defaults();
		$out = $d;
		$err = array();

		$period = isset( $in['period'] ) ? (string) $in['period'] : $d['period'];
		$out['period'] = isset( self::PERIODS[ $period ] ) ? $period : $d['period'];

		$from        = self::ymd( $in['from'] ?? '' );
		$to          = self::ymd( $in['to'] ?? '' );
		$out['from'] = $from;
		$out['to']   = $to;

		if ( 'custom' === $out['period'] && ( '' === $from || '' === $to || $from > $to ) ) {
			$err[]         = __( 'Μη έγκυρες ημερομηνίες. Δώσε «Από» και «Έως», με το «Από» πριν από το «Έως».', 'store-pulse' );
			$out['period'] = $d['period'];
		}

		$basis        = isset( $in['basis'] ) ? (string) $in['basis'] : '';
		$out['basis'] = isset( self::BASES[ $basis ] ) ? $basis : $d['basis'];

		$known    = array_keys( wc_get_order_statuses() );
		$statuses = array_values( array_intersect( self::str_list( $in['statuses'] ?? array() ), $known ) );
		if ( array() === $statuses ) {
			$err[]    = __( 'Επίλεξε τουλάχιστον μία κατάσταση παραγγελίας.', 'store-pulse' );
			$statuses = $d['statuses'];
		}
		$out['statuses'] = $statuses;

		$scope        = isset( $in['scope'] ) ? (string) $in['scope'] : '';
		$out['scope'] = in_array( $scope, array( 'all', 'cats', 'products' ), true ) ? $scope : 'all';

		$cats = array();
		foreach ( self::int_list( $in['cats'] ?? array() ) as $tid ) {
			$term = get_term( $tid, 'product_cat' );
			if ( $term instanceof WP_Term ) {
				$cats[] = $tid;
			}
		}
		$out['cats'] = $cats;

		$pids = array();
		foreach ( array_slice( self::int_list( $in['products'] ?? array() ), 0, self::MAX_PIDS ) as $pid ) {
			if ( in_array( get_post_type( $pid ), array( 'product', 'product_variation' ), true ) ) {
				$pids[] = $pid;
			}
		}
		$out['products'] = $pids;

		if ( 'cats' === $out['scope'] && array() === $cats ) {
			$err[]        = __( 'Επίλεξε τουλάχιστον μία κατηγορία.', 'store-pulse' );
			$out['scope'] = 'all';
		}
		if ( 'products' === $out['scope'] && array() === $pids ) {
			$err[]        = __( 'Επίλεξε τουλάχιστον ένα προϊόν.', 'store-pulse' );
			$out['scope'] = 'all';
		}

		$out['split']   = ! empty( $in['split'] );
		$out['free']    = ! empty( $in['free'] );
		$out['refunds'] = ! empty( $in['refunds'] );
		$out['totals']  = ! empty( $in['totals'] );

		$cols = array_values( array_intersect( array_keys( self::COLUMNS ), self::str_list( $in['cols'] ?? array() ) ) );
		if ( array() === $cols ) {
			$err[] = __( 'Επίλεξε τουλάχιστον μία στήλη.', 'store-pulse' );
			$cols  = $d['cols'];
		}
		$out['cols'] = $cols;

		$orderby        = isset( $in['orderby'] ) ? (string) $in['orderby'] : '';
		$out['orderby'] = isset( self::ORDERBY[ $orderby ] ) ? $orderby : $d['orderby'];
		$out['order']   = ( isset( $in['order'] ) && 'desc' === $in['order'] ) ? 'desc' : 'asc';

		$format        = isset( $in['format'] ) ? (string) $in['format'] : '';
		$out['format'] = isset( self::FORMATS[ $format ] ) ? $format : $d['format'];

		if ( $report ) {
			self::$errors = $err;
		}

		return $out;
	}

	/** The form fields of this request (GET), unslashed, as sanitize() input. */
	private static function from_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- callers verify the nonce first.
		$g  = wp_unslash( $_GET );
		$in = array();
		foreach ( array( 'period', 'from', 'to', 'basis', 'scope', 'orderby', 'order', 'format' ) as $k ) {
			$in[ $k ] = isset( $g[ 'sp_' . $k ] ) && is_string( $g[ 'sp_' . $k ] ) ? sanitize_text_field( $g[ 'sp_' . $k ] ) : '';
		}
		foreach ( array( 'statuses', 'cats', 'products', 'cols' ) as $k ) {
			$in[ $k ] = isset( $g[ 'sp_' . $k ] ) && is_array( $g[ 'sp_' . $k ] ) ? $g[ 'sp_' . $k ] : array();
		}
		foreach ( array( 'split', 'free', 'refunds', 'totals' ) as $k ) {
			$in[ $k ] = ! empty( $g[ 'sp_' . $k ] );
		}
		// phpcs:enable
		return $in;
	}

	private static function ymd( $v ): string {
		$v = is_string( $v ) ? trim( $v ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
			return '';
		}
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $v );
		return ( $d instanceof DateTimeImmutable && $d->format( 'Y-m-d' ) === $v ) ? $v : '';
	}

	private static function str_list( $v ): array {
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $x ) {
			if ( is_string( $x ) ) {
				$out[] = sanitize_key( $x );
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function int_list( $v ): array {
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $x ) {
			if ( is_scalar( $x ) && preg_match( '/^\d{1,20}$/', (string) $x ) && (int) $x > 0 ) {
				$out[] = (int) $x;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Local days [start, end] of the period, or null for "all".
	 *
	 * @return array{0:string,1:string}|null
	 */
	public static function range( array $args ): ?array {

		$now = new DateTimeImmutable( 'now', wp_timezone() );
		$day = $now->format( 'Y-m-d' );

		switch ( $args['period'] ) {
			case 'today':
				return array( $day, $day );
			case 'yesterday':
				$y = $now->modify( '-1 day' )->format( 'Y-m-d' );
				return array( $y, $y );
			case '7d':
				return array( $now->modify( '-6 days' )->format( 'Y-m-d' ), $day );
			case '30d':
				return array( $now->modify( '-29 days' )->format( 'Y-m-d' ), $day );
			case 'this_month':
				return array( $now->format( 'Y-m-01' ), $now->format( 'Y-m-t' ) );
			case 'last_month':
				$m = $now->modify( 'first day of last month' );
				return array( $m->format( 'Y-m-01' ), $m->format( 'Y-m-t' ) );
			case 'this_year':
				return array( $now->format( 'Y-01-01' ), $now->format( 'Y-12-31' ) );
			case 'last_year':
				$y = (int) $now->format( 'Y' ) - 1;
				return array( $y . '-01-01', $y . '-12-31' );
			case 'custom':
				return array( $args['from'], $args['to'] );
			case 'all':
			default:
				return null;
		}
	}

	/** Revenue Splitter VAT rates available (Bible §6)? */
	public static function rs_vat(): bool {
		return class_exists( 'RS_VAT' ) && is_callable( array( 'RS_VAT', 'get_rate' ) );
	}

	/* =====================================================================
	 * Report
	 * =================================================================== */

	/**
	 * Rows (unsorted) and totals for the arguments. Cached through
	 * SP_Data::cached() (same generation key as the dashboard).
	 *
	 * @return array{rows:array,orders:int,vat:string}
	 */
	public static function report( array $args ): array {

		$range = self::range( $args );
		$vat   = self::rs_vat() ? 'rs' : 'wc';

		$key = array(
			'v'        => 1,
			'range'    => $range,
			'basis'    => $args['basis'],
			'statuses' => $args['statuses'],
			'scope'    => $args['scope'],
			'cats'     => 'cats' === $args['scope'] ? $args['cats'] : array(),
			'products' => 'products' === $args['scope'] ? $args['products'] : array(),
			'split'    => $args['split'],
			'free'     => $args['free'],
			'refunds'  => $args['refunds'],
			'vat'      => $vat,
		);

		return SP_Data::cached(
			'sales|' . md5( (string) wp_json_encode( $key ) ),
			static function () use ( $args, $range, $vat ): array {
				return self::compute( $args, $range, $vat );
			}
		);
	}

	private static function compute( array $args, ?array $range, string $vat_source ): array {

		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 300 );
		}

		$query = array(
			'status'  => $args['statuses'],
			'type'    => 'shop_order',
			'limit'   => SP_Data::BATCH,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'objects',
		);

		$from_ts = null;
		$to_ts   = null;
		if ( null !== $range ) {
			try {
				$tz      = wp_timezone();
				$from_ts = ( new DateTimeImmutable( $range[0] . ' 00:00:00', $tz ) )->getTimestamp();
				$to_ts   = ( new DateTimeImmutable( $range[1] . ' 23:59:59', $tz ) )->getTimestamp();
			} catch ( Exception $e ) {
				return array( 'rows' => array(), 'orders' => 0, 'vat' => $vat_source );
			}
			$query[ 'date_' . $args['basis'] ] = $from_ts . '...' . $to_ts;
		}

		// Allowed categories, children included.
		$cat_set = array();
		if ( 'cats' === $args['scope'] ) {
			foreach ( $args['cats'] as $tid ) {
				$cat_set[ $tid ] = true;
				$kids            = get_term_children( $tid, 'product_cat' );
				if ( is_array( $kids ) ) {
					foreach ( $kids as $kid ) {
						$cat_set[ (int) $kid ] = true;
					}
				}
			}
		}
		$pid_set = ( 'products' === $args['scope'] ) ? array_flip( $args['products'] ) : array();

		$cat_cache  = array();
		$rows       = array();
		$all_orders = array();

		$page = 1;
		do {
			$query['page'] = $page;
			$batch         = wc_get_orders( $query );
			if ( ! is_array( $batch ) ) {
				break;
			}

			foreach ( $batch as $order ) {
				if ( ! $order instanceof WC_Order ) {
					continue;
				}

				if ( null !== $from_ts ) {
					$getter = 'get_date_' . $args['basis'];
					$date   = $order->$getter();
					if ( ! $date instanceof WC_DateTime ) {
						continue;
					}
					$ts = $date->getTimestamp();
					if ( $ts < $from_ts || $ts > $to_ts ) {
						continue;
					}
				}

				$refunds = SP_Data::line_refunds( $order );
				$oid     = $order->get_id();

				foreach ( $order->get_items() as $item_id => $item ) {
					if ( ! $item instanceof WC_Order_Item_Product ) {
						continue;
					}

					$pid  = (int) $item->get_product_id();
					$vid  = (int) $item->get_variation_id();
					$tax  = (float) $item->get_total_tax();
					$paid = (float) $item->get_total() + $tax;

					if ( ! $args['free'] && $paid < 0.005 ) {
						continue;
					}

					if ( 'products' === $args['scope'] && ! isset( $pid_set[ $pid ] ) && ! ( $vid && isset( $pid_set[ $vid ] ) ) ) {
						continue;
					}

					if ( 'cats' === $args['scope'] ) {
						if ( ! isset( $cat_cache[ $pid ] ) ) {
							$cat_cache[ $pid ] = $pid > 0 ? wc_get_product_term_ids( $pid, 'product_cat' ) : array();
						}
						$hit = false;
						foreach ( $cat_cache[ $pid ] as $tid ) {
							if ( isset( $cat_set[ (int) $tid ] ) ) {
								$hit = true;
								break;
							}
						}
						if ( ! $hit ) {
							continue;
						}
					}

					if ( $pid > 0 ) {
						$key = ( $args['split'] && $vid > 0 ) ? 'p' . $vid : 'p' . $pid;
					} else {
						$key = 'x' . md5( (string) $item->get_name() );
					}

					if ( ! isset( $rows[ $key ] ) ) {
						$rows[ $key ] = array(
							'id'         => ( $args['split'] && $vid > 0 ) ? $vid : $pid,
							'pid'        => $pid,
							'item_name'  => (string) $item->get_name(),
							'qty'        => 0,
							'before'     => 0.0,
							'paid'       => 0.0,
							'tax'        => 0.0,
							'ref_qty'    => 0,
							'ref_amount' => 0.0,
							'ref_tax'    => 0.0,
							'orders'     => array(),
						);
					}

					$ref = $refunds[ $item_id ] ?? array(
						'qty'    => 0,
						'amount' => 0.0,
						'tax'    => 0.0,
					);

					$r                = &$rows[ $key ];
					$r['qty']        += (int) $item->get_quantity();
					$r['before']     += (float) $item->get_subtotal() + (float) $item->get_subtotal_tax();
					$r['paid']       += $paid;
					$r['tax']        += $tax;
					$r['ref_qty']    += (int) $ref['qty'];
					$r['ref_amount'] += (float) $ref['amount'];
					$r['ref_tax']    += (float) $ref['tax'];
					$r['orders'][ $oid ] = true;
					unset( $r );

					$all_orders[ $oid ] = true;
				}
			}

			$page++;
		} while ( count( $batch ) === SP_Data::BATCH );

		$out = array();
		foreach ( $rows as $r ) {
			$out[] = self::finish_row( $r, $args, $vat_source );
		}

		return array(
			'rows'   => $out,
			'orders' => count( $all_orders ),
			'vat'    => $vat_source,
		);
	}

	/** Final figures and product details of one row. */
	private static function finish_row( array $r, array $args, string $vat_source ): array {

		$sub     = $args['refunds'];
		$sales   = $r['paid'] - ( $sub ? $r['ref_amount'] : 0.0 );
		$qty     = $r['qty'] - ( $sub ? $r['ref_qty'] : 0 );

		if ( 'rs' === $vat_source ) {
			$rate = (float) RS_VAT::get_rate( (int) $r['pid'] );
			$vat  = ( $rate > 0 ) ? $sales * $rate / ( 100.0 + $rate ) : 0.0;
		} else {
			$vat = $r['tax'] - ( $sub ? $r['ref_tax'] : 0.0 );
		}

		$product = $r['id'] > 0 ? wc_get_product( $r['id'] ) : null;
		$name    = $product instanceof WC_Product ? $product->get_name() : $r['item_name'];
		$sku     = $product instanceof WC_Product ? (string) $product->get_sku() : '';

		$cats = array();
		if ( $r['pid'] > 0 ) {
			$terms = get_the_terms( (int) $r['pid'], 'product_cat' );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $t ) {
					$cats[] = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
				}
			}
		}

		return array(
			'id'         => (int) $r['id'],
			'name'       => html_entity_decode( wp_strip_all_tags( (string) $name ), ENT_QUOTES, 'UTF-8' ),
			'sku'        => $sku,
			'cats'       => implode( ', ', $cats ),
			'qty'        => (int) $qty,
			'before'     => round( $r['before'], 2 ),
			'discount'   => round( max( 0.0, $r['before'] - $r['paid'] ), 2 ),
			'sales'      => round( $sales, 2 ),
			'vat'        => round( $vat, 2 ),
			'net'        => round( $sales - $vat, 2 ),
			'ref_qty'    => (int) $r['ref_qty'],
			'ref_amount' => round( $r['ref_amount'], 2 ),
			'orders'     => count( $r['orders'] ),
			'stock'      => self::stock_of( $product ),
		);
	}

	/**
	 * Stock: the quantity when stock is managed (for a variable product
	 * without its own stock, the sum of its managed variations), else
	 * the status key (instock / outofstock / onbackorder). '' if unknown.
	 *
	 * @return int|string
	 */
	private static function stock_of( $product ) {

		if ( ! $product instanceof WC_Product ) {
			return '';
		}
		if ( $product->managing_stock() ) {
			return (int) $product->get_stock_quantity();
		}
		if ( $product->is_type( 'variable' ) ) {
			$sum     = 0;
			$managed = false;
			foreach ( $product->get_children() as $cid ) {
				$child = wc_get_product( $cid );
				if ( $child instanceof WC_Product && $child->managing_stock() ) {
					$managed = true;
					$sum    += (int) $child->get_stock_quantity();
				}
			}
			if ( $managed ) {
				return $sum;
			}
		}
		return (string) $product->get_stock_status();
	}

	/** Rows sorted as asked. */
	public static function sorted( array $rows, array $args ): array {

		$by  = $args['orderby'];
		$dir = 'desc' === $args['order'] ? -1 : 1;

		usort(
			$rows,
			static function ( $a, $b ) use ( $by, $dir ) {
				if ( 'name' === $by ) {
					$c = strcmp( self::fold( $a['name'] ), self::fold( $b['name'] ) );
				} else {
					$c = $a[ $by ] <=> $b[ $by ];
				}
				if ( 0 === $c ) {
					$c = $a['id'] <=> $b['id'];
				}
				return $dir * $c;
			}
		);

		return $rows;
	}

	/** Case and accent folding for name sorting (Greek included). */
	private static function fold( string $s ): string {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		return remove_accents( $s );
	}

	/** Column sums for the totals row. */
	public static function totals( array $report ): array {
		$t = array_fill_keys( array_merge( self::MONEY_COLS, self::INT_COLS ), 0 );
		foreach ( $report['rows'] as $r ) {
			foreach ( $t as $k => $v ) {
				if ( 'orders' !== $k ) {
					$t[ $k ] += $r[ $k ];
				}
			}
		}
		foreach ( self::MONEY_COLS as $k ) {
			$t[ $k ] = round( (float) $t[ $k ], 2 );
		}
		$t['orders'] = (int) $report['orders']; // Distinct orders, not a sum.
		return $t;
	}

	/* =====================================================================
	 * CSV
	 * =================================================================== */

	/** admin_init: ?page=sp-sales&sp_csv=1 + nonce + capability. */
	public static function maybe_download(): void {

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below.
		if ( ! isset( $_GET['sp_csv'], $_GET['page'] ) || self::SLUG !== $_GET['page'] ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'store-pulse' ) );
		}
		// phpcs:enable
		if ( ! current_user_can( SP_Admin::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'store-pulse' ) );
		}

		$args = self::sanitize( self::from_request() );
		if ( array() !== self::$errors ) {
			return; // The page renders and shows the messages.
		}

		update_user_option( get_current_user_id(), self::LAST, $args );

		self::stream_csv( $args );
		exit;
	}

	private static function stream_csv( array $args ): void {

		$report = self::report( $args );
		$rows   = self::sorted( $report['rows'], $args );
		$range  = self::range( $args );
		$sep    = 'el' === $args['format'] ? ';' : ',';

		$fname = 'store-pulse-sales-' . ( null === $range ? 'all' : $range[0] . '_' . $range[1] ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $fname . '"' );

		$fh = fopen( 'php://output', 'w' );
		fwrite( $fh, "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel.

		$head = array();
		foreach ( $args['cols'] as $c ) {
			$head[] = __( self::COLUMNS[ $c ], 'store-pulse' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- msgids in SP_Lang.
		}
		fputcsv( $fh, $head, $sep );

		foreach ( $rows as $r ) {
			fputcsv( $fh, self::csv_cells( $r, $args ), $sep );
		}

		if ( $args['totals'] ) {
			fputcsv( $fh, self::csv_cells( self::totals( $report ), $args, true ), $sep );
		}

		fclose( $fh );
	}

	/** One CSV line. Text cells get formula protection; numbers do not. */
	private static function csv_cells( array $r, array $args, bool $total = false ): array {

		$dec   = 'el' === $args['format'] ? ',' : '.';
		$cells = array();
		$label = false;

		foreach ( $args['cols'] as $c ) {
			if ( $total ) {
				if ( in_array( $c, self::MONEY_COLS, true ) ) {
					$cells[] = number_format( (float) $r[ $c ], 2, $dec, '' );
				} elseif ( in_array( $c, self::INT_COLS, true ) ) {
					$cells[] = (string) (int) $r[ $c ];
				} elseif ( ! $label ) {
					$cells[] = __( 'ΣΥΝΟΛΑ', 'store-pulse' );
					$label   = true;
				} else {
					$cells[] = '';
				}
				continue;
			}

			if ( in_array( $c, self::MONEY_COLS, true ) ) {
				$cells[] = number_format( (float) $r[ $c ], 2, $dec, '' );
			} elseif ( in_array( $c, self::INT_COLS, true ) || 'id' === $c ) {
				$cells[] = (string) (int) $r[ $c ];
			} elseif ( 'stock' === $c ) {
				$cells[] = self::stock_label( $r['stock'] );
			} else {
				$cells[] = self::csv_text( (string) $r[ $c ] );
			}
		}

		return $cells;
	}

	/** Formula-injection guard (same rule as RS_Admin_UI::csv_cell). */
	public static function csv_text( string $cell ): string {
		if ( '' !== $cell && in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $cell;
		}
		return $cell;
	}

	/** Stock value → shown text. */
	public static function stock_label( $stock ): string {
		if ( is_int( $stock ) ) {
			return (string) $stock;
		}
		switch ( $stock ) {
			case 'instock':
				return __( 'Σε απόθεμα', 'store-pulse' );
			case 'outofstock':
				return __( 'Εξαντλημένο', 'store-pulse' );
			case 'onbackorder':
				return __( 'Κατόπιν παραγγελίας', 'store-pulse' );
			default:
				return '';
		}
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render_page(): void {

		if ( ! current_user_can( SP_Admin::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'store-pulse' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified right here.
		$submitted = isset( $_GET['sp_run'] ) || isset( $_GET['sp_csv'] );
		$nonce_ok  = isset( $_GET['_wpnonce'] )
			&& wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE );
		// phpcs:enable

		$report = null;
		$args   = self::saved();
		$errors = array();

		if ( $submitted && ! $nonce_ok ) {
			$errors[] = __( 'Η σελίδα έληξε. Πάτησε ξανά «Προβολή».', 'store-pulse' );
		} elseif ( $submitted ) {
			$args   = self::sanitize( self::from_request() );
			$errors = self::$errors;
			if ( array() === $errors ) {
				update_user_option( get_current_user_id(), self::LAST, $args );
				$report = self::report( $args );
			}
		}

		$statuses = wc_get_order_statuses();
		$cats     = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$cats     = is_array( $cats ) ? $cats : array();
		?>
		<div class="wrap sp-wrap sp-sales">
			<h1 class="sp-h1"><?php esc_html_e( 'Store Pulse — Πωλήσεις ανά προϊόν', 'store-pulse' ); ?></h1>

			<?php foreach ( $errors as $err ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $err ); ?></p></div>
			<?php endforeach; ?>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="sp-zone sp-sales-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<?php wp_nonce_field( self::NONCE, '_wpnonce', false ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sp_period"><?php esc_html_e( 'Περίοδος', 'store-pulse' ); ?></label></th>
						<td>
							<select id="sp_period" name="sp_period">
								<?php foreach ( self::PERIODS as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $args['period'], $k ); ?>><?php echo esc_html__( $label, 'store-pulse' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?></option>
								<?php endforeach; ?>
							</select>
							<span class="sp-sales-dates">
								<label><?php esc_html_e( 'Από', 'store-pulse' ); ?> <input type="date" name="sp_from" value="<?php echo esc_attr( $args['from'] ); ?>" /></label>
								<label><?php esc_html_e( 'Έως', 'store-pulse' ); ?> <input type="date" name="sp_to" value="<?php echo esc_attr( $args['to'] ); ?>" /></label>
							</span>
							<p class="description"><?php esc_html_e( 'Οι ημερομηνίες «Από» και «Έως» ισχύουν όταν η περίοδος είναι «Από - έως».', 'store-pulse' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sp_basis"><?php esc_html_e( 'Ποια ημερομηνία μετράει', 'store-pulse' ); ?></label></th>
						<td>
							<select id="sp_basis" name="sp_basis">
								<?php foreach ( self::BASES as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $args['basis'], $k ); ?>><?php echo esc_html__( $label, 'store-pulse' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Καταστάσεις παραγγελιών', 'store-pulse' ); ?></th>
						<td class="sp-checks">
							<?php foreach ( $statuses as $k => $label ) : ?>
								<label class="sp-check"><input type="checkbox" name="sp_statuses[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $args['statuses'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Προϊόντα', 'store-pulse' ); ?></th>
						<td>
							<label class="sp-check"><input type="radio" name="sp_scope" value="all" <?php checked( $args['scope'], 'all' ); ?> /> <?php esc_html_e( 'Όλα', 'store-pulse' ); ?></label>
							<label class="sp-check"><input type="radio" name="sp_scope" value="cats" <?php checked( $args['scope'], 'cats' ); ?> /> <?php esc_html_e( 'Κατηγορίες', 'store-pulse' ); ?></label>
							<label class="sp-check"><input type="radio" name="sp_scope" value="products" <?php checked( $args['scope'], 'products' ); ?> /> <?php esc_html_e( 'Συγκεκριμένα προϊόντα', 'store-pulse' ); ?></label>

							<p class="sp-sales-pick">
								<label for="sp_cats"><?php esc_html_e( 'Κατηγορίες', 'store-pulse' ); ?></label>
								<select id="sp_cats" name="sp_cats[]" multiple="multiple" class="wc-enhanced-select" data-placeholder="<?php esc_attr_e( 'Επίλεξε κατηγορίες', 'store-pulse' ); ?>">
									<?php foreach ( $cats as $t ) : ?>
										<option value="<?php echo esc_attr( (string) $t->term_id ); ?>" <?php selected( in_array( (int) $t->term_id, $args['cats'], true ) ); ?>><?php echo esc_html( $t->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<p class="sp-sales-pick">
								<label for="sp_products"><?php esc_html_e( 'Προϊόντα', 'store-pulse' ); ?></label>
								<select id="sp_products" name="sp_products[]" multiple="multiple" class="wc-product-search" data-action="woocommerce_json_search_products_and_variations" data-minimum_input_length="3" data-placeholder="<?php esc_attr_e( 'Αναζήτηση προϊόντος…', 'store-pulse' ); ?>">
									<?php
									foreach ( $args['products'] as $pid ) :
										$p = wc_get_product( $pid );
										if ( ! $p instanceof WC_Product ) {
											continue;
										}
										?>
										<option value="<?php echo esc_attr( (string) $pid ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<p class="description"><?php esc_html_e( 'Μια κατηγορία περιλαμβάνει και τις υποκατηγορίες της. Ένα μεταβλητό προϊόν περιλαμβάνει όλες τις παραλλαγές του.', 'store-pulse' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Επιλογές', 'store-pulse' ); ?></th>
						<td class="sp-checks">
							<label class="sp-check"><input type="checkbox" name="sp_split" value="1" <?php checked( $args['split'] ); ?> /> <?php esc_html_e( 'Χωριστή γραμμή για κάθε παραλλαγή', 'store-pulse' ); ?></label>
							<label class="sp-check"><input type="checkbox" name="sp_free" value="1" <?php checked( $args['free'] ); ?> /> <?php esc_html_e( 'Μαζί οι δωρεάν γραμμές (ποσό 0)', 'store-pulse' ); ?></label>
							<label class="sp-check"><input type="checkbox" name="sp_refunds" value="1" <?php checked( $args['refunds'] ); ?> /> <?php esc_html_e( 'Αφαίρεση επιστροφών', 'store-pulse' ); ?></label>
							<label class="sp-check"><input type="checkbox" name="sp_totals" value="1" <?php checked( $args['totals'] ); ?> /> <?php esc_html_e( 'Γραμμή συνόλων', 'store-pulse' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Στήλες', 'store-pulse' ); ?></th>
						<td class="sp-checks">
							<?php foreach ( self::COLUMNS as $k => $label ) : ?>
								<label class="sp-check"><input type="checkbox" name="sp_cols[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $args['cols'], true ) ); ?> /> <?php echo esc_html__( $label, 'store-pulse' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?></label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Τα ποσά περιλαμβάνουν ΦΠΑ. Τα μεταφορικά δεν μετρούν ποτέ.', 'store-pulse' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sp_orderby"><?php esc_html_e( 'Ταξινόμηση', 'store-pulse' ); ?></label></th>
						<td>
							<select id="sp_orderby" name="sp_orderby">
								<?php foreach ( self::ORDERBY as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $args['orderby'], $k ); ?>><?php echo esc_html__( $label, 'store-pulse' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?></option>
								<?php endforeach; ?>
							</select>
							<select name="sp_order" aria-label="<?php esc_attr_e( 'Σειρά', 'store-pulse' ); ?>">
								<option value="asc" <?php selected( $args['order'], 'asc' ); ?>><?php esc_html_e( 'Αύξουσα', 'store-pulse' ); ?></option>
								<option value="desc" <?php selected( $args['order'], 'desc' ); ?>><?php esc_html_e( 'Φθίνουσα', 'store-pulse' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sp_format"><?php esc_html_e( 'Μορφή CSV', 'store-pulse' ); ?></label></th>
						<td>
							<select id="sp_format" name="sp_format">
								<?php foreach ( self::FORMATS as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $args['format'], $k ); ?>><?php echo esc_html__( $label, 'store-pulse' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<p class="sp-sales-actions">
					<button type="submit" name="sp_run" value="1" class="button button-primary"><?php esc_html_e( 'Προβολή', 'store-pulse' ); ?></button>
					<button type="submit" name="sp_csv" value="1" class="button"><?php esc_html_e( 'Λήψη CSV', 'store-pulse' ); ?></button>
				</p>
			</form>

			<?php if ( null !== $report ) : ?>
				<?php self::render_preview( $report, $args ); ?>
			<?php endif; ?>

			<?php SP_Dashboard::render_footer(); ?>
		</div>
		<?php
	}

	private static function render_preview( array $report, array $args ): void {

		$rows  = self::sorted( $report['rows'], $args );
		$range = self::range( $args );
		$shown = array_slice( $rows, 0, self::PREVIEW );
		?>
		<h2 class="sp-h2">
			<?php
			echo esc_html(
				null === $range
					? __( 'Από την αρχή', 'store-pulse' )
					: SP_Lang::fmt_date( $range[0] ) . ' → ' . SP_Lang::fmt_date( $range[1] )
			);
			?>
		</h2>

		<p class="sp-note">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: products, 2: orders */
					__( '%1$s προϊόντα από %2$s παραγγελίες.', 'store-pulse' ),
					number_format_i18n( count( $rows ) ),
					number_format_i18n( (int) $report['orders'] )
				)
			);
			echo ' ';
			if ( count( $rows ) > self::PREVIEW ) {
				echo esc_html(
					sprintf(
						/* translators: %s: number of rows shown */
						__( 'Εδώ φαίνονται οι πρώτες %s γραμμές· το CSV έχει όλες.', 'store-pulse' ),
						number_format_i18n( self::PREVIEW )
					)
				);
				echo ' ';
			}
			echo esc_html(
				'rs' === $report['vat']
					? __( 'Ο ΦΠΑ υπολογίζεται με τον συντελεστή του Revenue Splitter για κάθε προϊόν.', 'store-pulse' )
					: __( 'Ο ΦΠΑ είναι αυτός που κατέγραψε το WooCommerce στις γραμμές των παραγγελιών. Με το Revenue Splitter υπολογίζεται με τον συντελεστή κάθε προϊόντος.', 'store-pulse' )
			);
			?>
		</p>

		<?php if ( array() === $rows ) : ?>
			<p class="sp-empty"><?php esc_html_e( 'Καμία πώληση στην περίοδο.', 'store-pulse' ); ?></p>
			<?php
			return;
		endif;
		?>

		<div class="sp-sales-scroll">
			<table class="widefat sp-table">
				<thead>
					<tr>
						<?php foreach ( $args['cols'] as $c ) : ?>
							<th class="<?php echo esc_attr( self::is_num( $c ) ? 'sp-num' : '' ); ?>"><?php echo esc_html__( self::COLUMNS[ $c ], 'store-pulse' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $shown as $r ) : ?>
						<tr>
							<?php foreach ( $args['cols'] as $c ) : ?>
								<td class="<?php echo esc_attr( self::is_num( $c ) ? 'sp-num' : '' ); ?>"><?php echo esc_html( self::screen_cell( $r, $c ) ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<?php if ( $args['totals'] ) : ?>
					<?php
					$t     = self::totals( $report );
					$label = false;
					?>
					<tfoot>
						<tr>
							<?php foreach ( $args['cols'] as $c ) : ?>
								<?php if ( in_array( $c, self::MONEY_COLS, true ) || in_array( $c, self::INT_COLS, true ) ) : ?>
									<th class="sp-num"><?php echo esc_html( self::screen_cell( $t, $c ) ); ?></th>
								<?php elseif ( ! $label ) : ?>
									<?php $label = true; ?>
									<th><?php esc_html_e( 'ΣΥΝΟΛΑ', 'store-pulse' ); ?></th>
								<?php else : ?>
									<th></th>
								<?php endif; ?>
							<?php endforeach; ?>
						</tr>
					</tfoot>
				<?php endif; ?>
			</table>
		</div>
		<?php
	}

	private static function is_num( string $c ): bool {
		return in_array( $c, self::MONEY_COLS, true ) || in_array( $c, self::INT_COLS, true ) || 'id' === $c || 'stock' === $c;
	}

	private static function screen_cell( array $r, string $c ): string {
		if ( in_array( $c, self::MONEY_COLS, true ) ) {
			return sp_money( (float) $r[ $c ] );
		}
		if ( in_array( $c, self::INT_COLS, true ) ) {
			return number_format_i18n( (int) $r[ $c ] );
		}
		if ( 'stock' === $c ) {
			return self::stock_label( $r['stock'] );
		}
		if ( 'id' === $c ) {
			return (string) (int) $r['id'];
		}
		return (string) $r[ $c ];
	}
}

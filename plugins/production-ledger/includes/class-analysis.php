<?php
/**
 * PL_Analysis — «Ανάλυση τίτλου» (1.3.0): where the copies of a title
 * went, how they reached people and what its promotion cost (read only).
 *
 * Channels (copies and net revenue ex VAT, per product):
 *  - full        e-shop line at the full price
 *  - discount    e-shop line with a coupon, a discount rule or a sale price
 *  - bundle      e-shop line sold inside a Noxpress bundle (each book is its
 *                own order line, so it counts once, under its own title)
 *  - free_order  e-shop line at 0 € (the way free copies went before the
 *                copies register)
 *  - import      orders brought in by an import (REST, no shipping line):
 *                sales from before the shop recorded channels
 *  - event       a sale at an event or festival (order meta _pl_channel =
 *                event, made by PL_Sales::event_sale())
 *  - trade       a bookstore's own purchase (Trade Accounts, _tra_trade)
 *  - consignment copies given to bookstores on consignment (Trade
 *                Accounts summary: given, sold, returned, on the shelf);
 *                the settlement and payment orders of consignments are
 *                left out of the order count, so nothing counts twice
 *  - free        copies out of the copies register (Revenue Splitter
 *                Αντίτυπα): author's free copies, legal deposit, promotion,
 *                press, with the recipient
 *  - author_buy  copies the author bought through the register
 * Only paid orders count (completed, processing, on-hold); cancelled,
 * failed and refunded orders do not, and a partial refund takes off the
 * refunded copies and amount.
 *
 * Delivery (copies per order's shipping line): allover (Courier,
 * flat_rate), boxnow, warehouse and grafo (the two local pick-ups),
 * pickup_other (any other pick-up), hand (PL «Παράδοση από τον εκδότη»
 * and every event sale), none (no shipping line: imports, trade
 * orders handed over), other.
 *
 * Promotion: the title's «promotion» ledger entries by material (item),
 * net, VAT and quantity.
 */

defined( 'ABSPATH' ) || exit;

final class PL_Analysis {

	const STATUSES  = array( 'wc-completed', 'wc-processing', 'wc-on-hold' );
	const MAX_ORDER = 20000;

	public static function channels(): array {
		return array(
			'full'        => __( 'e-shop, πλήρης τιμή', 'production-ledger' ),
			'discount'    => __( 'e-shop, με έκπτωση', 'production-ledger' ),
			'bundle'      => __( 'e-shop, σε πακέτο (bundle)', 'production-ledger' ),
			'free_order'  => __( 'e-shop, δωρεάν παραγγελία', 'production-ledger' ),
			'import'      => __( 'Παλιές πωλήσεις (εισαγωγή)', 'production-ledger' ),
			'event'       => __( 'Εκθέσεις και φεστιβάλ', 'production-ledger' ),
			'trade'       => __( 'Βιβλιοπωλεία, απευθείας αγορά', 'production-ledger' ),
			'consignment' => __( 'Βιβλιοπωλεία, παρακαταθήκη (πουλήθηκαν)', 'production-ledger' ),
			'author_buy'  => __( 'Αγορά από τον συγγραφέα', 'production-ledger' ),
			'free'        => __( 'Δωρεάν αντίτυπα (μητρώο)', 'production-ledger' ),
		);
	}

	public static function deliveries(): array {
		return array(
			'allover'      => __( 'Αποστολή με Allover', 'production-ledger' ),
			'boxnow'       => __( 'Αποστολή με BOX NOW', 'production-ledger' ),
			'warehouse'    => __( 'Παραλαβή από την αποθήκη', 'production-ledger' ),
			'grafo'        => __( 'Παραλαβή από το GrafoExMachina', 'production-ledger' ),
			'pickup_other' => __( 'Άλλη παραλαβή', 'production-ledger' ),
			'hand'         => __( 'Χέρι με χέρι από τον εκδότη', 'production-ledger' ),
			'none'         => __( 'Χωρίς στοιχείο παράδοσης', 'production-ledger' ),
			'other'        => __( 'Άλλος τρόπος', 'production-ledger' ),
		);
	}

	/** Default period start: Revenue Splitter's «sales since», else the ledger start. */
	public static function default_from(): string {
		$s = (string) get_option( 'rs_sales_since', '' );
		return PL_Settings::valid_date( $s ) ? $s : PL_Settings::start();
	}

	/** The delivery key of an order. */
	public static function delivery_of( WC_Order $o ): string {
		if ( 'event' === (string) $o->get_meta( '_pl_channel' ) ) {
			return 'hand';
		}
		$lines = $o->get_shipping_methods();
		if ( ! $lines ) {
			return 'none';
		}
		$l    = reset( $lines );
		$mid  = (string) $l->get_method_id();
		$inst = (int) $l->get_instance_id();
		$name = mb_strtolower( (string) $l->get_name() );
		if ( PL_Sales::METHOD === $mid ) {
			return 'hand';
		}
		if ( 0 === strpos( $mid, 'box_now' ) || 0 === strpos( $mid, 'boxnow' ) || false !== strpos( $name, 'box now' ) ) {
			return 'boxnow';
		}
		if ( 'local_pickup' === $mid || 'pickup_location' === $mid || false !== strpos( $name, 'παραλαβή' ) ) {
			if ( 43 === $inst || false !== strpos( $name, 'grafo' ) ) {
				return 'grafo';
			}
			if ( 44 === $inst || false !== strpos( $name, 'warehouse' ) || false !== strpos( $name, 'αποθήκ' ) ) {
				return 'warehouse';
			}
			return 'pickup_other';
		}
		if ( 'flat_rate' === $mid || false !== strpos( $name, 'courier' ) ) {
			return 'allover';
		}
		return 'other';
	}

	/** The channel of an order (before the per-line e-shop split), or '' to skip it. */
	public static function order_channel( WC_Order $o ): string {
		$via = (string) $o->get_created_via();
		if ( 0 === strpos( $via, 'tra-consignment' ) ) {
			return ''; // Counted from the consignment summary.
		}
		if ( 'event' === (string) $o->get_meta( '_pl_channel' ) ) {
			return 'event';
		}
		if ( '' !== (string) $o->get_meta( '_tra_trade' ) ) {
			return 'trade';
		}
		if ( 'rest-api' === $via && ! $o->get_shipping_methods() ) {
			return 'import';
		}
		return 'eshop';
	}

	private static function blank(): array {
		$ch = array();
		foreach ( array_keys( self::channels() ) as $k ) {
			$ch[ $k ] = array(
				'qty' => 0,
				'net' => 0.0,
			);
		}
		$dl = array();
		foreach ( array_keys( self::deliveries() ) as $k ) {
			$dl[ $k ] = 0;
		}
		return array(
			'channels' => $ch,
			'delivery' => $dl,
			'events'   => array(),
			'stores'   => array(),
			'free'     => array(),
			'orders'   => 0,
		);
	}

	/**
	 * Every title's picture for a period.
	 *
	 * @param int[] $only Product ids ([] = every product with a movement).
	 * @return array<int, array> product id => picture
	 */
	public static function collect( string $from, string $to, array $only = array() ): array {
		$want = array_flip( array_map( 'intval', $only ) );
		$acc  = array();
		$tz   = wp_timezone();
		$ids  = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => self::STATUSES,
				'date_created' => ( new DateTimeImmutable( $from . ' 00:00:00', $tz ) )->getTimestamp() . '...' . ( new DateTimeImmutable( $to . ' 23:59:59', $tz ) )->getTimestamp(),
				'limit'        => self::MAX_ORDER,
				'return'       => 'ids',
			)
		);
		foreach ( $ids as $oid ) {
			$o = wc_get_order( $oid );
			if ( ! $o instanceof WC_Order || $o instanceof WC_Order_Refund ) {
				continue;
			}
			$och = self::order_channel( $o );
			if ( '' === $och ) {
				continue;
			}
			$dlv  = self::delivery_of( $o );
			$seen = array();
			foreach ( $o->get_items( 'line_item' ) as $item_id => $it ) {
				if ( ! $it instanceof WC_Order_Item_Product ) {
					continue;
				}
				$pid = (int) $it->get_product_id();
				if ( $pid <= 0 || ( $want && ! isset( $want[ $pid ] ) ) ) {
					continue;
				}
				$qty = (int) $it->get_quantity() + (int) $o->get_qty_refunded_for_item( $item_id ); // Refunded qty is negative.
				if ( $qty <= 0 ) {
					continue;
				}
				$net = (float) $it->get_total() - abs( (float) $o->get_total_refunded_for_item( $item_id ) );
				$net = max( 0.0, $net );
				if ( 'eshop' === $och ) {
					$ch = self::eshop_kind( $it, $qty );
				} else {
					$ch = $och;
				}
				if ( ! isset( $acc[ $pid ] ) ) {
					$acc[ $pid ] = self::blank();
				}
				$acc[ $pid ]['channels'][ $ch ]['qty'] += $qty;
				$acc[ $pid ]['channels'][ $ch ]['net'] += $net;
				$acc[ $pid ]['delivery'][ $dlv ]       += $qty;
				if ( 'event' === $ch ) {
					$ev                             = (string) $o->get_meta( '_pl_event' );
					$ev                             = '' !== $ev ? $ev : __( 'Χωρίς όνομα', 'production-ledger' );
					$acc[ $pid ]['events'][ $ev ] = ( $acc[ $pid ]['events'][ $ev ] ?? 0 ) + $qty;
				}
				if ( ! isset( $seen[ $pid ] ) ) {
					$seen[ $pid ] = true;
					++$acc[ $pid ]['orders'];
				}
			}
		}

		// Consignments (Trade Accounts 1.8+): the picture at the end of the period.
		if ( class_exists( 'TRA_Consign' ) && method_exists( 'TRA_Consign', 'summary_by_product' ) ) {
			foreach ( TRA_Consign::summary_by_product( $only, $to ) as $pid => $rows ) {
				$pid = (int) $pid;
				foreach ( (array) $rows as $r ) {
					if ( (string) ( $r['first'] ?? '' ) > $to ) {
						continue;
					}
					if ( ! isset( $acc[ $pid ] ) ) {
						$acc[ $pid ] = self::blank();
					}
					$sold = (int) ( $r['sold'] ?? 0 );
					$acc[ $pid ]['channels']['consignment']['qty'] += $sold;
					$acc[ $pid ]['channels']['consignment']['net'] += self::net_of( (int) $pid, (float) ( $r['sold_value'] ?? 0 ) );
					$acc[ $pid ]['stores'][] = array(
						'name'     => (string) ( $r['name'] ?? '' ),
						'given'    => (int) ( $r['given'] ?? 0 ),
						'sold'     => $sold,
						'returned' => (int) ( $r['returned'] ?? 0 ),
						'shelf'    => (int) ( $r['open'] ?? 0 ),
						'owed'     => (float) ( $r['owed'] ?? 0 ),
					);
				}
			}
		}

		// Copies register (Revenue Splitter 1.19+).
		if ( class_exists( 'RS_Copies' ) && method_exists( 'RS_Copies', 'query' ) ) {
			foreach ( RS_Copies::query(
				array(
					'from' => $from,
					'to'   => $to,
				)
			) as $m ) {
				$pid = (int) $m['pid'];
				if ( $want && ! isset( $want[ $pid ] ) ) {
					continue;
				}
				$type = (string) $m['type'];
				if ( in_array( $type, array( 'damaged', 'return' ), true ) ) {
					continue;
				}
				if ( ! isset( $acc[ $pid ] ) ) {
					$acc[ $pid ] = self::blank();
				}
				$q = (int) $m['qty'];
				if ( 'author_buy' === $type ) {
					$acc[ $pid ]['channels']['author_buy']['qty'] += $q;
					$acc[ $pid ]['channels']['author_buy']['net'] += self::net_of( $pid, (float) ( $m['unit'] ?? 0 ) * $q );
					$acc[ $pid ]['delivery']['hand']              += $q;
					continue;
				}
				$acc[ $pid ]['channels']['free']['qty'] += $q;
				$acc[ $pid ]['free'][]                   = array(
					'date'      => (string) $m['date'],
					'type'      => $type,
					'qty'       => $q,
					'recipient' => (string) ( $m['rcpt'] ?? '' ),
					'kind'      => (string) ( $m['rkind'] ?? '' ),
					'ben'       => (string) ( $m['ben'] ?? '' ),
				);
			}
		}
		return $acc;
	}

	/** Net of VAT for a gross amount of a product (its tax class, base rates). */
	private static function net_of( int $pid, float $gross ): float {
		$p = wc_get_product( $pid );
		if ( ! $p instanceof WC_Product || $gross <= 0 ) {
			return max( 0.0, $gross );
		}
		$rates = WC_Tax::get_base_tax_rates( $p->get_tax_class() );
		$tax   = array_sum( WC_Tax::calc_tax( $gross, $rates, true ) );
		return round( $gross - $tax, 2 );
	}

	/** full | discount | bundle | free_order for an e-shop line. */
	private static function eshop_kind( WC_Order_Item_Product $it, int $qty ): string {
		$total = (float) $it->get_total() + (float) $it->get_total_tax();
		if ( $total < 0.005 ) {
			return 'free_order';
		}
		$pbn = $it->get_meta( '_pbn' );
		if ( ! empty( $pbn ) ) {
			return 'bundle';
		}
		if ( (float) $it->get_subtotal() - (float) $it->get_total() > 0.005 ) {
			return 'discount';
		}
		$p = $it->get_product();
		if ( $p instanceof WC_Product ) {
			$reg = (float) $p->get_regular_price( 'edit' );
			if ( $reg > 0 && $total / max( 1, (int) $it->get_quantity() ) < $reg - 0.005 ) {
				return 'discount';
			}
		}
		return 'full';
	}

	/** Promotion entries of a title by material. */
	public static function promo( int $pid, string $from = '', string $to = '' ): array {
		$rows  = PL_Entries::query(
			array(
				'product_id' => $pid,
				'category'   => 'promotion',
				'from'       => $from,
				'to'         => $to,
				'limit'      => 5000,
			)
		);
		$by    = array();
		$net   = 0.0;
		$vat   = 0.0;
		$dates = array();
		foreach ( $rows as $r ) {
			if ( ! empty( $r['is_test'] ) ) {
				continue;
			}
			$k = '' !== $r['item'] ? $r['item'] : '_other';
			if ( ! isset( $by[ $k ] ) ) {
				$by[ $k ] = array(
					'item'  => $k,
					'label' => '_other' === $k ? __( 'Άλλο έξοδο προώθησης', 'production-ledger' ) : PL_Rates::label( $k ),
					'qty'   => 0,
					'net'   => 0.0,
					'vat'   => 0.0,
				);
			}
			$by[ $k ]['qty'] += (int) $r['qty_basis'];
			$by[ $k ]['net'] += (float) $r['net_amount'];
			$by[ $k ]['vat'] += (float) $r['vat_amount'];
			$net             += (float) $r['net_amount'];
			$vat             += (float) $r['vat_amount'];
			$dates[ $r['entry_date'] ] = true;
		}
		foreach ( $by as $k => $l ) {
			$by[ $k ]['net']   = round( $l['net'], 2 );
			$by[ $k ]['vat']   = round( $l['vat'], 2 );
			$by[ $k ]['gross'] = round( $l['net'] + $l['vat'], 2 );
		}
		return array(
			'lines'    => array_values( $by ),
			'net'      => round( $net, 2 ),
			'vat'      => round( $vat, 2 ),
			'gross'    => round( $net + $vat, 2 ),
			'packages' => count( $dates ),
		);
	}

	/** One picture made presentable: rounded money, totals, labels. */
	private static function shape( int $pid, array $a, string $from, string $to ): array {
		$ch = array();
		foreach ( self::channels() as $k => $label ) {
			$ch[] = array(
				'key'   => $k,
				'label' => $label,
				'qty'   => (int) $a['channels'][ $k ]['qty'],
				'net'   => round( (float) $a['channels'][ $k ]['net'], 2 ),
			);
		}
		$dl = array();
		foreach ( self::deliveries() as $k => $label ) {
			$dl[] = array(
				'key'   => $k,
				'label' => $label,
				'qty'   => (int) $a['delivery'][ $k ],
			);
		}
		$sold = 0;
		$rev  = 0.0;
		foreach ( $a['channels'] as $k => $c ) {
			if ( in_array( $k, array( 'free', 'free_order' ), true ) ) {
				continue;
			}
			$sold += (int) $c['qty'];
			$rev  += (float) $c['net'];
		}
		$events = array();
		foreach ( $a['events'] as $name => $q ) {
			$events[] = array(
				'name' => (string) $name,
				'qty'  => (int) $q,
			);
		}
		$p = wc_get_product( $pid );
		return array(
			'product_id' => $pid,
			'title'      => $p instanceof WC_Product ? html_entity_decode( wp_strip_all_tags( $p->get_name() ), ENT_QUOTES, 'UTF-8' ) : '#' . $pid,
			'from'       => $from,
			'to'         => $to,
			'stock'      => $p instanceof WC_Product && $p->managing_stock() ? (int) $p->get_stock_quantity() : null,
			'sold'       => $sold,
			'revenue'    => round( $rev, 2 ),
			'free'       => (int) $a['channels']['free']['qty'] + (int) $a['channels']['free_order']['qty'],
			'orders'     => (int) $a['orders'],
			'channels'   => $ch,
			'delivery'   => $dl,
			'events'     => $events,
			'stores'     => $a['stores'],
			'free_list'  => $a['free'],
			'promo'      => self::promo( $pid ),
		);
	}

	/** One title. */
	public static function title( int $pid, string $from, string $to ): array {
		$p = wc_get_product( $pid );
		if ( $p instanceof WC_Product_Variation ) {
			$pid = (int) $p->get_parent_id();
		}
		$acc = self::collect( $from, $to, array( $pid ) );
		return self::shape( $pid, $acc[ $pid ] ?? self::blank(), $from, $to );
	}

	/**
	 * Every title with a movement in the period (most copies first), the
	 * same totals for the whole shop and the promo cost per material over
	 * all titles and per year (to see a cost rise or fall).
	 */
	public static function all( string $from, string $to ): array {
		$acc    = self::collect( $from, $to );
		$titles = array();
		$tot    = self::blank();
		foreach ( $acc as $pid => $a ) {
			$titles[] = self::shape( (int) $pid, $a, $from, $to );
			foreach ( $a['channels'] as $k => $c ) {
				$tot['channels'][ $k ]['qty'] += $c['qty'];
				$tot['channels'][ $k ]['net'] += $c['net'];
			}
			foreach ( $a['delivery'] as $k => $q ) {
				$tot['delivery'][ $k ] += $q;
			}
		}
		usort(
			$titles,
			static function ( $x, $y ) {
				return ( $y['sold'] + $y['free'] ) <=> ( $x['sold'] + $x['free'] );
			}
		);
		$total          = self::shape( 0, $tot, $from, $to );
		$total['title'] = __( 'Όλοι οι τίτλοι', 'production-ledger' );
		unset( $total['promo'], $total['stock'] );
		return array(
			'from'      => $from,
			'to'        => $to,
			'titles'    => $titles,
			'total'     => $total,
			'materials' => self::materials(),
		);
	}

	/** Promotion cost per material and year over every title. */
	public static function materials(): array {
		$rows = PL_Entries::query(
			array(
				'category' => 'promotion',
				'limit'    => 5000,
			)
		);
		$by   = array();
		foreach ( $rows as $r ) {
			if ( ! empty( $r['is_test'] ) || '' === $r['item'] ) {
				continue;
			}
			$y = substr( $r['entry_date'], 0, 4 );
			$k = $r['item'];
			if ( ! isset( $by[ $k ] ) ) {
				$by[ $k ] = array(
					'item'   => $k,
					'label'  => PL_Rates::label( $k ),
					'years'  => array(),
					'titles' => array(),
				);
			}
			if ( ! isset( $by[ $k ]['years'][ $y ] ) ) {
				$by[ $k ]['years'][ $y ] = array(
					'year' => $y,
					'qty'  => 0,
					'net'  => 0.0,
					'vat'  => 0.0,
				);
			}
			$by[ $k ]['years'][ $y ]['qty']        += (int) $r['qty_basis'];
			$by[ $k ]['years'][ $y ]['net']        += (float) $r['net_amount'];
			$by[ $k ]['years'][ $y ]['vat']        += (float) $r['vat_amount'];
			$by[ $k ]['titles'][ $r['product_id'] ] = true;
		}
		$out = array();
		foreach ( $by as $m ) {
			$years = array();
			foreach ( $m['years'] as $y ) {
				$y['net']  = round( $y['net'], 2 );
				$y['vat']  = round( $y['vat'], 2 );
				$y['unit'] = $y['qty'] > 0 ? round( $y['net'] / $y['qty'], 4 ) : null;
				$years[]   = $y;
			}
			$out[] = array(
				'item'   => $m['item'],
				'label'  => $m['label'],
				'titles' => count( $m['titles'] ),
				'years'  => $years,
			);
		}
		return $out;
	}
}

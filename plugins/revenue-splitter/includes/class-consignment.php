<?php
/**
 * Revenue Splitter — books on consignment in the Author Portal (1.19.0).
 *
 * Asked by Chris on 2026-11-16 (Γλαρόλυκοι, «Πλήρης εικόνα στο Author
 * portal», cards 1, 2 and 5): the creator sees every copy and every euro of
 * their titles, not only the e-shop's sales.
 *
 *  - «Σε παρακαταθήκη»: per bookstore and title (only titles where the
 *    beneficiary has a share), all time, not the period filter: copies
 *    given, sold, returned, on the shelf (with the settle-by date), money
 *    collected and owed, and the expected share of what is owed. The
 *    expected share is NOT part of the payable balance: money counts only
 *    when it is collected (Chris, 11/11/2026).
 *  - Receipts: each bookstore payment shows in «Ιστορικό συναλλαγών» as
 *    «Είσπραξη παρακαταθήκης» (date, bookstore, the creator's share), for
 *    display only: it is already inside the sales shares.
 *
 * Source: Trade Accounts 1.8.0+ (tra_consignment_summary(), module
 * «Παρακαταθήκες»). Without it the portal shows nothing new.
 *
 * @package RevenueSplitter
 */

defined( 'ABSPATH' ) || exit;

final class RS_Consignment {

	/** Whether Trade Accounts gives the consignment picture. */
	public static function available(): bool {
		return function_exists( 'tra_consignment_summary' );
	}

	/** The beneficiary's percent on a product, or null when they have no share. */
	public static function percent_of( int $pid, string $who ): ?float {
		foreach ( RS_Beneficiaries::get_map( $pid ) as $b ) {
			if ( isset( $b['name'] ) && (string) $b['name'] === $who ) {
				return (float) $b['percent'];
			}
		}
		return null;
	}

	/**
	 * The share a beneficiary would get from an amount VAT included, the
	 * way the reports count it once collected: amount less VAT, times the
	 * percent.
	 */
	public static function expected( int $pid, float $gross, float $percent ): float {
		$rate = RS_VAT::get_rate( $pid );
		$base = $gross - $gross * ( $rate / ( 100.0 + $rate ) );
		return round( $base * $percent / 100.0, 2 );
	}

	/**
	 * The rows of a beneficiary: one per bookstore and title where they
	 * have a share, as of a date ('' = today).
	 *
	 * @return array[] {pid, title, store, city, given, first, sold, returned, open, due, overdue, collected, owed, percent, expected}
	 */
	public static function rows( string $who, string $as_of = '' ): array {
		if ( ! self::available() ) {
			return array();
		}
		$out = array();
		foreach ( (array) tra_consignment_summary( array(), $as_of ) as $pid => $list ) {
			$pid = (int) $pid;
			$pct = self::percent_of( $pid, $who );
			if ( null === $pct ) {
				continue;
			}
			$title = wp_strip_all_tags( (string) get_the_title( $pid ) );
			foreach ( (array) $list as $r ) {
				$owed  = (int) ( $r['owed_c'] ?? 0 ) / 100;
				$out[] = array(
					'pid'       => $pid,
					'title'     => '' !== $title ? $title : '#' . $pid,
					'store'     => (string) ( $r['name'] ?? '' ),
					'city'      => (string) ( $r['city'] ?? '' ),
					'given'     => (int) ( $r['given'] ?? 0 ),
					'first'     => (string) ( $r['first'] ?? '' ),
					'sold'      => (int) ( $r['sold'] ?? 0 ),
					'returned'  => (int) ( $r['returned'] ?? 0 ),
					'open'      => (int) ( $r['open'] ?? 0 ),
					'due'       => (string) ( $r['due'] ?? '' ),
					'overdue'   => ! empty( $r['overdue'] ),
					'collected' => (int) ( $r['collected_c'] ?? 0 ) / 100,
					'owed'      => $owed,
					'percent'   => $pct,
					'expected'  => self::expected( $pid, $owed, $pct ),
				);
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['store'], $b['store'] ) ?: strcmp( $a['title'], $b['title'] );
			}
		);
		return $out;
	}

	/* =====================================================================
	 * Receipts («Είσπραξη παρακαταθήκης»), display only
	 * =================================================================== */

	/**
	 * The bookstore receipts that hold a share of the beneficiary: one per
	 * order that Revenue Splitter counts (completed or processing, from the
	 * sales start date), newest first. Display only: these amounts are
	 * already inside the sales shares.
	 *
	 * @return array[] {date, store, order, share}
	 */
	public static function receipts( string $who ): array {
		if ( ! function_exists( 'tra_consignments' ) ) {
			return array();
		}
		$ids = array();
		foreach ( (array) tra_consignments( array( 'limit' => 500 ) ) as $c ) {
			foreach ( (array) ( $c['orders'] ?? array() ) as $o ) {
				if ( empty( $o['cancelled'] ) && '' !== (string) ( $o['paid'] ?? '' ) ) {
					$ids[ (int) $o['id'] ] = true;
				}
			}
			foreach ( (array) ( $c['payments'] ?? array() ) as $p ) {
				$ids[ (int) ( $p['order'] ?? 0 ) ] = true;
			}
		}
		unset( $ids[0] );
		$since = RS_Reports::sales_since();
		$out   = array();
		foreach ( array_keys( $ids ) as $oid ) {
			$order = wc_get_order( (int) $oid );
			if ( ! $order instanceof WC_Order || ! in_array( 'wc-' . $order->get_status(), RS_Reports::STATUSES, true ) ) {
				continue;
			}
			$created = $order->get_date_created();
			$date    = $created ? wp_date( 'Y-m-d', $created->getTimestamp() ) : '';
			if ( '' === $date || ( '' !== $since && $date < $since ) ) {
				continue;
			}
			$share = 0.0;
			$hit   = false;
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}
				$pid = (int) ( $item->get_variation_id() ? wp_get_post_parent_id( $item->get_variation_id() ) : $item->get_product_id() );
				$pct = $pid > 0 ? self::percent_of( $pid, $who ) : null;
				if ( null === $pct ) {
					continue;
				}
				$gross = (float) $item->get_total() + (float) $item->get_total_tax() - (float) $order->get_total_refunded_for_item( $item->get_id() );
				if ( $gross <= 0.005 ) {
					continue;
				}
				$hit    = true;
				$share += self::expected( $pid, $gross, $pct );
			}
			if ( ! $hit ) {
				continue;
			}
			$out[] = array(
				'date'  => $date,
				'store' => RS_Reports::consign_store( $order ),
				'order' => (int) $oid,
				'share' => round( $share, 2 ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] ) ?: $b['order'] <=> $a['order'];
			}
		);
		return $out;
	}

	/* =====================================================================
	 * Labels of the «Ανά προϊόν» rows
	 * =================================================================== */

	/** «από τα οποία X € είσπραξη από <bookstore>» lines of a report product (plain text). */
	public static function product_notes( array $p, callable $cur ): array {
		$out = array();
		foreach ( (array) ( $p['consign']['stores'] ?? array() ) as $store => $gross ) {
			if ( (float) $gross <= 0.005 ) {
				continue;
			}
			/* translators: 1: amount, 2: bookstore */
			$out[] = sprintf( __( 'από τα οποία %1$s είσπραξη από %2$s', 'revenue-splitter' ), $cur( (float) $gross ), (string) $store );
		}
		return $out;
	}

	/** Copies of a report product: «N» or «N + μέρος» when a payment paid only part of a copy. */
	public static function qty_label( array $p ): string {
		$n = number_format_i18n( (int) $p['qty'] );
		return (int) ( $p['consign']['part0'] ?? 0 ) > 0 ? $n . ' + ' . __( 'μέρος', 'revenue-splitter' ) : $n;
	}

	public static function part_tip(): string {
		return __( 'Το βιβλιοπωλείο πλήρωσε ποσό που καλύπτει μέρος αντιτύπου: το ποσό μετράει, το αντίτυπο θα μετρηθεί όταν πληρωθεί ολόκληρο.', 'revenue-splitter' );
	}

	/* =====================================================================
	 * Portal
	 * =================================================================== */

	public static function render_portal( string $who ): void {
		$rows = self::rows( $who );
		if ( empty( $rows ) ) {
			return;
		}
		$cur = static function ( $v ) {
			return wp_strip_all_tags( wc_price( (float) $v ) );
		};
		$tot = array_fill_keys( array( 'given', 'sold', 'returned', 'open', 'collected', 'owed', 'expected' ), 0 );
		foreach ( $rows as $r ) {
			foreach ( array_keys( $tot ) as $k ) {
				$tot[ $k ] += $r[ $k ];
			}
		}
		?>
		<h3 id="rs-consign"><?php esc_html_e( 'Σε παρακαταθήκη', 'revenue-splitter' ); ?></h3>
		<div class="rs-pt-scroll">
		<table class="rs-pt-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Βιβλιοπωλείο', 'revenue-splitter' ); ?></th>
					<th><?php esc_html_e( 'Τίτλος', 'revenue-splitter' ); ?></th>
					<th class="num"><?php esc_html_e( 'Δόθηκαν', 'revenue-splitter' ); ?></th>
					<th class="num"><?php esc_html_e( 'Πουλήθηκαν', 'revenue-splitter' ); ?></th>
					<th class="num"><?php esc_html_e( 'Επέστρεψαν', 'revenue-splitter' ); ?></th>
					<th class="num"><?php esc_html_e( 'Στο ράφι', 'revenue-splitter' ); ?></th>
					<th class="num"><?php esc_html_e( 'Εισπράχθηκαν', 'revenue-splitter' ); ?></th>
					<th class="num"><?php esc_html_e( 'Οφειλόμενα', 'revenue-splitter' ); ?></th>
					<th class="num"><?php esc_html_e( 'Αναμενόμενο μερίδιο', 'revenue-splitter' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td><?php echo esc_html( $r['store'] ); ?><?php if ( '' !== $r['city'] ) : ?> <small class="rs-pt-muted"><?php echo esc_html( $r['city'] ); ?></small><?php endif; ?></td>
					<td><?php echo esc_html( $r['title'] ); ?></td>
					<td class="num"><?php echo esc_html( number_format_i18n( $r['given'] ) ); ?><?php if ( '' !== $r['first'] ) : ?><br /><small class="rs-pt-muted"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'από %s', 'revenue-splitter' ), RS_Contracts::dmy( $r['first'] ) ) ); ?></small><?php endif; ?></td>
					<td class="num"><?php echo esc_html( number_format_i18n( $r['sold'] ) ); ?></td>
					<td class="num"><?php echo esc_html( number_format_i18n( $r['returned'] ) ); ?></td>
					<td class="num"><?php echo esc_html( number_format_i18n( $r['open'] ) ); ?><?php if ( '' !== $r['due'] ) : ?><br /><small class="<?php echo esc_attr( $r['overdue'] ? 'rs-down' : 'rs-pt-muted' ); ?>"><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'εκκαθάριση έως %s', 'revenue-splitter' ), RS_Contracts::dmy( $r['due'] ) ) ); ?></small><?php endif; ?></td>
					<td class="num"><?php echo esc_html( $cur( $r['collected'] ) ); ?></td>
					<td class="num"><?php echo esc_html( $cur( $r['owed'] ) ); ?></td>
					<td class="num"><?php echo esc_html( $cur( $r['expected'] ) ); ?> <small><?php echo esc_html( number_format_i18n( $r['percent'], 1 ) ); ?>%</small></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
			<?php if ( count( $rows ) > 1 ) : ?>
			<tfoot>
				<tr>
					<th colspan="2"><?php esc_html_e( 'Σύνολο', 'revenue-splitter' ); ?></th>
					<th class="num"><?php echo esc_html( number_format_i18n( $tot['given'] ) ); ?></th>
					<th class="num"><?php echo esc_html( number_format_i18n( $tot['sold'] ) ); ?></th>
					<th class="num"><?php echo esc_html( number_format_i18n( $tot['returned'] ) ); ?></th>
					<th class="num"><?php echo esc_html( number_format_i18n( $tot['open'] ) ); ?></th>
					<th class="num"><?php echo esc_html( $cur( $tot['collected'] ) ); ?></th>
					<th class="num"><?php echo esc_html( $cur( $tot['owed'] ) ); ?></th>
					<th class="num"><?php echo esc_html( $cur( $tot['expected'] ) ); ?></th>
				</tr>
			</tfoot>
			<?php endif; ?>
		</table>
		</div>
		<p class="rs-pt-note">
			<?php esc_html_e( 'Όλη η ιστορία, όχι μόνο η περίοδος του φίλτρου. Τα ποσά είναι με ΦΠΑ. Το βιβλιοπωλείο πληρώνει συνολικά ποσά. Κάθε είσπραξη μοιράζεται στα πουλημένα αντίτυπα ανάλογα με την αξία τους.', 'revenue-splitter' ); ?>
			<?php esc_html_e( 'Το «Αναμενόμενο μερίδιο» είναι το μερίδιό σου από τα οφειλόμενα, χωρίς ΦΠΑ. Δεν μπαίνει στο «Αποπληρωτέο υπόλοιπο»: μετράει μόνο όταν εισπραχθεί.', 'revenue-splitter' ); ?>
		</p>
		<?php
	}
}

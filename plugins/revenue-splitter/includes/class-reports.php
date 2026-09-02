<?php
/**
 * Αναφορές: ερωτήματα πωλήσεων, αφαίρεση ΦΠΑ, καταμερισμός σε δικαιούχους.
 *
 * Στρατηγική:
 *  - Χρησιμοποιούμε το Order API του WooCommerce (HPOS-compatible).
 *  - Gross γραμμής = line total + line tax (ΦΠΑ-συμπεριληπτικό, ό,τι πλήρωσε ο πελάτης).
 *  - ΦΠΑ: αφαιρείται με βάση τον συντελεστή του Revenue Splitter,
 *    με τον τύπο «από μέσα»: vat = gross × rate / (100 + rate).
 *  - Refunds: αφαιρούνται ανά line item μέσω '_refunded_item_id'.
 *
 * v1.1.2 FIX: Το φίλτρο προϊόντος γίνεται ΠΕΡΙ ITEM (PHP-level) και όχι μέσω
 * του query arg 'product' του wc_get_orders(), το οποίο αγνοείται σιωπηλά
 * σε ορισμένα HPOS setups — γι' αυτό τα φίλτρα «δεν έφταναν» ποτέ στο
 * αποτέλεσμα. Per-item filtering = datastore-agnostic, πάντα σωστό.
 *
 * v1.1.2 AUDIT FIX (#7): Το order_count μετράει ΠΑΝΤΑ μόνο τις παραγγελίες
 * που συνέβαλαν τουλάχιστον μία πωλημένη γραμμή στο αποτέλεσμα
 * ($matched_orders) — όχι όλες τις παραγγελίες περιόδου (π.χ. παραγγελίες
 * μόνο με shipping/fees δεν πρεπεί να μετρούν).
 *
 * Αποτελέσματα cached σε transient (5 λεπτά) με hash στα args.
 */

defined( 'ABSPATH' ) || exit;

class RS_Reports {

	const CACHE_TTL = 300; // 5 λεπτά.

	const STATUSES = array( 'wc-completed', 'wc-processing' );

	public static function init(): void {
		add_action( 'rs_invalidate_cache', array( __CLASS__, 'flush_cache' ) );
	}

	public static function flush_cache(): void {
		update_option( 'rs_cache_version', (string) time() );
	}

	/* ---------------------------------------------------------------------
	 * Public API
	 * ------------------------------------------------------------------- */

	/**
	 * Κύρια αναφορά.
	 *
	 * @param array $args {
	 *     @type string $date_start  ISO date 'Y-m-d' (inclusive, local time).
	 *     @type string $date_end    ISO date 'Y-m-d' (inclusive, local time).
	 *     @type int[]  $product_ids Προαιρετικό φίλτρο σε συγκεκριμένα IDs.
	 * }
	 * @return array
	 */
	public static function run( array $args = array() ): array {

		$defaults = array(
			'date_start'  => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
			'date_end'    => gmdate( 'Y-m-d' ),
			'product_ids' => array(),
		);
		$args = wp_parse_args( $args, $defaults );

		$cache_key = 'rs_report_' . md5( wp_json_encode( $args ) . '|' . get_option( 'rs_cache_version', '0' ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = self::compute( $args );
		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Υπολογισμός
	 * ------------------------------------------------------------------- */

	private static function compute( array $args ): array {

		// Επιτρεπόμενα product IDs (flip σε lookup map για O(1)).
		$allowed_ids = array();
		if ( ! empty( $args['product_ids'] ) ) {
			$allowed_ids = array_fill_keys(
				array_map( 'absint', array_filter( (array) $args['product_ids'] ) ),
				true
			);
		}

		$query_args = array(
			'limit'  => -1,
			'status' => self::STATUSES,
			'type'   => 'shop_order',
			'return' => 'objects',

			/*
			 * ΟΧΙ query-level product filter εδώ (δεν είναι αξιόπιστο σε όλα
			 * τα datastores/HPOS εκδόσεις). Το φιλτράρισμα γίνεται per-item.
			 */
			'date_created' => $args['date_start'] . ' 00:00:00...' . $args['date_end'] . ' 23:59:59',
		);

		$orders = wc_get_orders( $query_args );

		$per_product    = array();
		$ben_totals     = array();
		$warnings       = array();
		$matched_orders = array(); // Παραγγελίες που συνεισέφεραν στο (φιλτραρισμένο) αποτέλεσμα.

		foreach ( $orders as $order ) {
			/** @var WC_Order $order */

			$refunded_by_item = self::collect_refunds( $order );

			foreach ( $order->get_items() as $item ) {
				/** @var WC_Order_Item_Product $item */

				$pid = self::resolve_product_id( $item );
				if ( null === $pid || $pid <= 0 ) {
					continue;
				}

				// ---- Το φίλτρο προϊόντος ΕΔΩ (per-item, πάντα σωστό) ----
				if ( ! empty( $allowed_ids ) && ! isset( $allowed_ids[ $pid ] ) ) {
					continue;
				}
				// --------------------------------------------------------

				// Gross γραμμής (ΦΠΑ-συμπεριληπτικό).
				$gross = (float) $item->get_total() + (float) $item->get_total_tax();

				// Refunds που αφορούν αυτή τη γραμμή.
				$item_id   = $item->get_id();
				$refunded  = isset( $refunded_by_item[ $item_id ] ) ? $refunded_by_item[ $item_id ] : 0.0;
				$net_gross = $gross - $refunded;

				if ( $net_gross <= 0.0 ) {
					continue; // Πλήρως refunded γραμμή.
				}

				$matched_orders[ $order->get_id() ] = true;

				/*
				 * Ο gross είναι ΦΠΑ-συμπεριληπτικός → εξάγουμε τον ΦΠΑ «από μέσα»:
				 *   vat  = gross × rate / (100 + rate)
				 *   base = gross − vat
				 */
				$rate = RS_VAT::get_rate( $pid );
				$map  = RS_Beneficiaries::get_map( $pid );

				$vat  = $net_gross * ( $rate / ( 100.0 + $rate ) );
				$base = $net_gross - $vat;

				if ( ! isset( $per_product[ $pid ] ) ) {
					$per_product[ $pid ] = array(
						'gross' => 0.0,
						'vat'   => 0.0,
						'net'   => 0.0,
						'qty'   => 0,
					);
				}

				$per_product[ $pid ]['gross'] += $net_gross;
				$per_product[ $pid ]['vat']   += $vat;
				$per_product[ $pid ]['net']   += $base;
				$per_product[ $pid ]['qty']   += (int) $item->get_quantity();

				foreach ( $map as $ben ) {
					$name = $ben['name'];
					if ( ! isset( $ben_totals[ $name ] ) ) {
						$ben_totals[ $name ] = 0.0;
					}
					$ben_totals[ $name ] += $base * ( $ben['percent'] / 100.0 );
				}

				if ( ! RS_VAT::has_explicit_rate( $pid ) ) {
					$warnings[ $pid ]['vat_default'] = true;
				}
				if ( ! RS_Beneficiaries::has_override( $pid ) ) {
					$warnings[ $pid ]['ben_default'] = true;
				}
			}
		}

		// Τελικός πίνακας ανά προϊόν.
		$products = array();
		foreach ( $per_product as $pid => $acc ) {

			$title = get_the_title( $pid );
			$title = $title ? $title : sprintf( __( 'Προϊόν #%d', 'revenue-splitter' ), $pid );

			$map    = RS_Beneficiaries::get_map( $pid );
			$splits = array();
			foreach ( $map as $ben ) {
				$splits[] = array(
					'name'    => $ben['name'],
					'percent' => $ben['percent'],
					'amount'  => round( $acc['net'] * ( $ben['percent'] / 100.0 ), 2 ),
				);
			}

			$products[] = array(
				'product_id'  => $pid,
				'title'       => $title,
				'qty'         => $acc['qty'],
				'gross'       => round( $acc['gross'], 2 ),
				'vat'         => round( $acc['vat'], 2 ),
				'net'         => round( $acc['net'], 2 ),
				'vat_rate'    => RS_VAT::get_rate( $pid ),
				'ben_default' => ! RS_Beneficiaries::has_override( $pid ),
				'splits'      => $splits,
			);
		}

		usort(
			$products,
			static function ( $a, $b ) {
				return $b['net'] <=> $a['net'];
			}
		);

		$t_gross = 0.0;
		$t_vat   = 0.0;
		$t_net   = 0.0;
		foreach ( $products as $p ) {
			$t_gross += $p['gross'];
			$t_vat   += $p['vat'];
			$t_net   += $p['net'];
		}

		arsort( $ben_totals );
		$beneficiaries = array();
		foreach ( $ben_totals as $name => $amount ) {
			$beneficiaries[] = array(
				'name'   => $name,
				'amount' => round( $amount, 2 ),
			);
		}

		/*
		 * AUDIT FIX (#7): order_count από τις παραγγελίες που πραγματικά
		 * συνέβαλαν πωλημένες γραμμές — πάντα $matched_orders, ανεξάρτητα
		 * από το αν υπάρχει φίλτρο προϊόντος (οι «άδειες» παραγγελίες
		 * μόνο με shipping/fees δεν μετράνε πουθενά).
		 */
		return array(
			'period'        => array(
				'start' => $args['date_start'],
				'end'   => $args['date_end'],
			),
			'products'      => $products,
			'totals'        => array(
				'gross' => round( $t_gross, 2 ),
				'vat'   => round( $t_vat, 2 ),
				'net'   => round( $t_net, 2 ),
			),
			'beneficiaries' => $beneficiaries,
			'warnings'      => $warnings,
			'order_count'   => count( $matched_orders ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * «Λογιστικό» product_id ενός line item — variations fall-back στον parent.
	 */
	private static function resolve_product_id( WC_Order_Item_Product $item ): ?int {

		$product = $item->get_product();

		if ( $product instanceof WC_Product_Variation ) {
			$parent = (int) $product->get_parent_id();
			return $parent > 0 ? $parent : null;
		}
		if ( $product instanceof WC_Product ) {
			return (int) $product->get_id();
		}

		// Διαγραμμένο προϊόν — το stored product_id (για variation, ο parent).
		$pid = (int) $item->get_product_id();
		return $pid > 0 ? $pid : null;
	}

	/**
	 * Refunded ποσά ανά original item id για μία παραγγελία.
	 *
	 * Τα refund items έχουν αρνητικά totals στο Woo — το abs() είναι υποχρεωτικό,
	 * αλλιώς τα refunds αγνοούνται σιωπηλά.
	 *
	 * @return float[] original_item_id => refunded amount (θετικό).
	 */
	private static function collect_refunds( WC_Order $order ): array {

		$refunded = array();

		foreach ( $order->get_refunds() as $refund ) {
			/** @var WC_Order_Refund $refund */

			foreach ( $refund->get_items() as $ref_item ) {
				/** @var WC_Order_Item $ref_item */

				$orig = (int) $ref_item->get_meta( '_refunded_item_id' );
				if ( $orig <= 0 ) {
					continue;
				}

				$amount = abs( (float) $ref_item->get_total() + (float) $ref_item->get_total_tax() );
				if ( $amount > 0 ) {
					$refunded[ $orig ] = ( $refunded[ $orig ] ?? 0.0 ) + $amount;
				}
			}
		}

		return $refunded;
	}
}
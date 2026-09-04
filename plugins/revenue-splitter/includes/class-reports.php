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
 * ($matched_orders) — όχι όλες τις παραγγελίες περιόδου.
 *
 * v1.3.0 FIX (#3): Rounding reconciliation (largest-remainder) στα splits
 * κάθε προϊόντος — το Σ των ποσών των δικαιούχων ταιριάζει ΠΑΝΤΑ ακριβώς
 * με το round(net, 2) του προϊόντος. Τέλος στα 99.99% / 100.01%.
 *
 * v1.3.0 (#1): lifetime_beneficiaries() — all-time κέρδη ανά δικαιούχο
 * (κοινός helper για dashboard, portal και CLI).
 *
 * v1.3.1 FIX (#7-audit): TIMEZONE CORRECTION στο order query.
 * Το date_created του wc_get_orders() ερμηνεύεται σε UTC, ενώ όλο το
 * υπόλοιπο plugin (περίοδοι UI, ledger sums, presets) δουλεύει σε
 * wp_timezone(). Χωρίς μετατροπή, παραγγελίες κοντά στα σύνορα ημερών/
 * μηνών μετριόντουσαν σε λάθος περίοδο (π.χ. GMT+3: 00:00–03:00 τοπική
 * ώρα πήγαιναν στην προηγούμενη μέρα/μήνα στο report).
 * Τώρα: τα local-day όρια (00:00:00 → 23:59:59 site timezone)
 * μετατρέπονται σε UTC timestamps πριν το query — το returned report
 * αντιστοιχεί ΠΑΝΤΑ στην περίοδο όπως τη βλέπει ο χρήστης.
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
	 *     @type string $date_start  ISO date 'Y-m-d' (inclusive, LOCAL site time).
	 *     @type string $date_end    ISO date 'Y-m-d' (inclusive, LOCAL site time).
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

	/**
	 * All-time κέρδη ανά δικαιούχο (v1.3.0, #1).
	 *
	 * «Πόσα χρωστάω συνολικά σε αυτόν τον δικαιούχο από πωλήσεις;» —
	 * η basis του lifetime balance (μαζί με ledger sums του caller).
	 *
	 * Περνά από το ίδιο caching με το run() — το wide-range report
	 * υπολογίζεται μία φορά / 5' ανεξαρτήτως πόσοι το ζητήσουν
	 * (dashboard, portal, CLI).
	 *
	 * @return float[] name => amount (rounded, 2 decimals).
	 */
	public static function lifetime_beneficiaries(): array {

		$now = new DateTimeImmutable( 'now', wp_timezone() );

		$report = self::run(
			array(
				'date_start' => '2000-01-01', // Πρακτικά «αρχή των χρόνων».
				'date_end'   => $now->format( 'Y-m-d' ),
			)
		);

		$out = array();
		foreach ( $report['beneficiaries'] as $b ) {
			$out[ (string) $b['name'] ] = (float) $b['amount'];
		}

		return $out;
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

		/*
		 * v1.3.1 FIX (#7): UTC conversion των LOCAL-day ορίων.
		 *
		 * Το date_created του wc_get_orders() ερμηνεύει τα date strings
		 * ως UTC. Χτίζουμε τα όρια της περιόδου ως αντικείμενα στην
		 * τοπική ζώνη (00:00:00 τοπική ώρα → 23:59:59 τοπική ώρα),
		 * τα μετατρέπουμε σε UTC και τα formatάρουμε — έτσι το query
		 * επιστρέφει ακριβώς ό,τι αντιστοιχεί στην τοπική ημερομηνία
		 * της περιόδου.
		 */
		try {
			$tz         = wp_timezone();
			$local_from = new DateTimeImmutable( $args['date_start'] . ' 00:00:00', $tz );
			$local_to   = new DateTimeImmutable( $args['date_end'] . ' 23:59:59', $tz );

			$utc_from = $local_from->setTimezone( new DateTimeZone( 'UTC' ) );
			$utc_to   = $local_to->setTimezone( new DateTimeZone( 'UTC' ) );

			$created_range = $utc_from->format( 'Y-m-d H:i:s' ) . '...' . $utc_to->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			// Αμυντικό fallback στην παλιά συμπεριφορά — το compute δεν
			// πρέπει ποτέ να γίνεται αιτία fatal από άκυρο date input.
			$created_range = $args['date_start'] . ' 00:00:00...' . $args['date_end'] . ' 23:59:59';
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
			'date_created' => $created_range,
		);

		$orders = wc_get_orders( $query_args );

		$per_product    = array();
		$ben_totals     = array();
		$warnings       = array();
		$matched_orders = array(); // Παραγγελίες που συνεισέφεραν τουλάχιστον μία πληρωμένη γραμμή.

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

				$qty = (int) $item->get_quantity();
				if ( $qty <= 0 ) {
					continue; // Γραμμές refund δεν είναι πωλήσεις.
				}

				// Gross γραμμής (ΦΠΑ-συμπεριληκτικό), μετά refunds.
				$gross = (float) $item->get_total() + (float) $item->get_total_tax();

				$item_id   = $item->get_id();
				$refunded  = isset( $refunded_by_item[ $item_id ] ) ? $refunded_by_item[ $item_id ] : 0.0;
				$net_gross = $gross - $refunded;

				$rate = RS_VAT::get_rate( $pid );

				if ( ! isset( $per_product[ $pid ] ) ) {
					$per_product[ $pid ] = array(
						'gross'    => 0.0,
						'vat'      => 0.0,
						'net'      => 0.0,
						'qty'      => 0,
						'qty_full' => 0,
						'qty_disc' => 0,
						'qty_free' => 0,
						'disc_w'   => 0.0, // Άθροισμα (έκπτωση% × τεμ.) → σταθμισμένος μ.ο.
						'disc_amt' => 0.0, // Συνολική έκπτωση (μικτή, incl ΦΠΑ).
					);
				}

				/*
				 * Δωρεάν αντίγραφα: γραμμή που δεν πληρώθηκε ΤΙΠΟΤΑ
				 * (coupon 100% ή τιμή 0). Μετράει στα τεμάχια, όχι στα έσοδα.
				 * Πλήρως refunded γραμμή (gross > 0, paid = 0) ΑΓΝΟΕΙΤΑΙ —
				 * δεν είναι «δωρεάν», δεν πουλήθηκε.
				 */
				if ( $net_gross <= 0.005 ) {
					if ( (float) $item->get_total() <= 0.005 ) {
						$per_product[ $pid ]['qty_free'] += $qty;
					}
					continue;
				}

				$matched_orders[ $order->get_id() ] = true;

				// Gross γραμμής χωρίς έκπτωση (μικτό, incl ΦΠΑ): regular subtotal × συντελεστής.
				$factor    = ( 100.0 + $rate ) / 100.0;
				$reg_gross = ( (float) $item->get_subtotal() ) * $factor;
				$disc_line = max( 0.0, $reg_gross - $gross );

				$per_product[ $pid ]['qty'] += $qty;

				if ( $disc_line > 0.01 ) {
					$per_product[ $pid ]['qty_disc'] += $qty;
					$per_product[ $pid ]['disc_w']   += ( $disc_line / $reg_gross ) * 100.0 * $qty;
					$per_product[ $pid ]['disc_amt'] += $disc_line;
				} else {
					$per_product[ $pid ]['qty_full'] += $qty;
				}

				/*
				 * Ο gross είναι ΦΠΑ-συμπεριληκτικός → εξάγουμε τον ΦΠΑ «από μέσα»:
				 *   vat  = gross × rate / (100 + rate)
				 *   base = gross − vat
				 */
				$vat  = $net_gross * ( $rate / ( 100.0 + $rate ) );
				$base = $net_gross - $vat;

				$per_product[ $pid ]['gross'] += $net_gross;
				$per_product[ $pid ]['vat']   += $vat;
				$per_product[ $pid ]['net']   += $base;

				$map = RS_Beneficiaries::get_map( $pid );

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

			if ( $acc['qty'] <= 0 && $acc['qty_free'] <= 0 ) {
				continue; // Μόνο refunded γραμμές — δεν εμφανίζεται.
			}

			$title = get_the_title( $pid );
			$title = $title ? $title : sprintf( __( 'Προϊόν #%d', 'revenue-splitter' ), $pid );

			$map    = RS_Beneficiaries::get_map( $pid );
			$splits = self::reconcile_splits( $map, round( $acc['net'], 2 ) );

			$products[] = array(
				'product_id'  => $pid,
				'title'       => $title,
				'qty'         => $acc['qty'],
				'gross'       => round( $acc['gross'], 2 ),
				'vat'         => round( $acc['vat'], 2 ),
				'net'         => round( $acc['net'], 2 ),
				'vat_rate'    => RS_VAT::get_rate( $pid ),
				'ben_default' => ! RS_Beneficiaries::has_override( $pid ),
				'qty_full'    => $acc['qty_full'],
				'qty_disc'    => $acc['qty_disc'],
				'qty_free'    => $acc['qty_free'],
				'disc_pct'    => $acc['qty_disc'] > 0 ? round( $acc['disc_w'] / $acc['qty_disc'], 1 ) : 0.0,
				'disc_amt'    => round( $acc['disc_amt'], 2 ),
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
	 * v1.3.0 FIX (#3): Largest-remainder reconciliation των splits.
	 *
	 * Το Σ(round(net_i × pct_i, 2)) δεν ισούται ΠΑΝΤΑ με round(net, 2)
	 * (π.χ. 33.33+33.33+33.34 ≠ 100.00 σε κάποιους συνδυασμούς rounding).
	 *
	 * Αλγόριθμος:
	 *  1. Κάθε split παίρνει floor(amount) σε cents.
	 *  2. Η διαφορά από τον στόχο (target cents = round(net,2) × 100)
	 *     διανέμεται 1 cent την φορά σε αυτά με το ΜΕΓΑΛΥΤΕΡΟ
	 *     υπολειπόμενο κλάσμα (largest remainder method).
	 *  3. Έτσι Σ splits == target ΠΑΝΤΑ, με max απόκλιση 1 cent
	 *     από το «δίκαιο» ποσό ανά δικαιούχο.
	 *
	 * @param array[] $map   [['name'=>string,'percent'=>float], …]
	 * @param float   $net   Το (στρογγυλεμένο) καθαρό ποσό προς διανομή.
	 * @return array[] [['name'=>string,'percent'=>float,'amount'=>float], …]
	 */
	private static function reconcile_splits( array $map, float $net ): array {

		// Προστασία από άδειο/άκυρο map (δεν θα έπρεπε να συμβεί — get_map
		// έχει trivial fallback, αλλά defensive εδώ).
		if ( empty( $map ) ) {
			return array();
		}

		$target = (int) round( $net * 100 ); // Στόχος σε cents.

		$cents = array();
		$frac  = array();
		$sum   = 0;

		foreach ( array_values( $map ) as $i => $ben ) {
			$raw         = $net * ( (float) $ben['percent'] / 100.0 ) * 100;
			$cents[ $i ] = (int) floor( $raw );
			$frac[ $i ]  = $raw - $cents[ $i ];
			$sum        += $cents[ $i ];
		}

		$diff = $target - $sum;

		if ( $diff > 0 ) {
			// Λείπουν cents → δώσε στα μεγαλύτερα υπολειπόμενα κλάσματα.
			arsort( $frac );
			foreach ( array_slice( array_keys( $frac ), 0, $diff, true ) as $i ) {
				$cents[ $i ]++;
			}
		} elseif ( $diff < 0 ) {
			// Περισσεύουν cents → πάρε από τα μικρότερα κλάσματα.
			asort( $frac );
			foreach ( array_slice( array_keys( $frac ), 0, - $diff, true ) as $i ) {
				$cents[ $i ]--;
			}
		}

		$out = array();
		foreach ( array_values( $map ) as $i => $ben ) {
			$out[] = array(
				'name'    => (string) $ben['name'],
				'percent' => (float) $ben['percent'],
				'amount'  => round( $cents[ $i ] / 100.0, 2 ),
			);
		}

		return $out;
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
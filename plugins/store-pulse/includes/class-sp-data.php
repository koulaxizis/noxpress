<?php
/**
 * SP_Data — Ο «pulse engine»: όλα τα μεγέθη του Store Pulse.
 *
 * Πηγές:
 *  - Παραγγελίες: WooCommerce Order API (HPOS-compatible, wc_get_orders)
 *    με timezone-correct όρια (ίδιο pattern με RS_Reports v1.3.1 #7:
 *    local-day 00:00:00–23:59:59 → UTC πριν το query).
 *  - Χρήματα: ΜΟΝΟ μέσω RS_Reports::run() / RS_Beneficiaries /
 *    RS_Ledger::sum() — το Store Pulse ΔΕΝ ξαναϋπολογίζει ποσοστά,
 *    ΦΠΑ ή μεταφορικά (τα RS reports βασίζονται σε line items, οπότε
 *    το shipping δεν μπαίνει ποτέ στα ποσά).
 *
 * «Εξυπηρετημένες» = status completed, με ημερομηνία ΟΛΟΚΛΗΡΩΣΗΣ
 * (date_completed) μέσα στην περίοδο. To query φιλτράρει με
 * date_created σε lookback παράθυρο (90 ημέρες πριν την έναρξη
 * της περιόδου) — δικό μας guard για παραγγελίες που ολοκληρώθηκαν
 * πολύ μετά τη δημιουργία τους.
 *
 * «Επιστροφές» = shop_order_refund objects με date_created στην
 * περίοδο (άρθρωση + ποσό).
 *
 * «Ακυρωμένες» = status cancelled, με date_modified στην περίοδο
 * (approximation της στιγμής ακύρωσης — το Woo δεν κρατά ειδικό
 * «cancelled_at»). Lookback 180 ημερών στο date_created ως pruning.
 *
 * Caching: κάθε section σε transient (5'), key = section + args +
 * rs_cache_version + sp_cache_version. Το rs_cache_version μπαίνει
 * στο key ώστε οι αλλαγές του Revenue Splitter (splits/ΦΠΑ/ledger)
 * να μην σερβίρουν stale money cards — μηδενικά ghost νούμερα.
 */

defined( 'ABSPATH' ) || exit;

final class SP_Data {

	const CACHE_TTL = 300; // 5 λεπτά — ίδιο ρυθμό με το RS.

	/** Statuses που θεωρούμε «σε εκκρεμότητα». */
	const PENDING_STATUSES = array( 'wc-pending', 'wc-processing', 'wc-on-hold' );

	/** Εκκρεμείς > 7 ημέρες (από τη δημιουργία τους). */
	const PENDING_OLD_DAYS = 7;

	/** Lookup lookback: πόσες ημέρες πριν το start του range ψάχνουμε */
	/** παραγγελίες που δημιουργήθηκαν (για date_completed filter). */
	const LOOKBACK_COMPLETED = '90 days';

	/** Lookback για cancelled (prune του date_created). */
	const LOOKBACK_CANCELLED = '180 days';

	/** Έγκυρα presets ρυθμίσεων (labels στο SP_Admin/SP_Lang). */
	const PRESETS = array( 'today', 'yesterday', '7d', '15d', 'month' );

	public static function init(): void {
		/*
		 * Οποιαδήποτε αλλαγή στο Revenue Splitter (καταμερισμός, ΦΠΑ,
		 * ledger, έναρξη καταγραφής) invalidates ΚΑΙ τα money cards
		 * εδώ: το rs_invalidate_cache κάνει bump το δικό μας
		 * sp_cache_version. Ακόμα κι αν ξεχάσουμε κάτι, το TTL (5')
		 * είναι το fail-safe.
		 */
		add_action( 'rs_invalidate_cache', array( __CLASS__, 'flush' ) );
	}

	/** Bump του δικού μας cache generation. */
	public static function flush(): void {
		update_option( 'sp_cache_version', (string) time() );
	}

	/* =====================================================================
	 * Περίοδοι
	 * =================================================================== */

	/**
	 * Το [start, end] (Y-m-d, τοπικές ημέρες) ενός preset.
	 *
	 *  - 'today'    : μόνο σήμερα.
	 *  - 'yesterday': μόνο χθες.
	 *  - '7d'       : σήμερα + 6 προηγούμενες.
	 *  - '15d'      : σήμερα + 14 προηγούμενες.
	 *  - 'month'    : rolling 30 ημέρες (σήμερα + 29).
	 *
	 * @return array{0:string,1:string}|null null σε άγνωστο preset.
	 */
	public static function period_range( string $preset ): ?array {

		if ( ! in_array( $preset, self::PRESETS, true ) ) {
			return null;
		}

		$now = new DateTimeImmutable( 'now', wp_timezone() );

		switch ( $preset ) {
			case 'today':
				$s = $now->format( 'Y-m-d' );
				$e = $s;
				break;
			case 'yesterday':
				$y = $now->modify( '-1 day' );
				$s = $y->format( 'Y-m-d' );
				$e = $s;
				break;
			case '7d':
				$s = $now->modify( '-6 days' )->format( 'Y-m-d' );
				$e = $now->format( 'Y-m-d' );
				break;
			case '15d':
				$s = $now->modify( '-14 days' )->format( 'Y-m-d' );
				$e = $now->format( 'Y-m-d' );
				break;
			case 'month':
			default:
				$s = $now->modify( '-29 days' )->format( 'Y-m-d' );
				$e = $now->format( 'Y-m-d' );
				break;
		}

		return array( $s, $e );
	}

	/* =====================================================================
	 * Caching helper
	 * =================================================================== */

	/**
	 * Cached producer. Transient key = section + args + generation
	 * markers. Η τιμή είναι πάντα array (canonical, για το transient).
	 *
	 * @param string   $key      Σταθερό, ήδη-sanitized section key.
	 * @param callable $producer () => array.
	 * @return array
	 */
	private static function cached( string $key, callable $producer ): array {

		$gen = 'rs' . (string) get_option( 'rs_cache_version', '0' )
			. '|sp' . (string) get_option( 'sp_cache_version', '0' );

		$tk = 'sp_' . md5( $key . '|' . $gen );
		$hit = get_transient( $tk );

		if ( is_array( $hit ) ) {
			return $hit;
		}

		$value = $producer();
		if ( ! is_array( $value ) ) {
			$value = array(); // Defensive — ποτέ poisoning του cache.
		}

		set_transient( $tk, $value, self::CACHE_TTL );

		return $value;
	}

	/**
	 * Local-day [start, end] → UTC 'Y-m-d H:i:s...Y-m-d H:i:s' για το
	 * date_created του wc_get_orders (το ερμηνεύει σε UTC — ίδιο fix
	 * με το RS_Reports v1.3.1 #7, χωρίς το οποίο οι μεσονύκτιες
	 * παραγγελίες πήγαιναν σε λάθος μέρα).
	 */
	private static function utc_created_range( string $start, string $end ): string {

		try {
			$tz = wp_timezone();

			$from = ( new DateTimeImmutable( $start . ' 00:00:00', $tz ) )
				->setTimezone( new DateTimeZone( 'UTC' ) );
			$to = ( new DateTimeImmutable( $end . ' 23:59:59', $tz ) )
				->setTimezone( new DateTimeZone( 'UTC' ) );

			return $from->format( 'Y-m-d H:i:s' ) . '...' . $to->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			/*
			 * Defensive fallback — ποτέ fatal από άκυρα date inputs.
			 * (Το input έχει περάσει ήδη από validated 'Y-m-d', οπότε
			 * σε πρακτική χρήση είναι unreachable — ο last resort
			 * μας είναι τα plain local timestamps.)
			 */
			return $start . ' 00:00:00...' . $end . ' 23:59:59';
		}
	}

	/* =====================================================================
	 * Sections
	 * =================================================================== */

	/**
	 * Εκκρεμείς: count + πόσες είναι «παλιές» (creation > 7 ημέρες).
	 *
	 * @return array{count:int,old:int}
	 */
	public static function pending(): array {

		return self::cached(
			'pending',
			static function (): array {

				$orders = wc_get_orders(
					array(
						'limit'  => -1,
						'status' => self::PENDING_STATUSES,
						'type'   => 'shop_order',
					)
				);

				$cut = ( new DateTimeImmutable( 'now', wp_timezone() ) )
					->modify( '-' . self::PENDING_OLD_DAYS . ' days' );

				$count = 0;
				$old   = 0;

				foreach ( $orders as $order ) {
					/** @var WC_Order $order */
					$count++;

					$dc = $order->get_date_created();
					if ( $dc instanceof WC_DateTime && $dc->getTimestamp() <= $cut->getTimestamp() ) {
						$old++;
					}
				}

				return array(
					'count' => $count,
					'old'   => $old,
				);
			}
		);
	}

	/**
	 * Εξυπηρετημένες παραγγελίες περιόδου (date_completed-based) + ό,τι
	 * παράγεται «δωρεάν» από το ίδιο dataset: πελάτες, gross, AOV,
	 * και per-product aggregation (qty/gross) για top sellers.
	 *
	 * Refunds αφαιρούνται από το gross (get_total_refunded), ώστε το
	 * νούμερο να είναι αυτό που τελικά πληρώθηκε στην περίοδο.
	 *
	 * @return array{count:int,gross:float,customers:int,aov:float,products:array<int,array{id:int,title:string,qty:int,gross:float}>}
	 */
	public static function completed( string $start, string $end ): array {

		return self::cached(
			'completed|' . $start . '|' . $end,
			static function () use ( $start, $end ): array {

				try {
					$tz = wp_timezone();

					$local_from = new DateTimeImmutable( $start . ' 00:00:00', $tz );
					$local_to   = new DateTimeImmutable( $end . ' 23:59:59', $tz );

					$created_from = $local_from
						->modify( '-' . self::LOOKBACK_COMPLETED )
						->format( 'Y-m-d' );
				} catch ( Exception $e ) {
					// Δεν θα φτάσουμε ποτέ εδώ με validated dates —
					// defensive: δεν γίνεται fatal, απλώς κενό dataset.
					return array(
						'count'     => 0,
						'gross'     => 0.0,
						'customers' => 0,
						'aov'       => 0.0,
						'products'  => array(),
					);
				}

				$orders = wc_get_orders(
					array(
						'limit'         => -1,
						'status'        => array( 'wc-completed' ),
						'type'          => 'shop_order',
						'date_created'  => self::utc_created_range( $created_from, $end ),
					)
				);

				$count     = 0;
				$gross     = 0.0;
				$customers = array();
				$per_prod  = array();

				foreach ( $orders as $order ) {
					/** @var WC_Order $order */

					$dc = $order->get_date_completed();

					/*
					 * Το φιλτράρισμα γίνεται σε PHP-level πάνω στο
					 * LOCAL date_completed — datastore-agnostic και
					 * πάντα σωστό (ίδια πειθαρχία με το per-item
					 * filtering του RS_Reports v1.1.2).
					 */
					if ( ! $dc instanceof WC_DateTime ) {
						continue;
					}

					$ts = $dc->getTimestamp();
					if ( $ts < $local_from->getTimestamp() || $ts > $local_to->getTimestamp() ) {
						continue;
					}

					$count++;

					/*
					 * Gross παραγγελίας = line items ΜΟΝΟ (item total +
					 * item tax) — μεταφορικά/fees ΔΕΝ μετρώνται, ίδια βάση
					 * με το Revenue Splitter και με το per-product gross
					 * παρακάτω. Από το αποτέλεσμα αφαιρείται το refunded.
					 */
					$order_items_gross = 0.0;
					foreach ( $order->get_items() as $item ) {
						/** @var WC_Order_Item_Product $item */
						if ( $item instanceof WC_Order_Item_Product ) {
							$order_items_gross += (float) $item->get_total() + (float) $item->get_total_tax();
						}
					}

					$refunded = (float) $order->get_total_refunded();
					$paid     = $order_items_gross - $refunded;
					$gross   += $paid;

					/*
					 * Refund ratio ανά παραγγελία: μοιράζουμε το refunded
					 * ποσό αναλογικά στα line items, ώστε το per-product
					 * gross να συμφωνεί με το order-level gross. Clamp στο
					 * 1.0 — το refunded μπορεί να περιλαμβάνει και return
					 * μεταφορικών από το Woo.
					 */
					$ratio = ( $order_items_gross > 0 && $refunded > 0 )
						? min( 1.0, $refunded / $order_items_gross )
						: 0.0;

					$cid = (int) $order->get_customer_id();
					if ( $cid > 0 ) {
						$customers[ 'u' . $cid ] = true;
					} else {
						$email = strtolower( trim( (string) $order->get_billing_email() ) );
						if ( '' !== $email ) {
							$customers[ 'e' . $email ] = true;
						}
					}

					foreach ( $order->get_items() as $item ) {
						/** @var WC_Order_Item_Product $item */

						if ( ! $item instanceof WC_Order_Item_Product ) {
							continue;
						}

						$pid = (int) $item->get_product_id();
						$qty = (int) $item->get_quantity();

						if ( $pid <= 0 || $qty <= 0 ) {
							continue;
						}

						$line = ( (float) $item->get_total() + (float) $item->get_total_tax() ) * ( 1.0 - $ratio );

						if ( ! isset( $per_prod[ $pid ] ) ) {
							$per_prod[ $pid ] = array(
								'id'    => $pid,
								'title' => '',
								'qty'   => 0,
								'gross' => 0.0,
							);
						}

						$per_prod[ $pid ]['qty']   += $qty;
						$per_prod[ $pid ]['gross'] += $line;
					}
				}

				// Top sellers (by qty, gross tiebreaker) — capped 10.
				usort(
					$per_prod,
					static function ( $a, $b ) {
						if ( $b['qty'] === $a['qty'] ) {
							return $b['gross'] <=> $a['gross'];
						}
						return $b['qty'] <=> $a['qty'];
					}
				);

				$products = array();
				foreach ( array_slice( $per_prod, 0, 10, true ) as $row ) {
					$title = get_the_title( $row['id'] );
					$row['title'] = $title ? (string) $title : '#' . $row['id'];
					$row['gross'] = round( $row['gross'], 2 );
					$products[] = $row;
				}

				return array(
					'count'     => $count,
					'gross'     => round( $gross, 2 ),
					'customers' => count( $customers ),
					'aov'       => $count > 0 ? round( $gross / $count, 2 ) : 0.0,
					'products'  => $products,
				);
			}
		);
	}

	/**
	 * Επιστροφές περιόδου: πλήθος refund objects + συνολικό ποσό.
	 *
	 * @return array{count:int,amount:float}
	 */
	public static function refunds( string $start, string $end ): array {

		return self::cached(
			'refunds|' . $start . '|' . $end,
			static function () use ( $start, $end ): array {

				$refunds = wc_get_orders(
					array(
						'limit'        => -1,
						'type'         => 'shop_order_refund',
						'status'       => 'any',
						'date_created' => self::utc_created_range( $start, $end ),
					)
				);

				$count  = 0;
				$amount = 0.0;

				foreach ( $refunds as $refund ) {
					/** @var WC_Order_Refund $refund */

					$count++;
					$amount += abs( (float) $refund->get_total() );
				}

				return array(
					'count'  => $count,
					'amount' => round( $amount, 2 ),
				);
			}
		);
	}

	/**
	 * Ακυρωμένες περιόδου (date_modified-based, με 180ήμερο pruning
	 * στο date_created — η ακύρωση δεν έχει δικό της timestamp στο Woo).
	 *
	 * @return array{count:int}
	 */
	public static function cancelled( string $start, string $end ): array {

		return self::cached(
			'cancelled|' . $start . '|' . $end,
			static function () use ( $start, $end ): array {

				try {
					$tz = wp_timezone();

					$local_from = new DateTimeImmutable( $start . ' 00:00:00', $tz );
					$local_to   = new DateTimeImmutable( $end . ' 23:59:59', $tz );

					$created_from = $local_from
						->modify( '-' . self::LOOKBACK_CANCELLED )
						->format( 'Y-m-d' );
				} catch ( Exception $e ) {
					return array( 'count' => 0 );
				}

				$orders = wc_get_orders(
					array(
						'limit'        => -1,
						'status'       => array( 'wc-cancelled' ),
						'type'         => 'shop_order',
						'date_created' => self::utc_created_range( $created_from, $end ),
					)
				);

				$count = 0;

				foreach ( $orders as $order ) {
					/** @var WC_Order $order */

					$dm = $order->get_date_modified();

					if ( ! $dm instanceof WC_DateTime ) {
						continue;
					}

					$ts = $dm->getTimestamp();
					if ( $ts < $local_from->getTimestamp() || $ts > $local_to->getTimestamp() ) {
						continue;
					}

					$count++;
				}

				return array( 'count' => $count );
			}
		);
	}

	/**
	 * Στοκ: low (0 < qty <= threshold) + out (qty <= 0 ή stock_status
	 * 'outofstock' χωρίς manage_stock).
	 *
	 * @return array{low:array{count:int,items:array<int,array{id:int,title:string,stock:int}>},out:array{count:int,items:array<int,array{id:int,title:string,stock:int}>}}
	 */
	public static function stock( int $threshold ): array {

		return self::cached(
			'stock|' . max( 0, $threshold ),
			static function () use ( $threshold ): array {

				$low = array( 'count' => 0, 'items' => array() );
				$out = array( 'count' => 0, 'items' => array() );

				$products = wc_get_products(
					array(
						'limit'  => -1,
						'status' => 'publish',
					)
				);

				foreach ( $products as $product ) {
					/** @var WC_Product $product */

					if ( ! $product instanceof WC_Product ) {
						continue;
					}

					$pid  = (int) $product->get_id();

					// Variations: δείχνουμε τον τίτλο του parent + suffix.
					if ( $product instanceof WC_Product_Variation ) {
						$parent_id = (int) $product->get_parent_id();
						$title = (string) get_the_title( $parent_id );
						$attr  = $product->get_attribute_summary(); // π.χ. "Χρώμα: κόκκινο"
						if ( '' !== $attr ) {
							$title .= ' — ' . $attr;
						}
					} else {
						$title = (string) get_the_title( $pid );
					}

					if ( '' === trim( $title ) ) {
						$title = '#' . $pid;
					}

					if ( $product->get_manage_stock() ) {
						$qty = (int) $product->get_stock_quantity();

						if ( $qty <= 0 ) {
							$out['count']++;
							$out['items'][] = array( 'id' => $pid, 'title' => $title, 'stock' => $qty );
						} elseif ( $qty <= $threshold ) {
							$low['count']++;
							$low['items'][] = array( 'id' => $pid, 'title' => $title, 'stock' => $qty );
						}
						continue;
					}

					// Χωρίς manage_stock: μόνο το stock_status έχει νόημα.
					if ( 'outofstock' === $product->get_stock_status() ) {
						$out['count']++;
						$out['items'][] = array( 'id' => $pid, 'title' => $title, 'stock' => 0 );
					}
				}

				// Sorting: low κατά stock ASC, out όπως έχει.
				usort(
					$low['items'],
					static function ( $a, $b ) {
						return $a['stock'] <=> $b['stock'];
					}
				);

				// Cap 50 — το panel δεν χρειάζεται τεράστιες λίστες.
				$low['items'] = array_slice( $low['items'], 0, 50, true );
				$out['items'] = array_slice( $out['items'], 0, 50, true );

				// Το count να συμφωνεί πάντα με τον ορατό πίνακα (cap 50).
				$low['count'] = count( $low['items'] );
				$out['count'] = count( $out['items'] );

				return array(
					'low' => $low,
					'out' => $out,
				);
			}
		);
	}

	/**
	 * Money cards — ΜΟΝΟ μέσω του Revenue Splitter.
	 *
	 * Επιστρέφει null όταν το RS δεν είναι ενεργό (caller δείχνει
	 * placeholder), αλλιώς:
	 *  - publisher_known: false όταν ο configured εκδότης δεν είναι
	 *    γνωστός δικαιούχος (κενό όνομα = δεν έχει επιλεγεί).
	 *  - share: το μερίδιο του εκδότη από πωλήσεις περιόδου.
	 *  - net: share + ledger income − payments του εκδότη (περίοδος).
	 *  - totals: gross/vat/net της περιόδου (line items ΜΟΝΟ — τα
	 *    μεταφορικά ΔΕΝ υπολογίζονται ποτέ).
	 *  - others: κάθε μη-εκδότης δικαιούχος → share + owed (= share
	 *    + ledger income − payments περιόδου).
	 *
	 * @return array|null
	 */
	public static function money( string $start, string $end, string $publisher ): ?array {

		if ( ! sp_rs_active() ) {
			return null;
		}

		$publisher = trim( $publisher );

		return self::cached(
			'money|' . $start . '|' . $end . '|' . md5( $publisher ),
			static function () use ( $start, $end, $publisher ): array {

				$report = RS_Reports::run(
					array(
						'date_start' => $start,
						'date_end'   => $end,
					)
				);

				$shares = array();
				foreach ( $report['beneficiaries'] as $b ) {
					$shares[ (string) $b['name'] ] = (float) $b['amount'];
				}

				$known = ( '' !== $publisher )
					&& in_array( $publisher, RS_Beneficiaries::collect_names(), true );

				// ---- Εκδότης ----
				$pub_share = $shares[ $publisher ] ?? 0.0;
				$pub_net   = $known
					? round(
						$pub_share
						+ RS_Ledger::sum( $publisher, $start, $end, 'income' )
						- RS_Ledger::sum( $publisher, $start, $end, 'payment' ),
						2
					)
					: 0.0;

				// ---- Οι υπόλοιποι ----
				$others      = array();
				$others_sum = 0.0;

				foreach ( $shares as $name => $share ) {
					if ( $name === $publisher ) {
						continue;
					}

					$owed = round(
						$share
						+ RS_Ledger::sum( $name, $start, $end, 'income' )
						- RS_Ledger::sum( $name, $start, $end, 'payment' ),
						2
					);

					$others_sum += $share;

					$others[] = array(
						'name'  => (string) $name,
						'share' => round( $share, 2 ),
						'owed'  => $owed,
					);
				}

				usort(
					$others,
					static function ( $a, $b ) {
						return $b['owed'] <=> $a['owed'];
					}
				);

				return array(
					'publisher_known' => $known,
					'share'           => round( $pub_share, 2 ),
					'net'             => $pub_net,
					'totals'          => array(
						'gross' => (float) $report['totals']['gross'],
						'vat'   => (float) $report['totals']['vat'],
						'net'   => (float) $report['totals']['net'],
					),
					'others'          => $others,
					'others_sum'      => round( $others_sum, 2 ),
				);
			}
		);
	}

	/* =====================================================================
	 * Helpers (φίλοι των admin renders — Parts 4/5)
	 * =================================================================== */

	/**
	 * Το stored preset ενός setting, με fallback σε default όταν το
	 * option είναι άγνωστο/σπασμένο (validate-then-serve — ποτέ fatal).
	 */
	public static function safe_preset( string $option_key, string $default ): string {

		$v = trim( (string) get_option( $option_key, $default ) );

		if ( ! in_array( $v, self::PRESETS, true ) ) {
			return $default;
		}

		return $v;
	}

	/** Το stored threshold (>= 0), με fallback 5. */
	public static function safe_threshold(): int {

		$v = get_option( 'sp_low_stock_threshold', '5' );

		if ( ! is_numeric( $v ) || (float) $v < 0 ) {
			return 5;
		}

		return (int) round( (float) $v );
	}

	/** Το stored όνομα εκδότη (sanitize_text_field, '' αν κενό). */
	public static function safe_publisher(): string {

		$v = get_option( 'sp_publisher', '' );

		return is_string( $v ) ? trim( sanitize_text_field( $v ) ) : '';
	}

	/** Τα stored quick-view cards (whitelist, default = όλα). */
	public static function safe_quick_cards(): array {

		$allowed = array( 'pending', 'old_pending', 'completed', 'low', 'out', 'publisher', 'others' );

		$v = get_option( 'sp_quick_cards', $allowed );

		// Λάθος τύπος (corrupted option) → default. Κενό array =
		// συνειδητή επιλογή «καμία κάρτα» → μένει κενό (το widget
		// κρύβεται από το SP_Admin::register_widget()).
		if ( ! is_array( $v ) ) {
			return $allowed;
		}

		return array_values( array_intersect( $allowed, array_map( 'strval', $v ) ) );
	}
}
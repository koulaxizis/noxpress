<?php
/**
 * Αναφορές: ερωτήματα πωλήσεων, αφαίρεση ΦΠΑ, καταμερισμός σε δικαιούχους.
 *
 * Στρατηγική:
 *  - Χρησιμοποιούμε το Order API του WooCommerce (HPOS-compatible).
 *  - Gross γραμμής = line total + line tax (ΦΠΑ-συμπεριλημτικό, ό,τι πλήρωσε ο πελάτης).
 *  - ΦΠΑ: αφαιρείται με βάση τον συντελεστή του Revenue Splitter,
 *    με τον τύπο «από μέσα»: vat = gross × rate / (100 + rate).
 *  - Refunds: αφαιρούνται ανά line item μέσω '_refunded_item_id'· τα
 *    amount-only refunds κατανέμονται αναλογικά (v1.7.0, collect_refunds).
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
 * Τώρα: τα local-day όρια (00:00:00 → 23:59:59 τοπική ώρα) μετατρέπονται
 * σε UTC timestamps πριν το query — το returned report αντιστοιχεί
 * ΠΑΝΤΑ στην περίοδο όπως τη βλέπει ο χρήστης.
 *
 * v1.3.5 FIX (#7): ΤΕΛΟΣ στο ψεύτικο «όλα σε έκπτωση».
 * Το undiscounted gross της γραμμής υπολογιζόταν με τον ΣΥΝΤΕΛΕΣΤΗ ΤΟΥ
 * PLUGIN (subtotal × (100+rate)/100). Όταν ο συντελεστής του plugin
 * αποκλίνει από τον πραγματικό Woo tax της γραμμής (ή σε γραμμές χωρίς
 * tax), το reg_gross φουσκώνει → disc_line > 0 → κάθε full-price γραμμή
 * μετριόταν ως «έκπτωση». Τώρα: undiscounted gross = get_subtotal() +
 * get_subtotal_tax() — ό,τι ΗΤΑΝ να πληρωθεί χωρίς έκπτωση, όπως το
 * κρατάει το WooCommerce (ground truth, ανεξαρτήτως tax settings /
 * price-incl-tax / συντελεστή plugin). Η αναλογία έκπτωσης και το
 * σταθμισμένο % βγαίνουν από πραγματικά δεδομένα, όχι από model.
 *
 * v1.3.5 (#8): Ανίχνευση κουπονιών — στις γραμμές με έκπτωση
 * συλλέγονται οι κωδικοί κουπονιών της παραγγελίας
 * (order->get_coupon_codes()) ανά προϊόν, σε πεδίο 'coupons' του
 * report (χρησιμοποιείται στο dashboard και στο portal).
 * Σημείωση: το Woo δεν χαρτογραφεί coupon→line item 1:1, οπότε σε
 * επίπεδο product aggregate: όποιο κουπόνι εφαρμόστηκε στην παραγγελία
 * που είχε γραμμή έκπτωσης του προϊόντος.
 *
 * v1.3.6 FIX (#1 bug του v1.3.5): Η κλήση ήταν $order->get_coupons() —
 * αυτό επιστρέφει OBJECTS WC_Order_Item_Coupon, όχι κωδικούς. Το
 * implode() του portal/CSV έπεφτε σε fatal με κάθε πραγματικό
 * κουπόνι. Τώρα: get_coupon_codes() (array of strings) — όπως έλεγε
 * εξαρχής το docblock του #8. Καθαρίστηκαν επίσης τα διαστραμμένα
 * σχόλια του v1.3.5 (#8).
 *
 * v1.3.6 (#9 — rs_sales_since): Νέο option «Έναρξη καταγραφής
 * πωλήσεων». Όταν είναι ορισμένο (έγκυρη ημερομηνία), το run()
 * clamp-άρει ΚΑΘΕ περίοδο: ό,τι είναι πριν την ημερομηνία έναρξης αγνοείται
 * πλήρως (πωλήσεις, μερίδια, lifetime balance). Αν ολόκληρη η
 * περίοδος είναι πριν την ημερομηνία έναρξης → άδειο report χωρίς
 * καν query στη βάση. Το lifetime_beneficiaries() καλύπτεται αυτόματα
 * (περνά '2000-01-01' → clamp στο sales_since). Το admin UI (Part 3)
 * σώζει το option + κάνει invalidate cache.
 *
 * Αποτελέσματα cached σε transient (5 λεπτά) με hash στα args.
 *
 * v1.3.7 FIX: Native «Τιμή προσφοράς» (Woo Sale Price, χωρίς κουπόνι)
 * μετρούσε ως «Πλήρης» — το Woo αποθηκεύει το sale price ΩΣ subtotal.
 * Νέο native_sale_discount(): paid-unit vs regular-unit incl tax →
 * σωστή κατηγοριοποίηση + σταθμισμένο % έκπτωσης. Τιμές με κουπόνια
 * συνεχίζουν μέσω subtotal/total (αλλαγή βάσης % μόνο για native).
 */

defined( 'ABSPATH' ) || exit;

class RS_Reports {

	const CACHE_TTL = 300; // 5 λεπτά.

	const STATUSES = array( 'wc-completed', 'wc-processing' );

	public static function init(): void {
		add_action( 'rs_invalidate_cache', array( __CLASS__, 'flush_cache' ) );

		/*
		 * v1.7.0: αλλαγή κατάστασης παραγγελίας ή επιστροφή χρημάτων →
		 * invalidate, ώστε reports/portal/Store Pulse να μη δείχνουν
		 * παλιά νούμερα για έως 5 λεπτά. Μέσω του κοινού action, ώστε
		 * να ενημερώνονται και όσοι ακούνε (π.χ. Store Pulse).
		 */
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_change' ) );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_order_change' ) );
		add_action( 'woocommerce_refund_deleted', array( __CLASS__, 'on_order_change' ) );
	}

	/** v1.7.0: hook callback για αλλαγές παραγγελιών (βλ. init). */
	public static function on_order_change(): void {
		do_action( 'rs_invalidate_cache' );
	}

	public static function flush_cache(): void {
		global $wpdb;

		/*
		 * Διαγραφή όλων των report transients. FIX #2: το pattern έλειπε
		 * το αρχικό '_' — τα transients αποθηκεύονται ως option names
		 * '_transient_rs_report_…' / '_transient_timeout_rs_report_…',
		 * οπότε τα παλιά LIKE 'transient_…' (χωρίς underscore) δεν
		 * ταίριαζαν με ΚΑΜΙΑ γραμμή. Τα underscores του pattern είναι
		 * single-char wildcards στο MySQL LIKE, οπότε ταιριάζουν και
		 * τον εαυτό τους. Με external object cache τα transients δεν
		 * ζουν στο options table — καλύπτεται από το version bump.
		 */
		// v1.7.0: LIKE escaped (esc_like) — τα '_' είναι literal.
		foreach ( array( '_transient_rs_report_', '_transient_timeout_rs_report_' ) as $prefix ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
		}
		update_option( 'rs_cache_version', (string) time() );
	}

	/* ---------------------------------------------------------------------
	 * Public API
	 * ------------------------------------------------------------------- */

	/**
	 * v1.3.6 (#9): Η ενεργή ημερομηνία έναρξης καταγραφής πωλήσεων.
	 *
	 * Διαβάζει το option 'rs_sales_since' και το VALIDATE-άρει αυστηρά:
	 * επιστρέφει '' (χωρίς clamp) όταν δεν έχει οριστεί ή όταν η τιμή
	 * είναι άκυρη — ένα παραμορφωμένο option ποτέ δεν κλειδώνει τα
	 * reports.
	 *
	 * @return string 'Y-m-d' ή '' όταν δεν υπάρχει όριο.
	 */
	public static function sales_since(): string {

		$v = trim( (string) get_option( 'rs_sales_since', '' ) );

		if ( '' === $v ) {
			return '';
		}

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) ) {
			return '';
		}

		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}

		return $v;
	}

	/**
	 * Κύρια αναφορά.
	 *
	 * v1.3.6 (#9): Η περίοδος clamp-άρει στο rs_sales_since ΠΡΙΝ το
	 * cache key — έτσι (α) όλοι οι callers (dashboard, portal, CLI,
	 * lifetime) λαμβάνουν το ίδιο clamp χωρίς να το ξέρουν, (β) το
	 * cache συσσωρεύει μεγαλύτερα hits (περίοδοι που clamp-άρονται στην
	 * ίδια effective ημερομηνία μοιράζονται cache entry), (γ) το
	 * επιστρεφόμενο 'period' στο report αντανακλά τις ΕΝΕΡΓΕΣ (clamped)
	 * ημερομηνίες, όχι τις ζητημένες.
	 *
	 * @param array $args {
	 *     @type string $date_start  ISO date 'Y-m-d' (inclusive, LOCAL site time).
	 *     @type string $date_end    ISO date 'Y-m-d' (inclusive, LOCAL site time).
	 *     @type int[]  $product_ids Προαιρετικό φίλτρο σε συγκεκριμένα IDs.
	 * }
	 * @return array
	 */
	public static function run( array $args = array() ): array {

		$now = new DateTimeImmutable( 'now', wp_timezone() );

		$defaults = array(
			'date_start'  => $now->modify( '-29 days' )->format( 'Y-m-d' ),
			'date_end'    => $now->format( 'Y-m-d' ),
			'product_ids' => array(),
		);
		$args = wp_parse_args( $args, $defaults );

		// ---- v1.3.6 (#9): clamp από την ημερομηνία έναρξης ----
		$since = self::sales_since();

		if ( '' !== $since ) {

			if ( $args['date_start'] < $since ) {
				$args['date_start'] = $since;
			}

			// Ολόκληρη η περίοδος πριν την έναρξη → άδειο report,
			// χωρίς καν query στη βάση (ταχύτητα + σαφήνεια).
			if ( $args['date_start'] > $args['date_end'] ) {
				return self::empty_report( $args['date_start'], $args['date_end'] );
			}
		}
		// --------------------------------------------------------

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
	 * (dashboard, portal, CLI). v1.3.6 (#9): clamp-άρει αυτόματα στο
	 * rs_sales_since — το «all-time» σημαίνει «ό,τι μετράει από την
	 * έναρξη καταγραφής».
	 *
	 * @return float[] name => amount (rounded, 2 decimals).
	 */
	public static function lifetime_beneficiaries(): array {

		$now = new DateTimeImmutable( 'now', wp_timezone() );

		$report = self::run(
			array(
				'date_start' => '2000-01-01', // Πρακτικά «αρχή των χρόνων» (clamp-άρει στο sales_since αν υπάρχει).
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

	/**
	 * v1.3.6 (#9): Canonical ΔΟΜΗ άδειου report — ίδιο σχήμα με το
	 * compute(), ώστε οι callers να μη χρειάζονται κανένα ειδικό path.
	 */
	private static function empty_report( string $start, string $end ): array {
		return array(
			'period'        => array(
				'start' => $start,
				'end'   => $end,
			),
			'products'      => array(),
			'totals'        => array(
				'gross' => 0.0,
				'vat'   => 0.0,
				'net'   => 0.0,
			),
			'beneficiaries' => array(),
			'channels'      => array(), // v1.3.8 (#7 στάδιο 3).
			'warnings'      => array(),
			'order_count'   => 0,
		);
	}

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
		$per_channel    = array(); // v1.3.8 (#7 στάδιο 3): κανάλι => {qty, free, gross, vat, net}.

		foreach ( $orders as $order ) {
			/** @var WC_Order $order */

			$refunded_by_item = self::collect_refunds( $order );

			/*
			 * v1.3.6 FIX (#1): get_coupon_codes() — array of STRINGS.
			 * Η get_coupons() του v1.3.5 (#8) επέστρεφε WC_Order_Item_Coupon
			 * objects και το implode() του portal/CSV έπεφτε σε fatal
			 * («Object of class WC_Order_Item_Coupon could not be converted
			 * to string») με ΚΑΘΕ πραγματικό κουπόνι.
			 */
			$order_coupons = $order->get_coupon_codes();

			/*
			 * v1.3.8 (#7 στάδιο 3): κανάλι της παραγγελίας — order meta
			 * '_rs_channel' (από το checkout με κουπόνι), διαφορετικά το
			 * default pseudo-κανάλι «Κατάστημα/Online» (Ρυθμίσεις).
			 * Read-time ΔΕΝ γίνεται whitelist validation — η λίστα μπορεί
			 * να έχει αλλάξει από την αγορά· εμφανίζεται ό,τι αποθηκεύτηκε.
			 */
			$order_channel = $order->get_meta( RS_Checkout::CHANNEL_META );
			if ( ! is_string( $order_channel ) || '' === $order_channel ) {
				$order_channel = RS_Admin_UI::default_channel();
			}
			$order_channel = sanitize_text_field( $order_channel );
			if ( '' === $order_channel ) {
				$order_channel = RS_Admin_UI::default_channel(); // Αμυντικά (ποτέ κενό key).
			}

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

				// Gross γραμμής (ΦΠΑ-συμπεριλημτικό), μετά refunds.
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
						'coupons'  => array(), // v1.3.5 (#8): κωδικοί κουπονιών (strings).
						'sale_est' => false, // Audit (#2): η native έκπτωση εκτιμήθηκε από τρέχοντα τιμοκατάλογο (χωρίς stamped τιμή).
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

						// v1.3.8 (#7 στάδιο 3): δωρεάν αντίτυπα μετριούνται
						// στο κανάλι της παραγγελίας (τεμάχια, χωρίς έσοδα).
						if ( ! isset( $per_channel[ $order_channel ] ) ) {
							$per_channel[ $order_channel ] = array( 'qty' => 0, 'free' => 0, 'gross' => 0.0, 'vat' => 0.0, 'net' => 0.0 );
						}
						$per_channel[ $order_channel ]['free'] += $qty;

						// v1.3.5 (#8): 100%-κουπόνι = αναφορά κουπονιού & στις
						// δωρεάν γραμμές (αλλιώς χάνεται από τα stats).
						if ( ! empty( $order_coupons ) ) {
							$per_product[ $pid ]['coupons'] = array_unique(
								array_merge( $per_product[ $pid ]['coupons'], $order_coupons )
							);
						}
					}
					continue;
				}

				$matched_orders[ $order->get_id() ] = true;

				/*
				 * v1.3.5 FIX (#7): undiscounted gross της γραμμής — από τα
				 * ίδια τα WooCommerce δεδομένα (subtotal + subtotal tax =
				 * ό,τι ΗΤΑΝ να πληρωθεί χωρίς κουπόνι, όπως το τιμολόγησε
				 * το shop), ΟΧΙ από ανακατασκευή με τον συντελεστή του
				 * plugin. Παλιά: subtotal × (100+plugin_rate)/100 — κάθε
				 * απόκλιση συντελεστή ή tax-free γραμμή έκανε τα full-price
				 * items να εμφανίζονται ως «έκπτωση».
				 */
				$reg_gross = (float) $item->get_subtotal() + (float) $item->get_subtotal_tax();

				// Έκπτωση ΑΠΟ ΚΟΥΠΟΝΙ: subtotal > total.
				$disc_line = max( 0.0, $reg_gross - $gross );

				// Παρονομαστής του σταθμισμένου % (κουπόνια: pre-coupon gross).
				$disc_base = $reg_gross;

				/*
				 * v1.3.7 FIX: Native «Τιμή προσφοράς» (Woo Sale Price, χωρίς
				 * κουπόνι). Το Woo αποθηκεύει το sale price ΩΣ subtotal —
				 * subtotal == total → disc_line = 0 → η γραμμή μετρούσε ως
				 * «Πλήρης». Ανίχνευση: paid-unit vs regular-price unit
				 * (ΦΠΑ-συμπεριληπτικά).
				 */
				if ( $disc_line <= 0.01 ) {
					$native = self::native_sale_discount( $item, $reg_gross, $qty );
					if ( null !== $native ) {
						$disc_line = $native['amount'];
						$disc_base = $native['base'];

						// Audit (#2): εκτίμηση από τρέχοντα τιμοκατάλογο — αν η
						// τιμή έχει αλλάξει από την παραγγελία, το % δεν είναι
						// ground truth. Μαρκάρεται και εμφανίζεται στο dashboard.
						if ( empty( $native['stamped'] ) ) {
							$per_product[ $pid ]['sale_est'] = true;
						}
					}
				}

				$per_product[ $pid ]['qty'] += $qty;

				if ( $disc_line > 0.01 ) {
					$per_product[ $pid ]['qty_disc'] += $qty;
					$per_product[ $pid ]['disc_w']   += ( $disc_line / $disc_base ) * 100.0 * $qty;
					$per_product[ $pid ]['disc_amt'] += $disc_line;

					// v1.3.5 (#8): κωδικοί κουπονιών της παραγγελίας —
					// ΜΟΝΟ όταν πράγματι υπάρχουν κουπόνια (native sale
					// χωρίς κουπόνι ΔΕΝ προσθέτει τίποτα εδώ).
					if ( ! empty( $order_coupons ) ) {
						$per_product[ $pid ]['coupons'] = array_unique(
							array_merge( $per_product[ $pid ]['coupons'], $order_coupons )
						);
					}
				} else {
					$per_product[ $pid ]['qty_full'] += $qty;
				}

				/*
				 * Ο gross είναι ΦΠΑ-συμπεριλημτικός → εξάγουμε τον ΦΠΑ «από μέσα»:
				 *   vat  = gross × rate / (100 + rate)
				 *   base = gross − vat
				 */
				$vat  = $net_gross * ( $rate / ( 100.0 + $rate ) );
				$base = $net_gross - $vat;

				$per_product[ $pid ]['gross'] += $net_gross;
				$per_product[ $pid ]['vat']   += $vat;
				$per_product[ $pid ]['net']   += $base;

				// v1.3.8 (#7 στάδιο 3): συσσώρευση ανά κανάλι — ίδιο φίλτρο
				// περιόδου/προϊόντων με τον πίνακα «Ανά προϊόν» (consistency).
				if ( ! isset( $per_channel[ $order_channel ] ) ) {
					$per_channel[ $order_channel ] = array( 'qty' => 0, 'free' => 0, 'gross' => 0.0, 'vat' => 0.0, 'net' => 0.0 );
				}
				$per_channel[ $order_channel ]['qty']   += $qty;
				$per_channel[ $order_channel ]['gross'] += $net_gross;
				$per_channel[ $order_channel ]['vat']   += $vat;
				$per_channel[ $order_channel ]['net']   += $base;

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
				'sale_est'    => (bool) $acc['sale_est'], // FIX #1: emit — παλιά χανόταν στο accumulator (dead flag).
				'qty_full'    => $acc['qty_full'],
				'qty_disc'    => $acc['qty_disc'],
				'qty_free'    => $acc['qty_free'],
				'disc_pct'    => $acc['qty_disc'] > 0 ? round( $acc['disc_w'] / $acc['qty_disc'], 1 ) : 0.0,
				'coupons'     => array_values( array_unique( $acc['coupons'] ) ), // v1.3.5 (#8) — strings.
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

		// v1.3.8 (#7 στάδιο 3): τελικός πίνακας ανά κανάλι (Μικτό ↓).
		$channels_out = array();
		foreach ( $per_channel as $ch_name => $ch_acc ) {
			$channels_out[] = array(
				'channel' => (string) $ch_name,
				'qty'     => (int) $ch_acc['qty'],
				'free'    => (int) $ch_acc['free'],
				'gross'   => round( (float) $ch_acc['gross'], 2 ),
				'vat'     => round( (float) $ch_acc['vat'], 2 ),
				'net'     => round( (float) $ch_acc['net'], 2 ),
			);
		}
		usort(
			$channels_out,
			static function ( $a, $b ) {
				return $b['gross'] <=> $a['gross'];
			}
		);

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
			'channels'      => $channels_out, // v1.3.8 (#7 στάδιο 3).
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

		/*
		 * Audit fix: η παλιά μοίρασμα (array_slice 0..diff) κάλυπτε μόνο
		 * |diff| ≤ n — δηλαδή ONLY το «καθαρό» flooring remainder. Όταν τα
		 * ποσοστά δεν αθροίζουν ακριβώς 100 (sanitize_list ανοχή ±0.05%)
		 * ή σε μεγάλα ποσά με float drift, το |diff| ξεπερνά τους n
		 * δικαιούχους και τα υπόλοιπα cents ΧΑΘΟΝΤΑΝ (Σ splits ≠ net).
		 *
		 * Νέο σχήμα: 1 cent ανά γύρο, με επανεκλογή του μεγαλύτερου/
		 * μικρότερου υπολειπόμενου ΚΑΘΕ φορά — σωστό για κάθε |diff|,
		 * με max απόκλιση 1 cent ανά δικαιούχο από το «δίκαιο» ποσό.
		 */
		while ( $diff > 0 ) {
			arsort( $frac );
			$top = array_key_first( $frac );

			$cents[ $top ]++;
			$frac[ $top ] -= 1.0; // Το κλάσμα ξοδεύτηκε — ο επόμενος γύρος διαλέγει τον επόμενο.
			$diff--;
		}

		while ( $diff < 0 ) {
			asort( $frac );
			$low = array_key_first( $frac );

			$cents[ $low ]--;
			$frac[ $low ] += 1.0;
			$diff++;
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
	 * v1.3.7: Ανίχνευση native έκπτωσης (Τιμή προσφοράς στο General tab).
	 *
	 * Ιεραρχία πηγών για το regular unit price (ΦΠΑ-συμπεριλημτικό):
	 *  1. Line item meta '_rs_reg_unit' — stamped στο checkout από το
	 *     RS_Checkout::stamp_regular_prices(). Ground truth ΚΑΤΑ ΤΗΝ
	 *     ΑΓΟΡΑ: ανεπηρέαστο από μελλοντικές αλλαγές τιμοκαταλόγου.
	 *  2. Fallback: τρέχον regular price του προϊόντος — καλύπτει
	 *     παραγγελίες πριν το v1.3.7 και χειροκίνητες παραγγελίες admin.
	 *
	 * Επιστρέφει null όταν δεν ανιχνεύεται έκπτωση.
	 *
	 * @param WC_Order_Item_Product $item                  Line item.
	 * @param float                 $paid_pre_coupon_gross Subtotal + subtotal tax (πριν κουπόνια).
	 * @param int                   $qty                   Τεμάχια γραμμής (> 0).
	 * @return array|null {base: float, amount: float}|null
	 */
	private static function native_sale_discount( WC_Order_Item_Product $item, float $paid_pre_coupon_gross, int $qty ): ?array {

		$regular_unit = null;
		$stamped      = false;

		// ---- Πηγή 1: stamped τιμή της στιγμής της αγοράς ----
		$stamp = $item->get_meta( '_rs_reg_unit' );
		if ( is_scalar( $stamp ) && is_numeric( (string) $stamp ) && (float) $stamp > 0.0 ) {
			$regular_unit = (float) $stamp;
			$stamped      = true;
		}

		// ---- Πηγή 2: τρέχον regular price (fallback) ----
		if ( null === $regular_unit ) {
			$product = $item->get_product();
			if ( $product instanceof WC_Product ) {
				$regular = (float) $product->get_regular_price();
				if ( $regular > 0.0 ) {
					$unit = (float) wc_get_price_including_tax( $product, array( 'price' => $regular ) );
					if ( $unit > 0.0 ) {
						$regular_unit = $unit;
					}
				}
			}
		}

		if ( null === $regular_unit ) {
			return null; // Διαγραμμένο/άγνωστο προϊόν ή άγνωστη τιμή.
		}

		$full_gross = $regular_unit * $qty;
		$amount     = $full_gross - $paid_pre_coupon_gross;

		if ( $amount <= 0.01 ) {
			return null; // Πλήρης τιμή (ή αμελητέα rounding).
		}

		return array(
			'base'    => $full_gross,
			'amount'  => $amount,
			'stamped' => $stamped,
		);
	}

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
	 * v1.7.0: Refunds ΧΩΡΙΣ line items (ποσό μόνο — π.χ. «Refund 20€»
	 * χωρίς ποσότητες, ή αυτόματο refund μέσω gateway) αγνοούνταν
	 * πλήρως → τα έσοδα/μερίδια έμεναν φουσκωμένα. Προσέγγιση:
	 *  1. Το itemized μέρος κάθε refund (line items + shipping + fees)
	 *     αφαιρείται όπως πριν, ανά line item (_refunded_item_id).
	 *  2. Το ΥΠΟΛΟΙΠΟ (refund amount − itemized) θεωρείται refund του
	 *     ΑΚΟΜΗ μη επιστραμμένου μέρους της παραγγελίας και κατανέμεται
	 *     ΑΝΑΛΟΓΙΚΑ: ratio = υπόλοιπο / (order total − ήδη itemized), και
	 *     κάθε product line χάνει ratio × (gross της − ό,τι έχει ήδη
	 *     επιστραφεί). Έτσι το κομμάτι που αναλογεί σε μεταφορικά/fees ΔΕΝ
	 *     χρεώνεται στα προϊόντα, και ένα amount-only refund όλου του
	 *     υπολοίπου μηδενίζει ακριβώς τις γραμμές.
	 *  3. Cap: ratio ≤ 1 — καμία γραμμή δεν «επιστρέφει» περισσότερα από το gross της.
	 *
	 * @return float[] original_item_id => refunded amount (θετικό).
	 */
	private static function collect_refunds( WC_Order $order ): array {

		$refunded       = array();
		$unassigned     = 0.0; // Amount-only υπόλοιπα όλων των refunds.
		$itemized_total = 0.0; // Itemized ποσά όλων των refunds.

		foreach ( $order->get_refunds() as $refund ) {
			/** @var WC_Order_Refund $refund */

			$itemized = 0.0;

			foreach ( $refund->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $ref_item ) {
				/** @var WC_Order_Item $ref_item */

				$amount = abs( (float) $ref_item->get_total() + (float) $ref_item->get_total_tax() );
				$itemized += $amount;

				if ( ! $ref_item instanceof WC_Order_Item_Product ) {
					continue; // Shipping/fee: μετράει στο itemized, όχι σε προϊόν.
				}

				$orig = (int) $ref_item->get_meta( '_refunded_item_id' );
				if ( $orig <= 0 ) {
					continue;
				}

				if ( $amount > 0 ) {
					$refunded[ $orig ] = ( $refunded[ $orig ] ?? 0.0 ) + $amount;
				}
			}

			$itemized_total += $itemized;

			$rest = abs( (float) $refund->get_amount() ) - $itemized;
			if ( $rest > 0.005 ) {
				$unassigned += $rest;
			}
		}

		if ( $unassigned > 0.005 ) {

			$remaining = (float) $order->get_total() - $itemized_total;

			if ( $remaining > 0.005 ) {
				$ratio = min( 1.0, $unassigned / $remaining );

				foreach ( $order->get_items() as $item_id => $item ) {
					if ( ! $item instanceof WC_Order_Item_Product ) {
						continue;
					}

					$gross   = (float) $item->get_total() + (float) $item->get_total_tax();
					$already = $refunded[ $item_id ] ?? 0.0;
					$left    = max( 0.0, $gross - $already );

					if ( $left <= 0.0 ) {
						continue;
					}

					$refunded[ $item_id ] = $already + $left * $ratio;
				}
			}
		}

		return $refunded;
	}
}
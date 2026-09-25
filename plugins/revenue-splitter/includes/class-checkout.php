<?php
/**
 * RS_Checkout — Υποχρεωτική αιτιολογία δωρεάν αντιτύπων (v1.3.0, #3).
 *
 * Ενεργοποιείται ΜΟΝΟ όταν στο καλάθι είναι εφαρμοσμένο κάποιο από τα
 * κουπόνια που έχουν οριστεί στις Ρυθμίσεις (option 'rs_reason_coupons').
 * Κανονικά checkout χωρίς αυτά τα κουπόνια: το πεδίο δεν υπάρχει καν.
 *
 * Η αιτιολογία αποθηκεύεται ως order meta (_rs_free_reason) και
 * εμφανίζεται στη σελίδα παραγγελίας στο admin.
 */

defined( 'ABSPATH' ) || exit;

final class RS_Checkout {

	const META = '_rs_free_reason';

	/**
	 * v1.3.8 (#7): Κανάλι πώλησης στο checkout (όταν εφαρμόζεται κουπόνι
	 * της whitelist) — αποθηκεύεται στο order meta ως '_rs_channel'.
	 */
	const CHANNEL_META = '_rs_channel';

	public static function init(): void {
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'add_field' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'stamp_regular_prices' ), 5, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'show_admin' ), 10, 1 );

		// Audit (#2): detection του WooCommerce Blocks checkout — το πεδίο
		// καναλιού/αιτιολογίας απαιτεί classic checkout (hooks). Αν η σελίδα
		// checkout χρησιμοποιεί block, εμφανίζουμε warning στους admins.
		add_action( 'admin_init', array( __CLASS__, 'maybe_warn_blocks_checkout' ) );
	}

	/**
	 * Ελέγχει αν η σελίδα checkout χρησιμοποιεί το blocks plugin → warning.
	 */
	public static function maybe_warn_blocks_checkout(): void {

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// static cache per-request.
		static $checked_today = false;
		if ( $checked_today ) {
			return;
		}
		$checked_today = true;

		$flag = 'rs_block_checkout_warned_' . get_current_user_id();

		// Βρες τη σελίδα checkout.
		$checkout_id = absint( get_option( 'woocommerce_checkout_page_id' ) );
		if ( ! $checkout_id ) {
			return;
		}

		$post = get_post( $checkout_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		// Έλεγχος αν περιέχει το block.
		$has_blocks = function_exists( 'has_block' )
			? has_block( 'woocommerce/checkout', $post->post_content )
			: false;

		if ( ! $has_blocks ) {
			delete_transient( $flag ); // Classic checkout — καθαρίζουμε τυχόν stale flag.
			return;
		}

		if ( get_transient( $flag ) ) {
			return; // Ήδη ειδοποιημένος μέσα στη μέρα.
		}

		// Warning για blocks checkout.
		add_action( 'admin_notices', function () use ( $flag ) {

			// Mark ως δείξαμε (session-based, δεν θα ξαναδείξει σήμερα).
			set_transient( $flag, true, DAY_IN_SECONDS );
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Revenue Splitter:', 'revenue-splitter' ); ?></strong>
					<?php
					printf(
						/* translators: 1: opening <a>, 2: closing </a> */
						esc_html__( 'Η σελίδα checkout χρησιμοποιεί το WooCommerce Blocks. Το πεδίο «Κανάλι πώλησης» θα εμφανίζεται ΜΟΝΟ σε classic checkout. %1$sΔιάβασε το επίσημο άρθρο%2$s για συμβατότητα ή επιστρέψε στο classic checkout.', 'revenue-splitter' ),
						'<a href="https://docs.woocommerce.com/document/woocommerce-blocks/" target="_blank" rel="noopener noreferrer">',
						'</a>'
					);
					?>
				</p>
			</div>
			<?php
		} );
	}

	/**
	 * Έχει εφαρμοστεί κάποιο από τα reason-coupons στο καλάθι;
	 * Σύγκριση case-insensitive (Woo κρατά τους κωδικούς όπως είναι).
	 */
	private static function triggered(): bool {

		if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof WC_Cart ) {
			return false;
		}

		$config = (string) get_option( 'rs_reason_coupons', '' );
		if ( '' === $config ) {
			return false;
		}

		$wanted = array_filter( array_map( 'trim', explode( ',', strtolower( $config ) ) ) );
		if ( empty( $wanted ) ) {
			return false;
		}

		$applied = array_map(
			static function ( $code ) {
				return strtolower( (string) $code );
			},
			WC()->cart->get_applied_coupons()
		);

		foreach ( $applied as $code ) {
			if ( in_array( $code, $wanted, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Προσθήκη πεδίου στο checkout (μόνο όταν ενεργό).
	 *
	 * v1.3.8 (#7): αν υπάρχουν ορισμένα κανάλια (Ρυθμίσεις → Κανάλια
	 * πώλησης), το πεδίο γίνεται dropdown επιλογής καναλιού από τη λίστα
	 * — αντί ελεύθερης αιτιολογίας. Διαφορετικά (κενή λίστα): legacy
	 * textarea ως قبل — καμία κλείδωμα της φόρμας.
	 */
	public static function add_field( array $fields ): array {

		if ( ! self::triggered() ) {
			return $fields;
		}

		$channels = RS_Admin_UI::get_channels();

		if ( ! empty( $channels ) ) {

			$options = array( '' => __( '— Επιλογή καναλιού —', 'revenue-splitter' ) );
			foreach ( $channels as $ch ) {
				$options[ $ch ] = $ch;
			}

			$fields['order']['rs_channel'] = array(
				'label'    => __( 'Κανάλι πώλησης', 'revenue-splitter' ),
				'type'     => 'select',
				'required' => true,
				'options'  => $options,
				'class'    => array( 'rs-checkout-channel' ),
			);

		} else {

			// Legacy: κανένα κανάλι ορισμένο → ελεύθερη αιτιολογία ως πριν.
			$fields['order']['rs_free_reason'] = array(
				'label'       => __( 'Αιτιολογία δωρεάν αντιτύπου', 'revenue-splitter' ),
				'type'        => 'textarea',
				'required'    => true,
				'placeholder' => __( 'π.χ. δώρο, διαγωνισμός, κριτική βιβλίου…', 'revenue-splitter' ),
			);
		}

		return $fields;
	}

	/**
	 * Server-side validation — το required του πεδίου δεν αρκεί μόνο του.
	 *
	 * v1.3.8 (#7): σε dropdown mode η τιμή ελέγχεται ΚΑΙ κατά whitelist
	 * (πάντα από server-side source — ποτέ trust στο rendered HTML).
	 */
	public static function validate(): void {

		if ( ! self::triggered() ) {
			return;
		}

		$channels = RS_Admin_UI::get_channels();

		if ( ! empty( $channels ) ) {

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- το Woo τρέχει το δικό του nonce στο checkout.
			$ch = isset( $_POST['rs_channel'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_channel'] ) ) : '';

			if ( '' === $ch ) {
				wc_add_notice(
					__( 'Παρακαλώ επίλεξε κανάλι πώλησης.', 'revenue-splitter' ),
					'error'
				);
			} elseif ( ! in_array( $ch, $channels, true ) ) {
				wc_add_notice(
					__( 'Μη έγκυρο κανάλι πώλησης.', 'revenue-splitter' ),
					'error'
				);
			}

			return;
		}

		// Legacy textarea mode.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- το Woo τρέχει το δικό του nonce στο checkout.
		// Audit fix: trim πριν το empty — whitespace-only string περνούσε το
		// validate και χανόταν σιωπηλά στο save (sanitize → '').
		$reason = isset( $_POST['rs_free_reason'] ) ? trim( (string) wp_unslash( $_POST['rs_free_reason'] ) ) : '';

		if ( '' === $reason ) {
			wc_add_notice(
				__( 'Παρακαλώ συμπλήρωσε την αιτιολογία δωρεάν αντιτύπου.', 'revenue-splitter' ),
				'error'
			);
		}
	}

	/**
	 * Αποθήκευση στο order meta.
	 *
	 * v1.3.8 (#7): dropdown mode → '_rs_channel' (whitelist-validated).
	 * Legacy textarea mode → '_rs_free_reason' ως πριν.
	 */
	public static function save( WC_Order $order, array $data ): void {

		if ( ! self::triggered() ) {
			return;
		}

		$channels = RS_Admin_UI::get_channels();

		if ( ! empty( $channels ) ) {

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo nonce στο checkout.
			$ch = isset( $_POST['rs_channel'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_channel'] ) ) : '';

			// Defend-in-depth: και εδώ whitelist (πέραν του validate()).
			if ( '' !== $ch && in_array( $ch, $channels, true ) ) {
				$order->update_meta_data( self::CHANNEL_META, $ch );
			}

			return;
		}

		// Legacy textarea mode.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo nonce στο checkout.
		$reason = isset( $_POST['rs_free_reason'] )
			? sanitize_textarea_field( wp_unslash( $_POST['rs_free_reason'] ) )
			: '';

		if ( '' !== $reason ) {
			$reason = mb_substr( $reason, 0, 500 ); // Όριο 500 chars.
			$order->update_meta_data( self::META, $reason );
		}
	}

	/** Προβολή στη σελίδα παραγγελίας (admin) — κανάλι (v1.3.8) + legacy αιτιολογία. */
	public static function show_admin( WC_Order $order ): void {

		$channel = $order->get_meta( self::CHANNEL_META );
		$reason  = $order->get_meta( self::META );

		if ( is_string( $channel ) && '' !== $channel ) {
			echo '<p style="margin:8px 0;"><strong>'
				. esc_html__( 'Κανάλι πώλησης:', 'revenue-splitter' )
				. '</strong> ' . esc_html( $channel ) . '</p>';
		}

		if ( is_string( $reason ) && '' !== $reason ) {
			echo '<p style="margin:8px 0;"><strong>'
				. esc_html__( 'Αιτιολογία δωρεάν αντιτύπου:', 'revenue-splitter' )
				. '</strong> ' . esc_html( $reason ) . '</p>';
		}
	}

	/**
	 * v1.3.7: Σφραγίζει το regular unit price (incl. tax) σε κάθε line item.
	 *
	 * Τρέχει στο woocommerce_checkout_create_order — το Woo αποθηκεύει τα
	 * item meta αμέσως μετά. Έτσι το RS_Reports δεν εξαρτάται ποτέ πια από
	 * το ΤΡΕΧΟΝ regular price του προϊόντος για παλιές πωλήσεις.
	 *
	 * Variations: κρατάμε την τιμή του variation (όπως και το subtotal της
	 * γραμμής) — συμβατό με το resolve_product_id() που κάνει roll-up
	 * στο parent ΜΟΝΟ για την αναφορά, όχι για τιμές.
	 */
	public static function stamp_regular_prices( WC_Order $order, array $data ): void {

		foreach ( $order->get_items() as $item ) {

			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$regular = (float) $product->get_regular_price();
			if ( $regular <= 0.0 ) {
				continue;
			}

			$unit = (float) wc_get_price_including_tax( $product, array( 'price' => $regular ) );
			if ( $unit > 0.0 ) {
				$item->update_meta_data( '_rs_reg_unit', (string) $unit );
			}
		}
	}
}
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

	public static function init(): void {
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'add_field' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'show_admin' ), 10, 1 );
		// v1.3.7: regular unit price (ΦΠΑ-συμπεριλημτικό) stamped στο κάθε
		// line item — ground truth της έκπτωσης για πάντα, ανεξαρτήτως
		// μεταγενέστερων αλλαγών τιμοκαταλόγου.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'stamp_regular_prices' ), 20, 2 );
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

	/** Προσθήκη πεδίου στο checkout (μόνο όταν ενεργό). */
	public static function add_field( array $fields ): array {

		if ( ! self::triggered() ) {
			return $fields;
		}

		$fields['order']['rs_free_reason'] = array(
			'label'       => __( 'Αιτιολογία δωρεάν αντιτύπου', 'revenue-splitter' ),
			'type'        => 'textarea',
			'required'    => true,
			'placeholder' => __( 'π.χ. δώρο, διαγωνισμός, κριτική βιβλίου…', 'revenue-splitter' ),
		);

		return $fields;
	}

	/** Server-side validation — το required του πεδίου δεν αρκεί μόνο του. */
	public static function validate(): void {

		if ( ! self::triggered() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- το Woo τρέχει το δικό του nonce στο checkout.
		if ( empty( $_POST['rs_free_reason'] ) ) {
			wc_add_notice(
				__( 'Παρακαλώ συμπλήρωσε την αιτιολογία δωρεάν αντιτύπου.', 'revenue-splitter' ),
				'error'
			);
		}
	}

	/** Αποθήκευση στο order meta. */
	public static function save( WC_Order $order, array $data ): void {

		if ( ! self::triggered() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo nonce στο checkout.
		$reason = isset( $_POST['rs_free_reason'] )
			? sanitize_textarea_field( wp_unslash( $_POST['rs_free_reason'] ) )
			: '';

		if ( '' !== $reason ) {
			$order->update_meta_data( self::META, $reason );
		}
	}

	/** Προβολή στη σελίδα παραγγελίας (admin). */
	public static function show_admin( WC_Order $order ): void {

		$reason = $order->get_meta( self::META );

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
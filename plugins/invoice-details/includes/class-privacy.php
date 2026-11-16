<?php
/**
 * IVD_Privacy — the invoice details in the WordPress privacy tools:
 *
 *  - Orders: WooCommerce's own order exporter and eraser take our order
 *    meta (and the Checkout block copies, and the Timologia / OBS names the
 *    plugin reads or writes), so «Erase personal data» anonymises them with
 *    the rest of the order.
 *  - Customers: an exporter and an eraser for the details saved on the
 *    account for the next order (user meta of the Checkout block fields).
 *  - A suggested paragraph for the privacy policy (VIES).
 */

defined( 'ABSPATH' ) || exit;

final class IVD_Privacy {

	public static function init(): void {
		add_filter( 'woocommerce_privacy_export_order_personal_data_meta', array( __CLASS__, 'order_export' ) );
		add_filter( 'woocommerce_privacy_remove_order_personal_data_meta', array( __CLASS__, 'order_erase' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
	}

	/** Order meta key => label, for the export. */
	private static function order_keys(): array {
		$keys = array(
			IVD_Data::META['vat']        => __( 'ΑΦΜ', 'invoice-details' ),
			IVD_Data::META['tax_office'] => __( 'ΔΟΥ', 'invoice-details' ),
			IVD_Data::META['activity']   => __( 'Δραστηριότητα', 'invoice-details' ),
			IVD_Data::META['hq']         => __( 'Έδρα', 'invoice-details' ),
		);
		foreach ( self::customer_fields() as $f => $label ) {
			$keys[ IVD_Data::block_key( $f ) ] = $label;
		}
		// Timologia / OBS names (read, or written with the compat setting).
		foreach ( array( '_billing_vat', '_billing_vat_number', 'vat', '_billing_irs', 'tax_office', '_billing_store', 'job_description', 'billing_bs_address' ) as $k ) {
			$keys[ $k ] = $k;
		}
		return $keys;
	}

	/**
	 * woocommerce_privacy_export_order_personal_data_meta.
	 *
	 * @param mixed $meta Meta key => label.
	 * @return mixed
	 */
	public static function order_export( $meta ) {
		return is_array( $meta ) ? $meta + self::order_keys() : $meta;
	}

	/**
	 * woocommerce_privacy_remove_order_personal_data_meta.
	 *
	 * @param mixed $meta Meta key => data type.
	 * @return mixed
	 */
	public static function order_erase( $meta ) {
		if ( ! is_array( $meta ) ) {
			return $meta;
		}
		foreach ( array_keys( self::order_keys() ) as $k ) {
			$meta[ $k ] = 'text';
		}
		return $meta;
	}

	public static function exporters( $list ) {
		if ( is_array( $list ) ) {
			$list['invoice-details'] = array(
				'exporter_friendly_name' => __( 'Invoice Details: αποθηκευμένα στοιχεία τιμολογίου', 'invoice-details' ),
				'callback'               => array( __CLASS__, 'export' ),
			);
		}
		return $list;
	}

	public static function erasers( $list ) {
		if ( is_array( $list ) ) {
			$list['invoice-details'] = array(
				'eraser_friendly_name' => __( 'Invoice Details: αποθηκευμένα στοιχεία τιμολογίου', 'invoice-details' ),
				'callback'             => array( __CLASS__, 'erase' ),
			);
		}
		return $list;
	}

	/** The customer's saved fields (block field => label). */
	private static function customer_fields(): array {
		return array(
			'company'    => __( 'Επωνυμία', 'invoice-details' ),
			'vat'        => __( 'ΑΦΜ', 'invoice-details' ),
			'tax_office' => __( 'ΔΟΥ', 'invoice-details' ),
			'activity'   => __( 'Δραστηριότητα', 'invoice-details' ),
			'hq'         => __( 'Έδρα', 'invoice-details' ),
		);
	}

	/**
	 * Exporter callback.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (one page only).
	 */
	public static function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		$data = array();
		if ( $user instanceof WP_User ) {
			foreach ( self::customer_fields() as $f => $label ) {
				$v = get_user_meta( $user->ID, IVD_Data::block_key( $f ), true );
				if ( is_scalar( $v ) && '' !== (string) $v ) {
					$data[] = array(
						'name'  => $label,
						'value' => (string) $v,
					);
				}
			}
		}
		return array(
			'data' => $data ? array(
				array(
					'group_id'    => 'invoice-details',
					'group_label' => __( 'Στοιχεία τιμολογίου', 'invoice-details' ),
					'item_id'     => 'invoice-details-' . ( $user instanceof WP_User ? $user->ID : 0 ),
					'data'        => $data,
				),
			) : array(),
			'done' => true,
		);
	}

	/**
	 * Eraser callback.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (one page only).
	 */
	public static function erase( $email, $page = 1 ): array {
		$user    = get_user_by( 'email', (string) $email );
		$removed = false;
		if ( $user instanceof WP_User ) {
			foreach ( array_keys( IVD_Data::BLOCK ) as $f ) {
				$key = IVD_Data::block_key( $f );
				if ( '' !== (string) get_user_meta( $user->ID, $key, true ) ) {
					delete_user_meta( $user->ID, $key );
					$removed = true;
				}
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/** A suggested paragraph for the privacy policy. */
	public static function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'Invoice Details',
			wp_kses_post(
				'<p>' . __( 'Όταν ζητάτε τιμολόγιο, αποθηκεύουμε στην παραγγελία την επωνυμία, τον ΑΦΜ, τη ΔΟΥ, τη δραστηριότητα και, αν τη δώσετε, τη διεύθυνση της έδρας, για την έκδοση του τιμολογίου και για τα λογιστικά μας βιβλία. Αν έχετε λογαριασμό, τα ίδια στοιχεία μένουν στον λογαριασμό σας για την επόμενη παραγγελία και τα αλλάζετε στον λογαριασμό σας. Για ΑΦΜ άλλης χώρας της ΕΕ στέλνουμε τη χώρα και τον αριθμό στην υπηρεσία VIES της Ευρωπαϊκής Επιτροπής, για να ελεγχθεί ο αριθμός.', 'invoice-details' ) . '</p>'
				. ( IVD_Aade::configured() ? '<p>' . __( 'Για ελληνικό ΑΦΜ, το κατάστημα ζητά από την ΑΑΔΕ (υπηρεσία Αναζήτησης Βασικών Στοιχείων Μητρώου) την επωνυμία, τη ΔΟΥ, τη δραστηριότητα και τη διεύθυνση της επιχείρησης, για να συμπληρωθούν τα πεδία. Η ΑΑΔΕ παίρνει μόνο τον ΑΦΜ.', 'invoice-details' ) . '</p>' : '' )
			)
		);
	}
}

<?php
/**
 * IVD_Data — the invoice details of an order and of a customer.
 *
 * Order meta (store records, they stay after uninstall, Bible §12):
 *   _ivd_type        'invoice' | 'receipt'
 *   _ivd_vat         the number without country prefix
 *   _ivd_country     ISO country of the number
 *   _ivd_tax_office  ΔΟΥ (Greek customers)
 *   _ivd_activity    business activity (Greek customers)
 *   _ivd_hq          head-office address, when it differs
 *   _ivd_check       JSON of the last check (IVD_Vat)
 * The company name is the order's own billing company (WooCommerce core),
 * which every invoicing provider reads.
 *
 * Orders made before the switch keep showing their details: the meta of
 * «Timologia for WooCommerce» (_billing_timologio Y, _billing_vat,
 * _billing_irs, _billing_store) and of «Timologia for WooCommerce OBS»
 * (invoice 1, vat / _billing_vat_number, tax_office, job_description,
 * billing_company, billing_bs_*) are read when our meta is missing.
 *
 * Compatible meta (setting compat): the same details are also written
 * under the names of one of those plugins, so an invoicing provider that
 * reads them keeps working.
 *
 * Customer details (user meta, the keys the Checkout block uses for its
 * additional fields in the "contact" location, so the classic checkout,
 * the block and My account share one place):
 *   _wc_other/invoice-details/invoice, /vat, /tax-office, /activity, /hq
 */

defined( 'ABSPATH' ) || exit;

final class IVD_Data {

	const NS = 'invoice-details';

	/** Field name => order meta key. */
	const META = array(
		'vat'        => '_ivd_vat',
		'country'    => '_ivd_country',
		'tax_office' => '_ivd_tax_office',
		'activity'   => '_ivd_activity',
		'hq'         => '_ivd_hq',
	);

	const META_TYPE  = '_ivd_type';
	const META_CHECK = '_ivd_check';

	/** Field name => block field / customer meta name (after the namespace). */
	const BLOCK = array(
		'invoice'    => 'invoice',
		'company'    => 'company',
		'vat'        => 'vat',
		'tax_office' => 'tax-office',
		'activity'   => 'activity',
		'hq'         => 'hq',
	);

	/** Most characters per field. */
	const MAX = array(
		'company'    => 150,
		'vat'        => 20,
		'tax_office' => 80,
		'activity'   => 150,
		'hq'         => 200,
	);

	/** The block field id of a field ("invoice-details/vat"). */
	public static function block_id( string $field ): string {
		return self::NS . '/' . self::BLOCK[ $field ];
	}

	/** The customer / order meta key WooCommerce uses for a block field. */
	public static function block_key( string $field ): string {
		return '_wc_other/' . self::block_id( $field );
	}

	/** One field value: plain text, one line, cut to its length. */
	public static function clean( string $field, $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		$max   = isset( self::MAX[ $field ] ) ? self::MAX[ $field ] : 150;
		return mb_substr( trim( sanitize_text_field( $value ) ), 0, $max );
	}

	/* =====================================================================
	 * Orders
	 * =================================================================== */

	/**
	 * The invoice details of an order, or null when it has no invoice.
	 *
	 * @param int|WC_Order $order Order or order ID.
	 */
	public static function get( $order ): ?array {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$type = (string) $order->get_meta( self::META_TYPE, true );
		if ( 'invoice' === $type ) {
			$check = json_decode( (string) $order->get_meta( self::META_CHECK, true ), true );
			return array(
				'type'       => 'invoice',
				'company'    => (string) $order->get_billing_company(),
				'vat'        => (string) $order->get_meta( self::META['vat'], true ),
				'country'    => (string) $order->get_meta( self::META['country'], true ),
				'tax_office' => (string) $order->get_meta( self::META['tax_office'], true ),
				'activity'   => (string) $order->get_meta( self::META['activity'], true ),
				'hq'         => (string) $order->get_meta( self::META['hq'], true ),
				'check'      => is_array( $check ) ? $check : array(),
				'source'     => 'ivd',
			);
		}
		if ( 'receipt' === $type ) {
			return null;
		}
		return self::legacy( $order );
	}

	/** The details from the meta of Timologia or OBS (orders before the switch). */
	private static function legacy( WC_Order $order ): ?array {
		// OBS writes _billing_timologio too, so it is read first.
		if ( '1' === (string) $order->get_meta( 'invoice', true ) ) {
			$vat = (string) $order->get_meta( 'vat', true );
			if ( '' === $vat ) {
				$vat = (string) $order->get_meta( '_billing_vat_number', true );
			}
			$company = (string) $order->get_meta( 'billing_company', true );
			$hq      = array_filter(
				array(
					(string) $order->get_meta( 'billing_bs_address', true ),
					trim( (string) $order->get_meta( 'billing_bs_zip', true ) . ' ' . (string) $order->get_meta( 'billing_bs_city', true ) ),
				)
			);
			list( $country, $number ) = IVD_Vat::split( $vat, (string) $order->get_billing_country() );
			return array(
				'type'       => 'invoice',
				'company'    => '' !== $company ? $company : (string) $order->get_billing_company(),
				'vat'        => $number,
				'country'    => $country,
				'tax_office' => (string) $order->get_meta( 'tax_office', true ),
				'activity'   => (string) $order->get_meta( 'job_description', true ),
				'hq'         => implode( ', ', $hq ),
				'check'      => array(),
				'source'     => 'obs',
			);
		}
		if ( 'Y' === (string) $order->get_meta( '_billing_timologio', true ) ) {
			list( $country, $number ) = IVD_Vat::split( (string) $order->get_meta( '_billing_vat', true ), (string) $order->get_billing_country() );
			return array(
				'type'       => 'invoice',
				'company'    => (string) $order->get_billing_company(),
				'vat'        => $number,
				'country'    => $country,
				'tax_office' => (string) $order->get_meta( '_billing_irs', true ),
				'activity'   => (string) $order->get_meta( '_billing_store', true ),
				'hq'         => '',
				'check'      => array(),
				'source'     => 'timologia',
			);
		}
		return null;
	}

	/**
	 * Writes the details on an order (no save: the caller saves).
	 *
	 * @param WC_Order   $order Order.
	 * @param array|null $d     Details (company, vat, country, tax_office,
	 *                          activity, hq), or null for a receipt.
	 * @param array      $check Check result (IVD_Vat), may be empty.
	 */
	public static function store( WC_Order $order, ?array $d, array $check = array() ): void {
		if ( null === $d ) {
			$order->update_meta_data( self::META_TYPE, 'receipt' );
			foreach ( self::META as $key ) {
				$order->delete_meta_data( $key );
			}
			$order->delete_meta_data( self::META_CHECK );
			self::compat( $order, null );
			return;
		}
		$order->update_meta_data( self::META_TYPE, 'invoice' );
		if ( isset( $d['company'] ) && '' !== $d['company'] ) {
			$order->set_billing_company( $d['company'] );
		}
		foreach ( self::META as $field => $key ) {
			$v = isset( $d[ $field ] ) ? (string) $d[ $field ] : '';
			if ( '' === $v ) {
				$order->delete_meta_data( $key );
			} else {
				$order->update_meta_data( $key, $v );
			}
		}
		if ( $check ) {
			$order->update_meta_data( self::META_CHECK, wp_json_encode( $check, JSON_UNESCAPED_UNICODE ) );
		}
		self::compat( $order, $d );
	}

	/** Writes the check result only (admin «Έλεγχος VIES»). */
	public static function store_check( WC_Order $order, array $check ): void {
		$order->update_meta_data( self::META_CHECK, wp_json_encode( $check, JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * The compatible meta of Timologia or OBS (setting compat).
	 *
	 * @param WC_Order   $order Order.
	 * @param array|null $d     Details or null for a receipt.
	 */
	private static function compat( WC_Order $order, ?array $d ): void {
		$mode = IVD_Settings::get()['compat'];
		if ( 'none' === $mode ) {
			return;
		}
		$vat = null === $d ? '' : ( 'GR' === $d['country'] || '' === $d['country'] ? $d['vat'] : $d['country'] . $d['vat'] );
		if ( 'timologia' === $mode ) {
			$order->update_meta_data( '_billing_timologio', null === $d ? 'N' : 'Y' );
			if ( null !== $d ) {
				$order->update_meta_data( '_billing_vat', $vat );
				$order->update_meta_data( '_billing_irs', $d['tax_office'] );
				$order->update_meta_data( '_billing_store', $d['activity'] );
			}
			return;
		}
		// OBS.
		$order->update_meta_data( '_billing_timologio', null === $d ? 'N' : 'Y' );
		if ( null === $d ) {
			return;
		}
		$order->update_meta_data( 'invoice', '1' );
		$order->update_meta_data( 'vat', $vat );
		$order->update_meta_data( '_billing_vat_number', $vat );
		$order->update_meta_data( 'tax_office', $d['tax_office'] );
		$order->update_meta_data( 'job_description', $d['activity'] );
		$order->update_meta_data( 'billing_company', isset( $d['company'] ) && '' !== $d['company'] ? $d['company'] : (string) $order->get_billing_company() );
		if ( '' !== $d['hq'] ) {
			$order->update_meta_data( 'billing_bs_address', $d['hq'] );
		}
	}

	/* =====================================================================
	 * Customers
	 * =================================================================== */

	/**
	 * The saved details of a customer (empty strings for a visitor).
	 *
	 * @param int $user_id User ID.
	 * @return array { invoice: bool, vat, tax_office, activity, hq }
	 */
	public static function customer( int $user_id ): array {
		$out = array(
			'invoice'    => false,
			'vat'        => '',
			'tax_office' => '',
			'activity'   => '',
			'hq'         => '',
		);
		if ( $user_id <= 0 ) {
			return $out;
		}
		foreach ( array_keys( $out ) as $field ) {
			$v = get_user_meta( $user_id, self::block_key( $field ), true );
			if ( 'invoice' === $field ) {
				$out['invoice'] = in_array( $v, array( true, 1, '1', 'yes', 'true' ), true );
			} else {
				$out[ $field ] = is_scalar( $v ) ? (string) $v : '';
			}
		}
		return $out;
	}

	/**
	 * Saves the details of a customer for the next order.
	 *
	 * @param int        $user_id User ID.
	 * @param array|null $d       Details, or null for a receipt (only the
	 *                            choice changes; the numbers stay for later).
	 */
	public static function save_customer( int $user_id, ?array $d ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		update_user_meta( $user_id, self::block_key( 'invoice' ), null === $d ? '0' : '1' );
		if ( null === $d ) {
			return;
		}
		$vat = 'GR' === $d['country'] || '' === $d['country'] ? $d['vat'] : $d['country'] . $d['vat'];
		update_user_meta( $user_id, self::block_key( 'vat' ), $vat );
		foreach ( array( 'tax_office', 'activity', 'hq' ) as $field ) {
			update_user_meta( $user_id, self::block_key( $field ), (string) $d[ $field ] );
		}
	}
}

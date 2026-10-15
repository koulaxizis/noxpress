<?php
/**
 * PKP_Method — the shipping method "Store pickup" (id nox_store_pickup).
 *
 * Added to shipping zones like any WooCommerce method. Per zone instance:
 *  - title / title_en  the name the customer sees (Greek / English; empty
 *                      English = the Greek)
 *  - cost              a plain amount, 0 by default (no formulas)
 *  - tax_status        'none' (default) | 'taxable'
 *  - stores            store ids offered in this zone (empty = every active
 *                      store)
 *
 * Offered only when the mode allows it (PKP_Settings::visible()), at
 * least one active store is left for the zone and the no-pickup rule does
 * not apply to the package (PKP_Checkout::pickup_allowed()). In Store API requests
 * (Cart / Checkout blocks) only when exactly one store is possible: the
 * blocks have no place for the choice (Bible §14.4 deviation, bootstrap).
 *
 * Loaded by the bootstrap after the WooCommerce check (WooCommerce loads
 * WC_Shipping_Method with its own includes).
 */

defined( 'ABSPATH' ) || exit;

class PKP_Method extends WC_Shipping_Method {

	const ID = 'nox_store_pickup';

	/**
	 * @param int $instance_id Shipping zone method instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = self::ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Παραλαβή από το κατάστημα', 'store-pickup' );
		$this->method_description = __( 'Ο πελάτης παραλαμβάνει την παραγγελία από ένα από τα καταστήματά σου, που τα διαλέγει στο ταμείο. Τα καταστήματα ορίζονται στο Noxpress › Παραλαβή.', 'store-pickup' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
		$this->init();
	}

	private function init(): void {
		$this->instance_form_fields = $this->fields();
		$this->title                = (string) $this->get_option( 'title' );
		$this->tax_status           = 'taxable' === $this->get_option( 'tax_status' ) ? 'taxable' : 'none';
		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/** Instance settings (the zone modal). */
	private function fields(): array {
		$stores = array();
		foreach ( PKP_Settings::active_stores() as $st ) {
			$stores[ $st['id'] ] = $st['name'];
		}
		return array(
			'title'      => array(
				'title'       => __( 'Όνομα', 'store-pickup' ),
				'type'        => 'text',
				'description' => __( 'Το όνομα της μεθόδου στο ταμείο.', 'store-pickup' ),
				'default'     => 'Παραλαβή από το κατάστημα',
				'desc_tip'    => true,
			),
			'title_en'   => array(
				'title'       => __( 'Όνομα (English)', 'store-pickup' ),
				'type'        => 'text',
				'description' => __( 'Για επισκέπτες στα αγγλικά. Κενό = το ελληνικό.', 'store-pickup' ),
				'default'     => 'Store pickup',
				'desc_tip'    => true,
			),
			'cost'       => array(
				'title'       => __( 'Κόστος', 'store-pickup' ),
				'type'        => 'price',
				'placeholder' => '0',
				'description' => __( 'Ένα σταθερό ποσό. 0 = χωρίς χρέωση.', 'store-pickup' ),
				'default'     => '0',
				'desc_tip'    => true,
			),
			'tax_status' => array(
				'title'   => __( 'Κατάσταση φόρου', 'store-pickup' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'none',
				'options' => array(
					'none'    => __( 'Κανένας', 'store-pickup' ),
					'taxable' => __( 'Φορολογήσιμο', 'store-pickup' ),
				),
			),
			'stores'     => array(
				'title'       => __( 'Καταστήματα', 'store-pickup' ),
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'description' => $stores
					? __( 'Ποια καταστήματα προσφέρονται σε αυτή τη ζώνη. Κενό = όλα τα ενεργά.', 'store-pickup' )
					: __( 'Δεν υπάρχει ακόμα ενεργό κατάστημα: πρόσθεσέ τα στο Noxpress › Παραλαβή.', 'store-pickup' ),
				'default'     => array(),
				'options'     => $stores,
			),
		);
	}

	/** Sanitizes the cost on save: a plain non-negative amount. */
	public function sanitize_cost( $value ) {
		$value = wc_format_decimal( is_scalar( $value ) ? (string) $value : '' );
		return ( is_numeric( $value ) && (float) $value > 0 ) ? $value : '0';
	}

	/** Field "cost": WooCommerce calls validate_{type}_field, we only narrow it. */
	public function validate_cost_field( $key, $value ) {
		return $this->sanitize_cost( $value );
	}

	/** Field "stores": only ids of existing stores. */
	public function validate_stores_field( $key, $value ) {
		$ids = array();
		foreach ( PKP_Settings::get()['stores'] as $st ) {
			$ids[ $st['id'] ] = true;
		}
		$out = array();
		foreach ( (array) $value as $id ) {
			$id = is_string( $id ) ? sanitize_key( $id ) : '';
			if ( isset( $ids[ $id ] ) ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** The name in the visitor language. */
	public function visitor_title(): string {
		$en = trim( (string) $this->get_option( 'title_en' ) );
		$el = trim( (string) $this->get_option( 'title' ) );
		$t  = ( 'en' === PKP_Settings::lang() && '' !== $en ) ? $en : $el;
		return '' !== $t ? $t : $this->method_title;
	}

	/** Active stores offered by this instance, in display order. */
	public function stores(): array {
		$pick = $this->get_option( 'stores' );
		$pick = is_array( $pick ) ? array_flip( $pick ) : array();
		$all  = PKP_Settings::active_stores();
		if ( ! $pick ) {
			return $all;
		}
		return array_values(
			array_filter(
				$all,
				static function ( $st ) use ( $pick ) {
					return isset( $pick[ $st['id'] ] );
				}
			)
		);
	}

	/**
	 * @param array $package Package.
	 */
	public function is_available( $package ) {
		try {
			$ok = PKP_Settings::visible() && $this->stores() && PKP_Checkout::pickup_allowed( is_array( $package ) ? $package : array() );
			if ( $ok && PKP_Checkout::is_rest() && count( $this->stores() ) > 1 ) {
				$ok = false;
			}
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		/** This filter is documented in WooCommerce (woocommerce_shipping_{id}_is_available). */
		return (bool) apply_filters( 'woocommerce_shipping_' . $this->id . '_is_available', $ok && $this->is_enabled(), $package, $this );
	}

	/**
	 * @param array $package Package.
	 */
	public function calculate_shipping( $package = array() ) {
		$this->add_rate(
			array(
				'label'   => $this->visitor_title(),
				'cost'    => (float) $this->sanitize_cost( $this->get_option( 'cost' ) ),
				'package' => $package,
			)
		);
	}
}

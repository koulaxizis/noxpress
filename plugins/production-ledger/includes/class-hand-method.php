<?php
/**
 * PL_Hand_Method (1.3.0) — «Παράδοση από τον εκδότη»: copies handed over
 * in person. Picked only on orders in the admin (and by event sales);
 * never offered at checkout. Loaded on woocommerce_shipping_init.
 */

defined( 'ABSPATH' ) || exit;

class PL_Hand_Method extends WC_Shipping_Method {

	/** @param int $instance_id Instance. */
	public function __construct( $instance_id = 0 ) {
		parent::__construct( $instance_id );
		$this->id                 = PL_Sales::METHOD;
		$this->method_title       = __( 'Παράδοση από τον εκδότη', 'production-ledger' );
		$this->method_description = __( 'Αντίτυπα που δίνει ο εκδότης χέρι με χέρι. Δεν εμφανίζεται ποτέ στο ταμείο: επιλέγεται μόνο σε παραγγελία από το admin.', 'production-ledger' );
		$this->title              = $this->method_title;
		$this->enabled            = 'yes';
		$this->supports           = array();
	}

	/**
	 * Never available to a cart.
	 *
	 * @param array $package Package.
	 */
	public function is_available( $package ) {
		return false;
	}

	/** @param array $package Package. */
	public function calculate_shipping( $package = array() ) {
	}
}

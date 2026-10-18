<?php
/**
 * SHN_Fields — preparation days and release date on products and product
 * categories (admin only, design §4.1).
 *
 *  - Product, Shipping tab: preparation min / max days and "available
 *    from" (pre-orders). Saved with the product (WooCommerce's own nonce),
 *    capability edit_product.
 *  - Product category (add / edit screens): preparation min / max days.
 *    Saved on created_/edited_product_cat after the term screen's nonce,
 *    capability manage_product_terms.
 * Empty fields mean "not set" and remove the meta.
 */

defined( 'ABSPATH' ) || exit;

final class SHN_Fields {

	const NONCE       = 'shn_term';
	const NONCE_FIELD = 'shn_term_nonce';

	public static function init(): void {
		add_action( 'woocommerce_product_options_shipping', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'product_save' ) );
		add_action( 'product_cat_add_form_fields', array( __CLASS__, 'term_add_fields' ), 20 );
		add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'term_edit_fields' ), 20 );
		add_action( 'created_product_cat', array( __CLASS__, 'term_save' ) );
		add_action( 'edited_product_cat', array( __CLASS__, 'term_save' ) );
	}

	/* =====================================================================
	 * Product
	 * =================================================================== */

	public static function product_fields(): void {
		global $product_object;
		$id = $product_object instanceof WC_Product ? $product_object->get_id() : 0;

		echo '<div class="options_group shn-product">';
		woocommerce_wp_text_input(
			array(
				'id'                => 'shn_prep_min',
				'label'             => __( 'Προετοιμασία από (εργάσιμες)', 'shipping-notes' ),
				'type'              => 'number',
				'value'             => $id ? (string) get_post_meta( $id, SHN_Settings::META_PREP_MIN, true ) : '',
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => (string) SHN_Settings::MAX_DAYS,
					'step' => '1',
				),
				'desc_tip'          => true,
				'description'       => __( 'Shipping Notes: ημέρες μέχρι να φύγει το δέμα (π.χ. χρόνος παραγωγής). Κενό = από την κατηγορία ή το κατάστημα.', 'shipping-notes' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => 'shn_prep_max',
				'label'             => __( 'Προετοιμασία έως (εργάσιμες)', 'shipping-notes' ),
				'type'              => 'number',
				'value'             => $id ? (string) get_post_meta( $id, SHN_Settings::META_PREP_MAX, true ) : '',
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => (string) SHN_Settings::MAX_DAYS,
					'step' => '1',
				),
			)
		);
		$from = $id ? (string) get_post_meta( $id, SHN_Settings::META_FROM, true ) : '';
		// With Pre-Orders active the release date lives in its own fields
		// (shn_available_from is filled from them); our field stays only
		// while a product still has a date of ours, so it can be cleared.
		if ( '' === $from && function_exists( 'por_get_release' ) ) {
			echo '<p class="form-field shn-from-por"><label>' . esc_html__( 'Διαθέσιμο από', 'shipping-notes' ) . '</label>'
				. esc_html__( 'Από την ημερομηνία κυκλοφορίας του Pre-Orders (καρτέλα «Γενικά»).', 'shipping-notes' ) . '</p>';
			echo '</div>';
			return;
		}
		woocommerce_wp_text_input(
			array(
				'id'          => 'shn_available_from',
				'label'       => __( 'Διαθέσιμο από', 'shipping-notes' ),
				'type'        => 'date',
				'value'       => $from,
				'desc_tip'    => true,
				'description' => __( 'Shipping Notes: για προπαραγγελίες. Ο χρόνος παράδοσης μετράει από αυτή την ημερομηνία. Μετά από αυτήν το πεδίο δεν ισχύει πια.', 'shipping-notes' ),
			)
		);
		echo '</div>';
	}

	/** @param WC_Product $product Product being saved (WooCommerce has checked its nonce). */
	public static function product_save( $product ): void {
		if ( ! $product instanceof WC_Product || ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the product form nonce.
		if ( ! isset( $_POST['shn_prep_min'] ) && ! isset( $_POST['shn_available_from'] ) ) {
			return; // Quick edit, REST or another form: leave the fields alone.
		}
		$min  = isset( $_POST['shn_prep_min'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['shn_prep_min'] ) ) : '';
		$max  = isset( $_POST['shn_prep_max'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['shn_prep_max'] ) ) : '';
		// null = the field was not on the form (Pre-Orders active): keep the date.
		$from = isset( $_POST['shn_available_from'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['shn_available_from'] ) ) : null;
		// phpcs:enable

		$range = SHN_Delivery::meta_range( $min, $max );
		if ( null === $range ) {
			$product->delete_meta_data( SHN_Settings::META_PREP_MIN );
			$product->delete_meta_data( SHN_Settings::META_PREP_MAX );
		} else {
			$product->update_meta_data( SHN_Settings::META_PREP_MIN, (string) $range[0] );
			$product->update_meta_data( SHN_Settings::META_PREP_MAX, (string) $range[1] );
		}

		if ( null === $from ) {
			return;
		}
		if ( self::valid_date( $from ) ) {
			$product->update_meta_data( SHN_Settings::META_FROM, $from );
		} else {
			$product->delete_meta_data( SHN_Settings::META_FROM );
		}
	}

	private static function valid_date( string $d ): bool {
		return (bool) preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/* =====================================================================
	 * Product category
	 * =================================================================== */

	public static function term_add_fields(): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		?>
		<div class="form-field shn-term">
			<label><?php esc_html_e( 'Χρόνος προετοιμασίας (Shipping Notes)', 'shipping-notes' ); ?></label>
			<input type="number" name="shn_prep_min" min="0" max="<?php echo (int) SHN_Settings::MAX_DAYS; ?>" step="1" style="width:90px" aria-label="<?php esc_attr_e( 'Από', 'shipping-notes' ); ?>" />
			–
			<input type="number" name="shn_prep_max" min="0" max="<?php echo (int) SHN_Settings::MAX_DAYS; ?>" step="1" style="width:90px" aria-label="<?php esc_attr_e( 'Έως', 'shipping-notes' ); ?>" />
			<p class="description"><?php esc_html_e( 'Εργάσιμες μέχρι να φύγει το δέμα για τα προϊόντα της κατηγορίας και των υποκατηγοριών της. Κενό = του καταστήματος.', 'shipping-notes' ); ?></p>
		</div>
		<?php
	}

	public static function term_edit_fields( $term ): void {
		$id = $term instanceof WP_Term ? (int) $term->term_id : 0;
		?>
		<tr class="form-field shn-term">
			<th scope="row"><label><?php esc_html_e( 'Χρόνος προετοιμασίας (Shipping Notes)', 'shipping-notes' ); ?></label></th>
			<td>
				<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
				<input type="number" name="shn_prep_min" min="0" max="<?php echo (int) SHN_Settings::MAX_DAYS; ?>" step="1" style="width:90px" value="<?php echo esc_attr( (string) get_term_meta( $id, SHN_Settings::TERM_PREP_MIN, true ) ); ?>" aria-label="<?php esc_attr_e( 'Από', 'shipping-notes' ); ?>" />
				–
				<input type="number" name="shn_prep_max" min="0" max="<?php echo (int) SHN_Settings::MAX_DAYS; ?>" step="1" style="width:90px" value="<?php echo esc_attr( (string) get_term_meta( $id, SHN_Settings::TERM_PREP_MAX, true ) ); ?>" aria-label="<?php esc_attr_e( 'Έως', 'shipping-notes' ); ?>" />
				<p class="description"><?php esc_html_e( 'Εργάσιμες μέχρι να φύγει το δέμα για τα προϊόντα της κατηγορίας και των υποκατηγοριών της. Κενό = του καταστήματος.', 'shipping-notes' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public static function term_save( $term_id ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE )
			|| ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}
		$min   = isset( $_POST['shn_prep_min'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['shn_prep_min'] ) ) : '';
		$max   = isset( $_POST['shn_prep_max'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['shn_prep_max'] ) ) : '';
		$range = SHN_Delivery::meta_range( $min, $max );
		if ( null === $range ) {
			delete_term_meta( (int) $term_id, SHN_Settings::TERM_PREP_MIN );
			delete_term_meta( (int) $term_id, SHN_Settings::TERM_PREP_MAX );
			return;
		}
		update_term_meta( (int) $term_id, SHN_Settings::TERM_PREP_MIN, (string) $range[0] );
		update_term_meta( (int) $term_id, SHN_Settings::TERM_PREP_MAX, (string) $range[1] );
	}
}

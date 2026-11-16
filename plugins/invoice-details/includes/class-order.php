<?php
/**
 * IVD_Order — what an order with an invoice shows.
 *
 *  - Customer: rows in the order totals (woocommerce_get_order_item_totals):
 *    thank-you page, My account › Orders and every order email.
 *  - Admin: a meta box «Στοιχεία τιμολογίου» on the order screen (edit the
 *    details, see the last check, «Έλεγχος VIES»), saved on
 *    woocommerce_process_shop_order_meta with our own nonce; a «Τ» column
 *    and a «Μόνο τιμολόγια» filter in the orders list (HPOS and legacy;
 *    old Timologia / OBS invoices included).
 *  - WooCommerce lists the block fields of the order under the billing
 *    address too; they are taken out there, so the meta box is the one
 *    place to edit.
 *
 * Every callback is wrapped in try/catch (Bible §14.6).
 */

defined( 'ABSPATH' ) || exit;

final class IVD_Order {

	const NONCE       = 'ivd_order';
	const NONCE_FIELD = 'ivd_order_nonce';
	const CAP         = 'manage_woocommerce';

	private static $broken = false;

	public static function init(): void {
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'totals' ), 20, 2 );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ), 30 );
			add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_meta_box' ), 50, 2 );
			add_action( 'admin_post_ivd_check', array( __CLASS__, 'route_check' ) );
			add_action( 'admin_post_ivd_aade', array( __CLASS__, 'route_aade' ) );
			add_action( 'admin_notices', array( __CLASS__, 'check_notice' ) );
			add_filter( 'woocommerce_admin_billing_fields', array( __CLASS__, 'admin_billing_fields' ), 20, 3 );
			if ( IVD_Settings::get()['column'] ) {
				add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'columns' ), 20 );
				add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'column_hpos' ), 10, 2 );
				add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'columns' ), 20 );
				add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'column_legacy' ), 10, 2 );
				add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( __CLASS__, 'filter_select' ) );
				add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( __CLASS__, 'filter_hpos' ) );
				add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_select_legacy' ) );
				add_action( 'pre_get_posts', array( __CLASS__, 'filter_legacy' ) );
			}
		}
	}

	private static function fail( \Throwable $e ): void {
		self::$broken = true;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only.
			error_log( 'Invoice Details (order): ' . $e->getMessage() );
		}
	}

	/** The number as shown: Greek without prefix, other countries with it (EL for Greece is not added). */
	public static function number( array $d ): string {
		if ( '' === $d['vat'] ) {
			return '';
		}
		if ( '' === $d['country'] || 'GR' === $d['country'] ) {
			return $d['vat'];
		}
		$prefix = IVD_Vat::is_eu( $d['country'] ) ? IVD_Vat::EU[ $d['country'] ] : $d['country'];
		return $prefix . ' ' . $d['vat'];
	}

	/* =====================================================================
	 * Customer side: order totals rows
	 * =================================================================== */

	/**
	 * woocommerce_get_order_item_totals: the invoice rows after the total.
	 *
	 * @param array    $rows  Rows.
	 * @param WC_Order $order Order.
	 */
	public static function totals( $rows, $order ) {
		if ( self::$broken || ! is_array( $rows ) || ! $order instanceof WC_Order ) {
			return $rows;
		}
		try {
			$d = IVD_Data::get( $order );
			if ( null === $d ) {
				return $rows;
			}
			$add = array(
				'ivd_type' => array(
					'label' => esc_html( IVD_Settings::text( 'choice' ) ) . ':',
					'value' => esc_html( IVD_Settings::text( 'invoice' ) ),
				),
			);
			$map = array(
				'company'    => $d['company'],
				'vat'        => self::number( $d ),
				'tax_office' => $d['tax_office'],
				'activity'   => $d['activity'],
				'hq'         => $d['hq'],
			);
			foreach ( $map as $f => $v ) {
				if ( '' !== $v ) {
					$label           = 'hq' === $f ? __( 'Έδρα', 'invoice-details' ) : IVD_Settings::text( $f );
					$add[ 'ivd_' . $f ] = array(
						'label' => esc_html( $label ) . ':',
						'value' => esc_html( $v ),
					);
				}
			}
			return $rows + $add;
		} catch ( \Throwable $e ) {
			self::fail( $e );
			return $rows;
		}
	}

	/* =====================================================================
	 * Admin: meta box
	 * =================================================================== */

	public static function meta_box(): void {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'ivd-details', __( 'Στοιχεία τιμολογίου', 'invoice-details' ), array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'high' );
	}

	/**
	 * Meta box body.
	 *
	 * @param WP_Post|WC_Order $object Post (legacy) or order (HPOS).
	 */
	public static function render_meta_box( $object ): void {
		$order = $object instanceof WC_Order ? $object : wc_get_order( $object instanceof WP_Post ? $object->ID : 0 );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$d       = IVD_Data::get( $order );
		$invoice = null !== $d;
		if ( ! $invoice ) {
			$d = array(
				'company'    => (string) $order->get_billing_company(),
				'vat'        => '',
				'country'    => (string) $order->get_billing_country(),
				'tax_office' => '',
				'activity'   => '',
				'hq'         => '',
				'check'      => array(),
				'source'     => 'ivd',
			);
		}
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		echo '<p><label><input type="radio" name="ivd_type" value="receipt" ' . checked( $invoice, false, false ) . ' /> ' . esc_html__( 'Απόδειξη', 'invoice-details' ) . '</label> &nbsp; ';
		echo '<label><input type="radio" name="ivd_type" value="invoice" ' . checked( $invoice, true, false ) . ' /> ' . esc_html__( 'Τιμολόγιο', 'invoice-details' ) . '</label></p>';
		$fields = array(
			'company'    => __( 'Επωνυμία', 'invoice-details' ),
			'vat'        => __( 'ΑΦΜ (με πρόθεμα χώρας εκτός Ελλάδας)', 'invoice-details' ),
			'tax_office' => __( 'ΔΟΥ', 'invoice-details' ),
			'activity'   => __( 'Δραστηριότητα', 'invoice-details' ),
			'hq'         => __( 'Διεύθυνση έδρας', 'invoice-details' ),
		);
		$values = $d;
		$values['vat'] = str_replace( ' ', '', self::number( $d ) );
		foreach ( $fields as $f => $label ) {
			echo '<p style="margin:6px 0"><label for="ivd_' . esc_attr( $f ) . '" style="display:block">' . esc_html( $label ) . '</label>';
			echo '<input type="text" class="widefat" id="ivd_' . esc_attr( $f ) . '" name="ivd_' . esc_attr( $f ) . '" maxlength="' . (int) IVD_Data::MAX[ $f ] . '" value="' . esc_attr( (string) $values[ $f ] ) . '" /></p>';
		}
		if ( 'ivd' !== $d['source'] ) {
			/* translators: %s: plugin name (Timologia, OBS) */
			echo '<p class="description">' . esc_html( sprintf( __( 'Στοιχεία από το %s (παραγγελία πριν από το Invoice Details). Με την αποθήκευση γράφονται στο Invoice Details.', 'invoice-details' ), 'obs' === $d['source'] ? 'Timologia OBS' : 'Timologia for WooCommerce' ) ) . '</p>';
		}
		if ( $invoice ) {
			$c = $d['check'];
			if ( ! empty( $c['status'] ) ) {
				$when = ! empty( $c['at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $c['at'] ) : '';
				echo '<p style="margin:8px 0 4px"><strong>' . esc_html( IVD_Vat::status_label( (string) $c['status'] ) ) . '</strong>' . ( '' !== $when ? ' · ' . esc_html( $when ) : '' ) . '</p>';
				if ( ! empty( $c['name'] ) ) {
					echo '<p class="description" style="margin:0">' . esc_html__( 'VIES:', 'invoice-details' ) . ' ' . esc_html( (string) $c['name'] ) . ( ! empty( $c['address'] ) ? ', ' . esc_html( (string) $c['address'] ) : '' ) . '</p>';
				}
				if ( ! empty( $c['request'] ) ) {
					echo '<p class="description" style="margin:0">' . esc_html__( 'Αρ. ελέγχου VIES:', 'invoice-details' ) . ' ' . esc_html( (string) $c['request'] ) . '</p>';
				}
				if ( ! empty( $c['error'] ) && 'unavailable' === $c['status'] ) {
					echo '<p class="description" style="margin:0">' . esc_html( (string) $c['error'] ) . '</p>';
				}
			}
			if ( '' !== $d['vat'] && IVD_Vat::is_eu( '' !== $d['country'] ? $d['country'] : (string) $order->get_billing_country() ) ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=ivd_check&order=' . (int) $order->get_id() ), 'ivd_check_' . (int) $order->get_id() );
				echo '<p style="margin:8px 0 0"><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Έλεγχος VIES', 'invoice-details' ) . '</a></p>';
				if ( 'GR' === $d['country'] ) {
					if ( IVD_Aade::configured() ) {
						$aade = wp_nonce_url( admin_url( 'admin-post.php?action=ivd_aade&order=' . (int) $order->get_id() ), 'ivd_aade_' . (int) $order->get_id() );
						echo '<p style="margin:8px 0 0"><a class="button" href="' . esc_url( $aade ) . '">' . esc_html__( 'Συμπλήρωση από ΑΑΔΕ', 'invoice-details' ) . '</a></p>';
					}
					echo '<p class="description">' . esc_html__( 'Για ελληνικό ΑΦΜ το VIES είναι μόνο πληροφορία: πολλές ελληνικές επιχειρήσεις δεν είναι γραμμένες για ενδοκοινοτικές συναλλαγές.', 'invoice-details' ) . '</p>';
				}
			}
		}
	}

	/**
	 * woocommerce_process_shop_order_meta: the meta box values.
	 *
	 * @param int   $order_id Order ID.
	 * @param mixed $post     Post or order.
	 */
	public static function save_meta_box( $order_id, $post = null ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		try {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$type = isset( $_POST['ivd_type'] ) ? sanitize_key( wp_unslash( $_POST['ivd_type'] ) ) : '';
			if ( 'invoice' !== $type && 'receipt' !== $type ) {
				return;
			}
			$before = IVD_Data::get( $order );
			if ( 'receipt' === $type ) {
				if ( null !== $before ) {
					IVD_Data::store( $order, null );
					$order->save();
				}
				return;
			}
			$v = array();
			foreach ( array( 'company', 'vat', 'tax_office', 'activity', 'hq' ) as $f ) {
				$v[ $f ] = isset( $_POST[ 'ivd_' . $f ] ) ? IVD_Data::clean( $f, wp_unslash( $_POST[ 'ivd_' . $f ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- IVD_Data::clean sanitizes.
			}
			list( $country, $n ) = IVD_Vat::split( $v['vat'], (string) $order->get_billing_country() );
			$d                   = array(
				'company'    => $v['company'],
				'vat'        => $n,
				'country'    => $country,
				'tax_office' => $v['tax_office'],
				'activity'   => $v['activity'],
				'hq'         => $v['hq'],
			);
			$same = null !== $before && 'ivd' === $before['source'] && $before['vat'] === $n && $before['country'] === $country;
			// A changed number gets a fresh local check (VIES only with the button).
			$check = $same ? array() : IVD_Vat::check( $n, $country, false );
			IVD_Data::store( $order, $d, $check );
			$order->save();
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/** admin-post.php?action=ivd_check: VIES check of one order (nonce + capability). */
	public static function route_check(): void {
		$id = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0;
		if ( ! $id || ! current_user_can( self::CAP ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'ivd_check_' . $id ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'invoice-details' ) );
		}
		$order  = wc_get_order( $id );
		$status = 'none';
		if ( $order instanceof WC_Order ) {
			$d = IVD_Data::get( $order );
			if ( null !== $d && '' !== $d['vat'] ) {
				$country = '' !== $d['country'] ? $d['country'] : (string) $order->get_billing_country();
				$check   = IVD_Vat::vies( $d['vat'], $country );
				if ( 'ivd' === $d['source'] ) {
					IVD_Data::store_check( $order, $check );
				} else {
					IVD_Data::store( $order, $d, $check );
				}
				/* translators: %s: result of the VIES check */
				$order->add_order_note( sprintf( __( 'Invoice Details: έλεγχος VIES — %s.', 'invoice-details' ), IVD_Vat::status_label( $check['status'] ) ) );
				$order->save();
				$status = $check['status'];
			}
		}
		$back = $order instanceof WC_Order ? $order->get_edit_order_url() : admin_url();
		wp_safe_redirect( add_query_arg( 'ivd_checked', rawurlencode( $status ), $back ) );
		exit;
	}

	/**
	 * admin-post.php?action=ivd_aade: fills the empty details of one order
	 * from AADE (nonce + capability). Never overwrites what is there; a note
	 * says what came in.
	 */
	public static function route_aade(): void {
		$id = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0;
		if ( ! $id || ! current_user_can( self::CAP ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'ivd_aade_' . $id ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'invoice-details' ) );
		}
		$order  = wc_get_order( $id );
		$status = 'none';
		if ( $order instanceof WC_Order ) {
			$d = IVD_Data::get( $order );
			if ( null !== $d && '' !== $d['vat'] && 'GR' === $d['country'] ) {
				$r      = IVD_Aade::lookup( $d['vat'], true );
				$status = 'aade_' . $r['status'];
				if ( in_array( $r['status'], array( 'found', 'inactive' ), true ) ) {
					$map    = array(
						'company'    => 'name',
						'tax_office' => 'tax_office',
						'activity'   => 'activity',
						'hq'         => 'hq',
					);
					$filled = array();
					foreach ( $map as $f => $src ) {
						if ( '' === $d[ $f ] && '' !== $r[ $src ] ) {
							$d[ $f ]  = $r[ $src ];
							$filled[] = $f;
						}
					}
					IVD_Data::store( $order, $d );
					$order->add_order_note(
						sprintf(
							/* translators: 1: company name at AADE, 2: tax office, 3: active or inactive */
							__( 'Invoice Details: ΑΑΔΕ — %1$s, ΔΟΥ %2$s (%3$s).', 'invoice-details' ),
							$r['name'],
							$r['tax_office'],
							'found' === $r['status'] ? __( 'ενεργός', 'invoice-details' ) : __( 'ανενεργός', 'invoice-details' )
						)
					);
					$order->save();
				}
			}
		}
		$back = $order instanceof WC_Order ? $order->get_edit_order_url() : admin_url();
		wp_safe_redirect( add_query_arg( 'ivd_checked', rawurlencode( $status ), $back ) );
		exit;
	}

	/** The result notice after «Έλεγχος VIES». */
	public static function check_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice after our own redirect.
		$status = isset( $_GET['ivd_checked'] ) ? sanitize_key( wp_unslash( (string) $_GET['ivd_checked'] ) ) : '';
		if ( '' === $status || ! current_user_can( self::CAP ) ) {
			return;
		}
		if ( 0 === strpos( $status, 'aade_' ) ) {
			$labels = array(
				'aade_found'       => array( 'success', __( 'Τα κενά στοιχεία συμπληρώθηκαν από την ΑΑΔΕ.', 'invoice-details' ) ),
				'aade_inactive'    => array( 'warning', __( 'Ο ΑΦΜ είναι ανενεργός στην ΑΑΔΕ. Τα κενά στοιχεία συμπληρώθηκαν.', 'invoice-details' ) ),
				'aade_not_found'   => array( 'error', __( 'Ο ΑΦΜ δεν βρέθηκε στην ΑΑΔΕ.', 'invoice-details' ) ),
				'aade_unavailable' => array( 'warning', __( 'Η ΑΑΔΕ δεν απάντησε. Δοκίμασε ξανά αργότερα.', 'invoice-details' ) ),
			);
			$l = isset( $labels[ $status ] ) ? $labels[ $status ] : array( 'warning', __( 'Η συμπλήρωση από την ΑΑΔΕ δεν έγινε.', 'invoice-details' ) );
			echo '<div class="notice notice-' . esc_attr( $l[0] ) . ' is-dismissible"><p>' . esc_html( $l[1] ) . '</p></div>';
			return;
		}
		$type = 'valid' === $status ? 'success' : ( 'invalid' === $status ? 'error' : 'warning' );
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html__( 'Έλεγχος VIES:', 'invoice-details' ) . ' ' . esc_html( IVD_Vat::status_label( $status ) ) . '</p></div>';
	}

	/**
	 * woocommerce_admin_billing_fields: our block fields out of the billing
	 * address box (the meta box is the one place to edit them).
	 *
	 * @param array $fields Fields.
	 */
	public static function admin_billing_fields( $fields, $order = null, $context = 'edit' ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}
		foreach ( array_keys( $fields ) as $key ) {
			if ( is_string( $key ) && 0 === strpos( $key, IVD_Data::NS . '/' ) ) {
				unset( $fields[ $key ] );
			}
		}
		return $fields;
	}

	/* =====================================================================
	 * Admin: orders list
	 * =================================================================== */

	public static function columns( $cols ) {
		if ( ! is_array( $cols ) ) {
			return $cols;
		}
		$head = '<span title="' . esc_attr__( 'Τιμολόγιο', 'invoice-details' ) . '">' . esc_html__( 'Τ', 'invoice-details' ) . '</span>';
		$out  = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'order_number' === $k ) {
				$out['ivd_invoice'] = $head;
			}
		}
		if ( ! isset( $out['ivd_invoice'] ) ) {
			$out['ivd_invoice'] = $head;
		}
		return $out;
	}

	public static function column_hpos( $column, $order ): void {
		if ( 'ivd_invoice' === $column && $order instanceof WC_Order ) {
			self::cell( $order );
		}
	}

	public static function column_legacy( $column, $post_id ): void {
		if ( 'ivd_invoice' === $column ) {
			$order = wc_get_order( (int) $post_id );
			if ( $order instanceof WC_Order ) {
				self::cell( $order );
			}
		}
	}

	private static function cell( WC_Order $order ): void {
		$d = IVD_Data::get( $order );
		if ( null !== $d ) {
			$title = trim( $d['company'] . ' · ' . self::number( $d ), ' ·' );
			echo '<span class="dashicons dashicons-media-text" title="' . esc_attr( $title ) . '" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Τιμολόγιο', 'invoice-details' ) . ': ' . esc_html( $title ) . '</span>';
		}
	}

	private static function filter_on(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		return isset( $_GET['ivd_invoice'] ) && '1' === sanitize_key( wp_unslash( (string) $_GET['ivd_invoice'] ) );
	}

	public static function filter_select(): void {
		echo '<select name="ivd_invoice"><option value="">' . esc_html__( 'Αποδείξεις και τιμολόγια', 'invoice-details' ) . '</option><option value="1" ' . selected( self::filter_on(), true, false ) . '>' . esc_html__( 'Μόνο τιμολόγια', 'invoice-details' ) . '</option></select>';
	}

	public static function filter_select_legacy( $post_type ): void {
		if ( 'shop_order' === $post_type ) {
			self::filter_select();
		}
	}

	/** Meta query for invoice orders, old Timologia / OBS ones included. */
	public static function meta_query(): array {
		return array(
			'relation' => 'OR',
			array(
				'key'   => IVD_Data::META_TYPE,
				'value' => 'invoice',
			),
			array(
				'relation' => 'AND',
				array(
					'key'     => IVD_Data::META_TYPE,
					'compare' => 'NOT EXISTS',
				),
				array(
					'relation' => 'OR',
					array(
						'key'   => 'invoice',
						'value' => '1',
					),
					array(
						'key'   => '_billing_timologio',
						'value' => 'Y',
					),
				),
			),
		);
	}

	public static function filter_hpos( $args ) {
		if ( is_array( $args ) && self::filter_on() ) {
			$args['meta_query']   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
			$args['meta_query'][] = self::meta_query();
		}
		return $args;
	}

	public static function filter_legacy( $query ): void {
		if ( ! $query instanceof WP_Query || ! $query->is_main_query() || ! is_admin() || 'shop_order' !== $query->get( 'post_type' ) || ! self::filter_on() ) {
			return;
		}
		$mq   = (array) $query->get( 'meta_query' );
		$mq[] = self::meta_query();
		$query->set( 'meta_query', $mq );
	}
}

<?php
/**
 * PBN_Admin_Product — "Bundle" on WooCommerce's product screen.
 *
 *  - "Bundle" in the product type list.
 *  - Tab "Bundle contents": products (search from the 3rd character),
 *    quantity, allowed variations of a variable product (the customer
 *    picks one), drag to reorder; discount % or the bundle price; "most
 *    bundles ever sold"; a live summary (value, price, discount, bundles
 *    the stock allows, warnings).
 *  - Price, stock status and weight are not typed: they come from the
 *    contents (PBN_Bundle::before_save()). The Inventory tab keeps SKU and
 *    "Sold individually"; the Shipping tab is hidden (every product ships
 *    as its own line).
 *
 * Saving: woocommerce_admin_process_product_object, the rows go through
 * PBN_Bundle::validate_rows() (only products that exist and may go in a
 * bundle; never the bundle itself).
 */

defined( 'ABSPATH' ) || exit;

final class PBN_Admin_Product {

	const NONCE = 'pbn_product';

	public static function init(): void {
		add_filter( 'product_type_selector', array( __CLASS__, 'type' ), 20 );
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tabs' ), 20 );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ), 20 );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ), 20, 1 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_action( 'wp_ajax_pbn_admin_row', array( __CLASS__, 'ajax_row' ) );
		add_action( 'wp_ajax_pbn_admin_summary', array( __CLASS__, 'ajax_summary' ) );
		add_action( 'admin_notices', array( __CLASS__, 'saved_notice' ) );
	}

	/** @param mixed $types Product types. */
	public static function type( $types ) {
		$types                       = is_array( $types ) ? $types : array();
		$types[ PBN_Product::TYPE ] = __( 'Πακέτο', 'product-bundles' );
		return $types;
	}

	/** @param mixed $tabs Product data tabs. */
	public static function tabs( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			return $tabs;
		}
		if ( isset( $tabs['inventory']['class'] ) && is_array( $tabs['inventory']['class'] ) ) {
			$tabs['inventory']['class'][] = 'show_if_' . PBN_Product::TYPE;
		}
		if ( isset( $tabs['shipping']['class'] ) && is_array( $tabs['shipping']['class'] ) ) {
			$tabs['shipping']['class'][] = 'hide_if_' . PBN_Product::TYPE;
		}
		$tabs['pbn_bundle'] = array(
			'label'    => __( 'Περιεχόμενο πακέτου', 'product-bundles' ),
			'target'   => 'pbn_bundle_data',
			'class'    => array( 'show_if_' . PBN_Product::TYPE ),
			'priority' => 5,
		);
		return $tabs;
	}

	private static function is_product_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'product' === $screen->id;
	}

	public static function assets(): void {
		if ( ! self::is_product_screen() ) {
			return;
		}
		wp_enqueue_style( 'pbn-admin', PBN_URL . 'assets/admin.css', array(), PBN_VERSION );
		wp_enqueue_script( 'pbn-admin-product', PBN_URL . 'assets/admin-product.js', array( 'jquery', 'jquery-ui-sortable', 'wc-admin-product-meta-boxes', 'wc-enhanced-select' ), PBN_VERSION, true );
		wp_localize_script(
			'pbn-admin-product',
			'pbnProduct',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( self::NONCE ),
				'type'  => PBN_Product::TYPE,
				'i18n'  => array(
					'max'   => sprintf( /* translators: %d: max rows */ __( 'Ένα πακέτο έχει έως %d προϊόντα.', 'product-bundles' ), PBN_Bundle::MAX_ROWS ),
					'error' => __( 'Κάτι πήγε στραβά. Ξαναδοκίμασε.', 'product-bundles' ),
				),
			)
		);
	}

	/* =====================================================================
	 * Panel
	 * =================================================================== */

	public static function panel(): void {
		global $post, $product_object;
		$b = $product_object instanceof WC_Product ? $product_object : wc_get_product( $post ? $post->ID : 0 );
		if ( ! $b instanceof WC_Product ) {
			return;
		}
		$id    = (int) $b->get_id();
		$rows  = $b instanceof PBN_Product ? PBN_Bundle::rows( $b ) : array();
		$dtype = (string) $b->get_meta( PBN_Bundle::META_DTYPE, true, 'edit' );
		$dtype = 'price' === $dtype ? 'price' : 'percent';
		$disc  = $b->get_meta( PBN_Bundle::META_DISC, true, 'edit' );
		$disc  = is_numeric( $disc ) ? (string) $disc : (string) PBN_Settings::get()['discount'];
		$max   = (int) $b->get_meta( PBN_Bundle::META_MAX, true, 'edit' );

		echo '<div id="pbn_bundle_data" class="panel woocommerce_options_panel hidden">';
		wp_nonce_field( self::NONCE, 'pbn_product_nonce' );
		echo '<input type="hidden" name="pbn_present" value="1" />';

		echo '<div class="options_group pbn-items-group">';
		echo '<p class="form-field"><label for="pbn-add">' . esc_html__( 'Προσθήκη προϊόντος', 'product-bundles' ) . '</label>';
		echo '<select id="pbn-add" class="wc-product-search" style="width:60%" data-placeholder="' . esc_attr__( 'Αναζήτηση προϊόντος…', 'product-bundles' ) . '" data-action="woocommerce_json_search_products_and_variations" data-exclude_type="grouped,external,' . esc_attr( PBN_Product::TYPE ) . '" data-exclude="' . esc_attr( (string) $id ) . '" data-minimum_input_length="3"></select> ';
		echo '<button type="button" class="button pbn-add-row">' . esc_html__( 'Προσθήκη', 'product-bundles' ) . '</button></p>';

		echo '<div class="pbn-items" data-max="' . esc_attr( (string) PBN_Bundle::MAX_ROWS ) . '">';
		foreach ( $rows as $i => $row ) {
			echo self::row_html( $row, $i ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in row_html().
		}
		echo '</div>';
		echo '<p class="pbn-empty description"' . ( $rows ? ' hidden' : '' ) . '>' . esc_html__( 'Το πακέτο δεν έχει ακόμη προϊόντα. Πρόσθεσε απλά προϊόντα, παραλλαγές ή μεταβλητά προϊόντα (ο πελάτης διαλέγει παραλλαγή).', 'product-bundles' ) . '</p>';
		echo '</div>';

		echo '<div class="options_group">';
		echo '<p class="form-field"><label>' . esc_html__( 'Έκπτωση', 'product-bundles' ) . '</label>';
		echo '<span class="pbn-radios"><label class="pbn-radio"><input type="radio" name="pbn_dtype" value="percent" ' . checked( $dtype, 'percent', false ) . ' /> ' . esc_html__( 'Ποσοστό (%) επί της αξίας χωριστά', 'product-bundles' ) . '</label>';
		echo '<label class="pbn-radio"><input type="radio" name="pbn_dtype" value="price" ' . checked( $dtype, 'price', false ) . ' /> ' . esc_html__( 'Τελική τιμή πακέτου', 'product-bundles' ) . '</label></span></p>';
		woocommerce_wp_text_input(
			array(
				'id'          => 'pbn_disc',
				'label'       => __( 'Ποσοστό ή τιμή', 'product-bundles' ),
				'value'       => wc_format_localized_decimal( $disc ),
				'data_type'   => 'decimal',
				'desc_tip'    => true,
				'description' => __( 'Για ποσοστό: 0 έως 99. Για τελική τιμή: η τιμή με τη φορολογία όπως τη γράφεις στα προϊόντα. Όταν μια επιλογή του πελάτη κοστίζει περισσότερο, η διαφορά προστίθεται.', 'product-bundles' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => 'pbn_max',
				'label'             => __( 'Μέγιστα πακέτα', 'product-bundles' ),
				'value'             => $max > 0 ? (string) $max : '',
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'placeholder'       => __( 'Χωρίς όριο', 'product-bundles' ),
				'desc_tip'          => true,
				'description'       => __( 'Προαιρετικό, για περιορισμένες εκδόσεις: όταν πουληθούν τόσα πακέτα, το πακέτο γίνεται «Εξαντλημένο».', 'product-bundles' ),
			)
		);
		echo '</div>';

		echo '<div class="options_group"><div class="pbn-summary" aria-live="polite">' . self::summary_html( $b, $rows, $dtype, (float) wc_format_decimal( $disc ), $max ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in summary_html().
		echo '</div>';
	}

	/**
	 * One row of the contents editor.
	 *
	 * @param array $row Row.
	 * @param int   $i   Index.
	 */
	public static function row_html( array $row, int $i ): string {
		$p = wc_get_product( (int) $row['p'] );
		if ( ! $p instanceof WC_Product ) {
			return '';
		}
		$name = 'pbn_rows[' . $i . ']';
		$edit = get_edit_post_link( $p->is_type( 'variation' ) ? $p->get_parent_id() : $p->get_id() );
		$html = '<div class="pbn-row">';
		$html .= '<span class="pbn-row__handle" aria-hidden="true">&#8942;&#8942;</span>';
		$html .= '<input type="hidden" class="pbn-row__p" name="' . esc_attr( $name ) . '[p]" value="' . esc_attr( (string) $p->get_id() ) . '" />';
		$html .= '<span class="pbn-row__name">' . ( $edit ? '<a href="' . esc_url( $edit ) . '" target="_blank" rel="noopener">' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</a>' : esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) );
		if ( 'publish' !== $p->get_status() ) {
			$html .= ' <span class="pbn-warn-inline">' . esc_html( get_post_status_object( $p->get_status() ) ? get_post_status_object( $p->get_status() )->label : $p->get_status() ) . '</span>';
		}
		$html .= '</span>';
		$html .= '<label class="pbn-row__qty">' . esc_html__( 'Ποσότητα', 'product-bundles' ) . ' <input type="number" name="' . esc_attr( $name ) . '[q]" value="' . esc_attr( (string) (int) $row['q'] ) . '" min="1" max="' . esc_attr( (string) PBN_Bundle::MAX_QTY ) . '" step="1" class="small-text" /></label>';
		$html .= '<button type="button" class="button-link pbn-row__remove" aria-label="' . esc_attr__( 'Αφαίρεση', 'product-bundles' ) . '">&times;</button>';

		if ( $p->is_type( 'variable' ) ) {
			$allowed = $row['v'];
			$html   .= '<div class="pbn-row__vars"><span class="description">' . esc_html__( 'Ο πελάτης διαλέγει μία από τις επιλεγμένες παραλλαγές:', 'product-bundles' ) . '</span>';
			foreach ( $p->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( ! $v instanceof WC_Product_Variation ) {
					continue;
				}
				$on    = empty( $allowed ) || in_array( (int) $vid, $allowed, true );
				$label = wc_get_formatted_variation( $v, true, false, false );
				$note  = '';
				if ( ! PBN_Bundle::fully_specified( $v ) ) {
					$note = __( 'έχει «Οποιοδήποτε», δεν μπαίνει', 'product-bundles' );
				} elseif ( '' === $v->get_price() ) {
					$note = __( 'χωρίς τιμή', 'product-bundles' );
				} elseif ( ! $v->is_in_stock() ) {
					$note = __( 'εξαντλημένη', 'product-bundles' );
				}
				$html .= '<label class="pbn-var"><input type="checkbox" name="' . esc_attr( $name ) . '[v][]" value="' . esc_attr( (string) $vid ) . '" ' . checked( $on, true, false ) . ' /> ' . esc_html( wp_strip_all_tags( $label ) ) . ( '' !== $note ? ' <span class="pbn-warn-inline">' . esc_html( $note ) . '</span>' : '' ) . '</label>';
			}
			$html .= '</div>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Summary: value, price, discount, bundles available, warnings.
	 *
	 * @param WC_Product $b     Bundle (saved state; rows may be unsaved).
	 * @param array      $rows  Rows.
	 * @param string     $dtype Discount type.
	 * @param float      $disc  Discount value.
	 * @param int        $max   Most bundles.
	 */
	public static function summary_html( WC_Product $b, array $rows, string $dtype, float $disc, int $max ): string {
		if ( empty( $rows ) ) {
			return '<p class="description">' . esc_html__( 'Η σύνοψη εμφανίζεται όταν το πακέτο έχει προϊόντα.', 'product-bundles' ) . '</p>';
		}
		// A throw-away copy carries the unsaved values into the same code the shop uses.
		$tmp = new PBN_Product();
		$tmp->set_id( 0 );
		$tmp->update_meta_data( PBN_Bundle::META_ITEMS, wp_json_encode( $rows ) );
		$tmp->update_meta_data( PBN_Bundle::META_DTYPE, $dtype );
		$tmp->update_meta_data( PBN_Bundle::META_DISC, $disc );
		$tmp->update_meta_data( PBN_Bundle::META_MAX, $max );
		$tmp->set_total_sales( $b->get_total_sales( 'edit' ) );

		PBN_Bundle::flush_products();
		$min   = PBN_Bundle::quote( $tmp, array(), 'min' );
		$maxq  = PBN_Bundle::quote( $tmp, array(), 'max' );
		$avail = PBN_Bundle::availability( $tmp );

		$warn = array();
		foreach ( $rows as $row ) {
			$p = PBN_Bundle::product( (int) $row['p'] );
			if ( ! $p ) {
				continue;
			}
			$n = $p->get_name();
			if ( 'publish' !== $p->get_status() ) {
				/* translators: %s: product name */
				$warn[] = sprintf( __( '«%s» δεν είναι δημοσιευμένο: το πακέτο δεν πουλιέται.', 'product-bundles' ), $n );
			}
			if ( 'choice' === $row['m'] ) {
				if ( ! PBN_Bundle::choices( $row ) ) {
					/* translators: %s: product name */
					$warn[] = sprintf( __( '«%s» δεν έχει καμία παραλλαγή που να μπορεί να διαλέξει ο πελάτης.', 'product-bundles' ), $n );
				}
				continue;
			}
			if ( '' === $p->get_price() ) {
				/* translators: %s: product name */
				$warn[] = sprintf( __( '«%s» δεν έχει τιμή: το πακέτο δεν πουλιέται.', 'product-bundles' ), $n );
			} elseif ( ! $p->is_in_stock() ) {
				/* translators: %s: product name */
				$warn[] = sprintf( __( '«%s» είναι εξαντλημένο.', 'product-bundles' ), $n );
			}
		}

		$html = '<table class="pbn-sum"><tbody>';
		if ( $min['ok'] ) {
			$range = static function ( float $a, float $b ): string {
				return abs( $b - $a ) > 0.004 ? wc_price( $a ) . ' – ' . wc_price( $b ) : wc_price( $a );
			};
			$vmax  = $maxq['ok'] ? $maxq['display_value'] : $min['display_value'];
			$pmax  = $maxq['ok'] ? $maxq['display_price'] : $min['display_price'];
			$save  = $min['display_value'] - $min['display_price'];
			$pct   = $min['display_value'] > 0 ? round( $save / $min['display_value'] * 100 ) : 0;
			$html .= '<tr><th>' . esc_html__( 'Αξία χωριστά', 'product-bundles' ) . '</th><td>' . wp_kses_post( $range( $min['display_value'], $vmax ) ) . '</td></tr>';
			$html .= '<tr><th>' . esc_html__( 'Τιμή πακέτου', 'product-bundles' ) . '</th><td><strong>' . wp_kses_post( $range( $min['display_price'], $pmax ) ) . '</strong></td></tr>';
			$html .= '<tr><th>' . esc_html__( 'Έκπτωση', 'product-bundles' ) . '</th><td>' . wp_kses_post( wc_price( $save ) ) . ' (' . esc_html( (string) $pct ) . '%)</td></tr>';
			if ( 'price' === $dtype && $disc > $min['value'] + 0.004 ) {
				$warn[] = __( 'Η τιμή που έγραψες είναι μεγαλύτερη από την αξία χωριστά: το πακέτο πουλιέται στην αξία χωριστά.', 'product-bundles' );
			}
		} else {
			$html .= '<tr><th>' . esc_html__( 'Τιμή πακέτου', 'product-bundles' ) . '</th><td>' . esc_html__( 'Δεν υπολογίζεται (κάποιο προϊόν δεν έχει τιμή).', 'product-bundles' ) . '</td></tr>';
		}
		$label = -1 === $avail['count'] ? __( 'Χωρίς όριο (το απόθεμα δεν παρακολουθείται)', 'product-bundles' ) : (string) $avail['count'];
		$html .= '<tr><th>' . esc_html__( 'Διαθέσιμα πακέτα', 'product-bundles' ) . '</th><td>' . esc_html( $label ) . '</td></tr>';
		$html .= '<tr><th>' . esc_html__( 'Πωλήσεις πακέτου', 'product-bundles' ) . '</th><td>' . esc_html( (string) (int) $b->get_total_sales( 'edit' ) ) . '</td></tr>';
		$html .= '</tbody></table>';
		foreach ( $warn as $w ) {
			$html .= '<p class="pbn-warn">' . esc_html( $w ) . '</p>';
		}
		$html .= '<p class="description">' . esc_html__( 'Τιμή, απόθεμα και βάρος του πακέτου υπολογίζονται από τα προϊόντα και ενημερώνονται μόνα τους όταν αλλάζουν εκείνα.', 'product-bundles' ) . '</p>';
		return $html;
	}

	/* =====================================================================
	 * Save
	 * =================================================================== */

	/** Rows as posted (shape only; validate_rows() checks the products). */
	private static function posted_rows(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- nonce checked by the callers; sanitized by normalize_rows().
		$raw  = isset( $_POST['pbn_rows'] ) && is_array( $_POST['pbn_rows'] ) ? wp_unslash( $_POST['pbn_rows'] ) : array();
		$list = array();
		foreach ( $raw as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$list[] = array(
				'p' => isset( $r['p'] ) && is_scalar( $r['p'] ) ? absint( $r['p'] ) : 0,
				'q' => isset( $r['q'] ) && is_scalar( $r['q'] ) ? absint( $r['q'] ) : 1,
				// 'choice' keeps the ticked variations through normalize_rows();
				// validate_rows() then sets the real mode from the product type
				// (1.0.1: with 'fixed' every save dropped them, so the bundle
				// offered all variations).
				'm' => 'choice',
				'v' => isset( $r['v'] ) && is_array( $r['v'] ) ? array_map( 'absint', $r['v'] ) : array( -1 ),
			);
		}
		return $list;
	}

	/**
	 * Discount from the form.
	 *
	 * @return array { type, value, max }
	 */
	private static function posted_discount(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked by the callers.
		$type  = isset( $_POST['pbn_dtype'] ) && 'price' === $_POST['pbn_dtype'] ? 'price' : 'percent';
		$value = isset( $_POST['pbn_disc'] ) && is_scalar( $_POST['pbn_disc'] ) ? (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['pbn_disc'] ) ) ) : 0.0;
		$max   = isset( $_POST['pbn_max'] ) && is_scalar( $_POST['pbn_max'] ) ? absint( $_POST['pbn_max'] ) : 0;
		// phpcs:enable
		$value = 'percent' === $type ? min( 99.0, max( 0.0, $value ) ) : max( 0.0, $value );
		return array(
			'type'  => $type,
			'value' => $value,
			'max'   => $max,
		);
	}

	/**
	 * A row whose variations were all unticked keeps no variation: v = [-1]
	 * means "none ticked" (validate_rows() then drops all → the row would
	 * mean "all"). Unticked-all rows are dropped with a notice instead.
	 *
	 * @param array $list Rows as posted.
	 * @return array { rows, none }
	 */
	private static function split_none( array $list ): array {
		$rows = array();
		$none = 0;
		foreach ( $list as $r ) {
			if ( array( -1 ) === $r['v'] ) {
				$p = wc_get_product( $r['p'] );
				if ( $p && $p->is_type( 'variable' ) ) {
					++$none;
					continue;
				}
				$r['v'] = array();
			}
			$rows[] = $r;
		}
		return array(
			'rows' => $rows,
			'none' => $none,
		);
	}

	/** @param mixed $product Product being saved from the product screen. */
	public static function save( $product ): void {
		if ( ! $product instanceof PBN_Product || ! isset( $_POST['pbn_present'], $_POST['pbn_product_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['pbn_product_nonce'] ) ), self::NONCE ) || ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}
		$split = self::split_none( self::posted_rows() );
		$res   = PBN_Bundle::validate_rows( $split['rows'], (int) $product->get_id() );
		$d     = self::posted_discount();

		$product->update_meta_data( PBN_Bundle::META_ITEMS, wp_json_encode( $res['rows'] ) );
		$product->update_meta_data( PBN_Bundle::META_DTYPE, $d['type'] );
		$product->update_meta_data( PBN_Bundle::META_DISC, wc_format_decimal( $d['value'] ) );
		if ( $d['max'] > 0 ) {
			$product->update_meta_data( PBN_Bundle::META_MAX, $d['max'] );
		} else {
			$product->delete_meta_data( PBN_Bundle::META_MAX );
		}
		$dropped = $res['dropped'] + $split['none'];
		if ( $dropped > 0 ) {
			set_transient( 'pbn_dropped_' . get_current_user_id(), $dropped, 60 );
		}
	}

	public static function saved_notice(): void {
		$uid = get_current_user_id();
		$n   = $uid ? (int) get_transient( 'pbn_dropped_' . $uid ) : 0;
		if ( $n <= 0 || ! self::is_product_screen() ) {
			return;
		}
		delete_transient( 'pbn_dropped_' . $uid );
		echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html(
			sprintf(
				/* translators: %d: rows */
				__( 'Product Bundles: %d γραμμές του πακέτου δεν αποθηκεύτηκαν (προϊόν που δεν υπάρχει, δεν μπαίνει σε πακέτο, παραλλαγή με «Οποιοδήποτε» ή μεταβλητό χωρίς καμία επιλεγμένη παραλλαγή).', 'product-bundles' ),
				$n
			)
		) . '</p></div>';
	}

	/* =====================================================================
	 * AJAX (product screen)
	 * =================================================================== */

	private static function ajax_check(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Δεν επιτρέπεται.', 'product-bundles' ) ), 403 );
		}
	}

	/** A new row for a product picked in the search. */
	public static function ajax_row(): void {
		self::ajax_check();
		$pid  = isset( $_POST['product'] ) ? absint( wp_unslash( $_POST['product'] ) ) : 0;
		$self = isset( $_POST['bundle'] ) ? absint( wp_unslash( $_POST['bundle'] ) ) : 0;
		$idx  = isset( $_POST['index'] ) ? absint( wp_unslash( $_POST['index'] ) ) : 0;
		$res  = PBN_Bundle::validate_rows(
			array(
				array(
					'p' => $pid,
					'q' => 1,
				),
			),
			$self
		);
		if ( empty( $res['rows'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Αυτό το προϊόν δεν μπαίνει σε πακέτο (μόνο απλά, μεταβλητά και παραλλαγές με τιμή σε κάθε χαρακτηριστικό).', 'product-bundles' ) ), 400 );
		}
		wp_send_json_success( array( 'html' => self::row_html( $res['rows'][0], $idx ) ) );
	}

	/** The live summary for the unsaved form. */
	public static function ajax_summary(): void {
		self::ajax_check();
		$bid = isset( $_POST['bundle'] ) ? absint( wp_unslash( $_POST['bundle'] ) ) : 0;
		$b   = $bid ? wc_get_product( $bid ) : null;
		if ( ! $b instanceof WC_Product ) {
			$b = new PBN_Product();
		}
		$split = self::split_none( self::posted_rows() );
		$res   = PBN_Bundle::validate_rows( $split['rows'], $bid );
		$d     = self::posted_discount();
		wp_send_json_success( array( 'html' => self::summary_html( $b, $res['rows'], $d['type'], $d['value'], $d['max'] ) ) );
	}
}

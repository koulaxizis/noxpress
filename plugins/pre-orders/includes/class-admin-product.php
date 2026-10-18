<?php
/**
 * POR_Admin_Product — the pre-order fields in the product editor and the
 * product list (admin only).
 *
 *  - Simple products: General tab, «Pre-order», «Release date» (native
 *    date picker, strict YYYY-MM-DD + calendar check, future only), «Text
 *    for this product», a «Sale until release» helper that fills
 *    WooCommerce's own sale schedule, and a warning when the product is
 *    out of stock (WooCommerce would not sell it).
 *  - Variations: the same fields in each variation's panel.
 *  - A changed date with open pre-orders: «Update N open orders» (and
 *    «email the customers»), handled in the background (POR_Orders job).
 *  - Product list: column «Release» with a filter «On pre-order», quick
 *    edit and bulk edit for simple products.
 *
 * Saving: WooCommerce's own product form nonce, capability edit_product;
 * quick / bulk edit run inside WooCommerce's own handlers (nonce checked by
 * WordPress). Invalid dates are rejected with an admin notice and the old
 * value stays.
 */

defined( 'ABSPATH' ) || exit;

final class POR_Admin_Product {

	/** @var array Messages for the next screen (product edit). */
	private static $errors = array();

	public static function init(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'simple_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_simple' ), 20 );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'variation_fields' ), 20, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'save_variation' ), 20, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'columns' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'list_filter' ), 20 );
		add_action( 'pre_get_posts', array( __CLASS__, 'list_query' ) );

		add_action( 'woocommerce_product_quick_edit_end', array( __CLASS__, 'quick_fields' ) );
		add_action( 'woocommerce_product_bulk_edit_end', array( __CLASS__, 'bulk_fields' ) );
		add_action( 'woocommerce_product_quick_edit_save', array( __CLASS__, 'quick_save' ) );
		add_action( 'woocommerce_product_bulk_edit_save', array( __CLASS__, 'bulk_save' ) );
	}

	/* =====================================================================
	 * Product editor
	 * =================================================================== */

	public static function assets( $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->post_type || ! in_array( $screen->base, array( 'post', 'edit' ), true ) ) {
			return;
		}
		wp_enqueue_script( 'por-product', POR_URL . 'assets/product.js', array( 'jquery' ), POR_VERSION, true );
		wp_localize_script(
			'por-product',
			'porProduct',
			array(
				'needDate'  => __( 'Βάλε πρώτα την ημερομηνία κυκλοφορίας.', 'pre-orders' ),
				'needPrice' => __( 'Βάλε και την τιμή προσφοράς: ισχύει από σήμερα μέχρι την προηγούμενη μέρα της κυκλοφορίας.', 'pre-orders' ),
				'today'     => POR_Settings::today(),
			)
		);
		wp_add_inline_style( 'woocommerce_admin_styles', '.por-fields .por-warn{color:#b32d2e;}.por-fields .description{display:block;clear:both;}.column-por_release{width:9em;}' );
	}

	/** Shared field set (simple product or one variation). */
	private static function fields( WC_Product $p, string $suffix, bool $variation ): void {
		$r    = POR_Product::raw( $p );
		$id   = (int) $p->get_id();
		$name = static function ( string $f ) use ( $suffix ): string {
			return $f . $suffix;
		};
		$open = $id && $r['on'] ? POR_Orders::count_open( $id ) : 0;

		echo '<div class="por-fields' . ( $variation ? ' form-row form-row-full' : ' options_group' ) . '">';
		woocommerce_wp_checkbox(
			array(
				'id'            => $name( 'por_on' ),
				'name'          => $name( 'por_on' ),
				'label'         => __( 'Προπαραγγελία', 'pre-orders' ),
				'description'   => __( 'Το προϊόν πουλιέται ως προπαραγγελία μέχρι την ημερομηνία κυκλοφορίας.', 'pre-orders' ),
				'value'         => $r['on'] ? 'yes' : 'no',
				'cbvalue'       => 'yes',
				'wrapper_class' => $variation ? 'form-row form-row-full' : '',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => $name( 'por_date' ),
				'name'              => $name( 'por_date' ),
				'label'             => __( 'Ημερομηνία κυκλοφορίας', 'pre-orders' ),
				'type'              => 'date',
				'value'             => $r['date'],
				'class'             => 'por-date',
				'custom_attributes' => array(
					'min'     => wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ),
					'pattern' => '\d{4}-\d{2}-\d{2}',
				),
				'desc_tip'          => ! $variation,
				'description'       => __( 'Κενό = «Σύντομα διαθέσιμο», χωρίς ημερομηνία. Από αυτή τη μέρα το προϊόν πουλιέται κανονικά, χωρίς καμία ρύθμιση.', 'pre-orders' ),
				'wrapper_class'     => $variation ? 'form-row form-row-first' : '',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => $name( 'por_text' ),
				'name'              => $name( 'por_text' ),
				'label'             => __( 'Κείμενο για αυτό το προϊόν', 'pre-orders' ),
				'value'             => $r['text'],
				'placeholder'       => POR_Settings::text( 't_date' ), // The store's text, in the visitor language.
				'custom_attributes' => array( 'maxlength' => (string) POR_Product::TEXT_LEN ),
				'desc_tip'          => ! $variation,
				'description'       => __( 'Προαιρετικό. Αντί για το γενικό κείμενο διαθεσιμότητας· το {date} γίνεται η ημερομηνία.', 'pre-orders' ),
				'wrapper_class'     => $variation ? 'form-row form-row-last' : '',
			)
		);

		if ( ! $variation ) {
			echo '<p class="form-field por-sale-row"><label>&nbsp;</label><button type="button" class="button por-sale">' . esc_html__( 'Προσφορά μέχρι την κυκλοφορία', 'pre-orders' ) . '</button> ';
			echo '<span class="description">' . esc_html__( 'Γεμίζει τις ημερομηνίες της προσφοράς του WooCommerce: από σήμερα μέχρι την προηγούμενη μέρα της κυκλοφορίας.', 'pre-orders' ) . '</span></p>';
		}

		if ( $r['on'] && 'outofstock' === $p->get_stock_status( 'edit' ) ) {
			echo '<p class="form-field por-warn' . ( $variation ? ' form-row form-row-full' : '' ) . '">' . esc_html__( 'Το προϊόν είναι «Εξαντλημένο»: το WooCommerce δεν το πουλάει. Για προπαραγγελία βάλε «Σε απόθεμα» ή επίτρεψε backorders· για όριο τεμαχίων χρησιμοποίησε τη διαχείριση αποθέματος.', 'pre-orders' ) . '</p>';
		}

		if ( $open > 0 ) {
			echo '<p class="form-field por-open' . ( $variation ? ' form-row form-row-full' : '' ) . '"><label>&nbsp;</label>';
			echo '<label class="por-inline"><input type="checkbox" name="' . esc_attr( $name( 'por_update' ) ) . '" value="1" /> ';
			echo esc_html(
				sprintf(
					/* translators: %d: number of orders */
					_n( 'Αν αλλάξεις την ημερομηνία: ενημέρωση %d ανοιχτής παραγγελίας με τη νέα ημερομηνία', 'Αν αλλάξεις την ημερομηνία: ενημέρωση %d ανοιχτών παραγγελιών με τη νέα ημερομηνία', $open, 'pre-orders' ),
					$open
				)
			);
			echo '</label><br /><label class="por-inline"><input type="checkbox" name="' . esc_attr( $name( 'por_notify' ) ) . '" value="1" ' . disabled( ! POR_Emails::enabled( 'POR_Email_New_Date' ), true, false ) . ' /> ';
			echo esc_html__( 'και email «Νέα ημερομηνία κυκλοφορίας» στους πελάτες', 'pre-orders' );
			if ( ! POR_Emails::enabled( 'POR_Email_New_Date' ) ) {
				echo ' <span class="description">(' . esc_html__( 'το email είναι ανενεργό στις ρυθμίσεις emails του WooCommerce', 'pre-orders' ) . ')</span>';
			}
			echo '</label></p>';
		}
		echo '</div>';
	}

	public static function simple_fields(): void {
		global $product_object;
		if ( ! $product_object instanceof WC_Product ) {
			return;
		}
		echo '<div class="show_if_simple">';
		self::fields( $product_object, '', false );
		echo '</div>';
	}

	/**
	 * @param int     $loop           Variation index.
	 * @param array   $variation_data Unused.
	 * @param WP_Post $variation      Variation post.
	 */
	public static function variation_fields( $loop, $variation_data, $variation ): void {
		$v = $variation instanceof WP_Post ? wc_get_product( $variation->ID ) : null;
		if ( $v instanceof WC_Product ) {
			self::fields( $v, '[' . (int) $loop . ']', true );
		}
	}

	/**
	 * Reads one field set from $_POST and applies it.
	 *
	 * @param WC_Product $p      Product or variation.
	 * @param int|null   $index  Variation index, null for a simple product.
	 */
	private static function apply_post( WC_Product $p, ?int $index ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the product form nonce.
		$get = static function ( string $key ) use ( $index ): ?string {
			if ( null === $index ) {
				return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : null;
			}
			return isset( $_POST[ $key ][ $index ] ) && is_scalar( $_POST[ $key ][ $index ] ) ? (string) wp_unslash( $_POST[ $key ][ $index ] ) : null;
		};
		// phpcs:enable
		$date_raw = $get( 'por_date' );
		if ( null === $date_raw && null === $get( 'por_text' ) ) {
			return; // Another form (REST, a plugin): leave the fields alone.
		}
		$old  = POR_Product::raw( $p );
		$on   = 'yes' === $get( 'por_on' );
		$date = trim( sanitize_text_field( (string) $date_raw ) );
		$text = (string) $get( 'por_text' );

		if ( '' !== $date && ( ! POR_Settings::valid_date( $date ) || ( $date <= POR_Settings::today() && $date !== $old['date'] ) ) ) {
			self::$errors[] = sprintf(
				/* translators: 1: product name, 2: typed date */
				__( '%1$s: η ημερομηνία κυκλοφορίας «%2$s» δεν είναι έγκυρη μελλοντική ημερομηνία (ΕΕΕΕ-ΜΜ-ΗΗ). Κράτησα την προηγούμενη.', 'pre-orders' ),
				$p->get_name(),
				$date
			);
			$date = $old['date'];
		}

		POR_Product::set( $p, $on, $date, $text );

		// New date with open orders: update them if asked (after the save).
		$new = $on ? $date : '';
		if ( $old['on'] && $on && $new !== $old['date'] && '1' === $get( 'por_update' ) ) {
			$id     = (int) $p->get_id();
			$notify = '1' === $get( 'por_notify' );
			$from   = $old['date'];
			add_action(
				'shutdown',
				static function () use ( $id, $new, $from, $notify ) {
					POR_Orders::queue( 'date', $id, $new, $from, $notify );
				}
			);
		}
		if ( self::$errors ) {
			set_transient( 'por_product_err_' . get_current_user_id(), self::$errors, 60 );
		}
	}

	/** @param WC_Product $product Product being saved (WooCommerce checked the nonce). */
	public static function save_simple( $product ): void {
		if ( ! $product instanceof WC_Product || ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}
		if ( ! $product->is_type( 'simple' ) ) {
			// Another type now: a pre-order on the product level no longer applies.
			if ( 'yes' === $product->get_meta( POR_Product::M_ON, true, 'edit' ) ) {
				POR_Product::set( $product, false, '', '' );
			}
			return;
		}
		self::apply_post( $product, null );
	}

	/**
	 * @param WC_Product $variation Variation being saved.
	 * @param int        $i         Index in the posted arrays.
	 */
	public static function save_variation( $variation, $i ): void {
		if ( ! $variation instanceof WC_Product || ! current_user_can( 'edit_product', $variation->get_parent_id() ) ) {
			return;
		}
		self::apply_post( $variation, (int) $i );
	}

	/** Errors from the last save (date not valid). */
	public static function notices(): void {
		$key  = 'por_product_err_' . get_current_user_id();
		$msgs = get_transient( $key );
		if ( ! is_array( $msgs ) || ! $msgs ) {
			return;
		}
		delete_transient( $key );
		foreach ( $msgs as $m ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( (string) $m ) . '</p></div>';
		}
	}

	/* =====================================================================
	 * Product list: column, filter, quick / bulk edit
	 * =================================================================== */

	public static function columns( $cols ): array {
		$cols = is_array( $cols ) ? $cols : array();
		$out  = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'is_in_stock' === $k ) {
				$out['por_release'] = __( 'Κυκλοφορία', 'pre-orders' );
			}
		}
		if ( ! isset( $out['por_release'] ) ) {
			$out['por_release'] = __( 'Κυκλοφορία', 'pre-orders' );
		}
		return $out;
	}

	public static function column( $column, $post_id ): void {
		if ( 'por_release' !== $column ) {
			return;
		}
		$p = wc_get_product( (int) $post_id );
		if ( ! $p instanceof WC_Product ) {
			return;
		}
		$r = $p->is_type( 'simple' ) ? POR_Product::raw( $p ) : array(
			'on'   => false,
			'date' => '',
			'text' => '',
		);
		// Data for quick edit (read by assets/product.js).
		echo '<span class="hidden por-qe" data-on="' . esc_attr( $r['on'] ? 'yes' : 'no' ) . '" data-date="' . esc_attr( $r['date'] ) . '"></span>';

		$d = POR_Product::catalog_date( $p );
		if ( null === $d ) {
			if ( $r['on'] ) {
				echo '<span class="na">' . esc_html__( 'κυκλοφόρησε', 'pre-orders' ) . '</span>';
			} else {
				echo '<span class="na">–</span>';
			}
			return;
		}
		$label = '' === $d ? __( 'χωρίς ημερομηνία', 'pre-orders' ) : wp_date( (string) get_option( 'date_format', 'Y-m-d' ), POR_Settings::timestamp( $d ) );
		echo '<mark class="por-col">' . esc_html( $label ) . '</mark>';
		if ( $p->is_type( 'variable' ) ) {
			echo '<br /><small>' . esc_html__( 'σε παραλλαγές', 'pre-orders' ) . '</small>';
		}
	}

	public static function list_filter( $post_type ): void {
		if ( 'product' !== $post_type ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$cur = isset( $_GET['por_filter'] ) ? sanitize_key( wp_unslash( (string) $_GET['por_filter'] ) ) : '';
		echo '<select name="por_filter"><option value="">' . esc_html__( 'Προπαραγγελία: όλα', 'pre-orders' ) . '</option>';
		echo '<option value="active" ' . selected( $cur, 'active', false ) . '>' . esc_html__( 'Σε προπαραγγελία', 'pre-orders' ) . '</option></select>';
	}

	public static function list_query( $q ): void {
		global $pagenow;
		if ( 'edit.php' !== $pagenow || ! $q instanceof WP_Query || ! $q->is_main_query() || 'product' !== $q->get( 'post_type' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		if ( ! isset( $_GET['por_filter'] ) || 'active' !== sanitize_key( wp_unslash( (string) $_GET['por_filter'] ) ) ) {
			return;
		}
		$mq   = $q->get( 'meta_query' );
		$mq   = is_array( $mq ) ? $mq : array();
		$mq[] = array(
			'key'     => POR_Product::M_SORT,
			'value'   => POR_Settings::today(),
			'compare' => '>',
		);
		$q->set( 'meta_query', $mq ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	public static function quick_fields(): void {
		?>
		<br class="clear" />
		<div class="inline-edit-group por-quick">
			<label class="alignleft"><input type="checkbox" name="por_on" value="yes" /> <span class="checkbox-title"><?php esc_html_e( 'Προπαραγγελία', 'pre-orders' ); ?></span></label>
			<label class="alignleft"><span class="title"><?php esc_html_e( 'Κυκλοφορία', 'pre-orders' ); ?></span> <span class="input-text-wrap"><input type="date" name="por_date" value="" /></span></label>
			<input type="hidden" name="por_quick" value="1" />
		</div>
		<?php
	}

	public static function bulk_fields(): void {
		?>
		<div class="inline-edit-group por-bulk">
			<label class="alignleft">
				<span class="title"><?php esc_html_e( 'Προπαραγγελία', 'pre-orders' ); ?></span>
				<span class="input-text-wrap">
					<select name="por_bulk">
						<option value=""><?php esc_html_e( '— Χωρίς αλλαγή —', 'pre-orders' ); ?></option>
						<option value="on"><?php esc_html_e( 'Ναι, με αυτή την ημερομηνία', 'pre-orders' ); ?></option>
						<option value="off"><?php esc_html_e( 'Όχι', 'pre-orders' ); ?></option>
					</select>
				</span>
			</label>
			<label class="alignleft"><span class="title"><?php esc_html_e( 'Κυκλοφορία', 'pre-orders' ); ?></span> <span class="input-text-wrap"><input type="date" name="por_bulk_date" value="" /></span></label>
			<p class="description alignleft"><?php esc_html_e( 'Μόνο απλά προϊόντα· οι παραλλαγές ρυθμίζονται στη σελίδα του προϊόντος.', 'pre-orders' ); ?></p>
		</div>
		<?php
	}

	/** Quick edit (simple products). WordPress verified the inline-save nonce. */
	public static function quick_save( $product ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- inline-save nonce verified by WordPress.
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'simple' ) || ! isset( $_POST['por_quick'] ) || ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}
		$on   = isset( $_POST['por_on'] ) && 'yes' === $_POST['por_on'];
		$date = isset( $_POST['por_date'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['por_date'] ) ) ) : '';
		// phpcs:enable
		$old = POR_Product::raw( $product );
		if ( '' !== $date && ( ! POR_Settings::valid_date( $date ) || ( $date <= POR_Settings::today() && $date !== $old['date'] ) ) ) {
			$date = $old['date'];
		}
		POR_Product::set( $product, $on, $date );
		$product->save();
	}

	/** Bulk edit (simple products only). */
	public static function bulk_save( $product ): void {
		// The bulk edit form is sent by GET (WordPress's posts-filter form);
		// WordPress verified the bulk-posts nonce, WooCommerce its own.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$mode = isset( $_REQUEST['por_bulk'] ) && is_scalar( $_REQUEST['por_bulk'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['por_bulk'] ) ) : '';
		$date = isset( $_REQUEST['por_bulk_date'] ) && is_scalar( $_REQUEST['por_bulk_date'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_REQUEST['por_bulk_date'] ) ) ) : '';
		// phpcs:enable
		if ( ! in_array( $mode, array( 'on', 'off' ), true ) || ! $product instanceof WC_Product || ! $product->is_type( 'simple' ) || ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}
		if ( 'off' === $mode ) {
			POR_Product::set( $product, false, '' );
		} else {
			if ( '' !== $date && ( ! POR_Settings::valid_date( $date ) || $date <= POR_Settings::today() ) ) {
				return; // A bad date changes nothing.
			}
			POR_Product::set( $product, true, $date );
		}
		$product->save();
	}
}

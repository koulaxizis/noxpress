<?php
/**
 * VPK_Admin_Terms — fields where WooCommerce edits attributes, terms and
 * products (admin only).
 *
 *  - Products › Attributes (add / edit form): swatch type, shape, size,
 *    saved in vpk_settings['attrs'] (never in WooCommerce's attribute_type,
 *    so deactivating the plugin leaves every attribute a normal dropdown).
 *    Renames move the setting, deletes remove it.
 *  - Attribute terms (add / edit form): colour, second colour, image,
 *    short label (term meta vpk_*), and a preview column in the list.
 *  - Product data › Swatches (variable products): type per attribute,
 *    colours / images / labels per value of custom attributes, size guide.
 *    Saved on the product object with WooCommerce's own product save
 *    (nonce and capability checked by WooCommerce and WordPress).
 *
 * Our own nonce on the attribute and term forms, plus the capability that
 * WooCommerce / WordPress require for that screen.
 */

defined( 'ABSPATH' ) || exit;

final class VPK_Admin_Terms {

	const NONCE = 'vpk_terms';

	public static function init(): void {
		add_action( 'woocommerce_after_add_attribute_fields', array( __CLASS__, 'attribute_fields_add' ) );
		add_action( 'woocommerce_after_edit_attribute_fields', array( __CLASS__, 'attribute_fields_edit' ) );
		add_action( 'woocommerce_attribute_added', array( __CLASS__, 'attribute_saved' ), 10, 2 );
		add_action( 'woocommerce_attribute_updated', array( __CLASS__, 'attribute_updated' ), 10, 3 );
		add_action( 'woocommerce_attribute_deleted', array( __CLASS__, 'attribute_deleted' ), 10, 3 );

		add_action( 'admin_init', array( __CLASS__, 'term_hooks' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'product_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'product_save' ) );
	}

	/** Type => label msgid (admin). */
	public static function type_labels(): array {
		return array(
			'button'          => __( 'Κουμπί με κείμενο', 'variation-picker' ),
			'color'           => __( 'Χρώμα', 'variation-picker' ),
			'image'           => __( 'Εικόνα', 'variation-picker' ),
			'variation_image' => __( 'Εικόνα παραλλαγής (αυτόματα)', 'variation-picker' ),
			'radio'           => __( 'Λίστα (radio)', 'variation-picker' ),
			'select'          => __( 'Dropdown (όπως το WooCommerce)', 'variation-picker' ),
		);
	}

	/** Shape => label msgid. */
	public static function shape_labels(): array {
		return array(
			'square'  => __( 'Τετράγωνο', 'variation-picker' ),
			'rounded' => __( 'Στρογγυλεμένο', 'variation-picker' ),
			'circle'  => __( 'Κύκλος', 'variation-picker' ),
		);
	}

	/* =====================================================================
	 * Assets (attribute, term and product screens)
	 * =================================================================== */

	public static function assets( $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$terms   = in_array( $screen->base, array( 'edit-tags', 'term' ), true ) && isset( $screen->taxonomy ) && 0 === strpos( (string) $screen->taxonomy, 'pa_' );
		$product = 'post' === $screen->base && 'product' === $screen->post_type;
		$attrs   = 'product_page_product_attributes' === $screen->id;
		if ( ! $terms && ! $product && ! $attrs ) {
			return;
		}
		wp_enqueue_style( 'vpk-terms', VPK_URL . 'assets/terms.css', array( 'wp-color-picker' ), VPK_VERSION );
		if ( $terms || $product ) {
			wp_enqueue_media();
			wp_enqueue_script( 'vpk-terms', VPK_URL . 'assets/terms.js', array( 'jquery', 'wp-color-picker' ), VPK_VERSION, true );
			wp_localize_script(
				'vpk-terms',
				'vpkTerms',
				array(
					'choose' => __( 'Επιλογή εικόνας', 'variation-picker' ),
					'use'    => __( 'Χρήση εικόνας', 'variation-picker' ),
				)
			);
		}
	}

	/* =====================================================================
	 * Products › Attributes
	 * =================================================================== */

	public static function attribute_fields_add(): void {
		self::attribute_fields( array( 'type' => '', 'shape' => '', 'size' => 0 ), false );
	}

	public static function attribute_fields_edit(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which attribute WooCommerce's form edits.
		$id  = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$att = $id ? wc_get_attribute( $id ) : null;
		$row = $att ? VPK_Settings::attr( (string) $att->slug ) : VPK_Settings::attr_row( array() );
		self::attribute_fields( $row, true );
	}

	/**
	 * The three fields, as a table row (edit) or form-field divs (add).
	 *
	 * @param array $row  { type, shape, size }.
	 * @param bool  $edit Edit form (table layout).
	 */
	private static function attribute_fields( array $row, bool $edit ): void {
		$type = '<select name="vpk_attr_type" id="vpk_attr_type"><option value="">' . esc_html__( 'Γενική ρύθμιση (Variation Picker)', 'variation-picker' ) . '</option>';
		foreach ( self::type_labels() as $k => $label ) {
			$type .= '<option value="' . esc_attr( $k ) . '" ' . selected( $row['type'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$type .= '</select>';

		$shape = '<select name="vpk_attr_shape" id="vpk_attr_shape"><option value="">' . esc_html__( 'Γενική ρύθμιση', 'variation-picker' ) . '</option>';
		foreach ( self::shape_labels() as $k => $label ) {
			$shape .= '<option value="' . esc_attr( $k ) . '" ' . selected( $row['shape'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$shape .= '</select>';

		$size = '<input type="number" name="vpk_attr_size" id="vpk_attr_size" min="20" max="80" step="1" value="' . ( $row['size'] ? (int) $row['size'] : '' ) . '" placeholder="' . (int) VPK_Settings::get()['size'] . '" /> px';
		$help = esc_html__( 'Πώς εμφανίζεται το χαρακτηριστικό στη σελίδα προϊόντος (Variation Picker). Χρώματα και εικόνες ορίζονται σε κάθε τιμή.', 'variation-picker' );
		$nonce = wp_nonce_field( self::NONCE, 'vpk_terms_nonce', true, false );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
		if ( $edit ) {
			echo '<tr class="form-field vpk-attr-fields"><th scope="row" valign="top"><label for="vpk_attr_type">' . esc_html__( 'Swatches', 'variation-picker' ) . '</label></th><td>'
				. $nonce . $type . ' ' . $shape . ' ' . $size . '<p class="description">' . $help . '</p></td></tr>';
		} else {
			echo '<div class="form-field vpk-attr-fields"><label for="vpk_attr_type">' . esc_html__( 'Swatches', 'variation-picker' ) . '</label>'
				. $nonce . $type . ' ' . $shape . ' ' . $size . '<p class="description">' . $help . '</p></div>';
		}
		// phpcs:enable
	}

	/** True when the posted attribute form carries our nonce and the user may manage attributes. */
	private static function attribute_post_ok(): bool {
		return isset( $_POST['vpk_terms_nonce'] )
			&& wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vpk_terms_nonce'] ) ), self::NONCE )
			&& current_user_can( 'manage_product_terms' );
	}

	/**
	 * @param mixed $id   Attribute id.
	 * @param mixed $data Attribute data.
	 */
	public static function attribute_saved( $id, $data = array() ): void {
		if ( ! self::attribute_post_ok() ) {
			return;
		}
		$att = wc_get_attribute( (int) $id );
		if ( ! $att ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in attribute_post_ok().
		$raw = array(
			'type'  => isset( $_POST['vpk_attr_type'] ) ? sanitize_key( wp_unslash( $_POST['vpk_attr_type'] ) ) : '',
			'shape' => isset( $_POST['vpk_attr_shape'] ) ? sanitize_key( wp_unslash( $_POST['vpk_attr_shape'] ) ) : '',
			'size'  => isset( $_POST['vpk_attr_size'] ) ? absint( $_POST['vpk_attr_size'] ) : 0,
		);
		// phpcs:enable
		$s   = VPK_Settings::get();
		$row = VPK_Settings::attr_row( $raw );
		$tax = (string) $att->slug;
		if ( '' === $row['type'] && '' === $row['shape'] && ! $row['size'] ) {
			unset( $s['attrs'][ $tax ] );
		} else {
			$s['attrs'][ $tax ] = $row;
		}
		VPK_Settings::save( $s );
	}

	/**
	 * Rename: move the setting to the new taxonomy name, then save the posted fields.
	 *
	 * @param mixed $id       Attribute id.
	 * @param mixed $data     New data.
	 * @param mixed $old_slug Old slug (without pa_).
	 */
	public static function attribute_updated( $id, $data = array(), $old_slug = '' ): void {
		$att = wc_get_attribute( (int) $id );
		if ( $att && is_string( $old_slug ) && '' !== $old_slug ) {
			$old = wc_attribute_taxonomy_name( $old_slug );
			$new = (string) $att->slug;
			$s   = VPK_Settings::get();
			if ( $old !== $new && isset( $s['attrs'][ $old ] ) ) {
				$s['attrs'][ $new ] = $s['attrs'][ $old ];
				unset( $s['attrs'][ $old ] );
				if ( $s['shop']['attr'] === $old ) {
					$s['shop']['attr'] = $new;
				}
				VPK_Settings::save( $s );
			}
		}
		self::attribute_saved( $id, $data );
	}

	/**
	 * @param mixed $id       Attribute id.
	 * @param mixed $name     Attribute name.
	 * @param mixed $taxonomy Taxonomy.
	 */
	public static function attribute_deleted( $id, $name = '', $taxonomy = '' ): void {
		$s = VPK_Settings::get();
		if ( is_string( $taxonomy ) && isset( $s['attrs'][ $taxonomy ] ) ) {
			unset( $s['attrs'][ $taxonomy ] );
			VPK_Settings::save( $s );
		}
	}

	/* =====================================================================
	 * Attribute terms
	 * =================================================================== */

	public static function term_hooks(): void {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return;
		}
		foreach ( wc_get_attribute_taxonomies() as $a ) {
			$tax = wc_attribute_taxonomy_name( $a->attribute_name );
			add_action( $tax . '_add_form_fields', array( __CLASS__, 'term_fields_add' ) );
			add_action( $tax . '_edit_form_fields', array( __CLASS__, 'term_fields_edit' ), 10, 2 );
			add_action( 'created_' . $tax, array( __CLASS__, 'term_save' ) );
			add_action( 'edited_' . $tax, array( __CLASS__, 'term_save' ) );
			add_filter( 'manage_edit-' . $tax . '_columns', array( __CLASS__, 'term_columns' ) );
			add_filter( 'manage_' . $tax . '_custom_column', array( __CLASS__, 'term_column' ), 10, 3 );
		}
	}

	/**
	 * The four inputs of a value look (term form and product tab).
	 *
	 * @param string $name Input name prefix.
	 * @param array  $v    Look { color, color2, image, label }.
	 */
	public static function value_inputs( string $name, array $v ): string {
		$src = $v['image'] ? (string) wp_get_attachment_image_url( $v['image'], 'thumbnail' ) : '';
		return '<span class="vpk-fields">'
			. '<label class="vpk-field"><span>' . esc_html__( 'Χρώμα', 'variation-picker' ) . '</span><input type="text" class="vpk-color" name="' . esc_attr( $name ) . '[color]" value="' . esc_attr( $v['color'] ) . '" /></label>'
			. '<label class="vpk-field"><span>' . esc_html__( 'Δεύτερο χρώμα (δίχρωμο)', 'variation-picker' ) . '</span><input type="text" class="vpk-color" name="' . esc_attr( $name ) . '[color2]" value="' . esc_attr( $v['color2'] ) . '" /></label>'
			. '<span class="vpk-field vpk-image-field"><span>' . esc_html__( 'Εικόνα', 'variation-picker' ) . '</span>'
			. '<img class="vpk-image-preview" src="' . esc_url( $src ) . '" alt=""' . ( '' === $src ? ' hidden' : '' ) . ' />'
			. '<input type="hidden" class="vpk-image-id" name="' . esc_attr( $name ) . '[image]" value="' . ( $v['image'] ? (int) $v['image'] : '' ) . '" />'
			. '<button type="button" class="button vpk-image-pick">' . esc_html__( 'Επιλογή εικόνας', 'variation-picker' ) . '</button> '
			. '<button type="button" class="button-link vpk-image-clear"' . ( '' === $src ? ' hidden' : '' ) . '>' . esc_html__( 'Αφαίρεση', 'variation-picker' ) . '</button></span>'
			. '<label class="vpk-field"><span>' . esc_html__( 'Σύντομη ετικέτα (π.χ. XL)', 'variation-picker' ) . '</span><input type="text" name="' . esc_attr( $name ) . '[label]" value="' . esc_attr( $v['label'] ) . '" maxlength="20" /></label>'
			. '</span>';
	}

	/** @param mixed $taxonomy Taxonomy. */
	public static function term_fields_add( $taxonomy ): void {
		echo '<div class="form-field vpk-term-fields"><label>' . esc_html__( 'Swatch', 'variation-picker' ) . '</label>';
		wp_nonce_field( self::NONCE, 'vpk_terms_nonce' );
		echo self::value_inputs( 'vpk_term', VPK_Swatch::value_row( array() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in value_inputs().
		echo '<p class="description">' . esc_html__( 'Το χρώμα ή η εικόνα φαίνεται όταν το χαρακτηριστικό έχει τύπο «Χρώμα» ή «Εικόνα»· η σύντομη ετικέτα στα κουμπιά με κείμενο.', 'variation-picker' ) . '</p></div>';
	}

	/**
	 * @param mixed $term     Term.
	 * @param mixed $taxonomy Taxonomy.
	 */
	public static function term_fields_edit( $term, $taxonomy = '' ): void {
		if ( ! $term instanceof WP_Term ) {
			return;
		}
		echo '<tr class="form-field vpk-term-fields"><th scope="row">' . esc_html__( 'Swatch', 'variation-picker' ) . '</th><td>';
		wp_nonce_field( self::NONCE, 'vpk_terms_nonce' );
		echo self::value_inputs( 'vpk_term', VPK_Swatch::term( (int) $term->term_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in value_inputs().
		echo '<p class="description">' . esc_html__( 'Το χρώμα ή η εικόνα φαίνεται όταν το χαρακτηριστικό έχει τύπο «Χρώμα» ή «Εικόνα»· η σύντομη ετικέτα στα κουμπιά με κείμενο.', 'variation-picker' ) . '</p></td></tr>';
	}

	/** @param mixed $term_id Term id. */
	public static function term_save( $term_id ): void {
		if ( ! isset( $_POST['vpk_terms_nonce'], $_POST['vpk_term'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vpk_terms_nonce'] ) ), self::NONCE )
			|| ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by VPK_Swatch::value_row().
		$raw = is_array( $_POST['vpk_term'] ) ? wp_unslash( $_POST['vpk_term'] ) : array();
		VPK_Swatch::save_term( (int) $term_id, $raw );
	}

	/** @param mixed $cols Columns. */
	public static function term_columns( $cols ) {
		$cols = is_array( $cols ) ? $cols : array();
		$out  = array();
		foreach ( $cols as $k => $label ) {
			if ( 'name' === $k ) {
				$out['vpk'] = '<span class="screen-reader-text">' . esc_html__( 'Swatch', 'variation-picker' ) . '</span>';
			}
			$out[ $k ] = $label;
		}
		return $out;
	}

	/**
	 * @param mixed $content Content.
	 * @param mixed $column  Column.
	 * @param mixed $term_id Term id.
	 */
	public static function term_column( $content, $column, $term_id ) {
		if ( 'vpk' !== $column ) {
			return $content;
		}
		return self::preview( VPK_Swatch::term( (int) $term_id ) );
	}

	/**
	 * A small admin preview of a look.
	 *
	 * @param array $v Look.
	 */
	public static function preview( array $v ): string {
		if ( $v['image'] ) {
			$src = (string) wp_get_attachment_image_url( $v['image'], 'thumbnail' );
			if ( '' !== $src ) {
				return '<img class="vpk-prev vpk-prev--img" src="' . esc_url( $src ) . '" alt="" />';
			}
		}
		if ( '' !== $v['color'] ) {
			$bg = '' !== $v['color2'] ? 'linear-gradient(135deg,' . $v['color'] . ' 50%,' . $v['color2'] . ' 50%)' : $v['color'];
			return '<span class="vpk-prev" style="background:' . esc_attr( $bg ) . '"></span>';
		}
		if ( '' !== $v['label'] ) {
			return '<span class="vpk-prev vpk-prev--label">' . esc_html( $v['label'] ) . '</span>';
		}
		return '';
	}

	/* =====================================================================
	 * Product data › Swatches
	 * =================================================================== */

	/** @param mixed $tabs Tabs. */
	public static function product_tab( $tabs ) {
		$tabs        = is_array( $tabs ) ? $tabs : array();
		$tabs['vpk'] = array(
			'label'    => __( 'Swatches', 'variation-picker' ),
			'target'   => 'vpk_product_data',
			'class'    => array( 'show_if_variable' ),
			'priority' => 65,
		);
		return $tabs;
	}

	public static function product_panel(): void {
		global $post;
		$product = $post instanceof WP_Post ? wc_get_product( $post->ID ) : null;
		echo '<div id="vpk_product_data" class="panel woocommerce_options_panel hidden">';
		if ( ! $product ) {
			echo '</div>';
			return;
		}
		$conf  = VPK_Swatch::product( (int) $product->get_id() );
		$attrs = array();
		foreach ( $product->get_attributes() as $a ) {
			if ( $a instanceof WC_Product_Attribute && $a->get_variation() ) {
				$attrs[] = $a;
			}
		}

		echo '<div class="options_group vpk-product">';
		if ( ! $attrs ) {
			echo '<p class="vpk-note">' . esc_html__( 'Αποθήκευσε πρώτα τα χαρακτηριστικά που χρησιμοποιούνται για παραλλαγές· μετά εμφανίζονται εδώ.', 'variation-picker' ) . '</p>';
		}
		foreach ( $attrs as $a ) {
			$name = $a->get_name();
			$key  = sanitize_title( $name );
			$cur  = isset( $conf['attrs'][ $key ] ) ? $conf['attrs'][ $key ] : array(
				'type'   => '',
				'values' => array(),
			);
			$base = 'vpk_p[attrs][' . $key . ']';
			echo '<div class="vpk-product-attr"><p class="form-field"><label>' . esc_html( wc_attribute_label( $name, $product ) ) . '</label>';
			echo '<select name="' . esc_attr( $base ) . '[type]"><option value="">' . esc_html__( 'Ρύθμιση του χαρακτηριστικού', 'variation-picker' ) . '</option>';
			foreach ( self::type_labels() as $k => $label ) {
				echo '<option value="' . esc_attr( $k ) . '" ' . selected( $cur['type'], $k, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></p>';
			if ( ! $a->is_taxonomy() ) {
				echo '<details class="vpk-values"><summary>' . esc_html__( 'Χρώματα, εικόνες και ετικέτες των τιμών', 'variation-picker' ) . '</summary>';
				// Values go by index (a value may contain "[" or "]"), with the value in a hidden field.
				foreach ( array_values( $a->get_options() ) as $i => $opt ) {
					$opt = (string) $opt;
					$v   = isset( $cur['values'][ $opt ] ) ? $cur['values'][ $opt ] : VPK_Swatch::value_row( array() );
					echo '<div class="vpk-value-row"><strong>' . esc_html( $opt ) . '</strong>';
					echo '<input type="hidden" name="' . esc_attr( $base . '[vals][' . $i . '][value]' ) . '" value="' . esc_attr( $opt ) . '" />';
					echo self::value_inputs( $base . '[vals][' . $i . ']', $v ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in value_inputs().
					echo '</div>';
				}
				echo '</details>';
			} else {
				$url = admin_url( 'edit-tags.php?taxonomy=' . rawurlencode( $name ) . '&post_type=product' );
				echo '<p class="vpk-note"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Χρώματα και εικόνες ορίζονται στις τιμές του χαρακτηριστικού', 'variation-picker' ) . '</a></p>';
			}
			echo '</div>';
		}

		$guides = VPK_Settings::guides();
		if ( $guides ) {
			echo '<p class="form-field"><label for="vpk_p_guide">' . esc_html__( 'Οδηγός μεγεθών', 'variation-picker' ) . '</label><select id="vpk_p_guide" name="vpk_p[guide]">';
			echo '<option value="">' . esc_html__( 'Αυτόματα (από τις κατηγορίες)', 'variation-picker' ) . '</option>';
			echo '<option value="none" ' . selected( $conf['guide'], 'none', false ) . '>' . esc_html__( 'Κανένας', 'variation-picker' ) . '</option>';
			foreach ( $guides as $g ) {
				echo '<option value="' . esc_attr( $g['id'] ) . '" ' . selected( $conf['guide'], $g['id'], false ) . '>' . esc_html( $g['title_el'] ) . '</option>';
			}
			echo '</select></p>';
		}
		echo '<input type="hidden" name="vpk_p[present]" value="1" />';
		echo '</div></div>';
	}

	/**
	 * woocommerce_admin_process_product_object: the override config.
	 *
	 * @param mixed $product Product being saved.
	 */
	public static function product_save( $product ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- WooCommerce verified its meta box nonce; validated by VPK_Swatch::sanitize_product().
		if ( ! $product instanceof WC_Product || ! isset( $_POST['vpk_p'] ) || ! is_array( $_POST['vpk_p'] ) || ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}
		$raw = wp_unslash( $_POST['vpk_p'] );
		// phpcs:enable
		if ( isset( $raw['attrs'] ) && is_array( $raw['attrs'] ) ) {
			foreach ( $raw['attrs'] as $key => $a ) {
				if ( ! is_array( $a ) ) {
					continue;
				}
				$values = array();
				if ( isset( $a['vals'] ) && is_array( $a['vals'] ) ) {
					foreach ( $a['vals'] as $row ) {
						if ( is_array( $row ) && isset( $row['value'] ) && is_string( $row['value'] ) ) {
							$values[ $row['value'] ] = $row;
						}
					}
				}
				$raw['attrs'][ $key ] = array(
					'type'   => isset( $a['type'] ) ? $a['type'] : '',
					'values' => $values,
				);
			}
		}
		VPK_Swatch::set_product( $product, $raw );
	}
}

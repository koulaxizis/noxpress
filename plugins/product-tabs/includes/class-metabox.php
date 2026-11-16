<?php
/**
 * PTB_Metabox — the "Product tabs" box on the product screen.
 *
 *  - The product's tabs: title, type (text / video), video address and
 *    role, content (the classic editor, so HTML and shortcodes stay as
 *    written), priority (the place among the page's tabs), shown,
 *    "also on the other formats of the work" (when Product Formats runs).
 *    Add and remove in the box (assets/admin.js); the order follows the
 *    priority.
 *  - The shared tabs that match this product: hide here, or own text
 *    instead of the shared one.
 *
 * Saving: woocommerce_process_product_meta, own nonce, edit_post on the
 * product; HTML as written for users with unfiltered_html, else
 * wp_kses_post (Bible §11). The page cache of the product is cleared.
 */

defined( 'ABSPATH' ) || exit;

final class PTB_Metabox {

	const NONCE       = 'ptb_metabox';
	const NONCE_FIELD = 'ptb_mb_nonce';

	public static function init(): void {
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function add(): void {
		if ( ! current_user_can( Product_Tabs::CAP ) ) {
			return;
		}
		add_meta_box( 'ptb-tabs', __( 'Καρτέλες προϊόντος', 'product-tabs' ), array( __CLASS__, 'render' ), 'product', 'normal', 'default' );
	}

	public static function assets( $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->id || ! current_user_can( Product_Tabs::CAP ) ) {
			return;
		}
		wp_enqueue_editor();
		wp_enqueue_style( 'ptb-metabox', PTB_URL . 'assets/metabox.css', array(), PTB_VERSION );
		wp_enqueue_script( 'ptb-metabox', PTB_URL . 'assets/admin.js', array( 'jquery' ), PTB_VERSION, true );
		wp_localize_script(
			'ptb-metabox',
			'PTB_MB',
			array(
				'remove' => __( 'Να αφαιρεθεί αυτή η καρτέλα;', 'product-tabs' ),
				'max'    => PTB_Tabs::MAX_TABS,
			)
		);
	}

	/** Whether Product Formats is there (the "share" switch). */
	private static function formats(): bool {
		return function_exists( 'pfm_get_work' );
	}

	public static function render( $post ): void {
		$pid  = $post instanceof WP_Post ? (int) $post->ID : 0;
		$data = PTB_Tabs::get( $pid );
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		echo '<input type="hidden" name="ptb_mb" value="1" />';

		if ( 'off' === PTB_Settings::get()['mode'] ) {
			echo '<p class="ptb-mb-note">' . esc_html__( 'Το Product Tabs είναι ανενεργό: οι καρτέλες αποθηκεύονται, αλλά δεν φαίνονται ακόμα στο κατάστημα.', 'product-tabs' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . PTB_Admin_UI::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'product-tabs' ) . '</a></p>';
		}
		echo '<p class="description">' . esc_html__( 'Η σειρά ακολουθεί τη θέση: Περιγραφή 10, Πρόσθετες πληροφορίες 20, Κριτικές 30. Μικρότερος αριθμός = πιο αριστερά.', 'product-tabs' ) . '</p>';

		echo '<div class="ptb-mb-list" data-next="' . (int) count( $data['tabs'] ) . '">';
		$i = 0;
		foreach ( $data['tabs'] as $t ) {
			self::row( (string) $i, $t );
			++$i;
		}
		echo '</div>';

		echo '<p><button type="button" class="button ptb-mb-add">' . esc_html__( '+ Νέα καρτέλα', 'product-tabs' ) . '</button></p>';
		echo '<script type="text/html" id="tmpl-ptb-mb-row">';
		self::row( '__i__', PTB_Tabs::tab_defaults(), true );
		echo '</script>';

		// Shared tabs that match.
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
		$shared  = $product ? PTB_Tabs::shared_for( $product ) : array();
		if ( $shared ) {
			echo '<h4 class="ptb-mb-h">' . esc_html__( 'Κοινές καρτέλες σε αυτό το προϊόν', 'product-tabs' ) . '</h4>';
			foreach ( $shared as $s ) {
				$id   = $s['id'];
				$over = isset( $data['over'][ $id ] ) ? $data['over'][ $id ] : '';
				echo '<div class="ptb-mb-shared">';
				echo '<strong>' . esc_html( $s['title_el'] ) . '</strong>';
				if ( ! $s['on'] ) {
					echo ' <em>(' . esc_html__( 'ανενεργή', 'product-tabs' ) . ')</em>';
				}
				echo '<label class="ptb-mb-inline"><input type="checkbox" name="ptb_hide[]" value="' . esc_attr( $id ) . '" ' . checked( in_array( $id, $data['hide'], true ), true, false ) . ' /> ' . esc_html__( 'Να μη φαίνεται σε αυτό το προϊόν', 'product-tabs' ) . '</label>';
				if ( 'text' === $s['type'] ) {
					echo '<label class="ptb-mb-block">' . esc_html__( 'Δικό του κείμενο αντί για το κοινό (κενό = το κοινό)', 'product-tabs' );
					echo '<textarea name="ptb_over[' . esc_attr( $id ) . ']" rows="3" class="large-text">' . esc_textarea( $over ) . '</textarea></label>';
				}
				echo '</div>';
			}
		}
	}

	/**
	 * One tab row; $i is the index or the template placeholder.
	 *
	 * @param string $i   Row index.
	 * @param array  $t   Tab.
	 * @param bool   $tpl Template row (no editor yet: admin.js starts it).
	 */
	private static function row( string $i, array $t, bool $tpl = false ): void {
		$n    = 'ptb_tabs[' . $i . ']';
		$ed   = 'ptbc' . $i;
		$type = $t['type'];
		echo '<div class="ptb-mb-row" data-type="' . esc_attr( $type ) . '" data-editor="' . esc_attr( $ed ) . '">';
		echo '<input type="hidden" name="' . esc_attr( $n ) . '[id]" value="' . esc_attr( $t['id'] ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( $n ) . '[src]" value="' . esc_attr( $t['src'] ) . '" />';
		echo '<div class="ptb-mb-head">';
		echo '<label class="ptb-mb-title">' . esc_html__( 'Τίτλος', 'product-tabs' ) . ' <input type="text" name="' . esc_attr( $n ) . '[title]" value="' . esc_attr( $t['title'] ) . '" maxlength="' . (int) PTB_Settings::MAX_TITLE . '" /></label>';
		echo '<label>' . esc_html__( 'Είδος', 'product-tabs' ) . ' <select name="' . esc_attr( $n ) . '[type]" class="ptb-mb-type">'
			. '<option value="text" ' . selected( $type, 'text', false ) . '>' . esc_html__( 'Κείμενο', 'product-tabs' ) . '</option>'
			. '<option value="video" ' . selected( $type, 'video', false ) . '>' . esc_html__( 'Βίντεο', 'product-tabs' ) . '</option>'
			. '</select></label>';
		echo '<label>' . esc_html__( 'Θέση', 'product-tabs' ) . ' <input type="number" class="small-text" min="1" max="200" name="' . esc_attr( $n ) . '[priority]" value="' . (int) $t['priority'] . '" /></label>';
		echo '<label class="ptb-mb-inline"><input type="checkbox" name="' . esc_attr( $n ) . '[on]" value="1" ' . checked( $t['on'], true, false ) . ' /> ' . esc_html__( 'Εμφανίζεται', 'product-tabs' ) . '</label>';
		if ( self::formats() ) {
			echo '<label class="ptb-mb-inline"><input type="checkbox" name="' . esc_attr( $n ) . '[share]" value="1" ' . checked( $t['share'], true, false ) . ' /> ' . esc_html__( 'Και στις άλλες μορφές του έργου', 'product-tabs' ) . '</label>';
		}
		echo '<button type="button" class="button-link ptb-mb-remove">' . esc_html__( 'Αφαίρεση', 'product-tabs' ) . '</button>';
		echo '</div>';

		echo '<div class="ptb-mb-video">';
		echo '<label>' . esc_html__( 'Διεύθυνση YouTube ή Vimeo', 'product-tabs' ) . ' <input type="text" class="regular-text" name="' . esc_attr( $n ) . '[video]" value="' . esc_attr( PTB_Tabs::video_url( $t['video'] ) ) . '" placeholder="https://www.youtube.com/watch?v=…" /></label>';
		echo '<label>' . esc_html__( 'Ρόλος', 'product-tabs' ) . ' <select name="' . esc_attr( $n ) . '[role]">'
			. '<option value="" ' . selected( $t['role'], '', false ) . '>' . esc_html__( '— Κανένας —', 'product-tabs' ) . '</option>'
			. '<option value="trailer" ' . selected( $t['role'], 'trailer', false ) . '>' . esc_html__( 'Trailer', 'product-tabs' ) . '</option>'
			. '<option value="presentation" ' . selected( $t['role'], 'presentation', false ) . '>' . esc_html__( 'Παρουσίαση', 'product-tabs' ) . '</option>'
			. '</select></label>';
		echo '<p class="description">' . esc_html__( 'Κενός τίτλος = ο τίτλος του ρόλου από τις ρυθμίσεις. Το Home Sections διαλέγει το trailer και την παρουσίαση από εδώ.', 'product-tabs' ) . '</p>';
		echo '</div>';

		echo '<div class="ptb-mb-text">';
		if ( $tpl ) {
			echo '<textarea id="' . esc_attr( $ed ) . '" name="' . esc_attr( $n ) . '[content]" rows="8" class="large-text"></textarea>';
		} else {
			wp_editor(
				$t['content'],
				$ed,
				array(
					'textarea_name' => $n . '[content]',
					'textarea_rows' => 8,
					'media_buttons' => true,
					'teeny'         => false,
				)
			);
		}
		echo '</div>';
		echo '</div>';
	}

	/** woocommerce_process_product_meta. */
	public static function save( $post_id ): void {
		$post_id = (int) $post_id;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified right here.
		if ( ! isset( $_POST['ptb_mb'], $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( Product_Tabs::CAP ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by PTB_Tabs::sanitize().
		$rows = isset( $_POST['ptb_tabs'] ) && is_array( $_POST['ptb_tabs'] ) ? wp_unslash( $_POST['ptb_tabs'] ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by PTB_Tabs::sanitize().
		$hide = isset( $_POST['ptb_hide'] ) && is_array( $_POST['ptb_hide'] ) ? wp_unslash( $_POST['ptb_hide'] ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by PTB_Tabs::sanitize().
		$over = isset( $_POST['ptb_over'] ) && is_array( $_POST['ptb_over'] ) ? wp_unslash( $_POST['ptb_over'] ) : array();
		// phpcs:enable

		$tabs = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row['on']    = ! empty( $row['on'] );
			$row['share'] = ! empty( $row['share'] );
			$tabs[]       = $row;
		}
		$before = PTB_Tabs::get( $post_id );
		$data   = PTB_Tabs::sanitize(
			array(
				'tabs'     => $tabs,
				'hide'     => $hide,
				'over'     => $over,
				'imported' => $before['imported'],
			),
			! current_user_can( 'unfiltered_html' )
		);
		if ( PTB_Tabs::put( $post_id, $data ) && PTB_Settings::get()['purge'] && class_exists( 'Noxpress_Core' ) ) {
			Noxpress_Core::purge_page_cache( array( $post_id ) );
		}
	}
}

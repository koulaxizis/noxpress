<?php
/**
 * PFM_Product_Tab — "Formats" tab in the product data panel.
 *
 * Fields: the work (autocomplete from the 3rd character, Bible §5; a new
 * name creates a work, an empty name takes the product out of its work),
 * the format and an optional subtitle. Below, the other formats of the
 * work with links to edit them.
 *
 * Saved on woocommerce_process_product_meta with our own nonce and the
 * edit_post capability; WooCommerce then saves the product and the work's
 * member map is rebuilt by PFM_Works.
 */

defined( 'ABSPATH' ) || exit;

final class PFM_Product_Tab {

	const NONCE       = 'pfm_product_tab';
	const NONCE_FIELD = 'pfm_tab_nonce';

	public static function init(): void {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ), 20 );
	}

	public static function tab( $tabs ) {
		$tabs                = is_array( $tabs ) ? $tabs : array();
		$tabs['pfm_formats'] = array(
			'label'    => __( 'Μορφές', 'product-formats' ),
			'target'   => 'pfm_formats_panel',
			'class'    => array(),
			'priority' => 75,
		);
		return $tabs;
	}

	public static function panel(): void {
		global $post;
		$pid     = $post instanceof WP_Post ? (int) $post->ID : 0;
		$work_id = PFM_Works::work_of( $pid );
		$work    = PFM_Works::work( $work_id );
		$format  = PFM_Works::format_of( $pid );
		$variant = PFM_Works::variant_of( $pid );
		?>
		<div id="pfm_formats_panel" class="panel woocommerce_options_panel hidden pfm-tab">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<div class="options_group">
				<p class="form-field">
					<label for="pfm_work_name"><?php esc_html_e( 'Έργο', 'product-formats' ); ?></label>
					<input type="text" class="short" id="pfm_work_name" name="pfm_work_name" value="<?php echo esc_attr( $work ? $work->name : '' ); ?>" data-pfm-ac="work" data-pfm-target="pfm_work_id" autocomplete="off" />
					<input type="hidden" id="pfm_work_id" name="pfm_work_id" value="<?php echo (int) $work_id; ?>" />
					<span class="description"><?php esc_html_e( 'Γράψε 3 γράμματα για αναζήτηση. Νέο όνομα = νέο έργο· κενό = εκτός έργου.', 'product-formats' ); ?></span>
				</p>
				<p class="form-field">
					<label for="pfm_format"><?php esc_html_e( 'Μορφή', 'product-formats' ); ?></label>
					<?php self::format_select( 'pfm_format', 'pfm_format', $format ); ?>
				</p>
				<p class="form-field">
					<label for="pfm_variant"><?php esc_html_e( 'Υπότιτλος', 'product-formats' ); ?></label>
					<input type="text" class="short" id="pfm_variant" name="pfm_variant" value="<?php echo esc_attr( $variant ); ?>" maxlength="<?php echo (int) PFM_Works::MAX_VARIANT; ?>" />
					<span class="description"><?php esc_html_e( 'Προαιρετικό, π.χ. όταν ένα έργο έχει δύο προϊόντα της ίδιας μορφής.', 'product-formats' ); ?></span>
				</p>
			</div>
			<?php if ( $work ) : ?>
				<div class="options_group">
					<p class="form-field">
						<label><?php esc_html_e( 'Άλλες μορφές', 'product-formats' ); ?></label>
						<span class="pfm-siblings">
							<?php
							$n = 0;
							foreach ( PFM_Works::members( $work_id ) as $m ) {
								if ( $m['id'] === $pid ) {
									continue;
								}
								++$n;
								$label = PFM_Settings::label( $m['format'] );
								echo '<a href="' . esc_url( (string) get_edit_post_link( $m['id'] ) ) . '">'
									. esc_html( get_the_title( $m['id'] ) ) . '</a>'
									. ' <span class="description">' . esc_html( '' !== $label ? $label : __( 'χωρίς μορφή', 'product-formats' ) )
									. ( '' !== $m['variant'] ? ' · ' . esc_html( $m['variant'] ) : '' )
									. ( 'publish' !== $m['status'] ? ' · ' . esc_html( $m['status'] ) : '' )
									. '</span><br />';
							}
							if ( 0 === $n ) {
								esc_html_e( 'Καμία ακόμα.', 'product-formats' );
							}
							?>
						</span>
					</p>
					<p class="form-field">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PFM_Admin_UI::SLUG_MAIN . '&work=' . $work_id ) ); ?>"><?php esc_html_e( 'Διαχείριση του έργου', 'product-formats' ); ?></a>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Select of the enabled formats (plus the current one if it was disabled). */
	public static function format_select( string $name, string $id, string $current ): void {
		$formats = PFM_Settings::enabled_formats();
		echo '<select name="' . esc_attr( $name ) . '"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>';
		echo '<option value="">' . esc_html__( '— Χωρίς μορφή —', 'product-formats' ) . '</option>';
		foreach ( $formats as $key => $f ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $current, $key, false ) . '>' . esc_html( PFM_Settings::label( $key ) ) . '</option>';
		}
		if ( '' !== $current && ! isset( $formats[ $current ] ) && null !== PFM_Settings::format( $current ) ) {
			echo '<option value="' . esc_attr( $current ) . '" selected>' . esc_html( PFM_Settings::label( $current ) ) . '</option>';
		}
		echo '</select>';
	}

	public static function save( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE )
			|| ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$name    = isset( $_POST['pfm_work_name'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['pfm_work_name'] ) ) ) : '';
		$work_id = isset( $_POST['pfm_work_id'] ) ? absint( $_POST['pfm_work_id'] ) : 0;
		$format  = isset( $_POST['pfm_format'] ) ? sanitize_key( wp_unslash( (string) $_POST['pfm_format'] ) ) : '';
		$variant = isset( $_POST['pfm_variant'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['pfm_variant'] ) ) : '';

		if ( '' === $name ) {
			if ( PFM_Works::work_of( $post_id ) ) {
				PFM_Works::unassign( $post_id );
			}
			return;
		}

		$term = PFM_Works::work( $work_id );
		if ( null === $term || $term->name !== $name ) {
			$work_id = PFM_Works::find_by_name( $name );
		}
		if ( ! $work_id ) {
			$work_id = PFM_Works::create( $name );
		}
		if ( $work_id ) {
			PFM_Works::assign( $post_id, $work_id, $format, $variant );
		}
	}
}

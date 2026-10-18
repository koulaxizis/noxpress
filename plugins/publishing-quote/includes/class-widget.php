<?php
/**
 * PBQ_Widget — classic widget with the calculator (themes with classic
 * widget areas; block widget areas use the block).
 */

defined( 'ABSPATH' ) || exit;

final class PBQ_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'pbq_calculator',
			__( 'Κοστολόγηση έκδοσης', 'publishing-quote' ),
			array(
				'classname'                   => 'pbq-calculator-widget',
				'description'                 => __( 'Ο υπολογιστής κόστους έκδοσης του Publishing Quote.', 'publishing-quote' ),
				'customize_selective_refresh' => true,
			)
		);
	}

	/**
	 * @param array $args     Sidebar arguments.
	 * @param array $instance Widget settings.
	 */
	public function widget( $args, $instance ) {
		$html = PBQ_Front::render( array( 'title' => false ) );
		if ( '' === $html ) {
			return;
		}
		$title = isset( $instance['title'] ) ? (string) $instance['title'] : '';
		$title = '' !== $title ? $title : PBQ_Settings::text( 'title' );
		$title = apply_filters( 'widget_title', $title, $instance, $this->id_base ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core filter.
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput -- theme markup.
		if ( '' !== $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput -- theme markup.
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in PBQ_Front::render().
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput -- theme markup.
	}

	/** @param array $instance Widget settings. */
	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? (string) $instance['title'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Τίτλος (κενό = ο τίτλος των ρυθμίσεων):', 'publishing-quote' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p class="description"><?php esc_html_e( 'Οι τιμές και τα κείμενα ρυθμίζονται στο Noxpress → Publishing Quote.', 'publishing-quote' ); ?></p>
		<?php
		return '';
	}

	/**
	 * @param array $new_instance New settings.
	 * @param array $old_instance Old settings.
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'title' => isset( $new_instance['title'] ) ? mb_substr( sanitize_text_field( (string) $new_instance['title'] ), 0, 120 ) : '',
		);
	}
}

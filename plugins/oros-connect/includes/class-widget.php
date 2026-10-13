<?php
/**
 * ORC_Widget — classic widget with the newsletter form (themes with
 * classic widget areas; block widget areas use the block).
 */

defined( 'ABSPATH' ) || exit;

final class ORC_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'orc_newsletter',
			__( 'Newsletter (orOS)', 'oros-connect' ),
			array(
				'classname'                   => 'orc-newsletter-widget',
				'description'                 => __( 'Φόρμα εγγραφής στη λίστα σου στο orOS Newsletter.', 'oros-connect' ),
				'customize_selective_refresh' => true,
			)
		);
	}

	/**
	 * @param array $args     Sidebar arguments.
	 * @param array $instance Widget settings.
	 */
	public function widget( $args, $instance ) {
		$html = ORC_Newsletter::form( array( 'title' => false ) );
		if ( '' === $html ) {
			return;
		}
		$title = isset( $instance['title'] ) ? (string) $instance['title'] : '';
		$title = '' !== $title ? $title : ORC_Settings::text( 'title' );
		$title = apply_filters( 'widget_title', $title, $instance, $this->id_base ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core filter.
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput -- theme markup.
		if ( '' !== $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput -- theme markup.
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in ORC_Newsletter::form().
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput -- theme markup.
	}

	/** @param array $instance Widget settings. */
	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? (string) $instance['title'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Τίτλος (κενό = ο τίτλος των ρυθμίσεων):', 'oros-connect' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p class="description"><?php esc_html_e( 'Τα κείμενα και η διεύθυνση του orOS ρυθμίζονται στο Noxpress → orOS Connect.', 'oros-connect' ); ?></p>
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

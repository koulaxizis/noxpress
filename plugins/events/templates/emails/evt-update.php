<?php
/**
 * Events — buyers' notice (HTML).
 *
 * Override: yourtheme/woocommerce/emails/evt-update.php
 *
 * @var WC_Order   $order
 * @var array|null $event
 * @var string     $when
 * @var string     $where
 * @var string     $message
 * @var string     $tickets_url
 * @var string     $email_heading
 * @var string     $additional_content
 * @var WC_Email   $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<?php if ( $event ) : ?>
	<p style="margin:0 0 4px;font-weight:bold;"><a href="<?php echo esc_url( $event['url'] ); ?>"><?php echo esc_html( $event['title'] ); ?></a></p>
	<p style="margin:0 0 16px;"><?php echo esc_html( $when . ( '' !== $where ? ' · ' . $where : '' ) ); ?></p>
<?php endif; ?>
<?php echo wp_kses_post( wpautop( esc_html( $message ) ) ); ?>
<?php if ( '' !== $tickets_url ) : ?>
	<p><a href="<?php echo esc_url( $tickets_url ); ?>"><?php echo esc_html( EVT_Settings::text( 'show_tickets' ) ); ?></a></p>
<?php endif; ?>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );

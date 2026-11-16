<?php
/**
 * Events — buyers' notice (plain text).
 *
 * Override: yourtheme/woocommerce/emails/plain/evt-update.php
 *
 * @var array|null $event
 * @var string     $when
 * @var string     $where
 * @var string     $message
 * @var string     $tickets_url
 * @var string     $email_heading
 * @var string     $additional_content
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
if ( $event ) {
	echo esc_html( $event['title'] ) . "\n" . esc_html( $when . ( '' !== $where ? ' · ' . $where : '' ) ) . "\n" . esc_url_raw( $event['url'] ) . "\n\n";
}
echo esc_html( $message ) . "\n\n";
if ( '' !== $tickets_url ) {
	echo esc_html( EVT_Settings::text( 'show_tickets' ) ) . ': ' . esc_url_raw( $tickets_url ) . "\n\n";
}
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );

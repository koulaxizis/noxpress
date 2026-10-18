<?php
/**
 * Wish Shelf — wishlist alert, customer (plain text).
 *
 * Override: yourtheme/woocommerce/emails/plain/wsh-alert.php
 *
 * @var string  $first_name
 * @var array[] $lines
 * @var string  $list_url
 * @var string  $unsub_url
 * @var string  $email_heading
 * @var string  $additional_content
 */

defined( 'ABSPATH' ) || exit;

echo "=\n" . esc_html( wp_strip_all_tags( $email_heading ) ) . "\n=\n\n";
if ( '' !== $first_name ) {
	echo esc_html( sprintf( /* translators: %s: customer first name */ __( 'Γεια σας %s,', 'wish-shelf' ), $first_name ) ) . "\n\n";
}
echo esc_html( WSH_Settings::text( 't_mail_intro' ) ) . "\n\n";
foreach ( $lines as $line ) {
	echo '- ' . esc_html( $line['name'] ) . ': ' . esc_html( WSH_Alerts::line_text( $line ) ) . "\n  " . esc_url_raw( $line['url'] ) . "\n";
}
echo "\n";
if ( '' !== $list_url ) {
	echo esc_html( WSH_Settings::text( 't_view' ) ) . ': ' . esc_url_raw( $list_url ) . "\n\n";
}
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo esc_html( WSH_Settings::text( 't_mail_off' ) ) . ': ' . esc_url_raw( $unsub_url ) . "\n\n";
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );

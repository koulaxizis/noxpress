<?php
/**
 * Gift Cards — buyer confirmation, reminder, review notice, plain text.
 *
 * Override: yourtheme/woocommerce/emails/plain/gfc-notice.php
 */

defined( 'ABSPATH' ) || exit;

echo "=================================================\n";
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo "=================================================\n\n";

foreach ( (array) $lines as $gfc_line ) {
	if ( '' !== (string) $gfc_line ) {
		echo esc_html( (string) $gfc_line ) . "\n\n";
	}
}

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );

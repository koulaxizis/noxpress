<?php
/**
 * Gift Cards — the card with its code, plain text.
 *
 * Override: yourtheme/woocommerce/emails/plain/gfc-card.php
 */

defined( 'ABSPATH' ) || exit;

echo "=================================================\n";
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo "=================================================\n\n";

if ( $card && '' !== $code ) {
	echo esc_html( $intro ) . "\n\n";
	if ( '' !== (string) $card['message'] ) {
		echo '«' . esc_html( (string) $card['message'] ) . "»\n\n";
	}
	echo esc_html( GFC_Settings::money( (int) $card['initial'], (string) $card['currency'] ) ) . "\n";
	echo esc_html( $code ) . "\n\n";
	echo esc_html( $how ) . "\n\n";
	if ( '' !== $expires ) {
		echo esc_html( $expires ) . "\n\n";
	}
	echo esc_html( $shop_t ) . ': ' . esc_url_raw( $shop ) . "\n\n";
	echo esc_html( $keep ) . "\n\n";
}

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );

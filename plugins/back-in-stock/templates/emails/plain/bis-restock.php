<?php
/**
 * Back in Stock — back in stock, plain text.
 *
 * Override: yourtheme/woocommerce/emails/plain/bis-restock.php
 */

defined( 'ABSPATH' ) || exit;

echo "=================================================\n";
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo "=================================================\n\n";

echo esc_html( sprintf( /* translators: 1: product name, 2: site name */ __( 'Καλά νέα: το «%1$s» είναι ξανά σε απόθεμα στο %2$s.', 'back-in-stock' ), $name, $site ) ) . "\n\n";
if ( '' !== $price ) {
	echo esc_html( wp_strip_all_tags( html_entity_decode( $price, ENT_QUOTES, 'UTF-8' ) ) ) . "\n";
}
echo esc_url_raw( $url ) . "\n\n";

if ( ! empty( $also ) ) {
	echo esc_html__( 'Διαθέσιμο και ως:', 'back-in-stock' ) . "\n";
	foreach ( $also as $bis_a ) {
		echo '- ' . esc_html( $bis_a['name'] ) . ': ' . esc_url_raw( $bis_a['url'] ) . "\n";
	}
	echo "\n";
}

echo esc_html__( 'Το απόθεμα μπορεί να εξαντληθεί ξανά. Αυτό ήταν το μόνο email για αυτό το αίτημα, που τώρα σβήστηκε.', 'back-in-stock' ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo esc_html__( 'Ακύρωση όλων των ειδοποιήσεων αποθέματος για αυτό το email:', 'back-in-stock' ) . "\n";
echo esc_url_raw( $link ) . "\n\n";

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );

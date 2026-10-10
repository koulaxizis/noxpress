<?php
/**
 * Easy Withdrawal — customer receipt, plain text.
 *
 * Override: yourtheme/woocommerce/emails/plain/ewd-receipt.php
 */

defined( 'ABSPATH' ) || exit;

echo "=================================================\n";
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo "=================================================\n\n";

echo esc_html( sprintf( /* translators: %s: customer name */ __( 'Γεια σου %s,', 'easy-withdrawal' ), (string) $request['name'] ) ) . "\n\n";
echo esc_html__( 'Λάβαμε τη δήλωσή σου ότι υπαναχωρείς από τη σύμβαση αγοράς των παρακάτω προϊόντων. Αυτό το email είναι η απόδειξη παραλαβής της: κράτησέ το.', 'easy-withdrawal' ) . "\n\n";

wc_get_template( 'emails/plain/ewd-details.php', array( 'order' => $order, 'request' => $request ), '', EWD_PATH . 'templates/' );

if ( '' !== trim( $instructions ) ) {
	echo esc_html__( 'Επόμενα βήματα', 'easy-withdrawal' ) . "\n";
	echo esc_html( $instructions ) . "\n\n";
}

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );

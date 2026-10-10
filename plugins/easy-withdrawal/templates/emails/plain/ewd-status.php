<?php
/**
 * Easy Withdrawal — status change, customer, plain text.
 *
 * Override: yourtheme/woocommerce/emails/plain/ewd-status.php
 */

defined( 'ABSPATH' ) || exit;

echo "=================================================\n";
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo "=================================================\n\n";

echo esc_html( sprintf( /* translators: %s: customer name */ __( 'Γεια σου %s,', 'easy-withdrawal' ), (string) $request['name'] ) ) . "\n\n";
if ( 'done' === $request['status'] ) {
	echo esc_html( sprintf( /* translators: %s: request id */ __( 'Η υπαναχώρηση %s ολοκληρώθηκε.', 'easy-withdrawal' ), (string) $request['id'] ) ) . "\n\n";
} else {
	echo esc_html( sprintf( /* translators: %s: request id */ __( 'Το κατάστημα αμφισβητεί την υπαναχώρηση %s, για τον λόγο που γράφει παρακάτω. Αν διαφωνείς, απάντησε σε αυτό το email.', 'easy-withdrawal' ), (string) $request['id'] ) ) . "\n\n";
}
if ( '' !== trim( $note ) ) {
	echo esc_html( $note ) . "\n\n";
}

wc_get_template( 'emails/plain/ewd-details.php', array( 'order' => $order, 'request' => $request ), '', EWD_PATH . 'templates/' );

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );

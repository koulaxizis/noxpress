<?php
/**
 * Easy Withdrawal — new statement, store notice, plain text.
 *
 * Override: yourtheme/woocommerce/emails/plain/ewd-admin.php
 */

defined( 'ABSPATH' ) || exit;

echo "=================================================\n";
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo "=================================================\n\n";

echo esc_html( sprintf( /* translators: 1: customer name, 2: order number */ __( 'Ο/Η %1$s υπέβαλε δήλωση υπαναχώρησης για την παραγγελία #%2$s.', 'easy-withdrawal' ), (string) $request['name'], $order->get_order_number() ) ) . "\n\n";

wc_get_template( 'emails/plain/ewd-details.php', array( 'order' => $order, 'request' => $request ), '', EWD_PATH . 'templates/' );

echo esc_html__( 'Άνοιγμα του αιτήματος', 'easy-withdrawal' ) . ': ' . esc_url_raw( $admin_url ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );

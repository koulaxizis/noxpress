<?php
/**
 * Pre-Orders — «Released», customer (plain text).
 *
 * Override: yourtheme/woocommerce/emails/plain/por-released.php
 *
 * @var WC_Order $order
 * @var array[]  $rows
 * @var string   $account_url
 * @var string   $email_heading
 * @var string   $additional_content
 */

defined( 'ABSPATH' ) || exit;

echo "=\n" . esc_html( wp_strip_all_tags( $email_heading ) ) . "\n=\n\n";
echo esc_html( sprintf( /* translators: %s: customer first name */ __( 'Γεια σας %s,', 'pre-orders' ), (string) $order->get_billing_first_name() ) ) . "\n\n";
echo esc_html( sprintf( /* translators: %s: order number */ __( 'Κυκλοφόρησε ό,τι προπαραγγείλατε με την παραγγελία #%s. Θα σας ειδοποιήσουμε ξανά όταν αποσταλεί.', 'pre-orders' ), (string) $order->get_order_number() ) ) . "\n\n";
foreach ( $rows as $row ) {
	echo '- ' . esc_html( $row['name'] ) . ' × ' . (int) $row['qty'] . "\n";
}
echo "\n";
if ( '' !== $account_url ) {
	echo esc_html__( 'Δείτε την παραγγελία σας', 'pre-orders' ) . ': ' . esc_url_raw( $account_url ) . "\n\n";
}
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );

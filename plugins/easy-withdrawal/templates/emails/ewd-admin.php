<?php
/**
 * Easy Withdrawal — new statement, store notice (HTML).
 *
 * Override: yourtheme/woocommerce/emails/ewd-admin.php
 *
 * @var WC_Order $order
 * @var array    $request
 * @var string   $email_heading
 * @var string   $additional_content
 * @var string   $admin_url
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php echo esc_html( sprintf( /* translators: 1: customer name, 2: order number */ __( 'Ο/Η %1$s υπέβαλε δήλωση υπαναχώρησης για την παραγγελία #%2$s.', 'easy-withdrawal' ), (string) $request['name'], $order->get_order_number() ) ); ?></p>

<?php wc_get_template( 'emails/ewd-details.php', array( 'order' => $order, 'request' => $request ), '', EWD_PATH . 'templates/' ); ?>

<p><a href="<?php echo esc_url( $admin_url ); ?>"><?php esc_html_e( 'Άνοιγμα του αιτήματος', 'easy-withdrawal' ); ?></a></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );

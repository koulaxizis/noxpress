<?php
/**
 * Pre-Orders — «Released», customer (HTML).
 *
 * Override: yourtheme/woocommerce/emails/por-released.php
 *
 * @var WC_Order $order
 * @var array[]  $rows   { name, qty, date, text }
 * @var string   $account_url
 * @var string   $email_heading
 * @var string   $additional_content
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php echo esc_html( sprintf( /* translators: %s: customer first name */ __( 'Γεια σας %s,', 'pre-orders' ), (string) $order->get_billing_first_name() ) ); ?></p>
<p><?php echo esc_html( sprintf( /* translators: %s: order number */ __( 'Κυκλοφόρησε ό,τι προπαραγγείλατε με την παραγγελία #%s. Θα σας ειδοποιήσουμε ξανά όταν αποσταλεί.', 'pre-orders' ), (string) $order->get_order_number() ) ); ?></p>

<?php wc_get_template( 'emails/por-lines.php', array( 'rows' => $rows, 'released' => true ), '', POR_PATH . 'templates/' ); ?>

<?php if ( '' !== $account_url ) : ?>
	<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Δείτε την παραγγελία σας', 'pre-orders' ); ?></a></p>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );

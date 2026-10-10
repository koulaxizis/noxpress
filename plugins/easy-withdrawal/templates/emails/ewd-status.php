<?php
/**
 * Easy Withdrawal — status change, customer (HTML).
 *
 * Override: yourtheme/woocommerce/emails/ewd-status.php
 *
 * @var WC_Order $order
 * @var array    $request
 * @var string   $email_heading
 * @var string   $additional_content
 * @var string   $note
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php echo esc_html( sprintf( /* translators: %s: customer name */ __( 'Γεια σου %s,', 'easy-withdrawal' ), (string) $request['name'] ) ); ?></p>
<?php if ( 'done' === $request['status'] ) : ?>
	<p><?php echo esc_html( sprintf( /* translators: %s: request id */ __( 'Η υπαναχώρηση %s ολοκληρώθηκε.', 'easy-withdrawal' ), (string) $request['id'] ) ); ?></p>
<?php else : ?>
	<p><?php echo esc_html( sprintf( /* translators: %s: request id */ __( 'Το κατάστημα αμφισβητεί την υπαναχώρηση %s, για τον λόγο που γράφει παρακάτω. Αν διαφωνείς, απάντησε σε αυτό το email.', 'easy-withdrawal' ), (string) $request['id'] ) ); ?></p>
<?php endif; ?>

<?php if ( '' !== trim( $note ) ) : ?>
	<blockquote style="margin:0 0 18px;padding:10px 14px;border-left:3px solid #e5e5e5;"><?php echo nl2br( esc_html( $note ) ); ?></blockquote>
<?php endif; ?>

<?php wc_get_template( 'emails/ewd-details.php', array( 'order' => $order, 'request' => $request ), '', EWD_PATH . 'templates/' ); ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );

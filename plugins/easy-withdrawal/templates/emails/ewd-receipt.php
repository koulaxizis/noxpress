<?php
/**
 * Easy Withdrawal — customer receipt (HTML).
 *
 * Override: yourtheme/woocommerce/emails/ewd-receipt.php
 *
 * @var WC_Order $order
 * @var array    $request
 * @var string   $email_heading
 * @var string   $additional_content
 * @var string   $instructions
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php echo esc_html( sprintf( /* translators: %s: customer name */ __( 'Γεια σου %s,', 'easy-withdrawal' ), (string) $request['name'] ) ); ?></p>
<p><?php esc_html_e( 'Λάβαμε τη δήλωσή σου ότι υπαναχωρείς από τη σύμβαση αγοράς των παρακάτω προϊόντων. Αυτό το email είναι η απόδειξη παραλαβής της: κράτησέ το.', 'easy-withdrawal' ); ?></p>

<?php wc_get_template( 'emails/ewd-details.php', array( 'order' => $order, 'request' => $request ), '', EWD_PATH . 'templates/' ); ?>

<?php if ( '' !== trim( $instructions ) ) : ?>
	<h2><?php esc_html_e( 'Επόμενα βήματα', 'easy-withdrawal' ); ?></h2>
	<p><?php echo nl2br( esc_html( $instructions ) ); ?></p>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );

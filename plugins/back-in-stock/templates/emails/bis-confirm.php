<?php
/**
 * Back in Stock — confirm (HTML).
 *
 * Override: yourtheme/woocommerce/emails/bis-confirm.php
 *
 * @var string   $name
 * @var string   $url
 * @var string   $link
 * @var int      $hours
 * @var string   $site
 * @var string   $email_heading
 * @var string   $additional_content
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

$bis_color = (string) get_option( 'woocommerce_email_base_color', '#7f54b3' );

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php esc_html_e( 'Γεια σου,', 'back-in-stock' ); ?></p>
<p><?php echo esc_html( sprintf( /* translators: 1: product name, 2: site name */ __( 'Ζήτησες να σε ειδοποιήσουμε όταν το «%1$s» ξαναμπεί σε απόθεμα στο %2$s. Πάτα το κουμπί για να το επιβεβαιώσεις.', 'back-in-stock' ), $name, $site ) ); ?></p>

<p style="margin:24px 0;text-align:center;">
	<a href="<?php echo esc_url( $link ); ?>" style="display:inline-block;padding:12px 28px;border-radius:4px;background:<?php echo esc_attr( $bis_color ); ?>;color:#ffffff;font-weight:bold;text-decoration:none;"><?php esc_html_e( 'Επιβεβαίωση', 'back-in-stock' ); ?></a>
</p>

<p><?php echo esc_html( sprintf( /* translators: %d: hours */ __( 'Ο σύνδεσμος ισχύει %d ώρες. Θα λάβεις ένα μόνο email, όταν το προϊόν ξαναέρθει, και μετά το αίτημα σβήνεται.', 'back-in-stock' ), $hours ) ); ?></p>
<p><?php esc_html_e( 'Αν δεν το ζήτησες εσύ, αγνόησε αυτό το email: χωρίς επιβεβαίωση δεν θα λάβεις τίποτα άλλο.', 'back-in-stock' ); ?></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );

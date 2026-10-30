<?php
/**
 * Magic Login — login link (HTML).
 *
 * Override: yourtheme/woocommerce/emails/mgl-link.php
 *
 * @var WP_User  $user
 * @var string   $name
 * @var string   $link
 * @var int      $minutes
 * @var string   $site
 * @var string   $email_heading
 * @var string   $additional_content
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php echo esc_html( '' !== $name ? sprintf( /* translators: %s: customer name */ __( 'Γεια σου %s,', 'magic-login' ), $name ) : __( 'Γεια σου,', 'magic-login' ) ); ?></p>
<p><?php echo esc_html( sprintf( /* translators: 1: site name, 2: minutes */ __( 'Πάτησε το κουμπί για να συνδεθείς στον λογαριασμό σου στο %1$s. Ο σύνδεσμος ισχύει %2$d λεπτά και μόνο μία φορά.', 'magic-login' ), $site, $minutes ) ); ?></p>

<p style="margin:24px 0;text-align:center;">
	<a href="<?php echo esc_url( $link ); ?>" style="display:inline-block;padding:12px 28px;border-radius:4px;background:<?php echo esc_attr( (string) get_option( 'woocommerce_email_base_color', '#7f54b3' ) ); ?>;color:#ffffff;font-weight:bold;text-decoration:none;"><?php esc_html_e( 'Σύνδεση', 'magic-login' ); ?></a>
</p>

<p><?php esc_html_e( 'Αν το κουμπί δεν ανοίγει, αντίγραψε αυτή τη διεύθυνση στον browser:', 'magic-login' ); ?><br />
	<a href="<?php echo esc_url( $link ); ?>" style="word-break:break-all;"><?php echo esc_html( $link ); ?></a></p>

<p><?php esc_html_e( 'Αν δεν ζήτησες εσύ σύνδεση, αγνόησε αυτό το email. Κανείς δεν μπορεί να συνδεθεί χωρίς τον σύνδεσμο, και ο κωδικός σου δεν άλλαξε.', 'magic-login' ); ?></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );

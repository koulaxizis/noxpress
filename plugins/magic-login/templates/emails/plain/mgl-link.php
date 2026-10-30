<?php
/**
 * Magic Login — login link, plain text.
 *
 * Override: yourtheme/woocommerce/emails/plain/mgl-link.php
 */

defined( 'ABSPATH' ) || exit;

echo "=================================================\n";
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo "=================================================\n\n";

echo esc_html( '' !== $name ? sprintf( /* translators: %s: customer name */ __( 'Γεια σου %s,', 'magic-login' ), $name ) : __( 'Γεια σου,', 'magic-login' ) ) . "\n\n";
echo esc_html( sprintf( /* translators: 1: site name, 2: minutes */ __( 'Άνοιξε τον παρακάτω σύνδεσμο για να συνδεθείς στον λογαριασμό σου στο %1$s. Ισχύει %2$d λεπτά και μόνο μία φορά.', 'magic-login' ), $site, $minutes ) ) . "\n\n";
echo esc_url_raw( $link ) . "\n\n";
echo esc_html__( 'Αν δεν ζήτησες εσύ σύνδεση, αγνόησε αυτό το email. Κανείς δεν μπορεί να συνδεθεί χωρίς τον σύνδεσμο, και ο κωδικός σου δεν άλλαξε.', 'magic-login' ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );

<?php
/**
 * Back in Stock — confirm, plain text.
 *
 * Override: yourtheme/woocommerce/emails/plain/bis-confirm.php
 */

defined( 'ABSPATH' ) || exit;

echo "=================================================\n";
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo "=================================================\n\n";

echo esc_html__( 'Γεια σου,', 'back-in-stock' ) . "\n\n";
echo esc_html( sprintf( /* translators: 1: product name, 2: site name */ __( 'Ζήτησες να σε ειδοποιήσουμε όταν το «%1$s» ξαναμπεί σε απόθεμα στο %2$s. Άνοιξε τον σύνδεσμο για να το επιβεβαιώσεις:', 'back-in-stock' ), $name, $site ) ) . "\n\n";
echo esc_url_raw( $link ) . "\n\n";
echo esc_html( sprintf( /* translators: %d: hours */ __( 'Ο σύνδεσμος ισχύει %d ώρες. Θα λάβεις ένα μόνο email, όταν το προϊόν ξαναέρθει, και μετά το αίτημα σβήνεται.', 'back-in-stock' ), $hours ) ) . "\n\n";
echo esc_html__( 'Αν δεν το ζήτησες εσύ, αγνόησε αυτό το email: χωρίς επιβεβαίωση δεν θα λάβεις τίποτα άλλο.', 'back-in-stock' ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );

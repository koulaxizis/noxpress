<?php
/**
 * Gift Cards — buyer confirmation, reminder, review notice (HTML). Never
 * contains a code.
 *
 * Override: yourtheme/woocommerce/emails/gfc-notice.php
 *
 * @var string[] $lines              Paragraphs (plain text; a line that is a URL becomes a link).
 * @var string   $email_heading
 * @var string   $additional_content
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

foreach ( (array) $lines as $gfc_line ) {
	$gfc_line = (string) $gfc_line;
	if ( '' === $gfc_line ) {
		continue;
	}
	if ( 0 === strpos( $gfc_line, 'http' ) && wp_http_validate_url( $gfc_line ) ) {
		echo '<p><a href="' . esc_url( $gfc_line ) . '">' . esc_html( $gfc_line ) . '</a></p>';
	} else {
		echo '<p>' . esc_html( $gfc_line ) . '</p>';
	}
}

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );

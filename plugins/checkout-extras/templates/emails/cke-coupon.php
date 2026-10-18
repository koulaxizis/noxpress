<?php
/**
 * Checkout Extras — thank-you coupon / reminder, customer (HTML).
 *
 * Override: yourtheme/woocommerce/emails/cke-coupon.php
 *
 * @var WC_Order  $order
 * @var WC_Coupon $coupon
 * @var string    $box                The coupon box (escaped HTML).
 * @var string    $email_heading
 * @var string    $additional_content
 * @var WC_Email  $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

echo $box; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in CKE_Coupon::email_html().

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );

<?php
/**
 * Gift Cards — the card with its code (HTML).
 *
 * Override: yourtheme/woocommerce/emails/gfc-card.php
 *
 * @var array    $card               The card.
 * @var string   $intro              Opening line (plain text).
 * @var string   $code               The code, formatted XXXX-XXXX-XXXX-XXXX.
 * @var string   $how                How to use it (plain text).
 * @var string   $expires            "Valid until …" or ''.
 * @var string   $keep               "Keep this email" (plain text).
 * @var string   $shop               Shop URL.
 * @var string   $shop_t             Button label.
 * @var string   $email_heading
 * @var string   $additional_content
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

if ( $card && '' !== $code ) :
	$gfc_base = (string) get_option( 'woocommerce_email_base_color', '#6d4aff' );
	?>
<p><?php echo esc_html( $intro ); ?></p>
	<?php if ( '' !== (string) $card['message'] ) : ?>
<blockquote style="margin:0 0 16px;padding:8px 14px;border-left:3px solid <?php echo esc_attr( $gfc_base ); ?>;font-style:italic;"><?php echo nl2br( esc_html( (string) $card['message'] ) ); ?></blockquote>
	<?php endif; ?>
<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;margin:0 0 16px;border:2px dashed <?php echo esc_attr( $gfc_base ); ?>;border-radius:6px;">
	<tr><td style="padding:18px;text-align:center;">
		<div style="font-size:28px;font-weight:bold;margin-bottom:6px;"><?php echo esc_html( GFC_Settings::money( (int) $card['initial'], (string) $card['currency'] ) ); ?></div>
		<div style="font-family:Consolas,Menlo,monospace;font-size:22px;letter-spacing:2px;font-weight:bold;"><?php echo esc_html( $code ); ?></div>
	</td></tr>
</table>
<p><?php echo esc_html( $how ); ?></p>
	<?php if ( '' !== $expires ) : ?>
<p><strong><?php echo esc_html( $expires ); ?></strong></p>
	<?php endif; ?>
<p><a href="<?php echo esc_url( $shop ); ?>" style="display:inline-block;padding:10px 18px;background:<?php echo esc_attr( $gfc_base ); ?>;color:#ffffff;text-decoration:none;border-radius:4px;"><?php echo esc_html( $shop_t ); ?></a></p>
<p style="font-size:12px;opacity:.8;"><?php echo esc_html( $keep ); ?></p>
	<?php
endif;

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );

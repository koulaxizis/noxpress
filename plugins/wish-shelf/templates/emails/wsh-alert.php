<?php
/**
 * Wish Shelf — wishlist alert, customer (HTML).
 *
 * Override: yourtheme/woocommerce/emails/wsh-alert.php
 *
 * @var WP_User|null $user
 * @var string       $first_name
 * @var array[]      $lines     { kind, name, url, old, now, image }
 * @var string       $list_url
 * @var string       $unsub_url
 * @var string       $email_heading
 * @var string       $additional_content
 * @var WC_Email     $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<?php if ( '' !== $first_name ) : ?>
	<p><?php echo esc_html( sprintf( /* translators: %s: customer first name */ __( 'Γεια σας %s,', 'wish-shelf' ), $first_name ) ); ?></p>
<?php endif; ?>
<p><?php echo esc_html( WSH_Settings::text( 't_mail_intro' ) ); ?></p>

<table class="td" cellspacing="0" cellpadding="6" border="1" style="width:100%;margin:0 0 18px;border-collapse:collapse;">
	<tbody>
		<?php foreach ( $lines as $line ) : ?>
			<tr>
				<td class="td" style="text-align:left;vertical-align:middle;">
					<a href="<?php echo esc_url( $line['url'] ); ?>"><?php echo esc_html( $line['name'] ); ?></a><br />
					<?php echo esc_html( WSH_Alerts::line_text( $line ) ); ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php if ( '' !== $list_url ) : ?>
	<p><a href="<?php echo esc_url( $list_url ); ?>"><?php echo esc_html( WSH_Settings::text( 't_view' ) ); ?></a></p>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
?>
<p style="font-size:12px;"><a href="<?php echo esc_url( $unsub_url ); ?>"><?php echo esc_html( WSH_Settings::text( 't_mail_off' ) ); ?></a></p>
<?php
do_action( 'woocommerce_email_footer', $email );

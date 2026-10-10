<?php
/**
 * Easy Withdrawal — request details block shared by the HTML emails.
 *
 * Override: yourtheme/woocommerce/emails/ewd-details.php
 *
 * @var WC_Order $order
 * @var array    $request
 */

defined( 'ABSPATH' ) || exit;

$ewd_cell = 'text-align:left;padding:8px 10px;border:1px solid #e5e5e5;vertical-align:top;';
?>
<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;margin:0 0 18px;border:1px solid #e5e5e5;">
	<tbody>
		<tr><th scope="row" style="<?php echo esc_attr( $ewd_cell ); ?>"><?php esc_html_e( 'Αριθμός αιτήματος', 'easy-withdrawal' ); ?></th><td style="<?php echo esc_attr( $ewd_cell ); ?>"><?php echo esc_html( (string) $request['id'] ); ?></td></tr>
		<tr><th scope="row" style="<?php echo esc_attr( $ewd_cell ); ?>"><?php esc_html_e( 'Υποβλήθηκε', 'easy-withdrawal' ); ?></th><td style="<?php echo esc_attr( $ewd_cell ); ?>"><?php echo esc_html( EWD_Lang::datetime( (int) $request['created'] ) ); ?></td></tr>
		<tr><th scope="row" style="<?php echo esc_attr( $ewd_cell ); ?>"><?php esc_html_e( 'Παραγγελία', 'easy-withdrawal' ); ?></th><td style="<?php echo esc_attr( $ewd_cell ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?><?php echo $order->get_date_created() ? ' · ' . esc_html( EWD_Lang::date( $order->get_date_created()->getTimestamp() ) ) : ''; ?></td></tr>
		<tr><th scope="row" style="<?php echo esc_attr( $ewd_cell ); ?>"><?php esc_html_e( 'Όνομα', 'easy-withdrawal' ); ?></th><td style="<?php echo esc_attr( $ewd_cell ); ?>"><?php echo esc_html( (string) $request['name'] ); ?></td></tr>
		<tr><th scope="row" style="<?php echo esc_attr( $ewd_cell ); ?>"><?php esc_html_e( 'Email', 'easy-withdrawal' ); ?></th><td style="<?php echo esc_attr( $ewd_cell ); ?>"><?php echo esc_html( (string) $request['email'] ); ?></td></tr>
		<?php if ( '' !== (string) $request['reason'] ) : ?>
			<tr><th scope="row" style="<?php echo esc_attr( $ewd_cell ); ?>"><?php esc_html_e( 'Αιτιολογία', 'easy-withdrawal' ); ?></th><td style="<?php echo esc_attr( $ewd_cell ); ?>"><?php echo nl2br( esc_html( (string) $request['reason'] ) ); ?></td></tr>
		<?php endif; ?>
	</tbody>
</table>

<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;margin:0 0 18px;border:1px solid #e5e5e5;">
	<thead>
		<tr>
			<th scope="col" style="<?php echo esc_attr( $ewd_cell ); ?>"><?php esc_html_e( 'Προϊόν', 'easy-withdrawal' ); ?></th>
			<th scope="col" style="<?php echo esc_attr( $ewd_cell ); ?>"><?php esc_html_e( 'Ποσότητα', 'easy-withdrawal' ); ?></th>
			<th scope="col" style="<?php echo esc_attr( $ewd_cell ); ?>"><?php esc_html_e( 'Αξία', 'easy-withdrawal' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $request['items'] as $ewd_it ) : ?>
			<tr>
				<td style="<?php echo esc_attr( $ewd_cell ); ?>"><?php echo esc_html( (string) $ewd_it['name'] ); ?></td>
				<td style="<?php echo esc_attr( $ewd_cell ); ?>"><?php echo (int) $ewd_it['qty']; ?></td>
				<td style="<?php echo esc_attr( $ewd_cell ); ?>"><?php echo wp_kses_post( wc_price( (float) $ewd_it['unit'] * (int) $ewd_it['qty'], array( 'currency' => $order->get_currency() ) ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

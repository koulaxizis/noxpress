<?php
/**
 * Pre-Orders — the lines of an email (HTML).
 *
 * Override: yourtheme/woocommerce/emails/por-lines.php
 *
 * @var array[] $rows     { name, qty, date, text }
 * @var bool    $released True: «Released» email (no date column).
 */

defined( 'ABSPATH' ) || exit;
?>
<table class="td" cellspacing="0" cellpadding="6" border="1" style="width:100%;margin:0 0 18px;border-collapse:collapse;">
	<thead>
		<tr>
			<th class="td" scope="col" style="text-align:left;"><?php esc_html_e( 'Προϊόν', 'pre-orders' ); ?></th>
			<th class="td" scope="col" style="text-align:left;"><?php esc_html_e( 'Ποσότητα', 'pre-orders' ); ?></th>
			<?php if ( ! $released ) : ?>
				<th class="td" scope="col" style="text-align:left;"><?php esc_html_e( 'Κυκλοφορία', 'pre-orders' ); ?></th>
			<?php endif; ?>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $rows as $row ) : ?>
			<tr>
				<td class="td" style="text-align:left;"><?php echo esc_html( $row['name'] ); ?></td>
				<td class="td" style="text-align:left;"><?php echo (int) $row['qty']; ?></td>
				<?php if ( ! $released ) : ?>
					<td class="td" style="text-align:left;"><?php echo esc_html( $row['text'] ); ?></td>
				<?php endif; ?>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

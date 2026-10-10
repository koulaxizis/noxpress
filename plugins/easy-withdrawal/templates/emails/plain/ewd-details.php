<?php
/**
 * Easy Withdrawal — request details, plain text.
 *
 * Override: yourtheme/woocommerce/emails/plain/ewd-details.php
 *
 * @var WC_Order $order
 * @var array    $request
 */

defined( 'ABSPATH' ) || exit;

echo esc_html__( 'Αριθμός αιτήματος', 'easy-withdrawal' ) . ': ' . esc_html( (string) $request['id'] ) . "\n";
echo esc_html__( 'Υποβλήθηκε', 'easy-withdrawal' ) . ': ' . esc_html( EWD_Lang::datetime( (int) $request['created'] ) ) . "\n";
echo esc_html__( 'Παραγγελία', 'easy-withdrawal' ) . ': #' . esc_html( $order->get_order_number() ) . "\n";
echo esc_html__( 'Όνομα', 'easy-withdrawal' ) . ': ' . esc_html( (string) $request['name'] ) . "\n";
echo esc_html__( 'Email', 'easy-withdrawal' ) . ': ' . esc_html( (string) $request['email'] ) . "\n";
if ( '' !== (string) $request['reason'] ) {
	echo esc_html__( 'Αιτιολογία', 'easy-withdrawal' ) . ': ' . esc_html( (string) $request['reason'] ) . "\n";
}
echo "\n";
foreach ( $request['items'] as $ewd_it ) {
	echo esc_html( (int) $ewd_it['qty'] . ' × ' . (string) $ewd_it['name'] ) . ' — ' . esc_html( wp_strip_all_tags( wc_price( (float) $ewd_it['unit'] * (int) $ewd_it['qty'], array( 'currency' => $order->get_currency() ) ) ) ) . "\n";
}
echo "\n";

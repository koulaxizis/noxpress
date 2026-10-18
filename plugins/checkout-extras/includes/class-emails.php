<?php
/**
 * CKE_Emails — the two WooCommerce emails of the coupon part:
 *  - cke_coupon    "Thank-you coupon": sent when a coupon is created, only
 *                  with c_email = 'separate' (by default the coupon rides in
 *                  WooCommerce's own order email instead, CKE_Coupon::email_block).
 *  - cke_reminder  "Coupon reminder": sent c_remind_days before expiry while
 *                  the coupon is unused (Action Scheduler, CKE_Coupon::remind).
 * Both can be edited or switched off in WooCommerce → Settings → Emails.
 */

defined( 'ABSPATH' ) || exit;

final class CKE_Emails {

	public static function init(): void {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register' ) );
		add_action( 'cke_coupon_created', array( __CLASS__, 'on_created' ), 10, 2 );
	}

	public static function register( $emails ): array {
		$emails = is_array( $emails ) ? $emails : array();
		require_once CKE_PATH . 'includes/class-email-classes.php';
		$emails['CKE_Email_Coupon']   = new CKE_Email_Coupon();
		$emails['CKE_Email_Reminder'] = new CKE_Email_Reminder();
		return $emails;
	}

	/**
	 * cke_coupon_created: the separate email, when chosen.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 * @param WC_Order  $order  Source order.
	 */
	public static function on_created( $coupon, $order ): void {
		if ( 'separate' !== CKE_Settings::get()['c_email'] || ! $coupon instanceof WC_Coupon || ! $order instanceof WC_Order ) {
			return;
		}
		try {
			$mailer = WC()->mailer();
			$emails = $mailer ? $mailer->get_emails() : array();
			if ( isset( $emails['CKE_Email_Coupon'] ) ) {
				$emails['CKE_Email_Coupon']->trigger( $coupon, $order );
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}
}

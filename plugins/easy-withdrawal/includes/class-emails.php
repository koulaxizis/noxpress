<?php
/**
 * EWD_Emails — three WC_Email classes (design §7), listed under
 * WooCommerce → Settings → Emails, using the store's email template:
 *  - ewd_receipt  customer: acknowledgement of receipt on a durable medium,
 *                 with the statement, the items and the date and time
 *  - ewd_admin    store: a new withdrawal statement
 *  - ewd_status   customer: the request was completed or disputed
 *
 * Customer emails are built in the language of the request, the admin
 * email in the site language (EWD_Lang::with_lang). Templates live in
 * templates/emails/ and can be overridden by the theme in
 * yourtheme/woocommerce/emails/ like any WooCommerce email.
 */

defined( 'ABSPATH' ) || exit;

final class EWD_Emails {

	public static function init(): void {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register' ) );
	}

	public static function register( $emails ): array {
		$emails = is_array( $emails ) ? $emails : array();
		require_once EWD_PATH . 'includes/class-email-classes.php';
		$emails['EWD_Email_Receipt'] = new EWD_Email_Receipt();
		$emails['EWD_Email_Admin']   = new EWD_Email_Admin();
		$emails['EWD_Email_Status']  = new EWD_Email_Status();
		return $emails;
	}

	/** @return WC_Email|null */
	private static function email( string $class ) {
		$mailer = WC()->mailer();
		$all    = $mailer ? $mailer->get_emails() : array();
		return isset( $all[ $class ] ) ? $all[ $class ] : null;
	}

	public static function send_receipt( WC_Order $order, array $req ): bool {
		$email = self::email( 'EWD_Email_Receipt' );
		return $email ? (bool) EWD_Lang::with_lang( (string) $req['lang'], static function () use ( $email, $order, $req ) {
			return $email->trigger( $order, $req );
		} ) : false;
	}

	public static function send_admin( WC_Order $order, array $req ): bool {
		$email = self::email( 'EWD_Email_Admin' );
		return $email ? (bool) EWD_Lang::with_lang( EWD_Lang::site(), static function () use ( $email, $order, $req ) {
			return $email->trigger( $order, $req );
		} ) : false;
	}

	public static function send_status( WC_Order $order, array $req ): bool {
		$email = self::email( 'EWD_Email_Status' );
		return $email ? (bool) EWD_Lang::with_lang( (string) $req['lang'], static function () use ( $email, $order, $req ) {
			return $email->trigger( $order, $req );
		} ) : false;
	}

	/** Shared template arguments. */
	public static function template_args( WC_Order $order, array $req, WC_Email $email, bool $plain ): array {
		$log  = is_array( $req['log'] ?? null ) ? $req['log'] : array();
		$last = $log ? end( $log ) : array();
		return array(
			'order'              => $order,
			'request'            => $req,
			'email_heading'      => $email->get_heading(),
			'additional_content' => $email->get_additional_content(),
			'sent_to_admin'      => ! $email->is_customer_email(),
			'plain_text'         => $plain,
			'email'              => $email,
			'note'               => is_array( $last ) ? (string) ( $last['note'] ?? '' ) : '',
			'instructions'       => EWD_Settings::text( 'instr', (string) $req['lang'] ),
			'admin_url'          => admin_url( 'admin.php?page=ewd-requests&order=' . $order->get_id() ),
		);
	}
}

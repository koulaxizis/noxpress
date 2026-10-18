<?php
/**
 * WC_Email subclasses of Checkout Extras. Loaded only inside the
 * woocommerce_email_classes filter, when WC_Email exists.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared behaviour: the coupon travels with its source order; templates come
 * from the plugin (theme overrides in woocommerce/emails/ win).
 */
abstract class CKE_Email_Base extends WC_Email {

	/** @var WC_Coupon|null The coupon being sent. */
	public $coupon = null;

	/** @var string Text key of the body (CKE_Settings::TEXTS). */
	protected $body_key = 'c_body';

	public function __construct() {
		$this->customer_email = true;
		$this->template_base  = CKE_PATH . 'templates/';
		$this->template_html  = 'emails/cke-coupon.php';
		$this->template_plain = 'emails/plain/cke-coupon.php';
		$this->placeholders   = array(
			'{order_number}' => '',
			'{coupon_code}'  => '',
			'{amount}'       => '',
		);
		parent::__construct();
	}

	/**
	 * Send the email for a coupon.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 * @param WC_Order  $order  Source order.
	 */
	public function trigger( $coupon, $order = null ): bool {
		if ( ! $coupon instanceof WC_Coupon || ! $order instanceof WC_Order ) {
			return false;
		}
		$this->setup_locale();
		$this->object                         = $order;
		$this->coupon                         = $coupon;
		$this->recipient                      = (string) $order->get_billing_email();
		$this->placeholders['{order_number}'] = $order->get_order_number();
		$this->placeholders['{coupon_code}']  = strtoupper( $coupon->get_code() );
		$this->placeholders['{amount}']       = CKE_Coupon::amount_text( $coupon );

		$sent = false;
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
		return $sent;
	}

	private function args( bool $plain ): array {
		return array(
			'order'              => $this->object,
			'coupon'             => $this->coupon,
			'box'                => CKE_Coupon::email_html( $this->coupon, $this->object, $plain, $this->body_key ),
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain,
			'email'              => $this,
		);
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->args( false ), '', $this->template_base );
	}

	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->args( true ), '', $this->template_base );
	}

	public function get_default_additional_content() {
		return '';
	}
}

final class CKE_Email_Coupon extends CKE_Email_Base {

	public function __construct() {
		$this->id          = 'cke_coupon';
		$this->title       = __( 'Κουπόνι ευχαριστίας', 'checkout-extras' );
		$this->description = __( 'Στέλνεται στον πελάτη όταν δημιουργείται το κουπόνι ευχαριστίας, μόνο αν στο Checkout Extras έχεις διαλέξει «Χωριστό email». Αλλιώς το κουπόνι μπαίνει στο email της παραγγελίας.', 'checkout-extras' );
		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Ένα δώρο από το {site_title} για την επόμενη παραγγελία σας', 'checkout-extras' );
	}

	public function get_default_heading() {
		return __( '{amount} έκπτωση για εσάς', 'checkout-extras' );
	}
}

final class CKE_Email_Reminder extends CKE_Email_Base {

	public function __construct() {
		$this->id          = 'cke_reminder';
		$this->body_key    = 'c_remind_body';
		$this->title       = __( 'Υπενθύμιση κουπονιού', 'checkout-extras' );
		$this->description = __( 'Στέλνεται στον πελάτη λίγες ημέρες πριν λήξει ένα κουπόνι ευχαριστίας που δεν χρησιμοποιήθηκε, αν η υπενθύμιση είναι ενεργή στο Checkout Extras.', 'checkout-extras' );
		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Το κουπόνι σας λήγει σύντομα', 'checkout-extras' );
	}

	public function get_default_heading() {
		return __( 'Μη χάσετε την έκπτωσή σας', 'checkout-extras' );
	}
}

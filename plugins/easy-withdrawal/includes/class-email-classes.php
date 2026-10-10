<?php
/**
 * WC_Email subclasses of Easy Withdrawal. Loaded only inside the
 * woocommerce_email_classes filter, when WC_Email exists.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared behaviour: the request travels with the order, templates come
 * from the plugin (theme overrides in woocommerce/emails/ win).
 */
abstract class EWD_Email_Base extends WC_Email {

	/** @var array The request being sent. */
	public $request = array();

	public function __construct() {
		$this->template_base = EWD_PATH . 'templates/';
		$this->placeholders  = array(
			'{order_number}' => '',
			'{request_id}'   => '',
		);
		parent::__construct();
	}

	/**
	 * Send the email for a request.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $req   Request.
	 */
	public function trigger( $order, $req = array() ): bool {
		$this->setup_locale();
		$this->object                         = $order;
		$this->request                        = is_array( $req ) ? $req : array();
		$this->placeholders['{order_number}'] = $order->get_order_number();
		$this->placeholders['{request_id}']   = (string) ( $this->request['id'] ?? '' );
		$this->set_recipient_for_request();

		$sent = false;
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
		return $sent;
	}

	/** Customer emails go to the address stored with the request. */
	protected function set_recipient_for_request(): void {
		$this->recipient = (string) ( $this->request['email'] ?? '' );
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			EWD_Emails::template_args( $this->object, $this->request, $this, false ),
			'',
			$this->template_base
		);
	}

	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			EWD_Emails::template_args( $this->object, $this->request, $this, true ),
			'',
			$this->template_base
		);
	}

	public function get_default_additional_content() {
		return '';
	}
}

final class EWD_Email_Receipt extends EWD_Email_Base {

	public function __construct() {
		$this->id             = 'ewd_receipt';
		$this->customer_email = true;
		$this->title          = __( 'Υπαναχώρηση: απόδειξη παραλαβής', 'easy-withdrawal' );
		$this->description    = __( 'Στέλνεται στον πελάτη αμέσως μετά τη δήλωση υπαναχώρησης, με το περιεχόμενό της και την ημερομηνία και ώρα υποβολής. Η Οδηγία 2023/2673 τη ζητά σε σταθερό μέσο: μην την απενεργοποιήσεις.', 'easy-withdrawal' );
		$this->template_html  = 'emails/ewd-receipt.php';
		$this->template_plain = 'emails/plain/ewd-receipt.php';
		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Λάβαμε τη δήλωση υπαναχώρησης για την παραγγελία #{order_number}', 'easy-withdrawal' );
	}

	public function get_default_heading() {
		return __( 'Λάβαμε τη δήλωση υπαναχώρησης', 'easy-withdrawal' );
	}
}

final class EWD_Email_Admin extends EWD_Email_Base {

	public function __construct() {
		$this->id             = 'ewd_admin';
		$this->customer_email = false;
		$this->title          = __( 'Υπαναχώρηση: νέα δήλωση (κατάστημα)', 'easy-withdrawal' );
		$this->description    = __( 'Στέλνεται στους παραλήπτες των ρυθμίσεων του Easy Withdrawal όταν ένας πελάτης υποβάλει δήλωση υπαναχώρησης.', 'easy-withdrawal' );
		$this->template_html  = 'emails/ewd-admin.php';
		$this->template_plain = 'emails/plain/ewd-admin.php';
		parent::__construct();
	}

	protected function set_recipient_for_request(): void {
		$this->recipient = implode( ', ', EWD_Settings::recipients() );
	}

	public function get_default_subject() {
		return __( 'Νέα υπαναχώρηση {request_id} για την παραγγελία #{order_number}', 'easy-withdrawal' );
	}

	public function get_default_heading() {
		return __( 'Νέα δήλωση υπαναχώρησης', 'easy-withdrawal' );
	}

	/** The recipients live in the plugin's settings, not in this email's form. */
	public function init_form_fields() {
		parent::init_form_fields();
		unset( $this->form_fields['recipient'] );
	}
}

final class EWD_Email_Status extends EWD_Email_Base {

	public function __construct() {
		$this->id             = 'ewd_status';
		$this->customer_email = true;
		$this->title          = __( 'Υπαναχώρηση: αλλαγή κατάστασης', 'easy-withdrawal' );
		$this->description    = __( 'Στέλνεται στον πελάτη όταν ένα αίτημα υπαναχώρησης ολοκληρωθεί ή αμφισβητηθεί, με τη σημείωση του καταστήματος.', 'easy-withdrawal' );
		$this->template_html  = 'emails/ewd-status.php';
		$this->template_plain = 'emails/plain/ewd-status.php';
		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Ενημέρωση για την υπαναχώρηση {request_id}', 'easy-withdrawal' );
	}

	public function get_default_heading() {
		return __( 'Ενημέρωση για την υπαναχώρησή σου', 'easy-withdrawal' );
	}
}

<?php
/**
 * EVT_Email_Update — «Events: ενημέρωση εκδήλωσης», the buyers' notice
 * (1.1.0, Π29). Customer email, off by default (Bible §14.27). Loaded only
 * inside woocommerce_email_classes, when WC_Email exists.
 *
 * Template: emails/evt-update.php (and plain/), overridable by the theme in
 * yourtheme/woocommerce/emails/.
 */

defined( 'ABSPATH' ) || exit;

class EVT_Email_Update extends WC_Email {

	/** @var int The event. */
	public $event_id = 0;

	/** @var string The message of the shop. */
	public $message = '';

	public function __construct() {
		$this->id             = 'evt_update';
		$this->customer_email = true;
		$this->title          = __( 'Events: ενημέρωση εκδήλωσης', 'events' );
		$this->description    = __( 'Ένα μήνυμα του καταστήματος σε όσους έχουν εισιτήρια για μια εκδήλωση (αλλαγή ώρας, αναβολή, ακύρωση). Στέλνεται μόνο με το «Ενημέρωσε τους αγοραστές» στη λίστα συμμετεχόντων.', 'events' );
		$this->template_base  = EVT_PATH . 'templates/';
		$this->template_html  = 'emails/evt-update.php';
		$this->template_plain = 'emails/plain/evt-update.php';
		$this->placeholders   = array( '{event_title}' => '' );
		parent::__construct();
		$this->enabled = $this->get_option( 'enabled', 'no' );
	}

	public function init_form_fields() {
		parent::init_form_fields();
		if ( isset( $this->form_fields['enabled'] ) ) {
			$this->form_fields['enabled']['default'] = 'no';
		}
	}

	public function get_default_subject() {
		return __( 'Ενημέρωση για την εκδήλωση «{event_title}»', 'events' );
	}

	public function get_default_heading() {
		return __( 'Ενημέρωση εκδήλωσης', 'events' );
	}

	/**
	 * @param WC_Order $order    The order with tickets.
	 * @param int      $event_id The event.
	 * @param string   $message  The shop's message.
	 */
	public function trigger( $order, $event_id = 0, $message = '' ): bool {
		$this->setup_locale();
		$this->object    = $order;
		$this->event_id  = (int) $event_id;
		$this->message   = (string) $message;
		$this->recipient = $order instanceof WC_Order ? (string) $order->get_billing_email() : '';

		$this->placeholders['{event_title}'] = wp_strip_all_tags( get_the_title( $this->event_id ) );

		$sent = false;
		if ( $order instanceof WC_Order && $this->is_enabled() && $this->get_recipient() && '' !== $this->message ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
		return $sent;
	}

	protected function args( bool $plain ): array {
		$e = EVT_CPT::event( $this->event_id, true );
		return array(
			'order'              => $this->object,
			'event'              => $e,
			'when'               => $e ? EVT_CPT::when( $e ) : '',
			'where'              => $e ? EVT_Front::where_short( $e ) : '',
			'message'            => $this->message,
			'tickets_url'        => $this->object instanceof WC_Order ? EVT_Tickets::page_url( $this->object ) : '',
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
}

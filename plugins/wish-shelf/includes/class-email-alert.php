<?php
/**
 * WSH_Email_Alert — the customer email of WSH_Alerts (WC_Email). Loaded
 * only inside the woocommerce_email_classes filter, when WC_Email exists.
 * Off until the shop turns it on; templates in templates/emails/ (theme
 * overrides in yourtheme/woocommerce/emails/ win).
 */

defined( 'ABSPATH' ) || exit;

final class WSH_Email_Alert extends WC_Email {

	/** @var array[] Lines of this email (WSH_Alerts::line()). */
	public $lines = array();

	/** @var int Customer id. */
	public $user_id = 0;

	public function __construct() {
		$this->id             = WSH_Alerts::EMAIL_ID;
		$this->customer_email = true;
		$this->title          = __( 'Λίστα επιθυμιών: πτώση τιμής ή ξανά σε απόθεμα', 'wish-shelf' );
		$this->description    = __( 'Στέλνεται μία φορά την ημέρα το πολύ στους πελάτες που το ζήτησαν κάτω από τη λίστα τους, όταν ένα προϊόν της λίστας τους έπεσε σε τιμή ή ξαναμπήκε σε απόθεμα (Noxpress → Λίστα επιθυμιών → Λίστα).', 'wish-shelf' );
		$this->template_base  = WSH_PATH . 'templates/';
		$this->template_html  = 'emails/wsh-alert.php';
		$this->template_plain = 'emails/plain/wsh-alert.php';
		$this->placeholders   = array(
			'{product}' => '',
		);
		parent::__construct();
		$this->enabled = $this->get_option( 'enabled', 'no' );
	}

	/**
	 * Sends the email to the customer's account address.
	 *
	 * @param WP_User $user  Customer.
	 * @param array[] $lines Lines.
	 */
	public function trigger( $user, $lines = array() ): bool {
		if ( ! $user instanceof WP_User || ! $lines ) {
			return false;
		}
		$this->setup_locale();
		$this->object                     = $user;
		$this->user_id                    = (int) $user->ID;
		$this->lines                      = (array) $lines;
		$this->recipient                  = (string) $user->user_email;
		$this->placeholders['{product}'] = (string) $this->lines[0]['name'];

		$sent = false;
		if ( $this->is_enabled() && $this->get_recipient() ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
		return $sent;
	}

	private function args( bool $plain ): array {
		$user = $this->object instanceof WP_User ? $this->object : null;
		return array(
			'user'               => $user,
			'first_name'         => $user ? (string) $user->first_name : '',
			'lines'              => $this->lines,
			'list_url'           => WSH_Settings::list_url(),
			'unsub_url'          => WSH_Alerts::unsub_url( $this->user_id ),
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

	public function get_default_subject() {
		return __( 'Νέα από τη λίστα επιθυμιών σας: {product}', 'wish-shelf' );
	}

	public function get_default_heading() {
		return __( 'Νέα από τη λίστα επιθυμιών σας', 'wish-shelf' );
	}

	public function get_default_additional_content() {
		return '';
	}

	/** Off by default: the shop turns it on. */
	public function init_form_fields() {
		parent::init_form_fields();
		if ( isset( $this->form_fields['enabled'] ) ) {
			$this->form_fields['enabled']['default'] = 'no';
		}
	}
}

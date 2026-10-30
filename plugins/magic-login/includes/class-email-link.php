<?php
/**
 * MGL_Email_Link — the WC_Email with the login link. Loaded only inside
 * the woocommerce_email_classes filter, when WC_Email exists.
 *
 * The link is never stored here: it lives in $this->link only while the
 * e-mail is built, and the "additional content" field of the e-mail form
 * is plain text added below it.
 */

defined( 'ABSPATH' ) || exit;

final class MGL_Email_Link extends WC_Email {

	/** @var string The login link of the e-mail being sent. */
	public $link = '';

	public function __construct() {
		$this->id             = 'mgl_link';
		$this->customer_email = true;
		$this->title          = __( 'Magic Login: σύνδεσμος σύνδεσης', 'magic-login' );
		$this->description    = __( 'Στέλνεται στον πελάτη όταν ζητά σύνδεση χωρίς κωδικό, με σύνδεσμο μίας χρήσης. Αν το απενεργοποιήσεις, το κουμπί «Στείλε μου σύνδεσμο σύνδεσης» κρύβεται.', 'magic-login' );
		$this->template_base  = MGL_PATH . 'templates/';
		$this->template_html  = 'emails/mgl-link.php';
		$this->template_plain = 'emails/plain/mgl-link.php';
		$this->placeholders   = array(
			'{customer_name}' => '',
		);
		parent::__construct();
	}

	/**
	 * Send the link to the user.
	 *
	 * @param WP_User $user User.
	 * @param string  $link Login link.
	 */
	public function trigger( $user, $link = '' ): bool {
		$this->setup_locale();
		$this->object                          = $user;
		$this->link                            = (string) $link;
		$this->recipient                       = $user instanceof WP_User ? (string) $user->user_email : '';
		$this->placeholders['{customer_name}'] = $this->customer_name();

		$sent = false;
		if ( $this->is_enabled() && $this->get_recipient() && '' !== $this->link ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->link = '';
		$this->restore_locale();
		return $sent;
	}

	/** First name, else the display name. */
	private function customer_name(): string {
		if ( ! $this->object instanceof WP_User ) {
			return '';
		}
		$first = trim( (string) get_user_meta( $this->object->ID, 'first_name', true ) );
		return '' !== $first ? $first : (string) $this->object->display_name;
	}

	private function args( bool $plain ): array {
		return array(
			'user'               => $this->object,
			'name'               => $this->customer_name(),
			'link'               => $this->link,
			'minutes'            => (int) MGL_Settings::get()['ttl'],
			'site'               => $this->get_blogname(),
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
		return __( 'Ο σύνδεσμος σύνδεσης στο {site_title}', 'magic-login' );
	}

	public function get_default_heading() {
		return __( 'Σύνδεση χωρίς κωδικό', 'magic-login' );
	}

	public function get_default_additional_content() {
		return '';
	}
}

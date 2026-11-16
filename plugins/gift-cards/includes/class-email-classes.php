<?php
/**
 * WC_Email subclasses of Gift Cards. Loaded only inside the
 * woocommerce_email_classes filter, when WC_Email exists.
 *
 * Templates come from the plugin (theme overrides in woocommerce/emails/
 * win). Default subjects follow the card's language (customer texts,
 * Bible §14.17); a subject typed in WooCommerce › Settings › Emails
 * replaces them for both languages.
 */

defined( 'ABSPATH' ) || exit;

abstract class GFC_Email_Base extends WC_Email {

	/** @var array|null The card (GFC_Store row). */
	public $card = null;

	/** @var string 'el' | 'en'. */
	public $lang = 'el';

	/** @var string Customer-text key of the default subject. */
	protected $subject_key = '';

	/** @var string Customer-text key of the default heading ('' = subject key). */
	protected $heading_key = '';

	public function __construct() {
		$this->template_base  = GFC_PATH . 'templates/';
		$this->template_html  = 'emails/gfc-notice.php';
		$this->template_plain = 'emails/plain/gfc-notice.php';
		$this->placeholders   = array_merge(
			$this->placeholders,
			array(
				'{amount}'  => '',
				'{balance}' => '',
			)
		);
		parent::__construct();
	}

	protected function set_card( array $card ): void {
		$this->card                      = $card;
		$this->lang                      = 'en' === ( $card['lang'] ?? '' ) ? 'en' : 'el';
		$this->placeholders['{amount}']  = GFC_Settings::money( (int) $card['initial'], (string) $card['currency'] );
		$this->placeholders['{balance}'] = GFC_Settings::money( (int) $card['balance'], (string) $card['currency'] );
	}

	/** Address of the person who holds the card. */
	protected static function holder( array $card ): string {
		$to = $card['for_me'] ? (string) $card['buyer_email'] : (string) $card['recipient_email'];
		return is_email( $to ) ? $to : '';
	}

	public function get_default_subject() {
		return $this->subject_key ? GFC_Settings::text( $this->subject_key, $this->lang ) : '';
	}

	public function get_default_heading() {
		$key = $this->heading_key ? $this->heading_key : $this->subject_key;
		return $key ? GFC_Settings::text( $key, $this->lang ) : '';
	}

	public function get_default_additional_content() {
		return '';
	}

	/** Paragraphs of the body, plain text (escaped by the template). */
	protected function lines(): array {
		return array();
	}

	protected function args( bool $plain ): array {
		return array(
			'card'               => $this->card,
			'lines'              => $this->card ? $this->lines() : array(),
			'lang'               => $this->lang,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => ! $this->is_customer_email(),
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

	protected function deliver(): bool {
		$to = $this->get_recipient();
		if ( ! $to ) {
			return false;
		}
		return (bool) $this->send( $to, $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
	}
}

/**
 * The card itself, with its code: to the recipient, or to the buyer
 * ("for me"). Always sends: switching it off would lose the code, which
 * exists nowhere else (E3), so the "Enable" field is removed.
 */
final class GFC_Email_Card extends GFC_Email_Base {

	/** @var string The code, only while this email is being built. */
	public $code = '';

	/** @var string|null Temporary PDF file, only while sending. */
	private $pdf = null;

	public function __construct() {
		$this->id             = 'gfc_card';
		$this->customer_email = true;
		$this->title          = __( 'Δωροκάρτα (με τον κωδικό)', 'gift-cards' );
		$this->description    = __( 'Η ίδια η δωροκάρτα με τον κωδικό, στον παραλήπτη ή στον αγοραστή («Για μένα»), με το PDF συνημμένο. Δεν απενεργοποιείται: ο κωδικός δεν φυλάγεται πουθενά αλλού.', 'gift-cards' );
		$this->subject_key    = 'subj_card';
		$this->heading_key    = 'head_card';
		parent::__construct();
		$this->template_html  = 'emails/gfc-card.php';
		$this->template_plain = 'emails/plain/gfc-card.php';
		$this->enabled        = 'yes';
	}

	public function init_form_fields() {
		parent::init_form_fields();
		unset( $this->form_fields['enabled'] );
	}

	public function is_enabled() {
		return true;
	}

	/**
	 * @param array  $card The card.
	 * @param string $code Its code (never stored).
	 */
	public function trigger( $card, $code = '' ): bool {
		if ( ! is_array( $card ) || '' === (string) $code ) {
			return false;
		}
		$this->setup_locale();
		$this->set_card( $card );
		$this->code      = (string) $code;
		$this->recipient = self::holder( $card );
		$sent            = false;
		try {
			$this->pdf = GFC_Emails::pdf_file( $card, $this->code );
			$sent      = $this->deliver();
		} finally {
			if ( $this->pdf && file_exists( $this->pdf ) ) {
				wp_delete_file( $this->pdf );
			}
			$this->pdf  = null;
			$this->code = '';
			$this->restore_locale();
		}
		return $sent;
	}

	public function get_attachments() {
		$files = parent::get_attachments();
		$files = is_array( $files ) ? $files : array();
		if ( $this->pdf ) {
			$files[] = $this->pdf;
		}
		return $files;
	}

	protected function args( bool $plain ): array {
		$card = $this->card;
		$args = parent::args( $plain );
		if ( ! $card ) {
			return $args + array(
				'intro'   => '',
				'code'    => '',
				'how'     => '',
				'expires' => '',
				'keep'    => '',
				'shop'    => '',
				'shop_t'  => '',
			);
		}
		$l      = $this->lang;
		$amount = GFC_Settings::money( (int) $card['initial'], (string) $card['currency'] );
		$site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$sender = '' !== (string) $card['sender_name'] ? (string) $card['sender_name'] : $site;
		$intro  = $card['for_me']
			? GFC_Settings::fill_plain( 'email_intro_me', array( 'amount' => $amount, 'site' => $site ), $l )
			: GFC_Settings::fill_plain( 'email_intro', array( 'amount' => $amount, 'site' => $site, 'sender' => $sender ), $l );
		$exp    = '';
		if ( ! empty( $card['expires_at'] ) ) {
			$exp = GFC_Settings::fill_plain( 'email_expires', array( 'date' => wp_date( (string) get_option( 'date_format' ), (int) strtotime( $card['expires_at'] . ' UTC' ) ) ), $l );
		}
		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		return array_merge(
			$args,
			array(
				'intro'   => $intro,
				'code'    => GFC_Codes::format( $this->code ),
				'how'     => GFC_Settings::text( 'email_how', $l ),
				'expires' => $exp,
				'keep'    => GFC_Settings::text( 'email_keep', $l ),
				'shop'    => $shop ? $shop : home_url( '/' ),
				'shop_t'  => GFC_Settings::text( 'email_shop', $l ),
			)
		);
	}
}

/** To the buyer of a gift: "sent to …", without the code (E9). */
final class GFC_Email_Sent extends GFC_Email_Base {

	public function __construct() {
		$this->id             = 'gfc_sent';
		$this->customer_email = true;
		$this->title          = __( 'Δωροκάρτα: επιβεβαίωση στον αγοραστή', 'gift-cards' );
		$this->description    = __( 'Στον αγοραστή ενός δώρου, όταν φύγει η δωροκάρτα στον παραλήπτη. Δεν περιέχει τον κωδικό.', 'gift-cards' );
		$this->subject_key    = 'subj_sent';
		parent::__construct();
	}

	public function trigger( $card ): bool {
		if ( ! is_array( $card ) || ! is_email( (string) $card['buyer_email'] ) ) {
			return false;
		}
		$this->setup_locale();
		$this->set_card( $card );
		$this->recipient = (string) $card['buyer_email'];
		$sent            = $this->is_enabled() ? $this->deliver() : false;
		$this->restore_locale();
		return $sent;
	}

	protected function lines(): array {
		$c = $this->card;
		return array(
			GFC_Settings::fill_plain(
				'email_sent',
				array(
					'amount'    => GFC_Settings::money( (int) $c['initial'], (string) $c['currency'] ),
					'recipient' => (string) $c['recipient_name'],
					'email'     => (string) $c['recipient_email'],
				),
				$this->lang
			),
		);
	}
}

/** To the recipient, remind_days before expiry. */
final class GFC_Email_Remind extends GFC_Email_Base {

	public function __construct() {
		$this->id             = 'gfc_remind';
		$this->customer_email = true;
		$this->title          = __( 'Δωροκάρτα: υπενθύμιση λήξης', 'gift-cards' );
		$this->description    = __( 'Στον κάτοχο της δωροκάρτας, λίγες ημέρες πριν λήξει, αν έχει ακόμα υπόλοιπο.', 'gift-cards' );
		$this->subject_key    = 'subj_remind';
		parent::__construct();
	}

	public function trigger( $card ): bool {
		if ( ! is_array( $card ) ) {
			return false;
		}
		$this->setup_locale();
		$this->set_card( $card );
		$this->recipient = self::holder( $card );
		$sent            = $this->is_enabled() ? $this->deliver() : false;
		$this->restore_locale();
		return $sent;
	}

	protected function lines(): array {
		$c = $this->card;
		return array(
			GFC_Settings::fill_plain(
				'email_remind',
				array(
					'card'    => GFC_Codes::mask( (string) $c['last4'] ),
					'balance' => GFC_Settings::money( (int) $c['balance'], (string) $c['currency'] ),
					'date'    => $c['expires_at'] ? wp_date( (string) get_option( 'date_format' ), (int) strtotime( $c['expires_at'] . ' UTC' ) ) : '',
				),
				$this->lang
			),
			GFC_Settings::text( 'email_how', $this->lang ),
		);
	}
}

/** To the shop: cards of an order waiting for approval (E10). */
final class GFC_Email_Review extends GFC_Email_Base {

	/** @var int[] */
	public $ids = array();

	public function __construct() {
		$this->id          = 'gfc_review';
		$this->title       = __( 'Δωροκάρτες σε αναμονή έγκρισης', 'gift-cards' );
		$this->description = __( 'Στο κατάστημα, όταν μια παραγγελία δημιουργεί δωροκάρτες πάνω από το όριο ελέγχου. Οι κάρτες φεύγουν μόνο μετά την έγκριση.', 'gift-cards' );
		parent::__construct();
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	public function init_form_fields() {
		parent::init_form_fields();
		$this->form_fields = array_merge(
			array_slice( $this->form_fields, 0, 1, true ),
			array(
				'recipient' => array(
					'title'       => __( 'Παραλήπτες', 'gift-cards' ),
					'type'        => 'text',
					/* translators: %s: admin email */
					'description' => sprintf( __( 'Ένα ή περισσότερα email, χωρισμένα με κόμμα. Κενό = %s.', 'gift-cards' ), '<code>' . esc_html( get_option( 'admin_email' ) ) . '</code>' ),
					'placeholder' => '',
					'default'     => '',
					'desc_tip'    => false,
				),
			),
			array_slice( $this->form_fields, 1, null, true )
		);
	}

	public function get_default_subject() {
		return __( '[{site_title}] Δωροκάρτες σε αναμονή έγκρισης, παραγγελία #{order_number}', 'gift-cards' );
	}

	public function get_default_heading() {
		return __( 'Δωροκάρτες σε αναμονή έγκρισης', 'gift-cards' );
	}

	public function trigger( $order, $ids = array() ): bool {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		$this->setup_locale();
		$this->object                         = $order;
		$this->ids                            = array_map( 'intval', (array) $ids );
		$this->placeholders['{order_number}'] = $order->get_order_number();
		$this->card                           = array( 'id' => 0 );
		$sent                                 = $this->is_enabled() ? $this->deliver() : false;
		$this->restore_locale();
		return $sent;
	}

	protected function lines(): array {
		$out = array(
			/* translators: %s: order number */
			sprintf( __( 'Η παραγγελία #%s δημιούργησε δωροκάρτες πάνω από το όριο ελέγχου. Δεν έχουν σταλεί: έλεγξε την παραγγελία και έγκρινε ή ακύρωσε κάθε κάρτα.', 'gift-cards' ), $this->object ? $this->object->get_order_number() : '' ),
		);
		foreach ( $this->ids as $id ) {
			$c = GFC_Store::get( $id );
			if ( $c ) {
				$to    = $c['for_me'] ? __( 'για τον αγοραστή', 'gift-cards' ) : (string) $c['recipient_email'];
				$out[] = '#' . $c['id'] . ' · ' . GFC_Settings::money( (int) $c['initial'], (string) $c['currency'] ) . ' · ' . $to;
			}
		}
		$out[] = admin_url( 'admin.php?page=gfc-cards&status=review' );
		return $out;
	}
}

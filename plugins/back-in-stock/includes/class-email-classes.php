<?php
/**
 * The two WC_Email classes. Loaded only inside woocommerce_email_classes,
 * when WC_Email exists. Both are off by default (Bible §14.27).
 */

defined( 'ABSPATH' ) || exit;

/** Shared parts: the request, its product, the args of the templates. */
abstract class BIS_Email_Base extends WC_Email {

	/** @var array The request being sent. */
	public $row = array();

	/** @var string The link of the e-mail (confirm or unsubscribe). */
	public $link = '';

	public function __construct() {
		$this->customer_email = true;
		$this->template_base  = BIS_PATH . 'templates/';
		$this->placeholders   = array( '{product_name}' => '' );
		parent::__construct();
		$this->enabled = $this->get_option( 'enabled', 'no' );
	}

	public function init_form_fields() {
		parent::init_form_fields();
		if ( isset( $this->form_fields['enabled'] ) ) {
			$this->form_fields['enabled']['default'] = 'no';
		}
	}

	/** The product (or variation) of the request. */
	protected function item() {
		$id = ! empty( $this->row['variation_id'] ) ? (int) $this->row['variation_id'] : (int) ( $this->row['product_id'] ?? 0 );
		return $id > 0 ? wc_get_product( $id ) : null;
	}

	/**
	 * @param array  $row  The request.
	 * @param string $link The link.
	 */
	public function trigger( $row, $link = '' ): bool {
		$this->setup_locale();
		$this->row       = is_array( $row ) ? $row : array();
		$this->link      = (string) $link;
		$this->recipient = isset( $this->row['email'] ) ? (string) $this->row['email'] : '';
		$item            = $this->item();
		$this->object    = $item;

		$this->placeholders['{product_name}'] = $item ? wp_strip_all_tags( $item->get_name() ) : '';

		$sent = false;
		if ( $item && $this->is_enabled() && $this->get_recipient() && '' !== $this->link ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->row  = array();
		$this->link = '';
		$this->restore_locale();
		return $sent;
	}

	protected function args( bool $plain ): array {
		$item  = $this->object;
		$pid   = (int) ( $this->row['product_id'] ?? 0 );
		$image = '';
		if ( $item instanceof WC_Product ) {
			$img_id = $item->get_image_id() ? $item->get_image_id() : ( $pid ? get_post_thumbnail_id( $pid ) : 0 );
			$image  = $img_id ? (string) wp_get_attachment_image_url( (int) $img_id, 'woocommerce_thumbnail' ) : '';
		}
		return array(
			'product'            => $item,
			'name'               => $item instanceof WC_Product ? wp_strip_all_tags( $item->get_name() ) : '',
			'url'                => $item instanceof WC_Product ? (string) $item->get_permalink() : '',
			'price'              => $item instanceof WC_Product ? (string) $item->get_price_html() : '',
			'image'              => $image,
			'also'               => BIS_Emails::also_formats( $pid ),
			'link'               => $this->link,
			'hours'              => (int) BIS_Settings::get()['confirm_h'],
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

	public function get_default_additional_content() {
		return '';
	}
}

/** Double opt-in for a guest. */
final class BIS_Email_Confirm extends BIS_Email_Base {

	public function __construct() {
		$this->id             = 'bis_confirm';
		$this->title          = __( 'Back in Stock: επιβεβαίωση', 'back-in-stock' );
		$this->description    = __( 'Στέλνεται στον επισκέπτη χωρίς λογαριασμό που ζήτησε ειδοποίηση, για να επιβεβαιώσει ότι το email είναι δικό του. Αν το απενεργοποιήσεις, τη φόρμα τη βλέπουν μόνο οι συνδεδεμένοι πελάτες.', 'back-in-stock' );
		$this->template_html  = 'emails/bis-confirm.php';
		$this->template_plain = 'emails/plain/bis-confirm.php';
		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Επιβεβαίωσε την ειδοποίηση για το {product_name}', 'back-in-stock' );
	}

	public function get_default_heading() {
		return __( 'Επιβεβαίωσε την ειδοποίηση', 'back-in-stock' );
	}
}

/** The product is back in stock. */
final class BIS_Email_Restock extends BIS_Email_Base {

	public function __construct() {
		$this->id             = 'bis_restock';
		$this->title          = __( 'Back in Stock: ξανά σε απόθεμα', 'back-in-stock' );
		$this->description    = __( 'Στέλνεται μία φορά σε όποιον ζήτησε ειδοποίηση, όταν το προϊόν ξαναμπεί σε απόθεμα. Αν το απενεργοποιήσεις, η φόρμα δεν εμφανίζεται πουθενά και τίποτα δεν στέλνεται.', 'back-in-stock' );
		$this->template_html  = 'emails/bis-restock.php';
		$this->template_plain = 'emails/plain/bis-restock.php';
		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Ξανά σε απόθεμα: {product_name}', 'back-in-stock' );
	}

	public function get_default_heading() {
		return __( 'Ήρθε ξανά', 'back-in-stock' );
	}
}

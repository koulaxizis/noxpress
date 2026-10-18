<?php
/**
 * WC_Email subclasses of Pre-Orders. Loaded only inside the
 * woocommerce_email_classes filter, when WC_Email exists.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared behaviour: the order and the lines travel together, templates
 * come from the plugin (theme overrides in woocommerce/emails/ win), off
 * until the shop turns them on.
 */
abstract class POR_Email_Base extends WC_Email {

	/** @var int[] Order item IDs of this email. */
	public $item_ids = array();

	public function __construct() {
		$this->customer_email = true;
		$this->template_base  = POR_PATH . 'templates/';
		$this->placeholders   = array(
			'{order_number}' => '',
			'{product}'      => '',
			'{date}'         => '',
		);
		parent::__construct();
		$this->enabled = $this->get_option( 'enabled', 'no' );
	}

	/**
	 * Sends the email to the order's billing address.
	 *
	 * @param WC_Order $order    Order.
	 * @param int[]    $item_ids Order item IDs.
	 */
	public function trigger( $order, $item_ids = array() ): bool {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		$this->setup_locale();
		$this->object   = $order;
		$this->item_ids = array_map( 'intval', (array) $item_ids );
		$this->recipient = (string) $order->get_billing_email();

		$first = null;
		foreach ( $this->item_ids as $iid ) {
			$item = $order->get_item( $iid );
			if ( $item instanceof WC_Order_Item_Product ) {
				$first = $item;
				break;
			}
		}
		$date = $first ? (string) $first->get_meta( POR_Orders::I_RELEASE, true ) : '';

		$this->placeholders['{order_number}'] = $order->get_order_number();
		$this->placeholders['{product}']      = $first ? $first->get_name() : '';
		$this->placeholders['{date}']         = POR_Settings::valid_date( $date ) ? POR_Settings::format_date( $date ) : '';

		$sent = false;
		if ( $this->is_enabled() && $this->get_recipient() && $first ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
		$this->restore_locale();
		return $sent;
	}

	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			POR_Emails::template_args( $this->object, $this->item_ids, $this, false ),
			'',
			$this->template_base
		);
	}

	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			POR_Emails::template_args( $this->object, $this->item_ids, $this, true ),
			'',
			$this->template_base
		);
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

final class POR_Email_Released extends POR_Email_Base {

	public function __construct() {
		$this->id             = 'por_released';
		$this->title          = __( 'Προπαραγγελία: κυκλοφόρησε', 'pre-orders' );
		$this->description    = __( 'Στέλνεται στον πελάτη την ημέρα κυκλοφορίας ενός προϊόντος που προπαρήγγειλε, για τις παραγγελίες σε επεξεργασία ή σε αναμονή. Μία φορά ανά παραγγελία και προϊόν.', 'pre-orders' );
		$this->template_html  = 'emails/por-released.php';
		$this->template_plain = 'emails/plain/por-released.php';
		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Κυκλοφόρησε: {product}', 'pre-orders' );
	}

	public function get_default_heading() {
		return __( 'Κυκλοφόρησε!', 'pre-orders' );
	}
}

final class POR_Email_New_Date extends POR_Email_Base {

	public function __construct() {
		$this->id             = 'por_new_date';
		$this->title          = __( 'Προπαραγγελία: νέα ημερομηνία κυκλοφορίας', 'pre-orders' );
		$this->description    = __( 'Στέλνεται στον πελάτη όταν αλλάζεις την ημερομηνία κυκλοφορίας ενός προϊόντος και επιλέγεις να ενημερωθούν οι ανοιχτές προπαραγγελίες.', 'pre-orders' );
		$this->template_html  = 'emails/por-new-date.php';
		$this->template_plain = 'emails/plain/por-new-date.php';
		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Νέα ημερομηνία κυκλοφορίας για την παραγγελία #{order_number}', 'pre-orders' );
	}

	public function get_default_heading() {
		return __( 'Νέα ημερομηνία κυκλοφορίας', 'pre-orders' );
	}
}

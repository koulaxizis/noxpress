<?php
/**
 * POR_Emails — two customer emails as WC_Email classes, listed under
 * WooCommerce → Settings → Emails and built with the store's email
 * template. Both are OFF by default (design question 10):
 *  - por_released  «Released»: the release job of a product ran; one email
 *                  per order with the lines that were released
 *  - por_new_date  «New release date»: the shop changed the release date
 *                  and asked to tell the customers
 *
 * They are written in the visitor language of the plugin (POR_Settings::
 * lang(), Bible §14.17). Templates live in templates/emails/ and can be
 * overridden by the theme in yourtheme/woocommerce/emails/.
 */

defined( 'ABSPATH' ) || exit;

final class POR_Emails {

	public static function init(): void {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register' ) );
	}

	public static function register( $emails ): array {
		$emails = is_array( $emails ) ? $emails : array();
		require_once POR_PATH . 'includes/class-email-classes.php';
		$emails['POR_Email_Released'] = new POR_Email_Released();
		$emails['POR_Email_New_Date'] = new POR_Email_New_Date();
		return $emails;
	}

	/** @return WC_Email|null */
	private static function email( string $class ) {
		$mailer = function_exists( 'WC' ) ? WC()->mailer() : null;
		$all    = $mailer ? $mailer->get_emails() : array();
		return isset( $all[ $class ] ) ? $all[ $class ] : null;
	}

	/** True when an email is enabled in WooCommerce's settings. */
	public static function enabled( string $class ): bool {
		$email = self::email( $class );
		return $email ? $email->is_enabled() : false;
	}

	/**
	 * «Released» email for some lines of an order.
	 *
	 * @param int[] $item_ids Order item IDs.
	 */
	public static function released( WC_Order $order, array $item_ids ): bool {
		return self::send( 'POR_Email_Released', $order, $item_ids );
	}

	/**
	 * «New release date» email for some lines of an order.
	 *
	 * @param int[] $item_ids Order item IDs.
	 */
	public static function new_date( WC_Order $order, array $item_ids ): bool {
		return self::send( 'POR_Email_New_Date', $order, $item_ids );
	}

	private static function send( string $class, WC_Order $order, array $item_ids ): bool {
		$email = self::email( $class );
		if ( ! $email || ! $email->is_enabled() ) {
			return false;
		}
		return (bool) POR_Lang::with_lang(
			POR_Settings::lang(),
			static function () use ( $email, $order, $item_ids ) {
				return $email->trigger( $order, $item_ids );
			}
		);
	}

	/**
	 * Template arguments shared by both emails.
	 *
	 * @param int[] $item_ids Order item IDs.
	 */
	public static function template_args( WC_Order $order, array $item_ids, WC_Email $email, bool $plain ): array {
		$rows = array();
		foreach ( $item_ids as $item_id ) {
			$item = $order->get_item( (int) $item_id );
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$d      = (string) $item->get_meta( POR_Orders::I_RELEASE, true );
			$rows[] = array(
				'name' => $item->get_name(),
				'qty'  => (int) $item->get_quantity(),
				'date' => POR_Settings::valid_date( $d ) ? POR_Settings::format_date( $d ) : '',
				'text' => POR_Settings::release_text( POR_Settings::valid_date( $d ) ? $d : '' ),
			);
		}
		$account = '';
		if ( $order->get_customer_id() && function_exists( 'wc_get_endpoint_url' ) ) {
			$account = $order->get_view_order_url();
		}
		return array(
			'order'              => $order,
			'rows'               => $rows,
			'account_url'        => $account,
			'email_heading'      => $email->get_heading(),
			'additional_content' => $email->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain,
			'email'              => $email,
		);
	}
}

<?php
/**
 * BIS_Emails — registers the two WooCommerce e-mails (WooCommerce →
 * Settings → Emails), both OFF until the shop switches them on (Bible
 * §14.27), and sends them in the language of the request.
 *
 *  - BIS_Email_Confirm  «Back in Stock: επιβεβαίωση» to a guest after the
 *                       form (double opt-in). Off: guests see no form.
 *  - BIS_Email_Restock  «Back in Stock: ξανά σε απόθεμα». Off: no form at
 *                       all, and nothing is sent.
 *
 * Both go through wp_mail like every WooCommerce e-mail (FluentSMTP or any
 * SMTP plugin). Templates in templates/emails/, overridable by the theme.
 */

defined( 'ABSPATH' ) || exit;

final class BIS_Emails {

	const CONFIRM = 'bis_confirm';
	const RESTOCK = 'bis_restock';

	public static function init(): void {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register' ) );
	}

	public static function register( $emails ): array {
		$emails = is_array( $emails ) ? $emails : array();
		require_once BIS_PATH . 'includes/class-email-classes.php';
		$emails['BIS_Email_Confirm'] = new BIS_Email_Confirm();
		$emails['BIS_Email_Restock'] = new BIS_Email_Restock();
		return $emails;
	}

	/** Whether an e-mail is switched on, read from its option (no mailer loaded). */
	public static function enabled( string $id ): bool {
		$o = get_option( 'woocommerce_' . $id . '_settings', array() );
		return is_array( $o ) && isset( $o['enabled'] ) && 'yes' === $o['enabled'];
	}

	public static function settings_url( string $class ): string {
		return admin_url( 'admin.php?page=wc-settings&tab=email&section=' . strtolower( $class ) );
	}

	/** Link the confirm e-mail opens (a page with a POST button). */
	public static function confirm_link( int $id, string $token ): string {
		return add_query_arg(
			array(
				'bis' => 'c',
				'r'   => $id,
				'k'   => $token,
			),
			home_url( '/' )
		);
	}

	/** Link that cancels every open request of an address. */
	public static function unsub_link( string $email_hash ): string {
		return add_query_arg(
			array(
				'bis' => 'u',
				'e'   => $email_hash,
				'k'   => BIS_Store::unsub_key( $email_hash ),
			),
			home_url( '/' )
		);
	}

	private static function email( string $class ) {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return null;
		}
		$all = WC()->mailer()->get_emails();
		return isset( $all[ $class ] ) ? $all[ $class ] : null;
	}

	/** The confirm e-mail of a pending request. */
	public static function send_confirm( array $row, string $token ): bool {
		$email = self::email( 'BIS_Email_Confirm' );
		if ( ! $email ) {
			return false;
		}
		return (bool) BIS_Lang::with_lang(
			(string) $row['lang'],
			static function () use ( $email, $row, $token ) {
				return $email->trigger( $row, self::confirm_link( (int) $row['id'], $token ) );
			}
		);
	}

	/** The back-in-stock e-mail of an active request. */
	public static function send_restock( array $row ): bool {
		$email = self::email( 'BIS_Email_Restock' );
		if ( ! $email ) {
			return false;
		}
		return (bool) BIS_Lang::with_lang(
			(string) $row['lang'],
			static function () use ( $email, $row ) {
				return $email->trigger( $row, self::unsub_link( (string) $row['email_hash'] ) );
			}
		);
	}

	/**
	 * Other formats of the same work that are in stock (Product Formats),
	 * as array( array( 'name' => …, 'url' => … ) ).
	 */
	public static function also_formats( int $product_id ): array {
		if ( ! BIS_Settings::get()['also'] || ! function_exists( 'pfm_get_work' ) ) {
			return array();
		}
		$work = pfm_get_work( $product_id );
		if ( ! is_array( $work ) || empty( $work['members'] ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $work['members'] as $m ) {
			$mid = isset( $m['id'] ) ? (int) $m['id'] : 0;
			if ( $mid <= 0 || $mid === $product_id || ( isset( $m['visible'] ) && ! $m['visible'] ) ) {
				continue;
			}
			$p = wc_get_product( $mid );
			if ( ! $p || 'publish' !== $p->get_status() || ! $p->is_in_stock() ) {
				continue;
			}
			$out[] = array(
				'name' => wp_strip_all_tags( $p->get_name() ),
				'url'  => (string) get_permalink( $mid ),
			);
			if ( count( $out ) >= 4 ) {
				break;
			}
		}
		return $out;
	}
}

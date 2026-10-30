<?php
/**
 * MGL_Emails — registers the login-link e-mail (WC_Email, listed under
 * WooCommerce → Settings → Emails as «Magic Login: σύνδεσμος σύνδεσης»)
 * and sends it in the visitor's language (MGL_Lang::with_lang). It goes
 * through wp_mail like every WooCommerce e-mail, so FluentSMTP (or any
 * SMTP plugin) sends it. The template lives in templates/emails/ and can
 * be overridden by the theme in yourtheme/woocommerce/emails/.
 */

defined( 'ABSPATH' ) || exit;

final class MGL_Emails {

	/** WooCommerce's option of the e-mail's own settings (enabled, subject…). */
	const WC_OPT = 'woocommerce_mgl_link_settings';

	public static function init(): void {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register' ) );
	}

	public static function register( $emails ): array {
		$emails = is_array( $emails ) ? $emails : array();
		require_once MGL_PATH . 'includes/class-email-link.php';
		$emails['MGL_Email_Link'] = new MGL_Email_Link();
		return $emails;
	}

	/**
	 * Whether the e-mail is switched on in WooCommerce, read from its
	 * option (cheap: no mailer is loaded on the front end for this).
	 */
	public static function enabled(): bool {
		$o = get_option( self::WC_OPT, array() );
		return ! ( is_array( $o ) && isset( $o['enabled'] ) && 'no' === $o['enabled'] );
	}

	/** Admin URL of the e-mail's settings. */
	public static function settings_url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=email&section=mgl_email_link' );
	}

	/**
	 * Sends the link. True when wp_mail accepted it.
	 *
	 * @param WP_User $user User.
	 * @param string  $link The login link.
	 * @param string  $lang 'el' | 'en'.
	 */
	public static function send( WP_User $user, string $link, string $lang ): bool {
		$mailer = WC()->mailer();
		$all    = $mailer ? $mailer->get_emails() : array();
		$email  = isset( $all['MGL_Email_Link'] ) ? $all['MGL_Email_Link'] : null;
		if ( ! $email ) {
			return false;
		}
		return (bool) MGL_Lang::with_lang(
			$lang,
			static function () use ( $email, $user, $link ) {
				return $email->trigger( $user, $link );
			}
		);
	}
}

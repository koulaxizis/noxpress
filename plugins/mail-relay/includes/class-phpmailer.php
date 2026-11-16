<?php
/**
 * MRL_PHPMailer — WordPress's own PHPMailer (WP_PHPMailer on 6.8+) with one
 * more mailer, "mrlapi". PHPMailer's postSend() calls <Mailer>Send() for a
 * mailer it does not know, so with Mailer = 'mrlapi' the message that
 * PHPMailer has fully built (headers, encodings, attachments, text and
 * HTML parts) goes to MRL_Mailer::api_send() instead of SMTP or mail().
 *
 * The same path carries a refusal: when MRL_Mailer has a reason not to
 * send (host check failed, missing secret), mrlapiSend() throws, so
 * wp_mail() returns false and fires wp_mail_failed as for any error.
 *
 * Loaded only after PHPMailer itself (MRL_Mailer::ensure_phpmailer()).
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'MRL_PHPMailer_Base', false ) ) {
	if ( class_exists( 'WP_PHPMailer' ) ) {
		class MRL_PHPMailer_Base extends WP_PHPMailer {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	} else {
		class MRL_PHPMailer_Base extends PHPMailer\PHPMailer\PHPMailer {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	}
}

final class MRL_PHPMailer extends MRL_PHPMailer_Base {

	/**
	 * Called by postSend() when Mailer is 'mrlapi'.
	 *
	 * @param string $header MIME header built by PHPMailer.
	 * @param string $body   MIME body built by PHPMailer.
	 * @return bool
	 * @throws PHPMailer\PHPMailer\Exception On refusal or provider error.
	 */
	public function mrlapiSend( $header, $body ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- name required by PHPMailer.
		$raw = rtrim( (string) $header, "\r\n" ) . static::$LE . static::$LE . (string) $body;
		$err = MRL_Mailer::api_send( $this, $raw );
		if ( '' !== $err ) {
			throw new PHPMailer\PHPMailer\Exception( $err ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by wp_mail().
		}
		return true;
	}
}

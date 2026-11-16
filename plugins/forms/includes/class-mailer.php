<?php
/**
 * FRM_Mailer — the notification and the optional auto-reply.
 *
 *  - Everything goes through wp_mail(), so an SMTP plugin (Mail Relay,
 *    FluentSMTP…) sends, logs and retries it. Plain text.
 *  - From: not set here, so it stays the site's own address (or the SMTP
 *    plugin's): never the visitor's, which would break SPF / DMARC.
 *  - Reply-To: the visitor's e-mail, only after is_email(), with the name
 *    stripped of line breaks, quotes and angle brackets. Headers are always
 *    an array; no visitor text is ever joined into a header string.
 *  - Subjects: placeholders {site}, {form} and {field key}, line breaks
 *    removed, at most 200 characters.
 *  - Auto-reply (Chris 2026-11-16, Q7): off by default; fixed text of the
 *    form with {name} and {site} only, never what the visitor wrote, so the
 *    site cannot be used to send someone's text to a stranger. At most one
 *    per address per hour.
 *  - No IP is ever written in an e-mail.
 */

defined( 'ABSPATH' ) || exit;

final class FRM_Mailer {

	/** Recipients of a form: its list, else the site e-mail. */
	public static function recipients( array $form ): array {
		$list = '' !== $form['mail']['to'] ? $form['mail']['to'] : (string) get_option( 'admin_email' );
		$out  = array();
		foreach ( explode( ',', $list ) as $e ) {
			$e = trim( $e );
			if ( is_email( $e ) ) {
				$out[] = $e;
			}
		}
		return $out;
	}

	/** Strip a value for a header or a subject: no line breaks, no control characters. */
	public static function header_safe( string $v, int $max = 200 ): string {
		$v = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $v );
		return mb_substr( trim( (string) $v ), 0, $max );
	}

	/** Fill {placeholders} of a subject from the values. */
	public static function fill( string $tpl, array $data ): string {
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$map  = array(
			'{site}' => $site,
			'{form}' => $data['title'],
			'{name}' => $data['name'],
		);
		foreach ( $data['fields'] as $f ) {
			$map[ '{' . $f['key'] . '}' ] = (string) $f['value'];
		}
		$out = strtr( $tpl, $map );
		// Placeholders of empty or missing fields go, with the separators they leave.
		$out = preg_replace( '/\{[a-z0-9_-]+\}/', '', $out );
		$out = preg_replace( '/\s*[:\-–,]\s*$/u', '', (string) $out );
		return self::header_safe( (string) $out );
	}

	/** The notification body: one line per field, then page, product, date. */
	public static function body( array $data ): string {
		$lines = array();
		foreach ( $data['fields'] as $f ) {
			if ( '' === (string) $f['value'] ) {
				continue;
			}
			if ( 'textarea' === $f['type'] ) {
				$lines[] = $f['label'] . ":\n" . $f['value'];
				$lines[] = '';
			} else {
				$lines[] = $f['label'] . ': ' . $f['value'];
			}
		}
		$lines[] = '';
		$lines[] = '--';
		if ( ! empty( $data['product'] ) ) {
			$p       = $data['product'];
			$lines[] = __( 'Προϊόν', 'forms' ) . ': ' . $p['name'] . ( '' !== $p['sku'] ? ' (SKU ' . $p['sku'] . ')' : '' ) . ' ' . $p['url'];
		}
		if ( '' !== $data['page'] ) {
			$lines[] = __( 'Σελίδα', 'forms' ) . ': ' . $data['page'];
		}
		$lines[] = __( 'Φόρμα', 'forms' ) . ': ' . $data['title'];
		$lines[] = __( 'Ημερομηνία', 'forms' ) . ': ' . wp_date( 'Y-m-d H:i', (int) $data['time'] );
		if ( ! empty( $data['entry'] ) ) {
			$lines[] = admin_url( 'admin.php?page=frm-forms&tab=messages&view=' . (int) $data['entry'] );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Send the notification.
	 *
	 * @param array $form Form.
	 * @param array $data Submission (FRM_Submit::build()).
	 * @return bool wp_mail()'s answer.
	 */
	public static function notify( array $form, array $data ): bool {
		$to = self::recipients( $form );
		if ( ! $to ) {
			return false;
		}
		$tpl     = FRM_Settings::pick( $form['mail']['subject_el'], $form['mail']['subject_en'], FRM_Lang::owner_lang() );
		$subject = self::fill( $tpl, $data );
		if ( '' === $subject ) {
			$subject = self::header_safe( $data['title'] );
		}
		$headers = array();
		if ( '' !== $data['email'] && is_email( $data['email'] ) ) {
			$name      = str_replace( array( '"', '<', '>', ',', ';', '\\' ), '', self::header_safe( $data['name'], 80 ) );
			$headers[] = 'Reply-To: ' . ( '' !== $name ? '"' . $name . '" ' : '' ) . '<' . $data['email'] . '>';
		}

		/**
		 * Filters the notification before it goes to wp_mail().
		 *
		 * @param array $args to (string[]), subject, body, headers (string[]).
		 * @param array $form Form.
		 * @param array $data Submission.
		 */
		$args = apply_filters(
			'frm_mail_args',
			array(
				'to'      => $to,
				'subject' => $subject,
				'body'    => self::body( $data ),
				'headers' => $headers,
			),
			$form,
			$data
		);
		if ( ! is_array( $args ) || empty( $args['to'] ) ) {
			return false;
		}
		return (bool) wp_mail( $args['to'], (string) $args['subject'], (string) $args['body'], isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array() );
	}

	/** The auto-reply, when the form has it on and the visitor gave an e-mail. */
	public static function auto_reply( array $form, array $data ): bool {
		if ( empty( $form['auto']['on'] ) || '' === $data['email'] || ! is_email( $data['email'] ) ) {
			return false;
		}
		$once = 'frm_ar_' . substr( md5( strtolower( $data['email'] ) ), 0, 32 );
		if ( get_transient( $once ) ) {
			return false;
		}
		$lang = $data['lang'];
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$vars = array(
			'{name}' => '' !== $data['name'] ? self::header_safe( $data['name'], 80 ) : '',
			'{site}' => $site,
		);
		$subj = self::header_safe( strtr( FRM_Settings::pick( $form['auto']['subject_el'], $form['auto']['subject_en'], $lang ), $vars ) );
		$body = strtr( FRM_Settings::pick( $form['auto']['body_el'], $form['auto']['body_en'], $lang ), $vars );
		// "Hello ," when there is no name.
		$body = preg_replace( '/ +([,.!])/u', '$1', $body );
		if ( '' === $subj || '' === trim( (string) $body ) ) {
			return false;
		}
		set_transient( $once, 1, HOUR_IN_SECONDS );
		return (bool) wp_mail( $data['email'], $subj, (string) $body );
	}
}

<?php
/**
 * MRL_Api — HTTP API adapters: Brevo, Mailgun (US / EU), SendGrid,
 * Postmark, Amazon SES (v2, Signature Version 4).
 *
 * Every endpoint is fixed in the code. The only parts that come from the
 * settings are a Mailgun domain (a validated hostname, encoded as one path
 * segment) and an SES region (from a fixed list), so no request can be
 * pointed at another host (Bible §11, no SSRF). No redirects are followed
 * and TLS is always verified (WordPress default).
 *
 * The message arrives already built by PHPMailer (MRL_PHPMailer), so
 * headers, encodings, attachments and the text / HTML parts are exactly
 * what WordPress would send over SMTP. Mailgun and SES take that raw MIME
 * message; Brevo, SendGrid and Postmark take its fields as JSON.
 *
 * send() returns '' on success or the error text, which the caller
 * scrubs (MRL_Crypt::scrub) before it is shown or logged.
 */

defined( 'ABSPATH' ) || exit;

final class MRL_Api {

	const MAX_BYTES = 20971520; // 20 MB for the whole message.
	const TIMEOUT   = 20;

	/** Provider labels for the admin. */
	public static function labels(): array {
		return array(
			'smtp'     => 'SMTP',
			'brevo'    => 'Brevo',
			'mailgun'  => 'Mailgun',
			'sendgrid' => 'SendGrid',
			'postmark' => 'Postmark',
			'ses'      => 'Amazon SES',
		);
	}

	/**
	 * @param array  $c      Connection (MRL_Settings::sanitize_conn()).
	 * @param string $secret API key / secret access key.
	 * @param array  $m      from, from_name, to, cc, bcc, reply_to (lists of [email, name]),
	 *                       subject, content_type, body, alt, headers ([name, value]),
	 *                       attachments ([filename, bytes, mime, disposition, cid]), raw (MIME).
	 */
	public static function send( array $c, string $secret, array $m ): string {
		if ( '' === $secret ) {
			return 'No API key.';
		}
		if ( strlen( isset( $m['raw'] ) ? (string) $m['raw'] : '' ) > self::MAX_BYTES ) {
			return 'The message is larger than 20 MB.';
		}
		switch ( $c['type'] ) {
			case 'brevo':
				return self::brevo( $secret, $m );
			case 'mailgun':
				return self::mailgun( $c, $secret, $m );
			case 'sendgrid':
				return self::sendgrid( $secret, $m );
			case 'postmark':
				return self::postmark( $secret, $m );
			case 'ses':
				return self::ses( $c, $secret, $m );
		}
		return 'Unknown provider.';
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	private static function post( string $url, array $headers, string $body ): array {
		$r = wp_remote_post(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => $body,
				'user-agent'  => 'Mail Relay/' . MRL_VERSION . '; WordPress',
			)
		);
		if ( is_wp_error( $r ) ) {
			return array( 0, $r->get_error_message() );
		}
		return array( (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_body( $r ) );
	}

	/** '' for 2xx, else «HTTP <code>: <message from the JSON or the body>». */
	private static function result( array $res, string $msg_key = 'message' ): string {
		list( $code, $body ) = $res;
		if ( $code >= 200 && $code < 300 ) {
			return '';
		}
		if ( 0 === $code ) {
			return $body;
		}
		$j   = json_decode( $body, true );
		$txt = '';
		if ( is_array( $j ) ) {
			foreach ( array( $msg_key, 'message', 'Message', 'error', 'errors' ) as $k ) {
				if ( isset( $j[ $k ] ) ) {
					$txt = is_string( $j[ $k ] ) ? $j[ $k ] : (string) wp_json_encode( $j[ $k ] );
					break;
				}
			}
		}
		if ( '' === $txt ) {
			$txt = wp_strip_all_tags( $body );
		}
		return 'HTTP ' . $code . ': ' . substr( trim( $txt ), 0, 300 );
	}

	private static function html( array $m ): bool {
		return false !== stripos( (string) $m['content_type'], 'html' );
	}

	/** Custom headers except the ones the providers set themselves. */
	private static function extra_headers( array $m ): array {
		$out = array();
		foreach ( (array) $m['headers'] as $h ) {
			if ( ! is_array( $h ) || ! isset( $h[0], $h[1] ) ) {
				continue;
			}
			$n = trim( (string) $h[0] );
			if ( '' === $n || preg_match( '/^(from|to|cc|bcc|reply-to|subject|content-type|content-transfer-encoding|mime-version|date|message-id|sender|return-path)$/i', $n ) ) {
				continue;
			}
			$out[ $n ] = (string) $h[1];
		}
		return $out;
	}

	/** «Name <email>» or «email». */
	private static function addr( array $a ): string {
		$e = (string) $a[0];
		$n = isset( $a[1] ) ? trim( (string) $a[1] ) : '';
		return '' === $n ? $e : '"' . str_replace( array( '"', "\r", "\n" ), '', $n ) . '" <' . $e . '>';
	}

	/* ---------------------------------------------------------------------
	 * Brevo (JSON)
	 * ------------------------------------------------------------------- */

	private static function brevo( string $key, array $m ): string {
		$list = static function ( $l ) {
			$o = array();
			foreach ( (array) $l as $a ) {
				$x = array( 'email' => (string) $a[0] );
				if ( ! empty( $a[1] ) ) {
					$x['name'] = (string) $a[1];
				}
				$o[] = $x;
			}
			return $o;
		};
		$p    = array(
			'sender'  => array(
				'email' => $m['from'],
				'name'  => $m['from_name'],
			),
			'to'      => $list( $m['to'] ),
			'subject' => $m['subject'],
		);
		if ( $m['cc'] ) {
			$p['cc'] = $list( $m['cc'] );
		}
		if ( $m['bcc'] ) {
			$p['bcc'] = $list( $m['bcc'] );
		}
		if ( $m['reply_to'] ) {
			$p['replyTo'] = $list( array( $m['reply_to'][0] ) )[0];
		}
		if ( self::html( $m ) ) {
			$p['htmlContent'] = $m['body'];
			if ( '' !== $m['alt'] ) {
				$p['textContent'] = $m['alt'];
			}
		} else {
			$p['textContent'] = $m['body'];
		}
		$h = self::extra_headers( $m );
		if ( $h ) {
			$p['headers'] = $h;
		}
		foreach ( $m['attachments'] as $a ) {
			$p['attachment'][] = array(
				'name'    => $a[0],
				'content' => base64_encode( $a[1] ),
			);
		}
		return self::result(
			self::post(
				'https://api.brevo.com/v3/smtp/email',
				array(
					'api-key'      => $key,
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				(string) wp_json_encode( $p )
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Mailgun (raw MIME, messages.mime)
	 * ------------------------------------------------------------------- */

	private static function mailgun( array $c, string $key, array $m ): string {
		$host = 'eu' === $c['region'] ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';
		$url  = $host . '/v3/' . rawurlencode( $c['domain'] ) . '/messages.mime';
		$to   = array();
		foreach ( array_merge( $m['to'], $m['cc'], $m['bcc'] ) as $a ) {
			$to[] = (string) $a[0];
		}
		$b    = 'mrl' . bin2hex( random_bytes( 12 ) );
		$body = '--' . $b . "\r\n"
			. "Content-Disposition: form-data; name=\"to\"\r\n\r\n"
			. implode( ',', $to ) . "\r\n"
			. '--' . $b . "\r\n"
			. "Content-Disposition: form-data; name=\"message\"; filename=\"message.mime\"\r\n"
			. "Content-Type: message/rfc822\r\n\r\n"
			. $m['raw'] . "\r\n"
			. '--' . $b . "--\r\n";
		return self::result(
			self::post(
				$url,
				array(
					'Authorization' => 'Basic ' . base64_encode( 'api:' . $key ),
					'Content-Type'  => 'multipart/form-data; boundary=' . $b,
				),
				$body
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * SendGrid (JSON)
	 * ------------------------------------------------------------------- */

	private static function sendgrid( string $key, array $m ): string {
		$list = static function ( $l ) {
			$o = array();
			foreach ( (array) $l as $a ) {
				$x = array( 'email' => (string) $a[0] );
				if ( ! empty( $a[1] ) ) {
					$x['name'] = (string) $a[1];
				}
				$o[] = $x;
			}
			return $o;
		};
		$pers = array( 'to' => $list( $m['to'] ) );
		if ( $m['cc'] ) {
			$pers['cc'] = $list( $m['cc'] );
		}
		if ( $m['bcc'] ) {
			$pers['bcc'] = $list( $m['bcc'] );
		}
		$content = array();
		if ( self::html( $m ) ) {
			if ( '' !== $m['alt'] ) {
				$content[] = array(
					'type'  => 'text/plain',
					'value' => $m['alt'],
				);
			}
			$content[] = array(
				'type'  => 'text/html',
				'value' => $m['body'],
			);
		} else {
			$content[] = array(
				'type'  => 'text/plain',
				'value' => '' === $m['body'] ? ' ' : $m['body'],
			);
		}
		$p = array(
			'personalizations' => array( $pers ),
			'from'             => array(
				'email' => $m['from'],
				'name'  => $m['from_name'],
			),
			'subject'          => $m['subject'],
			'content'          => $content,
		);
		if ( $m['reply_to'] ) {
			$p['reply_to'] = $list( array( $m['reply_to'][0] ) )[0];
		}
		$h = self::extra_headers( $m );
		if ( $h ) {
			$p['headers'] = $h;
		}
		foreach ( $m['attachments'] as $a ) {
			$x = array(
				'content'     => base64_encode( $a[1] ),
				'filename'    => $a[0],
				'type'        => $a[2],
				'disposition' => 'inline' === $a[3] ? 'inline' : 'attachment',
			);
			if ( 'inline' === $a[3] && '' !== $a[4] ) {
				$x['content_id'] = $a[4];
			}
			$p['attachments'][] = $x;
		}
		return self::result(
			self::post(
				'https://api.sendgrid.com/v3/mail/send',
				array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				(string) wp_json_encode( $p )
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Postmark (JSON)
	 * ------------------------------------------------------------------- */

	private static function postmark( string $key, array $m ): string {
		$join = static function ( $l ) {
			return implode( ', ', array_map( array( __CLASS__, 'addr' ), (array) $l ) );
		};
		$p    = array(
			'From'          => self::addr( array( $m['from'], $m['from_name'] ) ),
			'To'            => $join( $m['to'] ),
			'Subject'       => $m['subject'],
			'MessageStream' => 'outbound',
		);
		if ( $m['cc'] ) {
			$p['Cc'] = $join( $m['cc'] );
		}
		if ( $m['bcc'] ) {
			$p['Bcc'] = $join( $m['bcc'] );
		}
		if ( $m['reply_to'] ) {
			$p['ReplyTo'] = $join( array( $m['reply_to'][0] ) );
		}
		if ( self::html( $m ) ) {
			$p['HtmlBody'] = $m['body'];
			if ( '' !== $m['alt'] ) {
				$p['TextBody'] = $m['alt'];
			}
		} else {
			$p['TextBody'] = $m['body'];
		}
		foreach ( self::extra_headers( $m ) as $n => $v ) {
			$p['Headers'][] = array(
				'Name'  => $n,
				'Value' => $v,
			);
		}
		foreach ( $m['attachments'] as $a ) {
			$x = array(
				'Name'        => $a[0],
				'Content'     => base64_encode( $a[1] ),
				'ContentType' => $a[2],
			);
			if ( 'inline' === $a[3] && '' !== $a[4] ) {
				$x['ContentID'] = 'cid:' . $a[4];
			}
			$p['Attachments'][] = $x;
		}
		$res = self::post(
			'https://api.postmarkapp.com/email',
			array(
				'X-Postmark-Server-Token' => $key,
				'Content-Type'            => 'application/json',
				'Accept'                  => 'application/json',
			),
			(string) wp_json_encode( $p )
		);
		$err = self::result( $res, 'Message' );
		if ( '' === $err ) {
			$j = json_decode( $res[1], true );
			if ( is_array( $j ) && isset( $j['ErrorCode'] ) && 0 !== (int) $j['ErrorCode'] ) {
				return 'Postmark ' . (int) $j['ErrorCode'] . ': ' . ( isset( $j['Message'] ) ? substr( (string) $j['Message'], 0, 300 ) : '' );
			}
		}
		return $err;
	}

	/* ---------------------------------------------------------------------
	 * Amazon SES v2 (raw MIME, Signature Version 4)
	 * ------------------------------------------------------------------- */

	private static function ses( array $c, string $secret, array $m ): string {
		if ( ! in_array( $c['region'], MRL_Settings::SES_REGIONS, true ) || '' === $c['user'] ) {
			return 'Amazon SES: region or access key ID missing.';
		}
		$dest = array();
		foreach ( array( 'to' => 'ToAddresses', 'cc' => 'CcAddresses', 'bcc' => 'BccAddresses' ) as $k => $n ) {
			foreach ( (array) $m[ $k ] as $a ) {
				$dest[ $n ][] = (string) $a[0];
			}
		}
		$payload = (string) wp_json_encode(
			array(
				'FromEmailAddress' => $m['from'],
				'Destination'      => $dest,
				'Content'          => array(
					'Raw' => array( 'Data' => base64_encode( $m['raw'] ) ),
				),
			)
		);
		$host    = 'email.' . $c['region'] . '.amazonaws.com';
		$path    = '/v2/email/outbound-emails';
		$headers = self::sigv4( 'POST', $host, $path, $payload, $c['region'], 'ses', $c['user'], $secret, time() );
		return self::result( self::post( 'https://' . $host . $path, $headers, $payload ) );
	}

	/** AWS Signature Version 4 headers for a JSON POST (public for the tests). */
	public static function sigv4( string $method, string $host, string $path, string $payload, string $region, string $service, string $key_id, string $secret, int $now ): array {
		$amz   = gmdate( 'Ymd\THis\Z', $now );
		$date  = gmdate( 'Ymd', $now );
		$ctype = 'application/json';
		$hash  = hash( 'sha256', $payload );
		$canon = $method . "\n" . $path . "\n\n"
			. 'content-type:' . $ctype . "\n"
			. 'host:' . $host . "\n"
			. 'x-amz-date:' . $amz . "\n\n"
			. "content-type;host;x-amz-date\n"
			. $hash;
		$scope = $date . '/' . $region . '/' . $service . '/aws4_request';
		$sts   = "AWS4-HMAC-SHA256\n" . $amz . "\n" . $scope . "\n" . hash( 'sha256', $canon );
		$k     = hash_hmac( 'sha256', $date, 'AWS4' . $secret, true );
		$k     = hash_hmac( 'sha256', $region, $k, true );
		$k     = hash_hmac( 'sha256', $service, $k, true );
		$k     = hash_hmac( 'sha256', 'aws4_request', $k, true );
		$sig   = hash_hmac( 'sha256', $sts, $k );
		return array(
			'Content-Type'  => $ctype,
			'X-Amz-Date'    => $amz,
			'Authorization' => 'AWS4-HMAC-SHA256 Credential=' . $key_id . '/' . $scope . ', SignedHeaders=content-type;host;x-amz-date, Signature=' . $sig,
		);
	}
}

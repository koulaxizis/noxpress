<?php
/**
 * BRC_Src_Newsletter — module "newsletter": a campaign that was sent.
 *
 * MailerLite: a webhook for the event campaign.sent to
 * /wp-json/broadcast/v1/hook/newsletter/mailerlite, signed with the
 * webhook's secret (header Signature = HMAC-SHA256 of the raw body). The
 * payload has the campaign id, name, total_recipients, preview_url and
 * date. Batched deliveries ({ events: [...] }) are read too.
 *
 * Link and title (1.3.0):
 *  - With "use the campaign's link" (nl_preview) on, the post links to the
 *    campaign's public preview, https://preview.mailerlite.io/preview/
 *    <account>/emails/<email>, and the fixed link (nl_link) is only the
 *    fallback. Before 1.3.0 a fixed link always won, so every campaign
 *    linked to the same page. A connect./dashboard.mailerlite.com preview
 *    link (sign-in needed) is rewritten to the public host; any other link
 *    is not used.
 *  - With an optional MailerLite API token (sealed like the other
 *    secrets, or BRC_MAILERLITE_API_TOKEN), the campaign is read from the
 *    API (GET /api/campaigns/<id>, 6 s, no redirects) for the email's
 *    subject and its preview link. Without a token, or when the call
 *    fails, the payload's own fields are used. The title is the subject
 *    when known, otherwise the campaign's name.
 *
 * Picture (1.4.0): neither MailerLite nor orOS sends an image, and Viber
 * shows no preview for a link inside a text message, so a newsletter post
 * went out as bare text. Now, with "picture from the email" (nl_img_email)
 * on and the API token set, the first real picture of the email's HTML
 * (https, .jpg/.jpeg/.png/.gif, not a 1×1 tracker or a small icon) is the
 * post's picture; otherwise the fixed newsletter picture of the settings
 * (nl_image, an attachment of the media library); otherwise text as before.
 * orOS issues get the fixed picture only.
 *
 * orOS Newsletter (1.2.0): the list's "When an issue goes out, tell"
 * address is /wp-json/broadcast/v1/hook/newsletter/oros and its notice key
 * (32 letters and digits, made in orOS) is the secret here. orOS posts
 * through its relay a JSON body { event: "issue.sent", list, subject, sent,
 * at, issue, lang } with X-Oros-Timestamp (Unix seconds) and
 * X-Oros-Signature: sha256=HMAC-SHA256(key, timestamp "." raw body). A
 * timestamp more than 5 minutes off is refused like a bad signature (orOS
 * signs each retry afresh). The subject becomes the post's title; the
 * link is the fixed one from the settings (orOS has no public web
 * version). Queue key "oros:<issue id>", so a retry never posts twice.
 */

defined( 'ABSPATH' ) || exit;

final class BRC_Src_Newsletter {

	public static function init(): void {
		// Everything happens in the REST route (BRC_Rest).
	}

	/** The URL to give MailerLite. */
	public static function hook_url( string $provider = 'mailerlite' ): string {
		return rest_url( 'broadcast/v1/hook/newsletter/' . $provider );
	}

	/**
	 * A MailerLite delivery.
	 *
	 * @return string 'ok' | 'bad_signature' | 'bad_json'
	 */
	public static function mailerlite( string $body, string $signature ): string {
		$secret = BRC_Crypt::get( 'mailerlite' );
		if ( '' === $secret || '' === $signature || ! hash_equals( hash_hmac( 'sha256', $body, $secret ), strtolower( trim( $signature ) ) ) ) {
			return 'bad_signature';
		}
		BRC_Queue::note( 'nl_hook', time() );

		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			return 'bad_json';
		}
		$events = isset( $json['events'] ) && is_array( $json['events'] ) ? $json['events'] : array( $json );
		foreach ( array_slice( $events, 0, 50 ) as $e ) {
			if ( ! is_array( $e ) ) {
				continue;
			}
			$type = isset( $e['event'] ) ? $e['event'] : ( isset( $e['type'] ) ? $e['type'] : '' );
			if ( 'campaign.sent' !== $type ) {
				continue;
			}
			$c = isset( $e['data'] ) && is_array( $e['data'] ) ? array_merge( $e, $e['data'] ) : $e;
			self::campaign( $c );
		}
		return 'ok';
	}

	/** Seconds an orOS notice's timestamp may be off. */
	const OROS_SKEW = 300;

	/**
	 * An orOS Newsletter notice.
	 *
	 * @return string 'ok' | 'bad_signature' | 'bad_json'
	 */
	public static function oros( string $body, string $ts, string $signature, int $now = 0 ): string {
		$secret = BRC_Crypt::get( 'oros' );
		$now    = $now > 0 ? $now : time();
		$ts     = trim( $ts );
		$sig    = strtolower( trim( $signature ) );
		if ( 0 === strpos( $sig, 'sha256=' ) ) {
			$sig = substr( $sig, 7 );
		}
		if ( '' === $secret || ! preg_match( '/^\d{9,11}$/', $ts ) || abs( $now - (int) $ts ) > self::OROS_SKEW
			|| ! preg_match( '/^[0-9a-f]{64}$/', $sig ) || ! hash_equals( hash_hmac( 'sha256', $ts . '.' . $body, $secret ), $sig ) ) {
			return 'bad_signature';
		}
		BRC_Queue::note( 'nl_hook_oros', $now );

		$e = json_decode( $body, true );
		if ( ! is_array( $e ) ) {
			return 'bad_json';
		}
		if ( ! isset( $e['event'] ) || 'issue.sent' !== $e['event'] ) {
			return 'ok';
		}
		$id = isset( $e['issue'] ) && is_scalar( $e['issue'] ) ? substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $e['issue'] ), 0, 40 ) : '';
		if ( '' === $id ) {
			return 'ok';
		}
		$s       = BRC_Settings::get();
		$subject = isset( $e['subject'] ) && is_string( $e['subject'] ) ? sanitize_text_field( mb_substr( $e['subject'], 0, 300 ) ) : '';
		$list    = isset( $e['list'] ) && is_string( $e['list'] ) ? sanitize_text_field( mb_substr( $e['list'], 0, 120 ) ) : '';
		$date    = '';
		if ( isset( $e['at'] ) && is_string( $e['at'] ) ) {
			$t    = strtotime( $e['at'] );
			$date = $t ? wp_date( get_option( 'date_format' ), $t ) : '';
		}
		$image = self::default_image();
		$text  = BRC_Template::render(
			$s['tpl_newsletter'],
			array(
				'title'      => $subject,
				'url'        => $s['nl_link'],
				'recipients' => isset( $e['sent'] ) && is_int( $e['sent'] ) && $e['sent'] >= 0 ? (string) $e['sent'] : '',
				'date'       => $date,
				'list'       => $list,
			)
		);
		BRC_Queue::add(
			'newsletter',
			'oros:' . $id,
			array(
				'title' => '' !== $subject ? $subject : ( '' !== $list ? $list : 'Newsletter' ),
				'text'  => $text,
				'url'   => $s['nl_link'],
				'image' => $image,
				'kind'  => '' !== $image ? 'picture' : 'text',
			),
			array( 'same_url' => false )
		);
		return 'ok';
	}

	/** MailerLite API base (no trailing slash). */
	const ML_API = 'https://connect.mailerlite.com/api';

	private static function campaign( array $c ): void {
		$id = isset( $c['id'] ) && is_scalar( $c['id'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $c['id'] ) : '';
		if ( '' === $id ) {
			return;
		}
		$s    = BRC_Settings::get();
		$name = isset( $c['name'] ) && is_scalar( $c['name'] ) ? sanitize_text_field( (string) $c['name'] ) : '';

		$info = self::details( $c );
		$api  = self::from_api( $id );
		foreach ( array( 'subject', 'link', 'image' ) as $k ) {
			if ( '' !== $api[ $k ] ) {
				$info[ $k ] = $api[ $k ];
			}
		}
		$url   = $s['nl_preview'] && '' !== $info['link'] ? $info['link'] : $s['nl_link'];
		$title = '' !== $info['subject'] ? $info['subject'] : $name;
		$image = $s['nl_img_email'] ? $info['image'] : '';
		if ( '' === $image ) {
			$image = self::default_image();
		}

		$date = '';
		if ( isset( $c['date'] ) && is_string( $c['date'] ) ) {
			$t    = strtotime( $c['date'] );
			$date = $t ? wp_date( get_option( 'date_format' ), $t ) : '';
		}
		$text = BRC_Template::render(
			$s['tpl_newsletter'],
			array(
				'title'      => $title,
				'url'        => $url,
				'recipients' => isset( $c['total_recipients'] ) ? (string) (int) $c['total_recipients'] : '',
				'date'       => $date,
			)
		);
		BRC_Queue::add(
			'newsletter',
			'ml:' . $id,
			array(
				'title' => '' !== $title ? $title : 'Newsletter',
				'text'  => $text,
				'url'   => $url,
				'image' => $image,
				'kind'  => '' !== $image ? 'picture' : 'text',
			),
			array( 'same_url' => false )
		);
	}

	/**
	 * Subject, public link and first picture from a campaign record
	 * (webhook payload or API answer): top level first, then the first of
	 * its emails.
	 *
	 * @return array{subject:string,link:string,image:string}
	 */
	public static function details( array $c ): array {
		$out    = array(
			'subject' => '',
			'link'    => '',
			'image'   => '',
		);
		$places = array( $c );
		if ( isset( $c['emails'] ) && is_array( $c['emails'] ) ) {
			$first = reset( $c['emails'] );
			if ( is_array( $first ) ) {
				$places[] = $first;
			}
		}
		foreach ( $places as $p ) {
			if ( '' === $out['subject'] && isset( $p['subject'] ) && is_string( $p['subject'] ) ) {
				$out['subject'] = sanitize_text_field( mb_substr( $p['subject'], 0, 300 ) );
			}
			if ( '' === $out['link'] && isset( $p['preview_url'] ) ) {
				$out['link'] = self::public_link( $p['preview_url'] );
			}
			if ( '' === $out['image'] && isset( $p['content'] ) && is_string( $p['content'] ) ) {
				$out['image'] = self::first_image( $p['content'] );
			}
		}
		return $out;
	}

	/** Smallest width or height (attribute) a picture may declare. */
	const IMG_MIN = 120;

	/**
	 * The first real picture of an email's HTML, or ''.
	 * Skips non-https sources, other file types (Viber takes JPEG, PNG and
	 * GIF), merge tags and pictures that declare a width or height under
	 * IMG_MIN (trackers, icons, spacers).
	 */
	public static function first_image( string $html ): string {
		if ( ! preg_match_all( '/<img\b[^>]*>/i', substr( $html, 0, 500000 ), $tags ) ) {
			return '';
		}
		foreach ( array_slice( $tags[0], 0, 50 ) as $tag ) {
			if ( ! preg_match( '/\ssrc\s*=\s*(["\'])(.*?)\1/i', $tag, $m ) ) {
				continue;
			}
			$url = BRC_Settings::clean_url( html_entity_decode( $m[2], ENT_QUOTES ) );
			if ( '' === $url || false !== strpos( $url, '{' ) ) {
				continue;
			}
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( ! preg_match( '/\.(jpe?g|png|gif)$/i', $path ) ) {
				continue;
			}
			foreach ( array( 'width', 'height' ) as $dim ) {
				if ( preg_match( '/\s' . $dim . '\s*=\s*["\']?(\d+)/i', $tag, $d ) && (int) $d[1] < self::IMG_MIN ) {
					continue 2;
				}
			}
			return $url;
		}
		return '';
	}

	/** The fixed newsletter picture (large size), or ''. */
	public static function default_image(): string {
		$s  = BRC_Settings::get();
		$id = (int) $s['nl_image'];
		if ( $id <= 0 || ! wp_attachment_is_image( $id ) ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $id, 'large' );
		return is_string( $url ) ? BRC_Settings::clean_url( $url ) : '';
	}

	/**
	 * The public preview link of a MailerLite email, or ''.
	 * preview.mailerlite.io is kept; a connect./dashboard.mailerlite.com
	 * preview (sign-in needed) is rewritten to it; anything else is ''.
	 */
	public static function public_link( $v ): string {
		$url = BRC_Settings::clean_url( $v );
		if ( '' === $url ) {
			return '';
		}
		$p    = wp_parse_url( $url );
		$host = isset( $p['host'] ) ? strtolower( $p['host'] ) : '';
		$path = isset( $p['path'] ) ? $p['path'] : '';
		if ( ! preg_match( '#^/preview/(\d+)/emails/(\d+)/?$#', $path, $m ) ) {
			return '';
		}
		if ( ! in_array( $host, array( 'preview.mailerlite.io', 'connect.mailerlite.com', 'dashboard.mailerlite.com' ), true ) ) {
			return '';
		}
		return 'https://preview.mailerlite.io/preview/' . $m[1] . '/emails/' . $m[2];
	}

	/**
	 * Subject, link and picture from the MailerLite API, when a token is set.
	 *
	 * @return array{subject:string,link:string,image:string}
	 */
	private static function from_api( string $id ): array {
		$none  = array(
			'subject' => '',
			'link'    => '',
			'image'   => '',
		);
		$token = BRC_Crypt::get( 'mailerlite_api' );
		if ( '' === $token ) {
			return $none;
		}
		$res = wp_safe_remote_get(
			self::ML_API . '/campaigns/' . rawurlencode( $id ),
			array(
				'timeout'     => 6,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$json = 200 === $code ? json_decode( (string) wp_remote_retrieve_body( $res ), true ) : null;
		$ok   = is_array( $json ) && isset( $json['data'] ) && is_array( $json['data'] );
		// Last call for the settings page: when and the HTTP code (0 = no answer).
		BRC_Queue::note(
			'nl_api',
			array(
				'at'   => time(),
				'code' => $code,
				'ok'   => $ok,
			)
		);
		return $ok ? self::details( $json['data'] ) : $none;
	}
}

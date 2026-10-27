<?php
/**
 * BRC_Src_Newsletter — module "newsletter": a campaign that was sent.
 *
 * MailerLite: a webhook for the event campaign.sent to
 * /wp-json/broadcast/v1/hook/newsletter/mailerlite, signed with the
 * webhook's secret (header Signature = HMAC-SHA256 of the raw body). The
 * payload has the campaign id, name, total_recipients, preview_url (a
 * MailerLite dashboard link) and date, but no subject and no public
 * web-version link, so the link of the post is a fixed one from the
 * settings (e.g. the newsletter archive), or the preview_url when the
 * owner ticks it. Batched deliveries ({ events: [...] }) are read too.
 *
 * orOS Newsletter will use the same route with its own provider name once
 * it can send "campaign sent"; the queue and the destination stay the same.
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

	private static function campaign( array $c ): void {
		$id = isset( $c['id'] ) && is_scalar( $c['id'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $c['id'] ) : '';
		if ( '' === $id ) {
			return;
		}
		$s    = BRC_Settings::get();
		$name = isset( $c['name'] ) && is_scalar( $c['name'] ) ? sanitize_text_field( (string) $c['name'] ) : '';
		$url  = $s['nl_link'];
		if ( '' === $url && $s['nl_preview'] && isset( $c['preview_url'] ) ) {
			$url = BRC_Settings::clean_url( $c['preview_url'] );
		}
		$date = '';
		if ( isset( $c['date'] ) && is_string( $c['date'] ) ) {
			$t    = strtotime( $c['date'] );
			$date = $t ? wp_date( get_option( 'date_format' ), $t ) : '';
		}
		$text = BRC_Template::render(
			$s['tpl_newsletter'],
			array(
				'title'      => $name,
				'url'        => $url,
				'recipients' => isset( $c['total_recipients'] ) ? (string) (int) $c['total_recipients'] : '',
				'date'       => $date,
			)
		);
		BRC_Queue::add(
			'newsletter',
			'ml:' . $id,
			array(
				'title' => '' !== $name ? $name : 'Newsletter',
				'text'  => $text,
				'url'   => $url,
				'kind'  => 'text',
			),
			array( 'same_url' => false )
		);
	}
}

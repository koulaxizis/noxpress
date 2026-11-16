<?php
/**
 * MRL_Import — non-secret settings from FluentSMTP (option
 * fluentmail-settings, only read). Host, port, encryption, user name,
 * provider, Mailgun domain / region, SES region and access key ID, sender.
 * Passwords and API keys are never taken: they are typed again (or set as
 * a constant), so no secret moves from one plugin's storage to another.
 *
 * The shape of FluentSMTP's option is read defensively: unknown or missing
 * keys are skipped, and the admin sees a preview before anything changes.
 */

defined( 'ABSPATH' ) || exit;

final class MRL_Import {

	const MAP = array(
		'smtp'       => 'smtp',
		'default'    => 'smtp',
		'sendinblue' => 'brevo',
		'brevo'      => 'brevo',
		'mailgun'    => 'mailgun',
		'sendgrid'   => 'sendgrid',
		'postmark'   => 'postmark',
		'ses'        => 'ses',
	);

	/** Connections found: list of [ conn, from_email, from_name, label ]. */
	public static function fluent(): array {
		$opt = get_option( 'fluentmail-settings', array() );
		if ( ! is_array( $opt ) || empty( $opt['connections'] ) || ! is_array( $opt['connections'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $opt['connections'] as $conn ) {
			if ( ! is_array( $conn ) || empty( $conn['provider_settings'] ) || ! is_array( $conn['provider_settings'] ) ) {
				continue;
			}
			$p    = $conn['provider_settings'];
			$prov = isset( $p['provider'] ) ? sanitize_key( (string) $p['provider'] ) : '';
			if ( ! isset( self::MAP[ $prov ] ) ) {
				continue;
			}
			$type = self::MAP[ $prov ];
			$c    = array(
				'type'   => $type,
				'preset' => 'custom',
				'host'   => isset( $p['host'] ) ? (string) $p['host'] : '',
				'port'   => isset( $p['port'] ) ? (int) $p['port'] : 587,
				'enc'    => isset( $p['encryption'] ) && in_array( $p['encryption'], array( 'ssl', 'tls', 'none' ), true ) ? $p['encryption'] : 'tls',
				'user'   => isset( $p['username'] ) ? (string) $p['username'] : '',
				'region' => '',
				'domain' => '',
			);
			if ( 'smtp' === $type && 'smtp.protonmail.ch' === strtolower( trim( $c['host'] ) ) ) {
				$c['preset'] = 'proton';
			}
			if ( 'mailgun' === $type ) {
				$c['domain'] = isset( $p['domain_name'] ) ? (string) $p['domain_name'] : '';
				$c['region'] = isset( $p['region'] ) && 'eu' === $p['region'] ? 'eu' : 'us';
			}
			if ( 'ses' === $type ) {
				$c['user']   = isset( $p['access_key'] ) && is_string( $p['access_key'] ) && 'wp_config' !== ( isset( $p['key_store'] ) ? $p['key_store'] : '' ) ? $p['access_key'] : '';
				$c['region'] = isset( $p['region'] ) ? (string) $p['region'] : '';
			}
			$out[] = array(
				MRL_Settings::sanitize_conn( $c ),
				isset( $p['sender_email'] ) && is_email( $p['sender_email'] ) ? strtolower( (string) $p['sender_email'] ) : '',
				isset( $p['sender_name'] ) ? sanitize_text_field( (string) $p['sender_name'] ) : '',
				isset( $conn['title'] ) ? sanitize_text_field( (string) $conn['title'] ) : $type,
			);
		}
		return $out;
	}

	/** Takes the first connection as primary and a second one as backup. */
	public static function apply(): int {
		$found = self::fluent();
		if ( ! $found ) {
			return 0;
		}
		$s            = MRL_Settings::get();
		$s['primary'] = $found[0][0];
		if ( '' !== $found[0][1] ) {
			$s['from_email'] = $found[0][1];
		}
		if ( '' !== $found[0][2] ) {
			$s['from_name'] = $found[0][2];
		}
		if ( isset( $found[1] ) ) {
			$s['backup']    = $found[1][0];
			$s['backup_on'] = false; // Turned on by the admin once its secret is set and tested.
		}
		$s['mode'] = 'off';
		MRL_Settings::save( $s );
		return min( 2, count( $found ) );
	}
}

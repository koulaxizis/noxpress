<?php
/**
 * MRL_Dns — read-only check of the From domain: SPF, DKIM, DMARC.
 *
 * Nothing is changed anywhere: the page says what is missing and what the
 * provider usually asks for. DKIM selectors are guessed from the provider
 * (Proton Mail: protonmail, protonmail2, protonmail3; Amazon SES and the
 * others publish their own selectors per account, so only common ones are
 * tried). Result cached for 10 minutes.
 */

defined( 'ABSPATH' ) || exit;

final class MRL_Dns {

	/** Expected SPF include per provider / preset. */
	const SPF = array(
		'proton'   => 'include:_spf.protonmail.ch',
		'gmail'    => 'include:_spf.google.com',
		'outlook'  => 'include:spf.protection.outlook.com',
		'brevo'    => 'include:spf.brevo.com',
		'mailgun'  => 'include:mailgun.org',
		'sendgrid' => 'include:sendgrid.net',
		'postmark' => 'include:spf.mtasv.net',
		'ses'      => 'include:amazonses.com',
	);

	const SELECTORS = array(
		'proton'  => array( 'protonmail', 'protonmail2', 'protonmail3' ),
		'gmail'   => array( 'google' ),
		'outlook' => array( 'selector1', 'selector2' ),
		'brevo'   => array( 'brevo1', 'brevo2', 'mail' ),
		'mailgun' => array( 'mx', 'k1', 'smtp', 'mailo' ),
		'sendgrid' => array( 's1', 's2' ),
		'postmark' => array( 'pm', '20230601pm' ),
		'ses'     => array(),
		'*'       => array( 'default', 'mail', 'dkim', 'k1', 's1', 'selector1' ),
	);

	/** Provider key of the primary connection for the tables above. */
	public static function provider(): string {
		$c = MRL_Settings::conn( 'primary' );
		if ( 'smtp' === $c['type'] ) {
			return in_array( $c['preset'], array( 'proton', 'gmail', 'outlook' ), true ) ? $c['preset'] : '';
		}
		return $c['type'];
	}

	private static function txt( string $name ): array {
		if ( ! function_exists( 'dns_get_record' ) ) {
			return array();
		}
		$r   = @dns_get_record( $name, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup is an answer.
		$out = array();
		foreach ( is_array( $r ) ? $r : array() as $x ) {
			if ( isset( $x['txt'] ) ) {
				$out[] = (string) $x['txt'];
			} elseif ( isset( $x['entries'] ) && is_array( $x['entries'] ) ) {
				$out[] = implode( '', $x['entries'] );
			}
		}
		return $out;
	}

	private static function cname( string $name ): string {
		if ( ! function_exists( 'dns_get_record' ) ) {
			return '';
		}
		$r = @dns_get_record( $name, DNS_CNAME ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return is_array( $r ) && isset( $r[0]['target'] ) ? (string) $r[0]['target'] : '';
	}

	/**
	 * @return array{domain:string, spf:array, dkim:array, dmarc:array}
	 *   each part: ok (bool|null = unknown), value, hint.
	 */
	public static function check( bool $fresh = false ): array {
		$from   = MRL_Settings::from_email();
		$domain = '' === $from ? '' : strtolower( substr( $from, strrpos( $from, '@' ) + 1 ) );
		$key    = 'mrl_dns_' . md5( $domain . '|' . self::provider() );
		if ( ! $fresh ) {
			$c = get_transient( $key );
			if ( is_array( $c ) ) {
				return $c;
			}
		}
		$out = array(
			'domain' => $domain,
			'spf'    => array( 'ok' => null, 'value' => '', 'hint' => '' ),
			'dkim'   => array( 'ok' => null, 'value' => '', 'hint' => '' ),
			'dmarc'  => array( 'ok' => null, 'value' => '', 'hint' => '' ),
		);
		if ( '' === $domain || '' === MRL_Settings::hostname( $domain ) ) {
			return $out;
		}
		$prov = self::provider();

		$spf = '';
		foreach ( self::txt( $domain ) as $t ) {
			if ( 0 === stripos( trim( $t ), 'v=spf1' ) ) {
				$spf = trim( $t );
				break;
			}
		}
		$out['spf']['value'] = $spf;
		if ( '' === $spf ) {
			$out['spf']['ok']   = false;
			$out['spf']['hint'] = isset( self::SPF[ $prov ] ) ? 'v=spf1 ' . self::SPF[ $prov ] . ' ~all' : 'v=spf1 … ~all';
		} elseif ( isset( self::SPF[ $prov ] ) && false === stripos( $spf, self::SPF[ $prov ] ) ) {
			$out['spf']['ok']   = false;
			$out['spf']['hint'] = self::SPF[ $prov ];
		} else {
			$out['spf']['ok'] = true;
		}

		$sel = array_merge( isset( self::SELECTORS[ $prov ] ) ? self::SELECTORS[ $prov ] : array(), self::SELECTORS['*'] );
		foreach ( array_unique( $sel ) as $s ) {
			$name = $s . '._domainkey.' . $domain;
			$hit  = '';
			foreach ( self::txt( $name ) as $t ) {
				if ( false !== stripos( $t, 'p=' ) ) {
					$hit = $s;
					break;
				}
			}
			if ( '' === $hit && '' !== self::cname( $name ) ) {
				$hit = $s;
			}
			if ( '' !== $hit ) {
				$out['dkim']['ok']    = true;
				$out['dkim']['value'] = $hit . '._domainkey';
				break;
			}
		}
		if ( true !== $out['dkim']['ok'] ) {
			// Unknown selector is not proof of a missing key (SES, custom setups).
			$out['dkim']['ok']   = 'ses' === $prov ? null : false;
			$out['dkim']['hint'] = 'proton' === $prov ? 'protonmail._domainkey, protonmail2._domainkey, protonmail3._domainkey (CNAME)' : '';
		}

		$dm = '';
		foreach ( self::txt( '_dmarc.' . $domain ) as $t ) {
			if ( 0 === stripos( trim( $t ), 'v=DMARC1' ) ) {
				$dm = trim( $t );
				break;
			}
		}
		$out['dmarc']['value'] = $dm;
		$out['dmarc']['ok']    = '' !== $dm;
		if ( '' === $dm ) {
			$out['dmarc']['hint'] = 'v=DMARC1; p=quarantine';
		}
		set_transient( $key, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}
}

<?php
/**
 * MRL_Settings — the mrl_settings option, presets, validation.
 *
 * Shape (every key always present after get()):
 *  mode        'off' | 'on'. «off» = WordPress sends as without the plugin.
 *              «on» needs a successful test of the primary connection
 *              and no other plugin that replaces wp_mail().
 *  from_email  From address ('' = the site's admin email).
 *  from_name   From name ('' = the site title).
 *  force_from  Every mail leaves with this From (Proton Mail and the APIs
 *              refuse other senders); the original From becomes Reply-To.
 *  primary     connection (see conn()).
 *  backup      connection, used only when backup_on.
 *  backup_on   Try the backup connection when the primary fails.
 *  bodies      Keep the content of non-sensitive mails for 7 days
 *              (sealed), for viewing and resending. Off by default.
 *  days        Log retention in days, 1–90 (default 30).
 *  alerts      Hourly summary email to the admin when mails fail.
 *  alert_to    Address of the summary ('' = admin email).
 *  keep        Uninstall keeps the settings and the log.
 *
 * Connection: type (smtp | brevo | mailgun | sendgrid | postmark | ses),
 * preset (smtp only: proton | gmail | outlook | siteground | custom),
 * host, port, enc (ssl | tls | none), user, region, domain. Secrets are
 * never here: MRL_Crypt.
 */

defined( 'ABSPATH' ) || exit;

final class MRL_Settings {

	const OPT   = 'mrl_settings';
	const STATE = 'mrl_state';

	const TYPES = array( 'smtp', 'brevo', 'mailgun', 'sendgrid', 'postmark', 'ses' );

	/** SMTP presets: host (with {domain} = the site's domain), port, enc. */
	const PRESETS = array(
		'proton'     => array( 'smtp.protonmail.ch', 587, 'tls' ),
		'gmail'      => array( 'smtp.gmail.com', 587, 'tls' ),
		'outlook'    => array( 'smtp.office365.com', 587, 'tls' ),
		'siteground' => array( 'mail.{domain}', 465, 'ssl' ),
	);

	const SES_REGIONS = array(
		'us-east-1', 'us-east-2', 'us-west-1', 'us-west-2', 'ca-central-1', 'sa-east-1',
		'eu-west-1', 'eu-west-2', 'eu-west-3', 'eu-central-1', 'eu-central-2', 'eu-north-1', 'eu-south-1',
		'ap-south-1', 'ap-northeast-1', 'ap-northeast-2', 'ap-northeast-3', 'ap-southeast-1', 'ap-southeast-2',
		'ap-southeast-3', 'me-south-1', 'me-central-1', 'af-south-1', 'il-central-1', 'us-gov-west-1',
	);

	/** Per-request cache. */
	private static $cache = null;

	public static function blank_conn(): array {
		return array(
			'type'   => 'smtp',
			'preset' => 'proton',
			'host'   => 'smtp.protonmail.ch',
			'port'   => 587,
			'enc'    => 'tls',
			'user'   => '',
			'region' => '',
			'domain' => '',
		);
	}

	public static function defaults(): array {
		return array(
			'mode'       => 'off',
			'from_email' => '',
			'from_name'  => '',
			'force_from' => true,
			'primary'    => self::blank_conn(),
			'backup'     => self::blank_conn(),
			'backup_on'  => false,
			'bodies'     => false,
			'days'       => 30,
			'alerts'     => true,
			'alert_to'   => '',
			'keep'       => false,
		);
	}

	public static function get(): array {
		if ( null === self::$cache ) {
			$raw         = get_option( self::OPT, array() );
			self::$cache = self::sanitize( is_array( $raw ) ? $raw : array() );
		}
		return self::$cache;
	}

	public static function save( array $s ): array {
		$s = self::sanitize( $s );
		update_option( self::OPT, $s, false );
		self::$cache = $s;
		foreach ( array( 'primary', 'backup' ) as $slot ) {
			MRL_Crypt::forget_other( $slot, self::bind( $s[ $slot ] ) );
		}
		return $s;
	}

	public static function conn( string $slot ): array {
		$s = self::get();
		return isset( $s[ $slot ] ) && is_array( $s[ $slot ] ) ? $s[ $slot ] : self::blank_conn();
	}

	/** What a secret belongs to: provider, host, port, user, region, domain. */
	public static function bind( array $c ): string {
		return substr( hash( 'sha256', implode( '|', array( $c['type'], $c['host'], (string) $c['port'], strtolower( $c['user'] ), $c['region'], $c['domain'] ) ) ), 0, 32 );
	}

	/** The site's domain without www. */
	public static function site_domain(): string {
		$h = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return (string) preg_replace( '/^www\./', '', strtolower( $h ) );
	}

	public static function from_email(): string {
		$s = self::get();
		$e = '' !== $s['from_email'] ? $s['from_email'] : (string) get_option( 'admin_email' );
		return is_email( $e ) ? $e : '';
	}

	public static function from_name(): string {
		$s = self::get();
		return '' !== $s['from_name'] ? $s['from_name'] : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/** A hostname, lower case, or '' when it is not one. */
	public static function hostname( string $h ): string {
		$h = strtolower( trim( $h ) );
		$h = rtrim( $h, '.' );
		if ( '' === $h || strlen( $h ) > 253 ) {
			return '';
		}
		if ( filter_var( $h, FILTER_VALIDATE_IP ) ) {
			return $h;
		}
		return preg_match( '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?)*$/', $h ) ? $h : '';
	}

	public static function sanitize_conn( $c ): array {
		$d = self::blank_conn();
		$c = is_array( $c ) ? $c : array();
		$o = array();

		$o['type'] = isset( $c['type'] ) && in_array( $c['type'], self::TYPES, true ) ? $c['type'] : $d['type'];

		$preset      = isset( $c['preset'] ) ? sanitize_key( (string) $c['preset'] ) : 'custom';
		$o['preset'] = ( 'custom' === $preset || isset( self::PRESETS[ $preset ] ) ) ? $preset : 'custom';

		$o['host'] = isset( $c['host'] ) ? self::hostname( (string) $c['host'] ) : '';
		$port      = isset( $c['port'] ) ? (int) $c['port'] : 587;
		$o['port'] = ( $port >= 1 && $port <= 65535 ) ? $port : 587;
		$o['enc']  = isset( $c['enc'] ) && in_array( $c['enc'], array( 'ssl', 'tls', 'none' ), true ) ? $c['enc'] : 'tls';

		$user      = isset( $c['user'] ) ? trim( sanitize_text_field( (string) $c['user'] ) ) : '';
		$o['user'] = substr( $user, 0, 254 );

		$region      = isset( $c['region'] ) ? sanitize_key( (string) $c['region'] ) : '';
		$o['region'] = '';
		$o['domain'] = '';

		if ( 'smtp' === $o['type'] ) {
			if ( isset( self::PRESETS[ $o['preset'] ] ) ) {
				list( $h, $p, $e ) = self::PRESETS[ $o['preset'] ];
				$o['host']         = self::hostname( str_replace( '{domain}', self::site_domain(), $h ) );
				$o['port']         = $p;
				$o['enc']          = $e;
			}
		} else {
			$o['preset'] = 'custom';
			$o['host']   = '';
			$o['port']   = 443;
			$o['enc']    = 'ssl';
		}
		if ( 'mailgun' === $o['type'] ) {
			$o['region'] = 'eu' === $region ? 'eu' : 'us';
			$o['domain'] = isset( $c['domain'] ) ? self::hostname( (string) $c['domain'] ) : '';
			$o['user']   = '';
		} elseif ( 'ses' === $o['type'] ) {
			$o['region'] = in_array( $region, self::SES_REGIONS, true ) ? $region : 'eu-central-1';
			$o['user']   = preg_match( '/^[A-Z0-9]{16,128}$/', $o['user'] ) ? $o['user'] : '';
		} elseif ( 'smtp' !== $o['type'] ) {
			$o['user'] = '';
		}
		return $o;
	}

	public static function sanitize( array $in ): array {
		$d   = self::defaults();
		$out = array();

		$out['mode'] = isset( $in['mode'] ) && 'on' === $in['mode'] ? 'on' : 'off';

		$fe                = isset( $in['from_email'] ) ? sanitize_email( (string) $in['from_email'] ) : '';
		$out['from_email'] = is_email( $fe ) ? strtolower( $fe ) : '';
		$fn                = isset( $in['from_name'] ) ? sanitize_text_field( (string) $in['from_name'] ) : '';
		$out['from_name']  = mb_substr( (string) preg_replace( '/[\r\n<>"]/', '', $fn ), 0, 120 );

		$out['force_from'] = isset( $in['force_from'] ) ? (bool) $in['force_from'] : $d['force_from'];
		$out['primary']    = self::sanitize_conn( isset( $in['primary'] ) ? $in['primary'] : array() );
		$out['backup']     = self::sanitize_conn( isset( $in['backup'] ) ? $in['backup'] : array() );
		$out['backup_on']  = ! empty( $in['backup_on'] );
		$out['bodies']     = ! empty( $in['bodies'] );
		$days              = isset( $in['days'] ) ? (int) $in['days'] : $d['days'];
		$out['days']       = max( 1, min( 90, $days ) );
		$out['alerts']     = isset( $in['alerts'] ) ? (bool) $in['alerts'] : $d['alerts'];
		$at                = isset( $in['alert_to'] ) ? sanitize_email( (string) $in['alert_to'] ) : '';
		$out['alert_to']   = is_email( $at ) ? strtolower( $at ) : '';
		$out['keep']       = ! empty( $in['keep'] );
		return $out;
	}

	/**
	 * Problems that stop a connection from sending, as translated texts
	 * (empty = ready). Checks the secret and, for SMTP, TLS and the host.
	 */
	public static function problems( string $slot, bool $resolve = true ): array {
		$c    = self::conn( $slot );
		$bind = self::bind( $c );
		$p    = array();
		$st   = MRL_Crypt::state( $slot, $bind );
		$has  = '' !== MRL_Crypt::get( $slot, $bind );

		if ( 'unreadable' === $st ) {
			$p[] = __( 'Ο αποθηκευμένος κωδικός δεν ανοίγει πια (άλλαξαν τα salts του wp-config.php). Γράψ\' τον ξανά.', 'mail-relay' );
		}
		if ( 'smtp' === $c['type'] ) {
			if ( '' === $c['host'] ) {
				$p[] = __( 'Λείπει ο SMTP server.', 'mail-relay' );
			} elseif ( $resolve ) {
				$h = self::host_problem( $c['host'], $has );
				if ( '' !== $h ) {
					$p[] = $h;
				}
			}
			if ( $has && 'none' === $c['enc'] ) {
				$p[] = __( 'Κωδικός χωρίς κρυπτογράφηση δεν στέλνεται ποτέ: διάλεξε SSL ή STARTTLS.', 'mail-relay' );
			}
			if ( '' !== $c['user'] && ! $has ) {
				$p[] = __( 'Λείπει ο κωδικός (ή το SMTP token).', 'mail-relay' );
			}
		} else {
			if ( ! $has ) {
				$p[] = __( 'Λείπει το κλειδί API.', 'mail-relay' );
			}
			if ( 'mailgun' === $c['type'] && '' === $c['domain'] ) {
				$p[] = __( 'Λείπει το domain του Mailgun.', 'mail-relay' );
			}
			if ( 'ses' === $c['type'] && '' === $c['user'] ) {
				$p[] = __( 'Λείπει το Access key ID του Amazon SES.', 'mail-relay' );
			}
		}
		if ( '' === self::from_email() ) {
			$p[] = __( 'Λείπει μια έγκυρη διεύθυνση αποστολέα (From).', 'mail-relay' );
		}
		return $p;
	}

	/**
	 * '' when the SMTP host may be used, else why not (Bible §11
	 * «Εξωτερικά URLs μόνο δημόσια»). A local relay (localhost, private
	 * addresses) only with MRL_ALLOW_LOCAL_SMTP and never with a password.
	 */
	public static function host_problem( string $host, bool $with_secret ): string {
		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			$v4 = gethostbynamel( $host );
			if ( is_array( $v4 ) ) {
				$ips = $v4;
			}
			if ( function_exists( 'dns_get_record' ) ) {
				$v6 = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup is an answer.
				foreach ( is_array( $v6 ) ? $v6 : array() as $r ) {
					if ( isset( $r['ipv6'] ) ) {
						$ips[] = (string) $r['ipv6'];
					}
				}
			}
		}
		if ( ! $ips ) {
			return __( 'Ο SMTP server δεν βρέθηκε (DNS).', 'mail-relay' );
		}
		foreach ( $ips as $ip ) {
			if ( ! self::public_ip( $ip ) ) {
				if ( defined( 'MRL_ALLOW_LOCAL_SMTP' ) && MRL_ALLOW_LOCAL_SMTP && ! $with_secret ) {
					continue;
				}
				return __( 'Ο SMTP server είναι τοπική ή ιδιωτική διεύθυνση. Επιτρέπεται μόνο χωρίς κωδικό και με τη σταθερά MRL_ALLOW_LOCAL_SMTP στο wp-config.php.', 'mail-relay' );
			}
		}
		return '';
	}

	public static function public_ip( string $ip ): bool {
		if ( class_exists( 'Noxpress_Safe' ) && method_exists( 'Noxpress_Safe', 'public_ip' ) ) {
			return Noxpress_Safe::public_ip( $ip );
		}
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/* ---------------------------------------------------------------------
	 * State: tested marks, site address (staging guard)
	 * ------------------------------------------------------------------- */

	public static function state(): array {
		$s = get_option( self::STATE, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'site'   => isset( $s['site'] ) && is_string( $s['site'] ) ? $s['site'] : '',
			'tested' => isset( $s['tested'] ) && is_array( $s['tested'] ) ? $s['tested'] : array(),
		);
	}

	public static function save_state( array $s ): void {
		update_option( self::STATE, $s, false );
	}

	public static function site_hash(): string {
		return substr( hash( 'sha256', strtolower( untrailingslashit( (string) get_option( 'home' ) ) ) . '|' . strtolower( untrailingslashit( (string) get_option( 'siteurl' ) ) ) ), 0, 32 );
	}

	/** Mark of a connection as it is now (settings + secret), for «tested». */
	public static function test_mark( string $slot ): string {
		$c    = self::conn( $slot );
		$bind = self::bind( $c );
		return substr( hash( 'sha256', $bind . '|' . MRL_Crypt::fingerprint( $slot, $bind ) . '|' . self::from_email() ), 0, 32 );
	}

	public static function tested( string $slot ): bool {
		$st = self::state();
		return isset( $st['tested'][ $slot ] ) && is_string( $st['tested'][ $slot ] ) && hash_equals( $st['tested'][ $slot ], self::test_mark( $slot ) );
	}

	public static function mark_tested( string $slot ): void {
		$st                    = self::state();
		$st['tested'][ $slot ] = self::test_mark( $slot );
		self::save_state( $st );
	}

	/** True when the site address is not the one sending was confirmed for. */
	public static function moved(): bool {
		$st = self::state();
		return '' !== $st['site'] && ! hash_equals( $st['site'], self::site_hash() );
	}

	public static function confirm_site(): void {
		$st         = self::state();
		$st['site'] = self::site_hash();
		self::save_state( $st );
	}

	/** Non-secret settings for the JSON backup. */
	public static function export(): array {
		$s = self::get();
		unset( $s['mode'] );
		return $s;
	}
}

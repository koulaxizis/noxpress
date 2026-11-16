<?php
/**
 * IVD_Aade — company details from the Greek tax authority (AADE) for a
 * Greek VAT number (ΑΦΜ), through its public registry service RgWsPublic2
 * (SOAP 1.2, WS-Security UsernameToken with the business's special
 * credentials, «ειδικοί κωδικοί»).
 *
 * Credentials live only in wp-config.php, never in the database or a form:
 *
 *   define( 'IVD_AADE_USER', '…' );
 *   define( 'IVD_AADE_PASS', '…' );
 *
 * The request carries the credentials and the number only; the answer is
 * public registry data (name, tax office, activity, address, active or
 * not). No SoapClient (ext-soap is often missing on shared hosting): the
 * envelope is built here and the answer read with DOMDocument, without a
 * network and without entities (a DOCTYPE is refused).
 *
 * Limits (Bible §22, the checkout lookup is a public point): only numbers
 * with a correct check digit, one answer per number cached for 7 days
 * (and 1 hour for "not found"), at most RATE_IP lookups per visitor (hash of
 * the IP, never the IP itself) in 10 minutes and RATE_ALL per hour for the
 * whole site. An outage or a refused lookup never stops anything: the
 * customer types the details.
 */

defined( 'ABSPATH' ) || exit;

final class IVD_Aade {

	const URL      = 'https://www1.gsis.gr/wsaade/RgWsPublic2/RgWsPublic2';
	const RATE_IP  = 10;
	const RATE_ALL = 300;
	const CACHE    = 'ivd_aade_';
	const LAST     = 'ivd_aade_last';
	const RL       = 'ivd_aade_rl_';

	/** Cloudflare's ranges (Bible §11, IP for rate limits), as in Magic Login. */
	const CF_RANGES = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
		'108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
		'162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
		'2a06:98c0::/29', '2c0f:f248::/32',
	);

	/** Answers already fetched in this request, by number. */
	private static $memo = array();

	/** True when both credentials are defined in wp-config.php. */
	public static function configured(): bool {
		return defined( 'IVD_AADE_USER' ) && defined( 'IVD_AADE_PASS' )
			&& is_string( IVD_AADE_USER ) && is_string( IVD_AADE_PASS )
			&& '' !== IVD_AADE_USER && '' !== IVD_AADE_PASS;
	}

	/** True when the lookup is configured and switched on in the settings. */
	public static function enabled(): bool {
		return self::configured() && ! empty( IVD_Settings::get()['aade'] );
	}

	/**
	 * The last error code of the service (for the settings screen), or ''.
	 * Only a code such as RG_WS_PUBLIC_TOKEN_USERNAME_NOT_AUTHENTICATED and a
	 * time, never the request or the credentials.
	 */
	public static function last_error(): array {
		$v = get_option( self::LAST, array() );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * Rate limit for the public lookup: at most RATE_IP per visitor in 10
	 * minutes and RATE_ALL for the site in an hour. Each counter is bumped
	 * atomically and the bumped value is checked, so requests that arrive
	 * together cannot all read the same count. IPv6 visitors count per /64.
	 *
	 * @return bool True when the lookup may go ahead.
	 */
	public static function allowed(): bool {
		$ip  = self::client_ip();
		$who = substr( wp_hash( '' !== $ip ? $ip : 'none' ), 0, 20 );
		if ( self::bump( 'ip', $who, 10 * MINUTE_IN_SECONDS ) > self::RATE_IP ) {
			return false;
		}
		return self::bump( 'all', 'site', HOUR_IN_SECONDS ) <= self::RATE_ALL;
	}

	/**
	 * Adds one to a counter of the current window and returns the new value.
	 * With a persistent object cache: wp_cache_add + wp_cache_incr. Without
	 * one: one row per window in the options table, raised with a single
	 * UPDATE ... SET option_value = option_value + 1, then read back. Each
	 * request's own increment happens before its read, so a request reads at
	 * least its own count: no more than the cap can pass, even together.
	 *
	 * @param string $kind   'ip' or 'all'.
	 * @param string $who    Hashed visitor key or 'site'.
	 * @param int    $window Window length in seconds.
	 */
	private static function bump( string $kind, string $who, int $window ): int {
		$slot = (int) floor( time() / $window );
		if ( wp_using_ext_object_cache() ) {
			$key = $kind . '_' . $slot . '_' . $who;
			wp_cache_add( $key, 0, 'ivd_aade_rl', $window + 60 );
			$n = wp_cache_incr( $key, 1, 'ivd_aade_rl' );
			return false === $n ? PHP_INT_MAX : (int) $n;
		}
		global $wpdb;
		$name = self::RL . $kind . '_' . $slot . '_' . $who;
		$up   = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s", $name );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- atomic counter on our own rows ($up is prepared above); the options API has no atomic increment.
		$done = $wpdb->query( $up );
		if ( 0 === $done ) {
			$made = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'off')", $name ) );
			if ( 1 === $made ) {
				// A new window: clear the rows of earlier windows of this kind.
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
						$wpdb->esc_like( self::RL . $kind . '_' ) . '%',
						$wpdb->esc_like( self::RL . $kind . '_' . $slot . '_' ) . '%'
					)
				);
			}
			$done = $wpdb->query( $up );
		}
		$n = 1 === $done ? $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ) : null;
		// phpcs:enable
		return null === $n ? PHP_INT_MAX : (int) $n; // Fail closed: no lookup, the customer types the details.
	}

	/**
	 * Company details for a Greek number.
	 *
	 * @param string $afm   Nine digits (prefix and spaces are removed).
	 * @param bool   $fresh Skip the cache (admin «Fill from AADE»).
	 * @return array status: found | inactive | not_found | unavailable | off | bad,
	 *               name, tax_office, activity, hq, error (code only).
	 */
	public static function lookup( string $afm, bool $fresh = false ): array {
		$out = array(
			'status'     => 'off',
			'name'       => '',
			'tax_office' => '',
			'activity'   => '',
			'hq'         => '',
			'error'      => '',
		);
		list( $country, $n ) = IVD_Vat::split( $afm, 'GR' );
		if ( 'GR' !== $country || ! IVD_Vat::greek_ok( $n ) ) {
			$out['status'] = 'bad';
			return $out;
		}
		if ( ! self::configured() ) {
			return $out;
		}
		if ( ! $fresh && isset( self::$memo[ $n ] ) ) {
			return self::$memo[ $n ];
		}
		$key = self::CACHE . md5( $n );
		if ( ! $fresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) && isset( $cached['status'] ) ) {
				return self::$memo[ $n ] = $cached;
			}
		}

		/**
		 * Short-circuits the AADE request (tests): return the SOAP answer as
		 * an XML string, a WP_Error, or null to send the request.
		 *
		 * @param string|WP_Error|null $pre Answer or null.
		 * @param string               $afm Nine digits.
		 */
		$body = apply_filters( 'ivd_aade_response', null, $n );
		if ( null === $body ) {
			$r = wp_safe_remote_post(
				self::URL,
				array(
					'timeout'     => 8,
					'redirection' => 0,
					'headers'     => array( 'Content-Type' => 'application/soap+xml; charset=utf-8' ),
					'user-agent'  => 'Invoice Details/' . IVD_VERSION . ' (WordPress)',
					'body'        => self::envelope( $n ),
				)
			);
			if ( is_wp_error( $r ) ) {
				$body = $r;
			} else {
				$body = (string) wp_remote_retrieve_body( $r );
				if ( '' === $body ) {
					$body = new WP_Error( 'ivd_aade_http', 'HTTP_' . (int) wp_remote_retrieve_response_code( $r ) );
				}
			}
		}
		if ( is_wp_error( $body ) || ! is_string( $body ) ) {
			$out['status'] = 'unavailable';
			$out['error']  = 'NETWORK';
			self::remember_error( 'NETWORK' );
			return self::$memo[ $n ] = $out;
		}

		$out = array_merge( $out, self::parse( $body ) );
		if ( 'unavailable' === $out['status'] ) {
			self::remember_error( '' !== $out['error'] ? $out['error'] : 'UNKNOWN' );
		} else {
			set_transient( $key, $out, 'not_found' === $out['status'] ? HOUR_IN_SECONDS : 7 * DAY_IN_SECONDS );
		}
		return self::$memo[ $n ] = $out;
	}

	/** The SOAP 1.2 request. */
	private static function envelope( string $n ): string {
		$x = static function ( string $v ): string {
			return htmlspecialchars( $v, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		};
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope"'
			. ' xmlns:ns1="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd"'
			. ' xmlns:ns2="http://rgwspublic2/RgWsPublic2Service" xmlns:ns3="http://rgwspublic2/RgWsPublic2">'
			. '<env:Header><ns1:Security><ns1:UsernameToken>'
			. '<ns1:Username>' . $x( (string) IVD_AADE_USER ) . '</ns1:Username>'
			. '<ns1:Password>' . $x( (string) IVD_AADE_PASS ) . '</ns1:Password>'
			. '</ns1:UsernameToken></ns1:Security></env:Header>'
			. '<env:Body><ns2:rgWsPublic2AfmMethod><ns2:INPUT_REC>'
			. '<ns3:afm_called_by></ns3:afm_called_by>'
			. '<ns3:afm_called_for>' . $x( $n ) . '</ns3:afm_called_for>'
			. '</ns2:INPUT_REC></ns2:rgWsPublic2AfmMethod></env:Body></env:Envelope>';
	}

	/**
	 * Reads the answer. Elements are found by local name, whatever the
	 * prefixes and the nesting.
	 *
	 * @param string $xml SOAP answer.
	 */
	public static function parse( string $xml ): array {
		$out = array( 'status' => 'unavailable' );
		if ( strlen( $xml ) > 200000 || false !== stripos( $xml, '<!DOCTYPE' ) || ! class_exists( 'DOMDocument' ) ) {
			$out['error'] = 'BAD_ANSWER';
			return $out;
		}
		$doc  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $doc->loadXML( $xml, LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			$out['error'] = 'BAD_ANSWER';
			return $out;
		}
		$first = static function ( $ctx, string $name ): string {
			$list = $ctx->getElementsByTagNameNS( '*', $name );
			return $list->length ? trim( (string) $list->item( 0 )->textContent ) : '';
		};

		$code = $first( $doc, 'error_code' );
		if ( '' !== $code ) {
			$code          = substr( (string) preg_replace( '/[^A-Z0-9_]/', '', strtoupper( $code ) ), 0, 60 );
			$out['error']  = $code;
			$out['status'] = in_array( $code, array( 'RG_WS_PUBLIC_WRONG_AFM', 'RG_WS_PUBLIC_AFM_CALLED_FOR_NOT_FOUND' ), true ) ? 'not_found' : 'unavailable';
			return $out;
		}
		$recs = $doc->getElementsByTagNameNS( '*', 'basic_rec' );
		if ( ! $recs->length ) {
			$out['error'] = $doc->getElementsByTagNameNS( '*', 'Fault' )->length ? 'FAULT' : 'BAD_ANSWER';
			return $out;
		}
		$rec  = $recs->item( 0 );
		$name = (string) preg_replace( '/\s+/u', ' ', $first( $rec, 'onomasia' ) );
		if ( '' === $name ) {
			$out['status'] = 'not_found';
			return $out;
		}
		$address = trim(
			implode(
				' ',
				array_filter(
					array(
						$first( $rec, 'postal_address' ),
						$first( $rec, 'postal_address_no' ),
					)
				)
			)
		);
		$place = trim( $first( $rec, 'postal_zip_code' ) . ' ' . $first( $rec, 'postal_area_description' ) );
		$hq    = trim( $address . ( '' !== $address && '' !== $place ? ', ' : '' ) . $place );

		$activity = '';
		foreach ( $doc->getElementsByTagNameNS( '*', 'firm_act_tab' ) as $tab ) {
			foreach ( $tab->getElementsByTagNameNS( '*', 'item' ) as $item ) {
				$descr = $first( $item, 'firm_act_descr' );
				if ( '' === $descr ) {
					continue;
				}
				if ( '1' === $first( $item, 'firm_act_kind' ) ) {
					$activity = $descr;
					break 2;
				}
				if ( '' === $activity ) {
					$activity = $descr;
				}
			}
		}

		$clean = static function ( string $v, string $f ): string {
			return IVD_Data::clean( $f, $v );
		};
		return array(
			'status'     => '1' === $first( $rec, 'deactivation_flag' ) || '' === $first( $rec, 'deactivation_flag' ) ? 'found' : 'inactive',
			'name'       => $clean( $name, 'company' ),
			'tax_office' => $clean( $first( $rec, 'doy_descr' ), 'tax_office' ),
			'activity'   => $clean( $activity, 'activity' ),
			'hq'         => $clean( $hq, 'hq' ),
			'error'      => '',
		);
	}

	private static function remember_error( string $code ): void {
		update_option(
			self::LAST,
			array(
				'code' => substr( $code, 0, 60 ),
				'at'   => time(),
			),
			false
		);
	}

	private static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' !== $remote && isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::in_ranges( $remote, self::CF_RANGES ) ) {
			$cf = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( false !== filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				return self::bucket( $cf );
			}
		}
		return self::bucket( $remote );
	}

	/** An IPv6 address counts per /64 (one household or server), IPv4 as is. */
	private static function bucket( string $ip ): string {
		if ( false === strpos( $ip, ':' ) ) {
			return $ip;
		}
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an invalid address stays as it is.
		if ( false === $bin || 16 !== strlen( $bin ) ) {
			return $ip;
		}
		return bin2hex( substr( $bin, 0, 8 ) ) . '::/64';
	}

	/** True when $ip is inside one of the CIDR ranges (IPv4 or IPv6). */
	private static function in_ranges( string $ip, array $ranges ): bool {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an invalid address is simply not in a range.
		if ( false === $bin ) {
			return false;
		}
		foreach ( $ranges as $cidr ) {
			list( $net, $bits ) = explode( '/', $cidr );
			$nbin               = @inet_pton( $net ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $nbin || strlen( $nbin ) !== strlen( $bin ) ) {
				continue;
			}
			$bits  = (int) $bits;
			$bytes = intdiv( $bits, 8 );
			if ( substr( $bin, 0, $bytes ) !== substr( $nbin, 0, $bytes ) ) {
				continue;
			}
			$rest = $bits % 8;
			if ( 0 === $rest ) {
				return true;
			}
			$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
			if ( ( ord( $bin[ $bytes ] ) & $mask ) === ( ord( $nbin[ $bytes ] ) & $mask ) ) {
				return true;
			}
		}
		return false;
	}

	/* =====================================================================
	 * Checkout lookup (AJAX, classic and block)
	 * =================================================================== */

	public static function init(): void {
		add_action( 'wp_ajax_ivd_aade', array( __CLASS__, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_ivd_aade', array( __CLASS__, 'ajax' ) ); // Public (§22): checkout lookup; nonce, check digit, cache and rate limits.
	}

	/** The data the checkout script needs, or null when the lookup is off. */
	public static function script_data(): ?array {
		if ( ! self::enabled() ) {
			return null;
		}
		return array(
			'url'   => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'ivd_aade' ),
			'own'   => IVD_Checkout::own_company(),
			'msg'   => array(
				'found'    => IVD_Settings::text( 'aade_found' ),
				'inactive' => IVD_Settings::text( 'aade_inactive' ),
			),
		);
	}

	/** admin-ajax.php?action=ivd_aade (POST: nonce, vat). */
	public static function ajax(): void {
		if ( ! check_ajax_referer( 'ivd_aade', 'nonce', false ) || ! self::enabled() || ! IVD_Checkout::is_offered() ) {
			wp_send_json_error( array( 'status' => 'off' ), 403 );
		}
		$vat = isset( $_POST['vat'] ) && is_string( $_POST['vat'] ) ? sanitize_text_field( wp_unslash( $_POST['vat'] ) ) : '';
		list( $country, $n ) = IVD_Vat::split( $vat, 'GR' );
		if ( 'GR' !== $country || ! IVD_Vat::greek_ok( $n ) ) {
			wp_send_json_error( array( 'status' => 'bad' ), 400 );
		}
		$cached = get_transient( self::CACHE . md5( $n ) );
		if ( ! is_array( $cached ) && ! self::allowed() ) {
			wp_send_json_error( array( 'status' => 'busy' ), 429 );
		}
		$r = self::lookup( $n );
		if ( in_array( $r['status'], array( 'found', 'inactive' ), true ) ) {
			wp_send_json_success(
				array(
					'status'     => $r['status'],
					'name'       => $r['name'],
					'tax_office' => $r['tax_office'],
					'activity'   => $r['activity'],
					'hq'         => $r['hq'],
				)
			);
		}
		wp_send_json_error( array( 'status' => $r['status'] ), 200 );
	}
}

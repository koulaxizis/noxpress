<?php
/**
 * FRM_Spam — protection against spam without third parties.
 *
 * Every layer runs on this server; nothing about the visitor leaves it.
 *
 *  1. Honeypot: a field named "website", off screen. Filled = bot.
 *  2. Signed time token: on the first touch of the form the script asks
 *     GET frm/v1/token (writes nothing): HMAC over form, issue time and a
 *     random value. «Send» earlier than min_secs or later than 2 hours
 *     after the issue is refused. The page itself stays the same for every
 *     visitor, so page caches keep it.
 *  3. Proof of work: the token is also a puzzle. The browser looks for a
 *     number n with SHA-256( token ":" n ) starting with `pow` zero bits
 *     (a Web Worker, while the visitor types; about 65 000 tries for 16
 *     bits, well under a second on a phone). The server checks one hash. A
 *     token works once (marked on «Send»).
 *  4. Content rules (per form): links, WordPress «Disallowed Comment Keys»,
 *     own words, text without Greek or Latin letters, e-mail domain that
 *     does not accept mail (DNS only).
 *  5. Limits per visitor: rl_form sends per 10 minutes to one form, rl_all
 *     per hour to all; the IP only as an HMAC in a transient that expires.
 *  6. Duplicates: the same content from the same visitor within 10 minutes
 *     is thanked and dropped.
 *
 * Without JavaScript there is no token and no puzzle: FRM_Front shows a
 * «Confirm sending» step (never cached) whose sealed blob (seal()) carries
 * the values and its own issue time.
 *
 * The daily counter (option frm_spam_stats, 30 days, reason => count) has
 * no content and no IP; one visitor adds at most 20 an hour to it.
 */

defined( 'ABSPATH' ) || exit;

final class FRM_Spam {

	const TOKEN_LIFE = 7200;
	const BLOB_LIFE  = 1800;
	const DUP        = 600;
	const RL_FORM    = 600;
	const RL_ALL     = 3600;
	const LOCK       = 60;
	const STATS      = 'frm_spam_stats';
	const STATS_CAP  = 20;

	/** Reasons of the counter: key => label (msgids). */
	public static function reasons(): array {
		return array(
			'honeypot' => __( 'Κρυφό πεδίο', 'forms' ),
			'time'     => __( 'Πολύ γρήγορη ή παλιά αποστολή', 'forms' ),
			'token'    => __( 'Χωρίς έγκυρο token', 'forms' ),
			'pow'      => __( 'Proof of work', 'forms' ),
			'limit'    => __( 'Όριο ανά επισκέπτη', 'forms' ),
			'links'    => __( 'Πολλά links', 'forms' ),
			'words'    => __( 'Λέξεις που δεν επιτρέπονται', 'forms' ),
			'letters'  => __( 'Χωρίς ελληνικά ή λατινικά γράμματα', 'forms' ),
			'mx'       => __( 'Email χωρίς mail server', 'forms' ),
			'custom'   => __( 'Άλλος έλεγχος (φίλτρο)', 'forms' ),
		);
	}

	private static function secret(): string {
		return hash( 'sha256', 'frm|' . wp_salt( 'nonce' ) );
	}

	private static function b64( string $s ): string {
		return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- URL-safe token, not obfuscation.
	}

	private static function unb64( string $s ) {
		$s = strtr( $s, '-_', '+/' );
		$p = strlen( $s ) % 4;
		if ( $p ) {
			$s .= str_repeat( '=', 4 - $p );
		}
		return base64_decode( $s, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- see b64().
	}

	const CF_RANGES = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
		'108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
		'162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
		'2a06:98c0::/29', '2c0f:f248::/32',
	);

	/**
	 * The visitor's address for the limits (Bible §11): REMOTE_ADDR, the
	 * only value a visitor cannot choose; CF-Connecting-IP only when the
	 * request really comes from Cloudflare. Headers such as X-Forwarded-For
	 * are not read: anyone can send them, which made the limit per IP
	 * easy to get around.
	 */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' !== $remote && isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::in_ranges( $remote, self::CF_RANGES ) ) {
			$cf = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( false !== filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				return $cf;
			}
		}
		return $remote;
	}

	/** True when $ip is inside one of the CIDR ranges (IPv4 or IPv6). */
	public static function in_ranges( string $ip, array $ranges ): bool {
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

	/** The visitor's address as an HMAC (never stored in clear). */
	public static function ip_hash(): string {
		return substr( hash_hmac( 'sha256', self::client_ip(), self::secret() ), 0, 32 );
	}

	/* =====================================================================
	 * Tokens and the puzzle
	 * =================================================================== */

	/**
	 * A new token for a form.
	 *
	 * @return array t (token), d (difficulty in bits, 0 = no puzzle), w (seconds to wait
	 *               before «Send» counts).
	 */
	public static function issue( array $form ): array {
		$payload = self::b64(
			(string) wp_json_encode(
				array(
					'f' => (int) $form['id'],
					'i' => time(),
					'n' => bin2hex( random_bytes( 8 ) ),
				)
			)
		);
		$sig     = self::b64( hash_hmac( 'sha256', 'tok|' . $payload, self::secret(), true ) );
		return array(
			't' => $payload . '.' . $sig,
			'd' => $form['spam']['pow'] ? (int) FRM_Settings::get()['pow'] : 0,
			'w' => (int) FRM_Settings::get()['min_secs'],
		);
	}

	/**
	 * Check a token.
	 *
	 * @return string '' when good, else a reason (token | time).
	 */
	public static function check_token( string $token, array $form ): string {
		if ( strlen( $token ) > 300 || 1 !== substr_count( $token, '.' ) ) {
			return 'token';
		}
		list( $payload, $sig ) = explode( '.', $token );
		$want                  = self::b64( hash_hmac( 'sha256', 'tok|' . $payload, self::secret(), true ) );
		if ( ! hash_equals( $want, $sig ) ) {
			return 'token';
		}
		$json = self::unb64( $payload );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) || ! isset( $data['f'], $data['i'] ) || (int) $data['f'] !== (int) $form['id'] ) {
			return 'token';
		}
		$age = time() - (int) $data['i'];
		if ( $age < (int) FRM_Settings::get()['min_secs'] || $age > self::TOKEN_LIFE ) {
			return 'time';
		}
		if ( get_transient( 'frm_used_' . md5( $token ) ) ) {
			return 'token';
		}
		return '';
	}

	/** Count the leading zero bits of a binary string. */
	public static function zero_bits( string $bin ): int {
		$n = 0;
		$l = strlen( $bin );
		for ( $i = 0; $i < $l; $i++ ) {
			$b = ord( $bin[ $i ] );
			if ( 0 === $b ) {
				$n += 8;
				continue;
			}
			for ( $bit = 7; $bit >= 0; $bit-- ) {
				if ( $b & ( 1 << $bit ) ) {
					return $n;
				}
				++$n;
			}
		}
		return $n;
	}

	/** True when the visitor's answer solves the puzzle of the token. */
	public static function check_pow( string $token, string $answer, int $bits ): bool {
		if ( $bits <= 0 ) {
			return true;
		}
		if ( '' === $answer || strlen( $answer ) > 12 || ! ctype_digit( $answer ) ) {
			return false;
		}
		return self::zero_bits( hash( 'sha256', $token . ':' . $answer, true ) ) >= $bits;
	}

	/** Mark a token as used (on an accepted «Send»). */
	public static function spend( string $token ): void {
		set_transient( 'frm_used_' . md5( $token ), 1, self::TOKEN_LIFE + 60 );
	}

	/* =====================================================================
	 * Sealed blob (no-JS confirmation step)
	 * =================================================================== */

	/** Seal values for the confirmation step (HMAC, with its own issue time). */
	public static function seal( array $data ): string {
		$data['_i'] = time();
		$payload    = self::b64( (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
		return $payload . '.' . self::b64( hash_hmac( 'sha256', 'blob|' . $payload, self::secret(), true ) );
	}

	/**
	 * Open a sealed blob.
	 *
	 * @return array|string The data, or a reason: token | time | expired.
	 */
	public static function unseal( string $blob ) {
		if ( strlen( $blob ) > 200000 || 1 !== substr_count( $blob, '.' ) ) {
			return 'token';
		}
		list( $payload, $sig ) = explode( '.', $blob );
		$want                  = self::b64( hash_hmac( 'sha256', 'blob|' . $payload, self::secret(), true ) );
		if ( ! hash_equals( $want, $sig ) ) {
			return 'token';
		}
		$json = self::unb64( $payload );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) || ! isset( $data['_i'] ) ) {
			return 'token';
		}
		$age = time() - (int) $data['_i'];
		if ( $age < (int) FRM_Settings::get()['min_secs'] ) {
			return 'time';
		}
		if ( $age > self::BLOB_LIFE ) {
			return 'expired';
		}
		if ( get_transient( 'frm_used_' . md5( $blob ) ) ) {
			return 'token';
		}
		return $data;
	}

	/* =====================================================================
	 * Limits and duplicates
	 * =================================================================== */

	/** True when this visitor may send to this form now. */
	/**
	 * Take a short lock, atomically (INSERT IGNORE on the options table's unique key), so
	 * that parallel requests cannot all pass the one-use, duplicate and limit checks
	 * before any of them records its send. A lock older than LOCK seconds is stale
	 * (a request that died) and is taken over.
	 */
	public static function lock( string $name ): bool {
		global $wpdb;
		$key = self::lock_key( $name );
		for ( $try = 0; $try < 2; $try++ ) {
			$ok = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, (string) time() ) );
			if ( 1 === (int) $ok ) {
				return true;
			}
			$at = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
			if ( $at && $at > time() - self::LOCK ) {
				return false;
			}
			$wpdb->delete( $wpdb->options, array( 'option_name' => $key ) );
		}
		return false;
	}

	public static function unlock( string $name ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::lock_key( $name ) ) );
	}

	private static function lock_key( string $name ): string {
		return 'frm_lock_' . substr( md5( $name ), 0, 24 );
	}

	/** Whether a token or sealed blob was already used. */
	public static function is_spent( string $token ): bool {
		$name = 'frm_used_' . md5( $token );
		if ( ! wp_using_ext_object_cache() ) {
			// Read again from the table: an earlier miss in this request is cached.
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( '_transient_' . $name, 'options' );
			wp_cache_delete( '_transient_timeout_' . $name, 'options' );
		}
		return (bool) get_transient( $name );
	}

	public static function within_limits( int $form_id ): bool {
		$s  = FRM_Settings::get();
		$ip = self::ip_hash();
		return (int) get_transient( 'frm_rl_' . $form_id . '_' . $ip ) < (int) $s['rl_form']
			&& (int) get_transient( 'frm_rla_' . $ip ) < (int) $s['rl_all'];
	}

	/** Count one accepted send. */
	public static function hit( int $form_id ): void {
		$ip = self::ip_hash();
		$k1 = 'frm_rl_' . $form_id . '_' . $ip;
		$k2 = 'frm_rla_' . $ip;
		set_transient( $k1, (int) get_transient( $k1 ) + 1, self::RL_FORM );
		set_transient( $k2, (int) get_transient( $k2 ) + 1, self::RL_ALL );
	}

	private static function dup_key( int $form_id, array $values ): string {
		return 'frm_dup_' . substr( md5( $form_id . '|' . self::ip_hash() . '|' . wp_json_encode( $values ) ), 0, 32 );
	}

	public static function is_dup( int $form_id, array $values ): bool {
		return (bool) get_transient( self::dup_key( $form_id, $values ) );
	}

	public static function mark_dup( int $form_id, array $values ): void {
		set_transient( self::dup_key( $form_id, $values ), 1, self::DUP );
	}

	/* =====================================================================
	 * Content rules
	 * =================================================================== */

	/** Count links (http://, https://, www., [url=) in a text. */
	public static function count_links( string $text ): int {
		return (int) preg_match_all( '~(https?://|\bwww\.|\[url[=\]])~i', $text );
	}

	/**
	 * Content rules of a form against the clean values.
	 *
	 * @param array $form   Form.
	 * @param array $values key => value (strings).
	 * @return string '' or a reason: links | words | letters | mx | custom.
	 */
	public static function content( array $form, array $values ): string {
		$sp    = $form['spam'];
		$texts = array();
		$email = '';
		foreach ( $form['fields'] as $f ) {
			$v = isset( $values[ $f['key'] ] ) ? (string) $values[ $f['key'] ] : '';
			if ( '' === $v ) {
				continue;
			}
			if ( in_array( $f['type'], array( 'text', 'textarea', 'url' ), true ) ) {
				$texts[] = $v;
			}
			if ( 'email' === $f['type'] && $f['key'] === $form['mail']['email_key'] ) {
				$email = $v;
			}
		}
		$all = implode( "\n", $texts );

		if ( $sp['links'] >= 0 && self::count_links( $all ) > $sp['links'] ) {
			return 'links';
		}
		if ( '' !== $sp['words'] ) {
			foreach ( preg_split( '/\r\n|\r|\n/', $sp['words'] ) as $w ) {
				$w = trim( (string) $w );
				if ( mb_strlen( $w ) >= 2 && false !== mb_stripos( $all . "\n" . $email, $w ) ) {
					return 'words';
				}
			}
		}
		if ( $sp['disallowed'] && function_exists( 'wp_check_comment_disallowed_list' ) && '' !== trim( (string) get_option( 'disallowed_keys' ) ) ) {
			if ( wp_check_comment_disallowed_list( '', $email, '', $all, '', '' ) ) {
				return 'words';
			}
		}
		if ( $sp['letters'] ) {
			foreach ( $form['fields'] as $f ) {
				if ( 'textarea' !== $f['type'] || empty( $values[ $f['key'] ] ) ) {
					continue;
				}
				$v = (string) $values[ $f['key'] ];
				if ( mb_strlen( $v ) >= 10 && ! preg_match( '/[\p{Greek}\p{Latin}]/u', $v ) ) {
					return 'letters';
				}
			}
		}
		if ( $sp['mx'] && '' !== $email && function_exists( 'checkdnsrr' ) ) {
			$domain = (string) substr( strrchr( $email, '@' ), 1 );
			if ( '' !== $domain && ! checkdnsrr( $domain . '.', 'MX' ) && ! checkdnsrr( $domain . '.', 'A' ) && ! checkdnsrr( $domain . '.', 'AAAA' ) ) {
				return 'mx';
			}
		}

		/**
		 * Filters the spam verdict of a submission. Return a non-empty string
		 * (shown to the visitor as it is: keep it short and plain) to refuse.
		 *
		 * @param string $verdict '' = accept.
		 * @param array  $form    Form (FRM_Forms::get()).
		 * @param array  $values  Clean values, key => string.
		 */
		$custom = apply_filters( 'frm_spam_check', '', $form, $values );
		if ( is_string( $custom ) && '' !== $custom ) {
			return 'custom:' . mb_substr( sanitize_text_field( $custom ), 0, 200 );
		}
		return '';
	}

	/* =====================================================================
	 * Daily counter
	 * =================================================================== */

	/** One blocked attempt (no content, no IP), at most STATS_CAP an hour per visitor. */
	public static function count( string $reason ): void {
		$reason = 0 === strpos( $reason, 'custom:' ) ? 'custom' : $reason;
		if ( ! isset( self::reasons()[ $reason ] ) ) {
			return;
		}
		$cap = 'frm_bl_' . self::ip_hash();
		$n   = (int) get_transient( $cap );
		if ( $n >= self::STATS_CAP ) {
			return;
		}
		set_transient( $cap, $n + 1, HOUR_IN_SECONDS );

		$day   = wp_date( 'Y-m-d' );
		$stats = get_option( self::STATS, array() );
		$stats = is_array( $stats ) ? $stats : array();
		if ( ! isset( $stats[ $day ] ) || ! is_array( $stats[ $day ] ) ) {
			$stats[ $day ] = array();
		}
		$stats[ $day ][ $reason ] = (int) ( $stats[ $day ][ $reason ] ?? 0 ) + 1;
		krsort( $stats );
		$stats = array_slice( $stats, 0, 30, true );
		update_option( self::STATS, $stats, false );
	}

	/**
	 * The counter.
	 *
	 * @return array total, today, by_reason (reason => n), days (Y-m-d => n), for the last $days days.
	 */
	public static function stats( int $days = 30 ): array {
		$stats = get_option( self::STATS, array() );
		$stats = is_array( $stats ) ? $stats : array();
		$from  = wp_date( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		$today = wp_date( 'Y-m-d' );
		$out   = array(
			'total'     => 0,
			'today'     => 0,
			'by_reason' => array(),
			'days'      => array(),
		);
		foreach ( $stats as $day => $row ) {
			if ( ! is_array( $row ) || (string) $day < $from ) {
				continue;
			}
			$sum = 0;
			foreach ( $row as $r => $n ) {
				$sum                       += (int) $n;
				$out['by_reason'][ (string) $r ] = (int) ( $out['by_reason'][ (string) $r ] ?? 0 ) + (int) $n;
			}
			$out['days'][ (string) $day ] = $sum;
			$out['total']                += $sum;
			if ( $today === (string) $day ) {
				$out['today'] = $sum;
			}
		}
		arsort( $out['by_reason'] );
		return $out;
	}
}

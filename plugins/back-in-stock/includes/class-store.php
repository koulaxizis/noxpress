<?php
/**
 * BIS_Store — the {prefix}bis_requests table and everything that reads or
 * writes it, the confirm tokens and the rate limits.
 *
 * A row is one request: an address for one product (and variation).
 *  - status 'pending'  a guest's request until the confirm click
 *                      (token_hash set, expires after confirm_h hours)
 *  - status 'active'   waits for the stock (expires after keep_days)
 *  - status 'sent'     the e-mail left: email and token are erased at that
 *                      moment, only email_hash stays for 30 days (the
 *                      «sent in 30 days» count and bis_covers()).
 *
 * The address is kept lowercased; email_hash is an HMAC of it with the
 * site's salt (never a plain hash someone could look up). Tokens are 128
 * random bits; only their HMAC is stored. Dates are GMT.
 */

defined( 'ABSPATH' ) || exit;

final class BIS_Store {

	const DB_OPT = 'bis_db_version';

	/** Days a sent request keeps its hash (counts, bis_covers()). */
	const SENT_KEEP_DAYS = 30;

	/** Days bis_covers() still counts a sent e-mail. */
	const COVER_DAYS = 2;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bis_requests';
	}

	/* =====================================================================
	 * Install / upgrade
	 * =================================================================== */

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(190) NOT NULL DEFAULT '',
  email_hash char(64) NOT NULL DEFAULT '',
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  lang varchar(5) NOT NULL DEFAULT 'el',
  status varchar(10) NOT NULL DEFAULT 'pending',
  token_hash char(64) NOT NULL DEFAULT '',
  created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  confirmed datetime DEFAULT NULL,
  sent datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY request (email_hash,product_id,variation_id),
  KEY product (product_id,variation_id,status),
  KEY status (status,created)
) {$charset};";
		dbDelta( $sql );
		update_option( self::DB_OPT, BIS_DB_VERSION, false );
		// Test mode from the first activation (Π1): only managers see the form.
		add_option( 'bis_settings', array( 'mode' => 'test' ), '', true );
	}

	public static function maybe_upgrade(): void {
		if ( BIS_DB_VERSION !== (string) get_option( self::DB_OPT, '' ) ) {
			self::install();
		}
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	public static function norm_email( string $email ): string {
		return strtolower( trim( sanitize_email( $email ) ) );
	}

	public static function hash_email( string $email ): string {
		return hash_hmac( 'sha256', 'bis|' . self::norm_email( $email ), wp_salt( 'auth' ) );
	}

	private static function hash_token( string $token ): string {
		return hash_hmac( 'sha256', 'bis-token|' . $token, wp_salt( 'auth' ) );
	}

	private static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	private static function ago( int $seconds ): string {
		return gmdate( 'Y-m-d H:i:s', time() - $seconds );
	}

	/** Key of the unsubscribe link: no expiry, tied to the address hash. */
	public static function unsub_key( string $email_hash ): string {
		return substr( hash_hmac( 'sha256', 'bis-unsub|' . $email_hash, wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function check_unsub( string $email_hash, string $key ): bool {
		return 64 === strlen( $email_hash ) && '' !== $key && hash_equals( self::unsub_key( $email_hash ), $key );
	}

	/* =====================================================================
	 * Requests
	 * =================================================================== */

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Adds (or renews) a request.
	 *
	 * @return array { result: 'active'|'pending'|'exists'|'limit'|'error', id: int, token: string }
	 */
	public static function add( string $email, int $product_id, int $variation_id, int $user_id, string $lang, bool $confirm ): array {
		global $wpdb;
		$table = self::table();
		$email = self::norm_email( $email );
		$hash  = self::hash_email( $email );
		$lang  = 'en' === $lang ? 'en' : 'el';
		$out   = array(
			'result' => 'error',
			'id'     => 0,
			'token'  => '',
		);
		if ( '' === $email || ! is_email( $email ) || $product_id <= 0 ) {
			return $out;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE email_hash = %s AND product_id = %d AND variation_id = %d", $hash, $product_id, $variation_id ), ARRAY_A );
		if ( is_array( $row ) && 'active' === $row['status'] ) {
			$out['result'] = 'exists';
			$out['id']     = (int) $row['id'];
			return $out;
		}

		$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email_hash = %s AND status IN ('pending','active')", $hash ) );
		if ( ! is_array( $row ) || 'pending' !== $row['status'] ) {
			if ( $open >= (int) BIS_Settings::get()['per_email'] ) {
				$out['result'] = 'limit';
				return $out;
			}
		}

		$token = $confirm ? bin2hex( random_bytes( 16 ) ) : '';
		$data  = array(
			'email'        => $email,
			'email_hash'   => $hash,
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'user_id'      => $user_id,
			'lang'         => $lang,
			'status'       => $confirm ? 'pending' : 'active',
			'token_hash'   => $confirm ? self::hash_token( $token ) : '',
			'created'      => self::now(),
			'confirmed'    => $confirm ? null : self::now(),
			'sent'         => null,
		);
		$fmt   = array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' );
		if ( is_array( $row ) ) {
			$ok = false !== $wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ), $fmt, array( '%d' ) );
			$id = (int) $row['id'];
		} else {
			$ok = false !== $wpdb->insert( $table, $data, $fmt );
			$id = (int) $wpdb->insert_id;
		}
		// phpcs:enable
		if ( ! $ok || $id <= 0 ) {
			return $out;
		}
		$out['result'] = $confirm ? 'pending' : 'active';
		$out['id']     = $id;
		$out['token']  = $token;
		return $out;
	}

	/** A pending request whose token matches and has not expired, else null. Changes nothing. */
	public static function peek( int $id, string $token ): ?array {
		$row = $id > 0 && '' !== $token ? self::get( $id ) : null;
		if ( ! $row || 'pending' !== $row['status'] || '' === $row['token_hash'] ) {
			return null;
		}
		if ( ! hash_equals( (string) $row['token_hash'], self::hash_token( $token ) ) ) {
			return null;
		}
		if ( strtotime( $row['created'] . ' UTC' ) < time() - (int) BIS_Settings::get()['confirm_h'] * HOUR_IN_SECONDS ) {
			return null;
		}
		return $row;
	}

	/** Confirms a pending request (one use: the token is erased). */
	public static function confirm( int $id, string $token ): ?array {
		global $wpdb;
		$row = self::peek( $id, $token );
		if ( ! $row ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$n = $wpdb->update(
			self::table(),
			array(
				'status'     => 'active',
				'token_hash' => '',
				'confirmed'  => self::now(),
			),
			array(
				'id'     => $id,
				'status' => 'pending',
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( 1 !== (int) $n ) {
			return null;
		}
		$row['status'] = 'active';
		return $row;
	}

	/** The address of an e-mail hash (from any open request), or ''. */
	public static function email_of( string $email_hash ): string {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT email FROM {$table} WHERE email_hash = %s AND email <> '' LIMIT 1", $email_hash ) );
	}

	/** Deletes every open request of an address. Returns the number deleted. */
	public static function unsubscribe( string $email_hash ): int {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE email_hash = %s AND status IN ('pending','active')", $email_hash ) );
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return (bool) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/** True when this address has an active request, or got the e-mail in the last COVER_DAYS days. */
	public static function covers( string $email, int $product_id, int $variation_id ): bool {
		global $wpdb;
		if ( '' === self::norm_email( $email ) ) {
			return false;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$n = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE email_hash = %s AND product_id = %d AND variation_id = %d AND ( status = 'active' OR ( status = 'sent' AND sent >= %s ) )",
				self::hash_email( $email ),
				$product_id,
				$variation_id,
				self::ago( self::COVER_DAYS * DAY_IN_SECONDS )
			)
		);
		return $n > 0;
	}

	/* =====================================================================
	 * Sending
	 * =================================================================== */

	/** Active requests of a product / variation with id > $after, oldest first. */
	public static function due( int $product_id, int $variation_id, int $after, int $limit ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE product_id = %d AND variation_id = %d AND status = 'active' AND id > %d ORDER BY id ASC LIMIT %d", $product_id, $variation_id, $after, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function count_active( int $product_id, int $variation_id ): int {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND variation_id = %d AND status = 'active'", $product_id, $variation_id ) );
	}

	/** Marks a request sent: the address and token are erased, the hash stays. */
	public static function mark_sent( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$wpdb->update(
			self::table(),
			array(
				'status'     => 'sent',
				'email'      => '',
				'token_hash' => '',
				'user_id'    => 0,
				'sent'       => self::now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/** Distinct product / variation pairs with active requests. */
	public static function waiting_pairs(): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, no input.
		$rows = $wpdb->get_results( "SELECT DISTINCT product_id, variation_id FROM {$table} WHERE status = 'active'", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/* =====================================================================
	 * Demand, admin
	 * =================================================================== */

	/**
	 * Counts per product / variation: active, pending, sent in 30 days.
	 *
	 * @param int $limit Rows (0 = all).
	 */
	public static function demand( int $limit = 0 ): array {
		global $wpdb;
		$table = self::table();
		$since = self::ago( 30 * DAY_IN_SECONDS );
		$sql   = "SELECT product_id, variation_id,
			SUM( status = 'active' ) AS active,
			SUM( status = 'pending' ) AS pending,
			SUM( status = 'sent' AND sent >= %s ) AS sent30
			FROM {$table} GROUP BY product_id, variation_id
			HAVING active > 0 OR pending > 0 OR sent30 > 0
			ORDER BY active DESC, pending DESC, sent30 DESC";
		if ( $limit > 0 ) {
			$sql .= ' LIMIT ' . (int) $limit;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- own table, prepared below.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $since ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$out[] = array(
				'product_id'   => (int) $r['product_id'],
				'variation_id' => (int) $r['variation_id'],
				'active'       => (int) $r['active'],
				'pending'      => (int) $r['pending'],
				'sent30'       => (int) $r['sent30'],
			);
		}
		return $out;
	}

	/** Totals over the whole table. */
	public static function totals(): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT SUM( status = 'active' ) AS active, SUM( status = 'pending' ) AS pending, SUM( status = 'sent' AND sent >= %s ) AS sent30 FROM {$table}", self::ago( 30 * DAY_IN_SECONDS ) ), ARRAY_A );
		return array(
			'active'  => is_array( $r ) ? (int) $r['active'] : 0,
			'pending' => is_array( $r ) ? (int) $r['pending'] : 0,
			'sent30'  => is_array( $r ) ? (int) $r['sent30'] : 0,
		);
	}

	/** The open requests (with addresses) of one product / variation, for the admin. */
	public static function rows_for( int $product_id, int $variation_id ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, email, user_id, lang, status, created, confirmed FROM {$table} WHERE product_id = %d AND variation_id = %d AND status IN ('pending','active') ORDER BY id ASC LIMIT 500", $product_id, $variation_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/* =====================================================================
	 * Privacy
	 * =================================================================== */

	public static function by_email( string $email ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email_hash = %s ORDER BY id ASC", self::hash_email( $email ) ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function erase_email( string $email ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return (int) $wpdb->delete( self::table(), array( 'email_hash' => self::hash_email( $email ) ), array( '%s' ) );
	}

	/* =====================================================================
	 * Clean-up (daily)
	 * =================================================================== */

	/** Deletes expired requests and old sent hashes. Returns the rows deleted. */
	public static function cleanup(): int {
		global $wpdb;
		$s     = BIS_Settings::get();
		$table = self::table();
		$n     = 0;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- own table.
		$n += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status = 'pending' AND created < %s", self::ago( (int) $s['confirm_h'] * HOUR_IN_SECONDS ) ) );
		$n += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status = 'active' AND COALESCE( confirmed, created ) < %s", self::ago( (int) $s['keep_days'] * DAY_IN_SECONDS ) ) );
		$n += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status = 'sent' AND sent < %s", self::ago( self::SENT_KEEP_DAYS * DAY_IN_SECONDS ) ) );
		// phpcs:enable
		return $n;
	}

	/* =====================================================================
	 * Rate limit per IP (hash only, one hour)
	 * =================================================================== */

	/** Cloudflare's published ranges (cloudflare.com/ips), for CF-Connecting-IP. */
	const CF_RANGES = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
		'108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
		'162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
		'2a06:98c0::/29', '2c0f:f248::/32',
	);

	/** REMOTE_ADDR; CF-Connecting-IP only when the request really comes from Cloudflare. */
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

	/** Counts one request for the visitor's IP; true when the limit was already reached. */
	public static function over_ip_limit(): bool {
		$key = 'bis_ri_' . md5( self::client_ip() . '|' . wp_salt( 'nonce' ) );
		$n   = (int) get_transient( $key );
		if ( $n >= (int) BIS_Settings::get()['per_ip'] ) {
			return true;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return false;
	}
}

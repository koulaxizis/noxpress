<?php
/**
 * LGD_Store — counters, lockouts, the event log and the statistics.
 *
 * Keys never hold an IP or a username: every identity becomes a salted
 * HMAC (hash_hmac sha256 with wp_salt( 'nonce' ), first 32 hex):
 *  - IP       'i' . hash( ip )
 *  - account  'u' . hash( 'u:' . user id ) for an existing account, or
 *             'u' . hash( 'n:' . lowercased name ) for a name without one,
 *             so both behave the same and say nothing about the account.
 *
 * Transients (sliding from the first failure of a window):
 *  - lgd_f_{key}  array( n, t0 )   failures in the window
 *  - lgd_l_{key}  int until        an active lockout
 *  - lgd_c_{key}  array( n, t0 )   lockouts of an IP in 24 hours (escalation)
 *  - lgd_r_{key}  array( n, t0 )   lost-password / unlock requests in an hour
 *
 * Options (autoload off):
 *  - lgd_locks  key => array( k: 'ip'|'user', until, ip: truncated IP,
 *               uid ), the index of active lockouts for the admin page
 *               (≤500, expired entries dropped on every write)
 *  - lgd_stats  'YmdH' (UTC) => failed logins in that hour, 31 days
 *  - lgd_state  saver: array( h, until ) — the IP that saved the settings
 *               is trusted for 24 hours; surge: array( hour, n, mailed )
 *  - lgd_db     table version
 *
 * Table {prefix}lgd_log: id, t (Unix time), ev, ip (truncated, e.g.
 * 203.0.113.x), iph (16 hex of the IP hash), uid, info. 30 days and at most
 * 5,000 rows (daily clean-up). Only security events: lockouts, unlocks,
 * limits reached, unlock e-mails, new devices, digests. Every login and
 * logout belongs to an activity log, not here.
 */

defined( 'ABSPATH' ) || exit;

final class LGD_Store {

	const DB_VERSION = '1';
	const LOCKS      = 'lgd_locks';
	const STATS      = 'lgd_stats';
	const STATE      = 'lgd_state';
	const MAX_LOCKS  = 500;
	const MAX_ROWS   = 5000;
	const KEEP_DAYS  = 30;

	/** Events of the log: key => label msgid. */
	public static function events(): array {
		return array(
			'lock_ip'     => __( 'Κλείδωμα IP', 'login-guard' ),
			'lock_long'   => __( 'Μακρύ κλείδωμα IP', 'login-guard' ),
			'lock_user'   => __( 'Κλείδωμα λογαριασμού', 'login-guard' ),
			'test_ip'     => __( 'Δοκιμή: θα κλείδωνε IP', 'login-guard' ),
			'test_user'   => __( 'Δοκιμή: θα κλείδωνε λογαριασμό', 'login-guard' ),
			'unlock'      => __( 'Ξεκλείδωμα', 'login-guard' ),
			'unlock_sent' => __( 'Στάλθηκε σύνδεσμος ξεκλειδώματος', 'login-guard' ),
			'limit_lost'  => __( 'Όριο αιτημάτων επαναφοράς κωδικού', 'login-guard' ),
			'limit_unl'   => __( 'Όριο αιτημάτων ξεκλειδώματος', 'login-guard' ),
			'xmlrpc'      => __( 'XML-RPC κλειστό', 'login-guard' ),
			'new_device'  => __( 'Διαχειριστής από νέα συσκευή', 'login-guard' ),
			'surge'       => __( 'Email για κύμα επιθέσεων', 'login-guard' ),
		);
	}

	/* =====================================================================
	 * Table
	 * =================================================================== */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lgd_log';
	}

	public static function install(): void {
		if ( self::DB_VERSION === get_option( 'lgd_db' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$t} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				t int(10) unsigned NOT NULL DEFAULT 0,
				ev varchar(20) NOT NULL DEFAULT '',
				ip varchar(48) NOT NULL DEFAULT '',
				iph char(16) NOT NULL DEFAULT '',
				uid bigint(20) unsigned NOT NULL DEFAULT 0,
				info varchar(100) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY t (t),
				KEY ev (ev)
			) {$charset};"
		);
		update_option( 'lgd_db', self::DB_VERSION, false );
	}

	/** One log row. Never throws: a full disk must not break a login. */
	public static function log( string $ev, string $ip = '', int $uid = 0, string $info = '' ): void {
		if ( ! isset( self::events()[ $ev ] ) ) {
			return;
		}
		global $wpdb;
		try {
			$suppress = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, one insert.
			$ok = $wpdb->insert(
				self::table(),
				array(
					't'    => time(),
					'ev'   => $ev,
					'ip'   => '' !== $ip ? self::trunc( $ip ) : '',
					'iph'  => '' !== $ip ? substr( self::hash( $ip ), 0, 16 ) : '',
					'uid'  => max( 0, $uid ),
					'info' => substr( sanitize_text_field( $info ), 0, 100 ),
				),
				array( '%d', '%s', '%s', '%s', '%d', '%s' )
			);
			$wpdb->suppress_errors( $suppress );
			if ( false === $ok && ! get_transient( 'lgd_db_retry' ) ) {
				// The table may be missing (e.g. after a restore): create it once an hour.
				set_transient( 'lgd_db_retry', 1, HOUR_IN_SECONDS );
				delete_option( 'lgd_db' );
				self::install();
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Newest rows first.
	 *
	 * @return array<int,array{t:int,ev:string,ip:string,iph:string,uid:int,info:string}>
	 */
	public static function rows( int $limit = 200, int $since = 0 ): array {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table name.
		$res = $wpdb->get_results( $wpdb->prepare( "SELECT t, ev, ip, iph, uid, info FROM {$t} WHERE t >= %d ORDER BY id DESC LIMIT %d", $since, max( 1, min( 1000, $limit ) ) ), ARRAY_A );
		$out = array();
		foreach ( is_array( $res ) ? $res : array() as $r ) {
			$out[] = array(
				't'    => (int) $r['t'],
				'ev'   => (string) $r['ev'],
				'ip'   => (string) $r['ip'],
				'iph'  => (string) $r['iph'],
				'uid'  => (int) $r['uid'],
				'info' => (string) $r['info'],
			);
		}
		return $out;
	}

	/** Event counts since a time: ev => n. */
	public static function counts( int $since ): array {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table name.
		$res = $wpdb->get_results( $wpdb->prepare( "SELECT ev, COUNT(*) AS n FROM {$t} WHERE t >= %d GROUP BY ev", $since ), ARRAY_A );
		$out = array_fill_keys( array_keys( self::events() ), 0 );
		foreach ( is_array( $res ) ? $res : array() as $r ) {
			if ( isset( $out[ $r['ev'] ] ) ) {
				$out[ $r['ev'] ] = (int) $r['n'];
			}
		}
		return $out;
	}

	public static function clear_log(): void {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table name.
		$wpdb->query( "DELETE FROM {$t}" );
	}

	/** Daily: rows older than 30 days, rows over 5,000, old statistics. */
	public static function cleanup(): void {
		global $wpdb;
		$t = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table name.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE t < %d", time() - self::KEEP_DAYS * DAY_IN_SECONDS ) );
		$cut = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} ORDER BY id DESC LIMIT 1 OFFSET %d", self::MAX_ROWS ) );
		if ( $cut > 0 ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE id <= %d", $cut ) );
		}
		// phpcs:enable
		self::stats_prune();
		self::locks_write( self::locks() );
	}

	/* =====================================================================
	 * Identities and addresses
	 * =================================================================== */

	/** The visitor's address (Bible §11). */
	public static function ip(): string {
		return Noxpress_Safe::client_ip();
	}

	/** Salted HMAC, 32 hex. */
	public static function hash( string $v ): string {
		return substr( hash_hmac( 'sha256', $v, wp_salt( 'nonce' ) ), 0, 32 );
	}

	public static function key_ip( string $ip ): string {
		return 'i' . self::hash( 'ip:' . $ip );
	}

	/** Account key for a typed login name or e-mail (see the class docblock). */
	public static function key_ident( string $ident ): string {
		$ident = strtolower( trim( $ident ) );
		$user  = self::find_user( $ident );
		return $user ? self::key_user( (int) $user->ID ) : 'u' . self::hash( 'n:' . $ident );
	}

	public static function key_user( int $uid ): string {
		return 'u' . self::hash( 'u:' . $uid );
	}

	/** User by login or e-mail, or null. */
	public static function find_user( string $ident ) {
		$ident = trim( $ident );
		if ( '' === $ident ) {
			return null;
		}
		$u = get_user_by( 'login', $ident );
		if ( ! $u && false !== strpos( $ident, '@' ) ) {
			$u = get_user_by( 'email', $ident );
		}
		return $u instanceof WP_User ? $u : null;
	}

	/** 203.0.113.7 → 203.0.113.x; 2001:db8:1:2::5 → 2001:db8:1::x. */
	public static function trunc( string $ip ): string {
		$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- invalid input gives ''.
		if ( false === $bin ) {
			return '';
		}
		if ( 4 === strlen( $bin ) ) {
			$p = explode( '.', (string) inet_ntop( $bin ) );
			return $p[0] . '.' . $p[1] . '.' . $p[2] . '.x';
		}
		$h = str_split( bin2hex( substr( $bin, 0, 6 ) ), 4 );
		return implode( ':', array_map( static function ( $x ) { return ltrim( $x, '0' ) ?: '0'; }, $h ) ) . '::x'; // phpcs:ignore Universal.Operators.DisallowShortTernary
	}

	/** True for an address that is never counted (allow list, filter, the saver). */
	public static function trusted( string $ip ): bool {
		if ( '' === $ip ) {
			return false;
		}
		$s  = LGD_Settings::get();
		$ok = $s['allow'] && Noxpress_Safe::in_ranges( $ip, $s['allow'] );
		if ( ! $ok ) {
			$st = self::state();
			$ok = isset( $st['saver']['h'], $st['saver']['until'] ) && (int) $st['saver']['until'] > time() && hash_equals( (string) $st['saver']['h'], self::hash( 'saver:' . $ip ) );
		}
		/**
		 * Filters whether an address is trusted (never counted or locked).
		 *
		 * @param bool   $ok
		 * @param string $ip
		 */
		return (bool) apply_filters( 'lgd_trusted_ip', $ok, $ip );
	}

	/** The IP that saves the settings stays trusted for 24 hours (anti self-lockout). */
	public static function remember_saver( string $ip ): void {
		if ( '' === $ip ) {
			return;
		}
		$st          = self::state();
		$st['saver'] = array(
			'h'     => self::hash( 'saver:' . $ip ),
			'until' => time() + DAY_IN_SECONDS,
		);
		update_option( self::STATE, $st, false );
	}

	public static function state(): array {
		$st = get_option( self::STATE, array() );
		return is_array( $st ) ? $st : array();
	}

	/* =====================================================================
	 * Windows and lockouts
	 * =================================================================== */

	/**
	 * Adds one to a window counter and returns the new count. The window
	 * starts with the first hit and does not slide with later hits.
	 */
	public static function bump( string $name, int $window ): int {
		$v   = get_transient( $name );
		$now = time();
		if ( ! is_array( $v ) || ! isset( $v[0], $v[1] ) || (int) $v[1] + $window <= $now ) {
			$v = array( 0, $now );
		}
		$v[0] = (int) $v[0] + 1;
		set_transient( $name, $v, max( 1, (int) $v[1] + $window - $now ) );
		return (int) $v[0];
	}

	/** Count of a window counter without changing it. */
	public static function peek( string $name, int $window ): int {
		$v = get_transient( $name );
		return is_array( $v ) && isset( $v[0], $v[1] ) && (int) $v[1] + $window > time() ? (int) $v[0] : 0;
	}

	/** Seconds left on a lockout of this key, 0 when there is none. */
	public static function locked( string $key ): int {
		$until = (int) get_transient( 'lgd_l_' . $key );
		return $until > time() ? $until - time() : 0;
	}

	/**
	 * Locks a key. $meta: k ('ip' | 'user'), ip (the full address, only
	 * truncated in the index), uid.
	 */
	public static function lock( string $key, int $seconds, array $meta ): int {
		$until = time() + max( 60, $seconds );
		set_transient( 'lgd_l_' . $key, $until, max( 60, $seconds ) );
		delete_transient( 'lgd_f_' . $key );
		$locks         = self::locks();
		$locks[ $key ] = array(
			'k'     => isset( $meta['k'] ) && 'user' === $meta['k'] ? 'user' : 'ip',
			'until' => $until,
			'ip'    => isset( $meta['ip'] ) ? self::trunc( (string) $meta['ip'] ) : '',
			'uid'   => isset( $meta['uid'] ) ? (int) $meta['uid'] : 0,
		);
		self::locks_write( $locks );
		return $until;
	}

	/** Ends a lockout (and its failure counter). True when one was active. */
	public static function unlock( string $key, string $by ): bool {
		$was = self::locked( $key ) > 0;
		delete_transient( 'lgd_l_' . $key );
		delete_transient( 'lgd_f_' . $key );
		$locks = self::locks();
		$meta  = isset( $locks[ $key ] ) ? $locks[ $key ] : array();
		if ( isset( $locks[ $key ] ) ) {
			unset( $locks[ $key ] );
			self::locks_write( $locks );
		}
		if ( $was ) {
			$kind = 0 === strpos( $key, 'u' ) ? 'user' : 'ip';
			self::log( 'unlock', '', isset( $meta['uid'] ) ? (int) $meta['uid'] : 0, $kind . ':' . sanitize_key( $by ) . ( ! empty( $meta['ip'] ) ? ' ' . $meta['ip'] : '' ) );
			/**
			 * A lockout ended early (admin page, unlock e-mail, WP-CLI).
			 *
			 * @param string $kind       'ip' | 'user'.
			 * @param string $ident_hash The hashed key (never an IP or name).
			 * @param string $by         'admin' | 'mail' | 'cli'.
			 */
			do_action( 'lgd_unlocked', $kind, $key, $by );
		}
		return $was;
	}

	/** Active lockouts: key => meta, expired ones dropped. */
	public static function locks(): array {
		$l   = get_option( self::LOCKS, array() );
		$out = array();
		$now = time();
		foreach ( is_array( $l ) ? $l : array() as $k => $m ) {
			if ( is_array( $m ) && isset( $m['until'] ) && (int) $m['until'] > $now && preg_match( '/^[iu][a-f0-9]{32}$/', (string) $k ) ) {
				$out[ (string) $k ] = $m;
			}
		}
		return $out;
	}

	private static function locks_write( array $locks ): void {
		$now   = time();
		$locks = array_filter(
			$locks,
			static function ( $m ) use ( $now ) {
				return is_array( $m ) && isset( $m['until'] ) && (int) $m['until'] > $now;
			}
		);
		if ( count( $locks ) > self::MAX_LOCKS ) {
			uasort(
				$locks,
				static function ( $a, $b ) {
					return (int) $b['until'] <=> (int) $a['until'];
				}
			);
			$locks = array_slice( $locks, 0, self::MAX_LOCKS, true );
		}
		if ( $locks ) {
			update_option( self::LOCKS, $locks, false );
		} else {
			delete_option( self::LOCKS );
		}
	}

	/** Ends every active lockout. Returns how many. */
	public static function unlock_all( string $by ): int {
		$n = 0;
		foreach ( array_keys( self::locks() ) as $k ) {
			$n += self::unlock( (string) $k, $by ) ? 1 : 0;
		}
		return $n;
	}

	/* =====================================================================
	 * Statistics (failed logins per hour)
	 * =================================================================== */

	public static function stats_hit(): void {
		$s = get_option( self::STATS, array() );
		$s = is_array( $s ) ? $s : array();
		$h = gmdate( 'YmdH' );
		$s[ $h ] = ( isset( $s[ $h ] ) ? (int) $s[ $h ] : 0 ) + 1;
		if ( count( $s ) > 24 * 31 + 24 ) {
			ksort( $s );
			$s = array_slice( $s, -24 * 31, null, true );
		}
		update_option( self::STATS, $s, false );
	}

	/** Failed logins in the last $hours hours. */
	public static function failures( int $hours ): int {
		$s   = get_option( self::STATS, array() );
		$min = gmdate( 'YmdH', time() - ( $hours - 1 ) * HOUR_IN_SECONDS );
		$n   = 0;
		foreach ( is_array( $s ) ? $s : array() as $h => $c ) {
			if ( (string) $h >= $min ) {
				$n += (int) $c;
			}
		}
		return $n;
	}

	private static function stats_prune(): void {
		$s   = get_option( self::STATS, array() );
		$min = gmdate( 'YmdH', time() - 31 * DAY_IN_SECONDS );
		$out = array();
		foreach ( is_array( $s ) ? $s : array() as $h => $c ) {
			if ( (string) $h >= $min ) {
				$out[ (string) $h ] = (int) $c;
			}
		}
		update_option( self::STATS, $out, false );
	}
}

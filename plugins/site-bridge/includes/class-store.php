<?php
/**
 * SBR_Store — the plugin's tables, rate counters and daily purge.
 *
 *  - {prefix}sbr_log     audit log: one row per tool call on each side
 *                        (hub and site), arguments with secrets removed
 *  - {prefix}sbr_grants  OAuth connections: hashes of the current access
 *                        and refresh tokens (never the tokens), scope,
 *                        allowed sites, lifetimes, revocation
 *
 * Created on activation and on version change (dbDelta). The daily
 * WP-Cron event sbr_purge removes log rows older than log_days and
 * connections that ended more than 30 days ago.
 */

defined( 'ABSPATH' ) || exit;

final class SBR_Store {

	const DB_VERSION = '1';
	const OPT_DB     = 'sbr_db_version';
	const CRON       = 'sbr_purge';

	public static function init(): void {
		add_action( self::CRON, array( __CLASS__, 'purge' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
	}

	public static function log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sbr_log';
	}

	public static function grants_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sbr_grants';
	}

	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::OPT_DB ) ) {
			self::install();
			self::schedule();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c   = $wpdb->get_charset_collate();
		$log = self::log_table();
		$gr  = self::grants_table();
		dbDelta(
			"CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ts datetime NOT NULL,
  side varchar(4) NOT NULL DEFAULT '',
  grant_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client varchar(190) NOT NULL DEFAULT '',
  site varchar(64) NOT NULL DEFAULT '',
  tool varchar(64) NOT NULL DEFAULT '',
  class varchar(8) NOT NULL DEFAULT '',
  args text NOT NULL,
  status varchar(8) NOT NULL DEFAULT '',
  message varchar(255) NOT NULL DEFAULT '',
  bytes int(10) unsigned NOT NULL DEFAULT 0,
  ms int(10) unsigned NOT NULL DEFAULT 0,
  ip varchar(45) NOT NULL DEFAULT '',
  extra longtext NOT NULL,
  PRIMARY KEY  (id),
  KEY ts (ts),
  KEY tool (tool),
  KEY site (site)
) {$c};"
		);
		dbDelta(
			"CREATE TABLE {$gr} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_id varchar(255) NOT NULL DEFAULT '',
  client_name varchar(190) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  scope varchar(64) NOT NULL DEFAULT '',
  sites text NOT NULL,
  access_hash char(64) NOT NULL DEFAULT '',
  access_exp int(10) unsigned NOT NULL DEFAULT 0,
  refresh_hash char(64) NOT NULL DEFAULT '',
  refresh_exp int(10) unsigned NOT NULL DEFAULT 0,
  prev_refresh_hash char(64) NOT NULL DEFAULT '',
  rotated_at int(10) unsigned NOT NULL DEFAULT 0,
  created int(10) unsigned NOT NULL DEFAULT 0,
  consent_exp int(10) unsigned NOT NULL DEFAULT 0,
  last_used int(10) unsigned NOT NULL DEFAULT 0,
  last_ip varchar(45) NOT NULL DEFAULT '',
  uses bigint(20) unsigned NOT NULL DEFAULT 0,
  revoked tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY access_hash (access_hash),
  KEY refresh_hash (refresh_hash),
  KEY prev_refresh_hash (prev_refresh_hash)
) {$c};"
		);
		update_option( self::OPT_DB, self::DB_VERSION, false );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/* =====================================================================
	 * Audit log
	 * =================================================================== */

	/**
	 * Writes one row. $row keys: side, grant_id, client, site, tool, class,
	 * args (array, redacted here), status (ok|denied|error), message,
	 * bytes, ms, extra (array, e.g. the old value of an option).
	 */
	public static function log( array $row ): void {
		global $wpdb;
		$args = isset( $row['args'] ) && is_array( $row['args'] ) ? SBR_Guard::redact_args( $row['args'] ) : array();
		$json = Site_Bridge::json( $args );
		if ( strlen( $json ) > 4000 ) {
			$json = substr( $json, 0, 4000 ) . '…';
		}
		$extra = '';
		if ( ! empty( $row['extra'] ) ) {
			$extra = Site_Bridge::json( $row['extra'] );
			if ( strlen( $extra ) > 200000 ) {
				$extra = Site_Bridge::json( array( 'truncated' => true ) );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- our own table.
		$wpdb->insert(
			self::log_table(),
			array(
				'ts'       => gmdate( 'Y-m-d H:i:s' ),
				'side'     => substr( (string) ( $row['side'] ?? '' ), 0, 4 ),
				'grant_id' => (int) ( $row['grant_id'] ?? 0 ),
				'client'   => substr( (string) ( $row['client'] ?? '' ), 0, 190 ),
				'site'     => substr( (string) ( $row['site'] ?? '' ), 0, 64 ),
				'tool'     => substr( (string) ( $row['tool'] ?? '' ), 0, 64 ),
				'class'    => substr( (string) ( $row['class'] ?? '' ), 0, 8 ),
				'args'     => $json,
				'status'   => substr( (string) ( $row['status'] ?? '' ), 0, 8 ),
				'message'  => function_exists( 'mb_substr' ) ? mb_substr( (string) ( $row['message'] ?? '' ), 0, 250 ) : substr( (string) ( $row['message'] ?? '' ), 0, 250 ),
				'bytes'    => max( 0, (int) ( $row['bytes'] ?? 0 ) ),
				'ms'       => max( 0, (int) ( $row['ms'] ?? 0 ) ),
				'ip'       => substr( Site_Bridge::ip(), 0, 45 ),
				'extra'    => $extra,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Log rows, newest first. $f: side, site, tool, status (exact),
	 * from / to (Y-m-d, UTC days), limit, offset.
	 */
	public static function log_rows( array $f, int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		list( $where, $vals ) = self::log_where( $f );
		$sql  = 'SELECT id, ts, side, grant_id, client, site, tool, class, args, status, message, bytes, ms, ip FROM ' . self::log_table() . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$vals = array_merge( $vals, array( max( 1, $limit ), max( 0, $offset ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- prepared below, our table.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function log_count( array $f ): int {
		global $wpdb;
		list( $where, $vals ) = self::log_where( $f );
		$sql = 'SELECT COUNT(*) FROM ' . self::log_table() . $where;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- prepared when there are values.
		return (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $sql, $vals ) ) : $wpdb->get_var( $sql ) );
	}

	private static function log_where( array $f ): array {
		$w = array();
		$v = array();
		foreach ( array( 'side', 'site', 'tool', 'status' ) as $k ) {
			if ( ! empty( $f[ $k ] ) ) {
				$w[] = "{$k} = %s";
				$v[] = (string) $f[ $k ];
			}
		}
		if ( ! empty( $f['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $f['from'] ) ) {
			$w[] = 'ts >= %s';
			$v[] = $f['from'] . ' 00:00:00';
		}
		if ( ! empty( $f['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $f['to'] ) ) {
			$w[] = 'ts <= %s';
			$v[] = $f['to'] . ' 23:59:59';
		}
		return array( $w ? ' WHERE ' . implode( ' AND ', $w ) : '', $v );
	}

	/** Distinct values of a log column (filters in the admin). */
	public static function log_distinct( string $col ): array {
		global $wpdb;
		if ( ! in_array( $col, array( 'site', 'tool' ), true ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- whitelisted column, our table.
		$vals = $wpdb->get_col( "SELECT DISTINCT {$col} FROM " . self::log_table() . " WHERE {$col} <> '' ORDER BY {$col} LIMIT 200" );
		return is_array( $vals ) ? $vals : array();
	}

	/* =====================================================================
	 * Rate counters
	 * =================================================================== */

	/**
	 * Counts one hit for $key and says whether it stays within $per_min and
	 * $per_day. Transient buckets; approximate under heavy concurrency,
	 * which is enough for its purpose (stopping a runaway loop).
	 */
	public static function hit( string $key, int $per_min, int $per_day ): bool {
		$k   = substr( md5( $key ), 0, 16 );
		$min = 'sbr_rl_m_' . $k . '_' . (int) floor( time() / 60 );
		$day = 'sbr_rl_d_' . $k . '_' . gmdate( 'Ymd' );
		$m   = (int) get_transient( $min ) + 1;
		$d   = (int) get_transient( $day ) + 1;
		set_transient( $min, $m, 120 );
		set_transient( $day, $d, DAY_IN_SECONDS + HOUR_IN_SECONDS );
		return $m <= $per_min && $d <= $per_day;
	}

	/* =====================================================================
	 * Purge
	 * =================================================================== */

	public static function purge(): void {
		global $wpdb;
		$days = (int) SBR_Settings::v( 'log_days' );
		$cut  = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * max( 1, $days ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- our table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::log_table() . ' WHERE ts < %s', $cut ) );
		$old = time() - 30 * DAY_IN_SECONDS;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- our table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::grants_table() . ' WHERE ( revoked = 1 AND rotated_at < %d AND created < %d ) OR ( refresh_exp < %d AND consent_exp < %d )', $old, $old, $old, $old ) );
	}
}

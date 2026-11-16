<?php
/**
 * ACL_Store — the table, the per-request buffer, queries, purge.
 *
 * Table {prefix}acl_events (dbDelta on activation and when acl_db changes,
 * Bible §12):
 *   id, ts (UTC), actor_id, actor_label, actor_role, context, ip,
 *   category, action, object_type, object_id, object_label, severity,
 *   details (JSON, up to 8 KB), ref (e.g. 'sbr:123', a Site Bridge row)
 *
 * Events of one request are kept in memory and written ONCE at shutdown
 * with one multi-row INSERT. Two events with the same object, action and
 * actor in one request become one row with the details of both (WooCommerce
 * saves a product two or three times for one «Update»). A request that
 * dies with a fatal error loses its events: written in the design.
 *
 * Append only: rows are never changed from the admin. «Empty the history»
 * deletes everything and then writes who did it. The daily purge removes
 * rows older than the setting and the oldest rows above the row limit,
 * in batches of 5 000.
 */

defined( 'ABSPATH' ) || exit;

final class ACL_Store {

	const DB_VERSION  = '1';
	const DB_OPT      = 'acl_db';
	const MAX_DETAILS = 8000;
	const BATCH       = 5000;
	const MAX_BUFFER  = 500;

	/** @var array Events waiting for shutdown, keyed by merge key. */
	private static $buffer = array();

	/** @var bool Shutdown hook added. */
	private static $hooked = false;

	public static function init(): void {
		// Schema change on update without activation (Bible §12).
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'acl_events';
	}

	public static function maybe_install(): void {
		if ( self::DB_VERSION !== (string) get_option( self::DB_OPT, '' ) ) {
			if ( get_transient( 'acl_db_lock' ) ) {
				return;
			}
			set_transient( 'acl_db_lock', 1, 60 );
			self::install();
			delete_transient( 'acl_db_lock' );
		}
	}

	public static function install(): void {
		if ( self::DB_VERSION === (string) get_option( self::DB_OPT, '' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$t} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				ts datetime NOT NULL,
				actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				actor_label varchar(190) NOT NULL DEFAULT '',
				actor_role varchar(64) NOT NULL DEFAULT '',
				context varchar(16) NOT NULL DEFAULT '',
				ip varchar(45) NOT NULL DEFAULT '',
				category varchar(32) NOT NULL DEFAULT '',
				action varchar(64) NOT NULL DEFAULT '',
				object_type varchar(32) NOT NULL DEFAULT '',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				object_label varchar(190) NOT NULL DEFAULT '',
				severity varchar(8) NOT NULL DEFAULT 'info',
				details longtext NULL,
				ref varchar(32) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY ts (ts),
				KEY object (object_type, object_id),
				KEY actor (actor_id),
				KEY category (category, ts)
			) {$charset};"
		);
		update_option( self::DB_OPT, self::DB_VERSION, true );
	}

	/* =====================================================================
	 * Buffer
	 * =================================================================== */

	/**
	 * Queues one event. $e keys: category, action, object_type, object_id,
	 * label, details (array), severity, actor (optional array( id, label,
	 * role ) when the actor is not the current user).
	 */
	public static function add( array $e ): void {
		$cats = ACL_Settings::categories();
		$cat  = isset( $e['category'] ) ? (string) $e['category'] : 'suite';
		if ( 'log' !== $cat && ! isset( $cats[ $cat ] ) ) {
			$cat = 'suite'; // acl_log() from a plugin with its own category name.
		}
		if ( 'log' !== $cat && ! ACL_Settings::on( $cat ) ) {
			return;
		}
		$action = substr( (string) preg_replace( '/[^a-z0-9_.\-]/', '', strtolower( (string) $e['action'] ) ), 0, 64 );
		if ( '' === $action ) {
			return;
		}
		$actor = isset( $e['actor'] ) && is_array( $e['actor'] ) ? $e['actor'] : ACL_Context::actor();
		$row   = array(
			'category'     => $cat,
			'action'       => $action,
			'object_type'  => substr( sanitize_key( (string) ( $e['object_type'] ?? '' ) ), 0, 32 ),
			'object_id'    => max( 0, (int) ( $e['object_id'] ?? 0 ) ),
			'object_label' => self::cut( (string) ( $e['label'] ?? '' ), 190 ),
			'severity'     => in_array( $e['severity'] ?? '', array( 'info', 'notice', 'warning', 'critical' ), true ) ? $e['severity'] : 'info',
			'details'      => isset( $e['details'] ) && is_array( $e['details'] ) ? $e['details'] : array(),
			'actor_id'     => max( 0, (int) $actor[0] ),
			'actor_label'  => self::cut( (string) $actor[1], 190 ),
			'actor_role'   => substr( sanitize_key( (string) $actor[2] ), 0, 64 ),
			'context'      => ACL_Context::context(),
			'ip'           => ACL_Context::stored_ip(),
			'ref'          => ACL_Context::ref(),
			'_call'        => ACL_Context::call(),
		);

		/** Return true to drop an event (e.g. a noisy option of your own). */
		if ( apply_filters( 'acl_skip_event', false, $row ) ) {
			return;
		}

		$tail = '|' . $row['object_type'] . '|' . $row['object_id'] . '|' . $row['actor_id'] . '|' . $row['ref'];
		$key  = $row['action'] . $tail;
		// Saves right after a creation belong to the «created» row.
		if ( preg_match( '/^(.+)\.updated$/', $row['action'], $m ) && isset( self::$buffer[ $m[1] . '.created' . $tail ] ) ) {
			$key = $m[1] . '.created' . $tail;
		}
		if ( '' === $row['object_type'] && 0 === $row['object_id'] ) {
			$key .= '|' . count( self::$buffer ); // Events without an object are never merged.
		}
		if ( isset( self::$buffer[ $key ] ) ) {
			$prev                 = self::$buffer[ $key ];
			$prev['details']      = array_merge( $prev['details'], $row['details'] );
			$prev['object_label'] = '' !== $row['object_label'] ? $row['object_label'] : $prev['object_label'];
			$prev['severity']     = self::worse( $prev['severity'], $row['severity'] );
			self::$buffer[ $key ] = $prev;
		} else {
			if ( count( self::$buffer ) >= self::MAX_BUFFER ) {
				self::flush(); // A bulk action: write what we have, keep going.
			}
			$row['ts']            = gmdate( 'Y-m-d H:i:s' );
			self::$buffer[ $key ] = $row;
		}

		if ( 'critical' === $row['severity'] ) {
			ACL_Notify::queue( $row );
		}
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 1 );
		}
	}

	/** Points the queued rows of one Site Bridge call to its audit row. */
	public static function stamp( int $call, string $ref ): void {
		if ( $call <= 0 ) {
			return;
		}
		foreach ( self::$buffer as $key => $row ) {
			if ( $call === (int) ( $row['_call'] ?? 0 ) && '' === $row['ref'] ) {
				self::$buffer[ $key ]['ref'] = substr( $ref, 0, 32 );
			}
		}
	}

	/** Drops a queued «updated» row that ended up with no details. */
	private static function worth( array $row ): bool {
		if ( preg_match( '/\.updated$/', $row['action'] ) && empty( $row['details'] ) ) {
			return false;
		}
		return true;
	}

	private static function worse( string $a, string $b ): string {
		$rank = array(
			'info'     => 0,
			'notice'   => 1,
			'warning'  => 2,
			'critical' => 3,
		);
		return ( $rank[ $b ] ?? 0 ) > ( $rank[ $a ] ?? 0 ) ? $b : $a;
	}

	/** Writes the buffer: one INSERT for every queued row. */
	public static function flush(): void {
		global $wpdb;
		$rows         = array_values( array_filter( self::$buffer, array( __CLASS__, 'worth' ) ) );
		self::$buffer = array();
		if ( ! $rows || self::DB_VERSION !== (string) get_option( self::DB_OPT, '' ) ) {
			return;
		}
		$cols  = array( 'ts', 'actor_id', 'actor_label', 'actor_role', 'context', 'ip', 'category', 'action', 'object_type', 'object_id', 'object_label', 'severity', 'details', 'ref' );
		$place = '(%s,%d,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s,%s)';
		foreach ( array_chunk( $rows, 100 ) as $chunk ) {
			$sql  = array();
			$vals = array();
			foreach ( $chunk as $r ) {
				$json = wp_json_encode( $r['details'], JSON_UNESCAPED_UNICODE );
				if ( ! is_string( $json ) || strlen( $json ) > self::MAX_DETAILS ) {
					$json = wp_json_encode( array( '…' => array( 't' => 'cut' ) ) );
				}
				$r['details'] = $json;
				$sql[]        = $place;
				foreach ( $cols as $c ) {
					$vals[] = $r[ $c ];
				}
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery -- our own table, placeholders built above.
			$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::table() . ' (' . implode( ',', $cols ) . ') VALUES ' . implode( ',', $sql ), $vals ) );
		}
	}

	private static function cut( string $s, int $len ): string {
		$s = trim( wp_strip_all_tags( $s ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $len ) : substr( $s, 0, $len );
	}

	/* =====================================================================
	 * Queries
	 * =================================================================== */

	/**
	 * WHERE for the filters: from / to (Y-m-d, site days), actor (id),
	 * category, context, severity, object_type + object_id, action
	 * prefix, q (object label or action, LIKE).
	 */
	private static function where( array $f ): array {
		global $wpdb;
		$w = array();
		$v = array();
		$tz = wp_timezone();
		foreach ( array( 'from' => '00:00:00', 'to' => '23:59:59' ) as $k => $time ) {
			if ( ! empty( $f[ $k ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $f[ $k ] ) ) {
				try {
					$d   = new DateTimeImmutable( $f[ $k ] . ' ' . $time, $tz );
					$w[] = 'from' === $k ? 'ts >= %s' : 'ts <= %s';
					$v[] = $d->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
				} catch ( \Exception $e ) {
					continue;
				}
			}
		}
		if ( isset( $f['since_id'] ) && (int) $f['since_id'] > 0 ) {
			$w[] = 'id > %d';
			$v[] = (int) $f['since_id'];
		}
		if ( isset( $f['actor'] ) && '' !== (string) $f['actor'] ) {
			$w[] = 'actor_id = %d';
			$v[] = (int) $f['actor'];
		}
		foreach ( array( 'category', 'context', 'severity', 'object_type' ) as $k ) {
			if ( ! empty( $f[ $k ] ) ) {
				$w[] = "{$k} = %s";
				$v[] = (string) $f[ $k ];
			}
		}
		if ( ! empty( $f['object_id'] ) ) {
			$w[] = 'object_id = %d';
			$v[] = (int) $f['object_id'];
		}
		if ( ! empty( $f['min_severity'] ) && in_array( $f['min_severity'], array( 'notice', 'warning', 'critical' ), true ) ) {
			$levels = array_slice( array( 'info', 'notice', 'warning', 'critical' ), array_search( $f['min_severity'], array( 'info', 'notice', 'warning', 'critical' ), true ) );
			$w[]    = 'severity IN (' . implode( ',', array_fill( 0, count( $levels ), '%s' ) ) . ')';
			$v      = array_merge( $v, $levels );
		}
		if ( isset( $f['q'] ) && '' !== trim( (string) $f['q'] ) ) {
			$like = '%' . $wpdb->esc_like( trim( (string) $f['q'] ) ) . '%';
			$w[]  = '(object_label LIKE %s OR action LIKE %s OR actor_label LIKE %s)';
			$v[]  = $like;
			$v[]  = $like;
			$v[]  = $like;
		}
		return array( $w ? ' WHERE ' . implode( ' AND ', $w ) : '', $v );
	}

	/** Rows, newest first. */
	public static function rows( array $f, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		list( $where, $vals ) = self::where( $f );
		$sql  = 'SELECT * FROM ' . self::table() . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$vals = array_merge( $vals, array( max( 1, $limit ), max( 0, $offset ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- prepared, our table.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$d              = json_decode( (string) $r['details'], true );
			$r['details']   = is_array( $d ) ? $d : array();
			$r['id']        = (int) $r['id'];
			$r['actor_id']  = (int) $r['actor_id'];
			$r['object_id'] = (int) $r['object_id'];
			$out[]          = $r;
		}
		return $out;
	}

	public static function count( array $f = array() ): int {
		global $wpdb;
		list( $where, $vals ) = self::where( $f );
		$sql = 'SELECT COUNT(*) FROM ' . self::table() . $where;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- prepared when there are values, our table.
		return (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $sql, $vals ) ) : $wpdb->get_var( $sql ) );
	}

	/** Actors seen in the history: id => last label (for the filter). */
	public static function actors(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- no input, our table.
		$rows = $wpdb->get_results( 'SELECT actor_id, MAX(id) AS last FROM ' . self::table() . ' WHERE actor_id > 0 GROUP BY actor_id ORDER BY last DESC LIMIT 200', ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- prepared, our table.
			$label                         = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT actor_label FROM ' . self::table() . ' WHERE id = %d', (int) $r['last'] ) );
			$out[ (int) $r['actor_id'] ] = $label;
		}
		return $out;
	}

	/* =====================================================================
	 * Purge, clear, erase
	 * =================================================================== */

	/** Daily: rows older than «days», then the oldest above «max». */
	public static function purge(): int {
		global $wpdb;
		if ( self::DB_VERSION !== (string) get_option( self::DB_OPT, '' ) ) {
			return 0;
		}
		$s      = ACL_Settings::get();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) $s['days'] * DAY_IN_SECONDS );
		$gone   = 0;
		for ( $i = 0; $i < 200; $i++ ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- prepared, our table.
			$n     = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE ts < %s ORDER BY id LIMIT %d', $cutoff, self::BATCH ) );
			$gone += $n;
			if ( $n < self::BATCH ) {
				break;
			}
		}
		$total = self::count();
		$over  = $total - (int) $s['max'];
		if ( $over > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- prepared, our table.
			$edge = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' ORDER BY id LIMIT 1 OFFSET %d', $over - 1 ) );
			for ( $i = 0; $edge > 0 && $i < 400; $i++ ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- prepared, our table.
				$n     = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE id <= %d ORDER BY id LIMIT %d', $edge, self::BATCH ) );
				$gone += $n;
				if ( $n < self::BATCH ) {
					break;
				}
			}
		}
		return $gone;
	}

	/** Deletes every row, then writes who emptied the history. */
	public static function clear(): void {
		global $wpdb;
		self::$buffer = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- no input, our table.
		$wpdb->query( 'TRUNCATE TABLE ' . self::table() );
		self::add(
			array(
				'category' => 'log',
				'action'   => 'log.cleared',
				'severity' => 'warning',
			)
		);
		self::flush();
	}

	/**
	 * Privacy eraser: rows where the user is the actor keep the event but
	 * lose who did it (label 'deleted', no IP), or the object when the row
	 * is about that user. Returns the number of rows changed.
	 */
	public static function anonymise( int $uid ): int {
		global $wpdb;
		if ( $uid <= 0 ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- our own table.
		$a = (int) $wpdb->update(
			self::table(),
			array(
				'actor_id'    => 0,
				'actor_label' => 'deleted',
				'ip'          => '',
			),
			array( 'actor_id' => $uid ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- our own table.
		$b = (int) $wpdb->update(
			self::table(),
			array(
				'object_id'    => 0,
				'object_label' => 'deleted',
			),
			array(
				'object_type' => 'user',
				'object_id'   => $uid,
			),
			array( '%d', '%s' ),
			array( '%s', '%d' )
		);
		return $a + $b;
	}
}

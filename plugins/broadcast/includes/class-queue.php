<?php
/**
 * BRC_Queue — the {prefix}brc_items table: the queue and the history.
 *
 * One row per (source item, destination). The row keeps the neutral
 * message (JSON: title, text, url, image, kind), so the queue screen shows
 * exactly what will be posted, and the last answer of the destination.
 *
 * Statuses:
 *  - pending   waits for the owner; reason approval | brake | publish
 *              (queue source: «Δημοσιεύτηκε») | unsure (YouTube could not
 *              tell a Short)
 *  - queued    will be posted at send_at
 *  - sending   claimed by a run (a second run never posts it again)
 *  - sent      posted (result = the destination's message id)
 *  - test      test mode: would have been posted, nothing left the site
 *  - failed    the destination refused it, or the retries ran out
 *  - skipped   reason dup_url (same link to the same place within 7 days)
 *              | off (module switched off) | gone (the source item changed)
 *  - rejected  rejected by the owner
 *
 * Dedupe: unique dkey = sha1( source | source key | destination ), so a
 * webhook delivered twice or a product published again is never posted
 * twice. The brake (items per source per hour / day) turns the excess
 * into pending items with a notice. The table is created with dbDelta on
 * activation and when the schema version (option brc_db) changes.
 *
 * brc_status (autoload off) keeps small facts for the admin: last Viber
 * callback, last newsletter hook, last YouTube check, last brake, digest
 * slot done. No personal data.
 */

defined( 'ABSPATH' ) || exit;

final class BRC_Queue {

	const DB_VERSION = '1';
	const STATUS_OPT = 'brc_status';

	/** Statuses that count as "this link already went (or is going) there". */
	const LIVE = array( 'pending', 'queued', 'sending', 'sent', 'test' );

	/** Statuses that are final (cleaned by retention). */
	const DONE = array( 'sent', 'test', 'failed', 'skipped', 'rejected' );

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'brc_items';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$t} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created int(10) unsigned NOT NULL DEFAULT 0,
				source varchar(20) NOT NULL DEFAULT '',
				skey varchar(100) NOT NULL DEFAULT '',
				dest varchar(20) NOT NULL DEFAULT '',
				dkey char(40) NOT NULL DEFAULT '',
				status varchar(12) NOT NULL DEFAULT '',
				reason varchar(20) NOT NULL DEFAULT '',
				send_at int(10) unsigned NOT NULL DEFAULT 0,
				sent_at int(10) unsigned NOT NULL DEFAULT 0,
				tries tinyint(3) unsigned NOT NULL DEFAULT 0,
				title varchar(255) NOT NULL DEFAULT '',
				url_hash char(40) NOT NULL DEFAULT '',
				msg longtext NOT NULL,
				result text NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY dkey (dkey),
				KEY status_send (status,send_at),
				KEY source_created (source,created),
				KEY url_hash (url_hash)
			) {$charset};"
		);
		update_option( 'brc_db', self::DB_VERSION, false );
	}

	/** admin_init: creates or upgrades the table when the schema changed. */
	public static function maybe_install(): void {
		if ( self::DB_VERSION !== get_option( 'brc_db', '' ) ) {
			self::install();
		}
	}

	/* ---------------------------------------------------------------------
	 * Small facts for the admin
	 * ------------------------------------------------------------------- */

	public static function notes(): array {
		$n = get_option( self::STATUS_OPT, array() );
		return is_array( $n ) ? $n : array();
	}

	public static function note( string $key, $value ): void {
		$n         = self::notes();
		$n[ $key ] = $value;
		update_option( self::STATUS_OPT, $n, false );
	}

	/* ---------------------------------------------------------------------
	 * Adding
	 * ------------------------------------------------------------------- */

	/**
	 * A message from a source into the queue, one row per active destination.
	 *
	 * @param string $source  Source module.
	 * @param string $skey    The source's own id of the item (dedupe key).
	 * @param array  $msg     title, text, url, image, kind.
	 * @param array  $opts    delay (seconds), reason (forces pending with it),
	 *                        same_url (false: the link is shared by many items,
	 *                        e.g. a newsletter archive, so it is no dedupe key).
	 * @return int[] New row ids (none when nothing was added).
	 */
	public static function add( string $source, string $skey, array $msg, array $opts = array() ): array {
		global $wpdb;
		if ( Broadcast::killed() || 'off' === BRC_Settings::get()['mode'] ) {
			return array();
		}
		$skey = mb_substr( $skey, 0, 100 );

		/**
		 * Filters a message before it is queued. Return an empty array to drop it.
		 *
		 * @param array  $msg    title, text, url, image, kind.
		 * @param string $source Source module.
		 * @param string $skey   The source's id of the item.
		 */
		$msg = apply_filters( 'brc_message', $msg, $source, $skey );
		if ( ! is_array( $msg ) || empty( $msg ) ) {
			return array();
		}
		$msg = self::clean_msg( $msg );
		if ( '' === $msg['text'] && '' === $msg['url'] ) {
			return array();
		}

		$s     = BRC_Settings::get();
		$now   = time();
		$delay = isset( $opts['delay'] ) ? max( 0, (int) $opts['delay'] ) : 0;
		$ids   = array();
		$t     = self::table();

		foreach ( BRC_Settings::destinations() as $dest ) {
			$dkey = sha1( $source . '|' . $skey . '|' . $dest );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE dkey = %s", $dkey ) ) ) {
				continue; // Already known: never twice.
			}

			$status = 'queued';
			$reason = '';
			if ( ! empty( $opts['reason'] ) ) {
				$status = 'pending';
				$reason = sanitize_key( (string) $opts['reason'] );
			} elseif ( BRC_Settings::needs_approval( $source ) ) {
				$status = 'pending';
				$reason = 'approval';
			}

			$url_hash = '' !== $msg['url'] && ! ( isset( $opts['same_url'] ) && false === $opts['same_url'] ) ? sha1( $dest . '|' . $msg['url'] ) : '';
			if ( '' !== $url_hash && self::url_seen( $url_hash, $now - 7 * DAY_IN_SECONDS ) ) {
				$status = 'skipped';
				$reason = 'dup_url';
			} elseif ( 'queued' === $status && self::over_brake( $source, $s, $now ) ) {
				$status = 'pending';
				$reason = 'brake';
				self::note( 'brake', $now );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
			$ok = $wpdb->insert(
				$t,
				array(
					'created'  => $now,
					'source'   => $source,
					'skey'     => $skey,
					'dest'     => $dest,
					'dkey'     => $dkey,
					'status'   => $status,
					'reason'   => $reason,
					'send_at'  => $now + $delay,
					'title'    => mb_substr( $msg['title'], 0, 255 ),
					'url_hash' => $url_hash,
					'msg'      => wp_json_encode( $msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'result'   => '',
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
			if ( $ok ) {
				$ids[] = (int) $wpdb->insert_id;
				if ( 'queued' === $status ) {
					BRC_Jobs::soon( $delay );
				}
			}
		}
		return $ids;
	}

	/** A message with every field present and plain. */
	public static function clean_msg( array $m ): array {
		$kinds = array( 'text', 'picture', 'url' );
		$out   = array(
			'title' => isset( $m['title'] ) && is_scalar( $m['title'] ) ? trim( wp_strip_all_tags( (string) $m['title'] ) ) : '',
			'text'  => isset( $m['text'] ) && is_scalar( $m['text'] ) ? BRC_Settings::plain( (string) $m['text'] ) : '',
			'url'   => isset( $m['url'] ) ? BRC_Settings::clean_url( $m['url'] ) : '',
			'image' => isset( $m['image'] ) ? BRC_Settings::clean_url( $m['image'] ) : '',
			'kind'  => isset( $m['kind'] ) && in_array( $m['kind'], $kinds, true ) ? $m['kind'] : 'text',
		);
		if ( 'picture' === $out['kind'] && '' === $out['image'] ) {
			$out['kind'] = 'text';
		}
		if ( 'url' === $out['kind'] && '' === $out['url'] ) {
			$out['kind'] = 'text';
		}
		$out['text'] = mb_substr( $out['text'], 0, 7000 );
		return $out;
	}

	private static function url_seen( string $url_hash, int $since ): bool {
		global $wpdb;
		$t    = self::table();
		$live = "'" . implode( "','", self::LIVE ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, constant list.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE url_hash = %s AND created >= %d AND status IN ({$live}) LIMIT 1", $url_hash, $since ) );
	}

	private static function over_brake( string $source, array $s, int $now ): bool {
		global $wpdb;
		$t    = self::table();
		$live = "'" . implode( "','", array( 'queued', 'sending', 'sent', 'test' ) ) . "'";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, constant list.
		$hour = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE source = %s AND created >= %d AND status IN ({$live})", $source, $now - HOUR_IN_SECONDS ) );
		$day  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE source = %s AND created >= %d AND status IN ({$live})", $source, $now - DAY_IN_SECONDS ) );
		// phpcs:enable
		return $hour >= $s['lim_hour'] || $day >= $s['lim_day'];
	}

	/** True when this source item is already in the table (any status, any destination). */
	public static function known( string $source, string $skey ): bool {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE source = %s AND skey = %s LIMIT 1", $source, mb_substr( $skey, 0, 100 ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------- */

	/** One row with its message decoded, or null. */
	public static function get( int $id ): ?array {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::row( $row ) : null;
	}

	private static function row( array $r ): array {
		$msg = json_decode( (string) $r['msg'], true );
		foreach ( array( 'id', 'created', 'send_at', 'sent_at', 'tries' ) as $k ) {
			$r[ $k ] = (int) $r[ $k ];
		}
		$r['msg'] = self::clean_msg( is_array( $msg ) ? $msg : array() );
		return $r;
	}

	/** WHERE clause and args for the history filters. */
	private static function where( array $f ): array {
		global $wpdb;
		$w = array( '1=1' );
		$a = array();
		if ( ! empty( $f['status'] ) ) {
			$w[] = 'status = %s';
			$a[] = (string) $f['status'];
		}
		if ( ! empty( $f['source'] ) ) {
			$w[] = 'source = %s';
			$a[] = (string) $f['source'];
		}
		if ( ! empty( $f['from'] ) ) {
			$w[] = 'created >= %d';
			$a[] = (int) $f['from'];
		}
		if ( ! empty( $f['to'] ) ) {
			$w[] = 'created <= %d';
			$a[] = (int) $f['to'];
		}
		if ( ! empty( $f['q'] ) ) {
			$w[] = 'title LIKE %s';
			$a[] = '%' . $wpdb->esc_like( (string) $f['q'] ) . '%';
		}
		return array( implode( ' AND ', $w ), $a );
	}

	/** Rows, newest first. */
	public static function list( array $f = array(), int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$t                = self::table();
		list( $w, $args ) = self::where( $f );
		$args[]           = max( 1, min( 5000, $limit ) );
		$args[]           = max( 0, $offset );
		$order            = ! empty( $f['oldest'] ) ? 'ASC' : 'DESC';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, placeholders in $w.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$w} ORDER BY id {$order} LIMIT %d OFFSET %d", $args ), ARRAY_A );
		return array_map( array( __CLASS__, 'row' ), is_array( $rows ) ? $rows : array() );
	}

	public static function count( array $f = array() ): int {
		global $wpdb;
		$t                = self::table();
		list( $w, $args ) = self::where( $f );
		$sql              = "SELECT COUNT(*) FROM {$t} WHERE {$w}";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- own table, placeholders in $w.
		return (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_var( $sql ) );
	}

	/* ---------------------------------------------------------------------
	 * Changes
	 * ------------------------------------------------------------------- */

	private static function update( int $id, array $data, string $only_status = '' ): bool {
		global $wpdb;
		$where = array( 'id' => $id );
		if ( '' !== $only_status ) {
			$where['status'] = $only_status;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return (bool) $wpdb->update( self::table(), $data, $where );
	}

	/**
	 * The owner approves a pending item (optionally with an edited text):
	 * it is posted now.
	 */
	public static function approve( int $id, ?string $text = null ): bool {
		$row = self::get( $id );
		if ( ! $row || 'pending' !== $row['status'] ) {
			return false;
		}
		$data = array(
			'status'  => 'queued',
			'reason'  => '',
			'send_at' => time(),
		);
		if ( null !== $text ) {
			$msg         = $row['msg'];
			$msg['text'] = $text;
			$msg         = self::clean_msg( $msg );
			if ( '' === $msg['text'] && '' === $msg['url'] ) {
				return false;
			}
			$data['msg'] = wp_json_encode( $msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		$ok = self::update( $id, $data, 'pending' );
		if ( $ok ) {
			BRC_Jobs::soon( 0 );
		}
		return $ok;
	}

	/**
	 * Replaces the message and title of a pending item, when its source
	 * has more now than when it was queued (an Instagram caption).
	 */
	public static function refill( int $id, array $msg, string $title ): bool {
		$msg = self::clean_msg( $msg );
		return self::update(
			$id,
			array(
				'msg'   => wp_json_encode( $msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'title' => mb_substr( trim( wp_strip_all_tags( $title ) ), 0, 255 ),
			),
			'pending'
		);
	}

	/** Saves an edited text of a pending item without approving it. */
	public static function edit( int $id, string $text ): bool {
		$row = self::get( $id );
		if ( ! $row || 'pending' !== $row['status'] ) {
			return false;
		}
		$msg         = $row['msg'];
		$msg['text'] = $text;
		$msg         = self::clean_msg( $msg );
		return self::update( $id, array( 'msg' => wp_json_encode( $msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), 'pending' );
	}

	public static function reject( int $id ): bool {
		return self::update(
			$id,
			array(
				'status' => 'rejected',
				'reason' => '',
			),
			'pending'
		);
	}

	/** A failed item back in the queue, now. */
	public static function retry( int $id ): bool {
		$ok = self::update(
			$id,
			array(
				'status'  => 'queued',
				'reason'  => '',
				'tries'   => 0,
				'send_at' => time(),
			),
			'failed'
		);
		if ( $ok ) {
			BRC_Jobs::soon( 0 );
		}
		return $ok;
	}

	/** Ids of queued items that are due. */
	public static function due( int $limit = 10 ): array {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'queued' AND send_at <= %d ORDER BY send_at ASC, id ASC LIMIT %d", time(), $limit ) );
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/** Seconds until the next queued item is due, or null. */
	public static function next_due(): ?int {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$at = $wpdb->get_var( "SELECT MIN(send_at) FROM {$t} WHERE status = 'queued'" );
		return null === $at ? null : max( 0, (int) $at - time() );
	}

	/** Claims a queued item for one run; false when another run has it. */
	public static function claim( int $id ): bool {
		return self::update(
			$id,
			array(
				'status'  => 'sending',
				'sent_at' => time(),
			),
			'queued'
		);
	}

	/** Items left in "sending" by a run that died get another chance. */
	public static function unstick(): void {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'queued', sent_at = 0 WHERE status = 'sending' AND sent_at < %d", time() - 15 * MINUTE_IN_SECONDS ) );
	}

	/** The end of a send attempt. */
	public static function finish( int $id, string $status, string $result, string $reason = '', int $retry_in = 0 ): void {
		$row  = self::get( $id );
		$data = array(
			'status' => $status,
			'reason' => $reason,
			'result' => mb_substr( BRC_Crypt::mask( $result ), 0, 1000 ),
			'tries'  => $row ? min( 255, $row['tries'] + 1 ) : 1,
		);
		// While sending, sent_at holds the claim time (unstick()); it stays only on a post.
		$data['sent_at'] = in_array( $status, array( 'sent', 'test' ), true ) ? time() : 0;
		if ( 'queued' === $status ) {
			$data['send_at'] = time() + max( 60, $retry_in );
		}
		self::update( $id, $data );
	}

	/** Deletes final rows older than the retention. */
	public static function cleanup( int $days ): int {
		global $wpdb;
		$t    = self::table();
		$done = "'" . implode( "','", self::DONE ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, constant list.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created < %d AND status IN ({$done})", time() - $days * DAY_IN_SECONDS ) );
	}

	/** Deletes every final row (Tools → clear the history). */
	public static function clear_history(): int {
		global $wpdb;
		$t    = self::table();
		$done = "'" . implode( "','", self::DONE ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, constant list.
		return (int) $wpdb->query( "DELETE FROM {$t} WHERE status IN ({$done})" );
	}
}

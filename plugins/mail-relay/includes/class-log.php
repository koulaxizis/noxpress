<?php
/**
 * MRL_Log — the sent log, table {prefix}mrl_log.
 *
 * One row per wp_mail() call: time, status (sent | failed | held), which
 * connection sent it (primary | backup | none), recipients (column
 * to_email, so Site Bridge masks it), subject, source (plugin / theme /
 * WordPress / woocommerce:<email id>), attachment names and sizes (never
 * their content), the scrubbed error, whether the mail was sensitive.
 *
 * Content (body): only when the «bodies» setting is on, only for mails
 * that are not sensitive, sealed with MRL_Crypt (so neither a database
 * copy nor Site Bridge can read it) and deleted after 7 days. Without
 * sealing (no salts / OpenSSL) no content is kept at all.
 *
 * Rows older than the retention (1–90 days, default 30) are deleted by the
 * daily job. The WordPress privacy tools (export / erase personal data)
 * find and delete the rows of an email address.
 */

defined( 'ABSPATH' ) || exit;

final class MRL_Log {

	const DB_VER    = '1';
	const BODY_DAYS = 7;
	const MAX_TEXT  = 65000;

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
	}

	/** Suggested text for the privacy policy (Settings → Privacy → Policy guide). */
	public static function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p>' . esc_html__( 'Τα email που στέλνει το site (παραγγελίες, λογαριασμός, φόρμες) φεύγουν μέσω του παρόχου email που έχουμε ορίσει. Για κάθε email κρατάμε σε ημερολόγιο τον παραλήπτη, το θέμα, την ώρα και αν στάλθηκε, για όσες ημέρες ορίζει ο διαχειριστής (30 από προεπιλογή), ώστε να ελέγχουμε ότι τα μηνύματα φτάνουν.', 'mail-relay' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Αν ο διαχειριστής το ενεργοποιήσει, κρατάμε και το περιεχόμενο των email για 7 ημέρες, κρυπτογραφημένο, για επανάληψη αποστολής. Email με κωδικούς ή συνδέσμους σύνδεσης δεν αποθηκεύονται ποτέ με το περιεχόμενό τους.', 'mail-relay' ) . '</p>';
		wp_add_privacy_policy_content( 'Mail Relay', wp_kses_post( $text ) );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mrl_log';
	}

	public static function install(): void {
		if ( self::DB_VER === get_option( 'mrl_db' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created int(10) unsigned NOT NULL DEFAULT 0,
  status varchar(10) NOT NULL DEFAULT '',
  conn varchar(10) NOT NULL DEFAULT '',
  to_email text NOT NULL,
  subject varchar(255) NOT NULL DEFAULT '',
  source varchar(100) NOT NULL DEFAULT '',
  attach text NULL,
  error text NULL,
  delicate tinyint(1) unsigned NOT NULL DEFAULT 0,
  body longtext NULL,
  body_until int(10) unsigned NOT NULL DEFAULT 0,
  resent_of bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY created (created),
  KEY status (status)
) {$charset};"
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- checks our own table.
		if ( $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) ) {
			update_option( 'mrl_db', self::DB_VER, false );
		}
	}

	private static function ready(): bool {
		if ( self::DB_VER !== get_option( 'mrl_db' ) ) {
			self::install();
		}
		return self::DB_VER === get_option( 'mrl_db' );
	}

	/** Lower-case, unique, valid addresses. */
	public static function addresses( array $list ): array {
		$out = array();
		foreach ( $list as $a ) {
			$a = strtolower( trim( (string) $a ) );
			if ( preg_match( '/<([^>]+)>/', $a, $m ) ) {
				$a = trim( $m[1] );
			}
			if ( '' !== $a && is_email( $a ) ) {
				$out[ $a ] = true;
			}
		}
		return array_keys( $out );
	}

	private static function cut( string $s, int $max ): string {
		$s = wp_check_invalid_utf8( $s, true );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	/**
	 * New row. $r: to (string[]), subject, source, attach (array of
	 * [name, size]), sensitive (bool), body (array|null: to, subject,
	 * message, headers), resent_of.
	 */
	public static function add( array $r ): int {
		if ( ! self::ready() ) {
			return 0;
		}
		global $wpdb;
		$body  = null;
		$until = 0;
		if ( isset( $r['body'] ) && is_array( $r['body'] ) && empty( $r['sensitive'] ) ) {
			$sealed = MRL_Crypt::seal( (string) wp_json_encode( $r['body'] ) );
			if ( '' !== $sealed ) {
				$body  = $sealed;
				$until = time() + self::BODY_DAYS * DAY_IN_SECONDS;
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
		$ok = $wpdb->insert(
			self::table(),
			array(
				'created'    => time(),
				'status'     => 'sending',
				'conn'       => '',
				'to_email'   => self::cut( implode( ', ', isset( $r['to'] ) ? (array) $r['to'] : array() ), self::MAX_TEXT ),
				'subject'    => self::cut( isset( $r['subject'] ) ? (string) $r['subject'] : '', 255 ),
				'source'     => self::cut( isset( $r['source'] ) ? (string) $r['source'] : '', 100 ),
				'attach'     => isset( $r['attach'] ) && $r['attach'] ? (string) wp_json_encode( $r['attach'] ) : null,
				'error'      => null,
				'delicate'   => empty( $r['sensitive'] ) ? 0 : 1,
				'body'       => $body,
				'body_until' => $until,
				'resent_of'  => isset( $r['resent_of'] ) ? (int) $r['resent_of'] : 0,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** Final status of a row: sent | failed | held, the connection, the scrubbed error. */
	public static function finish( int $id, string $status, string $conn, string $error = '' ): void {
		if ( $id <= 0 ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table.
		$wpdb->update(
			self::table(),
			array(
				'status' => $status,
				'conn'   => $conn,
				'error'  => '' === $error ? null : self::cut( $error, 2000 ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function get( int $id ): ?array {
		if ( ! self::ready() ) {
			return null;
		}
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? self::row( $row ) : null;
	}

	private static function row( array $r ): array {
		$att = isset( $r['attach'] ) && is_string( $r['attach'] ) ? json_decode( $r['attach'], true ) : array();
		return array(
			'id'        => (int) $r['id'],
			'created'   => (int) $r['created'],
			'status'    => (string) $r['status'],
			'conn'      => (string) $r['conn'],
			'to'        => (string) $r['to_email'],
			'subject'   => (string) $r['subject'],
			'source'    => (string) $r['source'],
			'attach'    => is_array( $att ) ? $att : array(),
			'error'     => (string) $r['error'],
			'sensitive' => (bool) $r['delicate'],
			'has_body'  => ! empty( $r['body'] ) && (int) $r['body_until'] > time(),
			'resent_of' => (int) $r['resent_of'],
		);
	}

	/** The opened body of a row (to, subject, message, headers), or null. */
	public static function body( int $id ): ?array {
		if ( ! self::ready() ) {
			return null;
		}
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT body, body_until, delicate FROM {$t} WHERE id = %d", $id ), ARRAY_A );
		if ( ! is_array( $row ) || empty( $row['body'] ) || (int) $row['delicate'] || (int) $row['body_until'] <= time() ) {
			return null;
		}
		$plain = MRL_Crypt::unseal( (string) $row['body'] );
		$b     = null === $plain ? null : json_decode( $plain, true );
		return is_array( $b ) ? $b : null;
	}

	/**
	 * Rows, newest first. $a: status ('' | sent | failed | held),
	 * search (recipient or subject), days (0 = all), limit, offset.
	 *
	 * @return array{rows: array, total: int}
	 */
	public static function query( array $a ): array {
		if ( ! self::ready() ) {
			return array(
				'rows'  => array(),
				'total' => 0,
			);
		}
		global $wpdb;
		$t      = self::table();
		$where  = array( '1=1' );
		$params = array();
		$status = isset( $a['status'] ) ? (string) $a['status'] : '';
		if ( in_array( $status, array( 'sent', 'failed', 'held', 'sending' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		$search = isset( $a['search'] ) ? trim( (string) $a['search'] ) : '';
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( strtolower( $search ) ) . '%';
			$where[]  = '(to_email LIKE %s OR LOWER(subject) LIKE %s OR source LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$days = isset( $a['days'] ) ? (int) $a['days'] : 0;
		if ( $days > 0 ) {
			$where[]  = 'created >= %d';
			$params[] = time() - $days * DAY_IN_SECONDS;
		}
		$limit  = isset( $a['limit'] ) ? max( 1, min( 1000, (int) $a['limit'] ) ) : 50;
		$offset = isset( $a['offset'] ) ? max( 0, (int) $a['offset'] ) : 0;
		$w      = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- our own table, placeholders built above.
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$w}", $params ) : "SELECT COUNT(*) FROM {$t} WHERE {$w}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, created, status, conn, to_email, subject, source, attach, error, delicate, (body IS NOT NULL) AS body, body_until, resent_of FROM {$t} WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $limit, $offset ) ) ),
			ARRAY_A
		);
		// phpcs:enable
		return array(
			'rows'  => array_map( array( __CLASS__, 'row' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/** Counts by status since a time. */
	public static function counts( int $since ): array {
		$out = array(
			'sent'   => 0,
			'failed' => 0,
			'held'   => 0,
			'backup' => 0,
		);
		if ( ! self::ready() ) {
			return $out;
		}
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, conn, COUNT(*) AS n FROM {$t} WHERE created >= %d GROUP BY status, conn", $since ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			if ( isset( $out[ $r['status'] ] ) ) {
				$out[ $r['status'] ] += (int) $r['n'];
			}
			if ( 'sent' === $r['status'] && 'backup' === $r['conn'] ) {
				$out['backup'] += (int) $r['n'];
			}
		}
		return $out;
	}

	/** Daily job: rows past retention, bodies past 7 days. */
	public static function cleanup(): void {
		if ( ! self::ready() ) {
			return;
		}
		global $wpdb;
		$t    = self::table();
		$days = MRL_Settings::get()['days'];
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created < %d", time() - $days * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET body = NULL, body_until = 0 WHERE body IS NOT NULL AND body_until < %d", time() ) );
		// A row still «sending» after a day was cut off (fatal error, timeout).
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'failed', error = %s WHERE status = 'sending' AND created < %d", 'Interrupted before the result was known.', time() - DAY_IN_SECONDS ) );
		// phpcs:enable
	}

	/** Bodies off: delete every kept body now. */
	public static function drop_bodies(): void {
		if ( ! self::ready() ) {
			return;
		}
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
		$wpdb->query( "UPDATE {$t} SET body = NULL, body_until = 0 WHERE body IS NOT NULL" );
	}

	public static function clear(): int {
		if ( ! self::ready() ) {
			return 0;
		}
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
		$n = (int) $wpdb->query( "DELETE FROM {$t}" );
		return $n;
	}

	/* ---------------------------------------------------------------------
	 * Masking (Site Bridge, abilities)
	 * ------------------------------------------------------------------- */

	/** c***@g***.com */
	public static function mask_email( string $e ): string {
		$e = trim( $e );
		$p = strrpos( $e, '@' );
		if ( false === $p ) {
			return '***';
		}
		$local = substr( $e, 0, $p );
		$dom   = substr( $e, $p + 1 );
		$dot   = strrpos( $dom, '.' );
		$tld   = false === $dot ? '' : substr( $dom, $dot );
		return substr( $local, 0, 1 ) . '***@' . substr( $dom, 0, 1 ) . '***' . $tld;
	}

	/** Masks every email address inside a text. */
	public static function mask_text( string $s ): string {
		return (string) preg_replace_callback(
			'/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
			static function ( $m ) {
				return self::mask_email( $m[0] );
			},
			$s
		);
	}

	/* ---------------------------------------------------------------------
	 * Privacy tools (Tools → Export / Erase Personal Data)
	 * ------------------------------------------------------------------- */

	public static function register_exporter( $list ) {
		$list   = is_array( $list ) ? $list : array();
		$list[] = array(
			'exporter_friendly_name' => __( 'Mail Relay: αρχείο αποστολών', 'mail-relay' ),
			'callback'               => array( __CLASS__, 'export_personal' ),
		);
		return $list;
	}

	public static function register_eraser( $list ) {
		$list   = is_array( $list ) ? $list : array();
		$list[] = array(
			'eraser_friendly_name' => __( 'Mail Relay: αρχείο αποστολών', 'mail-relay' ),
			'callback'             => array( __CLASS__, 'erase_personal' ),
		);
		return $list;
	}

	/** Row ids whose recipients include this address (exact match inside the list). */
	private static function ids_for( string $email, int $limit, int $offset ): array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email || ! is_email( $email ) || ! self::ready() ) {
			return array();
		}
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, to_email FROM {$t} WHERE to_email LIKE %s ORDER BY id LIMIT %d OFFSET %d", '%' . $wpdb->esc_like( $email ) . '%', $limit, $offset ), ARRAY_A );
		$ids  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			if ( in_array( $email, array_map( 'trim', explode( ',', (string) $r['to_email'] ) ), true ) ) {
				$ids[] = (int) $r['id'];
			}
		}
		return $ids;
	}

	public static function export_personal( $email, $page = 1 ) {
		$page  = max( 1, (int) $page );
		$per   = 200;
		$items = array();
		foreach ( self::ids_for( (string) $email, $per, ( $page - 1 ) * $per ) as $id ) {
			$r = self::get( $id );
			if ( ! $r ) {
				continue;
			}
			$items[] = array(
				'group_id'    => 'mail-relay',
				'group_label' => __( 'Email που στάλθηκαν', 'mail-relay' ),
				'item_id'     => 'mrl-' . $r['id'],
				'data'        => array(
					array(
						'name'  => __( 'Ημερομηνία', 'mail-relay' ),
						'value' => wp_date( 'Y-m-d H:i', $r['created'] ),
					),
					array(
						'name'  => __( 'Θέμα', 'mail-relay' ),
						'value' => $r['subject'],
					),
					array(
						'name'  => __( 'Κατάσταση', 'mail-relay' ),
						'value' => $r['status'],
					),
				),
			);
		}
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table.
		$more = self::ready() ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE to_email LIKE %s", '%' . $wpdb->esc_like( strtolower( (string) $email ) ) . '%' ) ) > $page * $per : false;
		return array(
			'data' => $items,
			'done' => ! $more,
		);
	}

	public static function erase_personal( $email, $page = 1 ) {
		$ids = self::ids_for( (string) $email, 500, 0 );
		$n   = 0;
		if ( $ids ) {
			global $wpdb;
			$t  = self::table();
			$in = implode( ',', array_map( 'intval', $ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own table, integer ids.
			$n = (int) $wpdb->query( "DELETE FROM {$t} WHERE id IN ({$in})" );
		}
		return array(
			'items_removed'  => $n > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $ids ) < 500,
		);
	}

	/* ---------------------------------------------------------------------
	 * CSV (metadata only)
	 * ------------------------------------------------------------------- */

	public static function csv( $fh, array $filter ): void {
		$row = array( 'id', 'date', 'status', 'connection', 'to', 'subject', 'source', 'attachments', 'error' );
		self::csv_row( $fh, $row );
		$offset = 0;
		do {
			$q = self::query( array_merge( $filter, array( 'limit' => 500, 'offset' => $offset ) ) );
			foreach ( $q['rows'] as $r ) {
				$names = array();
				foreach ( $r['attach'] as $a ) {
					if ( is_array( $a ) && isset( $a[0] ) ) {
						$names[] = (string) $a[0];
					}
				}
				self::csv_row( $fh, array( $r['id'], wp_date( 'Y-m-d H:i:s', $r['created'] ), $r['status'], $r['conn'], $r['to'], $r['subject'], $r['source'], implode( ' | ', $names ), $r['error'] ) );
			}
			$offset += 500;
		} while ( count( $q['rows'] ) === 500 && $offset < 100000 );
	}

	private static function csv_row( $fh, array $row ): void {
		if ( class_exists( 'Noxpress_Safe' ) && method_exists( 'Noxpress_Safe', 'csv_row' ) ) {
			Noxpress_Safe::csv_row( $fh, $row );
			return;
		}
		$cells = array();
		foreach ( $row as $c ) {
			$c       = (string) $c;
			$cells[] = ( '' !== $c && ! is_numeric( $c ) && false !== strpos( "=+-@\t\r", $c[0] ) ) ? "'" . $c : $c;
		}
		Noxpress_Safe::csv_row( $fh, $cells, ',' );
	}
}

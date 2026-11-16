<?php
/**
 * GFC_Schema — our three tables (Bible §12).
 *
 *  - {prefix}gfc_cards   one row per card. Amounts in cents (BIGINT). The
 *                        code exists only as code_hmac (NULL until the card
 *                        is delivered: the code is created at delivery).
 *                        issue_key is unique: one card per order line and
 *                        unit (o:order:item:seq) or per manual issue.
 *  - {prefix}gfc_ledger  append-only movements (issue, redeem, restore,
 *                        refund, adjust, expire, void). The code never
 *                        updates or deletes a row. uniq (nullable, unique)
 *                        makes a refund or a one-off movement idempotent.
 *  - {prefix}gfc_limits  attempt counters (key = sha256, window start).
 *
 * The schema version lives in gfc_db_version (autoload); init() runs
 * dbDelta once per version, behind a short transient lock, so an update
 * without reactivation migrates before any query.
 */

defined( 'ABSPATH' ) || exit;

final class GFC_Schema {

	const VERSION = '1';
	const OPT     = 'gfc_db_version';

	public static function init(): void {
		if ( self::VERSION !== get_option( self::OPT ) && ! get_transient( 'gfc_db_lock' ) ) {
			set_transient( 'gfc_db_lock', 1, 60 );
			self::install();
			delete_transient( 'gfc_db_lock' );
		}
	}

	public static function cards(): string {
		global $wpdb;
		return $wpdb->prefix . 'gfc_cards';
	}

	public static function ledger(): string {
		global $wpdb;
		return $wpdb->prefix . 'gfc_ledger';
	}

	public static function limits(): string {
		global $wpdb;
		return $wpdb->prefix . 'gfc_limits';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::cards() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  issue_key varchar(64) NOT NULL,
  code_hmac char(64) DEFAULT NULL,
  last4 char(4) NOT NULL DEFAULT '',
  initial bigint(20) NOT NULL DEFAULT 0,
  balance bigint(20) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  status varchar(12) NOT NULL DEFAULT 'pending',
  source varchar(10) NOT NULL DEFAULT 'order',
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  item_id bigint(20) unsigned NOT NULL DEFAULT 0,
  customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
  buyer_email varchar(100) NOT NULL DEFAULT '',
  for_me tinyint(1) NOT NULL DEFAULT 0,
  recipient_name varchar(100) NOT NULL DEFAULT '',
  recipient_email varchar(100) NOT NULL DEFAULT '',
  sender_name varchar(100) NOT NULL DEFAULT '',
  message text NULL,
  lang char(2) NOT NULL DEFAULT 'el',
  deliver_at datetime DEFAULT NULL,
  delivered_at datetime DEFAULT NULL,
  expires_at datetime DEFAULT NULL,
  reminded tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY issue_key (issue_key),
  UNIQUE KEY code_hmac (code_hmac),
  KEY status (status),
  KEY order_id (order_id),
  KEY customer_id (customer_id),
  KEY recipient_email (recipient_email)
) $c;"
		);

		dbDelta(
			'CREATE TABLE ' . self::ledger() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  card_id bigint(20) unsigned NOT NULL,
  delta bigint(20) NOT NULL,
  balance_after bigint(20) NOT NULL,
  type varchar(10) NOT NULL,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  refund_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  note varchar(255) NOT NULL DEFAULT '',
  uniq varchar(80) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq (uniq),
  KEY card_id (card_id),
  KEY order_id (order_id)
) $c;"
		);

		dbDelta(
			'CREATE TABLE ' . self::limits() . " (
  k char(64) NOT NULL,
  n int(11) NOT NULL DEFAULT 0,
  w int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (k)
) $c;"
		);

		update_option( self::OPT, self::VERSION, true );
	}
}

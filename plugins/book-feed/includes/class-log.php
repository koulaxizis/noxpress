<?php
/**
 * BKF_Log — history of imports and syncs.
 *
 * The bkf_log option (autoload off) keeps the newest 500 entries:
 * { t (Unix), act, pid, isbn, title, fields (comma list), msg }.
 * Acts: import, update, link, unlink, gone, back, locked, error, run.
 * Entries are collected during a request and written once at the end.
 * Nothing secret goes in: messages from the service are redacted by
 * BKF_Client before they get here.
 */

defined( 'ABSPATH' ) || exit;

final class BKF_Log {

	const OPTION = 'bkf_log';
	const MAX    = 500;

	const ACTS = array( 'import', 'update', 'link', 'unlink', 'gone', 'back', 'locked', 'error', 'run' );

	private static $pending = array();
	private static $hooked  = false;

	public static function add( string $act, int $pid = 0, string $isbn = '', string $title = '', array $fields = array(), string $msg = '' ): void {
		if ( ! in_array( $act, self::ACTS, true ) ) {
			return;
		}
		self::$pending[] = array(
			't'      => time(),
			'act'    => $act,
			'pid'    => $pid,
			'isbn'   => BKF_Settings::text( $isbn, 30 ),
			'title'  => BKF_Settings::text( $title, 200 ),
			'fields' => implode( ',', array_map( 'sanitize_key', $fields ) ),
			'msg'    => BKF_Settings::text( $msg, 300 ),
		);
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		}
	}

	/** Writes the collected entries (newest first). */
	public static function flush(): void {
		if ( ! self::$pending ) {
			return;
		}
		$all           = array_merge( array_reverse( self::$pending ), self::all() );
		self::$pending = array();
		update_option( self::OPTION, array_slice( $all, 0, self::MAX ), false );
	}

	/** Entries, newest first. */
	public static function all(): array {
		$v   = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $e ) {
			if ( is_array( $e ) && isset( $e['t'], $e['act'] ) && in_array( $e['act'], self::ACTS, true ) ) {
				$out[] = array(
					't'      => (int) $e['t'],
					'act'    => (string) $e['act'],
					'pid'    => (int) ( $e['pid'] ?? 0 ),
					'isbn'   => (string) ( $e['isbn'] ?? '' ),
					'title'  => (string) ( $e['title'] ?? '' ),
					'fields' => (string) ( $e['fields'] ?? '' ),
					'msg'    => (string) ( $e['msg'] ?? '' ),
				);
			}
		}
		return $out;
	}

	public static function clear(): void {
		self::$pending = array();
		delete_option( self::OPTION );
	}

	/** Label msgid of an act. */
	public static function act_label( string $act ): string {
		$map = array(
			'import' => __( 'Εισαγωγή', 'book-feed' ),
			'update' => __( 'Ενημέρωση', 'book-feed' ),
			'link'   => __( 'Σύνδεση', 'book-feed' ),
			'unlink' => __( 'Αποσύνδεση', 'book-feed' ),
			'gone'   => __( 'Εκτός καταλόγου', 'book-feed' ),
			'back'   => __( 'Ξανά στον κατάλογο', 'book-feed' ),
			'locked' => __( 'Κλειδωμένα πεδία', 'book-feed' ),
			'error'  => __( 'Σφάλμα', 'book-feed' ),
			'run'    => __( 'Συγχρονισμός', 'book-feed' ),
		);
		return isset( $map[ $act ] ) ? $map[ $act ] : $act;
	}

	/** Label msgid of a synced field. */
	public static function field_label( string $f ): string {
		$map = array(
			'title' => __( 'Τίτλος', 'book-feed' ),
			'desc'  => __( 'Περιγραφή', 'book-feed' ),
			'short' => __( 'Σύντομη περιγραφή', 'book-feed' ),
			'price' => __( 'Τιμή', 'book-feed' ),
			'stock' => __( 'Διαθεσιμότητα', 'book-feed' ),
			'image' => __( 'Εξώφυλλο', 'book-feed' ),
			'attrs' => __( 'Χαρακτηριστικά', 'book-feed' ),
			'dims'  => __( 'Βάρος και διαστάσεις', 'book-feed' ),
			'tax'   => __( 'Κλάση ΦΠΑ', 'book-feed' ),
		);
		return isset( $map[ $f ] ) ? $map[ $f ] : $f;
	}

	/** "Τιμή, Διαθεσιμότητα" from "price,stock". */
	public static function fields_text( string $fields ): string {
		$out = array();
		foreach ( array_filter( explode( ',', $fields ) ) as $f ) {
			$out[] = self::field_label( $f );
		}
		return implode( ', ', $out );
	}
}

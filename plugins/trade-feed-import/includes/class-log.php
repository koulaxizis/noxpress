<?php
/**
 * TFI_Log — history of imports and syncs (option tfi_log, autoload off,
 * newest first, last 300 entries). No credentials, no personal data:
 * product id, ISBN, title, what changed.
 *
 * Kinds: import, link, unlink, update, locked, gone, back, error, sync.
 */

defined( 'ABSPATH' ) || exit;

final class TFI_Log {

	const OPT = 'tfi_log';
	const MAX = 300;

	public static function add( string $kind, int $pid, string $isbn, string $title, array $fields = array(), string $note = '' ): void {
		$log = self::all();
		array_unshift(
			$log,
			array(
				't' => time(),
				'k' => sanitize_key( $kind ),
				'p' => $pid,
				'i' => mb_substr( $isbn, 0, 20 ),
				'n' => mb_substr( $title, 0, 160 ),
				'f' => array_slice( array_map( 'sanitize_key', $fields ), 0, 12 ),
				'm' => mb_substr( $note, 0, 300 ),
			)
		);
		update_option( self::OPT, array_slice( $log, 0, self::MAX ), false );
	}

	public static function all(): array {
		$log = get_option( self::OPT, array() );
		return is_array( $log ) ? array_values( array_filter( $log, 'is_array' ) ) : array();
	}

	public static function clear(): void {
		delete_option( self::OPT );
	}
}

<?php
/**
 * WSH_Ajax — the two wc-ajax endpoints of front.js.
 *
 *  - wsh_sync   (POST) a signed-in customer's own list: add, remove, clear,
 *               merge (the browser's list after sign-in), share (new / off,
 *               per list). The heart works on every list of the customer:
 *               add puts the product in the main list, remove takes it out
 *               of the list it is in.
 *               Nonce wsh_sync, whitelist of operations and ids, limits on
 *               items and requests (Bible §14.28).
 *  - wsh_render (GET)  the HTML of a guest's list from the ids in the
 *               request. Read only: writes nothing, sets no cookie.
 *
 * Both answer only when the wishlist is visible to the requester (mode,
 * test mode, WSH_DISABLE).
 */

defined( 'ABSPATH' ) || exit;

final class WSH_Ajax {

	public static function init(): void {
		add_action( 'wc_ajax_wsh_sync', array( __CLASS__, 'sync' ) );
		add_action( 'wc_ajax_wsh_render', array( __CLASS__, 'render' ) );
	}

	/** The customer's list as [[p, v], …]. */
	private static function list_pairs( int $uid ): array {
		$out = array();
		foreach ( WSH_Store::items( $uid ) as $it ) {
			$out[] = array( $it['p'], $it['v'] );
		}
		return $out;
	}

	/**
	 * A posted scalar, unslashed (callers validate).
	 *
	 * @param string $key Field.
	 */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in sync().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	public static function sync(): void {
		try {
			nocache_headers();
			$uid = get_current_user_id();
			if ( ! $uid || ! WSH_Front::active() || ! WSH_Store::ready() ) {
				wp_send_json_error( array( 'code' => 'off' ), 403 );
			}
			if ( ! check_ajax_referer( 'wsh_sync', 'nonce', false ) ) {
				wp_send_json_error( array( 'code' => 'nonce' ), 403 );
			}
			if ( ! WSH_Store::rate_ok( $uid ) ) {
				wp_send_json_error( array( 'code' => 'busy' ), 429 );
			}
			$op     = sanitize_key( self::post( 'op' ) );
			$result = '';
			$share  = null;
			switch ( $op ) {
				case 'add':
				case 'remove':
					$p = (int) self::post( 'p' );
					$v = (int) self::post( 'v' );
					if ( $p <= 0 || $v < 0 ) {
						wp_send_json_error( array( 'code' => 'invalid' ), 400 );
					}
					$result = 'add' === $op ? WSH_Store::add( $uid, $p, $v ) : ( WSH_Store::remove( $uid, $p, $v ) ? 'removed' : 'missing' );
					break;
				case 'clear':
					WSH_Store::clear( $uid );
					$result = 'cleared';
					break;
				case 'merge':
					$raw   = json_decode( self::post( 'items' ), true );
					$added = 0;
					if ( is_array( $raw ) ) {
						foreach ( array_slice( $raw, 0, WSH_Store::max_items() ) as $row ) {
							if ( ! is_array( $row ) || ! isset( $row[0] ) || ! is_numeric( $row[0] ) ) {
								continue;
							}
							$p = (int) $row[0];
							$v = isset( $row[1] ) && is_numeric( $row[1] ) ? (int) $row[1] : 0;
							$t = isset( $row[2] ) && is_numeric( $row[2] ) ? (int) $row[2] : 0;
							if ( 'added' === WSH_Store::add( $uid, $p, $v, $t ) ) {
								++$added;
							}
						}
					}
					$result = (string) $added;
					break;
				case 'share':
					$list = WSH_List::lists_on() ? absint( self::post( 'list' ) ) : 0;
					if ( ! WSH_Settings::get()['share'] || ! WSH_Store::owns_list( $uid, $list ) ) {
						wp_send_json_error( array( 'code' => 'off' ), 403 );
					}
					if ( 'off' === self::post( 'to' ) ) {
						WSH_Store::token_off( $uid, $list );
						$share = '';
					} else {
						$share = WSH_List::share_url( WSH_Store::new_token( $uid, $list ) );
					}
					$result = 'share';
					break;
				default:
					wp_send_json_error( array( 'code' => 'invalid' ), 400 );
			}
			$out = array(
				'result' => $result,
				'items'  => self::list_pairs( $uid ),
			);
			if ( null !== $share ) {
				$out['share'] = $share;
			}
			wp_send_json_success( $out );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'code' => 'error' ), 500 );
		}
	}

	public static function render(): void {
		try {
			if ( ! WSH_Front::active() ) {
				wp_send_json_error( array( 'code' => 'off' ), 403 );
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render of public products.
			$raw   = isset( $_GET['ids'] ) && is_string( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : '';
			$pairs = WSH_List::parse_keys( $raw );
			wp_send_json_success( array( 'html' => WSH_List::render( WSH_List::rows_from_keys( $pairs ), 'guest' ) ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'code' => 'error' ), 500 );
		}
	}
}

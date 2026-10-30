<?php
/**
 * TFI_Client — the publisher's Trade Feed (Trade Accounts, REST tra/v1).
 *
 * HTTPS only, HTTP Basic with the username and the Application Password
 * of the settings, JSON. Calls used:
 *  - GET /account     who we are at the publisher: discount, currency
 *  - GET /products    a page of titles (per_page up to 100)
 *  - GET /categories  the publisher's eligible categories
 *
 * A 429 answer is kept as "wait until" (tfi_wait, the Retry-After of the
 * publisher) so no call is made before then. Errors never contain the
 * password: the publisher's message is shortened and passed through
 * sanitize_text_field, and the Authorization header is never logged.
 */

defined( 'ABSPATH' ) || exit;

final class TFI_Client {

	const NS      = '/wp-json/tra/v1';
	const TIMEOUT = 25;
	const PER     = 100;
	const WAIT    = 'tfi_wait';

	/** GET /account. */
	public static function account() {
		return self::get( '/account' );
	}

	/** GET /categories: the items list. */
	public static function categories() {
		$r = self::get( '/categories' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return isset( $r['items'] ) && is_array( $r['items'] ) ? $r['items'] : array();
	}

	/**
	 * GET /products: one page.
	 *
	 * @param int   $page  Page (1-based).
	 * @param array $query Extra filters of the feed (isbn, search, category, availability).
	 * @return array|WP_Error { items: array, total: int, total_pages: int }
	 */
	public static function products( int $page, array $query = array() ) {
		$allowed = array_intersect_key( $query, array_flip( array( 'isbn', 'search', 'category', 'availability', 'modified_since' ) ) );
		$args    = array_merge(
			array_map( 'strval', $allowed ),
			array(
				'page'     => max( 1, $page ),
				'per_page' => self::PER,
				'orderby'  => 'id',
				'order'    => 'asc',
			)
		);
		$r = self::get( '/products', $args );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return array(
			'items'       => isset( $r['items'] ) && is_array( $r['items'] ) ? $r['items'] : array(),
			'total'       => isset( $r['total'] ) ? (int) $r['total'] : 0,
			'total_pages' => isset( $r['total_pages'] ) ? (int) $r['total_pages'] : 0,
		);
	}

	/** Seconds still to wait after a 429 (0 = none). */
	public static function waiting(): int {
		$until = (int) get_option( self::WAIT, 0 );
		return max( 0, $until - time() );
	}

	/**
	 * One GET to the feed, the decoded JSON, or WP_Error.
	 *
	 * @param string $route Route under tra/v1, with leading slash.
	 * @param array  $args  Query arguments.
	 */
	private static function get( string $route, array $args = array() ) {
		if ( Trade_Feed_Import::killed() ) {
			return new WP_Error( 'tfi_off', __( 'Το TFI_DISABLE είναι ενεργό στο wp-config.php: καμία κλήση στο Trade Feed.', 'trade-feed-import' ) );
		}
		$s  = TFI_Settings::get();
		$pw = TFI_Settings::password();
		if ( null === $pw ) {
			return new WP_Error( 'tfi_seal', __( 'Ο αποθηκευμένος κωδικός δεν διαβάζεται πια (άλλαξαν τα κλειδιά του wp-config.php). Γράψε ξανά τον κωδικό στη Σύνδεση.', 'trade-feed-import' ) );
		}
		if ( '' === $s['url'] || '' === $s['user'] || '' === $pw ) {
			return new WP_Error( 'tfi_setup', __( 'Συμπλήρωσε πρώτα τη διεύθυνση του εκδότη, το όνομα χρήστη και τον κωδικό στη Σύνδεση.', 'trade-feed-import' ) );
		}
		$wait = self::waiting();
		if ( $wait > 0 ) {
			/* translators: %d: seconds */
			return new WP_Error( 'tfi_rate', sprintf( __( 'Ο εκδότης ζήτησε παύση στις κλήσεις (όριο κλήσεων). Ξαναδοκίμασε σε %d δευτερόλεπτα.', 'trade-feed-import' ), $wait ) );
		}

		$url = add_query_arg( array_map( 'rawurlencode', $args ), $s['url'] . self::NS . $route );
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Basic ' . base64_encode( $s['user'] . ':' . $pw ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- HTTP Basic.
					'Accept'        => 'application/json',
				),
				'user-agent'  => 'Trade Feed Import/' . TFI_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			/* translators: %s: error message */
			return new WP_Error( 'tfi_http', sprintf( __( 'Ο εκδότης δεν απάντησε: %s', 'trade-feed-import' ), self::clean( $res->get_error_message() ) ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );

		if ( 429 === $code ) {
			$after = (int) wp_remote_retrieve_header( $res, 'retry-after' );
			update_option( self::WAIT, time() + max( 60, min( 3 * HOUR_IN_SECONDS, $after ) ), false );
			return new WP_Error( 'tfi_rate', __( 'Ο εκδότης ζήτησε παύση στις κλήσεις (όριο κλήσεων). Ο συγχρονισμός συνεχίζει αργότερα μόνος του.', 'trade-feed-import' ) );
		}
		if ( $code >= 300 && $code < 400 ) {
			$to = (string) wp_parse_url( (string) wp_remote_retrieve_header( $res, 'location' ), PHP_URL_HOST );
			/* translators: %s: the host the address leads to */
			return new WP_Error( 'tfi_redirect', sprintf( __( 'Η διεύθυνση του εκδότη οδηγεί αλλού (%s). Γράψε τη διεύθυνση όπως είναι στο email.', 'trade-feed-import' ), '' !== $to ? self::clean( $to ) : '?' ) );
		}
		if ( 401 === $code ) {
			return new WP_Error( 'tfi_auth', __( 'Ο εκδότης δεν δέχτηκε το όνομα χρήστη ή τον κωδικό. Έλεγξε ότι αντέγραψες τον κωδικό του Trade Feed όπως ήρθε στο email (με τα κενά ή χωρίς).', 'trade-feed-import' ) );
		}
		if ( 403 === $code ) {
			$msg = is_array( $body ) && isset( $body['message'] ) ? self::clean( (string) $body['message'] ) : '';
			/* translators: %s: the publisher's message */
			return new WP_Error( 'tfi_forbidden', sprintf( __( 'Ο εκδότης δεν επιτρέπει την κλήση: %s', 'trade-feed-import' ), '' !== $msg ? $msg : '403' ) );
		}
		if ( 404 === $code && is_array( $body ) && isset( $body['code'] ) && 'rest_no_route' === $body['code'] ) {
			return new WP_Error( 'tfi_no_feed', __( 'Σε αυτή τη διεύθυνση δεν υπάρχει Trade Feed (δεν βρέθηκε το tra/v1). Έλεγξε τη διεύθυνση του εκδότη.', 'trade-feed-import' ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $body ) && isset( $body['message'] ) ? self::clean( (string) $body['message'] ) : '';
			/* translators: 1: HTTP status, 2: message */
			return new WP_Error( 'tfi_status', sprintf( __( 'Ο εκδότης απάντησε με σφάλμα %1$d. %2$s', 'trade-feed-import' ), $code, $msg ) );
		}
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'tfi_json', __( 'Η απάντηση του εκδότη δεν είναι έγκυρο JSON.', 'trade-feed-import' ) );
		}
		return $body;
	}

	/** A message of the other side, safe to show and log. */
	private static function clean( string $msg ): string {
		$pw  = TFI_Settings::password();
		$msg = is_string( $pw ) && '' !== $pw ? str_replace( $pw, '***', $msg ) : $msg;
		return mb_substr( trim( sanitize_text_field( $msg ) ), 0, 200 );
	}
}

<?php
/**
 * Publishing Quote — public API for other plugins (Bible §6).
 *
 * Amounts are integers in cents of the site currency. A request is the
 * snapshot of the moment it was sent: later price changes do not change it.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'pbq_get_request' ) ) {
	/**
	 * One «I'm interested» request, or null.
	 *
	 * @return array|null id, date (ISO 8601, UTC), state (new | contact |
	 *                    oros | closed), lang, contact (name, email, phone,
	 *                    book, msg), input (the choices), lines (label,
	 *                    amount, vat, ask: what the visitor saw), typeset,
	 *                    total, incl_vat, vat, per_copy, valid_until, view
	 *                    (formatted texts), breakdown (rows for the owner
	 *                    only, never for the visitor), currency.
	 */
	function pbq_get_request( int $id ): ?array {
		return PBQ_Requests::get( $id );
	}
}

if ( ! function_exists( 'pbq_get_requests' ) ) {
	/**
	 * Request ids, newest first.
	 *
	 * @param array $args state, after (only ids above it), limit (≤ 500), page, search.
	 * @return int[]
	 */
	function pbq_get_requests( array $args = array() ): array {
		return PBQ_Requests::ids( $args );
	}
}

if ( ! function_exists( 'pbq_set_request_state' ) ) {
	/**
	 * Changes the state of a request (e.g. 'oros' once orOS has taken it),
	 * with an optional note shown in the admin. Fires pbq_request_state.
	 */
	function pbq_set_request_state( int $id, string $state, string $note = '' ): bool {
		return PBQ_Requests::set_state( $id, $state, $note );
	}
}

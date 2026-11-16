<?php
/**
 * Forms — public API for other plugins (Bible §6).
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'frm_get_form' ) ) {
	/**
	 * A form: id, title, active, fields [ key, type, label_el, label_en, required, … ], mail, store,
	 * spam, oros; or null.
	 */
	function frm_get_form( int $id ): ?array {
		return FRM_Forms::get( $id );
	}
}

if ( ! function_exists( 'frm_get_entry' ) ) {
	/**
	 * A stored message: id, form, form_title, date, state (new | read | spam), mail (sent | failed |
	 * none), name, email, fields [ key, label, type, value ], page, product, lang, reason, consent;
	 * or null.
	 */
	function frm_get_entry( int $id ): ?array {
		return FRM_Entries::get( $id );
	}
}

if ( ! function_exists( 'frm_get_entries' ) ) {
	/**
	 * Stored message ids, newest first.
	 *
	 * @param array $args form (int), state (inbox | new | read | spam | ''), search, page, per (≤ 500),
	 *                    after (Y-m-d).
	 * @return int[]
	 */
	function frm_get_entries( array $args = array() ): array {
		return FRM_Entries::query( $args )['ids'];
	}
}

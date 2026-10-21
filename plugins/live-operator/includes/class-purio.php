<?php
/**
 * LOP_Purio — everything Live Operator knows about PurioChat Pro's Live
 * Chat (ai-chat-search-pro, checked against 2.1.18), in one place.
 *
 * Used, read-only unless said otherwise:
 *  - class AI_Chat_Search_Pro_Live_Handoff, its public get_instance(),
 *    is_live_chat_enabled() and get_conversation_for_integration().
 *  - actions listeo_ai_live_handoff_requested( $conversation_id, $reason,
 *    $alerts_allowed ) and listeo_ai_live_handoff_visitor_message(
 *    $conversation_id, $message ) (LOP_Push).
 *  - the transient listeo_ai_live_handoff_operator_presence: PurioChat's
 *    «an operator is online» (its inbox writes it every 30 s, 2 minutes
 *    TTL). LOP_Presence answers it through the pre_transient_ / transient_
 *    filters; the stored value is never written.
 *  - the options listeo_ai_live_handoff_availability_mode and
 *    listeo_ai_live_handoff_waiting_timeout, only shown on the settings
 *    page (deviation from Bible §1.1, a third-party plugin without an API
 *    for them; never written).
 *  - its REST routes listeo/v1/live-handoff/threads… are called by the app
 *    page from the browser, with the operator's own login.
 */

defined( 'ABSPATH' ) || exit;

final class LOP_Purio {

	const PRESENCE = 'listeo_ai_live_handoff_operator_presence';

	/** PurioChat's Live Chat controller, or null when it is missing. */
	public static function handoff() {
		if ( ! class_exists( 'AI_Chat_Search_Pro_Live_Handoff' ) || ! method_exists( 'AI_Chat_Search_Pro_Live_Handoff', 'get_instance' ) ) {
			return null;
		}
		try {
			return AI_Chat_Search_Pro_Live_Handoff::get_instance();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** PurioChat Pro is active (its Live Chat class is loaded). */
	public static function present(): bool {
		return null !== self::handoff();
	}

	/** Live Chat is switched on and the Pro licence is valid. */
	public static function live(): bool {
		$h = self::handoff();
		if ( ! $h || ! method_exists( $h, 'is_live_chat_enabled' ) ) {
			return false;
		}
		try {
			return (bool) $h->is_live_chat_enabled();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** 'always' | 'online' | 'business_hours'. */
	public static function mode(): string {
		$mode = (string) get_option( 'listeo_ai_live_handoff_availability_mode', 'always' );
		return in_array( $mode, array( 'always', 'online', 'business_hours' ), true ) ? $mode : 'always';
	}

	/** Seconds a request waits for an operator before it goes back to the AI. */
	public static function timeout(): int {
		$t = (int) get_option( 'listeo_ai_live_handoff_waiting_timeout', 60 );
		return in_array( $t, array( 30, 60, 90, 120, 180, 240, 300 ), true ) ? $t : 60;
	}

	/**
	 * A conversation snapshot: thread (no token, no IP) and the last messages.
	 *
	 * @return array{thread:array,messages:array}|null
	 */
	public static function conversation( string $conversation_id, int $limit = 1 ): ?array {
		$h = self::handoff();
		if ( ! $h || ! method_exists( $h, 'get_conversation_for_integration' ) ) {
			return null;
		}
		try {
			$snap = $h->get_conversation_for_integration( $conversation_id, $limit );
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_array( $snap ) && isset( $snap['thread'] ) && is_array( $snap['thread'] ) ? $snap : null;
	}

	/** The last visitor message of a snapshot, '' when there is none. */
	public static function last_visitor_text( array $snap ): string {
		$messages = isset( $snap['messages'] ) && is_array( $snap['messages'] ) ? $snap['messages'] : array();
		for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
			$m = $messages[ $i ];
			if ( is_array( $m ) && isset( $m['sender_type'], $m['content'] ) && 'visitor' === $m['sender_type'] ) {
				return (string) $m['content'];
			}
		}
		return '';
	}
}

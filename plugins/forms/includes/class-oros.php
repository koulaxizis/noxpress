<?php
/**
 * FRM_Oros — sign-ups to orOS Newsletter through orOS Connect (Chris
 * 2026-11-16, Q9).
 *
 *  - A form with «Sign up to orOS Newsletter» on, an email field and a
 *    consent field: after an accepted «Send» with the consent ticked, the
 *    sign-up is queued and posted from the server to the list's sign-up
 *    address, with the same code orOS Connect uses for checkout sign-ups
 *    (ORC_Relay::fields() / ORC_Relay::post()). orOS Connect stays as it
 *    is; nothing is posted when it is missing, killed, or its Newsletter
 *    module is off or has no address.
 *  - The confirmation e-mail (double opt-in) follows orOS Connect's own
 *    setting: with «confirmation for checkout sign-ups too» off and a shop
 *    key, the post is signed so orOS can skip it, as for checkout.
 *  - The queue: Action Scheduler (group forms) when present, else WP-Cron.
 *    The arguments hold only a random key; the e-mail and the name wait in
 *    a transient (1 day) that is deleted after the last try, so no
 *    personal data stays in the scheduler's tables. Retries after 5 min,
 *    30 min, 2 h and 12 h for busy / unavailable / network answers.
 */

defined( 'ABSPATH' ) || exit;

final class FRM_Oros {

	const HOOK  = 'frm_oros_send';
	const GROUP = 'forms';
	const TRIES = 5;

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'send' ), 10, 2 );
	}

	/** True when orOS Connect can take a sign-up now. */
	public static function available(): bool {
		if ( ! class_exists( 'ORC_Relay' ) || ! class_exists( 'ORC_Settings' ) || ! class_exists( 'Oros_Connect' ) ) {
			return false;
		}
		if ( ! is_callable( array( 'ORC_Relay', 'post' ) ) || ! is_callable( array( 'ORC_Relay', 'fields' ) ) || ! is_callable( array( 'ORC_Settings', 'module' ) ) ) {
			return false;
		}
		if ( is_callable( array( 'Oros_Connect', 'killed' ) ) && Oros_Connect::killed() ) {
			return false;
		}
		$s = ORC_Settings::get();
		return ORC_Settings::module( 'newsletter' ) && is_array( $s ) && '' !== (string) ( $s['nl_url'] ?? '' );
	}

	private static function delay( int $try ): int {
		$d = array( 1 => 300, 2 => 1800, 3 => 7200, 4 => 43200 );
		return isset( $d[ $try ] ) ? $d[ $try ] : 43200;
	}

	private static function schedule( string $key, int $try, int $delay ): void {
		$args = array( $key, $try );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::HOOK, $args, self::GROUP );
		} else {
			wp_schedule_single_event( time() + $delay, self::HOOK, $args );
		}
	}

	/**
	 * Queue a sign-up after an accepted «Send».
	 *
	 * @param array $form Form.
	 * @param array $data Submission (FRM_Submit::build()).
	 */
	public static function queue( array $form, array $data ): void {
		if ( empty( $form['oros'] ) || empty( $data['consent'] ) || '' === $data['email'] || ! self::available() ) {
			return;
		}
		$key = strtolower( wp_generate_password( 24, false, false ) );
		set_transient(
			'frm_oros_' . $key,
			array(
				'email' => $data['email'],
				'name'  => $data['name'],
				'lang'  => $data['lang'],
			),
			DAY_IN_SECONDS
		);
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( $key, 1 ), self::GROUP );
		} else {
			self::schedule( $key, 1, 0 );
		}
	}

	/** Scheduled: post one sign-up. */
	public static function send( $key, $try = 1 ): void {
		$key = is_string( $key ) ? preg_replace( '/[^a-z0-9]/', '', $key ) : '';
		$try = max( 1, (int) $try );
		if ( '' === $key ) {
			return;
		}
		$item = get_transient( 'frm_oros_' . $key );
		if ( ! is_array( $item ) || empty( $item['email'] ) ) {
			return;
		}
		if ( ! self::available() ) {
			delete_transient( 'frm_oros_' . $key );
			return;
		}
		$s    = ORC_Settings::get();
		$sign = empty( $s['nl_doi'] ) ? (string) ( $s['nl_key'] ?? '' ) : '';
		$r    = ORC_Relay::post( (string) $s['nl_url'], ORC_Relay::fields( (string) $item['email'], (string) $item['name'], (string) $item['lang'], $sign ) );
		$res  = is_array( $r ) && isset( $r['res'] ) ? (string) $r['res'] : 'error';

		if ( 'ok' !== $res && is_callable( array( 'ORC_Relay', 'retryable' ) ) && ORC_Relay::retryable( $res ) && $try < self::TRIES ) {
			self::schedule( $key, $try + 1, self::delay( $try ) );
			return;
		}
		delete_transient( 'frm_oros_' . $key );

		/**
		 * After each sign-up from a form reached its end (sent, refused or out of tries).
		 *
		 * @param string $res ok | bad | gone | busy | off | error | network.
		 */
		do_action( 'frm_oros_sent', $res );
	}
}

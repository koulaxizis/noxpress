<?php
/**
 * SBR_Notify — an e-mail for every change of the write window (1.12.0).
 *
 * Sent by the site whose window changed, to its admin e-mail, when the
 * Access setting «E-mail for every change of the write window» is on (on
 * by default). Changes:
 *  - open            opened by hand (WP admin, Hub, app or Claude)
 *  - close           closed by hand; inside a stretch of the schedule it
 *                    is a pause until the stretch ends
 *  - schedule_open   a stretch of the schedule started (cron)
 *  - schedule_close  a stretch of the schedule ended (cron)
 *  - schedule_saved  the schedule was changed (old → new)
 *
 * The schedule's own events also go to the log here; opening and closing
 * by hand are logged by their callers, as before. The phone app learns
 * the same changes from the Hub (SBR_App, window state of every site).
 * E-mails are English, like the other Site Bridge mails.
 */

defined( 'ABSPATH' ) || exit;

final class SBR_Notify {

	/** Names of the sources in the e-mails. */
	const SOURCES = array(
		'admin'    => 'WP admin',
		'hub'      => 'the Hub',
		'app'      => 'the Site Bridge app',
		'claude'   => 'Claude (write_window_close)',
		'schedule' => 'the schedule',
	);

	/**
	 * One change of the window.
	 *
	 * @param string $event  open, close, schedule_open, schedule_close, schedule_saved.
	 * @param string $source A key of SOURCES.
	 * @param array  $data   until, minutes, paused, next, ended, still, who, old, new.
	 */
	public static function change( string $event, string $source, array $data = array() ): void {
		if ( 0 === strpos( $event, 'schedule_' ) ) {
			SBR_Store::log(
				array(
					'side'    => 'site',
					'client'  => 'schedule' === $source ? 'schedule' : (string) ( $data['who'] ?? '' ),
					'tool'    => 'window_' . $event,
					'class'   => 'admin',
					'args'    => self::log_args( $data ),
					'status'  => 'ok',
					'message' => 'schedule_saved' === $event ? 'WP admin' : 'Schedule',
				)
			);
		}
		if ( ! SBR_Settings::v( 'window_email' ) ) {
			return;
		}
		list( $subject, $lines ) = self::text( $event, $source, $data );
		$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$tz   = wp_timezone_string();
		$body = implode( "\n", $lines ) . "\n\n"
			. sprintf( "Site: %s\nTime: %s (%s)\n", home_url( '/' ), wp_date( 'Y-m-d H:i' ), $tz )
			. ( 'schedule' !== $source && 'schedule_saved' !== $event ? sprintf( "IP: %s\n", Site_Bridge::ip() ? Site_Bridge::ip() : '—' ) : '' )
			. sprintf( "\nAccess and schedule: %s\nHistory: %s\n", admin_url( 'admin.php?page=sbr-settings&tab=access' ), admin_url( 'admin.php?page=sbr-settings&tab=log' ) );
		wp_mail( get_option( 'admin_email' ), sprintf( '[%s] Site Bridge: %s', $name, $subject ), $body );
	}

	/** Subject and body lines. */
	private static function text( string $event, string $source, array $data ): array {
		$by    = self::SOURCES[ $source ] ?? $source;
		$who   = isset( $data['who'] ) && '' !== $data['who'] ? ' (' . $data['who'] . ')' : '';
		$until = (int) ( $data['until'] ?? 0 );
		$next  = (int) ( $data['next'] ?? 0 );
		switch ( $event ) {
			case 'open':
				return array(
					'write window opened until ' . self::t( $until ),
					array(
						'The write window was opened by ' . $by . $who . '.',
						'Open until: ' . self::t( $until, true ),
						'If this was not you, close it now and check the History tab.',
					),
				);
			case 'close':
				$lines = array( 'The write window was closed by ' . $by . $who . '.' );
				if ( ! empty( $data['paused'] ) ) {
					$lines[] = 'The schedule is paused until ' . self::t( (int) $data['paused'], true ) . '.';
				}
				$lines[] = $next ? 'Next scheduled opening: ' . self::t( $next, true ) : 'No scheduled opening.';
				return array( 'write window closed', $lines );
			case 'schedule_open':
				return array(
					'write window opened by the schedule until ' . self::t( $until ),
					array(
						'The schedule opened the write window.',
						'Open until: ' . self::t( $until, true ),
						'Close it early in WP admin → Noxpress → Site Bridge → Access, from the Hub or from the app; the next stretch opens as planned.',
					),
				);
			case 'schedule_close':
				$lines = array( 'The schedule closed the write window.' );
				if ( ! empty( $data['still'] ) ) {
					$lines[] = 'It stays open until ' . self::t( (int) $data['still'], true ) . ', opened by hand.';
				}
				$lines[] = $next ? 'Next scheduled opening: ' . self::t( $next, true ) : 'No scheduled opening.';
				return array( 'write window closed by the schedule', $lines );
			case 'schedule_saved':
				return array(
					'write window schedule changed',
					array(
						'The schedule of the write window was changed in WP admin' . $who . '.',
						'',
						'Before:',
						(string) ( $data['old'] ?? '' ),
						'',
						'Now:',
						(string) ( $data['new'] ?? '' ),
						'',
						$next ? 'Next scheduled opening: ' . self::t( $next, true ) : 'No scheduled opening.',
					),
				);
		}
		return array( 'write window changed', array( 'The write window changed.' ) );
	}

	/** Site time, «14:00» or «Tue 2026-11-10 14:00». */
	private static function t( int $ts, bool $long = false ): string {
		if ( $ts <= 0 ) {
			return '—';
		}
		return $long ? wp_date( 'D Y-m-d H:i', $ts ) : wp_date( 'H:i', $ts );
	}

	private static function log_args( array $data ): array {
		$out = array();
		foreach ( array( 'until', 'paused', 'next', 'ended', 'still' ) as $k ) {
			if ( ! empty( $data[ $k ] ) ) {
				$out[ $k ] = gmdate( 'c', (int) $data[ $k ] );
			}
		}
		if ( isset( $data['new'] ) ) {
			$out['old'] = (string) ( $data['old'] ?? '' );
			$out['new'] = (string) $data['new'];
		}
		return $out;
	}
}

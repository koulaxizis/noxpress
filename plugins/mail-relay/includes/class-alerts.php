<?php
/**
 * MRL_Alerts — telling someone that mail does not go out.
 *
 * A mail that cannot be sent cannot say so itself, so there are three ways:
 *  - A banner on every admin page for manage_options users while there are
 *    failures of the last 24 hours not yet seen in the log tab, while the
 *    site address changed (sending held), and while another plugin's
 *    wp_mail() keeps Mail Relay from sending.
 *  - At most one summary email an hour to the admin (setting «alerts»):
 *    counts and errors only, never the content or recipients of customers'
 *    mails. It goes through the primary, the backup and, last, the
 *    server's own mail().
 *  - The state is in the mail-relay/status ability (Site Bridge health).
 *
 * State: option mrl_alert { last, count, error, backup, held } (autoload
 * off); user meta mrl_seen = time the log tab was last opened.
 */

defined( 'ABSPATH' ) || exit;

final class MRL_Alerts {

	const OPT      = 'mrl_alert';
	const SENT     = 'mrl_alert_sent';
	const SEEN     = 'mrl_seen';
	const INTERVAL = HOUR_IN_SECONDS;

	/** True while the plugin sends its own mails (test, summary): no alerts about them. */
	public static $quiet = false;

	/** A summary is queued for shutdown. */
	private static $queued = false;

	public static function init(): void {
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function state(): array {
		$s = get_option( self::OPT, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'last'   => isset( $s['last'] ) ? (int) $s['last'] : 0,
			'count'  => isset( $s['count'] ) ? (int) $s['count'] : 0,
			'error'  => isset( $s['error'] ) ? (string) $s['error'] : '',
			'backup' => isset( $s['backup'] ) ? (int) $s['backup'] : 0,
			'held'   => isset( $s['held'] ) ? (int) $s['held'] : 0,
		);
	}

	/**
	 * A delivery failed ($saved = false) or the primary failed and the
	 * backup sent it ($saved = true).
	 */
	public static function failed( string $error, bool $saved ): void {
		if ( self::$quiet ) {
			return;
		}
		$s = self::state();
		if ( $saved ) {
			$s['backup'] = time();
		} else {
			$s['last'] = time();
			++$s['count'];
		}
		$s['error'] = substr( $error, 0, 500 );
		update_option( self::OPT, $s, false );

		if ( ! $saved && MRL_Settings::get()['alerts'] && ! self::$queued && ! get_transient( self::SENT ) ) {
			self::$queued = true;
			add_action( 'shutdown', array( __CLASS__, 'send_summary' ), 99 );
		}
	}

	public static function held(): void {
		if ( self::$quiet ) {
			return;
		}
		$s         = self::state();
		$s['held'] = time();
		update_option( self::OPT, $s, false );
	}

	/** Summary email (shutdown), at most once an hour. */
	public static function send_summary(): void {
		if ( get_transient( self::SENT ) ) {
			return;
		}
		set_transient( self::SENT, 1, self::INTERVAL );
		$st    = self::state();
		$c     = MRL_Log::counts( time() - DAY_IN_SECONDS );
		$s     = MRL_Settings::get();
		$to    = '' !== $s['alert_to'] ? $s['alert_to'] : (string) get_option( 'admin_email' );
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$lines = array(
			/* translators: %s: site name */
			sprintf( __( 'Το Mail Relay στο %s δεν μπόρεσε να στείλει email.', 'mail-relay' ), $site ),
			'',
			/* translators: %d: number */
			sprintf( __( 'Αποτυχίες τις τελευταίες 24 ώρες: %d', 'mail-relay' ), $c['failed'] ),
			/* translators: %d: number */
			sprintf( __( 'Στάλθηκαν τις τελευταίες 24 ώρες: %d', 'mail-relay' ), $c['sent'] ),
			'',
			__( 'Τελευταίο σφάλμα:', 'mail-relay' ),
			MRL_Log::mask_text( $st['error'] ),
			'',
			__( 'Αρχείο αποστολών:', 'mail-relay' ) . ' ' . admin_url( 'admin.php?page=mrl-settings&tab=log&mrl_status=failed' ),
		);
		/* translators: %s: site name */
		MRL_Mailer::send_summary( $to, sprintf( __( 'Mail Relay: αποτυχία αποστολής στο %s', 'mail-relay' ), $site ), implode( "\n", $lines ) );
	}

	/** The log tab was opened: failures until now are seen. */
	public static function seen(): void {
		update_user_meta( get_current_user_id(), self::SEEN, time() );
	}

	public static function notices(): void {
		if ( ! current_user_can( Mail_Relay::CAP ) ) {
			return;
		}
		$s    = MRL_Settings::get();
		$st   = self::state();
		$link = static function ( string $tab, array $args = array() ): string {
			return add_query_arg( array_merge( array( 'page' => 'mrl-settings', 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
		};
		if ( 'on' === $s['mode'] && MRL_Settings::moved() ) {
			echo '<div class="notice notice-warning"><p><strong>Mail Relay:</strong> ' . esc_html__( 'Η διεύθυνση του site άλλαξε (αντίγραφο ή μεταφορά), οπότε η αποστολή email είναι σε παύση. Αν αυτό είναι το πραγματικό site, επιβεβαίωσέ το στα Εργαλεία.', 'mail-relay' ) . ' <a href="' . esc_url( $link( 'tools' ) ) . '">' . esc_html__( 'Άνοιγμα', 'mail-relay' ) . '</a></p></div>';
		}
		if ( 'on' === $s['mode'] && '' !== MRL_Mailer::foreign_wp_mail() ) {
			echo '<div class="notice notice-error"><p><strong>Mail Relay:</strong> ' . esc_html__( 'Άλλο plugin έχει αντικαταστήσει τη συνάρτηση wp_mail(), οπότε το Mail Relay δεν στέλνει. Απενεργοποίησε το άλλο plugin email.', 'mail-relay' ) . '</p></div>';
		}
		$seen = (int) get_user_meta( get_current_user_id(), self::SEEN, true );
		if ( $st['last'] > time() - DAY_IN_SECONDS && $st['last'] > $seen ) {
			echo '<div class="notice notice-error"><p><strong>Mail Relay:</strong> ' . esc_html__( 'Κάποια email δεν στάλθηκαν τις τελευταίες 24 ώρες.', 'mail-relay' ) . ' <a href="' . esc_url( $link( 'log', array( 'mrl_status' => 'failed' ) ) ) . '">' . esc_html__( 'Δες το αρχείο', 'mail-relay' ) . '</a></p></div>';
		}
	}
}

<?php
/**
 * FRM_Widget — dashboard widget «Forms» (Bible §6): new messages, e-mails
 * that failed (the message is kept), blocked attempts today and this week.
 * Only for users with the plugin's capability; nothing is computed for
 * the others.
 */

defined( 'ABSPATH' ) || exit;

final class FRM_Widget {

	public static function init(): void {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		if ( ! current_user_can( Noxpress_Forms::cap() ) ) {
			return;
		}
		wp_add_dashboard_widget( 'frm_dashboard', __( 'Forms', 'forms' ), array( __CLASS__, 'render' ) );
	}

	public static function render(): void {
		$c    = FRM_Entries::counts();
		$s    = FRM_Spam::stats( 7 );
		$base = FRM_Admin_UI::url( 'messages' );
		$mode = FRM_Settings::get()['mode'];
		if ( 'live' !== $mode ) {
			echo '<p class="frm-w-warn">' . esc_html( 'off' === $mode ? __( 'Οι φόρμες είναι κλειστές (λειτουργία «Ανενεργό»).', 'forms' ) : __( 'Λειτουργία δοκιμής: μόνο οι διαχειριστές βλέπουν τις φόρμες.', 'forms' ) ) . '</p>';
		}
		?>
		<ul class="frm-w">
			<li><a href="<?php echo esc_url( add_query_arg( 'state', 'new', $base ) ); ?>"><strong><?php echo (int) $c['new']; ?></strong> <?php esc_html_e( 'νέα μηνύματα', 'forms' ); ?></a></li>
			<li><a href="<?php echo esc_url( add_query_arg( 'mail', 'failed', $base ) ); ?>"><strong><?php echo (int) $c['failed']; ?></strong> <?php esc_html_e( 'με email που απέτυχε', 'forms' ); ?></a></li>
			<li><strong><?php echo (int) $s['today']; ?></strong> <?php esc_html_e( 'μπλοκαρίστηκαν σήμερα', 'forms' ); ?> · <?php echo (int) $s['total']; ?> <?php esc_html_e( 'σε 7 ημέρες', 'forms' ); ?></li>
		</ul>
		<p class="description"><?php esc_html_e( 'Τα μηνύματα έρχονται με email. Κρατιούνται εδώ μόνο όταν το email αποτύχει, εκτός αν η φόρμα ορίζει «Πάντα».', 'forms' ); ?></p>
		<?php
		if ( $c['failed'] > 0 ) {
			echo '<p class="frm-w-warn">' . esc_html__( 'Κάποια email δεν στάλθηκαν: έλεγξε τη ρύθμιση email του site (π.χ. SMTP).', 'forms' ) . '</p>';
		}
	}
}

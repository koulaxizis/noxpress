<?php
/**
 * Smart Formatter — uninstall.php (v1.1.0)
 *
 * Cleanup (πλήρες — κανένα υπόλειμμα μετά το deactivate + delete):
 *  - Options: sf_profiles, sf_exclusions,
 *    sf_snapshots (OPT_HISTORY — περιλαμβάνει τα undo payloads).
 *  - Transients: sf_aui_msg_{user_id} (PRG notices — μπορεί να
 *    έχουν μείνει εκπνευσμένα στη βάση, διαγράφονται τώρα).
 *  - Καμία δομή σε server filesystem δεν δημιουργείται από το SF
 *    (uploads/folders): δεν χρειάζεται filesystem cleanup εδώ.
 *  - Multisite: κάθε site ΜΙΑ φορά (και το main site μέσα στο loop).
 *
 * Notes:
 *  - ΠΡΟΣΟΧΗ (σκόπιμο semantic): διαγράφεται ΜΟΝΟ το SF state.
 *    Τα ΠΡΟΪΟΝΤΑ (περιγραφές κτλ.) ΔΕΝ αγγίζονται — ό,τι έχει ήδη
 *    εφαρμοστεί με Apply παραμένει στο κατάστημα. Αν κάποιος θέλει
 *    rollback πριν το uninstall, πρέπει πρώτα Restore από το history.
 *  - Το rs_lang user meta ανήκει στο Revenue Splitter — δεν αγγίζεται.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Καθαρισμός ενός site (options + transients του SF μόνο).
 * Options: hardcoded λίστα (οι classes του plugin δεν φορτώνονται εδώ).
 */
function sf_uninstall_site(): void {
	global $wpdb;

	foreach ( array( 'sf_profiles', 'sf_exclusions', 'sf_snapshots' ) as $sf_opt ) {
		delete_option( $sf_opt );
	}

	// Transients sf_aui_msg_% — LIKE με escaped '_' (αλλιώς wildcard).
	$sf_like_val = $wpdb->esc_like( '_transient_sf_aui_msg_' ) . '%';
	$sf_like_to  = $wpdb->esc_like( '_transient_timeout_sf_aui_msg_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off uninstall cleanup.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$sf_like_val,
			$sf_like_to
		)
	);
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $sf_site_id ) {
		switch_to_blog( (int) $sf_site_id );
		sf_uninstall_site();
		restore_current_blog();
	}
} else {
	sf_uninstall_site();
}

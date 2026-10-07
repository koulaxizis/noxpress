<?php
/**
 * Smart Formatter — uninstall.php
 *
 * Cleanup (πλήρες — κανένα υπολείμμα μετά το deactivate + delete):
 *  - Options: sf_profiles, sf_exclusions,
 *    sf_snapshots (OPT_HISTORY — περιλαμβάνει τα undo payloads).
 *  - Transients: sf_aui_msg_{user_id} (PRG notices — μπορεί να
 *    έχουν μείνει εκπνευσμένα στη βάση, διαγράφονται τώρα).
 *  - Καμία δομή σε server filesystem δεν δημιουργείται από το SF
 *    (uploads/folders): δεν χρειάζεται filesystem cleanup εδώ.
 *
 * Notes:
 *  - Το uninstall.php τρέχει ΠΑΝΤΑ από το WP core (check-upgrade /
 *    delete_plugin) με ABSPATH ήδη defined — το exit guard είναι
 *    defence in depth για web-access αιτήματα.
 *  - ΠΡΟΣΟΧΗ (σκόπιμο semantic): διαγράφεται ΜΟΝΟ το SF state.
 *    Τα ΠΡΟΪΟΝΤΑ (περιγραφές κτλ.) ΔΕΝ αγγίζονται — ό,τι έχει ήδη
 *    εφαρμοστεί με Apply παραμένει στο κατάστημα. Αν κάποιος θέλει
 *    rollback πριν το uninstall, πρέπει πρώτα Restore από το
 *    history — το έχουμε ήδη ρητά στην ροή του UI.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/* Options — hardcoded list (intentional duplication of the const values:
   constants classes δεν φορτώνουν εδώ — το plugin είναι ήδη unloaded). */
$sf_options = array(
	'sf_profiles',
	'sf_exclusions',
	'sf_snapshots',
);

foreach ( $sf_options as $sf_opt ) {
	delete_option( $sf_opt );
}

/* Multisite-aware; single site: γρήγορο no-op. */
if ( is_multisite() ) {
	$sf_site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $sf_site_ids as $sf_site_id ) {
		switch_to_blog( $sf_site_id );
		foreach ( $sf_options as $sf_opt ) {
			delete_option( $sf_opt );
		}
		restore_current_blog();
	}
}

/* Transients: sf_aui_msg_% — γρήγορο targeted sweep (μικρός αριθμός
   users). ΔΕΝ full-options-table scan. */
global $wpdb;
$sf_transients = $wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_sf_aui_msg_%'
	    OR option_name LIKE '_transient_timeout_sf_aui_msg_%'"
);

/* NOTE: multisite — το transient sweep εκτελείται ΜΟΝΟ στο τρέχον
   site, σκόπιμα: το Noxpress ecosystem είναι single-site και δεν
   προσθέτουμε speculative code. Τα options παραπάνω καλύπτονται
   από το switch_to_blog loop. */
<?php
/**
 * Revenue Splitter — uninstall cleanup.
 * (auto-included από το WP όταν διαγράφεται το plugin)
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! defined( 'ABSPATH' ) ) {
	exit;
}

// Options.
delete_option( 'rs_default_vat_rate' );
delete_option( 'rs_beneficiaries' );
delete_option( 'rs_portal_keys' );    // Access keys του Portal.
delete_option( 'rs_cache_version' );  // Cache versioning.

// Destructive uninstall: product meta (overrides + ΦΠΑ).
delete_post_meta_by_key( '_rs_split' );
delete_post_meta_by_key( '_rs_vat_rate' );

// User-level (γλώσσα, φίλτρα, περίοδος) — mass, χωρίς get_users() loop.
delete_metadata( 'user', 0, 'rs_lang', '', true );
delete_metadata( 'user', 0, 'rs_last_period', '', true );
delete_metadata( 'user', 0, 'rs_last_filters', '', true );

// ΟΛΑ τα transients του plugin + τα timeouts τους.
global $wpdb;

$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\\_transient\\_rs\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_rs\\_%'"
);
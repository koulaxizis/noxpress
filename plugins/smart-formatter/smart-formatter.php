<?php
/**
 * Plugin Name: Smart Formatter
 * Description: Μαζική μορφοποίηση κειμένων προϊόντων WooCommerce (bold, italic, παρενθέσεις, αριθμοί, εισαγωγικά, τυπογραφία, κενά) με preview, dry run, snapshots/undo και profiles.
 * Version: 1.0.0
 * Author: Christos Koulaxizis
 * Author URI: https://koulaxizis.gr
 * License: MIT
 * Text Domain: smart-formatter
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map (ποιος κάνει τι):
 *  - smart-formatter.php  → ΜΟΝΟ bootstrap/constants/requires/init wiring
 *  - SF_Rules            → registry κανόνων (id, label, callback, descriptor)
 *  - SF_Engine           → tokenizer (HTML/tag protection) + rule pipeline
 *  - SF_Targets          → επίλυση στόχων (products/categories/tags × fields)
 *  - SF_Snapshots        → undo (snapshot πριν κάθε run, restore, ιστορικό)
 *  - SF_Admin_UI         → menu, dashboard, settings/profiles, AJAX endpoints
 *
 * Language: Greek msgids ως πηγή, EN μέσω δικού μας dict (gettext filter,
 * domain 'smart-formatter'). Το rs_lang (user meta) ΔΙΑΒΑΖΕΤΑΙ μόνο —
 * δεν γράφεται ποτέ από εδώ (Bible §9: one choice, whole ecosystem).
 */

defined( 'ABSPATH' ) || exit;

define( 'SF_VERSION', '1.0.0' );
define( 'SF_FILE', __FILE__ );
define( 'SF_PATH', plugin_dir_path( __FILE__ ) );

require_once SF_PATH . 'includes/class-rules.php';
require_once SF_PATH . 'includes/class-engine.php';
require_once SF_PATH . 'includes/class-targets.php';
require_once SF_PATH . 'includes/class-snapshots.php';
require_once SF_PATH . 'includes/class-admin-ui.php';

final class Smart_Formatter {

	public static function init(): void {
		SF_Admin_UI::init();
	}
}

add_action( 'plugins_loaded', array( 'Smart_Formatter', 'init' ), 20 );
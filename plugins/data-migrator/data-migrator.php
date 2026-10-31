<?php
/**
 * Plugin Name:          Data Migrator
 * Plugin URI:           https://noxpress.tech
 * Description:          Πλήρες αντίγραφο ασφαλείας του WordPress / WooCommerce site (βάση, uploads, θέματα, plugins, core) και επαναφορά στον ίδιο ή σε άλλον server, με αλλαγή domain χωρίς χειροκίνητες διορθώσεις. Ανάλυση πριν την επαναφορά, αναίρεση με ένα κλικ, installer για άδειο server, WP-CLI. Χωρίς εξωτερικές υπηρεσίες.
 * Version:              1.2.1
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/data-migrator
 * Text Domain:          data-migrator
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - data-migrator.php → bootstrap only (constants, requires, init wiring)
 *  - runner.php        → standalone restore runner (SHORTINIT, token)
 *  - engine/ (no WordPress calls; also inside runner.php and the installer)
 *    - NM_Util         → state files, locks, paths, log, translation
 *    - NM_Crypt        → AES-256 entries (WinZip AE-2), sealed secrets
 *    - NM_Zip_Writer   → resumable streaming ZIP (ZIP64, parts)
 *    - NM_Zip_Reader   → central directory walk, CRC-checked extraction
 *    - NM_DB           → own mysqli connection
 *    - NM_Dumper       → SQL dump in steps (keyset paging)
 *    - NM_Replacer     → serialized-safe search / replace, URL pairs
 *    - NM_Importer     → SQL → temporary tables, replace on the way
 *    - NM_Restorer     → extract, import, fixups, swap, undo
 *    - NM_Page         → standalone pages (runner, installer)
 *    - installer.tpl   → the installer for an empty server
 *  - includes/
 *    - NM_Lang         → EN dictionary + gettext filter (rs_lang, §9)
 *    - NM_Settings     → nm_settings option
 *    - NM_Storage      → private storage folder, backups, jobs, logs
 *    - NM_Integrity    → core checksums (api.wordpress.org)
 *    - NM_Backup       → backup job (scan, db, zip, manifest)
 *    - NM_Schedule     → scheduled backups (events, background steps, retention)
 *    - NM_Restore_Prep → upload, analysis (dry run), plan, restore jobs
 *    - NM_Post_Restore → first request after a restore (cache, rewrite, WC)
 *    - NM_Installer    → builds the installer with a one-time key
 *    - NM_Admin_UI     → menu, pages, AJAX steps, downloads, footer
 *    - NM_CLI          → wp data-migrator …
 *
 * Deviations from the Bible (with reason):
 *  - §1.2 (WooCommerce required): a WordPress plugin; it backs up and
 *    restores any WordPress site, with WooCommerce steps only when
 *    WooCommerce is active. Capability manage_options (the whole site).
 *  - §11 (nonce + capability on every state change): runner.php and the
 *    installer run outside the admin, because a restore replaces the
 *    users, the plugins and maybe WordPress itself. They accept POST only,
 *    with a 128-bit one-time token whose sha256 is stored (runner) or a
 *    one-time key with lockout after 5 failures and a 7-day expiry
 *    (installer). The scheduler's loopback (admin-ajax nm_sched_kick,
 *    no login) checks a random 128-bit token that changes with every run;
 *    all it can do is advance the scheduled backup already running.
 *  - §12 (no .htaccess in our folders): the backup folder gets a deny
 *    .htaccess on purpose. If the host refuses the directive the folder
 *    answers 500, which for backups is as safe as a deny; a self-test
 *    checks over HTTP that nothing can be downloaded.
 *  - §12 (uninstall keeps nothing): backups are deleted with the plugin
 *    unless "Keep backups" is ticked in DM Settings. A forgotten backup
 *    holds the whole database.
 *  - Multisite: not supported in 1.0.0; on a network the plugin shows a
 *    notice and does nothing else.
 *
 * Front end (Bible §14): nothing, except the one-time post-restore step
 * on wp_loaded (cache and rewrite flush) right after a restore.
 */

defined( 'ABSPATH' ) || exit;

define( 'NM_VERSION', '1.2.1' );
define( 'NM_FILE', __FILE__ );
define( 'NM_PATH', plugin_dir_path( __FILE__ ) );
define( 'NM_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16).
require_once NM_PATH . 'includes/noxpress-core/loader.php';

require_once NM_PATH . 'engine/class-nm-util.php';
require_once NM_PATH . 'engine/class-nm-crypt.php';
require_once NM_PATH . 'engine/class-nm-zip-writer.php';
require_once NM_PATH . 'engine/class-nm-zip-reader.php';
require_once NM_PATH . 'engine/class-nm-db.php';
require_once NM_PATH . 'engine/class-nm-dumper.php';
require_once NM_PATH . 'engine/class-nm-replacer.php';
require_once NM_PATH . 'engine/class-nm-importer.php';
require_once NM_PATH . 'engine/class-nm-restorer.php';
require_once NM_PATH . 'includes/class-lang.php';
require_once NM_PATH . 'includes/class-settings.php';
require_once NM_PATH . 'includes/class-storage.php';
require_once NM_PATH . 'includes/class-integrity.php';
require_once NM_PATH . 'includes/class-backup.php';
require_once NM_PATH . 'includes/class-schedule.php';
require_once NM_PATH . 'includes/class-restore-prep.php';
require_once NM_PATH . 'includes/class-post-restore.php';
require_once NM_PATH . 'includes/class-installer.php';

final class Data_Migrator {

	public static function init(): void {
		NM_Lang::init();
		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'data-migrator', false, dirname( plugin_basename( NM_FILE ) ) . '/languages' );
			}
		);

		if ( is_multisite() ) {
			add_action( 'admin_notices', array( __CLASS__, 'multisite_notice' ) );
			add_action( 'network_admin_notices', array( __CLASS__, 'multisite_notice' ) );
			return;
		}

		NM_Post_Restore::init();
		NM_Schedule::init();

		if ( is_admin() ) {
			require_once NM_PATH . 'includes/class-admin-ui.php';
			NM_Admin_UI::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once NM_PATH . 'includes/class-cli.php';
			WP_CLI::add_command( 'data-migrator', 'NM_CLI' );
		}
	}

	public static function multisite_notice(): void {
		if ( ! current_user_can( 'manage_network_options' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Το Data Migrator δεν υποστηρίζει ακόμα multisite και σε αυτό το δίκτυο δεν κάνει τίποτα.', 'data-migrator' ) . '</p></div>';
	}

	/**
	 * Deactivation: stop running backups and analyses, and remove the
	 * schedule events (admin_init puts them back on reactivation). A restore is left
	 * alone: runner.php does not need the plugin to be active, and it
	 * keeps .maintenance and the undo data under its own control.
	 */
	public static function deactivate(): void {
		NM_Schedule::clear_all();
		$base = (string) NM_Settings::get()['storage'];
		if ( '' === $base || ! is_dir( $base . '/jobs' ) ) {
			return;
		}
		foreach ( (array) glob( $base . '/jobs/*.php' ) as $f ) {
			$job = NM_Util::read_state( (string) $f );
			if ( ! is_array( $job ) || 'running' !== ( $job['status'] ?? '' ) ) {
				continue;
			}
			if ( 'backup' === $job['type'] ) {
				NM_Backup::cancel( (string) $job['id'] );
			} elseif ( 'analyze' === $job['type'] ) {
				$job['status'] = 'cancelled';
				NM_Util::write_state( (string) $f, $job );
			}
		}
	}
}

register_deactivation_hook( __FILE__, array( 'Data_Migrator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Data_Migrator', 'init' ), 20 );

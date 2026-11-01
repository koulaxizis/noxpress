<?php
/**
 * Plugin Name:          Lean Images
 * Plugin URI:           https://noxpress.tech
 * Description:          Τοπική μετατροπή των εικόνων σε WebP (και προαιρετικά AVIF) με το GD ή το Imagick του server, σε μικρές παρτίδες στο παρασκήνιο, και σερβίρισμα με <picture>. Τα πρωτότυπα δεν αλλάζουν ποτέ. Χωρίς λογαριασμό, όριο ή εξωτερική υπηρεσία.
 * Version:              1.0.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/lean-images
 * Text Domain:          lean-images
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - lean-images.php → bootstrap only (constants, requires, init wiring)
 *  - LIM_Lang        → EN dictionary + gettext filter (rs_lang, Bible §9)
 *  - LIM_Settings    → the lim_settings option, strict validation
 *  - LIM_Files       → which files an attachment has, their sidecars
 *                      (name.ext.webp / name.ext.avif), what we recorded
 *  - LIM_Convert     → one attachment → sidecars, through WordPress's own
 *                      image editor (Imagick or GD), the sample test
 *  - LIM_Queue       → background batches (Action Scheduler or WP-Cron),
 *                      lock, budget, night window, upload / edit / delete
 *                      hooks, page cache purge, stats
 *  - LIM_Front       → <picture> for images of WordPress functions and
 *                      content, the optional whole-page pass
 *  - LIM_Tools       → retry failed, one attachment, orphans, delete all,
 *                      Imagify detection
 *  - LIM_Abilities   → lean-images/* abilities (Site Bridge, WP 6.9+)
 *  - LIM_Admin_UI    → menu, settings page, media library column, footer
 *
 * Deviations from the Bible (with reason):
 *  - §1.2 (WooCommerce required): a WordPress plugin; lighter images help
 *    every site. Capability manage_options, as Fast Pages.
 *  - §14.2 (test mode for manage_woocommerce): manage_options, the
 *    capability of the plugin.
 *  - §12 (files under uploads/noxpress-<name>/): a sidecar lives next to
 *    its source (uploads/2026/10/cover.png.webp), the name every image
 *    plugin and CDN expects. Every write and delete goes through
 *    LIM_Files::sidecar(), which accepts only "<a file of the attachment's
 *    metadata>.webp|.avif" inside the uploads folder; the source files are
 *    never written, renamed or deleted.
 *  - §14.10 (no file checks at render): the front end checks that the
 *    sidecar is on disk (is_file, PHP's stat cache) before naming it in a
 *    <source>: a <source> that answers 404 shows a broken image, there is
 *    no fallback to the <img>. Safety over a few microseconds.
 *
 * Front end (Bible §14): LIM_DISABLE in wp-config.php stops the front end
 * and the background batches; the site then works as without the plugin.
 * Nothing happens on activation: conversion starts with «Start», and test
 * mode (on by default) shows <picture> only to administrators.
 */

defined( 'ABSPATH' ) || exit;

define( 'LIM_VERSION', '1.0.0' );
define( 'LIM_FILE', __FILE__ );
define( 'LIM_PATH', plugin_dir_path( __FILE__ ) );
define( 'LIM_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once LIM_PATH . 'includes/noxpress-core/loader.php';

require_once LIM_PATH . 'includes/class-lang.php';
require_once LIM_PATH . 'includes/class-settings.php';
require_once LIM_PATH . 'includes/class-files.php';
require_once LIM_PATH . 'includes/class-convert.php';
require_once LIM_PATH . 'includes/class-queue.php';
require_once LIM_PATH . 'includes/class-front.php';
require_once LIM_PATH . 'includes/class-tools.php';
require_once LIM_PATH . 'includes/class-abilities.php';

final class Lean_Images {

	const CAP = 'manage_options';

	public static function init(): void {
		LIM_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'lean-images', false, dirname( plugin_basename( LIM_FILE ) ) . '/languages' );
			}
		);

		// Upload / edit / delete hooks run even with LIM_DISABLE: they only
		// keep our records and our files in step with the library.
		LIM_Queue::init();
		LIM_Abilities::init();
		if ( ! self::killed() ) {
			LIM_Front::init();
		}

		if ( is_admin() ) {
			require_once LIM_PATH . 'includes/class-admin-ui.php';
			LIM_Admin_UI::init();
		}
	}

	/** True when LIM_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'LIM_DISABLE' ) && LIM_DISABLE;
	}

	public static function activate(): void {
		// Nothing changes until «Start» (Bible §14.1).
	}

	/** Stops the batches; the sidecars stay (uninstall deletes them). */
	public static function deactivate(): void {
		LIM_Queue::unschedule();
	}
}

register_activation_hook( __FILE__, array( 'Lean_Images', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Lean_Images', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Lean_Images', 'init' ), 20 );

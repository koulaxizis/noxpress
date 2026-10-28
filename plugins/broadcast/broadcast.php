<?php
/**
 * Plugin Name:          Broadcast
 * Plugin URI:           https://noxpress.tech
 * Description:          Ανακοινώνει γεγονότα του site σε κανάλια: νέα προϊόντα, newsletter που στάλθηκε, νέα βίντεο YouTube, αναρτήσεις Instagram και αναρτήσεις που ετοιμάζεις εσύ, σε ένα Viber Channel. Ουρά με έγκριση, πρότυπα ανά πηγή, χωρίς διπλές αναρτήσεις, με ιστορικό. Δουλεύει με ή χωρίς WooCommerce.
 * Version:              1.1.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/broadcast
 * Text Domain:          broadcast
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * A plugin with modules (Bible §20) that routes site events to channels:
 * sources → queue → destinations. Every source and every destination is a
 * module, off until switched on; a module that is off loads no code.
 * Messages are neutral (title, text, url, image) until a destination
 * adapter turns them into its own format, so a new destination is one
 * file and one module.
 *
 * Dispatch map:
 *  - broadcast.php     → bootstrap only (constants, requires, init wiring)
 *  - BRC_Lang          → EN dictionary + gettext filter (admin: rs_lang)
 *  - BRC_Settings      → the brc_settings option, strict validation,
 *                        modules, templates, limits, test / live / off
 *  - BRC_Crypt         → sealed secrets (Viber token, newsletter secret)
 *  - BRC_Queue         → the {prefix}brc_items table: queue and history,
 *                        dedupe, the brake, approval, retention
 *  - BRC_Jobs          → background work (Action Scheduler, else WP-Cron)
 *  - BRC_Template      → placeholders in the per-source templates
 *  - BRC_Viber         → module "viber" (destination): connect, webhook,
 *                        post through the Channels Post API
 *  - BRC_Rest          → REST broadcast/v1: Viber webhook, newsletter hook
 *  - BRC_Src_Products  → module "products" (WooCommerce): new products
 *  - BRC_Src_Newsletter→ module "newsletter": MailerLite campaign.sent
 *  - BRC_Src_Youtube   → module "youtube": channel feeds, Shorts off
 *  - BRC_Src_Instagram → module "instagram": the owner's account through
 *                        the Instagram API (own Meta app), photos only
 *  - BRC_Src_Queue     → module "queue": abilities for posts prepared
 *                        elsewhere (e.g. through Site Bridge), sent when
 *                        the owner presses «Δημοσιεύτηκε»
 *  - BRC_Src_Digest    → module "digest" (WooCommerce + Book Feed):
 *                        weekly digest of other publishers' titles
 *  - BRC_Admin_UI      → menu, queue, history, modules, templates, tools
 *
 * Deviations from the Bible (with reason):
 *  - §20 (one plugin per external system): Broadcast talks to Viber,
 *    MailerLite, YouTube and Instagram (Meta). It is one function, a router
 *    of announcements, and every system is a module behind one option, one
 *    page and one menu entry (Bible 1.76, §20 «Plugins-δρομολογητές»).
 *  - §1.2 (WooCommerce required): sources other than products work on any
 *    WordPress site (no "Requires Plugins"); the products and digest
 *    modules load only with WooCommerce (Bible 1.20 exception).
 *  - §8 (capability manage_woocommerce): manage_woocommerce with
 *    WooCommerce, manage_options without it.
 *  - §9 / §14.17 (visitor texts EL + EN + Auto): a channel has one language,
 *    so each template is one text in the channel's language.
 *  - §14.4 (no REST): the Viber webhook and the newsletter hook are the
 *    purpose of their modules; both accept only signed requests and write
 *    one queue row at most.
 *
 * Nothing runs on the front end. BRC_DISABLE in wp-config.php stops every
 * source, every send and every route.
 *
 * Cooperation (Bible §6): reads bkf_get_source() (Book Feed),
 * hic_hidden_terms() (Hide Catalog) and por_get_release() (Pre-Orders)
 * when they exist; fires brc_item_sent( $id, $item ) after each post and
 * brc_message( $message, $source, $item ) filters a message before it is
 * queued.
 */

defined( 'ABSPATH' ) || exit;

define( 'BRC_VERSION', '1.1.0' );
define( 'BRC_FILE', __FILE__ );
define( 'BRC_PATH', plugin_dir_path( __FILE__ ) );
define( 'BRC_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once BRC_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce HPOS: no order is read or written, but the declaration keeps
// WooCommerce from listing the plugin as incompatible.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BRC_FILE, true );
		}
	}
);

require_once BRC_PATH . 'includes/class-lang.php';
require_once BRC_PATH . 'includes/class-settings.php';
require_once BRC_PATH . 'includes/class-crypt.php';
require_once BRC_PATH . 'includes/class-queue.php';
require_once BRC_PATH . 'includes/class-jobs.php';
require_once BRC_PATH . 'includes/class-template.php';

final class Broadcast {

	/** Source modules => whether they need WooCommerce. */
	const SOURCES = array(
		'products'   => true,
		'newsletter' => false,
		'youtube'    => false,
		'instagram'  => false,
		'queue'      => false,
		'digest'     => true,
	);

	/** Destination modules. */
	const DESTS = array( 'viber' );

	public static function init(): void {
		BRC_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'broadcast', false, dirname( plugin_basename( BRC_FILE ) ) . '/languages' );
			}
		);

		// Background hooks are always registered, so work queued before a
		// module was switched off ends with a log line instead of an orphan
		// job (Bible §20).
		BRC_Jobs::init();

		// The routes stay registered under BRC_DISABLE and answer 403.
		if ( BRC_Settings::module( 'viber' ) || BRC_Settings::module( 'newsletter' ) ) {
			require_once BRC_PATH . 'includes/class-rest.php';
			BRC_Rest::init();
		}

		if ( self::killed() ) {
			if ( is_admin() ) {
				require_once BRC_PATH . 'includes/class-admin-ui.php';
				BRC_Admin_UI::init();
			}
			return;
		}

		foreach ( self::SOURCES as $src => $needs_wc ) {
			if ( ! BRC_Settings::module( $src ) || ( $needs_wc && ! self::woo() ) ) {
				continue;
			}
			$class = self::source_class( $src );
			require_once BRC_PATH . 'includes/sources/class-src-' . $src . '.php';
			$class::init();
		}

		if ( is_admin() ) {
			require_once BRC_PATH . 'includes/class-admin-ui.php';
			BRC_Admin_UI::init();
		}
	}

	/** Class name of a source module. */
	public static function source_class( string $src ): string {
		return 'BRC_Src_' . ucfirst( $src );
	}

	/** Loads a source module's file (for jobs and the admin), true when it exists. */
	public static function load_source( string $src ): bool {
		if ( ! isset( self::SOURCES[ $src ] ) || ( self::SOURCES[ $src ] && ! self::woo() ) ) {
			return false;
		}
		require_once BRC_PATH . 'includes/sources/class-src-' . $src . '.php';
		return true;
	}

	/** True when BRC_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'BRC_DISABLE' ) && BRC_DISABLE;
	}

	/** True when WooCommerce is active. */
	public static function woo(): bool {
		return class_exists( 'WooCommerce' );
	}

	/** Capability for the settings: shop managers with WooCommerce, admins without it. */
	public static function cap(): string {
		return self::woo() ? 'manage_woocommerce' : 'manage_options';
	}

	public static function activate(): void {
		BRC_Queue::install();
	}

	public static function deactivate(): void {
		BRC_Jobs::unschedule_all();
	}
}

register_activation_hook( __FILE__, array( 'Broadcast', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Broadcast', 'deactivate' ) );

// Priority 20 like the other suite plugins.
add_action( 'plugins_loaded', array( 'Broadcast', 'init' ), 20 );

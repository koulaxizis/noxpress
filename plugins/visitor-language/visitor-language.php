<?php
/**
 * Plugin Name:          Visitor Language
 * Plugin URI:           https://noxpress.tech
 * Description:          Μια αγγλική εκδοχή του site στο /en/, για επισκέπτες από το εξωτερικό: το WordPress, το WooCommerce, το καλάθι, το checkout, το θέμα και τα plugins Noxpress στα αγγλικά, και αγγλικά κείμενα μόνο για όσα προϊόντα και σελίδες θέλεις. Χωρίς αντίγραφα σελίδων, χωρίς υπηρεσία μετάφρασης, και το ελληνικό site μένει όπως είναι.
 * Version:              1.0.2
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://noxpress.tech/support/
 * Update URI:           https://noxpress.tech/updates/visitor-language
 * Text Domain:          visitor-language
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - visitor-language.php → bootstrap (constants, requires, the early
 *                           router boot, init wiring), vlg_lang(), vlg_url()
 *  - VLG_Lang      → EN dictionary + gettext filter (rs_lang, Bible §9)
 *  - VLG_Settings  → the vlg_settings option, strict validation
 *  - VLG_Router    → /en/ prefix: read and cut from REQUEST_URI while this
 *                    file loads, locale, home_url, redirect_canonical,
 *                    no page cache for English answers
 *  - VLG_Content   → English texts of posts / products (post meta), phrase
 *                    list, menus per location, "only in Greek" note,
 *                    EL / EN switch, hreflang
 *  - VLG_Orders    → the order's language: customer e-mails in English,
 *                    admin e-mails in the site language, return from a
 *                    payment provider to the /en/ thank-you page
 *  - VLG_Meta_Box  → the «English» box in the editor
 *  - VLG_Admin_UI  → menu, settings page
 *
 * WordPress-only plugin (Bible §1.2 exception): it works without
 * WooCommerce; product texts and shop labels only matter when it is there.
 * Capability manage_options (§22: it changes the whole site's front end).
 *
 * Front-end rules (Bible §14):
 *  - §14.1: nothing changes after activation; the switch starts off and
 *    /en/ answers 404 until it is turned on.
 *  - §14.3: VLG_DISABLE in wp-config.php stops every front-end hook.
 *  - §14.4: never admin, cron, CLI or XML-RPC; admin-ajax only when the
 *    referer is an /en/ page.
 *  - §14.5: a visitor request writes nothing to the database.
 *  - §14.6: every callback in try/catch(\Throwable): on an error the
 *    request stays Greek, as without the plugin.
 *  - §14.34: an English answer sets DONOTCACHEPAGE and no-cache headers,
 *    so a page cache never stores it under the Greek URL. The Greek pages
 *    stay cached; settings saves purge them (they carry the switch).
 *
 * Cooperation (Bible §6): vlg_lang(), vlg_url(), filter vlg_post_types.
 * Noxpress_Core::no_page_cache() and purge_page_cache().
 */

defined( 'ABSPATH' ) || exit;

define( 'VLG_VERSION', '1.0.2' );
define( 'VLG_FILE', __FILE__ );
define( 'VLG_PATH', plugin_dir_path( __FILE__ ) );
define( 'VLG_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16).
require_once VLG_PATH . 'includes/noxpress-core/loader.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', VLG_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', VLG_FILE, true );
		}
	}
);

require_once VLG_PATH . 'includes/class-lang.php';
require_once VLG_PATH . 'includes/class-settings.php';
require_once VLG_PATH . 'includes/class-router.php';

// The language of this request is decided now, before WordPress parses the
// request (plugins_loaded is too late for REQUEST_URI).
VLG_Router::boot();

final class Visitor_Language {

	const CAP = 'manage_options';

	public static function init(): void {
		VLG_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'visitor-language', false, dirname( plugin_basename( VLG_FILE ) ) . '/languages' );
				self::register_meta();
			}
		);

		if ( ! self::killed() ) {
			require_once VLG_PATH . 'includes/class-content.php';
			VLG_Content::init();
			if ( class_exists( 'WooCommerce' ) ) {
				require_once VLG_PATH . 'includes/class-orders.php';
				VLG_Orders::init();
			}
		}

		if ( is_admin() ) {
			require_once VLG_PATH . 'includes/class-content.php';
			require_once VLG_PATH . 'includes/class-meta-box.php';
			require_once VLG_PATH . 'includes/class-admin-ui.php';
			VLG_Meta_Box::init();
			VLG_Admin_UI::init();
		}
	}

	public static function killed(): bool {
		return VLG_Router::killed();
	}

	/** The English texts in the REST API, for whoever may edit that post (Site Bridge, apps). */
	private static function register_meta(): void {
		$types = array( 'page', 'post' );
		if ( post_type_exists( 'product' ) ) {
			$types[] = 'product';
		}
		foreach ( $types as $type ) {
			foreach ( array( '_vlg_title', '_vlg_excerpt', '_vlg_content' ) as $key ) {
				register_post_meta(
					$type,
					$key,
					array(
						'type'              => 'string',
						'single'            => true,
						'show_in_rest'      => true,
						'sanitize_callback' => '_vlg_title' === $key ? 'sanitize_text_field' : 'wp_kses_post',
						'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
							return current_user_can( 'edit_post', (int) $post_id );
						},
					)
				);
			}
		}
	}

	/** Purge the page cache (the Greek pages carry the switch and hreflang). */
	public static function purge(): array {
		if ( class_exists( 'Noxpress_Core' ) && method_exists( 'Noxpress_Core', 'purge_page_cache' ) ) {
			return Noxpress_Core::purge_page_cache();
		}
		do_action( 'noxpress_purge_page_cache', array() );
		return array();
	}

	public static function activate(): void {
		VLG_Settings::get();
	}

	public static function deactivate(): void {
		// Greek pages cached with the switch go away (§14.8).
		if ( VLG_Settings::get()['on'] ) {
			self::purge();
		}
	}
}

/**
 * The language of the current request, for other plugins (Bible §6).
 *
 * @return string 'el' | 'en'
 */
function vlg_lang(): string {
	return VLG_Router::lang();
}

/**
 * A URL of this site in one language.
 *
 * @param string $url  A URL of this site (with or without /en/).
 * @param string $lang 'el' | 'en'.
 */
function vlg_url( string $url, string $lang = 'en' ): string {
	return 'en' === $lang ? VLG_Router::add_prefix( $url ) : VLG_Router::remove_prefix( $url );
}

register_activation_hook( __FILE__, array( 'Visitor_Language', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Visitor_Language', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Visitor_Language', 'init' ), 20 );

<?php
/**
 * Plugin Name:          Events
 * Plugin URI:           https://noxpress.tech
 * Description:          Εκδηλώσεις (παρουσιάσεις, βραδιές, υπογραφές, συναυλίες, εργαστήρια, online) με ημερομηνία, χώρο, κατάσταση, είσοδο και σύνδεση με προϊόντα και δημιουργούς: λίστα επερχόμενων με shortcode και block, αρχείο, «Πρόσθεσε στο ημερολόγιο» (.ics και Google Calendar) και Event JSON-LD. Με το WooCommerce: εισιτήρια με θέσεις, κωδικούς και QR, είσοδος από κινητό και λίστα συμμετεχόντων.
 * Version:              1.1.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/events
 * Text Domain:          events
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - events.php    → bootstrap only (constants, requires, init wiring)
 *  - EVT_Lang      → EN dictionary + gettext filter (admin: rs_lang)
 *  - EVT_Settings  → the evt_settings option, strict validation, visitor
 *                    texts (EL / EN)
 *  - EVT_CPT       → the nox_event post type, the nox_venue taxonomy (with
 *                    address meta), the event meta and evt_get_event()
 *  - EVT_Query     → upcoming / past lists with filters (creator, product,
 *                    venue, city), always checked against the current time
 *  - EVT_Metabox   → the event edit screen and the venue term fields
 *  - EVT_Front     → the details box on the event page, [nx_events], the
 *                    «Επόμενες εκδηλώσεις» boxes on product and creator
 *                    pages, test mode, assets
 *  - EVT_Block     → the «Events» block (server-rendered)
 *  - EVT_Ics       → one .ics per event, the optional .ics feed, the Google
 *                    Calendar link
 *  - EVT_Schema    → Event / LiteraryEvent / MusicEvent JSON-LD (inside the
 *                    Search Signals or Rank Math graph, else our own)
 *  - EVT_Jobs      → page cache purges on save and the day after an event
 *                    ends (Action Scheduler or WP-Cron)
 *  - EVT_Admin_UI  → menu, list columns, settings, tools, footer
 *  - EVT_Abilities → events/list (read), events/create-draft and
 *                    events/tickets (read-only counts)
 *  - EVT_Ticket_Store → the {prefix}evt_tickets table, derived codes (1.1.0)
 *  - EVT_Tickets   → ticket products, sales window, codes on payment,
 *                    the ticket page, My account tab, privacy (1.1.0)
 *  - EVT_Checkin   → the «Ταμείο εκδήλωσης» role, the door page, the
 *                    attendee list, CSV, «notify the ticket holders» (1.1.0)
 *  - EVT_Email_Update → the WooCommerce e-mail of that notice (off by default)
 *
 * Front-end rules (Bible §14): test mode after activation (only managers
 * see event pages, lists and boxes); EVT_DISABLE in wp-config.php stops
 * every front-end hook; no cookie, no external request, no database write
 * on a visitor request; maps only as a link, never an embedded map.
 *
 * Cooperation (Bible §6): evt_get_event( $id ), evt_get_upcoming( $args );
 * action evt_event_published( $id ) the first time an event is published
 * (Broadcast), evt_event_changed( $id ) on every save; reads
 * crt_get_creators() only for names (Featured Creators), fires
 * noxpress_purge_page_cache (Fast Pages), noxpress_seo_graph and
 * noxpress_seo_sitemap_entry (Search Signals).
 *
 * Deviations from the Bible (with reason):
 *  - §12 (nothing left after Delete): events and venues are content the
 *    site wrote. They stay after uninstall unless the setting «Delete the
 *    events on uninstall» is on. Settings are always deleted.
 */

defined( 'ABSPATH' ) || exit;

define( 'EVT_VERSION', '1.1.0' );
define( 'EVT_FILE', __FILE__ );
define( 'EVT_PATH', plugin_dir_path( __FILE__ ) );
define( 'EVT_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16).
require_once EVT_PATH . 'includes/noxpress-core/loader.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', EVT_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', EVT_FILE, true );
		}
	}
);

require_once EVT_PATH . 'includes/class-lang.php';
require_once EVT_PATH . 'includes/class-settings.php';
require_once EVT_PATH . 'includes/class-cpt.php';
require_once EVT_PATH . 'includes/class-query.php';

final class Events_Plugin {

	public static function init(): void {
		EVT_Lang::init();
		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'events', false, dirname( plugin_basename( EVT_FILE ) ) . '/languages' );
			}
		);

		EVT_CPT::init();

		require_once EVT_PATH . 'includes/class-jobs.php';
		EVT_Jobs::init();

		require_once EVT_PATH . 'includes/class-ics.php';
		require_once EVT_PATH . 'includes/class-schema.php';
		if ( ! self::killed() ) {
			require_once EVT_PATH . 'includes/class-front.php';
			require_once EVT_PATH . 'includes/class-block.php';
			EVT_Front::init();
			EVT_Block::init();
			EVT_Ics::init();
			EVT_Schema::init();
		}

		if ( is_admin() ) {
			require_once EVT_PATH . 'includes/class-metabox.php';
			require_once EVT_PATH . 'includes/class-admin-ui.php';
			EVT_Metabox::init();
			EVT_Admin_UI::init();
		}

		if ( self::woo() ) {
			require_once EVT_PATH . 'includes/class-ticket-store.php';
			require_once EVT_PATH . 'includes/class-tickets.php';
			require_once EVT_PATH . 'includes/class-checkin.php';
			EVT_Tickets::init();
			EVT_Checkin::init();
		}

		require_once EVT_PATH . 'includes/class-abilities.php';
		EVT_Abilities::init();
	}

	/** True when EVT_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'EVT_DISABLE' ) && EVT_DISABLE;
	}

	/** Capability of the settings: the shop's, or the site's without WooCommerce. */
	public static function cap(): string {
		return class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	public static function woo(): bool {
		return class_exists( 'WooCommerce' );
	}
}

add_action( 'plugins_loaded', array( 'Events_Plugin', 'init' ), 20 );

if ( ! function_exists( 'evt_get_event' ) ) {
	/**
	 * One public event as an array (see EVT_CPT::event()), or null.
	 *
	 * @param int $event_id Event id.
	 */
	function evt_get_event( $event_id ): ?array {
		return EVT_CPT::event( (int) $event_id );
	}
}

if ( ! function_exists( 'evt_get_upcoming' ) ) {
	/**
	 * Upcoming public events (see EVT_Query::find()).
	 *
	 * @param array $args creator, product, venue, city, limit.
	 */
	function evt_get_upcoming( $args = array() ): array {
		return EVT_Query::find( is_array( $args ) ? $args : array() );
	}
}

register_activation_hook(
	EVT_FILE,
	static function () {
		EVT_CPT::register();
		flush_rewrite_rules( false );
		add_option( 'evt_settings', array( 'mode' => 'test' ), '', true );
		if ( class_exists( 'WooCommerce' ) ) {
			require_once EVT_PATH . 'includes/class-ticket-store.php';
			require_once EVT_PATH . 'includes/class-checkin.php';
			EVT_Ticket_Store::install();
			EVT_Checkin::ensure_role();
			add_option( 'evt_flush', '1', '', false );
		}
	}
);

register_deactivation_hook(
	EVT_FILE,
	static function () {
		flush_rewrite_rules( false );
	}
);

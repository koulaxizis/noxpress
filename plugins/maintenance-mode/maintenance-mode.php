<?php
/**
 * Plugin Name:          Maintenance Mode
 * Plugin URI:           https://noxpress.tech
 * Description:          Σελίδα «Σύντομα κοντά σας» ή «Συντήρηση» για τους επισκέπτες, ενώ οι διαχειριστές, όσοι έχουν σύνδεσμο προεπισκόπησης και οι IP που ορίζεις βλέπουν κανονικά το site. 503 με Retry-After για τη συντήρηση, noindex για νέο site, ώρα έναρξης και λήξης, καθάρισμα της page cache, και πληρωμές, REST και login που δεν κόβονται ποτέ.
 * Version:              1.0.3
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://noxpress.tech/support/
 * Update URI:           https://noxpress.tech/updates/maintenance-mode
 * Text Domain:          maintenance-mode
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - maintenance-mode.php → bootstrap only (constants, requires, init
 *                           wiring), public mmd_status()
 *  - MMD_Lang      → EN dictionary + gettext filter (rs_lang, Bible §9)
 *  - MMD_Settings  → the mmd_settings option, strict validation, the
 *                    state of this moment (on / scheduled / ended)
 *  - MMD_Access    → who passes: roles, IP list, preview links (signed
 *                    cookie, no database write on a visitor request)
 *  - MMD_Gate      → template_redirect (priority 0): the closed page,
 *                    robots.txt, feeds, wp/v2 REST for visitors, cache
 *  - MMD_Page      → the visitor page (own template, never the theme)
 *  - MMD_Import    → reads SeedProd Coming Soon 4.x–5.x and WP
 *                    Maintenance 2.x settings (never changes them)
 *  - MMD_Admin_UI  → menu, settings page, preview, admin bar, Site Health
 *  - MMD_Abilities → maintenance-mode/status, maintenance-mode/set
 *
 * WordPress-only plugin (Bible §1.2 exception): it works without
 * WooCommerce (no Requires Plugins, no WC headers); with WooCommerce the
 * order-received / order-pay pages stay open, so a payment in progress
 * finishes. Capability manage_options (§22: it changes the whole site).
 *
 * Front-end rules (Bible §14):
 *  - §14.1: nothing changes after activation; the switch starts off.
 *  - §14.3: MMD_DISABLE in wp-config.php stops every front-end hook.
 *  - §14.4: never admin, AJAX, cron, CLI or XML-RPC. REST stays open
 *    (payments, Site Bridge, Broadcast, apps); an optional switch closes
 *    only the wp/v2 routes to visitors who are not logged in.
 *  - §14.5: a visitor request writes nothing to the database. Start and
 *    end times are compared on every request, so the schedule works
 *    without cron; the page cache is purged on save, at the start / end
 *    (single cron events) and on the first admin request after them.
 *  - §14.6: every callback in try/catch(\Throwable): on an error the site
 *    stays open (never a closed site by accident).
 *  - §14.34: while it is on, every front-end answer sets DONOTCACHEPAGE;
 *    the preview cookie is in noxpress_private_cookies.
 *
 * Cooperation (Bible §6): filter noxpress_site_closed (what the current
 * request meets: '' | 'coming_soon' | 'maintenance'), read by Search
 * Signals 1.1.0+ to close sitemaps and llms.txt on a coming-soon site.
 * Noxpress_Core::purge_page_cache() and no_page_cache(). Easy Withdrawal:
 * a page with [nox_withdrawal] stays open (legal duty, design Q6).
 */

defined( 'ABSPATH' ) || exit;

define( 'MMD_VERSION', '1.0.3' );
define( 'MMD_FILE', __FILE__ );
define( 'MMD_PATH', plugin_dir_path( __FILE__ ) );
define( 'MMD_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once MMD_PATH . 'includes/noxpress-core/loader.php';

// WooCommerce compatibility: no order or cart data is read or written.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MMD_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MMD_FILE, true );
		}
	}
);

require_once MMD_PATH . 'includes/class-lang.php';
require_once MMD_PATH . 'includes/class-settings.php';
require_once MMD_PATH . 'includes/class-access.php';
require_once MMD_PATH . 'includes/class-page.php';
require_once MMD_PATH . 'includes/class-abilities.php';

final class Maintenance_Mode {

	const CAP  = 'manage_options';
	const CRON = 'mmd_edge';

	public static function init(): void {
		MMD_Lang::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'maintenance-mode', false, dirname( plugin_basename( MMD_FILE ) ) . '/languages' );
			}
		);

		// The preview cookie means «personal page» for every page cache (§14.34).
		add_filter( 'noxpress_private_cookies', array( 'MMD_Access', 'private_cookie' ) );
		// What the current request meets, for the suite (Bible §6).
		add_filter( 'noxpress_site_closed', array( __CLASS__, 'closed_filter' ) );
		// Start / end of a schedule: purge the page cache (no visitor writes).
		add_action( self::CRON, array( __CLASS__, 'edge' ) );

		// Emergency switch (Bible §14.3): no front-end hook at all.
		if ( ! self::killed() ) {
			require_once MMD_PATH . 'includes/class-gate.php';
			MMD_Gate::init();
		}

		MMD_Abilities::init();

		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 100 );
		add_action( 'wp_head', array( __CLASS__, 'admin_bar_css' ) );
		add_action( 'admin_head', array( __CLASS__, 'admin_bar_css' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'site_health' ) );

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'catch_up' ) );
			require_once MMD_PATH . 'includes/class-import.php';
			require_once MMD_PATH . 'includes/class-admin-ui.php';
			MMD_Admin_UI::init();
		}
	}

	/** True when MMD_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'MMD_DISABLE' ) && MMD_DISABLE;
	}

	/**
	 * Filter noxpress_site_closed: '' when the current request sees the
	 * site, otherwise the mode the visitor meets.
	 *
	 * @param mixed $value Earlier value (ignored unless ours says open).
	 * @return string
	 */
	public static function closed_filter( $value = '' ) {
		try {
			$st = mmd_status();
			if ( $st['active'] && ! MMD_Access::passes() ) {
				return $st['mode'];
			}
		} catch ( \Throwable $e ) {
			return is_string( $value ) ? $value : '';
		}
		return is_string( $value ) ? $value : '';
	}

	/** Purge the page cache now and remember the state it was purged for. */
	public static function purge(): array {
		update_option( 'mmd_purged', MMD_Settings::fingerprint(), false );
		if ( class_exists( 'Noxpress_Core' ) && method_exists( 'Noxpress_Core', 'purge_page_cache' ) ) {
			return Noxpress_Core::purge_page_cache();
		}
		do_action( 'noxpress_purge_page_cache', array() );
		return array();
	}

	/** Cron at a start or an end time. */
	public static function edge(): void {
		try {
			self::purge();
			self::schedule();
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Admin request: when a start / end passed since the last purge (cron
	 * missed it, e.g. every visitor got a cached page), purge now.
	 */
	public static function catch_up(): void {
		try {
			if ( current_user_can( self::CAP ) && get_option( 'mmd_purged', '' ) !== MMD_Settings::fingerprint() ) {
				self::purge();
				self::schedule();
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/** Single cron events at the coming start and end (none in the past). */
	public static function schedule(): void {
		wp_clear_scheduled_hook( self::CRON );
		$s   = MMD_Settings::get();
		$now = time();
		if ( ! $s['on'] ) {
			return;
		}
		foreach ( array( $s['start'], $s['end'] ) as $t ) {
			if ( $t > $now ) {
				wp_schedule_single_event( $t, self::CRON, array( $t ) );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Admin bar, Site Health
	 * ------------------------------------------------------------------- */

	/** Whether this user sees the admin bar badge. */
	private static function sees_badge(): bool {
		return is_user_logged_in() && ( current_user_can( self::CAP ) || MMD_Access::role_passes() );
	}

	/** @param WP_Admin_Bar $bar */
	public static function admin_bar( $bar ): void {
		try {
			if ( ! self::sees_badge() || ! is_object( $bar ) ) {
				return;
			}
			$st = mmd_status();
			if ( 'off' === $st['state'] || 'ended' === $st['state'] ) {
				return;
			}
			if ( 'scheduled' === $st['state'] ) {
				/* translators: %s: date and time */
				$title = sprintf( __( 'Συντήρηση από %s', 'maintenance-mode' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $st['start'] ) );
				$class = 'mmd-ab mmd-ab--soon';
			} else {
				$title = 'coming_soon' === $st['mode'] ? __( 'Σύντομα κοντά σας: ενεργό', 'maintenance-mode' ) : __( 'Συντήρηση ενεργή', 'maintenance-mode' );
				$class = 'mmd-ab mmd-ab--on';
			}
			$bar->add_node(
				array(
					'id'    => 'mmd-status',
					'title' => esc_html( $title ),
					'href'  => current_user_can( self::CAP ) ? admin_url( 'admin.php?page=mmd-settings' ) : false,
					'meta'  => array( 'class' => $class ),
				)
			);
		} catch ( \Throwable $e ) {
			return;
		}
	}

	public static function admin_bar_css(): void {
		if ( ! is_admin_bar_showing() || ! self::sees_badge() ) {
			return;
		}
		echo '<style id="mmd-ab">#wpadminbar .mmd-ab--on>.ab-item{background:#b32d2e!important;color:#fff!important}#wpadminbar .mmd-ab--soon>.ab-item{background:#996800!important;color:#fff!important}</style>' . "\n";
	}

	/** @param array $tests */
	public static function site_health( $tests ): array {
		$tests                             = is_array( $tests ) ? $tests : array();
		$tests['direct']['mmd_site_closed'] = array(
			'label' => 'Maintenance Mode',
			'test'  => array( __CLASS__, 'health_test' ),
		);
		return $tests;
	}

	public static function health_test(): array {
		$st   = mmd_status();
		$open = 'on' !== $st['state'];
		return array(
			'label'       => $open ? __( 'Το site είναι ανοιχτό στους επισκέπτες', 'maintenance-mode' ) : __( 'Το site είναι κλειστό στους επισκέπτες (Maintenance Mode)', 'maintenance-mode' ),
			'status'      => $open ? 'good' : 'recommended',
			'badge'       => array(
				'label' => 'Maintenance Mode',
				'color' => $open ? 'blue' : 'orange',
			),
			'description' => '<p>' . esc_html( $open ? __( 'Οι επισκέπτες βλέπουν κανονικά το site.', 'maintenance-mode' ) : __( 'Οι επισκέπτες βλέπουν τη σελίδα συντήρησης. Μην το ξεχάσεις ανοιχτό.', 'maintenance-mode' ) ) . '</p>',
			'actions'     => '<p><a href="' . esc_url( admin_url( 'admin.php?page=mmd-settings' ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'maintenance-mode' ) . '</a></p>',
			'test'        => 'mmd_site_closed',
		);
	}

	public static function activate(): void {
		MMD_Settings::get();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON );
		// The site opens at once (§14.8): pages cached while closed go away.
		if ( MMD_Settings::get()['on'] ) {
			self::purge();
		}
		delete_option( 'mmd_purged' );
	}
}

/**
 * The state of Maintenance Mode right now, for other plugins (Bible §6).
 *
 * @return array{state:string,active:bool,mode:string,start:int,end:int}
 *         state: off | scheduled (on, start in the future) | on | ended
 *         (on, end in the past); active: visitors meet the page now
 *         (false with MMD_DISABLE).
 */
function mmd_status(): array {
	$s  = MMD_Settings::get();
	$st = MMD_Settings::state( $s, time() );
	return array(
		'state'  => $st,
		'active' => 'on' === $st && ! Maintenance_Mode::killed(),
		'mode'   => $s['mode'],
		'start'  => (int) $s['start'],
		'end'    => (int) $s['end'],
	);
}

register_activation_hook( __FILE__, array( 'Maintenance_Mode', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Maintenance_Mode', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Maintenance_Mode', 'init' ), 20 );

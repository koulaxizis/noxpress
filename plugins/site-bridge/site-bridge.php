<?php
/**
 * Plugin Name:          Site Bridge
 * Plugin URI:           https://noxpress.tech
 * Description:          Το Claude διαχειρίζεται τα WordPress / WooCommerce sites σου μέσω MCP, από τους δικούς σου servers. Ένα Hub δέχεται τη σύνδεση του Claude (OAuth 2.1) και προωθεί κάθε κλήση, υπογεγραμμένη, στα sites. Κάθε site αποφασίζει μόνο του: ανάγνωση, εγγραφή ή διαχείριση, με παράθυρο εγγραφής που ανοίγεις εσύ. Ιστορικό κάθε κλήσης. Δουλεύει με το Claude, δεν σχετίζεται με την Anthropic.
 * Version:              1.4.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               Christos Koulaxizis
 * Author URI:           https://koulaxizis.gr
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Donate URI:           https://ko-fi.com/koulaxizis
 * Update URI:           https://noxpress.tech/updates/site-bridge
 * Text Domain:          site-bridge
 * Domain Path:          /languages
 *
 * Noxpress ecosystem (noxpress.tech) — design standard: Noxpress_bible.md.
 * Reference implementation: Revenue Splitter.
 *
 * Dispatch map:
 *  - site-bridge.php → bootstrap only (constants, requires, init wiring)
 *  - SBR_Lang      → EN dictionary + gettext filter (rs_lang, Bible §9)
 *  - SBR_Settings  → the sbr_settings option, strict validation, the
 *                    write window
 *  - SBR_Crypto    → Ed25519 keys (secret key sealed with the site's
 *                    salts), signatures, random tokens
 *  - SBR_Store     → tables sbr_log (audit) and sbr_grants (OAuth),
 *                    rate counters, purge
 *  - SBR_Guard     → SQL validator, secret redaction, PII masks, paths,
 *                    IP lists, blocked REST routes and options
 *  - SBR_Tools     → tool definitions and the read tools
 *  - SBR_Tools_Write → write and admin tools
 *  - SBR_Site      → Site role: signed endpoint, pairing, the checks
 *                    every tool call passes (level, window, scope, user)
 *  - SBR_Hub       → Hub role: site registry, signed dispatch, fleet calls
 *  - SBR_OAuth     → OAuth 2.1 authorization server (metadata, CIMD,
 *                    authorize + consent, token, optional DCR)
 *  - SBR_MCP       → MCP endpoint (Streamable HTTP, JSON responses)
 *  - SBR_Admin_UI  → menu, overview + diagnostics, access, sites,
 *                    connections, history, consent screen, footer
 *
 * Deviations from the Bible (with reason):
 *  - §1.2 (WooCommerce required): a WordPress plugin, it works with or
 *    without WooCommerce; WooCommerce data is reached through its REST
 *    routes when it is active (Bible 1.20 writes the exception).
 *  - §8 (capability manage_woocommerce): manage_options, remote access to
 *    the whole site.
 *  - §16 (catalog): in the Noxpress Core catalog since Core 1.1.17
 *    (until 1.2.0 it was for personal use only, Bible §21).
 *
 * Front end (Bible §14): nothing. Only REST routes under site-bridge/v1
 * and the /.well-known/ OAuth metadata paths answer; SBR_DISABLE in
 * wp-config.php turns every endpoint off.
 *
 * Bible §21 (remote access) is written from this plugin.
 */

defined( 'ABSPATH' ) || exit;

define( 'SBR_VERSION', '1.4.0' );
define( 'SBR_FILE', __FILE__ );
define( 'SBR_PATH', plugin_dir_path( __FILE__ ) );
define( 'SBR_URL', plugin_dir_url( __FILE__ ) );

// Noxpress Core: shared menu, hub and updates (Bible §16). The newest
// copy among the active Noxpress plugins is the one that loads.
require_once SBR_PATH . 'includes/noxpress-core/loader.php';

require_once SBR_PATH . 'includes/class-lang.php';
require_once SBR_PATH . 'includes/class-settings.php';
require_once SBR_PATH . 'includes/class-crypto.php';
require_once SBR_PATH . 'includes/class-store.php';
require_once SBR_PATH . 'includes/class-guard.php';
require_once SBR_PATH . 'includes/class-tools.php';
require_once SBR_PATH . 'includes/class-tools-write.php';
require_once SBR_PATH . 'includes/class-site.php';
require_once SBR_PATH . 'includes/class-hub.php';
require_once SBR_PATH . 'includes/class-oauth.php';
require_once SBR_PATH . 'includes/class-mcp.php';

/** A REST response printed as raw bytes by Site_Bridge::serve_raw(). */
final class SBR_Response extends WP_REST_Response {
	/** @var string */
	public $sbr_raw = '';
}

final class Site_Bridge {

	const REST_NS = 'site-bridge/v1';

	public static function init(): void {
		SBR_Lang::init();
		SBR_Store::init();

		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'site-bridge', false, dirname( plugin_basename( SBR_FILE ) ) . '/languages' );
			}
		);

		if ( ! self::killed() ) {
			SBR_Site::init();
			SBR_Hub::init();
			SBR_OAuth::init();
			SBR_MCP::init();
			add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_raw' ), 10, 4 );
		}

		if ( is_admin() ) {
			require_once SBR_PATH . 'includes/class-admin-ui.php';
			SBR_Admin_UI::init();
		}
	}

	/** True when SBR_DISABLE is defined and truthy in wp-config.php. */
	public static function killed(): bool {
		return defined( 'SBR_DISABLE' ) && SBR_DISABLE;
	}

	/** Full URL of a route of ours, e.g. rest('mcp'). */
	public static function rest( string $path ): string {
		return rest_url( self::REST_NS . '/' . ltrim( $path, '/' ) );
	}

	/**
	 * A REST response whose body we print byte for byte (MCP, OAuth, the
	 * signed site endpoint): signatures cover the exact bytes, and a 202
	 * must have no body at all.
	 */
	public static function raw( int $status, string $body, array $headers = array() ): SBR_Response {
		$res = new SBR_Response( null, $status );
		foreach ( $headers as $k => $v ) {
			$res->header( $k, $v );
		}
		$res->header( 'Cache-Control', 'no-store' );
		$res->header( 'X-Content-Type-Options', 'nosniff' );
		if ( '' !== $body && ! isset( $headers['Content-Type'] ) ) {
			$res->header( 'Content-Type', 'application/json; charset=utf-8' );
		}
		$res->sbr_raw = $body;
		return $res;
	}

	/** JSON for our raw responses: unescaped unicode and slashes. */
	public static function json( $data ): string {
		$out = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		return false === $out ? '{}' : $out;
	}

	/** rest_pre_serve_request: print raw bodies as they are. */
	public static function serve_raw( $served, $result, $request, $server ) {
		if ( $served || ! ( $result instanceof SBR_Response ) ) {
			return $served;
		}
		echo $result->sbr_raw; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON for API clients, not HTML.
		return true;
	}

	/** Client IP (REMOTE_ADDR only: forwarded headers are not trusted). */
	public static function ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
		return false === filter_var( $ip, FILTER_VALIDATE_IP ) ? '' : $ip;
	}

	public static function activate(): void {
		SBR_Store::install();
		SBR_Settings::first_run();
		SBR_Crypto::keys();
		SBR_Store::schedule();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( SBR_Store::CRON );
	}
}

register_activation_hook( __FILE__, array( 'Site_Bridge', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Site_Bridge', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Site_Bridge', 'init' ), 20 );

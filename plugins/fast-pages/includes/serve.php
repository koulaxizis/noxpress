<?php
/**
 * Fast Pages — the page cache answer, loaded by wp-content/advanced-cache.php
 * before WordPress (wp-settings.php, WP_CACHE). Plain PHP only.
 *
 * Reads the config the plugin writes (wp-content/cache/fast-pages/config.php),
 * finds the file of the request (FPG_Key) and, when it is there and fresh,
 * sends it and stops. Otherwise WordPress runs as usual and the plugin
 * writes the file at the end of the page (FPG_Cache).
 *
 * Header X-Fast-Pages: HIT, or BYPASS (<reason>) / MISS for the rest.
 * Test mode: files answer only ?fpg-test=1 (Bible §14.2). FPG_DISABLE in
 * wp-config.php stops everything here (Bible §14.3).
 */

defined( 'ABSPATH' ) || exit;

// No web request (WP-CLI, cron scripts): nothing to answer. Not PHP_SAPI,
// since some servers (php-wasm, embedded runtimes) report 'cli' for web requests.
if ( ( defined( 'FPG_DISABLE' ) && FPG_DISABLE ) || ( defined( 'WP_CLI' ) && WP_CLI ) || empty( $_SERVER['REQUEST_METHOD'] ) || ! defined( 'WP_CONTENT_DIR' ) ) {
	return;
}

require_once __DIR__ . '/class-key.php';

/**
 * Answer the request from the cache when possible. Never throws: any
 * problem means WordPress answers as if there were no cache.
 */
function fpg_serve(): void {
	$cfg_file = WP_CONTENT_DIR . '/cache/fast-pages/config.php';
	if ( ! is_file( $cfg_file ) ) {
		return;
	}
	$cfg = include $cfg_file;
	if ( ! is_array( $cfg ) || empty( $cfg['on'] ) || empty( $cfg['dir'] ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- FPG_Key only compares and hashes these values.
	$req = FPG_Key::request( $_SERVER, $_COOKIE, $cfg );
	$GLOBALS['fpg_request'] = $req;

	if ( ! $req['ok'] ) {
		fpg_header( 'BYPASS (' . $req['reason'] . ')' );
		return;
	}
	if ( ! empty( $cfg['test'] ) && ! $req['test'] ) {
		fpg_header( 'BYPASS (test mode)' );
		return;
	}
	$file = $req['file'];
	$time = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a missing file is the normal MISS.
	if ( false === $time ) {
		fpg_header( 'MISS' );
		return;
	}
	$life = isset( $cfg['life'] ) ? (int) $cfg['life'] : 0;
	if ( $life > 0 && $time + $life < time() ) {
		fpg_header( 'MISS (expired)' );
		return;
	}
	if ( headers_sent() ) {
		return;
	}
	$charset = isset( $cfg['charset'] ) && preg_match( '/^[A-Za-z0-9_-]{1,40}$/', (string) $cfg['charset'] ) ? $cfg['charset'] : 'UTF-8';
	header( 'Content-Type: text/html; charset=' . $charset );
	header( 'X-Fast-Pages: HIT' );
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $time ) . ' GMT' );
	header( 'Vary: Accept-Encoding' );
	if ( 'HEAD' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- no WordPress yet.
	}
	exit;
}

/** Sets the X-Fast-Pages header (the plugin may replace it later). */
function fpg_header( string $value ): void {
	if ( ! headers_sent() ) {
		header( 'X-Fast-Pages: ' . $value );
	}
}

try {
	fpg_serve();
} catch ( \Throwable $e ) {
	// No cache for this request.
	unset( $e );
}

<?php
/**
 * Noxpress Core loader (Bible §16).
 *
 * Every Noxpress plugin ships an identical copy of includes/noxpress-core/
 * and requires this file from its bootstrap. Each copy registers itself
 * as a candidate; on plugins_loaded (priority 1) the NEWEST copy is loaded,
 * once. The same pattern as WooCommerce's Action Scheduler.
 *
 * Keep this file tiny and stable: whichever copy is read first defines
 * noxpress_core_boot(), so its logic must work for every later version.
 *
 * When anything in this folder changes: bump the version key below, copy the
 * folder to every plugin (.github/scripts/sync-core.sh) and release them
 * all. The release workflow refuses to build when the copies differ.
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['noxpress_core_candidates']['1.0.3'] = __DIR__;

if ( ! function_exists( 'noxpress_core_boot' ) ) {

	/** Loads the newest registered Noxpress Core copy (once per request). */
	function noxpress_core_boot(): void {
		if ( defined( 'NOXPRESS_CORE' ) || empty( $GLOBALS['noxpress_core_candidates'] ) || ! is_array( $GLOBALS['noxpress_core_candidates'] ) ) {
			return;
		}

		$versions = array_map( 'strval', array_keys( $GLOBALS['noxpress_core_candidates'] ) );
		usort( $versions, 'version_compare' );
		$version = (string) end( $versions );
		$dir     = (string) $GLOBALS['noxpress_core_candidates'][ $version ];

		define( 'NOXPRESS_CORE', $version );
		define( 'NOXPRESS_CORE_PATH', $dir );

		require_once $dir . '/class-noxpress-core.php';
		Noxpress_Core::init();
	}

	add_action( 'plugins_loaded', 'noxpress_core_boot', 1 );
}

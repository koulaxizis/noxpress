<?php
/**
 * PHPStan bootstrap for the «PHP checks» workflow (audit 2026-11, I2).
 *
 * Each plugin defines its constants with define() in its own bootstrap
 * file, which PHPStan reads but does not run, and Noxpress Core defines
 * NOXPRESS_CORE / NOXPRESS_CORE_PATH inside noxpress_core_boot(). Without
 * this file every use is reported as "Constant ... not found", and every
 * require_once of PREFIX_PATH . '...' as "not a file". Here each constant
 * a suite file passes to define() gets a value like the real one: *_FILE
 * the plugin's main file, *_PATH its folder, *_URL a URL, the rest ''.
 * Also a few wp-config.php constants the WordPress stubs leave out.
 */

$noxpress_qa_root = dirname( __DIR__, 2 ) . '/plugins';
$noxpress_qa_skip = array( 'DONOTCACHEPAGE', 'SHORTINIT', 'WP_CACHE' ); // WordPress's own.

foreach ( glob( $noxpress_qa_root . '/*', GLOB_ONLYDIR ) as $noxpress_qa_dir ) {
	$noxpress_qa_slug = basename( $noxpress_qa_dir );
	$noxpress_qa_main = $noxpress_qa_dir . '/' . $noxpress_qa_slug . '.php';
	$noxpress_qa_it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $noxpress_qa_dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $noxpress_qa_it as $noxpress_qa_file ) {
		$noxpress_qa_path = $noxpress_qa_file->getPathname();
		if ( 'php' !== $noxpress_qa_file->getExtension() || false !== strpos( $noxpress_qa_path, '/vendor/' ) ) {
			continue;
		}
		if ( ! preg_match_all( "/\\bdefine\\(\\s*'([A-Z][A-Z0-9_]*)'/", (string) file_get_contents( $noxpress_qa_path ), $noxpress_qa_m ) ) {
			continue;
		}
		foreach ( $noxpress_qa_m[1] as $noxpress_qa_name ) {
			if ( defined( $noxpress_qa_name ) || in_array( $noxpress_qa_name, $noxpress_qa_skip, true ) || 0 === strpos( $noxpress_qa_name, 'NOXPRESS_CORE' ) ) {
				continue;
			}
			if ( '_FILE' === substr( $noxpress_qa_name, -5 ) ) {
				define( $noxpress_qa_name, $noxpress_qa_main );
			} elseif ( '_PATH' === substr( $noxpress_qa_name, -5 ) ) {
				define( $noxpress_qa_name, $noxpress_qa_dir . '/' );
			} elseif ( '_URL' === substr( $noxpress_qa_name, -4 ) ) {
				define( $noxpress_qa_name, 'https://example.test/wp-content/plugins/' . $noxpress_qa_slug . '/' );
			} else {
				define( $noxpress_qa_name, '' );
			}
		}
	}
}

// Noxpress Core: the analysed copy is Revenue Splitter's (the source).
define( 'NOXPRESS_CORE', '0' );
define( 'NOXPRESS_CORE_PATH', $noxpress_qa_root . '/revenue-splitter/includes/noxpress-core' );

// wp-config.php / default-constants.php values missing from the WordPress stubs.
foreach ( array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'COOKIEPATH' ) as $noxpress_qa_name ) {
	if ( ! defined( $noxpress_qa_name ) ) {
		define( $noxpress_qa_name, '' );
	}
}

unset( $noxpress_qa_root, $noxpress_qa_skip, $noxpress_qa_dir, $noxpress_qa_slug, $noxpress_qa_main, $noxpress_qa_it, $noxpress_qa_file, $noxpress_qa_path, $noxpress_qa_m, $noxpress_qa_name );

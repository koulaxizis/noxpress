<?php
/**
 * PHPStan bootstrap for the «PHP checks» workflow (audit 2026-11, I2).
 *
 * Each plugin defines its constants with define() in its own bootstrap
 * file, which PHPStan reads but does not run, and Noxpress Core defines
 * NOXPRESS_CORE / NOXPRESS_CORE_PATH inside noxpress_core_boot(). Without
 * this file every use is reported as "Constant ... not found". Here every
 * constant a suite file passes to define() is declared once, as a string,
 * except WordPress's own (they come from the WordPress stubs).
 */

$noxpress_qa_wp = array( 'DONOTCACHEPAGE', 'SHORTINIT', 'WP_CACHE' );

$noxpress_qa_root = dirname( __DIR__, 2 ) . '/plugins';
$noxpress_qa_it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $noxpress_qa_root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $noxpress_qa_it as $noxpress_qa_file ) {
	if ( 'php' !== $noxpress_qa_file->getExtension() || false !== strpos( $noxpress_qa_file->getPathname(), '/vendor/' ) ) {
		continue;
	}
	if ( preg_match_all( "/\\bdefine\\(\\s*'([A-Z][A-Z0-9_]*)'/", (string) file_get_contents( $noxpress_qa_file->getPathname() ), $noxpress_qa_m ) ) {
		foreach ( $noxpress_qa_m[1] as $noxpress_qa_name ) {
			if ( ! defined( $noxpress_qa_name ) && ! in_array( $noxpress_qa_name, $noxpress_qa_wp, true ) ) {
				define( $noxpress_qa_name, '' );
			}
		}
	}
}
unset( $noxpress_qa_wp, $noxpress_qa_root, $noxpress_qa_it, $noxpress_qa_file, $noxpress_qa_m, $noxpress_qa_name );

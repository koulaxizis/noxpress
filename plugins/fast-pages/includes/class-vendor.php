<?php
/**
 * FPG_Vendor — loads the bundled minifier (assets/vendor, Bible §10) on
 * the first use only, without Composer. See LICENSES.md.
 */

defined( 'ABSPATH' ) || exit;

final class FPG_Vendor {

	/** Class => file, in load order. */
	const FILES = array(
		'MatthiasMullie\PathConverter\ConverterInterface'      => 'path-converter/src/ConverterInterface.php',
		'MatthiasMullie\PathConverter\Converter'               => 'path-converter/src/Converter.php',
		'MatthiasMullie\PathConverter\NoConverter'             => 'path-converter/src/NoConverter.php',
		'MatthiasMullie\Minify\Exception'                      => 'minify/src/Exception.php',
		'MatthiasMullie\Minify\Exceptions\BasicException'      => 'minify/src/Exceptions/BasicException.php',
		'MatthiasMullie\Minify\Exceptions\FileImportException' => 'minify/src/Exceptions/FileImportException.php',
		'MatthiasMullie\Minify\Exceptions\IOException'         => 'minify/src/Exceptions/IOException.php',
		'MatthiasMullie\Minify\Exceptions\PatternMatchException' => 'minify/src/Exceptions/PatternMatchException.php',
		'MatthiasMullie\Minify\Minify'                         => 'minify/src/Minify.php',
		'MatthiasMullie\Minify\CSS'                            => 'minify/src/CSS.php',
		'MatthiasMullie\Minify\JS'                             => 'minify/src/JS.php',
	);

	/**
	 * Another plugin may ship the same library (often an older copy):
	 * a class that already exists is used as it is, never declared twice.
	 */
	public static function load(): void {
		foreach ( self::FILES as $class => $file ) {
			if ( ! class_exists( $class ) && ! interface_exists( $class ) ) {
				require_once FPG_PATH . 'assets/vendor/' . $file;
			}
		}
	}
}

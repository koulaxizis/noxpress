<?php
/**
 * BPV_Engine — makes one sample file.
 *
 * Order:
 *  1. Own engine (BPV_Pdf_*): pure PHP, works on every host.
 *  2. qpdf, when the own engine cannot read the file (e.g. encrypted with
 *     an owner password only) and qpdf exists and may run. Its output is
 *     passed once more through the own engine, which prunes unused images.
 *  3. Ghostscript, as the last resort (it rewrites the pages).
 *
 * External tools run only when the setting allows it, proc_open is not
 * disabled and the binary is found; arguments go as an array (no shell)
 * and a time limit applies. Every result is checked by reading it back
 * with the own engine before it replaces anything.
 */

defined( 'ABSPATH' ) || exit;

require_once BPV_PATH . 'includes/class-pdf.php';

final class BPV_Engine {

	const TIMEOUT = 120;

	/**
	 * Pages in a PDF, or an error.
	 *
	 * @return array{ok:bool, total:int, error:string}
	 */
	public static function count( string $path ): array {
		self::prepare();
		try {
			$r = new BPV_Pdf_Reader( $path );
			return array(
				'ok'    => true,
				'total' => count( $r->pages() ),
				'error' => '',
			);
		} catch ( BPV_Pdf_Error $e ) {
			if ( in_array( $e->reason, array( 'encrypted', 'damaged', 'unsupported' ), true ) && BPV_Settings::get()['external'] ) {
				$cmds = array();
				if ( self::tool( 'qpdf' ) ) {
					$cmds[] = array( self::tool( 'qpdf' ), '--show-npages', $path );
				}
				if ( self::tool( 'gs' ) ) {
					// PostScript string: escape \ ( ). SAFER stays on; only this file may be read.
					$ps     = '(' . addcslashes( $path, '\\()' ) . ') (r) file runpdfbegin pdfpagecount = quit';
					$cmds[] = array( self::tool( 'gs' ), '-q', '-dNODISPLAY', '-dBATCH', '-dNOPAUSE', '--permit-file-read=' . $path, '-c', $ps );
				}
				foreach ( $cmds as $cmd ) {
					$out = self::run( $cmd );
					if ( 0 === $out['code'] && preg_match( '/^\s*(\d+)\s*$/', $out['out'], $m ) && (int) $m[1] > 0 ) {
						return array(
							'ok'    => true,
							'total' => (int) $m[1],
							'error' => '',
						);
					}
				}
			}
			return array(
				'ok'    => false,
				'total' => 0,
				'error' => $e->reason,
			);
		} catch ( \Throwable $e ) {
			return array(
				'ok'    => false,
				'total' => 0,
				'error' => 'damaged',
			);
		}
	}

	/**
	 * Writes the chosen pages of $src to $dest.
	 *
	 * @param int[] $pages 1-based page numbers, ascending.
	 * @return array{ok:bool, count:int, engine:string, error:string}
	 *         engine: native | qpdf | gs; error: not_pdf | encrypted | damaged | unsupported | io | no_pages
	 */
	public static function make( string $src, array $pages, string $dest ): array {
		self::prepare();
		$tmp   = $dest . '.tmp';
		$index = array_map(
			static function ( $p ) {
				return (int) $p - 1;
			},
			$pages
		);

		$error = 'damaged';
		try {
			$r = new BPV_Pdf_Reader( $src );
			$w = new BPV_Pdf_Writer( $r );
			$w->write( $index, $tmp );
			unset( $r, $w );
			return self::finish( $tmp, $dest, count( $pages ), 'native' );
		} catch ( BPV_Pdf_Error $e ) {
			$error = $e->reason;
		} catch ( \Throwable $e ) {
			$error = 'damaged';
		}
		self::unlink( $tmp );
		if ( 'not_pdf' === $error || 'io' === $error || ! BPV_Settings::get()['external'] ) {
			return self::fail( $error );
		}

		$list = BPV_Pages::compact( $pages );
		$list = str_replace( ' ', '', $list );

		$qpdf = self::tool( 'qpdf' );
		if ( '' !== $qpdf ) {
			$mid = $dest . '.qpdf.tmp';
			$run = self::run( array( $qpdf, '--empty', '--pages', $src, $list, '--', $mid ) );
			// qpdf exits with 3 for warnings: the file is written.
			if ( in_array( $run['code'], array( 0, 3 ), true ) && is_file( $mid ) ) {
				return self::rewrite( $mid, $tmp, $dest, count( $pages ), 'qpdf' );
			}
			self::unlink( $mid );
		}

		$gs = self::tool( 'gs' );
		if ( '' !== $gs ) {
			$mid = $dest . '.gs.tmp';
			$run = self::run( array( $gs, '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=pdfwrite', '-dPDFSETTINGS=/default', '-sPageList=' . $list, '-sOutputFile=' . $mid, $src ) );
			if ( 0 === $run['code'] && is_file( $mid ) ) {
				return self::rewrite( $mid, $tmp, $dest, count( $pages ), 'gs' );
			}
			self::unlink( $mid );
		}
		return self::fail( $error );
	}

	/**
	 * Passes a tool's output through our writer: unused resources go, and
	 * links the tool left to dropped pages (Ghostscript keeps outline
	 * entries that point to missing objects) are cut. When our reader
	 * cannot read it, the tool's own file is used as it is.
	 */
	private static function rewrite( string $mid, string $tmp, string $dest, int $expect, string $engine ): array {
		try {
			$r = new BPV_Pdf_Reader( $mid );
			$w = new BPV_Pdf_Writer( $r );
			$w->write( range( 0, count( $r->pages() ) - 1 ), $tmp );
			unset( $r, $w );
			self::unlink( $mid );
			return self::finish( $tmp, $dest, $expect, $engine );
		} catch ( \Throwable $e ) {
			self::unlink( $tmp );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.rename_rename
			if ( @rename( $mid, $tmp ) ) {
				return self::finish( $tmp, $dest, $expect, $engine );
			}
			self::unlink( $mid );
			return self::fail( 'io' );
		}
	}

	/** Reads the new file back; moves it into place when it has the right page count. */
	private static function finish( string $tmp, string $dest, int $expect, string $engine ): array {
		try {
			$check = new BPV_Pdf_Reader( $tmp );
			$got   = count( $check->pages() );
			unset( $check );
		} catch ( \Throwable $e ) {
			$got = -1;
		}
		if ( $got !== $expect ) {
			self::unlink( $tmp );
			return self::fail( 'damaged' );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace.
		if ( ! @rename( $tmp, $dest ) ) {
			self::unlink( $tmp );
			return self::fail( 'io' );
		}
		return array(
			'ok'     => true,
			'count'  => $got,
			'engine' => $engine,
			'error'  => '',
		);
	}

	private static function fail( string $error ): array {
		return array(
			'ok'     => false,
			'count'  => 0,
			'engine' => '',
			'error'  => $error,
		);
	}

	private static function unlink( string $path ): void {
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/** More time and memory for big books, where the host allows it. */
	private static function prepare(): void {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/* ---------------------------------------------------------------------
	 * External tools
	 * ------------------------------------------------------------------- */

	public static function can_exec(): bool {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}
		$off = array_map( 'trim', explode( ',', strtolower( (string) ini_get( 'disable_functions' ) ) ) );
		return ! in_array( 'proc_open', $off, true );
	}

	/** Full path of qpdf or gs, or ''. Filter `bpv_tool_path` sets a path by hand. */
	public static function tool( string $name ): string {
		static $found = array();
		if ( isset( $found[ $name ] ) ) {
			return $found[ $name ];
		}
		$found[ $name ] = '';
		if ( ! self::can_exec() ) {
			return '';
		}
		$custom = (string) apply_filters( 'bpv_tool_path', '', $name );
		$cands  = '' !== $custom ? array( $custom ) : array( '/usr/bin/' . $name, '/usr/local/bin/' . $name, '/opt/homebrew/bin/' . $name, '/bin/' . $name );
		foreach ( $cands as $c ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors -- open_basedir may hide the folder.
			if ( @is_file( $c ) && @is_executable( $c ) ) {
				$found[ $name ] = $c;
				break;
			}
		}
		return $found[ $name ];
	}

	/** Version line of a tool, for the diagnostics. */
	public static function tool_version( string $name ): string {
		$path = self::tool( $name );
		if ( '' === $path ) {
			return '';
		}
		$run = self::run( array( $path, '--version' ), 10 );
		return 0 === $run['code'] ? trim( strtok( $run['out'], "\n" ) ) : '';
	}

	/**
	 * Runs a command without a shell.
	 *
	 * @return array{code:int, out:string}
	 */
	private static function run( array $cmd, int $timeout = self::TIMEOUT ): array {
		$spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- external PDF tools, array form (no shell).
		$proc = @proc_open( $cmd, $spec, $pipes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_resource( $proc ) ) {
			return array(
				'code' => -1,
				'out'  => '',
			);
		}
		fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		$out   = '';
		$start = time();
		$code  = -1;
		while ( true ) {
			$out .= (string) stream_get_contents( $pipes[1] );
			stream_get_contents( $pipes[2] );
			$st = proc_get_status( $proc );
			if ( ! $st['running'] ) {
				$code = (int) $st['exitcode'];
				$out .= (string) stream_get_contents( $pipes[1] );
				break;
			}
			if ( time() - $start > $timeout ) {
				proc_terminate( $proc );
				break;
			}
			usleep( 50000 );
		}
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		proc_close( $proc );
		return array(
			'code' => $code,
			'out'  => $out,
		);
	}
}

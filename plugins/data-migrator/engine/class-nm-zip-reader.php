<?php
/**
 * NM_Zip_Reader — reads the archives NM_Zip_Writer makes (and plain ZIP
 * files with stored / deflated entries, ZIP64 included).
 *
 * The central directory is walked from a byte position, so a caller can
 * stop after any entry and continue in the next request. Extraction of a
 * stored entry can also stop halfway and continue from the same byte.
 * Every extracted entry is checked against its CRC-32.
 */

defined( 'ABSPATH' ) || defined( 'NM_STANDALONE' ) || exit;

final class NM_Zip_Reader {

	const CHUNK = 1048576;

	/** @var resource|null */
	private $fh = null;
	/** @var string */
	private $path;

	public $cd_off  = 0;
	public $cd_size = 0;
	public $count   = 0;
	public $error   = '';

	public function __construct( string $path ) {
		$this->path = $path;
	}

	public function open(): bool {
		$this->fh = @fopen( $this->path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $this->fh ) {
			$this->error = 'open';
			return false;
		}
		$size = (int) filesize( $this->path );
		if ( $size < 22 ) {
			$this->error = 'short';
			return false;
		}
		$tail = min( $size, 65557 );
		fseek( $this->fh, $size - $tail );
		$buf = (string) fread( $this->fh, $tail );
		$pos = strrpos( $buf, "PK\x05\x06" );
		if ( false === $pos || strlen( $buf ) - $pos < 22 ) {
			$this->error = 'eocd';
			return false;
		}
		$e             = unpack( 'Vsig/vdisk/vcddisk/vnd/vn/Vcdsize/Vcdoff/vclen', substr( $buf, $pos, 22 ) );
		$this->count   = (int) $e['n'];
		$this->cd_size = (int) $e['cdsize'];
		$this->cd_off  = (int) $e['cdoff'];
		$eocd_at       = $size - $tail + $pos;
		if ( 0xFFFF === $this->count || 0xFFFFFFFF === $this->cd_off || 0xFFFFFFFF === $this->cd_size ) {
			if ( $eocd_at < 20 ) {
				$this->error = 'zip64';
				return false;
			}
			fseek( $this->fh, $eocd_at - 20 );
			$loc = unpack( 'Vsig/Vdisk/Poff/Vdisks', (string) fread( $this->fh, 20 ) );
			if ( 0x07064b50 !== $loc['sig'] ) {
				$this->error = 'zip64';
				return false;
			}
			fseek( $this->fh, (int) $loc['off'] );
			$z = unpack( 'Vsig/Psize/vmade/vneed/Vdisk/Vcddisk/Pnd/Pn/Pcdsize/Pcdoff', (string) fread( $this->fh, 56 ) );
			if ( 0x06064b50 !== $z['sig'] ) {
				$this->error = 'zip64';
				return false;
			}
			$this->count   = (int) $z['n'];
			$this->cd_size = (int) $z['cdsize'];
			$this->cd_off  = (int) $z['cdoff'];
		}
		if ( $this->cd_off + $this->cd_size > $size ) {
			$this->error = 'cd';
			return false;
		}
		return true;
	}

	public function close(): void {
		if ( $this->fh ) {
			fclose( $this->fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		$this->fh = null;
	}

	/**
	 * The entry whose central record starts at $pos (0 = first), or null
	 * at the end. $next receives the position of the following record.
	 *
	 * @return array|null name, method, crc, csize, usize, lho, flags
	 */
	public function entry_at( int $pos, ?int &$next = null ) {
		if ( $pos >= $this->cd_size ) {
			return null;
		}
		fseek( $this->fh, $this->cd_off + $pos );
		$raw = (string) fread( $this->fh, 46 );
		if ( strlen( $raw ) < 46 ) {
			return null;
		}
		$h = unpack( 'Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/vint/Vext/Vlho', $raw );
		if ( 0x02014b50 !== $h['sig'] ) {
			$this->error = 'cd';
			return null;
		}
		$name  = (string) fread( $this->fh, (int) $h['nlen'] );
		$extra = $h['elen'] ? (string) fread( $this->fh, (int) $h['elen'] ) : '';
		$csize = (int) $h['csize'];
		$usize = (int) $h['usize'];
		$lho   = (int) $h['lho'];
		// ZIP64 extended information: only the fields that overflowed.
		$p = 0;
		while ( $p + 4 <= strlen( $extra ) ) {
			$x = unpack( 'vid/vlen', substr( $extra, $p, 4 ) );
			if ( 0x0001 === $x['id'] ) {
				$f = substr( $extra, $p + 4, (int) $x['len'] );
				$q = 0;
				if ( 0xFFFFFFFF === $usize ) {
					$usize = (int) unpack( 'P', substr( $f, $q, 8 ) )[1];
					$q    += 8;
				}
				if ( 0xFFFFFFFF === $csize ) {
					$csize = (int) unpack( 'P', substr( $f, $q, 8 ) )[1];
					$q    += 8;
				}
				if ( 0xFFFFFFFF === $lho ) {
					$lho = (int) unpack( 'P', substr( $f, $q, 8 ) )[1];
				}
				break;
			}
			$p += 4 + (int) $x['len'];
		}
		$next = $pos + 46 + (int) $h['nlen'] + (int) $h['elen'] + (int) $h['clen'];
		return array(
			'name'   => $name,
			'method' => (int) $h['method'],
			'crc'    => (int) $h['crc'],
			'csize'  => $csize,
			'usize'  => $usize,
			'lho'    => $lho,
			'flags'  => (int) $h['flags'],
		);
	}

	/** All entries (only for small archives or tests). */
	public function entries(): array {
		$out = array();
		$pos = 0;
		$e   = $this->entry_at( $pos, $next );
		while ( $e ) {
			$out[] = $e;
			$pos   = (int) $next;
			$e     = $this->entry_at( $pos, $next );
		}
		return $out;
	}

	/** Find one entry by name (walks the directory). */
	public function find( string $name ) {
		$pos = 0;
		$e   = $this->entry_at( $pos, $next );
		while ( $e ) {
			if ( $e['name'] === $name ) {
				return $e;
			}
			$pos = (int) $next;
			$e   = $this->entry_at( $pos, $next );
		}
		return null;
	}

	private function data_offset( array $e ): int {
		fseek( $this->fh, $e['lho'] );
		$raw = (string) fread( $this->fh, 30 );
		if ( strlen( $raw ) < 30 ) {
			return -1;
		}
		$h = unpack( 'Vsig/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen', $raw );
		if ( 0x04034b50 !== $h['sig'] ) {
			return -1;
		}
		return $e['lho'] + 30 + (int) $h['nlen'] + (int) $h['elen'];
	}

	/** The whole entry as a string (small entries: manifest, SQL chunks). */
	public function read( array $e ) {
		if ( $e['usize'] > 268435456 || ( 0 !== $e['method'] && 8 !== $e['method'] ) || ( $e['flags'] & 1 ) ) {
			return false;
		}
		$at = $this->data_offset( $e );
		if ( $at < 0 ) {
			return false;
		}
		fseek( $this->fh, $at );
		$data = $e['csize'] > 0 ? (string) fread( $this->fh, $e['csize'] ) : '';
		if ( strlen( $data ) !== $e['csize'] ) {
			return false;
		}
		if ( 8 === $e['method'] ) {
			$data = @gzinflate( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $data ) {
				return false;
			}
		}
		if ( strlen( $data ) !== $e['usize'] || ( crc32( $data ) & 0xFFFFFFFF ) !== ( $e['crc'] & 0xFFFFFFFF ) ) {
			return false;
		}
		return $data;
	}

	/**
	 * Extract an entry to $dest. $done is the byte count already written
	 * (stored entries only; deflated entries always start over).
	 * Returns 'done', 'paused', or an error code ('crc', 'read', 'write',
	 * 'method').
	 */
	public function extract( array $e, string $dest, int &$done, float $deadline ): string {
		if ( ( 0 !== $e['method'] && 8 !== $e['method'] ) || ( $e['flags'] & 1 ) ) {
			return 'method';
		}
		$at = $this->data_offset( $e );
		if ( $at < 0 ) {
			return 'read';
		}
		if ( 8 === $e['method'] ) {
			$done = 0;
		}
		$out = @fopen( $dest, $done > 0 ? 'r+b' : 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $out ) {
			return 'write';
		}
		if ( $done > 0 ) {
			ftruncate( $out, $done );
			fseek( $out, $done );
		}

		if ( 0 === $e['method'] ) {
			fseek( $this->fh, $at + $done );
			while ( $done < $e['usize'] ) {
				$buf = fread( $this->fh, (int) min( self::CHUNK, $e['usize'] - $done ) );
				if ( false === $buf || '' === $buf ) {
					fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return 'read';
				}
				if ( fwrite( $out, $buf ) !== strlen( $buf ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return 'write';
				}
				$done += strlen( $buf );
				if ( $done < $e['usize'] && microtime( true ) >= $deadline ) {
					fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return 'paused';
				}
			}
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$crc = @hash_file( 'crc32b', $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return ( false !== $crc && hexdec( $crc ) === ( $e['crc'] & 0xFFFFFFFF ) ) ? 'done' : 'crc';
		}

		// Deflated: stream through inflate_add with a running CRC.
		$ctx  = inflate_init( ZLIB_ENCODING_RAW );
		$hash = hash_init( 'crc32b' );
		$left = $e['csize'];
		fseek( $this->fh, $at );
		$total = 0;
		while ( $left > 0 ) {
			$buf = fread( $this->fh, (int) min( self::CHUNK, $left ) );
			if ( false === $buf || '' === $buf ) {
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return 'read';
			}
			$left -= strlen( $buf );
			$plain = @inflate_add( $ctx, $buf, $left > 0 ? ZLIB_SYNC_FLUSH : ZLIB_FINISH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $plain ) {
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return 'crc';
			}
			hash_update( $hash, $plain );
			$total += strlen( $plain );
			if ( '' !== $plain && fwrite( $out, $plain ) !== strlen( $plain ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return 'write';
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$done = $total;
		return ( $total === $e['usize'] && hexdec( hash_final( $hash ) ) === ( $e['crc'] & 0xFFFFFFFF ) ) ? 'done' : 'crc';
	}
}

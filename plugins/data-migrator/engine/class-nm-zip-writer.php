<?php
/**
 * NM_Zip_Writer — resumable, streaming ZIP writer (PKWARE APPNOTE 6.3).
 *
 * - Stored entries for large or already compressed files, raw DEFLATE
 *   (gzdeflate) for small text files. ZIP64 records whenever a size, an
 *   offset or the entry count needs them.
 * - Sizes and CRC are known before an entry is written (hash_file for
 *   large files), so no data descriptors and no seeking back.
 * - Central directory records go to a sidecar file as each entry is
 *   written. The caller saves state() after every add; on the next
 *   request open() truncates the archive and the sidecar to the saved
 *   offsets, so a request that died halfway leaves no broken entry.
 * - A large file can span requests: add_file() stops at the deadline and
 *   continues from the same byte next time.
 *
 * - With a password (state 'enc'), every entry is AES-256 encrypted in
 *   the WinZip AE-2 format (NM_Crypt). The password itself is never in
 *   the state: the caller gives it with set_password() every request.
 *
 * Names are UTF-8 (flag bit 11), always relative with forward slashes.
 */

defined( 'ABSPATH' ) || defined( 'NM_STANDALONE' ) || exit;

final class NM_Zip_Writer {

	/** Files up to this size may be deflated in memory. */
	const DEFLATE_MAX = 4194304;

	/** Copy buffer. */
	const CHUNK = 1048576;

	/** Extensions that are compressed already: always stored. */
	const NO_DEFLATE = '/\.(jpe?g|png|gif|webp|avif|heic|ico|zip|gz|tgz|bz2|xz|7z|rar|mp3|mp4|m4a|m4v|mov|webm|ogg|ogv|oga|wav|flac|avi|mkv|pdf|woff2?|eot|ttf|otf|docx|xlsx|pptx|odt|ods|jar|phar|br)$/i';

	/** @var resource|null */
	private $zh = null;
	/** @var resource|null */
	private $ch = null;
	/** @var array */
	private $st;
	/** @var string */
	private $pw = '';

	/**
	 * @param array $state path, cd (sidecar), offset, cd_size, count, cur.
	 */
	public function __construct( array $state ) {
		$this->st = array_merge(
			array(
				'path'    => '',
				'cd'      => '',
				'offset'  => 0,
				'cd_size' => 0,
				'count'   => 0,
				'cur'     => null,
				'enc'     => false,
			),
			$state
		);
	}

	/** The password for an encrypted archive (needed before open()). */
	public function set_password( string $password ): void {
		$this->pw = $password;
	}

	/** Open both files and drop anything written after the saved state. */
	public function open(): bool {
		if ( ! empty( $this->st['enc'] ) && ( '' === $this->pw || ! NM_Crypt::available() ) ) {
			return false;
		}
		$this->zh = @fopen( $this->st['path'], 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$this->ch = @fopen( $this->st['cd'], 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $this->zh || ! $this->ch ) {
			return false;
		}
		ftruncate( $this->zh, (int) $this->st['offset'] );
		ftruncate( $this->ch, (int) $this->st['cd_size'] );
		fseek( $this->zh, 0, SEEK_END );
		fseek( $this->ch, 0, SEEK_END );
		return true;
	}

	public function close(): void {
		if ( $this->zh ) {
			fclose( $this->zh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( $this->ch ) {
			fclose( $this->ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		$this->zh = null;
		$this->ch = null;
	}

	public function state(): array {
		return $this->st;
	}

	/** Bytes written to the archive so far. */
	public function size(): int {
		return (int) $this->st['offset'];
	}

	public function busy(): bool {
		return ! empty( $this->st['cur'] );
	}

	/**
	 * Add a string as an entry (manifest, SQL chunks). $plain keeps it
	 * unencrypted in an encrypted archive (nm/encrypted.json).
	 */
	public function add_string( string $name, string $bytes, int $mtime = 0, bool $plain = false ): bool {
		$mtime = $mtime ? $mtime : time();
		$crc   = crc32( $bytes );
		$len   = strlen( $bytes );
		$data  = $bytes;
		$meth  = 0;
		if ( $len > 64 ) {
			$def = gzdeflate( $bytes, 6 );
			if ( false !== $def && strlen( $def ) < $len ) {
				$data = $def;
				$meth = 8;
			}
		}
		$aes = -1;
		if ( ! empty( $this->st['enc'] ) && ! $plain ) {
			$data = NM_Crypt::encrypt( $this->pw, $data );
			$aes  = $meth;
			$crc  = 0;
		}
		$off = (int) $this->st['offset'];
		$hdr = $this->local_header( $name, $meth, $mtime, $crc, strlen( $data ), $len, $aes );
		if ( ! $this->put( $hdr . $data ) ) {
			return false;
		}
		return $this->central( $name, $meth, $mtime, $crc, strlen( $data ), $len, $off, $aes );
	}

	/**
	 * Add a file. Returns 'done', 'paused' (deadline reached, call again
	 * next request with the saved state), 'skip' (unreadable or gone) or
	 * 'fail' (cannot write the archive).
	 */
	public function add_file( string $src, string $name, float $deadline ): string {
		$cur = $this->st['cur'];
		clearstatcache( true, $src );
		if ( ! is_file( $src ) || ! is_readable( $src ) ) {
			if ( $cur ) {
				$this->rewind_entry();
			}
			return 'skip';
		}
		$size  = (int) filesize( $src );
		$mtime = (int) filemtime( $src );

		if ( $cur && ( $cur['src'] !== $src || (int) $cur['size'] !== $size || (int) $cur['mtime'] !== $mtime ) ) {
			// The file changed while it was being copied: write it again.
			$this->rewind_entry();
			$cur = null;
		}

		if ( ! $cur ) {
			if ( $size <= self::DEFLATE_MAX && ! preg_match( self::NO_DEFLATE, $name ) ) {
				$bytes = @file_get_contents( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( false === $bytes ) {
					return 'skip';
				}
				return $this->add_string( $name, $bytes, $mtime ) ? 'done' : 'fail';
			}
			$enc  = ! empty( $this->st['enc'] );
			$salt = '';
			$pv   = '';
			if ( $enc ) {
				// AE-2 has no CRC: the MAC at the end covers the data.
				$crc            = '0';
				$salt           = random_bytes( NM_Crypt::SALT );
				list( , , $pv ) = NM_Crypt::derive( $this->pw, $salt );
			} else {
				$crc = @hash_file( 'crc32b', $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false === $crc ) {
					return 'skip';
				}
			}
			$off = (int) $this->st['offset'];
			$hdr = $this->local_header( $name, 0, $mtime, (int) hexdec( $crc ), $size + ( $enc ? NM_Crypt::OVERHEAD : 0 ), $size, $enc ? 0 : -1 );
			if ( ! $this->put( $hdr . $salt . $pv ) ) {
				return 'fail';
			}
			$cur             = array(
				'src'   => $src,
				'name'  => $name,
				'size'  => $size,
				'mtime' => $mtime,
				'crc'   => (int) hexdec( $crc ),
				'hdr'   => $off,
				'done'  => 0,
				'salt'  => bin2hex( $salt ),
				'data'  => (int) $this->st['offset'],
			);
			$this->st['cur'] = $cur;
		}
		$keys = array( '', '' );
		if ( ! empty( $cur['salt'] ) ) {
			$keys = NM_Crypt::derive( $this->pw, (string) hex2bin( $cur['salt'] ) );
		}

		$in = @fopen( $src, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $in ) {
			$this->rewind_entry();
			return 'skip';
		}
		if ( $cur['done'] > 0 ) {
			fseek( $in, (int) $cur['done'] );
		}
		while ( $cur['done'] < $cur['size'] ) {
			$want = (int) min( self::CHUNK, $cur['size'] - $cur['done'] );
			$buf  = fread( $in, $want );
			if ( false === $buf || '' === $buf ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$this->rewind_entry();
				return 'skip';
			}
			if ( '' !== $keys[0] ) {
				$buf = NM_Crypt::ctr( $keys[0], $buf, (int) $cur['done'] );
			}
			if ( ! $this->put( $buf ) ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return 'fail';
			}
			$cur['done']    += strlen( $buf );
			$this->st['cur'] = $cur;
			if ( $cur['done'] < $cur['size'] && microtime( true ) >= $deadline ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return 'paused';
			}
		}
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$csize = (int) $cur['size'];
		if ( '' !== $keys[0] ) {
			// The MAC over the encrypted data, read back from the archive.
			fflush( $this->zh );
			$rh  = @fopen( $this->st['path'], 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$mac = $rh ? NM_Crypt::mac_file( $keys[1], $rh, (int) $cur['data'], $csize ) : false;
			if ( $rh ) {
				fclose( $rh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			if ( false === $mac || ! $this->put( $mac ) ) {
				return 'fail';
			}
			$csize += NM_Crypt::OVERHEAD;
		}
		$this->st['cur'] = null;
		if ( ! $this->central( $cur['name'], 0, $cur['mtime'], $cur['crc'], $csize, $cur['size'], (int) $cur['hdr'], '' !== $keys[0] ? 0 : -1 ) ) {
			return 'fail';
		}
		return 'done';
	}

	/** Drop a half-written entry. */
	private function rewind_entry(): void {
		$cur = $this->st['cur'];
		if ( $cur ) {
			ftruncate( $this->zh, (int) $cur['hdr'] );
			fseek( $this->zh, 0, SEEK_END );
			$this->st['offset'] = (int) $cur['hdr'];
		}
		$this->st['cur'] = null;
	}

	/** Write the central directory and the end records. */
	public function finish(): bool {
		if ( $this->busy() ) {
			$this->rewind_entry();
		}
		$cd_off  = (int) $this->st['offset'];
		$cd_size = (int) $this->st['cd_size'];
		fflush( $this->ch );
		fseek( $this->ch, 0 );
		while ( ! feof( $this->ch ) ) {
			$buf = fread( $this->ch, self::CHUNK );
			if ( false === $buf ) {
				return false;
			}
			if ( '' !== $buf && ! $this->put( $buf ) ) {
				return false;
			}
		}
		$count = (int) $this->st['count'];
		$end   = '';
		if ( $count >= 0xFFFF || $cd_off >= 0xFFFFFFFF || $cd_size >= 0xFFFFFFFF ) {
			$z64  = (int) $this->st['offset'];
			$end .= pack( 'VPvvVVPPPP', 0x06064b50, 44, 45, 45, 0, 0, $count, $count, $cd_size, $cd_off );
			$end .= pack( 'VVPV', 0x07064b50, 0, $z64, 1 );
		}
		$end .= pack(
			'VvvvvVVv',
			0x06054b50,
			0,
			0,
			min( $count, 0xFFFF ),
			min( $count, 0xFFFF ),
			min( $cd_size, 0xFFFFFFFF ),
			min( $cd_off, 0xFFFFFFFF ),
			0
		);
		if ( ! $this->put( $end ) ) {
			return false;
		}
		fflush( $this->zh );
		return true;
	}

	/* ---------------------------------------------------------------
	 * Records
	 * ------------------------------------------------------------- */

	private function put( string $bytes ): bool {
		$len = strlen( $bytes );
		$n   = fwrite( $this->zh, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		if ( $n !== $len ) {
			return false;
		}
		$this->st['offset'] = (int) $this->st['offset'] + $len;
		return true;
	}

	private static function dos_time( int $ts ): array {
		$d = getdate( max( $ts, 315532800 ) ); // DOS time starts in 1980.
		return array(
			( $d['hours'] << 11 ) | ( $d['minutes'] << 5 ) | ( $d['seconds'] >> 1 ),
			( ( $d['year'] - 1980 ) << 9 ) | ( $d['mon'] << 5 ) | $d['mday'],
		);
	}

	/** AE-2 extra field (0x9901) naming the real method. */
	private static function aes_extra( int $aes ): string {
		return $aes >= 0 ? pack( 'vvvA2Cv', 0x9901, 7, 2, 'AE', 3, $aes ) : '';
	}

	/** $aes: the real method of an encrypted entry, -1 when not encrypted. */
	private function local_header( string $name, int $meth, int $mtime, int $crc, int $csize, int $usize, int $aes = -1 ): string {
		list( $t, $d ) = self::dos_time( $mtime );
		$z64           = $csize >= 0xFFFFFFFF || $usize >= 0xFFFFFFFF;
		$extra         = ( $z64 ? pack( 'vvPP', 0x0001, 16, $usize, $csize ) : '' ) . self::aes_extra( $aes );
		return pack(
			'VvvvvvVVVvv',
			0x04034b50,
			$aes >= 0 ? 51 : ( $z64 ? 45 : 20 ),
			$aes >= 0 ? 0x0801 : 0x0800,
			$aes >= 0 ? 99 : $meth,
			$t,
			$d,
			$crc,
			$z64 ? 0xFFFFFFFF : $csize,
			$z64 ? 0xFFFFFFFF : $usize,
			strlen( $name ),
			strlen( $extra )
		) . $name . $extra;
	}

	private function central( string $name, int $meth, int $mtime, int $crc, int $csize, int $usize, int $off, int $aes = -1 ): bool {
		list( $t, $d ) = self::dos_time( $mtime );
		$fields        = '';
		if ( $usize >= 0xFFFFFFFF ) {
			$fields .= pack( 'P', $usize );
		}
		if ( $csize >= 0xFFFFFFFF ) {
			$fields .= pack( 'P', $csize );
		}
		if ( $off >= 0xFFFFFFFF ) {
			$fields .= pack( 'P', $off );
		}
		$extra = '' === $fields ? '' : pack( 'vv', 0x0001, strlen( $fields ) ) . $fields;
		$need  = '' === $extra ? 20 : 45;
		$extra .= self::aes_extra( $aes );
		$rec   = pack(
			'VvvvvvvVVVvvvvvVV',
			0x02014b50,
			( 3 << 8 ) | ( $aes >= 0 ? 51 : 45 ),
			$aes >= 0 ? 51 : $need,
			$aes >= 0 ? 0x0801 : 0x0800,
			$aes >= 0 ? 99 : $meth,
			$t,
			$d,
			$crc,
			min( $csize, 0xFFFFFFFF ),
			min( $usize, 0xFFFFFFFF ),
			strlen( $name ),
			strlen( $extra ),
			0,
			0,
			0,
			0100644 << 16,
			min( $off, 0xFFFFFFFF )
		) . $name . $extra;
		$n     = fwrite( $this->ch, $rec ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		if ( strlen( $rec ) !== $n ) {
			return false;
		}
		$this->st['cd_size'] = (int) $this->st['cd_size'] + strlen( $rec );
		$this->st['count']   = (int) $this->st['count'] + 1;
		return true;
	}
}

<?php
/**
 * EBD_Pdf — pure PHP PDF reader (no Composer, no third-party code).
 *
 * A copy of Book Preview's BPV_Pdf reader (Book Preview 1.2.2,
 * includes/class-pdf.php, parser and reader only; the page writer is left
 * out), renamed so that the two plugins never share a class. Reads a PDF
 * with a classic cross-reference table, a cross-reference stream (PDF
 * 1.5+), object streams, hybrid files and incremental updates. Damaged
 * cross-references are rebuilt by scanning the file for objects; encrypted
 * files are refused (EBD_Pdf_Error 'encrypted').
 *
 * Additions over the Book Preview copy, for incremental updates
 * (EBD_Pdf_Stamp): the public $startxref (the last "startxref" value as
 * written in the file), $xref_stream (true when that section is a
 * cross-reference stream) and $base (bytes of junk before "%PDF-").
 *
 * Bible §10 deviation: the reader and the PDF values are instances, not
 * static classes, because one request may open several files.
 */

defined( 'ABSPATH' ) || exit;

/** An error with a machine reason: not_pdf, encrypted, damaged, unsupported, io, no_pages. */
final class EBD_Pdf_Error extends \RuntimeException {

	/** @var string */
	public $reason;

	public function __construct( string $reason, string $detail = '' ) {
		parent::__construct( '' !== $detail ? $reason . ': ' . $detail : $reason );
		$this->reason = $reason;
	}
}

/** Thrown inside the parser when the buffer ends before the object does. */
final class EBD_Pdf_Eof extends \RuntimeException {
}

/** Indirect reference "num gen R". */
final class EBD_Pdf_Ref {
	/** @var int */
	public $num;
	/** @var int */
	public $gen;

	public function __construct( int $num, int $gen ) {
		$this->num = $num;
		$this->gen = $gen;
	}
}

/** A name, decoded (#xx resolved), without the slash. */
final class EBD_Pdf_Name {
	/** @var string */
	public $name;

	public function __construct( string $name ) {
		$this->name = $name;
	}
}

/** Numbers, strings, booleans and null, kept as their original bytes. */
final class EBD_Pdf_Raw {
	/** @var string */
	public $raw;

	public function __construct( string $raw ) {
		$this->raw = $raw;
	}
}

final class EBD_Pdf_Dict {
	/** @var array<string, mixed> Decoded key => value. */
	public $items = array();

	public function get( string $key ) {
		return $this->items[ $key ] ?? null;
	}

	public function set( string $key, $value ): void {
		$this->items[ $key ] = $value;
	}

	public function remove( string $key ): void {
		unset( $this->items[ $key ] );
	}
}

/** A stream: its dictionary and where its data lives in the file. */
final class EBD_Pdf_Stream {
	/** @var EBD_Pdf_Dict */
	public $dict;
	/** @var int */
	public $offset;
	/** @var int */
	public $length;

	public function __construct( EBD_Pdf_Dict $dict, int $offset, int $length ) {
		$this->dict   = $dict;
		$this->offset = $offset;
		$this->length = $length;
	}
}

/**
 * Tokenizer / parser over a string buffer. Throws EBD_Pdf_Eof when the
 * buffer ends inside an object, so the reader can retry with more data.
 */
final class EBD_Pdf_Parser {

	const WS    = "\x00\x09\x0A\x0C\x0D\x20";
	const DELIM = "()<>[]{}/%";

	/** @var string */
	public $buf;
	/** @var int */
	public $pos = 0;
	/** @var bool True when $buf ends at the end of the file (no more data to come). */
	public $at_eof;

	public function __construct( string $buf, bool $at_eof = true ) {
		$this->buf    = $buf;
		$this->at_eof = $at_eof;
	}

	private function eof(): void {
		throw new EBD_Pdf_Eof();
	}

	/** Skips white space and comments. */
	public function skip_ws(): void {
		$len = strlen( $this->buf );
		while ( true ) {
			$this->pos += strspn( $this->buf, self::WS, $this->pos );
			if ( $this->pos < $len && '%' === $this->buf[ $this->pos ] ) {
				$this->pos += strcspn( $this->buf, "\r\n", $this->pos );
				continue;
			}
			break;
		}
		if ( $this->pos >= $len && ! $this->at_eof ) {
			$this->eof();
		}
	}

	/** A regular token (number or keyword) at the current position. */
	private function word(): string {
		$n = strcspn( $this->buf, self::WS . self::DELIM, $this->pos );
		if ( $this->pos + $n >= strlen( $this->buf ) && ! $this->at_eof ) {
			$this->eof();
		}
		$w          = substr( $this->buf, $this->pos, $n );
		$this->pos += $n;
		return $w;
	}

	/** Reads the next word without moving (used for "n g R" and "stream"). */
	public function peek_word(): string {
		$save = $this->pos;
		try {
			$this->skip_ws();
			$w = $this->word();
		} catch ( EBD_Pdf_Eof $e ) {
			$this->pos = $save;
			throw $e;
		}
		$this->pos = $save;
		return $w;
	}

	public function expect_word( string $word ): void {
		$this->skip_ws();
		$w = $this->word();
		if ( $w !== $word ) {
			throw new EBD_Pdf_Error( 'damaged', 'expected ' . $word );
		}
	}

	/** One object (dict, array, name, string, number, ref, bool, null). */
	public function value( int $depth = 0 ) {
		if ( $depth > 200 ) {
			throw new EBD_Pdf_Error( 'damaged', 'nesting' );
		}
		$this->skip_ws();
		$len = strlen( $this->buf );
		if ( $this->pos >= $len ) {
			throw new EBD_Pdf_Error( 'damaged', 'unexpected end' );
		}
		$c = $this->buf[ $this->pos ];

		if ( '/' === $c ) {
			++$this->pos;
			return new EBD_Pdf_Name( self::decode_name( $this->word() ) );
		}
		if ( '<' === $c ) {
			if ( $this->pos + 1 >= $len ) {
				$this->at_eof ? $this->bad( 'string' ) : $this->eof();
			}
			if ( '<' === $this->buf[ $this->pos + 1 ] ) {
				$this->pos += 2;
				return $this->dict_body( $depth );
			}
			$end = strpos( $this->buf, '>', $this->pos );
			if ( false === $end ) {
				$this->at_eof ? $this->bad( 'hex string' ) : $this->eof();
			}
			$raw       = substr( $this->buf, $this->pos, $end - $this->pos + 1 );
			$this->pos = $end + 1;
			return new EBD_Pdf_Raw( $raw );
		}
		if ( '[' === $c ) {
			++$this->pos;
			$out = array();
			while ( true ) {
				$this->skip_ws();
				if ( $this->pos >= $len ) {
					$this->at_eof ? $this->bad( 'array' ) : $this->eof();
				}
				if ( ']' === $this->buf[ $this->pos ] ) {
					++$this->pos;
					return $out;
				}
				$out[] = $this->value( $depth + 1 );
			}
		}
		if ( '(' === $c ) {
			return new EBD_Pdf_Raw( $this->literal() );
		}
		if ( ')' === $c || '>' === $c || ']' === $c || '{' === $c || '}' === $c ) {
			// Stray delimiter: skip it so a damaged object does not stop the parse.
			++$this->pos;
			return new EBD_Pdf_Raw( 'null' );
		}

		$w = $this->word();
		if ( '' === $w ) {
			$this->bad( 'token' );
		}
		if ( preg_match( '/^\d+$/', $w ) ) {
			// "num gen R"?
			$save = $this->pos;
			try {
				$this->skip_ws();
				$gen = $this->word();
				if ( preg_match( '/^\d+$/', $gen ) ) {
					$this->skip_ws();
					$r = $this->word();
					if ( 'R' === $r ) {
						return new EBD_Pdf_Ref( (int) $w, (int) $gen );
					}
				}
			} catch ( EBD_Pdf_Eof $e ) {
				if ( ! $this->at_eof ) {
					throw $e;
				}
			} catch ( EBD_Pdf_Error $e ) {
				// Not a reference.
				unset( $e );
			}
			$this->pos = $save;
			return new EBD_Pdf_Raw( $w );
		}
		return new EBD_Pdf_Raw( $w ); // Real numbers, true, false, null, and stray keywords.
	}

	private function bad( string $what ): void {
		throw new EBD_Pdf_Error( 'damaged', $what );
	}

	private function dict_body( int $depth ): EBD_Pdf_Dict {
		$d   = new EBD_Pdf_Dict();
		$len = strlen( $this->buf );
		while ( true ) {
			$this->skip_ws();
			if ( $this->pos >= $len ) {
				$this->at_eof ? $this->bad( 'dict' ) : $this->eof();
			}
			if ( '>' === $this->buf[ $this->pos ] ) {
				if ( $this->pos + 1 >= $len && ! $this->at_eof ) {
					$this->eof();
				}
				$this->pos += ( isset( $this->buf[ $this->pos + 1 ] ) && '>' === $this->buf[ $this->pos + 1 ] ) ? 2 : 1;
				return $d;
			}
			$key = $this->value( $depth + 1 );
			if ( ! $key instanceof EBD_Pdf_Name ) {
				continue; // Damaged entry: skip the stray token.
			}
			$this->skip_ws();
			if ( $this->pos < $len && '>' === $this->buf[ $this->pos ] ) {
				$d->set( $key->name, new EBD_Pdf_Raw( 'null' ) );
				continue;
			}
			$d->set( $key->name, $this->value( $depth + 1 ) );
		}
	}

	/** A literal string "( … )" with its parentheses, raw. */
	private function literal(): string {
		$start = $this->pos;
		$len   = strlen( $this->buf );
		$level = 0;
		$i     = $this->pos;
		while ( $i < $len ) {
			$i += strcspn( $this->buf, '()\\', $i );
			if ( $i >= $len ) {
				break;
			}
			$ch = $this->buf[ $i ];
			if ( '\\' === $ch ) {
				$i += 2;
				continue;
			}
			if ( '(' === $ch ) {
				++$level;
			} else {
				--$level;
				if ( 0 === $level ) {
					$this->pos = $i + 1;
					return substr( $this->buf, $start, $i + 1 - $start );
				}
			}
			++$i;
		}
		if ( ! $this->at_eof ) {
			$this->eof();
		}
		$this->bad( 'string' );
		return '';
	}

	public static function decode_name( string $raw ): string {
		if ( false === strpos( $raw, '#' ) ) {
			return $raw;
		}
		return (string) preg_replace_callback(
			'/#([0-9A-Fa-f]{2})/',
			static function ( $m ) {
				return chr( hexdec( $m[1] ) );
			},
			$raw
		);
	}
}

/**
 * Reader: cross-reference, objects, object streams, page tree.
 */
final class EBD_Pdf_Reader {

	const CHUNK      = 65536;
	const MAX_OBJECT = 33554432; // 32 MB for one object's dictionary (never stream data).
	const MAX_DEPTH  = 64;

	/** @var resource */
	private $fh;
	/** @var int */
	public $size;
	/** @var string */
	public $version = '1.4';
	/** @var EBD_Pdf_Dict */
	public $trailer;
	/** @var array<int, array{0:int,1:int,2:int}> num => [type, a, b]: type 1 = offset, gen; type 2 = objstm num, index. */
	private $xref = array();
	/** @var array<int, mixed> Resolved object cache. */
	private $cache = array();
	/** @var array<int, array> Decoded object streams: num => [ index => [num, value] ]. */
	private $objstm = array();
	/** @var bool */
	public $rebuilt = false;
	/** @var array|null */
	private $pages = null;
	/** @var int Header offset (bytes of junk before %PDF-). */
	public $base = 0;
	/** @var int The last startxref value, as written in the file (0 = none). */
	public $startxref = 0;
	/** @var bool True when the newest cross-reference section is a stream. */
	public $xref_stream = false;

	public function __construct( string $path ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new EBD_Pdf_Error( 'io', 'not readable' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- binary random access.
		$fh = fopen( $path, 'rb' );
		if ( ! $fh ) {
			throw new EBD_Pdf_Error( 'io', 'open' );
		}
		$this->fh   = $fh;
		$this->size = (int) filesize( $path );

		$head = $this->read( 0, 1024 );
		$at   = strpos( $head, '%PDF-' );
		if ( false === $at ) {
			throw new EBD_Pdf_Error( 'not_pdf' );
		}
		$this->base = $at;
		if ( preg_match( '/%PDF-(\d\.\d)/', $head, $m ) ) {
			$this->version = $m[1];
		}

		try {
			$this->load_xref();
			$this->check_root();
		} catch ( EBD_Pdf_Error $e ) {
			if ( 'encrypted' === $e->reason ) {
				throw $e;
			}
			$this->rebuild();
			$this->check_root();
		}
		if ( null !== $this->trailer->get( 'Encrypt' ) ) {
			throw new EBD_Pdf_Error( 'encrypted' );
		}
		// The catalog may raise the version (/Version, PDF 1.4+).
		$v = $this->resolve( $this->catalog()->get( 'Version' ) );
		if ( $v instanceof EBD_Pdf_Name && preg_match( '/^\d\.\d$/', $v->name ) && version_compare( $v->name, $this->version, '>' ) ) {
			$this->version = $v->name;
		}
	}

	public function __destruct() {
		if ( is_resource( $this->fh ) ) {
			fclose( $this->fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/** Raw bytes from the file. */
	public function read( int $offset, int $length ): string {
		if ( $length <= 0 || $offset < 0 || $offset >= $this->size ) {
			return '';
		}
		fseek( $this->fh, $offset );
		$out = '';
		while ( strlen( $out ) < $length && ! feof( $this->fh ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$chunk = fread( $this->fh, min( 1048576, $length - strlen( $out ) ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$out .= $chunk;
		}
		return $out;
	}

	/** Copies stream data to an open handle without loading it whole. */
	public function copy_to( int $offset, int $length, $out ): void {
		$done = 0;
		while ( $done < $length ) {
			$chunk = $this->read( $offset + $done, min( 1048576, $length - $done ) );
			if ( '' === $chunk ) {
				throw new EBD_Pdf_Error( 'damaged', 'stream past end of file' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $out, $chunk );
			$done += strlen( $chunk );
		}
	}

	/* ---------------------------------------------------------------------
	 * Cross-reference
	 * ------------------------------------------------------------------- */

	private function load_xref(): void {
		$tail_len = min( $this->size, 4096 );
		$tail     = $this->read( $this->size - $tail_len, $tail_len );
		if ( ! preg_match_all( '/startxref\s+(\d+)/', $tail, $m ) ) {
			throw new EBD_Pdf_Error( 'damaged', 'no startxref' );
		}
		$offset          = (int) end( $m[1] );
		$this->startxref = $offset;
		$first           = true;

		$seen          = array();
		$this->trailer = null;
		while ( $offset > 0 ) {
			$offset = $this->fix_offset( $offset );
			if ( isset( $seen[ $offset ] ) || count( $seen ) > 500 ) {
				break;
			}
			$seen[ $offset ] = true;
			if ( $first ) {
				$this->xref_stream = 0 !== strncmp( ltrim( $this->read( $offset, 32 ) ), 'xref', 4 );
				$first             = false;
			}
			$trailer         = $this->xref_section( $offset );
			if ( null === $this->trailer ) {
				$this->trailer = $trailer;
			}
			$prev = $trailer->get( 'Prev' );
			if ( ! $prev instanceof EBD_Pdf_Raw || ! is_numeric( $prev->raw ) ) {
				break;
			}
			$offset = (int) $prev->raw;
		}
		if ( ! $this->trailer instanceof EBD_Pdf_Dict ) {
			throw new EBD_Pdf_Error( 'damaged', 'no trailer' );
		}
	}

	/** Some files count offsets from the "%PDF-" header, not from byte 0. */
	private function fix_offset( int $offset ): int {
		return $offset + $this->base;
	}

	/** One cross-reference section (table or stream); returns its trailer. */
	private function xref_section( int $offset ): EBD_Pdf_Dict {
		$head = $this->read( $offset, 32 );
		if ( 0 === strncmp( ltrim( $head ), 'xref', 4 ) ) {
			return $this->xref_table( $offset );
		}
		// A cross-reference stream: "n g obj << /Type /XRef … >> stream".
		$obj = $this->parse_indirect_at( $offset, null );
		if ( ! $obj instanceof EBD_Pdf_Stream ) {
			throw new EBD_Pdf_Error( 'damaged', 'xref' );
		}
		$this->xref_stream( $obj );
		return $obj->dict;
	}

	private function xref_table( int $offset ): EBD_Pdf_Dict {
		$len  = self::CHUNK;
		$data = '';
		$p    = null;
		while ( true ) {
			$data  = $this->read( $offset, $len );
			$atend = $offset + strlen( $data ) >= $this->size;
			$tpos  = strpos( $data, 'trailer' );
			if ( false !== $tpos ) {
				$p = new EBD_Pdf_Parser( $data, $atend );
				try {
					$p->pos = $tpos + 7;
					$trailer = $p->value();
					break;
				} catch ( EBD_Pdf_Eof $e ) {
					unset( $e );
				}
			}
			if ( $atend || $len >= self::MAX_OBJECT ) {
				throw new EBD_Pdf_Error( 'damaged', 'xref table' );
			}
			$len *= 2;
		}
		if ( ! $trailer instanceof EBD_Pdf_Dict ) {
			throw new EBD_Pdf_Error( 'damaged', 'trailer' );
		}

		$table = substr( $data, 0, $tpos );
		$table = substr( $table, (int) strpos( $table, 'xref' ) + 4 );
		$lines = preg_split( '/[\r\n]+/', trim( $table ) );
		$num   = 0;
		$left  = 0;
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( 0 === $left && preg_match( '/^(\d+)\s+(\d+)$/', $line, $m ) ) {
				$num  = (int) $m[1];
				$left = (int) $m[2];
				continue;
			}
			if ( preg_match( '/^(\d{1,10})\s+(\d{1,5})\s+([nf])/', $line, $m ) ) {
				if ( 'n' === $m[3] && ! isset( $this->xref[ $num ] ) && (int) $m[1] > 0 ) {
					$this->xref[ $num ] = array( 1, $this->fix_offset( (int) $m[1] ), (int) $m[2] );
				}
				++$num;
				$left = max( 0, $left - 1 );
				continue;
			}
			throw new EBD_Pdf_Error( 'damaged', 'xref line' );
		}

		// Hybrid file: an extra xref stream for the objects in object streams.
		$stm = $trailer->get( 'XRefStm' );
		if ( $stm instanceof EBD_Pdf_Raw && is_numeric( $stm->raw ) ) {
			$obj = $this->parse_indirect_at( $this->fix_offset( (int) $stm->raw ), null );
			if ( $obj instanceof EBD_Pdf_Stream ) {
				$this->xref_stream( $obj );
			}
		}
		return $trailer;
	}

	private function xref_stream( EBD_Pdf_Stream $s ): void {
		$data = $this->decode( $s );
		$w    = $this->resolve( $s->dict->get( 'W' ) );
		if ( ! is_array( $w ) || 3 !== count( $w ) ) {
			throw new EBD_Pdf_Error( 'damaged', 'xref W' );
		}
		$w     = array_map( array( $this, 'int' ), $w );
		$size  = $this->int( $s->dict->get( 'Size' ) );
		$index = $this->resolve( $s->dict->get( 'Index' ) );
		$index = is_array( $index ) ? array_map( array( $this, 'int' ), $index ) : array( 0, $size );
		$row   = $w[0] + $w[1] + $w[2];
		if ( $row <= 0 || $w[0] < 0 || $w[1] < 0 || $w[2] < 0 || max( $w ) > 8 ) {
			throw new EBD_Pdf_Error( 'damaged', 'xref W' );
		}
		$pos = 0;
		$len = strlen( $data );
		for ( $i = 0; $i + 1 < count( $index ); $i += 2 ) {
			$first = $index[ $i ];
			$count = $index[ $i + 1 ];
			for ( $k = 0; $k < $count; $k++ ) {
				if ( $pos + $row > $len ) {
					return;
				}
				$type = $w[0] ? self::be( $data, $pos, $w[0] ) : 1;
				$a    = self::be( $data, $pos + $w[0], $w[1] );
				$b    = $w[2] ? self::be( $data, $pos + $w[0] + $w[1], $w[2] ) : 0;
				$pos += $row;
				$num  = $first + $k;
				if ( isset( $this->xref[ $num ] ) ) {
					continue; // A newer section already defines it.
				}
				if ( 1 === $type && $a > 0 ) {
					$this->xref[ $num ] = array( 1, $this->fix_offset( $a ), $b );
				} elseif ( 2 === $type ) {
					$this->xref[ $num ] = array( 2, $a, $b );
				}
			}
		}
	}

	private static function be( string $data, int $pos, int $width ): int {
		$v = 0;
		for ( $i = 0; $i < $width; $i++ ) {
			$v = ( $v << 8 ) | ord( $data[ $pos + $i ] );
		}
		return $v;
	}

	/** Damaged cross-reference: find every "n g obj" in the file. */
	private function rebuild(): void {
		$this->xref    = array();
		$this->cache   = array();
		$this->objstm  = array();
		$this->rebuilt = true;
		$trailers      = array();
		$xref_streams  = array();
		$objstms       = array();

		$chunk = 4194304;
		$over  = 64;
		for ( $off = 0; $off < $this->size; $off += $chunk ) {
			$data = $this->read( $off, $chunk + $over );
			if ( preg_match_all( '/(?<![0-9])(\d{1,10})[\x00\x09\x0A\x0C\x0D\x20]+(\d{1,5})[\x00\x09\x0A\x0C\x0D\x20]+obj\b/', $data, $m, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $m[0] as $i => $hit ) {
					if ( $hit[1] >= $chunk ) {
						continue; // In the overlap: the next chunk reports it.
					}
					$this->xref[ (int) $m[1][ $i ][0] ] = array( 1, $off + $hit[1], (int) $m[2][ $i ][0] );
				}
			}
			$t = 0;
			while ( false !== ( $t = strpos( $data, 'trailer', $t ) ) ) {
				if ( $t < $chunk ) {
					$trailers[] = $off + $t + 7;
				}
				$t += 7;
			}
		}

		// Object streams and xref streams among the objects found.
		foreach ( $this->xref as $num => $e ) {
			try {
				$head = $this->read( $e[1], 512 );
				if ( false === strpos( $head, 'ObjStm' ) && false === strpos( $head, 'XRef' ) ) {
					continue;
				}
				$o = $this->parse_indirect_at( $e[1], null );
			} catch ( \Throwable $ex ) {
				continue;
			}
			if ( ! $o instanceof EBD_Pdf_Stream ) {
				continue;
			}
			$type = $o->dict->get( 'Type' );
			if ( $type instanceof EBD_Pdf_Name && 'ObjStm' === $type->name ) {
				$objstms[] = $num;
			} elseif ( $type instanceof EBD_Pdf_Name && 'XRef' === $type->name ) {
				$xref_streams[] = $o->dict;
			}
		}
		foreach ( $objstms as $sn ) {
			try {
				foreach ( $this->objstm_load( $sn ) as $idx => $pair ) {
					if ( ! isset( $this->xref[ $pair[0] ] ) ) {
						$this->xref[ $pair[0] ] = array( 2, $sn, $idx );
					}
				}
			} catch ( \Throwable $ex ) {
				continue;
			}
		}

		$this->trailer = null;
		foreach ( array_reverse( $trailers ) as $tp ) {
			try {
				$p = new EBD_Pdf_Parser( $this->read( $tp, self::CHUNK ), true );
				$t = $p->value();
				if ( $t instanceof EBD_Pdf_Dict && null !== $t->get( 'Root' ) ) {
					$this->trailer = $t;
					break;
				}
			} catch ( \Throwable $ex ) {
				continue;
			}
		}
		if ( null === $this->trailer && $xref_streams ) {
			$this->trailer = end( $xref_streams );
		}
		if ( null === $this->trailer ) {
			// No trailer at all: look for the catalog.
			foreach ( array_keys( $this->xref ) as $num ) {
				try {
					$o = $this->get( $num );
				} catch ( \Throwable $ex ) {
					continue;
				}
				$type = $o instanceof EBD_Pdf_Dict ? $o->get( 'Type' ) : null;
				if ( $type instanceof EBD_Pdf_Name && 'Catalog' === $type->name ) {
					$this->trailer = new EBD_Pdf_Dict();
					$this->trailer->set( 'Root', new EBD_Pdf_Ref( $num, $this->xref[ $num ][2] ?? 0 ) );
				}
			}
		}
		if ( null === $this->trailer ) {
			throw new EBD_Pdf_Error( 'damaged', 'no catalog' );
		}
	}

	private function check_root(): void {
		$root = $this->catalog();
		if ( ! $root->get( 'Pages' ) ) {
			throw new EBD_Pdf_Error( 'damaged', 'no page tree' );
		}
	}

	public function catalog(): EBD_Pdf_Dict {
		$root = $this->resolve( $this->trailer->get( 'Root' ) );
		if ( ! $root instanceof EBD_Pdf_Dict ) {
			throw new EBD_Pdf_Error( 'damaged', 'no catalog' );
		}
		return $root;
	}

	/* ---------------------------------------------------------------------
	 * Objects
	 * ------------------------------------------------------------------- */

	/** Resolves a reference (any depth); other values pass through. */
	public function resolve( $v ) {
		$guard = 0;
		while ( $v instanceof EBD_Pdf_Ref && $guard++ < 32 ) {
			$v = $this->get( $v->num );
		}
		return $v;
	}

	/** The object with this number, or a null Raw when it does not exist. */
	public function get( int $num ) {
		if ( array_key_exists( $num, $this->cache ) ) {
			return $this->cache[ $num ];
		}
		$e = $this->xref[ $num ] ?? null;
		if ( null === $e ) {
			return new EBD_Pdf_Raw( 'null' );
		}
		$this->cache[ $num ] = new EBD_Pdf_Raw( 'null' ); // Guards against cycles while parsing.
		if ( 1 === $e[0] ) {
			$v = $this->parse_indirect_at( $e[1], $num );
		} else {
			$list = $this->objstm_load( $e[1] );
			$v    = new EBD_Pdf_Raw( 'null' );
			if ( isset( $list[ $e[2] ] ) && $list[ $e[2] ][0] === $num ) {
				$v = $list[ $e[2] ][1];
			} else {
				foreach ( $list as $pair ) {
					if ( $pair[0] === $num ) {
						$v = $pair[1];
						break;
					}
				}
			}
		}
		// Keep the cache small: dictionaries and arrays only (streams hold no data).
		if ( count( $this->cache ) > 20000 ) {
			$this->cache = array();
		}
		$this->cache[ $num ] = $v;
		return $v;
	}

	public function has( int $num ): bool {
		return isset( $this->xref[ $num ] );
	}

	public function generation( int $num ): int {
		return isset( $this->xref[ $num ] ) && 1 === $this->xref[ $num ][0] ? (int) $this->xref[ $num ][2] : 0;
	}

	/** Parses "n g obj … endobj" at an offset; streams keep their data in the file. */
	private function parse_indirect_at( int $offset, ?int $expect ) {
		$len = self::CHUNK;
		while ( true ) {
			$buf   = $this->read( $offset, $len );
			$atend = $offset + strlen( $buf ) >= $this->size;
			$p     = new EBD_Pdf_Parser( $buf, $atend );
			try {
				$p->skip_ws();
				$n = $p->value();
				$g = $p->value();
				$p->expect_word( 'obj' );
				if ( ! $n instanceof EBD_Pdf_Raw || ! $g instanceof EBD_Pdf_Raw ) {
					throw new EBD_Pdf_Error( 'damaged', 'object header' );
				}
				if ( null !== $expect && (int) $n->raw !== $expect ) {
					throw new EBD_Pdf_Error( 'damaged', 'object number' );
				}
				$v = $p->value();
				if ( $v instanceof EBD_Pdf_Dict && 'stream' === $p->peek_word() ) {
					$p->skip_ws();
					$p->pos += 6;
					// EOL after "stream": CRLF or LF (CR alone is tolerated).
					if ( isset( $buf[ $p->pos ] ) && "\r" === $buf[ $p->pos ] ) {
						++$p->pos;
					}
					if ( isset( $buf[ $p->pos ] ) && "\n" === $buf[ $p->pos ] ) {
						++$p->pos;
					}
					$start = $offset + $p->pos;
					return new EBD_Pdf_Stream( $v, $start, $this->stream_length( $v, $start ) );
				}
				return $v;
			} catch ( EBD_Pdf_Eof $e ) {
				if ( $atend || $len >= self::MAX_OBJECT ) {
					throw new EBD_Pdf_Error( 'damaged', 'object too large' );
				}
				$len *= 4;
			}
		}
	}

	/** /Length when it is right, else the distance to "endstream". */
	private function stream_length( EBD_Pdf_Dict $d, int $start ): int {
		$len = $d->get( 'Length' );
		if ( $len instanceof EBD_Pdf_Ref ) {
			$len = $this->resolve( $len );
		}
		if ( $len instanceof EBD_Pdf_Raw && is_numeric( $len->raw ) ) {
			$n = (int) $len->raw;
			if ( $n >= 0 && $start + $n <= $this->size ) {
				$after = ltrim( $this->read( $start + $n, 32 ), self::ws() );
				if ( 0 === strncmp( $after, 'endstream', 9 ) ) {
					return $n;
				}
			}
		}
		// Search for "endstream".
		$step = 1048576;
		for ( $off = $start; $off < $this->size; $off += $step ) {
			$data = $this->read( $off, $step + 16 );
			$at   = strpos( $data, 'endstream' );
			if ( false !== $at && $at <= $step ) {
				$end = $off + $at;
				// Drop the EOL before "endstream".
				$prev = $this->read( max( $start, $end - 2 ), min( 2, $end - $start ) );
				if ( "\r\n" === $prev ) {
					$end -= 2;
				} elseif ( '' !== $prev && ( "\n" === substr( $prev, -1 ) || "\r" === substr( $prev, -1 ) ) ) {
					--$end;
				}
				return max( 0, $end - $start );
			}
		}
		throw new EBD_Pdf_Error( 'damaged', 'stream end' );
	}

	private static function ws(): string {
		return EBD_Pdf_Parser::WS;
	}

	/** @return array<int, array{0:int,1:mixed}> index => [num, value] */
	private function objstm_load( int $num ): array {
		if ( isset( $this->objstm[ $num ] ) ) {
			return $this->objstm[ $num ];
		}
		$e = $this->xref[ $num ] ?? null;
		if ( null === $e || 1 !== $e[0] ) {
			throw new EBD_Pdf_Error( 'damaged', 'object stream' );
		}
		$s = $this->parse_indirect_at( $e[1], $num );
		if ( ! $s instanceof EBD_Pdf_Stream ) {
			throw new EBD_Pdf_Error( 'damaged', 'object stream' );
		}
		$data  = $this->decode( $s );
		$n     = $this->int( $s->dict->get( 'N' ) );
		$first = $this->int( $s->dict->get( 'First' ) );
		$hp    = new EBD_Pdf_Parser( substr( $data, 0, $first ), true );
		$pairs = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$a = $hp->value();
			$b = $hp->value();
			if ( ! $a instanceof EBD_Pdf_Raw || ! $b instanceof EBD_Pdf_Raw ) {
				break;
			}
			$pairs[] = array( (int) $a->raw, (int) $b->raw );
		}
		$out = array();
		$p   = new EBD_Pdf_Parser( $data, true );
		foreach ( $pairs as $i => $pair ) {
			$p->pos = $first + $pair[1];
			try {
				$out[ $i ] = array( $pair[0], $p->value() );
			} catch ( EBD_Pdf_Error $ex ) {
				$out[ $i ] = array( $pair[0], new EBD_Pdf_Raw( 'null' ) );
			}
		}
		if ( count( $this->objstm ) > 64 ) {
			$this->objstm = array();
		}
		$this->objstm[ $num ] = $out;
		return $out;
	}

	public function int( $v ): int {
		$v = $this->resolve( $v );
		return $v instanceof EBD_Pdf_Raw && is_numeric( $v->raw ) ? (int) $v->raw : 0;
	}

	/* ---------------------------------------------------------------------
	 * Stream data
	 * ------------------------------------------------------------------- */

	/**
	 * Decoded stream data. Supports FlateDecode (with PNG / TIFF predictors),
	 * ASCIIHexDecode and ASCII85Decode. Anything else throws 'unsupported'.
	 */
	public function decode( EBD_Pdf_Stream $s, int $max = 268435456 ): string {
		if ( $s->length > $max ) {
			throw new EBD_Pdf_Error( 'unsupported', 'stream too large' );
		}
		$data    = $this->read( $s->offset, $s->length );
		$filters = $this->resolve( $s->dict->get( 'Filter' ) );
		$parms   = $this->resolve( $s->dict->get( 'DecodeParms' ) );
		if ( null === $filters || ( $filters instanceof EBD_Pdf_Raw && 'null' === $filters->raw ) ) {
			return $data;
		}
		$filters = is_array( $filters ) ? $filters : array( $filters );
		$parms   = is_array( $parms ) ? $parms : array( $parms );
		foreach ( $filters as $i => $f ) {
			$f = $this->resolve( $f );
			$name = $f instanceof EBD_Pdf_Name ? $f->name : '';
			$parm = $this->resolve( $parms[ $i ] ?? null );
			switch ( $name ) {
				case 'FlateDecode':
				case 'Fl':
					$data = self::inflate( $data );
					if ( $parm instanceof EBD_Pdf_Dict ) {
						$data = $this->unpredict( $data, $parm );
					}
					break;
				case 'ASCIIHexDecode':
				case 'AHx':
					$hex  = preg_replace( '/[^0-9A-Fa-f]/', '', (string) strstr( $data . '>', '>', true ) );
					$data = (string) hex2bin( strlen( $hex ) % 2 ? $hex . '0' : $hex );
					break;
				case 'ASCII85Decode':
				case 'A85':
					$data = self::a85( $data );
					break;
				default:
					throw new EBD_Pdf_Error( 'unsupported', 'filter ' . $name );
			}
		}
		return $data;
	}

	public static function inflate( string $data ): string {
		$out = @gzuncompress( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- tried in order below.
		if ( false !== $out ) {
			return $out;
		}
		$out = @gzinflate( substr( $data, 2 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false !== $out ) {
			return $out;
		}
		// Truncated or damaged data: keep what inflates.
		if ( function_exists( 'inflate_init' ) ) {
			$ctx = @inflate_init( ZLIB_ENCODING_DEFLATE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $ctx ) {
				$out = @inflate_add( $ctx, $data, ZLIB_SYNC_FLUSH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( is_string( $out ) && '' !== $out ) {
					return $out;
				}
			}
		}
		throw new EBD_Pdf_Error( 'damaged', 'inflate' );
	}

	private function unpredict( string $data, EBD_Pdf_Dict $p ): string {
		$pred = $this->int( $p->get( 'Predictor' ) );
		if ( $pred < 2 ) {
			return $data;
		}
		$colors  = max( 1, $this->int( $p->get( 'Colors' ) ) ?: 1 );
		$bpc     = max( 1, $this->int( $p->get( 'BitsPerComponent' ) ) ?: 8 );
		$columns = max( 1, $this->int( $p->get( 'Columns' ) ) ?: 1 );
		$bpp     = (int) max( 1, ceil( $colors * $bpc / 8 ) );
		$rowlen  = (int) ceil( $columns * $colors * $bpc / 8 );

		if ( 2 === $pred ) {
			if ( 8 !== $bpc ) {
				throw new EBD_Pdf_Error( 'unsupported', 'tiff predictor' );
			}
			$out = '';
			foreach ( str_split( $data, $rowlen ) as $row ) {
				for ( $i = $bpp, $n = strlen( $row ); $i < $n; $i++ ) {
					$row[ $i ] = chr( ( ord( $row[ $i ] ) + ord( $row[ $i - $bpp ] ) ) & 0xFF );
				}
				$out .= $row;
			}
			return $out;
		}

		// PNG predictors (10–15): one filter byte per row.
		$out   = '';
		$prior = str_repeat( "\0", $rowlen );
		$len   = strlen( $data );
		for ( $pos = 0; $pos < $len; $pos += $rowlen + 1 ) {
			$type = ord( $data[ $pos ] );
			$row  = substr( $data, $pos + 1, $rowlen );
			if ( strlen( $row ) < $rowlen ) {
				$row = str_pad( $row, $rowlen, "\0" );
			}
			switch ( $type ) {
				case 0:
					break;
				case 1:
					for ( $i = $bpp; $i < $rowlen; $i++ ) {
						$row[ $i ] = chr( ( ord( $row[ $i ] ) + ord( $row[ $i - $bpp ] ) ) & 0xFF );
					}
					break;
				case 2:
					for ( $i = 0; $i < $rowlen; $i++ ) {
						$row[ $i ] = chr( ( ord( $row[ $i ] ) + ord( $prior[ $i ] ) ) & 0xFF );
					}
					break;
				case 3:
					for ( $i = 0; $i < $rowlen; $i++ ) {
						$left      = $i >= $bpp ? ord( $row[ $i - $bpp ] ) : 0;
						$row[ $i ] = chr( ( ord( $row[ $i ] ) + ( ( $left + ord( $prior[ $i ] ) ) >> 1 ) ) & 0xFF );
					}
					break;
				case 4:
					for ( $i = 0; $i < $rowlen; $i++ ) {
						$a  = $i >= $bpp ? ord( $row[ $i - $bpp ] ) : 0;
						$b  = ord( $prior[ $i ] );
						$c  = $i >= $bpp ? ord( $prior[ $i - $bpp ] ) : 0;
						$pp = $a + $b - $c;
						$pa = abs( $pp - $a );
						$pb = abs( $pp - $b );
						$pc = abs( $pp - $c );
						$pr = ( $pa <= $pb && $pa <= $pc ) ? $a : ( $pb <= $pc ? $b : $c );
						$row[ $i ] = chr( ( ord( $row[ $i ] ) + $pr ) & 0xFF );
					}
					break;
				default:
					throw new EBD_Pdf_Error( 'damaged', 'png predictor' );
			}
			$out  .= $row;
			$prior = $row;
		}
		return $out;
	}

	private static function a85( string $data ): string {
		$data = preg_replace( '/\s+/', '', $data );
		if ( 0 === strncmp( $data, '<~', 2 ) ) {
			$data = substr( $data, 2 );
		}
		$end = strpos( $data, '~>' );
		if ( false !== $end ) {
			$data = substr( $data, 0, $end );
		}
		$out   = '';
		$tuple = array();
		$len   = strlen( $data );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $data[ $i ];
			if ( 'z' === $c && ! $tuple ) {
				$out .= "\0\0\0\0";
				continue;
			}
			$tuple[] = ord( $c ) - 33;
			if ( 5 === count( $tuple ) ) {
				$v    = ( ( ( ( $tuple[0] * 85 + $tuple[1] ) * 85 + $tuple[2] ) * 85 + $tuple[3] ) * 85 + $tuple[4] );
				$out .= pack( 'N', $v & 0xFFFFFFFF );
				$tuple = array();
			}
		}
		if ( $tuple ) {
			$n = count( $tuple );
			$tuple = array_pad( $tuple, 5, 84 );
			$v     = ( ( ( ( $tuple[0] * 85 + $tuple[1] ) * 85 + $tuple[2] ) * 85 + $tuple[3] ) * 85 + $tuple[4] );
			$out  .= substr( pack( 'N', $v & 0xFFFFFFFF ), 0, $n - 1 );
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Page tree
	 * ------------------------------------------------------------------- */

	/**
	 * Every page in reading order.
	 *
	 * @return array<int, array{num:int, dict:EBD_Pdf_Dict, inherit:array}>
	 */
	public function pages(): array {
		if ( null !== $this->pages ) {
			return $this->pages;
		}
		$out     = array();
		$visited = array();
		$root    = $this->catalog()->get( 'Pages' );
		$this->walk( $root, array(), $out, $visited, 0 );
		if ( ! $out ) {
			throw new EBD_Pdf_Error( 'no_pages' );
		}
		$this->pages = $out;
		return $out;
	}

	/** Object numbers of every node of the page tree (pages and intermediate nodes). */
	public function page_tree_nums(): array {
		$this->pages();
		return $this->tree_nums;
	}

	/** @var array<int, bool> */
	private $tree_nums = array();

	private function walk( $node_ref, array $inherit, array &$out, array &$visited, int $depth ): void {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}
		$num = $node_ref instanceof EBD_Pdf_Ref ? $node_ref->num : 0;
		if ( $num ) {
			if ( isset( $visited[ $num ] ) ) {
				return;
			}
			$visited[ $num ]          = true;
			$this->tree_nums[ $num ] = true;
		}
		$node = $this->resolve( $node_ref );
		if ( ! $node instanceof EBD_Pdf_Dict ) {
			return;
		}
		foreach ( array( 'Resources', 'MediaBox', 'CropBox', 'Rotate' ) as $k ) {
			if ( null !== $node->get( $k ) ) {
				$inherit[ $k ] = $node->get( $k );
			}
		}
		$type = $node->get( 'Type' );
		$kids = $this->resolve( $node->get( 'Kids' ) );
		$is_pages = ( $type instanceof EBD_Pdf_Name && 'Pages' === $type->name ) || ( ! $type instanceof EBD_Pdf_Name && is_array( $kids ) );
		if ( $is_pages ) {
			foreach ( is_array( $kids ) ? $kids : array() as $kid ) {
				$this->walk( $kid, $inherit, $out, $visited, $depth + 1 );
			}
			return;
		}
		if ( ! $num ) {
			return; // A page must be an indirect object.
		}
		$out[] = array(
			'num'     => $num,
			'dict'    => $node,
			'inherit' => $inherit,
		);
	}
}

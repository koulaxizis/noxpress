<?php
/**
 * BPV_Pdf — pure PHP page extraction (no Composer, no third-party code).
 *
 * Reads a PDF with a classic cross-reference table, a cross-reference
 * stream (PDF 1.5+), object streams, hybrid files and incremental updates,
 * and writes a NEW PDF that holds only the chosen pages. Pages are copied
 * as they are (content streams byte for byte, fonts, images), so the
 * sample looks exactly like the book.
 *
 * What never reaches the sample:
 *  - every page that was not chosen: a reference to another page (a link,
 *    an annotation's /P) becomes null, and the page tree is new;
 *  - images that the chosen pages do not draw: page and form /XObject
 *    resources are pruned to the names the content actually uses, because
 *    some producers share one resource dictionary across the whole book;
 *  - outlines, the structure tree, forms, JavaScript, private application
 *    data (/PieceInfo) and the document metadata.
 *
 * Damaged cross-references are rebuilt by scanning the file for objects.
 * Encrypted files are refused (BPV_Pdf_Error 'encrypted'); the engine
 * class may then try qpdf or Ghostscript.
 *
 * Bible §10 deviation: the reader and the PDF values are instances, not
 * static classes, because one request may open several files.
 */

defined( 'ABSPATH' ) || exit;

/** An error with a machine reason: not_pdf, encrypted, damaged, unsupported, io, no_pages. */
final class BPV_Pdf_Error extends \RuntimeException {

	/** @var string */
	public $reason;

	public function __construct( string $reason, string $detail = '' ) {
		parent::__construct( '' !== $detail ? $reason . ': ' . $detail : $reason );
		$this->reason = $reason;
	}
}

/** Thrown inside the parser when the buffer ends before the object does. */
final class BPV_Pdf_Eof extends \RuntimeException {
}

/** Indirect reference "num gen R". */
final class BPV_Pdf_Ref {
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
final class BPV_Pdf_Name {
	/** @var string */
	public $name;

	public function __construct( string $name ) {
		$this->name = $name;
	}
}

/** Numbers, strings, booleans and null, kept as their original bytes. */
final class BPV_Pdf_Raw {
	/** @var string */
	public $raw;

	public function __construct( string $raw ) {
		$this->raw = $raw;
	}
}

final class BPV_Pdf_Dict {
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
final class BPV_Pdf_Stream {
	/** @var BPV_Pdf_Dict */
	public $dict;
	/** @var int */
	public $offset;
	/** @var int */
	public $length;

	public function __construct( BPV_Pdf_Dict $dict, int $offset, int $length ) {
		$this->dict   = $dict;
		$this->offset = $offset;
		$this->length = $length;
	}
}

/**
 * Tokenizer / parser over a string buffer. Throws BPV_Pdf_Eof when the
 * buffer ends inside an object, so the reader can retry with more data.
 */
final class BPV_Pdf_Parser {

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
		throw new BPV_Pdf_Eof();
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
		} catch ( BPV_Pdf_Eof $e ) {
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
			throw new BPV_Pdf_Error( 'damaged', 'expected ' . $word );
		}
	}

	/** One object (dict, array, name, string, number, ref, bool, null). */
	public function value( int $depth = 0 ) {
		if ( $depth > 200 ) {
			throw new BPV_Pdf_Error( 'damaged', 'nesting' );
		}
		$this->skip_ws();
		$len = strlen( $this->buf );
		if ( $this->pos >= $len ) {
			throw new BPV_Pdf_Error( 'damaged', 'unexpected end' );
		}
		$c = $this->buf[ $this->pos ];

		if ( '/' === $c ) {
			++$this->pos;
			return new BPV_Pdf_Name( self::decode_name( $this->word() ) );
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
			return new BPV_Pdf_Raw( $raw );
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
			return new BPV_Pdf_Raw( $this->literal() );
		}
		if ( ')' === $c || '>' === $c || ']' === $c || '{' === $c || '}' === $c ) {
			// Stray delimiter: skip it so a damaged object does not stop the parse.
			++$this->pos;
			return new BPV_Pdf_Raw( 'null' );
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
						return new BPV_Pdf_Ref( (int) $w, (int) $gen );
					}
				}
			} catch ( BPV_Pdf_Eof $e ) {
				if ( ! $this->at_eof ) {
					throw $e;
				}
			} catch ( BPV_Pdf_Error $e ) {
				// Not a reference.
				unset( $e );
			}
			$this->pos = $save;
			return new BPV_Pdf_Raw( $w );
		}
		return new BPV_Pdf_Raw( $w ); // Real numbers, true, false, null, and stray keywords.
	}

	private function bad( string $what ): void {
		throw new BPV_Pdf_Error( 'damaged', $what );
	}

	private function dict_body( int $depth ): BPV_Pdf_Dict {
		$d   = new BPV_Pdf_Dict();
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
			if ( ! $key instanceof BPV_Pdf_Name ) {
				continue; // Damaged entry: skip the stray token.
			}
			$this->skip_ws();
			if ( $this->pos < $len && '>' === $this->buf[ $this->pos ] ) {
				$d->set( $key->name, new BPV_Pdf_Raw( 'null' ) );
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
final class BPV_Pdf_Reader {

	const CHUNK      = 65536;
	const MAX_OBJECT = 33554432; // 32 MB for one object's dictionary (never stream data).
	const MAX_DEPTH  = 64;

	/** @var resource */
	private $fh;
	/** @var int */
	public $size;
	/** @var string */
	public $version = '1.4';
	/** @var BPV_Pdf_Dict */
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
	private $base = 0;

	public function __construct( string $path ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new BPV_Pdf_Error( 'io', 'not readable' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- binary random access.
		$fh = fopen( $path, 'rb' );
		if ( ! $fh ) {
			throw new BPV_Pdf_Error( 'io', 'open' );
		}
		$this->fh   = $fh;
		$this->size = (int) filesize( $path );

		$head = $this->read( 0, 1024 );
		$at   = strpos( $head, '%PDF-' );
		if ( false === $at ) {
			throw new BPV_Pdf_Error( 'not_pdf' );
		}
		$this->base = $at;
		if ( preg_match( '/%PDF-(\d\.\d)/', $head, $m ) ) {
			$this->version = $m[1];
		}

		try {
			$this->load_xref();
			$this->check_root();
		} catch ( BPV_Pdf_Error $e ) {
			if ( 'encrypted' === $e->reason ) {
				throw $e;
			}
			$this->rebuild();
			$this->check_root();
		}
		if ( null !== $this->trailer->get( 'Encrypt' ) ) {
			throw new BPV_Pdf_Error( 'encrypted' );
		}
		// The catalog may raise the version (/Version, PDF 1.4+).
		$v = $this->resolve( $this->catalog()->get( 'Version' ) );
		if ( $v instanceof BPV_Pdf_Name && preg_match( '/^\d\.\d$/', $v->name ) && version_compare( $v->name, $this->version, '>' ) ) {
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
				throw new BPV_Pdf_Error( 'damaged', 'stream past end of file' );
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
			throw new BPV_Pdf_Error( 'damaged', 'no startxref' );
		}
		$offset = (int) end( $m[1] );

		$seen          = array();
		$this->trailer = null;
		while ( $offset > 0 ) {
			$offset = $this->fix_offset( $offset );
			if ( isset( $seen[ $offset ] ) || count( $seen ) > 500 ) {
				break;
			}
			$seen[ $offset ] = true;
			$trailer         = $this->xref_section( $offset );
			if ( null === $this->trailer ) {
				$this->trailer = $trailer;
			}
			$prev = $trailer->get( 'Prev' );
			if ( ! $prev instanceof BPV_Pdf_Raw || ! is_numeric( $prev->raw ) ) {
				break;
			}
			$offset = (int) $prev->raw;
		}
		if ( ! $this->trailer instanceof BPV_Pdf_Dict ) {
			throw new BPV_Pdf_Error( 'damaged', 'no trailer' );
		}
	}

	/** Some files count offsets from the "%PDF-" header, not from byte 0. */
	private function fix_offset( int $offset ): int {
		return $offset + $this->base;
	}

	/** One cross-reference section (table or stream); returns its trailer. */
	private function xref_section( int $offset ): BPV_Pdf_Dict {
		$head = $this->read( $offset, 32 );
		if ( 0 === strncmp( ltrim( $head ), 'xref', 4 ) ) {
			return $this->xref_table( $offset );
		}
		// A cross-reference stream: "n g obj << /Type /XRef … >> stream".
		$obj = $this->parse_indirect_at( $offset, null );
		if ( ! $obj instanceof BPV_Pdf_Stream ) {
			throw new BPV_Pdf_Error( 'damaged', 'xref' );
		}
		$this->xref_stream( $obj );
		return $obj->dict;
	}

	private function xref_table( int $offset ): BPV_Pdf_Dict {
		$len  = self::CHUNK;
		$data = '';
		$p    = null;
		while ( true ) {
			$data  = $this->read( $offset, $len );
			$atend = $offset + strlen( $data ) >= $this->size;
			$tpos  = strpos( $data, 'trailer' );
			if ( false !== $tpos ) {
				$p = new BPV_Pdf_Parser( $data, $atend );
				try {
					$p->pos = $tpos + 7;
					$trailer = $p->value();
					break;
				} catch ( BPV_Pdf_Eof $e ) {
					unset( $e );
				}
			}
			if ( $atend || $len >= self::MAX_OBJECT ) {
				throw new BPV_Pdf_Error( 'damaged', 'xref table' );
			}
			$len *= 2;
		}
		if ( ! $trailer instanceof BPV_Pdf_Dict ) {
			throw new BPV_Pdf_Error( 'damaged', 'trailer' );
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
			throw new BPV_Pdf_Error( 'damaged', 'xref line' );
		}

		// Hybrid file: an extra xref stream for the objects in object streams.
		$stm = $trailer->get( 'XRefStm' );
		if ( $stm instanceof BPV_Pdf_Raw && is_numeric( $stm->raw ) ) {
			$obj = $this->parse_indirect_at( $this->fix_offset( (int) $stm->raw ), null );
			if ( $obj instanceof BPV_Pdf_Stream ) {
				$this->xref_stream( $obj );
			}
		}
		return $trailer;
	}

	private function xref_stream( BPV_Pdf_Stream $s ): void {
		$data = $this->decode( $s );
		$w    = $this->resolve( $s->dict->get( 'W' ) );
		if ( ! is_array( $w ) || 3 !== count( $w ) ) {
			throw new BPV_Pdf_Error( 'damaged', 'xref W' );
		}
		$w     = array_map( array( $this, 'int' ), $w );
		$size  = $this->int( $s->dict->get( 'Size' ) );
		$index = $this->resolve( $s->dict->get( 'Index' ) );
		$index = is_array( $index ) ? array_map( array( $this, 'int' ), $index ) : array( 0, $size );
		$row   = $w[0] + $w[1] + $w[2];
		if ( $row <= 0 || $w[0] < 0 || $w[1] < 0 || $w[2] < 0 || max( $w ) > 8 ) {
			throw new BPV_Pdf_Error( 'damaged', 'xref W' );
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
			if ( ! $o instanceof BPV_Pdf_Stream ) {
				continue;
			}
			$type = $o->dict->get( 'Type' );
			if ( $type instanceof BPV_Pdf_Name && 'ObjStm' === $type->name ) {
				$objstms[] = $num;
			} elseif ( $type instanceof BPV_Pdf_Name && 'XRef' === $type->name ) {
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
				$p = new BPV_Pdf_Parser( $this->read( $tp, self::CHUNK ), true );
				$t = $p->value();
				if ( $t instanceof BPV_Pdf_Dict && null !== $t->get( 'Root' ) ) {
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
				$type = $o instanceof BPV_Pdf_Dict ? $o->get( 'Type' ) : null;
				if ( $type instanceof BPV_Pdf_Name && 'Catalog' === $type->name ) {
					$this->trailer = new BPV_Pdf_Dict();
					$this->trailer->set( 'Root', new BPV_Pdf_Ref( $num, $this->xref[ $num ][2] ?? 0 ) );
				}
			}
		}
		if ( null === $this->trailer ) {
			throw new BPV_Pdf_Error( 'damaged', 'no catalog' );
		}
	}

	private function check_root(): void {
		$root = $this->catalog();
		if ( ! $root->get( 'Pages' ) ) {
			throw new BPV_Pdf_Error( 'damaged', 'no page tree' );
		}
	}

	public function catalog(): BPV_Pdf_Dict {
		$root = $this->resolve( $this->trailer->get( 'Root' ) );
		if ( ! $root instanceof BPV_Pdf_Dict ) {
			throw new BPV_Pdf_Error( 'damaged', 'no catalog' );
		}
		return $root;
	}

	/* ---------------------------------------------------------------------
	 * Objects
	 * ------------------------------------------------------------------- */

	/** Resolves a reference (any depth); other values pass through. */
	public function resolve( $v ) {
		$guard = 0;
		while ( $v instanceof BPV_Pdf_Ref && $guard++ < 32 ) {
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
			return new BPV_Pdf_Raw( 'null' );
		}
		$this->cache[ $num ] = new BPV_Pdf_Raw( 'null' ); // Guards against cycles while parsing.
		if ( 1 === $e[0] ) {
			$v = $this->parse_indirect_at( $e[1], $num );
		} else {
			$list = $this->objstm_load( $e[1] );
			$v    = new BPV_Pdf_Raw( 'null' );
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
			$p     = new BPV_Pdf_Parser( $buf, $atend );
			try {
				$p->skip_ws();
				$n = $p->value();
				$g = $p->value();
				$p->expect_word( 'obj' );
				if ( ! $n instanceof BPV_Pdf_Raw || ! $g instanceof BPV_Pdf_Raw ) {
					throw new BPV_Pdf_Error( 'damaged', 'object header' );
				}
				if ( null !== $expect && (int) $n->raw !== $expect ) {
					throw new BPV_Pdf_Error( 'damaged', 'object number' );
				}
				$v = $p->value();
				if ( $v instanceof BPV_Pdf_Dict && 'stream' === $p->peek_word() ) {
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
					return new BPV_Pdf_Stream( $v, $start, $this->stream_length( $v, $start ) );
				}
				return $v;
			} catch ( BPV_Pdf_Eof $e ) {
				if ( $atend || $len >= self::MAX_OBJECT ) {
					throw new BPV_Pdf_Error( 'damaged', 'object too large' );
				}
				$len *= 4;
			}
		}
	}

	/** /Length when it is right, else the distance to "endstream". */
	private function stream_length( BPV_Pdf_Dict $d, int $start ): int {
		$len = $d->get( 'Length' );
		if ( $len instanceof BPV_Pdf_Ref ) {
			$len = $this->resolve( $len );
		}
		if ( $len instanceof BPV_Pdf_Raw && is_numeric( $len->raw ) ) {
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
		throw new BPV_Pdf_Error( 'damaged', 'stream end' );
	}

	private static function ws(): string {
		return BPV_Pdf_Parser::WS;
	}

	/** @return array<int, array{0:int,1:mixed}> index => [num, value] */
	private function objstm_load( int $num ): array {
		if ( isset( $this->objstm[ $num ] ) ) {
			return $this->objstm[ $num ];
		}
		$e = $this->xref[ $num ] ?? null;
		if ( null === $e || 1 !== $e[0] ) {
			throw new BPV_Pdf_Error( 'damaged', 'object stream' );
		}
		$s = $this->parse_indirect_at( $e[1], $num );
		if ( ! $s instanceof BPV_Pdf_Stream ) {
			throw new BPV_Pdf_Error( 'damaged', 'object stream' );
		}
		$data  = $this->decode( $s );
		$n     = $this->int( $s->dict->get( 'N' ) );
		$first = $this->int( $s->dict->get( 'First' ) );
		$hp    = new BPV_Pdf_Parser( substr( $data, 0, $first ), true );
		$pairs = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$a = $hp->value();
			$b = $hp->value();
			if ( ! $a instanceof BPV_Pdf_Raw || ! $b instanceof BPV_Pdf_Raw ) {
				break;
			}
			$pairs[] = array( (int) $a->raw, (int) $b->raw );
		}
		$out = array();
		$p   = new BPV_Pdf_Parser( $data, true );
		foreach ( $pairs as $i => $pair ) {
			$p->pos = $first + $pair[1];
			try {
				$out[ $i ] = array( $pair[0], $p->value() );
			} catch ( BPV_Pdf_Error $ex ) {
				$out[ $i ] = array( $pair[0], new BPV_Pdf_Raw( 'null' ) );
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
		return $v instanceof BPV_Pdf_Raw && is_numeric( $v->raw ) ? (int) $v->raw : 0;
	}

	/* ---------------------------------------------------------------------
	 * Stream data
	 * ------------------------------------------------------------------- */

	/**
	 * Decoded stream data. Supports FlateDecode (with PNG / TIFF predictors),
	 * ASCIIHexDecode and ASCII85Decode. Anything else throws 'unsupported'.
	 */
	public function decode( BPV_Pdf_Stream $s, int $max = 268435456 ): string {
		if ( $s->length > $max ) {
			throw new BPV_Pdf_Error( 'unsupported', 'stream too large' );
		}
		$data    = $this->read( $s->offset, $s->length );
		$filters = $this->resolve( $s->dict->get( 'Filter' ) );
		$parms   = $this->resolve( $s->dict->get( 'DecodeParms' ) );
		if ( null === $filters || ( $filters instanceof BPV_Pdf_Raw && 'null' === $filters->raw ) ) {
			return $data;
		}
		$filters = is_array( $filters ) ? $filters : array( $filters );
		$parms   = is_array( $parms ) ? $parms : array( $parms );
		foreach ( $filters as $i => $f ) {
			$f = $this->resolve( $f );
			$name = $f instanceof BPV_Pdf_Name ? $f->name : '';
			$parm = $this->resolve( $parms[ $i ] ?? null );
			switch ( $name ) {
				case 'FlateDecode':
				case 'Fl':
					$data = self::inflate( $data );
					if ( $parm instanceof BPV_Pdf_Dict ) {
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
					throw new BPV_Pdf_Error( 'unsupported', 'filter ' . $name );
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
		throw new BPV_Pdf_Error( 'damaged', 'inflate' );
	}

	private function unpredict( string $data, BPV_Pdf_Dict $p ): string {
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
				throw new BPV_Pdf_Error( 'unsupported', 'tiff predictor' );
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
					throw new BPV_Pdf_Error( 'damaged', 'png predictor' );
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
	 * @return array<int, array{num:int, dict:BPV_Pdf_Dict, inherit:array}>
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
			throw new BPV_Pdf_Error( 'no_pages' );
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
		$num = $node_ref instanceof BPV_Pdf_Ref ? $node_ref->num : 0;
		if ( $num ) {
			if ( isset( $visited[ $num ] ) ) {
				return;
			}
			$visited[ $num ]          = true;
			$this->tree_nums[ $num ] = true;
		}
		$node = $this->resolve( $node_ref );
		if ( ! $node instanceof BPV_Pdf_Dict ) {
			return;
		}
		foreach ( array( 'Resources', 'MediaBox', 'CropBox', 'Rotate' ) as $k ) {
			if ( null !== $node->get( $k ) ) {
				$inherit[ $k ] = $node->get( $k );
			}
		}
		$type = $node->get( 'Type' );
		$kids = $this->resolve( $node->get( 'Kids' ) );
		$is_pages = ( $type instanceof BPV_Pdf_Name && 'Pages' === $type->name ) || ( ! $type instanceof BPV_Pdf_Name && is_array( $kids ) );
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

/**
 * Writer: a new PDF with only the chosen pages.
 */
final class BPV_Pdf_Writer {

	/** Page keys that never go to the sample. */
	const DROP_PAGE_KEYS = array( 'Parent', 'PieceInfo', 'B', 'AA', 'Metadata', 'StructParents', 'Thumb', 'ID', 'PZ', 'SeparationInfo', 'Tabs' );

	/** @var BPV_Pdf_Reader */
	private $r;
	/** @var array<int, int> old num => new num */
	private $map = array();
	/** @var int[] old nums waiting to be written */
	private $queue = array();
	/** @var array<int, bool> */
	private $blocked = array();
	/** @var int */
	private $next = 3; // 1 = catalog, 2 = page tree.
	/** @var array<int, int> new num => offset */
	private $offsets = array();
	/** @var resource */
	private $out;
	/** @var int */
	private $pos = 0;

	public function __construct( BPV_Pdf_Reader $reader ) {
		$this->r = $reader;
	}

	/**
	 * Writes the pages (0-based indexes, in the given order) to $path.
	 *
	 * @return int Pages written.
	 */
	public function write( array $indexes, string $path ): int {
		$pages  = $this->r->pages();
		$chosen = array();
		foreach ( $indexes as $i ) {
			if ( isset( $pages[ $i ] ) ) {
				$chosen[ $pages[ $i ]['num'] ] = $pages[ $i ];
			}
		}
		if ( ! $chosen ) {
			throw new BPV_Pdf_Error( 'no_pages' );
		}

		// References to anything else in the page tree become null.
		foreach ( $this->r->page_tree_nums() as $num => $unused ) {
			if ( ! isset( $chosen[ $num ] ) ) {
				$this->blocked[ $num ] = true;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$this->out = fopen( $path, 'wb' );
		if ( ! $this->out ) {
			throw new BPV_Pdf_Error( 'io', 'write' );
		}
		try {
			$version = version_compare( $this->r->version, '1.4', '<' ) ? '1.4' : $this->r->version;
			$this->emit( "%PDF-{$version}\n%\xE2\xE3\xCF\xD3\n" );

			// Page numbers first, in order, so the page objects keep their place.
			$kids = array();
			foreach ( $chosen as $num => $page ) {
				$kids[] = new BPV_Pdf_Ref( $this->num_for( $num ), 0 );
			}
			// Write the pages, then everything they reference.
			foreach ( $chosen as $num => $page ) {
				$this->write_page( $page );
			}
			while ( $this->queue ) {
				$old = array_shift( $this->queue );
				$this->write_object( $old );
			}

			$tree = new BPV_Pdf_Dict();
			$tree->set( 'Type', new BPV_Pdf_Name( 'Pages' ) );
			$tree->set( 'Kids', $kids );
			$tree->set( 'Count', new BPV_Pdf_Raw( (string) count( $kids ) ) );
			$this->begin( 2 );
			$this->emit( self::serialize( $tree ) . "\nendobj\n" );

			$cat = new BPV_Pdf_Dict();
			$cat->set( 'Type', new BPV_Pdf_Name( 'Catalog' ) );
			$cat->set( 'Pages', new BPV_Pdf_Ref( 2, 0 ) );
			// Keep how the book opens: single page or spreads, left to right or right to left.
			$src = $this->r->catalog();
			// Layers: without /OCProperties, viewers would ignore what is hidden.
			$oc = $this->r->resolve( $src->get( 'OCProperties' ) );
			if ( $oc instanceof BPV_Pdf_Dict ) {
				$cat->set( 'OCProperties', $this->remap( $oc ) );
				while ( $this->queue ) {
					$this->write_object( array_shift( $this->queue ) );
				}
			}
			foreach ( array( 'PageLayout', 'Lang' ) as $k ) {
				$v = $this->r->resolve( $src->get( $k ) );
				if ( $v instanceof BPV_Pdf_Name || $v instanceof BPV_Pdf_Raw ) {
					$cat->set( $k, $v );
				}
			}
			$vp = $this->r->resolve( $src->get( 'ViewerPreferences' ) );
			if ( $vp instanceof BPV_Pdf_Dict && $vp->get( 'Direction' ) instanceof BPV_Pdf_Name ) {
				$pref = new BPV_Pdf_Dict();
				$pref->set( 'Direction', $vp->get( 'Direction' ) );
				$cat->set( 'ViewerPreferences', $pref );
			}
			$this->begin( 1 );
			$this->emit( self::serialize( $cat ) . "\nendobj\n" );

			$info = $this->next++;
			$this->begin( $info );
			$this->emit( '<< /Producer (Book Preview) >>' . "\nendobj\n" );

			// Cross-reference table.
			$size = $this->next;
			$xref = $this->pos;
			$tab  = "xref\n0 {$size}\n0000000000 65535 f \n";
			for ( $i = 1; $i < $size; $i++ ) {
				$tab .= isset( $this->offsets[ $i ] ) ? sprintf( "%010d 00000 n \n", $this->offsets[ $i ] ) : "0000000000 65535 f \n";
			}
			$id    = bin2hex( random_bytes( 16 ) );
			$tab  .= "trailer\n<< /Size {$size} /Root 1 0 R /Info {$info} 0 R /ID [<{$id}><{$id}>] >>\nstartxref\n{$xref}\n%%EOF\n";
			$this->emit( $tab );
		} finally {
			fclose( $this->out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return count( $kids );
	}

	private function emit( string $s ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		if ( false === fwrite( $this->out, $s ) ) {
			throw new BPV_Pdf_Error( 'io', 'write' );
		}
		$this->pos += strlen( $s );
	}

	private function begin( int $num ): void {
		$this->offsets[ $num ] = $this->pos;
		$this->emit( "{$num} 0 obj\n" );
	}

	/** New number for an old object; queues it the first time. */
	private function num_for( int $old ): int {
		if ( ! isset( $this->map[ $old ] ) ) {
			$this->map[ $old ] = $this->next++;
			$this->queue[]     = $old;
		}
		return $this->map[ $old ];
	}

	private function write_page( array $page ): void {
		$num = $page['num'];
		// The page is written here, not from the queue.
		$this->queue = array_values( array_diff( $this->queue, array( $num ) ) );

		$d = new BPV_Pdf_Dict();
		foreach ( $page['dict']->items as $k => $v ) {
			if ( in_array( $k, self::DROP_PAGE_KEYS, true ) ) {
				continue;
			}
			$d->set( $k, $v );
		}
		foreach ( $page['inherit'] as $k => $v ) {
			if ( null === $d->get( $k ) ) {
				$d->set( $k, $v );
			}
		}
		$d->set( 'Type', new BPV_Pdf_Name( 'Page' ) );
		if ( null === $d->get( 'MediaBox' ) ) {
			$d->set( 'MediaBox', array( new BPV_Pdf_Raw( '0' ), new BPV_Pdf_Raw( '0' ), new BPV_Pdf_Raw( '595' ), new BPV_Pdf_Raw( '842' ) ) );
		}
		$res = $this->r->resolve( $d->get( 'Resources' ) );
		if ( $res instanceof BPV_Pdf_Dict ) {
			$d->set( 'Resources', $this->prune( $res, $this->content_of( $d->get( 'Contents' ) ) ) );
		} else {
			$d->set( 'Resources', new BPV_Pdf_Dict() );
		}

		$copy = $this->remap( $d );
		$copy->set( 'Parent', new BPV_Pdf_Ref( 2, 0 ) );
		$this->begin( $this->map[ $num ] );
		$this->emit( self::serialize( $copy ) . "\nendobj\n" );
	}

	private function write_object( int $old ): void {
		$new = $this->map[ $old ];
		$v   = $this->r->get( $old );
		$this->begin( $new );
		if ( $v instanceof BPV_Pdf_Stream ) {
			$dict = new BPV_Pdf_Dict();
			foreach ( $v->dict->items as $k => $item ) {
				if ( 'Length' === $k || 'PieceInfo' === $k || 'Metadata' === $k ) {
					continue;
				}
				$dict->set( $k, $item );
			}
			$sub = $this->r->resolve( $v->dict->get( 'Subtype' ) );
			if ( $sub instanceof BPV_Pdf_Name && 'Form' === $sub->name ) {
				$res = $this->r->resolve( $v->dict->get( 'Resources' ) );
				if ( $res instanceof BPV_Pdf_Dict ) {
					$dict->set( 'Resources', $this->prune( $res, $this->decode_or_null( $v ) ) );
				}
			}
			$dict = $this->remap( $dict );
			$dict->set( 'Length', new BPV_Pdf_Raw( (string) $v->length ) );
			$this->emit( self::serialize( $dict ) . "\nstream\n" );
			$this->r->copy_to( $v->offset, $v->length, $this->out );
			$this->pos += $v->length;
			$this->emit( "\nendstream\nendobj\n" );
			return;
		}
		$this->emit( self::serialize( $this->remap( $v ) ) . "\nendobj\n" );
	}

	/** Deep copy with references renumbered; links into the rest of the book become null. */
	private function remap( $v, int $depth = 0 ) {
		if ( $depth > 200 ) {
			return new BPV_Pdf_Raw( 'null' );
		}
		if ( $v instanceof BPV_Pdf_Ref ) {
			if ( isset( $this->blocked[ $v->num ] ) || ! $this->r->has( $v->num ) ) {
				return new BPV_Pdf_Raw( 'null' );
			}
			return new BPV_Pdf_Ref( $this->num_for( $v->num ), 0 );
		}
		if ( $v instanceof BPV_Pdf_Dict ) {
			$d = new BPV_Pdf_Dict();
			foreach ( $v->items as $k => $item ) {
				// Parents lead back into forms, outlines and the page tree.
				if ( 'Parent' === $k || 'StructParent' === $k || 'StructParents' === $k ) {
					continue;
				}
				$d->set( $k, $this->remap( $item, $depth + 1 ) );
			}
			return $d;
		}
		if ( is_array( $v ) ) {
			$out = array();
			foreach ( $v as $item ) {
				$out[] = $this->remap( $item, $depth + 1 );
			}
			return $out;
		}
		if ( $v instanceof BPV_Pdf_Stream ) {
			return new BPV_Pdf_Raw( 'null' ); // A direct stream cannot exist; defensive.
		}
		return $v;
	}

	/* ---------------------------------------------------------------------
	 * Resource pruning
	 * ------------------------------------------------------------------- */

	/** Decoded content of /Contents (stream or array), or null when it cannot be read. */
	private function content_of( $contents ): ?string {
		$contents = $this->r->resolve( $contents );
		$list     = is_array( $contents ) ? $contents : array( $contents );
		$out      = '';
		foreach ( $list as $c ) {
			$c = $this->r->resolve( $c );
			if ( null === $c || ( $c instanceof BPV_Pdf_Raw && 'null' === $c->raw ) ) {
				continue;
			}
			if ( ! $c instanceof BPV_Pdf_Stream ) {
				return null;
			}
			$data = $this->decode_or_null( $c );
			if ( null === $data ) {
				return null;
			}
			$out .= $data . "\n";
		}
		return $out;
	}

	private function decode_or_null( BPV_Pdf_Stream $s ): ?string {
		try {
			return $this->r->decode( $s, 67108864 );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** XObject names a content stream draws with "Do". */
	private static function used_xobjects( string $content ): array {
		$names = array();
		if ( preg_match_all( '#/([^\x00\x09\x0A\x0C\x0D\x20()<>\[\]{}/%]+)[\x00\x09\x0A\x0C\x0D\x20]*Do(?![^\x00\x09\x0A\x0C\x0D\x20()<>\[\]{}/%])#', $content, $m ) ) {
			foreach ( $m[1] as $n ) {
				$names[ BPV_Pdf_Parser::decode_name( $n ) ] = true;
			}
		}
		return $names;
	}

	/**
	 * A copy of $res with only the XObjects the content draws. Forms
	 * without their own /Resources use the owner's, so their names count
	 * too. When the content cannot be read, nothing is pruned.
	 */
	private function prune( BPV_Pdf_Dict $res, ?string $content ): BPV_Pdf_Dict {
		$xo = $this->r->resolve( $res->get( 'XObject' ) );
		if ( null === $content || ! $xo instanceof BPV_Pdf_Dict ) {
			return $res;
		}
		$used  = self::used_xobjects( $content );
		$queue = array_keys( $used );
		$seen  = array();
		while ( $queue ) {
			$name = (string) array_shift( $queue );
			if ( isset( $seen[ $name ] ) ) {
				continue;
			}
			$seen[ $name ] = true;
			$x             = $this->r->resolve( $xo->get( $name ) );
			if ( ! $x instanceof BPV_Pdf_Stream || null !== $x->dict->get( 'Resources' ) ) {
				continue;
			}
			$sub = $this->r->resolve( $x->dict->get( 'Subtype' ) );
			if ( ! $sub instanceof BPV_Pdf_Name || 'Form' !== $sub->name ) {
				continue;
			}
			$inner = $this->decode_or_null( $x );
			if ( null === $inner ) {
				return $res; // Cannot tell what the form draws: keep everything.
			}
			foreach ( array_keys( self::used_xobjects( $inner ) ) as $n ) {
				$used[ $n ] = true;
				$queue[]    = $n;
			}
		}
		// Type 3 fonts without their own resources draw with the page's.
		$fonts = $this->r->resolve( $res->get( 'Font' ) );
		if ( $fonts instanceof BPV_Pdf_Dict ) {
			foreach ( $fonts->items as $f ) {
				$f = $this->r->resolve( $f );
				if ( $f instanceof BPV_Pdf_Dict ) {
					$st = $this->r->resolve( $f->get( 'Subtype' ) );
					if ( $st instanceof BPV_Pdf_Name && 'Type3' === $st->name && null === $f->get( 'Resources' ) ) {
						return $res;
					}
				}
			}
		}

		$new_xo = new BPV_Pdf_Dict();
		foreach ( $xo->items as $k => $v ) {
			if ( isset( $used[ (string) $k ] ) ) {
				$new_xo->set( (string) $k, $v );
			}
		}
		$copy = new BPV_Pdf_Dict();
		foreach ( $res->items as $k => $v ) {
			$copy->set( $k, $v );
		}
		$copy->set( 'XObject', $new_xo );
		return $copy;
	}

	/* ---------------------------------------------------------------------
	 * Serialization
	 * ------------------------------------------------------------------- */

	public static function serialize( $v ): string {
		if ( $v instanceof BPV_Pdf_Raw ) {
			return $v->raw;
		}
		if ( $v instanceof BPV_Pdf_Name ) {
			return '/' . self::encode_name( $v->name );
		}
		if ( $v instanceof BPV_Pdf_Ref ) {
			return $v->num . ' ' . $v->gen . ' R';
		}
		if ( $v instanceof BPV_Pdf_Dict ) {
			$s = '<<';
			foreach ( $v->items as $k => $item ) {
				$s .= ' /' . self::encode_name( (string) $k ) . ' ' . self::serialize( $item );
			}
			return $s . ' >>';
		}
		if ( is_array( $v ) ) {
			$parts = array();
			foreach ( $v as $item ) {
				$parts[] = self::serialize( $item );
			}
			return '[' . implode( ' ', $parts ) . ']';
		}
		return 'null';
	}

	private static function encode_name( string $name ): string {
		return (string) preg_replace_callback(
			'/[^\x21-\x7E]|[#()<>\[\]{}\/%]/',
			static function ( $m ) {
				return sprintf( '#%02X', ord( $m[0] ) );
			},
			$name
		);
	}
}

<?php
/**
 * RS_PDF — a small PDF writer for the creator statements (1.15.0).
 *
 * Why our own: the Bible (§1) allows third-party code only when it cannot
 * be avoided, and a statement needs very little: A4 pages, text in one
 * Unicode font (Greek), lines and filled boxes. No external service, no
 * vendor library.
 *
 * Font: DejaVu Sans (assets/fonts/rs-sans.ttf, a copy reduced to Latin,
 * Greek and common punctuation; licence in assets/fonts/LICENSE-DejaVu.txt).
 * It is embedded as a CIDFontType2 with Identity-H encoding (CID = glyph
 * id). Every PDF carries only the glyphs it uses: the other glyphs are left
 * empty in the embedded copy, so a statement is a few tens of KB.
 * Bold is drawn as fill + thin stroke (text render mode 2), so one font file
 * is enough. A ToUnicode map keeps the text searchable and copyable.
 *
 * Coordinates are in points from the TOP-left corner (the writer flips them).
 */

defined( 'ABSPATH' ) || exit;

final class RS_PDF {

	const W = 595.28; // A4 width (pt).
	const H = 841.89; // A4 height (pt).

	/** @var array|null Parsed font, shared by every PDF of the request. */
	private static $font = null;

	/** @var string[] Content stream per page. */
	private $pages = array();

	/** @var int Current page index. */
	private $cur = -1;

	/** @var array<int,bool> Glyph ids used in this document. */
	private $used = array();

	/** @var array<int,int> gid => unicode code point (for ToUnicode). */
	private $uni = array();

	/** @var string Title in the document info. */
	private $title = '';

	public function __construct( string $title = '' ) {
		self::load_font();
		$this->title = $title;
	}

	/** Is the font file there and readable? (callers fall back to no PDF). */
	public static function available(): bool {
		try {
			self::load_font();
			return ! empty( self::$font );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function add_page(): void {
		$this->pages[] = '';
		$this->cur     = count( $this->pages ) - 1;
	}

	/* ---------------------------------------------------------------------
	 * Drawing
	 * ------------------------------------------------------------------- */

	/**
	 * Text at (x, y) where y is the baseline from the top.
	 *
	 * @param string $align 'L' | 'R' | 'C' (x is the left edge, the right edge or the centre).
	 * @param array  $rgb   Colour as three 0–1 floats.
	 */
	public function text( float $x, float $y, string $str, float $size = 10.0, bool $bold = false, string $align = 'L', array $rgb = array( 0.1, 0.1, 0.12 ) ): void {

		if ( '' === $str ) {
			return;
		}

		$w = $this->width( $str, $size );
		if ( 'R' === $align ) {
			$x -= $w;
		} elseif ( 'C' === $align ) {
			$x -= $w / 2;
		}

		$hex = $this->encode( $str );
		$col = self::rgb( $rgb );
		$s   = 'BT ';
		$s  .= $col . ' rg ' . $col . ' RG ';
		$s  .= $bold ? '2 Tr ' . self::n( $size * 0.035 ) . ' w ' : '0 Tr ';
		$s  .= '/F1 ' . self::n( $size ) . ' Tf ';
		$s  .= self::n( $x ) . ' ' . self::n( self::H - $y ) . ' Td ';
		$s  .= '<' . $hex . '> Tj ET' . "\n";

		$this->pages[ $this->cur ] .= $s;
	}

	/** Filled rectangle; (x, y) is the top-left corner. */
	public function rect( float $x, float $y, float $w, float $h, array $rgb ): void {
		$this->pages[ $this->cur ] .= self::rgb( $rgb ) . ' rg ' . self::n( $x ) . ' ' . self::n( self::H - $y - $h ) . ' ' . self::n( $w ) . ' ' . self::n( $h ) . " re f\n";
	}

	/** Straight line. */
	public function line( float $x1, float $y1, float $x2, float $y2, array $rgb = array( 0.8, 0.8, 0.84 ), float $width = 0.6 ): void {
		$this->pages[ $this->cur ] .= self::rgb( $rgb ) . ' RG ' . self::n( $width ) . ' w ' . self::n( $x1 ) . ' ' . self::n( self::H - $y1 ) . ' m ' . self::n( $x2 ) . ' ' . self::n( self::H - $y2 ) . " l S\n";
	}

	/** Width of a string in points. */
	public function width( string $str, float $size ): float {
		$f     = self::$font;
		$total = 0;
		foreach ( self::chars( $str ) as $cp ) {
			$gid    = $f['cmap'][ $cp ] ?? 0;
			$total += $f['widths'][ $gid ] ?? $f['widths'][0];
		}
		return $total * $size / $f['upm'];
	}

	/**
	 * Splits a string into lines no wider than $max (word wrap).
	 *
	 * @return string[]
	 */
	public function wrap( string $str, float $size, float $max ): array {
		$out  = array();
		$line = '';
		foreach ( preg_split( '/\s+/u', trim( $str ) ) as $word ) {
			$try = '' === $line ? $word : $line . ' ' . $word;
			if ( '' !== $line && $this->width( $try, $size ) > $max ) {
				$out[] = $line;
				$line  = $word;
			} else {
				$line = $try;
			}
		}
		if ( '' !== $line ) {
			$out[] = $line;
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Output
	 * ------------------------------------------------------------------- */

	/** The finished PDF as a binary string. */
	public function output(): string {

		$f    = self::$font;
		$objs = array(); // 1-based object bodies.
		$n    = count( $this->pages );

		// 1 catalog, 2 pages, 3 font (Type0), 4 CIDFont, 5 descriptor,
		// 6 font file, 7 ToUnicode, 8 info, then 2 per page (page, content).
		$kids = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$kids[] = ( 9 + $i * 2 ) . ' 0 R';
		}

		$objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objs[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . $n . ' >>';
		$objs[3] = '<< /Type /Font /Subtype /Type0 /BaseFont /RSSUBS+DejaVuSans /Encoding /Identity-H /DescendantFonts [4 0 R] /ToUnicode 7 0 R >>';

		$used = array_keys( $this->used );
		sort( $used );
		$w = '';
		foreach ( $used as $gid ) {
			$w .= $gid . ' [' . (int) round( ( $f['widths'][ $gid ] ?? $f['widths'][0] ) * 1000 / $f['upm'] ) . '] ';
		}
		$objs[4] = '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /RSSUBS+DejaVuSans /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor 5 0 R /CIDToGIDMap /Identity /DW 600 /W [' . $w . '] >>';

		$k       = 1000 / $f['upm'];
		$bbox    = array_map(
			static function ( $v ) use ( $k ) {
				return (int) round( $v * $k );
			},
			$f['bbox']
		);
		$objs[5] = '<< /Type /FontDescriptor /FontName /RSSUBS+DejaVuSans /Flags 32 /FontBBox [' . implode( ' ', $bbox ) . '] /ItalicAngle 0 /Ascent ' . (int) round( $f['ascent'] * $k ) . ' /Descent ' . (int) round( $f['descent'] * $k ) . ' /CapHeight ' . (int) round( $f['ascent'] * $k ) . ' /StemV 80 /FontFile2 6 0 R >>';

		$objs[6] = self::stream( self::subset( $used ), '/Length1 %RAW%' );
		$objs[7] = self::stream( $this->to_unicode( $used ) );
		$objs[8] = '<< /Title ' . self::utf16( $this->title ) . ' /Producer (Revenue Splitter) /CreationDate (D:' . gmdate( 'YmdHis' ) . 'Z) >>';

		for ( $i = 0; $i < $n; $i++ ) {
			$page_id          = 9 + $i * 2;
			$objs[ $page_id ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::n( self::W ) . ' ' . self::n( self::H ) . '] /Resources << /Font << /F1 3 0 R >> >> /Contents ' . ( $page_id + 1 ) . ' 0 R >>';
			$objs[ $page_id + 1 ] = self::stream( $this->pages[ $i ] );
		}

		ksort( $objs );
		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objs as $id => $body ) {
			$offsets[ $id ] = strlen( $pdf );
			$pdf           .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= 'xref' . "\n" . '0 ' . ( count( $objs ) + 1 ) . "\n" . "0000000000 65535 f \n";
		foreach ( $offsets as $off ) {
			$pdf .= sprintf( '%010d 00000 n ', $off ) . "\n";
		}
		$pdf .= 'trailer' . "\n" . '<< /Size ' . ( count( $objs ) + 1 ) . ' /Root 1 0 R /Info 8 0 R >>' . "\n" . 'startxref' . "\n" . $xref . "\n%%EOF\n";

		return $pdf;
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------- */

	/** Text → hex string of 2-byte glyph ids; records the glyphs used. */
	private function encode( string $str ): string {
		$f   = self::$font;
		$hex = '';
		foreach ( self::chars( $str ) as $cp ) {
			$gid = $f['cmap'][ $cp ] ?? 0;
			if ( $gid > 0 ) {
				$this->used[ $gid ] = true;
				if ( ! isset( $this->uni[ $gid ] ) ) {
					$this->uni[ $gid ] = $cp;
				}
			}
			$hex .= sprintf( '%04X', $gid );
		}
		return $hex;
	}

	/** @return int[] Unicode code points of a UTF-8 string. */
	private static function chars( string $str ): array {
		$out = array();
		$arr = preg_split( '//u', $str, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( (array) $arr as $ch ) {
			$out[] = (int) mb_ord( $ch, 'UTF-8' );
		}
		return $out;
	}

	private function to_unicode( array $used ): string {
		$s  = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n";
		$s .= "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n";
		$s .= "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
		foreach ( array_chunk( $used, 100 ) as $chunk ) {
			$s .= count( $chunk ) . " beginbfchar\n";
			foreach ( $chunk as $gid ) {
				$cp  = $this->uni[ $gid ] ?? 0;
				$u16 = mb_convert_encoding( mb_chr( $cp, 'UTF-8' ), 'UTF-16BE', 'UTF-8' );
				$s  .= sprintf( '<%04X>', $gid ) . ' <' . strtoupper( bin2hex( $u16 ) ) . ">\n";
			}
			$s .= "endbfchar\n";
		}
		$s .= "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
		return $s;
	}

	/** A stream object, Flate-compressed when zlib is there. */
	private static function stream( string $data, string $extra = '' ): string {
		$raw   = strlen( $data );
		$extra = str_replace( '%RAW%', (string) $raw, $extra );
		if ( function_exists( 'gzcompress' ) ) {
			$z = gzcompress( $data, 6 );
			if ( false !== $z ) {
				return '<< /Length ' . strlen( $z ) . ' /Filter /FlateDecode ' . $extra . " >>\nstream\n" . $z . "\nendstream";
			}
		}
		return '<< /Length ' . $raw . ' ' . $extra . " >>\nstream\n" . $data . "\nendstream";
	}

	private static function utf16( string $s ): string {
		$u = "\xFE\xFF" . mb_convert_encoding( $s, 'UTF-16BE', 'UTF-8' );
		return '(' . strtr(
			$u,
			array(
				'\\' => '\\\\',
				'('  => '\\(',
				')'  => '\\)',
				"\r" => '\\r',
			)
		) . ')';
	}

	private static function rgb( array $c ): string {
		return self::n( (float) ( $c[0] ?? 0 ) ) . ' ' . self::n( (float) ( $c[1] ?? 0 ) ) . ' ' . self::n( (float) ( $c[2] ?? 0 ) );
	}

	/** Number for PDF syntax: dot decimal, no locale, at most 3 decimals. */
	private static function n( float $v ): string {
		$s = number_format( $v, 3, '.', '' );
		$s = rtrim( rtrim( $s, '0' ), '.' );
		return '' === $s || '-0' === $s ? '0' : $s;
	}

	/* ---------------------------------------------------------------------
	 * TrueType: parse + subset
	 * ------------------------------------------------------------------- */

	public static function font_path(): string {
		return RS_PATH . 'assets/fonts/rs-sans.ttf';
	}

	private static function load_font(): void {

		if ( null !== self::$font ) {
			return;
		}

		$bin = is_readable( self::font_path() ) ? (string) file_get_contents( self::font_path() ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
		if ( strlen( $bin ) < 12 ) {
			throw new \RuntimeException( 'rs-pdf: font missing' );
		}

		self::$font = self::parse( $bin );
	}

	/**
	 * Reads the tables we need. Public for the build helper that reduces the
	 * shipped font (bin/ is not in the plugin; see the docblock above).
	 */
	public static function parse( string $bin ): array {

		$num    = self::u16( $bin, 4 );
		$tables = array();
		for ( $i = 0; $i < $num; $i++ ) {
			$o                                     = 12 + $i * 16;
			$tables[ substr( $bin, $o, 4 ) ] = array( self::u32( $bin, $o + 8 ), self::u32( $bin, $o + 12 ) );
		}
		foreach ( array( 'head', 'hhea', 'maxp', 'hmtx', 'loca', 'glyf', 'cmap' ) as $t ) {
			if ( ! isset( $tables[ $t ] ) ) {
				throw new \RuntimeException( 'rs-pdf: table ' . $t . ' missing' );
			}
		}

		$head   = $tables['head'][0];
		$upm    = self::u16( $bin, $head + 18 );
		$bbox   = array( self::s16( $bin, $head + 36 ), self::s16( $bin, $head + 38 ), self::s16( $bin, $head + 40 ), self::s16( $bin, $head + 42 ) );
		$locfmt = self::s16( $bin, $head + 50 );

		$hhea    = $tables['hhea'][0];
		$ascent  = self::s16( $bin, $hhea + 4 );
		$descent = self::s16( $bin, $hhea + 6 );
		$nhm     = self::u16( $bin, $hhea + 34 );

		$glyphs = self::u16( $bin, $tables['maxp'][0] + 4 );

		$widths = array();
		$hm     = $tables['hmtx'][0];
		$last   = 0;
		for ( $g = 0; $g < $glyphs; $g++ ) {
			if ( $g < $nhm ) {
				$last = self::u16( $bin, $hm + $g * 4 );
			}
			$widths[ $g ] = $last;
		}

		$loca = array();
		$lo   = $tables['loca'][0];
		for ( $g = 0; $g <= $glyphs; $g++ ) {
			$loca[ $g ] = 0 === $locfmt ? self::u16( $bin, $lo + $g * 2 ) * 2 : self::u32( $bin, $lo + $g * 4 );
		}

		// cmap: the Windows Unicode BMP subtable, format 4.
		$cm   = $tables['cmap'][0];
		$nsub = self::u16( $bin, $cm + 2 );
		$sub  = -1;
		for ( $i = 0; $i < $nsub; $i++ ) {
			$pid = self::u16( $bin, $cm + 4 + $i * 8 );
			$eid = self::u16( $bin, $cm + 6 + $i * 8 );
			$off = self::u32( $bin, $cm + 8 + $i * 8 );
			if ( 3 === $pid && 1 === $eid && 4 === self::u16( $bin, $cm + $off ) ) {
				$sub = $cm + $off;
				break;
			}
		}
		if ( $sub < 0 ) {
			throw new \RuntimeException( 'rs-pdf: no unicode cmap' );
		}
		$map   = array();
		$segx2 = self::u16( $bin, $sub + 6 );
		$ends  = $sub + 14;
		$strt  = $ends + $segx2 + 2;
		$dlt   = $strt + $segx2;
		$rng   = $dlt + $segx2;
		for ( $s = 0; $s < $segx2 / 2; $s++ ) {
			$end   = self::u16( $bin, $ends + $s * 2 );
			$start = self::u16( $bin, $strt + $s * 2 );
			$delta = self::s16( $bin, $dlt + $s * 2 );
			$ro    = self::u16( $bin, $rng + $s * 2 );
			for ( $c = $start; $c <= $end && 0xFFFF !== $c; $c++ ) {
				if ( 0 === $ro ) {
					$gid = ( $c + $delta ) & 0xFFFF;
				} else {
					$addr = $rng + $s * 2 + $ro + ( $c - $start ) * 2;
					$gid  = self::u16( $bin, $addr );
					if ( 0 !== $gid ) {
						$gid = ( $gid + $delta ) & 0xFFFF;
					}
				}
				if ( $gid > 0 && $gid < $glyphs ) {
					$map[ $c ] = $gid;
				}
			}
		}

		return array(
			'bin'     => $bin,
			'tables'  => $tables,
			'upm'     => $upm > 0 ? $upm : 1000,
			'bbox'    => $bbox,
			'ascent'  => $ascent,
			'descent' => $descent,
			'glyphs'  => $glyphs,
			'widths'  => $widths,
			'loca'    => $loca,
			'cmap'    => $map,
		);
	}

	/**
	 * A copy of the font where every glyph not in $gids (and not a component
	 * of one) is empty. Glyph ids stay the same, so Identity mapping works.
	 *
	 * @param int[] $gids
	 * @param bool  $keep_cmap Keep the cmap table (the shipped reduced font needs it; an embedded copy does not).
	 */
	public static function subset( array $gids, bool $keep_cmap = false, ?array $font = null ): string {

		$f    = $font ?? self::$font;
		$bin  = $f['bin'];
		$glyf = $f['tables']['glyf'][0];
		$keep = array( 0 => true );
		$todo = $gids;

		// Composite glyphs pull in their components.
		while ( ! empty( $todo ) ) {
			$g = (int) array_pop( $todo );
			if ( isset( $keep[ $g ] ) && 0 !== $g ) {
				continue;
			}
			$keep[ $g ] = true;
			$start      = $f['loca'][ $g ] ?? 0;
			$end        = $f['loca'][ $g + 1 ] ?? 0;
			if ( $end - $start < 10 || self::s16( $bin, $glyf + $start ) >= 0 ) {
				continue; // Empty or simple glyph.
			}
			$p = $glyf + $start + 10;
			do {
				$flags  = self::u16( $bin, $p );
				$comp   = self::u16( $bin, $p + 2 );
				$todo[] = $comp;
				$p     += 4 + ( ( $flags & 0x0001 ) ? 4 : 2 );
				if ( $flags & 0x0008 ) {
					$p += 2;
				} elseif ( $flags & 0x0040 ) {
					$p += 4;
				} elseif ( $flags & 0x0080 ) {
					$p += 8;
				}
			} while ( $flags & 0x0020 );
		}

		$new_glyf = '';
		$new_loca = '';
		for ( $g = 0; $g < $f['glyphs']; $g++ ) {
			$new_loca .= pack( 'N', strlen( $new_glyf ) );
			if ( isset( $keep[ $g ] ) ) {
				$data      = substr( $bin, $glyf + $f['loca'][ $g ], $f['loca'][ $g + 1 ] - $f['loca'][ $g ] );
				$new_glyf .= $data . str_repeat( "\0", ( 4 - strlen( $data ) % 4 ) % 4 );
			}
		}
		$new_loca .= pack( 'N', strlen( $new_glyf ) );

		$names = array( 'cvt ', 'fpgm', 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp', 'prep' );
		if ( $keep_cmap ) {
			$names[] = 'cmap';
			$names[] = 'name';
			$names[] = 'post';
			$names[] = 'OS/2';
			sort( $names );
		}

		$out = array();
		foreach ( $names as $t ) {
			if ( 'glyf' === $t ) {
				$out[ $t ] = $new_glyf;
			} elseif ( 'loca' === $t ) {
				$out[ $t ] = $new_loca;
			} elseif ( isset( $f['tables'][ $t ] ) ) {
				$out[ $t ] = substr( $bin, $f['tables'][ $t ][0], $f['tables'][ $t ][1] );
			}
		}
		// head: long loca offsets, checksum adjustment cleared.
		$out['head'] = substr_replace( $out['head'], pack( 'n', 1 ), 50, 2 );
		$out['head'] = substr_replace( $out['head'], "\0\0\0\0", 8, 4 );

		$count = count( $out );
		$es    = (int) floor( log( $count, 2 ) );
		$sr    = ( 2 ** $es ) * 16;
		$font  = pack( 'Nnnnn', 0x00010000, $count, $sr, $es, $count * 16 - $sr );
		$off   = 12 + $count * 16;
		$dir   = '';
		$body  = '';
		foreach ( $out as $tag => $data ) {
			$len   = strlen( $data );
			$pad   = $data . str_repeat( "\0", ( 4 - $len % 4 ) % 4 );
			$sum   = 0;
			$words = unpack( 'N*', $pad );
			foreach ( (array) $words as $wd ) {
				$sum = ( $sum + $wd ) & 0xFFFFFFFF;
			}
			$dir  .= $tag . pack( 'NNN', $sum, $off + strlen( $body ), $len );
			$body .= $pad;
		}

		return $font . $dir . $body;
	}

	private static function u16( string $b, int $o ): int {
		return ( ord( $b[ $o ] ) << 8 ) | ord( $b[ $o + 1 ] );
	}

	private static function s16( string $b, int $o ): int {
		$v = self::u16( $b, $o );
		return $v >= 0x8000 ? $v - 0x10000 : $v;
	}

	private static function u32( string $b, int $o ): int {
		return ( self::u16( $b, $o ) << 16 ) | self::u16( $b, $o + 2 );
	}
}

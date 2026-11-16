<?php
/**
 * EBD_Pdf_Stamp — puts the buyer's watermark into a PDF as an incremental
 * update (ISO 32000-1 §7.5.6).
 *
 * The original bytes are copied unchanged and new objects are appended:
 *  - the font (Noto Sans subset, CIDFontType2 + Identity-H, ToUnicode), so
 *    Greek, Latin and Cyrillic names print as they are written;
 *  - one shared "q" stream that opens every page and one watermark stream
 *    per page box: "Q" (closes the page's own state), then a visible line
 *    near the visual bottom of the page (rotation aware) and the
 *    fingerprint code as invisible text (render mode 3) in the middle of
 *    the page, both in a marked-content section /EBD << /C (code) >>;
 *  - a new version of every page object: Contents [q, original…, mark],
 *    Resources with our font added (one new resources object per shared
 *    original);
 *  - a new document information dictionary (the original entries, plus
 *    EBDCode, EBDLicensee and ModDate);
 *  - a cross-reference section of the same kind as the newest original
 *    one (a table, or a stream for files that use cross-reference streams),
 *    with /Prev pointing at it.
 *
 * Nothing is recompressed or rewritten, so a large book is stamped in the
 * time it takes to copy it, and the pages look exactly as before.
 *
 * Refused (EBD_Pdf_Error), so the caller serves the original file:
 * encrypted files, damaged cross-references (rebuilt by the reader: an
 * update on top would point at offsets that are wrong), files with bytes
 * before "%PDF-", files without pages.
 */

defined( 'ABSPATH' ) || exit;

final class EBD_Pdf_Stamp {

	/** Resource name of our font; a free variant is picked if a page uses it. */
	const FONT_RES = 'EBDWm';

	/** Gray of the visible line (0 black … 1 white). */
	const GRAY = '0.5';

	/** Distance of the line from the visual bottom edge, in points. */
	const MARGIN = 10;

	/** @var resource */
	private $out;
	/** @var EBD_Pdf_Reader */
	private $r;
	/** @var int Next free object number. */
	private $next = 0;
	/** @var array<int, array{0:int,1:int}> num => [offset, generation]. */
	private $offsets = array();

	/**
	 * Stamps $src into $dst (a new file; the caller renames it into place).
	 *
	 * @param string $src  Source PDF.
	 * @param string $dst  Target path (overwritten).
	 * @param array  $o    text (visible line, UTF-8), code (fingerprint),
	 *                     name (licensee), size (points, 5–12).
	 * @return int Pages stamped.
	 * @throws EBD_Pdf_Error When the file cannot take an incremental update.
	 */
	public static function stamp( string $src, string $dst, array $o ): int {
		$me = new self();
		return $me->run( $src, $dst, $o );
	}

	private function run( string $src, string $dst, array $o ): int {
		$r = new EBD_Pdf_Reader( $src );
		if ( $r->rebuilt || $r->base > 0 || $r->startxref <= 0 ) {
			throw new EBD_Pdf_Error( 'unsupported', 'cross-reference' );
		}
		$this->r    = $r;
		$pages      = $r->pages();
		$this->next = max( 1, $r->int( $r->trailer->get( 'Size' ) ) );

		$text = (string) ( $o['text'] ?? '' );
		$code = (string) ( $o['code'] ?? '' );
		$size = max( 5.0, min( 12.0, (float) ( $o['size'] ?? 7 ) ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- binary output.
		$out = fopen( $dst, 'wb' );
		if ( ! $out ) {
			throw new EBD_Pdf_Error( 'io', 'open target' );
		}
		$this->out = $out;
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- binary input.
			$in = fopen( $src, 'rb' );
			if ( ! $in ) {
				throw new EBD_Pdf_Error( 'io', 'open source' );
			}
			$copied = stream_copy_to_stream( $in, $out );
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			if ( $copied !== $r->size ) {
				throw new EBD_Pdf_Error( 'io', 'copy' );
			}
			$this->emit( "\n" );

			// Glyphs of both texts.
			$line_g = self::glyphs( $text );
			$code_g = self::glyphs( $code );
			$font   = $this->write_font( array_merge( $line_g, $code_g ) );

			$q_num = $this->alloc();
			$this->write_stream( $q_num, "q\n" );

			$res_new  = array(); // Original resources object num => new num.
			$mark_new = array(); // Box key => mark stream num.
			$count    = 0;

			foreach ( $pages as $page ) {
				/** @var EBD_Pdf_Dict $dict */
				$dict    = $page['dict'];
				$inherit = $page['inherit'];

				// Resources: the page's own, or inherited.
				$res_v = $dict->get( 'Resources' );
				if ( null === $res_v && isset( $inherit['Resources'] ) ) {
					$res_v = $inherit['Resources'];
				}
				$res_key = $res_v instanceof EBD_Pdf_Ref ? $res_v->num : 0;
				$res     = $r->resolve( $res_v );
				$res     = $res instanceof EBD_Pdf_Dict ? $res : new EBD_Pdf_Dict();
				$name    = self::free_name( $res );
				$new_res = $this->with_font( $res, $name, $font );
				if ( $res_key ) {
					$cache_key = $res_key . '/' . $name;
					if ( ! isset( $res_new[ $cache_key ] ) ) {
						$res_new[ $cache_key ] = $this->alloc();
						$this->write_object( $res_new[ $cache_key ], 0, self::serialize( $new_res ) );
					}
					$res_out = new EBD_Pdf_Ref( $res_new[ $cache_key ], 0 );
				} else {
					$res_out = $new_res;
				}

				// Visible box and rotation.
				$box = self::box( $r, $dict->get( 'CropBox' ) ?? ( $inherit['CropBox'] ?? null ) );
				if ( null === $box ) {
					$box = self::box( $r, $dict->get( 'MediaBox' ) ?? ( $inherit['MediaBox'] ?? null ) );
				}
				if ( null === $box ) {
					$box = array( 0.0, 0.0, 595.0, 842.0 );
				}
				$rot = $r->int( $dict->get( 'Rotate' ) ?? ( $inherit['Rotate'] ?? null ) );
				$rot = ( ( $rot % 360 ) + 360 ) % 360;
				$rot = in_array( $rot, array( 0, 90, 180, 270 ), true ) ? $rot : 0;

				$mk = implode( ',', array_map( 'strval', $box ) ) . '|' . $rot . '|' . $name;
				if ( ! isset( $mark_new[ $mk ] ) ) {
					$mark_new[ $mk ] = $this->alloc();
					$this->write_stream( $mark_new[ $mk ], self::mark( $box, $rot, $name, $size, $line_g, $code_g, $code ) );
				}

				// Contents: [q, original…, mark].
				$contents = array( new EBD_Pdf_Ref( $q_num, 0 ) );
				$orig     = $dict->get( 'Contents' );
				if ( $orig instanceof EBD_Pdf_Ref ) {
					$target = $r->resolve( $orig );
					if ( is_array( $target ) ) {
						foreach ( $target as $c ) {
							$contents[] = $c;
						}
					} else {
						$contents[] = $orig;
					}
				} elseif ( is_array( $orig ) ) {
					foreach ( $orig as $c ) {
						$contents[] = $c;
					}
				}
				$contents[] = new EBD_Pdf_Ref( $mark_new[ $mk ], 0 );

				$copy        = new EBD_Pdf_Dict();
				$copy->items = $dict->items;
				$copy->set( 'Contents', $contents );
				$copy->set( 'Resources', $res_out );
				$this->write_object( (int) $page['num'], $r->generation( (int) $page['num'] ), self::serialize( $copy ) );
				++$count;
			}

			// Document information.
			$info     = $r->resolve( $r->trailer->get( 'Info' ) );
			$new_info = new EBD_Pdf_Dict();
			if ( $info instanceof EBD_Pdf_Dict ) {
				$new_info->items = $info->items;
			}
			$new_info->set( 'EBDCode', new EBD_Pdf_Raw( self::text_string( $code ) ) );
			if ( '' !== (string) ( $o['name'] ?? '' ) ) {
				$new_info->set( 'EBDLicensee', new EBD_Pdf_Raw( self::text_string( (string) $o['name'] ) ) );
			}
			$new_info->set( 'ModDate', new EBD_Pdf_Raw( '(D:' . gmdate( 'YmdHis' ) . "Z)" ) );
			$info_num = $this->alloc();
			$this->write_object( $info_num, 0, self::serialize( $new_info ) );

			$this->write_xref( $info_num );
		} finally {
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return $count;
	}

	/* ---------------------------------------------------------------------
	 * Objects
	 * ------------------------------------------------------------------- */

	private function alloc(): int {
		return $this->next++;
	}

	private function emit( string $s ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		if ( strlen( $s ) !== fwrite( $this->out, $s ) ) {
			throw new EBD_Pdf_Error( 'io', 'write' );
		}
	}

	private function pos(): int {
		return (int) ftell( $this->out );
	}

	private function write_object( int $num, int $gen, string $body ): void {
		$this->offsets[ $num ] = array( $this->pos(), $gen );
		$this->emit( $num . ' ' . $gen . " obj\n" . $body . "\nendobj\n" );
	}

	/** A stream object; $extra are additional dictionary entries (serialized). */
	private function write_stream( int $num, string $data, string $extra = '' ): void {
		$this->offsets[ $num ] = array( $this->pos(), 0 );
		$this->emit( $num . " 0 obj\n<< /Length " . strlen( $data ) . ( '' !== $extra ? ' ' . $extra : '' ) . " >>\nstream\n" . $data . "\nendstream\nendobj\n" );
	}

	/**
	 * Writes the font objects; returns the Type0 font's object number.
	 *
	 * @param int[] $gids Glyph ids used (for the widths and ToUnicode).
	 */
	private function write_font( array $gids ): int {
		$path = EBD_Font::path();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local font file.
		$ttf = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
		if ( '' === $ttf ) {
			throw new EBD_Pdf_Error( 'io', 'font' );
		}
		$z = gzcompress( $ttf, 9 );
		if ( false === $z ) {
			throw new EBD_Pdf_Error( 'io', 'font compress' );
		}

		$base   = '/EBDWMK+NotoSans-Regular';
		$file_n = $this->alloc();
		$this->write_stream( $file_n, $z, '/Filter /FlateDecode /Length1 ' . strlen( $ttf ) );

		$desc_n = $this->alloc();
		$this->write_object(
			$desc_n,
			0,
			'<< /Type /FontDescriptor /FontName ' . $base . ' /Flags 32 /FontBBox ' . EBD_Font::BBOX
			. ' /ItalicAngle 0 /Ascent ' . EBD_Font::ASCENT . ' /Descent ' . EBD_Font::DESCENT
			. ' /CapHeight ' . EBD_Font::CAP_HEIGHT . ' /StemV 80 /FontFile2 ' . $file_n . ' 0 R >>'
		);

		// Widths and Unicode of the glyphs in use.
		$used = array();
		foreach ( EBD_Font::map() as $cp => $g ) {
			if ( in_array( $g[0], $gids, true ) && ! isset( $used[ $g[0] ] ) ) {
				$used[ $g[0] ] = array( $cp, $g[1] );
			}
		}
		ksort( $used );
		$w = '';
		foreach ( $used as $gid => $u ) {
			$w .= $gid . ' [' . $u[1] . '] ';
		}

		$cid_n = $this->alloc();
		$this->write_object(
			$cid_n,
			0,
			'<< /Type /Font /Subtype /CIDFontType2 /BaseFont ' . $base
			. ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>'
			. ' /FontDescriptor ' . $desc_n . ' 0 R /DW ' . EBD_Font::NOTDEF_W . ' /W [' . trim( $w ) . '] /CIDToGIDMap /Identity >>'
		);

		$cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
			. "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
			. "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
			. "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
		foreach ( array_chunk( $used, 100, true ) as $chunk ) {
			$cmap .= count( $chunk ) . " beginbfchar\n";
			foreach ( $chunk as $gid => $u ) {
				$cmap .= sprintf( "<%04X> <%s>\n", $gid, self::utf16_hex( (int) $u[0] ) );
			}
			$cmap .= "endbfchar\n";
		}
		$cmap .= "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
		$uni_n = $this->alloc();
		$this->write_stream( $uni_n, $cmap );

		$font_n = $this->alloc();
		$this->write_object(
			$font_n,
			0,
			'<< /Type /Font /Subtype /Type0 /BaseFont ' . $base . ' /Encoding /Identity-H /DescendantFonts [' . $cid_n . ' 0 R] /ToUnicode ' . $uni_n . ' 0 R >>'
		);
		return $font_n;
	}

	/** A copy of $res with the font $name => $font added to /Font. */
	private function with_font( EBD_Pdf_Dict $res, string $name, int $font ): EBD_Pdf_Dict {
		$copy        = new EBD_Pdf_Dict();
		$copy->items = $res->items;
		$fonts       = $this->r->resolve( $res->get( 'Font' ) );
		$new_fonts   = new EBD_Pdf_Dict();
		if ( $fonts instanceof EBD_Pdf_Dict ) {
			$new_fonts->items = $fonts->items;
		}
		$new_fonts->set( $name, new EBD_Pdf_Ref( $font, 0 ) );
		$copy->set( 'Font', $new_fonts );
		return $copy;
	}

	/** Our font's resource name, avoiding one the page already uses. */
	private static function free_name( EBD_Pdf_Dict $res ): string {
		$fonts = $res->get( 'Font' );
		$used  = $fonts instanceof EBD_Pdf_Dict ? $fonts->items : array();
		$name  = self::FONT_RES;
		$i     = 1;
		while ( array_key_exists( $name, $used ) ) {
			$name = self::FONT_RES . ( ++$i );
		}
		return $name;
	}

	private function write_xref( int $info_num ): void {
		$r       = $this->r;
		$trailer = $r->trailer;
		$id      = $trailer->get( 'ID' );
		$root    = $trailer->get( 'Root' );

		if ( $r->xref_stream ) {
			$xnum = $this->alloc();
			// Its own entry points at itself.
			$this->offsets[ $xnum ] = array( $this->pos(), 0 );
			ksort( $this->offsets );
			$index = array();
			$rows  = '';
			foreach ( self::runs( array_keys( $this->offsets ) ) as $run ) {
				$index[] = $run[0] . ' ' . count( $run );
				foreach ( $run as $n ) {
					$rows .= chr( 1 ) . pack( 'N', $this->offsets[ $n ][0] ) . pack( 'n', $this->offsets[ $n ][1] );
				}
			}
			$dict = '<< /Type /XRef /Size ' . $this->next . ' /W [1 4 2] /Index [' . implode( ' ', $index ) . ']'
				. ' /Root ' . self::serialize( $root ) . ' /Info ' . $info_num . ' 0 R /Prev ' . $r->startxref
				. ( null !== $id ? ' /ID ' . self::serialize( $r->resolve( $id ) ) : '' )
				. ' /Length ' . strlen( $rows ) . ' >>';
			$start = $this->offsets[ $xnum ][0];
			$this->emit( $xnum . " 0 obj\n" . $dict . "\nstream\n" . $rows . "\nendstream\nendobj\n" );
		} else {
			ksort( $this->offsets );
			$start = $this->pos();
			$x     = "xref\n";
			foreach ( self::runs( array_keys( $this->offsets ) ) as $run ) {
				$x .= $run[0] . ' ' . count( $run ) . "\n";
				foreach ( $run as $n ) {
					$x .= sprintf( "%010d %05d n \n", $this->offsets[ $n ][0], $this->offsets[ $n ][1] );
				}
			}
			$x .= 'trailer' . "\n" . '<< /Size ' . $this->next . ' /Root ' . self::serialize( $root )
				. ' /Info ' . $info_num . ' 0 R /Prev ' . $r->startxref
				. ( null !== $id ? ' /ID ' . self::serialize( $r->resolve( $id ) ) : '' ) . " >>\n";
			$this->emit( $x );
		}
		$this->emit( 'startxref' . "\n" . $start . "\n%%EOF\n" );
	}

	/**
	 * Consecutive runs of sorted object numbers.
	 *
	 * @param int[] $nums Sorted numbers.
	 * @return int[][]
	 */
	private static function runs( array $nums ): array {
		$runs = array();
		$cur  = array();
		foreach ( $nums as $n ) {
			if ( $cur && end( $cur ) + 1 !== $n ) {
				$runs[] = $cur;
				$cur    = array();
			}
			$cur[] = $n;
		}
		if ( $cur ) {
			$runs[] = $cur;
		}
		return $runs;
	}

	/* ---------------------------------------------------------------------
	 * Content
	 * ------------------------------------------------------------------- */

	/**
	 * The watermark content of one page box.
	 *
	 * @param float[] $box    [llx, lly, urx, ury].
	 * @param int     $rot    0, 90, 180 or 270.
	 * @param int[]   $line_g Glyph ids of the visible line.
	 * @param int[]   $code_g Glyph ids of the code.
	 */
	private static function mark( array $box, int $rot, string $name, float $size, array $line_g, array $code_g, string $code ): string {
		list( $llx, $lly, $urx, $ury ) = $box;
		$vis_w = in_array( $rot, array( 90, 270 ), true ) ? $ury - $lly : $urx - $llx;

		// Shrink the line until it fits the page width (keeps a 5% margin each side).
		$adv = self::advance( $line_g );
		$fs  = $size;
		if ( $adv > 0 && $adv * $fs / 1000 > $vis_w * 0.9 ) {
			$fs = max( 3.0, $vis_w * 0.9 * 1000 / $adv );
		}
		$w  = $adv * $fs / 1000;
		$m  = (float) self::MARGIN;
		$cx = ( $llx + $urx ) / 2;
		$cy = ( $lly + $ury ) / 2;

		switch ( $rot ) {
			case 90:
				$tm = array( 0, 1, -1, 0, $urx - $m, $cy - $w / 2 );
				break;
			case 180:
				$tm = array( -1, 0, 0, -1, $cx + $w / 2, $ury - $m );
				break;
			case 270:
				$tm = array( 0, -1, 1, 0, $llx + $m, $cy + $w / 2 );
				break;
			default:
				$tm = array( 1, 0, 0, 1, $cx - $w / 2, $lly + $m );
		}

		$s  = "Q\nq\n/EBD << /C " . self::text_string( $code ) . " >> BDC\n";
		if ( $line_g ) {
			$s .= 'BT ' . self::GRAY . ' g /' . $name . ' ' . self::num( $fs ) . ' Tf ' . implode( ' ', array_map( array( __CLASS__, 'num' ), $tm ) ) . ' Tm <' . self::hex( $line_g ) . "> Tj ET\n";
		}
		if ( $code_g ) {
			$s .= 'BT 3 Tr /' . $name . ' 4 Tf 1 0 0 1 ' . self::num( $cx ) . ' ' . self::num( $cy ) . ' Tm <' . self::hex( $code_g ) . "> Tj ET\n";
		}
		return $s . "EMC\nQ\n";
	}

	/**
	 * Glyph ids for a UTF-8 text; characters the font lacks become their
	 * accent-free form, else "?".
	 *
	 * @return int[]
	 */
	public static function glyphs( string $text ): array {
		$map   = EBD_Font::map();
		$out   = array();
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( is_array( $chars ) ? $chars : array() as $ch ) {
			$cp = self::codepoint( $ch );
			if ( isset( $map[ $cp ] ) ) {
				$out[] = $map[ $cp ][0];
				continue;
			}
			$plain = function_exists( 'remove_accents' ) ? remove_accents( $ch ) : $ch;
			$done  = false;
			foreach ( preg_split( '//u', (string) $plain, -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $p ) {
				$pc = self::codepoint( $p );
				if ( $pc !== $cp && isset( $map[ $pc ] ) ) {
					$out[] = $map[ $pc ][0];
					$done  = true;
				}
			}
			if ( ! $done && isset( $map[63] ) ) {
				$out[] = $map[63][0];
			}
		}
		return $out;
	}

	/** Width of glyphs in font units. */
	private static function advance( array $gids ): int {
		static $w = null;
		if ( null === $w ) {
			$w = array();
			foreach ( EBD_Font::map() as $g ) {
				$w[ $g[0] ] = $g[1];
			}
		}
		$sum = 0;
		foreach ( $gids as $g ) {
			$sum += $w[ $g ] ?? EBD_Font::NOTDEF_W;
		}
		return $sum;
	}

	private static function codepoint( string $ch ): int {
		$u = mb_convert_encoding( $ch, 'UCS-4BE', 'UTF-8' );
		$v = unpack( 'N', (string) $u );
		return is_array( $v ) ? (int) $v[1] : 0;
	}

	private static function hex( array $gids ): string {
		$s = '';
		foreach ( $gids as $g ) {
			$s .= sprintf( '%04X', $g );
		}
		return $s;
	}

	private static function utf16_hex( int $cp ): string {
		return strtoupper( bin2hex( (string) mb_convert_encoding( pack( 'N', $cp ), 'UTF-16BE', 'UCS-4BE' ) ) );
	}

	/** A PDF text string: ASCII as a literal, anything else as UTF-16BE hex with BOM. */
	public static function text_string( string $s ): string {
		if ( preg_match( '/^[\x20-\x7E]*$/', $s ) ) {
			return '(' . strtr( $s, array( '\\' => '\\\\', '(' => '\\(', ')' => '\\)' ) ) . ')';
		}
		return '<FEFF' . strtoupper( bin2hex( (string) mb_convert_encoding( $s, 'UTF-16BE', 'UTF-8' ) ) ) . '>';
	}

	private static function num( $v ): string {
		$s = rtrim( rtrim( sprintf( '%.3F', (float) $v ), '0' ), '.' );
		return '' === $s || '-0' === $s ? '0' : $s;
	}

	/**
	 * A page box as four floats (normalised), or null.
	 *
	 * @return float[]|null
	 */
	private static function box( EBD_Pdf_Reader $r, $v ): ?array {
		$v = $r->resolve( $v );
		if ( ! is_array( $v ) || 4 !== count( $v ) ) {
			return null;
		}
		$n = array();
		foreach ( $v as $x ) {
			$x = $r->resolve( $x );
			if ( ! $x instanceof EBD_Pdf_Raw || ! is_numeric( $x->raw ) ) {
				return null;
			}
			$n[] = (float) $x->raw;
		}
		$b = array( min( $n[0], $n[2] ), min( $n[1], $n[3] ), max( $n[0], $n[2] ), max( $n[1], $n[3] ) );
		return ( $b[2] - $b[0] > 1 && $b[3] - $b[1] > 1 ) ? $b : null;
	}

	/* ---------------------------------------------------------------------
	 * Serialization (as in Book Preview's writer)
	 * ------------------------------------------------------------------- */

	public static function serialize( $v ): string {
		if ( $v instanceof EBD_Pdf_Raw ) {
			return $v->raw;
		}
		if ( $v instanceof EBD_Pdf_Name ) {
			return '/' . self::encode_name( $v->name );
		}
		if ( $v instanceof EBD_Pdf_Ref ) {
			return $v->num . ' ' . $v->gen . ' R';
		}
		if ( $v instanceof EBD_Pdf_Dict ) {
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

	/* ---------------------------------------------------------------------
	 * Reading a code back («Ποιος το μοιράστηκε;»)
	 * ------------------------------------------------------------------- */

	/**
	 * Fingerprint codes found in a PDF: the information dictionary, then the
	 * raw bytes (our marked content is uncompressed), then decoded page
	 * content (a file another tool rewrote and compressed).
	 *
	 * @return string[] Unique codes.
	 */
	public static function find_codes( string $path, string $pattern ): array {
		$codes = array();
		// Raw scan in chunks.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = fopen( $path, 'rb' );
		if ( $fh ) {
			$prev = '';
			while ( ! feof( $fh ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$chunk = (string) fread( $fh, 1048576 );
				$buf   = $prev . $chunk;
				if ( preg_match_all( $pattern, $buf, $m ) ) {
					foreach ( $m[0] as $c ) {
						$codes[ $c ] = true;
					}
				}
				$prev = substr( $buf, -32 );
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( $codes ) {
			return array_keys( $codes );
		}
		try {
			$r    = new EBD_Pdf_Reader( $path );
			$info = $r->resolve( $r->trailer->get( 'Info' ) );
			if ( $info instanceof EBD_Pdf_Dict ) {
				$c = $info->get( 'EBDCode' );
				if ( $c instanceof EBD_Pdf_Raw && preg_match( $pattern, $c->raw, $m ) ) {
					$codes[ $m[0] ] = true;
				}
			}
			if ( ! $codes ) {
				$n = 0;
				foreach ( $r->pages() as $page ) {
					if ( ++$n > 50 ) {
						break;
					}
					$cs = $page['dict']->get( 'Contents' );
					$cs = $r->resolve( $cs );
					$cs = is_array( $cs ) ? $cs : array( $cs );
					foreach ( $cs as $c ) {
						$c = $r->resolve( $c );
						if ( $c instanceof EBD_Pdf_Stream ) {
							$data = $r->decode( $c, 33554432 );
							if ( preg_match_all( $pattern, $data, $m ) ) {
								foreach ( $m[0] as $x ) {
									$codes[ $x ] = true;
								}
							}
						}
					}
					if ( $codes ) {
						break;
					}
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return array_keys( $codes );
	}
}

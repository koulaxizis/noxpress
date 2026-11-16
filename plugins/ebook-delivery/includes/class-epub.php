<?php
/**
 * EBD_Epub — the buyer's watermark in an EPUB (2 or 3).
 *
 * The source is copied and changed in place with ZipArchive, so libzip
 * copies every untouched entry (images, fonts) as it is, without
 * recompressing it or holding it in memory; the "mimetype" entry stays
 * first and stored, as OCF requires.
 *
 * What changes:
 *  - a new page «Ex libris» (XHTML) right after the cover (or first, when
 *    the first spine item is not a cover): licensee, order, date, code;
 *  - a short line at the end of every other spine document (reflowable
 *    books only: a fixed-layout page has no room for it);
 *  - package metadata: <meta name="ebd:code" content="…"/> and
 *    <meta name="ebd:licensee" …/> (the OPF 2 form, which EPUB 3 accepts).
 *
 * Safety:
 *  - entry names with "..", a leading "/" or "\" or a drive letter are
 *    refused (zip slip), as are more than MAX_ENTRIES entries or more than
 *    MAX_UNCOMPRESSED bytes in all (zip bomb);
 *  - XML (container.xml, the OPF) is parsed with LIBXML_NONET, without
 *    DTD loading or entity substitution, and refused when it declares
 *    entities (XXE);
 *  - chapters are never parsed: the line goes in front of the last
 *    "</body>" as text, and a document without exactly one is left alone;
 *  - an EPUB whose META-INF/encryption.xml encrypts more than fonts (font
 *    obfuscation is allowed) has DRM: EBD_Epub_Error 'encrypted', and the
 *    caller serves the original.
 */

defined( 'ABSPATH' ) || exit;

final class EBD_Epub_Error extends \RuntimeException {

	/** @var string */
	public $reason;

	public function __construct( string $reason, string $detail = '' ) {
		parent::__construct( '' !== $detail ? $reason . ': ' . $detail : $reason );
		$this->reason = $reason;
	}
}

final class EBD_Epub {

	const MAX_ENTRIES      = 20000;
	const MAX_UNCOMPRESSED = 2147483647; // 2 GB in all.
	const MAX_XML          = 8388608;    // 8 MB for container.xml / the OPF.
	const MAX_DOC          = 16777216;   // 16 MB for one chapter we edit.

	/** Font obfuscation algorithms (IDPF and Adobe): not DRM. */
	const FONT_ALGOS = array( 'http://www.idpf.org/2008/embedding', 'http://ns.adobe.com/pdf/enc#RC' );

	/**
	 * Writes the stamped copy of $src to $dst.
	 *
	 * @param array $o text (the line), code, name, order, date, site, heading
	 *                 (the Ex libris title), lang ('el'|'en').
	 * @return int Documents changed (the Ex libris page included).
	 * @throws EBD_Epub_Error When the file is not a usable EPUB.
	 */
	public static function stamp( string $src, string $dst, array $o ): int {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new EBD_Epub_Error( 'unsupported', 'zip extension' );
		}
		$in = new ZipArchive();
		if ( true !== $in->open( $src, ZipArchive::RDONLY ) ) {
			throw new EBD_Epub_Error( 'not_epub', 'zip' );
		}
		try {
			$info = self::inspect( $in );
		} finally {
			$in->close();
		}

		if ( ! copy( $src, $dst ) ) {
			throw new EBD_Epub_Error( 'io', 'copy' );
		}
		$z = new ZipArchive();
		if ( true !== $z->open( $dst ) ) {
			throw new EBD_Epub_Error( 'io', 'open copy' );
		}
		$changed = 0;
		try {
			$opf_dir = $info['opf_dir'];
			$code    = (string) ( $o['code'] ?? '' );
			$text    = (string) ( $o['text'] ?? '' );

			// The line at the end of each spine document.
			if ( ! $info['fixed'] && '' !== $text ) {
				$line = '<p class="ebd-wm" style="margin:2em 0 0;font-size:0.7em;text-align:center;color:#777;"' . ( $info['version3'] ? ' data-ebd="' . self::x( $code ) . '"' : '' ) . '>' . self::x( $text ) . '</p>';
				foreach ( $info['spine_docs'] as $path ) {
					$doc = $z->getFromName( $path );
					if ( ! is_string( $doc ) || strlen( $doc ) > self::MAX_DOC ) {
						continue;
					}
					if ( 1 !== preg_match_all( '#</body\s*>#i', $doc, $m ) ) {
						continue;
					}
					$doc = (string) preg_replace( '#</body\s*>#i', $line . "\n</body>", $doc, 1 );
					if ( ! $z->addFromString( $path, $doc ) ) {
						throw new EBD_Epub_Error( 'io', 'write chapter' );
					}
					$z->setCompressionName( $path, ZipArchive::CM_DEFLATE );
					++$changed;
				}
			}

			// The Ex libris page.
			$name = self::free_name( $info['hrefs'], $opf_dir, 'ebd-exlibris', 'xhtml' );
			$page = self::exlibris( $o, $info['version3'], $info['fixed'] );
			$z->addFromString( $opf_dir . $name, $page );
			$z->setCompressionName( $opf_dir . $name, ZipArchive::CM_DEFLATE );
			++$changed;

			// The package document.
			$opf = self::edit_opf( $info['opf_xml'], $name, $info, $o );
			$z->addFromString( $info['opf_path'], $opf );
			$z->setCompressionName( $info['opf_path'], ZipArchive::CM_DEFLATE );
		} catch ( \Throwable $e ) {
			$z->unchangeAll();
			$z->close();
			throw $e instanceof EBD_Epub_Error ? $e : new EBD_Epub_Error( 'io', $e->getMessage() );
		}
		if ( ! $z->close() ) {
			throw new EBD_Epub_Error( 'io', 'close' );
		}
		return $changed;
	}

	/**
	 * Checks the archive and reads what stamp() needs.
	 *
	 * @return array{opf_path:string, opf_dir:string, opf_xml:string, version3:bool, fixed:bool, spine_docs:string[], hrefs:string[], first_is_cover:bool}
	 */
	private static function inspect( ZipArchive $z ): array {
		$n = $z->numFiles;
		if ( $n < 2 || $n > self::MAX_ENTRIES ) {
			throw new EBD_Epub_Error( 'not_epub', 'entries' );
		}
		$total = 0;
		$names = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$st = $z->statIndex( $i );
			if ( ! is_array( $st ) ) {
				throw new EBD_Epub_Error( 'damaged', 'stat' );
			}
			$name = (string) $st['name'];
			if ( ! self::safe_name( $name ) ) {
				throw new EBD_Epub_Error( 'unsafe', 'entry name' );
			}
			$total += (int) $st['size'];
			if ( $total > self::MAX_UNCOMPRESSED ) {
				throw new EBD_Epub_Error( 'unsafe', 'size' );
			}
			$names[ $name ] = true;
		}
		$mt = $z->getFromName( 'mimetype' );
		if ( ! is_string( $mt ) || 'application/epub+zip' !== trim( $mt ) ) {
			throw new EBD_Epub_Error( 'not_epub', 'mimetype' );
		}

		// DRM: encryption of anything but fonts.
		$enc = isset( $names['META-INF/encryption.xml'] ) ? self::read_xml( $z, 'META-INF/encryption.xml' ) : null;
		if ( $enc ) {
			foreach ( $enc->getElementsByTagNameNS( '*', 'EncryptionMethod' ) as $m ) {
				$alg = $m instanceof DOMElement ? $m->getAttribute( 'Algorithm' ) : '';
				if ( ! in_array( $alg, self::FONT_ALGOS, true ) ) {
					throw new EBD_Epub_Error( 'encrypted' );
				}
			}
		}
		if ( isset( $names['META-INF/rights.xml'] ) ) {
			throw new EBD_Epub_Error( 'encrypted', 'rights.xml' );
		}

		$container = self::read_xml( $z, 'META-INF/container.xml' );
		if ( ! $container ) {
			throw new EBD_Epub_Error( 'not_epub', 'container' );
		}
		$opf_path = '';
		foreach ( $container->getElementsByTagNameNS( '*', 'rootfile' ) as $rf ) {
			if ( $rf instanceof DOMElement && 'application/oebps-package+xml' === $rf->getAttribute( 'media-type' ) ) {
				$opf_path = $rf->getAttribute( 'full-path' );
				break;
			}
		}
		if ( '' === $opf_path || ! self::safe_name( $opf_path ) || ! isset( $names[ $opf_path ] ) ) {
			throw new EBD_Epub_Error( 'not_epub', 'rootfile' );
		}
		$opf_xml = (string) $z->getFromName( $opf_path );
		$opf     = self::parse_xml( $opf_xml );
		if ( ! $opf ) {
			throw new EBD_Epub_Error( 'damaged', 'opf' );
		}
		$opf_dir = false !== strrpos( $opf_path, '/' ) ? substr( $opf_path, 0, strrpos( $opf_path, '/' ) + 1 ) : '';
		$pkg     = $opf->documentElement;
		$ver3    = $pkg instanceof DOMElement && version_compare( $pkg->getAttribute( 'version' ), '3.0', '>=' );

		// Fixed layout (EPUB 3 rendition:layout).
		$fixed = false;
		foreach ( $opf->getElementsByTagNameNS( '*', 'meta' ) as $meta ) {
			if ( $meta instanceof DOMElement && 'rendition:layout' === $meta->getAttribute( 'property' ) && 'pre-paginated' === trim( $meta->textContent ) ) {
				$fixed = true;
			}
		}

		$manifest = array();
		$hrefs    = array();
		foreach ( $opf->getElementsByTagNameNS( '*', 'item' ) as $item ) {
			if ( ! $item instanceof DOMElement ) {
				continue;
			}
			$href    = rawurldecode( $item->getAttribute( 'href' ) );
			$hrefs[] = $href;
			$manifest[ $item->getAttribute( 'id' ) ] = array(
				'href'  => $href,
				'type'  => $item->getAttribute( 'media-type' ),
				'props' => $item->getAttribute( 'properties' ),
			);
		}
		$docs  = array();
		$first = true;
		$cover = false;
		foreach ( $opf->getElementsByTagNameNS( '*', 'itemref' ) as $ref ) {
			if ( ! $ref instanceof DOMElement ) {
				continue;
			}
			$id = $ref->getAttribute( 'idref' );
			if ( ! isset( $manifest[ $id ] ) ) {
				continue;
			}
			$it       = $manifest[ $id ];
			$is_cover = false;
			if ( $first ) {
				$cover    = false !== stripos( $id . ' ' . $it['href'] . ' ' . $it['props'], 'cover' );
				$is_cover = $cover;
				$first    = false;
			}
			if ( $is_cover || ! in_array( $it['type'], array( 'application/xhtml+xml', 'text/html' ), true ) ) {
				continue;
			}
			$path = self::normalise( $opf_dir . $it['href'] );
			if ( '' !== $path && isset( $names[ $path ] ) && ! in_array( $path, $docs, true ) ) {
				$docs[] = $path;
			}
		}
		return array(
			'opf_path'       => $opf_path,
			'opf_dir'        => $opf_dir,
			'opf_xml'        => $opf_xml,
			'version3'       => $ver3,
			'fixed'          => $fixed,
			'spine_docs'     => $docs,
			'hrefs'          => $hrefs,
			'first_is_cover' => $cover,
		);
	}

	/** The OPF with our manifest item, spine entry and metadata. */
	private static function edit_opf( string $xml, string $href, array $info, array $o ): string {
		$doc = self::parse_xml( $xml );
		if ( ! $doc ) {
			throw new EBD_Epub_Error( 'damaged', 'opf' );
		}
		$ns  = $doc->documentElement instanceof DOMElement ? $doc->documentElement->namespaceURI : '';
		$ns  = '' !== (string) $ns ? (string) $ns : 'http://www.idpf.org/2007/opf';
		$get = static function ( string $tag ) use ( $doc ): ?DOMElement {
			$l = $doc->getElementsByTagNameNS( '*', $tag );
			$e = $l->length ? $l->item( 0 ) : null;
			return $e instanceof DOMElement ? $e : null;
		};
		$manifest = $get( 'manifest' );
		$spine    = $get( 'spine' );
		$metadata = $get( 'metadata' );
		if ( ! $manifest || ! $spine || ! $metadata ) {
			throw new EBD_Epub_Error( 'damaged', 'opf parts' );
		}

		$ids = array();
		foreach ( $doc->getElementsByTagNameNS( '*', '*' ) as $el ) {
			if ( $el instanceof DOMElement && $el->hasAttribute( 'id' ) ) {
				$ids[ $el->getAttribute( 'id' ) ] = true;
			}
		}
		$id = 'ebd-exlibris';
		for ( $i = 2; isset( $ids[ $id ] ); $i++ ) {
			$id = 'ebd-exlibris-' . $i;
		}

		$item = $doc->createElementNS( $ns, 'item' );
		$item->setAttribute( 'id', $id );
		$item->setAttribute( 'href', $href );
		$item->setAttribute( 'media-type', 'application/xhtml+xml' );
		$manifest->appendChild( $item );

		$ref = $doc->createElementNS( $ns, 'itemref' );
		$ref->setAttribute( 'idref', $id );
		$refs  = array();
		foreach ( $spine->childNodes as $c ) {
			if ( $c instanceof DOMElement && 'itemref' === $c->localName ) {
				$refs[] = $c;
			}
		}
		if ( $info['first_is_cover'] && isset( $refs[1] ) ) {
			$spine->insertBefore( $ref, $refs[1] );
		} elseif ( $info['first_is_cover'] ) {
			$spine->appendChild( $ref );
		} elseif ( isset( $refs[0] ) ) {
			$spine->insertBefore( $ref, $refs[0] );
		} else {
			$spine->appendChild( $ref );
		}

		foreach ( array(
			'ebd:code'     => (string) ( $o['code'] ?? '' ),
			'ebd:licensee' => (string) ( $o['name'] ?? '' ),
		) as $k => $v ) {
			if ( '' === $v ) {
				continue;
			}
			$m = $doc->createElementNS( $ns, 'meta' );
			$m->setAttribute( 'name', $k );
			$m->setAttribute( 'content', $v );
			$metadata->appendChild( $m );
		}
		$out = $doc->saveXML();
		if ( ! is_string( $out ) ) {
			throw new EBD_Epub_Error( 'io', 'opf save' );
		}
		return $out;
	}

	/** The Ex libris document. */
	private static function exlibris( array $o, bool $v3, bool $fixed ): string {
		$lang  = 'en' === ( $o['lang'] ?? 'el' ) ? 'en' : 'el';
		$title = self::x( (string) ( $o['heading'] ?? 'Ex libris' ) );
		$rows  = '';
		foreach ( (array) ( $o['rows'] ?? array() ) as $row ) {
			$rows .= '<p>' . self::x( (string) $row ) . "</p>\n";
		}
		$code = self::x( (string) ( $o['code'] ?? '' ) );
		$head = $v3
			? "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE html>\n<html xmlns=\"http://www.w3.org/1999/xhtml\" xmlns:epub=\"http://www.idpf.org/2007/ops\" xml:lang=\"{$lang}\" lang=\"{$lang}\">\n"
			: "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.1//EN\" \"http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd\">\n<html xmlns=\"http://www.w3.org/1999/xhtml\" xml:lang=\"{$lang}\">\n";
		$view = $fixed ? "<meta name=\"viewport\" content=\"width=1000, height=1400\" />\n" : '';
		$charset = $v3 ? "<meta charset=\"UTF-8\" />\n" : "<meta http-equiv=\"Content-Type\" content=\"application/xhtml+xml; charset=utf-8\" />\n";
		return $head . "<head>\n" . $charset . $view
			. "<meta name=\"ebd-code\" content=\"{$code}\" />\n<title>{$title}</title>\n"
			. "<style type=\"text/css\">body{margin:3em 1.5em;text-align:center;font-family:serif;} h1{font-size:1.4em;font-weight:normal;letter-spacing:0.1em;margin:0 0 1.5em;} p{margin:0.4em 0;} .ebd-code{margin-top:2em;font-family:monospace;color:#555;}</style>\n"
			. "</head>\n<body>\n<h1>{$title}</h1>\n{$rows}<p class=\"ebd-code\">{$code}</p>\n</body>\n</html>\n";
	}

	/* ---------------------------------------------------------------------
	 * Reading a code back
	 * ------------------------------------------------------------------- */

	/** Codes in an EPUB: package metadata, then every XHTML document. */
	public static function find_codes( string $path, string $pattern ): array {
		$codes = array();
		if ( ! class_exists( 'ZipArchive' ) ) {
			return array();
		}
		$z = new ZipArchive();
		if ( true !== $z->open( $path, ZipArchive::RDONLY ) ) {
			return array();
		}
		try {
			$n = min( $z->numFiles, self::MAX_ENTRIES );
			for ( $i = 0; $i < $n; $i++ ) {
				$st = $z->statIndex( $i );
				if ( ! is_array( $st ) || (int) $st['size'] > self::MAX_DOC || ! preg_match( '/\.(opf|x?html?|xml|ncx)$/i', (string) $st['name'] ) ) {
					continue;
				}
				$data = $z->getFromIndex( $i );
				if ( is_string( $data ) && preg_match_all( $pattern, $data, $m ) ) {
					foreach ( $m[0] as $c ) {
						$codes[ $c ] = true;
					}
				}
			}
		} finally {
			$z->close();
		}
		return array_keys( $codes );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	private static function safe_name( string $name ): bool {
		if ( '' === $name || false !== strpos( $name, "\0" ) || false !== strpos( $name, '\\' ) ) {
			return false;
		}
		if ( '/' === $name[0] || preg_match( '/^[A-Za-z]:/', $name ) ) {
			return false;
		}
		foreach ( explode( '/', $name ) as $part ) {
			if ( '..' === $part ) {
				return false;
			}
		}
		return true;
	}

	/** Resolves "a/b/../c" inside the archive; '' when it leaves the root. */
	private static function normalise( string $path ): string {
		$path = (string) preg_replace( '/[#?].*$/', '', $path );
		$out  = array();
		foreach ( explode( '/', $path ) as $p ) {
			if ( '' === $p || '.' === $p ) {
				continue;
			}
			if ( '..' === $p ) {
				if ( ! $out ) {
					return '';
				}
				array_pop( $out );
				continue;
			}
			$out[] = $p;
		}
		return implode( '/', $out );
	}

	/** A file name next to the OPF that no manifest item uses. */
	private static function free_name( array $hrefs, string $dir, string $base, string $ext ): string {
		$name = $base . '.' . $ext;
		for ( $i = 2; in_array( $name, $hrefs, true ); $i++ ) {
			$name = $base . '-' . $i . '.' . $ext;
		}
		unset( $dir );
		return $name;
	}

	private static function read_xml( ZipArchive $z, string $name ): ?DOMDocument {
		$st = $z->statName( $name );
		if ( ! is_array( $st ) || (int) $st['size'] > self::MAX_XML ) {
			return null;
		}
		$xml = $z->getFromName( $name );
		return is_string( $xml ) ? self::parse_xml( $xml ) : null;
	}

	/** Parses XML without network, DTD loading or entities; null on failure. */
	private static function parse_xml( string $xml ): ?DOMDocument {
		if ( strlen( $xml ) > self::MAX_XML || preg_match( '/<!ENTITY/i', $xml ) ) {
			return null;
		}
		$prev = libxml_use_internal_errors( true );
		$doc  = new DOMDocument();
		$ok   = $doc->loadXML( $xml, LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $ok ? $doc : null;
	}

	/** XML-escaped text. */
	private static function x( string $s ): string {
		return htmlspecialchars( $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}
}

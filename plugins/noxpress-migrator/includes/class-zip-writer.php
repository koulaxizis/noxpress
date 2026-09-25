<?php
/**
 * NM_Zip_Writer — Pure-PHP streaming ZIP writer (STORED, ZIP64-ready).
 *
 * Γιατί ΟΛΗ η λογική εδώ αντί για ZipArchive:
 *  - Το ZipArchive χρειάζεται ΕΤΟΙΜΟ αρχείο στο disk (δεν stream-άρει)·
 *    για 5GB+ uploads σημαίνει χειρουργική mkdir/copy — αργή και
 *    επιρρεπής σε διπλό占用 χώρο.
 *  - Εδώ: κάθε entry γράφεται chunk-by-chunk απευθείας στο ZIP,
 *    με CRC32 υπολογιζόμενο on-the-fly (hash_update). ΠΟΤΕ κανένα
 *    περιεχόμενο αρχείου δεν μπαίνει στη μνήμη ολόκληρο.
 *
 * Design decisions (ρητά):
 *  - Compression: STORED (method 0). Migration archives = JPEG/PDF/
 *    video/ήδη-συμπιεσμένο SQL — το deflate θα έδινε ~2-5% και θα
 *    έφταιγε τον χρόνο export/restore τάξεως μεγέθους. I/O-bound
 *    αναμφίβολα > CPU-bound εδώ.
 *  - Data descriptors (flag bit 3): το local header γράφεται ΠΡΙΝ
 *    από τα data (χωρίς γνωστά CRC/size — placeholders), και τα
 *    πραγματικά μεγέθη έπονται ως trailing descriptor. Standard
 *    πρακτική streaming writers (java.util.zip, Go archive/zip).
 *  - ZIP64: local headers πάντα με extra 0x0001 placeholder (safe
 *    path), central directory/EOCD μεΡητά sentinels ΜΟΝΟ όταν
 *    χρειάζεται (> 4 GiB offsets / sizes, > 65535 entries) — ώστε
 *    μικρά αρχεία να μένουν 100% κλασικά-αναγνώσιμα.
 *  - Multi-volume: ΔΕΝ παράγουμε spec-split archives (.z01 με
 *    segment-boundary κανόνες — φτωχή υποστήριξη από εργαλεία).
 *    Αντίθετα: ΕΝΑ έγκυρο ZIP, το οποίο τεμαχίζεται σε ίσα byte-
 *    segments ({base}.z01 … {base}.zip). Συγκέντρωση (concat) =
 *    πλήρως έγκυρο ZIP ξανά. Το restore.php (Wave 3) κάνει το
 *    concat αυτόματα πριν την ανάγνωση.
 *
 * Memory footprint: σταθερό (ένα chunk buffer) — ανεξάρτητα από
 * συνολικό μέγεθος αρχείου/archive.
 */

defined( 'ABSPATH' ) || exit;

final class NM_Zip_Writer {

	/** Chunk ανά fwrite/fread (4 MiB — φιλικό σε shared hosting I/O). */
	const CHUNK = 4194304;

	const SIG_LOCAL       = 0x04034b50;
	const SIG_DESCRIPTOR  = 0x08074b50;
	const SIG_CENTRAL     = 0x02014b50;
	const SIG_EOCD        = 0x06054b50;
	const SIG_Z64_EOCD    = 0x06064b50;
	const SIG_Z64_LOCATOR = 0x07064b50;

	/** Flag: data descriptor σε χρήση. */
	const FLAG_DESCRIPTOR = 0x0008;
	/** Flag: όνομα αρχείου σε UTF-8 (bit 11). */
	const FLAG_UTF8       = 0x0800;

	const U32_MAX = 4294967295;
	const U16_MAX = 65535;

	/** @var resource|null Handle του ZIP (disk-based). */
	private $fh = null;

	/** @var string Απόλυτο path του ZIP. */
	private $path = '';

	/** @var int Bytes γραμμένα συνολικά (offset του επόμενου byte). */
	private $offset = 0;

	/** @var array|null Το entry σε εξέλιξη (ή null). */
	private $cur = null;

	/** @var resource|null Incremental CRC32 context (hash 'crc32b'). */
	private $ctx = null;

	/** @var array Κλειδώθηκαν entries: name/lho/usize/crc. */
	private $entries = array();

	/** @var bool Το finish() κλήθηκε. */
	private $finished = false;

	/**
	 * Νέο archive (truncate) ή resume από state (βλ. state()).
	 *
	 * @param string     $path  Πού γράφεται το ZIP (staging dir).
	 * @param array|null $resume ['entries' => array, 'offset' => int] —
	 *                           από NM_Zip_Writer::state() ενός προηγούμενου
	 *                           βήματος. Το ZIP πρέπει να είναι ΑΚΡΙΒΩΣ στο
	 *                           ίδιο offset, αλλιώς RuntimeException.
	 * @throws RuntimeException Αν το αρχείο δεν ανοίγει / το state δεν ταιριάζει.
	 */
	public function __construct( string $path, ?array $resume = null ) {

		if ( null === $resume ) {
			$fh = @fopen( $path, 'wb' );
			if ( false === $fh ) {
				throw new RuntimeException( 'NM_Zip_Writer: cannot open ' . $path . ' for writing.' );
			}
			$this->entries = array();
			$this->offset = 0;
		} else {
			$fh = @fopen( $path, 'r+b' );
			if ( false === $fh ) {
				throw new RuntimeException( 'NM_Zip_Writer: cannot resume ' . $path . ' — unreadable.' );
			}
			fseek( $fh, 0, SEEK_END );
			$actual = (int) ftell( $fh );
			$expect = (int) ( $resume['offset'] ?? -1 );
			if ( $actual !== $expect ) {
				fclose( $fh );
				throw new RuntimeException( 'NM_Zip_Writer: resume mismatch — file is ' . $actual . ' bytes, state says ' . $expect . '.' );
			}
			$entries = $resume['entries'] ?? null;
			if ( ! is_array( $entries ) ) {
				fclose( $fh );
				throw new RuntimeException( 'NM_Zip_Writer: resume state without entries.' );
			}
			$this->entries = $entries;
			$this->offset  = $actual;
		}

		$this->fh   = $fh;
		$this->path = $path;
	}

	/**
	 * Snapshot για persistence (checkpoints). Το state ΜΠΟΡΕΙ να περαστεί
	 * πίσω στον constructor ως $resume — punktu GT ["ent" => entries].
	 *
	 * @return array{entries: array, offset: int}
	 */
	public function state(): array {
		return array(
			'entries' => $this->entries,
			'offset'  => $this->offset,
		);
	}

	public function __destruct() {
		if ( null !== $this->fh && ! $this->finished ) {
			// Κλείδωμα χωρίς finish = ημιτελές ZIP — απλά ΚΛΕΙΝΟΥΜΕ.
			// Το staging sweep του uninstall/engine το καθαρίζει.
			@fclose( $this->fh );
			$this->fh = null;
		}
	}

	/** Το τρέχον offset (bytes γραμμένα) — για checkpoints. */
	public function offset(): int {
		return $this->offset;
	}

	/** Πλήθος ολοκληρωμένων entries. */
	public function count(): int {
		return count( $this->entries );
	}

	/* =====================================================================
	 * Streaming API — begin_file() → write()*N → end_file()
	 * =================================================================== */

	/**
	 * Ξεκινά ένα νέο entry. Πρέπει να μην υπάρχει άλλο σε εξέλιξη.
	 *
	 * @param string $name Όνομα μέσα στο ZIP (π.χ. 'db/dump.sql').
	 * @throws RuntimeException Αν υπάρχει ήδη entry σε εξέλιξη.
	 */
	public function begin_file( string $name ): void {

		if ( null !== $this->cur ) {
			throw new RuntimeException( 'NM_Zip_Writer: begin_file() called while a previous entry is open.' );
		}
		if ( $this->finished ) {
			throw new RuntimeException( 'NM_Zip_Writer: archive already finalized.' );
		}

		// UTF-8 flag ΠΑΝΤΑ: θέλουμε ελληνικά ονόματα αρχείων να
		// αποκωδικοποιούνται σωστά από εξωτερικά εργαλεία.
		$flags = self::FLAG_DESCRIPTOR | self::FLAG_UTF8;

		$extra = pack(
			'vvPP',
			0x0001, // ZIP64 extended information.
			16,     // μέγεθος αυτού του extra payload (2 quads).
			0,      // uncompressed size placeholder (ήρθε στο descriptor).
			0       // compressed size placeholder.
		);

		list( $time, $date ) = self::dos_datetime( time() );

		$local = pack(
			'VvvvvvVVVvv',
			self::SIG_LOCAL,
			45,              // version needed: 4.5 (ZIP64).
			$flags,
			0,               // method: STORED.
			$time,
			$date,
			self::U32_MAX,   // crc placeholder (descriptor θα φέρει).
			self::U32_MAX,   // csize placeholder.
			self::U32_MAX,   // usize placeholder.
			strlen( $name ),
			16               // extra len.
		) . $name . $extra;

		$this->raw( $local );

		$this->cur = array(
			'name'  => $name,
			'lho'   => $this->offset - strlen( $local ), // local header offset.
			'usize' => 0,
			'crc'   => 0,
		);

		$this->ctx = hash_init( 'crc32b' );
	}

	/**
	 * Γράφει ένα chunk δεδομένων του τρέχοντος entry.
	 *
	 * @param string $data Συνήθως ≤ self::CHUNK — αλλά δουλεύει με ΟΛΑ.
	 * @throws RuntimeException Αν δεν υπάρχει entry σε εξέλιξη.
	 */
	public function write( string $data ): void {

		if ( null === $this->cur ) {
			throw new RuntimeException( 'NM_Zip_Writer: write() called without begin_file().' );
		}

		$n = strlen( $data );
		if ( 0 === $n ) {
			return;
		}

		hash_update( $this->ctx, $data );
		$this->raw( $data );

		$this->cur['usize'] += $n;
	}

	/** Ολοκληρώνει το τρέχον entry (data descriptor + bookkeeping). */
	public function end_file(): void {

		if ( null === $this->cur ) {
			throw new RuntimeException( 'NM_Zip_Writer: end_file() called without begin_file().' );
		}

		$crc  = hexdec( hash_final( $this->ctx, false ) ); // u32.
		$size = $this->cur['usize'];

		// Descriptor: sig + crc(4) + usize(8) + csize(8) — ZIP64 μορφή
		// (8-byte sizes), παντέ/Same value σε STORED: usize == csize.
		$this->raw(
			pack( 'VVP P', self::SIG_DESCRIPTOR, $crc, $size, $size )
		);

		$this->entries[] = array(
			'name'  => $this->cur['name'],
			'lho'   => $this->cur['lho'],
			'usize' => $size,
			'crc'   => $crc,
		);

		$this->cur = null;
		$this->ctx = null;
	}

	/* =====================================================================
	 * Convenience — ολόκληρο αρχείο από τον δίσκο (streamed).
	 * =================================================================== */

	/**
	 * Προσθέτει αρχείο από τον δίσκο ως entry, σε chunks του CHUNK.
	 * (MySQL dump staging file, manifest, μικρά configs — οτιδήποτε.)
	 *
	 * @param string $name      Όνομα μέσα στο ZIP.
	 * @param string $disk_path Πραγματικό path στο δίσκο.
	 * @param int    $mtime     Timestamp για το DOS datetime (default: now).
	 * @throws RuntimeException Αν το source αρχείο δεν ανοίγει.
	 */
	public function add_file( string $name, string $disk_path, ?int $mtime = null ): void {

		$src = @fopen( $disk_path, 'rb' );
		if ( false === $src ) {
			throw new RuntimeException( 'NM_Zip_Writer: cannot read source ' . $disk_path );
		}

		try {
			$this->begin_file( $name );
			while ( true ) {
				$chunk = fread( $src, self::CHUNK );
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				$this->write( $chunk );
			}
			$this->end_file();
		} finally {
			@fclose( $src );
		}
	}

	/* =====================================================================
	 * Finalize — central directory + EOCD (+ ZIP64 όταν χρειάζεται).
	 * =================================================================== */

	/**
	 * Κλείνει το archive. Επιστρέφει summary για το manifest:
	 * {entries: int, bytes: int, path: string}.
	 *
	 * @throws RuntimeException Αν υπάρχει entry σε εξέλιξη ή άδειο call.
	 */
	public function finish(): array {

		if ( null !== $this->cur ) {
			throw new RuntimeException( 'NM_Zip_Writer: finish() called with an open entry.' );
		}
		if ( $this->finished ) {
			throw new RuntimeException( 'NM_Zip_Writer: already finalized.' );
		}

		$cd_start = $this->offset;

		foreach ( $this->entries as $e ) {
			$this->raw( $this->central_entry( $e ) );
		}

		$cd_size   = $this->offset - $cd_start;
		$cd_offset = $cd_start;
		$n         = count( $this->entries );

		// ---- ZIP64 EOCD (μόνο αν τα κλασικά πεδία υπερχειλίζουν) ----
		$need_z64 = ( $cd_offset > self::U32_MAX )
			|| ( $cd_size > self::U32_MAX )
			|| ( $n > self::U16_MAX );

		$z64_offset = 0;
		if ( $need_z64 ) {

			$z64_offset = $this->offset;

			// Record: sig(4) + size(8=44) + vmade(2) + vneed(2) +
			// disk(4) + cddisk(4) + n_disk(8) + n_total(8) +
			// cd_size(8) + cd_offset(8).
			$this->raw(
				pack( 'VPvvVVPPPP',
					self::SIG_Z64_EOCD,
					44,
					0x032D,        // version made by: Unix, 4.5.
					45,            // version needed: 4.5.
					0,             // αυτό το disk.
					0,             // disk του CD.
					$n,
					$n,
					$cd_size,
					$cd_offset
				)
			);

			// Locator: sig(4) + z64_disk(4) + z64_offset(8) + disks(4).
			$this->raw(
				pack( 'VVPV',
					self::SIG_Z64_LOCATOR,
					0,
					$z64_offset,
					1
				)
			);
		}

		// ---- Classic EOCD (πάντα τελευταίο — τα readers το θέλουν) ----
		$this->raw(
			pack( 'VvvvvVVv',
				self::SIG_EOCD,
				0,
				0,
				( $n > self::U16_MAX ) ? self::U16_MAX : $n,
				( $n > self::U16_MAX ) ? self::U16_MAX : $n,
				( $cd_size > self::U32_MAX ) ? self::U32_MAX : $cd_size,
				( $cd_offset > self::U32_MAX ) ? self::U32_MAX : $cd_offset,
				0              // σχόλιο: κανένα.
			)
		);

		fflush( $this->fh );
		fclose( $this->fh );
		$this->fh       = null;
		$this->finished = true;

		return array(
			'entries' => $n,
			'bytes'   => $this->offset,
			'path'    => $this->path,
		);
	}

	/** Manifest-friendly λίστα: name/usize/crc ανά entry. */
	public function entries(): array {
		return $this->entries;
	}

	/* =====================================================================
	 * Multi-volume split — byte-accurate, concat-restore.
	 * =================================================================== */

	/**
	 * Τεμαχίζει ΕΤΟΙΜΟ ZIP σε volumes: {base}.z01 … {base}.zip.
	 *
	 * Το τελευταίο κομμάτι παίρνει το extension .zip (σύμβαση εργαλείων
	 * όπως το 7-Zip), όλα τα προηγούμενα .z01, .z02… ΟΛΑ τα non-final
	 * volumes έχουν ΑΚΡΙΒΩΣ $max_bytes — μόνο το τελευταίο κόβεται όπου
	 * τελειώνει το αρχείο. Συγκέντρωση (cat z01 … zip > original) (ή
	 * απλό unpack από το 7-Zip) επαναφέρει το πλήρες ZIP.
	 *
	 * Το SOURCE ZIP ΔΙΑΓΡΑΦΕΤΑΙ μετά το επιτυχημένο split (δεν
	 * χρειαζόμαστε διπλόoccupied χώρο 10GB σε 5GB staging).
	 *
	 * @param string $src_path   Έγκυρο, ολοκληρωμένο ZIP.
	 * @param string $dst_dir    Φάκελος προορισμού ( staging, ίδιο συνήθως).
	 * @param string $base_name  Base χωρίς extension (π.χ. 'noxpress-migration-2026-09-17').
	 * @param int    $max_bytes  Μέγεθος τόμου σε bytes (>= 1024).
	 * @return array[] [['file' => fullPath, 'bytes' => int], ...]
	 * @throws RuntimeException Σε αποτυχία ανοίγματος/εγγραφής.
	 */
	public static function split_into_volumes( string $src_path, string $dst_dir, string $base_name, int $max_bytes ): array {

		if ( $max_bytes < 1024 ) {
			throw new RuntimeException( 'NM_Zip_Writer: volume size too small (< 1024 bytes).' );
		}

		$total = (int) @filesize( $src_path );
		if ( $total <= 0 ) {
			throw new RuntimeException( 'NM_Zip_Writer: source ZIP empty or unreadable.' );
		}

		$volumes   = max( 1, (int) ceil( $total / $max_bytes ) );
		$extension = array( 'zip' );
		for ( $i = $volumes - 2; $i >= 0; $i-- ) {
			array_unshift( $extension, sprintf( 'z%02d', $i + 1 ) );
		}

		$src = @fopen( $src_path, 'rb' );
		if ( false === $src ) {
			throw new RuntimeException( 'NM_Zip_Writer: cannot open source ' . $src_path );
		}

		$out     = array();
		$success = false;

		try {
			for ( $v = 0; $v < $volumes; $v++ ) {

				$dst_path = $dst_dir . '/' . $base_name . '.' . $extension[ $v ];
				$dst      = @fopen( $dst_path, 'wb' );
				if ( false === $dst ) {
					throw new RuntimeException( 'NM_Zip_Writer: cannot create volume ' . $dst_path );
				}

				$written = 0;

				try {
					while ( $written < $max_bytes ) {

						$want = (int) min( self::CHUNK, $max_bytes - $written );
						$chunk = fread( $src, $want );

						if ( false === $chunk || '' === $chunk ) {
							break; // Τέλος πηγής (τελευταίο volume).
						}

						if ( false === fwrite( $dst, $chunk ) ) {
							throw new RuntimeException( 'NM_Zip_Writer: write failed on ' . $dst_path );
						}

						$written += strlen( $chunk );
					}
				} finally {
					fflush( $dst );
					fclose( $dst );
				}

				if ( 0 === $written ) {
					@unlink( $dst_path );
					break; // Κενό volume δεν γράφεται ποτέ.
				}

				$out[] = array(
					'file'  => $dst_path,
					'bytes' => $written,
				);
			}
			$success = true;
		} finally {
			fclose( $src );
		}

		if ( ! $success ) {
			// Καθαρισμός ημιτελών volumes — ΚΑΝΕΝΑ προς χρήση.
			foreach ( $out as $vol ) {
				@unlink( $vol['file'] );
			}
			throw new RuntimeException( 'NM_Zip_Writer: volume split failed — partial volumes removed.' );
		}

		// Επιτυχές split → σβήνουμε το μοναδικό ZIP (διπλός χώρος OFF).
		@unlink( $src_path );

		return $out;
	}

	/* =====================================================================
	 * Internals
	 * =================================================================== */

	/** fwrite με tracking του offset — η μοναδική εξόδους στον δίσκο. */
	private function raw( string $bytes ): void {

		$n = strlen( $bytes );
		if ( 0 === $n ) {
			return;
		}

		$done = @fwrite( $this->fh, $bytes );
		if ( false === $done || $done !== $n ) {
			throw new RuntimeException( 'NM_Zip_Writer: short write — disk full or I/O error.' );
		}

		$this->offset += $n;
	}

	/**
	 * Central directory record για ένα entry — conditional ZIP64:
	 * sentinels ΜΟΝΟ όπου τα πραγματικά μεγέθη υπερχειλίζουν.
	 */
	private function central_entry( array $e ): string {

		$name = $e['name'];

		$usize_fix = ( $e['usize'] > self::U32_MAX ) ? self::U32_MAX : $e['usize'];
		$lho_fix   = ( $e['lho'] > self::U32_MAX ) ? self::U32_MAX : $e['lho'];

		// ZIP64 extra: sizes (_AND_ όταν ένα από τα δύο υπερχειλίζει —
		// spec: πεδίο ζεύγος) και ξεχωριστά το lho.
		$extra     = '';
		$extra_len = 0;

		if ( $e['usize'] > self::U32_MAX ) {
			$extra    .= pack( 'PP', $e['usize'], $e['usize'] ); // STORED: csize == usize.
			$usize_fix = self::U32_MAX;
			$extra_len += 16;
		}
		if ( $e['lho'] > self::U32_MAX ) {
			$extra    .= pack( 'P', $e['lho'] );
			$lho_fix   = self::U32_MAX;
			$extra_len += 8;
		}

		// Το ZIP64 header (0x0001 + length) προηγείται των payloads.
		if ( '' !== $extra ) {
			$extra = pack( 'vv', 0x0001, $extra_len ) . $extra;
			$extra_len += 4;
		}

		list( $time, $date ) = self::dos_datetime( time() );

		return pack(
			'VvvvvvvVVVvvvvvVV',
			self::SIG_CENTRAL,
			0x032D,          // version made by: Unix, 4.5.
			45,              // version needed: 4.5.
			self::FLAG_DESCRIPTOR | self::FLAG_UTF8,
			0,               // method: STORED.
			$time,
			$date,
			$e['crc'],
			$usize_fix,      // compressed size (STORED == usize).
			$usize_fix,      // uncompressed size.
			strlen( $name ),
			$extra_len,
			0,               // comment length.
			0,               // disk number start.
			0,               // internal attributes.
			( 0100644 << 16 ), // Unix -rw-r--r--.
			$lho_fix
		) . $name . $extra;
	}

	/**
	 * Unix timestamp → DOS (time, date) pair.
	 *
	 * @return array{0:int,1:int} [dos_time, dos_date]
	 */
	private static function dos_datetime( int $ts ): array {

		$dt = getdate( $ts );
		if ( ! isset( $dt['year'], $dt['mon'], $dt['mday'], $dt['hours'], $dt['minutes'], $dt['seconds'] ) ) {
			return array( 0, 0x21 ); // fallback: 1980-01-01 00:00.
		}

		$year = max( 1980, min( 2107, (int) $dt['year'] ) );

		$time = ( (int) $dt['hours'] << 11 )
			| ( (int) $dt['minutes'] << 5 )
			| ( (int) $dt['seconds'] >> 1 );

		$date = ( ( $year - 1980 ) << 9 )
			| ( (int) $dt['mon'] << 5 )
			| (int) $dt['mday'];

		return array( $time, $date );
	}
}
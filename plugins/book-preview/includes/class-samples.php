<?php
/**
 * BPV_Samples — sample files and product meta.
 *
 * Product meta (hidden, all prefixed _bpv_):
 *  - _bpv_source  "d:<download id>" | "a:<attachment id>"
 *  - _bpv_pages   the page field as typed ("1-12, 20, 35-40")
 *  - _bpv_hidden  '1' hides the button without deleting the sample
 *  - _bpv_sample  { file, count, total, pages, size, engine, sig, created }
 *  - _bpv_error   { error, page, time } of the last failed run
 *
 * Files: uploads/noxpress-previews/{product id}-{32 random hex}.pdf. Every
 * rebuild gets a new name and the old file goes, so no cache or CDN keeps
 * an old sample. The folder has an empty index.php (no file list).
 *
 * A sample is stale when the source file (size, modification time), the
 * page field or the engine version changed. Stale samples keep showing
 * until the rebuild is done; with "auto_regen" the rebuild runs in the
 * background (Action Scheduler, or WP-Cron without it). Visitor requests
 * never write anything.
 */

defined( 'ABSPATH' ) || exit;

final class BPV_Samples {

	const DIR     = 'noxpress-previews';
	const HOOK    = 'bpv_generate';
	const GROUP   = 'book-preview';
	const ENGINE  = '1'; // Bumped when the engine output changes, so samples count as stale.
	const META    = array( '_bpv_source', '_bpv_pages', '_bpv_hidden', '_bpv_sample', '_bpv_error' );

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run_job' ), 10, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_saved' ), 20, 1 );
	}

	/* ---------------------------------------------------------------------
	 * Folder and files
	 * ------------------------------------------------------------------- */

	/** @return array{path:string, url:string} */
	public static function dir(): array {
		$up = wp_get_upload_dir();
		return array(
			'path' => rtrim( $up['basedir'], '/\\' ) . '/' . self::DIR,
			'url'  => rtrim( $up['baseurl'], '/' ) . '/' . self::DIR,
		);
	}

	/**
	 * Creates the folder with an empty index.php (no file list); false when it
	 * cannot be written. No .htaccess: a directive the host does not allow
	 * (AllowOverride) turns every file of the folder into a 500 error, and
	 * the folder only ever holds the PDFs this plugin writes.
	 */
	public static function ensure_dir(): bool {
		$dir = self::dir()['path'];
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny guard file.
		if ( ! is_file( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		// phpcs:enable
		return wp_is_writable( $dir );
	}

	public static function sample( int $product_id ): ?array {
		$s = get_post_meta( $product_id, '_bpv_sample', true );
		if ( ! is_array( $s ) || empty( $s['file'] ) || ! preg_match( '/^\d+-[0-9a-f]{32}\.pdf$/', (string) $s['file'] ) ) {
			return null;
		}
		return $s;
	}

	/** Public URL of a product's sample, or '' (front end: reads only). */
	public static function url( int $product_id ): string {
		$s = self::sample( $product_id );
		if ( ! $s ) {
			return '';
		}
		$d = self::dir();
		if ( ! is_file( $d['path'] . '/' . $s['file'] ) ) {
			return '';
		}
		return $d['url'] . '/' . $s['file'];
	}

	/** Should the front end show a button for this product? */
	public static function visible( int $product_id ): bool {
		return '1' !== (string) get_post_meta( $product_id, '_bpv_hidden', true ) && '' !== self::url( $product_id );
	}

	private static function new_name( int $product_id ): string {
		return $product_id . '-' . bin2hex( random_bytes( 16 ) ) . '.pdf';
	}

	/* ---------------------------------------------------------------------
	 * State
	 * ------------------------------------------------------------------- */

	/** Signature of what the sample is made from. */
	public static function signature( string $path, string $pages ): string {
		clearstatcache( true, $path );
		$size  = is_file( $path ) ? (int) filesize( $path ) : 0;
		$mtime = is_file( $path ) ? (int) filemtime( $path ) : 0;
		// The path counts from the site's own folders, so moving the site to another server keeps samples ready.
		$rel = wp_normalize_path( $path );
		foreach ( array( WP_CONTENT_DIR, ABSPATH ) as $base ) {
			$base = trailingslashit( wp_normalize_path( $base ) );
			if ( 0 === strpos( $rel, $base ) ) {
				$rel = substr( $rel, strlen( $base ) );
				break;
			}
		}
		return md5( $rel . '|' . $size . '|' . $mtime . '|' . $pages . '|' . self::ENGINE );
	}

	/**
	 * State of a product: none | ready | stale | error, with the details.
	 *
	 * @return array{state:string, sample:?array, source:array, pages:string, error:?array}
	 */
	public static function state( int $product_id ): array {
		$key    = (string) get_post_meta( $product_id, '_bpv_source', true );
		$pages  = (string) get_post_meta( $product_id, '_bpv_pages', true );
		$sample = self::sample( $product_id );
		$err    = get_post_meta( $product_id, '_bpv_error', true );
		$err    = is_array( $err ) ? $err : null;
		$src    = '' !== $key ? BPV_Source::resolve( $product_id, $key ) : array(
			'ok'     => false,
			'path'   => '',
			'name'   => '',
			'public' => false,
			'error'  => 'missing',
		);

		if ( $sample && ! is_file( self::dir()['path'] . '/' . $sample['file'] ) ) {
			$sample = null;
		}
		$state = 'none';
		if ( $sample ) {
			$state = ( $src['ok'] && self::signature( $src['path'], self::effective_pages( $pages ) ) === ( $sample['sig'] ?? '' ) ) ? 'ready' : 'stale';
		}
		if ( $err && ( ! $sample || (int) ( $err['time'] ?? 0 ) >= (int) ( $sample['created'] ?? 0 ) ) ) {
			$state = $sample ? 'stale' : 'error';
		}
		return array(
			'state'  => $state,
			'sample' => $sample,
			'source' => $src,
			'pages'  => $pages,
			'error'  => $err,
		);
	}

	/** The page field, or "first N pages" when it is empty. */
	public static function effective_pages( string $pages ): string {
		if ( '' !== trim( $pages ) ) {
			return trim( $pages );
		}
		$s = BPV_Settings::get();
		return BPV_Pages::first( (int) $s['first_pages'], 0 );
	}

	/* ---------------------------------------------------------------------
	 * Generation
	 * ------------------------------------------------------------------- */

	/**
	 * Builds the sample of a product from its saved source and pages.
	 *
	 * @param bool $clamp Pages past the end are dropped (bulk runs).
	 * @return array{ok:bool, error:string, message:string}
	 */
	public static function generate( int $product_id, bool $clamp = false ): array {
		require_once BPV_PATH . 'includes/class-engine.php';

		$key = (string) get_post_meta( $product_id, '_bpv_source', true );
		if ( '' === $key ) {
			return self::failed( $product_id, 'no_source' );
		}
		$src = BPV_Source::resolve( $product_id, $key );
		if ( ! $src['ok'] ) {
			return self::failed( $product_id, 'source_' . $src['error'] );
		}
		if ( ! self::ensure_dir() ) {
			return self::failed( $product_id, 'dir' );
		}

		$spec  = self::effective_pages( (string) get_post_meta( $product_id, '_bpv_pages', true ) );
		$count = BPV_Engine::count( $src['path'] );
		if ( ! $count['ok'] ) {
			return self::failed( $product_id, $count['error'] );
		}
		$parsed = BPV_Pages::parse( $spec, $count['total'], $clamp );
		if ( ! $parsed['ok'] ) {
			return self::failed( $product_id, 'pages', $parsed );
		}

		$name = self::new_name( $product_id );
		$dest = self::dir()['path'] . '/' . $name;
		$res  = BPV_Engine::make( $src['path'], $parsed['pages'], $dest );
		if ( ! $res['ok'] ) {
			return self::failed( $product_id, $res['error'] );
		}

		$old = self::sample( $product_id );
		update_post_meta(
			$product_id,
			'_bpv_sample',
			array(
				'file'    => $name,
				'count'   => $res['count'],
				'total'   => $count['total'],
				'pages'   => BPV_Pages::compact( $parsed['pages'] ),
				'size'    => (int) filesize( $dest ),
				'engine'  => $res['engine'],
				'sig'     => self::signature( $src['path'], $spec ),
				'created' => time(),
			)
		);
		delete_post_meta( $product_id, '_bpv_error' );
		if ( $old && $old['file'] !== $name ) {
			self::delete_file( $old['file'] );
		}
		self::purge( $product_id );
		return array(
			'ok'      => true,
			'error'   => '',
			'message' => '',
		);
	}

	private static function failed( int $product_id, string $error, array $parsed = array() ): array {
		update_post_meta(
			$product_id,
			'_bpv_error',
			array(
				'error' => $error,
				'page'  => (int) ( $parsed['page'] ?? 0 ),
				'perr'  => (string) ( $parsed['error'] ?? '' ),
				'time'  => time(),
			)
		);
		return array(
			'ok'      => false,
			'error'   => $error,
			'message' => self::error_message( $error, $parsed ),
		);
	}

	/** The admin message for an error code. */
	public static function error_message( string $error, array $parsed = array() ): string {
		switch ( $error ) {
			case 'no_source':
			case 'source_missing':
				return __( 'Δεν έχει επιλεγεί PDF για το δείγμα.', 'book-preview' );
			case 'source_remote':
				return __( 'Το PDF βρίσκεται σε άλλον server. Το plugin διαβάζει μόνο αρχεία αυτού του site (χωρίς εξωτερικές κλήσεις).', 'book-preview' );
			case 'source_not_found':
				return __( 'Το αρχείο PDF δεν βρέθηκε στον server.', 'book-preview' );
			case 'source_not_pdf':
			case 'not_pdf':
				return __( 'Το αρχείο δεν είναι PDF.', 'book-preview' );
			case 'dir':
				return __( 'Ο φάκελος uploads/noxpress-previews δεν μπορεί να γραφτεί. Έλεγξε τα δικαιώματα του φακέλου uploads.', 'book-preview' );
			case 'pages':
				return BPV_Pages::message( $parsed + array( 'error' => 'syntax', 'page' => 0 ) );
			case 'encrypted':
				return __( 'Το PDF είναι κρυπτογραφημένο. Αν έχει μόνο κωδικό δικαιωμάτων, το qpdf στον server μπορεί να το ανοίξει· αλλιώς ανέβασε μια εκδοχή χωρίς κρυπτογράφηση.', 'book-preview' );
			case 'unsupported':
				return __( 'Το PDF χρησιμοποιεί συμπίεση που η μηχανή δεν διαβάζει. Με qpdf ή Ghostscript στον server θα δοκιμαστούν αυτόματα.', 'book-preview' );
			case 'no_pages':
				return __( 'Το PDF δεν έχει σελίδες που να διαβάζονται.', 'book-preview' );
			case 'io':
				return __( 'Το αρχείο του δείγματος δεν γράφτηκε (χώρος ή δικαιώματα στον δίσκο).', 'book-preview' );
			default:
				return __( 'Το PDF είναι κατεστραμμένο ή δεν διαβάζεται. Δοκίμασε να το εξαγάγεις ξανά από το πρόγραμμα σελιδοποίησης.', 'book-preview' );
		}
	}

	/** Deletes a product's sample file and meta (not the source or the page field). */
	public static function delete( int $product_id ): void {
		$s = self::sample( $product_id );
		if ( $s ) {
			self::delete_file( $s['file'] );
		}
		delete_post_meta( $product_id, '_bpv_sample' );
		delete_post_meta( $product_id, '_bpv_error' );
		self::purge( $product_id );
	}

	private static function delete_file( string $file ): void {
		if ( ! preg_match( '/^\d+-[0-9a-f]{32}\.pdf$/', $file ) ) {
			return;
		}
		$path = self::dir()['path'] . '/' . $file;
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/** Product deleted for good: its sample goes too. */
	public static function on_delete( $post_id ): void {
		$post_id = (int) $post_id;
		if ( 'product' === get_post_type( $post_id ) && self::sample( $post_id ) ) {
			self::delete( $post_id );
		}
	}

	/* ---------------------------------------------------------------------
	 * Background rebuild
	 * ------------------------------------------------------------------- */

	/** Products whose PDF or pages changed in this request's product save (set by the box). */
	public static $changed = array();

	/**
	 * After a product save: queue a build when the sample is stale, or when
	 * the PDF or pages were just set and there is no sample yet. A sample
	 * the admin deleted is not rebuilt by a later, unrelated save.
	 */
	public static function on_product_saved( $product_id ): void {
		$product_id = (int) $product_id;
		if ( ! BPV_Settings::get()['auto_regen'] || '' === (string) get_post_meta( $product_id, '_bpv_source', true ) ) {
			return;
		}
		$st = self::state( $product_id );
		if ( $st['error'] ) {
			return;
		}
		if ( 'stale' === $st['state'] || ( 'none' === $st['state'] && ! empty( self::$changed[ $product_id ] ) ) ) {
			self::schedule( $product_id );
		}
	}

	public static function schedule( int $product_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( self::HOOK, array( $product_id ), self::GROUP ) ) {
				as_enqueue_async_action( self::HOOK, array( $product_id ), self::GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK, array( $product_id ) ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK, array( $product_id ) );
		}
	}

	public static function run_job( $product_id ): void {
		$product_id = (int) $product_id;
		if ( $product_id > 0 && 'product' === get_post_type( $product_id ) ) {
			self::generate( $product_id );
		}
	}

	/* ---------------------------------------------------------------------
	 * Caches
	 * ------------------------------------------------------------------- */

	/** Purges the product page from the usual page caches. */
	public static function purge( int $product_id ): void {
		clean_post_cache( $product_id );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $product_id );
		}
		do_action( 'litespeed_purge_post', $product_id );
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $product_id );
		}
	}
}


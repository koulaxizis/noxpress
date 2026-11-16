<?php
/**
 * EBD_Store — the private folder of stamped copies, the daily cleanup and
 * the issue log (Bible §12, «Ιδιωτικά αρχεία» and «Χώρος στον δίσκο»).
 *
 * Folder: uploads/noxpress-ebook-delivery-<12 hex>/ (the suffix is random,
 * kept in ebd_dir), with an empty index.php, .htaccess and web.config that
 * deny every request. Copies are only ever read by PHP (WooCommerce's
 * «Force downloads»), so a host that ignores the deny files (nginx) still
 * needs a name that is guessed: <permission id>-<32 hex>.pdf|epub, the
 * hex an HMAC with the plugin's secret. An HTTP self-test on the status
 * tab says whether the folder answers from outside.
 *
 * Every path goes through FILE_RE before it is read, served or deleted.
 * Writing: <name>.<random>.tmp, then rename(), so nobody ever gets half a
 * file. Before writing, the free space must hold the source size twice
 * plus MIN_FREE; otherwise the original is served.
 *
 * Cleanup (Action Scheduler, else WP-Cron, daily): copies older than the
 * setting cache_days and .tmp files older than an hour.
 *
 * Issue log (ebd_issues, autoload off): the newest 100 problems (time,
 * product, file name, reason), one line per file and reason a day.
 */

defined( 'ABSPATH' ) || exit;

final class EBD_Store {

	const FILE_RE  = '/^\d{1,20}-[a-f0-9]{32}\.(pdf|epub)$/';
	const TMP_RE   = '/^\d{1,20}-[a-f0-9]{32}\.(pdf|epub)\.[a-f0-9]{12}\.tmp$/';
	const MIN_FREE = 268435456; // 256 MB.
	const ISSUES   = 'ebd_issues';
	const MAX_LOG  = 100;
	const HOOK     = 'ebd_cleanup';

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'daily' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 30 );
	}

	/** The daily task: expired copies and stale temporary files. */
	public static function daily(): void {
		self::cleanup( false );
	}

	/** Daily cleanup through Action Scheduler (group ebook-delivery), else WP-Cron. */
	public static function schedule(): void {
		try {
			if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
				if ( ! as_has_scheduled_action( self::HOOK, array(), 'ebook-delivery' ) ) {
					as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, array(), 'ebook-delivery' );
				}
			} elseif ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/** Absolute path of the folder (no trailing slash), '' when uploads are unavailable. */
	public static function dir(): string {
		$up = wp_upload_dir( null, false );
		if ( ! empty( $up['error'] ) || empty( $up['basedir'] ) ) {
			return '';
		}
		$key = (string) get_option( 'ebd_dir', '' );
		if ( ! preg_match( '/^[a-f0-9]{12}$/', $key ) ) {
			$key = bin2hex( random_bytes( 6 ) );
			update_option( 'ebd_dir', $key, false );
		}
		return untrailingslashit( (string) $up['basedir'] ) . '/noxpress-ebook-delivery-' . $key;
	}

	/** Creates and protects the folder; false when it cannot be written. */
	public static function ensure_dir(): bool {
		$dir = self::dir();
		if ( '' === $dir ) {
			return false;
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "# Ebook Delivery: private folder.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $body ) {
			if ( ! is_file( $dir . '/' . $name ) ) {
				@file_put_contents( $dir . '/' . $name, $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		return is_writable( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
	}

	/** The copy's file name for a permission and a key (both decide the hex). */
	public static function name_for( int $permission_id, string $key, string $ext ): string {
		$hex = substr( hash_hmac( 'sha256', $permission_id . '|' . $key, (string) get_option( 'ebd_secret', '' ) . 'store' ), 0, 32 );
		return $permission_id . '-' . $hex . '.' . ( 'epub' === $ext ? 'epub' : 'pdf' );
	}

	/** Absolute path of a name that matches FILE_RE, '' otherwise. */
	public static function path( string $name ): string {
		$dir = self::dir();
		return ( '' !== $dir && preg_match( self::FILE_RE, $name ) ) ? $dir . '/' . $name : '';
	}

	/** True when $path is one of our copies (folder and name pattern). */
	public static function is_ours( string $path ): bool {
		$dir = self::dir();
		if ( '' === $dir || '' === $path ) {
			return false;
		}
		return dirname( $path ) === $dir && 1 === preg_match( self::FILE_RE, basename( $path ) );
	}

	/** A fresh temporary path for a name (FILE_RE checked). */
	public static function tmp_for( string $name ): string {
		$path = self::path( $name );
		return '' === $path ? '' : $path . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
	}

	/** Enough free space for a source of $bytes? (unknown free space = yes) */
	public static function room_for( int $bytes ): bool {
		$dir  = self::dir();
		$free = '' !== $dir && is_dir( $dir ) && function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $free ) {
			return true;
		}
		return (float) $free >= 2.0 * $bytes + self::MIN_FREE;
	}

	/** Deletes copies older than cache_days and stale .tmp files. Returns files deleted. */
	public static function cleanup( bool $all = false ): int {
		$dir = self::dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return 0;
		}
		$days  = class_exists( 'EBD_Settings' ) ? (int) EBD_Settings::get()['cache_days'] : 7;
		$limit = time() - max( 1, $days ) * DAY_IN_SECONDS;
		$tmp   = time() - HOUR_IN_SECONDS;
		$n     = 0;
		foreach ( (array) scandir( $dir ) as $f ) {
			$f    = (string) $f;
			$path = $dir . '/' . $f;
			$copy = 1 === preg_match( self::FILE_RE, $f );
			$part = 1 === preg_match( self::TMP_RE, $f );
			if ( ! $copy && ! $part ) {
				continue;
			}
			$mtime = (int) @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $all || ( $copy && $mtime < $limit ) || ( $part && $mtime < $tmp ) ) {
				if ( @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
					++$n;
				}
			}
		}
		return $n;
	}

	/** Number and total size of the copies in the folder. */
	public static function stats(): array {
		$dir   = self::dir();
		$count = 0;
		$bytes = 0;
		if ( '' !== $dir && is_dir( $dir ) ) {
			foreach ( (array) scandir( $dir ) as $f ) {
				if ( preg_match( self::FILE_RE, (string) $f ) ) {
					++$count;
					$bytes += (int) @filesize( $dir . '/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}
		return array(
			'count' => $count,
			'bytes' => $bytes,
		);
	}

	/**
	 * HTTP self-test: 'safe' (a probe file does not download), 'exposed',
	 * or 'unknown' (the site did not answer). Admin only.
	 */
	public static function web_test(): string {
		if ( ! self::ensure_dir() ) {
			return 'unknown';
		}
		$dir = self::dir();
		$up  = wp_upload_dir( null, false );
		$url = trailingslashit( (string) $up['baseurl'] ) . basename( $dir );
		$f   = 'probe-' . bin2hex( random_bytes( 6 ) ) . '.txt';
		$tok = bin2hex( random_bytes( 8 ) );
		@file_put_contents( $dir . '/' . $f, $tok ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$r = wp_remote_get(
			$url . '/' . $f,
			array(
				'timeout'     => 8,
				'redirection' => 0,
			)
		);
		@unlink( $dir . '/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		if ( is_wp_error( $r ) ) {
			return 'unknown';
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		return ( 200 === $code && false !== strpos( (string) wp_remote_retrieve_body( $r ), $tok ) ) ? 'exposed' : 'safe';
	}

	/* ---------------------------------------------------------------------
	 * Issue log
	 * ------------------------------------------------------------------- */

	/** Reasons => labels (msgids). */
	public static function reasons(): array {
		return array(
			'encrypted'   => __( 'Κρυπτογραφημένο αρχείο ή DRM: στάλθηκε το αρχικό', 'ebook-delivery' ),
			'damaged'     => __( 'Χαλασμένο αρχείο: στάλθηκε το αρχικό', 'ebook-delivery' ),
			'unsupported' => __( 'Μορφή που δεν δέχεται υδατογράφημα: στάλθηκε το αρχικό', 'ebook-delivery' ),
			'not_pdf'     => __( 'Η κατάληξη λέει PDF αλλά το αρχείο δεν είναι PDF: στάλθηκε το αρχικό', 'ebook-delivery' ),
			'not_epub'    => __( 'Η κατάληξη λέει EPUB αλλά το αρχείο δεν είναι EPUB: στάλθηκε το αρχικό', 'ebook-delivery' ),
			'unsafe'      => __( 'Ύποπτο αρχείο ZIP (ονόματα ή μέγεθος): στάλθηκε το αρχικό', 'ebook-delivery' ),
			'too_big'     => __( 'Μεγαλύτερο από το όριο μεγέθους: στάλθηκε το αρχικό', 'ebook-delivery' ),
			'disk'        => __( 'Λίγος ελεύθερος χώρος στον δίσκο: στάλθηκε το αρχικό', 'ebook-delivery' ),
			'folder'      => __( 'Ο ιδιωτικός φάκελος δεν γράφεται: στάλθηκε το αρχικό', 'ebook-delivery' ),
			'remote'      => __( 'Αρχείο σε άλλον server: στάλθηκε όπως είναι', 'ebook-delivery' ),
			'io'          => __( 'Σφάλμα ανάγνωσης ή εγγραφής: στάλθηκε το αρχικό', 'ebook-delivery' ),
			'error'       => __( 'Απρόσμενο σφάλμα: στάλθηκε το αρχικό', 'ebook-delivery' ),
		);
	}

	/** Adds a line, at most once a day per (product, file, reason). */
	public static function issue( int $product_id, string $file, string $reason ): void {
		$reason = isset( self::reasons()[ $reason ] ) ? $reason : 'error';
		$file   = mb_substr( sanitize_file_name( basename( $file ) ), 0, 120 );
		$log    = get_option( self::ISSUES, array() );
		$log    = is_array( $log ) ? $log : array();
		foreach ( $log as $row ) {
			if ( is_array( $row ) && (int) ( $row['p'] ?? 0 ) === $product_id && ( $row['f'] ?? '' ) === $file && ( $row['r'] ?? '' ) === $reason && (int) ( $row['t'] ?? 0 ) > time() - DAY_IN_SECONDS ) {
				return;
			}
		}
		array_unshift(
			$log,
			array(
				't' => time(),
				'p' => $product_id,
				'f' => $file,
				'r' => $reason,
			)
		);
		update_option( self::ISSUES, array_slice( $log, 0, self::MAX_LOG ), false );
	}

	/** The log, newest first, validated. */
	public static function issues(): array {
		$log = get_option( self::ISSUES, array() );
		$out = array();
		foreach ( is_array( $log ) ? $log : array() as $row ) {
			if ( is_array( $row ) && isset( $row['t'], $row['p'], $row['f'], $row['r'] ) ) {
				$out[] = array(
					't' => (int) $row['t'],
					'p' => (int) $row['p'],
					'f' => (string) $row['f'],
					'r' => isset( self::reasons()[ $row['r'] ] ) ? (string) $row['r'] : 'error',
				);
			}
		}
		return $out;
	}
}

<?php
/**
 * NM_Export_Engine — Streaming MySQL dump (pure PHP, zero mysqldump).
 *
 * Γιατί ΠΟΤΕ exec()/mysqldump:
 *  - Shared hosting: το exec() είναι συχνά disabled (disable_functions).
 *  - Ο μοναδικός πραγματικός λόγος ύπαρξης του mysqldump σε flow
 *    σαν αυτό είναι η ταχύτητα — τη βγάζουμε με batched multi-row
 *    INSERTs + keyset pagination + time-budgeted steps.
 *
 * Αρχιτεκτονική (stage-then-pack):
 *  1. PHASE 'db': η βάση dump-άρεται σε staging αρχείο στον δίσκο
 *     (uploads/noxpress-migrator-tmp/dump.sql) — APPEND-able, άρα
 *     resumable με υπολογίσιμο κόστος μετά από οποιοδήποτε timeout.
 *  2. Το packaging (ZIP, manifest) γίνεται στο Wave 2 (files engine):
 *     το dump.sql προστίθεται ως ΟΛΟΚΛΗΡΩΜΕΝΟ entry μέσω
 *     NM_Zip_Writer::add_file().
 *
 *  ΠΟΤΕ δεν μπαίνει περιεχόμενο στη μνήμη: rows διαβάζονται batch-άτα
 *  (ROW_BATCH), escape-άρονται, συγκεντρώνονται σε buffered statement
 *  (INSERT ... VALUES (...),(...)) και flush-άρονται στο staging
 *  αρχείο όταν το buffer περάσει το FLUSH_BYTES.
 *
 * Checkpoint (option 'nm_checkpoint' — δεσμευτικό contract από
 * Μέρος 1/4): {phase, table, table_offset, bytes_written, started,
 * updated, volumes} + ΜΙΑ τεκμηριωμένη επέκταση 'pk_last' (βλ.
 * σημείωση παρακάτω — απαραίτητη για το keyset resume). Η λίστα
 * των tables ΔΕΝ αποθηκεύεται: ξαναπαράγεται ντετερμινιστικά
 * (SHOW TABLES ORDER BY) σε κάθε resume — ίδια σειρά => ίδια
 * συνέχεια, χωρίς να φουσκώνει το checkpoint.
 *
 * Lock: transient 'nm_lock' — δεν επιτρέπονται δύο παράλληλα exports
 * (corruption guard). Timeout 10' — αν πεθάνει ένα PHP process
 * midway, ο επόμενος administrator kick ξεκλειδώνει.
 */

defined( 'ABSPATH' ) || exit;

final class NM_Export_Engine {

	/** Rows ανά SELECT batch. */
	const ROW_BATCH = 500;

	/** Max tuples ανά INSERT statement (πριν γίνει flush). */
	const TUPLES_PER_INSERT = 100;

	/** Buffer flush όριο (bytes) — 512 KiB. */
	const FLUSH_BYTES = 524288;

	/** Χρονικό budget ανά step (δευτ.) — κρατά το request κάτω από
	 * τυπικά max_execution_time (30s) και PHP-FPM timeouts. */
	const STEP_BUDGET = 15;

	/** Lock timeout (δευτ.) — self-healing σε killed processes. */
	const LOCK_TIMEOUT = 600;

	/** statuses: idle | running | paused | complete | failed. */
	private $status = 'idle';

	/** Το staging handle (resource|null). */
	private $fh = null;

	/** Buffer τρέχοντος INSERT batch. */
	private $buf = '';

	/** Πλήθος tuples στο τρέχον buffer. */
	private $tuples = 0;

	/** Column cache του τρέχοντος πίνακα. */
	private $cols = array();

	/** Primary keys του τρέχοντος πίνακα (empty = OFFSET mode). */
	private $pk = array();

	/** Τελευταίο composite pk value (keyset cursor). */
	private $pk_last = null;

	/** Rows processed στον τρέχοντα πίνακα (progress only). */
	private $row_count = 0;

	/** Bytes γραμμένα συνολικά στο dump.sql. */
	private $bytes = 0;

	/** Το checkpoint όπως φορτώθηκε/αποθηκεύεται (array). */
	private $ck = array();

	/** Σφάλμα τελευταίου step (string|null). */
	private $error = null;

	/* =====================================================================
	 * init() — worker class: καμία WordPress hook δουλειά εδώ.
	 * (Το bootstrap καλεί ::init() σε ΟΛΕΣ τις συνιστώσες — το
	 * pattern είναι να υπάρχει, άδειο για engines.)
	 * =================================================================== */

	public static function init(): void {
		// Απολύτως τίποτα. Οι routes/routes-ajax δένουνε στο Admin UI
		// (Μέρος 4/4) και το CLI (Wave 2) — τα engines είναι καθαροί
		// workers που καλούνται ρητά.
	}

	/* =====================================================================
	 * Κατασκευή / lifecycle
	 * =================================================================== */

	/**
	 * Ξεκινά νέο export (ή παίρνει το υπάρχον checkpoint).
	 *
	 * @param bool $fresh TRUE = καθαρή εκκίνηση (σβήνει staging + checkpoint).
	 * @return self
	 */
	public static function start( bool $fresh = true ): self {

		if ( $fresh ) {
			self::clear_staging();
			delete_option( 'nm_checkpoint' );
		}

		$engine = new self();
		$engine->load_checkpoint();

		if ( 'idle' === $engine->status || '' === ( $engine->ck['table'] ?? '' ) ) {
			// Πρώτη εκκίνηση — γράφουμε το SQL header μόνο ΜΙΑ φορά
			// (το resume ΔΕΝ το ξαναγράψει — το αρχείο συνεχίζεται).
			if ( 'idle' === $engine->status ) {
				$engine->open_staging( 'wb' );
				$engine->write_sql_header();
				$engine->close_staging();
				$engine->init_checkpoint();
			}
		}

		return $engine;
	}

	private function init_checkpoint(): void {

		$this->ck = array(
			'phase'         => 'db',
			'table'         => '',
			'table_offset'  => 0,
			'pk_last'       => null, // τεκμηριωμένη επέκταση (keyset cursor).
			'bytes_written' => 0,
			'started'       => time(),
			'updated'       => time(),
			'volumes'       => 0,
		);
		$this->bytes = 0;
		$this->save_checkpoint();
	}

	private function load_checkpoint(): void {

		$raw = get_option( 'nm_checkpoint', '' );

		if ( is_string( $raw ) && '' !== $raw ) {
			$dec = json_decode( $raw, true );
			if ( is_array( $dec ) && isset( $dec['phase'], $dec['table'] ) ) {
				$this->ck        = $dec;
				$this->bytes     = (int) ( $dec['bytes_written'] ?? 0 );
				$this->pk_last   = $dec['pk_last'] ?? null;

				if ( 'failed' === $dec['phase'] ) {
					$this->status = 'failed';
				} elseif ( 'done' === $dec['phase'] ) {
					$this->status = 'complete';
				} elseif ( 'db' === $dec['phase'] ) {
					$this->status = 'paused';
				} else {
					$this->status = 'paused'; // files/packaged phases — τα χειρίζεται το Files Engine.
				}
				return;
			}
		}

		$this->status = 'idle';
	}

	/** Persist του checkpoint — η ΜΟΝΑΔΙΚΗ εγγραφή state. */
	private function save_checkpoint(): void {

		$this->ck['phase']         = $this->phase();
		$this->ck['table']         = $this->ck['table'] ?? '';
		$this->ck['table_offset']  = $this->row_count;
		$this->ck['pk_last']       = $this->pk_last;
		$this->ck['bytes_written'] = $this->bytes;
		$this->ck['updated']       = time();

		update_option( 'nm_checkpoint', wp_json_encode( $this->ck, JSON_UNESCAPED_UNICODE ), false );
	}

	/* =====================================================================
	 * Staging I/O
	 * =================================================================== */

	/** Ντετερμινιστικό staging path (fixed — no random component, το
	 * resume το βρίσκει πάντα). Ο φάκελος σβήνεται στο clear_staging()
	 * και από το uninstall.php (glob 'noxpress-migrator-tmp*'). */
	public static function staging_dir(): string {

		$up = wp_upload_dir();
		$base = (string) ( $up['basedir'] ?? '' );

		if ( '' === $base ) {
			throw new RuntimeException( 'NM_Export_Engine: wp_upload_dir() unavailable.' );
		}

		$dir = $base . '/noxpress-migrator-tmp';

		if ( ! is_dir( $dir ) ) {
			// Guard: αν υπάρχει FILE με αυτό το όνομα (astray), fail loud.
			if ( file_exists( $dir ) ) {
				throw new RuntimeException( 'NM_Export_Engine: staging path occupied by a file — ' . $dir );
			}
			if ( ! wp_mkdir_p( $dir ) ) {
				throw new RuntimeException( 'NM_Export_Engine: cannot create staging dir ' . $dir );
			}
			// htaccess deny — staging ΔΕΝ είναι ποτέ publicly reachable.
			@file_put_contents( $dir . '/.htaccess', "Require all denied\n" );
		}

		return $dir;
	}

	public static function staging_sql_path(): string {
		return self::staging_dir() . '/dump.sql';
	}

	private function open_staging( string $mode ): void {

		$path = self::staging_sql_path();
		$fh   = @fopen( $path, $mode );

		if ( false === $fh ) {
			throw new RuntimeException( 'NM_Export_Engine: cannot open staging file ' . $path . ' [' . $mode . ']' );
		}

		$this->fh = $fh;
	}

	private function close_staging(): void {
		if ( null !== $this->fh ) {
			@fflush( $this->fh );
			@fclose( $this->fh );
			$this->fh = null;
		}
	}

	/** Σβήνει το staging dir (αρχικά exports + retries). */
	public static function clear_staging(): void {

		$dir = self::staging_dir();

		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			@unlink( $dir . '/' . $entry );
		}
		@rmdir( $dir );
	}

	/* =====================================================================
	 * Lock (singleton export guard)
	 * =================================================================== */

	private static function acquire_lock(): bool {

		if ( false !== get_transient( 'nm_lock' ) ) {
			return false;
		}

		set_transient( 'nm_lock', time(), self::LOCK_TIMEOUT );

		return true;
	}

	private static function release_lock(): void {
		delete_transient( 'nm_lock' );
	}

	/* =====================================================================
	 * Το stepping — η καρδιά του engine
	 * =================================================================== */

	/**
	 * Εκτελεί μέχρι STEP_BUDGET δευτερόλεπτα δουλειάς (ή μέχρι να
	 * τελειώσει η βάση). Επιστρέφει progress/status report.
	 *
	 * @return array{status:string, table:string, rows:int, bytes:int,
	 *               pct:float, done_tables:int, total_tables:int,
	 *               error:?string}
	 */
	public function step(): array {

		if ( 'complete' === $this->status || 'failed' === $this->status ) {
			return $this->report();
		}

		if ( ! self::acquire_lock() ) {
			// Παράλληλο export ή killed-process lock — ΔΕΝ προχωράμε.
			// Το report δείχνει ότι είμαστε paused (resumable).
			$this->status = 'paused';
			return $this->report();
		}

		$t0   = microtime( true );
		$this->error = null;

		try {
			$this->status = 'running';

			// Append mode — το resume συνεχίζει εκεί που σταμάτησε.
			$this->open_staging( 'ab' );

			$tables  = self::table_list();
			$current = (string) ( $this->ck['table'] ?? '' );

			// Δείκτης resume: πίνακες είναι sorted — πρώτος με name >
			// current. Αν current === '' ξεκινάμε από το πρώτο.
			$queue = array();
			$carry = ( '' === $current );

			foreach ( $tables as $t ) {
				if ( $carry ) {
					$queue[] = $t;
				} elseif ( $t === $current ) {
					$carry = true; // Ο current ξαναδουλεύεται από το pk_last.
					$queue[] = $t;
				}
			}

			foreach ( $queue as $table ) {

				if ( ! $this->prepare_table( $table ) ) {
					continue; // Excluded (π.χ. δεν υπάρχει πια — ALTER mid-export).
				}

				// Νέος πίνακας ≠ checkpoint table → γράφουμε DDL +
				// προετοιμασία INSERT προθέματος.
				if ( $table !== $current ) {
					$this->write_table_ddl( $table );
					$this->ck['table'] = $table;
					$this->pk_last    = null;
					$this->row_count  = 0;
					$this->flush_buffer(); // καθαρό buffer ανά πίνακα.
					$this->buf = '';
					$this->tuples = 0;
					$this->prefix = $this->insert_prefix( $table );
					$current = $table;
				}

				$this->dump_rows( $t0 );

				if ( microtime( true ) - $t0 >= self::STEP_BUDGET ) {
					break; // Time-out budget — αλλά ΠΑΝΤΑ με saved checkpoint.
				}

				// Πίνακας τελείωσε μέσα στο budget → επόμενος αμέσως.
			}

			$this->flush_buffer();

			// Όλη η ουρά εξαντλήθηκε χωρίς time-out → βάση τελείωσε.
			if ( microtime( true ) - $t0 < self::STEP_BUDGET ) {
				$this->write_sql_footer();
				$this->close_staging();
				$this->status = 'complete';
				$this->save_checkpoint();
				self::release_lock();
				return $this->report();
			}

			$this->close_staging();
			$this->status = 'paused'; // Budget εξαντλήθηκε — resumable.
			$this->save_checkpoint();

		} catch ( Throwable $e ) {
			$this->error  = $e->getMessage();
			$this->status = 'failed';
			$this->save_checkpoint();
		} finally {
			$this->close_staging();
			self::release_lock();
		}

		return $this->report();
	}

	/** Progress report (ποτέ sensitive data — μόνο counters). */
	public function report(): array {

		$tables = self::table_list();
		$cur    = (string) ( $this->ck['table'] ?? '' );
		$done   = 0;

		foreach ( $tables as $t ) {
			if ( '' !== $cur && $t <= $cur ) { // sorted συγκρίσιμα strings.
				$done++;
			}
		}

		return array(
			'status'       => $this->status,
			'table'        => $cur,
			'rows'         => $this->row_count,
			'bytes'        => $this->bytes,
			'pct'          => ( count( $tables ) > 0 ) ? round( ( $done / count( $tables ) ) * 100, 1 ) : 0.0,
			'done_tables'  => $done,
			'total_tables' => count( $tables ),
			'error'        => $this->error,
		);
	}

	/* =====================================================================
	 * Tables discovery + introspection
	 * =================================================================== */

	/**
	 * ΟΛΕΣ οι BASE TABLES (όχι views) της βάσης, SORTED — ντετερμινιστική
	 * σειρά = βάση για το resume χωρίς αποθηκευμένη ουρά.
	 *
	 * Χωρίς prefix filtering: migration σημαίνει ΟΛΟΚΛΗΡΩΤΙΚΗ μεταφορά —
	 * οποιοδήποτε custom table (εκτός prefix, custom integrations,
	 * WLmpeg counts table κ.λπ.) πρέπει να ταξιδέψει ΚΑΙ ΕΚΕΙΝΟ.
	 */
	public static function table_list(): array {

		global $wpdb;

		static $list = null;

		if ( null !== $list ) {
			return $list;
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only introspection.
			'SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'',
			ARRAY_N
		);

		$list = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				if ( isset( $r[0] ) && is_string( $r[0] ) ) {
					$list[] = $r[0];
				}
			}
		}

		sort( $list, SORT_STRING );

		return $list;
	}

	/**
	 * Φορτώνει cols + pk του πίνακα. Επιστρέφει FALSE αν ο πίνακας
	 * χάθηκε mid-export (DROP από άλλη διεργασία) — τότε skip silently,
	 * ο πίνακας δεν υπήρχε στο εξαγόμενο σύστημα-στιγμιότυπο άλλωστε.
	 */
	private function prepare_table( string $table ): bool {

		global $wpdb;

		$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		if ( $exists !== $table ) {
			return false;
		}

		$this->cols = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SHOW COLUMNS FROM `' . $this->ident( $table ) . '`'
		);

		if ( ! is_array( $this->cols ) || empty( $this->cols ) ) {
			return false;
		}

		// Composite/single PRIMARY KEY detection (compound-άρα ряд).
		$pk_cols = array();
		$keys    = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SHOW KEYS FROM `' . $this->ident( $table ) . '` WHERE Key_name = \'PRIMARY\'',
			ARRAY_A
		);

		if ( is_array( $keys ) ) {
			foreach ( $keys as $k ) {
				$seq = (int) ( $k['Seq_in_index'] ?? 0 );
				$col = (string) ( $k['Column_name'] ?? '' );
				if ( $seq >= 1 && '' !== $col ) {
					$pk_cols[ $seq ] = $col;
				}
			}
			ksort( $pk_cols );
		}

		$this->pk = array_values( $pk_cols );

		return true;
	}

	/* =====================================================================
	 * DDL + rows
	 * =================================================================== */

	/** DROP + CREATE του πίνακα στο staging dump. */
	private function write_table_ddl( string $table ): void {

		global $wpdb;

		$create = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SHOW CREATE TABLE `' . $this->ident( $table ) . '`',
			ARRAY_N
		);

		$sql = "\n\n--\n-- Table structure: {$table}\n--\n\n";
		$sql .= 'DROP TABLE IF EXISTS `' . $this->ident( $table ) . "`;\n";

		if ( is_array( $create ) && isset( $create[1] ) ) {
			$sql .= $create[1] . ";\n";
		} else {
			// Χωρίς DDL ο πίνακας δεν αποκαθίσταται ποτέ — fail loudly.
			throw new RuntimeException( 'NM_Export_Engine: SHOW CREATE TABLE failed for ' . $table );
		}

		$this->write_staged( $sql );
	}

	/**
	 * Rows loop του τρέχοντος πίνακα (μέχρι τελείωμά του ή budget).
	 * Keyset pagination όταν έχουμε PRIMARY KEY (fast σε όλες τις
	 * σελίδες — ΔΕΝ ξαναδιαβάζει τα προηγούμενα rows), OFFSET
	 * fallback στους (σπάνιους) πίνακες χωρίς PK.
	 */
	private function dump_rows( float $t0 ): void {

		global $wpdb;

		$table  = $this->ck['table'];
		$column_list = '`' . implode( '`, `', array_map( array( $this, 'ident' ), $this->cols ) ) . '`';

		while ( true ) {

			if ( microtime( true ) - $t0 >= self::STEP_BUDGET ) {
				return; // Budget — τα tuples flushed ήδη από το buffer.
			}

			// ---- Query construction ----
			if ( ! empty( $this->pk ) && null !== $this->pk_last ) {
				// Keyset: WHERE (pk1, pk2) > (:last1, :last2) — MySQL row
				// constructor comparison, compound-safe.
				$placeholders = implode( ', ', array_fill( 0, count( $this->pk_last ), '%s' ) );
				$where = ' WHERE (' . implode( ', ', $this->quoting( $this->pk ) ) . ') > (' . $placeholders . ')';
				$args = array_merge( array( $where ), $this->pk_last );
			} else {
				$where    = '';
				$args     = array( '' );
				$offset   = (int) ( $this->ck['table_offset'] ?? 0 );
			}

			$order = empty( $this->pk )
				? ''
				: ' ORDER BY ' . implode( ', ', $this->quoting( $this->pk ) );

			$limit = ' LIMIT ' . self::ROW_BATCH;

			// OFFSET mode: LIMIT offset, batch (rows of last batched page).
			$sql_suffix = $order . $limit . ( empty( $this->pk ) ? '' : '' );

			if ( empty( $this->pk ) ) {
				$sql_suffix = ' LIMIT ' . $offset . ', ' . self::ROW_BATCH;
			}

			$query = 'SELECT ' . $column_list . ' FROM `' . $this->ident( $table ) . '`' . $args[0] . $sql_suffix;

			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				empty( $this->pk ) || null === $this->pk_last
					? str_replace( '  ', ' ', $query )
					: ( null === $this->pk_last
						? $query
						: $wpdb->prepare( $query, array_slice( $args, 1 ) ) ),
				ARRAY_N
			);

			if ( ! is_array( $rows ) ) {
				throw new RuntimeException( 'NM_Export_Engine: SELECT failed on ' . $table . ' — ' . $wpdb->last_error );
			}

			if ( empty( $rows ) ) {
				return; // Πίνακας εξαντλήθηκε.
			}

			foreach ( $rows as $row ) {
				$this->add_tuple( $row );
				$this->row_count++;
			}

			// Keyset cursor: οι τιμές pk της ΤΕΛΕΥΤΑΙΑΣ γραμμής του batch.
			if ( ! empty( $this->pk ) ) {
				$last_row = $rows[ count( $rows ) - 1 ];
				$vals     = array();
				foreach ( $this->pk as $i => $col ) {
					$pos = array_search( $col, $this->cols, true );
					$vals[] = (string) ( $last_row[ $pos ] ?? '' );
				}
				$this->pk_last = $vals;
			} else {
				// OFFSET mode: offset advances by rows read.
				$this->ck['table_offset'] = (int) ( $this->ck['table_offset'] ?? 0 ) + count( $rows );
			}

			if ( count( $rows ) < self::ROW_BATCH ) {
				return; // Τελευταίο (μικρότερο) batch — πίνακας τελείωσε.
			}
		}
	}

	/** Thêm ένα row tuple στο buffered INSERT statement. */
	private function add_tuple( array $row ): void {

		$values = array();
		foreach ( $row as $v ) {
			$values[] = $this->esc( $v );
		}

		if ( 0 === $this->tuples ) {
			$this->buf .= $this->prefix;
		} else {
			$this->buf .= ",\n";
		}

		$this->buf .= '(' . implode( ', ', $values ) . ')';
		$this->tuples++;

		if ( $this->tuples >= self::TUPLES_PER_INSERT || strlen( $this->buf ) >= self::FLUSH_BYTES ) {
			$this->flush_buffer();
		}
	}

	/** Flush του buffer (κλείνει το statement με ;\n) + reset. */
	private function flush_buffer(): void {

		if ( $this->tuples > 0 ) {
			$this->write_staged( $this->buf . ";\n" );
		}

		$this->buf    = '';
		$this->tuples = 0;
		$this->prefix = ''; // Αναδημιουργείται στο επόμενο table setup.
	}

	/** INSERT prefix του πίνακα (γίνεται property για resume-cleanliness). */
	private function insert_prefix( string $table ): string {
		$cols = '`' . implode( '`, `', array_map( array( $this, 'ident' ), $this->cols ) ) . '`';
		return 'INSERT INTO `' . $this->ident( $table ) . '` (' . $cols . ") VALUES\n";
	}

	/** @var string Τρέχον INSERT prefix (rebuild ανά πίνακα). */
	private $prefix = '';

	/* =====================================================================
	 * Escaping / identifiers
	 * =================================================================== */

	/** Identifier escaping (backtick doubling — mysql-standard). */
	private function ident( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Value escaping — NULL aware, binary-safe.
	 *
	 * mysqli_real_escape_string μέσω του handle του wpdb (σωστό charset
	 * connection — με αναθεωρημένη SET NAMES utf8mb4 στο header του
	 * dump). Fallback σε addslashes ΜΟΝΟ σε παμπάλαιο non-mysqli setups
	 * (de facto extinct στην πράξη, WP 6+ πάντα mysqli).
	 */
	private function esc( $value ): string {

		global $wpdb;

		if ( null === $value ) {
			return 'NULL';
		}
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			return (string) $value;
		}

		$value = (string) $value;

		if ( $wpdb->use_mysqli && $wpdb->dbh instanceof mysqli ) {
			return "'" . mysqli_real_escape_string( $wpdb->dbh, $value ) . "'";
		}

		return "'" . addslashes( $value ) . "'"; // @phpstan-ignore-line
	}

	/** Κουoted identifier list (helper για ORDER BY / WHERE pk). */
	private function quoting( array $cols ): array {
		return array_map( array( $this, 'ident' ), $cols );
	}

	/* =====================================================================
	 * Raw writes
	 * =================================================================== */

	/** write + tracking bytes — η μοναδική έξοδος dump data. */
	private function write_staged( string $sql ): void {

		if ( '' === $sql ) {
			return;
		}

		if ( null === $this->fh ) {
			throw new RuntimeException( 'NM_Export_Engine: staging file not open.' );
		}

		$done = @fwrite( $this->fh, $sql );

		if ( false === $done || $done !== strlen( $sql ) ) {
			throw new RuntimeException( 'NM_Export_Engine: short write to staging — disk full?' );
		}

		$this->bytes += strlen( $sql );
	}

	private function write_sql_header(): void {

		$header  = "-- Noxpress Migrator — MySQL dump\n";
		$header .= "-- Site: " . parse_url( home_url(), PHP_URL_HOST ) . "\n";
		$header .= '-- Generated: ' . gmdate( 'c' ) . "\n";
		$header .= "-- MySQL-compatible plain SQL. Execute as a whole or\n";
		$header .= "-- in ordered chunks (statements are batch-terminated).\n\n";
		$header .= "SET NAMES utf8mb4;\n";
		$header .= "SET SESSION foreign_key_checks = 0;\n";
		$header .= "SET SESSION unique_checks = 0;\n";
		$header .= "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n";
		$header .= "SET SESSION autocommit = 0;\n";

		$this->write_staged( $header );
	}

	private function write_sql_footer(): void {

		$this->write_staged( "\n\nCOMMIT;\n" );
		$this->write_staged( "SET SESSION foreign_key_checks = 1;\n" );
		$this->write_staged( "SET SESSION unique_checks = 1;\n" );
		$this->write_staged( "SET SESSION autocommit = 1;\n" );
		$this->write_staged( "\n-- Dump complete.\n" );
	}

	/* =====================================================================
	 * Accessors (μετα- αναφορά state για admin UI / CLI / packaging)
	 * =================================================================== */

	/** Το staging dump.sql ως PACKAGED-READY path (για το files engine). */
	public function dump_path(): string {
		return self::staging_sql_path();
	}

	public function phase(): string {
		return ( 'complete' === $this->status ) ? 'done' : 'db';
	}

	public function is_complete(): bool {
		return 'complete' === $this->status;
	}

	/** Abort: κατάσταση FAILED — staging ΔΙΑΤΗΡΕΙΤΑΙ για debugging. */
	public function abort(): void {
		$this->status = 'failed';
		// Ρητό phase marker ώστε το resume να δει FAILED (βλ. load_checkpoint).
		$this->ck['phase'] = 'failed';
		$this->save_checkpoint();
	}

	/** Wrap-up (καλείται ΠΡΙΝ το packaging στο Wave 2): cleanup staging. */
	public function finalize(): void {
		delete_option( 'nm_checkpoint' );
		self::clear_staging();
	}
}
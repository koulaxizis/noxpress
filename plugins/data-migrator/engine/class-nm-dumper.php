<?php
/**
 * NM_Dumper — database dump in resumable steps, without mysqldump.
 *
 * Output: numbered chunk files db/00001.sql … (about 4 MB each), one SQL
 * statement per line, so the importer can stop and continue at any line:
 *  - CREATE TABLE `t` (…)  newlines outside quotes become spaces, inside
 *    quotes "\n"; FOREIGN KEY constraints are left out and recorded in
 *    the state (re-added after the restore swaps tables in).
 *  - INSERT INTO `t` (`a`,`b`) VALUES (…),(…);  at most ~900 KB each.
 * Values: NULL, plain numbers (BIT read as a number), 0x… for binary / blob / spatial
 * columns, everything else quoted with backslash escapes. Generated
 * columns are left out (the server computes them again).
 *
 * Rows are read in key order (primary key, or a unique NOT NULL key) and
 * the next batch starts after the last key written, so a request that
 * dies costs at most the current batch. Tables without such a key use
 * LIMIT/OFFSET.
 */

defined( 'ABSPATH' ) || defined( 'NM_STANDALONE' ) || exit;

final class NM_Dumper {

	const CHUNK_BYTES = 4194304;
	const STMT_BYTES  = 921600;

	/** @var NM_DB */
	private $db;
	/** @var array */
	public $st;
	/** @var string */
	private $dir;
	/** @var resource|null */
	private $fh = null;
	/** @var callable|null Called with the state now and then. */
	private $save;

	public function __construct( NM_DB $db, array $state, string $dir, ?callable $save = null ) {
		$this->db   = $db;
		$this->dir  = rtrim( $dir, '/' );
		$this->save = $save;
		$this->st   = array_merge(
			array(
				'tables'  => array(),
				'ti'      => 0,
				'phase'   => 'create',
				'key'     => null,
				'offset'  => 0,
				'chunk'   => 1,
				'bytes'   => 0,
				'rows'    => array(),
				'info'    => array(),
				'maxstmt' => 0,
				'chunks'  => array(),
				'error'   => '',
			),
			$state
		);
	}

	/* ---------------------------------------------------------------
	 * Table discovery
	 * ------------------------------------------------------------- */

	/**
	 * Tables of the database: name => [type, rows, size, engine].
	 * Our own temporary and snapshot tables are never listed.
	 */
	public static function list_tables( NM_DB $db ): array {
		$out  = array();
		$rows = $db->rows( 'SHOW TABLE STATUS' );
		if ( false === $rows ) {
			return $out;
		}
		foreach ( $rows as $r ) {
			$name = (string) $r['Name'];
			if ( self::ours( $name ) ) {
				continue;
			}
			$is_view      = null === $r['Engine'] && 'VIEW' === strtoupper( (string) $r['Comment'] );
			$out[ $name ] = array(
				'type'   => $is_view ? 'view' : 'table',
				'rows'   => (int) $r['Rows'],
				'size'   => (int) $r['Data_length'] + (int) $r['Index_length'],
				'avg'    => (int) $r['Avg_row_length'],
				'engine' => (string) $r['Engine'],
			);
		}
		return $out;
	}

	/** Our temporary (nmt…_) and snapshot (nms…_) tables. */
	public static function ours( string $name ): bool {
		return (bool) preg_match( '/^nm[tsx][0-9a-f]{6}_/', $name );
	}

	/** Triggers, procedures and functions (reported, not dumped). */
	public static function routines( NM_DB $db ): array {
		$out = array(
			'triggers'   => 0,
			'procedures' => 0,
		);
		$t   = $db->rows( 'SHOW TRIGGERS' );
		if ( is_array( $t ) ) {
			$out['triggers'] = count( $t );
		}
		$p = $db->one( 'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()' );
		if ( null !== $p ) {
			$out['procedures'] = (int) $p;
		}
		return $out;
	}

	/* ---------------------------------------------------------------
	 * Run
	 * ------------------------------------------------------------- */

	/** @return string 'done' | 'more' | 'error' */
	public function run( float $deadline ): string {
		if ( ! $this->open_chunk() ) {
			$this->st['error'] = 'write';
			return 'error';
		}
		$last_save = microtime( true );
		while ( $this->st['ti'] < count( $this->st['tables'] ) ) {
			$table = (string) $this->st['tables'][ $this->st['ti'] ];
			if ( 'create' === $this->st['phase'] ) {
				$info = $this->table_info( $table );
				if ( null === $info ) {
					// Gone since the plan: skip it.
					$this->next_table();
					continue;
				}
				$this->st['info'][ $table ] = $info;
				if ( ! $this->write_line( $info['create'] ) ) {
					return $this->fail( 'write' );
				}
				unset( $this->st['info'][ $table ]['create'] );
				$this->st['rows'][ $table ] = 0;
				$this->st['phase']          = 'rows';
				$this->st['key']            = null;
				$this->st['offset']         = 0;
			}

			$r = $this->dump_batch( $table );
			if ( 'error' === $r ) {
				return 'error';
			}
			if ( 'table-done' === $r ) {
				$this->next_table();
			}
			if ( null !== $this->save && microtime( true ) - $last_save > 2 ) {
				$this->flush();
				call_user_func( $this->save, $this->st );
				$last_save = microtime( true );
			}
			if ( microtime( true ) >= $deadline ) {
				$this->flush();
				return $this->st['ti'] < count( $this->st['tables'] ) ? 'more' : $this->finish();
			}
		}
		return $this->finish();
	}

	private function finish(): string {
		$this->flush();
		if ( $this->fh ) {
			fclose( $this->fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->fh = null;
		}
		$this->note_chunk();
		return 'done';
	}

	private function fail( string $code ): string {
		$this->st['error'] = $code;
		return 'error';
	}

	private function next_table(): void {
		++$this->st['ti'];
		$this->st['phase']  = 'create';
		$this->st['key']    = null;
		$this->st['offset'] = 0;
	}

	/* ---------------------------------------------------------------
	 * Table info
	 * ------------------------------------------------------------- */

	private function table_info( string $table ) {
		$q    = NM_DB::ident( $table );
		$cols = $this->db->rows( 'SHOW FULL COLUMNS FROM ' . $q );
		if ( ! $cols ) {
			return null;
		}
		$create = $this->db->rows( 'SHOW CREATE TABLE ' . $q );
		if ( ! $create || ! isset( $create[0]['Create Table'] ) ) {
			return null;
		}
		$sql = (string) $create[0]['Create Table'];

		$list   = array();
		$kinds  = array();
		$notnul = array();
		foreach ( $cols as $c ) {
			$extra = strtoupper( (string) $c['Extra'] );
			if ( false !== strpos( $extra, 'GENERATED' ) ) {
				continue;
			}
			$name            = (string) $c['Field'];
			$list[]          = $name;
			$kinds[]         = self::kind( (string) $c['Type'] );
			$notnul[ $name ] = 'NO' === $c['Null'];
		}

		// Key for keyset paging: PRIMARY, else a unique key on NOT NULL columns.
		$keys = $this->db->rows( 'SHOW KEYS FROM ' . $q );
		$by   = array();
		foreach ( (array) $keys as $k ) {
			if ( (int) $k['Non_unique'] ) {
				continue;
			}
			$by[ (string) $k['Key_name'] ][ (int) $k['Seq_in_index'] ] = array( (string) $k['Column_name'], null !== $k['Sub_part'] );
		}
		$key = array();
		if ( isset( $by['PRIMARY'] ) ) {
			$by = array( 'PRIMARY' => $by['PRIMARY'] ) + $by;
		}
		foreach ( $by as $parts ) {
			ksort( $parts );
			$ok   = true;
			$cand = array();
			foreach ( $parts as $p ) {
				if ( $p[1] || empty( $notnul[ $p[0] ] ) || ! in_array( $p[0], $list, true ) ) {
					$ok = false;
					break;
				}
				$cand[] = $p[0];
			}
			if ( $ok && $cand ) {
				$key = $cand;
				break;
			}
		}

		// Foreign keys out of the CREATE statement.
		$fks   = array();
		$lines = explode( "\n", $sql );
		$keep  = array();
		foreach ( $lines as $line ) {
			if ( preg_match( '/^\s*CONSTRAINT\s+`(?:[^`]|``)+`\s+FOREIGN KEY\s/i', $line ) ) {
				$fks[] = rtrim( trim( $line ), ',' );
				continue;
			}
			$keep[] = $line;
		}
		if ( $fks ) {
			// The line before ") ENGINE=…" must not end with a comma.
			for ( $i = count( $keep ) - 1; $i > 0; $i-- ) {
				if ( 0 === strpos( ltrim( $keep[ $i ] ), ')' ) ) {
					$keep[ $i - 1 ] = rtrim( $keep[ $i - 1 ], ',' );
					break;
				}
			}
		}
		$sql = self::one_line( implode( "\n", $keep ) );

		$status = $this->db->rows( 'SHOW TABLE STATUS LIKE ' . "'" . $this->db->esc( addcslashes( $table, '\\%_' ) ) . "'" );
		$avg    = $status && isset( $status[0]['Avg_row_length'] ) ? (int) $status[0]['Avg_row_length'] : 0;

		return array(
			'create' => $sql,
			'cols'   => $list,
			'kinds'  => $kinds,
			'key'    => $key,
			'fks'    => $fks,
			'batch'  => max( 20, min( 5000, (int) ( 2097152 / max( 1, $avg ) ) ) ),
		);
	}

	/** 'n' number, 'b' binary (hex), 'bit' (read as a number), 's' string. */
	public static function kind( string $type ): string {
		$t = strtolower( $type );
		if ( preg_match( '/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real|year)\b/', $t ) ) {
			return 'n';
		}
		if ( preg_match( '/^bit\b/', $t ) ) {
			return 'bit';
		}
		if ( preg_match( '/^(binary|varbinary|tinyblob|blob|mediumblob|longblob|geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection|geomcollection)\b/', $t ) ) {
			return 'b';
		}
		return 's';
	}

	/** A CREATE statement on one line, string contents kept exact. */
	public static function one_line( string $sql ): string {
		$out   = '';
		$quote = '';
		$len   = strlen( $sql );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $sql[ $i ];
			if ( '' !== $quote ) {
				if ( '\\' === $c && '`' !== $quote && $i + 1 < $len ) {
					$out .= $c . $sql[ ++$i ];
					continue;
				}
				if ( "\n" === $c ) {
					$out .= '`' === $quote ? ' ' : '\\n';
					continue;
				}
				if ( "\r" === $c ) {
					$out .= '`' === $quote ? '' : '\\r';
					continue;
				}
				if ( $c === $quote ) {
					$quote = '';
				}
				$out .= $c;
				continue;
			}
			if ( "'" === $c || '"' === $c || '`' === $c ) {
				$quote = $c;
				$out  .= $c;
				continue;
			}
			if ( "\n" === $c || "\r" === $c ) {
				$out .= ' ';
				continue;
			}
			$out .= $c;
		}
		return preg_replace( '/ {2,}/', ' ', $out );
	}

	/* ---------------------------------------------------------------
	 * Rows
	 * ------------------------------------------------------------- */

	/** One SQL value. */
	public static function value( $v, string $kind ): string {
		if ( null === $v ) {
			return 'NULL';
		}
		$v = (string) $v;
		if ( ( 'n' === $kind || 'bit' === $kind ) && preg_match( '/^-?[0-9]+(\.[0-9]+)?([eE][-+]?[0-9]+)?$/', $v ) ) {
			return $v;
		}
		if ( 'b' === $kind ) {
			return '' === $v ? "''" : '0x' . bin2hex( $v );
		}
		return "'" . strtr(
			$v,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				"\x1a" => '\\Z',
			)
		) . "'";
	}

	/** @return string 'more' | 'table-done' | 'error' */
	private function dump_batch( string $table ): string {
		$info = $this->st['info'][ $table ];
		$cols = $info['cols'];
		$sel  = implode( ',', array_map( array( 'NM_DB', 'ident' ), $cols ) );
		$exp  = array();
		foreach ( $cols as $i => $c ) {
			// BIT comes back as raw bytes or digits depending on the server: read a number.
			$exp[] = 'bit' === $info['kinds'][ $i ] ? NM_DB::ident( $c ) . '+0' : NM_DB::ident( $c );
		}
		$sql = 'SELECT ' . implode( ',', $exp ) . ' FROM ' . NM_DB::ident( $table );
		$n    = (int) $info['batch'];
		if ( $info['key'] ) {
			$kq = array_map( array( 'NM_DB', 'ident' ), $info['key'] );
			if ( null !== $this->st['key'] ) {
				$vals = array();
				foreach ( $info['key'] as $i => $k ) {
					$idx    = array_search( $k, $cols, true );
					$vals[] = self::value( $this->st['key'][ $i ], $info['kinds'][ $idx ] );
				}
				$sql .= count( $kq ) > 1
					? ' WHERE (' . implode( ',', $kq ) . ') > (' . implode( ',', $vals ) . ')'
					: ' WHERE ' . $kq[0] . ' > ' . $vals[0];
			}
			$sql .= ' ORDER BY ' . implode( ',', $kq ) . ' LIMIT ' . $n;
		} else {
			$sql .= ' LIMIT ' . (int) $this->st['offset'] . ',' . $n;
		}

		$res = $this->db->query( $sql );
		if ( ! ( $res instanceof mysqli_result ) ) {
			$this->st['error'] = 'select:' . $table . ':' . $this->db->error;
			return 'error';
		}
		$head  = 'INSERT INTO ' . NM_DB::ident( $table ) . ' (' . $sel . ') VALUES ';
		$stmt  = '';
		$count = 0;
		$last  = null;
		while ( $row = $res->fetch_row() ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$vals = array();
			foreach ( $row as $i => $v ) {
				$vals[] = self::value( $v, $info['kinds'][ $i ] );
			}
			$tuple = '(' . implode( ',', $vals ) . ')';
			if ( '' !== $stmt && strlen( $stmt ) + strlen( $tuple ) + 2 > self::STMT_BYTES ) {
				if ( ! $this->write_line( $stmt . ';' ) ) {
					$res->free();
					$this->st['error'] = 'write';
					return 'error';
				}
				$stmt = '';
			}
			$stmt .= '' === $stmt ? $head . $tuple : ',' . $tuple;
			++$count;
			$last = $row;
		}
		$res->free();
		if ( '' !== $stmt && ! $this->write_line( $stmt . ';' ) ) {
			$this->st['error'] = 'write';
			return 'error';
		}
		$this->st['rows'][ $table ] = (int) $this->st['rows'][ $table ] + $count;
		if ( $info['key'] && $last ) {
			$k = array();
			foreach ( $info['key'] as $kc ) {
				$k[] = $last[ array_search( $kc, $cols, true ) ];
			}
			$this->st['key'] = $k;
		}
		$this->st['offset'] = (int) $this->st['offset'] + $count;
		return $count < $n ? 'table-done' : 'more';
	}

	/* ---------------------------------------------------------------
	 * Chunk files
	 * ------------------------------------------------------------- */

	public function chunk_path( int $n ): string {
		return $this->dir . '/' . sprintf( '%05d', $n ) . '.sql';
	}

	private function open_chunk(): bool {
		if ( $this->fh ) {
			return true;
		}
		$this->fh = @fopen( $this->chunk_path( (int) $this->st['chunk'] ), 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $this->fh ) {
			return false;
		}
		ftruncate( $this->fh, (int) $this->st['bytes'] );
		fseek( $this->fh, 0, SEEK_END );
		return true;
	}

	private function write_line( string $line ): bool {
		if ( (int) $this->st['bytes'] > 0 && (int) $this->st['bytes'] + strlen( $line ) > self::CHUNK_BYTES ) {
			fclose( $this->fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->fh = null;
			$this->note_chunk();
			$this->st['chunk'] = (int) $this->st['chunk'] + 1;
			$this->st['bytes'] = 0;
			if ( ! $this->open_chunk() ) {
				return false;
			}
		}
		$data = $line . "\n";
		if ( fwrite( $this->fh, $data ) !== strlen( $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			return false;
		}
		$this->st['bytes']   = (int) $this->st['bytes'] + strlen( $data );
		$this->st['maxstmt'] = max( (int) $this->st['maxstmt'], strlen( $line ) );
		return true;
	}

	/** Remember a finished chunk number. */
	private function note_chunk(): void {
		$n = (int) $this->st['chunk'];
		if ( (int) $this->st['bytes'] > 0 && ! in_array( $n, $this->st['chunks'], true ) ) {
			$this->st['chunks'][] = $n;
		}
	}

	private function flush(): void {
		if ( $this->fh ) {
			fflush( $this->fh );
		}
	}
}

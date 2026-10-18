<?php
/**
 * NM_Importer — loads the dump into temporary tables, in steps.
 *
 * Every table is created as nmt<tag>_<final name>; the live tables are
 * not touched until NM_Restorer swaps them in with one RENAME TABLE.
 * Final names take the target's table prefix. While each INSERT line is
 * loaded, string values go through NM_Replacer (old URLs and paths →
 * new), except posts.guid, and prefix-dependent keys are renamed
 * ({prefix}user_roles, usermeta keys that start with the prefix).
 *
 * INSERT becomes REPLACE, so a line that runs twice after a request died
 * halfway does not fail on a duplicate key.
 *
 * scan mode (dry run) parses the same lines and only counts what would
 * change; nothing is written to the database.
 */

defined( 'ABSPATH' ) || defined( 'NM_STANDALONE' ) || exit;

final class NM_Importer {

	/** @var NM_DB|null */
	private $db;
	/** @var NM_Replacer */
	private $rep;
	/** @var array */
	public $st;
	/** @var callable|null */
	private $save;

	public function __construct( ?NM_DB $db, NM_Replacer $rep, array $state, ?callable $save = null ) {
		$this->db   = $db;
		$this->rep  = $rep;
		$this->save = $save;
		$this->st   = array_merge(
			array(
				'chunks'    => array(),
				'ci'        => 0,
				'pos'       => 0,
				'src'       => 'wp_',
				'dst'       => 'wp_',
				'tag'       => '000000',
				'only'      => null,
				'temp'      => array(),
				'rows'      => array(),
				'repl'      => 0,
				'local'     => array(),
				'maxstmt'   => 0,
				'scan'      => false,
				'error'     => '',
				'collation' => array(),
			),
			$state
		);
	}

	/** Final table name: the source prefix becomes the target's. */
	public static function final_name( string $name, string $src, string $dst ): string {
		if ( $src !== $dst && 0 === strpos( $name, $src ) ) {
			return $dst . substr( $name, strlen( $src ) );
		}
		return $name;
	}

	/** nmt<tag>_<name>, or a hashed name when that would pass 64 chars. */
	public static function temp_name( string $final, string $tag, string $kind = 't' ): string {
		$t = 'nm' . $kind . $tag . '_' . $final;
		if ( strlen( $t ) > 64 ) {
			$t = 'nm' . $kind . $tag . '_' . substr( md5( $final ), 0, 24 );
		}
		return $t;
	}

	/** @return string 'done' | 'more' | 'error' */
	public function run( float $deadline ): string {
		$last_save = microtime( true );
		while ( $this->st['ci'] < count( $this->st['chunks'] ) ) {
			$file = (string) $this->st['chunks'][ $this->st['ci'] ];
			$fh   = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $fh ) {
				$this->st['error'] = 'read:' . basename( $file );
				return 'error';
			}
			fseek( $fh, (int) $this->st['pos'] );
			while ( true ) {
				$line = fgets( $fh );
				if ( false === $line ) {
					break;
				}
				$next = ftell( $fh );
				$line = rtrim( $line, "\r\n" );
				if ( '' !== $line && ! $this->line( $line ) ) {
					fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return 'error';
				}
				$this->st['pos'] = $next;
				if ( null !== $this->save && microtime( true ) - $last_save > 1 ) {
					call_user_func( $this->save, $this->st );
					$last_save = microtime( true );
				}
				if ( microtime( true ) >= $deadline ) {
					fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return 'more';
				}
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			++$this->st['ci'];
			$this->st['pos'] = 0;
		}
		return 'done';
	}

	/** One statement line. */
	private function line( string $line ): bool {
		$this->st['maxstmt'] = max( (int) $this->st['maxstmt'], strlen( $line ) );
		if ( preg_match( '/^CREATE TABLE `((?:[^`]|``)+)`/', $line, $m ) ) {
			return $this->create( str_replace( '``', '`', $m[1] ), $line, strlen( $m[0] ) );
		}
		if ( preg_match( '/^INSERT INTO `((?:[^`]|``)+)` \(([^)]*)\) VALUES /', $line, $m ) ) {
			return $this->insert( str_replace( '``', '`', $m[1] ), $m[2], substr( $line, strlen( $m[0] ) ) );
		}
		// Anything else in a dump of ours is unexpected: skip it.
		return true;
	}

	private function wanted( string $src_table ): bool {
		return null === $this->st['only'] || in_array( $src_table, (array) $this->st['only'], true );
	}

	private function create( string $src_table, string $line, int $head_len ): bool {
		if ( ! $this->wanted( $src_table ) ) {
			return true;
		}
		$final = self::final_name( $src_table, (string) $this->st['src'], (string) $this->st['dst'] );
		$temp  = self::temp_name( $final, (string) $this->st['tag'] );

		$this->st['temp'][ $src_table ] = array(
			'final' => $final,
			'temp'  => $temp,
		);
		$this->st['rows'][ $src_table ] = 0;
		if ( $this->st['scan'] ) {
			return true;
		}
		$sql = 'CREATE TABLE ' . NM_DB::ident( $temp ) . substr( $line, $head_len );
		$this->db->query( 'DROP TABLE IF EXISTS ' . NM_DB::ident( $temp ) );
		if ( $this->db->query( $sql ) ) {
			return true;
		}
		// Collations or table options the target server does not know.
		$tries = array(
			array( '/utf8mb4_(0900|uca1400)_[a-z0-9_]+/i', 'utf8mb4_unicode_520_ci' ),
			array( '/utf8mb4_unicode_520_ci/i', 'utf8mb4_unicode_ci' ),
			array( '/\s(PAGE_CHECKSUM|TRANSACTIONAL)=\d+/i', '' ),
			array( '/utf8mb4/i', 'utf8' ),
		);
		$errno = $this->db->errno;
		$error = $this->db->error;
		foreach ( $tries as $t ) {
			$sql2 = preg_replace( $t[0], $t[1], $sql );
			if ( $sql2 === $sql ) {
				continue;
			}
			$sql = $sql2;
			if ( $this->db->query( $sql ) ) {
				$this->st['collation'][] = $final;
				return true;
			}
		}
		$this->st['error'] = 'create:' . $final . ':' . $errno . ' ' . $error;
		return false;
	}

	private function insert( string $src_table, string $cols_sql, string $values ): bool {
		if ( ! $this->wanted( $src_table ) || ! isset( $this->st['temp'][ $src_table ] ) ) {
			return true;
		}
		$t     = $this->st['temp'][ $src_table ];
		$cols  = array();
		$parts = explode( ',', $cols_sql );
		foreach ( $parts as $c ) {
			$cols[] = str_replace( '``', '`', trim( trim( $c ), '`' ) );
		}
		$base = substr( $src_table, strlen( (string) $this->st['src'] ) );
		$is_prefixed = 0 === strpos( $src_table, (string) $this->st['src'] );

		// Columns with special handling.
		$skip   = -1;
		$rename = -1;
		$roles  = -1;
		if ( $is_prefixed && 'posts' === $base ) {
			$skip = self::col( $cols, 'guid' );
		}
		if ( $this->st['src'] !== $this->st['dst'] && $is_prefixed ) {
			if ( 'usermeta' === $base ) {
				$rename = self::col( $cols, 'meta_key' );
			} elseif ( 'options' === $base ) {
				$roles = self::col( $cols, 'option_name' );
			}
		}

		$need_walk = $this->rep->active() || $rename >= 0 || $roles >= 0 || $this->st['scan'];
		$rows      = 0;
		if ( $need_walk ) {
			$values = $this->walk_values( $values, $skip, $rename, $roles, $rows );
			if ( null === $values ) {
				$this->st['error'] = 'parse:' . $t['final'];
				return false;
			}
		} else {
			$rows = substr_count( $values, '),(' ) + 1;
		}
		$this->st['rows'][ $src_table ] = (int) $this->st['rows'][ $src_table ] + $rows;
		if ( $this->st['scan'] ) {
			return true;
		}
		$sql = 'REPLACE INTO ' . NM_DB::ident( $t['temp'] ) . ' (' . $cols_sql . ') VALUES ' . $values;
		if ( ! $this->db->query( $sql ) ) {
			$this->st['error'] = 'insert:' . $t['final'] . ':' . $this->db->errno . ' ' . $this->db->error;
			return false;
		}
		return true;
	}

	/** Index of a column, or -1. */
	private static function col( array $cols, string $name ): int {
		$k = array_search( $name, $cols, true );
		return false === $k ? -1 : (int) $k;
	}

	private static function unescape( string $s ): string {
		return strtr(
			$s,
			array(
				'\\\\' => '\\',
				'\\0'  => "\0",
				'\\n'  => "\n",
				'\\r'  => "\r",
				"\\'"  => "'",
				'\\"'  => '"',
				'\\Z'  => "\x1a",
			)
		);
	}

	/**
	 * Walk "(…),(…);" and rewrite string values. Returns the new text,
	 * or null when the line is not in our dump format.
	 */
	private function walk_values( string $v, int $skip, int $rename, int $roles, int &$rows ) {
		$len  = strlen( $v );
		$out  = '';
		$i    = 0;
		$col  = 0;
		$src  = (string) $this->st['src'];
		$dst  = (string) $this->st['dst'];
		$scan = (bool) $this->st['scan'];
		while ( $i < $len ) {
			$c = $v[ $i ];
			if ( "'" === $c ) {
				// Find the closing quote (backslash escapes).
				$j = $i + 1;
				while ( true ) {
					$q = strpos( $v, "'", $j );
					if ( false === $q ) {
						return null;
					}
					// Count backslashes just before the quote.
					$b = 0;
					for ( $k = $q - 1; $k > $i && '\\' === $v[ $k ]; $k-- ) {
						++$b;
					}
					if ( 0 === $b % 2 ) {
						break;
					}
					$j = $q + 1;
				}
				$raw = substr( $v, $i + 1, $q - $i - 1 );
				$new = null;
				if ( $col === $rename && '' !== $src && 0 === strpos( self::unescape( $raw ), $src ) ) {
					$new = $dst . substr( self::unescape( $raw ), strlen( $src ) );
				} elseif ( $col === $roles && self::unescape( $raw ) === $src . 'user_roles' ) {
					$new = $dst . 'user_roles';
				} elseif ( $col !== $skip && $this->rep->may_match( $raw ) ) {
					$plain = self::unescape( $raw );
					if ( $scan ) {
						NM_Replacer::find_local( $plain, $this->st['local'] );
					}
					$before = $this->rep->count;
					$rep    = $this->rep->replace( $plain );
					if ( $this->rep->count !== $before ) {
						$this->st['repl'] = (int) $this->st['repl'] + ( $this->rep->count - $before );
						$new              = $rep;
					}
				} elseif ( $scan && ( false !== strpos( $raw, '://localhost' ) || false !== strpos( $raw, '://127.0.0.1' ) || false !== strpos( $raw, ':\\\\/\\\\/localhost' ) ) ) {
					NM_Replacer::find_local( self::unescape( $raw ), $this->st['local'] );
				}
				$out .= null === $new ? substr( $v, $i, $q - $i + 1 ) : NM_Dumper::value( $new, 's' );
				$i    = $q + 1;
				continue;
			}
			if ( ',' === $c ) {
				++$col;
			} elseif ( '(' === $c ) {
				$col = 0;
			} elseif ( ')' === $c ) {
				++$rows;
			}
			// Unquoted tokens (NULL, numbers, 0x…) have no quotes or parens.
			$n = strcspn( $v, "',()", $i + 1 );
			$out .= substr( $v, $i, $n + 1 );
			$i   += $n + 1;
		}
		return $out;
	}
}

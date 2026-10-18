<?php
/**
 * NM_Restorer — the restore / migration pipeline, in resumable steps.
 *
 * Runs in runner.php (existing site), in the installer (empty server)
 * and in WP-CLI. Nothing on the live site changes before the commit
 * steps, and everything the commit changes can be undone:
 *
 *  extract   archive entries → stage folder (CRC checked), SQL chunks →
 *            work/db. Our own plugin folder, wp-config.php and
 *            .maintenance are never taken from an archive.
 *  import    SQL → temporary tables nmt<tag>_…, with URL / path
 *            replacement and the target's table prefix.
 *  fixup     temporary options: home / siteurl, blog_public, our own
 *            settings carried over from the target, our plugin kept
 *            active, transients and rewrite rules dropped, Action
 *            Scheduler claims released.
 *  maint     .maintenance on.
 *  files     each staged file moves into place; the file it replaces
 *            moves to the rollback folder first (journal line written
 *            before either move).
 *  tables    one RENAME TABLE: live → snapshot nms<tag>_…, temporary →
 *            live. Foreign keys are added back afterwards.
 *  post      salts (optional), opcache, object cache, .maintenance off.
 *
 * A failure during files or tables switches the job to rollback, which
 * replays the journal backwards and renames the snapshots back.
 */

defined( 'ABSPATH' ) || defined( 'NM_STANDALONE' ) || exit;

final class NM_Restorer {

	const STEPS = array( 'extract', 'import', 'fixup', 'maint', 'files', 'tables', 'post', 'done' );

	/** @var array */
	public $job;
	/** @var NM_DB|null */
	private $db;
	/** @var callable */
	private $save;
	/** @var float */
	private $deadline = 0.0;

	/**
	 * @param array    $job  The job (see NM_Restore_Prep / installer).
	 * @param NM_DB    $db   Connected to the target database.
	 * @param callable $save Called with the job to persist it.
	 */
	public function __construct( array $job, ?NM_DB $db, callable $save ) {
		$this->job  = $job;
		$this->db   = $db;
		$this->save = $save;
	}

	private function persist(): void {
		call_user_func( $this->save, $this->job );
	}

	/** Run steps until the deadline. @return string 'more' | 'done' | 'failed' | 'rolledback' */
	public function run( float $deadline ): string {
		$this->deadline = $deadline;
		if ( 'rollback' === ( $this->job['mode'] ?? '' ) ) {
			return $this->rollback();
		}
		while ( microtime( true ) < $deadline ) {
			$step = (string) $this->job['step'];
			if ( 'done' === $step ) {
				$this->job['status'] = 'done';
				$this->persist();
				return 'done';
			}
			NM_Util::log( 'step ' . $step );
			switch ( $step ) {
				case 'extract':
					$r = $this->step_extract();
					break;
				case 'import':
					$r = $this->step_import();
					break;
				case 'fixup':
					$r = $this->step_fixup();
					break;
				case 'maint':
					$r = $this->maintenance( true ) ? 'next' : $this->fail( NM_Util::t( 'Δεν γράφτηκε το αρχείο .maintenance στη ρίζα του site.' ) );
					break;
				case 'files':
					$r = $this->step_files();
					break;
				case 'tables':
					$r = $this->step_tables();
					break;
				case 'post':
					$r = $this->step_post();
					break;
				default:
					$r = $this->fail( 'step' );
			}
			if ( 'error' === $r ) {
				return $this->on_error();
			}
			if ( 'next' === $r ) {
				$i                 = array_search( $step, self::STEPS, true );
				$this->job['step'] = self::STEPS[ $i + 1 ];
				$this->job['ss']   = array();
			}
			$this->persist();
		}
		return 'more';
	}

	/** Record an error; the caller decides between failed and rollback. */
	private function fail( string $msg ): string {
		$this->job['error'] = $msg;
		NM_Util::log( $msg, true );
		return 'error';
	}

	private function on_error(): string {
		$step = (string) $this->job['step'];
		if ( in_array( $step, array( 'files', 'tables' ), true ) ) {
			// The live site is half changed: undo at once.
			$this->job['mode']   = 'rollback';
			$this->job['rb']     = array();
			$this->job['status'] = 'rolling-back';
			$this->persist();
			return 'more';
		}
		$this->maintenance( false );
		$this->job['status'] = 'failed';
		$this->persist();
		return 'failed';
	}

	/* ---------------------------------------------------------------
	 * Paths
	 * ------------------------------------------------------------- */

	/** Target folder of a file group. */
	private function group_dir( string $g ): string {
		$t = $this->job['target']['dirs'];
		return isset( $t[ $g ] ) ? rtrim( (string) $t[ $g ], '/' ) : '';
	}

	/** Should this archive file be restored? */
	public static function wanted( array $job, string $group, string $rel ): bool {
		$o = $job['options'];
		if ( ! in_array( $group, (array) $o['groups'], true ) ) {
			return false;
		}
		$base = basename( $rel );
		if ( 'wp-config.php' === $base && ( 'core' === $group || 'root' === $group ) ) {
			return false;
		}
		if ( '.maintenance' === $rel && ( 'core' === $group || 'root' === $group ) ) {
			return false;
		}
		if ( 'plugins' === $group && '' !== (string) $job['target']['own'] ) {
			$own = (string) $job['target']['own'];
			if ( $rel === $own || 0 === strpos( $rel, $own . '/' ) ) {
				return false;
			}
		}
		if ( 'root' === $group ) {
			// Root files: only the ones ticked (unknown files never by default).
			if ( false === strpos( $rel, '/' ) ) {
				return in_array( $rel, (array) $o['root_files'], true );
			}
			$top = strtok( $rel, '/' );
			return in_array( $top . '/', (array) $o['root_files'], true );
		}
		return true;
	}

	/* ---------------------------------------------------------------
	 * extract
	 * ------------------------------------------------------------- */

	private function step_extract(): string {
		$ss    = $this->job['ss'] + array(
			'part'  => 0,
			'pos'   => 0,
			'done'  => 0,
			'files' => 0,
			'bytes' => 0,
		);
		$parts = (array) $this->job['archive']['parts'];
		$stage = (string) $this->job['work']['stage'];
		$dbdir = (string) $this->job['work']['db'];
		$list  = (string) $this->job['work']['list'];
		$want_db = ! empty( $this->job['options']['db'] );

		while ( $ss['part'] < count( $parts ) ) {
			$zr = new NM_Zip_Reader( (string) $parts[ $ss['part'] ] );
			if ( ! $zr->open() ) {
				return $this->fail( NM_Util::tf( 'Το αρχείο %s δεν διαβάζεται ως ZIP.', basename( (string) $parts[ $ss['part'] ] ) ) );
			}
			$lh = @fopen( $list, 'ab' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$e  = $zr->entry_at( (int) $ss['pos'], $next );
			while ( $e ) {
				$name = $e['name'];
				$dest = '';
				if ( 0 === strpos( $name, 'db/' ) && preg_match( '#^db/\d{5}\.sql$#', $name ) ) {
					$dest = $want_db ? $dbdir . '/' . substr( $name, 3 ) : '';
				} elseif ( preg_match( '#^files/([a-z-]+)/(.+)$#', $name, $m ) && '/' !== substr( $name, -1 ) ) {
					if ( NM_Util::safe_rel( $m[2] ) && self::wanted( $this->job, $m[1], $m[2] ) ) {
						$dest = $stage . '/' . $m[1] . '/' . $m[2];
					}
				}
				if ( '' !== $dest ) {
					if ( ! NM_Util::mkdirp( dirname( $dest ) ) ) {
						$zr->close();
						return $this->fail( NM_Util::tf( 'Δεν δημιουργήθηκε ο φάκελος %s.', dirname( $dest ) ) );
					}
					$done = (int) $ss['done'];
					$r    = $zr->extract( $e, $dest, $done, $this->deadline );
					if ( 'paused' === $r ) {
						$ss['done']      = $done;
						$this->job['ss'] = $ss;
						$zr->close();
						if ( $lh ) {
							fclose( $lh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						}
						return 'more';
					}
					if ( 'done' !== $r ) {
						$zr->close();
						return $this->fail( NM_Util::tf( 'Το %1$s δεν βγήκε σωστά από το αρχείο (%2$s).', $name, $r ) );
					}
					if ( isset( $m[1] ) && 0 === strpos( $name, 'files/' ) && $lh ) {
						fwrite( $lh, $m[1] . "\t" . $m[2] . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
						++$ss['files'];
					}
					$ss['bytes'] += $e['usize'];
				}
				$m          = array();
				$ss['pos']  = (int) $next;
				$ss['done'] = 0;
				if ( microtime( true ) >= $this->deadline ) {
					$this->job['ss'] = $ss;
					$zr->close();
					if ( $lh ) {
						fclose( $lh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					}
					return 'more';
				}
				$e = $zr->entry_at( (int) $ss['pos'], $next );
			}
			if ( $lh ) {
				fclose( $lh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			$zr->close();
			++$ss['part'];
			$ss['pos'] = 0;
		}
		$this->job['report']['files_staged'] = (int) $ss['files'];
		$this->job['report']['bytes']        = (int) $ss['bytes'];
		return 'next';
	}

	/* ---------------------------------------------------------------
	 * import
	 * ------------------------------------------------------------- */

	public static function replacer( array $job ): NM_Replacer {
		return new NM_Replacer( (array) $job['options']['pairs'] );
	}

	private function step_import(): string {
		if ( empty( $this->job['options']['db'] ) ) {
			return 'next';
		}
		$ss = $this->job['ss'];
		if ( ! $ss ) {
			$chunks = glob( rtrim( (string) $this->job['work']['db'], '/' ) . '/*.sql' );
			sort( $chunks );
			$ss = array(
				'chunks' => $chunks ? $chunks : array(),
				'src'    => (string) $this->job['manifest']['prefix'],
				'dst'    => (string) $this->job['target']['prefix'],
				'tag'    => (string) $this->job['tag'],
				'only'   => $this->job['options']['tables'],
			);
			if ( ! $ss['chunks'] ) {
				return $this->fail( NM_Util::t( 'Το αρχείο δεν έχει βάση δεδομένων.' ) );
			}
		}
		$self = $this;
		$im   = new NM_Importer(
			$this->db,
			self::replacer( $this->job ),
			$ss,
			static function ( $st ) use ( $self ) {
				$self->job['ss'] = $st;
				$self->save_now();
			}
		);
		$this->db->query( 'SET FOREIGN_KEY_CHECKS = 0' );
		$this->db->query( 'SET UNIQUE_CHECKS = 0' );
		$r               = $im->run( $this->deadline );
		$this->job['ss'] = $im->st;
		if ( 'error' === $r ) {
			return $this->fail( NM_Util::tf( 'Η εισαγωγή της βάσης σταμάτησε: %s', (string) $im->st['error'] ) );
		}
		if ( 'done' === $r ) {
			$this->job['tables']              = $im->st['temp'];
			$this->job['report']['rows']      = array_sum( array_map( 'intval', $im->st['rows'] ) );
			$this->job['report']['tables']    = count( $im->st['temp'] );
			$this->job['report']['replaced']  = (int) $im->st['repl'];
			$this->job['report']['collation'] = $im->st['collation'];
			return 'next';
		}
		return 'more';
	}

	/** Persist from inside a callback. */
	public function save_now(): void {
		$this->persist();
	}

	/* ---------------------------------------------------------------
	 * fixup (temporary tables only)
	 * ------------------------------------------------------------- */

	/** Temporary name of a final table, or ''. */
	private function temp_of( string $final ): string {
		foreach ( (array) ( $this->job['tables'] ?? array() ) as $t ) {
			if ( $t['final'] === $final ) {
				return (string) $t['temp'];
			}
		}
		return '';
	}

	private function step_fixup(): string {
		if ( empty( $this->job['options']['db'] ) ) {
			return 'next';
		}
		$p   = (string) $this->job['target']['prefix'];
		$opt = $this->temp_of( $p . 'options' );
		if ( '' !== $opt ) {
			$o   = NM_DB::ident( $opt );
			$db  = $this->db;
			$set = static function ( string $name, string $value, string $autoload = 'yes' ) use ( $db, $o ) {
				$db->query( 'DELETE FROM ' . $o . " WHERE option_name = '" . $db->esc( $name ) . "'" );
				$db->query( 'INSERT INTO ' . $o . " (option_name, option_value, autoload) VALUES ('" . $db->esc( $name ) . "', '" . $db->esc( $value ) . "', '" . $db->esc( $autoload ) . "')" );
			};
			$set( 'siteurl', (string) $this->job['target']['siteurl'] );
			$set( 'home', (string) $this->job['target']['home'] );
			if ( null !== $this->job['options']['blog_public'] ) {
				$set( 'blog_public', (string) (int) $this->job['options']['blog_public'] );
			}

			// Our own settings stay the target's; the source's go.
			$this->db->query( 'DELETE FROM ' . $o . ' WHERE option_name LIKE ' . $this->db->like( 'nm_' ) );
			foreach ( (array) $this->job['target']['keep_options'] as $name => $value ) {
				if ( 0 === strpos( (string) $name, 'nm_' ) ) {
					$set( (string) $name, (string) $value, 'no' );
				}
			}
			$set(
				'nm_post_restore',
				NM_Util::json(
					array(
						'job'  => $this->job['id'],
						'time' => time(),
						'wc'   => true,
					)
				),
				'yes'
			);

			// Our plugin stays active so the report and undo are reachable.
			$own = (string) $this->job['target']['basename'];
			if ( '' !== $own ) {
				$cur = $this->db->one( 'SELECT option_value FROM ' . $o . " WHERE option_name = 'active_plugins'" );
				$arr = is_string( $cur ) ? @unserialize( $cur, array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
				$arr = is_array( $arr ) ? array_values( array_filter( $arr, 'is_string' ) ) : array();
				if ( ! in_array( $own, $arr, true ) ) {
					$arr[] = $own;
					sort( $arr );
					$set( 'active_plugins', serialize( $arr ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				}
			}

			// Caches that belong to the old site.
			$this->db->query(
				'DELETE FROM ' . $o . ' WHERE option_name LIKE ' . $this->db->like( '_transient_' )
				. ' OR option_name LIKE ' . $this->db->like( '_site_transient_' )
				. " OR option_name = 'rewrite_rules'"
			);
		}

		// Action Scheduler: release claims held by the old server.
		$asa = $this->temp_of( $p . 'actionscheduler_actions' );
		if ( '' !== $asa ) {
			$this->db->query( 'UPDATE ' . NM_DB::ident( $asa ) . " SET claim_id = 0 WHERE status = 'pending'" );
			$asc = $this->temp_of( $p . 'actionscheduler_claims' );
			if ( '' !== $asc ) {
				$this->db->query( 'DELETE FROM ' . NM_DB::ident( $asc ) );
			}
			$n = $this->db->one( 'SELECT COUNT(*) FROM ' . NM_DB::ident( $asa ) . " WHERE status = 'pending'" );
			$this->job['report']['as_pending'] = (int) $n;
		}
		return 'next';
	}

	/* ---------------------------------------------------------------
	 * .maintenance
	 * ------------------------------------------------------------- */

	public function maintenance( bool $on ): bool {
		$file = rtrim( (string) $this->job['target']['dirs']['core'], '/' ) . '/.maintenance';
		if ( $on ) {
			if ( ! isset( $this->job['maint_had'] ) ) {
				$this->job['maint_had'] = is_file( $file );
			}
			return false !== @file_put_contents( $file, '<?php $upgrading = ' . time() . '; ?>' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( empty( $this->job['maint_had'] ) && is_file( $file ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		return true;
	}

	/* ---------------------------------------------------------------
	 * files
	 * ------------------------------------------------------------- */

	private function step_files(): string {
		$ss   = $this->job['ss'] + array(
			'pos'   => 0,
			'moved' => 0,
			'skip'  => array(),
		);
		$list = (string) $this->job['work']['list'];
		if ( ! is_file( $list ) ) {
			return 'next';
		}
		$this->maintenance( true );
		$jh = @fopen( (string) $this->job['work']['journal'], 'ab' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$lh = @fopen( $list, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $jh || ! $lh ) {
			return $this->fail( NM_Util::t( 'Δεν ανοίγει η λίστα αρχείων ή το ημερολόγιο αναίρεσης.' ) );
		}
		fseek( $lh, (int) $ss['pos'] );
		$stage = (string) $this->job['work']['stage'];
		$rb    = (string) $this->job['work']['rollback'];
		$last  = microtime( true );
		while ( ( $line = fgets( $lh ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$next = ftell( $lh );
			$line = rtrim( $line, "\n" );
			$tab  = strpos( $line, "\t" );
			if ( false !== $tab ) {
				$g    = substr( $line, 0, $tab );
				$rel  = substr( $line, $tab + 1 );
				$dir  = $this->group_dir( $g );
				$src  = $stage . '/' . $g . '/' . $rel;
				$dest = $dir . '/' . $rel;
				if ( '' === $dir || ! NM_Util::safe_rel( $rel ) || ! is_file( $src ) ) {
					$ss['skip'][] = $g . '/' . $rel;
				} elseif ( is_dir( $dest ) || is_link( $dest ) ) {
					$ss['skip'][] = $g . '/' . $rel;
				} else {
					$had = is_file( $dest );
					// Journal first, then the moves: a crash in between is still undoable.
					fwrite( $jh, ( $had ? '1' : '0' ) . "\t" . $g . "\t" . $rel . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					fflush( $jh );
					if ( $had ) {
						$keep = $rb . '/' . $g . '/' . $rel;
						if ( ! NM_Util::mkdirp( dirname( $keep ) ) || ! NM_Util::move( $dest, $keep ) ) {
							fclose( $lh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
							fclose( $jh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
							return $this->fail( NM_Util::tf( 'Δεν μετακινήθηκε το %s στον φάκελο αναίρεσης.', $dest ) );
						}
					}
					if ( ! NM_Util::mkdirp( dirname( $dest ) ) || ! NM_Util::move( $src, $dest ) ) {
						fclose( $lh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						fclose( $jh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						return $this->fail( NM_Util::tf( 'Δεν γράφτηκε το %s.', $dest ) );
					}
					++$ss['moved'];
				}
			}
			$ss['pos'] = $next;
			if ( microtime( true ) - $last > 1 ) {
				$this->job['ss'] = $ss;
				$this->persist();
				$this->maintenance( true );
				$last = microtime( true );
			}
			if ( microtime( true ) >= $this->deadline ) {
				fclose( $lh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $jh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$this->job['ss'] = $ss;
				return 'more';
			}
		}
		fclose( $lh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $jh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$this->job['report']['files']      = (int) $ss['moved'];
		$this->job['report']['files_skip'] = array_slice( $ss['skip'], 0, 200 );
		self::opcache();
		return 'next';
	}

	public static function opcache(): void {
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/* ---------------------------------------------------------------
	 * tables
	 * ------------------------------------------------------------- */

	private function live_tables(): array {
		$out  = array();
		$rows = $this->db->rows( 'SHOW TABLES' );
		foreach ( (array) $rows as $r ) {
			$out[ (string) reset( $r ) ] = true;
		}
		return $out;
	}

	private function step_tables(): string {
		if ( empty( $this->job['options']['db'] ) || empty( $this->job['tables'] ) ) {
			return 'next';
		}
		$this->maintenance( true );
		$live  = $this->live_tables();
		$tag   = (string) $this->job['tag'];
		$parts = array();
		$snaps = array();
		foreach ( (array) $this->job['tables'] as $t ) {
			if ( ! isset( $live[ $t['temp'] ] ) ) {
				return $this->fail( NM_Util::tf( 'Λείπει ο προσωρινός πίνακας %s.', $t['temp'] ) );
			}
			if ( isset( $live[ $t['final'] ] ) ) {
				$snap               = NM_Importer::temp_name( $t['final'], $tag, 's' );
				$parts[]            = NM_DB::ident( $t['final'] ) . ' TO ' . NM_DB::ident( $snap );
				$snaps[ $t['final'] ] = $snap;
			}
			$parts[] = NM_DB::ident( $t['temp'] ) . ' TO ' . NM_DB::ident( $t['final'] );
		}
		if ( ! $this->db->query( 'RENAME TABLE ' . implode( ', ', $parts ) ) ) {
			return $this->fail( NM_Util::tf( 'Η αλλαγή των πινάκων απέτυχε: %s', $this->db->error ) );
		}
		$this->job['swapped']   = true;
		$this->job['snapshots'] = $snaps;
		$this->persist();

		// Foreign keys, with the referenced tables renamed like the rest.
		$names = array();
		foreach ( (array) $this->job['tables'] as $src => $t ) {
			$names[ (string) $src ] = $t['final'];
		}
		$warn = array();
		foreach ( (array) ( $this->job['manifest']['fks'] ?? array() ) as $src => $list ) {
			if ( ! isset( $names[ $src ] ) ) {
				continue;
			}
			foreach ( (array) $list as $fk ) {
				$fk  = preg_replace_callback(
					'/REFERENCES `((?:[^`]|``)+)`/',
					static function ( $m ) use ( $names ) {
						$n = str_replace( '``', '`', $m[1] );
						return 'REFERENCES ' . NM_DB::ident( $names[ $n ] ?? $n );
					},
					(string) $fk
				);
				$sql = 'ALTER TABLE ' . NM_DB::ident( $names[ $src ] ) . ' ADD ' . $fk;
				if ( ! $this->db->query( $sql ) ) {
					// Name taken (the snapshot keeps it): let the server pick one.
					$anon = preg_replace( '/^CONSTRAINT\s+`(?:[^`]|``)+`\s+/i', '', $fk );
					if ( ! $this->db->query( 'ALTER TABLE ' . NM_DB::ident( $names[ $src ] ) . ' ADD ' . $anon ) ) {
						$warn[] = $names[ $src ] . ': ' . $this->db->error;
					}
				}
			}
		}
		$this->job['report']['fk_warnings'] = $warn;

		// Live tables the archive did not have stay as they are.
		$kept = array();
		$p    = (string) $this->job['target']['prefix'];
		$fin  = array_flip( $names );
		foreach ( array_keys( $live ) as $name ) {
			if ( 0 === strpos( $name, $p ) && ! isset( $fin[ $name ] ) && ! NM_Dumper::ours( $name ) ) {
				$kept[] = $name;
			}
		}
		$this->job['report']['tables_kept'] = $kept;
		return 'next';
	}

	/* ---------------------------------------------------------------
	 * post
	 * ------------------------------------------------------------- */

	private function step_post(): string {
		if ( ! empty( $this->job['options']['salts'] ) ) {
			$this->job['report']['salts'] = self::new_salts( (string) $this->job['target']['config'] );
		}
		self::opcache();
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		$this->maintenance( false );
		$this->cleanup_work( false );
		$this->job['finished'] = time();
		return 'next';
	}

	/**
	 * Fresh salts in wp-config.php. Changes the file only when all eight
	 * define() lines are found exactly once. @return bool
	 */
	public static function new_salts( string $config ): bool {
		if ( '' === $config || ! is_file( $config ) || ! is_writable( $config ) ) {
			return false;
		}
		$src  = (string) file_get_contents( $config ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$keys = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );
		foreach ( $keys as $k ) {
			$re = '/define\(\s*([\'"])' . $k . '\1\s*,\s*([\'"])((?:(?!\2).)*)\2\s*\)\s*;/';
			if ( 1 !== preg_match_all( $re, $src ) ) {
				return false;
			}
			$salt = self::salt();
			$src  = preg_replace_callback(
				$re,
				static function ( $m ) use ( $k, $salt ) {
					return "define( '" . $k . "', '" . $salt . "' );";
				},
				$src
			);
		}
		$perm = fileperms( $config ) & 0777;
		if ( ! NM_Util::write_atomic( $config, $src ) ) {
			return false;
		}
		@chmod( $config, $perm ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return true;
	}

	/** 64 characters for a salt, without quotes or backslashes. */
	public static function salt(): string {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_[]{}<>~`+=,.;:/?|';
		$out   = '';
		for ( $i = 0; $i < 64; $i++ ) {
			$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $out;
	}

	/** Delete the stage and SQL chunks; with $all also the undo data. */
	public function cleanup_work( bool $all ): void {
		$w      = $this->job['work'];
		$guard  = (string) $w['guard'];
		$sguard = (string) ( $w['sguard'] ?? $guard );
		NM_Util::rrmdir( (string) $w['stage'], $sguard );
		NM_Util::rrmdir( (string) $w['db'], $guard );
		if ( $all ) {
			NM_Util::rrmdir( (string) $w['rollback'], $sguard );
			if ( ! empty( $w['stage_root'] ) ) {
				NM_Util::rrmdir( (string) $w['stage_root'], $sguard );
			}
			NM_Util::rrmdir( (string) $w['root'], $guard );
		}
	}

	/* ---------------------------------------------------------------
	 * finalize / rollback
	 * ------------------------------------------------------------- */

	/** Drop snapshots and leftovers, delete the undo folder. */
	public function finalize(): void {
		foreach ( (array) ( $this->job['snapshots'] ?? array() ) as $snap ) {
			if ( NM_Dumper::ours( (string) $snap ) ) {
				$this->db->query( 'DROP TABLE IF EXISTS ' . NM_DB::ident( (string) $snap ) );
			}
		}
		$this->drop_temps();
		$this->cleanup_work( true );
		$this->job['status'] = 'finalized';
		$this->persist();
	}

	/** Drop every temporary table of this job. */
	private function drop_temps(): void {
		$tag  = (string) $this->job['tag'];
		$rows = $this->db->rows( 'SHOW TABLES LIKE ' . $this->db->like( 'nmt' . $tag . '_' ) );
		foreach ( (array) $rows as $r ) {
			$name = (string) reset( $r );
			if ( NM_Dumper::ours( $name ) ) {
				$this->db->query( 'DROP TABLE IF EXISTS ' . NM_DB::ident( $name ) );
			}
		}
	}

	/** Undo: tables first (one RENAME), then the files journal backwards. */
	private function rollback(): string {
		$rb = (array) ( $this->job['rb'] ?? array() ) + array(
			'tables' => false,
			'files'  => -1,
		);
		$this->maintenance( true );

		if ( ! $rb['tables'] ) {
			if ( ! empty( $this->job['swapped'] ) ) {
				$tag   = (string) $this->job['tag'];
				$live  = $this->live_tables();
				$parts = array();
				$drop  = array();
				foreach ( (array) $this->job['tables'] as $t ) {
					$x = NM_Importer::temp_name( $t['final'], $tag, 'x' );
					if ( isset( $live[ $t['final'] ] ) ) {
						$parts[] = NM_DB::ident( $t['final'] ) . ' TO ' . NM_DB::ident( $x );
						$drop[]  = $x;
					}
					$snap = $this->job['snapshots'][ $t['final'] ] ?? '';
					if ( '' !== $snap && isset( $live[ $snap ] ) ) {
						$parts[] = NM_DB::ident( $snap ) . ' TO ' . NM_DB::ident( $t['final'] );
					}
				}
				if ( $parts && ! $this->db->query( 'RENAME TABLE ' . implode( ', ', $parts ) ) ) {
					$this->job['error_rb'] = $this->db->error;
					$this->persist();
					return 'failed';
				}
				foreach ( $drop as $x ) {
					$this->db->query( 'DROP TABLE IF EXISTS ' . NM_DB::ident( $x ) );
				}
				$this->job['swapped']   = false;
				$this->job['snapshots'] = array();
			}
			if ( $this->db ) {
				$this->drop_temps();
			}
			$rb['tables']    = true;
			$this->job['rb'] = $rb;
			$this->persist();
		}

		// Files: journal lines from the last to the first.
		$journal = (string) $this->job['work']['journal'];
		$lines   = is_file( $journal ) ? file( $journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : array();
		$lines   = is_array( $lines ) ? $lines : array();
		$i       = $rb['files'] < 0 ? count( $lines ) - 1 : (int) $rb['files'];
		$keep    = (string) $this->job['work']['rollback'];
		for ( ; $i >= 0; $i-- ) {
			$p = explode( "\t", (string) $lines[ $i ], 3 );
			if ( 3 === count( $p ) && NM_Util::safe_rel( $p[2] ) ) {
				$dir  = $this->group_dir( $p[1] );
				$dest = $dir . '/' . $p[2];
				if ( '1' === $p[0] ) {
					$orig = $keep . '/' . $p[1] . '/' . $p[2];
					if ( is_file( $orig ) ) {
						NM_Util::move( $orig, $dest );
					}
				} elseif ( '' !== $dir && is_file( $dest ) ) {
					@unlink( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			}
			if ( microtime( true ) >= $this->deadline ) {
				$rb['files']     = $i - 1;
				$this->job['rb'] = $rb;
				$this->persist();
				return 'more';
			}
		}
		@unlink( $journal ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		self::opcache();
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		$this->maintenance( false );
		$this->cleanup_work( true );
		$this->job['status'] = 'rolledback';
		$this->job['mode']   = '';
		$this->persist();
		return 'rolledback';
	}
}

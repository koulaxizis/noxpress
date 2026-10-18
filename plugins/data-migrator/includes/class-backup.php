<?php
/**
 * NM_Backup — makes a backup as a job of resumable steps:
 *
 *  scan    walk the selected folders into work/<id>/files.txt
 *          (group, relative path, size), with the exclusions; tables plan
 *  db      NM_Dumper → work/<id>/db/*.sql
 *  zip     SQL chunks, then the files, into one or more ZIP parts;
 *          each finished part gets its sha256
 *  finish  nm/manifest.json in the last part, metadata, cleanup
 *
 * The browser calls step() through admin-ajax (NM_Admin_UI), WP-CLI
 * calls it in a loop. A lock file keeps two requests from working on
 * the same job.
 *
 * File groups (target folders on restore):
 *  core        ABSPATH: wp-admin/, wp-includes/ and WordPress's own root files
 *  root        other files in ABSPATH (never wp-config.php) and folders the
 *              user ticked; unknown files are flagged in the manifest
 *  content     wp-content items that belong to no other group
 *  uploads, themes, plugins, mu-plugins, languages
 *  dropins     wp-content drop-ins (object-cache.php, advanced-cache.php …)
 */

defined( 'ABSPATH' ) || exit;

final class NM_Backup {

	const GROUPS = array( 'core', 'root', 'content', 'uploads', 'themes', 'plugins', 'mu-plugins', 'languages', 'dropins' );

	/** WordPress's own files in ABSPATH. */
	const CORE_FILES = array( 'index.php', 'license.txt', 'readme.html', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php', 'wp-config-sample.php', 'wp-cron.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php', 'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php' );

	/** Root files that are common and harmless (not flagged). */
	const KNOWN_ROOT = array( '.htaccess', 'robots.txt', 'favicon.ico', 'ads.txt', 'app-ads.txt', 'humans.txt', 'security.txt', 'manifest.json', 'browserconfig.xml', 'apple-touch-icon.png' );

	/** Root files that belong to the server, not the site (never restored by default). */
	const HOST_ROOT = array( '.user.ini', 'php.ini', 'web.config', '.htpasswd', 'error_log', '.env' );

	/** Folders never backed up, relative to wp-content. */
	const SKIP_CONTENT = array( 'cache', 'upgrade', 'upgrade-temp-backup', 'backup-db', 'backups', 'updraft', 'ai1wm-backups', 'backups-dup-lite', 'backups-dup-pro', 'wpvividbackups', 'wp-snapshots', 'wflogs', 'et-cache', 'litespeed', 'w3tc-config', 'wp-rocket-config', 'nginx-helper', 'uploads/backwpup', 'uploads/cache', 'uploads/wc-logs', 'uploads/wpforms/cache', 'uploads/ShortpixelBackups' );

	/** Names skipped anywhere. */
	const SKIP_NAMES = array( '.git', '.svn', '.hg', 'node_modules', '.DS_Store', 'Thumbs.db', 'error_log', '.maintenance' );

	/** Constants copied to the manifest (never anything secret). */
	const SAFE_CONSTANTS = array( 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT', 'WP_POST_REVISIONS', 'AUTOSAVE_INTERVAL', 'EMPTY_TRASH_DAYS', 'MEDIA_TRASH', 'IMAGE_EDIT_OVERWRITE', 'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS', 'FORCE_SSL_ADMIN', 'WP_CACHE', 'CONCATENATE_SCRIPTS', 'COMPRESS_SCRIPTS', 'WP_AUTO_UPDATE_CORE', 'AUTOMATIC_UPDATER_DISABLED', 'DISABLE_WP_CRON', 'WP_CRON_LOCK_TIMEOUT', 'WP_ENVIRONMENT_TYPE', 'WP_HOME', 'WP_SITEURL', 'WPLANG', 'FS_METHOD', 'WP_DEFAULT_THEME', 'DB_CHARSET', 'DB_COLLATE', 'UPLOADS', 'WP_CONTENT_URL', 'COOKIE_DOMAIN', 'WP_ALLOW_REPAIR', 'WP_DISABLE_FATAL_ERROR_HANDLER' );

	/* ---------------------------------------------------------------
	 * Site layout
	 * ------------------------------------------------------------- */

	/** Folder of each group on this site (normalized, no trailing slash). */
	public static function dirs(): array {
		$up = wp_upload_dir( null, false );
		return array(
			'core'       => NM_Util::norm( ABSPATH ),
			'root'       => NM_Util::norm( ABSPATH ),
			'content'    => NM_Util::norm( WP_CONTENT_DIR ),
			'uploads'    => NM_Util::norm( (string) $up['basedir'] ),
			'themes'     => NM_Util::norm( get_theme_root() ),
			'plugins'    => NM_Util::norm( WP_PLUGIN_DIR ),
			'mu-plugins' => NM_Util::norm( WPMU_PLUGIN_DIR ),
			'languages'  => NM_Util::norm( WP_LANG_DIR ),
			'dropins'    => NM_Util::norm( WP_CONTENT_DIR ),
		);
	}

	/** Drop-in file names WordPress knows. */
	public static function dropin_names(): array {
		if ( ! function_exists( '_get_dropins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return array_keys( _get_dropins() );
	}

	/**
	 * What is in ABSPATH: name => [type file|dir, kind core|content|known|host|unknown, php, size].
	 */
	public static function root_items(): array {
		$abs   = NM_Util::norm( ABSPATH );
		$dirs  = self::dirs();
		$out   = array();
		$items = @scandir( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		foreach ( (array) $items as $n ) {
			if ( '.' === $n || '..' === $n || 'wp-config.php' === $n || '.maintenance' === $n ) {
				continue;
			}
			$p = $abs . '/' . $n;
			if ( is_link( $p ) ) {
				continue;
			}
			$is_dir = is_dir( $p );
			$kind   = 'unknown';
			if ( $is_dir ) {
				if ( in_array( $n, array( 'wp-admin', 'wp-includes' ), true ) ) {
					$kind = 'core';
				} elseif ( in_array( $p, $dirs, true ) || NM_Util::inside( $dirs['content'], $p ) ) {
					$kind = 'content';
				} elseif ( '' !== NM_Storage::dir() && NM_Util::inside( NM_Storage::dir(), $p ) ) {
					continue;
				} elseif ( in_array( $n, self::SKIP_NAMES, true ) || 'cgi-bin' === $n ) {
					$kind = 'host';
				}
			} elseif ( in_array( $n, self::CORE_FILES, true ) ) {
				$kind = 'core';
			} elseif ( in_array( $n, self::KNOWN_ROOT, true ) || preg_match( '/^google[a-z0-9]+\.html$|^BingSiteAuth\.xml$|^yandex_[a-z0-9]+\.html$/i', $n ) ) {
				$kind = 'known';
			} elseif ( in_array( $n, self::HOST_ROOT, true ) || preg_match( '/\.log$/i', $n ) ) {
				$kind = 'host';
			}
			$out[ $n ] = array(
				'type' => $is_dir ? 'dir' : 'file',
				'kind' => $kind,
				'php'  => ! $is_dir && (bool) preg_match( '/\.(php\d?|phtml|phar)$/i', $n ),
				'size' => $is_dir ? 0 : (int) @filesize( $p ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			);
		}
		ksort( $out );
		return $out;
	}

	/** A connection with the site's credentials. */
	public static function db(): ?NM_DB {
		$db = new NM_DB();
		if ( ! $db->connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ) ) {
			NM_Util::log( 'db connect: ' . $db->error, true );
			return null;
		}
		return $db;
	}

	/* ---------------------------------------------------------------
	 * Start
	 * ------------------------------------------------------------- */

	/**
	 * Create a backup job.
	 *
	 * @param array $o scope (full|db|files|custom), groups[], db (bool),
	 *                 extra_tables[], root_dirs[], part_mb, note.
	 * @return array|WP_Error The job.
	 */
	public static function start( array $o ) {
		if ( '' === NM_Storage::dir() ) {
			return new WP_Error( 'storage', NM_Util::t( 'Δεν βρέθηκε φάκελος με δικαίωμα εγγραφής για τα αντίγραφα. Όρισε έναν στις Ρυθμίσεις.' ) );
		}
		$running = self::running();
		if ( $running ) {
			return new WP_Error( 'busy', NM_Util::t( 'Τρέχει ήδη μια εργασία του Data Migrator. Περίμενε να τελειώσει ή ακύρωσέ την.' ) );
		}
		$scope = in_array( $o['scope'] ?? '', array( 'full', 'db', 'files', 'custom' ), true ) ? $o['scope'] : 'full';
		$db    = true;
		$grps  = self::GROUPS;
		if ( 'db' === $scope ) {
			$grps = array();
		} elseif ( 'files' === $scope ) {
			$db = false;
		} elseif ( 'custom' === $scope ) {
			$db   = ! empty( $o['db'] );
			$grps = array_values( array_intersect( self::GROUPS, (array) ( $o['groups'] ?? array() ) ) );
		}
		if ( ! $db && ! $grps ) {
			return new WP_Error( 'empty', NM_Util::t( 'Διάλεξε τουλάχιστον τη βάση ή μία ομάδα αρχείων.' ) );
		}

		$dbc = $db ? self::db() : null;
		if ( $db && ! $dbc ) {
			return new WP_Error( 'db', NM_Util::t( 'Δεν έγινε σύνδεση με τη βάση δεδομένων.' ) );
		}
		$tables = array();
		$views  = array();
		$dbsize = 0.0;
		if ( $dbc ) {
			global $wpdb;
			$all   = NM_Dumper::list_tables( $dbc );
			$extra = array_map( 'strval', (array) ( $o['extra_tables'] ?? array() ) );
			foreach ( $all as $name => $t ) {
				$mine = 0 === strpos( $name, $wpdb->prefix );
				if ( ! $mine && ! in_array( $name, $extra, true ) ) {
					continue;
				}
				if ( 'view' === $t['type'] ) {
					$views[] = $name;
					continue;
				}
				$tables[] = $name;
				$dbsize  += (float) $t['size'];
			}
			$dbc->close();
		}

		$root_dirs = array();
		$items     = self::root_items();
		foreach ( (array) ( $o['root_dirs'] ?? array() ) as $d ) {
			$d = (string) $d;
			if ( isset( $items[ $d ] ) && 'dir' === $items[ $d ]['type'] && in_array( $items[ $d ]['kind'], array( 'unknown', 'host' ), true ) ) {
				$root_dirs[] = $d;
			}
		}

		$id   = NM_Util::hex( 16 );
		$work = NM_Storage::sub( 'work' ) . '/' . $id;
		if ( ! NM_Util::mkdirp( $work . '/db' ) ) {
			return new WP_Error( 'work', NM_Util::t( 'Δεν δημιουργήθηκε ο φάκελος εργασίας.' ) );
		}
		NM_Storage::protect( $work );
		$part = isset( $o['part_mb'] ) ? (int) $o['part_mb'] : (int) NM_Settings::get()['part_mb'];
		$part = in_array( $part, NM_Settings::PARTS, true ) ? $part : 0;
		$host = preg_replace( '/[^a-z0-9.-]/', '-', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$host = substr( trim( (string) $host, '.-' ), 0, 60 );
		$job  = array(
			'id'      => 'b-' . $id,
			'type'    => 'backup',
			'backup'  => $id,
			'created' => time(),
			'user'    => get_current_user_id(),
			'status'  => 'running',
			'phase'   => 'scan',
			'scope'   => $scope,
			'db'      => $db,
			'groups'  => $grps,
			'tables'  => $tables,
			'views'   => $views,
			'root'    => $root_dirs,
			'part_mb' => $part,
			'note'    => substr( sanitize_text_field( (string) ( $o['note'] ?? '' ) ), 0, 120 ),
			'sched'   => isset( $o['sched'] ) && preg_match( '/^[a-f0-9]{8}$/', (string) $o['sched'] ) ? (string) $o['sched'] : '',
			'dbsize'  => $dbsize,
			'stem'    => ( '' === $host ? 'site' : $host ) . '-' . wp_date( 'Ymd-His' ) . '-' . $id,
			'work'    => $work,
			'ss'      => array(),
			'parts'   => array(),
			'stats'   => array(
				'files' => 0,
				'bytes' => 0,
				'skip'  => 0,
				'links' => 0,
			),
			'flags'   => array(),
			'warn'    => array(),
		);
		NM_Storage::save_job( $job );
		NM_Storage::save_meta(
			array(
				'id'      => $id,
				'created' => $job['created'],
				'status'  => 'running',
				'scope'   => $scope,
				'note'    => $job['note'],
				'sched'   => $job['sched'],
				'parts'   => array(),
				'job'     => $job['id'],
				'home'    => home_url(),
			)
		);
		NM_Util::$log_file = NM_Storage::log_file( $job['id'] );
		NM_Util::log( 'backup ' . $id . ' started: scope ' . $scope . ', ' . count( $tables ) . ' tables, groups ' . implode( ',', $grps ) );
		return $job;
	}

	/** The running job (any type), or null. Jobs older than a day are ignored. */
	public static function running() {
		$d = NM_Storage::sub( 'jobs' );
		foreach ( (array) glob( $d . '/*.php' ) as $f ) {
			$j = NM_Util::read_state( (string) $f );
			if ( is_array( $j ) && in_array( $j['status'] ?? '', array( 'running', 'rolling-back' ), true ) && time() - (int) ( $j['created'] ?? 0 ) < DAY_IN_SECONDS ) {
				return $j;
			}
		}
		return null;
	}

	/* ---------------------------------------------------------------
	 * Steps
	 * ------------------------------------------------------------- */

	/**
	 * Work on a backup job until the time budget runs out.
	 *
	 * @return array status, phase, pct, text
	 */
	public static function step( string $job_id ): array {
		$path = NM_Storage::job_path( $job_id );
		$job  = '' === $path ? null : NM_Util::read_state( $path );
		if ( ! $job || 'backup' !== ( $job['type'] ?? '' ) ) {
			return array(
				'status' => 'failed',
				'text'   => NM_Util::t( 'Η εργασία δεν βρέθηκε.' ),
			);
		}
		if ( 'running' !== $job['status'] ) {
			return self::progress( $job );
		}
		$lock = NM_Util::lock( $path . '.lock' );
		if ( ! $lock ) {
			$p           = self::progress( $job );
			$p['status'] = 'busy';
			return $p;
		}
		NM_Util::prepare_request();
		NM_Util::$log_file = NM_Storage::log_file( $job_id );
		$deadline          = microtime( true ) + NM_Util::budget();
		try {
			while ( 'running' === $job['status'] && microtime( true ) < $deadline ) {
				if ( in_array( $job['phase'], array( 'db', 'zip', 'finish' ), true ) && self::low_disk() ) {
					self::disk_error( $job );
					self::fail( $job );
					NM_Storage::save_job( $job );
					break;
				}
				switch ( $job['phase'] ) {
					case 'scan':
						$r = self::phase_scan( $job, $deadline );
						break;
					case 'db':
						$r = self::phase_db( $job, $deadline );
						break;
					case 'zip':
						$r = self::phase_zip( $job, $deadline );
						break;
					case 'finish':
						$r = self::phase_finish( $job );
						break;
					default:
						$r = 'error';
				}
				if ( 'error' === $r ) {
					self::fail( $job );
				} elseif ( 'next' === $r ) {
					$order        = array( 'scan', 'db', 'zip', 'finish', 'done' );
					$job['phase'] = $order[ array_search( $job['phase'], $order, true ) + 1 ];
					$job['ss']    = array();
					if ( 'done' === $job['phase'] ) {
						$job['status'] = 'done';
					}
				}
				NM_Storage::save_job( $job );
			}
		} catch ( Throwable $e ) {
			$job['error'] = $e->getMessage();
			self::fail( $job );
			NM_Storage::save_job( $job );
		}
		NM_Util::unlock( $lock );
		return self::progress( $job );
	}

	private static function fail( array &$job ): void {
		$job['status'] = 'failed';
		NM_Util::log( 'backup failed: ' . (string) ( $job['error'] ?? '' ), true );
		$meta = NM_Storage::meta( (string) $job['backup'] );
		if ( $meta ) {
			$meta['status'] = 'failed';
			$meta['error']  = (string) ( $job['error'] ?? '' );
			NM_Storage::save_meta( $meta );
		}
		// Parts of a failed backup are useless: remove them and the work folder.
		foreach ( (array) glob( NM_Storage::sub( 'backups' ) . '/' . $job['stem'] . '*.zip' ) as $f ) {
			if ( preg_match( NM_Storage::FILE_RE, basename( (string) $f ) ) ) {
				@unlink( (string) $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		NM_Util::rrmdir( (string) $job['work'], NM_Storage::sub( 'work' ) );
	}

	/**
	 * Cancel a running job. With $why it ends as failed with that reason
	 * (the scheduler stopping a job that no longer moves).
	 */
	public static function cancel( string $job_id, string $why = '' ): bool {
		$job = NM_Storage::job( $job_id );
		if ( ! $job || 'backup' !== $job['type'] || 'running' !== $job['status'] ) {
			return false;
		}
		$job['error'] = '' === $why ? NM_Util::t( 'Ακυρώθηκε.' ) : $why;
		self::fail( $job );
		$job['status'] = '' === $why ? 'cancelled' : 'failed';
		NM_Storage::save_job( $job );
		return true;
	}

	/**
	 * Free space left on the disk of the backups folder must not fall
	 * under this (bytes): a full disk takes the site down with it
	 * (database writes, sessions, uploads fail).
	 */
	const RESERVE = 268435456;

	/** True when writing more would eat into the reserve. */
	private static function low_disk(): bool {
		$free = NM_Util::free_space( NM_Storage::dir() );
		return $free >= 0 && $free < self::RESERVE;
	}

	private static function disk_error( array &$job ): string {
		$job['error'] = NM_Util::tf( 'Σταμάτησε: ο ελεύθερος χώρος στον δίσκο έπεσε κάτω από %s. Το μισό αντίγραφο διαγράφηκε, για να συνεχίσει να δουλεύει το site.', NM_Util::size( (float) self::RESERVE ) );
		return 'error';
	}

	public static function progress( array $job ): array {
		$phases = array(
			'scan'   => NM_Util::t( 'Καταγραφή αρχείων' ),
			'db'     => NM_Util::t( 'Εξαγωγή βάσης' ),
			'zip'    => NM_Util::t( 'Συμπίεση' ),
			'finish' => NM_Util::t( 'Ολοκλήρωση' ),
			'done'   => NM_Util::t( 'Έτοιμο' ),
		);
		$pct  = 0;
		$text = $phases[ $job['phase'] ] ?? '';
		$ss   = (array) $job['ss'];
		if ( 'scan' === $job['phase'] ) {
			$pct   = 2;
			$text .= ' · ' . number_format_i18n( (int) $job['stats']['files'] );
		} elseif ( 'db' === $job['phase'] ) {
			$n     = max( 1, count( (array) $job['tables'] ) );
			$pct   = 5 + (int) ( 25 * ( (int) ( $ss['ti'] ?? 0 ) ) / $n );
			$text .= ' · ' . (int) ( $ss['ti'] ?? 0 ) . ' / ' . $n;
		} elseif ( 'zip' === $job['phase'] ) {
			$tot   = max( 1, (int) $job['stats']['bytes'] );
			$pct   = 30 + (int) min( 68, 68 * ( (float) ( $ss['bytes'] ?? 0 ) ) / $tot );
			$text .= ' · ' . NM_Util::size( (float) ( $ss['bytes'] ?? 0 ) ) . ' / ' . NM_Util::size( (float) $tot );
		} elseif ( 'finish' === $job['phase'] ) {
			$pct = 99;
		} elseif ( 'done' === $job['phase'] ) {
			$pct = 100;
		}
		return array(
			'status' => $job['status'],
			'phase'  => $job['phase'],
			'pct'    => $pct,
			'text'   => 'failed' === $job['status'] ? NM_Util::tf( 'Το αντίγραφο απέτυχε: %s', (string) ( $job['error'] ?? '' ) ) : $text,
			'id'     => $job['backup'],
		);
	}

	/* ---------------------------------------------------------------
	 * scan
	 * ------------------------------------------------------------- */

	/** True when a path must stay out of the backup. */
	private static function skip( string $group, string $rel, bool $is_dir, array $ctx ): bool {
		$name = basename( $rel );
		if ( in_array( $name, self::SKIP_NAMES, true ) ) {
			return true;
		}
		if ( ! $is_dir && preg_match( '/\.(log|tmp)$/i', $name ) ) {
			return true;
		}
		foreach ( $ctx['patterns'] as $pat ) {
			$subject = false === strpos( $pat, '/' ) ? $name : $group . '/' . $rel;
			if ( fnmatch( $pat, $subject ) ) {
				return true;
			}
		}
		return false;
	}

	/** Seed the walk queue. */
	private static function seed( array $job ): array {
		$dirs  = self::dirs();
		$queue = array();
		$files = array();
		$abs   = $dirs['core'];
		foreach ( (array) $job['groups'] as $g ) {
			if ( 'core' === $g ) {
				$queue[] = array( 'core', 'wp-admin' );
				$queue[] = array( 'core', 'wp-includes' );
				foreach ( self::CORE_FILES as $f ) {
					if ( is_file( $abs . '/' . $f ) ) {
						$files[] = array( 'core', $f );
					}
				}
			} elseif ( 'root' === $g ) {
				foreach ( self::root_items() as $n => $it ) {
					if ( 'file' === $it['type'] && 'core' !== $it['kind'] ) {
						$files[] = array( 'root', $n );
					} elseif ( 'dir' === $it['type'] && in_array( $n, (array) $job['root'], true ) ) {
						$queue[] = array( 'root', $n );
					}
				}
			} elseif ( 'dropins' === $g ) {
				foreach ( self::dropin_names() as $f ) {
					if ( is_file( $dirs['content'] . '/' . $f ) ) {
						$files[] = array( 'dropins', $f );
					}
				}
			} elseif ( '' !== $dirs[ $g ] && is_dir( $dirs[ $g ] ) ) {
				$queue[] = array( $g, '' );
			}
		}
		return array( $queue, $files );
	}

	private static function phase_scan( array &$job, float $deadline ): string {
		$list = $job['work'] . '/files.txt';
		$ss   = $job['ss'];
		if ( ! $ss ) {
			list( $queue, $files ) = self::seed( $job );
			$ss                    = array(
				'queue' => $queue,
				'size'  => 0,
				'seed'  => $files,
			);
			@file_put_contents( $list, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$job['flags'] = in_array( 'root', (array) $job['groups'], true ) ? self::flags( (array) $job['root'] ) : array();
			// Core integrity, once per backup (cached for an hour).
			if ( in_array( 'core', (array) $job['groups'], true ) ) {
				$job['integrity'] = NM_Integrity::summary( NM_Integrity::check() );
			}
		}
		$dirs    = self::dirs();
		$storage = NM_Storage::dir();
		$bases   = array();
		foreach ( $dirs as $g => $d ) {
			if ( 'core' !== $g && 'root' !== $g && 'dropins' !== $g && in_array( $g, (array) $job['groups'], true ) ) {
				$bases[ $d ] = $g;
			}
		}
		$skip_abs = array();
		foreach ( self::SKIP_CONTENT as $s ) {
			$skip_abs[] = $dirs['content'] . '/' . $s;
		}
		$ctx = array( 'patterns' => NM_Settings::patterns() );

		$fh = @fopen( $list, 'c+b' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			$job['error'] = NM_Util::t( 'Δεν γράφτηκε η λίστα αρχείων.' );
			return 'error';
		}
		ftruncate( $fh, (int) $ss['size'] );
		fseek( $fh, 0, SEEK_END );

		$add = static function ( string $g, string $rel, string $abs ) use ( &$job, $fh, $ctx ) {
			if ( self::skip( $g, $rel, false, $ctx ) ) {
				return;
			}
			$size = (int) @filesize( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! is_readable( $abs ) ) {
				++$job['stats']['skip'];
				return;
			}
			fwrite( $fh, $g . "\t" . $rel . "\t" . $size . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			++$job['stats']['files'];
			$job['stats']['bytes'] += $size;
		};

		foreach ( (array) $ss['seed'] as $f ) {
			$add( $f[0], $f[1], $dirs[ $f[0] ] . '/' . $f[1] );
		}
		$ss['seed'] = array();

		while ( $ss['queue'] ) {
			list( $g, $rel ) = array_shift( $ss['queue'] );
			$abs             = $dirs[ $g ] . ( '' === $rel ? '' : '/' . $rel );
			$items           = @scandir( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $items ) {
				++$job['stats']['skip'];
				$job['warn'][] = NM_Util::tf( 'Ο φάκελος %s δεν διαβάζεται.', $abs );
				continue;
			}
			$subdirs = array();
			foreach ( $items as $n ) {
				if ( '.' === $n || '..' === $n ) {
					continue;
				}
				$p  = $abs . '/' . $n;
				$r  = '' === $rel ? $n : $rel . '/' . $n;
				if ( is_link( $p ) ) {
					++$job['stats']['links'];
					continue;
				}
				if ( is_dir( $p ) ) {
					// Another group's folder, our storage, caches and backups of other plugins.
					if ( ( isset( $bases[ $p ] ) && $bases[ $p ] !== $g ) || in_array( $p, $dirs, true ) && $p !== $dirs[ $g ] ) {
						continue;
					}
					if ( '' !== $storage && NM_Util::inside( $p, $storage ) ) {
						continue;
					}
					if ( in_array( $p, $skip_abs, true ) || preg_match( '#/(nm-stage-[a-f0-9]+|backwpup-[^/]*|noxpress-data-migrator-[a-f0-9]+)$#', $p ) ) {
						continue;
					}
					if ( self::skip( $g, $r, true, $ctx ) ) {
						continue;
					}
					$subdirs[] = array( $g, $r );
					continue;
				}
				if ( 'content' === $g && '' === $rel && in_array( $n, self::dropin_names(), true ) ) {
					continue; // Drop-ins are their own group.
				}
				if ( ( 'core' === $g || 'root' === $g ) && '' === $rel ) {
					continue;
				}
				$add( $g, $r, $p );
			}
			// Depth first keeps the queue short.
			$ss['queue'] = array_merge( $subdirs, $ss['queue'] );
			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}
		fflush( $fh );
		$ss['size'] = (int) ftell( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$job['ss'] = $ss;
		if ( $ss['queue'] ) {
			return 'more';
		}
		NM_Util::log( 'scan: ' . $job['stats']['files'] . ' files, ' . NM_Util::size( (float) $job['stats']['bytes'] ) );
		$free = NM_Util::free_space( NM_Storage::dir() );
		// Scheduled runs nobody watches: skip when it would not fit.
		$need = (float) $job['stats']['bytes'] + (float) ( $job['dbsize'] ?? 0 ) + self::RESERVE;
		if ( ! empty( $job['sched'] ) && $free >= 0 && $free < $need ) {
			$job['error'] = NM_Util::tf( 'Ο ελεύθερος χώρος (%1$s) δεν φτάνει για το αντίγραφο (περίπου %2$s μαζί με ένα περιθώριο για το site). Δεν ξεκίνησε.', NM_Util::size( $free ), NM_Util::size( $need ) );
			return 'error';
		}
		if ( $free >= 0 && $free < (float) $job['stats']['bytes'] * 1.05 + 52428800 ) {
			$job['warn'][] = NM_Util::tf( 'Ο ελεύθερος χώρος (%1$s) μπορεί να μη φτάσει για το αντίγραφο (έως %2$s).', NM_Util::size( $free ), NM_Util::size( (float) $job['stats']['bytes'] ) );
		}
		return 'next';
	}

	/** Root files worth a look in the manifest and the restore screen. */
	/** Unknown and host items of the root that go into the archive. */
	private static function flags( array $root_dirs ): array {
		$out = array();
		foreach ( self::root_items() as $n => $it ) {
			if ( 'unknown' !== $it['kind'] && 'host' !== $it['kind'] ) {
				continue;
			}
			if ( 'dir' === $it['type'] ? ! in_array( $n, $root_dirs, true ) : ( in_array( $n, self::SKIP_NAMES, true ) || preg_match( '/\.(log|tmp)$/i', $n ) ) ) {
				continue; // Not in the archive.
			}
			$out[ $n ] = $it;
		}
		return $out;
	}

	/* ---------------------------------------------------------------
	 * db
	 * ------------------------------------------------------------- */

	private static function phase_db( array &$job, float $deadline ): string {
		if ( empty( $job['db'] ) || ! $job['tables'] ) {
			return 'next';
		}
		$db = self::db();
		if ( ! $db ) {
			$job['error'] = NM_Util::t( 'Δεν έγινε σύνδεση με τη βάση δεδομένων.' );
			return 'error';
		}
		$path = NM_Storage::job_path( $job['id'] );
		$ss   = $job['ss'] ? $job['ss'] : array( 'tables' => $job['tables'] );
		$dump = new NM_Dumper(
			$db,
			$ss,
			$job['work'] . '/db',
			static function ( $st ) use ( &$job, $path ) {
				$job['ss'] = $st;
				NM_Util::write_state( $path, $job );
			}
		);
		$r         = $dump->run( $deadline );
		$job['ss'] = $dump->st;
		$db->close();
		if ( 'error' === $r ) {
			$job['error'] = NM_Util::tf( 'Η εξαγωγή της βάσης σταμάτησε: %s', (string) $dump->st['error'] );
			return 'error';
		}
		if ( 'done' === $r ) {
			$fks = array();
			foreach ( (array) $dump->st['info'] as $t => $inf ) {
				if ( ! empty( $inf['fks'] ) ) {
					$fks[ $t ] = $inf['fks'];
				}
			}
			$job['dbinfo'] = array(
				'rows'    => $dump->st['rows'],
				'fks'     => $fks,
				'maxstmt' => (int) $dump->st['maxstmt'],
				'chunks'  => count( (array) $dump->st['chunks'] ),
			);
			$job['stats']['bytes'] += self::dir_bytes( $job['work'] . '/db' );
			NM_Util::log( 'db: ' . count( $job['tables'] ) . ' tables, ' . array_sum( array_map( 'intval', $dump->st['rows'] ) ) . ' rows' );
			return 'next';
		}
		return 'more';
	}

	private static function dir_bytes( string $dir ): int {
		$t = 0;
		foreach ( (array) glob( $dir . '/*' ) as $f ) {
			$t += (int) @filesize( (string) $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return $t;
	}

	/* ---------------------------------------------------------------
	 * zip
	 * ------------------------------------------------------------- */

	private static function part_name( array $job, int $n ): string {
		return $job['stem'] . ( $n > 1 ? '-part' . $n : '' ) . '.zip';
	}

	private static function writer( array $job, array $ss ): NM_Zip_Writer {
		$w = $ss['w'] ?? array();
		if ( ! $w ) {
			$w = array(
				'path' => NM_Storage::sub( 'backups' ) . '/' . self::part_name( $job, (int) $ss['part'] ),
				'cd'   => $job['work'] . '/cd-' . (int) $ss['part'] . '.bin',
			);
		}
		return new NM_Zip_Writer( $w );
	}

	private static function phase_zip( array &$job, float $deadline ): string {
		$ss = $job['ss'] + array(
			'part'   => 1,
			'chunks' => null,
			'pos'    => 0,
			'bytes'  => 0,
			'w'      => array(),
			'hash'   => 0,
		);
		if ( null === $ss['chunks'] ) {
			$c = glob( $job['work'] . '/db/*.sql' );
			sort( $c );
			$ss['chunks'] = $c ? array_values( $c ) : array();
		}
		// A finished part waiting for its checksum.
		if ( $ss['hash'] ) {
			$i                          = count( $job['parts'] ) - 1;
			$job['parts'][ $i ]['sha256'] = (string) hash_file( 'sha256', NM_Storage::sub( 'backups' ) . '/' . $job['parts'][ $i ]['file'] );
			$ss['hash']                 = 0;
			$job['ss']                  = $ss;
			return 'more';
		}
		$limit = (int) $job['part_mb'] * 1048576;
		$zw    = self::writer( $job, $ss );
		if ( ! $zw->open() ) {
			$job['error'] = NM_Util::t( 'Δεν γράφεται το αρχείο ZIP στον φάκελο των αντιγράφων.' );
			return 'error';
		}
		$path = NM_Storage::job_path( $job['id'] );
		$last = microtime( true );

		// 1. SQL chunks (deleted once they are in the archive).
		while ( $ss['chunks'] ) {
			$c     = (string) $ss['chunks'][0];
			$bytes = is_file( $c ) ? (string) file_get_contents( $c ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( $limit > 0 && $zw->size() > 0 && $zw->size() + strlen( $bytes ) > $limit ) {
				if ( ! self::close_part( $job, $ss, $zw ) ) {
					return 'error';
				}
				$job['ss'] = $ss;
				return 'more';
			}
			if ( ! $zw->add_string( 'db/' . basename( $c ), $bytes ) ) {
				$zw->close();
				$job['error'] = NM_Util::t( 'Δεν γράφεται το αρχείο ZIP (χώρος στον δίσκο;).' );
				return 'error';
			}
			$ss['bytes'] += strlen( $bytes );
			array_shift( $ss['chunks'] );
			$ss['w']   = $zw->state();
			$job['ss'] = $ss;
			NM_Util::write_state( $path, $job );
			@unlink( $c ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			if ( self::low_disk() ) {
				$zw->close();
				return self::disk_error( $job );
			}
			if ( microtime( true ) >= $deadline ) {
				$zw->close();
				return 'more';
			}
		}

		// 2. Files from the list.
		$dirs = self::dirs();
		$lh   = @fopen( $job['work'] . '/files.txt', 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $lh ) {
			$zw->close();
			$job['error'] = NM_Util::t( 'Δεν γράφτηκε η λίστα αρχείων.' );
			return 'error';
		}
		fseek( $lh, (int) $ss['pos'] );
		while ( ( $line = fgets( $lh ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$p = explode( "\t", rtrim( $line, "\n" ) );
			if ( 3 !== count( $p ) || ! isset( $dirs[ $p[0] ] ) ) {
				$ss['pos'] = ftell( $lh );
				continue;
			}
			$size = (int) $p[2];
			if ( $limit > 0 && $zw->size() > 0 && ! $zw->busy() && $zw->size() + $size > $limit ) {
				fclose( $lh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				if ( ! self::close_part( $job, $ss, $zw ) ) {
					return 'error';
				}
				$job['ss'] = $ss;
				return 'more';
			}
			$r = $zw->add_file( $dirs[ $p[0] ] . '/' . $p[1], 'files/' . $p[0] . '/' . $p[1], $deadline );
			if ( 'fail' === $r ) {
				fclose( $lh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$zw->close();
				$job['error'] = NM_Util::t( 'Δεν γράφεται το αρχείο ZIP (χώρος στον δίσκο;).' );
				return 'error';
			}
			$ss['w'] = $zw->state();
			if ( 'paused' === $r ) {
				$job['ss'] = $ss;
				fclose( $lh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$zw->close();
				return 'more';
			}
			if ( 'skip' === $r ) {
				++$job['stats']['skip'];
				if ( count( $job['warn'] ) < 50 ) {
					$job['warn'][] = NM_Util::tf( 'Παραλείφθηκε (δεν διαβάζεται ή χάθηκε): %s', $p[0] . '/' . $p[1] );
				}
			}
			$ss['bytes'] += $size;
			$ss['pos']    = ftell( $lh );
			if ( microtime( true ) - $last > 2 ) {
				$job['ss'] = $ss;
				NM_Util::write_state( $path, $job );
				$last = microtime( true );
				if ( self::low_disk() ) {
					fclose( $lh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					$zw->close();
					return self::disk_error( $job );
				}
			}
			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}
		$eof = feof( $lh );
		fclose( $lh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$zw->close();
		$job['ss'] = $ss;
		if ( $eof ) {
			// The last part stays open for the manifest.
			$job['zipss'] = $ss;
			return 'next';
		}
		return 'more';
	}

	/** Finish the current part and start the next one. */
	private static function close_part( array &$job, array &$ss, NM_Zip_Writer $zw ): bool {
		if ( ! $zw->finish() ) {
			$zw->close();
			$job['error'] = NM_Util::t( 'Δεν γράφεται το αρχείο ZIP (χώρος στον δίσκο;).' );
			return false;
		}
		$st = $zw->state();
		$zw->close();
		@unlink( $st['cd'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		$job['parts'][] = array(
			'file'   => basename( $st['path'] ),
			'size'   => (int) filesize( $st['path'] ),
			'sha256' => '',
		);
		++$ss['part'];
		$ss['w']    = array();
		$ss['hash'] = 1;
		NM_Util::log( 'part ' . count( $job['parts'] ) . ' closed' );
		return true;
	}

	/* ---------------------------------------------------------------
	 * finish
	 * ------------------------------------------------------------- */

	private static function phase_finish( array &$job ): string {
		$zss      = (array) ( $job['zipss'] ?? array() );
		$manifest = self::manifest( $job );
		$zw       = self::writer( $job, $zss + array( 'part' => 1 ) );
		if ( ! $zw->open() ) {
			$job['error'] = NM_Util::t( 'Δεν γράφεται το αρχείο ZIP στον φάκελο των αντιγράφων.' );
			return 'error';
		}
		if ( ! $zw->add_string( 'nm/manifest.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) || ! $zw->finish() ) {
			$zw->close();
			$job['error'] = NM_Util::t( 'Δεν γράφεται το αρχείο ZIP (χώρος στον δίσκο;).' );
			return 'error';
		}
		$st = $zw->state();
		$zw->close();
		@unlink( $st['cd'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		$job['parts'][] = array(
			'file'   => basename( $st['path'] ),
			'size'   => (int) filesize( $st['path'] ),
			'sha256' => (string) hash_file( 'sha256', $st['path'] ),
		);
		NM_Util::rrmdir( (string) $job['work'], NM_Storage::sub( 'work' ) );

		$meta = array(
			'id'        => $job['backup'],
			'created'   => $job['created'],
			'status'    => 'done',
			'scope'     => $job['scope'],
			'note'      => $job['note'],
			'sched'     => (string) ( $job['sched'] ?? '' ),
			'job'       => $job['id'],
			'home'      => home_url(),
			'parts'     => $job['parts'],
			'size'      => array_sum( array_column( $job['parts'], 'size' ) ),
			'db'        => (bool) $job['db'],
			'groups'    => $job['groups'],
			'files'     => (int) $job['stats']['files'],
			'tables'    => count( (array) $job['tables'] ),
			'wp'        => get_bloginfo( 'version' ),
			'flags'     => $job['flags'],
			'warn'      => array_slice( (array) $job['warn'], 0, 50 ),
			'integrity' => $job['integrity'] ?? null,
		);
		NM_Storage::save_meta( $meta );
		NM_Util::log( 'backup done: ' . count( $job['parts'] ) . ' part(s), ' . NM_Util::size( (float) $meta['size'] ) );
		return 'next';
	}

	/** nm/manifest.json */
	private static function manifest( array $job ): array {
		global $wpdb, $wp_version;
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = array();
		$active  = (array) get_option( 'active_plugins', array() );
		foreach ( get_plugins() as $file => $p ) {
			$plugins[] = array(
				'file'    => $file,
				'name'    => $p['Name'],
				'version' => $p['Version'],
				'active'  => in_array( $file, $active, true ),
			);
		}
		$constants = array();
		foreach ( self::SAFE_CONSTANTS as $c ) {
			if ( defined( $c ) ) {
				$v = constant( $c );
				if ( is_scalar( $v ) || null === $v ) {
					$constants[ $c ] = $v;
				}
			}
		}
		$up     = wp_upload_dir( null, false );
		$db     = self::db();
		$mysql  = $db ? $db->server_version() : '';
		$routes = $db ? NM_Dumper::routines( $db ) : array();
		if ( $db ) {
			$db->close();
		}
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => array( 'user_login' ),
				'number' => 20,
			)
		);
		$theme  = wp_get_theme();
		return array(
			'format'    => 1,
			'plugin'    => 'data-migrator',
			'version'   => NM_VERSION,
			'id'        => $job['backup'],
			'created'   => gmdate( 'c', (int) $job['created'] ),
			'scope'     => $job['scope'],
			'note'      => $job['note'],
			'db'        => (bool) $job['db'],
			'groups'    => $job['groups'],
			'root_dirs' => $job['root'],
			'site'      => array(
				'home'        => home_url(),
				'siteurl'     => site_url(),
				'name'        => get_bloginfo( 'name' ),
				'abspath'     => NM_Util::norm( ABSPATH ),
				'dirs'        => self::dirs(),
				'content_url' => content_url(),
				'uploads_url' => (string) $up['baseurl'],
				'prefix'      => $wpdb->prefix,
				'charset'     => $wpdb->charset,
				'collate'     => $wpdb->collate,
				'blog_public' => (int) get_option( 'blog_public' ),
				'permalinks'  => (string) get_option( 'permalink_structure' ),
				'locale'      => get_locale(),
				'timezone'    => wp_timezone_string(),
				'own'         => dirname( plugin_basename( NM_FILE ) ),
			),
			'versions'  => array(
				'wp'         => $wp_version,
				'db_version' => (int) get_option( 'db_version' ),
				'wc'         => defined( 'WC_VERSION' ) ? WC_VERSION : '',
				'php'        => PHP_VERSION,
				'mysql'      => $mysql,
			),
			'theme'     => array(
				'stylesheet' => get_stylesheet(),
				'template'   => get_template(),
				'name'       => $theme->get( 'Name' ),
			),
			'plugins'   => $plugins,
			'tables'    => $job['dbinfo']['rows'] ?? array(),
			'views'     => $job['views'],
			'routines'  => $routes,
			'fks'       => $job['dbinfo']['fks'] ?? array(),
			'maxstmt'   => (int) ( $job['dbinfo']['maxstmt'] ?? 0 ),
			'files'     => array(
				'count' => (int) $job['stats']['files'],
				'bytes' => (int) $job['stats']['bytes'],
				'skip'  => (int) $job['stats']['skip'],
				'links' => (int) $job['stats']['links'],
			),
			'flags'     => $job['flags'],
			'constants' => $constants,
			'integrity' => $job['integrity'] ?? null,
			'admins'    => array_map(
				static function ( $u ) {
					return $u->user_login;
				},
				$admins
			),
			'parts'     => $job['parts'],
			'warnings'  => array_slice( (array) $job['warn'], 0, 50 ),
		);
	}
}

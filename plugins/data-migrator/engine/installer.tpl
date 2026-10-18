<?php
/**
 * Data Migrator — installer for an empty server.
 *
 * Put this file and every part of the backup in the folder where the
 * site will live, open the file in the browser and give the one-time key
 * that the plugin showed when it made this installer.
 *
 * - Key: only its sha256 is in this file. 5 wrong attempts lock the
 *   installer for 15 minutes; it stops working 7 days after it was made.
 * - It never reads or writes outside its own folder.
 * - It writes wp-config.php from the backup's wp-config-sample.php with
 *   the database details you give and fresh salts (the old site's
 *   wp-config.php is never in a backup).
 * - At the end it deletes the backup parts, its work folder, its state
 *   and itself.
 *
 * Built by Data Migrator __NM_VERSION__ (https://noxpress.tech) for
 * backup __NM_BACKUP__.
 */

define( 'NM_STANDALONE', true );
define( 'NM_INST_KEYHASH', '__NM_KEYHASH__' );
define( 'NM_INST_BACKUP', '__NM_BACKUP__' );
define( 'NM_INST_EXPIRES', __NM_EXPIRES__ );

// __NM_ENGINE__

final class NM_Installer_App {

	/** @var string */
	private $dir;
	/** @var string */
	private $state_file;
	/** @var array */
	private $st;
	/** @var string */
	private $self_url;

	public function __construct() {
		$this->dir        = NM_Util::norm( __DIR__ );
		$this->state_file = $this->dir . '/.nm-installer-' . substr( NM_INST_KEYHASH, 0, 12 ) . '.php';
		$st               = NM_Util::read_state( $this->state_file );
		$this->st         = is_array( $st ) ? $st : array(
			'fails'  => 0,
			'locked' => 0,
			'sess'   => '',
			'lang'   => '',
		);
		$this->self_url   = strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' ); // phpcs:ignore
	}

	private function save(): void {
		NM_Util::write_state( $this->state_file, $this->st );
	}

	private function cookie_name(): string {
		return 'nm_inst_' . substr( NM_INST_KEYHASH, 0, 12 );
	}

	private function https(): bool {
		return ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ) ) || 443 === (int) ( $_SERVER['SERVER_PORT'] ?? 0 ) || 'https' === strtolower( (string) ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) ); // phpcs:ignore
	}

	private function authed(): bool {
		$c = isset( $_COOKIE[ $this->cookie_name() ] ) && is_string( $_COOKIE[ $this->cookie_name() ] ) ? $_COOKIE[ $this->cookie_name() ] : ''; // phpcs:ignore
		return '' !== $c && '' !== $this->st['sess'] && hash_equals( (string) $this->st['sess'], hash( 'sha256', $c ) );
	}

	private function csrf(): string {
		return substr( hash( 'sha256', 'csrf' . (string) $this->st['sess'] ), 0, 32 );
	}

	private function post( string $k ): string {
		return isset( $_POST[ $k ] ) && is_string( $_POST[ $k ] ) ? trim( $_POST[ $k ] ) : ''; // phpcs:ignore
	}

	/* ------------------------------------------------------------- */

	public function run(): void {
		$lang = isset( $_GET['lang'] ) && in_array( $_GET['lang'], array( 'el', 'en' ), true ) ? $_GET['lang'] : ''; // phpcs:ignore
		if ( '' !== $lang ) {
			$this->st['lang'] = $lang;
			$this->save();
		}
		NM_Util::$lang = 'en' === $this->st['lang'] ? 'en' : 'el';

		if ( time() > NM_INST_EXPIRES ) {
			$this->page_msg( NM_Util::t( 'Ο installer έληξε (ισχύει 7 ημέρες). Φτιάξε νέο από τη σελίδα «Αντίγραφα» του Data Migrator.' ) );
			return;
		}
		$method = (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ); // phpcs:ignore
		if ( ! $this->authed() ) {
			if ( 'POST' === $method && '' !== $this->post( 'key' ) ) {
				$this->login();
				return;
			}
			$this->page_login( '' );
			return;
		}
		if ( 'POST' === $method && ! hash_equals( $this->csrf(), $this->post( 't' ) ) ) {
			$this->page_msg( NM_Util::t( 'Μη έγκυρο αίτημα.' ) );
			return;
		}
		$do = 'POST' === $method ? $this->post( 'do' ) : '';
		if ( 'step' === $do ) {
			$this->step();
			return;
		}
		if ( 'start' === $do ) {
			$this->start();
			return;
		}
		$job = $this->job();
		if ( $job ) {
			$this->page_job( $job );
			return;
		}
		$this->page_form( array() );
	}

	private function login(): void {
		if ( (int) $this->st['locked'] > time() ) {
			$this->page_login( NM_Util::t( 'Πολλές λάθος προσπάθειες. Δοκίμασε ξανά σε 15 λεπτά.' ) );
			return;
		}
		$key = strtolower( preg_replace( '/[^A-Za-z0-9]/', '', $this->post( 'key' ) ) );
		if ( ! hash_equals( NM_INST_KEYHASH, hash( 'sha256', $key ) ) ) {
			++$this->st['fails'];
			if ( $this->st['fails'] >= 5 ) {
				$this->st['locked'] = time() + 900;
				$this->st['fails']  = 0;
			}
			$this->save();
			sleep( 1 );
			$this->page_login( NM_Util::t( 'Λάθος κλειδί.' ) );
			return;
		}
		$secret             = NM_Util::hex( 32 );
		$this->st['sess']   = hash( 'sha256', $secret );
		$this->st['fails']  = 0;
		$this->save();
		setcookie(
			$this->cookie_name(),
			$secret,
			array(
				'expires'  => 0,
				'path'     => '/',
				'secure'   => $this->https(),
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);
		header( 'Location: ' . $this->self_url, true, 303 );
	}

	/* ------------------------------------------------------------- */

	/** Archive parts next to the installer, in order. */
	private function parts(): array {
		$found = array();
		foreach ( (array) glob( $this->dir . '/*' . NM_INST_BACKUP . '*.zip' ) as $f ) {
			$b = basename( (string) $f );
			if ( preg_match( '/^[a-z0-9.-]{1,80}-\d{8}-\d{6}-' . NM_INST_BACKUP . '(-part(\d{1,3}))?\.zip$/', $b, $m ) ) {
				$found[ isset( $m[2] ) ? (int) $m[2] : 1 ] = (string) $f;
			}
		}
		ksort( $found );
		return array_values( $found );
	}

	private function manifest( array $parts ) {
		if ( ! $parts ) {
			return null;
		}
		$zr = new NM_Zip_Reader( end( $parts ) );
		if ( ! $zr->open() ) {
			return null;
		}
		$e = $zr->find( 'nm/manifest.json' );
		$m = $e ? $zr->read( $e ) : false;
		$zr->close();
		$j = is_string( $m ) ? json_decode( $m, true ) : null;
		return is_array( $j ) && NM_INST_BACKUP === ( $j['id'] ?? '' ) ? $j : null;
	}

	private function job_file(): string {
		return $this->dir . '/.nm-installer-' . substr( NM_INST_KEYHASH, 0, 12 ) . '-job.php';
	}

	private function job() {
		return NM_Util::read_state( $this->job_file() );
	}

	private function work(): string {
		return $this->dir . '/nm-installer-work-' . substr( NM_INST_KEYHASH, 0, 12 );
	}

	private function default_url(): string {
		$host = (string) ( $_SERVER['HTTP_HOST'] ?? 'localhost' ); // phpcs:ignore
		$host = preg_match( '/^[A-Za-z0-9.\-:\[\]]+$/', $host ) ? $host : 'localhost';
		$path = rtrim( str_replace( '\\', '/', dirname( $this->self_url ) ), '/' );
		return ( $this->https() ? 'https://' : 'http://' ) . $host . ( '/' === $path ? '' : $path );
	}

	/** Environment checks: [level, text]. */
	private function checks( array $parts, $m ): array {
		$out = array();
		$out[] = version_compare( PHP_VERSION, '7.4', '>=' ) ? array( 'ok', 'PHP ' . PHP_VERSION ) : array( 'fail', NM_Util::tf( 'Χρειάζεται PHP 7.4 ή νεότερη (εδώ %s).', PHP_VERSION ) );
		foreach ( array(
			'mysqli' => class_exists( 'mysqli' ),
			'zlib'   => function_exists( 'gzinflate' ),
			'json'   => function_exists( 'json_decode' ),
		) as $ext => $ok ) {
			$out[] = $ok ? array( 'ok', $ext ) : array( 'fail', NM_Util::tf( 'Λείπει η επέκταση PHP %s.', $ext ) );
		}
		if ( ! is_writable( $this->dir ) ) {
			$out[] = array( 'fail', NM_Util::t( 'Ο φάκελος δεν γράφεται από την PHP.' ) );
		}
		if ( ! $parts ) {
			$out[] = array( 'fail', NM_Util::t( 'Δεν βρέθηκαν τα κομμάτια του αντιγράφου δίπλα στον installer.' ) );
		} elseif ( ! $m ) {
			$out[] = array( 'fail', NM_Util::t( 'Λείπει το τελευταίο κομμάτι (με το manifest) ή δεν είναι αυτό το αντίγραφο.' ) );
		} elseif ( count( $parts ) !== count( (array) $m['parts'] ) + 1 ) {
			$out[] = array( 'fail', NM_Util::tf( 'Βρέθηκαν %1$d από %2$d κομμάτια.', count( $parts ), count( (array) $m['parts'] ) + 1 ) );
		} else {
			$out[] = array( 'ok', NM_Util::tf( 'Αντίγραφο του %1$s, %2$s.', (string) $m['site']['home'], (string) $m['created'] ) );
			if ( empty( $m['db'] ) || ! in_array( 'core', (array) $m['groups'], true ) ) {
				$out[] = array( 'fail', NM_Util::t( 'Ο installer χρειάζεται πλήρες αντίγραφο (βάση και αρχεία του WordPress).' ) );
			}
			$size = 0;
			foreach ( $parts as $p ) {
				$size += (int) filesize( $p );
			}
			$free = NM_Util::free_space( $this->dir );
			if ( $free >= 0 && $free < $size * 2.2 ) {
				$out[] = array( 'fail', NM_Util::tf( 'Δεν υπάρχει αρκετός χώρος στον δίσκο (ελεύθερα %1$s, χρειάζονται περίπου %2$s).', NM_Util::size( $free ), NM_Util::size( $size * 2.2 ) ) );
			}
		}
		if ( is_file( $this->dir . '/wp-config.php' ) && ! $this->job() ) {
			$out[] = array( 'fail', NM_Util::t( 'Υπάρχει ήδη wp-config.php εδώ. Για site που ήδη τρέχει, χρησιμοποίησε την επαναφορά μέσα από το plugin.' ) );
		}
		return $out;
	}

	/* ------------------------------------------------------------- */

	private function start(): void {
		$parts = $this->parts();
		$m     = $this->manifest( $parts );
		foreach ( $this->checks( $parts, $m ) as $c ) {
			if ( 'fail' === $c[0] ) {
				$this->page_form( array( $c[1] ) );
				return;
			}
		}
		$f = array(
			'host'   => $this->post( 'db_host' ),
			'name'   => $this->post( 'db_name' ),
			'user'   => $this->post( 'db_user' ),
			'pass'   => isset( $_POST['db_pass'] ) && is_string( $_POST['db_pass'] ) ? $_POST['db_pass'] : '', // phpcs:ignore
			'prefix' => $this->post( 'prefix' ),
			'url'    => rtrim( $this->post( 'url' ), '/' ),
		);
		$errors = array();
		if ( ! preg_match( '/^[A-Za-z0-9_]{1,20}$/', $f['prefix'] ) ) {
			$errors[] = NM_Util::t( 'Το πρόθεμα πινάκων δέχεται μόνο λατινικά, αριθμούς και _.' );
		}
		if ( ! preg_match( '#^https?://[^\s/?\#]+(/[^\s?\#]*)?$#', $f['url'] ) ) {
			$errors[] = NM_Util::t( 'Η διεύθυνση του site δεν είναι έγκυρη.' );
		}
		$db = new NM_DB();
		if ( ! $errors && ! $db->connect( $f['host'], $f['user'], $f['pass'], $f['name'] ) ) {
			$errors[] = NM_Util::tf( 'Δεν έγινε σύνδεση με τη βάση: %s', $db->error );
		}
		if ( ! $errors ) {
			$exists = $db->rows( 'SHOW TABLES LIKE ' . $db->like( $f['prefix'] ) );
			if ( $exists && '1' !== $this->post( 'overwrite' ) ) {
				$errors[] = NM_Util::tf( 'Η βάση έχει ήδη %1$d πίνακες με το πρόθεμα %2$s. Τσέκαρε «Αντικατάσταση» για να γραφτούν από πάνω.', count( $exists ), $f['prefix'] );
			}
			$db->close();
		}
		if ( $errors ) {
			$this->page_form( $errors );
			return;
		}

		$work  = $this->work();
		$guard = $this->dir;
		NM_Util::mkdirp( $work . '/db' );
		NM_Util::mkdirp( $work . '/stage' );
		NM_Util::mkdirp( $work . '/rollback' );
		file_put_contents( $work . '/index.php', "<?php\n" ); // phpcs:ignore
		file_put_contents( $work . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n" ); // phpcs:ignore

		$d       = $this->dir;
		$content = $d . '/wp-content';
		$dirs    = array(
			'core'       => $d,
			'root'       => $d,
			'content'    => $content,
			'uploads'    => $content . '/uploads',
			'themes'     => $content . '/themes',
			'plugins'    => $content . '/plugins',
			'mu-plugins' => $content . '/mu-plugins',
			'languages'  => $content . '/languages',
			'dropins'    => $content,
		);
		$src     = (array) $m['site']['dirs'];
		$pairs   = NM_Replacer::url_pairs( (string) $m['site']['home'], $f['url'] );
		if ( rtrim( (string) $m['site']['siteurl'], '/' ) !== rtrim( (string) $m['site']['home'], '/' ) ) {
			$sub   = substr( rtrim( (string) $m['site']['siteurl'], '/' ), strlen( rtrim( (string) $m['site']['home'], '/' ) ) );
			$pairs = NM_Replacer::url_pairs( (string) $m['site']['siteurl'], $f['url'] . $sub ) + $pairs;
		}
		$pairs += NM_Replacer::path_pairs( (string) $m['site']['abspath'], $d );
		foreach ( array( 'content', 'uploads' ) as $g ) {
			if ( isset( $src[ $g ] ) && ! NM_Util::inside( (string) $src[ $g ], (string) $m['site']['abspath'] ) ) {
				$pairs += NM_Replacer::path_pairs( (string) $src[ $g ], $dirs[ $g ] );
			}
		}
		foreach ( (array) ( $_POST['local'] ?? array() ) as $u ) { // phpcs:ignore
			if ( is_string( $u ) && preg_match( '#^https?://(localhost|127\.0\.0\.1)#', $u ) ) {
				$pairs += NM_Replacer::url_pairs( $u, $f['url'] );
			}
		}
		// Root files: the ticked flagged ones, and every other one except .htaccess.
		$flags = (array) ( $m['flags'] ?? array() );
		$root  = array();
		foreach ( (array) ( $_POST['root'] ?? array() ) as $r ) { // phpcs:ignore
			if ( is_string( $r ) && isset( $flags[ rtrim( $r, '/' ) ] ) ) {
				$root[] = $r;
			}
		}
		foreach ( $this->parts() as $p ) {
			$zr = new NM_Zip_Reader( $p );
			if ( ! $zr->open() ) {
				continue;
			}
			$pos = 0;
			$e   = $zr->entry_at( $pos, $next );
			while ( $e ) {
				if ( 0 === strpos( $e['name'], 'files/root/' ) ) {
					$rel = substr( $e['name'], 11 );
					$top = false === strpos( $rel, '/' ) ? $rel : strtok( $rel, '/' ) . '/';
					if ( '.htaccess' !== $top && ! isset( $flags[ rtrim( $top, '/' ) ] ) && ! in_array( $top, $root, true ) ) {
						$root[] = $top;
					}
				}
				$pos = (int) $next;
				$e   = $zr->entry_at( $pos, $next );
			}
			$zr->close();
		}
		$groups = array_values( array_diff( (array) $m['groups'], '1' === $this->post( 'dropins' ) ? array() : array( 'dropins' ) ) );
		$own    = (string) ( $m['site']['own'] ?? 'data-migrator' );
		$job    = array(
			'id'       => 'i-' . NM_INST_BACKUP,
			'type'     => 'install',
			'created'  => time(),
			'status'   => 'running',
			'step'     => 'extract',
			'ss'       => array(),
			'mode'     => '',
			'tag'      => substr( NM_Util::hex( 3 ), 0, 6 ),
			'lang'     => NM_Util::$lang,
			'archive'  => array( 'parts' => $this->parts() ),
			'manifest' => array(
				'prefix' => (string) $m['site']['prefix'],
				'fks'    => (array) ( $m['fks'] ?? array() ),
				'home'   => (string) $m['site']['home'],
				'admins' => (array) ( $m['admins'] ?? array() ),
			),
			'target'   => array(
				'dirs'         => $dirs,
				'prefix'       => $f['prefix'],
				'home'         => $f['url'],
				'siteurl'      => $f['url'] . ( isset( $sub ) ? $sub : '' ),
				'own'          => '',
				'basename'     => $own . '/data-migrator.php',
				'keep_options' => array(),
				'config'       => '',
			),
			'options'  => array(
				'db'          => true,
				'groups'      => $groups,
				'root_files'  => $root,
				'tables'      => null,
				'blog_public' => '1' === $this->post( 'blog_public' ) ? 1 : 0,
				'salts'       => false,
				'pairs'       => $pairs,
			),
			'work'     => array(
				'root'     => $work,
				'guard'    => $guard,
				'stage'    => $work . '/stage',
				'rollback' => $work . '/rollback',
				'db'       => $work . '/db',
				'list'     => $work . '/staged.txt',
				'journal'  => $work . '/journal.txt',
			),
			'db'       => self::seal( $f ),
			'consts'   => (array) ( $m['constants'] ?? array() ),
			'charset'  => (string) ( $m['site']['charset'] ?? 'utf8mb4' ),
			'collate'  => (string) ( $m['site']['collate'] ?? '' ),
			'report'   => array(),
		);
		NM_Util::write_state( $this->job_file(), $job );
		header( 'Location: ' . $this->self_url, true, 303 );
	}

	/** Database details for the job file (sealed with sodium when present). */
	private static function seal( array $f ): array {
		$plain = json_encode( $f );
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$key   = hash( 'sha256', NM_INST_KEYHASH . __FILE__, true );
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return array( 's' => base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) ) );
		}
		return array( 'p' => $plain );
	}

	private static function unseal( array $s ): array {
		if ( isset( $s['s'] ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$raw   = base64_decode( (string) $s['s'] );
			$key   = hash( 'sha256', NM_INST_KEYHASH . __FILE__, true );
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
			$j     = false === $plain ? null : json_decode( $plain, true );
			return is_array( $j ) ? $j : array();
		}
		$j = isset( $s['p'] ) ? json_decode( (string) $s['p'], true ) : null;
		return is_array( $j ) ? $j : array();
	}

	/* ------------------------------------------------------------- */

	private function step(): void {
		$file = $this->job_file();
		$lock = NM_Util::lock( $file . '.lock' );
		if ( ! $lock ) {
			NM_Page::json( array( 'status' => 'busy', 'text' => '…' ) );
			return;
		}
		NM_Util::prepare_request();
		$job = $this->job();
		if ( ! $job ) {
			NM_Util::unlock( $lock );
			NM_Page::json( array( 'status' => 'failed', 'text' => '' ) );
			return;
		}
		NM_Util::$log_file = $this->work() . '/install.log';
		$f                 = self::unseal( (array) $job['db'] );
		$db                = new NM_DB();
		if ( ! $db->connect( (string) ( $f['host'] ?? '' ), (string) ( $f['user'] ?? '' ), (string) ( $f['pass'] ?? '' ), (string) ( $f['name'] ?? '' ) ) ) {
			NM_Util::unlock( $lock );
			NM_Page::json( array( 'status' => 'running', 'text' => NM_Util::tf( 'Δεν έγινε σύνδεση με τη βάση: %s', $db->error ) ) );
			return;
		}
		$save = static function ( $j ) use ( $file ) {
			NM_Util::write_state( $file, $j );
		};
		if ( 'running' === $job['status'] || 'rolling-back' === $job['status'] ) {
			$r   = new NM_Restorer( $job, $db, $save );
			$out = $r->run( microtime( true ) + NM_Util::budget() );
			$job = $r->job;
			if ( 'done' === $out ) {
				$job['status'] = 'config';
				$save( $job );
			}
		}
		if ( 'config' === $job['status'] ) {
			$ok = $this->write_config( $job, $f );
			if ( ! $ok ) {
				$job['status'] = 'failed';
				$job['error']  = NM_Util::t( 'Δεν γράφτηκε το wp-config.php (λείπει το wp-config-sample.php ή ο φάκελος δεν γράφεται).' );
			} else {
				$r = new NM_Restorer( $job, $db, $save );
				$r->finalize();
				$job           = $r->job;
				$job['status'] = 'installed';
				if ( ! is_file( $this->dir . '/.htaccess' ) ) {
					@file_put_contents( $this->dir . '/.htaccess', "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase " . rtrim( (string) parse_url( (string) $job['target']['home'], PHP_URL_PATH ), '/' ) . "/\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . " . rtrim( (string) parse_url( (string) $job['target']['home'], PHP_URL_PATH ), '/' ) . "/index.php [L]\n</IfModule>\n# END WordPress\n" ); // phpcs:ignore
				}
			}
			$save( $job );
		}
		$db->close();
		NM_Util::unlock( $lock );
		$steps = NM_Restorer::STEPS;
		$idx   = (int) array_search( $job['step'], $steps, true );
		NM_Page::json(
			array(
				'status' => in_array( $job['status'], array( 'running', 'rolling-back', 'config' ), true ) ? 'running' : $job['status'],
				'pct'    => (int) round( 100 * $idx / ( count( $steps ) - 1 ) ),
				'text'   => (string) $job['step'],
			)
		);
	}

	/** wp-config.php from the sample, with our values and fresh salts. */
	private function write_config( array $job, array $f ): bool {
		$sample = $this->dir . '/wp-config-sample.php';
		if ( ! is_file( $sample ) || ! is_writable( $this->dir ) ) {
			return false;
		}
		$src  = (string) file_get_contents( $sample ); // phpcs:ignore
		$set  = static function ( string $src, string $name, string $value ): string {
			$re = '/define\(\s*([\'"])' . preg_quote( $name, '/' ) . '\1\s*,\s*([\'"])(?:(?!\2).)*\2\s*\)\s*;/';
			return (string) preg_replace_callback(
				$re,
				static function () use ( $name, $value ) {
					return 'define( ' . var_export( $name, true ) . ', ' . var_export( $value, true ) . ' );';
				},
				$src,
				1
			);
		};
		$src = $set( $src, 'DB_NAME', (string) $f['name'] );
		$src = $set( $src, 'DB_USER', (string) $f['user'] );
		$src = $set( $src, 'DB_PASSWORD', (string) $f['pass'] );
		$src = $set( $src, 'DB_HOST', (string) $f['host'] );
		$src = $set( $src, 'DB_CHARSET', '' !== (string) $job['charset'] ? (string) $job['charset'] : 'utf8mb4' );
		$src = $set( $src, 'DB_COLLATE', (string) $job['collate'] );
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $k ) {
			$src = $set( $src, $k, NM_Restorer::salt() );
		}
		$src = (string) preg_replace( '/\$table_prefix\s*=\s*([\'"])[^\'"]*\1\s*;/', '$table_prefix = ' . var_export( (string) $job['target']['prefix'], true ) . ';', $src, 1 );

		// Settings of the old site that are safe on any server.
		$extra = '';
		foreach ( array( 'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT', 'WP_POST_REVISIONS', 'AUTOSAVE_INTERVAL', 'EMPTY_TRASH_DAYS', 'MEDIA_TRASH', 'IMAGE_EDIT_OVERWRITE', 'DISALLOW_FILE_EDIT' ) as $c ) {
			if ( array_key_exists( $c, (array) $job['consts'] ) ) {
				$extra .= 'define( ' . var_export( $c, true ) . ', ' . var_export( $job['consts'][ $c ], true ) . " );\n";
			}
		}
		if ( '' !== $extra ) {
			$extra = "/* Data Migrator: settings of the old site. */\n" . $extra . "\n";
			$pos   = strpos( $src, '/* That\'s all, stop editing!' );
			$src   = false === $pos ? $src : substr( $src, 0, $pos ) . $extra . substr( $src, $pos );
		}
		return NM_Util::write_atomic( $this->dir . '/wp-config.php', $src );
	}

	/* ------------------------------------------------------------- */

	private function lang_links(): string {
		return '<p class="muted" style="float:right;margin-top:-44px"><a href="?lang=el">Ελληνικά</a> · <a href="?lang=en">English</a></p>';
	}

	private function page_msg( string $msg ): void {
		NM_Page::open( NM_Util::t( 'Data Migrator: installer' ) );
		echo '<p class="fail">' . NM_Page::h( $msg ) . '</p>'; // phpcs:ignore
		NM_Page::close();
	}

	private function page_login( string $err ): void {
		NM_Page::open( NM_Util::t( 'Data Migrator: installer' ) );
		echo $this->lang_links(); // phpcs:ignore
		if ( '' !== $err ) {
			echo '<p class="fail">' . NM_Page::h( $err ) . '</p>'; // phpcs:ignore
		}
		echo '<form method="post" action="' . NM_Page::h( $this->self_url ) . '"><label for="key">' . NM_Page::h( NM_Util::t( 'Κλειδί μίας χρήσης (το έδειξε το plugin όταν έφτιαξε τον installer)' ) ) . '</label><input type="password" id="key" name="key" autocomplete="off" autofocus> <button class="primary">' . NM_Page::h( NM_Util::t( 'Συνέχεια' ) ) . '</button></form>'; // phpcs:ignore
		NM_Page::close();
	}

	private function page_form( array $errors ): void {
		$parts = $this->parts();
		$m     = $this->manifest( $parts );
		NM_Page::open( NM_Util::t( 'Data Migrator: installer' ) );
		echo $this->lang_links(); // phpcs:ignore
		foreach ( $errors as $e ) {
			echo '<p class="fail">' . NM_Page::h( $e ) . '</p>'; // phpcs:ignore
		}
		echo '<h2>' . NM_Page::h( NM_Util::t( 'Έλεγχος' ) ) . '</h2><ul>'; // phpcs:ignore
		$fail = false;
		foreach ( $this->checks( $parts, $m ) as $c ) {
			$fail = $fail || 'fail' === $c[0];
			echo '<li class="' . NM_Page::h( $c[0] ) . '">' . NM_Page::h( $c[1] ) . '</li>'; // phpcs:ignore
		}
		echo '</ul>';
		if ( $fail || ! $m ) {
			NM_Page::close();
			return;
		}
		$v = static function ( string $k, string $d ) {
			return isset( $_POST[ $k ] ) && is_string( $_POST[ $k ] ) ? $_POST[ $k ] : $d; // phpcs:ignore
		};
		echo '<form method="post" action="' . NM_Page::h( $this->self_url ) . '"><input type="hidden" name="t" value="' . NM_Page::h( $this->csrf() ) . '"><input type="hidden" name="do" value="start">'; // phpcs:ignore
		echo '<h2>' . NM_Page::h( NM_Util::t( 'Βάση δεδομένων' ) ) . '</h2><div class="zone">'; // phpcs:ignore
		foreach ( array(
			'db_host' => array( NM_Util::t( 'Server βάσης' ), $v( 'db_host', 'localhost' ), 'text' ),
			'db_name' => array( NM_Util::t( 'Όνομα βάσης' ), $v( 'db_name', '' ), 'text' ),
			'db_user' => array( NM_Util::t( 'Χρήστης' ), $v( 'db_user', '' ), 'text' ),
			'db_pass' => array( NM_Util::t( 'Κωδικός' ), '', 'password' ),
			'prefix'  => array( NM_Util::t( 'Πρόθεμα πινάκων' ), $v( 'prefix', (string) $m['site']['prefix'] ), 'text' ),
		) as $k => $fld ) {
			echo '<label for="' . NM_Page::h( $k ) . '">' . NM_Page::h( $fld[0] ) . '</label><input type="' . NM_Page::h( $fld[2] ) . '" id="' . NM_Page::h( $k ) . '" name="' . NM_Page::h( $k ) . '" value="' . NM_Page::h( $fld[1] ) . '" autocomplete="off">'; // phpcs:ignore
		}
		echo '<label><input type="checkbox" name="overwrite" value="1"> ' . NM_Page::h( NM_Util::t( 'Αντικατάσταση πινάκων που υπάρχουν ήδη με το ίδιο πρόθεμα' ) ) . '</label></div>'; // phpcs:ignore
		echo '<h2>' . NM_Page::h( NM_Util::t( 'Νέο site' ) ) . '</h2><div class="zone"><label for="url">' . NM_Page::h( NM_Util::t( 'Διεύθυνση του site' ) ) . '</label><input type="url" id="url" name="url" value="' . NM_Page::h( $v( 'url', $this->default_url() ) ) . '">'; // phpcs:ignore
		echo '<p class="muted">' . NM_Page::h( NM_Util::tf( 'Παλιά διεύθυνση: %s. Όλες οι αναφορές της αλλάζουν αυτόματα.', (string) $m['site']['home'] ) ) . '</p>'; // phpcs:ignore
		echo '<label><input type="checkbox" name="blog_public" value="1"' . ( 1 === (int) ( $m['site']['blog_public'] ?? 1 ) ? ' checked' : '' ) . '> ' . NM_Page::h( NM_Util::t( 'Ορατό στις μηχανές αναζήτησης' ) ) . '</label>'; // phpcs:ignore
		echo '<label><input type="checkbox" name="dropins" value="1"> ' . NM_Page::h( NM_Util::t( 'Και τα drop-ins (object-cache.php, advanced-cache.php): μόνο αν ο νέος server έχει τις ίδιες υπηρεσίες cache' ) ) . '</label></div>'; // phpcs:ignore
		$flags = (array) ( $m['flags'] ?? array() );
		if ( $flags ) {
			echo '<h2>' . NM_Page::h( NM_Util::t( 'Αρχεία της ρίζας' ) ) . '</h2><div class="zone"><p class="muted">' . NM_Page::h( NM_Util::t( 'Αυτά τα αρχεία δεν είναι του WordPress. Επαναφέρονται μόνο αν τα τσεκάρεις.' ) ) . '</p>'; // phpcs:ignore
			foreach ( $flags as $n => $it ) {
				$n = (string) $n . ( 'dir' === ( $it['type'] ?? '' ) ? '/' : '' );
				echo '<label><input type="checkbox" name="root[]" value="' . NM_Page::h( $n ) . '"> <code>' . NM_Page::h( $n ) . '</code>' . ( ! empty( $it['php'] ) ? ' <span class="warn">' . NM_Page::h( NM_Util::t( 'PHP: έλεγξέ το πριν' ) ) . '</span>' : '' ) . '</label>'; // phpcs:ignore
			}
			echo '</div>';
		}
		echo '<p><button class="primary">' . NM_Page::h( NM_Util::t( 'Εγκατάσταση' ) ) . '</button></p></form>'; // phpcs:ignore
		NM_Page::close();
	}

	private function page_job( array $job ): void {
		NM_Page::open( NM_Util::t( 'Data Migrator: installer' ) );
		$fields = array( 't' => $this->csrf() );
		if ( in_array( $job['status'], array( 'running', 'rolling-back', 'config' ), true ) ) {
			echo '<p class="desc">' . NM_Page::h( NM_Util::t( 'Μην κλείσεις αυτή τη σελίδα μέχρι να τελειώσει.' ) ) . '</p><div class="bar"><span id="nm-bar"></span></div><p id="nm-text" class="muted">…</p>'; // phpcs:ignore
			echo '<form id="nm-reload" method="get" action="' . NM_Page::h( $this->self_url ) . '"></form>'; // phpcs:ignore
			NM_Page::stepper( $this->self_url, $fields );
			NM_Page::close();
			return;
		}
		if ( 'installed' === $job['status'] ) {
			echo '<p class="ok">' . NM_Page::h( NM_Util::t( 'Το site εγκαταστάθηκε.' ) ) . '</p>'; // phpcs:ignore
			NM_Page::report( $job );
			echo '<p><a class="button primary" href="' . NM_Page::h( (string) $job['target']['home'] . '/wp-login.php' ) . '">' . NM_Page::h( NM_Util::t( 'Σύνδεση στο site' ) ) . '</a></p>'; // phpcs:ignore
			echo '<p class="muted">' . NM_Page::h( NM_Util::t( 'Ο installer, τα κομμάτια του αντιγράφου και τα προσωρινά αρχεία σβήστηκαν.' ) ) . '</p>'; // phpcs:ignore
			NM_Page::close();
			$this->cleanup();
			return;
		}
		echo '<p class="fail">' . NM_Page::h( NM_Util::tf( 'Η εγκατάσταση σταμάτησε: %s', (string) ( $job['error'] ?? '' ) ) ) . '</p>'; // phpcs:ignore
		echo '<p class="muted">' . NM_Page::h( NM_Util::t( 'Διόρθωσε την αιτία και σβήσε το αρχείο .nm-installer-…-job.php για να ξεκινήσεις από την αρχή.' ) ) . '</p>'; // phpcs:ignore
		NM_Page::close();
	}

	/** Remove the archive, the work folder, the state and this file. */
	private function cleanup(): void {
		foreach ( $this->parts() as $p ) {
			@unlink( $p ); // phpcs:ignore
		}
		NM_Util::rrmdir( $this->work(), $this->dir );
		@unlink( $this->job_file() ); // phpcs:ignore
		@unlink( $this->job_file() . '.lock' ); // phpcs:ignore
		@unlink( $this->state_file ); // phpcs:ignore
		@unlink( __FILE__ ); // phpcs:ignore
	}
}

( new NM_Installer_App() )->run();

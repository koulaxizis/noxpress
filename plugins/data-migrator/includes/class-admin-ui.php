<?php
/**
 * NM_Admin_UI — menu, pages, AJAX steps and downloads (Bible §3–§8, §11).
 *
 * Submenus of the shared "Noxpress" menu (admin_menu priority 220),
 * capability manage_options:
 *  - nm-backups   «Αντίγραφα»: new backup, list, downloads, installer,
 *                 core check
 *  - nm-restore   «Επαναφορά»: upload, analysis (dry run), plan, start;
 *                 finalize / undo of the last restore
 *  - nm-settings  «DM Ρυθμίσεις»: storage folder, parts, exclusions,
 *                 keep backups on uninstall, language (without RS)
 *
 * State changes: POST on admin_init (page + nonce + capability, PRG,
 * message in the transient nm_aui_msg_{uid}). Long work: admin-ajax with
 * check_ajax_referer + capability, every parameter whitelisted.
 * Downloads: privileged GET (page + nonce + capability).
 */

defined( 'ABSPATH' ) || exit;

final class NM_Admin_UI {

	const CAP         = 'manage_options';
	const SLUG_MENU   = 'noxpress';
	const NONCE       = 'nm_admin';
	const NONCE_FIELD = 'nm_nonce';
	const NONCE_AJAX  = 'nm_ajax';
	const PAGES       = array( 'nm-backups', 'nm-restore', 'nm-settings' );

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 220 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'download' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NM_FILE ), array( __CLASS__, 'action_links' ) );
		foreach ( array( 'backup_start', 'step', 'cancel', 'upload', 'upload_done', 'analyze' ) as $a ) {
			add_action( 'wp_ajax_nm_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {
		// The shared top-level "Noxpress" is created by Noxpress Core (priority 5).
		add_submenu_page( self::SLUG_MENU, __( 'Data Migrator: Αντίγραφα', 'data-migrator' ), __( 'Αντίγραφα', 'data-migrator' ), self::CAP, 'nm-backups', array( __CLASS__, 'render' ) );
		add_submenu_page( self::SLUG_MENU, __( 'Data Migrator: Επαναφορά', 'data-migrator' ), __( 'Επαναφορά', 'data-migrator' ), self::CAP, 'nm-restore', array( __CLASS__, 'render' ) );
		add_submenu_page( self::SLUG_MENU, __( 'Data Migrator: Ρυθμίσεις', 'data-migrator' ), __( 'DM Ρυθμίσεις', 'data-migrator' ), self::CAP, 'nm-settings', array( __CLASS__, 'render' ) );
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=nm-backups' ) ) . '">' . esc_html__( 'Αντίγραφα', 'data-migrator' ) . '</a>' );
		return $links;
	}

	private static function page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	private static function q( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	public static function assets(): void {
		if ( ! in_array( self::page(), self::PAGES, true ) ) {
			return;
		}
		wp_enqueue_style( 'nm-admin', NM_URL . 'assets/admin.css', array(), NM_VERSION );
		wp_enqueue_script( 'nm-admin', NM_URL . 'assets/admin.js', array(), NM_VERSION, true );
		wp_localize_script(
			'nm-admin',
			'nmAdmin',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_AJAX ),
				'chunk'   => self::chunk_size(),
				'restore' => admin_url( 'admin.php?page=nm-restore' ),
				'backups' => admin_url( 'admin.php?page=nm-backups' ),
				'i18n'    => array(
					'error'    => __( 'Σφάλμα σύνδεσης. Νέα προσπάθεια σε λίγα δευτερόλεπτα…', 'data-migrator' ),
					'stopped'  => __( 'Η σύνδεση απέτυχε πολλές φορές. Ανανέωσε τη σελίδα: η εργασία συνεχίζει από εκεί που έμεινε.', 'data-migrator' ),
					'cancel'   => __( 'Να ακυρωθεί η εργασία;', 'data-migrator' ),
					'uploaded' => __( 'Ανέβηκε. Έλεγχος του αρχείου…', 'data-migrator' ),
					'nofile'   => __( 'Διάλεξε πρώτα το αρχείο ή τα κομμάτια του αντιγράφου.', 'data-migrator' ),
					'notzip'   => __( 'Μόνο αρχεία .zip του Data Migrator.', 'data-migrator' ),
				),
			)
		);
	}

	/** Upload chunk size: 2 MB, or less when the server allows less. */
	private static function chunk_size(): int {
		$max = min( NM_Util::ini_bytes( (string) ini_get( 'upload_max_filesize' ) ), NM_Util::ini_bytes( (string) ini_get( 'post_max_size' ) ) );
		$c   = 2097152;
		if ( $max > 0 ) {
			$c = (int) min( $c, max( 262144, $max - 65536 ) );
		}
		return $c;
	}

	/* =====================================================================
	 * Pages
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'data-migrator' ) );
		}
		$page   = self::page();
		$titles = array(
			'nm-backups'  => __( 'Data Migrator: Αντίγραφα', 'data-migrator' ),
			'nm-restore'  => __( 'Data Migrator: Επαναφορά', 'data-migrator' ),
			'nm-settings' => __( 'Data Migrator: Ρυθμίσεις', 'data-migrator' ),
		);
		echo '<div class="wrap nm-wrap"><h1>' . esc_html( $titles[ $page ] ?? 'Data Migrator' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Πλήρες αντίγραφο του site και επαναφορά εδώ ή σε άλλον server, με αλλαγή domain χωρίς χειροκίνητες διορθώσεις.', 'data-migrator' ) . '</p>';
		self::print_notices( self::take_msgs() );
		self::storage_notice();
		if ( 'nm-restore' === $page ) {
			self::render_restore();
		} elseif ( 'nm-settings' === $page ) {
			self::render_settings();
		} else {
			self::render_backups();
		}
		self::footer();
		echo '</div>';
	}

	/** Warnings about the storage folder. */
	private static function storage_notice(): void {
		if ( '' === NM_Storage::dir() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Δεν βρέθηκε φάκελος με δικαίωμα εγγραφής για τα αντίγραφα. Όρισε έναν στις Ρυθμίσεις.', 'data-migrator' ) . '</p></div>';
			return;
		}
		$st = get_option( 'nm_state', array() );
		$st = is_array( $st ) ? $st : array();
		if ( empty( $st['web_time'] ) ) {
			NM_Storage::web_test();
			$st = (array) get_option( 'nm_state', array() );
		}
		if ( 'exposed' === ( $st['web'] ?? '' ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Προσοχή: ο φάκελος των αντιγράφων κατεβαίνει από τον browser (ο server αγνοεί το .htaccess, π.χ. nginx). Όρισε φάκελο έξω από το web root στις Ρυθμίσεις ή ζήτησε από τον host να τον κλείσει (location … { deny all; }).', 'data-migrator' ) . '</p></div>';
		}
	}

	private static function form_open( string $action, string $page, string $confirm = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . $page ) ) . '"' . ( '' !== $confirm ? ' data-nm-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		echo '<input type="hidden" name="nm_action" value="' . esc_attr( $action ) . '">';
	}

	private static function when( int $ts ): string {
		return $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '—';
	}

	/* ----- Αντίγραφα --------------------------------------------------- */

	private static function group_labels(): array {
		return array(
			'core'       => __( 'Αρχεία του WordPress (core)', 'data-migrator' ),
			'root'       => __( 'Άλλα αρχεία της ρίζας', 'data-migrator' ),
			'uploads'    => __( 'Πολυμέσα (uploads)', 'data-migrator' ),
			'themes'     => __( 'Θέματα', 'data-migrator' ),
			'plugins'    => __( 'Plugins', 'data-migrator' ),
			'mu-plugins' => __( 'Must-use plugins', 'data-migrator' ),
			'languages'  => __( 'Μεταφράσεις', 'data-migrator' ),
			'dropins'    => __( 'Drop-ins (object-cache.php κ.λπ.)', 'data-migrator' ),
			'content'    => __( 'Λοιπά του wp-content', 'data-migrator' ),
		);
	}

	private static function render_backups(): void {
		global $wpdb;
		$set     = NM_Settings::get();
		$running = NM_Backup::running();
		$labels  = self::group_labels();

		echo '<h2 class="nm-h2">' . esc_html__( 'Νέο αντίγραφο', 'data-migrator' ) . '</h2><div class="nm-zone">';
		echo '<form id="nm-backup-form">';
		$scopes = array(
			'full'   => __( 'Πλήρες: βάση και όλα τα αρχεία', 'data-migrator' ),
			'db'     => __( 'Μόνο η βάση δεδομένων', 'data-migrator' ),
			'files'  => __( 'Μόνο τα αρχεία', 'data-migrator' ),
			'custom' => __( 'Επιλογή', 'data-migrator' ),
		);
		foreach ( $scopes as $k => $l ) {
			echo '<label style="display:block;margin:4px 0"><input type="radio" name="scope" value="' . esc_attr( $k ) . '"' . checked( 'full', $k, false ) . '> ' . esc_html( $l ) . '</label>';
		}
		echo '<div class="nm-custom" hidden><p class="nm-groups"><label><input type="checkbox" name="db" value="1" checked> ' . esc_html__( 'Βάση δεδομένων', 'data-migrator' ) . '</label>';
		foreach ( NM_Backup::GROUPS as $g ) {
			echo '<label><input type="checkbox" name="groups[]" value="' . esc_attr( $g ) . '" checked> ' . esc_html( $labels[ $g ] ) . '</label>';
		}
		echo '</p></div>';

		// Other tables of the database and unknown root folders.
		$db = NM_Backup::db();
		if ( $db ) {
			$other = array();
			foreach ( NM_Dumper::list_tables( $db ) as $name => $t ) {
				if ( 0 !== strpos( $name, $wpdb->prefix ) && 'table' === $t['type'] ) {
					$other[ $name ] = $t;
				}
			}
			$db->close();
			if ( $other ) {
				echo '<details><summary>' . esc_html( sprintf( /* translators: %d: number of tables */ __( 'Άλλοι πίνακες της βάσης, χωρίς το πρόθεμα του site (%d)', 'data-migrator' ), count( $other ) ) ) . '</summary><p class="description">' . esc_html__( 'Δεν είναι του WordPress. Μπαίνουν στο αντίγραφο μόνο αν τους τσεκάρεις.', 'data-migrator' ) . '</p><div class="nm-list">';
				foreach ( $other as $name => $t ) {
					echo '<label><input type="checkbox" name="extra_tables[]" value="' . esc_attr( $name ) . '"> <code>' . esc_html( $name ) . '</code> <span class="nm-muted">' . esc_html( NM_Util::size( (float) $t['size'] ) ) . '</span></label>';
				}
				echo '</div></details>';
			}
		}
		$dirs = array();
		foreach ( NM_Backup::root_items() as $n => $it ) {
			if ( 'dir' === $it['type'] && in_array( $it['kind'], array( 'unknown', 'host' ), true ) ) {
				$dirs[] = $n;
			}
		}
		if ( $dirs ) {
			echo '<details><summary>' . esc_html( sprintf( /* translators: %d: number of folders */ __( 'Άλλοι φάκελοι στη ρίζα του site (%d)', 'data-migrator' ), count( $dirs ) ) ) . '</summary><p class="description">' . esc_html__( 'Δεν είναι του WordPress (π.χ. άλλη εφαρμογή ή στατικά αρχεία). Μπαίνουν μόνο αν τους τσεκάρεις.', 'data-migrator' ) . '</p><div class="nm-list">';
			foreach ( $dirs as $n ) {
				echo '<label><input type="checkbox" name="root_dirs[]" value="' . esc_attr( $n ) . '"> <code>' . esc_html( $n ) . '/</code></label>';
			}
			echo '</div></details>';
		}

		echo '<p><label for="nm-part">' . esc_html__( 'Χωρισμός σε κομμάτια', 'data-migrator' ) . '</label> <select id="nm-part" name="part_mb">';
		foreach ( NM_Settings::PARTS as $p ) {
			echo '<option value="' . esc_attr( (string) $p ) . '"' . selected( (int) $set['part_mb'], $p, false ) . '>' . esc_html( 0 === $p ? __( 'Ένα αρχείο', 'data-migrator' ) : NM_Util::size( $p * 1048576.0 ) ) . '</option>';
		}
		echo '</select> <label for="nm-note">' . esc_html__( 'Σημείωση', 'data-migrator' ) . '</label> <input type="text" id="nm-note" name="note" maxlength="120"></p>';
		if ( $set['encrypt'] ) {
			$ok = NM_Crypt::available() && in_array( NM_Settings::password_source(), array( 'const', 'option' ), true );
			echo '<p class="' . ( $ok ? 'nm-ok' : 'nm-err' ) . '">' . esc_html( $ok ? __( 'Τα νέα αντίγραφα κρυπτογραφούνται (AES-256).', 'data-migrator' ) : __( 'Η κρυπτογράφηση είναι ενεργή αλλά ο κωδικός λείπει ή δεν διαβάζεται πια. Όρισέ τον ξανά στις Ρυθμίσεις.', 'data-migrator' ) ) . '</p>';
		}
		echo '<p><button type="submit" class="button button-primary"' . disabled( (bool) $running, true, false ) . '>' . esc_html__( 'Δημιουργία αντιγράφου', 'data-migrator' ) . '</button></p></form>';
		echo '<div class="nm-run' . ( $running && 'backup' === $running['type'] ? ' is-on' : '' ) . '" id="nm-backup-run" data-job="' . esc_attr( $running && 'backup' === $running['type'] ? (string) $running['id'] : '' ) . '"><div class="nm-progress"><span></span></div><p class="nm-text description">…</p><button type="button" class="button nm-cancel">' . esc_html__( 'Ακύρωση', 'data-migrator' ) . '</button></div>';
		if ( $running && 'backup' !== $running['type'] ) {
			echo '<p class="nm-warn">' . esc_html__( 'Τρέχει ήδη μια εργασία του Data Migrator. Περίμενε να τελειώσει ή ακύρωσέ την.', 'data-migrator' ) . '</p>';
		}
		echo '</div>';

		self::render_schedules( $scopes );

		// Backups.
		echo '<h2 class="nm-h2">' . esc_html__( 'Αποθηκευμένα αντίγραφα', 'data-migrator' ) . '</h2>';
		$all = NM_Storage::all();
		if ( ! $all ) {
			echo '<p class="description">' . esc_html__( 'Δεν υπάρχει ακόμα αντίγραφο.', 'data-migrator' ) . '</p>';
		} else {
			echo '<table class="widefat nm-table"><thead><tr><th>' . esc_html__( 'Ημερομηνία', 'data-migrator' ) . '</th><th>' . esc_html__( 'Περιεχόμενο', 'data-migrator' ) . '</th><th>' . esc_html__( 'Μέγεθος', 'data-migrator' ) . '</th><th>' . esc_html__( 'Site', 'data-migrator' ) . '</th><th>' . esc_html__( 'Ενέργειες', 'data-migrator' ) . '</th></tr></thead><tbody>';
			foreach ( $all as $m ) {
				self::backup_row( $m, $scopes );
			}
			echo '</tbody></table>';
		}
		echo '<p class="description">' . esc_html( sprintf( /* translators: 1: folder, 2: size */ __( 'Φάκελος: %1$s · Χώρος: %2$s', 'data-migrator' ), NM_Storage::dir(), NM_Util::size( NM_Storage::used() ) ) ) . '</p>';

		// Core check.
		echo '<h2 class="nm-h2">' . esc_html__( 'Έλεγχος αρχείων του WordPress', 'data-migrator' ) . '</h2><div class="nm-zone">';
		$c = get_transient( NM_Integrity::CACHE );
		if ( is_array( $c ) ) {
			self::integrity_html( $c );
		} else {
			echo '<p class="description">' . esc_html__( 'Συγκρίνει τα αρχεία του core με τα επίσημα checksums του WordPress.org (μία κλήση στο api.wordpress.org). Γίνεται και αυτόματα σε κάθε πλήρες αντίγραφο.', 'data-migrator' ) . '</p>';
		}
		self::form_open( 'integrity', 'nm-backups' );
		echo '<button class="button">' . esc_html__( 'Έλεγχος τώρα', 'data-migrator' ) . '</button></form></div>';

		// The key of a new installer, shown once.
		$key = get_transient( 'nm_inst_key_' . get_current_user_id() );
		if ( is_array( $key ) ) {
			delete_transient( 'nm_inst_key_' . get_current_user_id() );
		}
		if ( is_array( $key ) && NM_Storage::meta( (string) $key['id'] ) ) {
			$url = wp_nonce_url( admin_url( 'admin.php?page=nm-backups&nm_dl=installer&id=' . $key['id'] ), 'nm_dl_' . $key['id'] );
			echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Κλειδί του installer (φαίνεται μόνο τώρα):', 'data-migrator' ) . '</strong></p><p class="nm-codebox">' . esc_html( (string) $key['key'] ) . '</p><p>' . esc_html__( 'Ανέβασε στον νέο server, στον φάκελο του site, το nm-installer.php και όλα τα κομμάτια του αντιγράφου. Άνοιξε το nm-installer.php στον browser και δώσε αυτό το κλειδί. Ισχύει 7 ημέρες.', 'data-migrator' ) . '</p><p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Λήψη nm-installer.php', 'data-migrator' ) . '</a></p></div>';
		}
	}

	/* ----- Πρόγραμμα ---------------------------------------------------- */

	private static function render_schedules( array $scopes ): void {
		$all = NM_Schedule::all();
		$set = NM_Settings::get();
		echo '<h2 class="nm-h2">' . esc_html__( 'Πρόγραμμα αντιγράφων', 'data-migrator' ) . '</h2><div class="nm-zone">';
		echo '<p class="description">' . esc_html__( 'Αντίγραφα που γίνονται μόνα τους, στο παρασκήνιο, σε μικρά βήματα που δεν βαραίνουν το site. Μένουν στον ίδιο φάκελο με τα υπόλοιπα: κατέβασέ τα από τη λίστα, ώστε να έχεις αντίγραφο και έξω από τον server.', 'data-migrator' ) . '</p>';

		$late = false;
		foreach ( $all as $sc ) {
			if ( $sc['on'] && $sc['next'] > 0 && $sc['next'] < time() - HOUR_IN_SECONDS ) {
				$late = true;
			}
		}
		if ( $late && ! NM_Schedule::job() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Ένα πρόγραμμα δεν έτρεξε στην ώρα του. Το WP-Cron τρέχει μόνο όταν το site έχει επισκέψεις· σε ήσυχα sites ζήτησε από τον host πραγματικό cron, π.χ. κάθε 5 λεπτά:', 'data-migrator' ) . '</p><p><code>*/5 * * * * wget -q -O /dev/null ' . esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ) . '</code></p></div>';
		}

		if ( $all ) {
			echo '<table class="widefat nm-table"><thead><tr><th>' . esc_html__( 'Περιεχόμενο', 'data-migrator' ) . '</th><th>' . esc_html__( 'Πότε', 'data-migrator' ) . '</th><th>' . esc_html__( 'Επόμενο', 'data-migrator' ) . '</th><th>' . esc_html__( 'Τελευταίο', 'data-migrator' ) . '</th><th>' . esc_html__( 'Ενέργειες', 'data-migrator' ) . '</th></tr></thead><tbody>';
			foreach ( $all as $sc ) {
				echo '<tr><td>' . esc_html( $scopes[ $sc['scope'] ] ?? '' ) . '<br><span class="description">' . esc_html( sprintf( /* translators: %d: number of backups */ _n( 'Κρατά το τελευταίο %d', 'Κρατά τα τελευταία %d', (int) $sc['keep'], 'data-migrator' ), (int) $sc['keep'] ) ) . '</span></td>';
				echo '<td>' . esc_html( NM_Schedule::label( $sc ) ) . '</td>';
				echo '<td>' . ( $sc['on'] ? esc_html( self::when( (int) $sc['next'] ) ) : '<span class="nm-warn">' . esc_html__( 'Σε παύση', 'data-migrator' ) . '</span>' ) . '</td><td>';
				$last = (array) $sc['last'];
				if ( empty( $last['time'] ) ) {
					echo '—';
				} else {
					$cls = array(
						'done'    => 'nm-ok',
						'failed'  => 'nm-err',
						'skipped' => 'nm-warn',
					);
					$lbl = array(
						'done'    => __( 'Έγινε', 'data-migrator' ),
						'failed'  => __( 'Απέτυχε', 'data-migrator' ),
						'skipped' => __( 'Παραλείφθηκε', 'data-migrator' ),
					);
					echo '<span class="' . esc_attr( $cls[ $last['status'] ] ?? '' ) . '">' . esc_html( ( $lbl[ $last['status'] ] ?? '' ) . ' · ' . self::when( (int) $last['time'] ) ) . '</span>';
					if ( 'done' !== $last['status'] && '' !== (string) $last['msg'] ) {
						echo '<br><span class="description">' . esc_html( (string) $last['msg'] ) . '</span>';
					}
				}
				echo '</td><td class="nm-actions">';
				self::form_open( 'sched_run', 'nm-backups' );
				echo '<input type="hidden" name="id" value="' . esc_attr( $sc['id'] ) . '"><button class="button">' . esc_html__( 'Τρέξε τώρα', 'data-migrator' ) . '</button></form>';
				self::form_open( 'sched_delete', 'nm-backups', __( 'Να διαγραφεί το πρόγραμμα; Τα αντίγραφα που έχει κάνει μένουν.', 'data-migrator' ) );
				echo '<input type="hidden" name="id" value="' . esc_attr( $sc['id'] ) . '"><button class="button">' . esc_html__( 'Διαγραφή', 'data-migrator' ) . '</button></form>';
				echo '</td></tr><tr class="nm-sched-edit"><td colspan="5"><details><summary>' . esc_html__( 'Αλλαγή', 'data-migrator' ) . '</summary>';
				self::schedule_form( $sc );
				echo '</details></td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( count( $all ) < NM_Schedule::MAX ) {
			echo '<details class="nm-sched-new"' . ( $all ? '' : ' open' ) . '><summary>' . esc_html__( 'Νέο πρόγραμμα', 'data-migrator' ) . '</summary>';
			self::schedule_form( NM_Schedule::defaults() );
			echo '</details>';
		}
		$quota = (int) $set['sched_quota'];
		echo '<p class="description">' . esc_html( $quota > 0 ? sprintf( /* translators: %s: size */ __( 'Όριο χώρου για όλα τα προγραμματισμένα αντίγραφα: %s. Όταν το ξεπεράσουν, σβήνονται τα παλαιότερα προγραμματισμένα (ποτέ τα χειροκίνητα, ποτέ το νεότερο κάθε προγράμματος). Αλλάζει στις Ρυθμίσεις.', 'data-migrator' ), NM_Util::size( $quota * 1073741824.0 ) ) : __( 'Χωρίς όριο χώρου για τα προγραμματισμένα αντίγραφα (Ρυθμίσεις).', 'data-migrator' ) ) . '</p>';
		echo '</div>';
	}

	private static function schedule_form( array $sc ): void {
		$new = '' === (string) $sc['id'];
		self::form_open( 'sched_save', 'nm-backups' );
		echo '<div class="nm-sched-form">';
		if ( ! $new ) {
			echo '<input type="hidden" name="id" value="' . esc_attr( $sc['id'] ) . '">';
		}
		echo '<p>';
		foreach ( array(
			'db'   => __( 'Μόνο η βάση δεδομένων', 'data-migrator' ),
			'full' => __( 'Πλήρες: βάση και όλα τα αρχεία', 'data-migrator' ),
		) as $k => $l ) {
			echo '<label style="margin-right:16px"><input type="radio" name="scope" value="' . esc_attr( $k ) . '"' . checked( $sc['scope'], $k, false ) . '> ' . esc_html( $l ) . '</label>';
		}
		echo '</p><p><label>' . esc_html__( 'Συχνότητα', 'data-migrator' ) . ' <select name="freq">';
		foreach ( array(
			'daily'   => __( 'Κάθε μέρα', 'data-migrator' ),
			'weekly'  => __( 'Κάθε εβδομάδα', 'data-migrator' ),
			'monthly' => __( 'Κάθε μήνα', 'data-migrator' ),
		) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $sc['freq'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></label> <label data-freq="weekly">' . esc_html__( 'Ημέρα', 'data-migrator' ) . ' <select name="dow">';
		foreach ( NM_Schedule::days() as $d => $name ) {
			echo '<option value="' . esc_attr( (string) $d ) . '"' . selected( (int) $sc['dow'], $d, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></label> <label data-freq="monthly">' . esc_html__( 'Ημέρα του μήνα', 'data-migrator' ) . ' <input type="number" name="dom" min="1" max="28" value="' . esc_attr( (string) $sc['dom'] ) . '" style="width:64px"></label>';
		echo ' <label>' . esc_html__( 'Ώρα', 'data-migrator' ) . ' <input type="time" name="time" value="' . esc_attr( $sc['time'] ) . '"></label>';
		echo ' <label>' . esc_html__( 'Πόσα να κρατά', 'data-migrator' ) . ' <input type="number" name="keep" min="1" max="60" value="' . esc_attr( (string) $sc['keep'] ) . '" style="width:64px"></label></p>';
		echo '<p class="description">' . esc_html( sprintf( /* translators: %s: time zone */ __( 'Ζώνη ώρας του site: %s. Προτείνεται ώρα με λίγη κίνηση. Για τα πλήρη αντίγραφα αρκούν συνήθως 4, για τη βάση 7.', 'data-migrator' ), wp_timezone_string() ) ) . '</p>';
		echo '<p><label><input type="checkbox" name="on" value="1"' . checked( (bool) $sc['on'], true, false ) . '> ' . esc_html__( 'Ενεργό', 'data-migrator' ) . '</label></p>';
		echo '<p><button class="button button-primary">' . esc_html( $new ? __( 'Προσθήκη προγράμματος', 'data-migrator' ) : __( 'Αποθήκευση', 'data-migrator' ) ) . '</button></p></div></form>';
	}

	private static function backup_row( array $m, array $scopes ): void {
		$id      = (string) $m['id'];
		$status  = (string) ( $m['status'] ?? '' );
		$content = $scopes[ $m['scope'] ?? 'full' ] ?? '';
		if ( 'done' === $status ) {
			$content .= ' · ' . sprintf( /* translators: 1: files, 2: tables */ __( '%1$s αρχεία, %2$s πίνακες', 'data-migrator' ), number_format_i18n( (int) ( $m['files'] ?? 0 ) ), number_format_i18n( (int) ( $m['tables'] ?? 0 ) ) );
		}
		echo '<tr><td>' . esc_html( self::when( (int) $m['created'] ) );
		if ( ! empty( $m['sched'] ) ) {
			echo '<br><span class="nm-badge">' . esc_html__( 'Προγραμματισμένο', 'data-migrator' ) . '</span>';
		}
		if ( ! empty( $m['enc'] ) ) {
			echo '<br><span class="nm-badge">' . esc_html__( 'Κρυπτογραφημένο', 'data-migrator' ) . '</span>';
		}
		if ( ! empty( $m['note'] ) ) {
			echo '<br><span class="description">' . esc_html( (string) $m['note'] ) . '</span>';
		}
		echo '</td><td>' . esc_html( $content );
		if ( 'running' === $status ) {
			echo '<br><span class="nm-warn">' . esc_html__( 'Σε εξέλιξη', 'data-migrator' ) . '</span>';
		} elseif ( 'failed' === $status ) {
			echo '<br><span class="nm-err">' . esc_html( sprintf( /* translators: %s: error */ __( 'Απέτυχε: %s', 'data-migrator' ), (string) ( $m['error'] ?? '' ) ) ) . '</span>';
		}
		$flags = (array) ( $m['flags'] ?? array() );
		$php   = array();
		foreach ( $flags as $n => $it ) {
			if ( ! empty( $it['php'] ) ) {
				$php[] = $n;
			}
		}
		if ( $php ) {
			echo '<br><span class="nm-warn">' . esc_html( sprintf( /* translators: %s: file names */ __( 'Άγνωστα αρχεία PHP στη ρίζα: %s', 'data-migrator' ), implode( ', ', $php ) ) ) . '</span>';
		}
		if ( 'changed' === ( $m['integrity']['status'] ?? '' ) ) {
			echo '<br><span class="nm-warn">' . esc_html__( 'Ο έλεγχος του core βρήκε διαφορές', 'data-migrator' ) . '</span>';
		}
		echo '</td><td>' . esc_html( NM_Util::size( (float) ( $m['size'] ?? 0 ) ) );
		if ( count( (array) ( $m['parts'] ?? array() ) ) > 1 ) {
			echo '<br><span class="description">' . esc_html( sprintf( /* translators: %d: parts */ __( '%d κομμάτια', 'data-migrator' ), count( (array) $m['parts'] ) ) ) . '</span>';
		}
		echo '</td><td>' . esc_html( (string) ( $m['home'] ?? '' ) ) . '</td><td class="nm-actions">';
		if ( 'done' === $status ) {
			foreach ( (array) $m['parts'] as $i => $p ) {
				$url = wp_nonce_url( admin_url( 'admin.php?page=nm-backups&nm_dl=part&id=' . $id . '&n=' . $i ), 'nm_dl_' . $id );
				echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( count( (array) $m['parts'] ) > 1 ? sprintf( /* translators: %d: part number */ __( 'Λήψη %d', 'data-migrator' ), $i + 1 ) : __( 'Λήψη', 'data-migrator' ) ) . '</a>';
			}
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=nm-restore&backup=' . $id ) ) . '">' . esc_html__( 'Επαναφορά', 'data-migrator' ) . '</a>';
			if ( ! empty( $m['db'] ) && in_array( 'core', (array) ( $m['groups'] ?? array() ), true ) ) {
				self::form_open( 'installer', 'nm-backups' );
				echo '<input type="hidden" name="id" value="' . esc_attr( $id ) . '"><button class="button" title="' . esc_attr__( 'Για επαναφορά σε άδειο server, χωρίς WordPress', 'data-migrator' ) . '">' . esc_html__( 'Installer', 'data-migrator' ) . '</button></form>';
			}
		}
		if ( ! empty( $m['job'] ) && '' !== NM_Storage::log_file( (string) $m['job'] ) && is_file( NM_Storage::log_file( (string) $m['job'] ) ) ) {
			$url = wp_nonce_url( admin_url( 'admin.php?page=nm-backups&nm_dl=log&id=' . $id ), 'nm_dl_' . $id );
			echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Log', 'data-migrator' ) . '</a>';
		}
		if ( 'running' !== $status ) {
			self::form_open( 'delete', 'nm-backups', __( 'Να διαγραφεί οριστικά αυτό το αντίγραφο;', 'data-migrator' ) );
			echo '<input type="hidden" name="id" value="' . esc_attr( $id ) . '"><button class="button">' . esc_html__( 'Διαγραφή', 'data-migrator' ) . '</button></form>';
		}
		echo '</td></tr>';
	}

	private static function integrity_html( array $c ): void {
		if ( 'ok' === $c['status'] ) {
			echo '<p class="nm-ok">' . esc_html( sprintf( /* translators: 1: version, 2: date */ __( 'Τα αρχεία του WordPress %1$s είναι ίδια με τα επίσημα (%2$s).', 'data-migrator' ), (string) $c['version'], self::when( (int) $c['checked'] ) ) ) . '</p>';
			return;
		}
		if ( 'unknown' === $c['status'] ) {
			echo '<p class="nm-warn">' . esc_html__( 'Δεν έγινε έλεγχος: το api.wordpress.org δεν απάντησε ή δεν έχει checksums για αυτή την έκδοση.', 'data-migrator' ) . '</p>';
			return;
		}
		echo '<p class="nm-warn">' . esc_html( sprintf( /* translators: %s: date */ __( 'Βρέθηκαν διαφορές (%s). Έλεγξε τα αρχεία πριν τα πάρεις σε άλλο server.', 'data-migrator' ), self::when( (int) $c['checked'] ) ) ) . '</p><ul>';
		foreach ( array(
			'changed' => __( 'Αλλαγμένα', 'data-migrator' ),
			'missing' => __( 'Λείπουν', 'data-migrator' ),
			'extra'   => __( 'Άγνωστα αρχεία στο wp-admin / wp-includes', 'data-migrator' ),
		) as $k => $l ) {
			if ( ! empty( $c[ $k ] ) ) {
				echo '<li><strong>' . esc_html( $l ) . ':</strong> <code>' . esc_html( implode( '</code>, <code>', array_slice( (array) $c[ $k ], 0, 30 ) ) ) . '</code>' . ( count( (array) $c[ $k ] ) > 30 ? ' …' : '' ) . '</li>';
			}
		}
		echo '</ul>';
	}

	/* ----- Επαναφορά --------------------------------------------------- */

	private static function render_restore(): void {
		$open = NM_Post_Restore::open_restore();
		if ( $open ) {
			echo '<h2 class="nm-h2">' . esc_html__( 'Τελευταία επαναφορά', 'data-migrator' ) . '</h2><div class="nm-zone">';
			echo '<p>' . esc_html( sprintf( /* translators: %s: date */ __( 'Το site επαναφέρθηκε στις %s. Τα προηγούμενα αρχεία και πίνακες κρατήθηκαν για αναίρεση.', 'data-migrator' ), self::when( (int) ( $open['finished'] ?? $open['created'] ) ) ) ) . '</p>';
			$st = get_option( 'nm_state', array() );
			if ( is_array( $st ) && ! empty( $st['post']['done'] ) && (string) ( $st['post']['job'] ?? '' ) === (string) $open['id'] ) {
				echo '<p class="description">' . esc_html( sprintf( /* translators: %s: list */ __( 'Μετά την επαναφορά: cache, permalinks και WooCommerce (%s).', 'data-migrator' ), implode( ', ', (array) $st['post']['done'] ) ) ) . '</p>';
			}
			echo '<p class="nm-actions">';
			self::form_open( 'finalize', 'nm-restore', __( 'Να σβηστούν τα στοιχεία αναίρεσης; Μετά δεν γίνεται αναίρεση.', 'data-migrator' ) );
			echo '<input type="hidden" name="job" value="' . esc_attr( (string) $open['id'] ) . '"><button class="button button-primary">' . esc_html__( 'Οριστικοποίηση', 'data-migrator' ) . '</button></form>';
			self::form_open( 'undo', 'nm-restore', __( 'Να επιστρέψει το site όπως ήταν πριν την επαναφορά;', 'data-migrator' ) );
			echo '<input type="hidden" name="job" value="' . esc_attr( (string) $open['id'] ) . '"><button class="button">' . esc_html__( 'Αναίρεση επαναφοράς', 'data-migrator' ) . '</button></form></p></div>';
		}

		// A runner start prepared in the last request (code shown once).
		$go = get_transient( 'nm_go_' . get_current_user_id() );
		if ( is_array( $go ) ) {
			delete_transient( 'nm_go_' . get_current_user_id() );
			self::render_go( $go );
			return;
		}

		$analysis = self::q( 'analysis' );
		if ( '' !== $analysis ) {
			$an = NM_Storage::job( $analysis );
			if ( $an && 'analyze' === $an['type'] && 'done' === $an['status'] ) {
				self::render_plan( $an );
				return;
			}
		}

		echo '<h2 class="nm-h2">' . esc_html__( 'Ανέβασμα αντιγράφου', 'data-migrator' ) . '</h2><div class="nm-zone">';
		echo '<p class="description">' . esc_html__( 'Ένα αντίγραφο του Data Migrator από αυτό ή άλλο site. Αν έχει κομμάτια, διάλεξέ τα όλα μαζί. Ανεβαίνει σε μικρά κομμάτια, οπότε το όριο upload του server δεν μετράει.', 'data-migrator' ) . '</p>';
		echo '<p><input type="file" id="nm-upload-file" accept=".zip" multiple> <button type="button" class="button" id="nm-upload-go">' . esc_html__( 'Ανέβασμα', 'data-migrator' ) . '</button></p>';
		echo '<div class="nm-run" id="nm-upload-run"><div class="nm-progress"><span></span></div><p class="nm-text description">…</p></div></div>';

		echo '<h2 class="nm-h2">' . esc_html__( 'Επιλογή αντιγράφου', 'data-migrator' ) . '</h2><div class="nm-zone">';
		$all = array_filter(
			NM_Storage::all(),
			static function ( $m ) {
				return 'done' === ( $m['status'] ?? '' );
			}
		);
		if ( ! $all ) {
			echo '<p class="description">' . esc_html__( 'Δεν υπάρχει ακόμα αντίγραφο.', 'data-migrator' ) . '</p></div>';
			return;
		}
		$pick = self::q( 'backup' );
		echo '<p><select id="nm-pick">';
		foreach ( $all as $m ) {
			echo '<option value="' . esc_attr( (string) $m['id'] ) . '" data-enc="' . ( empty( $m['enc'] ) ? '0' : '1' ) . '"' . selected( $pick, (string) $m['id'], false ) . '>' . esc_html( self::when( (int) $m['created'] ) . ' · ' . (string) ( $m['home'] ?? '' ) . ' · ' . NM_Util::size( (float) ( $m['size'] ?? 0 ) ) . ( empty( $m['note'] ) ? '' : ' · ' . $m['note'] ) . ( empty( $m['enc'] ) ? '' : ' · ' . __( 'Κρυπτογραφημένο', 'data-migrator' ) ) ) . '</option>';
		}
		echo '</select> <button type="button" class="button button-primary" id="nm-analyze-go">' . esc_html__( 'Ανάλυση', 'data-migrator' ) . '</button></p>';
		echo '<p id="nm-pick-pw-row" hidden><label for="nm-pick-pw">' . esc_html__( 'Κωδικός του αντιγράφου', 'data-migrator' ) . '</label> <input type="password" id="nm-pick-pw" autocomplete="off" class="regular-text"> <span class="description">' . esc_html__( 'Κενό: ο κωδικός από τις Ρυθμίσεις.', 'data-migrator' ) . '</span></p>';
		echo '<p class="description">' . esc_html__( 'Η ανάλυση δεν αλλάζει τίποτα: ελέγχει το αρχείο και τον server, μετρά τι θα αλλάξει και δείχνει το πλάνο πριν την επαναφορά.', 'data-migrator' ) . '</p>';
		echo '<div class="nm-run" id="nm-analyze-run"><div class="nm-progress"><span></span></div><p class="nm-text description">…</p></div></div>';
	}

	private static function render_plan( array $an ): void {
		$res    = $an['result'];
		$m      = $res['manifest'];
		$d      = NM_Restore_Prep::defaults( $res );
		$labels = self::group_labels();
		$same   = NM_Restore_Prep::same_site( $m );

		echo '<h2 class="nm-h2">' . esc_html__( 'Πλάνο επαναφοράς', 'data-migrator' ) . '</h2>';
		echo '<div class="nm-zone"><p>' . esc_html( sprintf( /* translators: 1: site, 2: date, 3: WP version */ __( 'Αντίγραφο του %1$s από %2$s (WordPress %3$s).', 'data-migrator' ), (string) $m['site']['home'], self::when( (int) strtotime( (string) $m['created'] ) ), (string) $m['versions']['wp'] ) ) . '</p>';
		if ( $same ) {
			echo '<p class="description">' . esc_html__( 'Είναι αντίγραφο αυτού του site: επαναφορά χωρίς αλλαγή διευθύνσεων.', 'data-migrator' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html( sprintf( /* translators: %d: count */ __( 'Μεταφορά: η βάση έχει %d αναφορές στην παλιά διεύθυνση ή διαδρομή, που θα αλλάξουν.', 'data-migrator' ), (int) ( $res['repl'] ?? 0 ) ) ) . '</p>';
		}
		echo '<ul class="nm-checklist">';
		foreach ( NM_Restore_Prep::checks( $res ) as $c ) {
			$cls = array(
				'ok'   => 'nm-ok',
				'info' => 'nm-info',
				'warn' => 'nm-warn',
				'fail' => 'nm-err',
			);
			echo '<li class="' . esc_attr( $cls[ $c[0] ] ?? 'nm-info' ) . '">' . esc_html( $c[1] ) . '</li>';
		}
		echo '</ul></div>';

		self::form_open( 'restore_start', 'nm-restore' );
		echo '<input type="hidden" name="analysis" value="' . esc_attr( (string) $an['id'] ) . '">';

		// Address.
		echo '<h2 class="nm-h2">' . esc_html__( 'Διεύθυνση', 'data-migrator' ) . '</h2><div class="nm-zone nm-pairs">';
		echo '<p><label for="nm-home">' . esc_html__( 'Διεύθυνση του site μετά την επαναφορά', 'data-migrator' ) . '</label><br><input type="url" class="nm-url" id="nm-home" name="home" value="' . esc_attr( $d['home'] ) . '"></p>';
		echo '<p class="description">' . esc_html( sprintf( /* translators: %s: old URL */ __( 'Παλιά διεύθυνση: %s. Αλλάζουν και οι μορφές της: http / https, με και χωρίς www, //, JSON (\/) και URL-encoded, και η διαδρομή των αρχείων στον server. Το guid των άρθρων μένει ως έχει.', 'data-migrator' ), (string) $m['site']['home'] ) ) . '</p>';
		if ( ! empty( $res['local'] ) ) {
			echo '<p><strong>' . esc_html__( 'Διευθύνσεις τοπικής ανάπτυξης στη βάση', 'data-migrator' ) . '</strong></p>';
			foreach ( (array) $res['local'] as $u => $n ) {
				echo '<label style="display:block"><input type="checkbox" name="local[]" value="' . esc_attr( (string) $u ) . '"> <code>' . esc_html( (string) $u ) . '</code> → ' . esc_html__( 'νέα διεύθυνση', 'data-migrator' ) . ' <span class="description">(' . esc_html( sprintf( /* translators: %d: count */ _n( '%d εμφάνιση', '%d εμφανίσεις', (int) $n, 'data-migrator' ), (int) $n ) ) . ')</span></label>';
			}
			echo '<p class="description">' . esc_html__( 'Συνήθως είναι εικόνες ή ρυθμίσεις θέματος που έμειναν από την ανάπτυξη. Τσέκαρε όσες πρέπει να δείχνουν στο νέο site.', 'data-migrator' ) . '</p>';
		}
		if ( ! $same ) {
			echo '<label style="display:block"><input type="checkbox" name="bare" value="1"> ' . esc_html( sprintf( /* translators: %s: old host */ __( 'Και το σκέτο domain %s (π.χ. σε emails όπως info@…)', 'data-migrator' ), NM_Util::bare_host( (string) $m['site']['home'] ) ) ) . '</label>';
		}
		echo '<p><label for="nm-extra">' . esc_html__( 'Επιπλέον αντικαταστάσεις, μία ανά γραμμή: παλιό => νέο', 'data-migrator' ) . '</label><br><textarea id="nm-extra" name="extra" rows="3"></textarea></p></div>';

		// What.
		echo '<h2 class="nm-h2">' . esc_html__( 'Τι επαναφέρεται', 'data-migrator' ) . '</h2><div class="nm-zone"><p class="nm-groups">';
		if ( ! empty( $m['db'] ) ) {
			echo '<label><input type="checkbox" name="db" value="1" checked> ' . esc_html( sprintf( /* translators: 1: tables, 2: rows */ __( 'Βάση δεδομένων (%1$d πίνακες, %2$s γραμμές)', 'data-migrator' ), count( (array) ( $res['tables'] ?? array() ) ), number_format_i18n( (int) ( $res['rows'] ?? 0 ) ) ) ) . '</label>';
		}
		foreach ( (array) $res['groups'] as $g => $info ) {
			if ( 'root' === $g || ! isset( $labels[ $g ] ) ) {
				continue;
			}
			echo '<label><input type="checkbox" name="groups[]" value="' . esc_attr( (string) $g ) . '"' . checked( in_array( $g, $d['groups'], true ), true, false ) . '> ' . esc_html( $labels[ $g ] ) . ' <span class="description">(' . esc_html( number_format_i18n( (int) $info['files'] ) . ', ' . NM_Util::size( (float) $info['bytes'] ) ) . ')</span></label>';
		}
		echo '</p>';
		if ( ! empty( $res['dropins'] ) && ! $same ) {
			echo '<p class="description">' . esc_html( sprintf( /* translators: %s: files */ __( 'Τα drop-ins (%s) μένουν έξω σε μεταφορά: συνδέονται με υπηρεσίες cache του παλιού server.', 'data-migrator' ), implode( ', ', (array) $res['dropins'] ) ) ) . '</p>';
		}
		if ( ! empty( $res['root'] ) ) {
			echo '<input type="hidden" name="groups[]" value="root"><p><strong>' . esc_html__( 'Αρχεία και φάκελοι της ρίζας', 'data-migrator' ) . '</strong></p><div class="nm-list">';
			$flags = (array) ( $m['flags'] ?? array() );
			foreach ( (array) $res['root'] as $name => $size ) {
				$base = rtrim( (string) $name, '/' );
				$note = '';
				if ( ! empty( $flags[ $base ]['php'] ) ) {
					$note = ' <span class="nm-warn">' . esc_html__( 'άγνωστο PHP: έλεγξέ το πριν', 'data-migrator' ) . '</span>';
				} elseif ( isset( $flags[ $base ] ) ) {
					$note = ' <span class="description">' . esc_html__( 'δεν είναι του WordPress', 'data-migrator' ) . '</span>';
				} elseif ( '.htaccess' === $base && ! $same ) {
					$note = ' <span class="description">' . esc_html__( 'του παλιού server', 'data-migrator' ) . '</span>';
				}
				echo '<label><input type="checkbox" name="root_files[]" value="' . esc_attr( (string) $name ) . '"' . checked( in_array( (string) $name, $d['root_files'], true ), true, false ) . '> <code>' . esc_html( (string) $name ) . '</code>' . $note . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $note escaped above.
			}
			echo '</div>';
		}
		if ( ! empty( $res['tables'] ) ) {
			echo '<details><summary>' . esc_html__( 'Επιλογή πινάκων', 'data-migrator' ) . '</summary><p class="description">' . esc_html__( 'Για επαναφορά μόνο μερικών πινάκων (π.χ. μόνο τις παραγγελίες). Ένας πίνακας που μένει έξω κρατά τα σημερινά του δεδομένα.', 'data-migrator' ) . '</p><input type="hidden" name="pick_tables" value="1"><div class="nm-list">';
			foreach ( (array) $res['tables'] as $t ) {
				echo '<label><input type="checkbox" name="tables[]" value="' . esc_attr( (string) $t ) . '" checked> <code>' . esc_html( (string) $t ) . '</code></label>';
			}
			echo '</div></details>';
		}
		echo '</div>';

		// Options.
		echo '<h2 class="nm-h2">' . esc_html__( 'Επιλογές', 'data-migrator' ) . '</h2><div class="nm-zone">';
		echo '<label style="display:block"><input type="checkbox" name="blog_public" value="1"' . checked( 1, (int) $d['blog_public'], false ) . '> ' . esc_html__( 'Ορατό στις μηχανές αναζήτησης', 'data-migrator' ) . '</label>';
		echo '<label style="display:block"><input type="checkbox" name="salts" value="1"> ' . esc_html__( 'Νέα salts στο wp-config.php (αποσυνδέει όλους τους χρήστες· χρήσιμο μετά από παραβίαση)', 'data-migrator' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Το wp-config.php αυτού του site μένει ως έχει. Το Data Migrator δεν αντικαθίσταται ποτέ από το αντίγραφο.', 'data-migrator' ) . '</p></div>';

		echo '<p><button class="button button-primary">' . esc_html__( 'Προετοιμασία επαναφοράς', 'data-migrator' ) . '</button> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=nm-restore' ) ) . '">' . esc_html__( 'Άκυρο', 'data-migrator' ) . '</a></p></form>';
	}

	/** The recovery code and the button that opens the runner. */
	private static function render_go( array $go ): void {
		$undo = ! empty( $go['undo'] );
		echo '<h2 class="nm-h2">' . esc_html( $undo ? __( 'Αναίρεση επαναφοράς', 'data-migrator' ) : __( 'Έναρξη επαναφοράς', 'data-migrator' ) ) . '</h2><div class="nm-zone">';
		echo '<p><strong>' . esc_html__( 'Κωδικός ανάκτησης (φαίνεται μόνο τώρα, κράτησέ τον):', 'data-migrator' ) . '</strong></p><p class="nm-codebox">' . esc_html( implode( '-', str_split( (string) $go['token'], 8 ) ) ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Η επαναφορά τρέχει σε δική της σελίδα, έξω από τη διαχείριση, γιατί αλλάζει τη βάση και τους χρήστες. Αν η σελίδα κλείσει, άνοιξε τον παρακάτω σύνδεσμο και δώσε τον κωδικό: συνεχίζει από εκεί που έμεινε.', 'data-migrator' ) . '</p>';
		echo '<p><code>' . esc_html( NM_URL . 'runner.php' ) . '</code> · <code>' . esc_html( (string) $go['job'] ) . '</code></p>';
		if ( ! $undo ) {
			echo '<p class="nm-warn">' . esc_html__( 'Μετά την επαναφορά οι χρήστες είναι αυτοί του αντιγράφου: συνδέεσαι με λογαριασμό του αντιγράφου.', 'data-migrator' ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( NM_URL . 'runner.php' ) . '"><input type="hidden" name="job" value="' . esc_attr( (string) $go['job'] ) . '"><input type="hidden" name="token" value="' . esc_attr( (string) $go['token'] ) . '"><input type="hidden" name="do" value="page">';
		echo '<button class="button button-primary">' . esc_html( $undo ? __( 'Αναίρεση τώρα', 'data-migrator' ) : __( 'Έναρξη επαναφοράς', 'data-migrator' ) ) . '</button></form></div>';
		echo '<details><summary>' . esc_html__( 'Αν η σελίδα κλείσει', 'data-migrator' ) . '</summary><form method="post" action="' . esc_url( NM_URL . 'runner.php' ) . '"><input type="hidden" name="job" value="' . esc_attr( (string) $go['job'] ) . '"><input type="hidden" name="do" value="page"><p><input type="text" name="token" placeholder="' . esc_attr__( 'Κωδικός ανάκτησης', 'data-migrator' ) . '" autocomplete="off"> <button class="button">' . esc_html__( 'Συνέχεια', 'data-migrator' ) . '</button></p></form></details>';
	}

	/* ----- Ρυθμίσεις --------------------------------------------------- */

	private static function render_settings(): void {
		$s  = NM_Settings::get();
		$st = get_option( 'nm_state', array() );
		$st = is_array( $st ) ? $st : array();
		self::form_open( 'settings', 'nm-settings' );
		echo '<h2 class="nm-h2">' . esc_html__( 'Χώρος αποθήκευσης', 'data-migrator' ) . '</h2><div class="nm-zone">';
		echo '<p><label for="nm-storage">' . esc_html__( 'Φάκελος των αντιγράφων', 'data-migrator' ) . '</label><br><input type="text" class="nm-url" id="nm-storage" name="storage" value="' . esc_attr( NM_Storage::dir() ) . '"></p>';
		echo '<p class="description">' . esc_html__( 'Καλύτερα έξω από το web root. Αν τον αλλάξεις, τα παλιά αντίγραφα μένουν στον παλιό φάκελο (μετέφερέ τα με το χέρι αν τα θέλεις).', 'data-migrator' ) . '</p>';
		$web = array(
			'safe'    => array( 'nm-ok', __( 'Ο φάκελος δεν κατεβαίνει από τον browser.', 'data-migrator' ) ),
			'outside' => array( 'nm-ok', __( 'Ο φάκελος είναι έξω από το web root.', 'data-migrator' ) ),
			'exposed' => array( 'nm-err', __( 'Ο φάκελος κατεβαίνει από τον browser!', 'data-migrator' ) ),
			'unknown' => array( 'nm-warn', __( 'Ο έλεγχος δεν ολοκληρώθηκε (το site δεν απάντησε σε αίτημα προς τον εαυτό του).', 'data-migrator' ) ),
		);
		$w   = $web[ $st['web'] ?? 'unknown' ] ?? $web['unknown'];
		echo '<p class="' . esc_attr( $w[0] ) . '">' . esc_html( $w[1] ) . ( empty( $st['web_time'] ) ? '' : ' <span class="description">(' . esc_html( self::when( (int) $st['web_time'] ) ) . ')</span>' ) . '</p>';
		echo '<p><label for="nm-part-s">' . esc_html__( 'Χωρισμός σε κομμάτια (προεπιλογή)', 'data-migrator' ) . '</label> <select id="nm-part-s" name="part_mb">';
		foreach ( NM_Settings::PARTS as $p ) {
			echo '<option value="' . esc_attr( (string) $p ) . '"' . selected( (int) $s['part_mb'], $p, false ) . '>' . esc_html( 0 === $p ? __( 'Ένα αρχείο', 'data-migrator' ) : NM_Util::size( $p * 1048576.0 ) ) . '</option>';
		}
		echo '</select></p>';
		echo '<label style="display:block"><input type="checkbox" name="keep" value="1"' . checked( $s['keep'], true, false ) . '> ' . esc_html__( 'Να μείνουν τα αντίγραφα όταν διαγραφεί το plugin', 'data-migrator' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Από προεπιλογή σβήνονται: ένα ξεχασμένο αντίγραφο έχει όλη τη βάση του site.', 'data-migrator' ) . '</p></div>';

		self::render_crypt( $s );

		echo '<h2 class="nm-h2">' . esc_html__( 'Προγραμματισμένα αντίγραφα', 'data-migrator' ) . '</h2><div class="nm-zone">';
		echo '<p><label for="nm-quota">' . esc_html__( 'Όριο χώρου (GB, 0 = χωρίς όριο)', 'data-migrator' ) . '</label> <input type="number" id="nm-quota" name="sched_quota" min="0" max="10000" value="' . esc_attr( (string) $s['sched_quota'] ) . '" style="width:90px"></p>';
		echo '<p class="description">' . esc_html__( 'Για όλα τα προγραμματισμένα μαζί. Όταν το ξεπεράσουν, σβήνονται τα παλαιότερα προγραμματισμένα. Τα χειροκίνητα δεν σβήνονται ποτέ.', 'data-migrator' ) . '</p>';
		echo '<p><label for="nm-mail">' . esc_html__( 'Email στον διαχειριστή', 'data-migrator' ) . '</label> <select id="nm-mail" name="sched_mail">';
		foreach ( array(
			'fail' => __( 'Μόνο όταν κάτι δεν πάει καλά', 'data-migrator' ),
			'all'  => __( 'Μετά από κάθε αντίγραφο', 'data-migrator' ),
			'none' => __( 'Ποτέ', 'data-migrator' ),
		) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['sched_mail'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></p><p class="description">' . esc_html( sprintf( /* translators: %s: email address */ __( 'Στο %s (Ρυθμίσεις › Γενικά). Μόνο κείμενο και σύνδεσμος προς αυτή τη σελίδα, ποτέ το ίδιο το αντίγραφο.', 'data-migrator' ), (string) get_option( 'admin_email' ) ) ) . '</p></div>';

		echo '<h2 class="nm-h2">' . esc_html__( 'Εξαιρέσεις', 'data-migrator' ) . '</h2><div class="nm-zone">';
		echo '<p><textarea name="exclude" rows="5" style="width:100%;max-width:720px">' . esc_textarea( $s['exclude'] ) . '</textarea></p>';
		echo '<p class="description">' . esc_html__( 'Μία ανά γραμμή. Χωρίς / ταιριάζει στο όνομα (π.χ. *.mp4), με / στη διαδρομή μέσα στην ομάδα (π.χ. uploads/2019/*). Πάντα έξω: cache, αντίγραφα άλλων plugins, .git, node_modules, *.log.', 'data-migrator' ) . '</p></div>';

		if ( ! NM_Lang::rs_active() ) {
			$cur = (string) get_user_meta( get_current_user_id(), 'rs_lang', true );
			echo '<h2 class="nm-h2">' . esc_html__( 'Γλώσσα', 'data-migrator' ) . '</h2><div class="nm-zone">';
			foreach ( array(
				''   => __( 'Όπως η γλώσσα του λογαριασμού', 'data-migrator' ),
				'el' => 'Ελληνικά',
				'en' => 'English',
			) as $k => $l ) {
				echo '<label style="display:inline-block;margin-right:16px"><input type="radio" name="lang" value="' . esc_attr( $k ) . '"' . checked( in_array( $cur, array( 'el', 'en' ), true ) ? $cur : '', $k, false ) . '> ' . esc_html( $l ) . '</label>';
			}
			echo '<p class="description">' . esc_html__( 'Ισχύει για όλα τα plugins της σουίτας Noxpress.', 'data-migrator' ) . '</p></div>';
		}
		echo '<p><button class="button button-primary">' . esc_html__( 'Αποθήκευση', 'data-migrator' ) . '</button></p></form>';

		self::form_open( 'web_test', 'nm-settings' );
		echo '<p><button class="button">' . esc_html__( 'Έλεγχος του φακέλου μέσω HTTP', 'data-migrator' ) . '</button></p></form>';
	}

	/** Settings: encryption (off by default). */
	private static function render_crypt( array $s ): void {
		$have = NM_Crypt::available();
		$src  = NM_Settings::password_source();
		echo '<h2 class="nm-h2">' . esc_html__( 'Κρυπτογράφηση', 'data-migrator' ) . '</h2><div class="nm-zone">';
		if ( ! $have ) {
			echo '<p class="nm-warn">' . esc_html__( 'Δεν γίνεται σε αυτόν τον server: η PHP δεν έχει την επέκταση OpenSSL.', 'data-migrator' ) . '</p>';
		}
		echo '<label style="display:block"><input type="checkbox" name="encrypt" value="1"' . checked( $s['encrypt'], true, false ) . disabled( ! $have, true, false ) . '> ' . esc_html__( 'Κρυπτογράφηση των νέων αντιγράφων με κωδικό (AES-256)', 'data-migrator' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Για όλα τα νέα αντίγραφα, και τα προγραμματισμένα. Ανοίγουν με τον κωδικό στην επαναφορά, στον installer, στο 7-Zip, στο WinZip και στο Archive Utility του macOS. Τα ονόματα των αρχείων μέσα στο ZIP φαίνονται, τα περιεχόμενα όχι. Τα αντίγραφα γίνονται λίγο πιο αργά.', 'data-migrator' ) . '</p>';
		echo '<p class="nm-warn"><strong>' . esc_html__( 'Αν χαθεί ο κωδικός, τα κρυπτογραφημένα αντίγραφα δεν ανοίγουν με κανέναν τρόπο. Κράτα τον σε password manager, έξω από αυτό το site.', 'data-migrator' ) . '</strong></p>';
		if ( 'const' === $src ) {
			echo '<p class="nm-ok">' . esc_html__( 'Ο κωδικός ορίζεται στο wp-config.php (NM_BACKUP_PASSWORD).', 'data-migrator' ) . '</p></div>';
			return;
		}
		$state = array(
			'option'     => array( 'nm-ok', __( 'Υπάρχει αποθηκευμένος κωδικός (κρυπτογραφημένος με τα κλειδιά του wp-config.php).', 'data-migrator' ) ),
			'none'       => array( 'description', __( 'Δεν έχει οριστεί κωδικός.', 'data-migrator' ) ),
			'unreadable' => array( 'nm-err', __( 'Ο αποθηκευμένος κωδικός δεν διαβάζεται πια (άλλαξαν τα κλειδιά του wp-config.php). Γράψε τον ξανά.', 'data-migrator' ) ),
		);
		echo '<p class="' . esc_attr( $state[ $src ][0] ) . '">' . esc_html( $state[ $src ][1] ) . '</p>';
		echo '<p><label for="nm-pw">' . esc_html__( 'Νέος κωδικός', 'data-migrator' ) . '</label><br><input type="text" class="regular-text" id="nm-pw" name="nm_pw" minlength="' . esc_attr( (string) NM_Crypt::MIN ) . '" autocomplete="off" spellcheck="false"' . disabled( ! $have, true, false ) . '> <button type="button" class="button" id="nm-pw-gen"' . disabled( ! $have, true, false ) . '>' . esc_html__( 'Δημιουργία κωδικού', 'data-migrator' ) . '</button></p>';
		echo '<p class="description">' . esc_html( sprintf( /* translators: %d: characters */ __( 'Τουλάχιστον %d χαρακτήρες. Κενό: μένει ο τωρινός. Ένας νέος κωδικός ισχύει για τα επόμενα αντίγραφα· τα παλιά ανοίγουν με τον κωδικό που είχαν.', 'data-migrator' ), NM_Crypt::MIN ) ) . '</p>';
		echo '<label style="display:block"><input type="checkbox" name="pw_saved" value="1"> ' . esc_html__( 'Κράτησα τον νέο κωδικό σε ασφαλές μέρος', 'data-migrator' ) . '</label>';
		if ( 'none' !== $src ) {
			echo '<label style="display:block"><input type="checkbox" name="pw_forget" value="1"> ' . esc_html__( 'Να σβηστεί ο αποθηκευμένος κωδικός (η κρυπτογράφηση σταματά)', 'data-migrator' ) . '</label>';
		}
		echo '</div>';
	}

	/* =====================================================================
	 * POST actions (PRG)
	 * =================================================================== */

	/**
	 * The password fields of the settings form. Returns whether
	 * encryption is on: never on without a password that can be read.
	 */
	private static function save_crypt( array &$msgs ): bool {
		$want = '1' === self::post( 'encrypt' );
		$src  = NM_Settings::password_source();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in route(); a password is kept as typed.
		$new = isset( $_POST['nm_pw'] ) && is_string( $_POST['nm_pw'] ) ? (string) wp_unslash( $_POST['nm_pw'] ) : '';
		if ( 'const' !== $src && '' !== $new ) {
			if ( strlen( $new ) < NM_Crypt::MIN || preg_match( '/[\x00-\x1f]/', $new ) ) {
				$msgs[] = self::msg( 'error', sprintf( /* translators: %d: characters */ __( 'Ο κωδικός θέλει τουλάχιστον %d χαρακτήρες. Δεν αποθηκεύτηκε.', 'data-migrator' ), NM_Crypt::MIN ) );
			} elseif ( '1' !== self::post( 'pw_saved' ) ) {
				$msgs[] = self::msg( 'error', __( 'Τσέκαρε ότι κράτησες τον νέο κωδικό σε ασφαλές μέρος. Δεν αποθηκεύτηκε.', 'data-migrator' ) );
			} elseif ( ! NM_Settings::set_password( $new ) ) {
				$msgs[] = self::msg( 'error', __( 'Δεν γίνεται σε αυτόν τον server: η PHP δεν έχει την επέκταση OpenSSL.', 'data-migrator' ) );
			} else {
				$msgs[] = self::msg( 'success', 'option' === $src ? __( 'Ο νέος κωδικός αποθηκεύτηκε. Τα παλιά κρυπτογραφημένα αντίγραφα ανοίγουν με τον παλιό.', 'data-migrator' ) : __( 'Ο κωδικός αποθηκεύτηκε.', 'data-migrator' ) );
			}
		} elseif ( 'const' !== $src && '1' === self::post( 'pw_forget' ) ) {
			NM_Settings::set_password( '' );
			$want = false;
		}
		$src = NM_Settings::password_source();
		if ( $want && ( ! NM_Crypt::available() || ! in_array( $src, array( 'const', 'option' ), true ) ) ) {
			$msgs[] = self::msg( 'error', __( 'Η κρυπτογράφηση δεν ενεργοποιήθηκε: χρειάζεται κωδικός που διαβάζεται και η επέκταση OpenSSL της PHP.', 'data-migrator' ) );
			$want   = false;
		}
		return $want;
	}

	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in route() / ajax_guard(); every caller validates or sanitizes the value for its own use.
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? trim( (string) wp_unslash( $_POST[ $key ] ) ) : '';
	}

	private static function post_list( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in route() / ajax_guard(); callers validate each item.
		$v   = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
		$out = array();
		foreach ( $v as $x ) {
			if ( is_scalar( $x ) && strlen( (string) $x ) <= 255 ) {
				$out[] = (string) $x;
			}
		}
		return $out;
	}

	public static function route(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked right below.
		if ( empty( $_POST['nm_action'] ) || ! in_array( self::page(), self::PAGES, true ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'data-migrator' ) );
		}
		check_admin_referer( self::NONCE, self::NONCE_FIELD );
		$action = sanitize_key( self::post( 'nm_action' ) );
		$page   = self::page();
		$msgs   = array();
		$extra  = '';

		switch ( $action ) {
			case 'integrity':
				$c      = NM_Integrity::check( true );
				$msgs[] = self::msg( 'ok' === $c['status'] ? 'success' : 'warning', 'ok' === $c['status'] ? __( 'Τα αρχεία του WordPress είναι ίδια με τα επίσημα.', 'data-migrator' ) : ( 'unknown' === $c['status'] ? __( 'Δεν έγινε έλεγχος: το api.wordpress.org δεν απάντησε ή δεν έχει checksums για αυτή την έκδοση.', 'data-migrator' ) : __( 'Βρέθηκαν διαφορές στα αρχεία του WordPress.', 'data-migrator' ) ) );
				break;

			case 'delete':
				$id     = self::post( 'id' );
				$ok     = preg_match( NM_Storage::ID_RE, $id ) && NM_Storage::delete( $id );
				$msgs[] = self::msg( $ok ? 'success' : 'error', $ok ? __( 'Το αντίγραφο διαγράφηκε.', 'data-migrator' ) : __( 'Το αντίγραφο δεν βρέθηκε.', 'data-migrator' ) );
				break;

			case 'installer':
				$id  = self::post( 'id' );
				$key = preg_match( NM_Storage::ID_RE, $id ) ? NM_Installer::new_key( $id ) : new WP_Error( 'bad', __( 'Μη έγκυρο αίτημα.', 'data-migrator' ) );
				if ( is_wp_error( $key ) ) {
					$msgs[] = self::msg( 'error', $key->get_error_message() );
				} else {
					set_transient(
						'nm_inst_key_' . get_current_user_id(),
						array(
							'id'  => $id,
							'key' => $key,
						),
						300
					);
				}
				break;

			case 'settings':
				$s       = NM_Settings::get();
				$path    = self::post( 'storage' );
				$changed = NM_Util::norm( $path ) !== NM_Storage::dir();
				if ( $changed && '' !== $path ) {
					if ( ! path_is_absolute( $path ) || false !== strpos( $path, '..' ) || ! NM_Util::mkdirp( $path ) || ! wp_is_writable( $path ) ) {
						$msgs[] = self::msg( 'error', __( 'Ο φάκελος δεν δημιουργήθηκε ή δεν γράφεται. Οι υπόλοιπες ρυθμίσεις αποθηκεύτηκαν.', 'data-migrator' ) );
						$changed = false;
					} else {
						$s['storage'] = $path;
					}
				}
				$s['part_mb'] = (int) self::post( 'part_mb' );
				$s['exclude'] = self::post( 'exclude' );
				$s['keep']    = '1' === self::post( 'keep' );
				$s['sched_quota'] = (int) self::post( 'sched_quota' );
				$s['sched_mail']  = self::post( 'sched_mail' );
				$s['encrypt']     = self::save_crypt( $msgs );
				NM_Settings::save( $s );
				if ( $changed ) {
					NM_Storage::reset_cache();
					NM_Storage::web_test();
				}
				if ( ! NM_Lang::rs_active() ) {
					$lang = self::post( 'lang' );
					if ( in_array( $lang, array( 'el', 'en' ), true ) ) {
						update_user_meta( get_current_user_id(), 'rs_lang', $lang );
					} else {
						delete_user_meta( get_current_user_id(), 'rs_lang' );
					}
					NM_Lang::reset();
				}
				$msgs[] = self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'data-migrator' ) );
				break;

			case 'sched_save':
				$id = self::post( 'id' );
				if ( '' === $id && count( NM_Schedule::all() ) >= NM_Schedule::MAX ) {
					$msgs[] = self::msg( 'error', __( 'Μη έγκυρο αίτημα.', 'data-migrator' ) );
					break;
				}
				if ( '' !== $id && ! isset( NM_Schedule::all()[ $id ] ) ) {
					$msgs[] = self::msg( 'error', __( 'Το πρόγραμμα δεν βρέθηκε.', 'data-migrator' ) );
					break;
				}
				$sc     = NM_Schedule::put(
					array(
						'id'    => $id,
						'on'    => '1' === self::post( 'on' ),
						'scope' => self::post( 'scope' ),
						'freq'  => self::post( 'freq' ),
						'time'  => self::post( 'time' ),
						'dow'   => (int) self::post( 'dow' ),
						'dom'   => (int) self::post( 'dom' ),
						'keep'  => (int) self::post( 'keep' ),
					)
				);
				$msgs[] = self::msg( 'success', $sc['on'] ? sprintf( /* translators: %s: date and time */ __( 'Το πρόγραμμα αποθηκεύτηκε. Επόμενο αντίγραφο: %s.', 'data-migrator' ), self::when( (int) $sc['next'] ) ) : __( 'Το πρόγραμμα αποθηκεύτηκε σε παύση.', 'data-migrator' ) );
				if ( 'exposed' === NM_Storage::web_test() ) {
					$msgs[] = self::msg( 'error', __( 'Ο φάκελος των αντιγράφων κατεβαίνει από τον browser, γι\' αυτό τα προγραμματισμένα αντίγραφα σταμάτησαν. Όρισε φάκελο έξω από το web root στις Ρυθμίσεις.', 'data-migrator' ) );
				}
				break;

			case 'sched_delete':
				$ok     = NM_Schedule::remove( self::post( 'id' ) );
				$msgs[] = self::msg( $ok ? 'success' : 'error', $ok ? __( 'Το πρόγραμμα διαγράφηκε. Τα αντίγραφά του έμειναν στη λίστα.', 'data-migrator' ) : __( 'Το πρόγραμμα δεν βρέθηκε.', 'data-migrator' ) );
				break;

			case 'sched_run':
				$sc = NM_Schedule::all()[ self::post( 'id' ) ] ?? null;
				$r  = $sc ? NM_Schedule::start( $sc, true ) : new WP_Error( 'gone', __( 'Το πρόγραμμα δεν βρέθηκε.', 'data-migrator' ) );
				$msgs[] = self::msg( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? $r->get_error_message() : __( 'Το αντίγραφο ξεκίνησε στο παρασκήνιο. Η σελίδα δείχνει την πρόοδο· μπορείς και να την κλείσεις.', 'data-migrator' ) );
				break;

			case 'web_test':
				$r      = NM_Storage::web_test();
				$msgs[] = self::msg( 'exposed' === $r ? 'error' : ( 'unknown' === $r ? 'warning' : 'success' ), 'exposed' === $r ? __( 'Ο φάκελος κατεβαίνει από τον browser!', 'data-migrator' ) : ( 'unknown' === $r ? __( 'Ο έλεγχος δεν ολοκληρώθηκε (το site δεν απάντησε σε αίτημα προς τον εαυτό του).', 'data-migrator' ) : __( 'Ο φάκελος δεν κατεβαίνει από τον browser.', 'data-migrator' ) ) );
				break;

			case 'restore_start':
				$analysis = self::post( 'analysis' );
				$home     = esc_url_raw( self::post( 'home' ) );
				if ( ! preg_match( NM_Storage::JOB_RE, $analysis ) || ! preg_match( '#^https?://#', $home ) ) {
					$msgs[] = self::msg( 'error', __( 'Η διεύθυνση του site δεν είναι έγκυρη.', 'data-migrator' ) );
					$extra  = '&analysis=' . rawurlencode( $analysis );
					break;
				}
				$o = array(
					'db'          => '1' === self::post( 'db' ),
					'groups'      => self::post_list( 'groups' ),
					'root_files'  => self::post_list( 'root_files' ),
					'home'        => $home,
					'blog_public' => '1' === self::post( 'blog_public' ) ? 1 : 0,
					'salts'       => '1' === self::post( 'salts' ),
					'bare'        => '1' === self::post( 'bare' ),
					'local'       => array_values(
						array_filter(
							self::post_list( 'local' ),
							static function ( $u ) {
								return (bool) preg_match( '#^https?://(localhost|127\.0\.0\.1)(:\d+)?(/[A-Za-z0-9._~-]+)?$#', $u );
							}
						)
					),
					'extra'       => substr( self::post( 'extra' ), 0, 4000 ),
				);
				if ( '1' === self::post( 'pick_tables' ) ) {
					$o['tables'] = self::post_list( 'tables' );
				}
				if ( ! $o['db'] && ! array_diff( $o['groups'], array( 'root' ) ) && ! $o['root_files'] ) {
					$msgs[] = self::msg( 'error', __( 'Διάλεξε τουλάχιστον τη βάση ή μία ομάδα αρχείων.', 'data-migrator' ) );
					$extra  = '&analysis=' . rawurlencode( $analysis );
					break;
				}
				$r = NM_Restore_Prep::start( $analysis, $o );
				if ( is_wp_error( $r ) ) {
					$msgs[] = self::msg( 'error', $r->get_error_message() );
					$extra  = '&analysis=' . rawurlencode( $analysis );
					break;
				}
				set_transient(
					'nm_go_' . get_current_user_id(),
					array(
						'job'   => $r[0]['id'],
						'token' => $r[1],
					),
					600
				);
				break;

			case 'finalize':
				$ok     = NM_Restore_Prep::finalize( self::post( 'job' ) );
				$msgs[] = self::msg( $ok ? 'success' : 'error', $ok ? __( 'Οριστικοποιήθηκε: τα στοιχεία αναίρεσης σβήστηκαν.', 'data-migrator' ) : __( 'Δεν υπάρχει επαναφορά για οριστικοποίηση.', 'data-migrator' ) );
				break;

			case 'undo':
				$r = NM_Restore_Prep::undo( self::post( 'job' ) );
				if ( is_wp_error( $r ) ) {
					$msgs[] = self::msg( 'error', $r->get_error_message() );
				} else {
					set_transient(
						'nm_go_' . get_current_user_id(),
						array(
							'job'   => $r[0]['id'],
							'token' => $r[1],
							'undo'  => 1,
						),
						600
					);
				}
				break;
		}
		self::redirect( $page, $msgs, $extra );
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $page, array $msgs, string $extra = '' ): void {
		set_transient( 'nm_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page . $extra ) );
		exit;
	}

	private static function take_msgs(): array {
		$raw = get_transient( 'nm_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && $raw ) {
			delete_transient( 'nm_aui_msg_' . get_current_user_id() );
			return $raw;
		}
		return array();
	}

	private static function print_notices( array $notices ): void {
		foreach ( $notices as $n ) {
			if ( ! is_array( $n ) || ! isset( $n['type'], $n['text'] ) ) {
				continue;
			}
			$type = in_array( $n['type'], array( 'success', 'warning', 'error', 'info' ), true ) ? $n['type'] : 'info';
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible inline"><p>' . esc_html( (string) $n['text'] ) . '</p></div>';
		}
	}

	/* =====================================================================
	 * Downloads (privileged GET)
	 * =================================================================== */

	public static function download(): void {
		$what = self::q( 'nm_dl' );
		if ( '' === $what || 'nm-backups' !== self::page() ) {
			return;
		}
		$id = self::q( 'id' );
		if ( ! current_user_can( self::CAP ) || ! preg_match( NM_Storage::ID_RE, $id ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'data-migrator' ) );
		}
		check_admin_referer( 'nm_dl_' . $id );
		$meta = NM_Storage::meta( $id );
		if ( ! $meta ) {
			wp_die( esc_html__( 'Το αντίγραφο δεν βρέθηκε.', 'data-migrator' ) );
		}
		if ( 'installer' === $what ) {
			$src = NM_Installer::build( $id );
			if ( '' === $src ) {
				wp_die( esc_html__( 'Το κλειδί του installer έληξε. Φτιάξε νέο installer.', 'data-migrator' ) );
			}
			self::send_headers( 'nm-installer.php', strlen( $src ), 'application/octet-stream' );
			echo $src; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a file download.
			exit;
		}
		if ( 'log' === $what ) {
			$f = NM_Storage::log_file( (string) ( $meta['job'] ?? '' ) );
			if ( '' === $f || ! is_file( $f ) ) {
				wp_die( esc_html__( 'Το αντίγραφο δεν βρέθηκε.', 'data-migrator' ) );
			}
			self::send_headers( 'data-migrator-' . $id . '.log', (int) filesize( $f ), 'text/plain; charset=utf-8' );
			readfile( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			exit;
		}
		$n     = (int) self::q( 'n' );
		$parts = (array) ( $meta['parts'] ?? array() );
		$path  = isset( $parts[ $n ] ) ? NM_Storage::part_path( (string) $parts[ $n ]['file'] ) : '';
		if ( '' === $path || ! is_file( $path ) ) {
			wp_die( esc_html__( 'Το αντίγραφο δεν βρέθηκε.', 'data-migrator' ) );
		}
		NM_Util::prepare_request();
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		self::send_headers( basename( $path ), (int) filesize( $path ), 'application/zip' );
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		while ( $fh && ! feof( $fh ) ) {
			echo fread( $fh, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fread -- a file download.
			flush();
		}
		if ( $fh ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		exit;
	}

	private static function send_headers( string $name, int $size, string $type ): void {
		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $name ) . '"' );
		header( 'Content-Length: ' . $size );
		header( 'X-Content-Type-Options: nosniff' );
	}

	/* =====================================================================
	 * AJAX
	 * =================================================================== */

	private static function ajax_guard(): void {
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'text' => __( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'data-migrator' ) ), 403 );
		}
	}

	public static function ajax_backup_start(): void {
		self::ajax_guard();
		$o = array(
			'scope'        => sanitize_key( self::post( 'scope' ) ),
			'db'           => '1' === self::post( 'db' ),
			'groups'       => array_map( 'sanitize_key', self::post_list( 'groups' ) ),
			'extra_tables' => self::post_list( 'extra_tables' ),
			'root_dirs'    => self::post_list( 'root_dirs' ),
			'part_mb'      => (int) self::post( 'part_mb' ),
			'note'         => self::post( 'note' ),
		);
		$job = NM_Backup::start( $o );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'text' => $job->get_error_message() ) );
		}
		wp_send_json_success( array( 'job' => $job['id'] ) );
	}

	public static function ajax_step(): void {
		self::ajax_guard();
		$job = self::post( 'job' );
		if ( ! preg_match( NM_Storage::JOB_RE, $job ) ) {
			wp_send_json_error( array( 'text' => __( 'Μη έγκυρο αίτημα.', 'data-migrator' ) ) );
		}
		$r = 'a' === $job[0] ? NM_Restore_Prep::step( $job ) : NM_Backup::step( $job );
		if ( 'a' === $job[0] && 'done' === $r['status'] ) {
			$r['url'] = admin_url( 'admin.php?page=nm-restore&analysis=' . $job );
		}
		wp_send_json_success( $r );
	}

	public static function ajax_cancel(): void {
		self::ajax_guard();
		$job = self::post( 'job' );
		$ok  = preg_match( NM_Storage::JOB_RE, $job ) && NM_Backup::cancel( $job );
		wp_send_json_success( array( 'ok' => $ok ) );
	}

	public static function ajax_upload(): void {
		self::ajax_guard();
		$file = isset( $_FILES['chunk']['tmp_name'] ) && is_string( $_FILES['chunk']['tmp_name'] ) ? $_FILES['chunk']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ajax_guard(); a temp path checked with is_uploaded_file().
		if ( '' === $file || ! is_uploaded_file( $file ) ) {
			wp_send_json_error( array( 'text' => __( 'Το κομμάτι δεν έφτασε στον server.', 'data-migrator' ) ) );
		}
		$r = NM_Restore_Prep::upload_chunk( self::post( 'upload' ), (int) self::post( 'index' ), (int) self::post( 'offset' ), (int) self::post( 'total' ), $file );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'text' => $r->get_error_message() ) );
		}
		wp_send_json_success( array( 'size' => $r ) );
	}

	public static function ajax_upload_done(): void {
		self::ajax_guard();
		$r = NM_Restore_Prep::upload_finish( self::post( 'upload' ) );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'text' => $r->get_error_message() ) );
		}
		wp_send_json_success( array( 'id' => $r ) );
	}

	public static function ajax_analyze(): void {
		self::ajax_guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ajax_guard(); a password is kept as typed.
		$pw  = isset( $_POST['pw'] ) && is_string( $_POST['pw'] ) ? (string) wp_unslash( $_POST['pw'] ) : '';
		$job = NM_Restore_Prep::analyze_start( self::post( 'id' ), $pw );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'text' => $job->get_error_message() ) );
		}
		wp_send_json_success( array( 'job' => $job['id'] ) );
	}

	/* =====================================================================
	 * Footer (Bible §7 — identical pattern)
	 * =================================================================== */

	private static function footer(): void {
		?>
		<p class="nm-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'data-migrator' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="nm-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="nm-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'data-migrator' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="nm-footer-cta">
			<a class="nm-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'data-migrator' ); ?>
			</a>
			<a class="nm-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'data-migrator' ); ?>
			</a>
		</p>
		<?php
	}
}

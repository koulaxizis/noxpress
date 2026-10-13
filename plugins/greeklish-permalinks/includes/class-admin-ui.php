<?php
/**
 * GLP_Admin_UI — menu, settings page, live test, bulk page, tools
 * (Bible §3–§8, §11).
 *
 * Submenu «Greeklish» of the shared "Noxpress" menu (admin_menu priority
 * 140), slug glp-settings, capability manage_options (a setting of the
 * whole site, not of the shop). Tabs:
 *  - settings  slugs, mode, stop words, file names, redirects, own rules,
 *              live test (AJAX glp_test, with the unsaved form values)
 *  - bulk      on-demand conversion: scan, dry run, apply in batches,
 *              undo of the last run (AJAX glp_scan, glp_step, glp_undo)
 *  - tools     settings backup, redirect table, filters
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient glp_aui_msg_{uid}) or AJAX (check_ajax_referer
 * + capability, whitelisted parameters). Export: privileged GET.
 */

defined( 'ABSPATH' ) || exit;

final class GLP_Admin_UI {

	const CAP         = 'manage_options';
	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'glp-settings';
	const NONCE       = 'glp_admin';
	const NONCE_FIELD = 'glp_nonce';
	const NONCE_BAK   = 'glp_backup';
	const NONCE_AJAX  = 'glp_ajax';
	const MAX_IMPORT  = 33554432; // A run's backup holds the old texts of the posts it changed.
	const BATCH       = 20;
	const BATCH_FILES = 5;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 140 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'agp_notice' ) );
		add_action( 'wp_ajax_glp_test', array( __CLASS__, 'ajax_test' ) );
		add_action( 'wp_ajax_glp_scan', array( __CLASS__, 'ajax_scan' ) );
		add_action( 'wp_ajax_glp_step', array( __CLASS__, 'ajax_step' ) );
		add_action( 'wp_ajax_glp_undo', array( __CLASS__, 'ajax_undo' ) );
		add_action( 'wp_ajax_glp_probe', array( __CLASS__, 'ajax_probe' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( GLP_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label. */
	private static function tabs(): array {
		return array(
			'settings' => __( 'Ρυθμίσεις', 'greeklish-permalinks' ),
			'bulk'     => __( 'Μαζική μετατροπή', 'greeklish-permalinks' ),
			'tools'    => __( 'Εργαλεία', 'greeklish-permalinks' ),
		);
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {
		// The shared top-level "Noxpress" is created by Noxpress Core
		// (priority 5, Bible §16): a submenu only here.
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Greeklish Permalinks', 'greeklish-permalinks' ),
			__( 'Greeklish', 'greeklish-permalinks' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'greeklish-permalinks' ) . '</a>'
		);
		return $links;
	}

	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	private static function q( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	private static function current_tab(): string {
		$tab = sanitize_key( self::q( 'tab' ) );
		return isset( self::tabs()[ $tab ] ) ? $tab : 'settings';
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'glp-admin', GLP_URL . 'assets/admin.css', array(), GLP_VERSION );
		wp_enqueue_script( 'glp-admin', GLP_URL . 'assets/admin.js', array(), GLP_VERSION, true );
		wp_localize_script(
			'glp-admin',
			'glpAdmin',
			array(
				'ajax'       => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::NONCE_AJAX ),
				'batch'      => self::BATCH,
				'batchFiles' => self::BATCH_FILES,
				'i18n'       => array(
					'scanning'   => __( 'Σάρωση…', 'greeklish-permalinks' ),
					'none'       => __( 'Δεν βρέθηκε τίποτα με ελληνικά.', 'greeklish-permalinks' ),
					'more'       => __( 'Εμφανίζονται τα πρώτα %1$d από %2$d. Μετά την εφαρμογή, ξανάκανε σάρωση για τα υπόλοιπα.', 'greeklish-permalinks' ),
					'found'      => __( 'Βρέθηκαν %d.', 'greeklish-permalinks' ),
					'nothingSel' => __( 'Δεν επέλεξες τίποτα.', 'greeklish-permalinks' ),
					'needBackup' => __( 'Τσέκαρε πρώτα ότι έχεις πρόσφατο backup.', 'greeklish-permalinks' ),
					'confirm'    => __( 'Να εφαρμοστούν %d αλλαγές; Η προηγούμενη δυνατότητα αναίρεσης θα χαθεί.', 'greeklish-permalinks' ),
					'confirmUn'  => __( 'Να αναιρεθεί η τελευταία εφαρμογή;', 'greeklish-permalinks' ),
					'ok'         => __( 'Εντάξει', 'greeklish-permalinks' ),
					'skip'       => __( 'Παράλειψη', 'greeklish-permalinks' ),
					'error'      => __( 'Σφάλμα', 'greeklish-permalinks' ),
					'done'       => __( 'Έγινε: %1$d εντάξει, %2$d παραλείψεις, %3$d σφάλματα.', 'greeklish-permalinks' ),
					'dryDone'    => __( 'Δοκιμή: %1$d θα αλλάξουν, %2$d παραλείψεις, %3$d σφάλματα. Δεν άλλαξε τίποτα.', 'greeklish-permalinks' ),
					'undoDone'   => __( 'Η αναίρεση ολοκληρώθηκε.', 'greeklish-permalinks' ),
					'stopped'    => __( 'Το αντίγραφο ασφαλείας δεν γράφτηκε, οπότε η εφαρμογή σταμάτησε. Αναιρείται ό,τι άλλαξε ως εδώ…', 'greeklish-permalinks' ),
					'stoppedDone' => __( 'Η εφαρμογή σταμάτησε και ό,τι άλλαξε γύρισε πίσω. Έλεγξε τον χώρο της βάσης και ξαναδοκίμασε με λιγότερα στοιχεία.', 'greeklish-permalinks' ),
					'failed'     => __( 'Το αίτημα απέτυχε. Δοκίμασε ξανά.', 'greeklish-permalinks' ),
					'refs'       => __( '%1$d αρχεία στον δίσκο · %2$d άρθρα/σελίδες · %3$d αρχεία λήψης · %4$d άλλες αναφορές (μένουν με 301)', 'greeklish-permalinks' ),
					'colWhat'    => __( 'Τι', 'greeklish-permalinks' ),
					'colNow'     => __( 'Τώρα', 'greeklish-permalinks' ),
					'colAfter'   => __( 'Μετά', 'greeklish-permalinks' ),
					'colStatus'  => __( 'Κατάσταση', 'greeklish-permalinks' ),
					'undoHint'   => __( 'Ανανέωσε τη σελίδα για να δεις την αναίρεση αυτής της εφαρμογής.', 'greeklish-permalinks' ),
					'probeYes'   => __( '✓ Ο server στέλνει στο WordPress τα αρχεία που λείπουν: το 301 των παλιών URLs αρχείων θα δουλεύει.', 'greeklish-permalinks' ),
					'probeNo'    => __( '✗ Ο server ΔΕΝ στέλνει στο WordPress τα αρχεία που λείπουν: τα παλιά URLs αρχείων θα βγάζουν 404.', 'greeklish-permalinks' ),
					'probeUnknown' => __( 'Ο έλεγχος του server δεν έγινε (το site δεν απάντησε σε αίτημα προς τον εαυτό του).', 'greeklish-permalinks' ),
				),
			)
		);
	}

	/** While AutoConvert Greeklish Permalinks is active it converts first. */
	public static function agp_notice(): void {
		if ( ! class_exists( 'Agp' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( self::SLUG !== self::current_page() && ( ! $screen || 'plugins' !== $screen->id ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'Το AutoConvert Greeklish Permalinks είναι ακόμα ενεργό και μετατρέπει πρώτο τα νέα slugs, οπότε οι ρυθμίσεις του Greeklish Permalinks δεν ισχύουν γι\' αυτά. Απενεργοποίησέ το από τα Πρόσθετα· οι ρυθμίσεις του μένουν όπως είναι.', 'greeklish-permalinks' )
			. '</p></div>';
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'greeklish-permalinks' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap glp-wrap">
			<h1><?php esc_html_e( 'Greeklish Permalinks', 'greeklish-permalinks' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Ελληνικά σε λατινικά στα νέα slugs και, αν το θέλεις, στα ονόματα αρχείων. Τα υπάρχοντα αλλάζουν μόνο με τη μαζική μετατροπή.', 'greeklish-permalinks' ); ?></p>

			<?php self::print_notices( self::take_msgs() ); ?>

			<nav class="nav-tab-wrapper glp-tabs">
				<?php
				foreach ( self::tabs() as $slug => $label ) {
					printf(
						'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
						esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $slug ) ),
						$slug === $tab ? ' nav-tab-active' : '',
						esc_html( $label )
					);
				}
				?>
			</nav>

			<?php
			switch ( $tab ) {
				case 'bulk':
					self::render_bulk();
					break;
				case 'tools':
					self::render_tools();
					break;
				default:
					self::render_settings();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	private static function checkbox( string $name, bool $on, string $label ): void {
		printf(
			'<label class="glp-check"><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label )
		);
	}

	private static function render_settings(): void {
		$s = GLP_Settings::get();
		?>
		<form method="post" id="glp-settings-form">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="glp_action" value="save" />

			<h2 class="glp-h2"><?php esc_html_e( 'Slugs', 'greeklish-permalinks' ); ?></h2>
			<div class="glp-zone">
				<?php self::checkbox( 'glp_slugs', $s['slugs'], __( 'Μετατροπή των νέων slugs (άρθρα, σελίδες, προϊόντα, κατηγορίες, ετικέτες, χαρακτηριστικά, χρήστες)', 'greeklish-permalinks' ) ); ?>
				<p class="description"><?php esc_html_e( 'Μόνο όταν φτιάχνεται ένα slug. Τα υπάρχοντα slugs δεν αλλάζουν και συνεχίζουν να ανοίγουν.', 'greeklish-permalinks' ); ?></p>

				<p><strong><?php esc_html_e( 'Τρόπος μεταγραφής', 'greeklish-permalinks' ); ?></strong></p>
				<label class="glp-check"><input type="radio" name="glp_mode" value="phonetic"<?php checked( 'phonetic', $s['mode'] ); ?> /> <?php esc_html_e( 'Φωνητικός: ου → ou, αυ/ευ → av/ef, μπ/ντ/γκ στην αρχή λέξης → b/d/g, γγ → ng («Ευχαριστούμε» → efcharistoume)', 'greeklish-permalinks' ); ?></label>
				<label class="glp-check"><input type="radio" name="glp_mode" value="simple"<?php checked( 'simple', $s['mode'] ); ?> /> <?php esc_html_e( 'Απλός: κάθε γράμμα μόνο του, ου → oy, μπ → mp («Ευχαριστούμε» → eycharistoyme), όπως το AutoConvert Greeklish Permalinks χωρίς δίφθογγους', 'greeklish-permalinks' ); ?></label>

				<p class="glp-mt-8"><?php self::checkbox( 'glp_stop', $s['stop'], __( 'Αφαίρεση άρθρων, «και» και μικρών προθέσεων από τα νέα slugs («Το μοράκι και τα σκιουράκια» → moraki-skioyrakia)', 'greeklish-permalinks' ) ); ?></p>
			</div>

			<h2 class="glp-h2"><?php esc_html_e( 'Ονόματα αρχείων', 'greeklish-permalinks' ); ?></h2>
			<div class="glp-zone">
				<?php self::checkbox( 'glp_files', $s['files'], __( 'Μετατροπή του ονόματος κάθε νέου upload (εικόνες, PDF, zip, αρχεία προς λήψη)', 'greeklish-permalinks' ) ); ?>
				<?php self::checkbox( 'glp_files_lower', $s['files_lower'], __( 'Πεζά γράμματα στο όνομα αρχείου (Επιθυμίες.jpg → epithymies.jpg)', 'greeklish-permalinks' ) ); ?>
				<p class="description"><?php esc_html_e( 'Ο τίτλος του πολυμέσου μένει ελληνικός. Τα αρχεία που υπάρχουν ήδη αλλάζουν μόνο με τη μαζική μετατροπή.', 'greeklish-permalinks' ); ?></p>
			</div>

			<h2 class="glp-h2"><?php esc_html_e( 'Ελληνικά links', 'greeklish-permalinks' ); ?></h2>
			<div class="glp-zone">
				<?php self::checkbox( 'glp_redirect', $s['redirect'], __( '301 για ελληνικά links που δεν βρίσκονται, όταν η λατινική τους μορφή υπάρχει (π.χ. /product/σοκολατάκια/ → /product/sokolatakia/)', 'greeklish-permalinks' ) ); ?>
				<p class="description"><?php esc_html_e( 'Τρέχει μόνο όταν η σελίδα θα έβγαζε 404. Οι ανακατευθύνσεις της μαζικής μετατροπής ισχύουν πάντα.', 'greeklish-permalinks' ); ?></p>
			</div>

			<h2 class="glp-h2"><?php esc_html_e( 'Δικοί σου κανόνες', 'greeklish-permalinks' ); ?></h2>
			<div class="glp-zone">
				<textarea name="glp_rules" class="glp-rules" rows="6" placeholder="Γλαρόλυκοι = glarolykoi"><?php echo esc_textarea( GLP_Settings::rules_text( $s['rules'] ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Ένας κανόνας ανά γραμμή: ελληνική λέξη = λατινικά (γράμματα, αριθμοί, παύλες). Ισχύει για ολόκληρες λέξεις, χωρίς διάκριση πεζών και τόνων, πριν από τη μεταγραφή.', 'greeklish-permalinks' ); ?></p>
			</div>

			<h2 class="glp-h2"><?php esc_html_e( 'Δοκιμή', 'greeklish-permalinks' ); ?></h2>
			<div class="glp-zone">
				<input type="text" id="glp-test" class="glp-wide" placeholder="<?php esc_attr_e( 'Γράψε έναν τίτλο ή ένα όνομα αρχείου…', 'greeklish-permalinks' ); ?>" autocomplete="off" />
				<dl class="glp-test-out">
					<dt><?php esc_html_e( 'Slug', 'greeklish-permalinks' ); ?></dt><dd><code id="glp-test-slug">—</code></dd>
					<dt><?php esc_html_e( 'Όνομα αρχείου', 'greeklish-permalinks' ); ?></dt><dd><code id="glp-test-file">—</code></dd>
				</dl>
				<p class="description"><?php esc_html_e( 'Με τις ρυθμίσεις αυτής της φόρμας, και πριν τις αποθηκεύσεις.', 'greeklish-permalinks' ); ?></p>
			</div>

			<p class="glp-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'greeklish-permalinks' ); ?></button></p>
		</form>
		<?php
	}

	private static function render_bulk(): void {
		$types   = GLP_Bulk::post_types();
		$checked = GLP_Bulk::default_post_types();
		$log     = GLP_Bulk::log();
		?>
		<p class="description glp-mt-8"><?php esc_html_e( 'Μετατρέπει τα υπάρχοντα ελληνικά slugs και ονόματα αρχείων με τις τρέχουσες ρυθμίσεις. Τα παλιά URLs ανακατευθύνονται με 301 στα νέα.', 'greeklish-permalinks' ); ?></p>

		<h2 class="glp-h2"><?php esc_html_e( '1. Τι θα ελεγχθεί', 'greeklish-permalinks' ); ?></h2>
		<div class="glp-zone" id="glp-bulk-pick">
			<p><strong><?php esc_html_e( 'Τύποι περιεχομένου', 'greeklish-permalinks' ); ?></strong></p>
			<div class="glp-pick">
				<?php foreach ( $types as $name => $label ) : ?>
					<label class="glp-check"><input type="checkbox" name="glp_pt[]" value="<?php echo esc_attr( $name ); ?>"<?php checked( in_array( $name, $checked, true ) ); ?> /> <?php echo esc_html( $label ); ?> <span class="glp-muted"><?php echo esc_html( $name ); ?></span></label>
				<?php endforeach; ?>
			</div>
			<p><strong><?php esc_html_e( 'Ταξινομίες', 'greeklish-permalinks' ); ?></strong></p>
			<div class="glp-pick">
				<?php foreach ( GLP_Bulk::taxonomies() as $name => $label ) : ?>
					<?php $tx = get_taxonomy( $name ); ?>
					<label class="glp-check"><input type="checkbox" name="glp_tx[]" value="<?php echo esc_attr( $name ); ?>"<?php checked( $tx && $tx->public ); ?> /> <?php echo esc_html( $label ); ?> <span class="glp-muted"><?php echo esc_html( $name ); ?></span></label>
				<?php endforeach; ?>
			</div>
			<p><strong><?php esc_html_e( 'Αρχεία', 'greeklish-permalinks' ); ?></strong></p>
			<label class="glp-check"><input type="checkbox" id="glp-files" value="1" /> <?php esc_html_e( 'Ονόματα αρχείων πολυμέσων (μετονομασία στον δίσκο)', 'greeklish-permalinks' ); ?></label>
			<p class="description glp-hidden" id="glp-files-warn"><span class="glp-warn"><?php esc_html_e( 'Προσοχή:', 'greeklish-permalinks' ); ?></span> <?php esc_html_e( 'ενημερώνονται το κείμενο των άρθρων και τα αρχεία προς λήψη του WooCommerce. Αναφορές σε page builders, sliders, CSS, cache, feeds και emails που στάλθηκαν μένουν στο παλιό URL και βασίζονται στο 301.', 'greeklish-permalinks' ); ?> <span id="glp-probe-out"></span></p>
			<p class="glp-actions"><button type="button" class="button button-primary" id="glp-scan"><?php esc_html_e( 'Σάρωση', 'greeklish-permalinks' ); ?></button> <span id="glp-scan-msg" class="glp-muted"></span></p>
		</div>

		<div id="glp-bulk-list" class="glp-hidden">
			<h2 class="glp-h2"><?php esc_html_e( '2. Έλεγχος και εφαρμογή', 'greeklish-permalinks' ); ?></h2>
			<div class="glp-zone">
				<p class="glp-counts" id="glp-counts"></p>
				<div class="glp-results">
					<table class="widefat striped glp-table" id="glp-table">
						<thead><tr><th class="glp-c"><input type="checkbox" id="glp-all" checked /></th><th></th><th></th><th></th><th></th></tr></thead>
						<tbody></tbody>
					</table>
				</div>
				<div class="glp-progress glp-hidden" id="glp-progress"><div class="glp-progress-bar"></div></div>
				<p id="glp-run-msg"></p>
				<p class="glp-actions">
					<button type="button" class="button" id="glp-dry"><?php esc_html_e( 'Δοκιμή (dry run)', 'greeklish-permalinks' ); ?></button>
					<label class="glp-check"><input type="checkbox" id="glp-backup" /> <?php esc_html_e( 'Έχω πρόσφατο backup της βάσης και του φακέλου uploads', 'greeklish-permalinks' ); ?></label>
					<button type="button" class="button button-primary" id="glp-apply"><?php esc_html_e( 'Εφαρμογή', 'greeklish-permalinks' ); ?></button>
				</p>
			</div>
		</div>

		<h2 class="glp-h2"><?php esc_html_e( 'Αναίρεση', 'greeklish-permalinks' ); ?></h2>
		<div class="glp-zone" id="glp-undo-zone">
			<?php if ( $log && $log['items'] ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: date and time, 2: number of changes */
						esc_html__( 'Τελευταία εφαρμογή: %1$s, %2$d αλλαγές.', 'greeklish-permalinks' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $log['time'] ) ),
						count( $log['items'] )
					);
					?>
				</p>
				<p class="glp-actions">
					<button type="button" class="button" id="glp-undo"><?php esc_html_e( 'Αναίρεση της τελευταίας εφαρμογής', 'greeklish-permalinks' ); ?></button>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=bulk&glp_log_export=1' ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Λήψη αντιγράφου', 'greeklish-permalinks' ); ?></a>
				</p>
				<p class="description"><?php esc_html_e( 'Γυρίζει πίσω slugs, ονόματα αρχείων και αναφορές. Ό,τι άλλαξε με το χέρι μετά την εφαρμογή μένει όπως είναι.', 'greeklish-permalinks' ); ?></p>
				<p class="description"><?php esc_html_e( 'Η «Λήψη αντιγράφου» κατεβάζει τις τιμές πριν από την εφαρμογή (JSON). Φορτώνεται ξανά από τα Εργαλεία → Εισαγωγή, που την κάνει την εφαρμογή προς αναίρεση.', 'greeklish-permalinks' ); ?></p>
			<?php else : ?>
				<p class="glp-muted"><?php esc_html_e( 'Δεν υπάρχει εφαρμογή για αναίρεση.', 'greeklish-permalinks' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_tools(): void {
		$s = GLP_Settings::get();
		$n = GLP_Redirects::count_map();
		?>
		<h2 class="glp-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'greeklish-permalinks' ); ?></h2>
		<div class="glp-zone">
			<form method="post" enctype="multipart/form-data" class="glp-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'glp_backup_nonce' ); ?>
				<input type="file" name="glp_import_file" accept=".json,application/json" />
				<button type="submit" name="glp_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή (JSON)', 'greeklish-permalinks' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=tools&glp_export=1' ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'greeklish-permalinks' ); ?></a>
			<p class="description glp-mt-8"><?php esc_html_e( 'Η εξαγωγή έχει μόνο τις ρυθμίσεις και τους κανόνες· ο πίνακας ανακατευθύνσεων δεν μπαίνει. Η εισαγωγή δέχεται και το αντίγραφο μιας εφαρμογής από τη Μαζική μετατροπή.', 'greeklish-permalinks' ); ?></p>
		</div>

		<h2 class="glp-h2"><?php esc_html_e( 'Ανακατευθύνσεις μαζικής μετατροπής', 'greeklish-permalinks' ); ?></h2>
		<div class="glp-zone">
			<p>
				<?php
				/* translators: %d: number of old addresses */
				printf( esc_html__( 'Παλιές διευθύνσεις στον πίνακα: %d.', 'greeklish-permalinks' ), (int) $n );
				?>
			</p>
			<form method="post">
				<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
				<input type="hidden" name="glp_action" value="save_tools" />
				<?php self::checkbox( 'glp_keep_map', $s['keep_map'], __( 'Να μείνει ο πίνακας ανακατευθύνσεων όταν διαγραφεί το plugin', 'greeklish-permalinks' ) ); ?>
				<p class="description"><?php esc_html_e( 'Χωρίς το plugin δεν τον διαβάζει κανείς· χρήσιμο μόνο αν θα το ξαναεγκαταστήσεις. Οι ανακατευθύνσεις άρθρων του ίδιου του WordPress (_wp_old_slug) μένουν πάντα.', 'greeklish-permalinks' ); ?></p>
				<p class="glp-submit"><button type="submit" class="button"><?php esc_html_e( 'Αποθήκευση', 'greeklish-permalinks' ); ?></button></p>
			</form>
		</div>

		<h2 class="glp-h2"><?php esc_html_e( 'Για developers', 'greeklish-permalinks' ); ?></h2>
		<div class="glp-zone">
			<table class="widefat striped glp-table">
				<tbody>
					<tr><td><code>glp_convert( $text, 'slug' | 'file' | 'plain' )</code></td><td><?php esc_html_e( 'Μετατροπή με τις ρυθμίσεις του site.', 'greeklish-permalinks' ); ?></td></tr>
					<tr><td><code>glp_convert</code> <?php esc_html_e( '(φίλτρο)', 'greeklish-permalinks' ); ?></td><td><?php esc_html_e( 'Αλλάζει το αποτέλεσμα: ( $latin, $original, $context ).', 'greeklish-permalinks' ); ?></td></tr>
					<tr><td><code>GLP_DISABLE</code></td><td><?php esc_html_e( 'Στο wp-config.php: σταματά τις αυτόματες μετατροπές και τις ανακατευθύνσεις.', 'greeklish-permalinks' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* =====================================================================
	 * POST routes (admin_init, PRG)
	 * =================================================================== */

	public static function route(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}

		if ( isset( $_POST['glp_import'] ) ) {
			if ( ! isset( $_POST['glp_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['glp_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'greeklish-permalinks' ) );
			}
			self::redirect( 'tools', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['glp_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'greeklish-permalinks' ) );
		}

		switch ( sanitize_key( wp_unslash( $_POST['glp_action'] ) ) ) {
			case 'save':
				self::redirect( 'settings', self::do_save() );
				break;
			case 'save_tools':
				$s             = GLP_Settings::get();
				$s['keep_map'] = '1' === self::post( 'glp_keep_map' );
				GLP_Settings::save( $s );
				self::redirect( 'tools', array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'greeklish-permalinks' ) ) ) );
				break;
			default:
				self::redirect( 'settings', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'greeklish-permalinks' ) ) ) );
		}
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		set_transient( 'glp_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route() / the AJAX handlers.
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/**
	 * A posted list of names. Not sanitize_key(): attribute taxonomies can
	 * have Greek names (pa_χρώμα). GLP_Bulk::scan() keeps only registered
	 * post types and taxonomies.
	 */
	private static function post_keys( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified by the caller; each value sanitized below.
		$raw = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
		$out = array();
		foreach ( $raw as $v ) {
			if ( is_string( $v ) && '' !== $v && strlen( $v ) <= 100 ) {
				$out[] = sanitize_text_field( $v );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Settings from the posted form (save and live test). */
	private static function posted_settings(): array {
		$raw                = GLP_Settings::get();
		$raw['slugs']       = '1' === self::post( 'glp_slugs' );
		$raw['mode']        = sanitize_key( self::post( 'glp_mode' ) );
		$raw['stop']        = '1' === self::post( 'glp_stop' );
		$raw['files']       = '1' === self::post( 'glp_files' );
		$raw['files_lower'] = '1' === self::post( 'glp_files_lower' );
		$raw['redirect']    = '1' === self::post( 'glp_redirect' );
		$raw['rules']       = self::post( 'glp_rules' );
		return GLP_Settings::sanitize( $raw );
	}

	private static function do_save(): array {
		GLP_Settings::save( self::posted_settings() );
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'greeklish-permalinks' ) ) );
		$bad  = GLP_Settings::count_invalid_rules( self::post( 'glp_rules' ) );
		if ( $bad > 0 ) {
			$msgs[] = self::msg(
				'warning',
				/* translators: %d: number of lines */
				sprintf( __( '%d γραμμές κανόνων αγνοήθηκαν: θέλουν μία ελληνική λέξη, «=» και λατινικά γράμματα, αριθμούς ή παύλες.', 'greeklish-permalinks' ), $bad )
			);
		}
		return $msgs;
	}

	/* =====================================================================
	 * AJAX
	 * =================================================================== */

	private static function ajax_guard(): void {
		check_ajax_referer( self::NONCE_AJAX, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'greeklish-permalinks' ) ), 403 );
		}
	}

	/** Live test with the unsaved form values. */
	public static function ajax_test(): void {
		self::ajax_guard();
		$text = mb_substr( self::post( 'text' ), 0, 300 );
		$s    = self::posted_settings();
		$slug = sanitize_title_with_dashes( GLP_Converter::convert( $text, GLP_Settings::slug_opt( $s ) ), '', 'save' );
		// WordPress's own file name cleanup without our filter (it would use
		// the saved settings), then the form's settings.
		remove_filter( 'sanitize_file_name', array( 'GLP_Hooks', 'file' ), 10 );
		$file = GLP_Converter::file_name( sanitize_file_name( $text ), GLP_Settings::file_opt( $s ) );
		add_filter( 'sanitize_file_name', array( 'GLP_Hooks', 'file' ), 10, 2 );
		wp_send_json_success(
			array(
				'slug' => rawurldecode( $slug ),
				'file' => $file,
			)
		);
	}

	public static function ajax_scan(): void {
		self::ajax_guard();
		$res = GLP_Bulk::scan( self::post_keys( 'pt' ), self::post_keys( 'tx' ), '1' === self::post( 'files' ) );
		wp_send_json_success( $res );
	}

	/**
	 * One batch. run: 'dry' (nothing written), 'new' (start an apply run),
	 * or the run ID returned by the first apply batch.
	 */
	public static function ajax_step(): void {
		self::ajax_guard();
		$run  = preg_replace( '/[^A-Za-z0-9]/', '', self::post( 'run' ) );
		$keys = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in ajax_guard(); each key matched below.
		$raw = isset( $_POST['keys'] ) && is_array( $_POST['keys'] ) ? wp_unslash( $_POST['keys'] ) : array();
		foreach ( $raw as $k ) {
			if ( is_string( $k ) && preg_match( '/^[ptf]:\d{1,20}$/', $k ) ) {
				$keys[] = $k;
			}
		}
		$keys = array_slice( array_values( array_unique( $keys ) ), 0, self::BATCH );
		if ( ! $keys ) {
			wp_send_json_error( array( 'message' => __( 'Άκυρο στοιχείο.', 'greeklish-permalinks' ) ), 400 );
		}

		$apply = 'dry' !== $run;
		if ( 'new' === $run ) {
			$run = GLP_Bulk::start_run();
		} elseif ( $apply && ( ! GLP_Bulk::log() || GLP_Bulk::log()['run'] !== $run ) ) {
			wp_send_json_error( array( 'message' => __( 'Ξεκίνησε άλλη εφαρμογή στο μεταξύ. Ξανάκανε σάρωση.', 'greeklish-permalinks' ) ), 409 );
		}

		// Each item writes its undo entry before it changes (GLP_Bulk). When
		// that fails the batch stops here and the page undoes the run.
		$out  = array();
		$stop = false;
		foreach ( $keys as $k ) {
			$r     = GLP_Bulk::process( $k, $apply ? $run : '' );
			$stop  = ! empty( $r['stop'] );
			$out[] = $r;
			if ( $stop ) {
				break;
			}
		}
		wp_send_json_success(
			array(
				'run'   => $apply ? $run : 'dry',
				'items' => $out,
				'stop'  => $stop,
			)
		);
	}

	public static function ajax_undo(): void {
		self::ajax_guard();
		list( $done, $errors, $left ) = GLP_Bulk::undo( self::BATCH );
		wp_send_json_success(
			array(
				'done'   => $done,
				'errors' => $errors,
				'left'   => $left,
			)
		);
	}

	/**
	 * Server check before renaming files: does a missing file under
	 * uploads reach WordPress (so the 301 of old file URLs works)?
	 */
	public static function ajax_probe(): void {
		self::ajax_guard();
		$url = trailingslashit( wp_get_upload_dir()['baseurl'] ) . 'glp-probe-' . wp_generate_password( 8, false ) . '.jpg';
		$r   = wp_remote_get(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core filter.
			)
		);
		if ( is_wp_error( $r ) ) {
			wp_send_json_success( array( 'state' => 'unknown' ) );
		}
		wp_send_json_success( array( 'state' => '' !== (string) wp_remote_retrieve_header( $r, 'x-glp-probe' ) ? 'yes' : 'no' ) );
	}

	/* =====================================================================
	 * Backup (export GET / import POST)
	 * =================================================================== */

	/** Settings export (glp_export) and the last run's backup (glp_log_export), GET + nonce. */
	public static function route_export(): void {
		$what = '' !== self::q( 'glp_export' ) ? 'settings' : ( '' !== self::q( 'glp_log_export' ) ? 'undo' : '' );
		if ( self::SLUG !== self::current_page() || '' === $what ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'greeklish-permalinks' ) );
		}
		if ( 'undo' === $what ) {
			$log = GLP_Bulk::log();
			if ( ! $log || ! $log['items'] ) {
				wp_die( esc_html__( 'Δεν υπάρχει εφαρμογή για αναίρεση.', 'greeklish-permalinks' ) );
			}
			$opts = array( GLP_Bulk::LOG => $log );
		} else {
			$opts = array( GLP_Settings::OPT => GLP_Settings::get() );
		}
		$state = array(
			'plugin'  => 'greeklish-permalinks',
			'version' => GLP_VERSION,
			'options' => $opts,
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="greeklish-permalinks-' . $what . '-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	/** Strict import through GLP_Settings::sanitize(); an invalid file changes nothing. */
	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['glp_import_file']['error'] ) ? (int) $_FILES['glp_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['glp_import_file']['size'] ) ? (int) $_FILES['glp_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'greeklish-permalinks' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['glp_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['glp_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'greeklish-permalinks' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['glp_import_file']['tmp_name'] ), true );
		// phpcs:enable
		if ( ! is_array( $state ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'greeklish-permalinks' ) ) );
		}
		// A run's backup: back as the undo log, checked in full first.
		if ( isset( $state['options'][ GLP_Bulk::LOG ] ) ) {
			$log = GLP_Bulk::sanitize_log( $state['options'][ GLP_Bulk::LOG ] );
			if ( null === $log ) {
				return array( self::msg( 'error', __( 'Μη έγκυρο αντίγραφο εφαρμογής: δεν άλλαξε τίποτα.', 'greeklish-permalinks' ) ) );
			}
			if ( ! GLP_Bulk::import_log( $log ) ) {
				return array( self::msg( 'error', __( 'Το αντίγραφο δεν αποθηκεύτηκε (πολύ μεγάλο για τη βάση;).', 'greeklish-permalinks' ) ) );
			}
			return array( self::msg( 'success', __( 'Η εφαρμογή φορτώθηκε. Αναίρεσέ τη από τη Μαζική μετατροπή.', 'greeklish-permalinks' ) ) );
		}
		$blob = isset( $state['options'][ GLP_Settings::OPT ] ) ? $state['options'][ GLP_Settings::OPT ] : null;
		if ( is_string( $blob ) ) {
			$blob = json_decode( $blob, true );
		}
		if ( ! is_array( $blob ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Greeklish Permalinks).', 'greeklish-permalinks' ) ) );
		}
		GLP_Settings::save( $blob );
		return array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'greeklish-permalinks' ) ) );
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'glp_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'glp_aui_msg_' . get_current_user_id() );
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
	 * Footer (Bible §7 — identical pattern)
	 * =================================================================== */

	private static function footer(): void {
		?>
		<p class="glp-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'greeklish-permalinks' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="glp-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="glp-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'greeklish-permalinks' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="glp-footer-cta">
			<a class="glp-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'greeklish-permalinks' ); ?>
			</a>
			<a class="glp-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'greeklish-permalinks' ); ?>
			</a>
		</p>
		<?php
	}
}

<?php
/**
 * SF_Admin_UI — Admin pages, AJAX endpoints, settings,
 * profiles, state export/import.
 *
 * Security pattern (RS mirror):
 *  - Privileged GET (export state) = admin_init + triple gating
 *    (slug → nonce → capability).
 *  - POST που αλλάζει state (settings, import) = admin_init +
 *    nonce + capability + PRG (transient notices).
 *  - AJAX (preview / dry run / apply / restore / snapshot mgmt) =
 *    check_ajax_referer + current_user_can + strict whitelist
 *    validation ΟΛΩΝ των παραμέτρων (rules/fields/mode/terms).
 *
 * Apply flow (batched, safe against timeouts):
 *  JS -> sf_apply {phase:'start'}  → server: SF_Snapshots::begin() → run_id
 *  JS -> sf_apply {phase:'batch', offset} (loop, 20 units/_request)
 *        → server: plan(apply=true) + SF_Snapshots::append_units()
 *  JS -> sf_apply {phase:'finish', total} → server: ::finalize()
 *  Progress bar στο UI (ακριβώς όπως το roadmap του κανόνα batching).
 *
 * Menu (Bible §8, admin_menu priority 30): submenus στο κοινό top-level
 * 'noxpress', που το δημιουργεί το Noxpress Core (priority 5, Bible §16).
 * Τα δικά μας slugs (sf-formatter, sf-settings) δουλεύουν σε ΚΑΘΕ
 * συνδυασμό plugins.
 *
 * Language (Bible §9): ΔΙΑΒΑΣΗ ΜΟΝΟ του rs_lang user meta (το
 * γράφει μόνο το RS). EN μέσω gettext filter στο domain
 * 'smart-formatter' με δικό μας dict (pattern RS_Lang).
 *
 * State (ultra-complete export/import): sf_profiles, sf_exclusions,
 * sf_snapshots (history incl. undo payloads).
 */

defined( 'ABSPATH' ) || exit;

final class SF_Admin_UI {

	const CAP        = 'manage_woocommerce';
	const SLUG_MENU  = 'noxpress';     // Κοινό top-level του Noxpress ecosystem.
	const SLUG_DASH  = 'sf-formatter';
	const SLUG_SET   = 'sf-settings';

	/** Units ανά dry-run request (μόνο ανάγνωση — μεγαλύτερο από το apply BATCH). */
	const DRY_BATCH = 50;

	/** Μέγιστος αριθμός δειγμάτων before/after ανά dry-run/apply response. */
	const SAMPLE = 5;

	/** Options (state export whitelist). */
	const OPT_PROFILES   = 'sf_profiles';   // JSON: name => {rules[],fields[]} (strict).
	const OPT_EXCLUSIONS = 'sf_exclusions'; // JSON: array strings (strict).

	const STATE_OPTS = array(
		self::OPT_PROFILES,
		self::OPT_EXCLUSIONS,
		SF_Snapshots::OPT_HISTORY,
	);

	const AJAX_NONCE = 'sf_ajax';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 30 );
		add_action( 'admin_init', array( __CLASS__, 'route_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_backup' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_sf_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_sf_dryrun', array( __CLASS__, 'ajax_dryrun' ) );
		add_action( 'wp_ajax_sf_apply', array( __CLASS__, 'ajax_apply' ) );
		add_action( 'wp_ajax_sf_units', array( __CLASS__, 'ajax_units' ) );
		add_action( 'wp_ajax_sf_restore', array( __CLASS__, 'ajax_restore' ) );
		add_action( 'wp_ajax_sf_snapshot_delete', array( __CLASS__, 'ajax_snapshot_delete' ) );
		add_action( 'wp_ajax_sf_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'wp_ajax_sf_profile_save', array( __CLASS__, 'ajax_profile_save' ) );
		add_action( 'wp_ajax_sf_profile_delete', array( __CLASS__, 'ajax_profile_delete' ) );
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {

		// Το κοινό top-level «Noxpress» (με το hub ως landing) το δημιουργεί
		// το Noxpress Core (priority 5, Bible §16). Εδώ μόνο submenus.
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Smart Formatter', 'smart-formatter' ),
			__( 'Smart Formatter', 'smart-formatter' ),
			self::CAP,
			self::SLUG_DASH,
			array( __CLASS__, 'render_formatter' )
		);

		add_submenu_page(
			self::SLUG_MENU,
			__( 'Smart Formatter — Ρυθμίσεις', 'smart-formatter' ),
			__( 'SF Ρυθμίσεις', 'smart-formatter' ),
			self::CAP,
			self::SLUG_SET,
			array( __CLASS__, 'render_settings' )
		);
	}

	/** Το τρέχον admin page slug (sanitized) — '' εκτός admin.php?page=. */
	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	public static function assets(): void {

		$page    = self::current_page();
		$is_tool = ( self::SLUG_DASH === $page );
		$is_set  = ( self::SLUG_SET === $page );

		if ( ! $is_tool && ! $is_set ) {
			return;
		}

		$base = plugin_dir_url( SF_FILE );

		wp_enqueue_style( 'sf-admin', $base . 'assets/admin.css', array(), SF_VERSION );

		// Το JS χρειάζεται μόνο στη σελίδα του εργαλείου (η σελίδα ρυθμίσεων
		// είναι καθαρές φόρμες POST).
		if ( ! $is_tool ) {
			return;
		}

		wp_enqueue_script( 'sf-admin', $base . 'assets/admin.js', array(), SF_VERSION, true );

		wp_localize_script( 'sf-admin', 'SF_CFG', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::AJAX_NONCE ),
			'i18n'    => array(
				'confirmApply'    => __( 'Είσαι σίγουρος; Θα μορφοποιηθούν τα επιλεγμένα κείμενα — δημιουργείται snapshot για undo.', 'smart-formatter' ),
				'working'         => __( 'Επεξεργασία…', 'smart-formatter' ),
				'done'            => __( 'Ολοκληρώθηκε!', 'smart-formatter' ),
				'fail'            => __( 'Απέτυχε — δοκίμασε ξανά.', 'smart-formatter' ),
				'nothing'         => __( 'Καμία αλλαγή δεν εντοπίστηκε.', 'smart-formatter' ),
				'confirmDel'      => __( 'Διαγραφή του snapshot; Δεν επηρεάζει τα προϊόντα — χάνεται μόνο η δυνατότητα undo.', 'smart-formatter' ),
				/* translators: 1: αλλαγμένα, 2: πλήθος δείγματος */
				'previewSummary'  => __( 'Θα αλλάξουν %1$s από %2$s στοιχεία του δείγματος (πρώτα 5).', 'smart-formatter' ),
				/* translators: 1: αλλαγμένα, 2: σύνολο */
				'dryrunSummary'   => __( 'Θα αλλάξουν %1$s από %2$s στοιχεία. Δείγμα παρακάτω.', 'smart-formatter' ),
				/* translators: 1: αλλαγμένα */
				'applySummary'    => __( 'Άλλαξαν %1$s στοιχεία.', 'smart-formatter' ),
				'confirmRestore'  => __( 'Επαναφορά όλων των αποθηκευμένων τιμών αυτού του run στα προϊόντα;', 'smart-formatter' ),
				'confirmRestoreP' => __( 'Το snapshot είναι μερικό (περικομμένο): θα επαναφερθούν ΜΟΝΟ τα units που κρατήθηκαν. Συνέχεια;', 'smart-formatter' ),
				'confirmRestoreS' => __( 'Επαναφορά των επιλεγμένων units στα προϊόντα;', 'smart-formatter' ),
				'restoreSel'      => __( 'Επαναφορά επιλεγμένων', 'smart-formatter' ),
				/* translators: 1: units που επαναφέρθηκαν, 2: units που παραλείφθηκαν */
				'restoredMsg'     => __( 'Επαναφέρθηκαν %1$s units· παραλείφθηκαν %2$s.', 'smart-formatter' ),
				'partialMsg'      => __( 'ΜΕΡΙΚΗ επαναφορά: το snapshot ήταν περικομμένο — τα παλαιότερα units δεν είχαν κρατηθεί.', 'smart-formatter' ),
				'kindProduct'     => __( 'Προϊόν', 'smart-formatter' ),
				'kindTerm'        => __( 'Όρος', 'smart-formatter' ),
				'saved'           => __( 'Αποθηκεύτηκε.', 'smart-formatter' ),
				'pickProfile'     => __( 'Διάλεξε ένα προφίλ.', 'smart-formatter' ),
			),
		) );
	}

	/* =====================================================================
	 * Page: Smart Formatter (main tool)
	 * =================================================================== */

	public static function render_formatter(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'smart-formatter' ) );
		}

		$rules   = SF_Rules::registry();
		$fields  = SF_Targets::fields();
		$prof    = self::get_profiles();
		?>
		<div class="wrap sf-wrap">
			<h1><?php esc_html_e( 'Smart Formatter', 'smart-formatter' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Μαζική μορφοποίηση κειμένων προϊόντων. Το κείμενο σε HTML tags, attributes, shortcodes, code/pre blocks και entities δεν αγγίζεται ποτέ.', 'smart-formatter' ); ?></p>

			<form id="sf-form" onsubmit="return false;">
				<h2 class="sf-h2"><?php esc_html_e( '1. Στόχος', 'smart-formatter' ); ?></h2>
				<div class="sf-zone">
					<label class="sf-inline"><input type="radio" name="sf_mode" value="<?php echo esc_attr( SF_Targets::MODE_ALL ); ?>" checked />
						<?php esc_html_e( 'Όλα τα δημοσιευμένα προϊόντα', 'smart-formatter' ); ?></label>
					<label class="sf-inline"><input type="radio" name="sf_mode" value="<?php echo esc_attr( SF_Targets::MODE_PRODUCTS ); ?>" />
						<?php esc_html_e( 'Ξεχωριστά προϊόντα', 'smart-formatter' ); ?></label>
					<label class="sf-inline"><input type="radio" name="sf_mode" value="<?php echo esc_attr( SF_Targets::MODE_CATEGORIES ); ?>" />
						<?php esc_html_e( 'Κατηγορίες', 'smart-formatter' ); ?></label>
					<label class="sf-inline"><input type="radio" name="sf_mode" value="<?php echo esc_attr( SF_Targets::MODE_TAGS ); ?>" />
						<?php esc_html_e( 'Ετικέτες', 'smart-formatter' ); ?></label>

					<div id="sf-pick-products" class="sf-picker sf-hidden">
						<input type="search" id="sf-prod-search" class="sf-search regular-text"
							placeholder="<?php esc_attr_e( 'Αναζήτηση προϊόντος…', 'smart-formatter' ); ?>" />
						<select id="sf-products" class="sf-multi" name="sf_products[]" multiple size="6"></select>
						<span class="sf-hint"><?php esc_html_e( 'Ctrl/Cmd + click για πολλαπλή επιλογή.', 'smart-formatter' ); ?></span>
					</div>

					<div id="sf-pick-terms" class="sf-picker sf-hidden">
						<select id="sf-terms" class="sf-multi" name="sf_terms[]" multiple size="6"></select>
						<span class="sf-hint"><?php esc_html_e( 'Επιλογή όρων (πολλαπλή).', 'smart-formatter' ); ?></span>
					</div>
				</div>

				<h2 class="sf-h2"><?php esc_html_e( '2. Πεδία', 'smart-formatter' ); ?></h2>
				<div class="sf-zone">
					<?php foreach ( $fields as $f ) : ?>
						<label class="sf-check">
							<input type="checkbox" name="sf_fields[]" value="<?php echo esc_attr( $f['id'] ); ?>" <?php checked( $f['id'], SF_Targets::F_LONG_DESC ); ?> />
							<?php echo esc_html( __( $f['label'], 'smart-formatter' ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- msgid από το fields registry. ?>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Σημείωση: στις ιδιότητες μορφοποιούνται μόνο τα custom (όχι τα global) — τα global είναι κοινά όροι σε όλο το κατάστημα.', 'smart-formatter' ); ?></p>
				</div>

				<h2 class="sf-h2"><?php esc_html_e( '3. Κανόνες', 'smart-formatter' ); ?></h2>
				<div class="sf-zone">
					<?php foreach ( $rules as $r ) : ?>
						<label class="sf-check">
							<input type="checkbox" name="sf_rules[]" value="<?php echo esc_attr( $r['id'] ); ?>" />
							<?php echo esc_html( __( $r['label'], 'smart-formatter' ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- msgid από το rules registry. ?>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Η αφαίρεση μορφοποίησης τρέχει πρώτη· τα «όλο bold/italic» τελευταία — κάθε συνδυασμός δίνει έγκυρο HTML.', 'smart-formatter' ); ?></p>
				</div>

				<h2 class="sf-h2"><?php esc_html_e( '4. Προφίλ', 'smart-formatter' ); ?></h2>
				<div class="sf-zone">
					<select id="sf-profile-load">
						<option value=""><?php esc_html_e( '— Φόρτωση προφίλ —', 'smart-formatter' ); ?></option>
						<?php foreach ( $prof as $pname => $pdata ) : ?>
							<option value="<?php echo esc_attr( $pname ); ?>"
								data-rules="<?php echo esc_attr( wp_json_encode( $pdata['rules'] ) ); ?>"
								data-fields="<?php echo esc_attr( wp_json_encode( $pdata['fields'] ) ); ?>">
								<?php echo esc_html( $pname ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="text" id="sf-profile-name" class="regular-text"
						placeholder="<?php esc_attr_e( 'Όνομα νέου προφίλ', 'smart-formatter' ); ?>" maxlength="60" />
					<button type="button" class="button" id="sf-profile-save"><?php esc_html_e( 'Αποθήκευση κανόνων/πεδίων ως προφίλ', 'smart-formatter' ); ?></button>
					<button type="button" class="button" id="sf-profile-delete"><?php esc_html_e( 'Διαγραφή προφίλ', 'smart-formatter' ); ?></button>
					<span class="sf-hint" id="sf-profile-msg"></span>
					<?php if ( empty( $prof ) ) : ?>
						<span class="sf-hint"><?php esc_html_e( 'Δεν υπάρχουν αποθηκευμένα προφίλ.', 'smart-formatter' ); ?></span>
					<?php endif; ?>
				</div>

				<h2 class="sf-h2"><?php esc_html_e( '5. Εξαιρέσεις φράσεων (εκτός από τα global)', 'smart-formatter' ); ?></h2>
				<div class="sf-zone">
					<textarea id="sf-exclusions" rows="3" class="large-text code"
						placeholder="<?php esc_attr_e( 'Μία φράση ανά γραμμή (προαιρετικό)', 'smart-formatter' ); ?>"></textarea>
				</div>

				<h2 class="sf-h2"><?php esc_html_e( '6. Εκτέλεση', 'smart-formatter' ); ?></h2>
				<div class="sf-zone sf-actions">
					<button type="button" class="button" id="sf-btn-preview"><?php esc_html_e( 'Preview (πρώτα 5)', 'smart-formatter' ); ?></button>
					<button type="button" class="button" id="sf-btn-dryrun"><?php esc_html_e( 'Dry Run (καταμέτρηση)', 'smart-formatter' ); ?></button>
					<button type="button" class="button button-primary" id="sf-btn-apply"><?php esc_html_e( 'Εφαρμογή', 'smart-formatter' ); ?></button>
				</div>
			</form>

			<div id="sf-results" class="sf-results sf-hidden">
				<h2 class="sf-h2"><?php esc_html_e( 'Αποτελέσματα', 'smart-formatter' ); ?></h2>
				<div id="sf-progress-wrap" class="sf-hidden">
					<div class="sf-progress"><div class="sf-progress-bar" id="sf-progress-bar"></div></div>
					<span class="sf-hint" id="sf-progress-label"></span>
				</div>
				<div id="sf-summary" class="sf-summary"></div>
				<table class="widefat striped sf-table" id="sf-entries-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Στοιχείο', 'smart-formatter' ); ?></th>
							<th><?php esc_html_e( 'Πεδίο', 'smart-formatter' ); ?></th>
							<th><?php esc_html_e( 'Πριν', 'smart-formatter' ); ?></th>
							<th><?php esc_html_e( 'Μετά', 'smart-formatter' ); ?></th>
						</tr>
					</thead>
					<tbody id="sf-entries"></tbody>
				</table>
			</div>

			<?php self::render_history(); ?>

			<?php self::footer(); ?>
		</div>
		<?php
	}

	/** Snapshot history + restore UI (κάτω από το main tool). */
	private static function render_history(): void {

		$history = SF_Snapshots::history();
		?>
		<h2 class="sf-h2"><?php esc_html_e( 'Ιστορικό & Undo', 'smart-formatter' ); ?></h2>
		<table class="widefat striped sf-table" id="sf-history">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Run', 'smart-formatter' ); ?></th>
					<th><?php esc_html_e( 'Ημερομηνία', 'smart-formatter' ); ?></th>
					<th><?php esc_html_e( 'Κατάσταση', 'smart-formatter' ); ?></th>
					<th><?php esc_html_e( 'Αλλαγές', 'smart-formatter' ); ?></th>
					<th><?php esc_html_e( 'Ενέργειες', 'smart-formatter' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $history ) ) : ?>
				<tr><td colspan="5" class="sf-empty"><?php esc_html_e( 'Δεν υπάρχουν runs ακόμη.', 'smart-formatter' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $history as $h ) : ?>
				<tr data-run="<?php echo esc_attr( $h['run_id'] ); ?>" data-partial="<?php echo $h['truncated'] ? '1' : '0'; ?>">
					<td><code class="sf-run-id"><?php echo esc_html( substr( $h['run_id'], 0, 10 ) ); ?></code></td>
					<td><?php echo esc_html( $h['created'] ); ?></td>
					<td>
						<?php echo esc_html( SF_Snapshots::STATE_DONE === $h['state'] ? __( 'Ολοκληρώθηκε', 'smart-formatter' ) : __( 'Σε εξέλιξη', 'smart-formatter' ) ); ?>
						<?php if ( $h['truncated'] ) : ?>
							<span class="sf-warn"> · <?php esc_html_e( 'μερικό (περικομμένο)', 'smart-formatter' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( number_format_i18n( $h['changed_total'] ) ); ?></td>
					<td>
						<button type="button" class="button sf-btn-units"><?php esc_html_e( 'Δες μονάδες', 'smart-formatter' ); ?></button>
						<button type="button" class="button sf-btn-restore"><?php echo esc_html( $h['truncated'] ? __( 'Επαναφορά διαθέσιμων', 'smart-formatter' ) : __( 'Επαναφορά όλων', 'smart-formatter' ) ); ?></button>
						<button type="button" class="button sf-btn-delete"><?php esc_html_e( 'Διαγραφή', 'smart-formatter' ); ?></button>
					</td>
				</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<div id="sf-units-panel" class="sf-hidden"></div>
		<?php
	}

	/* =====================================================================
	 * Page: SF Ρυθμίσεις
	 * =================================================================== */

	public static function render_settings(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'smart-formatter' ) );
		}

		$notices = self::take_msgs();
		$excl    = self::get_exclusions();
		?>
		<div class="wrap sf-wrap">
			<h1><?php esc_html_e( 'Smart Formatter — Ρυθμίσεις', 'smart-formatter' ); ?></h1>

			<?php foreach ( $notices as $n ) : ?>
				<div class="notice notice-<?php echo esc_attr( $n['type'] ); ?> is-dismissible inline"><p><?php echo esc_html( $n['text'] ); ?></p></div>
			<?php endforeach; ?>

			<form method="post">
				<?php wp_nonce_field( 'sf_settings', 'sf_settings_nonce' ); ?>

				<h2 class="sf-h2"><?php esc_html_e( 'Καθολικές εξαιρέσεις φράσεων', 'smart-formatter' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Φράσεις που δεν μορφοποιούνται ποτέ, σε οποιοδήποτε run (π.χ. τίτλοι έργων). Μία ανά γραμμή. Συνδυάζονται με τυχόν per-run εξαιρέσεις στη σελίδα του εργαλείου.', 'smart-formatter' ); ?></p>
				<textarea name="sf_exclusions" rows="5" class="large-text code"
					placeholder="<?php esc_attr_e( 'The Pleiades and the Morning Star', 'smart-formatter' ); ?>"><?php echo esc_textarea( implode( "\n", $excl ) ); ?></textarea>

				<p class="sf-submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'smart-formatter' ); ?></button>
				</p>
			</form>

			<h2 class="sf-h2"><?php esc_html_e( 'Backup & Επαναφορά', 'smart-formatter' ); ?></h2>
			<form method="post" enctype="multipart/form-data" class="sf-inline-form">
				<?php wp_nonce_field( 'sf_backup', 'sf_backup_nonce' ); ?>
				<input type="file" name="sf_import_file" accept=".json,application/json" />
				<button type="submit" name="sf_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή state (JSON)', 'smart-formatter' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG_SET . '&sf_backup_export=1' ), 'sf_backup' ) ); ?>">
				<?php esc_html_e( 'Εξαγωγή state (JSON)', 'smart-formatter' ); ?>
			</a>
			<p class="description sf-mt-8">
				<?php esc_html_e( 'Συμπεριλαμβάνονται: προφίλ, καθολικές εξαιρέσεις και ιστορικό snapshots (με τα undo payloads). Η εισαγωγή ΑΝΤΙΚΑΘΙΣΤΑ τα αντίστοιχα δεδομένα. Τα Undo εκτελούνται από τη σελίδα του εργαλείου.', 'smart-formatter' ); ?>
			</p>

			<?php self::footer(); ?>
		</div>
		<?php
	}

	/* =====================================================================
	 * Validation helpers (ΜΙΑ υλοποίηση, AJAX + POST μοιράζονται)
	 * =================================================================== */

	/** Rules: whitelist από registry. */
	private static function valid_rules( array $raw ): array {
		$reg = SF_Rules::registry();
		$out = array();
		foreach ( $raw as $r ) {
			$r = (string) $r;
			if ( isset( $reg[ $r ] ) ) {
				$out[ $r ] = true;
			}
		}
		return array_keys( $out );
	}

	/** Exclusions: strings, trimmed, deduped, cap 100 chars/φράση, max 200 φράσεις. */
	private static function valid_exclusions( array $raw ): array {
		$out = array();
		foreach ( $raw as $e ) {
			$e = trim( sanitize_text_field( (string) $e ) );
			if ( '' !== $e ) {
				$out[ mb_substr( $e, 0, 100 ) ] = true;
			}
			if ( count( $out ) >= 200 ) {
				break;
			}
		}
		return array_keys( $out );
	}

	/** Selection από request data (AJAX ή POST). */
	private static function valid_selection( array $in ): array {
		$mode = isset( $in['sf_mode'] ) ? sanitize_key( (string) $in['sf_mode'] ) : '';
		if ( ! in_array( $mode, SF_Targets::MODES, true ) ) {
			$mode = SF_Targets::MODE_ALL;
		}
		$sel = array( 'mode' => $mode );

		if ( SF_Targets::MODE_PRODUCTS === $mode && ! empty( $in['sf_products'] ) && is_array( $in['sf_products'] ) ) {
			$sel['products'] = array_map( 'absint', $in['sf_products'] );
		}
		if ( in_array( $mode, array( SF_Targets::MODE_CATEGORIES, SF_Targets::MODE_TAGS ), true )
			&& ! empty( $in['sf_terms'] ) && is_array( $in['sf_terms'] ) ) {
			$sel['terms'] = array_map( 'absint', $in['sf_terms'] );
		}
		return $sel;
	}

	/** Fields + rules + exclusions από request data. */
	private static function valid_request_config( array $in ): array {
		$fields = isset( $in['sf_fields'] ) && is_array( $in['sf_fields'] ) ? $in['sf_fields'] : array();
		$rules  = isset( $in['sf_rules'] ) && is_array( $in['sf_rules'] ) ? $in['sf_rules'] : array();
		$excl   = isset( $in['sf_exclusions_raw'] ) && is_array( $in['sf_exclusions_raw'] )
			? $in['sf_exclusions_raw'] : array();

		return array(
			'fields'     => SF_Targets::valid_fields( $fields ),
			'rules'      => self::valid_rules( $rules ),
			'exclusions' => self::valid_exclusions( $excl ),
		);
	}

	/* =====================================================================
	 * AJAX: preview / dry run / apply
	 * =================================================================== */

	public static function ajax_preview(): void {
		self::ajax_gate();
		self::ajax_run( array( 'limit' => 5, 'apply' => false, 'preview' => true ) );
	}

	/**
	 * Dry run σε slices (DRY_BATCH units/request) — το JS κάνει loop με
	 * offset. Επιστρέφει counts + μικρό δείγμα, ποτέ ολόκληρα before/after
	 * όλου του καταλόγου (timeouts / τεράστιο JSON σε μεγάλα καταστήματα).
	 */
	public static function ajax_dryrun(): void {
		self::ajax_gate();
		self::ajax_run( array( 'limit' => self::DRY_BATCH, 'apply' => false, 'sample' => self::SAMPLE ) );
	}

	public static function ajax_apply(): void {
		self::ajax_gate();

		$phase = isset( $_POST['phase'] ) ? sanitize_key( (string) wp_unslash( $_POST['phase'] ) ) : '';
		$cfg   = self::valid_request_config( $_POST );
		$sel   = self::valid_selection( $_POST );

		// Χωρίς κανόνες ή πεδία → δεν επιτρέπεται run (ακόμα και dry).
		if ( empty( $cfg['rules'] ) || empty( $cfg['fields'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Διάλεξε τουλάχιστον έναν κανόνα και ένα πεδίο.', 'smart-formatter' ) ) );
		}

		switch ( $phase ) {
			case 'start':
				$run_id = SF_Snapshots::begin( $sel, $cfg['fields'], $cfg['rules'] );
				wp_send_json_success( array( 'run_id' => $run_id, 'batch' => SF_Targets::BATCH ) );

			case 'batch':
				$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['run_id'] ) ) : '';
				if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $run_id ) ) {
					wp_send_json_error( array( 'message' => __( 'Μη έγκυρο run id.', 'smart-formatter' ) ) );
				}
				self::ajax_run( array( 'limit' => SF_Targets::BATCH, 'apply' => true, 'run_id' => $run_id, 'sample' => self::SAMPLE ) );
				break;

			case 'finish':
				$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['run_id'] ) ) : '';
				$total  = isset( $_POST['total_changed'] ) ? absint( $_POST['total_changed'] ) : 0;
				if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $run_id ) ) {
					wp_send_json_error( array( 'message' => __( 'Μη έγκυρο run id.', 'smart-formatter' ) ) );
				}
				SF_Snapshots::finalize( $run_id, $total );
				wp_send_json_success( array( 'finished' => true ) );

			default:
				wp_send_json_error( array( 'message' => __( 'Άγνωστη φάση.', 'smart-formatter' ) ) );
		}
	}

	/** Κοινός runner για preview/dryrun/apply-batch (μία υλοποίηση). */
	private static function ajax_run( array $args ): void {

		$cfg = self::valid_request_config( $_POST );
		$sel = self::valid_selection( $_POST );

		if ( empty( $cfg['rules'] ) || empty( $cfg['fields'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Διάλεξε τουλάχιστον έναν κανόνα και ένα πεδίο.', 'smart-formatter' ) ) );
		}

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$run_id = $args['run_id'] ?? '';

		// Global exclusions + per-run → UNION (μία ερμηνεία παντού).
		$excl = array_values( array_unique( array_merge( self::get_exclusions(), $cfg['exclusions'] ) ) );

		$plan = SF_Targets::plan(
			$sel,
			$cfg['fields'],
			$cfg['rules'],
			$excl,
			array(
				'offset' => $offset,
				'limit'  => $args['limit'],
				'apply'  => $args['apply'],
			)
		);

		// Apply: append ΤΩΝ before-values των changed units στο snapshot.
		$changed_products = array();
		if ( $args['apply'] && ! empty( $run_id ) ) {
			$snap_units = array();
			$term_tax   = array();
			foreach ( SF_Targets::resolve_terms( $sel ) as $t ) {
				$term_tax[ (int) $t['id'] ] = $t['taxonomy'];
			}
			foreach ( $plan['entries'] as $e ) {
				if ( empty( $e['changed'] ) || ! empty( $e['error'] ) ) {
					continue;
				}
				$u = array(
					'kind'   => $e['kind'],
					'id'     => $e['id'],
					'field'  => $e['field'],
					'before' => $e['before'],
				);
				if ( 'term' === $e['kind'] ) {
					if ( ! isset( $term_tax[ (int) $e['id'] ] ) ) {
						continue; // Δεν αναγνωρίστηκε ο όρος — skip, όχι corrupted restore.
					}
					$u['taxonomy'] = $term_tax[ (int) $e['id'] ];
				} else {
					$changed_products[ (int) $e['id'] ] = true;
				}
				$snap_units[] = $u;
			}
			if ( ! empty( $snap_units ) ) {
				SF_Snapshots::append_units( $run_id, $snap_units );
			}
		}

		// Noxpress cache cooperation (contract §6): ενημέρωση άλλων plugins
		// (π.χ. Store Pulse) ότι άλλαξαν προϊόντα.
		if ( ! empty( $changed_products ) ) {
			do_action( 'noxpress_products_changed', array_keys( $changed_products ) );
		}

		// Entries για το wire: το preview στέλνει ό,τι ζητήθηκε (5 units)·
		// dry run / apply στέλνουν μόνο δείγμα αλλαγμένων (SAMPLE).
		$sample  = isset( $args['sample'] ) ? (int) $args['sample'] : 0;
		$entries = array();
		foreach ( $plan['entries'] as $e ) {
			if ( $sample > 0 && ( count( $entries ) >= $sample || ( empty( $e['changed'] ) && empty( $e['error'] ) ) ) ) {
				continue;
			}
			$entries[] = array(
				'title'   => $e['title'],
				'field'   => __( $e['field_label'], 'smart-formatter' ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- msgid από το fields registry.
				'before'  => $e['before'],
				'after'   => $e['after'],
				'changed' => (bool) $e['changed'],
				'error'   => $e['error'] ?? '',
			);
		}

		wp_send_json_success( array(
			'total_units'   => $plan['total_units'],
			'applied_units' => $plan['applied_units'],
			'changed'       => $plan['changed'],
			'next_offset'   => ( $offset + $plan['applied_units'] ),
			'has_more'      => ( $offset + $plan['applied_units'] ) < $plan['total_units'],
			'entries'       => $entries,
		) );
	}

	/* =====================================================================
	 * AJAX: snapshots (units / restore / delete)
	 * =================================================================== */

	public static function ajax_units(): void {
		self::ajax_gate();
		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['run_id'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $run_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Μη έγκυρο run id.', 'smart-formatter' ) ) );
		}
		wp_send_json_success( array( 'units' => SF_Snapshots::units_of( $run_id ) ) );
	}

	public static function ajax_restore(): void {
		self::ajax_gate();
		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['run_id'] ) ) : '';
		$raw_idx = isset( $_POST['indexes'] ) && is_array( $_POST['indexes'] ) ? wp_unslash( $_POST['indexes'] ) : array();
		$idx = array_map( 'absint', $raw_idx );

		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $run_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Μη έγκυρο run id.', 'smart-formatter' ) ) );
		}

		$res = SF_Snapshots::restore( $run_id, $idx );

		if ( ! empty( $res['product_ids'] ) ) {
			do_action( 'noxpress_products_changed', array_values( array_unique( array_map( 'intval', $res['product_ids'] ) ) ) );
		}

		$payload = array(
			'restored' => (int) $res['restored'],
			'skipped'  => (int) $res['skipped'],
			'partial'  => (bool) $res['partial'],
			'errors'   => array_values( array_map( 'strval', $res['errors'] ) ),
		);

		// Πραγματικό αποτέλεσμα: αποτυχία όταν ΤΙΠΟΤΑ δεν επαναφέρθηκε
		// (snapshot άγνωστο ή όλα τα units απέτυχαν/παραλείφθηκαν).
		if ( ! $res['found'] || ( 0 === $payload['restored'] && ( $payload['skipped'] > 0 || ! empty( $payload['errors'] ) ) ) ) {
			$payload['message'] = ! empty( $payload['errors'] )
				? implode( ' ', $payload['errors'] )
				: __( 'Τίποτα δεν επαναφέρθηκε.', 'smart-formatter' );
			wp_send_json_error( $payload );
		}

		wp_send_json_success( $payload );
	}

	public static function ajax_snapshot_delete(): void {
		self::ajax_gate();
		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['run_id'] ) ) : '';
		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $run_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Μη έγκυρο run id.', 'smart-formatter' ) ) );
		}
		$ok = SF_Snapshots::delete( $run_id );
		if ( $ok ) {
			wp_send_json_success( array( 'deleted' => true ) );
		}
		wp_send_json_error( array( 'message' => __( 'Το snapshot δεν βρέθηκε.', 'smart-formatter' ) ) );
	}

	/** AJAX gate: nonce + capability (δύο έλεγχοι, πάντα). */
	private static function ajax_gate(): void {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Δεν έχεις δικαίωμα.', 'smart-formatter' ) ) );
		}
	}

	/* =====================================================================
	 * AJAX: profiles (save / delete / list for JS)
	 * =================================================================== */

	/**
	 * Αναζήτηση προϊόντων / λήψη όρων (autocomplete από τον 3ο
	 * χαρακτήρα — pattern Bible §5).
	 * $_POST['type']: 'products' | 'categories' | 'tags'
	 * $_POST['q']:    search term (products μόνο, LIKE pre-escaped).
	 */
	public static function ajax_search(): void {
		self::ajax_gate();

		$type = isset( $_POST['type'] ) ? sanitize_key( (string) wp_unslash( $_POST['type'] ) ) : '';
		$q    = isset( $_POST['q'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['q'] ) ) : '';

		switch ( $type ) {
			case 'products':
				if ( mb_strlen( $q ) < 3 ) {
					wp_send_json_success( array( 'items' => array() ) );
				}
				global $wpdb;
				$like   = '%' . $wpdb->esc_like( $q ) . '%';
				$rows   = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ID, post_title FROM {$wpdb->posts}
						 WHERE post_type = 'product' AND post_status = 'publish'
						 AND post_title LIKE %s
						 ORDER BY post_title ASC LIMIT 30",
						$like
					)
				);
				$items = array();
				if ( is_array( $rows ) ) {
					foreach ( $rows as $r ) {
						$items[] = array( 'id' => (int) $r->ID, 'title' => (string) $r->post_title );
					}
				}
				wp_send_json_success( array( 'items' => $items ) );

			case 'categories':
			case 'tags':
				$taxonomy = ( 'categories' === $type ) ? 'product_cat' : 'product_tag';
				$terms    = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'number'     => 200,
						'orderby'    => 'name',
						'order'      => 'ASC',
					)
				);
				$items = array();
				if ( is_array( $terms ) && ! is_wp_error( $terms ) ) {
					foreach ( $terms as $t ) {
						$items[] = array( 'id' => (int) $t->term_id, 'title' => (string) $t->name );
					}
				}
				wp_send_json_success( array( 'items' => $items ) );

			default:
				wp_send_json_error( array( 'message' => __( 'Άγνωστος τύπος αναζήτησης.', 'smart-formatter' ) ) );
		}
	}

	public static function ajax_profile_save(): void {
		self::ajax_gate();
		$name   = isset( $_POST['name'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['name'] ) ) : '';
		$rules  = isset( $_POST['rules'] ) && is_array( wp_unslash( $_POST['rules'] ) ) ? wp_unslash( $_POST['rules'] ) : array();
		$fields = isset( $_POST['fields'] ) && is_array( wp_unslash( $_POST['fields'] ) ) ? wp_unslash( $_POST['fields'] ) : array();

		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Δώσε όνομα προφίλ.', 'smart-formatter' ) ) );
		}

		$ok = self::save_profile( $name, $rules, $fields );
		if ( $ok ) {
			wp_send_json_success( array( 'saved' => true, 'name' => $name ) );
		}
		wp_send_json_error( array( 'message' => __( 'Η αποθήκευση απέτυχε.', 'smart-formatter' ) ) );
	}

	public static function ajax_profile_delete(): void {
		self::ajax_gate();
		$name = isset( $_POST['name'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['name'] ) ) : '';
		$ok   = self::delete_profile( $name );
		if ( $ok ) {
			wp_send_json_success( array( 'deleted' => true ) );
		}
		wp_send_json_error( array( 'message' => __( 'Το προφίλ δεν βρέθηκε.', 'smart-formatter' ) ) );
	}

	/* =====================================================================
	 * Routes (PRG): settings + backup (admin_init)
	 * =================================================================== */

	public static function route_settings(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || self::SLUG_SET !== $_GET['page'] ) {
			return;
		}

		if ( ! isset( $_POST['sf_settings_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['sf_settings_nonce'] ) ), 'sf_settings' ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'smart-formatter' ) );
		}
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'smart-formatter' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- custom cleaning παρακάτω.
		$raw = isset( $_POST['sf_exclusions'] ) ? (string) wp_unslash( $_POST['sf_exclusions'] ) : '';
		$excl = self::valid_exclusions( preg_split( '/\r\n|\r|\n/', $raw ) );

		if ( empty( $excl ) ) {
			delete_option( self::OPT_EXCLUSIONS );
		} else {
			update_option( self::OPT_EXCLUSIONS, wp_json_encode( $excl, JSON_UNESCAPED_UNICODE ), false );
		}

		set_transient( 'sf_aui_msg_' . get_current_user_id(), array(
			array( 'type' => 'success', 'text' => __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'smart-formatter' ) ),
		), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG_SET ) );
		exit;
	}

	public static function route_backup(): void {

		// ---------- Export (GET + nonce) ----------
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce ελέγχεται παρακάτω.
		if ( isset( $_GET['sf_backup_export'] ) ) {

			if ( ! isset( $_GET['_wpnonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'sf_backup' )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'smart-formatter' ) );
			}

			$state = array(
				'version' => SF_VERSION,
				'options' => array(),
			);
			foreach ( self::STATE_OPTS as $opt ) {
				$state['options'][ $opt ] = get_option( $opt, '' );
			}

			nocache_headers();
			header( 'Content-Type: application/json; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="smart-formatter-state-' . gmdate( 'Y-m-d' ) . '.json"' );
			echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
			exit;
		}

		// ---------- Import (POST + nonce + PRG) ----------
		if ( isset( $_POST['sf_import'] ) ) {

			if ( ! isset( $_POST['sf_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['sf_backup_nonce'] ) ), 'sf_backup' )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'smart-formatter' ) );
			}

			$notices = array( array( 'type' => 'error', 'text' => __( 'Δεν επιλέχθηκε αρχείο JSON.', 'smart-formatter' ) ) );

			$up_err  = isset( $_FILES['sf_import_file']['error'] ) ? (int) $_FILES['sf_import_file']['error'] : UPLOAD_ERR_NO_FILE;
			$up_size = isset( $_FILES['sf_import_file']['size'] ) ? (int) $_FILES['sf_import_file']['size'] : 0;

			if ( UPLOAD_ERR_OK === $up_err && $up_size > 0 && $up_size <= 67108864
				&& ! empty( $_FILES['sf_import_file']['tmp_name'] )
				&& is_uploaded_file( $_FILES['sf_import_file']['tmp_name'] ) ) {

				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.readfile_read_file_get_contents -- uploaded tmp file.
				$raw   = (string) file_get_contents( $_FILES['sf_import_file']['tmp_name'] );
				$state = json_decode( $raw, true );

				$notices = is_array( $state )
					? self::import_state( $state )
					: array( array( 'type' => 'error', 'text' => __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'smart-formatter' ) ) );
			}

			set_transient( 'sf_aui_msg_' . get_current_user_id(), $notices, 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG_SET ) );
			exit;
		}
	}

	/**
	 * Import state — STRICT validation ανά option (pattern RS: άκυρο →
	 * ρητό error, το υπάρχον option παραμένει άθικτο).
	 */
	private static function import_state( array $state ): array {

		$notes = array();
		$opts  = ( isset( $state['options'] ) && is_array( $state['options'] ) ) ? $state['options'] : array();

		if ( empty( $opts ) ) {
			return array( array( 'type' => 'error', 'text' => __( 'Μη έγκυρο αρχείο backup (λείπουν τα options).', 'smart-formatter' ) ) );
		}

		// ---- Profiles: name => {rules:[known ids], fields:[known ids]} ----
		if ( isset( $opts[ self::OPT_PROFILES ] ) ) {
			$decoded = is_string( $opts[ self::OPT_PROFILES ] ) ? json_decode( $opts[ self::OPT_PROFILES ], true ) : null;
			$valid  = is_array( $decoded );
			$clean  = array();

			if ( $valid ) {
				foreach ( $decoded as $name => $p ) {
					$name = sanitize_text_field( (string) $name );
					if ( '' === $name || mb_strlen( $name ) > 60 || ! is_array( $p ) ) {
						$valid = false;
						break;
					}
					$clean[ $name ] = array(
						'rules'  => self::valid_rules( is_array( $p['rules'] ?? null ) ? $p['rules'] : array() ),
						'fields' => SF_Targets::valid_fields( is_array( $p['fields'] ?? null ) ? $p['fields'] : array() ),
					);
				}
			}

			if ( $valid ) {
				if ( empty( $clean ) ) {
					delete_option( self::OPT_PROFILES );
				} else {
					update_option( self::OPT_PROFILES, wp_json_encode( $clean, JSON_UNESCAPED_UNICODE ), false );
				}
			} else {
				$notes[] = array( 'type' => 'error', 'text' => __( 'Μη έγκυρο blob προφίλ.', 'smart-formatter' ) );
			}
		}

		// ---- Exclusions ----
		if ( isset( $opts[ self::OPT_EXCLUSIONS ] ) ) {
			$decoded = is_string( $opts[ self::OPT_EXCLUSIONS ] ) ? json_decode( $opts[ self::OPT_EXCLUSIONS ], true ) : null;
			$valid   = is_array( $decoded ) && count( $decoded ) === count( array_filter( $decoded, 'is_string' ) );
			$clean   = $valid ? self::valid_exclusions( $decoded ) : array();
			if ( $valid ) {
				update_option( self::OPT_EXCLUSIONS, wp_json_encode( $clean, JSON_UNESCAPED_UNICODE ), false );
			} else {
				$notes[] = array( 'type' => 'error', 'text' => __( 'Μη έγκυρο blob εξαιρέσεων.', 'smart-formatter' ) );
			}
		}

		// ---- Snapshots history — UNTRUSTED: ένα Restore θα το γράψει σε
		//      προϊόντα. Strict validation + wp_kses_post (SF_Snapshots). ----
		if ( isset( $opts[ SF_Snapshots::OPT_HISTORY ] ) ) {
			$raw   = $opts[ SF_Snapshots::OPT_HISTORY ];
			$clean = ( is_string( $raw ) && '' !== $raw ) ? SF_Snapshots::sanitize_import( $raw ) : null;
			if ( '' === $raw ) {
				delete_option( SF_Snapshots::OPT_HISTORY );
			} elseif ( null !== $clean ) {
				update_option( SF_Snapshots::OPT_HISTORY, $clean['json'], false );
				if ( $clean['dropped'] > 0 ) {
					$notes[] = array(
						'type' => 'warning',
						'text' => sprintf(
							/* translators: %d: πλήθος units */
							__( 'Παραλείφθηκαν %d units ιστορικού (άκυρα ή για προϊόντα/όρους που δεν υπάρχουν).', 'smart-formatter' ),
							$clean['dropped']
						),
					);
				}
			} else {
				$notes[] = array( 'type' => 'error', 'text' => __( 'Μη έγκυρο blob ιστορικού snapshots.', 'smart-formatter' ) );
			}
		}

		$has_errors = false;
		foreach ( $notes as $n ) {
			if ( 'error' === $n['type'] ) {
				$has_errors = true;
				break;
			}
		}

		$notes[] = $has_errors
			? array( 'type' => 'error', 'text' => __( 'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ — τα άκυρα τμήματα ΔΕΝ αντικαταστάθηκαν.', 'smart-formatter' ) )
			: array( 'type' => 'success', 'text' => __( 'Η εισαγωγή ολοκληρώθηκε.', 'smart-formatter' ) );

		return $notes;
	}

	/** Transient notices (PRG pattern — RS mirror). */
	private static function take_msgs(): array {
		$raw = get_transient( 'sf_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'sf_aui_msg_' . get_current_user_id() );
			return $raw;
		}
		return array();
	}

	/* =====================================================================
	 * Option readers (strict, cached ανά request)
	 * =================================================================== */

	public static function get_exclusions(): array {
		static $excl = null;
		if ( null === $excl ) {
			$excl = array();
			$raw  = get_option( self::OPT_EXCLUSIONS, '' );
			if ( is_string( $raw ) && '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$excl = self::valid_exclusions( $decoded );
				}
			}
		}
		return $excl;
	}

	public static function get_profiles(): array {
		static $prof = null;
		if ( null === $prof ) {
			$prof = array();
			$raw  = get_option( self::OPT_PROFILES, '' );
			if ( is_string( $raw ) && '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					foreach ( $decoded as $name => $p ) {
						// Αριθμητικά ονόματα («2024») γίνονται int keys στο json_decode.
						if ( ( is_string( $name ) || is_int( $name ) ) && is_array( $p ) ) {
							$prof[ (string) $name ] = array(
								'rules'  => self::valid_rules( is_array( $p['rules'] ?? null ) ? $p['rules'] : array() ),
								'fields' => SF_Targets::valid_fields( is_array( $p['fields'] ?? null ) ? $p['fields'] : array() ),
							);
						}
					}
				}
			}
		}
		return $prof;
	}

	/** Αποθήκευση profile (χρησιμοποιείται από το AJAX endpoint). */
	public static function save_profile( string $name, array $rules, array $fields ): bool {
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name || mb_strlen( $name ) > 60 ) {
			return false;
		}
		$prof = self::get_profiles();
		$prof[ $name ] = array(
			'rules'  => self::valid_rules( $rules ),
			'fields' => SF_Targets::valid_fields( $fields ),
		);
		return false !== update_option( self::OPT_PROFILES, wp_json_encode( $prof, JSON_UNESCAPED_UNICODE ), false );
	}

	public static function delete_profile( string $name ): bool {
		$name = trim( sanitize_text_field( $name ) );
		$prof = self::get_profiles();
		if ( ! isset( $prof[ $name ] ) ) {
			return false;
		}
		unset( $prof[ $name ] );
		if ( empty( $prof ) ) {
			return delete_option( self::OPT_PROFILES );
		}
		return false !== update_option( self::OPT_PROFILES, wp_json_encode( $prof, JSON_UNESCAPED_UNICODE ), false );
	}

	/* =====================================================================
	 * Footer (Bible §7 — identical pattern)
	 * =================================================================== */

	private static function footer(): void {
		?>
		<p class="sf-footer">
			<?php
			printf(
				/* translators: %s: όνομα δημιουργού */
				esc_html__( 'Made with ❤ by %s', 'smart-formatter' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="sf-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="sf-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'smart-formatter' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="sf-footer-cta">
			<a class="sf-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'smart-formatter' ); ?>
			</a>
			<a class="sf-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'smart-formatter' ); ?>
			</a>
		</p>
		<?php
	}
}

<?php
/**
 * SF_Admin_UI (+ SF_Lang) — Admin pages, AJAX endpoints, settings,
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
 * Menu (Bible §8): submenu κάτω από 'noxpress' όταν το RS είναι
 * ενεργό (SLUG_MENU constant reference — ποτέ hardcoded duplicate),
 * standalone top-level αλλιώς. Labels: «Smart Formatter» + «SF Ρυθμίσεις».
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
	const SLUG_DASH  = 'sf-formatter';
	const SLUG_SET   = 'sf-settings';

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
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
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
		add_filter( 'gettext', array( 'SF_Lang', 'filter' ), 10, 3 );
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {

		$parent = self::parent_slug();

		if ( null === $parent ) {
			// Standalone: το Smart Formatter δημιουργεί το top-level
			// μόνο του (Bible §8 standalone mode).
			add_menu_page(
				__( 'Smart Formatter', 'smart-formatter' ),
				__( 'Smart Formatter', 'smart-formatter' ),
				self::CAP,
				self::SLUG_DASH,
				array( __CLASS__, 'render_formatter' ),
				'dashicons-editor-textcolor',
				57
			);
			$parent = self::SLUG_DASH;
		}

		if ( self::SLUG_DASH !== $parent ) {
			add_submenu_page(
				$parent,
				__( 'Smart Formatter', 'smart-formatter' ),
				__( 'Smart Formatter', 'smart-formatter' ),
				self::CAP,
				self::SLUG_DASH,
				array( __CLASS__, 'render_formatter' )
			);
		}

		add_submenu_page(
			$parent,
			__( 'Smart Formatter — Ρυθμίσεις', 'smart-formatter' ),
			__( 'SF Ρυθμίσεις', 'smart-formatter' ),
			self::CAP,
			self::SLUG_SET,
			array( __CLASS__, 'render_settings' )
		);
	}

	/** Parent slug: 'noxpress' αν το RS δημιούργησε το top-level. */
	private static function parent_slug(): ?string {
		if ( class_exists( 'RS_Admin_UI' ) && defined( 'RS_Admin_UI::SLUG_MENU' ) ) {
			return RS_Admin_UI::SLUG_MENU; // Constant reference (Bible §10).
		}
		return null;
	}

	public static function assets( string $hook ): void {

		$ours = ( false !== strpos( $hook, 'sf-formatter' ) )
			|| ( false !== strpos( $hook, 'sf-settings' ) );

		if ( ! $ours ) {
			return;
		}

		$base = plugin_dir_url( SF_FILE );

		$css_v = file_exists( SF_PATH . 'assets/admin.css' ) ? (string) filemtime( SF_PATH . 'assets/admin.css' ) : SF_VERSION;
		wp_enqueue_style( 'sf-admin', $base . 'assets/admin.css', array(), $css_v );

		$js_v = file_exists( SF_PATH . 'assets/admin.js' ) ? (string) filemtime( SF_PATH . 'assets/admin.js' ) : SF_VERSION;
		wp_enqueue_script( 'sf-admin', $base . 'assets/admin.js', array(), $js_v, true );

		wp_localize_script( 'sf-admin', 'SF_CFG', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::AJAX_NONCE ),
			'i18n'    => array(
				'confirmApply' => __( 'Είσαι σίγουρος; Θα μορφοποιηθούν τα επιλεγμένα κείμενα — δημιουργείται snapshot για undo.', 'smart-formatter' ),
				'working'     => __( 'Επεξεργασία…', 'smart-formatter' ),
				'done'        => __( 'Ολοκληρώθηκε!', 'smart-formatter' ),
				'fail'        => __( 'Απέτυχε — δοκίμασε ξανά.', 'smart-formatter' ),
				'nothing'     => __( 'Καμία αλλαγή δεν εντοπίστηκε.', 'smart-formatter' ),
				'confirmDel'  => __( 'Διαγραφή του snapshot; Δεν επηρεάζει τα προϊόντα — χάνεται μόνο η δυνατότητα undo.', 'smart-formatter' ),
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
		$excl    = self::get_exclusions();
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
							<?php echo esc_html( $f['label'] ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Σημείωση: στις ιδιότητες μορφοποιούνται μόνο τα custom (όχι τα global) — τα global είναι κοινά όροι σε όλο το κατάστημα.', 'smart-formatter' ); ?></p>
				</div>

				<h2 class="sf-h2"><?php esc_html_e( '3. Κανόνες', 'smart-formatter' ); ?></h2>
				<div class="sf-zone">
					<?php foreach ( $rules as $r ) : ?>
						<label class="sf-check">
							<input type="checkbox" name="sf_rules[]" value="<?php echo esc_attr( $r['id'] ); ?>" />
							<?php echo esc_html( $r['label'] ); ?>
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
				<tr data-run="<?php echo esc_attr( $h['run_id'] ); ?>">
					<td><code class="sf-run-id"><?php echo esc_html( substr( $h['run_id'], 0, 10 ) ); ?></code></td>
					<td><?php echo esc_html( $h['created'] ); ?></td>
					<td>
						<?php echo esc_html( SF_Snapshots::STATE_DONE === $h['state'] ? __( 'Ολοκληρώθηκε', 'smart-formatter' ) : __( 'Σε εξέλιξη', 'smart-formatter' ) ); ?>
						<?php if ( $h['truncated'] ) : ?>
							<span class="sf-warn"> · <?php esc_html_e( 'περικομμένο', 'smart-formatter' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( number_format_i18n( $h['changed_total'] ) ); ?></td>
					<td>
						<button type="button" class="button sf-btn-units"><?php esc_html_e( 'Δες μονάδες', 'smart-formatter' ); ?></button>
						<button type="button" class="button sf-btn-restore"><?php esc_html_e( 'Επαναφορά όλων', 'smart-formatter' ); ?></button>
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

		$notices   = self::take_msgs();
		$excl      = self::get_exclusions();
		$hist_link = admin_url( 'admin.php?page=' . self::SLUG_DASH );
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

				<p style="margin-top:20px;">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'smart-formatter' ); ?></button>
				</p>
			</form>

			<h2 class="sf-h2"><?php esc_html_e( 'Backup & Επαναφορά', 'smart-formatter' ); ?></h2>
			<form method="post" enctype="multipart/form-data" style="display:inline; margin-right:10px;">
				<?php wp_nonce_field( 'sf_backup', 'sf_backup_nonce' ); ?>
				<input type="file" name="sf_import_file" accept=".json,application/json" />
				<button type="submit" name="sf_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή state (JSON)', 'smart-formatter' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG_SET . '&sf_backup_export=1' ), 'sf_backup' ) ); ?>">
				<?php esc_html_e( 'Εξαγωγή state (JSON)', 'smart-formatter' ); ?>
			</a>
			<p class="description" style="margin-top:8px;">
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

	public static function ajax_dryrun(): void {
		self::ajax_gate();
		self::ajax_run( array( 'limit' => null, 'apply' => false ) );
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
				self::ajax_run( array( 'limit' => SF_Targets::BATCH, 'apply' => true, 'run_id' => $run_id ) );
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
		if ( $args['apply'] && ! empty( $run_id ) ) {
			$snap_units = array();
			foreach ( $plan['entries'] as $e ) {
				if ( ! empty( $e['changed'] ) && empty( $e['error'] ) ) {
					$u = array(
						'kind'   => $e['kind'],
						'id'     => $e['id'],
						'field'  => $e['field'],
						'before' => $e['before'],
					);
					if ( 'term' === $e['kind'] ) {
						// Taxonomy χρειάζεται το restore — από resolve_terms.
						$terms = SF_Targets::resolve_terms( $sel );
						foreach ( $terms as $t ) {
							if ( (int) $t['id'] === (int) $e['id'] ) {
								$u['taxonomy'] = $t['taxonomy'];
								break;
							}
						}
						if ( ! isset( $u['taxonomy'] ) ) {
							continue; // Δεν αναγνωρίστηκε ο όρος — skip, όχι corrupted restore.
						}
					}
					$snap_units[] = $u;
				}
			}
			if ( ! empty( $snap_units ) ) {
				SF_Snapshots::append_units( $run_id, $snap_units );
			}
		}

		// Entries: ελαφριά έκδοση για το wire (cap preview σε 5, batches
		// προχωρούν στα 20 — αποδεκτό μέγεθος JSON).
		$entries = array();
		foreach ( $plan['entries'] as $e ) {
			$entries[] = array(
				'title'       => $e['title'],
				'field'       => $e['field_label'],
				'before'      => $e['before'],
				'after'       => $e['after'],
				'changed'     => (bool) $e['changed'],
				'error'       => $e['error'] ?? '',
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
		wp_send_json_success( $res );
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

		// ---- Snapshots history (payload passthrough — το load() του
		//      SF_Snapshots επανα-κάνει strict validation στο read) ----
		if ( isset( $opts[ SF_Snapshots::OPT_HISTORY ] ) ) {
			$raw = $opts[ SF_Snapshots::OPT_HISTORY ];
			if ( is_string( $raw ) && ( '' === $raw || null !== json_decode( $raw, true ) ) ) {
				if ( '' === $raw ) {
					delete_option( SF_Snapshots::OPT_HISTORY );
				} else {
					update_option( SF_Snapshots::OPT_HISTORY, $raw, false );
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
						if ( is_string( $name ) && is_array( $p ) ) {
							$prof[ $name ] = array(
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

	/** Αποθήκευση profile (AΡΙ endpoints αργότερα — και για AJAX). */
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
		<hr style="margin-top:24px;" />
		<p class="sf-footer">
			<?php
			printf(
				/* translators: %s: όνομα δημιουργού */
				esc_html__( 'Made with <3 by %s', 'smart-formatter' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="sf-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="sf-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'smart-formatter' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="sf-footer" style="margin-top:10px;">
			<a href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer"
				style="display:inline-block;background:#6d4aff;color:#fff;padding:6px 18px;border-radius:999px;text-decoration:none;font-weight:600;">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'smart-formatter' ); ?>
			</a>
		</p>
		<?php
	}
}

/**
 * SF_Lang — Domain dict + gettext filter (pattern RS_Lang, compact).
 *
 * Το rs_lang ΔΙΑΒΑΖΕΤΑΙ μόνο (Bible §9): 'el'|'en'|'' (auto). Όταν
 * el/auto-ελληνικά → τα Greek msgids (πηγή) περνούν ως έχουν. Όταν
 * 'en' (ή auto σε αγγλικό WP) → το dict δίνει EN όπου υπάρχει entry.
 */
final class SF_Lang {

	private static $dict = array(
		'Smart Formatter'                                        => 'Smart Formatter',
		'Μαζική μορφοποίηση κειμένων προϊόντων. Το κείμενο σε HTML tags, attributes, shortcodes, code/pre blocks και entities δεν αγγίζεται ποτέ.' => 'Bulk-format product texts. Text inside HTML tags, attributes, shortcodes, code/pre blocks and entities is never touched.',
		'Όλα τα δημοσιευμένα προϊόντα'                            => 'All published products',
		'Ξεχωριστά προϊόντα'                                     => 'Specific products',
		'Κατηγορίες'                                              => 'Categories',
		'Ετικέτες'                                                => 'Tags',
		'Πεδία'                                                   => 'Fields',
		'Σύντομη περιγραφή'                                       => 'Short description',
		'Αναλυτική περιγραφή'                                     => 'Long description',
		'Σημείωση αγοράς (purchase note)'                         => 'Purchase note',
		'Ιδιότητες (custom μόνο)'                                 => 'Attributes (custom only)',
		'Περιγραφές κατηγοριών/ετικετών'                          => 'Category/tag descriptions',
		'Κανόνες'                                                  => 'Rules',
		'Πλήρης αφαίρεση μορφοποίησης'                            => 'Strip all formatting',
		'Κανονικοποίηση κενών'                                    => 'Normalize whitespace',
		'Περιεχόμενο εισαγωγικών italic'                         => 'Quoted text italic',
		'Περιεχόμενο εισαγωγικών bold'                           => 'Quoted text bold',
		'Περιεχόμενο παρενθέσεων italic'                        => 'Parentheses italic',
		'Αριθμοί bold'                                           => 'Numbers bold',
		'Όλο το κείμενο italic'                                  => 'All text italic',
		'Όλο το κείμενο bold'                                    => 'All text bold',
		'Προφίλ'                                                  => 'Profiles',
		'Εξαιρέσεις φράσεων (εκτός από τα global)'               => 'Phrase exclusions (besides global)',
		'Εκτέλεση'                                                => 'Run',
		'Preview (πρώτα 5)'                                       => 'Preview (first 5)',
		'Dry Run (καταμέτρηση)'                                   => 'Dry Run (count only)',
		'Εφαρμογή'                                                => 'Apply',
		'Αποτελέσματα'                                            => 'Results',
		'Ιστορικό & Undo'                                         => 'History & Undo',
		'Ολοκληρώθηκε'                                            => 'Completed',
		'Σε εξέλιξη'                                              => 'Running',
		'Δες μονάδες'                                             => 'View units',
		'Επαναφορά όλων'                                          => 'Restore all',
		'Διαγραφή'                                                => 'Delete',
		'SF Ρυθμίσεις'                                            => 'SF Settings',
		'Καθολικές εξαιρέσεις φράσεων'                            => 'Global phrase exclusions',
		'Αποθήκευση ρυθμίσεων'                                    => 'Save settings',
		'Οι ρυθμίσεις αποθηκεύτηκαν.'                              => 'Settings saved.',
		'Η εισαγωγή ολοκληρώθηκε.'                                => 'Import completed.',
		'1. Στόχος'                                               => '1. Target',
		'Αναζήτηση προϊόντος…'                                    => 'Search products…',
		'Ctrl/Cmd + click για πολλαπλή επιλογή.'                   => 'Ctrl/Cmd + click for multiple selection.',
		'Επιλογή όρων (πολλαπλή).'                                 => 'Select terms (multiple).',
		'2. Πεδία'                                                 => '2. Fields',
		'Σημείωση: στις ιδιότητες μορφοποιούνται μόνο τα custom (όχι τα global) — τα global είναι κοινά όροι σε όλο το κατάστημα.' => 'Note: attributes — only custom ones are formatted (not global); global attributes are shared terms across the whole store.',
		'3. Κανόνες'                                               => '3. Rules',
		'Η αφαίρεση μορφοποίησης τρέχει πρώτη· τα «όλο bold/italic» τελευταία — κάθε συνδυασμός δίνει έγκυρο HTML.' => 'Stripping runs first; "all bold/italic" rules run last — every combination yields valid HTML.',
		'4. Προφίλ'                                                => '4. Profiles',
		'— Φόρτωση προφίλ —'                                      => '— Load profile —',
		'Όνομα νέου προφίλ'                                        => 'New profile name',
		'Αποθήκευση κανόνων/πεδίων ως προφίλ'                       => 'Save rules/fields as profile',
		'Διαγραφή προφίλ'                                          => 'Delete profile',
		'Δεν υπάρχουν αποθηκευμένα προφίλ.'                         => 'No saved profiles yet.',
		'Μία φράση ανά γραμμή (προαιρετικό)'                        => 'One phrase per line (optional)',
		'6. Εκτέλεση'                                              => '6. Run',
		'Στοιχείο'                                                 => 'Item',
		'Πεδίο'                                                    => 'Field',
		'Πριν'                                                     => 'Before',
		'Μετά'                                                     => 'After',
		'Run'                                                      => 'Run',
		'Ημερομηνία'                                               => 'Date',
		'Κατάσταση'                                                => 'Status',
		'Αλλαγές'                                                  => 'Changes',
		'Ενέργειες'                                                => 'Actions',
		'Δεν υπάρχουν runs ακόμη.'                                 => 'No runs yet.',
		'περικομμένο'                                              => 'truncated',
		'Είσαι σίγουρος; Θα μορφοποιηθούν τα επιλεγμένα κείμενα — δημιουργείται snapshot για undo.' => 'Are you sure? The selected texts will be formatted — a snapshot is created for undo.',
		'Επεξεργασία…'                                             => 'Working…',
		'Ολοκληρώθηκε!'                                            => 'Done!',
		'Απέτυχε — δοκίμασε ξανά.'                                  => 'Failed — try again.',
		'Καμία αλλαγή δεν εντοπίστηκε.'                             => 'No changes detected.',
		'Διαγραφή του snapshot; Δεν επηρεάζει τα προϊόντα — χάνεται μόνο η δυνατότητα undo.' => 'Delete snapshot? Products are not affected — only the undo opportunity is lost.',
		'Διάλεξε τουλάχιστον έναν κανόνα και ένα πεδίο.'           => 'Pick at least one rule and one field.',
		'Μη έγκυρο run id.'                                        => 'Invalid run id.',
		'Άγνωστη φάση.'                                            => 'Unknown phase.',
		'Δεν έχεις δικαίωμα.'                                       => 'You do not have permission.',
		'Άγνωστος τύπος αναζήτησης.'                               => 'Unknown search type.',
		'Δώσε όνομα προφίλ.'                                        => 'Give a profile name.',
		'Η αποθήκευση απέτυχε.'                                     => 'Saving failed.',
		'Το προφίλ δεν βρέθηκε.'                                    => 'Profile not found.',
		'Το snapshot δεν βρέθηκε.'                                  => 'Snapshot not found.',
		'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.'           => 'You do not have access to this page.',
		'Smart Formatter — Ρυθμίσεις'                              => 'Smart Formatter — Settings',
		'Backup & Επαναφορά'                                       => 'Backup & Restore',
		'Εισαγωγή state (JSON)'                                    => 'Import state (JSON)',
		'Εξαγωγή state (JSON)'                                      => 'Export state (JSON)',
		'Made with <3 by %s'                                       => 'Made with <3 by %s',
		'More plugins at'                                          => 'More plugins at',
		'☕ Στήριξε το project στο Ko-fi'                           => '☕ Support the project on Ko-fi',
		'Μη έγκυρο blob προφίλ.'                                   => 'Invalid profiles blob.',
		'Μη έγκυρο blob εξαιρέσεων.'                               => 'Invalid exclusions blob.',
		'Μη έγκυρο blob ιστορικού snapshots.'                      => 'Invalid snapshots history blob.',
		'Δεν επιλέχθηκε αρχείο JSON.'                              => 'No JSON file selected.',
		'Το αρχείο δεν είναι έγκυρο JSON.'                         => 'The file is not valid JSON.',
		'Μη έγκυρο αρχείο backup (λείπουν τα options).'            => 'Invalid backup file (options missing).',
		'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ — τα άκυρα τμήματα ΔΕΝ αντικαταστάθηκαν.' => 'Import completed PARTIALLY — invalid sections were NOT replaced.',
	);

	public static function filter( $translation, $text, $domain ) {
		if ( 'smart-formatter' === $domain && isset( self::$dict[ $text ] ) ) {
			$lang = get_user_meta( get_current_user_id(), 'rs_lang', true );
			if ( 'en' === $lang ) {
				return self::$dict[ $text ];
			}
			if ( '' === $lang || 'auto' === $lang ) {
				$locale = get_user_locale();
				if ( ! str_starts_with( $locale, 'el' ) && isset( self::$dict[ $text ] ) ) {
					return self::$dict[ $text ];
				}
			}
		}
		return $translation;
	}
}
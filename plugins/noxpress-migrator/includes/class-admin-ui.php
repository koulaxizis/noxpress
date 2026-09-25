<?php
/**
 * NM_Admin_UI — Admin pages: Dashboard, Ρυθμίσεις, progress UI, exports.
 *
 * Security pattern (mirror από RS_Admin_UI):
 *  - GET exports/checkpoint = admin_init + TRIPLE gating (slug → nonce → cap)
 *  - POST state changes (settings) = nonce + capability + PRG (transient notices)
 *
 * v1.0.0: Στήσιμο admin page, progress polling, settings, exports.
 */

defined( 'ABSPATH' ) || exit;

final class NM_Admin_UI {

	const CAP    = 'manage_options';
	const SLUG   = 'noxpress-migrator';
	const OPTION_LANG = 'nm_lang';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_actions' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_verify' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/* =====================================================================
	 * Menus / assets
	 * =================================================================== */

	public static function admin_menu(): void {
		add_menu_page(
			__( 'Noxpress Migrator', 'noxpress-migrator' ),
			__( 'Noxpress Migrator', 'noxpress-migrator' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-upload'
		);
		add_submenu_page(
			self::SLUG,
			__( 'Migration', 'noxpress-migrator' ),
			__( 'Migration', 'noxpress-migrator' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render_dashboard' )
		);
		add_submenu_page(
			self::SLUG,
			__( 'Settings', 'noxpress-migrator' ),
			__( 'Settings', 'noxpress-migrator' ),
			self::CAP,
			self::SLUG . '-settings',
			array( __CLASS__, 'render_settings' )
		);
		add_submenu_page(
			self::SLUG,
			__( 'Verify', 'noxpress-migrator' ),
			__( 'Verify', 'noxpress-migrator' ),
			self::CAP,
			self::SLUG . '-verify',
			array( __CLASS__, 'render_verify' )
		);
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, 'noxpress-migrator' ) ) {
			return;
		}

		$base = plugin_dir_url( NM_FILE );
		wp_enqueue_style( 'nm-admin', $base . 'assets/admin.css', array(), filemtime( NM_PATH . 'assets/admin.css' ) );
		wp_enqueue_script( 'nm-admin', $base . 'assets/admin.js', array( 'jquery' ), filemtime( NM_PATH . 'assets/admin.js' ), true );

		wp_localize_script( 'nm-admin', 'nm_ajax', array(
			'ajaxurl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'nm_progress' ),
			'slug'          => self::SLUG,
			'confirm_abort' => __( 'Are you sure?', 'noxpress-migrator' ),
		) );
	}

	/* =====================================================================
	 * Dashboard — migration progress
	 * =================================================================== */

	public static function render_dashboard(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'noxpress-migrator' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only checkpoint view.
		$fresh = isset( $_GET['nm_fresh'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['nm_fresh'] ) );

		if ( $fresh ) {
			NM_Export_Engine::start( true );
		}

		// Πάντα το orchestrator — αυτό κανονίζει db/files/packaged.
		if ( class_exists( 'NM_Files_Engine' ) ) {
			$report = NM_Files_Engine::current_report();
		} else {
			$db     = NM_Export_Engine::start( false );
			$report = $db->report();
		}
		$status = $report['status'];
		?>
		<div class="wrap nm-wrap">

			<h1><?php esc_html_e( 'Noxpress Migrator — Migration', 'noxpress-migrator' ); ?></h1>

			<?php
			$notices = self::take_notices();
			foreach ( $notices as $n ) :
				?>
				<div class="notice notice-<?php echo esc_attr( $n['type'] ); ?> is-dismissible inline"><p><?php echo esc_html( $n['text'] ); ?></p></div>
				<?php
			endforeach;
			?>

			<?php if ( 'idle' === $status || 'failed' === $status ) : ?>

				<form method="post" class="nm-form">
					<?php wp_nonce_field( 'nm_start', 'nm_start_nonce' ); ?>

					<h2><?php esc_html_e( 'What to include', 'noxpress-migrator' ); ?></h2>

					<label><input type="radio" name="nm_scope" value="everything" checked />
						<?php esc_html_e( 'Everything (database + all files)', 'noxpress-migrator' ); ?></label><br />
					<label><input type="radio" name="nm_scope" value="db" />
						<?php esc_html_e( 'Database only', 'noxpress-migrator' ); ?></label><br />
					<label><input type="radio" name="nm_scope" value="files" />
						<?php esc_html_e( 'Files only', 'noxpress-migrator' ); ?></label>

					<h2><?php esc_html_e( 'Options', 'noxpress-migrator' ); ?></h2>

					<p><label><?php esc_html_e( 'Volume size (MB)', 'noxpress-migrator' ); ?>:
					<input type="number" name="nm_volume_mb" min="50" max="4096" step="50" value="500" style="width:100px;" />
					<span class="description"><?php esc_html_e( 'Split point for multi-volume archives.', 'noxpress-migrator' ); ?></span></label></p>

					<h2><?php esc_html_e( 'Media newer than', 'noxpress-migrator' ); ?></h2>
					<p>
						<input type="date" name="nm_media_after" value="" />
						<span class="description"><?php esc_html_e( 'Only uploads newer than this date (optional). Leave empty for no limit.', 'noxpress-migrator' ); ?></span>
					</p>

					<p>
						<button type="submit" name="nm_start" value="1" class="button button-primary">
							<?php esc_html_e( 'Start export', 'noxpress-migrator' ); ?>
						</button>
						<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'nm_fresh', '1', admin_url( 'admin.php?page=' . self::SLUG ) ), 'nm_fresh' ) ); ?>"
						   class="button">
							<?php esc_html_e( 'New export', 'noxpress-migrator' ); ?>
						</a>
					</p>
				</form>

			<?php else : ?>

				<!-- PROGRESS UI -->
				<div id="nm-progress-panel" data-autostart="1">
					<h2><?php esc_html_e( 'Status', 'noxpress-migrator' ); ?></h2>

					<div class="nm-kpis">
						<div class="nm-kpi">
							<span class="nm-kpi-label"><?php esc_html_e( 'Phase', 'noxpress-migrator' ); ?></span>
							<strong id="nm-status-phase"><?php echo esc_html( $report['status'] ); ?></strong>
						</div>
						<div class="nm-kpi">
							<span class="nm-kpi-label"><?php esc_html_e( 'Table', 'noxpress-migrator' ); ?></span>
							<strong id="nm-status-table"><?php echo esc_html( $report['table'] ); ?></strong>
						</div>
						<div class="nm-kpi">
							<span class="nm-kpi-label"><?php esc_html_e( 'Rows', 'noxpress-migrator' ); ?></span>
							<strong id="nm-status-rows"><?php echo esc_html( number_format_i18n( $report['rows'] ) ); ?></strong>
						</div>
						<div class="nm-kpi">
							<span class="nm-kpi-label"><?php esc_html_e( 'Bytes', 'noxpress-migrator' ); ?></span>
							<strong id="nm-status-bytes"><?php echo esc_html( NM_Lang::fmt_bytes( $report['bytes'] ) ); ?></strong>
						</div>
						<div class="nm-kpi">
							<span class="nm-kpi-label"><?php esc_html_e( 'Progress', 'noxpress-migrator' ); ?></span>
							<strong><span id="nm-status-pct"><?php echo esc_html( (float) $report['pct'] ); ?></span>%</strong>
						</div>
					</div>

					<div class="nm-progress-bar-wrap">
						<div class="nm-progress-bar" id="nm-progress-fill" style="width:<?php echo esc_attr( (float) $report['pct'] ); ?>%;"></div>
					</div>

					<?php if ( ! empty( $report['error'] ) ) : ?>
						<div class="notice notice-error inline">
							<p><strong><?php esc_html_e( 'Error', 'noxpress-migrator' ); ?>:</strong> <?php echo esc_html( $report['error'] ); ?></p>
						</div>
					<?php endif; ?>

					<p>
						<button id="nm-abort-btn" class="button" onclick="nm_abort()"><?php esc_html_e( 'Abort', 'noxpress-migrator' ); ?></button>
					</p>
				</div>

			<?php else : ?>

				<!-- RUNNING / PAUSED -->
				<div id="nm-progress-panel">
					<h2><?php esc_html_e( 'Status', 'noxpress-migrator' ); ?></h2>

					<div class="nm-kpis">
						<div class="nm-kpi">
							<span class="nm-kpi-label"><?php esc_html_e( 'Status', 'noxpress-migrator' ); ?></span>
							<strong id="nm-status-phase"><?php echo esc_html( $report['status'] ); ?></strong>
						</div>
						<div class="nm-kpi">
							<span class="nm-kpi-label"><?php esc_html_e( 'Current item', 'noxpress-migrator' ); ?></span>
							<strong id="nm-status-table" style="font-size:14px;word-break:break-all;"><?php echo esc_html( $report['table'] ); ?></strong>
						</div>
						<div class="nm-kpi">
							<span class="nm-kpi-label"><?php esc_html_e( 'Items', 'noxpress-migrator' ); ?></span>
							<strong id="nm-status-rows"><?php echo esc_html( number_format_i18n( (int) $report['rows'] ) ); ?></strong>
						</div>
						<div class="nm-kpi">
							<span class="nm-kpi-label"><?php esc_html_e( 'Bytes', 'noxpress-migrator' ); ?></span>
							<strong id="nm-status-bytes"><?php echo esc_html( NM_Lang::fmt_bytes( $report['bytes'] ) ); ?></strong>
						</div>
						<div class="nm-kpi">
							<span class="nm-kpi-label"><?php esc_html_e( 'Progress', 'noxpress-migrator' ); ?></span>
							<strong><span id="nm-status-pct"><?php echo esc_html( (float) $report['pct'] ); ?></span>%</strong>
						</div>
					</div>

					<div class="nm-progress-bar-wrap">
						<div class="nm-progress-bar" id="nm-progress-fill" style="width:<?php echo esc_attr( (float) $report['pct'] ); ?>%;"></div>
					</div>

					<?php if ( ! empty( $report['error'] ) ) : ?>
						<div class="notice notice-error inline">
							<p><strong><?php esc_html_e( 'Error', 'noxpress-migrator' ); ?>:</strong> <?php echo esc_html( $report['error'] ); ?></p>
						</div>
					<?php endif; ?>

					<p>
						<button id="nm-abort-btn" class="button" onclick="nm_abort()"><?php esc_html_e( 'Abort', 'noxpress-migrator' ); ?></button>
					</p>
				</div>

			<?php endif; ?>

			<?php
			// ---- PACKAGED: download panel (τελικά volumes) ----
			if ( class_exists( 'NM_Files_Engine' )
				&& 'packaged' === (string) json_decode( (string) get_option( 'nm_checkpoint', '' ), true )['phase'] ?? '' ) :

				$paths = NM_Files_Engine::final_paths();
				if ( ! empty( $paths ) ) :
					?>
					<h2><?php esc_html_e( 'Download package', 'noxpress-migrator' ); ?></h2>
					<table class="widefat striped nm-vol-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'File', 'noxpress-migrator' ); ?></th>
								<th><?php esc_html_e( 'Size', 'noxpress-migrator' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'noxpress-migrator' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $paths as $v ) : ?>
								<tr>
									<td><code><?php echo esc_html( $v['file'] ); ?></code></td>
									<td><?php echo esc_html( NM_Lang::fmt_bytes( $v['bytes'] ) ); ?></td>
									<td>
										<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&nm_download=' . rawurlencode( $v['file'] ) ), 'nm_download' ) ); ?>">
											<?php esc_html_e( 'Download', 'noxpress-migrator' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p style="margin-top:12px;">
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&nm_delete=1' ), 'nm_delete' ) ); ?>"
						   onclick="return confirm('<?php echo esc_js( __( 'Delete the package permanently?', 'noxpress-migrator' ) ); ?>');">
							<?php esc_html_e( 'Delete package', 'noxpress-migrator' ); ?>
						</a>
					</p>
					<?php
				endif;
			endif;
			?>

			<?php self::footer(); ?>
		</div>

		<?php
	}

	/* =====================================================================
	 * Settings
	 * =================================================================== */

	public static function render_settings(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'noxpress-migrator' ) );
		}

		$notices = self::take_notices();
		$lang    = NM_Lang::get_choice();
		$vol     = get_option( 'nm_max_volume_mb', '500' );
		?>
		<div class="wrap nm-wrap">

			<h1><?php esc_html_e( 'Noxpress Migrator — Settings', 'noxpress-migrator' ); ?></h1>

			<?php foreach ( $notices as $n ) : ?>
				<div class="notice notice-<?php echo esc_attr( $n['type'] ); ?> is-dismissible inline"><p><?php echo esc_html( $n['text'] ); ?></p></div>
			<?php endforeach; ?>

			<form method="post">
				<?php wp_nonce_field( 'nm_settings', 'nm_settings_nonce' ); ?>

				<h2><?php esc_html_e( 'Display language', 'noxpress-migrator' ); ?></h2>
				<p>
					<select name="nm_lang">
						<option value="auto"<?php selected( $lang, 'auto' ); ?>><?php esc_html_e( 'Automatic (WordPress)', 'noxpress-migrator' ); ?></option>
						<option value="en"<?php selected( $lang, 'en' ); ?>>English</option>
						<option value="el"<?php selected( $lang, 'el' ); ?>>Ελληνικά</option>
					</select>
					<span class="description"><?php esc_html_e( 'Per user (only affects you). If you have not picked one, the WordPress language is followed.', 'noxpress-migrator' ); ?></span>
				</p>

				<h2><?php esc_html_e( 'Volume size (MB)', 'noxpress-migrator' ); ?></h2>
				<p>
					<input type="number" name="nm_volume_mb" min="50" max="4096" step="50"
						value="<?php echo esc_attr( $vol ); ?>" style="width:100px;" />
					<span class="description"><?php esc_html_e( 'Split point for multi-volume archives.', 'noxpress-migrator' ); ?></span>
				</p>

				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'noxpress-migrator' ); ?></button>
				</p>
			</form>

			<?php self::footer(); ?>
		</div>
		<?php
	}

	/* =====================================================================
	 * Verify page
	 * =================================================================== */

	public static function render_verify(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'noxpress-migrator' ) );
		}

		$state  = ( class_exists( 'NM_Verify' ) ) ? NM_Verify::load_state() : null;
		$report = ( class_exists( 'NM_Verify' ) ) ? NM_Verify::last_report() : array();

		$notices = array();
		$msg = get_transient( 'nm_verify_msg_' . get_current_user_id() );
		if ( is_array( $msg ) ) {
			delete_transient( 'nm_verify_msg_' . get_current_user_id() );
			$notices[] = $msg;
		}

		?>
		<div class="wrap nm-wrap">

			<h1><?php esc_html_e( 'Noxpress Migrator — Verify', 'noxpress-migrator' ); ?></h1>

			<?php foreach ( $notices as $n ) : ?>
				<div class="notice notice-<?php echo esc_attr( $n['type'] ); ?> is-dismissible inline"><p><?php echo esc_html( $n['text'] ); ?></p></div>
			<?php endforeach; ?>

			<?php if ( null === $state && empty( $report ) ) : ?>

				<!-- FORM: έναρξη verification -->
				<form method="post" class="nm-form">
					<?php wp_nonce_field( 'nm_verify', 'nm_verify_nonce' ); ?>

					<h2><?php esc_html_e( 'Start Verification', 'noxpress-migrator' ); ?></h2>

					<p><label><?php esc_html_e( 'Path to manifest.json (absolute path)', 'noxpress-migrator' ); ?>:</label>
					<input type="text" name="nm_manifest_path" value="" style="width:100%;max-width:500px;" />
					<span class="description"><?php esc_html_e( 'Leave empty to try auto-detection in uploads root.', 'noxpress-migrator' ); ?></span></p>

					<p><label><?php esc_html_e( 'Verification mode', 'noxpress-migrator' ); ?>:</label>
					<select name="nm_verify_mode">
						<option value="deep"><?php esc_html_e( 'Deep (SHA-256 on every file — recommended)', 'noxpress-migrator' ); ?></option>
						<option value="quick"><?php esc_html_e( 'Quick (existence + size only)', 'noxpress-migrator' ); ?></option>
					</select></p>

					<p>
						<button type="submit" name="nm_verify_start" value="1" class="button button-primary">
							<?php esc_html_e( 'Start Verification', 'noxpress-migrator' ); ?>
						</button>
					</p>
				</form>

			<?php elseif ( null !== $state ) : ?>

				<!-- PROGRESS UI -->
				<div id="nm-verify-progress" data-autostart="1">

					<h2><?php esc_html_e( 'Verification in progress', 'noxpress-migrator' ); ?></h2>

					<div class="nm-kpis">
						<div class="nm-kpi">
							<span class="nm-kpi-label"><?php esc_html_e( 'Phase', 'noxpress-migrator' ); ?></span>
							<strong id="nm-verify-phase"><?php echo esc_html( (string) ( $state['phase'] ?? '?' ) ); ?></strong>
						</div>
						<div class="nm-kpi">
							<span class="nm-kpi-label"><?php esc_html_e( 'Mode', 'noxpress-migrator' ); ?></span>
							<strong><?php echo esc_html( (string) ( $state['mode'] ?? '?' ) ); ?></strong>
						</div>
						<div class="nm-kpi">
							<span class="nm-kpi-label"><?php esc_html_e( 'Progress', 'noxpress-migrator' ); ?></span>
							<strong><span id="nm-verify-pct">0</span>%</strong>
						</div>
					</div>

					<div class="nm-progress-bar-wrap">
						<div class="nm-progress-bar" id="nm-verify-fill" style="width:0%;"></div>
					</div>
				</div>

			<?php else : ?>

				<!-- FINAL REPORT -->
				<h2><?php esc_html_e( 'Verification Results', 'noxpress-migrator' ); ?></h2>

				<?php
				$verdict = (string) ( $report['verdict'] ?? '?' );
				$is_ok   = false !== strpos( $verdict, 'PERFECT' ) || false !== strpos( $verdict, 'DATA OK' );
				?>
				<p class="nm-verbatim">
					<strong class="<?php echo $is_ok ? 'ok' : 'err'; ?>"><?php echo esc_html( $verdict ); ?></strong>
				</p>

				<?php if ( ! empty( $report['db_bad'] ) ) : ?>
					<h3><?php esc_html_e( 'Database mismatches', 'noxpress-migrator' ); ?></h3>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Table', 'noxpress-migrator' ); ?></th>
								<th><?php esc_html_e( 'Expected rows', 'noxpress-migrator' ); ?></th>
								<th><?php esc_html_e( 'Actual rows', 'noxpress-migrator' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $report['db_bad'] as $tbl => $m ) : ?>
								<tr>
									<td><code><?php echo esc_html( (string) $tbl ); ?></code></td>
									<td><?php echo esc_html( (string) $m['expected'] ); ?></td>
									<td><?php echo esc_html( (string) $m['actual'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php if ( ! empty( $report['orphan_tables'] ) ) : ?>
					<h3><?php esc_html_e( 'Extra local tables (orphans)', 'noxpress-migrator' ); ?></h3>
					<ul style="list-style:disc;padding-left:22px;">
						<?php foreach ( $report['orphan_tables'] as $ot ) : ?>
							<li><code><?php echo esc_html( (string) $ot ); ?></code></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( ! empty( $report['files_bad'] ) ) : ?>
					<h3><?php esc_html_e( 'File mismatches', 'noxpress-migrator' ); ?></h3>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'File', 'noxpress-migrator' ); ?></th>
								<th><?php esc_html_e( 'Issue', 'noxpress-migrator' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $report['files_bad'] as $fb ) : ?>
								<tr>
									<td><code><?php echo esc_html( (string) $fb['path'] ); ?></code></td>
									<td><?php echo esc_html( (string) $fb['reason'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<p style="margin-top:24px;">
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '-verify&nm_verify_reset=1' ), 'nm_verify_reset' ) ); ?>">
						<?php esc_html_e( 'Clear verification state', 'noxpress-migrator' ); ?>
					</a>
				</p>

			<?php endif; ?>

			<?php self::footer(); ?>
		</div>
		<?php
	}

	/* =====================================================================
	 * Routes (admin_init — PRG)
	 * =================================================================== */

	public static function route_settings(): void {

		if ( ! isset( $_POST['nm_settings_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nm_settings_nonce'] ) ), 'nm_settings' ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'noxpress-migrator' ) );
		}
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'noxpress-migrator' ) );
		}

		$notices = array();

		// ---- Language ----
		$lang = isset( $_POST['nm_lang'] ) ? sanitize_key( wp_unslash( $_POST['nm_lang'] ) ) : 'auto';
		if ( in_array( $lang, array( 'el', 'en', 'auto' ), true ) ) {
			NM_Lang::set_lang( get_current_user_id(), $lang );
		}

		// ---- Volume size ----
		$vol = isset( $_POST['nm_volume_mb'] ) ? absint( $_POST['nm_volume_mb'] ) : 500;
		if ( $vol < 50 || $vol > 4096 ) {
			$notices[] = array( 'type' => 'error', 'text' => __( 'Invalid volume size (50–4096).', 'noxpress-migrator' ) );
		} else {
			update_option( 'nm_max_volume_mb', (string) $vol );
		}

		if ( empty( $notices ) ) {
			$notices[] = array( 'type' => 'success', 'text' => __( 'Settings saved.', 'noxpress-migrator' ) );
		}

		set_transient( 'nm_settings_msg_' . get_current_user_id(), $notices, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) );
		exit;
	}
	
		/* =====================================================================
	 * Routes: Verify (POST start / GET reset)
	 * =================================================================== */

	public static function route_verify(): void {

		// ---- POST: έναρξη verification ----
		if ( isset( $_POST['nm_verify_start'] ) ) {

			if ( ! isset( $_POST['nm_verify_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nm_verify_nonce'] ) ), 'nm_verify' ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'noxpress-migrator' ) );
			}
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'noxpress-migrator' ) );
			}
			if ( ! class_exists( 'NM_Verify' ) ) {
				wp_die( esc_html__( 'Verify module missing — partial plugin upload.', 'noxpress-migrator' ) );
			}

			$path = isset( $_POST['nm_manifest_path'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['nm_manifest_path'] ) ) ) : '';
			$mode = isset( $_POST['nm_verify_mode'] ) ? sanitize_key( wp_unslash( $_POST['nm_verify_mode'] ) ) : 'deep';

			if ( '' === $path ) {
				// Φόρτωση από το uploads root αν είναι κενό.
				$up = wp_upload_dir();
				$manifest_file = rtrim( (string) $up['basedir'], '/' ) . '/noxpress-migration-manifest.json';
				if ( is_readable( $manifest_file ) ) {
					$path = $manifest_file;
				}
			}

			if ( '' === $path ) {
				self::add_notice( 'error', __( 'Please provide the manifest.json path (absolute path, e.g., /home/user/site/wp-content/uploads/.../manifest.json)', 'noxpress-migrator' ) );
				return;
			}

			$result = NM_Verify::begin( $path, $mode );

			if ( ! $result['ok'] ) {
				self::add_notice( 'error', $result['error'] ?? 'Unknown error.' );
			} else {
				self::add_notice( 'success', __( 'Verification started — polling...', 'noxpress-migrator' ) );
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '-verify' ) );
				exit;
			}
		}

		// ---- GET: reset verification ----
		if ( isset( $_GET['nm_verify_reset'] ) ) {

			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'nm_verify_reset' ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'noxpress-migrator' ) );
			}
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'noxpress-migrator' ) );
			}

			NM_Verify::reset();
			self::add_notice( 'info', __( 'Verification state cleared.', 'noxpress-migrator' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '-verify' ) );
			exit;
		}
	}

	/** Helper για notices (PRG pattern — transient με user_id). */
	private static function add_notice( string $type, string $text ): void {
		set_transient(
			'nm_verify_msg_' . get_current_user_id(),
			array( 'type' => $type, 'text' => $text ),
			60
		);
	}
	
		/* =====================================================================
	 * Routes: nm_start (POST) / nm_delete + nm_download (GET, nonce)
	 * =================================================================== */

	public static function route_actions(): void {

		// ---- POST: έναρξη νέου export ----
		if ( isset( $_POST['nm_start'] ) ) {

			if ( ! isset( $_POST['nm_start_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nm_start_nonce'] ) ), 'nm_start' ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'noxpress-migrator' ) );
			}
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'noxpress-migrator' ) );
			}
			if ( ! class_exists( 'NM_Files_Engine' ) ) {
				wp_die( esc_html__( 'Files engine missing — partial plugin upload.', 'noxpress-migrator' ) );
			}

			$scope = isset( $_POST['nm_scope'] ) ? sanitize_key( wp_unslash( $_POST['nm_scope'] ) ) : 'everything';
			$after = isset( $_POST['nm_media_after'] ) ? sanitize_text_field( wp_unslash( $_POST['nm_media_after'] ) ) : '';

			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $after ) ) {
				$after = '';
			}

			NM_Files_Engine::begin( $scope, $after );

			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
			exit;
		}

		// ---- GET: διαγραφή πακέτου ----
		if ( isset( $_GET['nm_delete'] ) ) {

			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'nm_delete' ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'noxpress-migrator' ) );
			}
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'noxpress-migrator' ) );
			}

			if ( class_exists( 'NM_Files_Engine' ) ) {
				NM_Files_Engine::delete_package();
			}

			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
			exit;
		}

		// ---- GET: streaming download τόμου ----
		if ( isset( $_GET['nm_download'] ) ) {

			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'nm_download' ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'noxpress-migrator' ) );
			}
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'noxpress-migrator' ) );
			}
			if ( ! class_exists( 'NM_Files_Engine' ) ) {
				wp_die( esc_html__( 'Files engine missing — partial plugin upload.', 'noxpress-migrator' ) );
			}

			$name  = isset( $_GET['nm_download'] ) ? sanitize_file_name( wp_unslash( $_GET['nm_download'] ) ) : '';
			$paths = NM_Files_Engine::final_paths();

			// Whitelist match — το όνομα ΠΡΕΠΕΙ να είναι ένα από τα
			// πραγματικά volumes του nm_last_manifest (κανένα path
			// traversal: δεν παίρνουμε ποτέ path από το request, μόνο
			// όνομα που συγκρίνεται με τη λίστα).
			$hit = null;
			foreach ( $paths as $v ) {
				if ( $v['file'] === $name ) {
					$hit = $v;
					break;
				}
			}

			if ( null === $hit || ! is_readable( $hit['full'] ) ) {
				wp_die( esc_html__( 'Το αρχείο δεν βρέθηκε.', 'noxpress-migrator' ) );
			}

			// Read-back verification ΠΡΙΝ το streaming — αν το staging
			// έχει φαγωθεί από οτιδήποτε, το πιάνουμε ΕΔΩ.
			$ver = NM_Files_Engine::verify_volumes();
			if ( true !== $ver ) {
				wp_die( esc_html__( 'Checksum mismatch — το αρχείο δεν είναι αξιόπιστο. Ξεκίνα νέο export.', 'noxpress-migrator' ) );
			}

			nocache_headers();
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . $hit['file'] . '"' );
			header( 'Content-Length: ' . (string) $hit['bytes'] );
			header( 'X-Content-Type-Options: nosniff' );

			// Streaming download — 4 MiB chunks, ποτέ readfile() σε
			// gigabyte αρχεία (μνήμη) ούτε fpassthru χωρίς bounds.
			$src = @fopen( $hit['full'], 'rb' );
			if ( false === $src ) {
				wp_die( esc_html__( 'Το αρχείο δεν μπορεί να διαβαστεί.', 'noxpress-migrator' ) );
			}

			while ( ! feof( $src ) ) {
				$chunk = fread( $src, 4194304 );
				if ( false === $chunk ) {
					break;
				}
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput -- binary stream.
				flush();
			}

			fclose( $src );
			exit;
		}
	}

	/* =====================================================================
	 * Ajax handlers (progress step + abort)
	 * =================================================================== */

	public static function ajax_step(): void {

		check_ajax_referer( 'nm_progress', 'nonce' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		// Το orchestrator κρίνει φάση: db → files → packaged.
		if ( class_exists( 'NM_Files_Engine' ) ) {
			wp_send_json( NM_Files_Engine::run_step() );
		}

		$db = NM_Export_Engine::start( false );
		wp_send_json( $db->step() );
	}

	public static function ajax_abort(): void {

		check_ajax_referer( 'nm_progress', 'nonce' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		if ( class_exists( 'NM_Files_Engine' ) ) {
			NM_Files_Engine::abort();
			wp_send_json_success();
		}

		$db = NM_Export_Engine::start( false );
		$db->abort();
		wp_send_json_success();
	}

	/** Read-only status (χωρίς stepping) — για symptom-refresh του UI. */
	public static function ajax_status(): void {

		check_ajax_referer( 'nm_progress', 'nonce' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		if ( class_exists( 'NM_Files_Engine' ) ) {
			wp_send_json( NM_Files_Engine::current_report() );
		}

		$db = NM_Export_Engine::start( false );
		wp_send_json( $db->report() );
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	private static function take_notices(): array {
		$raw = get_transient( 'nm_settings_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'nm_settings_msg_' . get_current_user_id() );
			return $raw;
		}
		return array();
	}

	private static function footer(): void {
		?>
		<hr style="margin-top:24px;" />
		<p class="nm-footer">
			<?php
			printf(
				/* translators: %s: creator */
				esc_html__( 'Made with <3 by %s', 'noxpress-migrator' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="nm-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="nm-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'noxpress-migrator' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<?php
	}

	/** AJAX handler για το Verify stepping. */
	public static function ajax_verify_step(): void {

		check_ajax_referer( 'nm_progress', 'nonce' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ) );
		}

		if ( class_exists( 'NM_Verify' ) ) {
			wp_send_json( NM_Verify::step() );
		}

		wp_send_json_error( array( 'message' => 'Verify module not found.' ) );
	}
}

// Hook ajax handlers (must happen after plugins_loaded)
add_action( 'wp_ajax_nm_progress_step', array( 'NM_Admin_UI', 'ajax_step' ) );
add_action( 'wp_ajax_nm_abort',         array( 'NM_Admin_UI', 'ajax_abort' ) );
add_action( 'wp_ajax_nm_status',         array( 'NM_Admin_UI', 'ajax_status' ) );
add_action( 'wp_ajax_nm_verify_step',    array( 'NM_Admin_UI', 'ajax_verify_step' ) );
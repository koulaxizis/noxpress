<?php
/**
 * Admin UI: Dashboard + Ρυθμίσεις.
 *
 * v1.1.0:
 *  - Φίλτρο προϊόντος (περνά product_ids στο report engine).
 *  - Φίλτρο δικαιούχου (view-level: KPI καθαρών κερδών + φιλτραρισμένος πίνακας).
 *  - Επιλογή γλώσσας ανά χρήστη (RS_Lang).
 *  - Credits footer (Made with ♥ by Christos Koulaxizis).
 */

defined( 'ABSPATH' ) || exit;

class RS_Admin_UI {

	const CAP       = 'manage_woocommerce';
	const PARENT    = 'revenue-splitter';
	const SLUG_DASH = 'revenue-splitter-dashboard';
	const SLUG_SET  = 'revenue-splitter-settings';

	public static function init(): void {

		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		// Cache flush όταν αποθηκεύεται/ενημερώνεται προϊόν.
		add_action( 'woocommerce_new_product', array( __CLASS__, 'invalidate' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'invalidate' ) );

		// Admin notices από αποθηκευμένα transients (errors του metabox/settings).
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_stored_errors' ) );
	}

	/* ---------------------------------------------------------------------
	 * Menu
	 * ------------------------------------------------------------------- */

	public static function menu(): void {

		add_menu_page(
			__( 'Revenue Splitter', 'revenue-splitter' ),
			__( 'Revenue Splitter', 'revenue-splitter' ),
			self::CAP,
			self::PARENT,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-chart-pie',
			56
		);

		add_submenu_page(
			self::PARENT,
			__( 'Dashboard', 'revenue-splitter' ),
			__( 'Dashboard', 'revenue-splitter' ),
			self::CAP,
			self::SLUG_DASH,
			array( __CLASS__, 'render_dashboard' )
		);

		add_submenu_page(
			self::PARENT,
			__( 'Ρυθμίσεις', 'revenue-splitter' ),
			__( 'Ρυθμίσεις', 'revenue-splitter' ),
			self::CAP,
			self::SLUG_SET,
			array( __CLASS__, 'render_settings' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Assets — scoped: μόνο στις σελίδες μας + στο product edit screen.
	 * ------------------------------------------------------------------- */

	public static function assets( string $hook_suffix ): void {

		$is_ours = false !== strpos( $hook_suffix, 'revenue-splitter' );
		$is_prod = in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true )
			&& isset( $_GET['post'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& 'product' === get_post_type( absint( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $is_ours && ! $is_prod ) {
			return;
		}

		wp_enqueue_style( 'rs-admin', RS_URL . 'assets/admin.css', array(), RS_VERSION );
		wp_enqueue_script( 'rs-admin', RS_URL . 'assets/admin.js', array(), RS_VERSION, true );
	}

	public static function invalidate( int $unused_product_id = 0 ): void {
		do_action( 'rs_invalidate_cache' );
	}

	/**
	 * Εμφανίζει (και σβήνει) τα αποθηκευμένα notices του χρήστη.
	 */
	public static function maybe_show_stored_errors(): void {

		$uid = get_current_user_id();

		$err = get_transient( 'rs_split_error_' . $uid );
		if ( is_string( $err ) && '' !== $err ) {
			delete_transient( 'rs_split_error_' . $uid );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ) . '</p></div>';
		}

		$ok = get_transient( 'rs_split_ok_' . $uid );
		if ( is_string( $ok ) && '' !== $ok ) {
			delete_transient( 'rs_split_ok_' . $uid );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $ok ) . '</p></div>';
		}
	}

	/* ---------------------------------------------------------------------
	 * Dashboard
	 * ------------------------------------------------------------------- */

	public static function render_dashboard(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}

		// ---------- POST: περίοδος / φίλτρα / export ----------
		$period = self::current_period();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['rs_dashboard_nonce'] )
			&& wp_verify_nonce( sanitize_key( $_POST['rs_dashboard_nonce'] ), 'rs_dashboard' ) ) {

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$start = isset( $_POST['rs_date_start'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_date_start'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$end = isset( $_POST['rs_date_end'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_date_end'] ) ) : '';

			if ( self::valid_date( $start ) && self::valid_date( $end ) && $start <= $end ) {
				$period = array( 'start' => $start, 'end' => $end );
				update_user_meta( get_current_user_id(), 'rs_last_period', $period );
			}

			// Φίλτρα (ισχύουν και για το export).
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$f_product = isset( $_POST['rs_filter_product'] ) ? absint( $_POST['rs_filter_product'] ) : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$f_ben = isset( $_POST['rs_filter_beneficiary'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_filter_beneficiary'] ) ) : '';

			update_user_meta(
				get_current_user_id(),
				'rs_last_filters',
				array(
					'product'     => $f_product,
					'beneficiary' => $f_ben,
				)
			);

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( isset( $_POST['rs_do_export'] ) ) {
				$export_args = array(
					'date_start' => $period['start'],
					'date_end'   => $period['end'],
				);
				if ( $f_product > 0 ) {
					$export_args['product_ids'] = array( $f_product );
				}
				self::stream_csv( RS_Reports::run( $export_args ) );
			}
		}

		// ---------- Φίλτρα από user meta ----------
		$filters     = get_user_meta( get_current_user_id(), 'rs_last_filters', true );
		$filter_prod = is_array( $filters ) ? max( 0, (int) ( $filters['product'] ?? 0 ) ) : 0;
		$filter_ben  = is_array( $filters ) ? (string) ( $filters['beneficiary'] ?? '' ) : '';

		// ---------- Report ----------
		$args = array(
			'date_start' => $period['start'],
			'date_end'   => $period['end'],
		);
		if ( $filter_prod > 0 ) {
			$args['product_ids'] = array( $filter_prod );
		}
		$report = RS_Reports::run( $args );

		// ---------- Dropdowns ----------
		$products_for_filter = wc_get_products(
			array(
				'limit'   => -1,
				'orderby' => 'title',
				'order'   => 'ASC',
				'status'  => array( 'publish', 'private', 'draft' ),
			)
		);

		$ben_names = self::collect_beneficiary_names();

		// ---------- Φίλτρο δικαιούχου (view-level) ----------
		$author_earnings  = 0.0;
		$visible_products = $report['products'];

		if ( '' !== $filter_ben ) {
			foreach ( $report['products'] as $p ) {
				foreach ( $p['splits'] as $s ) {
					if ( $s['name'] === $filter_ben ) {
						$author_earnings += (float) $s['amount'];
					}
				}
			}
			$author_earnings = round( $author_earnings, 2 );

			// Κρατάμε ΜΟΝΟ τα βιβλία όπου ο δικαιούχος μετέχει.
			$visible_products = array_values(
				array_filter(
					$report['products'],
					static function ( $p ) use ( $filter_ben ) {
						foreach ( $p['splits'] as $s ) {
							if ( $s['name'] === $filter_ben ) {
								return true;
							}
						}
						return false;
					}
				)
			);
		}

		?>
		<div class="wrap rs-wrap">

			<h1><?php esc_html_e( 'Revenue Splitter — Dashboard', 'revenue-splitter' ); ?></h1>

			<form method="post" class="rs-period-form">
				<?php wp_nonce_field( 'rs_dashboard', 'rs_dashboard_nonce' ); ?>

				<select name="rs_period_preset" id="rs-period-preset">
					<option value="7"><?php esc_html_e( 'Τελευταίες 7 ημέρες', 'revenue-splitter' ); ?></option>
					<option value="30"><?php esc_html_e( 'Τελευταίες 30 ημέρες', 'revenue-splitter' ); ?></option>
					<option value="month"><?php esc_html_e( 'Τρέχων μήνας', 'revenue-splitter' ); ?></option>
					<option value="year"><?php esc_html_e( 'Τρέχον έτος', 'revenue-splitter' ); ?></option>
					<option value="custom"><?php esc_html_e( 'Προσαρμοσμένο', 'revenue-splitter' ); ?></option>
				</select>

				<input type="date" name="rs_date_start" value="<?php echo esc_attr( $period['start'] ); ?>" />
				<input type="date" name="rs_date_end" value="<?php echo esc_attr( $period['end'] ); ?>" />

				<select name="rs_filter_product">
					<option value="0"><?php esc_html_e( 'Όλα τα προϊόντα', 'revenue-splitter' ); ?></option>
					<?php foreach ( $products_for_filter as $pf ) : ?>
						<option value="<?php echo esc_attr( $pf->get_id() ); ?>" <?php selected( $filter_prod, $pf->get_id() ); ?>>
							<?php echo esc_html( $pf->get_name() ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<select name="rs_filter_beneficiary">
					<option value=""><?php esc_html_e( 'Όλοι οι δικαιούχοι', 'revenue-splitter' ); ?></option>
					<?php foreach ( $ben_names as $bname ) : ?>
						<option value="<?php echo esc_attr( $bname ); ?>" <?php selected( $filter_ben, $bname ); ?>>
							<?php echo esc_html( $bname ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Εφαρμογή', 'revenue-splitter' ); ?>
				</button>
				<button type="submit" name="rs_do_export" value="1" class="button">
					<?php esc_html_e( 'Εξαγωγή CSV', 'revenue-splitter' ); ?>
				</button>
			</form>

			<?php if ( '' !== $filter_ben ) : ?>
				<!-- KPI συγγραφέα (view-level φίλτρο) -->
				<div class="rs-kpis">
					<div class="rs-kpi">
						<span class="rs-kpi-label">
							<?php
							printf(
								/* translators: %s: beneficiary name */
								esc_html__( 'Καθαρά κέρδη: %s', 'revenue-splitter' ),
								esc_html( $filter_ben )
							);
							?>
						</span>
						<strong><?php echo esc_html( self::money( $author_earnings ) ); ?></strong>
					</div>
				</div>
			<?php endif; ?>

			<!-- KPIs -->
			<div class="rs-kpis">
				<div class="rs-kpi">
					<span class="rs-kpi-label"><?php esc_html_e( 'Παραγγελίες', 'revenue-splitter' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $report['order_count'] ) ); ?></strong>
				</div>
				<div class="rs-kpi">
					<span class="rs-kpi-label"><?php esc_html_e( 'Μικτό (με ΦΠΑ)', 'revenue-splitter' ); ?></span>
					<strong><?php echo esc_html( self::money( $report['totals']['gross'] ) ); ?></strong>
				</div>
				<div class="rs-kpi">
					<span class="rs-kpi-label"><?php esc_html_e( 'ΦΠΑ', 'revenue-splitter' ); ?></span>
					<strong><?php echo esc_html( self::money( $report['totals']['vat'] ) ); ?></strong>
				</div>
				<div class="rs-kpi">
					<span class="rs-kpi-label"><?php esc_html_e( 'Καθαρό (πριν καταμερισμό)', 'revenue-splitter' ); ?></span>
					<strong><?php echo esc_html( self::money( $report['totals']['net'] ) ); ?></strong>
				</div>
			</div>

			<!-- Πίνακας ανά προϊόν -->
			<h2><?php esc_html_e( 'Ανά προϊόν', 'revenue-splitter' ); ?></h2>

			<?php if ( empty( $visible_products ) ) : ?>
				<p class="rs-empty">
					<?php esc_html_e( 'Καμία πωλημένη γραμμή στην περίοδο.', 'revenue-splitter' ); ?>
				</p>
			<?php else : ?>
				<table class="widefat striped rs-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Προϊόν', 'revenue-splitter' ); ?></th>
							<th class="num"><?php esc_html_e( 'Τεμ.', 'revenue-splitter' ); ?></th>
							<th class="num"><?php esc_html_e( 'Μικτό', 'revenue-splitter' ); ?></th>
							<th class="num"><?php esc_html_e( 'ΦΠΑ', 'revenue-splitter' ); ?></th>
							<th class="num"><?php esc_html_e( 'Καθαρό', 'revenue-splitter' ); ?></th>
							<th><?php esc_html_e( 'Καταμερισμός', 'revenue-splitter' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $visible_products as $p ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $p['title'] ); ?></strong>
								<small>(<?php echo esc_html( number_format_i18n( $p['vat_rate'], 2 ) ); ?>% ΦΠΑ)</small>
								<?php if ( ! empty( $p['ben_default'] ) ) : ?>
									<span class="rs-badge"><?php esc_html_e( 'global defaults', 'revenue-splitter' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="num"><?php echo esc_html( number_format_i18n( $p['qty'] ) ); ?></td>
							<td class="num"><?php echo esc_html( self::money( $p['gross'] ) ); ?></td>
							<td class="num rs-neg"><?php echo esc_html( '−' . self::money( $p['vat'] ) ); ?></td>
							<td class="num"><strong><?php echo esc_html( self::money( $p['net'] ) ); ?></strong></td>
							<td class="rs-splits">
								<?php foreach ( $p['splits'] as $s ) : ?>
									<?php if ( '' !== $filter_ben && $s['name'] !== $filter_ben ) { continue; } ?>
									<span class="rs-split-item">
										<?php
										printf(
											/* translators: 1: name, 2: amount, 3: percent */
											esc_html__( '%1$s: %2$s (%3$s%%)', 'revenue-splitter' ),
											esc_html( $s['name'] ),
											esc_html( self::money( $s['amount'] ) ),
											esc_html( number_format_i18n( $s['percent'], 2 ) )
										);
										?>
									</span>
								<?php endforeach; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<!-- Σύνολα ανά δικαιούχο -->
			<h2><?php esc_html_e( 'Σύνολα ανά δικαιούχο', 'revenue-splitter' ); ?></h2>

			<?php
			$ben_rows = $report['beneficiaries'];
			if ( '' !== $filter_ben ) {
				$ben_rows = array_values(
					array_filter(
						$report['beneficiaries'],
						static function ( $b ) use ( $filter_ben ) {
							return $b['name'] === $filter_ben;
						}
					)
				);
			}
			?>

			<?php if ( empty( $ben_rows ) ) : ?>
				<p class="rs-empty"><?php esc_html_e( 'Δεν υπάρχουν δεδομένα δικαιούχων στην περίοδο.', 'revenue-splitter' ); ?></p>
			<?php else : ?>
				<table class="widefat striped rs-table rs-ben-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Δικαιούχος', 'revenue-splitter' ); ?></th>
							<th class="num"><?php esc_html_e( 'Ποσό', 'revenue-splitter' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $ben_rows as $b ) : ?>
						<tr>
							<td><?php echo esc_html( $b['name'] ); ?></td>
							<td class="num"><strong><?php echo esc_html( self::money( $b['amount'] ) ); ?></strong></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php self::footer(); ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Ρυθμίσεις
	 * ------------------------------------------------------------------- */

	public static function render_settings(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}

		// ---------- POST: αποθήκευση ρυθμίσεων ----------
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['rs_settings_nonce'] )
			&& wp_verify_nonce( sanitize_key( $_POST['rs_settings_nonce'] ), 'rs_save_settings' ) ) {

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$vat_raw = isset( $_POST['rs_default_vat'] ) ? trim( (string) wp_unslash( $_POST['rs_default_vat'] ) ) : '';

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$names = isset( $_POST['rs_ben_name'] ) ? (array) $_POST['rs_ben_name'] : array();
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$percs = isset( $_POST['rs_ben_pct'] ) ? (array) $_POST['rs_ben_pct'] : array();

			$rows = array();
			foreach ( $names as $i => $n ) {
				$rows[] = array(
					'name'    => $n,
					'percent' => $percs[ $i ] ?? '',
				);
			}

			$ben_clean = RS_Beneficiaries::sanitize_list( $rows );

			if ( '' === $vat_raw || ! is_numeric( $vat_raw ) || (float) $vat_raw < 0 || (float) $vat_raw > 100 ) {
				set_transient( 'rs_split_error_' . get_current_user_id(), __( 'Μη έγκυρο global default ΦΠΑ (0–100).', 'revenue-splitter' ), 60 );
			} elseif ( null === $ben_clean ) {
				set_transient( 'rs_split_error_' . get_current_user_id(), RS_Beneficiaries::error_message_for( $rows ), 60 );
			} else {
				update_option( RS_VAT::OPTION_KEY, (string) round( (float) $vat_raw, 2 ) );
				RS_Beneficiaries::set_defaults( $ben_clean );
				do_action( 'rs_invalidate_cache' );
				set_transient( 'rs_split_ok_' . get_current_user_id(), __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'revenue-splitter' ), 60 );
			}

			// Γλώσσα ανά χρήστη.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$lang = isset( $_POST['rs_lang'] ) ? sanitize_key( wp_unslash( $_POST['rs_lang'] ) ) : 'el';
			RS_Lang::set_lang( get_current_user_id(), $lang );

			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG_SET . '&saved=1' ) );
			exit;
		}

		$default_vat    = RS_VAT::default_rate();
		$defaults       = RS_Beneficiaries::get_defaults();
		$map            = is_array( $defaults ) ? $defaults : array(
			array(
				'name'    => 'Christos',
				'percent' => 100,
			),
		);
		$current_lang_user = RS_Lang::get_lang();
		?>

		<div class="wrap rs-wrap">
			<h1><?php esc_html_e( 'Revenue Splitter — Ρυθμίσεις', 'revenue-splitter' ); ?></h1>

			<form method="post">
				<?php wp_nonce_field( 'rs_save_settings', 'rs_settings_nonce' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row"><label for="rs-default-vat"><?php esc_html_e( 'Default ΦΠΑ (%)', 'revenue-splitter' ); ?></label></th>
						<td>
							<input type="number" step="0.01" min="0" max="100" id="rs-default-vat"
								name="rs_default_vat" value="<?php echo esc_attr( $default_vat ); ?>" class="small-text" />
							<p class="description">
								<?php esc_html_e( 'Ισχύει για προϊόντα χωρίς δικό τους ΦΠΑ.', 'revenue-splitter' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rs-lang"><?php esc_html_e( 'Γλώσσα οθόνης', 'revenue-splitter' ); ?></label></th>
						<td>
							<select id="rs-lang" name="rs_lang">
								<option value="el" <?php selected( $current_lang_user, 'el' ); ?>><?php esc_html_e( 'Ελληνικά', 'revenue-splitter' ); ?></option>
								<option value="en" <?php selected( $current_lang_user, 'en' ); ?>>English</option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Ισχύει ανά χρήστη (μόνο για εσένα).', 'revenue-splitter' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Global Δικαιούχοι', 'revenue-splitter' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Ο προεπιλεγμένος καταμερισμός για κάθε προϊόν χωρίς δικό του override.', 'revenue-splitter' ); ?>
				</p>

				<div class="rs-rows">
					<table class="widefat striped rs-split-table">
						<thead>
							<tr>
								<th class="rs-col-name"><?php esc_html_e( 'Δικαιούχος', 'revenue-splitter' ); ?></th>
								<th class="rs-col-pct"><?php esc_html_e( 'Ποσοστό (%)', 'revenue-splitter' ); ?></th>
								<th class="rs-col-del"></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $map as $row ) : ?>
							<tr class="rs-row">
								<td><input type="text" name="rs_ben_name[]" value="<?php echo esc_attr( $row['name'] ); ?>" class="rs-ben-name" /></td>
								<td><input type="number" step="0.01" min="0" max="100" name="rs_ben_pct[]" value="<?php echo esc_attr( $row['percent'] ); ?>" class="rs-ben-pct" /></td>
								<td><button type="button" class="button-link rs-remove-row" aria-label="<?php esc_attr_e( 'Αφαίρεση γραμμής', 'revenue-splitter' ); ?>">×</button></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<button type="button" class="button rs-add-row">
						+ <?php esc_html_e( 'Προσθήκη δικαιούχου', 'revenue-splitter' ); ?>
					</button>

					<p class="rs-total-line">
						<?php esc_html_e( 'Σύνολο:', 'revenue-splitter' ); ?>
						<strong class="rs-total">100</strong>%
					</p>
				</div>

				<?php submit_button( __( 'Αποθήκευση ρυθμίσεων', 'revenue-splitter' ) ); ?>
			</form>

			<?php self::footer(); ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Συλλογή όλων των ονομάτων δικαιούχων (defaults + όλα τα overrides)
	 * για το dropdown φίλτρου.
	 *
	 * @return string[]
	 */
	private static function collect_beneficiary_names(): array {

		$names = array();

		$defaults = RS_Beneficiaries::get_defaults();
		if ( is_array( $defaults ) ) {
			foreach ( $defaults as $ben ) {
				if ( ! empty( $ben['name'] ) ) {
					$names[] = (string) $ben['name'];
				}
			}
		}

		// Overrides: προϊόντα με meta _rs_split.
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => RS_Beneficiaries::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		foreach ( $ids as $pid ) {
			foreach ( RS_Beneficiaries::get_map( (int) $pid ) as $ben ) {
				if ( ! empty( $ben['name'] ) ) {
					$names[] = (string) $ben['name'];
				}
			}
		}

		$names = array_values( array_unique( $names ) );
		sort( $names, SORT_STRING );

		return $names;
	}

	private static function current_period(): array {

		$stored = get_user_meta( get_current_user_id(), 'rs_last_period', true );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI state.
		$preset = isset( $_GET['preset'] ) ? sanitize_key( wp_unslash( $_GET['preset'] ) ) : '';

		$now = new DateTimeImmutable( 'now', wp_timezone() );

		switch ( $preset ) {
			case '7':
				return array(
					'start' => $now->modify( '-6 days' )->format( 'Y-m-d' ),
					'end'   => $now->format( 'Y-m-d' ),
				);
			case 'month':
				return array(
					'start' => $now->format( 'Y-m-01' ),
					'end'   => $now->format( 'Y-m-d' ),
				);
			case 'year':
				return array(
					'start' => $now->format( 'Y-01-01' ),
					'end'   => $now->format( 'Y-m-d' ),
				);
			case '30':
				return array(
					'start' => $now->modify( '-29 days' )->format( 'Y-m-d' ),
					'end'   => $now->format( 'Y-m-d' ),
				);
		}

		if ( is_array( $stored ) && isset( $stored['start'], $stored['end'] ) ) {
			return $stored;
		}

		return array(
			'start' => $now->modify( '-29 days' )->format( 'Y-m-d' ),
			'end'   => $now->format( 'Y-m-d' ),
		);
	}

	private static function valid_date( string $d ): bool {
		$dt = DateTimeImmutable::createFromFormat( '!Y-m-d', $d );
		return false !== $dt && $dt->format( 'Y-m-d' ) === $d;
	}

	private static function money( float $amount ): string {
		return number_format_i18n( $amount, 2 ) . ' €';
	}

	/**
	 * Credits footer — όλες οι σελίδες του plugin.
	 */
	private static function footer(): void {
		?>
		<p class="rs-footer">
			<?php
			printf(
				/* translators: 1: version, 2: author link */
				esc_html( __( 'Revenue Splitter v%1$s — Made with ♥ by %2$s', 'revenue-splitter' ) ),
				esc_html( RS_VERSION ),
				wp_kses_post( '<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>' )
			);
			?>
		</p>
		<?php
	}

	/**
	 * CSV εξαγωγή — native, χωρίς βιβλιοθήκες.
	 */
	private static function stream_csv( array $report ): void {

		$filename = 'revenue-splitter-' . $report['period']['start'] . '_' . $report['period']['end'] . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM ώστε το Excel να διαβάζει σωστά τα ελληνικά.
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, array( __( 'Περίοδος', 'revenue-splitter' ), $report['period']['start'], $report['period']['end'] ) );
		fputcsv( $out, array() );

		$headers = array( 'ID', 'Προϊόν', 'Τεμ.', 'Μικτό', 'ΦΠΑ %', 'ΦΠΑ', 'Καθαρό', 'Καταμερισμός' );
		fputcsv( $out, $headers );

		foreach ( $report['products'] as $p ) {
			$splits = implode( ' | ', array_map(
				static fn( $s ) => $s['name'] . ': ' . number_format( $s['amount'], 2, ',', '.' ) . ' € (' . $s['percent'] . '%)',
				$p['splits']
			) );

			fputcsv( $out, array(
				$p['product_id'],
				$p['title'],
				$p['qty'],
				number_format( $p['gross'], 2, ',', '' ),
				number_format( $p['vat_rate'], 2, ',', '' ),
				number_format( $p['vat'], 2, ',', '' ),
				number_format( $p['net'], 2, ',', '' ),
				$splits,
			) );
		}

		fputcsv( $out, array() );
		fputcsv( $out, array( '', '', '', '', '', 'ΜΙΚΤΑ:', '', '' ) );
		fputcsv( $out, array(
			'', '', '',
			number_format( $report['totals']['gross'], 2, ',', '' ),
			'',
			number_format( $report['totals']['vat'], 2, ',', '' ),
			number_format( $report['totals']['net'], 2, ',', '' ),
			'',
		) );

		fclose( $out );
		exit;
	}
}
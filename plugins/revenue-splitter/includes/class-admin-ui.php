<?php
/**
 * Admin UI: Dashboard + Ρυθμίσεις + Widget.
 *
 * v1.1.2:
 *  - Φίλτρα προϊόντος/δικαιούχου ΛΕΙΤΟΥΡΓΙΚΑ (βλ. class-reports.php fix).
 *  - Αναζήτηση σε τίτλους (view-level, mb-safe).
 *  - Dashboard widget «Γρήγορη ματιά» (μήνας έως σήμερα + top δικαιούχοι).
 *  - Εξαγωγές: CSV / XLS (SpreadsheetML, native Excel) / HTML / JSON
 *    — όλες zero-dependency, όλες σέβονται τα ενεργά φίλτρα.
 *  - File-based cache busting (filemtime) για assets.
 *
 * v1.1.2 AUDIT FIX:
 *  - (#3) Καθαρό μενού: το parent slug ΕΙΝΑΙ το dashboard — δεν υπάρχει
 *    διπλή καταχώριση «Revenue Splitter» + «Dashboard» που δείχνουν στο
 *    ίδιο σημείο.
 *  - (#4) Το preset dropdown συγχρονίζεται με την αποθηκευμένη περίοδο
 *    (server-side matching, default «Προσαρμοσμένο»).
 *  - (#7) fputcsv() με explicit separator/enclosure/escape — χωρίς
 *    deprecated implicit defaults σε PHP 8.4+.
 *  - (#12) Label «Παραγγελίες (περιόδου)» όταν είναι ενεργό φίλτρο
 *    δικαιούχου/αναζήτησης, για να μην μπερδεύεται το order_count.
 */

defined( 'ABSPATH' ) || exit;

class RS_Admin_UI {

	const CAP       = 'manage_woocommerce';
	const SLUG_DASH = 'revenue-splitter-dashboard';
	const SLUG_SET  = 'revenue-splitter-settings';

	public static function init(): void {

		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widget' ) );

		// Cache flush όταν αποθηκεύεται/ενημερώνεται προϊόν.
		add_action( 'woocommerce_new_product', array( __CLASS__, 'invalidate' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'invalidate' ) );

		// Cache flush όταν αλλάζει η παραγγελιακή βάση.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'invalidate' ) );
		add_action( 'woocommerce_refund_created', array( __CLASS__, 'invalidate' ) );

		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_stored_errors' ) );
	}

	/* ---------------------------------------------------------------------
	 * Menu
	 * ------------------------------------------------------------------- */

	public static function menu(): void {

		/*
		 * AUDIT FIX (#3): parent = dashboard. Το add_menu_page() με το ίδιο
		 * slug κάνει το πρώτο submenu να υποκαθίσταται σωστά — το WP δείχνει
		 * «Dashboard» ως πρώτο submenu που δείχνει στο parent. Κανένα duplicate.
		 */
		add_menu_page(
			__( 'Revenue Splitter — Dashboard', 'revenue-splitter' ),
			__( 'Revenue Splitter', 'revenue-splitter' ),
			self::CAP,
			self::SLUG_DASH,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-chart-pie',
			56
		);

		add_submenu_page(
			self::SLUG_DASH,
			__( 'Revenue Splitter — Dashboard', 'revenue-splitter' ),
			__( 'Dashboard', 'revenue-splitter' ),
			self::CAP,
			self::SLUG_DASH,
			array( __CLASS__, 'render_dashboard' )
		);

		add_submenu_page(
			self::SLUG_DASH,
			__( 'Ρυθμίσεις', 'revenue-splitter' ),
			__( 'Ρυθμίσεις', 'revenue-splitter' ),
			self::CAP,
			self::SLUG_SET,
			array( __CLASS__, 'render_settings' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------- */

	public static function assets( string $hook_suffix ): void {

		$is_ours = false !== strpos( $hook_suffix, 'revenue-splitter' );
		$is_prod = in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true )
			&& isset( $_GET['post'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& 'product' === get_post_type( absint( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $is_ours && ! $is_prod ) {
			return;
		}

		/*
		 * File-based cache busting με defensive guard: αν για οποιονδήποτε
		 * λόγο το αρχείο δεν υπάρχει (μισό deploy, plugin file removed),
		 * πέφτουμε στο RS_VERSION αντί για PHP warning + bad ?ver=.
		 */
		$css_file = RS_PATH . 'assets/admin.css';
		$js_file  = RS_PATH . 'assets/admin.js';

		$css_mtime = @filemtime( $css_file );
		$js_mtime  = @filemtime( $js_file );

		$css_ver = $css_mtime ? (string) $css_mtime : RS_VERSION;
		$js_ver  = $js_mtime ? (string) $js_mtime : RS_VERSION;

		wp_enqueue_style( 'rs-admin', RS_URL . 'assets/admin.css', array(), $css_ver );
		wp_enqueue_script( 'rs-admin', RS_URL . 'assets/admin.js', array(), $js_ver, true );
	}

	public static function invalidate( int $unused_id = 0 ): void {
		do_action( 'rs_invalidate_cache' );
	}

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
	 * Period helpers
	 * ------------------------------------------------------------------- */

	/** Τελευταία αποθηκευμένη περίοδος χρήστη, αλλιώς default 30 ημέρες. */
	private static function current_period(): array {

		$saved = get_user_meta( get_current_user_id(), 'rs_last_period', true );

		if ( is_array( $saved )
			&& isset( $saved['start'], $saved['end'] )
			&& self::valid_date( (string) $saved['start'] )
			&& self::valid_date( (string) $saved['end'] )
			&& $saved['start'] <= $saved['end'] ) {
			return array(
				'start' => (string) $saved['start'],
				'end'   => (string) $saved['end'],
			);
		}

		$now = new DateTimeImmutable( 'now', wp_timezone() );

		return array(
			'start' => $now->modify( '-29 days' )->format( 'Y-m-d' ),
			'end'   => $now->format( 'Y-m-d' ),
		);
	}

	/** Έλεγχος ότι το string είναι έγκυρη ημερομηνία 'Y-m-d'. */
	private static function valid_date( string $date ): bool {

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
			return false;
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/*
	 * AUDIT FIX (#4): Server-side υπολογισμός του preset που ταιριάζει
	 * στην (αποθηκευμένη ή υπολογισμένη) περίοδο — 'custom' όταν δεν
	 * ταιριάζει πουθενά. Το dropdown ανοίγει έτσι σωστά συγχρονισμένο.
	 */
	private static function matching_preset( array $period ): string {

		$now = new DateTimeImmutable( 'now', wp_timezone() );

		$preset_starts = array(
			'7'     => $now->modify( '-6 days' )->format( 'Y-m-d' ),
			'30'    => $now->modify( '-29 days' )->format( 'Y-m-d' ),
			'month' => $now->format( 'Y-m-01' ),
			'year'  => $now->format( 'Y-01-01' ),
		);

		$end = $now->format( 'Y-m-d' );

		foreach ( $preset_starts as $key => $start ) {
			if ( $period['start'] === $preset_starts[ $key ] && $period['end'] === $end ) {
				return (string) $key;
			}
		}

		return 'custom';
	}

	/* ---------------------------------------------------------------------
	 * Dashboard
	 * ------------------------------------------------------------------- */

	public static function render_dashboard(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}

		$period = self::current_period();

		// ---------- POST: περίοδος / φίλτρα / export ----------
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

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$f_product = isset( $_POST['rs_filter_product'] ) ? absint( $_POST['rs_filter_product'] ) : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$f_ben = isset( $_POST['rs_filter_beneficiary'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_filter_beneficiary'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$f_search = isset( $_POST['rs_search'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_search'] ) ) : '';

			update_user_meta(
				get_current_user_id(),
				'rs_last_filters',
				array(
					'product'     => $f_product,
					'beneficiary' => $f_ben,
					'search'      => $f_search,
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

				// Το export σέβεται ΟΛΑ τα φίλτρα: προϊόν (engine) + δικαιούχο + αναζήτηση (reducer).
				$export_report = self::filter_report_for_display(
					RS_Reports::run( $export_args ),
					$f_ben,
					$f_search
				);

				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$fmt = isset( $_POST['rs_export_format'] ) ? sanitize_key( wp_unslash( $_POST['rs_export_format'] ) ) : 'csv';

				switch ( $fmt ) {
					case 'xls':
						self::stream_xls( $export_report );
						break;
					case 'html':
						self::stream_html( $export_report );
						break;
					case 'json':
						self::stream_json( $export_report );
						break;
					default:
						self::stream_csv( $export_report );
				}
			}
		}

		// ---------- Φίλτρα από user meta ----------
		$filters        = get_user_meta( get_current_user_id(), 'rs_last_filters', true );
		$filter_prod    = is_array( $filters ) ? max( 0, (int) ( $filters['product'] ?? 0 ) ) : 0;
		$filter_ben     = is_array( $filters ) ? (string) ( $filters['beneficiary'] ?? '' ) : '';
		$filter_search  = is_array( $filters ) ? trim( (string) ( $filters['search'] ?? '' ) ) : '';

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

		// ---------- View-level: αναζήτηση + δικαιούχος ----------
		$filtered_report  = self::filter_report_for_display( $report, $filter_ben, $filter_search );
		$visible_products = $filtered_report['products'];

		$author_earnings = 0.0;
		if ( '' !== $filter_ben && ! empty( $filtered_report['beneficiaries'] ) ) {
			$author_earnings = round( (float) $filtered_report['beneficiaries'][0]['amount'], 2 );
		}

		// (#4) Συγχρονισμός preset με την πραγματική περίοδο.
		$active_preset = self::matching_preset( $period );

		// (#12) Label παραγγελιών όταν δουλεύουν view-level φίλτρα.
		$orders_label = ( '' !== $filter_ben || '' !== $filter_search )
			? __( 'Παραγγελίες (περιόδου)', 'revenue-splitter' )
			: __( 'Παραγγελίες', 'revenue-splitter' );

		?>
		<div class="wrap rs-wrap">

			<h1><?php esc_html_e( 'Revenue Splitter — Dashboard', 'revenue-splitter' ); ?></h1>

			<form method="post" class="rs-period-form">
				<?php wp_nonce_field( 'rs_dashboard', 'rs_dashboard_nonce' ); ?>

				<select name="rs_period_preset" id="rs-period-preset">
					<option value="7" <?php selected( $active_preset, '7' ); ?>><?php esc_html_e( 'Τελευταίες 7 ημέρες', 'revenue-splitter' ); ?></option>
					<option value="30" <?php selected( $active_preset, '30' ); ?>><?php esc_html_e( 'Τελευταίες 30 ημέρες', 'revenue-splitter' ); ?></option>
					<option value="month" <?php selected( $active_preset, 'month' ); ?>><?php esc_html_e( 'Τρέχων μήνας', 'revenue-splitter' ); ?></option>
					<option value="year" <?php selected( $active_preset, 'year' ); ?>><?php esc_html_e( 'Τρέχον έτος', 'revenue-splitter' ); ?></option>
					<option value="custom" <?php selected( $active_preset, 'custom' ); ?>><?php esc_html_e( 'Προσαρμοσμένο', 'revenue-splitter' ); ?></option>
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

				<input type="search" name="rs_search" class="rs-search"
					placeholder="<?php esc_attr_e( 'Αναζήτηση σε τίτλους…', 'revenue-splitter' ); ?>"
					value="<?php echo esc_attr( $filter_search ); ?>" />

				<select name="rs_export_format">
					<option value="csv">CSV</option>
					<option value="xls">Excel (XLS)</option>
					<option value="html">HTML</option>
					<option value="json">JSON</option>
				</select>

				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Εφαρμογή', 'revenue-splitter' ); ?>
				</button>
				<button type="submit" name="rs_do_export" value="1" class="button">
					<?php esc_html_e( 'Εξαγωγή', 'revenue-splitter' ); ?>
				</button>
			</form>

			<?php if ( '' !== $filter_ben ) : ?>
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
					<span class="rs-kpi-label"><?php echo esc_html( $orders_label ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $filtered_report['order_count'] ) ); ?></strong>
				</div>
				<div class="rs-kpi">
					<span class="rs-kpi-label"><?php esc_html_e( 'Μικτό (με ΦΠΑ)', 'revenue-splitter' ); ?></span>
					<strong><?php echo esc_html( self::money( $filtered_report['totals']['gross'] ) ); ?></strong>
				</div>
				<div class="rs-kpi">
					<span class="rs-kpi-label"><?php esc_html_e( 'ΦΠΑ', 'revenue-splitter' ); ?></span>
					<strong><?php echo esc_html( self::money( $filtered_report['totals']['vat'] ) ); ?></strong>
				</div>
				<div class="rs-kpi">
					<span class="rs-kpi-label"><?php esc_html_e( 'Καθαρό (πριν καταμερισμό)', 'revenue-splitter' ); ?></span>
					<strong><?php echo esc_html( self::money( $filtered_report['totals']['net'] ) ); ?></strong>
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

			<?php $ben_rows = $filtered_report['beneficiaries']; ?>

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
	 * Dashboard widget — «Γρήγορη ματιά»
	 * ------------------------------------------------------------------- */

	public static function register_widget(): void {

		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'rs_quick_glance',
			__( 'Revenue Splitter — Γρήγορη ματιά', 'revenue-splitter' ),
			array( __CLASS__, 'render_widget' )
		);
	}

	public static function render_widget(): void {

		$now = new DateTimeImmutable( 'now', wp_timezone() );

		$report = RS_Reports::run(
			array(
				'date_start' => $now->format( 'Y-m-01' ),
				'date_end'   => $now->format( 'Y-m-d' ),
			)
		);

		$top_ben = $report['beneficiaries'];
		if ( ! empty( $top_ben ) ) {
			usort(
				$top_ben,
				static function ( $a, $b ) {
					return (float) $b['amount'] <=> (float) $a['amount'];
				}
			);
			$top_ben = array_slice( $top_ben, 0, 3 );
		}

		$dash_url = admin_url( 'admin.php?page=' . self::SLUG_DASH );
		?>
		<div class="rs-widget">
			<?php if ( empty( $report['products'] ) ) : ?>
				<p class="rs-empty"><?php esc_html_e( 'Καμία πώληση τον τρέχοντα μήνα.', 'revenue-splitter' ); ?></p>
			<?php else : ?>
				<ul class="rs-widget-kpis">
					<li>
						<span><?php esc_html_e( 'Παραγγελίες', 'revenue-splitter' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( $report['order_count'] ) ); ?></strong>
					</li>
					<li>
						<span><?php esc_html_e( 'Μικτό', 'revenue-splitter' ); ?></span>
						<strong><?php echo esc_html( self::money( $report['totals']['gross'] ) ); ?></strong>
					</li>
					<li>
						<span><?php esc_html_e( 'Καθαρό', 'revenue-splitter' ); ?></span>
						<strong><?php echo esc_html( self::money( $report['totals']['net'] ) ); ?></strong>
					</li>
				</ul>

				<?php if ( ! empty( $top_ben ) ) : ?>
					<div class="rs-widget-section"><?php esc_html_e( 'Top δικαιούχοι', 'revenue-splitter' ); ?></div>
					<ul class="rs-widget-ben">
						<?php foreach ( $top_ben as $b ) : ?>
							<li>
								<span><?php echo esc_html( $b['name'] ); ?></span>
								<strong><?php echo esc_html( self::money( $b['amount'] ) ); ?></strong>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>

			<p class="rs-widget-section">
				<a href="<?php echo esc_url( $dash_url ); ?>"><?php esc_html_e( 'Άνοιγμα Dashboard', 'revenue-splitter' ); ?></a>
			</p>
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

		$notices = array();

		// ---------- POST ----------
		if ( isset( $_POST['rs_settings_nonce'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			&& wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rs_settings_nonce'] ) ), 'rs_settings' ) ) {

			// ---- Default ΦΠΑ ----
			if ( isset( $_POST['rs_default_vat_rate'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$vat_raw = trim( (string) wp_unslash( $_POST['rs_default_vat_rate'] ) );
				$vat_val = wc_format_decimal( $vat_raw );

				if ( '' !== $vat_val && is_numeric( $vat_val ) && (float) $vat_val >= 0 && (float) $vat_val <= 100 ) {
					update_option( RS_VAT::OPTION_KEY, (string) $vat_val );
					do_action( 'rs_invalidate_cache' );
				} elseif ( '' !== $vat_raw ) {
					$notices[] = array(
						'type' => 'error',
						'text' => __( 'Μη έγκυρο global default ΦΠΑ (0–100).', 'revenue-splitter' ),
					);
				}
			}

			// ---- Γλώσσα (ανά χρήστη) ----
			if ( isset( $_POST['rs_lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$lang = sanitize_key( (string) wp_unslash( $_POST['rs_lang'] ) );
				RS_Lang::set_lang( get_current_user_id(), $lang );
			}

			// ---- Global δικαιούχοι ----
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- πολυδιάστατο input.
			$rows = array();
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( isset( $_POST['rs_ben_name'], $_POST['rs_ben_pct'] )
				&& is_array( $_POST['rs_ben_name'] ) && is_array( $_POST['rs_ben_pct'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$names = array_values( (array) wp_unslash( $_POST['rs_ben_name'] ) );
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$percs = array_values( (array) wp_unslash( $_POST['rs_ben_pct'] ) );

				foreach ( $names as $i => $n ) {
					$rows[] = array(
						'name'    => $n,
						'percent' => $percs[ $i ] ?? '',
					);
				}
			}

			$clean = RS_Beneficiaries::sanitize_list( $rows );

			if ( null === $clean ) {
				$notices[] = array(
					'type' => 'error',
					'text' => RS_Beneficiaries::error_message_for( $rows ),
				);
			} else {
				RS_Beneficiaries::set_defaults( $clean );
				do_action( 'rs_invalidate_cache' );
			}

			if ( empty( $notices ) ) {
				$notices[] = array(
					'type' => 'success',
					'text' => __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'revenue-splitter' ),
				);
			}
		}

		// ---------- Τρέχουσες τιμές ----------
		$vat_default = RS_VAT::default_rate();
		$defaults    = RS_Beneficiaries::get_defaults();
		$ben_rows    = is_array( $defaults ) && ! empty( $defaults )
			? $defaults
			: array( array( 'name' => '', 'percent' => '' ) );
		$lang        = RS_Lang::get_lang();
		?>
		<div class="wrap rs-wrap">

			<h1><?php esc_html_e( 'Revenue Splitter — Ρυθμίσεις', 'revenue-splitter' ); ?></h1>

			<?php foreach ( $notices as $n ) : ?>
				<div class="notice notice-<?php echo esc_attr( $n['type'] ); ?> is-dismissible inline">
					<p><?php echo esc_html( $n['text'] ); ?></p>
				</div>
			<?php endforeach; ?>

			<form method="post">
				<?php wp_nonce_field( 'rs_settings', 'rs_settings_nonce' ); ?>

				<h2><?php esc_html_e( 'Default ΦΠΑ (%)', 'revenue-splitter' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="rs-default-vat-rate"><?php esc_html_e( 'Default ΦΠΑ (%)', 'revenue-splitter' ); ?></label>
						</th>
						<td>
							<input type="number" step="0.01" min="0" max="100" id="rs-default-vat-rate"
								name="rs_default_vat_rate" value="<?php echo esc_attr( $vat_default ); ?>" class="small-text" />
							<p class="description">
								<?php esc_html_e( 'Ισχύει για προϊόντα χωρίς δικό τους ΦΠΑ στο General tab.', 'revenue-splitter' ); ?>
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
							<?php foreach ( $ben_rows as $row ) : ?>
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

				<h2><?php esc_html_e( 'Γλώσσα οθόνης', 'revenue-splitter' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="rs-lang"><?php esc_html_e( 'Γλώσσα οθόνης', 'revenue-splitter' ); ?></label>
						</th>
						<td>
							<select name="rs_lang" id="rs-lang">
								<option value="el" <?php selected( $lang, 'el' ); ?>>Ελληνικά</option>
								<option value="en" <?php selected( $lang, 'en' ); ?>>English</option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Ισχύει ανά χρήστη (μόνο για εσένα).', 'revenue-splitter' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Αποθήκευση ρυθμίσεων', 'revenue-splitter' ) ); ?>
			</form>

			<?php self::footer(); ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Helpers κοινού χρήστη
	 * ------------------------------------------------------------------- */

	/**
	 * Μορφοποίηση ποσού: αριθμός + σύμβολο νομίσματος του Woo
	 * (plain text, HTML-entity-decoded — ασφαλές για esc_html/esc_attr).
	 */
	public static function money( $amount ): string {

		$amount = (float) $amount;

		$sym = function_exists( 'get_woocommerce_currency_symbol' )
			? get_woocommerce_currency_symbol()
			: '';

		$sym = html_entity_decode( (string) $sym, ENT_QUOTES, 'UTF-8' );

		return number_format_i18n( $amount, 2 ) . ( '' !== $sym ? ' ' . $sym : '' );
	}

	/**
	 * Όλα τα ονόματα δικαιούχων που υπάρχουν στο σύστημα:
	 * global defaults + όλα τα per-product overrides (αποθηκευμένα).
	 */
	private static function collect_beneficiary_names(): array {

		$names = array();

		// 1) Global defaults.
		$defaults = RS_Beneficiaries::get_defaults();
		if ( is_array( $defaults ) ) {
			foreach ( $defaults as $d ) {
				if ( is_array( $d ) && ! empty( $d['name'] ) ) {
					$names[ (string) $d['name'] ] = true;
				}
			}
		}

		// 2) Per-product overrides (raw meta — δεν κάνουμε fallback ώστε
		//    να μη διπλο-μετράμε τα global defaults).
		$product_ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => RS_Beneficiaries::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'none',
				'no_found_rows'  => true,
			)
		);

		foreach ( $product_ids as $pid ) {
			$decoded = json_decode( RS_Beneficiaries::raw( (int) $pid ), true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $row ) {
					if ( is_array( $row ) && ! empty( $row['name'] ) ) {
						$names[ sanitize_text_field( (string) $row['name'] ) ] = true;
					}
				}
			}
		}

		$flat = array_keys( $names );
		sort( $flat );

		return $flat;
	}

	/**
	 * View-level μείωση του report για τα φίλτρα δικαιούχου + αναζήτησης.
	 *
	 * Το φίλτρο προϊόντος γίνεται στην engine (RS_Reports::run) — εδώ
	 * ΦΙΛΤΡΑΡΟΥΜΕ ΕΔΩ:
	 *  - search: προϊόντα με τίτλο που ταιριάζει (case-insensitive, mb-safe),
	 *  - beneficiary: μόνο τα splits του συγκεκριμένου δικαιούχου
	 *    (τα προϊόντα που δεν τον περιλαμβάνουν κρύβονται).
	 *
	 * Ξαναϋπολογίζει totals + beneficiaries ώστε KPIs/πίνακες/exports
	 * να είναι ΣΥΝΕΠΗ με τα ορατά δεδομένα.
	 */
	private static function filter_report_for_display( array $report, string $ben, string $search ): array {

		$ben    = trim( $ben );
		$search = trim( $search );

		if ( '' === $ben && '' === $search ) {
			return $report;
		}

		$haystack = function_exists( 'mb_stripos' ) ? 'mb_stripos' : 'stripos';

		$products   = array();
		$t_gross    = 0.0;
		$t_vat      = 0.0;
		$t_net      = 0.0;
		$ben_amount = array();

		foreach ( $report['products'] as $p ) {

			// ---- Search filter (τίτλος) ----
			if ( '' !== $search ) {
				$hit = call_user_func( $haystack, (string) $p['title'], $search );
				if ( false === $hit ) {
					continue;
				}
			}

			// ---- Beneficiary filter (splits) ----
			$splits = $p['splits'];
			if ( '' !== $ben ) {
				$splits = array_values(
					array_filter(
						$splits,
						static function ( $s ) use ( $ben ) {
							return (string) $s['name'] === $ben;
						}
					)
				);
				if ( empty( $splits ) ) {
					continue; // Το προϊόν δεν αφορά τον φιλτραρισμένο δικαιούχο.
				}
			}

			$p['splits'] = $splits;

			$products[] = $p;

			$t_gross += (float) $p['gross'];
			$t_vat   += (float) $p['vat'];
			$t_net   += (float) $p['net'];

			foreach ( $splits as $s ) {
				$nm = (string) $s['name'];
				if ( ! isset( $ben_amount[ $nm ] ) ) {
					$ben_amount[ $nm ] = 0.0;
				}
				$ben_amount[ $nm ] += (float) $s['amount'];
			}
		}

		$beneficiaries = array();
		foreach ( $ben_amount as $name => $amount ) {
			$beneficiaries[] = array(
				'name'   => $name,
				'amount' => round( $amount, 2 ),
			);
		}

		$report['products'] = $products;
		$report['totals']   = array(
			'gross' => round( $t_gross, 2 ),
			'vat'   => round( $t_vat, 2 ),
			'net'   => round( $t_net, 2 ),
		);

		/*
		 * Με ενεργό φίλτρο δικαιούχου ο πίνακας beneficiaries πρέπει να
		 * περιέχει ακριβώς μία εγγραφή (το dashboard διαβάζει [0]).
		 */
		$report['beneficiaries'] = $beneficiaries;

		return $report;
	}

	/** Footer με credits σε όλες τις σελίδες του plugin. */
	private static function footer(): void {
		?>
		<p class="rs-footer">
			Revenue Splitter v<?php echo esc_html( RS_VERSION ); ?> — Made with ♥ by Christos Koulaxizis
		</p>
		<?php
	}

	/* =====================================================================
	 * EXPORT
	 * ===================================================================== */

	private static function splits_flat( array $splits ): string {
		$parts = array();
		foreach ( $splits as $s ) {
			$parts[] = $s['name'] . ': ' . number_format( $s['amount'], 2, ',', '' ) . ' (' . $s['percent'] . '%)';
		}
		return implode( ' | ', $parts );
	}

	private static function num( float $n ): string {
		return number_format( $n, 2, ',', '' );
	}

	private static function export_headers( string $filename, string $mime ): void {
		nocache_headers();
		// Ένα (1) charset declaration συνολικά — εδώ, όχι σε κάθε caller.
		header( 'Content-Type: ' . $mime . '; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	}

	/*
	 * AUDIT FIX (#7): PHP 8.4+ κάνει deprecated τα implicit defaults του
	 * fputcsv(). Explicit args = ίδια συμπεριφορά, χωρίς warnings σε
	 * νεότερα PHP.
	 */
	private static function put_csv_row( $out, array $fields ): void {
		fputcsv( $out, $fields, ',', '"', '\\' );
	}

	/* ---------- CSV ---------- */

	private static function stream_csv( array $report ): void {

		self::export_headers(
			'revenue-splitter-' . $report['period']['start'] . '_' . $report['period']['end'] . '.csv',
			'text/csv'
		);

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM για Excel + ελληνικά.

		self::put_csv_row( $out, array( __( 'Περίοδος', 'revenue-splitter' ), $report['period']['start'], $report['period']['end'] ) );
		self::put_csv_row( $out, array() );
		self::put_csv_row(
			$out,
			array(
				__( 'ID', 'revenue-splitter' ),
				__( 'Προϊόν', 'revenue-splitter' ),
				__( 'Τεμ.', 'revenue-splitter' ),
				__( 'Μικτό', 'revenue-splitter' ),
				__( 'ΦΠΑ %', 'revenue-splitter' ),
				__( 'ΦΠΑ', 'revenue-splitter' ),
				__( 'Καθαρό', 'revenue-splitter' ),
				__( 'Καταμερισμός', 'revenue-splitter' ),
			)
		);

		foreach ( $report['products'] as $p ) {
			self::put_csv_row(
				$out,
				array(
					$p['product_id'],
					$p['title'],
					$p['qty'],
					self::num( $p['gross'] ),
					self::num( $p['vat_rate'] ),
					self::num( $p['vat'] ),
					self::num( $p['net'] ),
					self::splits_flat( $p['splits'] ),
				)
			);
		}

		self::put_csv_row( $out, array() );
		self::put_csv_row(
			$out,
			array(
				'',
				__( 'ΣΥΝΟΛΑ', 'revenue-splitter' ),
				'',
				self::num( $report['totals']['gross'] ),
				'',
				self::num( $report['totals']['vat'] ),
				self::num( $report['totals']['net'] ),
				'',
			)
		);

		fclose( $out );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Export: XLS (SpreadsheetML 2003 — ανοίγει native σε Excel/LibreOffice,
	 * νούμερα ως αριθμούς, ελληνικά σωστά — zero dependencies)
	 * ------------------------------------------------------------------- */

	private static function stream_xls( array $report ): void {

		$filename = 'revenue-splitter-' . $report['period']['start'] . '_' . $report['period']['end'] . '.xls';

		self::export_headers( $filename, 'application/vnd.ms-excel' );

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Styles>
  <Style ss:ID="hdr">
   <Font ss:Bold="1" ss:Color="#FFFFFF"/>
   <Interior ss:Color="#1D2029" ss:Pattern="Solid"/>
  </Style>
  <Style ss:ID="tot">
   <Font ss:Bold="1"/>
  </Style>
 </Styles>
 <Worksheet ss:Name="Revenue Splitter">
  <Table>
   <Row>
    <Cell><Data ss:Type="String"><?php echo esc_html( __( 'Περίοδος', 'revenue-splitter' ) ); ?></Data></Cell>
    <Cell><Data ss:Type="String"><?php echo esc_html( $report['period']['start'] ); ?> — <?php echo esc_html( $report['period']['end'] ); ?></Data></Cell>
   </Row>
   <Row></Row>
   <Row>
    <Cell ss:StyleID="hdr"><Data ss:Type="String"><?php echo esc_html( __( 'ID', 'revenue-splitter' ) ); ?></Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String"><?php echo esc_html( __( 'Προϊόν', 'revenue-splitter' ) ); ?></Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String"><?php echo esc_html( __( 'Τεμ.', 'revenue-splitter' ) ); ?></Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String"><?php echo esc_html( __( 'Μικτό', 'revenue-splitter' ) ); ?></Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String"><?php echo esc_html( __( 'ΦΠΑ %', 'revenue-splitter' ) ); ?></Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String"><?php echo esc_html( __( 'ΦΠΑ', 'revenue-splitter' ) ); ?></Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String"><?php echo esc_html( __( 'Καθαρό', 'revenue-splitter' ) ); ?></Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String"><?php echo esc_html( __( 'Καταμερισμός', 'revenue-splitter' ) ); ?></Data></Cell>
   </Row>
<?php foreach ( $report['products'] as $p ) : ?>
   <Row>
    <Cell><Data ss:Type="Number"><?php echo (int) $p['product_id']; ?></Data></Cell>
    <Cell><Data ss:Type="String"><?php echo esc_html( $p['title'] ); ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?php echo (int) $p['qty']; ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?php echo esc_html( (string) (float) $p['gross'] ); ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?php echo esc_html( (string) (float) $p['vat_rate'] ); ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?php echo esc_html( (string) (float) $p['vat'] ); ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?php echo esc_html( (string) (float) $p['net'] ); ?></Data></Cell>
    <Cell><Data ss:Type="String"><?php echo esc_html( self::splits_flat( $p['splits'] ) ); ?></Data></Cell>
   </Row>
<?php endforeach; ?>
   <Row></Row>
   <Row>
    <Cell></Cell>
    <Cell ss:StyleID="tot"><Data ss:Type="String"><?php echo esc_html( __( 'ΣΥΝΟΛΑ', 'revenue-splitter' ) ); ?></Data></Cell>
    <Cell></Cell>
    <Cell ss:StyleID="tot"><Data ss:Type="Number"><?php echo esc_html( (string) (float) $report['totals']['gross'] ); ?></Data></Cell>
    <Cell></Cell>
    <Cell ss:StyleID="tot"><Data ss:Type="Number"><?php echo esc_html( (string) (float) $report['totals']['vat'] ); ?></Data></Cell>
    <Cell ss:StyleID="tot"><Data ss:Type="Number"><?php echo esc_html( (string) (float) $report['totals']['net'] ); ?></Data></Cell>
    <Cell></Cell>
   </Row>
  </Table>
 </Worksheet>
</Workbook>
		<?php
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Export: HTML (ανοίγει σε browser → Print → PDF σε 2 κλικ)
	 * ------------------------------------------------------------------- */

	private static function stream_html( array $report ): void {

		$filename = 'revenue-splitter-' . $report['period']['start'] . '_' . $report['period']['end'] . '.html';

		self::export_headers( $filename, 'text/html' );
		?>
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="utf-8" />
<title>Revenue Splitter — <?php echo esc_html( $report['period']['start'] ); ?> → <?php echo esc_html( $report['period']['end'] ); ?></title>
<style>
	body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color: #1a1c22; margin: 40px; }
	h1 { font-size: 20px; margin-bottom: 4px; }
	.period { color: #666; margin-bottom: 24px; }
	table { border-collapse: collapse; width: 100%; margin-bottom: 28px; }
	th, td { border: 1px solid #ccc; padding: 8px 12px; text-align: left; }
	th { background: #f0eefc; }
	.num { text-align: right; font-variant-numeric: tabular-nums; }
	.footer { color: #888; font-size: 12px; margin-top: 32px; border-top: 1px solid #ddd; padding-top: 10px; }
	@media print { .footer a { color: #1a1c22; text-decoration: none; } }
</style>
</head>
<body>
<h1>Revenue Splitter</h1>
<p class="period"><?php esc_html_e( 'Περίοδος', 'revenue-splitter' ); ?>: <?php echo esc_html( $report['period']['start'] ); ?> → <?php echo esc_html( $report['period']['end'] ); ?></p>

<h2><?php esc_html_e( 'Ανά προϊόν', 'revenue-splitter' ); ?></h2>
<table>
	<thead>
		<tr>
			<th><?php esc_html_e( 'Προϊόν', 'revenue-splitter' ); ?></th><th><?php esc_html_e( 'Τεμ.', 'revenue-splitter' ); ?></th><th><?php esc_html_e( 'Μικτό', 'revenue-splitter' ); ?></th><th><?php esc_html_e( 'ΦΠΑ', 'revenue-splitter' ); ?></th><th><?php esc_html_e( 'Καθαρό', 'revenue-splitter' ); ?></th><th><?php esc_html_e( 'Καταμερισμός', 'revenue-splitter' ); ?></th>
		</tr>
	</thead>
	<tbody>
<?php foreach ( $report['products'] as $p ) : ?>
		<tr>
			<td><?php echo esc_html( $p['title'] ); ?></td>
			<td class="num"><?php echo (int) $p['qty']; ?></td>
			<td class="num"><?php echo esc_html( self::num( $p['gross'] ) ); ?></td>
			<td class="num"><?php echo esc_html( self::num( $p['vat'] ) ); ?></td>
			<td class="num"><strong><?php echo esc_html( self::num( $p['net'] ) ); ?></strong></td>
			<td><?php echo esc_html( self::splits_flat( $p['splits'] ) ); ?></td>
		</tr>
<?php endforeach; ?>
	</tbody>
</table>

<h2><?php esc_html_e( 'Σύνολα ανά δικαιούχο', 'revenue-splitter' ); ?></h2>
<table>
	<thead><tr><th><?php esc_html_e( 'Δικαιούχος', 'revenue-splitter' ); ?></th><th><?php esc_html_e( 'Ποσό', 'revenue-splitter' ); ?></th></tr></thead>
	<tbody>
<?php foreach ( $report['beneficiaries'] as $b ) : ?>
		<tr>
			<td><?php echo esc_html( $b['name'] ); ?></td>
			<td class="num"><strong><?php echo esc_html( self::num( $b['amount'] ) ); ?></strong></td>
		</tr>
<?php endforeach; ?>
	</tbody>
</table>

<p class="footer">Revenue Splitter v<?php echo esc_html( RS_VERSION ); ?> — Made with ♥ by Christos Koulaxizis</p>
</body>
</html>
		<?php
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Export: JSON — δομημένο, για pipelines (π.χ. Syncthing / orOS flows)
	 * ------------------------------------------------------------------- */

	private static function stream_json( array $report ): void {

		$filename = 'revenue-splitter-' . $report['period']['start'] . '_' . $report['period']['end'] . '.json';

		self::export_headers( $filename, 'application/json' );

		echo wp_json_encode(
			$report,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		exit;
	}
}
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

		// File-based cache busting: κάθε αλλαγή αρχείου → νέο ?ver=.
		$css_ver = (string) filemtime( RS_PATH . 'assets/admin.css' );
		$js_ver  = (string) filemtime( RS_PATH . 'assets/admin.js' );

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
		$filters      = get_user_meta( get_current_user_id(), 'rs_last_filters', true );
		$filter_prod  = is_array( $filters ) ? max( 0, (int) ( $filters['product'] ?? 0 ) ) : 0;
		$filter_ben   = is_array( $filters ) ? (string) ( $filters['beneficiary'] ?? '' ) : '';
		$filter_search = is_array( $filters ) ? trim( (string) ( $filters['search'] ?? '' ) ) : '';

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
					<span class="rs-kpi-label"><?php esc_html_e( 'Παραγγελίες', 'revenue-splitter' ); ?></span>
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

		$now   = new DateTimeImmutable( 'now', wp_timezone() );
		$month = array(
			'date_start' => $now->format( 'Y-m-01' ),
			'date_end'   => $now->format( 'Y-m-d' ),
		);

		$report = RS_Reports::run( $month );

		$top_ben = $report['beneficiaries'];
		if ( ! empty( $top_ben ) ) {
			usort(
				$top_ben,
				static function ( $a, $b ) {
					return (float) $b['amount'] <=> (float) $a['amount'];
				}
			);
			
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
		header( 'Content-Type: ' . $mime . '; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
	}

	/* ---------- CSV ---------- */

	private static function stream_csv( array $report ): void {

		self::export_headers(
			'revenue-splitter-' . $report['period']['start'] . '_' . $report['period']['end'] . '.csv',
			'text/csv; charset=utf-8'
		);

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM για Excel + ελληνικά.

		fputcsv( $out, array( __( 'Περίοδος', 'revenue-splitter' ), $report['period']['start'], $report['period']['end'] ) );
		fputcsv( $out, array() );
		fputcsv( $out, array( 'ID', 'Προϊόν', 'Τεμ.', 'Μικτό', 'ΦΠΑ %', 'ΦΠΑ', 'Καθαρό', 'Καταμερισμός' ) );

		foreach ( $report['products'] as $p ) {
			fputcsv( $out, array(
				$p['product_id'],
				$p['title'],
				$p['qty'],
				self::num( $p['gross'] ),
				self::num( $p['vat_rate'] ),
				self::num( $p['vat'] ),
				self::num( $p['net'] ),
				self::splits_flat( $p['splits'] ),
			) );
		}

		fputcsv( $out, array() );
		fputcsv( $out, array(
			'',
			'ΣΥΝΟΛΑ',
			'',
			self::num( $report['totals']['gross'] ),
			'',
			self::num( $report['totals']['vat'] ),
			self::num( $report['totals']['net'] ),
			'',
		) );

		fclose( $out );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Export: XLS (SpreadsheetML 2003 — ανοίγει native σε Excel/LibreOffice,
	 * νούμερα ως αριθμούς, ελληνικά σωστά — zero dependencies)
	 * ------------------------------------------------------------------- */

	private static function stream_xls( array $report ): void {

		$filename = 'revenue-splitter-' . $report['period']['start'] . '_' . $report['period']['end'] . '.xls';

		self::export_headers( $filename, 'application/vnd.ms-excel; charset=UTF-8' );

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
    <Cell><Data ss:Type="String">Περίοδος</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo esc_html( $report['period']['start'] ); ?> — <?php echo esc_html( $report['period']['end'] ); ?></Data></Cell>
   </Row>
   <Row></Row>
   <Row>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">ID</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">Προϊόν</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">Τεμ.</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">Μικτό</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">ΦΠΑ %</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">ΦΠΑ</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">Καθαρό</Data></Cell>
    <Cell ss:StyleID="hdr"><Data ss:Type="String">Καταμερισμός</Data></Cell>
   </Row>
<?php foreach ( $report['products'] as $p ) : ?>
   <Row>
    <Cell><Data ss:Type="Number"><?php echo (int) $p['product_id']; ?></Data></Cell>
    <Cell><Data ss:Type="String"><?php echo esc_html( $p['title'] ); ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?php echo (int) $p['qty']; ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?php echo esc_html( $p['gross'] ); ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?php echo esc_html( $p['vat_rate'] ); ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?php echo esc_html( $p['vat'] ); ?></Data></Cell>
    <Cell><Data ss:Type="Number"><?php echo esc_html( $p['net'] ); ?></Data></Cell>
    <Cell><Data ss:Type="String"><?php echo esc_html( self::splits_flat( $p['splits'] ) ); ?></Data></Cell>
   </Row>
<?php endforeach; ?>
   <Row></Row>
   <Row>
    <Cell></Cell>
    <Cell ss:StyleID="tot"><Data ss:Type="String">ΣΥΝΟΛΑ</Data></Cell>
    <Cell></Cell>
    <Cell ss:StyleID="tot"><Data ss:Type="Number"><?php echo esc_html( $report['totals']['gross'] ); ?></Data></Cell>
    <Cell></Cell>
    <Cell ss:StyleID="tot"><Data ss:Type="Number"><?php echo esc_html( $report['totals']['vat'] ); ?></Data></Cell>
    <Cell ss:StyleID="tot"><Data ss:Type="Number"><?php echo esc_html( $report['totals']['net'] ); ?></Data></Cell>
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

		self::export_headers( $filename, 'text/html; charset=utf-8' );
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
<p class="period">Περίοδος: <?php echo esc_html( $report['period']['start'] ); ?> → <?php echo esc_html( $report['period']['end'] ); ?></p>

<h2>Ανά προϊόν</h2>
<table>
	<thead>
		<tr>
			<th>Προϊόν</th><th>Τεμ.</th><th>Μικτό</th><th>ΦΠΑ</th><th>Καθαρό</th><th>Καταμερισμός</th>
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

<h2>Σύνολα ανά δικαιούχο</h2>
<table>
	<thead><tr><th>Δικαιούχος</th><th>Ποσό</th></tr></thead>
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

		self::export_headers( $filename, 'application/json; charset=utf-8' );

		echo wp_json_encode(
			$report,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		exit;
	}
}
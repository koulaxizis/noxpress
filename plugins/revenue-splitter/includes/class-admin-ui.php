<?php
/**
 * RS_Admin_UI — Admin pages: Dashboard, Ρυθμίσεις, widget, exports,
 * backup/import.
 *
 * Security pattern:
 *  - Όλα τα privileged GET (exports, state export) = admin_init + TRIPLE
 *    gating: page slug → nonce → capability.
 *  - Όλα τα POST που αλλάζουν state (settings, backup/import, ledger)
 *    με nonce + capability + PRG (transient notices) — το refresh/back
 *    του browser δεν επαναλαμβάνει κανένα POST.
 *
 * v1.3.0 (#1): Στήλη «Συνολικό υπόλοιπο» ανά δικαιούχο στο dashboard.
 * v1.3.0 (#8): Πίνακας ΦΠΑ ανά συντελεστή (περιόδου) στο dashboard.
 * v1.3.0 (#4): Export/Import πλήρους plugin state σε JSON.
 *
 * v1.3.1 FIX (#5): KPI labels → class rs-kpi-label.
 * v1.3.1 FIX (#6): STRICT per-value validation στα portal keys (import).
 * v1.3.1 FIX (#9): CSV formula injection protection (csv_cell).
 * v1.3.1 FIX (#17): Settings save με PRG (admin_init + transient).
 * v1.3.1 (#16): product_stock() public — μοιράζεται με το Portal.
 * v1.3.1 (re-audit): csv_cell() public — καλείται από RS_Portal.
 *
 * v1.3.2 FIX (#1): admin.css φορτώνεται και στο wp-admin/index.php (widget).
 * v1.3.2 FIX (#3): import_state(): τελικό success ΜΟΝΟ χωρίς errors.
 * v1.3.2 FIX (#4): αποτυχημένο nonce στο route_settings() → wp_die().
 *
 * v1.3.6 (#8 admin): Στήλες «Μέση έκπτωση (%)» + «Κουπόνια» στον πίνακα
 * «Ανά προϊόν» (και στα exports CSV/XLS/HTML — συνέπεια με portal).
 * v1.3.6 (#6): Η στήλη «Καταμερισμός» τυπώνει CHIPS (.rs-chip) —
 * εμφανές ποσοστό/ποσό, όχι γκρι muted κείμενο (CSS: admin.css).
 * v1.3.6 (#9): Νέο option rs_sales_since («Έναρξη καταγραφής
 * πωλήσεων») στις Ρυθμίσεις — strict validation, rs_invalidate_cache
 * στο save, συμμετοχή στο STATE_OPTS/backup/import. Το clamping των
 * reports γίνεται στο RS_Reports::run().
 *
 * ΣΗΜΑΝΤΙΚΟ (dispatch map — ποιος κάνει τι, για αποφυγή διπλοεγγραφών):
 *  - Metabox προϊόντος + save δικαιούχων  → RS_Beneficiaries
 *  - ΦΠΑ πεδίο + save                    → RS_VAT
 *  - Checkout field                       → RS_Checkout
 *  - Αυτό το class: ΜΟΝΟ admin pages/dashboard/widget/exports/backup.
 */

defined( 'ABSPATH' ) || exit;

final class RS_Admin_UI {

	const CAP       = 'manage_woocommerce';
	const SLUG_DASH = 'revenue-splitter-dashboard';
	const SLUG_SET  = 'revenue-splitter-settings';

	/** Mirror του option του RS_Checkout (free-copy reason coupons). */
	const OPT_COUPONS = 'rs_reason_coupons';

	/** v1.3.6 (#9): mirror του option «έναρξη καταγραφής πωλήσεων». */
	const OPT_SALES_SINCE = 'rs_sales_since';

	/** Whitelist options για backup/import (#4) — v1.3.6 (#9): + rs_sales_since. */
	const STATE_OPTS = array(
		'rs_default_vat_rate',
		'rs_beneficiaries',
		'rs_portal_keys',
		'rs_ledger',
		'rs_reason_coupons',
		'rs_sales_since',
	);

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_exports' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_backup' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'widget' ) );
		// Το metabox προϊόντος το handle-άρουν τα RS_Beneficiaries
		// (metabox + save δικαιούχων) και RS_VAT (αποθήκευση ΦΠΑ).
	}

	/* =====================================================================
	 * Menus / assets / widget
	 * =================================================================== */

	public static function admin_menu(): void {

		add_menu_page(
			__( 'Revenue Splitter — Dashboard', 'revenue-splitter' ),
			__( 'Revenue Splitter', 'revenue-splitter' ),
			self::CAP,
			self::SLUG_DASH,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-chart-pie'
		);

		add_submenu_page(
			self::SLUG_DASH,
			__( 'Revenue Splitter — Γρήγορη ματιά', 'revenue-splitter' ),
			__( 'Dashboard', 'revenue-splitter' ),
			self::CAP,
			self::SLUG_DASH,
			array( __CLASS__, 'render_dashboard' )
		);

		add_submenu_page(
			self::SLUG_DASH,
			__( 'Revenue Splitter — Ρυθμίσεις', 'revenue-splitter' ),
			__( 'Ρυθμίσεις', 'revenue-splitter' ),
			self::CAP,
			self::SLUG_SET,
			array( __CLASS__, 'render_settings' )
		);
	}

	/**
	 * v1.3.2 FIX (#1): το CSS φορτώνεται ΚΑΙ στο dashboard (wp-admin/
	 * index.php) για το widget. Εκεί enqueue-άρει ΜΟΝΟ το stylesheet
	 * (κανένα JS — το admin.js αφορά μόνο το metabox beneficiary editor).
	 */
	public static function assets( string $hook ): void {

		$is_ours = ( false !== strpos( $hook, 'revenue-splitter' ) );
		$product = ( 'post.php' === $hook || 'post-new.php' === $hook );
		$is_dash = ( 'index.php' === $hook ); // v1.3.2 (#1): dashboard widget.

		if ( ! $is_ours && ! $product && ! $is_dash ) {
			return;
		}

		$base = plugin_dir_url( RS_FILE );

		wp_enqueue_style( 'rs-admin', $base . 'assets/admin.css', array(), (string) filemtime( RS_PATH . 'assets/admin.css' ) );

		// v1.3.3 FIX (#5): το JS δένεται ΜΟΝΟ σε .rs-split-table rows
		// (product metabox).
		if ( $product ) {
			wp_enqueue_script( 'rs-admin', $base . 'assets/admin.js', array(), (string) filemtime( RS_PATH . 'assets/admin.js' ), true );
		}
	}

	public static function widget(): void {

		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'rs_widget',
			__( 'Revenue Splitter', 'revenue-splitter' ),
			array( __CLASS__, 'render_widget' )
		);
	}

	/* =====================================================================
	 * Περίοδος (κοινός parser για dashboard + exports)
	 * =================================================================== */

	/** Presets + custom range από GET. Επιστρέφει [start, end, preset, label]. */
	private static function current_period(): array {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only period filter.
		$preset = isset( $_GET['rs_period'] ) ? sanitize_key( wp_unslash( $_GET['rs_period'] ) ) : 'month';

		$now = new DateTimeImmutable( 'now', wp_timezone() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$start = isset( $_GET['rs_start'] ) ? sanitize_text_field( wp_unslash( $_GET['rs_start'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$end   = isset( $_GET['rs_end'] ) ? sanitize_text_field( wp_unslash( $_GET['rs_end'] ) ) : '';

		$valid = static function ( string $d ): bool {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
				return false;
			}
			return checkdate( (int) substr( $d, 5, 2 ), (int) substr( $d, 8, 2 ), (int) substr( $d, 0, 4 ) );
		};

		switch ( $preset ) {
			case '7d':
				$label = __( 'Τελευταίες 7 ημέρες', 'revenue-splitter' );
				$s     = $now->modify( '-6 days' )->format( 'Y-m-d' );
				$e     = $now->format( 'Y-m-d' );
				break;
			case '30d':
				$label = __( 'Τελευταίες 30 ημέρες', 'revenue-splitter' );
				$s     = $now->modify( '-29 days' )->format( 'Y-m-d' );
				$e     = $now->format( 'Y-m-d' );
				break;
			case 'prev_month':
				$label = __( 'Προηγούμενος μήνας', 'revenue-splitter' );
				$m     = $now->modify( 'first day of previous month' );
				$s     = $m->format( 'Y-m-01' );
				$e     = $m->format( 'Y-m-t' );
				break;
			case 'year':
				$label = __( 'Τρέχον έτος', 'revenue-splitter' );
				$s     = $now->format( 'Y-01-01' );
				$e     = $now->format( 'Y-m-d' );
				break;
			case 'prev_year':
				$label = __( 'Προηγούμενο έτος', 'revenue-splitter' );
				$y     = (int) $now->format( 'Y' ) - 1;
				$s     = $y . '-01-01';
				$e     = $y . '-12-31';
				break;
			case 'custom':
				$label = __( 'Προσαρμοσμένο', 'revenue-splitter' );
				if ( $valid( $start ) && $valid( $end ) && $start <= $end ) {
					$s = $start;
					$e = $end;
				} else {
					$s = $now->format( 'Y-m-01' );
					$e = $now->format( 'Y-m-d' );
				}
				break;
			case 'month':
			default:
				$preset = 'month';
				$label  = __( 'Τρέχων μήνας', 'revenue-splitter' );
				$s      = $now->format( 'Y-m-01' );
				$e      = $now->format( 'Y-m-d' );
				break;
		}

		return array(
			'start'  => $s,
			'end'    => $e,
			'preset' => $preset,
			'label'  => $label,
		);
	}

	/* =====================================================================
	 * Dashboard
	 * =================================================================== */

	public static function render_dashboard(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}

		$per = self::current_period();

		// v1.3.4-a: φίλτρο ανά προϊόν (read-only GET). ΜΙΑ μεταβλητή παντού.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$pid = isset( $_GET['rs_product'] ) ? absint( $_GET['rs_product'] ) : 0;

		// Πλήρες report περιόδου — τροφοδοτεί το dropdown (πάντα).
		$report_all = RS_Reports::run(
			array(
				'date_start' => $per['start'],
				'date_end'   => $per['end'],
			)
		);

		if ( $pid > 0 ) {
			$report = RS_Reports::run(
				array(
					'date_start'  => $per['start'],
					'date_end'    => $per['end'],
					'product_ids' => array( $pid ),
				)
			);
		} else {
			$report = $report_all;
		}

		// ---------- v1.3.0 (#8): ΦΠΑ ανά συντελεστή ----------
		$by_rate = array();
		foreach ( $report['products'] as $p ) {
			$rk = (string) $p['vat_rate'];
			if ( ! isset( $by_rate[ $rk ] ) ) {
				$by_rate[ $rk ] = array( 'qty' => 0, 'gross' => 0.0, 'vat' => 0.0, 'net' => 0.0 );
			}
			$by_rate[ $rk ]['qty']   += (int) $p['qty'];
			$by_rate[ $rk ]['gross'] += (float) $p['gross'];
			$by_rate[ $rk ]['vat']   += (float) $p['vat'];
			$by_rate[ $rk ]['net']   += (float) $p['net'];
		}
		ksort( $by_rate );

		// ---------- v1.3.0 (#1): λογιστική δικαιούχων + lifetime ----------
		$lifetime = RS_Reports::lifetime_beneficiaries();

		$today = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );

		$people = array();
		foreach ( RS_Beneficiaries::collect_names() as $name ) {
			$people[ $name ] = true;
		}
		foreach ( $report['beneficiaries'] as $b ) {
			$people[ $b['name'] ] = true;
		}

		$accounts = array();
		foreach ( array_keys( $people ) as $name ) {
			$sales = 0.0;
			foreach ( $report['beneficiaries'] as $b ) {
				if ( $b['name'] === $name ) {
					$sales = (float) $b['amount'];
					break;
				}
			}
			$inc_p = RS_Ledger::sum( $name, $per['start'], $per['end'], 'income' );
			$pay_p = RS_Ledger::sum( $name, $per['start'], $per['end'], 'payment' );
			$inc_l = RS_Ledger::sum( $name, '2000-01-01', $today, 'income' );
			$pay_l = RS_Ledger::sum( $name, '2000-01-01', $today, 'payment' );
			$life  = ( $lifetime[ $name ] ?? 0.0 );

			$accounts[] = array(
				'name'   => $name,
				'sales'  => $sales,
				'inc'    => $inc_p,
				'pay'    => $pay_p,
				'remain' => round( $sales + $inc_p - $pay_p, 2 ),
				'life'   => round( $life + $inc_l - $pay_l, 2 ),
			);
		}
		usort(
			$accounts,
			static function ( $a, $b ) {
				return $b['remain'] <=> $a['remain'];
			}
		);

		$cur = self::currency_fmt();
		?>
		<div class="wrap rs-wrap">

			<h1><?php esc_html_e( 'Revenue Splitter — Dashboard', 'revenue-splitter' ); ?></h1>

			<form method="get" class="rs-period-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG_DASH ); ?>" />

				<select name="rs_product">
					<option value="0"<?php selected( $pid, 0 ); ?>><?php esc_html_e( 'Όλα τα προϊόντα', 'revenue-splitter' ); ?></option>
					<?php foreach ( $report_all['products'] as $dp ) : ?>
						<option value="<?php echo esc_attr( (string) $dp['product_id'] ); ?>"<?php selected( $pid, (int) $dp['product_id'] ); ?>>
							<?php echo esc_html( $dp['title'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<select name="rs_period">
					<option value="7d"<?php selected( $per['preset'], '7d' ); ?>><?php esc_html_e( 'Τελευταίες 7 ημέρες', 'revenue-splitter' ); ?></option>
					<option value="30d"<?php selected( $per['preset'], '30d' ); ?>><?php esc_html_e( 'Τελευταίες 30 ημέρες', 'revenue-splitter' ); ?></option>
					<option value="month"<?php selected( $per['preset'], 'month' ); ?>><?php esc_html_e( 'Τρέχων μήνας', 'revenue-splitter' ); ?></option>
					<option value="prev_month"<?php selected( $per['preset'], 'prev_month' ); ?>><?php esc_html_e( 'Προηγούμενος μήνας', 'revenue-splitter' ); ?></option>
					<option value="year"<?php selected( $per['preset'], 'year' ); ?>><?php esc_html_e( 'Τρέχον έτος', 'revenue-splitter' ); ?></option>
					<option value="prev_year"<?php selected( $per['preset'], 'prev_year' ); ?>><?php esc_html_e( 'Προηγούμενος έτος', 'revenue-splitter' ); ?></option>
					<option value="custom"<?php selected( $per['preset'], 'custom' ); ?>><?php esc_html_e( 'Προσαρμοσμένο', 'revenue-splitter' ); ?></option>
				</select>

				<input type="date" name="rs_start" value="<?php echo esc_attr( $per['start'] ); ?>" />
				<input type="date" name="rs_end" value="<?php echo esc_attr( $per['end'] ); ?>" />

				<button type="submit" class="button"><?php esc_html_e( 'Εφαρμογή', 'revenue-splitter' ); ?></button>

				<?php foreach ( array( 'csv', 'xls', 'html', 'json' ) as $fmt ) : ?>
					<a class="button" href="<?php echo esc_url( self::export_url( $fmt, $per, $pid ) ); ?>">
						<?php esc_html_e( 'Εξαγωγή', 'revenue-splitter' ); ?> <?php echo esc_html( strtoupper( $fmt ) ); ?>
					</a>
				<?php endforeach; ?>
			</form>

			<h2 class="rs-h2"><?php echo esc_html( $per['label'] ); ?> — <?php echo esc_html( $per['start'] ); ?> → <?php echo esc_html( $per['end'] ); ?></h2>

			<div class="rs-kpis">
				<div class="rs-kpi"><span class="rs-kpi-label"><?php esc_html_e( 'Παραγγελίες (περιόδου)', 'revenue-splitter' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $report['order_count'] ) ); ?></strong></div>
				<div class="rs-kpi"><span class="rs-kpi-label"><?php esc_html_e( 'Μικτό (με ΦΠΑ)', 'revenue-splitter' ); ?></span><strong><?php echo esc_html( $cur( $report['totals']['gross'] ) ); ?></strong></div>
				<div class="rs-kpi"><span class="rs-kpi-label"><?php esc_html_e( 'ΦΠΑ', 'revenue-splitter' ); ?></span><strong class="rs-neg">−<?php echo esc_html( $cur( $report['totals']['vat'] ) ); ?></strong></div>
				<div class="rs-kpi"><span class="rs-kpi-label"><?php esc_html_e( 'Καθαρό (πριν καταμερισμό)', 'revenue-splitter' ); ?></span><strong><?php echo esc_html( $cur( $report['totals']['net'] ) ); ?></strong></div>
			</div>

			<?php if ( ! empty( $by_rate ) ) : ?>
				<h2 class="rs-h2"><?php esc_html_e( 'ΦΠΑ ανά συντελεστή', 'revenue-splitter' ); ?></h2>
				<table class="widefat striped rs-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Συντελεστής', 'revenue-splitter' ); ?></th>
							<th class="num"><?php esc_html_e( 'Τεμ.', 'revenue-splitter' ); ?></th>
							<th class="num"><?php esc_html_e( 'Μικτό', 'revenue-splitter' ); ?></th>
							<th class="num"><?php esc_html_e( 'ΦΠΑ', 'revenue-splitter' ); ?></th>
							<th class="num"><?php esc_html_e( 'Καθαρό', 'revenue-splitter' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $by_rate as $rate => $v ) : ?>
						<tr>
							<td><strong><?php echo esc_html( number_format_i18n( (float) $rate, 2 ) ); ?>%</strong></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $v['qty'] ) ); ?></td>
							<td class="num"><?php echo esc_html( $cur( $v['gross'] ) ); ?></td>
							<td class="num"><?php echo esc_html( $cur( $v['vat'] ) ); ?></td>
							<td class="num"><?php echo esc_html( $cur( $v['net'] ) ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2 class="rs-h2"><?php esc_html_e( 'Ανά προϊόν', 'revenue-splitter' ); ?></h2>
			<table class="widefat striped rs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Προϊόν', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Τεμ.', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Πλήρης', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Έκπτωση', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Μέση έκπτωση (%)', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Κουπόνια', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Δωρεάν', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Μικτό', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'ΦΠΑ', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Καθαρό', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Στοκ', 'revenue-splitter' ); ?></th>
						<th><?php esc_html_e( 'Καταμερισμός', 'revenue-splitter' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $report['products'] ) ) : ?>
					<tr><td colspan="12" class="rs-empty"><?php esc_html_e( 'Καμία πωλημένη γραμμή στην περίοδο.', 'revenue-splitter' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $report['products'] as $p ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $p['title'] ); ?></strong>
							<?php if ( $p['ben_default'] ) : ?><small> · <?php esc_html_e( 'global defaults', 'revenue-splitter' ); ?></small><?php endif; ?>
						</td>
						<td class="num"><?php echo esc_html( number_format_i18n( $p['qty'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $p['qty_full'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $p['qty_disc'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $p['disc_pct'], 1 ) ); ?>%</td>
						<td class="num">
							<?php if ( ! empty( $p['coupons'] ) ) : ?>
								<?php echo esc_html( implode( ', ', $p['coupons'] ) ); ?>
							<?php else : ?>
								<span class="rs-muted">—</span>
							<?php endif; ?>
						</td>
						<td class="num"><?php echo esc_html( number_format_i18n( $p['qty_free'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $cur( $p['gross'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $cur( $p['vat'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $cur( $p['net'] ) ); ?></td>
						<td class="num"><?php echo esc_html( self::product_stock( (int) $p['product_id'] ) ); ?></td>
						<td>
							<?php
							// v1.3.6 (#6): chips — φανερός καταμερισμός με μια ματιά.
							$chips = array();
							foreach ( $p['splits'] as $s ) {
								$chips[] = '<span class="rs-chip"><span class="rs-chip-name">'
									. esc_html( $s['name'] )
									. '</span><span class="rs-chip-data">'
									. esc_html( number_format_i18n( (float) $s['percent'], 1 ) ) . '% · '
									. esc_html( number_format_i18n( (float) $s['amount'], 2 ) )
									. '</span></span>';
							}
							echo implode( '<br />', $chips ); // phpcs:ignore WordPress.Security.EscapeOutput -- esc_html εντός του loop.
							?>
						</td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
				<tfoot>
					<tr>
						<td><?php esc_html_e( 'ΣΥΝΟΛΑ', 'revenue-splitter' ); ?></td>
						<td colspan="6"></td>
						<td class="num"><?php echo esc_html( $cur( $report['totals']['gross'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $cur( $report['totals']['vat'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $cur( $report['totals']['net'] ) ); ?></td>
						<td colspan="2"></td>
					</tr>
				</tfoot>
			</table>

			<h2 class="rs-h2"><?php esc_html_e( 'Λογιστική δικαιούχων', 'revenue-splitter' ); ?></h2>
			<table class="widefat striped rs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Δικαιούχος', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Πωλήσεις', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Έσοδα εκτός πωλήσεων', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Πληρωμές', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Υπόλοιπο (περιόδου)', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Συνολικό υπόλοιπο', 'revenue-splitter' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $accounts ) ) : ?>
					<tr><td colspan="6" class="rs-empty"><?php esc_html_e( 'Δεν υπάρχουν δεδομένα δικαιούχων στην περίοδο.', 'revenue-splitter' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $accounts as $a ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $a['name'] ); ?></strong></td>
						<td class="num"><?php echo esc_html( $cur( $a['sales'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $cur( $a['inc'] ) ); ?></td>
						<td class="num">−<?php echo esc_html( $cur( $a['pay'] ) ); ?></td>
						<td class="num"><strong class="<?php echo esc_attr( $a['remain'] < 0 ? 'rs-neg' : '' ); ?>"><?php echo esc_html( $cur( $a['remain'] ) ); ?></strong></td>
						<td class="num"><strong class="<?php echo esc_attr( $a['life'] < 0 ? 'rs-neg' : '' ); ?>"><?php echo esc_html( $cur( $a['life'] ) ); ?></strong></td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php self::footer(); ?>
		</div>
		<?php
	}

	/* =====================================================================
	 * Ρυθμίσεις
	 * =================================================================== */

	public static function render_settings(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}

		// v1.3.1 FIX (#17): κανένα POST handling εδώ — το save γίνεται στο
		// route_settings() (admin_init + PRG). Εδώ διαβάζουμε μόνο τα
		// transient notices από redirects (settings / ledger / backup).
		$notices = array_merge( RS_Ledger::handle_admin_post(), self::take_backup_msgs() );

		$default_vat = get_option( 'rs_default_vat_rate', '24' );
		$coupons     = (string) get_option( self::OPT_COUPONS, '' );
		$lang        = RS_Lang::get_choice();

		// v1.3.6 (#9): ημερομηνία έναρξης καταγραφής πωλήσεων ('' = χωρίς όριο).
		$sales_since = RS_Reports::sales_since();

		// Global defaults σε μορφή «Όνομα|Ποσοστό» για το textarea
		// (το option ΜΕΝΕΙ JSON — το textarea είναι μόνο UI notation).
		$defaults     = RS_Beneficiaries::get_defaults();
		$global_lines = '';
		if ( is_array( $defaults ) ) {
			$parts = array();
			foreach ( $defaults as $b ) {
				if ( is_array( $b ) && isset( $b['name'], $b['percent'] ) ) {
					$parts[] = $b['name'] . '|' . number_format( (float) $b['percent'], 2, '.', '' );
				}
			}
			$global_lines = implode( "\n", $parts );
		}
		?>
		<div class="wrap rs-wrap">

			<h1><?php esc_html_e( 'Revenue Splitter — Ρυθμίσεις', 'revenue-splitter' ); ?></h1>

			<?php foreach ( $notices as $n ) : ?>
				<div class="notice notice-<?php echo esc_attr( $n['type'] ); ?> is-dismissible inline"><p><?php echo esc_html( $n['text'] ); ?></p></div>
			<?php endforeach; ?>

			<form method="post">
				<?php wp_nonce_field( 'rs_settings', 'rs_settings_nonce' ); ?>

				<h2 class="rs-h2"><?php esc_html_e( 'Default ΦΠΑ (%)', 'revenue-splitter' ); ?></h2>
				<p>
					<input type="number" name="rs_default_vat" min="0" max="100" step="0.01"
						value="<?php echo esc_attr( $default_vat ); ?>" style="width:90px;" />
					<span class="description"><?php esc_html_e( 'Ισχύει για προϊόντα χωρίς δικό τους ΦΠΑ στο General tab.', 'revenue-splitter' ); ?></span>
				</p>

			<h2 class="rs-h2"><?php esc_html_e( 'Γλώσσα οθόνης', 'revenue-splitter' ); ?></h2>
			<p>
				<select name="rs_lang">
					<option value="auto"<?php selected( $lang, 'auto' ); ?>><?php esc_html_e( 'Αυτόματη (WordPress)', 'revenue-splitter' ); ?></option>
					<option value="el"<?php selected( $lang, 'el' ); ?>>Ελληνικά</option>
					<option value="en"<?php selected( $lang, 'en' ); ?>>English</option>
				</select>
					<span class="description"><?php esc_html_e( 'Ισχύει ανά χρήστη (μόνο για εσένα). Εάν δεν έχεις διαλέξει, ακολουθείται η γλώσσα του WordPress.', 'revenue-splitter' ); ?></span>
				</p>

				<h2 class="rs-h2"><?php esc_html_e( 'Global Δικαιούχοι', 'revenue-splitter' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Ο προεπιλεγμένος καταμερισμός για κάθε προϊόν χωρίς δικό του override.', 'revenue-splitter' ); ?></p>
				<textarea name="rs_beneficiaries" rows="5" class="large-text code"
					placeholder="<?php esc_attr_e( 'Όνομα|Ποσοστό (μία γραμμή ανά δικαιούχο)', 'revenue-splitter' ); ?>"><?php echo esc_textarea( $global_lines ); ?></textarea>

				<h2 class="rs-h2"><?php esc_html_e( 'Έναρξη καταγραφής πωλήσεων', 'revenue-splitter' ); ?></h2>
				<p>
					<input type="date" name="rs_sales_since" value="<?php echo esc_attr( $sales_since ); ?>" />
					<span class="description">
						<?php esc_html_e( 'Το plugin μετράει πωλήσεις ΚΑΙ ποσοστά ΜΟΝΟ από αυτή την ημερομηνία και μετά. Κενό = χωρίς όριο (καταγράφεται όλο το ιστορικό). Χρήσιμο για καθαρή εκκίνηση χωρίς να διαγραφούν παλιές παραγγελίες.', 'revenue-splitter' ); ?>
					</span>
				</p>

				<h2 class="rs-h2"><?php esc_html_e( 'Κουπόνια δωρεάν αντιτύπων', 'revenue-splitter' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Όταν στο checkout εφαρμόζεται οποιοδήποτε από αυτά τα κουπόνια, ο πελάτης υποχρεούται να συμπληρώσει αιτιολογία δωρεάν αντιτύπου. Διαχωρισμός με κόμμα.', 'revenue-splitter' ); ?></p>
				<input type="text" name="rs_reason_coupons" class="large-text"
					placeholder="<?php esc_attr_e( 'FREEBOOK, REVIEWCOPY', 'revenue-splitter' ); ?>"
					value="<?php echo esc_attr( $coupons ); ?>" />

				<p style="margin-top:20px;">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'revenue-splitter' ); ?></button>
				</p>
			</form>

			<h2 class="rs-h2"><?php esc_html_e( 'Backup & Επαναφορά', 'revenue-splitter' ); ?></h2>
			<form method="post" enctype="multipart/form-data" style="display:inline; margin-right:10px;">
				<?php wp_nonce_field( 'rs_backup', 'rs_backup_nonce' ); ?>
				<input type="file" name="rs_import_file" accept=".json,application/json" />
				<button type="submit" name="rs_import" value="1" class="button">
					<?php esc_html_e( 'Εισαγωγή state (JSON)', 'revenue-splitter' ); ?>
				</button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG_SET . '&rs_backup_export=1' ), 'rs_backup' ) ); ?>">
				<?php esc_html_e( 'Εξαγωγή state (JSON)', 'revenue-splitter' ); ?>
			</a>
			<p class="description" style="margin-top:8px;">
				<?php esc_html_e( 'Συμπεριλαμβάνονται: ΦΠΑ default, global δικαιούχοι, κλειδιά portal (hashed), ledger (πληρωμές & έξτρα έσοδα), κουπόνια, ημερομηνία έναρξης, καταμερισμός/ΦΠΑ ανά προϊόν, αιτιολογίες δωρεάν αντιτύπων και γλώσσες χρηστών. Η εισαγωγή ΑΝΤΙΚΑΘΙΣΤΑ τα αντίστοιχα δεδομένα.', 'revenue-splitter' ); ?>
			</p>

			<?php RS_Ledger::render_admin(); ?>

			<?php self::footer(); ?>
		</div>
		<?php
	}

	/**
	 * v1.3.1 FIX (#17): PRG route για το POST των ρυθμίσεων.
	 *
	 * Εκτελείται στο admin_init (πριν από κάθε output): validate nonce +
	 * capability → save_settings() → notices σε transient → redirect.
	 * Το refresh/back μετά το save επαναλαμβάνει το GET, όχι το POST.
	 *
	 * v1.3.2 FIX (#4): nonce-fail ΔΕΝ είναι πια σιωπηλό — ρητό wp_die(),
	 * ενιαίο με το POST branch του route_backup().
	 */
	public static function route_settings(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only slug check.
		if ( ! isset( $_GET['page'] ) || self::SLUG_SET !== $_GET['page'] ) {
			return;
		}

		if ( ! isset( $_POST['rs_settings_nonce'] ) ) {
			return; // Κάποιο άλλο POST (ledger/backup) — δεν είναι δική μας δουλειά.
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rs_settings_nonce'] ) ), 'rs_settings' ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}

		$notices = self::save_settings();

		set_transient( 'rs_aui_msg_' . get_current_user_id(), $notices, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG_SET ) );
		exit;
	}

	/** Αποθηκεύει τις ρυθμίσεις. Επιστρέφει notices. */
	private static function save_settings(): array {

		$notices = array();

		// ---------- Default VAT ----------
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- numeric validation παρακάτω.
		$vat = isset( $_POST['rs_default_vat'] ) ? wp_unslash( (string) $_POST['rs_default_vat'] ) : '';
		$vat = is_numeric( $vat ) ? (float) $vat : -1;

		if ( $vat < 0 || $vat > 100 ) {
			$notices[] = array(
				'type' => 'error',
				'text' => __( 'Μη έγκυρο global default ΦΠΑ (0–100).', 'revenue-splitter' ),
			);
		} else {
			update_option( 'rs_default_vat_rate', (string) $vat );
			do_action( 'rs_invalidate_cache' );
		}

		// ---------- Γλώσσα ----------
		// v1.3.6 (#5): κενό/απουσία = reset στην αυτόματη Follow-WP mode.
		$lang = isset( $_POST['rs_lang'] ) ? sanitize_key( wp_unslash( $_POST['rs_lang'] ) ) : '';
		if ( in_array( $lang, array( 'el', 'en' ), true ) ) {
			RS_Lang::set_lang( get_current_user_id(), $lang );
		} elseif ( 'auto' === $lang ) {
			RS_Lang::set_lang( get_current_user_id(), 'auto' );
		}

		// ---------- v1.3.6 (#9): Έναρξη καταγραφής πωλήσεων ----------
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated παρακάτω.
		$since_raw = isset( $_POST['rs_sales_since'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['rs_sales_since'] ) ) ) : '';

		if ( '' === $since_raw ) {
			// Κενό = χωρίς όριο — σβήνουμε το option.
			delete_option( self::OPT_SALES_SINCE );
			do_action( 'rs_invalidate_cache' );
		} elseif ( 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $since_raw, $m )
			&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			update_option( self::OPT_SALES_SINCE, $since_raw );
			do_action( 'rs_invalidate_cache' );
		} else {
			$notices[] = array(
				'type' => 'error',
				'text' => __( 'Μη έγκυρη ημερομηνία έναρξης καταγραφής (απαιτείται Y-m-d ή κενό).', 'revenue-splitter' ),
			);
		}

		// ---------- Global beneficiaries (JSON μέσω RS_Beneficiaries) ----------
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- custom validation παρακάτω.
		$raw = isset( $_POST['rs_beneficiaries'] ) ? (string) wp_unslash( $_POST['rs_beneficiaries'] ) : '';

		$rows = array();
		$bad  = false;
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {

			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( 2 !== count( $parts ) || '' === $parts[0] || ! is_numeric( $parts[1] ) ) {
				$bad = true;
				break;
			}

			$rows[] = array(
				'name'    => $parts[0],
				'percent' => $parts[1],
			);
		}

		if ( $bad ) {
			$notices[] = array(
				'type' => 'error',
				'text' => __( 'Μη έγκυρη λίστα δικαιούχων.', 'revenue-splitter' ),
			);
		} elseif ( empty( $rows ) ) {
			// Κενό textarea = σκόπιμο κενό → σβήνουμε τα global defaults.
			delete_option( 'rs_beneficiaries' );
			do_action( 'rs_invalidate_cache' );
		} else {
			$clean = RS_Beneficiaries::sanitize_list( $rows );

			if ( null === $clean ) {
				$sum = 0.0;
				foreach ( $rows as $r ) {
					if ( is_numeric( $r['percent'] ) ) {
						$sum += (float) $r['percent'];
					}
				}
				$notices[] = array(
					'type' => 'error',
					'text' => abs( $sum - 100.0 ) > 0.05
						? sprintf(
							/* translators: %s: το άθροισμα ποσοστών */
							__( 'Τα ποσοστά δικαιούχων αθροίζουν %s%% — πρέπει να αθροίζουν 100%%.', 'revenue-splitter' ),
							number_format_i18n( $sum, 2 )
						)
						: __( 'Μη έγκυρη λίστα δικαιούχων.', 'revenue-splitter' ),
				);
			} else {
				RS_Beneficiaries::set_defaults( $clean );
				do_action( 'rs_invalidate_cache' );
			}
		}

		// ---------- Reason coupons ----------
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- custom cleaning παρακάτω.
		$codes = isset( $_POST['rs_reason_coupons'] ) ? wp_unslash( (string) $_POST['rs_reason_coupons'] ) : '';

		$clean_codes = array();
		foreach ( explode( ',', $codes ) as $c ) {
			$c = sanitize_key( strtoupper( trim( $c ) ) );
			if ( '' !== $c ) {
				$clean_codes[] = $c;
			}
		}
		update_option( self::OPT_COUPONS, implode( ',', array_unique( $clean_codes ) ) );

		if ( empty( $notices ) ) {
			$notices[] = array(
				'type' => 'success',
				'text' => __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'revenue-splitter' ),
			);
		}

		return $notices;
	}

	/**
	 * Το πλήρες state ως array — v1.3.6: υπερ-πλήρες.
	 *
	 *  - options:  whitelisted plugin options (ΦΠΑ default, global
	 *              δικαιούχοι, portal keys, ledger, κουπόνια, έναρξη).
	 *  - postmeta: _rs_split / _rs_vat_rate / _rs_beneficiaries (legacy)
	 *              ανά προϊόν + _rs_free_reason (classic datastore).
	 *              Το 'value' αντιγράφεται RAW (serialized strings από
	 *              την DB) — πιστή round-trip επαναφορά χωρίς
	 *              ερμηνεία/μετατροπή των δεδομένων.
	 *  - ordermeta:_rs_free_reason από το HPOS datastore (Woo 8.2+),
	 *              αν υπάρχει.
	 *  - usermeta: rs_lang ('el'/'en') ανά χρήστη.
	 */
	public static function export_state(): array {

		$state = array(
			'version'   => RS_VERSION,
			'options'   => array(),
			'postmeta'  => array(),
			'ordermeta' => array(),
			'usermeta'  => array(),
		);

		foreach ( self::STATE_OPTS as $opt ) {
			$state['options'][ $opt ] = get_option( $opt, '' );
		}

		global $wpdb;

		// ---- Post meta: overrides προϊόντων + free reasons (classic) ----
		$keys = array( '_rs_split', '_rs_beneficiaries', '_rs_vat_rate', '_rs_free_reason' );
		$ph   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only export.
			$wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( {$ph} )",
				$keys
			),
			ARRAY_A
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$pid = (int) $r['post_id'];
				$state['postmeta'][] = array(
					'post_id' => $pid,
					'title'   => (string) get_the_title( $pid ), // pliroforiko — βοηθά να αναγνωρίσεις το προϊόν.
					'key'     => (string) $r['meta_key'],
					'value'   => (string) $r['meta_value'],
				);
			}
		}

		// ---- Order meta: free reasons στο HPOS datastore (αν υπάρχει) ----
		$hpos_table = $wpdb->prefix . 'wc_orders_meta';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$hrows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT order_id, meta_value FROM {$hpos_table} WHERE meta_key = %s", '_rs_free_reason' ),
				ARRAY_A
			);
			if ( is_array( $hrows ) ) {
				foreach ( $hrows as $r ) {
					$state['ordermeta'][] = array(
						'order_id' => (int) $r['order_id'],
						'value'    => (string) $r['meta_value'],
					);
				}
			}
		}

		// ---- User meta: γλώσσα οθόνης ----
		$users = get_users( array( 'meta_key' => 'rs_lang', 'fields' => array( 'ID' ) ) );
		if ( is_array( $users ) ) {
			foreach ( $users as $u ) {
				$v = (string) get_user_meta( $u->ID, 'rs_lang', true );
				if ( in_array( $v, array( 'el', 'en' ), true ) ) {
					$state['usermeta'][] = array(
						'user_id' => (int) $u->ID,
						'lang'    => $v,
					);
				}
			}
		}

		return $state;
	}

	/**
	 * Εισαγωγή state. Αντικαθιστά τα whitelisted options.
	 *
	 * v1.3.2 FIX (#3): το τελικό «Η εισαγωγή ολοκληρώθηκε.» τυπώνεται
	 * ΜΟΝΟ όταν ΔΕΝ υπάρχουν error notes.
	 *
	 * v1.3.6 (#9): STRICT validation του rs_sales_since — κενό = σβήνει
	 * το όριο, έγκυρη Y-m-d = γράφεται, οτιδήποτε άλλο = ρητό error
	 * (το υπάρχον option παραμένει άθικτο — καμία σιωπηλή παραμόρφωση).
	 *
	 * @return array notices.
	 */
	public static function import_state( array $state ): array {

		if ( empty( $state['options'] ) || ! is_array( $state['options'] ) ) {
			return array( array( 'type' => 'error', 'text' => __( 'Μη έγκυρο αρχείο backup (λείπουν τα options).', 'revenue-splitter' ) ) );
		}

		$opts  = $state['options'];
		$notes = array();

		// ---- Default VAT ----
		if ( isset( $opts['rs_default_vat_rate'] ) ) {
			$v = is_numeric( $opts['rs_default_vat_rate'] ) ? (float) $opts['rs_default_vat_rate'] : -1;
			if ( $v >= 0 && $v <= 100 ) {
				update_option( 'rs_default_vat_rate', (string) $v );
			} else {
				$notes[] = array( 'type' => 'error', 'text' => __( 'Μη έγκυρο global default ΦΠΑ (0–100).', 'revenue-splitter' ) );
			}
		}

		// ---- Global beneficiaries (JSON — validation μέσω sanitize_list) ----
		if ( isset( $opts['rs_beneficiaries'] ) && is_string( $opts['rs_beneficiaries'] ) ) {
			$raw_t = trim( $opts['rs_beneficiaries'] );

			if ( '' === $raw_t ) {
				delete_option( 'rs_beneficiaries' );
			} else {
				$decoded = json_decode( $raw_t, true );
				$clean   = is_array( $decoded ) ? RS_Beneficiaries::sanitize_list( $decoded ) : null;

				if ( null !== $clean ) {
					RS_Beneficiaries::set_defaults( $clean );
				} else {
					$notes[] = array( 'type' => 'error', 'text' => __( 'Μη έγκυρη λίστα δικαιούχων.', 'revenue-splitter' ) );
				}
			}
		}

		// ---- Portal keys — v1.3.1 FIX (#6): STRICT per-value validation ----
		if ( isset( $opts['rs_portal_keys'] ) && is_string( $opts['rs_portal_keys'] ) ) {
			$decoded = json_decode( $opts['rs_portal_keys'], true );

			$valid = is_array( $decoded );
			if ( $valid ) {
				foreach ( $decoded as $name => $key ) {

					if ( ! is_string( $name ) || '' === $name || ! is_string( $key ) ) {
						$valid = false;
						break;
					}

					// Νέο format: 'sha256:' + ακριβώς 64 lowercase hex.
					$is_hash = ( 1 === preg_match( '/^sha256:[0-9a-f]{64}$/', $key ) );

					// Legacy plaintext: alphanumeric 20–200 chars.
					$is_legacy = ( 1 === preg_match( '/^[A-Za-z0-9]{20,200}$/', $key ) );

					if ( ! $is_hash && ! $is_legacy ) {
						$valid = false;
						break;
					}
				}
			}

			if ( $valid ) {
				update_option( 'rs_portal_keys', $opts['rs_portal_keys'] );
			} else {
				$notes[] = array( 'type' => 'error', 'text' => __( 'Μη έγκυρο blob κλειδιών portal.', 'revenue-splitter' ) );
			}
		}

		// ---- Ledger: WIPE + re-insert μέσω RS_Ledger::add (πλήρης validation) ----
		if ( isset( $opts['rs_ledger'] ) && is_string( $opts['rs_ledger'] ) ) {
			$decoded = json_decode( $opts['rs_ledger'], true );

			if ( null === $decoded && '' !== trim( $opts['rs_ledger'] ) ) {
				$notes[] = array( 'type' => 'error', 'text' => __( 'Μη έγκυρο blob ledger (JSON).', 'revenue-splitter' ) );
			} else {
				RS_Ledger::wipe();

				$added = 0;
				foreach ( ( is_array( $decoded ) ? $decoded : array() ) as $e ) {
					if ( ! is_array( $e ) ) {
						continue;
					}
					if ( true === RS_Ledger::add( $e ) ) {
						$added++;
					}
				}
				$notes[] = array(
					'type' => 'success',
					'text' => sprintf(
						/* translators: %d: πλήθος εγγραφών */
						__( 'Ledger: εισήχθησαν %d εγγραφές (με πλήρη validation).', 'revenue-splitter' ),
						$added
					),
				);
			}
		}

		// ---- Reason coupons ----
		if ( isset( $opts['rs_reason_coupons'] ) && is_string( $opts['rs_reason_coupons'] ) ) {
			$clean_codes = array();
			foreach ( explode( ',', $opts['rs_reason_coupons'] ) as $c ) {
				$c = sanitize_key( strtoupper( trim( $c ) ) );
				if ( '' !== $c ) {
					$clean_codes[] = $c;
				}
			}
			update_option( self::OPT_COUPONS, implode( ',', array_unique( $clean_codes ) ) );
		}

		// ---- v1.3.6 (#9): Sales since (STRICT) ----
		if ( isset( $opts['rs_sales_since'] ) && is_string( $opts['rs_sales_since'] ) ) {
			$v = trim( $opts['rs_sales_since'] );

			if ( '' === $v ) {
				delete_option( self::OPT_SALES_SINCE );
			} elseif ( 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m )
				&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				update_option( self::OPT_SALES_SINCE, $v );
			} else {
				$notes[] = array(
					'type' => 'error',
					'text' => __( 'Μη έγκυρη ημερομηνία έναρξης καταγραφής (απαιτείται Y-m-d ή κενό).', 'revenue-splitter' ),
				);
			}
		}

		// ---- v1.3.6: Post meta — καταμερισμοί/ΦΠΑ ανά προϊόν + free reasons ----
		if ( array_key_exists( 'postmeta', $state ) ) {

			if ( ! is_array( $state['postmeta'] ) ) {
				$notes[] = array( 'type' => 'error', 'text' => __( 'Μη έγκυρο τμήμα post meta στο backup.', 'revenue-splitter' ) );
			} else {
				global $wpdb;

				$allowed_keys = array( '_rs_split', '_rs_beneficiaries', '_rs_vat_rate', '_rs_free_reason' );
				$applied      = 0;
				$skipped      = array();

				// v1.3.7 (#5): HPOS-aware graft των _rs_free_reason. Αν το
				// site τρέχει το custom order datastore, οι αιτιολογίες των
				// ΠΑΡΑΓΓΕΛΙΩΝ γράφονται στο wc_orders_meta (όπου τις διαβάζει
				// το Woo) — ΟΧΙ στο postmeta που ένα HPOS order δεν αγγίζει
				// ποτέ. Ένα SHOW TABLES για όλο το import, όχι ανά γραμμή.
				$hpos_table  = $wpdb->prefix . 'wc_orders_meta';
				$hpos_active = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

				foreach ( $state['postmeta'] as $row ) {
					if ( ! is_array( $row )
						|| empty( $row['post_id'] )
						|| empty( $row['key'] )
						|| ! array_key_exists( 'value', $row )
						|| ! in_array( (string) $row['key'], $allowed_keys, true ) ) {
						continue;
					}

					$pid = (int) $row['post_id'];
					$key = (string) $row['key'];

					// ---- Free reasons ΠΡΙΝ από το get_post gate ----
					// Σε full-HPOS site η παραγγελία ΔΕΝ είναι post — το
					// get_post() πιο κάτω θα την μαρκάραdε άδικα ως
					// «προϊόν δεν βρέθηκε». Χειριζόμαστε εδώ, ξεχωριστά:
					if ( '_rs_free_reason' === $key ) {

						// HPOS site + η παραγγελία υπάρχει στο wc_orders →
						// γράψ' το εκεί (DELETE-first — το table δεν έχει
						// unique constraint στο order+key).
						if ( $hpos_active
							&& null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wc_orders WHERE id = %d", $pid ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery

							$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
								$wpdb->prepare(
									"DELETE FROM {$hpos_table} WHERE order_id = %d AND meta_key = %s",
									$pid,
									'_rs_free_reason'
								)
							);

							$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
								$hpos_table,
								array(
									'order_id'   => $pid,
									'meta_key'   => '_rs_free_reason',
									'meta_value' => (string) $row['value'],
								)
							);

							$applied++;
							continue;
						}

						// Classic datastore: η παραγγελία είναι post → postmeta.
						// Διαγραμμένη παραγγελία → σιωπηλό skip (ίδια συμπεριφορά
						// με το ordermeta section), ΟΧΙ μήνυμα «προϊόν δεν
						// βρέθηκε» — εδώ μιλάμε για παραγγελία, όχι προϊόν.
						if ( null === get_post( $pid ) ) {
							continue;
						}

						update_post_meta( $pid, $key, (string) $row['value'] );
						$applied++;
						continue;
					}

					// ---- Προϊοντικά keys: gate + skip notice ----
					if ( null === get_post( $pid ) ) {
						// Δεν υπάρχει το post με αυτό το ID — πιθανώς άλλαξαν
						// IDs μετά από μεταφορά/επαναφορά του site.
						$skipped[ $pid ] = true;
						continue;
					}

					update_post_meta( $pid, $key, (string) $row['value'] );
					$applied++;
				}

				$notes[] = array(
					'type' => 'success',
					'text' => sprintf(
						/* translators: %d: πλήθος εγγραφών */
						__( 'Προϊοντικά overrides: εφαρμόστηκαν %d εγγραφές.', 'revenue-splitter' ),
						$applied
					),
				);

				if ( ! empty( $skipped ) ) {
					$notes[] = array(
						'type' => 'error',
						'text' => sprintf(
							/* translators: %s: λίστα IDs */
							__( 'ΔΕΝ βρέθηκαν τα προϊόντα με IDs %s — τα overrides τους παραλείφθηκαν (τα product IDs του backup δεν ταιριάζουν με τα τρέχοντα).', 'revenue-splitter' ),
							implode( ', ', array_map( 'strval', array_keys( $skipped ) ) )
						),
					);
				}
			}
		}

		// ---- v1.3.6: HPOS free-copy reasons (αν υπάρχει το datastore) ----
		if ( ! empty( $state['ordermeta'] ) && is_array( $state['ordermeta'] ) ) {
			global $wpdb;

			$hpos_table = $wpdb->prefix . 'wc_orders_meta';

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$applied = 0;

				foreach ( $state['ordermeta'] as $row ) {
					if ( ! is_array( $row ) || empty( $row['order_id'] ) || ! array_key_exists( 'value', $row ) ) {
						continue;
					}

					$oid = (int) $row['order_id'];

					$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wc_orders WHERE id = %d", $oid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					if ( null === $exists ) {
						continue; // Η παραγγελία δεν υπάρχει (ή δεν είναι σε HPOS) — skip, όχι error.
					}

					// Καθαρισμός παλιάς γραμμής (το wc_orders_meta δεν έχει
					// unique constraint στο order_id+meta_key — χωρίς αυτό,
					// κάθε επαναλαμβανόμενο import δημιουργούσε διπλότυπα).
					$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$wpdb->prepare(
							"DELETE FROM {$hpos_table} WHERE order_id = %d AND meta_key = %s",
							$oid,
							'_rs_free_reason'
						)
					);

					$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$hpos_table,
						array(
							'order_id'   => $oid,
							'meta_key'   => '_rs_free_reason',
							'meta_value' => (string) $row['value'],
						)
					);
					$applied++;
				}

				if ( $applied > 0 ) {
					$notes[] = array(
						'type' => 'success',
						'text' => sprintf(
							/* translators: %d: πλήθος εγγραφών */
							__( 'Αιτιολογίες δωρεάν αντιτύπων (HPOS): εφαρμόστηκαν %d εγγραφές.', 'revenue-splitter' ),
							$applied
						),
					);
				}
			}
		}

		// ---- v1.3.6: User meta — γλώσσα οθόνης ----
		if ( ! empty( $state['usermeta'] ) && is_array( $state['usermeta'] ) ) {
			$applied = 0;

			foreach ( $state['usermeta'] as $u ) {
				if ( ! is_array( $u ) || empty( $u['user_id'] ) ) {
					continue;
				}
				if ( ! in_array( (string) ( $u['lang'] ?? '' ), array( 'el', 'en' ), true ) ) {
					continue;
				}
				if ( ! get_userdata( (int) $u['user_id'] ) ) {
					continue; // Ο χρήστης δεν υπάρχει πια.
				}

				update_user_meta( (int) $u['user_id'], 'rs_lang', (string) $u['lang'] );
				$applied++;
			}

			if ( $applied > 0 ) {
				$notes[] = array(
					'type' => 'success',
					'text' => sprintf(
						/* translators: %d: πλήθος χρηστών */
						__( 'Γλώσσα οθόνης: εφαρμόστηκε σε %d χρήστες.', 'revenue-splitter' ),
						$applied
					),
				);
			}
		}

		do_action( 'rs_invalidate_cache' );

		// v1.3.2 FIX (#3): επιχειρησιακό τελικό notice.
		$has_errors = false;
		foreach ( $notes as $n ) {
			if ( isset( $n['type'] ) && 'error' === $n['type'] ) {
				$has_errors = true;
				break;
			}
		}

		if ( $has_errors ) {
			$notes[] = array(
				'type' => 'error',
				'text' => __( 'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ — τα άκυρα τμήματα ΔΕΝ αντικαταστάθηκαν (δες τα παραπάνω σφάλματα).', 'revenue-splitter' ),
			);
		} else {
			$notes[] = array( 'type' => 'success', 'text' => __( 'Η εισαγωγή ολοκληρώθηκε.', 'revenue-splitter' ) );
		}

		return $notes;
	}

	/** PRG route για backup/import (admin_init — πριν από output). */
	public static function route_backup(): void {

		// ---------- Export (GET + nonce) ----------
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce ελέγχεται παρακάτω.
		if ( isset( $_GET['rs_backup_export'] ) ) {

			if ( ! isset( $_GET['_wpnonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'rs_backup' )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
			}

			nocache_headers();
			header( 'Content-Type: application/json; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="revenue-splitter-state-' . gmdate( 'Y-m-d' ) . '.json"' );
			echo wp_json_encode( self::export_state(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON payload, attachments.
			exit;
		}

		// ---------- Import (POST + nonce + PRG) ----------
		if ( isset( $_POST['rs_import'] ) ) {

			if ( ! isset( $_POST['rs_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rs_backup_nonce'] ) ), 'rs_backup' )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
			}

			$notices = array( array( 'type' => 'error', 'text' => __( 'Δεν επιλέχθηκε αρχείο JSON.', 'revenue-splitter' ) ) );

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON payload, json_decoded ως array.
			if ( ! empty( $_FILES['rs_import_file']['tmp_name'] )
				&& is_uploaded_file( $_FILES['rs_import_file']['tmp_name'] ) ) {

				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.readfile_read_file_get_contents -- uploaded tmp file.
				$raw   = (string) file_get_contents( $_FILES['rs_import_file']['tmp_name'] );
				$state = json_decode( $raw, true );

				if ( is_array( $state ) ) {
					$notices = self::import_state( $state );
				} else {
					$notices = array( array( 'type' => 'error', 'text' => __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'revenue-splitter' ) ) );
				}
			}

			set_transient( 'rs_aui_msg_' . get_current_user_id(), $notices, 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG_SET ) );
			exit;
		}
	}

	/** Διαβάζει (και αδειάζει) τα transient notices του backup/import/settings. */
	private static function take_backup_msgs(): array {
		$raw = get_transient( 'rs_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'rs_aui_msg_' . get_current_user_id() );
			return $raw;
		}
		return array();
	}

	/* =====================================================================
	 * Exports (admin_init + triple gating)  ← Part 3-B ξεκινά ΑΚΡΙΒΩΣ εδώ
	 * =================================================================== */
	private static function export_url( string $fmt, array $per, int $product_id = 0 ): string {

		$url = admin_url( 'admin.php?page=' . self::SLUG_DASH
			. '&rs_export=' . rawurlencode( $fmt )
			. '&rs_period=' . rawurlencode( $per['preset'] )
			. '&rs_start=' . rawurlencode( $per['start'] )
			. '&rs_end=' . rawurlencode( $per['end'] ) );

		if ( $product_id > 0 ) {
			$url .= '&rs_product=' . rawurlencode( (string) $product_id );
		}

		return wp_nonce_url( $url, 'rs_export' );
	}

	public static function route_exports(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce ελέγχεται παρακάτω.
		if ( ! isset( $_GET['rs_export'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || self::SLUG_DASH !== $_GET['page'] ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'rs_export' ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- validated στο current_period().
		$fmt = sanitize_key( (string) wp_unslash( $_GET['rs_export'] ) );
		$per = self::current_period();

		$run = array(
			'date_start' => $per['start'],
			'date_end'   => $per['end'],
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, absint παρακάτω.
		if ( isset( $_GET['rs_product'] ) ) {
			$pid = absint( $_GET['rs_product'] );
			if ( $pid > 0 ) {
				$run['product_ids'] = array( $pid );
			}
		}

		$report = RS_Reports::run( $run );

		$fname = 'revenue-splitter-' . $per['start'] . '_' . $per['end'];

		switch ( $fmt ) {
			case 'csv':
				self::stream_csv( $report, $per, $fname );
				exit;
			case 'xls':
				self::stream_xls( $report, $per );
				exit;
			case 'html':
				self::stream_html( $report, $per );
				exit;
			case 'json':
			default:
				nocache_headers();
				header( 'Content-Type: application/json; charset=UTF-8' );
				header( 'Content-Disposition: attachment; filename="' . $fname . '.json"' );
				echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON payload, attachment.
				exit;
		}
	}

	/* ---------------------------------------------------------------------
	 * Shared export helpers (χρησιμοποιούνται και από το RS_Portal)
	 * ------------------------------------------------------------------- */

	/**
	 * Κελιά CSV με formula-injection protection (v1.3.1 FIX #9).
	 *
	 * Public — το RS_Portal::stream_csv τη χρησιμοποιεί εξωτερικά.
	 * Τιμές που ξεκινούν με =, +, -, @ ή tab/CR παίρνουν apostrophe prefix,
	 * ώστε κακόβουλο product title να μην εκτελείται ως Excel formula.
	 */
	public static function csv_cell( $value ): string {

		$cell = (string) $value;

		if ( '' === $cell ) {
			return '';
		}

		if ( in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $cell;
		}

		return $cell;
	}

	/** Header του CSV/XLS/HTML export (πίνακας «Ανά προϊόν» — 12 στήλες). */
	private static function csv_header(): array {
		return array(
			__( 'Προϊόν', 'revenue-splitter' ),
			__( 'Τεμ.', 'revenue-splitter' ),
			__( 'Πλήρης', 'revenue-splitter' ),
			__( 'Έκπτωση', 'revenue-splitter' ),
			__( 'Μέση έκπτωση (%)', 'revenue-splitter' ),
			__( 'Κουπόνια', 'revenue-splitter' ),
			__( 'Δωρεάν', 'revenue-splitter' ),
			__( 'Μικτό', 'revenue-splitter' ),
			__( 'ΦΠΑ', 'revenue-splitter' ),
			__( 'Καθαρό', 'revenue-splitter' ),
			__( 'Στοκ', 'revenue-splitter' ),
			__( 'Καταμερισμός', 'revenue-splitter' ),
		);
	}

	/**
	 * Cells μίας γραμμής προϊόντος για τα exports (CSV/XLS/HTML).
	 *
	 * v1.3.6 (#8): + «Μέση έκπτωση (%)» και «Κουπόνια» — συνέπεια με τον
	 * πίνακα του dashboard.
	 */
	private static function product_cells( array $p ): array {

		$splits = array();
		foreach ( $p['splits'] as $s ) {
			$splits[] = sprintf(
				'%s %s%% · %s',
				(string) $s['name'],
				number_format( (float) $s['percent'], 1, ',', '.' ),
				number_format( (float) $s['amount'], 2, ',', '.' )
			);
		}

		return array(
			(string) $p['title'],
			(string) $p['qty'],
			(string) $p['qty_full'],
			(string) $p['qty_disc'],
			number_format( (float) $p['disc_pct'], 1, ',', '' ),
			! empty( $p['coupons'] ) ? implode( ', ', $p['coupons'] ) : '—',
			(string) $p['qty_free'],
			number_format( (float) $p['gross'], 2, ',', '.' ),
			number_format( (float) $p['vat'], 2, ',', '.' ),
			number_format( (float) $p['net'], 2, ',', '.' ),
			self::product_stock( (int) $p['product_id'] ),
			implode( ' | ', $splits ),
		);
	}

	private static function stream_csv( array $report, array $per, string $fname ): void {

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $fname . '.csv"' );

		$fh = fopen( 'php://output', 'w' );

		// UTF-8 BOM για σωστή αναγνώριση σε Excel.
		fwrite( $fh, "\xEF\xBB\xBF" );

		fputcsv( $fh, self::csv_header() );

		foreach ( $report['products'] as $p ) {
			fputcsv( $fh, array_map( array( __CLASS__, 'csv_cell' ), self::product_cells( $p ) ) );
		}

		// ΣΥΝΟΛΑ row.
		fputcsv(
			$fh,
			array(
				__( 'ΣΥΝΟΛΑ', 'revenue-splitter' ),
				'', '', '', '', '', '',
				number_format( (float) $report['totals']['gross'], 2, ',', '.' ),
				number_format( (float) $report['totals']['vat'], 2, ',', '.' ),
				number_format( (float) $report['totals']['net'], 2, ',', '.' ),
				'',
				'',
			)
		);

		fclose( $fh );
		exit;
	}

	private static function stream_xls( array $report, array $per ): void {

		nocache_headers();
		header( 'Content-Type: application/vnd.ms-excel; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="revenue-splitter-' . $per['start'] . '_' . $per['end'] . '.xls"' );

		echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';
		echo '<table border="1">';
		echo '<tr><th colspan="12">' . esc_html( sprintf( /* translators: 1: από, 2: έως */ __( 'Revenue Splitter — %1$s έως %2$s', 'revenue-splitter' ), $per['start'], $per['end'] ) ) . '</th></tr>';

		echo '<tr>';
		foreach ( self::csv_header() as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr>';

		foreach ( $report['products'] as $p ) {
			echo '<tr>';
			foreach ( self::product_cells( $p ) as $cell ) {
				echo '<td>' . esc_html( $cell ) . '</td>';
			}
			echo '</tr>';
		}

		echo '</table></body></html>';
		exit;
	}

	private static function stream_html( array $report, array $per ): void {

		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="revenue-splitter-' . $per['start'] . '_' . $per['end'] . '.html"' );

		// v1.3.1 FIX (#18): lang attribute βάσει γλώσσας χρήστη.
		$lang = RS_Lang::get_lang();

		echo '<!DOCTYPE html><html lang="' . esc_attr( $lang ) . '"><head><meta charset="UTF-8">';
		echo '<title>' . esc_html( sprintf( /* translators: 1: από, 2: έως */ __( 'Revenue Splitter — %1$s έως %2$s', 'revenue-splitter' ), $per['start'], $per['end'] ) ) . '</title>';
		echo '<style>body{font-family:system-ui,sans-serif;margin:24px;color:#1d2327}table{border-collapse:collapse;width:100%}th,td{border:1px solid #c3c4c7;padding:6px 10px;text-align:left}th{background:#f0f0f1}td.num{text-align:right}</style>';
		echo '</head><body>';

		echo '<h1>' . esc_html( sprintf( /* translators: 1: από, 2: έως */ __( 'Revenue Splitter — %1$s έως %2$s', 'revenue-splitter' ), $per['start'], $per['end'] ) ) . '</h1>';

		echo '<table><thead><tr>';
		foreach ( self::csv_header() as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $report['products'] as $p ) {
			echo '<tr>';
			foreach ( self::product_cells( $p ) as $cell ) {
				echo '<td>' . esc_html( $cell ) . '</td>';
			}
			echo '</tr>';
		}

		echo '<tr><th>' . esc_html__( 'ΣΥΝΟΛΑ', 'revenue-splitter' ) . '</th><th colspan="6"></th><th>' . esc_html( number_format_i18n( (float) $report['totals']['gross'], 2 ) ) . '</th><th>' . esc_html( number_format_i18n( (float) $report['totals']['vat'], 2 ) ) . '</th><th>' . esc_html( number_format_i18n( (float) $report['totals']['net'], 2 ) ) . '</th><th colspan="2"></th></tr>';

		echo '</tbody></table></body></html>';
		exit;
	}

	/* =====================================================================
	 * Widget (render) — v1.3.0
	 * =================================================================== */

	public static function render_widget(): void {

		if ( ! current_user_can( self::CAP ) ) {
			esc_html_e( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' );
			return;
		}

		$per    = self::current_period();
		$report = RS_Reports::run(
			array(
				'date_start' => $per['start'],
				'date_end'   => $per['end'],
			)
		);
		$cur = self::currency_fmt();
		?>
		<div class="rs-widget">
			<div class="rs-kpis rs-widget-kpis">
				<div class="rs-kpi"><span class="rs-kpi-label"><?php esc_html_e( 'Παραγγελίες', 'revenue-splitter' ); ?></span><strong><?php echo esc_html( number_format_i18n( (int) $report['order_count'] ) ); ?></strong></div>
				<div class="rs-kpi"><span class="rs-kpi-label"><?php esc_html_e( 'Μικτό (με ΦΠΑ)', 'revenue-splitter' ); ?></span><strong><?php echo esc_html( $cur( $report['totals']['gross'] ) ); ?></strong></div>
				<div class="rs-kpi"><span class="rs-kpi-label"><?php esc_html_e( 'ΦΠΑ', 'revenue-splitter' ); ?></span><strong class="rs-neg">−<?php echo esc_html( $cur( $report['totals']['vat'] ) ); ?></strong></div>
				<div class="rs-kpi"><span class="rs-kpi-label"><?php esc_html_e( 'Καθαρό', 'revenue-splitter' ); ?></span><strong><?php echo esc_html( $cur( $report['totals']['net'] ) ); ?></strong></div>
			</div>

			<p style="margin:10px 0 4px;">
				<strong><?php echo esc_html( $per['label'] ); ?></strong>
				<span class="rs-muted"> · <?php echo esc_html( $per['start'] ); ?> → <?php echo esc_html( $per['end'] ); ?></span>
			</p>

			<table class="widefat striped rs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Δικαιούχος', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Πωλήσεις', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Υπόλοιπο', 'revenue-splitter' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $report['beneficiaries'] ) ) : ?>
					<tr><td colspan="3" class="rs-empty"><?php esc_html_e( 'Κανείς δεν έχει μερίδιο στην περίοδο.', 'revenue-splitter' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( array_slice( $report['beneficiaries'], 0, 5 ) as $b ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $b['name'] ); ?></strong></td>
						<td class="num"><?php echo esc_html( $cur( (float) $b['amount'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $cur( round( (float) $b['amount'] - RS_Ledger::sum( $b['name'], $per['start'], $per['end'], 'payment' ), 2 ) ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<p style="margin:8px 0 0;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_DASH ) ); ?>">
					<?php esc_html_e( 'Πλήρες dashboard →', 'revenue-splitter' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	/**
	 * Callable που μορφοποιεί ποσά στο νόμισμα του WooCommerce.
	 *
	 * @return callable (float|string) => string
	 */
	private static function currency_fmt(): callable {
		return static function ( $amount ): string {
			if ( function_exists( 'wc_price' ) ) {
				return wp_strip_all_tags( wc_price( (float) $amount ) );
			}
			return number_format_i18n( (float) $amount, 2 );
		};
	}

	/**
	 * Στοκ προϊόντος σε readable μορφή ('∞' για μη λογιζόμενα).
	 *
	 * Public (v1.3.1 #16) — μοιράζεται με το RS_Portal (μία υλοποίηση).
	 */
	public static function product_stock( int $pid ): string {

		$product = wc_get_product( $pid );

		if ( ! $product instanceof WC_Product ) {
			return '—';
		}

		if ( ! $product->get_manage_stock() ) {
			return '∞';
		}

		return (string) $product->get_stock_quantity();
	}

	/** Footer με clickable cross-links (v1.3.0). */
	private static function footer(): void {
		?>
		<hr style="margin-top:24px;" />
		<p class="rs-footer">
			<?php
			printf(
				/* translators: %s: όνομα δημιουργού */
				esc_html__( 'Made with <3 by %s', 'revenue-splitter' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="rs-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="rs-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'revenue-splitter' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<?php
	}
}
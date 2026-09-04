<?php
/**
 * RS_Admin_UI — Admin pages: Dashboard, Ρυθμίσεις, widget, exports,
 * backup/import (v1.3.0).
 *
 * Security pattern:
 *  - Όλα τα privileged GET (exports, state export) = admin_init + TRIPLE
 *    gating: page slug → nonce → capability.
 *  - Όλα τα POST που αλλάζουν state με nonce + capability.
 *  - Backup/import + settings save = POST στο admin_init + PRG (transient
 *    notices) — πλήρως ενιαίο pattern με το ledger. Το refresh/back του
 *    browser δεν επαναλαμβάνει κανένα POST πια.
 *
 * v1.3.0 (#1): Στήλη «Συνολικό υπόλοιπο» ανά δικαιούχο στο dashboard.
 * v1.3.0 (#8): Πίνακας ΦΠΑ ανά συντελεστή (περιόδου) στο dashboard.
 * v1.3.0 (#4): Export/Import πλήρους plugin state σε JSON.
 * v1.3.0: Footer με clickable links (koulaxizis.gr · glarolykoi.net · noxpress.tech).
 *
 * v1.3.1 FIX (#5): Τα KPI labels παίρνουν class rs-kpi-label (τα αρχικά CSS
 * rules εφαρμόζονται πλέον πραγματικά).
 * v1.3.1 FIX (#6): Το import των portal keys κάνει STRICT per-value
 * validation (sha256:<64 hex> ή legacy alphanumeric plaintext) — ένα
 * παραμορφωμένο backup ΔΕΝ μπορεί πλέον να κλειδώσει όλους τους
 * δικαιούχους εκτός portal σιωπηλά.
 * v1.3.1 FIX (#9): CSV formula injection protection (csv_cell) — τιμές
 * που ξεκινούν με =, +, -, @ ή tab/CR παίρνουν apostrophe prefix.
 * v1.3.1 FIX (#17): Τα settings σώζονται με PRG (route_settings στο
 * admin_init + transient notice) — ενιαίο με ledger/backup/import.
 * v1.3.1 FIX (#18): Το HTML export χρησιμοποιεί το lang attribute της
 * γλώσσας χρήστη (el/en).
 * v1.3.1 (#16): product_stock() γίνεται public — τη μοιράζεται και το
 * Portal (μία υλοποίηση, μηδέν drift).
 * v1.3.1 (re-audit): csv_cell() γίνεται ΚΑΙ ΑΥΤΟ public — το RS_Portal
 * (stream_csv) το καλεί εξωτερικά. Πριν: private → PHP Error στην πρώτη
 * CSV εξαγωγή από portal.
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

	/** Whitelist options για backup/import (#4). */
	const STATE_OPTS = array(
		'rs_default_vat_rate',
		'rs_beneficiaries',
		'rs_portal_keys',
		'rs_ledger',
		'rs_reason_coupons',
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

	public static function assets( string $hook ): void {

		$is_ours = false !== strpos( $hook, 'revenue-splitter' );
		$product = 'post.php' === $hook || 'post-new.php' === $hook;

		if ( ! $is_ours && ! $product ) {
			return;
		}

		$base = plugin_dir_url( RS_FILE );

		wp_enqueue_style( 'rs-admin', $base . 'assets/admin.css', array(), (string) filemtime( RS_PATH . 'assets/admin.css' ) );
		wp_enqueue_script( 'rs-admin', $base . 'assets/admin.js', array(), (string) filemtime( RS_PATH . 'assets/admin.js' ), true );
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

		$per    = self::current_period();
		$report = RS_Reports::run(
			array(
				'date_start' => $per['start'],
				'date_end'   => $per['end'],
			)
		);

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

				<select name="rs_period">
					<option value="7d"<?php selected( $per['preset'], '7d' ); ?>><?php esc_html_e( 'Τελευταίες 7 ημέρες', 'revenue-splitter' ); ?></option>
					<option value="30d"<?php selected( $per['preset'], '30d' ); ?>><?php esc_html_e( 'Τελευταίες 30 ημέρες', 'revenue-splitter' ); ?></option>
					<option value="month"<?php selected( $per['preset'], 'month' ); ?>><?php esc_html_e( 'Τρέχων μήνας', 'revenue-splitter' ); ?></option>
					<option value="prev_month"<?php selected( $per['preset'], 'prev_month' ); ?>><?php esc_html_e( 'Προηγούμενος μήνας', 'revenue-splitter' ); ?></option>
					<option value="year"<?php selected( $per['preset'], 'year' ); ?>><?php esc_html_e( 'Τρέχον έτος', 'revenue-splitter' ); ?></option>
					<option value="prev_year"<?php selected( $per['preset'], 'prev_year' ); ?>><?php esc_html_e( 'Προηγούμενο έτος', 'revenue-splitter' ); ?></option>
					<option value="custom"<?php selected( $per['preset'], 'custom' ); ?>><?php esc_html_e( 'Προσαρμοσμένο', 'revenue-splitter' ); ?></option>
				</select>

				<input type="date" name="rs_start" value="<?php echo esc_attr( $per['start'] ); ?>" />
				<input type="date" name="rs_end" value="<?php echo esc_attr( $per['end'] ); ?>" />

				<button type="submit" class="button"><?php esc_html_e( 'Εφαρμογή', 'revenue-splitter' ); ?></button>

				<?php foreach ( array( 'csv', 'xls', 'html', 'json' ) as $fmt ) : ?>
					<a class="button" href="<?php echo esc_url( self::export_url( $fmt, $per ) ); ?>">
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
					<tr><td colspan="10" class="rs-empty"><?php esc_html_e( 'Καμία πωλημένη γραμμή στην περίοδο.', 'revenue-splitter' ); ?></td></tr>
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
						<td class="num"><?php echo esc_html( number_format_i18n( $p['qty_free'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $cur( $p['gross'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $cur( $p['vat'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $cur( $p['net'] ) ); ?></td>
						<td class="num"><?php echo esc_html( self::product_stock( (int) $p['product_id'] ) ); ?></td>
						<td>
							<?php
							$parts = array_map(
								static function ( $s ) {
									return esc_html( $s['name'] ) . ' <span class="rs-muted">' . esc_html( number_format_i18n( $s['percent'], 1 ) ) . '% · ' . esc_html( number_format_i18n( $s['amount'], 2 ) ) . '</span>';
								},
								$p['splits']
							);
							echo implode( '<br />', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput -- esc_html εντός.
							?>
						</td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
				<tfoot>
					<tr>
						<td><?php esc_html_e( 'ΣΥΝΟΛΑ', 'revenue-splitter' ); ?></td>
						<td colspan="5"></td>
						<td class="num"><?php echo esc_html( $cur( $report['totals']['gross'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $cur( $report['totals']['vat'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $cur( $report['totals']['net'] ) ); ?></td>
						<td></td>
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
		$lang        = RS_Lang::get_lang();

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
						<option value="el"<?php selected( $lang, 'el' ); ?>>Ελληνικά</option>
						<option value="en"<?php selected( $lang, 'en' ); ?>>English</option>
					</select>
					<span class="description"><?php esc_html_e( 'Ισχύει ανά χρήστη (μόνο για εσένα).', 'revenue-splitter' ); ?></span>
				</p>

				<h2 class="rs-h2"><?php esc_html_e( 'Global Δικαιούχοι', 'revenue-splitter' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Ο προεπιλεγμένος καταμερισμός για κάθε προϊόν χωρίς δικό του override.', 'revenue-splitter' ); ?></p>
				<textarea name="rs_beneficiaries" rows="5" class="large-text code"
					placeholder="<?php esc_attr_e( 'Όνομα|Ποσοστό (μία γραμμή ανά δικαιούχο)', 'revenue-splitter' ); ?>"><?php echo esc_textarea( $global_lines ); ?></textarea>

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
				<?php esc_html_e( 'Συμπεριλαμβάνονται: ΦΠΑ default, δικαιούχοι, κλειδιά portal (hashed), ledger, κουπόνια. Η εισαγωγή ΑΝΤΙΚΑΘΙΣΤΑ τα αντίστοιχα δεδομένα.', 'revenue-splitter' ); ?>
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
	 * Ανακυκλώνει το transient prefix rs_aui_msg_ (ίδιο με backup/import)
	 * — ήδη καθαρισμένο στο uninstall.php, ήδη διαβασμένο από το
	 * render_settings() μέσω take_backup_msgs(). Μηδέν νέα κλειδιά.
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
			return;
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
		$lang = isset( $_POST['rs_lang'] ) ? sanitize_key( wp_unslash( $_POST['rs_lang'] ) ) : 'el';
		RS_Lang::set_lang( get_current_user_id(), in_array( $lang, array( 'el', 'en' ), true ) ? $lang : 'el' );

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
			if ( count( $parts ) !== 2 || '' === $parts[0] || ! is_numeric( $parts[1] ) ) {
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
			// Κενό textarea = σκόπιμο κενό → σβήνουμε τα global defaults
			// (τα προϊόντα χωρίς override τραβάνε το trivial 100%).
			delete_option( 'rs_beneficiaries' );
			do_action( 'rs_invalidate_cache' );
		} else {
			$clean = RS_Beneficiaries::sanitize_list( $rows );

			if ( null === $clean ) {
				// Διαφοροποίηση μηνύματος: Σ ≠ 100 ή άκυρη γραμμή.
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

	/* =====================================================================
	 * Backup / Import (#4) — PRG στο admin_init
	 * =================================================================== */

	/** Το πλήρες state ως array (options whitelist). */
	public static function export_state(): array {

		$state = array( 'version' => RS_VERSION, 'options' => array() );

		foreach ( self::STATE_OPTS as $opt ) {
			$state['options'][ $opt ] = get_option( $opt, '' );
		}

		return $state;
	}

	/**
	 * Εισαγωγή state. Αντικαθιστά τα whitelisted options.
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
					$is_hash = 1 === preg_match( '/^sha256:[0-9a-f]{64}$/', $key );

					// Legacy plaintext: alphanumeric 20–200 chars
					// (wp_generate_password(64, false, false) → alnum).
					$is_legacy = 1 === preg_match( '/^[A-Za-z0-9]{20,200}$/', $key );

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
		// v1.3.1 (#12-b): ένα μαζικό wipe() αντί για loop delete() (O(n²)).
		if ( isset( $opts['rs_ledger'] ) && is_string( $opts['rs_ledger'] ) ) {
			$decoded = json_decode( $opts['rs_ledger'], true );

			if ( null === $decoded && '' !== trim( $opts['rs_ledger'] ) ) {
				$notes[] = array( 'type' => 'error', 'text' => __( 'Μη έγκυρο blob ledger (JSON).', 'revenue-splitter' ) );
			} else {
				// Μαζικός καθαρισμός υπαρχόντων μέσω του public API.
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

		do_action( 'rs_invalidate_cache' );

		$notes[] = array( 'type' => 'success', 'text' => __( 'Η εισαγωγή ολοκληρώθηκε.', 'revenue-splitter' ) );

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
			echo wp_json_encode( self::export_state(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
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
	 * Exports (admin_init + triple gating — pattern v1.1.3)
	 * =================================================================== */

	private static function export_url( string $fmt, array $per ): string {
		return wp_nonce_url(
			admin_url( 'admin.php?page=' . self::SLUG_DASH
				. '&rs_export=' . rawurlencode( $fmt )
				. '&rs_period=' . rawurlencode( $per['preset'] )
				. '&rs_start=' . rawurlencode( $per['start'] )
				. '&rs_end=' . rawurlencode( $per['end'] ) ),
			'rs_export'
		);
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
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- validated στο current_period().
		$fmt = sanitize_key( (string) wp_unslash( $_GET['rs_export'] ) );
		$per = self::current_period();

		$report = RS_Reports::run(
			array(
				'date_start' => $per['start'],
				'date_end'   => $per['end'],
			)
		);

		$fname = 'revenue-splitter-' . $per['start'] . '_' . $per['end'];

		switch ( $fmt ) {
			case 'csv':
				self::stream_csv( $fname, $per, $report );
				break;
			case 'xls':
				self::stream_xls( $fname, $per, $report );
				break;
			case 'html':
				self::stream_html( $fname, $per, $report );
				break;
			case 'json':
				nocache_headers();
				header( 'Content-Type: application/json; charset=UTF-8' );
				header( 'Content-Disposition: attachment; filename="' . $fname . '.json"' );
				echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
				exit;
		}
	}

	/**
	 * v1.3.1 FIX (#9): CSV formula injection guard.
	 *
	 * Κελιά με user-ελεγχόμενο περιεχόμενο (τίτλοι προϊόντων κ.λπ.) που
	 * ξεκινούν με =, +, -, @ ή tab/CR εκτελούνται ως τύποι στο Excel —
	 * classic CSV injection. Το αθώο "'" prefix τα ουδετεροποιεί.
	 *
	 * PUBLIC (re-audit fix): καλείται και από το RS_Portal::stream_csv —
	 * μία υλοποίηση, μηδέν drift (ίδιο pattern με το product_stock()).
	 */
	public static function csv_cell( $value ): string {
		$s = (string) $value;
		if ( 1 === preg_match( '/^[=+\-@\t\r]/', $s ) ) {
			return "'" . $s;
		}
		return $s;
	}

	/* =====================================================================
	 * Export writers
	 * #9: CSV formula-injection guard (csv_cell) — εφαρμόζεται και στο
	 *     HTML-table .xls, για συνέπεια.
	 * #18: Το HTML export παίρνει δυναμικό lang attribute (el/en).
	 * =================================================================== */

	private static function stream_csv( string $fname, array $per, array $report ): void {

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $fname . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM για Excel + ελληνικά.

		$csv = static function ( array $fields ) use ( $out ) {
			fputcsv( $out, $fields, ',', '"', '\\' );
		};

		$csv( array( $per['label'], $per['start'], $per['end'], (int) $report['order_count'] ) );
		$csv( array() );

		$csv(
			array(
				__( 'Προϊόν', 'revenue-splitter' ),
				__( 'Τεμ.', 'revenue-splitter' ),
				__( 'Πλήρης', 'revenue-splitter' ),
				__( 'Έκπτωση', 'revenue-splitter' ),
				__( 'Δωρεάν', 'revenue-splitter' ),
				__( 'Μικτό', 'revenue-splitter' ),
				__( 'ΦΠΑ', 'revenue-splitter' ),
				__( 'Καθαρό', 'revenue-splitter' ),
			)
		);

		foreach ( $report['products'] as $p ) {
			$csv(
				array(
					self::csv_cell( $p['title'] ), // #9: guard (user-controlled title).
					$p['qty'],
					$p['qty_full'],
					$p['qty_disc'],
					$p['qty_free'],
					number_format( (float) $p['gross'], 2, ',', '' ),
					number_format( (float) $p['vat'], 2, ',', '' ),
					number_format( (float) $p['net'], 2, ',', '' ),
				)
			);
		}

		$csv( array() );
		$csv(
			array(
				'',
				__( 'ΣΥΝΟΛΑ', 'revenue-splitter' ),
				'',
				'',
				'',
				number_format( (float) $report['totals']['gross'], 2, ',', '' ),
				number_format( (float) $report['totals']['vat'], 2, ',', '' ),
				number_format( (float) $report['totals']['net'], 2, ',', '' ),
			)
		);

		$csv( array() );
		$csv(
			array(
				__( 'Δικαιούχος', 'revenue-splitter' ),
				__( 'Ποσό (περιόδου)', 'revenue-splitter' ),
			)
		);
		foreach ( $report['beneficiaries'] as $b ) {
			$csv(
				array(
					self::csv_cell( $b['name'] ), // #9: guard (user-controlled name).
					number_format( (float) $b['amount'], 2, ',', '' ),
				)
			);
		}

		fclose( $out );
		exit;
	}

	/**
	 * .xls = HTML table (#14: γνωστό trade-off — το Excel δείχνει warning
	 * «η μορφή δεν ταιριάζει». Δουλεύει 100%, δεν αλλάζει στη v1.3.1 —
	 * σημειωμένο για μελλοντική αντικατάσταση με πραγματικό XLSX αν
	 * ζητηθεί).
	 */
	private static function stream_xls( string $fname, array $per, array $report ): void {

		nocache_headers();
		header( 'Content-Type: application/vnd.ms-excel; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $fname . '.xls"' );

		$cur = self::currency_fmt();

		echo '<html><head><meta charset="UTF-8" /></head><body>';
		echo '<table border="1" cellpadding="4" cellspacing="0">';
		echo '<tr><th colspan="8">' . esc_html( $per['label'] ) . ' — ' . esc_html( $per['start'] ) . ' → ' . esc_html( $per['end'] ) . ' (' . (int) $report['order_count'] . ')</th></tr>';
		echo '<tr><th>' . esc_html__( 'Προϊόν', 'revenue-splitter' ) . '</th><th>' . esc_html__( 'Τεμ.', 'revenue-splitter' ) . '</th><th>' . esc_html__( 'Πλήρης', 'revenue-splitter' ) . '</th><th>' . esc_html__( 'Έκπτωση', 'revenue-splitter' ) . '</th><th>' . esc_html__( 'Δωρεάν', 'revenue-splitter' ) . '</th><th>' . esc_html__( 'Μικτό', 'revenue-splitter' ) . '</th><th>' . esc_html__( 'ΦΠΑ', 'revenue-splitter' ) . '</th><th>' . esc_html__( 'Καθαρό', 'revenue-splitter' ) . '</th></tr>';

		foreach ( $report['products'] as $p ) {
			echo '<tr>';
			echo '<td>' . esc_html( self::csv_cell( $p['title'] ) ) . '</td>'; // #9: guard κι εδώ.
			echo '<td>' . (int) $p['qty'] . '</td>';
			echo '<td>' . (int) $p['qty_full'] . '</td>';
			echo '<td>' . (int) $p['qty_disc'] . '</td>';
			echo '<td>' . (int) $p['qty_free'] . '</td>';
			echo '<td>' . esc_html( $cur( $p['gross'] ) ) . '</td>';
			echo '<td>' . esc_html( $cur( $p['vat'] ) ) . '</td>';
			echo '<td>' . esc_html( $cur( $p['net'] ) ) . '</td>';
			echo '</tr>';
		}

		$t = $report['totals'];
		echo '<tr><th>' . esc_html__( 'ΣΥΝΟΛΑ', 'revenue-splitter' ) . '</th><th colspan="4"></th><th>' . esc_html( $cur( $t['gross'] ) ) . '</th><th>' . esc_html( $cur( $t['vat'] ) ) . '</th><th>' . esc_html( $cur( $t['net'] ) ) . '</th></tr>';
		echo '</table>';
		echo '</body></html>';
		exit;
	}

	/**
	 * v1.3.1 FIX (#18): το HTML export πλέον (α) χρησιμοποιεί gettext
	 * strings με text domain (όχι hardcoded raw), (β) βάζει σωστό
	 * <html lang> από τη γλώσσα χρήστη (RS_Lang).
	 */
	private static function stream_html( string $fname, array $per, array $report ): void {

		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $fname . '.html"' );

		$lang = RS_Lang::get_lang();
		$cur  = self::currency_fmt();
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $lang ); ?>">
<head>
	<meta charset="UTF-8" />
	<title>Revenue Splitter — <?php echo esc_html( $per['start'] ); ?> → <?php echo esc_html( $per['end'] ); ?></title>
	<style>
		body { font-family: system-ui, sans-serif; color: #1d2327; margin: 32px; }
		table { border-collapse: collapse; margin: 16px 0 32px; }
		th, td { border: 1px solid #c3c4c7; padding: 6px 12px; text-align: left; }
		th { background: #f0f0f1; }
		td.num { text-align: right; }
		h1 { font-size: 1.4em; }
		h2 { font-size: 1.1em; margin-top: 32px; }
	</style>
</head>
<body>
	<h1>Revenue Splitter</h1>
	<p>
		<strong><?php echo esc_html( $per['label'] ); ?>:</strong>
		<?php echo esc_html( $per['start'] ); ?> → <?php echo esc_html( $per['end'] ); ?>
		( <?php
		printf(
			/* translators: %s: πλήθος παραγγελιών */
			esc_html__( '%s παραγγελίες', 'revenue-splitter' ),
			esc_html( number_format_i18n( (int) $report['order_count'] ) )
		);
		?> )
	</p>

	<table>
		<thead>
			<tr>
				<th><?php esc_html_e( 'Προϊόν', 'revenue-splitter' ); ?></th>
				<th class="num"><?php esc_html_e( 'Τεμ.', 'revenue-splitter' ); ?></th>
				<th class="num"><?php esc_html_e( 'Πλήρης', 'revenue-splitter' ); ?></th>
				<th class="num"><?php esc_html_e( 'Έκπτωση', 'revenue-splitter' ); ?></th>
				<th class="num"><?php esc_html_e( 'Δωρεάν', 'revenue-splitter' ); ?></th>
				<th class="num"><?php esc_html_e( 'Μικτό', 'revenue-splitter' ); ?></th>
				<th class="num"><?php esc_html_e( 'ΦΠΑ', 'revenue-splitter' ); ?></th>
				<th class="num"><?php esc_html_e( 'Καθαρό', 'revenue-splitter' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $report['products'] as $p ) : ?>
			<tr>
				<td><?php echo esc_html( $p['title'] ); ?></td>
				<td class="num"><?php echo esc_html( number_format_i18n( (int) $p['qty'] ) ); ?></td>
				<td class="num"><?php echo esc_html( number_format_i18n( (int) $p['qty_full'] ) ); ?></td>
				<td class="num"><?php echo esc_html( number_format_i18n( (int) $p['qty_disc'] ) ); ?></td>
				<td class="num"><?php echo esc_html( number_format_i18n( (int) $p['qty_free'] ) ); ?></td>
				<td class="num"><?php echo esc_html( $cur( $p['gross'] ) ); ?></td>
				<td class="num"><?php echo esc_html( $cur( $p['vat'] ) ); ?></td>
				<td class="num"><?php echo esc_html( $cur( $p['net'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
		<tfoot>
			<tr>
				<th><?php esc_html_e( 'ΣΥΝΟΛΑ', 'revenue-splitter' ); ?></th>
				<th colspan="4"></th>
				<th class="num"><?php echo esc_html( $cur( $report['totals']['gross'] ) ); ?></th>
				<th class="num"><?php echo esc_html( $cur( $report['totals']['vat'] ) ); ?></th>
				<th class="num"><?php echo esc_html( $cur( $report['totals']['net'] ) ); ?></th>
			</tr>
		</tfoot>
	</table>

	<h2><?php esc_html_e( 'Δικαιούχοι', 'revenue-splitter' ); ?></h2>
	<table>
		<thead>
			<tr>
				<th><?php esc_html_e( 'Δικαιούχος', 'revenue-splitter' ); ?></th>
				<th class="num"><?php esc_html_e( 'Ποσό (περιόδου)', 'revenue-splitter' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $report['beneficiaries'] as $b ) : ?>
			<tr>
				<td><?php echo esc_html( $b['name'] ); ?></td>
				<td class="num"><?php echo esc_html( $cur( $b['amount'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<footer>
		<p>Made with &lt;3 by <a href="https://koulaxizis.gr">Christos Koulaxizis</a> ·
		<a href="https://glarolykoi.net">glarolykoi.net</a> ·
		<a href="https://noxpress.tech">noxpress.tech</a></p>
	</footer>
</body>
</html>
		<?php
		exit;
	}

	/* =====================================================================
	 * Shared helpers
	 * =================================================================== */

	/**
	 * v1.3.1 (#16): public πλέον — το RS_Portal (Part 6) καλεί το ΙΔΙΟ
	 * static μέθοδο αντί για δικό του copy-paste stock_of().
	 */
	public static function product_stock( int $product_id ): string {

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return '—'; // Διαγραμμένο/μη φορτώσιμο προϊόν.
		}

		// Variable: άθροισμα stock των variations που το διαχειρίζονται.
		if ( $product->is_type( 'variable' ) ) {
			$total = 0;
			$any   = false;
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child instanceof WC_Product && $child->get_manage_stock() ) {
					$q = $child->get_stock_quantity();
					if ( null !== $q ) {
						$total += (int) $q;
						$any    = true;
					}
				}
			}
			return $any ? (string) $total : '∞';
		}

		if ( ! $product->get_manage_stock() ) {
			return '∞'; // Διαχείριση stock απενεργοποιημένη (ψηφιακά κ.λπ.).
		}

		$q = $product->get_stock_quantity();

		return ( null === $q ) ? '∞' : (string) (int) $q;
	}

	/** Closure μορφοποίησης νομίσματος για admin renders. */
	private static function currency_fmt(): callable {

		$symbol = function_exists( 'get_woocommerce_currency_symbol' )
			? get_woocommerce_currency_symbol()
			: '€';

		return static function ( $amount ) use ( $symbol ) {
			return number_format_i18n( (float) $amount, 2 ) . ' ' . $symbol;
		};
	}

	/* =====================================================================
	 * Dashboard widget (wp-admin home)
	 * =================================================================== */

	public static function render_widget(): void {

		$now = new DateTimeImmutable( 'now', wp_timezone() );

		$report = RS_Reports::run(
			array(
				'date_start' => $now->format( 'Y-m-01' ),
				'date_end'   => $now->format( 'Y-m-d' ),
			)
		);

		$cur = self::currency_fmt();
		?>
				<div class="rs-widget">
			<p>
				<strong><?php esc_html_e( 'Τρέχων μήνας', 'revenue-splitter' ); ?>:</strong>
				<?php
				printf(
					/* translators: %s: πλήθος παραγγελιών */
					esc_html__( '%s παραγγελίες', 'revenue-splitter' ),
					esc_html( number_format_i18n( (int) $report['order_count'] ) )
				);
				?>
				· <?php echo esc_html( $cur( $report['totals']['net'] ) ); ?> <?php esc_html_e( 'καθαρά', 'revenue-splitter' ); ?>
			</p>

			<?php if ( ! empty( $report['beneficiaries'] ) ) : ?>
			<table class="rs-widget-table">
				<?php foreach ( array_slice( $report['beneficiaries'], 0, 5, true ) as $b ) : ?>
				<tr>
					<td><?php echo esc_html( $b['name'] ); ?></td>
					<td class="num"><?php echo esc_html( $cur( $b['amount'] ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</table>
			<?php endif; ?>

			<p style="margin-bottom:0;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_DASH ) ); ?>"><?php esc_html_e( 'Πλήρες dashboard →', 'revenue-splitter' ); ?></a>
			</p>
		</div>
		<?php
	}

	/* =====================================================================
	 * Footer (κοινό για dashboard + ρυθμίσεις)
	 * =================================================================== */

	private static function footer(): void {
		?>
		<p class="rs-footer">
			Made with &lt;3 by
			<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a> ·
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a> ·
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<?php
	}
}
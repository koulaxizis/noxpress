<?php
/**
 * PL_Admin_UI — menu, tabs, forms (Bible §3–§8, §11).
 *
 * Submenu «Κόστη & Απόδοση» of the shared "Noxpress" menu (admin_menu
 * priority 340), slug pl-ledger, capability manage_woocommerce. Tabs:
 *  - overview  month / year, KPI cards, per-product table (sortable,
 *              search), general expenses and the grand total
 *  - entries   add / edit / delete (with undo) product and pooled costs,
 *              filters by product, category, month
 *  - general   the general-expenses ledger
 *  - settings  start date, publisher names, carriers, payment groups,
 *              modules, Printful key, test rows, backup, uninstall
 *  - export    CSV per §5 for a month (entries, summary)
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient pl_aui_msg_{uid}). Downloads: privileged GET.
 * Product search: AJAX from the 3rd character, 30 results (§5).
 */

defined( 'ABSPATH' ) || exit;

final class PL_Admin_UI {

	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'pl-ledger';
	const NONCE       = 'pl_admin';
	const NONCE_FIELD = 'pl_nonce';
	const NONCE_GET   = 'pl_get';
	const MAX_JSON    = 262144;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 340 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_get' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_pl_products', array( __CLASS__, 'ajax_products' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PL_FILE ), array( __CLASS__, 'action_links' ) );
	}

	private static function tabs(): array {
		return array(
			'overview' => __( 'Επισκόπηση', 'production-ledger' ),
			'entries'  => __( 'Καταχωρίσεις', 'production-ledger' ),
			'general'  => __( 'Γενικά έξοδα', 'production-ledger' ),
			'settings' => __( 'Ρυθμίσεις', 'production-ledger' ),
			'export'   => __( 'Εξαγωγή', 'production-ledger' ),
		);
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Κόστη & Απόδοση', 'production-ledger' ),
			__( 'Κόστη & Απόδοση', 'production-ledger' ),
			Production_Ledger::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Κόστη & Απόδοση', 'production-ledger' ) . '</a>' );
		return $links;
	}

	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	private static function q( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing and filters.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? trim( (string) wp_unslash( $_POST[ $key ] ) ) : '';
	}

	private static function current_tab(): string {
		$t = sanitize_key( self::q( 'tab' ) );
		return isset( self::tabs()[ $t ] ) ? $t : 'overview';
	}

	private static function url( string $tab, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'pl-admin', PL_URL . 'assets/admin.css', array(), PL_VERSION );
		wp_enqueue_script( 'pl-admin', PL_URL . 'assets/admin.js', array(), PL_VERSION, true );
		wp_localize_script(
			'pl-admin',
			'plAdmin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'pl_products' ),
			)
		);
	}

	/* =====================================================================
	 * Formatting helpers
	 * =================================================================== */

	private static function money( float $v ): string {
		return number_format_i18n( $v, 2 );
	}

	private static function cell( float $v, bool $sign = false ): string {
		$cls = $sign ? ( $v < 0 ? ' pl-neg' : ( $v > 0 ? ' pl-pos' : '' ) ) : '';
		return '<td class="pl-num' . $cls . '" data-v="' . esc_attr( (string) $v ) . '">' . esc_html( self::money( $v ) ) . '</td>';
	}

	private static function badge( string $text, string $kind ): string {
		return '<span class="pl-badge pl-badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function form_head( string $action ): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		echo '<input type="hidden" name="pl_action" value="' . esc_attr( $action ) . '" />';
	}

	private static function button_form( string $action, array $fields, string $label, string $class = 'button', string $confirm = '' ): void {
		echo '<form method="post" class="pl-inline-form"' . ( '' !== $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		self::form_head( $action );
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '" />';
		}
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private static function product_label( int $pid ): string {
		if ( $pid <= 0 ) {
			return '';
		}
		$t = get_the_title( $pid );
		return $pid . ' — ' . html_entity_decode( '' !== (string) $t ? (string) $t : '#' . $pid, ENT_QUOTES, 'UTF-8' );
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Production_Ledger::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'production-ledger' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap pl-wrap">
			<h1><?php esc_html_e( 'Κόστη & Απόδοση', 'production-ledger' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Τι έφερε κάθε προϊόν αφού αφαιρεθούν όσα κόστισε, και τι μένει μετά τα γενικά έξοδα. Όλα τα ποσά είναι καθαρά, χωρίς ΦΠΑ.', 'production-ledger' ); ?></p>
			<?php
			self::print_notices( self::take_msgs() );
			self::print_undo();
			if ( Production_Ledger::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το PL_DISABLE είναι ενεργό στο wp-config.php: τα modules δεν τρέχουν και η ροή για το orOS απαντά 403.', 'production-ledger' ) . '</p></div>';
			}
			?>
			<nav class="nav-tab-wrapper pl-tabs">
				<?php
				foreach ( self::tabs() as $slug => $label ) {
					printf(
						'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
						esc_url( self::url( (string) $slug ) ),
						(string) $slug === $tab ? ' nav-tab-active' : '',
						esc_html( $label )
					);
				}
				?>
			</nav>
			<?php
			switch ( $tab ) {
				case 'entries':
					self::render_entries( 'costs' );
					break;
				case 'general':
					self::render_entries( 'general' );
					break;
				case 'settings':
					self::render_settings();
					break;
				case 'export':
					self::render_export();
					break;
				default:
					self::render_overview();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Overview
	 * ------------------------------------------------------------------- */

	/** The period of the overview: [ from, to, kind, value ]. */
	private static function period(): array {
		$kind = 'year' === self::q( 'p' ) ? 'year' : 'month';
		if ( 'year' === $kind ) {
			$y = (int) self::q( 'y' );
			$y = $y >= 2000 && $y <= 2100 ? $y : (int) wp_date( 'Y' );
			return array( $y . '-01-01', $y . '-12-31', 'year', (string) $y );
		}
		$m = self::q( 'm' );
		$m = preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $m ) ? $m : wp_date( 'Y-m' );
		return array( $m . '-01', gmdate( 'Y-m-t', strtotime( $m . '-01' ) ), 'month', $m );
	}

	private static function render_overview(): void {
		list( $from, $to, $kind, $val ) = self::period();
		$s     = PL_Settings::get();
		$start = $s['start'];
		?>
		<form method="get" class="pl-period">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="tab" value="overview" />
			<select name="p" class="pl-period-kind">
				<option value="month"<?php selected( $kind, 'month' ); ?>><?php esc_html_e( 'Μήνας', 'production-ledger' ); ?></option>
				<option value="year"<?php selected( $kind, 'year' ); ?>><?php esc_html_e( 'Έτος', 'production-ledger' ); ?></option>
			</select>
			<input type="month" name="m" value="<?php echo esc_attr( 'month' === $kind ? $val : wp_date( 'Y-m' ) ); ?>" class="pl-when-month" />
			<input type="number" name="y" min="2000" max="2100" value="<?php echo esc_attr( 'year' === $kind ? $val : wp_date( 'Y' ) ); ?>" class="pl-when-year small-text" />
			<button type="submit" class="button"><?php esc_html_e( 'Εμφάνιση', 'production-ledger' ); ?></button>
		</form>
		<?php
		if ( $to < $start ) {
			/* translators: %s: start date */
			echo '<div class="notice notice-info inline"><p>' . esc_html( sprintf( __( 'Η καταγραφή ξεκινά στις %s: η περίοδος είναι πριν από αυτή.', 'production-ledger' ), wp_date( get_option( 'date_format' ), strtotime( $start ) ) ) ) . '</p></div>';
			return;
		}
		if ( empty( $s['house'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Δεν έχεις ορίσει ποιοι δικαιούχοι του Revenue Splitter είναι ο ίδιος ο εκδότης: όλα τα μερίδια μετριούνται ως αμοιβές δημιουργών.', 'production-ledger' ) . ' <a href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'production-ledger' ) . '</a></p></div>';
		}
		$rep = PL_Report::get( $from, $to );
		if ( is_wp_error( $rep ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $rep->get_error_message() ) . '</p></div>';
			return;
		}
		if ( ! $rep['rs']['available'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Το Revenue Splitter δεν είναι ενεργό: οι αμοιβές δημιουργών μετρούν μόνο από τις καταχωρίσεις «Δημιουργοί».', 'production-ledger' ) . '</p></div>';
		}
		$t = $rep['totals'];
		?>
		<div class="pl-kpis">
			<div class="pl-kpi"><span><?php esc_html_e( 'Έσοδα (καθαρά)', 'production-ledger' ); ?></span><strong><?php echo esc_html( self::money( $t['revenue'] + $t['shipping_charged'] ) ); ?></strong><em><?php echo esc_html( sprintf( /* translators: 1: retail, 2: trade */ __( 'λιανική %1$s · χονδρική %2$s', 'production-ledger' ), self::money( $t['retail'] ), self::money( $t['trade'] ) ) ); ?></em></div>
			<div class="pl-kpi"><span><?php esc_html_e( 'Κόστη προϊόντων', 'production-ledger' ); ?></span><strong><?php echo esc_html( self::money( $t['costs_total'] ) ); ?></strong><em><?php echo esc_html( sprintf( /* translators: %s: amount */ __( 'δημιουργοί %s', 'production-ledger' ), self::money( $t['creators_rs'] ) ) ); ?></em></div>
			<div class="pl-kpi"><span><?php esc_html_e( 'Καθαρό προϊόντων', 'production-ledger' ); ?></span><strong class="<?php echo $t['net'] < 0 ? 'pl-neg' : 'pl-pos'; ?>"><?php echo esc_html( self::money( $t['net'] ) ); ?></strong><em><?php echo esc_html( sprintf( /* translators: %d: orders */ _n( '%d παραγγελία', '%d παραγγελίες', $rep['orders'], 'production-ledger' ), $rep['orders'] ) ); ?></em></div>
			<div class="pl-kpi"><span><?php esc_html_e( 'Γενικά έξοδα', 'production-ledger' ); ?></span><strong><?php echo esc_html( self::money( $rep['general']['total'] ) ); ?></strong><em>&nbsp;</em></div>
			<div class="pl-kpi pl-kpi--main"><span><?php esc_html_e( 'Τελικό αποτέλεσμα', 'production-ledger' ); ?></span><strong class="<?php echo $rep['grand_total'] < 0 ? 'pl-neg' : 'pl-pos'; ?>"><?php echo esc_html( self::money( $rep['grand_total'] ) ); ?></strong><em><?php echo esc_html( $rep['currency'] ); ?></em></div>
		</div>

		<h2 class="pl-h2"><?php esc_html_e( 'Ανά προϊόν', 'production-ledger' ); ?></h2>
		<p><input type="search" class="pl-search regular-text" placeholder="<?php esc_attr_e( 'Αναζήτηση προϊόντος…', 'production-ledger' ); ?>" data-table="pl-products" /></p>
		<?php if ( ! $rep['products'] ) : ?>
			<p class="pl-hint"><?php esc_html_e( 'Καμία πώληση ή καταχώριση σε αυτή την περίοδο.', 'production-ledger' ); ?></p>
		<?php else : ?>
		<div class="pl-scroll">
		<table class="widefat striped pl-table pl-sortable" id="pl-products">
			<thead><tr>
				<th data-sort="text"><?php esc_html_e( 'Προϊόν', 'production-ledger' ); ?></th>
				<th data-sort="num"><?php esc_html_e( 'Τεμ.', 'production-ledger' ); ?></th>
				<th data-sort="num"><?php esc_html_e( 'Λιανική', 'production-ledger' ); ?></th>
				<th data-sort="num"><?php esc_html_e( 'Χονδρική', 'production-ledger' ); ?></th>
				<th data-sort="num"><?php esc_html_e( 'Εκτός site', 'production-ledger' ); ?></th>
				<th data-sort="num"><?php esc_html_e( 'Δημιουργοί', 'production-ledger' ); ?></th>
				<th data-sort="num"><?php esc_html_e( 'Παραγωγή', 'production-ledger' ); ?></th>
				<th data-sort="num" title="<?php esc_attr_e( 'Τι πλήρωσαν οι πελάτες για αποστολή μείον ό,τι κόστισε στον μεταφορέα', 'production-ledger' ); ?>"><?php esc_html_e( 'Αποστολή ±', 'production-ledger' ); ?></th>
				<th data-sort="num"><?php esc_html_e( 'Αναλώσιμα & προμήθειες', 'production-ledger' ); ?></th>
				<th data-sort="num"><?php esc_html_e( 'Καθαρό', 'production-ledger' ); ?></th>
				<th data-sort="num" title="<?php esc_attr_e( 'Τεμάχια που μένουν να πουληθούν για να βγει το κόστος από την αρχή της καταγραφής', 'production-ledger' ); ?>"><?php esc_html_e( 'Νεκρό σημείο', 'production-ledger' ); ?></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( $rep['products'] as $p ) {
				$prod = array_sum( $p['costs'] );
				$tip  = array();
				foreach ( $p['costs'] as $c => $v ) {
					if ( $v > 0 ) {
						$tip[] = PL_Entries::label( (string) $c ) . ': ' . self::money( $v );
					}
				}
				echo '<tr>';
				echo '<td data-v="' . esc_attr( mb_strtolower( $p['title'] ) ) . '"><a href="' . esc_url( self::url( 'entries', array( 'f_product' => $p['product_id'] ) ) ) . '">' . esc_html( $p['title'] ) . '</a> <span class="pl-muted">#' . (int) $p['product_id'] . '</span></td>';
				echo '<td class="pl-num" data-v="' . (int) $p['units'] . '">' . (int) $p['units'] . '</td>';
				echo self::cell( $p['retail'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in cell().
				echo self::cell( $p['trade'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::cell( $p['offsite'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::cell( $p['creators_rs'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td class="pl-num" data-v="' . esc_attr( (string) $prod ) . '" title="' . esc_attr( implode( "\n", $tip ) ) . '">' . esc_html( self::money( $prod ) ) . '</td>';
				echo self::cell( $p['shipping_charged'] - $p['shipping_cost'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::cell( $p['consumables'] + $p['payment_fees'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::cell( $p['net'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$be = null === $p['break_even'] ? '' : (string) $p['break_even'];
				echo '<td class="pl-num" data-v="' . esc_attr( '' === $be ? '-1' : $be ) . '">' . esc_html( '' === $be ? '—' : $be ) . '</td>';
				echo '</tr>';
			}
			?>
			</tbody>
		</table>
		</div>
		<?php endif; ?>

		<h2 class="pl-h2"><?php esc_html_e( 'Γενικά έξοδα', 'production-ledger' ); ?></h2>
		<table class="widefat pl-table pl-narrow">
			<tbody>
			<tr><th><?php esc_html_e( 'Καθαρό προϊόντων', 'production-ledger' ); ?></th><?php echo self::cell( $t['net'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></tr>
			<?php
			foreach ( $rep['general']['by_category'] as $c => $v ) {
				echo '<tr><td>' . esc_html( PL_Entries::label( (string) $c ) ) . '</td>' . self::cell( -$v, true ) . '</tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			foreach ( $rep['general']['unallocated'] as $k => $v ) {
				echo '<tr><td>' . esc_html( __( 'Χωρίς παραγγελίες για κατανομή', 'production-ledger' ) . ': ' . $k ) . '</td>' . self::cell( -$v, true ) . '</tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
			<tr class="pl-total"><th><?php esc_html_e( 'Τελικό αποτέλεσμα', 'production-ledger' ); ?></th><?php echo self::cell( $rep['grand_total'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></tr>
			</tbody>
		</table>
		<p class="pl-hint">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: VAT on sales, 2: VAT on purchases */
					__( 'ΦΠΑ πωλήσεων %1$s · ΦΠΑ αγορών (προϊόντα) %2$s. Οι αμοιβές δημιουργών είναι του Revenue Splitter, για τις ίδιες παραγγελίες.', 'production-ledger' ),
					self::money( $t['vat_out'] ),
					self::money( $t['vat_in'] )
				)
			);
			?>
		</p>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Entries (costs) and general expenses
	 * ------------------------------------------------------------------- */

	private static function render_entries( string $scope ): void {
		$tab  = 'general' === $scope ? 'general' : 'entries';
		$edit = (int) self::q( 'edit' );
		$row  = $edit > 0 ? PL_Entries::get( $edit ) : null;
		if ( $row && null !== $row['deleted_at'] ) {
			$row = null;
		}
		$cats  = PL_Entries::categories();
		$pools = PL_Entries::pools();
		$start = PL_Settings::start();

		// Form.
		echo '<h2 class="pl-h2">' . esc_html( $row ? __( 'Επεξεργασία καταχώρισης', 'production-ledger' ) : ( 'general' === $scope ? __( 'Νέο γενικό έξοδο', 'production-ledger' ) : __( 'Νέα καταχώριση', 'production-ledger' ) ) ) . '</h2>';
		$mode = $row ? ( $row['product_id'] > 0 ? 'product' : ( '' !== $row['pool'] ? 'pool' : 'general' ) ) : ( 'general' === $scope ? 'general' : 'product' );
		?>
		<form method="post" class="pl-zone pl-entry-form">
			<?php self::form_head( 'save_entry' ); ?>
			<input type="hidden" name="pl_id" value="<?php echo esc_attr( $row ? (string) $row['id'] : '0' ); ?>" />
			<input type="hidden" name="pl_tab" value="<?php echo esc_attr( $tab ); ?>" />
			<?php if ( 'general' === $mode ) : ?>
				<input type="hidden" name="pl_mode" value="general" />
			<?php else : ?>
				<p class="pl-mode">
					<label class="pl-check"><input type="radio" name="pl_mode" value="product"<?php checked( $mode, 'product' ); ?> /> <?php esc_html_e( 'Ένα προϊόν', 'production-ledger' ); ?></label>
					<label class="pl-check"><input type="radio" name="pl_mode" value="pool"<?php checked( $mode, 'pool' ); ?> /> <?php esc_html_e( 'Μοιρασμένο στις παραγγελίες του μήνα (μεταφορικά, αναλώσιμα, προμήθειες πληρωμών)', 'production-ledger' ); ?></label>
				</p>
			<?php endif; ?>
			<div class="pl-grid">
				<label><span><?php esc_html_e( 'Ημερομηνία παραστατικού', 'production-ledger' ); ?></span>
					<input type="date" name="pl_date" required min="<?php echo esc_attr( $start ); ?>" value="<?php echo esc_attr( $row ? $row['entry_date'] : max( $start, wp_date( 'Y-m-d' ) ) ); ?>" /></label>
				<?php if ( 'general' !== $mode ) : ?>
				<label class="pl-only-product"><span><?php esc_html_e( 'Προϊόν', 'production-ledger' ); ?></span>
					<input type="text" name="pl_product" class="pl-product-search" list="pl-product-list" autocomplete="off" placeholder="<?php esc_attr_e( 'Γράψε 3 γράμματα ή το ID', 'production-ledger' ); ?>" value="<?php echo esc_attr( $row ? self::product_label( $row['product_id'] ) : '' ); ?>" />
					<datalist id="pl-product-list"></datalist></label>
				<?php endif; ?>
				<label><span><?php esc_html_e( 'Κατηγορία', 'production-ledger' ); ?></span>
					<select name="pl_category" class="pl-category">
						<?php
						foreach ( $cats as $slug => $c ) {
							if ( 'general' === $mode && 'offsite' === $slug ) {
								continue;
							}
							if ( 'general' !== $mode && 'general' === $slug ) {
								continue;
							}
							$cur = $row ? $row['category'] : ( 'general' === $mode ? 'general' : 'printing' );
							echo '<option value="' . esc_attr( $slug ) . '"' . selected( $cur, $slug, false ) . ' data-pool="' . ( isset( $pools[ $slug ] ) ? '1' : '0' ) . '">' . esc_html( $c[0] ) . '</option>';
						}
						?>
					</select></label>
				<?php if ( 'general' !== $mode ) : ?>
				<label class="pl-only-pool"><span><?php esc_html_e( 'Κατανομή σε', 'production-ledger' ); ?></span>
					<select name="pl_pool">
						<?php
						foreach ( $pools as $cat => $list ) {
							foreach ( $list as $k => $label ) {
								echo '<option value="' . esc_attr( (string) $k ) . '" data-cat="' . esc_attr( $cat ) . '"' . selected( $row ? $row['category'] . ':' . $row['pool'] : '', $cat . ':' . $k, false ) . '>' . esc_html( $label ) . '</option>';
							}
						}
						?>
					</select></label>
				<?php endif; ?>
				<label><span><?php esc_html_e( 'Προμηθευτής', 'production-ledger' ); ?></span>
					<input type="text" name="pl_supplier" maxlength="120" value="<?php echo esc_attr( $row ? $row['supplier'] : '' ); ?>" /></label>
				<label><span><?php esc_html_e( 'Αρ. παραστατικού', 'production-ledger' ); ?></span>
					<input type="text" name="pl_invoice" maxlength="60" value="<?php echo esc_attr( $row ? $row['invoice_no'] : '' ); ?>" /></label>
				<label class="pl-wide"><span><?php esc_html_e( 'Περιγραφή', 'production-ledger' ); ?></span>
					<input type="text" name="pl_description" maxlength="255" value="<?php echo esc_attr( $row ? $row['description'] : '' ); ?>" /></label>
				<label><span><?php esc_html_e( 'Καθαρό ποσό (χωρίς ΦΠΑ)', 'production-ledger' ); ?></span>
					<input type="text" name="pl_net" inputmode="decimal" required class="pl-net" value="<?php echo esc_attr( $row ? number_format( $row['net_amount'], 2, ',', '' ) : '' ); ?>" /></label>
				<label><span><?php esc_html_e( 'Συντελεστής ΦΠΑ %', 'production-ledger' ); ?></span>
					<input type="text" name="pl_rate" inputmode="decimal" class="pl-rate" value="<?php echo esc_attr( $row ? number_format( $row['vat_rate'], 2, ',', '' ) : '24' ); ?>" /></label>
				<label><span><?php esc_html_e( 'ΦΠΑ (κενό = από τον συντελεστή)', 'production-ledger' ); ?></span>
					<input type="text" name="pl_vat" inputmode="decimal" class="pl-vat" value="<?php echo esc_attr( $row ? number_format( $row['vat_amount'], 2, ',', '' ) : '' ); ?>" /></label>
				<label><span><?php esc_html_e( 'Πλήθος (π.χ. δέματα)', 'production-ledger' ); ?></span>
					<input type="number" name="pl_qty" min="0" value="<?php echo esc_attr( $row && null !== $row['qty_basis'] ? (string) $row['qty_basis'] : '' ); ?>" /></label>
				<label class="pl-wide"><span><?php esc_html_e( 'Σύνδεσμος παραστατικού', 'production-ledger' ); ?></span>
					<input type="url" name="pl_url" maxlength="500" value="<?php echo esc_attr( $row ? $row['attachment_url'] : '' ); ?>" /></label>
			</div>
			<p>
				<label class="pl-check"><input type="checkbox" name="pl_test" value="1"<?php checked( $row && $row['is_test'] ); ?> /> <?php esc_html_e( 'Δοκιμαστική (δεν μετρά στην αναφορά ούτε πάει στο orOS)', 'production-ledger' ); ?></label>
			</p>
			<p>
				<button type="submit" class="button button-primary"><?php echo esc_html( $row ? __( 'Αποθήκευση', 'production-ledger' ) : __( 'Προσθήκη', 'production-ledger' ) ); ?></button>
				<?php if ( $row ) : ?>
					<a class="button" href="<?php echo esc_url( self::url( $tab ) ); ?>"><?php esc_html_e( 'Ακύρωση', 'production-ledger' ); ?></a>
				<?php endif; ?>
			</p>
		</form>
		<?php

		// Filters.
		$f_prod = (int) self::q( 'f_product' );
		$f_cat  = sanitize_key( self::q( 'f_cat' ) );
		$f_mon  = self::q( 'f_month' );
		$f_mon  = preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $f_mon ) ? $f_mon : '';
		$f_del  = '1' === self::q( 'f_deleted' );
		$f_srch = self::q( 'f_search' );
		$paged  = max( 1, (int) self::q( 'paged' ) );
		$per    = 50;
		?>
		<h2 class="pl-h2"><?php echo esc_html( 'general' === $scope ? __( 'Γενικά έξοδα', 'production-ledger' ) : __( 'Καταχωρίσεις', 'production-ledger' ) ); ?></h2>
		<form method="get" class="pl-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
			<input type="month" name="f_month" value="<?php echo esc_attr( $f_mon ); ?>" />
			<select name="f_cat">
				<option value=""><?php esc_html_e( 'Όλες οι κατηγορίες', 'production-ledger' ); ?></option>
				<?php
				foreach ( $cats as $slug => $c ) {
					echo '<option value="' . esc_attr( $slug ) . '"' . selected( $f_cat, $slug, false ) . '>' . esc_html( $c[0] ) . '</option>';
				}
				?>
			</select>
			<?php if ( 'general' !== $scope ) : ?>
				<input type="number" name="f_product" min="0" placeholder="<?php esc_attr_e( 'ID προϊόντος', 'production-ledger' ); ?>" value="<?php echo esc_attr( $f_prod > 0 ? (string) $f_prod : '' ); ?>" class="small-text" />
			<?php endif; ?>
			<input type="search" name="f_search" value="<?php echo esc_attr( $f_srch ); ?>" placeholder="<?php esc_attr_e( 'Προμηθευτής, περιγραφή, αρ. παραστατικού', 'production-ledger' ); ?>" />
			<label class="pl-check"><input type="checkbox" name="f_deleted" value="1"<?php checked( $f_del ); ?> /> <?php esc_html_e( 'Διαγραμμένες', 'production-ledger' ); ?></label>
			<button type="submit" class="button"><?php esc_html_e( 'Φίλτρο', 'production-ledger' ); ?></button>
		</form>
		<?php
		$f = array(
			'scope'    => 'general' === $scope ? 'general' : 'costs',
			'category' => $f_cat,
			'deleted'  => $f_del,
			'search'   => $f_srch,
			'limit'    => $per,
			'offset'   => ( $paged - 1 ) * $per,
		);
		if ( '' !== $f_mon ) {
			$f['from'] = $f_mon . '-01';
			$f['to']   = gmdate( 'Y-m-t', strtotime( $f_mon . '-01' ) );
		}
		if ( $f_prod > 0 ) {
			$f['product_id'] = $f_prod;
		}
		$q = PL_Entries::query( $f );
		if ( ! $q['rows'] ) {
			echo '<p class="pl-hint">' . esc_html__( 'Καμία καταχώριση.', 'production-ledger' ) . '</p>';
			return;
		}
		$sum_net = 0.0;
		$sum_vat = 0.0;
		?>
		<div class="pl-scroll">
		<table class="widefat striped pl-table">
			<thead><tr>
				<th><?php esc_html_e( 'Ημερομηνία', 'production-ledger' ); ?></th>
				<?php if ( 'general' !== $scope ) : ?><th><?php esc_html_e( 'Προϊόν / κατανομή', 'production-ledger' ); ?></th><?php endif; ?>
				<th><?php esc_html_e( 'Κατηγορία', 'production-ledger' ); ?></th>
				<th><?php esc_html_e( 'Προμηθευτής', 'production-ledger' ); ?></th>
				<th><?php esc_html_e( 'Αρ. παραστατικού', 'production-ledger' ); ?></th>
				<th><?php esc_html_e( 'Περιγραφή', 'production-ledger' ); ?></th>
				<th class="pl-num"><?php esc_html_e( 'Καθαρό', 'production-ledger' ); ?></th>
				<th class="pl-num"><?php esc_html_e( 'ΦΠΑ', 'production-ledger' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( $q['rows'] as $e ) {
				$sum_net += $e['net_amount'];
				$sum_vat += $e['vat_amount'];
				echo '<tr>';
				echo '<td>' . esc_html( wp_date( get_option( 'date_format' ), strtotime( $e['entry_date'] . ' 12:00' ) ) ) . '</td>';
				if ( 'general' !== $scope ) {
					if ( $e['product_id'] > 0 ) {
						echo '<td>' . esc_html( self::product_label( $e['product_id'] ) ) . '</td>';
					} else {
						$pl = isset( $pools[ $e['category'] ][ $e['pool'] ] ) ? $pools[ $e['category'] ][ $e['pool'] ] : $e['pool'];
						echo '<td>' . self::badge( __( 'μοιρασμένο', 'production-ledger' ), 'info' ) . ' ' . esc_html( $pl ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
					}
				}
				echo '<td>' . esc_html( PL_Entries::label( $e['category'] ) );
				if ( $e['is_test'] ) {
					echo ' ' . self::badge( __( 'δοκιμή', 'production-ledger' ), 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				if ( 'auto' === $e['source'] ) {
					echo ' ' . self::badge( __( 'αυτόματη', 'production-ledger' ), 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo '</td>';
				echo '<td>' . esc_html( $e['supplier'] ) . '</td>';
				echo '<td>' . ( '' !== $e['attachment_url'] ? '<a href="' . esc_url( $e['attachment_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( '' !== $e['invoice_no'] ? $e['invoice_no'] : __( 'άνοιγμα', 'production-ledger' ) ) . '</a>' : esc_html( $e['invoice_no'] ) ) . '</td>';
				echo '<td>' . esc_html( $e['description'] ) . '</td>';
				echo self::cell( $e['net_amount'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo self::cell( $e['vat_amount'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td class="pl-actions">';
				if ( null === $e['deleted_at'] ) {
					echo '<a class="button button-small" href="' . esc_url( self::url( $tab, array( 'edit' => $e['id'] ) ) ) . '">' . esc_html__( 'Αλλαγή', 'production-ledger' ) . '</a> ';
					self::button_form( 'delete_entry', array( 'pl_id' => $e['id'], 'pl_tab' => $tab ), __( 'Διαγραφή', 'production-ledger' ), 'button button-small' );
				} else {
					self::button_form( 'restore_entry', array( 'pl_id' => $e['id'], 'pl_tab' => $tab ), __( 'Επαναφορά', 'production-ledger' ), 'button button-small' );
				}
				echo '</td></tr>';
			}
			?>
			</tbody>
			<tfoot><tr>
				<th colspan="<?php echo 'general' === $scope ? 5 : 6; ?>"><?php esc_html_e( 'Σύνολο σελίδας', 'production-ledger' ); ?></th>
				<?php echo self::cell( $sum_net ) . self::cell( $sum_vat ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<th></th>
			</tr></tfoot>
		</table>
		</div>
		<?php
		$pages = (int) ceil( $q['total'] / $per );
		if ( $pages > 1 ) {
			echo '<p class="pl-pages">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				$args = array_filter(
					array(
						'f_month'   => $f_mon,
						'f_cat'     => $f_cat,
						'f_product' => $f_prod > 0 ? $f_prod : '',
						'f_search'  => $f_srch,
						'f_deleted' => $f_del ? '1' : '',
						'paged'     => $i,
					)
				);
				echo $i === $paged ? '<strong>' . (int) $i . '</strong> ' : '<a href="' . esc_url( self::url( $tab, $args ) ) . '">' . (int) $i . '</a> ';
			}
			echo '</p>';
		}
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	private static function render_settings(): void {
		$s     = PL_Settings::get();
		$names = array();
		if ( class_exists( 'RS_Beneficiaries' ) && is_callable( array( 'RS_Beneficiaries', 'collect_names' ) ) ) {
			try {
				$names = (array) RS_Beneficiaries::collect_names();
			} catch ( \Throwable $e ) {
				$names = array();
			}
		}
		$names = array_values( array_unique( array_merge( array_map( 'strval', $names ), $s['house'] ) ) );
		$pfk   = PL_Settings::pf_key_state();
		?>
		<form method="post" class="pl-settings">
			<?php self::form_head( 'save_settings' ); ?>

			<h2 class="pl-h2"><?php esc_html_e( 'Καταγραφή', 'production-ledger' ); ?></h2>
			<div class="pl-zone">
				<p><label><?php esc_html_e( 'Η καταγραφή ξεκινά στις', 'production-ledger' ); ?>
					<input type="date" name="pl_start" value="<?php echo esc_attr( $s['start'] ); ?>" required /></label></p>
				<p class="pl-hint"><?php esc_html_e( 'Τίποτα πριν από αυτή την ημέρα δεν μετρά στην αναφορά και καμία καταχώριση δεν μπορεί να έχει παλαιότερη ημερομηνία.', 'production-ledger' ); ?></p>
			</div>

			<h2 class="pl-h2"><?php esc_html_e( 'Ο εκδότης στο Revenue Splitter', 'production-ledger' ); ?></h2>
			<div class="pl-zone">
				<p class="pl-hint"><?php esc_html_e( 'Τσέκαρε τα ονόματα δικαιούχων που είσαι εσύ (ο εκδότης). Το μερίδιό τους μένει στο αποτέλεσμα· όλων των άλλων μετρά ως αμοιβή δημιουργού.', 'production-ledger' ); ?></p>
				<?php
				if ( ! $names ) {
					echo '<p class="pl-hint">' . esc_html__( 'Δεν βρέθηκαν δικαιούχοι (το Revenue Splitter δεν είναι ενεργό).', 'production-ledger' ) . '</p>';
				}
				foreach ( $names as $n ) {
					echo '<label class="pl-check pl-block"><input type="checkbox" name="pl_house[]" value="' . esc_attr( $n ) . '"' . checked( in_array( $n, $s['house'], true ), true, false ) . ' /> ' . esc_html( $n ) . '</label>';
				}
				?>
			</div>

			<h2 class="pl-h2"><?php esc_html_e( 'Μεταφορείς', 'production-ledger' ); ?></h2>
			<div class="pl-zone">
				<p class="pl-hint"><?php esc_html_e( 'Μία γραμμή ανά μεταφορέα: κλειδί | όνομα | λέξεις. Μια αποστολή ανήκει στον μεταφορέα όταν το id της μεθόδου ή το όνομά της περιέχει μία από τις λέξεις. Το τιμολόγιο του μήνα μπαίνει ως «μοιρασμένο» και πέφτει ίσα σε κάθε δέμα του.', 'production-ledger' ); ?></p>
				<textarea name="pl_carriers" rows="4" class="large-text code"><?php echo esc_textarea( PL_Settings::to_lines( $s['carriers'], 'match' ) ); ?></textarea>
			</div>

			<h2 class="pl-h2"><?php esc_html_e( 'Ομάδες πληρωμών', 'production-ledger' ); ?></h2>
			<div class="pl-zone">
				<p class="pl-hint"><?php esc_html_e( 'Μία γραμμή ανά πάροχο: κλειδί | όνομα | ids μεθόδων πληρωμής. Το τιμολόγιο προμηθειών του μήνα μοιράζεται στις παραγγελίες που πληρώθηκαν μέσω του παρόχου, ανάλογα με την αξία τους.', 'production-ledger' ); ?></p>
				<textarea name="pl_pay" rows="4" class="large-text code"><?php echo esc_textarea( PL_Settings::to_lines( $s['pay'], 'methods' ) ); ?></textarea>
				<p><label class="pl-check"><input type="checkbox" name="pl_spread" value="1"<?php checked( $s['spread_consumables'] ); ?> /> <?php esc_html_e( 'Τα αναλώσιμα μοιράζονται στις αποστολές του μήνα (αλλιώς μετρούν ως γενικό έξοδο)', 'production-ledger' ); ?></label></p>
			</div>

			<h2 class="pl-h2"><?php esc_html_e( 'Modules', 'production-ledger' ); ?></h2>
			<div class="pl-zone">
				<p><label class="pl-check"><input type="checkbox" name="pl_mod_oros" value="1"<?php checked( $s['mods']['oros'] ); ?> /> <strong><?php esc_html_e( 'orOS Budget', 'production-ledger' ); ?></strong> — <?php esc_html_e( 'ροή εγγραφών μόνο για ανάγνωση στο /wp-json/wc/v3/pl/records, που τραβά το orOS Budget με τα κλειδιά REST του καταστήματος.', 'production-ledger' ); ?></label></p>
				<?php
				$pulled = get_option( 'pl_oros_pulled', array() );
				if ( is_array( $pulled ) && ! empty( $pulled['t'] ) ) {
					/* translators: 1: date and time, 2: number of rows */
					echo '<p class="pl-hint">' . esc_html( sprintf( __( 'Τελευταία ανάγνωση από το orOS: %1$s, %2$d γραμμές.', 'production-ledger' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $pulled['t'] ), (int) $pulled['rows'] ) ) . '</p>';
				}
				?>
				<p><label class="pl-check"><input type="checkbox" name="pl_mod_printful" value="1"<?php checked( $s['mods']['printful'] ); ?> /> <strong>Printful</strong> — <?php esc_html_e( 'το κόστος κάθε ολοκληρωμένης παραγγελίας από το Printful γίνεται καταχώριση «Εκτύπωση» των προϊόντων της.', 'production-ledger' ); ?></label></p>
				<p><label><?php esc_html_e( 'Κλειδί API Printful (μόνο ανάγνωση)', 'production-ledger' ); ?>
					<input type="password" name="pl_pf_key" autocomplete="new-password" value="" class="regular-text" <?php disabled( 'constant' === $pfk ); ?> /></label>
				<?php
				$states = array(
					'constant'   => __( 'από τη σταθερά PL_PRINTFUL_KEY στο wp-config.php', 'production-ledger' ),
					'option'     => __( 'αποθηκευμένο (κρυπτογραφημένο). Κενό = μένει το τωρινό.', 'production-ledger' ),
					'unreadable' => __( 'δεν διαβάζεται πια (άλλαξαν τα salts του wp-config.php): γράψ\' το ξανά.', 'production-ledger' ),
					'none'       => __( 'δεν έχει οριστεί.', 'production-ledger' ),
				);
				echo ' <span class="pl-hint">' . esc_html( $states[ $pfk ] ) . '</span>';
				?>
				</p>
				<?php if ( 'option' === $pfk ) : ?>
					<p><label class="pl-check"><input type="checkbox" name="pl_pf_clear" value="1" /> <?php esc_html_e( 'Διαγραφή του αποθηκευμένου κλειδιού', 'production-ledger' ); ?></label></p>
				<?php endif; ?>
				<p><label><?php esc_html_e( 'Store ID στο Printful (μόνο για κλειδί λογαριασμού)', 'production-ledger' ); ?>
					<input type="text" name="pl_pf_store" value="<?php echo esc_attr( $s['pf_store'] ); ?>" class="small-text" inputmode="numeric" /></label></p>
				<?php
				$log = get_option( 'pl_pf_log', array() );
				if ( is_array( $log ) && $log ) {
					echo '<details><summary>' . esc_html__( 'Ιστορικό Printful', 'production-ledger' ) . '</summary><table class="widefat pl-table pl-narrow"><tbody>';
					foreach ( array_slice( $log, 0, 20 ) as $l ) {
						echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i', (int) $l['t'] ) ) . '</td><td>#' . (int) $l['order'] . '</td><td>' . esc_html( (string) $l['res'] ) . '</td><td>' . (int) $l['code'] . '</td><td>' . (int) $l['try'] . '</td></tr>';
					}
					echo '</tbody></table></details>';
				}
				?>
			</div>

			<h2 class="pl-h2"><?php esc_html_e( 'Διαγραφή του plugin', 'production-ledger' ); ?></h2>
			<div class="pl-zone">
				<label class="pl-check"><input type="checkbox" name="pl_drop" value="1"<?php checked( $s['drop'] ); ?> /> <?php esc_html_e( 'Διαγραφή όλων των καταχωρίσεων όταν διαγραφεί το plugin', 'production-ledger' ); ?></label>
				<p class="pl-hint"><?php esc_html_e( 'Κλειστό: οι καταχωρίσεις είναι τα βιβλία σου και μένουν στη βάση. Οι ρυθμίσεις φεύγουν πάντα.', 'production-ledger' ); ?></p>
			</div>

			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'production-ledger' ); ?></button></p>
		</form>

		<h2 class="pl-h2"><?php esc_html_e( 'Δοκιμαστικές καταχωρίσεις', 'production-ledger' ); ?></h2>
		<div class="pl-zone">
			<?php
			$tests = PL_Entries::query(
				array(
					'test'  => true,
					'all'   => true,
					'limit' => 1,
				)
			)['total'];
			/* translators: %d: number of rows */
			echo '<p>' . esc_html( sprintf( _n( '%d δοκιμαστική καταχώριση.', '%d δοκιμαστικές καταχωρίσεις.', $tests, 'production-ledger' ), $tests ) ) . '</p>';
			if ( $tests > 0 ) {
				self::button_form( 'purge_test', array(), __( 'Οριστική διαγραφή των δοκιμαστικών', 'production-ledger' ), 'button', __( 'Οι δοκιμαστικές καταχωρίσεις θα σβηστούν οριστικά. Συνέχεια;', 'production-ledger' ) );
			}
			?>
		</div>

		<h2 class="pl-h2"><?php esc_html_e( 'Αντίγραφο ρυθμίσεων', 'production-ledger' ); ?></h2>
		<div class="pl-zone">
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( self::url( 'settings', array( 'pl_get' => 'settings' ) ), self::NONCE_GET ) ); ?>"><?php esc_html_e( 'Λήψη JSON', 'production-ledger' ); ?></a>
			<span class="pl-hint"><?php esc_html_e( 'Χωρίς το κλειδί του Printful.', 'production-ledger' ); ?></span></p>
			<form method="post" enctype="multipart/form-data">
				<?php self::form_head( 'import_settings' ); ?>
				<input type="file" name="pl_file" accept=".json,application/json" required />
				<button type="submit" class="button"><?php esc_html_e( 'Εισαγωγή', 'production-ledger' ); ?></button>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Export
	 * ------------------------------------------------------------------- */

	private static function render_export(): void {
		$m   = wp_date( 'Y-m' );
		$fmt = 'el' === PL_Lang::lang() ? 'excel' : 'standard';
		?>
		<h2 class="pl-h2"><?php esc_html_e( 'Εξαγωγή CSV', 'production-ledger' ); ?></h2>
		<form method="get" class="pl-zone">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="tab" value="export" />
			<?php wp_nonce_field( self::NONCE_GET, '_wpnonce', false ); ?>
			<p><label><?php esc_html_e( 'Μήνας', 'production-ledger' ); ?> <input type="month" name="pl_month" value="<?php echo esc_attr( $m ); ?>" required /></label>
			<label><?php esc_html_e( 'Μορφή', 'production-ledger' ); ?>
				<select name="pl_format">
					<option value="excel"<?php selected( $fmt, 'excel' ); ?>><?php esc_html_e( 'Excel ελληνικά (; και δεκαδικό κόμμα)', 'production-ledger' ); ?></option>
					<option value="standard"<?php selected( $fmt, 'standard' ); ?>><?php esc_html_e( 'Τυπικό (, και δεκαδική τελεία)', 'production-ledger' ); ?></option>
				</select></label></p>
			<p>
				<button type="submit" name="pl_get" value="entries" class="button button-primary"><?php esc_html_e( 'Καταχωρίσεις του μήνα', 'production-ledger' ); ?></button>
				<button type="submit" name="pl_get" value="summary" class="button"><?php esc_html_e( 'Σύνοψη ανά προϊόν', 'production-ledger' ); ?></button>
			</p>
			<p class="pl-hint"><?php esc_html_e( 'Δύο αρχεία: ένα με κάθε καταχώριση και ένα με το αποτέλεσμα κάθε προϊόντος, τα γενικά έξοδα και το τελικό σύνολο. Ανοίγουν στο Excel, στο Υπολογιστικό φύλλο και στην εισαγωγή του orOS Budget.', 'production-ledger' ); ?></p>
		</form>
		<?php
	}

	/* =====================================================================
	 * Privileged GET: CSV and settings JSON (slug + nonce + capability)
	 * =================================================================== */

	public static function route_get(): void {
		if ( self::SLUG !== self::current_page() || '' === self::q( 'pl_get' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_GET ) || ! current_user_can( Production_Ledger::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'production-ledger' ) );
		}
		$what = sanitize_key( self::q( 'pl_get' ) );
		if ( 'settings' === $what ) {
			$json = wp_json_encode(
				array(
					'plugin'  => 'production-ledger',
					'version' => PL_VERSION,
					'options' => array( PL_Settings::OPTION => PL_Settings::get() ),
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="production-ledger-settings-' . wp_date( 'Y-m-d' ) . '.json"' );
			echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON download.
			exit;
		}
		if ( 'entries' === $what || 'summary' === $what ) {
			$month = self::q( 'pl_month' );
			$csv   = PL_Export::csv( $what, $month, 'standard' === self::q( 'pl_format' ) ? 'standard' : 'excel' );
			if ( is_wp_error( $csv ) ) {
				wp_die( esc_html__( 'Άκυρος μήνας.', 'production-ledger' ) );
			}
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . PL_Export::filename( $what, $month ) . '"' );
			echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a CSV download, cells guarded in PL_Export.
			exit;
		}
	}

	/* =====================================================================
	 * AJAX: product search (§5: from the 3rd character, 30 results)
	 * =================================================================== */

	public static function ajax_products(): void {
		check_ajax_referer( 'pl_products', 'nonce' );
		if ( ! current_user_can( Production_Ledger::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		$term = isset( $_GET['q'] ) && is_scalar( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$out  = array();
		if ( ctype_digit( $term ) ) {
			$p = wc_get_product( (int) $term );
			if ( $p instanceof WC_Product && ! $p instanceof WC_Product_Variation ) {
				$out[] = self::product_label( (int) $p->get_id() );
			}
		} elseif ( mb_strlen( $term ) >= 3 ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a title search with esc_like, 30 rows.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ('publish','private','draft','pending','future') AND post_title LIKE %s ORDER BY post_title LIMIT 30", '%' . $wpdb->esc_like( $term ) . '%' ) );
			foreach ( (array) $ids as $id ) {
				$out[] = self::product_label( (int) $id );
			}
		}
		wp_send_json_success( $out );
	}

	/* =====================================================================
	 * POST router (Bible §11)
	 * =================================================================== */

	public static function route(): void {
		if ( self::SLUG !== self::current_page() || ! isset( $_POST[ self::NONCE_FIELD ], $_POST['pl_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Production_Ledger::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'production-ledger' ) );
		}
		$tab = in_array( self::post( 'pl_tab' ), array( 'entries', 'general' ), true ) ? self::post( 'pl_tab' ) : 'entries';
		switch ( sanitize_key( wp_unslash( $_POST['pl_action'] ) ) ) {
			case 'save_entry':
				self::do_save_entry( $tab );
				break;
			case 'delete_entry':
				$id = (int) self::post( 'pl_id' );
				if ( PL_Entries::delete( $id ) ) {
					set_transient( 'pl_undo_' . get_current_user_id(), array( $id, $tab ), 300 );
					self::redirect( $tab, array( self::msg( 'success', __( 'Η καταχώριση διαγράφηκε.', 'production-ledger' ) ) ) );
				}
				self::redirect( $tab, array( self::msg( 'error', __( 'Η καταχώριση δεν βρέθηκε.', 'production-ledger' ) ) ) );
				break;
			case 'restore_entry':
				delete_transient( 'pl_undo_' . get_current_user_id() );
				if ( PL_Entries::restore( (int) self::post( 'pl_id' ) ) ) {
					self::redirect( $tab, array( self::msg( 'success', __( 'Η καταχώριση επανήλθε.', 'production-ledger' ) ) ) );
				}
				self::redirect( $tab, array( self::msg( 'error', __( 'Η επαναφορά δεν έγινε: η καταχώριση δεν είναι διαγραμμένη ή η πηγή της έφτιαξε νεότερη.', 'production-ledger' ) ) ) );
				break;
			case 'purge_test':
				$n = PL_Entries::purge_test();
				/* translators: %d: rows */
				self::redirect( 'settings', array( self::msg( 'success', sprintf( _n( 'Σβήστηκε %d δοκιμαστική καταχώριση.', 'Σβήστηκαν %d δοκιμαστικές καταχωρίσεις.', $n, 'production-ledger' ), $n ) ) ) );
				break;
			case 'save_settings':
				self::do_save_settings();
				break;
			case 'import_settings':
				self::do_import_settings();
				break;
			default:
				self::redirect( 'overview', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'production-ledger' ) ) ) );
		}
	}

	private static function do_save_entry( string $tab ): void {
		$id   = (int) self::post( 'pl_id' );
		$mode = self::post( 'pl_mode' );
		$pid  = 0;
		$pool = '';
		if ( 'product' === $mode ) {
			$pid = preg_match( '/^\s*(\d+)/', self::post( 'pl_product' ), $m ) ? (int) $m[1] : 0;
			if ( $pid <= 0 ) {
				self::redirect( $tab, array( self::msg( 'error', __( 'Διάλεξε προϊόν (γράψε 3 γράμματα του τίτλου ή το ID του).', 'production-ledger' ) ) ), $id ? array( 'edit' => $id ) : array() );
			}
		} elseif ( 'pool' === $mode ) {
			$pool = sanitize_key( self::post( 'pl_pool' ) );
		}
		$in = array(
			'date'           => self::post( 'pl_date' ),
			'product_id'     => $pid,
			'category'       => sanitize_key( self::post( 'pl_category' ) ),
			'pool'           => $pool,
			'supplier'       => self::post( 'pl_supplier' ),
			'invoice_no'     => self::post( 'pl_invoice' ),
			'description'    => self::post( 'pl_description' ),
			'net'            => self::post( 'pl_net' ),
			'vat_rate'       => self::post( 'pl_rate' ),
			'vat'            => self::post( 'pl_vat' ),
			'qty_basis'      => self::post( 'pl_qty' ),
			'attachment_url' => self::post( 'pl_url' ),
			'test'           => '1' === self::post( 'pl_test' ),
		);
		$r  = $id > 0 ? PL_Entries::update( $id, $in ) : PL_Entries::add( $in );
		if ( is_wp_error( $r ) ) {
			self::redirect( $tab, array( self::msg( 'error', self::error_text( $r ) ) ), $id ? array( 'edit' => $id ) : array() );
		}
		self::redirect( $tab, array( self::msg( 'success', $id > 0 ? __( 'Η καταχώριση αποθηκεύτηκε.', 'production-ledger' ) : __( 'Η καταχώριση προστέθηκε.', 'production-ledger' ) ) ) );
	}

	/** Greek text for a validation error code. */
	private static function error_text( WP_Error $e ): string {
		$t = array(
			'pl_date'    => __( 'Η ημερομηνία δεν είναι έγκυρη.', 'production-ledger' ),
			/* translators: %s: start date */
			'pl_early'   => sprintf( __( 'Η ημερομηνία είναι πριν από την αρχή της καταγραφής (%s).', 'production-ledger' ), PL_Settings::start() ),
			'pl_late'    => __( 'Η ημερομηνία είναι πάνω από ένα χρόνο μπροστά.', 'production-ledger' ),
			'pl_cat'     => __( 'Άκυρη κατηγορία.', 'production-ledger' ),
			'pl_product' => __( 'Το προϊόν δεν υπάρχει στο κατάστημα.', 'production-ledger' ),
			'pl_general' => __( 'Η κατηγορία «Γενικό έξοδο» είναι μόνο για τα γενικά έξοδα.', 'production-ledger' ),
			'pl_offsite' => __( 'Τα έσοδα εκτός site χρειάζονται προϊόν.', 'production-ledger' ),
			'pl_pool'    => __( 'Η κατανομή δεν ταιριάζει με την κατηγορία (μεταφορικά → μεταφορέας, αναλώσιμα → όλες οι αποστολές, προμήθειες → πάροχος πληρωμών).', 'production-ledger' ),
			'pl_amount'  => __( 'Το καθαρό ποσό πρέπει να είναι ≥ 0 με έως 2 δεκαδικά.', 'production-ledger' ),
			'pl_rate'    => __( 'Ο συντελεστής ΦΠΑ πρέπει να είναι από 0 έως 100.', 'production-ledger' ),
			'pl_vat'     => __( 'Ο ΦΠΑ πρέπει να είναι ποσό ≥ 0.', 'production-ledger' ),
			'pl_qty'     => __( 'Άκυρο πλήθος.', 'production-ledger' ),
			'pl_url'     => __( 'Ο σύνδεσμος πρέπει να είναι διεύθυνση http(s).', 'production-ledger' ),
			'pl_missing' => __( 'Η καταχώριση δεν βρέθηκε.', 'production-ledger' ),
			'pl_db'      => __( 'Η καταχώριση δεν αποθηκεύτηκε (σφάλμα βάσης).', 'production-ledger' ),
		);
		$c = (string) $e->get_error_code();
		return isset( $t[ $c ] ) ? $t[ $c ] : $e->get_error_message();
	}

	private static function do_save_settings(): void {
		$old = PL_Settings::get();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$house = isset( $_POST['pl_house'] ) && is_array( $_POST['pl_house'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['pl_house'] ) ) : array();
		$msgs  = array();

		$carriers = PL_Settings::parse_lines( self::post( 'pl_carriers' ), 'match' );
		$pay      = PL_Settings::parse_lines( self::post( 'pl_pay' ), 'methods' );
		if ( ! $carriers && '' !== self::post( 'pl_carriers' ) ) {
			$msgs[]   = self::msg( 'warning', __( 'Οι μεταφορείς δεν διαβάστηκαν (μορφή: κλειδί | όνομα | λέξεις): κράτησα τους προηγούμενους.', 'production-ledger' ) );
			$carriers = $old['carriers'];
		}
		if ( ! $pay && '' !== self::post( 'pl_pay' ) ) {
			$msgs[] = self::msg( 'warning', __( 'Οι ομάδες πληρωμών δεν διαβάστηκαν (μορφή: κλειδί | όνομα | ids): κράτησα τις προηγούμενες.', 'production-ledger' ) );
			$pay    = $old['pay'];
		}
		$start = self::post( 'pl_start' );
		if ( ! PL_Settings::valid_date( $start ) ) {
			$start  = $old['start'];
			$msgs[] = self::msg( 'warning', __( 'Άκυρη ημερομηνία έναρξης: έμεινε η προηγούμενη.', 'production-ledger' ) );
		}
		PL_Settings::save(
			array(
				'start'              => $start,
				'house'              => $house,
				'carriers'           => $carriers,
				'pay'                => $pay,
				'spread_consumables' => '1' === self::post( 'pl_spread' ),
				'mods'               => array(
					'oros'     => '1' === self::post( 'pl_mod_oros' ),
					'printful' => '1' === self::post( 'pl_mod_printful' ),
				),
				'pf_store'           => preg_replace( '/\D/', '', self::post( 'pl_pf_store' ) ),
				'drop'               => '1' === self::post( 'pl_drop' ),
			)
		);
		if ( '1' === self::post( 'pl_pf_clear' ) ) {
			PL_Settings::set_pf_key( '' );
		} elseif ( '' !== self::post( 'pl_pf_key' ) && ! PL_Settings::pf_key_from_constant() ) {
			if ( ! PL_Settings::set_pf_key( self::post( 'pl_pf_key' ) ) ) {
				$msgs[] = self::msg( 'error', __( 'Το κλειδί του Printful δεν αποθηκεύτηκε (άκυρη μορφή ή λείπει το OpenSSL).', 'production-ledger' ) );
			}
		}
		array_unshift( $msgs, self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'production-ledger' ) ) );
		self::redirect( 'settings', $msgs );
	}

	private static function do_import_settings(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$f = isset( $_FILES['pl_file'] ) && is_array( $_FILES['pl_file'] ) ? $_FILES['pl_file'] : null;
		if ( ! $f || ! empty( $f['error'] ) || empty( $f['tmp_name'] ) || (int) $f['size'] > self::MAX_JSON || ! is_uploaded_file( (string) $f['tmp_name'] ) ) {
			self::redirect( 'settings', array( self::msg( 'error', __( 'Το αρχείο δεν ανέβηκε ή είναι πολύ μεγάλο.', 'production-ledger' ) ) ) );
		}
		$data = json_decode( (string) file_get_contents( (string) $f['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- an uploaded temp file.
		if ( ! is_array( $data ) || ( $data['plugin'] ?? '' ) !== 'production-ledger' || ! isset( $data['options'][ PL_Settings::OPTION ] ) || ! is_array( $data['options'][ PL_Settings::OPTION ] ) ) {
			self::redirect( 'settings', array( self::msg( 'error', __( 'Δεν είναι αντίγραφο ρυθμίσεων του Production Ledger: τίποτα δεν άλλαξε.', 'production-ledger' ) ) ) );
		}
		PL_Settings::save( $data['options'][ PL_Settings::OPTION ] );
		self::redirect( 'settings', array( self::msg( 'success', __( 'Οι ρυθμίσεις εισήχθησαν (το κλειδί του Printful έμεινε όπως ήταν).', 'production-ledger' ) ) ) );
	}

	/* =====================================================================
	 * Messages (PRG)
	 * =================================================================== */

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs, array $args = array() ): void {
		if ( $msgs ) {
			set_transient( 'pl_aui_msg_' . get_current_user_id(), $msgs, 60 );
		}
		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	private static function take_msgs(): array {
		$raw = get_transient( 'pl_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && $raw ) {
			delete_transient( 'pl_aui_msg_' . get_current_user_id() );
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

	/** «Undo» right after a delete (5 minutes). */
	private static function print_undo(): void {
		$u = get_transient( 'pl_undo_' . get_current_user_id() );
		if ( ! is_array( $u ) || count( $u ) < 2 ) {
			return;
		}
		echo '<div class="notice notice-info inline pl-undo"><p>' . esc_html__( 'Διέγραψες μια καταχώριση.', 'production-ledger' ) . ' ';
		self::button_form( 'restore_entry', array( 'pl_id' => (int) $u[0], 'pl_tab' => (string) $u[1] ), __( 'Αναίρεση', 'production-ledger' ), 'button button-small' );
		echo '</p></div>';
	}

	/* =====================================================================
	 * Footer (Bible §7)
	 * =================================================================== */

	private static function footer(): void {
		?>
		<p class="pl-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'production-ledger' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="pl-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="pl-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'production-ledger' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="pl-footer-cta">
			<a class="pl-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'production-ledger' ); ?></a>
			<a class="pl-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>"><?php esc_html_e( 'Noxpress Dashboard', 'production-ledger' ); ?></a>
		</p>
		<?php
	}
}

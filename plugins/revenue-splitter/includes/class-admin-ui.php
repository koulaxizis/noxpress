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
 * v1.3.8 (#1): Custom χρώμα ανά δικαιούχο (option
 * rs_beneficiary_colors, JSON όνομα → #RRGGBB, default #6d4aff) —
 * εφαρμόζεται στα chips του καταμερισμού, στα ονόματα του πίνακα
 * «Λογιστική δικαιούχων» και (Part 4) στο widget. Το χρώμα κειμένου
 * υπολογίζεται με luminance (chip_fg) για πάντα αναγνώσιμη αντίθεση.
 * v1.3.8 (#2): Multi-select προϊόντων (rs_product[]) με πεδίο
 * αναζήτησης (admin.js, delegated #rs-prod-search) + λίστα από ΟΛΑ
 * τα δημοσιευμένα προϊόντα (όχι μόνο όσα έχουν πωλήσεις στην περίοδο).
 * v1.3.8 (#3): Φίλτρο δικαιούχου (rs_ben, whitelist από
 * RS_Beneficiaries::collect_names()) — φιλτράρει «Ανά προϊόν»,
 * totals, ΦΠΑ/συντελεστή, «Λογιστική» και όλα τα exports. Semantics:
 * ορατά είναι ΜΟΝΟ τα προϊόντα που συμμετέχει ο δικαιούχος (chips
 * μόνο του), τα totals είναι των ορατών προϊόντων, το order_count
 * παραμένει της περιόδου (δεν ανάγεται από aggregated δεδομένα).
 * v1.3.8 (#4): Default preset του Dashboard (και fallback σε άκυρο
 * preset) = «Τρέχον έτος». Ισχύει και στο widget (ίδιο parser).
 * v1.3.8 (#5): Εμφανιζόμενες ημερομηνίες d/m/Y όταν el — μέσω
 * RS_Lang::fmt_date(). DISPLAY-ONLY: GET params, SQL, options,
 * filenames παραμένουν ΠΑΝΤΑ ISO Y-m-d.
 * v1.3.8 (#7 στάδιο 1): option rs_channels — λίστα καναλιών πώλησης
 * (Ρυθμίσεις). Το wiring στο checkout (κουπόνι → επιλογή καναλιού
 * αντί για αιτιολογία) και στο ledger (dropdown καναλιού στη
 * χειροκίνητη είσοδο εσόδου) γίνεται στα αντίστοιχα classes.
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
	const SLUG_MENU = 'noxpress';            // Κοινό top-level (Noxpress Core, Bible §16)
	const SLUG_DASH = 'revenue-splitter-dashboard';
	const SLUG_SET  = 'revenue-splitter-settings';

	/** Mirror του option του RS_Checkout (free-copy reason coupons). */
	const OPT_COUPONS = 'rs_reason_coupons';

	/** v1.3.6 (#9): mirror του option «έναρξη καταγραφής πωλήσεων». */
	const OPT_SALES_SINCE = 'rs_sales_since';

	/** v1.3.8 (#1): custom χρώματα δικαιούχων (JSON: όνομα => #rrggbb). */
	const OPT_COLORS = 'rs_beneficiary_colors';

	/** v1.3.8 (#7): λίστα καναλιών πώλησης (comma/ newline separated). */
	const OPT_CHANNELS = 'rs_channels';

	/** v1.3.8 (#7 στάδιο 3): default κανάλι για παραγγελίες χωρίς μαρκάρισμα. */
	const OPT_DEFAULT_CHANNEL = 'rs_default_channel';

	/** v1.3.8 (#7 στάδιο 3): fallback ετικέτα του default pseudo-καναλιού. */
	const FALLBACK_CHANNEL = 'Κατάστημα/Online';

	/** v1.3.8 (πρόταση 1): GET key του mini chart (6/12 μήνες). */
	const OPT_TREND_GET = 'rs_chart';

	/**
	 * Whitelist options για backup/import (#4) — v1.3.6 (#9): +
	 * rs_sales_since. v1.3.8 (#1/#7): + rs_beneficiary_colors,
	 * rs_channels.
	 */
	const STATE_OPTS = array(
		'rs_default_vat_rate',
		'rs_beneficiaries',
		'rs_portal_keys',
		'rs_ledger',
		'rs_reason_coupons',
		'rs_sales_since',
		'rs_beneficiary_colors',
		'rs_channels',
		'rs_default_channel', // v1.3.8 (#7 στάδιο 3).
		'rs_beneficiary_emails', // vNext (#2): emails δικαιούχων.
		'rs_email_reports',     // vNext (#1): opt-in μηνιαίας αναφοράς.
	);

	/** @var array|null Per-request cache: χρώματα δικαιούχων. */
	private static $color_cache = null;

	/** @var array|null Per-request cache: λίστα καναλιών. */
	private static $channels_cache = null;

	/** @var string|null Per-request cache: default κανάλι. */
	private static $default_channel_cache = null;

	/**
	 * v1.7.0: καθαρισμός των per-request caches (rs_invalidate_cache) —
	 * π.χ. το state import ξαναδιαβάζει τα κανάλια πριν το ledger.
	 */
	public static function clear_cache(): void {
		self::$color_cache           = null;
		self::$channels_cache        = null;
		self::$default_channel_cache = null;
	}

	public static function init(): void {
		add_action( 'rs_invalidate_cache', array( __CLASS__, 'clear_cache' ) );

		// Noxpress contract (Bible §8): RS = priority 9, μετά το Noxpress
		// Core (5) που δημιουργεί το κοινό top-level.
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 9 );
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

		// Το κοινό top-level «Noxpress» και η landing σελίδα του (hub) ανήκουν
		// στο Noxpress Core (includes/noxpress-core, Bible §16), που τρέχει
		// πρώτο (priority 5). Εδώ μόνο τα δικά μας submenus.
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Revenue Splitter — Γρήγορη ματιά', 'revenue-splitter' ),
			__( 'Revenue Splitter', 'revenue-splitter' ),
			self::CAP,
			self::SLUG_DASH,
			array( __CLASS__, 'render_dashboard' )
		);

		add_submenu_page(
			self::SLUG_MENU,
			__( 'Revenue Splitter — Ρυθμίσεις', 'revenue-splitter' ),
			__( 'RS Ρυθμίσεις', 'revenue-splitter' ),
			self::CAP,
			self::SLUG_SET,
			array( __CLASS__, 'render_settings' )
		);

		// Η σελίδα «RS Portal» (κλειδιά δικαιούχων) καταχωρείται από το
		// RS_Portal::admin_menu() — εδώ καμία διπλή εγγραφή.
	}

	/**
	 * v1.3.2 FIX (#1): το CSS φορτώνεται ΚΑΙ στο dashboard (wp-admin/
	 * index.php) για το widget. Εκεί enqueue-άρει ΜΟΝΟ το stylesheet.
	 *
	 * v1.3.8 FIX (#2): το admin.js φορτώνει ΚΑΙ στις δικές μας admin
	 * pages (dashboard) — το delegated #rs-prod-search filter
	 * χρειάζεται εκεί (το metabox JS παραμένει δεμένο ΜΟΝΟ σε
	 * .rs-split-table rows, δεν επηρεάζεται).
	 */
	public static function assets( string $hook ): void {

		// Noxpress contract: ταίριασμα με το $_GET['page'] (sanitized) απέναντι
		// στα ΔΙΚΑ μας slugs — λειτουργεί είτε το parent είναι το «noxpress»
		// είτε όχι (καμία εικασία πάνω στο hook suffix).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page slug.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$own     = array( self::SLUG_DASH, self::SLUG_SET, 'revenue-splitter-portal' );
		$is_ours = ( '' !== $page && in_array( $page, $own, true ) );

		// Metabox προϊόντος: μόνο σε οθόνες επεξεργασίας product.
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$product = ( 'post.php' === $hook || 'post-new.php' === $hook )
			&& ( ! $screen || 'product' === $screen->post_type );
		$is_dash = ( 'index.php' === $hook ); // v1.3.2 (#1): dashboard widget.

		if ( ! $is_ours && ! $product && ! $is_dash ) {
			return;
		}

		$base  = plugin_dir_url( RS_FILE );
		$css_v = file_exists( RS_PATH . 'assets/admin.css' ) ? (string) filemtime( RS_PATH . 'assets/admin.css' ) : RS_VERSION;

		wp_enqueue_style( 'rs-admin', $base . 'assets/admin.css', array(), $css_v );

		// v1.3.8 (#2): δικές μας σελίδες = χρειάζονται και το admin.js
		// (v1.3.3 FIX #5: metabox bindings παραμένουν scoped σε
		// .rs-split-table — το πρόσθετο delegated listener του
		// #rs-prod-search είναι passive, δεν αγγίζει τίποτα άλλο).
		if ( $product || $is_ours ) {
			$js_v = file_exists( RS_PATH . 'assets/admin.js' ) ? (string) filemtime( RS_PATH . 'assets/admin.js' ) : RS_VERSION;
			wp_enqueue_script( 'rs-admin', $base . 'assets/admin.js', array(), $js_v, true );
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

		// v1.3.8 (#4): default = «Τρέχον έτος» (και fallback παρακάτω).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only period filter.
		$preset = isset( $_GET['rs_period'] ) ? sanitize_key( wp_unslash( $_GET['rs_period'] ) ) : 'year';

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
					// Άκυρο custom range → το dropdown πρέπει να ταιριάζει
					// με ό,τι πραγματικά βλέπει ο χρήστης (δες observation #5).
					$preset = 'year';
					$label  = __( 'Τρέχον έτος', 'revenue-splitter' );
					$s      = $now->format( 'Y-01-01' );
					$e      = $now->format( 'Y-m-d' );
				}
				break;
			case 'month':
				$preset = 'month';
				$label  = __( 'Τρέχων μήνας', 'revenue-splitter' );
				$s      = $now->format( 'Y-m-01' );
				$e      = $now->format( 'Y-m-d' );
				break;
			default:
				// v1.3.8 (#4): άκυρο/άγνωστο preset → fail-safe στο
				// «Τρέχον έτος» (νέο default), όχι στον μήνα.
				$preset = 'year';
				$label  = __( 'Τρέχον έτος', 'revenue-splitter' );
				$s      = $now->format( 'Y-01-01' );
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
	 * v1.3.8: Φίλτρα (multi-product + δικαιούχος) + color helpers
	 * =================================================================== */

	/**
	 * #2: Επιλεγμένα IDs προϊόντων από το GET. Δέχεται τόσο array
	 * (rs_product[]=1&rs_product[]=2, νέο multi-select) όσο και legacy
	 * scalar (rs_product=1). Επιστρέφει sorted unique array — κανένα
	 * ID = όλα τα προϊόντα.
	 *
	 * Το canonical sort() είναι ΚΑΙ cache-key canonicalization
	 * (audit #3): ίδιο σύνολο επιλογών = ίδιο cache entry του
	 * RS_Reports::run() ανεξαρτήτως σειράς κλικ.
	 */
	private static function current_products(): array {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, absint παρακάτω.
		if ( ! isset( $_GET['rs_product'] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- array validated/sanitized below.
		$raw = wp_unslash( $_GET['rs_product'] );

		$ids = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $v ) {
				if ( ! is_scalar( $v ) ) {
					continue; // Nested-array smuggling (π.χ. rs_product[0][x]=1) — αγνοείται.
				}
				$v = absint( $v );
				if ( $v > 0 ) {
					$ids[] = $v;
				}
			}
		} else {
			$v = absint( $raw ); // Legacy single-value link.
			if ( $v > 0 ) {
				$ids[] = $v;
			}
		}

		$ids = array_values( array_unique( $ids ) );
		sort( $ids );

		return $ids;
	}

	/**
	 * #3: Επιλεγμένος δικαιούχος από το GET — STRICT whitelist
	 * (RS_Beneficiaries::collect_names()). Οτιδήποτε άλλο → '' (= όλοι).
	 */
	private static function current_beneficiary(): string {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$ben = isset( $_GET['rs_ben'] ) ? sanitize_text_field( wp_unslash( $_GET['rs_ben'] ) ) : '';

		if ( '' === $ben ) {
			return '';
		}

		return in_array( $ben, RS_Beneficiaries::collect_names(), true ) ? $ben : '';
	}

	/**
	 * #3: Φιλτράρει το report κατά δικαιούχο (in-place semantics, νέο
	 * array). Το product filter ΔΕΝ γίνεται εδώ — περνάει upstream στο
	 * RS_Reports::run() ως product_ids (single source of truth + cache).
	 *
	 * v1.7.0: public — το χρησιμοποιεί και το `wp rs report --ben` (RS_CLI).
	 */
	public static function apply_ben_filter( array $report, string $ben ): array {

		if ( '' === $ben ) {
			return $report;
		}

		// 1) Splits → μόνο του δικαιούχου. Προϊόντα χωρίς συμμετοχή → εκτός.
		foreach ( $report['products'] as $i => $p ) {
			$report['products'][ $i ]['splits'] = array_values(
				array_filter(
					$p['splits'],
					static function ( $s ) use ( $ben ) {
						return ( $s['name'] ?? '' ) === $ben;
					}
				)
			);
		}

		$report['products'] = array_values(
			array_filter(
				$report['products'],
				static function ( $p ) {
					return ! empty( $p['splits'] );
				}
			)
		);

		// 2) Beneficiaries → μόνο ο ίδιος.
		$report['beneficiaries'] = array_values(
			array_filter(
				$report['beneficiaries'],
				static function ( $b ) use ( $ben ) {
					return ( $b['name'] ?? '' ) === $ben;
				}
			)
		);

		// 3) Totals → επανυπολογισμός από τα ΟΡΑΤΑ προϊόντα (πλήρεις
		// ποσότητες αυτών — gross/vat/net είναι μετρικά προϊόντος, δεν
		// αναλύονται ανά δικαιούχο). order_count: ΑΜΕΤΑΒΛΗΤΟ — μέτρημα
		// παραγγελιών περιόδου, δεν ανάγεται από aggregated δεδομένα.
		$gross = 0.0;
		$vat   = 0.0;
		$net   = 0.0;
		foreach ( $report['products'] as $p ) {
			$gross += (float) $p['gross'];
			$vat   += (float) $p['vat'];
			$net   += (float) $p['net'];
		}
		$report['totals']['gross'] = round( $gross, 2 );
		$report['totals']['vat']   = round( $vat, 2 );
		$report['totals']['net']   = round( $net, 2 );

		return $report;
	}

	/**
	 * #1: Custom χρώμα δικαιούχου από το option rs_beneficiary_colors
	 * (JSON όνομα → #RRGGBB). Οτιδήποτε άκυρο/άγνωστο → default μωβ.
	 *
	 * Public + static-cache ανά request — το RS_Portal το ξαναχρησιμοποιεί
	 * (ίδια ουρά αναφοράς).
	 */
	public static function ben_color( string $name ): string {
		// Single source of truth: get_color_map() — πανομοιότυπο parsing
		// (regex + strtolower + static cache), καμία διπλή υλοποίηση.
		return self::get_color_map()[ $name ] ?? '#6d4aff';
	}

	/**
	 * #1: Χρώμα κειμένου πάνω σε chip — luminance contrast (WCAG-ish,
	 * βασικός). Ανοιχτό background → σκούρο κείμενο, αλλιώς λευκό.
	 */
	public static function chip_fg( string $hex ): string {

		if ( 1 !== preg_match( '/^#[0-9a-fA-F]{6}$/', $hex ) ) {
			return '#ffffff';
		}

		$r   = (float) hexdec( substr( $hex, 1, 2 ) );
		$g   = (float) hexdec( substr( $hex, 3, 2 ) );
		$b   = (float) hexdec( substr( $hex, 5, 2 ) );
		$lum = ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) / 255.0;

		return ( $lum > 0.62 ) ? '#1d2327' : '#ffffff';
	}

	/* =====================================================================
	 * Dashboard
	 * =================================================================== */

	public static function render_dashboard(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}

		$per = self::current_period();

		// v1.3.8 (#2): multi-select IDs (sorted unique) — περνούν
		// upstream στο RS_Reports::run (single source of truth + cache).
		$pids = self::current_products();

		// v1.3.8 (#3): φίλτρο δικαιούχου (whitelist-validated).
		$ben = self::current_beneficiary();

		$run = array(
			'date_start' => $per['start'],
			'date_end'   => $per['end'],
		);
		if ( ! empty( $pids ) ) {
			$run['product_ids'] = $pids;
		}

		$report = RS_Reports::run( $run );
		$report = self::apply_ben_filter( $report, $ben );

		// ---------- v1.3.8 (#2): λίστα ΟΛΩΝ των δημοσιευμένων προϊόντων ----------
		// Το dropdown ΔΕΝ εξαρτάται πλέον από το ρεπόρτ περιόδου: μπορείς
		// να φιλτράρεις και σε προϊόν χωρίς πωλήσεις (audit finding #2).
		$prod_map = array();
		$products = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => -1,
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);
		if ( is_array( $products ) ) {
			foreach ( $products as $prod ) {
				if ( $prod instanceof WC_Product ) {
					$prod_map[ (int) $prod->get_id() ] = (string) $prod->get_name();
				}
			}
		}
		// Συμπλήρωση: προϊόντα του report που λείπουν από τη λίστα
		// (π.χ. non-public status αλλά με πωλήσεις ιστορικά).
		foreach ( $report['products'] as $dp ) {
			$k = (int) $dp['product_id'];
			if ( ! isset( $prod_map[ $k ] ) ) {
				$prod_map[ $k ] = (string) $dp['title'];
			}
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

		// ---------- v1.3.8 (#7 στάδιο 3): «Ανά κανάλι» (ΠΑΝΤΑ σφαιρικός) ----------
		// Το key 'channels' του report ΔΕΝ φιλτράρεται από το apply_ben_filter
		// (απόφαση χρήστη: ο πίνακας είναι σφαιρικός) — σέβεται όμως
		// περίοδο + φίλτρο προϊόντων (upstream στο RS_Reports::run()).
		$channel_rows = self::channel_rows( $report, $per );

		// ---------- Πρόταση 1: mini chart τάσης (6/12 μήνες) ----------
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only selector.
		$trend_n  = ( isset( $_GET[ self::OPT_TREND_GET ] ) && in_array( (int) $_GET[ self::OPT_TREND_GET ], array( 6, 12 ), true ) )
			? absint( $_GET[ self::OPT_TREND_GET ] ) : 12;
		$trend    = self::monthly_trend( $trend_n, $ben );
		$max_net  = 0.0;
		foreach ( $trend as $t ) {
			$max_net = max( $max_net, (float) $t['net'] );
		}

		$today    = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );
		$lifetime = RS_Reports::lifetime_beneficiaries();

		$people = array();
		if ( '' !== $ben ) {
			// v1.3.8 (#3): φίλτρο → μόνο ο επιλεγμένος δικαιούχος.
			$people[ $ben ] = true;
		} else {
			foreach ( RS_Beneficiaries::collect_names() as $name ) {
				$people[ $name ] = true;
			}
			foreach ( $report['beneficiaries'] as $b ) {
				$people[ $b['name'] ] = true;
			}
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

				<?php // v1.3.8 (#2): picker προϊόντων — αναζήτηση + multi-select. ?>
				<span class="rs-prod-picker">
					<input type="search" id="rs-prod-search" class="rs-search regular-text"
						placeholder="<?php esc_attr_e( 'Αναζήτηση προϊόντος…', 'revenue-splitter' ); ?>" />
					<select id="rs-prod-multi" class="rs-prod-multi" name="rs_product[]" multiple size="6">
											<?php foreach ( $prod_map as $dpid => $title ) : ?>
						<option value="<?php echo esc_attr( (string) $dpid ); ?>"
							<?php selected( in_array( (int) $dpid, $pids, true ), true ); ?>>
							<?php echo esc_html( (string) $dpid . ' — ' . $title ); ?>
						</option>
					<?php endforeach; ?>
					</select>
					<span class="rs-prod-hint"><?php esc_html_e( 'Ctrl/Cmd + click για πολλαπλή επιλογή. Καμία επιλογή = όλα.', 'revenue-splitter' ); ?></span>
				</span>

				<?php // v1.3.8 (#3): φίλτρο δικαιούχου (strict whitelist). ?>
				<select name="rs_ben">
					<option value=""<?php selected( $ben, '' ); ?>><?php esc_html_e( 'Όλοι οι δικαιούχοι', 'revenue-splitter' ); ?></option>
					<?php foreach ( RS_Beneficiaries::collect_names() as $bname ) : ?>
						<option value="<?php echo esc_attr( $bname ); ?>"<?php selected( $ben, $bname ); ?>>
							<?php echo esc_html( $bname ); ?>
						</option>
					<?php endforeach; ?>
				</select>

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
					<a class="button" href="<?php echo esc_url( self::export_url( $fmt, $per, $pids, $ben ) ); ?>">
						<?php esc_html_e( 'Εξαγωγή', 'revenue-splitter' ); ?> <?php echo esc_html( strtoupper( $fmt ) ); ?>
					</a>
				<?php endforeach; ?>
			</form>

			<?php // v1.3.8 (#5): εμφανιζόμενες ημερομηνίες d/m/Y στα el (DISPLAY-ONLY). ?>
			<h2 class="rs-h2"><?php echo esc_html( $per['label'] ); ?> — <?php echo esc_html( RS_Lang::fmt_date( $per['start'] ) ); ?> → <?php echo esc_html( RS_Lang::fmt_date( $per['end'] ) ); ?></h2>

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
							<?php if ( ! empty( $p['sale_est'] ) ) : ?><small> · <?php esc_html_e( 'εκτίμηση έκπτωσης (τιμοκατάλογος)', 'revenue-splitter' ); ?></small><?php endif; ?>
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
							// v1.3.8 (#1): chips με custom χρώμα δικαιούχου —
							// bg από ben_color(), fg από chip_fg() (contrast).
							// v1.3.8 (#3): με φίλτρο → μόνο ο επιλεγμένος.
							$chips = array();
							foreach ( $p['splits'] as $s ) {
								$bg = self::ben_color( (string) $s['name'] );
								$fg = self::chip_fg( $bg );
								$chips[] = '<span class="rs-chip" style="background:' . esc_attr( $bg ) . ';color:' . esc_attr( $fg ) . ';">'
									. '<span class="rs-chip-name">' . esc_html( $s['name'] ) . '</span>'
									. '<span class="rs-chip-data">'
									. esc_html( number_format_i18n( (float) $s['percent'], 1 ) ) . '% · '
									. esc_html( number_format_i18n( (float) $s['amount'], 2 ) )
									. '</span></span>';
							}
							echo implode( '<br />', $chips ); // phpcs:ignore WordPress.Security.EscapeOutput -- esc_html/esc_attr εντός του loop.
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
			
						<?php // ---------- v1.3.8 (#7 στάδιο 3): Πίνακας «Ανά κανάλι» ---------- ?>
			<h2 class="rs-h2"><?php esc_html_e( 'Ανά κανάλι', 'revenue-splitter' ); ?></h2>
			<table class="widefat striped rs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Κανάλι', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Τεμ.', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Δωρεάν', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Μικτό', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'ΦΠΑ', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Καθαρό', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Έσοδα εκτός πωλήσεων', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Πληρωμές', 'revenue-splitter' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $channel_rows ) ) : ?>
					<tr><td colspan="8" class="rs-empty"><?php esc_html_e( 'Καμία κίνηση ανά κανάλι στην περίοδο.', 'revenue-splitter' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $channel_rows as $cr ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $cr['channel'] ); ?></strong>
							<?php if ( $cr['channel'] === self::default_channel() ) : ?>
								<small class="rs-muted"> · <?php esc_html_e( 'default', 'revenue-splitter' ); ?></small>
							<?php endif; ?>
						</td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $cr['qty'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $cr['free'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $cur( $cr['gross'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $cur( $cr['vat'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $cur( $cr['net'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $cur( $cr['income'] ) ); ?></td>
						<td class="num">−<?php echo esc_html( $cur( $cr['payment'] ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
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
						<?php // v1.3.8 (#1): custom χρώμα και στο όνομα του πίνακα. ?>
						<td>
							<strong class="rs-chip-name rs-name-pill" style="background:<?php echo esc_attr( self::ben_color( $a['name'] ) ); ?>;color:<?php echo esc_attr( self::chip_fg( self::ben_color( $a['name'] ) ) ); ?>;">
								<?php echo esc_html( $a['name'] ); ?>
							</strong>
						</td>
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

						<?php // ---------- Πρόταση 1: mini chart τάσης ---------- ?>
			<div class="rs-trend-head">
				<h2 class="rs-h2"><?php esc_html_e( 'Τάση (μήνα με μήνα)', 'revenue-splitter' ); ?></h2>
				<form method="get" class="rs-trend-range">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG_DASH ); ?>" />
					<input type="hidden" name="rs_period" value="<?php echo esc_attr( $per['preset'] ); ?>" />
					<input type="hidden" name="rs_start" value="<?php echo esc_attr( $per['start'] ); ?>" />
					<input type="hidden" name="rs_end" value="<?php echo esc_attr( $per['end'] ); ?>" />
					<input type="hidden" name="rs_ben" value="<?php echo esc_attr( $ben ); ?>" />
					<?php foreach ( $pids as $tpid ) : ?>
						<input type="hidden" name="rs_product[]" value="<?php echo esc_attr( (string) $tpid ); ?>" />
					<?php endforeach; ?>
					<select name="rs_chart" onchange="this.form.submit()">
						<option value="6"<?php selected( $trend_n, 6 ); ?>><?php esc_html_e( '6 μήνες', 'revenue-splitter' ); ?></option>
						<option value="12"<?php selected( $trend_n, 12 ); ?>><?php esc_html_e( '12 μήνες', 'revenue-splitter' ); ?></option>
					</select>
				</form>
			</div>
			<div class="rs-trend">
				<?php foreach ( $trend as $t ) :
					$hn = ( $max_net > 0 ) ? max( 2, (int) round( ( (float) $t['net'] / $max_net ) * 100 ) ) : 2;
					$hs = ( $max_net > 0 ) ? max( 2, (int) round( ( (float) $t['shares'] / $max_net ) * 100 ) ) : 2;
					?>
					<div class="rs-trend-col" title="<?php echo esc_attr( $t['label'] . ' — ' . $cur( (float) $t['net'] ) . ' / ' . $cur( (float) $t['shares'] ) ); ?>">
						<div class="rs-trend-pair">
							<div class="rs-bar-net" style="height:<?php echo esc_attr( (string) $hn ); ?>%;"></div>
							<div class="rs-bar-sh" style="height:<?php echo esc_attr( (string) $hs ); ?>%;"></div>
						</div>
						<small><?php echo esc_html( $t['label'] ); ?></small>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="rs-prod-hint">
				<span class="rs-dot" style="background:#6d4aff;"></span>
				<?php esc_html_e( 'Καθαρό', 'revenue-splitter' ); ?>
				&nbsp;&nbsp;<span class="rs-dot" style="background:#a78bfa;"></span>
				<?php echo esc_html( '' !== $ben ? __( 'Μερίδιο δικαιούχου', 'revenue-splitter' ) : __( 'Σύνολο μεριδίων', 'revenue-splitter' ) ); ?>
			</p>

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

		// v1.3.8 (#7): λίστα καναλιών πώλησης ('' = κανένα ορισμένο).
		$channels = self::get_channels();

		// v1.3.8 (#1): διαφορετικός έλεγχος στο select του checked-styling
		// (= selected attrs). Οι δικαιούχοι: collect_names() ∪ όσοι έχουν
		// ήδη custom χρώμα (γράφτηκε παλαιότερα και έχει παραμείνει).
		$color_people = array();
		foreach ( RS_Beneficiaries::collect_names() as $n ) {
			$color_people[ $n ] = true;
		}
		foreach ( array_keys( self::get_color_map() ) as $n ) {
			$color_people[ $n ] = true;
		}
		$color_people = array_keys( $color_people );

		// vNext (#2): emails δικαιούχων — ίδιο σύνολο ονομάτων με τα
		// χρώματα (collect_names ∪ όσοι έχουν ήδη αποθηκευμένο email).
		$email_people = array();
		foreach ( RS_Beneficiaries::collect_names() as $n ) {
			$email_people[ $n ] = true;
		}
		foreach ( array_keys( RS_Emails::get_email_map() ) as $n ) {
			$email_people[ $n ] = true;
		}
		$email_people = array_keys( $email_people );

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

				<?php // ---------- v1.3.8 (#1): Χρώματα δικαιούχων ---------- ?>
				<h2 class="rs-h2"><?php esc_html_e( 'Χρώματα δικαιούχων', 'revenue-splitter' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Προσαρμοσμένο χρώμα ανά δικαιούχο — εμφανίζεται στα chips του καταμερισμού και στα ονόματα των πινάκων. Default: μωβ #6d4aff.', 'revenue-splitter' ); ?></p>
				<table class="rs-color-table">
					<tbody>
						<?php if ( empty( $color_people ) ) : ?>
							<tr><td class="rs-empty"><?php esc_html_e( 'Κανείς δεν έχει μερίδιο στην περίοδο.', 'revenue-splitter' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $color_people as $cp ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $cp ); ?></strong></td>
								<td>
									<input type="color" name="rs_ben_color[<?php echo esc_attr( md5( $cp ) ); ?>]"
										value="<?php echo esc_attr( self::ben_color( $cp ) ); ?>" />
								</td>
							</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
				<?php // Hidden mirror: όνομα ανά hash — ώστε το POST να έχει ακριβή name→color ζεύγη χωρίς attribute-injection επιφάνεια. ?>
				<?php foreach ( $color_people as $cp ) : ?>
					<input type="hidden" name="rs_ben_color_name[<?php echo esc_attr( md5( $cp ) ); ?>]" value="<?php echo esc_attr( $cp ); ?>" />
				<?php endforeach; ?>

				<?php // ---------- vNext (#1/#2): Emails δικαιούχων & μηνιαία αναφορά ---------- ?>
				<h2 class="rs-h2"><?php esc_html_e( 'Emails δικαιούχων & μηνιαία αναφορά', 'revenue-splitter' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Το email χρησιμοποιείται για την αποστολή νέου κλειδιού portal (ροή «Ξέχασα το κλειδί») και για τη μηνιαία αναφορά πωλήσεων (στέλνεται τις πρώτες μέρες κάθε μήνα για τον προηγούμενο). Ο συγγραφέας μπορεί να ενεργοποιήσει/απενεργοποιήσει την αναφορά και μόνος του από το portal του.', 'revenue-splitter' ); ?></p>
				<table class="rs-color-table">
					<tbody>
						<?php if ( empty( $email_people ) ) : ?>
							<tr><td class="rs-empty"><?php esc_html_e( 'Κανείς δεν έχει μερίδιο στην περίοδο.', 'revenue-splitter' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $email_people as $ep ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $ep ); ?></strong></td>
								<td>
									<input type="email" name="rs_ben_email[<?php echo esc_attr( md5( $ep ) ); ?>]"
										value="<?php echo esc_attr( RS_Emails::get_email( $ep ) ); ?>"
										placeholder="name@example.com" class="regular-text" />
								</td>
								<td>
									<label>
										<input type="checkbox" name="rs_ben_report[<?php echo esc_attr( md5( $ep ) ); ?>]" value="1" <?php checked( RS_Emails::is_opted_in( $ep ) ); ?> />
										<?php esc_html_e( 'Μηνιαία αναφορά', 'revenue-splitter' ); ?>
									</label>
								</td>
							</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
				<?php // Hidden mirror: ίδιο anti-attribute-injection pattern με τα χρώματα. ?>
				<?php foreach ( $email_people as $ep ) : ?>
					<input type="hidden" name="rs_ben_email_name[<?php echo esc_attr( md5( $ep ) ); ?>]" value="<?php echo esc_attr( $ep ); ?>" />
				<?php endforeach; ?>

				<h2 class="rs-h2"><?php esc_html_e( 'Έναρξη καταγραφής πωλήσεων', 'revenue-splitter' ); ?></h2>
				<p>
					<input type="date" name="rs_sales_since" value="<?php echo esc_attr( $sales_since ); ?>" />
					<span class="description">
						<?php esc_html_e( 'Το plugin μετράει πωλήσεις ΚΑΙ ποσοστά ΜΟΝΟ από αυτή την ημερομηνία και μετά. Κενό = χωρίς όριο (καταγράφεται όλο το ιστορικό). Χρήσιμο για καθαρή εκκίνηση χωρίς να διαγραφούν παλιές παραγγελίες.', 'revenue-splitter' ); ?>
					</span>
				</p>

				<?php // ---------- v1.3.8 (#7): Κανάλια πώλησης ---------- ?>
				<h2 class="rs-h2"><?php esc_html_e( 'Κανάλια πώλησης', 'revenue-splitter' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Προεπιλεγμένη λίστα καναλιών για το checkout (όταν εφαρμόζεται κουπόνι δωρεάν αντιτύπου) και για τη χειροκίνητη εισαγωγή εσόδων στο ledger. Μία γραμμή ανά κανάλι.', 'revenue-splitter' ); ?></p>
							<textarea name="rs_channels" rows="4" class="large-text code"
				placeholder="<?php echo esc_attr( __( "Βιβλιοπωλείο\nΕκδηλώσεις\nOnline\nΧονδρική", 'revenue-splitter' ) ); ?>"><?php echo esc_textarea( implode( "\n", $channels ) ); ?></textarea>

			<?php // ---------- v1.3.8 (#7 στάδιο 3): Default κανάλι ---------- ?>
			<p style="margin-top:12px;">
				<label for="rs-default-channel"><strong><?php esc_html_e( 'Default κανάλι (παραγγελίες χωρίς μαρκάρισμα)', 'revenue-splitter' ); ?></strong></label><br />
				<input type="text" id="rs-default-channel" name="rs_default_channel" class="regular-text"
					maxlength="100"
					placeholder="<?php echo esc_attr( self::FALLBACK_CHANNEL ); ?>"
					value="<?php echo esc_attr( self::default_channel() ); ?>" />
				<span class="description"><?php esc_html_e( 'Σε αυτό το κανάλι καταμετρώνται όλες οι κανονικές παραγγελίες του καταστήματος που δεν έχουν μαρκαριστεί με κανάλι (π.χ. από κουπόνι στο checkout). Κενό = «Κατάστημα/Online».', 'revenue-splitter' ); ?></span>
			</p>

				<h2 class="rs-h2"><?php esc_html_e( 'Κουπόνια δωρεάν αντιτύπων', 'revenue-splitter' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Όταν στο checkout εφαρμόζεται οποιοδήποτε από αυτά τα κουπόνια, ο πελάτης υποχρεούται να επιλέξει κανάλι πώλησης από τη λίστα των καναλιών. Διαχωρισμός με κόμμα.', 'revenue-splitter' ); ?></p>
				<input type="text" name="rs_reason_coupons" class="large-text"
					placeholder="<?php esc_attr_e( 'FREEBOOK, REVIEWCOPY', 'revenue-splitter' ); ?>"
					value="<?php echo esc_attr( $coupons ); ?>" />

				<p style="margin-top:20px;">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'revenue-splitter' ); ?></button>
				</p>
			</form>

			<?php // ---------- vNext (#4): Δοκιμαστικό email ---------- ?>
			<h2 class="rs-h2"><?php esc_html_e( 'Δοκιμή αποστολής email', 'revenue-splitter' ); ?></h2>
			<form method="post" style="display:inline;">
				<?php wp_nonce_field( 'rs_test_email', 'rs_test_email_nonce' ); ?>
				<input type="email" name="rs_test_to" class="regular-text" list="rs-test-emails"
					placeholder="name@example.com"
					value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" />
				<?php // Quick-select: τα αποθηκευμένα emails δικαιούχων ως datalist προτάσεις. ?>
				<datalist id="rs-test-emails">
					<?php foreach ( RS_Emails::get_email_map() as $test_mail ) : ?>
						<option value="<?php echo esc_attr( $test_mail ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
				<button type="submit" name="rs_test_email_send" value="1" class="button">
					<?php esc_html_e( 'Αποστολή δοκιμαστικού email', 'revenue-splitter' ); ?>
				</button>
				<p class="description" style="margin-top:6px;">
					<?php esc_html_e( 'Στέλνει ένα απλό δοκιμαστικό email για να επιβεβαιώσεις ότι η αποστολή (και άρα η μηνιαία αναφορά) φτάνει σε παραλήπτη.', 'revenue-splitter' ); ?>
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
				<?php esc_html_e( 'Συμπεριλαμβάνονται: ΦΠΑ default, global δικαιούχοι, χρώματα δικαιούχων, emails δικαιούχων, opt-in μηνιαίας αναφοράς, κανάλια πώλησης, κλειδιά portal (hashed), ledger (πληρωμές & έξτρα έσοδα), κουπόνια, ημερομηνία έναρξης, καταμερισμός/ΦΠΑ ανά προϊόν, αιτιολογίες δωρεάν αντιτύπων και γλώσσες χρηστών. Η εισαγωγή ΑΝΤΙΚΑΘΙΣΤΑ τα αντίστοιχα δεδομένα.', 'revenue-splitter' ); ?>
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

		// ---------- vNext (#4): Δοκιμαστικό email (ξεχωριστό POST + PRG) ----------
		if ( isset( $_POST['rs_test_email_send'] ) ) {

			if ( ! isset( $_POST['rs_test_email_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rs_test_email_nonce'] ) ), 'rs_test_email' )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
			}

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- is_email validation παρακάτω.
			$to   = isset( $_POST['rs_test_to'] ) ? sanitize_email( wp_unslash( $_POST['rs_test_to'] ) ) : '';
			$note = self::send_test_email( $to );

			set_transient( 'rs_aui_msg_' . get_current_user_id(), array( $note ), 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG_SET ) );
			exit;
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

		// ---------- v1.3.8 (#1): Χρώματα δικαιούχων ----------
		// POST σχήμα: rs_ben_color[md5(όνομα)] => '#rrggbb' (από <input type=color>)
		//             rs_ben_color_name[md5(όνομα)] => όνομα (hidden mirror)
		// Η σύζευξη γίνεται ΜΟΝΑΔΙΚΑ μέσω του md5 key — ουδέποτε
		// εμπιστευόμαστε το όνομα απευθείας από το name attribute.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- arrays validated παρακάτω.
		$colors_in  = isset( $_POST['rs_ben_color'] ) && is_array( $_POST['rs_ben_color'] ) ? wp_unslash( $_POST['rs_ben_color'] ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- arrays validated παρακάτω.
		$names_in   = isset( $_POST['rs_ben_color_name'] ) && is_array( $_POST['rs_ben_color_name'] ) ? wp_unslash( $_POST['rs_ben_color_name'] ) : array();

		$color_map = array();
		$bad_hex   = false;

		foreach ( $names_in as $hash => $name ) {
			$name = sanitize_text_field( (string) $name );
			$hex  = isset( $colors_in[ $hash ] ) ? (string) $colors_in[ $hash ] : '';

			if ( '' === $name ) {
				continue; // Κενό όνομα (διαγραμμένη γραμμή) — παραλείπεται.
			}

			// STRICT: #RRGGBB μόνο — οτιδήποτε άλλο ΔΕΝ γράφεται ποτέ.
			if ( 1 !== preg_match( '/^#[0-9a-fA-F]{6}$/', $hex ) ) {
				$bad_hex = true;
				continue;
			}

			// Αγνοούμε όσα είναι ΊΣΑ με το default (#6d4aff) — καθαρό
			// option χωρίς περιττές εγγραφές.
			if ( '#6d4aff' === strtolower( $hex ) ) {
				continue;
			}

			$color_map[ $name ] = strtolower( $hex );
		}

		if ( $bad_hex ) {
			$notices[] = array(
				'type' => 'error',
				'text' => __( 'Μη έγκυρο χρώμα δικαιούχου (απαιτείται #RRGGBB).', 'revenue-splitter' ),
			);
		}

		if ( empty( $color_map ) ) {
			delete_option( self::OPT_COLORS );
		} else {
			update_option( self::OPT_COLORS, wp_json_encode( $color_map, JSON_UNESCAPED_UNICODE ) );
		}

		// ---------- vNext (#1/#2): Emails δικαιούχων + opt-in μηνιαίας ---------- //
		// POST σχήμα: rs_ben_email[md5(όνομα)] => email
		//             rs_ben_email_name[md5(όνομα)] => όνομα (hidden mirror)
		//             rs_ben_report[md5(όνομα)] => '1' (checkbox opt-in)
		// Ίδιο md5-key coupling με τα χρώματα — το όνομα ΔΕΝ εμπιστεύεται
		// ποτέ από name attribute, μόνο από το hidden mirror.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- arrays validated παρακάτω.
		$emails_in  = isset( $_POST['rs_ben_email'] ) && is_array( $_POST['rs_ben_email'] ) ? wp_unslash( $_POST['rs_ben_email'] ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- arrays validated παρακάτω.
		$email_names_in = isset( $_POST['rs_ben_email_name'] ) && is_array( $_POST['rs_ben_email_name'] ) ? wp_unslash( $_POST['rs_ben_email_name'] ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- arrays validated παρακάτω.
		$reports_in  = isset( $_POST['rs_ben_report'] ) && is_array( $_POST['rs_ben_report'] ) ? wp_unslash( $_POST['rs_ben_report'] ) : array();

		$email_map = array();
		$bad_email = false;

		foreach ( $email_names_in as $hash => $name ) {
			$name = sanitize_text_field( (string) $name );
			$mail = isset( $emails_in[ $hash ] ) ? strtolower( trim( (string) $emails_in[ $hash ] ) ) : '';

			if ( '' === $name || '' === $mail ) {
				continue; // Κενό όνομα/email — παραλείπεται.
			}

			// STRICT: is_email μόνο — άκυρο => notice, οι υπόλοιποι σώζονται.
			if ( false === is_email( $mail ) ) {
				$bad_email = true;
				continue;
			}

			$email_map[ $name ] = $mail;
		}

		if ( $bad_email ) {
			$notices[] = array(
				'type' => 'error',
				'text' => __( 'Μη έγκυρο email δικαιούχου (τα υπόλοιπα αποθηκεύτηκαν κανονικά).', 'revenue-splitter' ),
			);
		}

		if ( empty( $email_map ) ) {
			delete_option( RS_Emails::OPT_EMAILS );
		} else {
			update_option( RS_Emails::OPT_EMAILS, wp_json_encode( $email_map, JSON_UNESCAPED_UNICODE ) );
		}

		// Opt-in: ΜΟΝΟ ονόματα ΜΕ έγκυρο email στο (νέο) map — ενιαία
		// άμυνα με το RS_Emails::set_optin του portal.
		$report_map = array();
		foreach ( $email_names_in as $hash => $name ) {
			$name = sanitize_text_field( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			if ( isset( $reports_in[ $hash ] ) && isset( $email_map[ $name ] ) ) {
				$report_map[ $name ] = 1;
			}
		}

		if ( empty( $report_map ) ) {
			delete_option( RS_Emails::OPT_REPORTS );
		} else {
			update_option( RS_Emails::OPT_REPORTS, wp_json_encode( $report_map, JSON_UNESCAPED_UNICODE ) );
		}

		// ---------- v1.3.8 (#7): Κανάλια πώλησης ----------
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- custom cleaning παρακάτω.
		$channels_raw = isset( $_POST['rs_channels'] ) ? (string) wp_unslash( $_POST['rs_channels'] ) : '';

				$channels = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $channels_raw ) as $ch_line ) {
			$ch_line = trim( sanitize_text_field( (string) $ch_line ) );
			if ( mb_strlen( $ch_line ) > 100 ) {
				$ch_line = mb_substr( $ch_line, 0, 100 ); // Όριο 100 χαρακτήρες/κανάλι.
			}
			if ( '' !== $ch_line ) {
				$channels[] = $ch_line;
			}
		}
		$channels = array_values( array_unique( $channels ) );

		if ( empty( $channels ) ) {
			delete_option( self::OPT_CHANNELS );
		} else {
			update_option( self::OPT_CHANNELS, wp_json_encode( $channels, JSON_UNESCAPED_UNICODE ) );
		}

		// ---------- v1.3.8 (#7 στάδιο 3): Default κανάλι ----------
		// Κενό = το fallback constant («Κατάστημα/Online»).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized παρακάτω.
		$def_ch = isset( $_POST['rs_default_channel'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['rs_default_channel'] ) ) ) : '';

		if ( '' !== $def_ch ) {
			$def_ch = mb_substr( $def_ch, 0, 100 );
			update_option( self::OPT_DEFAULT_CHANNEL, $def_ch );
		} else {
			delete_option( self::OPT_DEFAULT_CHANNEL );
		}

		// Το default κανάλι επηρεάζει την ομαδοποίηση «Ανά κανάλι» στα
		// cached reports (RS_Reports::run) — απαιτείται invalidate.
		do_action( 'rs_invalidate_cache' );

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

		update_option( self::OPT_COUPONS, self::clean_coupon_codes( $codes ) );

		if ( empty( $notices ) ) {
			$notices[] = array(
				'type' => 'success',
				'text' => __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'revenue-splitter' ),
			);
		}

		return $notices;
	}

	/**
	 * v1.7.0: Κανονικοποίηση λίστας κωδικών κουπονιών (comma-separated)
	 * με ΤΟΝ ΙΔΙΟ τρόπο που το WooCommerce κανονικοποιεί τους κωδικούς
	 * (wc_format_coupon_code → html_entity_decode + wc_sanitize_coupon_code
	 * + wc_strtolower, multibyte-safe). Το παλιό sanitize_key(strtoupper())
	 * έκοβε κενά/μη-ASCII χαρακτήρες (π.χ. «ΔΩΡΟ 2025» → «2025»).
	 */
	public static function clean_coupon_codes( string $raw ): string {

		$clean = array();

		foreach ( explode( ',', $raw ) as $c ) {
			$c = self::normalize_coupon_code( $c );
			if ( '' !== $c ) {
				$clean[] = $c;
			}
		}

		return implode( ',', array_unique( $clean ) );
	}

	/** Ένας κωδικός κουπονιού σε μορφή σύγκρισης του WooCommerce. */
	public static function normalize_coupon_code( string $code ): string {

		$code = trim( sanitize_text_field( $code ) );

		if ( function_exists( 'wc_format_coupon_code' ) ) {
			$code = (string) wc_format_coupon_code( $code );
		}

		return function_exists( 'wc_strtolower' )
			? trim( (string) wc_strtolower( $code ) )
			: trim( mb_strtolower( $code, 'UTF-8' ) );
	}

	/**
	 * vNext (#4): Δοκιμαστικό email (PRG handler → RS_Emails::send_test()).
	 *
	 * Thin delegator: ΜΟΝΟ validation του παραλήπτη + formatting του
	 * notice. Το ίδιο το email (σύνολο branding, headers, dispatch)
	 * ζει στο RS_Emails — ενιαία οδός με monthly/key-reset emails.
	 *
	 * @return array notice {type, text}
	 */
	private static function send_test_email( string $to ): array {

		if ( '' === $to || false === is_email( $to ) ) {
			return array(
				'type' => 'error',
				'text' => __( 'Μη έγκυρη διεύθυνση παραλήπτη για το δοκιμαστικό email.', 'revenue-splitter' ),
			);
		}

		$sent = RS_Emails::send_test( $to );

		if ( $sent ) {
			return array(
				'type' => 'success',
				'text' => sprintf(
					/* translators: %s: παραλήπτης */
					__( 'Το δοκιμαστικό email στάλθηκε στο %s.', 'revenue-splitter' ),
					$to
				),
			);
		}

		return array(
			'type' => 'error',
			'text' => __( 'Η αποστολή απέτυχε — έλεγξε τις ρυθμίσεις email του WordPress (π.χ. SMTP plugin ή PHP mail).', 'revenue-splitter' ),
		);
	}

	/**
	 * v1.3.8 (#1): Το χάρτη χρωμάτων raw (όνομα => #rrggbb) — για render
	 * και για cross-reference με collect_names(). Public: το χρειάζεται
	 * και το RS_Portal (ίδια UI συνθήκη) + το Ledgers admin form σε
	 * μελλοντικό στάδιο. ΠΟΤΕ δεν επιστρέφει άκυρα hex (parsed+filtered).
	 */
	public static function get_color_map(): array {

		if ( null === self::$color_cache ) {
			$map = array();
			$raw = get_option( self::OPT_COLORS, '' );
			if ( is_string( $raw ) && '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					foreach ( $decoded as $k => $hex ) {
						if ( is_string( $k ) && is_string( $hex )
							&& 1 === preg_match( '/^#[0-9a-fA-F]{6}$/', $hex ) ) {
							$map[ $k ] = strtolower( $hex );
						}
					}
				}
			}
			self::$color_cache = $map;
		}

		return self::$color_cache;
	}

	/**
	 * v1.3.8 (#7): Λίστα καναλιών πώλησης (array strings, [] = κανένα).
	 * Public — το χρησιμοποιούν το RS_Checkout (dropdown στο checkout
	 * όταν εφαρμόζεται κουπόνι), το RS_Ledger (dropdown στη χειροκίνητη
	 * είσοδο εσόδου) και το RS_Portal (σε επόμενο στάδιο).
	 */
	public static function get_channels(): array {

		if ( null === self::$channels_cache ) {
			$channels = array();
			$raw      = get_option( self::OPT_CHANNELS, '' );
			if ( is_string( $raw ) && '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					foreach ( $decoded as $ch ) {
						if ( is_string( $ch ) && '' !== trim( $ch ) ) {
							$channels[] = trim( $ch );
						}
					}
				}
			}
			self::$channels_cache = $channels;
		}

		return self::$channels_cache;
	}
	
	
	/**
	 * v1.3.8 (#7 στάδιο 3): Το default κανάλι (pseudo «Κατάστημα/Online»)
	 * — ρυθμίσιμο στις Ρυθμίσεις, fallback στην constant. ΠΟΤΕ κενό.
	 */
	public static function default_channel(): string {

		if ( null === self::$default_channel_cache ) {
			$raw = trim( (string) get_option( self::OPT_DEFAULT_CHANNEL, '' ) );

			self::$default_channel_cache = ( '' !== $raw ) ? mb_substr( $raw, 0, 100 ) : self::FALLBACK_CHANNEL;
		}

		return self::$default_channel_cache;
	}

	/**
	 * v1.3.8 (#7 στάδιο 3): Γραμμές του πίνακα «Ανά κανάλι» —
	 * WooCommerce (report['channels']) + ledger (income/payment).
	 *
	 * ΠΑΝΤΑ σφαιρικός (απόφαση χρήστη): ΔΕΝ φιλτράρεται από το
	 * rs_ben — μόνο από περίοδο και φίλτρο προϊόντων.
	 *
	 * Σειρά: default κανάλι πρώτα, υπόλοιπα κατά (Καθαρό + Έσοδα) ↓.
	 * Ledger εγγραφές χωρίς κανάλι → γραμμή «— χωρίς κανάλι —»,
	 * ΜΟΝΟ όταν έχουν κίνηση στην περίοδο.
	 *
	 * @return array[] {channel,qty,free,gross,vat,net,income,payment}
	 */
	private static function channel_rows( array $report, array $per ): array {

		$default = self::default_channel();
		$rows     = array();

		$touch = static function ( string $name ) use ( &$rows ): void {
			if ( ! isset( $rows[ $name ] ) ) {
				$rows[ $name ] = array( 'qty' => 0, 'free' => 0, 'gross' => 0.0, 'vat' => 0.0, 'net' => 0.0, 'income' => 0.0, 'payment' => 0.0 );
			}
		};

		// 1) Πάντα παρόντα: default + κανάλια Ρυθμίσεων (και τα μηδενικά).
		$touch( $default );
		foreach ( self::get_channels() as $ch ) {
			$touch( $ch );
		}

		// 2) WooCommerce ανά κανάλι (και κανάλια που ΔΕΝ υπάρχουν πια
		//    στη λίστα — δεδομένα πάνω από ονομασίες).
		foreach ( ( $report['channels'] ?? array() ) as $c ) {
			$name = (string) ( $c['channel'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$touch( $name );
			$rows[ $name ]['qty']   += (int) $c['qty'];
			$rows[ $name ]['free']  += (int) $c['free'];
			$rows[ $name ]['gross'] += (float) $c['gross'];
			$rows[ $name ]['vat']   += (float) $c['vat'];
			$rows[ $name ]['net']   += (float) $c['net'];
		}

		// 3) Ledger ανά κανάλι ('' → «— χωρίς κανάλι —», μόνο με κίνηση).
		foreach ( RS_Ledger::channel_sums( $per['start'], $per['end'] ) as $ch => $sums ) {

			$has_motion = ( 0.0 !== (float) ( $sums['income'] ?? 0.0 ) )
						|| ( 0.0 !== (float) ( $sums['payment'] ?? 0.0 ) );
			if ( ! $has_motion ) {
				continue; // Κενό κλειδί χωρίς κίνηση → καμία γραμμή.
			}

			$name = ( '' === $ch ) ? __( '— χωρίς κανάλι —', 'revenue-splitter' ) : (string) $ch;
			$touch( $name );
			$rows[ $name ]['income']  += (float) ( $sums['income'] ?? 0.0 );
			$rows[ $name ]['payment'] += (float) ( $sums['payment'] ?? 0.0 );
		}

		// 4) Λίστα + ταξινόμηση: default πρώτα, υπόλοιπα κατά όγκο ↓.
		$list = array();
		foreach ( $rows as $name => $r ) {
			$r['channel'] = $name;
			$list[]       = $r;
		}

		usort(
			$list,
			static function ( $a, $b ) use ( $default ) {
				if ( $a['channel'] === $default ) {
					return -1;
				}
								if ( $b['channel'] === $default ) {
					return 1;
				}

				$ka = $a['net'] + $a['income'];
				$kb = $b['net'] + $b['income'];

				if ( $ka === $kb ) {
					return strcmp( $a['channel'], $b['channel'] );
				}

				return $kb <=> $ka;
			}
		);

		return $list;
	}
	
	
	
	/**
	 * Πρόταση 1: μηνιαία τάση (6|12 μήνες) — Καθαρό + μερίδια.
	 *
	 * SEMANTICS (ρήτο):
	 *  - «net» = καθαρό του ΜΗΝΑ, πάντα σύνολο καταστήματος (ΔΕΝ επηρεάζεται
	 *    από φίλτρο δικαιούχου ούτε από φίλτρο προϊόντων — η τάση είναι
	 *    store-wide μετρικό· εξοικονομεί και 12× queries ανά φίλτρο).
	 *  - «shares»: χωρίς φίλτρο δικαιούχου = σύνολο μεριδίων (≈ net).
	 *    Με φίλτρο = ΜΟΝΟ ο επιλεγμένος δικαιούχος (μετρικά διαχωρισμένα
	 *    στατικά — όπως στο φίλτρο #3).
	 *  - Το φίλτρο προϊόντων ΔΕΝ εφαρμόζεται στο trend (ΣΚΟΠΙΜΟ — lower
	 *    cost, ενιαία εικόνα).
	 *  - Clamp στο rs_sales_since + caching 5'/μήνα: μέσα στο run().
	 *
	 * @return array[] {label:string, net:float, shares:float}
	 */
	private static function monthly_trend( int $months, string $ben = '' ): array {

		$now = new DateTimeImmutable( 'now', wp_timezone() );
		$out = array();

		for ( $i = $months - 1; $i >= 0; $i-- ) {

			$m = $now->modify( 'first day of this month' )->modify( "-{$i} months" );

			$rep = RS_Reports::run(
				array(
					'date_start' => $m->format( 'Y-m-01' ),
					'date_end'   => $m->format( 'Y-m-t' ),
				)
			);

			$shares = 0.0;
			foreach ( $rep['beneficiaries'] as $b ) {
				if ( '' === $ben || $b['name'] === $ben ) {
					$shares += (float) $b['amount'];
				}
			}

			$out[] = array(
				'label'  => wp_date( 'M Y', $m->getTimestamp(), wp_timezone() ),
				'net'    => round( (float) $rep['totals']['net'], 2 ),
				'shares' => round( $shares, 2 ),
			);
		}

		return $out;
	}
	
		/**
	 * Το πλήρες state ως array — v1.3.6: υπερ-πλήρες.
	 *
	 *  - options:  whitelisted plugin options (ΦΠΑ default, global
	 *              δικαιούχοι, χρώματα δικαιούχων [v1.3.8], κανάλια
	 *              [v1.3.8], portal keys, ledger, κουπόνια, έναρξη).
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
					'title'   => (string) get_the_title( $pid ), // Πληροφοριακό — βοηθά να αναγνωρίσεις το προϊόν.
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
	 * v1.3.8 (#1): STRICT validation του rs_beneficiary_colors — JSON
	 * array όνομα => #RRGGBB (regex-checked ΜΟΝΟ). Άκυρο → error,
	 * το υπάρχον option παραμένει άθικτο.
	 * v1.3.8 (#7): STRICT validation του rs_channels — JSON array από
	 * strings ≤100 chars (sanitize_text_field καθαρισμός). Άκυρο →
	 * error, το υπάρχον option παραμένει άθικτο.
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

		// ---- v1.3.8 (#1): Χρώματα δικαιούχων (STRICT) ----
		if ( isset( $opts['rs_beneficiary_colors'] ) ) {

			$raw_c = $opts['rs_beneficiary_colors'];

			// Κενό = σκόπιμο σβήσιμο.
			if ( '' === $raw_c ) {
				delete_option( self::OPT_COLORS );
			} elseif ( is_string( $raw_c ) ) {
				$decoded = json_decode( $raw_c, true );

				$valid = is_array( $decoded );
				if ( $valid ) {
					foreach ( $decoded as $name => $hex ) {
						if ( ! is_string( $name ) || '' === $name
							|| ! is_string( $hex )
							|| 1 !== preg_match( '/^#[0-9a-fA-F]{6}$/', $hex ) ) {
							$valid = false;
							break;
						}
					}
				}

				if ( $valid ) {
					// Ίδιο normalization με το save_settings(): lowercase +
					// παράλειψη όσων ισούνται με το default (#6d4aff).
					$norm = array();
					foreach ( $decoded as $cname => $chex ) {
						$chex = strtolower( $chex );
						if ( '#6d4aff' === $chex ) {
							continue;
						}
						$norm[ $cname ] = $chex;
					}
					if ( empty( $norm ) ) {
						delete_option( self::OPT_COLORS );
					} else {
						update_option( self::OPT_COLORS, wp_json_encode( $norm, JSON_UNESCAPED_UNICODE ) );
					}
				} else {
					$notes[] = array( 'type' => 'error', 'text' => __( 'Μη έγκυρο blob χρωμάτων δικαιούχων (απαιτούνται #RRGGBB τιμές).', 'revenue-splitter' ) );
				}
			} else {
				$notes[] = array( 'type' => 'error', 'text' => __( 'Μη έγκυρο blob χρωμάτων δικαιούχων (απαιτούνται #RRGGBB τιμές).', 'revenue-splitter' ) );
			}
		}

		// ---- vNext (#2): Emails δικαιούχων (STRICT) ----
		if ( isset( $opts['rs_beneficiary_emails'] ) ) {

			$raw_e = $opts['rs_beneficiary_emails'];

			// Κενό = σκόπιμο σβήσιμο.
			if ( '' === $raw_e ) {
				delete_option( RS_Emails::OPT_EMAILS );
			} elseif ( is_string( $raw_e ) ) {
				$decoded = json_decode( $raw_e, true );

				$valid = is_array( $decoded );
				if ( $valid ) {
					foreach ( $decoded as $ename => $email ) {
						if ( ! is_string( $ename ) || '' === $ename
							|| ! is_string( $email ) || false === is_email( $email ) ) {
							$valid = false;
							break;
						}
					}
				}

				if ( $valid ) {
					// Ίδιο normalization με το save_settings(): lowercase.
					$norm = array();
					foreach ( $decoded as $ename => $email ) {
						$norm[ $ename ] = strtolower( $email );
					}
					update_option( RS_Emails::OPT_EMAILS, wp_json_encode( $norm, JSON_UNESCAPED_UNICODE ) );
				} else {
					$notes[] = array( 'type' => 'error', 'text' => __( 'Μη έγκυρο blob emails δικαιούχων.', 'revenue-splitter' ) );
				}
			} else {
				$notes[] = array( 'type' => 'error', 'text' => __( 'Μη έγκυρο blob emails δικαιούχων.', 'revenue-splitter' ) );
			}
		}

		// ---- vNext (#1): Opt-in μηνιαίας αναφοράς (STRICT) ----
		if ( isset( $opts['rs_email_reports'] ) ) {

			$raw_r = $opts['rs_email_reports'];

			// Κενό = σκόπιμο σβήσιμο.
			if ( '' === $raw_r ) {
				delete_option( RS_Emails::OPT_REPORTS );
			} elseif ( is_string( $raw_r ) ) {
				$decoded = json_decode( $raw_r, true );

				$valid = is_array( $decoded );
				if ( $valid ) {
					foreach ( $decoded as $rname => $on ) {
						if ( ! is_string( $rname ) || '' === $rname || 1 !== (int) $on ) {
							$valid = false;
							break;
						}
					}
				}

				if ( $valid ) {
					$norm = array();
					foreach ( $decoded as $rname => $on ) {
						$norm[ $rname ] = 1;
					}
					update_option( RS_Emails::OPT_REPORTS, wp_json_encode( $norm, JSON_UNESCAPED_UNICODE ) );
				} else {
					$notes[] = array( 'type' => 'error', 'text' => __( 'Μη έγκυρο blob opt-in μηνιαίας αναφοράς.', 'revenue-splitter' ) );
				}
			} else {
				$notes[] = array( 'type' => 'error', 'text' => __( 'Μη έγκυρο blob opt-in μηνιαίας αναφοράς.', 'revenue-splitter' ) );
			}
		}

		// ---- v1.3.8 (#7): Κανάλια πώλησης (STRICT) ----
		if ( isset( $opts['rs_channels'] ) ) {

			$raw_ch = $opts['rs_channels'];

			// Κενό = σκόπιμο σβήσιμο.
			if ( '' === $raw_ch ) {
				delete_option( self::OPT_CHANNELS );
			} elseif ( is_string( $raw_ch ) ) {
				$decoded = json_decode( $raw_ch, true );

				$valid = is_array( $decoded );
				if ( $valid ) {
					foreach ( $decoded as $ch ) {
						if ( ! is_string( $ch ) || '' === trim( $ch ) ) {
							$valid = false;
							break;
						}
					}
				}

				if ( $valid ) {
					$clean_channels = array();
					foreach ( $decoded as $ch ) {
						$ch = mb_substr( trim( sanitize_text_field( $ch ) ), 0, 100 );
						if ( '' !== $ch ) {
							$clean_channels[] = $ch;
						}
					}
					$clean_channels = array_values( array_unique( $clean_channels ) );

					if ( empty( $clean_channels ) ) {
						delete_option( self::OPT_CHANNELS );
					} else {
						update_option( self::OPT_CHANNELS, wp_json_encode( $clean_channels, JSON_UNESCAPED_UNICODE ) );
					}
				} else {
					$notes[] = array( 'type' => 'error', 'text' => __( 'Μη έγκυρο blob καναλιών πώλησης.', 'revenue-splitter' ) );
				}
			} else {
				$notes[] = array( 'type' => 'error', 'text' => __( 'Μη έγκυρο blob καναλιών πώλησης.', 'revenue-splitter' ) );
			}
		}
		
		
		// ---- v1.3.8 (#7 στάδιο 3): Default κανάλι (STRICT) ----
		if ( isset( $opts['rs_default_channel'] ) ) {

			if ( ! is_string( $opts['rs_default_channel'] ) ) {
				$notes[] = array( 'type' => 'error', 'text' => __( 'Μη έγκυρο default κανάλι.', 'revenue-splitter' ) );
			} else {
				$v = trim( sanitize_text_field( $opts['rs_default_channel'] ) );

				if ( '' === $v ) {
					delete_option( self::OPT_DEFAULT_CHANNEL ); // Σκόπιμο reset στο fallback.
				} else {
					update_option( self::OPT_DEFAULT_CHANNEL, mb_substr( $v, 0, 100 ) );
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

		// ---- Reason coupons ----
		if ( isset( $opts['rs_reason_coupons'] ) && is_string( $opts['rs_reason_coupons'] ) ) {
			update_option( self::OPT_COUPONS, self::clean_coupon_codes( $opts['rs_reason_coupons'] ) );
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
					// get_post() πιο κάτω θα την μάρκαρε άδικα ως
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

				if ( $applied > 0 ) {
					$notes[] = array(
						'type' => 'success',
						'text' => sprintf(
							/* translators: %d: πλήθος εγγραφών */
							__( 'Προϊοντικά overrides: εφαρμόστηκαν %d εγγραφές.', 'revenue-splitter' ),
							$applied
						),
					);
				}

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

		// ---- Ledger: ATOMIC import (all-or-nothing, audit finding #1) ----
		// v1.7.0: το ledger εισάγεται ΜΕΤΑ τα global defaults, τα κανάλια
		// ΚΑΙ τα per-product overrides (postmeta) — αλλιώς εγγραφές για
		// δικαιούχους που υπάρχουν ΜΟΝΟ σε override προϊόντος απορρίπτονταν
		// ως «Άγνωστος δικαιούχος». Το invalidate καθαρίζει το static cache
		// του collect_names()/καναλιών πριν το validation.
		do_action( 'rs_invalidate_cache' );

		// Audit FIX: non-string rs_ledger (π.χ. tampered JSON) δεν αγνοείται
		// πια σιωπηλά — ρητό error, ενιαία με channels/colors/portal-keys.
		if ( isset( $opts['rs_ledger'] ) && ! is_string( $opts['rs_ledger'] ) ) {
			$notes[] = array( 'type' => 'error', 'text' => __( 'Μη έγκυρο blob ledger (JSON).', 'revenue-splitter' ) );
		}
		if ( isset( $opts['rs_ledger'] ) && is_string( $opts['rs_ledger'] ) ) {
			$decoded  = json_decode( $opts['rs_ledger'], true );
			$entries_ = is_array( $decoded ) ? $decoded : array();

			if ( null === $decoded && '' !== trim( $opts['rs_ledger'] ) ) {
				$notes[] = array( 'type' => 'error', 'text' => __( 'Μη έγκυρο blob ledger (JSON).', 'revenue-splitter' ) );
			} else {
				$result = RS_Ledger::import_bulk( $entries_ );

				if ( true === $result ) {
					$notes[] = array(
						'type' => 'success',
						'text' => sprintf(
							/* translators: %d: πλήθος εγγραφών */
							__( 'Ledger: εισήχθησαν %d εγγραφές (με πλήρη validation).', 'revenue-splitter' ),
							count( $entries_ )
						),
					);
				} else {
					foreach ( $result as $err ) {
						$notes[] = array( 'type' => 'error', 'text' => $err );
					}
					$notes[] = array( 'type' => 'error', 'text' => __( 'Ledger: ΚΑΜΙΑ αλλαγή δεν έγινε — το υπάρχον ledger παρέμεινε άθικτο.', 'revenue-splitter' ) );
				}
			}
		}

		// ---- v1.4.1 (#2): Backup χωρίς ledger section — ρητό info ----
		// Όταν απουσιάζει ΠΛΗΡΩΣ το rs_ledger key (π.χ. backup από
		// παλαιότερη έκδοση ή hand-made JSON), το υπάρχον ledger ΔΕΝ
		// αγγίζεται. Η απουσία πρέπει να είναι ορατή στο αποτέλεσμα
		// (replace-semantics διαφάνεια), όχι σιωπηλό skip — ενιαία
		// λογική με το notice «ΔΕΝ βρέθηκαν τα προϊόντα με IDs %s».
		if ( ! isset( $opts['rs_ledger'] ) ) {
			$notes[] = array(
				'type' => 'info',
				'text' => __( 'Το backup δεν περιέχει ledger — το υπάρχον ledger παρέμεινε άθικτο.', 'revenue-splitter' ),
			);
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

			// Upload health check: ρητό μήνυμα ανά περίπτωση — δεν
			// λένε όλα «δεν επιλέχθηκε αρχείο».
			$up_err  = isset( $_FILES['rs_import_file']['error'] ) ? (int) $_FILES['rs_import_file']['error'] : UPLOAD_ERR_NO_FILE;
			$up_size = isset( $_FILES['rs_import_file']['size'] ) ? (int) $_FILES['rs_import_file']['size'] : 0;

			if ( UPLOAD_ERR_INI_SIZE === $up_err || UPLOAD_ERR_FORM_SIZE === $up_err ) {
				$notices = array( array( 'type' => 'error', 'text' => __( 'Το αρχείο υπερβαίνει το όριο μεταφόρτωσης του server — δες το upload_max_filesize της PHP.', 'revenue-splitter' ) ) );
			} elseif ( UPLOAD_ERR_OK !== $up_err ) {
				$notices = array( array( 'type' => 'error', 'text' => __( 'Σφάλμα κατά τη μεταφόρτωση του αρχείου — δοκίμασε ξανά.', 'revenue-splitter' ) ) );
			} elseif ( $up_size <= 0 || $up_size > 67108864 ) { // 64 MB.
				$notices = array( array( 'type' => 'error', 'text' => __( 'Μη αποδεκτό μέγεθος αρχείου (όριο 64 MB).', 'revenue-splitter' ) ) );
			}

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON payload, json_decoded ως array.
			if ( UPLOAD_ERR_OK === $up_err
				&& $up_size > 0 && $up_size <= 67108864
				&& ! empty( $_FILES['rs_import_file']['tmp_name'] )
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
	 * Exports (admin_init + triple gating)
	 *
	 * v1.3.8 (#2/#3): exports λαμβάνουν ΟΛΑ τα ενεργά φίλτρα:
	 *  - rs_product[] (multi) ή legacy rs_product (scalar)
	 *  - rs_ben (φίλτρο δικαιούχου, whitelist-validated)
	 * Εμφανιζόμενες ημερομηνίες στα el → d/m/Y (#5). Filenames και
	 * εσωτερικά params παραμένουν ISO Y-m-d.
	 * =================================================================== */

	private static function export_url( string $fmt, array $per, array $pids = array(), string $ben = '' ): string {

		$url = admin_url( 'admin.php?page=' . self::SLUG_DASH
			. '&rs_export=' . rawurlencode( $fmt )
			. '&rs_period=' . rawurlencode( $per['preset'] )
			. '&rs_start=' . rawurlencode( $per['start'] )
			. '&rs_end=' . rawurlencode( $per['end'] ) );

		// v1.3.8 (#2): πολλά rs_product[] params — canonical sorted order
		// (ίδιο pattern με current_products()).
		$sorted = $pids;
		sort( $sorted );
		foreach ( $sorted as $pid ) {
			$url .= '&rs_product[]=' . rawurlencode( (string) $pid );
		}

		// v1.3.8 (#3): φίλτρο δικαιούχου.
		if ( '' !== $ben ) {
			$url .= '&rs_ben=' . rawurlencode( $ben );
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

		// Strict whitelist: άγνωστο format ΔΕΝ πέφτει σιωπηλά στο JSON.
		if ( ! in_array( $fmt, array( 'csv', 'xls', 'html', 'json' ), true ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}

		$per = self::current_period();

		// v1.3.8 (#2/#3): ίδια φίλτρα με το dashboard — ΜΙΑ υλοποίηση
		// ερμηνείας (current_products/current_beneficiary), ώστε export
		// = ακριβώς ό,τι βλέπεις στην οθόνη.
		$pids = self::current_products();
		$ben  = self::current_beneficiary();

		$run = array(
			'date_start' => $per['start'],
			'date_end'   => $per['end'],
		);
		if ( ! empty( $pids ) ) {
			$run['product_ids'] = $pids;
		}

		$report = RS_Reports::run( $run );
		$report = self::apply_ben_filter( $report, $ben );

		$fname = 'revenue-splitter-' . $per['start'] . '_' . $per['end'];

		// v1.3.8 (#7 στάδιο 3): exports παίρνουν ΚΑΙ το block «Ανά κανάλι».
				$channel_rows = self::channel_rows( $report, $per );

		switch ( $fmt ) {
			case 'csv':
				self::stream_csv( $report, $fname, $channel_rows );
				exit;
			case 'xls':
				self::stream_xls( $report, $per, $fname, $channel_rows );
				exit;
			case 'html':
				self::stream_html( $report, $per, $fname, $channel_rows );
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
	
		/** Header του block «Ανά κανάλι» (exports). */
	private static function channel_csv_header(): array {
		return array(
			__( 'Κανάλι', 'revenue-splitter' ),
			__( 'Τεμ.', 'revenue-splitter' ),
			__( 'Δωρεάν', 'revenue-splitter' ),
			__( 'Μικτό', 'revenue-splitter' ),
			__( 'ΦΠΑ', 'revenue-splitter' ),
			__( 'Καθαρό', 'revenue-splitter' ),
			__( 'Έσοδα εκτός πωλήσεων', 'revenue-splitter' ),
			__( 'Πληρωμές', 'revenue-splitter' ),
		);
	}

	/** Cells μίας γραμμής καναλιού (exports). */
	private static function channel_cells( array $cr ): array {
		return array(
			(string) $cr['channel'],
			(string) $cr['qty'],
			(string) $cr['free'],
			number_format( (float) $cr['gross'], 2, ',', '.' ),
			number_format( (float) $cr['vat'], 2, ',', '.' ),
			number_format( (float) $cr['net'], 2, ',', '.' ),
			number_format( (float) $cr['income'], 2, ',', '.' ),
			number_format( (float) $cr['payment'], 2, ',', '.' ),
		);
	}

	private static function stream_csv( array $report, string $fname, array $channel_rows = array() ): void {

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

				// ---- v1.3.8: δεύτερο block «Ανά κανάλι» ----
		fputcsv( $fh, array() );
		fputcsv( $fh, array( __( 'Ανά κανάλι', 'revenue-splitter' ) ) );
		fputcsv( $fh, self::channel_csv_header() );

		foreach ( $channel_rows as $cr ) {
			fputcsv( $fh, array_map( array( __CLASS__, 'csv_cell' ), self::channel_cells( $cr ) ) );
		}

		fclose( $fh );
		exit;
	}

	private static function stream_xls( array $report, array $per, string $fname, array $channel_rows = array() ): void {

		nocache_headers();
		header( 'Content-Type: application/vnd.ms-excel; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $fname . '.xls"' );

		// v1.3.8 (#5): εμφανιζόμενες ημερομηνίες d/m/Y στα el (DISPLAY-ONLY).
		$range = sprintf(
			/* translators: 1: από, 2: έως */
			__( 'Revenue Splitter — %1$s έως %2$s', 'revenue-splitter' ),
			RS_Lang::fmt_date( $per['start'] ),
			RS_Lang::fmt_date( $per['end'] )
		);

		echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';
		echo '<table border="1">';
		echo '<tr><th colspan="12">' . esc_html( $range ) . '</th></tr>';

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

		echo '<tr><th>' . esc_html__( 'ΣΥΝΟΛΑ', 'revenue-splitter' ) . '</th><th colspan="6"></th><th>' . esc_html( number_format_i18n( (float) $report['totals']['gross'], 2 ) ) . '</th><th>' . esc_html( number_format_i18n( (float) $report['totals']['vat'], 2 ) ) . '</th><th>' . esc_html( number_format_i18n( (float) $report['totals']['net'], 2 ) ) . '</th><th colspan="2"></th></tr>';

		echo '</table>';

		// v1.3.8: δεύτερο block — «Ανά κανάλι».
		echo '<h2>' . esc_html__( 'Ανά κανάλι', 'revenue-splitter' ) . '</h2>';
		echo '<table border="1">';
		echo '<tr>';
		foreach ( self::channel_csv_header() as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr>';
		foreach ( $channel_rows as $cr ) {
			echo '<tr>';
			foreach ( self::channel_cells( $cr ) as $cell ) {
				echo '<td>' . esc_html( $cell ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</table></body></html>';
		exit;
	}

	private static function stream_html( array $report, array $per, string $fname, array $channel_rows = array() ): void {

		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $fname . '.html"' );

		// v1.3.1 FIX (#18): lang attribute βάσει γλώσσας χρήστη.
		$lang = RS_Lang::get_lang();

		// v1.3.8 (#5): εμφανιζόμενες ημερομηνίες d/m/Y στα el (DISPLAY-ONLY).
		$range = sprintf(
			/* translators: 1: από, 2: έως */
			__( 'Revenue Splitter — %1$s έως %2$s', 'revenue-splitter' ),
			RS_Lang::fmt_date( $per['start'] ),
			RS_Lang::fmt_date( $per['end'] )
		);

		echo '<!DOCTYPE html><html lang="' . esc_attr( $lang ) . '"><head><meta charset="UTF-8">';
		echo '<title>' . esc_html( $range ) . '</title>';
		echo '<style>body{font-family:system-ui,sans-serif;margin:24px;color:#1d2327}table{border-collapse:collapse;width:100%}th,td{border:1px solid #c3c4c7;padding:6px 10px;text-align:left}th{background:#f0f0f1}td.num{text-align:right}</style>';
		echo '</head><body>';

		echo '<h1>' . esc_html( $range ) . '</h1>';

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

		echo '</tbody></table>';

		// v1.3.8: δεύτερο block — «Ανά κανάλι».
		echo '<h2>' . esc_html__( 'Ανά κανάλι', 'revenue-splitter' ) . '</h2>';
		echo '<table><thead><tr>';
		foreach ( self::channel_csv_header() as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $channel_rows as $cr ) {
			echo '<tr>';
			foreach ( self::channel_cells( $cr ) as $ci => $cell ) {
				echo '<td' . ( $ci > 0 ? ' class="num"' : '' ) . '>' . esc_html( $cell ) . '</td>';
			}
			echo '</tr>';
		}

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

			<p class="rs-widget-period">
				<strong><?php echo esc_html( $per['label'] ); ?></strong>
				<span class="rs-muted"> · <?php echo esc_html( RS_Lang::fmt_date( $per['start'] ) ); ?> → <?php echo esc_html( RS_Lang::fmt_date( $per['end'] ) ); ?></span>
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
						<?php // v1.3.8 (#1): custom χρώμα και στο widget. ?>
						<td><strong class="rs-chip-name rs-name-pill" style="background:<?php echo esc_attr( self::ben_color( $b['name'] ) ); ?>;color:<?php echo esc_attr( self::chip_fg( self::ben_color( $b['name'] ) ) ); ?>;"><?php echo esc_html( $b['name'] ); ?></strong></td>
						<td class="num"><?php echo esc_html( $cur( (float) $b['amount'] ) ); ?></td>
						<?php
						// v1.4.1 FIX: ενιαία σημασιολογία με το dashboard (sales + income − payments).
						$widget_remain = round(
							(float) $b['amount']
							+ RS_Ledger::sum( $b['name'], $per['start'], $per['end'], 'income' )
							- RS_Ledger::sum( $b['name'], $per['start'], $per['end'], 'payment' ),
							2
						);
						?>
						<td class="num"><?php echo esc_html( $cur( $widget_remain ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<p class="rs-widget-links">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_DASH ) ); ?>">
					<?php esc_html_e( '🤓 Dashboard', 'revenue-splitter' ); ?>
				</a>
				<span class="rs-muted"> · </span>
				<a class="rs-strong-link" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( '☕ Ko-fi', 'revenue-splitter' ); ?>
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

	/**
	 * Footer με clickable cross-links (v1.3.0) + Ko-fi support CTA.
	 *
	 * v1.7.0: public — το χρησιμοποιεί και η admin σελίδα του RS_Portal.
	 * Κοινό pattern του οικοσυστήματος Noxpress (Ko-fi + Dashboard links,
	 * μεταφράσιμο «Made with ❤»). Styles στο admin.css (.rs-footer, .rs-kofi).
	 */
	public static function footer(): void {
		?>
		<p class="rs-footer">
			<?php
			printf(
				/* translators: %s: όνομα δημιουργού */
				esc_html__( 'Made with ❤ by %s', 'revenue-splitter' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="rs-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="rs-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'revenue-splitter' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="rs-footer-cta">
			<a class="rs-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'revenue-splitter' ); ?>
			</a>
			<a class="rs-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_MENU ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'revenue-splitter' ); ?>
			</a>
		</p>
		<?php
	}
}

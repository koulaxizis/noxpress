<?php
/**
 * RS_Portal — Author Portal (Phase 6).
 *
 * Δημόσια σελίδα (shortcode [rs_portal]) όπου κάθε δικαιούχος βλέπει
 * τα δικά του καθαρά κέρδη ανά προϊόν και περίοδο, με Access Key —
 * χωρίς WP λογαριασμό, χωρίς tracking, χωρίς IP logs.
 *
 * Ροή:
 *  1. Ο admin ανοίγει το «Portal» submenu — κάθε γνωστός δικαιούχος
 *     παίρνει αυτόματα τυχαίο 64-char Access Key.
 *  2. Στέλνει το key στον συγγραφέα με ασφαλές κανάλι.
 *  3. Ο συγγραφέας μπαίνει στη σελίδα με το shortcode, βάζει το key,
 *    βλέπει τα κέρδη του για όποια περίοδο θέλει, κάνει export CSV.
 *
 * Ασφάλεια:
 *  - Access keys: wp_generate_password(64) — τυχαία, per-beneficiary.
 *  - Cookie: τυχαίο token 64 hex chars (ΔΕΝ είναι το access key).
 *    Ο server κρατά ΜΟΝΟ sha256(token) → όνομα δικαιούχου (transient 24h).
 *  - Cookie flags: HttpOnly + Secure (HTTPS) + SameSite=Lax.
 *  - Rate limit: 5 ΑΠΟΤΥΧΗΜΕΝΕΣ προσπάθειες / 15 min ανά IP.
 *    Μόνο sha256 hash του IP σε transient που λήγει — privacy-first.
 *    (v1.1.3 FIX: η rate_limit_fail() καλείται ΠΡΑΓΜΑΤΙΚΑ τώρα στο
 *    failed login — πριν ήταν νεκρός κώδικας.)
 *  - Logout μέσω POST + nonce (v1.1.3 FIX: όχι GET logout, anti-CSRF).
 *  - Ο δικαιούχος ΔΕΝ βλέπει: gross, ΦΠΑ, άλλους δικαιούχους,
 *    σύνολα καταστήματος. Μόνο τίτλο, τεμάχια, ποσοστό, ποσό του.
 *
 * ΣΗΜΑΝΤΙΚΟ: η σελίδα με το shortcode ΔΕΝ πρέπει να είναι page-cached
 * (WP Rocket / LiteSpeed κ.λπ. — πρόσθεσε exclusion για το URL της).
 *
 * Frontend γλώσσα: Ελληνικά (δικαιούχοι = ανώνυμοι επισκέπτες, ο
 * gettext/EN μηχανισμός αφορά admin-only sessions).
 */

defined( 'ABSPATH' ) || exit;

final class RS_Portal {

	const OPT_KEYS  = 'rs_portal_keys';
	const COOKIE    = 'rs_portal_token';

	const TOKEN_TTL = DAY_IN_SECONDS; // Συνεδρία portal: 24 ώρες.
	const RATE_MAX  = 5;              // Αποτυχημένες προσπάθειες ανά window.
	const RATE_WIN  = 900;            // 15 λεπτά.

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'catch_request' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_shortcode( 'rs_portal', array( __CLASS__, 'shortcode' ) );
	}

	/* =====================================================================
	 * ADMIN — σελίδα «Portal»
	 * =================================================================== */

	public static function admin_menu(): void {
		add_submenu_page(
			'revenue-splitter-dashboard',
			__( 'Revenue Splitter — Portal', 'revenue-splitter' ),
			__( 'Portal', 'revenue-splitter' ),
			RS_Admin_UI::CAP,
			'revenue-splitter-portal',
			array( __CLASS__, 'render_admin' )
		);
	}

	public static function render_admin(): void {

		if ( ! current_user_can( RS_Admin_UI::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}

		$message = '';

		// ---------- POST: regenerate ----------
		if ( isset( $_POST['rs_portal_nonce'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			&& wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rs_portal_nonce'] ) ), 'rs_portal_admin' ) ) {

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$regen = isset( $_POST['rs_regen'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_regen'] ) ) : '';

			if ( '' !== $regen && isset( self::get_keys()[ $regen ] ) ) {
				$keys           = self::get_keys();
				$keys[ $regen ] = self::generate_key();
				update_option( self::OPT_KEYS, wp_json_encode( $keys ) );

				$message = sprintf(
					/* translators: %s: beneficiary name */
					__( 'Νέο κλειδί για: %s — το παλιό σταματά να λειτουργεί άμεσα.', 'revenue-splitter' ),
					$regen
				);
			}
		}

		// Lazy-key creation: κάθε γνωστός δικαιούχος παίρνει κλειδί αυτόματα.
		// (v1.1.3: το κοινό helper στο RS_Beneficiaries — ένα σημείο αλήθειας.)
		$known = RS_Beneficiaries::collect_names();
		$keys  = self::get_keys();
		$dirty = false;

		foreach ( $known as $name ) {
			if ( ! isset( $keys[ $name ] ) ) {
				$keys[ $name ] = self::generate_key();
				$dirty         = true;
			}
		}
		if ( $dirty ) {
			update_option( self::OPT_KEYS, wp_json_encode( $keys ) );
		}
		?>
		<div class="wrap rs-wrap">

			<h1><?php esc_html_e( 'Revenue Splitter — Portal', 'revenue-splitter' ); ?></h1>

			<?php if ( '' !== $message ) : ?>
				<div class="notice notice-success is-dismissible inline"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Ο δικαιούχος μπαίνει σε σελίδα με το shortcode [rs_portal] και βλέπει ΜΟΝΟ τα δικά του κέρδη. Κανένα IP log, κανένα tracking — μόνο ένα λειτουργικό cookie token 24h.', 'revenue-splitter' ); ?>
			</p>

			<table class="widefat striped rs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Δικαιούχος', 'revenue-splitter' ); ?></th>
						<th><?php esc_html_e( 'Access Key', 'revenue-splitter' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $keys ) ) : ?>
					<tr>
						<td colspan="3" class="rs-empty">
							<?php esc_html_e( 'Δεν έχουν ρυθμιστεί δικαιούχοι ακόμη. Πήγαινε στις Ρυθμίσεις.', 'revenue-splitter' ); ?>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $keys as $name => $key ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $name ); ?></strong></td>
						<td><code style="user-select: all; word-break: break-all;"><?php echo esc_html( $key ); ?></code></td>
						<td>
							<form method="post" style="display:inline; margin:0;">
								<?php wp_nonce_field( 'rs_portal_admin', 'rs_portal_nonce' ); ?>
								<input type="hidden" name="rs_regen" value="<?php echo esc_attr( $name ); ?>" />
								<button type="submit" class="button button-small">
									<?php esc_html_e( 'Νέο κλειδί', 'revenue-splitter' ); ?>
								</button>
							</form>
						</td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<p class="description" style="margin-top:12px;">
				<strong><?php esc_html_e( 'Σημείωση:', 'revenue-splitter' ); ?></strong>
				<?php esc_html_e( '«Νέο κλειδί» αντικαθιστά το παλιό ΑΜΕΣΩΣ (αν κλεβεί, το ανανεώνεις και τέλος). Στείλε το με ασφαλές κανάλι.', 'revenue-splitter' ); ?>
			</p>

			<p class="rs-footer">
				Revenue Splitter v<?php echo esc_html( RS_VERSION ); ?> — Made with ♥ by Christos Koulaxizis
			</p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Keys / tokens / rate limit
	 * ------------------------------------------------------------------- */

	/** @return array<string,string> name => key */
	private static function get_keys(): array {
		$raw     = get_option( self::OPT_KEYS, '' );
		$decoded = ( '' !== $raw && is_string( $raw ) ) ? json_decode( $raw, true ) : null;
		return is_array( $decoded ) ? $decoded : array();
	}

	/** 64 chars alphanumeric — αναγνώσιμο και στο τηλέφωνο. */
	private static function generate_key(): string {
		return wp_generate_password( 64, false, false );
	}

	/** sha256 του token — το ίδιο το token ΔΕΝ αποθηκεύεται ποτέ. */
	private static function token_hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/** Token από cookie, ή ''. 64-char hex μόνο. */
	private static function request_token(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- regex-validated παρακάτω.
		$t = isset( $_COOKIE[ self::COOKIE ] ) ? (string) $_COOKIE[ self::COOKIE ] : '';
		return preg_match( '/^[0-9a-f]{64}$/', $t ) ? $t : '';
	}

	/** Όνομα δικαιούχου αν το token είναι έγκυρο, αλλιώς ''. */
	public static function logged_in_beneficiary(): string {

		$token = self::request_token();
		if ( '' === $token ) {
			return '';
		}

		$name = get_transient( 'rs_tok_' . self::token_hash( $token ) );

		return is_string( $name ) && '' !== $name ? $name : '';
	}

	/**
	 * Έλεγχος rate limit (ΠΡΙΝ την επαλήθευση κλειδιού).
	 * Hash IP μόνο — ποτέ plaintext IP στη βάση.
	 */
	private static function rate_limit_gate(): bool {

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return true;
		}

		$key = 'rs_rl_' . hash( 'sha256', $ip );
		$n   = (int) get_transient( $key );

		return $n < self::RATE_MAX;
	}

	/**
	 * Καταγραφή ΑΠΟΤΥΧΗΜΕΝΗΣ προσπάθειας.
	 *
	 * v1.1.3 FIX (#2): τώρα καλείται ΠΡΑΓΜΑΤΙΚΑ στο failed login —
	 * πριν ο μετρητής υπήρχε ως νεκρός κώδικας και ο περιορισμός
	 * «5 / 15 λεπτά» δεν εφαρμοζόταν ποτέ.
	 */
	private static function rate_limit_fail(): void {

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return;
		}

		$key = 'rs_rl_' . hash( 'sha256', $ip );
		$n   = (int) get_transient( $key );

		set_transient( $key, $n + 1, self::RATE_WIN );
	}

	/** Πλήρες URL της τρέχουσας σελίδας. */
	private static function current_url(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw από κάτω.
		$req = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return home_url( esc_url_raw( $req ) );
	}

	/** Πλήρες URL της ίδιας σελίδας με overridden query args. */
	private static function portal_url( array $args ): string {
		return esc_url( add_query_arg( $args, self::current_url() ) );
	}

	/** Ταιριάζει submitted key με δικαιούχο (timing-safe). */
	private static function match_key( string $submitted ): string {

		if ( '' === $submitted ) {
			return '';
		}

		foreach ( self::get_keys() as $name => $key ) {
			if ( hash_equals( (string) $key, $submitted ) ) {
				return (string) $name;
			}
		}

		return '';
	}

	/* ---------------------------------------------------------------------
	 * Frontend routing — template_redirect
	 * ------------------------------------------------------------------- */

	public static function catch_request(): void {

		// ---------- LOGOUT (POST + nonce — v1.1.3 FIX #7) ----------
		if ( isset( $_POST['rs_portal_logout'] )
			&& isset( $_POST['rs_logout_nonce'] )
			&& wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rs_logout_nonce'] ) ), 'rs_portal_logout' ) ) {

			$token = self::request_token();
			if ( '' !== $token ) {
				delete_transient( 'rs_tok_' . self::token_hash( $token ) );
			}

			setcookie(
				self::COOKIE,
				'',
				array(
					'expires'  => time() - 3600,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);

			wp_safe_redirect( esc_url_raw( remove_query_arg( array( 'rs_portal_logout', 'rs_portal_failed', 'rs_start', 'rs_end' ), self::current_url() ) ) );
			exit;
		}

		// ---------- LOGIN (POST) ----------
		if ( isset( $_POST['rs_portal_nonce'] )
			&& wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rs_portal_nonce'] ) ), 'rs_portal_login' ) ) {

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$submitted = isset( $_POST['rs_access_key'] ) ? trim( (string) wp_unslash( $_POST['rs_access_key'] ) ) : '';

			if ( ! self::rate_limit_gate() ) {
				wp_die(
					esc_html__( 'Πολλές αποτυχημένες προσπάθειες. Δοκίμασε ξανά σε 15 λεπτά.', 'revenue-splitter' ),
					'', array( 'response' => 429 )
				);
			}

			$name = self::match_key( $submitted );

			if ( '' !== $name ) {
				$token = bin2hex( random_bytes( 32 ) );

				set_transient( 'rs_tok_' . self::token_hash( $token ), $name, self::TOKEN_TTL );

				setcookie(
					self::COOKIE,
					$token,
					array(
						'expires'  => time() + self::TOKEN_TTL,
						'path'     => '/',
						'secure'   => is_ssl(),
						'httponly' => true,
						'samesite' => 'Lax',
					)
				);

				wp_safe_redirect( self::current_url() );
				exit;
			}

			// v1.1.3 FIX (#2): η αποτυχημένη προσπάθεια ΚΑΤΑΓΡΑΦΕΤΑΙ στον
			// rate limiter — αυτό ήταν το νεκρό σκέλος του κύκλου.
			self::rate_limit_fail();

			// Αποτυχημένο key → redirect με flag για το μήνυμα λάθους.
			wp_safe_redirect( esc_url_raw( add_query_arg( 'rs_portal_failed', '1', self::current_url() ) ) );
			exit;
		}

		// ---------- EXPORT CSV (GET) ----------
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- session-cookie gated παρακάτω.
		if ( isset( $_GET['rs_portal_export'] ) && 'csv' === $_GET['rs_portal_export'] ) {

			$who = self::logged_in_beneficiary();
			if ( '' === $who ) {
				wp_die( esc_html__( 'Η συνεδρία έληξε. Ξανασυνδέσου με το κλειδί σου.', 'revenue-splitter' ) );
			}

			$range = self::current_range();
			$view  = self::beneficiary_view( $who, $range['start'], $range['end'] );

			self::stream_csv( $who, $view );
		}
	}

	/** Range από GET (whitelisted regex + checkdate) ή default τρέχων μήνας. */
	private static function current_range(): array {

		$start = isset( $_GET['rs_start'] ) ? sanitize_text_field( wp_unslash( $_GET['rs_start'] ) ) : '';
		$end   = isset( $_GET['rs_end'] ) ? sanitize_text_field( wp_unslash( $_GET['rs_end'] ) ) : '';

		$valid = static function ( string $d ): bool {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
				return false;
			}
			return checkdate( (int) substr( $d, 5, 2 ), (int) substr( $d, 8, 2 ), (int) substr( $d, 0, 4 ) );
		};

		if ( $valid( $start ) && $valid( $end ) && $start <= $end ) {
			return array( 'start' => $start, 'end' => $end );
		}

		$now = new DateTimeImmutable( 'now', wp_timezone() );
		return array(
			'start' => $now->format( 'Y-m-01' ),
			'end'   => $now->format( 'Y-m-d' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Beneficiary view — δικά του ποσά ανά προϊόν
	 * ------------------------------------------------------------------- */

	/**
	 * Τι βλέπει ο δικαιούχος:
	 *  - ΜΟΝΟ τα δικά του splits (amount, percent)
	 *  - Τεκμήριο πωλήσεων: title, qty
	 *  - ΟΧΙ gross / ΦΠΑ / σύνολα site / άλλοι δικαιούχοι
	 *
	 * @return array{start:string,end:string,rows:array[],total:float}
	 */
	private static function beneficiary_view( string $who, string $start, string $end ): array {

		$report = RS_Reports::run(
			array(
				'date_start' => $start,
				'date_end'   => $end,
			)
		);

		$rows  = array();
		$total = 0.0;

		foreach ( $report['products'] as $p ) {
			foreach ( $p['splits'] as $s ) {

				if ( (string) $s['name'] !== $who ) {
					continue;
				}

				$rows[] = array(
					'title'   => (string) $p['title'],
					'qty'     => (int) $p['qty'],
					'percent' => (float) $s['percent'],
					'amount'  => round( (float) $s['amount'], 2 ),
				);

				$total += (float) $s['amount'];
			}
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return (float) $b['amount'] <=> (float) $a['amount'];
			}
		);

		return array(
			'start' => $start,
			'end'   => $end,
			'rows'  => $rows,
			'total' => round( $total, 2 ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Shortcode [rs_portal]
	 * ------------------------------------------------------------------- */

	/** Το inline CSS τυπώνεται μία φορά ανά σελίδα (guard). */
	private static $css_printed = false;

	public static function shortcode( $atts ): string {

		// ---------- Inline CSS (μία φορά ανά σελίδα) ----------
		if ( ! self::$css_printed ) {
			self::$css_printed = true;
			echo '<style>
.rs-portal{max-width:720px;margin:2em auto;font-family:inherit}
.rs-portal-card{background:#14161d;border:1px solid #2e3240;border-left:3px solid #6d4aff;border-radius:8px;padding:22px 26px;margin-bottom:16px;color:#eae8fa}
.rs-portal h2{margin:0 0 8px;color:#f6f4ff;font-size:1.3rem}
.rs-portal-muted,.rs-portal-label{color:#9b97b8;font-size:.92rem}
.rs-portal-hello{font-size:1.05rem;margin:0}
.rs-portal-login input[type=password]{width:100%;padding:10px 12px;background:#0f1115;border:1px solid #34394a;border-radius:4px;color:#f2f0fe;font-size:1rem;margin-bottom:10px;box-sizing:border-box}
.rs-portal-btn{display:inline-block;padding:9px 18px;background:#6d4aff;color:#fff;border:none;border-radius:6px;font-weight:500;text-decoration:none;cursor:pointer}
.rs-portal-btn:hover{background:#8263ff;color:#fff}
.rs-portal-btn-light{background:#1e2130;border:1px solid #4a4f66;color:#eeeafc}
.rs-portal-btn-light:hover{border-color:#6d4aff}
.rs-portal-error{color:#ff9a9a}
.rs-portal-period-links{display:flex;flex-wrap:wrap;gap:10px;margin-top:12px}
.rs-portal-period-links a{color:#beb1ff;text-decoration:none;font-size:.88rem;padding:4px 10px;border:1px solid #3a3f52;border-radius:4px}
.rs-portal-period-links a:hover{border-color:#6d4aff}
.rs-portal-total{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px}
.rs-portal-total-amount{font-size:1.5rem;color:#f6f4ff;font-variant-numeric:tabular-nums}
.rs-portal-table-wrap{overflow-x:auto}
.rs-portal-table{width:100%;border-collapse:collapse;font-size:.93rem}
.rs-portal-table th,.rs-portal-table td{padding:10px 12px;border-bottom:1px solid #23262f;text-align:left}
.rs-portal-table thead th{color:#e6e1fd;border-bottom:2px solid #3a3f52}
.rs-portal-table tfoot td{border-top:2px solid #3a3f52;color:#f6f4ff}
.rs-portal-table .num{text-align:right;font-variant-numeric:tabular-nums}
.rs-portal-empty{color:#9b97b8;font-style:italic;margin:0}
.rs-portal-actions{display:flex;gap:10px}
.rs-portal-footer{color:#9b97b8;font-size:.78rem;text-align:center;margin-top:8px}
@media(prefers-color-scheme:light){.rs-portal-card{background:#fafaff;border-color:#e3e0f5;color:#2a2233}.rs-portal h2,.rs-portal-table thead th,.rs-portal-table tfoot td,.rs-portal-total-amount{color:#2a2233}}
</style>';
		}

		$who = self::logged_in_beneficiary();

		ob_start();

		// ---------- Είσοδος ----------
		if ( '' === $who ) {
			self::render_login();
			return (string) ob_get_clean();
		}

		// ---------- Dashboard ----------
		$range = self::current_range();
		$view  = self::beneficiary_view( $who, $range['start'], $range['end'] );

		self::render_dashboard( $who, $view );

		return (string) ob_get_clean();
	}

	private static function render_login(): void {
		?>
		<div class="rs-portal">
			<div class="rs-portal-card rs-portal-login">
				<h2><?php esc_html_e( 'Author Portal', 'revenue-splitter' ); ?></h2>
				<p class="rs-portal-muted">
					<?php esc_html_e( 'Βάλε το προσωπικό σου κλειδί για να δεις τα καθαρά σου κέρδη.', 'revenue-splitter' ); ?>
				</p>

				<?php if ( isset( $_GET['rs_portal_failed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<p class="rs-portal-error"><?php esc_html_e( 'Λάθος κλειδί — δοκίμασε ξανά.', 'revenue-splitter' ); ?></p>
				<?php endif; ?>

				<form method="post">
					<?php wp_nonce_field( 'rs_portal_login', 'rs_portal_nonce' ); ?>
					<input type="password" name="rs_access_key" autocomplete="off"
						placeholder="<?php esc_attr_e( 'Access key', 'revenue-splitter' ); ?>"
						required minlength="32" maxlength="64" />
					<button type="submit" class="rs-portal-btn">
						<?php esc_html_e( 'Είσοδος', 'revenue-splitter' ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php
	}

	private static function render_dashboard( string $who, array $view ): void {

		$sym = function_exists( 'get_woocommerce_currency_symbol' )
			? get_woocommerce_currency_symbol()
			: '';
		$sym = html_entity_decode( (string) $sym, ENT_QUOTES, 'UTF-8' );

		?>
		<div class="rs-portal">
			<div class="rs-portal-card rs-portal-welcome">
				<h2><?php esc_html_e( 'Author Portal', 'revenue-splitter' ); ?></h2>
				<p class="rs-portal-hello">
					<?php
					printf(
						/* translators: %s: beneficiary name */
						esc_html__( 'Καλωσόρισες, %s!', 'revenue-splitter' ),
						esc_html( $who )
					);
					?>
				</p>
				<div class="rs-portal-period-links">
					<a href="<?php echo esc_url( self::month_url( 0 ) ); ?>"><?php esc_html_e( 'Τρέχων μήνας', 'revenue-splitter' ); ?></a>
					<a href="<?php echo esc_url( self::month_url( -1 ) ); ?>"><?php esc_html_e( 'Προηγούμενος μήνας', 'revenue-splitter' ); ?></a>
					<a href="<?php echo esc_url( self::year_url( 0 ) ); ?>"><?php esc_html_e( 'Τρέχον έτος', 'revenue-splitter' ); ?></a>
					<a href="<?php echo esc_url( self::year_url( -1 ) ); ?>"><?php esc_html_e( 'Προηγούμενο έτος', 'revenue-splitter' ); ?></a>
				</div>
			</div>

			<div class="rs-portal-card rs-portal-total">
				<span class="rs-portal-label">
					<?php esc_html_e( 'Περίοδος', 'revenue-splitter' ); ?>:
					<strong><?php echo esc_html( $view['start'] ); ?></strong> — <strong><?php echo esc_html( $view['end'] ); ?></strong>
				</span>
				<strong class="rs-portal-total-amount">
					<?php echo esc_html( number_format_i18n( $view['total'], 2 ) . ( '' !== $sym ? ' ' . $sym : '' ) ); ?>
				</strong>
			</div>

			<?php if ( empty( $view['rows'] ) ) : ?>
				<div class="rs-portal-card">
					<p class="rs-portal-empty"><?php esc_html_e( 'Καμία πώληση σε αυτή την περίοδο.', 'revenue-splitter' ); ?></p>
				</div>
			<?php else : ?>
				<div class="rs-portal-card rs-portal-table-wrap">
					<table class="rs-portal-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Προϊόν', 'revenue-splitter' ); ?></th>
								<th class="num"><?php esc_html_e( 'Τεμ.', 'revenue-splitter' ); ?></th>
								<th class="num"><?php esc_html_e( 'Ποσοστό σου', 'revenue-splitter' ); ?></th>
								<th class="num"><?php esc_html_e( 'Ποσό σου', 'revenue-splitter' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $view['rows'] as $r ) : ?>
							<tr>
								<td><?php echo esc_html( $r['title'] ); ?></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $r['qty'] ) ); ?></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $r['percent'], 2 ) ); ?>%</td>
								<td class="num"><strong><?php echo esc_html( number_format_i18n( $r['amount'], 2 ) ); ?></strong></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
						<tfoot>
							<tr>
								<td colspan="3"><?php esc_html_e( 'Σύνολο', 'revenue-splitter' ); ?></td>
								<td class="num"><strong><?php echo esc_html( number_format_i18n( $view['total'], 2 ) ); ?></strong></td>
							</tr>
						</tfoot>
					</table>
				</div>

				<div class="rs-portal-actions">
					<a class="rs-portal-btn rs-portal-btn-light" href="<?php echo esc_url( self::export_url() ); ?>">
						<?php esc_html_e( 'Εξαγωγή CSV', 'revenue-splitter' ); ?>
					</a>

					<!-- v1.1.3 FIX (#7): logout μέσω POST + nonce (όχι GET link). -->
					<form method="post" class="rs-portal-logout-form">
						<?php wp_nonce_field( 'rs_portal_logout', 'rs_logout_nonce' ); ?>
						<button type="submit" name="rs_portal_logout" value="1" class="rs-portal-btn rs-portal-btn-light">
							<?php esc_html_e( 'Αποσύνδεση', 'revenue-splitter' ); ?>
						</button>
					</form>
				</div>
			<?php endif; ?>

			<p class="rs-portal-footer">
				Revenue Splitter v<?php echo esc_html( RS_VERSION ); ?> — Made with ♥ by Christos Koulaxizis
			</p>
		</div>
		<?php
	}

	/** URL για τρέχοντα (0) ή προηγούμενο (−1) μήνα. */
	private static function month_url( int $offset ): string {

		$now = new DateTimeImmutable( 'now', wp_timezone() );
		$d   = ( -1 === $offset ) ? $now->modify( 'first day of previous month' ) : $now;

		return self::portal_url(
			array(
				'rs_start' => $d->format( 'Y-m-01' ),
				'rs_end'   => $d->format( 'Y-m-t' ),
			)
		);
	}

	/** URL για τρέχον (0) ή προηγούμενο (−1) έτος. */
	private static function year_url( int $offset ): string {
		$y = (int) current_time( 'Y' ) + $offset;

		return self::portal_url(
			array(
				'rs_start' => $y . '-01-01',
				'rs_end'   => $y . '-12-31',
			)
		);
	}

	private static function export_url(): string {
		return self::portal_url( array( 'rs_portal_export' => 'csv' ) );
	}

	/* ---------------------------------------------------------------------
	 * Export CSV (user-scoped)
	 * ------------------------------------------------------------------- */

	private static function stream_csv( string $who, array $view ): void {

		$fname = 'rs-portal-' . sanitize_file_name( $who ) . '-' . $view['start'] . '_' . $view['end'] . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $fname . '"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM για Excel + ελληνικά.

		fputcsv( $out, array( __( 'Δικαιούχος', 'revenue-splitter' ), $who, $view['start'], $view['end'] ), ',', '"', '\\' );
		fputcsv( $out, array(), ',', '"', '\\' );
		fputcsv(
			$out,
			array(
				__( 'Προϊόν', 'revenue-splitter' ),
				__( 'Τεμ.', 'revenue-splitter' ),
				__( 'Ποσοστό (%)', 'revenue-splitter' ),
				__( 'Ποσό', 'revenue-splitter' ),
			),
			',', '"', '\\'
		);

		foreach ( $view['rows'] as $r ) {
			fputcsv(
				$out,
				array(
					$r['title'],
					$r['qty'],
					number_format( $r['percent'], 2, ',', '' ),
					number_format( $r['amount'], 2, ',', '' ),
				),
				',', '"', '\\'
			);
		}

		fputcsv( $out, array(), ',', '"', '\\' );
		fputcsv(
			$out,
			array( '', '', __( 'ΣΥΝΟΛΑ', 'revenue-splitter' ), number_format( $view['total'], 2, ',', '' ) ),
			',', '"', '\\'
		);

		fclose( $out );
		exit;
	}
}
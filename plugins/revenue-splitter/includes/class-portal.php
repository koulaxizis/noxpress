<?php
/**
 * RS_Portal — Author Portal (frontend, v1.3.0).
 *
 * Shortcode: [author_portal]
 *
 * Ροή:
 *  1. Ο admin δημιουργεί/ανανεώνει κλειδιά ανά δικαιούχο από το
 *     WP-admin → Revenue Splitter → Portal (το plaintext κλειδί εμφανίζεται
 *     ΜΙΑ φορά, τη στιγμή της δημιουργίας — αποθηκεύεται μόνο sha256).
 *  2. Ο δικαιούχος μπαίνει από τη σελίδα του shortcode (όνομα + κλειδί),
 *     παίρνει session cookie (httpOnly, 7 μέρες) και βλέπει:
 *     τους τελευταίους 6 μήνες, στατιστικά μήνα/προηγούμενου μήνα/
 *     lifetime, αναλυτικό πίνακα προϊόντων (πλήρης/έκπτωση/δωρεάν,
 *     με ΦΠΑ/χωρίς ΦΠΑ, stock, προσωπικό μερίδιο) και το υπόλοιπό του
 *     (all-time πωλήσεις + έσοδα εκτός πωλήσεων − πληρωμές).
 *
 * Security:
 *  - Login POST + logout + CSV: χειρίζονται στο 'init' (headers OK για
 *    cookies/redirects) με nonce + referer-aware PRG.
 *  - Rate limit: 5 αποτυχημένες προσπάθειες / 15' ανά (όνομα+IP)
 *    (transient rs_rl_*). v1.3.1 FIX (#19): ο μετρητής ΜΗΔΕΝΙΖΕΤΑΙ στο
 *    επιτυχές login — δεν «κληρονομείται» αριθμός από παλιές αποτυχίες
 *    μετά από επιτυχία.
 *  - Session: τυχαίο token → transient rs_tok_* (TTL 7 μέρες,
 *    ανανεώνεται σε κάθε επίσκεψη). Logout το σβήνει.
 *
 * v1.3.1 FIX (#15): ΟΛΟ το frontend output (CSS inclusively) παράγεται
 * ΜΕΣΑ στο ob_start() του shortcode — το <style> δεν πέφτει ποτέ εκτός
 * του σημείου του shortcode σε widgets/filters που κάνουν defer.
 *
 * v1.3.1 FIX (#9): Το CSV export του portal περνά από csv_cell guard
 * (shared με το admin — RS_Admin_UI::csv_cell, πλέον public).
 *
 * v1.3.1 FIX (#16): Το stock εμφανίζεται μέσω
 * RS_Admin_UI::product_stock() — μία υλοποίηση για admin + portal.
 *
 * v1.3.1 (re-audit #6): Key rotation ΜΕΣΑ από one-shot transient
 * (rs_newkey_<user_id>, TTL 60") — το plaintext κλειδί ΔΕΝ ταξιδεύει
 * ποτέ σε URL/query string (browser history, access logs, analytics).
 * Ιδίως handlers, μάσκα στο όνομα του prefix, καθαρισμένο από το
 * uninstall.php v1.3.1.
 *
 * Σχέδιο αποθήκευσης κλειδιών: option 'rs_portal_keys' =
 * JSON { name => 'sha256:<64 hex>' }. Legacy plaintext τιμές
 * (από παλιά backups) γίνονται accepted ΜΙΑ φορά και αναβαθμίζονται
 * αυτόματα σε sha256 στο πρώτο επιτυχημένο login (silent migration).
 */

defined( 'ABSPATH' ) || exit;

final class RS_Portal {

	const SHORTCODE   = 'author_portal';
	const SLUG_ADMIN  = 'revenue-splitter-portal';
	const COOKIE_NAME = 'rs_ptok';
	const TOK_TTL     = WEEK_IN_SECONDS;

	const RL_MAX = 5;        // αποτυχημένες προσπάθειες…
	const RL_WIN = 900;      // …ανά 15 λεπτά.

	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'route' ) );

		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_admin_keys' ) );
	}

	/* =====================================================================
	 * Admin: διαχείριση κλειδιών (WP-admin → Revenue Splitter → Portal)
	 * =================================================================== */

	public static function admin_menu(): void {

		add_submenu_page(
			'revenue-splitter-dashboard',
			__( 'Revenue Splitter — Portal', 'revenue-splitter' ),
			__( 'Portal', 'revenue-splitter' ),
			RS_Admin_UI::CAP,
			self::SLUG_ADMIN,
			array( __CLASS__, 'render_admin' )
		);
	}

	/**
	 * Δημιουργία/ανανέωση κλειδιού (GET + nonce + cap, στο admin_init).
	 *
	 * Το plaintext κλειδί ΔΕΝ αποθηκεύεται πουθενά — ταξιδεύει σε one-shot
	 * transient (60") και εμφανίζεται μία φορά στο page render που ακολουθεί
	 * το redirect (re-audit #6: ποτέ σε query string).
	 */
	public static function route_admin_keys(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce ελέγχεται παρακάτω.
		if ( ! isset( $_GET['rs_regen'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || self::SLUG_ADMIN !== $_GET['page'] ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'rs_portal_regen' )
			|| ! current_user_can( RS_Admin_UI::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$who = sanitize_text_field( wp_unslash( $_GET['rs_regen'] ) );

		$plain = self::rotate_key( $who );

		if ( '' !== $plain ) {
			// One-shot transient: διαβάζεται (και σβήνεται) ΜΙΑ φορά από το
			// render_admin() του επόμενου page load. Μηδέν leak σε URL/logs.
			set_transient( 'rs_newkey_' . get_current_user_id(), $who . '|' . $plain, 60 );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG_ADMIN ) );
		exit;
	}

	/** Δημιουργεί/αντικαθιστά το κλειδί του ονόματος → plaintext. */
	private static function rotate_key( string $who ): string {

		if ( '' === $who ) {
			return '';
		}

		$keys        = self::all_keys();
		$plain       = wp_generate_password( 48, false, false );
		$keys[ $who ] = self::hash_key( $plain );

		update_option( 'rs_portal_keys', wp_json_encode( $keys, JSON_UNESCAPED_UNICODE ) );

		return $plain;
	}

	public static function render_admin(): void {

		if ( ! current_user_can( RS_Admin_UI::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}

		// Έκθεση plaintext ΜΙΑ φορά, μετά το rotate (route_admin_keys) —
		// διάβασμα + αδειασμός του one-shot transient (re-audit #6).
		$new_key = self::take_new_key();

		$names = RS_Beneficiaries::collect_names();
		$keys  = self::all_keys();
		?>
		<div class="wrap rs-wrap">
			<h1><?php esc_html_e( 'Revenue Splitter — Portal', 'revenue-splitter' ); ?></h1>

			<?php if ( '' !== $new_key && false !== strpos( $new_key, '|' ) ) : ?>
				<?php list( $for, $secret ) = explode( '|', $new_key, 2 ); // phpcs:ignore WordPress.Security.VariableAnalysis ?>
				<div class="notice notice-success rs-newkey">
					<p>
						<strong><?php esc_html_e( 'Νέο κλειδί για', 'revenue-splitter' ); ?> <?php echo esc_html( $for ); ?>:</strong><br />
						<code><?php echo esc_html( $secret ); ?></code><br />
						<?php esc_html_e( 'Αντιγράψε το ΤΩΡΑ — δεν θα εμφανιστεί ξανά (αποθηκεύεται μόνο sha256).', 'revenue-splitter' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Κάθε δικαιούχος μπαίνει στη σελίδα του portal ([author_portal]) με το όνομά του και το προσωπικό του κλειδί.', 'revenue-splitter' ); ?>
			</p>

			<table class="widefat striped rs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Δικαιούχος', 'revenue-splitter' ); ?></th>
						<th><?php esc_html_e( 'Κατάσταση κλειδιού', 'revenue-splitter' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $names ) ) : ?>
					<tr><td colspan="3" class="rs-empty"><?php esc_html_e( 'Δεν υπάρχουν δικαιούχοι ακόμη.', 'revenue-splitter' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $names as $name ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $name ); ?></strong></td>
							<td>
								<?php if ( isset( $keys[ $name ] ) ) : ?>
									<code class="rs-muted"><?php echo esc_html( self::mask_stored( $keys[ $name ] ) ); ?></code>
								<?php else : ?>
									<em><?php esc_html_e( 'χωρίς κλειδί', 'revenue-splitter' ); ?></em>
								<?php endif; ?>
							</td>
							<td>
								<a class="button button-small"
									href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG_ADMIN . '&rs_regen=' . rawurlencode( $name ) ), 'rs_portal_regen' ) ); ?>">
									<?php if ( isset( $keys[ $name ] ) ) : ?>
										<?php esc_html_e( 'Ανανέωση', 'revenue-splitter' ); ?>
									<?php else : ?>
										<?php esc_html_e( 'Δημιουργία', 'revenue-splitter' ); ?>
									<?php endif; ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php self::footer(); ?>
		</div>
		<?php
	}

	/**
	 * Διαβάζει (και αδειάζει) το one-shot plaintext κλειδί του τρέχοντος
	 * admin. Format: 'Όνομα|plaintext'. Empty string αν δεν υπάρχει.
	 */
	private static function take_new_key(): string {
		$key = 'rs_newkey_' . get_current_user_id();

		$raw = get_transient( $key );
		if ( is_string( $raw ) && '' !== $raw ) {
			delete_transient( $key );
			return $raw;
		}
		return '';
	}

	/** Πρώτοι/τελευταίοι χαρακτήρες του STORED κλειδιού (ποτέ ολόκληρο). */
	private static function mask_stored( string $stored ): string {
		return strlen( $stored ) > 14 ? substr( $stored, 0, 11 ) . '…' . substr( $stored, -3 ) : '••••';
	}

	/* =====================================================================
	 * Κλειδιά: helpers
	 * =================================================================== */

	/** @return array name => stored ('sha256:…' ή legacy plaintext). */
	private static function all_keys(): array {
		$raw     = (string) get_option( 'rs_portal_keys', '' );
		$decoded = '' !== $raw ? json_decode( $raw, true ) : null;
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function hash_key( string $plain ): string {
		return 'sha256:' . hash( 'sha256', $plain );
	}

	/**
	 * Επαλήθευση κλειδιού. Legacy plaintext τιμές αναβαθμίζονται αυτόματα
	 * σε sha256 στο πρώτο επιτυχημένο login (silent migration — συμβατό
	 * με τα legacy backups που δέχεται το import, βλ. Part 5 / #6).
	 */
	private static function verify_key( string $who, string $plain ): bool {

		$keys  = self::all_keys();
		$plain = trim( $plain );

		if ( '' === $plain ) {
			return false;
		}

		if ( ! isset( $keys[ $who ] ) || ! is_string( $keys[ $who ] ) ) {
			return false;
		}

		$stored = $keys[ $who ];

		// Νέο format.
		if ( 0 === strpos( $stored, 'sha256:' ) ) {
			return hash_equals( $stored, self::hash_key( $plain ) );
		}

		// Legacy plaintext → compare + migrate.
		if ( hash_equals( $stored, $plain ) ) {
			$keys[ $who ] = self::hash_key( $plain );
			update_option( 'rs_portal_keys', wp_json_encode( $keys, JSON_UNESCAPED_UNICODE ) );
			return true;
		}

		return false;
	}

	/* =====================================================================
	 * Sessions
	 * =================================================================== */

	/** Το όνομα του συνδεδεμένου δικαιούχου ή null. */
	public static function current(): ?string {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only token.
		$tok = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) : '';

		if ( '' === $tok || ! preg_match( '/^[A-Za-z0-9]{20,64}$/', $tok ) ) {
			return null;
		}

		$name = get_transient( 'rs_tok_' . $tok );

		if ( ! is_string( $name ) || '' === $name ) {
			// Νεκρό/expired cookie — καθαρό κλείσιμο για να μην κολλάει
			// το login UI σε φάντασμα session.
			if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
				setcookie( self::COOKIE_NAME, '', time() - HOUR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
			}
			return null;
		}

		// Sliding TTL.
		set_transient( 'rs_tok_' . $tok, $name, self::TOK_TTL );

		return $name;
	}

	private static function start_session( string $who ): void {
		$tok = wp_generate_password( 40, false, false );
		set_transient( 'rs_tok_' . $tok, $who, self::TOK_TTL );
		setcookie( self::COOKIE_NAME, $tok, time() + self::TOK_TTL, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
	}

	private static function end_session(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only token.
		$tok = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) : '';

		if ( '' !== $tok ) {
			delete_transient( 'rs_tok_' . $tok );
		}

		setcookie( self::COOKIE_NAME, '', time() - HOUR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
	}

	/* =====================================================================
	 * Routing (init — πριν από output: cookies + redirects είναι OK)
	 * =================================================================== */

	public static function route(): void {

		// ---------- Logout ----------
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce ελέγχεται παρακάτω.
		if ( isset( $_GET['rs_logout'] ) ) {

			if ( ! isset( $_GET['_wpnonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'rs_portal' ) ) {
				return;
			}

			self::end_session();
			wp_safe_redirect( self::portal_url() );
			exit;
		}

		// ---------- CSV export ----------
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce ελέγχεται παρακάτω.
		if ( isset( $_GET['rs_portal_csv'] ) ) {

			if ( ! isset( $_GET['_wpnonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'rs_portal_csv' )
				|| null === ( $who = self::current() ) ) {
				return;
			}

			self::stream_csv( $who );
		}

		// ---------- Login POST ----------
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce ελέγχεται παρακάτω.
		if ( isset( $_POST['rs_portal_login'] ) ) {

			if ( ! isset( $_POST['rs_portal_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rs_portal_nonce'] ) ), 'rs_portal_login' ) ) {
				return;
			}

			$who  = isset( $_POST['rs_portal_name'] ) ? sanitize_text_field( wp_unslash( $_POST['rs_portal_name'] ) ) : '';
			$key  = isset( $_POST['rs_portal_key'] ) ? trim( (string) wp_unslash( $_POST['rs_portal_key'] ) ) : '';
			$back = self::portal_url();

			if ( self::too_many_attempts( $who ) ) {
				wp_safe_redirect( add_query_arg( 'rs_pt_msg', 'rl', $back ) );
				exit;
			}

			if ( '' !== $who && self::verify_key( $who, $key ) ) {

				// v1.3.1 FIX (#19): επιτυχές login → ΜΗΔΕΝΙΣΜΟΣ του rate
				// limiter του (name+IP). Παλιά: οι αποτυχίες παρέμεναν.
				self::clear_attempts( $who );

				self::start_session( $who );
				wp_safe_redirect( remove_query_arg( 'rs_pt_msg', $back ) );
				exit;
			}

			self::record_attempt( $who );
			wp_safe_redirect( add_query_arg( 'rs_pt_msg', 'bad', $back ) );
			exit;
		}
	}

	/* ---------- Rate limiting (rs_rl_*) ---------- */

	private static function rl_key( string $who ): string {
		return 'rs_rl_' . md5( strtolower( $who ) . '|' . self::client_ip() );
	}

	private static function too_many_attempts( string $who ): bool {
		$n = (int) get_transient( self::rl_key( $who ) );
		return $n >= self::RL_MAX;
	}

	private static function record_attempt( string $who ): void {
		$k = self::rl_key( $who );
		$n = (int) get_transient( $k );
		set_transient( $k, $n + 1, self::RL_WIN );
	}

	/** v1.3.1 FIX (#19). */
	private static function clear_attempts( string $who ): void {
		delete_transient( self::rl_key( $who ) );
	}

	private static function client_ip(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- IP addresses.
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
	}

	/* =====================================================================
	 * Shortcode (render)
	 * =================================================================== */

	public static function shortcode(): string {

		// v1.3.1 FIX (#15): ΟΛΟ το output — CSS inclusive — μέσα στο buffer.
		ob_start();

		$who = self::current();

		if ( null === $who ) {
			self::render_login();
		} else {
			self::render_dashboard( $who );
		}

		return ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaping εντός render*.
	}

	/* ---------- Login form ---------- */

	private static function render_login(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag.
		$err = isset( $_GET['rs_pt_msg'] ) ? sanitize_key( wp_unslash( $_GET['rs_pt_msg'] ) ) : '';

		self::print_css();
		?>
		<div class="rs-portal">

			<?php if ( 'bad' === $err ) : ?>
				<p class="rs-pt-error"><?php esc_html_e( 'Λάθος όνομα ή κλειδί.', 'revenue-splitter' ); ?></p>
			<?php elseif ( 'rl' === $err ) : ?>
				<p class="rs-pt-error"><?php esc_html_e( 'Πολλές αποτυχημένες προσπάθειες — δοκίμασε ξανά σε 15 λεπτά.', 'revenue-splitter' ); ?></p>
			<?php endif; ?>

			<form method="post" class="rs-pt-login">
				<?php wp_nonce_field( 'rs_portal_login', 'rs_portal_nonce' ); ?>

				<label><?php esc_html_e( 'Όνομα', 'revenue-splitter' ); ?></label>
				<input type="text" name="rs_portal_name" required autocomplete="username" />

				<label><?php esc_html_e( 'Κλειδί', 'revenue-splitter' ); ?></label>
				<input type="password" name="rs_portal_key" required autocomplete="current-password" />

				<button type="submit" name="rs_portal_login" value="1"><?php esc_html_e( 'Είσοδος', 'revenue-splitter' ); ?></button>
			</form>
		</div>
		<?php
	}

	/* ---------- Dashboard (συνδεδεμένος δικαιούχος) ---------- */

	private static function render_dashboard( string $who ): void {

		$now   = new DateTimeImmutable( 'now', wp_timezone() );
		$today = $now->format( 'Y-m-d' );

		$this_m = array( 'start' => $now->format( 'Y-m-01' ), 'end' => $today );
		$prev   = $now->modify( 'first day of previous month' );

		$rep_this = RS_Reports::run( array( 'date_start' => $this_m['start'], 'date_end' => $this_m['end'] ) );
		$rep_prev = RS_Reports::run( array( 'date_start' => $prev->format( 'Y-m-01' ), 'date_end' => $prev->format( 'Y-m-t' ) ) );

		// Lifetime: πωλήσεις (cached report) + ledger.
		$sales_l = RS_Reports::lifetime_beneficiaries()[ $who ] ?? 0.0;
		$inc_l   = RS_Ledger::sum( $who, '2000-01-01', $today, 'income' );
		$pay_l   = RS_Ledger::sum( $who, '2000-01-01', $today, 'payment' );
		$remain  = round( $sales_l + $inc_l - $pay_l, 2 );

		$this_share = self::share_of( $rep_this, $who );
		$prev_share = self::share_of( $rep_prev, $who );

		// Chart: τελευταίοι 6 μήνες (public cached runs — ένα/μήνα).
		$months = array();
		for ( $i = 5; $i >= 0; $i-- ) {
			$m     = $now->modify( 'first day of this month' )->modify( "-{$i} months" );
			$rep_m = RS_Reports::run( array( 'date_start' => $m->format( 'Y-m-01' ), 'date_end' => $m->format( 'Y-m-t' ) ) );
			$months[] = array(
				'label' => wp_date( 'M Y', $m->getTimestamp(), wp_timezone() ),
				'value' => self::share_of( $rep_m, $who ),
			);
		}
		$max_m = 0.0;
		foreach ( $months as $mm ) {
			$max_m = max( $max_m, (float) $mm['value'] );
		}

		$cur = self::currency_fmt();

		$logout = wp_nonce_url( add_query_arg( 'rs_logout', '1', self::portal_url() ), 'rs_portal' );
		$csv    = wp_nonce_url( add_query_arg( 'rs_portal_csv', '1', self::portal_url() ), 'rs_portal_csv' );
		?>
		<div class="rs-portal">
			<div class="rs-pt-head">
				<strong><?php echo esc_html( $who ); ?></strong>
				<span>
					<a href="<?php echo esc_url( $csv ); ?>"><?php esc_html_e( 'CSV', 'revenue-splitter' ); ?></a> ·
					<a href="<?php echo esc_url( $logout ); ?>"><?php esc_html_e( 'Αποσύνδεση', 'revenue-splitter' ); ?></a>
				</span>
			</div>

			<div class="rs-kpis">
				<div class="rs-kpi"><span class="rs-kpi-label"><?php esc_html_e( 'Αυτόν τον μήνα', 'revenue-splitter' ); ?></span><strong><?php echo esc_html( $cur( $this_share ) ); ?></strong></div>
				<div class="rs-kpi"><span class="rs-kpi-label"><?php esc_html_e( 'Προηγούμενος μήνας', 'revenue-splitter' ); ?></span><strong><?php echo esc_html( $cur( $prev_share ) ); ?></strong></div>
				<div class="rs-kpi"><span class="rs-kpi-label"><?php esc_html_e( 'Αποπληρωτέο υπόλοιπο', 'revenue-splitter' ); ?></span><strong><?php echo esc_html( $cur( $remain ) ); ?></strong></div>
			</div>

			<h3><?php esc_html_e( 'Τελευταίοι 6 μήνες', 'revenue-splitter' ); ?></h3>
			<div class="rs-pt-chart">
				<?php foreach ( $months as $mm ) :
					$h = $max_m > 0 ? max( 3, (int) round( ( (float) $mm['value'] / $max_m ) * 100 ) ) : 3;
					?>
					<div class="rs-pt-col" title="<?php echo esc_attr( $mm['label'] . ': ' . $cur( $mm['value'] ) ); ?>">
						<div class="rs-pt-bar" style="height:<?php echo esc_attr( (string) $h ); ?>%;"></div>
						<small><?php echo esc_html( $mm['label'] ); ?></small>
					</div>
				<?php endforeach; ?>
			</div>

			<h3><?php esc_html_e( 'Αυτόν τον μήνα — ανά προϊόν', 'revenue-splitter' ); ?></h3>
			<table class="rs-pt-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Προϊόν', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Τεμ.', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Πλήρης', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Έκπτωση', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Δωρεάν', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Με ΦΠΑ', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'ΦΠΑ', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Χωρίς ΦΠΑ', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Στοκ', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Το μερίδιό μου', 'revenue-splitter' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				$rows = 0;
				foreach ( $rep_this['products'] as $p ) :
					$mine = null;
					foreach ( $p['splits'] as $s ) {
						if ( $s['name'] === $who ) {
							$mine = $s;
							break;
						}
					}
					if ( null === $mine ) {
						continue; // Το προϊόν δεν αφορά αυτόν τον δικαιούχο.
					}
					$rows++;
					?>
					<tr>
						<td><?php echo esc_html( $p['title'] ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $p['qty'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $p['qty_full'] ) ); ?></td>
						<td class="num">
							<?php echo esc_html( number_format_i18n( (int) $p['qty_disc'] ) ); ?>
							<?php if ( $p['qty_disc'] > 0 ) : ?><small>(<?php echo esc_html( number_format_i18n( $p['disc_pct'], 1 ) ); ?>%)</small><?php endif; ?>
						</td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $p['qty_free'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $cur( $p['gross'] ) ); ?></td>
						<td class="num">−<?php echo esc_html( $cur( $p['vat'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $cur( $p['net'] ) ); ?></td>
						<td class="num"><?php echo esc_html( RS_Admin_UI::product_stock( (int) $p['product_id'] ) ); // #16. ?></td>
						<td class="num"><strong><?php echo esc_html( $cur( $mine['amount'] ) ); ?></strong> <small><?php echo esc_html( number_format_i18n( $mine['percent'], 1 ) ); ?>%</small></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( 0 === $rows ) : ?>
					<tr><td colspan="10" class="rs-empty"><?php esc_html_e( 'Καμία πώληση των προϊόντων σου αυτόν τον μήνα.', 'revenue-splitter' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>

			<p class="rs-pt-note">
				<?php esc_html_e( 'Το «αποπληρωτέο υπόλοιπο» = all-time μερίδια από πωλήσεις + έσοδα εκτός πωλήσεων − πληρωμές που έχεις λάβει.', 'revenue-splitter' ); ?>
			</p>
		</div>
		<?php
	}

	/** Μείγμα του δικαιούχου από ένα report (splits του μήνα). */
	private static function share_of( array $report, string $who ): float {
		foreach ( $report['beneficiaries'] as $b ) {
			if ( $b['name'] === $who ) {
				return (float) $b['amount'];
			}
		}
		return 0.0;
	}

	/* ---------- CSV export ---------- */

	private static function stream_csv( string $who ): void {

		$now   = new DateTimeImmutable( 'now', wp_timezone() );
		$rep   = RS_Reports::run( array( 'date_start' => '2000-01-01', 'date_end' => $now->format( 'Y-m-d' ) ) );
		$sales = RS_Reports::lifetime_beneficiaries()[ $who ] ?? 0.0;
		$today = $now->format( 'Y-m-d' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="portal-' . sanitize_key( $who ) . '-' . $today . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM.

		$cur = self::currency_fmt();

		fputcsv( $out, array( $who, $today ), ',', '"', '\\' );
		fputcsv( $out, array(), ',', '"', '\\' );

		fputcsv( $out, array( __( 'Προϊόν', 'revenue-splitter' ), __( 'Τεμ.', 'revenue-splitter' ), __( 'Πλήρης', 'revenue-splitter' ), __( 'Έκπτωση', 'revenue-splitter' ), __( 'Δωρεάν', 'revenue-splitter' ), __( 'Καθαρό', 'revenue-splitter' ), __( 'Ποσοστό', 'revenue-splitter' ), __( 'Μερίδιο', 'revenue-splitter' ) ), ',', '"', '\\' );

		foreach ( $rep['products'] as $p ) {
			foreach ( $p['splits'] as $s ) {
				if ( $s['name'] !== $who ) {
					continue;
				}
				fputcsv(
					$out,
					array(
						RS_Admin_UI::csv_cell( $p['title'] ), // #9: formula-injection guard.
						(int) $p['qty'],
						(int) $p['qty_full'],
						(int) $p['qty_disc'],
						(int) $p['qty_free'],
						$cur( $p['net'] ),
						$s['percent'],
						$cur( $s['amount'] ),
					),
					',',
					'"',
					'\\'
				);
			}
		}

		fputcsv( $out, array(), ',', '"', '\\' );
		fputcsv( $out, array( __( 'All-time μερίδια από πωλήσεις', 'revenue-splitter' ), $cur( $sales ) ), ',', '"', '\\' );
		fputcsv( $out, array( __( 'Έσοδα εκτός πωλήσεων', 'revenue-splitter' ), $cur( RS_Ledger::sum( $who, '2000-01-01', $today, 'income' ) ) ), ',', '"', '\\' );
		fputcsv( $out, array( __( 'Πληρωμές που ελήφθησαν', 'revenue-splitter' ), $cur( RS_Ledger::sum( $who, '2000-01-01', $today, 'payment' ) ) ), ',', '"', '\\' );

		fclose( $out );
		exit;
	}

	/* =====================================================================
	 * Frontend helpers
	 * =================================================================== */

	/**
	 * v1.3.1 FIX (#15): Το CSS τυπώνεται ως ΠΡΩΤΟ πράγμα ΜΕΣΑ στο buffer
	 * του shortcode (πρώτη κλήση σε κάθε render).
	 */
	private static function print_css(): void {
		?>
<style>
.rs-portal { font-family: system-ui, sans-serif; margin: 1.5em 0; color: #1d2327; }
.rs-portal *, .rs-portal *::before, .rs-portal *::after { box-sizing: border-box; }
.rs-pt-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1em; }
.rs-pt-login { max-width: 340px; display: grid; gap: 6px; }
.rs-pt-login label { font-weight: 600; font-size: 0.85em; }
.rs-pt-login input { padding: 8px 10px; border: 1px solid #c3c4c7; border-radius: 3px; width: 100%; }
.rs-pt-login button, .rs-pt-head a { cursor: pointer; }
.rs-pt-login button { padding: 8px 14px; border: 0; border-radius: 3px; background: #6d4aff; color: #fff; font-weight: 600; }
.rs-pt-error { color: #b32d2e; font-weight: 600; }
.rs-pt-table { width: 100%; border-collapse: collapse; margin-top: 0.5em; }
.rs-pt-table th, .rs-pt-table td { border: 1px solid #e2e4e7; padding: 6px 10px; text-align: left; font-size: 0.92em; }
.rs-pt-table th { background: #f6f7f7; }
.rs-pt-table .num { text-align: right; font-variant-numeric: tabular-nums; }
.rs-pt-table small { color: #787c82; }
.rs-pt-chart { display: flex; align-items: flex-end; gap: 8px; height: 120px; margin: 0.5em 0 1.5em; }
.rs-pt-col { flex: 1; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; height: 100%; text-align: center; }
.rs-pt-bar { width: 70%; background: #6d4aff; border-radius: 3px 3px 0 0; min-height: 3px; }
.rs-pt-col small { margin-top: 4px; font-size: 0.72em; color: #787c82; }
.rs-kpis { display: flex; gap: 12px; flex-wrap: wrap; margin: 1em 0; }
.rs-kpi { flex: 1 1 160px; border: 1px solid #e2e4e7; border-radius: 4px; padding: 10px 14px; }
.rs-kpi strong { display: block; font-size: 1.25em; margin-top: 2px; font-variant-numeric: tabular-nums; }
.rs-kpi-label { font-size: 0.72em; text-transform: uppercase; letter-spacing: 0.06em; color: #787c82; }
.rs-pt-note { color: #787c82; font-size: 0.85em; }
.rs-empty { text-align: center; color: #787c82; font-style: italic; }
.rs-portal h3 { margin: 1.4em 0 0.4em; font-size: 1.05em; }
</style>
		<?php
	}

	private static function currency_fmt(): callable {

		$symbol = function_exists( 'get_woocommerce_currency_symbol' )
			? get_woocommerce_currency_symbol()
			: '€';

		return static function ( $amount ) use ( $symbol ) {
			return number_format_i18n( (float) $amount, 2 ) . ' ' . $symbol;
		};
	}

	/** URL της σελίδας που τρέχει το shortcode (referer-aware). */
	private static function portal_url(): string {

		$ref = wp_get_raw_referer();
		$url = $ref ? wp_validate_redirect( $ref, '' ) : '';

		if ( '' === $url ) {
			// Fallback: η τρέχουσα σελίδα (shortcode page ή home).
			$url = is_singular() ? get_permalink() : home_url( '/' );
		}

		return $url;
	}

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
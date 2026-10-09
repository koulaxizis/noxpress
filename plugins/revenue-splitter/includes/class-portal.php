<?php
/**
 * RS_Portal — Author Portal (frontend, v1.3.2 → v1.3.5).
 *
 * Shortcode: [rs_portal] (+ [author_portal] — και τα δύο καταχωρούνται).
 *
 * Ροή:
 *  1. Ο admin δημιουργεί/ανανεώνει κλειδιά ανά δικαιούχο από το
 *     WP-admin → Revenue Splitter → Portal (plaintext ΜΙΑ φορά, sha256-only).
 *  2. Ο δικαιούχος μπαίνει ΜΟΝΟ με το κλειδί του (v1.3.5 #3: το πεδίο
 *     ονόματος αφαιρέθηκε — ένας απλός, κεντραρισμένος password-τύπου
 *     login), παίρνει session cookie (httpOnly, 7 μέρες) και βλέπει:
 *     επιλογή περιόδου (presets + custom), φίλτρο προϊόντος (μόνο τα
 *     δικά του), αναλυτικό πίνακα (πλήρης/έκπτωση % /δωρεάν, ΦΠΑ ανά
 *     συντελεστή, κουπόνια, stock, προσωπικό μερίδιο), KPI σύγκριση
 *     μήνα vs προηγούμενου, chart 6/12 μηνών, ιστορικό συναλλαγών
 *     (ledger) και CSV export που σέβεται τα ενεργά φίλτρα.
 *
 * Security:
 *  - Login POST + logout + CSV: 'init' (headers OK) με nonce + PRG.
 *  - Rate limit (v1.7.0): 5 αποτυχημένες / 15' ανά IP (hashed, rs_rl_l_*)
 *    — ανεξάρτητα από το κλειδί που μαντεύεται. Το reset έχει δικό του
 *    όριο ανά IP (rs_rl_r_*) ΚΑΙ ανά δικαιούχο (3/ώρα, rs_rl_b_*) που
 *    ΔΕΝ μηδενίζεται από επιτυχή reset/login.
 *  - Session: τυχαίο token → transient rs_tok_* (TTL 7 μέρες, sliding).
 *  - Login με κλειδί ΜΟΝΟ: το κλειδί ταυτοποιεί ΜΟΝΑΔΙΚΑ τον δικαιούχο
 *    (48-char alphanumeric) — scan όλων των hashed keys με
 *    hash_equals (timing-safe).
 *
 * v1.3.5 (#1): Περίοδος + φίλτρο προϊόντος + enriched πίνακες +
 * ΦΠΑ/συντελεστή + KPI δ% + chart 6/12 + ιστορικό + CSV με φίλτρα.
 * v1.3.5 (#3): Login μόνο με κλειδί, φόρμα κεντραρισμένη.
 *
 * v1.3.8 (#1–#5): default Τρέχον έτος, multi-select προϊόντων με αναζήτηση, d/m/Y στα el (ήδη μέσω fmt_date), custom χρώμα ονόματος στο head.
 */

defined( 'ABSPATH' ) || exit;

final class RS_Portal {

	const SHORTCODE   = 'author_portal';
	const SLUG_ADMIN  = 'revenue-splitter-portal';
	const COOKIE_NAME = 'rs_ptok';
	const TOK_TTL     = WEEK_IN_SECONDS;

	const RL_MAX = 5;        // αποτυχημένες προσπάθειες…
	const RL_WIN = 900;      // …ανά 15 λεπτά.

	const RESET_MAX = 3;                 // v1.7.0: resets ανά δικαιούχο…
	const RESET_WIN = HOUR_IN_SECONDS;   // …ανά ώρα.

	/** @var bool Έχει επιλυθεί η session σε αυτό το request; */
	private static $session_resolved = false;

	/** @var string|null Cached όνομα συνδεδεμένου δικαιούχου. */
	private static $session_who = null;

	// GET keys των frontend φίλτρων (ξεχωριστά από του admin, μηδέν σύγκρουση).
	const GP_PERIOD = 'rs_p_period';
	const GP_START  = 'rs_p_start';
	const GP_END    = 'rs_p_end';
	const GP_PROD   = 'rs_p_product';
	const GP_CHART  = 'rs_p_chart';

	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		// Legacy alias: παλιές σελίδες με [rs_portal].
		add_shortcode( 'rs_portal', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'route' ) );

		// Ίδια προτεραιότητα με το RS_Admin_UI (9): το top-level «Noxpress»
		// το έχει ήδη δημιουργήσει το Noxpress Core (priority 5).
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 9 );
		add_action( 'admin_init', array( __CLASS__, 'route_admin_keys' ) );
	}

	/* =====================================================================
	 * Admin: διαχείριση κλειδιών (χωρίς αλλαγές σε λειτουργία)
	 * =================================================================== */

	public static function admin_menu(): void {

		// Parent = το κοινό top-level «Noxpress» menu (RS_Admin_UI::SLUG_MENU)
		// και ΟΧΙ hardcoded 'revenue-splitter-dashboard' — μετά το restructure
		// δεν υπάρχει πια top-level με αυτόν τον slug (το Portal έμεινε ορφανό).
		add_submenu_page(
			RS_Admin_UI::SLUG_MENU,
			__( 'Revenue Splitter — Portal', 'revenue-splitter' ),
			__( 'RS Portal', 'revenue-splitter' ),
			RS_Admin_UI::CAP,
			self::SLUG_ADMIN,
			array( __CLASS__, 'render_admin' )
		);
	}

	/**
	 * Δημιουργία/ανανέωση κλειδιού (GET + nonce + cap, στο admin_init).
	 * Το plaintext ταξιδεύει σε one-shot transient (60") — ποτέ σε URL.
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

		if ( '' === $who
			|| ! class_exists( 'RS_Beneficiaries' )
			|| ! in_array( $who, RS_Beneficiaries::collect_names(), true ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG_ADMIN ) );
			exit;
		}

		$plain = self::rotate_key( $who );

		if ( '' !== $plain ) {
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

		$keys         = self::all_keys();
		$plain        = wp_generate_password( 48, false, false );
		$keys[ $who ] = self::hash_key( $plain );

		update_option( 'rs_portal_keys', wp_json_encode( $keys, JSON_UNESCAPED_UNICODE ) );

		return $plain;
	}

	public static function render_admin(): void {

		if ( ! current_user_can( RS_Admin_UI::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'revenue-splitter' ) );
		}

		$new_key = self::take_new_key();

		$names = RS_Beneficiaries::collect_names();
		$keys  = self::all_keys();
		?>
		<div class="wrap rs-wrap">
			<h1><?php esc_html_e( 'Revenue Splitter — Portal', 'revenue-splitter' ); ?></h1>

			<?php if ( '' !== $new_key && false !== strpos( $new_key, '|' ) ) : ?>
				<?php list( $for, $secret ) = explode( '|', $new_key, 2 ); ?>
				<div class="notice notice-success rs-newkey">
					<p>
						<strong><?php esc_html_e( 'Νέο κλειδί για', 'revenue-splitter' ); ?> <?php echo esc_html( $for ); ?>:</strong><br />
						<code><?php echo esc_html( $secret ); ?></code><br />
						<?php esc_html_e( 'Αντιγράψε το ΤΩΡΑ — δεν θα εμφανιστεί ξανά (αποθηκεύεται μόνο sha256).', 'revenue-splitter' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Ο δικαιούχος μπαίνει στη σελίδα του portal ([rs_portal]) ΜΟΝΟ με το κλειδί του — το κλειδί ταυτοποιεί μοναδικά τον κάτοχό του.', 'revenue-splitter' ); ?>
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

			<?php RS_Admin_UI::footer(); ?>
		</div>
		<?php
	}

	/** Διαβάζει (και αδειάζει) το one-shot plaintext κλειδί. */
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
	 * v1.3.5 (#3): Ταυτοποίηση ΜΟΝΟ με κλειδί.
	 *
	 * Scan όλων των αποθηκευμένων κλειδιών με hash_equals (timing-safe).
	 * Legacy plaintext τιμές αναβαθμίζονται αυτόματα σε sha256 στο πρώτο
	 * επιτυχές login (silent migration).
	 *
	 * @return string|null Το όνομα του δικαιούχου ή null.
	 */
	private static function who_for_key( string $plain ): ?string {

		$plain = trim( $plain );

		if ( '' === $plain ) {
			return null;
		}

		$keys = self::all_keys();

		foreach ( $keys as $name => $stored ) {
			if ( ! is_string( $stored ) ) {
				continue;
			}

			// Νέο format: sha256.
			if ( 0 === strpos( $stored, 'sha256:' ) ) {
				if ( hash_equals( $stored, self::hash_key( $plain ) ) ) {
					return (string) $name;
				}
				continue;
			}

			// Legacy plaintext → compare + migrate.
			if ( hash_equals( $stored, $plain ) ) {
				$keys[ $name ] = self::hash_key( $plain );
				update_option( 'rs_portal_keys', wp_json_encode( $keys, JSON_UNESCAPED_UNICODE ) );
				return (string) $name;
			}
		}

		return null;
	}

	/* =====================================================================
	 * Sessions
	 * =================================================================== */

	/**
	 * Το όνομα του συνδεδεμένου δικαιούχου ή null.
	 *
	 * v1.7.0: επιλύεται ΜΙΑ φορά ανά request, νωρίς στο init (route()),
	 * όπου επιτρέπονται ακόμη headers — το shortcode (render, μετά το
	 * output) διαβάζει μόνο το cached αποτέλεσμα και δεν στέλνει ποτέ
	 * cookie. Το σβήσιμο ληγμένου cookie γίνεται μόνο αν !headers_sent().
	 */
	public static function current(): ?string {

		if ( self::$session_resolved ) {
			return self::$session_who;
		}
		self::$session_resolved = true;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only token.
		$tok = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) : '';

		if ( '' === $tok || ! preg_match( '/^[A-Za-z0-9]{20,64}$/', $tok ) ) {
			return null;
		}

		$name = get_transient( 'rs_tok_' . $tok );

		if ( ! is_string( $name ) || '' === $name ) {
			if ( ! headers_sent() ) {
				self::clear_cookie();
			}
			return null;
		}

		// Sliding TTL.
		set_transient( 'rs_tok_' . $tok, $name, self::TOK_TTL );

		self::$session_who = $name;

		return $name;
	}

	/** Σβήσιμο του session cookie (ΜΟΝΟ πριν από output). */
	private static function clear_cookie(): void {
		setcookie(
			self::COOKIE_NAME,
			'',
			array(
				'expires'  => time() - HOUR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	private static function start_session( string $who ): void {
		$tok = wp_generate_password( 40, false, false );
		set_transient( 'rs_tok_' . $tok, $who, self::TOK_TTL );
		// php 7.3+ syntax: [name, value, expire, path, domain, secure, httponly, samesite]
		setcookie(
			self::COOKIE_NAME,
			$tok,
			array(
				'expires'  => time() + self::TOK_TTL,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true, // Audit fix: blocking JS access.
				'samesite' => 'Lax',
			)
		);
		self::$session_resolved = true;
		self::$session_who      = $who;
	}

	private static function end_session(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only token.
		$tok = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) : '';

		if ( '' !== $tok ) {
			delete_transient( 'rs_tok_' . $tok );
		}

		self::clear_cookie();
		self::$session_resolved = true;
		self::$session_who      = null;
	}

	/* =====================================================================
	 * Routing (init — πριν από output)
	 * =================================================================== */

	public static function route(): void {

		// v1.7.0: επίλυση session ΕΔΩ (init, πριν από output) — sliding
		// TTL / σβήσιμο ληγμένου cookie ποτέ μέσα στο shortcode render.
		if ( ! is_admin() && isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			self::current();
		}

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

		// ---------- CSV export (σέβεται τα ενεργά φίλτρα: GET params) ----------
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce ελέγχεται παρακάτω.
		if ( isset( $_GET['rs_portal_csv'] ) ) {

			if ( ! isset( $_GET['_wpnonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'rs_portal_csv' )
				|| null === ( $who = self::current() ) ) {
				return;
			}

			self::stream_csv( $who );
		}

		// ---------- vNext (#3): Forgot-key reset (email → νέο κλειδί) ----------
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce ελέγχεται αμέσως παρακάτω.
		if ( isset( $_POST['rs_portal_reset'] ) ) {

			if ( ! isset( $_POST['rs_portal_reset_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rs_portal_reset_nonce'] ) ), 'rs_portal_reset' ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- frontline input του reset form.
			$email = isset( $_POST['rs_reset_email'] ) ? trim( (string) wp_unslash( $_POST['rs_reset_email'] ) ) : '';
			$back  = self::portal_url();

			// v1.7.0: Rate limit ανά IP ΠΡΙΝ από κάθε lookup/dispatch.
			// ΚΑΘΕ αίτημα μετράει (βρεθεί ή όχι το email) — ίδια
			// συμπεριφορά και για τις δύο περιπτώσεις (no enumeration).
			if ( self::too_many_attempts( 'reset' ) ) {
				wp_safe_redirect( add_query_arg( 'rs_pt_msg', 'rl', $back ) );
				exit;
			}
			self::record_attempt( 'reset' );

			// Reverse lookup: ΜΟΝΟ εδώ αποκαλύπτεται το όνομα. Το
			// outcome προς τον χρήστη είναι ΠΑΝΤΑ generic (no email
			// enumeration) — μήνυμα 'rq'.
			$who = RS_Emails::name_for_email( $email );

			// Throttle ανά δικαιούχο (3/ώρα), ανεξάρτητο από login/IP και
			// ΠΟΤΕ δεν μηδενίζεται από επιτυχία — κανένα mail-bombing ή
			// συνεχές rotation του κλειδιού κάποιου τρίτου. Πάνω από το
			// όριο: σιωπηλό skip, ίδιο generic μήνυμα.
			if ( '' !== $who && self::reset_allowed( $who ) ) {
				$plain = self::rotate_key( $who );

				if ( '' !== $plain ) {
					RS_Emails::send_key( $who, $plain, $email );
					RS_Emails::notify_admin_rotation( $who );
				}
			}

			wp_safe_redirect( add_query_arg( 'rs_pt_msg', 'rq', $back ) );
			exit;
		}

		// ---------- vNext (#1): Toggle μηνιαίας αναφοράς (συνδεδεμένος δικαιούχος) ----------
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce ελέγχεται αμέσως παρακάτω.
		if ( isset( $_POST['rs_portal_report_toggle'] ) ) {

			if ( ! isset( $_POST['rs_portal_report_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rs_portal_report_nonce'] ) ), 'rs_portal_report' ) ) {
				return;
			}

			$who = self::current();
			if ( null === $who ) {
				wp_safe_redirect( self::portal_url() );
				exit;
			}

			RS_Emails::set_optin( $who, ! RS_Emails::is_opted_in( $who ) );

			wp_safe_redirect( remove_query_arg( 'rs_pt_msg', self::portal_url() ) );
			exit;
		}

		// ---------- Login POST (ΜΟΝΟ κλειδί — v1.3.5 #3) ----------
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce ελέγχεται παρακάτω.
		if ( isset( $_POST['rs_portal_login'] ) ) {

			if ( ! isset( $_POST['rs_portal_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rs_portal_nonce'] ) ), 'rs_portal_login' ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- frontline input του login form.
			$key  = isset( $_POST['rs_portal_key'] ) ? trim( (string) wp_unslash( $_POST['rs_portal_key'] ) ) : '';
			$back = self::portal_url();

			// v1.7.0: όριο ανά IP (ΟΧΙ ανά μαντεψιά) — πολλές διαφορετικές
			// μαντεψιές από την ίδια IP μετράνε όλες μαζί.
			if ( self::too_many_attempts( 'login' ) ) {
				wp_safe_redirect( add_query_arg( 'rs_pt_msg', 'rl', $back ) );
				exit;
			}

			$who = self::who_for_key( $key );

			if ( null !== $who ) {
				// Ο μετρητής αποτυχιών ΔΕΝ μηδενίζεται στην επιτυχία: ένας
				// κάτοχος έγκυρου κλειδιού δεν μπορεί να «ξεπλένει» το όριο
				// για να δοκιμάζει κλειδιά τρίτων. Λήγει μόνος (15').
				self::start_session( $who );
				wp_safe_redirect( remove_query_arg( 'rs_pt_msg', $back ) );
				exit;
			}

			self::record_attempt( 'login' );
			wp_safe_redirect( add_query_arg( 'rs_pt_msg', 'bad', $back ) );
			exit;
		}
	}

	/* ---------- Rate limiting (rs_rl_*) — v1.7.0: ανά IP (hashed) ---------- */

	/**
	 * Transient key ανά ενέργεια ('login' | 'reset') + hashed IP.
	 * Η IP δεν αποθηκεύεται ποτέ raw (salted hash).
	 */
	private static function rl_key( string $action ): string {
		$prefix = ( 'reset' === $action ) ? 'rs_rl_r_' : 'rs_rl_l_';
		return $prefix . substr( hash( 'sha256', wp_salt( 'nonce' ) . '|' . self::client_ip() ), 0, 40 );
	}

	private static function too_many_attempts( string $action ): bool {
		$n = (int) get_transient( self::rl_key( $action ) );
		return $n >= self::RL_MAX;
	}

	private static function record_attempt( string $action ): void {
		$k = self::rl_key( $action );
		$n = (int) get_transient( $k );
		set_transient( $k, $n + 1, self::RL_WIN );
	}

	/**
	 * v1.7.0: Throttle επαναφορών ανά δικαιούχο (RESET_MAX / RESET_WIN).
	 * Επιστρέφει true και καταγράφει την επαναφορά αν επιτρέπεται.
	 */
	private static function reset_allowed( string $who ): bool {
		$k = 'rs_rl_b_' . md5( $who );
		$n = (int) get_transient( $k );
		if ( $n >= self::RESET_MAX ) {
			return false;
		}
		set_transient( $k, $n + 1, self::RESET_WIN );
		return true;
	}

	/**
	 * Πραγματική IP επισκέπτη (behind-proxy aware — δες v1.3.2 FIX #5).
	 */
	private static function client_ip(): string {

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- IP addresses.
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		if ( '' === $remote ) {
			return 'unknown';
		}

		if ( ! self::is_reserved( $remote ) ) {
			return $remote;
		}

		if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$cf = trim( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] );
			if ( '' !== $cf && false !== filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				return $cf;
			}
		}

		if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- IP chain.
			$chain = array_map( 'trim', explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			if ( ! empty( $chain[0] ) && false !== filter_var( $chain[0], FILTER_VALIDATE_IP ) ) {
				return $chain[0];
			}
		}

		return $remote;
	}

	private static function is_reserved( string $ip ): bool {

		if ( '' === $ip || 'unknown' === $ip ) {
			return false;
		}

		return false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/* =====================================================================
	 * Period parser (frontend — δικά μας GET keys, read-only φίλτρα)
	 * =================================================================== */

	/**
	 * v1.3.5 (#1): presets + custom range, όπως στο admin, αλλά σε
	 * rs_p_* GET keys (κανένα collision με admin params) και χωρίς
	 * εξάρτηση από το RS_Admin_UI.
	 *
	 * @return array{start:string,end:string,preset:string,label:string,chart:int}
	 */
	private static function current_period(): array {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only period filter.
		// v1.3.8 (#4): default = «Τρέχον έτος» — ευθυγραμμισμένο με το admin.
		$preset = isset( $_GET[ self::GP_PERIOD ] ) ? sanitize_key( wp_unslash( $_GET[ self::GP_PERIOD ] ) ) : 'year';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$start = isset( $_GET[ self::GP_START ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::GP_START ] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$end   = isset( $_GET[ self::GP_END ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::GP_END ] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$chart = isset( $_GET[ self::GP_CHART ] ) ? absint( $_GET[ self::GP_CHART ] ) : 12;

		$now = new DateTimeImmutable( 'now', wp_timezone() );

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
			case 'custom':
				$label = __( 'Προσαρμοσμένο', 'revenue-splitter' );
				if ( $valid( $start ) && $valid( $end ) && $start <= $end ) {
					$s = $start;
					$e = $end;
				} else {
					// Audit fix: ίδιο fallback με το admin (v1.3.8 #4) — έτος,
					// όχι μήνας (ασυμφωνία με το admin προκαλεί confusion).
					$s = $now->format( 'Y-01-01' );
					$e = $now->format( 'Y-m-d' );
				}
				break;
			case 'month':
				$preset = 'month';
				$label  = __( 'Τρέχων μήνας', 'revenue-splitter' );
				$s      = $now->format( 'Y-m-01' );
				$e      = $now->format( 'Y-m-d' );
				break;
			default:
				// v1.3.8 (#4): άκυρο/άγνωστο preset → fail-safe στο «Τρέχον έτος».
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
			'chart'  => in_array( $chart, array( 6, 12 ), true ) ? $chart : 12,
		);
	}
	
	
	/**
	 * v1.3.8 (#2): Επιλεγμένα IDs προϊόντων από το GET (rs_p_product[]),
	 * με legacy scalar fallback. Sorted unique — κανένα ID = όλα τα
	 * ΔΙΚΑ του προϊόντα. Το sort() κανονικοποιεί το cache key.
	 */
	private static function current_products(): array {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, absint παρακάτω.
		if ( ! isset( $_GET[ self::GP_PROD ] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- array validated/sanitized below.
		$raw = wp_unslash( $_GET[ self::GP_PROD ] );

		$ids = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $v ) {
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

	/* =====================================================================
	 * Shortcode (render)
	 * =================================================================== */

	public static function shortcode(): string {

		ob_start();

		$who = self::current();

		if ( null === $who ) {
			self::render_login();
		} else {
			self::render_dashboard( $who );
		}

		return ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaping εντός render*.
	}

	/* ---------- Login form (μόνο κλειδί, κεντραρισμένο — v1.3.5 #3) ---------- */

	private static function render_login(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag.
		$err = isset( $_GET['rs_pt_msg'] ) ? sanitize_key( wp_unslash( $_GET['rs_pt_msg'] ) ) : '';

		self::print_css();
		?>
		<div class="rs-portal">
			<div class="rs-pt-login-center">
				<form method="post" class="rs-pt-login">
					<?php wp_nonce_field( 'rs_portal_login', 'rs_portal_nonce' ); ?>

					<label for="rs-portal-key"><?php esc_html_e( 'Κλειδί', 'revenue-splitter' ); ?></label>
					<input type="password" id="rs-portal-key" name="rs_portal_key" required
						autocomplete="current-password" autofocus />

					<button type="submit" name="rs_portal_login" value="1"><?php esc_html_e( 'Είσοδος', 'revenue-splitter' ); ?></button>

					<?php if ( 'bad' === $err ) : ?>
						<p class="rs-pt-error"><?php esc_html_e( 'Λάθος κλειδί.', 'revenue-splitter' ); ?></p>
					<?php elseif ( 'rl' === $err ) : ?>
						<p class="rs-pt-error"><?php esc_html_e( 'Πολλές αποτυχημένες προσπάθειες — δοκίμασε ξανά σε 15 λεπτά.', 'revenue-splitter' ); ?></p>
					<?php endif; ?>
				</form>

				<?php if ( 'rq' === $err ) : ?>
					<p class="rs-pt-msg-ok"><?php esc_html_e( 'Αν το email υπάρχει στα αρχεία μας, θα λάβεις νέο κλειδί σε λίγα λεπτά.', 'revenue-splitter' ); ?></p>
				<?php endif; ?>

				<details class="rs-pt-forgot">
					<summary><?php esc_html_e( 'Ξέχασες το κλειδί;', 'revenue-splitter' ); ?></summary>
					<form method="post" class="rs-pt-reset">
						<?php wp_nonce_field( 'rs_portal_reset', 'rs_portal_reset_nonce' ); ?>

						<label for="rs-reset-email"><?php esc_html_e( 'Email', 'revenue-splitter' ); ?></label>
						<input type="email" id="rs-reset-email" name="rs_reset_email" required
							autocomplete="email" />

						<button type="submit" name="rs_portal_reset" value="1"><?php esc_html_e( 'Στείλε νέο κλειδί', 'revenue-splitter' ); ?></button>
					</form>
				</details>
			</div>
		</div>
		<?php
	}

	/* ---------- Dashboard (συνδεδεμένος δικαιούχος) ---------- */

	private static function render_dashboard( string $who ): void {

		self::print_css();

		$now   = new DateTimeImmutable( 'now', wp_timezone() );
		$today = $now->format( 'Y-m-d' );

		// ---------- ΠΕΡΙΟΔΟΣ + ΦΙΛΤΡΟ ΠΡΟΪΟΝΤΟΣ (v1.3.5 #1) ----------
		$per = self::current_period();

		// v1.3.8 (#2): multi-select IDs (sorted unique).
		$pids = self::current_products();

		// Πλήρες report περιόδου — τροφοδοτεί το dropdown (πάντα).
		$rep_all = RS_Reports::run(
			array(
				'date_start' => $per['start'],
				'date_end'   => $per['end'],
			)
		);

		// Τα προϊόντα ΠΟΥ ΑΦΟΡΟΥΝ τον δικαιούχο (έχει μερίδιο στα splits).
		$my_products = array();
		foreach ( $rep_all['products'] as $p ) {
			foreach ( $p['splits'] as $s ) {
				if ( $s['name'] === $who ) {
					$my_products[] = $p;
					break;
				}
			}
		}

		// v1.3.9 (#1): Audit fix — φιλτράρισμα στο `pids` στα `my_products`
		// ΠΡΙΝ το call στο Reports. Αλλιώς το cache-key collision-άρει
		// μεταξύ δικαιούχων (ο Α φιλτράρει το Β, αλλά το cached report περιέχει
		// και τα δύο) → potential data leak ή λάθος totals.
		$allowed_pids = array_intersect( $pids, array_column( $my_products, 'product_id' ) );
		sort( $allowed_pids );

		// Ενεργό φίλτρο (πολλαπλά IDs) → filtered report (σωστά totals).
		if ( ! empty( $allowed_pids ) ) {
			$rep = RS_Reports::run(
				array(
					'date_start'  => $per['start'],
					'date_end'    => $per['end'],
					'product_ids' => $allowed_pids,
				)
			);
		} else {
			$rep = $rep_all;
		}

		// ---------- KPIs: μήνας vs προηγούμενος + υπόλοιπο ----------
		$this_m = array( 'start' => $now->format( 'Y-m-01' ), 'end' => $today );
		$prev    = $now->modify( 'first day of previous month' );

		$rep_this = RS_Reports::run( array( 'date_start' => $this_m['start'], 'date_end' => $this_m['end'] ) );
		$rep_prev = RS_Reports::run( array( 'date_start' => $prev->format( 'Y-m-01' ), 'date_end' => $prev->format( 'Y-m-t' ) ) );

		$sales_l = RS_Reports::lifetime_beneficiaries()[ $who ] ?? 0.0;
		$inc_l   = RS_Ledger::sum( $who, '2000-01-01', $today, 'income' );
		$pay_l   = RS_Ledger::sum( $who, '2000-01-01', $today, 'payment' );
		$remain  = round( $sales_l + $inc_l - $pay_l, 2 );

		$this_share = self::share_of( $rep_this, $who );
		$prev_share = self::share_of( $rep_prev, $who );

		// v1.3.5: δ% έναντι προηγούμενου μήνα (▲/▼).
		$delta_txt = '';
		if ( $prev_share > 0 ) {
			$delta     = ( $this_share - $prev_share ) / $prev_share * 100.0;
			$arrow     = $delta >= 0 ? '▲' : '▼';
			$cls       = $delta >= 0 ? 'rs-up' : 'rs-down';
			$delta_txt = '<small class="' . esc_attr( $cls ) . '">' . $arrow . ' ' . esc_html( number_format_i18n( abs( $delta ), 1 ) ) . '%</small>';
		}

		// Το μερίδιό μου στην ΕΝΕΡΓΗ περίοδο/φίλτρο.
		$period_share = self::share_of( $rep, $who );

		// ---------- ΦΠΑ ανά συντελεστή (v1.3.5 #1 — δικά του προϊόντα) ----------
		$by_rate = array();
		foreach ( $rep['products'] as $p ) {
			$has_share = false;
			foreach ( $p['splits'] as $s ) {
				if ( $s['name'] === $who ) {
					$has_share = true;
					break;
				}
			}
			if ( ! $has_share ) {
				continue; // Με φίλτρο «όλα»: προϊόντα άλλων μόνο — skip.
			}

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

		// ---------- Chart: 6/12 μήνες (v1.3.5 #1) ----------
		$months = array();
		for ( $i = $per['chart'] - 1; $i >= 0; $i-- ) {
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

		// ---------- Ιστορικό συναλλαγών (v1.3.5 #1) ----------
		$history = RS_Ledger::for_beneficiary( $who );

		$cur = self::currency_fmt();

		$logout = wp_nonce_url( add_query_arg( 'rs_logout', '1', self::portal_url() ), 'rs_portal' );

		$csv_base = add_query_arg(
			array(
				'rs_portal_csv' => '1',
				self::GP_PERIOD  => $per['preset'],
				self::GP_START   => $per['start'],
				self::GP_END     => $per['end'],
			),
			self::portal_url()
		);
		// Multi-value: τα rs_p_product[] ΓΡΑΦΟΝΤΑΙ ως ξεχωριστά params
		// (array key θα overwrite-άρει το προηγούμενο — bug admin pattern).
		foreach ( $pids as $cp ) {
			$csv_base .= '&' . rawurlencode( self::GP_PROD . '[]' ) . '=' . rawurlencode( (string) $cp );
		}
		$csv = wp_nonce_url( $csv_base, 'rs_portal_csv' );
		?>
		<div class="rs-portal">
			<div class="rs-pt-head">
				<strong class="rs-name-pill" style="background:<?php echo esc_attr( RS_Admin_UI::ben_color( $who ) ); ?>;color:<?php echo esc_attr( RS_Admin_UI::chip_fg( RS_Admin_UI::ben_color( $who ) ) ); ?>;"><?php echo esc_html( $who ); ?></strong>
				<span>
					<a href="<?php echo esc_url( $csv ); ?>"><?php esc_html_e( 'CSV', 'revenue-splitter' ); ?></a> ·
					<a href="<?php echo esc_url( $logout ); ?>"><?php esc_html_e( 'Αποσύνδεση', 'revenue-splitter' ); ?></a>
				</span>
			</div>

			<form method="get" class="rs-pt-filters">
				<select name="<?php echo esc_attr( self::GP_PERIOD ); ?>">
					<option value="month"<?php selected( $per['preset'], 'month' ); ?>><?php esc_html_e( 'Τρέχων μήνας', 'revenue-splitter' ); ?></option>
					<option value="prev_month"<?php selected( $per['preset'], 'prev_month' ); ?>><?php esc_html_e( 'Προηγούμενος μήνας', 'revenue-splitter' ); ?></option>
					<option value="7d"<?php selected( $per['preset'], '7d' ); ?>><?php esc_html_e( 'Τελευταίες 7 ημέρες', 'revenue-splitter' ); ?></option>
					<option value="30d"<?php selected( $per['preset'], '30d' ); ?>><?php esc_html_e( 'Τελευταίες 30 ημέρες', 'revenue-splitter' ); ?></option>
					<option value="year"<?php selected( $per['preset'], 'year' ); ?>><?php esc_html_e( 'Τρέχον έτος', 'revenue-splitter' ); ?></option>
					<option value="custom"<?php selected( $per['preset'], 'custom' ); ?>><?php esc_html_e( 'Προσαρμοσμένο', 'revenue-splitter' ); ?></option>
				</select>

				<input type="date" name="<?php echo esc_attr( self::GP_START ); ?>" value="<?php echo esc_attr( $per['start'] ); ?>" />
				<input type="date" name="<?php echo esc_attr( self::GP_END ); ?>" value="<?php echo esc_attr( $per['end'] ); ?>" />

				<span class="rs-prod-picker">
					<input type="search" id="rs-prod-search" class="rs-pt-prod-search"
						placeholder="<?php esc_attr_e( 'Αναζήτηση προϊόντος…', 'revenue-splitter' ); ?>" />
										<select id="rs-prod-multi" class="rs-pt-multi" name="<?php echo esc_attr( self::GP_PROD ); ?>[]" multiple size="5">
						<?php foreach ( $my_products as $mp ) : ?>
							<option value="<?php echo esc_attr( (string) $mp['product_id'] ); ?>"
								<?php selected( in_array( (int) $mp['product_id'], $pids, true ) ); ?>>
								<?php echo esc_html( (string) $mp['product_id'] . ' — ' . $mp['title'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<span class="rs-prod-hint"><?php esc_html_e( 'Ctrl/Cmd + click για πολλαπλή επιλογή. Καμία επιλογή = όλα.', 'revenue-splitter' ); ?></span>
				</span>

				<button type="submit" class="rs-pt-btn"><?php esc_html_e( 'Εφαρμογή', 'revenue-splitter' ); ?></button>
			</form>

			<div class="rs-kpis">
				<div class="rs-kpi">
					<span class="rs-kpi-label"><?php esc_html_e( 'Αυτόν τον μήνα', 'revenue-splitter' ); ?></span>
					<strong><?php echo esc_html( $cur( $this_share ) ); ?></strong>
					<?php echo $delta_txt; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped εντός. ?>
				</div>
				<div class="rs-kpi">
					<span class="rs-kpi-label"><?php esc_html_e( 'Μερίδιό σου (περιόδου)', 'revenue-splitter' ); ?></span>
					<strong><?php echo esc_html( $cur( $period_share ) ); ?></strong>
					<small class="rs-pt-kpi-sub"><?php echo esc_html( $per['label'] . ': ' . RS_Lang::fmt_date( $per['start'] ) . ' → ' . RS_Lang::fmt_date( $per['end'] ) ); ?></small>
				</div>
				<div class="rs-kpi">
					<span class="rs-kpi-label"><?php esc_html_e( 'Αποπληρωτέο υπόλοιπο', 'revenue-splitter' ); ?></span>
					<strong><?php echo esc_html( $cur( $remain ) ); ?></strong>
				</div>
			</div>

			<div class="rs-pt-chart-head">
				<h3><?php esc_html_e( 'Τελευταίοι μήνες', 'revenue-splitter' ); ?></h3>
				<form method="get" class="rs-pt-chart-range">
					<input type="hidden" name="<?php echo esc_attr( self::GP_PERIOD ); ?>" value="<?php echo esc_attr( $per['preset'] ); ?>" />
					<input type="hidden" name="<?php echo esc_attr( self::GP_START ); ?>" value="<?php echo esc_attr( $per['start'] ); ?>" />
					<input type="hidden" name="<?php echo esc_attr( self::GP_END ); ?>" value="<?php echo esc_attr( $per['end'] ); ?>" />
					<?php foreach ( $pids as $cp ) : ?>
						<input type="hidden" name="<?php echo esc_attr( self::GP_PROD ); ?>[]" value="<?php echo esc_attr( (string) $cp ); ?>" />
					<?php endforeach; ?>
					<select name="<?php echo esc_attr( self::GP_CHART ); ?>" onchange="this.form.submit()">
						<option value="6"<?php selected( $per['chart'], 6 ); ?>><?php esc_html_e( '6 μήνες', 'revenue-splitter' ); ?></option>
						<option value="12"<?php selected( $per['chart'], 12 ); ?>><?php esc_html_e( '12 μήνες', 'revenue-splitter' ); ?></option>
					</select>
				</form>
			</div>
			<div class="rs-pt-chart">
				<?php foreach ( $months as $mm ) :
					$h = $max_m > 0 ? max( 3, (int) round( ( (float) $mm['value'] / $max_m ) * 100 ) ) : 3;
					?>
					<div class="rs-pt-col" title="<?php echo esc_attr( $mm['label'] . ': ' . $cur( $mm['value'] ) ); ?>">
						<span class="rs-pt-val"><?php echo esc_html( $cur( $mm['value'] ) ); ?></span>
						<div class="rs-pt-bar" style="height:<?php echo esc_attr( (string) $h ); ?>%;"></div>
						<small><?php echo esc_html( $mm['label'] ); ?></small>
					</div>
				<?php endforeach; ?>
			</div>

			<h3><?php esc_html_e( 'Ανά προϊόν', 'revenue-splitter' ); ?> — <?php echo esc_html( $per['label'] ); ?></h3>
			<table class="rs-pt-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Προϊόν', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Τεμ.', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Πλήρης', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Έκπτωση', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Μέση έκπτωση (%)', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Κουπόνια', 'revenue-splitter' ); ?></th>
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
				foreach ( $rep['products'] as $p ) :
					$mine = null;
					foreach ( $p['splits'] as $s ) {
						if ( $s['name'] === $who ) {
							$mine = $s;
							break;
						}
					}
					if ( null === $mine ) {
						continue; // Δεν αφορά αυτόν τον δικαιούχο.
					}
					$rows++;
					?>
					<tr>
						<td><?php echo esc_html( $p['title'] ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $p['qty'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $p['qty_full'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $p['qty_disc'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $p['disc_pct'], 1 ) ); ?>%</td>
						<td class="num">
							<?php if ( ! empty( $p['coupons'] ) ) : ?>
								<?php echo esc_html( implode( ', ', $p['coupons'] ) ); ?>
							<?php else : ?>
								<span class="rs-pt-muted">—</span>
							<?php endif; ?>
						</td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $p['qty_free'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $cur( $p['gross'] ) ); ?></td>
						<td class="num">−<?php echo esc_html( $cur( $p['vat'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $cur( $p['net'] ) ); ?></td>
						<td class="num"><?php echo esc_html( RS_Admin_UI::product_stock( (int) $p['product_id'] ) ); ?></td>
						<td class="num"><strong><?php echo esc_html( $cur( $mine['amount'] ) ); ?></strong> <small><?php echo esc_html( number_format_i18n( $mine['percent'], 1 ) ); ?>%</small></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( 0 === $rows ) : ?>
					<tr><td colspan="12" class="rs-empty"><?php esc_html_e( 'Καμία πώληση σε αυτή την περίοδο.', 'revenue-splitter' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( ! empty( $by_rate ) ) : ?>
				<h3><?php esc_html_e( 'ΦΠΑ ανά συντελεστή', 'revenue-splitter' ); ?></h3>
				<table class="rs-pt-table">
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

			<h3><?php esc_html_e( 'Ιστορικό συναλλαγών', 'revenue-splitter' ); ?></h3>
			<table class="rs-pt-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Ημερομηνία', 'revenue-splitter' ); ?></th>
						<th><?php esc_html_e( 'Τύπος', 'revenue-splitter' ); ?></th>
						<th class="num"><?php esc_html_e( 'Ποσό', 'revenue-splitter' ); ?></th>
						<th><?php esc_html_e( 'Αιτιολογία', 'revenue-splitter' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $history ) ) : ?>
					<tr><td colspan="4" class="rs-empty"><?php esc_html_e( 'Καμία εγγραφή.', 'revenue-splitter' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $history as $e ) : ?>
					<tr>
							<td><?php echo esc_html( RS_Lang::fmt_date( $e['date'] ) ); ?></td>
						<td><?php echo esc_html( 'income' === $e['type'] ? __( 'Έσοδο', 'revenue-splitter' ) : __( 'Πληρωμή', 'revenue-splitter' ) ); ?></td>
						<td class="num"><strong><?php echo esc_html( ( 'income' === $e['type'] ? ( $e['amount'] < 0 ? '−' : '+' ) : '−' ) . $cur( abs( (float) $e['amount'] ) ) ); ?></strong></td>
						<td><?php echo esc_html( $e['note'] ); ?></td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<p class="rs-pt-note">
				<?php esc_html_e( 'Το «αποπληρωτέο υπόλοιπο» = all-time μερίδια από πωλήσεις + έσοδα εκτός πωλήσεων − πληρωμές που έχεις λάβει.', 'revenue-splitter' ); ?>
			</p>

			<hr class="rs-pt-sep" />

			<h3><?php esc_html_e( 'Μηνιαία αναφορά email', 'revenue-splitter' ); ?></h3>
			<?php $my_email = RS_Emails::get_email( $who ); ?>
			<?php if ( '' === $my_email ) : ?>
				<p class="rs-pt-note">
					<?php esc_html_e( 'Δεν έχει οριστεί email για το όνομά σου — ενημέρωσε τον εκδότη για να μπορείς να ενεργοποιήσεις τη μηνιαία αναφορά.', 'revenue-splitter' ); ?>
				</p>
			<?php else : ?>
				<p class="rs-pt-note">
					<?php echo esc_html( sprintf( __( 'Η αναφορά θα στέλνεται στο %s.', 'revenue-splitter' ), $my_email ) ); ?>
				</p>
				<form method="post" class="rs-pt-inline">
					<?php wp_nonce_field( 'rs_portal_report', 'rs_portal_report_nonce' ); ?>
					<input type="hidden" name="rs_portal_report_toggle" value="1" />
					<button type="submit" class="<?php echo esc_attr( RS_Emails::is_opted_in( $who ) ? 'rs-pt-btn-on' : 'rs-pt-btn' ); ?>">
						<?php echo RS_Emails::is_opted_in( $who ) ? esc_html__( 'Απενεργοποίηση αναφοράς', 'revenue-splitter' ) : esc_html__( 'Ενεργοποίηση αναφοράς', 'revenue-splitter' ); ?>
					</button>
				</form>
				<?php if ( RS_Emails::is_opted_in( $who ) ) : ?>
					<p class="rs-pt-note"><?php esc_html_e( 'Η αναφορά είναι ενεργή — στέλνεται τις πρώτες μέρες κάθε μήνα και καλύπτει τον προηγούμενο.', 'revenue-splitter' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>

			<?php self::footer(); ?>
		</div>
		<?php
	}

	/** Μερίδιο του δικαιούχου από ένα report. */
	private static function share_of( array $report, string $who ): float {
		foreach ( $report['beneficiaries'] as $b ) {
			if ( $b['name'] === $who ) {
				return (float) $b['amount'];
			}
		}
		return 0.0;
	}

	/* ---------- CSV export (σέβεται περίοδο + προϊόν — v1.3.5 #1) ---------- */

	private static function stream_csv( string $who ): void {

		$per = self::current_period();

		// v1.3.8 (#2): ίδιο φίλτρο με το dashboard του portal.
		$pids = self::current_products();

		$run = array(
			'date_start' => $per['start'],
			'date_end'   => $per['end'],
		);

		// Πλήρες report περιόδου πρώτα — για τα δικά του προϊόντα.
		$rep_all = RS_Reports::run( $run );

		$my_ids = array();
		foreach ( $rep_all['products'] as $p ) {
			foreach ( $p['splits'] as $s ) {
				if ( $s['name'] === $who ) {
					$my_ids[] = (int) $p['product_id'];
					break;
				}
			}
		}

		// v1.5.0 polish (#3): ομοιομορφία με το render_dashboard() (v1.3.9 #1) —
		// το φίλτρο περιορίζεται στα δικά του προϊόντα ΠΡΙΝ το δεύτερο call,
		// κανένα crafted rs_p_product[] δεν περνάει στο Reports.
		$allowed_pids = array_intersect( $pids, $my_ids );
		sort( $allowed_pids );

		if ( ! empty( $allowed_pids ) ) {
			$rep = RS_Reports::run(
				array(
					'date_start'  => $per['start'],
					'date_end'    => $per['end'],
					'product_ids' => $allowed_pids,
				)
			);
		} else {
			$rep = $rep_all;
		}
		$today = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );

		$ascii = trim( (string) preg_replace( '/[^A-Za-z0-9_-]+/', '-', $who ), '-' );
		if ( '' === $ascii ) {
			$ascii = 'beneficiary';
		}
		header(
			'Content-Disposition: attachment; '
			. 'filename="portal-' . $ascii . '-' . $today . '.csv"'
			. "; filename*=UTF-8''" . rawurlencode( 'portal-' . $who . '-' . $today . '.csv' )
		);

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM.

		$cur = self::currency_fmt();

		fputcsv( $out, array( RS_Admin_UI::csv_cell( $who ), $per['start'], $per['end'] ), ',', '"', '\\' );
		fputcsv( $out, array(), ',', '"', '\\' );

		fputcsv(
			$out,
			array(
				__( 'Προϊόν', 'revenue-splitter' ),
				__( 'Τεμ.', 'revenue-splitter' ),
				__( 'Πλήρης', 'revenue-splitter' ),
				__( 'Έκπτωση', 'revenue-splitter' ),
				__( 'Μέση έκπτωση (%)', 'revenue-splitter' ),
				__( 'Κουπόνια', 'revenue-splitter' ),
				__( 'Δωρεάν', 'revenue-splitter' ),
				__( 'Καθαρό', 'revenue-splitter' ),
				__( 'Ποσοστό', 'revenue-splitter' ),
				__( 'Μερίδιο', 'revenue-splitter' ),
			),
			',',
			'"',
			'\\'
		);

		foreach ( $rep['products'] as $p ) {
			foreach ( $p['splits'] as $s ) {
				if ( $s['name'] !== $who ) {
					continue;
				}
				fputcsv(
					$out,
					array(
						RS_Admin_UI::csv_cell( $p['title'] ),
						(int) $p['qty'],
						(int) $p['qty_full'],
						(int) $p['qty_disc'],
						$p['disc_pct'],
						RS_Admin_UI::csv_cell( implode( ' ', $p['coupons'] ) ),
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
		fputcsv( $out, array( __( 'Αποπληρωτέο υπόλοιπο', 'revenue-splitter' ), $cur( ( RS_Reports::lifetime_beneficiaries()[ $who ] ?? 0.0 ) + RS_Ledger::sum( $who, '2000-01-01', $today, 'income' ) - RS_Ledger::sum( $who, '2000-01-01', $today, 'payment' ) ) ), ',', '"', '\\' );

		fclose( $out );
		exit;
	}

	/* =====================================================================
	 * Frontend helpers
	 * =================================================================== */

	private static function print_css(): void {
		?>
<style>
.rs-portal { font-family: system-ui, sans-serif; margin: 1.5em 0; color: #eae8fa;
	background: #101218; border: 1px solid #2b2e36; border-radius: 8px; padding: 24px 28px;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.35); }
.rs-portal *, .rs-portal *::before, .rs-portal *::after { box-sizing: border-box; }
.rs-pt-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1em; }
.rs-portal h3 { color: #f6f4ff; }

/* v1.3.5 (#3): κεντραρισμένο login ΜΟΝΟ με κλειδί. */
.rs-pt-login-center { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 1.5em 0; }
.rs-pt-login { max-width: 320px; width: 100%; display: grid; gap: 8px; }
.rs-pt-login label { font-weight: 600; font-size: 0.85em; color: #eae8fa; }
.rs-pt-login input { padding: 9px 11px; border: 1px solid #3a3f52; border-radius: 3px; width: 100%;
	background: #1a1d26; color: #f0eefc; }
.rs-pt-login button { padding: 9px 14px; border: 0; border-radius: 3px; background: #6d4aff; color: #fff; font-weight: 600; cursor: pointer; }
.rs-pt-login button:hover { background: #8263ff; }

/* v1.3.5 (#1): φίλτρα περιόδου/προϊόντος. */
.rs-pt-filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0 0 1em; }
.rs-pt-filters select,
.rs-pt-filters input[type="date"] { padding: 6px 8px; border: 1px solid #3a3f52; border-radius: 3px; background: #1a1d26; color: #f0eefc; min-height: 34px; }
.rs-pt-btn { padding: 7px 14px; border: 0; border-radius: 3px; background: #6d4aff; color: #fff; font-weight: 600; cursor: pointer; }
.rs-pt-btn:hover { background: #8263ff; }

.rs-pt-error { color: #ff9a9a; font-weight: 600; margin: 0; }

.rs-pt-table { width: 100%; border-collapse: collapse; margin-top: 0.5em; }
.rs-pt-table th, .rs-pt-table td { border: 1px solid #2e3240; padding: 6px 10px; text-align: left; font-size: 0.92em; }
.rs-pt-table th { background: #1d2029; color: #e6e1fd; }
.rs-pt-table tbody tr:nth-child(2n) td { background: #181b23; }
.rs-pt-table tbody tr:hover td { background: #1e212c; }
.rs-pt-table .num { text-align: right; font-variant-numeric: tabular-nums; }
.rs-pt-table small, .rs-pt-muted { color: #b7b2d6; }

.rs-pt-chart-head { display: flex; justify-content: space-between; align-items: baseline; }
.rs-pt-chart-range select { padding: 3px 6px; border: 1px solid #3a3f52; border-radius: 3px; background: #1a1d26; color: #f0eefc; }
.rs-pt-chart { display: flex; align-items: flex-end; gap: 8px; height: 140px; margin: 0.5em 0 1.5em; }
.rs-pt-col { flex: 1; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; height: 100%; text-align: center; }
.rs-pt-bar { width: 70%; background: #6d4aff; border-radius: 3px 3px 0 0; min-height: 3px; }
.rs-pt-col small { margin-top: 4px; font-size: 0.72em; color: #b7b2d6; }
.rs-pt-val { font-size: 0.68em; color: #b7b2d6; margin-bottom: 2px; white-space: nowrap; }

.rs-kpis { display: flex; gap: 12px; flex-wrap: wrap; margin: 1em 0; }
.rs-kpi { flex: 1 1 180px; background: #17191f; border: 1px solid #2e3240; border-left: 3px solid #6d4aff; border-radius: 6px; padding: 10px 14px; }
.rs-kpi strong { display: block; font-size: 1.25em; margin-top: 2px; font-variant-numeric: tabular-nums; color: #f6f4ff; }
.rs-kpi-label { font-size: 0.72em; text-transform: uppercase; letter-spacing: 0.06em; color: #b7b2d6; }
.rs-kpi .rs-up { color: #8fe6ab; font-weight: 600; }
.rs-kpi .rs-down { color: #ff9a9a; font-weight: 600; }
.rs-pt-kpi-sub { display: block; margin-top: 3px; color: #b7b2d6; }

.rs-portal h3 { margin: 1.4em 0 0.4em; font-size: 1.05em; }
.rs-pt-note { color: #b7b2d6; font-size: 0.85em; }
.rs-empty { text-align: center; color: #b7b2d6; font-style: italic; }

/* v1.3.8 (#2): multi-select προϊόντων + αναζήτηση (portal). */
.rs-prod-picker { display: flex; flex-direction: column; gap: 4px; min-width: 240px; }
.rs-pt-prod-search { min-width: 0; width: 100%; padding: 6px 8px; border: 1px solid #3a3f52; border-radius: 3px; background: #1a1d26; color: #f0eefc; }
select.rs-pt-multi { min-height: 32px; padding: 2px 6px; border: 1px solid #3a3f52; border-radius: 3px; background: #1a1d26; color: #f0eefc; }
select.rs-pt-multi option { background: #1e212b; color: #f0eefc; }
select.rs-pt-multi option:checked { background: #6d4aff; color: #fff; }
.rs-prod-hint { font-size: 11px; color: #8b87a3; }

/* vNext (#1/#3): forgot-key form + toggle μηνιαίας αναφοράς. */
.rs-pt-msg-ok { color: #8fe6ab; font-weight: 600; margin: 0; text-align: center; }
.rs-pt-forgot { margin-top: 1em; border-top: 1px solid #2b2e36; padding-top: 0.8em; }
.rs-pt-forgot summary { cursor: pointer; font-size: 0.85em; color: #b7b2d6; }
.rs-pt-reset { max-width: 320px; margin: 0 auto; display: grid; gap: 8px; padding-top: 0.8em; }
.rs-pt-reset label { font-weight: 600; font-size: 0.85em; color: #eae8fa; }
.rs-pt-reset input { padding: 9px 11px; border: 1px solid #3a3f52; border-radius: 3px; width: 100%; background: #1a1d26; color: #f0eefc; }
.rs-pt-reset button { padding: 9px 14px; border: 0; border-radius: 3px; background: #6d4aff; color: #fff; font-weight: 600; cursor: pointer; }
.rs-pt-sep { border: 0; border-top: 1px solid #2b2e36; margin: 1.5em 0; }
.rs-pt-inline { margin: 0.4em 0 0; }
.rs-pt-btn-on { padding: 7px 14px; border: 0; border-radius: 3px; background: #3a3f52; color: #fff; font-weight: 600; cursor: pointer; }
.rs-footer {
	margin-top: 28px;
	padding-top: 12px;
	border-top: 1px solid #23262f;
	font-size: 13px;
	color: #a29dc0;
}
.rs-footer a { color: #beb1ff; text-decoration: none; }
.rs-footer a:hover { text-decoration: underline; }
.rs-footer-cta { margin: 10px 0 0; }
.rs-portal a.rs-kofi { display: inline-block; background: #6d4aff; color: #fff; padding: 6px 18px; border-radius: 999px; text-decoration: none; font-weight: 600; }
.rs-portal a.rs-kofi:hover { background: #8263ff; color: #fff; }
.rs-name-pill { display: inline-block; padding: 2px 12px; border-radius: 12px; }

/* Dark theme helpers */
.rs-portal input[type="date"] { color-scheme: dark; }
.rs-portal a { color: #beb1ff; }
.rs-portal a:hover { color: #d4c9ff; }
</style>
<script>
/* v1.3.8 (#2): delegated φίλτρο αναζήτησης του multi-select (portal inline —
 * το admin.js ΔΕΝ φορτώνεται στο frontend). */
document.addEventListener('input', function (e) {
	if (!e.target.matches('#rs-prod-search')) return;
	var sel = document.getElementById('rs-prod-multi');
	if (!sel) return;
	var q = String(e.target.value || '').trim().toLowerCase();
	for (var i = 0; i < sel.options.length; i++) {
		var t = sel.options[i].textContent.toLowerCase();
		sel.options[i].hidden = (q !== '' && t.indexOf(q) === -1);
	}
});
</script>
		<?php
	}

	private static function currency_fmt(): callable {

		// v1.7.0: το WooCommerce επιστρέφει HTML entity (π.χ. &euro;) —
		// decode σε πραγματικό χαρακτήρα, ώστε το CSV να μην περιέχει
		// raw entities (στο HTML το esc_html το ξανα-κωδικοποιεί σωστά).
		$symbol = function_exists( 'get_woocommerce_currency_symbol' )
			? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
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
			$url = is_singular() ? get_permalink() : home_url( '/' );
		}

		return $url;
	}

	/**
	 * Footer του FRONTEND portal (ίδιο pattern με το RS_Admin_UI::footer(),
	 * χωρίς admin links — ο δικαιούχος δεν έχει πρόσβαση στο wp-admin).
	 * Styles στο inline CSS του portal (.rs-footer, .rs-kofi).
	 */
	private static function footer(): void {
		?>
		<p class="rs-footer">
			<?php
			printf(
				/* translators: %s: όνομα δημιουργού */
				esc_html__( 'Made with ❤ by %s', 'revenue-splitter' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			·
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a> ·
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="rs-footer-cta">
			<a class="rs-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'revenue-splitter' ); ?>
			</a>
		</p>
		<?php
	}
}
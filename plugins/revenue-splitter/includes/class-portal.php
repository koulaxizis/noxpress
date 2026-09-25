<?php
/**
 * RS_Portal — Author Portal (frontend, v1.3.2 → v1.3.5).
 *
 * Shortcode: [author_portal] (+ alias [rs_portal]).
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
 *  - Rate limit: 5 αποτυχημένες / 15' ανά (key-hash + IP) (rs_rl_*),
 *    μηδενίζεται στο επιτυχές login.
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

		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_admin_keys' ) );
	}

	/* =====================================================================
	 * Admin: διαχείριση κλειδιών (χωρίς αλλαγές σε λειτουργία)
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
				<?php esc_html_e( 'Ο δικαιούχος μπαίνει στη σελίδα του portal ([author_portal]) ΜΟΝΟ με το κλειδί του — το κλειδί ταυτοποιεί μοναδικά τον κάτοχό του.', 'revenue-splitter' ); ?>
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

	/** Το όνομα του συνδεδεμένου δικαιούχου ή null. */
	public static function current(): ?string {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only token.
		$tok = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) : '';

		if ( '' === $tok || ! preg_match( '/^[A-Za-z0-9]{20,64}$/', $tok ) ) {
			return null;
		}

		$name = get_transient( 'rs_tok_' . $tok );

		if ( ! is_string( $name ) || '' === $name ) {
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
		// php 7.3+ syntax: [name, value, expire, path, domain, secure, httponly, samesite]
		setcookie( self::COOKIE_NAME, $tok, [
			'expires'  => time() + self::TOK_TTL,
			'path'     => COOKIEPATH ?: '/',
			'domain'   => COOKIE_DOMAIN,
			'secure'   => is_ssl(),
			'httponly' => true, // Audit fix: blocking JS access.
			'samesite' => 'Lax',
		] );
	}

	private static function end_session(): void {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only token.
		$tok = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) : '';

		if ( '' !== $tok ) {
			delete_transient( 'rs_tok_' . $tok );
		}

		setcookie( self::COOKIE_NAME, '', [
			'expires'  => time() - HOUR_IN_SECONDS,
			'path'     => COOKIEPATH ?: '/',
			'domain'   => COOKIE_DOMAIN,
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		] );
	}

	/* =====================================================================
	 * Routing (init — πριν από output)
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

			if ( self::too_many_attempts( $key ) ) {
				wp_safe_redirect( add_query_arg( 'rs_pt_msg', 'rl', $back ) );
				exit;
			}

			$who = self::who_for_key( $key );

			if ( null !== $who ) {
				self::clear_attempts( $key );
				self::start_session( $who );
				wp_safe_redirect( remove_query_arg( 'rs_pt_msg', $back ) );
				exit;
			}

			self::record_attempt( $key );
			wp_safe_redirect( add_query_arg( 'rs_pt_msg', 'bad', $back ) );
			exit;
		}
	}

	/* ---------- Rate limiting (rs_rl_*) — πλέον key+IP, όχι name+IP ---------- */

	/** Hash του υποβληθέντος κλειδιού για το rate-limit key (ποτέ raw). */
	private static function rl_key( string $attempt ): string {
		return 'rs_rl_' . md5( 'k:' . hash( 'sha256', $attempt ) . '|' . self::client_ip() );
	}

	private static function too_many_attempts( string $attempt ): bool {
		$n = (int) get_transient( self::rl_key( $attempt ) );
		return $n >= self::RL_MAX;
	}

	private static function record_attempt( string $attempt ): void {
		$k = self::rl_key( $attempt );
		$n = (int) get_transient( $k );
		set_transient( $k, $n + 1, self::RL_WIN );
	}

	private static function clear_attempts( string $attempt ): void {
		delete_transient( self::rl_key( $attempt ) );
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
		// entre benef (Α φιλτράρει το Β, αλλά το cached report περιέχει
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
				<strong style="display:inline-block;background:<?php echo esc_attr( RS_Admin_UI::ben_color( $who ) ); ?>;color:<?php echo esc_attr( RS_Admin_UI::chip_fg( RS_Admin_UI::ben_color( $who ) ) ); ?>;padding:2px 12px;border-radius:12px;"><?php echo esc_html( $who ); ?></strong>
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

			<p class="rs-pt-kofi">
				<a href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
					☕ <?php esc_html_e( 'Στήριξε το project στο Ko-fi', 'revenue-splitter' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/** Μείγμα του δικαιούχου από ένα report. */
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
.rs-portal { font-family: system-ui, sans-serif; margin: 1.5em 0; color: #1d2327; }
.rs-portal *, .rs-portal *::before, .rs-portal *::after { box-sizing: border-box; }
.rs-pt-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1em; }

/* v1.3.5 (#3): κεντραρισμένο login ΜΟΝΟ με κλειδί. */
.rs-pt-login-center { display: flex; justify-content: center; padding: 1.5em 0; }
.rs-pt-login { max-width: 320px; width: 100%; display: grid; gap: 8px; }
.rs-pt-login label { font-weight: 600; font-size: 0.85em; }
.rs-pt-login input { padding: 9px 11px; border: 1px solid #c3c4c7; border-radius: 3px; width: 100%; }
.rs-pt-login button { padding: 9px 14px; border: 0; border-radius: 3px; background: #6d4aff; color: #fff; font-weight: 600; cursor: pointer; }

/* v1.3.5 (#1): φίλτρα περιόδου/προϊόντος. */
.rs-pt-filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0 0 1em; }
.rs-pt-filters select,
.rs-pt-filters input[type="date"] { padding: 6px 8px; border: 1px solid #c3c4c7; border-radius: 3px; background: #fff; min-height: 34px; }
.rs-pt-btn { padding: 7px 14px; border: 0; border-radius: 3px; background: #6d4aff; color: #fff; font-weight: 600; cursor: pointer; }

.rs-pt-error { color: #b32d2e; font-weight: 600; margin: 0; }

.rs-pt-table { width: 100%; border-collapse: collapse; margin-top: 0.5em; }
.rs-pt-table th, .rs-pt-table td { border: 1px solid #e2e4e7; padding: 6px 10px; text-align: left; font-size: 0.92em; }
.rs-pt-table th { background: #f6f7f7; }
.rs-pt-table .num { text-align: right; font-variant-numeric: tabular-nums; }
.rs-pt-table small, .rs-pt-muted { color: #787c82; }

.rs-pt-chart-head { display: flex; justify-content: space-between; align-items: baseline; }
.rs-pt-chart-range select { padding: 3px 6px; border: 1px solid #c3c4c7; border-radius: 3px; background: #fff; }
.rs-pt-chart { display: flex; align-items: flex-end; gap: 8px; height: 140px; margin: 0.5em 0 1.5em; }
.rs-pt-col { flex: 1; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; height: 100%; text-align: center; }
.rs-pt-bar { width: 70%; background: #6d4aff; border-radius: 3px 3px 0 0; min-height: 3px; }
.rs-pt-col small { margin-top: 4px; font-size: 0.72em; color: #787c82; }
.rs-pt-val { font-size: 0.68em; color: #787c82; margin-bottom: 2px; white-space: nowrap; }

.rs-kpis { display: flex; gap: 12px; flex-wrap: wrap; margin: 1em 0; }
.rs-kpi { flex: 1 1 180px; border: 1px solid #e2e4e7; border-radius: 4px; padding: 10px 14px; }
.rs-kpi strong { display: block; font-size: 1.25em; margin-top: 2px; font-variant-numeric: tabular-nums; }
.rs-kpi-label { font-size: 0.72em; text-transform: uppercase; letter-spacing: 0.06em; color: #787c82; }
.rs-kpi .rs-up { color: #0a7c3a; font-weight: 600; }
.rs-kpi .rs-down { color: #b32d2e; font-weight: 600; }
.rs-pt-kpi-sub { display: block; margin-top: 3px; color: #787c82; }

.rs-portal h3 { margin: 1.4em 0 0.4em; font-size: 1.05em; }
.rs-pt-note { color: #787c82; font-size: 0.85em; }
.rs-pt-kofi { margin: 0.8em 0 0; font-size: 0.85em; }
.rs-pt-kofi a { display: inline-block; background: #6d4aff; color: #fff; padding: 6px 16px; border-radius: 999px; text-decoration: none; font-weight: 600; }
.rs-empty { text-align: center; color: #787c82; font-style: italic; }

/* v1.3.8 (#2): multi-select προϊόντων + αναζήτηση (portal). */
.rs-prod-picker { display: flex; flex-direction: column; gap: 4px; min-width: 240px; }
.rs-pt-prod-search { min-width: 0; width: 100%; padding: 6px 8px; border: 1px solid #c3c4c7; border-radius: 3px; }
select.rs-pt-multi { min-height: 32px; padding: 2px 6px; border: 1px solid #c3c4c7; border-radius: 3px; background: #fff; }
.rs-prod-hint { font-size: 11px; color: #787c82; }
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
			$url = is_singular() ? get_permalink() : home_url( '/' );
		}

		return $url;
	}

	/**
	 * Footer με clickable cross-links + Ko-fi support CTA
	 * (ενιαίο pattern με το RS_Admin_UI::footer()).
	 */
	private static function footer(): void {
		?>
		<p class="rs-footer">
			Made with &lt;3 by
			<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a> ·
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a> ·
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="rs-footer" style="margin-top:10px;">
			<a href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer"
				style="display:inline-block;background:#6d4aff;color:#fff;padding:6px 18px;border-radius:999px;text-decoration:none;font-weight:600;">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'revenue-splitter' ); ?>
			</a>
		</p>
		<?php
	}
}
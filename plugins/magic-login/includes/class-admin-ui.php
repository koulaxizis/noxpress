<?php
/**
 * MGL_Admin_UI — menu, settings, log, tools (Bible §3–§8, §11).
 *
 * Submenu «Magic Login» of the shared "Noxpress" menu (admin_menu
 * priority 290), slug mgl-settings, capability Magic_Login::cap(). Tabs:
 *  - settings  mode (off / test / live) and test accounts, visitor
 *              language, where the button shows, link lifetime, «remember
 *              me», rate limits, visitor texts; checks of what the button
 *              needs (the e-mail switched on, the checkout login form)
 *  - log       the newest 200 events, with counts for the last 30 days
 *  - tools     settings backup, developers
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient mgl_aui_msg_{uid}). Export: privileged GET.
 */

defined( 'ABSPATH' ) || exit;

final class MGL_Admin_UI {

	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'mgl-settings';
	const NONCE       = 'mgl_admin';
	const NONCE_FIELD = 'mgl_nonce';
	const NONCE_BAK   = 'mgl_backup';
	const MAX_IMPORT  = 262144;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 290 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MGL_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label. */
	private static function tabs(): array {
		return array(
			'settings' => __( 'Ρυθμίσεις', 'magic-login' ),
			'log'      => __( 'Ιστορικό', 'magic-login' ),
			'tools'    => __( 'Εργαλεία', 'magic-login' ),
		);
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {
		// The shared top-level "Noxpress" is created by Noxpress Core
		// (priority 5, Bible §16): a submenu only here.
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Magic Login', 'magic-login' ),
			__( 'Magic Login', 'magic-login' ),
			Magic_Login::cap(),
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'magic-login' ) . '</a>'
		);
		return $links;
	}

	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	private static function q( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	private static function current_tab(): string {
		$tab = sanitize_key( self::q( 'tab' ) );
		return isset( self::tabs()[ $tab ] ) ? $tab : 'settings';
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'mgl-admin', MGL_URL . 'assets/admin.css', array(), MGL_VERSION );
	}

	/** Suggested text for the site's privacy policy (Settings → Privacy). */
	public static function privacy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'Όταν ζητάς σύνδεση χωρίς κωδικό, κρατάμε για λίγα λεπτά (μέχρι να λήξει ή να χρησιμοποιηθεί) ένα κρυπτογραφημένο αποτύπωμα του συνδέσμου που σου στέλνουμε με email. Για να εμποδίσουμε την κατάχρηση μετράμε τα αιτήματα ανά λογαριασμό και ανά διεύθυνση IP για έως μία ώρα, χωρίς να αποθηκεύουμε τη διεύθυνση IP. Κρατάμε ιστορικό με τις τελευταίες 200 ενέργειες (ώρα, αριθμός λογαριασμού, ενέργεια), χωρίς email και διευθύνσεις IP.', 'magic-login' ) . '</p>';
		wp_add_privacy_policy_content( 'Magic Login', wp_kses_post( $text ) );
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Magic_Login::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'magic-login' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap mgl-wrap">
			<h1><?php esc_html_e( 'Magic Login', 'magic-login' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Σύνδεση πελατών χωρίς κωδικό: στη φόρμα σύνδεσης ο πελάτης γράφει το email του και παίρνει σύνδεσμο μίας χρήσης. Ο κωδικός μένει ως εναλλακτική, και οι λογαριασμοί προσωπικού (διαχειριστές, διαχειριστές καταστήματος, συντάκτες) συνδέονται μόνο με κωδικό.', 'magic-login' ); ?></p>

			<?php
			self::print_notices( self::take_msgs() );
			if ( Magic_Login::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το MGL_DISABLE είναι ενεργό στο wp-config.php: κανένα κουμπί και κανένας σύνδεσμος δεν λειτουργεί.', 'magic-login' ) . '</p></div>';
			}
			?>

			<nav class="nav-tab-wrapper mgl-tabs">
				<?php
				foreach ( self::tabs() as $slug => $label ) {
					printf(
						'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
						esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $slug ) ),
						$slug === $tab ? ' nav-tab-active' : '',
						esc_html( $label )
					);
				}
				?>
			</nav>

			<?php
			switch ( $tab ) {
				case 'log':
					self::render_log();
					break;
				case 'tools':
					self::render_tools();
					break;
				default:
					self::render_settings();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	private static function checkbox( string $name, bool $on, string $label ): void {
		printf(
			'<label class="mgl-check"><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label )
		);
	}

	private static function radio( string $name, string $value, string $current, string $label ): void {
		printf(
			'<label class="mgl-check"><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $value, $current, false ),
			esc_html( $label )
		);
	}

	private static function badge( string $text, string $kind ): string {
		return '<span class="mgl-badge mgl-badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function fmt(): string {
		return get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	}

	/** One visitor text: Greek and English inputs. */
	private static function text_pair( string $key, string $label, array $s ): void {
		$max = MGL_Settings::texts()[ $key ][2];
		?>
		<span class="mgl-label"><?php echo esc_html( $label ); ?></span>
		<div class="mgl-pair">
			<label><span class="mgl-lang"><?php esc_html_e( 'Ελληνικά', 'magic-login' ); ?></span>
				<input type="text" class="mgl-wide" name="mgl_t_<?php echo esc_attr( $key ); ?>_el" value="<?php echo esc_attr( $s[ 't_' . $key . '_el' ] ); ?>" maxlength="<?php echo (int) $max; ?>" /></label>
			<label><span class="mgl-lang"><?php esc_html_e( 'English (κενό = το ελληνικό)', 'magic-login' ); ?></span>
				<input type="text" class="mgl-wide" name="mgl_t_<?php echo esc_attr( $key ); ?>_en" value="<?php echo esc_attr( $s[ 't_' . $key . '_en' ] ); ?>" maxlength="<?php echo (int) $max; ?>" /></label>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	private static function render_settings(): void {
		$s         = MGL_Settings::get();
		$email_on  = MGL_Emails::enabled();
		$reminder  = 'yes' === get_option( 'woocommerce_enable_checkout_login_reminder', 'no' );
		$myaccount = function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'myaccount' ) > 0;
		$test_url  = function_exists( 'wc_get_page_permalink' ) ? add_query_arg( 'mgl-test', '1', wc_get_page_permalink( 'myaccount' ) ) : '';
		?>
		<h2 class="mgl-h2"><?php esc_html_e( 'Έλεγχος', 'magic-login' ); ?></h2>
		<div class="mgl-zone mgl-status">
			<p>
				<?php
				echo $email_on ? self::badge( __( 'Ενεργό', 'magic-login' ), 'ok' ) : self::badge( __( 'Κλειστό', 'magic-login' ), 'danger' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
				esc_html_e( 'Το email «Magic Login: σύνδεσμος σύνδεσης» του WooCommerce.', 'magic-login' );
				?>
				<a href="<?php echo esc_url( MGL_Emails::settings_url() ); ?>"><?php esc_html_e( 'Θέμα, επικεφαλίδα και ενεργοποίηση', 'magic-login' ); ?></a>
			</p>
			<?php if ( ! $email_on ) : ?>
				<p class="mgl-warn"><?php esc_html_e( 'Με το email κλειστό το κουμπί δεν εμφανίζεται πουθενά.', 'magic-login' ); ?></p>
			<?php endif; ?>
			<p>
				<?php echo $myaccount ? self::badge( __( 'Ναι', 'magic-login' ), 'ok' ) : self::badge( __( 'Όχι', 'magic-login' ), 'danger' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?>
				<?php esc_html_e( 'Σελίδα «Ο λογαριασμός μου» (εκεί ανοίγει ο σύνδεσμος του email).', 'magic-login' ); ?>
			</p>
			<p>
				<?php echo $reminder ? self::badge( __( 'Ναι', 'magic-login' ), 'ok' ) : self::badge( __( 'Όχι', 'magic-login' ), 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?>
				<?php esc_html_e( 'Φόρμα σύνδεσης στο ταμείο (WooCommerce → Ρυθμίσεις → Λογαριασμοί και απόρρητο, η σύνδεση σε υπάρχοντα λογαριασμό στο ταμείο).', 'magic-login' ); ?>
			</p>
			<p class="description"><?php esc_html_e( 'Τα email φεύγουν μέσω wp_mail, όπως όλα τα email του WooCommerce (π.χ. FluentSMTP). Το Checkout block δεν έχει φόρμα σύνδεσης: εκεί ο πελάτης συνδέεται από το «Ο λογαριασμός μου».', 'magic-login' ); ?></p>
		</div>

		<form method="post">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="mgl_action" value="save_settings" />

			<h2 class="mgl-h2"><?php esc_html_e( 'Λειτουργία', 'magic-login' ); ?></h2>
			<div class="mgl-zone">
				<?php
				self::radio( 'mgl_mode', 'off', $s['mode'], __( 'Ανενεργό: κανένα κουμπί, κανένας σύνδεσμος δεν λειτουργεί', 'magic-login' ) );
				self::radio( 'mgl_mode', 'test', $s['mode'], __( 'Δοκιμή: το κουμπί φαίνεται μόνο με ?mgl-test στη διεύθυνση και σύνδεσμοι πάνε μόνο στους λογαριασμούς δοκιμής', 'magic-login' ) );
				self::radio( 'mgl_mode', 'live', $s['mode'], __( 'Ενεργό: όλοι οι πελάτες και οι λογαριασμοί χονδρικής', 'magic-login' ) );
				?>
				<span class="mgl-label"><?php esc_html_e( 'Email των λογαριασμών δοκιμής (ένα ανά γραμμή, έως 10)', 'magic-login' ); ?></span>
				<textarea name="mgl_testers" class="mgl-testers"><?php echo esc_textarea( implode( "\n", $s['testers'] ) ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'Πελάτες, όχι διαχειριστές: οι λογαριασμοί προσωπικού δεν συνδέονται ποτέ με σύνδεσμο. Σελίδα δοκιμής (αποσυνδεδεμένος):', 'magic-login' ); ?>
					<?php if ( '' !== $test_url ) : ?>
						<a href="<?php echo esc_url( $test_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $test_url ); ?></a>
					<?php endif; ?>
				</p>

				<span class="mgl-label"><?php esc_html_e( 'Γλώσσα των κειμένων και του email για τους επισκέπτες', 'magic-login' ); ?></span>
				<?php
				self::radio( 'mgl_lang', 'auto', $s['lang'], __( 'Αυτόματα, από τη γλώσσα του site', 'magic-login' ) );
				self::radio( 'mgl_lang', 'el', $s['lang'], __( 'Ελληνικά', 'magic-login' ) );
				self::radio( 'mgl_lang', 'en', $s['lang'], __( 'English', 'magic-login' ) );
				?>
			</div>

			<h2 class="mgl-h2"><?php esc_html_e( 'Πού εμφανίζεται το κουμπί', 'magic-login' ); ?></h2>
			<div class="mgl-zone">
				<?php
				self::checkbox( 'mgl_account', $s['account'], __( 'Στη φόρμα σύνδεσης του «Ο λογαριασμός μου»', 'magic-login' ) );
				self::checkbox( 'mgl_checkout', $s['checkout'], __( 'Στη φόρμα σύνδεσης του ταμείου (κλασικό ταμείο)', 'magic-login' ) );
				?>
				<p class="description"><?php esc_html_e( 'Μετά τη σύνδεση ο πελάτης επιστρέφει εκεί που ζήτησε τον σύνδεσμο: στο ταμείο με το καλάθι του ή στη σελίδα του λογαριασμού.', 'magic-login' ); ?></p>
			</div>

			<h2 class="mgl-h2"><?php esc_html_e( 'Σύνδεσμος', 'magic-login' ); ?></h2>
			<div class="mgl-zone">
				<span class="mgl-label"><?php esc_html_e( 'Ισχύς του συνδέσμου (λεπτά, 5–60)', 'magic-login' ); ?></span>
				<input type="number" class="mgl-num" name="mgl_ttl" value="<?php echo esc_attr( (string) $s['ttl'] ); ?>" min="5" max="60" step="1" />
				<p class="description"><?php esc_html_e( 'Κάθε σύνδεσμος χρησιμοποιείται μία φορά. Ένας νέος ακυρώνει τον προηγούμενο, και μια αλλαγή κωδικού ακυρώνει όποιον εκκρεμεί.', 'magic-login' ); ?></p>
				<?php self::checkbox( 'mgl_remember', $s['remember'], __( 'Ο πελάτης μένει συνδεδεμένος 14 ημέρες (όπως με το «Να με θυμάσαι»)', 'magic-login' ) ); ?>
			</div>

			<h2 class="mgl-h2"><?php esc_html_e( 'Όρια', 'magic-login' ); ?></h2>
			<div class="mgl-zone">
				<span class="mgl-label"><?php esc_html_e( 'Σύνδεσμοι ανά λογαριασμό σε 15 λεπτά (1–10)', 'magic-login' ); ?></span>
				<input type="number" class="mgl-num" name="mgl_per_acct" value="<?php echo esc_attr( (string) $s['per_acct'] ); ?>" min="1" max="10" step="1" />
				<span class="mgl-label"><?php esc_html_e( 'Αιτήματα ανά διεύθυνση IP σε μία ώρα (5–200)', 'magic-login' ); ?></span>
				<input type="number" class="mgl-num" name="mgl_per_ip" value="<?php echo esc_attr( (string) $s['per_ip'] ); ?>" min="5" max="200" step="1" />
				<p class="description"><?php esc_html_e( 'Μετρούν και τα αιτήματα για email χωρίς λογαριασμό, ώστε το όριο να μη δείχνει ποια email είναι πελάτες. Οι διευθύνσεις IP δεν αποθηκεύονται, μόνο ένα αποτύπωμά τους για μία ώρα.', 'magic-login' ); ?></p>
			</div>

			<h2 class="mgl-h2"><?php esc_html_e( 'Κείμενα για τους επισκέπτες', 'magic-login' ); ?></h2>
			<div class="mgl-zone">
				<p class="description"><?php esc_html_e( 'Το {minutes} γίνεται η ισχύς του συνδέσμου και το {email} το email του λογαριασμού, κρυμμένο εν μέρει (π.χ. k***@example.gr). Το κείμενο του email αλλάζει από το WooCommerce → Ρυθμίσεις → Emails ή με template του θέματος.', 'magic-login' ); ?></p>
				<?php
				self::text_pair( 'button', __( 'Κουμπί στη φόρμα σύνδεσης', 'magic-login' ), $s );
				self::text_pair( 'hint', __( 'Οδηγία κάτω από το κουμπί', 'magic-login' ), $s );
				self::text_pair( 'sent', __( 'Μήνυμα μετά το αίτημα (ίδιο, είτε υπάρχει λογαριασμός είτε όχι)', 'magic-login' ), $s );
				self::text_pair( 'empty', __( 'Όταν το πεδίο email είναι κενό', 'magic-login' ), $s );
				self::text_pair( 'limit', __( 'Όταν φτάσει το όριο αιτημάτων', 'magic-login' ), $s );
				self::text_pair( 'title', __( 'Τίτλος της σελίδας επιβεβαίωσης', 'magic-login' ), $s );
				self::text_pair( 'confirm', __( 'Κείμενο της σελίδας επιβεβαίωσης', 'magic-login' ), $s );
				self::text_pair( 'go', __( 'Κουμπί της σελίδας επιβεβαίωσης', 'magic-login' ), $s );
				self::text_pair( 'invalid', __( 'Σύνδεσμος που έληξε ή χρησιμοποιήθηκε', 'magic-login' ), $s );
				self::text_pair( 'stale', __( 'Σελίδα που έμεινε ανοιχτή πολύ ώρα', 'magic-login' ), $s );
				?>
			</div>

			<p class="mgl-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'magic-login' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Log
	 * ------------------------------------------------------------------- */

	private static function render_log(): void {
		$rows   = MGL_Tokens::entries();
		$events = MGL_Tokens::events();
		$since  = time() - 30 * DAY_IN_SECONDS;
		$counts = array_fill_keys( array_keys( $events ), 0 );
		foreach ( $rows as $r ) {
			if ( $r['t'] >= $since ) {
				++$counts[ $r['e'] ];
			}
		}
		?>
		<h2 class="mgl-h2"><?php esc_html_e( 'Τελευταίες 30 ημέρες', 'magic-login' ); ?></h2>
		<div class="mgl-zone">
			<p class="mgl-counts">
				<?php
				foreach ( array( 'sent', 'used', 'invalid', 'limited', 'mail_fail' ) as $e ) {
					echo self::badge( $events[ $e ] . ': ' . number_format_i18n( $counts[ $e ] ), 'mail_fail' === $e && $counts[ $e ] > 0 ? 'danger' : 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
				}
				?>
			</p>
			<p class="description"><?php esc_html_e( 'Από τις εγγραφές που κρατά το ιστορικό (οι 200 νεότερες).', 'magic-login' ); ?></p>
		</div>

		<h2 class="mgl-h2"><?php esc_html_e( 'Ιστορικό', 'magic-login' ); ?></h2>
		<table class="widefat striped mgl-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Ώρα', 'magic-login' ); ?></th>
					<th><?php esc_html_e( 'Λογαριασμός', 'magic-login' ); ?></th>
					<th><?php esc_html_e( 'Ενέργεια', 'magic-login' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="3" class="mgl-empty"><?php esc_html_e( 'Καμία εγγραφή ακόμα.', 'magic-login' ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $rows as $r ) :
					$user = $r['u'] > 0 ? get_user_by( 'id', $r['u'] ) : false;
					$cls  = 'used' === $r['e'] || 'sent' === $r['e'] ? 'is-ok' : ( 'mail_fail' === $r['e'] ? 'is-fail' : ( 'limited' === $r['e'] || 'invalid' === $r['e'] ? 'is-retry' : '' ) );
					?>
					<tr>
						<td><?php echo esc_html( wp_date( self::fmt(), $r['t'] ) ); ?></td>
						<td>
							<?php if ( $user instanceof WP_User ) : ?>
								<a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php echo esc_html( $user->display_name ); ?></a> <span class="mgl-muted">#<?php echo (int) $user->ID; ?></span>
							<?php elseif ( $r['u'] > 0 ) : ?>
								<span class="mgl-muted">#<?php echo (int) $r['u']; ?></span>
							<?php else : ?>
								<span class="mgl-muted">—</span>
							<?php endif; ?>
						</td>
						<td class="<?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $events[ $r['e'] ] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" class="mgl-mt-8">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="mgl_action" value="clear_log" />
			<p><button type="submit" class="button"><?php esc_html_e( 'Καθαρισμός ιστορικού', 'magic-login' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tools
	 * ------------------------------------------------------------------- */

	private static function render_tools(): void {
		?>
		<h2 class="mgl-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'magic-login' ); ?></h2>
		<div class="mgl-zone">
			<form method="post" enctype="multipart/form-data" class="mgl-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'mgl_backup_nonce' ); ?>
				<input type="file" name="mgl_import_file" accept=".json,application/json" />
				<button type="submit" name="mgl_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'magic-login' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=tools&mgl_export=1' ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'magic-login' ); ?></a>
			<p class="description mgl-mt-8"><?php esc_html_e( 'Μόνο οι ρυθμίσεις: σύνδεσμοι και ιστορικό δεν μπαίνουν στο αρχείο. Το θέμα και η επικεφαλίδα του email είναι ρυθμίσεις του WooCommerce.', 'magic-login' ); ?></p>
		</div>

		<h2 class="mgl-h2"><?php esc_html_e( 'Για developers', 'magic-login' ); ?></h2>
		<div class="mgl-zone">
			<table class="widefat striped mgl-table">
				<tbody>
					<tr><td><code>mgl_user_allowed</code> <?php esc_html_e( '(φίλτρο)', 'magic-login' ); ?></td><td><?php esc_html_e( 'Αν ένας χρήστης μπορεί να συνδεθεί με σύνδεσμο: ( $ok, $user ). Οι λογαριασμοί προσωπικού μένουν πάντα εκτός.', 'magic-login' ); ?></td></tr>
					<tr><td><code>mgl_text</code> <?php esc_html_e( '(φίλτρο)', 'magic-login' ); ?></td><td><?php esc_html_e( 'Αλλάζει ένα κείμενο του επισκέπτη: ( $text, $key, $lang ).', 'magic-login' ); ?></td></tr>
					<tr><td><code>mgl_logged_in</code> <?php esc_html_e( '(action)', 'magic-login' ); ?></td><td><?php esc_html_e( 'Μετά από σύνδεση με σύνδεσμο: ( $user_id ). Τρέχει και το wp_login του WordPress.', 'magic-login' ); ?></td></tr>
					<tr><td><code>emails/mgl-link.php</code></td><td><?php esc_html_e( 'Template του email, αντικαθίσταται από το θέμα στο yourtheme/woocommerce/emails/ (και plain/).', 'magic-login' ); ?></td></tr>
					<tr><td><code>MGL_DISABLE</code></td><td><?php esc_html_e( 'Στο wp-config.php: κανένα κουμπί και κανένας σύνδεσμος δεν λειτουργεί.', 'magic-login' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* =====================================================================
	 * POST routes (admin_init, PRG)
	 * =================================================================== */

	public static function route(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}

		if ( isset( $_POST['mgl_import'] ) ) {
			if ( ! isset( $_POST['mgl_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['mgl_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( Magic_Login::cap() ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'magic-login' ) );
			}
			self::redirect( 'tools', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['mgl_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Magic_Login::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'magic-login' ) );
		}

		switch ( sanitize_key( wp_unslash( $_POST['mgl_action'] ) ) ) {
			case 'save_settings':
				self::redirect( 'settings', self::do_save_settings() );
				break;
			case 'clear_log':
				delete_option( MGL_Tokens::LOG );
				self::redirect( 'log', array( self::msg( 'success', __( 'Το ιστορικό καθαρίστηκε.', 'magic-login' ) ) ) );
				break;
			default:
				self::redirect( 'settings', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'magic-login' ) ) ) );
		}
	}

	private static function do_save_settings(): array {
		$s             = MGL_Settings::get();
		$s['mode']     = sanitize_key( self::post( 'mgl_mode' ) );
		$s['lang']     = sanitize_key( self::post( 'mgl_lang' ) );
		$s['account']  = '1' === self::post( 'mgl_account' );
		$s['checkout'] = '1' === self::post( 'mgl_checkout' );
		$s['remember'] = '1' === self::post( 'mgl_remember' );
		$s['ttl']      = (int) self::post( 'mgl_ttl' );
		$s['per_acct'] = (int) self::post( 'mgl_per_acct' );
		$s['per_ip']   = (int) self::post( 'mgl_per_ip' );
		$s['testers']  = sanitize_textarea_field( self::post( 'mgl_testers' ) );
		foreach ( array_keys( MGL_Settings::texts() ) as $k ) {
			foreach ( array( 'el', 'en' ) as $l ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
				if ( isset( $_POST[ 'mgl_t_' . $k . '_' . $l ] ) ) {
					$s[ 't_' . $k . '_' . $l ] = self::post( 'mgl_t_' . $k . '_' . $l );
				}
			}
		}
		MGL_Settings::save( $s );

		$s    = MGL_Settings::get();
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'magic-login' ) ) );
		if ( 'test' === $s['mode'] && empty( $s['testers'] ) ) {
			$msgs[] = self::msg( 'warning', __( 'Λειτουργία δοκιμής χωρίς λογαριασμούς δοκιμής: κανένας σύνδεσμος δεν θα σταλεί.', 'magic-login' ) );
		}
		if ( 'off' !== $s['mode'] && ! MGL_Emails::enabled() ) {
			$msgs[] = self::msg( 'warning', __( 'Το email του Magic Login είναι κλειστό στο WooCommerce: το κουμπί δεν εμφανίζεται.', 'magic-login' ) );
		}
		foreach ( $s['testers'] as $e ) {
			$u = get_user_by( 'email', $e );
			if ( ! $u instanceof WP_User ) {
				/* translators: %s: e-mail address */
				$msgs[] = self::msg( 'warning', sprintf( __( 'Λογαριασμός δοκιμής χωρίς χρήστη: %s', 'magic-login' ), $e ) );
			} elseif ( ! MGL_Tokens::allowed( $u ) ) {
				/* translators: %s: e-mail address */
				$msgs[] = self::msg( 'warning', sprintf( __( 'Ο λογαριασμός δοκιμής %s είναι λογαριασμός προσωπικού και δεν θα πάρει σύνδεσμο.', 'magic-login' ), $e ) );
			}
		}
		return $msgs;
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		set_transient( 'mgl_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/* =====================================================================
	 * Backup (export GET / import POST)
	 * =================================================================== */

	public static function route_export(): void {
		if ( self::SLUG !== self::current_page() || '' === self::q( 'mgl_export' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( Magic_Login::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'magic-login' ) );
		}
		$state = array(
			'plugin'  => 'magic-login',
			'version' => MGL_VERSION,
			'options' => array(
				MGL_Settings::OPT => MGL_Settings::get(),
			),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="magic-login-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	/** Strict import through MGL_Settings::sanitize(); an invalid file changes nothing. */
	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['mgl_import_file']['error'] ) ? (int) $_FILES['mgl_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['mgl_import_file']['size'] ) ? (int) $_FILES['mgl_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'magic-login' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['mgl_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['mgl_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'magic-login' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['mgl_import_file']['tmp_name'] ), true );
		// phpcs:enable
		if ( ! is_array( $state ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'magic-login' ) ) );
		}
		$blob = isset( $state['options'][ MGL_Settings::OPT ] ) ? $state['options'][ MGL_Settings::OPT ] : null;
		if ( is_string( $blob ) ) {
			$blob = json_decode( $blob, true );
		}
		if ( ! is_array( $blob ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Magic Login).', 'magic-login' ) ) );
		}
		MGL_Settings::save( $blob );
		return array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'magic-login' ) ) );
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'mgl_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'mgl_aui_msg_' . get_current_user_id() );
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

	/* =====================================================================
	 * Footer (Bible §7 — identical pattern)
	 * =================================================================== */

	private static function footer(): void {
		?>
		<p class="mgl-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'magic-login' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="mgl-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="mgl-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'magic-login' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="mgl-footer-cta">
			<a class="mgl-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'magic-login' ); ?>
			</a>
			<a class="mgl-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'magic-login' ); ?>
			</a>
		</p>
		<?php
	}
}

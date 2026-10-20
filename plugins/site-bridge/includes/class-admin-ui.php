<?php
/**
 * SBR_Admin_UI — menu, pages and the consent screen (Bible §3–§8, §11).
 *
 * Submenu «Site Bridge» of the shared "Noxpress" menu (admin_menu
 * priority 170), slug sbr-settings, capability manage_options. Tabs:
 *  - overview     roles (Hub, Site), MCP address, how to connect Claude,
 *                 diagnostics
 *  - access       Site role: level, write window, run-as user, masks,
 *                 IP list, allowed zip hosts, pairing with a Hub
 *  - sites        Hub role: paired sites, add / remove, status, window
 *  - connections  Hub role: OAuth connections, revoke, kill switch,
 *                 allowed clients, DCR, limits, Hub IP list
 *  - log          audit log with filters and CSV export
 *
 * admin.php?page=sbr-authorize&req=… is the OAuth consent screen: a
 * hidden page (parent options.php) drawn on admin_init (after WordPress'
 * own login and page checks) and ended with exit.
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient sbr_aui_msg_{uid}). CSV: privileged GET.
 */

defined( 'ABSPATH' ) || exit;

final class SBR_Admin_UI {

	const CAP         = 'manage_options';
	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'sbr-settings';
	const NONCE       = 'sbr_admin';
	const NONCE_FIELD = 'sbr_nonce';
	const NONCE_CSV   = 'sbr_csv';
	const PER_PAGE    = 50;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 170 );
		add_action( 'admin_init', array( __CLASS__, 'consent' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_csv' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'key_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SBR_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label. */
	private static function tabs(): array {
		$t = array(
			'overview' => __( 'Επισκόπηση', 'site-bridge' ),
			'access'   => __( 'Πρόσβαση', 'site-bridge' ),
		);
		if ( SBR_Settings::v( 'hub' ) ) {
			$t['sites']       = __( 'Sites', 'site-bridge' );
			$t['connections'] = __( 'Συνδέσεις', 'site-bridge' );
		}
		$t['log'] = __( 'Ιστορικό', 'site-bridge' );
		return $t;
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {
		// The shared top-level "Noxpress" is created by Noxpress Core
		// (priority 5, Bible §16): a submenu only here.
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Site Bridge', 'site-bridge' ),
			__( 'Site Bridge', 'site-bridge' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
		// The consent screen: a page WordPress lets administrators open
		// (admin.php refuses unknown pages before admin_init), hidden
		// from the menu. consent() draws it on admin_init and exits.
		add_submenu_page(
			'options.php',
			__( 'Site Bridge: έγκριση σύνδεσης', 'site-bridge' ),
			'',
			self::CAP,
			'sbr-authorize',
			'__return_null'
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'site-bridge' ) . '</a>'
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
		return isset( self::tabs()[ $tab ] ) ? $tab : 'overview';
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'sbr-admin', SBR_URL . 'assets/admin.css', array(), SBR_VERSION );
		wp_enqueue_script( 'sbr-admin', SBR_URL . 'assets/admin.js', array(), SBR_VERSION, true );
		wp_localize_script(
			'sbr-admin',
			'sbrAdmin',
			array(
				'copied' => __( 'Αντιγράφηκε', 'site-bridge' ),
			)
		);
	}

	/** After the salts changed, the key pair was replaced: pairings are gone. */
	public static function key_notice(): void {
		$t = (int) get_option( 'sbr_key_reset', 0 );
		if ( ! $t || self::SLUG !== self::current_page() || ! current_user_can( self::CAP ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'Τα κλειδιά του Site Bridge δεν διαβάζονταν πια (άλλαξαν τα salts του wp-config.php;) και φτιάχτηκαν νέα. Κάνε ξανά σύζευξη των sites με το Hub.', 'site-bridge' )
			. '</p></div>';
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'site-bridge' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap sbr-wrap">
			<h1><?php esc_html_e( 'Site Bridge', 'site-bridge' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Το Claude διαχειρίζεται τα sites σου μέσω MCP, από τους δικούς σου servers. Κάθε site αποφασίζει μόνο του τι επιτρέπεται.', 'site-bridge' ); ?></p>

			<?php
			if ( Site_Bridge::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το SBR_DISABLE είναι ενεργό στο wp-config.php: όλα τα endpoints είναι κλειστά.', 'site-bridge' ) . '</p></div>';
			}
			self::print_notices( self::take_msgs() );
			?>

			<nav class="nav-tab-wrapper sbr-tabs">
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
				case 'access':
					self::render_access();
					break;
				case 'sites':
					self::render_sites();
					break;
				case 'connections':
					self::render_connections();
					break;
				case 'log':
					self::render_log();
					break;
				default:
					self::render_overview();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	private static function form_open( string $action, string $class = '' ): void {
		echo '<form method="post" action="" class="' . esc_attr( $class ) . '">';
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		echo '<input type="hidden" name="sbr_action" value="' . esc_attr( $action ) . '">';
	}

	private static function when( int $ts ): string {
		return $ts > 0 ? wp_date( 'Y-m-d H:i', $ts ) : '—';
	}

	/* ----- Overview ----- */

	private static function render_overview(): void {
		$s = SBR_Settings::get();
		?>
		<p class="description sbr-warn"><?php esc_html_e( 'Το Site Bridge δίνει στο Claude απομακρυσμένη πρόσβαση διαχειριστή στα sites που επιτρέπεις. Άνοιξε μόνο ό,τι χρειάζεσαι και κράτα τα sites σε Ανάγνωση.', 'site-bridge' ); ?></p>
		<p class="description"><?php esc_html_e( 'Δουλεύει με το Claude. Δεν είναι προϊόν της Anthropic και δεν σχετίζεται με αυτήν.', 'site-bridge' ); ?></p>
		<h2 class="sbr-h2"><?php esc_html_e( 'Ρόλοι', 'site-bridge' ); ?></h2>
		<div class="sbr-zone">
			<?php self::form_open( 'save_roles' ); ?>
				<label class="sbr-check"><input type="checkbox" name="sbr_site" value="1" <?php checked( $s['site'] ); ?>>
					<strong><?php esc_html_e( 'Site', 'site-bridge' ); ?></strong> — <?php esc_html_e( 'το Claude μπορεί να δουλέψει σε αυτό το site, μέσα από ένα Hub, με όσα επιτρέπει η καρτέλα Πρόσβαση.', 'site-bridge' ); ?></label>
				<label class="sbr-check"><input type="checkbox" name="sbr_hub" value="1" <?php checked( $s['hub'] ); ?>>
					<strong><?php esc_html_e( 'Hub', 'site-bridge' ); ?></strong> — <?php esc_html_e( 'εδώ συνδέεται το Claude (OAuth) και από εδώ φτάνει σε όλα τα sites. Ένα Hub αρκεί για όλα.', 'site-bridge' ); ?></label>
				<p class="sbr-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'site-bridge' ); ?></button></p>
			</form>
		</div>

		<?php if ( $s['hub'] ) : ?>
			<h2 class="sbr-h2"><?php esc_html_e( 'Σύνδεση του Claude', 'site-bridge' ); ?></h2>
			<div class="sbr-zone">
				<div class="sbr-copyrow"><span><?php esc_html_e( 'Διεύθυνση MCP:', 'site-bridge' ); ?></span> <code id="sbr-mcp-url"><?php echo esc_html( SBR_OAuth::resource() ); ?></code>
					<button type="button" class="button" data-sbr-copy="sbr-mcp-url"><?php esc_html_e( 'Αντιγραφή', 'site-bridge' ); ?></button></div>
				<ol class="sbr-steps">
					<li><?php esc_html_e( 'Στο Claude: Settings → Connectors → Add custom connector. Όνομα «Site Bridge», URL η διεύθυνση πιο πάνω. Άφησε κενά τα πεδία OAuth client.', 'site-bridge' ); ?></li>
					<li><?php esc_html_e( 'Πάτα Connect: ανοίγει αυτό το site, μπαίνεις με τον λογαριασμό σου και εγκρίνεις τα scopes και τα sites.', 'site-bridge' ); ?></li>
					<li><?php esc_html_e( 'Οι συνδέσεις φαίνονται και ανακαλούνται στην καρτέλα Συνδέσεις.', 'site-bridge' ); ?></li>
				</ol>
				<p class="description"><?php
					/* translators: %s: key id */
					printf( esc_html__( 'Κλειδί του Hub: %s', 'site-bridge' ), '<code>' . esc_html( SBR_Hub::hub_id() ) . '</code>' );
				?></p>
			</div>
		<?php endif; ?>

		<h2 class="sbr-h2"><?php esc_html_e( 'Διάγνωση', 'site-bridge' ); ?></h2>
		<div class="sbr-zone">
			<?php
			$checks = get_transient( 'sbr_diag_' . get_current_user_id() );
			if ( is_array( $checks ) ) {
				echo '<ul class="sbr-checks">';
				foreach ( $checks as $c ) {
					$mark = 'ok' === $c[0] ? '✓' : ( 'warn' === $c[0] ? '!' : '✗' );
					echo '<li class="' . esc_attr( $c[0] ) . '">' . esc_html( $mark . ' ' . $c[1] ) . '</li>';
				}
				echo '</ul>';
			} else {
				echo '<p class="description">' . esc_html__( 'Ελέγχει HTTPS, permalinks, το header Authorization, τα metadata του OAuth, τα κλειδιά και αν μπορούν να αλλάζουν plugins από μακριά.', 'site-bridge' ) . '</p>';
			}
			self::form_open( 'diag' );
			?>
				<p class="sbr-mt-8"><button type="submit" class="button"><?php esc_html_e( 'Έλεγχος τώρα', 'site-bridge' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/** Diagnostics: array of array( ok|warn|bad, text ). */
	private static function diagnose(): array {
		$out   = array();
		$https = 0 === strpos( home_url( '/' ), 'https://' );
		$out[] = $https ? array( 'ok', __( 'Το site έχει HTTPS.', 'site-bridge' ) ) : array( 'bad', __( 'Το site δεν έχει HTTPS: το Claude και τα sites δεν θα συνδεθούν.', 'site-bridge' ) );
		$out[] = '' !== (string) get_option( 'permalink_structure' ) ? array( 'ok', __( 'Τα permalinks είναι «όμορφα» (χρειάζονται για τις διευθύνσεις του OAuth).', 'site-bridge' ) ) : array( 'bad', __( 'Τα permalinks είναι «Απλά»: διάλεξε άλλη δομή στις Ρυθμίσεις → Μόνιμοι σύνδεσμοι.', 'site-bridge' ) );
		$out[] = function_exists( 'sodium_crypto_sign_detached' ) ? array( 'ok', __( 'Υπογραφές Ed25519 διαθέσιμες.', 'site-bridge' ) ) : array( 'bad', __( 'Λείπουν οι συναρτήσεις sodium.', 'site-bridge' ) );

		$res = wp_remote_get(
			Site_Bridge::rest( 'diag' ),
			array(
				'timeout'   => 10,
				'headers'   => array( 'Authorization' => 'Bearer sbr-diag' ),
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter for loopbacks.
			)
		);
		if ( is_wp_error( $res ) ) {
			/* translators: %s: error message */
			$out[] = array( 'warn', sprintf( __( 'Το site δεν απάντησε σε αίτημα προς τον εαυτό του (%s), οπότε το header Authorization δεν ελέγχθηκε.', 'site-bridge' ), $res->get_error_message() ) );
		} else {
			$d     = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			$out[] = is_array( $d ) && ! empty( $d['authorization_header'] )
				? array( 'ok', __( 'Το header Authorization φτάνει στο WordPress.', 'site-bridge' ) )
				: array( 'bad', __( 'Ο server κόβει το header Authorization: το OAuth δεν θα δουλέψει. Ζήτησε από τον host να το περνά στην PHP (π.χ. CGIPassAuth On).', 'site-bridge' ) );
		}

		if ( SBR_Settings::v( 'hub' ) ) {
			$wk = wp_remote_get(
				home_url( '/.well-known/oauth-authorization-server' ),
				array(
					'timeout'   => 10,
					'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter for loopbacks.
				)
			);
			$d     = is_wp_error( $wk ) ? null : json_decode( (string) wp_remote_retrieve_body( $wk ), true );
			$out[] = is_array( $d ) && ( $d['issuer'] ?? '' ) === SBR_OAuth::issuer()
				? array( 'ok', __( 'Τα metadata του OAuth απαντούν στο /.well-known/.', 'site-bridge' ) )
				: array( 'warn', __( 'Το /.well-known/oauth-authorization-server δεν απαντά (ο server το κρατά για τον εαυτό του;). Το Claude θα βρει τα metadata από το 401 του endpoint, αλλά αν η σύνδεση αποτύχει, αυτό είναι το πρώτο που ελέγχεις.', 'site-bridge' ) );
			$out[] = $https || 'local' === wp_get_environment_type() ? array( 'ok', __( 'Το Hub δέχεται συνδέσεις.', 'site-bridge' ) ) : array( 'bad', __( 'Το Hub χρειάζεται HTTPS.', 'site-bridge' ) );
		}

		if ( SBR_Settings::v( 'site' ) ) {
			$out[] = SBR_Settings::run_as_user() ? array( 'ok', __( 'Ο χρήστης εκτέλεσης είναι διαχειριστής.', 'site-bridge' ) ) : array( 'bad', __( 'Δεν έχει οριστεί διαχειριστής για την εκτέλεση των εργαλείων (Πρόσβαση).', 'site-bridge' ) );
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$mods  = ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) && 'direct' === get_filesystem_method();
			$out[] = $mods ? array( 'ok', __( 'Τα plugins και τα θέματα μπορούν να εγκατασταθούν και να ενημερωθούν από μακριά.', 'site-bridge' ) ) : array( 'warn', __( 'Τα plugins και τα θέματα δεν μπορούν να αλλάξουν από μακριά (DISALLOW_FILE_MODS ή ο server θέλει FTP).', 'site-bridge' ) );
			$out[] = SBR_Site::trust() || SBR_Settings::v( 'hub' ) ? array( 'ok', __( 'Το site έχει Hub.', 'site-bridge' ) ) : array( 'warn', __( 'Το site δεν έχει γίνει ακόμα σύζευξη με Hub (Πρόσβαση → Σύζευξη).', 'site-bridge' ) );
		}
		if ( get_option( 'sbr_key_reset' ) ) {
			$out[] = array( 'warn', __( 'Τα κλειδιά ξαναφτιάχτηκαν μετά από αλλαγή των salts: κάνε ξανά τις συζεύξεις.', 'site-bridge' ) );
		}
		return $out;
	}

	/** A write window length for buttons and lists: "30 λεπτά", "3 ώρες". */
	private static function window_length( int $m ): string {
		if ( $m > 60 ) {
			/* translators: %d: hours */
			return sprintf( __( '%d ώρες', 'site-bridge' ), intdiv( $m, 60 ) );
		}
		/* translators: %d: minutes */
		return sprintf( __( '%d λεπτά', 'site-bridge' ), $m );
	}

	/* ----- Access (Site role) ----- */

	private static function render_access(): void {
		$s = SBR_Settings::get();
		if ( ! $s['site'] ) {
			echo '<div class="sbr-zone"><p>' . esc_html__( 'Ο ρόλος Site είναι κλειστός. Άνοιξέ τον στην Επισκόπηση για να μπορεί το Claude να δουλέψει σε αυτό το site.', 'site-bridge' ) . '</p></div>';
		}
		$until = SBR_Settings::window_until();
		?>
		<h2 class="sbr-h2"><?php esc_html_e( 'Παράθυρο εγγραφής', 'site-bridge' ); ?></h2>
		<div class="sbr-zone">
			<p>
				<?php
				if ( $until ) {
					/* translators: %s: time */
					echo '<span class="sbr-badge sbr-badge--warn">' . esc_html( sprintf( __( 'Ανοιχτό ως %s', 'site-bridge' ), wp_date( 'H:i', $until ) ) ) . '</span>';
				} else {
					echo '<span class="sbr-badge sbr-badge--ok">' . esc_html__( 'Κλειστό', 'site-bridge' ) . '</span>';
				}
				?>
			</p>
			<p class="description"><?php esc_html_e( 'Τα εργαλεία εγγραφής και διαχείρισης δουλεύουν μόνο όσο είναι ανοιχτό, και μόνο αν το επιτρέπει το επίπεδο πιο κάτω. Κλείνει μόνο του.', 'site-bridge' ); ?></p>
			<div class="sbr-window">
				<?php foreach ( SBR_Settings::WINDOW_MINUTES as $m ) : ?>
					<?php self::form_open( 'window_open' ); ?>
						<input type="hidden" name="sbr_minutes" value="<?php echo (int) $m; ?>">
						<button type="submit" class="button"><?php
							/* translators: %s: length, e.g. "30 minutes" */
							echo esc_html( sprintf( __( 'Άνοιγμα για %s', 'site-bridge' ), self::window_length( $m ) ) );
						?></button>
					</form>
				<?php endforeach; ?>
				<?php if ( $until ) : ?>
					<?php self::form_open( 'window_close' ); ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Κλείσιμο τώρα', 'site-bridge' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		</div>

		<?php self::form_open( 'save_access' ); ?>
		<h2 class="sbr-h2"><?php esc_html_e( 'Επίπεδο', 'site-bridge' ); ?></h2>
		<div class="sbr-zone sbr-levels">
			<?php
			$levels = array(
				'off'   => array( __( 'Κλειστό', 'site-bridge' ), __( 'Κανένα εργαλείο δεν δουλεύει.', 'site-bridge' ) ),
				'read'  => array( __( 'Ανάγνωση', 'site-bridge' ), __( 'Μόνο ανάγνωση: ρυθμίσεις, βάση (SELECT), REST GET, σελίδες, αρχεία κώδικα, logs. Η σωστή επιλογή για sites πελατών.', 'site-bridge' ) ),
				'write' => array( __( 'Εγγραφή', 'site-bridge' ), __( 'Και αλλαγές περιεχομένου: REST εγγραφές (προϊόντα, σελίδες…), options, πολυμέσα, caches. Μόνο με ανοιχτό παράθυρο.', 'site-bridge' ) ),
				'admin' => array( __( 'Διαχείριση', 'site-bridge' ), __( 'Και plugins / θέματα: εγκατάσταση, ενεργοποίηση, ενημέρωση, κανάλι Noxpress. Μόνο με ανοιχτό παράθυρο.', 'site-bridge' ) ),
			);
			foreach ( $levels as $k => $l ) {
				echo '<label><input type="radio" name="sbr_level" value="' . esc_attr( $k ) . '" ' . checked( $s['level'], $k, false ) . '> <strong>' . esc_html( $l[0] ) . '</strong><span class="sbr-hint">' . esc_html( $l[1] ) . '</span></label>';
			}
			?>
			<label class="sbr-check sbr-mt-8"><input type="checkbox" name="sbr_remote_window" value="1" <?php checked( $s['remote_window'] ); ?>>
				<?php esc_html_e( 'Το Hub μπορεί να ανοίξει το παράθυρο εγγραφής αυτού του site (κλειστό για sites πελατών)', 'site-bridge' ); ?></label>
		</div>

		<h2 class="sbr-h2"><?php esc_html_e( 'Εκτέλεση και προστασία', 'site-bridge' ); ?></h2>
		<div class="sbr-zone">
			<label class="sbr-label" for="sbr_run_as"><?php esc_html_e( 'Χρήστης εκτέλεσης', 'site-bridge' ); ?></label>
			<select id="sbr_run_as" name="sbr_run_as">
				<option value="0">—</option>
				<?php
				foreach ( get_users( array( 'role' => 'administrator', 'number' => 100, 'orderby' => 'login' ) ) as $u ) {
					echo '<option value="' . (int) $u->ID . '" ' . selected( $s['run_as'], $u->ID, false ) . '>' . esc_html( $u->user_login . ' (' . $u->display_name . ')' ) . '</option>';
				}
				?>
			</select>
			<span class="sbr-hint"><?php esc_html_e( 'Τα εργαλεία τρέχουν ως αυτός ο διαχειριστής: τα δικαιώματά του ισχύουν και το όνομά του φαίνεται στις αλλαγές.', 'site-bridge' ); ?></span>

			<label class="sbr-check sbr-mt-8"><input type="checkbox" name="sbr_pii_mask" value="1" <?php checked( $s['pii_mask'] ); ?>>
				<?php esc_html_e( 'Μάσκα σε προσωπικά δεδομένα (email, τηλέφωνο, διεύθυνση, ΤΚ, IP) σε κάθε αποτέλεσμα', 'site-bridge' ); ?></label>
			<label class="sbr-check"><input type="checkbox" name="sbr_email_admin" value="1" <?php checked( $s['email_admin'] ); ?>>
				<?php esc_html_e( 'Email στη διεύθυνση διαχειριστή μετά από κάθε εργαλείο διαχείρισης (plugins, θέματα, κανάλι)', 'site-bridge' ); ?></label>

			<label class="sbr-label" for="sbr_site_ips"><?php esc_html_e( 'IP που δέχεται το site (μία ανά γραμμή, CIDR)', 'site-bridge' ); ?></label>
			<textarea id="sbr_site_ips" name="sbr_site_ips" class="sbr-lines"><?php echo esc_textarea( $s['site_ips'] ); ?></textarea>
			<span class="sbr-hint"><?php esc_html_e( 'Κενό = κάθε IP (οι υπογραφές μένουν υποχρεωτικές). Βάλε μόνο την IP του Hub αν τη γνωρίζεις· μια λάθος IP κλειδώνει έξω το Hub.', 'site-bridge' ); ?></span>

			<label class="sbr-label" for="sbr_zip_hosts"><?php esc_html_e( 'Από πού επιτρέπεται εγκατάσταση plugin με zip (προθέματα URL)', 'site-bridge' ); ?></label>
			<textarea id="sbr_zip_hosts" name="sbr_zip_hosts" class="sbr-lines"><?php echo esc_textarea( $s['zip_hosts'] ); ?></textarea>
		</div>
		<p class="sbr-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'site-bridge' ); ?></button></p>
		</form>

		<h2 class="sbr-h2"><?php esc_html_e( 'Σύζευξη με Hub', 'site-bridge' ); ?></h2>
		<div class="sbr-zone">
			<?php
			$trust = SBR_Site::trust();
			if ( $trust ) {
				/* translators: 1: hub URL, 2: key id, 3: site id, 4: date */
				echo '<p>' . esc_html( sprintf( __( 'Συνδεδεμένο με το Hub %1$s (κλειδί %2$s) ως «%3$s» από %4$s.', 'site-bridge' ), $trust['hub_url'], $trust['hub_id'], $trust['site_id'], self::when( (int) $trust['paired'] ) ) ) . '</p>';
				$seen = (int) get_option( 'sbr_hub_seen', 0 );
				/* translators: %s: date */
				echo '<p class="description">' . esc_html( sprintf( __( 'Τελευταίο αίτημα από το Hub: %s', 'site-bridge' ), self::when( $seen ) ) ) . '</p>';
				self::form_open( 'forget_hub' );
				echo '<p><button type="submit" class="button" data-sbr-confirm="' . esc_attr__( 'Να ξεχάσει το site αυτό το Hub; Το Claude δεν θα φτάνει πια εδώ μέχρι νέα σύζευξη.', 'site-bridge' ) . '">' . esc_html__( 'Αποσύνδεση από το Hub', 'site-bridge' ) . '</button></p></form>';
			} elseif ( SBR_Settings::v( 'hub' ) ) {
				echo '<p class="description">' . esc_html__( 'Αυτό το site είναι και Hub: φαίνεται αυτόματα στη λίστα των sites του, χωρίς σύζευξη.', 'site-bridge' ) . '</p>';
			} else {
				echo '<p class="description">' . esc_html__( 'Το site δεν έχει Hub ακόμα.', 'site-bridge' ) . '</p>';
			}
			$code = get_transient( 'sbr_pair_show_' . get_current_user_id() );
			if ( is_string( $code ) && '' !== $code ) {
				echo '<p>' . esc_html__( 'Αντέγραψε τον κωδικό στο Hub (Sites → Προσθήκη site). Ισχύει 15 λεπτά και μία φορά:', 'site-bridge' ) . '</p>';
				echo '<textarea id="sbr-pair-code" class="sbr-code" readonly>' . esc_textarea( $code ) . '</textarea>';
				echo '<p><button type="button" class="button" data-sbr-copy="sbr-pair-code">' . esc_html__( 'Αντιγραφή', 'site-bridge' ) . '</button></p>';
			}
			if ( $s['site'] ) {
				self::form_open( 'pair_code' );
				echo '<p><button type="submit" class="button">' . esc_html__( 'Νέος κωδικός σύζευξης', 'site-bridge' ) . '</button></p></form>';
			}
			?>
		</div>
		<?php
	}

	/* ----- Sites (Hub role) ----- */

	private static function render_sites(): void {
		$sites = SBR_Hub::sites();
		?>
		<h2 class="sbr-h2"><?php esc_html_e( 'Sites', 'site-bridge' ); ?></h2>
		<table class="widefat sbr-table">
			<thead><tr>
				<th><?php esc_html_e( 'Id', 'site-bridge' ); ?></th>
				<th><?php esc_html_e( 'Site', 'site-bridge' ); ?></th>
				<th><?php esc_html_e( 'Επίπεδο', 'site-bridge' ); ?></th>
				<th><?php esc_html_e( 'Παράθυρο', 'site-bridge' ); ?></th>
				<th><?php esc_html_e( 'Τελευταία επαφή', 'site-bridge' ); ?></th>
				<th><?php esc_html_e( 'Ενέργειες', 'site-bridge' ); ?></th>
			</tr></thead>
			<tbody>
			<?php
			if ( ! $sites ) {
				echo '<tr><td colspan="6" class="sbr-empty">' . esc_html__( 'Κανένα site ακόμα. Πρόσθεσε ένα με κωδικό σύζευξης, ή άνοιξε τον ρόλο Site σε αυτό το site.', 'site-bridge' ) . '</td></tr>';
			}
			foreach ( $sites as $id => $site ) {
				$st = ! empty( $site['self'] ) ? SBR_Site::status() : (array) ( $site['status'] ?? array() );
				$w  = (int) ( $st['window_until'] ?? 0 );
				echo '<tr><td><code>' . esc_html( $id ) . '</code></td>';
				echo '<td>' . esc_html( (string) $site['label'] ) . '<br><span class="sbr-hint">' . esc_html( preg_replace( '#/wp-json/site-bridge/v1/?$#', '/', (string) $site['url'] ) ) . '</span>';
				if ( ! empty( $site['self'] ) ) {
					echo ' <span class="sbr-badge sbr-badge--muted">' . esc_html__( 'αυτό το site', 'site-bridge' ) . '</span>';
				}
				echo '</td><td>' . esc_html( (string) ( $st['level'] ?? '—' ) ) . '</td>';
				/* translators: %s: time */
				echo '<td>' . ( $w > time() ? esc_html( sprintf( __( 'ανοιχτό ως %s', 'site-bridge' ), wp_date( 'H:i', $w ) ) ) : esc_html__( 'κλειστό', 'site-bridge' ) ) . '</td>';
				echo '<td>' . esc_html( ! empty( $site['self'] ) ? '—' : self::when( (int) ( $site['last_seen'] ?? 0 ) ) ) . '</td><td>';
				if ( empty( $site['self'] ) ) {
					self::form_open( 'site_refresh', 'sbr-inline-form' );
					echo '<input type="hidden" name="sbr_site_id" value="' . esc_attr( $id ) . '"><button type="submit" class="button">' . esc_html__( 'Έλεγχος', 'site-bridge' ) . '</button></form>';
				}
				if ( ! empty( $site['self'] ) || ! empty( $st['remote_window'] ) ) {
					self::form_open( $w > time() ? 'site_window_close' : 'site_window_open', 'sbr-inline-form' );
					echo '<input type="hidden" name="sbr_site_id" value="' . esc_attr( $id ) . '">';
					if ( $w > time() ) {
						echo '<button type="submit" class="button">' . esc_html__( 'Κλείσιμο παραθύρου', 'site-bridge' ) . '</button></form>';
					} else {
						echo '<select name="sbr_minutes" aria-label="' . esc_attr__( 'Διάρκεια παραθύρου', 'site-bridge' ) . '">';
						foreach ( SBR_Settings::WINDOW_MINUTES as $m ) {
							echo '<option value="' . (int) $m . '"' . selected( 30, $m, false ) . '>' . esc_html( self::window_length( $m ) ) . '</option>';
						}
						echo '</select> <button type="submit" class="button">' . esc_html__( 'Άνοιγμα παραθύρου', 'site-bridge' ) . '</button></form>';
					}
				}
				if ( empty( $site['self'] ) ) {
					self::form_open( 'site_remove', 'sbr-inline-form' );
					echo '<input type="hidden" name="sbr_site_id" value="' . esc_attr( $id ) . '"><button type="submit" class="button" data-sbr-confirm="' . esc_attr__( 'Να αφαιρεθεί το site; Θα χρειαστεί νέα σύζευξη για να επιστρέψει.', 'site-bridge' ) . '">' . esc_html__( 'Αφαίρεση', 'site-bridge' ) . '</button></form>';
				}
				echo '</td></tr>';
			}
			?>
			</tbody>
		</table>

		<h2 class="sbr-h2"><?php esc_html_e( 'Προσθήκη site', 'site-bridge' ); ?></h2>
		<div class="sbr-zone">
			<p class="description"><?php esc_html_e( 'Στο άλλο site: εγκατάσταση του Site Bridge, ρόλος Site, επίπεδο στην Πρόσβαση, και «Νέος κωδικός σύζευξης». Επικόλλησε τον κωδικό εδώ.', 'site-bridge' ); ?></p>
			<?php self::form_open( 'site_add' ); ?>
				<label class="sbr-label" for="sbr_code"><?php esc_html_e( 'Κωδικός σύζευξης', 'site-bridge' ); ?></label>
				<textarea id="sbr_code" name="sbr_code" class="sbr-code" required></textarea>
				<label class="sbr-label" for="sbr_label"><?php esc_html_e( 'Όνομα (προαιρετικό, γίνεται και το id)', 'site-bridge' ); ?></label>
				<input type="text" id="sbr_label" name="sbr_label" class="sbr-wide" maxlength="60">
				<p class="sbr-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Σύζευξη', 'site-bridge' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/* ----- Connections (Hub role) ----- */

	private static function render_connections(): void {
		$s      = SBR_Settings::get();
		$grants = SBR_OAuth::grants();
		$now    = time();
		?>
		<h2 class="sbr-h2"><?php esc_html_e( 'Συνδέσεις', 'site-bridge' ); ?></h2>
		<table class="widefat sbr-table">
			<thead><tr>
				<th>#</th>
				<th><?php esc_html_e( 'Client', 'site-bridge' ); ?></th>
				<th><?php esc_html_e( 'Scopes', 'site-bridge' ); ?></th>
				<th><?php esc_html_e( 'Sites', 'site-bridge' ); ?></th>
				<th><?php esc_html_e( 'Εγκρίθηκε', 'site-bridge' ); ?></th>
				<th><?php esc_html_e( 'Λήγει', 'site-bridge' ); ?></th>
				<th><?php esc_html_e( 'Τελευταία χρήση', 'site-bridge' ); ?></th>
				<th><?php esc_html_e( 'Κλήσεις', 'site-bridge' ); ?></th>
				<th><?php esc_html_e( 'Κατάσταση', 'site-bridge' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php
			if ( ! $grants ) {
				echo '<tr><td colspan="10" class="sbr-empty">' . esc_html__( 'Καμία σύνδεση ακόμα.', 'site-bridge' ) . '</td></tr>';
			}
			foreach ( $grants as $g ) {
				$active = ! (int) $g['revoked'] && (int) $g['consent_exp'] > $now && (int) $g['refresh_exp'] > $now;
				$sites  = json_decode( (string) $g['sites'], true );
				$sites  = is_array( $sites ) ? $sites : array();
				echo '<tr><td>' . (int) $g['id'] . '</td>';
				echo '<td>' . esc_html( (string) $g['client_name'] ) . '<br><span class="sbr-hint">' . esc_html( (string) $g['client_id'] ) . '</span></td>';
				echo '<td>' . esc_html( (string) $g['scope'] ) . '</td>';
				echo '<td>' . esc_html( in_array( '*', $sites, true ) ? __( 'όλα, και όσα προστεθούν', 'site-bridge' ) : implode( ', ', $sites ) ) . '</td>';
				echo '<td>' . esc_html( self::when( (int) $g['created'] ) ) . '</td>';
				echo '<td>' . esc_html( self::when( (int) $g['consent_exp'] ) ) . '</td>';
				echo '<td>' . esc_html( self::when( (int) $g['last_used'] ) ) . '</td>';
				echo '<td>' . (int) $g['uses'] . '</td>';
				echo '<td>' . ( $active ? '<span class="sbr-badge sbr-badge--ok">' . esc_html__( 'ενεργή', 'site-bridge' ) . '</span>' : '<span class="sbr-badge sbr-badge--muted">' . esc_html__( 'έληξε / ανακλήθηκε', 'site-bridge' ) . '</span>' ) . '</td><td>';
				if ( $active ) {
					self::form_open( 'revoke', 'sbr-inline-form' );
					echo '<input type="hidden" name="sbr_grant" value="' . (int) $g['id'] . '"><button type="submit" class="button" data-sbr-confirm="' . esc_attr__( 'Να ανακληθεί η σύνδεση; Το Claude θα πρέπει να συνδεθεί ξανά.', 'site-bridge' ) . '">' . esc_html__( 'Ανάκληση', 'site-bridge' ) . '</button></form>';
				}
				echo '</td></tr>';
			}
			?>
			</tbody>
		</table>

		<h2 class="sbr-h2"><?php esc_html_e( 'Kill switch', 'site-bridge' ); ?></h2>
		<div class="sbr-zone sbr-danger-zone">
			<p class="description"><?php esc_html_e( 'Τερματίζει αμέσως κάθε σύνδεση. Για να κλείσει και το endpoint, σβήσε τον ρόλο Hub ή βάλε define( \'SBR_DISABLE\', true ); στο wp-config.php.', 'site-bridge' ); ?></p>
			<?php self::form_open( 'revoke_all' ); ?>
				<button type="submit" class="button" data-sbr-confirm="<?php esc_attr_e( 'Να τερματιστούν ΟΛΕΣ οι συνδέσεις;', 'site-bridge' ); ?>"><?php esc_html_e( 'Ανάκληση όλων', 'site-bridge' ); ?></button>
			</form>
		</div>

		<?php self::form_open( 'save_conn' ); ?>
		<h2 class="sbr-h2"><?php esc_html_e( 'Ρυθμίσεις του Hub', 'site-bridge' ); ?></h2>
		<div class="sbr-zone">
			<label class="sbr-label" for="sbr_clients"><?php esc_html_e( 'Clients που επιτρέπονται (προθέματα του client_id)', 'site-bridge' ); ?></label>
			<textarea id="sbr_clients" name="sbr_clients" class="sbr-lines"><?php echo esc_textarea( $s['clients'] ); ?></textarea>
			<span class="sbr-hint"><?php esc_html_e( 'Το Claude ταυτοποιείται με έγγραφο στο claude.ai (CIMD). Οι διευθύνσεις επιστροφής πρέπει να είναι σε αυτά τα hosts ή τοπικές (Claude Code).', 'site-bridge' ); ?></span>

			<label class="sbr-check sbr-mt-8"><input type="checkbox" name="sbr_dcr" value="1" <?php checked( $s['dcr'] ); ?>>
				<?php esc_html_e( 'Δυναμική εγγραφή clients (DCR). Κλειστή, εκτός αν ένας client δεν υποστηρίζει CIMD.', 'site-bridge' ); ?></label>

			<div class="sbr-grid sbr-mt-8">
				<div><label class="sbr-label" for="sbr_rate_min"><?php esc_html_e( 'Κλήσεις ανά λεπτό ανά σύνδεση', 'site-bridge' ); ?></label>
					<input type="number" id="sbr_rate_min" name="sbr_rate_min" min="1" max="2000" value="<?php echo (int) $s['rate_min']; ?>"></div>
				<div><label class="sbr-label" for="sbr_rate_day"><?php esc_html_e( 'Κλήσεις ανά ημέρα ανά σύνδεση', 'site-bridge' ); ?></label>
					<input type="number" id="sbr_rate_day" name="sbr_rate_day" min="1" max="1000000" value="<?php echo (int) $s['rate_day']; ?>"></div>
				<div><label class="sbr-label" for="sbr_log_days"><?php esc_html_e( 'Ημέρες ιστορικού', 'site-bridge' ); ?></label>
					<input type="number" id="sbr_log_days" name="sbr_log_days" min="1" max="3650" value="<?php echo (int) $s['log_days']; ?>"></div>
			</div>

			<label class="sbr-label" for="sbr_hub_ips"><?php esc_html_e( 'IP που δέχεται το endpoint MCP (μία ανά γραμμή, CIDR)', 'site-bridge' ); ?></label>
			<textarea id="sbr_hub_ips" name="sbr_hub_ips" class="sbr-lines"><?php echo esc_textarea( $s['hub_ips'] ); ?></textarea>
			<span class="sbr-hint"><?php esc_html_e( 'Κενό = κάθε IP. Το Claude καλεί από 160.79.104.0/21· το Claude Code από τη δική σου IP.', 'site-bridge' ); ?></span>
		</div>
		<p class="sbr-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'site-bridge' ); ?></button></p>
		</form>
		<?php
	}

	/* ----- Log ----- */

	private static function log_filters(): array {
		$f = array();
		foreach ( array( 'side', 'site', 'tool', 'status', 'from', 'to' ) as $k ) {
			$v = self::q( 'f_' . $k );
			if ( '' !== $v ) {
				$f[ $k ] = $v;
			}
		}
		if ( isset( $f['side'] ) && ! in_array( $f['side'], array( 'hub', 'site' ), true ) ) {
			unset( $f['side'] );
		}
		if ( isset( $f['status'] ) && ! in_array( $f['status'], array( 'ok', 'denied', 'error' ), true ) ) {
			unset( $f['status'] );
		}
		return $f;
	}

	private static function render_log(): void {
		$f     = self::log_filters();
		$page  = max( 1, (int) self::q( 'paged' ) );
		$total = SBR_Store::log_count( $f );
		$rows  = SBR_Store::log_rows( $f, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
		?>
		<h2 class="sbr-h2"><?php esc_html_e( 'Ιστορικό', 'site-bridge' ); ?></h2>
		<form method="get" action="" class="sbr-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<input type="hidden" name="tab" value="log">
			<select name="f_side">
				<option value=""><?php esc_html_e( 'Hub και site', 'site-bridge' ); ?></option>
				<option value="hub" <?php selected( $f['side'] ?? '', 'hub' ); ?>>hub</option>
				<option value="site" <?php selected( $f['side'] ?? '', 'site' ); ?>>site</option>
			</select>
			<select name="f_site">
				<option value=""><?php esc_html_e( 'Όλα τα sites', 'site-bridge' ); ?></option>
				<?php foreach ( SBR_Store::log_distinct( 'site' ) as $v ) : ?>
					<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $f['site'] ?? '', $v ); ?>><?php echo esc_html( $v ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="f_tool">
				<option value=""><?php esc_html_e( 'Όλα τα εργαλεία', 'site-bridge' ); ?></option>
				<?php foreach ( SBR_Store::log_distinct( 'tool' ) as $v ) : ?>
					<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $f['tool'] ?? '', $v ); ?>><?php echo esc_html( $v ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="f_status">
				<option value=""><?php esc_html_e( 'Κάθε αποτέλεσμα', 'site-bridge' ); ?></option>
				<?php foreach ( array( 'ok', 'denied', 'error' ) as $v ) : ?>
					<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $f['status'] ?? '', $v ); ?>><?php echo esc_html( $v ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="date" name="f_from" value="<?php echo esc_attr( $f['from'] ?? '' ); ?>">
			<input type="date" name="f_to" value="<?php echo esc_attr( $f['to'] ?? '' ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Φίλτρο', 'site-bridge' ); ?></button>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( array( 'page' => self::SLUG, 'sbr_csv' => 1 ), self::prefixed( $f ) ), admin_url( 'admin.php' ) ), self::NONCE_CSV ) ); ?>"><?php esc_html_e( 'Εξαγωγή CSV', 'site-bridge' ); ?></a>
		</form>
		<p class="description"><?php esc_html_e( 'Ώρες σε UTC. Τα μυστικά δεν γράφονται ποτέ στο ιστορικό.', 'site-bridge' ); ?></p>
		<table class="widefat sbr-table">
			<thead><tr>
				<th><?php esc_html_e( 'Ώρα', 'site-bridge' ); ?></th>
				<th><?php esc_html_e( 'Πλευρά', 'site-bridge' ); ?></th>
				<th><?php esc_html_e( 'Site', 'site-bridge' ); ?></th>
				<th><?php esc_html_e( 'Εργαλείο', 'site-bridge' ); ?></th>
				<th><?php esc_html_e( 'Ορίσματα', 'site-bridge' ); ?></th>
				<th><?php esc_html_e( 'Αποτέλεσμα', 'site-bridge' ); ?></th>
				<th><?php esc_html_e( 'Σύνδεση', 'site-bridge' ); ?></th>
				<th>ms</th>
			</tr></thead>
			<tbody>
			<?php
			if ( ! $rows ) {
				echo '<tr><td colspan="8" class="sbr-empty">' . esc_html__( 'Τίποτα ακόμα.', 'site-bridge' ) . '</td></tr>';
			}
			foreach ( $rows as $r ) {
				$badge = 'ok' === $r['status'] ? 'ok' : ( 'denied' === $r['status'] ? 'warn' : 'danger' );
				echo '<tr><td>' . esc_html( (string) $r['ts'] ) . '</td><td>' . esc_html( (string) $r['side'] ) . '</td><td>' . esc_html( (string) $r['site'] ) . '</td>';
				echo '<td><code>' . esc_html( (string) $r['tool'] ) . '</code> <span class="sbr-hint">' . esc_html( (string) $r['class'] ) . '</span></td>';
				echo '<td class="sbr-args"><code>' . esc_html( (string) $r['args'] ) . '</code></td>';
				echo '<td><span class="sbr-badge sbr-badge--' . esc_attr( $badge ) . '">' . esc_html( (string) $r['status'] ) . '</span> ' . esc_html( (string) $r['message'] ) . '</td>';
				echo '<td>' . esc_html( (string) $r['client'] ) . ( (int) $r['grant_id'] ? ' #' . (int) $r['grant_id'] : '' ) . '</td><td>' . (int) $r['ms'] . '</td></tr>';
			}
			?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			$base = array_merge( array( 'page' => self::SLUG, 'tab' => 'log' ), self::prefixed( $f ) );
			echo '<div class="sbr-pager">';
			if ( $page > 1 ) {
				echo '<a class="button" href="' . esc_url( add_query_arg( $base + array( 'paged' => $page - 1 ), admin_url( 'admin.php' ) ) ) . '">‹</a>';
			}
			/* translators: 1: page, 2: pages, 3: rows */
			echo '<span>' . esc_html( sprintf( __( 'Σελίδα %1$d από %2$d (%3$d εγγραφές)', 'site-bridge' ), $page, $pages, $total ) ) . '</span>';
			if ( $page < $pages ) {
				echo '<a class="button" href="' . esc_url( add_query_arg( $base + array( 'paged' => $page + 1 ), admin_url( 'admin.php' ) ) ) . '">›</a>';
			}
			echo '</div>';
		}
	}

	private static function prefixed( array $f ): array {
		$out = array();
		foreach ( $f as $k => $v ) {
			$out[ 'f_' . $k ] = $v;
		}
		return $out;
	}

	/* =====================================================================
	 * POST routing (PRG)
	 * =================================================================== */

	public static function route(): void {
		if ( self::SLUG !== self::current_page() || ! isset( $_POST[ self::NONCE_FIELD ], $_POST['sbr_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'site-bridge' ) );
		}
		$saved = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'site-bridge' ) ) );
		$s     = SBR_Settings::get();
		switch ( sanitize_key( wp_unslash( $_POST['sbr_action'] ) ) ) {
			case 'save_roles':
				$s['hub']  = '1' === self::post( 'sbr_hub' );
				$s['site'] = '1' === self::post( 'sbr_site' );
				if ( $s['site'] && ! SBR_Settings::run_as_user() ) {
					$s['run_as'] = get_current_user_id();
				}
				SBR_Settings::save( $s );
				self::audit( 'roles', array( 'hub' => $s['hub'], 'site' => $s['site'] ) );
				self::redirect( 'overview', $saved );
				break;
			case 'diag':
				set_transient( 'sbr_diag_' . get_current_user_id(), self::diagnose(), 10 * MINUTE_IN_SECONDS );
				self::redirect( 'overview', array() );
				break;
			case 'save_access':
				$old              = $s['level'];
				$s['level']       = self::post( 'sbr_level' );
				$s['remote_window'] = '1' === self::post( 'sbr_remote_window' );
				$s['run_as']      = (int) self::post( 'sbr_run_as' );
				$s['pii_mask']    = '1' === self::post( 'sbr_pii_mask' );
				$s['email_admin'] = '1' === self::post( 'sbr_email_admin' );
				$s['site_ips']    = self::post( 'sbr_site_ips' );
				$s['zip_hosts']   = self::post( 'sbr_zip_hosts' );
				SBR_Settings::save( $s );
				$s = SBR_Settings::get();
				self::audit( 'access', array( 'level' => $old . ' → ' . $s['level'], 'pii_mask' => $s['pii_mask'], 'remote_window' => $s['remote_window'] ) );
				$msgs = array_merge( $saved, self::dropped( array( 'sbr_site_ips' => 'site_ips', 'sbr_zip_hosts' => 'zip_hosts' ) ) );
				if ( (int) self::post( 'sbr_run_as' ) && ! SBR_Settings::run_as_user() ) {
					$msgs[] = self::msg( 'error', __( 'Ο χρήστης εκτέλεσης πρέπει να είναι διαχειριστής.', 'site-bridge' ) );
				}
				self::redirect( 'access', $msgs );
				break;
			case 'window_open':
				$until = SBR_Settings::open_window( (int) self::post( 'sbr_minutes' ) );
				self::audit( 'window_open', array( 'until' => gmdate( 'c', $until ) ) );
				/* translators: %s: time */
				self::redirect( 'access', array( self::msg( 'warning', sprintf( __( 'Το παράθυρο εγγραφής είναι ανοιχτό ως %s.', 'site-bridge' ), wp_date( 'H:i', $until ) ) ) ) );
				break;
			case 'window_close':
				SBR_Settings::close_window();
				self::audit( 'window_close', array() );
				self::redirect( 'access', array( self::msg( 'success', __( 'Το παράθυρο εγγραφής έκλεισε.', 'site-bridge' ) ) ) );
				break;
			case 'pair_code':
				set_transient( 'sbr_pair_show_' . get_current_user_id(), SBR_Site::new_code(), 15 * MINUTE_IN_SECONDS );
				self::redirect( 'access', array() );
				break;
			case 'forget_hub':
				delete_option( SBR_Site::TRUST );
				self::audit( 'unpair', array() );
				self::redirect( 'access', array( self::msg( 'success', __( 'Το site ξέχασε το Hub.', 'site-bridge' ) ) ) );
				break;
			case 'site_add':
				$r = SBR_Hub::add_site( self::post( 'sbr_code' ), self::post( 'sbr_label' ) );
				/* translators: %s: site id */
				self::redirect( 'sites', array( is_wp_error( $r ) ? self::msg( 'error', $r->get_error_message() ) : self::msg( 'success', sprintf( __( 'Το site προστέθηκε ως «%s».', 'site-bridge' ), $r ) ) ) );
				break;
			case 'site_remove':
				SBR_Hub::remove_site( sanitize_key( self::post( 'sbr_site_id' ) ) );
				self::redirect( 'sites', array( self::msg( 'success', __( 'Το site αφαιρέθηκε.', 'site-bridge' ) ) ) );
				break;
			case 'site_refresh':
			case 'site_window_open':
			case 'site_window_close':
				$sites = SBR_Hub::sites();
				$id    = sanitize_key( self::post( 'sbr_site_id' ) );
				if ( ! isset( $sites[ $id ] ) ) {
					self::redirect( 'sites', array( self::msg( 'error', __( 'Άγνωστο site.', 'site-bridge' ) ) ) );
				}
				$act = sanitize_key( wp_unslash( $_POST['sbr_action'] ) );
				if ( 'site_refresh' === $act ) {
					$r = SBR_Hub::status( $sites[ $id ] );
				} else {
					$min = (int) self::post( 'sbr_minutes' );
					$min = in_array( $min, SBR_Settings::WINDOW_MINUTES, true ) ? $min : 30;
					$r   = SBR_Hub::window( $sites[ $id ], 'site_window_open' === $act ? $min : 0 );
					self::audit( $act, 'site_window_open' === $act ? array( 'site' => $id, 'minutes' => $min ) : array( 'site' => $id ) );
				}
				self::redirect( 'sites', array( is_wp_error( $r ) ? self::msg( 'error', $r->get_error_message() ) : self::msg( 'success', __( 'Έγινε.', 'site-bridge' ) ) ) );
				break;
			case 'revoke':
				SBR_OAuth::revoke( (int) self::post( 'sbr_grant' ) );
				self::redirect( 'connections', array( self::msg( 'success', __( 'Η σύνδεση ανακλήθηκε.', 'site-bridge' ) ) ) );
				break;
			case 'revoke_all':
				$n = SBR_OAuth::revoke_all();
				/* translators: %d: number of connections */
				self::redirect( 'connections', array( self::msg( 'warning', ( 1 === $n ? __( 'Ανακλήθηκε 1 σύνδεση.', 'site-bridge' ) : sprintf( __( 'Ανακλήθηκαν %d συνδέσεις.', 'site-bridge' ), $n ) ) ) ) );
				break;
			case 'save_conn':
				$s['clients']  = self::post( 'sbr_clients' );
				$s['dcr']      = '1' === self::post( 'sbr_dcr' );
				$s['rate_min'] = (int) self::post( 'sbr_rate_min' );
				$s['rate_day'] = (int) self::post( 'sbr_rate_day' );
				$s['log_days'] = (int) self::post( 'sbr_log_days' );
				$s['hub_ips']  = self::post( 'sbr_hub_ips' );
				SBR_Settings::save( $s );
				self::audit( 'hub_settings', array( 'dcr' => $s['dcr'] ) );
				self::redirect( 'connections', array_merge( $saved, self::dropped( array( 'sbr_clients' => 'clients', 'sbr_hub_ips' => 'hub_ips' ) ) ) );
				break;
			default:
				self::redirect( 'overview', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'site-bridge' ) ) ) );
		}
	}

	/** A warning when lines of a list field were not valid and were left out. */
	private static function dropped( array $fields ): array {
		$n = 0;
		foreach ( $fields as $field => $key ) {
			$typed = array_unique( array_filter( array_map( 'trim', (array) preg_split( '/[\r\n,]+/', self::post( $field ) ) ) ) );
			$n    += max( 0, count( $typed ) - count( SBR_Settings::lines( $key ) ) );
		}
		if ( ! $n ) {
			return array();
		}
		return array(
			self::msg(
				'warning',
				1 === $n
					? __( 'Μία γραμμή δεν ήταν έγκυρη και αφαιρέθηκε (IP / CIDR, ή διεύθυνση https που τελειώνει σε /).', 'site-bridge' )
					/* translators: %d: number of lines left out */
					: sprintf( __( '%d γραμμές δεν ήταν έγκυρες και αφαιρέθηκαν (IP / CIDR, ή διευθύνσεις https που τελειώνουν σε /).', 'site-bridge' ), $n )
			),
		);
	}

	/** Admin changes go to the audit log too. */
	private static function audit( string $what, array $args ): void {
		$u = wp_get_current_user();
		SBR_Store::log(
			array(
				'side'    => 'site',
				'client'  => 'admin: ' . $u->user_login,
				'tool'    => $what,
				'class'   => 'admin',
				'args'    => $args,
				'status'  => 'ok',
				'message' => 'WP admin',
			)
		);
	}

	public static function route_csv(): void {
		if ( self::SLUG !== self::current_page() || '1' !== self::q( 'sbr_csv' ) ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_CSV ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'site-bridge' ) );
		}
		$rows = SBR_Store::log_rows( self::log_filters(), 50000, 0 );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="site-bridge-log-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for spreadsheets.
		fputcsv( $out, array( 'id', 'ts_utc', 'side', 'grant_id', 'client', 'site', 'tool', 'class', 'args', 'status', 'message', 'bytes', 'ms', 'ip' ) );
		foreach ( $rows as $r ) {
			$line = array();
			foreach ( $r as $v ) {
				$v      = (string) $v;
				$line[] = preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
			}
			fputcsv( $out, $line );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		set_transient( 'sbr_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route() / consent().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? trim( (string) wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/** A posted list of keys. */
	private static function post_list( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in consent().
		$v = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
		$out = array();
		foreach ( $v as $x ) {
			if ( is_scalar( $x ) ) {
				$x     = (string) $x;
				$out[] = '*' === $x ? '*' : sanitize_key( $x );
			}
		}
		return $out;
	}

	private static function take_msgs(): array {
		$raw = get_transient( 'sbr_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'sbr_aui_msg_' . get_current_user_id() );
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
	 * OAuth consent screen
	 * =================================================================== */

	public static function consent(): void {
		if ( 'sbr-authorize' !== self::current_page() ) {
			return;
		}
		if ( ! SBR_Hub::enabled() ) {
			wp_die( esc_html__( 'Ο ρόλος Hub είναι κλειστός σε αυτό το site.', 'site-bridge' ) );
		}
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Μόνο διαχειριστές εγκρίνουν συνδέσεις.', 'site-bridge' ) );
		}
		$rid = self::q( 'req' );
		$r   = SBR_OAuth::request( $rid );
		if ( ! $r ) {
			wp_die( esc_html__( 'Το αίτημα σύνδεσης έληξε ή δεν υπάρχει. Ξεκίνα ξανά από το Claude.', 'site-bridge' ) );
		}
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			if ( ! isset( $_POST['sbr_consent_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['sbr_consent_nonce'] ) ), 'sbr_consent_' . $rid ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'site-bridge' ) );
			}
			if ( '1' === self::post( 'sbr_approve' ) ) {
				$sites = self::post_list( 'sbr_sites' );
				if ( '1' === self::post( 'sbr_all_sites' ) ) {
					$sites[] = '*';
				}
				$url = SBR_OAuth::approve( $rid, array_map( 'sanitize_key', self::post_list( 'sbr_scopes' ) ), $sites, get_current_user_id() );
			} else {
				$url = SBR_OAuth::deny( $rid );
			}
			wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the client's registered redirect URI, validated in SBR_OAuth::authorize().
			exit;
		}
		self::consent_page( $rid, $r );
		exit;
	}

	private static function consent_page( string $rid, array $r ): void {
		$host   = (string) wp_parse_url( $r['redirect_uri'], PHP_URL_HOST );
		$labels = array(
			'read'  => __( 'Ανάγνωση: ρυθμίσεις, βάση, REST GET, σελίδες, αρχεία, logs', 'site-bridge' ),
			'write' => __( 'Εγγραφή: περιεχόμενο, options, πολυμέσα, caches (όπου το site το επιτρέπει, με ανοιχτό παράθυρο)', 'site-bridge' ),
			'admin' => __( 'Διαχείριση: plugins, θέματα, κανάλι ενημερώσεων (όπου το site το επιτρέπει, με ανοιχτό παράθυρο)', 'site-bridge' ),
		);
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		?>
<!doctype html>
<html lang="<?php echo esc_attr( 'el' === SBR_Lang::lang() ? 'el' : 'en' ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php esc_html_e( 'Site Bridge: έγκριση σύνδεσης', 'site-bridge' ); ?></title>
<style>
body{margin:0;background:#101218;color:#eae8fa;font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.box{max-width:620px;margin:40px auto;padding:28px 32px;background:#14161d;border:1px solid #2e3240;border-radius:8px}
h1{font-size:20px;margin:0 0 6px;color:#f6f4ff}h2{font-size:13px;text-transform:uppercase;letter-spacing:.03em;color:#b7b2d6;margin:22px 0 8px;border-bottom:1px solid #2e3240;padding-bottom:6px}
.who{background:#1d2029;border:1px solid #2e3240;border-radius:6px;padding:12px 14px}.host{font-size:18px;color:#f5c97e;font-weight:600}
label{display:block;margin:0 0 8px;cursor:pointer}input{accent-color:#6d4aff}.muted{color:#b7b2d6;font-size:13px}
.btns{display:flex;gap:12px;margin-top:24px}button{font:inherit;padding:9px 20px;border-radius:5px;border:1px solid #4a4f66;background:#1e2130;color:#eeeafc;cursor:pointer}
button.ok{background:#6d4aff;border-color:#6d4aff;color:#fff}code{color:#beb1ff;word-break:break-all}
</style>
</head>
<body>
<div class="box">
	<h1><?php esc_html_e( 'Έγκριση σύνδεσης στο Site Bridge', 'site-bridge' ); ?></h1>
	<p class="muted"><?php
		/* translators: %s: user login */
		echo esc_html( sprintf( __( 'Συνδεδεμένος ως %s.', 'site-bridge' ), wp_get_current_user()->user_login ) );
	?></p>
	<div class="who">
		<div><?php echo esc_html( (string) $r['client_name'] ); ?></div>
		<div class="muted"><code><?php echo esc_html( (string) $r['client_id'] ); ?></code></div>
		<div class="muted"><?php esc_html_e( 'Μετά την έγκριση η σύνδεση επιστρέφει στο:', 'site-bridge' ); ?></div>
		<div class="host"><?php echo esc_html( $host ); ?></div>
		<?php if ( in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true ) ) : ?>
			<p class="muted"><?php esc_html_e( 'Τοπική διεύθυνση (Claude Code): έγκρινε μόνο αν μόλις ξεκίνησες εσύ τη σύνδεση από τον υπολογιστή σου.', 'site-bridge' ); ?></p>
		<?php endif; ?>
	</div>
	<form method="post" action="">
		<?php wp_nonce_field( 'sbr_consent_' . $rid, 'sbr_consent_nonce' ); ?>
		<h2><?php esc_html_e( 'Scopes', 'site-bridge' ); ?></h2>
		<?php foreach ( $labels as $k => $l ) : ?>
			<label><input type="checkbox" name="sbr_scopes[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( 'read' === $k || in_array( $k, (array) $r['scopes'], true ) ); ?> <?php disabled( 'read' === $k ); ?>> <?php echo esc_html( $l ); ?></label>
		<?php endforeach; ?>
		<input type="hidden" name="sbr_scopes[]" value="read">
		<p class="muted"><?php esc_html_e( 'Κάθε site εφαρμόζει επιπλέον το δικό του επίπεδο και το παράθυρο εγγραφής.', 'site-bridge' ); ?></p>
		<h2><?php esc_html_e( 'Sites', 'site-bridge' ); ?></h2>
		<?php foreach ( SBR_Hub::sites() as $id => $s ) : ?>
			<label><input type="checkbox" name="sbr_sites[]" value="<?php echo esc_attr( $id ); ?>" checked> <?php echo esc_html( $s['label'] ); ?> <span class="muted">(<?php echo esc_html( $id ); ?>)</span></label>
		<?php endforeach; ?>
		<label><input type="checkbox" name="sbr_all_sites" value="1"> <?php esc_html_e( 'Και όσα sites προστεθούν αργότερα', 'site-bridge' ); ?></label>
		<p class="muted"><?php esc_html_e( 'Η έγκριση ισχύει 90 ημέρες. Ανακαλείται όποτε θέλεις από Noxpress → Site Bridge → Συνδέσεις.', 'site-bridge' ); ?></p>
		<div class="btns">
			<button type="submit" name="sbr_approve" value="1" class="ok"><?php esc_html_e( 'Έγκριση', 'site-bridge' ); ?></button>
			<button type="submit" name="sbr_approve" value="0"><?php esc_html_e( 'Άρνηση', 'site-bridge' ); ?></button>
		</div>
	</form>
</div>
</body>
</html>
		<?php
	}

	/* =====================================================================
	 * Footer (Bible §7 — identical pattern)
	 * =================================================================== */

	private static function footer(): void {
		?>
		<p class="sbr-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'site-bridge' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="sbr-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="sbr-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'site-bridge' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="sbr-footer-cta">
			<a class="sbr-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'site-bridge' ); ?>
			</a>
			<a class="sbr-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'site-bridge' ); ?>
			</a>
		</p>
		<?php
	}
}

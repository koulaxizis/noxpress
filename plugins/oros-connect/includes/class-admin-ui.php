<?php
/**
 * ORC_Admin_UI — menu, modules, Newsletter settings, tools (Bible §3–§8,
 * §11).
 *
 * Submenu «orOS Connect» of the shared "Noxpress" menu (admin_menu
 * priority 150), slug orc-settings, capability Oros_Connect::cap(). Tabs:
 *  - modules     mode (test / live / off), visitor language, module switches
 *  - newsletter  orOS sign-up address + connection check, form texts,
 *                checkout consent, shop key, send log
 *  - offers      module «Προσφορές»: what orOS needs to pull the requests of
 *                Publishing Quote, waiting requests, last pull
 *  - tools       settings backup, clear the log, developers
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient orc_aui_msg_{uid}). Export: privileged GET.
 */

defined( 'ABSPATH' ) || exit;

final class ORC_Admin_UI {

	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'orc-settings';
	const NONCE       = 'orc_admin';
	const NONCE_FIELD = 'orc_nonce';
	const NONCE_BAK   = 'orc_backup';
	const MAX_IMPORT  = 262144;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 150 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ORC_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label. */
	private static function tabs(): array {
		return array(
			'modules'    => __( 'Modules', 'oros-connect' ),
			'newsletter' => __( 'Newsletter', 'oros-connect' ),
			'offers'     => __( 'Προσφορές', 'oros-connect' ),
			'tools'      => __( 'Εργαλεία', 'oros-connect' ),
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
			__( 'orOS Connect', 'oros-connect' ),
			__( 'orOS Connect', 'oros-connect' ),
			Oros_Connect::cap(),
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'oros-connect' ) . '</a>'
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
		return isset( self::tabs()[ $tab ] ) ? $tab : 'modules';
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'orc-admin', ORC_URL . 'assets/admin.css', array(), ORC_VERSION );
	}

	/** Suggested text for the site's privacy policy (Settings → Privacy). */
	public static function privacy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p>' . esc_html__( 'Όταν γράφεσαι στο newsletter μας (φόρμα εγγραφής ή κουτάκι στο ταμείο), το email σου, το όνομά σου αν το δώσεις και η γλώσσα σου στέλνονται στη λίστα μας στο orOS Newsletter μέσω του relay του orOS (Cloudflare Workers). Εκεί κρατιούνται κρυπτογραφημένα μέχρι να τα παραλάβουμε, και μετά διαγράφονται από το relay. Θα λάβεις email επιβεβαίωσης, και κάθε email μας έχει σύνδεσμο διαγραφής.', 'oros-connect' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Στις παραγγελίες κρατάμε πότε δόθηκε η συναίνεση στο ταμείο και το κείμενό της, ως απόδειξη της συναίνεσης.', 'oros-connect' ) . '</p>';
		wp_add_privacy_policy_content( 'orOS Connect', wp_kses_post( $text ) );
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Oros_Connect::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'oros-connect' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap orc-wrap">
			<h1><?php esc_html_e( 'orOS Connect', 'oros-connect' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Σύνδεση του site με το orOS. Κάθε module ενεργοποιείται χωριστά· όσα είναι κλειστά δεν φορτώνουν τίποτα.', 'oros-connect' ); ?></p>

			<?php
			self::print_notices( self::take_msgs() );
			if ( Oros_Connect::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το ORC_DISABLE είναι ενεργό στο wp-config.php: καμία φόρμα, κανένα κουτάκι στο ταμείο και καμία αποστολή.', 'oros-connect' ) . '</p></div>';
			}
			?>

			<nav class="nav-tab-wrapper orc-tabs">
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
				case 'newsletter':
					self::render_newsletter();
					break;
				case 'offers':
					self::render_offers();
					break;
				case 'tools':
					self::render_tools();
					break;
				default:
					self::render_modules();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	private static function checkbox( string $name, bool $on, string $label ): void {
		printf(
			'<label class="orc-check"><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label )
		);
	}

	private static function radio( string $name, string $value, string $current, string $label ): void {
		printf(
			'<label class="orc-check"><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $value, $current, false ),
			esc_html( $label )
		);
	}

	private static function badge( string $text, string $kind ): string {
		return '<span class="orc-badge orc-badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function render_modules(): void {
		$s = ORC_Settings::get();
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="orc_action" value="save_modules" />

			<h2 class="orc-h2"><?php esc_html_e( 'Modules', 'oros-connect' ); ?></h2>
			<div class="orc-mods">
				<div class="orc-mod">
					<h3>
						<?php esc_html_e( 'Newsletter', 'oros-connect' ); ?>
						<?php echo $s['mods']['newsletter'] ? self::badge( __( 'Ενεργό', 'oros-connect' ), 'ok' ) : self::badge( __( 'Κλειστό', 'oros-connect' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?>
					</h3>
					<p><?php esc_html_e( 'Φόρμα εγγραφής (μπλοκ, shortcode, widget) και κουτάκι στο ταμείο, που στέλνουν νέους συνδρομητές στη λίστα σου στο orOS Newsletter.', 'oros-connect' ); ?></p>
					<?php self::checkbox( 'orc_mod_newsletter', $s['mods']['newsletter'], __( 'Ενεργοποίηση', 'oros-connect' ) ); ?>
				</div>
				<div class="orc-mod">
					<h3>
						<?php esc_html_e( 'Προσφορές', 'oros-connect' ); ?>
						<?php echo $s['mods']['offers'] ? self::badge( __( 'Ενεργό', 'oros-connect' ), 'ok' ) : self::badge( __( 'Κλειστό', 'oros-connect' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?>
					</h3>
					<p><?php esc_html_e( 'Τα αιτήματα «Ενδιαφέρομαι» του Publishing Quote γίνονται πρόχειρες προσφορές στο orOS. Το orOS τα παίρνει όταν είναι ανοιχτό, και η προσφορά φεύγει μόνο όταν τη στείλεις εσύ.', 'oros-connect' ); ?></p>
					<?php self::checkbox( 'orc_mod_offers', $s['mods']['offers'], __( 'Ενεργοποίηση', 'oros-connect' ) ); ?>
				</div>
				<?php
				$soon = array(
					__( 'Stock ledger', 'oros-connect' ) => __( 'Κάθε αλλαγή αποθέματος του καταστήματος στο orOS Stock, και αλλαγές από το orOS κατά διαφορά, χωρίς χαμένες πωλήσεις.', 'oros-connect' ),
					__( 'Receipt link', 'oros-connect' ) => __( 'Ο ΜΑΡΚ και το PDF της απόδειξης από το orOS Invoices στον λογαριασμό του πελάτη και στο email.', 'oros-connect' ),
					__( 'Inbox', 'oros-connect' )        => __( 'Νέες παραγγελίες, υπαναχωρήσεις και χαμηλό απόθεμα στο Today του orOS.', 'oros-connect' ),
				);
				foreach ( $soon as $name => $desc ) {
					?>
					<div class="orc-mod is-soon">
						<h3><?php echo esc_html( $name ); ?> <?php echo self::badge( __( 'Σύντομα', 'oros-connect' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></h3>
						<p><?php echo esc_html( $desc ); ?></p>
					</div>
					<?php
				}
				?>
			</div>

			<h2 class="orc-h2"><?php esc_html_e( 'Λειτουργία', 'oros-connect' ); ?></h2>
			<div class="orc-zone">
				<?php
				self::radio( 'orc_mode', 'test', $s['mode'], __( 'Δοκιμή: μόνο οι διαχειριστές βλέπουν τη φόρμα και το κουτάκι στο ταμείο', 'oros-connect' ) );
				self::radio( 'orc_mode', 'live', $s['mode'], __( 'Ενεργό για όλους', 'oros-connect' ) );
				self::radio( 'orc_mode', 'off', $s['mode'], __( 'Ανενεργό: τίποτα στο site (οι συναινέσεις που δόθηκαν ήδη στέλνονται κανονικά)', 'oros-connect' ) );
				?>
			</div>

			<h2 class="orc-h2"><?php esc_html_e( 'Γλώσσα των κειμένων για τους επισκέπτες', 'oros-connect' ); ?></h2>
			<div class="orc-zone">
				<?php
				self::radio( 'orc_lang', 'auto', $s['lang'], __( 'Αυτόματα, από τη γλώσσα του site', 'oros-connect' ) );
				self::radio( 'orc_lang', 'el', $s['lang'], __( 'Ελληνικά', 'oros-connect' ) );
				self::radio( 'orc_lang', 'en', $s['lang'], __( 'English', 'oros-connect' ) );
				?>
			</div>

			<p class="orc-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'oros-connect' ); ?></button></p>
		</form>
		<?php
	}

	/** One visitor text: Greek and English inputs. */
	private static function text_pair( string $key, string $label, array $s ): void {
		$max = ORC_Settings::texts()[ $key ][2];
		?>
		<span class="orc-label"><?php echo esc_html( $label ); ?></span>
		<div class="orc-pair">
			<label><span class="orc-lang"><?php esc_html_e( 'Ελληνικά', 'oros-connect' ); ?></span>
				<input type="text" name="orc_t_<?php echo esc_attr( $key ); ?>_el" value="<?php echo esc_attr( $s[ 't_' . $key . '_el' ] ); ?>" maxlength="<?php echo (int) $max; ?>" /></label>
			<label><span class="orc-lang"><?php esc_html_e( 'English (κενό = το ελληνικό)', 'oros-connect' ); ?></span>
				<input type="text" name="orc_t_<?php echo esc_attr( $key ); ?>_en" value="<?php echo esc_attr( $s[ 't_' . $key . '_en' ] ); ?>" maxlength="<?php echo (int) $max; ?>" /></label>
		</div>
		<?php
	}

	private static function render_newsletter(): void {
		$s = ORC_Settings::get();
		if ( ! $s['mods']['newsletter'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Το module Newsletter είναι κλειστό. Οι ρυθμίσεις αποθηκεύονται, αλλά τίποτα δεν φαίνεται στο site μέχρι να το ενεργοποιήσεις στα Modules.', 'oros-connect' ) . '</p></div>';
		}
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="orc_action" value="save_nl" />

			<h2 class="orc-h2"><?php esc_html_e( 'Σύνδεση με το orOS', 'oros-connect' ); ?></h2>
			<div class="orc-zone">
				<ol>
					<li><?php esc_html_e( 'Στο orOS: Newsletter → η λίστα σου → Subscribe link → ενεργοποίησε τη φόρμα εγγραφής.', 'oros-connect' ); ?></li>
					<li><?php esc_html_e( 'Αντέγραψε το «HTML για το site σου» (ή μόνο τη διεύθυνση https://…/nl/s/…).', 'oros-connect' ); ?></li>
					<li><?php esc_html_e( 'Επικόλλησέ το εδώ. Κρατάμε μόνο τη διεύθυνση εγγραφής.', 'oros-connect' ); ?></li>
				</ol>
				<textarea name="orc_nl_url" class="orc-wide" rows="4" placeholder="https://…/nl/s/…"><?php echo esc_textarea( $s['nl_url'] ); ?></textarea>
				<?php if ( '' !== $s['nl_url'] ) : ?>
					<p class="orc-mt-8">
						<?php echo self::badge( __( 'Διεύθυνση εγγραφής', 'oros-connect' ), 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?>
						<code><?php echo esc_html( ORC_Relay::host( $s['nl_url'] ) ); ?></code>
						<button type="submit" name="orc_action" value="check" class="button"><?php esc_html_e( 'Έλεγχος σύνδεσης', 'oros-connect' ); ?></button>
					</p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Μετά την εγγραφή ο επισκέπτης βλέπει τη σελίδα ευχαριστίας που όρισες στο orOS (Subscribe link → σελίδα ευχαριστίας), αλλιώς ένα απλό μήνυμα του orOS. Το orOS στέλνει το email επιβεβαίωσης.', 'oros-connect' ); ?></p>
			</div>

			<h2 class="orc-h2"><?php esc_html_e( 'Φόρμα εγγραφής', 'oros-connect' ); ?></h2>
			<div class="orc-zone">
				<?php self::checkbox( 'orc_nl_name', $s['nl_name'], __( 'Πεδίο για όνομα (προαιρετικό για τον επισκέπτη)', 'oros-connect' ) ); ?>
				<?php
				self::text_pair( 'title', __( 'Τίτλος', 'oros-connect' ), $s );
				self::text_pair( 'intro', __( 'Κείμενο πάνω από τη φόρμα', 'oros-connect' ), $s );
				self::text_pair( 'email', __( 'Πεδίο email', 'oros-connect' ), $s );
				self::text_pair( 'name', __( 'Πεδίο ονόματος', 'oros-connect' ), $s );
				self::text_pair( 'button', __( 'Κουμπί', 'oros-connect' ), $s );
				self::text_pair( 'note', __( 'Σημείωση κάτω από τη φόρμα', 'oros-connect' ), $s );
				?>
				<p class="description orc-mt-8">
					<?php esc_html_e( 'Πού μπαίνει: το μπλοκ «Newsletter (orOS)», το shortcode [oros_newsletter] (title="no" χωρίς τίτλο, name="yes|no") ή το widget «Newsletter (orOS)».', 'oros-connect' ); ?>
				</p>
			</div>

			<?php if ( Oros_Connect::woo() ) : ?>
				<h2 class="orc-h2"><?php esc_html_e( 'Ταμείο', 'oros-connect' ); ?></h2>
				<div class="orc-zone">
					<?php self::checkbox( 'orc_nl_checkout', $s['nl_checkout'], __( 'Κουτάκι newsletter στο ταμείο (ατσέκαρτο), στο κλασικό ταμείο και στο μπλοκ Checkout', 'oros-connect' ) ); ?>
					<?php self::text_pair( 'checkbox', __( 'Κείμενο του κουτιού', 'oros-connect' ), $s ); ?>
					<p class="description orc-mt-8"><?php esc_html_e( 'Ο πελάτης στέλνεται στο orOS όταν πληρωθεί η παραγγελία (σε επεξεργασία ή ολοκληρωμένη), με το email και το όνομα χρέωσης. Η συναίνεση μένει στην παραγγελία.', 'oros-connect' ); ?></p>

					<span class="orc-label"><?php esc_html_e( 'Επιβεβαίωση', 'oros-connect' ); ?></span>
					<?php self::checkbox( 'orc_nl_doi', $s['nl_doi'], __( 'Email επιβεβαίωσης και για όσους γράφονται από το ταμείο (double opt-in)', 'oros-connect' ) ); ?>
					<p class="description"><?php esc_html_e( 'Κλειστό: η συναίνεση στο ταμείο αρκεί και ο πελάτης γράφεται κατευθείαν. Για να το δεχτεί το orOS, η εγγραφή υπογράφεται με το κλειδί καταστήματος· όσο το orOS δεν το υποστηρίζει ακόμα, στέλνεται email επιβεβαίωσης σε όλους.', 'oros-connect' ); ?></p>

					<span class="orc-label"><?php esc_html_e( 'Κλειδί καταστήματος', 'oros-connect' ); ?></span>
					<input type="text" name="orc_nl_key" class="orc-key" value="<?php echo esc_attr( $s['nl_key'] ); ?>" maxlength="32" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e( '32 γράμματα και αριθμοί', 'oros-connect' ); ?>" />
					<button type="submit" name="orc_action" value="newkey" class="button"><?php esc_html_e( 'Νέο κλειδί', 'oros-connect' ); ?></button>
					<p class="description"><?php esc_html_e( 'Το ίδιο κλειδί μπαίνει και στο orOS, στη λίστα (Subscribe link). Κράτα το μυστικό: όποιος το έχει μπορεί να γράφει emails στη λίστα χωρίς επιβεβαίωση.', 'oros-connect' ); ?></p>
				</div>
			<?php endif; ?>

			<p class="orc-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'oros-connect' ); ?></button></p>
		</form>

		<?php if ( Oros_Connect::woo() ) : ?>
			<h2 class="orc-h2"><?php esc_html_e( 'Τελευταίες αποστολές από το ταμείο', 'oros-connect' ); ?></h2>
			<?php self::render_log(); ?>
		<?php endif; ?>
		<?php
	}

	private static function render_log(): void {
		$log = ORC_Relay::get_log();
		$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<table class="widefat orc-table">
			<thead><tr>
				<th><?php esc_html_e( 'Πότε', 'oros-connect' ); ?></th>
				<th><?php esc_html_e( 'Παραγγελία', 'oros-connect' ); ?></th>
				<th><?php esc_html_e( 'Αποτέλεσμα', 'oros-connect' ); ?></th>
				<th><?php esc_html_e( 'Προσπάθεια', 'oros-connect' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $log ) : ?>
				<tr><td colspan="4" class="orc-empty"><?php esc_html_e( 'Καμία αποστολή ακόμα.', 'oros-connect' ); ?></td></tr>
			<?php else : ?>
				<?php
				foreach ( array_slice( $log, 0, 20 ) as $e ) {
					$cls = 'ok' === $e['res'] ? 'is-ok' : ( ORC_Relay::retryable( $e['res'] ) ? 'is-retry' : 'is-fail' );
					$url = function_exists( 'wc_get_order' ) && wc_get_order( $e['order'] ) ? wc_get_order( $e['order'] )->get_edit_order_url() : '';
					?>
					<tr>
						<td><?php echo esc_html( wp_date( $fmt, $e['t'] ) ); ?></td>
						<td><?php echo '' !== $url ? '<a href="' . esc_url( $url ) . '">#' . (int) $e['order'] . '</a>' : '#' . (int) $e['order']; ?></td>
						<td class="<?php echo esc_attr( $cls ); ?>"><?php echo esc_html( ORC_Relay::reason( $e['res'] ) . ( $e['code'] ? ' (HTTP ' . $e['code'] . ')' : '' ) ); ?></td>
						<td><?php echo (int) $e['try']; ?></td>
					</tr>
					<?php
				}
				?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_offers(): void {
		$s   = ORC_Settings::get();
		$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		if ( ! $s['mods']['offers'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Το module Προσφορές είναι κλειστό: το orOS δεν μπορεί να πάρει τίποτα μέχρι να το ενεργοποιήσεις στα Modules.', 'oros-connect' ) . '</p></div>';
		}
		$source = function_exists( 'pbq_get_requests' );
		if ( ! $source ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το Publishing Quote δεν είναι ενεργό. Οι Προσφορές παίρνουν τα αιτήματα «Ενδιαφέρομαι» από αυτό.', 'oros-connect' ) . '</p></div>';
		}
		?>
		<h2 class="orc-h2"><?php esc_html_e( 'Πώς δουλεύει', 'oros-connect' ); ?></h2>
		<div class="orc-zone">
			<ol>
				<li><?php esc_html_e( 'Ο επισκέπτης πατά «Ενδιαφέρομαι» στο Publishing Quote. Το αίτημα μένει στο site και σου έρχεται email.', 'oros-connect' ); ?></li>
				<li><?php esc_html_e( 'Όταν ανοίγεις το orOS, παίρνει τα νέα αιτήματα, φτιάχνει από το καθένα πρόχειρη προσφορά και επαφή, και τα μαρκάρει «Στο orOS».', 'oros-connect' ); ?></li>
				<li><?php esc_html_e( 'Ελέγχεις την προσφορά στο orOS και τη στέλνεις εσύ. Η ανάλυση μπαίνει ως εσωτερική σημείωση και δεν φεύγει ποτέ προς τον πελάτη.', 'oros-connect' ); ?></li>
			</ol>
		</div>

		<h2 class="orc-h2"><?php esc_html_e( 'Σύνδεση με το orOS', 'oros-connect' ); ?></h2>
		<div class="orc-zone">
			<span class="orc-label"><?php esc_html_e( 'Διεύθυνση του site', 'oros-connect' ); ?></span>
			<code class="orc-wide"><?php echo esc_html( rest_url( 'orc/v1/offers' ) ); ?></code>
			<?php if ( Oros_Connect::woo() ) : ?>
				<p class="description orc-mt-8"><?php esc_html_e( 'Το orOS χρησιμοποιεί τα κλειδιά WooCommerce REST που ήδη έχει για το κατάστημα (Stock → WooCommerce). Με κλειδί «Ανάγνωση» μόνο διαβάζει· για να μαρκάρει τα αιτήματα «Στο orOS» χρειάζεται «Ανάγνωση/Εγγραφή».', 'oros-connect' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=advanced&section=keys' ) ); ?>"><?php esc_html_e( 'Κλειδιά REST', 'oros-connect' ); ?></a></p>
			<?php else : ?>
				<p class="description orc-mt-8"><?php esc_html_e( 'Χωρίς WooCommerce, το orOS συνδέεται με ένα Application Password ενός διαχειριστή (Χρήστες → Προφίλ → Application Passwords).', 'oros-connect' ); ?>
					<a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>"><?php esc_html_e( 'Application Passwords', 'oros-connect' ); ?></a></p>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Η εισαγωγή αιτημάτων ως προσφορές ετοιμάζεται στο orOS. Μέχρι τότε τα αιτήματα μένουν στο Publishing Quote → Αιτήματα και έρχονται με email.', 'oros-connect' ); ?></p>
		</div>

		<h2 class="orc-h2"><?php esc_html_e( 'Κατάσταση', 'oros-connect' ); ?></h2>
		<div class="orc-zone">
			<?php
			$seen = class_exists( 'ORC_Offers' ) ? ORC_Offers::last_seen() : array();
			$wait = $source ? count( pbq_get_requests( array( 'state' => 'new', 'limit' => 500 ) ) ) : 0;
			?>
			<p>
				<?php
				/* translators: %d: number of requests */
				echo esc_html( sprintf( __( 'Νέα αιτήματα που περιμένουν το orOS: %d', 'oros-connect' ), $wait ) );
				?>
			</p>
			<p>
				<?php
				echo esc_html(
					$seen
						/* translators: 1: date and time, 2: number of requests */
						? sprintf( __( 'Τελευταία φορά που τα πήρε το orOS: %1$s (%2$d αιτήματα).', 'oros-connect' ), wp_date( $fmt, $seen['t'] ), $seen['n'] )
						: __( 'Το orOS δεν έχει πάρει ακόμα αιτήματα.', 'oros-connect' )
				);
				?>
			</p>
			<?php if ( $source ) : ?>
				<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=pbq-settings&tab=requests' ) ); ?>"><?php esc_html_e( 'Όλα τα αιτήματα', 'oros-connect' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_tools(): void {
		?>
		<h2 class="orc-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'oros-connect' ); ?></h2>
		<div class="orc-zone">
			<form method="post" enctype="multipart/form-data" class="orc-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'orc_backup_nonce' ); ?>
				<input type="file" name="orc_import_file" accept=".json,application/json" />
				<button type="submit" name="orc_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'oros-connect' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=tools&orc_export=1' ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'oros-connect' ); ?></a>
			<p class="description orc-mt-8"><?php esc_html_e( 'Χωρίς το κλειδί καταστήματος: μετά την εισαγωγή το υπάρχον κλειδί μένει όπως είναι.', 'oros-connect' ); ?></p>
		</div>

		<h2 class="orc-h2"><?php esc_html_e( 'Ιστορικό αποστολών', 'oros-connect' ); ?></h2>
		<div class="orc-zone">
			<form method="post">
				<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
				<input type="hidden" name="orc_action" value="clear_log" />
				<p><?php esc_html_e( 'Κρατά τις τελευταίες 50 προσπάθειες, χωρίς emails.', 'oros-connect' ); ?></p>
				<button type="submit" class="button"><?php esc_html_e( 'Καθαρισμός ιστορικού', 'oros-connect' ); ?></button>
			</form>
		</div>

		<h2 class="orc-h2"><?php esc_html_e( 'Για developers', 'oros-connect' ); ?></h2>
		<div class="orc-zone">
			<table class="widefat striped orc-table">
				<tbody>
					<tr><td><code>[oros_newsletter title="yes|no" name="yes|no"]</code></td><td><?php esc_html_e( 'Η φόρμα εγγραφής.', 'oros-connect' ); ?></td></tr>
					<tr><td><code>orc_text</code> <?php esc_html_e( '(φίλτρο)', 'oros-connect' ); ?></td><td><?php esc_html_e( 'Αλλάζει ένα κείμενο του επισκέπτη: ( $text, $key, $lang ).', 'oros-connect' ); ?></td></tr>
					<tr><td><code>orc_newsletter_sent</code> <?php esc_html_e( '(action)', 'oros-connect' ); ?></td><td><?php esc_html_e( 'Μετά από κάθε αποστολή από το ταμείο: ( $order_id, $result ).', 'oros-connect' ); ?></td></tr>
					<tr><td><code>GET /wp-json/orc/v1/offers</code></td><td><?php esc_html_e( 'Τα αιτήματα του Publishing Quote για το orOS (state=new|contact|oros|closed|all, limit, page). Μόνο για διαχειριστές.', 'oros-connect' ); ?></td></tr>
					<tr><td><code>POST /wp-json/orc/v1/offers/&lt;id&gt;</code></td><td><?php esc_html_e( 'Αλλάζει την κατάσταση ενός αιτήματος: { state, note }.', 'oros-connect' ); ?></td></tr>
					<tr><td><code>ORC_DISABLE</code></td><td><?php esc_html_e( 'Στο wp-config.php: σταματά τις φόρμες, το κουτάκι στο ταμείο, τις αποστολές και τις Προσφορές.', 'oros-connect' ); ?></td></tr>
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

		if ( isset( $_POST['orc_import'] ) ) {
			if ( ! isset( $_POST['orc_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['orc_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( Oros_Connect::cap() ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'oros-connect' ) );
			}
			self::redirect( 'tools', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['orc_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Oros_Connect::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'oros-connect' ) );
		}

		$saved = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'oros-connect' ) ) );
		switch ( sanitize_key( wp_unslash( $_POST['orc_action'] ) ) ) {
			case 'save_modules':
				$s                       = ORC_Settings::get();
				$s['mods']['newsletter'] = '1' === self::post( 'orc_mod_newsletter' );
				$s['mods']['offers']     = '1' === self::post( 'orc_mod_offers' );
				$s['mode']               = sanitize_key( self::post( 'orc_mode' ) );
				$s['lang']               = sanitize_key( self::post( 'orc_lang' ) );
				ORC_Settings::save( $s );
				self::redirect( 'modules', $saved );
				break;
			case 'save_nl':
				self::redirect( 'newsletter', self::do_save_nl() );
				break;
			case 'check':
				$msgs = self::do_save_nl();
				$msgs[] = self::check_msg();
				self::redirect( 'newsletter', $msgs );
				break;
			case 'newkey':
				$msgs           = self::do_save_nl();
				$s              = ORC_Settings::get();
				$s['nl_key']    = ORC_Settings::new_key();
				ORC_Settings::save( $s );
				$msgs[] = self::msg( 'warning', __( 'Νέο κλειδί καταστήματος: βάλε το ίδιο και στο orOS. Το παλιό δεν ισχύει πια.', 'oros-connect' ) );
				self::redirect( 'newsletter', $msgs );
				break;
			case 'clear_log':
				ORC_Relay::clear_log();
				self::redirect( 'tools', array( self::msg( 'success', __( 'Το ιστορικό καθαρίστηκε.', 'oros-connect' ) ) ) );
				break;
			default:
				self::redirect( 'modules', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'oros-connect' ) ) ) );
		}
	}

	private static function do_save_nl(): array {
		$s          = ORC_Settings::get();
		$raw_url    = self::post( 'orc_nl_url' );
		$s['nl_url'] = $raw_url;
		$s['nl_name'] = '1' === self::post( 'orc_nl_name' );
		if ( Oros_Connect::woo() ) {
			$s['nl_checkout'] = '1' === self::post( 'orc_nl_checkout' );
			$s['nl_doi']      = '1' === self::post( 'orc_nl_doi' );
			$s['nl_key']      = trim( self::post( 'orc_nl_key' ) );
		}
		foreach ( array_keys( ORC_Settings::texts() ) as $k ) {
			foreach ( array( 'el', 'en' ) as $l ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
				if ( isset( $_POST[ 'orc_t_' . $k . '_' . $l ] ) ) {
					$s[ 't_' . $k . '_' . $l ] = self::post( 'orc_t_' . $k . '_' . $l );
				}
			}
		}
		ORC_Settings::save( $s );
		$after = ORC_Settings::get();

		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'oros-connect' ) ) );
		if ( '' !== trim( $raw_url ) && '' === $after['nl_url'] ) {
			$msgs[] = self::msg( 'error', __( 'Δεν βρέθηκε διεύθυνση εγγραφής του orOS (https://…/nl/s/ και 16 γράμματα ή αριθμοί). Η διεύθυνση άδειασε.', 'oros-connect' ) );
		}
		if ( Oros_Connect::woo() && '' !== trim( self::post( 'orc_nl_key' ) ) && '' === $after['nl_key'] ) {
			$msgs[] = self::msg( 'error', __( 'Το κλειδί καταστήματος θέλει ακριβώς 32 λατινικά γράμματα ή αριθμούς. Δεν κρατήθηκε.', 'oros-connect' ) );
		}
		if ( $after['nl_checkout'] && '' === $after['nl_url'] ) {
			$msgs[] = self::msg( 'warning', __( 'Το κουτάκι στο ταμείο δεν φαίνεται μέχρι να μπει η διεύθυνση εγγραφής του orOS.', 'oros-connect' ) );
		}
		return $msgs;
	}

	private static function check_msg(): array {
		$url = ORC_Settings::get()['nl_url'];
		if ( '' === $url ) {
			return self::msg( 'error', __( 'Δεν υπάρχει διεύθυνση εγγραφής για έλεγχο.', 'oros-connect' ) );
		}
		$r = ORC_Relay::check( $url );
		if ( 'ok' === $r['res'] ) {
			return self::msg( 'success', __( '✓ Το orOS απάντησε: η λίστα υπάρχει και δέχεται εγγραφές.', 'oros-connect' ) );
		}
		/* translators: 1: reason, 2: HTTP status */
		return self::msg( 'error', sprintf( __( '✗ Ο έλεγχος απέτυχε: %1$s (HTTP %2$d).', 'oros-connect' ), ORC_Relay::reason( $r['res'] ), $r['code'] ) );
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		set_transient( 'orc_aui_msg_' . get_current_user_id(), $msgs, 60 );
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
		if ( self::SLUG !== self::current_page() || '' === self::q( 'orc_export' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( Oros_Connect::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'oros-connect' ) );
		}
		$opt = ORC_Settings::get();
		unset( $opt['nl_key'] );
		$state = array(
			'plugin'  => 'oros-connect',
			'version' => ORC_VERSION,
			'options' => array(
				ORC_Settings::OPT => $opt,
			),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="oros-connect-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	/** Strict import through ORC_Settings::sanitize(); an invalid file changes nothing. */
	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['orc_import_file']['error'] ) ? (int) $_FILES['orc_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['orc_import_file']['size'] ) ? (int) $_FILES['orc_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'oros-connect' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['orc_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['orc_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'oros-connect' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['orc_import_file']['tmp_name'] ), true );
		// phpcs:enable
		if ( ! is_array( $state ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'oros-connect' ) ) );
		}
		$blob = isset( $state['options'][ ORC_Settings::OPT ] ) ? $state['options'][ ORC_Settings::OPT ] : null;
		if ( is_string( $blob ) ) {
			$blob = json_decode( $blob, true );
		}
		if ( ! is_array( $blob ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του orOS Connect).', 'oros-connect' ) ) );
		}
		$blob['nl_key'] = ORC_Settings::get()['nl_key'];
		ORC_Settings::save( $blob );
		return array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'oros-connect' ) ) );
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'orc_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'orc_aui_msg_' . get_current_user_id() );
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
		<p class="orc-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'oros-connect' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="orc-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="orc-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'oros-connect' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="orc-footer-cta">
			<a class="orc-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'oros-connect' ); ?>
			</a>
			<a class="orc-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'oros-connect' ); ?>
			</a>
		</p>
		<?php
	}
}

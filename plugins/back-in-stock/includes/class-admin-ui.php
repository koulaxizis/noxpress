<?php
/**
 * BIS_Admin_UI — menu, requests, settings, tools (Bible §3–§8, §11).
 *
 * Submenu «Ξανά σε απόθεμα» of the shared "Noxpress" menu (admin_menu
 * priority 420), slug bis-requests, capability Back_In_Stock::cap(). Tabs:
 *  - requests  demand per product / variation (active, pending, sent in
 *              30 days, stock now), «Στείλε τώρα» for one that is in stock,
 *              the addresses of one product with «Διαγραφή»
 *  - settings  mode, language, lifetimes, sending, limits, cooperation,
 *              visitor texts; checks of the two e-mails
 *  - tools     settings backup, developers
 *
 * Also the dashboard widget «Ζήτηση εξαντλημένων» (the 5 products with the
 * most waiting requests). Every state change: POST on admin_init (slug +
 * nonce + capability, PRG, message in the transient bis_aui_msg_{uid}).
 */

defined( 'ABSPATH' ) || exit;

final class BIS_Admin_UI {

	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'bis-requests';
	const NONCE       = 'bis_admin';
	const NONCE_FIELD = 'bis_admin_nonce';
	const NONCE_BAK   = 'bis_backup';
	const MAX_IMPORT  = 262144;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 420 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BIS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	private static function tabs(): array {
		return array(
			'requests' => __( 'Αιτήματα', 'back-in-stock' ),
			'settings' => __( 'Ρυθμίσεις', 'back-in-stock' ),
			'tools'    => __( 'Εργαλεία', 'back-in-stock' ),
		);
	}

	public static function admin_menu(): void {
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Back in Stock', 'back-in-stock' ),
			__( 'Ξανά σε απόθεμα', 'back-in-stock' ),
			Back_In_Stock::cap(),
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=settings' ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'back-in-stock' ) . '</a>'
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
		return isset( self::tabs()[ $tab ] ) ? $tab : 'requests';
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'bis-admin', BIS_URL . 'assets/admin.css', array(), BIS_VERSION );
	}

	/* =====================================================================
	 * Dashboard widget
	 * =================================================================== */

	public static function dashboard(): void {
		if ( ! current_user_can( Back_In_Stock::cap() ) ) {
			return;
		}
		wp_add_dashboard_widget( 'bis_demand', __( 'Ζήτηση εξαντλημένων', 'back-in-stock' ), array( __CLASS__, 'widget' ) );
	}

	public static function widget(): void {
		$rows = array_filter(
			BIS_Store::demand( 20 ),
			static function ( $d ) {
				return $d['active'] > 0;
			}
		);
		$rows = array_slice( $rows, 0, 5 );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Κανένα αίτημα δεν περιμένει.', 'back-in-stock' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><tbody>';
			foreach ( $rows as $d ) {
				$p = wc_get_product( $d['variation_id'] ? $d['variation_id'] : $d['product_id'] );
				echo '<tr><td>' . esc_html( $p ? wp_strip_all_tags( $p->get_name() ) : '#' . $d['product_id'] ) . '</td><td style="text-align:right;white-space:nowrap;">' . esc_html( number_format_i18n( $d['active'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Όλα τα αιτήματα', 'back-in-stock' ) . '</a></p>';
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Back_In_Stock::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'back-in-stock' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap bis-wrap">
			<h1><?php esc_html_e( 'Back in Stock', 'back-in-stock' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Φόρμα «Ειδοποίησέ με όταν ξαναέρθει» στα εξαντλημένα προϊόντα. Ο πελάτης παίρνει ένα email όταν το προϊόν ξαναμπεί σε απόθεμα και μετά το αίτημα σβήνεται. Χωρίς newsletter.', 'back-in-stock' ); ?></p>
			<?php
			self::print_notices( self::take_msgs() );
			if ( Back_In_Stock::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το BIS_DISABLE είναι ενεργό στο wp-config.php: καμία φόρμα δεν εμφανίζεται.', 'back-in-stock' ) . '</p></div>';
			}
			?>
			<nav class="nav-tab-wrapper bis-tabs">
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
				case 'settings':
					self::render_settings();
					break;
				case 'tools':
					self::render_tools();
					break;
				default:
					self::render_requests();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	private static function checkbox( string $name, bool $on, string $label ): void {
		printf(
			'<label class="bis-check"><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label )
		);
	}

	private static function radio( string $name, string $value, string $current, string $label ): void {
		printf(
			'<label class="bis-check"><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $value, $current, false ),
			esc_html( $label )
		);
	}

	private static function number( string $name, int $value, int $min, int $max, string $label ): void {
		printf(
			'<span class="bis-label">%1$s</span><input type="number" class="bis-num" name="%2$s" value="%3$d" min="%4$d" max="%5$d" step="1" />',
			esc_html( $label ),
			esc_attr( $name ),
			(int) $value,
			(int) $min,
			(int) $max
		);
	}

	private static function badge( string $text, string $kind ): string {
		return '<span class="bis-badge bis-badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function fmt(): string {
		return get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	}

	private static function text_pair( string $key, string $label, array $s ): void {
		$max = BIS_Settings::texts()[ $key ][2];
		?>
		<span class="bis-label"><?php echo esc_html( $label ); ?></span>
		<div class="bis-pair">
			<label><span class="bis-lang"><?php esc_html_e( 'Ελληνικά', 'back-in-stock' ); ?></span>
				<input type="text" class="bis-wide" name="bis_t_<?php echo esc_attr( $key ); ?>_el" value="<?php echo esc_attr( $s[ 't_' . $key . '_el' ] ); ?>" maxlength="<?php echo (int) $max; ?>" /></label>
			<label><span class="bis-lang"><?php esc_html_e( 'English (κενό = το ελληνικό)', 'back-in-stock' ); ?></span>
				<input type="text" class="bis-wide" name="bis_t_<?php echo esc_attr( $key ); ?>_en" value="<?php echo esc_attr( $s[ 't_' . $key . '_en' ] ); ?>" maxlength="<?php echo (int) $max; ?>" /></label>
		</div>
		<?php
	}

	private static function stock_label( string $status ): string {
		switch ( $status ) {
			case 'instock':
				return self::badge( __( 'Σε απόθεμα', 'back-in-stock' ), 'ok' );
			case 'onbackorder':
				return self::badge( __( 'Κατόπιν παραγγελίας', 'back-in-stock' ), 'warn' );
			case 'outofstock':
				return self::badge( __( 'Εξαντλημένο', 'back-in-stock' ), 'muted' );
		}
		return self::badge( '—', 'muted' );
	}

	/* ---------------------------------------------------------------------
	 * Requests
	 * ------------------------------------------------------------------- */

	private static function render_requests(): void {
		$pid = absint( self::q( 'product' ) );
		if ( $pid > 0 ) {
			self::render_product( $pid, absint( self::q( 'variation' ) ) );
			return;
		}
		$t    = BIS_Store::totals();
		$rows = BIS_Store::demand( 200 );
		self::email_checks();
		?>
		<h2 class="bis-h2"><?php esc_html_e( 'Ζήτηση', 'back-in-stock' ); ?></h2>
		<div class="bis-zone">
			<p class="bis-counts">
				<?php
				// phpcs:disable WordPress.Security.EscapeOutput -- escaped in badge().
				echo self::badge( __( 'Ενεργά', 'back-in-stock' ) . ': ' . number_format_i18n( $t['active'] ), 'ok' );
				echo self::badge( __( 'Περιμένουν επιβεβαίωση', 'back-in-stock' ) . ': ' . number_format_i18n( $t['pending'] ), 'muted' );
				echo self::badge( __( 'Στάλθηκαν σε 30 ημέρες', 'back-in-stock' ) . ': ' . number_format_i18n( $t['sent30'] ), 'muted' );
				// phpcs:enable
				?>
			</p>
		</div>
		<table class="widefat striped bis-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Προϊόν', 'back-in-stock' ); ?></th>
					<th><?php esc_html_e( 'Απόθεμα τώρα', 'back-in-stock' ); ?></th>
					<th class="num"><?php esc_html_e( 'Ενεργά', 'back-in-stock' ); ?></th>
					<th class="num"><?php esc_html_e( 'Ανεπιβεβαίωτα', 'back-in-stock' ); ?></th>
					<th class="num"><?php esc_html_e( 'Στάλθηκαν (30 ημ.)', 'back-in-stock' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6" class="bis-empty"><?php esc_html_e( 'Κανένα αίτημα ακόμα.', 'back-in-stock' ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $rows as $d ) :
					$p      = wc_get_product( $d['variation_id'] ? $d['variation_id'] : $d['product_id'] );
					$status = $p ? (string) $p->get_stock_status() : '';
					$detail = admin_url( 'admin.php?page=' . self::SLUG . '&product=' . $d['product_id'] . '&variation=' . $d['variation_id'] );
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $detail ); ?>"><?php echo esc_html( $p ? wp_strip_all_tags( $p->get_name() ) : '#' . $d['product_id'] ); ?></a>
							<?php if ( $p ) : ?>
								<span class="bis-muted"> · <a href="<?php echo esc_url( (string) get_edit_post_link( $d['product_id'] ) ); ?>"><?php esc_html_e( 'Επεξεργασία', 'back-in-stock' ); ?></a></span>
							<?php endif; ?>
						</td>
						<td><?php echo self::stock_label( $status ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $d['active'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $d['pending'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $d['sent30'] ) ); ?></td>
						<td>
							<?php if ( 'instock' === $status && $d['active'] > 0 ) : ?>
								<form method="post" class="bis-inline-form">
									<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
									<input type="hidden" name="bis_action" value="send_now" />
									<input type="hidden" name="bis_pid" value="<?php echo (int) $d['product_id']; ?>" />
									<input type="hidden" name="bis_vid" value="<?php echo (int) $d['variation_id']; ?>" />
									<button type="submit" class="button button-small"><?php esc_html_e( 'Στείλε τώρα', 'back-in-stock' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description bis-mt-8"><?php esc_html_e( 'Τα emails φεύγουν μόνα τους όταν ένα προϊόν ξαναμπεί σε απόθεμα. Το «Στείλε τώρα» χρειάζεται μόνο όταν το απόθεμα άλλαξε έξω από το WooCommerce (π.χ. απευθείας στη βάση) ή για να μην περιμένεις την καθυστέρηση.', 'back-in-stock' ); ?></p>
		<?php
	}

	private static function render_product( int $pid, int $vid ): void {
		$p    = wc_get_product( $vid ? $vid : $pid );
		$rows = BIS_Store::rows_for( $pid, $vid );
		$back = admin_url( 'admin.php?page=' . self::SLUG );
		?>
		<p><a href="<?php echo esc_url( $back ); ?>">← <?php esc_html_e( 'Όλα τα προϊόντα', 'back-in-stock' ); ?></a></p>
		<h2 class="bis-h2"><?php echo esc_html( $p ? wp_strip_all_tags( $p->get_name() ) : '#' . $pid ); ?> <?php echo $p ? self::stock_label( (string) $p->get_stock_status() ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></h2>
		<table class="widefat striped bis-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Email', 'back-in-stock' ); ?></th>
					<th><?php esc_html_e( 'Λογαριασμός', 'back-in-stock' ); ?></th>
					<th><?php esc_html_e( 'Κατάσταση', 'back-in-stock' ); ?></th>
					<th><?php esc_html_e( 'Γλώσσα', 'back-in-stock' ); ?></th>
					<th><?php esc_html_e( 'Ημερομηνία', 'back-in-stock' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6" class="bis-empty"><?php esc_html_e( 'Κανένα ανοιχτό αίτημα.', 'back-in-stock' ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $rows as $r ) :
					$u = (int) $r['user_id'] > 0 ? get_user_by( 'id', (int) $r['user_id'] ) : false;
					?>
					<tr>
						<td><?php echo esc_html( (string) $r['email'] ); ?></td>
						<td>
							<?php if ( $u instanceof WP_User ) : ?>
								<a href="<?php echo esc_url( get_edit_user_link( $u->ID ) ); ?>"><?php echo esc_html( $u->display_name ); ?></a>
							<?php else : ?>
								<span class="bis-muted"><?php esc_html_e( 'Επισκέπτης', 'back-in-stock' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo 'active' === $r['status'] ? self::badge( __( 'Ενεργό', 'back-in-stock' ), 'ok' ) : self::badge( __( 'Περιμένει επιβεβαίωση', 'back-in-stock' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></td>
						<td><?php echo esc_html( 'en' === $r['lang'] ? 'English' : 'Ελληνικά' ); ?></td>
						<td><?php echo esc_html( wp_date( self::fmt(), (int) strtotime( $r['created'] . ' UTC' ) ) ); ?></td>
						<td>
							<form method="post" class="bis-inline-form">
								<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
								<input type="hidden" name="bis_action" value="delete" />
								<input type="hidden" name="bis_id" value="<?php echo (int) $r['id']; ?>" />
								<input type="hidden" name="bis_pid" value="<?php echo (int) $pid; ?>" />
								<input type="hidden" name="bis_vid" value="<?php echo (int) $vid; ?>" />
								<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Διαγραφή', 'back-in-stock' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** The two e-mails, switched on or not. */
	private static function email_checks(): void {
		$restock = BIS_Emails::enabled( BIS_Emails::RESTOCK );
		$confirm = BIS_Emails::enabled( BIS_Emails::CONFIRM );
		?>
		<h2 class="bis-h2"><?php esc_html_e( 'Έλεγχος', 'back-in-stock' ); ?></h2>
		<div class="bis-zone bis-status">
			<p>
				<?php echo $restock ? self::badge( __( 'Ενεργό', 'back-in-stock' ), 'ok' ) : self::badge( __( 'Κλειστό', 'back-in-stock' ), 'danger' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?>
				<?php esc_html_e( 'Το email «Back in Stock: ξανά σε απόθεμα».', 'back-in-stock' ); ?>
				<a href="<?php echo esc_url( BIS_Emails::settings_url( 'BIS_Email_Restock' ) ); ?>"><?php esc_html_e( 'Θέμα, επικεφαλίδα και ενεργοποίηση', 'back-in-stock' ); ?></a>
			</p>
			<p>
				<?php echo $confirm ? self::badge( __( 'Ενεργό', 'back-in-stock' ), 'ok' ) : self::badge( __( 'Κλειστό', 'back-in-stock' ), 'danger' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?>
				<?php esc_html_e( 'Το email «Back in Stock: επιβεβαίωση» (για επισκέπτες χωρίς λογαριασμό).', 'back-in-stock' ); ?>
				<a href="<?php echo esc_url( BIS_Emails::settings_url( 'BIS_Email_Confirm' ) ); ?>"><?php esc_html_e( 'Θέμα, επικεφαλίδα και ενεργοποίηση', 'back-in-stock' ); ?></a>
			</p>
			<?php if ( ! $restock ) : ?>
				<p class="bis-warn"><?php esc_html_e( 'Με το email «ξανά σε απόθεμα» κλειστό η φόρμα δεν εμφανίζεται πουθενά.', 'back-in-stock' ); ?></p>
			<?php elseif ( ! $confirm ) : ?>
				<p class="bis-warn"><?php esc_html_e( 'Με το email επιβεβαίωσης κλειστό τη φόρμα τη βλέπουν μόνο οι συνδεδεμένοι πελάτες.', 'back-in-stock' ); ?></p>
			<?php endif; ?>
			<?php if ( ! function_exists( 'as_schedule_single_action' ) ) : ?>
				<p class="bis-warn"><?php esc_html_e( 'Το Action Scheduler δεν βρέθηκε: τα emails δεν μπορούν να σταλούν στο παρασκήνιο.', 'back-in-stock' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	private static function render_settings(): void {
		$s = BIS_Settings::get();
		self::email_checks();
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="bis_action" value="save_settings" />

			<h2 class="bis-h2"><?php esc_html_e( 'Λειτουργία', 'back-in-stock' ); ?></h2>
			<div class="bis-zone">
				<?php
				self::radio( 'bis_mode', 'off', $s['mode'], __( 'Ανενεργό: καμία φόρμα, κανένα email', 'back-in-stock' ) );
				self::radio( 'bis_mode', 'test', $s['mode'], __( 'Δοκιμή: τη φόρμα τη βλέπουν μόνο οι διαχειριστές του καταστήματος', 'back-in-stock' ) );
				self::radio( 'bis_mode', 'live', $s['mode'], __( 'Ενεργό: όλοι οι επισκέπτες', 'back-in-stock' ) );
				?>
				<span class="bis-label"><?php esc_html_e( 'Γλώσσα των κειμένων και των emails για τους επισκέπτες', 'back-in-stock' ); ?></span>
				<?php
				self::radio( 'bis_lang', 'auto', $s['lang'], __( 'Αυτόματα, από τη γλώσσα του site', 'back-in-stock' ) );
				self::radio( 'bis_lang', 'el', $s['lang'], __( 'Ελληνικά', 'back-in-stock' ) );
				self::radio( 'bis_lang', 'en', $s['lang'], __( 'English', 'back-in-stock' ) );
				?>
				<p class="description"><?php esc_html_e( 'Κάθε αίτημα κρατά τη γλώσσα του, και το email του φεύγει σε αυτή.', 'back-in-stock' ); ?></p>
			</div>

			<h2 class="bis-h2"><?php esc_html_e( 'Διάρκεια', 'back-in-stock' ); ?></h2>
			<div class="bis-zone">
				<?php
				self::number( 'bis_confirm_h', (int) $s['confirm_h'], 12, 168, __( 'Ώρες για την επιβεβαίωση ενός επισκέπτη (12–168)', 'back-in-stock' ) );
				self::number( 'bis_keep_days', (int) $s['keep_days'], 30, 365, __( 'Ημέρες που περιμένει ένα αίτημα το απόθεμα (30–365)', 'back-in-stock' ) );
				?>
				<p class="description"><?php esc_html_e( 'Μετά σβήνεται χωρίς email. Μετά την αποστολή το email του πελάτη σβήνεται αμέσως· μένει μόνο ένα αποτύπωμα για 30 ημέρες, για τις μετρήσεις και για να μη στείλει και το Wish Shelf το ίδιο μήνυμα.', 'back-in-stock' ); ?></p>
			</div>

			<h2 class="bis-h2"><?php esc_html_e( 'Αποστολή', 'back-in-stock' ); ?></h2>
			<div class="bis-zone">
				<?php
				self::number( 'bis_delay', (int) $s['delay'], 0, 120, __( 'Λεπτά αναμονής μετά το «σε απόθεμα» (0–120)', 'back-in-stock' ) );
				self::number( 'bis_batch', (int) $s['batch'], 10, 200, __( 'Emails ανά παρτίδα (10–200)', 'back-in-stock' ) );
				?>
				<p class="description"><?php esc_html_e( 'Η αναμονή προστατεύει από λάθος αλλαγή (0 → 5 → 0): πριν σταλεί οτιδήποτε το απόθεμα ελέγχεται ξανά. Οι παρτίδες τρέχουν στο παρασκήνιο με το Action Scheduler του WooCommerce, μία κάθε 30 δευτερόλεπτα.', 'back-in-stock' ); ?></p>
			</div>

			<h2 class="bis-h2"><?php esc_html_e( 'Όρια', 'back-in-stock' ); ?></h2>
			<div class="bis-zone">
				<?php
				self::number( 'bis_per_ip', (int) $s['per_ip'], 3, 100, __( 'Αιτήματα ανά διεύθυνση IP σε μία ώρα (3–100)', 'back-in-stock' ) );
				self::number( 'bis_per_email', (int) $s['per_email'], 1, 100, __( 'Ανοιχτά αιτήματα ανά email (1–100)', 'back-in-stock' ) );
				?>
				<p class="description"><?php esc_html_e( 'Οι διευθύνσεις IP δεν αποθηκεύονται, μόνο ένα αποτύπωμά τους για μία ώρα. Η φόρμα έχει και honeypot και έλεγχο χρόνου για τα bots.', 'back-in-stock' ); ?></p>
			</div>

			<h2 class="bis-h2"><?php esc_html_e( 'Συνεργασία', 'back-in-stock' ); ?></h2>
			<div class="bis-zone">
				<?php
				self::checkbox( 'bis_hide_gone', (bool) $s['hide_gone'], __( 'Χωρίς φόρμα στους τίτλους που έφυγαν από τη ροή του Book Feed (εκτός κυκλοφορίας)', 'back-in-stock' ) );
				self::checkbox( 'bis_also', (bool) $s['also'], __( '«Διαθέσιμο και ως» στο email, όταν το Product Formats έχει άλλη μορφή του ίδιου έργου σε απόθεμα', 'back-in-stock' ) );
				self::checkbox( 'bis_privacy', (bool) $s['privacy'], __( 'Σύνδεσμος στην πολιτική απορρήτου δίπλα στο κουτάκι συναίνεσης', 'back-in-stock' ) );
				?>
				<p class="description"><?php esc_html_e( 'Χωρίς φόρμα, πάντα: προϊόντα σε προπαραγγελία (Pre-Orders), κρυφές κατηγορίες (Hide Catalog), προϊόντα «κατόπιν παραγγελίας». Όποιος έχει το προϊόν στη λίστα του Wish Shelf και ζήτησε και εδώ ειδοποίηση, παίρνει ένα email, από το Back in Stock.', 'back-in-stock' ); ?></p>
			</div>

			<h2 class="bis-h2"><?php esc_html_e( 'Κείμενα για τους επισκέπτες', 'back-in-stock' ); ?></h2>
			<div class="bis-zone">
				<p class="description"><?php esc_html_e( 'Το {product} γίνεται το όνομα του προϊόντος και το {email} το email, κρυμμένο εν μέρει. Το κείμενο των emails αλλάζει από το WooCommerce → Ρυθμίσεις → Emails ή με template του θέματος.', 'back-in-stock' ); ?></p>
				<?php
				$labels = array(
					'title'     => __( 'Τίτλος της φόρμας', 'back-in-stock' ),
					'hint'      => __( 'Οδηγία', 'back-in-stock' ),
					'pick'      => __( 'Πάνω από τη λίστα παραλλαγών (χωρίς JavaScript)', 'back-in-stock' ),
					'email'     => __( 'Ετικέτα του πεδίου email', 'back-in-stock' ),
					'consent'   => __( 'Κουτάκι συναίνεσης', 'back-in-stock' ),
					'button'    => __( 'Κουμπί', 'back-in-stock' ),
					'ok_member' => __( 'Μετά το αίτημα (συνδεδεμένος πελάτης)', 'back-in-stock' ),
					'ok_guest'  => __( 'Μετά το αίτημα (επισκέπτης, ίδιο είτε υπήρχε αίτημα είτε όχι)', 'back-in-stock' ),
					'bad_email' => __( 'Λάθος email', 'back-in-stock' ),
					'no_ok'     => __( 'Χωρίς τσεκαρισμένο κουτάκι', 'back-in-stock' ),
					'limit'     => __( 'Όριο αιτημάτων', 'back-in-stock' ),
					'error'     => __( 'Σφάλμα', 'back-in-stock' ),
					'confirm_q' => __( 'Σελίδα επιβεβαίωσης', 'back-in-stock' ),
					'confirm_b' => __( 'Κουμπί επιβεβαίωσης', 'back-in-stock' ),
					'confirmed' => __( 'Μετά την επιβεβαίωση', 'back-in-stock' ),
					'unsub_q'   => __( 'Σελίδα ακύρωσης', 'back-in-stock' ),
					'unsub_b'   => __( 'Κουμπί ακύρωσης', 'back-in-stock' ),
					'unsubbed'  => __( 'Μετά την ακύρωση', 'back-in-stock' ),
					'invalid'   => __( 'Σύνδεσμος που έληξε', 'back-in-stock' ),
					'also'      => __( '«Διαθέσιμο και ως»', 'back-in-stock' ),
				);
				foreach ( $labels as $k => $label ) {
					self::text_pair( $k, $label, $s );
				}
				?>
			</div>

			<p class="bis-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'back-in-stock' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tools
	 * ------------------------------------------------------------------- */

	private static function render_tools(): void {
		?>
		<h2 class="bis-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'back-in-stock' ); ?></h2>
		<div class="bis-zone">
			<form method="post" enctype="multipart/form-data" class="bis-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'bis_backup_nonce' ); ?>
				<input type="file" name="bis_import_file" accept=".json,application/json" />
				<button type="submit" name="bis_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'back-in-stock' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=tools&bis_export=1' ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'back-in-stock' ); ?></a>
			<p class="description bis-mt-8"><?php esc_html_e( 'Μόνο οι ρυθμίσεις: τα αιτήματα και τα emails των πελατών δεν μπαίνουν ποτέ στο αρχείο.', 'back-in-stock' ); ?></p>
		</div>

		<h2 class="bis-h2"><?php esc_html_e( 'Για developers', 'back-in-stock' ); ?></h2>
		<div class="bis-zone">
			<table class="widefat striped bis-table">
				<tbody>
					<tr><td><code>bis_covers( $email, $product_id, $variation_id )</code></td><td><?php esc_html_e( 'Αν το Back in Stock στέλνει (ή έστειλε τις τελευταίες 2 ημέρες) το email για αυτό το email και προϊόν. Το διαβάζει το Wish Shelf.', 'back-in-stock' ); ?></td></tr>
					<tr><td><code>bis_show_form</code> <?php esc_html_e( '(φίλτρο)', 'back-in-stock' ); ?></td><td><?php esc_html_e( 'Αν ένα προϊόν παίρνει φόρμα: ( $ok, $product ).', 'back-in-stock' ); ?></td></tr>
					<tr><td><code>bis_text</code> <?php esc_html_e( '(φίλτρο)', 'back-in-stock' ); ?></td><td><?php esc_html_e( 'Αλλάζει ένα κείμενο του επισκέπτη: ( $text, $key, $lang ).', 'back-in-stock' ); ?></td></tr>
					<tr><td><code>bis_sent</code> <?php esc_html_e( '(action)', 'back-in-stock' ); ?></td><td><?php esc_html_e( 'Μετά από κάθε email «ξανά σε απόθεμα»: ( $request_id, $product_id, $variation_id ).', 'back-in-stock' ); ?></td></tr>
					<tr><td><code>emails/bis-confirm.php</code>, <code>emails/bis-restock.php</code></td><td><?php esc_html_e( 'Templates των emails, αντικαθίστανται από το θέμα στο yourtheme/woocommerce/emails/ (και plain/).', 'back-in-stock' ); ?></td></tr>
					<tr><td><code>BIS_DISABLE</code></td><td><?php esc_html_e( 'Στο wp-config.php: καμία φόρμα δεν εμφανίζεται.', 'back-in-stock' ); ?></td></tr>
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

		if ( isset( $_POST['bis_import'] ) ) {
			if ( ! isset( $_POST['bis_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bis_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( Back_In_Stock::cap() ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'back-in-stock' ) );
			}
			self::redirect( 'tools', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['bis_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Back_In_Stock::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'back-in-stock' ) );
		}

		switch ( sanitize_key( wp_unslash( $_POST['bis_action'] ) ) ) {
			case 'save_settings':
				self::redirect( 'settings', self::do_save_settings() );
				break;
			case 'send_now':
				$ok = BIS_Sender::send_now( absint( self::post( 'bis_pid' ) ), absint( self::post( 'bis_vid' ) ) );
				self::redirect(
					'requests',
					array(
						$ok
							? self::msg( 'success', __( 'Η αποστολή ξεκινά σε λίγα δευτερόλεπτα, στο παρασκήνιο.', 'back-in-stock' ) )
							: self::msg( 'error', __( 'Το προϊόν δεν είναι σε απόθεμα ή το Action Scheduler δεν βρέθηκε.', 'back-in-stock' ) ),
					)
				);
				break;
			case 'delete':
				BIS_Store::delete( absint( self::post( 'bis_id' ) ) );
				self::redirect(
					'requests&product=' . absint( self::post( 'bis_pid' ) ) . '&variation=' . absint( self::post( 'bis_vid' ) ),
					array( self::msg( 'success', __( 'Το αίτημα διαγράφηκε.', 'back-in-stock' ) ) )
				);
				break;
			default:
				self::redirect( 'requests', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'back-in-stock' ) ) ) );
		}
	}

	private static function do_save_settings(): array {
		$s = BIS_Settings::get();
		foreach ( array( 'mode', 'lang' ) as $k ) {
			$s[ $k ] = sanitize_key( self::post( 'bis_' . $k ) );
		}
		foreach ( array( 'keep_days', 'confirm_h', 'delay', 'batch', 'per_ip', 'per_email' ) as $k ) {
			$s[ $k ] = (int) self::post( 'bis_' . $k );
		}
		foreach ( array( 'hide_gone', 'also', 'privacy' ) as $k ) {
			$s[ $k ] = '1' === self::post( 'bis_' . $k );
		}
		foreach ( array_keys( BIS_Settings::texts() ) as $k ) {
			foreach ( array( 'el', 'en' ) as $l ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
				if ( isset( $_POST[ 'bis_t_' . $k . '_' . $l ] ) ) {
					$s[ 't_' . $k . '_' . $l ] = self::post( 'bis_t_' . $k . '_' . $l );
				}
			}
		}
		BIS_Settings::save( $s );
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'back-in-stock' ) ) );
		if ( 'off' !== BIS_Settings::mode() && ! BIS_Emails::enabled( BIS_Emails::RESTOCK ) ) {
			$msgs[] = self::msg( 'warning', __( 'Το email «Back in Stock: ξανά σε απόθεμα» είναι κλειστό στο WooCommerce: η φόρμα δεν εμφανίζεται.', 'back-in-stock' ) );
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
		set_transient( 'bis_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab ) );
		exit;
	}

	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/* =====================================================================
	 * Backup
	 * =================================================================== */

	public static function route_export(): void {
		if ( self::SLUG !== self::current_page() || '' === self::q( 'bis_export' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( Back_In_Stock::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'back-in-stock' ) );
		}
		$state = array(
			'plugin'  => 'back-in-stock',
			'version' => BIS_VERSION,
			'options' => array(
				BIS_Settings::OPT => BIS_Settings::get(),
			),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="back-in-stock-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['bis_import_file']['error'] ) ? (int) $_FILES['bis_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['bis_import_file']['size'] ) ? (int) $_FILES['bis_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'back-in-stock' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['bis_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['bis_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'back-in-stock' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['bis_import_file']['tmp_name'] ), true );
		// phpcs:enable
		$blob = is_array( $state ) && isset( $state['options'][ BIS_Settings::OPT ] ) ? $state['options'][ BIS_Settings::OPT ] : null;
		if ( ! is_array( $blob ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Back in Stock).', 'back-in-stock' ) ) );
		}
		BIS_Settings::save( $blob );
		return array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'back-in-stock' ) ) );
	}

	/* =====================================================================
	 * Notices, footer
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'bis_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'bis_aui_msg_' . get_current_user_id() );
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

	private static function footer(): void {
		?>
		<p class="bis-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'back-in-stock' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="bis-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="bis-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'back-in-stock' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="bis-footer-cta">
			<a class="bis-kofi" href="https://noxpress.tech/support/" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '♡ Στήριξε το Noxpress', 'back-in-stock' ); ?>
			</a>
			<a class="bis-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'back-in-stock' ); ?>
			</a>
		</p>
		<?php
	}
}

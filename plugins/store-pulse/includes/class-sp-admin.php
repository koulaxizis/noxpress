<?php
/**
 * SP_Admin — Μενού, Ρυθμίσεις, Widget, Assets.
 *
 * Κοινό μενού «Noxpress» (admin_menu, priority 20 — RS = 9, SF = 30):
 *  - Το top-level 'noxpress' (hub) το δημιουργεί το Noxpress Core
 *    (includes/noxpress-core, priority 5, Bible §16). Εμείς δένουμε
 *    ως submenus.
 *  - Σε ΚΑΘΕ συνδυασμό υπάρχουν οι δικές μας σελίδες 'sp-dashboard'
 *    και 'sp-settings' — το admin.php?page=sp-dashboard δουλεύει πάντα.
 *
 * Ρυθμίσεις: PRG pattern (POST → validate → redirect) με nonce
 * 'sp-save-settings', στο admin_init (πριν σταλεί οποιοδήποτε output,
 * ώστε το redirect να λειτουργεί). Καμία επιλογή δεν γράφεται χωρίς
 * validation.
 *
 * Quick View widget: wp_add_dashboard_widget με capability
 * manage_woocommerce — το markup κάνει delegate στο SP_Dashboard
 * ώστε όλο το rendering να μένει σε ένα σημείο.
 *
 * Assets: μοναδικό CSS (assets/sp-admin.css), scoped σε .sp-wrap,
 * φορτώνεται μόνο στις δικές μας σελίδες (match στο ?page=) +
 * index.php (widget).
 */

defined( 'ABSPATH' ) || exit;

final class SP_Admin {

	const CAP           = 'manage_woocommerce';
	const SLUG_MENU     = 'noxpress';
	const SLUG_DASH     = 'sp-dashboard';
	const SLUG_SETTINGS = 'sp-settings';
	const NONCE_ACTION  = 'sp-save-settings';
	const NONCE_NAME    = 'sp_nonce';
	const WIDGET_ID     = 'sp_quick_view';

	/** Labels των presets — msgids που υπάρχουν στο SP_Lang. */
	const PRESET_LABELS = array(
		'today'     => 'Σήμερα',
		'yesterday' => 'Χθες',
		'7d'        => 'Τελευταίες 7 ημέρες',
		'15d'       => 'Τελευταίες 15 ημέρες',
		'month'     => 'Τον τελευταίο μήνα',
	);

	/** Whitelist των Quick View καρτών → msgid labels (SP_Lang). */
	const CARD_LABELS = array(
		'pending'     => 'Εκκρεμείς παραγγελίες',
		'old_pending' => 'Παλιές εκκρεμείς (>7 ημέρες)',
		'completed'   => 'Εξυπηρετημένες παραγγελίες',
		'low'         => 'Χαμηλό στοκ',
		'out'         => 'Εξαντλημένα',
		'publisher'   => 'Κέρδος εκδότη',
		'others'      => 'Οφειλές προς άλλους',
	);


	/** Μηνύματα λάθους του τελευταίου POST (ίδιο request με το render). */
	private static $errors = array();

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'handle_settings_post' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widget' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( SP_FILE ),
			array( __CLASS__, 'action_links' )
		);
	}

	/* =====================================================================
	 * URLs
	 * =================================================================== */

	public static function dash_url(): string {

		// Το 'sp-dashboard' είναι καταχωρημένο σε κάθε συνδυασμό
		// plugins (βλ. register_menu) — άρα αυτό το URL δουλεύει πάντα.
		return admin_url( 'admin.php?page=' . self::SLUG_DASH );
	}

	public static function settings_url(): string {

		// Άμεση επιστροφή του admin.php?page=sp-settings.
		return admin_url( 'admin.php?page=' . self::SLUG_SETTINGS );
	}

	/* =====================================================================
	 * Menu
	 * =================================================================== */

	public static function register_menu(): void {

		// Το κοινό top-level «Noxpress» (με το hub ως landing) το δημιουργεί
		// το Noxpress Core (priority 5, Bible §16). Εδώ μόνο submenus.
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Store Pulse — Dashboard', 'store-pulse' ),
			__( 'Store Pulse', 'store-pulse' ),
			self::CAP,
			self::SLUG_DASH,
			array( __CLASS__, 'render_dashboard' )
		);
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Store Pulse — Ρυθμίσεις', 'store-pulse' ),
			__( 'SP Ρυθμίσεις', 'store-pulse' ),
			self::CAP,
			self::SLUG_SETTINGS,
			array( __CLASS__, 'render_settings' )
		);
	}

	/* =====================================================================
	 * Assets
	 * =================================================================== */

	public static function enqueue_assets( string $hook ): void {

		$page = isset( $_GET['page'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( $_GET['page'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

		$load = in_array( $page, array( self::SLUG_DASH, self::SLUG_SETTINGS ), true )
			|| ( 'index.php' === $hook ); // Quick View widget.

		if ( ! $load ) {
			return;
		}

		wp_enqueue_style(
			'sp-admin',
			SP_URL . 'assets/sp-admin.css',
			array(),
			SP_VERSION
		);
	}

	/* =====================================================================
	 * Plugin action links (σελίδα Plugins)
	 * =================================================================== */

	public static function action_links( array $links ): array {

		$custom = array(
			'<a href="' . esc_url( self::dash_url() ) . '">'
				. esc_html__( 'Dashboard', 'store-pulse' ) . '</a>',
			'<a href="' . esc_url( self::settings_url() ) . '">'
				. esc_html__( 'Ρυθμίσεις', 'store-pulse' ) . '</a>',
		);

		return array_merge( $custom, $links );
	}

	/* =====================================================================
	 * Renders (delegation)
	 * =================================================================== */

	public static function render_dashboard(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'store-pulse' ) );
		}

		SP_Dashboard::render_page();
	}

	/* =====================================================================
	 * Quick View widget (WordPress dashboard)
	 * =================================================================== */

	public static function register_widget(): void {

		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// Δεν έχει επιλέξει καμία κάρτα → δεν εμφανίζεται καθόλου.
		if ( array() === SP_Data::safe_quick_cards() ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'Store Pulse', 'store-pulse' ),
			array( 'SP_Dashboard', 'render_widget' )
		);
	}

	/* =====================================================================
	 * Σελίδα Ρυθμίσεων
	 * =================================================================== */

	public static function render_settings(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'store-pulse' ) );
		}

		$errors = self::$errors;

		$saved = isset( $_GET['sp-updated'] )
			&& '1' === sanitize_text_field( wp_unslash( $_GET['sp-updated'] ) );

		$period_orders   = SP_Data::safe_preset( 'sp_default_period_orders', '7d' );
		$period_money    = SP_Data::safe_preset( 'sp_default_period_money', 'month' );
		$period_refunds  = SP_Data::safe_preset( 'sp_default_period_refunds', 'month' );
		$period_canceled = SP_Data::safe_preset( 'sp_default_period_cancelled', 'month' );

		$threshold  = SP_Data::safe_threshold();
		$publisher  = SP_Data::safe_publisher();
		$quick      = SP_Data::safe_quick_cards();

		$rs_names = sp_rs_active() ? RS_Beneficiaries::collect_names() : array();
		?>
		<div class="wrap sp-wrap">
			<h1><?php esc_html_e( 'Store Pulse — Ρυθμίσεις', 'store-pulse' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'store-pulse' ); ?></p>
				</div>
			<?php endif; ?>

			<?php foreach ( $errors as $err ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $err ); ?></p></div>
			<?php endforeach; ?>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<input type="hidden" name="sp_form" value="settings" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="sp_period_orders">
								<?php esc_html_e( 'Χρονικό διάστημα εξυπηρετημένων παραγγελιών', 'store-pulse' ); ?>
							</label>
						</th>
						<td>
							<select id="sp_period_orders" name="sp_period_orders">
								<?php self::preset_options( $period_orders ); ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Ισχύει για την κάρτα «Εξυπηρετημένες παραγγελίες» σε dashboards και widget.', 'store-pulse' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="sp_period_money">
								<?php esc_html_e( 'Χρονικό διάστημα κερδών & οφειλών', 'store-pulse' ); ?>
							</label>
						</th>
						<td>
							<select id="sp_period_money" name="sp_period_money">
								<?php self::preset_options( $period_money ); ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Ισχύει για τις κάρτες «Κέρδος εκδότη» και «Οφειλές προς άλλους».', 'store-pulse' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="sp_period_refunds">
								<?php esc_html_e( 'Χρονικό διάστημα επιστροφών', 'store-pulse' ); ?>
							</label>
						</th>
						<td>
							<select id="sp_period_refunds" name="sp_period_refunds">
								<?php self::preset_options( $period_refunds ); ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Ισχύει για την κάρτα «Επιστροφές» σε dashboards και widget.', 'store-pulse' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="sp_period_cancelled">
								<?php esc_html_e( 'Χρονικό διάστημα ακυρώσεων', 'store-pulse' ); ?>
							</label>
						</th>
						<td>
							<select id="sp_period_cancelled" name="sp_period_cancelled">
								<?php self::preset_options( $period_canceled ); ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Χρονικό διάστημα προβολής των ακυρωμένων παραγγελιών.', 'store-pulse' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="sp_threshold">
								<?php esc_html_e( 'Όριο χαμηλού στοκ (τεμάχια)', 'store-pulse' ); ?>
							</label>
						</th>
						<td>
							<input type="number" id="sp_threshold" name="sp_threshold"
								min="0" step="1"
								value="<?php echo esc_attr( (string) $threshold ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Ένα προϊόν θεωρείται σε χαμηλό στοκ όταν το απόθεμά του είναι ≤ αυτό το νούμερο. Το «0» θεωρείται εξαντλημένο (ξεχωριστή κάρτα).', 'store-pulse' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="sp_publisher">
								<?php esc_html_e( 'Δικαιούχος — εκδότης', 'store-pulse' ); ?>
							</label>
						</th>
						<td>
							<?php if ( sp_rs_active() ) : ?>
								<select id="sp_publisher" name="sp_publisher">
									<option value="">—</option>
									<?php foreach ( $rs_names as $name ) : ?>
										<option value="<?php echo esc_attr( $name ); ?>"
											<?php selected( $publisher, $name ); ?>>
											<?php echo esc_html( $name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input type="text" disabled="disabled"
									placeholder="<?php esc_attr_e( 'Χρειάζεται το Revenue Splitter', 'store-pulse' ); ?>"
									class="regular-text" />
								<input type="hidden" name="sp_publisher" value="" />
							<?php endif; ?>
							<p class="description">
								<?php esc_html_e( 'Ο δικαιούχος του Revenue Splitter για τον οποίο εμφανίζεται το κέρδος. Πρέπει να υπάρχει ήδη στις Ρυθμίσεις του Revenue Splitter.', 'store-pulse' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<?php esc_html_e( 'Κάρτες Quick View (widget)', 'store-pulse' ); ?>
						</th>
						<td>
							<fieldset>
								<legend class="screen-reader-text">
									<?php esc_html_e( 'Κάρτες Quick View (widget)', 'store-pulse' ); ?>
								</legend>
								<?php foreach ( self::CARD_LABELS as $card_key => $card_label ) : ?>
									<label class="sp-check">
										<input type="checkbox"
											name="sp_quick_cards[]"
											value="<?php echo esc_attr( $card_key ); ?>"
											<?php checked( in_array( $card_key, $quick, true ) ); ?> />
										<?php esc_html_e( $card_label, 'store-pulse' ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description">
									<?php esc_html_e( 'Επίλεξε ποιες κάρτες εμφανίζονται στο Quick View widget της αρχικής σελίδας του WordPress.', 'store-pulse' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<?php esc_html_e( 'Γλώσσα οθόνης', 'store-pulse' ); ?>
						</th>
						<td>
							<p class="description">
								<span class="sp-lang-current">
									<?php
									// Ονόματα γλωσσών: endonyms — ίδια σε κάθε γλώσσα UI.
									echo esc_html( 'el' === SP_Lang::get_lang() ? 'Ελληνικά' : 'English' );
									echo ' — ';
									echo esc_html(
										'auto' === SP_Lang::get_choice()
											? __( 'Αυτόματη (WordPress)', 'store-pulse' )
											: __( 'Επιλογή χρήστη', 'store-pulse' )
									);
									?>
								</span><br />
								<?php esc_html_e( 'Η γλώσσα οθόνης επιλέγεται ανά χρήστη στις Ρυθμίσεις του Revenue Splitter και ισχύει για όλα τα Noxpress plugins. Χωρίς το Revenue Splitter ακολουθείται η γλώσσα του προφίλ σου στο WordPress.', 'store-pulse' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Αποθήκευση ρυθμίσεων', 'store-pulse' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * POST των Ρυθμίσεων: validate + save + redirect (PRG). Τρέχει στο
	 * admin_init — πριν από κάθε output — ώστε το wp_safe_redirect να
	 * στέλνει πραγματικά header. Σε λάθη, τα μηνύματα μένουν στο
	 * self::$errors και τα δείχνει το render_settings (ίδιο request).
	 */
	public static function handle_settings_post(): void {

		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- ο nonce ελέγχεται παρακάτω.
		$sp_form_action = isset( $_POST['sp_form'] )
			? sanitize_text_field( wp_unslash( $_POST['sp_form'] ) )
			: '';
		$page = isset( $_GET['page'] )
			? sanitize_key( wp_unslash( $_GET['page'] ) )
			: '';
		// phpcs:enable

		if ( 'settings' !== $sp_form_action || self::SLUG_SETTINGS !== $page ) {
			return;
		}

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'store-pulse' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		self::$errors = self::save_settings();

		if ( array() !== self::$errors ) {
			return;
		}

		SP_Data::flush();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::SLUG_SETTINGS,
					'sp-updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Τα <option> ενός preset select — κάθε tag escaped.
	 */
	private static function preset_options( string $current ): void {

		foreach ( self::PRESET_LABELS as $value => $label ) {

			// Πρώτα το __( ) (gettext filter, GR/EN), μετά το escaping.
			$text = esc_html__( $label, 'store-pulse' );

			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				$text
			);
		}
	}

	/* =====================================================================
	 * Αποθήκευση (validation) — επιστρέφει array με μηνύματα λάθους.
	 * Κενό array = όλα ΟΚ (ο caller κάνει redirect).
	 * =================================================================== */

	private static function save_settings(): array {

		$errors = array();

		// ---- Presets (whitelist SP_Data::PRESETS) ----
		$post_periods = array(
			'sp_period_orders'    => isset( $_POST['sp_period_orders'] ) ? sanitize_text_field( wp_unslash( $_POST['sp_period_orders'] ) ) : '',
			'sp_period_money'     => isset( $_POST['sp_period_money'] ) ? sanitize_text_field( wp_unslash( $_POST['sp_period_money'] ) ) : '',
			'sp_period_refunds'   => isset( $_POST['sp_period_refunds'] ) ? sanitize_text_field( wp_unslash( $_POST['sp_period_refunds'] ) ) : '',
			'sp_period_cancelled' => isset( $_POST['sp_period_cancelled'] ) ? sanitize_text_field( wp_unslash( $_POST['sp_period_cancelled'] ) ) : '',
		);

		$preset_targets = array(
			'sp_period_orders'    => 'sp_default_period_orders',
			'sp_period_money'     => 'sp_default_period_money',
			'sp_period_refunds'   => 'sp_default_period_refunds',
			'sp_period_cancelled' => 'sp_default_period_cancelled',
		);

		$valid_periods  = array();
		$invalid_period = false;

		foreach ( $post_periods as $field => $value ) {

			if ( ! in_array( $value, SP_Data::PRESETS, true ) ) {
				$invalid_period = true;
				continue;
			}

			$valid_periods[ $preset_targets[ $field ] ] = $value;
		}

		// Ένα μήνυμα, όσα πεδία κι αν είναι άκυρα.
		if ( $invalid_period ) {
			$errors[] = __( 'Μη έγκυρο χρονικό διάστημα.', 'store-pulse' );
		}

		// ---- Low-stock threshold ----
		$raw_threshold = isset( $_POST['sp_threshold'] )
			? sanitize_text_field( wp_unslash( $_POST['sp_threshold'] ) )
			: '';

		$valid_threshold = null;

		if ( ! is_numeric( $raw_threshold ) || (float) $raw_threshold < 0 ) {
			$errors[] = __( 'Μη έγκυρο όριο στοκ (0+).', 'store-pulse' );
		} else {
			$valid_threshold = (string) (int) round( (float) $raw_threshold );
		}

		// ---- Publisher ----
		$raw_publisher = isset( $_POST['sp_publisher'] )
			? sanitize_text_field( wp_unslash( $_POST['sp_publisher'] ) )
			: '';

		if ( '' !== $raw_publisher && ! sp_rs_active() ) {
			// Χωρίς RS δεν επιτρέπεται επιλογή (το UI το έχει ήδη disabled).
			$raw_publisher = '';
		}

		if ( '' !== $raw_publisher
			&& sp_rs_active()
			&& ! in_array( $raw_publisher, RS_Beneficiaries::collect_names(), true ) ) {
			// Άγνωστος δικαιούχος — δεν γράφουμε τιμή, warning όχι fatal.
			$raw_publisher = '';
		}

		// ---- Quick View cards ----
		$raw_cards = isset( $_POST['sp_quick_cards'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['sp_quick_cards'] ) )
			: array();

		$cards = array_values(
			array_intersect(
				array_keys( self::CARD_LABELS ),
				$raw_cards
			)
		);

		if ( array() === $cards ) {
			$errors[] = __( 'Επίλεξε τουλάχιστον μία κάρτα για το Quick View.', 'store-pulse' );
		}

		// ---- Write phase: όλα ή τίποτα (no partial writes) ----
		if ( array() === $errors ) {

			foreach ( $valid_periods as $target => $value ) {
				update_option( $target, $value );
			}

			if ( null !== $valid_threshold ) {
				update_option( 'sp_low_stock_threshold', $valid_threshold );
			}

			update_option( 'sp_publisher', $raw_publisher );
			update_option( 'sp_quick_cards', $cards );
		}

		return $errors;
	}
}
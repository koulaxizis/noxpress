<?php
/**
 * LF_Admin_UI — admin pages, POST routes (PRG), page probe, backup.
 *
 * Pages (Bible §8, admin_menu priority 40): shared top-level 'noxpress'.
 * When another Noxpress plugin created it (RS 9 / SP 20 / SF 30) we add
 * submenus; otherwise Loop Fixer creates it with its own page as landing.
 *  - lf-loop-fixer  status, theme detector, areas, text fixes, page probe
 *  - lf-settings    master switch, test mode, backup & restore
 *
 * Security (Bible §11): every state change is a POST handled on
 * admin_init — page slug → nonce → capability → whitelisted action →
 * strict validation (LF_Settings, one implementation) → PRG with a
 * per-user notice transient. The privileged GET (export) is gated the
 * same way. No AJAX endpoints and no admin JavaScript: plain forms.
 *
 * Probe: a POST (opened in a new tab) is validated here and redirected
 * to the same-site URL with ?lf_probe=<nonce>; the front end records what
 * it saw in the transient lf_probe_{uid}, shown on the main page.
 *
 * Language (Bible §9): reads the rs_lang user meta only (LF_Lang).
 */

defined( 'ABSPATH' ) || exit;

final class LF_Admin_UI {

	const CAP       = 'manage_woocommerce';
	const SLUG_MENU = 'noxpress';     // Shared top-level of the Noxpress ecosystem.
	const SLUG_MAIN = 'lf-loop-fixer';
	const SLUG_SET  = 'lf-settings';

	const NONCE       = 'lf_admin';
	const NONCE_FIELD = 'lf_nonce';
	const NONCE_BAK   = 'lf_backup';

	/** Empty rows offered for new text fixes. */
	const NEW_FIX_ROWS = 2;

	/** Max size of an imported backup file (bytes). */
	const MAX_IMPORT = 1048576;

	/** true when Loop Fixer created the top-level 'noxpress' in this request. */
	private static $owns_top = false;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 40 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_backup' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LF_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {

		if ( empty( $GLOBALS['admin_page_hooks'][ self::SLUG_MENU ] ) ) {
			// No other Noxpress plugin created the top-level: we do, with
			// the Loop Fixer page as landing page.
			add_menu_page(
				__( 'Noxpress', 'loop-fixer' ),
				__( 'Noxpress', 'loop-fixer' ),
				self::CAP,
				self::SLUG_MENU,
				array( __CLASS__, 'render_main' ),
				'dashicons-chart-pie',
				57
			);
			self::$owns_top = true;
		}

		add_submenu_page(
			self::SLUG_MENU,
			__( 'Loop Fixer', 'loop-fixer' ),
			__( 'Loop Fixer', 'loop-fixer' ),
			self::CAP,
			self::SLUG_MAIN,
			array( __CLASS__, 'render_main' )
		);

		add_submenu_page(
			self::SLUG_MENU,
			__( 'Loop Fixer — Ρυθμίσεις', 'loop-fixer' ),
			__( 'LF Ρυθμίσεις', 'loop-fixer' ),
			self::CAP,
			self::SLUG_SET,
			array( __CLASS__, 'render_settings' )
		);

		if ( self::$owns_top ) {
			// WordPress repeats the top-level as first submenu ("Noxpress"),
			// a duplicate of "Loop Fixer": the top-level link now opens lf-loop-fixer.
			remove_submenu_page( self::SLUG_MENU, self::SLUG_MENU );
		}
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG_MAIN ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'loop-fixer' ) . '</a>'
		);
		return $links;
	}

	/** Current admin page slug (sanitized) — '' outside admin.php?page=. */
	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	private static function is_main_page( string $page ): bool {
		return self::SLUG_MAIN === $page || ( self::$owns_top && self::SLUG_MENU === $page );
	}

	public static function assets(): void {
		$page = self::current_page();
		if ( ! self::is_main_page( $page ) && self::SLUG_SET !== $page ) {
			return;
		}
		wp_enqueue_style( 'lf-admin', LF_URL . 'assets/admin.css', array(), LF_VERSION );
	}

	/* =====================================================================
	 * Labels (msgids in one place)
	 * =================================================================== */

	private static function mode_labels(): array {
		return array(
			'off'     => __( 'Ανενεργό', 'loop-fixer' ),
			'inject'  => __( 'Έγχυση στην κάρτα του θέματος', 'loop-fixer' ),
			'replace' => __( 'Αντικατάσταση με το πρότυπο του WooCommerce', 'loop-fixer' ),
		);
	}

	private static function part_labels(): array {
		return array(
			'rating' => __( 'Βαθμολογία', 'loop-fixer' ),
			'price'  => __( 'Τιμή', 'loop-fixer' ),
			'button' => __( 'Κουμπί καλαθιού', 'loop-fixer' ),
		);
	}

	private static function anchor_labels(): array {
		return array(
			'title'     => __( 'Τίτλος προϊόντος', 'loop-fixer' ),
			'thumbnail' => __( 'Εικόνα προϊόντος', 'loop-fixer' ),
		);
	}

	private static function align_labels(): array {
		return array(
			''       => __( 'Όπως το θέμα', 'loop-fixer' ),
			'left'   => __( 'Αριστερά', 'loop-fixer' ),
			'center' => __( 'Κέντρο', 'loop-fixer' ),
			'right'  => __( 'Δεξιά', 'loop-fixer' ),
		);
	}

	private static function color_labels(): array {
		return array(
			'price'       => __( 'Χρώμα τιμής', 'loop-fixer' ),
			'price_hover' => __( 'Χρώμα τιμής (hover κάρτας)', 'loop-fixer' ),
			'btn_bg'      => __( 'Φόντο κουμπιού', 'loop-fixer' ),
			'btn_text'    => __( 'Κείμενο κουμπιού', 'loop-fixer' ),
		);
	}

	/* =====================================================================
	 * Page: Loop Fixer (main)
	 * =================================================================== */

	public static function render_main(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'loop-fixer' ) );
		}

		$notices = self::take_msgs();
		?>
		<div class="wrap lf-wrap">
			<h1><?php esc_html_e( 'Loop Fixer', 'loop-fixer' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Επαναφέρει τιμή, κουμπί καλαθιού και βαθμολογία στις κάρτες προϊόντων που ζωγραφίζει το θέμα με δικό του κώδικα. Τα αρχεία του θέματος δεν αλλάζουν ποτέ.', 'loop-fixer' ); ?></p>

			<?php self::print_notices( $notices ); ?>

			<?php
			self::render_status();
			if ( self::theme_supported() ) {
				self::render_detector();
				self::render_areas();
				self::render_text_fixes();
				self::render_probe();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	/** Classic theme (block themes draw cards with blocks — nothing to fix). */
	private static function theme_supported(): bool {
		return ! ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() );
	}

	private static function render_status(): void {
		$s     = LF_Settings::get();
		$theme = LF_Settings::theme();
		$wpt   = wp_get_theme();
		?>
		<h2 class="lf-h2"><?php esc_html_e( 'Κατάσταση', 'loop-fixer' ); ?></h2>
		<div class="lf-zone lf-status">
			<p>
				<strong><?php esc_html_e( 'Ενεργό θέμα:', 'loop-fixer' ); ?></strong>
				<?php echo esc_html( $wpt->get( 'Name' ) . ' ' . $wpt->get( 'Version' ) ); ?>
				<code><?php echo esc_html( LF_Settings::theme_key() ); ?></code>
			</p>
			<p>
				<strong><?php esc_html_e( 'Loop Fixer:', 'loop-fixer' ); ?></strong>
				<?php if ( Loop_Fixer::killed() ) : ?>
					<span class="lf-badge lf-badge--danger"><?php esc_html_e( 'Απενεργοποιημένο από το LF_DISABLE (wp-config.php)', 'loop-fixer' ); ?></span>
				<?php elseif ( empty( $s['enabled'] ) ) : ?>
					<span class="lf-badge lf-badge--muted"><?php esc_html_e( 'Ανενεργό', 'loop-fixer' ); ?></span>
				<?php elseif ( ! empty( $s['test_mode'] ) ) : ?>
					<span class="lf-badge lf-badge--warn"><?php esc_html_e( 'Λειτουργία δοκιμής — οι αλλαγές φαίνονται μόνο στους διαχειριστές του καταστήματος', 'loop-fixer' ); ?></span>
				<?php else : ?>
					<span class="lf-badge lf-badge--ok"><?php esc_html_e( 'Ενεργό για όλους τους επισκέπτες', 'loop-fixer' ); ?></span>
				<?php endif; ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_SET ) ); ?>"><?php esc_html_e( 'Αλλαγή', 'loop-fixer' ); ?></a>
			</p>

			<?php if ( ! self::theme_supported() ) : ?>
				<p class="lf-warn"><?php esc_html_e( 'Το ενεργό θέμα είναι block theme: οι κάρτες προϊόντων σχεδιάζονται από blocks του WooCommerce και δεν χρειάζονται διόρθωση. Το Loop Fixer μένει ανενεργό σε αυτό το θέμα.', 'loop-fixer' ); ?></p>
			<?php endif; ?>

			<?php foreach ( $theme['suspended'] as $id ) : ?>
				<form method="post" class="lf-suspended">
					<?php self::nonce_field(); ?>
					<input type="hidden" name="lf_action" value="confirm" />
					<input type="hidden" name="lf_area" value="<?php echo esc_attr( $id ); ?>" />
					<p class="lf-warn">
						<?php
						printf(
							/* translators: %s: area label */
							esc_html__( 'Η περιοχή «%s» ανεστάλη: τα αρχεία του θέματος άλλαξαν μετά την τελευταία ρύθμιση (π.χ. ενημέρωση θέματος). Έλεγξε τη σελίδα με τη σάρωση και επιβεβαίωσε για να ενεργοποιηθεί ξανά.', 'loop-fixer' ),
							esc_html( LF_Areas::label( $id ) )
						);
						?>
						<button type="submit" class="button"><?php esc_html_e( 'Επιβεβαίωση & επανενεργοποίηση', 'loop-fixer' ); ?></button>
					</p>
				</form>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/* ---------- Detector ---------- */

	private static function render_detector(): void {
		$rows  = LF_Detector::scan();
		$theme = LF_Settings::theme();
		$modes = self::mode_labels();
		?>
		<h2 class="lf-h2"><?php esc_html_e( '1. Ανιχνευτής θέματος', 'loop-fixer' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Αρχεία του θέματος που ζωγραφίζουν κάρτες προϊόντων: overrides προτύπων του WooCommerce και αρχεία με δικό τους WP_Query προϊόντων. Η ανάλυση διαβάζει μόνο τον κώδικα (τα σχόλια αγνοούνται).', 'loop-fixer' ); ?></p>

		<table class="widefat striped lf-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Αρχείο', 'loop-fixer' ); ?></th>
					<th><?php esc_html_e( 'Περιοχή', 'loop-fixer' ); ?></th>
					<th><?php esc_html_e( 'Ευρήματα', 'loop-fixer' ); ?></th>
					<th><?php esc_html_e( 'Πρόταση', 'loop-fixer' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="5" class="lf-empty"><?php esc_html_e( 'Δεν βρέθηκαν overrides καρτών ή βρόχοι προϊόντων στο θέμα — πιθανότατα οι κάρτες είναι ήδη οι κανονικές του WooCommerce.', 'loop-fixer' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$f          = $row['flags'];
					$configured = isset( $theme['areas'][ $row['area'] ] );
					?>
					<tr>
						<td>
							<code><?php echo esc_html( $row['file'] ); ?></code><br />
							<span class="lf-hint">
								<?php echo 'T1' === $row['kind'] ? esc_html__( 'Override προτύπου WooCommerce', 'loop-fixer' ) : esc_html__( 'Δικός του βρόχος προϊόντων', 'loop-fixer' ); ?>
							</span>
						</td>
						<td><?php echo esc_html( LF_Areas::label( $row['area'] ) ); ?></td>
						<td>
							<?php self::flag( $f['std'], __( 'Hooks κάρτας', 'loop-fixer' ) ); ?>
							<?php self::flag( $f['price'], __( 'Τιμή', 'loop-fixer' ) ); ?>
							<?php self::flag( $f['button'], __( 'Κουμπί', 'loop-fixer' ) ); ?>
							<?php if ( $f['broken_loop'] ) : ?>
								<span class="lf-badge lf-badge--danger"><?php esc_html_e( 'Σπασμένος βρόχος', 'loop-fixer' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( isset( $modes[ $row['suggest'] ] ) ? $modes[ $row['suggest'] ] : $row['suggest'] ); ?></td>
						<td>
							<?php if ( 'T2' === $row['kind'] && ! $configured ) : ?>
								<form method="post">
									<?php self::nonce_field(); ?>
									<input type="hidden" name="lf_action" value="add_area" />
									<input type="hidden" name="lf_file" value="<?php echo esc_attr( $row['file'] ); ?>" />
									<button type="submit" class="button"><?php esc_html_e( 'Προσθήκη ως περιοχή', 'loop-fixer' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" class="lf-mt-8">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="lf_action" value="rescan" />
			<button type="submit" class="button"><?php esc_html_e( 'Νέα σάρωση θέματος', 'loop-fixer' ); ?></button>
		</form>
		<?php
	}

	private static function flag( bool $on, string $label ): void {
		printf(
			'<span class="lf-badge %1$s">%2$s %3$s</span> ',
			$on ? 'lf-badge--ok' : 'lf-badge--muted',
			$on ? '✓' : '✗',
			esc_html( $label )
		);
	}

	/* ---------- Areas ---------- */

	/** Built-in areas + configured file areas of the active theme. */
	private static function area_ids(): array {
		$ids   = array_keys( LF_Areas::builtin() );
		$theme = LF_Settings::theme();
		foreach ( array_keys( $theme['areas'] ) as $id ) {
			$id = (string) $id;
			if ( LF_Areas::is_file_area( $id ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	private static function render_areas(): void {
		?>
		<h2 class="lf-h2"><?php esc_html_e( '2. Περιοχές', 'loop-fixer' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Έγχυση: το slot (τιμή / κουμπί / βαθμολογία) μπαίνει μέσα στην κάρτα του θέματος, αμέσως μετά το κλείσιμο του στοιχείου που περιέχει τον τίτλο ή την εικόνα. Αντικατάσταση: η περιοχή σχεδιάζεται με το πρότυπο του WooCommerce (κανονικές κάρτες με όλα τα hooks — διορθώνει και σπασμένους βρόχους). Οι κάρτες που είναι ήδη κανονικές δεν αγγίζονται.', 'loop-fixer' ); ?></p>

		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="lf_action" value="save_areas" />
			<?php
			foreach ( self::area_ids() as $id ) {
				self::render_area( $id );
			}
			?>
			<p class="lf-submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση περιοχών', 'loop-fixer' ); ?></button>
			</p>
		</form>

		<h3 class="lf-h3"><?php esc_html_e( 'Προσθήκη αρχείου θέματος', 'loop-fixer' ); ?></h3>
		<form method="post" class="lf-inline-form">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="lf_action" value="add_area" />
			<input type="text" name="lf_file" class="regular-text" maxlength="200" placeholder="template-parts/home-products.php" />
			<button type="submit" class="button"><?php esc_html_e( 'Προσθήκη', 'loop-fixer' ); ?></button>
		</form>
		<p class="description lf-mt-8"><?php esc_html_e( 'Διαδρομή σχετική με τον φάκελο του θέματος, για αρχεία που τρέχουν δικό τους WP_Query προϊόντων και δεν εμφανίζονται στον ανιχνευτή.', 'loop-fixer' ); ?></p>
		<?php
	}

	private static function render_area( string $id ): void {
		$cfg       = LF_Settings::area( $id );
		$name      = 'lf_areas[' . $id . ']';
		$dom       = 'lf-a-' . substr( md5( $id ), 0, 8 );
		$modes     = self::mode_labels();
		$suspended = LF_Settings::is_suspended( $id );
		?>
		<fieldset class="lf-area">
			<legend>
				<?php echo esc_html( LF_Areas::label( $id ) ); ?>
				<?php if ( $suspended ) : ?>
					<span class="lf-badge lf-badge--warn"><?php esc_html_e( 'Σε αναστολή', 'loop-fixer' ); ?></span>
				<?php endif; ?>
			</legend>

			<div class="lf-row">
				<?php foreach ( LF_Areas::modes_for( $id ) as $mode ) : ?>
					<label class="lf-inline">
						<input type="radio" name="<?php echo esc_attr( $name . '[mode]' ); ?>" value="<?php echo esc_attr( $mode ); ?>" <?php checked( $cfg['mode'], $mode ); ?> />
						<?php echo esc_html( $modes[ $mode ] ); ?>
					</label>
				<?php endforeach; ?>
			</div>

			<details class="lf-details">
				<summary><?php esc_html_e( 'Ρυθμίσεις έγχυσης', 'loop-fixer' ); ?></summary>

				<div class="lf-grid">
					<div>
						<span class="lf-label"><?php esc_html_e( 'Τι προστίθεται', 'loop-fixer' ); ?></span>
						<?php foreach ( self::part_labels() as $part => $label ) : ?>
							<label class="lf-check">
								<input type="checkbox" name="<?php echo esc_attr( $name . '[parts][]' ); ?>" value="<?php echo esc_attr( $part ); ?>" <?php checked( in_array( $part, $cfg['parts'], true ) ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</div>

					<div>
						<label class="lf-label" for="<?php echo esc_attr( $dom . '-anchor' ); ?>"><?php esc_html_e( 'Σημείο αναφοράς', 'loop-fixer' ); ?></label>
						<select id="<?php echo esc_attr( $dom . '-anchor' ); ?>" name="<?php echo esc_attr( $name . '[anchor]' ); ?>">
							<?php foreach ( self::anchor_labels() as $k => $label ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $cfg['anchor'], $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>

						<label class="lf-label" for="<?php echo esc_attr( $dom . '-tag' ); ?>"><?php esc_html_e( 'Μετά το κλείσιμο του στοιχείου', 'loop-fixer' ); ?></label>
						<select id="<?php echo esc_attr( $dom . '-tag' ); ?>" name="<?php echo esc_attr( $name . '[tag]' ); ?>">
							<?php foreach ( LF_Settings::TAGS as $tag ) : ?>
								<option value="<?php echo esc_attr( $tag ); ?>" <?php selected( $cfg['tag'], $tag ); ?>><?php echo esc_html( '</' . $tag . '>' ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="lf-hint"><?php esc_html_e( 'Το πρώτο τέτοιο κλείσιμο μετά το σημείο αναφοράς, μέσα στην ίδια κάρτα.', 'loop-fixer' ); ?></span>
					</div>

					<div>
						<span class="lf-label"><?php esc_html_e( 'Εμφάνιση', 'loop-fixer' ); ?></span>
						<?php foreach ( self::color_labels() as $k => $label ) : ?>
							<label class="lf-field">
								<span><?php echo esc_html( $label ); ?></span>
								<input type="text" class="lf-color" name="<?php echo esc_attr( $name . '[style][' . $k . ']' ); ?>" value="<?php echo esc_attr( $cfg['style'][ $k ] ); ?>" maxlength="7" placeholder="#rrggbb" />
								<?php if ( '' !== $cfg['style'][ $k ] ) : ?>
									<span class="lf-swatch" style="background:<?php echo esc_attr( $cfg['style'][ $k ] ); ?>"></span>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
						<label class="lf-field">
							<span><?php esc_html_e( 'Στοίχιση', 'loop-fixer' ); ?></span>
							<select name="<?php echo esc_attr( $name . '[style][align]' ); ?>">
								<?php foreach ( self::align_labels() as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $cfg['style']['align'], $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label class="lf-field">
							<span><?php esc_html_e( 'Απόσταση από πάνω (px)', 'loop-fixer' ); ?></span>
							<input type="number" min="0" max="999" class="small-text" name="<?php echo esc_attr( $name . '[style][gap]' ); ?>" value="<?php echo esc_attr( $cfg['style']['gap'] ); ?>" />
						</label>
					</div>
				</div>

				<label class="lf-label" for="<?php echo esc_attr( $dom . '-card' ); ?>"><?php esc_html_e( 'CSS selector της κάρτας (για το χρώμα τιμής στο hover)', 'loop-fixer' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $dom . '-card' ); ?>" class="regular-text" name="<?php echo esc_attr( $name . '[card]' ); ?>" value="<?php echo esc_attr( $cfg['card'] ); ?>" maxlength="120" placeholder=".product-content-box" />

				<label class="lf-label" for="<?php echo esc_attr( $dom . '-css' ); ?>"><?php esc_html_e( 'Πρόσθετο CSS', 'loop-fixer' ); ?></label>
				<textarea id="<?php echo esc_attr( $dom . '-css' ); ?>" class="large-text code" rows="3" name="<?php echo esc_attr( $name . '[css]' ); ?>" placeholder="<?php echo esc_attr( '.' . LF_Render::area_class( $id ) . ' .price { font-size: 18px; }' ); ?>"><?php echo esc_textarea( $cfg['css'] ); ?></textarea>
				<span class="lf-hint">
					<?php
					printf(
						/* translators: %s: CSS class of the area's slot */
						esc_html__( 'Το slot αυτής της περιοχής έχει την κλάση %s.', 'loop-fixer' ),
						'<code>.' . esc_html( LF_Render::area_class( $id ) ) . '</code>'
					);
					?>
				</span>
			</details>

			<?php if ( LF_Areas::is_file_area( $id ) ) : ?>
				<label class="lf-check lf-mt-8">
					<input type="checkbox" name="lf_remove[]" value="<?php echo esc_attr( $id ); ?>" />
					<?php esc_html_e( 'Αφαίρεση αυτής της περιοχής', 'loop-fixer' ); ?>
				</label>
			<?php endif; ?>
		</fieldset>
		<?php
	}

	/* ---------- Text fixes ---------- */

	private static function render_text_fixes(): void {
		$theme = LF_Settings::theme();
		$fixes = $theme['text_fixes'];
		$free  = max( 0, min( self::NEW_FIX_ROWS, LF_Settings::MAX_TEXT_FIXES - count( $fixes ) ) );
		for ( $i = 0; $i < $free; $i++ ) {
			$fixes[] = array(
				'find'    => '',
				'replace' => 'wc_title',
				'custom'  => '',
			);
		}
		?>
		<h2 class="lf-h2"><?php esc_html_e( '3. Διορθώσεις κειμένου στις σελίδες καταστήματος', 'loop-fixer' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Ακριβής αναζήτηση και αντικατάσταση στο HTML των σελίδων καταστήματος, κατηγοριών και αναζήτησης προϊόντων — π.χ. ένα σταθερό <h1>Shop</h1> του θέματος γίνεται ο σωστός τίτλος κάθε σελίδας. Όταν η αναζήτηση είναι ολόκληρο στοιχείο, αλλάζει μόνο το κείμενό του.', 'loop-fixer' ); ?></p>

		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="lf_action" value="save_fixes" />
			<table class="widefat striped lf-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Αναζήτηση (ακριβώς όπως στο HTML)', 'loop-fixer' ); ?></th>
						<th><?php esc_html_e( 'Αντικατάσταση με', 'loop-fixer' ); ?></th>
						<th><?php esc_html_e( 'Δικό σου κείμενο', 'loop-fixer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $fixes as $i => $fix ) : ?>
						<tr>
							<td><input type="text" class="large-text code" name="<?php echo esc_attr( 'lf_fixes[' . $i . '][find]' ); ?>" value="<?php echo esc_attr( $fix['find'] ); ?>" maxlength="<?php echo esc_attr( (string) LF_Settings::MAX_FIND_LEN ); ?>" placeholder="&lt;h1&gt;Shop&lt;/h1&gt;" /></td>
							<td>
								<select name="<?php echo esc_attr( 'lf_fixes[' . $i . '][replace]' ); ?>">
									<option value="wc_title" <?php selected( $fix['replace'], 'wc_title' ); ?>><?php esc_html_e( 'Τίτλος σελίδας του WooCommerce', 'loop-fixer' ); ?></option>
									<option value="custom" <?php selected( $fix['replace'], 'custom' ); ?>><?php esc_html_e( 'Δικό σου κείμενο', 'loop-fixer' ); ?></option>
								</select>
							</td>
							<td><input type="text" class="regular-text" name="<?php echo esc_attr( 'lf_fixes[' . $i . '][custom]' ); ?>" value="<?php echo esc_attr( $fix['custom'] ); ?>" maxlength="<?php echo esc_attr( (string) LF_Settings::MAX_CUSTOM_LEN ); ?>" /></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description lf-mt-8">
				<?php
				printf(
					/* translators: %d: maximum number of text fixes */
					esc_html__( 'Άδειασε την αναζήτηση για να διαγράψεις μια διόρθωση. Έως %d διορθώσεις ανά θέμα.', 'loop-fixer' ),
					(int) LF_Settings::MAX_TEXT_FIXES
				);
				?>
			</p>
			<p class="lf-submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση διορθώσεων', 'loop-fixer' ); ?></button>
			</p>
		</form>
		<?php
	}

	/* ---------- Probe ---------- */

	private static function render_probe(): void {
		$report  = get_transient( 'lf_probe_' . get_current_user_id() );
		$default = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		$url     = is_array( $report ) && ! empty( $report['url'] ) ? remove_query_arg( LF_Runtime::PROBE_ARG, (string) $report['url'] ) : $default;
		?>
		<h2 class="lf-h2"><?php esc_html_e( '4. Σάρωση σελίδας', 'loop-fixer' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Ανοίγει μια σελίδα του site σε νέα καρτέλα με τις τρέχουσες ρυθμίσεις εφαρμοσμένες μόνο για σένα (ακόμη κι αν το Loop Fixer είναι ανενεργό) και καταγράφει τους βρόχους προϊόντων που βρήκε. Μετά ανανέωσε αυτή τη σελίδα για να δεις τα αποτελέσματα.', 'loop-fixer' ); ?></p>

		<form method="post" target="_blank" class="lf-inline-form">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="lf_action" value="probe" />
			<input type="url" name="lf_probe_url" class="regular-text" value="<?php echo esc_attr( $url ); ?>" required />
			<button type="submit" class="button"><?php esc_html_e( 'Σάρωση σελίδας', 'loop-fixer' ); ?></button>
		</form>

		<?php if ( ! is_array( $report ) ) : ?>
			<p class="lf-hint lf-mt-8"><?php esc_html_e( 'Δεν υπάρχει ακόμη αποτέλεσμα σάρωσης.', 'loop-fixer' ); ?></p>
			<?php
			return;
		endif;

		$loops = isset( $report['loops'] ) && is_array( $report['loops'] ) ? $report['loops'] : array();
		$modes = self::mode_labels();
		?>
		<p class="lf-mt-8">
			<?php
			printf(
				/* translators: 1: page URL, 2: date and time */
				esc_html__( 'Τελευταία σάρωση: %1$s — %2$s', 'loop-fixer' ),
				'<code>' . esc_html( (string) $report['url'] ) . '</code>',
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $report['time'] ) )
			);
			?>
		</p>
		<?php if ( isset( $report['theme'] ) && LF_Settings::theme_key() !== $report['theme'] ) : ?>
			<p class="lf-warn"><?php esc_html_e( 'Η σάρωση έγινε με άλλο ενεργό θέμα.', 'loop-fixer' ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $report['failed'] ) ) : ?>
			<p class="lf-warn"><?php esc_html_e( 'Παρουσιάστηκε σφάλμα κατά τη σάρωση: το Loop Fixer σταμάτησε τις αλλαγές για εκείνη τη σελίδα και άφησε το HTML του θέματος όπως ήταν.', 'loop-fixer' ); ?></p>
		<?php endif; ?>

		<table class="widefat striped lf-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Περιοχή', 'loop-fixer' ); ?></th>
					<th><?php esc_html_e( 'Λειτουργία', 'loop-fixer' ); ?></th>
					<th><?php esc_html_e( 'Κάρτες', 'loop-fixer' ); ?></th>
					<th><?php esc_html_e( 'Κανονικές', 'loop-fixer' ); ?></th>
					<th><?php esc_html_e( 'Με slot', 'loop-fixer' ); ?></th>
					<th><?php esc_html_e( 'Χωρίς θέση', 'loop-fixer' ); ?></th>
					<th><?php esc_html_e( 'Χρόνος (ms)', 'loop-fixer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $loops ) ) : ?>
					<tr><td colspan="7" class="lf-empty"><?php esc_html_e( 'Δεν βρέθηκαν βρόχοι προϊόντων σε αυτή τη σελίδα.', 'loop-fixer' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $loops as $loop ) : ?>
					<?php
					$area = LF_Settings::valid_area_id( (string) ( isset( $loop['area'] ) ? $loop['area'] : '' ) );
					if ( '' === $area ) {
						continue;
					}
					$mode   = isset( $loop['mode'] ) ? (string) $loop['mode'] : 'off';
					$missed = isset( $loop['missed'] ) ? (int) $loop['missed'] : 0;
					?>
					<tr>
						<td>
							<?php echo esc_html( LF_Areas::label( $area ) ); ?>
							<?php if ( ! empty( $loop['file'] ) ) : ?>
								<br /><code><?php echo esc_html( (string) $loop['file'] ); ?></code>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( isset( $modes[ $mode ] ) ? $modes[ $mode ] : $mode ); ?></td>
						<td><?php echo (int) ( isset( $loop['cards'] ) ? $loop['cards'] : 0 ); ?></td>
						<td><?php echo (int) ( isset( $loop['standard'] ) ? $loop['standard'] : 0 ); ?></td>
						<td><?php echo (int) ( isset( $loop['marked'] ) ? $loop['marked'] : 0 ) - $missed; ?></td>
						<td<?php echo $missed > 0 ? ' class="lf-warn"' : ''; ?>><?php echo (int) $missed; ?></td>
						<td><?php echo esc_html( (string) ( isset( $loop['ms'] ) ? (float) $loop['ms'] : 0 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description lf-mt-8"><?php esc_html_e( '«Χωρίς θέση»: το slot δεν τοποθετήθηκε γιατί δεν βρέθηκε το κλείσιμο του στοιχείου μέσα στην κάρτα — άλλαξε το σημείο αναφοράς ή το στοιχείο. Σε περιοχή με Αντικατάσταση οι κάρτες είναι κανονικές.', 'loop-fixer' ); ?></p>
		<?php
	}

	/* =====================================================================
	 * Page: settings
	 * =================================================================== */

	public static function render_settings(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'loop-fixer' ) );
		}

		$notices = self::take_msgs();
		$s       = LF_Settings::get();
		?>
		<div class="wrap lf-wrap">
			<h1><?php esc_html_e( 'Loop Fixer — Ρυθμίσεις', 'loop-fixer' ); ?></h1>

			<?php self::print_notices( $notices ); ?>

			<form method="post">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="lf_action" value="save_settings" />

				<h2 class="lf-h2"><?php esc_html_e( 'Λειτουργία', 'loop-fixer' ); ?></h2>
				<div class="lf-zone">
					<label class="lf-check">
						<input type="checkbox" name="lf_enabled" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?> />
						<?php esc_html_e( 'Ενεργοποίηση του Loop Fixer στο front end', 'loop-fixer' ); ?>
					</label>
					<label class="lf-check">
						<input type="checkbox" name="lf_test_mode" value="1" <?php checked( ! empty( $s['test_mode'] ) ); ?> />
						<?php esc_html_e( 'Λειτουργία δοκιμής: οι αλλαγές φαίνονται μόνο σε όσους διαχειρίζονται το κατάστημα', 'loop-fixer' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή', 'loop-fixer' ); ?>
						<code>define( 'LF_DISABLE', true );</code>
					</p>
					<?php if ( Loop_Fixer::killed() ) : ?>
						<p class="lf-warn"><?php esc_html_e( 'Το LF_DISABLE είναι ενεργό: καμία αλλαγή δεν εφαρμόζεται στο front end.', 'loop-fixer' ); ?></p>
					<?php endif; ?>
				</div>

				<p class="lf-submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'loop-fixer' ); ?></button>
				</p>
			</form>

			<h2 class="lf-h2"><?php esc_html_e( 'Ρυθμίσεις ενεργού θέματος', 'loop-fixer' ); ?></h2>
			<form method="post">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="lf_action" value="reset_theme" />
				<p class="description"><?php esc_html_e( 'Οι περιοχές και οι διορθώσεις κειμένου αποθηκεύονται ξεχωριστά για κάθε θέμα. Η επαναφορά διαγράφει μόνο εκείνες του ενεργού θέματος.', 'loop-fixer' ); ?></p>
				<label class="lf-check">
					<input type="checkbox" name="lf_confirm_reset" value="1" required />
					<?php esc_html_e( 'Ναι, διάγραψε τις ρυθμίσεις του ενεργού θέματος', 'loop-fixer' ); ?>
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Επαναφορά', 'loop-fixer' ); ?></button>
			</form>

			<h2 class="lf-h2"><?php esc_html_e( 'Backup & Επαναφορά', 'loop-fixer' ); ?></h2>
			<form method="post" enctype="multipart/form-data" class="lf-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'lf_backup_nonce' ); ?>
				<input type="file" name="lf_import_file" accept=".json,application/json" />
				<button type="submit" name="lf_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'loop-fixer' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG_SET . '&lf_backup_export=1' ), self::NONCE_BAK ) ); ?>">
				<?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'loop-fixer' ); ?>
			</a>
			<p class="description lf-mt-8">
				<?php esc_html_e( 'Περιλαμβάνονται όλες οι ρυθμίσεις, για όλα τα θέματα. Η εισαγωγή ΑΝΤΙΚΑΘΙΣΤΑ τις τρέχουσες ρυθμίσεις, μετά από πλήρη έλεγχο εγκυρότητας.', 'loop-fixer' ); ?>
			</p>

			<?php self::footer(); ?>
		</div>
		<?php
	}

	/* =====================================================================
	 * POST routes (admin_init, PRG)
	 * =================================================================== */

	private static function nonce_field(): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
	}

	public static function route(): void {

		$page = self::current_page();
		if ( self::SLUG_MAIN !== $page && self::SLUG_SET !== $page && self::SLUG_MENU !== $page ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['lf_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'loop-fixer' ) );
		}

		$action = sanitize_key( wp_unslash( $_POST['lf_action'] ) );
		$back   = self::SLUG_SET === $page ? self::SLUG_SET : self::SLUG_MAIN;

		switch ( $action ) {
			case 'probe':
				self::do_probe(); // Redirects to the front end (exits).
				break;
			case 'save_settings':
				$msgs = self::do_save_settings();
				break;
			case 'reset_theme':
				$msgs = self::do_reset_theme();
				break;
			case 'rescan':
				LF_Detector::scan( true );
				$msgs = array( self::msg( 'success', __( 'Η σάρωση του θέματος ολοκληρώθηκε.', 'loop-fixer' ) ) );
				break;
			case 'add_area':
				$msgs = self::do_add_area();
				break;
			case 'save_areas':
				$msgs = self::do_save_areas();
				break;
			case 'save_fixes':
				$msgs = self::do_save_fixes();
				break;
			case 'confirm':
				$msgs = self::do_confirm();
				break;
			default:
				$msgs = array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'loop-fixer' ) ) );
		}

		self::redirect( $back, $msgs );
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $slug, array $msgs ): void {
		set_transient( 'lf_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . $slug ) );
		exit;
	}

	/** Settings save: master switch + test mode (themes untouched). */
	private static function do_save_settings(): array {
		$s              = LF_Settings::get();
		$s['enabled']   = ! empty( $_POST['lf_enabled'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$s['test_mode'] = ! empty( $_POST['lf_test_mode'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		LF_Settings::save( $s );
		return array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'loop-fixer' ) ) );
	}

	private static function do_reset_theme(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		if ( empty( $_POST['lf_confirm_reset'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιβεβαιώθηκε η επαναφορά.', 'loop-fixer' ) ) );
		}
		$s = LF_Settings::get();
		unset( $s['themes'][ LF_Settings::theme_key() ] );
		LF_Settings::save( $s );
		return array( self::msg( 'success', __( 'Οι ρυθμίσεις του ενεργού θέματος διαγράφηκαν.', 'loop-fixer' ) ) );
	}

	/** Add a file area: valid theme-relative path of an existing theme file. */
	private static function do_add_area(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in route(); validated by valid_rel_path().
		$raw  = isset( $_POST['lf_file'] ) ? (string) wp_unslash( $_POST['lf_file'] ) : '';
		$path = LF_Settings::valid_rel_path( ltrim( str_replace( '\\', '/', $raw ), '/' ) );

		if ( '' === $path || '' === LF_Areas::theme_file( $path ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν βρέθηκε στο ενεργό θέμα (διαδρομή σχετική με τον φάκελο του θέματος, π.χ. template-parts/home.php).', 'loop-fixer' ) ) );
		}

		$id    = LF_Areas::FILE_PREFIX . $path;
		$theme = LF_Settings::theme();
		if ( isset( $theme['areas'][ $id ] ) ) {
			return array( self::msg( 'warning', __( 'Η περιοχή υπάρχει ήδη.', 'loop-fixer' ) ) );
		}
		$files = 0;
		foreach ( array_keys( $theme['areas'] ) as $k ) {
			if ( LF_Areas::is_file_area( (string) $k ) ) {
				++$files;
			}
		}
		if ( $files >= LF_Settings::MAX_FILE_AREAS ) {
			return array(
				self::msg(
					'error',
					/* translators: %d: maximum number of file areas */
					sprintf( __( 'Έφτασες το όριο των %d αρχείων θέματος.', 'loop-fixer' ), (int) LF_Settings::MAX_FILE_AREAS )
				),
			);
		}

		$theme['areas'][ $id ] = LF_Settings::area_defaults();
		LF_Settings::save_theme( LF_Settings::sanitize_theme( $theme ) );
		return array( self::msg( 'success', __( 'Η περιοχή προστέθηκε — ρύθμισέ την παρακάτω (ξεκινά ανενεργή).', 'loop-fixer' ) ) );
	}

	private static function do_save_areas(): array {
		// Detect file changes made since the last save before accepting the
		// current files as the new reference.
		LF_Detector::check();

		$theme = LF_Settings::theme();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in route(); every value goes through LF_Settings::sanitize_area().
		$posted = isset( $_POST['lf_areas'] ) && is_array( $_POST['lf_areas'] ) ? wp_unslash( $_POST['lf_areas'] ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against known ids only.
		$remove = isset( $_POST['lf_remove'] ) && is_array( $_POST['lf_remove'] ) ? array_map( 'strval', wp_unslash( $_POST['lf_remove'] ) ) : array();

		$areas = array();
		foreach ( self::area_ids() as $id ) {
			if ( in_array( $id, $remove, true ) && LF_Areas::is_file_area( $id ) ) {
				continue;
			}
			$raw = isset( $posted[ $id ] ) && is_array( $posted[ $id ] ) ? $posted[ $id ] : array();
			if ( ! isset( $raw['parts'] ) ) {
				$raw['parts'] = array(); // Unchecked boxes are not posted.
			}
			$area = LF_Settings::sanitize_area( $raw, $id );
			if ( 'off' === $area['mode'] && ! LF_Areas::is_file_area( $id ) && $area == LF_Settings::area_defaults() ) { // phpcs:ignore Universal.Operators.StrictComparisons -- array value comparison.
				continue; // Untouched built-in area: keep the stored settings small.
			}
			$areas[ $id ] = $area;
		}

		$theme['areas'] = $areas;
		// A suspended area that is no longer injecting has nothing to suspend.
		$suspended = array();
		foreach ( $theme['suspended'] as $id ) {
			if ( isset( $areas[ $id ] ) && 'inject' === $areas[ $id ]['mode'] ) {
				$suspended[] = $id;
			}
		}
		$theme['suspended'] = $suspended;

		// Reference fingerprints = current files, except those of suspended
		// areas (they keep the old ones until the admin confirms).
		$old = $theme['fingerprints'];
		$fp  = LF_Detector::fingerprints( $theme );
		foreach ( $suspended as $id ) {
			foreach ( LF_Detector::files_of_area( $id ) as $rel ) {
				if ( isset( $old[ $rel ] ) ) {
					$fp[ $rel ] = $old[ $rel ];
				}
			}
		}
		$theme['fingerprints'] = $fp;

		LF_Settings::save_theme( LF_Settings::sanitize_theme( $theme ) );

		$msgs = array( self::msg( 'success', __( 'Οι περιοχές αποθηκεύτηκαν.', 'loop-fixer' ) ) );
		$s    = LF_Settings::get();
		if ( empty( $s['enabled'] ) && LF_Settings::has_active_areas() ) {
			$msgs[] = self::msg( 'warning', __( 'Το Loop Fixer είναι ακόμη ανενεργό: δοκίμασε με τη σάρωση σελίδας και ενεργοποίησέ το από τις Ρυθμίσεις.', 'loop-fixer' ) );
		}
		return $msgs;
	}

	private static function do_save_fixes(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in route(); validated by LF_Settings::sanitize_text_fix().
		$posted = isset( $_POST['lf_fixes'] ) && is_array( $_POST['lf_fixes'] ) ? wp_unslash( $_POST['lf_fixes'] ) : array();

		$fixes = array();
		foreach ( $posted as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$fix = LF_Settings::sanitize_text_fix( $raw );
			if ( null !== $fix ) {
				$fixes[] = $fix;
			}
		}

		$theme               = LF_Settings::theme();
		$theme['text_fixes'] = array_slice( $fixes, 0, LF_Settings::MAX_TEXT_FIXES );
		LF_Settings::save_theme( LF_Settings::sanitize_theme( $theme ) );
		return array( self::msg( 'success', __( 'Οι διορθώσεις κειμένου αποθηκεύτηκαν.', 'loop-fixer' ) ) );
	}

	private static function do_confirm(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in route(); validated by valid_area_id().
		$id = LF_Settings::valid_area_id( isset( $_POST['lf_area'] ) ? (string) wp_unslash( $_POST['lf_area'] ) : '' );
		if ( '' === $id || ! LF_Settings::is_suspended( $id ) ) {
			return array( self::msg( 'error', __( 'Άγνωστη περιοχή.', 'loop-fixer' ) ) );
		}
		LF_Detector::confirm( $id );
		return array( self::msg( 'success', __( 'Η περιοχή ενεργοποιήθηκε ξανά με τα τρέχοντα αρχεία του θέματος.', 'loop-fixer' ) ) );
	}

	/** Probe: same-site URL only, then redirect with a fresh probe nonce. */
	private static function do_probe(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$url  = isset( $_POST['lf_probe_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['lf_probe_url'] ) ) : '';
		$home = wp_parse_url( home_url() );
		$dest = '' !== $url ? wp_parse_url( $url ) : false;

		if ( ! is_array( $dest ) || empty( $dest['host'] ) || ! isset( $home['host'] ) || strtolower( $dest['host'] ) !== strtolower( $home['host'] ) ) {
			self::redirect( self::SLUG_MAIN, array( self::msg( 'error', __( 'Η σάρωση δέχεται μόνο σελίδες αυτού του site.', 'loop-fixer' ) ) ) );
		}

		delete_transient( 'lf_probe_' . get_current_user_id() );
		$target = add_query_arg( LF_Runtime::PROBE_ARG, wp_create_nonce( LF_Runtime::PROBE_NONCE ), remove_query_arg( LF_Runtime::PROBE_ARG, $url ) );
		wp_safe_redirect( $target );
		exit;
	}

	/* =====================================================================
	 * Backup (export GET / import POST)
	 * =================================================================== */

	public static function route_backup(): void {

		if ( self::SLUG_SET !== self::current_page() ) {
			return;
		}

		// ---------- Export (GET + nonce) ----------
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified below.
		if ( isset( $_GET['lf_backup_export'] ) ) {

			if ( ! isset( $_GET['_wpnonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'loop-fixer' ) );
			}

			$state = array(
				'plugin'  => 'loop-fixer',
				'version' => LF_VERSION,
				'options' => array(
					LF_Settings::OPT => LF_Settings::get(),
				),
			);

			nocache_headers();
			header( 'Content-Type: application/json; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="loop-fixer-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
			echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
			exit;
		}

		// ---------- Import (POST + nonce + PRG) ----------
		if ( isset( $_POST['lf_import'] ) ) {

			if ( ! isset( $_POST['lf_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['lf_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'loop-fixer' ) );
			}

			$msgs = array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'loop-fixer' ) ) );

			$up_err  = isset( $_FILES['lf_import_file']['error'] ) ? (int) $_FILES['lf_import_file']['error'] : UPLOAD_ERR_NO_FILE;
			$up_size = isset( $_FILES['lf_import_file']['size'] ) ? (int) $_FILES['lf_import_file']['size'] : 0;

			if ( UPLOAD_ERR_OK === $up_err && $up_size > 0 && $up_size <= self::MAX_IMPORT
				&& ! empty( $_FILES['lf_import_file']['tmp_name'] )
				&& is_uploaded_file( $_FILES['lf_import_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- tmp path from PHP.

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.ValidatedSanitizedInput -- uploaded tmp file.
				$raw   = (string) file_get_contents( $_FILES['lf_import_file']['tmp_name'] );
				$state = json_decode( $raw, true );

				$msgs = is_array( $state )
					? self::import_state( $state )
					: array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'loop-fixer' ) ) );
			} elseif ( $up_size > self::MAX_IMPORT ) {
				$msgs = array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'loop-fixer' ) ) );
			}

			self::redirect( self::SLUG_SET, $msgs );
		}
	}

	/**
	 * Strict import: the settings blob must be an object; every value goes
	 * through LF_Settings::sanitize_all() (the same validation as the
	 * forms). Invalid file → explicit error, current settings untouched.
	 */
	private static function import_state( array $state ): array {
		$opts = isset( $state['options'] ) && is_array( $state['options'] ) ? $state['options'] : array();
		if ( ! isset( $opts[ LF_Settings::OPT ] ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Loop Fixer).', 'loop-fixer' ) ) );
		}
		$blob = $opts[ LF_Settings::OPT ];
		if ( is_string( $blob ) ) {
			$blob = json_decode( $blob, true );
		}
		if ( ! is_array( $blob ) || ! isset( $blob['themes'] ) || ! is_array( $blob['themes'] ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο blob ρυθμίσεων.', 'loop-fixer' ) ) );
		}

		$clean = LF_Settings::sanitize_all( $blob );
		LF_Settings::save( $clean );

		$msgs = array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'loop-fixer' ) ) );
		if ( count( $clean['themes'] ) < count( $blob['themes'] ) ) {
			$msgs[] = self::msg( 'warning', __( 'Κάποια θέματα παραλείφθηκαν επειδή τα δεδομένα τους δεν ήταν έγκυρα.', 'loop-fixer' ) );
		}
		return $msgs;
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'lf_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'lf_aui_msg_' . get_current_user_id() );
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
		<p class="lf-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'loop-fixer' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="lf-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="lf-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'loop-fixer' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="lf-footer-cta">
			<a class="lf-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'loop-fixer' ); ?>
			</a>
			<a class="lf-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'loop-fixer' ); ?>
			</a>
		</p>
		<?php
	}
}

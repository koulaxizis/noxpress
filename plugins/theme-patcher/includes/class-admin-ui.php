<?php
/**
 * TP_Admin_UI — admin pages, POST routes (PRG), page probe, backup.
 *
 * Pages (Bible §8, admin_menu priority 40): submenus of the shared
 * top-level 'noxpress', created by Noxpress Core (priority 5, Bible §16).
 *  - tp-theme-patcher  status + tabs: cards (detector, areas, page probe),
 *                      texts, page rules, theme, categories, checks
 *  - tp-settings       master switch, test mode, backup & restore
 *
 * Security (Bible §11): every state change is a POST handled on
 * admin_init — page slug → nonce → capability → whitelisted action →
 * strict validation (TP_Settings, one implementation) → PRG with a
 * per-user notice transient. The privileged GET (export) is gated the
 * same way. No AJAX endpoints and no admin JavaScript: plain forms.
 *
 * Probe: a POST (opened in a new tab) is validated here and redirected
 * to the same-site URL with ?tp_probe=<nonce>; the front end records what
 * it saw in the transient tp_probe_{uid}, shown on the main page.
 *
 * Language (Bible §9): reads the rs_lang user meta only (TP_Lang).
 */

defined( 'ABSPATH' ) || exit;

final class TP_Admin_UI {

	const CAP       = 'manage_woocommerce';
	const SLUG_MENU = 'noxpress';     // Shared top-level of the Noxpress ecosystem.
	const SLUG_MAIN = 'tp-theme-patcher';
	const SLUG_SET  = 'tp-settings';

	const NONCE       = 'tp_admin';
	const NONCE_FIELD = 'tp_nonce';
	const NONCE_BAK   = 'tp_backup';

	/** Empty rows offered for new text table rows / overrides / removals. */
	const NEW_FIX_ROWS = 2;

	/** Tabs of the main page: slug => label msgid. */
	const TABS = array(
		'cards'  => 'Κάρτες προϊόντων',
		'texts'  => 'Κείμενα',
		'page'   => 'Σελίδα',
		'theme'  => 'Ρυθμίσεις θέματος',
		'cats'   => 'Κατηγορίες',
		'checks' => 'Έλεγχοι',
	);

	/** Max size of an imported backup file (bytes). */
	const MAX_IMPORT = 1048576;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 40 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_backup' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TP_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {

		// The shared top-level "Noxpress" (with the hub as landing page) is
		// created by Noxpress Core (priority 5, Bible §16): submenus only here.
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Theme Patcher', 'theme-patcher' ),
			__( 'Theme Patcher', 'theme-patcher' ),
			self::CAP,
			self::SLUG_MAIN,
			array( __CLASS__, 'render_main' )
		);

		add_submenu_page(
			self::SLUG_MENU,
			__( 'Theme Patcher — Ρυθμίσεις', 'theme-patcher' ),
			__( 'TP Ρυθμίσεις', 'theme-patcher' ),
			self::CAP,
			self::SLUG_SET,
			array( __CLASS__, 'render_settings' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG_MAIN ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'theme-patcher' ) . '</a>'
		);
		return $links;
	}

	/** Current admin page slug (sanitized) — '' outside admin.php?page=. */
	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	private static function is_main_page( string $page ): bool {
		return self::SLUG_MAIN === $page;
	}

	public static function assets(): void {
		$page = self::current_page();
		if ( ! self::is_main_page( $page ) && self::SLUG_SET !== $page ) {
			return;
		}
		wp_enqueue_style( 'tp-admin', TP_URL . 'assets/admin.css', array(), TP_VERSION );
	}

	/* =====================================================================
	 * Labels (msgids in one place)
	 * =================================================================== */

	private static function mode_labels(): array {
		return array(
			'off'     => __( 'Ανενεργό', 'theme-patcher' ),
			'inject'  => __( 'Έγχυση στην κάρτα του θέματος', 'theme-patcher' ),
			'replace' => __( 'Αντικατάσταση με το πρότυπο του WooCommerce', 'theme-patcher' ),
		);
	}

	private static function part_labels(): array {
		return array(
			'sale_badge' => __( 'Ένδειξη έκπτωσης (%)', 'theme-patcher' ),
			'rating'     => __( 'Βαθμολογία', 'theme-patcher' ),
			'price'      => __( 'Τιμή', 'theme-patcher' ),
			'button'     => __( 'Κουμπί καλαθιού', 'theme-patcher' ),
		);
	}

	private static function anchor_labels(): array {
		return array(
			'title'     => __( 'Τίτλος προϊόντος', 'theme-patcher' ),
			'thumbnail' => __( 'Εικόνα προϊόντος', 'theme-patcher' ),
		);
	}

	private static function align_labels(): array {
		return array(
			''       => __( 'Όπως το θέμα', 'theme-patcher' ),
			'left'   => __( 'Αριστερά', 'theme-patcher' ),
			'center' => __( 'Κέντρο', 'theme-patcher' ),
			'right'  => __( 'Δεξιά', 'theme-patcher' ),
		);
	}

	private static function color_labels(): array {
		return array(
			'price'       => __( 'Χρώμα τιμής', 'theme-patcher' ),
			'price_hover' => __( 'Χρώμα τιμής (hover κάρτας)', 'theme-patcher' ),
			'btn_bg'      => __( 'Φόντο κουμπιού', 'theme-patcher' ),
			'btn_text'    => __( 'Κείμενο κουμπιού', 'theme-patcher' ),
			'badge_bg'    => __( 'Φόντο ένδειξης έκπτωσης', 'theme-patcher' ),
			'badge_text'  => __( 'Κείμενο ένδειξης έκπτωσης', 'theme-patcher' ),
		);
	}

	private static function scope_labels(): array {
		return array(
			'all'     => __( 'Όλο το site', 'theme-patcher' ),
			'shop'    => __( 'Κατάστημα & κατηγορίες', 'theme-patcher' ),
			'home'    => __( 'Αρχική σελίδα', 'theme-patcher' ),
			'product' => __( 'Σελίδες προϊόντων', 'theme-patcher' ),
		);
	}

	/** Image sizes for selects: name => "name (w×h)". */
	private static function size_options(): array {
		$out = array( '' => __( 'Όπως το θέμα', 'theme-patcher' ) );
		$all = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : array();
		foreach ( $all as $name => $d ) {
			$out[ $name ] = $name . ' (' . (int) $d['width'] . '×' . (int) $d['height'] . ')';
		}
		return $out;
	}

	private static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'cards';
		return isset( self::TABS[ $tab ] ) ? $tab : 'cards';
	}

	/* =====================================================================
	 * Page: Theme Patcher (main)
	 * =================================================================== */

	public static function render_main(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'theme-patcher' ) );
		}

		$notices = self::take_msgs();
		?>
		<div class="wrap tp-wrap">
			<h1><?php esc_html_e( 'Theme Patcher', 'theme-patcher' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Διορθώνει ό,τι κάνει λάθος το θέμα στις κάρτες προϊόντων, στα κείμενα, στις κατηγορίες και στις ρυθμίσεις του. Τα αρχεία του θέματος δεν αλλάζουν ποτέ.', 'theme-patcher' ); ?></p>

			<?php self::print_notices( $notices ); ?>

			<?php
			self::render_status();
			if ( self::theme_supported() ) {
				$tab = self::current_tab();
				self::render_tabs( $tab );
				switch ( $tab ) {
					case 'texts':
						self::render_texts();
						break;
					case 'page':
						self::render_page_rules();
						break;
					case 'theme':
						self::render_theme_tab();
						break;
					case 'cats':
						self::render_cats();
						break;
					case 'checks':
						self::render_checks();
						break;
					default:
						self::render_detector();
						self::render_areas();
						self::render_probe();
				}
			}
			self::footer();
			?>
		</div>
		<?php
	}

	private static function render_tabs( string $current ): void {
		echo '<nav class="nav-tab-wrapper tp-tabs">';
		foreach ( self::TABS as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::SLUG_MAIN . '&tab=' . $slug ) ),
				$slug === $current ? ' nav-tab-active' : '',
				esc_html( __( $label, 'theme-patcher' ) ) // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- msgid from TABS.
			);
		}
		echo '</nav>';
	}

	/** Hidden field: tab to come back to after a POST. */
	private static function tab_field( string $tab ): void {
		echo '<input type="hidden" name="tp_tab" value="' . esc_attr( $tab ) . '" />';
	}

	/** Classic theme (block themes draw cards with blocks — nothing to fix). */
	private static function theme_supported(): bool {
		return ! ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() );
	}

	private static function render_status(): void {
		$s     = TP_Settings::get();
		$theme = TP_Settings::theme();
		$wpt   = wp_get_theme();
		?>
		<h2 class="tp-h2"><?php esc_html_e( 'Κατάσταση', 'theme-patcher' ); ?></h2>
		<div class="tp-zone tp-status">
			<p>
				<strong><?php esc_html_e( 'Ενεργό θέμα:', 'theme-patcher' ); ?></strong>
				<?php echo esc_html( $wpt->get( 'Name' ) . ' ' . $wpt->get( 'Version' ) ); ?>
				<code><?php echo esc_html( TP_Settings::theme_key() ); ?></code>
			</p>
			<p>
				<strong><?php esc_html_e( 'Theme Patcher:', 'theme-patcher' ); ?></strong>
				<?php if ( Theme_Patcher::killed() ) : ?>
					<span class="tp-badge tp-badge--danger"><?php esc_html_e( 'Απενεργοποιημένο από το TP_DISABLE (wp-config.php)', 'theme-patcher' ); ?></span>
				<?php elseif ( empty( $s['enabled'] ) ) : ?>
					<span class="tp-badge tp-badge--muted"><?php esc_html_e( 'Ανενεργό', 'theme-patcher' ); ?></span>
				<?php elseif ( ! empty( $s['test_mode'] ) ) : ?>
					<span class="tp-badge tp-badge--warn"><?php esc_html_e( 'Λειτουργία δοκιμής — οι αλλαγές φαίνονται μόνο στους διαχειριστές του καταστήματος', 'theme-patcher' ); ?></span>
				<?php else : ?>
					<span class="tp-badge tp-badge--ok"><?php esc_html_e( 'Ενεργό για όλους τους επισκέπτες', 'theme-patcher' ); ?></span>
				<?php endif; ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG_SET ) ); ?>"><?php esc_html_e( 'Αλλαγή', 'theme-patcher' ); ?></a>
			</p>

			<?php if ( ! self::theme_supported() ) : ?>
				<p class="tp-warn"><?php esc_html_e( 'Το ενεργό θέμα είναι block theme: οι κάρτες προϊόντων σχεδιάζονται από blocks του WooCommerce και δεν χρειάζονται διόρθωση. Το Theme Patcher μένει ανενεργό σε αυτό το θέμα.', 'theme-patcher' ); ?></p>
			<?php endif; ?>

			<?php foreach ( $theme['suspended'] as $id ) : ?>
				<form method="post" class="tp-suspended">
					<?php self::nonce_field(); ?>
					<input type="hidden" name="tp_action" value="confirm" />
					<input type="hidden" name="tp_area" value="<?php echo esc_attr( $id ); ?>" />
					<p class="tp-warn">
						<?php
						printf(
							/* translators: %s: area label */
							esc_html__( 'Η περιοχή «%s» ανεστάλη: τα αρχεία του θέματος άλλαξαν μετά την τελευταία ρύθμιση (π.χ. ενημέρωση θέματος). Έλεγξε τη σελίδα με τη σάρωση και επιβεβαίωσε για να ενεργοποιηθεί ξανά.', 'theme-patcher' ),
							esc_html( TP_Areas::label( $id ) )
						);
						?>
						<button type="submit" class="button"><?php esc_html_e( 'Επιβεβαίωση & επανενεργοποίηση', 'theme-patcher' ); ?></button>
					</p>
				</form>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/* ---------- Detector ---------- */

	private static function render_detector(): void {
		$rows  = TP_Detector::scan();
		$theme = TP_Settings::theme();
		$modes = self::mode_labels();
		?>
		<h2 class="tp-h2"><?php esc_html_e( '1. Ανιχνευτής θέματος', 'theme-patcher' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Αρχεία του θέματος που ζωγραφίζουν κάρτες προϊόντων: overrides προτύπων του WooCommerce και αρχεία με δικό τους WP_Query προϊόντων. Η ανάλυση διαβάζει μόνο τον κώδικα (τα σχόλια αγνοούνται).', 'theme-patcher' ); ?></p>

		<table class="widefat striped tp-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Αρχείο', 'theme-patcher' ); ?></th>
					<th><?php esc_html_e( 'Περιοχή', 'theme-patcher' ); ?></th>
					<th><?php esc_html_e( 'Ευρήματα', 'theme-patcher' ); ?></th>
					<th><?php esc_html_e( 'Πρόταση', 'theme-patcher' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="5" class="tp-empty"><?php esc_html_e( 'Δεν βρέθηκαν overrides καρτών ή βρόχοι προϊόντων στο θέμα — πιθανότατα οι κάρτες είναι ήδη οι κανονικές του WooCommerce.', 'theme-patcher' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$f          = $row['flags'];
					$configured = isset( $theme['areas'][ $row['area'] ] );
					?>
					<tr>
						<td>
							<code><?php echo esc_html( $row['file'] ); ?></code><br />
							<span class="tp-hint">
								<?php echo 'T1' === $row['kind'] ? esc_html__( 'Override προτύπου WooCommerce', 'theme-patcher' ) : esc_html__( 'Δικός του βρόχος προϊόντων', 'theme-patcher' ); ?>
							</span>
						</td>
						<td><?php echo esc_html( TP_Areas::label( $row['area'] ) ); ?></td>
						<td>
							<?php self::flag( $f['std'], __( 'Hooks κάρτας', 'theme-patcher' ) ); ?>
							<?php self::flag( $f['price'], __( 'Τιμή', 'theme-patcher' ) ); ?>
							<?php self::flag( $f['button'], __( 'Κουμπί', 'theme-patcher' ) ); ?>
							<?php if ( $f['broken_loop'] ) : ?>
								<span class="tp-badge tp-badge--danger"><?php esc_html_e( 'Σπασμένος βρόχος', 'theme-patcher' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( isset( $modes[ $row['suggest'] ] ) ? $modes[ $row['suggest'] ] : $row['suggest'] ); ?></td>
						<td>
							<?php if ( 'T2' === $row['kind'] && ! $configured ) : ?>
								<form method="post">
									<?php self::nonce_field(); ?>
									<input type="hidden" name="tp_action" value="add_area" />
									<input type="hidden" name="tp_file" value="<?php echo esc_attr( $row['file'] ); ?>" />
									<button type="submit" class="button"><?php esc_html_e( 'Προσθήκη ως περιοχή', 'theme-patcher' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" class="tp-mt-8">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="tp_action" value="rescan" />
			<button type="submit" class="button"><?php esc_html_e( 'Νέα σάρωση θέματος', 'theme-patcher' ); ?></button>
		</form>
		<?php
	}

	private static function flag( bool $on, string $label ): void {
		printf(
			'<span class="tp-badge %1$s">%2$s %3$s</span> ',
			$on ? 'tp-badge--ok' : 'tp-badge--muted',
			$on ? '✓' : '✗',
			esc_html( $label )
		);
	}

	/* ---------- Areas ---------- */

	/** Built-in areas + configured file areas of the active theme. */
	private static function area_ids(): array {
		$ids   = array_keys( TP_Areas::builtin() );
		$theme = TP_Settings::theme();
		foreach ( array_keys( $theme['areas'] ) as $id ) {
			$id = (string) $id;
			if ( TP_Areas::is_file_area( $id ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	private static function render_areas(): void {
		?>
		<h2 class="tp-h2"><?php esc_html_e( '2. Περιοχές', 'theme-patcher' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Έγχυση: το slot (έκπτωση / βαθμολογία / τιμή / κουμπί) μπαίνει μέσα στην κάρτα του θέματος, αμέσως μετά το κλείσιμο του στοιχείου που περιέχει τον τίτλο ή την εικόνα. Αντικατάσταση: η περιοχή σχεδιάζεται με το πρότυπο του WooCommerce (κανονικές κάρτες με όλα τα hooks — διορθώνει και σπασμένους βρόχους). Οι κάρτες που είναι ήδη κανονικές δεν αγγίζονται.', 'theme-patcher' ); ?></p>

		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="tp_action" value="save_areas" />
			<?php self::tab_field( 'cards' ); ?>
			<?php
			foreach ( self::area_ids() as $id ) {
				self::render_area( $id );
			}
			?>
			<p class="tp-submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση περιοχών', 'theme-patcher' ); ?></button>
			</p>
		</form>

		<h3 class="tp-h3"><?php esc_html_e( 'Προσθήκη αρχείου θέματος', 'theme-patcher' ); ?></h3>
		<form method="post" class="tp-inline-form">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="tp_action" value="add_area" />
			<input type="text" name="tp_file" class="regular-text" maxlength="200" placeholder="template-parts/home-products.php" />
			<button type="submit" class="button"><?php esc_html_e( 'Προσθήκη', 'theme-patcher' ); ?></button>
		</form>
		<p class="description tp-mt-8"><?php esc_html_e( 'Διαδρομή σχετική με τον φάκελο του θέματος, για αρχεία που τρέχουν δικό τους WP_Query προϊόντων και δεν εμφανίζονται στον ανιχνευτή.', 'theme-patcher' ); ?></p>
		<?php
	}

	private static function render_area( string $id ): void {
		$cfg       = TP_Settings::area( $id );
		$name      = 'tp_areas[' . $id . ']';
		$dom       = 'tp-a-' . substr( md5( $id ), 0, 8 );
		$modes     = self::mode_labels();
		$suspended = TP_Settings::is_suspended( $id );
		?>
		<fieldset class="tp-area">
			<legend>
				<?php echo esc_html( TP_Areas::label( $id ) ); ?>
				<?php if ( $suspended ) : ?>
					<span class="tp-badge tp-badge--warn"><?php esc_html_e( 'Σε αναστολή', 'theme-patcher' ); ?></span>
				<?php endif; ?>
			</legend>

			<div class="tp-row">
				<?php foreach ( TP_Areas::modes_for( $id ) as $mode ) : ?>
					<label class="tp-inline">
						<input type="radio" name="<?php echo esc_attr( $name . '[mode]' ); ?>" value="<?php echo esc_attr( $mode ); ?>" <?php checked( $cfg['mode'], $mode ); ?> />
						<?php echo esc_html( $modes[ $mode ] ); ?>
					</label>
				<?php endforeach; ?>
			</div>

			<details class="tp-details">
				<summary><?php esc_html_e( 'Ρυθμίσεις έγχυσης', 'theme-patcher' ); ?></summary>

				<div class="tp-grid">
					<div>
						<span class="tp-label"><?php esc_html_e( 'Τι προστίθεται', 'theme-patcher' ); ?></span>
						<?php foreach ( self::part_labels() as $part => $label ) : ?>
							<label class="tp-check">
								<input type="checkbox" name="<?php echo esc_attr( $name . '[parts][]' ); ?>" value="<?php echo esc_attr( $part ); ?>" <?php checked( in_array( $part, $cfg['parts'], true ) ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
						<label class="tp-check">
							<input type="checkbox" name="<?php echo esc_attr( $name . '[hooks]' ); ?>" value="1" <?php checked( $cfg['hooks'] ); ?> />
							<?php esc_html_e( 'Hooks κάρτας για άλλα plugins', 'theme-patcher' ); ?>
						</label>
						<span class="tp-hint"><?php esc_html_e( 'Τυπώνει ό,τι προσθέτουν άλλα plugins στην κάρτα (π.χ. δείγματα χρώματος, λίστα επιθυμιών). Τιμή, βαθμολογία και κουμπί του WooCommerce δεν διπλασιάζονται.', 'theme-patcher' ); ?></span>

						<label class="tp-label" for="<?php echo esc_attr( $dom . '-thumb' ); ?>"><?php esc_html_e( 'Μέγεθος εικόνας κάρτας', 'theme-patcher' ); ?></label>
						<select id="<?php echo esc_attr( $dom . '-thumb' ); ?>" name="<?php echo esc_attr( $name . '[thumb]' ); ?>">
							<?php foreach ( self::size_options() as $k => $label ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $cfg['thumb'], $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="tp-hint"><?php esc_html_e( 'Συνήθως woocommerce_thumbnail. Λειτουργεί σε περιοχές με Έγχυση.', 'theme-patcher' ); ?></span>
					</div>

					<div>
						<label class="tp-label" for="<?php echo esc_attr( $dom . '-anchor' ); ?>"><?php esc_html_e( 'Σημείο αναφοράς', 'theme-patcher' ); ?></label>
						<select id="<?php echo esc_attr( $dom . '-anchor' ); ?>" name="<?php echo esc_attr( $name . '[anchor]' ); ?>">
							<?php foreach ( self::anchor_labels() as $k => $label ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $cfg['anchor'], $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>

						<label class="tp-label" for="<?php echo esc_attr( $dom . '-tag' ); ?>"><?php esc_html_e( 'Μετά το κλείσιμο του στοιχείου', 'theme-patcher' ); ?></label>
						<select id="<?php echo esc_attr( $dom . '-tag' ); ?>" name="<?php echo esc_attr( $name . '[tag]' ); ?>">
							<?php foreach ( TP_Settings::TAGS as $tag ) : ?>
								<option value="<?php echo esc_attr( $tag ); ?>" <?php selected( $cfg['tag'], $tag ); ?>><?php echo esc_html( '</' . $tag . '>' ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="tp-hint"><?php esc_html_e( 'Το πρώτο τέτοιο κλείσιμο μετά το σημείο αναφοράς, μέσα στην ίδια κάρτα.', 'theme-patcher' ); ?></span>
					</div>

					<div>
						<span class="tp-label"><?php esc_html_e( 'Εμφάνιση', 'theme-patcher' ); ?></span>
						<?php foreach ( self::color_labels() as $k => $label ) : ?>
							<label class="tp-field">
								<span><?php echo esc_html( $label ); ?></span>
								<input type="text" class="tp-color" name="<?php echo esc_attr( $name . '[style][' . $k . ']' ); ?>" value="<?php echo esc_attr( $cfg['style'][ $k ] ); ?>" maxlength="7" placeholder="#rrggbb" />
								<?php if ( '' !== $cfg['style'][ $k ] ) : ?>
									<span class="tp-swatch" style="background:<?php echo esc_attr( $cfg['style'][ $k ] ); ?>"></span>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
						<label class="tp-field">
							<span><?php esc_html_e( 'Στοίχιση', 'theme-patcher' ); ?></span>
							<select name="<?php echo esc_attr( $name . '[style][align]' ); ?>">
								<?php foreach ( self::align_labels() as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $cfg['style']['align'], $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label class="tp-field">
							<span><?php esc_html_e( 'Απόσταση από πάνω (px)', 'theme-patcher' ); ?></span>
							<input type="number" min="0" max="999" class="small-text" name="<?php echo esc_attr( $name . '[style][gap]' ); ?>" value="<?php echo esc_attr( $cfg['style']['gap'] ); ?>" />
						</label>
					</div>
				</div>

				<label class="tp-label" for="<?php echo esc_attr( $dom . '-card' ); ?>"><?php esc_html_e( 'CSS selector της κάρτας (για τη θέση της έκπτωσης, το hover και τον τίτλο)', 'theme-patcher' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $dom . '-card' ); ?>" class="regular-text" name="<?php echo esc_attr( $name . '[card]' ); ?>" value="<?php echo esc_attr( $cfg['card'] ); ?>" maxlength="120" placeholder=".product-content-box" />

				<label class="tp-label" for="<?php echo esc_attr( $dom . '-badge' ); ?>"><?php esc_html_e( 'Selector της ετικέτας έκπτωσης του θέματος', 'theme-patcher' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $dom . '-badge' ); ?>" class="regular-text" name="<?php echo esc_attr( $name . '[badge_hide]' ); ?>" value="<?php echo esc_attr( $cfg['badge_hide'] ); ?>" maxlength="120" placeholder=".product-sale-tag" />
				<span class="tp-hint"><?php esc_html_e( 'Κρύβεται όταν είναι ενεργή η «Ένδειξη έκπτωσης», για να μη φαίνονται δύο.', 'theme-patcher' ); ?></span>

				<div class="tp-grid">
					<div>
						<label class="tp-label" for="<?php echo esc_attr( $dom . '-tsel' ); ?>"><?php esc_html_e( 'Selector τίτλου', 'theme-patcher' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $dom . '-tsel' ); ?>" class="regular-text" name="<?php echo esc_attr( $name . '[title][sel]' ); ?>" value="<?php echo esc_attr( $cfg['title']['sel'] ); ?>" maxlength="120" placeholder="h3" />
					</div>
					<div>
						<label class="tp-label" for="<?php echo esc_attr( $dom . '-tlines' ); ?>"><?php esc_html_e( 'Μέγιστες γραμμές τίτλου', 'theme-patcher' ); ?></label>
						<input type="number" id="<?php echo esc_attr( $dom . '-tlines' ); ?>" min="1" max="5" class="small-text" name="<?php echo esc_attr( $name . '[title][lines]' ); ?>" value="<?php echo esc_attr( $cfg['title']['lines'] ); ?>" />
					</div>
					<div>
						<label class="tp-label" for="<?php echo esc_attr( $dom . '-tsize' ); ?>"><?php esc_html_e( 'Μέγεθος τίτλου (px)', 'theme-patcher' ); ?></label>
						<input type="number" id="<?php echo esc_attr( $dom . '-tsize' ); ?>" min="8" max="99" class="small-text" name="<?php echo esc_attr( $name . '[title][size]' ); ?>" value="<?php echo esc_attr( $cfg['title']['size'] ); ?>" />
					</div>
				</div>
				<span class="tp-hint"><?php esc_html_e( 'Ο τίτλος μετριέται μέσα στην κάρτα (selector κάρτας + selector τίτλου). Χωρίς selector κάρτας ισχύει σε όλη τη σελίδα.', 'theme-patcher' ); ?></span>

				<label class="tp-label" for="<?php echo esc_attr( $dom . '-css' ); ?>"><?php esc_html_e( 'Πρόσθετο CSS', 'theme-patcher' ); ?></label>
				<textarea id="<?php echo esc_attr( $dom . '-css' ); ?>" class="large-text code" rows="3" name="<?php echo esc_attr( $name . '[css]' ); ?>" placeholder="<?php echo esc_attr( '.' . TP_Render::area_class( $id ) . ' .price { font-size: 18px; }' ); ?>"><?php echo esc_textarea( $cfg['css'] ); ?></textarea>
				<span class="tp-hint">
					<?php
					printf(
						/* translators: %s: CSS class of the area's slot */
						esc_html__( 'Το slot αυτής της περιοχής έχει την κλάση %s.', 'theme-patcher' ),
						'<code>.' . esc_html( TP_Render::area_class( $id ) ) . '</code>'
					);
					?>
				</span>
			</details>

			<?php if ( TP_Areas::is_file_area( $id ) ) : ?>
				<label class="tp-check tp-mt-8">
					<input type="checkbox" name="tp_remove[]" value="<?php echo esc_attr( $id ); ?>" />
					<?php esc_html_e( 'Αφαίρεση αυτής της περιοχής', 'theme-patcher' ); ?>
				</label>
			<?php endif; ?>
		</fieldset>
		<?php
	}

	/* ---------- Tab: texts ---------- */

	private static function render_texts(): void {
		$theme  = TP_Settings::theme();
		$texts  = $theme['texts'];
		$scopes = self::scope_labels();
		$free   = max( 0, min( self::NEW_FIX_ROWS, TP_Settings::MAX_TEXTS - count( $texts ) ) );
		for ( $i = 0; $i < $free; $i++ ) {
			$texts[] = array(
				'find'    => '',
				'replace' => 'custom',
				'custom'  => '',
				'scope'   => 'all',
			);
		}
		?>
		<h2 class="tp-h2"><?php esc_html_e( 'Κείμενα του θέματος', 'theme-patcher' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Πίνακας «κείμενο του θέματος → δικό μου κείμενο». Η αναζήτηση γίνεται ακριβώς όπως είναι στο HTML της σελίδας (και σε κρυφά κείμενα). Όταν η αναζήτηση είναι ολόκληρο στοιχείο, π.χ. <h1>Shop</h1>, αλλάζει μόνο το κείμενό του. Το δικό σου κείμενο δέχεται απλό HTML (συνδέσμους, έντονα). Άδειο δικό σου κείμενο σβήνει το κείμενο του θέματος.', 'theme-patcher' ); ?></p>

		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="tp_action" value="save_texts" />
			<?php self::tab_field( 'texts' ); ?>
			<table class="widefat striped tp-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Κείμενο του θέματος (ακριβώς όπως στο HTML)', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'Αντικατάσταση με', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'Δικό σου κείμενο', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'Πού', 'theme-patcher' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $texts as $i => $fix ) : ?>
						<?php $n = 'tp_texts[' . $i . ']'; ?>
						<tr>
							<td><textarea class="large-text code" rows="2" name="<?php echo esc_attr( $n . '[find]' ); ?>" maxlength="<?php echo esc_attr( (string) TP_Settings::MAX_FIND_LEN ); ?>" placeholder="Shop Now"><?php echo esc_textarea( $fix['find'] ); ?></textarea></td>
							<td>
								<select name="<?php echo esc_attr( $n . '[replace]' ); ?>">
									<option value="custom" <?php selected( $fix['replace'], 'custom' ); ?>><?php esc_html_e( 'Δικό σου κείμενο', 'theme-patcher' ); ?></option>
									<option value="wc_title" <?php selected( $fix['replace'], 'wc_title' ); ?>><?php esc_html_e( 'Τίτλος σελίδας του WooCommerce', 'theme-patcher' ); ?></option>
								</select>
							</td>
							<td><textarea class="large-text" rows="2" name="<?php echo esc_attr( $n . '[custom]' ); ?>" maxlength="<?php echo esc_attr( (string) TP_Settings::MAX_CUSTOM_LEN ); ?>"><?php echo esc_textarea( $fix['custom'] ); ?></textarea></td>
							<td>
								<select name="<?php echo esc_attr( $n . '[scope]' ); ?>">
									<?php foreach ( $scopes as $k => $label ) : ?>
										<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $fix['scope'], $k ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description tp-mt-8">
				<?php
				printf(
					/* translators: %d: maximum number of rows */
					esc_html__( 'Άδειασε το κείμενο του θέματος για να διαγράψεις μια γραμμή. Έως %d γραμμές ανά θέμα. Μετά την αποθήκευση εμφανίζονται δύο νέες κενές γραμμές.', 'theme-patcher' ),
					(int) TP_Settings::MAX_TEXTS
				);
				?>
			</p>
			<p class="tp-submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση κειμένων', 'theme-patcher' ); ?></button>
			</p>
		</form>
		<?php
	}

	/* ---------- Tab: page rules ---------- */

	private static function render_page_rules(): void {
		$p      = TP_Settings::theme()['page'];
		$remove = $p['remove'];
		for ( $i = count( $remove ); $i < TP_Settings::MAX_REMOVE && $i < count( $p['remove'] ) + self::NEW_FIX_ROWS; $i++ ) {
			$remove[] = '';
		}
		?>
		<h2 class="tp-h2"><?php esc_html_e( 'Κανόνες σελίδας', 'theme-patcher' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Εφαρμόζονται στο τελικό HTML κάθε σελίδας του front end.', 'theme-patcher' ); ?></p>

		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="tp_action" value="save_page" />
			<?php self::tab_field( 'page' ); ?>

			<div class="tp-zone">
				<label class="tp-check">
					<input type="checkbox" name="tp_page[same_tab]" value="1" <?php checked( $p['same_tab'] ); ?> />
					<?php esc_html_e( 'Οι σύνδεσμοι προς σελίδες αυτού του site ανοίγουν στην ίδια καρτέλα', 'theme-patcher' ); ?>
				</label>
				<span class="tp-hint"><?php esc_html_e( 'Αφαιρεί το target="_blank" μόνο από εσωτερικούς συνδέσμους. Οι εξωτερικοί (π.χ. social) μένουν όπως είναι.', 'theme-patcher' ); ?></span>

				<label class="tp-check">
					<input type="checkbox" name="tp_page[img_alt]" value="1" <?php checked( $p['img_alt'] ); ?> />
					<?php esc_html_e( 'Περιγραφή (alt) στις εικόνες που δεν έχουν', 'theme-patcher' ); ?>
				</label>
				<span class="tp-hint"><?php esc_html_e( 'Εικόνες κατηγοριών: το όνομα της κατηγορίας. Άλλες εικόνες της βιβλιοθήκης: το alt ή ο τίτλος τους.', 'theme-patcher' ); ?></span>

				<label class="tp-check">
					<input type="checkbox" name="tp_page[aria]" value="1" <?php checked( $p['aria'] ); ?> />
					<?php esc_html_e( 'Όνομα για αναγνώστες οθόνης σε συνδέσμους και κουμπιά που έχουν μόνο εικονίδιο', 'theme-patcher' ); ?>
				</label>
				<span class="tp-hint"><?php esc_html_e( 'Το όνομα βγαίνει από το εικονίδιο ή τη διεύθυνση: Facebook, Instagram, καλάθι, αναζήτηση, μενού, επόμενο / προηγούμενο, επιστροφή στην αρχή κ.ά.', 'theme-patcher' ); ?></span>

				<label class="tp-label" for="tp-cat-img-size"><?php esc_html_e( 'Μέγεθος εικόνων κατηγοριών', 'theme-patcher' ); ?></label>
				<select id="tp-cat-img-size" name="tp_page[cat_img_size]">
					<?php foreach ( self::size_options() as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $p['cat_img_size'], $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="tp-hint"><?php esc_html_e( 'Για θέματα που τυπώνουν την εικόνα κατηγορίας σε πλήρες μέγεθος (π.χ. πλακίδια αρχικής).', 'theme-patcher' ); ?></span>
			</div>

			<h3 class="tp-h3"><?php esc_html_e( 'Αφαίρεση στοιχείων', 'theme-patcher' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Μορφή: span.credit_link, .κλάση, #id ή div.κλάση. Πρόσθεσε :empty για να αφαιρείται μόνο όταν είναι άδειο (π.χ. span.product-sale-tag:empty).', 'theme-patcher' ); ?></p>
			<?php foreach ( $remove as $sel ) : ?>
				<input type="text" class="regular-text code tp-block" name="tp_page[remove][]" value="<?php echo esc_attr( $sel ); ?>" maxlength="80" placeholder="span.credit_link" />
			<?php endforeach; ?>

			<h3 class="tp-h3"><?php esc_html_e( 'CSS σελίδας', 'theme-patcher' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Τυπώνεται στο <head> όλων των σελίδων. Για το λευκό φόντο φωτογραφιών πάνω σε χρωματιστό πλακίδιο: .tile img { mix-blend-mode: multiply; }', 'theme-patcher' ); ?></p>
			<textarea class="large-text code" rows="6" name="tp_page[css]"><?php echo esc_textarea( $p['css'] ); ?></textarea>

			<p class="tp-submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση κανόνων', 'theme-patcher' ); ?></button>
			</p>
		</form>
		<?php
	}

	/* ---------- Tab: theme settings ---------- */

	private static function render_theme_tab(): void {
		$theme = TP_Settings::theme();
		$scan  = TP_Theme::scan();
		$mods  = array();
		foreach ( $theme['mods'] as $k => $v ) {
			$mods[] = array( (string) $k, (string) $v );
		}
		$free = max( 0, min( self::NEW_FIX_ROWS, TP_Settings::MAX_MODS - count( $mods ) ) );
		for ( $i = 0; $i < $free; $i++ ) {
			$mods[] = array( '', '' );
		}
		$stored = get_theme_mods();
		$stored = is_array( $stored ) ? $stored : array();
		?>
		<h2 class="tp-h2"><?php esc_html_e( 'Ρυθμίσεις θέματος', 'theme-patcher' ); ?></h2>

		<?php if ( $scan ) : ?>
			<table class="widefat striped tp-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Αρχείο θέματος', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'Γράφει ρυθμίσεις του θέματος', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'Διαβάζει αρχεία μέσω HTTP', 'theme-patcher' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $scan as $rel => $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( $rel ); ?></code></td>
							<td>
								<?php foreach ( $row['writes'] as $mod ) : ?>
									<code><?php echo esc_html( $mod ); ?></code><br />
								<?php endforeach; ?>
							</td>
							<td><?php echo $row['http'] ? (int) $row['http'] : ''; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description tp-mt-8"><?php esc_html_e( 'Ό,τι γράφεται μέσα σε πρότυπο γράφεται σε κάθε προβολή σελίδας και πατάει την επιλογή του Customizer.', 'theme-patcher' ); ?></p>
		<?php else : ?>
			<p class="tp-hint"><?php esc_html_e( 'Δεν βρέθηκαν αρχεία του θέματος που γράφουν ρυθμίσεις ή διαβάζουν αρχεία μέσω HTTP.', 'theme-patcher' ); ?></p>
		<?php endif; ?>

		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="tp_action" value="save_theme" />
			<?php self::tab_field( 'theme' ); ?>

			<div class="tp-zone tp-mt-8">
				<label class="tp-check">
					<input type="checkbox" name="tp_guard" value="1" <?php checked( $theme['guard'] ); ?> />
					<?php esc_html_e( 'Προστασία ρυθμίσεων: οι προβολές σελίδων δεν μπορούν να γράψουν ρυθμίσεις του θέματος', 'theme-patcher' ); ?>
				</label>
				<span class="tp-hint"><?php esc_html_e( 'Καμία εγγραφή στη βάση από επισκέψεις. Ό,τι αποθηκεύεις στον Customizer μένει όπως το αποθήκευσες. Ο Customizer και το admin δεν επηρεάζονται.', 'theme-patcher' ); ?></span>

				<label class="tp-check">
					<input type="checkbox" name="tp_local_files" value="1" <?php checked( $theme['local_files'] ); ?> />
					<?php esc_html_e( 'Ανάγνωση αρχείων του θέματος από τον δίσκο αντί για HTTP', 'theme-patcher' ); ?>
				</label>
				<span class="tp-hint"><?php esc_html_e( 'Μόνο στις γραμμές του θέματος που διαβάζουν δικό του αρχείο (π.χ. SVG) μέσω της διεύθυνσης του site. Το site δεν καλεί πια τον εαυτό του.', 'theme-patcher' ); ?></span>
			</div>

			<h3 class="tp-h3"><?php esc_html_e( 'Σταθερές τιμές ρυθμίσεων', 'theme-patcher' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Η ρύθμιση παίρνει αυτή την τιμή στο front end, ό,τι κι αν γράψει ή διαβάσει το θέμα (π.χ. καθυστέρηση slider σε χιλιοστά του δευτερολέπτου). Η αποθηκευμένη τιμή δεν αλλάζει. Κενό όνομα διαγράφει τη γραμμή.', 'theme-patcher' ); ?></p>
			<datalist id="tp-mod-names">
				<?php foreach ( $stored as $k => $v ) : ?>
					<?php if ( is_scalar( $v ) ) : ?>
						<option value="<?php echo esc_attr( (string) $k ); ?>"><?php echo esc_html( mb_substr( (string) $v, 0, 60 ) ); ?></option>
					<?php endif; ?>
				<?php endforeach; ?>
			</datalist>
			<table class="widefat striped tp-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Ρύθμιση του θέματος', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'Τιμή', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'Αποθηκευμένη τιμή', 'theme-patcher' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $mods as $i => $mod ) : ?>
						<tr>
							<td><input type="text" class="regular-text code" list="tp-mod-names" name="<?php echo esc_attr( 'tp_mods[' . $i . '][name]' ); ?>" value="<?php echo esc_attr( $mod[0] ); ?>" maxlength="100" /></td>
							<td><input type="text" class="regular-text" name="<?php echo esc_attr( 'tp_mods[' . $i . '][value]' ); ?>" value="<?php echo esc_attr( $mod[1] ); ?>" maxlength="<?php echo esc_attr( (string) TP_Settings::MAX_MOD_LEN ); ?>" /></td>
							<td><?php echo '' !== $mod[0] && isset( $stored[ $mod[0] ] ) && is_scalar( $stored[ $mod[0] ] ) ? '<code>' . esc_html( mb_substr( (string) $stored[ $mod[0] ], 0, 80 ) ) . '</code>' : ''; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="tp-submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'theme-patcher' ); ?></button>
			</p>
		</form>
		<?php
	}

	/* ---------- Tab: categories ---------- */

	private static function render_cats(): void {
		$cats    = TP_Settings::theme()['cats'];
		$summary = TP_Categories::summary();
		$files   = TP_Categories::list_files();
		$terms   = get_terms(
			array(
				'taxonomy'   => TP_Categories::TAX,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$terms   = is_array( $terms ) ? $terms : array();
		$lists   = $cats['lists'];
		if ( count( $lists ) < TP_Settings::MAX_CAT_LISTS ) {
			$lists[] = array(
				'file'       => '',
				'include'    => array(),
				'hide_empty' => false,
			);
		}
		?>
		<h2 class="tp-h2"><?php esc_html_e( 'Εικόνες κατηγοριών', 'theme-patcher' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: categories, 2: with own image, 3: with image from a product */
				esc_html__( '%1$d κατηγορίες: %2$d με δική τους εικόνα, %3$d με εικόνα από προϊόν.', 'theme-patcher' ),
				(int) $summary['total'],
				(int) $summary['own'],
				(int) $summary['fallback']
			);
			if ( $summary['none'] ) {
				echo ' ';
				printf(
					/* translators: %s: category names */
					esc_html__( 'Χωρίς εικόνα: %s', 'theme-patcher' ),
					esc_html( implode( ', ', array_slice( $summary['none'], 0, 20 ) ) . ( count( $summary['none'] ) > 20 ? '…' : '' ) )
				);
			}
			?>
		</p>

		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="tp_action" value="save_cats" />
			<?php self::tab_field( 'cats' ); ?>
			<div class="tp-zone">
				<label class="tp-check">
					<input type="checkbox" name="tp_cats[fallback]" value="1" <?php checked( $cats['fallback'] ); ?> />
					<?php esc_html_e( 'Οι κατηγορίες χωρίς εικόνα δείχνουν την εικόνα ενός προϊόντος τους', 'theme-patcher' ); ?>
				</label>
				<span class="tp-hint"><?php esc_html_e( 'Οι κατηγορίες με δική τους εικόνα δεν αλλάζουν. Η επιλογή ενημερώνεται όταν αποθηκεύεται προϊόν ή κατηγορία, ποτέ σε προβολή σελίδας.', 'theme-patcher' ); ?></span>
				<label class="tp-label" for="tp-cat-source"><?php esc_html_e( 'Ποιο προϊόν', 'theme-patcher' ); ?></label>
				<select id="tp-cat-source" name="tp_cats[source]">
					<option value="recent" <?php selected( $cats['source'], 'recent' ); ?>><?php esc_html_e( 'Το πιο πρόσφατο', 'theme-patcher' ); ?></option>
					<option value="popular" <?php selected( $cats['source'], 'popular' ); ?>><?php esc_html_e( 'Το πιο δημοφιλές (πωλήσεις)', 'theme-patcher' ); ?></option>
				</select>
			</div>

			<h2 class="tp-h2"><?php esc_html_e( 'Λίστες κατηγοριών του θέματος', 'theme-patcher' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Για αρχεία του θέματος που τυπώνουν κατηγορίες (π.χ. πλακίδια αρχικής): ποιες κατηγορίες εμφανίζονται και με ποια σειρά. Χωρίς επιλεγμένες κατηγορίες, το θέμα κρατά τη δική του λίστα.', 'theme-patcher' ); ?></p>

			<?php foreach ( $lists as $li => $list ) : ?>
				<?php
				$n     = 'tp_cats[lists][' . $li . ']';
				$order = array_flip( $list['include'] );
				?>
				<fieldset class="tp-area">
					<legend><?php echo '' !== $list['file'] ? esc_html( $list['file'] ) : esc_html__( 'Νέα λίστα', 'theme-patcher' ); ?></legend>
					<label class="tp-label" for="<?php echo esc_attr( 'tp-list-file-' . $li ); ?>"><?php esc_html_e( 'Αρχείο θέματος', 'theme-patcher' ); ?></label>
					<select id="<?php echo esc_attr( 'tp-list-file-' . $li ); ?>" name="<?php echo esc_attr( $n . '[file]' ); ?>">
						<option value=""><?php esc_html_e( '— Καμία —', 'theme-patcher' ); ?></option>
						<?php foreach ( array_unique( array_merge( $files, '' !== $list['file'] ? array( $list['file'] ) : array() ) ) as $f ) : ?>
							<option value="<?php echo esc_attr( $f ); ?>" <?php selected( $list['file'], $f ); ?>><?php echo esc_html( $f ); ?></option>
						<?php endforeach; ?>
					</select>
					<label class="tp-check">
						<input type="checkbox" name="<?php echo esc_attr( $n . '[hide_empty]' ); ?>" value="1" <?php checked( $list['hide_empty'] ); ?> />
						<?php esc_html_e( 'Χωρίς άδειες κατηγορίες', 'theme-patcher' ); ?>
					</label>
					<details class="tp-details">
						<summary>
							<?php
							printf(
								/* translators: %d: number of chosen categories */
								esc_html__( 'Κατηγορίες (%d επιλεγμένες)', 'theme-patcher' ),
								count( $list['include'] )
							);
							?>
						</summary>
						<p class="tp-hint"><?php esc_html_e( 'Τσέκαρε όσες θέλεις και γράψε τη σειρά τους (1, 2, 3…).', 'theme-patcher' ); ?></p>
						<div class="tp-scroll">
							<?php foreach ( $terms as $t ) : ?>
								<?php $on = isset( $order[ $t->term_id ] ); ?>
								<label class="tp-field">
									<input type="checkbox" name="<?php echo esc_attr( $n . '[use][]' ); ?>" value="<?php echo (int) $t->term_id; ?>" <?php checked( $on ); ?> />
									<input type="number" min="1" max="999" class="small-text" name="<?php echo esc_attr( $n . '[order][' . (int) $t->term_id . ']' ); ?>" value="<?php echo $on ? (int) $order[ $t->term_id ] + 1 : ''; ?>" />
									<span><?php echo esc_html( ( $t->parent ? '— ' : '' ) . $t->name . ' (' . (int) $t->count . ')' ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</details>
				</fieldset>
			<?php endforeach; ?>

			<p class="tp-submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'theme-patcher' ); ?></button>
			</p>
		</form>

		<form method="post" class="tp-mt-8">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="tp_action" value="rebuild_cats" />
			<?php self::tab_field( 'cats' ); ?>
			<button type="submit" class="button"><?php esc_html_e( 'Επιλογή εικόνων τώρα για όλες τις κατηγορίες', 'theme-patcher' ); ?></button>
		</form>
		<?php
	}

	/* ---------- Tab: checks ---------- */

	private static function render_checks(): void {
		$filters = TP_Checks::filter_widgets();
		?>
		<h2 class="tp-h2"><?php esc_html_e( 'Φίλτρα προϊόντων', 'theme-patcher' ); ?></h2>
		<?php if ( ! $filters ) : ?>
			<p class="tp-hint"><?php esc_html_e( 'Δεν βρέθηκαν φίλτρα WooCommerce σε μορφή block σε ενεργές περιοχές widgets.', 'theme-patcher' ); ?></p>
		<?php else : ?>
			<table class="widefat striped tp-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Περιοχή widgets', 'theme-patcher' ); ?></th>
						<th><?php esc_html_e( 'Blocks', 'theme-patcher' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $filters as $f ) : ?>
						<tr>
							<td><?php echo esc_html( $f['sidebar'] ); ?></td>
							<td><code><?php echo esc_html( implode( ', ', $f['blocks'] ) ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="tp-mt-8"><a class="button" href="<?php echo esc_url( admin_url( 'widgets.php' ) ); ?>"><?php esc_html_e( 'Άνοιγμα widgets', 'theme-patcher' ); ?></a></p>
		<?php endif; ?>
		<?php
	}

	/* ---------- Probe ---------- */

	private static function render_probe(): void {
		$report  = get_transient( 'tp_probe_' . get_current_user_id() );
		$default = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		$url     = is_array( $report ) && ! empty( $report['url'] ) ? remove_query_arg( TP_Runtime::PROBE_ARG, (string) $report['url'] ) : $default;
		?>
		<h2 class="tp-h2"><?php esc_html_e( '3. Σάρωση σελίδας', 'theme-patcher' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Ανοίγει μια σελίδα του site σε νέα καρτέλα με τις τρέχουσες ρυθμίσεις εφαρμοσμένες μόνο για σένα (ακόμη κι αν το Theme Patcher είναι ανενεργό) και καταγράφει τους βρόχους προϊόντων που βρήκε. Μετά ανανέωσε αυτή τη σελίδα για να δεις τα αποτελέσματα.', 'theme-patcher' ); ?></p>

		<form method="post" target="_blank" class="tp-inline-form">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="tp_action" value="probe" />
			<input type="url" name="tp_probe_url" class="regular-text" value="<?php echo esc_attr( $url ); ?>" required />
			<button type="submit" class="button"><?php esc_html_e( 'Σάρωση σελίδας', 'theme-patcher' ); ?></button>
		</form>

		<?php if ( ! is_array( $report ) ) : ?>
			<p class="tp-hint tp-mt-8"><?php esc_html_e( 'Δεν υπάρχει ακόμη αποτέλεσμα σάρωσης.', 'theme-patcher' ); ?></p>
			<?php
			return;
		endif;

		$loops = isset( $report['loops'] ) && is_array( $report['loops'] ) ? $report['loops'] : array();
		$modes = self::mode_labels();
		?>
		<p class="tp-mt-8">
			<?php
			printf(
				/* translators: 1: page URL, 2: date and time */
				esc_html__( 'Τελευταία σάρωση: %1$s — %2$s', 'theme-patcher' ),
				'<code>' . esc_html( (string) $report['url'] ) . '</code>',
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $report['time'] ) )
			);
			?>
		</p>
		<?php if ( isset( $report['theme'] ) && TP_Settings::theme_key() !== $report['theme'] ) : ?>
			<p class="tp-warn"><?php esc_html_e( 'Η σάρωση έγινε με άλλο ενεργό θέμα.', 'theme-patcher' ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $report['failed'] ) ) : ?>
			<p class="tp-warn"><?php esc_html_e( 'Παρουσιάστηκε σφάλμα κατά τη σάρωση: το Theme Patcher σταμάτησε τις αλλαγές για εκείνη τη σελίδα και άφησε το HTML του θέματος όπως ήταν.', 'theme-patcher' ); ?></p>
		<?php endif; ?>

		<table class="widefat striped tp-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Περιοχή', 'theme-patcher' ); ?></th>
					<th><?php esc_html_e( 'Λειτουργία', 'theme-patcher' ); ?></th>
					<th><?php esc_html_e( 'Κάρτες', 'theme-patcher' ); ?></th>
					<th><?php esc_html_e( 'Κανονικές', 'theme-patcher' ); ?></th>
					<th><?php esc_html_e( 'Με slot', 'theme-patcher' ); ?></th>
					<th><?php esc_html_e( 'Χωρίς θέση', 'theme-patcher' ); ?></th>
					<th><?php esc_html_e( 'Χρόνος (ms)', 'theme-patcher' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $loops ) ) : ?>
					<tr><td colspan="7" class="tp-empty"><?php esc_html_e( 'Δεν βρέθηκαν βρόχοι προϊόντων σε αυτή τη σελίδα.', 'theme-patcher' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $loops as $loop ) : ?>
					<?php
					$area = TP_Settings::valid_area_id( (string) ( isset( $loop['area'] ) ? $loop['area'] : '' ) );
					if ( '' === $area ) {
						continue;
					}
					$mode   = isset( $loop['mode'] ) ? (string) $loop['mode'] : 'off';
					$missed = isset( $loop['missed'] ) ? (int) $loop['missed'] : 0;
					?>
					<tr>
						<td>
							<?php echo esc_html( TP_Areas::label( $area ) ); ?>
							<?php if ( ! empty( $loop['file'] ) ) : ?>
								<br /><code><?php echo esc_html( (string) $loop['file'] ); ?></code>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( isset( $modes[ $mode ] ) ? $modes[ $mode ] : $mode ); ?></td>
						<td><?php echo (int) ( isset( $loop['cards'] ) ? $loop['cards'] : 0 ); ?></td>
						<td><?php echo (int) ( isset( $loop['standard'] ) ? $loop['standard'] : 0 ); ?></td>
						<td><?php echo (int) ( isset( $loop['marked'] ) ? $loop['marked'] : 0 ) - $missed; ?></td>
						<td<?php echo $missed > 0 ? ' class="tp-warn"' : ''; ?>><?php echo (int) $missed; ?></td>
						<td><?php echo esc_html( (string) ( isset( $loop['ms'] ) ? (float) $loop['ms'] : 0 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description tp-mt-8"><?php esc_html_e( '«Χωρίς θέση»: το slot δεν τοποθετήθηκε γιατί δεν βρέθηκε το κλείσιμο του στοιχείου μέσα στην κάρτα — άλλαξε το σημείο αναφοράς ή το στοιχείο. Σε περιοχή με Αντικατάσταση οι κάρτες είναι κανονικές.', 'theme-patcher' ); ?></p>
		<?php
		self::render_patch_report( isset( $report['patch'] ) && is_array( $report['patch'] ) ? $report['patch'] : array() );
	}

	/** Page-level changes counted by the probe. */
	private static function render_patch_report( array $patch ): void {
		$page  = isset( $patch['page'] ) && is_array( $patch['page'] ) ? $patch['page'] : array();
		$theme = isset( $patch['theme'] ) && is_array( $patch['theme'] ) ? $patch['theme'] : array();
		$cats  = isset( $patch['cats'] ) && is_array( $patch['cats'] ) ? $patch['cats'] : array();
		$n     = static function ( array $a, string $k ): int {
			return isset( $a[ $k ] ) ? (int) $a[ $k ] : 0;
		};
		$rows    = array(
			array( __( 'Κείμενα που άλλαξαν', 'theme-patcher' ), $n( $page, 'texts' ) ),
			array( __( 'Στοιχεία που αφαιρέθηκαν', 'theme-patcher' ), $n( $page, 'removed' ) ),
			array( __( 'Σύνδεσμοι στην ίδια καρτέλα', 'theme-patcher' ), $n( $page, 'same_tab' ) ),
			array( __( 'Εικόνες κατηγοριών σε μικρότερο μέγεθος', 'theme-patcher' ), $n( $page, 'cat_img' ) ),
			array( __( 'Εικόνες που πήραν alt', 'theme-patcher' ), $n( $page, 'alt' ) ),
			array( __( 'Σύνδεσμοι και κουμπιά που πήραν όνομα', 'theme-patcher' ), $n( $page, 'aria' ) ),
			array( __( 'Εικόνες κατηγοριών από προϊόν', 'theme-patcher' ), $n( $cats, 'fallback' ) ),
			array( __( 'Λίστες κατηγοριών που άλλαξαν', 'theme-patcher' ), $n( $cats, 'lists' ) ),
			array( __( 'Αρχεία θέματος από τον δίσκο αντί για HTTP', 'theme-patcher' ), $n( $theme, 'local' ) ),
		);
		$blocked = isset( $theme['blocked'] ) && is_array( $theme['blocked'] ) ? $theme['blocked'] : array();
		?>
		<h3 class="tp-h3"><?php esc_html_e( 'Αλλαγές στη σελίδα', 'theme-patcher' ); ?></h3>
		<table class="widefat striped tp-table">
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr><td><?php echo esc_html( $row[0] ); ?></td><td><?php echo (int) $row[1]; ?></td></tr>
				<?php endforeach; ?>
				<tr>
					<td><?php esc_html_e( 'Εγγραφές ρυθμίσεων θέματος που μπλοκαρίστηκαν', 'theme-patcher' ); ?></td>
					<td>
						<?php
						if ( ! $blocked ) {
							echo '0';
						}
						foreach ( $blocked as $mod => $count ) {
							echo '<code>' . esc_html( (string) $mod ) . '</code> × ' . (int) $count . '<br />';
						}
						?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/* =====================================================================
	 * Page: settings
	 * =================================================================== */

	public static function render_settings(): void {

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'theme-patcher' ) );
		}

		$notices = self::take_msgs();
		$s       = TP_Settings::get();
		?>
		<div class="wrap tp-wrap">
			<h1><?php esc_html_e( 'Theme Patcher — Ρυθμίσεις', 'theme-patcher' ); ?></h1>

			<?php self::print_notices( $notices ); ?>

			<form method="post">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="tp_action" value="save_settings" />

				<h2 class="tp-h2"><?php esc_html_e( 'Λειτουργία', 'theme-patcher' ); ?></h2>
				<div class="tp-zone">
					<label class="tp-check">
						<input type="checkbox" name="tp_enabled" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?> />
						<?php esc_html_e( 'Ενεργοποίηση του Theme Patcher στο front end', 'theme-patcher' ); ?>
					</label>
					<label class="tp-check">
						<input type="checkbox" name="tp_test_mode" value="1" <?php checked( ! empty( $s['test_mode'] ) ); ?> />
						<?php esc_html_e( 'Λειτουργία δοκιμής: οι αλλαγές φαίνονται μόνο σε όσους διαχειρίζονται το κατάστημα', 'theme-patcher' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή', 'theme-patcher' ); ?>
						<code>define( 'TP_DISABLE', true );</code>
					</p>
					<?php if ( Theme_Patcher::killed() ) : ?>
						<p class="tp-warn"><?php esc_html_e( 'Το TP_DISABLE είναι ενεργό: καμία αλλαγή δεν εφαρμόζεται στο front end.', 'theme-patcher' ); ?></p>
					<?php endif; ?>
				</div>

				<p class="tp-submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'theme-patcher' ); ?></button>
				</p>
			</form>

			<h2 class="tp-h2"><?php esc_html_e( 'Ρυθμίσεις ενεργού θέματος', 'theme-patcher' ); ?></h2>
			<form method="post">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="tp_action" value="reset_theme" />
				<p class="description"><?php esc_html_e( 'Όλες οι ρυθμίσεις καρτών, κειμένων, σελίδας, θέματος και κατηγοριών αποθηκεύονται ξεχωριστά για κάθε θέμα. Η επαναφορά διαγράφει μόνο εκείνες του ενεργού θέματος.', 'theme-patcher' ); ?></p>
				<label class="tp-check">
					<input type="checkbox" name="tp_confirm_reset" value="1" required />
					<?php esc_html_e( 'Ναι, διάγραψε τις ρυθμίσεις του ενεργού θέματος', 'theme-patcher' ); ?>
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Επαναφορά', 'theme-patcher' ); ?></button>
			</form>

			<h2 class="tp-h2"><?php esc_html_e( 'Backup & Επαναφορά', 'theme-patcher' ); ?></h2>
			<form method="post" enctype="multipart/form-data" class="tp-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'tp_backup_nonce' ); ?>
				<input type="file" name="tp_import_file" accept=".json,application/json" />
				<button type="submit" name="tp_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'theme-patcher' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG_SET . '&tp_backup_export=1' ), self::NONCE_BAK ) ); ?>">
				<?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'theme-patcher' ); ?>
			</a>
			<p class="description tp-mt-8">
				<?php esc_html_e( 'Περιλαμβάνονται όλες οι ρυθμίσεις, για όλα τα θέματα. Η εισαγωγή ΑΝΤΙΚΑΘΙΣΤΑ τις τρέχουσες ρυθμίσεις, μετά από πλήρη έλεγχο εγκυρότητας.', 'theme-patcher' ); ?>
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
		if ( self::SLUG_MAIN !== $page && self::SLUG_SET !== $page ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['tp_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'theme-patcher' ) );
		}

		$action = sanitize_key( wp_unslash( $_POST['tp_action'] ) );
		$back   = self::SLUG_SET === $page ? self::SLUG_SET : self::SLUG_MAIN;
		$tab    = isset( $_POST['tp_tab'] ) ? sanitize_key( wp_unslash( $_POST['tp_tab'] ) ) : '';
		if ( self::SLUG_MAIN === $back && isset( self::TABS[ $tab ] ) && 'cards' !== $tab ) {
			$back .= '&tab=' . $tab;
		}

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
				TP_Detector::scan( true );
				$msgs = array( self::msg( 'success', __( 'Η σάρωση του θέματος ολοκληρώθηκε.', 'theme-patcher' ) ) );
				break;
			case 'add_area':
				$msgs = self::do_add_area();
				break;
			case 'save_areas':
				$msgs = self::do_save_areas();
				break;
			case 'save_texts':
				$msgs = self::do_save_texts();
				break;
			case 'save_page':
				$msgs = self::do_save_page();
				break;
			case 'save_theme':
				$msgs = self::do_save_theme();
				break;
			case 'save_cats':
				$msgs = self::do_save_cats();
				break;
			case 'rebuild_cats':
				/* translators: %d: number of categories */
				$msgs = array( self::msg( 'success', sprintf( __( 'Επιλέχθηκε εικόνα προϊόντος για %d κατηγορίες.', 'theme-patcher' ), TP_Categories::rebuild() ) ) );
				break;
			case 'confirm':
				$msgs = self::do_confirm();
				break;
			default:
				$msgs = array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'theme-patcher' ) ) );
		}

		$s = TP_Settings::get();
		if ( 0 === strpos( $action, 'save_' ) && 'save_settings' !== $action && empty( $s['enabled'] ) && TP_Settings::has_anything() ) {
			$msgs[] = self::msg( 'warning', __( 'Το Theme Patcher είναι ακόμη ανενεργό: δοκίμασε με τη σάρωση σελίδας και ενεργοποίησέ το από τις Ρυθμίσεις.', 'theme-patcher' ) );
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
		set_transient( 'tp_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . $slug ) );
		exit;
	}

	/** Settings save: master switch + test mode (themes untouched). */
	private static function do_save_settings(): array {
		$s              = TP_Settings::get();
		$s['enabled']   = ! empty( $_POST['tp_enabled'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$s['test_mode'] = ! empty( $_POST['tp_test_mode'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		TP_Settings::save( $s );
		return array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'theme-patcher' ) ) );
	}

	private static function do_reset_theme(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		if ( empty( $_POST['tp_confirm_reset'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιβεβαιώθηκε η επαναφορά.', 'theme-patcher' ) ) );
		}
		$s = TP_Settings::get();
		unset( $s['themes'][ TP_Settings::theme_key() ] );
		TP_Settings::save( $s );
		return array( self::msg( 'success', __( 'Οι ρυθμίσεις του ενεργού θέματος διαγράφηκαν.', 'theme-patcher' ) ) );
	}

	/** Add a file area: valid theme-relative path of an existing theme file. */
	private static function do_add_area(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in route(); validated by valid_rel_path().
		$raw  = isset( $_POST['tp_file'] ) ? (string) wp_unslash( $_POST['tp_file'] ) : '';
		$path = TP_Settings::valid_rel_path( ltrim( str_replace( '\\', '/', $raw ), '/' ) );

		if ( '' === $path || '' === TP_Areas::theme_file( $path ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν βρέθηκε στο ενεργό θέμα (διαδρομή σχετική με τον φάκελο του θέματος, π.χ. template-parts/home.php).', 'theme-patcher' ) ) );
		}

		$id    = TP_Areas::FILE_PREFIX . $path;
		$theme = TP_Settings::theme();
		if ( isset( $theme['areas'][ $id ] ) ) {
			return array( self::msg( 'warning', __( 'Η περιοχή υπάρχει ήδη.', 'theme-patcher' ) ) );
		}
		$files = 0;
		foreach ( array_keys( $theme['areas'] ) as $k ) {
			if ( TP_Areas::is_file_area( (string) $k ) ) {
				++$files;
			}
		}
		if ( $files >= TP_Settings::MAX_FILE_AREAS ) {
			return array(
				self::msg(
					'error',
					/* translators: %d: maximum number of file areas */
					sprintf( __( 'Έφτασες το όριο των %d αρχείων θέματος.', 'theme-patcher' ), (int) TP_Settings::MAX_FILE_AREAS )
				),
			);
		}

		$theme['areas'][ $id ] = TP_Settings::area_defaults();
		TP_Settings::save_theme( TP_Settings::sanitize_theme( $theme ) );
		return array( self::msg( 'success', __( 'Η περιοχή προστέθηκε — ρύθμισέ την παρακάτω (ξεκινά ανενεργή).', 'theme-patcher' ) ) );
	}

	private static function do_save_areas(): array {
		// Detect file changes made since the last save before accepting the
		// current files as the new reference.
		TP_Detector::check();

		$theme = TP_Settings::theme();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in route(); every value goes through TP_Settings::sanitize_area().
		$posted = isset( $_POST['tp_areas'] ) && is_array( $_POST['tp_areas'] ) ? wp_unslash( $_POST['tp_areas'] ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against known ids only.
		$remove = isset( $_POST['tp_remove'] ) && is_array( $_POST['tp_remove'] ) ? array_map( 'strval', wp_unslash( $_POST['tp_remove'] ) ) : array();

		$areas = array();
		foreach ( self::area_ids() as $id ) {
			if ( in_array( $id, $remove, true ) && TP_Areas::is_file_area( $id ) ) {
				continue;
			}
			$raw = isset( $posted[ $id ] ) && is_array( $posted[ $id ] ) ? $posted[ $id ] : array();
			if ( ! isset( $raw['parts'] ) ) {
				$raw['parts'] = array(); // Unchecked boxes are not posted.
			}
			$area = TP_Settings::sanitize_area( $raw, $id );
			if ( 'off' === $area['mode'] && ! TP_Areas::is_file_area( $id ) && $area == TP_Settings::area_defaults() ) { // phpcs:ignore Universal.Operators.StrictComparisons -- array value comparison.
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
		$fp  = TP_Detector::fingerprints( $theme );
		foreach ( $suspended as $id ) {
			foreach ( TP_Detector::files_of_area( $id ) as $rel ) {
				if ( isset( $old[ $rel ] ) ) {
					$fp[ $rel ] = $old[ $rel ];
				}
			}
		}
		$theme['fingerprints'] = $fp;

		TP_Settings::save_theme( TP_Settings::sanitize_theme( $theme ) );

		return array( self::msg( 'success', __( 'Οι περιοχές αποθηκεύτηκαν.', 'theme-patcher' ) ) );
	}

	/** POSTed array field (verified in route(); every value is validated by TP_Settings). */
	private static function posted_array( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in route(); validated by TP_Settings.
		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
	}

	private static function do_save_texts(): array {
		$theme          = TP_Settings::theme();
		$theme['texts'] = array_values( self::posted_array( 'tp_texts' ) );
		TP_Settings::save_theme( TP_Settings::sanitize_theme( $theme ) );
		return array( self::msg( 'success', __( 'Τα κείμενα αποθηκεύτηκαν.', 'theme-patcher' ) ) );
	}

	private static function do_save_page(): array {
		$raw = self::posted_array( 'tp_page' );
		if ( isset( $raw['remove'] ) && is_array( $raw['remove'] ) ) {
			$bad = 0;
			foreach ( $raw['remove'] as $sel ) {
				if ( '' !== trim( (string) $sel ) && '' === TP_Settings::valid_simple_selector( (string) $sel ) ) {
					++$bad;
				}
			}
		}
		$theme         = TP_Settings::theme();
		$theme['page'] = $raw;
		TP_Settings::save_theme( TP_Settings::sanitize_theme( $theme ) );
		$msgs = array( self::msg( 'success', __( 'Οι κανόνες σελίδας αποθηκεύτηκαν.', 'theme-patcher' ) ) );
		if ( ! empty( $bad ) ) {
			$msgs[] = self::msg( 'warning', __( 'Κάποιοι κανόνες αφαίρεσης δεν έχουν έγκυρη μορφή και αγνοήθηκαν.', 'theme-patcher' ) );
		}
		return $msgs;
	}

	private static function do_save_theme(): array {
		$theme                = TP_Settings::theme();
		$theme['guard']       = ! empty( $_POST['tp_guard'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$theme['local_files'] = ! empty( $_POST['tp_local_files'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$mods                 = array();
		foreach ( self::posted_array( 'tp_mods' ) as $row ) {
			if ( is_array( $row ) && isset( $row['name'], $row['value'] ) && '' !== trim( (string) $row['name'] ) ) {
				$mods[ trim( (string) $row['name'] ) ] = (string) $row['value'];
			}
		}
		$theme['mods'] = $mods;
		$clean         = TP_Settings::sanitize_theme( $theme );
		TP_Settings::save_theme( $clean );
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις θέματος αποθηκεύτηκαν.', 'theme-patcher' ) ) );
		if ( count( $clean['mods'] ) < count( $mods ) ) {
			$msgs[] = self::msg( 'warning', __( 'Κάποιες σταθερές τιμές δεν είναι έγκυρες (όνομα με γράμματα, αριθμούς, - και _) και αγνοήθηκαν.', 'theme-patcher' ) );
		}
		return $msgs;
	}

	private static function do_save_cats(): array {
		$raw   = self::posted_array( 'tp_cats' );
		$lists = array();
		foreach ( ( isset( $raw['lists'] ) && is_array( $raw['lists'] ) ) ? $raw['lists'] : array() as $l ) {
			if ( ! is_array( $l ) || empty( $l['file'] ) ) {
				continue;
			}
			$use   = isset( $l['use'] ) && is_array( $l['use'] ) ? array_map( 'intval', $l['use'] ) : array();
			$order = isset( $l['order'] ) && is_array( $l['order'] ) ? $l['order'] : array();
			$rank  = array();
			foreach ( $use as $pos => $tid ) {
				$o            = isset( $order[ $tid ] ) && '' !== (string) $order[ $tid ] ? (int) $order[ $tid ] : 1000;
				$rank[ $tid ] = $o * 1000 + $pos; // Ties keep the list order.
			}
			asort( $rank );
			$lists[] = array(
				'file'       => (string) $l['file'],
				'include'    => array_keys( $rank ),
				'hide_empty' => ! empty( $l['hide_empty'] ),
			);
		}
		$theme         = TP_Settings::theme();
		$was_on        = $theme['cats']['fallback'];
		$theme['cats'] = array(
			'fallback' => ! empty( $raw['fallback'] ),
			'source'   => isset( $raw['source'] ) ? (string) $raw['source'] : 'recent',
			'lists'    => $lists,
		);
		$clean = TP_Settings::sanitize_theme( $theme );
		TP_Settings::save_theme( $clean );

		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις κατηγοριών αποθηκεύτηκαν.', 'theme-patcher' ) ) );
		if ( $clean['cats']['fallback'] && ( ! $was_on || ! TP_Categories::map() ) ) {
			$msgs[] = self::msg(
				'success',
				/* translators: %d: number of categories */
				sprintf( __( 'Επιλέχθηκε εικόνα προϊόντος για %d κατηγορίες.', 'theme-patcher' ), TP_Categories::rebuild() )
			);
		}
		return $msgs;
	}

	private static function do_confirm(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in route(); validated by valid_area_id().
		$id = TP_Settings::valid_area_id( isset( $_POST['tp_area'] ) ? (string) wp_unslash( $_POST['tp_area'] ) : '' );
		if ( '' === $id || ! TP_Settings::is_suspended( $id ) ) {
			return array( self::msg( 'error', __( 'Άγνωστη περιοχή.', 'theme-patcher' ) ) );
		}
		TP_Detector::confirm( $id );
		return array( self::msg( 'success', __( 'Η περιοχή ενεργοποιήθηκε ξανά με τα τρέχοντα αρχεία του θέματος.', 'theme-patcher' ) ) );
	}

	/** Probe: same-site URL only, then redirect with a fresh probe nonce. */
	private static function do_probe(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$url  = isset( $_POST['tp_probe_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['tp_probe_url'] ) ) : '';
		$home = wp_parse_url( home_url() );
		$dest = '' !== $url ? wp_parse_url( $url ) : false;

		if ( ! is_array( $dest ) || empty( $dest['host'] ) || ! isset( $home['host'] ) || strtolower( $dest['host'] ) !== strtolower( $home['host'] ) ) {
			self::redirect( self::SLUG_MAIN, array( self::msg( 'error', __( 'Η σάρωση δέχεται μόνο σελίδες αυτού του site.', 'theme-patcher' ) ) ) );
		}

		delete_transient( 'tp_probe_' . get_current_user_id() );
		$target = add_query_arg( TP_Runtime::PROBE_ARG, wp_create_nonce( TP_Runtime::PROBE_NONCE ), remove_query_arg( TP_Runtime::PROBE_ARG, $url ) );
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
		if ( isset( $_GET['tp_backup_export'] ) ) {

			if ( ! isset( $_GET['_wpnonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'theme-patcher' ) );
			}

			$state = array(
				'plugin'  => 'theme-patcher',
				'version' => TP_VERSION,
				'options' => array(
					TP_Settings::OPT => TP_Settings::get(),
				),
			);

			nocache_headers();
			header( 'Content-Type: application/json; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="theme-patcher-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
			echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
			exit;
		}

		// ---------- Import (POST + nonce + PRG) ----------
		if ( isset( $_POST['tp_import'] ) ) {

			if ( ! isset( $_POST['tp_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['tp_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'theme-patcher' ) );
			}

			$msgs = array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'theme-patcher' ) ) );

			$up_err  = isset( $_FILES['tp_import_file']['error'] ) ? (int) $_FILES['tp_import_file']['error'] : UPLOAD_ERR_NO_FILE;
			$up_size = isset( $_FILES['tp_import_file']['size'] ) ? (int) $_FILES['tp_import_file']['size'] : 0;

			if ( UPLOAD_ERR_OK === $up_err && $up_size > 0 && $up_size <= self::MAX_IMPORT
				&& ! empty( $_FILES['tp_import_file']['tmp_name'] )
				&& is_uploaded_file( $_FILES['tp_import_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- tmp path from PHP.

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.ValidatedSanitizedInput -- uploaded tmp file.
				$raw   = (string) file_get_contents( $_FILES['tp_import_file']['tmp_name'] );
				$state = json_decode( $raw, true );

				$msgs = is_array( $state )
					? self::import_state( $state )
					: array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'theme-patcher' ) ) );
			} elseif ( $up_size > self::MAX_IMPORT ) {
				$msgs = array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'theme-patcher' ) ) );
			}

			self::redirect( self::SLUG_SET, $msgs );
		}
	}

	/**
	 * Strict import: the settings blob must be an object; every value goes
	 * through TP_Settings::sanitize_all() (the same validation as the
	 * forms). Invalid file → explicit error, current settings untouched.
	 */
	private static function import_state( array $state ): array {
		$opts = isset( $state['options'] ) && is_array( $state['options'] ) ? $state['options'] : array();
		if ( ! isset( $opts[ TP_Settings::OPT ] ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Theme Patcher).', 'theme-patcher' ) ) );
		}
		$blob = $opts[ TP_Settings::OPT ];
		if ( is_string( $blob ) ) {
			$blob = json_decode( $blob, true );
		}
		if ( ! is_array( $blob ) || ! isset( $blob['themes'] ) || ! is_array( $blob['themes'] ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο blob ρυθμίσεων.', 'theme-patcher' ) ) );
		}

		$clean = TP_Settings::sanitize_all( $blob );
		TP_Settings::save( $clean );

		$msgs = array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'theme-patcher' ) ) );
		if ( count( $clean['themes'] ) < count( $blob['themes'] ) ) {
			$msgs[] = self::msg( 'warning', __( 'Κάποια θέματα παραλείφθηκαν επειδή τα δεδομένα τους δεν ήταν έγκυρα.', 'theme-patcher' ) );
		}
		return $msgs;
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'tp_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'tp_aui_msg_' . get_current_user_id() );
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
		<p class="tp-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'theme-patcher' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="tp-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="tp-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'theme-patcher' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="tp-footer-cta">
			<a class="tp-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'theme-patcher' ); ?>
			</a>
			<a class="tp-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'theme-patcher' ); ?>
			</a>
		</p>
		<?php
	}
}

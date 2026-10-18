<?php
/**
 * WSH_Admin_UI — menu, settings page, PRG routes, report, backup
 * (Bible §3–§8, §11).
 *
 * One page, submenu «Λίστα επιθυμιών» of the shared "Noxpress" menu
 * (admin_menu priority 200), slug wsh-settings, with tabs:
 *  - general  mode, other wishlist plugins, guests, buttons, look, notice
 *  - list     list page, layout, columns, options, My Account, counter, sharing
 *  - texts    visitor text language and texts
 *  - report   most wanted products, totals, CSV
 *  - tools    import from WishSuite, backup, uninstall choice, developers
 *
 * Every state change: POST on admin_init, slug + nonce + capability,
 * strict validation (WSH_Settings::sanitize), PRG with the message in the
 * transient wsh_aui_msg_{uid}. Exports: privileged GET (slug + nonce +
 * capability).
 */

defined( 'ABSPATH' ) || exit;

final class WSH_Admin_UI {

	const CAP         = 'manage_woocommerce';
	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'wsh-settings';
	const NONCE       = 'wsh_admin';
	const NONCE_FIELD = 'wsh_nonce';
	const NONCE_BAK   = 'wsh_backup';
	const NONCE_CSV   = 'wsh_csv';
	const MAX_IMPORT  = 262144;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 200 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_get' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WSH_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label msgid. */
	private static function tabs(): array {
		return array(
			'general' => __( 'Γενικά', 'wish-shelf' ),
			'list'    => __( 'Λίστα', 'wish-shelf' ),
			'texts'   => __( 'Κείμενα', 'wish-shelf' ),
			'report'  => __( 'Αναφορά', 'wish-shelf' ),
			'tools'   => __( 'Εργαλεία', 'wish-shelf' ),
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
			__( 'Wish Shelf', 'wish-shelf' ),
			__( 'Λίστα επιθυμιών', 'wish-shelf' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'wish-shelf' ) . '</a>'
		);
		return $links;
	}

	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	/** A sanitized GET parameter (read-only routing). */
	private static function q( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	private static function current_tab(): string {
		$tab = sanitize_key( self::q( 'tab' ) );
		return isset( self::tabs()[ $tab ] ) ? $tab : 'general';
	}

	public static function assets( $hook = '' ): void {
		if ( 'edit.php' === $hook && 'product' === self::q( 'post_type' ) ) {
			wp_add_inline_style( 'woocommerce_admin_styles', '.wp-list-table .column-wsh{width:3.2em;text-align:center}.wp-list-table th.column-wsh .wsh-col-heart{color:#d63638}' );
			return;
		}
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'wsh-admin', WSH_URL . 'assets/admin.css', array(), WSH_VERSION );
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'wish-shelf' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap wsh-wrap">
			<h1><?php esc_html_e( 'Wish Shelf', 'wish-shelf' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Λίστα επιθυμιών για τους πελάτες: καρδιά στα προϊόντα, σελίδα λίστας, καρτέλα στον λογαριασμό, κοινοποίηση και αναφορά με τα πιο επιθυμητά προϊόντα.', 'wish-shelf' ); ?></p>

			<?php self::print_notices( self::take_msgs() ); ?>

			<nav class="nav-tab-wrapper wsh-tabs">
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
				case 'list':
					self::render_list();
					break;
				case 'texts':
					self::render_texts();
					break;
				case 'report':
					self::render_report();
					break;
				case 'tools':
					self::render_tools();
					break;
				default:
					self::render_general();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	/**
	 * A <select> from value => label.
	 *
	 * @param string $name    Name.
	 * @param array  $opts    Options.
	 * @param string $current Current value.
	 */
	private static function select( string $name, array $opts, string $current ): string {
		$html = '<select name="' . esc_attr( $name ) . '">';
		foreach ( $opts as $k => $label ) {
			$html .= '<option value="' . esc_attr( (string) $k ) . '" ' . selected( $current, (string) $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html . '</select>';
	}

	/**
	 * A checkbox line.
	 *
	 * @param string $name  Name.
	 * @param bool   $on    Checked.
	 * @param string $label Label.
	 */
	private static function check( string $name, bool $on, string $label ): string {
		return '<label class="wsh-check"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $on, true, false ) . ' /> ' . esc_html( $label ) . '</label>';
	}

	/* ---------------------------------------------------------------------
	 * Tab: general
	 * ------------------------------------------------------------------- */

	private static function render_general(): void {
		$s = WSH_Settings::get();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="wsh_action" value="save_general" />

			<h2 class="wsh-h2"><?php esc_html_e( 'Λειτουργία', 'wish-shelf' ); ?></h2>
			<div class="wsh-zone">
				<?php
				if ( Wish_Shelf::killed() ) {
					echo '<p class="wsh-warn">' . esc_html__( 'Το WSH_DISABLE είναι ενεργό στο wp-config.php: η λίστα επιθυμιών δεν εμφανίζεται πουθενά.', 'wish-shelf' ) . '</p>';
				}
				$others = WSH_Import::active_names();
				if ( $others ) {
					echo '<p class="wsh-warn">' . esc_html(
						sprintf(
							/* translators: %s: plugin names */
							__( 'Είναι ενεργό και άλλο plugin για λίστα επιθυμιών: %s. Όσο είναι ενεργό, το Wish Shelf μένει σε λειτουργία δοκιμής (μόνο οι διαχειριστές το βλέπουν), ώστε ο επισκέπτης να μη δει δύο κουμπιά. Πέρασε τις λίστες με τα Εργαλεία → Εισαγωγή και απενεργοποίησέ το όταν είσαι έτοιμος.', 'wish-shelf' ),
							implode( ', ', $others )
						)
					) . '</p>';
				}
				if ( ! WSH_Store::ready() ) {
					echo '<p class="wsh-warn">' . esc_html__( 'Ο πίνακας των λιστών δεν δημιουργήθηκε. Απενεργοποίησε και ενεργοποίησε ξανά το plugin.', 'wish-shelf' ) . '</p>';
				}
				$modes = array(
					'off'  => __( 'Ανενεργό', 'wish-shelf' ),
					'test' => __( 'Λειτουργία δοκιμής: φαίνεται μόνο στους διαχειριστές του καταστήματος', 'wish-shelf' ),
					'live' => __( 'Ενεργό για όλους', 'wish-shelf' ),
				);
				foreach ( $modes as $k => $label ) {
					echo '<label class="wsh-check"><input type="radio" name="wsh_mode" value="' . esc_attr( $k ) . '" ' . checked( $s['mode'], $k, false ) . ' /> ' . esc_html( $label ) . '</label>';
				}
				echo '<p class="description">' . esc_html__( 'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή', 'wish-shelf' ) . ' <code>define( \'WSH_DISABLE\', true );</code></p>';
				?>
			</div>

			<h2 class="wsh-h2"><?php esc_html_e( 'Επισκέπτες χωρίς σύνδεση', 'wish-shelf' ); ?></h2>
			<div class="wsh-zone">
				<label class="wsh-check"><input type="radio" name="wsh_guests" value="allow" <?php checked( $s['guests'], 'allow' ); ?> /> <?php esc_html_e( 'Κρατούν λίστα στον browser τους (localStorage, χωρίς cookie)', 'wish-shelf' ); ?></label>
				<label class="wsh-check"><input type="radio" name="wsh_guests" value="login" <?php checked( $s['guests'], 'login' ); ?> /> <?php esc_html_e( 'Πρέπει να συνδεθούν', 'wish-shelf' ); ?></label>
				<p class="description"><?php esc_html_e( 'Η λίστα ενός επισκέπτη δεν στέλνεται ποτέ στο κατάστημα: μένει μόνο σε εκείνη τη συσκευή και σε εκείνον τον browser, και περνά στον λογαριασμό του όταν συνδεθεί. Γι\' αυτό η αναφορά μετρά μόνο τους συνδεδεμένους πελάτες.', 'wish-shelf' ); ?></p>
			</div>

			<h2 class="wsh-h2"><?php esc_html_e( 'Κουμπί', 'wish-shelf' ); ?></h2>
			<div class="wsh-zone">
				<?php echo self::check( 'wsh_loop_enabled', $s['loop']['enabled'], __( 'Στις κάρτες προϊόντων (κατάστημα, κατηγορίες, σχετικά)', 'wish-shelf' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="wsh-field"><span><?php esc_html_e( 'Θέση στις κάρτες', 'wish-shelf' ); ?></span>
				<?php
				echo self::select( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in select().
					'wsh_loop_position',
					array(
						'before' => __( 'Πριν από το «Προσθήκη στο καλάθι»', 'wish-shelf' ),
						'after'  => __( 'Μετά το «Προσθήκη στο καλάθι»', 'wish-shelf' ),
						'image'  => __( 'Πάνω στην εικόνα (γωνία, μόνο καρδιά)', 'wish-shelf' ),
					),
					$s['loop']['position']
				);
				?>
				</p>
				<?php echo self::check( 'wsh_single_enabled', $s['single']['enabled'], __( 'Στη σελίδα προϊόντος', 'wish-shelf' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="wsh-field"><span><?php esc_html_e( 'Θέση στη σελίδα προϊόντος', 'wish-shelf' ); ?></span>
				<?php
				echo self::select( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in select().
					'wsh_single_position',
					array(
						'before' => __( 'Πριν από το «Προσθήκη στο καλάθι»', 'wish-shelf' ),
						'after'  => __( 'Μετά το «Προσθήκη στο καλάθι»', 'wish-shelf' ),
					),
					$s['single']['position']
				);
				?>
				</p>
				<p class="wsh-field"><span><?php esc_html_e( 'Εμφάνιση', 'wish-shelf' ); ?></span>
				<?php
				echo self::select( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in select().
					'wsh_style',
					array(
						'icon_text' => __( 'Καρδιά και κείμενο', 'wish-shelf' ),
						'icon'      => __( 'Μόνο καρδιά', 'wish-shelf' ),
						'text'      => __( 'Μόνο κείμενο', 'wish-shelf' ),
					),
					$s['style']
				);
				?>
				</p>
				<?php echo self::check( 'wsh_toast', $s['toast'], __( 'Μικρή ειδοποίηση μετά το κλικ, με σύνδεσμο «Προβολή λίστας»', 'wish-shelf' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="description"><?php esc_html_e( 'Σε μεταβλητά προϊόντα, στη σελίδα του προϊόντος αποθηκεύεται η παραλλαγή που έχει επιλέξει ο πελάτης (αλλιώς το προϊόν). Σε block themes το κουμπί μπαίνει δίπλα στα blocks «Product Button» και «Add to Cart»· αλλού χρησιμοποίησε το block «Wishlist button» ή το [nox_wishlist_button].', 'wish-shelf' ); ?></p>
			</div>

			<p class="wsh-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'wish-shelf' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: list
	 * ------------------------------------------------------------------- */

	private static function render_list(): void {
		$s    = WSH_Settings::get();
		$page = WSH_Settings::page_id();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="wsh_action" value="save_list" />

			<h2 class="wsh-h2"><?php esc_html_e( 'Σελίδα λίστας', 'wish-shelf' ); ?></h2>
			<div class="wsh-zone">
				<p class="wsh-field"><span><?php esc_html_e( 'Σελίδα', 'wish-shelf' ); ?></span>
				<?php
				wp_dropdown_pages(
					array(
						'name'              => 'wsh_page_id',
						'selected'          => (int) $s['page_id'],
						'show_option_none'  => __( '— Καμία —', 'wish-shelf' ),
						'option_none_value' => '0',
						'post_status'       => array( 'publish', 'draft', 'private' ),
					)
				);
				?>
				<?php if ( $page ) : ?>
					<a href="<?php echo esc_url( get_permalink( $page ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Προβολή', 'wish-shelf' ); ?></a>
				<?php endif; ?>
				</p>
				<?php if ( $s['page_id'] && ! $page ) : ?>
					<p class="wsh-warn"><?php esc_html_e( 'Η σελίδα που επέλεξες δεν είναι δημοσιευμένη.', 'wish-shelf' ); ?></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Η σελίδα χρειάζεται το shortcode [nox_wishlist] ή το block «Wishlist». Αν αλλάζεις από άλλο plugin, βάλε το [nox_wishlist] στη θέση του παλιού shortcode στην ίδια σελίδα, ώστε τα μενού να μην αλλάξουν.', 'wish-shelf' ); ?></p>
				<p><button type="submit" name="wsh_create_page" value="1" class="button"><?php esc_html_e( 'Δημιουργία σελίδας', 'wish-shelf' ); ?></button></p>
			</div>

			<h2 class="wsh-h2"><?php esc_html_e( 'Εμφάνιση της λίστας', 'wish-shelf' ); ?></h2>
			<div class="wsh-zone">
				<p class="wsh-field"><span><?php esc_html_e( 'Διάταξη', 'wish-shelf' ); ?></span>
				<?php
				echo self::select( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in select().
					'wsh_layout',
					array(
						'table' => __( 'Πίνακας', 'wish-shelf' ),
						'cards' => __( 'Κάρτες του θέματος (όπως το κατάστημα)', 'wish-shelf' ),
					),
					$s['layout']
				);
				?>
				</p>
				<p class="wsh-field wsh-field--top"><span><?php esc_html_e( 'Στήλες του πίνακα', 'wish-shelf' ); ?></span><span class="wsh-cols">
				<?php
				$labels = array(
					'image'  => __( 'Εικόνα', 'wish-shelf' ),
					'price'  => __( 'Τιμή', 'wish-shelf' ),
					'stock'  => __( 'Διαθεσιμότητα', 'wish-shelf' ),
					'date'   => __( 'Ημερομηνία προσθήκης', 'wish-shelf' ),
					'qty'    => __( 'Ποσότητα', 'wish-shelf' ),
					'cart'   => __( 'Κουμπί «Στο καλάθι»', 'wish-shelf' ),
					'remove' => __( 'Αφαίρεση', 'wish-shelf' ),
				);
				foreach ( $labels as $k => $label ) {
					echo self::check( 'wsh_cols[' . $k . ']', $s['cols'][ $k ], $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
				</span></p>
				<?php
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in check().
				echo self::check( 'wsh_price_drop', $s['price_drop'], __( 'Ένδειξη «Η τιμή έπεσε κατά …» (συνδεδεμένοι πελάτες)', 'wish-shelf' ) );
				echo self::check( 'wsh_bulk', $s['bulk'], __( 'Μαζικές ενέργειες: «Όλα στο καλάθι», «Επιλεγμένα στο καλάθι», «Άδειασμα λίστας»', 'wish-shelf' ) );
				echo self::check( 'wsh_remove_after', $s['remove_after'], __( 'Αφαίρεση από τη λίστα όταν ένα προϊόν μπαίνει στο καλάθι από τη λίστα', 'wish-shelf' ) );
				// phpcs:enable
				?>
				<p class="description"><?php esc_html_e( 'Μεταβλητά προϊόντα χωρίς επιλεγμένη παραλλαγή, πακέτα και άλλοι τύποι που θέλουν επιλογές δείχνουν «Επιλογές» και ανοίγουν τη σελίδα του προϊόντος.', 'wish-shelf' ); ?></p>
			</div>

			<h2 class="wsh-h2"><?php esc_html_e( 'Λογαριασμός και μετρητής', 'wish-shelf' ); ?></h2>
			<div class="wsh-zone">
				<?php echo self::check( 'wsh_account', $s['account'], __( 'Καρτέλα «Λίστα επιθυμιών» στο «Ο λογαριασμός μου»', 'wish-shelf' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="wsh-field"><span><?php esc_html_e( 'Διεύθυνση της καρτέλας', 'wish-shelf' ); ?></span><input type="text" name="wsh_endpoint" value="<?php echo esc_attr( $s['endpoint'] ); ?>" maxlength="40" pattern="[a-z0-9\-]+" /></p>
				<p class="wsh-field"><span><?php esc_html_e( 'Μετρητής στο μενού', 'wish-shelf' ); ?></span>
				<?php
				$locs = array( '' => __( '— Κανένα —', 'wish-shelf' ) );
				foreach ( get_registered_nav_menus() as $loc => $label ) {
					$locs[ $loc ] = $label;
				}
				echo self::select( 'wsh_menu', $locs, $s['menu'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in select().
				?>
				</p>
				<?php echo self::check( 'wsh_count_zero', $s['count_zero'], __( 'Ο μετρητής φαίνεται και όταν η λίστα είναι άδεια', 'wish-shelf' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="description"><?php esc_html_e( 'Σε block themes ο μετρητής μπαίνει με το block «Wishlist counter» στο header· αλλού με το [nox_wishlist_count].', 'wish-shelf' ); ?></p>
			</div>

			<h2 class="wsh-h2"><?php esc_html_e( 'Κοινοποίηση', 'wish-shelf' ); ?></h2>
			<div class="wsh-zone">
				<?php echo self::check( 'wsh_share', $s['share'], __( 'Σύνδεσμος κοινοποίησης στη λίστα', 'wish-shelf' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="description"><?php esc_html_e( 'Επισκέπτες: σύνδεσμος με τα προϊόντα μέσα στη διεύθυνση. Συνδεδεμένοι πελάτες: σύνδεσμος με τυχαίο κλειδί, ιδιωτικός μέχρι να τον φτιάξουν, με «Νέος σύνδεσμος» που ακυρώνει τον παλιό. Το όνομα του πελάτη δεν φαίνεται. Κανένα script κοινωνικών δικτύων: αντιγραφή συνδέσμου και το μενού κοινοποίησης της συσκευής. Οι σελίδες κοινοποίησης έχουν noindex.', 'wish-shelf' ); ?></p>
			</div>

			<p class="wsh-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'wish-shelf' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: texts
	 * ------------------------------------------------------------------- */

	private static function text_labels(): array {
		return array(
			't_add'       => __( 'Κουμπί', 'wish-shelf' ),
			't_added'     => __( 'Κουμπί, όταν το προϊόν είναι στη λίστα', 'wish-shelf' ),
			't_toast_add' => __( 'Ειδοποίηση προσθήκης ({product_name})', 'wish-shelf' ),
			't_toast_rem' => __( 'Ειδοποίηση αφαίρεσης ({product_name})', 'wish-shelf' ),
			't_view'      => __( 'Σύνδεσμος «Προβολή λίστας»', 'wish-shelf' ),
			't_title'     => __( 'Τίτλος (καρτέλα λογαριασμού, μετρητής)', 'wish-shelf' ),
			't_empty'     => __( 'Άδεια λίστα', 'wish-shelf' ),
			't_login'     => __( 'Σύνδεση για επισκέπτες', 'wish-shelf' ),
			't_drop'      => __( 'Πτώση τιμής ({amount})', 'wish-shelf' ),
			't_product'   => __( 'Στήλη: προϊόν', 'wish-shelf' ),
			't_price'     => __( 'Στήλη: τιμή', 'wish-shelf' ),
			't_stock'     => __( 'Στήλη: διαθεσιμότητα', 'wish-shelf' ),
			't_date'      => __( 'Στήλη: ημερομηνία', 'wish-shelf' ),
			't_qty'       => __( 'Στήλη: ποσότητα', 'wish-shelf' ),
			't_cart'      => __( 'Κουμπί «Στο καλάθι»', 'wish-shelf' ),
			't_options'   => __( 'Κουμπί «Επιλογές»', 'wish-shelf' ),
			't_remove'    => __( 'Αφαίρεση (για screen readers)', 'wish-shelf' ),
			't_all'       => __( 'Όλα στο καλάθι', 'wish-shelf' ),
			't_selected'  => __( 'Επιλεγμένα στο καλάθι', 'wish-shelf' ),
			't_clear'     => __( 'Άδειασμα λίστας', 'wish-shelf' ),
			't_confirm'   => __( 'Επιβεβαίωση αδειάσματος', 'wish-shelf' ),
			't_share'     => __( 'Κοινοποίηση', 'wish-shelf' ),
			't_copy'      => __( 'Αντιγραφή συνδέσμου', 'wish-shelf' ),
			't_copied'    => __( 'Ο σύνδεσμος αντιγράφηκε', 'wish-shelf' ),
			't_share_new' => __( 'Νέος σύνδεσμος', 'wish-shelf' ),
			't_share_off' => __( 'Διακοπή κοινοποίησης', 'wish-shelf' ),
			't_skipped'   => __( 'Προϊόντα που δεν μπήκαν στο καλάθι', 'wish-shelf' ),
			't_shared'    => __( 'Τίτλος κοινής λίστας', 'wish-shelf' ),
		);
	}

	private static function render_texts(): void {
		$s = WSH_Settings::get();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="wsh_action" value="save_texts" />
			<h2 class="wsh-h2"><?php esc_html_e( 'Κείμενα για τον πελάτη', 'wish-shelf' ); ?></h2>
			<div class="wsh-zone">
				<p class="wsh-field"><span><?php esc_html_e( 'Γλώσσα κειμένων', 'wish-shelf' ); ?></span>
				<?php
				echo self::select( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in select().
					'wsh_lang',
					array(
						'auto' => __( 'Αυτόματα (γλώσσα του site)', 'wish-shelf' ),
						'el'   => __( 'Ελληνικά', 'wish-shelf' ),
						'en'   => __( 'English', 'wish-shelf' ),
					),
					$s['lang']
				);
				?>
				</p>
				<table class="widefat wsh-table wsh-texts"><thead><tr><th><?php esc_html_e( 'Κείμενο', 'wish-shelf' ); ?></th><th><?php esc_html_e( 'Ελληνικά', 'wish-shelf' ); ?></th><th><?php esc_html_e( 'English', 'wish-shelf' ); ?></th></tr></thead><tbody>
				<?php
				foreach ( self::text_labels() as $key => $label ) {
					echo '<tr><td>' . esc_html( $label ) . '</td>';
					foreach ( array( 'el', 'en' ) as $l ) {
						echo '<td><input type="text" name="wsh_t[' . esc_attr( $key ) . '][' . esc_attr( $l ) . ']" value="' . esc_attr( $s[ $key ][ $l ] ) . '" maxlength="' . (int) WSH_Settings::TEXTS[ $key ] . '" /></td>';
					}
					echo '</tr>';
				}
				?>
				</tbody></table>
				<p class="description wsh-mt-8"><?php esc_html_e( 'Κενό ελληνικό κείμενο = το αρχικό. Κενό αγγλικό = δείχνει το ελληνικό. Τα {product_name} και {amount} γεμίζουν με το όνομα του προϊόντος και το ποσό. Τα μηνύματα του καλαθιού και της διαθεσιμότητας είναι του WooCommerce.', 'wish-shelf' ); ?></p>
			</div>
			<p class="wsh-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'wish-shelf' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: report
	 * ------------------------------------------------------------------- */

	private static function render_report(): void {
		$tot    = WSH_Report::totals();
		$rows   = WSH_Report::rows();
		$fmt    = (string) get_user_option( 'wsh_csv' );
		$fmt    = in_array( $fmt, array( 'excel', 'std' ), true ) ? $fmt : ( 'el' === WSH_Lang::lang() ? 'excel' : 'std' );
		?>
		<h2 class="wsh-h2"><?php esc_html_e( 'Τι θέλουν οι πελάτες', 'wish-shelf' ); ?></h2>
		<div class="wsh-kpis">
			<div class="wsh-kpi"><span class="wsh-kpi__n"><?php echo (int) $tot['customers']; ?></span><span class="wsh-kpi__l"><?php esc_html_e( 'Πελάτες με λίστα', 'wish-shelf' ); ?></span></div>
			<div class="wsh-kpi"><span class="wsh-kpi__n"><?php echo (int) $tot['items']; ?></span><span class="wsh-kpi__l"><?php esc_html_e( 'Προϊόντα σε λίστες', 'wish-shelf' ); ?></span></div>
		</div>
		<p class="description"><?php esc_html_e( 'Μετρούν μόνο οι συνδεδεμένοι πελάτες: η λίστα ενός επισκέπτη μένει στον browser του. Η στήλη «Αγοράστηκαν από λίστα» μετρά γραμμές πληρωμένων παραγγελιών που μπήκαν στο καλάθι από μια λίστα, και των επισκεπτών.', 'wish-shelf' ); ?></p>
		<?php if ( ! $rows ) : ?>
			<p class="wsh-zone"><?php esc_html_e( 'Κανένα προϊόν σε λίστα ακόμα.', 'wish-shelf' ); ?></p>
		<?php else : ?>
			<table class="widefat wsh-table">
				<thead><tr>
					<th><?php esc_html_e( 'Προϊόν', 'wish-shelf' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'wish-shelf' ); ?></th>
					<th class="num"><?php esc_html_e( 'Σε λίστες', 'wish-shelf' ); ?></th>
					<th class="num"><?php esc_html_e( 'Αγοράστηκαν από λίστα', 'wish-shelf' ); ?></th>
					<th><?php esc_html_e( 'Απόθεμα', 'wish-shelf' ); ?></th>
					<th class="num"><?php esc_html_e( 'Τιμή', 'wish-shelf' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( $r['id'] ) ); ?>"><?php echo esc_html( $r['name'] ); ?></a></td>
						<td><?php echo esc_html( $r['sku'] ); ?></td>
						<td class="num"><?php echo (int) $r['wishes']; ?></td>
						<td class="num"><?php echo (int) $r['bought']; ?></td>
						<td><?php echo 'in' === $r['stock'] ? esc_html__( 'Σε απόθεμα', 'wish-shelf' ) : '<span class="wsh-warn-t">' . esc_html__( 'Εξαντλημένο', 'wish-shelf' ) . '</span>'; ?></td>
						<td class="num"><?php echo null === $r['price'] ? '–' : wp_kses_post( wc_price( $r['price'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wsh-inline-form wsh-mt-8">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="tab" value="report" />
			<input type="hidden" name="wsh_csv" value="1" />
			<?php wp_nonce_field( self::NONCE_CSV, '_wpnonce', false ); ?>
			<?php
			echo self::select( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in select().
				'wsh_fmt',
				array(
					'excel' => __( 'Excel ελληνικά (; και κόμμα)', 'wish-shelf' ),
					'std'   => __( 'Τυπικό CSV (, και τελεία)', 'wish-shelf' ),
				),
				$fmt
			);
			?>
			<button type="submit" class="button"><?php esc_html_e( 'Εξαγωγή CSV', 'wish-shelf' ); ?></button>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: tools
	 * ------------------------------------------------------------------- */

	private static function render_tools(): void {
		$s   = WSH_Settings::get();
		$pre = WSH_Import::preview();
		?>
		<h2 class="wsh-h2"><?php esc_html_e( 'Εισαγωγή από WishSuite', 'wish-shelf' ); ?></h2>
		<div class="wsh-zone">
			<p class="description"><?php esc_html_e( 'Αντιγράφει τις λίστες των συνδεδεμένων πελατών από το WishSuite, χωρίς διπλά και χωρίς να αλλάξει τα δεδομένα του. Οι λίστες επισκεπτών του WishSuite είναι στους browsers τους και δεν μεταφέρονται.', 'wish-shelf' ); ?></p>
			<?php if ( ! $pre['table'] ) : ?>
				<p><?php esc_html_e( 'Δεν βρέθηκε πίνακας του WishSuite σε αυτό το site.', 'wish-shelf' ); ?></p>
			<?php else : ?>
				<p><?php echo esc_html( sprintf( /* translators: 1: rows, 2: customers */ __( 'Βρέθηκαν %1$d προϊόντα σε λίστες %2$d πελατών.', 'wish-shelf' ), $pre['rows'], $pre['users'] ) ); ?></p>
				<form method="post" class="wsh-inline-form">
					<?php self::nonce_field(); ?>
					<input type="hidden" name="wsh_action" value="import_wishsuite" />
					<button type="submit" class="button"><?php esc_html_e( 'Εισαγωγή', 'wish-shelf' ); ?></button>
				</form>
			<?php endif; ?>
		</div>

		<h2 class="wsh-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'wish-shelf' ); ?></h2>
		<div class="wsh-zone">
			<form method="post" enctype="multipart/form-data" class="wsh-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'wsh_backup_nonce' ); ?>
				<input type="file" name="wsh_import_file" accept=".json,application/json" />
				<button type="submit" name="wsh_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'wish-shelf' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=tools&wsh_export=1' ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'wish-shelf' ); ?></a>
			<p class="description wsh-mt-8"><?php esc_html_e( 'Το backup έχει μόνο τις ρυθμίσεις και τα κείμενα, όχι τις λίστες των πελατών.', 'wish-shelf' ); ?></p>
		</div>

		<h2 class="wsh-h2"><?php esc_html_e( 'Διαγραφή του plugin', 'wish-shelf' ); ?></h2>
		<div class="wsh-zone">
			<form method="post">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="wsh_action" value="save_uninstall" />
				<?php echo self::check( 'wsh_delete_data', $s['delete_data'], __( 'Διαγραφή των λιστών των πελατών όταν διαγραφεί το plugin', 'wish-shelf' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="description"><?php esc_html_e( 'Χωρίς το τικ οι λίστες και οι σύνδεσμοι κοινοποίησης μένουν, ώστε μια νέα εγκατάσταση να τις βρει. Οι ρυθμίσεις και οι μετρητές των προϊόντων φεύγουν πάντα.', 'wish-shelf' ); ?></p>
				<p class="wsh-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'wish-shelf' ); ?></button></p>
			</form>
		</div>

		<h2 class="wsh-h2"><?php esc_html_e( 'Για προγραμματιστές', 'wish-shelf' ); ?></h2>
		<div class="wsh-zone">
			<table class="widefat wsh-table">
				<tbody>
					<tr><td><code>wsh_get_items( $user_id )</code></td><td><?php esc_html_e( 'Η λίστα ενός συνδεδεμένου πελάτη: προϊόν, παραλλαγή, ημερομηνία, τιμή κατά την προσθήκη.', 'wish-shelf' ); ?></td></tr>
					<tr><td><code>wsh_product_count( $product_id )</code></td><td><?php esc_html_e( 'Σε πόσες λίστες πελατών είναι ένα προϊόν.', 'wish-shelf' ); ?></td></tr>
					<tr><td><code>wsh_item_added</code>, <code>wsh_item_removed</code></td><td><?php esc_html_e( 'Actions όταν αλλάζει η λίστα ενός συνδεδεμένου πελάτη ( $user_id, $product_id, $variation_id ).', 'wish-shelf' ); ?></td></tr>
					<tr><td><code>wsh_text</code>, <code>wsh_max_items</code></td><td><?php esc_html_e( 'Φίλτρα για τα κείμενα του πελάτη (π.χ. WPML) και το όριο προϊόντων ανά λίστα (200).', 'wish-shelf' ); ?></td></tr>
					<tr><td><code>[nox_wishlist]</code>, <code>[nox_wishlist_button id=""]</code>, <code>[nox_wishlist_count]</code></td><td><?php esc_html_e( 'Shortcodes για τη λίστα, το κουμπί και τον μετρητή (και ως blocks).', 'wish-shelf' ); ?></td></tr>
					<tr><td><code>WSH_DISABLE</code></td><td><?php esc_html_e( 'Σταθερά στο wp-config.php: σταματά κάθε αλλαγή στο front end.', 'wish-shelf' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* =====================================================================
	 * Routes
	 * =================================================================== */

	private static function nonce_field(): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
	}

	public static function route(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}

		// Settings import (own nonce, multipart form).
		if ( isset( $_POST['wsh_import'] ) ) {
			if ( ! isset( $_POST['wsh_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wsh_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'wish-shelf' ) );
			}
			self::redirect( 'tools', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['wsh_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'wish-shelf' ) );
		}

		$saved = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'wish-shelf' ) ) );
		switch ( sanitize_key( wp_unslash( $_POST['wsh_action'] ) ) ) {
			case 'save_general':
				self::do_save_general();
				self::redirect( 'general', $saved );
				break;
			case 'save_list':
				self::redirect( 'list', self::do_save_list() );
				break;
			case 'save_texts':
				self::do_save_texts();
				self::redirect( 'texts', $saved );
				break;
			case 'save_uninstall':
				$s                = WSH_Settings::get();
				$s['delete_data'] = '1' === self::post( 'wsh_delete_data' );
				WSH_Settings::save( $s );
				self::redirect( 'tools', $saved );
				break;
			case 'import_wishsuite':
				$r = WSH_Import::import_wishsuite();
				self::redirect(
					'tools',
					array(
						self::msg(
							'success',
							sprintf(
								/* translators: 1: items added, 2: items skipped */
								__( 'Η εισαγωγή ολοκληρώθηκε: %1$d προϊόντα μπήκαν σε λίστες, %2$d παραλείφθηκαν (υπήρχαν ήδη ή δεν υπάρχουν πια).', 'wish-shelf' ),
								$r['added'],
								$r['skipped']
							)
						),
					)
				);
				break;
			default:
				self::redirect( 'general', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'wish-shelf' ) ) ) );
		}
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		set_transient( 'wsh_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/** A posted array, unslashed (sanitized by WSH_Settings). */
	private static function post_array( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(), sanitized by WSH_Settings.
		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
	}

	private static function do_save_general(): void {
		$s           = WSH_Settings::get();
		$s['mode']   = sanitize_key( self::post( 'wsh_mode' ) );
		$s['guests'] = sanitize_key( self::post( 'wsh_guests' ) );
		$s['style']  = sanitize_key( self::post( 'wsh_style' ) );
		$s['toast']  = '1' === self::post( 'wsh_toast' );
		$s['loop']   = array(
			'enabled'  => '1' === self::post( 'wsh_loop_enabled' ),
			'position' => sanitize_key( self::post( 'wsh_loop_position' ) ),
		);
		$s['single'] = array(
			'enabled'  => '1' === self::post( 'wsh_single_enabled' ),
			'position' => sanitize_key( self::post( 'wsh_single_position' ) ),
		);
		WSH_Settings::save( $s );
	}

	private static function do_save_list(): array {
		$s            = WSH_Settings::get();
		$s['page_id'] = (int) self::post( 'wsh_page_id' );
		$s['layout']  = sanitize_key( self::post( 'wsh_layout' ) );
		$cols         = self::post_array( 'wsh_cols' );
		foreach ( WSH_Settings::COLS as $c ) {
			$s['cols'][ $c ] = isset( $cols[ $c ] ) && '1' === $cols[ $c ];
		}
		foreach ( array( 'price_drop', 'bulk', 'remove_after', 'account', 'count_zero', 'share' ) as $k ) {
			$s[ $k ] = '1' === self::post( 'wsh_' . $k );
		}
		$s['endpoint'] = self::post( 'wsh_endpoint' );
		$s['menu']     = self::post( 'wsh_menu' );
		if ( '' !== $s['menu'] && ! isset( get_registered_nav_menus()[ $s['menu'] ] ) ) {
			$s['menu'] = '';
		}
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'wish-shelf' ) ) );

		if ( '1' === self::post( 'wsh_create_page' ) ) {
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => WSH_Settings::text( 't_title' ),
					'post_content' => "<!-- wp:shortcode -->\n[nox_wishlist]\n<!-- /wp:shortcode -->",
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				$msgs[] = self::msg( 'error', __( 'Η σελίδα δεν δημιουργήθηκε.', 'wish-shelf' ) );
			} else {
				$s['page_id'] = (int) $id;
				$msgs[]       = self::msg( 'success', __( 'Η σελίδα δημιουργήθηκε και επιλέχθηκε.', 'wish-shelf' ) );
			}
		}
		WSH_Settings::save( $s );
		return $msgs;
	}

	private static function do_save_texts(): void {
		$s         = WSH_Settings::get();
		$s['lang'] = sanitize_key( self::post( 'wsh_lang' ) );
		$t         = self::post_array( 'wsh_t' );
		foreach ( array_keys( WSH_Settings::TEXTS ) as $k ) {
			if ( isset( $t[ $k ] ) && is_array( $t[ $k ] ) ) {
				$s[ $k ] = array(
					'el' => isset( $t[ $k ]['el'] ) && is_string( $t[ $k ]['el'] ) ? $t[ $k ]['el'] : '',
					'en' => isset( $t[ $k ]['en'] ) && is_string( $t[ $k ]['en'] ) ? $t[ $k ]['en'] : '',
				);
			}
		}
		WSH_Settings::save( $s );
	}

	/* =====================================================================
	 * Privileged GET: settings export, report CSV
	 * =================================================================== */

	public static function route_get(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		if ( '' !== self::q( 'wsh_csv' ) ) {
			if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_CSV ) || ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'wish-shelf' ) );
			}
			$fmt = 'std' === self::q( 'wsh_fmt' ) ? 'std' : 'excel';
			update_user_option( get_current_user_id(), 'wsh_csv', $fmt );
			WSH_Report::csv( $fmt );
		}
		if ( '' === self::q( 'wsh_export' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'wish-shelf' ) );
		}
		$state = array(
			'plugin'  => 'wish-shelf',
			'version' => WSH_VERSION,
			'options' => array(
				WSH_Settings::OPT => WSH_Settings::get(),
			),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="wish-shelf-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	/** Strict import (Bible §11): the blob goes through the same validation as the forms. */
	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['wsh_import_file']['error'] ) ? (int) $_FILES['wsh_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['wsh_import_file']['size'] ) ? (int) $_FILES['wsh_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'wish-shelf' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['wsh_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['wsh_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'wish-shelf' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['wsh_import_file']['tmp_name'] ), true );
		// phpcs:enable
		if ( ! is_array( $state ) || ! isset( $state['options'] ) || ! is_array( $state['options'] ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'wish-shelf' ) ) );
		}
		$blob = isset( $state['options'][ WSH_Settings::OPT ] ) ? $state['options'][ WSH_Settings::OPT ] : null;
		if ( ! is_array( $blob ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Wish Shelf).', 'wish-shelf' ) ) );
		}
		WSH_Settings::save( $blob );
		return array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'wish-shelf' ) ) );
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'wsh_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'wsh_aui_msg_' . get_current_user_id() );
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
		<p class="wsh-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'wish-shelf' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="wsh-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="wsh-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'wish-shelf' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="wsh-footer-cta">
			<a class="wsh-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'wish-shelf' ); ?>
			</a>
			<a class="wsh-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'wish-shelf' ); ?>
			</a>
		</p>
		<?php
	}
}

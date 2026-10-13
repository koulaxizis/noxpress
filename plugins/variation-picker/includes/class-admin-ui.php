<?php
/**
 * VPK_Admin_UI — menu, settings page, PRG routes, backup (Bible §3–§8, §11).
 *
 * One page, submenu «Swatches» of the shared "Noxpress" menu (admin_menu
 * priority 130), slug vpk-settings, with tabs:
 *  - general     mode, other swatches plugins, default type, look (with a
 *                live preview), behaviour, tooltip info, cart dot
 *  - attributes  type / shape / size of every attribute
 *  - shop        swatches on shop cards
 *  - guides      size guides
 *  - texts       visitor text language and texts
 *  - tools       import from other plugins, availability maps, backup,
 *                uninstall choice, filters and API
 *
 * Every state change: POST on admin_init, slug + nonce + capability,
 * strict validation (VPK_Settings::sanitize*), PRG with the message in the
 * transient vpk_aui_msg_{uid}. Export: privileged GET (slug + nonce +
 * capability).
 */

defined( 'ABSPATH' ) || exit;

final class VPK_Admin_UI {

	const CAP         = 'manage_woocommerce';
	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'vpk-settings';
	const NONCE       = 'vpk_admin';
	const NONCE_FIELD = 'vpk_nonce';
	const NONCE_BAK   = 'vpk_backup';
	const MAX_IMPORT  = 524288;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 130 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( VPK_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label msgid. */
	private static function tabs(): array {
		return array(
			'general'    => __( 'Γενικά', 'variation-picker' ),
			'attributes' => __( 'Χαρακτηριστικά', 'variation-picker' ),
			'shop'       => __( 'Κατάστημα', 'variation-picker' ),
			'guides'     => __( 'Οδηγοί μεγεθών', 'variation-picker' ),
			'texts'      => __( 'Κείμενα', 'variation-picker' ),
			'tools'      => __( 'Εργαλεία', 'variation-picker' ),
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
			__( 'Variation Picker', 'variation-picker' ),
			__( 'Swatches', 'variation-picker' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'variation-picker' ) . '</a>'
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

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'vpk-admin', VPK_URL . 'assets/admin.css', array(), VPK_VERSION );
		$tab = self::current_tab();
		if ( 'general' === $tab ) {
			// Live preview: the real front-end CSS on sample swatches.
			wp_enqueue_style( 'vpk-front', VPK_URL . 'assets/front.css', array(), VPK_VERSION );
			wp_enqueue_script( 'vpk-admin', VPK_URL . 'assets/admin.js', array(), VPK_VERSION, true );
		}
		if ( 'guides' === $tab ) {
			wp_enqueue_media();
			wp_enqueue_script( 'vpk-terms', VPK_URL . 'assets/terms.js', array( 'jquery' ), VPK_VERSION, true );
			wp_localize_script(
				'vpk-terms',
				'vpkTerms',
				array(
					'choose' => __( 'Επιλογή εικόνας', 'variation-picker' ),
					'use'    => __( 'Χρήση εικόνας', 'variation-picker' ),
				)
			);
		}
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'variation-picker' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap vpk-wrap">
			<h1><?php esc_html_e( 'Variation Picker', 'variation-picker' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Swatches για τις παραλλαγές: κουμπιά, χρώματα, εικόνες, οδηγός μεγεθών και swatches στο κατάστημα.', 'variation-picker' ); ?></p>

			<?php self::print_notices( self::take_msgs() ); ?>

			<nav class="nav-tab-wrapper vpk-tabs">
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
				case 'attributes':
					self::render_attributes();
					break;
				case 'shop':
					self::render_shop();
					break;
				case 'guides':
					self::render_guides();
					break;
				case 'texts':
					self::render_texts();
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
	 * @param string $id      Optional id.
	 */
	private static function select( string $name, array $opts, string $current, string $id = '' ): string {
		$html = '<select name="' . esc_attr( $name ) . '"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>';
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
		return '<label class="vpk-check"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $on, true, false ) . ' /> ' . esc_html( $label ) . '</label>';
	}

	/** Display modes for unavailable / out-of-stock values. */
	private static function display_opts(): array {
		return array(
			'blur'  => __( 'Θολό', 'variation-picker' ),
			'cross' => __( 'Διαγραμμένο (×)', 'variation-picker' ),
			'hide'  => __( 'Κρυφό', 'variation-picker' ),
		);
	}

	/** Attribute taxonomies: taxonomy => label. */
	private static function attribute_list(): array {
		$out = array();
		foreach ( wc_get_attribute_taxonomies() as $a ) {
			$tax = wc_attribute_taxonomy_name( $a->attribute_name );
			if ( VPK_Settings::valid_taxonomy_name( $tax ) ) {
				$out[ $tax ] = (string) $a->attribute_label;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Tab: general
	 * ------------------------------------------------------------------- */

	private static function render_general(): void {
		$s = VPK_Settings::get();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="vpk_action" value="save_general" />

			<h2 class="vpk-h2"><?php esc_html_e( 'Λειτουργία', 'variation-picker' ); ?></h2>
			<div class="vpk-zone">
				<?php
				if ( Variation_Picker::killed() ) {
					echo '<p class="vpk-warn">' . esc_html__( 'Το VPK_DISABLE είναι ενεργό στο wp-config.php: τα swatches δεν εμφανίζονται πουθενά.', 'variation-picker' ) . '</p>';
				}
				$others = VPK_Compat::active_names();
				if ( $others ) {
					echo '<p class="vpk-warn">' . esc_html(
						sprintf(
							/* translators: %s: plugin names */
							__( 'Είναι ενεργό και άλλο plugin για swatches: %s. Όσο είναι ενεργό, το Variation Picker μένει σε λειτουργία δοκιμής (μόνο οι διαχειριστές το βλέπουν), ώστε ο επισκέπτης να μη δει διπλά swatches. Απενεργοποίησέ το όταν είσαι έτοιμος.', 'variation-picker' ),
							implode( ', ', $others )
						)
					) . '</p>';
				}
				$modes = array(
					'off'  => __( 'Ανενεργό', 'variation-picker' ),
					'test' => __( 'Λειτουργία δοκιμής: φαίνεται μόνο στους διαχειριστές του καταστήματος', 'variation-picker' ),
					'live' => __( 'Ενεργό για όλους', 'variation-picker' ),
				);
				foreach ( $modes as $k => $label ) {
					echo '<label class="vpk-check"><input type="radio" name="vpk_mode" value="' . esc_attr( $k ) . '" ' . checked( $s['mode'], $k, false ) . ' /> ' . esc_html( $label ) . '</label>';
				}
				echo '<p class="description">' . esc_html__( 'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή', 'variation-picker' ) . ' <code>define( \'VPK_DISABLE\', true );</code></p>';
				?>
				<p class="vpk-field"><span><?php esc_html_e( 'Χαρακτηριστικά χωρίς ρύθμιση', 'variation-picker' ); ?></span>
				<?php
				echo self::select( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in select().
					'vpk_auto',
					array(
						'button' => __( 'Γίνονται κουμπιά με κείμενο', 'variation-picker' ),
						'select' => __( 'Μένουν dropdown', 'variation-picker' ),
					),
					$s['auto']
				);
				?>
				</p>
			</div>

			<h2 class="vpk-h2"><?php esc_html_e( 'Εμφάνιση', 'variation-picker' ); ?></h2>
			<div class="vpk-zone vpk-look">
				<div class="vpk-look__fields">
					<p class="vpk-field"><span><?php esc_html_e( 'Σχήμα', 'variation-picker' ); ?></span><?php echo self::select( 'vpk_shape', VPK_Admin_Terms::shape_labels(), $s['shape'], 'vpk_shape' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
					<p class="vpk-field"><span><?php esc_html_e( 'Μέγεθος (px)', 'variation-picker' ); ?></span><input type="number" id="vpk_size" name="vpk_size" min="20" max="80" value="<?php echo (int) $s['size']; ?>" /></p>
					<p class="vpk-field"><span><?php esc_html_e( 'Μέγεθος γραμμάτων (px)', 'variation-picker' ); ?></span><input type="number" id="vpk_font" name="vpk_font" min="10" max="20" value="<?php echo (int) $s['font']; ?>" /></p>
					<p class="vpk-field"><span><?php esc_html_e( 'Πάχος περιγράμματος (px)', 'variation-picker' ); ?></span><input type="number" id="vpk_border" name="vpk_border" min="0" max="4" value="<?php echo (int) $s['border']; ?>" /></p>
					<p class="vpk-field"><span><?php esc_html_e( 'Χρώμα περιγράμματος', 'variation-picker' ); ?></span><input type="color" id="vpk_border_color" name="vpk_border_color" value="<?php echo esc_attr( $s['border_color'] ); ?>" /></p>
					<p class="vpk-field"><span><?php esc_html_e( 'Χρώμα επιλεγμένου', 'variation-picker' ); ?></span><input type="color" id="vpk_active_color" name="vpk_active_color" value="<?php echo esc_attr( $s['active_color'] ); ?>" /></p>
				</div>
				<div class="vpk-preview" aria-label="<?php esc_attr_e( 'Προεπισκόπηση', 'variation-picker' ); ?>">
					<div class="vpk-root vpk-preview__root" id="vpk-preview">
						<div class="vpk-list vpk-list--color vpk-shape--<?php echo esc_attr( $s['shape'] ); ?>">
							<span class="vpk-sw vpk-sw--color vpk-is-on"><span class="vpk-sw__color" style="background:#141414"></span></span>
							<span class="vpk-sw vpk-sw--color"><span class="vpk-sw__color" style="background:#ffffff"></span></span>
							<span class="vpk-sw vpk-sw--color"><span class="vpk-sw__color" style="background:linear-gradient(135deg,#1e3a8a 50%,#f5f5f5 50%)"></span></span>
							<span class="vpk-sw vpk-sw--color vpk-is-unavail vpk-mode-blur"><span class="vpk-sw__color" style="background:#c0392b"></span></span>
						</div>
						<div class="vpk-list vpk-list--button vpk-shape--<?php echo esc_attr( $s['shape'] ); ?>">
							<span class="vpk-sw vpk-sw--label"><span class="vpk-sw__label">S</span></span>
							<span class="vpk-sw vpk-sw--label vpk-is-on"><span class="vpk-sw__label">M</span></span>
							<span class="vpk-sw vpk-sw--label"><span class="vpk-sw__label">L</span></span>
							<span class="vpk-sw vpk-sw--label vpk-is-oos vpk-mode-cross"><span class="vpk-sw__label">XL</span></span>
						</div>
					</div>
				</div>
			</div>

			<h2 class="vpk-h2"><?php esc_html_e( 'Συμπεριφορά', 'variation-picker' ); ?></h2>
			<div class="vpk-zone">
				<p class="vpk-field"><span><?php esc_html_e( 'Συνδυασμοί που δεν υπάρχουν', 'variation-picker' ); ?></span><?php echo self::select( 'vpk_unavailable', self::display_opts(), $s['unavailable'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
				<p class="vpk-field"><span><?php esc_html_e( 'Εκτός αποθέματος', 'variation-picker' ); ?></span><?php echo self::select( 'vpk_oos', self::display_opts(), $s['oos'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
				<p class="vpk-field"><span><?php esc_html_e( 'Tooltip', 'variation-picker' ); ?></span>
				<?php
				echo self::select( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in select().
					'vpk_tooltip',
					array(
						'none'  => __( 'Κανένα', 'variation-picker' ),
						'text'  => __( 'Όνομα', 'variation-picker' ),
						'image' => __( 'Όνομα και εικόνα', 'variation-picker' ),
					),
					$s['tooltip']
				);
				?>
				</p>
				<?php
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in check().
				echo self::check( 'vpk_selected_label', $s['selected_label'], __( 'Η επιλεγμένη τιμή δίπλα στο όνομα (π.χ. «Χρώμα: Μαύρο»)', 'variation-picker' ) );
				echo self::check( 'vpk_auto_select', $s['auto_select'], __( 'Αυτόματη επιλογή όταν μένει μόνο μία δυνατή τιμή', 'variation-picker' ) );
				echo self::check( 'vpk_deselect', $s['deselect'], __( 'Δεύτερο κλικ στην επιλεγμένη τιμή την αποεπιλέγει', 'variation-picker' ) );
				echo self::check( 'vpk_url_sync', $s['url_sync'], __( 'Οι επιλογές μπαίνουν στη διεύθυνση της σελίδας (ο σύνδεσμος ανοίγει την ίδια παραλλαγή)', 'variation-picker' ) );
				// phpcs:enable
				?>
				<p class="description"><?php esc_html_e( 'Ένα κλικ σε τιμή που δεν ταιριάζει με τις άλλες επιλογές την επιλέγει και καθαρίζει τις υπόλοιπες.', 'variation-picker' ); ?></p>
			</div>

			<h2 class="vpk-h2"><?php esc_html_e( 'Πληροφορίες στο tooltip', 'variation-picker' ); ?></h2>
			<div class="vpk-zone">
				<?php echo self::check( 'vpk_price_diff', $s['price_diff'], __( 'Διαφορά τιμής (π.χ. «+3,00 €») όταν μια τιμή κοστίζει περισσότερο', 'variation-picker' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="vpk-field"><span><?php esc_html_e( '«Μόνο N διαθέσιμα» από απόθεμα', 'variation-picker' ); ?></span><input type="number" name="vpk_low_stock" min="0" max="100" value="<?php echo (int) $s['low_stock']; ?>" /> <span class="vpk-hint"><?php esc_html_e( '0 = ποτέ. Μόνο για παραλλαγές με διαχείριση αποθέματος.', 'variation-picker' ); ?></span></p>
			</div>

			<h2 class="vpk-h2"><?php esc_html_e( 'Καλάθι', 'variation-picker' ); ?></h2>
			<div class="vpk-zone">
				<?php echo self::check( 'vpk_cart_dot', $s['cart_dot'], __( 'Χρωματιστή κουκκίδα δίπλα στο χρώμα στο καλάθι, στο mini-cart και στο ταμείο', 'variation-picker' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="description"><?php esc_html_e( 'Για χαρακτηριστικά τύπου «Χρώμα». Το Cart / Checkout block δεν τη δείχνει.', 'variation-picker' ); ?></p>
			</div>

			<p class="vpk-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'variation-picker' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: attributes
	 * ------------------------------------------------------------------- */

	private static function render_attributes(): void {
		$list = self::attribute_list();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="vpk_action" value="save_attributes" />
			<h2 class="vpk-h2"><?php esc_html_e( 'Χαρακτηριστικά', 'variation-picker' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Ο τύπος κάθε χαρακτηριστικού στη σελίδα προϊόντος. Κενό = η γενική ρύθμιση. Χρώματα, εικόνες και σύντομες ετικέτες ορίζονται στις τιμές («Τιμές»). Ένα προϊόν μπορεί να αλλάξει τον τύπο στην καρτέλα «Swatches».', 'variation-picker' ); ?></p>
			<?php if ( ! $list ) : ?>
				<p class="vpk-zone"><?php esc_html_e( 'Δεν υπάρχουν χαρακτηριστικά στο WooCommerce.', 'variation-picker' ); ?></p>
			<?php else : ?>
			<table class="widefat vpk-table">
				<thead><tr>
					<th><?php esc_html_e( 'Χαρακτηριστικό', 'variation-picker' ); ?></th>
					<th><?php esc_html_e( 'Τύπος', 'variation-picker' ); ?></th>
					<th><?php esc_html_e( 'Σχήμα', 'variation-picker' ); ?></th>
					<th><?php esc_html_e( 'Μέγεθος (px)', 'variation-picker' ); ?></th>
					<th><?php esc_html_e( 'Τιμές', 'variation-picker' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				$general = VPK_Settings::get();
				foreach ( $list as $tax => $label ) {
					$a     = VPK_Settings::attr( $tax );
					$types = array( '' => __( 'Γενική ρύθμιση', 'variation-picker' ) ) + VPK_Admin_Terms::type_labels();
					$shps  = array( '' => __( 'Γενική ρύθμιση', 'variation-picker' ) ) + VPK_Admin_Terms::shape_labels();
					$terms = get_terms(
						array(
							'taxonomy'   => $tax,
							'hide_empty' => false,
							'number'     => 8,
						)
					);
					$prev  = '';
					if ( ! is_wp_error( $terms ) ) {
						foreach ( $terms as $t ) {
							$prev .= VPK_Admin_Terms::preview( VPK_Swatch::term( (int) $t->term_id ) );
						}
					}
					$count = (int) wp_count_terms(
						array(
							'taxonomy'   => $tax,
							'hide_empty' => false,
						)
					);
					$name  = 'vpk_attrs[' . $tax . ']';
					echo '<tr><td><strong>' . esc_html( $label ) . '</strong><br /><code>' . esc_html( $tax ) . '</code></td>';
					echo '<td>' . self::select( $name . '[type]', $types, $a['type'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<td>' . self::select( $name . '[shape]', $shps, $a['shape'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<td><input type="number" class="vpk-num" name="' . esc_attr( $name ) . '[size]" min="20" max="80" value="' . ( $a['size'] ? (int) $a['size'] : '' ) . '" placeholder="' . (int) $general['size'] . '" /></td>';
					echo '<td><span class="vpk-prevs">' . $prev . '</span> <a href="' . esc_url( admin_url( 'edit-tags.php?taxonomy=' . rawurlencode( $tax ) . '&post_type=product' ) ) . '">' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $prev escaped in preview().
						. esc_html( sprintf( /* translators: %d: number of values */ __( '%d τιμές', 'variation-picker' ), $count ) ) . '</a></td></tr>';
				}
				?>
				</tbody>
			</table>
			<?php endif; ?>
			<p class="vpk-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'variation-picker' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: shop
	 * ------------------------------------------------------------------- */

	private static function render_shop(): void {
		$s  = VPK_Settings::get()['shop'];
		$at = array( '' => __( 'Αυτόματα: το πρώτο χαρακτηριστικό με χρώμα ή εικόνα', 'variation-picker' ) ) + self::attribute_list();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="vpk_action" value="save_shop" />
			<h2 class="vpk-h2"><?php esc_html_e( 'Swatches στις κάρτες προϊόντων', 'variation-picker' ); ?></h2>
			<div class="vpk-zone">
				<?php echo self::check( 'vpk_shop[enabled]', $s['enabled'], __( 'Swatches στο κατάστημα, στις κατηγορίες και στα σχετικά προϊόντα', 'variation-picker' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="vpk-field"><span><?php esc_html_e( 'Χαρακτηριστικό', 'variation-picker' ); ?></span><?php echo self::select( 'vpk_shop[attr]', $at, $s['attr'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
				<p class="vpk-field"><span><?php esc_html_e( 'Θέση', 'variation-picker' ); ?></span>
				<?php
				echo self::select( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in select().
					'vpk_shop[position]',
					array(
						'after_title'  => __( 'Μετά τον τίτλο', 'variation-picker' ),
						'after_price'  => __( 'Μετά την τιμή', 'variation-picker' ),
						'after_button' => __( 'Μετά το κουμπί', 'variation-picker' ),
					),
					$s['position']
				);
				?>
				</p>
				<p class="vpk-field"><span><?php esc_html_e( 'Μέγιστος αριθμός', 'variation-picker' ); ?></span><input type="number" name="vpk_shop[limit]" min="0" max="20" value="<?php echo (int) $s['limit']; ?>" /> <span class="vpk-hint"><?php esc_html_e( '0 = όλα. Τα υπόλοιπα γίνονται «+N».', 'variation-picker' ); ?></span></p>
				<p class="vpk-field"><span><?php esc_html_e( 'Μέγεθος (px)', 'variation-picker' ); ?></span><input type="number" name="vpk_shop[size]" min="14" max="48" value="<?php echo (int) $s['size']; ?>" /></p>
				<?php
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in check().
				echo self::check( 'vpk_shop[swap_image]', $s['swap_image'], __( 'Hover ή focus αλλάζει την εικόνα της κάρτας στην εικόνα της παραλλαγής', 'variation-picker' ) );
				echo self::check( 'vpk_shop[preselect]', $s['preselect'], __( 'Ο σύνδεσμος ανοίγει το προϊόν με την τιμή ήδη επιλεγμένη', 'variation-picker' ) );
				// phpcs:enable
				?>
				<p class="description"><?php esc_html_e( 'Για τα κλασικά πρότυπα καταστήματος (και τις κάρτες του Theme Patcher). Στα block themes, το WooCommerce τα δείχνει στο κατάστημα, στις κατηγορίες και στην αναζήτηση, κάτω από τον τίτλο· όχι σε μπλοκ Product Collection σε άλλες σελίδες. Ένα προϊόν χωρίς χάρτη διαθεσιμότητας δεν δείχνει swatches μέχρι να φτιαχτεί (Εργαλεία).', 'variation-picker' ); ?></p>
			</div>
			<p class="vpk-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'variation-picker' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: guides
	 * ------------------------------------------------------------------- */

	private static function render_guides(): void {
		$guides = VPK_Settings::guides();
		$cats   = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 500,
			)
		);
		$cats   = is_wp_error( $cats ) ? array() : $cats;
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="vpk_action" value="save_guides" />
			<h2 class="vpk-h2"><?php esc_html_e( 'Οδηγοί μεγεθών', 'variation-picker' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Ένα κουμπί «Οδηγός μεγεθών» δίπλα στο χαρακτηριστικό ανοίγει τον πίνακα. Ένα προϊόν παίρνει τον πρώτο ενεργό οδηγό των κατηγοριών του (και των γονικών τους)· στην καρτέλα «Swatches» του προϊόντος διαλέγεις άλλον ή κανέναν. Πίνακας: μία γραμμή ανά σειρά, κελιά με «|», η πρώτη γραμμή είναι η κεφαλίδα.', 'variation-picker' ); ?></p>
			<?php
			$i = 0;
			foreach ( $guides as $g ) {
				self::guide_card( $i++, $g, $cats );
			}
			if ( $i < VPK_Settings::MAX_GUIDES ) {
				self::guide_card( $i, null, $cats );
			}
			?>
			<p class="vpk-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'variation-picker' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * One guide card (an empty one for a new guide).
	 *
	 * @param int        $i    Row index.
	 * @param array|null $g    Guide, or null.
	 * @param WP_Term[]  $cats Product categories.
	 */
	private static function guide_card( int $i, ?array $g, array $cats ): void {
		$new  = null === $g;
		$g    = $new ? array(
			'id'       => '',
			'active'   => true,
			'title_el' => '',
			'title_en' => '',
			'attr'     => '',
			'cats'     => array(),
			'table_el' => "Μέγεθος | Στήθος (cm) | Μήκος (cm)\nS | 96 | 70\nM | 102 | 72",
			'table_en' => '',
			'note_el'  => '',
			'note_en'  => '',
			'image'    => 0,
		) : $g;
		$name = 'vpk_g[' . $i . ']';
		$src  = $g['image'] ? (string) wp_get_attachment_image_url( (int) $g['image'], 'thumbnail' ) : '';
		$at   = array( '' => __( 'Κάτω από το τελευταίο χαρακτηριστικό', 'variation-picker' ) ) + self::attribute_list();
		?>
		<fieldset class="vpk-zone vpk-card<?php echo $new ? ' vpk-card--new' : ''; ?>">
			<legend><?php echo $new ? esc_html__( 'Νέος οδηγός', 'variation-picker' ) : esc_html( $g['title_el'] ); ?></legend>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $g['id'] ); ?>" />
			<div class="vpk-grid">
				<label class="vpk-label"><?php esc_html_e( 'Τίτλος (Ελληνικά)', 'variation-picker' ); ?>
					<input type="text" class="vpk-wide" name="<?php echo esc_attr( $name ); ?>[title_el]" value="<?php echo esc_attr( $new ? '' : $g['title_el'] ); ?>" maxlength="100" placeholder="<?php echo $new ? esc_attr__( 'π.χ. Μεγέθη T-shirt', 'variation-picker' ) : ''; ?>" />
				</label>
				<label class="vpk-label"><?php esc_html_e( 'Τίτλος (English)', 'variation-picker' ); ?>
					<input type="text" class="vpk-wide" name="<?php echo esc_attr( $name ); ?>[title_en]" value="<?php echo esc_attr( $g['title_en'] ); ?>" maxlength="100" />
				</label>
				<label class="vpk-label"><?php esc_html_e( 'Πίνακας (Ελληνικά)', 'variation-picker' ); ?>
					<textarea class="vpk-wide vpk-mono" rows="5" name="<?php echo esc_attr( $name ); ?>[table_el]"><?php echo esc_textarea( $new ? '' : $g['table_el'] ); ?></textarea>
					<?php if ( $new ) : ?><span class="vpk-hint"><?php echo esc_html( str_replace( "\n", ' ⏎ ', $g['table_el'] ) ); ?></span><?php endif; ?>
				</label>
				<label class="vpk-label"><?php esc_html_e( 'Πίνακας (English)', 'variation-picker' ); ?>
					<textarea class="vpk-wide vpk-mono" rows="5" name="<?php echo esc_attr( $name ); ?>[table_en]"><?php echo esc_textarea( $g['table_en'] ); ?></textarea>
				</label>
				<label class="vpk-label"><?php esc_html_e( 'Σημείωση (Ελληνικά)', 'variation-picker' ); ?>
					<input type="text" class="vpk-wide" name="<?php echo esc_attr( $name ); ?>[note_el]" value="<?php echo esc_attr( $g['note_el'] ); ?>" maxlength="300" />
				</label>
				<label class="vpk-label"><?php esc_html_e( 'Σημείωση (English)', 'variation-picker' ); ?>
					<input type="text" class="vpk-wide" name="<?php echo esc_attr( $name ); ?>[note_en]" value="<?php echo esc_attr( $g['note_en'] ); ?>" maxlength="300" />
				</label>
				<label class="vpk-label"><?php esc_html_e( 'Δίπλα στο χαρακτηριστικό', 'variation-picker' ); ?>
					<?php echo self::select( $name . '[attr]', $at, $g['attr'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</label>
				<label class="vpk-label"><?php esc_html_e( 'Κατηγορίες (καμία = όλα τα προϊόντα)', 'variation-picker' ); ?>
					<select class="vpk-wide" name="<?php echo esc_attr( $name ); ?>[cats][]" multiple size="5">
						<?php
						foreach ( $cats as $c ) {
							echo '<option value="' . (int) $c->term_id . '" ' . selected( in_array( (int) $c->term_id, $g['cats'], true ), true, false ) . '>' . esc_html( $c->name ) . '</option>';
						}
						?>
					</select>
				</label>
				<span class="vpk-label vpk-image-field"><?php esc_html_e( 'Εικόνα (προαιρετικό)', 'variation-picker' ); ?>
					<img class="vpk-image-preview" src="<?php echo esc_url( $src ); ?>" alt=""<?php echo '' === $src ? ' hidden' : ''; ?> />
					<input type="hidden" class="vpk-image-id" name="<?php echo esc_attr( $name ); ?>[image]" value="<?php echo $g['image'] ? (int) $g['image'] : ''; ?>" />
					<span><button type="button" class="button vpk-image-pick"><?php esc_html_e( 'Επιλογή εικόνας', 'variation-picker' ); ?></button>
					<button type="button" class="button-link vpk-image-clear"<?php echo '' === $src ? ' hidden' : ''; ?>><?php esc_html_e( 'Αφαίρεση', 'variation-picker' ); ?></button></span>
				</span>
			</div>
			<p class="vpk-row">
				<label class="vpk-inline"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[active]" value="1" <?php checked( $g['active'] ); ?> /> <?php esc_html_e( 'Ενεργός', 'variation-picker' ); ?></label>
				<?php if ( ! $new ) : ?>
					<label class="vpk-inline vpk-warn"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete]" value="1" /> <?php esc_html_e( 'Διαγραφή', 'variation-picker' ); ?></label>
				<?php endif; ?>
			</p>
		</fieldset>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: texts
	 * ------------------------------------------------------------------- */

	private static function render_texts(): void {
		$s    = VPK_Settings::get();
		$rows = array(
			't_guide'   => __( 'Κουμπί του οδηγού μεγεθών', 'variation-picker' ),
			't_low'     => __( 'Χαμηλό απόθεμα (%d = ποσότητα)', 'variation-picker' ),
			't_close'   => __( 'Κλείσιμο του οδηγού (για screen readers)', 'variation-picker' ),
			't_unavail' => __( 'Συνδυασμός που δεν υπάρχει (tooltip)', 'variation-picker' ),
			't_oos'     => __( 'Εκτός αποθέματος (tooltip)', 'variation-picker' ),
		);
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="vpk_action" value="save_texts" />
			<h2 class="vpk-h2"><?php esc_html_e( 'Κείμενα για τον πελάτη', 'variation-picker' ); ?></h2>
			<div class="vpk-zone">
				<p class="vpk-field"><span><?php esc_html_e( 'Γλώσσα κειμένων', 'variation-picker' ); ?></span>
				<?php
				echo self::select( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in select().
					'vpk_lang',
					array(
						'auto' => __( 'Αυτόματα (γλώσσα του site)', 'variation-picker' ),
						'el'   => __( 'Ελληνικά', 'variation-picker' ),
						'en'   => __( 'English', 'variation-picker' ),
					),
					$s['lang']
				);
				?>
				</p>
				<table class="widefat vpk-table vpk-texts"><thead><tr><th><?php esc_html_e( 'Κείμενο', 'variation-picker' ); ?></th><th><?php esc_html_e( 'Ελληνικά', 'variation-picker' ); ?></th><th><?php esc_html_e( 'English', 'variation-picker' ); ?></th></tr></thead><tbody>
				<?php
				foreach ( $rows as $key => $label ) {
					echo '<tr><td>' . esc_html( $label ) . '</td>';
					foreach ( array( 'el', 'en' ) as $l ) {
						echo '<td><input type="text" name="vpk_t[' . esc_attr( $key ) . '][' . esc_attr( $l ) . ']" value="' . esc_attr( $s[ $key ][ $l ] ) . '" maxlength="' . (int) VPK_Settings::TEXTS[ $key ] . '" /></td>';
					}
					echo '</tr>';
				}
				?>
				</tbody></table>
				<p class="description vpk-mt-8"><?php esc_html_e( 'Κενό ελληνικό κείμενο = το αρχικό. Κενό αγγλικό = δείχνει το ελληνικό. Τα ονόματα των τιμών είναι τα ονόματα των χαρακτηριστικών του WooCommerce.', 'variation-picker' ); ?></p>
			</div>
			<p class="vpk-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'variation-picker' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: tools
	 * ------------------------------------------------------------------- */

	private static function render_tools(): void {
		$s      = VPK_Settings::get();
		$cov    = VPK_Map::coverage();
		$others = VPK_Compat::active_names();
		?>
		<h2 class="vpk-h2"><?php esc_html_e( 'Εισαγωγή από άλλο plugin για swatches', 'variation-picker' ); ?></h2>
		<div class="vpk-zone">
			<p class="description"><?php esc_html_e( 'Διαβάζει χρώματα, εικόνες, ετικέτες και τύπους χαρακτηριστικών από τα CartFlows Variation Swatches, Variation Swatches (GetWooPlugins) και WCBoost Variation Swatches, και τα αντιγράφει μόνο όπου το Variation Picker δεν έχει ήδη τιμή. Δεν αλλάζει τίποτα στα δεδομένα εκείνων των plugins.', 'variation-picker' ); ?></p>
			<?php if ( $others ) : ?>
				<p><?php echo esc_html( sprintf( /* translators: %s: plugin names */ __( 'Ενεργό τώρα: %s', 'variation-picker' ), implode( ', ', $others ) ) ); ?></p>
			<?php endif; ?>
			<form method="post" class="vpk-inline-form">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="vpk_action" value="import_other" />
				<button type="submit" class="button"><?php esc_html_e( 'Εισαγωγή', 'variation-picker' ); ?></button>
			</form>
		</div>

		<h2 class="vpk-h2"><?php esc_html_e( 'Χάρτες διαθεσιμότητας', 'variation-picker' ); ?></h2>
		<div class="vpk-zone">
			<p><?php echo esc_html( sprintf( /* translators: 1: products with a map, 2: variable products */ __( 'Μεταβλητά προϊόντα με χάρτη: %1$d από %2$d.', 'variation-picker' ), $cov['mapped'], $cov['variable'] ) ); ?></p>
			<p class="description"><?php esc_html_e( 'Ο χάρτης λέει ποιοι συνδυασμοί υπάρχουν και ποια εικόνα έχει κάθε τιμή. Φτιάχνεται όταν αποθηκεύεται ένα προϊόν ή αλλάζει το απόθεμά του. Η ανακατασκευή τρέχει στο παρασκήνιο (Action Scheduler) σε ομάδες των 50.', 'variation-picker' ); ?></p>
			<form method="post" class="vpk-inline-form">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="vpk_action" value="rebuild_maps" />
				<button type="submit" class="button"><?php esc_html_e( 'Ανακατασκευή όλων', 'variation-picker' ); ?></button>
			</form>
		</div>

		<h2 class="vpk-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'variation-picker' ); ?></h2>
		<div class="vpk-zone">
			<form method="post" enctype="multipart/form-data" class="vpk-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'vpk_backup_nonce' ); ?>
				<input type="file" name="vpk_import_file" accept=".json,application/json" />
				<button type="submit" name="vpk_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'variation-picker' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=tools&vpk_export=1' ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'variation-picker' ); ?></a>
			<p class="description vpk-mt-8"><?php esc_html_e( 'Το backup έχει τις ρυθμίσεις, τους τύπους των χαρακτηριστικών και τους οδηγούς μεγεθών. Τα χρώματα και οι εικόνες των τιμών μένουν στις τιμές.', 'variation-picker' ); ?></p>
		</div>

		<h2 class="vpk-h2"><?php esc_html_e( 'Διαγραφή του plugin', 'variation-picker' ); ?></h2>
		<div class="vpk-zone">
			<form method="post">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="vpk_action" value="save_uninstall" />
				<?php echo self::check( 'vpk_delete_data', $s['delete_data'], __( 'Διαγραφή των χρωμάτων, εικόνων και ετικετών των τιμών και των ρυθμίσεων ανά προϊόν όταν διαγραφεί το plugin', 'variation-picker' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="description"><?php esc_html_e( 'Χωρίς το τικ μένουν, ώστε μια νέα εγκατάσταση να τα βρει. Οι ρυθμίσεις, οι οδηγοί και οι χάρτες φεύγουν πάντα.', 'variation-picker' ); ?></p>
				<p class="vpk-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'variation-picker' ); ?></button></p>
			</form>
		</div>

		<h2 class="vpk-h2"><?php esc_html_e( 'Για προγραμματιστές', 'variation-picker' ); ?></h2>
		<div class="vpk-zone">
			<table class="widefat vpk-table">
				<tbody>
					<tr><td><code>vpk_get_term_swatch( $term )</code></td><td><?php esc_html_e( 'Η εμφάνιση μιας τιμής χαρακτηριστικού (τύπος, χρώμα, δεύτερο χρώμα, εικόνα, ετικέτα) ή null, π.χ. για χρωματιστά φίλτρα.', 'variation-picker' ); ?></td></tr>
					<tr><td><code>vpk_text</code></td><td><?php esc_html_e( 'Φίλτρο για τα κείμενα του πελάτη (π.χ. WPML).', 'variation-picker' ); ?></td></tr>
					<tr><td><code>VPK_DISABLE</code></td><td><?php esc_html_e( 'Σταθερά στο wp-config.php: σταματά κάθε αλλαγή στο front end.', 'variation-picker' ); ?></td></tr>
				</tbody>
			</table>
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
		if ( self::SLUG !== self::current_page() ) {
			return;
		}

		// Settings import (own nonce, multipart form).
		if ( isset( $_POST['vpk_import'] ) ) {
			if ( ! isset( $_POST['vpk_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vpk_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'variation-picker' ) );
			}
			self::redirect( 'tools', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['vpk_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'variation-picker' ) );
		}

		$saved = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'variation-picker' ) ) );
		switch ( sanitize_key( wp_unslash( $_POST['vpk_action'] ) ) ) {
			case 'save_general':
				self::do_save_general();
				self::redirect( 'general', $saved );
				break;
			case 'save_attributes':
				self::do_save_attributes();
				self::redirect( 'attributes', $saved );
				break;
			case 'save_shop':
				self::do_save_shop();
				self::redirect( 'shop', $saved );
				break;
			case 'save_guides':
				self::redirect( 'guides', self::do_save_guides() );
				break;
			case 'save_texts':
				self::do_save_texts();
				self::redirect( 'texts', $saved );
				break;
			case 'save_uninstall':
				$s                = VPK_Settings::get();
				$s['delete_data'] = '1' === self::post( 'vpk_delete_data' );
				VPK_Settings::save( $s );
				self::redirect( 'tools', $saved );
				break;
			case 'import_other':
				$r = VPK_Compat::import();
				self::redirect(
					'tools',
					array(
						self::msg(
							'success',
							sprintf(
								/* translators: 1: values, 2: attributes */
								__( 'Η εισαγωγή ολοκληρώθηκε: %1$d τιμές και %2$d χαρακτηριστικά.', 'variation-picker' ),
								$r['terms'],
								$r['attrs']
							)
						),
					)
				);
				break;
			case 'rebuild_maps':
				$ok = VPK_Map::enqueue_all();
				self::redirect(
					'tools',
					array(
						$ok
							? self::msg( 'success', __( 'Η ανακατασκευή ξεκίνησε στο παρασκήνιο. Ανανέωσε τη σελίδα σε λίγο για να δεις την πρόοδο.', 'variation-picker' ) )
							: self::msg( 'error', __( 'Το Action Scheduler του WooCommerce δεν είναι διαθέσιμο.', 'variation-picker' ) ),
					)
				);
				break;
			default:
				self::redirect( 'general', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'variation-picker' ) ) ) );
		}
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		set_transient( 'vpk_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/** A posted array, unslashed (sanitized by VPK_Settings). */
	private static function post_array( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(), sanitized by VPK_Settings.
		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
	}

	private static function do_save_general(): void {
		$s = VPK_Settings::get();
		foreach ( array( 'mode', 'auto', 'shape', 'unavailable', 'oos', 'tooltip' ) as $k ) {
			$s[ $k ] = sanitize_key( self::post( 'vpk_' . $k ) );
		}
		foreach ( array( 'size', 'font', 'border', 'low_stock' ) as $k ) {
			$s[ $k ] = self::post( 'vpk_' . $k );
		}
		foreach ( array( 'border_color', 'active_color' ) as $k ) {
			$s[ $k ] = self::post( 'vpk_' . $k );
		}
		foreach ( array( 'selected_label', 'auto_select', 'deselect', 'url_sync', 'price_diff', 'cart_dot' ) as $k ) {
			$s[ $k ] = '1' === self::post( 'vpk_' . $k );
		}
		VPK_Settings::save( $s );
	}

	private static function do_save_attributes(): void {
		$s     = VPK_Settings::get();
		$known = self::attribute_list();
		foreach ( self::post_array( 'vpk_attrs' ) as $tax => $row ) {
			if ( ! is_string( $tax ) || ! isset( $known[ $tax ] ) || ! is_array( $row ) ) {
				continue;
			}
			$a = VPK_Settings::attr_row( $row );
			if ( '' === $a['type'] && '' === $a['shape'] && ! $a['size'] ) {
				unset( $s['attrs'][ $tax ] );
			} else {
				$s['attrs'][ $tax ] = $a;
			}
		}
		VPK_Settings::save( $s );
	}

	private static function do_save_shop(): void {
		$s    = VPK_Settings::get();
		$shop = self::post_array( 'vpk_shop' );
		foreach ( array( 'enabled', 'swap_image', 'preselect' ) as $k ) {
			$shop[ $k ] = isset( $shop[ $k ] ) && '1' === $shop[ $k ];
		}
		$s['shop'] = $shop;
		VPK_Settings::save( $s );
	}

	private static function do_save_guides(): array {
		$known = array();
		foreach ( VPK_Settings::guides() as $g ) {
			$known[ $g['id'] ] = true;
		}
		$rows    = array();
		$skipped = false;
		foreach ( self::post_array( 'vpk_g' ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$str = static function ( string $k ) use ( $row ): string {
				return isset( $row[ $k ] ) && is_string( $row[ $k ] ) ? $row[ $k ] : '';
			};
			if ( '1' === $str( 'delete' ) ) {
				continue;
			}
			if ( '' === trim( $str( 'title_el' ) ) ) {
				// An empty new card is not a guide; an existing one needs its title.
				$skipped = $skipped || isset( $known[ sanitize_key( $str( 'id' ) ) ] ) || '' !== trim( $str( 'table_el' ) );
				continue;
			}
			$id = sanitize_key( $str( 'id' ) );
			if ( ! isset( $known[ $id ] ) ) {
				$id = VPK_Settings::new_id();
			}
			$row['id']     = $id;
			$row['active'] = '1' === $str( 'active' );
			$rows[]        = $row;
		}
		VPK_Settings::save_guides( $rows );
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'variation-picker' ) ) );
		if ( $skipped ) {
			$msgs[] = self::msg( 'warning', __( 'Ένας οδηγός χωρίς ελληνικό τίτλο δεν αποθηκεύτηκε.', 'variation-picker' ) );
		}
		return $msgs;
	}

	private static function do_save_texts(): void {
		$s         = VPK_Settings::get();
		$s['lang'] = sanitize_key( self::post( 'vpk_lang' ) );
		$t         = self::post_array( 'vpk_t' );
		foreach ( array_keys( VPK_Settings::TEXTS ) as $k ) {
			if ( isset( $t[ $k ] ) && is_array( $t[ $k ] ) ) {
				$s[ $k ] = array(
					'el' => isset( $t[ $k ]['el'] ) && is_string( $t[ $k ]['el'] ) ? $t[ $k ]['el'] : '',
					'en' => isset( $t[ $k ]['en'] ) && is_string( $t[ $k ]['en'] ) ? $t[ $k ]['en'] : '',
				);
			}
		}
		VPK_Settings::save( $s );
	}

	/* =====================================================================
	 * Backup (export GET / import POST)
	 * =================================================================== */

	public static function route_export(): void {
		if ( self::SLUG !== self::current_page() || '' === self::q( 'vpk_export' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'variation-picker' ) );
		}
		$state = array(
			'plugin'  => 'variation-picker',
			'version' => VPK_VERSION,
			'options' => array(
				VPK_Settings::OPT        => VPK_Settings::get(),
				VPK_Settings::OPT_GUIDES => VPK_Settings::guides(),
			),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="variation-picker-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	/**
	 * Strict import, per option (Bible §11): each blob goes through the same
	 * validation as the forms; an invalid blob leaves that option untouched
	 * and says so.
	 */
	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['vpk_import_file']['error'] ) ? (int) $_FILES['vpk_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['vpk_import_file']['size'] ) ? (int) $_FILES['vpk_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'variation-picker' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['vpk_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['vpk_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'variation-picker' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['vpk_import_file']['tmp_name'] ), true );
		// phpcs:enable
		if ( ! is_array( $state ) || ! isset( $state['options'] ) || ! is_array( $state['options'] ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'variation-picker' ) ) );
		}
		$opts = $state['options'];
		$ok   = 0;
		$bad  = 0;

		$blob = isset( $opts[ VPK_Settings::OPT ] ) ? $opts[ VPK_Settings::OPT ] : null;
		if ( is_array( $blob ) ) {
			VPK_Settings::save( $blob );
			++$ok;
		} else {
			++$bad;
		}
		$guides = isset( $opts[ VPK_Settings::OPT_GUIDES ] ) ? $opts[ VPK_Settings::OPT_GUIDES ] : null;
		if ( is_array( $guides ) ) {
			VPK_Settings::save_guides( $guides );
			++$ok;
		} else {
			++$bad;
		}

		if ( ! $ok ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Variation Picker).', 'variation-picker' ) ) );
		}
		if ( $bad ) {
			return array( self::msg( 'warning', __( 'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ: ένα μέρος του αρχείου έλειπε ή δεν ήταν έγκυρο και έμεινε ως είχε.', 'variation-picker' ) ) );
		}
		return array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'variation-picker' ) ) );
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'vpk_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'vpk_aui_msg_' . get_current_user_id() );
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
		<p class="vpk-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'variation-picker' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="vpk-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="vpk-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'variation-picker' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="vpk-footer-cta">
			<a class="vpk-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'variation-picker' ); ?>
			</a>
			<a class="vpk-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'variation-picker' ); ?>
			</a>
		</p>
		<?php
	}
}

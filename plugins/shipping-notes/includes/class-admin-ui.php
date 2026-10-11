<?php
/**
 * SHN_Admin_UI — menu, settings page, PRG routes, backup (Bible §3–§8, §11).
 *
 * One page, submenu «Αποστολή» of the shared "Noxpress" menu (admin_menu
 * priority 90), slug shn-settings, with tabs:
 *  - free      free-shipping bar: mode, threshold source, zones, places,
 *              look, texts
 *  - delivery  delivery time: mode, product-page zone, transit days per
 *              method, preparation, cut-off, working days, holidays, look,
 *              texts; "add Greek public holidays"
 *  - preview   what a cart of X in zone Y shows; the estimate of a product
 *              with today's dates (read-only GET)
 *  - tools     settings export / import, shortcodes and blocks
 *
 * Every state change: POST on admin_init, slug + nonce + capability,
 * strict validation (SHN_Settings::sanitize), PRG with the message in the
 * transient shn_aui_msg_{uid}. Export: privileged GET (slug + nonce +
 * capability).
 */

defined( 'ABSPATH' ) || exit;

final class SHN_Admin_UI {

	const CAP         = 'manage_woocommerce';
	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'shn-settings';
	const NONCE       = 'shn_admin';
	const NONCE_FIELD = 'shn_nonce';
	const NONCE_BAK   = 'shn_backup';
	const MAX_IMPORT  = 262144;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 90 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SHN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label msgid. */
	private static function tabs(): array {
		return array(
			'free'     => __( 'Δωρεάν αποστολή', 'shipping-notes' ),
			'delivery' => __( 'Χρόνος παράδοσης', 'shipping-notes' ),
			'preview'  => __( 'Προεπισκόπηση', 'shipping-notes' ),
			'tools'    => __( 'Εργαλεία', 'shipping-notes' ),
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
			__( 'Shipping Notes', 'shipping-notes' ),
			__( 'Αποστολή', 'shipping-notes' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'shipping-notes' ) . '</a>'
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
		return isset( self::tabs()[ $tab ] ) ? $tab : 'free';
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'shn-admin', SHN_URL . 'assets/admin.css', array(), SHN_VERSION );
		if ( 'preview' === self::current_tab() ) {
			wp_enqueue_style( 'shn-front', SHN_URL . 'assets/front.css', array(), SHN_VERSION );
		}
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'shipping-notes' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap shn-wrap">
			<h1><?php esc_html_e( 'Shipping Notes', 'shipping-notes' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Δύο μηνύματα για τον πελάτη: πόσα απομένουν για δωρεάν αποστολή, και σε πόσες εργάσιμες φτάνει ένα προϊόν.', 'shipping-notes' ); ?></p>

			<?php self::print_notices( self::take_msgs() ); ?>

			<nav class="nav-tab-wrapper shn-tabs">
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
				case 'delivery':
					self::render_delivery();
					break;
				case 'preview':
					self::render_preview();
					break;
				case 'tools':
					self::render_tools();
					break;
				default:
					self::render_free();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	/** Mode radios (off / test / live). */
	private static function mode_field( string $name, string $value ): void {
		$opts = array(
			'off'  => __( 'Ανενεργό', 'shipping-notes' ),
			'test' => __( 'Λειτουργία δοκιμής: φαίνεται μόνο στους διαχειριστές του καταστήματος', 'shipping-notes' ),
			'live' => __( 'Ενεργό για όλους', 'shipping-notes' ),
		);
		foreach ( $opts as $k => $label ) {
			echo '<label class="shn-check"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $k ) . '" ' . checked( $value, $k, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή', 'shipping-notes' ) . ' <code>define( \'SHN_DISABLE\', true );</code></p>';
	}

	private static function checkbox( string $name, bool $on, string $label ): void {
		echo '<label class="shn-check"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $on, true, false ) . ' /> ' . esc_html( $label ) . '</label>';
	}

	/** Language of the visitor texts (shared by both tabs). */
	private static function lang_field( string $value ): void {
		echo '<p class="shn-field"><span>' . esc_html__( 'Γλώσσα κειμένων', 'shipping-notes' ) . '</span><select name="shn_lang">';
		foreach ( array(
			'auto' => __( 'Αυτόματα (γλώσσα του site)', 'shipping-notes' ),
			'el'   => __( 'Ελληνικά', 'shipping-notes' ),
			'en'   => __( 'English', 'shipping-notes' ),
		) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $value, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
	}

	/**
	 * Table of { el, en } visitor texts.
	 *
	 * @param array $rows Text key => label.
	 */
	private static function texts_table( array $s, array $rows ): void {
		echo '<table class="widefat striped shn-table shn-texts"><thead><tr><th>' . esc_html__( 'Κείμενο', 'shipping-notes' ) . '</th><th>' . esc_html__( 'Ελληνικά', 'shipping-notes' ) . '</th><th>' . esc_html__( 'English', 'shipping-notes' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $key => $label ) {
			$len = SHN_Settings::TEXTS[ $key ];
			echo '<tr><td>' . esc_html( $label ) . '</td>';
			foreach ( array( 'el', 'en' ) as $l ) {
				echo '<td><input type="text" name="shn_t[' . esc_attr( $key ) . '][' . esc_attr( $l ) . ']" value="' . esc_attr( $s[ $key ][ $l ] ) . '" maxlength="' . (int) $len . '" /></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description shn-mt-8">' . esc_html__( 'Κενό ελληνικό κείμενο = το αρχικό. Κενό αγγλικό = δείχνει το ελληνικό.', 'shipping-notes' ) . '</p>';
	}

	/** One-line summary of a zone's free_shipping methods in WooCommerce. */
	private static function zone_free_summary( $zone ): string {
		$parts = array();
		foreach ( $zone->get_shipping_methods( false ) as $m ) {
			if ( ! $m instanceof WC_Shipping_Free_Shipping ) {
				continue;
			}
			$req = (string) $m->requires;
			switch ( $req ) {
				case 'min_amount':
					$t = sprintf( /* translators: %s: amount */ __( 'από %s', 'shipping-notes' ), wp_strip_all_tags( wc_price( (float) $m->min_amount ) ) );
					break;
				case 'either':
					$t = sprintf( /* translators: %s: amount */ __( 'από %s ή με κουπόνι', 'shipping-notes' ), wp_strip_all_tags( wc_price( (float) $m->min_amount ) ) );
					break;
				case 'both':
					$t = sprintf( /* translators: %s: amount */ __( 'από %s και με κουπόνι', 'shipping-notes' ), wp_strip_all_tags( wc_price( (float) $m->min_amount ) ) );
					break;
				case 'coupon':
					$t = __( 'μόνο με κουπόνι', 'shipping-notes' );
					break;
				default:
					$t = __( 'πάντα δωρεάν', 'shipping-notes' );
			}
			if ( 'yes' === $m->ignore_discounts && in_array( $req, array( 'min_amount', 'either', 'both' ), true ) ) {
				$t .= ' · ' . __( 'πριν από τις εκπτώσεις κουπονιών', 'shipping-notes' );
			}
			if ( 'yes' !== $m->enabled ) {
				$t .= ' · ' . __( 'ανενεργή', 'shipping-notes' );
			}
			$parts[] = $t;
		}
		return $parts ? implode( '; ', $parts ) : '—';
	}

	private static function zone_name( $zone ): string {
		return 0 === (int) $zone->get_id() ? __( 'Περιοχές που δεν καλύπτονται από άλλες ζώνες', 'shipping-notes' ) : (string) $zone->get_zone_name();
	}

	/* ---------------------------------------------------------------------
	 * Tab: free shipping
	 * ------------------------------------------------------------------- */

	private static function render_free(): void {
		$s     = SHN_Settings::get();
		$zones = SHN_Threshold::zones();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="shn_action" value="save_free" />

			<h2 class="shn-h2"><?php esc_html_e( 'Λειτουργία', 'shipping-notes' ); ?></h2>
			<div class="shn-zone"><?php self::mode_field( 'shn_fs_mode', $s['fs_mode'] ); ?></div>

			<h2 class="shn-h2"><?php esc_html_e( 'Όριο δωρεάν αποστολής', 'shipping-notes' ); ?></h2>
			<div class="shn-zone">
				<label class="shn-check"><input type="radio" name="shn_fs_source" value="auto" <?php checked( $s['fs_source'], 'auto' ); ?> /> <?php esc_html_e( 'Αυτόματα από τις ζώνες αποστολής του WooCommerce (μέθοδος «Δωρεάν αποστολή», ελάχιστο ποσό)', 'shipping-notes' ); ?></label>
				<label class="shn-check"><input type="radio" name="shn_fs_source" value="zones" <?php checked( $s['fs_source'], 'zones' ); ?> /> <?php esc_html_e( 'Αυτόματα, με δικό μου ποσό για τις ζώνες χωρίς «Δωρεάν αποστολή»', 'shipping-notes' ); ?></label>
				<label class="shn-check"><input type="radio" name="shn_fs_source" value="fixed" <?php checked( $s['fs_source'], 'fixed' ); ?> /> <?php esc_html_e( 'Σταθερό ποσό για όλο το site:', 'shipping-notes' ); ?>
					<input type="text" class="shn-num" name="shn_fs_fixed" value="<?php echo esc_attr( $s['fs_fixed'] > 0 ? wc_format_localized_price( (string) $s['fs_fixed'] ) : '' ); ?>" inputmode="decimal" /> <?php echo esc_html( get_woocommerce_currency_symbol() ); ?></label>
				<p class="description"><?php esc_html_e( 'Το ποσό που μετράει υπολογίζεται όπως στο WooCommerce: με ή χωρίς ΦΠΑ όπως δείχνει τις τιμές το καλάθι, μετά τις εκπτώσεις κουπονιών (εκτός αν η μέθοδος λέει «πριν από τις εκπτώσεις»). Με δικό σου ποσό, το μήνυμα μπορεί να διαφωνεί με το πραγματικό κόστος στο ταμείο.', 'shipping-notes' ); ?></p>

				<table class="widefat striped shn-table shn-mt-8">
					<thead><tr>
						<th><?php esc_html_e( 'Ζώνη', 'shipping-notes' ); ?></th>
						<th><?php esc_html_e( 'Δωρεάν αποστολή στο WooCommerce', 'shipping-notes' ); ?></th>
						<th><?php esc_html_e( 'Δικό μου ποσό', 'shipping-notes' ); ?></th>
						<th><?php esc_html_e( 'Ισχύει τώρα', 'shipping-notes' ); ?></th>
					</tr></thead>
					<tbody>
					<?php
					foreach ( $zones as $zid => $zone ) {
						$rule = SHN_Threshold::rule( $zone );
						$now  = null === $rule ? __( 'τίποτα (δεν εμφανίζεται)', 'shipping-notes' ) : ( $rule['threshold'] > 0 ? wp_strip_all_tags( wc_price( $rule['threshold'] ) ) : __( 'μόνο «κερδίσατε»', 'shipping-notes' ) );
						$warn = false;
						if ( null !== $rule && 'auto' !== $s['fs_source'] ) {
							foreach ( $zone->get_shipping_methods( true ) as $m ) {
								if ( $m instanceof WC_Shipping_Free_Shipping && (float) $m->min_amount > 0 && abs( (float) $m->min_amount - $rule['threshold'] ) > 0.001 ) {
									$warn = true;
								}
							}
						}
						echo '<tr><td class="shn-zone-name"><a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping&zone_id=' . (int) $zid ) ) . '">' . esc_html( self::zone_name( $zone ) ) . '</a></td>';
						echo '<td>' . esc_html( self::zone_free_summary( $zone ) ) . '</td>';
						echo '<td><input type="text" class="shn-num" name="shn_fs_zones[' . (int) $zid . ']" value="' . esc_attr( isset( $s['fs_zones'][ $zid ] ) ? wc_format_localized_price( (string) $s['fs_zones'][ $zid ] ) : '' ) . '" inputmode="decimal" /></td>';
						echo '<td' . ( $warn ? ' class="shn-warn"' : '' ) . '>' . esc_html( $now ) . ( $warn ? ' · ' . esc_html__( 'διαφέρει από το WooCommerce', 'shipping-notes' ) : '' ) . '</td></tr>';
					}
					?>
					</tbody>
				</table>
				<p class="description shn-mt-8"><?php esc_html_e( 'Το «Δικό μου ποσό» ισχύει μόνο με την επιλογή «με δικό μου ποσό» και μόνο σε ζώνες χωρίς ενεργή «Δωρεάν αποστολή». Με «μόνο με κουπόνι» δεν υπάρχει ποσό· με «ποσό και κουπόνι» η μπάρα φαίνεται μόλις μπει το κουπόνι.', 'shipping-notes' ); ?></p>
			</div>

			<h2 class="shn-h2"><?php esc_html_e( 'Πού εμφανίζεται', 'shipping-notes' ); ?></h2>
			<div class="shn-zone">
				<label class="shn-check"><input type="checkbox" name="shn_fs_cart" value="1" <?php checked( $s['fs_cart'] ); ?> /> <?php esc_html_e( 'Καλάθι (κλασικό)', 'shipping-notes' ); ?>
					<select name="shn_fs_cart_pos">
						<option value="totals" <?php selected( $s['fs_cart_pos'], 'totals' ); ?>><?php esc_html_e( 'πάνω από τα σύνολα', 'shipping-notes' ); ?></option>
						<option value="table" <?php selected( $s['fs_cart_pos'], 'table' ); ?>><?php esc_html_e( 'πάνω από τα προϊόντα', 'shipping-notes' ); ?></option>
					</select>
				</label>
				<?php
				self::checkbox( 'shn_fs_minicart', $s['fs_minicart'], __( 'Mini-cart (widget και καλάθι στο header του θέματος)', 'shipping-notes' ) );
				self::checkbox( 'shn_fs_checkout', $s['fs_checkout'], __( 'Ταμείο (κλασικό)', 'shipping-notes' ) );
				self::checkbox( 'shn_fs_block_cart', $s['fs_block_cart'], __( 'Μπλοκ «Καλάθι»', 'shipping-notes' ) );
				self::checkbox( 'shn_fs_block_checkout', $s['fs_block_checkout'], __( 'Μπλοκ «Ταμείο»', 'shipping-notes' ) );
				?>
				<p class="description"><?php esc_html_e( 'Οπουδήποτε αλλού: το shortcode [nox_free_shipping] ή το μπλοκ «Δωρεάν αποστολή (Shipping Notes)». Στο μπλοκ Mini-Cart των block themes, βάλε το μπλοκ μας στο template part «Mini-Cart» από τον Site Editor.', 'shipping-notes' ); ?></p>
			</div>

			<h2 class="shn-h2"><?php esc_html_e( 'Εμφάνιση', 'shipping-notes' ); ?></h2>
			<div class="shn-zone">
				<?php
				self::checkbox( 'shn_fs_bar', $s['fs_bar'], __( 'Μπάρα προόδου κάτω από το κείμενο', 'shipping-notes' ) );
				self::checkbox( 'shn_fs_show_empty', $s['fs_show_empty'], __( 'Μήνυμα και με άδειο καλάθι', 'shipping-notes' ) );
				self::checkbox( 'shn_fs_show_won', $s['fs_show_won'], __( 'Μήνυμα όταν κερδίζει δωρεάν αποστολή', 'shipping-notes' ) );
				?>
				<p class="shn-field"><span><?php esc_html_e( 'Χρώμα μπάρας', 'shipping-notes' ); ?></span>
					<input type="text" class="shn-color" name="shn_fs_color" value="<?php echo esc_attr( $s['fs_color'] ); ?>" placeholder="#6d4aff" maxlength="7" />
					<span class="shn-hint"><?php esc_html_e( 'Κενό = το χρώμα του κειμένου του θέματος.', 'shipping-notes' ); ?></span>
				</p>
			</div>

			<h2 class="shn-h2"><?php esc_html_e( 'Κείμενα', 'shipping-notes' ); ?></h2>
			<div class="shn-zone">
				<?php
				self::lang_field( $s['lang'] );
				self::texts_table(
					$s,
					array(
						'fs_t_empty' => __( 'Άδειο καλάθι', 'shipping-notes' ),
						'fs_t_left'  => __( 'Απομένει ποσό', 'shipping-notes' ),
						'fs_t_won'   => __( 'Κερδίσατε', 'shipping-notes' ),
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Placeholders: {threshold} όριο, {remaining} ποσό που απομένει, {total} ποσό που μετράει, {percent} ποσοστό.', 'shipping-notes' ); ?></p>
			</div>

			<p class="shn-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'shipping-notes' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: delivery time
	 * ------------------------------------------------------------------- */

	/** Short weekday names in the admin language, ISO keys 1 (Mon) – 7 (Sun). */
	private static function weekdays(): array {
		return array(
			1 => __( 'Δευ', 'shipping-notes' ),
			2 => __( 'Τρί', 'shipping-notes' ),
			3 => __( 'Τετ', 'shipping-notes' ),
			4 => __( 'Πέμ', 'shipping-notes' ),
			5 => __( 'Παρ', 'shipping-notes' ),
			6 => __( 'Σάβ', 'shipping-notes' ),
			7 => __( 'Κυρ', 'shipping-notes' ),
		);
	}

	/** "Τρί 13/10" in the store timezone, weekday in the admin language. */
	private static function short_date( DateTimeInterface $d, string $fmt = 'j/n' ): string {
		$days = self::weekdays();
		$ts   = $d->getTimestamp();
		return $days[ (int) wp_date( 'N', $ts, wp_timezone() ) ] . ' ' . wp_date( $fmt, $ts, wp_timezone() );
	}

	private static function render_delivery(): void {
		$s     = SHN_Settings::get();
		$zones = SHN_Threshold::zones();
		$base  = SHN_Threshold::base_zone();
		$days  = self::weekdays();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="shn_action" value="save_delivery" />

			<h2 class="shn-h2"><?php esc_html_e( 'Λειτουργία', 'shipping-notes' ); ?></h2>
			<div class="shn-zone"><?php self::mode_field( 'shn_dl_mode', $s['dl_mode'] ); ?></div>

			<h2 class="shn-h2"><?php esc_html_e( 'Χρόνος μεταφοράς ανά μέθοδο αποστολής', 'shipping-notes' ); ?></h2>
			<div class="shn-zone">
				<p class="shn-field"><span><?php esc_html_e( 'Ζώνη στη σελίδα προϊόντος', 'shipping-notes' ); ?></span>
					<select name="shn_dl_zone">
						<option value="-1" <?php selected( $s['dl_zone'], -1 ); ?>><?php echo esc_html( sprintf( /* translators: %s: zone name */ __( 'Η ζώνη της διεύθυνσης του καταστήματος (%s)', 'shipping-notes' ), self::zone_name( $base ) ) ); ?></option>
						<?php
						foreach ( $zones as $zid => $zone ) {
							echo '<option value="' . (int) $zid . '" ' . selected( $s['dl_zone'], (int) $zid, false ) . '>' . esc_html( self::zone_name( $zone ) ) . '</option>';
						}
						?>
					</select>
				</p>
				<p class="description"><?php esc_html_e( 'Μία ζώνη για όλους τους επισκέπτες, ώστε η page cache να δείχνει πάντα σωστό κείμενο.', 'shipping-notes' ); ?></p>

				<table class="widefat striped shn-table shn-mt-8">
					<thead><tr>
						<th><?php esc_html_e( 'Ζώνη', 'shipping-notes' ); ?></th>
						<th><?php esc_html_e( 'Μέθοδος', 'shipping-notes' ); ?></th>
						<th><?php esc_html_e( 'Εργάσιμες (από – έως)', 'shipping-notes' ); ?></th>
						<th><?php esc_html_e( 'Παραδίδει Σάββατο', 'shipping-notes' ); ?></th>
					</tr></thead>
					<tbody>
					<?php
					$any = false;
					foreach ( $zones as $zid => $zone ) {
						foreach ( $zone->get_shipping_methods( false ) as $m ) {
							$any = true;
							$iid = (int) $m->get_instance_id();
							$cfg = $s['dl_methods'][ $iid ] ?? null;
							echo '<tr><td class="shn-zone-name">' . esc_html( self::zone_name( $zone ) ) . '</td>';
							echo '<td>' . esc_html( wp_strip_all_tags( (string) $m->get_title() ) ) . ( 'yes' !== $m->enabled ? ' <span class="shn-muted">(' . esc_html__( 'ανενεργή', 'shipping-notes' ) . ')</span>' : '' ) . '</td>';
							echo '<td class="shn-days"><input type="number" min="0" max="' . (int) SHN_Settings::MAX_DAYS . '" name="shn_m[' . $iid . '][min]" value="' . esc_attr( null === $cfg ? '' : (string) $cfg['min'] ) . '" aria-label="' . esc_attr__( 'Από', 'shipping-notes' ) . '" /> – ';
							echo '<input type="number" min="0" max="' . (int) SHN_Settings::MAX_DAYS . '" name="shn_m[' . $iid . '][max]" value="' . esc_attr( null === $cfg ? '' : (string) $cfg['max'] ) . '" aria-label="' . esc_attr__( 'Έως', 'shipping-notes' ) . '" /></td>';
							echo '<td><input type="checkbox" name="shn_m[' . $iid . '][sat]" value="1" ' . checked( null !== $cfg && $cfg['sat'], true, false ) . ' /></td></tr>';
						}
					}
					if ( ! $any ) {
						echo '<tr><td colspan="4" class="shn-empty">' . esc_html__( 'Δεν υπάρχουν μέθοδοι αποστολής. Πρόσθεσέ τες στο WooCommerce → Ρυθμίσεις → Αποστολή.', 'shipping-notes' ) . '</td></tr>';
					}
					?>
					</tbody>
				</table>
				<p class="description shn-mt-8"><?php esc_html_e( 'Εργάσιμες από την αποστολή του δέματος ως την παράδοση. Μέθοδος χωρίς ημέρες δεν μπαίνει στον υπολογισμό (π.χ. παραλαβή από το κατάστημα).', 'shipping-notes' ); ?></p>
			</div>

			<h2 class="shn-h2"><?php esc_html_e( 'Χρόνος προετοιμασίας', 'shipping-notes' ); ?></h2>
			<div class="shn-zone">
				<p class="shn-field shn-days"><span><?php esc_html_e( 'Κατάστημα (εργάσιμες)', 'shipping-notes' ); ?></span>
					<input type="number" min="0" max="<?php echo (int) SHN_Settings::MAX_DAYS; ?>" name="shn_dl_prep_min" value="<?php echo (int) $s['dl_prep_min']; ?>" aria-label="<?php esc_attr_e( 'Από', 'shipping-notes' ); ?>" /> –
					<input type="number" min="0" max="<?php echo (int) SHN_Settings::MAX_DAYS; ?>" name="shn_dl_prep_max" value="<?php echo (int) $s['dl_prep_max']; ?>" aria-label="<?php esc_attr_e( 'Έως', 'shipping-notes' ); ?>" />
				</p>
				<p class="shn-field shn-days"><span><label><input type="checkbox" name="shn_dl_bo" value="1" <?php checked( $s['dl_bo'] ); ?> /> <?php esc_html_e( 'Προϊόντα σε backorder', 'shipping-notes' ); ?></label></span>
					<input type="number" min="0" max="<?php echo (int) SHN_Settings::MAX_DAYS; ?>" name="shn_dl_bo_min" value="<?php echo (int) $s['dl_bo_min']; ?>" aria-label="<?php esc_attr_e( 'Από', 'shipping-notes' ); ?>" /> –
					<input type="number" min="0" max="<?php echo (int) SHN_Settings::MAX_DAYS; ?>" name="shn_dl_bo_max" value="<?php echo (int) $s['dl_bo_max']; ?>" aria-label="<?php esc_attr_e( 'Έως', 'shipping-notes' ); ?>" />
				</p>
				<p class="description"><?php esc_html_e( 'Εργάσιμες μέχρι να φύγει το δέμα. Ισχύει η πιο συγκεκριμένη τιμή: προϊόν (καρτέλα «Αποστολή»), μετά κατηγορία (η πιο αργή από τις κατηγορίες του), μετά κατάστημα.', 'shipping-notes' ); ?></p>
			</div>

			<h2 class="shn-h2"><?php esc_html_e( 'Ημερολόγιο', 'shipping-notes' ); ?></h2>
			<div class="shn-zone">
				<p class="shn-field"><span><?php esc_html_e( 'Ώρα cut-off', 'shipping-notes' ); ?></span>
					<input type="time" class="shn-time" name="shn_dl_cutoff" value="<?php echo esc_attr( $s['dl_cutoff'] ); ?>" />
					<span class="shn-hint"><?php echo esc_html( sprintf( /* translators: %s: time zone */ __( 'Ζώνη ώρας του site: %s. Κενό = χωρίς cut-off.', 'shipping-notes' ), wp_timezone_string() ) ); ?></span>
				</p>
				<p class="shn-field"><span><?php esc_html_e( 'Εργάσιμες αποστολής', 'shipping-notes' ); ?></span>
					<?php
					foreach ( $days as $n => $label ) {
						echo '<label class="shn-inline"><input type="checkbox" name="shn_dl_workdays[]" value="' . (int) $n . '" ' . checked( in_array( $n, $s['dl_workdays'], true ), true, false ) . ' /> ' . esc_html( $label ) . '</label> ';
					}
					?>
				</p>
				<label class="shn-label" for="shn_dl_holidays"><?php esc_html_e( 'Αργίες', 'shipping-notes' ); ?></label>
				<textarea id="shn_dl_holidays" class="shn-holidays" name="shn_dl_holidays"><?php echo esc_textarea( implode( "\n", $s['dl_holidays'] ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Μία ανά γραμμή: ΜΜ-ΗΗ για κάθε χρόνο (π.χ. 12-25) ή ΕΕΕΕ-ΜΜ-ΗΗ για μία φορά (π.χ. 2027-04-30). Δεκτό και ΗΗ/ΜΜ ή ΗΗ/ΜΜ/ΕΕΕΕ. Οι περασμένες ημερομηνίες σβήνονται στην αποθήκευση. Οι αργίες ισχύουν και για την αποστολή και για την παράδοση.', 'shipping-notes' ); ?></p>
			</div>

			<h2 class="shn-h2"><?php esc_html_e( 'Εμφάνιση', 'shipping-notes' ); ?></h2>
			<div class="shn-zone">
				<label class="shn-check"><input type="radio" name="shn_dl_format" value="range" <?php checked( $s['dl_format'], 'range' ); ?> /> <?php esc_html_e( 'Ένα εύρος, από τη γρηγορότερη ως την πιο αργή μέθοδο', 'shipping-notes' ); ?></label>
				<label class="shn-check"><input type="radio" name="shn_dl_format" value="list" <?php checked( $s['dl_format'], 'list' ); ?> /> <?php esc_html_e( 'Λίστα ανά μέθοδο', 'shipping-notes' ); ?></label>
				<p class="shn-field"><span><?php esc_html_e( 'Θέση στη σελίδα προϊόντος', 'shipping-notes' ); ?></span>
					<select name="shn_dl_position">
						<option value="price" <?php selected( $s['dl_position'], 'price' ); ?>><?php esc_html_e( 'Κάτω από την τιμή', 'shipping-notes' ); ?></option>
						<option value="cart" <?php selected( $s['dl_position'], 'cart' ); ?>><?php esc_html_e( 'Κάτω από το κουμπί καλαθιού', 'shipping-notes' ); ?></option>
						<option value="none" <?php selected( $s['dl_position'], 'none' ); ?>><?php esc_html_e( 'Μόνο με shortcode ή μπλοκ', 'shipping-notes' ); ?></option>
					</select>
				</p>
				<?php
				self::checkbox( 'shn_dl_dates', $s['dl_dates'], __( 'Ημερομηνίες αντί για εργάσιμες (π.χ. «Τρί 13/10 – Πέμ 15/10»)', 'shipping-notes' ) );
				self::checkbox( 'shn_dl_cutoff_line', $s['dl_cutoff_line'], __( 'Γραμμή «Παραγγείλτε μέσα σε…» πριν από το cut-off', 'shipping-notes' ) );
				?>
				<p class="description"><?php esc_html_e( 'Οι ημερομηνίες και η αντίστροφη μέτρηση υπολογίζονται στον browser του επισκέπτη με την ώρα του καταστήματος, ώστε να είναι σωστές και σε σελίδες από page cache. Χωρίς JavaScript μένει το κείμενο σε εργάσιμες. Οι προπαραγγελίες δείχνουν πάντα την ημερομηνία κυκλοφορίας. Shortcode: [nox_delivery] ή [nox_delivery id="123"]· μπλοκ «Χρόνος παράδοσης (Shipping Notes)».', 'shipping-notes' ); ?></p>
			</div>

			<h2 class="shn-h2"><?php esc_html_e( 'Κείμενα', 'shipping-notes' ); ?></h2>
			<div class="shn-zone">
				<?php
				self::lang_field( $s['lang'] );
				self::texts_table(
					$s,
					array(
						'dl_t_est'        => __( 'Εύρος', 'shipping-notes' ),
						'dl_t_title'      => __( 'Τίτλος λίστας', 'shipping-notes' ),
						'dl_t_item'       => __( 'Γραμμή λίστας', 'shipping-notes' ),
						'dl_t_one'        => __( 'Μονάδα: μία μέρα', 'shipping-notes' ),
						'dl_t_many'       => __( 'Μονάδα: πολλές μέρες', 'shipping-notes' ),
						'dl_t_dates'      => __( 'Εύρος με ημερομηνίες', 'shipping-notes' ),
						'dl_t_item_dates' => __( 'Γραμμή λίστας με ημερομηνίες', 'shipping-notes' ),
						'dl_t_cutoff'     => __( 'Γραμμή cut-off', 'shipping-notes' ),
						'dl_t_preorder'   => __( 'Προπαραγγελία', 'shipping-notes' ),
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Placeholders: {days} π.χ. «1–3 εργάσιμες», {method} μέθοδος, {dates} ημερομηνίες, {hours} {minutes} {cutoff} για το cut-off, {available} ημερομηνία κυκλοφορίας.', 'shipping-notes' ); ?></p>
			</div>

			<p class="shn-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'shipping-notes' ); ?></button></p>
		</form>

		<h2 class="shn-h2"><?php esc_html_e( 'Ελληνικές αργίες', 'shipping-notes' ); ?></h2>
		<form method="post" class="shn-inline-form">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="shn_action" value="greek_holidays" />
			<button type="submit" class="button"><?php esc_html_e( 'Προσθήκη ελληνικών αργιών', 'shipping-notes' ); ?></button>
		</form>
		<p class="description shn-mt-8"><?php esc_html_e( 'Προσθέτει στη λίστα τις σταθερές αργίες και τις κινητές του Πάσχα (Καθαρά Δευτέρα, Μεγάλη Παρασκευή, Δευτέρα του Πάσχα, Αγίου Πνεύματος) για φέτος και του χρόνου. Υπολογίζονται εδώ, χωρίς σύνδεση σε άλλο site.', 'shipping-notes' ); ?></p>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: preview (read-only GET)
	 * ------------------------------------------------------------------- */

	private static function render_preview(): void {
		$zones   = SHN_Threshold::zones();
		$zid     = self::q( 'zone' );
		$zid     = ctype_digit( $zid ) && isset( $zones[ (int) $zid ] ) ? (int) $zid : (int) SHN_Threshold::base_zone()->get_id();
		$amt_raw = self::q( 'amount' );
		$amount  = SHN_Settings::amount( $amt_raw );
		$pid     = absint( self::q( 'product' ) );
		?>
		<h2 class="shn-h2"><?php esc_html_e( 'Δωρεάν αποστολή', 'shipping-notes' ); ?></h2>
		<div class="shn-zone">
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<input type="hidden" name="tab" value="preview" />
				<input type="hidden" name="product" value="<?php echo (int) $pid; ?>" />
				<p class="shn-field"><span><?php esc_html_e( 'Ποσό που μετράει', 'shipping-notes' ); ?></span>
					<input type="text" class="shn-num" name="amount" value="<?php echo esc_attr( $amt_raw ); ?>" inputmode="decimal" /> <?php echo esc_html( get_woocommerce_currency_symbol() ); ?>
				</p>
				<p class="shn-field"><span><?php esc_html_e( 'Ζώνη', 'shipping-notes' ); ?></span>
					<select name="zone">
						<?php
						foreach ( $zones as $id => $zone ) {
							echo '<option value="' . (int) $id . '" ' . selected( $zid, (int) $id, false ) . '>' . esc_html( self::zone_name( $zone ) ) . '</option>';
						}
						?>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Προεπισκόπηση', 'shipping-notes' ); ?></button>
				</p>
			</form>
			<?php
			$rule = SHN_Threshold::rule( $zones[ $zid ] );
			if ( null === $rule ) {
				echo '<p class="shn-muted">' . esc_html__( 'Σε αυτή τη ζώνη δεν εμφανίζεται τίποτα: δεν υπάρχει όριο δωρεάν αποστολής.', 'shipping-notes' ) . '</p>';
			} else {
				if ( $amount <= 0 ) {
					$st = SHN_Threshold::state( $rule['threshold'] > 0 ? 'empty' : 'won', $rule['threshold'], 0.0 );
				} else {
					$st = SHN_Threshold::state( $amount >= $rule['threshold'] ? 'won' : 'left', $rule['threshold'], $amount );
				}
				$html = SHN_Front::fs_html( $st );
				echo '' !== $html
					? '<div class="shn-preview">' . $html . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in fs_html().
					: '<p class="shn-muted">' . esc_html__( 'Αυτό το μήνυμα είναι κλειστό στις ρυθμίσεις.', 'shipping-notes' ) . '</p>';
				echo '<p class="description shn-mt-8">' . esc_html__( 'Ποσό 0 = άδειο καλάθι. Τα κουπόνια δωρεάν αποστολής και τα φίλτρα άλλων plugins φαίνονται μόνο σε πραγματικό καλάθι.', 'shipping-notes' ) . '</p>';
			}
			?>
		</div>

		<h2 class="shn-h2"><?php esc_html_e( 'Χρόνος παράδοσης', 'shipping-notes' ); ?></h2>
		<div class="shn-zone">
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<input type="hidden" name="tab" value="preview" />
				<input type="hidden" name="amount" value="<?php echo esc_attr( $amt_raw ); ?>" />
				<input type="hidden" name="zone" value="<?php echo (int) $zid; ?>" />
				<p class="shn-field"><span><?php esc_html_e( 'ID προϊόντος', 'shipping-notes' ); ?></span>
					<input type="number" class="shn-num" name="product" min="1" value="<?php echo $pid ? (int) $pid : ''; ?>" />
					<button type="submit" class="button"><?php esc_html_e( 'Προεπισκόπηση', 'shipping-notes' ); ?></button>
				</p>
			</form>
			<?php
			$product = $pid ? wc_get_product( $pid ) : null;
			if ( $pid && ! $product instanceof WC_Product ) {
				echo '<p class="shn-warn">' . esc_html__( 'Δεν βρέθηκε προϊόν με αυτό το ID.', 'shipping-notes' ) . '</p>';
			} elseif ( $product instanceof WC_Product ) {
				$est = SHN_Delivery::for_product( $product );
				if ( null === $est ) {
					echo '<p class="shn-muted">' . esc_html__( 'Για αυτό το προϊόν δεν εμφανίζεται χρόνος παράδοσης (ψηφιακό, εξωτερικό, εξαντλημένο, χωρίς ρυθμισμένη μέθοδο ή χωρίς εργάσιμες).', 'shipping-notes' ) . '</p>';
				} else {
					echo '<div class="shn-preview">' . SHN_Delivery::render( $product ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in render().
					self::preview_dates( $est );
				}
			}
			?>
		</div>
		<?php
	}

	/** Today's dates per method, computed on the server (SHN_Calendar). */
	private static function preview_dates( array $est ): void {
		$s     = SHN_Settings::get();
		$now   = SHN_Calendar::now();
		$from  = '' !== $est['from'] ? new DateTimeImmutable( $est['from'] . ' 00:00:00', wp_timezone() ) : null;
		$start = SHN_Calendar::start( $from && $from > $now ? $from : $now, $from && $from > $now ? '' : $s['dl_cutoff'], $s['dl_workdays'], $s['dl_holidays'] );
		echo '<table class="widefat striped shn-table shn-mt-8"><thead><tr><th>' . esc_html__( 'Μέθοδος', 'shipping-notes' ) . '</th><th>' . esc_html__( 'Προετοιμασία', 'shipping-notes' ) . '</th><th>' . esc_html__( 'Μεταφορά', 'shipping-notes' ) . '</th><th>' . esc_html__( 'Παράδοση για παραγγελία τώρα', 'shipping-notes' ) . '</th></tr></thead><tbody>';
		foreach ( $est['lines'] as $l ) {
			$w = $start ? SHN_Calendar::window( $start, $l, $s['dl_workdays'], $s['dl_holidays'] ) : null;
			echo '<tr><td>' . esc_html( $l['method'] ) . '</td><td>' . (int) $l['pmin'] . '–' . (int) $l['pmax'] . '</td><td>' . (int) $l['tmin'] . '–' . (int) $l['tmax'] . ( $l['sat'] ? ' · ' . esc_html__( 'Σάββατο', 'shipping-notes' ) : '' ) . '</td>';
			echo '<td>' . ( $w ? esc_html( self::short_date( $w[0] ) . ' – ' . self::short_date( $w[1] ) ) : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description shn-mt-8">' . esc_html( sprintf( /* translators: %s: date and time */ __( 'Ώρα καταστήματος τώρα: %s.', 'shipping-notes' ), self::short_date( SHN_Calendar::now(), 'j/n/Y H:i' ) ) ) . '</p>';
	}

	/* ---------------------------------------------------------------------
	 * Tab: tools
	 * ------------------------------------------------------------------- */

	private static function render_tools(): void {
		?>
		<h2 class="shn-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'shipping-notes' ); ?></h2>
		<div class="shn-zone">
			<form method="post" enctype="multipart/form-data" class="shn-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'shn_backup_nonce' ); ?>
				<input type="file" name="shn_import_file" accept=".json,application/json" />
				<button type="submit" name="shn_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'shipping-notes' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=tools&shn_export=1' ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'shipping-notes' ); ?></a>
			<p class="description shn-mt-8"><?php esc_html_e( 'Οι ρυθμίσεις των μεθόδων και των ζωνών δείχνουν σε IDs του WooCommerce: σε άλλο site ίσως χρειαστεί να τις ξαναδείς. Οι τιμές προϊόντων και κατηγοριών δεν μπαίνουν στο backup.', 'shipping-notes' ); ?></p>
		</div>

		<h2 class="shn-h2"><?php esc_html_e( 'Shortcodes και μπλοκ', 'shipping-notes' ); ?></h2>
		<div class="shn-zone">
			<table class="widefat striped shn-table">
				<tbody>
					<tr><td><code>[nox_free_shipping]</code></td><td><?php esc_html_e( 'Το μήνυμα δωρεάν αποστολής για το καλάθι του επισκέπτη, οπουδήποτε. Ανανεώνεται μαζί με το καλάθι.', 'shipping-notes' ); ?></td></tr>
					<tr><td><code>[nox_delivery]</code> · <code>[nox_delivery id="123"]</code></td><td><?php esc_html_e( 'Ο χρόνος παράδοσης του τρέχοντος ή ενός συγκεκριμένου προϊόντος.', 'shipping-notes' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Μπλοκ', 'shipping-notes' ); ?></td><td><?php esc_html_e( '«Δωρεάν αποστολή (Shipping Notes)» και «Χρόνος παράδοσης (Shipping Notes)», στην ομάδα WooCommerce του editor.', 'shipping-notes' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Φίλτρα', 'shipping-notes' ); ?></td><td><code>shn_text</code> · <code>shn_threshold</code> · <code>shn_available_from</code></td></tr>
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

		// Import (own nonce, multipart form).
		if ( isset( $_POST['shn_import'] ) ) {
			if ( ! isset( $_POST['shn_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['shn_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'shipping-notes' ) );
			}
			self::redirect( 'tools', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['shn_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'shipping-notes' ) );
		}

		switch ( sanitize_key( wp_unslash( $_POST['shn_action'] ) ) ) {
			case 'save_free':
				self::redirect( 'free', self::do_save_free() );
				break;
			case 'save_delivery':
				self::redirect( 'delivery', self::do_save_delivery() );
				break;
			case 'greek_holidays':
				self::redirect( 'delivery', self::do_greek_holidays() );
				break;
			default:
				self::redirect( 'free', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'shipping-notes' ) ) ) );
		}
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		set_transient( 'shn_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/** A posted array, unslashed (sanitized by SHN_Settings::sanitize()). */
	private static function post_array( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(), sanitized by SHN_Settings::sanitize().
		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
	}

	/** Posted texts of the given keys merged into $raw. */
	private static function posted_texts( array $raw, array $keys ): array {
		$t = self::post_array( 'shn_t' );
		foreach ( $keys as $k ) {
			if ( isset( $t[ $k ] ) && is_array( $t[ $k ] ) ) {
				$raw[ $k ] = array(
					'el' => isset( $t[ $k ]['el'] ) && is_string( $t[ $k ]['el'] ) ? $t[ $k ]['el'] : '',
					'en' => isset( $t[ $k ]['en'] ) && is_string( $t[ $k ]['en'] ) ? $t[ $k ]['en'] : '',
				);
			}
		}
		return $raw;
	}

	private static function do_save_free(): array {
		$raw              = SHN_Settings::get();
		$raw['lang']      = sanitize_key( self::post( 'shn_lang' ) );
		$raw['fs_mode']   = sanitize_key( self::post( 'shn_fs_mode' ) );
		$raw['fs_source'] = sanitize_key( self::post( 'shn_fs_source' ) );
		$raw['fs_fixed']  = self::local_amount( self::post( 'shn_fs_fixed' ) );
		$raw['fs_zones']  = array();
		foreach ( self::post_array( 'shn_fs_zones' ) as $zid => $v ) {
			$raw['fs_zones'][ $zid ] = is_string( $v ) ? self::local_amount( $v ) : 0;
		}
		$raw['fs_cart_pos'] = sanitize_key( self::post( 'shn_fs_cart_pos' ) );
		foreach ( array( 'fs_cart', 'fs_minicart', 'fs_checkout', 'fs_block_cart', 'fs_block_checkout', 'fs_bar', 'fs_show_empty', 'fs_show_won' ) as $k ) {
			$raw[ $k ] = '1' === self::post( 'shn_' . $k );
		}
		$raw['fs_color'] = self::post( 'shn_fs_color' );
		$raw             = self::posted_texts( $raw, array( 'fs_t_empty', 'fs_t_left', 'fs_t_won' ) );

		SHN_Settings::save( $raw );
		$s    = SHN_Settings::get();
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'shipping-notes' ) ) );
		if ( 'fixed' === $s['fs_source'] && $s['fs_fixed'] <= 0 ) {
			$msgs[] = self::msg( 'warning', __( 'Διάλεξες σταθερό ποσό χωρίς ποσό: το μήνυμα δεν εμφανίζεται.', 'shipping-notes' ) );
		}
		if ( '' !== trim( self::post( 'shn_fs_color' ) ) && '' === $s['fs_color'] ) {
			$msgs[] = self::msg( 'warning', __( 'Το χρώμα θέλει μορφή #rrggbb: αγνοήθηκε.', 'shipping-notes' ) );
		}
		if ( 'off' !== $s['fs_mode'] && 'auto' === $s['fs_source'] ) {
			$any = false;
			foreach ( SHN_Threshold::zones() as $zone ) {
				if ( null !== SHN_Threshold::rule( $zone ) ) {
					$any = true;
					break;
				}
			}
			if ( ! $any ) {
				$msgs[] = self::msg( 'warning', __( 'Καμία ζώνη δεν έχει ενεργή «Δωρεάν αποστολή» με ελάχιστο ποσό: το μήνυμα δεν θα εμφανιστεί πουθενά.', 'shipping-notes' ) );
			}
		}
		return $msgs;
	}

	/**
	 * Amount typed by a person: with the store's separators ("1.234,50" in a
	 * Greek store), or with a lone comma as the decimal mark ("99,50").
	 */
	private static function local_amount( string $v ): float {
		$v = trim( $v );
		if ( '' === $v ) {
			return 0.0;
		}
		if ( false !== strpos( $v, ',' ) && false === strpos( $v, '.' ) && 1 === substr_count( $v, ',' ) ) {
			return SHN_Settings::amount( str_replace( ',', '.', $v ) );
		}
		return SHN_Settings::amount( wc_format_decimal( $v ) );
	}

	private static function do_save_delivery(): array {
		$raw                   = SHN_Settings::get();
		$raw['lang']           = sanitize_key( self::post( 'shn_lang' ) );
		$raw['dl_mode']        = sanitize_key( self::post( 'shn_dl_mode' ) );
		$raw['dl_zone']        = (int) self::post( 'shn_dl_zone' );
		$raw['dl_methods']     = self::post_array( 'shn_m' );
		$raw['dl_prep_min']    = self::post( 'shn_dl_prep_min' );
		$raw['dl_prep_max']    = self::post( 'shn_dl_prep_max' );
		$raw['dl_bo']          = '1' === self::post( 'shn_dl_bo' );
		$raw['dl_bo_min']      = self::post( 'shn_dl_bo_min' );
		$raw['dl_bo_max']      = self::post( 'shn_dl_bo_max' );
		$raw['dl_cutoff']      = self::post( 'shn_dl_cutoff' );
		$raw['dl_workdays']    = self::post_array( 'shn_dl_workdays' );
		$raw['dl_holidays']    = self::post( 'shn_dl_holidays' );
		$raw['dl_format']      = sanitize_key( self::post( 'shn_dl_format' ) );
		$raw['dl_position']    = sanitize_key( self::post( 'shn_dl_position' ) );
		$raw['dl_dates']       = '1' === self::post( 'shn_dl_dates' );
		$raw['dl_cutoff_line'] = '1' === self::post( 'shn_dl_cutoff_line' );
		$raw                   = self::posted_texts( $raw, array( 'dl_t_est', 'dl_t_item', 'dl_t_title', 'dl_t_one', 'dl_t_many', 'dl_t_dates', 'dl_t_item_dates', 'dl_t_cutoff', 'dl_t_preorder' ) );

		SHN_Settings::save( $raw );
		$s    = SHN_Settings::get();
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'shipping-notes' ) ) );

		$typed = trim( self::post( 'shn_dl_cutoff' ) );
		if ( '' !== $typed && ( ! preg_match( '/^(\d{1,2})[:.](\d{2})(?::\d{2})?$/', $typed, $tm ) || (int) $tm[1] > 23 || (int) $tm[2] > 59 ) ) {
			$msgs[] = self::msg( 'warning', __( 'Η ώρα cut-off θέλει μορφή ΩΩ:ΛΛ: κρατήθηκε η προηγούμενη.', 'shipping-notes' ) );
		}
		$lines = array_filter( preg_split( '/[\s,;]+/', self::post( 'shn_dl_holidays' ) ) );
		if ( count( $lines ) > count( $s['dl_holidays'] ) ) {
			$msgs[] = self::msg( 'warning', __( 'Κάποιες αργίες ήταν άκυρες, διπλές ή περασμένες και αγνοήθηκαν.', 'shipping-notes' ) );
		}
		if ( ! $s['dl_workdays'] ) {
			$msgs[] = self::msg( 'warning', __( 'Δεν υπάρχει καμία εργάσιμη: ο χρόνος παράδοσης δεν εμφανίζεται.', 'shipping-notes' ) );
		}
		if ( 'off' !== $s['dl_mode'] && ! SHN_Delivery::methods() ) {
			$msgs[] = self::msg( 'warning', __( 'Η ζώνη της σελίδας προϊόντος δεν έχει μέθοδο με ημέρες μεταφοράς: ο χρόνος παράδοσης δεν εμφανίζεται.', 'shipping-notes' ) );
		}
		return $msgs;
	}

	private static function do_greek_holidays(): array {
		$s    = SHN_Settings::get();
		$year = (int) wp_date( 'Y' );
		$add  = array_merge( SHN_Calendar::greek_holidays( $year ), SHN_Calendar::greek_holidays( $year + 1 ) );
		$old  = count( $s['dl_holidays'] );

		$s['dl_holidays'] = array_merge( $s['dl_holidays'], $add );
		SHN_Settings::save( $s );
		$new = count( SHN_Settings::get()['dl_holidays'] ) - $old;
		return array(
			self::msg(
				'success',
				sprintf( /* translators: %d: number of dates */ __( 'Προστέθηκαν %d αργίες.', 'shipping-notes' ), max( 0, $new ) )
			),
		);
	}

	/* =====================================================================
	 * Backup (export GET / import POST)
	 * =================================================================== */

	public static function route_export(): void {
		if ( self::SLUG !== self::current_page() || '' === self::q( 'shn_export' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'shipping-notes' ) );
		}
		$state = array(
			'plugin'  => 'shipping-notes',
			'version' => SHN_VERSION,
			'options' => array(
				SHN_Settings::OPT => SHN_Settings::get(),
			),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="shipping-notes-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	/**
	 * Strict import: the blob must be an object; it goes through
	 * SHN_Settings::sanitize() (the same validation as the forms). An invalid
	 * file leaves the current settings untouched and says so.
	 */
	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['shn_import_file']['error'] ) ? (int) $_FILES['shn_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['shn_import_file']['size'] ) ? (int) $_FILES['shn_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'shipping-notes' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['shn_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['shn_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'shipping-notes' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['shn_import_file']['tmp_name'] ), true );
		// phpcs:enable
		if ( ! is_array( $state ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'shipping-notes' ) ) );
		}
		$blob = isset( $state['options'][ SHN_Settings::OPT ] ) ? $state['options'][ SHN_Settings::OPT ] : null;
		if ( is_string( $blob ) ) {
			$blob = json_decode( $blob, true );
		}
		if ( ! is_array( $blob ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Shipping Notes).', 'shipping-notes' ) ) );
		}
		SHN_Settings::save( $blob );
		return array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'shipping-notes' ) ) );
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'shn_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'shn_aui_msg_' . get_current_user_id() );
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
		<p class="shn-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'shipping-notes' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="shn-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="shn-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'shipping-notes' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="shn-footer-cta">
			<a class="shn-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'shipping-notes' ); ?>
			</a>
			<a class="shn-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'shipping-notes' ); ?>
			</a>
		</p>
		<?php
	}
}

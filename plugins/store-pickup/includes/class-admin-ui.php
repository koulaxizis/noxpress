<?php
/**
 * PKP_Admin_UI — menu, settings page, PRG routes, backup (Bible §3–§8, §11).
 *
 * One page, submenu «Παραλαβή» of the shared "Noxpress" menu (admin_menu
 * priority 110), slug pkp-settings, with tabs:
 *  - stores  mode, shipping address hiding, which zones have the method,
 *            the store list (name, address, hours / details el/en, map
 *            link, active, order; add / delete)
 *  - texts   visitor text language and texts
 *  - tools   settings export / import, filter and API
 *
 * Every state change: POST on admin_init, slug + nonce + capability,
 * strict validation (PKP_Settings::sanitize), PRG with the message in the
 * transient pkp_aui_msg_{uid}. Export: privileged GET (slug + nonce +
 * capability). Saving flushes WooCommerce's cached shipping rates.
 */

defined( 'ABSPATH' ) || exit;

final class PKP_Admin_UI {

	const CAP         = 'manage_woocommerce';
	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'pkp-settings';
	const NONCE       = 'pkp_admin';
	const NONCE_FIELD = 'pkp_nonce';
	const NONCE_BAK   = 'pkp_backup';
	const MAX_IMPORT  = 262144;
	const NEW_ROWS    = 2;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 110 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PKP_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label msgid. */
	private static function tabs(): array {
		return array(
			'stores' => __( 'Καταστήματα', 'store-pickup' ),
			'texts'  => __( 'Κείμενα', 'store-pickup' ),
			'tools'  => __( 'Εργαλεία', 'store-pickup' ),
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
			__( 'Store Pickup', 'store-pickup' ),
			__( 'Παραλαβή', 'store-pickup' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'store-pickup' ) . '</a>'
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
		return isset( self::tabs()[ $tab ] ) ? $tab : 'stores';
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'pkp-admin', PKP_URL . 'assets/admin.css', array(), PKP_VERSION );
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'store-pickup' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap pkp-wrap">
			<h1><?php esc_html_e( 'Store Pickup', 'store-pickup' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Παραλαβή από το κατάστημα ως μέθοδος αποστολής, με επιλογή καταστήματος στο ταμείο.', 'store-pickup' ); ?></p>

			<?php self::print_notices( self::take_msgs() ); ?>

			<nav class="nav-tab-wrapper pkp-tabs">
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
				case 'texts':
					self::render_texts();
					break;
				case 'tools':
					self::render_tools();
					break;
				default:
					self::render_stores();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	/** Mode radios (off / test / live). */
	private static function mode_field( string $value ): void {
		$opts = array(
			'off'  => __( 'Ανενεργό', 'store-pickup' ),
			'test' => __( 'Λειτουργία δοκιμής: φαίνεται μόνο στους διαχειριστές του καταστήματος', 'store-pickup' ),
			'live' => __( 'Ενεργό για όλους', 'store-pickup' ),
		);
		foreach ( $opts as $k => $label ) {
			echo '<label class="pkp-check"><input type="radio" name="pkp_mode" value="' . esc_attr( $k ) . '" ' . checked( $value, $k, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή', 'store-pickup' ) . ' <code>define( \'PKP_DISABLE\', true );</code></p>';
	}

	/**
	 * Every shipping zone with the state of our method in it.
	 *
	 * @return array[] { name, state: 'on' | 'off' | 'none' }
	 */
	private static function zone_states(): array {
		$out   = array();
		$zones = array();
		foreach ( WC_Shipping_Zones::get_zones() as $z ) {
			$zones[] = new WC_Shipping_Zone( (int) $z['id'] );
		}
		$zones[] = new WC_Shipping_Zone( 0 );
		foreach ( $zones as $zone ) {
			$state = 'none';
			foreach ( $zone->get_shipping_methods( false ) as $m ) {
				if ( PKP_Method::ID === $m->id ) {
					$state = $m->is_enabled() ? 'on' : ( 'none' === $state ? 'off' : $state );
					if ( 'on' === $state ) {
						break;
					}
				}
			}
			$name  = 0 === (int) $zone->get_id() ? __( 'Περιοχές που δεν καλύπτονται από άλλες ζώνες', 'store-pickup' ) : (string) $zone->get_zone_name();
			$out[] = array(
				'name'  => $name,
				'state' => $state,
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Tab: stores
	 * ------------------------------------------------------------------- */

	private static function render_stores(): void {
		$s = PKP_Settings::get();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="pkp_action" value="save_stores" />

			<h2 class="pkp-h2"><?php esc_html_e( 'Λειτουργία', 'store-pickup' ); ?></h2>
			<div class="pkp-zone">
				<?php
				if ( Store_Pickup::killed() ) {
					echo '<p class="pkp-warn">' . esc_html__( 'Το PKP_DISABLE είναι ενεργό στο wp-config.php: η μέθοδος δεν εμφανίζεται πουθενά.', 'store-pickup' ) . '</p>';
				}
				self::mode_field( $s['mode'] );
				?>
				<label class="pkp-check pkp-mt-8"><input type="checkbox" name="pkp_hide_address" value="1" <?php checked( $s['hide_address'] ); ?> /> <?php esc_html_e( 'Απόκρυψη της διεύθυνσης αποστολής όταν ο πελάτης διαλέγει παραλαβή (ταμείο, παραγγελία, emails)', 'store-pickup' ); ?></label>
			</div>

			<h2 class="pkp-h2"><?php esc_html_e( 'Ζώνες αποστολής', 'store-pickup' ); ?></h2>
			<div class="pkp-zone">
				<p class="description"><?php esc_html_e( 'Η μέθοδος «Παραλαβή από το κατάστημα» μπαίνει σε κάθε ζώνη όπως όλες οι μέθοδοι του WooCommerce. Εκεί ορίζεις όνομα, κόστος (0 = χωρίς χρέωση) και ποια καταστήματα προσφέρονται.', 'store-pickup' ); ?></p>
				<table class="widefat pkp-table">
					<thead><tr><th><?php esc_html_e( 'Ζώνη', 'store-pickup' ); ?></th><th><?php esc_html_e( 'Παραλαβή από το κατάστημα', 'store-pickup' ); ?></th></tr></thead>
					<tbody>
					<?php
					foreach ( self::zone_states() as $z ) {
						if ( 'on' === $z['state'] ) {
							$badge = '<span class="pkp-badge pkp-badge--ok">' . esc_html__( 'ενεργή', 'store-pickup' ) . '</span>';
						} elseif ( 'off' === $z['state'] ) {
							$badge = '<span class="pkp-badge pkp-badge--warn">' . esc_html__( 'ανενεργή', 'store-pickup' ) . '</span>';
						} else {
							$badge = '<span class="pkp-badge pkp-badge--muted">' . esc_html__( 'δεν έχει προστεθεί', 'store-pickup' ) . '</span>';
						}
						echo '<tr><td>' . esc_html( $z['name'] ) . '</td><td>' . $badge . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $badge escaped above.
					}
					?>
					</tbody>
				</table>
				<p class="pkp-mt-8"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ); ?>"><?php esc_html_e( 'Ζώνες αποστολής του WooCommerce', 'store-pickup' ); ?></a></p>
			</div>

			<h2 class="pkp-h2"><?php esc_html_e( 'Καταστήματα', 'store-pickup' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Ο πελάτης βλέπει τα ενεργά καταστήματα με αυτή τη σειρά. Οι ώρες και οι διευκρινίσεις δέχονται έως 5 γραμμές. Κενό αγγλικό κείμενο = δείχνει το ελληνικό.', 'store-pickup' ); ?></p>
			<?php
			$i = 0;
			foreach ( $s['stores'] as $st ) {
				self::store_card( $i, $st, $i + 1 );
				++$i;
			}
			for ( $n = 0; $n < self::NEW_ROWS && $i < PKP_Settings::MAX_STORES; $n++ ) {
				self::store_card( $i, null, $i + 1 );
				++$i;
			}
			?>
			<p class="pkp-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'store-pickup' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * One store card (an empty one for a new store).
	 *
	 * @param int        $i     Row index.
	 * @param array|null $st    Store, or null for a new one.
	 * @param int        $order Position.
	 */
	private static function store_card( int $i, ?array $st, int $order ): void {
		$new  = null === $st;
		$st   = $new ? array(
			'id'         => '',
			'name'       => '',
			'address'    => '',
			'details_el' => '',
			'details_en' => '',
			'map'        => '',
			'active'     => true,
		) : $st;
		$name = 'pkp_s[' . $i . ']';
		$lens = PKP_Settings::STORE_FIELDS;
		?>
		<fieldset class="pkp-zone pkp-store-card<?php echo $new ? ' pkp-store-card--new' : ''; ?>">
			<legend><?php echo $new ? esc_html__( 'Νέο κατάστημα', 'store-pickup' ) : esc_html( $st['name'] ); ?></legend>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $st['id'] ); ?>" />
			<div class="pkp-grid">
				<label class="pkp-label"><?php esc_html_e( 'Όνομα', 'store-pickup' ); ?>
					<input type="text" class="pkp-wide" name="<?php echo esc_attr( $name ); ?>[name]" value="<?php echo esc_attr( $st['name'] ); ?>" maxlength="<?php echo (int) $lens['name']; ?>" />
				</label>
				<label class="pkp-label"><?php esc_html_e( 'Διεύθυνση', 'store-pickup' ); ?>
					<input type="text" class="pkp-wide" name="<?php echo esc_attr( $name ); ?>[address]" value="<?php echo esc_attr( $st['address'] ); ?>" maxlength="<?php echo (int) $lens['address']; ?>" />
				</label>
				<label class="pkp-label"><?php esc_html_e( 'Ώρες και διευκρινίσεις (Ελληνικά)', 'store-pickup' ); ?>
					<textarea class="pkp-wide" rows="3" name="<?php echo esc_attr( $name ); ?>[details_el]" maxlength="<?php echo (int) $lens['details_el']; ?>"><?php echo esc_textarea( $st['details_el'] ); ?></textarea>
				</label>
				<label class="pkp-label"><?php esc_html_e( 'Ώρες και διευκρινίσεις (English)', 'store-pickup' ); ?>
					<textarea class="pkp-wide" rows="3" name="<?php echo esc_attr( $name ); ?>[details_en]" maxlength="<?php echo (int) $lens['details_en']; ?>"><?php echo esc_textarea( $st['details_en'] ); ?></textarea>
				</label>
				<label class="pkp-label"><?php esc_html_e( 'Σύνδεσμος χάρτη (προαιρετικό)', 'store-pickup' ); ?>
					<input type="url" class="pkp-wide" name="<?php echo esc_attr( $name ); ?>[map]" value="<?php echo esc_attr( $st['map'] ); ?>" maxlength="<?php echo (int) $lens['map']; ?>" placeholder="https://" />
				</label>
			</div>
			<p class="pkp-row">
				<label class="pkp-inline"><?php esc_html_e( 'Σειρά', 'store-pickup' ); ?> <input type="number" class="pkp-num" min="1" max="<?php echo (int) PKP_Settings::MAX_STORES; ?>" name="<?php echo esc_attr( $name ); ?>[order]" value="<?php echo (int) $order; ?>" /></label>
				<label class="pkp-inline"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[active]" value="1" <?php checked( $st['active'] ); ?> /> <?php esc_html_e( 'Ενεργό', 'store-pickup' ); ?></label>
				<?php if ( ! $new ) : ?>
					<label class="pkp-inline pkp-warn"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete]" value="1" /> <?php esc_html_e( 'Διαγραφή', 'store-pickup' ); ?></label>
				<?php endif; ?>
			</p>
		</fieldset>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: texts
	 * ------------------------------------------------------------------- */

	private static function render_texts(): void {
		$s    = PKP_Settings::get();
		$rows = array(
			't_heading'  => __( 'Τίτλος της λίστας στο ταμείο και γραμμή στην παραγγελία', 'store-pickup' ),
			't_details'  => __( 'Ετικέτα των ωρών στην παραγγελία (admin)', 'store-pickup' ),
			't_required' => __( 'Σφάλμα όταν δεν επιλέχθηκε κατάστημα', 'store-pickup' ),
			't_map'      => __( 'Σύνδεσμος χάρτη', 'store-pickup' ),
		);
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="pkp_action" value="save_texts" />
			<h2 class="pkp-h2"><?php esc_html_e( 'Κείμενα για τον πελάτη', 'store-pickup' ); ?></h2>
			<div class="pkp-zone">
				<p class="pkp-field"><span><?php esc_html_e( 'Γλώσσα κειμένων', 'store-pickup' ); ?></span><select name="pkp_lang">
				<?php
				foreach ( array(
					'auto' => __( 'Αυτόματα (γλώσσα του site)', 'store-pickup' ),
					'el'   => __( 'Ελληνικά', 'store-pickup' ),
					'en'   => __( 'English', 'store-pickup' ),
				) as $k => $label ) {
					echo '<option value="' . esc_attr( $k ) . '" ' . selected( $s['lang'], $k, false ) . '>' . esc_html( $label ) . '</option>';
				}
				?>
				</select></p>
				<p class="description"><?php esc_html_e( 'Το όνομα της μεθόδου (ελληνικά και αγγλικά) ορίζεται σε κάθε ζώνη αποστολής.', 'store-pickup' ); ?></p>
				<table class="widefat pkp-table pkp-texts"><thead><tr><th><?php esc_html_e( 'Κείμενο', 'store-pickup' ); ?></th><th><?php esc_html_e( 'Ελληνικά', 'store-pickup' ); ?></th><th><?php esc_html_e( 'English', 'store-pickup' ); ?></th></tr></thead><tbody>
				<?php
				foreach ( $rows as $key => $label ) {
					echo '<tr><td>' . esc_html( $label ) . '</td>';
					foreach ( array( 'el', 'en' ) as $l ) {
						echo '<td><input type="text" name="pkp_t[' . esc_attr( $key ) . '][' . esc_attr( $l ) . ']" value="' . esc_attr( $s[ $key ][ $l ] ) . '" maxlength="' . (int) PKP_Settings::TEXTS[ $key ] . '" /></td>';
					}
					echo '</tr>';
				}
				?>
				</tbody></table>
				<p class="description pkp-mt-8"><?php esc_html_e( 'Κενό ελληνικό κείμενο = το αρχικό. Κενό αγγλικό = δείχνει το ελληνικό.', 'store-pickup' ); ?></p>
			</div>
			<p class="pkp-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'store-pickup' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: tools
	 * ------------------------------------------------------------------- */

	private static function render_tools(): void {
		?>
		<h2 class="pkp-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'store-pickup' ); ?></h2>
		<div class="pkp-zone">
			<form method="post" enctype="multipart/form-data" class="pkp-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'pkp_backup_nonce' ); ?>
				<input type="file" name="pkp_import_file" accept=".json,application/json" />
				<button type="submit" name="pkp_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'store-pickup' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=tools&pkp_export=1' ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'store-pickup' ); ?></a>
			<p class="description pkp-mt-8"><?php esc_html_e( 'Το backup έχει τα καταστήματα και τα κείμενα. Οι ρυθμίσεις της μεθόδου σε κάθε ζώνη (όνομα, κόστος, καταστήματα) μένουν στο WooCommerce.', 'store-pickup' ); ?></p>
		</div>

		<h2 class="pkp-h2"><?php esc_html_e( 'Για προγραμματιστές', 'store-pickup' ); ?></h2>
		<div class="pkp-zone">
			<table class="widefat pkp-table">
				<tbody>
					<tr><td><code>pkp_get_order_store( $order )</code></td><td><?php esc_html_e( 'Το κατάστημα παραλαβής μιας παραγγελίας (id, όνομα, διεύθυνση, διευκρινίσεις, χάρτης), όπως αποθηκεύτηκε στο ταμείο, ή null.', 'store-pickup' ); ?></td></tr>
					<tr><td><code>pkp_text</code></td><td><?php esc_html_e( 'Φίλτρο για τα κείμενα του πελάτη (π.χ. WPML).', 'store-pickup' ); ?></td></tr>
					<tr><td><code>nox_store_pickup</code></td><td><?php esc_html_e( 'Το id της μεθόδου αποστολής.', 'store-pickup' ); ?></td></tr>
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
		if ( isset( $_POST['pkp_import'] ) ) {
			if ( ! isset( $_POST['pkp_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['pkp_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'store-pickup' ) );
			}
			self::redirect( 'tools', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['pkp_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'store-pickup' ) );
		}

		switch ( sanitize_key( wp_unslash( $_POST['pkp_action'] ) ) ) {
			case 'save_stores':
				self::redirect( 'stores', self::do_save_stores() );
				break;
			case 'save_texts':
				self::redirect( 'texts', self::do_save_texts() );
				break;
			default:
				self::redirect( 'stores', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'store-pickup' ) ) ) );
		}
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		set_transient( 'pkp_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/** A posted array, unslashed (sanitized by PKP_Settings::sanitize()). */
	private static function post_array( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(), sanitized by PKP_Settings::sanitize().
		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
	}

	/** Saved settings change the offered rates: drop WooCommerce's cached ones. */
	private static function flush_rates(): void {
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	}

	private static function do_save_stores(): array {
		$raw                 = PKP_Settings::get();
		$raw['mode']         = sanitize_key( self::post( 'pkp_mode' ) );
		$raw['hide_address'] = '1' === self::post( 'pkp_hide_address' );

		$known = array();
		foreach ( $raw['stores'] as $st ) {
			$known[ $st['id'] ] = true;
		}

		$rows = array();
		$pos  = 0;
		foreach ( self::post_array( 'pkp_s' ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$str = static function ( string $k ) use ( $row ): string {
				return isset( $row[ $k ] ) && is_string( $row[ $k ] ) ? $row[ $k ] : '';
			};
			if ( '1' === $str( 'delete' ) || '' === trim( $str( 'name' ) ) ) {
				continue;
			}
			$id = sanitize_key( $str( 'id' ) );
			// Only ids this site already has; anything else is a new store.
			if ( ! isset( $known[ $id ] ) ) {
				$id = PKP_Settings::new_id();
			}
			$rows[] = array(
				'sort'  => array( max( 1, (int) $str( 'order' ) ), $pos++ ),
				'store' => array(
					'id'         => $id,
					'name'       => $str( 'name' ),
					'address'    => $str( 'address' ),
					'details_el' => $str( 'details_el' ),
					'details_en' => $str( 'details_en' ),
					'map'        => $str( 'map' ),
					'active'     => '1' === $str( 'active' ),
				),
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return $a['sort'] <=> $b['sort'];
			}
		);
		$raw['stores'] = array_column( $rows, 'store' );

		PKP_Settings::save( $raw );
		self::flush_rates();
		$s    = PKP_Settings::get();
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'store-pickup' ) ) );

		foreach ( $raw['stores'] as $i => $st ) {
			if ( '' !== trim( $st['map'] ) && ( ! isset( $s['stores'][ $i ] ) || '' === $s['stores'][ $i ]['map'] ) ) {
				$msgs[] = self::msg( 'warning', __( 'Ένας σύνδεσμος χάρτη δεν ήταν έγκυρη διεύθυνση http(s) και αγνοήθηκε.', 'store-pickup' ) );
				break;
			}
		}
		if ( count( $rows ) > PKP_Settings::MAX_STORES ) {
			$msgs[] = self::msg( 'warning', __( 'Κρατήθηκαν μόνο τα πρώτα 20 καταστήματα.', 'store-pickup' ) );
		}
		if ( 'off' !== $s['mode'] ) {
			if ( ! PKP_Settings::active_stores() ) {
				$msgs[] = self::msg( 'warning', __( 'Δεν υπάρχει ενεργό κατάστημα: η μέθοδος δεν εμφανίζεται στο ταμείο.', 'store-pickup' ) );
			}
			$any = false;
			foreach ( self::zone_states() as $z ) {
				if ( 'on' === $z['state'] ) {
					$any = true;
					break;
				}
			}
			if ( ! $any ) {
				$msgs[] = self::msg( 'warning', __( 'Καμία ζώνη αποστολής δεν έχει ενεργή τη μέθοδο «Παραλαβή από το κατάστημα»: πρόσθεσέ τη στις ζώνες του WooCommerce.', 'store-pickup' ) );
			}
		}
		return $msgs;
	}

	private static function do_save_texts(): array {
		$raw         = PKP_Settings::get();
		$raw['lang'] = sanitize_key( self::post( 'pkp_lang' ) );
		$t           = self::post_array( 'pkp_t' );
		foreach ( array_keys( PKP_Settings::TEXTS ) as $k ) {
			if ( isset( $t[ $k ] ) && is_array( $t[ $k ] ) ) {
				$raw[ $k ] = array(
					'el' => isset( $t[ $k ]['el'] ) && is_string( $t[ $k ]['el'] ) ? $t[ $k ]['el'] : '',
					'en' => isset( $t[ $k ]['en'] ) && is_string( $t[ $k ]['en'] ) ? $t[ $k ]['en'] : '',
				);
			}
		}
		PKP_Settings::save( $raw );
		self::flush_rates();
		return array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'store-pickup' ) ) );
	}

	/* =====================================================================
	 * Backup (export GET / import POST)
	 * =================================================================== */

	public static function route_export(): void {
		if ( self::SLUG !== self::current_page() || '' === self::q( 'pkp_export' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'store-pickup' ) );
		}
		$state = array(
			'plugin'  => 'store-pickup',
			'version' => PKP_VERSION,
			'options' => array(
				PKP_Settings::OPT => PKP_Settings::get(),
			),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="store-pickup-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	/**
	 * Strict import: the blob must be an object; it goes through
	 * PKP_Settings::sanitize() (the same validation as the forms). An invalid
	 * file leaves the current settings untouched and says so.
	 */
	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['pkp_import_file']['error'] ) ? (int) $_FILES['pkp_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['pkp_import_file']['size'] ) ? (int) $_FILES['pkp_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'store-pickup' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['pkp_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['pkp_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'store-pickup' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['pkp_import_file']['tmp_name'] ), true );
		// phpcs:enable
		if ( ! is_array( $state ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'store-pickup' ) ) );
		}
		$blob = isset( $state['options'][ PKP_Settings::OPT ] ) ? $state['options'][ PKP_Settings::OPT ] : null;
		if ( is_string( $blob ) ) {
			$blob = json_decode( $blob, true );
		}
		if ( ! is_array( $blob ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Store Pickup).', 'store-pickup' ) ) );
		}
		PKP_Settings::save( $blob );
		self::flush_rates();
		return array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'store-pickup' ) ) );
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'pkp_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'pkp_aui_msg_' . get_current_user_id() );
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
		<p class="pkp-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'store-pickup' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="pkp-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="pkp-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'store-pickup' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="pkp-footer-cta">
			<a class="pkp-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'store-pickup' ); ?>
			</a>
			<a class="pkp-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'store-pickup' ); ?>
			</a>
		</p>
		<?php
	}
}

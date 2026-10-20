<?php
/**
 * BKF_Admin_UI — menu, page, PRG routes, CSV, backup (Bible §3–§8, §11).
 *
 * One page, submenu «Τίτλοι εκδοτών» of the shared "Noxpress" menu
 * (admin_menu priority 240), slug bkf-feed, with tabs:
 *  - search    the feed's catalogue: title, contributor, ISBN, publisher,
 *              years, kind; results with "in the store" / "same ISBN"
 *              marks; import of the ticked titles (AJAX, 5 per request)
 *  - rules     automatic imports per publisher
 *  - log       history, filter by act, CSV export, clear
 *  - settings  connection, import, field mapping, roles, categories,
 *              VAT, availability, sync, fixed price, uninstall
 *  - tools     settings backup (JSON, without the password), developers
 *
 * A status box on every tab: connection, the running job (with stop),
 * the last run and "Sync now".
 *
 * Every state change: POST on admin_init, slug + nonce + capability,
 * strict validation, PRG with the message in bkf_aui_msg_{uid}. CSV and
 * backup export: privileged GET (slug + nonce + capability). The search
 * is a GET with its own nonce, since it calls the service.
 */

defined( 'ABSPATH' ) || exit;

final class BKF_Admin_UI {

	const CAP         = 'manage_woocommerce';
	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'bkf-feed';
	const NONCE       = 'bkf_admin';
	const NONCE_FIELD = 'bkf_nonce';
	const NONCE_BAK   = 'bkf_backup';
	const NONCE_FIND  = 'bkf_find';
	const MAX_IMPORT  = 262144;
	const PER_PAGE    = 20;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 240 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_get' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'global_notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BKF_FILE ), array( __CLASS__, 'action_links' ) );

		require_once BKF_PATH . 'includes/class-admin-ajax.php';
		BKF_Admin_Ajax::init();
	}

	private static function tabs(): array {
		return array(
			'search'   => __( 'Αναζήτηση', 'book-feed' ),
			'rules'    => __( 'Κανόνες', 'book-feed' ),
			'log'      => __( 'Ιστορικό', 'book-feed' ),
			'settings' => __( 'BF Ρυθμίσεις', 'book-feed' ),
			'tools'    => __( 'Εργαλεία', 'book-feed' ),
		);
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Book Feed', 'book-feed' ),
			__( 'Τίτλοι εκδοτών', 'book-feed' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'book-feed' ) . '</a>'
		);
		return $links;
	}

	public static function url( string $tab, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	/** A sanitized GET parameter (read-only routing). */
	public static function q( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	private static function current_tab(): string {
		$tab = sanitize_key( self::q( 'tab' ) );
		return isset( self::tabs()[ $tab ] ) ? $tab : 'search';
	}

	public static function assets( $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_product = $screen && 'product' === $screen->post_type && 'post' === $screen->base;
		if ( self::SLUG !== self::current_page() && ! $is_product ) {
			return;
		}
		wp_enqueue_style( 'bkf-admin', BKF_URL . 'assets/admin.css', array(), BKF_VERSION );
		wp_enqueue_script( 'bkf-admin', BKF_URL . 'assets/admin.js', array(), BKF_VERSION, true );
		wp_localize_script(
			'bkf-admin',
			'bkfAdmin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( BKF_Admin_Ajax::NONCE ),
				'i18n'  => array(
					'none'      => __( 'Δεν βρέθηκε εκδότης.', 'book-feed' ),
					'pick'      => __( 'Τσέκαρε τουλάχιστον έναν τίτλο.', 'book-feed' ),
					'importing' => __( 'Εισαγωγή…', 'book-feed' ),
					'done'      => __( 'Η εισαγωγή τελείωσε.', 'book-feed' ),
					'imported'  => __( 'Εισήχθη', 'book-feed' ),
					'updated'   => __( 'Ενημερώθηκε', 'book-feed' ),
					'exists'    => __( 'Υπάρχει ήδη (ίδιο ISBN)', 'book-feed' ),
					'missing'   => __( 'Δεν βρέθηκε στην υπηρεσία', 'book-feed' ),
					'error'     => __( 'Σφάλμα', 'book-feed' ),
					'busy'      => __( 'Τρέχει συγχρονισμός· ξαναδοκιμάζω…', 'book-feed' ),
					'linked'    => __( 'Συνδέθηκε', 'book-feed' ),
					'instore'   => __( 'Στο κατάστημα', 'book-feed' ),
					'failed'    => __( 'Η σύνδεση με τον server απέτυχε.', 'book-feed' ),
					'finished'  => __( 'Ο συγχρονισμός τελείωσε.', 'book-feed' ),
					'reload'    => __( 'Ανανέωση σελίδας', 'book-feed' ),
					'confirm'   => __( 'Σίγουρα;', 'book-feed' ),
					'take'      => __( 'Όλα τα πεδία του προϊόντος θα γραφτούν ξανά από τον κατάλογο. Συνέχεια;', 'book-feed' ),
					'unlink'    => __( 'Το προϊόν μένει όπως είναι, αλλά δεν θα συγχρονίζεται πια. Συνέχεια;', 'book-feed' ),
				),
			)
		);
	}

	/** Site-wide notices for shop managers: unreadable password. */
	public static function global_notices(): void {
		if ( ! current_user_can( self::CAP ) || 'unreadable' !== BKF_Settings::password_source() ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'Book Feed: ο αποθηκευμένος κωδικός της υπηρεσίας δεν διαβάζεται πια (άλλαξαν τα κλειδιά του wp-config.php). Γράψ’ τον ξανά στις Ρυθμίσεις.', 'book-feed' )
			. ' <a href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'book-feed' ) . '</a></p></div>';
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'book-feed' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap bkf-wrap">
			<h1><?php esc_html_e( 'Book Feed', 'book-feed' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Τίτλοι άλλων εκδοτικών από τον κατάλογο Bookpoint (ΟΣΔΕΛ) ως προϊόντα του καταστήματος, με καθημερινό συγχρονισμό τιμής και διαθεσιμότητας.', 'book-feed' ); ?></p>

			<?php
			self::print_notices( self::take_msgs() );
			self::status_box();
			?>

			<nav class="nav-tab-wrapper bkf-tabs">
				<?php
				foreach ( self::tabs() as $slug => $label ) {
					printf(
						'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
						esc_url( self::url( $slug ) ),
						$slug === $tab ? ' nav-tab-active' : '',
						esc_html( $label )
					);
				}
				?>
			</nav>

			<?php
			switch ( $tab ) {
				case 'rules':
					self::render_rules();
					break;
				case 'log':
					self::render_log();
					break;
				case 'settings':
					self::render_settings();
					break;
				case 'tools':
					self::render_tools();
					break;
				default:
					BKF_Admin_Ajax::render_search();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	/** Connection, running job, last run, buttons. */
	private static function status_box(): void {
		$s    = BKF_Settings::get();
		$src  = BKF_Settings::password_source();
		$job  = BKF_Sync::job();
		$last = BKF_Sync::last();
		?>
		<div class="bkf-zone bkf-status" id="bkf-status">
			<div class="bkf-status-row">
				<?php
				if ( ! BKF_Settings::ready() ) {
					echo '<span class="bkf-badge bkf-badge--warn">' . esc_html__( 'Χωρίς σύνδεση', 'book-feed' ) . '</span> ';
					echo '<a href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Γράψε όνομα χρήστη και κωδικό στις Ρυθμίσεις.', 'book-feed' ) . '</a>';
				} else {
					/* translators: %s: user name */
					echo '<span class="bkf-badge bkf-badge--ok">' . esc_html( sprintf( __( 'Χρήστης %s', 'book-feed' ), $s['user'] ) ) . '</span> ';
					if ( 'http' === BKF_Client::scheme() ) {
						echo '<span class="bkf-badge bkf-badge--warn">' . esc_html__( 'http χωρίς κρυπτογράφηση', 'book-feed' ) . '</span> ';
					} elseif ( 'https' === BKF_Client::scheme() ) {
						echo '<span class="bkf-badge bkf-badge--ok">https</span> ';
					}
					if ( 'const' === $src ) {
						echo '<span class="bkf-badge bkf-badge--muted">BKF_PASSWORD</span> ';
					}
				}
				echo '<span class="bkf-muted"> · </span>';
				echo '<span class="bkf-badge bkf-badge--muted">' . esc_html( sprintf( /* translators: %d: products */ _n( '%d τίτλος στο κατάστημα', '%d τίτλοι στο κατάστημα', self::count_ours(), 'book-feed' ), self::count_ours() ) ) . '</span>';
				?>
			</div>
			<div class="bkf-status-row">
				<?php if ( $job ) : ?>
					<span class="bkf-badge bkf-badge--warn bkf-running"><?php esc_html_e( 'Συγχρονισμός σε εξέλιξη', 'book-feed' ); ?></span>
					<span class="bkf-muted bkf-job-text"><?php echo esc_html( self::stats_text( $job['stats'] ) ); ?></span>
					<form method="post" class="bkf-inline-form">
						<?php self::nonce_field(); ?>
						<input type="hidden" name="bkf_action" value="cancel" />
						<button type="submit" class="button"><?php esc_html_e( 'Διακοπή', 'book-feed' ); ?></button>
					</form>
				<?php else : ?>
					<?php if ( $last ) : ?>
						<span class="bkf-muted">
							<?php
							/* translators: 1: date and time, 2: summary */
							echo esc_html( sprintf( __( 'Τελευταίος συγχρονισμός %1$s: %2$s', 'book-feed' ), self::when( $last['t'] ), self::stats_text( $last['stats'] ) ) );
							?>
						</span>
						<?php if ( '' !== $last['error'] ) : ?>
							<span class="bkf-badge bkf-badge--danger"><?php echo esc_html( $last['error'] ); ?></span>
						<?php endif; ?>
					<?php else : ?>
						<span class="bkf-muted"><?php esc_html_e( 'Δεν έχει γίνει ακόμα συγχρονισμός.', 'book-feed' ); ?></span>
					<?php endif; ?>
					<form method="post" class="bkf-inline-form">
						<?php self::nonce_field(); ?>
						<input type="hidden" name="bkf_action" value="sync" />
						<button type="submit" class="button" <?php disabled( ! BKF_Settings::ready() ); ?>><?php esc_html_e( 'Συγχρονισμός τώρα', 'book-feed' ); ?></button>
					</form>
					<?php if ( $s['sync_on'] ) : ?>
						<span class="bkf-muted">
							<?php
							/* translators: %s: date and time */
							echo esc_html( sprintf( __( 'Επόμενος αυτόματος: %s', 'book-feed' ), self::when( BKF_Sync::next_daily_time() ) ) );
							?>
						</span>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public static function stats_text( array $st ): string {
		$st = array_merge(
			array(
				'checked'  => 0,
				'updated'  => 0,
				'gone'     => 0,
				'imported' => 0,
				'exists'   => 0,
				'errors'   => 0,
			),
			$st
		);
		/* translators: 1: checked, 2: updated, 3: gone, 4: imported, 5: already in the store, 6: errors */
		return sprintf( __( 'Έλεγχος %1$d, ενημερώσεις %2$d, εκτός καταλόγου %3$d, νέοι τίτλοι %4$d, υπάρχουν ήδη %5$d, σφάλματα %6$d.', 'book-feed' ), $st['checked'], $st['updated'], $st['gone'], $st['imported'], $st['exists'], $st['errors'] );
	}

	public static function when( int $t ): string {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $t );
	}

	private static function count_ours(): int {
		static $n = null;
		if ( null === $n ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one count.
			$n = (int) $wpdb->get_var(
				"SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_bkf_source_id' AND p.post_type = 'product' AND p.post_status <> 'trash'"
			);
		}
		return $n;
	}

	/** <select> of product categories. */
	public static function cat_select( string $name, int $selected, string $none ): string {
		return (string) wp_dropdown_categories(
			array(
				'taxonomy'          => 'product_cat',
				'hide_empty'        => 0,
				'hierarchical'      => 1,
				'name'              => $name,
				'id'                => sanitize_html_class( str_replace( array( '[', ']' ), '-', $name ) ),
				'selected'          => $selected,
				'show_option_none'  => $none,
				'option_none_value' => '0',
				'orderby'           => 'name',
				'echo'              => 0,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Tab: rules
	 * ------------------------------------------------------------------- */

	private static function render_rules(): void {
		$rules = BKF_Settings::rules();
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="bkf_action" value="save_rules" />
			<p class="description"><?php esc_html_e( 'Ένας κανόνας φέρνει αυτόματα τους νέους τίτλους ενός εκδότη, στον καθημερινό συγχρονισμό, πάντα ως πρόχειρα για να τους ελέγξεις πριν τη δημοσίευση. Οι τίτλοι που υπάρχουν ήδη στο κατάστημα (ίδιο ISBN) δεν διπλασιάζονται.', 'book-feed' ); ?></p>
			<?php
			foreach ( $rules as $i => $r ) {
				self::rule_card( $i, $r );
			}
			self::rule_card( count( $rules ), null );
			?>
			<p class="bkf-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'book-feed' ); ?></button></p>
		</form>
		<?php
	}

	private static function rule_card( int $i, ?array $r ): void {
		$new  = null === $r;
		$r    = $new ? BKF_Settings::clean_rule( array( 'pub_id' => 'x', 'on' => true ) ) : $r;
		$name = 'bkf_r[' . $i . ']';
		$seen = BKF_Settings::seen();
		if ( $new ) {
			$r['pub_id'] = '';
			$r['id']     = '';
		}
		?>
		<fieldset class="bkf-zone bkf-rule-card<?php echo $new ? ' bkf-rule-card--new' : ''; ?>">
			<legend><?php echo $new ? esc_html__( 'Νέος κανόνας', 'book-feed' ) : esc_html( BKF_Sync::rule_name( $r ) ); ?></legend>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $r['id'] ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[last]" value="<?php echo esc_attr( (string) $r['last'] ); ?>" />
			<label class="bkf-check"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[on]" value="1" <?php checked( $r['on'] ); ?> /> <?php esc_html_e( 'Ενεργός', 'book-feed' ); ?></label>
			<div class="bkf-grid">
				<p class="bkf-field"><span><?php esc_html_e( 'Εκδότης', 'book-feed' ); ?></span>
					<input type="text" class="bkf-pub-name" name="<?php echo esc_attr( $name ); ?>[pub_name]" value="<?php echo esc_attr( $r['pub_name'] ); ?>" autocomplete="off" placeholder="<?php esc_attr_e( 'Γράψε 3 γράμματα…', 'book-feed' ); ?>" />
				</p>
				<p class="bkf-field"><span><?php esc_html_e( 'Κωδικός εκδότη', 'book-feed' ); ?></span>
					<input type="text" class="bkf-pub-id" name="<?php echo esc_attr( $name ); ?>[pub_id]" value="<?php echo esc_attr( $r['pub_id'] ); ?>" pattern="[A-Za-z0-9_\-]+" />
				</p>
				<p class="bkf-field"><span><?php esc_html_e( 'Όνομα κανόνα (προαιρετικό)', 'book-feed' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $name ); ?>[name]" value="<?php echo esc_attr( $r['name'] ); ?>" />
				</p>
				<p class="bkf-field"><span><?php esc_html_e( 'Είδος', 'book-feed' ); ?></span>
					<select name="<?php echo esc_attr( $name ); ?>[kind]">
						<option value=""><?php esc_html_e( 'Όλα', 'book-feed' ); ?></option>
						<?php
						$kinds = $seen['kind'];
						if ( '' !== $r['kind'] && ! in_array( $r['kind'], $kinds, true ) ) {
							$kinds[] = $r['kind'];
						}
						foreach ( $kinds as $k ) {
							echo '<option value="' . esc_attr( $k ) . '" ' . selected( $r['kind'], $k, false ) . '>' . esc_html( $k ) . '</option>';
						}
						?>
					</select>
				</p>
				<p class="bkf-field"><span><?php esc_html_e( 'Μόνο από το έτος', 'book-feed' ); ?></span>
					<input type="number" min="0" max="2100" name="<?php echo esc_attr( $name ); ?>[year_from]" value="<?php echo esc_attr( $r['year_from'] ? (string) $r['year_from'] : '' ); ?>" />
				</p>
				<p class="bkf-field"><span><?php esc_html_e( 'Νέοι τίτλοι ανά εκτέλεση (έως)', 'book-feed' ); ?></span>
					<input type="number" min="1" max="500" name="<?php echo esc_attr( $name ); ?>[max]" value="<?php echo esc_attr( (string) $r['max'] ); ?>" />
				</p>
				<p class="bkf-field"><span><?php esc_html_e( 'Κατηγορία', 'book-feed' ); ?></span>
					<?php echo self::cat_select( $name . '[cat]', (int) $r['cat'], __( '— Από την αντιστοίχιση των Ρυθμίσεων —', 'book-feed' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup. ?>
				</p>
				<p class="bkf-field"><span><?php esc_html_e( 'Τρόπος', 'book-feed' ); ?></span>
					<select name="<?php echo esc_attr( $name ); ?>[mode]">
						<option value="sell" <?php selected( $r['mode'], 'sell' ); ?>><?php esc_html_e( 'Πώληση από το κατάστημα', 'book-feed' ); ?></option>
						<option value="external" <?php selected( $r['mode'], 'external' ); ?>><?php esc_html_e( 'Μόνο προβολή, με κουμπί προς άλλο site', 'book-feed' ); ?></option>
					</select>
				</p>
				<p class="bkf-field bkf-wide"><span><?php esc_html_e( 'Διεύθυνση του κουμπιού (για «Μόνο προβολή»)', 'book-feed' ); ?></span>
					<input type="url" name="<?php echo esc_attr( $name ); ?>[url]" value="<?php echo esc_attr( $r['url'] ); ?>" placeholder="https://example.com/search?isbn={isbn13}" />
					<span class="bkf-hint"><?php esc_html_e( 'Μπαίνουν τα {isbn}, {isbn13} (μόνο ψηφία) και {title}.', 'book-feed' ); ?></span>
				</p>
				<p class="bkf-field"><span><?php esc_html_e( 'Κείμενο κουμπιού', 'book-feed' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $name ); ?>[button]" value="<?php echo esc_attr( $r['button'] ); ?>" />
				</p>
			</div>
			<?php if ( ! $new ) : ?>
				<p class="bkf-row">
					<?php
					if ( $r['last'] ) {
						/* translators: %s: date */
						echo '<span class="bkf-badge">' . esc_html( sprintf( __( 'Τελευταία εκτέλεση: %s', 'book-feed' ), self::when( $r['last'] ) ) ) . '</span> ';
					} else {
						echo '<span class="bkf-badge bkf-badge--muted">' . esc_html__( 'Δεν έχει τρέξει ακόμα', 'book-feed' ) . '</span> ';
					}
					?>
					<a href="<?php echo esc_url( wp_nonce_url( self::url( 'search', array( 'bkf_pub' => $r['pub_id'], 'bkf_pubn' => $r['pub_name'], 'bkf_kind' => $r['kind'], 'bkf_y1' => $r['year_from'] ? $r['year_from'] : '' ) ), self::NONCE_FIND, 'bkf_f' ) ); ?>"><?php esc_html_e( 'Δες τους τίτλους του', 'book-feed' ); ?></a>
				</p>
				<p class="bkf-row"><label class="bkf-inline bkf-warn"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete]" value="1" /> <?php esc_html_e( 'Διαγραφή κανόνα', 'book-feed' ); ?></label></p>
			<?php endif; ?>
		</fieldset>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: log
	 * ------------------------------------------------------------------- */

	private static function render_log(): void {
		$act  = sanitize_key( self::q( 'bkf_act' ) );
		$all  = BKF_Log::all();
		$rows = '' === $act ? $all : array_values(
			array_filter(
				$all,
				static function ( $e ) use ( $act ) {
					return $e['act'] === $act;
				}
			)
		);
		?>
		<form method="get" class="bkf-inline-form bkf-mt-8">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="tab" value="log" />
			<select name="bkf_act">
				<option value=""><?php esc_html_e( 'Όλες οι ενέργειες', 'book-feed' ); ?></option>
				<?php
				foreach ( BKF_Log::ACTS as $a ) {
					echo '<option value="' . esc_attr( $a ) . '" ' . selected( $act, $a, false ) . '>' . esc_html( BKF_Log::act_label( $a ) ) . '</option>';
				}
				?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Φίλτρο', 'book-feed' ); ?></button>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( self::url( 'log', array( 'bkf_csv' => 'el', 'bkf_act' => $act ) ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'CSV (Excel ελληνικά)', 'book-feed' ); ?></a>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( self::url( 'log', array( 'bkf_csv' => 'std', 'bkf_act' => $act ) ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'CSV (τυπικό)', 'book-feed' ); ?></a>
		</form>
		<div class="bkf-scroll">
			<table class="widefat bkf-table">
				<thead><tr>
					<th><?php esc_html_e( 'Πότε', 'book-feed' ); ?></th>
					<th><?php esc_html_e( 'Ενέργεια', 'book-feed' ); ?></th>
					<th><?php esc_html_e( 'Τίτλος', 'book-feed' ); ?></th>
					<th>ISBN</th>
					<th><?php esc_html_e( 'Πεδία', 'book-feed' ); ?></th>
					<th><?php esc_html_e( 'Μήνυμα', 'book-feed' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6" class="bkf-empty"><?php esc_html_e( 'Το ιστορικό είναι άδειο.', 'book-feed' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( array_slice( $rows, 0, 500 ) as $e ) : ?>
					<tr>
						<td class="bkf-num"><?php echo esc_html( self::when( $e['t'] ) ); ?></td>
						<td><span class="bkf-badge<?php echo 'error' === $e['act'] ? ' bkf-badge--danger' : ( 'locked' === $e['act'] || 'gone' === $e['act'] ? ' bkf-badge--warn' : '' ); ?>"><?php echo esc_html( BKF_Log::act_label( $e['act'] ) ); ?></span></td>
						<td>
							<?php
							if ( $e['pid'] && get_post( $e['pid'] ) ) {
								echo '<a href="' . esc_url( (string) get_edit_post_link( $e['pid'] ) ) . '">' . esc_html( '' !== $e['title'] ? $e['title'] : '#' . $e['pid'] ) . '</a>';
							} else {
								echo esc_html( $e['title'] );
							}
							?>
						</td>
						<td><?php echo esc_html( $e['isbn'] ); ?></td>
						<td><?php echo esc_html( BKF_Log::fields_text( $e['fields'] ) ); ?></td>
						<td><?php echo esc_html( $e['msg'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ( $all ) : ?>
			<form method="post" class="bkf-mt-8">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="bkf_action" value="clear_log" />
				<button type="submit" class="button" data-bkf-confirm="1"><?php esc_html_e( 'Καθαρισμός ιστορικού', 'book-feed' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: settings
	 * ------------------------------------------------------------------- */

	/** <select> of attribute targets. */
	private static function target_select( string $name, string $value, bool $brand = false ): string {
		$opts = array(
			''      => __( 'Παράλειψη', 'book-feed' ),
			'local' => __( 'Χαρακτηριστικό μόνο του προϊόντος', 'book-feed' ),
		);
		if ( $brand && taxonomy_exists( 'product_brand' ) ) {
			$opts['brand'] = __( 'Brand (product_brand)', 'book-feed' );
		}
		foreach ( BKF_Settings::attributes() as $tax => $label ) {
			/* translators: %s: attribute name */
			$opts[ $tax ] = sprintf( __( 'Χαρακτηριστικό: %s', 'book-feed' ), $label );
		}
		$out = '<select name="' . esc_attr( $name ) . '">';
		foreach ( $opts as $k => $label ) {
			$out .= '<option value="' . esc_attr( $k ) . '" ' . selected( $value, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $out . '</select>';
	}

	private static function render_settings(): void {
		$s       = BKF_Settings::get();
		$seen    = BKF_Settings::seen();
		$src     = BKF_Settings::password_source();
		$classes = BKF_Settings::tax_classes();
		$tlabels = array(
			'publisher' => __( 'Εκδότης', 'book-feed' ),
			'year'      => __( 'Έτος έκδοσης', 'book-feed' ),
			'pages'     => __( 'Σελίδες', 'book-feed' ),
			'language'  => __( 'Γλώσσα', 'book-feed' ),
			'series'    => __( 'Σειρά', 'book-feed' ),
			'form'      => __( 'Μορφή (δέσιμο)', 'book-feed' ),
			'isbn'      => 'ISBN',
			'kind'      => __( 'Είδος (βιβλίο, ebook, audiobook)', 'book-feed' ),
			'genre'     => __( 'Κατηγορίες του καταλόγου (π.χ. ως «Είδος»)', 'book-feed' ),
			'subtitle'  => __( 'Υπότιτλος (όταν μπαίνει σε χαρακτηριστικό)', 'book-feed' ),
		);
		// Combined roles ("A, B") are split per role since 1.1.0.
		$roles = array_values( array_unique( array_filter( array_merge( $seen['role'], array_keys( $s['roles'] ) ), static function ( $r ) {
			return false === strpos( $r, ',' );
		} ) ) );
		$cats  = array_values( array_unique( array_merge( $seen['cat'], array_keys( $s['cats'] ) ) ) );
		$vats  = array_values( array_unique( array_merge( $seen['vat'], array_keys( $s['tax'] ) ) ) );
		$stat  = array_values( array_unique( array_merge( array( 'Κυκλοφορεί' ), $seen['status'], array_keys( $s['stock'] ) ) ) );
		sort( $roles );
		sort( $cats );
		sort( $vats );
		?>
		<form method="post">
			<?php self::nonce_field(); ?>
			<input type="hidden" name="bkf_action" value="save_settings" />

			<h2 class="bkf-h2"><?php esc_html_e( 'Σύνδεση', 'book-feed' ); ?></h2>
			<div class="bkf-zone">
				<p class="bkf-field"><span><?php esc_html_e( 'Διεύθυνση της υπηρεσίας', 'book-feed' ); ?></span>
					<input type="url" class="bkf-wide-input" name="bkf_s[url]" value="<?php echo esc_attr( $s['url'] ); ?>" />
				</p>
				<p class="bkf-field"><span><?php esc_html_e( 'Όνομα χρήστη (από τον ΟΣΔΕΛ)', 'book-feed' ); ?></span>
					<input type="text" name="bkf_s[user]" value="<?php echo esc_attr( $s['user'] ); ?>" autocomplete="off" />
				</p>
				<p class="bkf-field"><span><?php esc_html_e( 'Κωδικός', 'book-feed' ); ?></span>
					<?php if ( 'const' === $src ) : ?>
						<span class="bkf-badge bkf-badge--muted"><?php esc_html_e( 'Από τη σταθερά BKF_PASSWORD στο wp-config.php', 'book-feed' ); ?></span>
					<?php else : ?>
						<input type="password" name="bkf_pass" value="" autocomplete="new-password" placeholder="<?php echo 'option' === $src ? esc_attr__( 'Κενό = μένει ο τωρινός', 'book-feed' ) : ''; ?>" />
						<?php if ( 'option' === $src ) : ?>
							<label class="bkf-inline"><input type="checkbox" name="bkf_pass_clear" value="1" /> <?php esc_html_e( 'Διαγραφή κωδικού', 'book-feed' ); ?></label>
						<?php elseif ( 'unreadable' === $src ) : ?>
							<span class="bkf-badge bkf-badge--danger"><?php esc_html_e( 'Ο αποθηκευμένος κωδικός δεν διαβάζεται πια· γράψ’ τον ξανά', 'book-feed' ); ?></span>
						<?php endif; ?>
					<?php endif; ?>
				</p>
				<p class="bkf-hint"><?php esc_html_e( 'Ο κωδικός φυλάγεται κρυπτογραφημένος με τα κλειδιά του wp-config.php και δεν εμφανίζεται ποτέ. Εναλλακτικά: define( \'BKF_PASSWORD\', \'…\' ); στο wp-config.php.', 'book-feed' ); ?></p>
				<label class="bkf-check"><input type="checkbox" name="bkf_s[https]" value="1" <?php checked( $s['https'] ); ?> /> <?php esc_html_e( 'Δοκιμή πρώτα με https', 'book-feed' ); ?></label>
				<?php if ( 'http' === BKF_Client::scheme() ) : ?>
					<p class="bkf-warn"><?php esc_html_e( 'Η σύνδεση γίνεται με http: ο κωδικός ταξιδεύει χωρίς κρυπτογράφηση σε κάθε αίτημα. Γράψε τη διεύθυνση με https:// αν η υπηρεσία το δέχεται.', 'book-feed' ); ?></p>
				<?php endif; ?>
				<p><button type="submit" name="bkf_test" value="1" class="button"><?php esc_html_e( 'Αποθήκευση και δοκιμή σύνδεσης', 'book-feed' ); ?></button></p>
			</div>

			<h2 class="bkf-h2"><?php esc_html_e( 'Εισαγωγή', 'book-feed' ); ?></h2>
			<div class="bkf-zone">
				<div class="bkf-grid">
					<p class="bkf-field"><span><?php esc_html_e( 'Κατάσταση νέων προϊόντων (εισαγωγή με το χέρι)', 'book-feed' ); ?></span>
						<select name="bkf_s[status]">
							<option value="draft" <?php selected( $s['status'], 'draft' ); ?>><?php esc_html_e( 'Πρόχειρο', 'book-feed' ); ?></option>
							<option value="publish" <?php selected( $s['status'], 'publish' ); ?>><?php esc_html_e( 'Δημοσιευμένο', 'book-feed' ); ?></option>
						</select>
					</p>
					<p class="bkf-field"><span><?php esc_html_e( 'Υπότιτλος', 'book-feed' ); ?></span>
						<select name="bkf_s[subtitle]">
							<option value="title" <?php selected( $s['subtitle'], 'title' ); ?>><?php esc_html_e( 'Στον τίτλο, μετά από «:»', 'book-feed' ); ?></option>
							<option value="attr" <?php selected( $s['subtitle'], 'attr' ); ?>><?php esc_html_e( 'Σε χαρακτηριστικό', 'book-feed' ); ?></option>
							<option value="none" <?php selected( $s['subtitle'], 'none' ); ?>><?php esc_html_e( 'Πουθενά', 'book-feed' ); ?></option>
						</select>
					</p>
					<p class="bkf-field"><span><?php esc_html_e( 'ISBN στο προϊόν', 'book-feed' ); ?></span>
						<select name="bkf_s[isbn]">
							<option value="as_is" <?php selected( $s['isbn'], 'as_is' ); ?>><?php esc_html_e( 'Όπως το δίνει ο κατάλογος', 'book-feed' ); ?></option>
							<option value="digits" <?php selected( $s['isbn'], 'digits' ); ?>><?php esc_html_e( 'Μόνο ψηφία', 'book-feed' ); ?></option>
						</select>
					</p>
					<p class="bkf-field"><span><?php esc_html_e( 'Κατηγορία για τίτλους χωρίς αντιστοίχιση', 'book-feed' ); ?></span>
						<?php echo self::cat_select( 'bkf_s[default_cat]', (int) $s['default_cat'], __( '— Η προεπιλογή του WooCommerce —', 'book-feed' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup. ?>
					</p>
				</div>
				<label class="bkf-check"><input type="checkbox" name="bkf_s[sku]" value="1" <?php checked( $s['sku'] ); ?> /> <?php esc_html_e( 'Το ISBN και ως κωδικός προϊόντος (SKU)', 'book-feed' ); ?></label>
				<label class="bkf-check"><input type="checkbox" name="bkf_s[descriptions]" value="1" <?php checked( $s['descriptions'] ); ?> /> <?php esc_html_e( 'Περιγραφή και σύντομη περιγραφή από τον κατάλογο', 'book-feed' ); ?></label>
				<label class="bkf-check"><input type="checkbox" name="bkf_s[images]" value="1" <?php checked( $s['images'] ); ?> /> <?php esc_html_e( 'Κατέβασμα του εξωφύλλου στη βιβλιοθήκη πολυμέσων', 'book-feed' ); ?></label>
				<label class="bkf-check"><input type="checkbox" name="bkf_s[back_cover]" value="1" <?php checked( $s['back_cover'] ); ?> /> <?php esc_html_e( 'Και το οπισθόφυλλο, στη συλλογή εικόνων', 'book-feed' ); ?></label>
				<label class="bkf-check"><input type="checkbox" name="bkf_s[pub_cover]" value="1" <?php checked( $s['pub_cover'] ); ?> /> <?php esc_html_e( 'Το εξώφυλλο από τη σελίδα του εκδότη, όταν είναι μεγαλύτερο από του καταλόγου', 'book-feed' ); ?></label>
				<div class="bkf-grid bkf-mt-8">
					<p class="bkf-field"><span><?php esc_html_e( 'Όνομα αρχείου του εξωφύλλου', 'book-feed' ); ?></span>
						<input type="text" class="bkf-wide-input" name="bkf_s[img_name]" value="<?php echo esc_attr( $s['img_name'] ); ?>" />
					</p>
					<p class="bkf-field"><span><?php esc_html_e( 'Εναλλακτικό κείμενο (alt) του εξωφύλλου', 'book-feed' ); ?></span>
						<input type="text" class="bkf-wide-input" name="bkf_s[img_alt]" value="<?php echo esc_attr( $s['img_alt'] ); ?>" />
					</p>
				</div>
				<p class="bkf-hint"><?php echo esc_html( sprintf( /* translators: %s: placeholders */ __( 'Μπορείς να γράψεις: %s. Το όνομα αρχείου γίνεται greeklish με παύλες.', 'book-feed' ), implode( ' ', BKF_Settings::IMG_TAGS ) ) ); ?></p>
				<p class="bkf-hint"><?php esc_html_e( 'Πριν δημοσιεύσεις περιγραφές και εξώφυλλα, βεβαιώσου ότι το επιτρέπει η σύμβασή σου με τον ΟΣΔΕΛ.', 'book-feed' ); ?></p>
			</div>

			<h2 class="bkf-h2"><?php esc_html_e( 'Αντιστοίχιση πεδίων', 'book-feed' ); ?></h2>
			<div class="bkf-zone">
				<table class="widefat bkf-table bkf-map">
					<tbody>
					<?php foreach ( BKF_Settings::TARGET_KEYS as $k ) : ?>
						<tr><td><?php echo esc_html( $tlabels[ $k ] ); ?></td><td><?php echo self::target_select( 'bkf_s[targets][' . $k . ']', $s['targets'][ $k ], 'publisher' === $k ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in target_select. ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="bkf-hint"><?php esc_html_e( 'Ένα χαρακτηριστικό του καταστήματος (pa_) φιλτράρεται στο Shop Filters και στα widgets. Τα «χαρακτηριστικά μόνο του προϊόντος» φαίνονται στην καρτέλα «Επιπλέον πληροφορίες».', 'book-feed' ); ?></p>
				<p class="bkf-field bkf-mt-8"><span><?php esc_html_e( 'Σειρά των χαρακτηριστικών στο προϊόν', 'book-feed' ); ?></span>
					<textarea name="bkf_s[order_text]" rows="6" class="bkf-wide-input"><?php echo esc_textarea( implode( "\n", $s['order'] ) ); ?></textarea>
				</p>
				<p class="bkf-hint"><?php esc_html_e( 'Ένα όνομα ανά γραμμή, όπως φαίνεται στο προϊόν (π.χ. ISBN, Συγγραφείς, Έτος έκδοσης). Όσα δεν γράψεις μπαίνουν μετά. Κενό: ISBN, συντελεστές, κατηγορίες, είδος, έτος, σελίδες, μορφή, γλώσσα, σειρά, εκδότης.', 'book-feed' ); ?></p>
			</div>

			<h2 class="bkf-h2"><?php esc_html_e( 'Μετονομασία τιμών', 'book-feed' ); ?></h2>
			<div class="bkf-zone">
				<textarea name="bkf_s[rename_text]" rows="6" class="bkf-wide-input"><?php echo esc_textarea( BKF_Settings::rename_text( $s['rename'] ) ); ?></textarea>
				<p class="bkf-hint"><?php esc_html_e( 'Μία ανά γραμμή: «τιμή του καταλόγου = τιμή του καταστήματος», π.χ. «Μαλακό εξώφυλλο = Χαρτόδετο». Με κενό δεξιά, η τιμή παραλείπεται. Ισχύει για εκδότη, μορφή, είδος, κατηγορίες ως χαρακτηριστικό, γλώσσα, σειρά και ετικέτες.', 'book-feed' ); ?></p>
				<?php
				$vals = array_values( array_unique( array_filter( array_merge( $seen['form'], $seen['kind'] ) ) ) );
				if ( $vals ) :
					?>
					<p class="bkf-hint"><?php echo esc_html( sprintf( /* translators: %s: values */ __( 'Μορφές και είδη που έχουν φανεί: %s', 'book-feed' ), implode( ', ', $vals ) ) ); ?></p>
				<?php endif; ?>
				<?php if ( $seen['class'] ) : ?>
					<p class="bkf-hint"><?php echo esc_html( sprintf( /* translators: %s: values */ __( 'Θέματα και λέξεις-κλειδιά που έχουν φανεί: %s', 'book-feed' ), implode( ', ', array_slice( $seen['class'], 0, 60 ) ) ) ); ?></p>
				<?php endif; ?>
			</div>

			<h2 class="bkf-h2"><?php esc_html_e( 'Ρόλοι συντελεστών', 'book-feed' ); ?></h2>
			<div class="bkf-zone">
				<?php if ( ! $roles ) : ?>
					<p class="bkf-muted"><?php esc_html_e( 'Οι ρόλοι (π.χ. συγγραφέας, μεταφραστής) εμφανίζονται εδώ μόλις τους δει το plugin σε μια αναζήτηση. Μέχρι τότε κάθε ρόλος γίνεται χαρακτηριστικό του προϊόντος με το όνομά του.', 'book-feed' ); ?></p>
				<?php else : ?>
					<table class="widefat bkf-table bkf-map"><tbody>
					<?php foreach ( $roles as $i => $role ) : ?>
						<tr><td><?php echo esc_html( '' === $role ? __( '(χωρίς ρόλο)', 'book-feed' ) : $role ); ?><input type="hidden" name="bkf_s[role_names][<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $role ); ?>" /></td>
						<td><?php echo self::target_select( 'bkf_s[role_targets][' . $i . ']', BKF_Settings::role_target( $role ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in target_select. ?></td></tr>
					<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>
				<p class="bkf-field bkf-mt-8"><span><?php esc_html_e( 'Ονόματα', 'book-feed' ); ?></span>
					<select name="bkf_s[names]">
						<option value="as_is" <?php selected( $s['names'], 'as_is' ); ?>><?php esc_html_e( 'Όπως τα δίνει ο κατάλογος (Επώνυμο, Όνομα)', 'book-feed' ); ?></option>
						<option value="first_last" <?php selected( $s['names'], 'first_last' ); ?>><?php esc_html_e( 'Όνομα Επώνυμο', 'book-feed' ); ?></option>
					</select>
				</p>
			</div>

			<h2 class="bkf-h2"><?php esc_html_e( 'Κατηγορίες', 'book-feed' ); ?></h2>
			<div class="bkf-zone">
				<?php if ( ! $cats ) : ?>
					<p class="bkf-muted"><?php esc_html_e( 'Οι κατηγορίες του καταλόγου εμφανίζονται εδώ μόλις τις δει το plugin σε μια αναζήτηση.', 'book-feed' ); ?></p>
				<?php else : ?>
					<table class="widefat bkf-table bkf-map"><tbody>
					<?php foreach ( $cats as $i => $cat ) : ?>
						<tr><td><?php echo esc_html( $cat ); ?><input type="hidden" name="bkf_s[cat_names][<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $cat ); ?>" /></td>
						<td><?php echo self::cat_select( 'bkf_s[cat_terms][' . $i . ']', isset( $s['cats'][ $cat ] ) ? (int) $s['cats'][ $cat ] : 0, __( '— Χωρίς αντιστοίχιση —', 'book-feed' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup. ?></td></tr>
					<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>
				<label class="bkf-check bkf-mt-8"><input type="checkbox" name="bkf_s[cat_parents]" value="1" <?php checked( $s['cat_parents'] ); ?> /> <?php esc_html_e( 'Και οι γονικές κατηγορίες της κατηγορίας που αντιστοιχίστηκε', 'book-feed' ); ?></label>
				<p class="bkf-hint"><?php esc_html_e( 'Η κατηγορία μπαίνει μόνο στην εισαγωγή. Ένας κανόνας με δική του κατηγορία κερδίζει την αντιστοίχιση. Για χρόνο αποστολής ειδικά για αυτούς τους τίτλους, όρισε ημέρες προετοιμασίας στην κατηγορία τους από το Shipping Notes.', 'book-feed' ); ?></p>
			</div>

			<h2 class="bkf-h2"><?php esc_html_e( 'Ετικέτες', 'book-feed' ); ?></h2>
			<div class="bkf-zone">
				<div class="bkf-grid">
					<p class="bkf-field"><span><?php esc_html_e( 'Ετικέτες προϊόντος από', 'book-feed' ); ?></span>
						<select name="bkf_s[tags]">
							<option value="" <?php selected( $s['tags'], '' ); ?>><?php esc_html_e( 'Καμία ετικέτα', 'book-feed' ); ?></option>
							<option value="class" <?php selected( $s['tags'], 'class' ); ?>><?php esc_html_e( 'Θέματα και λέξεις-κλειδιά του καταλόγου', 'book-feed' ); ?></option>
							<option value="cats" <?php selected( $s['tags'], 'cats' ); ?>><?php esc_html_e( 'Κατηγορίες του καταλόγου', 'book-feed' ); ?></option>
						</select>
					</p>
					<p class="bkf-field"><span><?php esc_html_e( 'Το πολύ', 'book-feed' ); ?></span>
						<input type="number" min="1" max="50" name="bkf_s[tags_max]" value="<?php echo esc_attr( (string) $s['tags_max'] ); ?>" />
					</p>
				</div>
				<p class="bkf-hint"><?php esc_html_e( 'Οι ετικέτες μπαίνουν μόνο στην εισαγωγή· μετά είναι δικές σου.', 'book-feed' ); ?></p>
			</div>

			<h2 class="bkf-h2"><?php esc_html_e( 'ΦΠΑ', 'book-feed' ); ?></h2>
			<div class="bkf-zone">
				<?php if ( ! $vats ) : ?>
					<p class="bkf-muted"><?php esc_html_e( 'Οι συντελεστές ΦΠΑ του καταλόγου εμφανίζονται εδώ μόλις τους δει το plugin. Μέχρι τότε η κλάση φόρου βγαίνει αυτόματα από τον συντελεστή.', 'book-feed' ); ?></p>
				<?php else : ?>
					<table class="widefat bkf-table bkf-map"><tbody>
					<?php foreach ( $vats as $i => $rate ) : ?>
						<?php $auto = BKF_Settings::auto_tax_class( (float) $rate ); ?>
						<tr><td><?php echo esc_html( $rate . '%' ); ?><input type="hidden" name="bkf_s[vat_rates][<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $rate ); ?>" /></td>
						<td><select name="bkf_s[vat_classes][<?php echo (int) $i; ?>]">
							<option value="__auto"><?php echo esc_html( null === $auto ? __( 'Αυτόματα (καμία κλάση με αυτόν τον συντελεστή· μένει όπως είναι)', 'book-feed' ) : sprintf( /* translators: %s: tax class */ __( 'Αυτόματα (%s)', 'book-feed' ), $classes[ $auto ] ?? $auto ) ); ?></option>
							<?php foreach ( $classes as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( '' === $slug ? '__std' : $slug ); ?>" <?php selected( isset( $s['tax'][ $rate ] ) ? ( '' === $s['tax'][ $rate ] ? '__std' : $s['tax'][ $rate ] ) : '', '' === $slug ? '__std' : $slug ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select></td></tr>
					<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>
				<p class="bkf-hint">
					<?php
					if ( wc_tax_enabled() && ! wc_prices_include_tax() ) {
						esc_html_e( 'Το κατάστημα δίνει τιμές χωρίς ΦΠΑ: η τιμή του καταλόγου (με ΦΠΑ) μετατρέπεται με τον συντελεστή του τίτλου.', 'book-feed' );
					} else {
						esc_html_e( 'Η τιμή του καταλόγου περιλαμβάνει ΦΠΑ και μπαίνει όπως είναι.', 'book-feed' );
					}
					?>
				</p>
			</div>

			<h2 class="bkf-h2"><?php esc_html_e( 'Διαθεσιμότητα', 'book-feed' ); ?></h2>
			<div class="bkf-zone">
				<table class="widefat bkf-table bkf-map"><tbody>
				<?php foreach ( $stat as $i => $st ) : ?>
					<tr><td><?php echo esc_html( $st ); ?><input type="hidden" name="bkf_s[stock_names][<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $st ); ?>" /></td>
					<td><select name="bkf_s[stock_to][<?php echo (int) $i; ?>]">
						<?php
						$cur = BKF_Settings::stock_for( $st );
						foreach ( wc_get_product_stock_status_options() as $k => $label ) {
							echo '<option value="' . esc_attr( $k ) . '" ' . selected( $cur, $k, false ) . '>' . esc_html( $label ) . '</option>';
						}
						?>
					</select></td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<p class="bkf-hint"><?php esc_html_e( 'Μια τιμή διαθεσιμότητας που δεν έχει αντιστοιχιστεί κάνει τον τίτλο «Εξαντλημένο», ώστε να μην πουλάς κάτι που ίσως δεν υπάρχει. Οι νέες τιμές εμφανίζονται εδώ μόλις τις δει το plugin.', 'book-feed' ); ?></p>
			</div>

			<h2 class="bkf-h2"><?php esc_html_e( 'Τιμές από τα sites των εκδοτών', 'book-feed' ); ?></h2>
			<div class="bkf-zone">
				<p class="bkf-hint"><?php esc_html_e( 'Για κάθε εκδότη εδώ, το plugin διαβάζει την τιμή από τη σελίδα του βιβλίου στο site του (δομημένα δεδομένα schema.org, όπως τα δίνει κάθε WooCommerce) και τη βάζει αντί για την τιμή του καταλόγου, στην εισαγωγή και σε κάθε συγχρονισμό. Αν η σελίδα δεν διαβάζεται ή η τιμή απέχει πάνω από το διπλάσιο ή κάτω από το μισό της τιμής του καταλόγου, μένει η τιμή του καταλόγου. Κάθε αλλαγή γράφεται στο ιστορικό.', 'book-feed' ); ?></p>
				<?php
				$pp   = array_values(
					array_map(
						static function ( $id, $row ) {
							return array( 'pub_id' => $id ) + $row;
						},
						array_keys( $s['pub_prices'] ),
						$s['pub_prices']
					)
				);
				$pp[] = array(
					'pub_id' => '',
					'name'   => '',
					'url'    => '',
				);
				foreach ( $pp as $i => $row ) :
					?>
					<fieldset class="bkf-pp-row bkf-grid">
						<p class="bkf-field bkf-pub-wrap"><span><?php esc_html_e( 'Εκδότης', 'book-feed' ); ?></span>
							<input type="text" class="bkf-pub-name" name="bkf_s[pp][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $row['name'] ); ?>" autocomplete="off" placeholder="<?php esc_attr_e( 'Γράψε 3 γράμματα…', 'book-feed' ); ?>" />
							<input type="hidden" class="bkf-pub-id" name="bkf_s[pp][<?php echo (int) $i; ?>][pub_id]" value="<?php echo esc_attr( $row['pub_id'] ); ?>" />
						</p>
						<p class="bkf-field"><span><?php esc_html_e( 'Σελίδα του βιβλίου στο site του εκδότη', 'book-feed' ); ?></span>
							<input type="url" class="bkf-wide-input" name="bkf_s[pp][<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr( $row['url'] ); ?>" placeholder="https://…/wp-json/wc/store/v1/products?search={isbn13}" />
						</p>
					</fieldset>
				<?php endforeach; ?>
				<p class="bkf-hint"><?php esc_html_e( 'Γράψε {isbn} (με παύλες, όπως το δίνει ο κατάλογος) ή {isbn13} (μόνο ψηφία) εκεί που μπαίνει το ISBN. Σε site WooCommerce: https://site/wp-json/wc/store/v1/products?search={isbn13} ή η σελίδα του προϊόντος. Άδεια διεύθυνση αφαιρεί τον εκδότη. Δοκίμασε ένα ISBN από τα Εργαλεία.', 'book-feed' ); ?></p>
				<p class="bkf-hint"><?php esc_html_e( 'Αν οι διευθύνσεις του εκδότη δεν έχουν το ISBN, βάλε τη σελίδα του βιβλίου στο κουτί Book Feed κάθε προϊόντος.', 'book-feed' ); ?></p>
				<p class="bkf-field"><span><?php esc_html_e( 'Λέξεις πριν από την τιμή του εκδότη (μία ανά γραμμή)', 'book-feed' ); ?></span>
					<textarea name="bkf_s[labels_text]" rows="3" class="bkf-wide-input"><?php echo esc_textarea( implode( "\n", $s['pub_labels'] ) ); ?></textarea>
				</p>
				<p class="bkf-hint"><?php esc_html_e( 'Για σελίδες χωρίς δομημένα δεδομένα: ο πρώτος αριθμός μετά από αυτές τις λέξεις είναι η τιμή, π.χ. «Τιμή εκδότη 24,40 €». Έχει προτεραιότητα, γιατί η τιμή του e-shop του εκδότη είναι συχνά με έκπτωση.', 'book-feed' ); ?></p>
			</div>

			<h2 class="bkf-h2"><?php esc_html_e( 'Συγχρονισμός', 'book-feed' ); ?></h2>
			<div class="bkf-zone">
				<label class="bkf-check"><input type="checkbox" name="bkf_s[sync_on]" value="1" <?php checked( $s['sync_on'] ); ?> /> <?php esc_html_e( 'Καθημερινός συγχρονισμός', 'book-feed' ); ?></label>
				<p class="bkf-field"><span><?php esc_html_e( 'Ώρα', 'book-feed' ); ?></span>
					<select name="bkf_s[hour]">
						<?php for ( $h = 0; $h < 24; $h++ ) : ?>
							<option value="<?php echo (int) $h; ?>" <?php selected( $s['hour'], $h ); ?>><?php echo esc_html( sprintf( '%02d:00', $h ) ); ?></option>
						<?php endfor; ?>
					</select>
				</p>
				<p class="bkf-label"><?php esc_html_e( 'Η τιμή και η διαθεσιμότητα ακολουθούν πάντα τον κατάλογο. Αυτά ακολουθούν κι αυτά, όσο δεν τα έχεις αλλάξει με το χέρι:', 'book-feed' ); ?></p>
				<?php foreach ( BKF_Settings::SYNC_FIELDS as $f ) : ?>
					<label class="bkf-check"><input type="checkbox" name="bkf_s[fields][]" value="<?php echo esc_attr( $f ); ?>" <?php checked( in_array( $f, $s['fields'], true ) ); ?> /> <?php echo esc_html( BKF_Log::field_label( $f ) ); ?></label>
				<?php endforeach; ?>
				<p class="bkf-field bkf-mt-8"><span><?php esc_html_e( 'Τίτλος που φεύγει από τον κατάλογο', 'book-feed' ); ?></span>
					<select name="bkf_s[gone]">
						<option value="outofstock" <?php selected( $s['gone'], 'outofstock' ); ?>><?php esc_html_e( 'Εξαντλημένο (μένει ορατό)', 'book-feed' ); ?></option>
						<option value="draft" <?php selected( $s['gone'], 'draft' ); ?>><?php esc_html_e( 'Πρόχειρο (φεύγει από το site)', 'book-feed' ); ?></option>
					</select>
				</p>
				<p class="bkf-field"><span><?php esc_html_e( 'Παύση ανάμεσα στις σελίδες του καταλόγου (ms)', 'book-feed' ); ?></span>
					<input type="number" min="0" max="5000" step="100" name="bkf_s[pause]" value="<?php echo esc_attr( (string) $s['pause'] ); ?>" />
				</p>
			</div>

			<h2 class="bkf-h2"><?php esc_html_e( 'Ενιαία τιμή βιβλίου', 'book-feed' ); ?></h2>
			<div class="bkf-zone">
				<p class="bkf-field"><span><?php esc_html_e( 'Μέγιστη έκπτωση όσο ισχύει η ενιαία τιμή (%)', 'book-feed' ); ?></span>
					<input type="number" min="0" max="100" name="bkf_s[guard_pct]" value="<?php echo esc_attr( (string) $s['guard_pct'] ); ?>" />
				</p>
				<p class="bkf-hint"><?php esc_html_e( 'Μόνο προειδοποίηση όταν αποθηκεύεις τιμή προσφοράς με μεγαλύτερη έκπτωση, για τίτλους που ο κατάλογος δίνει ημερομηνία λήξης ενιαίας τιμής. Όταν ο τύπος τιμής του καταλόγου γράφει ποσοστό, ισχύει εκείνο. Το ποσοστό και οι εξαιρέσεις του νόμου είναι δική σου ευθύνη.', 'book-feed' ); ?></p>
			</div>

			<h2 class="bkf-h2"><?php esc_html_e( 'Διαγραφή του plugin', 'book-feed' ); ?></h2>
			<div class="bkf-zone">
				<label class="bkf-check bkf-warn"><input type="checkbox" name="bkf_s[delete_products]" value="1" <?php checked( $s['delete_products'] ); ?> /> <?php esc_html_e( 'Διαγραφή και των προϊόντων που εισήχθησαν, όταν διαγραφεί το plugin', 'book-feed' ); ?></label>
				<p class="bkf-hint"><?php esc_html_e( 'Χωρίς αυτό, τα προϊόντα, οι εικόνες και οι κατηγορίες μένουν· φεύγουν μόνο οι ρυθμίσεις, το ιστορικό και τα σημάδια του plugin στα προϊόντα. Οι εικόνες μένουν πάντα.', 'book-feed' ); ?></p>
			</div>

			<p class="bkf-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'book-feed' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: tools
	 * ------------------------------------------------------------------- */

	private static function render_tools(): void {
		?>
		<h2 class="bkf-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'book-feed' ); ?></h2>
		<div class="bkf-zone">
			<form method="post" enctype="multipart/form-data" class="bkf-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'bkf_backup_nonce' ); ?>
				<input type="file" name="bkf_import_file" accept=".json,application/json" />
				<button type="submit" name="bkf_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'book-feed' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( self::url( 'tools', array( 'bkf_export' => 1 ) ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'book-feed' ); ?></a>
			<p class="description bkf-mt-8"><?php esc_html_e( 'Ρυθμίσεις και κανόνες, χωρίς τον κωδικό. Κατηγορίες και χαρακτηριστικά δείχνονται με το id ή το slug τους: σε άλλο site κρατιούνται μόνο όσα υπάρχουν εκεί.', 'book-feed' ); ?></p>
		</div>

		<h2 class="bkf-h2"><?php esc_html_e( 'Δοκιμή τιμής εκδότη', 'book-feed' ); ?></h2>
		<div class="bkf-zone">
			<form method="post" class="bkf-inline-form">
				<?php self::nonce_field(); ?>
				<input type="hidden" name="bkf_action" value="pp_test" />
				<input type="text" name="bkf_pp_isbn" placeholder="978-…" pattern="[0-9Xx\- ]{10,20}" required />
				<input type="url" name="bkf_pp_url" class="bkf-wide-input" maxlength="300" placeholder="<?php esc_attr_e( 'Σελίδα του βιβλίου (προαιρετικά)', 'book-feed' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Διάβασε την τιμή', 'book-feed' ); ?></button>
			</form>
			<p class="description bkf-mt-8"><?php esc_html_e( 'Βρίσκει τον τίτλο στον κατάλογο, ανοίγει τη σελίδα που γράφεις, αλλιώς τη σελίδα του προϊόντος ή του εκδότη από τις Ρυθμίσεις, και δείχνει τι τιμή διαβάζει. Δεν αλλάζει κανένα προϊόν.', 'book-feed' ); ?></p>
		</div>

		<h2 class="bkf-h2"><?php esc_html_e( 'Για προγραμματιστές', 'book-feed' ); ?></h2>
		<div class="bkf-zone">
			<table class="widefat bkf-table">
				<tbody>
					<tr><td><code>bkf_get_source( $product_id )</code></td><td><?php esc_html_e( 'Από πού ήρθε ένα προϊόν: source, id, isbn, synced, linked· null για προϊόντα που δεν ήρθαν από τον κατάλογο.', 'book-feed' ); ?></td></tr>
					<tr><td><code>noxpress_products_changed</code></td><td><?php esc_html_e( 'Πυροδοτείται με τα ids των προϊόντων που άλλαξαν σε κάθε εισαγωγή ή βήμα συγχρονισμού.', 'book-feed' ); ?></td></tr>
					<tr><td><code>wp book-feed status | search | sync</code></td><td><?php esc_html_e( 'Εντολές WP-CLI: κατάσταση, αναζήτηση στον κατάλογο (μόνο ανάγνωση), συγχρονισμός με --yes.', 'book-feed' ); ?></td></tr>
					<tr><td><code>BKF_PASSWORD</code></td><td><?php esc_html_e( 'Σταθερά στο wp-config.php με τον κωδικό της υπηρεσίας, αντί για τις ρυθμίσεις.', 'book-feed' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* =====================================================================
	 * POST routes (admin_init, PRG)
	 * =================================================================== */

	public static function nonce_field(): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
	}

	public static function route(): void {
		if ( self::SLUG !== self::current_page() || 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
			return;
		}

		if ( isset( $_POST['bkf_import'] ) ) {
			if ( ! isset( $_POST['bkf_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['bkf_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'book-feed' ) );
			}
			self::redirect( 'tools', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['bkf_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'book-feed' ) );
		}

		$back = sanitize_key( self::q( 'tab' ) );
		$back = isset( self::tabs()[ $back ] ) ? $back : 'search';

		switch ( sanitize_key( wp_unslash( $_POST['bkf_action'] ) ) ) {
			case 'save_settings':
				self::redirect( 'settings', self::do_save_settings() );
				break;
			case 'save_rules':
				self::redirect( 'rules', self::do_save_rules() );
				break;
			case 'sync':
				if ( ! BKF_Settings::ready() ) {
					self::redirect( $back, array( self::msg( 'error', __( 'Λείπουν το όνομα χρήστη ή ο κωδικός της υπηρεσίας (Ρυθμίσεις).', 'book-feed' ) ) ) );
				}
				$ok = BKF_Sync::start( 'manual' );
				self::redirect( $back, array( $ok ? self::msg( 'success', __( 'Ο συγχρονισμός ξεκίνησε στο παρασκήνιο. Η σελίδα δείχνει την πρόοδο.', 'book-feed' ) ) : self::msg( 'warning', __( 'Τρέχει ήδη συγχρονισμός.', 'book-feed' ) ) ) );
				break;
			case 'cancel':
				BKF_Sync::cancel();
				self::redirect( $back, array( self::msg( 'success', __( 'Ο συγχρονισμός σταμάτησε.', 'book-feed' ) ) ) );
				break;
			case 'pp_test':
				self::redirect( 'tools', array( self::pp_test() ) );
				break;
			case 'clear_log':
				BKF_Log::clear();
				self::redirect( 'log', array( self::msg( 'success', __( 'Το ιστορικό καθαρίστηκε.', 'book-feed' ) ) ) );
				break;
			case 'import':
				// Same form: a "Σύνδεση" button sends bkf_link.
				BKF_Admin_Ajax::back( isset( $_POST['bkf_link'] ) ? BKF_Admin_Ajax::do_link() : BKF_Admin_Ajax::do_import_post() );
				break;
			default:
				self::redirect( $back, array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'book-feed' ) ) ) );
		}
	}

	public static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		set_transient( 'bkf_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	private static function post_array( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); validated per field.
		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
	}

	/** Pairs two parallel arrays from the form (names[i] => values[i]). */
	private static function pairs( array $in, string $names, string $values ): array {
		$out = array();
		$n   = isset( $in[ $names ] ) && is_array( $in[ $names ] ) ? $in[ $names ] : array();
		$v   = isset( $in[ $values ] ) && is_array( $in[ $values ] ) ? $in[ $values ] : array();
		foreach ( $n as $i => $name ) {
			if ( is_scalar( $name ) && isset( $v[ $i ] ) && is_scalar( $v[ $i ] ) ) {
				$out[ (string) $name ] = (string) $v[ $i ];
			}
		}
		return $out;
	}

	private static function do_save_settings(): array {
		$bad_pp = array();
		$in     = self::post_array( 'bkf_s' );
		$cur = BKF_Settings::get();
		$new = $in;

		$new['roles'] = self::pairs( $in, 'role_names', 'role_targets' );

		$new['cats'] = array();
		foreach ( self::pairs( $in, 'cat_names', 'cat_terms' ) as $cat => $term ) {
			if ( (int) $term > 0 ) {
				$new['cats'][ $cat ] = (int) $term;
			}
		}
		$new['tax'] = array();
		foreach ( self::pairs( $in, 'vat_rates', 'vat_classes' ) as $rate => $class ) {
			if ( '__auto' !== $class ) {
				$new['tax'][ $rate ] = '__std' === $class ? '' : $class;
			}
		}
		$new['stock']  = self::pairs( $in, 'stock_names', 'stock_to' );
		$new['fields'] = isset( $in['fields'] ) && is_array( $in['fields'] ) ? $in['fields'] : array();
		foreach ( array( 'https', 'sku', 'descriptions', 'images', 'back_cover', 'pub_cover', 'cat_parents', 'sync_on', 'delete_products' ) as $b ) {
			$new[ $b ] = ! empty( $in[ $b ] );
		}
		$new['pub_prices'] = array();
		foreach ( isset( $in['pp'] ) && is_array( $in['pp'] ) ? $in['pp'] : array() as $row ) {
			if ( is_array( $row ) && ! empty( $row['pub_id'] ) && is_string( $row['pub_id'] ) ) {
				$url = isset( $row['url'] ) && is_string( $row['url'] ) ? trim( $row['url'] ) : '';
				if ( '' === $url ) {
					continue;
				}
				if ( ! BKF_Settings::pub_url_ok( $url ) ) {
					$bad_pp[] = (string) ( $row['name'] ?? $row['pub_id'] );
					continue;
				}
				$new['pub_prices'][ $row['pub_id'] ] = array(
					'name' => (string) ( $row['name'] ?? '' ),
					'url'  => $url,
				);
			}
		}
		$new['pub_labels'] = isset( $in['labels_text'] ) && is_string( $in['labels_text'] ) ? preg_split( '/\R/u', $in['labels_text'] ) : $cur['pub_labels'];
		$new['order']  = isset( $in['order_text'] ) && is_string( $in['order_text'] ) ? preg_split( '/\R/u', $in['order_text'] ) : array();
		$new['rename'] = isset( $in['rename_text'] ) && is_string( $in['rename_text'] ) ? BKF_Settings::parse_rename( $in['rename_text'] ) : array();
		unset( $new['pp'], $new['labels_text'], $new['order_text'], $new['rename_text'], $new['role_names'], $new['role_targets'], $new['cat_names'], $new['cat_terms'], $new['vat_rates'], $new['vat_classes'], $new['stock_names'], $new['stock_to'] );

		$msgs = array();
		if ( $bad_pp ) {
			/* translators: %s: publishers */
			$msgs[] = self::msg( 'warning', sprintf( __( 'Δεν κρατήθηκε η σελίδα για: %s. Χρειάζεται http(s):// και {isbn} ή {isbn13}.', 'book-feed' ), implode( ', ', $bad_pp ) ) );
		}
		if ( isset( $in['url'] ) && is_string( $in['url'] ) && '' !== trim( $in['url'] ) && BKF_Settings::clean( $new )['url'] !== esc_url_raw( trim( $in['url'] ) ) ) {
			$msgs[] = self::msg( 'warning', __( 'Η διεύθυνση δεν είναι έγκυρη (http ή https, χωρίς παραμέτρους): κρατήθηκε η προεπιλογή.', 'book-feed' ) );
		}
		BKF_Settings::save( $new );
		if ( $new['url'] !== $cur['url'] || ! empty( $in['https'] ) !== $cur['https'] ) {
			delete_transient( BKF_Client::SCHEME_TR );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in route().
		if ( ! empty( $_POST['bkf_pass_clear'] ) ) {
			BKF_Settings::set_password( '' );
		} elseif ( isset( $_POST['bkf_pass'] ) && is_string( $_POST['bkf_pass'] ) && '' !== $_POST['bkf_pass'] ) {
			// The password is kept byte for byte (no sanitizing that could change it).
			$pw = (string) wp_unslash( $_POST['bkf_pass'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( strlen( $pw ) > 200 || preg_match( '/[\x00-\x1f]/', $pw ) ) {
				$msgs[] = self::msg( 'error', __( 'Ο κωδικός δεν αποθηκεύτηκε: έως 200 χαρακτήρες, χωρίς χαρακτήρες ελέγχου.', 'book-feed' ) );
			} elseif ( ! BKF_Settings::set_password( $pw ) ) {
				$msgs[] = self::msg( 'error', __( 'Ο κωδικός δεν αποθηκεύτηκε: η PHP του server δεν έχει OpenSSL με AES-256-GCM. Χρησιμοποίησε τη σταθερά BKF_PASSWORD.', 'book-feed' ) );
			}
		}
		$test = ! empty( $_POST['bkf_test'] );
		// phpcs:enable

		BKF_Sync::reschedule_daily();
		array_unshift( $msgs, self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'book-feed' ) ) );

		if ( $test ) {
			$msgs[] = self::test_connection();
		}
		return $msgs;
	}

	/** One small request: the number of books the account sees. */
	/** Reads the publisher's price for one ISBN, changing nothing. */
	private static function pp_test(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$isbn = isset( $_POST['bkf_pp_isbn'] ) && is_string( $_POST['bkf_pp_isbn'] ) ? BKF_Record::isbn_digits( sanitize_text_field( wp_unslash( $_POST['bkf_pp_isbn'] ) ) ) : '';
		if ( '' === $isbn ) {
			return self::msg( 'error', __( 'Γράψε ένα έγκυρο ISBN.', 'book-feed' ) );
		}
		$res = BKF_Client::query(
			array(
				'q'    => BKF_Client::book_query( array( 'isbn' => $isbn ) ),
				'fl'   => BKF_Client::BOOK_FIELDS,
				'rows' => 1,
			)
		);
		if ( is_wp_error( $res ) ) {
			/* translators: %s: error */
			return self::msg( 'error', sprintf( __( 'Η σύνδεση απέτυχε: %s', 'book-feed' ), $res->get_error_message() ) );
		}
		$recs = BKF_Record::from_docs( $res['docs'] );
		if ( ! $recs ) {
			return self::msg( 'error', __( 'Ο τίτλος δεν βρέθηκε στην υπηρεσία.', 'book-feed' ) );
		}
		$r = $recs[0];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$page = isset( $_POST['bkf_pp_url'] ) && is_string( $_POST['bkf_pp_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['bkf_pp_url'] ) ), array( 'http', 'https' ) ) : '';
		if ( '' === $page ) {
			$pid  = BKF_Importer::find_by_isbn( $isbn );
			$page = $pid ? (string) get_post_meta( $pid, '_bkf_pub_url', true ) : '';
		}
		$r['pub_url'] = $page;

		$url = BKF_Pub_Price::url( BKF_Pub_Price::template( $r ), $r );
		if ( '' === $url ) {
			/* translators: %s: publisher */
			return self::msg( 'warning', sprintf( __( 'Δεν έχει οριστεί σελίδα για τον εκδότη «%s» στις Ρυθμίσεις.', 'book-feed' ), '' !== $r['pub_name'] ? $r['pub_name'] : $r['imprint'] ) );
		}
		$why = '';
		$p   = BKF_Pub_Price::price( $r, $why, true );
		$cat = null === $r['price'] ? '—' : BKF_Pub_Price::money( (float) $r['price'] );
		if ( null === $p ) {
			/* translators: 1: title, 2: catalogue price, 3: reason, 4: address */
			return self::msg( 'warning', sprintf( __( '«%1$s»: μένει η τιμή του καταλόγου (%2$s). %3$s Σελίδα: %4$s', 'book-feed' ), $r['title'], $cat, $why, $url ) );
		}
		/* translators: 1: title, 2: catalogue price, 3: publisher price, 4: address */
		return self::msg( 'success', sprintf( __( '«%1$s»: κατάλογος %2$s, εκδότης %3$s. Σελίδα: %4$s', 'book-feed' ), $r['title'], $cat, BKF_Pub_Price::money( (float) $p ), $url ) );
	}

	private static function test_connection(): array {
		delete_transient( BKF_Client::SCHEME_TR );
		$r = BKF_Client::query(
			array(
				'q'    => '*:*',
				'fl'   => 'product_id',
				'rows' => 1,
			)
		);
		if ( is_wp_error( $r ) ) {
			/* translators: %s: error */
			return self::msg( 'error', sprintf( __( 'Η σύνδεση απέτυχε: %s', 'book-feed' ), $r->get_error_message() ) );
		}
		$scheme = BKF_Client::scheme();
		/* translators: 1: number of books, 2: http or https */
		$text = sprintf( __( 'Η σύνδεση δουλεύει (%2$s): ο κατάλογος έχει %1$s τίτλους.', 'book-feed' ), number_format_i18n( $r['num'] ), '' !== $scheme ? $scheme : 'http' );
		if ( 'http' === $scheme ) {
			$text .= ' ' . __( 'Προσοχή: χωρίς https ο κωδικός ταξιδεύει χωρίς κρυπτογράφηση.', 'book-feed' );
		}
		return self::msg( 'http' === $scheme ? 'warning' : 'success', $text );
	}

	private static function do_save_rules(): array {
		$in    = self::post_array( 'bkf_r' );
		$rules = array();
		$bad   = 0;
		foreach ( $in as $r ) {
			if ( ! is_array( $r ) || ! empty( $r['delete'] ) ) {
				continue;
			}
			$pub = isset( $r['pub_id'] ) && is_scalar( $r['pub_id'] ) ? trim( (string) $r['pub_id'] ) : '';
			if ( '' === $pub ) {
				if ( ! empty( $r['id'] ) ) {
					++$bad;
				}
				continue; // the empty "new rule" card
			}
			$c = BKF_Settings::clean_rule( $r );
			if ( ! $c ) {
				++$bad;
				continue;
			}
			if ( 'external' === ( $r['mode'] ?? '' ) && 'sell' === $c['mode'] ) {
				++$bad;
			}
			$rules[] = $c;
		}
		BKF_Settings::save_rules( $rules );
		$msgs = array( self::msg( 'success', __( 'Οι κανόνες αποθηκεύτηκαν.', 'book-feed' ) ) );
		if ( $bad ) {
			$msgs[] = self::msg( 'warning', __( 'Κάποιοι κανόνες δεν κρατήθηκαν ή άλλαξαν: ο κωδικός εκδότη θέλει μόνο γράμματα, ψηφία, - και _, και η «Μόνο προβολή» θέλει διεύθυνση http(s).', 'book-feed' ) );
		}
		return $msgs;
	}

	/* =====================================================================
	 * Privileged GET: CSV, settings export
	 * =================================================================== */

	public static function route_get(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		$csv = self::q( 'bkf_csv' );
		$exp = self::q( 'bkf_export' );
		if ( '' === $csv && '' === $exp ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'book-feed' ) );
		}
		if ( '' !== $exp ) {
			self::export_settings();
		}
		self::export_csv( 'el' === $csv ? 'el' : 'std', sanitize_key( self::q( 'bkf_act' ) ) );
	}

	private static function export_settings(): void {
		$state = array(
			'plugin'  => 'book-feed',
			'version' => BKF_VERSION,
			'options' => array(
				BKF_Settings::OPTION => BKF_Settings::get(),
				BKF_Settings::RULES  => BKF_Settings::rules(),
			),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="book-feed-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	/** A text cell safe from spreadsheet formulas (Bible §5). */
	private static function cell( string $v ): string {
		if ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$v = "'" . $v;
		}
		return $v;
	}

	private static function export_csv( string $format, string $act ): void {
		$sep  = 'el' === $format ? ';' : ',';
		$rows = BKF_Log::all();
		if ( '' !== $act ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $e ) use ( $act ) {
						return $e['act'] === $act;
					}
				)
			);
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="book-feed-log-' . wp_date( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( __( 'Πότε', 'book-feed' ), __( 'Ενέργεια', 'book-feed' ), __( 'Προϊόν', 'book-feed' ), __( 'Τίτλος', 'book-feed' ), 'ISBN', __( 'Πεδία', 'book-feed' ), __( 'Μήνυμα', 'book-feed' ) ), $sep, '"', '\\' );
		foreach ( $rows as $e ) {
			fputcsv(
				$out,
				array(
					wp_date( 'Y-m-d H:i', $e['t'] ),
					self::cell( BKF_Log::act_label( $e['act'] ) ),
					$e['pid'] ? $e['pid'] : '',
					self::cell( $e['title'] ),
					self::cell( $e['isbn'] ),
					self::cell( BKF_Log::fields_text( $e['fields'] ) ),
					self::cell( $e['msg'] ),
				),
				$sep,
				'"',
				'\\'
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Strict import of a settings backup: each option goes through the same
	 * validation as the forms; an invalid blob leaves that option untouched.
	 */
	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['bkf_import_file']['error'] ) ? (int) $_FILES['bkf_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['bkf_import_file']['size'] ) ? (int) $_FILES['bkf_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'book-feed' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['bkf_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['bkf_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'book-feed' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['bkf_import_file']['tmp_name'] ), true );
		// phpcs:enable
		if ( ! is_array( $state ) || ! isset( $state['options'] ) || ! is_array( $state['options'] ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON με ρυθμίσεις του Book Feed.', 'book-feed' ) ) );
		}
		$ok   = 0;
		$fail = 0;
		$opts = $state['options'];
		if ( isset( $opts[ BKF_Settings::OPTION ] ) && is_array( $opts[ BKF_Settings::OPTION ] ) ) {
			BKF_Settings::save( array_merge( BKF_Settings::defaults(), $opts[ BKF_Settings::OPTION ] ) );
			++$ok;
		} elseif ( isset( $opts[ BKF_Settings::OPTION ] ) ) {
			++$fail;
		}
		$in_rules = 0;
		if ( isset( $opts[ BKF_Settings::RULES ] ) && is_array( $opts[ BKF_Settings::RULES ] ) ) {
			$in_rules = count( $opts[ BKF_Settings::RULES ] );
			BKF_Settings::save_rules( $opts[ BKF_Settings::RULES ] );
			++$ok;
		} elseif ( isset( $opts[ BKF_Settings::RULES ] ) ) {
			++$fail;
		}
		if ( ! $ok ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON με ρυθμίσεις του Book Feed.', 'book-feed' ) ) );
		}
		BKF_Sync::reschedule_daily();
		$kept = count( BKF_Settings::rules() );
		if ( $fail || $kept < $in_rules ) {
			/* translators: 1: rules kept, 2: rules in the file */
			return array( self::msg( 'warning', sprintf( __( 'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ: κρατήθηκαν %1$d από %2$d κανόνες, και ό,τι δεν ήταν έγκυρο έμεινε όπως ήταν.', 'book-feed' ), $kept, $in_rules ) ) );
		}
		return array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'book-feed' ) ) );
	}

	/* =====================================================================
	 * Notices + footer
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'bkf_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'bkf_aui_msg_' . get_current_user_id() );
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
		<p class="bkf-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'book-feed' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="bkf-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="bkf-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'book-feed' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="bkf-footer-cta">
			<a class="bkf-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'book-feed' ); ?>
			</a>
			<a class="bkf-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'book-feed' ); ?>
			</a>
		</p>
		<?php
	}
}

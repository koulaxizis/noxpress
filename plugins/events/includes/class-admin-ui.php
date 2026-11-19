<?php
/**
 * EVT_Admin_UI — menu, list columns, settings, tools, footer (Bible §3–§8, §11).
 *
 * Submenus of the shared "Noxpress" menu (admin_menu priority 440):
 *  - «Εκδηλώσεις»               edit.php?post_type=nox_event (edit_posts)
 *  - «Εκδηλώσεις: ρυθμίσεις»    evt-settings (Events_Plugin::cap()), tabs:
 *      settings  mode, language, URL bases, boxes, .ics feed, schema
 *      texts     visitor texts (EL / EN)
 *      tools     settings backup, uninstall choice, developers
 *
 * The event list gets the columns «Πότε» and «Κατάσταση» (sortable by the
 * start, newest first by default) and a line with links to the venues and
 * the settings. Every state change: POST on admin_init (slug + nonce +
 * capability, PRG, message in the transient evt_aui_msg_{uid}).
 */

defined( 'ABSPATH' ) || exit;

final class EVT_Admin_UI {

	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'evt-settings';
	const NONCE       = 'evt_admin';
	const NONCE_FIELD = 'evt_admin_nonce';
	const NONCE_BAK   = 'evt_backup';
	const MAX_IMPORT  = 262144;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 440 );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( EVT_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'manage_' . EVT_CPT::PT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . EVT_CPT::PT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'manage_edit-' . EVT_CPT::PT . '_sortable_columns', array( __CLASS__, 'sortable' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'list_order' ) );
		add_action( 'admin_notices', array( __CLASS__, 'list_notice' ) );
	}

	private static function tabs(): array {
		return array(
			'settings' => __( 'Ρυθμίσεις', 'events' ),
			'texts'    => __( 'Κείμενα', 'events' ),
			'tools'    => __( 'Εργαλεία', 'events' ),
		);
	}

	/* =====================================================================
	 * Menu, list, assets
	 * =================================================================== */

	public static function admin_menu(): void {
		// The shared top-level "Noxpress" is created by Noxpress Core (Bible §16).
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Εκδηλώσεις', 'events' ),
			__( 'Εκδηλώσεις', 'events' ),
			'edit_posts',
			'edit.php?post_type=' . EVT_CPT::PT
		);
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Events — Ρυθμίσεις', 'events' ),
			__( 'Εκδηλώσεις: ρυθμίσεις', 'events' ),
			Events_Plugin::cap(),
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	private static function own_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && ( EVT_CPT::PT === $screen->post_type || EVT_CPT::TAX === $screen->taxonomy );
	}

	/** Keeps "Noxpress" open on the event and venue screens. */
	public static function parent_file( $file ) {
		return self::own_screen() ? self::SLUG_MENU : $file;
	}

	public static function submenu_file( $file, $parent = '' ) {
		return self::own_screen() ? 'edit.php?post_type=' . EVT_CPT::PT : $file;
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'events' ) . '</a>'
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
		return isset( self::tabs()[ $tab ] ) ? $tab : 'settings';
	}

	public static function assets(): void {
		if ( self::SLUG === self::current_page() || self::own_screen() ) {
			wp_enqueue_style( 'evt-admin', EVT_URL . 'assets/admin.css', array(), EVT_VERSION );
		}
	}

	public static function columns( $cols ): array {
		$cols = is_array( $cols ) ? $cols : array();
		$out  = array();
		foreach ( $cols as $k => $v ) {
			if ( 'date' === $k ) {
				continue;
			}
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['evt_when']   = esc_html__( 'Πότε', 'events' );
				$out['evt_status'] = esc_html__( 'Κατάσταση', 'events' );
			}
		}
		$out['date'] = $cols['date'] ?? esc_html__( 'Ημερομηνία', 'events' );
		return $out;
	}

	private static function status_label( string $s ): string {
		$map = array(
			'scheduled'   => __( 'Προγραμματισμένη', 'events' ),
			'postponed'   => __( 'Αναβλήθηκε', 'events' ),
			'rescheduled' => __( 'Νέα ημερομηνία', 'events' ),
			'cancelled'   => __( 'Ακυρώθηκε', 'events' ),
		);
		return $map[ $s ] ?? $map['scheduled'];
	}

	public static function column( $col, $post_id ): void {
		$e = EVT_CPT::event( (int) $post_id, true );
		if ( ! $e ) {
			return;
		}
		if ( 'evt_when' === $col ) {
			echo $e['start'] > 0 ? esc_html( EVT_CPT::when( $e ) ) : '<span class="evt-muted">' . esc_html__( 'Χωρίς ημερομηνία', 'events' ) . '</span>';
			if ( $e['online'] ) {
				echo '<br /><span class="evt-muted">' . esc_html__( 'Online', 'events' ) . '</span>';
			}
		} elseif ( 'evt_status' === $col ) {
			if ( $e['start'] > 0 && $e['ended'] ) {
				echo '<span class="evt-badge evt-badge--ended">' . esc_html__( 'Έγινε', 'events' ) . '</span> ';
			}
			echo '<span class="evt-badge evt-badge--' . esc_attr( $e['status'] ) . '">' . esc_html( self::status_label( $e['status'] ) ) . '</span>';
		}
	}

	public static function sortable( $cols ): array {
		$cols             = is_array( $cols ) ? $cols : array();
		$cols['evt_when'] = 'evt_start';
		return $cols;
	}

	/** The admin list: by start (newest first) unless another order was chosen. */
	public static function list_order( $q ): void {
		if ( ! is_admin() || ! $q instanceof WP_Query || ! $q->is_main_query() || EVT_CPT::PT !== $q->get( 'post_type' ) ) {
			return;
		}
		$by = (string) $q->get( 'orderby' );
		if ( '' !== $by && 'evt_start' !== $by ) {
			return;
		}
		$q->set(
			'meta_query',
			array(
				'relation' => 'OR',
				'evt_s'    => array(
					'key'     => '_evt_start',
					'compare' => 'EXISTS',
					'type'    => 'NUMERIC',
				),
				array(
					'key'     => '_evt_start',
					'compare' => 'NOT EXISTS',
				),
			)
		);
		$q->set( 'orderby', array( 'evt_s' => 'ASC' === strtoupper( (string) $q->get( 'order' ) ) ? 'ASC' : 'DESC' ) );
	}

	/** One line above the event list: venues, settings, test mode. */
	public static function list_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-' . EVT_CPT::PT !== $screen->id ) {
			return;
		}
		echo '<div class="notice notice-info inline evt-listbar"><p>';
		echo '<a href="' . esc_url( admin_url( 'edit-tags.php?taxonomy=' . EVT_CPT::TAX . '&post_type=' . EVT_CPT::PT ) ) . '">' . esc_html__( 'Χώροι', 'events' ) . '</a>';
		if ( current_user_can( Events_Plugin::cap() ) ) {
			echo ' · <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'events' ) . '</a>';
		}
		$mode = EVT_Settings::mode();
		if ( 'test' === $mode ) {
			echo ' · <strong>' . esc_html__( 'Δοκιμή: τις σελίδες τις βλέπουν μόνο όσοι επεξεργάζονται άρθρα.', 'events' ) . '</strong>';
		} elseif ( 'off' === $mode ) {
			echo ' · <strong>' . esc_html__( 'Ανενεργό: καμία σελίδα εκδήλωσης δεν φαίνεται.', 'events' ) . '</strong>';
		}
		echo '</p></div>';
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Events_Plugin::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'events' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap evt-wrap">
			<h1><?php esc_html_e( 'Events', 'events' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Εκδηλώσεις με ημερομηνία, χώρο, κατάσταση και είσοδο, συνδεδεμένες με προϊόντα και δημιουργούς. Λίστα επερχόμενων, αρχείο, «Πρόσθεσε στο ημερολόγιο» και Event schema.', 'events' ); ?></p>
			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . EVT_CPT::PT ) ); ?>"><?php esc_html_e( 'Όλες οι εκδηλώσεις', 'events' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . EVT_CPT::PT ) ); ?>"><?php esc_html_e( 'Νέα εκδήλωση', 'events' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . EVT_CPT::TAX . '&post_type=' . EVT_CPT::PT ) ); ?>"><?php esc_html_e( 'Χώροι', 'events' ); ?></a>
			</p>
			<?php
			self::print_notices( self::take_msgs() );
			if ( Events_Plugin::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το EVT_DISABLE είναι ενεργό στο wp-config.php: καμία σελίδα εκδήλωσης, λίστα ή κουτί δεν εμφανίζεται.', 'events' ) . '</p></div>';
			}
			if ( '' === (string) get_option( 'permalink_structure' ) ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Τα permalinks είναι «Απλά»: οι σελίδες των εκδηλώσεων ανοίγουν με ?nox_event=… αντί για τη βάση URL.', 'events' ) . '</p></div>';
			}
			?>
			<nav class="nav-tab-wrapper evt-tabs">
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
					self::render_settings();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	private static function checkbox( string $name, bool $on, string $label ): void {
		printf(
			'<label class="evt-check"><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label )
		);
	}

	private static function radio( string $name, string $value, string $current, string $label ): void {
		printf(
			'<label class="evt-check"><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $value, $current, false ),
			esc_html( $label )
		);
	}

	private static function render_settings(): void {
		$s    = EVT_Settings::get();
		$arch = get_post_type_archive_link( EVT_CPT::PT );
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="evt_action" value="save_settings" />

			<h2 class="evt-h2"><?php esc_html_e( 'Λειτουργία', 'events' ); ?></h2>
			<div class="evt-zone">
				<?php
				self::radio( 'evt_mode', 'off', $s['mode'], __( 'Ανενεργό: καμία σελίδα, λίστα ή κουτί εκδηλώσεων', 'events' ) );
				self::radio( 'evt_mode', 'test', $s['mode'], __( 'Δοκιμή: τις σελίδες, τις λίστες και τα κουτιά τα βλέπουν μόνο όσοι επεξεργάζονται άρθρα', 'events' ) );
				self::radio( 'evt_mode', 'live', $s['mode'], __( 'Ενεργό: όλοι οι επισκέπτες', 'events' ) );
				?>
				<span class="evt-label"><?php esc_html_e( 'Γλώσσα των κειμένων για τους επισκέπτες', 'events' ); ?></span>
				<?php
				self::radio( 'evt_lang', 'auto', $s['lang'], __( 'Αυτόματα, από τη γλώσσα του site', 'events' ) );
				self::radio( 'evt_lang', 'el', $s['lang'], __( 'Ελληνικά', 'events' ) );
				self::radio( 'evt_lang', 'en', $s['lang'], __( 'English', 'events' ) );
				?>
			</div>

			<h2 class="evt-h2"><?php esc_html_e( 'Διευθύνσεις', 'events' ); ?></h2>
			<div class="evt-zone">
				<label class="evt-field"><span class="evt-label"><?php esc_html_e( 'Βάση URL των εκδηλώσεων', 'events' ); ?></span>
					<code><?php echo esc_html( trailingslashit( home_url() ) ); ?></code><input type="text" name="evt_base" value="<?php echo esc_attr( $s['base'] ); ?>" maxlength="40" pattern="[a-z0-9\-]+" /></label>
				<label class="evt-field"><span class="evt-label"><?php esc_html_e( 'Βάση URL των χώρων', 'events' ); ?></span>
					<code><?php echo esc_html( trailingslashit( home_url() ) ); ?></code><input type="text" name="evt_venue_base" value="<?php echo esc_attr( $s['venue_base'] ); ?>" maxlength="40" pattern="[a-z0-9\-]+" /></label>
				<p class="description"><?php esc_html_e( 'Λατινικά πεζά, αριθμοί και παύλες. Αν αλλάξεις βάση, οι παλιοί σύνδεσμοι σταματούν να ανοίγουν (βάλε ανακατευθύνσεις στο Redirects).', 'events' ); ?></p>
				<?php if ( $arch ) : ?>
					<p><?php esc_html_e( 'Αρχείο εκδηλώσεων:', 'events' ); ?> <a href="<?php echo esc_url( (string) $arch ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) $arch ); ?></a></p>
				<?php endif; ?>
			</div>

			<h2 class="evt-h2"><?php esc_html_e( 'Κουτιά «Επόμενες εκδηλώσεις»', 'events' ); ?></h2>
			<div class="evt-zone">
				<?php
				self::checkbox( 'evt_on_product', $s['on_product'], __( 'Στις σελίδες των προϊόντων που συνδέονται με μια επερχόμενη εκδήλωση', 'events' ) );
				self::checkbox( 'evt_on_creator', $s['on_creator'], __( 'Στις σελίδες των δημιουργών (Featured Creators)', 'events' ) );
				?>
				<label class="evt-field"><span class="evt-label"><?php esc_html_e( 'Εκδηλώσεις σε κάθε κουτί', 'events' ); ?></span>
					<input type="number" class="evt-num" name="evt_box_count" value="<?php echo (int) $s['box_count']; ?>" min="1" max="10" step="1" /></label>
				<p class="description"><?php esc_html_e( 'Το κουτί εμφανίζεται μόνο όταν υπάρχει επερχόμενη εκδήλωση. Λίστα οπουδήποτε: shortcode [nx_events] ή το block «Εκδηλώσεις».', 'events' ); ?></p>
			</div>

			<?php if ( Events_Plugin::woo() ) : ?>
				<h2 class="evt-h2"><?php esc_html_e( 'Εισιτήρια', 'events' ); ?></h2>
				<div class="evt-zone">
					<label class="evt-field"><span class="evt-label"><?php esc_html_e( 'Οι πωλήσεις κλείνουν τόσες ώρες πριν την έναρξη', 'events' ); ?></span>
						<input type="number" class="evt-num" name="evt_close_h" value="<?php echo (int) $s['close_h']; ?>" min="0" max="168" step="1" /></label>
					<label class="evt-field"><span class="evt-label"><?php esc_html_e( 'Τα ονόματα συμμετεχόντων σβήνονται τόσες ημέρες μετά την εκδήλωση', 'events' ); ?></span>
						<input type="number" class="evt-num" name="evt_names_days" value="<?php echo (int) $s['names_days']; ?>" min="1" max="365" step="1" /></label>
					<p class="description"><?php esc_html_e( 'Ένα εισιτήριο είναι προϊόν του καταστήματος που συνδέεις στην εκδήλωση (κουτί «Εισιτήρια στο κατάστημα»). Οι θέσεις είναι το απόθεμά του. Για την είσοδο, δώσε σε όποιον ελέγχει τα εισιτήρια τον ρόλο «Ταμείο εκδήλωσης».', 'events' ); ?></p>
				</div>
			<?php endif; ?>

			<h2 class="evt-h2"><?php esc_html_e( 'Ημερολόγιο και SEO', 'events' ); ?></h2>
			<div class="evt-zone">
				<?php
				self::checkbox( 'evt_ics_feed', $s['ics_feed'], __( 'Feed .ics με όλες τις επερχόμενες εκδηλώσεις (για συνδρομή από ημερολόγιο)', 'events' ) );
				if ( $s['ics_feed'] ) {
					echo '<p class="description"><code>' . esc_html( EVT_Ics::feed_link() ) . '</code></p>';
				}
				self::checkbox( 'evt_schema', $s['schema'], __( 'Event schema (JSON-LD) στη σελίδα κάθε εκδήλωσης', 'events' ) );
				?>
				<p class="description"><?php esc_html_e( 'Με το Search Signals ή το Rank Math ενεργό, το schema μπαίνει μέσα στο δικό τους graph. Κάθε εκδήλωση έχει πάντα το δικό της αρχείο .ics και σύνδεσμο Google Calendar.', 'events' ); ?></p>
			</div>

			<?php submit_button( __( 'Αποθήκευση', 'events' ) ); ?>
		</form>
		<?php
	}

	private static function render_texts(): void {
		$s     = EVT_Settings::get();
		$texts = EVT_Settings::texts();
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="evt_action" value="save_texts" />
			<h2 class="evt-h2"><?php esc_html_e( 'Κείμενα για τους επισκέπτες', 'events' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Κενό ελληνικό = το αρχικό. Κενό αγγλικό = το ελληνικό.', 'events' ); ?></p>
			<table class="widefat striped evt-table evt-texts">
				<thead><tr><th><?php esc_html_e( 'Ελληνικά', 'events' ); ?></th><th><?php esc_html_e( 'English', 'events' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $texts as $k => $t ) : ?>
						<tr>
							<td><input type="text" class="evt-wide" name="evt_t_<?php echo esc_attr( $k ); ?>_el" value="<?php echo esc_attr( $s[ 't_' . $k . '_el' ] ); ?>" maxlength="<?php echo (int) $t[2]; ?>" placeholder="<?php echo esc_attr( $t[0] ); ?>" /></td>
							<td><input type="text" class="evt-wide" name="evt_t_<?php echo esc_attr( $k ); ?>_en" value="<?php echo esc_attr( $s[ 't_' . $k . '_en' ] ); ?>" maxlength="<?php echo (int) $t[2]; ?>" placeholder="<?php echo esc_attr( $t[1] ); ?>" /></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Αποθήκευση', 'events' ) ); ?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tools
	 * ------------------------------------------------------------------- */

	private static function render_tools(): void {
		$s = EVT_Settings::get();
		?>
		<h2 class="evt-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'events' ); ?></h2>
		<div class="evt-zone">
			<form method="post" enctype="multipart/form-data" class="evt-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'evt_backup_nonce' ); ?>
				<input type="file" name="evt_import_file" accept=".json,application/json" />
				<button type="submit" name="evt_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'events' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=tools&evt_export=1' ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'events' ); ?></a>
			<p class="description evt-mt-8"><?php esc_html_e( 'Μόνο οι ρυθμίσεις. Οι εκδηλώσεις και οι χώροι μεταφέρονται με το Εργαλεία → Εξαγωγή του WordPress.', 'events' ); ?></p>
		</div>

		<h2 class="evt-h2"><?php esc_html_e( 'Απεγκατάσταση', 'events' ); ?></h2>
		<div class="evt-zone">
			<form method="post">
				<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
				<input type="hidden" name="evt_action" value="save_uninstall" />
				<?php self::checkbox( 'evt_delete_all', $s['delete_all'], __( 'Διαγραφή των εκδηλώσεων και των χώρων κατά τη διαγραφή του plugin', 'events' ) ); ?>
				<p class="description"><?php esc_html_e( 'Κλειστό (προεπιλογή): οι ρυθμίσεις σβήνονται, οι εκδηλώσεις και οι χώροι μένουν στη βάση.', 'events' ); ?></p>
				<?php submit_button( __( 'Αποθήκευση', 'events' ), 'secondary' ); ?>
			</form>
		</div>

		<h2 class="evt-h2"><?php esc_html_e( 'Για developers', 'events' ); ?></h2>
		<div class="evt-zone">
			<table class="widefat striped evt-table">
				<tbody>
					<tr><td><code>[nx_events count="5" creator="" product="" venue="" city="" past="0" title=""]</code></td><td><?php esc_html_e( 'Λίστα εκδηλώσεων. Τα creator και product δέχονται id ή «current».', 'events' ); ?></td></tr>
					<tr><td><code>evt_get_event( $id )</code></td><td><?php esc_html_e( 'Μια δημοσιευμένη εκδήλωση ως πίνακας, ή null.', 'events' ); ?></td></tr>
					<tr><td><code>evt_get_upcoming( $args )</code></td><td><?php esc_html_e( 'Οι επερχόμενες εκδηλώσεις (creator, product, venue, city, limit).', 'events' ); ?></td></tr>
					<tr><td><code>evt_event_published</code> <?php esc_html_e( '(action)', 'events' ); ?></td><td><?php esc_html_e( 'Μία φορά, την πρώτη φορά που δημοσιεύεται μια εκδήλωση: ( $id ).', 'events' ); ?></td></tr>
					<tr><td><code>evt_event_changed</code> <?php esc_html_e( '(action)', 'events' ); ?></td><td><?php esc_html_e( 'Μετά από κάθε αποθήκευση εκδήλωσης: ( $id ).', 'events' ); ?></td></tr>
					<tr><td><code>evt_schema</code> <?php esc_html_e( '(φίλτρο)', 'events' ); ?></td><td><?php esc_html_e( 'Αλλάζει το Event schema: ( $node, $event ).', 'events' ); ?></td></tr>
					<tr><td><code>evt_text</code> <?php esc_html_e( '(φίλτρο)', 'events' ); ?></td><td><?php esc_html_e( 'Αλλάζει ένα κείμενο του επισκέπτη: ( $text, $key, $lang ).', 'events' ); ?></td></tr>
					<tr><td><code>?evt_ics=ID</code>, <code>?evt_feed=ics</code></td><td><?php esc_html_e( 'Το .ics μιας εκδήλωσης και το feed (αν είναι ανοιχτό).', 'events' ); ?></td></tr>
					<tr><td><code>EVT_DISABLE</code></td><td><?php esc_html_e( 'Στο wp-config.php: σταματά κάθε λειτουργία στο front-end.', 'events' ); ?></td></tr>
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

		if ( isset( $_POST['evt_import'] ) ) {
			if ( ! isset( $_POST['evt_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['evt_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( Events_Plugin::cap() ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'events' ) );
			}
			self::redirect( 'tools', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['evt_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Events_Plugin::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'events' ) );
		}

		$s  = EVT_Settings::get();
		$ok = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'events' ) ) );
		switch ( sanitize_key( wp_unslash( $_POST['evt_action'] ) ) ) {
			case 'save_settings':
				$s['mode']       = sanitize_key( self::post( 'evt_mode' ) );
				$s['lang']       = sanitize_key( self::post( 'evt_lang' ) );
				$s['base']       = self::post( 'evt_base' );
				$s['venue_base'] = self::post( 'evt_venue_base' );
				$s['box_count']  = (int) self::post( 'evt_box_count' );
				if ( Events_Plugin::woo() ) {
					$s['close_h']    = (int) self::post( 'evt_close_h' );
					$s['names_days'] = (int) self::post( 'evt_names_days' );
				}
				foreach ( array( 'on_product', 'on_creator', 'ics_feed', 'schema' ) as $k ) {
					$s[ $k ] = '1' === self::post( 'evt_' . $k );
				}
				EVT_Settings::save( $s );
				self::redirect( 'settings', $ok );
				break;
			case 'save_texts':
				foreach ( array_keys( EVT_Settings::texts() ) as $k ) {
					foreach ( array( 'el', 'en' ) as $l ) {
						$s[ 't_' . $k . '_' . $l ] = self::post( 'evt_t_' . $k . '_' . $l );
					}
				}
				EVT_Settings::save( $s );
				self::redirect( 'texts', $ok );
				break;
			case 'save_uninstall':
				$s['delete_all'] = '1' === self::post( 'evt_delete_all' );
				EVT_Settings::save( $s );
				self::redirect( 'tools', $ok );
				break;
			default:
				self::redirect( 'settings', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'events' ) ) ) );
		}
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		set_transient( 'evt_aui_msg_' . get_current_user_id(), $msgs, 60 );
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
		if ( self::SLUG !== self::current_page() || '' === self::q( 'evt_export' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( Events_Plugin::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'events' ) );
		}
		$state = array(
			'plugin'  => 'events',
			'version' => EVT_VERSION,
			'options' => array(
				EVT_Settings::OPT => EVT_Settings::get(),
			),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="events-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['evt_import_file']['error'] ) ? (int) $_FILES['evt_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['evt_import_file']['size'] ) ? (int) $_FILES['evt_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'events' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['evt_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['evt_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'events' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['evt_import_file']['tmp_name'] ), true );
		// phpcs:enable
		$blob = is_array( $state ) && isset( $state['options'][ EVT_Settings::OPT ] ) ? $state['options'][ EVT_Settings::OPT ] : null;
		if ( ! is_array( $blob ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Events).', 'events' ) ) );
		}
		EVT_Settings::save( $blob );
		return array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'events' ) ) );
	}

	/* =====================================================================
	 * Notices, footer
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'evt_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'evt_aui_msg_' . get_current_user_id() );
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
		<p class="evt-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'events' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="evt-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="evt-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'events' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="evt-footer-cta">
			<a class="evt-kofi" href="https://noxpress.tech/support/" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '♡ Στήριξε το Noxpress', 'events' ); ?>
			</a>
			<a class="evt-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'events' ); ?>
			</a>
		</p>
		<?php
	}
}

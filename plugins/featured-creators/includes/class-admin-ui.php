<?php
/**
 * CRT_Admin_UI — menu, creator list columns, settings page, PRG routes,
 * backup (Bible §3–§8, §11).
 *
 * Submenus of the shared "Noxpress" menu (admin_menu priority 100):
 *  - «Δημιουργοί»     edit.php?post_type=nox_creator (the creators)
 *  - «FC Ρυθμίσεις»   crt-settings, with tabs:
 *      roles     mode, roles (order, headings, names, schema, box), language
 *      page      creator page, bundles, formats, index, links, product box
 *      texts     visitor texts
 *      seo       Person / ProfilePage, product types per category
 *      coverage  products without roles, people without creators,
 *                creators without products, conflicts (read-only)
 *      import    draft creators from the terms (AJAX batches)
 *      tools     settings export / import, shortcodes, uninstall choice
 *
 * Every state change: POST on admin_init, slug + nonce + capability,
 * strict validation (CRT_Settings::sanitize), PRG with the message in the
 * transient crt_aui_msg_{uid}. Export: privileged GET.
 */

defined( 'ABSPATH' ) || exit;

final class CRT_Admin_UI {

	const CAP         = 'manage_woocommerce';
	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'crt-settings';
	const NONCE       = 'crt_admin';
	const NONCE_FIELD = 'crt_nonce';
	const NONCE_BAK   = 'crt_backup';
	const MAX_IMPORT  = 262144;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 100 );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CRT_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'manage_' . CRT_CPT::PT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . CRT_CPT::PT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'list_notice' ) );
	}

	private static function tabs(): array {
		return array(
			'roles'    => __( 'Ρόλοι', 'featured-creators' ),
			'page'     => __( 'Σελίδες και προϊόντα', 'featured-creators' ),
			'texts'    => __( 'Κείμενα', 'featured-creators' ),
			'seo'      => __( 'SEO', 'featured-creators' ),
			'coverage' => __( 'Κάλυψη', 'featured-creators' ),
			'import'   => __( 'Εισαγωγή', 'featured-creators' ),
			'tools'    => __( 'Εργαλεία', 'featured-creators' ),
		);
	}

	/* =====================================================================
	 * Menu, list, assets
	 * =================================================================== */

	public static function admin_menu(): void {
		// The shared top-level "Noxpress" is created by Noxpress Core
		// (priority 5, Bible §16): submenus only here.
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Featured Creators', 'featured-creators' ),
			__( 'Δημιουργοί', 'featured-creators' ),
			'edit_pages',
			'edit.php?post_type=' . CRT_CPT::PT
		);
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Featured Creators — Ρυθμίσεις', 'featured-creators' ),
			__( 'FC Ρυθμίσεις', 'featured-creators' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/** Keeps "Noxpress" open on the creator list and edit screens. */
	public static function parent_file( $file ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && CRT_CPT::PT === $screen->post_type ) {
			return self::SLUG_MENU;
		}
		return $file;
	}

	public static function submenu_file( $file, $parent = '' ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && CRT_CPT::PT === $screen->post_type ) {
			return 'edit.php?post_type=' . CRT_CPT::PT;
		}
		return $file;
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'featured-creators' ) . '</a>'
		);
		return $links;
	}

	public static function columns( $cols ): array {
		$cols = is_array( $cols ) ? $cols : array();
		$out  = array();
		foreach ( $cols as $k => $v ) {
			if ( 'title' === $k ) {
				$out['crt_photo'] = '<span class="screen-reader-text">' . esc_html__( 'Φωτογραφία', 'featured-creators' ) . '</span>';
			}
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['crt_roles']    = esc_html__( 'Ρόλοι με προϊόντα', 'featured-creators' );
				$out['crt_products'] = esc_html__( 'Προϊόντα', 'featured-creators' );
				$out['crt_key']      = esc_html__( 'Κλειδί', 'featured-creators' );
			}
		}
		return $out;
	}

	public static function column( $col, $post_id ): void {
		$post_id = (int) $post_id;
		switch ( $col ) {
			case 'crt_photo':
				echo has_post_thumbnail( $post_id ) ? get_the_post_thumbnail( $post_id, 'thumbnail', array( 'alt' => '' ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- core image markup.
				break;
			case 'crt_roles':
				echo esc_html( CRT_Front::roles_line_auto( $post_id ) );
				break;
			case 'crt_products':
				echo (int) get_post_meta( $post_id, CRT_CPT::M_COUNT, true );
				break;
			case 'crt_key':
				echo '<code>' . esc_html( CRT_CPT::key( $post_id ) ) . '</code>';
				break;
		}
	}

	/** On the creator list: a hint while no role is set, and the test mode. */
	public static function list_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-' . CRT_CPT::PT !== $screen->id || ! current_user_can( self::CAP ) ) {
			return;
		}
		$s = CRT_Settings::get();
		if ( ! $s['roles'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Δεν έχεις διαλέξει ακόμα ποια χαρακτηριστικά είναι ρόλοι.', 'featured-creators' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'featured-creators' ) . '</a></p></div>';
		} elseif ( 'live' !== $s['mode'] ) {
			echo '<div class="notice notice-info"><p>' . esc_html( 'test' === $s['mode'] ? __( 'Λειτουργία δοκιμής: οι σελίδες δημιουργών φαίνονται μόνο στους διαχειριστές του καταστήματος.', 'featured-creators' ) : __( 'Το Featured Creators είναι ανενεργό: οι σελίδες δημιουργών δεν φαίνονται.', 'featured-creators' ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'featured-creators' ) . '</a></p></div>';
		}
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
		return isset( self::tabs()[ $tab ] ) ? $tab : 'roles';
	}

	public static function assets( $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'edit-' . CRT_CPT::PT === $screen->id ) {
			wp_enqueue_style( 'crt-admin', CRT_URL . 'assets/admin.css', array(), CRT_VERSION );
			return;
		}
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'crt-admin', CRT_URL . 'assets/admin.css', array(), CRT_VERSION );
		wp_enqueue_script( 'crt-admin', CRT_URL . 'assets/admin.js', array( 'jquery', 'jquery-ui-sortable' ), CRT_VERSION, true );
		wp_localize_script(
			'crt-admin',
			'crtAdmin',
			array(
				'ajax'     => admin_url( 'admin-ajax.php' ),
				'nonceImp' => wp_create_nonce( CRT_Import::NONCE ),
				'batch'    => CRT_Import::BATCH,
				/* translators: 1: created, 2: skipped */
				'done'     => __( 'Ολοκληρώθηκε: %1$d νέοι πρόχειροι δημιουργοί, %2$d παραλείφθηκαν.', 'featured-creators' ),
				'error'    => __( 'Η εισαγωγή σταμάτησε με σφάλμα. Ό,τι δημιουργήθηκε μέχρι εκεί έμεινε· ξανατρέξε τη για τα υπόλοιπα.', 'featured-creators' ),
				'none'     => __( 'Δεν επιλέχθηκε κανένα πρόσωπο.', 'featured-creators' ),
			)
		);
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'featured-creators' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap crt-wrap">
			<h1><?php esc_html_e( 'Featured Creators', 'featured-creators' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Μία σελίδα για κάθε δημιουργό, με τα προϊόντα του ανά ρόλο, συνδέσμους από τα προϊόντα και ευρετήριο.', 'featured-creators' ); ?>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . CRT_CPT::PT ) ); ?>"><?php esc_html_e( 'Οι δημιουργοί', 'featured-creators' ); ?> →</a></p>

			<?php self::print_notices( self::take_msgs() ); ?>

			<nav class="nav-tab-wrapper crt-tabs">
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
				case 'page':
					self::render_page_tab();
					break;
				case 'texts':
					self::render_texts();
					break;
				case 'seo':
					self::render_seo();
					break;
				case 'coverage':
					self::render_coverage();
					break;
				case 'import':
					self::render_import();
					break;
				case 'tools':
					self::render_tools();
					break;
				default:
					self::render_roles();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	private static function checkbox( string $name, bool $on, string $label ): void {
		echo '<label class="crt-check"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $on, true, false ) . ' /> ' . esc_html( $label ) . '</label>';
	}

	private static function form_open( string $action ): void {
		echo '<form method="post">';
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		echo '<input type="hidden" name="crt_action" value="' . esc_attr( $action ) . '" />';
	}

	private static function form_close(): void {
		echo '<p class="crt-submit"><button type="submit" class="button button-primary">' . esc_html__( 'Αποθήκευση', 'featured-creators' ) . '</button></p></form>';
	}

	/** Schema property labels. */
	private static function props(): array {
		return array(
			'author'      => 'author',
			'illustrator' => 'illustrator',
			'editor'      => 'editor',
			'translator'  => 'translator',
			'readBy'      => 'readBy',
			'director'    => 'director',
			'actor'       => 'actor',
			'byArtist'    => 'byArtist',
			'producer'    => 'producer',
			'contributor' => 'contributor',
			''            => __( '— κανένα —', 'featured-creators' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Tab: roles
	 * ------------------------------------------------------------------- */

	/** Attributes whose term slugs repeat in other attributes (role candidates). */
	private static function suggested_roles(): array {
		global $wpdb;
		$taxes = array_keys( CRT_Settings::attribute_taxonomies() );
		if ( count( $taxes ) < 2 ) {
			return array();
		}
		$in = implode( ',', array_fill( 0, count( $taxes ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above; admin-only.
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT tt.taxonomy FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy IN ($in) AND t.slug IN ( SELECT t2.slug FROM {$wpdb->terms} t2 INNER JOIN {$wpdb->term_taxonomy} tt2 ON tt2.term_id = t2.term_id WHERE tt2.taxonomy IN ($in) GROUP BY t2.slug HAVING COUNT(DISTINCT tt2.taxonomy) > 1 )", array_merge( $taxes, $taxes ) ) );
		return array_map( 'strval', (array) $rows );
	}

	private static function render_roles(): void {
		$s       = CRT_Settings::get();
		$attrs   = CRT_Settings::attribute_taxonomies();
		$suggest = $s['roles'] ? array() : self::suggested_roles();

		// Rows: chosen roles first (in order), then the other attributes.
		$rows = array();
		foreach ( $s['roles'] as $r ) {
			$rows[ $r['tax'] ] = $r + array( 'on' => true );
		}
		foreach ( $attrs as $tax => $label ) {
			if ( ! isset( $rows[ $tax ] ) ) {
				$rows[ $tax ] = CRT_Settings::new_role( $tax, false ) + array( 'on' => in_array( $tax, $suggest, true ) );
			}
		}

		self::form_open( 'save_roles' );
		?>
		<h2 class="crt-h2"><?php esc_html_e( 'Λειτουργία', 'featured-creators' ); ?></h2>
		<div class="crt-zone">
			<?php
			foreach ( array(
				'off'  => __( 'Ανενεργό', 'featured-creators' ),
				'test' => __( 'Λειτουργία δοκιμής: φαίνεται μόνο στους διαχειριστές του καταστήματος', 'featured-creators' ),
				'live' => __( 'Ενεργό για όλους', 'featured-creators' ),
			) as $k => $label ) {
				echo '<label class="crt-check"><input type="radio" name="crt_mode" value="' . esc_attr( $k ) . '" ' . checked( $s['mode'], $k, false ) . ' /> ' . esc_html( $label ) . '</label>';
			}
			?>
			<p class="description"><?php esc_html_e( 'Στη λειτουργία δοκιμής, οι σελίδες δημιουργών, οι σύνδεσμοι, τα πλαίσια, το ευρετήριο και το schema φαίνονται μόνο σε εσένα· οι υπόλοιποι παίρνουν 404 στις σελίδες δημιουργών και τίποτα αλλού.', 'featured-creators' ); ?></p>
			<p class="description"><?php esc_html_e( 'Έκτακτη απενεργοποίηση χωρίς πρόσβαση στο admin: πρόσθεσε στο wp-config.php τη γραμμή', 'featured-creators' ); ?> <code>define( 'CRT_DISABLE', true );</code></p>
		</div>

		<h2 class="crt-h2"><?php esc_html_e( 'Ρόλοι', 'featured-creators' ); ?></h2>
		<div class="crt-zone">
			<p class="description"><?php esc_html_e( 'Διάλεξε ποια χαρακτηριστικά του WooCommerce είναι ρόλοι δημιουργών και σύρε τα στη σειρά που θέλεις στη σελίδα του δημιουργού. Ένα πρόσωπο αναγνωρίζεται από το ίδιο slug σε όλους τους ρόλους.', 'featured-creators' ); ?></p>
			<?php if ( $suggest ) : ?>
				<p class="crt-warn"><?php esc_html_e( 'Προεπιλεγμένα είναι τα χαρακτηριστικά που μοιράζονται slugs με άλλα (πιθανοί ρόλοι). Έλεγξέ τα και αποθήκευσε.', 'featured-creators' ); ?></p>
			<?php endif; ?>
			<?php if ( ! $attrs ) : ?>
				<p class="crt-warn"><?php esc_html_e( 'Δεν υπάρχουν global χαρακτηριστικά (Προϊόντα → Χαρακτηριστικά).', 'featured-creators' ); ?></p>
			<?php else : ?>
			<table class="widefat crt-table crt-roles">
				<thead><tr>
					<th></th>
					<th><?php esc_html_e( 'Ρόλος', 'featured-creators' ); ?></th>
					<th><?php esc_html_e( 'Χαρακτηριστικό', 'featured-creators' ); ?></th>
					<th><?php esc_html_e( 'Τίτλος ενότητας (el / en)', 'featured-creators' ); ?></th>
					<th><?php esc_html_e( 'Όνομα ρόλου (el / en)', 'featured-creators' ); ?></th>
					<th><?php esc_html_e( 'Schema', 'featured-creators' ); ?></th>
					<th><?php esc_html_e( 'Στο πλαίσιο', 'featured-creators' ); ?></th>
				</tr></thead>
				<tbody data-crt-sortable>
				<?php
				foreach ( $rows as $tax => $r ) {
					$label = isset( $attrs[ $tax ] ) ? $attrs[ $tax ] : $tax;
					$n     = 'crt_roles[' . $tax . ']';
					echo '<tr>';
					echo '<td class="crt-handle" aria-hidden="true">⋮⋮<input type="hidden" name="crt_order[]" value="' . esc_attr( $tax ) . '" /></td>';
					echo '<td><input type="checkbox" name="' . esc_attr( $n ) . '[on]" value="1" ' . checked( ! empty( $r['on'] ), true, false ) . ' /></td>';
					echo '<td><strong>' . esc_html( $label ) . '</strong><br /><code>' . esc_html( $tax ) . '</code></td>';
					echo '<td><input type="text" name="' . esc_attr( $n ) . '[h_el]" value="' . esc_attr( $r['h_el'] ) . '" placeholder="' . esc_attr( $label ) . '" maxlength="80" /><input type="text" name="' . esc_attr( $n ) . '[h_en]" value="' . esc_attr( $r['h_en'] ) . '" maxlength="80" /></td>';
					echo '<td><input type="text" name="' . esc_attr( $n ) . '[l_el]" value="' . esc_attr( $r['l_el'] ) . '" placeholder="' . esc_attr( $label ) . '" maxlength="80" /><input type="text" name="' . esc_attr( $n ) . '[l_en]" value="' . esc_attr( $r['l_en'] ) . '" maxlength="80" /></td>';
					echo '<td><select name="' . esc_attr( $n ) . '[schema]">';
					foreach ( self::props() as $k => $pl ) {
						echo '<option value="' . esc_attr( $k ) . '" ' . selected( $r['schema'], $k, false ) . '>' . esc_html( $pl ) . '</option>';
					}
					echo '</select></td>';
					echo '<td><input type="checkbox" name="' . esc_attr( $n ) . '[box]" value="1" ' . checked( ! empty( $r['box'] ), true, false ) . ' /></td>';
					echo '</tr>';
				}
				?>
				</tbody>
			</table>
			<p class="description crt-mt-8"><?php esc_html_e( 'Τίτλος ενότητας: π.χ. «Βιβλία ως συγγραφέας» στη σελίδα του δημιουργού. Όνομα ρόλου: π.χ. «Συγγραφέας», στο πλαίσιο του προϊόντος, στο ευρετήριο και στις ιδιότητες. Κενό = το όνομα του χαρακτηριστικού· κενό αγγλικό = το ελληνικό.', 'featured-creators' ); ?></p>
			<?php endif; ?>
		</div>

		<h2 class="crt-h2"><?php esc_html_e( 'Γλώσσα κειμένων', 'featured-creators' ); ?></h2>
		<div class="crt-zone">
			<select name="crt_lang">
				<?php
				foreach ( array(
					'auto' => __( 'Αυτόματα (γλώσσα του site)', 'featured-creators' ),
					'el'   => __( 'Ελληνικά', 'featured-creators' ),
					'en'   => __( 'English', 'featured-creators' ),
				) as $k => $label ) {
					echo '<option value="' . esc_attr( $k ) . '" ' . selected( $s['lang'], $k, false ) . '>' . esc_html( $label ) . '</option>';
				}
				?>
			</select>
		</div>
		<?php
		self::form_close();
	}

	/* ---------------------------------------------------------------------
	 * Tab: pages and products
	 * ------------------------------------------------------------------- */

	private static function render_page_tab(): void {
		$s     = CRT_Settings::get();
		$cats  = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$types = CRT_Settings::product_types();
		self::form_open( 'save_page' );
		?>
		<h2 class="crt-h2"><?php esc_html_e( 'Σελίδα δημιουργού', 'featured-creators' ); ?></h2>
		<div class="crt-zone">
			<p class="crt-field"><span><?php esc_html_e( 'Βάση διεύθυνσης', 'featured-creators' ); ?></span>
				<code><?php echo esc_html( home_url( '/' ) ); ?></code><input type="text" name="crt_base" value="<?php echo esc_attr( $s['base'] ); ?>" maxlength="60" /><code>/{slug}/</code></p>
			<p class="crt-field"><span><?php esc_html_e( 'Κενό πάνω από τη σελίδα', 'featured-creators' ); ?></span>
				<input type="text" class="crt-num" name="crt_top" value="<?php echo esc_attr( $s['top'] ); ?>" maxlength="10" />
				<span class="crt-hint"><?php esc_html_e( 'π.χ. 2.5em, 40px, 0 — ώστε η σελίδα να μην κολλάει στο header του θέματος.', 'featured-creators' ); ?></span></p>
			<p class="crt-field"><span><?php esc_html_e( 'Στήλες προϊόντων', 'featured-creators' ); ?></span>
				<input type="number" class="crt-num" name="crt_columns" value="<?php echo (int) $s['columns']; ?>" min="1" max="6" /></p>
			<?php self::checkbox( 'crt_formats', $s['formats'], __( 'Μία κάρτα ανά έργο όταν είναι ενεργό το Product Formats (οι μορφές του ίδιου έργου δεν επαναλαμβάνονται)', 'featured-creators' ) ); ?>
			<p class="description"><?php esc_html_e( 'Εμφανίζονται μόνο δημοσιευμένα προϊόντα που φαίνονται στον κατάλογο, νεότερα πρώτα, μία ενότητα ανά ρόλο με τη σειρά των ρόλων. Τα εξαντλημένα κρύβονται αν το λέει η ρύθμιση του WooCommerce.', 'featured-creators' ); ?></p>
		</div>

		<h2 class="crt-h2"><?php esc_html_e( 'Πακέτα (bundles)', 'featured-creators' ); ?></h2>
		<div class="crt-zone">
			<p class="description"><?php esc_html_e( 'Τα προϊόντα αυτών των κατηγοριών (και των υποκατηγοριών τους) ή τύπων μπαίνουν σε χωριστή ομάδα «Πακέτα», μετά τους ρόλους.', 'featured-creators' ); ?></p>
			<span class="crt-label"><?php esc_html_e( 'Κατηγορίες', 'featured-creators' ); ?></span>
			<div class="crt-scroll crt-cats">
				<?php
				if ( is_array( $cats ) ) {
					foreach ( $cats as $c ) {
						echo '<label class="crt-field"><input type="checkbox" name="crt_bundle_cats[]" value="' . (int) $c->term_id . '" ' . checked( in_array( (int) $c->term_id, $s['bundle_cats'], true ), true, false ) . ' /> <span>' . esc_html( $c->name ) . ' <span class="crt-muted">(' . (int) $c->count . ')</span></span></label>';
					}
				}
				?>
			</div>
			<span class="crt-label"><?php esc_html_e( 'Τύποι προϊόντος', 'featured-creators' ); ?></span>
			<?php
			foreach ( $types as $k => $label ) {
				if ( 'simple' === $k || 'variable' === $k ) {
					continue;
				}
				echo '<label class="crt-inline"><input type="checkbox" name="crt_bundle_types[]" value="' . esc_attr( $k ) . '" ' . checked( in_array( $k, $s['bundle_types'], true ), true, false ) . ' /> ' . esc_html( $label ) . ' <code>' . esc_html( $k ) . '</code></label>';
			}
			?>
		</div>

		<h2 class="crt-h2"><?php esc_html_e( 'Στο προϊόν', 'featured-creators' ); ?></h2>
		<div class="crt-zone">
			<?php self::checkbox( 'crt_links', $s['links'], __( 'Τα ονόματα στα «Επιπλέον πληροφορίες» γίνονται σύνδεσμοι προς τη σελίδα του δημιουργού', 'featured-creators' ) ); ?>
			<?php self::checkbox( 'crt_box', $s['box'], __( 'Πλαίσιο δημιουργών (φωτογραφία, ρόλος, δύο γραμμές βιογραφικού, σύνδεσμος)', 'featured-creators' ) ); ?>
			<p class="crt-field"><span><?php esc_html_e( 'Θέση πλαισίου', 'featured-creators' ); ?></span>
				<select name="crt_box_pos">
					<option value="description" <?php selected( $s['box_pos'], 'description' ); ?>><?php esc_html_e( 'στο τέλος της καρτέλας «Περιγραφή»', 'featured-creators' ); ?></option>
					<option value="after" <?php selected( $s['box_pos'], 'after' ); ?>><?php esc_html_e( 'μετά τις καρτέλες', 'featured-creators' ); ?></option>
					<option value="none" <?php selected( $s['box_pos'], 'none' ); ?>><?php esc_html_e( 'μόνο με shortcode ή μπλοκ', 'featured-creators' ); ?></option>
				</select></p>
			<p class="crt-field"><span><?php esc_html_e( 'Μέγιστος αριθμός καρτών', 'featured-creators' ); ?></span>
				<input type="number" class="crt-num" name="crt_box_max" value="<?php echo (int) $s['box_max']; ?>" min="1" max="12" /></p>
			<p class="description"><?php esc_html_e( 'Στο πλαίσιο μπαίνουν μόνο οι ρόλοι με τικ «Στο πλαίσιο» (καρτέλα Ρόλοι). Χωρίς καρτέλα «Περιγραφή» (κενή περιγραφή), το πλαίσιο μπαίνει μετά τις καρτέλες.', 'featured-creators' ); ?></p>
		</div>

		<h2 class="crt-h2"><?php esc_html_e( 'Ευρετήριο', 'featured-creators' ); ?></h2>
		<div class="crt-zone">
			<p class="crt-field"><span><?php esc_html_e( 'Σελίδα ευρετηρίου', 'featured-creators' ); ?></span>
				<?php
				wp_dropdown_pages(
					array(
						'name'              => 'crt_index_page',
						'selected'          => (int) $s['index_page'],
						'show_option_none'  => '—',
						'option_none_value' => '0',
					)
				);
				?>
			</p>
			<p class="description"><?php esc_html_e( 'Βάλε στη σελίδα το shortcode [nox_creators] ή το μπλοκ «Δημιουργοί (Featured Creators)». Φίλτρο ανά ρόλο και αλφαβητική σειρά με το «όνομα ταξινόμησης».', 'featured-creators' ); ?></p>
		</div>
		<?php
		self::form_close();
	}

	/* ---------------------------------------------------------------------
	 * Tab: texts
	 * ------------------------------------------------------------------- */

	private static function render_texts(): void {
		$s    = CRT_Settings::get();
		$rows = array(
			't_bundles'    => __( 'Τίτλος ομάδας πακέτων', 'featured-creators' ),
			't_interviews' => __( 'Τίτλος λίστας συνεντεύξεων', 'featured-creators' ),
			't_videos'     => __( 'Τίτλος λίστας βίντεο', 'featured-creators' ),
			't_reviews'    => __( 'Τίτλος λίστας κριτικών', 'featured-creators' ),
			't_box'        => __( 'Τίτλος πλαισίου στο προϊόν', 'featured-creators' ),
			't_more'       => __( 'Σύνδεσμος «όλα τα έργα»', 'featured-creators' ),
			't_all'        => __( 'Φίλτρο ευρετηρίου «όλοι»', 'featured-creators' ),
			't_formats'    => __( 'Μορφές ενός έργου ({n} = πλήθος)', 'featured-creators' ),
		);
		self::form_open( 'save_texts' );
		echo '<h2 class="crt-h2">' . esc_html__( 'Κείμενα επισκέπτη', 'featured-creators' ) . '</h2><div class="crt-zone">';
		echo '<table class="widefat striped crt-table crt-texts"><thead><tr><th>' . esc_html__( 'Κείμενο', 'featured-creators' ) . '</th><th>' . esc_html__( 'Ελληνικά', 'featured-creators' ) . '</th><th>' . esc_html__( 'English', 'featured-creators' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $key => $label ) {
			$len = CRT_Settings::TEXTS[ $key ];
			echo '<tr><td>' . esc_html( $label ) . '</td>';
			foreach ( array( 'el', 'en' ) as $l ) {
				echo '<td><input type="text" name="crt_t[' . esc_attr( $key ) . '][' . esc_attr( $l ) . ']" value="' . esc_attr( $s[ $key ][ $l ] ) . '" maxlength="' . (int) $len . '" /></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description crt-mt-8">' . esc_html__( 'Κενό ελληνικό κείμενο = το αρχικό. Κενό αγγλικό = δείχνει το ελληνικό. Οι τίτλοι και τα ονόματα των ρόλων ρυθμίζονται στην καρτέλα Ρόλοι. Φίλτρο για κώδικα: crt_text.', 'featured-creators' ) . '</p></div>';
		self::form_close();
	}

	/* ---------------------------------------------------------------------
	 * Tab: SEO
	 * ------------------------------------------------------------------- */

	private static function render_seo(): void {
		$s    = CRT_Settings::get();
		$cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$rm   = defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
		self::form_open( 'save_seo' );
		?>
		<h2 class="crt-h2"><?php esc_html_e( 'Σελίδα δημιουργού', 'featured-creators' ); ?></h2>
		<div class="crt-zone">
			<?php self::checkbox( 'crt_schema', $s['schema'], __( 'Schema ProfilePage + Person (όνομα, φωτογραφία, περιγραφή, ιδιότητες, σύνδεσμοι ως sameAs)', 'featured-creators' ) ); ?>
			<p class="description"><?php echo esc_html( $rm ? __( 'Rank Math είναι ενεργό: τα στοιχεία μπαίνουν στο δικό του graph και το Article που θα έβαζε για τη σελίδα αφαιρείται. Τίτλος, περιγραφή και sitemap από το Rank Math.', 'featured-creators' ) : __( 'Χωρίς Rank Math: το plugin τυπώνει δικό του JSON-LD.', 'featured-creators' ) ); ?></p>
		</div>

		<h2 class="crt-h2"><?php esc_html_e( 'Προϊόντα', 'featured-creators' ); ?></h2>
		<div class="crt-zone">
			<?php self::checkbox( 'crt_schema_products', $s['schema_products'], __( 'Οι δημιουργοί στο schema του προϊόντος, ως author, illustrator κ.λπ. (η ιδιότητα κάθε ρόλου ορίζεται στην καρτέλα Ρόλοι)', 'featured-creators' ) ); ?>
			<p class="description"><?php esc_html_e( 'Το schema.org Product δεν έχει author. Γι\' αυτό ένα προϊόν δηλώνεται και ως έργο (Book, Audiobook, MusicAlbum, Movie) ανάλογα με την κατηγορία του· χωρίς αντιστοίχιση κατηγορίας, το schema του προϊόντος μένει όπως είναι. Μια ιδιότητα που δεν υπάρχει στον τύπο γίνεται contributor.', 'featured-creators' ); ?></p>
			<div class="crt-scroll crt-cats">
				<?php
				if ( is_array( $cats ) ) {
					foreach ( $cats as $c ) {
						$v = isset( $s['schema_types'][ (int) $c->term_id ] ) ? $s['schema_types'][ (int) $c->term_id ] : '';
						echo '<label class="crt-field"><span>' . esc_html( $c->name ) . '</span><select name="crt_types[' . (int) $c->term_id . ']"><option value="">—</option>';
						foreach ( CRT_Settings::TYPES as $t ) {
							echo '<option value="' . esc_attr( $t ) . '" ' . selected( $v, $t, false ) . '>' . esc_html( $t ) . '</option>';
						}
						echo '</select></label>';
					}
				}
				?>
			</div>
			<p class="description crt-mt-8"><?php esc_html_e( 'Οι υποκατηγορίες κληρονομούν τον τύπο της γονικής.', 'featured-creators' ); ?></p>
		</div>
		<?php
		self::form_close();
	}

	/* ---------------------------------------------------------------------
	 * Tab: coverage (read-only)
	 * ------------------------------------------------------------------- */

	private static function render_coverage(): void {
		global $wpdb;
		$taxes = CRT_Settings::role_taxes();
		if ( ! $taxes ) {
			echo '<div class="crt-zone"><p class="crt-warn">' . esc_html__( 'Δεν έχουν οριστεί ρόλοι.', 'featured-creators' ) . '</p></div>';
			return;
		}
		$in = implode( ',', array_fill( 0, count( $taxes ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above; admin-only, read-only.
		$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
		$without = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, p.post_title FROM {$wpdb->posts} p WHERE p.post_type = 'product' AND p.post_status = 'publish' AND NOT EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = p.ID AND tt.taxonomy IN ($in) ) ORDER BY p.post_title ASC", $taxes ) );
		// phpcs:enable
		$without = (array) $without;
		$with    = $total - count( $without );

		$persons  = CRT_Match::persons();
		$orphans  = array();
		foreach ( $persons as $slug => $p ) {
			if ( ! $p['owner'] && $p['products'] > 0 ) {
				$orphans[ $slug ] = $p;
			}
		}
		$empty = array();
		foreach ( CRT_Match::creator_ids() as $cid ) {
			if ( 'publish' === get_post_status( $cid ) && 0 === (int) get_post_meta( $cid, CRT_CPT::M_COUNT, true ) ) {
				$empty[] = $cid;
			}
		}
		$conf   = CRT_Match::conflicts();
		$labels = CRT_Settings::attribute_taxonomies();
		?>
		<h2 class="crt-h2"><?php esc_html_e( 'Προϊόντα', 'featured-creators' ); ?></h2>
		<div class="crt-zone">
			<p>
				<?php
				printf(
					/* translators: 1: products with roles, 2: published products */
					esc_html__( '%1$d από %2$d δημοσιευμένα προϊόντα έχουν τουλάχιστον έναν ρόλο.', 'featured-creators' ),
					(int) $with,
					(int) $total
				);
				?>
			</p>
			<?php if ( $without ) : ?>
				<details class="crt-details"><summary><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Προϊόντα χωρίς ρόλο (%d)', 'featured-creators' ), count( $without ) ) ); ?></summary>
					<table class="widefat crt-table"><tbody>
					<?php
					foreach ( array_slice( $without, 0, 200 ) as $p ) {
						echo '<tr><td><a href="' . esc_url( (string) get_edit_post_link( (int) $p->ID ) ) . '">' . esc_html( $p->post_title ) . '</a></td></tr>';
					}
					?>
					</tbody></table>
				</details>
			<?php endif; ?>
		</div>

		<h2 class="crt-h2"><?php esc_html_e( 'Πρόσωπα χωρίς δημιουργό', 'featured-creators' ); ?></h2>
		<div class="crt-zone">
			<?php if ( ! $orphans ) : ?>
				<p class="crt-muted"><?php esc_html_e( 'Κάθε πρόσωπο με προϊόντα έχει δημιουργό.', 'featured-creators' ); ?></p>
			<?php else : ?>
				<p><?php echo esc_html( sprintf( /* translators: %d: count */ __( '%d πρόσωπα έχουν προϊόντα αλλά όχι σελίδα.', 'featured-creators' ), count( $orphans ) ) ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=import' ) ); ?>"><?php esc_html_e( 'Εισαγωγή', 'featured-creators' ); ?> →</a></p>
			<?php endif; ?>
		</div>

		<h2 class="crt-h2"><?php esc_html_e( 'Δημοσιευμένοι δημιουργοί χωρίς προϊόντα', 'featured-creators' ); ?></h2>
		<div class="crt-zone">
			<?php if ( ! $empty ) : ?>
				<p class="crt-muted"><?php esc_html_e( 'Κανένας.', 'featured-creators' ); ?></p>
			<?php else : ?>
				<p>
				<?php
				$links = array();
				foreach ( $empty as $cid ) {
					$links[] = '<a href="' . esc_url( (string) get_edit_post_link( $cid ) ) . '">' . esc_html( get_the_title( $cid ) ) . '</a>';
				}
				echo implode( ' · ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
				?>
				</p>
				<p class="description"><?php esc_html_e( 'Συνήθως λείπει το σωστό κλειδί ή κάποιο ψευδώνυμο στο «Ταίριασμα με χαρακτηριστικά».', 'featured-creators' ); ?></p>
			<?php endif; ?>
		</div>

		<h2 class="crt-h2"><?php esc_html_e( 'Συγκρούσεις', 'featured-creators' ); ?></h2>
		<div class="crt-zone">
			<?php if ( ! $conf ) : ?>
				<p class="crt-muted"><?php esc_html_e( 'Καμία.', 'featured-creators' ); ?></p>
			<?php else : ?>
				<table class="widefat crt-table"><tbody>
				<?php
				foreach ( $conf as $tt => $cids ) {
					$t = get_term_by( 'term_taxonomy_id', (int) $tt );
					if ( ! $t instanceof WP_Term ) {
						continue;
					}
					$links = array();
					foreach ( (array) $cids as $cid ) {
						$links[] = '<a href="' . esc_url( (string) get_edit_post_link( (int) $cid ) ) . '">' . esc_html( get_the_title( (int) $cid ) ) . '</a>';
					}
					echo '<tr><td>' . esc_html( $t->name ) . ' · ' . esc_html( isset( $labels[ $t->taxonomy ] ) ? $labels[ $t->taxonomy ] : $t->taxonomy ) . '</td><td>' . implode( ', ', $links ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
				}
				?>
				</tbody></table>
				<p class="description"><?php esc_html_e( 'Ένα term σε σύγκρουση δεν ανήκει σε κανέναν. Λύση: εξαίρεση στον έναν δημιουργό ή «επιπλέον term» στον άλλο.', 'featured-creators' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: import
	 * ------------------------------------------------------------------- */

	private static function render_import(): void {
		if ( ! CRT_Settings::role_taxes() ) {
			echo '<div class="crt-zone"><p class="crt-warn">' . esc_html__( 'Δεν έχουν οριστεί ρόλοι.', 'featured-creators' ) . '</p></div>';
			return;
		}
		$persons  = CRT_Match::persons();
		$existing = CRT_Import::existing_keys();
		$labels   = CRT_Settings::attribute_taxonomies();
		?>
		<h2 class="crt-h2"><?php esc_html_e( 'Πρόχειροι δημιουργοί από τα terms', 'featured-creators' ); ?></h2>
		<div class="crt-zone" data-crt-import>
			<p class="description"><?php esc_html_e( 'Ένα πρόσωπο ανά slug, από όλους τους ρόλους. Οι επιλεγμένοι γίνονται πρόχειροι δημιουργοί με το όνομα και το slug του term· τα στοιχεία, τη φωτογραφία και τη δημοσίευση τα κάνεις εσύ. Όσοι έχουν ήδη δημιουργό δεν ξαναδημιουργούνται.', 'featured-creators' ); ?></p>
			<p><label class="crt-inline"><input type="checkbox" data-crt-all /> <?php esc_html_e( 'Όλα', 'featured-creators' ); ?></label></p>
			<table class="widefat crt-table">
				<thead><tr>
					<th></th>
					<th><?php esc_html_e( 'Όνομα', 'featured-creators' ); ?></th>
					<th><?php esc_html_e( 'Slug', 'featured-creators' ); ?></th>
					<th><?php esc_html_e( 'Ρόλοι', 'featured-creators' ); ?></th>
					<th><?php esc_html_e( 'Προϊόντα', 'featured-creators' ); ?></th>
					<th><?php esc_html_e( 'Δημιουργός', 'featured-creators' ); ?></th>
				</tr></thead>
				<tbody>
				<?php
				if ( ! $persons ) {
					echo '<tr><td colspan="6" class="crt-empty">' . esc_html__( 'Δεν υπάρχουν terms στους ρόλους.', 'featured-creators' ) . '</td></tr>';
				}
				foreach ( $persons as $slug => $p ) {
					$cid = $p['owner'] ? (int) $p['owner'] : ( isset( $existing[ $slug ] ) ? (int) $existing[ $slug ] : 0 );
					$r   = array();
					foreach ( array_unique( $p['taxes'] ) as $tax ) {
						$r[] = isset( $labels[ $tax ] ) ? $labels[ $tax ] : $tax;
					}
					echo '<tr>';
					echo '<td>' . ( $cid ? '' : '<input type="checkbox" data-crt-slug="' . esc_attr( (string) $slug ) . '" ' . checked( $p['products'] > 0, true, false ) . ' />' ) . '</td>';
					echo '<td>' . esc_html( $p['name'] ) . '</td><td><code>' . esc_html( (string) $slug ) . '</code></td>';
					echo '<td>' . esc_html( implode( ', ', $r ) ) . '</td><td>' . (int) $p['products'] . '</td>';
					echo '<td>' . ( $cid ? '<a href="' . esc_url( (string) get_edit_post_link( $cid ) ) . '">' . esc_html( get_the_title( $cid ) ) . '</a> <span class="crt-muted">(' . esc_html( (string) get_post_status( $cid ) ) . ')</span>' : '—' ) . '</td>';
					echo '</tr>';
				}
				?>
				</tbody>
			</table>
			<p class="crt-submit"><button type="button" class="button button-primary" data-crt-run><?php esc_html_e( 'Δημιουργία πρόχειρων δημιουργών', 'featured-creators' ); ?></button></p>
			<div class="crt-progress" hidden data-crt-bar><span></span></div>
			<p class="crt-muted" data-crt-msg></p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: tools
	 * ------------------------------------------------------------------- */

	private static function render_tools(): void {
		$s = CRT_Settings::get();
		?>
		<h2 class="crt-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'featured-creators' ); ?></h2>
		<div class="crt-zone">
			<form method="post" enctype="multipart/form-data" class="crt-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'crt_backup_nonce' ); ?>
				<input type="file" name="crt_import_file" accept=".json,application/json" />
				<button type="submit" name="crt_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'featured-creators' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=tools&crt_export=1' ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'featured-creators' ); ?></a>
			<p class="description crt-mt-8"><?php esc_html_e( 'Μόνο οι ρυθμίσεις. Οι δημιουργοί εξάγονται με το Εργαλεία → Εξαγωγή του WordPress («Δημιουργοί»). Κατηγορίες και σελίδες δείχνουν σε IDs: σε άλλο site ίσως χρειαστεί να τις ξαναδείς.', 'featured-creators' ); ?></p>
		</div>

		<h2 class="crt-h2"><?php esc_html_e( 'Shortcodes, μπλοκ και φίλτρα', 'featured-creators' ); ?></h2>
		<div class="crt-zone">
			<table class="widefat striped crt-table">
				<tbody>
					<tr><td><code>[nox_creators]</code> · <code>[nox_creators role="pa_…"]</code></td><td><?php esc_html_e( 'Το ευρετήριο των δημιουργών, προαιρετικά με προεπιλεγμένο ρόλο.', 'featured-creators' ); ?></td></tr>
					<tr><td><code>[nox_creator_box]</code> · <code>[nox_creator_box id="123"]</code></td><td><?php esc_html_e( 'Το πλαίσιο δημιουργών του τρέχοντος ή ενός συγκεκριμένου προϊόντος.', 'featured-creators' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Μπλοκ', 'featured-creators' ); ?></td><td><?php esc_html_e( '«Δημιουργοί», «Πλαίσιο δημιουργών» και «Σελίδα δημιουργού» (για block themes).', 'featured-creators' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Φίλτρα και actions', 'featured-creators' ); ?></td><td><code>crt_text</code> · <code>crt_creator_changed</code> · <code>crt_get_creators()</code></td></tr>
				</tbody>
			</table>
		</div>

		<form method="post">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="crt_action" value="save_tools" />
			<h2 class="crt-h2"><?php esc_html_e( 'Απεγκατάσταση', 'featured-creators' ); ?></h2>
			<div class="crt-zone">
				<?php self::checkbox( 'crt_delete_posts', $s['delete_posts'], __( 'Διαγραφή των δημιουργών (σελίδες, βιογραφικά, λίστες) όταν διαγραφεί το plugin', 'featured-creators' ) ); ?>
				<p class="description"><?php esc_html_e( 'Κλειστό: οι δημιουργοί μένουν στη βάση μετά το «Διαγραφή» και ξαναεμφανίζονται αν εγκαταστήσεις πάλι το plugin. Οι ρυθμίσεις φεύγουν πάντα. Οι φωτογραφίες μένουν πάντα στη βιβλιοθήκη πολυμέσων.', 'featured-creators' ); ?></p>
			</div>
			<p class="crt-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'featured-creators' ); ?></button></p>
		</form>
		<?php
	}

	/* =====================================================================
	 * POST routes (admin_init, PRG)
	 * =================================================================== */

	public static function route(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}

		if ( isset( $_POST['crt_import'] ) ) {
			if ( ! isset( $_POST['crt_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['crt_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'featured-creators' ) );
			}
			self::redirect( 'tools', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['crt_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'featured-creators' ) );
		}

		$raw  = CRT_Settings::get();
		$tab  = 'roles';
		switch ( sanitize_key( wp_unslash( $_POST['crt_action'] ) ) ) {
			case 'save_roles':
				$raw['mode']  = sanitize_key( self::post( 'crt_mode' ) );
				$raw['lang']  = sanitize_key( self::post( 'crt_lang' ) );
				$raw['roles'] = self::posted_roles();
				break;
			case 'save_page':
				$tab                 = 'page';
				$raw['base']         = self::post( 'crt_base' );
				$raw['top']          = self::post( 'crt_top' );
				$raw['columns']      = (int) self::post( 'crt_columns' );
				$raw['formats']      = '1' === self::post( 'crt_formats' );
				$raw['bundle_cats']  = array_map( 'intval', self::post_array( 'crt_bundle_cats' ) );
				$raw['bundle_types'] = array_map( 'strval', array_filter( self::post_array( 'crt_bundle_types' ), 'is_string' ) );
				$raw['links']        = '1' === self::post( 'crt_links' );
				$raw['box']          = '1' === self::post( 'crt_box' );
				$raw['box_pos']      = sanitize_key( self::post( 'crt_box_pos' ) );
				$raw['box_max']      = (int) self::post( 'crt_box_max' );
				$raw['index_page']   = (int) self::post( 'crt_index_page' );
				break;
			case 'save_texts':
				$tab = 'texts';
				$t   = self::post_array( 'crt_t' );
				foreach ( array_keys( CRT_Settings::TEXTS ) as $k ) {
					if ( isset( $t[ $k ] ) && is_array( $t[ $k ] ) ) {
						$raw[ $k ] = array(
							'el' => isset( $t[ $k ]['el'] ) && is_string( $t[ $k ]['el'] ) ? $t[ $k ]['el'] : '',
							'en' => isset( $t[ $k ]['en'] ) && is_string( $t[ $k ]['en'] ) ? $t[ $k ]['en'] : '',
						);
					}
				}
				break;
			case 'save_seo':
				$tab                    = 'seo';
				$raw['schema']          = '1' === self::post( 'crt_schema' );
				$raw['schema_products'] = '1' === self::post( 'crt_schema_products' );
				$raw['schema_types']    = array();
				foreach ( self::post_array( 'crt_types' ) as $id => $type ) {
					if ( is_string( $type ) && '' !== $type ) {
						$raw['schema_types'][ (int) $id ] = $type;
					}
				}
				break;
			case 'save_tools':
				$tab                 = 'tools';
				$raw['delete_posts'] = '1' === self::post( 'crt_delete_posts' );
				break;
			default:
				self::redirect( 'roles', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'featured-creators' ) ) ) );
		}

		$old  = CRT_Settings::get();
		$s    = CRT_Settings::save( $raw );
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'featured-creators' ) ) );

		if ( 'roles' === $tab ) {
			if ( array_column( $old['roles'], 'tax' ) !== array_column( $s['roles'], 'tax' ) || $old['mode'] !== $s['mode'] ) {
				CRT_Match::recompute();
			}
			if ( ! $s['roles'] ) {
				$msgs[] = self::msg( 'warning', __( 'Κανένας ρόλος: οι δημιουργοί δεν θα έχουν προϊόντα.', 'featured-creators' ) );
			}
		}
		if ( 'page' === $tab ) {
			if ( '' !== trim( self::post( 'crt_top' ) ) && strtolower( trim( self::post( 'crt_top' ) ) ) !== $s['top'] ) {
				$msgs[] = self::msg( 'warning', __( 'Το κενό θέλει μορφή όπως 2.5em, 40px ή 0: κρατήθηκε η προεπιλογή.', 'featured-creators' ) );
			}
			if ( sanitize_title( self::post( 'crt_base' ) ) !== $s['base'] ) {
				$msgs[] = self::msg( 'warning', __( 'Η βάση διεύθυνσης δεν επιτρέπεται: κρατήθηκε η προεπιλογή.', 'featured-creators' ) );
			}
			CRT_Match::refresh_stats();
		}
		if ( 'seo' === $tab && $s['schema_products'] && ! $s['schema_types'] ) {
			$msgs[] = self::msg( 'warning', __( 'Χωρίς αντιστοίχιση κατηγορίας σε τύπο έργου, οι δημιουργοί δεν μπαίνουν στο schema των προϊόντων.', 'featured-creators' ) );
		}
		self::redirect( $tab, $msgs );
	}

	/** Roles from the posted table, in the posted order, only the ticked ones. */
	private static function posted_roles(): array {
		$rows  = self::post_array( 'crt_roles' );
		$order = self::post_array( 'crt_order' );
		$out   = array();
		foreach ( $order as $tax ) {
			if ( ! is_string( $tax ) || ! isset( $rows[ $tax ] ) || ! is_array( $rows[ $tax ] ) || empty( $rows[ $tax ]['on'] ) ) {
				continue;
			}
			$r     = $rows[ $tax ];
			$out[] = array(
				'tax'    => $tax,
				'h_el'   => isset( $r['h_el'] ) && is_string( $r['h_el'] ) ? $r['h_el'] : '',
				'h_en'   => isset( $r['h_en'] ) && is_string( $r['h_en'] ) ? $r['h_en'] : '',
				'l_el'   => isset( $r['l_el'] ) && is_string( $r['l_el'] ) ? $r['l_el'] : '',
				'l_en'   => isset( $r['l_en'] ) && is_string( $r['l_en'] ) ? $r['l_en'] : '',
				'schema' => isset( $r['schema'] ) && is_string( $r['schema'] ) ? $r['schema'] : 'contributor',
				'box'    => ! empty( $r['box'] ),
			);
		}
		return $out;
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		set_transient( 'crt_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab ) );
		exit;
	}

	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	private static function post_array( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(), sanitized by CRT_Settings::sanitize().
		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
	}

	/* =====================================================================
	 * Backup
	 * =================================================================== */

	public static function route_export(): void {
		if ( self::SLUG !== self::current_page() || '' === self::q( 'crt_export' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'featured-creators' ) );
		}
		$state = array(
			'plugin'  => 'featured-creators',
			'version' => CRT_VERSION,
			'options' => array(
				CRT_Settings::OPT => CRT_Settings::get(),
			),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="featured-creators-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	/** Strict import through CRT_Settings::sanitize(); an invalid file changes nothing. */
	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['crt_import_file']['error'] ) ? (int) $_FILES['crt_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['crt_import_file']['size'] ) ? (int) $_FILES['crt_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'featured-creators' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['crt_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['crt_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'featured-creators' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['crt_import_file']['tmp_name'] ), true );
		// phpcs:enable
		if ( ! is_array( $state ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'featured-creators' ) ) );
		}
		$blob = isset( $state['options'][ CRT_Settings::OPT ] ) ? $state['options'][ CRT_Settings::OPT ] : null;
		if ( is_string( $blob ) ) {
			$blob = json_decode( $blob, true );
		}
		if ( ! is_array( $blob ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Featured Creators).', 'featured-creators' ) ) );
		}
		CRT_Settings::save( $blob );
		CRT_Match::recompute();
		return array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'featured-creators' ) ) );
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'crt_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'crt_aui_msg_' . get_current_user_id() );
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
		<p class="crt-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'featured-creators' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="crt-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="crt-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'featured-creators' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="crt-footer-cta">
			<a class="crt-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'featured-creators' ); ?>
			</a>
			<a class="crt-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'featured-creators' ); ?>
			</a>
		</p>
		<?php
	}
}

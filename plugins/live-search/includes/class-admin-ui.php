<?php
/**
 * LVS_Admin_UI — submenu «Αναζήτηση» under the shared Noxpress menu.
 *
 * Tabs: Ρυθμίσεις (mode, what is searched, what a suggestion shows,
 * engine, synonyms, visitor texts, statistics), Ευρετήριο (state, rebuild
 * with progress, test a phrase), Στατιστικά (phrases, phrases that found
 * nothing → synonym), Εισαγωγή / Εξαγωγή (FiboSearch, JSON backup).
 * Plain POST with nonce + capability + PRG (Bible §11); the rebuild runs
 * through AJAX in batches (check_ajax_referer + current_user_can).
 */

defined( 'ABSPATH' ) || exit;

final class LVS_Admin_UI {

	const SLUG      = 'lvs-settings';
	const SLUG_MENU = 'noxpress';
	const NONCE     = 'lvs_admin';
	const MAX_JSON  = 262144;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 400 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_lvs_rebuild', array( __CLASS__, 'ajax_rebuild' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LVS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	private static function tabs(): array {
		return array(
			'settings' => __( 'Ρυθμίσεις', 'live-search' ),
			'index'    => __( 'Ευρετήριο', 'live-search' ),
			'stats'    => __( 'Στατιστικά', 'live-search' ),
			'import'   => __( 'Εισαγωγή / Εξαγωγή', 'live-search' ),
		);
	}

	public static function admin_menu(): void {
		// The shared top-level "Noxpress" is created by Noxpress Core
		// (priority 5, Bible §16): a submenu only here.
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Live Search', 'live-search' ),
			__( 'Αναζήτηση', 'live-search' ),
			Live_Search::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'live-search' ) . '</a>' );
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

	private static function url( string $tab, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'lvs-admin', LVS_URL . 'assets/admin.css', array(), LVS_VERSION );
		wp_enqueue_script( 'lvs-admin', LVS_URL . 'assets/admin.js', array(), LVS_VERSION, true );
		wp_localize_script(
			'lvs-admin',
			'lvsAdmin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'lvs_rebuild' ),
				't'     => array(
					'run'  => __( 'Χτίσιμο: %1$d από %2$d προϊόντα…', 'live-search' ),
					'done' => __( 'Έτοιμο. Το ευρετήριο έχει %d προϊόντα.', 'live-search' ),
					'fail' => __( 'Το χτίσιμο σταμάτησε. Δοκίμασε ξανά· ό,τι έγινε μένει.', 'live-search' ),
				),
			)
		);
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Live_Search::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'live-search' ) );
		}
		$tab = self::current_tab();
		$s   = LVS_Settings::get();
		?>
		<div class="wrap lvs-wrap">
			<h1><?php esc_html_e( 'Live Search', 'live-search' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Αναζήτηση προϊόντων με προτάσεις καθώς γράφει ο επισκέπτης: τίτλος, ISBN, συγγραφείς και ό,τι άλλο διαλέξεις, χωρίς τόνους, με greeklish και με ανοχή σε μικρά λάθη. Οι φόρμες αναζήτησης του θέματος μένουν ίδιες· απλώς βρίσκουν περισσότερα.', 'live-search' ); ?></p>
			<?php
			self::print_notices( self::take_msgs() );
			if ( Live_Search::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το LVS_DISABLE είναι ενεργό στο wp-config.php: οι προτάσεις, η σελίδα αποτελεσμάτων και τα routes είναι κλειστά.', 'live-search' ) . '</p></div>';
			}
			if ( 'off' === $s['mode'] ) {
				echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Το plugin είναι κλειστό: οι επισκέπτες βλέπουν την αναζήτηση του θέματος όπως πριν. Διάλεξε «Δοκιμή» για να το δεις μόνο εσύ.', 'live-search' ) . '</p></div>';
			} elseif ( 'test' === $s['mode'] ) {
				echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Λειτουργία δοκιμής: οι προτάσεις και η νέα σελίδα αποτελεσμάτων φαίνονται μόνο σε διαχειριστές του καταστήματος (εσένα).', 'live-search' ) . '</p></div>';
			}
			if ( self::fibo_active() && 'live' === $s['mode'] ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το FiboSearch είναι ακόμα ενεργό: οι φόρμες του δεν είναι φόρμες του θέματος, και οι δύο αναζητήσεις μπορεί να εμφανίζονται μαζί. Απενεργοποίησέ το όταν είσαι σίγουρος.', 'live-search' ) . '</p></div>';
			}
			?>
			<nav class="nav-tab-wrapper lvs-tabs">
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
				case 'index':
					self::render_index();
					break;
				case 'stats':
					self::render_stats();
					break;
				case 'import':
					self::render_import();
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
			'<label class="lvs-check"><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label )
		);
	}

	private static function radio( string $name, string $value, string $current, string $label ): void {
		printf(
			'<label class="lvs-radio"><input type="radio" name="%1$s" value="%2$s"%3$s> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $current, $value, false ),
			esc_html( $label )
		);
	}

	private static function form_head( string $action ): void {
		wp_nonce_field( self::NONCE, 'lvs_nonce' );
		echo '<input type="hidden" name="lvs_action" value="' . esc_attr( $action ) . '">';
	}

	/** Attribute taxonomies: name => label. */
	private static function attribute_taxes(): array {
		$out = array();
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $a ) {
				$out[ 'pa_' . $a->attribute_name ] = (string) $a->attribute_label;
			}
		}
		return $out;
	}

	private static function render_settings(): void {
		$s     = LVS_Settings::get();
		$attrs = self::attribute_taxes();
		?>
		<form method="post" class="lvs-form">
			<?php self::form_head( 'save' ); ?>

			<div class="lvs-zone">
				<h2 class="lvs-h2"><?php esc_html_e( 'Κατάσταση', 'live-search' ); ?></h2>
				<p>
					<?php
					self::radio( 'mode', 'off', $s['mode'], __( 'Κλειστό', 'live-search' ) );
					self::radio( 'mode', 'test', $s['mode'], __( 'Δοκιμή (μόνο διαχειριστές)', 'live-search' ) );
					self::radio( 'mode', 'live', $s['mode'], __( 'Ενεργό για όλους', 'live-search' ) );
					?>
				</p>
				<p>
					<?php
					self::radio( 'forms', 'all', $s['forms'], __( 'Κάθε φόρμα αναζήτησης του site ψάχνει προϊόντα', 'live-search' ) );
					self::radio( 'forms', 'product', $s['forms'], __( 'Μόνο οι φόρμες αναζήτησης προϊόντων', 'live-search' ) );
					?>
				</p>
			</div>

			<div class="lvs-zone">
				<h2 class="lvs-h2"><?php esc_html_e( 'Τι ψάχνει', 'live-search' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Ο τίτλος πάντα. Μετά από αλλαγή εδώ το ευρετήριο ξαναχτίζεται στο παρασκήνιο.', 'live-search' ); ?></p>
				<p>
					<?php
					self::checkbox( 'codes', $s['codes'], __( 'ISBN, GTIN και SKU (και η άλλη μορφή του ISBN, 10 ↔ 13 ψηφία)', 'live-search' ) );
					self::checkbox( 'cats', $s['cats'], __( 'Κατηγορίες', 'live-search' ) );
					self::checkbox( 'tags', $s['tags'], __( 'Ετικέτες', 'live-search' ) );
					self::checkbox( 'brands', $s['brands'], __( 'Brands / εκδότες', 'live-search' ) );
					self::checkbox( 'excerpt', $s['excerpt'], __( 'Σύντομη περιγραφή (μόνο στη σελίδα αποτελεσμάτων)', 'live-search' ) );
					?>
				</p>
				<?php if ( $attrs ) : ?>
					<table class="widefat striped lvs-table">
						<thead><tr><th><?php esc_html_e( 'Χαρακτηριστικό', 'live-search' ); ?></th><th><?php esc_html_e( 'Πρόσωπα (π.χ. συγγραφείς): ψάχνονται με μεγάλο βάρος και φαίνονται κάτω από τον τίτλο', 'live-search' ); ?></th><th><?php esc_html_e( 'Ψάχνεται', 'live-search' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $attrs as $tax => $label ) : ?>
							<tr>
								<td><?php echo esc_html( $label ); ?> <code><?php echo esc_html( $tax ); ?></code></td>
								<td><input type="checkbox" name="people[]" value="<?php echo esc_attr( $tax ); ?>"<?php checked( in_array( $tax, $s['people'], true ) ); ?>></td>
								<td><input type="checkbox" name="attrs[]" value="<?php echo esc_attr( $tax ); ?>"<?php checked( in_array( $tax, $s['attrs'], true ) ); ?>></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="lvs-muted"><?php esc_html_e( 'Το κατάστημα δεν έχει καθολικά χαρακτηριστικά προϊόντων.', 'live-search' ); ?></p>
				<?php endif; ?>
				<h3 class="lvs-h3"><?php esc_html_e( 'Συνώνυμα', 'live-search' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Μία ομάδα ανά γραμμή, π.χ. «ηχοβιβλίο = audiobook, ακουστικό βιβλίο». Κάθε λέξη βρίσκει και τις άλλες.', 'live-search' ); ?></p>
				<textarea name="synonyms" rows="5" class="large-text code"><?php echo esc_textarea( $s['synonyms'] ); ?></textarea>
			</div>

			<div class="lvs-zone">
				<h2 class="lvs-h2"><?php esc_html_e( 'Τι δείχνει μια πρόταση', 'live-search' ); ?></h2>
				<p>
					<label class="lvs-inline"><?php esc_html_e( 'Πλήθος προτάσεων', 'live-search' ); ?> <input type="number" name="limit" min="3" max="20" value="<?php echo esc_attr( (string) $s['limit'] ); ?>" class="small-text"></label>
					<label class="lvs-inline"><?php esc_html_e( 'Γράμματα πριν ξεκινήσουν', 'live-search' ); ?> <input type="number" name="min_chars" min="1" max="5" value="<?php echo esc_attr( (string) $s['min_chars'] ); ?>" class="small-text"></label>
				</p>
				<p>
					<?php
					self::checkbox( 'image', $s['image'], __( 'Μικρή εικόνα', 'live-search' ) );
					self::checkbox( 'price', $s['price'], __( 'Τιμή (οι λογαριασμοί χονδρικής δεν βλέπουν τιμή)', 'live-search' ) );
					self::checkbox( 'byline', $s['byline'], __( 'Πρόσωπα κάτω από τον τίτλο', 'live-search' ) );
					self::checkbox( 'group', $s['group'], __( 'Ένα έργο σε πολλές μορφές ως μία πρόταση (Product Formats)', 'live-search' ) );
					self::checkbox( 'creators', $s['creators'], __( 'Ενότητα «Δημιουργοί» (Featured Creators)', 'live-search' ) );
					self::checkbox( 'isbn_direct', $s['isbn_direct'], __( 'Ακριβές ISBN ενός προϊόντος: κατευθείαν στη σελίδα του', 'live-search' ) );
					?>
				</p>
				<p>
					<strong><?php esc_html_e( 'Εξαντλημένα:', 'live-search' ); ?></strong>
					<?php
					self::radio( 'oos', 'last', $s['oos'], __( 'στο τέλος', 'live-search' ) );
					self::radio( 'oos', 'normal', $s['oos'], __( 'κανονικά', 'live-search' ) );
					self::radio( 'oos', 'hide', $s['oos'], __( 'καθόλου', 'live-search' ) );
					?>
				</p>
			</div>

			<div class="lvs-zone">
				<h2 class="lvs-h2"><?php esc_html_e( 'Από πού έρχονται οι προτάσεις', 'live-search' ); ?></h2>
				<p>
					<?php
					self::radio( 'engine', 'auto', $s['engine'], __( 'Αυτόματα', 'live-search' ) );
					self::radio( 'engine', 'file', $s['engine'], __( 'Αρχείο στον browser', 'live-search' ) );
					self::radio( 'engine', 'server', $s['engine'], __( 'Server (REST)', 'live-search' ) );
					?>
				</p>
				<p class="description"><?php esc_html_e( 'Αρχείο: ένα στατικό αρχείο με τα δημόσια στοιχεία των προϊόντων κατεβαίνει μία φορά, όταν ο επισκέπτης πατήσει στο πεδίο αναζήτησης, και κάθε πλήκτρο απαντά αμέσως, χωρίς αίτημα στον server. Server: ένα αίτημα ανά πληκτρολόγηση, για μεγάλους καταλόγους.', 'live-search' ); ?></p>
				<p><label class="lvs-inline"><?php esc_html_e( 'Αυτόματα: server πάνω από', 'live-search' ); ?> <input type="number" name="threshold" min="500" max="50000" step="100" value="<?php echo esc_attr( (string) $s['threshold'] ); ?>" class="small-text"> <?php esc_html_e( 'προϊόντα', 'live-search' ); ?></label></p>
			</div>

			<div class="lvs-zone">
				<h2 class="lvs-h2"><?php esc_html_e( 'Κείμενα για τον επισκέπτη', 'live-search' ); ?></h2>
				<p>
					<label class="lvs-inline"><?php esc_html_e( 'Γλώσσα', 'live-search' ); ?>
						<select name="lang">
							<option value="auto"<?php selected( $s['lang'], 'auto' ); ?>><?php esc_html_e( 'Η γλώσσα του site', 'live-search' ); ?></option>
							<option value="el"<?php selected( $s['lang'], 'el' ); ?>>Ελληνικά</option>
							<option value="en"<?php selected( $s['lang'], 'en' ); ?>>English</option>
						</select>
					</label>
				</p>
				<table class="widefat striped lvs-table">
					<thead><tr><th></th><th>Ελληνικά</th><th>English</th></tr></thead>
					<tbody>
					<?php
					$labels = array(
						'placeholder' => __( 'Μέσα στο πεδίο', 'live-search' ),
						'none'        => __( 'Χωρίς αποτέλεσμα', 'live-search' ),
						'all'         => __( 'Σύνδεσμος για όλα', 'live-search' ),
					);
					foreach ( $labels as $k => $label ) :
						?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td><input type="text" class="regular-text" name="texts[<?php echo esc_attr( $k ); ?>][el]" value="<?php echo esc_attr( $s['texts'][ $k ]['el'] ); ?>"></td>
							<td><input type="text" class="regular-text" name="texts[<?php echo esc_attr( $k ); ?>][en]" value="<?php echo esc_attr( $s['texts'][ $k ]['en'] ); ?>"></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="lvs-zone">
				<h2 class="lvs-h2"><?php esc_html_e( 'Στατιστικά', 'live-search' ); ?></h2>
				<?php self::checkbox( 'stats', $s['stats'], __( 'Κράτα τι ψάχνουν οι επισκέπτες, σε ιδιωτικό αρχείο: μόνο η φράση, πόσα βρήκε και η ώρα, χωρίς IP, browser ή χρήστη, για 90 ημέρες', 'live-search' ) ); ?>
			</div>

			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'live-search' ); ?></button></p>
		</form>
		<?php
	}

	private static function render_index(): void {
		$f     = LVS_File::state();
		$st    = LVS_Index::state();
		$q     = get_option( LVS_Index::OPT_Q, array() );
		$test  = self::q( 'test' );
		$fmt   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="lvs-zone">
			<h2 class="lvs-h2"><?php esc_html_e( 'Κατάσταση ευρετηρίου', 'live-search' ); ?></h2>
			<table class="widefat striped lvs-table lvs-kv">
				<tbody>
					<tr><th><?php esc_html_e( 'Προϊόντα στο ευρετήριο', 'live-search' ); ?></th><td><?php echo esc_html( number_format_i18n( LVS_Index::count_visible() ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Προτάσεις από', 'live-search' ); ?></th><td><?php echo esc_html( 'file' === LVS_Settings::engine() ? __( 'Αρχείο στον browser', 'live-search' ) : __( 'Server (REST)', 'live-search' ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Αρχείο', 'live-search' ); ?></th><td>
						<?php
						$url = LVS_File::current_url();
						if ( '' !== $url ) {
							printf(
								/* translators: 1: file name, 2: size, 3: products */
								esc_html__( '%1$s, %2$s, %3$d προϊόντα', 'live-search' ),
								'<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) $f['name'] ) . '</a>',
								esc_html( size_format( (int) ( $f['bytes'] ?? 0 ) ) ),
								(int) ( $f['count'] ?? 0 )
							);
						} else {
							esc_html_e( 'Δεν υπάρχει ακόμα.', 'live-search' );
						}
						?>
					</td></tr>
					<tr><th><?php esc_html_e( 'Τελευταίο πλήρες χτίσιμο', 'live-search' ); ?></th><td><?php echo ! empty( $st['built'] ) ? esc_html( wp_date( $fmt, (int) $st['built'] ) ) : '—'; ?></td></tr>
					<tr><th><?php esc_html_e( 'Σε αναμονή', 'live-search' ); ?></th><td><?php echo esc_html( number_format_i18n( is_array( $q ) ? count( $q ) : 0 ) ); ?></td></tr>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Το ευρετήριο ενημερώνεται μόνο του όταν αλλάζει ένα προϊόν, κάθε ώρα για ό,τι άλλαξε αλλιώς, και ολόκληρο κάθε βράδυ.', 'live-search' ); ?></p>
			<p>
				<button type="button" class="button button-primary" id="lvs-rebuild"><?php esc_html_e( 'Ξαναχτίσιμο τώρα', 'live-search' ); ?></button>
				<span id="lvs-rebuild-msg" class="lvs-muted" aria-live="polite"></span>
			</p>
			<div class="lvs-bar" hidden><span id="lvs-bar"></span></div>
		</div>

		<div class="lvs-zone">
			<h2 class="lvs-h2"><?php esc_html_e( 'Δοκιμή μιας φράσης', 'live-search' ); ?></h2>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="tab" value="index">
				<input type="search" name="test" value="<?php echo esc_attr( $test ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'π.χ. h eirini, 978-960-…, συγγραφέας', 'live-search' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Δοκιμή', 'live-search' ); ?></button>
			</form>
			<?php
			if ( '' !== $test ) {
				$r = LVS_Abilities::test(
					array(
						'q'     => $test,
						'limit' => 30,
					)
				);
				echo '<p class="lvs-muted">';
				printf(
					/* translators: 1: normalised, 2: phonetic, 3: found */
					esc_html__( 'Κανονικοποίηση: «%1$s» · φωνητικό κλειδί: «%2$s» · βρέθηκαν: %3$d', 'live-search' ),
					esc_html( $r['normalised'] ),
					esc_html( $r['phonetic'] ),
					(int) $r['found']
				);
				echo '</p>';
				if ( '' !== $r['opens'] ) {
					echo '<p>' . esc_html__( 'Ακριβές ISBN: το Enter ανοίγει κατευθείαν', 'live-search' ) . ' <a href="' . esc_url( $r['opens'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $r['opens'] ) . '</a></p>';
				}
				if ( $r['results'] ) {
					echo '<table class="widefat striped lvs-table"><thead><tr><th>#</th><th>' . esc_html__( 'Προϊόν', 'live-search' ) . '</th><th>' . esc_html__( 'Βαθμός', 'live-search' ) . '</th><th>' . esc_html__( 'Απόθεμα', 'live-search' ) . '</th></tr></thead><tbody>';
					foreach ( $r['results'] as $i => $x ) {
						echo '<tr><td>' . (int) ( $i + 1 ) . '</td><td><a href="' . esc_url( $x['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $x['title'] ) . '</a></td><td>' . (int) $x['score'] . '</td><td>' . ( $x['out_of_stock'] ? esc_html__( 'Εξαντλημένο', 'live-search' ) : '✓' ) . '</td></tr>';
					}
					echo '</tbody></table>';
				}
				if ( $r['creators'] ) {
					echo '<p>' . esc_html__( 'Δημιουργοί:', 'live-search' ) . ' ';
					foreach ( $r['creators'] as $c ) {
						echo '<a href="' . esc_url( $c['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $c['name'] ) . '</a> ';
					}
					echo '</p>';
				}
			}
			?>
		</div>
		<?php
	}

	private static function render_stats(): void {
		$days = (int) self::q( 'days' );
		$days = in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30;
		$r    = LVS_Stats::report( $days );
		$st   = LVS_Stats::state();
		if ( isset( $st['web'] ) && 'exposed' === $st['web'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Ο φάκελος των στατιστικών κατεβαίνει από τον browser (ο server αγνοεί το .htaccess, π.χ. nginx). Δεν έχει IP ή email, αλλά πρόσθεσε έναν κανόνα deny στον server ή κλείσε τα στατιστικά.', 'live-search' ) . '</p></div>';
		}
		if ( ! LVS_Settings::get()['stats'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Τα στατιστικά είναι κλειστά στις Ρυθμίσεις.', 'live-search' ) . '</p></div>';
		}
		$zero = array_values(
			array_filter(
				$r['rows'],
				static function ( $x ) {
					return 0 === $x['found'];
				}
			)
		);
		?>
		<div class="lvs-zone">
			<p>
				<?php
				foreach ( array( 7, 30, 90 ) as $d ) {
					/* translators: %d: days */
					$label = sprintf( __( '%d ημέρες', 'live-search' ), $d );
					printf( '<a class="button%1$s" href="%2$s">%3$s</a> ', $d === $days ? ' button-primary' : '', esc_url( self::url( 'stats', array( 'days' => $d ) ) ), esc_html( $label ) );
				}
				?>
			</p>
			<p class="lvs-muted">
				<?php
				printf(
					/* translators: 1: searches, 2: with no result */
					esc_html__( 'Αναζητήσεις: %1$s · χωρίς αποτέλεσμα: %2$s. Δεν μετράνε οι διαχειριστές, τα bots και η λειτουργία δοκιμής.', 'live-search' ),
					esc_html( number_format_i18n( $r['total'] ) ),
					esc_html( number_format_i18n( $r['zero'] ) )
				);
				?>
			</p>
		</div>

		<div class="lvs-zone">
			<h2 class="lvs-h2"><?php esc_html_e( 'Χωρίς αποτέλεσμα', 'live-search' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Λέξεις που ψάχνουν οι επισκέπτες και δεν βρίσκουν τίποτα. Αν εννοούν κάτι που υπάρχει, πρόσθεσε ένα συνώνυμο.', 'live-search' ); ?></p>
			<?php if ( $zero ) : ?>
				<table class="widefat striped lvs-table">
					<thead><tr><th><?php esc_html_e( 'Φράση', 'live-search' ); ?></th><th><?php esc_html_e( 'Φορές', 'live-search' ); ?></th><th><?php esc_html_e( 'Συνώνυμο', 'live-search' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $zero, 0, 100 ) as $x ) : ?>
						<tr>
							<td><?php echo esc_html( $x['q'] ); ?></td>
							<td><?php echo (int) $x['count']; ?></td>
							<td>
								<form method="post" class="lvs-inline-form">
									<?php self::form_head( 'synonym' ); ?>
									<input type="hidden" name="phrase" value="<?php echo esc_attr( $x['q'] ); ?>">
									<input type="text" name="target" class="regular-text" placeholder="<?php esc_attr_e( 'σημαίνει…', 'live-search' ); ?>">
									<button type="submit" class="button"><?php esc_html_e( 'Πρόσθεσε', 'live-search' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="lvs-muted"><?php esc_html_e( 'Καμία.', 'live-search' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="lvs-zone">
			<h2 class="lvs-h2"><?php esc_html_e( 'Πιο συχνές αναζητήσεις', 'live-search' ); ?></h2>
			<?php if ( $r['rows'] ) : ?>
				<table class="widefat striped lvs-table">
					<thead><tr><th><?php esc_html_e( 'Φράση', 'live-search' ); ?></th><th><?php esc_html_e( 'Φορές', 'live-search' ); ?></th><th><?php esc_html_e( 'Αποτελέσματα την τελευταία φορά', 'live-search' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $r['rows'], 0, 100 ) as $x ) : ?>
						<tr><td><a href="<?php echo esc_url( self::url( 'index', array( 'test' => $x['q'] ) ) ); ?>"><?php echo esc_html( $x['q'] ); ?></a></td><td><?php echo (int) $x['count']; ?></td><td><?php echo (int) $x['found']; ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="lvs-muted"><?php esc_html_e( 'Δεν υπάρχουν ακόμα αναζητήσεις.', 'live-search' ); ?></p>
			<?php endif; ?>
			<form method="post" data-lvs-confirm="<?php esc_attr_e( 'Να σβηστούν όλα τα στατιστικά;', 'live-search' ); ?>">
				<?php self::form_head( 'clear_stats' ); ?>
				<p><button type="submit" class="button"><?php esc_html_e( 'Διαγραφή στατιστικών', 'live-search' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	private static function fibo_active(): bool {
		return defined( 'DGWT_WCAS_VERSION' ) || class_exists( 'DgoraWcas\\Main' );
	}

	private static function render_import(): void {
		$fibo = get_option( 'dgwt_wcas_settings', null );
		?>
		<div class="lvs-zone">
			<h2 class="lvs-h2"><?php esc_html_e( 'Από το FiboSearch', 'live-search' ); ?></h2>
			<?php if ( is_array( $fibo ) ) : ?>
				<p class="description"><?php esc_html_e( 'Περνούν τα γράμματα πριν ξεκινήσουν οι προτάσεις, το πλήθος τους, το κείμενο μέσα στο πεδίο, τα κείμενα «κανένα αποτέλεσμα» και «όλα», η εικόνα, η τιμή, τα εξαντλημένα, το SKU / GTIN και τα συνώνυμα. Οι ρυθμίσεις του FiboSearch μόνο διαβάζονται.', 'live-search' ); ?></p>
				<form method="post">
					<?php self::form_head( 'import_fibo' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Εισαγωγή από το FiboSearch', 'live-search' ); ?></button>
				</form>
			<?php else : ?>
				<p class="lvs-muted"><?php esc_html_e( 'Δεν βρέθηκαν ρυθμίσεις του FiboSearch σε αυτό το site.', 'live-search' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="lvs-zone">
			<h2 class="lvs-h2"><?php esc_html_e( 'Αντίγραφο ρυθμίσεων', 'live-search' ); ?></h2>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( self::url( 'import', array( 'lvs_export' => 1 ) ), self::NONCE, 'lvs_nonce' ) ); ?>"><?php esc_html_e( 'Εξαγωγή JSON', 'live-search' ); ?></a></p>
			<form method="post" enctype="multipart/form-data">
				<?php self::form_head( 'import_json' ); ?>
				<input type="file" name="lvs_json" accept="application/json,.json">
				<button type="submit" class="button"><?php esc_html_e( 'Εισαγωγή JSON', 'live-search' ); ?></button>
			</form>
		</div>
		<?php
	}

	/* =====================================================================
	 * Actions (PRG)
	 * =================================================================== */

	public static function route(): void {
		if ( self::SLUG !== self::current_page() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['lvs_action'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing
			return;
		}
		if ( ! current_user_can( Live_Search::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'live-search' ) );
		}
		check_admin_referer( self::NONCE, 'lvs_nonce' );
		$action = sanitize_key( self::post( 'lvs_action' ) );
		switch ( $action ) {
			case 'save':
				self::do_save();
				break;
			case 'synonym':
				self::do_synonym();
				break;
			case 'clear_stats':
				$n = LVS_Stats::clear();
				/* translators: %d: files */
				self::redirect( 'stats', array( self::msg( 'success', sprintf( __( 'Σβήστηκαν %d αρχεία στατιστικών.', 'live-search' ), $n ) ) ) );
				break;
			case 'import_fibo':
				self::do_import_fibo();
				break;
			case 'import_json':
				self::do_import_json();
				break;
		}
		self::redirect( self::current_tab(), array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'live-search' ) ) ) );
	}

	/** The raw settings as posted (checkboxes absent = off). */
	private static function posted(): array {
		$raw = array();
		foreach ( array( 'mode', 'forms', 'oos', 'engine', 'lang', 'limit', 'min_chars', 'threshold', 'synonyms' ) as $k ) {
			$raw[ $k ] = self::post( $k );
		}
		foreach ( array( 'codes', 'cats', 'tags', 'brands', 'excerpt', 'image', 'price', 'byline', 'isbn_direct', 'group', 'creators', 'stats' ) as $k ) {
			$raw[ $k ] = '1' === self::post( $k );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- verified in route(); sanitized in LVS_Settings::sanitize().
		$raw['people'] = isset( $_POST['people'] ) && is_array( $_POST['people'] ) ? array_map( 'strval', wp_unslash( $_POST['people'] ) ) : array();
		$raw['attrs']  = isset( $_POST['attrs'] ) && is_array( $_POST['attrs'] ) ? array_map( 'strval', wp_unslash( $_POST['attrs'] ) ) : array();
		$raw['texts']  = isset( $_POST['texts'] ) && is_array( $_POST['texts'] ) ? wp_unslash( $_POST['texts'] ) : array();
		// phpcs:enable
		return $raw;
	}

	/** Saves and does what the change needs (rebuild, file, page cache). */
	private static function apply( array $raw ): array {
		$old = LVS_Settings::get();
		LVS_Settings::save( $raw );
		$new  = LVS_Settings::get();
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'live-search' ) ) );

		$index_keys = array( 'codes', 'people', 'attrs', 'cats', 'tags', 'brands', 'excerpt' );
		$reindex    = false;
		foreach ( $index_keys as $k ) {
			if ( $old[ $k ] !== $new[ $k ] ) {
				$reindex = true;
			}
		}
		if ( $reindex || 0 === LVS_Index::count_visible() ) {
			LVS_Index::start_full();
			$msgs[] = self::msg( 'info', __( 'Το ευρετήριο ξαναχτίζεται στο παρασκήνιο (λίγα λεπτά). Μπορείς να το κάνεις και τώρα από την καρτέλα «Ευρετήριο».', 'live-search' ) );
		} elseif ( 'off' !== $new['mode'] ) {
			LVS_File::maybe_build();
		}
		if ( 'off' !== $new['mode'] ) {
			LVS_Stats::ensure_dir();
		}
		// What a cached page carries: mode, texts, display, engine.
		$page_keys = array( 'mode', 'forms', 'limit', 'min_chars', 'image', 'price', 'byline', 'oos', 'isbn_direct', 'group', 'creators', 'engine', 'threshold', 'stats', 'lang', 'texts' );
		$purge     = false;
		foreach ( $page_keys as $k ) {
			if ( $old[ $k ] !== $new[ $k ] ) {
				$purge = true;
			}
		}
		if ( $purge && class_exists( 'Noxpress_Core' ) && method_exists( 'Noxpress_Core', 'purge_page_cache' ) ) {
			$names = Noxpress_Core::purge_page_cache();
			if ( $names ) {
				/* translators: %s: cache plugins */
				$msgs[] = self::msg( 'info', sprintf( __( 'Καθαρίστηκε η page cache: %s.', 'live-search' ), implode( ', ', $names ) ) );
			}
		}
		return $msgs;
	}

	private static function do_save(): void {
		self::redirect( 'settings', self::apply( self::posted() ) );
	}

	private static function do_synonym(): void {
		$phrase = trim( sanitize_text_field( self::post( 'phrase' ) ) );
		$target = trim( sanitize_text_field( self::post( 'target' ) ) );
		if ( '' === $phrase || '' === $target || false !== strpbrk( $phrase . $target, '=,' ) ) {
			self::redirect( 'stats', array( self::msg( 'error', __( 'Γράψε τι σημαίνει η φράση (χωρίς «=» και «,»).', 'live-search' ) ) ) );
		}
		$s             = LVS_Settings::get();
		$s['synonyms'] = trim( $s['synonyms'] . "\n" . $phrase . ' = ' . $target );
		$msgs          = self::apply( $s );
		/* translators: 1: phrase, 2: meaning */
		$msgs[] = self::msg( 'success', sprintf( __( 'Συνώνυμο: «%1$s» = «%2$s».', 'live-search' ), $phrase, $target ) );
		self::redirect( 'stats', $msgs );
	}

	private static function do_import_fibo(): void {
		$f = get_option( 'dgwt_wcas_settings', null );
		if ( ! is_array( $f ) ) {
			self::redirect( 'import', array( self::msg( 'error', __( 'Δεν βρέθηκαν ρυθμίσεις του FiboSearch.', 'live-search' ) ) ) );
		}
		$s    = LVS_Settings::get();
		$on   = static function ( $k ) use ( $f ) {
			return isset( $f[ $k ] ) && 'on' === $f[ $k ];
		};
		$lang = 'en' === LVS_Lang::from_locale( (string) get_locale() ) ? 'en' : 'el';
		if ( isset( $f['min_chars'] ) && is_numeric( $f['min_chars'] ) ) {
			$s['min_chars'] = (int) $f['min_chars'];
		}
		if ( isset( $f['suggestions_limit'] ) && is_numeric( $f['suggestions_limit'] ) ) {
			$s['limit'] = (int) $f['suggestions_limit'];
		}
		foreach ( array(
			'search_placeholder'          => 'placeholder',
			'search_no_results_text'      => 'none',
			'search_see_all_results_text' => 'all',
		) as $from => $to ) {
			if ( ! empty( $f[ $from ] ) && is_string( $f[ $from ] ) ) {
				$s['texts'][ $to ][ $lang ] = $f[ $from ];
			}
		}
		$s['image'] = $on( 'show_product_image' );
		$s['price'] = $on( 'show_product_price' );
		if ( $on( 'exclude_out_of_stock' ) ) {
			$s['oos'] = 'hide';
		}
		if ( $on( 'search_in_product_sku' ) || $on( 'search_in_product_global_unique_id' ) ) {
			$s['codes'] = true;
		}
		if ( ! empty( $f['search_synonyms'] ) && is_string( $f['search_synonyms'] ) ) {
			// FiboSearch: one group per line, words separated by commas.
			$lines = array();
			foreach ( preg_split( '/\r\n|\r|\n/', $f['search_synonyms'] ) as $line ) {
				$parts = array_values( array_filter( array_map( 'trim', explode( ',', (string) $line ) ), 'strlen' ) );
				if ( count( $parts ) >= 2 ) {
					$lines[] = $parts[0] . ' = ' . implode( ', ', array_slice( $parts, 1 ) );
				}
			}
			$s['synonyms'] = trim( $s['synonyms'] . "\n" . implode( "\n", $lines ) );
		}
		$msgs   = self::apply( $s );
		$msgs[] = self::msg( 'success', __( 'Οι ρυθμίσεις του FiboSearch πέρασαν. Το FiboSearch δεν άλλαξε: απενεργοποίησέ το όταν το Live Search είναι ενεργό και σε έχει πείσει.', 'live-search' ) );
		self::redirect( 'settings', $msgs );
	}

	public static function route_export(): void {
		if ( self::SLUG !== self::current_page() || empty( $_GET['lvs_export'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( Live_Search::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'live-search' ) );
		}
		check_admin_referer( self::NONCE, 'lvs_nonce' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="live-search-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode(
			array(
				'plugin'   => 'live-search',
				'version'  => LVS_VERSION,
				'exported' => gmdate( 'c' ),
				'settings' => LVS_Settings::get(),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		exit;
	}

	private static function do_import_json(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$file = isset( $_FILES['lvs_json'] ) && is_array( $_FILES['lvs_json'] ) ? $_FILES['lvs_json'] : null;
		if ( ! $file || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) || (int) $file['size'] > self::MAX_JSON ) {
			self::redirect( 'import', array( self::msg( 'error', __( 'Διάλεξε ένα αρχείο JSON έως 256 KB.', 'live-search' ) ) ) );
		}
		$raw  = (string) file_get_contents( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ( $data['plugin'] ?? '' ) !== 'live-search' || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			self::redirect( 'import', array( self::msg( 'error', __( 'Το αρχείο δεν είναι αντίγραφο ρυθμίσεων του Live Search.', 'live-search' ) ) ) );
		}
		// Every value goes through the same validation as the form.
		self::redirect( 'settings', self::apply( $data['settings'] ) );
	}

	/* =====================================================================
	 * AJAX: rebuild in batches
	 * =================================================================== */

	public static function ajax_rebuild(): void {
		check_ajax_referer( 'lvs_rebuild', 'nonce' );
		if ( ! current_user_can( Live_Search::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$start  = isset( $_POST['start'] ) ? (int) $_POST['start'] : 0;
		if ( 0 === $offset || $start <= 0 ) {
			$start = time();
		}
		try {
			$r = LVS_Index::batch( $offset, $start );
		} catch ( \Throwable $e ) {
			wp_send_json_error( null, 500 );
		}
		if ( $r['done'] ) {
			// The file even while the plugin is off, so the test mode is ready.
			if ( 'file' === LVS_Settings::engine() ) {
				LVS_File::build();
			}
			LVS_Stats::ensure_dir();
		}
		wp_send_json_success(
			array(
				'done'  => $r['done'],
				'next'  => $r['next'],
				'total' => $r['total'],
				'start' => $start,
				'count' => LVS_Index::count_visible(),
			)
		);
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		if ( $msgs ) {
			set_transient( 'lvs_aui_msg_' . get_current_user_id(), $msgs, 60 );
		}
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	private static function take_msgs(): array {
		$raw = get_transient( 'lvs_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'lvs_aui_msg_' . get_current_user_id() );
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
		<p class="lvs-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'live-search' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="lvs-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="lvs-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'live-search' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="lvs-footer-cta">
			<a class="lvs-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'live-search' ); ?>
			</a>
			<a class="lvs-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'live-search' ); ?>
			</a>
		</p>
		<?php
	}
}

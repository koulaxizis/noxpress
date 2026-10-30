<?php
/**
 * TFI_Admin_UI — menu, page, PRG routes, backup, product meta box (Bible
 * §3–§8, §11).
 *
 * Submenu «Trade Feed Import» of the shared "Noxpress" menu (admin_menu
 * priority 300), slug tfi-settings, capability manage_woocommerce.
 *
 * Made for bookshops without technical knowledge: until the first import
 * the page is a guide of three steps and nothing else.
 *  1. Connect  paste the publisher's e-mail as it came (the address, the
 *              username and the password are read from it), or type the
 *              three fields; the plugin checks them at once.
 *  2. Import   one category (a new one with the publisher's name, or an
 *              existing one), shown at once or as drafts, and one button
 *              that brings every title. Products of the shop with the
 *              same ISBN are linked instead of duplicated.
 *  3. Done     what came in, with a link to the products.
 * After that the tabs: start (status, sync now), titles (the publisher's
 * catalogue and what each title is in this shop), settings, history,
 * tools (backup, disconnect).
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient tfi_aui_msg_{uid}). The result of the
 * connection check is kept for an hour in tfi_probe (shop name, titles,
 * discount; never the password).
 */

defined( 'ABSPATH' ) || exit;

final class TFI_Admin_UI {

	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'tfi-settings';
	const NONCE       = 'tfi_admin';
	const NONCE_FIELD = 'tfi_nonce';
	const NONCE_BAK   = 'tfi_backup';
	const MAX_IMPORT  = 262144;
	const PROBE       = 'tfi_probe';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 300 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'meta_box' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TFI_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label. */
	private static function tabs(): array {
		return array(
			'start'    => __( 'Αρχή', 'trade-feed-import' ),
			'titles'   => __( 'Τίτλοι', 'trade-feed-import' ),
			'settings' => __( 'Ρυθμίσεις', 'trade-feed-import' ),
			'log'      => __( 'Ιστορικό', 'trade-feed-import' ),
			'tools'    => __( 'Εργαλεία', 'trade-feed-import' ),
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
			__( 'Trade Feed Import', 'trade-feed-import' ),
			__( 'Trade Feed Import', 'trade-feed-import' ),
			Trade_Feed_Import::cap(),
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'trade-feed-import' ) . '</a>'
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
		if ( self::guide_mode() ) {
			return 'start';
		}
		$tab = sanitize_key( self::q( 'tab' ) );
		return isset( self::tabs()[ $tab ] ) ? $tab : 'start';
	}

	/** True until the first import has finished: the page shows only the guide. */
	private static function guide_mode(): bool {
		return ! TFI_Settings::connected() || ( ! TFI_Sync::last() && ! TFI_Importer::ours() );
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'tfi-admin', TFI_URL . 'assets/admin.css', array(), TFI_VERSION );
		wp_enqueue_script( 'tfi-admin', TFI_URL . 'assets/admin.js', array(), TFI_VERSION, true );
	}

	private static function url( string $tab = 'start', array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Trade_Feed_Import::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'trade-feed-import' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap tfi-wrap">
			<h1><?php esc_html_e( 'Trade Feed Import', 'trade-feed-import' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Οι τίτλοι του εκδότη σου στο δικό σου κατάστημα, με τιμή και διαθεσιμότητα που ενημερώνονται μόνες τους κάθε μέρα.', 'trade-feed-import' ); ?></p>

			<?php
			self::print_notices( self::take_msgs() );
			if ( Trade_Feed_Import::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το TFI_DISABLE είναι ενεργό στο wp-config.php: καμία κλήση στο Trade Feed.', 'trade-feed-import' ) . '</p></div>';
			}
			if ( null === TFI_Settings::password() ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Ο αποθηκευμένος κωδικός δεν διαβάζεται πια (άλλαξαν τα κλειδιά του wp-config.php). Γράψε ξανά τον κωδικό στη Σύνδεση.', 'trade-feed-import' ) . '</p></div>';
			}

			if ( ! self::guide_mode() ) :
				?>
				<nav class="nav-tab-wrapper tfi-tabs">
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
			endif;

			switch ( $tab ) {
				case 'titles':
					self::render_titles();
					break;
				case 'settings':
					self::render_settings();
					break;
				case 'log':
					self::render_log();
					break;
				case 'tools':
					self::render_tools();
					break;
				default:
					self::render_start();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	private static function form_open( string $action ): void {
		echo '<form method="post">';
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		echo '<input type="hidden" name="tfi_action" value="' . esc_attr( $action ) . '" />';
	}

	private static function checkbox( string $name, bool $on, string $label ): void {
		printf(
			'<label class="tfi-check"><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label )
		);
	}

	private static function radio( string $name, string $value, string $current, string $label ): void {
		printf(
			'<label class="tfi-check"><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $value, $current, false ),
			esc_html( $label )
		);
	}

	private static function badge( string $text, string $kind ): string {
		return '<span class="tfi-badge tfi-badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function fmt( int $ts ): string {
		return $ts > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '—';
	}

	/** A select of the shop's product categories (indented tree). */
	private static function cat_select( string $name, int $chosen, string $none ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$by = array();
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$by[ (int) $t->parent ][] = $t;
		}
		echo '<select name="' . esc_attr( $name ) . '">';
		echo '<option value="0">' . esc_html( $none ) . '</option>';
		$walk = static function ( int $parent, int $depth ) use ( &$walk, $by, $chosen ) {
			foreach ( isset( $by[ $parent ] ) ? $by[ $parent ] : array() as $t ) {
				printf(
					'<option value="%1$d"%2$s>%3$s</option>',
					(int) $t->term_id,
					selected( $chosen, (int) $t->term_id, false ),
					esc_html( str_repeat( '— ', $depth ) . html_entity_decode( (string) $t->name, ENT_QUOTES, 'UTF-8' ) )
				);
				$walk( (int) $t->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		echo '</select>';
	}

	/* ---------------------------------------------------------------------
	 * Start: the guide, then the status
	 * ------------------------------------------------------------------- */

	private static function render_start(): void {
		$job = TFI_Sync::job();
		if ( $job ) {
			self::render_running( $job );
			return;
		}
		if ( ! TFI_Settings::connected() ) {
			self::render_step_connect();
			return;
		}
		if ( self::guide_mode() ) {
			self::render_step_import();
			return;
		}
		self::render_status();
	}

	private static function steps( int $now ): void {
		$labels = array(
			1 => __( 'Σύνδεση', 'trade-feed-import' ),
			2 => __( 'Εισαγωγή τίτλων', 'trade-feed-import' ),
			3 => __( 'Έτοιμο', 'trade-feed-import' ),
		);
		echo '<ol class="tfi-steps">';
		foreach ( $labels as $n => $label ) {
			$cls = $n < $now ? 'is-done' : ( $n === $now ? 'is-now' : '' );
			echo '<li class="' . esc_attr( $cls ) . '"><span>' . (int) $n . '</span> ' . esc_html( $label ) . '</li>';
		}
		echo '</ol>';
	}

	private static function render_step_connect(): void {
		$s = TFI_Settings::get();
		self::steps( 1 );
		?>
		<h2 class="tfi-h2"><?php esc_html_e( 'Βήμα 1: Σύνδεση με τον εκδότη', 'trade-feed-import' ); ?></h2>
		<div class="tfi-zone">
			<p class="tfi-big"><?php esc_html_e( 'Ο εκδότης σού έστειλε ένα email με τίτλο «Πρόσβαση στο Trade Feed». Άνοιξέ το, επίλεξε όλο το κείμενο (Ctrl+A), αντίγραψέ το (Ctrl+C) και επικόλλησέ το εδώ (Ctrl+V).', 'trade-feed-import' ); ?></p>
			<?php self::form_open( 'connect' ); ?>
				<textarea name="tfi_mail" rows="9" class="tfi-wide tfi-paste" placeholder="<?php esc_attr_e( 'Επικόλλησε εδώ ολόκληρο το email…', 'trade-feed-import' ); ?>"></textarea>
				<details class="tfi-details tfi-mt-8">
					<summary><?php esc_html_e( 'Δεν έχεις το email; Γράψε τα στοιχεία ένα-ένα', 'trade-feed-import' ); ?></summary>
					<span class="tfi-label"><?php esc_html_e( 'Διεύθυνση του εκδότη (π.χ. glarolykoi.net)', 'trade-feed-import' ); ?></span>
					<input type="text" name="tfi_url" class="regular-text" value="<?php echo esc_attr( $s['url'] ); ?>" autocomplete="off" />
					<span class="tfi-label"><?php esc_html_e( 'Όνομα χρήστη', 'trade-feed-import' ); ?></span>
					<input type="text" name="tfi_user" class="regular-text" value="<?php echo esc_attr( $s['user'] ); ?>" autocomplete="off" />
					<span class="tfi-label"><?php esc_html_e( 'Κωδικός', 'trade-feed-import' ); ?></span>
					<input type="password" name="tfi_pass" class="regular-text" value="" autocomplete="new-password" />
				</details>
				<p class="tfi-submit"><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Σύνδεση', 'trade-feed-import' ); ?></button></p>
			</form>
			<p class="description"><?php esc_html_e( 'Ο κωδικός φυλάσσεται κρυπτογραφημένος και χρησιμοποιείται μόνο για να διαβάζει τον κατάλογο του εκδότη. Δεν είναι ο κωδικός σύνδεσης στο site του.', 'trade-feed-import' ); ?></p>
		</div>
		<?php
	}

	private static function probe(): array {
		$p = get_transient( self::PROBE );
		return is_array( $p ) ? $p : array();
	}

	private static function render_step_import(): void {
		$s     = TFI_Settings::get();
		$probe = self::probe();
		$pub   = isset( $probe['publisher'] ) && '' !== $probe['publisher'] ? $probe['publisher'] : TFI_Settings::host();
		self::steps( 2 );
		?>
		<div class="notice notice-success inline"><p>
			<?php
			echo esc_html(
				isset( $probe['total'] )
					/* translators: 1: publisher, 2: number of titles */
					? sprintf( __( 'Η σύνδεση πέτυχε. Ο εκδότης «%1$s» έχει %2$d τίτλους για σένα.', 'trade-feed-import' ), $pub, (int) $probe['total'] )
					/* translators: %s: publisher */
					: sprintf( __( 'Η σύνδεση πέτυχε (%s).', 'trade-feed-import' ), $pub )
			);
			if ( isset( $probe['discount'] ) ) {
				echo ' ' . esc_html(
					/* translators: %s: discount percent */
					sprintf( __( 'Η έκπτωσή σου: %s%%.', 'trade-feed-import' ), wc_format_localized_decimal( (string) $probe['discount'] ) )
				);
			}
			?>
		</p></div>

		<h2 class="tfi-h2"><?php esc_html_e( 'Βήμα 2: Εισαγωγή των τίτλων', 'trade-feed-import' ); ?></h2>
		<div class="tfi-zone">
			<?php self::form_open( 'first_import' ); ?>
				<span class="tfi-label"><?php esc_html_e( 'Σε ποια κατηγορία του καταστήματός σου να μπουν τα βιβλία;', 'trade-feed-import' ); ?></span>
				<label class="tfi-check tfi-big"><input type="radio" name="tfi_cat_mode" value="new" checked="checked" /> <?php esc_html_e( 'Σε νέα κατηγορία με όνομα:', 'trade-feed-import' ); ?></label>
				<input type="text" name="tfi_cat_name" class="regular-text" value="<?php echo esc_attr( $pub ); ?>" />
				<label class="tfi-check tfi-big tfi-mt-8"><input type="radio" name="tfi_cat_mode" value="existing" /> <?php esc_html_e( 'Σε κατηγορία που έχω ήδη:', 'trade-feed-import' ); ?></label>
				<?php self::cat_select( 'tfi_cat', (int) $s['cat'], __( '— διάλεξε —', 'trade-feed-import' ) ); ?>

				<span class="tfi-label"><?php esc_html_e( 'Να φαίνονται αμέσως στους πελάτες σου;', 'trade-feed-import' ); ?></span>
				<?php
				self::radio( 'tfi_status', 'draft', $s['status'], __( 'Όχι ακόμα: να μπουν ως πρόχειρα, να τα δω πρώτα (προτείνεται)', 'trade-feed-import' ) );
				self::radio( 'tfi_status', 'publish', $s['status'], __( 'Ναι, να φαίνονται αμέσως', 'trade-feed-import' ) );
				?>
				<p class="tfi-submit"><button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Φέρε όλους τους τίτλους', 'trade-feed-import' ); ?></button></p>
			</form>
			<p class="description"><?php esc_html_e( 'Κάθε βιβλίο έρχεται με εξώφυλλο, ISBN, συγγραφείς, περιγραφή και την τιμή λιανικής του εκδότη. Αν έχεις ήδη στο κατάστημα ένα βιβλίο με το ίδιο ISBN, δεν φτιάχνεται δεύτερο: το δικό σου απλώς παίρνει τιμή και διαθεσιμότητα από τον εκδότη. Από αύριο, τιμές και διαθεσιμότητα ενημερώνονται μόνες τους κάθε πρωί.', 'trade-feed-import' ); ?></p>
		</div>
		<?php self::form_open( 'disconnect' ); ?>
			<p><button type="submit" class="button-link"><?php esc_html_e( 'Λάθος στοιχεία; Σύνδεση από την αρχή', 'trade-feed-import' ); ?></button></p>
		</form>
		<?php
	}

	private static function render_running( array $job ): void {
		if ( ! empty( $job['first'] ) ) {
			self::steps( 2 );
		}
		$pages = max( 1, (int) $job['pages'] );
		$page  = min( $pages, (int) $job['page'] );
		$pct   = 'gone' === $job['phase'] ? 100 : (int) floor( ( $page - 1 ) * 100 / $pages );
		?>
		<h2 class="tfi-h2"><?php esc_html_e( 'Φέρνουμε τους τίτλους…', 'trade-feed-import' ); ?></h2>
		<div class="tfi-zone" data-tfi-refresh="8">
			<div class="tfi-bar"><span style="width:<?php echo (int) max( 4, $pct ); ?>%"></span></div>
			<p class="tfi-big">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: titles read, 2: new products, 3: updated products */
						__( 'Διαβάστηκαν %1$d τίτλοι · νέα προϊόντα: %2$d · ενημερώθηκαν: %3$d', 'trade-feed-import' ),
						(int) $job['n']['listed'],
						(int) $job['n']['created'],
						(int) $job['n']['updated']
					)
				);
				?>
			</p>
			<p class="description"><?php esc_html_e( 'Μπορείς να κλείσεις αυτή τη σελίδα: η εισαγωγή συνεχίζει μόνη της. Η σελίδα ανανεώνεται κάθε λίγα δευτερόλεπτα.', 'trade-feed-import' ); ?></p>
			<?php
			$wait = TFI_Client::waiting();
			if ( $wait > 0 ) {
				/* translators: %d: minutes */
				echo '<p class="tfi-warn">' . esc_html( sprintf( __( 'Ο εκδότης ζήτησε μικρή παύση. Συνεχίζουμε σε περίπου %d λεπτά.', 'trade-feed-import' ), (int) ceil( $wait / 60 ) ) ) . '</p>';
			}
			self::form_open( 'cancel' );
			echo '<p><button type="submit" class="button">' . esc_html__( 'Διακοπή', 'trade-feed-import' ) . '</button></p></form>';
			?>
		</div>
		<?php
	}

	private static function render_status(): void {
		$last = TFI_Sync::last();
		$ours = TFI_Importer::ours();
		$s    = TFI_Settings::get();

		if ( $last && ! empty( $last['first'] ) && '' === $last['error'] ) {
			self::steps( 3 );
			?>
			<div class="tfi-zone tfi-done">
				<h2 class="tfi-h2"><?php esc_html_e( 'Έτοιμο!', 'trade-feed-import' ); ?></h2>
				<p class="tfi-big">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: new products, 2: linked products */
							__( 'Μπήκαν %1$d νέα βιβλία στο κατάστημά σου. %2$d βιβλία που είχες ήδη συνδέθηκαν με τον εκδότη.', 'trade-feed-import' ),
							(int) $last['n']['created'],
							(int) ( $last['n']['linked'] ?? 0 )
						)
					);
					?>
				</p>
				<?php if ( 'draft' === $s['status'] ) : ?>
					<p><?php esc_html_e( 'Είναι πρόχειρα: οι πελάτες σου δεν τα βλέπουν ακόμα. Άνοιξέ τα, δες ότι είναι όπως τα θέλεις και πάτα «Δημοσίευση» (ή διάλεξέ τα όλα στη λίστα προϊόντων → Μαζική επεξεργασία → Κατάσταση: Δημοσιευμένο).', 'trade-feed-import' ); ?></p>
					<p><a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&post_status=draft' ) ); ?>"><?php esc_html_e( 'Δες τα πρόχειρα προϊόντα', 'trade-feed-import' ); ?></a></p>
				<?php else : ?>
					<p><a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>"><?php esc_html_e( 'Δες τα προϊόντα', 'trade-feed-import' ); ?></a></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Δεν χρειάζεται να κάνεις τίποτε άλλο. Κάθε πρωί τιμές, διαθεσιμότητα και προπαραγγελίες ενημερώνονται μόνες τους. Ό,τι αλλάξεις εσύ σε ένα βιβλίο (τίτλο, περιγραφή, εξώφυλλο) δεν θα το ξαναγράψουμε.', 'trade-feed-import' ); ?></p>
			</div>
			<?php
		}

		$locked = 0;
		$gone   = 0;
		foreach ( $ours as $pid ) {
			$locked += '' !== (string) get_post_meta( $pid, '_tfi_locked', true ) ? 1 : 0;
			$gone   += '1' === (string) get_post_meta( $pid, '_tfi_gone', true ) ? 1 : 0;
		}
		$next = TFI_Sync::next( 'tfi_daily' );
		?>
		<h2 class="tfi-h2"><?php esc_html_e( 'Κατάσταση', 'trade-feed-import' ); ?></h2>
		<div class="tfi-zone">
			<table class="widefat striped tfi-table">
				<tbody>
					<tr><td><?php esc_html_e( 'Εκδότης', 'trade-feed-import' ); ?></td><td><?php echo esc_html( TFI_Settings::host() ); ?> · <?php echo esc_html( $s['user'] ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Βιβλία από τον εκδότη στο κατάστημά σου', 'trade-feed-import' ); ?></td><td><?php echo (int) count( $ours ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Με δικές σου αλλαγές (κλειδωμένα πεδία)', 'trade-feed-import' ); ?></td><td><?php echo (int) $locked; ?></td></tr>
					<tr><td><?php esc_html_e( 'Δεν τα δίνει πια ο εκδότης', 'trade-feed-import' ); ?></td><td><?php echo (int) $gone; ?></td></tr>
					<tr><td><?php esc_html_e( 'Τελευταίος συγχρονισμός', 'trade-feed-import' ); ?></td><td>
						<?php
						echo esc_html( $last ? self::fmt( (int) $last['ended'] ) : '—' );
						if ( $last && '' !== $last['error'] ) {
							echo ' ' . self::badge( __( 'Σφάλμα', 'trade-feed-import' ), 'danger' ) . ' <span class="tfi-warn">' . esc_html( $last['error'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- badge() escapes.
						} elseif ( $last ) {
							echo ' ' . self::badge( __( 'Εντάξει', 'trade-feed-import' ), 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput -- badge() escapes.
						}
						?>
					</td></tr>
					<tr><td><?php esc_html_e( 'Επόμενος συγχρονισμός', 'trade-feed-import' ); ?></td><td><?php echo esc_html( $s['sync_on'] && $next ? self::fmt( (int) $next ) : __( 'Κλειστός (Ρυθμίσεις)', 'trade-feed-import' ) ); ?></td></tr>
				</tbody>
			</table>
			<?php self::form_open( 'sync_now' ); ?>
				<p class="tfi-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Συγχρονισμός τώρα', 'trade-feed-import' ); ?></button>
				<a class="button" href="<?php echo esc_url( self::url( 'titles' ) ); ?>"><?php esc_html_e( 'Όλοι οι τίτλοι του εκδότη', 'trade-feed-import' ); ?></a></p>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Titles
	 * ------------------------------------------------------------------- */

	private static function render_titles(): void {
		$rows = TFI_Sync::catalog();
		$at   = (int) get_option( TFI_Sync::CATALOG . '_at', 0 );
		if ( ! $rows ) {
			echo '<div class="tfi-zone"><p class="tfi-empty">' . esc_html__( 'Η λίστα των τίτλων γεμίζει με τον πρώτο συγχρονισμό. Πάτα «Συγχρονισμός τώρα» στην Αρχή.', 'trade-feed-import' ) . '</p></div>';
			return;
		}
		$ours = TFI_Importer::ours( array_column( $rows, 'id' ) );
		$isbn = TFI_Importer::isbn_map( array_column( $rows, 'd' ) );
		$cur  = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		$av   = array(
			'instock'     => __( 'Διαθέσιμο', 'trade-feed-import' ),
			'outofstock'  => __( 'Εξαντλημένο', 'trade-feed-import' ),
			'onbackorder' => __( 'Σε αναμονή', 'trade-feed-import' ),
			'preorder'    => __( 'Προπαραγγελία', 'trade-feed-import' ),
		);
		?>
		<h2 class="tfi-h2"><?php esc_html_e( 'Οι τίτλοι του εκδότη', 'trade-feed-import' ); ?></h2>
		<p class="description">
			<?php
			/* translators: 1: number of titles, 2: date */
			echo esc_html( sprintf( __( '%1$d τίτλοι, από τον συγχρονισμό της %2$s.', 'trade-feed-import' ), count( $rows ), self::fmt( $at ) ) );
			?>
		</p>
		<?php self::form_open( 'titles' ); ?>
			<div class="tfi-scroll-x">
			<table class="widefat striped tfi-table">
				<thead><tr>
					<td class="check-column"><input type="checkbox" class="tfi-all" aria-label="<?php esc_attr_e( 'Όλα', 'trade-feed-import' ); ?>" /></td>
					<th><?php esc_html_e( 'Τίτλος', 'trade-feed-import' ); ?></th>
					<th>ISBN</th>
					<th><?php esc_html_e( 'Λιανική', 'trade-feed-import' ); ?></th>
					<th><?php esc_html_e( 'Χονδρική', 'trade-feed-import' ); ?></th>
					<th><?php esc_html_e( 'Διαθεσιμότητα', 'trade-feed-import' ); ?></th>
					<th><?php esc_html_e( 'Στο κατάστημά σου', 'trade-feed-import' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<th class="check-column"><input type="checkbox" name="tfi_ids[]" value="<?php echo (int) $r['id']; ?>" /></th>
						<td><?php echo esc_html( (string) $r['title'] ); ?></td>
						<td><code><?php echo esc_html( (string) $r['isbn'] ); ?></code></td>
						<td><?php echo '' !== $r['retail'] ? esc_html( wc_format_localized_price( (string) $r['retail'] ) . ' ' . $cur ) : '—'; ?></td>
						<td><?php echo '' !== $r['trade'] ? esc_html( wc_format_localized_price( (string) $r['trade'] ) . ' ' . $cur ) : '—'; ?></td>
						<td>
							<?php
							echo esc_html( isset( $av[ $r['status'] ] ) ? $av[ $r['status'] ] : (string) $r['status'] );
							if ( 'preorder' === $r['status'] && '' !== $r['rel'] ) {
								echo ' · ' . esc_html( wp_date( get_option( 'date_format' ), (int) strtotime( $r['rel'] . ' 12:00' ) ) );
							}
							?>
						</td>
						<td>
							<?php
							if ( isset( $ours[ $r['id'] ] ) ) {
								$pid = $ours[ $r['id'] ];
								$lk  = (string) get_post_meta( $pid, '_tfi_locked', true );
								echo self::badge( '1' === (string) get_post_meta( $pid, '_tfi_linked', true ) ? __( 'Συνδεδεμένο', 'trade-feed-import' ) : __( 'Εισήχθη', 'trade-feed-import' ), 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput -- badge() escapes.
								echo ' <a href="' . esc_url( (string) get_edit_post_link( $pid ) ) . '">#' . (int) $pid . '</a>';
								if ( '' !== $lk ) {
									$labels = array_map( array( 'TFI_Importer', 'field_label' ), explode( ',', $lk ) );
									echo '<br /><span class="tfi-muted">' . esc_html__( 'Δικές σου αλλαγές:', 'trade-feed-import' ) . ' ' . esc_html( implode( ', ', $labels ) ) . '</span>';
								}
							} elseif ( '' !== $r['d'] && isset( $isbn[ $r['d'] ] ) ) {
								echo self::badge( __( 'Υπάρχει ήδη', 'trade-feed-import' ), 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput -- badge() escapes.
								echo ' <a href="' . esc_url( (string) get_edit_post_link( $isbn[ $r['d'] ] ) ) . '">#' . (int) $isbn[ $r['d'] ] . '</a>';
							} else {
								echo self::badge( __( 'Όχι ακόμα', 'trade-feed-import' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- badge() escapes.
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<p class="tfi-submit">
				<select name="tfi_bulk">
					<option value="import"><?php esc_html_e( 'Εισαγωγή όσων λείπουν', 'trade-feed-import' ); ?></option>
					<option value="link"><?php esc_html_e( 'Σύνδεση όσων υπάρχουν ήδη (μόνο τιμή και διαθεσιμότητα)', 'trade-feed-import' ); ?></option>
					<option value="take"><?php esc_html_e( 'Πάρε ξανά τα πάντα από τον εκδότη (σβήνει τις δικές σου αλλαγές)', 'trade-feed-import' ); ?></option>
					<option value="unlink"><?php esc_html_e( 'Αποσύνδεση (το προϊόν μένει, δεν ενημερώνεται πια)', 'trade-feed-import' ); ?></option>
				</select>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Εφαρμογή στα επιλεγμένα', 'trade-feed-import' ); ?></button>
			</p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	private static function render_settings(): void {
		$s    = TFI_Settings::get();
		$cats = TFI_Settings::source_cats();
		self::form_open( 'save_settings' );
		?>
			<h2 class="tfi-h2"><?php esc_html_e( 'Καθημερινός συγχρονισμός', 'trade-feed-import' ); ?></h2>
			<div class="tfi-zone">
				<?php self::checkbox( 'tfi_sync_on', $s['sync_on'], __( 'Κάθε μέρα: τιμή, διαθεσιμότητα και προπαραγγελίες από τον εκδότη', 'trade-feed-import' ) ); ?>
				<span class="tfi-label"><?php esc_html_e( 'Ώρα', 'trade-feed-import' ); ?></span>
				<select name="tfi_hour">
					<?php for ( $h = 0; $h < 24; $h++ ) : ?>
						<option value="<?php echo (int) $h; ?>"<?php selected( $s['hour'], $h ); ?>><?php echo esc_html( sprintf( '%02d:00', $h ) ); ?></option>
					<?php endfor; ?>
				</select>
				<?php self::checkbox( 'tfi_auto_new', $s['auto_new'], __( 'Νέοι τίτλοι του εκδότη μπαίνουν μόνοι τους στο κατάστημα', 'trade-feed-import' ) ); ?>
				<span class="tfi-label"><?php esc_html_e( 'Ένας τίτλος που δεν τον δίνει πια ο εκδότης', 'trade-feed-import' ); ?></span>
				<?php
				self::radio( 'tfi_gone', 'outofstock', $s['gone'], __( 'Γίνεται «Εξαντλημένο»', 'trade-feed-import' ) );
				self::radio( 'tfi_gone', 'draft', $s['gone'], __( 'Γίνεται πρόχειρο (κρύβεται από τους πελάτες)', 'trade-feed-import' ) );
				?>
				<p class="description"><?php esc_html_e( 'Η τιμή λιανικής ακολουθεί πάντα τον εκδότη (ενιαία τιμή βιβλίου). Την «Τιμή προσφοράς» την ορίζεις εσύ και δεν την αγγίζουμε. Αν μετράς δικό σου απόθεμα σε ένα βιβλίο, η διαθεσιμότητά του δεν αλλάζει ποτέ από εδώ.', 'trade-feed-import' ); ?></p>
			</div>

			<h2 class="tfi-h2"><?php esc_html_e( 'Νέα προϊόντα', 'trade-feed-import' ); ?></h2>
			<div class="tfi-zone">
				<span class="tfi-label"><?php esc_html_e( 'Κατάσταση', 'trade-feed-import' ); ?></span>
				<?php
				self::radio( 'tfi_status', 'draft', $s['status'], __( 'Πρόχειρο', 'trade-feed-import' ) );
				self::radio( 'tfi_status', 'publish', $s['status'], __( 'Δημοσιευμένο', 'trade-feed-import' ) );
				?>
				<span class="tfi-label"><?php esc_html_e( 'Κατηγορία', 'trade-feed-import' ); ?></span>
				<?php self::cat_select( 'tfi_cat', (int) $s['cat'], __( 'Η προεπιλεγμένη του WooCommerce', 'trade-feed-import' ) ); ?>
				<?php if ( $cats ) : ?>
					<details class="tfi-details tfi-mt-8">
						<summary><?php esc_html_e( 'Ανά κατηγορία του εκδότη (προαιρετικό)', 'trade-feed-import' ); ?></summary>
						<?php foreach ( $cats as $id => $c ) : ?>
							<div class="tfi-field">
								<span><?php echo esc_html( $c['name'] ); ?></span>
								<?php self::cat_select( 'tfi_map[' . (int) $id . ']', isset( $s['cat_map'][ $id ] ) ? (int) $s['cat_map'][ $id ] : 0, __( '— η παραπάνω —', 'trade-feed-import' ) ); ?>
							</div>
						<?php endforeach; ?>
					</details>
				<?php endif; ?>
				<span class="tfi-label"><?php esc_html_e( 'Κατηγορία ΦΠΑ', 'trade-feed-import' ); ?></span>
				<select name="tfi_tax_class">
					<option value=""><?php esc_html_e( 'Αυτόματα, με τον συντελεστή ΦΠΑ του εκδότη', 'trade-feed-import' ); ?></option>
					<?php foreach ( WC_Tax::get_tax_classes() as $name ) : ?>
						<?php $slug = sanitize_title( $name ); ?>
						<option value="<?php echo esc_attr( $slug ); ?>"<?php selected( $s['tax_class'], $slug ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Αυτόματα: κάθε βιβλίο παίρνει την κατηγορία ΦΠΑ του καταστήματός σου που έχει τον ίδιο συντελεστή με του εκδότη (π.χ. 6%), αλλιώς την κανονική.', 'trade-feed-import' ); ?></p>
				<?php
				self::checkbox( 'tfi_brand', $s['brand'], __( 'Ο εκδότης ως brand του προϊόντος', 'trade-feed-import' ) );
				self::checkbox( 'tfi_cost', $s['cost'], __( 'Η τιμή χονδρικής ως «Κόστος» του προϊόντος', 'trade-feed-import' ) );
				if ( ! TFI_Importer::cogs_on() ) {
					echo '<p class="description">' . esc_html__( 'Το πεδίο «Κόστος» του WooCommerce δεν είναι ενεργό σε αυτό το κατάστημα: η τιμή χονδρικής φαίνεται μόνο στο πλαίσιο «Trade Feed» της σελίδας του προϊόντος.', 'trade-feed-import' ) . '</p>';
				}
				?>
			</div>

			<h2 class="tfi-h2"><?php esc_html_e( 'Απεγκατάσταση', 'trade-feed-import' ); ?></h2>
			<div class="tfi-zone">
				<?php self::checkbox( 'tfi_purge', $s['purge'], __( 'Όταν διαγράψω το plugin, να σβηστούν και τα προϊόντα που έφερε', 'trade-feed-import' ) ); ?>
				<p class="description"><?php esc_html_e( 'Κλειστό από προεπιλογή: τα προϊόντα μένουν στο κατάστημα. Τα δικά σου προϊόντα που απλώς συνδέθηκαν δεν σβήνονται ποτέ.', 'trade-feed-import' ); ?></p>
			</div>

			<p class="tfi-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'trade-feed-import' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * History, tools
	 * ------------------------------------------------------------------- */

	private static function render_log(): void {
		$log   = TFI_Log::all();
		$kinds = array(
			'import' => __( 'Εισαγωγή', 'trade-feed-import' ),
			'link'   => __( 'Σύνδεση', 'trade-feed-import' ),
			'unlink' => __( 'Αποσύνδεση', 'trade-feed-import' ),
			'update' => __( 'Ενημέρωση', 'trade-feed-import' ),
			'locked' => __( 'Δικές σου αλλαγές', 'trade-feed-import' ),
			'gone'   => __( 'Εκτός feed', 'trade-feed-import' ),
			'back'   => __( 'Ξανά στο feed', 'trade-feed-import' ),
			'error'  => __( 'Σφάλμα', 'trade-feed-import' ),
			'sync'   => __( 'Συγχρονισμός', 'trade-feed-import' ),
		);
		echo '<h2 class="tfi-h2">' . esc_html__( 'Ιστορικό', 'trade-feed-import' ) . '</h2>';
		if ( ! $log ) {
			echo '<div class="tfi-zone"><p class="tfi-empty">' . esc_html__( 'Τίποτα ακόμα.', 'trade-feed-import' ) . '</p></div>';
			return;
		}
		echo '<div class="tfi-scroll-x"><table class="widefat striped tfi-table"><thead><tr><th>' . esc_html__( 'Πότε', 'trade-feed-import' ) . '</th><th>' . esc_html__( 'Τι', 'trade-feed-import' ) . '</th><th>' . esc_html__( 'Προϊόν', 'trade-feed-import' ) . '</th><th>' . esc_html__( 'Λεπτομέρειες', 'trade-feed-import' ) . '</th></tr></thead><tbody>';
		foreach ( $log as $l ) {
			$k      = isset( $l['k'] ) ? (string) $l['k'] : '';
			$fields = isset( $l['f'] ) && is_array( $l['f'] ) ? array_map( array( 'TFI_Importer', 'field_label' ), $l['f'] ) : array();
			$pid    = isset( $l['p'] ) ? (int) $l['p'] : 0;
			echo '<tr><td>' . esc_html( self::fmt( isset( $l['t'] ) ? (int) $l['t'] : 0 ) ) . '</td>';
			echo '<td>' . esc_html( isset( $kinds[ $k ] ) ? $kinds[ $k ] : $k ) . '</td>';
			echo '<td>' . ( $pid > 0 ? '<a href="' . esc_url( (string) get_edit_post_link( $pid ) ) . '">' . esc_html( (string) ( $l['n'] ?? '' ) ) . '</a>' : '' ) . '</td>';
			echo '<td>' . esc_html( trim( implode( ', ', $fields ) . ' ' . (string) ( $l['m'] ?? '' ) ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		self::form_open( 'clear_log' );
		echo '<p><button type="submit" class="button">' . esc_html__( 'Καθαρισμός ιστορικού', 'trade-feed-import' ) . '</button></p></form>';
	}

	private static function render_tools(): void {
		$s = TFI_Settings::get();
		?>
		<h2 class="tfi-h2"><?php esc_html_e( 'Σύνδεση', 'trade-feed-import' ); ?></h2>
		<div class="tfi-zone">
			<p><?php echo esc_html( $s['url'] ); ?> · <?php echo esc_html( $s['user'] ); ?> · <?php esc_html_e( 'κωδικός αποθηκευμένος', 'trade-feed-import' ); ?></p>
			<p class="description"><?php esc_html_e( 'Αν ο εκδότης σού έστειλε νέο κωδικό, αποσυνδέσου και κάνε ξανά τη σύνδεση με το νέο email. Τα προϊόντα σου δεν αλλάζουν.', 'trade-feed-import' ); ?></p>
			<?php self::form_open( 'disconnect' ); ?>
				<p><button type="submit" class="button"><?php esc_html_e( 'Αποσύνδεση και νέα σύνδεση', 'trade-feed-import' ); ?></button></p>
			</form>
		</div>

		<h2 class="tfi-h2"><?php esc_html_e( 'Αντίγραφο ρυθμίσεων', 'trade-feed-import' ); ?></h2>
		<div class="tfi-zone">
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( self::url( 'tools', array( 'tfi_export' => 1 ) ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή (JSON)', 'trade-feed-import' ); ?></a></p>
			<p class="description"><?php esc_html_e( 'Το αρχείο δεν περιέχει τον κωδικό.', 'trade-feed-import' ); ?></p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( self::NONCE_BAK, 'tfi_backup_nonce' ); ?>
				<input type="file" name="tfi_import_file" accept="application/json,.json" />
				<button type="submit" name="tfi_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή', 'trade-feed-import' ); ?></button>
			</form>
		</div>

		<h2 class="tfi-h2"><?php esc_html_e( 'Για προγραμματιστές', 'trade-feed-import' ); ?></h2>
		<div class="tfi-zone">
			<p class="description"><code>tfi_get_source( $product_id )</code> · <code>noxpress_products_changed</code> · <code>define( 'TFI_DISABLE', true );</code></p>
		</div>
		<?php
	}

	/* =====================================================================
	 * Product meta box
	 * =================================================================== */

	public static function meta_box( $post ): void {
		if ( ! $post instanceof WP_Post || null === tfi_get_source( (int) $post->ID ) ) {
			return;
		}
		add_meta_box( 'tfi-source', __( 'Trade Feed', 'trade-feed-import' ), array( __CLASS__, 'render_box' ), 'product', 'side', 'default' );
	}

	public static function render_box( WP_Post $post ): void {
		$src    = tfi_get_source( (int) $post->ID );
		$trade  = (string) get_post_meta( $post->ID, '_tfi_trade', true );
		$locked = TFI_Importer::locked_fields( (int) $post->ID );
		if ( ! $src ) {
			return;
		}
		echo '<p>' . esc_html( $src['source'] ) . ( $src['linked'] ? ' · ' . esc_html__( 'συνδεδεμένο: μόνο τιμή και διαθεσιμότητα', 'trade-feed-import' ) : '' ) . '</p>';
		if ( '' !== $trade ) {
			echo '<p>' . esc_html__( 'Τιμή χονδρικής:', 'trade-feed-import' ) . ' <strong>' . wp_kses_post( wc_price( (float) $trade ) ) . '</strong></p>';
		}
		echo '<p>' . esc_html__( 'Τελευταίος συγχρονισμός:', 'trade-feed-import' ) . ' ' . esc_html( self::fmt( (int) $src['synced'] ) ) . '</p>';
		if ( $src['gone'] ) {
			echo '<p class="description">' . esc_html__( 'Ο εκδότης δεν δίνει πια αυτόν τον τίτλο.', 'trade-feed-import' ) . '</p>';
		}
		if ( $locked ) {
			echo '<p class="description">' . esc_html__( 'Δικές σου αλλαγές (δεν τις ξαναγράφουμε):', 'trade-feed-import' ) . ' ' . esc_html( implode( ', ', array_map( array( 'TFI_Importer', 'field_label' ), $locked ) ) ) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'Η τιμή λιανικής και η διαθεσιμότητα έρχονται από τον εκδότη κάθε μέρα.', 'trade-feed-import' ) . '</p>';
	}

	/* =====================================================================
	 * Routes
	 * =================================================================== */

	public static function route(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}

		if ( isset( $_POST['tfi_import'] ) ) {
			if ( ! isset( $_POST['tfi_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['tfi_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( Trade_Feed_Import::cap() ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'trade-feed-import' ) );
			}
			self::redirect( 'tools', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['tfi_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Trade_Feed_Import::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'trade-feed-import' ) );
		}

		switch ( sanitize_key( wp_unslash( $_POST['tfi_action'] ) ) ) {
			case 'connect':
				self::redirect( 'start', self::do_connect() );
				break;
			case 'first_import':
				self::redirect( 'start', self::do_first_import() );
				break;
			case 'disconnect':
				TFI_Sync::cancel();
				TFI_Settings::set_password( '' );
				delete_transient( self::PROBE );
				TFI_Sync::reschedule_daily();
				self::redirect( 'start', array( self::msg( 'success', __( 'Αποσυνδέθηκες. Τα προϊόντα σου έμειναν όπως ήταν. Κάνε τη σύνδεση με τα νέα στοιχεία.', 'trade-feed-import' ) ) ) );
				break;
			case 'cancel':
				TFI_Sync::cancel();
				self::redirect( 'start', array( self::msg( 'info', __( 'Σταμάτησε. Όσα βιβλία μπήκαν ήδη έμειναν.', 'trade-feed-import' ) ) ) );
				break;
			case 'sync_now':
				$ok = TFI_Sync::start();
				self::redirect( 'start', array( $ok ? self::msg( 'success', __( 'Ο συγχρονισμός ξεκίνησε.', 'trade-feed-import' ) ) : self::msg( 'warning', __( 'Ένας συγχρονισμός τρέχει ήδη.', 'trade-feed-import' ) ) ) );
				break;
			case 'titles':
				self::redirect( 'titles', self::do_titles() );
				break;
			case 'save_settings':
				self::redirect( 'settings', self::do_save_settings() );
				break;
			case 'clear_log':
				TFI_Log::clear();
				self::redirect( 'log', array( self::msg( 'success', __( 'Το ιστορικό καθαρίστηκε.', 'trade-feed-import' ) ) ) );
				break;
			default:
				self::redirect( 'start', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'trade-feed-import' ) ) ) );
		}
	}

	private static function do_connect(): array {
		$found = TFI_Settings::parse_mail( self::post( 'tfi_mail' ) );
		$url   = '' !== $found['url'] ? $found['url'] : TFI_Settings::site_url( self::post( 'tfi_url' ) );
		$user  = '' !== $found['user'] ? $found['user'] : trim( sanitize_text_field( self::post( 'tfi_user' ) ) );
		$pass  = '' !== $found['pass'] ? $found['pass'] : TFI_Settings::app_password( self::post( 'tfi_pass' ) );

		$missing = array();
		if ( '' === $url ) {
			$missing[] = __( 'τη διεύθυνση του εκδότη', 'trade-feed-import' );
		}
		if ( '' === $user ) {
			$missing[] = __( 'το όνομα χρήστη', 'trade-feed-import' );
		}
		if ( '' === $pass ) {
			$missing[] = __( 'τον κωδικό', 'trade-feed-import' );
		}
		if ( $missing ) {
			/* translators: %s: the missing details */
			return array( self::msg( 'error', sprintf( __( 'Δεν βρήκα %s. Επικόλλησε ολόκληρο το email ή συμπλήρωσε τα πεδία ένα-ένα.', 'trade-feed-import' ), implode( ', ', $missing ) ) ) );
		}

		$s         = TFI_Settings::get();
		$s['url']  = $url;
		$s['user'] = $user;
		TFI_Settings::save( $s );
		if ( ! TFI_Settings::set_password( $pass ) ) {
			return array( self::msg( 'error', __( 'Ο κωδικός δεν μπορεί να φυλαχτεί κρυπτογραφημένος σε αυτόν τον server (λείπει το OpenSSL). Ζήτησε βοήθεια από την εταιρεία φιλοξενίας.', 'trade-feed-import' ) ) );
		}
		unset( $pass, $found );
		delete_option( TFI_Client::WAIT );

		$acc = TFI_Client::account();
		if ( is_wp_error( $acc ) ) {
			TFI_Settings::set_password( '' );
			return array( self::msg( 'error', $acc->get_error_message() ) );
		}
		$probe = array(
			'name'     => isset( $acc['name'] ) ? mb_substr( sanitize_text_field( (string) $acc['name'] ), 0, 120 ) : '',
			'discount' => isset( $acc['discount_percent'] ) && is_numeric( $acc['discount_percent'] ) ? (float) $acc['discount_percent'] : null,
		);
		$cats = TFI_Client::categories();
		if ( is_array( $cats ) ) {
			TFI_Settings::save_source_cats( $cats );
		}
		$first = TFI_Client::products( 1 );
		if ( is_array( $first ) ) {
			$probe['total'] = (int) $first['total'];
			$r              = $first['items'] ? TFI_Record::from( $first['items'][0] ) : null;
			$probe['publisher'] = $r ? $r['publisher'] : '';
		}
		if ( null === $probe['discount'] ) {
			unset( $probe['discount'] );
		}
		set_transient( self::PROBE, $probe, HOUR_IN_SECONDS );
		TFI_Sync::reschedule_daily();
		return array();
	}

	private static function do_first_import(): array {
		if ( ! TFI_Settings::connected() ) {
			return array( self::msg( 'error', __( 'Κάνε πρώτα τη σύνδεση.', 'trade-feed-import' ) ) );
		}
		$s = TFI_Settings::get();
		if ( 'existing' === self::post( 'tfi_cat_mode' ) ) {
			$s['cat'] = (int) self::post( 'tfi_cat' );
			if ( $s['cat'] <= 0 ) {
				return array( self::msg( 'error', __( 'Διάλεξε κατηγορία ή γράψε όνομα για νέα.', 'trade-feed-import' ) ) );
			}
		} else {
			$name = trim( sanitize_text_field( self::post( 'tfi_cat_name' ) ) );
			if ( '' === $name ) {
				return array( self::msg( 'error', __( 'Διάλεξε κατηγορία ή γράψε όνομα για νέα.', 'trade-feed-import' ) ) );
			}
			$t = get_term_by( 'name', $name, 'product_cat' );
			if ( $t instanceof WP_Term ) {
				$s['cat'] = (int) $t->term_id;
			} else {
				$new = wp_insert_term( mb_substr( $name, 0, 100 ), 'product_cat' );
				if ( is_wp_error( $new ) ) {
					return array( self::msg( 'error', $new->get_error_message() ) );
				}
				$s['cat'] = (int) $new['term_id'];
			}
		}
		$s['status'] = sanitize_key( self::post( 'tfi_status' ) );
		TFI_Settings::save( $s );
		TFI_Sync::start( true );
		return array();
	}

	/** The full records of remote ids, from the feed (by ISBN when every title has one). */
	private static function fetch( array $ids ): array {
		$want = array_flip( array_map( 'intval', $ids ) );
		$rows = array();
		$all  = true;
		foreach ( TFI_Sync::catalog() as $c ) {
			if ( isset( $want[ (int) $c['id'] ] ) ) {
				$rows[] = $c;
				$all    = $all && '' !== (string) $c['d'];
			}
		}
		$out = array();
		if ( $all ) {
			foreach ( array_chunk( array_column( $rows, 'd' ), 100 ) as $codes ) {
				$res = TFI_Client::products( 1, array( 'isbn' => implode( ',', $codes ) ) );
				if ( is_wp_error( $res ) ) {
					return array( 'error' => $res->get_error_message() );
				}
				foreach ( $res['items'] as $it ) {
					$r = TFI_Record::from( $it );
					if ( $r && isset( $want[ $r['id'] ] ) ) {
						$out[ $r['id'] ] = $r;
					}
				}
			}
			return $out;
		}
		for ( $page = 1; $page <= 30; $page++ ) {
			$res = TFI_Client::products( $page );
			if ( is_wp_error( $res ) ) {
				return array( 'error' => $res->get_error_message() );
			}
			foreach ( $res['items'] as $it ) {
				$r = TFI_Record::from( $it );
				if ( $r && isset( $want[ $r['id'] ] ) ) {
					$out[ $r['id'] ] = $r;
				}
			}
			if ( count( $out ) >= count( $want ) || $page >= (int) $res['total_pages'] ) {
				break;
			}
		}
		return $out;
	}

	private static function do_titles(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$ids  = isset( $_POST['tfi_ids'] ) && is_array( $_POST['tfi_ids'] ) ? array_slice( array_filter( array_map( 'intval', wp_unslash( $_POST['tfi_ids'] ) ) ), 0, 200 ) : array();
		$bulk = sanitize_key( self::post( 'tfi_bulk' ) );
		if ( ! $ids ) {
			return array( self::msg( 'warning', __( 'Δεν επιλέχθηκε κανένας τίτλος.', 'trade-feed-import' ) ) );
		}
		$ours = TFI_Importer::ours( $ids );
		$n    = 0;

		if ( 'unlink' === $bulk ) {
			foreach ( $ids as $id ) {
				if ( isset( $ours[ $id ] ) ) {
					TFI_Importer::unlink( $ours[ $id ] );
					++$n;
				}
			}
		} else {
			$recs = self::fetch( $ids );
			if ( isset( $recs['error'] ) ) {
				return array( self::msg( 'error', (string) $recs['error'] ) );
			}
			$where = TFI_Importer::lookup( array_values( $recs ) );
			foreach ( $recs as $id => $r ) {
				$w = $where[ $id ];
				if ( 'import' === $bulk && 'new' === $w['state'] ) {
					$res = TFI_Importer::import( $r );
					$n  += 'created' === $res['result'] ? 1 : 0;
				} elseif ( 'link' === $bulk && 'exists' === $w['state'] ) {
					$n += TFI_Importer::link( $w['pid'], $r ) ? 1 : 0;
				} elseif ( 'take' === $bulk && 'ours' === $w['state'] ) {
					$res = TFI_Importer::update( $w['pid'], $r, true );
					$n  += 'error' !== $res['result'] ? 1 : 0;
				}
			}
			$changed = TFI_Importer::take_changed();
			if ( $changed ) {
				do_action( 'noxpress_products_changed', $changed );
			}
		}
		/* translators: %d: number of titles */
		return array( self::msg( $n > 0 ? 'success' : 'info', sprintf( __( 'Έγινε σε %d τίτλους.', 'trade-feed-import' ), $n ) ) );
	}

	private static function do_save_settings(): array {
		$s              = TFI_Settings::get();
		$s['sync_on']   = '1' === self::post( 'tfi_sync_on' );
		$s['auto_new']  = '1' === self::post( 'tfi_auto_new' );
		$s['brand']     = '1' === self::post( 'tfi_brand' );
		$s['cost']      = '1' === self::post( 'tfi_cost' );
		$s['purge']     = '1' === self::post( 'tfi_purge' );
		$s['hour']      = (int) self::post( 'tfi_hour' );
		$s['gone']      = sanitize_key( self::post( 'tfi_gone' ) );
		$s['status']    = sanitize_key( self::post( 'tfi_status' ) );
		$s['cat']       = (int) self::post( 'tfi_cat' );
		$s['tax_class'] = sanitize_title( self::post( 'tfi_tax_class' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); ints only (TFI_Settings::sanitize()).
		$s['cat_map'] = isset( $_POST['tfi_map'] ) && is_array( $_POST['tfi_map'] ) ? array_map( 'intval', wp_unslash( $_POST['tfi_map'] ) ) : array();
		TFI_Settings::save( $s );
		TFI_Sync::reschedule_daily();
		return array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'trade-feed-import' ) ) );
	}

	public static function route_export(): void {
		if ( self::SLUG !== self::current_page() || '' === self::q( 'tfi_export' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( Trade_Feed_Import::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'trade-feed-import' ) );
		}
		$state = array(
			'plugin'  => 'trade-feed-import',
			'version' => TFI_VERSION,
			'options' => array(
				TFI_Settings::OPT => TFI_Settings::get(),
			),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="trade-feed-import-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['tfi_import_file']['error'] ) ? (int) $_FILES['tfi_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['tfi_import_file']['size'] ) ? (int) $_FILES['tfi_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'trade-feed-import' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['tfi_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['tfi_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'trade-feed-import' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['tfi_import_file']['tmp_name'] ), true );
		// phpcs:enable
		$blob = is_array( $state ) && isset( $state['options'][ TFI_Settings::OPT ] ) ? $state['options'][ TFI_Settings::OPT ] : null;
		if ( ! is_array( $blob ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Trade Feed Import).', 'trade-feed-import' ) ) );
		}
		TFI_Settings::save( $blob );
		TFI_Sync::reschedule_daily();
		return array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'trade-feed-import' ) ) );
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
			set_transient( 'tfi_aui_msg_' . get_current_user_id(), $msgs, 60 );
		}
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	private static function take_msgs(): array {
		$raw = get_transient( 'tfi_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'tfi_aui_msg_' . get_current_user_id() );
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
		<p class="tfi-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'trade-feed-import' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="tfi-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="tfi-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'trade-feed-import' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="tfi-footer-cta">
			<a class="tfi-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'trade-feed-import' ); ?>
			</a>
			<a class="tfi-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'trade-feed-import' ); ?>
			</a>
		</p>
		<?php
	}
}

<?php
/**
 * MMD_Admin_UI — menu, tabs, forms (Bible §3–§8, §11).
 *
 * Submenu «Maintenance Mode» of the shared "Noxpress" menu (admin_menu
 * priority 390), slug mmd-settings, capability manage_options. Tabs:
 *  - status  the switch, mode, start / end, Retry-After, preview
 *  - page    texts (Greek / English), language, logo, colours, countdown,
 *            contact
 *  - access  roles, IP list, preview links, wp/v2 REST
 *  - more    import from SeedProd / WP Maintenance, developers, language
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient mmd_aui_msg_{uid}).
 */

defined( 'ABSPATH' ) || exit;

final class MMD_Admin_UI {

	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'mmd-settings';
	const NONCE       = 'mmd_admin';
	const NONCE_FIELD = 'mmd_nonce';
	const PRIORITY    = 390;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), self::PRIORITY );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MMD_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_notices', array( __CLASS__, 'other_plugin_notice' ) );
	}

	/** Tab slug => label. */
	private static function tabs(): array {
		return array(
			'status' => __( 'Κατάσταση', 'maintenance-mode' ),
			'page'   => __( 'Σελίδα', 'maintenance-mode' ),
			'access' => __( 'Πρόσβαση', 'maintenance-mode' ),
			'more'   => __( 'Εισαγωγή και developers', 'maintenance-mode' ),
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
			__( 'Maintenance Mode', 'maintenance-mode' ),
			__( 'Maintenance Mode', 'maintenance-mode' ),
			Maintenance_Mode::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'maintenance-mode' ) . '</a>'
		);
		return $links;
	}

	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	private static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		return isset( self::tabs()[ $tab ] ) ? $tab : 'status';
	}

	private static function url( string $tab, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'mmd-admin', MMD_URL . 'assets/admin.css', array(), MMD_VERSION );
		if ( 'page' === self::current_tab() ) {
			wp_enqueue_media();
		}
		wp_enqueue_script( 'mmd-admin', MMD_URL . 'assets/admin.js', array(), MMD_VERSION, true );
	}

	/** While a plugin we replace is active, both would show a page (§14.17). */
	public static function other_plugin_notice(): void {
		if ( ! current_user_can( Maintenance_Mode::CAP ) ) {
			return;
		}
		$others = MMD_Import::active_others();
		if ( ! $others || 'off' === mmd_status()['state'] ) {
			return;
		}
		/* translators: %s: plugin names */
		echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'Maintenance Mode: είναι ενεργό και το %s. Απενεργοποίησέ το, ώστε οι επισκέπτες να μη βλέπουν δύο διαφορετικές σελίδες.', 'maintenance-mode' ), implode( ', ', $others ) ) ) . '</p></div>';
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Maintenance_Mode::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'maintenance-mode' ) );
		}
		MMD_Access::prune();
		$tab = self::current_tab();
		?>
		<div class="wrap mmd-wrap">
			<h1><?php esc_html_e( 'Maintenance Mode', 'maintenance-mode' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Σελίδα «Σύντομα κοντά σας» ή «Συντήρηση» για τους επισκέπτες. Εσύ, οι ρόλοι που διαλέγεις, οι IP της λίστας και όσοι έχουν σύνδεσμο προεπισκόπησης βλέπουν κανονικά το site. Login, REST API και πληρωμές δεν κόβονται ποτέ.', 'maintenance-mode' ); ?></p>
			<?php
			self::print_notices( self::take_msgs() );
			if ( Maintenance_Mode::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το MMD_DISABLE είναι ενεργό στο wp-config.php: το site είναι ανοιχτό σε όλους, ό,τι κι αν λένε οι ρυθμίσεις.', 'maintenance-mode' ) . '</p></div>';
			}
			?>
			<nav class="nav-tab-wrapper mmd-tabs">
				<?php
				foreach ( self::tabs() as $slug => $label ) {
					printf(
						'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
						esc_url( self::url( (string) $slug ) ),
						(string) $slug === $tab ? ' nav-tab-active' : '',
						esc_html( $label )
					);
				}
				?>
			</nav>
			<?php
			switch ( $tab ) {
				case 'page':
					self::render_page();
					break;
				case 'access':
					self::render_access();
					break;
				case 'more':
					self::render_more();
					break;
				default:
					self::render_status();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	private static function badge( string $text, string $kind ): string {
		return '<span class="mmd-badge mmd-badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function fmt(): string {
		return get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	}

	private static function checkbox( string $name, bool $on, string $label ): void {
		printf(
			'<label class="mmd-check"><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label )
		);
	}

	private static function radio( string $name, string $value, string $current, string $label ): void {
		printf(
			'<label class="mmd-check"><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $value, $current, false ),
			esc_html( $label )
		);
	}

	private static function form_head( string $action ): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		echo '<input type="hidden" name="mmd_action" value="' . esc_attr( $action ) . '" />';
	}

	private static function button_form( string $action, array $fields, string $label, string $class = 'button' ): void {
		echo '<form method="post" class="mmd-inline-form">';
		self::form_head( $action );
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '" />';
		}
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/** datetime-local value in the site time zone ('' for 0). */
	private static function local( int $t ): string {
		return $t > 0 ? wp_date( 'Y-m-d\TH:i', $t ) : '';
	}

	/** Unix time of a datetime-local value in the site time zone (0 for empty / bad). */
	private static function parse_local( string $v ): int {
		$v = trim( $v );
		if ( '' === $v ) {
			return 0;
		}
		$d = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $v, wp_timezone() );
		return $d ? $d->getTimestamp() : 0;
	}

	public static function view_url( string $mode = '' ): string {
		$args = array(
			'mmd-view'     => '1',
			'_wpnonce'     => wp_create_nonce( 'mmd_view' ),
		);
		if ( '' !== $mode ) {
			$args['mode'] = $mode;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/* ---------------------------------------------------------------------
	 * Status
	 * ------------------------------------------------------------------- */

	private static function state_line( array $st ): string {
		$mode = 'coming_soon' === $st['mode'] ? __( 'Σύντομα κοντά σας', 'maintenance-mode' ) : __( 'Συντήρηση', 'maintenance-mode' );
		switch ( $st['state'] ) {
			case 'on':
				/* translators: %s: mode name */
				$txt = sprintf( __( 'Κλειστό για τους επισκέπτες: «%s».', 'maintenance-mode' ), $mode );
				if ( $st['end'] ) {
					/* translators: %s: date */
					$txt .= ' ' . sprintf( __( 'Ανοίγει αυτόματα στις %s.', 'maintenance-mode' ), wp_date( self::fmt(), $st['end'] ) );
				}
				return self::badge( __( 'Κλειστό', 'maintenance-mode' ), 'danger' ) . ' ' . esc_html( $txt );
			case 'scheduled':
				/* translators: 1: mode name, 2: date */
				return self::badge( __( 'Προγραμματισμένο', 'maintenance-mode' ), 'warn' ) . ' ' . esc_html( sprintf( __( '«%1$s» από τις %2$s. Ως τότε το site είναι ανοιχτό.', 'maintenance-mode' ), $mode, wp_date( self::fmt(), $st['start'] ) ) );
			case 'ended':
				/* translators: %s: date */
				return self::badge( __( 'Ανοιχτό', 'maintenance-mode' ), 'ok' ) . ' ' . esc_html( sprintf( __( 'Η ώρα λήξης (%s) πέρασε: το site είναι ανοιχτό σε όλους.', 'maintenance-mode' ), wp_date( self::fmt(), $st['end'] ) ) );
			default:
				return self::badge( __( 'Ανοιχτό', 'maintenance-mode' ), 'ok' ) . ' ' . esc_html__( 'Οι επισκέπτες βλέπουν κανονικά το site.', 'maintenance-mode' );
		}
	}

	private static function render_status(): void {
		$s  = MMD_Settings::get();
		$st = mmd_status();
		echo '<div class="mmd-state">' . self::state_line( $st ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in state_line().
		?>
		<form method="post">
			<?php self::form_head( 'save_status' ); ?>
			<h2 class="mmd-h2"><?php esc_html_e( 'Διακόπτης', 'maintenance-mode' ); ?></h2>
			<div class="mmd-zone">
				<?php
				self::radio( 'mmd_on', '0', $s['on'] ? '1' : '0', __( 'Ανοιχτό: όλοι βλέπουν κανονικά το site', 'maintenance-mode' ) );
				self::radio( 'mmd_on', '1', $s['on'] ? '1' : '0', __( 'Κλειστό για τους επισκέπτες (από την ώρα έναρξης, αν υπάρχει)', 'maintenance-mode' ) );
				?>
			</div>

			<h2 class="mmd-h2"><?php esc_html_e( 'Λειτουργία', 'maintenance-mode' ); ?></h2>
			<div class="mmd-zone">
				<?php
				self::radio( 'mmd_mode', 'maintenance', $s['mode'], __( 'Συντήρηση: για site που ήδη λειτουργεί. Κωδικός 503 με Retry-After, ώστε οι μηχανές αναζήτησης να ξαναπεράσουν αργότερα χωρίς να χαθούν θέσεις.', 'maintenance-mode' ) );
				self::radio( 'mmd_mode', 'coming_soon', $s['mode'], __( 'Σύντομα κοντά σας: για νέο site που δεν έχει βγει. Κωδικός 200 με noindex, robots.txt με «Disallow: /», κλειστά feeds και sitemaps.', 'maintenance-mode' ) );
				?>
			</div>

			<h2 class="mmd-h2"><?php esc_html_e( 'Πρόγραμμα', 'maintenance-mode' ); ?></h2>
			<div class="mmd-zone">
				<div class="mmd-grid">
					<label class="mmd-field"><span class="mmd-label"><?php esc_html_e( 'Έναρξη (κενό = αμέσως)', 'maintenance-mode' ); ?></span>
						<input type="datetime-local" name="mmd_start" value="<?php echo esc_attr( self::local( $s['start'] ) ); ?>" /></label>
					<label class="mmd-field"><span class="mmd-label"><?php esc_html_e( 'Λήξη (κενό = ώσπου να το κλείσεις)', 'maintenance-mode' ); ?></span>
						<input type="datetime-local" name="mmd_end" value="<?php echo esc_attr( self::local( $s['end'] ) ); ?>" /></label>
					<label class="mmd-field"><span class="mmd-label"><?php esc_html_e( 'Retry-After χωρίς λήξη (ώρες, 1–72)', 'maintenance-mode' ); ?></span>
						<input type="number" class="mmd-num" name="mmd_retry" min="1" max="72" value="<?php echo (int) $s['retry']; ?>" /></label>
				</div>
				<?php /* translators: %s: time zone */ ?>
				<p class="description"><?php echo esc_html( sprintf( __( 'Ζώνη ώρας του site: %s. Η έναρξη και η λήξη ελέγχονται σε κάθε επίσκεψη, χωρίς cron.', 'maintenance-mode' ), wp_timezone_string() ) ); ?></p>
			</div>
			<p class="mmd-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'maintenance-mode' ); ?></button></p>
		</form>

		<h2 class="mmd-h2"><?php esc_html_e( 'Cache', 'maintenance-mode' ); ?></h2>
		<div class="mmd-zone">
			<p class="description"><?php esc_html_e( 'Με κάθε αποθήκευση, και στην έναρξη και στη λήξη, η page cache του site καθαρίζεται (Fast Pages, WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache), ώστε οι επισκέπτες να μη βλέπουν παλιές σελίδες. Όσο είναι κλειστό, καμία σελίδα δεν μπαίνει σε cache. Αν έχεις CDN που κρατά σελίδες (π.χ. Cloudflare με «Cache Everything»), καθάρισέ το κι εκεί.', 'maintenance-mode' ); ?></p>
			<?php self::button_form( 'purge', array(), __( 'Καθάρισμα cache τώρα', 'maintenance-mode' ) ); ?>
			<?php if ( is_file( ABSPATH . 'robots.txt' ) && 'coming_soon' === $s['mode'] ) : ?>
				<p><?php echo self::badge( __( 'Προσοχή', 'maintenance-mode' ), 'warn' ) . ' ' . esc_html__( 'Υπάρχει αρχείο robots.txt στη ρίζα του site: ο server το δίνει χωρίς το WordPress, οπότε το «Disallow: /» δεν μπαίνει. Η σελίδα έχει πάντως noindex.', 'maintenance-mode' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></p>
			<?php endif; ?>
		</div>

		<h2 class="mmd-h2"><?php esc_html_e( 'Προεπισκόπηση', 'maintenance-mode' ); ?></h2>
		<div class="mmd-zone">
			<p>
				<a class="button" href="<?php echo esc_url( self::view_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Δες τη σελίδα όπως ο επισκέπτης', 'maintenance-mode' ); ?></a>
			</p>
			<iframe class="mmd-frame" src="<?php echo esc_url( self::view_url() ); ?>" title="<?php esc_attr_e( 'Προεπισκόπηση', 'maintenance-mode' ); ?>" loading="lazy"></iframe>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------- */

	private static function image_field( string $name, int $id, string $label ): void {
		$src = $id ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
		?>
		<div class="mmd-field">
			<span class="mmd-label"><?php echo esc_html( $label ); ?></span>
			<img id="<?php echo esc_attr( $name ); ?>-thumb" class="mmd-thumb" src="<?php echo esc_url( (string) $src ); ?>" alt="" <?php echo $src ? '' : 'hidden'; ?> />
			<input type="hidden" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo (int) $id; ?>" />
			<button type="button" class="button" data-mmd-pick="<?php echo esc_attr( $name ); ?>"><?php esc_html_e( 'Επιλογή', 'maintenance-mode' ); ?></button>
			<button type="button" class="button-link" data-mmd-clear="<?php echo esc_attr( $name ); ?>"><?php esc_html_e( 'Αφαίρεση', 'maintenance-mode' ); ?></button>
		</div>
		<?php
	}

	private static function render_page(): void {
		$s   = MMD_Settings::get();
		$del = MMD_Page::defaults( $s['mode'], 'el' );
		$den = MMD_Page::defaults( $s['mode'], 'en' );
		?>
		<form method="post">
			<?php self::form_head( 'save_page' ); ?>
			<h2 class="mmd-h2"><?php esc_html_e( 'Κείμενα', 'maintenance-mode' ); ?></h2>
			<div class="mmd-zone">
				<span class="mmd-label"><?php esc_html_e( 'Γλώσσα της σελίδας', 'maintenance-mode' ); ?></span>
				<?php
				self::radio( 'mmd_lang', 'auto', $s['lang'], __( 'Αυτόματα (από τη γλώσσα του site)', 'maintenance-mode' ) );
				self::radio( 'mmd_lang', 'el', $s['lang'], 'Ελληνικά' );
				self::radio( 'mmd_lang', 'en', $s['lang'], 'English' );
				?>
				<label class="mmd-field"><span class="mmd-label"><?php esc_html_e( 'Τίτλος (ελληνικά)', 'maintenance-mode' ); ?></span>
					<input type="text" class="mmd-wide-input" name="mmd_title_el" maxlength="120" value="<?php echo esc_attr( $s['title_el'] ); ?>" placeholder="<?php echo esc_attr( $del['title'] ); ?>" /></label>
				<label class="mmd-field"><span class="mmd-label"><?php esc_html_e( 'Κείμενο (ελληνικά, επιτρέπεται απλό HTML)', 'maintenance-mode' ); ?></span>
					<textarea class="mmd-text-area" name="mmd_text_el" placeholder="<?php echo esc_attr( $del['text'] ); ?>"><?php echo esc_textarea( $s['text_el'] ); ?></textarea></label>
				<label class="mmd-field"><span class="mmd-label"><?php esc_html_e( 'Τίτλος (αγγλικά)', 'maintenance-mode' ); ?></span>
					<input type="text" class="mmd-wide-input" name="mmd_title_en" maxlength="120" value="<?php echo esc_attr( $s['title_en'] ); ?>" placeholder="<?php echo esc_attr( $den['title'] ); ?>" /></label>
				<label class="mmd-field"><span class="mmd-label"><?php esc_html_e( 'Κείμενο (αγγλικά)', 'maintenance-mode' ); ?></span>
					<textarea class="mmd-text-area" name="mmd_text_en" placeholder="<?php echo esc_attr( $den['text'] ); ?>"><?php echo esc_textarea( $s['text_en'] ); ?></textarea></label>
				<p class="description"><?php esc_html_e( 'Κενό πεδίο = το έτοιμο κείμενο της λειτουργίας. Κενό αγγλικό = δείχνει το ελληνικό. Έως 2.000 χαρακτήρες.', 'maintenance-mode' ); ?></p>
			</div>

			<h2 class="mmd-h2"><?php esc_html_e( 'Εμφάνιση', 'maintenance-mode' ); ?></h2>
			<div class="mmd-zone">
				<?php
				self::image_field( 'mmd_logo', $s['logo'], __( 'Λογότυπο (κενό = το εικονίδιο του site)', 'maintenance-mode' ) );
				self::image_field( 'mmd_bg_image', $s['bg_image'], __( 'Εικόνα φόντου (προαιρετική, σκουραίνει για να διαβάζεται το κείμενο)', 'maintenance-mode' ) );
				?>
				<div class="mmd-grid">
					<label class="mmd-field"><span class="mmd-label"><?php esc_html_e( 'Φόντο', 'maintenance-mode' ); ?></span>
						<input type="color" name="mmd_bg" value="<?php echo esc_attr( $s['bg'] ); ?>" /></label>
					<label class="mmd-field"><span class="mmd-label"><?php esc_html_e( 'Κείμενο', 'maintenance-mode' ); ?></span>
						<input type="color" name="mmd_fg" value="<?php echo esc_attr( $s['fg'] ); ?>" /></label>
					<label class="mmd-field"><span class="mmd-label"><?php esc_html_e( 'Τόνος (γραμμή, links)', 'maintenance-mode' ); ?></span>
						<input type="color" name="mmd_accent" value="<?php echo esc_attr( $s['accent'] ); ?>" /></label>
				</div>
				<?php self::checkbox( 'mmd_countdown', $s['countdown'], __( 'Αντίστροφη μέτρηση ως την ώρα λήξης (μόνο όταν υπάρχει λήξη)', 'maintenance-mode' ) ); ?>
			</div>

			<h2 class="mmd-h2"><?php esc_html_e( 'Επικοινωνία', 'maintenance-mode' ); ?></h2>
			<div class="mmd-zone">
				<div class="mmd-grid">
					<label class="mmd-field"><span class="mmd-label">Email</span>
						<input type="email" name="mmd_email" value="<?php echo esc_attr( $s['email'] ); ?>" /></label>
					<label class="mmd-field"><span class="mmd-label"><?php esc_html_e( 'Τηλέφωνο', 'maintenance-mode' ); ?></span>
						<input type="text" name="mmd_phone" maxlength="40" value="<?php echo esc_attr( $s['phone'] ); ?>" /></label>
				</div>
				<label class="mmd-field"><span class="mmd-label"><?php esc_html_e( 'Σύνδεσμοι (έως 6, ένας ανά γραμμή: Όνομα | https://…)', 'maintenance-mode' ); ?></span>
					<textarea class="mmd-text-area" name="mmd_links" placeholder="Instagram | https://instagram.com/…"><?php echo esc_textarea( implode( "\n", $s['links'] ) ); ?></textarea></label>
				<p class="description"><?php esc_html_e( 'Απλοί σύνδεσμοι, χωρίς φόρμα και χωρίς συλλογή email.', 'maintenance-mode' ); ?></p>
			</div>
			<p class="mmd-submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'maintenance-mode' ); ?></button>
				<a class="button" href="<?php echo esc_url( self::view_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Δες τη σελίδα όπως ο επισκέπτης', 'maintenance-mode' ); ?></a>
			</p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Access
	 * ------------------------------------------------------------------- */

	private static function render_access(): void {
		$s     = MMD_Settings::get();
		$links = MMD_Access::links();
		$ip    = MMD_Access::client_ip();
		?>
		<form method="post">
			<?php self::form_head( 'save_access' ); ?>
			<h2 class="mmd-h2"><?php esc_html_e( 'Ρόλοι που βλέπουν το site', 'maintenance-mode' ); ?></h2>
			<div class="mmd-zone">
				<?php
				foreach ( wp_roles()->get_names() as $role => $name ) {
					if ( 'administrator' === $role ) {
						echo '<label class="mmd-check"><input type="checkbox" checked disabled /> ' . esc_html( translate_user_role( $name ) ) . ' <span class="mmd-muted">' . esc_html__( '(πάντα)', 'maintenance-mode' ) . '</span></label>';
						continue;
					}
					printf(
						'<label class="mmd-check"><input type="checkbox" name="mmd_roles[]" value="%1$s"%2$s /> %3$s</label>',
						esc_attr( (string) $role ),
						checked( in_array( $role, $s['roles'], true ), true, false ),
						esc_html( translate_user_role( $name ) )
					);
				}
				?>
				<p class="description"><?php esc_html_e( 'Όποιος έχει δικαίωμα διαχείρισης του site βλέπει πάντα το site, ώστε να μην κλειδωθείς ποτέ έξω. Οι πελάτες με λογαριασμό βλέπουν τη σελίδα συντήρησης, εκτός αν τσεκάρεις τον ρόλο τους.', 'maintenance-mode' ); ?></p>
			</div>

			<h2 class="mmd-h2"><?php esc_html_e( 'Διευθύνσεις IP', 'maintenance-mode' ); ?></h2>
			<div class="mmd-zone">
				<textarea class="mmd-text-area" name="mmd_ips" placeholder="203.0.113.7&#10;198.51.100.0/24"><?php echo esc_textarea( implode( "\n", $s['ips'] ) ); ?></textarea>
				<?php /* translators: %s: IP address */ ?>
				<p class="description"><?php echo esc_html( sprintf( __( 'Μία ανά γραμμή, ακριβής διεύθυνση ή εύρος (CIDR), έως 50. Η δική σου τώρα: %s', 'maintenance-mode' ), '' !== $ip ? $ip : '—' ) ); ?></p>
			</div>

			<h2 class="mmd-h2"><?php esc_html_e( 'REST API', 'maintenance-mode' ); ?></h2>
			<div class="mmd-zone">
				<?php self::checkbox( 'mmd_rest_close', $s['rest_close'], __( 'Κλειστά τα routes wp/v2 (άρθρα, σελίδες, χρήστες) για επισκέπτες χωρίς σύνδεση, όσο το site είναι κλειστό', 'maintenance-mode' ) ); ?>
				<p class="description"><?php esc_html_e( 'Όλα τα άλλα routes μένουν ανοιχτά: πληρωμές, WooCommerce, Site Bridge, Broadcast, εφαρμογές.', 'maintenance-mode' ); ?></p>
			</div>
			<p class="mmd-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'maintenance-mode' ); ?></button></p>
		</form>

		<h2 class="mmd-h2"><?php esc_html_e( 'Σύνδεσμοι προεπισκόπησης', 'maintenance-mode' ); ?></h2>
		<div class="mmd-zone">
			<p class="description"><?php esc_html_e( 'Για τον πελάτη ή όποιον θέλεις να δει το site χωρίς λογαριασμό. Ο σύνδεσμος βάζει ένα cookie ως τη λήξη του· η ανάκληση τον σταματά αμέσως.', 'maintenance-mode' ); ?></p>
			<?php if ( $links ) : ?>
				<table class="widefat striped mmd-table">
					<thead><tr>
						<th><?php esc_html_e( 'Ετικέτα', 'maintenance-mode' ); ?></th>
						<th><?php esc_html_e( 'Λήξη', 'maintenance-mode' ); ?></th>
						<th><?php esc_html_e( 'Σύνδεσμος', 'maintenance-mode' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $links as $l ) : ?>
						<tr>
							<td><?php echo esc_html( '' !== $l['label'] ? $l['label'] : '—' ); ?></td>
							<td><?php echo $l['expires'] > time() ? esc_html( wp_date( self::fmt(), $l['expires'] ) ) : self::badge( __( 'Έληξε', 'maintenance-mode' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></td>
							<td>
								<?php if ( $l['expires'] > time() ) : ?>
									<input type="text" readonly class="mmd-copy" id="mmd-l-<?php echo esc_attr( $l['id'] ); ?>" value="<?php echo esc_attr( MMD_Access::url( $l ) ); ?>" />
									<button type="button" class="button-link" data-mmd-copy="mmd-l-<?php echo esc_attr( $l['id'] ); ?>" data-done="<?php esc_attr_e( 'Αντιγράφηκε', 'maintenance-mode' ); ?>"><?php esc_html_e( 'Αντιγραφή', 'maintenance-mode' ); ?></button>
								<?php endif; ?>
							</td>
							<td><?php self::button_form( 'revoke', array( 'mmd_link' => $l['id'] ), __( 'Ανάκληση', 'maintenance-mode' ), 'button-link' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<form method="post" class="mmd-grid">
				<?php self::form_head( 'new_link' ); ?>
				<label class="mmd-field"><span class="mmd-label"><?php esc_html_e( 'Ετικέτα (π.χ. «Πελάτης»)', 'maintenance-mode' ); ?></span>
					<input type="text" name="mmd_label" maxlength="60" /></label>
				<label class="mmd-field"><span class="mmd-label"><?php esc_html_e( 'Ισχύει για μέρες (1–90)', 'maintenance-mode' ); ?></span>
					<input type="number" class="mmd-num" name="mmd_days" min="1" max="90" value="14" /></label>
				<p class="mmd-field"><button type="submit" class="button"><?php esc_html_e( 'Νέος σύνδεσμος', 'maintenance-mode' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Import, developers, language
	 * ------------------------------------------------------------------- */

	private static function render_more(): void {
		$sources = MMD_Import::sources();
		?>
		<h2 class="mmd-h2"><?php esc_html_e( 'Εισαγωγή', 'maintenance-mode' ); ?></h2>
		<div class="mmd-zone">
			<p class="description"><?php esc_html_e( 'Αντιγράφει τίτλο, κείμενο, λογότυπο, χρώματα, λειτουργία και (από το WP Maintenance) την ώρα της αντίστροφης μέτρησης. Οι ρυθμίσεις του άλλου plugin δεν αλλάζουν και ο διακόπτης μένει όπως είναι.', 'maintenance-mode' ); ?></p>
			<?php
			if ( ! $sources ) {
				echo '<p>' . esc_html__( 'Δεν βρέθηκαν ρυθμίσεις από SeedProd Coming Soon (4.x–5.x) ή WP Maintenance σε αυτό το site.', 'maintenance-mode' ) . '</p>';
			}
			foreach ( $sources as $key => $name ) {
				/* translators: %s: plugin name */
				self::button_form( 'import', array( 'mmd_source' => $key ), sprintf( __( 'Εισαγωγή από %s', 'maintenance-mode' ), $name ) );
			}
			if ( MMD_Import::seedprod_builder() ) {
				echo '<p>' . self::badge( __( 'SeedProd 6+', 'maintenance-mode' ), 'warn' ) . ' ' . esc_html__( 'Αυτή η έκδοση του SeedProd φτιάχνει τη σελίδα στο δικό της builder και δεν εισάγεται. Αντέγραψε τα κείμενα με το χέρι στην καρτέλα «Σελίδα».', 'maintenance-mode' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
			}
			?>
		</div>

		<h2 class="mmd-h2"><?php esc_html_e( 'Για developers', 'maintenance-mode' ); ?></h2>
		<div class="mmd-zone">
			<table class="widefat striped mmd-table">
				<tbody>
					<tr><td><code>mmd_status()</code></td><td><?php esc_html_e( 'Η κατάσταση τώρα: state (off, scheduled, on, ended), active, mode, start, end.', 'maintenance-mode' ); ?></td></tr>
					<tr><td><code>noxpress_site_closed</code> <?php esc_html_e( '(φίλτρο)', 'maintenance-mode' ); ?></td><td><?php esc_html_e( 'Τι βλέπει το τρέχον αίτημα: \'\' (το site), coming_soon ή maintenance. Το διαβάζει το Search Signals για sitemaps και llms.txt.', 'maintenance-mode' ); ?></td></tr>
					<tr><td><code>mmd_allow_request</code> <?php esc_html_e( '(φίλτρο)', 'maintenance-mode' ); ?></td><td><?php esc_html_e( 'true = το αίτημα περνά, ενώ το site είναι κλειστό: ( $allow, $mode ).', 'maintenance-mode' ); ?></td></tr>
					<tr><td><code>mmd_page_text</code>, <code>mmd_page_html</code> <?php esc_html_e( '(φίλτρα)', 'maintenance-mode' ); ?></td><td><?php esc_html_e( 'Τίτλος / κείμενο ( $t, $mode, $lang ) και το τελικό HTML ( $html, $mmd ) της σελίδας.', 'maintenance-mode' ); ?></td></tr>
					<tr><td><code>maintenance-mode.php</code></td><td><?php esc_html_e( 'Αρχείο στο (child) θέμα: αντικαθιστά το πρότυπο της σελίδας, με τα ίδια δεδομένα ($mmd).', 'maintenance-mode' ); ?></td></tr>
					<tr><td><code>maintenance-mode/status, set</code></td><td><?php esc_html_e( 'Abilities (WordPress 6.9+) για το Site Bridge, με το ίδιο δικαίωμα (manage_options).', 'maintenance-mode' ); ?></td></tr>
					<tr><td><code>X-Maintenance-Mode</code></td><td><?php esc_html_e( 'Header της σελίδας: maintenance, coming_soon ή preview.', 'maintenance-mode' ); ?></td></tr>
					<tr><td><code>MMD_DISABLE</code></td><td><?php esc_html_e( 'Στο wp-config.php: το site ανοίγει σε όλους, ό,τι κι αν λένε οι ρυθμίσεις.', 'maintenance-mode' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
		if ( ! MMD_Lang::rs_active() ) {
			$cur = (string) get_user_meta( get_current_user_id(), 'rs_lang', true );
			echo '<form method="post">';
			self::form_head( 'save_lang' );
			echo '<h2 class="mmd-h2">' . esc_html__( 'Γλώσσα', 'maintenance-mode' ) . '</h2><div class="mmd-zone">';
			foreach ( array(
				''   => __( 'Όπως η γλώσσα του λογαριασμού', 'maintenance-mode' ),
				'el' => 'Ελληνικά',
				'en' => 'English',
			) as $k => $l ) {
				self::radio( 'mmd_ui_lang', (string) $k, in_array( $cur, array( 'el', 'en' ), true ) ? $cur : '', $l );
			}
			echo '<p class="description">' . esc_html__( 'Ισχύει για όλα τα plugins της σουίτας Noxpress.', 'maintenance-mode' ) . '</p></div>';
			echo '<p class="mmd-submit"><button type="submit" class="button">' . esc_html__( 'Αποθήκευση', 'maintenance-mode' ) . '</button></p></form>';
		}
	}

	/* =====================================================================
	 * POST routes (admin_init, PRG)
	 * =================================================================== */

	public static function route(): void {
		if ( self::SLUG !== self::current_page() || ! isset( $_POST[ self::NONCE_FIELD ], $_POST['mmd_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Maintenance_Mode::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'maintenance-mode' ) );
		}

		switch ( sanitize_key( wp_unslash( $_POST['mmd_action'] ) ) ) {
			case 'save_status':
				self::do_save_status();
				break;
			case 'purge':
				self::redirect( 'status', array( self::purged_msg( Maintenance_Mode::purge() ) ) );
				break;
			case 'save_page':
				self::do_save_page();
				break;
			case 'save_access':
				$s               = MMD_Settings::get();
				$s['roles']      = isset( $_POST['mmd_roles'] ) && is_array( $_POST['mmd_roles'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['mmd_roles'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key on each.
				$s['ips']        = sanitize_textarea_field( self::post( 'mmd_ips' ) );
				$s['rest_close'] = '1' === self::post( 'mmd_rest_close' );
				$saved           = MMD_Settings::save( $s );
				$msgs            = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'maintenance-mode' ) ) );
				$asked           = preg_split( '/[\s,]+/', trim( sanitize_textarea_field( self::post( 'mmd_ips' ) ) ) );
				$asked           = array_filter( is_array( $asked ) ? $asked : array() );
				if ( count( $asked ) > count( $saved['ips'] ) ) {
					$msgs[] = self::msg( 'warning', __( 'Κάποιες γραμμές δεν ήταν έγκυρες διευθύνσεις ή εύρη IP και αγνοήθηκαν.', 'maintenance-mode' ) );
				}
				self::redirect( 'access', $msgs );
				break;
			case 'new_link':
				$r = MMD_Access::create( sanitize_text_field( self::post( 'mmd_label' ) ), (int) self::post( 'mmd_days' ) );
				self::redirect( 'access', array( is_wp_error( $r ) ? self::msg( 'error', $r->get_error_message() ) : self::msg( 'success', __( 'Ο σύνδεσμος δημιουργήθηκε. Αντέγραψέ τον από τον πίνακα.', 'maintenance-mode' ) ) ) );
				break;
			case 'revoke':
				$ok = MMD_Access::revoke( sanitize_key( self::post( 'mmd_link' ) ) );
				self::redirect( 'access', array( $ok ? self::msg( 'success', __( 'Ο σύνδεσμος ανακλήθηκε.', 'maintenance-mode' ) ) : self::msg( 'error', __( 'Ο σύνδεσμος δεν βρέθηκε.', 'maintenance-mode' ) ) ) );
				break;
			case 'import':
				$src = sanitize_key( self::post( 'mmd_source' ) );
				if ( ! isset( MMD_Import::sources()[ $src ] ) ) {
					self::redirect( 'more', array( self::msg( 'error', __( 'Άγνωστη πηγή.', 'maintenance-mode' ) ) ) );
				}
				$keys = MMD_Import::apply( $src );
				/* translators: %d: number of settings */
				self::redirect( 'page', array( $keys ? self::msg( 'success', sprintf( __( 'Εισήχθησαν %d ρυθμίσεις. Έλεγξέ τες και αποθήκευσε.', 'maintenance-mode' ), count( $keys ) ) ) : self::msg( 'warning', __( 'Δεν βρέθηκε τίποτα για εισαγωγή.', 'maintenance-mode' ) ) ) );
				break;
			case 'save_lang':
				if ( ! MMD_Lang::rs_active() ) {
					$lang = self::post( 'mmd_ui_lang' );
					if ( in_array( $lang, array( 'el', 'en' ), true ) ) {
						update_user_meta( get_current_user_id(), 'rs_lang', $lang );
					} else {
						delete_user_meta( get_current_user_id(), 'rs_lang' );
					}
					MMD_Lang::reset();
				}
				self::redirect( 'more', array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'maintenance-mode' ) ) ) );
				break;
			default:
				self::redirect( 'status', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'maintenance-mode' ) ) ) );
		}
	}

	private static function do_save_status(): void {
		$s          = MMD_Settings::get();
		$on         = '1' === self::post( 'mmd_on' );
		$mode       = 'coming_soon' === self::post( 'mmd_mode' ) ? 'coming_soon' : 'maintenance';
		$start      = self::parse_local( sanitize_text_field( self::post( 'mmd_start' ) ) );
		$end        = self::parse_local( sanitize_text_field( self::post( 'mmd_end' ) ) );
		$s['retry'] = (int) self::post( 'mmd_retry' );
		$now        = time();
		if ( $start && $end && $end <= $start ) {
			self::redirect( 'status', array( self::msg( 'error', __( 'Η λήξη πρέπει να είναι μετά την έναρξη. Τίποτα δεν άλλαξε.', 'maintenance-mode' ) ) ) );
		}
		if ( $on && $end && $end <= $now ) {
			self::redirect( 'status', array( self::msg( 'error', __( 'Η ώρα λήξης έχει περάσει. Άλλαξέ την ή άφησέ την κενή. Τίποτα δεν άλλαξε.', 'maintenance-mode' ) ) ) );
		}
		if ( $start && $start <= $now ) {
			$start = 0; // A start in the past means «now».
		}
		$s['on']    = $on;
		$s['mode']  = $mode;
		$s['start'] = $start;
		$s['end']   = $end;
		MMD_Settings::save( $s );
		Maintenance_Mode::schedule();
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'maintenance-mode' ) ), self::purged_msg( Maintenance_Mode::purge() ) );
		self::redirect( 'status', $msgs );
	}

	private static function do_save_page(): void {
		$s = MMD_Settings::get();
		foreach ( array( 'title_el', 'title_en', 'text_el', 'text_en', 'bg', 'fg', 'accent', 'email', 'phone', 'links', 'lang' ) as $k ) {
			$s[ $k ] = self::post( 'mmd_' . $k ); // Validated in MMD_Settings::clean().
		}
		$s['logo']      = (int) self::post( 'mmd_logo' );
		$s['bg_image']  = (int) self::post( 'mmd_bg_image' );
		$s['countdown'] = '1' === self::post( 'mmd_countdown' );
		MMD_Settings::save( $s );
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'maintenance-mode' ) ) );
		if ( mmd_status()['active'] ) {
			Maintenance_Mode::purge();
		}
		self::redirect( 'page', $msgs );
	}

	/** @param string[] $names Caches that were purged. */
	private static function purged_msg( array $names ): array {
		if ( ! $names ) {
			return self::msg( 'info', __( 'Δεν βρέθηκε page cache για καθάρισμα.', 'maintenance-mode' ) );
		}
		/* translators: %s: cache names */
		return self::msg( 'success', sprintf( __( 'Καθαρίστηκε η cache: %s.', 'maintenance-mode' ), implode( ', ', $names ) ) );
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		if ( $msgs ) {
			set_transient( 'mmd_aui_msg_' . get_current_user_id(), $msgs, 60 );
		}
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	private static function take_msgs(): array {
		$raw = get_transient( 'mmd_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'mmd_aui_msg_' . get_current_user_id() );
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
		<p class="mmd-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'maintenance-mode' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="mmd-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="mmd-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'maintenance-mode' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="mmd-footer-cta">
			<a class="mmd-kofi" href="https://noxpress.tech/support/" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '♡ Στήριξε το Noxpress', 'maintenance-mode' ); ?>
			</a>
			<a class="mmd-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'maintenance-mode' ); ?>
			</a>
		</p>
		<?php
	}
}

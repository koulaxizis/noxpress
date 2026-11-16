<?php
/**
 * EBD_Admin_UI — menu, settings, library texts, limits, leak lookup,
 * status, tools (Bible §3–§8, §11).
 *
 * Submenu «Ebook Delivery» of the shared "Noxpress" menu (admin_menu
 * priority 360), slug ebd-settings, capability Ebook_Delivery::cap(). Tabs:
 *  - settings  mode (off / test / live), formats, name style, line size,
 *              size limit, cache days, clean file names and the author
 *              attribute, visitor language, watermark texts
 *  - library   the «Η βιβλιοθήκη μου» page and its texts, the e-mail link
 *  - limits    defaults for new downloadable products, bulk apply with
 *              backup, restore and download of the backup
 *  - leaks     «Ποιος το μοιράστηκε;»: a code or an uploaded file → order
 *  - status    checks, HTTP test of the private folder, cache, issue log
 *  - tools     test watermark (upload → stamped copy with sample data),
 *              settings backup, developers
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * messages in the transient ebd_aui_msg_{uid}). Downloads (stamped test
 * file, backups): privileged GET / POST with nonce. Uploaded files are read
 * from PHP's temporary file only and never stored.
 */

defined( 'ABSPATH' ) || exit;

final class EBD_Admin_UI {

	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'ebd-settings';
	const NONCE       = 'ebd_admin';
	const NONCE_FIELD = 'ebd_nonce';
	const MAX_IMPORT  = 262144;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 360 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( EBD_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label. */
	private static function tabs(): array {
		return array(
			'settings' => __( 'Υδατογράφημα', 'ebook-delivery' ),
			'library'  => __( 'Βιβλιοθήκη', 'ebook-delivery' ),
			'limits'   => __( 'Όρια λήψεων', 'ebook-delivery' ),
			'leaks'    => __( 'Ποιος το μοιράστηκε;', 'ebook-delivery' ),
			'status'   => __( 'Κατάσταση', 'ebook-delivery' ),
			'tools'    => __( 'Εργαλεία', 'ebook-delivery' ),
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
			__( 'Ebook Delivery', 'ebook-delivery' ),
			__( 'Ebook Delivery', 'ebook-delivery' ),
			Ebook_Delivery::cap(),
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'ebook-delivery' ) . '</a>'
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
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'ebd-admin', EBD_URL . 'assets/admin.css', array(), EBD_VERSION );
	}

	/** Suggested text for the site's privacy policy (Settings → Privacy). */
	public static function privacy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'Όταν κατεβάζεις ένα ψηφιακό βιβλίο (PDF ή EPUB) που αγόρασες, το αρχείο που παίρνεις έχει το όνομά σου, τον αριθμό της παραγγελίας και έναν κωδικό, ορατά στις σελίδες και μέσα στα στοιχεία του αρχείου (μαζί με το ονοματεπώνυμό σου). Έτσι προστατεύουμε τους δημιουργούς από την παράνομη διανομή. Το αντίγραφο με τα στοιχεία σου κρατιέται στον server μας για λίγες ημέρες, ώστε οι επόμενες λήψεις να είναι γρήγορες, και μετά σβήνεται. Ο κωδικός αντιστοιχεί στην παραγγελία σου μόνο για εμάς.', 'ebook-delivery' ) . '</p>';
		wp_add_privacy_policy_content( 'Ebook Delivery', wp_kses_post( $text ) );
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Ebook_Delivery::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'ebook-delivery' ), '', array( 'response' => 403 ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap ebd-wrap">
			<h1><?php esc_html_e( 'Ebook Delivery', 'ebook-delivery' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Κάθε PDF και EPUB που κατεβαίνει παίρνει το όνομα του αγοραστή, τον αριθμό της παραγγελίας και έναν κρυφό κωδικό. Οι λήψεις μένουν του WooCommerce: έλεγχος συνδέσμου, όρια, λήξη και ιστορικό δεν αλλάζουν.', 'ebook-delivery' ); ?></p>

			<?php
			self::print_notices( self::take_msgs() );
			if ( Ebook_Delivery::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το EBD_DISABLE είναι ενεργό στο wp-config.php: τα αρχεία στέλνονται χωρίς υδατογράφημα και η βιβλιοθήκη δεν εμφανίζεται.', 'ebook-delivery' ) . '</p></div>';
			}
			$issues = EBD_Store::issues();
			if ( $issues && $issues[0]['t'] > time() - 7 * DAY_IN_SECONDS && 'status' !== $tab ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Κάποια αρχεία στάλθηκαν χωρίς υδατογράφημα τις τελευταίες 7 ημέρες.', 'ebook-delivery' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=status' ) ) . '">' . esc_html__( 'Δες την Κατάσταση', 'ebook-delivery' ) . '</a></p></div>';
			}
			?>

			<nav class="nav-tab-wrapper ebd-tabs">
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
				case 'library':
					self::render_library();
					break;
				case 'limits':
					self::render_limits();
					break;
				case 'leaks':
					self::render_leaks();
					break;
				case 'status':
					self::render_status();
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
			'<label class="ebd-check"><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label )
		);
	}

	private static function radio( string $name, string $value, string $current, string $label ): void {
		printf(
			'<label class="ebd-check"><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $value, $current, false ),
			esc_html( $label )
		);
	}

	private static function badge( string $text, string $kind ): string {
		return '<span class="ebd-badge ebd-badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function fmt(): string {
		return get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	}

	/** One visitor text: Greek and English inputs. */
	private static function text_pair( string $key, string $label, array $s ): void {
		$max = EBD_Settings::texts()[ $key ][2];
		?>
		<span class="ebd-label"><?php echo esc_html( $label ); ?></span>
		<div class="ebd-pair">
			<label><span class="ebd-lang"><?php esc_html_e( 'Ελληνικά', 'ebook-delivery' ); ?></span>
				<input type="text" class="ebd-wide" name="ebd_t_<?php echo esc_attr( $key ); ?>_el" value="<?php echo esc_attr( $s[ 't_' . $key . '_el' ] ); ?>" maxlength="<?php echo (int) $max; ?>" /></label>
			<label><span class="ebd-lang"><?php esc_html_e( 'English (κενό = το ελληνικό)', 'ebook-delivery' ); ?></span>
				<input type="text" class="ebd-wide" name="ebd_t_<?php echo esc_attr( $key ); ?>_en" value="<?php echo esc_attr( $s[ 't_' . $key . '_en' ] ); ?>" maxlength="<?php echo (int) $max; ?>" /></label>
		</div>
		<?php
	}

	private static function form_open( string $action, bool $files = false ): void {
		echo '<form method="post"' . ( $files ? ' enctype="multipart/form-data"' : '' ) . '>';
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		echo '<input type="hidden" name="ebd_action" value="' . esc_attr( $action ) . '" />';
	}

	/* ---------------------------------------------------------------------
	 * Settings (watermark)
	 * ------------------------------------------------------------------- */

	private static function render_settings(): void {
		$s     = EBD_Settings::get();
		$attrs = function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array();
		self::form_open( 'save_settings' );
		?>
			<h2 class="ebd-h2"><?php esc_html_e( 'Λειτουργία', 'ebook-delivery' ); ?></h2>
			<div class="ebd-zone">
				<?php
				self::radio( 'ebd_mode', 'off', $s['mode'], __( 'Ανενεργό: τα αρχεία στέλνονται όπως πριν, χωρίς βιβλιοθήκη', 'ebook-delivery' ) );
				self::radio( 'ebd_mode', 'test', $s['mode'], __( 'Δοκιμή: υδατογράφημα και βιβλιοθήκη μόνο για τους διαχειριστές καταστήματος (κατέβασε ένα αρχείο από μια δική σου δοκιμαστική παραγγελία)', 'ebook-delivery' ) );
				self::radio( 'ebd_mode', 'live', $s['mode'], __( 'Ενεργό: για όλους τους αγοραστές', 'ebook-delivery' ) );
				?>
				<p class="description"><?php esc_html_e( 'Αν ένα αρχείο δεν δέχεται υδατογράφημα (κρυπτογραφημένο, χαλασμένο, πολύ μεγάλο), ο αγοραστής παίρνει το αρχικό και εσύ βλέπεις σημείωση στην Κατάσταση. Κανείς δεν μένει χωρίς το βιβλίο του.', 'ebook-delivery' ); ?></p>
			</div>

			<h2 class="ebd-h2"><?php esc_html_e( 'Αρχεία', 'ebook-delivery' ); ?></h2>
			<div class="ebd-zone">
				<?php
				self::checkbox( 'ebd_pdf', $s['pdf'], __( 'PDF: γραμμή στο κάτω μέρος κάθε σελίδας, κρυφός κωδικός και στοιχεία στα metadata', 'ebook-delivery' ) );
				self::checkbox( 'ebd_epub', $s['epub'], __( 'EPUB: σελίδα Ex libris μετά το εξώφυλλο, γραμμή στο τέλος κάθε κεφαλαίου και στοιχεία στα metadata', 'ebook-delivery' ) );
				?>
				<p class="description"><?php esc_html_e( 'Τα υπόλοιπα αρχεία (MP3, ZIP, MOBI…) στέλνονται όπως είναι. Ένα προϊόν εξαιρείται από το κουτί «Χωρίς υδατογράφημα» στις επιλογές λήψης του.', 'ebook-delivery' ); ?></p>
				<span class="ebd-label"><?php esc_html_e( 'Όνομα του αγοραστή στη γραμμή και στο Ex libris', 'ebook-delivery' ); ?></span>
				<?php
				self::radio( 'ebd_name_style', 'initial', $s['name_style'], __( 'Όνομα και αρχικό επωνύμου (Μαρία Π.)', 'ebook-delivery' ) );
				self::radio( 'ebd_name_style', 'full', $s['name_style'], __( 'Ονοματεπώνυμο (Μαρία Παπαδοπούλου)', 'ebook-delivery' ) );
				?>
				<p class="description"><?php esc_html_e( 'Τα metadata του αρχείου κρατούν πάντα το ονοματεπώνυμο. Το email δεν γράφεται πουθενά στο αρχείο.', 'ebook-delivery' ); ?></p>
				<span class="ebd-label"><?php esc_html_e( 'Μέγεθος γραμμάτων της γραμμής (5–12 pt)', 'ebook-delivery' ); ?></span>
				<input type="number" class="ebd-num" name="ebd_size" value="<?php echo esc_attr( (string) $s['size'] ); ?>" min="5" max="12" step="1" />
				<span class="ebd-label"><?php esc_html_e( 'Μέγιστο μέγεθος αρχείου για υδατογράφημα (MB, 1–2048)', 'ebook-delivery' ); ?></span>
				<input type="number" class="ebd-num" name="ebd_max_mb" value="<?php echo esc_attr( (string) $s['max_mb'] ); ?>" min="1" max="2048" step="1" />
				<span class="ebd-label"><?php esc_html_e( 'Ημέρες που κρατιέται ένα υδατογραφημένο αντίγραφο (1–30)', 'ebook-delivery' ); ?></span>
				<input type="number" class="ebd-num" name="ebd_cache_days" value="<?php echo esc_attr( (string) $s['cache_days'] ); ?>" min="1" max="30" step="1" />
				<p class="description"><?php esc_html_e( 'Το αντίγραφο φτιάχνεται στην πρώτη λήψη και ξαναχρησιμοποιείται στις επόμενες. Μετά σβήνεται και ξαναφτιάχνεται όταν χρειαστεί.', 'ebook-delivery' ); ?></p>
			</div>

			<h2 class="ebd-h2"><?php esc_html_e( 'Όνομα αρχείου', 'ebook-delivery' ); ?></h2>
			<div class="ebd-zone">
				<?php self::checkbox( 'ebd_clean_names', $s['clean_names'], __( 'Ο αγοραστής παίρνει «Συγγραφέας - Τίτλος.pdf» αντί για το όνομα του αρχείου στον server', 'ebook-delivery' ) ); ?>
				<span class="ebd-label"><?php esc_html_e( 'Χαρακτηριστικό με τον συγγραφέα', 'ebook-delivery' ); ?></span>
				<select name="ebd_author_tax">
					<option value=""><?php esc_html_e( '— Κανένα (μόνο ο τίτλος) —', 'ebook-delivery' ); ?></option>
					<?php foreach ( (array) $attrs as $a ) : ?>
						<?php $tax = 'pa_' . $a->attribute_name; ?>
						<option value="<?php echo esc_attr( $tax ); ?>"<?php selected( $s['author_tax'], $tax ); ?>><?php echo esc_html( $a->attribute_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Ισχύει για όλα τα αρχεία του προϊόντος, και για όσα δεν παίρνουν υδατογράφημα (π.χ. MP3). Όταν ένα προϊόν έχει πολλά αρχεία, μπαίνει και το όνομα του αρχείου.', 'ebook-delivery' ); ?></p>
			</div>

			<h2 class="ebd-h2"><?php esc_html_e( 'Κείμενα υδατογραφήματος', 'ebook-delivery' ); ?></h2>
			<div class="ebd-zone">
				<span class="ebd-label"><?php esc_html_e( 'Γλώσσα των κειμένων για τους αγοραστές', 'ebook-delivery' ); ?></span>
				<?php
				self::radio( 'ebd_lang', 'auto', $s['lang'], __( 'Αυτόματα, από τη γλώσσα του site', 'ebook-delivery' ) );
				self::radio( 'ebd_lang', 'el', $s['lang'], __( 'Ελληνικά', 'ebook-delivery' ) );
				self::radio( 'ebd_lang', 'en', $s['lang'], __( 'English', 'ebook-delivery' ) );
				?>
				<p class="description"><?php esc_html_e( 'Μεταβλητές: {name} (το όνομα όπως ορίστηκε παραπάνω), {order} (αριθμός παραγγελίας), {date} (ημερομηνία παραγγελίας), {site} (όνομα του site), {code} (ο κρυφός κωδικός).', 'ebook-delivery' ); ?></p>
				<?php
				self::text_pair( 'line', __( 'Γραμμή στις σελίδες (PDF) και στο τέλος των κεφαλαίων (EPUB)', 'ebook-delivery' ), $s );
				self::text_pair( 'ex_title', __( 'Τίτλος της σελίδας Ex libris (EPUB)', 'ebook-delivery' ), $s );
				self::text_pair( 'ex_owner', __( 'Ex libris: πρώτη γραμμή', 'ebook-delivery' ), $s );
				self::text_pair( 'ex_order', __( 'Ex libris: δεύτερη γραμμή', 'ebook-delivery' ), $s );
				self::text_pair( 'ex_note', __( 'Ex libris: σημείωση (κενό = καμία)', 'ebook-delivery' ), $s );
				?>
			</div>

			<p class="ebd-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'ebook-delivery' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Library
	 * ------------------------------------------------------------------- */

	private static function render_library(): void {
		$s   = EBD_Settings::get();
		$url = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( EBD_Library::endpoint() ) : '';
		self::form_open( 'save_library' );
		?>
			<h2 class="ebd-h2"><?php esc_html_e( 'Η βιβλιοθήκη μου', 'ebook-delivery' ); ?></h2>
			<div class="ebd-zone">
				<?php
				self::checkbox( 'ebd_library', $s['library'], __( 'Σελίδα με τα ψηφιακά αρχεία του πελάτη στον λογαριασμό του, ένα ανά τίτλο με εξώφυλλο', 'ebook-delivery' ) );
				self::checkbox( 'ebd_lib_replace', $s['lib_replace'], __( 'Αντικαθιστά την καρτέλα «Λήψεις» του WooCommerce (η διεύθυνσή της συνεχίζει να δουλεύει)', 'ebook-delivery' ) );
				self::checkbox( 'ebd_email_link', $s['email_link'], __( 'Σύνδεσμος στη βιβλιοθήκη στα email παραγγελίας που έχουν ψηφιακά αρχεία (πελάτες με λογαριασμό)', 'ebook-delivery' ) );
				?>
				<p class="description">
					<?php esc_html_e( 'Φαίνεται όταν η λειτουργία είναι «Ενεργό», ή «Δοκιμή» για τους διαχειριστές καταστήματος. Οι επισκέπτες χωρίς λογαριασμό κατεβάζουν όπως πριν από το email της παραγγελίας.', 'ebook-delivery' ); ?>
					<?php if ( '' !== $url ) : ?>
						<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $url ); ?></a>
					<?php endif; ?>
				</p>
			</div>

			<h2 class="ebd-h2"><?php esc_html_e( 'Κείμενα της βιβλιοθήκης', 'ebook-delivery' ); ?></h2>
			<div class="ebd-zone">
				<p class="description"><?php esc_html_e( 'Μεταβλητές: {date} (ημερομηνία), {n} (λήψεις που απομένουν), {tab} (το όνομα της καρτέλας).', 'ebook-delivery' ); ?></p>
				<?php
				self::text_pair( 'tab', __( 'Όνομα της καρτέλας και τίτλος της σελίδας', 'ebook-delivery' ), $s );
				self::text_pair( 'empty', __( 'Όταν δεν υπάρχει κανένα αρχείο', 'ebook-delivery' ), $s );
				self::text_pair( 'browse', __( 'Κουμπί προς το κατάστημα', 'ebook-delivery' ), $s );
				self::text_pair( 'bought', __( 'Ημερομηνία αγοράς', 'ebook-delivery' ), $s );
				self::text_pair( 'left', __( 'Λήψεις που απομένουν', 'ebook-delivery' ), $s );
				self::text_pair( 'unlimited', __( 'Χωρίς όριο λήψεων', 'ebook-delivery' ), $s );
				self::text_pair( 'expires', __( 'Λήξη πρόσβασης', 'ebook-delivery' ), $s );
				self::text_pair( 'email', __( 'Γραμμή στο email της παραγγελίας', 'ebook-delivery' ), $s );
				?>
			</div>
			<p class="ebd-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'ebook-delivery' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Limits
	 * ------------------------------------------------------------------- */

	private static function render_limits(): void {
		$o    = EBD_Limits::options();
		$bak  = EBD_Limits::backup();
		$prev = get_transient( 'ebd_preview_' . get_current_user_id() );
		?>
		<div class="ebd-zone">
			<p class="description"><?php esc_html_e( 'Το WooCommerce κρατά όριο λήψεων και λήξη σε κάθε προϊόν (Δεδομένα προϊόντος → Γενικά) και τα αντιγράφει σε κάθε αγορά. Για έναν πελάτη που έχασε το αρχείο του, η παραγγελία έχει το κουτί «Δικαιώματα λήψης», όπου αλλάζεις τις λήψεις που απομένουν και τη λήξη. Τίποτα εδώ δεν αλλάζει αγορές που έγιναν ήδη.', 'ebook-delivery' ); ?></p>
		</div>

		<?php self::form_open( 'save_limits' ); ?>
			<h2 class="ebd-h2"><?php esc_html_e( 'Προεπιλογές για νέα προϊόντα', 'ebook-delivery' ); ?></h2>
			<div class="ebd-zone">
				<?php self::checkbox( 'ebd_lim_on', $o['on'], __( 'Νέο downloadable προϊόν με κενά πεδία παίρνει αυτές τις τιμές', 'ebook-delivery' ) ); ?>
				<span class="ebd-label"><?php esc_html_e( 'Όριο λήψεων (κενό = απεριόριστες)', 'ebook-delivery' ); ?></span>
				<input type="number" class="ebd-num" name="ebd_lim_limit" value="<?php echo $o['limit'] > 0 ? esc_attr( (string) $o['limit'] ) : ''; ?>" min="1" max="1000" step="1" />
				<span class="ebd-label"><?php esc_html_e( 'Λήξη πρόσβασης σε ημέρες (κενό = χωρίς λήξη)', 'ebook-delivery' ); ?></span>
				<input type="number" class="ebd-num" name="ebd_lim_expiry" value="<?php echo $o['expiry'] > 0 ? esc_attr( (string) $o['expiry'] ) : ''; ?>" min="1" max="36500" step="1" />
				<p class="ebd-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'ebook-delivery' ); ?></button></p>
			</div>
		</form>

		<h2 class="ebd-h2"><?php esc_html_e( 'Μαζική εφαρμογή σε όλα τα downloadable προϊόντα', 'ebook-delivery' ); ?></h2>
		<div class="ebd-zone">
			<?php self::form_open( 'limits_preview' ); ?>
				<span class="ebd-label"><?php esc_html_e( 'Όριο λήψεων (κενό = απεριόριστες)', 'ebook-delivery' ); ?></span>
				<input type="number" class="ebd-num" name="ebd_bulk_limit" value="<?php echo is_array( $prev ) && $prev['limit'] > 0 ? esc_attr( (string) $prev['limit'] ) : ''; ?>" min="1" max="1000" step="1" />
				<span class="ebd-label"><?php esc_html_e( 'Λήξη πρόσβασης σε ημέρες (κενό = χωρίς λήξη)', 'ebook-delivery' ); ?></span>
				<input type="number" class="ebd-num" name="ebd_bulk_expiry" value="<?php echo is_array( $prev ) && $prev['expiry'] > 0 ? esc_attr( (string) $prev['expiry'] ) : ''; ?>" min="1" max="36500" step="1" />
				<p><button type="submit" class="button"><?php esc_html_e( 'Έλεγχος (χωρίς αλλαγές)', 'ebook-delivery' ); ?></button></p>
			</form>
			<?php if ( is_array( $prev ) ) : ?>
				<p>
					<?php
					/* translators: 1: products that change, 2: downloadable products */
					echo esc_html( sprintf( __( 'Θα αλλάξουν %1$s από %2$s downloadable προϊόντα.', 'ebook-delivery' ), number_format_i18n( (int) $prev['change'] ), number_format_i18n( (int) $prev['total'] ) ) );
					?>
				</p>
				<?php if ( (int) $prev['change'] > 0 ) : ?>
					<?php self::form_open( 'limits_apply' ); ?>
						<input type="hidden" name="ebd_bulk_limit" value="<?php echo esc_attr( (string) $prev['limit'] ); ?>" />
						<input type="hidden" name="ebd_bulk_expiry" value="<?php echo esc_attr( (string) $prev['expiry'] ); ?>" />
						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Εφαρμογή', 'ebook-delivery' ); ?></button>
						<span class="description"><?php esc_html_e( 'Οι προηγούμενες τιμές γράφονται πρώτα σε αντίγραφο, ώστε να επαναφέρονται.', 'ebook-delivery' ); ?></span></p>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<?php if ( $bak ) : ?>
			<h2 class="ebd-h2"><?php esc_html_e( 'Αντίγραφο της τελευταίας μαζικής εφαρμογής', 'ebook-delivery' ); ?></h2>
			<div class="ebd-zone">
				<p>
					<?php
					/* translators: 1: date, 2: number of products */
					echo esc_html( sprintf( __( '%1$s: %2$s προϊόντα.', 'ebook-delivery' ), wp_date( self::fmt(), (int) $bak['t'] ), number_format_i18n( count( $bak['items'] ) ) ) );
					?>
				</p>
				<?php self::form_open( 'limits_restore' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Επαναφορά', 'ebook-delivery' ); ?></button>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=limits&ebd_export=limits' ), self::NONCE ) ); ?>"><?php esc_html_e( 'Λήψη αντιγράφου (JSON)', 'ebook-delivery' ); ?></a>
				</form>
				<p class="description"><?php esc_html_e( 'Η επαναφορά αγγίζει μόνο προϊόντα που έχουν ακόμα τις τιμές της μαζικής εφαρμογής· όσα άλλαξες με το χέρι μένουν όπως είναι.', 'ebook-delivery' ); ?></p>
			</div>
		<?php endif; ?>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Leaks
	 * ------------------------------------------------------------------- */

	private static function render_leaks(): void {
		$res = get_transient( 'ebd_leak_' . get_current_user_id() );
		if ( is_array( $res ) ) {
			delete_transient( 'ebd_leak_' . get_current_user_id() );
		}
		?>
		<div class="ebd-zone">
			<p class="description"><?php esc_html_e( 'Βρήκες ένα βιβλίο σου σε κάποιο site; Γράψε τον κωδικό ED-… που υπάρχει στις σελίδες του (ή στη σελίδα Ex libris του EPUB), ή ανέβασε το αρχείο. Θα δεις από ποια παραγγελία βγήκε. Το αρχείο διαβάζεται μόνο για τον έλεγχο και δεν αποθηκεύεται.', 'ebook-delivery' ); ?></p>
			<?php self::form_open( 'leak_code' ); ?>
				<span class="ebd-label"><?php esc_html_e( 'Κωδικός', 'ebook-delivery' ); ?></span>
				<input type="text" name="ebd_code" class="ebd-code" maxlength="32" placeholder="ED-7K3QX9AB" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Αναζήτηση', 'ebook-delivery' ); ?></button>
			</form>
			<?php self::form_open( 'leak_file', true ); ?>
				<span class="ebd-label"><?php esc_html_e( 'ή αρχείο PDF / EPUB', 'ebook-delivery' ); ?></span>
				<input type="file" name="ebd_leak_file" accept=".pdf,.epub,application/pdf,application/epub+zip" />
				<button type="submit" class="button"><?php esc_html_e( 'Έλεγχος αρχείου', 'ebook-delivery' ); ?></button>
			</form>
		</div>

		<?php if ( is_array( $res ) ) : ?>
			<h2 class="ebd-h2"><?php esc_html_e( 'Αποτέλεσμα', 'ebook-delivery' ); ?></h2>
			<div class="ebd-zone">
				<?php if ( empty( $res['codes'] ) ) : ?>
					<p class="ebd-warn"><?php esc_html_e( 'Δεν βρέθηκε κωδικός. Το αρχείο ίσως δεν βγήκε από αυτό το site ή κάποιος έσβησε τα στοιχεία του.', 'ebook-delivery' ); ?></p>
				<?php endif; ?>
				<?php foreach ( (array) $res['codes'] as $code => $rows ) : ?>
					<p><strong><?php echo esc_html( (string) $code ); ?></strong></p>
					<?php if ( empty( $rows ) ) : ?>
						<p class="ebd-warn"><?php esc_html_e( 'Κανένα δικαίωμα λήψης του καταστήματος δεν έχει αυτόν τον κωδικό (άλλο site, ή η παραγγελία διαγράφηκε).', 'ebook-delivery' ); ?></p>
					<?php else : ?>
						<table class="widefat striped ebd-table">
							<thead><tr>
								<th><?php esc_html_e( 'Παραγγελία', 'ebook-delivery' ); ?></th>
								<th><?php esc_html_e( 'Πελάτης', 'ebook-delivery' ); ?></th>
								<th><?php esc_html_e( 'Προϊόν', 'ebook-delivery' ); ?></th>
								<th><?php esc_html_e( 'Λήψεις', 'ebook-delivery' ); ?></th>
								<th><?php esc_html_e( 'Πρόσβαση από', 'ebook-delivery' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $rows as $r ) : ?>
								<?php
								$order   = wc_get_order( (int) $r['order_id'] );
								$product = wc_get_product( (int) $r['product_id'] );
								?>
								<tr>
									<td>
										<?php if ( $order ) : ?>
											<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( (string) $order->get_order_number() ); ?></a>
										<?php else : ?>
											#<?php echo (int) $r['order_id']; ?>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $order ? trim( $order->get_formatted_billing_full_name() ) : '' ); ?><br /><span class="ebd-muted"><?php echo esc_html( (string) $r['user_email'] ); ?></span></td>
									<td><?php echo esc_html( $product ? wp_strip_all_tags( $product->get_name() ) : '#' . (int) $r['product_id'] ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $r['download_count'] ) ); ?></td>
									<td><?php echo esc_html( (string) $r['access_granted'] ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Status
	 * ------------------------------------------------------------------- */

	private static function render_status(): void {
		$s      = EBD_Settings::get();
		$st     = EBD_Store::stats();
		$dir    = EBD_Store::dir();
		$free   = '' !== $dir && is_dir( $dir ) && function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$web    = (string) get_option( 'ebd_web_test', '' );
		$issues = EBD_Store::issues();
		$labels = EBD_Store::reasons();
		$method = (string) get_option( 'woocommerce_file_download_method', 'force' );
		$modes  = array(
			'off'  => __( 'Ανενεργό', 'ebook-delivery' ),
			'test' => __( 'Δοκιμή', 'ebook-delivery' ),
			'live' => __( 'Ενεργό', 'ebook-delivery' ),
		);
		?>
		<h2 class="ebd-h2"><?php esc_html_e( 'Έλεγχος', 'ebook-delivery' ); ?></h2>
		<div class="ebd-zone ebd-status">
			<p><?php echo self::badge( $modes[ $s['mode'] ], 'live' === $s['mode'] ? 'ok' : ( 'test' === $s['mode'] ? 'warn' : 'muted' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?> <?php esc_html_e( 'Λειτουργία', 'ebook-delivery' ); ?></p>
			<p><?php echo class_exists( 'ZipArchive' ) ? self::badge( __( 'Ναι', 'ebook-delivery' ), 'ok' ) : self::badge( __( 'Όχι', 'ebook-delivery' ), 'danger' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php esc_html_e( 'Επέκταση PHP zip (για EPUB)', 'ebook-delivery' ); ?></p>
			<p><?php echo function_exists( 'gzcompress' ) ? self::badge( __( 'Ναι', 'ebook-delivery' ), 'ok' ) : self::badge( __( 'Όχι', 'ebook-delivery' ), 'danger' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php esc_html_e( 'Επέκταση PHP zlib (για PDF)', 'ebook-delivery' ); ?></p>
			<p><?php echo EBD_Store::ensure_dir() ? self::badge( __( 'Ναι', 'ebook-delivery' ), 'ok' ) : self::badge( __( 'Όχι', 'ebook-delivery' ), 'danger' ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php esc_html_e( 'Ο ιδιωτικός φάκελος γράφεται', 'ebook-delivery' ); ?> <code><?php echo esc_html( basename( $dir ) ); ?></code></p>
			<p>
				<?php
				if ( 'safe' === $web ) {
					echo self::badge( __( 'Ασφαλής', 'ebook-delivery' ), 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput
				} elseif ( 'exposed' === $web ) {
					echo self::badge( __( 'Ανοιχτός', 'ebook-delivery' ), 'danger' ); // phpcs:ignore WordPress.Security.EscapeOutput
				} else {
					echo self::badge( __( 'Άγνωστο', 'ebook-delivery' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				esc_html_e( 'Ο φάκελος δεν ανοίγει από τον browser (δοκιμή με HTTP)', 'ebook-delivery' );
				?>
			</p>
			<?php if ( 'exposed' === $web ) : ?>
				<p class="ebd-warn"><?php esc_html_e( 'Ο server σου αγνοεί το .htaccess (π.χ. nginx). Τα ονόματα των αρχείων δεν μαντεύονται, αλλά πρόσθεσε έναν κανόνα «deny all» για τον φάκελο στη ρύθμιση του server ή ζήτα το από τον host.', 'ebook-delivery' ); ?></p>
			<?php endif; ?>
			<?php self::form_open( 'web_test' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Δοκιμή φακέλου τώρα', 'ebook-delivery' ); ?></button>
			</form>
			<p class="description ebd-mt-8">
				<?php
				/* translators: %s: free disk space */
				echo esc_html( false !== $free ? sprintf( __( 'Ελεύθερος χώρος στον δίσκο: %s.', 'ebook-delivery' ), size_format( (float) $free ) ) : __( 'Ο ελεύθερος χώρος στον δίσκο δεν διαβάζεται σε αυτόν τον server.', 'ebook-delivery' ) );
				echo ' ';
				/* translators: %s: WooCommerce download method */
				echo esc_html( sprintf( __( 'Μέθοδος λήψης του WooCommerce: %s (για τα υδατογραφημένα αρχεία πάντα «Force downloads»).', 'ebook-delivery' ), $method ) );
				?>
			</p>
		</div>

		<h2 class="ebd-h2"><?php esc_html_e( 'Αντίγραφα στον server', 'ebook-delivery' ); ?></h2>
		<div class="ebd-zone">
			<p>
				<?php
				/* translators: 1: number of files, 2: total size */
				echo esc_html( sprintf( __( '%1$s αρχεία, %2$s.', 'ebook-delivery' ), number_format_i18n( $st['count'] ), size_format( $st['bytes'] ) ) );
				?>
			</p>
			<?php self::form_open( 'purge_cache' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Διαγραφή όλων των αντιγράφων', 'ebook-delivery' ); ?></button>
				<span class="description"><?php esc_html_e( 'Ξαναφτιάχνονται στην επόμενη λήψη. Χρήσιμο αφού αλλάξεις ένα αρχείο-πηγή με το ίδιο όνομα και μέγεθος.', 'ebook-delivery' ); ?></span>
			</form>
		</div>

		<h2 class="ebd-h2"><?php esc_html_e( 'Αρχεία που στάλθηκαν χωρίς υδατογράφημα', 'ebook-delivery' ); ?></h2>
		<table class="widefat striped ebd-table">
			<thead><tr>
				<th><?php esc_html_e( 'Ώρα', 'ebook-delivery' ); ?></th>
				<th><?php esc_html_e( 'Προϊόν', 'ebook-delivery' ); ?></th>
				<th><?php esc_html_e( 'Αρχείο', 'ebook-delivery' ); ?></th>
				<th><?php esc_html_e( 'Αιτία', 'ebook-delivery' ); ?></th>
			</tr></thead>
			<tbody>
				<?php if ( ! $issues ) : ?>
					<tr><td colspan="4" class="ebd-empty"><?php esc_html_e( 'Κανένα. Όλα τα PDF και EPUB στάλθηκαν με υδατογράφημα.', 'ebook-delivery' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $issues as $r ) : ?>
					<?php $p = wc_get_product( $r['p'] ); ?>
					<tr>
						<td><?php echo esc_html( wp_date( self::fmt(), $r['t'] ) ); ?></td>
						<td>
							<?php if ( $p ) : ?>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $p->get_parent_id() ? $p->get_parent_id() : $p->get_id() ) ); ?>"><?php echo esc_html( wp_strip_all_tags( $p->get_name() ) ); ?></a>
							<?php else : ?>
								#<?php echo (int) $r['p']; ?>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $r['f'] ); ?></code></td>
						<td class="is-retry"><?php echo esc_html( $labels[ $r['r'] ] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $issues ) : ?>
			<?php self::form_open( 'clear_issues' ); ?>
				<p class="ebd-mt-8"><button type="submit" class="button"><?php esc_html_e( 'Καθαρισμός λίστας', 'ebook-delivery' ); ?></button></p>
			</form>
		<?php endif; ?>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tools
	 * ------------------------------------------------------------------- */

	private static function render_tools(): void {
		?>
		<h2 class="ebd-h2"><?php esc_html_e( 'Δοκιμή υδατογραφήματος', 'ebook-delivery' ); ?></h2>
		<div class="ebd-zone">
			<p class="description"><?php esc_html_e( 'Ανέβασε ένα PDF ή EPUB και κατέβασε αμέσως το αντίγραφο με δοκιμαστικά στοιχεία (Μαρία Παπαδοπούλου, παραγγελία #1234), με τις τωρινές ρυθμίσεις. Δεν αποθηκεύεται τίποτα.', 'ebook-delivery' ); ?></p>
			<?php self::form_open( 'test_stamp', true ); ?>
				<input type="file" name="ebd_test_file" accept=".pdf,.epub,application/pdf,application/epub+zip" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Δοκιμή', 'ebook-delivery' ); ?></button>
			</form>
		</div>

		<h2 class="ebd-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'ebook-delivery' ); ?></h2>
		<div class="ebd-zone">
			<?php self::form_open( 'import', true ); ?>
				<input type="file" name="ebd_import_file" accept=".json,application/json" />
				<button type="submit" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'ebook-delivery' ); ?></button>
			</form>
			<a class="button ebd-mt-8" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=tools&ebd_export=settings' ), self::NONCE ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'ebook-delivery' ); ?></a>
			<p class="description ebd-mt-8"><?php esc_html_e( 'Ρυθμίσεις και προεπιλογές ορίων. Το μυστικό κλειδί των κωδικών δεν μπαίνει στο αρχείο: σε άλλο site οι κωδικοί είναι άλλοι.', 'ebook-delivery' ); ?></p>
		</div>

		<h2 class="ebd-h2"><?php esc_html_e( 'Για developers', 'ebook-delivery' ); ?></h2>
		<div class="ebd-zone">
			<table class="widefat striped ebd-table">
				<tbody>
					<tr><td><code>ebd_should_stamp</code> <?php esc_html_e( '(φίλτρο)', 'ebook-delivery' ); ?></td><td><?php esc_html_e( 'Αν μια λήψη παίρνει υδατογράφημα: ( $stamp, $product, $download, $order ).', 'ebook-delivery' ); ?></td></tr>
					<tr><td><code>ebd_line_text</code> <?php esc_html_e( '(φίλτρο)', 'ebook-delivery' ); ?></td><td><?php esc_html_e( 'Η ορατή γραμμή: ( $text, $context ).', 'ebook-delivery' ); ?></td></tr>
					<tr><td><code>ebd_download_name</code> <?php esc_html_e( '(φίλτρο)', 'ebook-delivery' ); ?></td><td><?php esc_html_e( 'Το καθαρό όνομα αρχείου χωρίς κατάληξη: ( $name, $product, $ext ).', 'ebook-delivery' ); ?></td></tr>
					<tr><td><code>ebd_library_items</code> <?php esc_html_e( '(φίλτρο)', 'ebook-delivery' ); ?></td><td><?php esc_html_e( 'Οι τίτλοι της βιβλιοθήκης: ( $items, $user_id ).', 'ebook-delivery' ); ?></td></tr>
					<tr><td><code>ebd_library_endpoint</code> <?php esc_html_e( '(φίλτρο)', 'ebook-delivery' ); ?></td><td><?php esc_html_e( 'Το slug της σελίδας στον λογαριασμό (προεπιλογή library). Μετά την αλλαγή αποθήκευσε τα permalinks.', 'ebook-delivery' ); ?></td></tr>
					<tr><td><code>ebd_text</code> <?php esc_html_e( '(φίλτρο)', 'ebook-delivery' ); ?></td><td><?php esc_html_e( 'Αλλάζει ένα κείμενο του επισκέπτη: ( $text, $key, $lang ).', 'ebook-delivery' ); ?></td></tr>
					<tr><td><code>ebd_stamped</code> <?php esc_html_e( '(action)', 'ebook-delivery' ); ?></td><td><?php esc_html_e( 'Μετά από κάθε νέο αντίγραφο: ( $path, $context ).', 'ebook-delivery' ); ?></td></tr>
					<tr><td><code>EBD_DISABLE</code></td><td><?php esc_html_e( 'Στο wp-config.php: τα αρχεία στέλνονται χωρίς υδατογράφημα και η βιβλιοθήκη δεν εμφανίζεται.', 'ebook-delivery' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* =====================================================================
	 * POST routes (admin_init, PRG)
	 * =================================================================== */

	public static function route(): void {
		if ( self::SLUG !== self::current_page() || ! isset( $_POST[ self::NONCE_FIELD ], $_POST['ebd_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Ebook_Delivery::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'ebook-delivery' ), '', array( 'response' => 403 ) );
		}

		switch ( sanitize_key( wp_unslash( $_POST['ebd_action'] ) ) ) {
			case 'save_settings':
				self::redirect( 'settings', self::do_save( 'settings' ) );
				break;
			case 'save_library':
				self::redirect( 'library', self::do_save( 'library' ) );
				break;
			case 'save_limits':
				EBD_Limits::save_options( '1' === self::post( 'ebd_lim_on' ), (int) self::post( 'ebd_lim_limit' ), (int) self::post( 'ebd_lim_expiry' ) );
				self::redirect( 'limits', array( self::msg( 'success', __( 'Οι προεπιλογές αποθηκεύτηκαν.', 'ebook-delivery' ) ) ) );
				break;
			case 'limits_preview':
				$limit  = (int) self::post( 'ebd_bulk_limit' );
				$expiry = (int) self::post( 'ebd_bulk_expiry' );
				$p      = EBD_Limits::preview( $limit, $expiry );
				set_transient(
					'ebd_preview_' . get_current_user_id(),
					array_merge(
						$p,
						array(
							'limit'  => $limit,
							'expiry' => $expiry,
						)
					),
					HOUR_IN_SECONDS
				);
				self::redirect( 'limits', array() );
				break;
			case 'limits_apply':
				$r = EBD_Limits::apply( (int) self::post( 'ebd_bulk_limit' ), (int) self::post( 'ebd_bulk_expiry' ) );
				delete_transient( 'ebd_preview_' . get_current_user_id() );
				$msgs = array();
				if ( '' !== $r['error'] ) {
					$msgs[] = self::msg( 'error', __( 'Το αντίγραφο δεν γράφτηκε: η εφαρμογή σταμάτησε και ό,τι είχε αλλάξει επανήλθε.', 'ebook-delivery' ) );
				} else {
					/* translators: %s: number of products */
					$msgs[] = self::msg( 'success', sprintf( __( 'Άλλαξαν %s προϊόντα.', 'ebook-delivery' ), number_format_i18n( $r['changed'] ) ) );
				}
				self::redirect( 'limits', $msgs );
				break;
			case 'limits_restore':
				$r = EBD_Limits::restore();
				/* translators: 1: restored products, 2: skipped products */
				self::redirect( 'limits', array( self::msg( 'success', sprintf( __( 'Επαναφέρθηκαν %1$s προϊόντα, %2$s έμειναν όπως είναι.', 'ebook-delivery' ), number_format_i18n( $r['restored'] ), number_format_i18n( $r['skipped'] ) ) ) ) );
				break;
			case 'leak_code':
				$code = EBD_Code::normalise( self::post( 'ebd_code' ) );
				if ( '' === $code ) {
					self::redirect( 'leaks', array( self::msg( 'error', __( 'Ο κωδικός έχει τη μορφή ED- και 8 χαρακτήρες.', 'ebook-delivery' ) ) ) );
				}
				self::leak_result( array( $code ) );
				break;
			case 'leak_file':
				$tmp = self::uploaded( 'ebd_leak_file', 2147483647 );
				if ( '' === $tmp ) {
					self::redirect( 'leaks', array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο.', 'ebook-delivery' ) ) ) );
				}
				Ebook_Delivery::load_engine();
				$head  = (string) file_get_contents( $tmp, false, null, 0, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$codes = 'PK' === substr( $head, 0, 2 ) ? EBD_Epub::find_codes( $tmp, EBD_Code::PATTERN ) : EBD_Pdf_Stamp::find_codes( $tmp, EBD_Code::PATTERN );
				self::leak_result( $codes );
				break;
			case 'web_test':
				$r = EBD_Store::web_test();
				update_option( 'ebd_web_test', $r, false );
				self::redirect( 'status', array() );
				break;
			case 'purge_cache':
				$n = EBD_Store::cleanup( true );
				/* translators: %s: number of files */
				self::redirect( 'status', array( self::msg( 'success', sprintf( __( 'Διαγράφηκαν %s αρχεία.', 'ebook-delivery' ), number_format_i18n( $n ) ) ) ) );
				break;
			case 'clear_issues':
				update_option( EBD_Store::ISSUES, array(), false );
				self::redirect( 'status', array( self::msg( 'success', __( 'Η λίστα καθαρίστηκε.', 'ebook-delivery' ) ) ) );
				break;
			case 'test_stamp':
				self::do_test_stamp();
				break;
			case 'import':
				self::redirect( 'tools', self::do_import() );
				break;
			default:
				self::redirect( 'settings', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'ebook-delivery' ) ) ) );
		}
	}

	private static function do_save( string $which ): array {
		$s = EBD_Settings::get();
		if ( 'settings' === $which ) {
			$s['mode']        = sanitize_key( self::post( 'ebd_mode' ) );
			$s['lang']        = sanitize_key( self::post( 'ebd_lang' ) );
			$s['name_style']  = sanitize_key( self::post( 'ebd_name_style' ) );
			$s['pdf']         = '1' === self::post( 'ebd_pdf' );
			$s['epub']        = '1' === self::post( 'ebd_epub' );
			$s['clean_names'] = '1' === self::post( 'ebd_clean_names' );
			$s['author_tax']  = sanitize_key( self::post( 'ebd_author_tax' ) );
			$s['size']        = (int) self::post( 'ebd_size' );
			$s['max_mb']      = (int) self::post( 'ebd_max_mb' );
			$s['cache_days']  = (int) self::post( 'ebd_cache_days' );
			$keys             = array( 'line', 'ex_title', 'ex_owner', 'ex_order', 'ex_note' );
		} else {
			$s['library']     = '1' === self::post( 'ebd_library' );
			$s['lib_replace'] = '1' === self::post( 'ebd_lib_replace' );
			$s['email_link']  = '1' === self::post( 'ebd_email_link' );
			$keys             = array( 'tab', 'empty', 'browse', 'bought', 'left', 'unlimited', 'expires', 'email' );
		}
		foreach ( $keys as $k ) {
			foreach ( array( 'el', 'en' ) as $l ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
				if ( isset( $_POST[ 'ebd_t_' . $k . '_' . $l ] ) ) {
					$s[ 't_' . $k . '_' . $l ] = self::post( 'ebd_t_' . $k . '_' . $l );
				}
			}
		}
		EBD_Settings::save( $s );
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'ebook-delivery' ) ) );
		$s    = EBD_Settings::get();
		if ( 'settings' === $which && false === strpos( $s['t_line_el'] . $s['t_line_en'], '{code}' ) ) {
			$msgs[] = self::msg( 'warning', __( 'Η γραμμή δεν έχει το {code}: ο κωδικός μένει μόνο κρυφός μέσα στο αρχείο.', 'ebook-delivery' ) );
		}
		if ( 'settings' === $which && $s['epub'] && ! class_exists( 'ZipArchive' ) ) {
			$msgs[] = self::msg( 'warning', __( 'Ο server δεν έχει την επέκταση PHP zip: τα EPUB στέλνονται χωρίς υδατογράφημα.', 'ebook-delivery' ) );
		}
		return $msgs;
	}

	/**
	 * Looks codes up and shows the result (one transient read on the next
	 * page; it holds order numbers and e-mails for at most a minute).
	 *
	 * @param string[] $codes Codes.
	 */
	private static function leak_result( array $codes ): void {
		$out = array( 'codes' => array() );
		foreach ( array_slice( array_unique( $codes ), 0, 10 ) as $c ) {
			$c = EBD_Code::normalise( (string) $c );
			if ( '' !== $c ) {
				$out['codes'][ $c ] = EBD_Code::lookup( $c );
			}
		}
		set_transient( 'ebd_leak_' . get_current_user_id(), $out, MINUTE_IN_SECONDS );
		self::redirect( 'leaks', array() );
	}

	/** Stamps an uploaded file with sample data and sends it back. */
	private static function do_test_stamp(): void {
		$tmp = self::uploaded( 'ebd_test_file', (int) EBD_Settings::get()['max_mb'] * 1048576 );
		if ( '' === $tmp ) {
			self::redirect( 'tools', array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο ή είναι μεγαλύτερο από το όριο μεγέθους.', 'ebook-delivery' ) ) ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route().
		$orig = isset( $_FILES['ebd_test_file']['name'] ) ? sanitize_file_name( wp_unslash( (string) $_FILES['ebd_test_file']['name'] ) ) : 'test';
		$head = (string) file_get_contents( $tmp, false, null, 0, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$ext  = 'PK' === substr( $head, 0, 2 ) ? 'epub' : 'pdf';
		$out  = wp_tempnam( 'ebd-test' );
		Ebook_Delivery::load_engine();
		$lang = EBD_Settings::visitor_lang();
		$s    = EBD_Settings::get();
		$name = 'initial' === $s['name_style'] ? 'Μαρία Π.' : 'Μαρία Παπαδοπούλου';
		$vars = array(
			'name'  => $name,
			'order' => '1234',
			'date'  => wp_date( (string) get_option( 'date_format' ) ),
			'site'  => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'code'  => 'ED-TEST0000',
		);
		try {
			if ( 'pdf' === $ext ) {
				EBD_Pdf_Stamp::stamp(
					$tmp,
					$out,
					array(
						'text' => EBD_Settings::text( 'line', $vars, $lang ),
						'code' => $vars['code'],
						'name' => 'Μαρία Παπαδοπούλου',
						'size' => (int) $s['size'],
					)
				);
			} else {
				EBD_Epub::stamp(
					$tmp,
					$out,
					array(
						'text'    => EBD_Settings::text( 'line', $vars, $lang ),
						'code'    => $vars['code'],
						'name'    => 'Μαρία Παπαδοπούλου',
						'lang'    => $lang,
						'heading' => EBD_Settings::text( 'ex_title', $vars, $lang ),
						'rows'    => array_values( array_filter( array( EBD_Settings::text( 'ex_owner', $vars, $lang ), EBD_Settings::text( 'ex_order', $vars, $lang ), $vars['site'], EBD_Settings::text( 'ex_note', $vars, $lang ) ) ) ),
					)
				);
			}
		} catch ( \Throwable $e ) {
			@unlink( $out ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			$reason = ( $e instanceof EBD_Pdf_Error || $e instanceof EBD_Epub_Error ) ? $e->reason : 'error';
			$labels = EBD_Store::reasons();
			self::redirect( 'tools', array( self::msg( 'error', $labels[ $reason ] ?? $labels['error'] ) ) );
		}
		nocache_headers();
		header( 'Content-Type: ' . ( 'pdf' === $ext ? 'application/pdf' : 'application/epub+zip' ) );
		header( 'Content-Disposition: attachment; filename="' . EBD_Delivery_Mask_Name::safe( 'watermark-' . preg_replace( '/\.(pdf|epub)$/i', '', $orig ) . '.' . $ext ) . '"' );
		header( 'Content-Length: ' . (int) filesize( $out ) );
		readfile( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		@unlink( $out ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		exit;
	}

	/** Path of a valid uploaded file (PHP's temporary file), '' otherwise. */
	private static function uploaded( string $field, int $max ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES[ $field ]['error'] ) ? (int) $_FILES[ $field ]['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES[ $field ]['size'] ) ? (int) $_FILES[ $field ]['size'] : 0;
		$tmp  = isset( $_FILES[ $field ]['tmp_name'] ) ? (string) $_FILES[ $field ]['tmp_name'] : '';
		// phpcs:enable
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || $size > $max || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return '';
		}
		return $tmp;
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		if ( $msgs ) {
			set_transient( 'ebd_aui_msg_' . get_current_user_id(), $msgs, 60 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/* =====================================================================
	 * Backup (export GET / import POST)
	 * =================================================================== */

	public static function route_export(): void {
		if ( self::SLUG !== self::current_page() || '' === self::q( 'ebd_export' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE ) || ! current_user_can( Ebook_Delivery::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'ebook-delivery' ), '', array( 'response' => 403 ) );
		}
		if ( 'limits' === self::q( 'ebd_export' ) ) {
			$state = array(
				'plugin'  => 'ebook-delivery',
				'version' => EBD_VERSION,
				'backup'  => EBD_Limits::backup(),
			);
			$file  = 'ebook-delivery-limits-backup-' . gmdate( 'Y-m-d' ) . '.json';
		} else {
			$state = array(
				'plugin'  => 'ebook-delivery',
				'version' => EBD_VERSION,
				'options' => array(
					EBD_Settings::OPT => EBD_Settings::get(),
					EBD_Limits::OPT   => EBD_Limits::options(),
				),
			);
			$file  = 'ebook-delivery-settings-' . gmdate( 'Y-m-d' ) . '.json';
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	/** Strict import through the sanitizers; an invalid file changes nothing. */
	private static function do_import(): array {
		$tmp = self::uploaded( 'ebd_import_file', self::MAX_IMPORT );
		if ( '' === $tmp ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'ebook-delivery' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $tmp ), true );
		if ( ! is_array( $state ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'ebook-delivery' ) ) );
		}
		$blob = $state['options'][ EBD_Settings::OPT ] ?? null;
		if ( ! is_array( $blob ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Ebook Delivery).', 'ebook-delivery' ) ) );
		}
		EBD_Settings::save( $blob );
		$lim = $state['options'][ EBD_Limits::OPT ] ?? null;
		if ( is_array( $lim ) ) {
			EBD_Limits::save_options( ! empty( $lim['on'] ), (int) ( $lim['limit'] ?? -1 ), (int) ( $lim['expiry'] ?? -1 ) );
		}
		return array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'ebook-delivery' ) ) );
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'ebd_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'ebd_aui_msg_' . get_current_user_id() );
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
		<p class="ebd-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'ebook-delivery' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="ebd-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="ebd-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'ebook-delivery' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="ebd-footer-cta">
			<a class="ebd-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'ebook-delivery' ); ?>
			</a>
			<a class="ebd-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'ebook-delivery' ); ?>
			</a>
		</p>
		<?php
	}
}

/** The file name of the test download (header safe). */
final class EBD_Delivery_Mask_Name {
	public static function safe( string $name ): string {
		$name = (string) preg_replace( '/[\x00-\x1F\x7F"\\\\\/:*?<>|;]+/u', '-', $name );
		return '' === trim( $name ) ? 'watermark' : $name;
	}
}

<?php
/**
 * FRM_Admin_UI — menu, forms and builder, messages, settings, texts, tools
 * (Bible §3–§8, §11).
 *
 * Submenu «Forms» of the shared "Noxpress" menu (admin_menu priority 400),
 * slug frm-forms, capability Noxpress_Forms::cap(). Tabs:
 *  - forms     the forms list; ?edit=ID (or new&tpl=…) opens the builder
 *  - messages  stored messages: list, view, state, resend, delete, CSV
 *  - settings  mode, visitor language, retention, spam defaults and the
 *              daily counter of blocked attempts
 *  - texts     every visitor text, Greek and English
 *  - tools     import from Contact Form 7, backup of forms and settings
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient frm_aui_msg_{uid}). Exports: privileged GET.
 */

defined( 'ABSPATH' ) || exit;

final class FRM_Admin_UI {

	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'frm-forms';
	const NONCE       = 'frm_admin';
	const NONCE_FIELD = 'frm_nonce_admin';
	const NONCE_GET   = 'frm_export';
	const MAX_IMPORT  = 1048576;
	const PER_PAGE    = 30;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 450 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FRM_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label. */
	private static function tabs(): array {
		return array(
			'forms'    => __( 'Φόρμες', 'forms' ),
			'messages' => __( 'Μηνύματα', 'forms' ),
			'settings' => __( 'Ρυθμίσεις', 'forms' ),
			'texts'    => __( 'Κείμενα', 'forms' ),
			'tools'    => __( 'Εργαλεία', 'forms' ),
		);
	}

	/** An admin URL of the page. */
	public static function url( string $tab = 'forms', array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {
		// The shared top-level "Noxpress" is created by Noxpress Core
		// (priority 5, Bible §16): a submenu only here.
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Forms', 'forms' ),
			__( 'Forms', 'forms' ),
			Noxpress_Forms::cap(),
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Φόρμες', 'forms' ) . '</a>'
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
		return isset( self::tabs()[ $tab ] ) ? $tab : 'forms';
	}

	public static function assets( $hook = '' ): void {
		if ( 'index.php' === $hook ) {
			wp_add_inline_style( 'dashboard', '#frm_dashboard .frm-w{margin:0 0 8px}#frm_dashboard .frm-w li{margin:0 0 4px}#frm_dashboard .frm-w strong{font-size:15px}#frm_dashboard .frm-w-warn{color:#996800}' );
			return;
		}
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'frm-admin', FRM_URL . 'assets/admin.css', array(), FRM_VERSION );
		wp_enqueue_script( 'frm-admin', FRM_URL . 'assets/admin.js', array(), FRM_VERSION, true );
	}

	/** Suggested text for the site's privacy policy (Settings → Privacy). */
	public static function privacy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p>' . esc_html__( 'Όταν μας γράφεις από μια φόρμα του site, λαμβάνουμε με email ό,τι συμπληρώνεις (π.χ. όνομα, email, μήνυμα), τη σελίδα από την οποία το έστειλες και, σε ερώτηση για προϊόν, το προϊόν. Τα χρησιμοποιούμε μόνο για να σου απαντήσουμε.', 'forms' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Το μήνυμα κρατιέται στο site μόνο αν αποτύχει η αποστολή του email (ή αν η φόρμα ορίζει να κρατιούνται όλα) και σβήνεται αυτόματα μετά από τους μήνες που ορίζονται στις ρυθμίσεις. Για την προστασία από spam δεν χρησιμοποιούμε υπηρεσίες τρίτων ούτε cookies· η διεύθυνση IP κρατιέται μόνο ως κρυπτογραφικό αποτύπωμα, για λίγα λεπτά, για το όριο μηνυμάτων.', 'forms' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Αν μια φόρμα σε γράφει στο newsletter, το email σου στέλνεται στη λίστα μας μόνο όταν το ζητήσεις με το αντίστοιχο κουτάκι. Μπορείς να ζητήσεις αντίγραφο ή διαγραφή των μηνυμάτων σου όποτε θέλεις.', 'forms' ) . '</p>';
		wp_add_privacy_policy_content( 'Forms', wp_kses_post( $text ) );
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Noxpress_Forms::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'forms' ) );
		}
		$tab  = self::current_tab();
		$mode = FRM_Settings::get()['mode'];
		?>
		<div class="wrap frm-wrap">
			<h1><?php esc_html_e( 'Forms', 'forms' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Φόρμες επικοινωνίας με block και shortcode. Κάθε μήνυμα έρχεται με email· κρατιέται εδώ μόνο όταν το email αποτύχει, ώστε να μη χάνεται. Προστασία από spam χωρίς υπηρεσίες τρίτων.', 'forms' ); ?></p>

			<?php
			self::print_notices( self::take_msgs() );
			if ( Noxpress_Forms::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το FRM_DISABLE είναι ενεργό στο wp-config.php: οι φόρμες δεν φαίνονται πουθενά.', 'forms' ) . '</p></div>';
			} elseif ( 'test' === $mode ) {
				echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Λειτουργία δοκιμής: μόνο οι διαχειριστές βλέπουν τις φόρμες. Άλλαξέ τη σε «Ζωντανό» στις Ρυθμίσεις όταν είναι έτοιμες.', 'forms' ) . '</p></div>';
			} elseif ( 'off' === $mode ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Οι φόρμες είναι κλειστές (λειτουργία «Ανενεργό»).', 'forms' ) . '</p></div>';
			}
			?>

			<nav class="nav-tab-wrapper frm-tabs">
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
			$method = 'render_' . $tab;
			self::$method();
			self::footer();
			?>
		</div>
		<?php
	}

	/* ---------- small helpers ---------- */

	private static function checkbox( string $name, bool $on, string $label, string $attrs = '' ): void {
		printf(
			'<label class="frm-check"><input type="checkbox" name="%1$s" value="1"%2$s%3$s /> %4$s</label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			$attrs, // phpcs:ignore WordPress.Security.EscapeOutput -- fixed attributes from this class.
			esc_html( $label )
		);
	}

	private static function radio( string $name, string $value, string $current, string $label ): void {
		printf(
			'<label class="frm-check"><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $value, $current, false ),
			esc_html( $label )
		);
	}

	/** A labelled input in a .frm-grid2. */
	private static function input( string $name, string $value, string $label, string $type = 'text', string $hint = '', string $extra = '' ): void {
		printf(
			'<label><span>%1$s</span><input type="%2$s" name="%3$s" value="%4$s" class="frm-wide"%5$s />%6$s</label>',
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $name ),
			esc_attr( $value ),
			$extra, // phpcs:ignore WordPress.Security.EscapeOutput -- fixed attributes from this class.
			'' !== $hint ? '<small class="frm-hint frm-muted">' . esc_html( $hint ) . '</small>' : ''
		);
	}

	private static function textarea( string $name, string $value, string $label, int $rows = 3, string $hint = '' ): void {
		printf(
			'<label><span>%1$s</span><textarea name="%2$s" rows="%3$d" class="frm-wide">%4$s</textarea>%5$s</label>',
			esc_html( $label ),
			esc_attr( $name ),
			(int) $rows,
			esc_textarea( $value ),
			'' !== $hint ? '<small class="frm-hint frm-muted">' . esc_html( $hint ) . '</small>' : ''
		);
	}

	private static function badge( string $text, string $kind ): string {
		return '<span class="frm-badge frm-badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function nonce(): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
	}

	private static function action( string $act ): void {
		echo '<input type="hidden" name="frm_action" value="' . esc_attr( $act ) . '" />';
	}

	private static function fmt(): string {
		return get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	}

	/* =====================================================================
	 * Forms list
	 * =================================================================== */

	private static function render_forms(): void {
		$edit = self::q( 'edit' );
		if ( '' !== $edit ) {
			if ( 'new' === $edit ) {
				$tpl  = sanitize_key( self::q( 'tpl' ) );
				$tpl  = isset( FRM_Forms::template_labels()[ $tpl ] ) ? $tpl : 'contact';
				$form = FRM_Forms::template( $tpl );
				$form = array_merge(
					$form,
					array(
						'id'     => 0,
						'title'  => FRM_Forms::template_labels()[ $tpl ],
						'active' => true,
					)
				);
				self::render_builder( $form );
				return;
			}
			$form = FRM_Forms::get( (int) $edit );
			if ( ! $form ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Η φόρμα δεν βρέθηκε.', 'forms' ) . '</p></div>';
			} else {
				self::render_builder( $form );
				return;
			}
		}

		$forms = FRM_Forms::all();
		?>
		<h2 class="frm-h2"><?php esc_html_e( 'Νέα φόρμα', 'forms' ); ?></h2>
		<p class="frm-acts">
			<?php foreach ( FRM_Forms::template_labels() as $t => $label ) : ?>
				<a class="button<?php echo 'contact' === $t ? ' button-primary' : ''; ?>" href="<?php echo esc_url( self::url( 'forms', array( 'edit' => 'new', 'tpl' => $t ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</p>

		<h2 class="frm-h2"><?php esc_html_e( 'Οι φόρμες σου', 'forms' ); ?></h2>
		<table class="widefat frm-table">
			<thead><tr>
				<th><?php esc_html_e( 'Τίτλος', 'forms' ); ?></th>
				<th><?php esc_html_e( 'Shortcode', 'forms' ); ?></th>
				<th><?php esc_html_e( 'Πεδία', 'forms' ); ?></th>
				<th><?php esc_html_e( 'Email προς', 'forms' ); ?></th>
				<th><?php esc_html_e( 'Μηνύματα', 'forms' ); ?></th>
				<th><?php esc_html_e( 'Κατάσταση', 'forms' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $forms ) : ?>
				<tr><td colspan="6" class="frm-empty"><?php esc_html_e( 'Καμία φόρμα ακόμα. Ξεκίνα από ένα πρότυπο παραπάνω, ή εισήγαγε τις φόρμες του Contact Form 7 από τα Εργαλεία.', 'forms' ); ?></td></tr>
			<?php endif; ?>
			<?php
			foreach ( $forms as $f ) {
				$url = self::url( 'forms', array( 'edit' => $f['id'] ) );
				$c   = FRM_Entries::counts( (int) $f['id'] );
				$to  = FRM_Mailer::recipients( $f );
				?>
				<tr>
					<td><a href="<?php echo esc_url( $url ); ?>"><strong><?php echo esc_html( $f['title'] ); ?></strong></a>
						<?php if ( $f['cf7']['id'] > 0 ) : ?><br /><small class="frm-muted"><?php esc_html_e( 'Από το Contact Form 7', 'forms' ); ?></small><?php endif; ?>
						<?php if ( $f['oros'] ) : ?><br /><small class="frm-muted"><?php esc_html_e( 'Εγγραφή στο orOS Newsletter', 'forms' ); ?></small><?php endif; ?>
					</td>
					<td><code>[nx_form id="<?php echo (int) $f['id']; ?>"]</code></td>
					<td><?php echo (int) count( $f['fields'] ); ?></td>
					<td><?php echo esc_html( implode( ', ', $to ) ); ?></td>
					<td><a href="<?php echo esc_url( self::url( 'messages', array( 'form' => $f['id'] ) ) ); ?>"><?php echo (int) ( $c['new'] + $c['read'] ); ?></a><?php echo $c['new'] > 0 ? ' ' . self::badge( sprintf( /* translators: %d: count */ __( '%d νέα', 'forms' ), (int) $c['new'] ), 'warn' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></td>
					<td><?php echo $f['active'] ? self::badge( __( 'Ενεργή', 'forms' ), 'ok' ) : self::badge( __( 'Ανενεργή', 'forms' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></td>
				</tr>
				<?php
			}
			?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Βάλε μια φόρμα σε σελίδα με το block «Φόρμα» ή με το shortcode. Μια φόρμα που εισήχθη από το Contact Form 7 δουλεύει και με το παλιό shortcode του, όταν το Contact Form 7 απενεργοποιηθεί.', 'forms' ); ?></p>
		<?php
	}

	/* =====================================================================
	 * Builder
	 * =================================================================== */

	/** Which builder rows each type shows (data-for). */
	private static function shows(): array {
		return array(
			'label'    => 'text email tel url number date textarea select radio checkboxes consent hidden note',
			'key'      => 'text email tel url number date textarea select radio checkboxes consent hidden',
			'ph'       => 'text email tel url number textarea select',
			'help'     => 'text email tel url number date textarea select radio checkboxes consent note',
			'required' => 'text email tel url number date textarea select radio checkboxes',
			'width'    => 'text email tel url number date textarea select radio checkboxes consent note',
			'max'      => 'text textarea',
			'num'      => 'number',
			'options'  => 'select radio checkboxes',
			'source'   => 'hidden',
		);
	}

	private static function for_attr( string $what, string $type ): string {
		$list = self::shows()[ $what ];
		$on   = in_array( $type, explode( ' ', $list ), true );
		return ' data-for="' . esc_attr( $list ) . '"' . ( $on ? '' : ' hidden' );
	}

	/** One field card of the builder. $i = '__I__' for the template. */
	private static function field_card( array $f, string $i ): void {
		$n     = 'frm_f[' . $i . ']';
		$type  = $f['type'];
		$types = FRM_Fields::type_labels();
		?>
		<div class="frm-card" data-type="<?php echo esc_attr( $type ); ?>">
			<div class="frm-card-head">
				<select name="<?php echo esc_attr( $n ); ?>[type]" class="frm-type" aria-label="<?php esc_attr_e( 'Είδος πεδίου', 'forms' ); ?>">
					<?php foreach ( $types as $t => $label ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>"<?php selected( $t, $type ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<strong class="frm-card-title"><?php echo esc_html( '' !== $f['label_el'] ? $f['label_el'] : ( '' !== $f['key'] ? $f['key'] : '' ) ); ?></strong>
				<span class="frm-card-tools">
					<button type="button" class="button button-small" data-frm-act="up" aria-label="<?php esc_attr_e( 'Πάνω', 'forms' ); ?>">↑</button>
					<button type="button" class="button button-small" data-frm-act="down" aria-label="<?php esc_attr_e( 'Κάτω', 'forms' ); ?>">↓</button>
					<button type="button" class="button button-small" data-frm-act="del"><?php esc_html_e( 'Αφαίρεση', 'forms' ); ?></button>
				</span>
			</div>
			<input type="hidden" name="<?php echo esc_attr( $n ); ?>[id]" value="<?php echo esc_attr( $f['id'] ); ?>" />
			<div class="frm-grid2">
				<label<?php echo self::for_attr( 'label', $type ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in for_attr(). ?>><span><?php esc_html_e( 'Ετικέτα (ελληνικά)', 'forms' ); ?></span><input type="text" name="<?php echo esc_attr( $n ); ?>[label_el]" value="<?php echo esc_attr( $f['label_el'] ); ?>" class="frm-wide frm-label-el" maxlength="300" /></label>
				<label<?php echo self::for_attr( 'label', $type ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in for_attr(). ?>><span><?php esc_html_e( 'Ετικέτα (αγγλικά)', 'forms' ); ?></span><input type="text" name="<?php echo esc_attr( $n ); ?>[label_en]" value="<?php echo esc_attr( $f['label_en'] ); ?>" class="frm-wide" maxlength="300" /></label>
				<label<?php echo self::for_attr( 'key', $type ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in for_attr(). ?>><span><?php esc_html_e( 'Κλειδί (για το θέμα του email: {κλειδί})', 'forms' ); ?></span><input type="text" name="<?php echo esc_attr( $n ); ?>[key]" value="<?php echo esc_attr( $f['key'] ); ?>" class="frm-wide frm-mono" maxlength="40" pattern="[a-z0-9_\-]*" /></label>
				<label<?php echo self::for_attr( 'width', $type ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in for_attr(). ?>><span><?php esc_html_e( 'Πλάτος', 'forms' ); ?></span>
					<select name="<?php echo esc_attr( $n ); ?>[width]">
						<option value="full"<?php selected( 'full', $f['width'] ); ?>><?php esc_html_e( 'Όλη η γραμμή', 'forms' ); ?></option>
						<option value="half"<?php selected( 'half', $f['width'] ); ?>><?php esc_html_e( 'Μισή γραμμή', 'forms' ); ?></option>
					</select>
				</label>
				<label<?php echo self::for_attr( 'ph', $type ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in for_attr(). ?>><span><?php esc_html_e( 'Κείμενο μέσα στο πεδίο (ελληνικά)', 'forms' ); ?></span><input type="text" name="<?php echo esc_attr( $n ); ?>[ph_el]" value="<?php echo esc_attr( $f['ph_el'] ); ?>" class="frm-wide" maxlength="150" /></label>
				<label<?php echo self::for_attr( 'ph', $type ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in for_attr(). ?>><span><?php esc_html_e( 'Κείμενο μέσα στο πεδίο (αγγλικά)', 'forms' ); ?></span><input type="text" name="<?php echo esc_attr( $n ); ?>[ph_en]" value="<?php echo esc_attr( $f['ph_en'] ); ?>" class="frm-wide" maxlength="150" /></label>
				<label<?php echo self::for_attr( 'help', $type ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in for_attr(). ?>><span><?php echo 'note' === $type ? esc_html__( 'Κείμενο (ελληνικά)', 'forms' ) : esc_html__( 'Βοήθεια κάτω από το πεδίο (ελληνικά)', 'forms' ); ?></span><textarea name="<?php echo esc_attr( $n ); ?>[help_el]" rows="2" class="frm-wide"><?php echo esc_textarea( $f['help_el'] ); ?></textarea></label>
				<label<?php echo self::for_attr( 'help', $type ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in for_attr(). ?>><span><?php echo 'note' === $type ? esc_html__( 'Κείμενο (αγγλικά)', 'forms' ) : esc_html__( 'Βοήθεια κάτω από το πεδίο (αγγλικά)', 'forms' ); ?></span><textarea name="<?php echo esc_attr( $n ); ?>[help_en]" rows="2" class="frm-wide"><?php echo esc_textarea( $f['help_en'] ); ?></textarea></label>
				<label<?php echo self::for_attr( 'options', $type ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in for_attr(). ?> class="frm-span2"><span><?php esc_html_e( 'Επιλογές, μία ανά γραμμή: «Ελληνικά | English»', 'forms' ); ?></span><textarea name="<?php echo esc_attr( $n ); ?>[options_text]" rows="4" class="frm-wide"><?php echo esc_textarea( FRM_Fields::options_text( $f ) ); ?></textarea></label>
				<label<?php echo self::for_attr( 'max', $type ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in for_attr(). ?>><span><?php esc_html_e( 'Μέγιστοι χαρακτήρες', 'forms' ); ?></span><input type="number" min="1" max="<?php echo (int) FRM_Fields::MAX_LIMIT; ?>" name="<?php echo esc_attr( $n ); ?>[max]" value="<?php echo (int) $f['max']; ?>" /></label>
				<label<?php echo self::for_attr( 'num', $type ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in for_attr(). ?>><span><?php esc_html_e( 'Από / έως (αριθμός)', 'forms' ); ?></span><span class="frm-pair"><input type="text" name="<?php echo esc_attr( $n ); ?>[nmin]" value="<?php echo esc_attr( $f['nmin'] ); ?>" class="frm-num" inputmode="decimal" /> – <input type="text" name="<?php echo esc_attr( $n ); ?>[nmax]" value="<?php echo esc_attr( $f['nmax'] ); ?>" class="frm-num" inputmode="decimal" /></span></label>
				<label<?php echo self::for_attr( 'source', $type ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in for_attr(). ?>><span><?php esc_html_e( 'Τιμή', 'forms' ); ?></span>
					<select name="<?php echo esc_attr( $n ); ?>[source]">
						<option value="page"<?php selected( 'page', $f['source'] ); ?>><?php esc_html_e( 'Η σελίδα της φόρμας', 'forms' ); ?></option>
						<option value="product"<?php selected( 'product', $f['source'] ); ?>><?php esc_html_e( 'Το προϊόν της σελίδας (ερώτηση για προϊόν)', 'forms' ); ?></option>
						<option value="static"<?php selected( 'static', $f['source'] ); ?>><?php esc_html_e( 'Σταθερό κείμενο', 'forms' ); ?></option>
					</select>
				</label>
				<label<?php echo self::for_attr( 'source', $type ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in for_attr(). ?>><span><?php esc_html_e( 'Σταθερό κείμενο', 'forms' ); ?></span><input type="text" name="<?php echo esc_attr( $n ); ?>[value]" value="<?php echo esc_attr( $f['value'] ); ?>" class="frm-wide" maxlength="200" /></label>
			</div>
			<p<?php echo self::for_attr( 'required', $type ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in for_attr(). ?>><label class="frm-check"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[required]" value="1"<?php checked( $f['required'] ); ?> /> <?php esc_html_e( 'Υποχρεωτικό', 'forms' ); ?></label></p>
			<p class="frm-muted frm-hint-consent"<?php echo 'consent' === $type ? '' : ' hidden'; ?>><?php esc_html_e( 'Η συναίνεση είναι πάντα υποχρεωτική. Κενή ετικέτα = το τυπικό κείμενο με σύνδεσμο στην πολιτική απορρήτου· γράψε {privacy} όπου θέλεις τον σύνδεσμο.', 'forms' ); ?></p>
		</div>
		<?php
	}

	private static function render_builder( array $form ): void {
		$id   = (int) $form['id'];
		$back = self::url( 'forms' );
		$oros = FRM_Oros::available();
		$emails = array();
		$texts  = array();
		foreach ( $form['fields'] as $f ) {
			if ( 'email' === $f['type'] ) {
				$emails[ $f['key'] ] = FRM_Fields::plain_label( $f, 'el' );
			} elseif ( 'text' === $f['type'] ) {
				$texts[ $f['key'] ] = FRM_Fields::plain_label( $f, 'el' );
			}
		}
		?>
		<p><a href="<?php echo esc_url( $back ); ?>">← <?php esc_html_e( 'Όλες οι φόρμες', 'forms' ); ?></a></p>
		<?php if ( $id > 0 ) : ?>
			<p class="frm-muted"><?php esc_html_e( 'Shortcode:', 'forms' ); ?> <code>[nx_form id="<?php echo (int) $id; ?>"]</code>
			<?php if ( $form['cf7']['id'] > 0 ) : ?> · <?php esc_html_e( 'και το παλιό shortcode του Contact Form 7', 'forms' ); ?><?php endif; ?></p>
		<?php endif; ?>

		<form method="post" class="frm-builder">
			<?php self::nonce(); ?>
			<?php self::action( 'save_form' ); ?>
			<input type="hidden" name="frm_id" value="<?php echo (int) $id; ?>" />

			<div class="frm-zone frm-grid2">
				<?php self::input( 'frm_title', $form['title'], __( 'Τίτλος (μόνο για σένα και στο θέμα του email)', 'forms' ), 'text', '', ' maxlength="120" required' ); ?>
				<label><span><?php esc_html_e( 'Κατάσταση', 'forms' ); ?></span><?php self::checkbox( 'frm_active', (bool) $form['active'], __( 'Ενεργή (φαίνεται στο site)', 'forms' ) ); ?></label>
			</div>

			<h2 class="frm-h2"><?php esc_html_e( 'Πεδία', 'forms' ); ?></h2>
			<div id="frm-fields" class="frm-cards">
				<?php
				foreach ( $form['fields'] as $i => $f ) {
					self::field_card( $f, (string) $i );
				}
				?>
			</div>
			<template id="frm-field-tpl"><?php self::field_card( FRM_Fields::blank( 'text' ), '__I__' ); ?></template>
			<p><button type="button" class="button" data-frm-act="add"><?php esc_html_e( '+ Προσθήκη πεδίου', 'forms' ); ?></button></p>

			<h2 class="frm-h2"><?php esc_html_e( 'Ειδοποίηση με email', 'forms' ); ?></h2>
			<div class="frm-zone frm-grid2">
				<?php self::input( 'frm_s[mail][to]', $form['mail']['to'], __( 'Προς (κόμμα ανάμεσα· κενό = το email του site)', 'forms' ), 'text', (string) get_option( 'admin_email' ) ); ?>
				<?php self::input( 'frm_s[mail][subject_el]', $form['mail']['subject_el'], __( 'Θέμα', 'forms' ), 'text', __( '{site}, {form}, {name} και {κλειδί} κάθε πεδίου.', 'forms' ), ' maxlength="200"' ); ?>
				<label><span><?php esc_html_e( 'Απάντηση στο email του πεδίου', 'forms' ); ?></span>
					<select name="frm_s[mail][email_key]">
						<option value=""><?php esc_html_e( '— αυτόματα —', 'forms' ); ?></option>
						<?php foreach ( $emails as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $k, $form['mail']['email_key'] ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><span><?php esc_html_e( 'Όνομα αποστολέα από το πεδίο', 'forms' ); ?></span>
					<select name="frm_s[mail][name_key]">
						<option value=""><?php esc_html_e( '— αυτόματα —', 'forms' ); ?></option>
						<?php foreach ( $texts as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $k, $form['mail']['name_key'] ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
			<p class="description"><?php esc_html_e( 'Το email φεύγει από τον αποστολέα του site (ή το Mail Relay / SMTP plugin)· το «Απάντηση» πηγαίνει στον επισκέπτη.', 'forms' ); ?></p>

			<h2 class="frm-h2"><?php esc_html_e( 'Αποθήκευση μηνυμάτων', 'forms' ); ?></h2>
			<div class="frm-zone">
				<?php
				self::radio( 'frm_s[store]', 'fail', $form['store'], __( 'Μόνο όταν αποτύχει το email (προτείνεται)', 'forms' ) );
				self::radio( 'frm_s[store]', 'all', $form['store'], __( 'Πάντα', 'forms' ) );
				self::radio( 'frm_s[store]', 'off', $form['store'], __( 'Ποτέ (αν αποτύχει το email, ο επισκέπτης βλέπει σφάλμα)', 'forms' ) );
				?>
			</div>

			<h2 class="frm-h2"><?php esc_html_e( 'Κείμενα της φόρμας', 'forms' ); ?></h2>
			<div class="frm-zone frm-grid2">
				<?php
				self::input( 'frm_s[texts][submit_el]', $form['texts']['submit_el'], __( 'Κουμπί (ελληνικά)', 'forms' ), 'text', __( 'Κενό = το γενικό κείμενο.', 'forms' ), ' maxlength="60"' );
				self::input( 'frm_s[texts][submit_en]', $form['texts']['submit_en'], __( 'Κουμπί (αγγλικά)', 'forms' ), 'text', '', ' maxlength="60"' );
				self::input( 'frm_s[texts][thanks_el]', $form['texts']['thanks_el'], __( 'Μήνυμα επιτυχίας (ελληνικά)', 'forms' ), 'text', '', ' maxlength="300"' );
				self::input( 'frm_s[texts][thanks_en]', $form['texts']['thanks_en'], __( 'Μήνυμα επιτυχίας (αγγλικά)', 'forms' ), 'text', '', ' maxlength="300"' );
				?>
			</div>

			<h2 class="frm-h2"><?php esc_html_e( 'Προστασία από spam', 'forms' ); ?></h2>
			<div class="frm-zone">
				<p class="frm-muted"><?php esc_html_e( 'Πάντα ενεργά: κρυφό πεδίο για bots, υπογεγραμμένος χρόνος, όριο μηνυμάτων ανά επισκέπτη, μία αποστολή ανά token.', 'forms' ); ?></p>
				<?php
				self::checkbox( 'frm_s[spam][pow]', (bool) $form['spam']['pow'], __( 'Αόρατο proof of work (ο browser λύνει ένα μικρό πρόβλημα όσο γράφει ο επισκέπτης)', 'forms' ) );
				echo '<br />';
				self::checkbox( 'frm_s[spam][nojs]', (bool) $form['spam']['nojs'], __( 'Χωρίς JavaScript: σελίδα «Επιβεβαίωση αποστολής» (αλλιώς η αποστολή χωρίς JS απορρίπτεται)', 'forms' ) );
				echo '<br />';
				self::checkbox( 'frm_s[spam][disallowed]', (bool) $form['spam']['disallowed'], __( 'Έλεγχος με τις «Λέξεις που δεν επιτρέπονται» του WordPress (Ρυθμίσεις → Συζήτηση)', 'forms' ) );
				echo '<br />';
				self::checkbox( 'frm_s[spam][letters]', (bool) $form['spam']['letters'], __( 'Μόνο ελληνικά ή λατινικά γράμματα στο μήνυμα', 'forms' ) );
				echo '<br />';
				self::checkbox( 'frm_s[spam][mx]', (bool) $form['spam']['mx'], __( 'Έλεγχος ότι ο τομέας του email δέχεται μηνύματα (DNS)', 'forms' ) );
				?>
				<div class="frm-grid2 frm-mt-8">
					<?php self::input( 'frm_s[spam][links]', (string) $form['spam']['links'], __( 'Μέγιστα links στο μήνυμα (-1 = χωρίς όριο)', 'forms' ), 'number', '', ' min="-1" max="50"' ); ?>
					<?php self::textarea( 'frm_s[spam][words]', $form['spam']['words'], __( 'Δικές σου λέξεις που δεν επιτρέπονται (μία ανά γραμμή)', 'forms' ), 3 ); ?>
				</div>
				<p class="frm-muted"><?php esc_html_e( 'Ένα bot παίρνει σιωπηλό «Ευχαριστούμε» και τίποτα δεν στέλνεται. Ένα μήνυμα που κόβεται από κανόνα περιεχομένου βλέπει το σφάλμα και πηγαίνει στο Spam (κρατιέται 7 ημέρες), εκτός αν η αποθήκευση είναι «Ποτέ».', 'forms' ); ?></p>
			</div>

			<h2 class="frm-h2"><?php esc_html_e( 'Αυτόματη απάντηση', 'forms' ); ?></h2>
			<div class="frm-zone">
				<?php self::checkbox( 'frm_s[auto][on]', (bool) $form['auto']['on'], __( 'Στέλνε στον επισκέπτη ένα σύντομο «Λάβαμε το μήνυμά σας» (μία φορά την ώρα ανά email)', 'forms' ) ); ?>
				<div class="frm-grid2 frm-mt-8">
					<?php
					self::input( 'frm_s[auto][subject_el]', $form['auto']['subject_el'], __( 'Θέμα (ελληνικά)', 'forms' ), 'text', '', ' maxlength="200"' );
					self::input( 'frm_s[auto][subject_en]', $form['auto']['subject_en'], __( 'Θέμα (αγγλικά)', 'forms' ), 'text', '', ' maxlength="200"' );
					self::textarea( 'frm_s[auto][body_el]', $form['auto']['body_el'], __( 'Κείμενο (ελληνικά)', 'forms' ), 5, __( 'Μόνο {name} και {site}: τίποτα άλλο από όσα έγραψε ο επισκέπτης.', 'forms' ) );
					self::textarea( 'frm_s[auto][body_en]', $form['auto']['body_en'], __( 'Κείμενο (αγγλικά)', 'forms' ), 5 );
					?>
				</div>
			</div>

			<h2 class="frm-h2"><?php esc_html_e( 'orOS Newsletter', 'forms' ); ?></h2>
			<div class="frm-zone">
				<?php self::checkbox( 'frm_s[oros]', (bool) $form['oros'], __( 'Γράψε τον επισκέπτη στο orOS Newsletter όταν τσεκάρει τη συναίνεση', 'forms' ) ); ?>
				<p class="frm-muted">
					<?php
					echo $oros
						? esc_html__( 'Μέσω του orOS Connect (module Newsletter), πάντα με email επιβεβαίωσης από το orOS. Χρειάζεται πεδίο Email και πεδίο Συναίνεση με κείμενο για newsletter.', 'forms' )
						: esc_html__( 'Το orOS Connect με το module Newsletter δεν είναι ενεργό ή ρυθμισμένο: η επιλογή μένει αποθηκευμένη και δουλεύει όταν ενεργοποιηθεί.', 'forms' );
					?>
				</p>
			</div>

			<p class="frm-submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση φόρμας', 'forms' ); ?></button>
				<?php if ( $id > 0 ) : ?>
					<button type="submit" class="button" name="frm_action_alt" value="duplicate"><?php esc_html_e( 'Αντίγραφο', 'forms' ); ?></button>
				<?php endif; ?>
			</p>
		</form>

		<?php if ( $id > 0 ) : ?>
			<?php $c = FRM_Entries::counts( $id ); ?>
			<h2 class="frm-h2"><?php esc_html_e( 'Διαγραφή', 'forms' ); ?></h2>
			<form method="post" class="frm-zone" onsubmit="return confirm('<?php echo esc_js( __( 'Οριστική διαγραφή της φόρμας και των μηνυμάτων της;', 'forms' ) ); ?>');">
				<?php self::nonce(); ?>
				<?php self::action( 'delete_form' ); ?>
				<input type="hidden" name="frm_id" value="<?php echo (int) $id; ?>" />
				<p class="frm-muted">
					<?php
					printf(
						/* translators: %d: number of stored messages */
						esc_html__( 'Σβήνει τη φόρμα και τα %d αποθηκευμένα μηνύματά της. Όπου υπάρχει το shortcode της, δεν θα φαίνεται τίποτα.', 'forms' ),
						(int) ( $c['new'] + $c['read'] + $c['spam'] )
					);
					?>
				</p>
				<button type="submit" class="button"><?php esc_html_e( 'Διαγραφή φόρμας', 'forms' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}

	/* =====================================================================
	 * Messages
	 * =================================================================== */

	/** The list filters from the query string. */
	private static function msg_filters(): array {
		$state = self::q( 'state' );
		return array(
			'form'   => (int) self::q( 'form' ),
			'state'  => in_array( $state, array( 'new', 'read', 'spam', 'all' ), true ) ? $state : 'inbox',
			'mail'   => 'failed' === self::q( 'mail' ) ? 'failed' : '',
			'search' => mb_substr( self::q( 's' ), 0, 100 ),
		);
	}

	private static function render_messages(): void {
		$view = (int) self::q( 'view' );
		if ( $view > 0 ) {
			self::render_message( $view );
			return;
		}
		$flt  = self::msg_filters();
		$page = max( 1, (int) self::q( 'paged' ) );
		$q    = FRM_Entries::query(
			array(
				'form'   => $flt['form'],
				'state'  => 'all' === $flt['state'] ? '' : $flt['state'],
				'mail'   => $flt['mail'],
				'search' => $flt['search'],
				'page'   => $page,
				'per'    => self::PER_PAGE,
			)
		);
		$c     = FRM_Entries::counts( $flt['form'] );
		$args  = array_filter(
			array(
				'form' => $flt['form'] ? $flt['form'] : null,
				's'    => '' !== $flt['search'] ? $flt['search'] : null,
			)
		);
		$links = array(
			'inbox' => __( 'Εισερχόμενα', 'forms' ) . ' (' . ( $c['new'] + $c['read'] ) . ')',
			'new'   => __( 'Νέα', 'forms' ) . ' (' . $c['new'] . ')',
			'spam'  => 'Spam (' . $c['spam'] . ')',
		);
		$forms = FRM_Forms::choices();
		?>
		<div class="frm-filters">
			<?php foreach ( $links as $st => $label ) : ?>
				<a href="<?php echo esc_url( self::url( 'messages', array_merge( $args, array( 'state' => $st ) ) ) ); ?>" class="<?php echo $st === $flt['state'] && '' === $flt['mail'] ? 'is-current' : ''; ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
			<a href="<?php echo esc_url( self::url( 'messages', array_merge( $args, array( 'mail' => 'failed' ) ) ) ); ?>" class="<?php echo 'failed' === $flt['mail'] ? 'is-current' : ''; ?>"><?php echo esc_html( __( 'Email που απέτυχε', 'forms' ) . ' (' . $c['failed'] . ')' ); ?></a>
			<form method="get" class="frm-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<input type="hidden" name="tab" value="messages" />
				<input type="hidden" name="state" value="<?php echo esc_attr( $flt['state'] ); ?>" />
				<select name="form">
					<option value="0"><?php esc_html_e( 'Όλες οι φόρμες', 'forms' ); ?></option>
					<?php foreach ( $forms as $fid => $title ) : ?>
						<option value="<?php echo (int) $fid; ?>"<?php selected( (int) $fid, $flt['form'] ); ?>><?php echo esc_html( $title ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $flt['search'] ); ?>" placeholder="<?php esc_attr_e( 'Αναζήτηση', 'forms' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Φιλτράρισμα', 'forms' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( self::url( 'messages', array_merge( $args, array( 'state' => $flt['state'], 'mail' => $flt['mail'], 'frm_csv' => 1 ) ) ), self::NONCE_GET ) ); ?>"><?php esc_html_e( 'Εξαγωγή CSV', 'forms' ); ?></a>
		</div>
		<table class="widefat frm-table">
			<thead><tr>
				<th><?php esc_html_e( 'Ημερομηνία', 'forms' ); ?></th>
				<th><?php esc_html_e( 'Φόρμα', 'forms' ); ?></th>
				<th><?php esc_html_e( 'Από', 'forms' ); ?></th>
				<th><?php esc_html_e( 'Μήνυμα', 'forms' ); ?></th>
				<th><?php esc_html_e( 'Κατάσταση', 'forms' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $q['ids'] ) : ?>
				<tr><td colspan="5" class="frm-empty"><?php esc_html_e( 'Κανένα μήνυμα εδώ. Τα μηνύματα έρχονται με email και κρατιούνται εδώ μόνο όταν το email αποτύχει (ή όταν η φόρμα ορίζει «Πάντα»).', 'forms' ); ?></td></tr>
			<?php endif; ?>
			<?php
			foreach ( $q['ids'] as $id ) {
				$e = FRM_Entries::get( $id );
				if ( ! $e ) {
					continue;
				}
				$url  = self::url( 'messages', array( 'view' => $id ) );
				$prev = '';
				foreach ( $e['fields'] as $f ) {
					if ( 'textarea' === $f['type'] && '' !== (string) $f['value'] ) {
						$prev = (string) $f['value'];
						break;
					}
				}
				if ( '' === $prev ) {
					$bits = array();
					foreach ( $e['fields'] as $f ) {
						if ( '' !== (string) $f['value'] && ! in_array( $f['type'], array( 'consent', 'hidden', 'email' ), true ) ) {
							$bits[] = (string) $f['value'];
						}
					}
					$prev = implode( ' · ', $bits );
				}
				$st = $e['state'];
				?>
				<tr class="<?php echo 'new' === $st ? 'frm-row-new' : ''; ?>">
					<td><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( get_the_date( self::fmt(), $id ) ); ?></a></td>
					<td><?php echo esc_html( $e['form_title'] ); ?></td>
					<td><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( '' !== $e['name'] ? $e['name'] : $e['email'] ); ?></a><?php echo '' !== $e['name'] && '' !== $e['email'] ? '<br /><small class="frm-muted">' . esc_html( $e['email'] ) . '</small>' : ''; ?></td>
					<td><?php echo esc_html( wp_html_excerpt( $prev, 90, '…' ) ); ?></td>
					<td>
						<?php
						echo self::badge( FRM_Entries::state_label( $st ), 'new' === $st ? 'warn' : ( 'spam' === $st ? 'danger' : 'muted' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
						if ( 'failed' === $e['mail'] ) {
							echo ' ' . self::badge( FRM_Entries::mail_label( 'failed' ), 'danger' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
						}
						?>
					</td>
				</tr>
				<?php
			}
			?>
			</tbody>
		</table>
		<p>
			<?php if ( $page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page - 1 ) ); ?>">← <?php esc_html_e( 'Νεότερα', 'forms' ); ?></a>
			<?php endif; ?>
			<?php if ( $page * self::PER_PAGE < $q['total'] ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page + 1 ) ); ?>"><?php esc_html_e( 'Παλαιότερα', 'forms' ); ?> →</a>
			<?php endif; ?>
		</p>
		<p class="description">
			<?php
			$s = FRM_Settings::get();
			printf(
				/* translators: 1: months, 2: days */
				esc_html__( 'Τα μηνύματα σβήνονται αυτόματα μετά από %1$d μήνες και το spam μετά από %2$d ημέρες (Ρυθμίσεις).', 'forms' ),
				(int) $s['keep'],
				(int) $s['spam_days']
			);
			?>
		</p>
		<?php
	}

	private static function render_message( int $id ): void {
		$e    = FRM_Entries::get( $id );
		$back = self::url( 'messages' );
		echo '<p><a href="' . esc_url( $back ) . '">← ' . esc_html__( 'Όλα τα μηνύματα', 'forms' ) . '</a></p>';
		if ( ! $e ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Το μήνυμα δεν βρέθηκε.', 'forms' ) . '</p></div>';
			return;
		}
		$st     = $e['state'];
		$reason = '' !== $e['reason'] ? ( FRM_Spam::reasons()[ 0 === strpos( $e['reason'], 'custom' ) ? 'custom' : $e['reason'] ] ?? $e['reason'] ) : '';
		?>
		<h2 class="frm-h2"><?php echo esc_html( '' !== $e['name'] ? $e['name'] : ( '' !== $e['email'] ? $e['email'] : $e['form_title'] ) ); ?> · <?php echo esc_html( get_the_date( self::fmt(), $id ) ); ?>
			<?php echo self::badge( FRM_Entries::state_label( $st ), 'new' === $st ? 'warn' : ( 'spam' === $st ? 'danger' : 'muted' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?>
			<?php echo self::badge( FRM_Entries::mail_label( $e['mail'] ), 'sent' === $e['mail'] ? 'ok' : ( 'failed' === $e['mail'] ? 'danger' : 'muted' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?>
		</h2>
		<div class="frm-bench">
			<div>
				<table class="widefat frm-table frm-req-meta"><tbody>
					<?php foreach ( $e['fields'] as $f ) : ?>
						<tr>
							<th><?php echo esc_html( (string) $f['label'] ); ?></th>
							<td>
								<?php
								$v = (string) $f['value'];
								if ( 'email' === $f['type'] && is_email( $v ) ) {
									echo '<a href="' . esc_url( 'mailto:' . $v ) . '">' . esc_html( $v ) . '</a>';
								} elseif ( 'consent' === $f['type'] ) {
									echo esc_html( '' !== $v ? __( 'Ναι', 'forms' ) : __( 'Όχι', 'forms' ) );
								} else {
									echo '<pre class="frm-pre">' . esc_html( $v ) . '</pre>';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody></table>
			</div>
			<div>
				<table class="widefat frm-table frm-req-meta"><tbody>
					<tr><th><?php esc_html_e( 'Φόρμα', 'forms' ); ?></th><td><?php echo esc_html( $e['form_title'] ); ?></td></tr>
					<?php if ( '' !== $e['page'] ) : ?>
						<tr><th><?php esc_html_e( 'Σελίδα', 'forms' ); ?></th><td><a href="<?php echo esc_url( $e['page'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $e['page'] ); ?></a></td></tr>
					<?php endif; ?>
					<?php if ( $e['product'] ) : ?>
						<tr><th><?php esc_html_e( 'Προϊόν', 'forms' ); ?></th><td><a href="<?php echo esc_url( (string) $e['product']['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $e['product']['name'] ); ?></a><?php echo '' !== (string) $e['product']['sku'] ? ' · SKU ' . esc_html( (string) $e['product']['sku'] ) : ''; ?></td></tr>
					<?php endif; ?>
					<tr><th><?php esc_html_e( 'Γλώσσα', 'forms' ); ?></th><td><?php echo esc_html( 'en' === $e['lang'] ? 'English' : __( 'Ελληνικά', 'forms' ) ); ?></td></tr>
					<?php if ( $e['consent'] ) : ?>
						<tr><th><?php esc_html_e( 'Συναίνεση', 'forms' ); ?></th><td><?php echo esc_html( wp_date( self::fmt(), (int) $e['consent']['time'] ) . ' · ' . (string) $e['consent']['text'] ); ?></td></tr>
					<?php endif; ?>
					<?php if ( '' !== $reason ) : ?>
						<tr><th><?php esc_html_e( 'Λόγος spam', 'forms' ); ?></th><td><?php echo esc_html( $reason ); ?></td></tr>
					<?php endif; ?>
				</tbody></table>

				<h2 class="frm-h2"><?php esc_html_e( 'Ενέργειες', 'forms' ); ?></h2>
				<div class="frm-acts">
					<?php
					$acts = array();
					if ( 'new' === $st ) {
						$acts['read'] = __( 'Σημείωση ως διαβασμένο', 'forms' );
					} elseif ( 'read' === $st ) {
						$acts['new'] = __( 'Σημείωση ως νέο', 'forms' );
					}
					$acts[ 'spam' === $st ? 'read' : 'spam' ] = 'spam' === $st ? __( 'Δεν είναι spam', 'forms' ) : __( 'Είναι spam', 'forms' );
					foreach ( $acts as $to => $label ) :
						?>
						<form method="post">
							<?php self::nonce(); ?>
							<?php self::action( 'state' ); ?>
							<input type="hidden" name="frm_id" value="<?php echo (int) $id; ?>" />
							<input type="hidden" name="frm_state" value="<?php echo esc_attr( $to ); ?>" />
							<button type="submit" class="button<?php echo 'read' === $to && 'new' === $st ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button>
						</form>
					<?php endforeach; ?>
					<?php if ( 'spam' !== $st && FRM_Forms::get( $e['form'] ) ) : ?>
						<form method="post">
							<?php self::nonce(); ?>
							<?php self::action( 'resend' ); ?>
							<input type="hidden" name="frm_id" value="<?php echo (int) $id; ?>" />
							<button type="submit" class="button"><?php echo esc_html( 'sent' === $e['mail'] ? __( 'Ξανά αποστολή του email', 'forms' ) : __( 'Αποστολή του email τώρα', 'forms' ) ); ?></button>
						</form>
					<?php endif; ?>
					<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Οριστική διαγραφή του μηνύματος;', 'forms' ) ); ?>');">
						<?php self::nonce(); ?>
						<?php self::action( 'delete' ); ?>
						<input type="hidden" name="frm_id" value="<?php echo (int) $id; ?>" />
						<button type="submit" class="button"><?php esc_html_e( 'Διαγραφή', 'forms' ); ?></button>
					</form>
				</div>
				<?php if ( '' !== $e['email'] && is_email( $e['email'] ) ) : ?>
					<p><a class="button" href="<?php echo esc_url( 'mailto:' . $e['email'] . '?subject=' . rawurlencode( 'Re: ' . $e['form_title'] ) ); ?>"><?php esc_html_e( 'Απάντηση με email', 'forms' ); ?></a></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/* =====================================================================
	 * Settings + texts
	 * =================================================================== */

	private static function render_settings(): void {
		$s = FRM_Settings::get();
		?>
		<form method="post">
			<?php self::nonce(); ?>
			<?php self::action( 'save_settings' ); ?>
			<input type="hidden" name="frm_tab" value="settings" />

			<h2 class="frm-h2"><?php esc_html_e( 'Λειτουργία', 'forms' ); ?></h2>
			<div class="frm-zone">
				<?php
				self::radio( 'frm[mode]', 'off', $s['mode'], __( 'Ανενεργό (οι φόρμες δεν φαίνονται)', 'forms' ) );
				self::radio( 'frm[mode]', 'test', $s['mode'], __( 'Δοκιμή (μόνο οι διαχειριστές)', 'forms' ) );
				self::radio( 'frm[mode]', 'live', $s['mode'], __( 'Ζωντανό', 'forms' ) );
				?>
			</div>

			<h2 class="frm-h2"><?php esc_html_e( 'Γλώσσα επισκέπτη', 'forms' ); ?></h2>
			<div class="frm-zone">
				<?php
				self::radio( 'frm[lang]', 'auto', $s['lang'], __( 'Αυτόματα (Polylang, WPML ή η γλώσσα του site)', 'forms' ) );
				self::radio( 'frm[lang]', 'el', $s['lang'], __( 'Πάντα ελληνικά', 'forms' ) );
				self::radio( 'frm[lang]', 'en', $s['lang'], __( 'Πάντα αγγλικά', 'forms' ) );
				?>
			</div>

			<h2 class="frm-h2"><?php esc_html_e( 'Διατήρηση', 'forms' ); ?></h2>
			<div class="frm-zone frm-grid2">
				<?php
				self::input( 'frm[keep]', (string) $s['keep'], __( 'Μηνύματα: μήνες (0 = για πάντα)', 'forms' ), 'number', '', ' min="0" max="120"' );
				self::input( 'frm[spam_days]', (string) $s['spam_days'], __( 'Spam: ημέρες', 'forms' ), 'number', '', ' min="1" max="90"' );
				?>
				<label><span><?php esc_html_e( 'Απεγκατάσταση', 'forms' ); ?></span><?php self::checkbox( 'frm[wipe]', (bool) $s['wipe'], __( 'Σβήσε και τα μηνύματα όταν διαγράφεται το plugin', 'forms' ) ); ?></label>
			</div>

			<h2 class="frm-h2"><?php esc_html_e( 'Προστασία από spam (όλες οι φόρμες)', 'forms' ); ?></h2>
			<div class="frm-zone frm-grid2">
				<?php
				self::input( 'frm[min_secs]', (string) $s['min_secs'], __( 'Ελάχιστα δευτερόλεπτα πριν την αποστολή', 'forms' ), 'number', '', ' min="1" max="30"' );
				self::input( 'frm[pow]', (string) $s['pow'], __( 'Δυσκολία proof of work (bits, 12–20)', 'forms' ), 'number', __( '16 ≈ λιγότερο από 1 δευτερόλεπτο σε κινητό.', 'forms' ), ' min="12" max="20"' );
				self::input( 'frm[rl_form]', (string) $s['rl_form'], __( 'Μηνύματα ανά φόρμα ανά επισκέπτη σε 10 λεπτά', 'forms' ), 'number', '', ' min="1" max="100"' );
				self::input( 'frm[rl_all]', (string) $s['rl_all'], __( 'Μηνύματα ανά επισκέπτη την ώρα (όλες οι φόρμες)', 'forms' ), 'number', '', ' min="1" max="500"' );
				?>
			</div>
			<p class="frm-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'forms' ); ?></button></p>
		</form>

		<?php
		$st      = FRM_Spam::stats( 30 );
		$reasons = FRM_Spam::reasons();
		?>
		<h2 class="frm-h2"><?php esc_html_e( 'Μπλοκαρισμένες προσπάθειες (30 ημέρες)', 'forms' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: today, 2: 30 days */
				esc_html__( 'Σήμερα: %1$d · 30 ημέρες: %2$d', 'forms' ),
				(int) $st['today'],
				(int) $st['total']
			);
			?>
		</p>
		<?php if ( $st['by_reason'] ) : ?>
			<table class="widefat frm-table frm-narrow">
				<thead><tr><th><?php esc_html_e( 'Λόγος', 'forms' ); ?></th><th><?php esc_html_e( 'Πλήθος', 'forms' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $st['by_reason'] as $r => $n ) : ?>
					<tr><td><?php echo esc_html( $reasons[ $r ] ?? $r ); ?></td><td><?php echo (int) $n; ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'Μόνο πλήθος ανά ημέρα: κανένα περιεχόμενο και καμία IP. Μετράει έως 20 φορές την ώρα ανά επισκέπτη.', 'forms' ); ?></p>
		<?php
	}

	private static function render_texts(): void {
		$s = FRM_Settings::get();
		?>
		<form method="post">
			<?php self::nonce(); ?>
			<?php self::action( 'save_settings' ); ?>
			<input type="hidden" name="frm_tab" value="texts" />
			<p class="description"><?php esc_html_e( 'Τα κείμενα που βλέπει ο επισκέπτης, σε όλες τις φόρμες. Το κουμπί και το μήνυμα επιτυχίας αλλάζουν και ανά φόρμα. Κενό αγγλικό = το ελληνικό.', 'forms' ); ?></p>
			<table class="widefat frm-table">
				<thead><tr><th><?php esc_html_e( 'Ελληνικά', 'forms' ); ?></th><th><?php esc_html_e( 'Αγγλικά', 'forms' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( FRM_Settings::texts() as $k => $t ) : ?>
					<tr>
						<td><input type="text" class="frm-wide" name="frm[t_<?php echo esc_attr( $k ); ?>_el]" value="<?php echo esc_attr( (string) $s[ 't_' . $k . '_el' ] ); ?>" maxlength="<?php echo (int) $t[2]; ?>" /></td>
						<td><input type="text" class="frm-wide" name="frm[t_<?php echo esc_attr( $k ); ?>_en]" value="<?php echo esc_attr( (string) $s[ 't_' . $k . '_en' ] ); ?>" maxlength="<?php echo (int) $t[2]; ?>" placeholder="<?php echo esc_attr( $t[1] ); ?>" /></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="frm-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση κειμένων', 'forms' ); ?></button></p>
		</form>
		<?php
	}

	/* =====================================================================
	 * Tools
	 * =================================================================== */

	private static function render_tools(): void {
		$found = FRM_CF7::found();
		?>
		<h2 class="frm-h2"><?php esc_html_e( 'Εισαγωγή από Contact Form 7', 'forms' ); ?></h2>
		<div class="frm-zone">
			<?php if ( ! $found ) : ?>
				<p class="frm-muted"><?php esc_html_e( 'Δεν βρέθηκαν φόρμες του Contact Form 7 σε αυτό το site.', 'forms' ); ?></p>
			<?php else : ?>
				<p class="frm-muted"><?php esc_html_e( 'Η εισαγωγή διαβάζει μόνο: το Contact Form 7 και οι φόρμες του μένουν όπως είναι. Η νέα φόρμα είναι ανενεργή μέχρι να τη δεις και να την ενεργοποιήσεις. Όταν απενεργοποιήσεις το Contact Form 7, το shortcode του δείχνει τη φόρμα του Forms.', 'forms' ); ?></p>
				<table class="widefat frm-table">
					<thead><tr>
						<th><?php esc_html_e( 'Φόρμα Contact Form 7', 'forms' ); ?></th>
						<th><?php esc_html_e( 'Shortcode', 'forms' ); ?></th>
						<th><?php esc_html_e( 'Στο Forms', 'forms' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $found as $c ) : ?>
						<tr>
							<td><?php echo esc_html( $c['title'] ); ?> <small class="frm-muted">#<?php echo (int) $c['id']; ?></small></td>
							<td><code>[contact-form-7 id="<?php echo esc_html( '' !== $c['short'] ? $c['short'] : (string) $c['id'] ); ?>"]</code></td>
							<td>
								<?php if ( $c['imported'] ) : ?>
									<a href="<?php echo esc_url( self::url( 'forms', array( 'edit' => $c['imported'] ) ) ); ?>"><?php echo esc_html( (string) ( FRM_Forms::get( $c['imported'] )['title'] ?? '' ) ); ?></a>
								<?php else : ?>
									<span class="frm-muted">—</span>
								<?php endif; ?>
							</td>
							<td>
								<form method="post">
									<?php self::nonce(); ?>
									<?php self::action( 'cf7_import' ); ?>
									<input type="hidden" name="frm_cf7" value="<?php echo (int) $c['id']; ?>" />
									<button type="submit" class="button<?php echo $c['imported'] ? '' : ' button-primary'; ?>"><?php echo esc_html( $c['imported'] ? __( 'Νέα εισαγωγή', 'forms' ) : __( 'Εισαγωγή', 'forms' ) ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="frm-muted">
					<?php
					echo FRM_CF7::active()
						? esc_html__( 'Το Contact Form 7 είναι ακόμα ενεργό: το shortcode του δείχνει ακόμα τις δικές του φόρμες.', 'forms' )
						: esc_html__( 'Το Contact Form 7 δεν είναι ενεργό: τα shortcodes του που έχουν εισαχθεί δείχνουν τις φόρμες του Forms.', 'forms' );
					?>
				</p>
			<?php endif; ?>
		</div>

		<h2 class="frm-h2"><?php esc_html_e( 'Backup φορμών και ρυθμίσεων', 'forms' ); ?></h2>
		<div class="frm-zone">
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( self::url( 'tools', array( 'frm_export' => 1 ) ), self::NONCE_GET ) ); ?>"><?php esc_html_e( 'Εξαγωγή JSON', 'forms' ); ?></a></p>
			<form method="post" enctype="multipart/form-data">
				<?php self::nonce(); ?>
				<?php self::action( 'import' ); ?>
				<input type="file" name="frm_import_file" accept="application/json,.json" />
				<button type="submit" class="button"><?php esc_html_e( 'Εισαγωγή JSON', 'forms' ); ?></button>
			</form>
			<p class="frm-muted"><?php esc_html_e( 'Το αρχείο έχει τις ρυθμίσεις, τα κείμενα και τις φόρμες, όχι μηνύματα. Η εισαγωγή αντικαθιστά τις ρυθμίσεις και προσθέτει τις φόρμες ως νέες, ανενεργές.', 'forms' ); ?></p>
		</div>

		<h2 class="frm-h2"><?php esc_html_e( 'Για developers', 'forms' ); ?></h2>
		<div class="frm-zone">
			<p class="frm-muted"><code>[nx_form id="…"]</code> · block <code>forms/form</code> · <code>frm_get_form( $id )</code>, <code>frm_get_entries( $args )</code>, <code>frm_get_entry( $id )</code> · actions <code>frm_submission</code>, <code>frm_entry_created</code> · filters <code>frm_spam_check</code>, <code>frm_mail_args</code>, <code>frm_text</code> · <code>define( 'FRM_DISABLE', true );</code></p>
		</div>
		<?php
	}

	/* =====================================================================
	 * Routing (POST)
	 * =================================================================== */

	public static function route(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['frm_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Noxpress_Forms::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'forms' ) );
		}
		$id  = isset( $_POST['frm_id'] ) ? absint( $_POST['frm_id'] ) : 0;
		$act = sanitize_key( wp_unslash( $_POST['frm_action'] ) );
		if ( 'save_form' === $act && isset( $_POST['frm_action_alt'] ) && 'duplicate' === sanitize_key( wp_unslash( $_POST['frm_action_alt'] ) ) ) {
			$act = 'duplicate';
		}

		switch ( $act ) {
			case 'save_form':
			case 'duplicate':
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- every value goes through FRM_Forms::sanitize().
				$schema           = isset( $_POST['frm_s'] ) && is_array( $_POST['frm_s'] ) ? wp_unslash( $_POST['frm_s'] ) : array();
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- every value goes through FRM_Fields::sanitize().
				$fields           = isset( $_POST['frm_f'] ) && is_array( $_POST['frm_f'] ) ? wp_unslash( $_POST['frm_f'] ) : array();
				unset( $fields['__I__'] );
				$schema['fields'] = array_values( $fields );
				$schema['spam']   = isset( $schema['spam'] ) && is_array( $schema['spam'] ) ? $schema['spam'] : array();
				foreach ( array( 'pow', 'nojs' ) as $k ) {
					$schema['spam'][ $k ] = ! empty( $schema['spam'][ $k ] ); // An unticked box sends nothing.
				}
				$title  = isset( $_POST['frm_title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['frm_title'] ) ) : '';
				$active = ! empty( $_POST['frm_active'] );
				$old    = $id > 0 ? FRM_Forms::get( $id ) : null;
				if ( $old ) {
					$schema['cf7'] = $old['cf7']; // Kept: not in the builder.
				}
				if ( 'duplicate' === $act ) {
					/* translators: %s: form title */
					$title         = sprintf( __( '%s (αντίγραφο)', 'forms' ), $title );
					$schema['cf7'] = array();
					$new           = FRM_Forms::save( 0, $title, $schema, false );
					self::redirect(
						'forms&edit=' . $new,
						array( $new ? self::msg( 'success', __( 'Το αντίγραφο φτιάχτηκε (ανενεργό).', 'forms' ) ) : self::msg( 'error', __( 'Η φόρμα δεν αποθηκεύτηκε.', 'forms' ) ) )
					);
				}
				$saved = FRM_Forms::save( $old ? $id : 0, $title, $schema, $active );
				if ( ! $saved ) {
					self::redirect( 'forms', array( self::msg( 'error', __( 'Η φόρμα δεν αποθηκεύτηκε.', 'forms' ) ) ) );
				}
				self::redirect( 'forms&edit=' . $saved, self::form_warnings( (array) FRM_Forms::get( $saved ) ) );
				break;

			case 'delete_form':
				$n  = FRM_Entries::delete_for_form( $id );
				$ok = FRM_Forms::delete( $id );
				if ( $ok ) {
					FRM_CF7::unmap( $id );
				}
				self::redirect(
					'forms',
					array(
						$ok
							/* translators: %d: number of messages */
							? self::msg( 'success', sprintf( __( 'Η φόρμα διαγράφηκε, μαζί με %d μηνύματα.', 'forms' ), $n ) )
							: self::msg( 'error', __( 'Η φόρμα δεν βρέθηκε.', 'forms' ) ),
					)
				);
				break;

			case 'state':
				$state = isset( $_POST['frm_state'] ) ? sanitize_key( wp_unslash( $_POST['frm_state'] ) ) : '';
				$ok    = FRM_Entries::set_state( $id, $state );
				self::redirect( 'messages&view=' . $id, array( $ok ? self::msg( 'success', __( 'Η κατάσταση αποθηκεύτηκε.', 'forms' ) ) : self::msg( 'error', __( 'Το μήνυμα δεν βρέθηκε.', 'forms' ) ) ) );
				break;

			case 'resend':
				$data = FRM_Entries::data( $id );
				$p    = $data ? get_post( $id ) : null;
				$form = $p ? FRM_Forms::get( (int) $p->post_parent ) : null;
				if ( ! $data || ! $form ) {
					self::redirect( 'messages', array( self::msg( 'error', __( 'Το μήνυμα δεν βρέθηκε.', 'forms' ) ) ) );
				}
				$data['entry'] = $id;
				$sent          = FRM_Mailer::notify( $form, $data );
				FRM_Entries::set_mail( $id, $sent ? 'sent' : 'failed' );
				self::redirect( 'messages&view=' . $id, array( $sent ? self::msg( 'success', __( 'Το email στάλθηκε.', 'forms' ) ) : self::msg( 'error', __( 'Το email δεν στάλθηκε ξανά. Έλεγξε τη ρύθμιση email του site.', 'forms' ) ) ) );
				break;

			case 'delete':
				$ok = FRM_Entries::delete( $id );
				self::redirect( 'messages', array( $ok ? self::msg( 'success', __( 'Το μήνυμα διαγράφηκε.', 'forms' ) ) : self::msg( 'error', __( 'Το μήνυμα δεν βρέθηκε.', 'forms' ) ) ) );
				break;

			case 'save_settings':
				$tab = isset( $_POST['frm_tab'] ) && 'texts' === $_POST['frm_tab'] ? 'texts' : 'settings';
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- every value goes through FRM_Settings::sanitize().
				$raw = isset( $_POST['frm'] ) && is_array( $_POST['frm'] ) ? wp_unslash( $_POST['frm'] ) : array();
				$s   = FRM_Settings::get();
				foreach ( $raw as $k => $v ) {
					if ( is_string( $k ) && array_key_exists( $k, $s ) && is_scalar( $v ) ) {
						$s[ $k ] = $v;
					}
				}
				if ( 'settings' === $tab ) {
					$s['wipe'] = ! empty( $raw['wipe'] );
				}
				FRM_Settings::save( $s );
				self::redirect( $tab, array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'forms' ) ) ) );
				break;

			case 'cf7_import':
				$cf7 = isset( $_POST['frm_cf7'] ) ? absint( $_POST['frm_cf7'] ) : 0;
				$r   = FRM_CF7::import( $cf7 );
				$out = array();
				if ( $r['ok'] ) {
					/* translators: %s: form title */
					$out[] = self::msg( 'success', sprintf( __( 'Η φόρμα «%s» εισήχθη (ανενεργή). Έλεγξέ τη και ενεργοποίησέ τη.', 'forms' ), $r['title'] ) );
				} else {
					$out[] = self::msg( 'error', __( 'Η εισαγωγή δεν έγινε.', 'forms' ) );
				}
				foreach ( $r['notes'] as $note ) {
					$out[] = self::msg( 'warning', $note );
				}
				self::redirect( $r['ok'] ? 'forms&edit=' . $r['form'] : 'tools', $out );
				break;

			case 'import':
				self::redirect( 'tools', self::do_import() );
				break;

			default:
				self::redirect( 'forms', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'forms' ) ) ) );
		}
	}

	/** Notes after a save: what keeps a form from working as expected. */
	private static function form_warnings( array $form ): array {
		$out = array( self::msg( 'success', __( 'Η φόρμα αποθηκεύτηκε.', 'forms' ) ) );
		if ( empty( $form['fields'] ) ) {
			$out[] = self::msg( 'warning', __( 'Η φόρμα δεν έχει πεδία.', 'forms' ) );
			return $out;
		}
		$inputs = array_filter(
			$form['fields'],
			static function ( $f ) {
				return in_array( $f['type'], FRM_Fields::INPUTS, true ) && 'consent' !== $f['type'];
			}
		);
		if ( ! $inputs ) {
			$out[] = self::msg( 'warning', __( 'Η φόρμα δεν έχει κανένα πεδίο που συμπληρώνει ο επισκέπτης.', 'forms' ) );
		}
		if ( ! empty( $form['oros'] ) && ( ! FRM_Forms::has_type( $form, 'consent' ) || '' === $form['mail']['email_key'] ) ) {
			$out[] = self::msg( 'warning', __( 'Για εγγραφή στο orOS Newsletter η φόρμα χρειάζεται πεδίο Email και πεδίο Συναίνεση.', 'forms' ) );
		}
		if ( FRM_Submit::has_product_field( $form ) && ! Noxpress_Forms::woo() ) {
			$out[] = self::msg( 'warning', __( 'Το πεδίο «Το προϊόν της σελίδας» χρειάζεται WooCommerce.', 'forms' ) );
		}
		if ( ! empty( $form['auto']['on'] ) && '' === $form['mail']['email_key'] ) {
			$out[] = self::msg( 'warning', __( 'Η αυτόματη απάντηση χρειάζεται πεδίο Email.', 'forms' ) );
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
		set_transient( 'frm_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab ) );
		exit;
	}

	/* =====================================================================
	 * Exports (GET) and import (POST)
	 * =================================================================== */

	public static function route_export(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		$json = '' !== self::q( 'frm_export' );
		$csv  = '' !== self::q( 'frm_csv' );
		if ( ! $json && ! $csv ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_GET ) || ! current_user_can( Noxpress_Forms::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'forms' ) );
		}
		nocache_headers();
		if ( $json ) {
			$forms = array();
			foreach ( FRM_Forms::all() as $f ) {
				$schema = $f;
				unset( $schema['id'], $schema['title'], $schema['active'], $schema['modified'] );
				$forms[] = array(
					'title'  => $f['title'],
					'active' => $f['active'],
					'schema' => $schema,
				);
			}
			$state = array(
				'plugin'  => 'forms',
				'version' => FRM_VERSION,
				'options' => array(
					FRM_Settings::OPT => FRM_Settings::get(),
				),
				'forms'   => $forms,
			);
			header( 'Content-Type: application/json; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="forms-backup-' . gmdate( 'Y-m-d' ) . '.json"' );
			echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
			exit;
		}

		$flt  = self::msg_filters();
		$form = $flt['form'] ? FRM_Forms::get( $flt['form'] ) : null;
		$sep  = 'el' === FRM_Lang::lang() ? ';' : ',';
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="forms-messages-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CSV download stream.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM for Excel.
		$head = array( 'id', __( 'Ημερομηνία', 'forms' ), __( 'Φόρμα', 'forms' ), __( 'Κατάσταση', 'forms' ), 'Email ' . __( 'ειδοποίησης', 'forms' ), __( 'Όνομα', 'forms' ), 'Email', __( 'Σελίδα', 'forms' ) );
		$keys = array();
		if ( $form ) {
			foreach ( $form['fields'] as $f ) {
				if ( 'note' !== $f['type'] ) {
					$keys[] = $f['key'];
					$head[] = FRM_Fields::plain_label( $f, 'el' );
				}
			}
		} else {
			$head[] = __( 'Πεδία', 'forms' );
		}
		Noxpress_Safe::csv_row( $out, $head, $sep );
		$page = 1;
		do {
			$q = FRM_Entries::query(
				array(
					'form'   => $flt['form'],
					'state'  => 'all' === $flt['state'] ? '' : $flt['state'],
					'mail'   => $flt['mail'],
					'search' => $flt['search'],
					'page'   => $page,
					'per'    => 200,
				)
			);
			foreach ( $q['ids'] as $id ) {
				$e = FRM_Entries::get( $id );
				if ( ! $e ) {
					continue;
				}
				$row = array( $id, get_post_time( 'Y-m-d H:i', false, $id ), $e['form_title'], $e['state'], $e['mail'], $e['name'], $e['email'], $e['page'] );
				$by  = array();
				foreach ( $e['fields'] as $f ) {
					$by[ (string) $f['key'] ] = $f;
				}
				if ( $form ) {
					foreach ( $keys as $k ) {
						$row[] = isset( $by[ $k ] ) ? (string) $by[ $k ]['value'] : '';
					}
				} else {
					$parts = array();
					foreach ( $e['fields'] as $f ) {
						if ( '' !== (string) $f['value'] ) {
							$parts[] = $f['label'] . ': ' . $f['value'];
						}
					}
					$row[] = implode( "\n", $parts );
				}
				Noxpress_Safe::csv_row( $out, $row, $sep );
			}
			++$page;
		} while ( count( $q['ids'] ) === 200 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CSV download stream.
		exit;
	}

	/** Import a backup: settings validated as a whole, forms added as new and inactive. */
	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['frm_import_file']['error'] ) ? (int) $_FILES['frm_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['frm_import_file']['size'] ) ? (int) $_FILES['frm_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'forms' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['frm_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['frm_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'forms' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['frm_import_file']['tmp_name'] ), true );
		// phpcs:enable
		if ( ! is_array( $state ) || ( $state['plugin'] ?? '' ) !== 'forms' ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup του Forms.', 'forms' ) ) );
		}
		$out  = array();
		$fail = false;
		$blob = $state['options'][ FRM_Settings::OPT ] ?? null;
		if ( is_array( $blob ) ) {
			FRM_Settings::save( $blob );
			$out[] = self::msg( 'success', __( 'Οι ρυθμίσεις εισήχθησαν.', 'forms' ) );
		} elseif ( null !== $blob ) {
			$fail  = true;
			$out[] = self::msg( 'error', __( 'Οι ρυθμίσεις του αρχείου δεν είναι έγκυρες· οι τωρινές έμειναν ίδιες.', 'forms' ) );
		}
		$n = 0;
		if ( isset( $state['forms'] ) && is_array( $state['forms'] ) ) {
			foreach ( array_slice( $state['forms'], 0, 100 ) as $f ) {
				if ( ! is_array( $f ) || ! isset( $f['schema'] ) || ! is_array( $f['schema'] ) ) {
					$fail = true;
					continue;
				}
				$f['schema']['cf7'] = array();
				if ( FRM_Forms::save( 0, (string) ( $f['title'] ?? '' ), $f['schema'], false ) ) {
					++$n;
				} else {
					$fail = true;
				}
			}
		}
		/* translators: %d: number of forms */
		$out[] = self::msg( $fail ? 'warning' : 'success', sprintf( __( 'Φόρμες που προστέθηκαν (ανενεργές): %d.', 'forms' ), $n ) );
		if ( $fail ) {
			$out[] = self::msg( 'warning', __( 'Η εισαγωγή ολοκληρώθηκε ΜΕΡΙΚΩΣ.', 'forms' ) );
		}
		return $out;
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'frm_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'frm_aui_msg_' . get_current_user_id() );
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
		<p class="frm-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'forms' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="frm-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="frm-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'forms' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="frm-footer-cta">
			<a class="frm-kofi" href="https://noxpress.tech/support/" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '♡ Στήριξε το Noxpress', 'forms' ); ?>
			</a>
			<a class="frm-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'forms' ); ?>
			</a>
		</p>
		<?php
	}
}

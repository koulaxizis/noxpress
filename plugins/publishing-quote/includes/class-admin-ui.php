<?php
/**
 * PBQ_Admin_UI — menu, settings, test bench, requests, tools (Bible §3–§8,
 * §11).
 *
 * Submenu «Publishing Quote» of the shared "Noxpress" menu (admin_menu
 * priority 180), slug pbq-settings, capability Publishing_Quote::cap().
 * Tabs:
 *  - general    mode, visitor language, VAT, rounding, validity, e-mails,
 *               retention, currency (without WooCommerce)
 *  - services   editing (levels), cover, illustrations, printing, the
 *               hidden typesetting fee
 *  - templates  sizes, papers, bindings, cover finishes
 *  - extras     extra services
 *  - texts      every visitor text, Greek and English
 *  - test       the calculator with the full breakdown (read only, GET)
 *  - requests   «I'm interested» requests: list, view, state, delete, CSV
 *  - tools      settings backup, developers
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient pbq_aui_msg_{uid}). Exports: privileged GET.
 */

defined( 'ABSPATH' ) || exit;

final class PBQ_Admin_UI {

	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'pbq-settings';
	const NONCE       = 'pbq_admin';
	const NONCE_FIELD = 'pbq_nonce_admin';
	const NONCE_BAK   = 'pbq_backup';
	const MAX_IMPORT  = 524288;
	const PER_PAGE    = 30;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 180 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PBQ_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label. */
	private static function tabs(): array {
		return array(
			'general'   => __( 'Γενικά', 'publishing-quote' ),
			'services'  => __( 'Υπηρεσίες', 'publishing-quote' ),
			'templates' => __( 'Templates', 'publishing-quote' ),
			'extras'    => __( 'Έξτρα παροχές', 'publishing-quote' ),
			'texts'     => __( 'Κείμενα', 'publishing-quote' ),
			'test'      => __( 'Δοκιμή', 'publishing-quote' ),
			'requests'  => __( 'Αιτήματα', 'publishing-quote' ),
			'tools'     => __( 'Εργαλεία', 'publishing-quote' ),
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
			__( 'Publishing Quote', 'publishing-quote' ),
			__( 'Publishing Quote', 'publishing-quote' ),
			Publishing_Quote::cap(),
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'publishing-quote' ) . '</a>'
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
		return isset( self::tabs()[ $tab ] ) ? $tab : 'general';
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'pbq-admin', PBQ_URL . 'assets/admin.css', array(), PBQ_VERSION );
		wp_enqueue_script( 'pbq-admin', PBQ_URL . 'assets/admin.js', array(), PBQ_VERSION, true );
		if ( in_array( self::current_tab(), array( 'test', 'requests' ), true ) ) {
			wp_enqueue_style( 'pbq-front', PBQ_URL . 'assets/front.css', array(), PBQ_VERSION );
		}
	}

	/** Suggested text for the site's privacy policy (Settings → Privacy). */
	public static function privacy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p>' . esc_html__( 'Όταν ζητάς προσφορά έκδοσης από τον υπολογιστή μας («Ενδιαφέρομαι»), κρατάμε το όνομα, το email, το τηλέφωνο αν το δώσεις, το μήνυμά σου, τις επιλογές σου και το ποσό που είδες, για να σου στείλουμε την προσφορά. Λαμβάνουμε τα στοιχεία με email.', 'publishing-quote' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Τα αιτήματα σβήνονται αυτόματα μετά από τους μήνες που ορίζονται στις ρυθμίσεις, και μπορείς να ζητήσεις αντίγραφο ή διαγραφή τους όποτε θέλεις.', 'publishing-quote' ) . '</p>';
		wp_add_privacy_policy_content( 'Publishing Quote', wp_kses_post( $text ) );
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Publishing_Quote::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'publishing-quote' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap pbq-wrap">
			<h1><?php esc_html_e( 'Publishing Quote', 'publishing-quote' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Κοστολόγηση έκδοσης για υποψήφιους συγγραφείς. Ο επισκέπτης βλέπει μόνο το ποσό ανά υπηρεσία και το σύνολο· οι συντελεστές και η σελιδοποίηση μένουν στον server.', 'publishing-quote' ); ?></p>

			<?php
			self::print_notices( self::take_msgs() );
			if ( Publishing_Quote::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το PBQ_DISABLE είναι ενεργό στο wp-config.php: ο υπολογιστής και η φόρμα δεν φαίνονται πουθενά.', 'publishing-quote' ) . '</p></div>';
			}
			?>

			<nav class="nav-tab-wrapper pbq-tabs">
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
			$method = 'render_' . $tab;
			self::$method();
			self::footer();
			?>
		</div>
		<?php
	}

	/* ---------- small field helpers ---------- */

	/** A money / factor value for an input: "0.012", "12", "1.5". */
	private static function m( float $v ): string {
		$t = rtrim( rtrim( number_format( $v, 4, '.', '' ), '0' ), '.' );
		return '' === $t ? '0' : $t;
	}

	private static function vat_val( float $v ): string {
		return $v < 0 ? '' : self::m( $v );
	}

	private static function checkbox( string $name, bool $on, string $label ): void {
		printf(
			'<label class="pbq-check"><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label )
		);
	}

	private static function radio( string $name, string $value, string $current, string $label ): void {
		printf(
			'<label class="pbq-check"><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $value, $current, false ),
			esc_html( $label )
		);
	}

	/** A labelled input in a .pbq-grid2. */
	private static function input( string $name, string $value, string $label, string $cls = 'pbq-num', string $type = 'text', string $hint = '' ): void {
		printf(
			'<label><span>%1$s</span><input type="%2$s" name="%3$s" value="%4$s" class="%5$s"%6$s />%7$s</label>',
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $cls ),
			'pbq-num' === $cls || 'pbq-num-s' === $cls ? ' inputmode="decimal"' : '',
			'' !== $hint ? '<small class="pbq-hint pbq-muted">' . esc_html( $hint ) . '</small>' : ''
		);
	}

	private static function badge( string $text, string $kind ): string {
		return '<span class="pbq-badge pbq-badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function form_open( string $tab ): void {
		echo '<form method="post">';
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		echo '<input type="hidden" name="pbq_action" value="save" /><input type="hidden" name="pbq_tab" value="' . esc_attr( $tab ) . '" />';
	}

	private static function form_close(): void {
		echo '<p class="pbq-submit"><button type="submit" class="button button-primary">' . esc_html__( 'Αποθήκευση ρυθμίσεων', 'publishing-quote' ) . '</button></p></form>';
	}

	/* ---------- list editors ---------- */

	/** Column specs of each list: field => array( label, type ). */
	private static function columns( string $list ): array {
		$name = array(
			'el' => array( __( 'Όνομα (ελληνικά)', 'publishing-quote' ), 'name' ),
			'en' => array( __( 'Όνομα (English)', 'publishing-quote' ), 'name' ),
		);
		switch ( $list ) {
			case 'levels':
				return $name + array( 'rate' => array( __( 'Τιμή ανά σελίδα χειρογράφου', 'publishing-quote' ), 'money' ) );
			case 'sizes':
				return $name + array(
					'factor' => array( __( 'Συντελεστής εκτύπωσης', 'publishing-quote' ), 'factor' ),
					'wpp'    => array( __( 'Λέξεις ανά σελίδα βιβλίου', 'publishing-quote' ), 'int' ),
					'sig'    => array( __( 'Πολλαπλάσιο σελίδων', 'publishing-quote' ), 'int' ),
				);
			case 'papers':
				return $name + array( 'page' => array( __( 'Τιμή ανά σελίδα και αντίτυπο', 'publishing-quote' ), 'money' ) );
			case 'bindings':
				return $name + array(
					'copy'  => array( __( 'Ανά αντίτυπο', 'publishing-quote' ), 'money' ),
					'setup' => array( __( 'Στήσιμο', 'publishing-quote' ), 'money' ),
					'pmin'  => array( __( 'Ελάχιστες σελίδες', 'publishing-quote' ), 'int' ),
					'pmax'  => array( __( 'Μέγιστες σελίδες', 'publishing-quote' ), 'int' ),
					'hard'  => array( __( 'Σκληρό', 'publishing-quote' ), 'bool' ),
				);
			case 'finishes':
				return $name + array(
					'kind'  => array( __( 'Είδος', 'publishing-quote' ), 'kind_f' ),
					'copy'  => array( __( 'Ανά αντίτυπο', 'publishing-quote' ), 'money' ),
					'setup' => array( __( 'Στήσιμο', 'publishing-quote' ), 'money' ),
					'hard'  => array( __( 'Μόνο με σκληρό', 'publishing-quote' ), 'bool' ),
				);
			case 'extras':
				return $name + array(
					'd_el'  => array( __( 'Περιγραφή (ελληνικά)', 'publishing-quote' ), 'long' ),
					'd_en'  => array( __( 'Περιγραφή (English)', 'publishing-quote' ), 'long' ),
					'kind'  => array( __( 'Χρέωση', 'publishing-quote' ), 'kind_e' ),
					'price' => array( __( 'Τιμή', 'publishing-quote' ), 'money' ),
					'min'   => array( __( 'Ελάχιστη χρέωση', 'publishing-quote' ), 'money' ),
					'vat'   => array( __( 'ΦΠΑ %', 'publishing-quote' ), 'vat' ),
					'ask'   => array( __( 'Κατόπιν συζήτησης', 'publishing-quote' ), 'bool' ),
					'qmax'  => array( __( 'Μέγιστη ποσότητα', 'publishing-quote' ), 'int' ),
				);
			case 'tiers':
				return array(
					'from' => array( __( 'Από (τεμάχια)', 'publishing-quote' ), 'int' ),
					'pct'  => array( __( 'Έκπτωση %', 'publishing-quote' ), 'money' ),
				);
		}
		return array();
	}

	private static function cell( string $base, string $f, string $type, $v ): string {
		$n = $base . '[' . $f . ']';
		switch ( $type ) {
			case 'name':
				return '<input type="text" class="pbq-name" name="' . esc_attr( $n ) . '" value="' . esc_attr( (string) $v ) . '" maxlength="120" />';
			case 'long':
				return '<textarea name="' . esc_attr( $n ) . '" rows="2" maxlength="300">' . esc_textarea( (string) $v ) . '</textarea>';
			case 'money':
			case 'factor':
				return '<input type="text" inputmode="decimal" class="pbq-num" name="' . esc_attr( $n ) . '" value="' . esc_attr( self::m( (float) $v ) ) . '" />';
			case 'vat':
				return '<input type="text" inputmode="decimal" class="pbq-num-s" name="' . esc_attr( $n ) . '" value="' . esc_attr( self::vat_val( (float) $v ) ) . '" placeholder="' . esc_attr( PBQ_Settings::rate_label( (float) PBQ_Settings::get()['vat'] ) ) . '" />';
			case 'int':
				return '<input type="number" class="pbq-num-s" name="' . esc_attr( $n ) . '" value="' . esc_attr( (string) (int) $v ) . '" min="0" step="1" />';
			case 'bool':
				return '<input type="checkbox" name="' . esc_attr( $n ) . '" value="1"' . checked( ! empty( $v ), true, false ) . ' />';
			case 'kind_f':
				return '<select name="' . esc_attr( $n ) . '">'
					. '<option value="one"' . selected( 'one', $v, false ) . '>' . esc_html__( 'Ένα από τα βασικά', 'publishing-quote' ) . '</option>'
					. '<option value="add"' . selected( 'add', $v, false ) . '>' . esc_html__( 'Πρόσθετο', 'publishing-quote' ) . '</option></select>';
			case 'kind_e':
				$opts = array(
					'fixed' => __( 'Πάγιο', 'publishing-quote' ),
					'qty'   => __( 'Ανά τεμάχιο', 'publishing-quote' ),
					'copy'  => __( 'Ανά αντίτυπο του τιράζ', 'publishing-quote' ),
					'words' => __( 'Ανά λέξη του κειμένου', 'publishing-quote' ),
				);
				$h    = '<select name="' . esc_attr( $n ) . '">';
				foreach ( $opts as $k => $l ) {
					$h .= '<option value="' . esc_attr( $k ) . '"' . selected( $k, $v, false ) . '>' . esc_html( $l ) . '</option>';
				}
				return $h . '</select>';
		}
		return '';
	}

	private static function list_row( string $field, string $key, array $cols, array $r, bool $has_on ): string {
		$base = 'pbq[' . $field . '][' . $key . ']';
		$off  = $has_on && empty( $r['on'] );
		$h    = '<tr' . ( $off ? ' class="is-off"' : '' ) . '>';
		if ( $has_on ) {
			$h .= '<td class="pbq-on"><input type="hidden" name="' . esc_attr( $base . '[id]' ) . '" value="' . esc_attr( isset( $r['id'] ) ? $r['id'] : '' ) . '" />'
				. '<input type="checkbox" name="' . esc_attr( $base . '[on]' ) . '" value="1"' . checked( ! empty( $r['on'] ), true, false ) . ' title="' . esc_attr__( 'Ενεργό', 'publishing-quote' ) . '" /></td>';
		}
		foreach ( $cols as $f => $c ) {
			$h .= '<td>' . self::cell( $base, $f, $c[1], isset( $r[ $f ] ) ? $r[ $f ] : '' ) . '</td>';
		}
		$h .= '<td class="pbq-ctl">'
			. '<button type="button" class="button pbq-mini" data-pbq-act="up" title="' . esc_attr__( 'Πάνω', 'publishing-quote' ) . '">↑</button> '
			. '<button type="button" class="button pbq-mini" data-pbq-act="down" title="' . esc_attr__( 'Κάτω', 'publishing-quote' ) . '">↓</button> '
			. '<button type="button" class="button pbq-mini" data-pbq-act="del" title="' . esc_attr__( 'Αφαίρεση', 'publishing-quote' ) . '">✕</button></td>';
		return $h . '</tr>';
	}

	/**
	 * An editable list (template rows or tiers).
	 *
	 * @param string $field Settings key (sizes, il_tiers …).
	 * @param string $kind  Column set (lists() name or 'tiers').
	 */
	private static function list_editor( string $field, string $kind, array $rows ): void {
		$cols   = self::columns( $kind );
		$has_on = 'tiers' !== $kind;
		$id     = 'pbq-list-' . $field;
		echo '<div class="pbq-scrollx"><table class="pbq-list" id="' . esc_attr( $id ) . '"><thead><tr>';
		if ( $has_on ) {
			echo '<th>' . esc_html__( 'Ενεργό', 'publishing-quote' ) . '</th>';
		}
		foreach ( $cols as $c ) {
			echo '<th>' . esc_html( $c[0] ) . '</th>';
		}
		echo '<th></th></tr></thead><tbody>';
		$i = 0;
		foreach ( $rows as $r ) {
			echo self::list_row( $field, 'k' . $i++, $cols, $r, $has_on ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in list_row().
		}
		echo '</tbody></table></div>';
		// An empty marker keeps the list in the POST even when every row is removed.
		echo '<input type="hidden" name="pbq[' . esc_attr( $field ) . '][_]" value="" />';
		$blank = $has_on ? array( 'on' => true ) : array();
		echo '<template id="' . esc_attr( $id ) . '-tpl">' . self::list_row( $field, '__KEY__', $cols, $blank, $has_on ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in list_row().
		echo '<p><button type="button" class="button" data-pbq-act="add" data-pbq-list="' . esc_attr( $id ) . '">' . esc_html__( '+ Προσθήκη γραμμής', 'publishing-quote' ) . '</button></p>';
	}

	/* =====================================================================
	 * Tabs
	 * =================================================================== */

	private static function render_general(): void {
		$s = PBQ_Settings::get();
		self::form_open( 'general' );
		?>
		<h2 class="pbq-h2"><?php esc_html_e( 'Λειτουργία', 'publishing-quote' ); ?></h2>
		<div class="pbq-zone">
			<?php
			self::radio( 'pbq[mode]', 'test', $s['mode'], __( 'Δοκιμή: μόνο οι διαχειριστές βλέπουν τον υπολογιστή', 'publishing-quote' ) );
			self::radio( 'pbq[mode]', 'live', $s['mode'], __( 'Ενεργό για όλους', 'publishing-quote' ) );
			self::radio( 'pbq[mode]', 'off', $s['mode'], __( 'Ανενεργό: τίποτα στο site', 'publishing-quote' ) );
			?>
			<p class="description"><?php esc_html_e( 'Πού μπαίνει: το μπλοκ «Publishing quote», το shortcode [publishing_quote] (title="no" χωρίς τίτλο) ή το widget «Κοστολόγηση έκδοσης».', 'publishing-quote' ); ?></p>
		</div>

		<h2 class="pbq-h2"><?php esc_html_e( 'Γλώσσα των κειμένων για τους επισκέπτες', 'publishing-quote' ); ?></h2>
		<div class="pbq-zone">
			<?php
			self::radio( 'pbq[lang]', 'auto', $s['lang'], __( 'Αυτόματα, από τη γλώσσα του site', 'publishing-quote' ) );
			self::radio( 'pbq[lang]', 'el', $s['lang'], __( 'Ελληνικά', 'publishing-quote' ) );
			self::radio( 'pbq[lang]', 'en', $s['lang'], __( 'English', 'publishing-quote' ) );
			?>
		</div>

		<h2 class="pbq-h2"><?php esc_html_e( 'ΦΠΑ και στρογγύλευση', 'publishing-quote' ); ?></h2>
		<div class="pbq-zone">
			<div class="pbq-grid2">
				<?php self::input( 'pbq[vat]', self::m( (float) $s['vat'] ), __( 'Γενικός συντελεστής ΦΠΑ %', 'publishing-quote' ), 'pbq-num', 'text', __( 'Ισχύει όπου μια υπηρεσία δεν έχει δικό της. Ποιος συντελεστής ισχύει για τι το επιβεβαιώνει ο λογιστής σου.', 'publishing-quote' ) ); ?>
				<label><span><?php esc_html_e( 'Βήμα στρογγύλευσης ανά γραμμή', 'publishing-quote' ); ?></span>
					<select name="pbq[step]">
						<?php
						$steps = array(
							1    => __( '0,01 (χωρίς στρογγύλευση)', 'publishing-quote' ),
							10   => '0,10',
							100  => '1',
							500  => '5',
							1000 => '10',
						);
						foreach ( $steps as $v => $l ) {
							echo '<option value="' . (int) $v . '"' . selected( $v, (int) $s['step'], false ) . '>' . esc_html( $l ) . '</option>';
						}
						?>
					</select>
				</label>
			</div>
			<p class="pbq-mt-8">
				<?php
				self::radio( 'pbq[round]', 'up', $s['round'], __( 'Στρογγύλευση προς τα πάνω', 'publishing-quote' ) );
				self::radio( 'pbq[round]', 'near', $s['round'], __( 'Στρογγύλευση στο πλησιέστερο', 'publishing-quote' ) );
				?>
			</p>
			<?php
			self::radio( 'pbq[show]', 'incl', $s['show'], __( 'Τιμές με ΦΠΑ (για ιδιώτες)', 'publishing-quote' ) );
			self::radio( 'pbq[show]', 'excl', $s['show'], __( 'Τιμές χωρίς ΦΠΑ, με τον ΦΠΑ στο τέλος (για επαγγελματίες)', 'publishing-quote' ) );
			?>
			<p class="description"><?php esc_html_e( 'Κάθε γραμμή στρογγυλεύεται χωριστά και το σύνολο είναι το άθροισμα των γραμμών. Η στρογγύλευση δυσκολεύει και όποιον δοκιμάζει τιμές για να βρει τους συντελεστές.', 'publishing-quote' ); ?></p>
		</div>

		<h2 class="pbq-h2"><?php esc_html_e( 'Αποτέλεσμα και αιτήματα', 'publishing-quote' ); ?></h2>
		<div class="pbq-zone">
			<div class="pbq-grid2">
				<?php
				self::input( 'pbq[valid]', (string) (int) $s['valid'], __( 'Ισχύς της τιμής σε ημέρες (0 = χωρίς ημερομηνία)', 'publishing-quote' ), 'pbq-num-s', 'number' );
				self::input( 'pbq[email]', $s['email'], __( 'Email για τα αιτήματα', 'publishing-quote' ), 'pbq-name', 'email', sprintf( /* translators: %s: e-mail */ __( 'Κενό = %s', 'publishing-quote' ), (string) get_option( 'admin_email' ) ) );
				self::input( 'pbq[keep]', (string) (int) $s['keep'], __( 'Διαγραφή αιτημάτων μετά από μήνες (0 = ποτέ)', 'publishing-quote' ), 'pbq-num-s', 'number' );
				?>
			</div>
			<p class="pbq-mt-8">
				<?php
				self::checkbox( 'pbq[ack]', (bool) $s['ack'], __( 'Email «λάβαμε το αίτημά σου» στον επισκέπτη, χωρίς ποσά', 'publishing-quote' ) );
				self::checkbox( 'pbq[privacy]', (bool) $s['privacy'], __( 'Σύνδεσμος στην πολιτική απορρήτου δίπλα στη συναίνεση (όταν έχει οριστεί σελίδα)', 'publishing-quote' ) );
				self::checkbox( 'pbq[wipe]', (bool) $s['wipe'], __( 'Διαγραφή των αιτημάτων στο uninstall', 'publishing-quote' ) );
				?>
			</p>
		</div>

		<?php if ( ! Publishing_Quote::woo() ) : ?>
			<h2 class="pbq-h2"><?php esc_html_e( 'Νόμισμα', 'publishing-quote' ); ?></h2>
			<div class="pbq-zone">
				<div class="pbq-grid2">
					<?php self::input( 'pbq[cur_sym]', $s['cur_sym'], __( 'Σύμβολο', 'publishing-quote' ), 'pbq-num-s' ); ?>
					<label><span><?php esc_html_e( 'Θέση', 'publishing-quote' ); ?></span>
						<select name="pbq[cur_pos]">
							<?php
							foreach ( array(
								'right_space' => '1.234 €',
								'right'       => '1.234€',
								'left_space'  => '€ 1.234',
								'left'        => '€1.234',
							) as $v => $l ) {
								echo '<option value="' . esc_attr( $v ) . '"' . selected( $v, $s['cur_pos'], false ) . '>' . esc_html( $l ) . '</option>';
							}
							?>
						</select>
					</label>
					<label><span><?php esc_html_e( 'Υποδιαστολή', 'publishing-quote' ); ?></span>
						<select name="pbq[cur_dec]"><option value=","<?php selected( ',', $s['cur_dec'] ); ?>>,</option><option value="."<?php selected( '.', $s['cur_dec'] ); ?>>.</option></select>
					</label>
					<label><span><?php esc_html_e( 'Διαχωριστικό χιλιάδων', 'publishing-quote' ); ?></span>
						<select name="pbq[cur_thou]">
							<option value="."<?php selected( '.', $s['cur_thou'] ); ?>>.</option>
							<option value=","<?php selected( ',', $s['cur_thou'] ); ?>>,</option>
							<option value=" "<?php selected( ' ', $s['cur_thou'] ); ?>><?php esc_html_e( 'κενό', 'publishing-quote' ); ?></option>
							<option value=""<?php selected( '', $s['cur_thou'] ); ?>><?php esc_html_e( 'κανένα', 'publishing-quote' ); ?></option>
						</select>
					</label>
				</div>
			</div>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Νόμισμα και μορφή αριθμών: από τις ρυθμίσεις του WooCommerce.', 'publishing-quote' ); ?></p>
		<?php endif; ?>
		<?php
		self::form_close();
	}

	private static function render_services(): void {
		$s   = PBQ_Settings::get();
		$vat = __( 'ΦΠΑ % (κενό = ο γενικός)', 'publishing-quote' );
		self::form_open( 'services' );
		echo '<p class="description">' . esc_html__( 'Όλα τα ποσά χωρίς ΦΠΑ. Ο επισκέπτης δεν βλέπει κανέναν από αυτούς τους αριθμούς, μόνο το τελικό ποσό κάθε υπηρεσίας.', 'publishing-quote' ) . '</p>';
		?>
		<div class="pbq-svc">
			<h3><?php esc_html_e( 'Επιμέλεια', 'publishing-quote' ); ?></h3>
			<?php self::checkbox( 'pbq[ed_on]', (bool) $s['ed_on'], __( 'Ο επισκέπτης μπορεί να ζητήσει επιμέλεια', 'publishing-quote' ) ); ?>
			<p>
				<?php
				self::radio( 'pbq[ed_unit]', 'pages', $s['ed_unit'], __( 'Ο επισκέπτης δίνει σελίδες χειρογράφου (A4)', 'publishing-quote' ) );
				self::radio( 'pbq[ed_unit]', 'words', $s['ed_unit'], __( 'Ο επισκέπτης δίνει λέξεις', 'publishing-quote' ) );
				?>
			</p>
			<div class="pbq-grid2">
				<?php
				self::input( 'pbq[ed_wpp]', (string) (int) $s['ed_wpp'], __( 'Λέξεις ανά σελίδα χειρογράφου', 'publishing-quote' ), 'pbq-num-s', 'number', __( 'Μετατρέπει λέξεις σε σελίδες (για την τιμή) και σελίδες σε λέξεις (για την εκτίμηση των σελίδων του βιβλίου).', 'publishing-quote' ) );
				self::input( 'pbq[ed_max]', (string) (int) $s['ed_max'], __( 'Μέγιστες σελίδες χειρογράφου', 'publishing-quote' ), 'pbq-num-s', 'number' );
				self::input( 'pbq[ed_min]', self::m( (float) $s['ed_min'] ), __( 'Ελάχιστη χρέωση', 'publishing-quote' ) );
				self::input( 'pbq[ed_vat]', self::vat_val( (float) $s['ed_vat'] ), $vat );
				?>
			</div>
			<p class="pbq-label"><?php esc_html_e( 'Είδη επιμέλειας (με ένα μόνο, ο επισκέπτης δεν βλέπει επιλογή)', 'publishing-quote' ); ?></p>
			<?php self::list_editor( 'levels', 'levels', $s['levels'] ); ?>
		</div>

		<div class="pbq-svc">
			<h3><?php esc_html_e( 'Σχεδιασμός εξωφύλλου', 'publishing-quote' ); ?></h3>
			<?php self::checkbox( 'pbq[cv_on]', (bool) $s['cv_on'], __( 'Ο επισκέπτης μπορεί να ζητήσει σχεδιασμό εξωφύλλου', 'publishing-quote' ) ); ?>
			<div class="pbq-grid2">
				<?php
				self::input( 'pbq[cv_price]', self::m( (float) $s['cv_price'] ), __( 'Τιμή', 'publishing-quote' ) );
				self::input( 'pbq[cv_hard]', self::m( (float) $s['cv_hard'] ), __( 'Επιπλέον με σκληρό δέσιμο', 'publishing-quote' ) );
				self::input( 'pbq[cv_vat]', self::vat_val( (float) $s['cv_vat'] ), $vat );
				?>
			</div>
		</div>

		<div class="pbq-svc">
			<h3><?php esc_html_e( 'Εσωτερική εικονογράφηση', 'publishing-quote' ); ?></h3>
			<?php self::checkbox( 'pbq[il_on]', (bool) $s['il_on'], __( 'Ο επισκέπτης μπορεί να ζητήσει εικονογράφηση', 'publishing-quote' ) ); ?>
			<div class="pbq-grid2">
				<?php
				self::input( 'pbq[il_bw]', self::m( (float) $s['il_bw'] ), __( 'Τιμή ανά ασπρόμαυρο σχέδιο', 'publishing-quote' ) );
				self::input( 'pbq[il_color]', self::m( (float) $s['il_color'] ), __( 'Τιμή ανά έγχρωμο σχέδιο', 'publishing-quote' ) );
				self::input( 'pbq[il_max]', (string) (int) $s['il_max'], __( 'Μέγιστα σχέδια', 'publishing-quote' ), 'pbq-num-s', 'number' );
				self::input( 'pbq[il_min]', self::m( (float) $s['il_min'] ), __( 'Ελάχιστη χρέωση', 'publishing-quote' ) );
				self::input( 'pbq[il_vat]', self::vat_val( (float) $s['il_vat'] ), $vat );
				?>
			</div>
			<p class="pbq-label"><?php esc_html_e( 'Κλίμακες (έκπτωση από τόσα σχέδια και πάνω)', 'publishing-quote' ); ?></p>
			<?php self::list_editor( 'il_tiers', 'tiers', $s['il_tiers'] ); ?>
		</div>

		<div class="pbq-svc">
			<h3><?php esc_html_e( 'Εκτύπωση', 'publishing-quote' ); ?></h3>
			<?php self::checkbox( 'pbq[pr_on]', (bool) $s['pr_on'], __( 'Ο επισκέπτης μπορεί να ζητήσει εκτύπωση', 'publishing-quote' ) ); ?>
			<p class="description"><?php esc_html_e( 'Ανά αντίτυπο = σελίδες × τιμή χαρτιού ανά σελίδα × συντελεστής μεγέθους × (συντελεστής χρώματος για έγχρωμες σελίδες) + δέσιμο ανά αντίτυπο + εκτύπωση εξωφύλλου + φινιρίσματα. Σύνολο = ανά αντίτυπο × τιράζ − έκπτωση κλίμακας + στησίματα.', 'publishing-quote' ); ?></p>
			<div class="pbq-grid2">
				<?php
				self::input( 'pbq[pr_setup]', self::m( (float) $s['pr_setup'] ), __( 'Στήσιμο εκτύπωσης', 'publishing-quote' ) );
				self::input( 'pbq[pr_cover]', self::m( (float) $s['pr_cover'] ), __( 'Εκτύπωση εξωφύλλου ανά αντίτυπο', 'publishing-quote' ) );
				self::input( 'pbq[pr_color]', self::m( (float) $s['pr_color'] ), __( 'Συντελεστής για έγχρωμες σελίδες', 'publishing-quote' ), 'pbq-num', 'text', __( 'π.χ. 3 = τριπλάσια τιμή σελίδας', 'publishing-quote' ) );
				self::input( 'pbq[pr_front]', (string) (int) $s['pr_front'], __( 'Σελίδες εκτός κειμένου (τίτλος, ταυτότητα κ.λπ.)', 'publishing-quote' ), 'pbq-num-s', 'number' );
				self::input( 'pbq[pr_run_min]', (string) (int) $s['pr_run_min'], __( 'Ελάχιστο τιράζ', 'publishing-quote' ), 'pbq-num-s', 'number' );
				self::input( 'pbq[pr_run_max]', (string) (int) $s['pr_run_max'], __( 'Μέγιστο τιράζ', 'publishing-quote' ), 'pbq-num-s', 'number' );
				self::input( 'pbq[pr_run_def]', (string) (int) $s['pr_run_def'], __( 'Προεπιλεγμένο τιράζ', 'publishing-quote' ), 'pbq-num-s', 'number' );
				self::input( 'pbq[pr_min]', self::m( (float) $s['pr_min'] ), __( 'Ελάχιστη χρέωση', 'publishing-quote' ) );
				self::input( 'pbq[pr_vat]', self::vat_val( (float) $s['pr_vat'] ), $vat );
				?>
			</div>
			<?php self::checkbox( 'pbq[pr_color_on]', (bool) $s['pr_color_on'], __( 'Ο επισκέπτης διαλέγει ασπρόμαυρες ή έγχρωμες σελίδες', 'publishing-quote' ) ); ?>
			<p class="pbq-label"><?php esc_html_e( 'Κλίμακες τιράζ (έκπτωση από τόσα αντίτυπα και πάνω)', 'publishing-quote' ); ?></p>
			<?php self::list_editor( 'pr_tiers', 'tiers', $s['pr_tiers'] ); ?>
			<p class="description"><?php esc_html_e( 'Μεγέθη, χαρτιά, δεσίματα και φινιρίσματα: καρτέλα Templates.', 'publishing-quote' ); ?></p>
		</div>

		<div class="pbq-svc">
			<h3><?php esc_html_e( 'Σελιδοποίηση (κρυφή)', 'publishing-quote' ); ?> <?php echo self::badge( __( 'ο επισκέπτης βλέπει «Περιλαμβάνεται»', 'publishing-quote' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></h3>
			<div class="pbq-grid2">
				<?php
				self::input( 'pbq[ts_fixed]', self::m( (float) $s['ts_fixed'] ), __( 'Πάγιο', 'publishing-quote' ) );
				self::input( 'pbq[ts_page]', self::m( (float) $s['ts_page'] ), __( 'Ανά σελίδα βιβλίου', 'publishing-quote' ) );
				?>
				<label><span><?php esc_html_e( 'Πού μπαίνει το ποσό', 'publishing-quote' ); ?></span>
					<select name="pbq[ts_mode]">
						<?php
						$modes = array(
							'prop'    => __( 'Αναλογικά σε όλες τις υπηρεσίες με ποσό (προτείνεται)', 'publishing-quote' ),
							'editing' => __( 'Στην Επιμέλεια', 'publishing-quote' ),
							'cover'   => __( 'Στον σχεδιασμό εξωφύλλου', 'publishing-quote' ),
							'illus'   => __( 'Στην Εικονογράφηση', 'publishing-quote' ),
							'print'   => __( 'Στην Εκτύπωση', 'publishing-quote' ),
						);
						foreach ( $modes as $v => $l ) {
							echo '<option value="' . esc_attr( $v ) . '"' . selected( $v, $s['ts_mode'], false ) . '>' . esc_html( $l ) . '</option>';
						}
						?>
					</select>
				</label>
			</div>
			<p class="pbq-label"><?php esc_html_e( 'Χρεώνεται όταν ο επισκέπτης διαλέγει', 'publishing-quote' ); ?></p>
			<input type="hidden" name="pbq[ts_need][]" value="" />
			<?php
			$names = array(
				'editing' => __( 'Επιμέλεια', 'publishing-quote' ),
				'cover'   => __( 'Σχεδιασμό εξωφύλλου', 'publishing-quote' ),
				'illus'   => __( 'Εικονογράφηση', 'publishing-quote' ),
				'print'   => __( 'Εκτύπωση', 'publishing-quote' ),
			);
			foreach ( $names as $k => $l ) {
				printf(
					'<label class="pbq-inline"><input type="checkbox" name="pbq[ts_need][]" value="%1$s"%2$s /> %3$s</label>',
					esc_attr( $k ),
					checked( in_array( $k, $s['ts_need'], true ), true, false ),
					esc_html( $l )
				);
			}
			?>
			<p class="description"><?php esc_html_e( 'Το ποσό μοιράζεται μέσα στις υπηρεσίες, ώστε το σύνολο να είναι πάντα το άθροισμα των γραμμών. Ο υπολογισμός γίνεται μόνο στον server: δεν υπάρχει στον κώδικα της σελίδας.', 'publishing-quote' ); ?></p>
		</div>
		<?php
		self::form_close();
	}

	private static function render_templates(): void {
		$s = PBQ_Settings::get();
		self::form_open( 'templates' );
		$blocks = array(
			'sizes'    => array( __( 'Μεγέθη', 'publishing-quote' ), __( 'Λέξεις ανά σελίδα βιβλίου: για την εκτίμηση των σελίδων. Πολλαπλάσιο: οι σελίδες στρογγυλεύονται προς τα πάνω σε τυπογραφικά (π.χ. 16).', 'publishing-quote' ) ),
			'papers'   => array( __( 'Χαρτιά σώματος', 'publishing-quote' ), __( 'Τιμή εκτύπωσης μίας σελίδας σε ένα αντίτυπο, για το μέγεθος με συντελεστή 1.', 'publishing-quote' ) ),
			'bindings' => array( __( 'Δεσίματα', 'publishing-quote' ), __( 'Ελάχιστες / μέγιστες σελίδες: 0 = χωρίς όριο. Με «Σκληρό» ισχύει και το επιπλέον ποσό του εξωφύλλου.', 'publishing-quote' ) ),
			'finishes' => array( __( 'Φινιρίσματα εξωφύλλου', 'publishing-quote' ), __( 'Βασικά: ο επισκέπτης διαλέγει ένα (π.χ. πλαστικοποίηση). Πρόσθετα: όσα θέλει (π.χ. πτερύγια).', 'publishing-quote' ) ),
		);
		foreach ( $blocks as $list => $b ) {
			echo '<div class="pbq-svc"><h3>' . esc_html( $b[0] ) . '</h3><p class="description">' . esc_html( $b[1] ) . '</p>';
			self::list_editor( $list, $list, $s[ $list ] );
			echo '</div>';
		}
		self::form_close();
	}

	private static function render_extras(): void {
		$s = PBQ_Settings::get();
		self::form_open( 'extras' );
		echo '<p class="description">' . esc_html__( 'Πάγιο: ναι / όχι. Ανά τεμάχιο: ο επισκέπτης γράφει ποσότητα. Ανά αντίτυπο: η ποσότητα ακολουθεί το τιράζ (μόνο με εκτύπωση). Ανά λέξη: π.χ. μετάφραση. «Κατόπιν συζήτησης»: η γραμμή δεν έχει ποσό και δεν μπαίνει στο σύνολο. Κενός ΦΠΑ = ο γενικός.', 'publishing-quote' ) . '</p>';
		self::list_editor( 'extras', 'extras', $s['extras'] );
		self::form_close();
	}

	private static function render_texts(): void {
		$s = PBQ_Settings::get();
		self::form_open( 'texts' );
		echo '<p class="description">' . esc_html__( 'Κάθε κείμενο που βλέπει ο επισκέπτης. Κενό αγγλικό = το ελληνικό. Placeholders: {rate}, {date}, {min}, {max}, {name}, {site}.', 'publishing-quote' ) . '</p><div class="pbq-zone">';
		foreach ( PBQ_Settings::texts() as $k => $t ) {
			echo '<span class="pbq-label"><code>' . esc_html( $k ) . '</code></span><div class="pbq-pair">';
			foreach ( array( 'el', 'en' ) as $l ) {
				$long = $t[2] > 200;
				echo '<label><span class="pbq-lang">' . esc_html( 'el' === $l ? __( 'Ελληνικά', 'publishing-quote' ) : __( 'English (κενό = το ελληνικό)', 'publishing-quote' ) ) . '</span>';
				if ( $long ) {
					echo '<textarea class="pbq-name" rows="2" name="pbq[t_' . esc_attr( $k . '_' . $l ) . ']" maxlength="' . (int) $t[2] . '">' . esc_textarea( $s[ 't_' . $k . '_' . $l ] ) . '</textarea>';
				} else {
					echo '<input type="text" class="pbq-name" name="pbq[t_' . esc_attr( $k . '_' . $l ) . ']" value="' . esc_attr( $s[ 't_' . $k . '_' . $l ] ) . '" maxlength="' . (int) $t[2] . '" />';
				}
				echo '</label>';
			}
			echo '</div>';
		}
		echo '</div>';
		self::form_close();
	}

	/** Services that are on but have no price: a hint on the test bench. */
	private static function zero_prices(): array {
		$s   = PBQ_Settings::get();
		$out = array();
		if ( $s['ed_on'] ) {
			foreach ( PBQ_Settings::rows_on( 'levels' ) as $r ) {
				if ( $r['rate'] <= 0 ) {
					$out[] = __( 'Επιμέλεια', 'publishing-quote' ) . ': ' . $r['el'];
				}
			}
		}
		if ( $s['cv_on'] && $s['cv_price'] <= 0 ) {
			$out[] = __( 'Σχεδιασμός εξωφύλλου', 'publishing-quote' );
		}
		if ( $s['il_on'] && $s['il_bw'] <= 0 && $s['il_color'] <= 0 ) {
			$out[] = __( 'Εικονογράφηση', 'publishing-quote' );
		}
		if ( $s['pr_on'] ) {
			foreach ( PBQ_Settings::rows_on( 'papers' ) as $r ) {
				if ( $r['page'] <= 0 ) {
					$out[] = __( 'Χαρτί', 'publishing-quote' ) . ': ' . $r['el'];
				}
			}
		}
		foreach ( PBQ_Settings::rows_on( 'extras' ) as $r ) {
			if ( $r['price'] <= 0 && empty( $r['ask'] ) ) {
				$out[] = __( 'Έξτρα', 'publishing-quote' ) . ': ' . $r['el'];
			}
		}
		return $out;
	}

	private static function render_test(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- read-only test bench; whitelisted in PBQ_Calc::input().
		$raw  = isset( $_GET['q'] ) && is_array( $_GET['q'] ) ? wp_unslash( $_GET['q'] ) : null;
		// phpcs:enable
		$lang = 'en' === self::q( 'lang' ) ? 'en' : ( 'el' === self::q( 'lang' ) ? 'el' : PBQ_Settings::visitor_lang() );
		$in   = PBQ_Calc::input( null !== $raw ? $raw : array( 'edit' => '1', 'print' => '1', 'pages' => '200' ) );
		$q    = PBQ_Calc::quote( $in, $lang );
		$view = PBQ_Calc::public_view( $q );

		$zero = self::zero_prices();
		if ( $zero ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Χωρίς τιμή ακόμα (ο επισκέπτης θα δει «Περιλαμβάνεται»):', 'publishing-quote' ) . ' ' . esc_html( implode( ' · ', $zero ) ) . '</p></div>';
		}
		?>
		<p class="description"><?php esc_html_e( 'Ο ίδιος υπολογισμός με το site, με την πλήρη ανάλυση που ο επισκέπτης δεν βλέπει ποτέ. Τίποτα δεν αποθηκεύεται.', 'publishing-quote' ); ?></p>
		<div class="pbq-bench">
			<div class="pbq" data-lang="<?php echo esc_attr( $lang ); ?>">
				<form method="get" class="pbq-form">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
					<input type="hidden" name="tab" value="test" />
					<p>
						<label class="pbq-inline"><input type="radio" name="lang" value="el"<?php checked( 'el', $lang ); ?> /> <?php esc_html_e( 'Ελληνικά', 'publishing-quote' ); ?></label>
						<label class="pbq-inline"><input type="radio" name="lang" value="en"<?php checked( 'en', $lang ); ?> /> English</label>
					</p>
					<?php echo PBQ_Front::fields( $in, $lang, 'pbq-bench', $view['fields'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in PBQ_Front::fields(). ?>
					<p class="pbq-actions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Υπολογισμός', 'publishing-quote' ); ?></button></p>
				</form>
			</div>
			<div>
				<h2 class="pbq-h2"><?php esc_html_e( 'Τι βλέπει ο επισκέπτης', 'publishing-quote' ); ?></h2>
				<div class="pbq pbq-zone"><div class="pbq-result"><?php echo PBQ_Front::result( $view ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in PBQ_Front::result(). ?></div></div>
				<h2 class="pbq-h2"><?php esc_html_e( 'Ανάλυση (μόνο για σένα)', 'publishing-quote' ); ?></h2>
				<table class="widefat pbq-table">
					<tbody>
					<?php foreach ( PBQ_Requests::breakdown_rows( array( 'quote' => $q ) ) as $r ) : ?>
						<tr><th><?php echo esc_html( $r[0] ); ?></th><td><?php echo esc_html( $r[1] ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	private static function render_requests(): void {
		$view = (int) self::q( 'view' );
		if ( $view > 0 ) {
			self::render_request( $view );
			return;
		}
		$state  = in_array( self::q( 'state' ), PBQ_Requests::STATES, true ) ? self::q( 'state' ) : '';
		$search = self::q( 's' );
		$page   = max( 1, (int) self::q( 'paged' ) );
		$ids    = PBQ_Requests::ids(
			array(
				'state'  => $state,
				'search' => $search,
				'limit'  => self::PER_PAGE + 1,
				'page'   => $page,
			)
		);
		$more   = count( $ids ) > self::PER_PAGE;
		$ids    = array_slice( $ids, 0, self::PER_PAGE );
		$counts = PBQ_Requests::counts();
		$base   = admin_url( 'admin.php?page=' . self::SLUG . '&tab=requests' );
		$fmt    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="pbq-filters">
			<a href="<?php echo esc_url( $base ); ?>" class="<?php echo '' === $state ? 'is-current' : ''; ?>"><?php esc_html_e( 'Όλα', 'publishing-quote' ); ?> (<?php echo (int) array_sum( $counts ); ?>)</a>
			<?php foreach ( PBQ_Requests::STATES as $st ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'state', $st, $base ) ); ?>" class="<?php echo $st === $state ? 'is-current' : ''; ?>"><?php echo esc_html( PBQ_Requests::state_label( $st ) ); ?> (<?php echo (int) $counts[ $st ]; ?>)</a>
			<?php endforeach; ?>
			<form method="get" class="pbq-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<input type="hidden" name="tab" value="requests" />
				<input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Όνομα', 'publishing-quote' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Αναζήτηση', 'publishing-quote' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=requests&pbq_csv=1' ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή CSV', 'publishing-quote' ); ?></a>
		</div>
		<table class="widefat pbq-table">
			<thead><tr>
				<th><?php esc_html_e( 'Ημερομηνία', 'publishing-quote' ); ?></th>
				<th><?php esc_html_e( 'Όνομα', 'publishing-quote' ); ?></th>
				<th>Email</th>
				<th><?php esc_html_e( 'Σύνολο', 'publishing-quote' ); ?></th>
				<th><?php esc_html_e( 'Κατάσταση', 'publishing-quote' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $ids ) : ?>
				<tr><td colspan="5" class="pbq-empty"><?php esc_html_e( 'Κανένα αίτημα ακόμα.', 'publishing-quote' ); ?></td></tr>
			<?php endif; ?>
			<?php
			foreach ( $ids as $id ) {
				$d = PBQ_Requests::data( $id );
				if ( ! $d ) {
					continue;
				}
				$st  = PBQ_Requests::state( $id );
				$url = add_query_arg( 'view', $id, $base );
				?>
				<tr>
					<td><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( get_the_date( $fmt, $id ) ); ?></a></td>
					<td><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $d['contact']['name'] ); ?></a></td>
					<td><?php echo esc_html( $d['contact']['email'] ); ?></td>
					<td><?php echo esc_html( $d['view']['total']['amount'] ); ?></td>
					<td><?php echo self::badge( PBQ_Requests::state_label( $st ), 'new' === $st ? 'warn' : ( 'closed' === $st ? 'muted' : 'ok' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></td>
				</tr>
				<?php
			}
			?>
			</tbody>
		</table>
		<p>
			<?php if ( $page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page - 1 ) ); ?>">← <?php esc_html_e( 'Νεότερα', 'publishing-quote' ); ?></a>
			<?php endif; ?>
			<?php if ( $more ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page + 1 ) ); ?>"><?php esc_html_e( 'Παλαιότερα', 'publishing-quote' ); ?> →</a>
			<?php endif; ?>
		</p>
		<p class="description"><?php esc_html_e( 'Τα αιτήματα έρχονται και με email. Η σύνδεση με το orOS (πρόχειρη προσφορά που στέλνεις μετά τον έλεγχο) έρχεται με το orOS Connect.', 'publishing-quote' ); ?></p>
		<?php
	}

	private static function render_request( int $id ): void {
		$d    = PBQ_Requests::data( $id );
		$back = admin_url( 'admin.php?page=' . self::SLUG . '&tab=requests' );
		echo '<p><a href="' . esc_url( $back ) . '">← ' . esc_html__( 'Όλα τα αιτήματα', 'publishing-quote' ) . '</a></p>';
		if ( ! $d ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Το αίτημα δεν βρέθηκε.', 'publishing-quote' ) . '</p></div>';
			return;
		}
		$c     = $d['contact'];
		$st    = PBQ_Requests::state( $id );
		$notes = get_post_meta( $id, '_pbq_notes', true );
		$fmt   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<h2 class="pbq-h2"><?php echo esc_html( $c['name'] ); ?> · <?php echo esc_html( get_the_date( $fmt, $id ) ); ?> <?php echo self::badge( PBQ_Requests::state_label( $st ), 'new' === $st ? 'warn' : 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></h2>
		<div class="pbq-bench">
			<div>
				<table class="widefat pbq-table pbq-req-meta"><tbody>
					<tr><th><?php esc_html_e( 'Όνομα', 'publishing-quote' ); ?></th><td><?php echo esc_html( $c['name'] ); ?></td></tr>
					<tr><th>Email</th><td><a href="<?php echo esc_url( 'mailto:' . $c['email'] ); ?>"><?php echo esc_html( $c['email'] ); ?></a></td></tr>
					<tr><th><?php esc_html_e( 'Τηλέφωνο', 'publishing-quote' ); ?></th><td><?php echo esc_html( $c['phone'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Βιβλίο', 'publishing-quote' ); ?></th><td><?php echo esc_html( $c['book'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Μήνυμα', 'publishing-quote' ); ?></th><td><pre class="pbq-pre"><?php echo esc_html( $c['msg'] ); ?></pre></td></tr>
					<tr><th><?php esc_html_e( 'Γλώσσα', 'publishing-quote' ); ?></th><td><?php echo esc_html( 'en' === $d['lang'] ? 'English' : __( 'Ελληνικά', 'publishing-quote' ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Συναίνεση', 'publishing-quote' ); ?></th><td><?php echo esc_html( wp_date( $fmt, (int) $d['consent']['time'] ) . ' · ' . $d['consent']['text'] ); ?></td></tr>
				</tbody></table>

				<h2 class="pbq-h2"><?php esc_html_e( 'Κατάσταση', 'publishing-quote' ); ?></h2>
				<form method="post" class="pbq-zone">
					<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
					<input type="hidden" name="pbq_action" value="state" />
					<input type="hidden" name="pbq_id" value="<?php echo (int) $id; ?>" />
					<?php
					foreach ( PBQ_Requests::STATES as $s ) {
						self::radio( 'pbq_state', $s, $st, PBQ_Requests::state_label( $s ) );
					}
					?>
					<input type="text" name="pbq_note" class="pbq-wide" maxlength="500" placeholder="<?php esc_attr_e( 'Σημείωση (προαιρετική)', 'publishing-quote' ); ?>" />
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση', 'publishing-quote' ); ?></button></p>
				</form>
				<?php if ( is_array( $notes ) && $notes ) : ?>
					<ul>
						<?php foreach ( array_reverse( $notes ) as $n ) : ?>
							<li><span class="pbq-muted"><?php echo esc_html( wp_date( $fmt, (int) $n['t'] ) ); ?></span> · <?php echo esc_html( $n['text'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Οριστική διαγραφή του αιτήματος;', 'publishing-quote' ) ); ?>');">
					<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
					<input type="hidden" name="pbq_action" value="delete" />
					<input type="hidden" name="pbq_id" value="<?php echo (int) $id; ?>" />
					<button type="submit" class="button"><?php esc_html_e( 'Διαγραφή αιτήματος', 'publishing-quote' ); ?></button>
				</form>
			</div>
			<div>
				<h2 class="pbq-h2"><?php esc_html_e( 'Τι είδε ο επισκέπτης', 'publishing-quote' ); ?></h2>
				<div class="pbq pbq-zone"><div class="pbq-result"><?php echo PBQ_Front::result( $d['view'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in PBQ_Front::result(). ?></div></div>
				<h2 class="pbq-h2"><?php esc_html_e( 'Ανάλυση (μόνο για σένα)', 'publishing-quote' ); ?></h2>
				<table class="widefat pbq-table"><tbody>
					<?php foreach ( PBQ_Requests::breakdown_rows( $d ) as $r ) : ?>
						<tr><th><?php echo esc_html( $r[0] ); ?></th><td><?php echo esc_html( $r[1] ); ?></td></tr>
					<?php endforeach; ?>
				</tbody></table>
				<p class="description"><?php esc_html_e( 'Με τους συντελεστές της στιγμής που στάλθηκε το αίτημα.', 'publishing-quote' ); ?></p>
			</div>
		</div>
		<?php
	}

	private static function render_tools(): void {
		?>
		<h2 class="pbq-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'publishing-quote' ); ?></h2>
		<div class="pbq-zone">
			<form method="post" enctype="multipart/form-data" class="pbq-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'pbq_backup_nonce' ); ?>
				<input type="file" name="pbq_import_file" accept=".json,application/json" />
				<button type="submit" name="pbq_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'publishing-quote' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=tools&pbq_export=1' ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'publishing-quote' ); ?></a>
			<p class="description pbq-mt-8"><?php esc_html_e( 'Τιμές, templates, έξτρα και κείμενα. Τα αιτήματα δεν μπαίνουν στο backup (για αυτά: Αιτήματα → Εξαγωγή CSV).', 'publishing-quote' ); ?></p>
		</div>

		<h2 class="pbq-h2"><?php esc_html_e( 'Για developers', 'publishing-quote' ); ?></h2>
		<div class="pbq-zone">
			<table class="widefat striped pbq-table">
				<tbody>
					<tr><td><code>[publishing_quote title="yes|no"]</code></td><td><?php esc_html_e( 'Ο υπολογιστής.', 'publishing-quote' ); ?></td></tr>
					<tr><td><code>POST /wp-json/pbq/v1/quote</code></td><td><?php esc_html_e( '{ q: επιλογές, lang } → ποσά ανά υπηρεσία και σύνολο, χωρίς ανάλυση.', 'publishing-quote' ); ?></td></tr>
					<tr><td><code>pbq_get_request( $id )</code>, <code>pbq_get_requests( $args )</code>, <code>pbq_set_request_state( $id, $state, $note )</code></td><td><?php esc_html_e( 'Τα αιτήματα για άλλα plugins (π.χ. orOS Connect).', 'publishing-quote' ); ?></td></tr>
					<tr><td><code>pbq_request_created</code>, <code>pbq_request_state</code> <?php esc_html_e( '(actions)', 'publishing-quote' ); ?></td><td><?php esc_html_e( 'Μετά από νέο αίτημα: ( $id, $request ). Μετά από αλλαγή κατάστασης: ( $id, $state, $old ).', 'publishing-quote' ); ?></td></tr>
					<tr><td><code>pbq_text</code> <?php esc_html_e( '(φίλτρο)', 'publishing-quote' ); ?></td><td><?php esc_html_e( 'Αλλάζει ένα κείμενο του επισκέπτη: ( $text, $key, $lang ).', 'publishing-quote' ); ?></td></tr>
					<tr><td><code>PBQ_DISABLE</code></td><td><?php esc_html_e( 'Στο wp-config.php: σταματά τον υπολογιστή, τα REST routes και τη φόρμα.', 'publishing-quote' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* =====================================================================
	 * POST routes (admin_init, PRG)
	 * =================================================================== */

	/** Settings keys of each tab; bools are false when not posted. */
	private static function tab_keys( string $tab ): array {
		switch ( $tab ) {
			case 'general':
				return array(
					'keys'  => array( 'mode', 'lang', 'vat', 'show', 'step', 'round', 'valid', 'email', 'keep', 'cur_sym', 'cur_pos', 'cur_dec', 'cur_thou' ),
					'bools' => array( 'ack', 'wipe', 'privacy' ),
					'lists' => array(),
				);
			case 'services':
				return array(
					'keys'  => array( 'ed_unit', 'ed_wpp', 'ed_max', 'ed_min', 'ed_vat', 'cv_price', 'cv_hard', 'cv_vat', 'il_bw', 'il_color', 'il_max', 'il_min', 'il_vat', 'pr_setup', 'pr_cover', 'pr_color', 'pr_front', 'pr_run_min', 'pr_run_max', 'pr_run_def', 'pr_min', 'pr_vat', 'ts_fixed', 'ts_page', 'ts_mode' ),
					'bools' => array( 'ed_on', 'cv_on', 'il_on', 'pr_on', 'pr_color_on' ),
					'lists' => array( 'levels', 'il_tiers', 'pr_tiers', 'ts_need' ),
				);
			case 'templates':
				return array(
					'keys'  => array(),
					'bools' => array(),
					'lists' => array( 'sizes', 'papers', 'bindings', 'finishes' ),
				);
			case 'extras':
				return array(
					'keys'  => array(),
					'bools' => array(),
					'lists' => array( 'extras' ),
				);
			case 'texts':
				$keys = array();
				foreach ( array_keys( PBQ_Settings::texts() ) as $k ) {
					$keys[] = 't_' . $k . '_el';
					$keys[] = 't_' . $k . '_en';
				}
				return array(
					'keys'  => $keys,
					'bools' => array(),
					'lists' => array(),
				);
		}
		return array(
			'keys'  => array(),
			'bools' => array(),
			'lists' => array(),
		);
	}

	public static function route(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}

		if ( isset( $_POST['pbq_import'] ) ) {
			if ( ! isset( $_POST['pbq_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['pbq_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( Publishing_Quote::cap() ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'publishing-quote' ) );
			}
			self::redirect( 'tools', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['pbq_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Publishing_Quote::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'publishing-quote' ) );
		}

		switch ( sanitize_key( wp_unslash( $_POST['pbq_action'] ) ) ) {
			case 'save':
				$tab = isset( $_POST['pbq_tab'] ) ? sanitize_key( wp_unslash( $_POST['pbq_tab'] ) ) : '';
				if ( ! in_array( $tab, array( 'general', 'services', 'templates', 'extras', 'texts' ), true ) ) {
					self::redirect( 'general', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'publishing-quote' ) ) ) );
				}
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- every value goes through PBQ_Settings::sanitize().
				$raw  = isset( $_POST['pbq'] ) && is_array( $_POST['pbq'] ) ? wp_unslash( $_POST['pbq'] ) : array();
				$spec = self::tab_keys( $tab );
				$s    = PBQ_Settings::get();
				foreach ( $spec['keys'] as $k ) {
					if ( isset( $raw[ $k ] ) ) {
						$s[ $k ] = $raw[ $k ];
					}
				}
				foreach ( $spec['bools'] as $k ) {
					$s[ $k ] = ! empty( $raw[ $k ] );
				}
				$kept = false;
				foreach ( $spec['lists'] as $k ) {
					$list = isset( $raw[ $k ] ) && is_array( $raw[ $k ] ) ? $raw[ $k ] : array();
					unset( $list['_'] );
					// The calculator needs at least one row in these lists: with
					// every row removed, the rows already saved stay.
					if ( in_array( $k, array( 'levels', 'sizes', 'papers', 'bindings' ), true ) && ! self::has_row( $list ) ) {
						$kept = true;
						continue;
					}
					$s[ $k ] = 'ts_need' === $k ? array_values( array_filter( $list, 'is_string' ) ) : $list;
				}
				PBQ_Settings::save( $s );
				$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'publishing-quote' ) ) );
				if ( $kept ) {
					$msgs[] = self::msg( 'warning', __( 'Τα είδη επιμέλειας, τα μεγέθη, τα χαρτιά και τα δεσίματα χρειάζονται τουλάχιστον μία γραμμή: όπου τις αφαίρεσες όλες, έμειναν οι προηγούμενες.', 'publishing-quote' ) );
				}
				self::redirect( $tab, $msgs );
				break;
			case 'state':
				$id    = isset( $_POST['pbq_id'] ) ? absint( $_POST['pbq_id'] ) : 0;
				$state = isset( $_POST['pbq_state'] ) ? sanitize_key( wp_unslash( $_POST['pbq_state'] ) ) : '';
				$note  = isset( $_POST['pbq_note'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['pbq_note'] ) ) : '';
				$ok    = PBQ_Requests::set_state( $id, $state, $note );
				self::redirect( 'requests&view=' . $id, array( $ok ? self::msg( 'success', __( 'Η κατάσταση αποθηκεύτηκε.', 'publishing-quote' ) ) : self::msg( 'error', __( 'Το αίτημα δεν βρέθηκε.', 'publishing-quote' ) ) ) );
				break;
			case 'delete':
				$id = isset( $_POST['pbq_id'] ) ? absint( $_POST['pbq_id'] ) : 0;
				$ok = PBQ_Requests::delete( $id );
				self::redirect( 'requests', array( $ok ? self::msg( 'success', __( 'Το αίτημα διαγράφηκε.', 'publishing-quote' ) ) : self::msg( 'error', __( 'Το αίτημα δεν βρέθηκε.', 'publishing-quote' ) ) ) );
				break;
			default:
				self::redirect( 'general', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'publishing-quote' ) ) ) );
		}
	}

	/** True when a posted list has a row with a Greek name. */
	private static function has_row( array $list ): bool {
		foreach ( $list as $r ) {
			if ( is_array( $r ) && isset( $r['el'] ) && is_string( $r['el'] ) && '' !== trim( $r['el'] ) ) {
				return true;
			}
		}
		return false;
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		set_transient( 'pbq_aui_msg_' . get_current_user_id(), $msgs, 60 );
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
		$json = '' !== self::q( 'pbq_export' );
		$csv  = '' !== self::q( 'pbq_csv' );
		if ( ! $json && ! $csv ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( Publishing_Quote::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'publishing-quote' ) );
		}
		nocache_headers();
		if ( $json ) {
			$state = array(
				'plugin'  => 'publishing-quote',
				'version' => PBQ_VERSION,
				'options' => array(
					PBQ_Settings::OPT => PBQ_Settings::get(),
				),
			);
			header( 'Content-Type: application/json; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="publishing-quote-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
			echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
			exit;
		}
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="publishing-quote-requests-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CSV download stream.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM for Excel.
		fputcsv( $out, array( 'id', 'date', 'state', 'name', 'email', 'phone', 'book', 'total', 'lang' ), ',', '"', '\\' );
		$page = 1;
		do {
			$ids = PBQ_Requests::ids(
				array(
					'limit' => 200,
					'page'  => $page,
				)
			);
			foreach ( $ids as $id ) {
				$d = PBQ_Requests::data( $id );
				if ( ! $d ) {
					continue;
				}
				$row = array( $id, get_post_time( 'Y-m-d H:i', false, $id ), PBQ_Requests::state( $id ), $d['contact']['name'], $d['contact']['email'], $d['contact']['phone'], $d['contact']['book'], number_format( $d['quote']['total'] / 100, 2, '.', '' ), $d['lang'] );
				// Spreadsheet formula injection: a leading = + - @ becomes text.
				foreach ( $row as $i => $v ) {
					$v         = (string) $v;
					$row[ $i ] = '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $v : $v;
				}
				fputcsv( $out, $row, ',', '"', '\\' );
			}
			++$page;
		} while ( count( $ids ) === 200 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CSV download stream.
		exit;
	}

	/** Strict import through PBQ_Settings::sanitize(); an invalid file changes nothing. */
	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['pbq_import_file']['error'] ) ? (int) $_FILES['pbq_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['pbq_import_file']['size'] ) ? (int) $_FILES['pbq_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'publishing-quote' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['pbq_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['pbq_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'publishing-quote' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['pbq_import_file']['tmp_name'] ), true );
		// phpcs:enable
		if ( ! is_array( $state ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'publishing-quote' ) ) );
		}
		$blob = isset( $state['options'][ PBQ_Settings::OPT ] ) ? $state['options'][ PBQ_Settings::OPT ] : null;
		if ( is_string( $blob ) ) {
			$blob = json_decode( $blob, true );
		}
		if ( ! is_array( $blob ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Publishing Quote).', 'publishing-quote' ) ) );
		}
		PBQ_Settings::save( $blob );
		return array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'publishing-quote' ) ) );
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'pbq_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'pbq_aui_msg_' . get_current_user_id() );
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
		<p class="pbq-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'publishing-quote' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="pbq-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="pbq-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'publishing-quote' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="pbq-footer-cta">
			<a class="pbq-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'publishing-quote' ); ?>
			</a>
			<a class="pbq-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'publishing-quote' ); ?>
			</a>
		</p>
		<?php
	}
}

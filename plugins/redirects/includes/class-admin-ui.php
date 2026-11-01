<?php
/**
 * RDR_Admin_UI — menu, tabs, forms (Bible §3–§8, §11).
 *
 * Submenu «Redirects» of the shared "Noxpress" menu (admin_menu priority
 * 320), slug rdr-settings, capability manage_options. Tabs:
 *  - rules     notices (mode, other redirect plugins, trashed posts,
 *              staged rules due for 301), add / edit, «test an address»,
 *              the list with bulk actions
 *  - 404       the 404 report with suggestions and one-click rules
 *  - import    other plugins and CSV (scan → preview → apply), export,
 *              JSON backup
 *  - settings  mode, log, scanner list, limits, uninstall, developers
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient rdr_aui_msg_{uid}). Exports: privileged GET.
 */

defined( 'ABSPATH' ) || exit;

final class RDR_Admin_UI {

	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'rdr-settings';
	const NONCE       = 'rdr_admin';
	const NONCE_FIELD = 'rdr_nonce';
	const NONCE_EXP   = 'rdr_export';
	const IGNORED     = 'rdr_ignored';
	const MAX_IGNORED = 500;
	const MAX_JSON    = 4194304;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 320 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RDR_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label. */
	private static function tabs(): array {
		return array(
			'rules'    => __( 'Κανόνες', 'redirects' ),
			'404'      => __( '404', 'redirects' ),
			'import'   => __( 'Εισαγωγή / Εξαγωγή', 'redirects' ),
			'settings' => __( 'Ρυθμίσεις', 'redirects' ),
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
			__( 'Redirects', 'redirects' ),
			__( 'Redirects', 'redirects' ),
			Redirects::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'redirects' ) . '</a>'
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

	/** A GET value that may hold an encoded URL (sanitize_text_field drops %xx). */
	private static function q_raw( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill, escaped on output.
		$v = isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? (string) wp_unslash( $_GET[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F<>"]/', '', wp_strip_all_tags( $v ) ) );
	}

	private static function current_tab(): string {
		$tab = sanitize_key( self::q( 'tab' ) );
		return isset( self::tabs()[ $tab ] ) ? $tab : 'rules';
	}

	private static function url( string $tab, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'rdr-admin', RDR_URL . 'assets/admin.css', array(), RDR_VERSION );
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Redirects::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'redirects' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap rdr-wrap">
			<h1><?php esc_html_e( 'Redirects', 'redirects' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Ανακατευθύνσεις για παλιές διευθύνσεις, μόνο εκεί που το site απαντά 404: μια σελίδα που υπάρχει δεν επηρεάζεται ποτέ. Τα 404 γράφονται σε ιδιωτικό αρχείο, χωρίς IP και χωρίς τα bots, με πρόταση στόχου για καθένα.', 'redirects' ); ?></p>

			<?php
			self::print_notices( self::take_msgs() );
			if ( Redirects::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το RDR_DISABLE είναι ενεργό στο wp-config.php: κανένας κανόνας δεν εφαρμόζεται και τίποτα δεν γράφεται στο log.', 'redirects' ) . '</p></div>';
			}
			if ( ! RDR_Settings::live() ) {
				echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Λειτουργία δοκιμής: οι κανόνες ισχύουν μόνο για διαχειριστές (εσένα). Οι επισκέπτες βλέπουν ακόμα το 404. Το log γράφει κανονικά. Όταν είσαι έτοιμος, διάλεξε «Ενεργό» στις Ρυθμίσεις.', 'redirects' ) . '</p></div>';
			}
			$st = RDR_Log::state();
			if ( isset( $st['web'] ) && 'exposed' === $st['web'] ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Ο φάκελος του log κατεβαίνει από τον browser (ο server αγνοεί το .htaccess, π.χ. nginx). Το log δεν έχει IP ή email, αλλά πρόσθεσε έναν κανόνα deny για τον φάκελο στον server ή κλείσε το log στις Ρυθμίσεις.', 'redirects' ) . '</p></div>';
			}
			?>

			<nav class="nav-tab-wrapper rdr-tabs">
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
				case '404':
					self::render_404();
					break;
				case 'import':
					self::render_import();
					break;
				case 'settings':
					self::render_settings();
					break;
				default:
					self::render_rules();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	private static function badge( string $text, string $kind ): string {
		return '<span class="rdr-badge rdr-badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function fmt(): string {
		return get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	}

	private static function checkbox( string $name, bool $on, string $label ): void {
		printf(
			'<label class="rdr-check"><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label )
		);
	}

	private static function radio( string $name, string $value, string $current, string $label ): void {
		printf(
			'<label class="rdr-check"><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $value, $current, false ),
			esc_html( $label )
		);
	}

	/** Hidden fields of a POST form. */
	private static function form_head( string $action ): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		echo '<input type="hidden" name="rdr_action" value="' . esc_attr( $action ) . '" />';
	}

	/** A one-button POST form. */
	private static function button_form( string $action, array $fields, string $label, string $class = 'button' ): void {
		echo '<form method="post" class="rdr-inline-form">';
		self::form_head( $action );
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '" />';
		}
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/** Display of a normalised path. */
	private static function show_path( string $p ): string {
		return '/' . $p;
	}

	private static function code_label( int $code ): string {
		$labels = array(
			301 => __( '301 μόνιμη', 'redirects' ),
			302 => __( '302 προσωρινή', 'redirects' ),
			307 => __( '307 προσωρινή', 'redirects' ),
			410 => __( '410 δεν υπάρχει πια', 'redirects' ),
		);
		return isset( $labels[ $code ] ) ? $labels[ $code ] : (string) $code;
	}

	private static function match_label( string $m ): string {
		$labels = array(
			'exact'  => __( 'ακριβώς', 'redirects' ),
			'prefix' => __( 'αρχίζει με', 'redirects' ),
			'regex'  => __( 'regex', 'redirects' ),
		);
		return isset( $labels[ $m ] ) ? $labels[ $m ] : $m;
	}

	/* ---------------------------------------------------------------------
	 * Rules
	 * ------------------------------------------------------------------- */

	private static function render_rules(): void {
		$s      = RDR_Settings::get();
		$edit   = (int) self::q( 'edit' );
		$rule   = $edit > 0 ? RDR_Rules::get( $edit ) : null;
		$counts = RDR_Rules::counts();

		// Other redirect systems.
		$conf = RDR_Import::conflicts();
		if ( $conf ) {
			echo '<div class="notice notice-warning inline"><p>';
			/* translators: %s: list of plugins / modules */
			echo esc_html( sprintf( __( 'Είναι ενεργό και άλλο σύστημα ανακατευθύνσεων: %s. Δουλεύουν μαζί, αλλά ο κανόνας του άλλου plugin τρέχει πρώτος, και δύο λίστες μπερδεύουν. Πέρασε τους κανόνες του εδώ και κλείσε το δικό του module.', 'redirects' ), implode( ', ', $conf ) ) );
			echo ' <a href="' . esc_url( self::url( 'import' ) ) . '">' . esc_html__( 'Εισαγωγή', 'redirects' ) . '</a></p></div>';
		}

		// Trashed posts and changed term addresses.
		$pending = RDR_Watch::pending();
		if ( $pending ) {
			?>
			<h2 class="rdr-h2"><?php esc_html_e( 'Διευθύνσεις που μόλις σταμάτησαν', 'redirects' ); ?></h2>
			<div class="rdr-zone">
				<table class="widefat striped rdr-table">
					<tbody>
					<?php foreach ( array_slice( $pending, 0, 15 ) as $p ) : ?>
						<tr>
							<td>
								<code><?php echo esc_html( self::show_path( (string) $p['path'] ) ); ?></code>
								<span class="rdr-muted">
									<?php
									echo esc_html(
										'term' === $p['kind']
											/* translators: %s: term name */
											? sprintf( __( 'Ο όρος «%s» άλλαξε διεύθυνση.', 'redirects' ), (string) $p['title'] )
											/* translators: %s: post title */
											: sprintf( __( 'Το «%s» πήγε στον κάδο ή σβήστηκε.', 'redirects' ), (string) $p['title'] )
									);
									?>
								</span>
							</td>
							<td class="rdr-actions">
								<?php
								if ( '' !== (string) $p['to'] ) {
									self::button_form(
										'pending',
										array(
											'key'  => $p['key'],
											'what' => '301',
										),
										'term' === $p['kind'] ? __( '301 στη νέα διεύθυνση', 'redirects' ) : __( '301 στην κατηγορία του', 'redirects' ),
										'button button-primary'
									);
								}
								if ( 'post' === $p['kind'] ) {
									self::button_form(
										'pending',
										array(
											'key'  => $p['key'],
											'what' => '410',
										),
										__( '410', 'redirects' )
									);
									echo '<a class="button" href="' . esc_url( self::url( 'rules', array( 'from' => '/' . $p['path'] ) ) ) . '#rdr-form">' . esc_html__( 'Άλλος στόχος', 'redirects' ) . '</a>';
								}
								self::button_form(
									'pending',
									array(
										'key'  => $p['key'],
										'what' => 'no',
									),
									__( 'Όχι', 'redirects' )
								);
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php
		}

		// Staged rules due for 301.
		$due = RDR_Rules::due_for_301();
		if ( $due ) {
			echo '<div class="notice notice-info inline"><p>';
			/* translators: 1: number of rules, 2: days */
			echo esc_html( sprintf( _n( '%1$d κανόνας απαντά 302 πάνω από %2$d ημέρες χωρίς πρόβλημα. Κάν\' τον 301 (μόνιμη), ώστε οι μηχανές αναζήτησης να περάσουν τη θέση του στη νέα διεύθυνση.', '%1$d κανόνες απαντούν 302 πάνω από %2$d ημέρες χωρίς πρόβλημα. Κάν\' τους 301 (μόνιμη), ώστε οι μηχανές αναζήτησης να περάσουν τη θέση τους στη νέα διεύθυνση.', count( $due ), 'redirects' ), count( $due ), RDR_Settings::STAGE_DAYS ) );
			echo '</p><p>';
			self::button_form( 'unstage_due', array(), __( 'Κάνε τους 301', 'redirects' ), 'button button-primary' );
			echo ' <a href="' . esc_url( self::url( 'rules', array( 'only' => 'staged' ) ) ) . '">' . esc_html__( 'Δες τους', 'redirects' ) . '</a></p></div>';
		}

		// Add / edit.
		$from  = $rule ? ( 'regex' === $rule['match'] ? $rule['src'] : '/' . $rule['src'] ) : self::q_raw( 'from' );
		$to    = $rule ? ( 'none' === $rule['target_type'] ? '' : RDR_Rules::describe_target( $rule )[0] ) : self::q_raw( 'to' );
		$match = $rule ? $rule['match'] : 'exact';
		$code  = $rule ? $rule['code'] : 301;
		$stg   = $rule ? $rule['staged'] : (bool) $s['staged'];
		?>
		<h2 class="rdr-h2" id="rdr-form">
			<?php
			echo $rule
				/* translators: %d: rule id */
				? esc_html( sprintf( __( 'Αλλαγή κανόνα #%d', 'redirects' ), $rule['id'] ) )
				: esc_html__( 'Νέος κανόνας', 'redirects' );
			?>
		</h2>
		<form method="post" class="rdr-zone rdr-rule-form">
			<?php self::form_head( 'save_rule' ); ?>
			<input type="hidden" name="rdr_id" value="<?php echo (int) ( $rule ? $rule['id'] : 0 ); ?>" />
			<div class="rdr-grid">
				<label class="rdr-field"><span class="rdr-label"><?php esc_html_e( 'Παλιά διεύθυνση', 'redirects' ); ?></span>
					<input type="text" class="rdr-wide" name="rdr_from" value="<?php echo esc_attr( $from ); ?>" placeholder="/old-page/" maxlength="500" required /></label>
				<label class="rdr-field"><span class="rdr-label"><?php esc_html_e( 'Σύγκριση', 'redirects' ); ?></span>
					<select name="rdr_match">
						<?php foreach ( RDR_Rules::MATCHES as $m ) : ?>
							<option value="<?php echo esc_attr( $m ); ?>"<?php selected( $m, $match ); ?>><?php echo esc_html( self::match_label( $m ) ); ?></option>
						<?php endforeach; ?>
					</select></label>
				<label class="rdr-field"><span class="rdr-label"><?php esc_html_e( 'Νέα διεύθυνση', 'redirects' ); ?></span>
					<input type="text" class="rdr-wide" name="rdr_to" value="<?php echo esc_attr( $to ); ?>" placeholder="/new-page/ · https://…" maxlength="2000" /></label>
				<label class="rdr-field"><span class="rdr-label"><?php esc_html_e( 'Τύπος', 'redirects' ); ?></span>
					<select name="rdr_code">
						<?php foreach ( RDR_Rules::CODES as $c ) : ?>
							<option value="<?php echo (int) $c; ?>"<?php selected( $c, $code ); ?>><?php echo esc_html( self::code_label( $c ) ); ?></option>
						<?php endforeach; ?>
					</select></label>
				<label class="rdr-field"><span class="rdr-label"><?php esc_html_e( 'Σημείωση', 'redirects' ); ?></span>
					<input type="text" class="rdr-wide" name="rdr_note" value="<?php echo esc_attr( $rule ? $rule['note'] : '' ); ?>" maxlength="200" /></label>
			</div>
			<?php self::checkbox( 'rdr_staged', $stg, sprintf( /* translators: %d: days */ __( 'Ένα 301 ξεκινά ως 302 (προσωρινή) και σε %d ημέρες προτείνεται να γίνει μόνιμο, ώστε ένα λάθος να μη μείνει για πάντα στους browsers', 'redirects' ), RDR_Settings::STAGE_DAYS ) ); ?>
			<p class="description"><?php esc_html_e( 'Παλιά διεύθυνση: διαδρομή ή URL του site, με ή χωρίς τελική κάθετο, ελληνικά ή encoded. «Αρχίζει με» πιάνει και ό,τι είναι κάτω από τη διαδρομή. Regex: πάνω στη διαδρομή χωρίς την πρώτη κάθετο, σε πεζά, με $1…$9 στη νέα διεύθυνση (π.χ. ^μάρκα/(.+) → /brand/$1). Νέα διεύθυνση: διαδρομή του site που αρχίζει με / ή URL· αν είναι άρθρο, προϊόν ή κατηγορία, ο κανόνας το ακολουθεί κι αν αλλάξει ξανά slug. Για το 410 μένει κενή.', 'redirects' ); ?></p>
			<p class="rdr-submit">
				<button type="submit" class="button button-primary"><?php echo $rule ? esc_html__( 'Αποθήκευση κανόνα', 'redirects' ) : esc_html__( 'Προσθήκη κανόνα', 'redirects' ); ?></button>
				<?php if ( $rule ) : ?>
					<a class="button" href="<?php echo esc_url( self::url( 'rules' ) ); ?>"><?php esc_html_e( 'Ακύρωση', 'redirects' ); ?></a>
				<?php endif; ?>
			</p>
		</form>

		<?php self::render_test(); ?>

		<?php
		// List.
		$search = self::q( 's' );
		$only   = in_array( self::q( 'only' ), array( 'staged', 'off' ), true ) ? self::q( 'only' ) : '';
		$page   = max( 1, (int) self::q( 'paged' ) );
		$all    = RDR_Rules::all(
			array(
				'search' => $search,
				'page'   => $page,
				'per'    => 50,
				'only'   => $only,
			)
		);
		$hits   = RDR_Log::hits( 30 );
		?>
		<h2 class="rdr-h2"><?php esc_html_e( 'Κανόνες', 'redirects' ); ?></h2>
		<div class="rdr-zone">
			<p class="rdr-counts">
				<?php
				/* translators: %s: number */
				echo self::badge( sprintf( __( 'Ενεργοί: %s', 'redirects' ), number_format_i18n( $counts['active'] ) ), 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
				/* translators: %s: number */
				echo self::badge( sprintf( __( 'Ως 302 (δοκιμή): %s', 'redirects' ), number_format_i18n( $counts['staged'] ) ), 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
				/* translators: %s: number */
				echo self::badge( sprintf( __( 'Ανενεργοί: %s', 'redirects' ), number_format_i18n( $counts['off'] ) ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
				?>
			</p>
			<form method="get" class="rdr-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<input type="hidden" name="tab" value="rules" />
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Αναζήτηση', 'redirects' ); ?>" />
				<select name="only">
					<option value=""><?php esc_html_e( 'Όλοι', 'redirects' ); ?></option>
					<option value="staged"<?php selected( 'staged', $only ); ?>><?php esc_html_e( 'Ως 302 (δοκιμή)', 'redirects' ); ?></option>
					<option value="off"<?php selected( 'off', $only ); ?>><?php esc_html_e( 'Ανενεργοί', 'redirects' ); ?></option>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Φίλτρο', 'redirects' ); ?></button>
			</form>
		</div>

		<form method="post">
			<?php self::form_head( 'bulk' ); ?>
			<div class="rdr-table-wrap">
			<table class="widefat striped rdr-table">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" class="rdr-all" onclick="var c=this.checked;this.closest('table').querySelectorAll('input[name=&quot;rdr_ids[]&quot;]').forEach(function(x){x.checked=c;});" aria-label="<?php esc_attr_e( 'Επιλογή όλων', 'redirects' ); ?>" /></td>
						<th><?php esc_html_e( 'Παλιά διεύθυνση', 'redirects' ); ?></th>
						<th><?php esc_html_e( 'Νέα διεύθυνση', 'redirects' ); ?></th>
						<th><?php esc_html_e( 'Τύπος', 'redirects' ); ?></th>
						<th><?php esc_html_e( 'Hits 30 ημ.', 'redirects' ); ?></th>
						<th><?php esc_html_e( 'Σημείωση', 'redirects' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $all['rows'] ) : ?>
					<tr><td colspan="6" class="rdr-empty"><?php esc_html_e( 'Κανένας κανόνας ακόμα.', 'redirects' ); ?></td></tr>
				<?php endif; ?>
				<?php
				foreach ( $all['rows'] as $r ) :
					list( $turl, $tok ) = RDR_Rules::describe_target( $r );
					?>
					<tr class="<?php echo $r['active'] ? '' : 'rdr-off'; ?>">
						<th class="check-column"><input type="checkbox" name="rdr_ids[]" value="<?php echo (int) $r['id']; ?>" /></th>
						<td>
							<code><?php echo esc_html( 'regex' === $r['match'] ? $r['src'] : self::show_path( $r['src'] ) ); ?></code>
							<?php
							if ( 'exact' !== $r['match'] ) {
								echo ' ' . self::badge( self::match_label( $r['match'] ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
							}
							if ( ! $r['active'] ) {
								echo ' ' . self::badge( __( 'ανενεργός', 'redirects' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
							}
							?>
							<div class="rdr-row-actions">
								<span class="rdr-muted">#<?php echo (int) $r['id']; ?></span>
								<a href="<?php echo esc_url( self::url( 'rules', array( 'edit' => $r['id'] ) ) ); ?>#rdr-form"><?php esc_html_e( 'Αλλαγή', 'redirects' ); ?></a>
							</div>
						</td>
						<td>
							<?php if ( 410 === $r['code'] ) : ?>
								<span class="rdr-muted">—</span>
							<?php elseif ( $tok ) : ?>
								<a href="<?php echo esc_url( $turl ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( rawurldecode( $turl ) ); ?></a>
								<?php
								if ( 'url' !== $r['target_type'] ) {
									echo ' ' . self::badge( 'post' === $r['target_type'] ? __( 'ακολουθεί το άρθρο', 'redirects' ) : __( 'ακολουθεί τον όρο', 'redirects' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
								}
								?>
							<?php else : ?>
								<span class="is-fail"><?php echo esc_html( $turl ); ?></span>
								<?php echo ' ' . self::badge( __( 'ο στόχος δεν υπάρχει: μένει το 404', 'redirects' ), 'danger' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?>
							<?php endif; ?>
						</td>
						<td>
							<?php
							echo esc_html( self::code_label( $r['code'] ) );
							if ( $r['staged'] && 301 === $r['code'] ) {
								$until = $r['created'] + RDR_Settings::STAGE_DAYS * DAY_IN_SECONDS;
								echo '<br />' . self::badge( __( 'απαντά 302', 'redirects' ), 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
								if ( $until > time() ) {
									/* translators: %s: date */
									echo ' <span class="rdr-muted">' . esc_html( sprintf( __( 'πρόταση για 301 στις %s', 'redirects' ), wp_date( get_option( 'date_format' ), $until ) ) ) . '</span>';
								}
							}
							?>
						</td>
						<td><?php echo esc_html( number_format_i18n( isset( $hits[ $r['id'] ] ) ? (int) $hits[ $r['id'] ] : 0 ) ); ?></td>
						<td class="rdr-muted"><?php echo esc_html( $r['note'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<p class="rdr-bulk">
				<select name="rdr_bulk">
					<option value=""><?php esc_html_e( 'Μαζική ενέργεια', 'redirects' ); ?></option>
					<option value="on"><?php esc_html_e( 'Ενεργοποίηση', 'redirects' ); ?></option>
					<option value="off"><?php esc_html_e( 'Απενεργοποίηση', 'redirects' ); ?></option>
					<option value="unstage"><?php esc_html_e( 'Κάνε τους 301 (μόνιμη)', 'redirects' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Διαγραφή', 'redirects' ); ?></option>
				</select>
				<button type="submit" class="button" onclick="var s=this.form.rdr_bulk.value;return s!=='delete'||confirm('<?php echo esc_js( __( 'Διαγραφή των επιλεγμένων κανόνων;', 'redirects' ) ); ?>');"><?php esc_html_e( 'Εφαρμογή', 'redirects' ); ?></button>
			</p>
		</form>
		<?php
		$pages = (int) ceil( $all['total'] / 50 );
		if ( $pages > 1 ) {
			echo '<p class="rdr-pages">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				if ( $i === $page ) {
					echo '<strong>' . (int) $i . '</strong> ';
				} else {
					echo '<a href="' . esc_url( self::url( 'rules', array( 'paged' => $i, 's' => $search, 'only' => $only ) ) ) . '">' . (int) $i . '</a> ';
				}
			}
			echo '</p>';
		}
	}

	/** «Test an address» (GET, read only). */
	private static function render_test(): void {
		$url = self::q_raw( 'rdr_test' );
		?>
		<h2 class="rdr-h2"><?php esc_html_e( 'Δοκιμή διεύθυνσης', 'redirects' ); ?></h2>
		<div class="rdr-zone">
			<form method="get" class="rdr-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<input type="hidden" name="tab" value="rules" />
				<input type="text" class="rdr-wide" name="rdr_test" value="<?php echo esc_attr( $url ); ?>" placeholder="<?php echo esc_attr( home_url( '/old-page/' ) ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Τι θα γίνει;', 'redirects' ); ?></button>
			</form>
			<?php
			if ( '' !== $url ) {
				$e    = RDR_Rules::explain( $url );
				$text = '';
				switch ( $e['result'] ) {
					case 'external':
						$text = __( 'Δεν είναι διεύθυνση αυτού του site.', 'redirects' );
						break;
					case 'protected':
						$text = __( 'Διεύθυνση που δεν ανακατευθύνεται ποτέ (αρχική, διαχείριση, σύνδεση, REST, καλάθι, ταμείο ή λογαριασμός).', 'redirects' );
						break;
					case 'exists':
						$text = __( 'Η σελίδα ή το αρχείο υπάρχει: ανοίγει κανονικά και κανένας κανόνας δεν εφαρμόζεται.', 'redirects' );
						break;
					case 'rule':
						if ( 410 === $e['code'] ) {
							/* translators: %d: rule id */
							$text = sprintf( __( 'Κανόνας #%d: απαντά 410 (δεν υπάρχει πια).', 'redirects' ), $e['rule'] );
						} elseif ( 404 === $e['code'] ) {
							/* translators: %d: rule id */
							$text = sprintf( __( 'Κανόνας #%d, αλλά ο στόχος του δεν υπάρχει: μένει το 404.', 'redirects' ), $e['rule'] );
						} else {
							/* translators: 1: rule id, 2: code, 3: URL */
							$text = sprintf( __( 'Κανόνας #%1$d: %2$d προς %3$s', 'redirects' ), $e['rule'], $e['code'], rawurldecode( $e['target'] ) );
						}
						if ( ! $e['live'] ) {
							$text .= ' ' . __( '(Λειτουργία δοκιμής: μόνο για διαχειριστές.)', 'redirects' );
						}
						break;
					case 'old_slug':
						/* translators: %s: URL */
						$text = sprintf( __( 'Κανένας κανόνας: το WordPress το στέλνει με 301 από το παλιό slug στο %s.', 'redirects' ), rawurldecode( $e['target'] ) );
						break;
					case 'greeklish':
						$text = __( 'Κανένας κανόνας: ελληνική διεύθυνση, που μπορεί να τη στείλει το Greeklish Permalinks, αν βρει άρθρο ή όρο στη μορφή greeklish. Αλλιώς 404.', 'redirects' );
						break;
					default:
						$text = __( 'Κανένας κανόνας: 404.', 'redirects' );
				}
				echo '<p class="rdr-test-result"><code>' . esc_html( null === $e['path'] ? $url : self::show_path( (string) $e['path'] ) ) . '</code> ' . esc_html( $text ) . '</p>';
			}
			?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * 404
	 * ------------------------------------------------------------------- */

	private static function ignored(): array {
		$i = get_option( self::IGNORED, array() );
		return is_array( $i ) ? array_values( array_filter( $i, 'is_string' ) ) : array();
	}

	private static function render_404(): void {
		$s     = RDR_Settings::get();
		$days  = in_array( (int) self::q( 'days' ), array( 1, 7, 30, 90 ), true ) ? (int) self::q( 'days' ) : 30;
		$ign   = self::ignored();
		$rep   = RDR_Log::report( $days, $ign );
		$files = RDR_Log::files();

		if ( ! $s['log'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το log 404 είναι κλειστό στις Ρυθμίσεις: δεν γράφεται τίποτα καινούργιο.', 'redirects' ) . '</p></div>';
		}
		if ( '' === RDR_Log::dir() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Ο φάκελος του log δεν φτιάχτηκε (το uploads δεν γράφεται;). Χωρίς αυτόν δεν γράφεται log.', 'redirects' ) . '</p></div>';
		}
		?>
		<div class="rdr-zone">
			<form method="get" class="rdr-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<input type="hidden" name="tab" value="404" />
				<select name="days">
					<?php foreach ( array( 1, 7, 30, 90 ) as $d ) : ?>
						<option value="<?php echo (int) $d; ?>"<?php selected( $d, $days ); ?>>
							<?php
							/* translators: %d: days */
							echo esc_html( 1 === $d ? __( 'Τελευταίο 24ωρο', 'redirects' ) : sprintf( __( 'Τελευταίες %d ημέρες', 'redirects' ), $d ) );
							?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Εμφάνιση', 'redirects' ); ?></button>
			</form>
			<p class="rdr-counts">
				<?php
				/* translators: %s: number */
				echo self::badge( sprintf( __( '404: %s', 'redirects' ), number_format_i18n( $rep['total'] ) ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
				/* translators: %s: number */
				echo self::badge( sprintf( __( 'Διευθύνσεις: %s', 'redirects' ), number_format_i18n( $rep['paths'] ) ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
				/* translators: %s: number */
				echo self::badge( sprintf( __( 'Από crawlers: %s', 'redirects' ), number_format_i18n( $rep['bots'] ) ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
				/* translators: %s: number */
				echo self::badge( sprintf( __( 'Από links του site σου: %s', 'redirects' ), number_format_i18n( $rep['self'] ) ), $rep['self'] > 0 ? 'warn' : 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
				?>
			</p>
			<p class="description"><?php esc_html_e( 'Μόνο ό,τι έμεινε 404: ό,τι έστειλε κάποιος κανόνας, το WordPress ή το Greeklish Permalinks δεν είναι εδώ. Οι διευθύνσεις που ψάχνουν τα bots για τρύπες (λίστα στις Ρυθμίσεις) δεν γράφονται καθόλου. «Από links του site σου» σημαίνει σπασμένο link μέσα στο site: διόρθωσέ το και στο κείμενο.', 'redirects' ); ?></p>
		</div>

		<div class="rdr-table-wrap">
		<table class="widefat striped rdr-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Διεύθυνση', 'redirects' ); ?></th>
					<th><?php esc_html_e( 'Φορές', 'redirects' ); ?></th>
					<th><?php esc_html_e( 'Τελευταία', 'redirects' ); ?></th>
					<th><?php esc_html_e( 'Από πού', 'redirects' ); ?></th>
					<th><?php esc_html_e( 'Πρόταση', 'redirects' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $rep['rows'] ) : ?>
				<tr><td colspan="5" class="rdr-empty"><?php esc_html_e( 'Κανένα 404 σε αυτή την περίοδο.', 'redirects' ); ?></td></tr>
			<?php endif; ?>
			<?php
			foreach ( array_slice( $rep['rows'], 0, 100 ) as $i => $r ) :
				$sugg = $i < 30 ? RDR_Suggest::for_path( $r['path'] ) : array();
				?>
				<tr>
					<td><code><?php echo esc_html( self::show_path( $r['path'] ) ); ?></code></td>
					<td>
						<?php echo esc_html( number_format_i18n( $r['hits'] ) ); ?>
						<?php if ( $r['bots'] > 0 ) : ?>
							<br /><span class="rdr-muted">
							<?php
							/* translators: %s: number */
							echo esc_html( sprintf( __( 'crawlers: %s', 'redirects' ), number_format_i18n( $r['bots'] ) ) );
							?>
							</span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( wp_date( self::fmt(), $r['last'] ) ); ?></td>
					<td>
						<?php
						$from = array();
						if ( $r['self'] > 0 ) {
							/* translators: %s: number */
							$from[] = sprintf( __( 'το site σου (%s)', 'redirects' ), number_format_i18n( $r['self'] ) );
						}
						foreach ( $r['hosts'] as $h => $n ) {
							$from[] = $h . ' (' . number_format_i18n( $n ) . ')';
						}
						echo $from ? esc_html( implode( ', ', $from ) ) : '<span class="rdr-muted">—</span>';
						?>
					</td>
					<td class="rdr-actions">
						<?php foreach ( $sugg as $sg ) : ?>
							<div class="rdr-sugg">
								<?php
								self::button_form(
									'from_404',
									array(
										'rdr_from' => '/' . $r['path'],
										'rdr_to'   => $sg['url'],
										'rdr_code' => 301,
									),
									__( '301 εδώ', 'redirects' ),
									'button button-primary'
								);
								?>
								<a href="<?php echo esc_url( $sg['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( '' !== $sg['label'] ? $sg['label'] : rawurldecode( $sg['url'] ) ); ?></a>
								<span class="rdr-muted"><?php echo esc_html( $sg['why'] ); ?></span>
							</div>
						<?php endforeach; ?>
						<div class="rdr-sugg">
							<a class="button" href="<?php echo esc_url( self::url( 'rules', array( 'from' => '/' . $r['path'] ) ) ); ?>#rdr-form"><?php esc_html_e( 'Άλλος στόχος', 'redirects' ); ?></a>
							<?php
							self::button_form(
								'from_404',
								array(
									'rdr_from' => '/' . $r['path'],
									'rdr_to'   => '',
									'rdr_code' => 410,
								),
								__( '410', 'redirects' )
							);
							self::button_form( 'ignore', array( 'rdr_path' => $r['path'] ), __( 'Αγνόηση', 'redirects' ) );
							?>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>

		<h2 class="rdr-h2"><?php esc_html_e( 'Αρχεία του log', 'redirects' ); ?></h2>
		<div class="rdr-zone">
			<p>
				<?php
				$size = 0;
				foreach ( $files as $f ) {
					$size += $f[0];
				}
				/* translators: 1: number of files, 2: size, 3: months */
				echo esc_html( sprintf( __( '%1$d αρχεία, %2$s συνολικά. Κρατούνται %3$d μήνες.', 'redirects' ), count( $files ), size_format( $size ), (int) $s['months'] ) );
				?>
			</p>
			<?php if ( $ign ) : ?>
				<p>
					<?php
					/* translators: %d: number of paths */
					echo esc_html( sprintf( _n( '%d διεύθυνση αγνοείται και δεν φαίνεται στον πίνακα.', '%d διευθύνσεις αγνοούνται και δεν φαίνονται στον πίνακα.', count( $ign ), 'redirects' ), count( $ign ) ) );
					?>
				</p>
				<?php self::button_form( 'unignore', array(), __( 'Να ξαναφανούν', 'redirects' ) ); ?>
			<?php endif; ?>
			<form method="post" class="rdr-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Σβήνονται όλα τα αρχεία του log (και τα hits των κανόνων). Συνέχεια;', 'redirects' ) ); ?>');">
				<?php self::form_head( 'clear_log' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Καθαρισμός log', 'redirects' ); ?></button>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Import / export
	 * ------------------------------------------------------------------- */

	private static function preview_key(): string {
		return 'rdr_prev_' . get_current_user_id();
	}

	private static function render_import(): void {
		$avail = RDR_Import::available();
		$prev  = get_transient( self::preview_key() );
		if ( is_array( $prev ) && isset( $prev['rows'], $prev['source'] ) ) {
			self::render_preview( $prev );
			return;
		}
		?>
		<h2 class="rdr-h2"><?php esc_html_e( 'Εισαγωγή από άλλο plugin', 'redirects' ); ?></h2>
		<div class="rdr-zone">
			<?php if ( ! $avail ) : ?>
				<p class="rdr-muted"><?php esc_html_e( 'Δεν βρέθηκαν κανόνες από Rank Math, Redirection, Yoast SEO Premium ή Simple 301 Redirects σε αυτό το site.', 'redirects' ); ?></p>
			<?php endif; ?>
			<?php foreach ( RDR_Import::sources() as $key => $label ) : ?>
				<?php
				if ( ! isset( $avail[ $key ] ) ) {
					continue;
				}
				?>
				<p>
					<strong><?php echo esc_html( $label ); ?></strong>
					<span class="rdr-muted">
					<?php
					/* translators: %s: number of rows */
					echo esc_html( sprintf( __( '%s γραμμές', 'redirects' ), number_format_i18n( $avail[ $key ] ) ) );
					?>
					</span>
					<?php self::button_form( 'scan', array( 'rdr_source' => $key ), __( 'Σάρωση', 'redirects' ) ); ?>
				</p>
			<?php endforeach; ?>
			<p class="description"><?php esc_html_e( 'Πρώτα σάρωση και προεπισκόπηση: τίποτα δεν αποθηκεύεται πριν πατήσεις «Εισαγωγή». Οι πίνακες των άλλων plugins μόνο διαβάζονται. Μετά την εισαγωγή κλείσε το module ανακατευθύνσεων του άλλου plugin, ώστε να μένει ένα σύστημα.', 'redirects' ); ?></p>
		</div>

		<h2 class="rdr-h2"><?php esc_html_e( 'Εισαγωγή από CSV', 'redirects' ); ?></h2>
		<div class="rdr-zone">
			<form method="post" enctype="multipart/form-data" class="rdr-inline-form">
				<?php self::form_head( 'scan_csv' ); ?>
				<input type="file" name="rdr_csv" accept=".csv,text/csv" />
				<button type="submit" class="button"><?php esc_html_e( 'Σάρωση CSV', 'redirects' ); ?></button>
			</form>
			<p class="description"><?php esc_html_e( 'Στήλες: from, to, code (301, 302, 307, 410· κενό = 301), match (exact, prefix, regex· κενό = exact). Η πρώτη γραμμή μπορεί να είναι επικεφαλίδα. Έως 5.000 γραμμές.', 'redirects' ); ?></p>
		</div>

		<h2 class="rdr-h2"><?php esc_html_e( 'Εξαγωγή και backup', 'redirects' ); ?></h2>
		<div class="rdr-zone">
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( self::url( 'import', array( 'rdr_export' => 'csv' ) ), self::NONCE_EXP ) ); ?>"><?php esc_html_e( 'Εξαγωγή κανόνων (CSV)', 'redirects' ); ?></a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( self::url( 'import', array( 'rdr_export' => 'json' ) ), self::NONCE_EXP ) ); ?>"><?php esc_html_e( 'Backup (JSON)', 'redirects' ); ?></a>
			</p>
			<form method="post" enctype="multipart/form-data" class="rdr-inline-form">
				<?php self::form_head( 'import_json' ); ?>
				<input type="file" name="rdr_json" accept=".json,application/json" />
				<button type="submit" class="button"><?php esc_html_e( 'Επαναφορά backup (JSON)', 'redirects' ); ?></button>
			</form>
			<p class="description"><?php esc_html_e( 'Το backup έχει τις ρυθμίσεις, τους κανόνες και τις αγνοημένες διευθύνσεις, όχι το log. Η επαναφορά προσθέτει όσους κανόνες λείπουν, με τους ίδιους ελέγχους· οι υπάρχοντες μένουν όπως είναι.', 'redirects' ); ?></p>
		</div>
		<?php
	}

	private static function render_preview( array $prev ): void {
		$rows   = $prev['rows'];
		$labels = RDR_Import::sources() + array( 'csv' => 'CSV' );
		$stat   = array(
			'new'     => 0,
			'dup'     => 0,
			'invalid' => 0,
		);
		foreach ( $rows as $r ) {
			++$stat[ $r['status'] ];
		}
		?>
		<h2 class="rdr-h2">
			<?php
			/* translators: %s: source name */
			echo esc_html( sprintf( __( 'Προεπισκόπηση: %s', 'redirects' ), isset( $labels[ $prev['source'] ] ) ? $labels[ $prev['source'] ] : $prev['source'] ) );
			?>
		</h2>
		<div class="rdr-zone">
			<p class="rdr-counts">
				<?php
				/* translators: %s: number */
				echo self::badge( sprintf( __( 'Νέοι: %s', 'redirects' ), number_format_i18n( $stat['new'] ) ), 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
				/* translators: %s: number */
				echo self::badge( sprintf( __( 'Υπάρχουν ήδη: %s', 'redirects' ), number_format_i18n( $stat['dup'] ) ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
				/* translators: %s: number */
				echo self::badge( sprintf( __( 'Δεν περνούν: %s', 'redirects' ), number_format_i18n( $stat['invalid'] ) ), $stat['invalid'] ? 'danger' : 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
				?>
			</p>
			<p class="description"><?php esc_html_e( 'Τσεκαρισμένοι είναι οι νέοι κανόνες που ήταν ενεργοί στην πηγή. Οι κανόνες μπαίνουν ενεργοί με τον τύπο τους (όχι ως 302), αφού λειτουργούσαν ήδη.', 'redirects' ); ?></p>
		</div>
		<form method="post">
			<?php self::form_head( 'apply_import' ); ?>
			<div class="rdr-scroll">
			<table class="widefat striped rdr-table">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" onclick="var c=this.checked;this.closest('table').querySelectorAll('input[name=&quot;rdr_pick[]&quot;]:not(:disabled)').forEach(function(x){x.checked=c;});" aria-label="<?php esc_attr_e( 'Επιλογή όλων', 'redirects' ); ?>" /></td>
						<th><?php esc_html_e( 'Παλιά διεύθυνση', 'redirects' ); ?></th>
						<th><?php esc_html_e( 'Νέα διεύθυνση', 'redirects' ); ?></th>
						<th><?php esc_html_e( 'Τύπος', 'redirects' ); ?></th>
						<th><?php esc_html_e( 'Κατάσταση', 'redirects' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( array_slice( $rows, 0, 1000, true ) as $i => $r ) : ?>
					<tr>
						<th class="check-column"><input type="checkbox" name="rdr_pick[]" value="<?php echo (int) $i; ?>"<?php checked( 'new' === $r['status'] && $r['active'] ); ?><?php disabled( 'new' !== $r['status'] ); ?> /></th>
						<td><code><?php echo esc_html( $r['from'] ); ?></code> <?php echo 'exact' !== $r['match'] ? self::badge( self::match_label( $r['match'] ), 'muted' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></td>
						<td><?php echo esc_html( 410 === $r['code'] ? '—' : rawurldecode( $r['to'] ) ); ?></td>
						<td><?php echo esc_html( self::code_label( $r['code'] ) ); ?><?php echo $r['active'] ? '' : ' ' . self::badge( __( 'ανενεργός', 'redirects' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></td>
						<td>
							<?php
							if ( 'new' === $r['status'] ) {
								echo self::badge( __( 'νέος', 'redirects' ), 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
							} else {
								echo self::badge( 'dup' === $r['status'] ? __( 'υπάρχει', 'redirects' ) : __( 'δεν περνά', 'redirects' ), 'dup' === $r['status'] ? 'muted' : 'danger' ) . ' <span class="rdr-muted">' . esc_html( $r['why'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php if ( count( $rows ) > 1000 ) : ?>
				<p class="description"><?php esc_html_e( 'Φαίνονται οι πρώτες 1.000 γραμμές. Χώρισε το CSV για τις υπόλοιπες.', 'redirects' ); ?></p>
			<?php endif; ?>
			<p class="rdr-submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Εισαγωγή των επιλεγμένων', 'redirects' ); ?></button>
			</p>
		</form>
		<?php self::button_form( 'cancel_import', array(), __( 'Ακύρωση', 'redirects' ) ); ?>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	private static function render_settings(): void {
		$s  = RDR_Settings::get();
		$st = RDR_Log::state();
		?>
		<form method="post">
			<?php self::form_head( 'save_settings' ); ?>

			<h2 class="rdr-h2"><?php esc_html_e( 'Λειτουργία', 'redirects' ); ?></h2>
			<div class="rdr-zone">
				<?php
				self::radio( 'rdr_mode', 'test', $s['mode'], __( 'Δοκιμή: οι κανόνες ισχύουν μόνο για διαχειριστές· οι επισκέπτες βλέπουν το 404', 'redirects' ) );
				self::radio( 'rdr_mode', 'live', $s['mode'], __( 'Ενεργό: οι κανόνες ισχύουν για όλους', 'redirects' ) );
				?>
				<p class="description"><?php esc_html_e( 'Στη δοκιμή άνοιξε μια παλιά διεύθυνση συνδεδεμένος: θα δεις την ανακατεύθυνση, και το header X-Redirects λέει ποιος κανόνας απάντησε.', 'redirects' ); ?></p>
				<?php
				self::checkbox( 'rdr_staged', $s['staged'], sprintf( /* translators: %d: days */ __( 'Ένας νέος κανόνας 301 ξεκινά ως 302 και σε %d ημέρες προτείνεται να γίνει μόνιμος', 'redirects' ), RDR_Settings::STAGE_DAYS ) );
				self::checkbox( 'rdr_query', $s['query'], __( 'Το query string της παλιάς διεύθυνσης (π.χ. ?utm_source=…) περνά στη νέα, αν η νέα δεν έχει δικό της', 'redirects' ) );
				?>
			</div>

			<h2 class="rdr-h2"><?php esc_html_e( 'Log 404', 'redirects' ); ?></h2>
			<div class="rdr-zone">
				<?php self::checkbox( 'rdr_log', $s['log'], __( 'Καταγραφή των 404 και των hits των κανόνων σε ιδιωτικό αρχείο', 'redirects' ) ); ?>
				<p class="description"><?php esc_html_e( 'Γράφονται μόνο η διαδρομή (χωρίς query string), η ώρα, το host από το οποίο ήρθε ο επισκέπτης και αν ήταν crawler. Καμία IP, κανένα user agent, καμία εγγραφή στη βάση.', 'redirects' ); ?></p>
				<div class="rdr-grid">
					<label class="rdr-field"><span class="rdr-label"><?php esc_html_e( 'Μήνες που κρατιούνται (1–12)', 'redirects' ); ?></span>
						<input type="number" class="rdr-num" name="rdr_months" value="<?php echo (int) $s['months']; ?>" min="1" max="12" /></label>
					<label class="rdr-field"><span class="rdr-label"><?php esc_html_e( 'MB ανά μήνα (1–50)', 'redirects' ); ?></span>
						<input type="number" class="rdr-num" name="rdr_max_mb" value="<?php echo (int) $s['max_mb']; ?>" min="1" max="50" /></label>
					<label class="rdr-field"><span class="rdr-label"><?php esc_html_e( 'Γραμμές το λεπτό (10–600)', 'redirects' ); ?></span>
						<input type="number" class="rdr-num" name="rdr_per_min" value="<?php echo (int) $s['per_min']; ?>" min="10" max="600" /></label>
				</div>
				<?php self::checkbox( 'rdr_bots', $s['bots'], __( 'Οι διευθύνσεις που ψάχνουν τα bots για τρύπες δεν γράφονται καθόλου', 'redirects' ) ); ?>
				<span class="rdr-label"><?php esc_html_e( 'Λίστα (μία ανά γραμμή, * = οτιδήποτε, χωρίς την πρώτη κάθετο)', 'redirects' ); ?></span>
				<textarea name="rdr_patterns" class="rdr-patterns" rows="8"><?php echo esc_textarea( implode( "\n", $s['patterns'] ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Μόνο για το log: οι κανόνες δουλεύουν και για αυτές τις διευθύνσεις. Κενό πεδίο = η προεπιλεγμένη λίστα.', 'redirects' ); ?></p>
			</div>

			<h2 class="rdr-h2"><?php esc_html_e( 'Απεγκατάσταση', 'redirects' ); ?></h2>
			<div class="rdr-zone">
				<?php self::checkbox( 'rdr_keep', $s['keep'], __( 'Να μείνουν οι κανόνες και το log όταν σβήσω το plugin', 'redirects' ) ); ?>
			</div>

			<p class="rdr-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'redirects' ); ?></button></p>
		</form>

		<h2 class="rdr-h2"><?php esc_html_e( 'Φάκελος του log', 'redirects' ); ?></h2>
		<div class="rdr-zone">
			<?php
			$dir = RDR_Log::dir();
			if ( '' === $dir ) {
				echo '<p>' . self::badge( __( 'Λείπει', 'redirects' ), 'danger' ) . ' ' . esc_html__( 'Το uploads δεν γράφεται.', 'redirects' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
			} else {
				$web   = isset( $st['web'] ) ? (string) $st['web'] : 'unknown';
				$kinds = array(
					'safe'    => array( __( 'Κλειστός από τον browser', 'redirects' ), 'ok' ),
					'exposed' => array( __( 'Κατεβαίνει από τον browser', 'redirects' ), 'danger' ),
					'unknown' => array( __( 'Δεν ελέγχθηκε (το site δεν απάντησε)', 'redirects' ), 'warn' ),
				);
				$k     = isset( $kinds[ $web ] ) ? $kinds[ $web ] : $kinds['unknown'];
				echo '<p>' . self::badge( $k[0], $k[1] ) . ' <code>' . esc_html( str_replace( ABSPATH, '', $dir ) ) . '</code>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge().
				if ( ! empty( $st['web_time'] ) ) {
					/* translators: %s: date */
					echo ' <span class="rdr-muted">' . esc_html( sprintf( __( 'έλεγχος: %s', 'redirects' ), wp_date( self::fmt(), (int) $st['web_time'] ) ) ) . '</span>';
				}
				echo '</p>';
				self::button_form( 'web_test', array(), __( 'Έλεγχος ξανά', 'redirects' ) );
			}
			?>
			<p class="description"><?php esc_html_e( 'Ο φάκελος έχει .htaccess και web.config που τον κλείνουν. Σε nginx χρειάζεται κανόνας στον server· ο έλεγχος ζητά ένα δοκιμαστικό αρχείο από τον browser για να το δει.', 'redirects' ); ?></p>
		</div>

		<h2 class="rdr-h2"><?php esc_html_e( 'Για developers', 'redirects' ); ?></h2>
		<div class="rdr-zone">
			<table class="widefat striped rdr-table">
				<tbody>
					<tr><td><code>rdr_add_redirect( $from, $to, $code, $source )</code></td><td><?php esc_html_e( 'Προσθέτει κανόνα με τους ίδιους ελέγχους με αυτή τη σελίδα· επιστρέφει το id ή 0.', 'redirects' ); ?></td></tr>
					<tr><td><code>rdr_redirect_target</code> <?php esc_html_e( '(φίλτρο)', 'redirects' ); ?></td><td><?php esc_html_e( 'Ο στόχος πριν από την ανακατεύθυνση: ( $url, $rule, $path ). Κενό = χωρίς ανακατεύθυνση.', 'redirects' ); ?></td></tr>
					<tr><td><code>redirects/list, top-404, test-url, add</code></td><td><?php esc_html_e( 'Abilities (WordPress 6.9+) για το Site Bridge, με το ίδιο δικαίωμα (manage_options).', 'redirects' ); ?></td></tr>
					<tr><td><code>X-Redirects</code></td><td><?php esc_html_e( 'Header σε κάθε ανακατεύθυνση: το id του κανόνα (και «test» στη δοκιμή).', 'redirects' ); ?></td></tr>
					<tr><td><code>RDR_DISABLE</code></td><td><?php esc_html_e( 'Στο wp-config.php: κανένας κανόνας και κανένα log.', 'redirects' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* =====================================================================
	 * POST routes (admin_init, PRG)
	 * =================================================================== */

	public static function route(): void {
		if ( self::SLUG !== self::current_page() || ! isset( $_POST[ self::NONCE_FIELD ], $_POST['rdr_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Redirects::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'redirects' ) );
		}

		switch ( sanitize_key( wp_unslash( $_POST['rdr_action'] ) ) ) {
			case 'save_rule':
				self::do_save_rule();
				break;
			case 'from_404':
				self::do_from_404();
				break;
			case 'bulk':
				self::do_bulk();
				break;
			case 'pending':
				self::do_pending();
				break;
			case 'unstage_due':
				$n = RDR_Rules::bulk( RDR_Rules::due_for_301(), 'unstage' );
				/* translators: %d: number of rules */
				self::redirect( 'rules', array( self::msg( 'success', sprintf( _n( '%d κανόνας έγινε 301.', '%d κανόνες έγιναν 301.', $n, 'redirects' ), $n ) ) ) );
				break;
			case 'ignore':
				$p   = RDR_Path::norm( self::post( 'rdr_path' ) );
				$ign = self::ignored();
				if ( null !== $p && '' !== $p && ! in_array( $p, $ign, true ) ) {
					array_unshift( $ign, $p );
					update_option( self::IGNORED, array_slice( $ign, 0, self::MAX_IGNORED ), false );
				}
				self::redirect( '404', array( self::msg( 'success', __( 'Η διεύθυνση δεν θα φαίνεται πια στον πίνακα.', 'redirects' ) ) ) );
				break;
			case 'unignore':
				delete_option( self::IGNORED );
				self::redirect( '404', array( self::msg( 'success', __( 'Οι αγνοημένες διευθύνσεις φαίνονται ξανά.', 'redirects' ) ) ) );
				break;
			case 'clear_log':
				$n = RDR_Log::clear();
				/* translators: %d: number of files */
				self::redirect( '404', array( self::msg( 'success', sprintf( __( 'Σβήστηκαν %d αρχεία του log.', 'redirects' ), $n ) ) ) );
				break;
			case 'scan':
				self::do_scan( sanitize_key( self::post( 'rdr_source' ) ) );
				break;
			case 'scan_csv':
				self::do_scan_csv();
				break;
			case 'apply_import':
				self::do_apply_import();
				break;
			case 'cancel_import':
				delete_transient( self::preview_key() );
				self::redirect( 'import', array() );
				break;
			case 'import_json':
				self::redirect( 'import', self::do_import_json() );
				break;
			case 'save_settings':
				self::do_save_settings();
				break;
			case 'web_test':
				$r = RDR_Log::web_test();
				self::redirect( 'settings', array( 'exposed' === $r ? self::msg( 'error', __( 'Ο φάκελος κατεβαίνει από τον browser.', 'redirects' ) ) : ( 'safe' === $r ? self::msg( 'success', __( 'Ο φάκελος είναι κλειστός από τον browser.', 'redirects' ) ) : self::msg( 'warning', __( 'Το site δεν απάντησε στον έλεγχο.', 'redirects' ) ) ) ) );
				break;
			default:
				self::redirect( 'rules', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'redirects' ) ) ) );
		}
	}

	/** Messages of a saved rule. */
	private static function saved_msgs( $r ): array {
		if ( is_wp_error( $r ) ) {
			return array( self::msg( 'error', $r->get_error_message() ) );
		}
		/* translators: %d: rule id */
		$msgs = array( self::msg( 'success', sprintf( __( 'Ο κανόνας #%d αποθηκεύτηκε.', 'redirects' ), $r['id'] ) ) );
		foreach ( $r['msgs'] as $m ) {
			$msgs[] = self::msg( 'info', $m );
		}
		if ( ! RDR_Settings::live() ) {
			$msgs[] = self::msg( 'info', __( 'Λειτουργία δοκιμής: ο κανόνας ισχύει μόνο για διαχειριστές μέχρι να διαλέξεις «Ενεργό» στις Ρυθμίσεις.', 'redirects' ) );
		}
		return $msgs;
	}

	private static function do_save_rule(): void {
		$id = (int) self::post( 'rdr_id' );
		$r  = RDR_Rules::save(
			array(
				'src'    => self::post( 'rdr_from' ),
				'match'  => sanitize_key( self::post( 'rdr_match' ) ),
				'target' => self::post( 'rdr_to' ),
				'code'   => (int) self::post( 'rdr_code' ),
				'staged' => '1' === self::post( 'rdr_staged' ),
				'note'   => self::post( 'rdr_note' ),
			),
			$id
		);
		if ( is_wp_error( $r ) ) {
			// Back to the form with what was written.
			set_transient( 'rdr_aui_msg_' . get_current_user_id(), self::saved_msgs( $r ), 60 );
			wp_safe_redirect(
				self::url(
					'rules',
					$id > 0 ? array( 'edit' => $id ) : array(
						'from' => self::post( 'rdr_from' ),
						'to'   => self::post( 'rdr_to' ),
					)
				) . '#rdr-form'
			);
			exit;
		}
		self::redirect( 'rules', self::saved_msgs( $r ) );
	}

	private static function do_from_404(): void {
		$code = (int) self::post( 'rdr_code' );
		$r    = RDR_Rules::save(
			array(
				'src'    => self::post( 'rdr_from' ),
				'match'  => 'exact',
				'target' => self::post( 'rdr_to' ),
				'code'   => $code,
				'staged' => (bool) RDR_Settings::get()['staged'],
				'note'   => '404',
			)
		);
		self::redirect( '404', self::saved_msgs( $r ) );
	}

	private static function do_bulk(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$ids  = isset( $_POST['rdr_ids'] ) && is_array( $_POST['rdr_ids'] ) ? array_map( 'intval', wp_unslash( $_POST['rdr_ids'] ) ) : array();
		$what = sanitize_key( self::post( 'rdr_bulk' ) );
		if ( ! $ids || ! in_array( $what, array( 'on', 'off', 'unstage', 'delete' ), true ) ) {
			self::redirect( 'rules', array( self::msg( 'warning', __( 'Διάλεξε κανόνες και ενέργεια.', 'redirects' ) ) ) );
		}
		$n = 'delete' === $what ? RDR_Rules::delete( $ids ) : RDR_Rules::bulk( $ids, $what );
		/* translators: %d: number of rules */
		self::redirect( 'rules', array( self::msg( 'success', sprintf( _n( 'Άλλαξε %d κανόνας.', 'Άλλαξαν %d κανόνες.', $n, 'redirects' ), $n ) ) ) );
	}

	private static function do_pending(): void {
		$key  = sanitize_key( self::post( 'key' ) );
		$what = sanitize_key( self::post( 'what' ) );
		$item = RDR_Watch::find( $key );
		if ( ! $item ) {
			self::redirect( 'rules', array() );
		}
		RDR_Watch::dismiss( $key );
		if ( 'no' === $what ) {
			self::redirect( 'rules', array() );
		}
		$r = RDR_Rules::save(
			array(
				'src'    => '/' . $item['path'],
				'match'  => 'exact',
				'target' => '410' === $what ? '' : (string) $item['to'],
				'code'   => '410' === $what ? 410 : 301,
				'staged' => (bool) RDR_Settings::get()['staged'],
				'note'   => 'term' === $item['kind'] ? __( 'όρος άλλαξε διεύθυνση', 'redirects' ) : __( 'στον κάδο', 'redirects' ),
			)
		);
		if ( ! is_wp_error( $r ) ) {
			// A page cache may still hold the old page.
			do_action( 'noxpress_purge_page_cache', array( RDR_Path::url( (string) $item['path'] ) ) );
		}
		self::redirect( 'rules', self::saved_msgs( $r ) );
	}

	private static function do_scan( string $source ): void {
		$rows = RDR_Import::scan( $source );
		if ( is_wp_error( $rows ) ) {
			self::redirect( 'import', array( self::msg( 'error', $rows->get_error_message() ) ) );
		}
		if ( ! $rows ) {
			self::redirect( 'import', array( self::msg( 'warning', __( 'Η πηγή δεν έχει κανόνες που μπορούν να περάσουν.', 'redirects' ) ) ) );
		}
		set_transient(
			self::preview_key(),
			array(
				'source' => $source,
				'rows'   => RDR_Import::classify( $rows ),
			),
			HOUR_IN_SECONDS
		);
		self::redirect( 'import', array() );
	}

	private static function do_scan_csv(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['rdr_csv']['error'] ) ? (int) $_FILES['rdr_csv']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['rdr_csv']['size'] ) ? (int) $_FILES['rdr_csv']['size'] : 0;
		$tmp  = isset( $_FILES['rdr_csv']['tmp_name'] ) ? (string) $_FILES['rdr_csv']['tmp_name'] : '';
		// phpcs:enable
		if ( $size > RDR_Import::MAX_CSV ) {
			self::redirect( 'import', array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'redirects' ) ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			self::redirect( 'import', array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο.', 'redirects' ) ) ) );
		}
		$rows = RDR_Import::scan_csv( $tmp );
		if ( is_wp_error( $rows ) || ! $rows ) {
			self::redirect( 'import', array( self::msg( 'error', is_wp_error( $rows ) ? $rows->get_error_message() : __( 'Το CSV δεν έχει γραμμές με δύο στήλες τουλάχιστον.', 'redirects' ) ) ) );
		}
		set_transient(
			self::preview_key(),
			array(
				'source' => 'csv',
				'rows'   => RDR_Import::classify( $rows ),
			),
			HOUR_IN_SECONDS
		);
		self::redirect( 'import', array() );
	}

	private static function do_apply_import(): void {
		$prev = get_transient( self::preview_key() );
		if ( ! is_array( $prev ) || ! isset( $prev['rows'], $prev['source'] ) ) {
			self::redirect( 'import', array( self::msg( 'error', __( 'Η προεπισκόπηση έληξε. Κάνε ξανά σάρωση.', 'redirects' ) ) ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$picked = isset( $_POST['rdr_pick'] ) && is_array( $_POST['rdr_pick'] ) ? array_map( 'intval', wp_unslash( $_POST['rdr_pick'] ) ) : array();
		$res    = RDR_Import::apply( $prev['rows'], $picked, (string) $prev['source'] );
		delete_transient( self::preview_key() );
		/* translators: 1: imported, 2: failed */
		$msgs = array( self::msg( $res['fail'] ? 'warning' : 'success', sprintf( __( 'Μπήκαν %1$d κανόνες, %2$d δεν πέρασαν.', 'redirects' ), $res['ok'], $res['fail'] ) ) );
		foreach ( $res['errors'] as $e ) {
			$msgs[] = self::msg( 'error', $e );
		}
		if ( $res['ok'] > 0 && RDR_Import::conflicts() ) {
			$msgs[] = self::msg( 'info', __( 'Τώρα κλείσε το module ανακατευθύνσεων του άλλου plugin, ώστε να μένει ένα σύστημα.', 'redirects' ) );
		}
		self::redirect( 'rules', $msgs );
	}

	private static function do_save_settings(): void {
		$s             = RDR_Settings::get();
		$s['mode']     = sanitize_key( self::post( 'rdr_mode' ) );
		$s['staged']   = '1' === self::post( 'rdr_staged' );
		$s['query']    = '1' === self::post( 'rdr_query' );
		$s['log']      = '1' === self::post( 'rdr_log' );
		$s['bots']     = '1' === self::post( 'rdr_bots' );
		$s['keep']     = '1' === self::post( 'rdr_keep' );
		$s['months']   = (int) self::post( 'rdr_months' );
		$s['max_mb']   = (int) self::post( 'rdr_max_mb' );
		$s['per_min']  = (int) self::post( 'rdr_per_min' );
		$pat           = trim( sanitize_textarea_field( self::post( 'rdr_patterns' ) ) );
		$s['patterns'] = '' === $pat ? RDR_Settings::default_patterns() : $pat;
		RDR_Settings::save( $s );
		self::redirect( 'settings', array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'redirects' ) ) ) );
	}

	/* =====================================================================
	 * Export (GET) and JSON restore
	 * =================================================================== */

	public static function route_export(): void {
		$kind = self::q( 'rdr_export' );
		if ( self::SLUG !== self::current_page() || '' === $kind ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_EXP ) || ! current_user_can( Redirects::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'redirects' ) );
		}
		nocache_headers();
		if ( 'csv' === $kind ) {
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="redirects-' . gmdate( 'Y-m-d' ) . '.csv"' );
			$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for spreadsheets.
			foreach ( RDR_Import::csv_rows() as $row ) {
				fputcsv( $out, $row, ',', '"', '\\' );
			}
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			exit;
		}
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="redirects-backup-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( RDR_Import::backup(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	/** Strict restore: settings through sanitize(), rules through save(). */
	private static function do_import_json(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['rdr_json']['error'] ) ? (int) $_FILES['rdr_json']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['rdr_json']['size'] ) ? (int) $_FILES['rdr_json']['size'] : 0;
		$tmp  = isset( $_FILES['rdr_json']['tmp_name'] ) ? (string) $_FILES['rdr_json']['tmp_name'] : '';
		// phpcs:enable
		if ( $size > self::MAX_JSON ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'redirects' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο.', 'redirects' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $tmp ), true );
		if ( ! is_array( $state ) || ! isset( $state['plugin'] ) || 'redirects' !== $state['plugin'] || ! isset( $state['options'][ RDR_Settings::OPT ] ) || ! is_array( $state['options'][ RDR_Settings::OPT ] ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Redirects).', 'redirects' ) ) );
		}
		RDR_Settings::save( $state['options'][ RDR_Settings::OPT ] );
		if ( isset( $state['options']['rdr_ignored'] ) && is_array( $state['options']['rdr_ignored'] ) ) {
			$ign = array();
			foreach ( $state['options']['rdr_ignored'] as $p ) {
				$n = is_string( $p ) ? RDR_Path::norm( $p ) : null;
				if ( null !== $n && '' !== $n ) {
					$ign[] = $n;
				}
			}
			update_option( self::IGNORED, array_slice( array_values( array_unique( array_merge( self::ignored(), $ign ) ) ), 0, self::MAX_IGNORED ), false );
		}
		$ok   = 0;
		$fail = 0;
		foreach ( isset( $state['rules'] ) && is_array( $state['rules'] ) ? array_slice( $state['rules'], 0, RDR_Import::MAX_ROWS ) : array() as $r ) {
			if ( ! is_array( $r ) || ! isset( $r['from'] ) ) {
				++$fail;
				continue;
			}
			$res = RDR_Rules::save(
				array(
					'src'    => (string) $r['from'],
					'match'  => isset( $r['match'] ) ? (string) $r['match'] : 'exact',
					'target' => isset( $r['to'] ) ? (string) $r['to'] : '',
					'code'   => isset( $r['code'] ) ? (int) $r['code'] : 301,
					'staged' => ! empty( $r['staged'] ),
					'active' => ! isset( $r['active'] ) || (bool) $r['active'],
					'note'   => isset( $r['note'] ) ? (string) $r['note'] : '',
				)
			);
			is_wp_error( $res ) ? ++$fail : ++$ok;
		}
		/* translators: 1: rules added, 2: rules skipped */
		return array( self::msg( 'success', sprintf( __( 'Οι ρυθμίσεις επανήλθαν. Κανόνες: %1$d προστέθηκαν, %2$d παραλείφθηκαν (υπήρχαν ή δεν περνούν τους ελέγχους).', 'redirects' ), $ok, $fail ) ) );
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
			set_transient( 'rdr_aui_msg_' . get_current_user_id(), $msgs, 60 );
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
		$raw = get_transient( 'rdr_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'rdr_aui_msg_' . get_current_user_id() );
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
		<p class="rdr-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'redirects' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="rdr-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="rdr-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'redirects' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="rdr-footer-cta">
			<a class="rdr-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'redirects' ); ?>
			</a>
			<a class="rdr-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'redirects' ); ?>
			</a>
		</p>
		<?php
	}
}

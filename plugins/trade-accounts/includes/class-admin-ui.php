<?php
/**
 * TRA_Admin_UI — menu, modules, settings, accounts, API, tools (Bible
 * §3–§8, §11).
 *
 * Submenu «Trade Accounts» of the shared "Noxpress" menu (admin_menu
 * priority 260), slug tra-settings, capability Trade_Accounts::cap(). Tabs:
 *  - modules   module switches, mode (test / live / off), visitor language
 *  - settings  discount, price base, eligible categories and brands,
 *              physical only, price label, invoice rule, visitor texts
 *  - accounts  trade accounts: new account, own discount, API switch,
 *              feed password (shown once), revoke, back to customer
 *  - api       how a bookstore connects, rate limit, contributor
 *              attributes, a test call, the call log
 *  - tools     settings backup, developers
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient tra_aui_msg_{uid}). A new feed password goes
 * through the transient tra_pw_{uid} (60 s, deleted when shown). Export:
 * privileged GET.
 */

defined( 'ABSPATH' ) || exit;

final class TRA_Admin_UI {

	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'tra-settings';
	const NONCE       = 'tra_admin';
	const NONCE_FIELD = 'tra_nonce';
	const NONCE_BAK   = 'tra_backup';
	const MAX_IMPORT  = 262144;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 260 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_export' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TRA_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/** Tab slug => label. */
	private static function tabs(): array {
		return array(
			'modules'  => __( 'Modules', 'trade-accounts' ),
			'settings' => __( 'Ρυθμίσεις', 'trade-accounts' ),
			'accounts' => __( 'Λογαριασμοί', 'trade-accounts' ),
			'api'      => __( 'Trade Feed', 'trade-accounts' ),
			'tools'    => __( 'Εργαλεία', 'trade-accounts' ),
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
			__( 'Trade Accounts', 'trade-accounts' ),
			__( 'Trade Accounts', 'trade-accounts' ),
			Trade_Accounts::cap(),
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Ρυθμίσεις', 'trade-accounts' ) . '</a>'
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
		return isset( self::tabs()[ $tab ] ) ? $tab : 'modules';
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'tra-admin', TRA_URL . 'assets/admin.css', array(), TRA_VERSION );
	}

	/** Suggested text for the site's privacy policy (Settings → Privacy). */
	public static function privacy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'Για τους λογαριασμούς χονδρικής (βιβλιοπωλεία και μεταπωλητές) κρατάμε την έκπτωση του λογαριασμού και, όταν ο λογαριασμός χρησιμοποιεί το Trade Feed, πότε έγινε κάθε κλήση και τι ζητήθηκε (τελευταίες 200 κλήσεις, χωρίς διεύθυνση IP).', 'trade-accounts' ) . '</p>';
		wp_add_privacy_policy_content( 'Trade Accounts', wp_kses_post( $text ) );
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Trade_Accounts::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'trade-accounts' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap tra-wrap">
			<h1><?php esc_html_e( 'Trade Accounts', 'trade-accounts' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Λογαριασμοί χονδρικής για βιβλιοπωλεία και μεταπωλητές: τιμές χονδρικής στο κατάστημα και κατάλογος (API) για τα δικά τους e-shops. Κάθε module ενεργοποιείται χωριστά· όσα είναι κλειστά δεν φορτώνουν τίποτα.', 'trade-accounts' ); ?></p>

			<?php
			self::print_notices( self::take_msgs() );
			if ( Trade_Accounts::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το TRA_DISABLE είναι ενεργό στο wp-config.php: καμία τιμή χονδρικής και το Trade Feed απαντά 403.', 'trade-accounts' ) . '</p></div>';
			}
			?>

			<nav class="nav-tab-wrapper tra-tabs">
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
				case 'settings':
					self::render_settings();
					break;
				case 'accounts':
					self::render_accounts();
					break;
				case 'api':
					self::render_api();
					break;
				case 'tools':
					self::render_tools();
					break;
				default:
					self::render_modules();
			}
			self::footer();
			?>
		</div>
		<?php
	}

	private static function checkbox( string $name, bool $on, string $label ): void {
		printf(
			'<label class="tra-check"><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label>',
			esc_attr( $name ),
			checked( $on, true, false ),
			esc_html( $label )
		);
	}

	private static function radio( string $name, string $value, string $current, string $label ): void {
		printf(
			'<label class="tra-check"><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $value, $current, false ),
			esc_html( $label )
		);
	}

	private static function badge( string $text, string $kind ): string {
		return '<span class="tra-badge tra-badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function on_off( bool $on ): string {
		return $on ? self::badge( __( 'Ενεργό', 'trade-accounts' ), 'ok' ) : self::badge( __( 'Κλειστό', 'trade-accounts' ), 'muted' );
	}

	private static function fmt(): string {
		return get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	}

	/* ---------------------------------------------------------------------
	 * Modules
	 * ------------------------------------------------------------------- */

	private static function render_modules(): void {
		$s = TRA_Settings::get();
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="tra_action" value="save_modules" />

			<h2 class="tra-h2"><?php esc_html_e( 'Modules', 'trade-accounts' ); ?></h2>
			<div class="tra-mods">
				<div class="tra-mod">
					<h3><?php esc_html_e( 'Τιμές χονδρικής', 'trade-accounts' ); ?> <?php echo self::on_off( $s['mods']['pricing'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></h3>
					<p><?php esc_html_e( 'Οι λογαριασμοί χονδρικής βλέπουν και αγοράζουν τα επιλέξιμα προϊόντα με την έκπτωσή τους, στο κατάστημα, στο καλάθι και στην παραγγελία. Πληρωμές, μεταφορικά, ΦΠΑ και Revenue Splitter δουλεύουν όπως στη λιανική.', 'trade-accounts' ); ?></p>
					<?php self::checkbox( 'tra_mod_pricing', $s['mods']['pricing'], __( 'Ενεργοποίηση', 'trade-accounts' ) ); ?>
				</div>
				<div class="tra-mod">
					<h3><?php esc_html_e( 'Trade Feed', 'trade-accounts' ); ?> <?php echo self::on_off( $s['mods']['feed'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in badge(). ?></h3>
					<p><?php esc_html_e( 'API για τα e-shops των λογαριασμών: τα επιλέξιμα προϊόντα με τιμές λιανικής και χονδρικής, διαθεσιμότητα και προπαραγγελίες, σε JSON ή XML, με όνομα χρήστη και κωδικό ανά λογαριασμό.', 'trade-accounts' ); ?></p>
					<?php self::checkbox( 'tra_mod_feed', $s['mods']['feed'], __( 'Ενεργοποίηση', 'trade-accounts' ) ); ?>
				</div>
			</div>

			<h2 class="tra-h2"><?php esc_html_e( 'Λειτουργία', 'trade-accounts' ); ?></h2>
			<div class="tra-zone">
				<?php
				self::radio( 'tra_mode', 'test', $s['mode'], __( 'Δοκιμή: μόνο οι διαχειριστές βλέπουν τιμές χονδρικής (ως προεπισκόπηση)', 'trade-accounts' ) );
				self::radio( 'tra_mode', 'live', $s['mode'], __( 'Ενεργό: οι λογαριασμοί χονδρικής βλέπουν τις τιμές τους', 'trade-accounts' ) );
				self::radio( 'tra_mode', 'off', $s['mode'], __( 'Ανενεργό: καμία τιμή χονδρικής στο κατάστημα (το Trade Feed δεν επηρεάζεται)', 'trade-accounts' ) );
				?>
			</div>

			<h2 class="tra-h2"><?php esc_html_e( 'Γλώσσα των κειμένων για τους επισκέπτες', 'trade-accounts' ); ?></h2>
			<div class="tra-zone">
				<?php
				self::radio( 'tra_lang', 'auto', $s['lang'], __( 'Αυτόματα, από τη γλώσσα του site', 'trade-accounts' ) );
				self::radio( 'tra_lang', 'el', $s['lang'], __( 'Ελληνικά', 'trade-accounts' ) );
				self::radio( 'tra_lang', 'en', $s['lang'], __( 'English', 'trade-accounts' ) );
				?>
			</div>

			<p class="tra-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'trade-accounts' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	/** Terms of a taxonomy in tree order: array of array( term, depth ). */
	private static function tree( string $tax ): array {
		if ( ! taxonomy_exists( $tax ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $tax,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$by = array();
		foreach ( $terms as $t ) {
			$by[ (int) $t->parent ][] = $t;
		}
		$out  = array();
		$walk = static function ( int $parent, int $depth ) use ( &$walk, &$out, $by ) {
			if ( empty( $by[ $parent ] ) || $depth > 10 ) {
				return;
			}
			foreach ( $by[ $parent ] as $t ) {
				$out[] = array( $t, $depth );
				$walk( (int) $t->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return $out;
	}

	private static function term_boxes( string $name, string $tax, array $chosen ): void {
		$tree = self::tree( $tax );
		echo '<div class="tra-scroll">';
		foreach ( $tree as $row ) {
			list( $t, $depth ) = $row;
			printf(
				'<label class="tra-block" style="padding-left:%1$dpx"><input type="checkbox" name="%2$s[]" value="%3$d"%4$s /> %5$s <span class="tra-muted">(%6$d)</span></label>',
				(int) $depth * 18,
				esc_attr( $name ),
				(int) $t->term_id,
				checked( in_array( (int) $t->term_id, $chosen, true ), true, false ),
				esc_html( $t->name ),
				(int) $t->count
			);
		}
		echo '</div>';
	}

	/** One visitor text: Greek and English inputs. */
	private static function text_pair( string $key, string $label, array $s ): void {
		$max = TRA_Settings::texts()[ $key ][2];
		?>
		<span class="tra-label"><?php echo esc_html( $label ); ?></span>
		<div class="tra-pair">
			<label><span class="tra-lang"><?php esc_html_e( 'Ελληνικά', 'trade-accounts' ); ?></span>
				<input type="text" class="tra-wide" name="tra_t_<?php echo esc_attr( $key ); ?>_el" value="<?php echo esc_attr( $s[ 't_' . $key . '_el' ] ); ?>" maxlength="<?php echo (int) $max; ?>" /></label>
			<label><span class="tra-lang"><?php esc_html_e( 'English (κενό = το ελληνικό)', 'trade-accounts' ); ?></span>
				<input type="text" class="tra-wide" name="tra_t_<?php echo esc_attr( $key ); ?>_en" value="<?php echo esc_attr( $s[ 't_' . $key . '_en' ] ); ?>" maxlength="<?php echo (int) $max; ?>" /></label>
		</div>
		<?php
	}

	private static function render_settings(): void {
		$s = TRA_Settings::get();
		if ( empty( $s['cats'] ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Δεν έχει επιλεγεί καμία κατηγορία: κανένα προϊόν δεν είναι επιλέξιμο ακόμα.', 'trade-accounts' ) . '</p></div>';
		}
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="tra_action" value="save_settings" />

			<h2 class="tra-h2"><?php esc_html_e( 'Έκπτωση', 'trade-accounts' ); ?></h2>
			<div class="tra-zone">
				<span class="tra-label"><?php esc_html_e( 'Έκπτωση κάθε λογαριασμού χονδρικής (%)', 'trade-accounts' ); ?></span>
				<input type="number" name="tra_discount" value="<?php echo esc_attr( (string) $s['discount'] ); ?>" min="0" max="90" step="0.01" />
				<p class="description"><?php esc_html_e( 'Ένας λογαριασμός μπορεί να έχει και δική του έκπτωση (καρτέλα Λογαριασμοί).', 'trade-accounts' ); ?></p>

				<span class="tra-label"><?php esc_html_e( 'Η έκπτωση υπολογίζεται', 'trade-accounts' ); ?></span>
				<?php
				self::radio( 'tra_base', 'regular', $s['base'], __( 'Πάνω στην κανονική τιμή λιανικής, χωρίς να μετρά καμία προσφορά', 'trade-accounts' ) );
				self::radio( 'tra_base', 'active', $s['base'], __( 'Πάνω στην τιμή που δείχνει το κατάστημα τώρα (και πάνω στην τιμή προσφοράς)', 'trade-accounts' ) );
				?>
			</div>

			<h2 class="tra-h2"><?php esc_html_e( 'Επιλέξιμα προϊόντα', 'trade-accounts' ); ?></h2>
			<div class="tra-zone">
				<p class="tra-mt-8">
					<?php
					/* translators: %d: number of products */
					echo esc_html( sprintf( __( 'Επιλέξιμα τώρα: %d προϊόντα.', 'trade-accounts' ), TRA_Scope::count() ) );
					?>
				</p>
				<span class="tra-label"><?php esc_html_e( 'Κατηγορίες (μαζί με τις υποκατηγορίες τους)', 'trade-accounts' ); ?></span>
				<?php self::term_boxes( 'tra_cats', 'product_cat', $s['cats'] ); ?>

				<?php if ( taxonomy_exists( 'product_brand' ) ) : ?>
					<span class="tra-label"><?php esc_html_e( 'Brands (κανένα = όλα)', 'trade-accounts' ); ?></span>
					<?php self::term_boxes( 'tra_brands', 'product_brand', $s['brands'] ); ?>
					<p class="description"><?php esc_html_e( 'Ένα προϊόν είναι επιλέξιμο όταν είναι σε μία από τις κατηγορίες ΚΑΙ σε ένα από τα brands.', 'trade-accounts' ); ?></p>
				<?php endif; ?>

				<span class="tra-label"><?php esc_html_e( 'Περιορισμοί', 'trade-accounts' ); ?></span>
				<?php self::checkbox( 'tra_physical', $s['physical'], __( 'Μόνο φυσικά προϊόντα (όχι εικονικά ή για λήψη)', 'trade-accounts' ) ); ?>
				<p class="description"><?php esc_html_e( 'Πάντα εκτός: τίτλοι άλλων εκδοτών από το Book Feed, εξωτερικά και ομαδικά προϊόντα, και όσα έχουν τσεκαρισμένο το «Εκτός χονδρικής» στη σελίδα του προϊόντος.', 'trade-accounts' ); ?></p>
			</div>

			<h2 class="tra-h2"><?php esc_html_e( 'Κατάστημα', 'trade-accounts' ); ?></h2>
			<div class="tra-zone">
				<?php self::checkbox( 'tra_label', $s['label'], __( 'Ένδειξη δίπλα στην τιμή χονδρικής', 'trade-accounts' ) ); ?>
				<?php self::text_pair( 'label', __( 'Κείμενο της ένδειξης', 'trade-accounts' ), $s ); ?>
			</div>

			<h2 class="tra-h2"><?php esc_html_e( 'Οδηγός χονδρικής', 'trade-accounts' ); ?></h2>
			<div class="tra-zone">
				<span class="tra-label"><?php esc_html_e( 'Σελίδα του οδηγού', 'trade-accounts' ); ?></span>
				<?php
				wp_dropdown_pages(
					array(
						'name'              => 'tra_guide',
						'selected'          => (int) $s['guide'],
						'show_option_none'  => __( '— Καμία —', 'trade-accounts' ),
						'option_none_value' => '0',
						'post_status'       => array( 'publish', 'draft', 'private' ),
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Όταν είναι δημοσιευμένη, οι λογαριασμοί χονδρικής τη βλέπουν στο μενού και στον πίνακα του «Ο λογαριασμός μου», και το email του Trade Feed τη συνδέει.', 'trade-accounts' ); ?></p>
				<?php self::text_pair( 'guide', __( 'Όνομα στο μενού', 'trade-accounts' ), $s ); ?>
				<?php self::text_pair( 'guide_note', __( 'Γραμμή στον πίνακα του λογαριασμού', 'trade-accounts' ), $s ); ?>
			</div>

			<h2 class="tra-h2"><?php esc_html_e( 'Τιμολόγιο', 'trade-accounts' ); ?></h2>
			<div class="tra-zone">
				<?php self::checkbox( 'tra_invoice', $s['invoice'], __( 'Οι παραγγελίες χονδρικής θέλουν τιμολόγιο (κλασικό ταμείο)', 'trade-accounts' ) ); ?>
				<div class="tra-grid tra-mt-8">
					<label><span class="tra-label"><?php esc_html_e( 'Πεδίο του ταμείου', 'trade-accounts' ); ?></span>
						<input type="text" name="tra_inv_field" value="<?php echo esc_attr( $s['inv_field'] ); ?>" maxlength="64" spellcheck="false" /></label>
					<label><span class="tra-label"><?php esc_html_e( 'Τιμή για «Τιμολόγιο»', 'trade-accounts' ); ?></span>
						<input type="text" name="tra_inv_value" value="<?php echo esc_attr( $s['inv_value'] ); ?>" maxlength="64" spellcheck="false" /></label>
				</div>
				<p class="description"><?php esc_html_e( 'Προεπιλογή: το πεδίο τύπου παραστατικού του «Timologia for WooCommerce» (OBS). Το ταμείο προεπιλέγει το τιμολόγιο για τον λογαριασμό χονδρικής.', 'trade-accounts' ); ?></p>
				<?php self::text_pair( 'invoice', __( 'Μήνυμα όταν δεν επιλεγεί τιμολόγιο', 'trade-accounts' ), $s ); ?>
			</div>

			<h2 class="tra-h2"><?php esc_html_e( 'Κουπόνια', 'trade-accounts' ); ?></h2>
			<div class="tra-zone">
				<?php self::checkbox( 'tra_no_coupons', $s['no_coupons'], __( 'Κανένα κουπόνι έκπτωσης σε καλάθι με τιμές χονδρικής', 'trade-accounts' ) ); ?>
				<?php self::text_pair( 'coupon', __( 'Μήνυμα όταν δοκιμάζει κουπόνι', 'trade-accounts' ), $s ); ?>
				<span class="tra-label"><?php esc_html_e( 'Κουπόνι επιβράβευσης', 'trade-accounts' ); ?></span>
				<?php self::checkbox( 'tra_no_reward', $s['no_reward'], __( 'Κανένα κουπόνι ευχαριστίας μετά από παραγγελία χονδρικής', 'trade-accounts' ) ); ?>
				<p class="description"><?php esc_html_e( 'Ισχύει για το κουπόνι της σελίδας ευχαριστίας του Thanko Thank You Page Customizer και του Checkout Extras: σε παραγγελία χονδρικής δεν δημιουργείται, δεν φαίνεται και δεν στέλνεται με email.', 'trade-accounts' ); ?></p>
			</div>

			<p class="tra-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'trade-accounts' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Accounts
	 * ------------------------------------------------------------------- */

	/** The new feed password of this admin, once (then it is gone). */
	private static function take_password(): array {
		$key = 'tra_pw_' . get_current_user_id();
		$raw = get_transient( $key );
		delete_transient( $key );
		return is_array( $raw ) && isset( $raw['u'], $raw['p'] ) ? $raw : array();
	}

	private static function render_accounts(): void {
		$s = TRA_Settings::get();
		if ( ! $s['mods']['pricing'] && ! $s['mods']['feed'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Και τα δύο modules είναι κλειστά: οι λογαριασμοί υπάρχουν, αλλά δεν βλέπουν τιμές χονδρικής ούτε το Trade Feed μέχρι να ενεργοποιήσεις ένα module.', 'trade-accounts' ) . '</p></div>';
		} elseif ( $s['mods']['pricing'] && 'live' !== $s['mode'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Οι τιμές χονδρικής δεν είναι ενεργές για τους λογαριασμούς (λειτουργία δοκιμής ή ανενεργό, καρτέλα Modules).', 'trade-accounts' ) . '</p></div>';
		}

		$pw = self::take_password();
		if ( $pw ) {
			$u = get_userdata( (int) $pw['u'] );
			?>
			<div class="notice notice-warning inline tra-secret">
				<p><strong><?php esc_html_e( 'Νέος κωδικός Trade Feed. Φαίνεται μόνο αυτή τη φορά: αντέγραψέ τον τώρα και στείλ’ τον στο βιβλιοπωλείο με ασφαλή τρόπο.', 'trade-accounts' ); ?></strong></p>
				<p><?php esc_html_e( 'Όνομα χρήστη', 'trade-accounts' ); ?>: <code><?php echo esc_html( $u instanceof WP_User ? $u->user_login : '' ); ?></code></p>
				<p><?php esc_html_e( 'Κωδικός', 'trade-accounts' ); ?>: <code class="tra-key"><?php echo esc_html( (string) $pw['p'] ); ?></code></p>
				<p><?php esc_html_e( 'Διεύθυνση', 'trade-accounts' ); ?>: <code><?php echo esc_html( rest_url( 'tra/v1/products' ) ); ?></code></p>
			</div>
			<?php
		}

		$accounts = TRA_Accounts::all();
		$pw_ok    = TRA_Accounts::passwords_available();
		$fmt      = self::fmt();
		?>
		<h2 class="tra-h2"><?php esc_html_e( 'Λογαριασμοί χονδρικής', 'trade-accounts' ); ?></h2>
		<?php if ( ! $pw_ok ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Τα Application Passwords δεν είναι διαθέσιμα σε αυτό το site (χρειάζονται HTTPS και να μην τα έχει κλείσει κάποιο plugin ασφαλείας).', 'trade-accounts' ); ?></p></div>
		<?php endif; ?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="tra_action" value="accounts" />
			<table class="widefat tra-table">
				<thead><tr>
					<th><?php esc_html_e( 'Λογαριασμός', 'trade-accounts' ); ?></th>
					<th><?php esc_html_e( 'Έκπτωση (%)', 'trade-accounts' ); ?></th>
					<th><?php esc_html_e( 'Trade Feed', 'trade-accounts' ); ?></th>
					<th><?php esc_html_e( 'Κωδικός', 'trade-accounts' ); ?></th>
					<th><?php esc_html_e( 'Τελευταία κλήση', 'trade-accounts' ); ?></th>
					<th><?php esc_html_e( 'Ενέργειες', 'trade-accounts' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $accounts ) : ?>
					<tr><td colspan="6" class="tra-empty"><?php esc_html_e( 'Δεν υπάρχουν λογαριασμοί χονδρικής ακόμα.', 'trade-accounts' ); ?></td></tr>
				<?php else : ?>
					<?php
					foreach ( $accounts as $u ) {
						$uid  = (int) $u->ID;
						$own  = (string) get_user_meta( $uid, 'tra_discount', true );
						$pws  = TRA_Accounts::passwords( $uid );
						$seen = TRA_Accounts::seen( $uid );
						?>
						<tr>
							<td>
								<a href="<?php echo esc_url( get_edit_user_link( $uid ) ); ?>"><strong><?php echo esc_html( $u->display_name ); ?></strong></a><br />
								<span class="tra-muted"><?php echo esc_html( $u->user_login . ' · ' . $u->user_email ); ?></span>
							</td>
							<td>
								<input type="text" name="tra_disc[<?php echo (int) $uid; ?>]" value="<?php echo esc_attr( $own ); ?>" size="5" placeholder="<?php echo esc_attr( (string) $s['discount'] ); ?>" />
							</td>
							<td>
								<label class="tra-check"><input type="checkbox" name="tra_api[<?php echo (int) $uid; ?>]" value="1"<?php checked( TRA_Accounts::api_on( $uid ) ); ?> /> <?php esc_html_e( 'Ναι', 'trade-accounts' ); ?></label>
							</td>
							<td>
								<?php
								if ( $pws ) {
									$p = $pws[0];
									/* translators: %s: date */
									echo esc_html( sprintf( __( 'από %s', 'trade-accounts' ), wp_date( get_option( 'date_format' ), $p['created'] ) ) );
									if ( $p['last_used'] ) {
										echo '<br /><span class="tra-muted">';
										/* translators: %s: date and time */
										echo esc_html( sprintf( __( 'χρήση %s', 'trade-accounts' ), wp_date( $fmt, $p['last_used'] ) ) );
										echo '</span>';
									}
								} else {
									echo '<span class="tra-muted">' . esc_html__( 'κανένας', 'trade-accounts' ) . '</span>';
								}
								?>
							</td>
							<td>
								<?php
								if ( $seen ) {
									echo esc_html( wp_date( $fmt, (int) $seen['t'] ) );
									echo '<br /><span class="tra-muted">' . esc_html( (string) $seen['s'] . ' · ' . $seen['r'] ) . '</span>';
								} else {
									echo '<span class="tra-muted">—</span>';
								}
								?>
							</td>
							<td>
								<button type="submit" name="tra_do" value="save:<?php echo (int) $uid; ?>" class="button"><?php esc_html_e( 'Αποθήκευση', 'trade-accounts' ); ?></button>
								<button type="submit" name="tra_do" value="pw:<?php echo (int) $uid; ?>" class="button"<?php disabled( ! $pw_ok ); ?> onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Νέος κωδικός: ο παλιός σταματά να ισχύει αμέσως. Συνέχεια;', 'trade-accounts' ) ) ); ?>);"><?php esc_html_e( 'Νέος κωδικός', 'trade-accounts' ); ?></button>
								<?php if ( $pws ) : ?>
									<button type="submit" name="tra_do" value="revoke:<?php echo (int) $uid; ?>" class="button"><?php esc_html_e( 'Ανάκληση κωδικού', 'trade-accounts' ); ?></button>
								<?php endif; ?>
								<button type="submit" name="tra_do" value="remove:<?php echo (int) $uid; ?>" class="button" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Ο λογαριασμός γίνεται απλός πελάτης: χωρίς τιμές χονδρικής και χωρίς Trade Feed. Οι παραγγελίες του μένουν. Συνέχεια;', 'trade-accounts' ) ) ); ?>);"><?php esc_html_e( 'Αφαίρεση', 'trade-accounts' ); ?></button>
							</td>
						</tr>
						<?php
					}
					?>
				<?php endif; ?>
				</tbody>
			</table>
			<p class="description tra-mt-8"><?php esc_html_e( 'Κενή έκπτωση = η έκπτωση των ρυθμίσεων. Ένας υπάρχων πελάτης γίνεται λογαριασμός χονδρικής και από τους Χρήστες, με τον ρόλο «Χονδρική».', 'trade-accounts' ); ?></p>
		</form>

		<h2 class="tra-h2"><?php esc_html_e( 'Νέος λογαριασμός', 'trade-accounts' ); ?></h2>
		<div class="tra-zone">
			<form method="post">
				<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
				<input type="hidden" name="tra_action" value="create" />
				<div class="tra-grid">
					<label><span class="tra-label"><?php esc_html_e( 'Επωνυμία', 'trade-accounts' ); ?></span>
						<input type="text" name="tra_company" maxlength="120" /></label>
					<label><span class="tra-label"><?php esc_html_e( 'Email', 'trade-accounts' ); ?></span>
						<input type="email" name="tra_email" maxlength="100" required /></label>
					<label><span class="tra-label"><?php esc_html_e( 'Όνομα', 'trade-accounts' ); ?></span>
						<input type="text" name="tra_first" maxlength="60" /></label>
					<label><span class="tra-label"><?php esc_html_e( 'Επώνυμο', 'trade-accounts' ); ?></span>
						<input type="text" name="tra_last" maxlength="60" /></label>
					<label><span class="tra-label"><?php esc_html_e( 'Δική του έκπτωση (%, κενό = των ρυθμίσεων)', 'trade-accounts' ); ?></span>
						<input type="text" name="tra_new_disc" size="5" placeholder="<?php echo esc_attr( (string) $s['discount'] ); ?>" /></label>
				</div>
				<?php
				self::checkbox( 'tra_new_api', true, __( 'Πρόσβαση στο Trade Feed', 'trade-accounts' ) );
				self::checkbox( 'tra_notify', true, __( 'Email με σύνδεσμο για να ορίσει κωδικό σύνδεσης στο site (μόνο για νέο χρήστη)', 'trade-accounts' ) );
				?>
				<p class="description"><?php esc_html_e( 'Αν το email ανήκει ήδη σε πελάτη, ο πελάτης γίνεται λογαριασμός χονδρικής. Λογαριασμοί προσωπικού δεν αλλάζουν ποτέ.', 'trade-accounts' ); ?></p>
				<p class="tra-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Δημιουργία λογαριασμού', 'trade-accounts' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Trade Feed
	 * ------------------------------------------------------------------- */

	private static function render_api(): void {
		$s = TRA_Settings::get();
		if ( ! $s['mods']['feed'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Το module Trade Feed είναι κλειστό: το API δεν απαντά μέχρι να το ενεργοποιήσεις στα Modules.', 'trade-accounts' ) . '</p></div>';
		}
		$base = rest_url( 'tra/v1/' );
		?>
		<h2 class="tra-h2"><?php esc_html_e( 'Πώς συνδέεται ένα βιβλιοπωλείο', 'trade-accounts' ); ?></h2>
		<div class="tra-zone">
			<ol>
				<li><?php esc_html_e( 'Φτιάχνεις λογαριασμό χονδρικής με πρόσβαση στο Trade Feed και πατάς «Νέος κωδικός» (καρτέλα Λογαριασμοί).', 'trade-accounts' ); ?></li>
				<li><?php esc_html_e( 'Στέλνεις στο βιβλιοπωλείο το όνομα χρήστη, τον κωδικό και τη διεύθυνση του API.', 'trade-accounts' ); ?></li>
				<li><?php esc_html_e( 'Το e-shop του καλεί το API με HTTP Basic (όνομα χρήστη και κωδικός), πάντα με HTTPS, σε JSON ή XML.', 'trade-accounts' ); ?></li>
			</ol>
			<p class="description"><?php esc_html_e( 'Βιβλιοπωλείο με WooCommerce: το δωρεάν plugin Trade Feed Import κάνει όλη τη σύνδεση με επικόλληση του email του κωδικού, φέρνει τους τίτλους και ενημερώνει τιμές και διαθεσιμότητα κάθε μέρα.', 'trade-accounts' ); ?> <a href="https://noxpress.tech/#trade-feed-import" target="_blank" rel="noopener noreferrer">noxpress.tech</a></p>
			<table class="widefat striped tra-table">
				<tbody>
					<tr><td><code>GET <?php echo esc_html( $base ); ?>account</code></td><td><?php esc_html_e( 'Ο λογαριασμός: έκπτωση, νόμισμα, όριο κλήσεων.', 'trade-accounts' ); ?></td></tr>
					<tr><td><code>GET <?php echo esc_html( $base ); ?>products</code></td><td><?php esc_html_e( 'Τα επιλέξιμα προϊόντα, 50 ανά σελίδα (έως 100), με τιμές λιανικής και χονδρικής, διαθεσιμότητα, προπαραγγελία και ημερομηνία κυκλοφορίας.', 'trade-accounts' ); ?></td></tr>
					<tr><td><code>GET <?php echo esc_html( $base ); ?>products/&lt;isbn&gt;</code></td><td><?php esc_html_e( 'Ένα προϊόν με ISBN, ISSN, GTIN ή SKU.', 'trade-accounts' ); ?></td></tr>
					<tr><td><code>GET <?php echo esc_html( $base ); ?>categories</code></td><td><?php esc_html_e( 'Οι επιλέξιμες κατηγορίες.', 'trade-accounts' ); ?></td></tr>
				</tbody>
			</table>
			<span class="tra-label"><?php esc_html_e( 'Φίλτρα του /products', 'trade-accounts' ); ?></span>
			<p class="description"><code>category</code> <code>isbn</code> <code>author</code> <code>attribute</code> + <code>attribute_term</code> <code>availability</code> (instock, outofstock, onbackorder, preorder) <code>modified_since</code> <code>search</code> <code>orderby</code> (modified, date, title, id) <code>order</code> <code>fields</code> <code>page</code> <code>per_page</code> <code>output</code> (json, xml)</p>
			<span class="tra-label"><?php esc_html_e( 'Παράδειγμα', 'trade-accounts' ); ?></span>
			<code class="tra-wide tra-block">curl -u 'USERNAME:XXXX XXXX XXXX XXXX XXXX XXXX' '<?php echo esc_html( $base ); ?>products?modified_since=2026-01-01&amp;per_page=100'</code>
			<code class="tra-wide tra-block">curl -u 'USERNAME:XXXX XXXX XXXX XXXX XXXX XXXX' '<?php echo esc_html( $base ); ?>products?availability=preorder&amp;output=xml'</code>
			<p class="description tra-mt-8"><?php esc_html_e( 'Οι τιμές είναι κείμενα με τα δεκαδικά του καταστήματος («13.00»). Κάθε απάντηση έχει τα headers X-RateLimit-Limit, X-RateLimit-Remaining και X-RateLimit-Reset· πάνω από το όριο έρχεται 429 με Retry-After.', 'trade-accounts' ); ?></p>
			<?php if ( $s['mods']['feed'] ) : ?>
				<p class="tra-mt-8"><a class="button" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( add_query_arg( array( 'per_page' => 5, '_wpnonce' => wp_create_nonce( 'wp_rest' ) ), rest_url( 'tra/v1/products' ) ) ); ?>"><?php esc_html_e( 'Δοκιμή (ως διαχειριστής, 5 προϊόντα)', 'trade-accounts' ); ?></a></p>
			<?php endif; ?>
		</div>

		<form method="post">
			<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
			<input type="hidden" name="tra_action" value="save_api" />

			<h2 class="tra-h2"><?php esc_html_e( 'Ρυθμίσεις του Trade Feed', 'trade-accounts' ); ?></h2>
			<div class="tra-zone">
				<span class="tra-label"><?php esc_html_e( 'Κλήσεις ανά ώρα και λογαριασμό', 'trade-accounts' ); ?></span>
				<input type="number" name="tra_rate" value="<?php echo (int) $s['rate']; ?>" min="10" max="2000" step="1" />

				<span class="tra-label"><?php esc_html_e( 'Ιδιότητες με συντελεστές (συγγραφείς, εικονογράφοι, μεταφραστές…)', 'trade-accounts' ); ?></span>
				<div class="tra-scroll">
					<?php
					foreach ( wc_get_attribute_taxonomies() as $a ) {
						$tax = wc_attribute_taxonomy_name( $a->attribute_name );
						printf(
							'<label class="tra-block"><input type="checkbox" name="tra_contrib[]" value="%1$s"%2$s /> %3$s <code>%4$s</code></label>',
							esc_attr( $tax ),
							checked( in_array( $tax, $s['contrib'], true ), true, false ),
							esc_html( $a->attribute_label ),
							esc_html( $tax )
						);
					}
					?>
				</div>
				<p class="description"><?php esc_html_e( 'Στο Trade Feed βγαίνουν ως «contributors» με τον ρόλο τους, και το φίλτρο author ψάχνει σε αυτές. Οι υπόλοιπες ιδιότητες βγαίνουν στα «attributes».', 'trade-accounts' ); ?></p>
			</div>

			<p class="tra-submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Αποθήκευση ρυθμίσεων', 'trade-accounts' ); ?></button></p>
		</form>

		<h2 class="tra-h2"><?php esc_html_e( 'Τελευταίες κλήσεις', 'trade-accounts' ); ?></h2>
		<?php self::render_log(); ?>
		<?php
	}

	private static function render_log(): void {
		$log   = get_option( 'tra_log', array() );
		$log   = is_array( $log ) ? array_slice( $log, 0, 50 ) : array();
		$fmt   = self::fmt();
		$names = array();
		?>
		<table class="widefat tra-table">
			<thead><tr>
				<th><?php esc_html_e( 'Πότε', 'trade-accounts' ); ?></th>
				<th><?php esc_html_e( 'Λογαριασμός', 'trade-accounts' ); ?></th>
				<th><?php esc_html_e( 'Κλήση', 'trade-accounts' ); ?></th>
				<th><?php esc_html_e( 'Αποτέλεσμα', 'trade-accounts' ); ?></th>
				<th><?php esc_html_e( 'Προϊόντα', 'trade-accounts' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $log ) : ?>
				<tr><td colspan="5" class="tra-empty"><?php esc_html_e( 'Καμία κλήση ακόμα.', 'trade-accounts' ); ?></td></tr>
			<?php else : ?>
				<?php
				foreach ( $log as $l ) {
					if ( ! is_array( $l ) || ! isset( $l['t'], $l['u'], $l['r'], $l['s'], $l['n'] ) ) {
						continue;
					}
					$uid = (int) $l['u'];
					if ( ! isset( $names[ $uid ] ) ) {
						$u             = get_userdata( $uid );
						$names[ $uid ] = $u instanceof WP_User ? $u->display_name : '#' . $uid;
					}
					$code = (int) $l['s'];
					?>
					<tr>
						<td><?php echo esc_html( wp_date( $fmt, (int) $l['t'] ) ); ?></td>
						<td><?php echo esc_html( $names[ $uid ] ); ?></td>
						<td><code><?php echo esc_html( (string) $l['r'] ); ?></code></td>
						<td class="<?php echo esc_attr( $code < 300 ? '' : 'tra-warn' ); ?>"><?php echo (int) $code; ?></td>
						<td><?php echo (int) $l['n']; ?></td>
					</tr>
					<?php
				}
				?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tools
	 * ------------------------------------------------------------------- */

	private static function render_tools(): void {
		?>
		<h2 class="tra-h2"><?php esc_html_e( 'Backup ρυθμίσεων', 'trade-accounts' ); ?></h2>
		<div class="tra-zone">
			<form method="post" enctype="multipart/form-data" class="tra-inline-form">
				<?php wp_nonce_field( self::NONCE_BAK, 'tra_backup_nonce' ); ?>
				<input type="file" name="tra_import_file" accept=".json,application/json" />
				<button type="submit" name="tra_import" value="1" class="button"><?php esc_html_e( 'Εισαγωγή ρυθμίσεων (JSON)', 'trade-accounts' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=tools&tra_export=1' ), self::NONCE_BAK ) ); ?>"><?php esc_html_e( 'Εξαγωγή ρυθμίσεων (JSON)', 'trade-accounts' ); ?></a>
			<p class="description tra-mt-8"><?php esc_html_e( 'Μόνο οι ρυθμίσεις: οι λογαριασμοί, οι εκπτώσεις τους και οι κωδικοί δεν μπαίνουν στο αρχείο.', 'trade-accounts' ); ?></p>
		</div>

		<h2 class="tra-h2"><?php esc_html_e( 'Ιστορικό κλήσεων', 'trade-accounts' ); ?></h2>
		<div class="tra-zone">
			<form method="post">
				<?php wp_nonce_field( self::NONCE, self::NONCE_FIELD ); ?>
				<input type="hidden" name="tra_action" value="clear_log" />
				<p><?php esc_html_e( 'Κρατά τις τελευταίες 200 κλήσεις του Trade Feed, χωρίς διευθύνσεις IP και χωρίς κωδικούς.', 'trade-accounts' ); ?></p>
				<button type="submit" class="button"><?php esc_html_e( 'Καθαρισμός ιστορικού', 'trade-accounts' ); ?></button>
			</form>
		</div>

		<h2 class="tra-h2"><?php esc_html_e( 'Για developers', 'trade-accounts' ); ?></h2>
		<div class="tra-zone">
			<table class="widefat striped tra-table">
				<tbody>
					<tr><td><code>tra_is_trade( $user_id )</code></td><td><?php esc_html_e( 'Αν ο χρήστης είναι λογαριασμός χονδρικής (0 = ο τρέχων).', 'trade-accounts' ); ?></td></tr>
					<tr><td><code>tra_is_eligible( $product )</code></td><td><?php esc_html_e( 'Αν το προϊόν είναι επιλέξιμο για χονδρική και Trade Feed.', 'trade-accounts' ); ?></td></tr>
					<tr><td><code>tra_trade_price( $product, $user_id )</code></td><td><?php esc_html_e( 'Η τιμή χονδρικής του προϊόντος για τον λογαριασμό, ή null.', 'trade-accounts' ); ?></td></tr>
					<tr><td><code>tra_eligible</code> <?php esc_html_e( '(φίλτρο)', 'trade-accounts' ); ?></td><td><?php esc_html_e( 'Αλλάζει την επιλεξιμότητα: ( $ok, $product ).', 'trade-accounts' ); ?></td></tr>
					<tr><td><code>tra_discount</code> <?php esc_html_e( '(φίλτρο)', 'trade-accounts' ); ?></td><td><?php esc_html_e( 'Αλλάζει την έκπτωση ενός λογαριασμού: ( $percent, $user_id ).', 'trade-accounts' ); ?></td></tr>
					<tr><td><code>tra_feed_item</code> <?php esc_html_e( '(φίλτρο)', 'trade-accounts' ); ?></td><td><?php esc_html_e( 'Αλλάζει ένα προϊόν του Trade Feed: ( $item, $product ).', 'trade-accounts' ); ?></td></tr>
					<tr><td><code>tra_text</code> <?php esc_html_e( '(φίλτρο)', 'trade-accounts' ); ?></td><td><?php esc_html_e( 'Αλλάζει ένα κείμενο του επισκέπτη: ( $text, $key, $lang ).', 'trade-accounts' ); ?></td></tr>
					<tr><td><code>TRA_DISABLE</code></td><td><?php esc_html_e( 'Στο wp-config.php: σταματά τις τιμές χονδρικής και κλείνει το Trade Feed (403).', 'trade-accounts' ); ?></td></tr>
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

		if ( isset( $_POST['tra_import'] ) ) {
			if ( ! isset( $_POST['tra_backup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['tra_backup_nonce'] ) ), self::NONCE_BAK )
				|| ! current_user_can( Trade_Accounts::cap() ) ) {
				wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'trade-accounts' ) );
			}
			self::redirect( 'tools', self::do_import() );
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST['tra_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Trade_Accounts::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'trade-accounts' ) );
		}

		$saved = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'trade-accounts' ) ) );
		switch ( sanitize_key( wp_unslash( $_POST['tra_action'] ) ) ) {
			case 'save_modules':
				$s                    = TRA_Settings::get();
				$s['mods']['pricing'] = '1' === self::post( 'tra_mod_pricing' );
				$s['mods']['feed']    = '1' === self::post( 'tra_mod_feed' );
				$s['mode']            = sanitize_key( self::post( 'tra_mode' ) );
				$s['lang']            = sanitize_key( self::post( 'tra_lang' ) );
				TRA_Settings::save( $s );
				self::redirect( 'modules', $saved );
				break;
			case 'save_settings':
				self::redirect( 'settings', self::do_save_settings() );
				break;
			case 'save_api':
				$s            = TRA_Settings::get();
				$s['rate']    = (int) self::post( 'tra_rate' );
				$s['contrib'] = self::post_list( 'tra_contrib' );
				TRA_Settings::save( $s );
				self::redirect( 'api', $saved );
				break;
			case 'accounts':
				self::redirect( 'accounts', self::do_accounts() );
				break;
			case 'create':
				self::redirect( 'accounts', self::do_create() );
				break;
			case 'clear_log':
				delete_option( 'tra_log' );
				self::redirect( 'tools', array( self::msg( 'success', __( 'Το ιστορικό καθαρίστηκε.', 'trade-accounts' ) ) ) );
				break;
			default:
				self::redirect( 'modules', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'trade-accounts' ) ) ) );
		}
	}

	private static function do_save_settings(): array {
		$s             = TRA_Settings::get();
		$s['discount'] = str_replace( ',', '.', trim( self::post( 'tra_discount' ) ) );
		$s['base']     = sanitize_key( self::post( 'tra_base' ) );
		$s['cats']     = array_map( 'intval', self::post_list( 'tra_cats' ) );
		$s['brands']   = array_map( 'intval', self::post_list( 'tra_brands' ) );
		$s['physical'] = '1' === self::post( 'tra_physical' );
		$s['label']    = '1' === self::post( 'tra_label' );
		$s['invoice']  = '1' === self::post( 'tra_invoice' );
		$s['no_coupons'] = '1' === self::post( 'tra_no_coupons' );
		$s['no_reward']  = '1' === self::post( 'tra_no_reward' );
		$s['inv_field'] = self::post( 'tra_inv_field' );
		$s['inv_value'] = self::post( 'tra_inv_value' );
		$s['guide']     = (int) self::post( 'tra_guide' );
		foreach ( array_keys( TRA_Settings::texts() ) as $k ) {
			foreach ( array( 'el', 'en' ) as $l ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
				if ( isset( $_POST[ 'tra_t_' . $k . '_' . $l ] ) ) {
					$s[ 't_' . $k . '_' . $l ] = self::post( 'tra_t_' . $k . '_' . $l );
				}
			}
		}
		TRA_Settings::save( $s );
		TRA_Scope::forget();
		$msgs = array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'trade-accounts' ) ) );
		if ( empty( TRA_Settings::get()['cats'] ) ) {
			$msgs[] = self::msg( 'warning', __( 'Δεν έχει επιλεγεί καμία κατηγορία: κανένα προϊόν δεν είναι επιλέξιμο ακόμα.', 'trade-accounts' ) );
		}
		return $msgs;
	}

	/** The row buttons of the accounts table: save:ID | pw:ID | revoke:ID | remove:ID. */
	private static function do_accounts(): array {
		$do = self::post( 'tra_do' );
		if ( ! preg_match( '/^(save|pw|revoke|remove):(\d+)$/', $do, $m ) ) {
			return array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'trade-accounts' ) ) );
		}
		$uid = (int) $m[2];
		if ( ! TRA_Accounts::is_trade( $uid ) ) {
			return array( self::msg( 'error', __( 'Ο χρήστης δεν είναι λογαριασμός χονδρικής.', 'trade-accounts' ) ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in route().
		$disc = isset( $_POST['tra_disc'][ $uid ] ) && is_scalar( $_POST['tra_disc'][ $uid ] ) ? sanitize_text_field( wp_unslash( (string) $_POST['tra_disc'][ $uid ] ) ) : '';
		$api  = ! empty( $_POST['tra_api'][ $uid ] );
		// phpcs:enable

		switch ( $m[1] ) {
			case 'save':
				TRA_Accounts::update( $uid, $disc, $api );
				return array( self::msg( 'success', __( 'Ο λογαριασμός αποθηκεύτηκε.', 'trade-accounts' ) ) );
			case 'pw':
				$pw = TRA_Accounts::new_password( $uid );
				if ( is_wp_error( $pw ) ) {
					return array( self::msg( 'error', $pw->get_error_message() ) );
				}
				set_transient(
					'tra_pw_' . get_current_user_id(),
					array(
						'u' => $uid,
						'p' => $pw,
					),
					60
				);
				return array( self::msg( 'success', __( 'Νέος κωδικός Trade Feed: ο παλιός δεν ισχύει πια.', 'trade-accounts' ) ) );
			case 'revoke':
				TRA_Accounts::revoke( $uid );
				return array( self::msg( 'success', __( 'Ο κωδικός ανακλήθηκε: το βιβλιοπωλείο δεν μπορεί να καλέσει το Trade Feed μέχρι να πάρει νέο.', 'trade-accounts' ) ) );
			default:
				TRA_Accounts::remove( $uid );
				return array( self::msg( 'success', __( 'Ο λογαριασμός έγινε απλός πελάτης.', 'trade-accounts' ) ) );
		}
	}

	private static function do_create(): array {
		$uid = TRA_Accounts::create(
			array(
				'email'   => self::post( 'tra_email' ),
				'first'   => self::post( 'tra_first' ),
				'last'    => self::post( 'tra_last' ),
				'company' => self::post( 'tra_company' ),
				'notify'  => '1' === self::post( 'tra_notify' ),
			)
		);
		if ( is_wp_error( $uid ) ) {
			return array( self::msg( 'error', $uid->get_error_message() ) );
		}
		TRA_Accounts::update( (int) $uid, self::post( 'tra_new_disc' ), '1' === self::post( 'tra_new_api' ) );
		return array( self::msg( 'success', __( 'Ο λογαριασμός χονδρικής δημιουργήθηκε. Για το Trade Feed πάτα «Νέος κωδικός» στη γραμμή του.', 'trade-accounts' ) ) );
	}

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs ): void {
		set_transient( 'tra_aui_msg_' . get_current_user_id(), $msgs, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab ) );
		exit;
	}

	/** A posted scalar, unslashed (callers sanitize). */
	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/** A posted list of scalars, unslashed and sanitized as text. */
	private static function post_list( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$raw = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized below.
		$out = array();
		foreach ( $raw as $v ) {
			if ( is_scalar( $v ) ) {
				$out[] = sanitize_text_field( (string) $v );
			}
		}
		return $out;
	}

	/* =====================================================================
	 * Backup (export GET / import POST)
	 * =================================================================== */

	public static function route_export(): void {
		if ( self::SLUG !== self::current_page() || '' === self::q( 'tra_export' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( self::q( '_wpnonce' ), self::NONCE_BAK ) || ! current_user_can( Trade_Accounts::cap() ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'trade-accounts' ) );
		}
		$state = array(
			'plugin'  => 'trade-accounts',
			'version' => TRA_VERSION,
			'options' => array(
				TRA_Settings::OPT => TRA_Settings::get(),
			),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="trade-accounts-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON attachment.
		exit;
	}

	/** Strict import through TRA_Settings::sanitize(); an invalid file changes nothing. */
	private static function do_import(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in route(); tmp path from PHP.
		$err  = isset( $_FILES['tra_import_file']['error'] ) ? (int) $_FILES['tra_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		$size = isset( $_FILES['tra_import_file']['size'] ) ? (int) $_FILES['tra_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT ) {
			return array( self::msg( 'error', __( 'Το αρχείο είναι πολύ μεγάλο.', 'trade-accounts' ) ) );
		}
		if ( UPLOAD_ERR_OK !== $err || $size <= 0 || empty( $_FILES['tra_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['tra_import_file']['tmp_name'] ) ) {
			return array( self::msg( 'error', __( 'Δεν επιλέχθηκε αρχείο JSON.', 'trade-accounts' ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded tmp file.
		$state = json_decode( (string) file_get_contents( $_FILES['tra_import_file']['tmp_name'] ), true );
		// phpcs:enable
		if ( ! is_array( $state ) ) {
			return array( self::msg( 'error', __( 'Το αρχείο δεν είναι έγκυρο JSON.', 'trade-accounts' ) ) );
		}
		$blob = isset( $state['options'][ TRA_Settings::OPT ] ) ? $state['options'][ TRA_Settings::OPT ] : null;
		if ( is_string( $blob ) ) {
			$blob = json_decode( $blob, true );
		}
		if ( ! is_array( $blob ) ) {
			return array( self::msg( 'error', __( 'Μη έγκυρο αρχείο backup (λείπουν οι ρυθμίσεις του Trade Accounts).', 'trade-accounts' ) ) );
		}
		TRA_Settings::save( $blob );
		return array( self::msg( 'success', __( 'Η εισαγωγή ολοκληρώθηκε.', 'trade-accounts' ) ) );
	}

	/* =====================================================================
	 * Notices
	 * =================================================================== */

	private static function take_msgs(): array {
		$raw = get_transient( 'tra_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && ! empty( $raw ) ) {
			delete_transient( 'tra_aui_msg_' . get_current_user_id() );
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
		<p class="tra-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'trade-accounts' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="tra-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="tra-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'trade-accounts' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="tra-footer-cta">
			<a class="tra-kofi" href="https://ko-fi.com/koulaxizis" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( '☕ Στήριξε το project στο Ko-fi', 'trade-accounts' ); ?>
			</a>
			<a class="tra-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>">
				<?php esc_html_e( 'Noxpress Dashboard', 'trade-accounts' ); ?>
			</a>
		</p>
		<?php
	}
}

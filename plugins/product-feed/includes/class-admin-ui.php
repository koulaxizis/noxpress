<?php
/**
 * PFD_Admin_UI — menu, tabs, forms (Bible §3–§8, §11).
 *
 * Submenu «Product Feed» of the shared "Noxpress" menu (admin_menu
 * priority 360), slug pfd-feeds, capability manage_woocommerce. Tabs:
 *  - feeds     list (URL, state, last build, items), «Ανανέωση τώρα»,
 *              «Αλλαγή», «Διαγραφή»; the form of one feed (&edit=)
 *  - check     report of the last build per feed (left out per reason,
 *              warnings, first 300 issues) and a preview of 20 items or
 *              of one product, built in the request, nothing written
 *  - settings  CTX Feed import, folder key, uninstall, settings backup
 *
 * Every state change: POST on admin_init (slug + nonce + capability, PRG,
 * message in the transient pfd_aui_msg_{uid}). Downloads: privileged GET.
 */

defined( 'ABSPATH' ) || exit;

final class PFD_Admin_UI {

	const SLUG_MENU   = 'noxpress';
	const SLUG        = 'pfd-feeds';
	const NONCE       = 'pfd_admin';
	const NONCE_FIELD = 'pfd_nonce';
	const NONCE_GET   = 'pfd_get';
	const MAX_JSON    = 262144;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 360 );
		add_action( 'admin_init', array( __CLASS__, 'route' ) );
		add_action( 'admin_init', array( __CLASS__, 'route_get' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PFD_FILE ), array( __CLASS__, 'action_links' ) );
	}

	private static function tabs(): array {
		return array(
			'feeds'    => __( 'Feeds', 'product-feed' ),
			'check'    => __( 'Έλεγχος', 'product-feed' ),
			'settings' => __( 'Ρυθμίσεις', 'product-feed' ),
		);
	}

	/* =====================================================================
	 * Menu + assets
	 * =================================================================== */

	public static function admin_menu(): void {
		add_submenu_page(
			self::SLUG_MENU,
			__( 'Product Feed', 'product-feed' ),
			__( 'Product Feed', 'product-feed' ),
			Product_Feed::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Feeds', 'product-feed' ) . '</a>' );
		return $links;
	}

	private static function current_page(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	private static function q( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing and filters.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? trim( (string) wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/** @return int[] */
	private static function post_ids( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$v = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
		return array_values( array_filter( array_map( 'absint', (array) $v ) ) );
	}

	private static function current_tab(): string {
		$t = sanitize_key( self::q( 'tab' ) );
		return isset( self::tabs()[ $t ] ) ? $t : 'feeds';
	}

	private static function url( string $tab, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public static function assets(): void {
		if ( self::SLUG !== self::current_page() ) {
			return;
		}
		wp_enqueue_style( 'pfd-admin', PFD_URL . 'assets/admin.css', array(), PFD_VERSION );
		wp_enqueue_script( 'pfd-admin', PFD_URL . 'assets/admin.js', array(), PFD_VERSION, true );
		wp_localize_script(
			'pfd-admin',
			'pfdAdmin',
			array(
				'copied' => __( 'Αντιγράφηκε', 'product-feed' ),
			)
		);
	}

	/* =====================================================================
	 * Texts of codes
	 * =================================================================== */

	private static function reason( string $code ): string {
		$map = array(
			'not_public'   => __( 'Δεν είναι δημόσιο (πρόχειρο, κωδικός ή κρυφό από τον κατάλογο)', 'product-feed' ),
			'type'         => __( 'Ομαδοποιημένο ή εξωτερικό προϊόν', 'product-feed' ),
			'category'     => __( 'Κατηγορία εκτός feed', 'product-feed' ),
			'tag'          => __( 'Ετικέτα εκτός feed', 'product-feed' ),
			'bookfeed'     => __( 'Τίτλος από το Book Feed', 'product-feed' ),
			'hidden'       => __( 'Κρυφό από το Hide Catalog', 'product-feed' ),
			'digital'      => __( 'Downloadable ή virtual', 'product-feed' ),
			'out_of_stock' => __( 'Χωρίς απόθεμα', 'product-feed' ),
			'errors'       => __( 'Σοβαρό σφάλμα (βλ. παρακάτω)', 'product-feed' ),
		);
		$code = 0 === strpos( $code, 'skip_' ) ? substr( $code, 5 ) : $code;
		return $map[ $code ] ?? $code;
	}

	private static function issue( string $code ): string {
		$map = array(
			'no_title'      => __( 'Χωρίς τίτλο', 'product-feed' ),
			'no_image'      => __( 'Χωρίς εικόνα', 'product-feed' ),
			'no_price'      => __( 'Χωρίς τιμή', 'product-feed' ),
			'no_desc'       => __( 'Χωρίς περιγραφή', 'product-feed' ),
			'no_gtin'       => __( 'Χωρίς GTIN / ISBN', 'product-feed' ),
			'bad_gtin'      => __( 'Λάθος ψηφίο ελέγχου στο GTIN / ISBN', 'product-feed' ),
			'no_brand'      => __( 'Χωρίς brand / εκδότη', 'product-feed' ),
			'long_title'    => __( 'Τίτλος πάνω από 150 χαρακτήρες (κόβεται)', 'product-feed' ),
			'no_category'   => __( 'Χωρίς κατηγορία', 'product-feed' ),
			'no_mpn'        => __( 'Χωρίς MPN / ISBN (το Skroutz δεν κατατάσσει βιβλία χωρίς ISBN)', 'product-feed' ),
			'image_http'    => __( 'Εικόνα χωρίς https', 'product-feed' ),
			'ean_13'        => __( 'Το EAN δεν έχει 13 ψηφία', 'product-feed' ),
			'no_identifier' => __( 'Χωρίς brand, GTIN ή SKU (η Meta θέλει ένα από αυτά)', 'product-feed' ),
		);
		return $map[ $code ] ?? self::reason( $code );
	}

	private static function error_text( string $code ): string {
		$map = array(
			'mkdir'   => __( 'Ο φάκελος των feeds δεν δημιουργήθηκε (δικαιώματα στο uploads).', 'product-feed' ),
			'write'   => __( 'Το αρχείο δεν γράφτηκε (δικαιώματα ή χώρος στον δίσκο).', 'product-feed' ),
			'rename'  => __( 'Το νέο αρχείο δεν αντικατέστησε το παλιό.', 'product-feed' ),
			'tmp'     => __( 'Χάθηκε το προσωρινό αρχείο: ξεκίνα ξανά.', 'product-feed' ),
			'legacy'  => __( 'Το feed γράφτηκε, αλλά όχι και στη δεύτερη διαδρομή.', 'product-feed' ),
			'stopped' => __( 'Σταμάτησε από σένα.', 'product-feed' ),
		);
		if ( 0 === strpos( $code, 'exception: ' ) ) {
			/* translators: %s: error message */
			return sprintf( __( 'Σφάλμα: %s', 'product-feed' ), substr( $code, 11 ) );
		}
		return $map[ $code ] ?? $code;
	}

	private static function badge( string $text, string $kind ): string {
		return '<span class="pfd-badge pfd-badge--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function form_head( string $action ): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD );
		echo '<input type="hidden" name="pfd_action" value="' . esc_attr( $action ) . '" />';
	}

	private static function button_form( string $action, array $fields, string $label, string $class = 'button', string $confirm = '' ): void {
		echo '<form method="post" class="pfd-inline-form"' . ( '' !== $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		self::form_head( $action );
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '" />';
		}
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private static function when( int $ts ): string {
		return $ts > 0 ? wp_date( 'd/m/Y H:i', $ts ) : '—';
	}

	/* =====================================================================
	 * Page
	 * =================================================================== */

	public static function render(): void {
		if ( ! current_user_can( Product_Feed::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'product-feed' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap pfd-wrap">
			<h1><?php esc_html_e( 'Product Feed', 'product-feed' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Feeds προϊόντων για Google Merchant Center, Skroutz, BestPrice, κατάλογο Meta και CSV. Κάθε feed είναι ένα στατικό αρχείο με σταθερό URL που ανανεώνεται στο παρασκήνιο.', 'product-feed' ); ?></p>
			<?php
			self::print_notices( self::take_msgs() );
			if ( Product_Feed::killed() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το PFD_DISABLE είναι ενεργό στο wp-config.php: κανένα feed δεν ανανεώνεται. Τα αρχεία που υπάρχουν μένουν όπως είναι.', 'product-feed' ) . '</p></div>';
			}
			?>
			<nav class="nav-tab-wrapper pfd-tabs">
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
				case 'check':
					self::render_check();
					break;
				case 'settings':
					self::render_settings();
					break;
				default:
					if ( '' !== self::q( 'edit' ) ) {
						self::render_form( self::q( 'edit' ) );
					} else {
						self::render_feeds();
					}
			}
			self::footer();
			?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Feeds list
	 * ------------------------------------------------------------------- */

	private static function render_feeds(): void {
		$feeds = PFD_Settings::feeds();
		echo '<h2 class="pfd-h2">' . esc_html__( 'Τα feeds σου', 'product-feed' ) . '</h2>';
		if ( ! $feeds ) {
			echo '<div class="pfd-zone"><p>' . esc_html__( 'Δεν υπάρχει ακόμα κανένα feed. Φτιάξε ένα παρακάτω ή εισήγαγε τα feeds του CTX Feed από τις Ρυθμίσεις.', 'product-feed' ) . '</p></div>';
		} else {
			echo '<div class="pfd-scroll"><table class="widefat pfd-table"><thead><tr>';
			foreach ( array( __( 'Feed', 'product-feed' ), __( 'Κανάλι', 'product-feed' ), __( 'URL', 'product-feed' ), __( 'Κατάσταση', 'product-feed' ), __( 'Τελευταία ανανέωση', 'product-feed' ), __( 'Εγγραφές', 'product-feed' ), '' ) as $h ) {
				echo '<th>' . esc_html( $h ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $feeds as $id => $f ) {
				$st  = PFD_Settings::status( (string) $id );
				$url = PFD_Settings::feed_url( $f );
				echo '<tr><td><strong>' . esc_html( $f['name'] ) . '</strong>';
				if ( ! $f['active'] ) {
					echo ' ' . self::badge( __( 'ανενεργό', 'product-feed' ), 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
				}
				echo '</td><td>' . esc_html( PFD_Channels::label( $f['channel'] ) ) . '</td><td>';
				if ( is_file( PFD_Settings::feed_path( $f ) ) ) {
					echo '<code class="pfd-url">' . esc_html( $url ) . '</code> <button type="button" class="button button-small pfd-copy" data-copy="' . esc_attr( $url ) . '">' . esc_html__( 'Αντιγραφή', 'product-feed' ) . '</button>';
				} else {
					echo '<span class="pfd-muted">' . esc_html__( 'Δεν έχει φτιαχτεί ακόμα', 'product-feed' ) . '</span>';
				}
				$lurl = PFD_Settings::legacy_url( $f );
				if ( '' !== $lurl ) {
					echo '<br><span class="pfd-muted">' . esc_html__( 'Και:', 'product-feed' ) . '</span> <code class="pfd-url">' . esc_html( $lurl ) . '</code>';
				}
				echo '</td><td>';
				if ( 'running' === $st['state'] ) {
					/* translators: %d: products read so far */
					echo self::badge( sprintf( __( 'φτιάχνεται (%d προϊόντα)', 'product-feed' ), (int) ( $st['run']['products'] ?? 0 ) ), 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
				} elseif ( '' !== $st['error'] ) {
					echo self::badge( __( 'σφάλμα', 'product-feed' ), 'danger' ) . '<br><span class="pfd-hint">' . esc_html( self::error_text( (string) $st['error'] ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
				} elseif ( $st['finished'] ) {
					echo self::badge( __( 'έτοιμο', 'product-feed' ), 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
				} else {
					echo '—';
				}
				echo '</td><td>' . esc_html( self::when( (int) $st['finished'] ) ) . '</td><td class="pfd-num">' . esc_html( number_format_i18n( (int) $st['count'] ) ) . '</td><td class="pfd-actions">';
				if ( 'running' === $st['state'] ) {
					self::button_form( 'stop', array( 'pfd_id' => $id ), __( 'Διακοπή', 'product-feed' ), 'button button-small' );
				} else {
					self::button_form( 'build', array( 'pfd_id' => $id ), __( 'Ανανέωση τώρα', 'product-feed' ), 'button button-small button-primary' );
				}
				echo '<a class="button button-small" href="' . esc_url( self::url( 'feeds', array( 'edit' => $id ) ) ) . '">' . esc_html__( 'Αλλαγή', 'product-feed' ) . '</a> ';
				echo '<a class="button button-small" href="' . esc_url( self::url( 'check', array( 'feed' => $id ) ) ) . '">' . esc_html__( 'Έλεγχος', 'product-feed' ) . '</a> ';
				self::button_form( 'delete', array( 'pfd_id' => $id ), __( 'Διαγραφή', 'product-feed' ), 'button button-small', __( 'Να διαγραφεί αυτό το feed και το αρχείο του; Όποια υπηρεσία το διαβάζει θα βρει 404.', 'product-feed' ) );
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
			echo '<p class="pfd-hint">' . esc_html__( 'Οι ανανεώσεις τρέχουν στο παρασκήνιο με το Action Scheduler του WooCommerce, σε παρτίδες των 100 προϊόντων. Το παλιό αρχείο μένει ώσπου να ετοιμαστεί ολόκληρο το νέο.', 'product-feed' ) . '</p>';
		}

		echo '<h2 class="pfd-h2">' . esc_html__( 'Νέο feed', 'product-feed' ) . '</h2><div class="pfd-zone"><form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" /><input type="hidden" name="tab" value="feeds" /><input type="hidden" name="edit" value="new" />';
		echo '<label class="pfd-inline">' . esc_html__( 'Κανάλι', 'product-feed' ) . ' <select name="channel">';
		foreach ( PFD_Channels::all() as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></label> <button type="submit" class="button button-primary">' . esc_html__( 'Συνέχεια', 'product-feed' ) . '</button></form></div>';
	}

	/* ---------------------------------------------------------------------
	 * Feed form
	 * ------------------------------------------------------------------- */

	private static function render_form( string $edit ): void {
		$id = 'new' === $edit ? '' : $edit;
		if ( '' !== $id ) {
			$f = PFD_Settings::feed( $id );
			if ( ! $f ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Το feed δεν βρέθηκε.', 'product-feed' ) . '</p></div>';
				return;
			}
		} else {
			$ch = sanitize_key( self::q( 'channel' ) );
			$f  = PFD_Settings::defaults( PFD_Channels::exists( $ch ) ? $ch : 'google' );
		}
		$ch    = $f['channel'];
		$ships = PFD_Channels::ship_values( $ch );
		echo '<h2 class="pfd-h2">' . esc_html( '' === $id ? __( 'Νέο feed', 'product-feed' ) : __( 'Αλλαγή feed', 'product-feed' ) ) . ' · ' . esc_html( PFD_Channels::label( $ch ) ) . '</h2>';
		if ( 'bestprice' === $ch ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'BestPrice: τα ονόματα των πεδίων ακολουθούν τη δημοσιευμένη δομή του BestPrice. Σύγκρινέ τα με τον οδηγό XML στο merchant panel σου πριν δηλώσεις το feed.', 'product-feed' ) . '</p></div>';
		}
		if ( 'google' === $ch || 'meta' === $ch ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Το Google δεν δέχεται ψηφιακά βιβλία στις διαφημίσεις Shopping: τα downloadable και τα virtual προϊόντα μένουν έξω, εκτός αν το αλλάξεις παρακάτω.', 'product-feed' ) . '</p></div>';
		}
		echo '<form method="post">';
		self::form_head( 'save' );
		echo '<input type="hidden" name="pfd_id" value="' . esc_attr( $id ) . '" /><input type="hidden" name="pfd_channel" value="' . esc_attr( $ch ) . '" />';

		echo '<div class="pfd-zone"><div class="pfd-grid">';
		self::text( 'pfd_name', __( 'Όνομα', 'product-feed' ), $f['name'] );
		self::text( 'pfd_file', __( 'Όνομα αρχείου (λατινικά, αριθμοί, παύλα)', 'product-feed' ), $f['file'], '.' . PFD_Channels::ext( $ch ) );
		echo '<label class="pfd-block">' . esc_html__( 'Ανανέωση κάθε', 'product-feed' ) . ' <select name="pfd_every">';
		foreach ( array( 1, 2, 3, 6, 12, 24 ) as $h ) {
			/* translators: %d: hours */
			echo '<option value="' . (int) $h . '"' . selected( (int) $f['every'], $h, false ) . '>' . esc_html( sprintf( _n( '%d ώρα', '%d ώρες', $h, 'product-feed' ), $h ) ) . '</option>';
		}
		echo '</select></label></div>';
		self::check( 'pfd_active', __( 'Ενεργό (ανανεώνεται μόνο του)', 'product-feed' ), $f['active'] );
		self::check( 'pfd_on_change', __( 'Ανανέωση και 10 λεπτά μετά από αλλαγή τιμής, αποθέματος ή προϊόντος', 'product-feed' ), $f['on_change'] );
		echo '</div>';

		echo '<h2 class="pfd-h2">' . esc_html__( 'Ποια προϊόντα μπαίνουν', 'product-feed' ) . '</h2><div class="pfd-zone">';
		if ( 'skroutz' === $ch ) {
			echo '<p class="pfd-hint">' . esc_html__( 'Το Skroutz δεν έχει τιμή «εξαντλημένο»: προϊόντα χωρίς απόθεμα δεν μπαίνουν ποτέ.', 'product-feed' ) . '</p>';
		} else {
			self::check( 'pfd_instock_only', __( 'Μόνο όσα έχουν απόθεμα (ή είναι σε προπαραγγελία / backorder)', 'product-feed' ), $f['instock_only'] );
		}
		self::check( 'pfd_variations', __( 'Οι παραλλαγές ως χωριστές εγγραφές (Google, Meta, CSV) ή ως μεγέθη του προϊόντος (Skroutz, BestPrice)', 'product-feed' ), $f['variations'] );
		self::check( 'pfd_digital', __( 'Μαζί με downloadable και virtual προϊόντα', 'product-feed' ), $f['digital'] );
		self::check( 'pfd_hidden', __( 'Μαζί με όσα κρύβει το Hide Catalog από το κατάστημα', 'product-feed' ), $f['hidden'], ! function_exists( 'hic_hidden_terms' ) );
		self::check( 'pfd_bookfeed', __( 'Μαζί με τίτλους από το Book Feed (μπαίνουν και όταν το Hide Catalog τους κρύβει)', 'product-feed' ), $f['bookfeed'], ! function_exists( 'bkf_get_source' ) );
		self::check( 'pfd_skip_errors', __( 'Έξω όσα έχουν σοβαρό σφάλμα (χωρίς τιμή, εικόνα ή τίτλο), για να μην απορριφθούν', 'product-feed' ), $f['skip_errors'] );
		echo '<div class="pfd-grid">';
		self::cats( 'pfd_cats_in', __( 'Μόνο από αυτές τις κατηγορίες (καμία = όλες)', 'product-feed' ), $f['cats_in'] );
		self::cats( 'pfd_cats_out', __( 'Εκτός από αυτές τις κατηγορίες', 'product-feed' ), $f['cats_out'] );
		echo '</div>';
		self::text( 'pfd_tags_out', __( 'Εκτός από προϊόντα με αυτές τις ετικέτες (ονόματα ή ids, με κόμμα)', 'product-feed' ), self::tag_names( $f['tags_out'] ) );
		echo '</div>';

		echo '<h2 class="pfd-h2">' . esc_html__( 'Πεδία', 'product-feed' ) . '</h2><div class="pfd-zone"><div class="pfd-grid">';
		echo '<label class="pfd-block">' . esc_html__( 'Brand / κατασκευαστής', 'product-feed' ) . ' <select name="pfd_brand_src">';
		foreach ( array( 'brand' => __( 'Μάρκα του WooCommerce (π.χ. εκδότης)', 'product-feed' ), 'attr' => __( 'Χαρακτηριστικό', 'product-feed' ), 'fixed' => __( 'Σταθερό κείμενο', 'product-feed' ) ) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['brand_src'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		self::attrs( 'pfd_brand_attr', __( 'Χαρακτηριστικό για το brand', 'product-feed' ), $f['brand_attr'] );
		self::text( 'pfd_brand_fixed', __( 'Σταθερό brand (και όταν λείπει η μάρκα)', 'product-feed' ), $f['brand_fixed'] );
		self::text( 'pfd_gtin_meta', __( 'Meta key για GTIN, όταν λείπει το πεδίο GTIN του WooCommerce (προαιρετικό)', 'product-feed' ), $f['gtin_meta'] );
		self::attrs( 'pfd_color_attr', __( 'Χαρακτηριστικό χρώματος', 'product-feed' ), $f['color_attr'] );
		self::attrs( 'pfd_size_attr', __( 'Χαρακτηριστικό μεγέθους', 'product-feed' ), $f['size_attr'] );
		echo '<label class="pfd-block">' . esc_html__( 'Περιγραφή', 'product-feed' ) . ' <select name="pfd_desc_src"><option value="long"' . selected( $f['desc_src'], 'long', false ) . '>' . esc_html__( 'Πλήρης (αλλιώς σύντομη)', 'product-feed' ) . '</option><option value="short"' . selected( $f['desc_src'], 'short', false ) . '>' . esc_html__( 'Σύντομη (αλλιώς πλήρης)', 'product-feed' ) . '</option></select></label>';
		if ( 'skroutz' === $ch || 'bestprice' === $ch || 'csv' === $ch ) {
			self::text( 'pfd_qty_default', __( 'Ποσότητα για προϊόντα χωρίς μέτρηση αποθέματος', 'product-feed' ), (string) $f['qty_default'] );
		}
		echo '</div></div>';

		if ( $ships ) {
			echo '<h2 class="pfd-h2">' . esc_html__( 'Χρόνος αποστολής', 'product-feed' ) . '</h2><div class="pfd-zone"><div class="pfd-grid">';
			self::select( 'pfd_ship', __( 'Για προϊόντα με απόθεμα', 'product-feed' ), $ships, $f['ship'] );
			self::select( 'pfd_ship_back', __( 'Για προπαραγγελίες και backorder', 'product-feed' ), $ships, $f['ship_back'] );
			echo '</div>';
			$lines = array();
			foreach ( $f['ship_cats'] as $cat => $v ) {
				$t       = get_term( (int) $cat, 'product_cat' );
				$lines[] = ( $t instanceof WP_Term ? $t->slug : (string) $cat ) . ' = ' . ( (int) array_search( $v, $ships, true ) + 1 );
			}
			echo '<label class="pfd-block">' . esc_html__( 'Εξαιρέσεις ανά κατηγορία: μία ανά γραμμή, «slug ή id = αριθμός επιλογής» (1 = πρώτη επιλογή παραπάνω)', 'product-feed' ) . '<br><textarea name="pfd_ship_cats" class="pfd-wide" rows="4">' . esc_textarea( implode( "\n", $lines ) ) . '</textarea></label>';
			echo '<p class="pfd-hint">';
			foreach ( $ships as $i => $v ) {
				echo esc_html( ( $i + 1 ) . ' = ' . $v ) . '<br>';
			}
			echo '</p></div>';
		}

		if ( 'google' === $ch || 'meta' === $ch ) {
			echo '<h2 class="pfd-h2">' . esc_html__( 'Κατηγορία Google', 'product-feed' ) . '</h2><div class="pfd-zone">';
			self::text( 'pfd_gcat_default', __( 'Κατηγορία Google για όλα (αριθμός, π.χ. 784 = Βιβλία· κενό = καμία)', 'product-feed' ), $f['gcat_default'] ? (string) $f['gcat_default'] : '' );
			$lines = array();
			foreach ( $f['gcat'] as $cat => $v ) {
				$t       = get_term( (int) $cat, 'product_cat' );
				$lines[] = ( $t instanceof WP_Term ? $t->slug : (string) $cat ) . ' = ' . (int) $v;
			}
			echo '<label class="pfd-block">' . esc_html__( 'Ανά κατηγορία του καταστήματος: «slug ή id = αριθμός κατηγορίας Google», μία ανά γραμμή', 'product-feed' ) . '<br><textarea name="pfd_gcat" class="pfd-wide" rows="4">' . esc_textarea( implode( "\n", $lines ) ) . '</textarea></label>';
			echo '<p class="pfd-hint">' . esc_html__( 'Οι αριθμοί είναι στη λίστα κατηγοριών προϊόντων της Google (taxonomy-with-ids). Η διαδρομή κατηγορίας του καταστήματος πηγαίνει πάντα στο product_type.', 'product-feed' ) . '</p></div>';
		}

		echo '<h2 class="pfd-h2">' . esc_html__( 'Σύνδεσμοι και διαδρομή', 'product-feed' ) . '</h2><div class="pfd-zone"><div class="pfd-grid">';
		self::text( 'pfd_utm_source', 'utm_source', $f['utm_source'] );
		self::text( 'pfd_utm_medium', 'utm_medium', $f['utm_medium'] );
		self::text( 'pfd_utm_campaign', 'utm_campaign', $f['utm_campaign'] );
		echo '</div>';
		self::text( 'pfd_legacy', __( 'Και σε αυτή τη διαδρομή, μέσα στο uploads (π.χ. το παλιό αρχείο άλλου plugin· κενό = όχι)', 'product-feed' ), $f['legacy'] );
		echo '<p class="pfd-hint">' . esc_html__( 'Χρήσιμο όταν μια υπηρεσία διαβάζει ήδη ένα παλιό URL: το Merchant Center δεν χρειάζεται αλλαγή. Βάλ\' το μόνο αφού απενεργοποιήσεις το άλλο plugin, αλλιώς θα γράφουν και τα δύο το ίδιο αρχείο.', 'product-feed' ) . '</p>';
		if ( '' !== $f['legacy'] && 0 === strpos( $f['legacy'], 'woo-feed/' ) && self::ctx_active() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Το CTX Feed είναι ακόμα ενεργό και ξαναγράφει αυτό το αρχείο κάθε ώρα.', 'product-feed' ) . '</p></div>';
		}
		echo '</div>';

		echo '<p class="pfd-submit"><button type="submit" class="button button-primary">' . esc_html__( 'Αποθήκευση', 'product-feed' ) . '</button> ';
		echo '<a class="button" href="' . esc_url( self::url( 'feeds' ) ) . '">' . esc_html__( 'Ακύρωση', 'product-feed' ) . '</a></p></form>';
	}

	private static function ctx_active(): bool {
		return defined( 'WOO_FEED_FREE_VERSION' ) || defined( 'WOO_FEED_PRO_VERSION' ) || class_exists( 'Woo_Feed' );
	}

	private static function text( string $name, string $label, string $value, string $suffix = '' ): void {
		echo '<label class="pfd-block">' . esc_html( $label ) . '<br><input type="text" class="regular-text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />' . ( '' !== $suffix ? ' <code>' . esc_html( $suffix ) . '</code>' : '' ) . '</label>';
	}

	private static function check( string $name, string $label, bool $on, bool $disabled = false ): void {
		echo '<label class="pfd-check pfd-block"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( $on, true, false ) . disabled( $disabled, true, false ) . ' /> ' . esc_html( $label ) . '</label>';
	}

	private static function select( string $name, string $label, array $values, string $cur ): void {
		echo '<label class="pfd-block">' . esc_html( $label ) . '<br><select name="' . esc_attr( $name ) . '">';
		foreach ( $values as $v ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $cur, $v, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select></label>';
	}

	private static function attrs( string $name, string $label, string $cur ): void {
		echo '<label class="pfd-block">' . esc_html( $label ) . '<br><select name="' . esc_attr( $name ) . '"><option value="">—</option>';
		foreach ( wc_get_attribute_taxonomies() as $a ) {
			$tax = wc_attribute_taxonomy_name( $a->attribute_name );
			echo '<option value="' . esc_attr( $tax ) . '"' . selected( $cur, $tax, false ) . '>' . esc_html( $a->attribute_label ) . '</option>';
		}
		echo '</select></label>';
	}

	private static function cats( string $name, string $label, array $cur ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 2000,
			)
		);
		echo '<label class="pfd-block">' . esc_html( $label ) . '<br><select name="' . esc_attr( $name ) . '[]" multiple size="8" class="pfd-multi">';
		if ( is_array( $terms ) ) {
			$by = array();
			foreach ( $terms as $t ) {
				$by[ (int) $t->parent ][] = $t;
			}
			self::cat_options( $by, 0, 0, $cur );
		}
		echo '</select></label>';
	}

	private static function cat_options( array $by, int $parent, int $depth, array $cur ): void {
		if ( empty( $by[ $parent ] ) || $depth > 8 ) {
			return;
		}
		foreach ( $by[ $parent ] as $t ) {
			echo '<option value="' . (int) $t->term_id . '"' . ( in_array( (int) $t->term_id, $cur, true ) ? ' selected' : '' ) . '>' . esc_html( str_repeat( '— ', $depth ) . $t->name ) . '</option>';
			self::cat_options( $by, (int) $t->term_id, $depth + 1, $cur );
		}
	}

	private static function tag_names( array $ids ): string {
		$out = array();
		foreach ( $ids as $id ) {
			$t     = get_term( (int) $id, 'product_tag' );
			$out[] = $t instanceof WP_Term ? $t->name : (string) $id;
		}
		return implode( ', ', $out );
	}

	/* ---------------------------------------------------------------------
	 * Check
	 * ------------------------------------------------------------------- */

	private static function render_check(): void {
		$feeds = PFD_Settings::feeds();
		if ( ! $feeds ) {
			echo '<div class="pfd-zone"><p>' . esc_html__( 'Δεν υπάρχει ακόμα κανένα feed.', 'product-feed' ) . '</p></div>';
			return;
		}
		$id = self::q( 'feed' );
		if ( ! isset( $feeds[ $id ] ) ) {
			$id = (string) array_key_first( $feeds );
		}
		$f  = $feeds[ $id ];
		$st = PFD_Settings::status( $id );

		echo '<form method="get" class="pfd-filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" /><input type="hidden" name="tab" value="check" />';
		echo '<label class="pfd-inline">' . esc_html__( 'Feed', 'product-feed' ) . ' <select name="feed">';
		foreach ( $feeds as $fid => $one ) {
			echo '<option value="' . esc_attr( (string) $fid ) . '"' . selected( $id, (string) $fid, false ) . '>' . esc_html( $one['name'] ) . '</option>';
		}
		echo '</select></label> <label class="pfd-inline">' . esc_html__( 'Ένα προϊόν (ID)', 'product-feed' ) . ' <input type="text" name="product" size="8" value="' . esc_attr( self::q( 'product' ) ) . '" /></label> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Προβολή', 'product-feed' ) . '</button></form>';

		echo '<h2 class="pfd-h2">' . esc_html__( 'Τελευταία ανανέωση', 'product-feed' ) . '</h2><div class="pfd-zone">';
		if ( ! $st['finished'] ) {
			echo '<p>' . esc_html__( 'Το feed δεν έχει φτιαχτεί ακόμα. Πάτα «Ανανέωση τώρα» στη λίστα των feeds.', 'product-feed' ) . '</p>';
		} else {
			/* translators: 1: date, 2: items, 3: products read */
			echo '<p>' . esc_html( sprintf( __( '%1$s · %2$s εγγραφές από %3$s προϊόντα', 'product-feed' ), self::when( (int) $st['finished'] ), number_format_i18n( (int) $st['count'] ), number_format_i18n( (int) $st['products'] ) ) ) . '</p>';
			if ( $st['skipped'] ) {
				echo '<table class="widefat pfd-table pfd-narrow"><thead><tr><th>' . esc_html__( 'Έμειναν έξω', 'product-feed' ) . '</th><th class="pfd-num">#</th></tr></thead><tbody>';
				foreach ( $st['skipped'] as $code => $n ) {
					echo '<tr><td>' . esc_html( self::reason( (string) $code ) ) . '</td><td class="pfd-num">' . esc_html( number_format_i18n( (int) $n ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			if ( $st['warnings'] ) {
				echo '<table class="widefat pfd-table pfd-narrow pfd-mt-8"><thead><tr><th>' . esc_html__( 'Προειδοποιήσεις (μπαίνουν, αλλά καλό είναι να διορθωθούν)', 'product-feed' ) . '</th><th class="pfd-num">#</th></tr></thead><tbody>';
				foreach ( $st['warnings'] as $code => $n ) {
					echo '<tr><td>' . esc_html( self::issue( (string) $code ) ) . '</td><td class="pfd-num">' . esc_html( number_format_i18n( (int) $n ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			if ( $st['issues'] ) {
				echo '<details class="pfd-mt-8"><summary>' . esc_html__( 'Προϊόντα με σφάλμα ή προειδοποίηση (έως 300)', 'product-feed' ) . '</summary>';
				self::issue_table( $st['issues'] );
				echo '</details>';
			}
		}
		echo '</div>';

		$only = absint( self::q( 'product' ) );
		$pre  = PFD_Builder::preview( $f, 20, $only );
		echo '<h2 class="pfd-h2">' . esc_html( $only ? __( 'Προεπισκόπηση του προϊόντος', 'product-feed' ) : __( 'Προεπισκόπηση των πρώτων 20 εγγραφών', 'product-feed' ) ) . '</h2><div class="pfd-zone">';
		echo '<p class="pfd-hint">' . esc_html__( 'Φτιάχνεται τώρα, με τις τρέχουσες ρυθμίσεις, χωρίς να γραφτεί αρχείο.', 'product-feed' ) . '</p>';
		if ( $pre['lines'] ) {
			self::issue_table( $pre['lines'], true );
		}
		echo '<textarea class="pfd-wide code pfd-preview" rows="18" readonly>' . esc_textarea( $pre['text'] ) . '</textarea></div>';
	}

	private static function issue_table( array $rows, bool $ok_too = false ): void {
		echo '<div class="pfd-scroll"><table class="widefat pfd-table"><thead><tr><th>ID</th><th>' . esc_html__( 'Προϊόν', 'product-feed' ) . '</th><th>' . esc_html__( 'Σφάλματα', 'product-feed' ) . '</th><th>' . esc_html__( 'Προειδοποιήσεις', 'product-feed' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) || count( $r ) < 4 ) {
				continue;
			}
			list( $pid, $title, $err, $warn ) = $r;
			if ( ! $ok_too && ! $err && ! $warn ) {
				continue;
			}
			$edit = get_edit_post_link( (int) $pid );
			echo '<tr><td>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . (int) $pid . '</a>' : (int) $pid ) . '</td><td>' . esc_html( (string) $title ) . '</td><td>';
			echo $err ? '<span class="pfd-neg">' . esc_html( implode( ' · ', array_map( array( __CLASS__, 'issue' ), (array) $err ) ) ) . '</span>' : '—';
			echo '</td><td>' . ( $warn ? '<span class="pfd-warn">' . esc_html( implode( ' · ', array_map( array( __CLASS__, 'issue' ), (array) $warn ) ) ) . '</span>' : '—' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	private static function render_settings(): void {
		$s   = PFD_Settings::get();
		$ctx = PFD_Ctx_Import::found();

		echo '<h2 class="pfd-h2">' . esc_html__( 'Εισαγωγή από το CTX Feed', 'product-feed' ) . '</h2><div class="pfd-zone">';
		if ( ! $ctx ) {
			echo '<p class="pfd-muted">' . esc_html__( 'Δεν βρέθηκαν feeds του CTX Feed σε αυτό το site.', 'product-feed' ) . '</p>';
		} else {
			/* translators: %d: number of CTX feeds */
			echo '<p>' . esc_html( sprintf( _n( 'Βρέθηκε %d feed του CTX Feed:', 'Βρέθηκαν %d feeds του CTX Feed:', count( $ctx ), 'product-feed' ), count( $ctx ) ) ) . '</p><ul class="pfd-list">';
			foreach ( $ctx as $name => $c ) {
				echo '<li><strong>' . esc_html( (string) $name ) . '</strong> · ' . esc_html( $c['provider'] ) . ( '' !== $c['url'] ? ' · <code>' . esc_html( $c['url'] ) . '</code>' : '' ) . '</li>';
			}
			echo '</ul><p class="pfd-hint">' . esc_html__( 'Φτιάχνει ένα feed για το καθένα, με τα ίδια φίλτρα, τις ετικέτες που εξαιρούνται και τα UTM. Το CTX Feed δεν αλλάζει και συνεχίζει να δουλεύει. Το νέο feed παίρνει δικό του URL, για να συγκρίνεις πρώτα.', 'product-feed' ) . '</p>';
			self::button_form( 'ctx_import', array(), __( 'Εισαγωγή', 'product-feed' ), 'button button-primary' );
		}
		echo '</div>';

		echo '<h2 class="pfd-h2">' . esc_html__( 'Φάκελος και διαγραφή', 'product-feed' ) . '</h2><div class="pfd-zone"><form method="post">';
		self::form_head( 'save_settings' );
		echo '<p>' . esc_html__( 'Φάκελος των feeds:', 'product-feed' ) . ' <code>' . esc_html( PFD_Settings::base_url() ) . '/</code></p>';
		echo '<p class="pfd-hint">' . esc_html__( 'Το τυχαίο όνομα του φακέλου δυσκολεύει όποιον θέλει να διαβάσει όλο τον κατάλογο. Τα δεδομένα είναι ούτως ή άλλως δημόσια (τιμές και σελίδες του καταστήματος).', 'product-feed' ) . '</p>';
		self::check( 'pfd_drop', __( 'Διαγραφή των αρχείων των feeds όταν διαγραφεί το plugin (η δεύτερη διαδρομή δεν σβήνεται ποτέ)', 'product-feed' ), (bool) $s['drop'] );
		echo '<p class="pfd-submit"><button type="submit" class="button button-primary">' . esc_html__( 'Αποθήκευση ρυθμίσεων', 'product-feed' ) . '</button></p></form>';
		self::button_form( 'new_key', array(), __( 'Νέο τυχαίο όνομα φακέλου', 'product-feed' ), 'button', __( 'Όλα τα URLs των feeds θα αλλάξουν και θα πρέπει να τα δηλώσεις ξανά σε κάθε υπηρεσία. Συνέχεια;', 'product-feed' ) );
		echo '</div>';

		echo '<h2 class="pfd-h2">' . esc_html__( 'Αντίγραφο ρυθμίσεων', 'product-feed' ) . '</h2><div class="pfd-zone">';
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( self::url( 'settings', array( 'pfd_get' => 'settings' ) ), self::NONCE_GET ) ) . '">' . esc_html__( 'Λήψη JSON', 'product-feed' ) . '</a></p>';
		echo '<form method="post" enctype="multipart/form-data" data-confirm="' . esc_attr__( 'Τα feeds αυτού του site θα αντικατασταθούν από το αρχείο. Συνέχεια;', 'product-feed' ) . '">';
		self::form_head( 'import_settings' );
		echo '<input type="file" name="pfd_file" accept="application/json,.json" /> <button type="submit" class="button">' . esc_html__( 'Εισαγωγή JSON', 'product-feed' ) . '</button></form>';
		echo '<p class="pfd-hint">' . esc_html__( 'Το αρχείο έχει τα feeds και τις ρυθμίσεις τους, όχι το όνομα του φακέλου.', 'product-feed' ) . '</p></div>';
	}

	/* =====================================================================
	 * GET downloads
	 * =================================================================== */

	public static function route_get(): void {
		if ( self::SLUG !== self::current_page() || '' === self::q( 'pfd_get' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_GET ) || ! current_user_can( Product_Feed::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'product-feed' ) );
		}
		if ( 'settings' === sanitize_key( self::q( 'pfd_get' ) ) ) {
			$json = wp_json_encode(
				array(
					'plugin'  => 'product-feed',
					'version' => PFD_VERSION,
					'options' => array(
						PFD_Settings::FEEDS  => PFD_Settings::feeds(),
						PFD_Settings::OPTION => array( 'drop' => (bool) PFD_Settings::get()['drop'] ),
					),
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="product-feed-settings-' . wp_date( 'Y-m-d' ) . '.json"' );
			echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON download.
			exit;
		}
	}

	/* =====================================================================
	 * POST router (Bible §11)
	 * =================================================================== */

	public static function route(): void {
		if ( self::SLUG !== self::current_page() || ! isset( $_POST[ self::NONCE_FIELD ], $_POST['pfd_action'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) || ! current_user_can( Product_Feed::CAP ) ) {
			wp_die( esc_html__( 'Δεν έχεις δικαίωμα πρόσβασης σε αυτή τη σελίδα.', 'product-feed' ) );
		}
		$id = self::post( 'pfd_id' );
		switch ( sanitize_key( wp_unslash( $_POST['pfd_action'] ) ) ) {
			case 'save':
				self::do_save();
				break;
			case 'build':
				if ( PFD_Settings::valid_id( $id ) && PFD_Builder::start( $id, 'manual', true ) ) {
					self::redirect( 'feeds', array( self::msg( 'success', __( 'Η ανανέωση ξεκίνησε στο παρασκήνιο. Η σελίδα δείχνει την πρόοδο όταν την ανανεώσεις.', 'product-feed' ) ) ) );
				}
				self::redirect( 'feeds', array( self::msg( 'error', __( 'Η ανανέωση δεν ξεκίνησε (το feed λείπει, το PFD_DISABLE είναι ενεργό ή ο φάκελος δεν γράφεται).', 'product-feed' ) ) ) );
				break;
			case 'stop':
				if ( PFD_Settings::valid_id( $id ) ) {
					PFD_Builder::stop( $id );
				}
				self::redirect( 'feeds', array( self::msg( 'success', __( 'Η ανανέωση σταμάτησε. Το προηγούμενο αρχείο έμεινε.', 'product-feed' ) ) ) );
				break;
			case 'delete':
				if ( PFD_Settings::valid_id( $id ) ) {
					PFD_Builder::stop( $id );
					PFD_Settings::delete( $id );
				}
				self::redirect( 'feeds', array( self::msg( 'success', __( 'Το feed διαγράφηκε.', 'product-feed' ) ) ) );
				break;
			case 'ctx_import':
				list( $n, $paths ) = PFD_Ctx_Import::run();
				/* translators: %d: feeds */
				$msgs = array( self::msg( 'success', sprintf( _n( 'Εισήχθη %d feed. Πάτα «Ανανέωση τώρα» για να το φτιάξεις.', 'Εισήχθησαν %d feeds. Πάτα «Ανανέωση τώρα» για να τα φτιάξεις.', $n, 'product-feed' ), $n ) ) );
				foreach ( $paths as $p ) {
					/* translators: %s: path under uploads */
					$msgs[] = self::msg( 'info', sprintf( __( 'Όταν απενεργοποιήσεις το CTX Feed, βάλε «%s» στο «Και σε αυτή τη διαδρομή» του feed, ώστε η υπηρεσία να διαβάζει το νέο feed από το ίδιο URL.', 'product-feed' ), $p ) );
				}
				self::redirect( 'feeds', $msgs );
				break;
			case 'save_settings':
				PFD_Settings::save( array( 'drop' => '1' === self::post( 'pfd_drop' ) ) );
				self::redirect( 'settings', array( self::msg( 'success', __( 'Οι ρυθμίσεις αποθηκεύτηκαν.', 'product-feed' ) ) ) );
				break;
			case 'new_key':
				foreach ( array_keys( PFD_Settings::feeds() ) as $fid ) {
					PFD_Builder::stop( (string) $fid );
				}
				PFD_Settings::new_key();
				self::redirect( 'settings', array( self::msg( 'success', __( 'Ο φάκελος άλλαξε. Ανανέωσε τα feeds και δήλωσε τα νέα URLs.', 'product-feed' ) ) ) );
				break;
			case 'import_settings':
				self::do_import_settings();
				break;
			default:
				self::redirect( 'feeds', array( self::msg( 'error', __( 'Άγνωστη ενέργεια.', 'product-feed' ) ) ) );
		}
	}

	private static function do_save(): void {
		$id = self::post( 'pfd_id' );
		if ( '' !== $id && ! PFD_Settings::valid_id( $id ) ) {
			self::redirect( 'feeds', array( self::msg( 'error', __( 'Το feed δεν βρέθηκε.', 'product-feed' ) ) ) );
		}
		$ch    = self::post( 'pfd_channel' );
		$ships = PFD_Channels::ship_values( $ch );
		$in    = array(
			'channel'      => $ch,
			'name'         => self::post( 'pfd_name' ),
			'file'         => self::post( 'pfd_file' ),
			'every'        => (int) self::post( 'pfd_every' ),
			'cats_in'      => self::post_ids( 'pfd_cats_in' ),
			'cats_out'     => self::post_ids( 'pfd_cats_out' ),
			'tags_out'     => self::parse_tags( self::post( 'pfd_tags_out' ) ),
			'legacy'       => self::post( 'pfd_legacy' ),
			'utm_source'   => self::post( 'pfd_utm_source' ),
			'utm_medium'   => self::post( 'pfd_utm_medium' ),
			'utm_campaign' => self::post( 'pfd_utm_campaign' ),
			'brand_src'    => self::post( 'pfd_brand_src' ),
			'brand_attr'   => self::post( 'pfd_brand_attr' ),
			'brand_fixed'  => self::post( 'pfd_brand_fixed' ),
			'gtin_meta'    => self::post( 'pfd_gtin_meta' ),
			'color_attr'   => self::post( 'pfd_color_attr' ),
			'size_attr'    => self::post( 'pfd_size_attr' ),
			'desc_src'     => self::post( 'pfd_desc_src' ),
			'ship'         => self::post( 'pfd_ship' ),
			'ship_back'    => self::post( 'pfd_ship_back' ),
			'ship_cats'    => self::parse_map( self::post( 'pfd_ship_cats' ), $ships ),
			'gcat'         => self::parse_map( self::post( 'pfd_gcat' ), array() ),
			'gcat_default' => absint( self::post( 'pfd_gcat_default' ) ),
			'qty_default'  => '' !== self::post( 'pfd_qty_default' ) ? absint( self::post( 'pfd_qty_default' ) ) : 1,
		);
		foreach ( array( 'active', 'on_change', 'instock_only', 'hidden', 'bookfeed', 'digital', 'skip_errors', 'variations' ) as $b ) {
			$in[ $b ] = '1' === self::post( 'pfd_' . $b );
		}
		$msgs = array();
		$raw  = ltrim( self::post( 'pfd_legacy' ), '/' );
		if ( '' !== $raw && ! PFD_Settings::valid_legacy( $raw, PFD_Channels::exists( $ch ) ? $ch : 'google' ) ) {
			$msgs[] = self::msg( 'warning', __( 'Η δεύτερη διαδρομή δεν είναι δεκτή (μόνο μέσα στο uploads, χωρίς «..», με την κατάληξη του feed) και έμεινε κενή.', 'product-feed' ) );
		}
		$res = PFD_Settings::put( $id, $in );
		if ( is_wp_error( $res ) ) {
			$code = $res->get_error_code();
			$text = 'file' === $code ? __( 'Άλλο feed έχει ήδη αυτό το όνομα αρχείου.', 'product-feed' ) : ( 'legacy' === $code ? __( 'Άλλο feed γράφει ήδη σε αυτή τη διαδρομή.', 'product-feed' ) : ( 'full' === $code ? __( 'Έφτασες το όριο των 20 feeds.', 'product-feed' ) : __( 'Το feed δεν βρέθηκε.', 'product-feed' ) ) );
			self::redirect( 'feeds', array( self::msg( 'error', $text ) ), array( 'edit' => '' !== $id ? $id : 'new', 'channel' => $ch ) );
		}
		array_unshift( $msgs, self::msg( 'success', __( 'Το feed αποθηκεύτηκε. Πάτα «Ανανέωση τώρα» για να δεις το αποτέλεσμα.', 'product-feed' ) ) );
		self::redirect( 'feeds', $msgs );
	}

	/** «name, 12, other» → tag ids (unknown names are left out). */
	private static function parse_tags( string $text ): array {
		$out = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', $text ) ) ) as $t ) {
			if ( ctype_digit( $t ) ) {
				$out[] = (int) $t;
				continue;
			}
			$term = get_term_by( 'name', $t, 'product_tag' );
			if ( ! $term instanceof WP_Term ) {
				$term = get_term_by( 'slug', sanitize_title( $t ), 'product_tag' );
			}
			if ( $term instanceof WP_Term ) {
				$out[] = (int) $term->term_id;
			}
		}
		return $out;
	}

	/**
	 * «slug or id = value» lines → [ cat id => value ]. With $choices the
	 * value is a 1-based choice number (or the exact text); without, a
	 * positive integer.
	 */
	private static function parse_map( string $text, array $choices ): array {
		$out = array();
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			if ( ! preg_match( '/^\s*([^=]+?)\s*=\s*(.+?)\s*$/u', (string) $line, $m ) ) {
				continue;
			}
			$key = $m[1];
			$cat = ctype_digit( $key ) ? (int) $key : 0;
			if ( ! $cat ) {
				$t   = get_term_by( 'slug', sanitize_title( $key ), 'product_cat' );
				$cat = $t instanceof WP_Term ? (int) $t->term_id : 0;
			}
			if ( ! $cat ) {
				continue;
			}
			if ( $choices ) {
				$v = ctype_digit( $m[2] ) ? ( $choices[ (int) $m[2] - 1 ] ?? '' ) : ( in_array( $m[2], $choices, true ) ? $m[2] : '' );
				if ( '' !== $v ) {
					$out[ $cat ] = $v;
				}
			} elseif ( ctype_digit( $m[2] ) ) {
				$out[ $cat ] = (int) $m[2];
			}
		}
		return $out;
	}

	private static function do_import_settings(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$f = isset( $_FILES['pfd_file'] ) && is_array( $_FILES['pfd_file'] ) ? $_FILES['pfd_file'] : null;
		if ( ! $f || ! empty( $f['error'] ) || empty( $f['tmp_name'] ) || (int) $f['size'] > self::MAX_JSON || ! is_uploaded_file( (string) $f['tmp_name'] ) ) {
			self::redirect( 'settings', array( self::msg( 'error', __( 'Το αρχείο δεν ανέβηκε ή είναι πολύ μεγάλο.', 'product-feed' ) ) ) );
		}
		$data = json_decode( (string) file_get_contents( (string) $f['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- an uploaded temp file.
		if ( ! is_array( $data ) || ( $data['plugin'] ?? '' ) !== 'product-feed' || ! isset( $data['options'][ PFD_Settings::FEEDS ] ) || ! is_array( $data['options'][ PFD_Settings::FEEDS ] ) ) {
			self::redirect( 'settings', array( self::msg( 'error', __( 'Δεν είναι αντίγραφο ρυθμίσεων του Product Feed: τίποτα δεν άλλαξε.', 'product-feed' ) ) ) );
		}
		foreach ( array_keys( PFD_Settings::feeds() ) as $fid ) {
			PFD_Builder::stop( (string) $fid );
		}
		$n = PFD_Settings::replace_all( $data['options'][ PFD_Settings::FEEDS ] );
		if ( isset( $data['options'][ PFD_Settings::OPTION ]['drop'] ) ) {
			PFD_Settings::save( array( 'drop' => (bool) $data['options'][ PFD_Settings::OPTION ]['drop'] ) );
		}
		/* translators: %d: feeds */
		self::redirect( 'settings', array( self::msg( 'success', sprintf( _n( 'Εισήχθη %d feed.', 'Εισήχθησαν %d feeds.', $n, 'product-feed' ), $n ) ) ) );
	}

	/* =====================================================================
	 * Messages (PRG)
	 * =================================================================== */

	private static function msg( string $type, string $text ): array {
		return array(
			'type' => $type,
			'text' => $text,
		);
	}

	private static function redirect( string $tab, array $msgs, array $args = array() ): void {
		if ( $msgs ) {
			set_transient( 'pfd_aui_msg_' . get_current_user_id(), $msgs, 60 );
		}
		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	private static function take_msgs(): array {
		$raw = get_transient( 'pfd_aui_msg_' . get_current_user_id() );
		if ( is_array( $raw ) && $raw ) {
			delete_transient( 'pfd_aui_msg_' . get_current_user_id() );
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
	 * Footer (Bible §7)
	 * =================================================================== */

	private static function footer(): void {
		?>
		<p class="pfd-footer">
			<?php
			printf(
				/* translators: %s: author name */
				esc_html__( 'Made with ❤ by %s', 'product-feed' ),
				'<a href="https://koulaxizis.gr" target="_blank" rel="noopener noreferrer">Christos Koulaxizis</a>'
			);
			?>
			<span class="pfd-muted"> · </span>
			<a href="https://glarolykoi.net" target="_blank" rel="noopener noreferrer">glarolykoi.net</a>
			<span class="pfd-muted"> · </span>
			<?php esc_html_e( 'More plugins at', 'product-feed' ); ?>
			<a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a>
		</p>
		<p class="pfd-footer-cta">
			<a class="pfd-kofi" href="https://noxpress.tech/support/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '♡ Στήριξε το Noxpress', 'product-feed' ); ?></a>
			<a class="pfd-footer-dash" href="<?php echo esc_url( admin_url( 'admin.php?page=noxpress' ) ); ?>"><?php esc_html_e( 'Noxpress Dashboard', 'product-feed' ); ?></a>
		</p>
		<?php
	}
}
